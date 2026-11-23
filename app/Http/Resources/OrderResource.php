<?php

namespace App\Http\Resources;

use App\Services\Media\ImageService;
use App\Services\Reviews\ReviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'status_label' => __('portal.status.'.$this->status->value),

            /*
             * Where the search for a rider stands, which is a different
             * question from where the food is. An order can be `preparing`
             * and `searching` at the same moment, and null before dispatch
             * has started or when offers are switched off entirely.
             */
            'dispatch_status' => $this->dispatch_status,
            'dispatch_label' => $this->dispatch_status === null
                ? null
                : __('portal.dispatch.'.$this->dispatch_status->value),

            'fulfilment_type' => $this->fulfilment_type,

            'customer_name' => $this->delivery_contact_name ?? $this->whenLoaded('customer', fn () => $this->customer?->name),
            'customer_note' => $this->customer_note,

            'delivery_address' => $this->when($this->isDelivery(), fn () => [
                // array_filter drops the nulls; line2 and landmark are usually
                // both absent and an address full of nulls reads as an error.
                ...array_filter([
                    'line1' => $this->delivery_line1,
                    'line2' => $this->delivery_line2,
                    'landmark' => $this->delivery_landmark,
                    'city' => $this->delivery_city,
                    'pincode' => $this->delivery_pincode,
                ]),

                /*
                 * The home pin. Outside the filter deliberately — a coordinate
                 * is a number and array_filter would eat a legitimate zero,
                 * which is a bug waiting somewhere that is not Madurai.
                 *
                 * Safe here where the rider board is not: this resource is
                 * only ever the customer reading their own order, or the
                 * merchant who was given the address to cook for.
                 */
                'latitude' => $this->delivery_latitude === null ? null : (float) $this->delivery_latitude,
                'longitude' => $this->delivery_longitude === null ? null : (float) $this->delivery_longitude,
            ]),

            /*
             * The shop pin, for the tracking map.
             *
             * whenLoaded, so the merchant's own order list does not fetch the
             * merchant once per row to tell them their own address.
             */
            'restaurant' => $this->whenLoaded('merchant', fn () => [
                'id' => $this->merchant->id,
                'name' => $this->merchant->business_name,

                /*
                 * The area under the name on a history card, and the logo
                 * beside it. Same two keys, same spelling, as the
                 * restaurant card on the home screen — the app should not
                 * have to learn a second shape for the same shop.
                 *
                 * logo_url is a signed URL with a lifetime of
                 * media.url_ttl_minutes, and null for a shop that has not
                 * uploaded one. Worth fetching, not storing.
                 */
                'area' => $this->merchant->city,
                'logo_url' => app(ImageService::class)->url($this->merchant->logo_path),

                'address' => implode(', ', array_filter([
                    $this->merchant->address_line1,
                    $this->merchant->address_line2,
                    $this->merchant->city,
                ])),
                'phone' => $this->merchant->business_phone,
                'latitude' => $this->merchant->latitude === null ? null : (float) $this->merchant->latitude,
                'longitude' => $this->merchant->longitude === null ? null : (float) $this->merchant->longitude,
            ]),

            'distance_metres' => $this->distance_metres,

            'items_total' => (float) $this->items_total,
            'packaging_fee' => (float) $this->packaging_fee,
            'delivery_fee' => (float) $this->delivery_fee,
            'discount_total' => (float) $this->discount_total,
            'tax_total' => (float) $this->tax_total,
            'grand_total' => (float) $this->grand_total,

            /*
             * How the bill was split. `cod_amount` is the figure a rider
             * reads off their screen and asks for at the door, so it is
             * stored rather than worked out from the total — a number
             * derived from a total that a refund has since touched is a
             * rider arguing with a customer on a doorstep.
             */
            'wallet_amount' => (float) $this->wallet_amount,
            'cod_amount' => (float) $this->cod_amount,
            // What the merchant is actually paid, after commission.
            'merchant_payout' => (float) $this->merchant_payout,

            'pickup_code' => $this->pickup_code,
            'estimated_prep_minutes' => $this->estimated_prep_minutes,

            'placed_at' => $this->placed_at,
            'accepted_at' => $this->accepted_at,
            'ready_at' => $this->ready_at,
            'delivered_at' => $this->delivered_at,
            'cancelled_at' => $this->cancelled_at,
            'cancellation_reason' => $this->cancellation_reason,

            /*
             * Who brought it, on a delivered order as well as a live one.
             *
             * A history card naming the rider is how a customer rates the
             * right person a day later. Only a name — the phone number is on
             * the live tracking screen while the delivery is happening and
             * has no business in a list of last month's dinners.
             *
             * photo_url is null because there is no rider photo: riders
             * upload KYC documents, which include a face, and serving one of
             * those as a profile picture would publish an identity document.
             * The key is here so the app can stop drawing a placeholder the
             * day a real photo exists.
             */
            'rider' => $this->whenLoaded('rider', fn () => [
                'name' => $this->rider->full_name,
                'photo_url' => null,
            ]),

            /*
             * The rating this order already has, or null — so the app can tell
             * "not rated" from "rated 0". An unrated order yields null
             * here without the closure running: whenLoaded answers null
             * for a relation that is loaded and empty, which is the
             * behaviour this field wants and the reason can_review below
             * cannot use it.
             *
             * Deliberately only the two numbers. The comment and the tags
             * belong to GET /orders/{id}/review, which is one order rather
             * than twenty, and a history list does not need to carry
             * everybody's sentences.
             */
            'review' => $this->whenLoaded('review', fn () => [
                'rating' => $this->review->rating,
                'rider_rating' => $this->review->rider_rating,
            ]),

            /*
             * Whether to offer the rating button: delivered, inside the
             * window, and not already rated.
             *
             * Answered by the same code that will accept or refuse the review
             * (ReviewService), because the alternative is two definitions of
             * "can" and a button that leads to a 422.
             *
             * Present only where the review relation was loaded. Not a
             * nicety: without that gate the merchant's own order list —
             * which renders this same resource and has no use for the
             * field — would ask the database once per row whether a
             * customer had rated it.
             */
            'can_review' => $this->when(
                $this->resource->relationLoaded('review'),
                fn () => ReviewService::canReview($this->resource),
            ),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'timeline' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($row) => [
                'to_status' => $row->to_status,
                'label' => __('portal.status.'.$row->to_status->value),
                'note' => $row->note,
                'at' => $row->created_at,
            ])),
        ];
    }
}
