<?php

namespace App\Http\Controllers;

use App\Models\Business;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CurrentBusinessController extends Controller
{
    /**
     * Switch the business the user is working in.
     */
    public function update(Request $request): RedirectResponse
    {
        $request->validate(['business_id' => ['required', 'integer']]);

        $business = Business::findOrFail($request->integer('business_id'));

        abort_unless($request->user()->switchBusiness($business), 403);

        return to_route('dashboard');
    }
}
