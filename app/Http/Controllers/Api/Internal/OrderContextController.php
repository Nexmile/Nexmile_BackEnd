<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Services\LiveState\TrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The order and customer context an outside support system may read.
 *
 * Section 9 of the AI BPO specification, implemented on the Nexmile side.
 * Every method here answers a question a support agent actually asks, and
 * nothing here answers one they do not need to.
 *
 * Three rules run through all of it:
 *
 * **It returns facts, never predictions.** An agent, or a model behind one,
 * must be able to repeat what this says to a customer without adding to it.
 * Where Nexmile does not know something, the field is null and the caller is
 * expected to say so rather than guess.
 *
 * **It is narrower than the customer's own API.** A customer may see their
 * delivery address; a support agent needs to know the order exists, where it
 * is and whether it is late. Each field here was included because a workflow
 * in section 9.1 needs it.
 *
 * **Every call is recorded.** `EnsureServiceScope` writes the audit row; the
 * methods here name the subject so the log can answer "who read about order
 * 4821", not merely "who called this URL".
 */
class OrderContextController extends Controller
{
    /**
     * Read one order
     *
     * Status, timing, the restaurant and whether a rider is assigned. This is
     * the call behind "where is my order", and it is the reason the whole
     * integration exists.
     */
    public function order(Request $request, int $order): JsonResponse
    {
        $model = Order::with([
            'merchant:id,business_name,city,business_phone',
            'rider:id,full_name,vehicle_type',
            // amountPaid() sums the loaded collection; loading it here keeps
            // this one query rather than one per call site that forgot.
            'payments:id,order_id,status,amount,method',
            'items:id,order_id,name,quantity,line_total',
        ])->findOrFail($order);

        $this->subject($request, 'order', $model->id);

        return response()->json(['data' => [
            'id' => $model->id,
            'order_number' => $model->order_number,
            'customer_id' => $model->user_id,

            'status' => $model->status->value,
            'status_label' => __('portal.status.'.$model->status->value),
            'is_terminal' => $model->status->isTerminal(),

            'fulfilment_type' => $model->fulfilment_type,

            /*
             * From the payment row, not from the order.
             *
             * `orders` has no `payment_method` column and never has — this
             * read `$model->payment_method` and so has been sending null
             * since the endpoint was written. Nobody noticed because nothing
             * consumed it until an assistant started reading these facts out
             * to customers, which is a good argument for not shipping fields
             * ahead of a reader.
             */
            'payment_method' => $model->payments->sortByDesc('id')->first()?->method,

            'grand_total' => (float) $model->grand_total,

            /*
             * Whether the money actually arrived, which `payment_method` does
             * not answer — "upi" is an intention, not a receipt.
             *
             * Derived rather than stored: payment status lives on the payment
             * rows, an order can have several attempts, and the only question
             * support ever asks is "has this been paid for". Three answers,
             * because "partly" is real: a part-wallet order captures the rest
             * online and a failed attempt leaves the balance spent.
             *
             * It matters most on a cash order, where the honest answer is
             * "not yet, they pay at the door" — and a support system that
             * reported that as unpaid would have somebody chasing a payment
             * that was never due.
             */
            'amount_paid' => round($model->amountPaid(), 2),
            'payment_state' => $this->paymentState($model),

            'restaurant' => $model->merchant === null ? null : [
                'id' => $model->merchant->id,
                'name' => $model->merchant->business_name,
                'city' => $model->merchant->city,
                'phone' => $model->merchant->business_phone,
            ],

            /*
             * Named, with no contact details. An agent needs to know a rider
             * is on the way and who they are; the rider's own phone number is
             * not support's to hand out, and section 9 says the same.
             */
            'rider' => $model->rider === null ? null : [
                'id' => $model->rider->id,
                'name' => $model->rider->full_name,
                'vehicle_type' => $model->rider->vehicle_type,
            ],

            /*
             * The whole lifecycle, as timestamps. "Accepted 40 minutes ago and
             * not yet ready" is a complete answer to a late-delivery question
             * and needs no interpretation from us.
             */
            'timestamps' => [
                'placed_at' => $model->placed_at,
                'accepted_at' => $model->accepted_at,
                'ready_at' => $model->ready_at,
                'assigned_at' => $model->assigned_at,
                'picked_up_at' => $model->picked_up_at,
                'delivered_at' => $model->delivered_at,
                'cancelled_at' => $model->cancelled_at,
            ],

            /*
             * What they ordered.
             *
             * Half of what support is ever asked is about the food rather
             * than the delivery — one missing, the wrong dish, a second
             * biryani nobody ordered — and without the lines the answer to
             * all of it was to hand over and ask somebody to look it up.
             *
             * Name, count and line total only. No options, no notes, no
             * item ids: enough to say "two Mutton Biryani" back to a
             * customer and recognise what they are describing, and not a
             * copy of the basket.
             */
            'items' => $model->items->map(fn ($item) => [
                'name' => $item->name,
                'quantity' => (int) $item->quantity,
                'line_total' => (float) $item->line_total,
            ])->all(),

            // The drop area, never the address. Support resolves a problem;
            // it does not need to know which door.
            'delivery_area' => $model->delivery_city,
        ]]);
    }

