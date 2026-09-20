<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='fa'?'rtl':'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ __('ui.two_factor_challenge') }}</title><link rel="stylesheet" href="{{ asset('assets/app.css') }}"></head>
<body class="login-page">
<form class="login-card" method="post" action="{{ route('two-factor.verify') }}">
    @csrf
    <div class="logo">2FA</div>
    <h1>{{ __('ui.two_factor_challenge') }}</h1>
    <p class="login-help">{{ __('ui.two_factor_challenge_help') }}</p>
    <label>{{ __('ui.authentication_code') }}<input name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus></label>
    @error('code')<p class="field-error">{{ $message }}</p>@enderror
    <button class="primary">{{ __('ui.verify_and_login') }}</button>
</form>
</body></html>
