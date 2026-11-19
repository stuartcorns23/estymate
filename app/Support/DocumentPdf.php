<?php

namespace App\Support;

use App\Enums\DocumentType;
use App\Models\Business;
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
        $businessAddress = array_values(array_filter([
            $business->address_line_1,
            $business->address_line_2,
            $business->city,
            $business->county,
            $business->postcode,
        ]));
        $footerLines = self::footerLines($business, $businessAddress, $design->footerDetails);

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
            'businessAddress' => $businessAddress,
            'footerLines' => $footerLines,
            'footerHeight' => self::footerHeight($footerLines, $design->footer),
        ])->render();
    }

    /**
     * The business details printed along the bottom of every page, as lines of [label, value]
     * pairs. A null label is printed bold with no label, like the business's name.
     *
     * @param  list<string>  $address
     * @return list<list<array{?string, string}>>
     */
    public static function footerLines(Business $business, array $address, bool $withDetails = true): array
    {
        $lines = [];

        if ($withDetails) {
            $lines[] = array_filter([
                [null, $business->name],
                $address ? ['', implode(', ', $address)] : null,
                $business->contact_name ? ['Contact person', $business->contact_name] : null,
            ]);
            $lines[] = array_filter([
                $business->phone ? ['Phone', $business->phone] : null,
                $business->website ? ['Web', $business->website] : null,
                $business->email ? ['Email', $business->email] : null,
                $business->bank_account_name ? ['Account holder', $business->bank_account_name] : null,
                $business->bank_name ? ['Bank', $business->bank_name] : null,
            ]);
            $lines[] = array_filter([
                $business->bank_sort_code ? ['Sort code', $business->bank_sort_code] : null,
                $business->bank_account_number ? ['Account No.', $business->bank_account_number] : null,
                $business->bank_iban ? ['IBAN', $business->bank_iban] : null,
                $business->bank_bic ? ['BIC/SWIFT', $business->bank_bic] : null,
            ]);
        } else {
            $lines[] = [[null, $business->name]];
        }

        // The legal details UK invoices should carry, whatever else is shown.
        $lines[] = array_filter([
            $business->company_number ? ['Company number', $business->company_number] : null,
            $business->vat_number ? ['VAT registration number', $business->vat_number] : null,
        ]);

        return array_values(array_map('array_values', array_filter($lines)));
    }

    /**
     * One footer line as HTML: labelled values spaced apart, the name in bold, the address after a comma.
     *
     * @param  list<array{?string, string}>  $line
     */
    public static function footerLineHtml(array $line): string
    {
        $html = '';

        foreach ($line as [$label, $value]) {
            $html .= match ($label) {
                null => ($html === '' ? '' : ' &nbsp; ').'<strong>'.e($value).'</strong>',
                '' => ($html === '' ? '' : ', ').e($value),
                default => ($html === '' ? '' : ' &nbsp; ').'<span class="footer-item">'.e($label).': <strong>'.e($value).'</strong></span>',
            };
        }

        return $html;
    }

    /**
     * Roughly how tall the footer is in pixels, so the page keeps room for it at the bottom.
     *
     * @param  list<list<array{?string, string}>>  $lines
     */
    private static function footerHeight(array $lines, ?string $footerText): int
    {
        // About 150 characters fit across the page at the footer's 8px size.
        $rows = 0;
        foreach ($lines as $line) {
            $text = implode('   ', array_map(fn ($part) => ($part[0] ? $part[0].': ' : '').$part[1], $line));
            $rows += max(1, (int) ceil(mb_strlen($text) / 150));
        }
        if ($footerText) {
            foreach (preg_split('/\R/', $footerText) as $textLine) {
                $rows += max(1, (int) ceil(mb_strlen($textLine) / 150));
            }
        }

        return $rows * 12 + ($footerText ? 4 : 0);
    }

    public static function filename(Document $document): string
    {
        return "{$document->type->label()} {$document->number}.pdf";
    }
}
