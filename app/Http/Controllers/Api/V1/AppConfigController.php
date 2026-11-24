<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings the app reads at startup (EP1).
 *
 * Deliberately small, and the rule for what belongs here is narrow: things
 * that must be changeable **without an app release**, and that are the same
 * for everybody. A support number changes when the SIM changes. A chat
 * feature has to be switchable off at eight on a Friday evening without
 * waiting for a store review.
 *
 * Anything that differs per customer belongs on an endpoint that knows who
 * they are. Anything that only changes with a release belongs in the app.
 *
 * **Unauthenticated**, because an app may need the support number before
 * anybody has signed in — which is exactly when somebody is stuck and
 * wants to phone a human. Nothing here is private: it is the company's
 * published contact details and two booleans.
 */
class AppConfigController extends Controller
{
    /**
     * App configuration
     *
     * Read once at startup and cached by the app. None of it is secret and
     * none of it is per-customer.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'support' => [
                /*
                 * Null until a real number exists, and the app hides its call
                 * button when it is null rather than dialling something
                 * invented. A support number that rings nowhere is worse than
                 * no support number: it spends somebody's last patience.
                 */
                'phone' => config('site.support.phone'),
                'email' => config('site.email.support'),

                /*
                 * The chat's off switch, server-side.
                 *
                 * Separate from `available` on /support/entry, which reports
                 * that the platform cannot be reached. This one is a
                 * decision: switched off deliberately, for a festival night
                 * or a bill that got away. The app should hide the bubble
                 * entirely when it is false, not show a broken one.
                 */
                'chat_enabled' => (bool) config('site.support.chat_enabled'),
            ],

            'locales' => array_keys(config('site.locales')),
        ]]);
    }
}
