@props(['url'])
<tr>
<td class="header" style="background-color: #411550;">
<a href="{{ $url }}" style="display: inline-block; color: #ffffff;">
@if (trim($slot) === 'Laravel')
<img src="https://laravel.com/img/notification-logo-v2.1.png" class="logo" alt="Laravel Logo">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
