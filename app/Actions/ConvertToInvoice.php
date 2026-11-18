<?php

namespace App\Actions;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class ConvertToInvoice
{
    /**
     * Create an invoice from an estimate or a pro-forma invoice, and mark the original as invoiced.
     *
     * Without a payment the invoice is a draft to check and send. With one (a paid pro-forma), the
     * invoice is issued today and the payment is recorded on it, since a payment received for a
     * pro-forma is the point a real VAT invoice is owed.
     *
     * @param  array{amount: int, paid_on: string, method: string, reference?: ?string}|null  $payment  amount in pence
     */
    public function handle(Document $source, ?array $payment = null): Document
    {
        return DB::transaction(function () use ($source, $payment) {
            $business = $source->business;

            $invoice = $source->replicate([
                'number', 'status', 'type', 'sent_at', 'paid_at', 'source_document_id', 'public_token',
                'amount_paid', 'amount_credited', 'last_reminder_at', 'delivery_address',
            ]);
            $invoice->type = DocumentType::Invoice;
            $invoice->status = DocumentType::Invoice->initialStatus();
            $invoice->number = $business->claimDocumentNumber(DocumentType::Invoice);
            $invoice->issue_date = today();
            $invoice->due_date = $payment ? today() : today()->addDays($business->payment_terms_days);
            $invoice->source_document_id = $source->id;

            if ($payment) {
                $invoice->status = DocumentStatus::Sent;
            }

            $invoice->save();

            foreach ($source->items as $item) {
                $invoice->items()->create($item->replicate(['document_id'])->getAttributes());
            }

            if ($payment) {
                $invoice->payments()->save(new Payment($payment));
                $invoice->refreshPayments();
            }

            $source->status = DocumentStatus::Invoiced;
            $source->save();

            return $invoice;
        });
    }
}
