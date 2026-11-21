@extends('storefront.layout')
@section('content')
<div class="sf-notice">
  <h1>We cannot take orders right now</h1>
  <p><b>{{ $brand['name'] }}</b> is temporarily unable to take new orders. Please try again later, or contact the shop directly.</p>
  <p><a class="sf-btn" href="/">Back to the shop</a></p>
</div>
@endsection
