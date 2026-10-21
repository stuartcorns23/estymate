import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatRate } from '@/lib/money';
import { type BreadcrumbItem, type Product, type TaxRate } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function ProductForm({ product, taxRates }: { product: Product | null; taxRates: TaxRate[] }) {
    const title = product ? `Edit ${product.name}` : 'Add item';
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Products & services', href: '/products' },
        { title: product ? product.name : 'Add item', href: '#' },
    ];
    const defaultRate = taxRates.find((rate) => rate.is_default);

    const { data, setData, post, put, processing, errors } = useForm({
        name: product?.name ?? '',
        description: product?.description ?? '',
        unit_price: product ? (product.unit_price / 100).toFixed(2) : '',
        unit: product?.unit ?? '',
        tax_rate_id: String(product ? (product.tax_rate_id ?? '') : (defaultRate?.id ?? '')),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (product) {
            put(route('products.update', product.id));
        } else {
            post(route('products.store'));
        }
    };

    const destroy = () => {
        if (product && confirm(`Delete ${product.name}? Documents that already use it aren't changed.`)) {
            router.delete(route('products.destroy', product.id));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <form onSubmit={submit} className="max-w-2xl space-y-6 p-4">
                <PageHeader title={title} />

                <div className="grid gap-2">
                    <Label htmlFor="name">Name</Label>
                    <Input id="name" required value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Website design" />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="description">Description</Label>
                    <Textarea id="description" value={data.description} onChange={(e) => setData('description', e.target.value)} />
                    <InputError message={errors.description} />
                </div>

                <div className="grid gap-6 sm:grid-cols-3">
                    <div className="grid gap-2">
                        <Label htmlFor="unit_price">Price (£, excl. VAT)</Label>
                        <Input
                            id="unit_price"
                            required
                            inputMode="decimal"
                            value={data.unit_price}
                            onChange={(e) => setData('unit_price', e.target.value)}
                            placeholder="0.00"
                        />
                        <InputError message={errors.unit_price} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="unit">Unit</Label>
                        <Input id="unit" value={data.unit} onChange={(e) => setData('unit', e.target.value)} placeholder="hour, day, item" />
                        <InputError message={errors.unit} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="tax_rate_id">VAT rate</Label>
                        <Select value={data.tax_rate_id} onValueChange={(value) => setData('tax_rate_id', value)}>
                            <SelectTrigger id="tax_rate_id">
                                <SelectValue placeholder="Choose a rate" />
                            </SelectTrigger>
                            <SelectContent>
                                {taxRates.map((rate) => (
                                    <SelectItem key={rate.id} value={String(rate.id)}>
                                        {rate.name} ({formatRate(rate.rate)})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.tax_rate_id} />
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    <Button disabled={processing}>{product ? 'Save' : 'Add item'}</Button>
                    <Button variant="ghost" asChild>
                        <Link href={route('products.index')}>Cancel</Link>
                    </Button>
                    {product && (
                        <Button type="button" variant="ghost" className="text-destructive ml-auto" onClick={destroy}>
                            Delete
                        </Button>
                    )}
                </div>
            </form>
        </AppLayout>
    );
}
