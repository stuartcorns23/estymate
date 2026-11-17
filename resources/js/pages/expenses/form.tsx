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
import { Paperclip } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface ExpenseFormProps {
    expense: Expense | null;
    categories: Option[];
    taxRates: TaxRate[];
    suppliers: string[];
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

export default function ExpenseForm({ expense, categories, taxRates, suppliers }: ExpenseFormProps) {
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

    const setTotal = (total: string) => {
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

                <div className="grid gap-6 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="supplier">Supplier</Label>
                        <Input
                            id="supplier"
                            required
                            list="suppliers"
                            autoComplete="off"
                            value={data.supplier}
                            onChange={(e) => setData('supplier', e.target.value)}
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
                        <Input id="reference" value={data.reference} onChange={(e) => setData('reference', e.target.value)} />
                        <InputError message={errors.reference} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="date">Date</Label>
                        <Input id="date" type="date" required value={data.date} onChange={(e) => setData('date', e.target.value)} />
                        <InputError message={errors.date} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="category">Category</Label>
                        <Select value={data.category} onValueChange={(value) => setData('category', value)}>
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
                        onChange={(e) => setData('description', e.target.value)}
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
                                setData('vat_amount', e.target.value);
                            }}
                            placeholder="0.00"
                        />
                        <InputError message={errors.vat_amount} />
                    </div>
                </div>

                {data.total && net >= 0 && <p className="text-muted-foreground -mt-3 text-sm">Net {formatMoney(net)} before VAT</p>}

                <div className="grid gap-2 sm:max-w-xs">
                    <Label htmlFor="paid_on">Paid on</Label>
                    <Input id="paid_on" type="date" value={data.paid_on} onChange={(e) => setData('paid_on', e.target.value)} />
                    <p className="text-muted-foreground text-xs">Leave blank if you haven't paid it yet.</p>
                    <InputError message={errors.paid_on} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="attachment">Invoice or receipt</Label>
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
                    <Input
                        id="attachment"
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,application/pdf,image/*"
                        onChange={(e) => setData('attachment', e.target.files?.[0] ?? null)}
                    />
                    <p className="text-muted-foreground text-xs">PDF or photo, up to 10 MB.</p>
                    {progress && <progress value={progress.percentage} max="100" className="w-full" />}
                    <InputError message={errors.attachment} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="notes">Notes</Label>
                    <Textarea id="notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                    <InputError message={errors.notes} />
                </div>

                <div className="flex items-center gap-2">
                    <Button disabled={processing}>{expense ? 'Save' : 'Add expense'}</Button>
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
