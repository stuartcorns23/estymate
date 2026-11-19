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
            'taxRates' => $business->documentTaxRates()->orderByDesc('rate')->get(['id', 'name', 'rate', 'is_default']),
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

        $validated = $request->validated();

        // Store bank details in the form they're printed: 60-83-71, GB03SRLG..., SRLGGB2L.
        if (! empty($validated['bank_sort_code'])) {
            $digits = preg_replace('/\D/', '', $validated['bank_sort_code']);
            $validated['bank_sort_code'] = implode('-', str_split($digits, 2));
        }
        foreach (['bank_iban', 'bank_bic'] as $field) {
            if (! empty($validated[$field])) {
                $validated[$field] = strtoupper(preg_replace('/\s+/', '', $validated[$field]));
            }
        }

        $business->update($validated);

        return to_route('business.edit');
    }
}
