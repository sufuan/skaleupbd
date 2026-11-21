@extends('storefront.layout')
@section('content')
@if($reported)
  <div class="sf-thanks">Thank you — your report has been received. Our team will review this page.</div>
@endif

@foreach($blocks as $block)
  @php $d = $block['data']; $r = $block['resolved']; @endphp
  @switch($block['type'])

    @case('hero')
      <section class="sf-hero">
        <div class="sf-hero-grid">
          <div>
            <h1>{{ $d['title'] ?? '' }}</h1>
            @if(!empty($d['subtitle']))<p>{{ $d['subtitle'] }}</p>@endif
            @if(!empty($r['product']))
              <a class="sf-btn" href="/p/{{ $r['product']['id'] }}">{{ $d['cta_text'] ?: 'Shop now' }}</a>
            @else
              <a class="sf-btn" href="#products">{{ $d['cta_text'] ?: 'Shop now' }}</a>
            @endif
          </div>
          @if(!empty($r['image']))<div><img src="{{ $r['image'] }}" alt=""></div>@endif
        </div>
      </section>
      @break

    @case('product_grid')
      <section class="sf-section" id="products">
        @if(!empty($d['title']))<h2>{{ $d['title'] }}</h2>@endif
        <div class="sf-grid">
          @foreach($r['products'] ?? [] as $p)
            <a class="sf-card" href="/p/{{ $p['id'] }}">
              <span class="sf-thumb">@if($p['image'])<img src="{{ $p['image'] }}" alt="" loading="lazy">@else<span class="sf-noimg">No image</span>@endif</span>
              <span class="sf-card-body">
                <span class="sf-card-name">{{ $p['name'] }}</span>
                <span>
                  @if($p['has_offer'])<span class="sf-badge offer">Offer</span> @endif
                  @if($p['available'])<span class="sf-badge">In stock</span>@else<span class="sf-badge out">Out of stock</span>@endif
                </span>
                <span class="sf-price">@if($p['has_offer'])<s>৳{{ number_format($p['price']) }}</s>@endif৳{{ number_format($p['effective']) }}</span>
                <span class="sf-btn">Order now</span>
              </span>
            </a>
          @endforeach
        </div>
        @if(empty($r['products']))<p>No products yet.</p>@endif
      </section>
      @break

    @case('product_single')
      @if(!empty($r['product']))
      <section class="sf-section">
        <div class="sf-feature">
          <div>@if($r['product']['image'])<img src="{{ $r['product']['image'] }}" alt="">@endif</div>
          <div>
            <h2 style="margin-top:0">{{ $r['product']['name'] }}</h2>
            @if($r['product']['short'])<p class="sf-desc">{{ $r['product']['short'] }}</p>@endif
            <div class="sf-pprice">@if($r['product']['has_offer'])<s>৳{{ number_format($r['product']['price']) }}</s>@endif৳{{ number_format($r['product']['effective']) }}</div>
            <p>@if($r['product']['available'])<span class="sf-badge">In stock</span>@else<span class="sf-badge out">Out of stock</span>@endif</p>
            <a class="sf-btn" href="/p/{{ $r['product']['id'] }}">Order now</a>
            @if($fbPage)<a class="sf-btn messenger" href="https://m.me/{{ $fbPage }}?ref={{ $r['product']['id'] }}" target="_blank" rel="noopener">Order on Messenger</a>@endif
          </div>
        </div>
      </section>
      @endif
      @break

    @case('price_offer')
      <section class="sf-section">
        <div class="sf-offerbox">
          @if(!empty($d['title']))<h2>{{ $d['title'] }}</h2>@endif
          @if(!empty($d['text']))<div>{!! $d['text'] !!}</div>@endif
          @if(!empty($r['product']))
            <p style="margin:14px 0 0"><a class="sf-btn" style="background:#fff;color:var(--sf-accent)" href="/p/{{ $r['product']['id'] }}">{{ $r['product']['name'] }} — ৳{{ number_format($r['product']['effective']) }}</a></p>
          @endif
        </div>
      </section>
      @break

    @case('order_form')
      @if(!empty($r['product']))
      <section class="sf-section">
        @include('storefront.partials.order-form', ['product' => $r['product'], 'showQty' => true])
      </section>
      @endif
      @break

    @case('testimonials')
      <section class="sf-section">
        @if(!empty($d['title']))<h2>{{ $d['title'] }}</h2>@endif
        <div class="sf-tgrid">
          @foreach($d['items'] ?? [] as $t)
            <div class="sf-testi"><p>“{{ $t['text'] ?? '' }}”</p><div class="sf-tname">— {{ $t['name'] ?? '' }}</div></div>
          @endforeach
        </div>
      </section>
      @break

    @case('faq')
      <section class="sf-section sf-faq">
        @if(!empty($d['title']))<h2>{{ $d['title'] }}</h2>@endif
        @foreach($d['items'] ?? [] as $f)
          <details><summary>{{ $f['q'] ?? '' }}</summary><p>{{ $f['a'] ?? '' }}</p></details>
        @endforeach
      </section>
      @break

    @case('countdown')
      @if(!empty($d['ends_at']))
      <section class="sf-section">
        <div class="sf-count">
          @if(!empty($d['title']))<h2 style="margin-top:0">{{ $d['title'] }}</h2>@endif
          <div class="sf-timer" data-ends="{{ $d['ends_at'] }}">00:00:00</div>
        </div>
      </section>
      @endif
      @break

    @case('legal')
      <section class="sf-section">
        <div class="sf-legal">
          <h2>{{ $d['title'] ?? '' }}</h2>
          <div>{!! $d['text'] ?? '' !!}</div>
        </div>
      </section>
      @break
  @endswitch
@endforeach

@if($ctaProduct)
<div class="sf-sticky">
  <div class="sf-sp"><span>{{ $ctaProduct['name'] }}</span><b>৳{{ number_format($ctaProduct['effective']) }}</b></div>
  <a class="sf-btn" href="/p/{{ $ctaProduct['id'] }}">Order now</a>
</div>
@endif
@endsection
