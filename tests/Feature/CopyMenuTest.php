<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Category;
use App\Models\ItemOption;
use App\Models\ItemOptionGroup;
use App\Models\MenuItem;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Copying one restaurant's menu onto another.
 *
 * For a second branch, a franchise, or a kitchen onboarded by somebody who
 * has already typed the same forty dishes once.
 *
 * Most of these tests are about what it refuses to copy, because this writes
 * onto a live restaurant and every one of those fields would be a claim the
 * new kitchen has not earned.
 */
class CopyMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('media.disk'));
    }

    private function merchant(string $name): Merchant
    {
        static $n = 0;
        $n++;

        $owner = User::create([
            'name' => 'Owner '.$n, 'phone' => '97830001'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "copy{$n}@example.in", 'password' => 'secret',
            'role' => UserRole::Merchant, 'status' => UserStatus::Active,
        ]);

        return Merchant::create([
            'user_id' => $owner->id, 'business_name' => $name, 'owner_name' => 'Owner',
            'address_line1' => '1 Main Road', 'city' => 'Madurai', 'pincode' => '625001',
            'latitude' => 9.9195, 'longitude' => 78.1193,
            'kyc_status' => KycStatus::Verified, 'is_accepting_orders' => true,
        ]);
    }

    private function stockTheKitchen(Merchant $shop): MenuItem
    {
        Storage::disk(config('media.disk'))->put('menu/'.$shop->id.'/biryani.webp', 'photo-bytes');

        $category = Category::create([
            'merchant_id' => $shop->id, 'name' => 'Biryani', 'sort_order' => 1, 'is_active' => true,
        ]);

        $item = MenuItem::create([
            'merchant_id' => $shop->id, 'category_id' => $category->id,
            'name' => 'Mutton Biryani', 'description' => 'Slow cooked, seeraga samba rice.',
            'price' => 250, 'compare_at_price' => 300, 'gst_rate' => 5,
            'is_veg' => false, 'contains_egg' => false, 'is_available' => true,
            'prep_time_minutes' => 25, 'sort_order' => 1,
        ]);

        $item->forceFill([
            'image_path' => 'menu/'.$shop->id.'/biryani.webp',
            // Earned by this kitchen, from its own customers.
            'rating' => 4.6, 'rating_count' => 42, 'is_bestseller' => true,
            // Tonight's leftovers, in this kitchen.
            'is_surplus_deal' => true, 'surplus_quantity' => 4,
        ])->save();

        $group = ItemOptionGroup::create([
            'menu_item_id' => $item->id, 'name' => 'Portion', 'selection' => 'single',
            'is_required' => true, 'min_selections' => 1, 'max_selections' => 1, 'sort_order' => 1,
        ]);

        ItemOption::create([
            'item_option_group_id' => $group->id, 'name' => 'Full',
            'price_delta' => 80, 'is_available' => true, 'sort_order' => 1,
        ]);

        return $item;
    }

    /* ------------------------------------------- what it copies ---- */

    public function test_the_menu_arrives_whole(): void
    {
        $from = $this->merchant('Kishore Food');
        $to = $this->merchant('Vijay Wedding Biryani');
        $this->stockTheKitchen($from);

        $this->artisan('nexmile:copy-menu', ['from' => 'Kishore Food', 'to' => 'Vijay Wedding'])
            ->assertSuccessful();

        $copy = MenuItem::where('merchant_id', $to->id)->sole();

        $this->assertSame('Mutton Biryani', $copy->name);
        $this->assertSame('Slow cooked, seeraga samba rice.', $copy->description);
        $this->assertSame('250.00', $copy->price);
        $this->assertSame('5.00', $copy->gst_rate);
        $this->assertSame(25, $copy->prep_time_minutes);

        // The category came with it, and belongs to the new restaurant.
        $this->assertSame($to->id, $copy->category->merchant_id);
        $this->assertSame('Biryani', $copy->category->name);

        // So did the options.
        $option = $copy->optionGroups->sole()->options->sole();
        $this->assertSame('Full', $option->name);
        $this->assertSame('80.00', $option->price_delta);
    }

    /* ------------------------------------- what it will not copy --- */

    public function test_it_does_not_hand_over_another_kitchens_reputation(): void
    {
        /*
         * A rating belongs to the people who ate at the first restaurant,
         * and a bestseller badge is earned from sales that did not happen
         * here. Copied, they would be a claim the new kitchen has not made
         * and cannot support.
         */
        $from = $this->merchant('Kishore Food');
        $to = $this->merchant('Vijay Wedding Biryani');
        $this->stockTheKitchen($from);

        $this->artisan('nexmile:copy-menu', ['from' => 'Kishore', 'to' => 'Vijay'])->assertSuccessful();

        $copy = MenuItem::where('merchant_id', $to->id)->sole();

        $this->assertNull($copy->rating);
        $this->assertSame(0, (int) $copy->rating_count);
        $this->assertFalse((bool) $copy->is_bestseller);
    }

    public function test_it_does_not_advertise_somebody_elses_leftovers(): void
    {
        /*
         * A surplus deal is tonight's unsold food in a particular kitchen.
         * Copying one sells a portion that does not exist, to a customer who
         * will arrive to collect it.
         */
        $from = $this->merchant('Kishore Food');
        $to = $this->merchant('Vijay Wedding Biryani');
        $this->stockTheKitchen($from);

        $this->artisan('nexmile:copy-menu', ['from' => 'Kishore', 'to' => 'Vijay'])->assertSuccessful();

        $copy = MenuItem::where('merchant_id', $to->id)->sole();

        $this->assertFalse((bool) $copy->is_surplus_deal);
        $this->assertNull($copy->surplus_quantity);
    }

    public function test_the_photograph_is_copied_and_not_shared(): void
    {
        /*
         * Pointing both dishes at one object is a line of code and no bytes,
         * and it is a trap: replacing a photo deletes the one it replaced.
         * The day the second restaurant updates their biryani picture, the
         * first restaurant's biryani loses its image and nobody connects the
         * two events.
         */
        $from = $this->merchant('Kishore Food');
        $to = $this->merchant('Vijay Wedding Biryani');
        $original = $this->stockTheKitchen($from);

        $this->artisan('nexmile:copy-menu', ['from' => 'Kishore', 'to' => 'Vijay'])->assertSuccessful();

        $copy = MenuItem::where('merchant_id', $to->id)->sole();
        $disk = Storage::disk(config('media.disk'));

        $this->assertNotSame($original->image_path, $copy->image_path);
        $disk->assertExists($copy->image_path);
        $disk->assertExists($original->image_path);
        $this->assertSame($disk->get($original->image_path), $disk->get($copy->image_path));
    }

    /* ------------------------------------------- doing it twice ---- */

    public function test_running_it_again_adds_nothing(): void
    {
        $from = $this->merchant('Kishore Food');
        $to = $this->merchant('Vijay Wedding Biryani');
        $this->stockTheKitchen($from);

        $this->artisan('nexmile:copy-menu', ['from' => 'Kishore', 'to' => 'Vijay'])->assertSuccessful();
        $this->artisan('nexmile:copy-menu', ['from' => 'Kishore', 'to' => 'Vijay'])->assertSuccessful();

        $this->assertSame(1, MenuItem::where('merchant_id', $to->id)->count());
        $this->assertSame(1, Category::where('merchant_id', $to->id)->count());
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        // The way to run this the first time on a live restaurant.
        $from = $this->merchant('Kishore Food');
        $to = $this->merchant('Vijay Wedding Biryani');
        $this->stockTheKitchen($from);

        $this->artisan('nexmile:copy-menu', ['from' => 'Kishore', 'to' => 'Vijay', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(0, MenuItem::where('merchant_id', $to->id)->count());
        $this->assertSame(0, Category::where('merchant_id', $to->id)->count());
    }

    /* --------------------------------------- leaving the source --- */

    public function test_the_original_is_read_and_never_written(): void
    {
        $from = $this->merchant('Kishore Food');
        $to = $this->merchant('Vijay Wedding Biryani');
        $original = $this->stockTheKitchen($from);

        $before = $original->fresh()->toArray();

        $this->artisan('nexmile:copy-menu', ['from' => 'Kishore', 'to' => 'Vijay'])->assertSuccessful();

        $this->assertSame($before, $original->fresh()->toArray());
        $this->assertSame(1, MenuItem::where('merchant_id', $from->id)->count());
    }

    /* ----------------------------------------- naming the shops --- */

    public function test_an_ambiguous_name_is_refused_rather_than_guessed(): void
    {
        /*
         * This writes a menu onto a live restaurant. Picking the first of
         * several matches is how the wrong kitchen ends up advertising
         * somebody else's biryani.
         */
        $this->merchant('Kishore Food');
        $this->merchant('Kishore Food Express');
        $this->merchant('Vijay Wedding Biryani');

        $this->artisan('nexmile:copy-menu', ['from' => 'Kishore', 'to' => 'Vijay'])
            ->expectsOutputToContain('matches more than one restaurant')
            ->assertFailed();
    }

    public function test_an_unknown_name_is_refused(): void
    {
        $this->merchant('Kishore Food');

        $this->artisan('nexmile:copy-menu', ['from' => 'Kishore', 'to' => 'Nobody At All'])
            ->assertFailed();
    }

    public function test_a_restaurant_cannot_be_copied_onto_itself(): void
    {
        $this->merchant('Kishore Food');

        $this->artisan('nexmile:copy-menu', ['from' => 'Kishore', 'to' => 'Kishore'])
            ->expectsOutputToContain('same restaurant')
            ->assertFailed();
    }
}
