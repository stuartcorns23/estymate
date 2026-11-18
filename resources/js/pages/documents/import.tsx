import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FileUp, LoaderCircle } from 'lucide-react';
import { ChangeEvent, useRef, useState } from 'react';

type RowStatus = 'ready' | 'warning' | 'duplicate' | 'invalid';

interface PreviewInvoice {
    key: string;
    rows: number[];
    status: RowStatus;
    messages: string[];
    number: string | null;
    client: string | null;
    new_client: boolean;
    new_client_key: string | null;
    issue_date: string | null;
    due_date: string | null;
    invoice_status: 'draft' | 'sent' | 'paid' | 'void' | null;
    currency: string | null;
    total: number | null;
    tax_total: number | null;
    amount_paid: number | null;
    payments: number;
    lines: number;
}

interface Preview {
    headers: string[];
    format: string | null;
    mapping: (string | null)[];
    samples: string[];
    invoices: PreviewInvoice[];
    plan: {
        name: string;
        invoice_limit: number | null;
        invoices_remaining: number | null;
        client_limit: number | null;
        clients_remaining: number | null;
    };
}

const SKIP = 'skip';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Invoices', href: '/invoices' },
    { title: 'Import', href: '/invoices/import' },
];

const statusStyles: Record<RowStatus, { label: string; className: string }> = {
    ready: { label: 'Ready', className: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300' },
    warning: { label: 'Check', className: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200' },
    duplicate: { label: 'Already added', className: 'bg-blue-100 text-blue-900 dark:bg-blue-950 dark:text-blue-200' },
    invalid: { label: "Can't import", className: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' },
};

const invoiceStatusLabels: Record<string, string> = { draft: 'Draft', sent: 'Unpaid', paid: 'Paid', void: 'Void' };

const importable = (invoice: PreviewInvoice) => invoice.status === 'ready' || invoice.status === 'warning';

async function readCsv(file: File): Promise<string> {
    const buffer = await file.arrayBuffer();

    try {
        return new TextDecoder('utf-8', { fatal: true }).decode(buffer);
    } catch {
        return new TextDecoder('windows-1252').decode(buffer);
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function money(pence: number | null, currency: string | null): string {
    if (pence === null) return '';

    try {
        return formatMoney(pence, currency ?? 'GBP');
    } catch {
        return `${currency ?? ''} ${(pence / 100).toFixed(2)}`;
    }
}

export default function InvoiceImport({ fields, maxBytes }: { fields: { value: string; label: string }[]; maxBytes: number }) {
    const [fileName, setFileName] = useState<string | null>(null);
    const [csv, setCsv] = useState<string | null>(null);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [mapping, setMapping] = useState<(string | null)[] | null>(null);
    const [chosen, setChosen] = useState<Set<string>>(new Set());
    const [reminders, setReminders] = useState(false);
    const [loading, setLoading] = useState(false);
    const [importing, setImporting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const request = useRef(0);

    const loadPreview = async (text: string, newMapping: (string | null)[] | null, resetChoice: boolean) => {
        const id = ++request.current;
        setLoading(true);
        setError(null);

        try {
            const response = await fetch(route('invoices.import.preview'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify({ csv: text, mapping: newMapping }),
            });
            const body = await response.json();

            if (id !== request.current) return;

            if (!response.ok) {
                setError(body.errors?.csv?.[0] ?? body.message ?? 'The file could not be read.');
                setPreview(null);

                return;
            }

            setPreview(body);
            setMapping(body.mapping);

            setChosen((previous) => {
                const selectable = (body.invoices as PreviewInvoice[]).filter(importable).map((invoice) => invoice.key);

                return new Set(resetChoice ? selectable : selectable.filter((key) => previous.has(key)));
            });
        } catch {
            if (id === request.current) setError('The file could not be checked. Try again.');
        } finally {
            if (id === request.current) setLoading(false);
        }
    };

    const chooseFile = async (event: ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        event.target.value = '';

        if (!file) return;

        if (file.size > maxBytes) {
            setError('The file is too big. Split it into files under 4 MB.');

            return;
        }

        const text = await readCsv(file);
        setFileName(file.name);
        setCsv(text);
        setPreview(null);
        setMapping(null);
        await loadPreview(text, null, true);
    };

    const changeMapping = (column: number, value: string) => {
        if (!mapping || !csv) return;

        const next = mapping.map((field, index) => (index === column ? (value === SKIP ? null : value) : field));
        setMapping(next);
        loadPreview(csv, next, false);
    };

    const invoices = preview?.invoices ?? [];
    const selectable = invoices.filter(importable);
    const counts = invoices.reduce<Record<RowStatus, number>>((totals, invoice) => ({ ...totals, [invoice.status]: totals[invoice.status] + 1 }), {
        ready: 0,
        warning: 0,
        duplicate: 0,
        invalid: 0,
    });

    // Work out what the plan's limits leave room for, in the order the invoices will be imported.
    const invoicesRemaining = preview?.plan.invoices_remaining ?? null;
    const clientsRemaining = preview?.plan.clients_remaining ?? null;
    const newClients = new Set<string>();
    let importCount = 0;
    let leftOut = 0;

    for (const invoice of invoices) {
        if (!chosen.has(invoice.key)) continue;

        const needsClient = invoice.new_client_key !== null && !newClients.has(invoice.new_client_key);

        if (
            (invoicesRemaining !== null && importCount >= invoicesRemaining) ||
            (needsClient && clientsRemaining !== null && newClients.size >= clientsRemaining)
        ) {
            leftOut++;
            continue;
        }

        if (needsClient && invoice.new_client_key) newClients.add(invoice.new_client_key);
        importCount++;
    }

    const toggle = (key: string, checked: boolean) => {
        setChosen((previous) => {
            const next = new Set(previous);

            if (checked) next.add(key);
            else next.delete(key);

            return next;
        });
    };

    const toggleAll = (checked: boolean) => setChosen(checked ? new Set(selectable.map((invoice) => invoice.key)) : new Set());

    const submit = () => {
        if (!csv || !mapping) return;

        router.post(
            route('invoices.import.store'),
            { csv, mapping, reminders, invoices: invoices.filter((invoice) => chosen.has(invoice.key)).map((invoice) => invoice.key) },
            {
                onStart: () => setImporting(true),
                onFinish: () => setImporting(false),
                onError: (errors) => setError(Object.values(errors)[0] ?? 'The import failed.'),
            },
        );
    };

    const missing = mapping
        ? [
              !mapping.includes('number') && 'the invoice number',
              !mapping.includes('client_name') && 'the client name',
              !mapping.includes('issue_date') && 'the issue date',
          ].filter(Boolean)
        : [];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Import invoices" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Import invoices"
                    description="Bring in past and unpaid invoices from a CSV file, such as an export from Billdu or another billing app"
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={route('invoices.index')}>Cancel</Link>
                        </Button>
                    }
                />

                <div className="flex flex-wrap items-center gap-4 rounded-lg border border-dashed p-6">
                    <Label
                        htmlFor="csv"
                        className="bg-primary text-primary-foreground hover:bg-primary/90 inline-flex cursor-pointer items-center gap-2 rounded-md px-4 py-2 text-sm font-medium"
                    >
                        <FileUp className="size-4" /> {fileName ? 'Choose another file' : 'Choose a CSV file'}
                    </Label>
                    <input id="csv" type="file" accept=".csv,text/csv,text/plain" className="sr-only" onChange={chooseFile} />
                    <p className="text-muted-foreground text-sm">
                        {fileName ? (
                            <>
                                <span className="text-foreground font-medium">{fileName}</span>
                                {preview && ` · ${invoices.length} ${invoices.length === 1 ? 'invoice' : 'invoices'}`}
                                {preview?.format && ` · recognised as a ${preview.format} export`}
                            </>
                        ) : (
                            'One row per invoice, or one row per line item with the invoice number on each. Nothing is saved until you press Import.'
                        )}
                    </p>
                    {loading && <LoaderCircle className="text-muted-foreground size-4 animate-spin" />}
                </div>

                <InputError message={error ?? (missing.length > 0 ? `Choose which column holds ${missing.join(', ')}.` : undefined)} />

                {preview && mapping && (
                    <>
                        <section className="space-y-3">
                            <div>
                                <h2 className="font-semibold">Match the columns</h2>
                                <p className="text-muted-foreground text-sm">
                                    {preview.format
                                        ? `We've matched the ${preview.format} columns for you. Check them, then the invoices below.`
                                        : 'Choose the invoice field for each column in your file. Columns you leave out are not imported.'}
                                </p>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {preview.headers.map((header, column) => (
                                    <div key={column} className="space-y-1">
                                        <Label htmlFor={`column-${column}`} className="truncate">
                                            {header || `Column ${column + 1}`}
                                            {preview.samples[column] && (
                                                <span className="text-muted-foreground font-normal"> e.g. {preview.samples[column]}</span>
                                            )}
                                        </Label>
                                        <Select value={mapping[column] ?? SKIP} onValueChange={(value) => changeMapping(column, value)}>
                                            <SelectTrigger id={`column-${column}`}>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value={SKIP}>Don't import</SelectItem>
                                                {fields.map((field) => (
                                                    <SelectItem key={field.value} value={field.value}>
                                                        {field.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                ))}
                            </div>
                        </section>

                        <section className="space-y-3">
                            <div>
                                <h2 className="font-semibold">Check the invoices</h2>
                                <p className="text-muted-foreground text-sm">
                                    {counts.ready + counts.warning} ready to import
                                    {counts.warning > 0 && ` (${counts.warning} to check)`}
                                    {counts.duplicate > 0 && `, ${counts.duplicate} already in your invoices`}
                                    {counts.invalid > 0 && `, ${counts.invalid} can't be imported`}. Clients are matched by name or email, and new
                                    ones are added for the rest.
                                </p>
                            </div>

                            {leftOut > 0 && (
                                <p className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
                                    The {preview.plan.name} plan has room for{' '}
                                    {invoicesRemaining !== null && `${invoicesRemaining} more invoices this month`}
                                    {invoicesRemaining !== null && clientsRemaining !== null && ' and '}
                                    {clientsRemaining !== null && `${clientsRemaining} more clients`}, so {leftOut} of the ticked invoices will be
                                    left out.{' '}
                                    <Link href={route('billing.edit')} className="font-medium underline">
                                        Upgrade your plan
                                    </Link>{' '}
                                    to import them all.
                                </p>
                            )}

                            <div className="overflow-x-auto rounded-lg border">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-muted-foreground text-left">
                                        <tr>
                                            <th className="w-10 px-3 py-2">
                                                <Checkbox
                                                    aria-label="Tick all"
                                                    checked={selectable.length > 0 && chosen.size === selectable.length}
                                                    onCheckedChange={(checked) => toggleAll(checked === true)}
                                                />
                                            </th>
                                            <th className="px-3 py-2 font-medium">Number</th>
                                            <th className="px-3 py-2 font-medium">Check</th>
                                            <th className="px-3 py-2 font-medium">Client</th>
                                            <th className="px-3 py-2 font-medium whitespace-nowrap">Issued</th>
                                            <th className="px-3 py-2 font-medium whitespace-nowrap">Due</th>
                                            <th className="px-3 py-2 text-right font-medium">VAT</th>
                                            <th className="px-3 py-2 text-right font-medium">Total</th>
                                            <th className="px-3 py-2 font-medium">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {invoices.map((invoice) => (
                                            <tr key={invoice.key} className={importable(invoice) ? undefined : 'text-muted-foreground'}>
                                                <td className="px-3 py-2 align-top">
                                                    <Checkbox
                                                        aria-label={`Import invoice ${invoice.number ?? ''}`}
                                                        disabled={!importable(invoice)}
                                                        checked={chosen.has(invoice.key)}
                                                        onCheckedChange={(checked) => toggle(invoice.key, checked === true)}
                                                    />
                                                </td>
                                                <td className="px-3 py-2 align-top whitespace-nowrap">
                                                    {invoice.number ?? `Row ${invoice.rows[0]}`}
                                                    {invoice.lines > 1 && <p className="text-muted-foreground text-xs">{invoice.lines} lines</p>}
                                                </td>
                                                <td className="px-3 py-2 align-top">
                                                    <span
                                                        className={`rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ${statusStyles[invoice.status].className}`}
                                                    >
                                                        {statusStyles[invoice.status].label}
                                                    </span>
                                                    {invoice.messages.map((message) => (
                                                        <p key={message} className="text-muted-foreground mt-1 max-w-64 text-xs">
                                                            {message}
                                                        </p>
                                                    ))}
                                                </td>
                                                <td className="px-3 py-2 align-top">
                                                    {invoice.client}
                                                    {invoice.client && importable(invoice) && (
                                                        <p className="text-muted-foreground text-xs">
                                                            {invoice.new_client ? 'New client' : 'Existing client'}
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 align-top whitespace-nowrap">{formatDate(invoice.issue_date)}</td>
                                                <td className="px-3 py-2 align-top whitespace-nowrap">{formatDate(invoice.due_date)}</td>
                                                <td className="px-3 py-2 text-right align-top tabular-nums">
                                                    {money(invoice.tax_total, invoice.currency)}
                                                </td>
                                                <td className="px-3 py-2 text-right align-top tabular-nums">
                                                    {money(invoice.total, invoice.currency)}
                                                </td>
                                                <td className="px-3 py-2 align-top whitespace-nowrap">
                                                    {invoice.invoice_status && invoiceStatusLabels[invoice.invoice_status]}
                                                    {invoice.invoice_status === 'sent' && !!invoice.amount_paid && (
                                                        <p className="text-muted-foreground text-xs">
                                                            {money(invoice.amount_paid, invoice.currency)} paid
                                                        </p>
                                                    )}
                                                    {invoice.payments > 1 && (
                                                        <p className="text-muted-foreground text-xs">{invoice.payments} payments</p>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <div className="flex items-start gap-3">
                            <Checkbox id="reminders" checked={reminders} onCheckedChange={(checked) => setReminders(checked === true)} />
                            <div className="grid gap-1">
                                <Label htmlFor="reminders">Send payment reminders for imported unpaid invoices</Label>
                                <p className="text-muted-foreground text-sm">
                                    Left off, clients aren't emailed about old invoices. You can still send each one yourself.
                                </p>
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-4">
                            <Button onClick={submit} disabled={importing || loading || importCount === 0 || missing.length > 0}>
                                {importing && <LoaderCircle className="animate-spin" />}
                                Import {importCount} {importCount === 1 ? 'invoice' : 'invoices'}
                            </Button>
                            <span className="text-muted-foreground text-sm">
                                Invoices keep their numbers. New invoices carry on numbering after the highest one imported.
                            </span>
                        </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
