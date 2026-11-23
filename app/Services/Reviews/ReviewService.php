<?php

namespace App\Services\Reviews;

use App\Enums\OrderStatus;
use App\Models\MenuItem;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Review;
use App\Models\ReviewItem;
use App\Models\Rider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leaving and aggregating ratings (EP12).
 *
 * The aggregate on the merchant is the number every restaurant card shows, so
 * it is recalculated from the table rather than nudged arithmetically — an
 * incremental average drifts the moment one review is edited or deleted, and
 * the drift is invisible.
 */
class ReviewService
{
    /**
     * Ratings needed before a restaurant shows a score at all.
     *
     * One five-star review from the owner's cousin is not a rating, and a
     * single bad night should not brand a new kitchen 1.0 forever.
     */
    public const MIN_RATINGS_TO_PUBLISH = 3;

    /**
     * Record what a customer said about one delivered order.
     *
     * @throws ValidationException
     */
    public function leave(Order $order, User $actor, ReviewDraft $draft): Review
    {
        if ($order->user_id !== $actor->id) {
            throw ValidationException::withMessages([
                'order' => 'You can only review your own orders.',
            ]);
        }

        if ($order->status !== OrderStatus::Delivered) {
            throw ValidationException::withMessages([
                'order' => 'You can review an order once it has been delivered.',
            ]);
        }

        if ($order->review()->exists()) {
            throw ValidationException::withMessages([
                'order' => 'You have already reviewed this order.',
            ]);
        }

        if (! self::isWithinWindow($order)) {
            throw ValidationException::withMessages([
                'order' => trans_choice('portal.reviews.window_closed', self::windowDays(), [
                    'days' => self::windowDays(),
                ]),
            ]);
        }

        $review = DB::transaction(function () use ($order, $actor, $draft) {
            /*
             * A rider's fields are dropped outright on a collected order, not
             * merely ignored. There is nobody to attach them to, and a stored
             * complaint about a rider who never existed is a row that will one
             * day be read as if it meant something.
             */
            $delivered = $order->rider_id !== null;

            $review = Review::create([
                'order_id' => $order->id,
                'user_id' => $actor->id,
                'merchant_id' => $order->merchant_id,
                // Only where a rider actually carried it: rating nobody for a
                // collected order would be rating thin air.
                'rider_id' => $order->rider_id,
                'rating' => $draft->rating,
                'rider_rating' => $delivered ? $draft->riderRating : null,
                'comment' => $draft->comment,

                // Empty lists are stored as null, so "said nothing" is one
                // value rather than two that readers have to treat alike.
                'tags' => $draft->tags === [] ? null : $draft->tags,
                'rider_tags' => $delivered && $draft->riderTags !== [] ? $draft->riderTags : null,
                'rider_comment' => $delivered ? $draft->riderComment : null,
            ]);

            /*
             * Only dishes that were actually on this order. Otherwise an
             * account could rate every dish in a restaurant from one ₹30 idli,
             * which is the cheapest way there is to bury a competitor.
             */
            $ordered = $order->items()->pluck('menu_item_id')->filter()->all();

            foreach ($draft->dishRatings as $menuItemId => $score) {
                if (! in_array((int) $menuItemId, $ordered, true)) {
                    continue;
                }

                $review->items()->create([
                    'menu_item_id' => (int) $menuItemId,
                    'rating' => (int) $score,
                ]);

                $this->recalculateDish(MenuItem::find($menuItemId));
            }

            $this->recalculate($order->merchant);
            $this->recalculateRider($order->rider);

            return $review;
        });

        return $review;
    }

    /** How many days after delivery an order can still be rated. */
    public static function windowDays(): int
    {
        return max(1, (int) config('reviews.window_days'));
    }

    /**
     * Whether this order is still inside its rating window.
     *
     * Static, and here rather than on the model, because two callers need the
     * same answer: this service when a review arrives, and OrderResource when
     * it tells the app whether to show the button. Those two disagreeing is a
     * button that leads to a 422.
     *
     * Measured from `delivered_at`, falling back to the order's own clock for
     * rows delivered before that column was filled — a null there must not
     * read as "delivered in 1970" and close the window on a live order.
     */
    public static function isWithinWindow(Order $order): bool
    {
        if ($order->status !== OrderStatus::Delivered) {
            return false;
        }

        $delivered = $order->delivered_at ?? $order->updated_at ?? $order->created_at;

        if ($delivered === null) {
            return true;
        }

        return $delivered->copy()->addDays(self::windowDays())->isFuture();
    }

