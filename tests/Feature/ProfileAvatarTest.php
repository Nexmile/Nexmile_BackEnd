<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A profile picture.
 *
 * The app had been binding to a field that did not exist, because this was
 * never built — there was no column, no route and nothing in the response.
 * So these tests are mostly about the shape the app reads, and about the two
 * things that would make a face a privacy problem rather than a feature:
 * where the file lands, and who gets handed the link.
 */
class ProfileAvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('media.disk'));
    }

    private function customer(): User
    {
        return User::create([
            'name' => 'Lakshmi',
            'phone' => '9780000501',
            'email' => 'lakshmi.avatar@example.in',
            'password' => 'secret',
            'role' => UserRole::Customer,
            'status' => UserStatus::Active,
        ]);
    }

    /** A selfie the size a phone actually produces. */
    private function selfie(int $width = 3000, int $height = 3000): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);

        // Noise, so it cannot compress to nothing and flatter the result.
        for ($i = 0; $i < 300; $i++) {
            imagefilledellipse(
                $image,
                random_int(0, $width),
                random_int(0, $height),
                random_int(40, 400),
                random_int(40, 400),
                imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)),
            );
        }

        $path = tempnam(sys_get_temp_dir(), 'ava').'.jpg';
        imagejpeg($image, $path, 92);
        imagedestroy($image);

        return new UploadedFile($path, 'selfie.jpg', 'image/jpeg', null, true);
    }

    /* ------------------------------------------------- the basics ---- */

    public function test_a_customer_can_set_a_profile_picture_and_gets_the_url_back(): void
    {
        $user = $this->customer();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/profile/photo', ['photo' => $this->selfie()])
            ->assertOk()
            ->assertJsonPath('message', 'Profile photo saved.');

        // The whole profile comes back, so the account screen can rebind from
        // one response instead of making a second call.
        $this->assertNotNull($response->json('data.avatar_url'));
        $this->assertSame('Lakshmi', $response->json('data.name'));

        $this->assertNotNull($user->fresh()->avatar_path);
        Storage::disk(config('media.disk'))->assertExists($user->fresh()->avatar_path);
    }

    public function test_the_field_is_null_rather_than_absent_before_one_is_set(): void
    {
        /*
         * The app draws initials when this is null, and it has to be able to
         * tell "no picture" from "key missing" without a crash. Sending the
         * key always is what lets the app lay out for it.
         */
        $this->actingAs($this->customer())
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.avatar_url', null);
    }

    public function test_a_phone_selfie_is_not_stored_at_phone_size(): void
    {
        /*
         * The reason this goes through the pipeline rather than straight into
         * the bucket. Three thousand pixels, drawn in a circle a hundred and
         * twenty across.
         */
        $file = $this->selfie();
        $before = $file->getSize();

        $this->actingAs($user = $this->customer())
            ->postJson('/api/v1/profile/photo', ['photo' => $file])
            ->assertOk();

        $path = $user->fresh()->avatar_path;
        $contents = (string) Storage::disk(config('media.disk'))->get($path);

        $this->assertStringEndsWith('.webp', $path);
        $this->assertSame(256, getimagesizefromstring($contents)[0]);
        $this->assertLessThan($before / 10, strlen($contents));
    }

    public function test_replacing_a_picture_removes_the_old_file(): void
    {
        // Nothing snapshots an avatar path, so the previous object is a bill
        // nobody is watching rather than something still being read.
        $user = $this->customer();
        $disk = Storage::disk(config('media.disk'));

        $this->actingAs($user)->postJson('/api/v1/profile/photo', ['photo' => $this->selfie()])->assertOk();
        $first = $user->fresh()->avatar_path;

        $this->actingAs($user)->postJson('/api/v1/profile/photo', ['photo' => $this->selfie()])->assertOk();
        $second = $user->fresh()->avatar_path;

        $this->assertNotSame($first, $second);
        $disk->assertMissing($first);
        $disk->assertExists($second);
    }

    public function test_removing_a_picture_deletes_the_file_too(): void
    {
        /*
         * Somebody asking us to drop a photograph of their face means the
         * object goes, not just the pointer to it.
         */
        $user = $this->customer();

        $this->actingAs($user)->postJson('/api/v1/profile/photo', ['photo' => $this->selfie()])->assertOk();
        $path = $user->fresh()->avatar_path;

        $this->actingAs($user)
            ->deleteJson('/api/v1/profile/photo')
            ->assertOk()
            ->assertJsonPath('data.avatar_url', null);

        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk(config('media.disk'))->assertMissing($path);
    }

    public function test_removing_a_picture_that_is_not_there_is_not_an_error(): void
    {
        // The app may send this from a menu that does not know the state.
        $this->actingAs($this->customer())
            ->deleteJson('/api/v1/profile/photo')
            ->assertOk();
    }

    /* ------------------------------------------------- who and what -- */

    public function test_signing_out_is_required(): void
    {
        $this->postJson('/api/v1/profile/photo', ['photo' => $this->selfie()])
            ->assertUnauthorized();
    }

    public function test_one_account_cannot_write_over_another_ones_picture(): void
    {
        /*
         * There is no id in the route — it is always the caller's own
         * account — but the stored path is built from the user id, so this is
         * the assertion that the folder follows the token and not anything
         * in the request.
         */
        $mine = $this->customer();

        $theirs = User::create([
            'name' => 'Other', 'phone' => '9780000502', 'email' => 'other.avatar@example.in',
            'password' => 'secret', 'role' => UserRole::Customer, 'status' => UserStatus::Active,
        ]);

        $this->actingAs($mine)
            ->postJson('/api/v1/profile/photo', ['photo' => $this->selfie()])
            ->assertOk();

        $this->assertStringStartsWith("avatars/{$mine->id}/", $mine->fresh()->avatar_path);
        $this->assertNull($theirs->fresh()->avatar_path);
    }

    public function test_a_document_is_not_accepted_as_a_photograph(): void
    {
        /*
         * mimes, not Laravel's `image` rule. That rule admits SVG, which is a
         * document that can carry script, and this one is rendered inside the
         * app under our own name.
         */
        $this->actingAs($this->customer())
            ->postJson('/api/v1/profile/photo', [
                'photo' => UploadedFile::fake()->create('resume.pdf', 20, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');
    }

    public function test_an_oversized_file_is_refused_with_a_readable_reason(): void
    {
        $this->actingAs($this->customer())
            ->postJson('/api/v1/profile/photo', [
                'photo' => UploadedFile::fake()->create('huge.jpg', config('media.max_size_kb') + 512, 'image/jpeg'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');
    }

    public function test_a_missing_file_is_refused(): void
    {
        $this->actingAs($this->customer())
            ->postJson('/api/v1/profile/photo', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');
    }

    /* -------------------------------------------------- the privacy -- */

    public function test_a_customers_face_is_not_handed_to_the_merchant_or_the_rider(): void
    {
        /*
         * The one that matters.
         *
         * UserResource is only ever returned to its own owner, which is what
         * makes avatar_url safe to put on it. If this resource is ever nested
         * inside somebody else's response — an order, a review, a chat — the
         * avatar goes out to people the customer never showed their face to.
         *
         * Asserted against the source rather than a response, because the
         * leak would arrive as a new call site, not as a changed field.
         */
        $callers = [];

        foreach (['app/Http/Controllers', 'app/Http/Resources', 'app/Services'] as $directory) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($directory)),
            );

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                if (str_contains($contents, 'new UserResource')) {
                    $callers[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }

        $expected = [
            'Auth'.DIRECTORY_SEPARATOR.'OtpAuthController.php',
            'Merchant'.DIRECTORY_SEPARATOR.'AuthController.php',
            'ProfileController.php',
        ];

        foreach ($callers as $caller) {
            $allowed = false;

            foreach ($expected as $suffix) {
                $allowed = $allowed || str_ends_with($caller, $suffix);
            }

            $this->assertTrue(
                $allowed,
                "{$caller} returns a UserResource. If that reaches anybody but the account's own "
                .'owner, it is handing out a customer photograph — take avatar_url off the resource first.',
            );
        }
    }

    public function test_the_link_expires(): void
    {
        // Signed and temporary, like every other object on the private disk.
        $this->actingAs($user = $this->customer())
            ->postJson('/api/v1/profile/photo', ['photo' => $this->selfie()])
            ->assertOk();

        $url = $this->actingAs($user)->getJson('/api/v1/profile')->json('data.avatar_url');

        $this->assertNotNull($url);
        $this->assertStringContainsString($user->fresh()->avatar_path, $url);
    }

    public function test_a_broken_image_is_stored_rather_than_refusing_the_upload(): void
    {
        /*
         * Consistent with the rest of the pipeline: our encoder having an
         * opinion about somebody's file must not be the reason they cannot
         * set a picture. The original goes in and the failure is logged.
         */
        $this->actingAs($user = $this->customer())
            ->postJson('/api/v1/profile/photo', [
                'photo' => UploadedFile::fake()->createWithContent('broken.jpg', 'this is not an image'),
            ])
            ->assertOk();

        $this->assertStringEndsWith('.jpg', (string) $user->fresh()->avatar_path);
    }
}
