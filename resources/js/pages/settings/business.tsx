import { type BreadcrumbItem, type Business } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import BusinessFields, { type BusinessForm } from '@/components/business/business-fields';
import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
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
