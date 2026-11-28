<?php

namespace App\Services\LiveState\Routing;

use App\Contracts\RouteProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Google's Routes API, for the line on the customer's map and the turns in
 * the rider's navigation.
 *
 * The replacement for GoogleDirections, and not only because it is newer.
 * Google lists the Directions API as legacy and a Google Cloud project
 * created today generally cannot enable it at all — so on a new project the
 * older provider is not a slower option, it is no option.
 *
 * **It asks for TWO_WHEELER.** Directions had no such mode, so the old
 * provider asked for driving and overstated every journey: a scooter in
 * Madurai takes lanes and turns a car cannot, and at one kilometre that
 * difference is most of the trip. Overstating an arrival time is the safe
 * direction to be wrong in, which is why the old behaviour was acceptable,
 * but being right is better.
 *
 * Billed per request, and a customer watching a delivery polls every few
 * seconds — so the caching in TrackingService is not an optimisation, it is
 * the difference between a few paise and a few hundred rupees per order.
 * NavigationService holds the rider side down for the same reason. Nothing
 * should call this directly.
 */
class GoogleRoutes implements RouteProvider
{
    private const ENDPOINT = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    /**
     * Exactly the fields we read, and no more.
     *
     * The field mask is not optional on this API — a request without one is
     * refused — and it is also the bill: asking for the whole route object
     * would include per-step polylines and a viewport nothing here draws.
     */
    private const FIELD_MASK = 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline,'
        .'routes.legs.steps.distanceMeters,routes.legs.steps.staticDuration,'
        .'routes.legs.steps.endLocation,routes.legs.steps.navigationInstruction';

    public function configured(): bool
    {
        return ! empty(config('services.google_maps.key'));
    }

    public function between(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        $route = $this->compute($fromLat, $fromLng, $toLat, $toLng);

        if ($route === null) {
            return null;
        }

        return [
            'polyline' => $route['polyline'],
            'duration_seconds' => $route['duration_seconds'],
            'distance_metres' => $route['distance_metres'],
        ];
    }

    public function navigation(
        float $fromLat,
        float $fromLng,
        float $toLat,
        float $toLng,
        ?string $language = null,
    ): ?array {
        $route = $this->compute($fromLat, $fromLng, $toLat, $toLng, $language);

        if ($route === null) {
            return null;
        }

        return [
            'polyline' => $route['polyline'],
            'duration_seconds' => $route['duration_seconds'],
            'distance_metres' => $route['distance_metres'],
            'steps' => $this->steps($route['raw']),
        ];
    }

