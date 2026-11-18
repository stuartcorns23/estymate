<?php

namespace App\Support;

use App\Enums\DocumentType;
use App\Models\Document;
use Barryvdh\DomPDF\Facade\Pdf;

class DocumentPdf
{
    /**
     * Render the document as a PDF and return its bytes.
     */
    public static function render(Document $document, ?DocumentDesign $design = null): string
    {
        return Pdf::loadHTML(self::html($document, $design))
            ->setPaper('a4')
            ->output();
    }

    /**
     * The document's HTML in the business's chosen layout. With $preview, it's styled to look like
     * an A4 page in a browser, for the live preview in settings.
     */
    public static function html(Document $document, ?DocumentDesign $design = null, bool $preview = false): string
    {
        $document->loadMissing(['business', 'client', 'items', 'source']);

        $design ??= DocumentDesign::forBusiness($document->business);

        $business = $document->business;

        return view("pdf.layouts.{$design->layout->value}", [
            'document' => $document,
            'business' => $business,
            'client' => $document->client,
            'design' => $design,
            'preview' => $preview,
            'isInvoice' => $document->type === DocumentType::Invoice,
            'isProForma' => $document->type === DocumentType::ProForma,
            // Invoices and pro-formas ask for payment by a due date; estimates are valid until one.
            'requestsPayment' => $document->type->requestsPayment(),
            'isCreditNote' => $document->type === DocumentType::CreditNote,
            'isDeliveryNote' => $document->type === DocumentType::DeliveryNote,
            'showsPrices' => $document->type->showsPrices(),
            // The invoice a credit note credits, which UK credit notes must name.
            'creditedInvoice' => $document->type === DocumentType::CreditNote ? $document->source : null,
            'money' => fn (int $pence) => Money::format($pence, $document->currency),
            'number' => fn ($value) => rtrim(rtrim((string) $value, '0'), '.'),
            'businessAddress' => array_values(array_filter([
                $business->address_line_1,
                $business->address_line_2,
                $business->city,
                $business->county,
                $business->postcode,
            ])),
        ])->render();
    }

    public static function filename(Document $document): string
    {
        return "{$document->type->label()} {$document->number}.pdf";
    }
}
