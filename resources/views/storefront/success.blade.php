@extends('storefront.layout')
@section('content')
<div class="sf-notice">
  <div class="sf-ok-icon">✓</div>
  <h1>Order received - thank you!</h1>
  <p>Your order number is <b>{{ $order->invoice_no }}</b>.<br>
  Total: <b>৳{{ number_format((float) $order->grand_total) }}</b> (cash on delivery).<br>
  We will call <b>{{ $order->customer_phone }}</b> shortly to confirm your order.</p>
  @if(!empty($advanceNumbers))
  <div class="sf-advance">
    <div class="sf-advance-title">Want your order confirmed faster? Pay an advance:</div>
    @foreach($advanceNumbers as $n)
    <div class="sf-advance-row"><span>{{ $n['label'] }} (Personal)</span><b>{{ $n['number'] }}</b></div>
    @endforeach
    <div class="sf-advance-note">Send money to any of these numbers, then keep your TrxID - we may ask for it on the confirmation call.</div>
  </div>
  @endif
  <p><a class="sf-btn" href="/">Continue shopping</a></p>
</div>
@endsection

@section('scripts')
@if($purchaseEventId)
<script>
window.sfTrack('Purchase',{currency:'BDT',value:{{ (float) $order->grand_total }},content_ids:[@foreach($order->items as $i)'{{ $i->product_id }}'@if(!$loop->last),@endif @endforeach],content_type:'product',order_id:'{{ $order->invoice_no }}'}, '{{ $purchaseEventId }}');
</script>
@endif
@endsection
