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
        $model = Order::with(['merchant:id,business_name,city,business_phone', 'rider:id,full_name,vehicle_type'])
            ->findOrFail($order);

        $this->subject($request, 'order', $model->id);

        return response()->json(['data' => [
            'id' => $model->id,
            'order_number' => $model->order_number,
            'customer_id' => $model->user_id,

            'status' => $model->status->value,
            'status_label' => __('portal.status.'.$model->status->value),
            'is_terminal' => $model->status->isTerminal(),

            'fulfilment_type' => $model->fulfilment_type,
            'payment_method' => $model->payment_method,
            'grand_total' => (float) $model->grand_total,

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
            'status' => $order->status->value,
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
