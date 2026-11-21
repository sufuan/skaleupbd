@extends('storefront.layout')
@section('content')
<div class="sf-product">
  <div>
    <div class="sf-gallery-main">
      @if($product['image'])<img src="{{ $product['image'] }}" alt="{{ $product['name'] }}">@else<div style="aspect-ratio:1/1;display:flex;align-items:center;justify-content:center;color:#b7aed0">No image</div>@endif
    </div>
    @if(count($product['gallery']) > 1)
    <div class="sf-thumbs">
      @foreach($product['gallery'] as $i => $g)<img src="{{ $g }}" alt="" class="{{ $i === 0 ? 'on' : '' }}">@endforeach
    </div>
    @endif
  </div>
  <div>
    <h1 class="sf-pname">{{ $product['name'] }}</h1>
    @if($product['sku'])<div class="sf-psku">SKU: {{ $product['sku'] }}</div>@endif
    <div class="sf-pprice">@if($product['has_offer'])<s>৳{{ number_format($product['price']) }}</s>@endif৳{{ number_format($product['effective']) }}</div>
    <p>
      @if($product['available'])<span class="sf-badge">In stock</span>@else<span class="sf-badge out">Out of stock</span>@endif
      @if($product['has_offer'])<span class="sf-badge offer">Offer price</span>@endif
    </p>
    @if($product['short'])<p class="sf-desc">{{ $product['short'] }}</p>@endif
    @if($messengerUrl)
      <p><a class="sf-btn messenger block" href="{{ $messengerUrl }}" target="_blank" rel="noopener">Order on Messenger</a></p>
    @endif
    @include('storefront.partials.order-form', ['product' => $product, 'showQty' => true])
    @if($product['long'])<div class="sf-desc" style="margin-top:20px">{!! nl2br(e($product['long'])) !!}</div>@endif
  </div>
</div>

<div class="sf-sticky">
  <div class="sf-sp"><span>{{ $product['name'] }}</span><b>৳{{ number_format($product['effective']) }}</b></div>
  <a class="sf-btn" href="#order-form-{{ $product['id'] }}">Order now</a>
</div>
@endsection

@section('scripts')
<script>
window.sfTrack('ViewContent',{content_ids:['{{ $product['id'] }}'],content_type:'product',value:{{ $product['effective'] }},currency:'BDT'}, window.sfEventId());
</script>
@endsection
