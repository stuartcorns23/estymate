import { type BreadcrumbItem, type BusinessRole } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Team settings',
        href: '/settings/team',
    },
];

interface Member {
    id: number;
    name: string;
    email: string;
    role: BusinessRole;
    canRemove: boolean;
}

interface Invitation {
    id: number;
    email: string;
    role: BusinessRole;
}

interface TeamProps {
    members: Member[];
    invitations: Invitation[];
    canManageTeam: boolean;
}

export default function Team({ members, invitations, canManageTeam }: TeamProps) {
    const { data, setData, post, errors, processing, reset } = useForm({
        email: '',
        role: 'member',
    });

    const invite: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('team.invite'), {
            preserveScroll: true,
            onSuccess: () => reset('email'),
        });
    };

    const removeMember = (member: Member) => {
        if (confirm(`Remove ${member.name} from this business?`)) {
            router.delete(route('team.members.destroy', member.id), { preserveScroll: true });
        }
    };

    const cancelInvitation = (invitation: Invitation) => {
        router.delete(route('team.invitations.destroy', invitation.id), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Team settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Team members" description="People who can work in this business" />

                    <ul className="divide-y rounded-lg border">
                        {members.map((member) => (
                            <li key={member.id} className="flex items-center justify-between gap-4 px-4 py-3">
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-medium">{member.name}</p>
                                    <p className="text-muted-foreground truncate text-sm">{member.email}</p>
                                </div>
                                <div className="flex shrink-0 items-center gap-2">
                                    <Badge variant="secondary" className="capitalize">
                                        {member.role}
                                    </Badge>
                                    {member.canRemove && (
                                        <Button variant="ghost" size="sm" onClick={() => removeMember(member)}>
                                            Remove
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>

                {canManageTeam && (
                    <div className="space-y-6">
                        <HeadingSmall title="Invite someone" description="They'll get an email with a link to join" />

                        <form onSubmit={invite} className="space-y-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    required
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    placeholder="colleague@example.co.uk"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="role">Role</Label>
                                <Select value={data.role} onValueChange={(value) => setData('role', value)}>
                                    <SelectTrigger id="role">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="member">Member: creates clients, estimates and invoices</SelectItem>
                                        <SelectItem value="admin">Admin: also manages business settings and team</SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.role} />
                            </div>

                            <Button disabled={processing}>Send invitation</Button>
                        </form>

                        {invitations.length > 0 && (
                            <ul className="divide-y rounded-lg border">
                                {invitations.map((invitation) => (
                                    <li key={invitation.id} className="flex items-center justify-between gap-4 px-4 py-3">
                                        <div className="min-w-0">
                                            <p className="truncate text-sm">{invitation.email}</p>
                                            <p className="text-muted-foreground text-sm capitalize">Invited as {invitation.role}</p>
                                        </div>
                                        <Button variant="ghost" size="sm" onClick={() => cancelInvitation(invitation)}>
                                            Cancel
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}
            </SettingsLayout>
        </AppLayout>
    );
}
