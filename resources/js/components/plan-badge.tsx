import { SidebarGroup, useSidebar } from '@/components/ui/sidebar';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';

function daysUntil(date: string): number {
    return Math.max(0, Math.floor((new Date(`${date}T23:59:59`).getTime() - Date.now()) / 86_400_000));
}

export function PlanBadge() {
    const { auth } = usePage<SharedData>().props;
    const { state } = useSidebar();

    if (!auth.plan || state === 'collapsed') {
        return null;
    }

    const trialDays = auth.plan.trial_ends_at ? daysUntil(auth.plan.trial_ends_at) : null;

    return (
        <SidebarGroup className="px-2 py-0">
            <Link href={route('billing.edit')} className="hover:bg-sidebar-accent rounded-md border px-3 py-2 text-xs">
                <span className="font-medium">{auth.plan.name} plan</span>
                {trialDays !== null && (
                    <span className="text-muted-foreground block">
                        Free trial · {trialDays} {trialDays === 1 ? 'day' : 'days'} left
                    </span>
                )}
            </Link>
        </SidebarGroup>
    );
}
