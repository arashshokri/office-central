@extends('layouts.app')

@section('content')
<div class="page-title"><div><h1>{{ __('ui.security_settings') }}</h1><p>{{ __('ui.security_settings_help') }}</p></div></div>

@if(session('recovery_codes'))
<section class="one-time-secret recovery-secret">
    <div><strong>{{ __('ui.recovery_codes') }}</strong><small>{{ __('ui.recovery_codes_once') }}</small></div>
    <div class="recovery-grid" dir="ltr">@foreach(session('recovery_codes') as $code)<code>{{ $code }}</code>@endforeach</div>
</section>
@endif

<section class="panel security-panel">
    <div class="section-heading">
        <div><h2>{{ __('ui.two_factor_authentication') }}</h2><p>{{ __('ui.two_factor_description') }}</p></div>
        <span class="badge {{ $user->two_factor_confirmed_at ? 'active' : 'inactive' }}">{{ $user->two_factor_confirmed_at ? __('ui.enabled') : __('ui.disabled') }}</span>
    </div>

    @if($user->two_factor_confirmed_at)
        <form class="stack-form compact-form" method="post" action="{{ route('security.two-factor.disable') }}">@csrf @method('DELETE')
            <label>{{ __('ui.current_password') }}<input type="password" name="password" autocomplete="current-password" required></label>
            <button class="danger-button" data-confirm="{{ __('ui.confirm_disable_two_factor') }}">{{ __('ui.disable_two_factor') }}</button>
        </form>
    @elseif($user->two_factor_secret)
        <div class="totp-setup">
            <p>{{ __('ui.two_factor_manual_help') }}</p>
            <code dir="ltr">{{ $user->two_factor_secret }}</code>
            <details><summary>{{ __('ui.technical_uri') }}</summary><small dir="ltr">{{ $provisioningUri }}</small></details>
        </div>
        <form class="stack-form compact-form" method="post" action="{{ route('security.two-factor.confirm') }}">@csrf
            <label>{{ __('ui.authentication_code') }}<input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required></label>
            <button class="primary">{{ __('ui.confirm_two_factor') }}</button>
        </form>
    @else
        <form class="stack-form compact-form" method="post" action="{{ route('security.two-factor.begin') }}">@csrf
            <label>{{ __('ui.current_password') }}<input type="password" name="password" autocomplete="current-password" required></label>
            <button class="primary">{{ __('ui.begin_two_factor') }}</button>
        </form>
    @endif
</section>
@endsection
