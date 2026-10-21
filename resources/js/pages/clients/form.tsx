import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Client } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type ClientField = Exclude<keyof Client, 'id' | 'documents_count'>;

const fields: { name: ClientField; label: string; type?: string; autoComplete?: string; wide?: boolean }[] = [
    { name: 'name', label: 'Client or business name', autoComplete: 'organization', wide: true },
    { name: 'contact_name', label: 'Contact name', autoComplete: 'name' },
    { name: 'email', label: 'Email', type: 'email', autoComplete: 'email' },
    { name: 'phone', label: 'Phone', type: 'tel', autoComplete: 'tel' },
    { name: 'vat_number', label: 'VAT number' },
    { name: 'address_line_1', label: 'Address line 1', autoComplete: 'address-line1' },
    { name: 'address_line_2', label: 'Address line 2', autoComplete: 'address-line2' },
    { name: 'city', label: 'Town or city', autoComplete: 'address-level2' },
    { name: 'county', label: 'County', autoComplete: 'address-level1' },
    { name: 'postcode', label: 'Postcode', autoComplete: 'postal-code' },
];

export default function ClientForm({ client }: { client: Client | null }) {
    const title = client ? `Edit ${client.name}` : 'Add client';
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Clients', href: '/clients' },
        { title: client ? client.name : 'Add client', href: '#' },
    ];

    const { data, setData, post, put, processing, errors } = useForm<Record<ClientField, string>>({
        name: client?.name ?? '',
        contact_name: client?.contact_name ?? '',
        email: client?.email ?? '',
        phone: client?.phone ?? '',
        vat_number: client?.vat_number ?? '',
        address_line_1: client?.address_line_1 ?? '',
        address_line_2: client?.address_line_2 ?? '',
        city: client?.city ?? '',
        county: client?.county ?? '',
        postcode: client?.postcode ?? '',
        notes: client?.notes ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (client) {
            put(route('clients.update', client.id));
        } else {
            post(route('clients.store'));
        }
    };

    const destroy = () => {
        if (client && confirm(`Delete ${client.name}? Their existing estimates and invoices are kept.`)) {
            router.delete(route('clients.destroy', client.id));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <form onSubmit={submit} className="max-w-3xl space-y-6 p-4">
                <PageHeader
                    title={title}
                    actions={
                        client && (
                            <>
                                <Button variant="outline" asChild>
                                    <Link href={`${route('estimates.create')}?client_id=${client.id}`}>New estimate</Link>
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href={`${route('invoices.create')}?client_id=${client.id}`}>New invoice</Link>
                                </Button>
                            </>
                        )
                    }
                />

                <div className="grid gap-6 sm:grid-cols-2">
                    {fields.map((field) => (
                        <div key={field.name} className={field.wide ? 'grid gap-2 sm:col-span-2' : 'grid gap-2'}>
                            <Label htmlFor={field.name}>{field.label}</Label>
                            <Input
                                id={field.name}
                                type={field.type ?? 'text'}
                                required={field.name === 'name'}
                                autoComplete={field.autoComplete}
                                value={data[field.name]}
                                onChange={(e) => setData(field.name, e.target.value)}
                            />
                            <InputError message={errors[field.name]} />
                        </div>
                    ))}

                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="notes">Private notes</Label>
                        <Textarea id="notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                        <InputError message={errors.notes} />
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    <Button disabled={processing}>{client ? 'Save' : 'Add client'}</Button>
                    <Button variant="ghost" asChild>
                        <Link href={route('clients.index')}>Cancel</Link>
                    </Button>
                    {client && (
                        <Button type="button" variant="ghost" className="text-destructive ml-auto" onClick={destroy}>
                            Delete
                        </Button>
                    )}
                </div>
            </form>
        </AppLayout>
    );
}
