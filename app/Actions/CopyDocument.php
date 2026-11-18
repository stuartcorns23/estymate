<?php

namespace App\Actions;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use Illuminate\Support\Facades\DB;

class CopyDocument
{
    /**
     * Make a draft credit note or delivery note from another document, with the same client, PO
     * number, reference and lines, linked back to it.
     *
     * A credit note starts with all of the invoice's lines, to trim down to what's being credited.
     * A delivery note keeps the lines' prices so nothing is lost, but never shows them.
     */
    public function handle(Document $source, DocumentType $type): Document
    {
        return DB::transaction(function () use ($source, $type) {
            $business = $source->business;

            $document = new Document([
                'client_id' => $source->client_id,
                'issue_date' => today(),
                'reference' => $source->reference,
                'po_number' => $source->po_number,
                'notes' => $type === DocumentType::CreditNote ? "Credit against invoice {$source->number}." : null,
            ]);
            $document->business_id = $source->business_id;
            $document->type = $type;
            $document->status = DocumentStatus::Draft;
            $document->currency = $source->currency;
            $document->number = $business->claimDocumentNumber($type);
            $document->source_document_id = $source->id;
            $document->save();

            foreach ($source->items as $item) {
                $document->items()->create($item->replicate(['document_id'])->getAttributes());
            }

            $document->refreshTotals();
            $document->save();

            return $document;
        });
    }
}
