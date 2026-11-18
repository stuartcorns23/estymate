@if ($isProForma)
    {{-- HMRC: a pro-forma isn't a VAT invoice, so the client can't reclaim VAT from it. --}}
    <div class="notes proforma-notice" style="font-weight: bold;">This is a pro-forma invoice, not a VAT invoice. A full invoice will be issued once payment is received.</div>
@endif
@if ($document->notes)
    <div class="notes">{{ $document->notes }}</div>
@endif
@if ($isDeliveryNote)
    {{-- Room for the customer to sign for the goods. --}}
    <table class="w-full signature" style="margin-top: 36px;">
        <tr>
            <td style="width: 40%; padding-right: 24px;"><div style="border-bottom: 1px solid #9ca3af; height: 28px;"></div><div class="label" style="margin-top: 4px;">Received by (print name)</div></td>
            <td style="width: 40%; padding-right: 24px;"><div style="border-bottom: 1px solid #9ca3af; height: 28px;"></div><div class="label" style="margin-top: 4px;">Signature</div></td>
            <td><div style="border-bottom: 1px solid #9ca3af; height: 28px;"></div><div class="label" style="margin-top: 4px;">Date</div></td>
        </tr>
    </table>
@endif