    /**
     * A customer's recent orders
     *
     * For after identity has been verified. Newest first, capped, and
     * deliberately thin: enough to say "is it this one?" and nothing more.
     *
     * `is_terminal` and `customer_id` are here for one reason each, and both
     * are about the support system not having to guess.
     *
     * **`is_terminal`** so the caller can tell a live order from a finished
     * one without knowing Nexmile's status vocabulary. It could infer it from
     * a list of status strings held on its side, and that list would be
     * correct until the day somebody here adds a status — at which point a
     * brand-new order reads as finished in a different codebase and nothing
     * fails loudly. The enum already knows; it should say.
     *
     * **`customer_id`** so the caller can check what it was given. It is
     * redundant with the path on this endpoint and deliberately so: a support
     * system holding an order id from anywhere else can compare this against
     * the customer it believes it is serving, rather than trusting whatever
     * handed it the id.
     */
    public function customerOrders(Request $request, int $customer): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,25'],
        ]);

        $user = User::findOrFail($customer);

        $this->subject($request, 'customer', $user->id);

        $orders = $user->orders()
            ->with('merchant:id,business_name')
            ->latest('id')
            ->limit($data['limit'] ?? 10)
            ->get();

        return response()->json(['data' => $orders->map(fn (Order $order) => [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'customer_id' => $order->user_id,
            'status' => $order->status->value,
            'status_label' => __('portal.status.'.$order->status->value),
            'is_terminal' => $order->status->isTerminal(),
            'restaurant' => $order->merchant?->business_name,
            'grand_total' => (float) $order->grand_total,
            'placed_at' => $order->placed_at,
        ])->all()]);
    }

    /**
     * Read one customer
     *
     * Identity and contact, so an agent knows who they are speaking to. No
     * addresses, no payment details, no order history — those have their own
     * scopes and their own audit entries.
     */
    public function customer(Request $request, int $customer): JsonResponse
    {
        $user = User::findOrFail($customer);

        $this->subject($request, 'customer', $user->id);

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'status' => $user->status->value,
            'preferred_locale' => $user->preferred_locale,
            'joined_at' => $user->created_at,
        ]]);
    }

    /**
     * Read one restaurant
     *
     * Whether it is open, taking orders and reachable — the three things
     * behind "the restaurant hasn't accepted my order".
     */
    public function merchant(Request $request, int $merchant): JsonResponse
    {
        $model = Merchant::findOrFail($merchant);

        $this->subject($request, 'merchant', $model->id);

        return response()->json(['data' => [
            'id' => $model->id,
            'name' => $model->business_name,
            'city' => $model->city,
            'phone' => $model->business_phone,
            'is_accepting_orders' => (bool) $model->is_accepting_orders,
            'is_open_now' => $model->isOpenNow(),
            'avg_prep_time_minutes' => (int) $model->avg_prep_time_minutes,
            // Named rather than inferred, so an agent is told "closed for the
            // night" and "paused by the owner" apart.
            'licence_problem' => $model->fssaiBlocker(),
        ]]);
    }

    /**
     * Delivery state for an order's rider
     *
     * Deliberately keyed on the **order**, not the rider. A support system
     * has no business asking where a named rider is; it has every business
     * asking where the food on order 4821 has got to, and those are different
     * questions even when the answer is the same coordinates.
     *
     * Position is released only while the order is actually being carried,
     * which is `TrackingService`'s existing rule, reused rather than
     * reimplemented.
     */
    public function deliveryStatus(Request $request, int $order, TrackingService $tracking): JsonResponse
    {
        $model = Order::with('rider')->findOrFail($order);

        $this->subject($request, 'order', $model->id);

        $live = $tracking->for($model);

        return response()->json(['data' => [
            'order_id' => $model->id,
            'status' => $model->status->value,
            'rider_assigned' => $model->rider_id !== null,
            'rider_name' => $model->rider?->full_name,
            'estimated_minutes' => $live['estimated_minutes'] ?? null,

            /*
             * How far the rider still has to carry it, straight line.
             *
             * A distance is not a location. The position below is released
             * only while the order is being carried and is deliberately not
             * passed on to anything that talks to a customer; this number is
             * safe to read out — "about two kilometres away" is what the
             * customer's own map already shows them — and it is the one
             * figure a support conversation actually needs from it.
             *
             * Null before pickup, because the rider is riding the other way.
             */
            'distance_metres' => $tracking->remainingMetres($model),
            'position' => isset($live['rider']['latitude']) ? [
                'latitude' => $live['rider']['latitude'],
                'longitude' => $live['rider']['longitude'],
                // The fix time, not now. A position from eleven minutes ago is
                // not a live position, and an agent reading it out as one is
                // how a customer is told the rider is round the corner when
                // the phone died in the next street.
                'updated_at' => $live['rider']['updated_at'] ?? null,
            ] : null,
        ]]);
    }

    /**
     * Has this order been paid for?
     *
     * Three words rather than the payment table, because that is the whole
     * of what a support conversation needs and because the alternative — a
     * list of attempts with statuses — is something a caller would have to
     * interpret, and interpreting it differently from us is how a customer
     * gets told they owe money they have already paid.
     *
     * `due_on_delivery` is deliberately its own answer rather than "unpaid".
     * A cash order that has not been paid for is not a problem; it is a cash
     * order.
     */
    private function paymentState(Order $order): string
    {
        $paid = round($order->amountPaid(), 2);
        $total = round((float) $order->grand_total, 2);
        $wallet = round((float) $order->wallet_amount, 2);

        if ($paid + $wallet >= $total) {
            return 'paid';
        }

        if ($paid + $wallet > 0) {
            return 'part_paid';
        }

        /*
         * `cod_amount`, not the payment method.
         *
         * It is what checkout actually writes — the amount the rider is to
         * collect — so it is true for a part-wallet cash order too, where
         * the method alone would not tell you anything was still owed.
         */
        return (float) $order->cod_amount > 0
            ? 'due_on_delivery'
            : 'unpaid';
    }

    /**
     * Name what this call was about, for the audit row.
     *
     * Written to the request rather than returned, because the middleware
     * writes the log after the response and needs to find it there.
     */
    private function subject(Request $request, string $type, int $id): void
    {
        $request->attributes->set('audit_subject_type', $type);
        $request->attributes->set('audit_subject_id', $id);
    }
}
