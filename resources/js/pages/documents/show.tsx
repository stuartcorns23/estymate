import DocumentStatusBadge from '@/components/document-status-badge';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney, formatRate } from '@/lib/money';
import { type BreadcrumbItem, type Client, type DocumentStatus, type DocumentSummary, type DocumentTypeInfo, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ChevronDown, Download, FileOutput, Mail, Pencil, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Item {
    id: number;
    description: string;
    quantity: string;
    unit: string | null;
    unit_price: number;
    tax_rate: string;
    net_amount: number;
}

interface DocumentDetail extends DocumentSummary {
    reference: string | null;
    notes: string | null;
    subtotal: number;
    tax_total: number;
    sent_at: string | null;
    client: Client;
    items: Item[];
    vat_breakdown: { rate: string; net: number; tax: number }[];
    source: { number: string; url: string } | null;
    converted_to: { number: string; url: string } | null;
    is_editable: boolean;
    is_deletable: boolean;
    email_message: string;
}

interface ShowDocumentProps {
    type: DocumentTypeInfo;
    document: DocumentDetail;
    statuses: { value: DocumentStatus; label: string }[];
}

function SendDialog({ type, document }: { type: DocumentTypeInfo; document: DocumentDetail }) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        email: document.client.email ?? '',
        message: document.email_message,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route(`${type.plural}.send`, document.id), {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Mail /> {document.sent_at ? 'Send again' : 'Send'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>
                            Email {type.label.toLowerCase()} {document.number}
                        </DialogTitle>
                        <DialogDescription>The PDF is attached. Replies go to your business email.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="send-email">To</Label>
                        <Input id="send-email" type="email" required value={data.email} onChange={(e) => setData('email', e.target.value)} />
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="send-message">Message</Label>
                        <Textarea id="send-message" rows={8} required value={data.message} onChange={(e) => setData('message', e.target.value)} />
                        <InputError message={errors.message} />
                    </div>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button disabled={processing}>Send</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function ShowDocument({ type, document, statuses }: ShowDocumentProps) {
    const { flash } = usePage<SharedData & { flash?: { status?: string } }>().props;
    const isInvoice = type.value === 'invoice';
    const label = type.label.toLowerCase();
    const money = (pence: number) => formatMoney(pence, document.currency);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: `${type.label}s`, href: `/${type.plural}` },
        { title: document.number, href: '#' },
    ];
    const canSend = !['void', 'invoiced'].includes(document.status);
    const canConvert = !isInvoice && !['invoiced', 'declined'].includes(document.status);

    const setStatus = (status: DocumentStatus) => {
        router.patch(route(`${type.plural}.status`, document.id), { status }, { preserveScroll: true });
    };

    const destroy = () => {
        if (confirm(`Delete ${label} ${document.number}?`)) {
            router.delete(route(`${type.plural}.destroy`, document.id));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${type.label} ${document.number}`} />

            <div className="max-w-5xl space-y-6 p-4">
                <PageHeader
                    title={`${type.label} ${document.number}`}
                    actions={
                        <>
                            {document.is_editable && (
                                <Button variant="outline" asChild>
                                    <Link href={route(`${type.plural}.edit`, document.id)}>
                                        <Pencil /> Edit
                                    </Link>
                                </Button>
                            )}
                            <Button variant="outline" asChild>
                                <a href={route(`${type.plural}.pdf`, document.id)}>
                                    <Download /> PDF
                                </a>
                            </Button>
                            {canConvert && (
                                <Button variant="outline" onClick={() => router.post(route('estimates.convert', document.id))}>
                                    <FileOutput /> Convert to invoice
                                </Button>
                            )}
                            {document.status !== 'invoiced' && (
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button variant="outline">
                                            Mark as <ChevronDown />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuLabel className="text-muted-foreground text-xs">Change status</DropdownMenuLabel>
                                        {statuses
                                            .filter((status) => status.value !== document.status)
                                            .map((status) => (
                                                <DropdownMenuItem key={status.value} onSelect={() => setStatus(status.value)}>
                                                    {status.label}
                                                </DropdownMenuItem>
                                            ))}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                            {canSend && <SendDialog type={type} document={document} />}
                        </>
                    }
                />

                <div className="flex flex-wrap items-center gap-3 text-sm">
                    <DocumentStatusBadge status={document.status} overdue={document.is_overdue} />
                    {document.sent_at && (
                        <span className="text-muted-foreground">Last sent {new Date(document.sent_at).toLocaleString('en-GB')}</span>
                    )}
                    {document.source && (
                        <span className="text-muted-foreground">
                            Created from{' '}
                            <Link href={document.source.url} className="underline">
                                estimate {document.source.number}
                            </Link>
                        </span>
                    )}
                    {document.converted_to && (
                        <span className="text-muted-foreground">
                            Converted to{' '}
                            <Link href={document.converted_to.url} className="underline">
                                invoice {document.converted_to.number}
                            </Link>
                        </span>
                    )}
                    {flash?.status && <span className="text-green-700 dark:text-green-400">{flash.status}</span>}
                </div>

                <div className="bg-card space-y-8 rounded-xl border p-6 shadow-xs">
                    <div className="grid gap-6 sm:grid-cols-2">
                        <div className="text-sm">
                            <p className="text-muted-foreground mb-1 text-xs tracking-wide uppercase">{isInvoice ? 'Bill to' : 'Prepared for'}</p>
                            <p className="font-medium">{document.client.name}</p>
                            {[
                                document.client.contact_name,
                                document.client.address_line_1,
                                document.client.address_line_2,
                                document.client.city,
                                document.client.county,
                                document.client.postcode,
                            ]
                                .filter(Boolean)
                                .map((line, i) => (
                                    <p key={i}>{line}</p>
                                ))}
                        </div>
                        <dl className="grid grid-cols-2 gap-y-1 text-sm sm:justify-self-end">
                            <dt className="text-muted-foreground">Date</dt>
                            <dd className="text-right">{formatDate(document.issue_date)}</dd>
                            {document.due_date && (
                                <>
                                    <dt className="text-muted-foreground">{isInvoice ? 'Due' : 'Valid until'}</dt>
                                    <dd className="text-right">{formatDate(document.due_date)}</dd>
                                </>
                            )}
                            {document.reference && (
                                <>
                                    <dt className="text-muted-foreground">Reference</dt>
                                    <dd className="text-right">{document.reference}</dd>
                                </>
                            )}
                        </dl>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground border-b text-left text-xs tracking-wide uppercase">
                                <tr>
                                    <th className="py-2 pr-4 font-medium">Description</th>
                                    <th className="py-2 pr-4 text-right font-medium">Qty</th>
                                    <th className="py-2 pr-4 text-right font-medium">Price</th>
                                    <th className="py-2 pr-4 text-right font-medium">VAT</th>
                                    <th className="py-2 text-right font-medium">Amount</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {document.items.map((item) => (
                                    <tr key={item.id}>
                                        <td className="py-3 pr-4 whitespace-pre-line">{item.description}</td>
                                        <td className="py-3 pr-4 text-right whitespace-nowrap tabular-nums">
                                            {Number(item.quantity)} {item.unit}
                                        </td>
                                        <td className="py-3 pr-4 text-right tabular-nums">{money(item.unit_price)}</td>
                                        <td className="py-3 pr-4 text-right tabular-nums">{formatRate(item.tax_rate)}</td>
                                        <td className="py-3 text-right tabular-nums">{money(item.net_amount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <dl className="ml-auto grid w-full max-w-xs grid-cols-2 gap-y-1 text-sm">
                        <dt className="text-muted-foreground">Subtotal</dt>
                        <dd className="text-right tabular-nums">{money(document.subtotal)}</dd>
                        {document.vat_breakdown.map((vat) => (
                            <div key={vat.rate} className="contents">
                                <dt className="text-muted-foreground">VAT {formatRate(vat.rate)}</dt>
                                <dd className="text-right tabular-nums">{money(vat.tax)}</dd>
                            </div>
                        ))}
                        <dt className="border-t pt-2 font-semibold">Total</dt>
                        <dd className="border-t pt-2 text-right font-semibold tabular-nums">{money(document.total)}</dd>
                    </dl>

                    {document.notes && <p className="text-muted-foreground text-sm whitespace-pre-line">{document.notes}</p>}
                </div>

                {document.is_deletable && (
                    <Button variant="ghost" className="text-destructive" onClick={destroy}>
                        <Trash2 /> Delete draft
                    </Button>
                )}
            </div>
        </AppLayout>
    );
}
