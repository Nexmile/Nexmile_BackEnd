<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Refund;
use App\Models\ServiceApiLog;
use App\Models\ServiceClient;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;

/**
 * The service API from section 9 of the AI BPO specification.
 *
 * Most of this file is about refusal. These endpoints answer questions about
 * people who are not the caller, which is the opposite of every other route
 * in the platform, so the interesting tests are the ones proving a caller
 * cannot reach what it should not.
 *
 * The single most important test here is that a customer's own token cannot
 * open this door. Sanctum will authenticate one happily — it is a valid
 * token — and if the scope check did not also ask *what kind of thing* is
 * holding it, any signed-in customer could read any other customer's orders.
 */
class InternalServiceApiTest extends CartTest
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('service:1');
    }

    /** @param list<string> $scopes */
    private function service(array $scopes = ['orders.read'], array $attributes = []): ServiceClient
    {
        static $n = 0;
        $n++;

        $client = ServiceClient::create([
            'name' => 'BPO platform '.$n,
            'slug' => 'bpo-platform-'.$n,
            'is_active' => true,
            'rate_limit_per_minute' => 120,
            ...$attributes,
        ]);

        Sanctum::actingAs($client, $scopes);

        return $client;
    }

    private function placedOrder(): Order
    {
        $customer = $this->customer();
        Sanctum::actingAs($customer);

        $shop = $this->restaurant();
        $dish = $this->dish($shop);

        $address = $customer->addresses()->create([
            'label' => 'home',
            'contact_name' => 'Meena',
            'contact_phone' => '9876543210',
            'line1' => '4 Gandhi Nagar',
            'city' => 'Madurai',
            'pincode' => '625020',
            'latitude' => 9.9200,
            'longitude' => 78.1195,
        ]);

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $dish->id, 'quantity' => 1,
        ])->assertCreated();

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery',
            'payment_method' => 'cod',
            'address_id' => $address->id,
        ])->assertCreated();

        return Order::latest('id')->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Who may open the door
    |--------------------------------------------------------------------------
    */

    public function test_a_customer_token_cannot_reach_the_internal_api(): void
    {
        $order = $this->placedOrder();

        // Still signed in as the customer who placed it — their own order.
        Sanctum::actingAs($order->customer);

        /*
         * Even for their own order. The endpoint is not for people at all,
         * and allowing it "just for the owner" would mean the kind-of-caller
         * check lives in each controller instead of at the door.
         */
        $this->getJson("/api/internal/v1/orders/{$order->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'SERVICE_ACCESS_DENIED');
    }

    public function test_an_unauthenticated_call_is_refused(): void
    {
        $order = $this->placedOrder();

        app('auth')->forgetGuards();

        $this->getJson("/api/internal/v1/orders/{$order->id}")->assertStatus(401);
    }

    public function test_a_credential_without_the_scope_is_refused(): void
    {
        $order = $this->placedOrder();
        $this->service(['merchants.read']);

        $this->getJson("/api/internal/v1/orders/{$order->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'SERVICE_ACCESS_DENIED');
    }

    public function test_a_suspended_integration_is_refused(): void
    {
        $order = $this->placedOrder();
        $this->service(['orders.read'], ['is_active' => false]);

        $this->getJson("/api/internal/v1/orders/{$order->id}")->assertStatus(403);
    }

    public function test_revoking_a_client_stops_it_immediately(): void
    {
        $order = $this->placedOrder();
        $client = $this->service(['orders.read']);

        $this->getJson("/api/internal/v1/orders/{$order->id}")->assertOk();

        $this->artisan('nexmile:revoke-service-client', ['client' => $client->slug])
            ->assertSuccessful();

        $this->assertFalse($client->fresh()->is_active);
        $this->assertSame(0, $client->tokens()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | What it answers
    |--------------------------------------------------------------------------
    */

    public function test_an_order_comes_back_with_its_status_and_timing(): void
    {
        $order = $this->placedOrder();
        $this->service(['orders.read']);

        $body = $this->getJson("/api/internal/v1/orders/{$order->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame($order->id, $body['id']);
        $this->assertSame('placed', $body['status']);
        $this->assertNotNull($body['restaurant']['name']);
        $this->assertArrayHasKey('placed_at', $body['timestamps']);

        // No rider yet, and said so rather than omitted.
        $this->assertNull($body['rider']);
    }

    public function test_the_delivery_address_never_crosses_the_boundary(): void
    {
        $order = $this->placedOrder();
        $this->service(['orders.read']);

        $body = $this->getJson("/api/internal/v1/orders/{$order->id}")->assertOk()->json();

        /*
         * Support resolves a problem; it does not need to know which door.
         * The area is enough to say "a delivery in Madurai" and not enough to
         * find anybody.
         */
        $flat = json_encode($body);
        $this->assertStringNotContainsString('4 Gandhi Nagar', (string) $flat);
        $this->assertStringNotContainsString('625020', (string) $flat);
    }

    public function test_a_customers_recent_orders_are_capped(): void
    {
        $order = $this->placedOrder();
        $this->service(['orders.read']);

        $this->getJson("/api/internal/v1/customers/{$order->user_id}/orders?limit=50")
            ->assertStatus(422)
            ->assertJsonValidationErrors('limit');

        $this->getJson("/api/internal/v1/customers/{$order->user_id}/orders")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_cash_order_is_due_on_delivery_rather_than_unpaid(): void
    {
        /*
         * Its own answer, not "unpaid", and the distinction is the point.
         *
         * A cash order that has not been paid for is not a problem — it is a
         * cash order. A support system that reported it as unpaid would have
         * somebody chasing a payment that was never due, and saying so to the
         * customer would be an accusation.
         */
        $order = $this->placedOrder();
        $this->service(['orders.read']);

        $this->getJson("/api/internal/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_state', 'due_on_delivery')
            ->assertJsonPath('data.amount_paid', 0);
    }

    public function test_the_order_list_says_whether_each_one_is_still_live(): void
    {
        /*
         * The support system has to tell "being cooked" from "delivered last
         * Tuesday" to decide whether to ask which order somebody means.
         *
         * It is sent rather than inferred on purpose. The caller could hold
         * its own list of which statuses count as finished, and that list
         * would be right until somebody here adds a status — after which a
         * live order reads as finished in another codebase, and nothing
         * fails anywhere. The enum knows; this is it saying so.
         */
        $order = $this->placedOrder();
        $this->service(['orders.read']);

        $row = $this->getJson("/api/internal/v1/customers/{$order->user_id}/orders")
            ->assertOk()
            ->json('data.0');

        $this->assertFalse($row['is_terminal']);
        $this->assertSame($order->user_id, $row['customer_id']);
        $this->assertNotEmpty($row['status_label']);

        // And a finished one says so.
        $order->forceFill(['status' => \App\Enums\OrderStatus::Delivered, 'delivered_at' => now()])->save();

        $this->assertTrue(
            $this->getJson("/api/internal/v1/customers/{$order->user_id}/orders")
                ->assertOk()
                ->json('data.0.is_terminal'),
        );
    }

    public function test_the_order_carries_what_was_actually_ordered(): void
    {
        /*
         * Half of what support is ever asked is about the food rather than
         * the delivery — one missing, the wrong dish, a second biryani
         * nobody ordered. Without the lines, the answer to all of it was to
         * hand over and ask somebody to look it up.
         *
         * Name, count and line total. No options, no notes, no item ids:
         * enough to say "two Chicken 65" back to a customer and recognise
         * what they are describing, and not a copy of the basket.
         */
        $order = $this->placedOrder();
        $this->service(['orders.read']);

        $items = $this->getJson("/api/internal/v1/orders/{$order->id}")
            ->assertOk()
            ->json('data.items');

        $this->assertNotEmpty($items);
        $this->assertArrayHasKey('name', $items[0]);
        $this->assertArrayHasKey('quantity', $items[0]);
        $this->assertArrayHasKey('line_total', $items[0]);

        // Not the whole row.
        $this->assertArrayNotHasKey('id', $items[0]);
        $this->assertArrayNotHasKey('options', $items[0]);
    }
    public function test_distance_is_absent_until_the_rider_is_carrying_it(): void
    {
        /*
         * Before pickup the rider is riding the other way — towards the
         * kitchen — so reporting the kitchen-to-door distance as how far
         * away they are would tell a customer their food is two kilometres
         * off while the rider is in the next street.
         *
         * The field is sent as null rather than omitted, so a caller can
         * tell "not carrying yet" from "this version does not send it".
         */
        $order = $this->placedOrder();
        $this->service(['riders.read']);

        $body = $this->getJson("/api/internal/v1/orders/{$order->id}/delivery-status")
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('distance_metres', $body);
        $this->assertNull($body['distance_metres']);

        // And no coordinates, because nobody is carrying it.
        $this->assertNull($body['position']);
    }
    public function test_an_unknown_order_is_a_404(): void
    {
        $this->service(['orders.read']);

        $this->getJson('/api/internal/v1/orders/999999')->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Refunds: the line that must not move
    |--------------------------------------------------------------------------
    */

    public function test_the_eligibility_check_issues_nothing(): void
    {
        $order = $this->placedOrder();
        $this->service(['refunds.check']);

        $body = $this->postJson('/api/internal/v1/refunds/eligibility-check', [
            'order_id' => $order->id,
        ])->assertOk()->json('data');

        $this->assertArrayHasKey('eligible_automatically', $body);
        $this->assertArrayHasKey('requires_human_decision', $body);

        // Nothing was created, nothing moved.
        $this->assertSame(0, Refund::count());
    }

    public function test_a_cash_order_is_not_automatically_refundable(): void
    {
        $order = $this->placedOrder();
        $this->service(['refunds.check']);

        $body = $this->postJson('/api/internal/v1/refunds/eligibility-check', [
            'order_id' => $order->id,
        ])->assertOk()->json('data');

        $this->assertFalse($body['eligible_automatically']);
        $this->assertMoney(0.0, $body['amount_refundable_to_source']);
        $this->assertNotEmpty($body['reasons']);
    }

    public function test_there_is_no_endpoint_that_issues_a_refund(): void
    {
        $this->service(array_keys(ServiceClient::scopes()));

        /*
         * The whole security argument for this boundary rests on this. If a
         * route ever appears that lets an outside system move money, a
         * compromise of that system becomes a compromise of the bank account.
         */
        foreach (['/api/internal/v1/refunds', '/api/internal/v1/refunds/issue'] as $path) {
            $this->postJson($path, ['order_id' => 1])->assertStatus(404);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The audit trail
    |--------------------------------------------------------------------------
    */

    public function test_every_call_is_written_down_with_what_it_read(): void
    {
        $order = $this->placedOrder();
        $client = $this->service(['orders.read']);

        $this->getJson("/api/internal/v1/orders/{$order->id}")->assertOk();

        $log = ServiceApiLog::sole();

        $this->assertSame($client->id, $log->service_client_id);
        $this->assertSame('GET', $log->method);
        $this->assertSame('orders.read', $log->scope);
        $this->assertSame(200, $log->status_code);

        // The subject, so the question can be asked from the other end:
        // everything any service has read about this order.
        $this->assertSame('order', $log->subject_type);
        $this->assertSame($order->id, $log->subject_id);
    }

    public function test_a_refused_call_is_written_down_too(): void
    {
        $order = $this->placedOrder();
        $this->service(['merchants.read']);

        $this->getJson("/api/internal/v1/orders/{$order->id}")->assertStatus(403);

        // A run of these is either a misconfigured client or somebody trying
        // doors. Both are worth being able to see.
        $this->assertSame(403, ServiceApiLog::sole()->status_code);
    }

    public function test_the_log_records_the_route_not_the_filled_in_url(): void
    {
        $order = $this->placedOrder();
        $this->service(['orders.read']);

        $this->getJson("/api/internal/v1/orders/{$order->id}")->assertOk();

        // A thousand calls group into one readable line; the id is the
        // subject, recorded separately.
        $this->assertStringContainsString('{order}', ServiceApiLog::sole()->path);
    }

    public function test_the_callers_address_is_hashed_not_stored(): void
    {
        $order = $this->placedOrder();
        $this->service(['orders.read']);

        $this->getJson("/api/internal/v1/orders/{$order->id}")->assertOk();

        $log = ServiceApiLog::sole();

        $this->assertNotNull($log->ip_hash);
        $this->assertSame(64, strlen($log->ip_hash));
        $this->assertStringNotContainsString('127.0.0.1', $log->ip_hash);
    }

    /*
    |--------------------------------------------------------------------------
    | Minting credentials
    |--------------------------------------------------------------------------
    */

    public function test_a_credential_cannot_be_minted_with_a_scope_nothing_enforces(): void
    {
        /*
         * A scope no route checks reads like a restriction and is not one.
         * Refused at the point of minting, where somebody is watching.
         */
        $this->artisan('nexmile:make-service-client', [
            '--name' => 'Typo client',
            '--scopes' => 'orders.reed',
        ])->assertFailed();

        $this->assertSame(0, ServiceClient::count());
    }

    public function test_a_credential_with_no_scopes_is_refused(): void
    {
        $this->artisan('nexmile:make-service-client', [
            '--name' => 'Empty client',
            '--scopes' => ',',
        ])->assertFailed();

        $this->assertSame(0, ServiceClient::count());
    }

    public function test_a_minted_credential_works_for_its_scopes_and_no_others(): void
    {
        $this->artisan('nexmile:make-service-client', [
            '--name' => 'Read only client',
            '--slug' => 'read-only',
            '--scopes' => 'orders.read',
        ])->assertSuccessful();

        $client = ServiceClient::sole();
        $token = $client->tokens()->sole();

        $this->assertTrue($token->can('orders.read'));
        $this->assertFalse($token->can('tickets.write'));
    }
}
