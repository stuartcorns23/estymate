{{-- Minimal: generous margins, light type and hairlines, colour used sparingly. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    @include('pdf.partials.head', ['marginY' => 56, 'marginX' => 64])
    <style>
        body { color: #374151; }
        h1 { font-size: 13px; font-weight: normal; letter-spacing: 3px; text-transform: uppercase; color: {{ $design->colourText() }}; }
        .number { font-size: 9px; color: #9ca3af; letter-spacing: 1px; margin-top: 2px; }
        .header { margin-bottom: 44px; }
        .parties { margin-bottom: 36px; }
        .label { color: #9ca3af; letter-spacing: 1.5px; }
        table.items th { color: #9ca3af; font-weight: normal; letter-spacing: 1.5px; border-bottom: 0.5px solid #d1d5db; padding-bottom: 8px; }
        table.items td { border-bottom: 0.5px solid #eef0f3; padding-top: 9px; padding-bottom: 9px; }
        table.totals { margin-top: 18px; }
        table.totals .grand td { font-size: 13px; padding-top: 10px; color: {{ $design->colourText() }}; }
        table.totals .grand td.right { border-bottom: 1.5px solid {{ $design->accent }}; padding-bottom: 4px; }
        .notes { margin-top: 40px; color: #6b7280; }
        .footer { margin-top: 48px; color: #9ca3af; }
    </style>
</head>
<body>
@include('pdf.partials.footer')
<div class="sheet">
    <table class="w-full header">
        <tr>
            <td style="vertical-align: middle">
                @if ($design->logo)
                    @include('pdf.partials.logo', ['maxWidth' => 120, 'maxHeight' => 40])
                @else
                    <span style="font-size: 12px; letter-spacing: 1px">{{ $business->name }}</span>
                @endif
            </td>
            <td class="right" style="vertical-align: middle">
                <h1>{{ $document->type->label() }}</h1>
                <div class="number">{{ $document->number }}</div>
            </td>
        </tr>
    </table>

    <table class="w-full parties">
        <tr>
            <td style="width: 36%">@include('pdf.partials.client')</td>
            <td style="width: 34%">
                <div class="label">From</div>
                @include('pdf.partials.business', ['nameStyle' => 'font-weight: normal'])
            </td>
            <td class="right">@include('pdf.partials.meta')</td>
        </tr>
    </table>

    @include('pdf.partials.items')
    @include('pdf.partials.totals')
    @include('pdf.partials.notes')
    @include('pdf.partials.signature')
</div>
</body>
</html>
