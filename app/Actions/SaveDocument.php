<?php

namespace App\Actions;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class SaveDocument
{
    /**
     * Create or update a document with its line items and totals.
     *
     * @param  array{client_id: int, issue_date: string, due_date?: ?string, reference?: ?string, notes?: ?string, items: list<array{product_id?: ?int, description: string, quantity: string|float, unit?: ?string, unit_price: string|float, tax_rate: string|float}>}  $data
     */
    public function handle(Document $document, DocumentType $type, array $data): Document
    {
        return DB::transaction(function () use ($document, $type, $data) {
            $document->fill($data);

            if (! $document->exists) {
                $business = auth()->user()->currentBusiness;

                $document->type = $type;
                $document->status = $type->initialStatus();
                $document->currency = $business->currency;
                $document->number = $business->claimDocumentNumber($type);
            }

            $document->save();
            $document->items()->delete();

            foreach (array_values($data['items']) as $position => $item) {
                $unitPrice = Money::toPence($item['unit_price']);

                $document->items()->create([
                    'product_id' => $item['product_id'] ?? null,
                    'position' => $position,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'] ?? null,
                    'unit_price' => $unitPrice,
                    'tax_rate' => $item['tax_rate'],
                    ...DocumentItem::amountsFor($item['quantity'], $unitPrice, $item['tax_rate']),
                ]);
            }

            $document->refreshTotals();
            $document->save();

            return $document;
        });
    }
}
