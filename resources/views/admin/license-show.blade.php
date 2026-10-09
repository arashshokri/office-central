@extends('layouts.app')

@section('content')
<div class="page-title">
    <div><h1>{{ __('ui.license_details') }}</h1><p dir="ltr">{{ $license->uuid }}</p></div>
    <a class="secondary button" href="{{ route('licenses.index') }}">{{ __('ui.back') }}</a>
</div>

@if(auth()->user()->role !== 'viewer')
<section class="one-time-secret">
    <div><strong>{{ __('ui.full_license_key') }}</strong><small>{{ __('ui.license_code_help') }}</small></div>
    @if($license->license_key_encrypted)
    <code id="generatedLicenseKey" dir="ltr">{{ $license->license_key_encrypted }}</code>
    <button type="button" data-copy-target="generatedLicenseKey">{{ __('ui.copy') }}</button>
    @else
    <p>{{ __('ui.legacy_code_unavailable') }}</p>
    <form method="post" action="{{ route('licenses.replace-code',$license) }}">@csrf<button data-confirm="{{ __('ui.confirm_replace_code') }}">{{ __('ui.replace_license_code') }}</button></form>
    @endif
</section>
<div class="license-delete-action"><form method="post" action="{{ route('licenses.destroy',$license) }}">@csrf @method('DELETE')<button class="danger-button" data-confirm="{{ __('ui.confirm_delete_license') }}">{{ __('ui.delete_license') }}</button></form></div>
@endif

@if(auth()->user()->role !== 'viewer')
<section class="panel update-permission" id="update-permission">
    <div class="section-heading"><div><h2>{{ __('ui.customer_update_permission') }}</h2><p>{{ __('ui.customer_update_help') }}</p></div><x-icon name="upload"/></div>
    <div class="license-version-summary">
        <div><small>{{ __('ui.installed_customer_versions') }}</small><strong dir="ltr">{{ $license->installations->pluck('application_version')->filter()->unique()->implode(' / ') ?: '—' }}</strong></div>
        <div><small>{{ __('ui.allowed_update_version') }}</small><strong>{{ $license->updateRelease ? 'v'.$license->updateRelease->version : __('ui.no_update_permission') }}</strong></div>
    </div>
    <form class="permission-form" method="post" action="{{ route('licenses.update-release', $license) }}">@csrf @method('PUT')
        <label for="allowedUpdateRelease">{{ __('ui.allowed_update_version') }}
            <select id="allowedUpdateRelease" name="release_id">
                <option value="">{{ __('ui.no_update_permission') }}</option>
                @foreach($updateReleases as $release)
                    <option value="{{ $release->id }}"
                        @selected((string)old('release_id',$license->update_release_id)===(string)$release->id)
                        @disabled(!$release->isOfficeRuntimeReady())>
                        v{{ $release->version }} — {{ __('ui.channel_'.$release->channel->value) }}
                        @if(!$release->isOfficeRuntimeReady())
                            — {{ __($release->status->value !== 'published' ? 'ui.update_release_unpublished' : 'ui.update_release_missing_runtime') }}
                        @elseif($release->is_security)
                            — {{ __('ui.security_update') }}
                        @endif
                    </option>
                @endforeach
            </select>
            @error('release_id')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <button class="primary">{{ __('ui.save_update_permission') }}</button>
    </form>
    <p class="muted version-permission-help">{{ __('ui.customer_update_workflow') }}</p>
    @if(!$updateReleases->contains(fn($release) => $release->isOfficeRuntimeReady()))<div class="flash version-permission-help">{{ __('ui.no_ready_update_release') }}</div>@endif
    @if($license->installations->contains(fn($installation) => $installation->target_release_id))<p class="muted version-permission-help">{{ __('ui.license_version_overrides_target') }}</p>@endif
    <div class="form-actions"><a class="secondary button" href="{{ route('releases.index') }}">{{ __('ui.releases') }}</a><a class="secondary button" href="{{ route('releases.create') }}">{{ __('ui.upload_runtime_release') }}</a><a class="table-action" href="{{ route('repositories.index') }}">{{ __('ui.repositories') }}</a></div>
</section>
@endif

<div class="detail-grid">
    <section class="panel detail-card">
        <div class="section-heading"><h2>{{ __('ui.license_information') }}</h2><span class="badge {{ $license->status->value }}">{{ __('ui.state_'.$license->status->value) }}</span></div>
        <dl class="detail-list">
            <div><dt>{{ __('ui.customer') }}</dt><dd>{{ $license->customer->name }}</dd></div>
            <div><dt>{{ __('ui.product') }}</dt><dd>{{ $license->product->name }}</dd></div>
            <div><dt>{{ __('ui.release') }}</dt><dd>{{ $license->release?->version ?? '—' }}</dd></div>
            <div><dt>{{ __('ui.field_license_key_prefix') }}</dt><dd dir="ltr">{{ auth()->user()->role === 'viewer' ? $license->license_key_prefix.'••••' : ($license->license_key_encrypted ?? __('ui.legacy_code_unavailable_short')) }}</dd></div>
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
