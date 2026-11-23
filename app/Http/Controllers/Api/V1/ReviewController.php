<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Review;
use App\Services\Reviews\ReviewDraft;
use App\Services\Reviews\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Ratings left by a customer against a delivered order (EP12).
 */
class ReviewController extends Controller
{
    public function __construct(protected ReviewService $reviews) {}

    /**
     * The review left on an order
     *
     * `data` is null when the customer has not reviewed it yet, so the app can
     * tell "not rated" from "rated 0".
     */
    public function show(Request $request, int $order): JsonResponse
    {
        $model = $this->order($request, $order);

        return response()->json([
            'data' => $model->review === null ? null : $this->payload($model->review),
        ]);
    }

    /**
     * Leave a review
     *
     * Once per order, only after it was delivered, and only inside the rating
     * window (`reviews.window_days`). Everything but `rating` is optional.
     *
     * The `rider` block is dropped entirely on a collected order — there is
     * nobody to attach it to. Its `comment` is for ops and the rider's record
     * and is never shown publicly.
     */
    public function store(Request $request, int $order): JsonResponse
    {
        $model = $this->order($request, $order);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:1000'],

            /*
             * Optional per-dish scores, keyed by menu_item_id:
             *   {"dishes": {"41": 5, "44": 3}}
             *
             * Anything not on the order is ignored rather than refused — a
             * stale menu id from a slow screen should not lose the whole
             * review, and rating dishes you did not buy is the cheapest way
             * there is to bury a competitor.
             */
            'dishes' => ['sometimes', 'array', 'max:30'],
            'dishes.*' => ['integer', 'between:1,5'],

            /*
             * Tag codes, not labels — the apps hold the translations. The
             * vocabulary is in config/reviews.php and checked below, where the
             * error can be reported against `tags` rather than `tags.3`.
             */
            'tags' => ['sometimes', 'array', 'max:'.config('reviews.max_tags')],
            'tags.*' => ['string'],

            'rider' => ['sometimes', 'array'],
            'rider.rating' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'rider.tags' => ['sometimes', 'array', 'max:'.config('reviews.max_tags')],
            'rider.tags.*' => ['string'],
            'rider.comment' => [
                'sometimes', 'nullable', 'string',
                'max:'.config('reviews.rider_comment_max'),
            ],

            /*
             * The flat form this endpoint took before there was anything else
             * to say about a rider. Still accepted so a client already
             * shipped with it keeps working; `rider.rating` wins when both
             * arrive.
             */
            'rider_rating' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
        ]);

        $this->validateTags($request);

        $review = $this->reviews->leave(
            $model,
            $request->user(),
            ReviewDraft::fromRequest($data),
        );

        return response()->json([
            'message' => 'Thanks for the feedback.',
            'data' => $this->payload($review),
        ], 201);
    }

    /**
     * Check the tag codes, reporting against `tags` and `rider.tags`.
     *
     * Done here rather than with an `in:` rule on `tags.*` so the error lands
     * on the field the app sent. A rule on the member reports `tags.2`, which
     * means the app has to parse a key to find out which of its own lists was
     * wrong — and an unknown code is a client that is ahead of the server, the
     * one case where the message needs to be useful to a developer.
     *
     * The subject is what is enforced: food codes on the food, delivery codes
     * on the rider. A delivery tag arriving on the food is not an opinion, it
     * is a mistake, and letting it through would put "food_spilled" on a
     * kitchen's report. Which codes an app offers at which star count is the
     * app's business — four stars and "too_oily" is a customer telling the
     * truth, and refusing it would lose the sentence as well as the tag.
     */
    private function validateTags(Request $request): void
    {
        $fields = [
            'tags' => config('reviews.tags.food'),
            'rider.tags' => config('reviews.tags.rider'),
        ];

        $errors = [];

        foreach ($fields as $field => $allowed) {
            $sent = $request->input($field);

            if (! is_array($sent)) {
                continue;
            }

            $unknown = array_values(array_diff(array_map('strval', $sent), $allowed));

            if ($unknown !== []) {
                // Named, not counted. "Unknown tag" alone leaves a developer
                // diffing two lists by eye.
                $errors[$field] = 'Unknown tag: '.implode(', ', $unknown).'.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function order(Request $request, int $order): Order
    {
        // Scoped to the caller: an order id is not a licence to read someone
        // else's dinner.
        return $request->user()->orders()->with('review')->findOrFail($order);
    }

    /** @return array<string, mixed> */
    private function payload(Review $review): array
    {
        return [
            'id' => $review->id,
            'order_id' => $review->order_id,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'tags' => $review->tags ?? [],

            // Only what was kept: a dish that was not on the order is silently
            // dropped, and the app should show what actually saved.
            'dishes' => $review->items()->pluck('rating', 'menu_item_id'),

            /*
             * Echoed back to the person who wrote it, which is not the same as
             * publishing it. `rider.comment` reaching a restaurant's review
             * list would be the leak; reaching the customer who typed it a
             * second ago is how they see what saved.
             *
             * Null throughout on a collected order, because nothing was
             * stored — see ReviewService::leave.
             */
            'rider' => [
                'rating' => $review->rider_rating,
                'tags' => $review->rider_tags ?? [],
                'comment' => $review->rider_comment,
            ],

            // The flat field the old shape returned. Kept so a client reading
            // it does not start seeing null.
            'rider_rating' => $review->rider_rating,

            'created_at' => $review->created_at,
        ];
    }
}
