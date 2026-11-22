<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $brand['name'] }}@isset($pageTitle) - {{ $pageTitle }}@endisset</title>
<meta name="description" content="{{ $brand['name'] }} - order online, cash on delivery.">
<meta property="og:title" content="{{ $brand['name'] }}@isset($pageTitle) - {{ $pageTitle }}@endisset">
<meta property="og:description" content="{{ $brand['name'] }} - order online, cash on delivery.">
<meta property="og:type" content="website">
@if($brand['logo'])<meta property="og:image" content="{{ $brand['logo'] }}">@endif
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Noto+Sans+Bengali:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/storefront.css">
<style>:root{--sf-accent:{{ $brand['color'] }}}</style>
@if($brand['ga4'])
<script async src="https://www.googletagmanager.com/gtag/js?id={{ $brand['ga4'] }}"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','{{ $brand['ga4'] }}');</script>
@endif
@if($brand['pixel'])
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init','{{ $brand['pixel'] }}');
window.sfEventId=function(){return 'sf.'+Date.now()+'.'+Math.random().toString(36).slice(2,12)};
fbq('track','PageView',{},{eventID:window.sfEventId()});
</script>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id={{ $brand['pixel'] }}&ev=PageView&noscript=1"></noscript>
@endif
<script>
window.sfTrack=function(ev,params,eventID){
  if(window.fbq) fbq('track',ev,params||{},{eventID:eventID||window.sfEventId()});
  if(window.gtag) gtag('event',ev.toLowerCase(),params||{});
};
</script>
</head>
<body>
<header class="sf-header">
  <div class="sf-wrap sf-header-in">
    @if($brand['logo'])<img class="sf-logo" src="{{ $brand['logo'] }}" alt="">@else<div class="sf-logo-fallback">{{ mb_strtoupper(mb_substr($brand['name'],0,1)) }}</div>@endif
    <div>
      <div class="sf-shopname">{{ $brand['name'] }}</div>
      <div class="sf-shopsub">Cash on delivery · সারা দেশে ডেলিভারি</div>
    </div>
  </div>
</header>

<main class="sf-wrap">
@yield('content')
</main>

<footer class="sf-footer">
  <div class="sf-wrap">
    <div class="sf-fname">{{ $brand['name'] }}</div>
    @if($brand['footer'])<p>{{ $brand['footer'] }}</p>@endif
    <p><a href="/report">Report this page</a> · Powered by SkaleUp</p>
  </div>
</footer>
@yield('scripts')
<script>
(function(){
  // --- checkout error from the one-shot cookie (pages are render-cached) ---
  var m=document.cookie.match(/(?:^|; )sf_err=([^;]*)/);
  if(m){
    var msg=decodeURIComponent(m[1].replace(/\+/g,' '));
    document.querySelectorAll('.sf-err-slot').forEach(function(el){ el.textContent=msg; el.className='sf-err'; el.style.display='block'; });
    document.cookie='sf_err=; Max-Age=0; path=/';
  }
  // --- attribution: URL params -> session -> hidden fields ---
  var keys=['utm_source','utm_medium','utm_campaign','fbclid'];
  try{
    var q=new URLSearchParams(location.search), stored=JSON.parse(sessionStorage.getItem('sf_attr')||'{}'), changed=false;
    keys.forEach(function(k){ if(q.get(k)){ stored[k]=q.get(k); changed=true; } });
    if(changed) sessionStorage.setItem('sf_attr', JSON.stringify(stored));
    keys.forEach(function(k){ document.querySelectorAll('input[name="'+k+'"]').forEach(function(i){ i.value=stored[k]||''; }); });
  }catch(e){}
  // --- order forms: event ids, totals, qty ---
  var fmt=function(n){ return '৳'+Math.round(n).toLocaleString('en-IN'); };
  document.querySelectorAll('.sf-order-form').forEach(function(form){
    var ic=form.querySelector('input[name="ic_event_id"]'); if(ic) ic.value=window.sfEventId();
    var price=parseFloat(form.dataset.price||'0'), inside=parseFloat(form.dataset.inside||'0'), outside=parseFloat(form.dataset.outside||'0');
    var district=form.querySelector('.sf-district'), qty=form.querySelector('.sf-qty-input');
    function totals(){
      var qn=Math.max(1, parseInt(qty&&qty.value||'1',10)||1);
      var del=district&&district.value ? (district.value==='Dhaka'?inside:outside) : inside;
      var sub=form.querySelector('.sf-t-sub'), d=form.querySelector('.sf-t-del'), g=form.querySelector('.sf-t-grand');
      if(sub) sub.textContent=fmt(price*qn); if(d) d.textContent=fmt(del); if(g) g.textContent=fmt(price*qn+del);
    }
    if(district) district.addEventListener('change', totals);
    if(qty){ qty.addEventListener('input', totals);
      var minus=form.querySelector('.sf-qminus'), plus=form.querySelector('.sf-qplus');
      if(minus) minus.addEventListener('click', function(){ qty.value=Math.max(1,(parseInt(qty.value||'1',10)||1)-1); totals(); });
      if(plus) plus.addEventListener('click', function(){ qty.value=Math.min(20,(parseInt(qty.value||'1',10)||1)+1); totals(); });
    }
    var vsel=form.querySelector('select[name="variant_id"]');
    if(vsel){ vsel.addEventListener('change', function(){ var o=vsel.selectedOptions[0]; if(o&&o.dataset.price){ price=parseFloat(o.dataset.price); } totals(); }); }
    totals();
    form.addEventListener('submit', function(){
      var pe=form.querySelector('input[name="purchase_event_id"]'); if(pe) pe.value=window.sfEventId();
      var pid=form.querySelector('input[name="product_id"]').value;
      window.sfTrack('InitiateCheckout',{currency:'BDT',value:price*(parseInt(qty&&qty.value||'1',10)||1),content_ids:[pid],content_type:'product'}, ic?ic.value:undefined);
    });
  });
  // --- countdown blocks ---
  document.querySelectorAll('.sf-timer').forEach(function(el){
    var end=new Date(el.dataset.ends).getTime();
    function tick(){
      var s=Math.max(0, Math.floor((end-Date.now())/1000));
      var d=Math.floor(s/86400), h=Math.floor(s%86400/3600), mi=Math.floor(s%3600/60), se=s%60;
      el.textContent=(d>0?d+'d ':'')+String(h).padStart(2,'0')+':'+String(mi).padStart(2,'0')+':'+String(se).padStart(2,'0');
    }
    tick(); setInterval(tick,1000);
  });
  // --- gallery thumbs ---
  document.querySelectorAll('.sf-thumbs img').forEach(function(img){
    img.addEventListener('click', function(){
      var main=document.querySelector('.sf-gallery-main img'); if(main) main.src=img.src;
      document.querySelectorAll('.sf-thumbs img').forEach(function(i){ i.classList.remove('on'); }); img.classList.add('on');
    });
  });
})();
</script>
</body>
</html>
