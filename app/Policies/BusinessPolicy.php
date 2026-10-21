<?php

namespace App\Policies;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;

class BusinessPolicy
{
    /**
     * Owners and admins can change business details.
     */
    public function update(User $user, Business $business): bool
    {
        return (bool) $user->roleIn($business)?->canManage();
    }

    /**
     * Owners and admins can invite and remove team members.
     */
    public function manageTeam(User $user, Business $business): bool
    {
        return (bool) $user->roleIn($business)?->canManage();
    }

    /**
     * Owners can't be removed, and admins can't remove other admins.
     */
    public function removeMember(User $user, Business $business, User $member): bool
    {
        $memberRole = $member->roleIn($business);

        if ($memberRole === null || $memberRole === BusinessRole::Owner || $user->is($member)) {
            return false;
        }

        return match ($user->roleIn($business)) {
            BusinessRole::Owner => true,
            BusinessRole::Admin => $memberRole === BusinessRole::Member,
            default => false,
        };
    }
}
