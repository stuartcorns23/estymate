import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { formatDate, formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type PlanInfo, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Billing',
        href: '/settings/billing',
    },
];

const limitLabels: Record<keyof PlanInfo['limits'], string> = {
    clients: 'Clients',
    invoices_per_month: 'Invoices this month',
    estimates_per_month: 'Estimates this month',
    team_members: 'Team members',
};

interface BillingProps {
    plan: PlanInfo;
    usage: Record<keyof PlanInfo['limits'], number>;
    plans: PlanInfo[];
    subscription: { status: string; interval: Interval; on_grace_period: boolean; ends_at: string | null } | null;
    trial_ends_at: string | null;
    comped: boolean;
    stripeConfigured: boolean;
    canManage: boolean;
}

type Interval = 'month' | 'year';

function limitText(limit: number | null, unit: string) {
    return limit === null ? `Unlimited ${unit}` : `${limit} ${unit}`;
}

export default function Billing({ plan, usage, plans, subscription, trial_ends_at, comped, stripeConfigured, canManage }: BillingProps) {
    const { flash } = usePage<SharedData & { flash?: { status?: string } }>().props;
    const subscribed = subscription !== null && subscription.status !== 'canceled';
    const offersYearly = plans.some((option) => option.yearly_purchasable);
    const [interval, setBillingInterval] = useState<Interval>(subscription?.interval ?? 'month');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Billing" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title={`You're on the ${plan.name} plan`} description={describe()} />

                    {flash?.status && (
                        <p className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
                            {flash.status}
                        </p>
                    )}

                    <ul className="divide-y rounded-lg border">
                        {(Object.keys(limitLabels) as (keyof PlanInfo['limits'])[]).map((key) => {
                            const limit = plan.limits[key];
                            const percent = limit ? Math.min(100, (usage[key] / limit) * 100) : 0;

                            return (
                                <li key={key} className="space-y-2 px-4 py-3 text-sm">
                                    <div className="flex justify-between">
                                        <span>{limitLabels[key]}</span>
                                        <span className="text-muted-foreground tabular-nums">
                                            {usage[key]} of {limit ?? 'unlimited'}
                                        </span>
                                    </div>
                                    {limit !== null && (
                                        <div className="bg-muted h-1.5 overflow-hidden rounded-full">
                                            <div
                                                className={cn('h-full rounded-full', percent >= 100 ? 'bg-red-500' : 'bg-primary')}
                                                style={{ width: `${percent}%` }}
                                            />
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>

                    {canManage && subscribed && stripeConfigured && (
                        <Button variant="outline" onClick={() => router.post(route('billing.portal'))}>
                            Manage subscription and card
                        </Button>
                    )}
                </div>

                {canManage && !comped && (
                    <div className="space-y-6">
                        <div className="flex flex-wrap items-end justify-between gap-4">
                            <HeadingSmall title="Plans" description="Prices exclude VAT. Change or cancel at any time." />
                            {offersYearly && (
                                <div className="inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800">
                                    {(['month', 'year'] as const).map((value) => (
                                        <button
                                            key={value}
                                            onClick={() => setBillingInterval(value)}
                                            className={cn(
                                                'rounded-md px-3.5 py-1.5 text-sm transition-colors',
                                                interval === value
                                                    ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                                                    : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                                            )}
                                        >
                                            {value === 'month' ? 'Monthly' : 'Yearly'}
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>
                        <div className="grid gap-4 sm:grid-cols-3">
                            {plans.map((option) => {
                                const yearly = interval === 'year' && option.yearly_purchasable;
                                const price = yearly ? option.yearly_price : option.price;
                                const saving = option.price * 12 - option.yearly_price;

                                return (
                                    <div
                                        key={option.key}
                                        className={cn('flex flex-col gap-3 rounded-lg border p-4', option.key === plan.key && 'border-primary')}
                                    >
                                        <div>
                                            <p className="font-medium">{option.name}</p>
                                            <p className="text-2xl font-semibold">
                                                {price === 0 ? 'Free' : formatMoney(price)}
                                                {price > 0 && (
                                                    <span className="text-muted-foreground text-sm font-normal"> /{yearly ? 'year' : 'month'}</span>
                                                )}
                                            </p>
                                            {yearly && saving > 0 && (
                                                <p className="text-xs text-green-700 dark:text-green-400">Save {formatMoney(saving)} a year</p>
                                            )}
                                        </div>
                                        <ul className="text-muted-foreground flex-1 space-y-1 text-sm">
                                            <li>{limitText(option.limits.clients, 'clients')}</li>
                                            <li>{limitText(option.limits.invoices_per_month, 'invoices a month')}</li>
                                            <li>{limitText(option.limits.estimates_per_month, 'estimates a month')}</li>
                                            <li>{limitText(option.limits.team_members, option.limits.team_members === 1 ? 'user' : 'users')}</li>
                                        </ul>
                                        {option.key === plan.key && !trial_ends_at ? (
                                            <Button variant="secondary" disabled>
                                                Current plan
                                            </Button>
                                        ) : option.purchasable && stripeConfigured ? (
                                            <Button
                                                onClick={() =>
                                                    router.post(route('billing.checkout', option.key), { interval: yearly ? 'year' : 'month' })
                                                }
                                            >
                                                {subscribed ? 'Switch' : 'Choose'} {option.name}
                                            </Button>
                                        ) : null}
                                    </div>
                                );
                            })}
                        </div>
                        {!stripeConfigured && (
                            <p className="text-muted-foreground text-xs">
                                Subscriptions aren't set up on this server yet. Add the Stripe keys and price ids to turn them on.
                            </p>
                        )}
                    </div>
                )}
            </SettingsLayout>
        </AppLayout>
    );

    function describe() {
        if (comped) {
            return 'Provided free of charge.';
        }

        if (trial_ends_at) {
            return `Free trial until ${formatDate(trial_ends_at)}. Choose a plan to keep these limits afterwards.`;
        }

        if (subscription?.on_grace_period && subscription.ends_at) {
            return `Cancelled. You keep this plan until ${formatDate(subscription.ends_at)}.`;
        }

        if (subscription?.status === 'past_due') {
            return 'Your last payment failed. Update your card to keep your plan.';
        }

        return subscribed ? 'Thanks for subscribing.' : 'Upgrade any time for higher limits.';
    }
}
