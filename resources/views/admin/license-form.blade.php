@extends('layouts.app')
@section('content')
<div class="page-title"><h1>{{ __('ui.new_license') }}</h1></div>
<p>{{ __('ui.helper_simple_help') }}</p>
<form class="panel form record-form" method="post" action="{{ route('licenses.store') }}">@csrf
    <label>{{ __('ui.license_name') }}<input name="display_name" maxlength="120" value="{{ old('display_name') }}"></label>
    <label>{{ __('ui.edition') }}<input name="edition" maxlength="80" value="{{ old('edition') }}" placeholder="{{ __('ui.edition_example') }}"></label>
    <label>{{ __('ui.install_mode') }}<select name="activation_mode" id="helperMode">
        <option value="attach_once" @selected(old('activation_mode', 'attach_once') === 'attach_once')>{{ __('ui.attach_once') }}</option>
        <option value="installer_once" @selected(old('activation_mode') === 'installer_once')>{{ __('ui.installer_once') }}</option>
        <option value="legacy" @selected(old('activation_mode') === 'legacy')>{{ __('ui.legacy_license') }}</option>
    </select></label>
    <div id="freshInstallOptions" hidden>
    @unless($hasOfficeRuntime)<p class="form-note">{{ __('ui.protected_release_required') }} <a href="{{ route('releases.create') }}">{{ __('ui.new_release') }}</a></p>@endunless
    <label>{{ __('ui.customer_panel_url') }}<input type="text" name="deployment[app_url]" dir="ltr" value="{{ old('deployment.app_url') }}" placeholder="https://office.customer.ir"><small>{{ __('ui.customer_https_help') }}</small>@error('deployment.app_url')<small class="field-error">{{ $message }}</small>@enderror</label>
    <label>{{ __('ui.initial_admin_email') }}<input type="email" name="deployment[admin_email]" value="{{ old('deployment.admin_email') }}"></label>
    <label>{{ __('ui.initial_admin_name') }}<input name="deployment[admin_name]" value="{{ old('deployment.admin_name', __('ui.initial_admin_default_name')) }}"></label>
    <label>{{ __('ui.web_bind_ip') }}<input name="deployment[bind_ip]" value="{{ old('deployment.bind_ip', '127.0.0.1') }}"></label>
    <label>{{ __('ui.web_port') }}<input name="deployment[port]" type="number" min="1024" max="65535" value="{{ old('deployment.port', 8080) }}"></label>
    <label>{{ __('ui.customer_proxy_network') }}<input name="deployment[proxy_network]" value="{{ old('deployment.proxy_network') }}" placeholder="proxynet"></label>
    </div>
    <p class="form-note">{{ __('ui.installer_code_lifecycle') }}</p>
    <label>{{ __('ui.customer') }}<select name="customer_id" required>@foreach($customers as $x)<option value="{{ $x->id }}" @selected((string) old('customer_id') === (string) $x->id)>{{ $x->name }}</option>@endforeach</select></label>
    <label>{{ __('ui.product') }}<select name="product_id" id="helperProduct" required>@foreach($products as $x)<option value="{{ $x->id }}" @selected((string) old('product_id', $products->firstWhere('slug', 'office')?->id) === (string) $x->id)>{{ $x->name }}</option>@endforeach</select></label>
    <label>{{ __('ui.release') }}<select name="release_id" id="helperRelease"><option value="">{{ __('ui.existing_version_optional') }}</option>@foreach($releases as $x)<option value="{{ $x->id }}" data-product="{{ $x->product_id }}" data-ready="{{ $x->isOfficeUpdateReady() ? '1' : '0' }}" @selected((string) old('release_id') === (string) $x->id)>{{ $x->product?->name }} / {{ $x->version }} / {{ $x->channel->value }} — {{ $x->runtime_manifest ? __('ui.protected_runtime') : __('ui.source_archive') }}</option>@endforeach</select>@error('release_id')<small class="field-error">{{ $message }}</small>@enderror</label>
    <label>{{ __('ui.max_installations') }}<input name="max_installations" type="number" min="1" value="{{ old('max_installations', 1) }}" required></label>
    <x-jalali-picker name="expires_at" :label="__('ui.field_expires_at')" :value="old('expires_at', '')"/>
    @include('admin.feature-plan', ['license' => null])
    <div class="form-actions"><button class="primary">{{ __('ui.generate') }}</button><a href="{{ route('licenses.index') }}">{{ __('ui.cancel') }}</a></div>
</form>
<script>
(() => {
    const mode = document.getElementById('helperMode');
    const options = document.getElementById('freshInstallOptions');
    const product = document.getElementById('helperProduct');
    const release = document.getElementById('helperRelease');
    const show = () => {
        options.hidden = mode.value !== 'installer_once';
        options.querySelectorAll('input').forEach(input => input.disabled = options.hidden);
        release.required = !options.hidden;
        Array.from(release.options).forEach(option => {
            option.disabled = option.value === '' ? !options.hidden : option.dataset.product !== product.value || (!options.hidden && option.dataset.ready !== '1');
            option.hidden = option.value !== '' && option.dataset.product !== product.value;
        });
        if (release.selectedOptions[0]?.disabled) release.value = Array.from(release.options).find(option => !option.disabled)?.value || '';
    };
    mode.addEventListener('change', show);
    product.addEventListener('change', show);
    show();
})();
</script>
@endsection
