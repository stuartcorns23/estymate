@if ($isProForma)
    {{-- HMRC: a pro-forma isn't a VAT invoice, so the client can't reclaim VAT from it. --}}
    <div class="notes proforma-notice" style="font-weight: bold;">This is a pro-forma invoice, not a VAT invoice. A full invoice will be issued once payment is received.</div>
@endif
@if ($document->notes)
    <div class="notes">{{ $document->notes }}</div>
@endif
