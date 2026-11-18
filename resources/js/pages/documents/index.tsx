import DocumentStatusBadge from '@/components/document-status-badge';
import PageHeader from '@/components/page-header';
import Pagination from '@/components/pagination';
import PeriodFilter, { periodParam } from '@/components/period-filter';
import SearchInput from '@/components/search-input';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type DocumentSummary, type DocumentTypeInfo, type Option, type Paginated, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { FileUp, Plus } from 'lucide-react';

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
        { value: 'credited', label: 'Credited' },
        { value: 'void', label: 'Void' },
    ],
    proforma: [
        { value: '', label: 'All' },
        { value: 'draft', label: 'Draft' },
        { value: 'sent', label: 'Awaiting payment' },
        { value: 'invoiced', label: 'Invoiced' },
        { value: 'void', label: 'Void' },
    ],
    creditnote: [
        { value: '', label: 'All' },
        { value: 'draft', label: 'Draft' },
        { value: 'sent', label: 'Issued' },
        { value: 'void', label: 'Void' },
    ],
    deliverynote: [
        { value: '', label: 'All' },
        { value: 'draft', label: 'Draft' },
        { value: 'sent', label: 'Sent' },
        { value: 'delivered', label: 'Delivered' },
    ],
};

interface DocumentsIndexProps {
    type: DocumentTypeInfo;
    documents: Paginated<DocumentSummary>;
    filters: { status: string; search: string; period: string };
    periods: Option[];
}

export default function DocumentsIndex({ type, documents, filters, periods }: DocumentsIndexProps) {
    const period = periodParam(filters.period, 'all');
    const title = `${type.label}s`;
    const breadcrumbs: BreadcrumbItem[] = [{ title, href: `/${type.plural}` }];
    const isInvoice = type.value === 'invoice';
    const requestsPayment = type.requests_payment;
    const { flash } = usePage<SharedData & { flash?: { status?: string } }>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="space-y-6 p-4">
                <PageHeader
                    title={title}
                    actions={
                        <>
                            {isInvoice && (
                                <Button variant="outline" asChild>
                                    <Link href={route('invoices.import')}>
                                        <FileUp /> Import
                                    </Link>
                                </Button>
                            )}
                            {type.value !== 'creditnote' && (
                                <Button asChild>
                                    <Link href={route(`${type.plural}.create`)}>
                                        <Plus /> New {type.label.toLowerCase()}
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                {type.value === 'proforma' && (
                    <p className="text-muted-foreground text-sm">
                        Pro-forma invoices ask for payment before the work or delivery. They aren't VAT invoices, so they stay out of your income, VAT
                        and tax return figures. Convert one into an invoice once it's paid or the work is done.
                    </p>
                )}

                {type.value === 'creditnote' && (
                    <p className="text-muted-foreground text-sm">
                        A credit note cancels all or part of an invoice. Once issued, it comes off the invoice's balance and your income and VAT. To
                        make one, open the invoice and choose "Credit note".
                    </p>
                )}

                {type.value === 'deliverynote' && (
                    <p className="text-muted-foreground text-sm">
                        Delivery notes list what you delivered, without prices, with space for the customer to sign. Make one from scratch, or from an
                        invoice, estimate or pro-forma.
                    </p>
                )}

                {flash?.status && (
                    <p className="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-900 dark:border-green-900 dark:bg-green-950 dark:text-green-100">
                        {flash.status}
                    </p>
                )}

                <div className="flex flex-wrap items-center gap-4">
                    <SearchInput
                        value={filters.search}
                        placeholder={`Search ${title.toLowerCase()}`}
                        params={{ ...(filters.status ? { status: filters.status } : {}), ...(period ? { period } : {}) }}
                    />
                    <PeriodFilter
                        value={filters.period}
                        periods={periods}
                        defaultValue="all"
                        params={{ status: filters.status || undefined, search: filters.search || undefined }}
                    />
                    <nav className="flex flex-wrap gap-1">
                        {statusFilters[type.value].map((filter) => (
                            <Link
                                key={filter.value}
                                href={route(`${type.plural}.index`, {
                                    status: filter.value || undefined,
                                    search: filters.search || undefined,
                                    period,
                                })}
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
                        {filters.search || filters.status || period ? `No ${title.toLowerCase()} match.` : `No ${title.toLowerCase()} yet.`}
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left">
                                <tr>
                                    <th className="px-4 py-2 font-medium">Number</th>
                                    <th className="px-4 py-2 font-medium">Client</th>
                                    <th className="hidden px-4 py-2 font-medium md:table-cell">Date</th>
                                    {type.has_due_date && (
                                        <th className="hidden px-4 py-2 font-medium md:table-cell">{requestsPayment ? 'Due' : 'Valid until'}</th>
                                    )}
                                    <th className="px-4 py-2 font-medium">Status</th>
                                    {type.shows_prices && <th className="px-4 py-2 text-right font-medium">Total</th>}
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
                                        {type.has_due_date && (
                                            <td className="hidden px-4 py-3 whitespace-nowrap md:table-cell">{formatDate(document.due_date)}</td>
                                        )}
                                        <td className="px-4 py-3">
                                            <DocumentStatusBadge status={document.status} overdue={document.is_overdue} />
                                        </td>
                                        {type.shows_prices && (
                                            <td className="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                                                {formatMoney(document.total, document.currency)}
                                            </td>
                                        )}
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
