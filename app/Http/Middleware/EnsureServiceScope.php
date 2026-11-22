<?php

namespace App\Http\Middleware;

use App\Models\ServiceApiLog;
use App\Models\ServiceClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The door to `/internal/v1`.
 *
 * Four things, in this order, because the order is the security property:
 * the caller must be a service rather than a person, that service must be
 * switched on, it must hold the scope this route needs, and it must not be
 * hammering us. Then, whatever happened, the call is written down.
 *
 * A customer's own token must never open this door. Sanctum will happily
 * authenticate one — it is a valid token — and every endpoint behind here
 * returns other people's data, so "is the tokenable a ServiceClient" is the
 * check that matters most and it is the first one made.
 */
class EnsureServiceScope
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $started = microtime(true);
        $client = $request->user();

        /*
         * Not a service account. This is the case worth being loudest about:
         * a customer token reaching an endpoint that reads any customer's
         * orders would be the whole platform's privacy guarantee gone, and it
         * would look like an ordinary authenticated request in every log.
         */
        if (! $client instanceof ServiceClient) {
            return $this->deny($request, $scope, null, 403,
                'This endpoint is for registered service integrations.', $started);
        }

        if (! $client->is_active) {
            return $this->deny($request, $scope, $client, 403,
                'This integration has been suspended.', $started);
        }

        /*
         * Abilities are Sanctum's, so a client may hold several tokens with
         * different reach — a read-only credential for the chat agent and a
         * narrower one for a batch job.
         */
        if (! $request->user()->tokenCan($scope)) {
            return $this->deny($request, $scope, $client, 403,
                "This credential does not carry the {$scope} scope.", $started);
        }

        $key = 'service:'.$client->id;

        if (RateLimiter::tooManyAttempts($key, $client->rate_limit_per_minute)) {
            $response = $this->deny($request, $scope, $client, 429,
                'Too many requests. Slow down and retry.', $started);

            return $response->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        $response = $next($request);

        /*
         * Touched here rather than on every query, so "when did this
         * integration last call us" is answerable without reading the log
         * table. Saved quietly: a clock update must never fail a request that
         * otherwise worked.
         */
        rescue(fn () => $client->forceFill(['last_used_at' => now()])->saveQuietly(), report: false);

        $this->record($request, $scope, $client, $response->getStatusCode(), $started);

        return $response;
    }

    /**
     * Refuse, and write the refusal down.
     *
     * A rejected call is more interesting than an accepted one. A run of 403s
     * on a scope a credential does not hold is either a misconfigured client
     * or somebody trying doors, and both are worth being able to see.
     */
    private function deny(Request $request, string $scope, ?ServiceClient $client, int $status, string $message, float $started): Response
    {
        $this->record($request, $scope, $client, $status, $started);

        return response()->json([
            'error' => [
                'code' => $status === 429 ? 'RATE_LIMITED' : 'SERVICE_ACCESS_DENIED',
                'message' => $message,
                'request_id' => $request->headers->get('X-Request-Id') ?? (string) str()->uuid(),
            ],
        ], $status);
    }

    /**
     * Never let auditing break the request it is auditing.
     *
     * A log write that throws would turn a working endpoint into a 500 and
     * take the evidence with it. Wrapped and reported instead, so a broken
     * log is a bug somebody is told about rather than an outage.
     */
    private function record(Request $request, string $scope, ?ServiceClient $client, int $status, float $started): void
    {
        rescue(fn () => ServiceApiLog::create([
            'service_client_id' => $client?->id,
            'method' => $request->method(),
            // The pattern, not the filled-in URL: /internal/v1/orders/{order}
            // groups a thousand calls into one readable line, and the id is
            // recorded separately as the subject.
            'path' => $request->route()?->uri() ?? $request->path(),
            'scope' => $scope,
            'subject_type' => $request->attributes->get('audit_subject_type'),
            'subject_id' => $request->attributes->get('audit_subject_id'),
            'status_code' => $status,
            'ip_hash' => $request->ip() === null ? null : hash('sha256', $request->ip()),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]), report: true);
    }
}
