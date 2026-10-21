import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { CheckCircle2, CircleAlert } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Payment settings',
        href: '/settings/payments',
    },
];

interface PaymentsProps {
    stripe: { configured: boolean; connected: boolean; charges_enabled: boolean };
    canUpdate: boolean;
}

export default function Payments({ stripe, canUpdate }: PaymentsProps) {
    const connect = () => router.post(route('payments.connect'));
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
                            Online payments aren't set up on this server yet. Add the Stripe keys to the <code>.env</code> file to turn them on.
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

                    {stripe.configured && canUpdate && (
                        <div className="flex gap-2">
                            {!stripe.charges_enabled && (
                                <Button onClick={connect}>{stripe.connected ? 'Finish Stripe setup' : 'Connect Stripe'}</Button>
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
