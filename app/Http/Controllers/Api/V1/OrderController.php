<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CartResource;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\LiveState\OrderStateService;
use App\Services\LiveState\TrackingService;
use App\Services\Orders\CartService;
use App\Services\Orders\InvoiceService;
use App\Services\Payments\WalletService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The customer's own orders (EP5, EP9).
 *
 * Scoped to the authenticated user throughout: an order id belonging to
 * someone else is a 404, never a window onto what a stranger ate and where
 * they live.
 */
class OrderController extends Controller
{
    public function __construct(
        protected OrderStateService $liveState,
        protected WalletService $wallets,
    ) {}

    /**
     * Order history
     *
     * Newest first. `?active=1` returns only orders still in progress, which
     * is what a home screen banner needs.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        $orders = $request->user()->orders()
            /*
             * `review` and `rider` are for the history card: whether it was
             * rated, and who brought it. Loaded rather than asked per row —
             * can_review is answered from the loaded relation precisely so
             * a list of twenty orders is not twenty extra queries.
             */
            ->with(['items', 'merchant', 'review', 'rider'])
            ->when($filters['active'] ?? false, fn ($q) => $q->active())
            ->latest('created_at')
            ->paginate($filters['per_page'] ?? 20);

        return OrderResource::collection($orders)->response();
    }

    /**
     * One order
     */
    public function show(Request $request, int $order): JsonResponse
    {
        return response()->json([
            'data' => new OrderResource($this->find($request, $order, [
                'items.options', 'merchant', 'statusHistory', 'rider.user', 'review',
            ])),
        ]);
    }

    /**
     * Live tracking
     *
     * Deliberately small and cheap: this is polled every few seconds while an
     * order is in flight, so it reads Redis and falls back to MySQL rather
     * than loading the whole order each time.
     */
    public function track(Request $request, int $order, TrackingService $tracking): JsonResponse
    {
        $model = $this->find($request, $order, ['items', 'merchant', 'rider.user']);

        $cached = rescue(fn () => $this->liveState->get($model->id), [], report: false);

        $status = OrderStatus::tryFrom($cached['status'] ?? '') ?? $model->status;

        $live = $tracking->for($model);

        return response()->json([
            'data' => [
                'order_number' => $model->order_number,
                'status' => $status,
                'status_label' => __('portal.status.'.$status->value),

                /*
                 * Where the search for a rider stands, which is a different
                 * question from where the food is — an order is `preparing`
                 * and `searching` at the same moment. Null before dispatch
                 * starts, or when offers are switched off.
                 *
                 * `no_rider_found` is the one the app must not swallow: the
                 * food is getting cold and nobody is coming for it, and a
                 * customer watching a spinner will not learn that on their own.
                 */
                'dispatch_status' => $model->dispatch_status,
                'dispatch_label' => $model->dispatch_status === null
                    ? null
                    : __('portal.dispatch.'.$model->dispatch_status->value),

                'estimated_prep_minutes' => $model->estimated_prep_minutes,
                'accepted_at' => $model->accepted_at,
                'ready_at' => $model->ready_at,
                'picked_up_at' => $model->picked_up_at,
                'delivered_at' => $model->delivered_at,
                // Shown to the rider at handover; the customer sees it too so
                // they can check the right order arrived.
                'pickup_code' => $model->pickup_code,
                'cancellation_reason' => $model->cancellation_reason,

                /*
                 * Minutes until the food arrives — not until it is cooked.
                 * Before pickup it covers the rider's ride to the kitchen,
                 * the handover and the delivery; after pickup, the ride.
                 */
                'estimated_minutes' => $live['estimated_minutes'],

                /*
                 * Google's encoded overview polyline, ready to draw. Null
                 * whenever there is no route to give, which the app treats
                 * as "show the markers, skip the line" rather than an error.
                 * Never a two-point straight line — if there is no road
                 * route, there is no route.
                 */
                'route' => $live['route'],

                /*
                 * What that line joins: 'restaurant' or 'rider'.
                 *
                 * Sent because an app that has only a polyline has to guess,
                 * and the reasonable guess — that it starts where the rider
                 * is — is wrong for the whole time the food is being cooked.
                 * One customer app measured "how far away is my rider" along
                 * this line, found the rider at its end, and showed 0.0 km
                 * while they were a kilometre and a half out.
                 *
                 * Before pickup the line is the kitchen-to-door leg: the
                 * journey the food takes, which is what somebody watching
                 * wants to see, and which does not move while the rider
                 * fetches it — that is why it caches, and the cache is most
                 * of the maps bill.
                 */
                'route_starts_at' => $live['route_starts_at'],

                /*
                 * Where the rider is heading: 'restaurant' before pickup,
                 * 'customer' after, null once it is over. A different
                 * question from where the line ends, with a different answer
                 * for the whole of the preparing stage. A dotted fallback
                 * line should point here.
                 */
                'next_stop' => $live['next_stop'],

                /*
                 * Published so both apps and the backend share one idea of
                 * stale. Past this many seconds since rider.updated_at, the
                 * map should say the location is updating rather than show a
                 * rider frozen mid-street.
                 */
                'stale_after_seconds' => (int) config('tracking.stale_after_seconds'),

                /*
                 * Position fields are filled only while this order is being
                 * carried. Once it is delivered the rider's movements are
                 * their own business, not the customer's.
                 */
                'rider' => $live['rider'],

                /*
                 * The two ends of the journey, so the map has something to
                 * draw before there is a rider or a route.
                 *
                 * Without these the tracking screen had only a rider
                 * position — filled only while the food is being carried —
                 * and a polyline that needs a maps key. On an order still
                 * being cooked, with no key, that is nothing at all, and the
                 * map showed a placeholder while the order was progressing
                 * perfectly well underneath it.
                 *
                 * Both pins let it always show the real thing: the food is
                 * being cooked here, and it is coming to there.
                 */
                'restaurant' => $model->merchant === null ? null : array_filter([
                    'name' => $model->merchant->business_name,
                    'latitude' => $model->merchant->latitude === null ? null : (float) $model->merchant->latitude,
                    'longitude' => $model->merchant->longitude === null ? null : (float) $model->merchant->longitude,
                ], fn ($value) => $value !== null),

                /*
                 * The customer's own address, sent to the customer. It is
                 * where they asked for their food — the same coordinates
                 * they chose at checkout — and it is the one pin the map
                 * cannot work without.
                 *
                 * Deliberately not the same decision as the service API,
                 * which gets only `delivery_area`: that is a support
                 * platform reading about somebody else.
                 */
                'destination' => $model->delivery_latitude === null ? null : [
                    'latitude' => (float) $model->delivery_latitude,
                    'longitude' => (float) $model->delivery_longitude,
                ],
            ],
        ]);
    }

    /**
     * Tax invoice
     *
     * A printable page rather than JSON: this exists to be saved as a PDF or
     * handed to an accountant, and nobody does either with an API response.
     */
    public function invoice(Request $request, int $order, InvoiceService $invoices): View
    {
        $model = $this->find($request, $order, ['items.options', 'merchant', 'customer', 'payments']);

        return view('invoice', ['invoice' => $invoices->build($model)]);
    }

    /**
     * Cancel an order
     *
     * Only before the restaurant accepts. Once they have, the kitchen may
     * already have started, and cancelling would waste food someone is part
     * way through cooking.
     */
    public function cancel(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $model = $this->find($request, $order);

        if (! in_array($model->status->value, config('checkout.customer_cancellable_statuses'), true)) {
            return response()->json([
                'message' => $model->status === OrderStatus::Cancelled
                    ? 'This order is already cancelled.'
                    : 'The restaurant has already started your order. Call them if something is wrong.',
            ], 422);
        }

        DB::transaction(function () use ($model, $request, $data) {
            $from = $model->status;

            $model->update([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => 'customer',
                'cancellation_reason' => $data['reason'] ?? null,
                // Cancelled before anyone started work, so nothing is charged.
                'cancellation_fee' => 0,
            ]);

            $model->statusHistory()->create([
                'from_status' => $from,
                'to_status' => OrderStatus::Cancelled,
                'changed_by_user_id' => $request->user()->id,
                'note' => $data['reason'] ?? null,
                'created_at' => now(),
            ]);

            /*
             * Wallet money goes back to the wallet, in the same
             * transaction that cancels the order — a cancellation that
             * succeeds without the refund is money quietly kept.
             *
             * Only the wallet part. Anything paid by card goes back to
             * the card: turning somebody's money into store credit
             * without asking is a complaint even when the figure is
             * right.
             */
            $this->wallets->refundOrder($model, 'Refund for cancelled order '.$model->order_number);
        });

        rescue(fn () => $this->liveState->setStatus($model->id, OrderStatus::Cancelled), report: true);

        return response()->json([
            'message' => 'Order cancelled.',
            'data' => new OrderResource($model->fresh('items')),
        ]);
    }

    /**
     * Order it again
     *
     * Puts the same dishes, options and notes back into that restaurant's
     * cart and answers with the cart, in the same shape as
     * `GET /restaurants/{id}/cart`.
     *
     * Anything that cannot be ordered again is **named in `skipped`** rather
     * than dropped quietly: a basket that silently comes back one dish short
     * is a customer paying for an order they did not mean to place. A dish is
     * skipped when it has been taken off the menu, is out of stock, is a
     * rescue deal outside its window, or had a choice attached that no longer
     * exists.
     *
     * The cart is **replaced, not added to**. "Order again" means that order,
     * and merging it into whatever was left in the basket from yesterday
     * produces a third thing the customer never asked for.
     */
    public function reorder(Request $request, int $order, CartService $carts): JsonResponse
    {
        $model = $this->find($request, $order, ['items.options']);

        if ($model->merchant_id === null) {
            return response()->json(['message' => 'That restaurant is no longer on Nexmile.'], 422);
        }

        $cart = $carts->forMerchant($request->user(), $model->merchant_id);

        $skipped = [];

        DB::transaction(function () use ($carts, $cart, $model, &$skipped) {
            $carts->clear($cart);

            foreach ($model->items as $line) {
                /*
                 * A line whose dish row is gone cannot be rebuilt — the name
                 * on the order is a snapshot, and there is nothing to add to
                 * a cart. Named from the snapshot so the customer is told
                 * what is missing rather than how many things are.
                 */
                if ($line->menu_item_id === null) {
                    $skipped[] = ['name' => $line->name];

                    continue;
                }

                $optionIds = $line->options
                    ->pluck('item_option_id')
                    ->filter()
                    ->values()
                    ->all();

                try {
                    $carts->add($cart, $line->menu_item_id, (int) $line->quantity, $optionIds, $line->notes);
                } catch (ValidationException) {
                    /*
                     * Out of stock, off the menu, a deal that has ended, or a
                     * required choice that no longer exists. All of them mean
                     * the same thing to the customer, so they get the same
                     * answer: this dish, not this time.
                     */
                    $skipped[] = ['name' => $line->name];
                }
            }
        });

        return response()->json([
            'message' => $skipped === []
                ? 'Added back to your basket.'
                : 'Added back to your basket, minus what is unavailable.',
            'data' => new CartResource($cart->fresh(['items.menuItem', 'items.options.itemOption', 'merchant'])),
            'skipped' => $skipped,
        ]);
    }

    /**
     * @param  list<string>  $with
     */
    protected function find(Request $request, int $order, array $with = ['items']): Order
    {
        return $request->user()->orders()->with($with)->findOrFail($order);
    }
}
