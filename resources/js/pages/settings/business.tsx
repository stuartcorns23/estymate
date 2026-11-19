import { type BreadcrumbItem, type Business } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, useForm } from '@inertiajs/react';
import { ComponentProps, FormEventHandler } from 'react';

import BusinessFields from '@/components/business/business-fields';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({
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
        proforma_prefix: business.proforma_prefix,
        creditnote_prefix: business.creditnote_prefix,
        deliverynote_prefix: business.deliverynote_prefix,
        invoice_prefix: business.invoice_prefix,
        payment_terms_days: String(business.payment_terms_days),
        payment_details: business.payment_details ?? '',
        send_reminders: business.send_reminders,
        website: business.website ?? '',
        contact_name: business.contact_name ?? '',
        bank_name: business.bank_name ?? '',
        bank_account_name: business.bank_account_name ?? '',
        bank_sort_code: business.bank_sort_code ?? '',
        bank_account_number: business.bank_account_number ?? '',
        bank_iban: business.bank_iban ?? '',
        bank_bic: business.bank_bic ?? '',
    });

    type FooterField =
        | 'website'
        | 'contact_name'
        | 'bank_name'
        | 'bank_account_name'
        | 'bank_sort_code'
        | 'bank_account_number'
        | 'bank_iban'
        | 'bank_bic';

    const footerInput = (name: FooterField, label: string, placeholder?: string, extra: Partial<ComponentProps<typeof Input>> = {}) => (
        <div className="grid gap-2">
            <Label htmlFor={name}>{label}</Label>
            <Input
                id={name}
                value={data[name]}
                onChange={(e) => setData(name, e.target.value)}
                disabled={!canUpdate || processing}
                placeholder={placeholder}
                {...extra}
            />
            <InputError message={errors[name]} />
        </div>
    );

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

                        <div className="grid gap-6 sm:grid-cols-2">
                            {footerInput('website', 'Website', 'www.example.co.uk', { autoComplete: 'url' })}
                            {footerInput('contact_name', 'Contact person', 'Jane Smith', { autoComplete: 'name' })}
                        </div>

                        <HeadingSmall
                            title="Bank details"
                            description="Printed in the footer of your documents so clients can pay by bank transfer. Leave blank to hide."
                        />

                        <div className="grid gap-6 sm:grid-cols-2">
                            {footerInput('bank_account_name', 'Account holder', 'Acme Ltd')}
                            {footerInput('bank_name', 'Bank', 'Starling')}
                            {footerInput('bank_sort_code', 'Sort code', '00-00-00', { inputMode: 'numeric' })}
                            {footerInput('bank_account_number', 'Account number', '12345678', { inputMode: 'numeric' })}
                            {footerInput('bank_iban', 'IBAN', 'GB00 XXXX 0000 0000 0000 00')}
                            {footerInput('bank_bic', 'BIC / SWIFT', 'XXXXGB2L')}
                        </div>

                        <HeadingSmall title="Estimates and invoices" description="Numbering and payment terms for new documents" />

                        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
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
                                <Label htmlFor="proforma_prefix">Pro-forma prefix</Label>
                                <Input
                                    id="proforma_prefix"
                                    value={data.proforma_prefix}
                                    onChange={(e) => setData('proforma_prefix', e.target.value)}
                                    disabled={!canUpdate || processing}
                                />
                                <InputError message={errors.proforma_prefix} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="creditnote_prefix">Credit note prefix</Label>
                                <Input
                                    id="creditnote_prefix"
                                    value={data.creditnote_prefix}
                                    onChange={(e) => setData('creditnote_prefix', e.target.value)}
                                    disabled={!canUpdate || processing}
                                />
                                <InputError message={errors.creditnote_prefix} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="deliverynote_prefix">Delivery note prefix</Label>
                                <Input
                                    id="deliverynote_prefix"
                                    value={data.deliverynote_prefix}
                                    onChange={(e) => setData('deliverynote_prefix', e.target.value)}
                                    disabled={!canUpdate || processing}
                                />
                                <InputError message={errors.deliverynote_prefix} />
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

                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="send_reminders"
                                checked={data.send_reminders}
                                onCheckedChange={(checked) => setData('send_reminders', checked === true)}
                                disabled={!canUpdate || processing}
                            />
                            <div className="grid gap-1">
                                <Label htmlFor="send_reminders">Send overdue reminders automatically</Label>
                                <p className="text-muted-foreground text-xs">
                                    Clients get a polite email the day after an invoice is due, then weekly, up to 3 reminders.
                                </p>
                            </div>
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
