<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\RazorpayGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Which gateway gets bound, and what happens when the setting is wrong.
 *
 * This used to be `default => new FakeGateway`, which meant any value that
 * was not exactly `razorpay` fell through to a gateway issuing pretend
 * orders and a publishable key reading `rzp_test_fake`. On production. With
 * no error anywhere, and the only symptom a customer saying money does not
 * go into their wallet.
 *
 * `PAYMENT_GATEWAY=Razorpay`, a trailing space, a typo — all of them. The
 * vocabulary is closed now, and these are the tests that keep it closed.
 */
class PaymentGatewayBindingTest extends TestCase
{
    use RefreshDatabase;

    private function bind(?string $gateway): PaymentGateway
    {
        config(['payments.gateway' => $gateway]);

        $this->app->forgetInstance(PaymentGateway::class);

        return app(PaymentGateway::class);
    }

    public function test_razorpay_is_bound_when_it_is_named(): void
    {
        $this->assertInstanceOf(RazorpayGateway::class, $this->bind('razorpay'));
    }

    public function test_no_gateway_means_cash_only_rather_than_a_failure(): void
    {
        /*
         * A real and supported state. Nothing calls this instance — the
         * wallet and payment endpoints refuse on a null gateway first — but
         * it has to resolve, or injecting the contract would break a
         * cash-only deployment on every request.
         */
        $this->assertInstanceOf(FakeGateway::class, $this->bind(null));
        $this->assertInstanceOf(FakeGateway::class, $this->bind(''));
    }

    public function test_the_fake_is_available_outside_production(): void
    {
        $this->assertInstanceOf(FakeGateway::class, $this->bind('fake'));
    }

    public function test_a_capitalised_value_is_refused_rather_than_faked(): void
    {
        /*
         * The exact shape of the original bug. `.env` files are edited by
         * hand at eleven at night.
         */
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown payment gateway [Razorpay]');

        $this->bind('Razorpay');
    }

    public function test_a_typo_is_refused_rather_than_faked(): void
    {
        $this->expectException(RuntimeException::class);

        $this->bind('razorpay1');
    }

    public function test_a_trailing_space_is_refused_rather_than_faked(): void
    {
        // Invisible in a terminal, invisible in a diff, and it used to mean
        // production quietly stopped taking money.
        $this->expectException(RuntimeException::class);

        $this->bind('razorpay ');
    }

    public function test_the_fake_gateway_is_refused_on_production(): void
    {
        /*
         * The fake signs with a real HMAC and approves its own payments,
         * which is exactly what makes it useful in tests and exactly what
         * makes it a way to order food without paying.
         */
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must never take real money');

        $this->bind('fake');
    }

    public function test_the_key_secret_is_never_sent_to_a_customer(): void
    {
        /*
         * The app gets the publishable key id and nothing else. A secret in
         * an API response is a secret in a proxy log, a crash report and a
         * screenshot.
         */
        config([
            'payments.gateway' => 'fake',
            'payments.razorpay.secret' => 'rzp_secret_must_not_leak',
            'payments.razorpay.webhook_secret' => 'whsec_must_not_leak',
        ]);

        $customer = \App\Models\User::create([
            'name' => 'Topper', 'phone' => '9780000777', 'email' => 'topup@example.in',
            'password' => 'secret', 'role' => \App\Enums\UserRole::Customer,
            'status' => \App\Enums\UserStatus::Active,
        ]);

        $body = $this->actingAs($customer)
            ->postJson('/api/v1/wallet/topups', ['amount' => 100])
            ->assertCreated()
            ->getContent();

        $this->assertStringNotContainsString('rzp_secret_must_not_leak', (string) $body);
        $this->assertStringNotContainsString('whsec_must_not_leak', (string) $body);

        // And what it does send is the publishable key, under the name the
        // app already binds to.
        $this->assertNotEmpty(json_decode((string) $body, true)['data']['razorpay_key_id']);
    }
}
