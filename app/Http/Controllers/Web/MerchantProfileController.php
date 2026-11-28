<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * A merchant editing their own details (EP2, EP3).
 *
 * The API has had this since EP2; the portal never did, so a merchant could
 * not correct their own phone number — or, more seriously, their location.
 *
 * KYC fields are absent by design: letting a verified merchant edit their own
 * FSSAI number would make verification meaningless. Commission is absent for
 * the same reason it is not mass-assignable — it is a contract term.
 */
class MerchantProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('merchants.profile', ['merchant' => $this->merchant($request)]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'business_phone' => ['nullable', 'string', 'regex:/^[6-9]\d{9}$/'],
            'business_email' => ['nullable', 'email', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],

            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'pincode' => ['required', 'string', 'regex:/^[1-9]\d{5}$/'],

            /*
             * Required, not optional. Delivery is capped at 1 km and matching
             * is done on coordinates — a merchant without them is invisible to
             * every customer, and cannot work out why.
             */
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],

            'avg_prep_time_minutes' => ['required', 'integer', 'between:1,120'],
            'packaging_fee' => ['required', 'numeric', 'between:0,500'],
            'min_order_value' => ['required', 'numeric', 'between:0,10000'],
            'supports_pickup' => ['sometimes', 'boolean'],
        ], [
            'business_phone.regex' => __('portal.profile.phone_invalid'),
            'pincode.regex' => __('portal.profile.pincode_invalid'),
            'latitude.required' => __('portal.profile.location_required'),
            'longitude.required' => __('portal.profile.location_required'),
        ]);

        $this->merchant($request)->update($data);

        return back()->with('status', __('portal.profile.saved'));
    }

    /**
     * Change your own password.
     *
     * The other half of an administrator being able to reset one. Without
     * this, a merchant who has been given a temporary password over the
     * phone keeps it forever, and the person who read it out still knows it.
     *
     * There is no self-service "forgot password" and cannot be one yet: a
     * reset link needs mail, which is not configured, and a reset code needs
     * SMS, which is waiting on DLT registration. A link that silently goes
     * to a log file would be worse than no link at all — the merchant would
     * wait for an email that was never sent. Until one of those exists, a
     * locked-out merchant rings Nexmile and an administrator resets it.
     */
    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', Password::min(10), 'confirmed'],
        ], [
            'current_password.current_password' => __('portal.profile.password_wrong'),
        ]);

        $request->user()->forceFill(['password' => $data['password']])->save();

        /*
         * Other sessions are closed. A password is usually changed because
         * somebody else knows the old one — a shared till tablet, a member of
         * staff who has left — and leaving their session signed in makes the
         * change decorative.
         */
        Auth::logoutOtherDevices($data['password']);

        return back()->with('status', __('portal.profile.password_saved'));
    }

    protected function merchant(Request $request): Merchant
    {
        $merchant = $request->user()->merchant;

        abort_if($merchant === null, 404);

        return $merchant;
    }
}
