<?php

namespace App\Http\Controllers\Api\Internal;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\RiderReport;
use App\Models\User;
use App\Services\Push\OrderNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The two things an outside support system may ask Nexmile to *do*.
 *
 * Both are deliberately small, and both stop short of the thing the caller
 * probably wants. Raising a case is allowed; deciding its outcome is not.
 * Asking whether a refund is possible is allowed; issuing one is not.
 *
 * Section 8.3 of the specification puts it well: consequential actions
 * require policy checks and human approval. This file is where that line is
 * drawn on the Nexmile side, and it is drawn in code rather than in a prompt,
 * because a prompt is a request and a controller is a rule.
 */
class SupportActionController extends Controller
{
    /**
     * Tell a customer their support thread has a reply
     *
     * The one thing the BPO platform may ask Nexmile to send to a phone, and
     * it is deliberately thin: a customer id and a conversation id. No
     * message text crosses this door.
     *
     * The reason is the lock screen. A support conversation can be about a
     * missing delivery, a complaint about a worker, or money — and a
     * notification is read by whoever is holding the phone, which is not
     * always the person who typed the question. The push says a reply is
     * waiting; reading it takes opening the app.
     *
     * Nexmile decides whether to send at all. Device tokens, notification
     * preferences and languages live here and are not the BPO's business.
     */
    public function notifyReply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_ref' => ['required', 'string', 'max:64'],
            'conversation_id' => ['required', 'string', 'max:64'],
        ]);

        $customer = User::query()
            ->where('id', $data['customer_ref'])
            ->where('role', UserRole::Customer)
            ->first();

        /*
         * A deleted or unknown customer is a 200, not a 404.
         *
         * There is nothing for the caller to do about it and nothing to
         * retry, and answering "no such customer" to a machine holding a
         * customer id turns this endpoint into a way to check which ids
         * exist.
         */
        if ($customer !== null) {
            app(OrderNotifier::class)->supportReplied($customer, $data['conversation_id']);
        }

        return response()->json(['data' => ['sent' => $customer !== null]]);
    }

    /**
     * Raise a support case against an order     *
     * Creates the same record the customer app creates when a customer
     * reports a problem with their delivery, so one queue holds both and the
     * rider-conduct workflow already built around it keeps working.
     *
     * Idempotent on `reference`: the specification requires retry-safe
     * creates, and a support system that retries a timeout must not open two
     * cases about one complaint.
     */
    public function ticket(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'category' => ['required', 'string', Rule::in(array_keys(config('conduct.report_categories')))],
            'description' => ['required', 'string', 'max:2000'],
            // The caller's own id for this case. Replayed safely.
            'reference' => ['required', 'string', 'max:64'],
        ]);

        $order = Order::findOrFail($data['order_id']);

        $request->attributes->set('audit_subject_type', 'order');
        $request->attributes->set('audit_subject_id', $order->id);

        /*
         * A case about a delivery needs a rider to be about. Before one is
         * assigned there is nobody to report, and the honest answer is that
         * this is an order problem rather than a conduct one.
         */
        if ($order->rider_id === null) {
            return response()->json([
                'error' => [
                    'code' => 'NO_RIDER_ASSIGNED',
                    'message' => 'No rider has been assigned to this order yet, so there is nothing to report against.',
                ],
            ], 422);
        }

        $existing = RiderReport::where('order_id', $order->id)
            ->where('description', 'like', '%['.$data['reference'].']%')
            ->first();

        if ($existing !== null) {
            // The same case, not a second one. 200 rather than 201, so the
            // caller can tell a replay from a new create.
            return response()->json(['data' => $this->present($existing)]);
        }

        $report = RiderReport::create([
            'order_id' => $order->id,
            'rider_id' => $order->rider_id,
            'reported_by_user_id' => $order->user_id,
            'category' => $data['category'],
            // The reference is kept in the text because RiderReport has no
            // column for a foreign id and inventing one would change a table
            // the conduct workflow depends on. Honest, if inelegant.
            'description' => $data['description']."\n\n[".$data['reference'].']',
        ]);

        return response()->json(['data' => $this->present($report)], 201);
    }

    /**
     * Ask whether an order can be refunded
     *
     * **Read-only. This issues nothing.** It answers what the policy says, so
     * an agent can tell a customer where they stand without promising an
     * outcome Nexmile has not agreed to.
     *
     * The specification calls this read-only in MVP, and it should stay that
     * way longer than that: the moment an outside system can cause money to
     * move, a compromise of that system is a compromise of the bank account.
     */
    public function refundEligibility(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
        ]);

        $order = Order::with('payments')->findOrFail($data['order_id']);

        $request->attributes->set('audit_subject_type', 'order');
        $request->attributes->set('audit_subject_id', $order->id);

        $reasons = [];

        /*
         * What was actually taken, not what was billed. The two differ on
         * every part-wallet order, and quoting the order total to a customer
         * when half of it was never charged is a promise nobody can keep.
         */
        $paid = $order->payments
            ->where('status', PaymentStatus::Paid)
            ->sum('amount');

        $gatewayPaid = $order->payments
            ->where('status', PaymentStatus::Paid)
            ->whereNotNull('gateway')
            ->sum('amount');

        if ($order->status === OrderStatus::Cancelled) {
            $reasons[] = 'The order is already cancelled; any refund due has been started.';
        }

        if ($order->status === OrderStatus::PendingPayment) {
            $reasons[] = 'The order was never paid for.';
        }

        if ((float) $paid <= 0.0) {
            $reasons[] = (float) $order->cod_amount > 0
                ? 'Cash on delivery, and nothing has been collected yet.'
                : 'Nothing has been paid against this order.';
        } elseif ((float) $gatewayPaid <= 0.0) {
            /*
             * Wallet-only. Refundable, but through the wallet rather than a
             * card, which is a different conversation and a different
             * timeline for the customer.
             */
            $reasons[] = 'Paid from the Nexmile wallet, so any refund returns to the wallet.';
        }

        if ($order->status === OrderStatus::Delivered) {
            $reasons[] = 'The order was delivered. A refund after delivery is a decision for the operations team.';
        }

        $eligible = $reasons === [];

        return response()->json(['data' => [
            'order_id' => $order->id,
            'status' => $order->status->value,
            'amount_paid' => (float) $paid,
            'amount_refundable_to_source' => (float) $gatewayPaid,
            'cash_on_delivery' => (float) $order->cod_amount,

            /*
             * Three states, not two. "No" and "not automatically" are
             * different answers to a customer, and flattening them into a
             * boolean is how an agent ends up saying no to somebody who
             * should have been escalated.
             */
            'eligible_automatically' => $eligible,
            'requires_human_decision' => ! $eligible,
            'reasons' => $reasons,

            /*
             * Said plainly, because the caller is a machine that will be
             * asked to summarise this to a person. Nothing in this response
             * authorises anything.
             */
            'note' => 'This is an eligibility opinion only. Nexmile issues refunds; no external system may.',
        ]]);
    }

    /** @return array<string, mixed> */
    private function present(RiderReport $report): array
    {
        return [
            'id' => $report->id,
            'order_id' => $report->order_id,
            'category' => $report->category,
            'created_at' => $report->created_at,
            'status' => $report->reviewed_at === null ? 'open' : 'reviewed',
        ];
    }
}
