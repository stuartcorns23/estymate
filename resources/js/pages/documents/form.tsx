import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatMoney, formatRate, toPence } from '@/lib/money';
import { type BreadcrumbItem, type DocumentTypeInfo, type TaxRate } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { FormEventHandler } from 'react';

type LineItem = {
    product_id: number | null;
    description: string;
    quantity: string;
    unit: string;
    unit_price: string;
    tax_rate: string;
};

type DocumentFormData = {
    client_id: string;
    issue_date: string;
    due_date: string;
    reference: string;
    notes: string;
    items: LineItem[];
};

interface ProductOption {
    id: number;
    name: string;
    description: string | null;
    unit: string | null;
    unit_price: string;
    tax_rate: string | null;
}

interface DocumentFormProps {
    type: DocumentTypeInfo;
    clients: { id: number; name: string; email: string | null }[];
    products: ProductOption[];
    taxRates: TaxRate[];
    document: {
        id?: number;
        number?: string;
        client_id: number | null;
        issue_date: string;
        due_date: string | null;
        reference: string;
        notes: string;
        items: LineItem[];
    };
}

/**
 * Mirrors DocumentItem::amountsFor on the server, so the preview matches what gets saved.
 */
function lineAmounts(item: LineItem) {
    const net = Math.round((parseFloat(item.quantity) || 0) * toPence(item.unit_price));
    const tax = Math.round((net * (parseFloat(item.tax_rate) || 0)) / 100);

    return { net, tax };
}

