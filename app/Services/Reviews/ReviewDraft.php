<?php

namespace App\Services\Reviews;

/**
 * What a customer said about one delivered order.
 *
 * An object rather than nine positional arguments. Three of the fields are
 * nullable integers or strings about two different subjects — the food and the
 * rider — and `leave($order, $user, 4, 5, null, [], [], [], null)` is a call
 * nobody can read or safely reorder.
 *
 * Validation has already happened: the controller owns the shape of the
 * request, including which tag codes exist. This carries the result.
 */
final readonly class ReviewDraft
{
    /**
     * @param  int  $rating  the food and the restaurant, 1..5
     * @param  array<int|string, int>  $dishRatings  menu_item_id => 1..5
     * @param  list<string>  $tags  food tag codes
     * @param  int|null  $riderRating  null for pickup, or when not given
     * @param  list<string>  $riderTags  delivery tag codes
     * @param  string|null  $riderComment  for ops and the rider's record, never public
     */
    public function __construct(
        public int $rating,
        public ?string $comment = null,
        public array $dishRatings = [],
        public array $tags = [],
        public ?int $riderRating = null,
        public array $riderTags = [],
        public ?string $riderComment = null,
    ) {}

    /**
     * Build from validated input, accepting both shapes of the rider fields.
     *
     * The apps send `rider: {rating, tags, comment}`. A flat `rider_rating` is
     * what the endpoint took before there was anything else to say about a
     * rider, and it is still accepted: a client already shipped with it should
     * not start failing, and the two cannot disagree because the nested form
     * wins when both arrive.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromRequest(array $data): self
    {
        $rider = is_array($data['rider'] ?? null) ? $data['rider'] : [];

        return new self(
            rating: (int) $data['rating'],
            comment: $data['comment'] ?? null,
            dishRatings: $data['dishes'] ?? [],
            tags: array_values(array_unique($data['tags'] ?? [])),
            riderRating: $rider['rating'] ?? $data['rider_rating'] ?? null,
            riderTags: array_values(array_unique($rider['tags'] ?? [])),
            riderComment: $rider['comment'] ?? null,
        );
    }
}
