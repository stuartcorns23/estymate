import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { loadConnectAndInitialize, type StripeConnectInstance } from '@stripe/connect-js';
import { ConnectAccountOnboarding, ConnectComponentsProvider, ConnectNotificationBanner } from '@stripe/react-connect-js';
import { CheckCircle2, CircleAlert, ExternalLink } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Payment settings',
        href: '/settings/payments',
    },
];

interface PaymentsProps {
    stripe: {
        configured: boolean;
        connected: boolean;
        charges_enabled: boolean;
        publishable_key: string | null;
        dashboard_url: string | null;
    };
    canUpdate: boolean;
}

/**
 * Laravel sets the XSRF-TOKEN cookie on every response; send it back on our own fetch calls.
 */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function initializeConnect(publishableKey: string): StripeConnectInstance {
    const dark = document.documentElement.classList.contains('dark');

    return loadConnectAndInitialize({
        publishableKey,
        fetchClientSecret: async () => {
            const response = await fetch(route('payments.session'), {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            });

            if (!response.ok) {
                throw new Error('Could not start a Stripe session.');
            }

            return (await response.json()).client_secret;
        },
        appearance: {
            variables: {
                colorPrimary: dark ? '#fafafa' : '#171717',
                colorBackground: dark ? '#0a0a0a' : '#ffffff',
                colorText: dark ? '#fafafa' : '#0a0a0a',
                borderRadius: '8px',
                fontFamily: 'Instrument Sans, ui-sans-serif, system-ui, sans-serif',
            },
        },
    });
}

export default function Payments({ stripe, canUpdate }: PaymentsProps) {
    const embedded = stripe.connected && canUpdate && stripe.publishable_key !== null;
    const [connectInstance] = useState(() => (embedded ? initializeConnect(stripe.publishable_key!) : null));

    const connect = () => router.post(route('payments.connect'));
    const sync = () => router.post(route('payments.sync'), {}, { preserveScroll: true });
    const disconnect = () => {
        if (confirm('Stop taking card payments? Your Stripe account itself is not closed.')) {
            router.delete(route('payments.disconnect'));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payment settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Online card payments"
                        description="Connect Stripe so clients can pay invoices by card. Money goes straight to your own Stripe account and bank."
                    />

                    {!stripe.configured ? (
                        <p className="text-muted-foreground rounded-lg border p-4 text-sm">
                            Online payments aren't set up on this server yet. Add <code>STRIPE_KEY</code> and <code>STRIPE_SECRET</code> to the{' '}
                            <code>.env</code> file to turn them on.
                        </p>
                    ) : stripe.charges_enabled ? (
                        <div className="flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-900 dark:border-green-900 dark:bg-green-950 dark:text-green-100">
                            <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
                            <p>Stripe is connected. Sent invoices show a "Pay now" button, and payments are recorded automatically.</p>
                        </div>
                    ) : stripe.connected ? (
                        <div className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
                            <CircleAlert className="mt-0.5 size-4 shrink-0" />
                            <p>Your Stripe account needs a few more details before it can take payments.</p>
                        </div>
                    ) : null}

                    {stripe.configured && connectInstance && (
                        <ConnectComponentsProvider connectInstance={connectInstance}>
                            {/* Stripe keeps asking for details as requirements change; the banner surfaces those requests. */}
                            <ConnectNotificationBanner />
                            {!stripe.charges_enabled && <ConnectAccountOnboarding onExit={sync} />}
                        </ConnectComponentsProvider>
                    )}

                    {stripe.configured && canUpdate && (
                        <div className="flex flex-wrap gap-2">
                            {!stripe.connected && <Button onClick={connect}>Connect Stripe</Button>}
                            {stripe.connected && !stripe.charges_enabled && (
                                <Button variant="outline" onClick={sync}>
                                    Check status
                                </Button>
                            )}
                            {stripe.dashboard_url && (
                                <Button variant="outline" asChild>
                                    <a href={stripe.dashboard_url} target="_blank" rel="noreferrer">
                                        Open Stripe Dashboard <ExternalLink className="size-4" />
                                    </a>
                                </Button>
                            )}
                            {stripe.connected && (
                                <Button variant="ghost" className="text-destructive" onClick={disconnect}>
                                    Disconnect
                                </Button>
                            )}
                        </div>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
