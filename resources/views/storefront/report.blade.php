@extends('storefront.layout')
@section('content')
<div class="sf-notice" style="text-align:left">
  <h1 style="text-align:center">Report this page</h1>
  <p style="text-align:center">Tell us what is wrong with <b>{{ $brand['name'] }}</b>'s page. Our team reviews every report.</p>
  @if($errors->any())<div class="sf-err">{{ $errors->first() }}</div>@endif
  <form method="POST" action="/report">
    @csrf
    <input type="hidden" name="page_url" value="{{ url()->previous() }}">
    <input type="text" name="website" class="sf-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
    <div class="sf-field">
      <label>What is the problem?</label>
      <textarea name="reason" required rows="4" maxlength="1000" placeholder="e.g. This page pretends to be another brand, prices look fake..."></textarea>
    </div>
    <div class="sf-field">
      <label>Your phone or email (optional)</label>
      <input type="text" name="contact" maxlength="120" placeholder="So we can follow up if needed">
    </div>
    <button type="submit" class="sf-btn block">Send report</button>
  </form>
</div>
@endsection
