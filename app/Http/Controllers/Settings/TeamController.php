<?php

namespace App\Http\Controllers\Settings;

use App\Enums\BusinessRole;
use App\Http\Controllers\Controller;
use App\Models\BusinessInvitation;
use App\Models\User;
use App\Notifications\BusinessInvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    /**
     * Show the current business's members and pending invitations.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $business = $user->currentBusiness;

        return Inertia::render('settings/team', [
            'members' => $business->users()->orderBy('name')->get()->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->pivot->role,
                'canRemove' => $user->can('removeMember', [$business, $member]),
            ]),
            'invitations' => $business->invitations()->latest()->get(['id', 'email', 'role']),
            'canManageTeam' => $user->can('manageTeam', $business),
        ]);
    }

    /**
     * Invite someone to the current business by email.
     */
    public function invite(Request $request): RedirectResponse
    {
        $business = $request->user()->currentBusiness;

        Gate::authorize('manageTeam', $business);

        $validated = $request->validate([
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique('business_invitations')->where('business_id', $business->id),
                function (string $attribute, mixed $value, \Closure $fail) use ($business) {
                    if ($business->users()->where('email', $value)->exists()) {
                        $fail('This person is already a member of your business.');
                    }
                },
            ],
            'role' => ['required', Rule::in(BusinessRole::invitable())],
        ], [
            'email.unique' => 'This person has already been invited.',
        ]);

        $invitation = $business->invitations()->create($validated);

        Notification::route('mail', $invitation->email)->notify(new BusinessInvitationNotification($invitation));

        return to_route('team.edit');
    }

    /**
     * Cancel a pending invitation.
     */
    public function cancelInvitation(Request $request, BusinessInvitation $invitation): RedirectResponse
    {
        $business = $request->user()->currentBusiness;

        abort_unless($invitation->business_id === $business->id, 404);
        Gate::authorize('manageTeam', $business);

        $invitation->delete();

        return to_route('team.edit');
    }

    /**
     * Remove a member from the current business.
     */
    public function removeMember(Request $request, User $member): RedirectResponse
    {
        $business = $request->user()->currentBusiness;

        Gate::authorize('removeMember', [$business, $member]);

        $business->users()->detach($member);

        if ($member->current_business_id === $business->id) {
            $member->forceFill(['current_business_id' => $member->businesses()->value('businesses.id')])->save();
        }

        return to_route('team.edit');
    }
}
