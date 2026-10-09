@extends('layouts.app')
@section('content')
<div class="page-title"><div><h1>{{ __('ui.repositories') }}</h1><p>{{ __('ui.repositories_simple_help') }}</p></div></div>
<div class="repository-steps"><span><b>1</b>{{ __('ui.repo_step_connect') }}</span><span><b>2</b>{{ __('ui.repo_step_test') }}</span><span><b>3</b>{{ __('ui.repo_step_sync') }}</span></div>
<div class="repository-workspace">
    <section class="panel">
        <div class="section-heading"><h2>{{ __($editing ? 'ui.edit_repository' : 'ui.connect_repository') }}</h2><x-icon name="code"/></div>
        @if($products->isEmpty())<p class="empty">{{ __('ui.repository_create_product') }}</p><a class="primary button" href="{{ route('products.create') }}">{{ __('ui.new_product') }}</a>
        @else
        <form class="stack-form" method="post" action="{{ $editing ? route('repositories.update',$editing) : route('repositories.store') }}">@csrf @if($editing) @method('PUT') @endif
            <label>{{ __('ui.product') }}<select name="product_id" required><option value="">{{ __('ui.select') }}</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string)old('product_id',$editing?->product_id)===(string)$product->id)>{{ $product->name }}</option>@endforeach</select>@error('product_id')<small class="field-error">{{ $message }}</small>@enderror</label>
            <label>{{ __('ui.repository_address') }}<input name="repository_url" dir="ltr" value="{{ old('repository_url',$editing?->repository_url) }}" placeholder="https://github.com/owner/repository" required><small class="muted">{{ __('ui.repository_address_help') }}</small>@error('repository_url')<small class="field-error">{{ $message }}</small>@enderror</label>
            <details class="advanced-settings" @if($editing || $errors->has('access_token') || $errors->has('release_channel')) open @endif><summary>{{ __('ui.advanced_settings') }}</summary>
                <div class="stack-form">
                    <label>{{ __('ui.channel') }}<select name="release_channel">@foreach(['stable','beta','alpha','internal'] as $channel)<option value="{{ $channel }}" @selected(old('release_channel',$editing?->release_channel ?? 'stable')===$channel)>{{ __('ui.channel_'.$channel) }}</option>@endforeach</select></label>
                    <label>{{ __('ui.github_token_optional') }}<input type="password" name="access_token" autocomplete="new-password"><small class="muted">{{ __($editing?->encrypted_access_token ? 'ui.repository_keep_token' : 'ui.repository_token_help') }}</small>@error('access_token')<small class="field-error">{{ $message }}</small>@enderror</label>
                    @if($editing)<label>{{ __('ui.status') }}<select name="enabled"><option value="1" @selected((string)old('enabled',(int)$editing->enabled)==='1')>{{ __('ui.enabled') }}</option><option value="0" @selected((string)old('enabled',(int)$editing->enabled)==='0')>{{ __('ui.disabled') }}</option></select></label>@endif
                    <label class="check-label"><input type="checkbox" name="auto_publish" value="1" @checked(old('auto_publish',$editing?->auto_publish))>{{ __('ui.auto_publish') }}</label>
                    <small class="muted">{{ __('ui.repository_publish_help') }}</small>
                </div>
            </details>
            <div class="form-actions"><button class="primary">{{ __($editing ? 'ui.save' : 'ui.connect_repository') }}</button>@if($editing)<a href="{{ route('repositories.index') }}">{{ __('ui.cancel') }}</a>@endif</div>
        </form>
        @endif
    </section>
    <section class="panel connected-panel">
        <div class="section-heading"><h2>{{ __('ui.connected_repositories') }}</h2><span class="role-pill">{{ $integrations->count() }}</span></div>
        <div class="connected-repositories">
        @forelse($integrations as $integration)
            <article class="repository-card"><div class="repository-card-title"><strong>{{ $integration->product?->name }}</strong><span class="badge {{ $integration->enabled ? 'active' : 'inactive' }}">{{ $integration->enabled ? __('ui.enabled') : __('ui.disabled') }}</span></div>
                <a class="repository-url" href="{{ $integration->repository_url }}" target="_blank" rel="noopener" dir="ltr">{{ preg_replace('~^https://github.com/~','',$integration->repository_url) }}</a>
                <div class="repository-meta"><span>{{ __('ui.channel_'.$integration->release_channel) }}</span><span>{{ $integration->encrypted_access_token ? __('ui.repository_token_saved') : __('ui.repository_public_access') }}</span><span>{{ $integration->last_sync_at ? \App\Support\PanelDate::format($integration->last_sync_at) : __('ui.never_synced') }}</span></div>
                @if($integration->last_error)<p class="integration-error">{{ $integration->last_error }}</p>@endif
                <div class="form-actions">
                    <form method="post" action="{{ route('repositories.test',$integration) }}">@csrf<button>{{ __('ui.test_connection') }}</button></form>
                    @if($integration->enabled)<form method="post" action="{{ route('repositories.sync',$integration) }}">@csrf<button class="primary">{{ __('ui.sync_now') }}</button></form>@endif
                    <a class="table-action" href="{{ route('repositories.edit',$integration) }}">{{ __('ui.edit') }}</a>
                    <form method="post" action="{{ route('repositories.destroy',$integration) }}">@csrf @method('DELETE')<button class="danger-button" data-confirm="{{ __('ui.confirm_disconnect_repository') }}">{{ __('ui.disconnect') }}</button></form>
                </div>
            </article>
        @empty<div class="empty repository-empty"><x-icon name="code"/><strong>{{ __('ui.no_repository') }}</strong><p>{{ __('ui.no_repository_help') }}</p></div>@endforelse
        </div>
    </section>
</div>
<details class="panel repository-package-help"><summary>{{ __('ui.repository_package_help_title') }}</summary><p>{{ __('ui.repository_office_runtime_help') }}</p></details>
@endsection
