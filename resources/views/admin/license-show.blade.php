@extends('layouts.app')

@section('content')
<div class="page-title">
    <div><h1>{{ __('ui.license_details') }}</h1><p dir="ltr">{{ $license->uuid }}</p></div>
    <a class="secondary button" href="{{ route('licenses.index') }}">{{ __('ui.back') }}</a>
</div>

@if(session('license_key'))
<section class="one-time-secret">
    <div><strong>{{ __('ui.copy_license_now') }}</strong><small>{{ __('ui.license_once') }}</small></div>
    <code id="generatedLicenseKey" dir="ltr">{{ session('license_key') }}</code>
    <button type="button" data-copy-target="generatedLicenseKey">{{ __('ui.copy') }}</button>
</section>
@endif

<div class="detail-grid">
    <section class="panel detail-card">
        <div class="section-heading"><h2>{{ __('ui.license_information') }}</h2><span class="badge {{ $license->status->value }}">{{ __('ui.state_'.$license->status->value) }}</span></div>
        <dl class="detail-list">
            <div><dt>{{ __('ui.customer') }}</dt><dd>{{ $license->customer->name }}</dd></div>
            <div><dt>{{ __('ui.product') }}</dt><dd>{{ $license->product->name }}</dd></div>
            <div><dt>{{ __('ui.release') }}</dt><dd>{{ $license->release?->version ?? '—' }}</dd></div>
            <div><dt>{{ __('ui.field_license_key_prefix') }}</dt><dd dir="ltr">{{ $license->license_key_prefix }}••••</dd></div>
            <div><dt>{{ __('ui.max_installations') }}</dt><dd>{{ $license->max_installations }}</dd></div>
            <div><dt>{{ __('ui.state_revision') }}</dt><dd>{{ $license->state_revision }}</dd></div>
            <div><dt>{{ __('ui.install_mode') }}</dt><dd>{{ __('ui.'.($license->activation_mode === 'attach_once' ? 'attach_once' : ($license->activation_mode === 'installer_once' ? 'installer_once' : 'legacy_license'))) }}</dd></div>
            <div><dt>{{ __('ui.code_consumed_at') }}</dt><dd>{{ $license->consumed_at?->toISOString() ?? '—' }}</dd></div>
        </dl>
    </section>

    <section class="panel control-card {{ $license->temporarily_locked_at ? 'is-locked' : 'is-open' }}">
        <div class="control-state-icon">{{ $license->temporarily_locked_at ? '!' : '✓' }}</div>
        <div>
            <h2>{{ $license->temporarily_locked_at ? __('ui.temporary_lock_active') : __('ui.access_is_open') }}</h2>
            <p>{{ $license->temporarily_locked_at ? $license->temporary_lock_message : __('ui.fail_open_explanation') }}</p>
        </div>
        @if(auth()->user()->role !== 'viewer')
            @if($license->temporarily_locked_at)
                <form method="post" action="{{ route('licenses.temporary-unlock',$license) }}">@csrf @method('DELETE')
                    <button class="success-button" data-confirm="{{ __('ui.confirm_unlock') }}">{{ __('ui.unlock_now') }}</button>
                </form>
            @else
                <form class="lock-form" method="post" action="{{ route('licenses.temporary-lock',$license) }}">@csrf
                    <label>{{ __('ui.customer_lock_message') }}<textarea name="message" maxlength="500" placeholder="{{ config('office.default_lock_message') }}"></textarea></label>
                    <button class="danger-button" data-confirm="{{ __('ui.confirm_lock') }}">{{ __('ui.temporary_lock') }}</button>
                </form>
            @endif
        @endif
    </section>
</div>

@if(in_array($license->activation_mode, ['installer_once', 'attach_once']))
<section class="panel installer-guide">
    <div class="section-heading"><h2>{{ __($license->activation_mode === 'attach_once' ? 'ui.connect_existing_office' : 'ui.install_office_for_customer') }}</h2></div>
    <ol>
        <li>{{ __('ui.installer_server_prerequisites') }}</li>
        @if($license->activation_mode === 'attach_once')
        <li>{{ __('ui.attach_upgrade_first') }}</li>
        <li>{{ __('ui.attach_preserves_data') }}</li>
        @else
        <li>{{ __('ui.installer_proxy_step') }}</li>
        <li>{{ __('ui.installer_license_prompt') }}</li>
        @endif
    </ol>
    @if($license->consumed_at)
        <p>{{ __('ui.installer_consumed_help') }}</p>
    @else
        <pre id="officeInstallerCommand">sudo apt-get update &amp;&amp;
sudo apt-get install -y curl python3 ca-certificates &amp;&amp;
curl --fail --proto '=https' --tlsv1.2 https://update.ponet.ir/agent/install.sh -o office-install.sh &amp;&amp;
sudo bash office-install.sh</pre>
        <button class="secondary" type="button" data-copy-target="officeInstallerCommand">{{ __('ui.copy') }}</button>
    @endif
    <p>{{ __('ui.installer_code_lifecycle') }}</p>
</section>
@endif

<section class="panel">
    <div class="section-heading"><h2>{{ __('ui.installations') }}</h2><span>{{ $license->installations->count() }} / {{ $license->max_installations }}</span></div>
    <div class="installation-cards">
        @forelse($license->installations as $installation)
            <a href="{{ route('installations.show',$installation) }}">
                <strong>{{ $installation->hostname }}</strong>
                <span>{{ $installation->application_version ?: '—' }}</span>
                <em class="badge {{ $installation->status->value }}">{{ __('ui.state_'.$installation->status->value) }}</em>
            </a>
        @empty
            <p class="empty">{{ __('ui.no_installations') }}</p>
        @endforelse
    </div>
</section>
@endsection
