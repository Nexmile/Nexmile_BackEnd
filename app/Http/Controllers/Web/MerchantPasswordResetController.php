<?php

namespace App\Http\Controllers\Web;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\RefreshToken;
use App\Services\Auth\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * A merchant letting themselves back in, with a code to their own inbox.
 *
 * This replaces ringing Nexmile, which was the only way before and is still
 * the fallback when there is no mail gateway.
 *
 * **It refuses to exist when mail is not configured.** `MAIL_MAILER` defaults
 * to `log`, which writes the message to a file on the server and reports
 * success — so the form would take an address, say "check your email", and
 * send nothing. A restaurant would sit waiting for a code that was never
 * posted rather than picking up the phone, and lose the evening. Better to
 * have no link than a link that lies.
 *
 * The code itself is the same machinery as a customer signing in: one table,
 * one expiry, one attempt ceiling, one burn-the-previous-code rule. A second
 * implementation would be a second place to get those wrong.
 */
class MerchantPasswordResetController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * Is there anything behind this feature?
     *
     * `log` writes to a file, `array` keeps it in memory for tests. Neither
     * reaches a merchant.
     */
    public static function available(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array', null, ''], true);
    }

    public function show(): View
    {
        abort_unless(self::available(), 404);

        return view('merchants.password.request');
    }

    /**
     * Send a code, or appear to.
     *
     * The response does not change when the address is unknown, and that is
     * not politeness. A form that says "no such account" is a way to find
     * out which restaurants are on Nexmile and which email addresses they
     * use, one guess at a time, from a page needing no login.
     */
    public function send(Request $request): RedirectResponse
    {
        abort_unless(self::available(), 404);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
        ]);

        /*
         * Per address and per caller. OtpService already throttles an
         * identifier, which stops one address being mailed repeatedly; this
         * stops one machine working through a list of them.
         */
        $key = 'merchant-reset:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages([
                'email' => __('portal.login.reset_throttled'),
            ]);
        }

        RateLimiter::hit($key, 3600);

        $merchant = User::where('email', $data['email'])
            ->where('role', UserRole::Merchant)
            ->first();

        /*
         * Sent only to a real merchant, and the caller is told the same
         * thing either way. Rescued because a mail gateway having a bad
         * minute must not turn into a different-looking response — the
         * difference is the leak.
         */
        if ($merchant !== null) {
            rescue(fn () => $this->otp->request(
                identifier: $merchant->email,
                intendedRole: 'merchant',
                ip: $request->ip(),
                purpose: 'password_reset',
            ), report: true);
        }

        return redirect()
            ->route('merchants.password.reset', ['email' => $data['email']])
            ->with('status', __('portal.login.reset_sent'));
    }

    public function edit(Request $request): View
    {
        abort_unless(self::available(), 404);

        return view('merchants.password.reset', [
            'email' => (string) $request->query('email', ''),
        ]);
    }

    /**
     * The code, and a new password.
     */
    public function update(Request $request): RedirectResponse
    {
        abort_unless(self::available(), 404);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'code' => ['required', 'string', 'max:10'],
            'password' => ['required', 'string', Password::min(10), 'confirmed'],
        ]);

        $merchant = User::where('email', $data['email'])
            ->where('role', UserRole::Merchant)
            ->first();

        /*
         * Checked before the code, and reported as a wrong code.
         *
         * An unknown address that answered differently from a wrong code
         * would turn this form into the account-enumeration tool the send
         * step refuses to be.
         */
        if ($merchant === null) {
            throw ValidationException::withMessages([
                'code' => 'That code is not correct.',
            ]);
        }

        // Throws on a wrong, expired or exhausted code, and burns it on
        // success so a replay cannot set the password twice.
        $this->otp->check($data['email'], $data['code'], 'password_reset');

        $merchant->forceFill(['password' => $data['password']])->save();

        /*
         * Everything signed in as them is signed out. A reset is usually
         * asked for because access was lost or shared, and leaving the old
         * sessions alive makes it decorative.
         */
        $merchant->tokens()->delete();
        RefreshToken::where('user_id', $merchant->id)->delete();

        return redirect()
            ->route('merchants.login')
            ->with('status', __('portal.login.reset_done'));
    }
}
