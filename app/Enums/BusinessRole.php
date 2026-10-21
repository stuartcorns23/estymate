<?php

namespace App\Enums;

enum BusinessRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    /**
     * Whether this role can change business settings and manage the team.
     */
    public function canManage(): bool
    {
        return $this !== self::Member;
    }

    /**
     * Roles that can be given through an invitation.
     *
     * @return list<string>
     */
    public static function invitable(): array
    {
        return [self::Admin->value, self::Member->value];
    }
}
