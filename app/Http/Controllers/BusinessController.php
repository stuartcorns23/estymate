<?php

namespace App\Http\Controllers;

use App\Actions\CreateBusiness;
use App\Http\Requests\BusinessRequest;
use App\Models\BusinessInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BusinessController extends Controller
{
    /**
     * Show the page for setting up a new business.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('businesses/create', [
            'invitations' => BusinessInvitation::with('business:id,name')
                ->where('email', $request->user()->email)
                ->get()
                ->map(fn (BusinessInvitation $invitation) => [
                    'business' => $invitation->business->name,
                    'role' => $invitation->role,
                    'url' => route('invitations.accept', $invitation->token),
                ]),
        ]);
    }

    /**
     * Create a business owned by the current user.
     */
    public function store(BusinessRequest $request, CreateBusiness $createBusiness): RedirectResponse
    {
        $createBusiness->handle($request->user(), $request->validated());

        return to_route('dashboard');
    }
}
