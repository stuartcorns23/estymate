{{-- Bold: a big title and a coloured "amount due" panel at the top. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    @include('pdf.partials.head')
    <style>
        h1 { font-size: 40px; line-height: 1; letter-spacing: -1px; color: {{ $design->colourText() }}; margin-top: 18px; }
        .number { font-size: 11px; color: {{ $design->accentText() }}; font-weight: bold; margin-top: 4px; }
        .due { background: {{ $design->colour }}; color: {{ $design->onColour() }}; padding: 14px 16px; }
        .due .due-label { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.85; }
        .due .due-amount { font-size: 22px; font-weight: bold; margin: 2px 0 4px; }
        .rule { height: 6px; background: {{ $design->accent }}; margin: 24px 0 22px; }
        .parties { margin-bottom: 22px; }
        table.items th { background: {{ $design->colour }}; color: {{ $design->onColour() }}; }
        table.items td { border-bottom: 1px solid #e5e7eb; }
        table.totals { margin-top: 12px; }
        table.totals .grand td { font-size: 14px; font-weight: bold; color: {{ $design->colourText() }}; border-top: 2px solid {{ $design->colour }}; padding-top: 8px; }
        .notes { margin-top: 28px; padding-left: 10px; border-left: 3px solid {{ $design->accent }}; }
        .footer { margin-top: 36px; }
    </style>
</head>
<body>
<div class="sheet">
    <table class="w-full">
        <tr>
            <td>
                @include('pdf.partials.logo', ['maxWidth' => 200, 'maxHeight' => 64])
                <h1>{{ $document->type->label() }}</h1>
                <div class="number">{{ $document->number }}</div>
            </td>
            <td style="width: 42%">
                <div class="due">
                    <div class="due-label">{{ $requestsPayment ? 'Amount due' : 'Estimate total' }}</div>
                    <div class="due-amount">{{ $money($isInvoice ? $document->balanceDue() : $document->total) }}</div>
                    @if ($document->due_date)
                        <div>{{ $requestsPayment ? 'Due' : 'Valid until' }} {{ $document->due_date->format('j M Y') }}</div>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    <table class="w-full parties">
        <tr>
            <td style="width: 34%">@include('pdf.partials.client')</td>
            <td style="width: 33%">
                <div class="label">From</div>
                @include('pdf.partials.business')
            </td>
            <td class="right">@include('pdf.partials.meta')</td>
        </tr>
    </table>

    @include('pdf.partials.items')
    @include('pdf.partials.totals')
    @include('pdf.partials.notes')
    @include('pdf.partials.footer')
</div>
</body>
</html>
