<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\OtpCodeMail;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A merchant letting themselves back in, with a code to their own inbox.
 *
 * This replaces ringing Nexmile, which was the only way before and remains
 * the fallback when there is no mail gateway.
 *
 * Most of these are about what the form refuses to reveal. A password reset
 * page needs no login, so every difference between a known address and an
 * unknown one is a way to find out which restaurants are on Nexmile and
 * which email addresses they use, one guess at a time.
 */
class MerchantPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A real mailer, faked. The flow refuses to exist on `log`.
        config(['mail.default' => 'smtp']);
        Mail::fake();
    }

    private function merchant(string $password = 'original-password'): Merchant
    {
        static $n = 0;
        $n++;

        $owner = User::create([
            'name' => 'Owner '.$n,
            'phone' => '97820001'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "reset{$n}@example.in",
            'password' => $password,
            'role' => UserRole::Merchant,
            'status' => UserStatus::Active,
        ]);

        return Merchant::create([
            'user_id' => $owner->id, 'business_name' => 'Hotel '.$n, 'owner_name' => 'Owner',
            'address_line1' => '1 Main Road', 'city' => 'Madurai', 'pincode' => '625001',
            'latitude' => 9.9195, 'longitude' => 78.1193,
            'kyc_status' => KycStatus::Verified, 'is_accepting_orders' => true,
        ]);
    }

    /** The code that was actually emailed, since the table only has its hash. */
    private function mailedCode(): ?string
    {
        $code = null;

        Mail::assertSent(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return $code;
    }

    /* ------------------------------------- only when it can send --- */

    public function test_the_whole_flow_is_absent_without_a_mail_gateway(): void
    {
        /*
         * MAIL_MAILER defaults to `log`, which writes the message to a file
         * on the server and reports success. A form offered then would say
         * "check your email" and send nothing, and a restaurant would wait
         * all evening instead of ringing us. Better no link than one that
         * lies.
         */
        config(['mail.default' => 'log']);

        $this->get('/merchants/forgot-password')->assertNotFound();
        $this->post('/merchants/forgot-password', ['email' => 'a@b.in'])->assertNotFound();
        $this->get('/merchants/reset-password')->assertNotFound();
        $this->post('/merchants/reset-password', [])->assertNotFound();

        $this->get('/merchants/login')->assertOk()->assertSee('Nexmile');
    }

    public function test_the_link_appears_as_soon_as_mail_is_configured(): void
    {
        // The page asks the mailer rather than a feature flag, so switching
        // SMTP on is the whole release.
        $this->get('/merchants/login')
            ->assertOk()
            ->assertSee('Reset your password')
            ->assertDontSee('Call Nexmile on');
    }

    /* ------------------------------------------- the happy path ---- */

    public function test_a_merchant_resets_their_password_with_an_emailed_code(): void
    {
        $merchant = $this->merchant();

        $this->post('/merchants/forgot-password', ['email' => $merchant->user->email])
            ->assertRedirect();

        $this->post('/merchants/reset-password', [
            'email' => $merchant->user->email,
            'code' => $this->mailedCode(),
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect(route('merchants.login'));

        $this->assertTrue(auth()->attempt([
            'email' => $merchant->user->email,
            'password' => 'a-brand-new-password',
        ]));
    }

    public function test_a_reset_signs_every_device_out(): void
    {
        // A reset is usually asked for because access was lost or shared.
        // Leaving the old sessions alive makes it decorative.
        $merchant = $this->merchant();
        $merchant->user->createToken('till-tablet');

        $this->post('/merchants/forgot-password', ['email' => $merchant->user->email]);

        $this->post('/merchants/reset-password', [
            'email' => $merchant->user->email, 'code' => $this->mailedCode(),
            'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect();

        $this->assertSame(0, $merchant->user->fresh()->tokens()->count());
    }

    /* ------------------------------------- what it will not say ---- */

    public function test_an_unknown_email_answers_exactly_like_a_known_one(): void
    {
        /*
         * Not politeness. This page needs no login, so a form that says "no
         * such account" is a way to enumerate every restaurant on Nexmile
         * and the address each one uses.
         */
        $merchant = $this->merchant();

        $known = $this->post('/merchants/forgot-password', ['email' => $merchant->user->email]);
        $unknown = $this->post('/merchants/forgot-password', ['email' => 'nobody@example.in']);

        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame(
            $known->getSession()->get('status'),
            $unknown->getSession()->get('status'),
        );

        // And nothing went to the address that does not exist.
        Mail::assertSentCount(1);
    }

    public function test_an_unknown_email_at_the_second_step_reads_as_a_wrong_code(): void
    {
        // Otherwise the second form becomes the enumeration tool the first
        // one refuses to be.
        $this->post('/merchants/reset-password', [
            'email' => 'nobody@example.in', 'code' => '123456',
            'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasErrors('code');
    }

    public function test_a_customer_address_gets_no_reset_code(): void
    {
        /*
         * Merchant-only. A customer signs in with a code to their phone and
         * has no portal password, so a reset code would be for an account
         * that cannot use one.
         */
        $customer = User::create([
            'name' => 'Cust', 'phone' => '9780000888', 'email' => 'cust.reset@example.in',
            'password' => 'secret', 'role' => UserRole::Customer, 'status' => UserStatus::Active,
        ]);

        $this->post('/merchants/forgot-password', ['email' => $customer->email])->assertRedirect();

        Mail::assertNothingSent();
    }

    /* ----------------------------------------------- the code ------ */

    public function test_a_wrong_code_changes_nothing(): void
    {
        $merchant = $this->merchant();

        $this->post('/merchants/forgot-password', ['email' => $merchant->user->email]);

        $this->post('/merchants/reset-password', [
            'email' => $merchant->user->email, 'code' => '000000',
            'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasErrors('code');

        $this->assertTrue(auth()->attempt([
            'email' => $merchant->user->email, 'password' => 'original-password',
        ]));
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        /*
         * Burned on success, so a replay cannot set the password again —
         * which is how a code read over somebody's shoulder becomes a
         * lasting problem rather than a moment's one.
         */
        $merchant = $this->merchant();
        $this->post('/merchants/forgot-password', ['email' => $merchant->user->email]);

        $payload = [
            'email' => $merchant->user->email, 'code' => $this->mailedCode(),
            'password' => 'first-new-password', 'password_confirmation' => 'first-new-password',
        ];

        $this->post('/merchants/reset-password', $payload)->assertRedirect();

        $this->post('/merchants/reset-password', array_merge($payload, [
            'password' => 'second-new-password', 'password_confirmation' => 'second-new-password',
        ]))->assertSessionHasErrors('code');

        $this->assertTrue(auth()->attempt([
            'email' => $merchant->user->email, 'password' => 'first-new-password',
        ]));
    }

    public function test_a_short_password_is_refused_even_with_the_right_code(): void
    {
        $merchant = $this->merchant();
        $this->post('/merchants/forgot-password', ['email' => $merchant->user->email]);

        $this->post('/merchants/reset-password', [
            'email' => $merchant->user->email, 'code' => $this->mailedCode(),
            'password' => 'short', 'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(auth()->attempt([
            'email' => $merchant->user->email, 'password' => 'original-password',
        ]));
    }
}
