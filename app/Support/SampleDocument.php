<?php

namespace App\Support;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Business;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentItem;

/**
 * A made-up estimate or invoice for previewing a business's document design. Never saved.
 */
class SampleDocument
{
    public static function make(Business $business, DocumentType $type): Document
    {
        $client = new Client([
            'name' => 'Riverside Cafe Ltd',
            'contact_name' => 'Sam Taylor',
            'address_line_1' => '12 High Street',
            'city' => 'Bristol',
            'postcode' => 'BS1 4DJ',
        ]);

        $items = collect([
            ['Website design and build', '1', null, 120000, '20.00'],
            ['Content writing', '6', 'hrs', 4500, '20.00'],
            ['Printed menus', '200', null, 85, '0.00'],
        ])->map(function (array $line, int $index) {
            [$description, $quantity, $unit, $unitPrice, $taxRate] = $line;
            $net = (int) round((float) $quantity * $unitPrice);

            return new DocumentItem([
                'position' => $index,
                'description' => $description,
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_price' => $unitPrice,
                'tax_rate' => $taxRate,
                'net_amount' => $net,
                'tax_amount' => (int) round($net * (float) $taxRate / 100),
            ]);
        });

        $document = new Document([
            'issue_date' => now()->startOfDay(),
            'due_date' => $type->hasDueDate() ? now()->startOfDay()->addDays($type->requestsPayment() ? $business->payment_terms_days : 30) : null,
            'po_number' => 'PO-2291',
            'notes' => match ($type) {
                DocumentType::Estimate => 'This estimate is valid for 30 days.',
                DocumentType::CreditNote => 'Credit for the cancelled second phase.',
                DocumentType::DeliveryNote => 'Please check the goods and sign below.',
                default => $business->payment_details,
            },
            'delivery_address' => $type === DocumentType::DeliveryNote ? "Site office\nUnit 4, Riverside Park\nLeeds LS10 1AB" : null,
        ]);

        $prefix = $business->documentPrefix($type);

        $document->forceFill([
            'type' => $type,
            'status' => DocumentStatus::Sent,
            'number' => $prefix.'0042',
            'currency' => $business->currency ?: 'GBP',
            'subtotal' => $items->sum('net_amount'),
            'tax_total' => $items->sum('tax_amount'),
            'total' => $items->sum('net_amount') + $items->sum('tax_amount'),
            'amount_paid' => 0,
            'amount_credited' => 0,
        ]);

        $invoice = null;

        if ($type === DocumentType::CreditNote) {
            $invoice = (new Document(['issue_date' => now()->startOfDay()->subDays(14)]))
                ->forceFill(['type' => DocumentType::Invoice, 'number' => $business->documentPrefix(DocumentType::Invoice).'0041']);
        }

        $document->setRelation('source', $invoice);

        $document->setRelation('business', $business);
        $document->setRelation('client', $client);
        $document->setRelation('items', $items);

        return $document;
    }
}
