{{-- The business's own footer text, then the legal details UK invoices should carry. --}}
<div class="footer">
    @if ($design->footer)
        <div class="footer-text">{{ $design->footer }}</div>
    @endif
    {{ $business->name }}
    @if ($business->company_number) · Company number {{ $business->company_number }}@endif
    @if ($business->vat_number) · VAT registration number {{ $business->vat_number }}@endif
</div>
