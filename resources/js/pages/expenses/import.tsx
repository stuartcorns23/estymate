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
import { ChangeEvent, useMemo, useRef, useState } from 'react';

type RowStatus = 'ready' | 'warning' | 'duplicate' | 'invalid';

interface PreviewRow {
    row: number;
    status: RowStatus;
    attributes: Record<string, string | number | null>;
    messages: string[];
}

interface Preview {
    headers: string[];
    format: string | null;
    mapping: (string | null)[];
    samples: string[];
    rows: PreviewRow[];
}

const SKIP = 'skip';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Expenses', href: '/expenses' },
    { title: 'Import', href: '/expenses/import' },
];

const statusStyles: Record<RowStatus, { label: string; className: string }> = {
    ready: { label: 'Ready', className: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300' },
    warning: { label: 'Check', className: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200' },
    duplicate: { label: 'Duplicate?', className: 'bg-blue-100 text-blue-900 dark:bg-blue-950 dark:text-blue-200' },
    invalid: { label: "Can't import", className: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' },
};

/**
 * Read the file in the browser. Most exports are UTF-8; older Excel ones are Windows-1252.
 */
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

type Option = { value: string; label: string };

export default function ExpenseImport({ fields, categories, maxBytes }: { fields: Option[]; categories: Option[]; maxBytes: number }) {
    const [fileName, setFileName] = useState<string | null>(null);
    const [csv, setCsv] = useState<string | null>(null);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [mapping, setMapping] = useState<(string | null)[] | null>(null);
    const [chosen, setChosen] = useState<Set<number>>(new Set());
    const [loading, setLoading] = useState(false);
    const [importing, setImporting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const request = useRef(0);

    const categoryLabels = useMemo(() => Object.fromEntries(categories.map((category) => [category.value, category.label])), [categories]);

    const loadPreview = async (text: string, newMapping: (string | null)[] | null, resetChoice: boolean) => {
        const id = ++request.current;
        setLoading(true);
        setError(null);

        try {
            const response = await fetch(route('expenses.import.preview'), {
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
                const selectable = (body.rows as PreviewRow[]).filter((row) => row.status !== 'invalid');

                if (resetChoice) {
                    // Duplicates start unticked, so they're only imported if the person decides to.
                    return new Set(selectable.filter((row) => row.status !== 'duplicate').map((row) => row.row));
                }

                return new Set(selectable.filter((row) => previous.has(row.row)).map((row) => row.row));
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

    // Re-check the rows when a column's mapping changes.
    const changeMapping = (column: number, value: string) => {
        if (!mapping || !csv) return;

        const next = mapping.map((field, index) => (index === column ? (value === SKIP ? null : value) : field));
        setMapping(next);
        loadPreview(csv, next, false);
    };

    const rows = preview?.rows ?? [];
    const counts = rows.reduce<Record<RowStatus, number>>((totals, row) => ({ ...totals, [row.status]: totals[row.status] + 1 }), {
        ready: 0,
        warning: 0,
        duplicate: 0,
        invalid: 0,
    });
    const selectable = rows.filter((row) => row.status !== 'invalid');

    const toggle = (row: number, checked: boolean) => {
        setChosen((previous) => {
            const next = new Set(previous);

            if (checked) next.add(row);
            else next.delete(row);

            return next;
        });
    };

    const toggleAll = (checked: boolean) => setChosen(checked ? new Set(selectable.map((row) => row.row)) : new Set());

    const submit = () => {
        if (!csv || !mapping) return;

        router.post(
            route('expenses.import.store'),
            { csv, mapping, rows: rows.filter((row) => chosen.has(row.row)).map((row) => row.row) },
            {
                onStart: () => setImporting(true),
                onFinish: () => setImporting(false),
                onError: (errors) => setError(Object.values(errors)[0] ?? 'The import failed.'),
            },
        );
    };

    const importCount = chosen.size;
    const ready =
        mapping !== null && mapping.includes('supplier') && mapping.includes('date') && (mapping.includes('total') || mapping.includes('net'));
    const missing = !mapping
        ? undefined
        : !mapping.includes('supplier')
          ? 'Choose which column holds the supplier.'
          : !mapping.includes('date')
            ? 'Choose which column holds the date.'
            : !mapping.includes('total') && !mapping.includes('net')
              ? 'Choose which column holds the amount.'
              : undefined;

    const money = (row: PreviewRow, field: string) =>
        typeof row.attributes[field] === 'number' ? formatMoney(row.attributes[field] as number, (row.attributes.currency as string) ?? 'GBP') : '';
    const shownColumns: { label: string; value: (row: PreviewRow) => string; numeric?: boolean }[] = [
        { label: 'Date', value: (row) => (row.attributes.date ? formatDate(row.attributes.date as string) : '') },
        { label: 'Supplier', value: (row) => (row.attributes.supplier as string) ?? '' },
        { label: 'Reference', value: (row) => (row.attributes.reference as string) ?? '' },
        { label: 'Description', value: (row) => (row.attributes.description as string) ?? '' },
        { label: 'Category', value: (row) => categoryLabels[row.attributes.category as string] ?? '' },
        { label: 'Total', value: (row) => money(row, 'total'), numeric: true },
        { label: 'VAT', value: (row) => money(row, 'vat_amount'), numeric: true },
        {
            label: 'Paid',
            value: (row) => (row.attributes.paid_on ? formatDate(row.attributes.paid_on as string) : row.status === 'invalid' ? '' : 'Unpaid'),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Import expenses" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Import expenses"
                    description="Add purchase invoices, bills and receipts from a CSV file, such as an export from Billdu, Excel or your bank"
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={route('expenses.index')}>Cancel</Link>
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
                                {preview && ` · ${rows.length} rows`}
                                {preview?.format && ` · recognised as a ${preview.format} export`}
                            </>
                        ) : (
                            'The first row should be the column headings. Nothing is saved until you press Import.'
                        )}
                    </p>
                    {loading && <LoaderCircle className="text-muted-foreground size-4 animate-spin" />}
                </div>

                <InputError message={error ?? missing} />

                {preview && mapping && (
                    <>
                        <section className="space-y-3">
                            <div>
                                <h2 className="font-semibold">Match the columns</h2>
                                <p className="text-muted-foreground text-sm">
                                    {preview.format
                                        ? `We've matched the ${preview.format} columns for you. Payment details and other columns go into each expense's notes.`
                                        : 'Choose the expense field for each column in your file. Columns you leave out are not imported.'}
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
                                <h2 className="font-semibold">Check the expenses</h2>
                                <p className="text-muted-foreground text-sm">
                                    {counts.ready + counts.warning} ready to import
                                    {counts.warning > 0 && ` (${counts.warning} with small fixes)`}
                                    {counts.duplicate > 0 && `, ${counts.duplicate} possible duplicates left unticked`}
                                    {counts.invalid > 0 && `, ${counts.invalid} can't be imported`}. Categories are matched to ours, and the original
                                    wording is kept in the notes. Untick any you don't want.
                                </p>
                            </div>

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
                                            <th className="px-3 py-2 font-medium">Row</th>
                                            <th className="px-3 py-2 font-medium">Status</th>
                                            {shownColumns.map((column) => (
                                                <th
                                                    key={column.label}
                                                    className={`px-3 py-2 font-medium whitespace-nowrap ${column.numeric ? 'text-right' : ''}`}
                                                >
                                                    {column.label}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {rows.map((row) => (
                                            <tr key={row.row} className={row.status === 'invalid' ? 'text-muted-foreground' : undefined}>
                                                <td className="px-3 py-2 align-top">
                                                    <Checkbox
                                                        aria-label={`Import row ${row.row}`}
                                                        disabled={row.status === 'invalid'}
                                                        checked={chosen.has(row.row)}
                                                        onCheckedChange={(checked) => toggle(row.row, checked === true)}
                                                    />
                                                </td>
                                                <td className="px-3 py-2 align-top tabular-nums">{row.row}</td>
                                                <td className="px-3 py-2 align-top">
                                                    <span
                                                        className={`rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ${statusStyles[row.status].className}`}
                                                    >
                                                        {statusStyles[row.status].label}
                                                    </span>
                                                    {row.messages.map((message) => (
                                                        <p key={message} className="text-muted-foreground mt-1 max-w-64 text-xs">
                                                            {message}
                                                        </p>
                                                    ))}
                                                </td>
                                                {shownColumns.map((column) => (
                                                    <td
                                                        key={column.label}
                                                        className={`px-3 py-2 align-top ${column.numeric ? 'text-right whitespace-nowrap tabular-nums' : ''}`}
                                                    >
                                                        {column.value(row)}
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <div className="flex flex-wrap items-center gap-4">
                            <Button onClick={submit} disabled={importing || loading || importCount === 0 || !ready}>
                                {importing && <LoaderCircle className="animate-spin" />}
                                Import {importCount} {importCount === 1 ? 'expense' : 'expenses'}
                            </Button>
                            <span className="text-muted-foreground text-sm">
                                You can edit or delete expenses afterwards, and add receipts to them.
                            </span>
                        </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
