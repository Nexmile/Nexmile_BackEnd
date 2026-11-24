<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\ServiceClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The support bubble, from the customer app's side.
 *
 * Nexmile holds no conversation — the BPO platform does — so what is tested
 * here is the proxy layer's two jobs, both of which the BPO cannot do for
 * itself: establishing who the customer is, and whether an order is theirs.
 *
 * The BPO is faked throughout. These tests are about what Nexmile sends and
 * what it refuses to send, not about whether a thread works.
 */
class SupportBubbleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('support.token', 'test-bpo-token');
        config()->set('support.base_url', 'https://bpo.test/api/service/v1');

        Http::preventStrayRequests();

        /*
         * One stub, reading state the tests change.
         *
         * Http::fake() stacks rather than replaces — the first matching stub
         * wins — so a second call inside a test would be ignored. The closure
         * keeps there being exactly one.
         */
        Http::fake(['bpo.test/*' => fn () => Http::response($this->bpoBody, $this->bpoStatus)]);

        $this->fakeBpo();
    }

    /** @var array<string, mixed>|null */
    private ?array $bpoBody = null;

    private int $bpoStatus = 200;

    /** @param array<string, mixed> $data */
    private function fakeBpo(array $data = [], int $status = 200): void
    {
        $this->bpoStatus = $status;
        $this->bpoBody = [
            'data' => [
                'id' => '0193f2a1-7c3d-7b9e-a4e1-2f8c6d5b4a30',
                'status' => 'open',
                'language' => 'en',
                'order_ref' => null,
                'pending_reply' => false,
                'messages' => [],
                ...$data,
            ],
            'meta' => ['pending_reply' => false],
        ];
    }

    /** The platform is down. */
    private function bpoDown(): void
    {
        $this->bpoBody = null;
        $this->bpoStatus = 500;
    }

    private function customer(string $suffix = '01'): User
    {
        return User::create([
            'name' => 'Cust '.$suffix,
            'phone' => '978100000'.$suffix,
            'email' => "support{$suffix}@example.in",
            'password' => 'secret',
            'role' => UserRole::Customer,
            'status' => UserStatus::Active,
        ]);
    }

    private function orderFor(User $user): Order
    {
        $owner = User::create([
            'name' => 'Owner', 'phone' => '9782000011', 'email' => 'supowner@example.in',
            'password' => 'secret', 'role' => UserRole::Merchant, 'status' => UserStatus::Active,
        ]);

        $shop = Merchant::create([
            'user_id' => $owner->id, 'business_name' => 'Ponnusamy Hotel', 'owner_name' => 'Owner',
            'address_line1' => '1 Main Road', 'city' => 'Madurai', 'pincode' => '625001',
            'latitude' => 9.9195, 'longitude' => 78.1193,
            'kyc_status' => KycStatus::Verified, 'is_accepting_orders' => true,
        ]);

        return Order::create([
            'order_number' => 'NX'.random_int(10000, 99999),
            'user_id' => $user->id,
            'merchant_id' => $shop->id,
            'status' => OrderStatus::Delivered,
            'fulfilment_type' => 'delivery',
            'items_total' => 200, 'packaging_fee' => 0, 'delivery_fee' => 25,
            'discount_total' => 0, 'tax_total' => 10, 'grand_total' => 235,
        ]);
    }

    /** @return array<int, ClientRequest> */
    private function sent(): array
    {
        $calls = [];

        Http::recorded(function (ClientRequest $request) use (&$calls) {
            $calls[] = $request;

            return true;
        });

        return $calls;
    }

    private function lastBody(): array
    {
        $calls = $this->sent();

        return json_decode(end($calls)->body(), true) ?: [];
    }

    /* ------------------------------------------------- the bubble ---- */

    public function test_the_bubble_knows_what_to_say_before_it_is_tapped(): void
    {
        Sanctum::actingAs($this->customer());

        $this->getJson('/api/v1/support/entry')
            ->assertOk()
            ->assertJsonPath('data.label', __('support.label'))
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.unread', 0);
    }

    public function test_an_unread_count_the_platform_does_not_send_is_zero(): void
    {
        /*
         * This used to count "replies since the customer last spoke", here,
         * from the message list. That rule left a badge on the bubble
         * forever for anyone who read an answer and was satisfied by it, and
         * the counting now happens on the platform against a read marker.
         *
         * What is left to check here is the absence case: an older platform,
         * or a thread with nothing in it, must read as nothing to see rather
         * than as an error.
         */
        $this->fakeBpo();

        Sanctum::actingAs($this->customer());

        $this->getJson('/api/v1/support/entry')->assertOk()->assertJsonPath('data.unread', 0);
    }

    /* ------------------------------------------------ who they are ---- */

    public function test_the_customer_reference_is_the_signed_in_user_never_the_request(): void
    {
        /*
         * The BPO trusts whatever `customer_ref` it is handed. That makes
         * this the line that stops one customer reading another's support
         * history, and it has to be taken from the session — so an id in the
         * body must change nothing at all.
         */
        Sanctum::actingAs($user = $this->customer());

        $this->postJson('/api/v1/support/conversations', ['customer_ref' => '99999'])
            ->assertOk();

        $this->assertSame((string) $user->id, $this->lastBody()['customer_ref']);
    }

    /* -------------------------------------------- whose order it is --- */

    public function test_an_order_of_their_own_is_passed_to_the_conversation(): void
    {
        Sanctum::actingAs($user = $this->customer());
        $order = $this->orderFor($user);

        $this->postJson('/api/v1/support/conversations', ['order_id' => $order->id])->assertOk();

        $this->assertSame((string) $order->id, $this->lastBody()['order_ref']);
    }

    public function test_somebody_elses_order_is_dropped_rather_than_discussed(): void
    {
        /*
         * The test this file exists for. An order id arriving from a phone is
         * not evidence of anything: without this check, "I need help with
         * order 10432" hands the AI a stranger's dinner — their restaurant,
         * their address, their delivery — to talk about.
         *
         * Dropped, not refused: the customer still gets a thread. Refusing
         * would turn a stale id on a back-stacked screen into somebody who
         * cannot reach support at all, and support is the one part of the app
         * that must never be the broken part.
         */
        $stranger = $this->customer('02');
        $order = $this->orderFor($stranger);

        Sanctum::actingAs($this->customer('03'));

        $this->postJson('/api/v1/support/conversations', ['order_id' => $order->id])
            ->assertOk();

        $this->assertArrayNotHasKey('order_ref', $this->lastBody());
    }

    public function test_an_order_that_does_not_exist_is_dropped_too(): void
    {
        Sanctum::actingAs($this->customer());

        $this->postJson('/api/v1/support/conversations', ['order_id' => 987654])->assertOk();

        $this->assertArrayNotHasKey('order_ref', $this->lastBody());
    }

    /* ------------------------------------------------- messages ------- */

    public function test_sending_answers_202_and_carries_the_client_id(): void
    {
        /*
         * 202, because the interesting half has not happened yet: the reply
         * is generated on the BPO side and arrives seconds later. The client
         * id is what lets a timeout that already reached the server be told
         * apart from a customer tapping send twice.
         */
        Sanctum::actingAs($this->customer());

        $this->postJson('/api/v1/support/conversations/abc/messages', [
            'content' => 'where is my order',
            'client_id' => 'uuid-1',
        ])->assertStatus(202);

        $body = $this->lastBody();

        $this->assertSame('where is my order', $body['content']);
        $this->assertSame('uuid-1', $body['client_ref']);
    }

    public function test_an_empty_message_is_refused_before_it_costs_anything(): void
    {
        Sanctum::actingAs($this->customer());

        $this->postJson('/api/v1/support/conversations/abc/messages', ['content' => ''])
            ->assertStatus(422);

        $this->assertSame([], $this->sent());
    }

    public function test_the_handover_message_is_the_servers_words(): void
    {
        /*
         * There are no agents on shift, so the reply window has to be honest
         * and changeable from the server. An app writing its own "an agent
         * will be with you shortly" makes a promise neither end can keep.
         */
        $this->fakeBpo([
            'status' => 'handed_over',
            'messages' => [['sender' => 'system', 'content' => 'Passed to the team. Reply within 4 working hours.']],
        ]);

        Sanctum::actingAs($this->customer());

        $this->postJson('/api/v1/support/conversations/abc/handover')
            ->assertOk()
            ->assertJsonPath('data.system_message', 'Passed to the team. Reply within 4 working hours.');
    }

    /* ---------------------------------------------- when it is down --- */

    public function test_support_being_down_is_a_503_a_customer_can_read(): void
    {
        /*
         * This is the one part of the app that exists for when something has
         * already gone wrong, so it fails in words rather than in a stack
         * trace, and the app shows the phone number.
         */
        $this->bpoDown();

        Sanctum::actingAs($this->customer());

        $this->postJson('/api/v1/support/conversations/abc/messages', ['content' => 'hello'])
            ->assertStatus(503)
            ->assertJsonPath('message', __('support.unavailable'));
    }

    public function test_the_bubble_still_appears_when_support_is_down(): void
    {
        // It is the route to a phone number as well as to a chat.
        $this->bpoDown();

        Sanctum::actingAs($this->customer());

        $this->getJson('/api/v1/support/entry')
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.label', __('support.label'));
    }

    /* ----------------------------------------------- not anonymous ---- */

    public function test_support_needs_a_signed_in_customer(): void
    {
        $this->postJson('/api/v1/support/conversations', [])->assertStatus(401);

        $this->assertSame([], $this->sent());
    }

    public function test_an_unconfigured_backend_says_support_is_unavailable(): void
    {
        /*
         * The natural deployment order is this application first, before the
         * shared secret exists on either side. Without this, every support
         * request on an otherwise healthy backend would be a 500 — on the one
         * screen a customer reaches for when something has already gone
         * wrong.
         */
        config()->set('support.token', null);

        Sanctum::actingAs($this->customer('06'));

        $this->postJson('/api/v1/support/conversations/abc/messages', ['content' => 'hello'])
            ->assertStatus(503)
            ->assertJsonPath('message', __('support.unavailable'));

        // And nothing was sent anywhere.
        $this->assertSame([], $this->sent());
    }

    public function test_the_order_number_is_sent_so_a_switch_can_be_worded(): void
    {
        // The platform holds an id. Without the label it would have to call
        // back here for one, inside a request a customer is waiting on.
        Sanctum::actingAs($user = $this->customer('07'));
        $order = $this->orderFor($user);

        $this->postJson('/api/v1/support/conversations', ['order_id' => $order->id])->assertOk();

        $this->assertSame($order->order_number, $this->lastBody()['order_label']);
    }

    public function test_a_strangers_order_number_is_not_sent_either(): void
    {
        $stranger = $this->customer('08');
        $order = $this->orderFor($stranger);

        Sanctum::actingAs($this->customer('09'));

        $this->postJson('/api/v1/support/conversations', ['order_id' => $order->id])->assertOk();

        $body = $this->lastBody();

        $this->assertArrayNotHasKey('order_ref', $body);
        $this->assertArrayNotHasKey('order_label', $body);
    }

    public function test_feedback_takes_value_as_the_app_sends_it(): void
    {
        Sanctum::actingAs($this->customer('10'));

        $this->postJson('/api/v1/support/messages/abc/feedback', ['value' => 'up'])->assertOk();

        $this->assertSame('up', $this->lastBody()['feedback']);
    }

    public function test_feedback_refuses_anything_but_a_thumb(): void
    {
        Sanctum::actingAs($this->customer('11'));

        $this->postJson('/api/v1/support/messages/abc/feedback', ['value' => 'sideways'])
            ->assertStatus(422);
    }

    public function test_the_unread_count_comes_from_the_platform(): void
    {
        // Counted in one place. Two places deciding what unread means is two
        // places to get it wrong.
        $this->fakeBpo(['unread' => 3]);

        Sanctum::actingAs($this->customer('12'));

        $this->getJson('/api/v1/support/entry')->assertOk()->assertJsonPath('data.unread', 3);
    }

    /* -------------------------------------- the notification door ----- */

    public function test_the_bpo_can_ask_us_to_nudge_a_customer(): void
    {
        Notification::fake();

        $customer = $this->customer('04');

        $client = ServiceClient::create([
            'name' => 'BPO platform', 'slug' => 'bpo-platform',
            'is_active' => true, 'rate_limit_per_minute' => 120,
        ]);

        Sanctum::actingAs($client, ['support.notify']);

        $this->postJson('/api/internal/v1/support-notifications', [
            'customer_ref' => (string) $customer->id,
            'conversation_id' => 'abc-123',
        ])->assertOk()->assertJsonPath('data.sent', true);
    }

    public function test_an_unknown_customer_is_not_confirmed_or_denied(): void
    {
        /*
         * A 200 either way. There is nothing for the caller to retry, and
         * answering "no such customer" to a machine holding a customer id
         * turns this endpoint into a way to check which ids exist.
         */
        $client = ServiceClient::create([
            'name' => 'BPO platform 2', 'slug' => 'bpo-platform-2',
            'is_active' => true, 'rate_limit_per_minute' => 120,
        ]);

        Sanctum::actingAs($client, ['support.notify']);

        $this->postJson('/api/internal/v1/support-notifications', [
            'customer_ref' => '999999',
            'conversation_id' => 'abc-123',
        ])->assertOk()->assertJsonPath('data.sent', false);
    }

    public function test_a_credential_without_the_scope_cannot_nudge_anyone(): void
    {
        $client = ServiceClient::create([
            'name' => 'BPO platform 3', 'slug' => 'bpo-platform-3',
            'is_active' => true, 'rate_limit_per_minute' => 120,
        ]);

        Sanctum::actingAs($client, ['orders.read']);

        $this->postJson('/api/internal/v1/support-notifications', [
            'customer_ref' => '1', 'conversation_id' => 'abc',
        ])->assertStatus(403);
    }

    public function test_a_customers_own_token_cannot_open_the_service_door(): void
    {
        // Sanctum will happily authenticate it — it is a valid token — and
        // everything behind that door is about somebody else.
        Sanctum::actingAs($this->customer('05'));

        $this->postJson('/api/internal/v1/support-notifications', [
            'customer_ref' => '1', 'conversation_id' => 'abc',
        ])->assertStatus(403);
    }

    /* ----------------------------------------------- the bill --------- */

    public function test_a_runaway_client_is_throttled(): void
    {
        /*
         * Each message may cost a model call, so this caps a bill as much as
         * it caps load. Keyed on the customer, not the address: a hostel on
         * one connection is many customers.
         */
        config()->set('support.messages_per_minute', 3);

        Sanctum::actingAs($this->customer());

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/support/conversations/abc/messages', ['content' => "hi {$i}"])
                ->assertStatus(202);
        }

        $this->postJson('/api/v1/support/conversations/abc/messages', ['content' => 'again'])
            ->assertStatus(429);
    }
}
