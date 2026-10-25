<?php

namespace App\Http\Controllers\Settings;

use App\Billing\Plan;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class BillingController extends Controller
{
    /**
     * Show the business's plan, usage and upgrade options.
     */
    public function edit(Request $request): Response
    {
        $business = $request->user()->currentBusiness;
        $subscription = $business->subscription();

        return Inertia::render('settings/billing', [
            'plan' => $business->plan()->toArray(),
            'usage' => $business->planUsage(),
            'plans' => array_map(fn (Plan $plan) => $plan->toArray(), Plan::all()),
            'subscription' => $subscription ? [
                'status' => $subscription->stripe_status,
                'on_grace_period' => $subscription->onGracePeriod(),
                'ends_at' => $subscription->ends_at?->toDateString(),
            ] : null,
            'trial_ends_at' => $business->isOnFreeTrial() ? $business->trial_ends_at->toDateString() : null,
            'comped' => $business->plan_override !== null,
            'stripeConfigured' => filled(config('cashier.secret')),
            'canManage' => $request->user()->can('update', $business),
        ]);
    }

    /**
     * Start a subscription to a plan through Stripe Checkout.
     */
    public function checkout(Request $request, string $plan): HttpResponse
    {
        $business = $request->user()->currentBusiness;

        Gate::authorize('update', $business);

        $plan = rescue(fn () => Plan::find($plan), report: false);
        abort_unless($plan?->stripePrice, 404);

        // Plan changes for existing subscribers happen in the billing portal, which handles proration.
        if ($business->subscribed()) {
            return $this->portal($request);
        }

        $subscription = $business->newSubscription('default', $plan->stripePrice)->allowPromotionCodes();
        $options = [
            'success_url' => route('billing.edit').'?subscribed=1',
            'cancel_url' => route('billing.edit'),
        ];

        // Stripe Tax needs the customer's address to work out VAT, and UK businesses can add their VAT number.
        if (config('plans.stripe_tax')) {
            $subscription->collectTaxIds();
            $options['customer_update'] = ['address' => 'auto'];
        }

        $checkout = $subscription->checkout($options);

        return Inertia::location($checkout->url);
    }

    /**
     * Send the user to Stripe's billing portal to change plan, update their card or cancel.
     */
    public function portal(Request $request): HttpResponse
    {
        $business = $request->user()->currentBusiness;

        Gate::authorize('update', $business);

        return Inertia::location($business->billingPortalUrl(route('billing.edit')));
    }
}
