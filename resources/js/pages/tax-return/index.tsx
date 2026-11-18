import PageHeader from '@/components/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney } from '@/lib/money';
import { type BreadcrumbItem, type Option } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Download, FileText, Info, Lock } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tax return', href: '/tax-return' }];

interface ExpenseBox {
    box: string;
    label: string;
    full_form_box: number;
    short_form_box: number;
    amount: number;
    categories: { label: string; amount: number; count: number }[];
}

interface TaxReport {
    tax_year: { start_year: number; label: string; period: string; filing_deadline: string; in_progress: boolean };
    basis: 'cash' | 'accruals';
    basis_label: string;
    vat_registered: boolean;
    currency: string;
    turnover: number;
    sales_count: number;
    credits: { count: number; amount: number };
    expense_boxes: ExpenseBox[];
    total_expenses: number;
    expense_count: number;
    net_profit: number;
    single_figure_allowed: boolean;
    under_trading_allowance: boolean;
    vat: { output: number; input: number; net: number } | null;
    quarters: { label: string; turnover: number; expenses: number; profit: number }[];
    unpaid_expenses: { count: number; total: number } | null;
}

interface TaxReturnProps {
    included: boolean;
    requiredPlan: string | null;
    report: TaxReport | null;
    years: Option[];
    bases: Option[];
}

