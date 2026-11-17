{{-- Modern: a full-width band in the brand colour, with the logo and title inside it. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    @include('pdf.partials.head', ['marginY' => 40, 'marginX' => 48])
    <style>
        .band { background: {{ $design->colour }}; color: {{ $design->onColour() }}; margin: -40px -48px 0; padding: 32px 48px 28px; }
        .band h1 { font-size: 26px; letter-spacing: 1px; }
        .band .sub { opacity: 0.85; }
        .band-accent { height: 4px; background: {{ $design->accent }}; margin: 0 -48px; }
        .parties { margin: 28px 0 22px; }
        .parties .label { color: {{ $design->accentText() }}; }
        table.items th { background: {{ \App\Support\DocumentDesign::tint($design->colour, 0.1) }}; color: {{ $design->colourText() }}; }
        table.items td { border-bottom: 1px solid #eceff3; }
        table.totals { margin-top: 14px; }
        table.totals .grand td { background: {{ $design->accent }}; color: {{ $design->onAccent() }}; font-size: 12px; font-weight: bold; padding: 7px 8px; }
        .notes { margin-top: 28px; }
        .footer { margin-top: 36px; padding-top: 10px; border-top: 1px solid #eceff3; }
    </style>
</head>
<body>
<div class="sheet">
    <div class="band">
        <table class="w-full">
            <tr>
                <td style="vertical-align: middle">
                    @if ($design->logo)
                        @include('pdf.partials.logo', ['maxWidth' => 170, 'maxHeight' => 56])
                    @else
                        <strong style="font-size: 16px">{{ $business->name }}</strong>
                    @endif
                </td>
                <td class="right" style="vertical-align: middle">
                    <h1>{{ strtoupper($document->type->label()) }}</h1>
                    <div class="sub">{{ $document->number }}</div>
                </td>
            </tr>
        </table>
    </div>
    <div class="band-accent"></div>

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
