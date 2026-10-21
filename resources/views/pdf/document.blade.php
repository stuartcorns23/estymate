@php
    use App\Enums\DocumentType;
    use App\Support\Money;

    $business = $document->business;
    $client = $document->client;
    $money = fn (int $pence) => Money::format($pence, $document->currency);
    $businessAddress = array_filter([$business->address_line_1, $business->address_line_2, $business->city, $business->county, $business->postcode]);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $document->type->label() }} {{ $document->number }}</title>
    <style>
        @page { margin: 40px 48px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; line-height: 1.45; }
        h1 { font-size: 22px; margin: 0 0 4px; letter-spacing: 0.5px; }
        .muted { color: #6b7280; }
        .header, .parties, .totals-wrap { width: 100%; border-collapse: collapse; }
        .header td, .parties td { vertical-align: top; padding: 0; }
        .right { text-align: right; }
        .meta td { padding: 1px 0 1px 12px; }
        .parties { margin: 28px 0 20px; }
        .label { font-size: 8px; text-transform: uppercase; letter-spacing: 0.6px; color: #6b7280; margin-bottom: 4px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.items th { font-size: 8px; text-transform: uppercase; letter-spacing: 0.6px; color: #6b7280; text-align: left; border-bottom: 1px solid #d1d5db; padding: 6px 4px; }
        table.items td { border-bottom: 1px solid #f0f0f0; padding: 7px 4px; vertical-align: top; }
        table.items .num { text-align: right; white-space: nowrap; }
        table.totals { margin-left: auto; margin-top: 12px; border-collapse: collapse; min-width: 240px; }
        table.totals td { padding: 3px 4px; }
        table.totals .grand td { border-top: 1px solid #1f2937; font-size: 12px; font-weight: bold; padding-top: 6px; }
        .notes { margin-top: 28px; white-space: pre-line; }
        .footer { margin-top: 36px; font-size: 8px; color: #6b7280; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <h1>{{ strtoupper($document->type->label()) }}</h1>
                <div class="muted">{{ $document->number }}</div>
            </td>
            <td class="right">
                <strong>{{ $business->name }}</strong><br>
                @foreach ($businessAddress as $line){{ $line }}<br>@endforeach
                @if ($business->email){{ $business->email }}<br>@endif
                @if ($business->phone){{ $business->phone }}<br>@endif
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td style="width: 55%">
                <div class="label">{{ $document->type === DocumentType::Invoice ? 'Bill to' : 'Prepared for' }}</div>
                <strong>{{ $client->name }}</strong><br>
                @if ($client->contact_name){{ $client->contact_name }}<br>@endif
                @foreach ($client->addressLines() as $line){{ $line }}<br>@endforeach
                @if ($client->vat_number)VAT: {{ $client->vat_number }}<br>@endif
            </td>
            <td class="right">
                <table class="meta" style="margin-left: auto">
                    <tr><td class="muted">{{ $document->type === DocumentType::Invoice ? 'Invoice date' : 'Date' }}</td><td class="right">{{ $document->issue_date->format('j M Y') }}</td></tr>
                    @if ($document->type === DocumentType::Invoice)
                        <tr><td class="muted">Tax point</td><td class="right">{{ $document->issue_date->format('j M Y') }}</td></tr>
                    @endif
                    @if ($document->due_date)
                        <tr><td class="muted">{{ $document->type === DocumentType::Invoice ? 'Due date' : 'Valid until' }}</td><td class="right">{{ $document->due_date->format('j M Y') }}</td></tr>
                    @endif
                    @if ($document->reference)
                        <tr><td class="muted">Reference</td><td class="right">{{ $document->reference }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Qty</th>
                <th class="num">Unit price</th>
                <th class="num">VAT</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($document->items as $item)
                <tr>
                    <td style="white-space: pre-line">{{ $item->description }}</td>
                    <td class="num">{{ rtrim(rtrim($item->quantity, '0'), '.') }}{{ $item->unit ? ' '.$item->unit : '' }}</td>
                    <td class="num">{{ $money($item->unit_price) }}</td>
                    <td class="num">{{ rtrim(rtrim($item->tax_rate, '0'), '.') }}%</td>
                    <td class="num">{{ $money($item->net_amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td class="muted">Subtotal</td><td class="right">{{ $money($document->subtotal) }}</td></tr>
        @foreach ($document->vatBreakdown() as $vat)
            <tr><td class="muted">VAT {{ rtrim(rtrim($vat['rate'], '0'), '.') }}% on {{ $money($vat['net']) }}</td><td class="right">{{ $money($vat['tax']) }}</td></tr>
        @endforeach
        <tr class="grand"><td>Total {{ $document->currency }}</td><td class="right">{{ $money($document->total) }}</td></tr>
    </table>

    @if ($document->notes)
        <div class="notes">{{ $document->notes }}</div>
    @endif

    <div class="footer">
        {{ $business->name }}
        @if ($business->company_number) · Company number {{ $business->company_number }}@endif
        @if ($business->vat_number) · VAT registration number {{ $business->vat_number }}@endif
    </div>
</body>
</html>
