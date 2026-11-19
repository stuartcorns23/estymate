{{-- Classic: logo and title on the left, business details on the right. The original layout. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    @include('pdf.partials.head')
    <style>
        h1 { font-size: 22px; letter-spacing: 0.5px; color: {{ $design->colourText() }}; margin-top: 6px; }
        .parties { margin: 28px 0 20px; }
        table.items th { color: #6b7280; border-bottom: 1px solid {{ $design->colour }}; }
        table.items td { border-bottom: 1px solid #f0f0f0; }
        table.totals { margin-top: 12px; }
        table.totals .grand td { border-top: 1px solid {{ $design->colour }}; font-size: 12px; font-weight: bold; padding-top: 6px; color: {{ $design->colourText() }}; }
        .notes { margin-top: 28px; }
        .footer { margin-top: 36px; }
        .accent { color: {{ $design->accentText() }}; }
    </style>
</head>
<body>
@include('pdf.partials.footer')
<div class="sheet">
    <table class="w-full">
        <tr>
            <td>
                @include('pdf.partials.logo', ['maxWidth' => 180, 'maxHeight' => 60])
                <h1>{{ strtoupper($document->type->label()) }}</h1>
                <div class="accent">{{ $document->number }}</div>
            </td>
            <td class="right">
                @include('pdf.partials.business')
            </td>
        </tr>
    </table>

    <table class="w-full parties">
        <tr>
            <td style="width: 55%">@include('pdf.partials.client')</td>
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
