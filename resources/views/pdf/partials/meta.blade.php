{{-- Dates and reference, as label/value rows. A UK VAT invoice must show its tax point. --}}
@php
    $rows = [[$isInvoice ? 'Invoice date' : 'Date', $document->issue_date->format('j M Y')]];
    if ($isInvoice) {
        $rows[] = ['Tax point', $document->issue_date->format('j M Y')];
    }
    if ($document->due_date) {
        $rows[] = [$isInvoice ? 'Due date' : 'Valid until', $document->due_date->format('j M Y')];
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
