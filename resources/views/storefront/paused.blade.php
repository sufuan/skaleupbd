@extends('storefront.layout')
@section('content')
<div class="sf-notice">
  <h1>এই পেজটি সাময়িকভাবে বন্ধ আছে</h1>
  <p><b>{{ $brand['name'] }}</b> - এই পেজটি এখন বন্ধ রাখা হয়েছে। দোকানের মূল পেজ থেকে অর্ডার করতে পারবেন।</p>
  <p><a class="sf-btn" href="/">দোকানের মূল পেজে যান</a></p>
</div>
@endsection
