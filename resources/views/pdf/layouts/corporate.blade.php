{{-- Corporate: boxed panels, striped rows and a coloured top rule. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    @include('pdf.partials.head')
    @php($tint = \App\Support\DocumentDesign::tint($design->colour, 0.07))
    <style>
        .top-rule { height: 3px; background: {{ $design->colour }}; margin-bottom: 18px; }
        h1 { font-size: 18px; letter-spacing: 1px; color: {{ $design->colourText() }}; }
        .box { border: 1px solid #d9dde3; padding: 10px 12px; }
        .box .label { color: {{ $design->accentText() }}; }
        .gap { width: 12px; }
        .parties { margin: 20px 0 18px; }
        table.items { border: 1px solid #d9dde3; }
        table.items th { background: {{ $design->colour }}; color: {{ $design->onColour() }}; }
        table.items tr.even td { background: {{ $tint }}; }
        table.totals { margin-top: 12px; border: 1px solid #d9dde3; }
        table.totals td { padding: 4px 10px; }
        table.totals .grand td { background: {{ $tint }}; font-size: 12px; font-weight: bold; color: {{ $design->colourText() }}; border-top: 1px solid #d9dde3; padding: 7px 10px; }
        .notes { margin-top: 22px; }
        .footer { margin-top: 30px; padding-top: 8px; border-top: 3px solid {{ $design->accent }}; }
    </style>
</head>
<body>
<div class="sheet">
    <div class="top-rule"></div>
    <table class="w-full">
        <tr>
            <td style="vertical-align: middle">
                @include('pdf.partials.logo', ['maxWidth' => 170, 'maxHeight' => 56])
                @unless ($design->logo)
                    <strong style="font-size: 14px">{{ $business->name }}</strong>
                @endunless
            </td>
            <td class="right" style="vertical-align: middle">
                <h1>{{ strtoupper($document->type->label()) }}</h1>
                <div class="muted">No. {{ $document->number }}</div>
            </td>
        </tr>
    </table>

    <table class="w-full parties">
        <tr>
            <td class="box" style="width: 34%">
                <div class="label">From</div>
                @include('pdf.partials.business')
            </td>
            <td class="gap"></td>
            <td class="box" style="width: 34%">@include('pdf.partials.client')</td>
            <td class="gap"></td>
            <td class="box">
                <div class="label">Details</div>
                @include('pdf.partials.meta', ['tableStyle' => 'width: 100%', 'labelCellStyle' => 'padding-left: 0'])
            </td>
        </tr>
    </table>

    @include('pdf.partials.items')
    @include('pdf.partials.totals')
    @include('pdf.partials.notes')
    @include('pdf.partials.footer')
</div>
</body>
</html>
