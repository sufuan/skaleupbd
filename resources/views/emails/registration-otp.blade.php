<x-mail::message>
# {{ __('Hello :name,', ['name' => $name]) }}

{{ __('Welcome to Scalenow Bd. To finish creating your shop account, type this code on the verification page:') }}

<x-mail::panel>
<div style="text-align: center; font-size: 34px; font-weight: 700; letter-spacing: 10px; color: #411550;">{{ $code }}</div>
</x-mail::panel>

{{ __('This code is valid for 10 minutes. Never share it with anyone - the Scalenow Bd team will never ask for it.') }}

{{ __('If you did not create this account, you can safely ignore this email.') }}

{{ __('- The Scalenow Bd team') }}
</x-mail::message>
