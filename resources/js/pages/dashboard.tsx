import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

interface Row {
    id: number;
    number: string;
    client_name: string | null;
    date: string;
    amount: number;
    currency: string;
}

interface DashboardProps {
    totals: { outstanding: number; overdue: number; paid_this_month: number; drafts: number };
    overdueInvoices: Row[];
    openEstimates: Row[];
    usage: { clients: number; estimates_this_month: number; invoices_this_month: number };
}

function Stat({ label, value, href, tone }: { label: string; value: string; href: string; tone?: 'danger' }) {
    return (
        <Link href={href} className="bg-card hover:bg-muted/40 rounded-xl border p-5 transition-colors">
            <p className="text-muted-foreground text-sm">{label}</p>
            <p className={`mt-1 text-2xl font-semibold tabular-nums ${tone === 'danger' ? 'text-red-600 dark:text-red-400' : ''}`}>{value}</p>
        </Link>
    );
}

function List({ title, rows, empty, routeName, dateLabel }: { title: string; rows: Row[]; empty: string; routeName: string; dateLabel: string }) {
    return (
        <section className="bg-card rounded-xl border">
            <h2 className="border-b px-5 py-3 font-medium">{title}</h2>
            {rows.length === 0 ? (
                <p className="text-muted-foreground px-5 py-6 text-sm">{empty}</p>
            ) : (
                <ul className="divide-y">
                    {rows.map((row) => (
                        <li key={row.id}>
                            <Link
                                href={route(routeName, row.id)}
                                className="hover:bg-muted/40 flex items-center justify-between gap-4 px-5 py-3 text-sm"
                            >
                                <span className="min-w-0">
                                    <span className="font-medium">{row.number}</span> <span className="text-muted-foreground">{row.client_name}</span>
                                    <span className="text-muted-foreground block text-xs">
                                        {dateLabel} {formatDate(row.date)}
                                    </span>
                                </span>
                                <span className="tabular-nums">{formatMoney(row.amount, row.currency)}</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

export default function Dashboard({ totals, overdueInvoices, openEstimates, usage }: DashboardProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="space-y-6 p-4">
                <PageHeader
                    title="Dashboard"
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={route('estimates.create')}>
                                    <Plus /> Estimate
                                </Link>
                            </Button>
                            <Button asChild>
                                <Link href={route('invoices.create')}>
                                    <Plus /> Invoice
                                </Link>
                            </Button>
                        </>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat label="Outstanding" value={formatMoney(totals.outstanding)} href={route('invoices.index', { status: 'sent' })} />
                    <Stat
                        label="Overdue"
                        value={formatMoney(totals.overdue)}
                        href={route('invoices.index', { status: 'overdue' })}
                        tone={totals.overdue > 0 ? 'danger' : undefined}
                    />
                    <Stat label="Paid this month" value={formatMoney(totals.paid_this_month)} href={route('invoices.index', { status: 'paid' })} />
                    <Stat label="Draft invoices" value={String(totals.drafts)} href={route('invoices.index', { status: 'draft' })} />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <List title="Overdue invoices" rows={overdueInvoices} empty="Nothing overdue. Nice." routeName="invoices.show" dateLabel="Due" />
                    <List
                        title="Estimates awaiting action"
                        rows={openEstimates}
                        empty="No sent or accepted estimates waiting to be invoiced."
                        routeName="estimates.show"
                        dateLabel="Valid until"
                    />
                </div>

                <p className="text-muted-foreground text-xs">
                    This month: {usage.invoices_this_month} invoices and {usage.estimates_this_month} estimates created · {usage.clients} clients
                </p>
            </div>
        </AppLayout>
    );
}
