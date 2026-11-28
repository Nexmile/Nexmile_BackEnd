<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\ItemOption;
use App\Models\ItemOptionGroup;
use App\Models\MenuItem;
use App\Models\Merchant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Copy one restaurant's menu onto another.
 *
 * For a new kitchen that sells what an existing one sells — a second branch,
 * a franchise, or a restaurant onboarded by somebody who has already typed
 * the same forty dishes once. Categories, items, option groups and options,
 * in that order, because each needs the ids of the one before it.
 *
 * **Photographs are copied, not shared.** It is tempting to point both items
 * at the same object: it is one line and no bytes. But replacing a photo
 * deletes the one it replaced, so the day the second restaurant updates
 * their biryani picture, the first restaurant's biryani loses its image and
 * nobody connects the two events. Each copy gets its own object under the
 * target's own folder.
 *
 * **Four things are deliberately not copied**, and each would be a lie about
 * the new restaurant: ratings and rating counts, which belong to the people
 * who ate at the first one; the bestseller flag, which is computed from
 * sales that did not happen here; and surplus deals, which are tonight's
 * leftovers in somebody else's kitchen — advertising those would sell food
 * that does not exist.
 *
 * Nothing about the source is touched. It is read and never written.
 */
class CopyMenuCommand extends Command
{
    protected $signature = 'nexmile:copy-menu
                            {from : the restaurant to copy from, by name}
                            {to : the restaurant to copy onto, by name}
                            {--dry-run : report what would happen and change nothing}';

    protected $description = "Copy a restaurant's categories, dishes and options onto another restaurant";

