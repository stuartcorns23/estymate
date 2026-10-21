import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/money';
import { type PlanInfo, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

function limit(value: number | null, unit: string) {
    return value === null ? `Unlimited ${unit}` : `${value} ${unit}`;
}

export default function Welcome({ plans, trialDays }: { plans: PlanInfo[]; trialDays: number }) {
    const { auth } = usePage<SharedData>().props;

    return (
        <>
            <Head title="Estimates and invoices for UK businesses" />
            <div className="bg-background text-foreground flex min-h-screen flex-col">
                <header className="mx-auto flex w-full max-w-5xl items-center justify-between p-6">
                    <div className="flex items-center gap-2 font-semibold">
                        <AppLogoIcon className="size-6 fill-current" />
                        Estymate
                    </div>
                    <nav className="flex items-center gap-2">
                        {auth.user ? (
                            <Button asChild>
                                <Link href={route('dashboard')}>Dashboard</Link>
                            </Button>
                        ) : (
                            <>
                                <Button variant="ghost" asChild>
                                    <Link href={route('login')}>Log in</Link>
                                </Button>
                                <Button asChild>
                                    <Link href={route('register')}>Register</Link>
                                </Button>
                            </>
                        )}
                    </nav>
                </header>

                <main className="mx-auto flex w-full max-w-5xl flex-col justify-center gap-6 p-6 py-20">
                    <h1 className="max-w-2xl text-4xl font-semibold tracking-tight lg:text-5xl">Estimates and invoices for UK businesses</h1>
                    <p className="text-muted-foreground max-w-xl text-lg">
                        Send professional quotes, turn them into invoices and get paid, with UK VAT built in.
                    </p>
                    {!auth.user && (
                        <div className="flex flex-wrap items-center gap-3">
                            <Button size="lg" asChild>
                                <Link href={route('register')}>Start your free trial</Link>
                            </Button>
                            <span className="text-muted-foreground text-sm">{trialDays} days free, no card needed</span>
                        </div>
                    )}
                </main>

                <section className="mx-auto w-full max-w-5xl space-y-6 p-6 pb-16">
                    <h2 className="text-2xl font-semibold tracking-tight">Simple pricing</h2>
                    <div className="grid gap-4 sm:grid-cols-3">
                        {plans.map((plan) => (
                            <div key={plan.key} className="bg-card flex flex-col gap-3 rounded-xl border p-6">
                                <p className="font-medium">{plan.name}</p>
                                <p className="text-3xl font-semibold">
                                    {plan.price === 0 ? 'Free' : formatMoney(plan.price)}
                                    {plan.price > 0 && <span className="text-muted-foreground text-sm font-normal"> /month + VAT</span>}
                                </p>
                                <ul className="text-muted-foreground space-y-1 text-sm">
                                    <li>{limit(plan.limits.clients, 'clients')}</li>
                                    <li>{limit(plan.limits.invoices_per_month, 'invoices a month')}</li>
                                    <li>{limit(plan.limits.estimates_per_month, 'estimates a month')}</li>
                                    <li>{limit(plan.limits.team_members, plan.limits.team_members === 1 ? 'user' : 'users')}</li>
                                </ul>
                            </div>
                        ))}
                    </div>
                </section>
            </div>
        </>
    );
}
