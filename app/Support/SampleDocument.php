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
            'due_date' => now()->startOfDay()->addDays($type->requestsPayment() ? $business->payment_terms_days : 30),
            'reference' => 'PO-2291',
            'notes' => $type->requestsPayment() ? $business->payment_details : 'This estimate is valid for 30 days.',
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
        ]);

        $document->setRelation('business', $business);
        $document->setRelation('client', $client);
        $document->setRelation('items', $items);

        return $document;
    }
}