export default function DocumentForm({ type, clients, products, taxRates, document }: DocumentFormProps) {
    const isInvoice = type.value === 'invoice';
    const label = type.label.toLowerCase();
    const defaultRate = taxRates.find((rate) => rate.is_default)?.rate ?? taxRates[0]?.rate ?? '0.00';
    const title = document.id ? `Edit ${label} ${document.number}` : `New ${label}`;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: `${type.label}s`, href: `/${type.plural}` },
        { title: document.number ?? `New ${label}`, href: '#' },
    ];

    const emptyLine = (): LineItem => ({ product_id: null, description: '', quantity: '1', unit: '', unit_price: '', tax_rate: defaultRate });

    const { data, setData, post, put, processing, errors } = useForm<DocumentFormData>({
        client_id: document.client_id ? String(document.client_id) : '',
        issue_date: document.issue_date,
        due_date: document.due_date ?? '',
        reference: document.reference,
        notes: document.notes,
        items: document.items.length ? document.items.map((item) => ({ ...item, quantity: String(Number(item.quantity)) })) : [emptyLine()],
    });

    const errorFor = (key: string) => (errors as Record<string, string | undefined>)[key];

    const updateLine = (index: number, changes: Partial<LineItem>) => {
        setData(
            'items',
            data.items.map((item, i) => (i === index ? { ...item, ...changes } : item)),
        );
    };

    const addProduct = (productId: string) => {
        const product = products.find((p) => String(p.id) === productId);

        if (!product) {
            return;
        }

        const line: LineItem = {
            product_id: product.id,
            description: product.description ? `${product.name}\n${product.description}` : product.name,
            quantity: '1',
            unit: product.unit ?? '',
            unit_price: product.unit_price,
            tax_rate: product.tax_rate ?? defaultRate,
        };

        // Replace the starting blank line rather than leaving it empty above the product.
        const items = data.items.length === 1 && !data.items[0].description && !data.items[0].unit_price ? [line] : [...data.items, line];
        setData('items', items);
    };

    const totals = data.items.reduce(
        (sum, item) => {
            const { net, tax } = lineAmounts(item);
            return { net: sum.net + net, tax: sum.tax + tax };
        },
        { net: 0, tax: 0 },
    );

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (document.id) {
            put(route(`${type.plural}.update`, document.id));
        } else {
            post(route(`${type.plural}.store`));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <form onSubmit={submit} className="max-w-5xl space-y-8 p-4">
                <PageHeader title={title} />

                {clients.length === 0 && (
                    <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                        You need a client first.{' '}
                        <Link href={route('clients.create')} className="font-medium underline">
                            Add a client
                        </Link>
                    </div>
                )}

                <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="client_id">Client</Label>
                        <Select value={data.client_id} onValueChange={(value) => setData('client_id', value)}>
                            <SelectTrigger id="client_id">
                                <SelectValue placeholder="Choose a client" />
                            </SelectTrigger>
                            <SelectContent>
                                {clients.map((client) => (
                                    <SelectItem key={client.id} value={String(client.id)}>
                                        {client.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.client_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="issue_date">Date</Label>
                        <Input id="issue_date" type="date" required value={data.issue_date} onChange={(e) => setData('issue_date', e.target.value)} />
                        <InputError message={errors.issue_date} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="due_date">{isInvoice ? 'Due date' : 'Valid until'}</Label>
                        <Input id="due_date" type="date" value={data.due_date} onChange={(e) => setData('due_date', e.target.value)} />
                        <InputError message={errors.due_date} />
                    </div>

                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="reference">Reference or PO number</Label>
                        <Input id="reference" value={data.reference} onChange={(e) => setData('reference', e.target.value)} />
                        <InputError message={errors.reference} />
                    </div>
                </div>

                <section className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="font-medium">Lines</h2>
                        {products.length > 0 && (
                            <Select value="" onValueChange={addProduct}>
                                <SelectTrigger className="w-56">
                                    <SelectValue placeholder="Add a saved item" />
                                </SelectTrigger>
                                <SelectContent>
                                    {products.map((product) => (
                                        <SelectItem key={product.id} value={String(product.id)}>
                                            {product.name} · {formatMoney(toPence(product.unit_price))}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                    </div>
                    <InputError message={errors.items} />

                    <div className="space-y-3">
                        <div className="text-muted-foreground hidden grid-cols-[1fr_5rem_6rem_7rem_8rem_6rem_2.5rem] gap-2 px-1 text-xs font-medium md:grid">
                            <span>Description</span>
                            <span>Qty</span>
                            <span>Unit</span>
                            <span>Price (£)</span>
                            <span>VAT</span>
                            <span className="text-right">Amount</span>
                            <span />
                        </div>

                        {data.items.map((item, index) => (
                            <div
                                key={index}
                                className="grid gap-2 rounded-lg border p-3 md:grid-cols-[1fr_5rem_6rem_7rem_8rem_6rem_2.5rem] md:items-start md:border-0 md:p-0"
                            >
                                <div>
                                    <Textarea
                                        aria-label="Description"
                                        rows={1}
                                        className="min-h-10"
                                        value={item.description}
                                        onChange={(e) => updateLine(index, { description: e.target.value })}
                                        placeholder="What you're charging for"
                                    />
                                    <InputError message={errorFor(`items.${index}.description`)} />
                                </div>
                                <div>
                                    <Input
                                        aria-label="Quantity"
                                        inputMode="decimal"
                                        value={item.quantity}
                                        onChange={(e) => updateLine(index, { quantity: e.target.value })}
                                    />
                                    <InputError message={errorFor(`items.${index}.quantity`)} />
                                </div>
                                <Input
                                    aria-label="Unit"
                                    value={item.unit}
                                    onChange={(e) => updateLine(index, { unit: e.target.value })}
                                    placeholder="hours"
                                />
                                <div>
                                    <Input
                                        aria-label="Unit price"
                                        inputMode="decimal"
                                        value={item.unit_price}
                                        onChange={(e) => updateLine(index, { unit_price: e.target.value })}
                                        placeholder="0.00"
                                    />
                                    <InputError message={errorFor(`items.${index}.unit_price`)} />
                                </div>
                                <div>
                                    <Select value={item.tax_rate} onValueChange={(value) => updateLine(index, { tax_rate: value })}>
                                        <SelectTrigger aria-label="VAT rate">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {taxRates.map((rate) => (
                                                <SelectItem key={rate.id} value={rate.rate}>
                                                    {formatRate(rate.rate)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errorFor(`items.${index}.tax_rate`)} />
                                </div>
                                <div className="flex h-10 items-center justify-end text-sm tabular-nums">{formatMoney(lineAmounts(item).net)}</div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Remove line"
                                    disabled={data.items.length === 1}
                                    onClick={() =>
                                        setData(
                                            'items',
                                            data.items.filter((_, i) => i !== index),
                                        )
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        ))}
                    </div>

                    <Button type="button" variant="outline" size="sm" onClick={() => setData('items', [...data.items, emptyLine()])}>
                        <Plus /> Add line
                    </Button>

                    <dl className="ml-auto grid w-full max-w-xs grid-cols-2 gap-y-1 pt-4 text-sm">
                        <dt className="text-muted-foreground">Subtotal</dt>
                        <dd className="text-right tabular-nums">{formatMoney(totals.net)}</dd>
                        <dt className="text-muted-foreground">VAT</dt>
                        <dd className="text-right tabular-nums">{formatMoney(totals.tax)}</dd>
                        <dt className="border-t pt-2 font-semibold">Total</dt>
                        <dd className="border-t pt-2 text-right font-semibold tabular-nums">{formatMoney(totals.net + totals.tax)}</dd>
                    </dl>
                </section>

                <div className="grid gap-2">
                    <Label htmlFor="notes">Notes shown on the {label}</Label>
                    <Textarea
                        id="notes"
                        rows={4}
                        value={data.notes}
                        onChange={(e) => setData('notes', e.target.value)}
                        placeholder={isInvoice ? 'Payment details, thank-you note' : 'Scope, terms or anything the client should know'}
                    />
                    <InputError message={errors.notes} />
                </div>

                <div className="flex items-center gap-2">
                    <Button disabled={processing || clients.length === 0}>{document.id ? 'Save' : `Create ${label}`}</Button>
                    <Button variant="ghost" asChild>
                        <Link href={document.id ? route(`${type.plural}.show`, document.id) : route(`${type.plural}.index`)}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
