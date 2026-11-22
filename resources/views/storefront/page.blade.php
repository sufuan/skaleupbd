@extends('storefront.layout')
@section('content')
@if($reported)
  <div class="sf-thanks">Thank you — your report has been received. Our team will review this page.</div>
@endif
@if($preview)
  <div class="sf-thanks">Preview — this is your draft. Customers do not see these changes until you publish them.</div>
@endif

@foreach($sections as $section)
  @php $p = $section['props']; $r = $section['resolved']; @endphp
  @switch($section['type'])

    @case('hero')
      @if(empty($p['hidden']))
      <section class="sf-hero" data-bid="{{ $section['id'] ?? '' }}">
        <div class="sf-hero-grid">
          <div>
            <h1>{{ $p['title'] ?? '' }}</h1>
            @if(!empty($p['subtitle']))<p>{{ $p['subtitle'] }}</p>@endif
            @if($ctaProduct)
              <a class="sf-btn" href="/p/{{ $ctaProduct['id'] }}">অর্ডার করুন</a>
            @endif
          </div>
          @if(!empty($r['image']))<div><img src="{{ $r['image'] }}" alt="{{ $p['alt'] ?? '' }}"></div>@endif
        </div>
      </section>
      @endif
      @break

    @case('offer')
      @if(empty($p['hidden']) && !empty($p['text']))
      <section class="sf-section" data-bid="{{ $section['id'] ?? '' }}">
        <div class="sf-offerbox"><h2 style="margin:0">{{ $p['text'] }}</h2></div>
      </section>
      @endif
      @break

    @case('products')
      <section class="sf-section" id="products" data-bid="{{ $section['id'] ?? '' }}">
        @if(!empty($p['title']))<h2>{{ $p['title'] }}</h2>@endif
        <div class="sf-grid">
          @foreach($r['products'] ?? [] as $prod)
            <a class="sf-card" href="/p/{{ $prod['id'] }}">
              <span class="sf-thumb">@if($prod['image'])<img src="{{ $prod['image'] }}" alt="" loading="lazy">@else<span class="sf-noimg">No image</span>@endif</span>
              <span class="sf-card-body">
                <span class="sf-card-name">{{ $prod['name'] }}</span>
                <span>
                  @if($prod['has_offer'])<span class="sf-badge offer">Offer</span> @endif
                  @if($prod['available'])<span class="sf-badge">In stock</span>@else<span class="sf-badge out">Out of stock</span>@endif
                </span>
                <span class="sf-price">@if($prod['has_offer'])<s>৳{{ number_format($prod['price']) }}</s>@endif৳{{ number_format($prod['effective']) }}</span>
                <span class="sf-btn">Order now</span>
              </span>
            </a>
          @endforeach
        </div>
        @if(empty($r['products']))<p>No products yet.</p>@endif
      </section>
      @break

    @case('faq')
      @if(empty($p['hidden']) && !empty($p['items']))
      <section class="sf-section sf-faq" data-bid="{{ $section['id'] ?? '' }}">
        @foreach($p['items'] as $f)
          <details><summary>{{ $f['q'] ?? '' }}</summary><p>{{ $f['a'] ?? '' }}</p></details>
        @endforeach
      </section>
      @endif
      @break

    @case('order_form')
      @if(!empty($r['product']))
      <section class="sf-section" data-bid="{{ $section['id'] ?? '' }}">
        @include('storefront.partials.order-form', ['product' => $r['product'], 'showQty' => true, 'pageId' => $page->id])
        @if(!empty($fbPage))
        <p style="text-align:center;margin-top:10px"><a class="sf-btn messenger" href="https://m.me/{{ $fbPage }}?ref={{ $r['product']['id'] }}" target="_blank" rel="noopener">Order on Messenger</a></p>
        @endif
      </section>
      @endif
      @break
  @endswitch
@endforeach

<section class="sf-section">
  <div class="sf-legal">
    <h2>Terms &amp; Conditions</h2>
    <div>{!! $legal['terms'] !!}</div>
  </div>
</section>
<section class="sf-section">
  <div class="sf-legal">
    <h2>Privacy Policy</h2>
    <div>{!! $legal['privacy'] !!}</div>
  </div>
</section>
<section class="sf-section">
  <div class="sf-legal">
    <h2>Return Policy</h2>
    <div>{!! $legal['return'] !!}</div>
  </div>
</section>

@if($ctaProduct)
<div class="sf-sticky">
  <div class="sf-sp"><span>{{ $ctaProduct['name'] }}</span><b>৳{{ number_format($ctaProduct['effective']) }}</b></div>
  <a class="sf-btn" href="/p/{{ $ctaProduct['id'] }}">Order now</a>
</div>
@endif
@endsection
