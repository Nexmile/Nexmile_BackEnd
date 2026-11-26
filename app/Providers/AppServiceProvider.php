<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Contracts\PushSender;
use App\Contracts\RoadDistanceProvider;
use App\Contracts\RouteProvider;
use App\Contracts\SmsSender;
use App\Services\Discovery\RoadDistance\GoogleDistanceMatrix;
use App\Services\Discovery\RoadDistance\NullRoadDistance;
use App\Services\LiveState\Routing\GoogleDirections;
use App\Services\LiveState\Routing\NullRoute;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\RazorpayGateway;
use App\Services\Push\FcmPushSender;
use App\Services\Push\LogPushSender;
use App\Services\Push\NullPushSender;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\NullSmsSender;
use App\Services\Support\BpoClient;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * The support platform.
         *
         * Built even with no credential, and the client then answers null to
         * everything, which the controller turns into "support is
         * unavailable" and a phone number. Refusing to construct would turn
         * every support request into a 500 on a backend that is otherwise
         * perfectly healthy — and the natural deployment order is this
         * application first, before the token exists on either side.
         *
         * It is not silent: the client logs a warning on each call, so an
         * unconfigured install does not read as an outage.
         */
        $this->app->singleton(BpoClient::class, fn () => new BpoClient(
            baseUrl: (string) config('support.base_url'),
            token: (string) (config('support.token') ?? ''),
            timeout: (int) config('support.timeout_seconds'),
        ));
        // Swap SMS_DRIVER to add a real gateway; nothing else changes.
        $this->app->bind(SmsSender::class, function () {
            return match (config('sms.driver')) {
                'null' => new NullSmsSender,
                default => new LogSmsSender,
            };
        });

        // Same shape again. With no PUSH_DRIVER the log driver is bound, so
        // the dispatch chain works end to end without a Firebase project.
        $this->app->bind(PushSender::class, function () {
            return match (config('push.driver')) {
                'fcm' => new FcmPushSender,
                'null' => new NullPushSender,
                default => new LogPushSender,
            };
        });

        /*
         * Road distance. The null driver measures nothing and every caller
         * falls back to the straight line, which is what discovery did before
         * this existed — so an unset key degrades the answer rather than
         * emptying the busiest screen in the product.
         */
        $this->app->bind(RoadDistanceProvider::class, function () {
            return match (config('discovery.road_distance.driver')) {
                'google' => new GoogleDistanceMatrix,
                default => new NullRoadDistance,
            };
        });

        /*
         * The line drawn on a customer's tracking map. The null driver
         * draws nothing and the map falls back to a moving marker with an
         * estimate from the straight line — a missing key must degrade the
         * map, never blank it while somebody waits for dinner.
         */
        $this->app->bind(RouteProvider::class, function () {
            return match (config('tracking.route.driver')) {
                'google' => new GoogleDirections,
                default => new NullRoute,
            };
        });

        /*
         * Which gateway takes money.
         *
         * This used to be `default => new FakeGateway`, and that default was
         * a trap. `PAYMENT_GATEWAY=Razorpay`, or a trailing space in the
         * `.env`, or `razorpay1` from a fat finger, all fell through to a
         * gateway that issues pretend orders and a publishable key reading
         * `rzp_test_fake` — on production, with no error anywhere. The app
         * would open a checkout that cannot complete and the only symptom
         * would be a customer saying money does not go in.
         *
         * So the vocabulary is now closed. A value we do not recognise is a
         * misconfiguration, and a misconfiguration in the thing that takes
         * money should stop the request, not quietly pretend.
         */
        $this->app->bind(PaymentGateway::class, function () {
            $gateway = config('payments.gateway');

            if ($gateway === 'razorpay') {
                return new RazorpayGateway;
            }

            /*
             * Cash only, which is a real and supported state.
             *
             * Nothing reaches this instance: `topup()` and both payment
             * endpoints refuse on a null gateway before they use it. It is
             * bound rather than left unresolvable so that injecting the
             * contract does not fail on a cash-only deployment.
             */
            if ($gateway === null || $gateway === '') {
                return new FakeGateway;
            }

            if ($gateway === 'fake') {
                /*
                 * Deliberate in tests and local work — the fake signs with a
                 * real HMAC, so the verification path is genuinely exercised.
                 * Never on production, where a gateway that approves its own
                 * payments is a way to order food without paying.
                 */
                if (app()->isProduction()) {
                    throw new RuntimeException(
                        'PAYMENT_GATEWAY=fake on production. The fake gateway approves its own '
                        .'payments and must never take real money. Set PAYMENT_GATEWAY=razorpay, '
                        .'or leave it unset for cash only.'
                    );
                }

                return new FakeGateway;
            }

            throw new RuntimeException(
                "Unknown payment gateway [{$gateway}]. Use 'razorpay', or 'fake' outside "
                .'production, or leave PAYMENT_GATEWAY unset for cash only.'
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerApiDocs();

        /*
         * Support messages, per customer.
         *
         * Each one may cost a model call, so this caps a bill as much as it
         * caps load. Keyed on the user rather than the IP: a whole hostel on
         * one connection is many customers, and throttling them together
         * would take support away from people who had done nothing.
         */
        RateLimiter::for('support', fn (Request $request) => Limit::perMinute(
            (int) config('support.messages_per_minute')
        )->by((string) $request->user()?->id));
    }

    /**
     * One OpenAPI document per app, alongside the combined one at /docs/api.
     *
     * An app developer reading a single 94-endpoint document has to work out
     * which half belongs to them, and the answer is not obvious — `/profile`
     * is shared, `/auth` is shared, and `/orders` means something different to
     * a customer and a rider. Splitting them is the same reasoning as the
     * three Postman collections.
     */
    private function registerApiDocs(): void
    {
        /*
         * Shared by customer and rider: both sign in the same way and both
         * edit the same profile. A merchant does neither — they use
         * /merchant/login with a password.
         */
        $shared = ['api/v1/auth', 'api/v1/profile', 'api/v1/devices', 'api/v1/app'];

        $apis = [
            'customer' => [
                'title' => 'Nexmile — Customer app',
                'description' => "Everything the customer app needs.\n\n".
                    "Sign-up and sign-in are the same two calls — there is no separate registration endpoint, and the account is created on first successful verification.\n\n".
                    '**Money is a JSON number** and loses its zero fraction: ₹430.00 arrives as `430`. Read it as `num`, never `double`.

'.
                    'The wallet is closed-loop: money goes in through the gateway and comes out only as food. There is no withdrawal endpoint. Spending a balance works even when online payment is unavailable — topping up does not.',
                'prefixes' => [...$shared, 'api/v1/addresses', 'api/v1/restaurants', 'api/v1/carts', 'api/v1/orders',
                    // Home screen v2: discovery, merchandising and favourites.
                    'api/v1/home', 'api/v1/filters', 'api/v1/collections', 'api/v1/favourites',
                    // Reporting the rider who brought an order.
                    'api/v1/report-categories',
                    // Wallet: balance, statement and topping up.
                    'api/v1/wallet',
                    // Rating the app, from the profile page. Preferences ride
                    // in on the shared api/v1/profile prefix above.
                    'api/v1/feedback',
                    // The support bubble. Proxied to the BPO platform, but it
                    // is the customer app that calls it.
                    'api/v1/support'],
            ],
            'rider' => [
                'title' => 'Nexmile — Rider app',
                'description' => "Everything the rider app needs.\n\n".
                    "Auth is identical to the customer app except `intended_role: \"rider\"`. The account comes back `pending` and stays that way until an admin approves the documents, so onboarding is a wizard rather than a single screen.\n\n".
                    "Register for push with `/devices` after signing in, and poll `/rider/orders/available` while the board is on screen — the board is a race between riders, so it is polled rather than pushed.\n\n".
                    'Referral bonuses pay on deliveries the invited rider completes, never on signing up. See `max_per_referral` for the "earn up to" figure rather than printing an amount into the app.',
                'prefixes' => [...$shared, 'api/v1/rider'],
            ],
            'merchant' => [
                'title' => 'Nexmile — Merchant',
                'description' => "Everything a merchant can do over the API.\n\n".
                    "Merchants sign in with a **password**, not an OTP. Registration happens on the website at nexmile.in/merchants/register.\n\n".
                    '**Menu item updates are POST, not PATCH** — PHP does not parse multipart bodies on PATCH, so a PATCH upload arrives with an empty `$_FILES`.',
                'prefixes' => ['api/v1/merchant', 'api/v1/devices'],
            ],
        ];

        foreach ($apis as $name => $api) {
            Scramble::registerApi($name, [
                'info' => [
                    'version' => config('scramble.info.version', '1.0.0'),
                    'description' => $api['description'],
                ],
            ])
                ->routes(fn (Route $route) => Str::startsWith($route->uri(), $api['prefixes']))
                ->afterOpenApiGenerated(function (OpenApi $document) use ($api) {
                    $document->info->title = $api['title'];
                });
        }
    }
}
