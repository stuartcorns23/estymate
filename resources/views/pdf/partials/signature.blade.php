{{-- "Issued by" with the business's signature, when it signs its documents. --}}
@if ($design->signature)
    @php([$signatureWidth, $signatureHeight] = $design->signatureSize(200, 70))
    <table class="w-full signed-by">
        <tr>
            <td></td>
            <td class="right" style="width: 240px;">
                <div class="label">Issued by, signature</div>
                <img src="{{ $design->signature }}" width="{{ $signatureWidth }}" height="{{ $signatureHeight }}" alt="Signature" style="display: block; margin-left: auto;">
                @if ($design->signatureName)
                    <div class="signature-name">{{ $design->signatureName }}</div>
                @endif
            </td>
        </tr>
    </table>
@endif
