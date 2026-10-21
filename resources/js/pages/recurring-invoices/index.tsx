import PageHeader from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Recurring invoices', href: '/recurring-invoices' }];

interface Schedule {
    id: number;
    client_name: string | null;
    template: { id: number; number: string };
    total: number;
    currency: string;
    frequency: string;
    next_issue_date: string;
    ends_on: string | null;
    auto_send: boolean;
    is_active: boolean;
    invoices_count: number;
}

export default function RecurringInvoices({ schedules }: { schedules: Schedule[] }) {
    const toggle = (schedule: Schedule) => {
        router.patch(route('recurring-invoices.update', schedule.id), { is_active: !schedule.is_active }, { preserveScroll: true });
    };

    const destroy = (schedule: Schedule) => {
        if (confirm('Stop this schedule? Invoices it already created are kept.')) {
            router.delete(route('recurring-invoices.destroy', schedule.id), { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Recurring invoices" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Recurring invoices"
                    description="Invoices that are created for you on a schedule. Open any invoice and choose Repeat to add one."
                />

                {schedules.length === 0 ? (
                    <div className="text-muted-foreground rounded-lg border border-dashed p-10 text-center text-sm">
                        No recurring invoices yet.{' '}
                        <Link href={route('invoices.index')} className="underline">
                            Pick an invoice to repeat
                        </Link>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left">
                                <tr>
                                    <th className="px-4 py-2 font-medium">Client</th>
                                    <th className="px-4 py-2 font-medium">Schedule</th>
                                    <th className="hidden px-4 py-2 font-medium md:table-cell">Next invoice</th>
                                    <th className="px-4 py-2 text-right font-medium">Amount</th>
                                    <th className="px-4 py-2" />
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {schedules.map((schedule) => (
                                    <tr key={schedule.id}>
                                        <td className="px-4 py-3">
                                            <span className="font-medium">{schedule.client_name}</span>
                                            <span className="text-muted-foreground block text-xs">
                                                Copies{' '}
                                                <Link href={route('invoices.show', schedule.template.id)} className="underline">
                                                    {schedule.template.number}
                                                </Link>{' '}
                                                · {schedule.invoices_count} created
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            {schedule.frequency}
                                            {schedule.auto_send && <span className="text-muted-foreground block text-xs">Emailed automatically</span>}
                                        </td>
                                        <td className="hidden px-4 py-3 md:table-cell">
                                            {schedule.is_active ? formatDate(schedule.next_issue_date) : <Badge variant="secondary">Paused</Badge>}
                                            {schedule.ends_on && (
                                                <span className="text-muted-foreground block text-xs">Ends {formatDate(schedule.ends_on)}</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">{formatMoney(schedule.total, schedule.currency)}</td>
                                        <td className="px-4 py-3 text-right whitespace-nowrap">
                                            <Button variant="ghost" size="sm" onClick={() => toggle(schedule)}>
                                                {schedule.is_active ? 'Pause' : 'Resume'}
                                            </Button>
                                            <Button variant="ghost" size="sm" className="text-destructive" onClick={() => destroy(schedule)}>
                                                Stop
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
