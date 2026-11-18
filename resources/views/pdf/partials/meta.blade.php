{{-- Dates, PO number and reference, as label/value rows. A UK VAT invoice must show its tax point; a pro-forma has none. A credit note names the invoice it credits. --}}
@php
    $dateLabel = match (true) {
        $isInvoice => 'Invoice date',
        $isCreditNote => 'Credit note date',
        $isDeliveryNote => 'Delivery date',
        default => 'Date',
    };
    $rows = [[$dateLabel, $document->issue_date->format('j M Y')]];
    if ($isInvoice || $isCreditNote) {
        $rows[] = ['Tax point', $document->issue_date->format('j M Y')];
    }
    if ($creditedInvoice) {
        $rows[] = ['Credits invoice', $creditedInvoice->number];
        $rows[] = ['Invoice date', $creditedInvoice->issue_date->format('j M Y')];
    }
    if ($document->due_date) {
        $rows[] = [$isInvoice ? 'Due date' : ($requestsPayment ? 'Payment due' : 'Valid until'), $document->due_date->format('j M Y')];
    }
    if ($document->po_number) {
        $rows[] = ['PO number', $document->po_number];
    }
    if ($document->reference) {
        $rows[] = ['Reference', $document->reference];
    }
@endphp
<table class="meta" style="{{ $tableStyle ?? 'margin-left: auto' }}">
    @foreach ($rows as [$metaLabel, $metaValue])
        <tr><td class="muted" style="{{ $labelCellStyle ?? '' }}">{{ $metaLabel }}</td><td class="right">{{ $metaValue }}</td></tr>
    @endforeach
</table>
