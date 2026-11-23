<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\MenuItem;
use App\Models\Merchant;
use App\Models\MerchantPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What a restaurant card carries: a short photo strip and one dish on it.
 *
 * Both are for the card alone. A list is the busiest response in the product,
 * so the interesting assertions here are the limits — three photos rather than
 * the whole carousel, and a fixed number of queries however many restaurants
 * are on the screen.
 */
class RestaurantCardMediaTest extends TestCase
{
    use RefreshDatabase;

    /** Madurai. Everything below sits inside the delivery radius of it. */
    private const LAT = 9.9195;

    private const LNG = 78.1193;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
    }

    private function customer(): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'Customer '.$n,
            'phone' => '90400000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "card.customer{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Customer,
            'status' => UserStatus::Active,
        ]);
    }

    private function restaurant(): Merchant
    {
        static $n = 0;
        $n++;

        $owner = User::create([
            'name' => 'Owner '.$n,
            'phone' => '91400000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "card.owner{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Merchant,
            'status' => UserStatus::Active,
        ]);

        return Merchant::create([
            'user_id' => $owner->id,
            'business_name' => 'Hotel '.$n,
            'owner_name' => 'Owner',
            'address_line1' => '9 Anna Salai',
            'city' => 'Madurai',
            'pincode' => '625001',
            'latitude' => self::LAT,
            'longitude' => self::LNG,
            'kyc_status' => KycStatus::Verified,
            'is_accepting_orders' => true,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function dish(Merchant $merchant, string $name, array $attributes = []): MenuItem
    {
        $dish = $merchant->menuItems()->create([
            'name' => $name,
            'price' => 202,
            'is_available' => true,
            ...$attributes,
        ]);

        /*
         * is_bestseller is not fillable: it is recomputed overnight by
         * nexmile:mark-bestsellers from delivered orders, and must never be
         * settable by the restaurant it flatters.
         */
        if ($attributes['is_bestseller'] ?? false) {
            $dish->forceFill(['is_bestseller' => true])->save();
        }

        return $dish->fresh();
    }

    private function photos(Merchant $merchant, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            // forceCreate: image_path is not fillable, because ImageService
            // writes it after the object is stored and a mass-assignable path
            // column is a way to point a record at anything in the bucket.
            MerchantPhoto::forceCreate([
                'merchant_id' => $merchant->id,
                'caption' => "Slide {$i}",
                'position' => $i,
                'image_path' => "merchants/photos/{$merchant->id}-{$i}.jpg",
            ]);
        }
    }

    /** @param  array<string, mixed>  $extra */
    private function query(array $extra = []): string
    {
        return http_build_query([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
            ...$extra,
        ]);
    }

    public function test_a_card_carries_three_photos_not_the_whole_carousel(): void
    {
        $merchant = $this->restaurant();
        $this->photos($merchant, 8);

        Sanctum::actingAs($this->customer());

        $response = $this->getJson('/api/v1/restaurants?'.$this->query())->assertOk();

        $photos = $response->json('data.0.photos');

        $this->assertCount(3, $photos, 'A card takes discovery.card_photos, not the carousel.');

        // In the order the merchant arranged them, and signed like every other
        // image we hand out.
        $this->assertSame(['Slide 1', 'Slide 2', 'Slide 3'], array_column($photos, 'caption'));
        $this->assertNotEmpty($photos[0]['url']);

        /*
         * The storefront still sends all eight. The cap belongs to the card,
         * not to the restaurant.
         */
        $this->getJson("/api/v1/restaurants/{$merchant->id}")
            ->assertOk()
            ->assertJsonCount(8, 'data.photos');
    }

    public function test_the_card_dish_is_a_bestseller(): void
    {
        $merchant = $this->restaurant();
        $this->dish($merchant, 'Plain Dosa');
        $this->dish($merchant, 'Non Veg Loaded', ['is_veg' => false, 'is_bestseller' => true]);

        Sanctum::actingAs($this->customer());

        $this->getJson('/api/v1/restaurants?'.$this->query())
            ->assertOk()
            ->assertJsonPath('data.0.featured_dish.name', 'Non Veg Loaded')
            // 202, not 202.0: a float with nothing after the point serialises
            // without one. Same as matched_dishes, which the app already reads.
            ->assertJsonPath('data.0.featured_dish.price', 202)
            ->assertJsonPath('data.0.featured_dish.is_veg', false);
    }

    public function test_a_restaurant_with_no_bestseller_sends_no_dish(): void
    {
        /*
         * Absent, not substituted. A bestseller is earned from delivered
         * orders and withheld below a minimum, so most kitchens have none
         * yet — and a card promoting whichever dish happened to sort first is
         * the platform recommending something nobody has bought.
         */
        $merchant = $this->restaurant();
        $this->dish($merchant, 'Plain Dosa');

        Sanctum::actingAs($this->customer());

        $this->getJson('/api/v1/restaurants?'.$this->query())
            ->assertOk()
            ->assertJsonMissingPath('data.0.featured_dish');
    }

    public function test_an_unavailable_bestseller_is_not_put_on_a_card(): void
    {
        /*
         * Sold out for the night. Advertising it on the card sends somebody to
         * a shop for a dish checkout would refuse — the same rule the search
         * results already follow.
         */
        $merchant = $this->restaurant();
        $this->dish($merchant, 'Mutton Biryani', ['is_available' => false, 'is_bestseller' => true]);

        Sanctum::actingAs($this->customer());

        $this->getJson('/api/v1/restaurants?'.$this->query())
            ->assertOk()
            ->assertJsonMissingPath('data.0.featured_dish');
    }

    public function test_the_home_screen_cards_carry_the_same_fields(): void
    {
        $merchant = $this->restaurant();
        $this->photos($merchant, 4);
        $this->dish($merchant, 'Non Veg Loaded', ['is_veg' => false, 'is_bestseller' => true]);

        Sanctum::actingAs($this->customer());

        $response = $this->getJson('/api/v1/home?'.$this->query())->assertOk();

        $rail = collect($response->json('data.sections'))->firstWhere('type', 'restaurants');

        $this->assertNotNull($rail, 'The home screen should have a restaurant rail.');
        $this->assertCount(3, $rail['items'][0]['photos']);
        $this->assertSame('Non Veg Loaded', $rail['items'][0]['featured_dish']['name']);
    }

    public function test_the_cost_does_not_grow_with_the_number_of_restaurants(): void
    {
        /*
         * Why both of these are attached to the page rather than read per
         * card: one query for the photos and one for the dishes, whether the
         * screen holds one restaurant or ten. A per-card read is the whole
         * difference between a list that appears and a list that arrives.
         */
        Sanctum::actingAs($this->customer());

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->getJson('/api/v1/restaurants?'.$this->query())->assertOk();

            $queries = count(DB::getRawQueryLog());

            DB::disableQueryLog();

            return $queries;
        };

        $first = $this->restaurant();
        $this->photos($first, 3);
        $this->dish($first, 'Dish 0', ['is_bestseller' => true]);

        $one = $count();

        for ($i = 1; $i < 10; $i++) {
            $other = $this->restaurant();
            $this->photos($other, 3);
            $this->dish($other, 'Dish '.$i, ['is_bestseller' => true]);
        }

        $this->assertSame($one, $count(), 'Ten restaurants must cost what one does.');
    }
}
