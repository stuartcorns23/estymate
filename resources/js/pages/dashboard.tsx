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
    totals: { outstanding: number; overdue: number; paid_this_month: number; drafts: number; spent_this_month: number };
    cashflow: { month: string; income: number; expenses: number }[];
    expenseCategories: { category: string; label: string; total: number }[];
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

// Two series: money in and money out. Text stays in the text colours; only the marks carry these.
// Brand chart steps from app.css (electric blue / turquoise), each with its own light and dark value.
const incomeColor = 'bg-chart-1';
const expenseColor = 'bg-chart-2';

function monthLabel(month: string, withYear = false): string {
    return new Date(`${month}-01T00:00:00`).toLocaleDateString('en-GB', withYear ? { month: 'long', year: 'numeric' } : { month: 'short' });
}

function Cashflow({ months }: { months: DashboardProps['cashflow'] }) {
    const max = Math.max(1, ...months.map((month) => Math.max(month.income, month.expenses)));
    const totalIn = months.reduce((sum, month) => sum + month.income, 0);
    const totalOut = months.reduce((sum, month) => sum + month.expenses, 0);

    return (
        <section className="bg-card rounded-xl border">
            <div className="flex flex-wrap items-baseline justify-between gap-2 border-b px-5 py-3">
                <h2 className="font-medium">Money in and out, last 12 months</h2>
                <div className="text-muted-foreground flex gap-4 text-xs">
                    <span className="flex items-center gap-1.5">
                        <span className={`size-2.5 rounded-sm ${incomeColor}`} /> Payments received {formatMoney(totalIn)}
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className={`size-2.5 rounded-sm ${expenseColor}`} /> Expenses {formatMoney(totalOut)}
                    </span>
                </div>
            </div>
            <div className="px-5 pt-4 pb-3">
                <div
                    className="flex h-44 items-end gap-1 border-b sm:gap-2"
                    role="img"
                    aria-label="Payments received and expenses for each of the last 12 months"
                >
                    {months.map((month) => (
                        <div
                            key={month.month}
                            className="group hover:bg-muted/50 relative flex h-full flex-1 items-end justify-center gap-0.5 rounded-t"
                            title={`${monthLabel(month.month, true)}: in ${formatMoney(month.income)}, out ${formatMoney(month.expenses)}`}
                        >
                            <div className={`w-full max-w-3 rounded-t ${incomeColor}`} style={{ height: `${(month.income / max) * 100}%` }} />
                            <div className={`w-full max-w-3 rounded-t ${expenseColor}`} style={{ height: `${(month.expenses / max) * 100}%` }} />
                            <div className="bg-popover text-popover-foreground pointer-events-none absolute bottom-full z-10 mb-1 hidden rounded-md border px-2 py-1 text-xs whitespace-nowrap shadow group-hover:block">
                                <p className="font-medium">{monthLabel(month.month, true)}</p>
                                <p>In {formatMoney(month.income)}</p>
                                <p>Out {formatMoney(month.expenses)}</p>
                                <p className="text-muted-foreground">Net {formatMoney(month.income - month.expenses)}</p>
                            </div>
                        </div>
                    ))}
                </div>
                <div className="text-muted-foreground mt-1 flex gap-1 text-center text-[11px] sm:gap-2">
                    {months.map((month, index) => (
                        <span key={month.month} className={`flex-1 ${index % 2 ? 'hidden sm:block' : ''}`}>
                            {monthLabel(month.month)}
                        </span>
                    ))}
                </div>
            </div>
        </section>
    );
}

function ExpenseBreakdown({ categories }: { categories: DashboardProps['expenseCategories'] }) {
    const total = categories.reduce((sum, category) => sum + category.total, 0);
    const max = Math.max(1, ...categories.map((category) => category.total));

    return (
        <section className="bg-card rounded-xl border">
            <div className="flex items-baseline justify-between gap-2 border-b px-5 py-3">
                <h2 className="font-medium">Expenses by category, last 12 months</h2>
                <span className="text-muted-foreground text-sm tabular-nums">{formatMoney(total)}</span>
            </div>
            {categories.length === 0 ? (
                <p className="text-muted-foreground px-5 py-6 text-sm">
                    No expenses yet.{' '}
                    <Link href={route('expenses.create')} className="text-foreground underline">
                        Add one
                    </Link>{' '}
                    to see where your money goes.
                </p>
            ) : (
                <ul className="space-y-3 px-5 py-4">
                    {categories.map((category) => (
                        <li key={category.category}>
                            <Link href={route('expenses.index', { category: category.category })} className="group block text-sm">
                                <span className="flex justify-between gap-4">
                                    <span className="group-hover:underline">{category.label}</span>
                                    <span className="tabular-nums">
                                        {formatMoney(category.total)}{' '}
                                        <span className="text-muted-foreground">({Math.round((category.total / total) * 100)}%)</span>
                                    </span>
                                </span>
                                <span className="bg-muted mt-1 block h-2 rounded-full">
                                    <span
                                        className={`block h-2 rounded-full ${expenseColor}`}
                                        style={{ width: `${(category.total / max) * 100}%` }}
                                    />
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

export default function Dashboard({ totals, overdueInvoices, openEstimates, usage, cashflow, expenseCategories }: DashboardProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="space-y-6 p-4">
                <PageHeader
                    title="Dashboard"
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={route('expenses.create')}>
                                    <Plus /> Expense
                                </Link>
                            </Button>
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

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <Stat label="Outstanding" value={formatMoney(totals.outstanding)} href={route('invoices.index', { status: 'sent' })} />
                    <Stat
                        label="Overdue"
                        value={formatMoney(totals.overdue)}
                        href={route('invoices.index', { status: 'overdue' })}
                        tone={totals.overdue > 0 ? 'danger' : undefined}
                    />
                    <Stat label="Paid this month" value={formatMoney(totals.paid_this_month)} href={route('invoices.index', { status: 'paid' })} />
                    <Stat label="Draft invoices" value={String(totals.drafts)} href={route('invoices.index', { status: 'draft' })} />
                    <Stat label="Spent this month" value={formatMoney(totals.spent_this_month)} href={route('expenses.index')} />
                </div>

                <div className="grid gap-4 lg:grid-cols-5">
                    <div className="lg:col-span-3">
                        <Cashflow months={cashflow} />
                    </div>
                    <div className="lg:col-span-2">
                        <ExpenseBreakdown categories={expenseCategories} />
                    </div>
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
