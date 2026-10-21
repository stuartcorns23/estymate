<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Stripe\StripeGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

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
                'configured' => filled(config('services.stripe.secret')),
                'connected' => $business->stripe_account_id !== null,
                'charges_enabled' => $business->stripe_charges_enabled,
            ],
            'canUpdate' => $request->user()->can('update', $business),
        ]);
    }

    /**
     * Send the user to Stripe to create or finish setting up their connected account.
     */
    public function connect(Request $request, StripeGateway $stripe): HttpResponse
    {
        $business = $request->user()->currentBusiness;

        Gate::authorize('update', $business);
        abort_unless(filled(config('services.stripe.secret')), 503, 'Stripe is not configured.');

        if (! $business->stripe_account_id) {
            $business->forceFill([
                'stripe_account_id' => $stripe->createAccount($business->email ?? $request->user()->email, $business->name),
            ])->save();
        }

        return Inertia::location($stripe->onboardingUrl(
            $business->stripe_account_id,
            route('payments.connect.refresh'),
            route('payments.connect.return'),
        ));
    }

    /**
     * Stripe sends the user here after onboarding; check whether payments are switched on.
     */
    public function return(Request $request, StripeGateway $stripe): RedirectResponse
    {
        $business = $request->user()->currentBusiness;

        if ($business->stripe_account_id) {
            $business->forceFill(['stripe_charges_enabled' => $stripe->chargesEnabled($business->stripe_account_id)])->save();
        }

        return to_route('payments.edit');
    }

    /**
     * Onboarding links expire; Stripe sends the user here to get a fresh one.
     */
    public function refresh(Request $request, StripeGateway $stripe): HttpResponse
    {
        return $this->connect($request, $stripe);
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
