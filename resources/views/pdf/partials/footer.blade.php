{{-- Pinned to the bottom of every page. Layouts include it first, straight inside <body>: Dompdf only repeats a fixed element on every page from there. --}}
<div class="footer">
    @if ($design->footer)
        <div class="footer-text">{{ $design->footer }}</div>
    @endif
    @foreach ($footerLines as $line)
        <div class="footer-line">{!! \App\Support\DocumentPdf::footerLineHtml($line) !!}</div>
    @endforeach
</div>
