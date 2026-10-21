<?php

namespace App\Actions;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use Illuminate\Support\Facades\DB;

class ConvertEstimateToInvoice
{
    /**
     * Create a draft invoice from an estimate and mark the estimate as invoiced.
     */
    public function handle(Document $estimate): Document
    {
        return DB::transaction(function () use ($estimate) {
            $business = $estimate->business;

            $invoice = $estimate->replicate(['number', 'status', 'type', 'sent_at', 'paid_at', 'source_document_id', 'public_token']);
            $invoice->type = DocumentType::Invoice;
            $invoice->status = DocumentType::Invoice->initialStatus();
            $invoice->number = $business->claimDocumentNumber(DocumentType::Invoice);
            $invoice->issue_date = today();
            $invoice->due_date = today()->addDays($business->payment_terms_days);
            $invoice->source_document_id = $estimate->id;
            $invoice->save();

            foreach ($estimate->items as $item) {
                $invoice->items()->create($item->replicate(['document_id'])->getAttributes());
            }

            $estimate->status = DocumentStatus::Invoiced;
            $estimate->save();

            return $invoice;
        });
    }
}
