{{-- Subtotal, VAT by rate, total and any balance due. Layouts style the .grand row. --}}
<table class="totals">
    <tr><td class="muted">Subtotal</td><td class="right">{{ $money($document->subtotal) }}</td></tr>
    @foreach ($document->vatBreakdown() as $vat)
        <tr><td class="muted">VAT {{ $number($vat['rate']) }}% on {{ $money($vat['net']) }}</td><td class="right">{{ $money($vat['tax']) }}</td></tr>
    @endforeach
    <tr class="grand"><td>Total {{ $document->currency }}</td><td class="right">{{ $money($document->total) }}</td></tr>
    @if ($document->amount_paid > 0)
        <tr><td class="muted">Paid</td><td class="right">-{{ $money($document->amount_paid) }}</td></tr>
        <tr class="balance"><td><strong>Balance due</strong></td><td class="right"><strong>{{ $money($document->balanceDue()) }}</strong></td></tr>
    @endif
</table>
