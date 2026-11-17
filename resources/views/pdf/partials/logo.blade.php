{{-- The business's logo, scaled to fit $maxWidth x $maxHeight. --}}
@if ($design->logo)
    @php([$logoWidth, $logoHeight] = $design->logoSize($maxWidth ?? 180, $maxHeight ?? 60))
    <img class="logo" src="{{ $design->logo }}" width="{{ $logoWidth }}" height="{{ $logoHeight }}" alt="{{ $business->name }}" @if (! empty($alignRight)) style="margin-left: auto" @endif>
@endif
