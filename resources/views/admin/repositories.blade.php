@extends('layouts.app')

@section('content')
<div class="page-title"><div><h1>{{ __('ui.repositories') }}</h1><p>{{ __('ui.repositories_help') }}</p></div></div>

<div class="detail-grid repository-grid">
    <section class="panel">
        <div class="section-heading"><h2>{{ __('ui.connect_repository') }}</h2></div>
        <form class="stack-form" method="post" action="{{ route('repositories.store') }}">@csrf
            <label>{{ __('ui.product') }}<select name="product_id" required>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select></label>
            <label>GitHub URL<input type="url" name="repository_url" placeholder="https://github.com/owner/repository" required></label>
            <label>{{ __('ui.branch') }}<input name="branch" value="main"></label>
            <label>{{ __('ui.channel') }}<select name="release_channel"><option>stable</option><option>beta</option><option>alpha</option><option>internal</option></select></label>
            <label>{{ __('ui.github_token_optional') }}<input type="password" name="access_token" autocomplete="off"></label>
            <label class="check-label"><input type="checkbox" name="auto_publish" value="1">{{ __('ui.auto_publish') }}</label>
            <button class="primary">{{ __('ui.save') }}</button>
        </form>
    </section>
    <section class="panel">
        <div class="section-heading"><h2>{{ __('ui.connected_repositories') }}</h2></div>
        <div class="repository-list">
            @forelse($integrations as $integration)
            <article>
                <div><strong>{{ $integration->product?->name }}</strong><a href="{{ $integration->repository_url }}" target="_blank" rel="noopener" dir="ltr">{{ $integration->repository_url }}</a></div>
                <span class="badge {{ $integration->enabled ? 'active' : 'inactive' }}">{{ $integration->enabled ? __('ui.enabled') : __('ui.disabled') }}</span>
                <small>{{ $integration->last_sync_at?->diffForHumans() ?? __('ui.never_synced') }}</small>
                @if($integration->last_error)<p class="integration-error">{{ $integration->last_error }}</p>@endif
                <form method="post" action="{{ route('repositories.sync',$integration) }}">@csrf<button>{{ __('ui.sync_now') }}</button></form>
            </article>
            @empty<p class="empty">{{ __('ui.no_records') }}</p>@endforelse
        </div>
    </section>
</div>
@endsection
