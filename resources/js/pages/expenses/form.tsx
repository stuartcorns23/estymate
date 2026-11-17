import FileDropZone from '@/components/file-drop-zone';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatMoney, formatRate, toPence } from '@/lib/money';
import { type BreadcrumbItem, type Expense, type Option, type TaxRate } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { LoaderCircle, Paperclip, Sparkles } from 'lucide-react';
import { FormEventHandler, useRef, useState } from 'react';

interface ExpenseFormProps {
    expense: Expense | null;
    categories: Option[];
    taxRates: TaxRate[];
    suppliers: string[];
    canScan: boolean;
    scanTypes: string[];
}

type ScannedFields = Partial<Record<'supplier' | 'reference' | 'date' | 'category' | 'description' | 'total' | 'vat_amount', string>>;

/**
 * Laravel sets the XSRF-TOKEN cookie on every response; send it back on our own fetch calls.
 */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

const pounds = (pence: number) => (pence / 100).toFixed(2);

/**
 * The VAT included in a total, like £120 at 20% → "20.00".
 */
function vatIncluded(total: string, rate: string): string {
    const pence = toPence(total);
    const percent = Number(rate);

    return pounds(Math.round((pence * percent) / (100 + percent)));
}

export default function ExpenseForm({ expense, categories, taxRates, suppliers, canScan, scanTypes }: ExpenseFormProps) {
    const title = expense ? `Edit expense from ${expense.supplier}` : 'Add expense';
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Expenses', href: '/expenses' },
        { title: expense ? expense.supplier : 'Add expense', href: '#' },
    ];
    const defaultRate = taxRates.find((rate) => rate.is_default);

    // The VAT rate only helps work out the VAT amount; the amount is what's saved.
    const [vatRate, setVatRate] = useState(expense ? 'custom' : (defaultRate?.rate ?? 'custom'));

    const { data, setData, post, processing, errors, progress, transform } = useForm({
        supplier: expense?.supplier ?? '',
        reference: expense?.reference ?? '',
        date: expense?.date ?? new Date().toISOString().slice(0, 10),
        category: expense?.category ?? '',
        description: expense?.description ?? '',
        total: expense ? pounds(expense.total) : '',
        vat_amount: expense ? pounds(expense.vat_amount) : '',
        paid_on: expense?.paid_on ?? '',
        notes: expense?.notes ?? '',
        attachment: null as File | null,
        remove_attachment: false as boolean,
    });

    // Fields the person has typed in themselves, which reading a document never overwrites.
    const touched = useRef(new Set<string>());
    const [scan, setScan] = useState<{ state: 'idle' | 'reading' | 'done' | 'failed'; message?: string }>({ state: 'idle' });

    const edit = <K extends keyof typeof data>(key: K, value: (typeof data)[K]) => {
        touched.current.add(key);
        setData(key, value);
    };

    const readDocument = async (file: File) => {
        setScan({ state: 'reading' });

        const body = new FormData();
        body.append('attachment', file);

        try {
            const response = await fetch(route('expenses.scan'), {
                method: 'POST',
                body,
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            });
            const json = await response.json().catch(() => ({}));

            if (!response.ok) {
                const message = json.errors?.attachment?.[0] ?? json.message ?? "The document couldn't be read. Please fill in the details yourself.";
                setScan({ state: 'failed', message: response.status === 429 ? 'Too many documents read at once. Try again in a minute.' : message });
                return;
            }

            const fields: ScannedFields = json.fields ?? {};
            // On a new expense, fill anything the person hasn't typed; when editing, only fill blanks.
            const fillable = (Object.keys(fields) as (keyof ScannedFields)[]).filter((key) => !touched.current.has(key) && (!expense || !data[key]));

            if (fillable.length === 0) {
                setScan({ state: 'done', message: 'Nothing new to fill in from the document.' });
                return;
            }

            setData((current) => ({ ...current, ...Object.fromEntries(fillable.map((key) => [key, fields[key]])) }));

            if (fillable.includes('total') || fillable.includes('vat_amount')) {
                setVatRate('custom');
            }

            setScan({
                state: 'done',
                message: `Filled in ${fillable.length} ${fillable.length === 1 ? 'detail' : 'details'} from the document. Check them before saving.`,
            });
        } catch {
            setScan({ state: 'failed', message: "The document couldn't be read. Please fill in the details yourself." });
        }
    };

    const chooseFile = (file: File) => {
        setData((current) => ({ ...current, attachment: file, remove_attachment: false }));

        if (canScan && scanTypes.includes(file.type)) {
            readDocument(file);
        } else {
            setScan({ state: 'idle' });
        }
    };

    const setTotal = (total: string) => {
        touched.current.add('total');
        setData((current) => ({ ...current, total, vat_amount: vatRate === 'custom' ? current.vat_amount : vatIncluded(total, vatRate) }));
    };

    const chooseRate = (rate: string) => {
        setVatRate(rate);

        if (rate !== 'custom') {
            setData('vat_amount', vatIncluded(data.total, rate));
        }
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (expense) {
            // Files can only be uploaded with POST, so the update is sent as a POST that Laravel treats as PUT.
            transform((form) => ({ ...form, _method: 'put' }));
            post(route('expenses.update', expense.id), { forceFormData: true });
        } else {
            post(route('expenses.store'), { forceFormData: true });
        }
    };

    const destroy = () => {
        if (expense && confirm(`Delete this expense from ${expense.supplier}?`)) {
            router.delete(route('expenses.destroy', expense.id));
        }
    };

    const net = toPence(data.total) - toPence(data.vat_amount || '0');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <form onSubmit={submit} className="max-w-2xl space-y-6 p-4">
                <PageHeader title={title} />

                <div className="grid gap-2">
                    <Label htmlFor="attachment">Invoice or receipt</Label>
                    <FileDropZone id="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.heic,application/pdf,image/*" onFile={chooseFile}>
                        {data.attachment ? (
                            <span className="font-medium">{data.attachment.name}</span>
                        ) : (
                            <span>
                                <span className="font-medium">Drop the invoice or receipt here</span> or click to choose a file
                            </span>
                        )}
                        <span className="text-muted-foreground text-xs">
                            PDF or photo, up to 10 MB.{canScan && " We'll read it and fill in the details for you."}
                        </span>
                    </FileDropZone>
                    {expense?.has_attachment && !data.remove_attachment && !data.attachment && (
                        <div className="flex items-center gap-3 text-sm">
                            <a href={route('expenses.attachment', expense.id)} className="inline-flex items-center gap-1.5 hover:underline">
                                <Paperclip className="size-4" /> {expense.attachment_name}
                            </a>
                            <Button type="button" variant="ghost" size="sm" onClick={() => setData('remove_attachment', true)}>
                                Remove
                            </Button>
                        </div>
                    )}
                    {scan.state === 'reading' && (
                        <p className="text-muted-foreground flex items-center gap-2 text-sm">
                            <LoaderCircle className="size-4 animate-spin" /> Reading the document…
                        </p>
                    )}
                    {scan.state === 'done' && (
                        <p className="flex items-center gap-2 text-sm">
                            <Sparkles className="size-4 shrink-0" /> {scan.message}
                        </p>
                    )}
                    {scan.state === 'failed' && <p className="text-destructive text-sm">{scan.message}</p>}
                    {progress && <progress value={progress.percentage} max="100" className="w-full" />}
                    <InputError message={errors.attachment} />
                </div>

                <div className="grid gap-6 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="supplier">Supplier</Label>
                        <Input
                            id="supplier"
                            required
                            list="suppliers"
                            autoComplete="off"
                            value={data.supplier}
                            onChange={(e) => edit('supplier', e.target.value)}
                            placeholder="Screwfix"
                        />
                        <datalist id="suppliers">
                            {suppliers.map((supplier) => (
                                <option key={supplier} value={supplier} />
                            ))}
                        </datalist>
                        <InputError message={errors.supplier} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="reference">Invoice or receipt number</Label>
                        <Input id="reference" value={data.reference} onChange={(e) => edit('reference', e.target.value)} />
                        <InputError message={errors.reference} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="date">Date</Label>
                        <Input id="date" type="date" required value={data.date} onChange={(e) => edit('date', e.target.value)} />
                        <InputError message={errors.date} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="category">Category</Label>
                        <Select value={data.category} onValueChange={(value) => edit('category', value)}>
                            <SelectTrigger id="category">
                                <SelectValue placeholder="Choose a category" />
                            </SelectTrigger>
                            <SelectContent>
                                {categories.map((category) => (
                                    <SelectItem key={category.value} value={category.value}>
                                        {category.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.category} />
                    </div>
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="description">What was it for?</Label>
                    <Input
                        id="description"
                        value={data.description}
                        onChange={(e) => edit('description', e.target.value)}
                        placeholder="Timber and fixings"
                    />
                    <InputError message={errors.description} />
                </div>

                <div className="grid gap-6 sm:grid-cols-3">
                    <div className="grid gap-2">
                        <Label htmlFor="total">Total (£, incl. VAT)</Label>
                        <Input
                            id="total"
                            required
                            inputMode="decimal"
                            value={data.total}
                            onChange={(e) => setTotal(e.target.value)}
                            placeholder="0.00"
                        />
                        <InputError message={errors.total} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="vat_rate">VAT rate</Label>
                        <Select value={vatRate} onValueChange={chooseRate}>
                            <SelectTrigger id="vat_rate">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {taxRates.map((rate) => (
                                    <SelectItem key={rate.id} value={rate.rate}>
                                        {rate.name} ({formatRate(rate.rate)})
                                    </SelectItem>
                                ))}
                                <SelectItem value="custom">Enter VAT amount</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="vat_amount">VAT (£)</Label>
                        <Input
                            id="vat_amount"
                            inputMode="decimal"
                            value={data.vat_amount}
                            onChange={(e) => {
                                setVatRate('custom');
                                edit('vat_amount', e.target.value);
                            }}
                            placeholder="0.00"
                        />
                        <InputError message={errors.vat_amount} />
                    </div>
                </div>

                {data.total && net >= 0 && <p className="text-muted-foreground -mt-3 text-sm">Net {formatMoney(net)} before VAT</p>}

                <div className="grid gap-2 sm:max-w-xs">
                    <Label htmlFor="paid_on">Paid on</Label>
                    <Input id="paid_on" type="date" value={data.paid_on} onChange={(e) => edit('paid_on', e.target.value)} />
                    <p className="text-muted-foreground text-xs">Leave blank if you haven't paid it yet.</p>
                    <InputError message={errors.paid_on} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="notes">Notes</Label>
                    <Textarea id="notes" value={data.notes} onChange={(e) => edit('notes', e.target.value)} />
                    <InputError message={errors.notes} />
                </div>

                <div className="flex items-center gap-2">
                    <Button disabled={processing || scan.state === 'reading'}>{expense ? 'Save' : 'Add expense'}</Button>
                    <Button variant="ghost" asChild>
                        <Link href={route('expenses.index')}>Cancel</Link>
                    </Button>
                    {expense && (
                        <Button type="button" variant="ghost" className="text-destructive ml-auto" onClick={destroy}>
                            Delete
                        </Button>
                    )}
                </div>
            </form>
        </AppLayout>
    );
}