    /**
     * Whether the app should offer a rating button for this order.
     *
     * Delivered, inside the window, and not already rated. The `review`
     * relation is used when it is loaded so a list of twenty orders does not
     * ask the database twenty times.
     */
    public static function canReview(Order $order): bool
    {
        if (! self::isWithinWindow($order)) {
            return false;
        }

        return $order->relationLoaded('review')
            ? $order->review === null
            : ! $order->review()->exists();
    }

    /**
     * Recompute a merchant's published rating from its reviews.
     */
    public function recalculate(Merchant $merchant): void
    {
        $row = Review::query()
            ->where('merchant_id', $merchant->id)
            // A hidden review is evidence, not a rating. Leaving it in the
            // average would make moderation cosmetic.
            ->visible()
            ->selectRaw('COUNT(*) as total, AVG(rating) as average')
            ->first();

        $count = (int) ($row->total ?? 0);

        $merchant->forceFill([
            'rating_count' => $count,
            // Null rather than a number nobody should act on yet.
            'rating' => $count >= self::MIN_RATINGS_TO_PUBLISH
                ? round((float) $row->average, 2)
                : null,
        ])->save();
    }

    /**
     * Recompute one dish's rating from its ratings.
     *
     * Same threshold as a restaurant, and for the same reason: a dish showing
     * "5.0" off a single review is noise wearing the clothes of a signal, and
     * it is the number a customer trusts most because it is the most specific.
     */
    public function recalculateDish(?MenuItem $item): void
    {
        if ($item === null) {
            return;
        }

        $row = ReviewItem::query()
            ->where('menu_item_id', $item->id)
            ->whereHas('review', fn ($q) => $q->visible())
            ->selectRaw('COUNT(*) as total, AVG(rating) as average')
            ->first();

        $count = (int) ($row->total ?? 0);

        $item->forceFill([
            'rating_count' => $count,
            'rating' => $count >= self::MIN_RATINGS_TO_PUBLISH
                ? round((float) $row->average, 2)
                : null,
        ])->save();
    }

    /**
     * Hide a review, and recompute everything it was propping up.
     */
    public function hide(Review $review, User $actor, string $reason): void
    {
        DB::transaction(function () use ($review, $actor, $reason) {
            $review->forceFill([
                'hidden_at' => now(),
                'hidden_by_user_id' => $actor->id,
                'hidden_reason' => $reason,
            ])->save();

            $this->afterModeration($review);
        });
    }

    /** Put one back, when it should not have gone. */
    public function unhide(Review $review): void
    {
        DB::transaction(function () use ($review) {
            $review->forceFill([
                'hidden_at' => null,
                'hidden_by_user_id' => null,
                'hidden_reason' => null,
            ])->save();

            $this->afterModeration($review);
        });
    }

    private function afterModeration(Review $review): void
    {
        $this->recalculate($review->merchant);
        $this->recalculateRider($review->rider);

        // Every dish it rated moves too, or a hidden review keeps holding a
        // dish average up from behind a curtain.
        foreach ($review->items as $item) {
            $this->recalculateDish($item->menuItem);
        }
    }

    /**
     * Recompute a rider's rating from the reviews that mention them.
     *
     * rider_rating was collected on every review and never used — the column
     * existed, the number was captured, and nothing ever wrote it. A rating a
     * customer takes the trouble to give and nobody reads is worse than not
     * asking.
     *
     * Same threshold as everything else: one bad night should not brand
     * someone, and a rider is the person here with the least protection from
     * a number nobody sanity-checks.
     */
    public function recalculateRider(?Rider $rider): void
    {
        if ($rider === null) {
            return;
        }

        $row = Review::query()
            ->where('rider_id', $rider->id)
            ->whereNotNull('rider_rating')
            ->visible()
            ->selectRaw('COUNT(*) as total, AVG(rider_rating) as average')
            ->first();

        $count = (int) ($row->total ?? 0);

        $rider->forceFill([
            'rating_count' => $count,
            'rating' => $count >= self::MIN_RATINGS_TO_PUBLISH
                ? round((float) $row->average, 2)
                : null,
        ])->save();
    }
}
