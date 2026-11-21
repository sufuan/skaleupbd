{{-- M18 order form. Expects: $product (vm), $districts, $sf, optional $showQty --}}
<div class="sf-orderbox" id="order-form-{{ $product['id'] }}">
  <h2>অর্ডার করুন</h2>
  <p class="sf-sub">{{ $product['name'] }} — ক্যাশ অন ডেলিভারি। অর্ডারের পর আমরা কল করে নিশ্চিত করব।</p>
  <div class="sf-err-slot" style="display:none"></div>
  <form method="POST" action="/order" class="sf-order-form" data-inside="{{ $sf->deliveryInside() }}" data-outside="{{ $sf->deliveryOutside() }}" data-price="{{ $product['effective'] }}">
    <input type="hidden" name="_token" value="%%SF_CSRF%%">
    <input type="hidden" name="product_id" value="{{ $product['id'] }}">
    <input type="hidden" name="ic_event_id" value="">
    <input type="hidden" name="purchase_event_id" value="">
    <input type="hidden" name="utm_source" value="">
    <input type="hidden" name="utm_medium" value="">
    <input type="hidden" name="utm_campaign" value="">
    <input type="hidden" name="fbclid" value="">
    <input type="text" name="website" class="sf-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
    <div class="sf-field">
      <label>আপনার নাম</label>
      <input type="text" name="customer_name" required maxlength="120" placeholder="যেমন: রহিম উদ্দিন">
    </div>
    <div class="sf-field">
      <label>মোবাইল নম্বর</label>
      <input type="tel" name="customer_phone" required maxlength="20" placeholder="01XXXXXXXXX">
    </div>
    <div class="sf-field">
      <label>সম্পূর্ণ ঠিকানা</label>
      <textarea name="customer_address" required rows="2" maxlength="500" placeholder="বাসা/হোল্ডিং, রোড, এলাকা"></textarea>
    </div>
    <div class="sf-field">
      <label>জেলা</label>
      <select name="district" required class="sf-district">
        <option value="">— জেলা বেছে নিন —</option>
        @foreach($districts as $d)<option value="{{ $d }}">{{ $d }}</option>@endforeach
      </select>
    </div>
    @if(!empty($showQty))
    <div class="sf-field">
      <label>পরিমাণ</label>
      <div class="sf-qty">
        <button type="button" class="sf-qminus">−</button>
        <input type="text" name="qty" value="1" class="sf-qty-input" inputmode="numeric">
        <button type="button" class="sf-qplus">+</button>
      </div>
    </div>
    @else
      <input type="hidden" name="qty" value="1" class="sf-qty-input">
    @endif
    <div class="sf-total"><span>পণ্যের দাম</span><span class="sf-t-sub">৳{{ number_format($product['effective']) }}</span></div>
    <div class="sf-total"><span>ডেলিভারি চার্জ</span><span class="sf-t-del">৳{{ number_format($sf->deliveryInside()) }}</span></div>
    <div class="sf-total grand"><span>মোট</span><span class="sf-t-grand">৳{{ number_format($product['effective'] + $sf->deliveryInside()) }}</span></div>
    <button type="submit" class="sf-btn block" @disabled(!$product['available'])>{{ $product['available'] ? 'অর্ডার নিশ্চিত করুন' : 'স্টক শেষ' }}</button>
  </form>
</div>
