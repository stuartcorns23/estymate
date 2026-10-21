import DocumentStatusBadge from '@/components/document-status-badge';
import { Button } from '@/components/ui/button';
import { formatDate, formatMoney, formatRate } from '@/lib/money';
import { type DocumentStatus } from '@/types';
import { Head, router } from '@inertiajs/react';
import { CheckCircle2, CreditCard, Download } from 'lucide-react';
import { useState } from 'react';

interface PublicDocumentProps {
    document: {
        type: string;
        number: string;
        status: DocumentStatus;
        is_overdue: boolean;
        issue_date: string;
        due_date: string | null;
        currency: string;
        subtotal: number;
        tax_total: number;
        total: number;
        amount_paid: number;
        balance_due: number | null;
        notes: string | null;
        items: { id: number; description: string; quantity: string; unit: string | null; unit_price: number; tax_rate: string; net_amount: number }[];
        business: { name: string; email: string | null; phone: string | null };
        client_name: string;
        can_pay: boolean;
        pdf_url: string;
        pay_url: string;
    };
    justPaid: boolean;
}

export default function PublicDocument({ document, justPaid }: PublicDocumentProps) {
    const [paying, setPaying] = useState(false);
    const money = (pence: number) => formatMoney(pence, document.currency);
    const isInvoice = document.type === 'Invoice';

    const pay = () => {
        setPaying(true);
        router.post(document.pay_url, {}, { onFinish: () => setPaying(false) });
    };

    return (
        <>
            <Head title={`${document.type} ${document.number} from ${document.business.name}`} />
            <div className="bg-muted/40 text-foreground min-h-screen px-4 py-10">
                <div className="mx-auto max-w-3xl space-y-6">
                    <header className="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <p className="text-muted-foreground text-sm">{document.business.name}</p>
                            <h1 className="text-2xl font-semibold">
                                {document.type} {document.number}
                            </h1>
                        </div>
                        <div className="flex gap-2">
                            <Button variant="outline" asChild>
                                <a href={document.pdf_url}>
                                    <Download /> PDF
                                </a>
                            </Button>
                            {document.can_pay && !justPaid && (
                                <Button onClick={pay} disabled={paying}>
                                    <CreditCard /> Pay {money(document.balance_due ?? 0)}
                                </Button>
                            )}
                        </div>
                    </header>

                    {justPaid && (
                        <div className="flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-900 dark:border-green-900 dark:bg-green-950 dark:text-green-100">
                            <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
                            <p>Thank you, your payment was received. {document.business.name} will see it straight away.</p>
                        </div>
                    )}

                    <div className="bg-card space-y-8 rounded-xl border p-6 shadow-xs">
                        <div className="flex flex-wrap justify-between gap-6 text-sm">
                            <div>
                                <p className="text-muted-foreground mb-1 text-xs tracking-wide uppercase">{isInvoice ? 'Bill to' : 'Prepared for'}</p>
                                <p className="font-medium">{document.client_name}</p>
                            </div>
                            <dl className="grid grid-cols-2 gap-x-6 gap-y-1">
                                <dt className="text-muted-foreground">Date</dt>
                                <dd className="text-right">{formatDate(document.issue_date)}</dd>
                                {document.due_date && (
                                    <>
                                        <dt className="text-muted-foreground">{isInvoice ? 'Due' : 'Valid until'}</dt>
                                        <dd className="text-right">{formatDate(document.due_date)}</dd>
                                    </>
                                )}
                                <dt className="text-muted-foreground">Status</dt>
                                <dd className="text-right">
                                    <DocumentStatusBadge status={document.status} overdue={document.is_overdue} />
                                </dd>
                            </dl>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground border-b text-left text-xs tracking-wide uppercase">
                                    <tr>
                                        <th className="py-2 pr-4 font-medium">Description</th>
                                        <th className="py-2 pr-4 text-right font-medium">Qty</th>
                                        <th className="hidden py-2 pr-4 text-right font-medium sm:table-cell">Price</th>
                                        <th className="hidden py-2 pr-4 text-right font-medium sm:table-cell">VAT</th>
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
                                            <td className="hidden py-3 pr-4 text-right tabular-nums sm:table-cell">{money(item.unit_price)}</td>
                                            <td className="hidden py-3 pr-4 text-right tabular-nums sm:table-cell">{formatRate(item.tax_rate)}</td>
                                            <td className="py-3 text-right tabular-nums">{money(item.net_amount)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <dl className="ml-auto grid w-full max-w-xs grid-cols-2 gap-y-1 text-sm">
                            <dt className="text-muted-foreground">Subtotal</dt>
                            <dd className="text-right tabular-nums">{money(document.subtotal)}</dd>
                            <dt className="text-muted-foreground">VAT</dt>
                            <dd className="text-right tabular-nums">{money(document.tax_total)}</dd>
                            <dt className="border-t pt-2 font-semibold">Total</dt>
                            <dd className="border-t pt-2 text-right font-semibold tabular-nums">{money(document.total)}</dd>
                            {document.amount_paid > 0 && document.balance_due !== null && (
                                <>
                                    <dt className="text-muted-foreground">Paid</dt>
                                    <dd className="text-right tabular-nums">−{money(document.amount_paid)}</dd>
                                    <dt className="font-semibold">Balance due</dt>
                                    <dd className="text-right font-semibold tabular-nums">{money(document.balance_due)}</dd>
                                </>
                            )}
                        </dl>

                        {document.notes && <p className="text-muted-foreground text-sm whitespace-pre-line">{document.notes}</p>}
                    </div>

                    <p className="text-muted-foreground text-center text-xs">
                        Questions? Contact {document.business.name}
                        {document.business.email && (
                            <>
                                {' '}
                                at{' '}
                                <a href={`mailto:${document.business.email}`} className="underline">
                                    {document.business.email}
                                </a>
                            </>
                        )}
                        {document.business.phone && ` or ${document.business.phone}`}.
                    </p>
                </div>
            </div>
        </>
    );
}
