<?php

namespace Tests\Feature;

use App\Enums\FulfilmentType;
use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\RiderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Jobs\RequestOrderReview;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Rider;
use App\Models\User;
use App\Services\Push\OrderNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tags, the rider's half of a review, and the window both close in.
 *
 * A star is a score and nothing else: "3" tells a kitchen something was wrong
 * and not which thing. Tags are the one question most people will answer, so
 * they are what turns a number into something anybody can act on — and the
 * rider's tags are the half that must never be published, because they name
 * one worker who cannot answer them.
 */
class ReviewTagsAndWindowTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'Priya Customer',
            'phone' => '90600000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "tags.customer{$n}@example.in",
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
            'phone' => '91600000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "tags.owner{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Merchant,
            'status' => UserStatus::Active,
        ]);

        return Merchant::create([
            'user_id' => $owner->id,
            'business_name' => 'Ponnusamy Hotel',
            'owner_name' => 'Owner',
            'address_line1' => '9 Anna Salai',
            'city' => 'Madurai',
            'pincode' => '625001',
            'kyc_status' => KycStatus::Verified,
        ]);
    }

    private function rider(): Rider
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'name' => 'Murugan R',
            'phone' => '92600000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "tags.rider{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Rider,
            'status' => UserStatus::Active,
        ]);

        return Rider::create([
            'user_id' => $user->id,
            'full_name' => 'Murugan',
            'vehicle_number' => 'TN58AB'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'vehicle_type' => 'bike',
            'kyc_status' => KycStatus::Verified,
            'duty_status' => RiderStatus::Available,
        ]);
    }

    /** A delivered order, carried by a rider unless `$pickup`. */
    private function order(User $customer, Merchant $merchant, bool $pickup = false): Order
    {
        static $n = 0;
        $n++;

        $order = $merchant->orders()->create([
            'order_number' => 'NXT'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'user_id' => $customer->id,
            'rider_id' => $pickup ? null : $this->rider()->id,
            'status' => OrderStatus::Delivered,
            'fulfilment_type' => $pickup ? FulfilmentType::Pickup : FulfilmentType::Delivery,
            'delivery_contact_name' => 'Priya',
            'delivery_line1' => '4 Gandhi Nagar',
            'delivery_city' => 'Madurai',
            'delivery_pincode' => '625020',
            'items_total' => 200,
            'grand_total' => 240,
            'merchant_payout' => 180,
            'placed_at' => now()->subHours(2),
            'delivered_at' => now()->subHour(),
        ]);

        $order->items()->create([
            'name' => 'Biryani',
            'unit_price' => 200,
            'quantity' => 1,
            'line_total' => 200,
        ]);

        return $order->fresh();
    }

    public function test_a_review_carries_tags_for_the_food_and_the_rider(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, $this->restaurant());

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'rating' => 4,
            'comment' => 'Biryani was great',
            'tags' => ['tasty', 'good_packaging'],
            'rider' => [
                'rating' => 5,
                'tags' => ['on_time', 'polite'],
                'comment' => 'Thanks!',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.tags', ['tasty', 'good_packaging'])
            ->assertJsonPath('data.rider.rating', 5)
            ->assertJsonPath('data.rider.tags', ['on_time', 'polite'])
            ->assertJsonPath('data.rider.comment', 'Thanks!');

        $review = $order->fresh()->review;

        $this->assertSame(['tasty', 'good_packaging'], $review->tags);
        $this->assertSame(['on_time', 'polite'], $review->rider_tags);
        $this->assertSame('Thanks!', $review->rider_comment);

        // The rider's stars reach the rider, which is the whole point of
        // collecting them.
        $this->assertSame(5, $review->rider_rating);
    }

    public function test_an_unknown_tag_is_refused_against_the_field_that_sent_it(): void
    {
        /*
         * `tags`, not `tags.2`. An unknown code means a client is ahead of the
         * server, and an error keyed to a list index makes the app parse a key
         * to find out which of its own lists was wrong.
         */
        $customer = $this->customer();
        $order = $this->order($customer, $this->restaurant());

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'rating' => 2,
            'tags' => ['cold_food', 'tasted_of_regret'],
            'rider' => ['rating' => 3, 'tags' => ['invented_code']],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tags', 'rider.tags'])
            // Named, so a developer does not diff two lists by eye.
            ->assertJsonFragment(['tags' => ['Unknown tag: tasted_of_regret.']]);

        $this->assertNull($order->fresh()->review);
    }

    public function test_a_delivery_tag_cannot_be_filed_against_the_food(): void
    {
        /*
         * The subject is what the server enforces. "food_spilled" is a real
         * code, but on the food it would put a delivery failure on a kitchen's
         * report.
         */
        $customer = $this->customer();
        $order = $this->order($customer, $this->restaurant());

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'rating' => 2,
            'tags' => ['food_spilled'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tags']);
    }

    public function test_praise_is_accepted_alongside_a_low_score(): void
    {
        /*
         * Which tags an app offers at which star count is the app's business.
         * Four stars and "too_oily" is a customer telling the truth, and
         * refusing it would lose the sentence as well as the tag.
         */
        $customer = $this->customer();
        $order = $this->order($customer, $this->restaurant());

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'rating' => 4,
            'tags' => ['tasty', 'too_oily'],
        ])->assertCreated();
    }

    public function test_the_riders_half_is_dropped_on_a_collected_order(): void
    {
        /*
         * Nobody carried it. A stored complaint about a rider who never
         * existed is a row that will one day be read as if it meant something.
         */
        $customer = $this->customer();
        $order = $this->order($customer, $this->restaurant(), pickup: true);

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'rating' => 5,
            'rider' => ['rating' => 1, 'tags' => ['late'], 'comment' => 'Never came'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.rider.rating', null)
            ->assertJsonPath('data.rider.tags', [])
            ->assertJsonPath('data.rider.comment', null);

        $review = $order->fresh()->review;

        $this->assertNull($review->rider_rating);
        $this->assertNull($review->rider_tags);
        $this->assertNull($review->rider_comment);
    }

    public function test_the_flat_rider_rating_is_still_accepted(): void
    {
        // The shape this endpoint took before there was anything else to say
        // about a rider. A client already shipped with it must not break.
        $customer = $this->customer();
        $order = $this->order($customer, $this->restaurant());

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'rating' => 4,
            'rider_rating' => 5,
        ])
            ->assertCreated()
            ->assertJsonPath('data.rider_rating', 5)
            ->assertJsonPath('data.rider.rating', 5);
    }

    public function test_the_nested_rider_rating_wins_when_both_arrive(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, $this->restaurant());

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'rating' => 4,
            'rider_rating' => 2,
            'rider' => ['rating' => 5],
        ])
            ->assertCreated()
            ->assertJsonPath('data.rider.rating', 5);
    }

    public function test_an_order_cannot_be_rated_once_the_window_has_closed(): void
    {
        /*
         * A rating is evidence about one meal on one evening. Five weeks later
         * it is memory, and it lands on a kitchen that has had two hundred
         * orders since and cannot act on it.
         */
        $customer = $this->customer();
        $order = $this->order($customer, $this->restaurant());

        $order->forceFill([
            'delivered_at' => now()->subDays(config('reviews.window_days') + 1),
        ])->save();

        Sanctum::actingAs($customer);

        $response = $this->postJson("/api/v1/orders/{$order->id}/review", ['rating' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['order']);

        // The message is shown to the customer as it is, so it has to say how
        // long they had rather than "not allowed".
        $this->assertStringContainsString(
            (string) config('reviews.window_days'),
            $response->json('errors.order.0'),
        );

        $this->assertNull($order->fresh()->review);
    }

    public function test_an_order_on_the_last_day_of_the_window_can_still_be_rated(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, $this->restaurant());

        $order->forceFill([
            'delivered_at' => now()->subDays(config('reviews.window_days'))->addHour(),
        ])->save();

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/orders/{$order->id}/review", ['rating' => 5])
            ->assertCreated();
    }

    public function test_the_rider_comment_never_reaches_the_public_review_list(): void
    {
        /*
         * The one real privacy edge in this feature. The comment is written by
         * a customer about an identifiable worker, it feeds that worker's
         * record, and a restaurant's public page is not where it belongs.
         */
        $customer = $this->customer();
        $merchant = $this->restaurant();
        $order = $this->order($customer, $merchant);

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'rating' => 2,
            'comment' => 'The food was cold',
            'tags' => ['cold_food'],
            'rider' => [
                'rating' => 1,
                'tags' => ['rude', 'asked_extra_money'],
                'comment' => 'He demanded fifty rupees extra',
            ],
        ])->assertCreated();

        $response = $this->getJson("/api/v1/restaurants/{$merchant->id}/reviews")->assertOk();

        // The food half is published, which is what a review list is for.
        $response->assertJsonPath('data.0.tags', ['cold_food']);
        $response->assertJsonPath('data.0.comment', 'The food was cold');

        // The rider half is not there at all — not as a key, not as a value.
        $body = $response->getContent();

        $this->assertStringNotContainsString('demanded fifty rupees', $body);
        $this->assertStringNotContainsString('asked_extra_money', $body);
        $this->assertStringNotContainsString('rider_comment', $body);
        $this->assertStringNotContainsString('rider_tags', $body);
    }

    public function test_a_rider_comment_longer_than_the_limit_is_refused(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, $this->restaurant());

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'rating' => 3,
            'rider' => ['comment' => str_repeat('a', config('reviews.rider_comment_max') + 1)],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rider.comment']);
    }

    public function test_the_reminder_is_scheduled_when_an_order_is_delivered(): void
    {
        Queue::fake();

        $customer = $this->customer();
        $order = $this->order($customer, $this->restaurant());

        app(OrderNotifier::class)->delivered($order);

        Queue::assertPushed(RequestOrderReview::class, fn ($job) => $job->orderId === $order->id);
    }

    public function test_a_history_row_says_whether_it_was_rated_and_who_brought_it(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, $this->restaurant());

        Sanctum::actingAs($customer);

        // Before rating: the button should be offered, and the rider named so
        // the customer knows who they are rating.
        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data.0.can_review', true)
            ->assertJsonPath('data.0.review', null)
            ->assertJsonPath('data.0.rider.name', 'Murugan')
            ->assertJsonPath('data.0.rider.photo_url', null);

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'rating' => 4,
            'rider' => ['rating' => 5],
        ])->assertCreated();

        // After: the two numbers, and no second invitation.
        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data.0.can_review', false)
            ->assertJsonPath('data.0.review.rating', 4)
            ->assertJsonPath('data.0.review.rider_rating', 5);

        $this->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.can_review', false)
            ->assertJsonPath('data.review.rating', 4)
            ->assertJsonPath('data.rider.name', 'Murugan');
    }

    public function test_a_history_row_stops_offering_the_button_once_the_window_closes(): void
    {
        /*
         * The button and the endpoint have to agree. Answered by the same code
         * that accepts or refuses the review, because two definitions of "can"
         * is a button that leads to a 422.
         */
        $customer = $this->customer();
        $order = $this->order($customer, $this->restaurant());

        $order->forceFill([
            'delivered_at' => now()->subDays(config('reviews.window_days') + 1),
        ])->save();

        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data.0.can_review', false)
            ->assertJsonPath('data.0.review', null);
    }

    public function test_the_history_list_does_not_ask_per_row(): void
    {
        /*
         * can_review is answered from the loaded relation for this reason. A
         * list of ten orders asking "has this been rated?" ten times is the
         * sort of regression that only shows up once somebody has a year of
         * history.
         */
        $customer = $this->customer();
        $merchant = $this->restaurant();

        Sanctum::actingAs($customer);

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->getJson('/api/v1/orders')->assertOk();

            $queries = count(DB::getRawQueryLog());

            DB::disableQueryLog();

            return $queries;
        };

        $this->order($customer, $merchant);

        $one = $count();

        for ($i = 0; $i < 9; $i++) {
            $this->order($customer, $merchant);
        }

        $this->assertSame($one, $count(), 'Ten orders must cost what one does.');
    }

    public function test_the_reminder_is_not_sent_before_its_time(): void
    {
        /*
         * The `sync` queue ignores a delay, so on a dev machine — or a
         * production box whose QUEUE_CONNECTION was never changed — this job
         * runs inside the delivery request. "How was your order?" while the
         * customer is still at the door is the exact moment this push exists
         * to avoid, so the job checks the clock itself rather than trusting
         * the queue to have waited.
         */
        $customer = $this->customer();
        $order = $this->order($customer, $this->restaurant());

        $order->forceFill(['delivered_at' => now()])->save();

        $notifier = $this->mock(OrderNotifier::class);
        $notifier->shouldNotReceive('reviewRequested');

        (new RequestOrderReview($order->id))->handle($notifier);
    }

    public function test_the_reminder_is_sent_once_the_wait_has_passed(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, $this->restaurant());

        $order->forceFill([
            'delivered_at' => now()->subMinutes((int) config('reviews.reminder_after_minutes') + 1),
        ])->save();

        $notifier = $this->mock(OrderNotifier::class);
        $notifier->shouldReceive('reviewRequested')->once();

        (new RequestOrderReview($order->id))->handle($notifier);
    }

    public function test_the_reminder_is_dropped_if_the_order_was_already_rated(): void
    {
        /*
         * Re-checked when the job runs, not when it was scheduled. Nagging
         * somebody to rate an order they have already rated is the push that
         * teaches them to turn notifications off.
         */
        $customer = $this->customer();
        $order = $this->order($customer, $this->restaurant());

        Sanctum::actingAs($customer);
        $this->postJson("/api/v1/orders/{$order->id}/review", ['rating' => 5])->assertCreated();

        $notifier = $this->mock(OrderNotifier::class);
        $notifier->shouldNotReceive('reviewRequested');

        (new RequestOrderReview($order->id))->handle($notifier);
    }
}
