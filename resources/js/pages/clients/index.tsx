import PageHeader from '@/components/page-header';
import Pagination from '@/components/pagination';
import SearchInput from '@/components/search-input';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Client, type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Clients', href: '/clients' }];

export default function ClientsIndex({ clients, filters }: { clients: Paginated<Client>; filters: { search: string } }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Clients" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Clients"
                    description="The people and businesses you send estimates and invoices to"
                    actions={
                        <Button asChild>
                            <Link href={route('clients.create')}>
                                <Plus /> Add client
                            </Link>
                        </Button>
                    }
                />

                <SearchInput value={filters.search} placeholder="Search clients" />

                {clients.data.length === 0 ? (
                    <div className="text-muted-foreground rounded-lg border border-dashed p-10 text-center text-sm">
                        {filters.search
                            ? 'No clients match your search.'
                            : 'No clients yet. Add your first client to start sending estimates and invoices.'}
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left">
                                <tr>
                                    <th className="px-4 py-2 font-medium">Name</th>
                                    <th className="hidden px-4 py-2 font-medium md:table-cell">Contact</th>
                                    <th className="hidden px-4 py-2 font-medium sm:table-cell">Email</th>
                                    <th className="px-4 py-2 text-right font-medium">Documents</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {clients.data.map((client) => (
                                    <tr key={client.id} className="hover:bg-muted/30">
                                        <td className="px-4 py-3">
                                            <Link href={route('clients.edit', client.id)} className="font-medium hover:underline">
                                                {client.name}
                                            </Link>
                                        </td>
                                        <td className="hidden px-4 py-3 md:table-cell">{client.contact_name}</td>
                                        <td className="text-muted-foreground hidden px-4 py-3 sm:table-cell">{client.email}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{client.documents_count}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <Pagination data={clients} />
            </div>
        </AppLayout>
    );
}
