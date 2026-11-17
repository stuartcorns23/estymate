{{-- The business's name and contact details. --}}
<strong @isset($nameStyle) style="{{ $nameStyle }}" @endisset>{{ $business->name }}</strong><br>
@foreach ($businessAddress as $line){{ $line }}<br>@endforeach
@if ($business->email){{ $business->email }}<br>@endif
@if ($business->phone){{ $business->phone }}<br>@endif
