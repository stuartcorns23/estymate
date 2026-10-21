import { type BreadcrumbItem, type Business } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import BusinessFields, { type BusinessForm } from '@/components/business/business-fields';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Business settings',
        href: '/settings/business',
    },
];

interface TaxRate {
    id: number;
    name: string;
    rate: string;
    is_default: boolean;
}

export default function BusinessSettings({ business, taxRates, canUpdate }: { business: Business; taxRates: TaxRate[]; canUpdate: boolean }) {
    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm<BusinessForm>({
        name: business.name,
        email: business.email ?? '',
        phone: business.phone ?? '',
        address_line_1: business.address_line_1 ?? '',
        address_line_2: business.address_line_2 ?? '',
        city: business.city ?? '',
        county: business.county ?? '',
        postcode: business.postcode ?? '',
        vat_number: business.vat_number ?? '',
        company_number: business.company_number ?? '',
        estimate_prefix: business.estimate_prefix,
        invoice_prefix: business.invoice_prefix,
        payment_terms_days: String(business.payment_terms_days),
        payment_details: business.payment_details ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        patch(route('business.update'), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Business settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Business details" description="Shown on your estimates and invoices" />

                    <form onSubmit={submit} className="space-y-6">
                        <BusinessFields data={data} setData={setData} errors={errors} disabled={!canUpdate || processing} />

                        <HeadingSmall title="Estimates and invoices" description="Numbering and payment terms for new documents" />

                        <div className="grid gap-6 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="estimate_prefix">Estimate prefix</Label>
                                <Input
                                    id="estimate_prefix"
                                    value={data.estimate_prefix}
                                    onChange={(e) => setData('estimate_prefix', e.target.value)}
                                    disabled={!canUpdate || processing}
                                />
                                <InputError message={errors.estimate_prefix} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="invoice_prefix">Invoice prefix</Label>
                                <Input
                                    id="invoice_prefix"
                                    value={data.invoice_prefix}
                                    onChange={(e) => setData('invoice_prefix', e.target.value)}
                                    disabled={!canUpdate || processing}
                                />
                                <InputError message={errors.invoice_prefix} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="payment_terms_days">Payment terms (days)</Label>
                                <Input
                                    id="payment_terms_days"
                                    type="number"
                                    min={0}
                                    max={365}
                                    value={data.payment_terms_days}
                                    onChange={(e) => setData('payment_terms_days', e.target.value)}
                                    disabled={!canUpdate || processing}
                                />
                                <InputError message={errors.payment_terms_days} />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="payment_details">Payment details</Label>
                            <Textarea
                                id="payment_details"
                                rows={4}
                                value={data.payment_details}
                                onChange={(e) => setData('payment_details', e.target.value)}
                                disabled={!canUpdate || processing}
                                placeholder={'Bank: Example Bank\nSort code: 00-00-00\nAccount number: 12345678'}
                            />
                            <p className="text-muted-foreground text-xs">Added to the notes on new invoices.</p>
                            <InputError message={errors.payment_details} />
                        </div>

                        {canUpdate && (
                            <div className="flex items-center gap-4">
                                <Button disabled={processing}>Save</Button>

                                <Transition
                                    show={recentlySuccessful}
                                    enter="transition ease-in-out"
                                    enterFrom="opacity-0"
                                    leave="transition ease-in-out"
                                    leaveTo="opacity-0"
                                >
                                    <p className="text-sm text-neutral-600">Saved</p>
                                </Transition>
                            </div>
                        )}
                    </form>
                </div>

                <div className="space-y-6">
                    <HeadingSmall title="VAT rates" description={`Prices are in ${business.currency}. These UK rates are available on documents.`} />

                    <ul className="divide-y rounded-lg border">
                        {taxRates.map((taxRate) => (
                            <li key={taxRate.id} className="flex items-center justify-between px-4 py-3 text-sm">
                                <span>
                                    {taxRate.name}
                                    {taxRate.is_default && <span className="text-muted-foreground"> (default)</span>}
                                </span>
                                <span className="font-medium">{Number(taxRate.rate)}%</span>
                            </li>
                        ))}
                    </ul>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
