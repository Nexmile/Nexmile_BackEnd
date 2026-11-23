<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Push\OrderNotifier;
use App\Services\Reviews\ReviewService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * "How was it?", a while after the food arrived.
 *
 * The delivery push already says "tap to rate", and it is sent at the worst
 * possible moment: the customer is at the door with their hands full. Most
 * ratings come from asking again once the meal has been eaten.
 *
 * Scheduled when the order is delivered, `reviews.reminder_after_minutes`
 * later. Which means **with no queue worker running, no reminder is sent** —
 * nothing else covers for it, deliberately, the same choice CloseDispatchWave
 * documents. A missed reminder costs a rating; a second mechanism quietly
 * covering for a missing worker costs the ability to reason about either.
 *
 * Carries an order id rather than the model, so a deleted row means there is
 * nothing to do rather than a job that fails.
 */
class RequestOrderReview implements ShouldQueue
{
    use Queueable;

    /**
     * Once.
     *
     * A reminder is worth nothing if it is late and worse than nothing if it
     * arrives twice. There is no state to repair on failure, so a retry only
     * risks a second interruption about the same dinner.
     */
    public int $tries = 1;

    public function __construct(public int $orderId) {}

    public function handle(OrderNotifier $notifier): void
    {
        $order = Order::with(['customer', 'review'])->find($this->orderId);

        if ($order === null) {
            return;
        }

        /*
         * Has enough time actually passed?
         *
         * The delay is the queue's business, but the *reason* for it belongs
         * here, because not every queue honours one. On the `sync` driver —
         * a dev machine, the test suite, or a production box whose
         * QUEUE_CONNECTION was never changed — `delay()` is ignored and this
         * runs inside the delivery request. Without this check the customer
         * gets "how was your order?" while they are still at the door with
         * their hands full, which is the exact moment this push exists to
         * avoid.
         */
        $after = (int) config('reviews.reminder_after_minutes');

        if ($order->delivered_at?->copy()->addMinutes($after)->isFuture()) {
            return;
        }

        /*
         * Re-checked now, not when this was scheduled. Three quarters of an
         * hour is long enough for the customer to have rated it themselves,
         * for the order to have been refunded out of `delivered`, or for the
         * window to have been shortened in config — and nagging somebody to
         * rate an order they have already rated is the push that teaches them
         * to turn notifications off.
         */
        if (! ReviewService::canReview($order)) {
            return;
        }

        $notifier->reviewRequested($order);
    }
}