export default function TaxReturn({ included, requiredPlan, report, years, bases }: TaxReturnProps) {
    if (!included || !report) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Tax return" />
                <div className="space-y-6 p-4">
                    <PageHeader title="Tax return figures" description="Figures for the self-employment pages of your Self Assessment" />
                    <div className="mx-auto max-w-lg rounded-lg border border-dashed p-10 text-center">
                        <Lock className="text-muted-foreground mx-auto mb-3 size-6" />
                        <h2 className="font-medium">Available on the {requiredPlan ?? 'Pro'} plan</h2>
                        <p className="text-muted-foreground mt-2 text-sm">
                            See your turnover, allowable expenses and profit for each UK tax year, sorted into the boxes HMRC asks for, and download
                            them for your accountant.
                        </p>
                        <Button asChild className="mt-5">
                            <Link href={route('billing.edit')}>Upgrade to {requiredPlan ?? 'Pro'}</Link>
                        </Button>
                    </div>
                </div>
            </AppLayout>
        );
    }

    const money = (pence: number) => formatMoney(pence, report.currency);
    const params = { year: report.tax_year.start_year, basis: report.basis };
    const change = (next: Partial<typeof params>) =>
        router.get(route('tax-return.index'), { ...params, ...next }, { preserveState: true, replace: true });
    const profit = report.net_profit >= 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tax return" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Tax return figures"
                    description={`${report.tax_year.label} tax year, ${report.tax_year.period}`}
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <a href={route('tax-return.csv', params)}>
                                    <Download /> CSV
                                </a>
                            </Button>
                            <Button variant="outline" asChild>
                                <a href={route('tax-return.pdf', params)}>
                                    <FileText /> PDF
                                </a>
                            </Button>
                        </>
                    }
                />

                <div className="flex flex-wrap items-center gap-3">
                    <Select value={String(report.tax_year.start_year)} onValueChange={(year) => change({ year: Number(year) })}>
                        <SelectTrigger className="w-40" aria-label="Tax year">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {years.map((year) => (
                                <SelectItem key={year.value} value={year.value}>
                                    {year.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select value={report.basis} onValueChange={(basis) => change({ basis: basis as TaxReport['basis'] })}>
                        <SelectTrigger className="w-64" aria-label="Accounting basis">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {bases.map((basis) => (
                                <SelectItem key={basis.value} value={basis.value}>
                                    {basis.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <p className="text-muted-foreground text-sm">
                        {report.basis === 'cash'
                            ? 'Money received and expenses paid in the year.'
                            : 'Invoices issued and expenses billed in the year.'}{' '}
                        {report.vat_registered ? 'Amounts exclude VAT.' : 'Expenses include the VAT you paid.'}
                    </p>
                </div>

                <Alert>
                    <Info className="size-4" />
                    <AlertTitle>A guide, not tax advice</AlertTitle>
                    <AlertDescription>
                        These figures come from the invoices, payments and expenses in Estymate. Check them, and adjust for anything that isn't here,
                        such as private use, cash takings or capital allowances, before you file.{' '}
                        {report.tax_year.in_progress
                            ? 'This tax year is still in progress.'
                            : `The online return for ${report.tax_year.label} is due by ${formatDate(report.tax_year.filing_deadline)}.`}
                    </AlertDescription>
                </Alert>

                <div className="grid gap-4 sm:grid-cols-3">
                    <Stat label="Turnover" value={money(report.turnover)} hint={`SA103F box 15 · SA103S box 9`} />
                    <Stat label="Allowable expenses" value={money(report.total_expenses)} hint="SA103F box 31 · SA103S box 20" />
                    <Stat
                        label={profit ? 'Net profit' : 'Net loss'}
                        value={money(Math.abs(report.net_profit))}
                        hint={profit ? 'SA103F box 47' : 'SA103F box 48'}
                    />
                </div>

                {report.unpaid_expenses && (
                    <p className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                        {report.unpaid_expenses.count} {report.unpaid_expenses.count === 1 ? 'expense' : 'expenses'} dated in this year (
                        {money(report.unpaid_expenses.total)}) {report.unpaid_expenses.count === 1 ? 'has' : 'have'} no "paid on" date, so the cash
                        basis leaves {report.unpaid_expenses.count === 1 ? 'it' : 'them'} out.{' '}
                        <Link href={route('expenses.index')} className="underline">
                            Review expenses
                        </Link>
                    </p>
                )}

                <section className="space-y-3">
                    <h2 className="font-medium">Self-employment (SA103)</h2>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left">
                                <tr>
                                    <th className="w-20 px-4 py-2 font-medium">Full form</th>
                                    <th className="hidden w-20 px-4 py-2 font-medium sm:table-cell">Short form</th>
                                    <th className="px-4 py-2 font-medium">Item</th>
                                    <th className="px-4 py-2 text-right font-medium">Amount</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                <tr>
                                    <td className="px-4 py-3 tabular-nums">15</td>
                                    <td className="hidden px-4 py-3 tabular-nums sm:table-cell">9</td>
                                    <td className="px-4 py-3">
                                        Turnover
                                        <p className="text-muted-foreground">
                                            {report.sales_count} {report.basis === 'cash' ? 'payments received' : 'invoices issued'}
                                            {report.credits.count > 0 &&
                                                `, less ${money(report.credits.amount)} from ${report.credits.count} ${
                                                    report.basis === 'cash'
                                                        ? report.credits.count === 1
                                                            ? 'refund'
                                                            : 'refunds'
                                                        : report.credits.count === 1
                                                          ? 'credit note'
                                                          : 'credit notes'
                                                }`}
                                        </p>
                                    </td>
                                    <td className="px-4 py-3 text-right font-medium tabular-nums">{money(report.turnover)}</td>
                                </tr>
                                {report.expense_boxes.map((box) => (
                                    <tr key={box.box}>
                                        <td className="px-4 py-3 tabular-nums">{box.full_form_box}</td>
                                        <td className="hidden px-4 py-3 tabular-nums sm:table-cell">{box.short_form_box}</td>
                                        <td className="px-4 py-3">
                                            {box.label}
                                            <p className="text-muted-foreground">
                                                {box.categories.map((category) => `${category.label} ${money(category.amount)}`).join(' · ')}
                                            </p>
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">{money(box.amount)}</td>
                                    </tr>
                                ))}
                                {report.expense_boxes.length === 0 && (
                                    <tr>
                                        <td colSpan={4} className="text-muted-foreground px-4 py-3">
                                            No expenses in this tax year.
                                        </td>
                                    </tr>
                                )}
                                <tr className="bg-muted/30">
                                    <td className="px-4 py-3 tabular-nums">31</td>
                                    <td className="hidden px-4 py-3 tabular-nums sm:table-cell">20</td>
                                    <td className="px-4 py-3 font-medium">Total allowable expenses</td>
                                    <td className="px-4 py-3 text-right font-medium tabular-nums">{money(report.total_expenses)}</td>
                                </tr>
                                <tr className="bg-muted/30">
                                    <td className="px-4 py-3 tabular-nums">{profit ? 47 : 48}</td>
                                    <td className="hidden px-4 py-3 sm:table-cell" />
                                    <td className="px-4 py-3 font-medium">{profit ? 'Net profit' : 'Net loss'}</td>
                                    <td className="px-4 py-3 text-right font-semibold tabular-nums">{money(Math.abs(report.net_profit))}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <ul className="text-muted-foreground list-disc space-y-1 pl-5 text-sm">
                        {report.single_figure_allowed && (
                            <li>Your turnover is under £90,000, so you can give your expenses as one total instead of box by box.</li>
                        )}
                        {report.under_trading_allowance && (
                            <li>
                                Your turnover is £1,000 or less. You may be able to use the trading allowance instead of claiming expenses, and you
                                might not need to declare this income at all.
                            </li>
                        )}
                        <li>Business entertainment isn't allowable, so leave client hospitality out of the advertising box.</li>
                        {report.basis === 'accruals' && (
                            <li>On the traditional basis, equipment is usually claimed through capital allowances rather than as an expense.</li>
                        )}
                    </ul>
                </section>

                {report.vat && (
                    <section className="space-y-3">
                        <h2 className="font-medium">VAT in this tax year</h2>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Stat label="VAT on sales" value={money(report.vat.output)} />
                            <Stat label="VAT on expenses" value={money(report.vat.input)} />
                            <Stat label={report.vat.net >= 0 ? 'Net VAT owed' : 'Net VAT to reclaim'} value={money(Math.abs(report.vat.net))} />
                        </div>
                        <p className="text-muted-foreground text-sm">
                            For reference only. Your VAT returns cover your own VAT quarters and are filed separately.
                        </p>
                    </section>
                )}

                <section className="space-y-3">
                    <h2 className="font-medium">By quarter</h2>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left">
                                <tr>
                                    <th className="px-4 py-2 font-medium">Quarter</th>
                                    <th className="px-4 py-2 text-right font-medium">Turnover</th>
                                    <th className="px-4 py-2 text-right font-medium">Expenses</th>
                                    <th className="px-4 py-2 text-right font-medium">Profit</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {report.quarters.map((quarter) => (
                                    <tr key={quarter.label}>
                                        <td className="px-4 py-3 whitespace-nowrap">{quarter.label}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{money(quarter.turnover)}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{money(quarter.expenses)}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{money(quarter.profit)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <p className="text-muted-foreground text-sm">
                        These are the standard Making Tax Digital update periods, for anyone sending quarterly updates to HMRC.
                    </p>
                </section>
            </div>
        </AppLayout>
    );
}

function Stat({ label, value, hint }: { label: string; value: string; hint?: string }) {
    return (
        <div className="rounded-lg border p-4">
            <p className="text-muted-foreground text-sm">{label}</p>
            <p className="mt-1 text-2xl font-semibold tabular-nums">{value}</p>
            {hint && <p className="text-muted-foreground mt-1 text-xs">{hint}</p>}
        </div>
    );
}
