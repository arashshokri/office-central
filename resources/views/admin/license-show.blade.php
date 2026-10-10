@extends('layouts.app')

@section('content')
@php($canManage = auth()->user()->role !== 'viewer')
<div class="license-details">
    <div class="page-title">
        <div><h1>{{ __('ui.license_details') }}</h1><p>{{ $license->display_name ?: $license->customer->name }} · {{ $license->product->name }}</p></div>
        <div class="form-actions">@if($canManage)<a class="primary button" href="{{ route('licenses.edit',$license) }}">{{ __('ui.edit_license') }}</a>@endif<a class="secondary button" href="{{ route('licenses.index') }}">{{ __('ui.back') }}</a></div>
    </div>

    <section class="panel license-key-bar" aria-label="{{ __($canManage ? 'ui.full_license_key' : 'ui.field_license_key_prefix') }}">
        <div class="license-key-value"><span>{{ __($canManage ? 'ui.full_license_key' : 'ui.field_license_key_prefix') }}</span>
            @if(!$canManage)<code dir="ltr">{{ $license->license_key_prefix }}••••</code>
            @elseif($license->license_key_encrypted)<code id="generatedLicenseKey" dir="ltr">{{ $license->license_key_encrypted }}</code>
            @else<span class="muted">{{ __('ui.legacy_code_unavailable_short') }}</span>@endif
        </div>
        <div class="form-actions">
            <span class="badge {{ $license->status->value }}">{{ __('ui.state_'.$license->status->value) }}</span>
            @if($canManage && $license->license_key_encrypted)<button class="secondary" type="button" data-copy-target="generatedLicenseKey"><x-icon name="copy"/>{{ __('ui.copy') }}</button>
            @elseif($canManage)<form method="post" action="{{ route('licenses.replace-code',$license) }}">@csrf<button class="secondary" data-confirm="{{ __('ui.confirm_replace_code') }}">{{ __('ui.replace_license_code') }}</button></form>@endif
        </div>
    </section>

    <div class="license-overview {{ $canManage ? '' : 'license-overview-readonly' }}">
        <section class="panel">
            <div class="section-heading"><h2>{{ __('ui.license_information') }}</h2></div>
            <dl class="license-facts">
                <div><dt>{{ __('ui.customer') }}</dt><dd>{{ $license->customer->name }}</dd></div>
                <div><dt>{{ __('ui.product') }}</dt><dd>{{ $license->product->name }}</dd></div>
                <div><dt>{{ __('ui.license_name') }}</dt><dd>{{ $license->display_name ?: '—' }}</dd></div>
                <div><dt>{{ __('ui.edition') }}</dt><dd>{{ $license->edition ?: '—' }}</dd></div>
                <div><dt>{{ __('ui.field_expires_at') }}</dt><dd>{{ $license->expires_at ? \App\Support\PanelDate::format($license->expires_at, 'Y/m/d') : __('ui.lifetime_license') }}</dd></div>
                <div><dt>{{ __('ui.install_mode') }}</dt><dd>{{ __('ui.'.($license->activation_mode === 'attach_once' ? 'attach_once' : ($license->activation_mode === 'installer_once' ? 'installer_once' : 'legacy_license'))) }}</dd></div>
            </dl>
            <details class="license-technical">
                <summary>{{ __('ui.license_technical_details') }}</summary>
                <dl class="license-facts">
                    <div class="license-fact-wide"><dt>UUID</dt><dd dir="ltr">{{ $license->uuid }}</dd></div>
                    <div><dt>{{ __('ui.release') }}</dt><dd dir="ltr">{{ $license->release?->version ?? '—' }}</dd></div>
                    <div><dt>{{ __('ui.state_revision') }}</dt><dd>{{ $license->state_revision }}</dd></div>
                    <div><dt>{{ __('ui.code_consumed_at') }}</dt><dd>{{ \App\Support\PanelDate::format($license->consumed_at) }}</dd></div>
                </dl>
            </details>
        </section>

        @if($canManage)
        <section class="panel update-permission" id="update-permission">
            <div class="section-heading"><h2>{{ __('ui.customer_update_permission') }}</h2><x-icon name="upload"/></div>
            <div class="license-version-summary">
                <div><small>{{ __('ui.license_installed_version_short') }}</small><strong dir="ltr">{{ $license->installations->pluck('application_version')->filter()->unique()->implode(' / ') ?: '—' }}</strong></div>
                <div><small>{{ __('ui.license_allowed_version_short') }}</small><strong>{{ $license->updateRelease ? 'v'.$license->updateRelease->version : __('ui.no_update_permission') }}</strong></div>
            </div>
            <form class="permission-form" method="post" action="{{ route('licenses.update-release', $license) }}">@csrf @method('PUT')
                <label for="allowedUpdateRelease">{{ __('ui.license_select_update') }}
                    <select id="allowedUpdateRelease" name="release_id">
                        <option value="">{{ __('ui.no_update_permission') }}</option>
                        @foreach($updateReleases as $release)
                            <option value="{{ $release->id }}" @selected((string)old('release_id',$license->update_release_id)===(string)$release->id) @disabled(!$release->isOfficeUpdateReady())>
                                v{{ $release->version }} · {{ __('ui.channel_'.$release->channel->value) }} · {{ $release->source_type->value === 'github' ? 'GitHub' : __('ui.manual_upload') }}
                                @if(!$release->isOfficeUpdateReady()) — {{ __($release->status->value !== 'published' ? 'ui.update_release_unpublished' : 'ui.update_release_missing_runtime') }}
                                @elseif($release->is_security) — {{ __('ui.security_update') }}@endif
                            </option>
                        @endforeach
                    </select>
                    @error('release_id')<small class="field-error">{{ $message }}</small>@enderror
                </label>
                <button class="primary">{{ __('ui.save') }}</button>
            </form>
            @if(!$updateReleases->contains(fn($release) => $release->isOfficeUpdateReady()))<p class="license-inline-note">{{ __('ui.no_ready_update_release') }}</p>@endif
            @if($license->installations->contains(fn($installation) => $installation->target_release_id))<p class="license-inline-note">{{ __('ui.license_target_override_short') }}</p>@endif
            <div class="license-resource-links"><a href="{{ route('releases.index') }}">{{ __('ui.releases') }}</a><a href="{{ route('releases.create') }}">{{ __('ui.upload_runtime_release') }}</a><a href="{{ route('repositories.index') }}">{{ __('ui.repositories') }}</a></div>
        </section>
        @endif
    </div>

    <div class="license-management {{ $canManage ? '' : 'license-management-readonly' }}">
        <section class="panel license-access {{ $license->temporarily_locked_at ? 'is-locked' : '' }}">
            <div class="section-heading"><h2>{{ __('ui.license_access_control') }}</h2><span class="badge {{ $license->temporarily_locked_at ? 'locked' : 'active' }}">{{ $license->temporarily_locked_at ? __('ui.temporary_lock_active') : __('ui.access_is_open') }}</span></div>
            @if($license->temporarily_locked_at)
                <p class="license-inline-note">{{ $license->temporary_lock_message }}</p>
                @if($canManage)<form method="post" action="{{ route('licenses.temporary-unlock',$license) }}">@csrf @method('DELETE')<button class="success-button" data-confirm="{{ __('ui.confirm_unlock') }}">{{ __('ui.unlock_now') }}</button></form>@endif
            @elseif($canManage)
                <form class="license-lock-form" method="post" action="{{ route('licenses.temporary-lock',$license) }}">@csrf
                    <label for="customerLockMessage">{{ __('ui.customer_lock_message') }}<span>{{ __('ui.license_optional') }}</span></label>
                    <textarea id="customerLockMessage" name="message" rows="2" maxlength="500" placeholder="{{ config('office.default_lock_message') }}"></textarea>
                    <button class="secondary license-danger-outline" data-confirm="{{ __('ui.confirm_lock') }}">{{ __('ui.temporary_lock') }}</button>
                </form>
            @endif
        </section>
        @if($canManage)
        <section class="panel license-status">
            <div class="section-heading"><h2>{{ __('ui.license_controls') }}</h2><span class="badge {{ $license->status->value }}">{{ __('ui.state_'.$license->status->value) }}</span></div>
            <div class="form-actions">@foreach(['active'=>'success-button','suspended'=>'secondary','revoked'=>'danger-button'] as $status=>$class)<form method="post" action="{{ route('licenses.status',[$license,$status]) }}">@csrf<button class="{{ $class }}" @disabled($license->status->value === $status) data-confirm="{{ __('ui.confirm_license_status',['status'=>__('ui.state_'.$status)]) }}">{{ __('ui.state_'.$status) }}</button></form>@endforeach</div>
            <div class="license-delete-row"><form method="post" action="{{ route('licenses.destroy',$license) }}">@csrf @method('DELETE')<button class="secondary license-danger-outline" data-confirm="{{ __('ui.confirm_delete_license') }}">{{ __('ui.delete_license') }}</button></form></div>
        </section>
        @endif
    </div>

    <section class="panel license-installations">
        <div class="section-heading"><h2>{{ __('ui.installations') }}</h2><span class="badge" aria-label="{{ __('ui.max_installations') }}">{{ $license->installations->count() }} / {{ $license->max_installations }}</span></div>
        <div class="installation-cards">
            @forelse($license->installations as $installation)
                <a href="{{ route('installations.show',$installation) }}"><strong>{{ $installation->hostname }}</strong><span dir="ltr">{{ $installation->application_version ?: '—' }}</span><em class="badge {{ $installation->status->value }}">{{ __('ui.state_'.$installation->status->value) }}</em></a>
            @empty<p class="empty">{{ __('ui.no_installations') }}</p>@endforelse
        </div>
    </section>

    @if(in_array($license->activation_mode, ['installer_once', 'attach_once']))
    <details class="panel license-disclosure installer-guide">
        <summary>{{ __($license->activation_mode === 'attach_once' ? 'ui.connect_existing_office' : 'ui.install_office_for_customer') }}</summary>
        <div class="license-disclosure-content">
            @if($license->consumed_at)<p>{{ __('ui.installer_consumed_help') }}</p>
            @else
                @if($license->activation_mode === 'attach_once')<p>{{ __('ui.attach_upgrade_first') }}</p><p>{{ __('ui.attach_preserves_data') }}</p>@else<p>{{ __('ui.license_web_setup_short') }}</p>@endif
                <pre id="officeInstallerCommand">sudo apt-get update &amp;&amp;
sudo apt-get install -y curl python3 ca-certificates &amp;&amp;
curl --fail --proto '=https' --tlsv1.2 https://update.ponet.ir/agent/install.sh -o office-install.sh &amp;&amp;
sudo bash office-install.sh</pre>
                <button class="secondary" type="button" data-copy-target="officeInstallerCommand">{{ __('ui.copy') }}</button>
                <p>{{ __('ui.installer_code_lifecycle') }}</p>
            @endif
        </div>
    </details>
    @endif

    @if($canManage)
    <details class="panel license-disclosure">
        <summary><span>{{ __('ui.planned_features') }}</span><span class="badge draft">{{ __('ui.planning_only') }}</span></summary>
        <div class="license-disclosure-content"><p>{{ __('ui.license_features_short') }}</p><p>{{ __($license->planned_feature_policy === 'selected' ? 'ui.selected_product_features' : 'ui.all_product_features') }}</p><div class="feature-chips">@forelse($plannedFeatures as $feature)<span class="badge">{{ $feature->name }}@if($feature->is_required) · {{ __('ui.required_feature') }}@endif</span>@empty<span class="muted">{{ __('ui.no_features') }}</span>@endforelse</div></div>
    </details>
    @endif
</div>
@endsection
