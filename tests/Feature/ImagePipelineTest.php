<?php

namespace Tests\Feature;

use App\Console\Commands\OptimiseImagesCommand;
use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\MenuItem;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Media\ImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What a customer actually downloads.
 *
 * Until this pipeline existed, whatever a merchant uploaded is what every
 * customer downloaded: a four-megabyte photograph off a phone, four thousand
 * pixels wide, sent to draw a card about four hundred pixels wide. On the
 * connections this product serves, that is most of a minute before the first
 * screen settles, every time.
 *
 * These tests are mostly about the things that go wrong when you start
 * rewriting people's photographs — rotation, animation, small files, broken
 * files — because the resizing itself is the easy part.
 */
class ImagePipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('media.disk'));
    }

    /** A photograph the size a phone actually produces. */
    private function photo(int $width = 3000, int $height = 2000, string $name = 'shop.jpg'): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);

        // Noise, so it cannot be compressed to nothing and flatter the result.
        for ($i = 0; $i < 400; $i++) {
            imagefilledellipse(
                $image,
                random_int(0, $width),
                random_int(0, $height),
                random_int(40, 400),
                random_int(40, 400),
                imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)),
            );
        }

        $path = tempnam(sys_get_temp_dir(), 'img').'.jpg';
        imagejpeg($image, $path, 92);
        imagedestroy($image);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    public function test_a_phone_photograph_is_stored_at_the_size_it_is_shown_at(): void
    {
        $file = $this->photo();
        $before = $file->getSize();

        $path = app(ImageService::class)->store('menu/1', $file);

        $disk = Storage::disk(config('media.disk'));
        $after = strlen((string) $disk->get($path));

        // The point of the whole exercise, asserted as a number.
        $this->assertLessThan($before / 5, $after,
            sprintf('Stored %s from a %s original — the pipeline is not shrinking.',
                round($after / 1024).'KB', round($before / 1024).'KB'));

        [$width] = getimagesizefromstring((string) $disk->get($path));

        $this->assertSame(config('media.variants.menu.width'), $width);
        $this->assertStringEndsWith('.webp', $path);
    }

    public function test_each_kind_of_image_gets_its_own_size(): void
    {
        // A logo shares a folder with the carousel and is shown at a
        // fraction of the size, so it has to be named rather than inferred.
        $disk = Storage::disk(config('media.disk'));

        $carousel = app(ImageService::class)->store('storefront/1', $this->photo());
        $logo = app(ImageService::class)->store('storefront/1', $this->photo(), 'logo');

        $this->assertSame(1400, getimagesizefromstring((string) $disk->get($carousel))[0]);
        $this->assertSame(400, getimagesizefromstring((string) $disk->get($logo))[0]);
    }

    public function test_a_small_image_is_not_stretched(): void
    {
        // scaleDown leaves it alone. Enlarging a 200px logo to 400 produces a
        // blurry 400px logo and a bigger file for it.
        $path = app(ImageService::class)->store('cuisines', $this->photo(120, 120));

        $this->assertSame(
            120,
            getimagesizefromstring((string) Storage::disk(config('media.disk'))->get($path))[0],
        );
    }

    public function test_an_animation_is_left_alone(): void
    {
        /*
         * A GIF banner re-encoded to a still WebP is a banner that stopped
         * moving, and nobody would connect that to an image pipeline three
         * weeks later.
         */
        $gif = UploadedFile::fake()->createWithContent(
            'promo.gif',
            base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'),
        );

        $path = app(ImageService::class)->store('banners', $gif);

        $this->assertStringEndsWith('.gif', $path);
    }

    public function test_a_file_the_encoder_cannot_read_is_stored_rather_than_refused(): void
    {
        /*
         * A merchant adding a photo of tonight's special must not meet an
         * error because our resizer had an opinion about their file. The
         * original goes in and the failure is logged.
         */
        $broken = UploadedFile::fake()->createWithContent('broken.jpg', 'this is not an image');

        $path = app(ImageService::class)->store('menu/1', $broken);

        $this->assertStringEndsWith('.jpg', $path);
        Storage::disk(config('media.disk'))->assertExists($path);
    }

    public function test_a_portrait_photograph_is_not_stored_on_its_side(): void
    {
        /*
         * Phones record which way up a photo is in EXIF rather than in the
         * pixels, and re-encoding drops the tag. Without an explicit orient
         * every portrait photograph would arrive rotated, and it would look
         * like we had broken the image rather than failed to turn it.
         */
        $path = app(ImageService::class)->store('menu/1', $this->photo(1200, 2000, 'tall.jpg'));

        [$width, $height] = getimagesizefromstring(
            (string) Storage::disk(config('media.disk'))->get($path),
        );

        $this->assertLessThan($height, $width, 'A portrait photo came back landscape.');
    }

    public function test_the_backfill_reports_before_it_rewrites(): void
    {
        // The default way to run this the first time. It must touch nothing.
        $disk = Storage::disk(config('media.disk'));
        $disk->put('menu/1/old.jpg', (string) file_get_contents($this->photo()->getRealPath()));

        $merchant = $this->merchantWithPhoto('menu/1/old.jpg');

        $this->artisan('nexmile:optimise-images', ['--dry-run' => true])
            ->assertSuccessful();

        $disk->assertExists('menu/1/old.jpg');
        $this->assertSame('menu/1/old.jpg', $merchant->fresh()->image_path);
    }

    public function test_the_backfill_rewrites_and_repoints_the_record(): void
    {
        $disk = Storage::disk(config('media.disk'));
        $original = (string) file_get_contents($this->photo()->getRealPath());
        $disk->put('menu/1/old.jpg', $original);

        $item = $this->merchantWithPhoto('menu/1/old.jpg');

        $this->artisan('nexmile:optimise-images')->assertSuccessful();

        $path = $item->fresh()->image_path;

        $this->assertStringEndsWith('.webp', $path);
        $disk->assertExists($path);

        // The old object is gone, not left behind as a bill nobody watches.
        $disk->assertMissing('menu/1/old.jpg');

        $this->assertLessThan(strlen($original) / 5, strlen((string) $disk->get($path)));
    }

    public function test_the_backfill_never_makes_a_file_bigger(): void
    {
        /*
         * The rule is "smaller or untouched", not "always re-encode".
         *
         * Small images are often already at their floor, and a format's
         * fixed header can make the re-encoded copy larger than the original
         * — at which point rewriting costs quality and bytes and buys
         * nothing. A one-pixel PNG is the clearest case of that.
         *
         * Note this is not "leave small images alone": an 80x80 photograph
         * does get rewritten, because WebP genuinely beats JPEG at that size
         * too. The test is on the outcome, not on a size threshold.
         */
        $disk = Storage::disk(config('media.disk'));

        $tiny = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        $disk->put('menu/1/tiny.png', $tiny);

        $item = $this->merchantWithPhoto('menu/1/tiny.png');

        $this->artisan('nexmile:optimise-images')->assertSuccessful();

        $this->assertSame('menu/1/tiny.png', $item->fresh()->image_path);
        $this->assertSame($tiny, (string) $disk->get('menu/1/tiny.png'));
    }

    public function test_identity_documents_are_not_in_the_backfill(): void
    {
        /*
         * KYC papers are read by a person deciding whether somebody may
         * work. Legibility is the entire point of them, they are never
         * downloaded by a customer, and they are the one kind of image where
         * making the file smaller is a cost with no benefit.
         */
        $command = new \ReflectionClass(OptimiseImagesCommand::class);
        $groups = $command->getMethod('groups');
        $groups->setAccessible(true);

        $models = array_column($groups->invoke($command->newInstanceWithoutConstructor()), 'model');

        foreach ($models as $model) {
            $this->assertStringNotContainsStringIgnoringCase('kyc', $model);
            $this->assertStringNotContainsStringIgnoringCase('document', $model);
        }
    }

    private function merchantWithPhoto(string $path): MenuItem
    {
        $owner = User::create([
            'name' => 'Owner', 'phone' => '9780000099', 'email' => 'img.owner@example.in',
            'password' => 'secret', 'role' => UserRole::Merchant,
            'status' => UserStatus::Active,
        ]);

        $merchant = Merchant::create([
            'user_id' => $owner->id, 'business_name' => 'Hotel Image', 'owner_name' => 'Owner',
            'address_line1' => '1 Main Road', 'city' => 'Madurai', 'pincode' => '625001',
            'latitude' => 9.9195, 'longitude' => 78.1193,
            'kyc_status' => KycStatus::Verified, 'is_accepting_orders' => true,
        ]);

        $item = $merchant->menuItems()->create([
            'name' => 'Biryani', 'price' => 200, 'gst_rate' => 5,
        ]);

        $item->forceFill(['image_path' => $path])->save();

        return $item;
    }
}
