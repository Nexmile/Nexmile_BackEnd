<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Getting a locked-out restaurant back in.
 *
 * There was no way at all: no reset route anywhere in the application, and
 * no way for a merchant to change their own password once they had one. A
 * restaurant that forgot it stopped trading until somebody opened a database
 * console.
 *
 * It is an administrator handing over a temporary password rather than a
 * "forgot password" link, and that is a decision rather than a shortcut.
 * `MAIL_MAILER` defaults to `log`, so a reset link would land in a file on
 * the server while the merchant waited for an email nobody sent. SMS is
 * waiting on DLT. A button that silently does nothing is worse than no
 * button, because the person cannot tell a broken system from a slow one.
 */
class MerchantPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(string $password = 'original-password'): Merchant
    {
        static $n = 0;
        $n++;

        $owner = User::create([
            'name' => 'Owner '.$n,
            'phone' => '97810001'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "shop{$n}@example.in",
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

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'phone' => '9781999999', 'email' => 'pwadmin@example.in',
            'password' => 'secret', 'role' => UserRole::Admin, 'status' => UserStatus::Active,
        ]);
    }

    /* ------------------------------------- the merchant's own ----- */

    public function test_a_merchant_can_change_their_own_password(): void
    {
        $merchant = $this->merchant();

        $this->actingAs($merchant->user)
            ->post('/merchants/profile/password', [
                'current_password' => 'original-password',
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])
            ->assertRedirect();

        $this->assertTrue(auth()->attempt([
            'email' => $merchant->user->email,
            'password' => 'a-brand-new-password',
        ]));
    }

    public function test_the_current_password_has_to_be_right(): void
    {
        // Otherwise anybody who finds an unattended till tablet owns the
        // account rather than merely borrowing the session.
        $merchant = $this->merchant();

        $this->actingAs($merchant->user)
            ->post('/merchants/profile/password', [
                'current_password' => 'not-it',
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(auth()->attempt([
            'email' => $merchant->user->email, 'password' => 'original-password',
        ]));
    }

    public function test_a_short_or_unconfirmed_password_is_refused(): void
    {
        $merchant = $this->merchant();

        $this->actingAs($merchant->user)->post('/merchants/profile/password', [
            'current_password' => 'original-password',
            'password' => 'short', 'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->actingAs($merchant->user)->post('/merchants/profile/password', [
            'current_password' => 'original-password',
            'password' => 'a-brand-new-password', 'password_confirmation' => 'something-else',
        ])->assertSessionHasErrors('password');
    }

    public function test_changing_it_needs_a_signed_in_merchant(): void
    {
        $this->post('/merchants/profile/password', [
            'current_password' => 'original-password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect();
    }

    /* ------------------------------------ the administrator's ----- */

    public function test_an_admin_can_set_a_temporary_password_and_is_shown_it_once(): void
    {
        $merchant = $this->merchant();

        $response = $this->actingAs($this->admin())
            ->post("/admin/merchants/{$merchant->id}/password")
            ->assertRedirect();

        $reset = $response->getSession()->get('password_reset');

        $this->assertNotEmpty($reset['password']);
        $this->assertSame($merchant->business_name, $reset['name']);

        // And it actually works, which is the only thing that makes printing
        // it worth doing.
        $this->assertTrue(auth()->attempt([
            'email' => $merchant->user->email,
            'password' => $reset['password'],
        ]));
    }

    public function test_the_temporary_password_is_never_written_down(): void
    {
        /*
         * Flashed to one response and stored nowhere. A temporary password
         * sitting in a database is a permanent credential in a table nobody
         * is watching.
         */
        $merchant = $this->merchant();

        $response = $this->actingAs($this->admin())
            ->post("/admin/merchants/{$merchant->id}/password");

        $password = $response->getSession()->get('password_reset')['password'];

        $stored = json_encode(User::find($merchant->user_id)?->toArray());

        $this->assertStringNotContainsString($password, (string) $stored);
        $this->assertNotSame($password, User::find($merchant->user_id)?->password);
    }

    public function test_a_reset_signs_every_device_out(): void
    {
        /*
         * A reset is usually asked for because access was lost or shared.
         * Leaving the old sessions signed in makes it decorative.
         */
        $merchant = $this->merchant();
        $token = $merchant->user->createToken('till-tablet')->plainTextToken;

        $this->assertSame(1, $merchant->user->tokens()->count());

        $this->actingAs($this->admin())
            ->post("/admin/merchants/{$merchant->id}/password")
            ->assertRedirect();

        $this->assertSame(0, $merchant->user->fresh()->tokens()->count());

        /*
         * The guards are forgotten first, or this proves nothing: acting as
         * the administrator above leaves that resolved for the rest of the
         * test, and the call below would be authenticated as them rather
         * than by the token we are trying to show is dead.
         */
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer '.$token])
            ->assertUnauthorized();
    }

    public function test_only_an_administrator_may_reset_one(): void
    {
        /*
         * This hands over working access to somebody's business. A merchant
         * resetting another merchant would be the whole platform.
         */
        $mine = $this->merchant();
        $theirs = $this->merchant();

        $this->actingAs($mine->user)
            ->post("/admin/merchants/{$theirs->id}/password")
            ->assertForbidden();

        $this->assertTrue(auth()->attempt([
            'email' => $theirs->user->email, 'password' => 'original-password',
        ]));
    }

    public function test_an_anonymous_caller_cannot_reset_one(): void
    {
        $merchant = $this->merchant();

        $this->post("/admin/merchants/{$merchant->id}/password")->assertRedirect();

        $this->assertTrue(auth()->attempt([
            'email' => $merchant->user->email, 'password' => 'original-password',
        ]));
    }

    public function test_there_is_still_no_public_forgot_password_route(): void
    {
        /*
         * Asserted rather than assumed. The moment mail or SMS is
         * configured, adding one is right — but adding one *now* would be a
         * form that collects an email address and then does nothing
         * observable, and a merchant would wait on it instead of ringing us.
         */
        foreach (['/forgot-password', '/password/reset', '/merchants/forgot-password'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }
}
