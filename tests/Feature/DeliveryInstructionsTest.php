<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\RiderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Address;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Rider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Leave it at the door" and "no cutlery", which used to travel inside the
 * order note.
 *
 * The note is one field with two audiences. Everything here is about keeping
 * each half with the person who can act on it: the kitchen packs the bag, the
 * rider stands at the door, and neither needs to read the other's instructions
 * to do their own job.
 *
 * Deliberately **not** extending CartTest or DispatchTest. That chain re-runs
 * every parent test in every child, and this file needs four fixtures, not a
 * second copy of the checkout suite.
 */
class DeliveryInstructionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_chips_and_the_cutlery_flag_are_saved_and_echoed_back(): void
    {
        [$shop, $address] = $this->readyToOrder();

        $body = $this->checkout($shop, $address, [
            'delivery_instructions' => ['leave_at_door', 'no_bell'],
            'no_cutlery' => true,
            'note' => 'Less spicy',
        ])->assertCreated()->json('data');

        // Echoed, so the cart page renders what saved rather than what it sent.
        $this->assertSame(['leave_at_door', 'no_bell'], $body['delivery_instructions']);
        $this->assertTrue($body['no_cutlery']);

        // And the note is left alone — it is the kitchen's field, and the app
        // no longer has to pack three different things into it.
        $this->assertSame('Less spicy', $body['customer_note']);

        $order = Order::sole();
        $this->assertSame(['leave_at_door', 'no_bell'], $order->delivery_instructions);
        $this->assertTrue($order->no_cutlery);
    }

    public function test_an_unknown_chip_is_refused_against_the_field(): void
    {
        /*
         * Keyed to `delivery_instructions`, not `delivery_instructions.1`, so
         * the app does not have to parse an index to find out which of its own
         * chips the server has never heard of.
         */
        [$shop, $address] = $this->readyToOrder();

        $this->checkout($shop, $address, [
            'delivery_instructions' => ['leave_at_door', 'no_horn'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('delivery_instructions')
            // Named, so a developer is not diffing two lists by eye.
            ->assertJsonFragment([
                'delivery_instructions' => ['Unknown delivery instruction: no_horn.'],
            ]);

        $this->assertSame(0, Order::count());
    }

    public function test_more_chips_than_the_cap_are_refused(): void
    {
        [$shop, $address] = $this->readyToOrder();

        $this->checkout($shop, $address, [
            // Every real code, and then one of them again, which is over.
            'delivery_instructions' => [
                ...config('checkout.delivery_instructions'),
                'leave_at_door',
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('delivery_instructions');
    }

    public function test_a_duplicate_chip_counts_once(): void
    {
        // A chip tapped twice is one instruction. Stored twice, the rider
        // reads the same line twice.
        [$shop, $address] = $this->readyToOrder();

        $this->checkout($shop, $address, [
            'delivery_instructions' => ['no_call', 'no_call'],
        ])->assertCreated();

        $this->assertSame(['no_call'], Order::sole()->delivery_instructions);
    }

    public function test_the_instructions_are_dropped_on_a_collected_order(): void
    {
        /*
         * A pickup order has no rider, so "leave at the door" has nobody to
         * instruct. Stored, it would be a row somebody later reads as a
         * delivery nobody made.
         */
        [$shop] = $this->readyToOrder();

        $body = $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'pickup',
            'payment_method' => 'cod',
            'delivery_instructions' => ['leave_at_door'],
        ])->assertCreated()->json('data');

        // Accepted and dropped, not refused: a customer should not lose an
        // order over a chip the app forgot to clear when they switched tabs.
        $this->assertSame([], $body['delivery_instructions']);
        $this->assertNull(Order::sole()->delivery_instructions);
    }

    public function test_the_cutlery_flag_survives_a_collected_order(): void
    {
        // The kitchen packs the bag whether somebody collects it or a rider
        // does, so this half is true of a pickup order too.
        [$shop] = $this->readyToOrder();

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'pickup',
            'payment_method' => 'cod',
            'no_cutlery' => true,
        ])->assertCreated()->assertJsonPath('data.no_cutlery', true);

        $this->assertTrue(Order::sole()->no_cutlery);
    }

    public function test_an_order_that_asks_for_nothing_reads_as_nothing(): void
    {
        // An app that has not shipped the new cart page sends neither field,
        // and must get an order rather than a 422.
        [$shop, $address] = $this->readyToOrder();

        $this->checkout($shop, $address)
            ->assertCreated()
            ->assertJsonPath('data.delivery_instructions', [])
            ->assertJsonPath('data.no_cutlery', false);

        $order = Order::sole();
        $this->assertNull($order->delivery_instructions);
        $this->assertFalse($order->no_cutlery);
    }

    public function test_the_assigned_rider_is_told_what_the_customer_asked_for(): void
    {
        $order = $this->readyOrderWith([
            'delivery_instructions' => ['leave_with_security', 'pet_at_home'],
        ]);

        Sanctum::actingAs($this->rider()->user);

        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")
            ->assertOk()
            ->assertJsonPath(
                'data.dropoff.delivery_instructions',
                ['leave_with_security', 'pet_at_home'],
            );
    }

    public function test_the_offer_board_does_not_say_where_somebody_lives(): void
    {
        /*
         * An offer fans out to every rider on duty. "Leave with security" and
         * "pet at home" are facts about a customer's home, which is why the
         * address and the phone number are not on the board either. These
         * belong inside `dropoff`, and `dropoff` appears once the job is taken.
         */
        $this->readyOrderWith(['delivery_instructions' => ['pet_at_home']]);

        Sanctum::actingAs($this->rider()->user);

        $board = $this->getJson('/api/v1/rider/orders/available')->assertOk()->json('data');

        $this->assertCount(1, $board);
        $this->assertArrayNotHasKey('dropoff', $board[0]);

        // Belt and braces: not under some other key either.
        $this->assertStringNotContainsString('pet_at_home', json_encode($board));
    }

    public function test_the_rider_is_not_sent_the_cutlery_flag(): void
    {
        // A rider cannot take cutlery out of a sealed bag. A flag nobody can
        // act on is a flag that gets ignored, including the day it matters.
        $order = $this->readyOrderWith(['no_cutlery' => true]);

        Sanctum::actingAs($this->rider()->user);

        $body = $this->postJson("/api/v1/rider/orders/{$order->id}/accept")
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('no_cutlery', $body);
        $this->assertStringNotContainsString('no_cutlery', json_encode($body));
    }

    public function test_the_merchant_api_carries_the_cutlery_flag_but_not_the_door_instructions(): void
    {
        /*
         * The merchant API returns the same OrderResource the customer gets,
         * so the audience split has to be made inside the resource rather than
         * assumed from which controller called it. Without this test the
         * instructions reach a kitchen the first time somebody builds a
         * merchant app, and nothing fails.
         */
        $order = $this->readyOrderWith([
            'delivery_instructions' => ['leave_at_door'],
            'no_cutlery' => true,
        ]);

        Sanctum::actingAs($order->merchant->user);

        $body = $this->getJson("/api/v1/merchant/orders/{$order->id}")->assertOk()->json('data');

        $this->assertArrayNotHasKey('delivery_instructions', $body);
        $this->assertStringNotContainsString('leave_at_door', json_encode($body));

        // Theirs, so they do get it.
        $this->assertTrue($body['no_cutlery']);
    }

    public function test_the_customer_still_gets_their_own_instructions_back(): void
    {
        // The gate above is on ownership, so the one person who should see
        // them is worth pinning separately.
        [$shop, $address] = $this->readyToOrder();

        $id = $this->checkout($shop, $address, [
            'delivery_instructions' => ['no_call'],
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/v1/orders/{$id}")
            ->assertOk()
            ->assertJsonPath('data.delivery_instructions', ['no_call']);

        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data.0.delivery_instructions', ['no_call']);
    }

    public function test_the_kitchen_is_shown_the_cutlery_flag_and_not_the_door_instructions(): void
    {
        $order = $this->readyOrderWith([
            'no_cutlery' => true,
            'delivery_instructions' => ['leave_at_door'],
        ]);

        $page = $this->actingAs($order->merchant->user)
            ->get("/merchants/orders/{$order->id}")
            ->assertOk();

        // In their own language, not as a code.
        $page->assertSee(__('portal.orders.no_cutlery'));

        // The door is not the kitchen's business, and a raw code on a merchant
        // screen is noise they would learn to read past.
        $page->assertDontSee('leave_at_door');
    }

    /* ---------------------------------------------------------------- */

    /** @param array<string, mixed> $overrides */
    private function checkout(Merchant $shop, Address $address, array $overrides = []): TestResponse
    {
        return $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery',
            'payment_method' => 'cod',
            'address_id' => $address->id,
            ...$overrides,
        ]);
    }

    /**
     * A signed-in customer with one dish in the cart.
     *
     * @return array{0: Merchant, 1: Address}
     */
    private function readyToOrder(): array
    {
        Sanctum::actingAs($user = $this->customerUser());
        $shop = $this->shop();

        $dish = $shop->menuItems()->create([
            'name' => 'Chicken Biryani',
            'price' => 200,
            'gst_rate' => 5,
        ]);

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $dish->id,
            'quantity' => 1,
        ])->assertCreated();

        return [$shop, $this->addressFor($user)];
    }

    /**
     * An order walked to ready_for_pickup, so a rider can be offered it.
     *
     * @param  array<string, mixed>  $checkout
     */
    private function readyOrderWith(array $checkout): Order
    {
        [$shop, $address] = $this->readyToOrder();

        $id = $this->checkout($shop, $address, $checkout)->assertCreated()->json('data.id');

        Sanctum::actingAs($shop->user);
        $this->postJson("/api/v1/merchant/orders/{$id}/accept")->assertOk();
        $this->postJson("/api/v1/merchant/orders/{$id}/ready")->assertOk();

        return Order::find($id);
    }

    private function customerUser(): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'Cust '.$n,
            'phone' => '97830000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "instr{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Customer,
            'status' => UserStatus::Active,
        ]);
    }

    private function shop(): Merchant
    {
        static $n = 0;
        $n++;

        $owner = User::create([
            'name' => 'Owner '.$n,
            'phone' => '97840000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "instrowner{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Merchant,
            'status' => UserStatus::Active,
        ]);

        return Merchant::create([
            'user_id' => $owner->id,
            'business_name' => 'Ponnusamy Hotel',
            'owner_name' => 'Owner',
            'address_line1' => '1 Main Road',
            'city' => 'Madurai',
            'pincode' => '625001',
            'latitude' => 9.9195,
            'longitude' => 78.1193,
            'kyc_status' => KycStatus::Verified,
            'is_accepting_orders' => true,
            'fssai_license_no' => '12345678901234',
            'fssai_expiry_date' => now()->addYear(),
        ]);
    }

    private function addressFor(User $user): Address
    {
        return $user->addresses()->create([
            'label' => 'home',
            'contact_name' => 'Meena',
            'contact_phone' => '9876543210',
            'line1' => '4 Gandhi Nagar',
            'city' => 'Madurai',
            'pincode' => '625020',
            'latitude' => 9.9200,
            'longitude' => 78.1195,
        ]);
    }

    private function rider(): Rider
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'name' => 'Rider '.$n,
            'phone' => '97850000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "instrrider{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Rider,
            'status' => UserStatus::Active,
        ]);

        return $user->rider()->create([
            'full_name' => 'Selvam K',
            'vehicle_type' => 'motorcycle',
            'vehicle_number' => 'TN59AB'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'kyc_status' => KycStatus::Verified,
            'kyc_verified_at' => now(),
            'driving_licence_expiry' => now()->addYear(),
            'insurance_expiry' => now()->addYear(),
            'duty_status' => RiderStatus::Available,
            'last_latitude' => 9.9195,
            'last_longitude' => 78.1193,
            'last_location_at' => now(),
        ]);
    }
}
