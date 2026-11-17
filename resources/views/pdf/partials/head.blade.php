{{-- Shared <head>: page size, fonts and the styles every layout builds on. --}}
@php
    $marginY ??= 40;
    $marginX ??= 48;
@endphp
<meta charset="utf-8">
<title>{{ $document->type->label() }} {{ $document->number }}</title>
<style>
    @page { margin: {{ $marginY }}px {{ $marginX }}px; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: {!! $design->font->css() !!}; font-size: 10px; color: #1f2937; line-height: 1.45; background: #ffffff; }
    @if ($preview)
        html { background: #ffffff; }
        .sheet { width: 794px; min-height: 1123px; padding: {{ $marginY }}px {{ $marginX }}px; margin: 0 auto; }
    @endif
    table { border-collapse: collapse; }
    td, th { vertical-align: top; padding: 0; }
    h1 { margin: 0; font-weight: bold; }
    .w-full { width: 100%; }
    .right { text-align: right; }
    .muted { color: #6b7280; }
    .label { font-size: 8px; text-transform: uppercase; letter-spacing: 0.6px; color: #6b7280; margin-bottom: 4px; }
    .logo { display: block; }
    table.meta td { padding: 1px 0 1px 12px; }
    table.items { width: 100%; }
    table.items th { font-size: 8px; text-transform: uppercase; letter-spacing: 0.6px; text-align: left; padding: 6px 6px; }
    table.items td { padding: 7px 6px; }
    table.items .num { text-align: right; white-space: nowrap; }
    table.totals { margin-left: auto; min-width: 240px; }
    table.totals td { padding: 3px 6px; }
    .notes { white-space: pre-line; }
    .footer { font-size: 8px; color: #6b7280; }
    .footer-text { white-space: pre-line; margin-bottom: 4px; }
</style>
