@extends('layouts.app')
@section('content')
<div class="page-title"><h1>{{ __('ui.new_license') }}</h1></div>
@unless($hasOfficeRuntime)
<div class="flash error">{{ __('ui.protected_release_required') }} <a href="{{ route('releases.create') }}">{{ __('ui.new_release') }}</a></div>
@endunless
<form class="panel form" method="post" action="{{ route('licenses.store') }}">@csrf
    <label>{{ __('ui.install_mode') }}<select name="activation_mode">
        <option value="installer_once" @selected(old('activation_mode', 'installer_once') === 'installer_once')>{{ __('ui.installer_once') }}</option>
        <option value="legacy" @selected(old('activation_mode') === 'legacy')>{{ __('ui.legacy_license') }}</option>
    </select></label>
    <label>{{ __('ui.customer_panel_url') }}<input type="url" name="deployment[app_url]" value="{{ old('deployment.app_url') }}" placeholder="https://office.customer.ir"></label>
    <label>{{ __('ui.initial_admin_email') }}<input type="email" name="deployment[admin_email]" value="{{ old('deployment.admin_email') }}"></label>
    <label>{{ __('ui.initial_admin_name') }}<input name="deployment[admin_name]" value="{{ old('deployment.admin_name', __('ui.initial_admin_default_name')) }}"></label>
    <label>{{ __('ui.web_bind_ip') }}<input name="deployment[bind_ip]" value="{{ old('deployment.bind_ip', '127.0.0.1') }}"></label>
    <label>{{ __('ui.web_port') }}<input name="deployment[port]" type="number" min="1024" max="65535" value="{{ old('deployment.port', 8080) }}"></label>
    <label>{{ __('ui.customer_proxy_network') }}<input name="deployment[proxy_network]" value="{{ old('deployment.proxy_network') }}" placeholder="proxynet"></label>
    <p class="form-note">{{ __('ui.installer_code_lifecycle') }}</p>
    <label>{{ __('ui.customer') }}<select name="customer_id" required>@foreach($customers as $x)<option value="{{ $x->id }}" @selected((string) old('customer_id') === (string) $x->id)>{{ $x->name }}</option>@endforeach</select></label>
    <label>{{ __('ui.product') }}<select name="product_id" required>@foreach($products as $x)<option value="{{ $x->id }}" @selected((string) old('product_id') === (string) $x->id)>{{ $x->name }}</option>@endforeach</select></label>
    <label>{{ __('ui.release') }}<select name="release_id"><option value="">—</option>@foreach($releases as $x)<option value="{{ $x->id }}" @selected((string) old('release_id') === (string) $x->id)>{{ $x->product?->name }} / {{ $x->version }} / {{ $x->channel->value }} — {{ $x->runtime_manifest ? __('ui.protected_runtime') : __('ui.source_archive') }}</option>@endforeach</select></label>
    <label>{{ __('ui.max_installations') }}<input name="max_installations" type="number" min="1" value="{{ old('max_installations', 1) }}" required></label>
    <label>{{ __('ui.field_expires_at') }}<input name="expires_at" type="date" value="{{ old('expires_at') }}"></label>
    <button class="primary">{{ __('ui.generate') }}</button>
</form>
@endsection
