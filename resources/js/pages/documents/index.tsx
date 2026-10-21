import DocumentStatusBadge from '@/components/document-status-badge';
import PageHeader from '@/components/page-header';
import Pagination from '@/components/pagination';
import SearchInput from '@/components/search-input';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type DocumentSummary, type DocumentTypeInfo, type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';

const statusFilters: Record<DocumentTypeInfo['value'], { value: string; label: string }[]> = {
    estimate: [
        { value: '', label: 'All' },
        { value: 'draft', label: 'Draft' },
        { value: 'sent', label: 'Sent' },
        { value: 'accepted', label: 'Accepted' },
        { value: 'declined', label: 'Declined' },
        { value: 'invoiced', label: 'Invoiced' },
    ],
    invoice: [
        { value: '', label: 'All' },
        { value: 'draft', label: 'Draft' },
        { value: 'sent', label: 'Unpaid' },
        { value: 'overdue', label: 'Overdue' },
        { value: 'paid', label: 'Paid' },
        { value: 'void', label: 'Void' },
    ],
};

interface DocumentsIndexProps {
    type: DocumentTypeInfo;
    documents: Paginated<DocumentSummary>;
    filters: { status: string; search: string };
}

export default function DocumentsIndex({ type, documents, filters }: DocumentsIndexProps) {
    const title = `${type.label}s`;
    const breadcrumbs: BreadcrumbItem[] = [{ title, href: `/${type.plural}` }];
    const isInvoice = type.value === 'invoice';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="space-y-6 p-4">
                <PageHeader
                    title={title}
                    actions={
                        <Button asChild>
                            <Link href={route(`${type.plural}.create`)}>
                                <Plus /> New {type.label.toLowerCase()}
                            </Link>
                        </Button>
                    }
                />

                <div className="flex flex-wrap items-center gap-4">
                    <SearchInput
                        value={filters.search}
                        placeholder={`Search ${title.toLowerCase()}`}
                        params={filters.status ? { status: filters.status } : {}}
                    />
                    <nav className="flex flex-wrap gap-1">
                        {statusFilters[type.value].map((filter) => (
                            <Link
                                key={filter.value}
                                href={route(`${type.plural}.index`, { status: filter.value || undefined, search: filters.search || undefined })}
                                className={cn(
                                    'rounded-md px-3 py-1.5 text-sm',
                                    filters.status === filter.value ? 'bg-muted font-medium' : 'text-muted-foreground hover:text-foreground',
                                )}
                                preserveState
                            >
                                {filter.label}
                            </Link>
                        ))}
                    </nav>
                </div>

                {documents.data.length === 0 ? (
                    <div className="text-muted-foreground rounded-lg border border-dashed p-10 text-center text-sm">
                        {filters.search || filters.status ? `No ${title.toLowerCase()} match.` : `No ${title.toLowerCase()} yet.`}
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left">
                                <tr>
                                    <th className="px-4 py-2 font-medium">Number</th>
                                    <th className="px-4 py-2 font-medium">Client</th>
                                    <th className="hidden px-4 py-2 font-medium md:table-cell">Date</th>
                                    <th className="hidden px-4 py-2 font-medium md:table-cell">{isInvoice ? 'Due' : 'Valid until'}</th>
                                    <th className="px-4 py-2 font-medium">Status</th>
                                    <th className="px-4 py-2 text-right font-medium">Total</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {documents.data.map((document) => (
                                    <tr key={document.id} className="hover:bg-muted/30">
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            <Link href={route(`${type.plural}.show`, document.id)} className="font-medium hover:underline">
                                                {document.number}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">{document.client_name}</td>
                                        <td className="hidden px-4 py-3 whitespace-nowrap md:table-cell">{formatDate(document.issue_date)}</td>
                                        <td className="hidden px-4 py-3 whitespace-nowrap md:table-cell">{formatDate(document.due_date)}</td>
                                        <td className="px-4 py-3">
                                            <DocumentStatusBadge status={document.status} overdue={document.is_overdue} />
                                        </td>
                                        <td className="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                                            {formatMoney(document.total, document.currency)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <Pagination data={documents} />
            </div>
        </AppLayout>
    );
}
