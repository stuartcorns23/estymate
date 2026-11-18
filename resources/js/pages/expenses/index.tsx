import PageHeader from '@/components/page-header';
import Pagination from '@/components/pagination';
import PeriodFilter, { periodParam } from '@/components/period-filter';
import SearchInput from '@/components/search-input';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney } from '@/lib/money';
import { type BreadcrumbItem, type Expense, type Option, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Paperclip, Plus } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Expenses', href: '/expenses' }];

interface ExpensesIndexProps {
    expenses: Paginated<Expense>;
    totals: { total: number; vat: number };
    filters: { search: string; category: string; period: string };
    categories: Option[];
    periods: Option[];
}

export default function ExpensesIndex({ expenses, totals, filters, categories, periods }: ExpensesIndexProps) {
    const labels = Object.fromEntries(categories.map((category) => [category.value, category.label]));
    const period = periodParam(filters.period, 'all');
    const filtered = Boolean(filters.search || filters.category || period);

    const filterCategory = (category: string) => {
        router.get(
            route('expenses.index'),
            { search: filters.search || undefined, category: category === 'all' ? undefined : category, period },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Expenses" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Expenses"
                    description="Purchase invoices, bills and receipts from your suppliers"
                    actions={
                        <Button asChild>
                            <Link href={route('expenses.create')}>
                                <Plus /> Add expense
                            </Link>
                        </Button>
                    }
                />

                <div className="flex flex-wrap items-center gap-3">
                    <SearchInput
                        value={filters.search}
                        placeholder="Search suppliers or references"
                        params={{ ...(filters.category ? { category: filters.category } : {}), ...(period ? { period } : {}) }}
                    />
                    <PeriodFilter
                        value={filters.period}
                        periods={periods}
                        defaultValue="all"
                        params={{ search: filters.search || undefined, category: filters.category || undefined }}
                    />
                    <Select value={filters.category || 'all'} onValueChange={filterCategory}>
                        <SelectTrigger className="w-56" aria-label="Category">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All categories</SelectItem>
                            {categories.map((category) => (
                                <SelectItem key={category.value} value={category.value}>
                                    {category.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {expenses.total > 0 && (
                        <p className="text-muted-foreground ml-auto text-sm">
                            {filtered ? 'Matching' : 'All'} expenses:{' '}
                            <span className="text-foreground font-medium tabular-nums">{formatMoney(totals.total)}</span> incl.{' '}
                            {formatMoney(totals.vat)} VAT
                        </p>
                    )}
                </div>

                {expenses.data.length === 0 ? (
                    <div className="text-muted-foreground rounded-lg border border-dashed p-10 text-center text-sm">
                        {filtered ? 'No expenses match.' : 'No expenses yet. Add the bills and receipts you pay to see where your money goes.'}
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left">
                                <tr>
                                    <th className="px-4 py-2 font-medium">Date</th>
                                    <th className="px-4 py-2 font-medium">Supplier</th>
                                    <th className="hidden px-4 py-2 font-medium md:table-cell">Category</th>
                                    <th className="hidden px-4 py-2 text-right font-medium sm:table-cell">VAT</th>
                                    <th className="px-4 py-2 text-right font-medium">Total</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {expenses.data.map((expense) => (
                                    <tr key={expense.id} className="hover:bg-muted/30">
                                        <td className="px-4 py-3 whitespace-nowrap">{formatDate(expense.date)}</td>
                                        <td className="px-4 py-3">
                                            <Link href={route('expenses.edit', expense.id)} className="font-medium hover:underline">
                                                {expense.supplier}
                                            </Link>
                                            {expense.has_attachment && (
                                                <Paperclip className="text-muted-foreground ml-1.5 inline size-3.5" aria-label="Has attachment" />
                                            )}
                                            {(expense.reference || expense.description) && (
                                                <p className="text-muted-foreground line-clamp-1">
                                                    {[expense.reference, expense.description].filter(Boolean).join(' · ')}
                                                </p>
                                            )}
                                        </td>
                                        <td className="hidden px-4 py-3 md:table-cell">{labels[expense.category] ?? expense.category}</td>
                                        <td className="hidden px-4 py-3 text-right tabular-nums sm:table-cell">
                                            {formatMoney(expense.vat_amount, expense.currency)}
                                        </td>
                                        <td className="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                                            {formatMoney(expense.total, expense.currency)}
                                            {!expense.paid_on && <span className="text-muted-foreground block text-xs">Unpaid</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <Pagination data={expenses} />
            </div>
        </AppLayout>
    );
}
