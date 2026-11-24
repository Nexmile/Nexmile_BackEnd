<?php

namespace App\Console\Commands;

use App\Models\Banner;
use App\Models\Collection as MenuCollection;
use App\Models\Cuisine;
use App\Models\MenuItem;
use App\Models\Merchant;
use App\Models\MerchantPhoto;
use App\Services\Media\ImageOptimiser;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Shrink the images that were stored before anything shrank them.
 *
 * Every upload is resized on the way in now. This is for everything that
 * arrived before that was true: originals straight off a phone, four
 * thousand pixels wide, being downloaded in full to draw a card the width of
 * a thumbnail.
 *
 * **It rewrites in place and the original is gone.** That is deliberate —
 * these are photographs of shops and dishes, not assets anybody will want to
 * re-crop — but it is the reason for `--dry-run`, which is the default way
 * to use this the first time.
 */
class OptimiseImagesCommand extends Command
{
    protected $signature = 'nexmile:optimise-images
        {--dry-run : Report what would change and write nothing}
        {--only= : One group only: menu, storefront, logos, banners, cuisines, collections}';

    protected $description = 'Resize and re-encode images stored before the upload pipeline existed';

    /**
     * Every column holding a customer-facing image, and the settings it
     * should be stored at.
     *
     * KYC documents are deliberately absent. They are identity papers read
     * by a human deciding whether somebody may work, legibility is the whole
     * point of them, and they are not downloaded by customers at all.
     *
     * @return array<string, array{model: class-string<Model>, column: string, variant: string}>
     */
    private function groups(): array
    {
        return [
            'menu' => ['model' => MenuItem::class, 'column' => 'image_path', 'variant' => 'menu'],
            'storefront' => ['model' => MerchantPhoto::class, 'column' => 'image_path', 'variant' => 'storefront'],
            'logos' => ['model' => Merchant::class, 'column' => 'logo_path', 'variant' => 'logo'],
            'covers' => ['model' => Merchant::class, 'column' => 'banner_path', 'variant' => 'storefront'],
            'banners' => ['model' => Banner::class, 'column' => 'image_path', 'variant' => 'banners'],
            'cuisines' => ['model' => Cuisine::class, 'column' => 'image_path', 'variant' => 'cuisines'],
            'collections' => ['model' => MenuCollection::class, 'column' => 'banner_path', 'variant' => 'collections'],
        ];
    }

    public function handle(ImageOptimiser $optimiser): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = $this->option('only');
        $disk = Storage::disk(config('media.disk'));

        if ($dry) {
            $this->warn('Dry run: nothing will be written.');
        }

        $totalBefore = 0;
        $totalAfter = 0;
        $changed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->groups() as $name => $group) {
            if ($only !== null && $only !== $name) {
                continue;
            }

            /** @var class-string<Model> $model */
            $model = $group['model'];

            $rows = $model::query()->whereNotNull($group['column'])->get(['id', $group['column']]);

            if ($rows->isEmpty()) {
                continue;
            }

            $this->line(" {$name}: {$rows->count()} images");

            foreach ($rows as $row) {
                $path = $row->getAttribute($group['column']);

                try {
                    if (! $disk->exists($path)) {
                        $skipped++;

                        continue;
                    }

                    $contents = (string) $disk->get($path);
                    $before = strlen($contents);

                    $result = $optimiser->optimiseBytes(
                        $contents,
                        pathinfo($path, PATHINFO_EXTENSION),
                        $group['variant'],
                    );

                    // Already small enough, or a format left alone.
                    if ($result === null) {
                        $skipped++;
                        $totalBefore += $before;
                        $totalAfter += $before;

                        continue;
                    }

                    $totalBefore += $before;
                    $totalAfter += strlen($result['contents']);
                    $changed++;

                    if ($dry) {
                        continue;
                    }

                    /*
                     * New object first, then the row, then the old object.
                     *
                     * Any other order can leave a record pointing at
                     * something that is not there. This order's worst case is
                     * an orphan nobody is paying much for, which the next run
                     * of this command does not touch and a person can sweep.
                     */
                    $new = preg_replace('/\.[^.]+$/', '', $path).'.'.$result['extension'];

                    if ($new !== $path) {
                        $disk->put($new, $result['contents'], 'private');
                        $row->forceFill([$group['column'] => $new])->save();
                        $disk->delete($path);
                    } else {
                        $disk->put($path, $result['contents'], 'private');
                    }
                } catch (Throwable $e) {
                    $failed++;
                    $this->warn("  {$path}: {$e->getMessage()}");
                }
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '%s %d images · %s → %s (%s saved)',
            $dry ? 'Would rewrite' : 'Rewrote',
            $changed,
            $this->size($totalBefore),
            $this->size($totalAfter),
            $this->size(max(0, $totalBefore - $totalAfter)),
        ));

        if ($skipped > 0) {
            $this->line("{$skipped} left alone (already small, or an animation).");
        }

        if ($failed > 0) {
            $this->warn("{$failed} could not be read or re-encoded. They are untouched.");
        }

        return self::SUCCESS;
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).' MB'
            : round($bytes / 1024).' KB';
    }
}
