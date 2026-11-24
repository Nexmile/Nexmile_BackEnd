<?php

namespace App\Services\Support;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only way Nexmile talks to the BPO platform.
 *
 * One class, so the rules about that boundary live in one place — mirroring
 * `NexmileClient` on the other side, which is the only way the BPO talks back
 * to us. Two doors, each opening one way, each with its own credential.
 *
 * **Every call is scoped to a customer here, not at the far end only.** The
 * `customer_ref` is taken from the authenticated user and never from the
 * request body, so a conversation id guessed or replayed by a phone cannot
 * read somebody else's support history. The BPO checks it too. Both ends
 * check, because the failure is invisible until it is a disclosure.
 */
class BpoClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout,
    ) {}

    /** @return array<string, mixed>|null */
    /**
     * `$orderLabel` is the human order number, used only to word the system
     * line when a thread changes subject. The BPO holds an id and would
     * otherwise have to call back here for a label, inside a request a
     * customer is waiting on.
     */
    public function open(User $customer, ?int $orderId, string $language, ?string $orderLabel = null): ?array
    {
        return $this->post('conversations', array_filter([
            'customer_ref' => $this->ref($customer),
            'order_ref' => $orderId === null ? null : (string) $orderId,
            'order_label' => $orderLabel,
            'language' => $language,
        ]));
    }

    /** @return array<string, mixed>|null */
    public function conversation(User $customer, string $id): ?array
    {
        return $this->get("conversations/{$id}", ['customer_ref' => $this->ref($customer)]);
    }

    /** @return array<string, mixed>|null */
    public function messages(User $customer, string $id, ?string $afterId = null): ?array
    {
        return $this->get("conversations/{$id}/messages", array_filter([
            'customer_ref' => $this->ref($customer),
            'after_id' => $afterId,
        ]));
    }

    /** @return array<string, mixed>|null */
    public function send(User $customer, string $id, string $content, ?string $clientId): ?array
    {
        return $this->post("conversations/{$id}/messages", array_filter([
            'customer_ref' => $this->ref($customer),
            'content' => $content,
            'client_ref' => $clientId,
        ]));
    }

    /** @return array<string, mixed>|null */
    public function handover(User $customer, string $id): ?array
    {
        return $this->post("conversations/{$id}/handover", ['customer_ref' => $this->ref($customer)]);
    }

    /** @return array<string, mixed>|null */
    public function close(User $customer, string $id): ?array
    {
        return $this->post("conversations/{$id}/close", ['customer_ref' => $this->ref($customer)]);
    }

    /** @return array<string, mixed>|null */
    public function feedback(User $customer, string $messageId, string $feedback): ?array
    {
        return $this->post("messages/{$messageId}/feedback", [
            'customer_ref' => $this->ref($customer),
            'feedback' => $feedback,
        ]);
    }

    /**
     * Who this is, as far as the BPO is concerned.
     *
     * The Nexmile user id and nothing else — no name, no phone number, no
     * email. The BPO holds references, not copies: it can ask us who this is
     * if a human ever needs to know, and until then a transcript on a second
     * system is not also a copy of somebody's contact details.
     */
    private function ref(User $customer): string
    {
        return (string) $customer->id;
    }

    /** @param array<string, mixed> $query */
    private function get(string $path, array $query): ?array
    {
        return $this->unwrap(fn (PendingRequest $r) => $r->get($path, $query), $path);
    }

    /** @param array<string, mixed> $payload */
    private function post(string $path, array $payload): ?array
    {
        return $this->unwrap(fn (PendingRequest $r) => $r->post($path, $payload), $path);
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>|null
     */
    private function unwrap(callable $call, string $path): ?array
    {
        /*
         * No credential configured. Answered as "support is unavailable"
         * rather than thrown.
         *
         * This is the opposite of how the BPO treats a missing Nexmile token,
         * and the difference is what the credential is for. Without it the
         * BPO cannot do its job at all, so failing to boot is right there.
         * Here it disables one optional feature, and a 500 on the support
         * endpoint would take out the screen a customer reaches for when
         * something has already gone wrong — to protect a feature that is
         * not switched on.
         *
         * Logged as a warning so this is not mistaken for an outage, which
         * is the real cost of failing quietly.
         */
        if ($this->token === '') {
            Log::warning('BPO_SERVICE_TOKEN is not set; in-app support is disabled.', ['path' => $path]);

            return null;
        }

        try {
            $response = $call($this->request());
        } catch (ConnectionException $e) {
            /*
             * The BPO is unreachable. Null, not an exception: the controller
             * turns this into "support is unavailable, here is the phone
             * number", which is a worse outcome than a working chat and a
             * much better one than a customer's complaint ending in a 500.
             */
            Log::warning('BPO unreachable', ['path' => $path, 'message' => $e->getMessage()]);

            return null;
        }

        if ($response->successful()) {
            // Both halves: `data` is the payload, `meta` carries
            // pending_reply, and the controller needs each of them.
            return [
                'data' => $response->json('data'),
                'meta' => $response->json('meta', []),
                'status' => $response->status(),
            ];
        }

        /*
         * A 404 is ordinary: a thread that was closed and swept, or an id
         * from a stale screen. A 401 is not — it means our credential is
         * wrong or has been rotated out from under us, and nobody would
         * otherwise notice until the complaints arrived.
         */
        if ($response->status() !== 404) {
            Log::error('BPO refused a call', [
                'path' => $path,
                'status' => $response->status(),
                'code' => $response->json('error.code'),
            ]);
        }

        return null;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/').'/')
            ->withToken($this->token)
            ->acceptJson()
            ->timeout($this->timeout)
            /*
             * Two attempts, briefly spaced — the same shape as NexmileClient
             * for the same reason: a customer watching a thread will wait a
             * second for a retry and will not wait fifteen for three.
             */
            ->retry(2, 200, throw: false);
    }
}
