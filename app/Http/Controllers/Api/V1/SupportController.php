<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Support\BpoClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * In-app support (the bubble in the customer app).
 *
 * This controller holds no conversation. Threads, messages and the AI live on
 * the BPO platform; Nexmile proxies, because the customer already has a
 * session here and because a platform holding every client's transcripts
 * should not be on the public internet with a login of its own.
 *
 * Two things this layer owns and the BPO cannot:
 *
 * **Who the customer is.** The BPO is told a `customer_ref` and trusts it.
 * That id is taken from the authenticated user here, never from the request,
 * which is what stops a conversation id from a stale screen reading somebody
 * else's support history.
 *
 * **Whether an order is theirs.** An `order_id` arriving from a phone is
 * checked against this customer's own orders before it is passed on. Without
 * that, "I need help with order 10432" would hand the AI a stranger's dinner
 * to discuss.
 */
class SupportController extends Controller
{
    public function __construct(private readonly BpoClient $bpo) {}

    /**
     * What the bubble should show before anyone taps it
     *
     * Called when the home screen loads. `label` comes from the server so the
     * wording can change for a festival or a bad day without an app release.
     */
    public function entry(Request $request): JsonResponse
    {
        $thread = $this->bpo->open($request->user(), null, $this->language($request));

        if ($thread === null) {
            // Support is down. The bubble still appears — it is the route to
            // a phone number as well as to a chat.
            return response()->json(['data' => [
                'unread' => 0,
                'label' => __('support.label'),
                'has_open_thread' => false,
                'available' => false,
            ]]);
        }

        return response()->json(['data' => [
            /*
             * Counted by the platform, from when the customer last opened
             * the thread. Not recomputed here: two places deciding what
             * "unread" means is two places to get it wrong.
             */
            'unread' => (int) ($thread['data']['unread'] ?? 0),
            'label' => __('support.label'),
            'has_open_thread' => ($thread['data']['status'] ?? null) !== 'closed',
            'available' => true,
        ]]);
    }

    /**
     * Open the support thread
     *
     * Idempotent: one open thread per customer, so the app may call this from
     * the bubble, from an order screen, and from the help menu without
     * checking whether one exists.
     *
     * Pass `order_id` wherever an order is on screen. It is the difference
     * between "how can I help?" and an answer about the order they are
     * actually looking at.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $orderId = $data['order_id'] ?? null;
        $order = $orderId === null ? null : $this->ownOrder($request, $orderId);

        if ($orderId !== null && $order === null) {
            /*
             * Silently dropped rather than refused. The customer gets their
             * thread; it simply has no order attached. Refusing would turn a
             * stale id on a back-stacked screen into a customer who cannot
             * reach support at all, and support is the one thing that must
             * never be the broken part.
             */
            $orderId = null;
        }

        $thread = $this->bpo->open($request->user(), $orderId, $this->language($request), $order?->order_number);

        return $thread === null
            ? $this->unavailable()
            : response()->json(['data' => $thread['data']]);
    }

    public function show(Request $request, string $conversation): JsonResponse
    {
        $thread = $this->bpo->conversation($request->user(), $conversation);

        return $thread === null
            ? $this->unavailable()
            : response()->json(['data' => $thread['data']]);
    }

    /**
     * New messages only
     *
     * The polling endpoint. Call it every 2 seconds while a reply is
     * outstanding and **not at all** when none is — an idle thread that polls
     * is a battery complaint and a bill.
     */
    public function messages(Request $request, string $conversation): JsonResponse
    {
        $request->validate(['after_id' => ['sometimes', 'nullable', 'string', 'max:64']]);

        $result = $this->bpo->messages(
            $request->user(),
            $conversation,
            $request->string('after_id')->toString() ?: null,
        );

        return $result === null
            ? $this->unavailable()
            : response()->json(['data' => $result['data'], 'meta' => $result['meta']]);
    }

    /**
     * Send a message
     *
     * Answers **202**, and the body is the customer's own message as stored —
     * not the reply. A model takes seconds to answer and holding the request
     * open for it fails on the networks this product serves, so the answer
     * arrives through polling or a push.
     *
     * Send `client_id` — a uuid per message, the same one on a retry. It is
     * what lets a timeout that already reached the server be told apart from
     * a customer tapping send twice.
     */
    public function send(Request $request, string $conversation): JsonResponse
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
            'client_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $result = $this->bpo->send(
            $request->user(),
            $conversation,
            $data['content'],
            $data['client_id'] ?? null,
        );

        return $result === null
            ? $this->unavailable()
            : response()->json(['data' => $result['data'], 'meta' => $result['meta']], 202);
    }

    /**
     * Ask for a person
     *
     * The response carries `system_message`: the words to show, already
     * translated. **Render that string rather than writing your own** — there
     * are no agents on shift today, so the reply window has to be honest and
     * changeable from the server.
     */
    public function handover(Request $request, string $conversation): JsonResponse
    {
        $thread = $this->bpo->handover($request->user(), $conversation);

        if ($thread === null) {
            return $this->unavailable();
        }

        $messages = $thread['data']['messages'] ?? [];
        $system = collect($messages)->where('sender', 'system')->last();

        return response()->json(['data' => [
            ...$thread['data'],
            'system_message' => $system['content'] ?? null,
        ]]);
    }

    public function close(Request $request, string $conversation): JsonResponse
    {
        $thread = $this->bpo->close($request->user(), $conversation);

        return $thread === null
            ? $this->unavailable()
            : response()->json(['data' => $thread['data']]);
    }

    /** Was that answer any good? */
    public function feedback(Request $request, string $message): JsonResponse
    {
        // `value`, not `feedback`: the endpoint is already called
        // feedback, and repeating the word in the body reads like a stutter.
        $data = $request->validate(['value' => ['required', 'in:up,down']]);

        $result = $this->bpo->feedback($request->user(), $message, $data['value']);

        return $result === null
            ? $this->unavailable()
            : response()->json(['data' => $result['data']]);
    }

    /**
     * Is this order actually theirs?
     *
     * Scoped through the user, like every other order lookup in this API. An
     * order id is not a licence to discuss somebody else's dinner with an AI.
     */
    private function ownOrder(Request $request, int $orderId): ?Order
    {
        return Order::query()
            ->where('id', $orderId)
            ->where('user_id', $request->user()->id)
            ->first(['id', 'order_number']);
    }

    private function language(Request $request): string
    {
        return in_array(app()->getLocale(), ['en', 'ta', 'hi'], true) ? app()->getLocale() : 'en';
    }

    /**
     * Support itself is down.
     *
     * 503 rather than 500, and a message written for the customer, because
     * this is the one part of the app that exists for when something has
     * already gone wrong. The app shows the phone number here.
     */
    private function unavailable(): JsonResponse
    {
        return response()->json([
            'message' => __('support.unavailable'),
            'errors' => ['support' => [__('support.unavailable')]],
        ], 503);
    }
}
