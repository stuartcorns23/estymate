{{-- Who the document is for. A delivery note also says where the goods went, when that's somewhere else. --}}
<div class="label" @isset($labelStyle) style="{{ $labelStyle }}" @endisset>{{ $requestsPayment || $isCreditNote ? 'Bill to' : ($isDeliveryNote ? 'Customer' : 'Prepared for') }}</div>
<strong>{{ $client->name }}</strong><br>
@if ($client->contact_name){{ $client->contact_name }}<br>@endif
@foreach ($client->addressLines() as $line){{ $line }}<br>@endforeach
@if ($client->vat_number)VAT: {{ $client->vat_number }}<br>@endif
@if ($isDeliveryNote && $document->delivery_address)
    <div class="label" style="margin-top: 10px; {{ $labelStyle ?? '' }}">Deliver to</div>
    <div style="white-space: pre-line">{{ $document->delivery_address }}</div>
@endif
