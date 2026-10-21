<?php

namespace App\Http\Controllers;

use App\Models\BusinessInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvitationController extends Controller
{
    /**
     * Join the business the invitation is for.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = BusinessInvitation::where('token', $token)->firstOrFail();
        $user = $request->user();

        abort_unless(strcasecmp($invitation->email, $user->email) === 0, 403, 'This invitation was sent to a different email address.');

        DB::transaction(function () use ($invitation, $user) {
            $invitation->business->addUser($user, $invitation->role);
            $user->switchBusiness($invitation->business);
            $invitation->delete();
        });

        return to_route('dashboard');
    }
}
