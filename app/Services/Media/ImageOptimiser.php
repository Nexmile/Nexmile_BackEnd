<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Shrinking a photograph to the size it is actually displayed at.
 *
 * Until this existed, whatever a merchant uploaded is what every customer
 * downloaded. A shop photographed on a phone is three or four megabytes and
 * four thousand pixels wide, and it was being sent to render a card about
 * four hundred pixels wide — fifty times more data than the screen can use,
 * on connections where that is most of a minute before anything appears.
 *
 * Three decisions worth knowing about:
 *
 * **It never enlarges.** `scaleDown` leaves a small image alone. A logo that
 * arrives at 200px stays at 200px rather than being stretched to a blurry
 * 400.
 *
 * **It never fails an upload.** If the encoder chokes — a corrupt file, an
 * unusual colour profile, a format GD dislikes — the original is stored
 * instead and the failure is logged. A merchant adding a photo of tonight's
 * special must not get an error because our resizer had an opinion.
 *
 * **It leaves animations alone.** A GIF re-encoded to a still WebP is a
 * banner that stopped moving, and nobody would connect that to an image
 * pipeline three weeks later.
 */
class ImageOptimiser
{
    /** Formats where re-encoding would lose something we cannot get back. */
    private const PASS_THROUGH = ['gif', 'svg'];

    /**
     * Process an upload, or hand back the original.
     *
     * @return array{contents: string, extension: string}
     */
    public function optimise(UploadedFile $file, string $variant): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'jpg');

        $original = fn (): array => [
            'contents' => (string) file_get_contents($file->getRealPath()),
            'extension' => $extension,
        ];

        if (in_array($extension, self::PASS_THROUGH, true)) {
            return $original();
        }

        $settings = $this->settings($variant);

        try {
            $image = ImageManager::gd()->read($file->getRealPath());

            /*
             * Phone cameras record which way up the photo is in EXIF rather
             * than in the pixels. Re-encoding drops that tag, so without this
             * every photo taken in portrait would arrive on its side — and it
             * would look like we had broken the image rather than rotated it.
             */
            $image->orient();

            $image->scaleDown(width: $settings['width']);

            return [
                'contents' => (string) $image->toWebp($settings['quality']),
                'extension' => 'webp',
            ];
        } catch (Throwable $e) {
            Log::warning('Could not optimise an upload; storing the original.', [
                'variant' => $variant,
                'extension' => $extension,
                'message' => $e->getMessage(),
            ]);

            return $original();
        }
    }

    /**
     * Resize the bytes of something already stored.
     *
     * Used by the backfill. Returns null when there is nothing to gain —
     * an unreadable file, a format left alone, or a result no smaller than
     * what is already there, which happens with images that were already
     * small and would only lose quality by being encoded again.
     *
     * @return array{contents: string, extension: string}|null
     */
    public function optimiseBytes(string $contents, string $extension, string $variant): ?array
    {
        if (in_array(strtolower($extension), self::PASS_THROUGH, true)) {
            return null;
        }

        $settings = $this->settings($variant);

        try {
            $image = ImageManager::gd()->read($contents);
            $image->orient();
            $image->scaleDown(width: $settings['width']);

            $encoded = (string) $image->toWebp($settings['quality']);
        } catch (Throwable $e) {
            Log::warning('Could not optimise a stored image.', [
                'variant' => $variant,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        return strlen($encoded) >= strlen($contents)
            ? null
            : ['contents' => $encoded, 'extension' => 'webp'];
    }

    /**
     * The size a given kind of image is actually shown at.
     *
     * Keyed on the first segment of its storage path, so `menu/41` and
     * `storefront/7` find their own settings and anything new falls back to
     * a sensible default rather than to the original size.
     *
     * @return array{width: int, quality: int}
     */
    public function settings(string $variant): array
    {
        $key = strtolower(explode('/', trim($variant, '/'))[0] ?? '');

        $variants = config('media.variants', []);
        $settings = $variants[$key] ?? config('media.default_variant');

        return [
            'width' => (int) $settings['width'],
            'quality' => (int) $settings['quality'],
        ];
    }
}
