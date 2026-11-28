<?php

namespace Tests\Feature;

use App\Contracts\RouteProvider;
use App\Services\LiveState\Routing\GoogleDirections;
use App\Services\LiveState\Routing\GoogleRoutes;
use App\Services\LiveState\Routing\NullRoute;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The road route, from Google's current API.
 *
 * This replaces GoogleDirections rather than sitting beside it. Google lists
 * the Directions API as legacy and a Google Cloud project created today
 * generally cannot enable it at all, so on a new deployment the old provider
 * is not a slower option — it is no option.
 *
 * Most of what is tested here is the parsing, because the failures are quiet
 * ones. A duration that does not parse becomes an arrival time of "now" on a
 * customer's screen; a step with no end point sends the rider's camera to
 * the Gulf of Guinea. Neither looks like an error anywhere.
 */
class GoogleRoutesTest extends TestCase
{
    private const URL = 'https://routes.googleapis.com/*';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google_maps.key' => 'test-server-key']);
        Http::preventStrayRequests();
    }

    /** @param array<string, mixed> $route */
    private function fake(array $route = [], int $status = 200): void
    {
        Http::fake([self::URL => Http::response(['routes' => $route === [] ? [] : [$route]], $status)]);
    }

    /** @return array<string, mixed> */
    private function route(): array
    {
        return [
            'distanceMeters' => 2410,
            'duration' => '540s',
            'polyline' => ['encodedPolyline' => 'yzpkAqfzqMvAcB'],
            'legs' => [[
                'steps' => [
                    [
                        'distanceMeters' => 350,
                        'staticDuration' => '60s',
                        'endLocation' => ['latLng' => ['latitude' => 9.9201, 'longitude' => 78.1210]],
                        'navigationInstruction' => [
                            'instructions' => 'Turn left onto Anna Salai',
                            'maneuver' => 'TURN_LEFT',
                        ],
                    ],
                    [
                        'distanceMeters' => 2060,
                        'staticDuration' => '480s',
                        'endLocation' => ['latLng' => ['latitude' => 9.9250, 'longitude' => 78.1300]],
                        'navigationInstruction' => ['instructions' => 'Destination will be on the right'],
                    ],
                ],
            ]],
        ];
    }

    private function provider(): GoogleRoutes
    {
        return new GoogleRoutes;
    }

    /* ------------------------------------------------ the happy path -- */

    public function test_it_returns_the_line_the_distance_and_the_time(): void
    {
        $this->fake($this->route());

        $result = $this->provider()->between(9.9195, 78.1193, 9.9250, 78.1300);

        $this->assertSame('yzpkAqfzqMvAcB', $result['polyline']);
        $this->assertSame(2410, $result['distance_metres']);
        $this->assertSame(540, $result['duration_seconds']);
    }

    public function test_it_asks_for_a_two_wheeler_on_a_quality_line(): void
    {
        /*
         * The reason this provider exists. Directions had no two-wheeler
         * mode, so the old one asked for driving and overstated every
         * journey — a scooter in Madurai takes lanes and turns a car cannot,
         * and at one kilometre that is most of the trip.
         *
         * HIGH_QUALITY because the default simplifies the line, and on a
         * one-kilometre route a simplified line cuts the corner through a
         * building and makes the rider look lost on the customer's map.
         */
        $this->fake($this->route());

        $this->provider()->between(9.9195, 78.1193, 9.9250, 78.1300);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['travelMode'] === 'TWO_WHEELER'
                && $body['polylineQuality'] === 'HIGH_QUALITY'
                && $body['routingPreference'] === 'TRAFFIC_AWARE'
                && $body['origin']['location']['latLng']['latitude'] === 9.9195;
        });
    }

    public function test_the_field_mask_is_sent_and_the_key_is_a_header(): void
    {
        /*
         * The field mask is not optional on this API — a request without one
         * is refused outright. It is also the bill: the whole route object
         * includes per-step polylines and a viewport nothing here draws.
         */
        $this->fake($this->route());

        $this->provider()->between(9.9195, 78.1193, 9.9250, 78.1300);

        Http::assertSent(function ($request) {
            $mask = $request->header('X-Goog-FieldMask')[0] ?? '';

            return $request->header('X-Goog-Api-Key')[0] === 'test-server-key'
                && str_contains($mask, 'routes.polyline.encodedPolyline')
                && str_contains($mask, 'routes.legs.steps.navigationInstruction')
                // The key goes in a header, never in the URL, where it would
                // be written to every proxy and access log on the way.
                && ! str_contains($request->url(), 'test-server-key');
        });
    }

    public function test_navigation_returns_the_turns_in_riding_order(): void
    {
        $this->fake($this->route());

        $steps = $this->provider()->navigation(9.9195, 78.1193, 9.9250, 78.1300, 'ta')['steps'];

        $this->assertCount(2, $steps);
        $this->assertSame('Turn left onto Anna Salai', $steps[0]['instruction']);
        $this->assertSame('TURN_LEFT', $steps[0]['maneuver']);
        $this->assertSame(350, $steps[0]['distance_metres']);
        $this->assertSame(60, $steps[0]['duration_seconds']);
        $this->assertSame(9.9201, $steps[0]['end_latitude']);

        // Not a turn, so no icon. An app that assumes one for every step
        // draws a wrong one.
        $this->assertNull($steps[1]['maneuver']);
    }

    public function test_the_riders_language_is_passed_on(): void
    {
        // "Turn right onto 3rd Street" is no use to somebody who reads Tamil.
        $this->fake($this->route());

        $this->provider()->navigation(9.9195, 78.1193, 9.9250, 78.1300, 'ta');

        Http::assertSent(fn ($request) => json_decode($request->body(), true)['languageCode'] === 'ta');
    }

    /* -------------------------------------------- the quiet failures -- */

    public function test_an_unparseable_duration_is_not_an_arrival_time_of_now(): void
    {
        /*
         * Durations arrive as "540s". A cast would turn anything else into
         * zero, and zero seconds is "arriving now" on a customer's screen
         * while the rider is still at the restaurant.
         */
        $route = $this->route();
        $route['duration'] = 'soon';

        $this->fake($route);

        $this->assertNull($this->provider()->between(9.9195, 78.1193, 9.9250, 78.1300));
    }

    public function test_a_fractional_duration_is_rounded_rather_than_refused(): void
    {
        $route = $this->route();
        $route['duration'] = '540.6s';

        $this->fake($route);

        $this->assertSame(541, $this->provider()->between(9.9195, 78.1193, 9.9250, 78.1300)['duration_seconds']);
    }

    public function test_a_step_with_no_end_point_is_dropped(): void
    {
        // Passing it on with zeroes would put a turn in the Gulf of Guinea
        // and send the rider's camera with it.
        $route = $this->route();
        unset($route['legs'][0]['steps'][0]['endLocation']);

        $this->fake($route);

        $steps = $this->provider()->navigation(9.9195, 78.1193, 9.9250, 78.1300)['steps'];

        $this->assertCount(1, $steps);
        $this->assertSame('Destination will be on the right', $steps[0]['instruction']);
    }

    public function test_no_route_between_the_points_is_null_not_an_error(): void
    {
        // An ordinary answer: the caller draws a straight line, which is
        // honest about what we know.
        $this->fake();

        $this->assertNull($this->provider()->between(9.9195, 78.1193, 9.9250, 78.1300));
    }

    public function test_a_refusal_is_logged_and_draws_no_route(): void
    {
        /*
         * Unlike the legacy API, this one uses real status codes. A key
         * restricted to the wrong IP and an API nobody enabled both arrive
         * as 403 and have very different fixes, which is why the body is
         * logged rather than just the code.
         */
        Http::fake([self::URL => Http::response([
            'error' => ['message' => 'Routes API has not been used in project 1234 before or it is disabled.'],
        ], 403)]);

        $this->assertNull($this->provider()->between(9.9195, 78.1193, 9.9250, 78.1300));
    }

    public function test_with_no_key_nothing_is_called_at_all(): void
    {
        config(['services.google_maps.key' => null]);
        Http::fake([self::URL => Http::response([], 200)]);

        $provider = $this->provider();

        $this->assertFalse($provider->configured());
        $this->assertNull($provider->between(9.9195, 78.1193, 9.9250, 78.1300));

        Http::assertNothingSent();
    }

    /* ------------------------------------------------- the binding ---- */

    public function test_the_driver_name_selects_the_provider(): void
    {
        foreach ([
            'routes' => GoogleRoutes::class,
            'google' => GoogleDirections::class,
            'directions' => GoogleDirections::class,
            'null' => NullRoute::class,
        ] as $driver => $class) {
            config(['tracking.route.driver' => $driver]);
            $this->app->forgetInstance(RouteProvider::class);

            $this->assertInstanceOf($class, app(RouteProvider::class), "driver [{$driver}]");
        }
    }

    public function test_an_unknown_driver_draws_a_straight_line_rather_than_failing(): void
    {
        /*
         * Logged, not thrown. A typo in an env file should not take a
         * customer's tracking map down — but the degraded map looks exactly
         * like a route that was never configured, so the log line is the
         * only way it gets noticed.
         */
        config(['tracking.route.driver' => 'gogle']);
        $this->app->forgetInstance(RouteProvider::class);

        $this->assertInstanceOf(NullRoute::class, app(RouteProvider::class));
    }
}
