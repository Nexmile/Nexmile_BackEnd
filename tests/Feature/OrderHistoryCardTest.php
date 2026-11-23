<?php

namespace Tests\Feature;

use App\Enums\FulfilmentType;
use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What a history card can show.
 *
 * The app draws each past order as a card with the shop's name, its area and
 * its picture. The name was always there; the other two were not, so every
 * card fell back to an order number and a grey placeholder. These are the two
 * fields that fix it, under the same names the home screen card already uses.
 */
class OrderHistoryCardTest extends TestCase
{
    use RefreshDatabase;

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
            'phone' => '95200000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "card{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Customer,
            'status' => UserStatus::Active,
        ]);
    }

    private function restaurant(?string $logo = null): Merchant
    {
        static $n = 0;
        $n++;

        $owner = User::create([
            'name' => 'Owner '.$n,
            'phone' => '95300000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "cardshop{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Merchant,
            'status' => UserStatus::Active,
        ]);

        if ($logo !== null) {
            Storage::disk('s3')->put($logo, 'not-really-a-png');
        }

        $merchant = Merchant::create([
            'user_id' => $owner->id,
            'business_name' => 'Ponnusamy Hotel',
            'owner_name' => 'Owner',
            'address_line1' => '9 Anna Salai',
            'city' => 'Thirumangalam',
            'pincode' => '625706',
            'kyc_status' => KycStatus::Verified,
        ]);

        // forceFill, because logo_path is deliberately not fillable —
        // ImageService owns that column so a merchant cannot point it
        // at somebody else's object. This is the same path an upload
        // takes.
        if ($logo !== null) {
            $merchant->forceFill(['logo_path' => $logo])->save();
        }

        return $merchant;
    }

    private function order(User $customer, Merchant $merchant): Order
    {
        static $n = 0;
        $n++;

        $order = $merchant->orders()->create([
            'order_number' => 'NXC'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'user_id' => $customer->id,
            'status' => OrderStatus::Delivered,
            'fulfilment_type' => FulfilmentType::Delivery,
            'delivery_contact_name' => 'Meena',
            'delivery_line1' => '4 Gandhi Nagar',
            'delivery_city' => 'Madurai',
            'delivery_pincode' => '625020',
            'items_total' => 60,
            'grand_total' => 90,
            'merchant_payout' => 54,
            'placed_at' => now(),
        ]);

        $order->items()->create([
            'name' => 'Masala Dosa',
            'unit_price' => 60,
            'quantity' => 1,
            'line_total' => 60,
        ]);

        return $order->fresh();
    }

    public function test_a_history_card_gets_the_shops_area_and_picture(): void
    {
        $customer = $this->customer();
        $merchant = $this->restaurant('merchants/logos/ponnusamy.png');
        $order = $this->order($customer, $merchant);

        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data.0.restaurant.name', 'Ponnusamy Hotel')
            ->assertJsonPath('data.0.restaurant.area', 'Thirumangalam')
            ->assertJsonPath(
                'data.0.restaurant.logo_url',
                fn ($url) => is_string($url) && $url !== '',
            );

        // The same card opened, so the app does not have to swap shapes.
        $this->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.restaurant.area', 'Thirumangalam')
            ->assertJsonPath(
                'data.restaurant.logo_url',
                fn ($url) => is_string($url) && $url !== '',
            );
    }

    public function test_a_shop_with_no_picture_still_sends_the_key(): void
    {
        /*
         * Null, not absent. The app shows its placeholder on null; a missing
         * key is the case that crashed it, and most shops in Thirumangalam
         * have not uploaded a logo yet.
         */
        $customer = $this->customer();
        $merchant = $this->restaurant();
        $this->order($customer, $merchant);

        Sanctum::actingAs($customer);

        $response = $this->getJson('/api/v1/orders')->assertOk();

        $response->assertJsonStructure([
            'data' => [
                ['restaurant' => ['id', 'name', 'area', 'logo_url']],
            ],
        ]);

        $this->assertNull($response->json('data.0.restaurant.logo_url'));
        $this->assertSame('Thirumangalam', $response->json('data.0.restaurant.area'));
    }
}
