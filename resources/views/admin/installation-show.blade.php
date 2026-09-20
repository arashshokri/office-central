@extends('layouts.app')

@section('content')
<div class="page-title">
    <div><h1>{{ $installation->hostname }}</h1><p dir="ltr">{{ $installation->uuid }}</p></div>
    <a class="secondary button" href="{{ route('installations.index') }}">{{ __('ui.back') }}</a>
</div>

<div class="detail-grid">
    <section class="panel detail-card">
        <div class="section-heading"><h2>{{ __('ui.installation_details') }}</h2><span class="badge {{ $installation->status->value }}">{{ __('ui.state_'.$installation->status->value) }}</span></div>
        <dl class="detail-list">
            <div><dt>{{ __('ui.customer') }}</dt><dd>{{ $installation->license->customer->name }}</dd></div>
            <div><dt>{{ __('ui.product') }}</dt><dd>{{ $installation->license->product->name }}</dd></div>
            <div><dt>{{ __('ui.application_version') }}</dt><dd>{{ $installation->application_version ?: '—' }}</dd></div>
            <div><dt>{{ __('ui.agent_version') }}</dt><dd>{{ $installation->agent_version ?: '—' }}</dd></div>
            <div><dt>{{ __('ui.last_seen') }}</dt><dd>{{ $installation->last_seen_at?->diffForHumans() ?? '—' }}</dd></div>
            <div><dt>{{ __('ui.last_state_sync') }}</dt><dd>{{ $installation->last_state_synced_at?->diffForHumans() ?? '—' }}</dd></div>
            <div><dt>{{ __('ui.ip_address') }}</dt><dd dir="ltr">{{ $installation->last_ip ?: '—' }}</dd></div>
            <div><dt>{{ __('ui.operating_system') }}</dt><dd>{{ trim($installation->os_name.' '.$installation->os_version) ?: '—' }}</dd></div>
        </dl>
    </section>

    <section class="panel">
        <div class="section-heading"><h2>{{ __('ui.installation_controls') }}</h2></div>
        @if(auth()->user()->role !== 'viewer')
        <form class="stack-form" method="post" action="{{ route('installations.target-release',$installation) }}">@csrf @method('PUT')
            <label>{{ __('ui.target_release') }}
                <select name="release_id"><option value="">{{ __('ui.no_target_release') }}</option>@foreach($releases as $release)<option value="{{ $release->id }}" @selected($installation->target_release_id===$release->id)>v{{ $release->version }} — {{ $release->channel->value }}</option>@endforeach</select>
            </label>
            <button class="primary">{{ __('ui.save') }}</button>
        </form>
        <div class="control-divider"></div>
        @if($installation->status->value === 'locked')
            <form method="post" action="{{ route('installations.status',[$installation,'active']) }}">@csrf<button class="success-button" data-confirm="{{ __('ui.confirm_unlock') }}">{{ __('ui.unlock_installation') }}</button></form>
        @else
            <form method="post" action="{{ route('installations.status',[$installation,'locked']) }}">@csrf<button class="danger-button" data-confirm="{{ __('ui.confirm_lock') }}">{{ __('ui.lock_installation') }}</button></form>
        @endif
        @else
            <p class="empty">{{ __('ui.read_only_access') }}</p>
        @endif
    </section>
</div>

<section class="panel">
    <div class="section-heading"><h2>{{ __('ui.recent_events') }}</h2></div>
    <div class="timeline">
        @forelse($installation->events->sortByDesc('occurred_at')->take(20) as $event)
            <div><i></i><strong>{{ $event->type }}</strong><span>{{ $event->occurred_at?->diffForHumans() }}</span></div>
        @empty<p class="empty">{{ __('ui.no_records') }}</p>@endforelse
    </div>
</section>
@endsection
