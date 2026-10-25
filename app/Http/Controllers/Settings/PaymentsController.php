<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Stripe\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PaymentsController extends Controller
{
    /**
     * Show the business's online payment settings.
     */
    public function edit(Request $request): Response
    {
        $business = $request->user()->currentBusiness;

        return Inertia::render('settings/payments', [
            'stripe' => [
                'configured' => filled(config('services.stripe.secret')) && filled(config('services.stripe.key')),
                'connected' => $business->stripe_account_id !== null,
                'charges_enabled' => $business->stripe_charges_enabled,
                'publishable_key' => config('services.stripe.key'),
                // Connected accounts have the full Stripe Dashboard for payouts, refunds and disputes.
                'dashboard_url' => $business->stripe_account_id ? "https://dashboard.stripe.com/{$business->stripe_account_id}" : null,
            ],
            'canUpdate' => $request->user()->can('update', $business),
        ]);
    }

    /**
     * Create the business's connected Stripe account. Onboarding then happens on
     * the payment settings page with Stripe's embedded onboarding component.
     */
    public function connect(Request $request, StripeGateway $stripe): RedirectResponse
    {
        $business = $request->user()->currentBusiness;

        Gate::authorize('update', $business);
        abort_unless(filled(config('services.stripe.secret')), 503, 'Stripe is not configured.');

        if (! $business->stripe_account_id) {
            $business->forceFill([
                'stripe_account_id' => $stripe->createAccount($business->email ?? $request->user()->email, $business->name),
            ])->save();
        }

        return to_route('payments.edit');
    }

    /**
     * A client secret for the embedded onboarding and notification banner components.
     */
    public function session(Request $request, StripeGateway $stripe): JsonResponse
    {
        $business = $request->user()->currentBusiness;

        Gate::authorize('update', $business);
        abort_unless($business->stripe_account_id, 404);

        return response()->json(['client_secret' => $stripe->accountSessionSecret($business->stripe_account_id)]);
    }

    /**
     * Re-check whether the account can take payments, e.g. after leaving onboarding.
     * The account.updated webhook does the same, but may arrive a little later.
     */
    public function sync(Request $request, StripeGateway $stripe): RedirectResponse
    {
        $business = $request->user()->currentBusiness;

        if ($business->stripe_account_id) {
            $business->forceFill(['stripe_charges_enabled' => $stripe->chargesEnabled($business->stripe_account_id)])->save();
        }

        return to_route('payments.edit');
    }

    /**
     * Stop taking online payments. The Stripe account itself stays with the business.
     */
    public function disconnect(Request $request): RedirectResponse
    {
        $business = $request->user()->currentBusiness;

        Gate::authorize('update', $business);

        $business->forceFill(['stripe_account_id' => null, 'stripe_charges_enabled' => false])->save();

        return to_route('payments.edit');
    }
}