    /** @var array<string, int> */
    private array $tally = [
        'categories' => 0, 'categories_existing' => 0,
        'items' => 0, 'items_existing' => 0,
        'groups' => 0, 'options' => 0,
        'images' => 0, 'images_failed' => 0,
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $source = $this->merchant((string) $this->argument('from'));
        $target = $this->merchant((string) $this->argument('to'));

        if ($source === null || $target === null) {
            return self::FAILURE;
        }

        if ($source->id === $target->id) {
            $this->components->error('Those are the same restaurant.');

            return self::FAILURE;
        }

        $items = MenuItem::where('merchant_id', $source->id)
            ->with(['optionGroups.options'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $this->components->info(sprintf(
            'Copying from %s (%d dishes) onto %s (%d dishes)%s',
            $source->business_name,
            $items->count(),
            $target->business_name,
            MenuItem::where('merchant_id', $target->id)->count(),
            $dry ? ' — DRY RUN, nothing will change' : '',
        ));

        if ($items->isEmpty()) {
            $this->components->warn('Nothing to copy.');

            return self::SUCCESS;
        }

        try {
            /*
             * One transaction, so a failure halfway leaves no half-built
             * menu. Image copies are the exception and cannot be rolled
             * back — a failed run can leave unreferenced objects in the
             * bucket, which costs pennies and breaks nothing.
             */
            DB::transaction(function () use ($source, $target, $items, $dry) {
                $categories = $this->copyCategories($source, $target, $dry);

                foreach ($items as $item) {
                    $this->copyItem($item, $target, $categories, $dry);
                }

                if ($dry) {
                    throw new DryRun;
                }
            });
        } catch (DryRun) {
            // Expected: the transaction was rolled back on purpose.
        } catch (Throwable $e) {
            $this->components->error('Nothing was copied: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->report($source, $target, $dry);

        return self::SUCCESS;
    }

    /**
     * Categories first: a dish cannot point at one that does not exist yet.
     *
     * @return array<int, int> source category id => target category id
     */
    private function copyCategories(Merchant $source, Merchant $target, bool $dry): array
    {
        $map = [];

        foreach (Category::where('merchant_id', $source->id)->orderBy('sort_order')->get() as $category) {
            $existing = Category::where('merchant_id', $target->id)
                ->where('name', $category->name)
                ->first();

            if ($existing !== null) {
                $this->tally['categories_existing']++;
                $map[$category->id] = $existing->id;

                continue;
            }

            $copy = Category::create([
                'merchant_id' => $target->id,
                'name' => $category->name,
                'description' => $category->description,
                'sort_order' => $category->sort_order,
                'is_active' => $category->is_active,
            ]);

            /*
             * forceFill, because `image_path` is not mass-assignable on a
             * category — it is a key into the bucket that also holds the KYC
             * documents, and nothing should be able to set one from request
             * input. Passing it to create() would have been accepted
             * silently and dropped, and every copied category would have
             * come out with no picture for no visible reason.
             */
            $copy->forceFill([
                'image_path' => $this->copyImage($category->image_path, 'categories', $dry),
            ])->save();

            $this->tally['categories']++;
            $map[$category->id] = $copy->id;
        }

        return $map;
    }

    /** @param array<int, int> $categories */
    private function copyItem(MenuItem $item, Merchant $target, array $categories, bool $dry): void
    {
        /*
         * Matched on name within the target, so running this twice adds
         * nothing. A restaurant that genuinely sells two dishes with the
         * same name has a bigger problem than this command.
         */
        if (MenuItem::where('merchant_id', $target->id)->where('name', $item->name)->exists()) {
            $this->tally['items_existing']++;

            return;
        }

        $copy = MenuItem::create([
            'merchant_id' => $target->id,
            'category_id' => $categories[$item->category_id] ?? null,
            'name' => $item->name,
            'description' => $item->description,
            'image_path' => $this->copyImage($item->image_path, 'menu/'.$target->id, $dry),
            'price' => $item->price,
            'compare_at_price' => $item->compare_at_price,
            'gst_rate' => $item->gst_rate,
            'is_veg' => $item->is_veg,
            'contains_egg' => $item->contains_egg,
            'is_available' => $item->is_available,
            'prep_time_minutes' => $item->prep_time_minutes,
            'sort_order' => $item->sort_order,

            /*
             * Not copied, and each would be a lie about this kitchen:
             * rating and rating_count belong to the people who ate at the
             * other one; is_bestseller is computed from sales that did not
             * happen here; and the surplus fields are tonight's leftovers
             * in somebody else's kitchen.
             */
        ]);

        $this->tally['items']++;

        foreach ($item->optionGroups as $group) {
            $groupCopy = ItemOptionGroup::create([
                'menu_item_id' => $copy->id,
                'name' => $group->name,
                'selection' => $group->selection,
                'is_required' => $group->is_required,
                'min_selections' => $group->min_selections,
                'max_selections' => $group->max_selections,
                'sort_order' => $group->sort_order,
            ]);

            $this->tally['groups']++;

            foreach ($group->options as $option) {
                ItemOption::create([
                    'item_option_group_id' => $groupCopy->id,
                    'name' => $option->name,
                    'price_delta' => $option->price_delta,
                    'is_available' => $option->is_available,
                    'sort_order' => $option->sort_order,
                ]);

                $this->tally['options']++;
            }
        }
    }

    /**
     * A photograph of its own, not a shared one.
     *
     * Pointing both items at the same object is one line and no bytes, and
     * it is a trap: replacing a photo deletes the one it replaced, so the
     * day the second restaurant updates their biryani picture the first
     * restaurant's biryani loses its image and nobody connects the two
     * events.
     *
     * A failed copy costs the picture, not the dish. A menu with one
     * missing photograph is a menu; a run that stops halfway is neither.
     */
    private function copyImage(?string $path, string $directory, bool $dry): ?string
    {
        if (blank($path)) {
            return null;
        }

        $destination = trim($directory, '/').'/'.Str::uuid()->toString()
            .'.'.(pathinfo($path, PATHINFO_EXTENSION) ?: 'webp');

        if ($dry) {
            $this->tally['images']++;

            return $destination;
        }

        $copied = rescue(
            fn () => Storage::disk(config('media.disk'))->copy($path, $destination),
            false,
            report: false,
        );

        if ($copied === false) {
            $this->tally['images_failed']++;

            return null;
        }

        $this->tally['images']++;

        return $destination;
    }

    private function merchant(string $name): ?Merchant
    {
        $matches = Merchant::where('business_name', 'like', '%'.$name.'%')->get();

        if ($matches->isEmpty()) {
            $this->components->error("No restaurant matching [{$name}].");

            return null;
        }

        /*
         * Refused rather than guessed. This writes a menu onto a live
         * restaurant, and picking the first of several matches is how the
         * wrong kitchen ends up advertising somebody else's biryani.
         */
        if ($matches->count() > 1) {
            $this->components->error("[{$name}] matches more than one restaurant:");

            foreach ($matches as $match) {
                $this->line('  '.$match->id.'  '.$match->business_name);
            }

            return null;
        }

        return $matches->first();
    }

    private function report(Merchant $source, Merchant $target, bool $dry): void
    {
        $this->newLine();
        $this->components->info($dry ? 'Would copy' : 'Copied');

        foreach ([
            'Categories copied' => $this->tally['categories'],
            'Categories already there' => $this->tally['categories_existing'],
            'Dishes copied' => $this->tally['items'],
            'Dishes already there' => $this->tally['items_existing'],
            'Option groups' => $this->tally['groups'],
            'Options' => $this->tally['options'],
            'Photographs' => $this->tally['images'],
        ] as $label => $value) {
            $this->components->twoColumnDetail($label, (string) $value);
        }

        if ($this->tally['images_failed'] > 0) {
            $this->components->warn(sprintf(
                '%d photographs could not be copied. Those dishes have no image and need one adding by hand.',
                $this->tally['images_failed'],
            ));
        }

        $this->newLine();
        $this->components->twoColumnDetail(
            $source->business_name.' (unchanged)',
            MenuItem::where('merchant_id', $source->id)->count().' dishes',
        );
        $this->components->twoColumnDetail(
            $target->business_name.($dry ? ' (would have)' : ''),
            MenuItem::where('merchant_id', $target->id)->count().' dishes',
        );

        if ($dry) {
            $this->newLine();
            $this->components->warn('Dry run. Nothing was written. Run again without --dry-run to copy.');
        }
    }
}

/** Thrown to roll a dry run back, never to report a failure. */
class DryRun extends \RuntimeException {}