    /**
     * One route, parsed, or null when there is nothing usable in the answer.
     *
     * @return array{polyline: string, duration_seconds: int, distance_metres: int, raw: array<string, mixed>}|null
     */
    protected function compute(
        float $fromLat,
        float $fromLng,
        float $toLat,
        float $toLng,
        ?string $language = null,
    ): ?array {
        $key = config('services.google_maps.key');

        if (empty($key)) {
            return null;
        }

        try {
            $response = Http::timeout((int) config('tracking.route.timeout_seconds', 4))
                ->withHeaders([
                    'X-Goog-Api-Key' => $key,
                    'X-Goog-FieldMask' => self::FIELD_MASK,
                ])
                ->post(self::ENDPOINT, array_filter([
                    'origin' => $this->point($fromLat, $fromLng),
                    'destination' => $this->point($toLat, $toLng),

                    // A scooter, not a car. See the class note.
                    'travelMode' => 'TWO_WHEELER',

                    /*
                     * The arrival time a customer actually waits out, rather
                     * than a free-flowing road at seven in the evening. It is
                     * a slower and dearer call than the traffic-unaware
                     * default, and it is the number being promised to
                     * somebody standing at a door.
                     */
                    'routingPreference' => 'TRAFFIC_AWARE',

                    /*
                     * Follow every street. The default simplifies the line,
                     * which on a one-kilometre route can cut a corner through
                     * a building and make the rider look lost on the
                     * customer's map.
                     */
                    'polylineQuality' => 'HIGH_QUALITY',

                    'languageCode' => $language,
                    'units' => 'METRIC',
                ], fn ($value) => $value !== null));

            /*
             * Unlike the legacy API, this one uses real HTTP status codes —
             * a bad key, a disabled API or an exhausted quota arrive as 4xx
             * rather than as a 200 with a status field to inspect. Logged
             * with the body, because "403" alone does not distinguish a key
             * restricted to the wrong IP from an API nobody enabled, and
             * those have very different fixes.
             */
            if (! $response->successful()) {
                Log::warning('Routes API refused the request', [
                    'status' => $response->status(),
                    'error' => $response->json('error.message'),
                ]);

                return null;
            }

            $route = $response->json('routes.0');
            $polyline = $route['polyline']['encodedPolyline'] ?? null;
            $seconds = $this->seconds($route['duration'] ?? null);

            /*
             * A 200 with no route in it is an ordinary answer: two points
             * with no road between them. Null, and the caller draws a
             * straight line, which is honest about what we know.
             */
            if ($polyline === null || $seconds === null) {
                return null;
            }

            return [
                'polyline' => $polyline,
                'duration_seconds' => $seconds,
                'distance_metres' => (int) ($route['distanceMeters'] ?? 0),
                'raw' => $route,
            ];
        } catch (Throwable $e) {
            // A dropped connection must not break a map somebody is watching.
            Log::warning('Routes API unreachable', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** @return array<string, mixed> */
    protected function point(float $latitude, float $longitude): array
    {
        return ['location' => ['latLng' => ['latitude' => $latitude, 'longitude' => $longitude]]];
    }

    /**
     * The turns, in riding order.
     *
     * @param  array<string, mixed>  $route
     * @return array<int, array{instruction: string, maneuver: string|null, distance_metres: int, duration_seconds: int, end_latitude: float, end_longitude: float}>
     */
    protected function steps(array $route): array
    {
        $steps = [];

        foreach ($route['legs'] ?? [] as $leg) {
            foreach ($leg['steps'] ?? [] as $step) {
                $end = $step['endLocation']['latLng'] ?? null;

                /*
                 * A step with no end point cannot be navigated to. Skipped
                 * rather than passed on with zeroes, which would put a turn
                 * in the Gulf of Guinea and send the rider's camera with it.
                 */
                if (! isset($end['latitude'], $end['longitude'])) {
                    continue;
                }

                $steps[] = [
                    /*
                     * Plain text already, unlike the legacy API's HTML. The
                     * app renders this directly, so anything that arrived as
                     * markup would be shown to a rider as angle brackets.
                     */
                    'instruction' => trim((string) ($step['navigationInstruction']['instructions'] ?? '')),

                    // Absent on steps that are not turns — "head north",
                    // "you have arrived". An app that assumes an icon for
                    // every step draws a wrong one.
                    'maneuver' => $step['navigationInstruction']['maneuver'] ?? null,

                    'distance_metres' => (int) ($step['distanceMeters'] ?? 0),
                    'duration_seconds' => $this->seconds($step['staticDuration'] ?? null) ?? 0,
                    'end_latitude' => (float) $end['latitude'],
                    'end_longitude' => (float) $end['longitude'],
                ];
            }
        }

        return $steps;
    }

    /**
     * Durations arrive as a protobuf duration string — "540s", sometimes
     * with a fraction as "540.5s".
     *
     * Returned as whole seconds, and null when it is not a duration at all.
     * `(int) "540s"` would quietly work and `(int) "abouts"` would quietly be
     * zero, which is an arrival time of "now" on a customer's screen.
     */
    protected function seconds(mixed $duration): ?int
    {
        if (is_int($duration) || is_float($duration)) {
            return (int) round((float) $duration);
        }

        if (! is_string($duration) || ! preg_match('/^(\d+(?:\.\d+)?)s$/', trim($duration), $m)) {
            return null;
        }

        return (int) round((float) $m[1]);
    }
}
