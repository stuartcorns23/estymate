{{-- Who the document is for. --}}
<div class="label" @isset($labelStyle) style="{{ $labelStyle }}" @endisset>{{ $requestsPayment ? 'Bill to' : 'Prepared for' }}</div>
<strong>{{ $client->name }}</strong><br>
@if ($client->contact_name){{ $client->contact_name }}<br>@endif
@foreach ($client->addressLines() as $line){{ $line }}<br>@endforeach
@if ($client->vat_number)VAT: {{ $client->vat_number }}<br>@endif
