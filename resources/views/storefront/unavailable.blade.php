@extends('storefront.layout')
@section('content')
<div class="sf-notice">
  @if($takenDown)
    <h1>This shop is unavailable</h1>
    <p>This storefront is not available right now. Please check back later.</p>
  @else
    <h1>Coming soon</h1>
    <p><b>{{ $brand['name'] }}</b> is getting ready. The shop will open here very soon.</p>
  @endif
</div>
@endsection
