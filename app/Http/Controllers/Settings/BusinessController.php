<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BusinessController extends Controller
{
    /**
     * Show the current business's settings page.
     */
    public function edit(Request $request): Response
    {
        $business = $request->user()->currentBusiness;

        return Inertia::render('settings/business', [
            'business' => $business,
            'taxRates' => $business->taxRates()->orderByDesc('rate')->get(['id', 'name', 'rate', 'is_default']),
            'canUpdate' => $request->user()->can('update', $business),
        ]);
    }

    /**
     * Update the current business's details.
     */
    public function update(BusinessRequest $request): RedirectResponse
    {
        $business = $request->user()->currentBusiness;

        Gate::authorize('update', $business);

        $business->update($request->validated());

        return to_route('business.edit');
    }
}
