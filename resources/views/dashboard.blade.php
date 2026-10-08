@extends('layouts.app')
@section('content')
<div class="page-title"><div><h1>{{ __('ui.dashboard') }}</h1><p>{{ __('ui.control_center') }} · {{ __('ui.app') }}</p></div>@if(auth()->user()->role !== 'viewer')<a class="primary" href="{{ route('licenses.create') }}"><x-icon name="key"/>{{ __('ui.new_license') }}</a>@endif</div>
<div class="metrics">
    @foreach(['customers'=>['total_customers','users'],'products'=>['total_products','box'],'active_licenses'=>['active_licenses','key'],'offline_installations'=>['offline_installations','server'],'clone_events'=>['clone_events','shield']] as $key=>[$label,$icon])
    <article><span>{{ __('ui.'.$label) }}</span><strong>{{ number_format($metrics[$key]) }}</strong><i class="{{ in_array($key,['offline_installations','clone_events'])?'warn':'' }}"><x-icon :name="$icon"/></i></article>
    @endforeach
</div>
<div class="grid">
    <section class="panel"><div class="section-heading"><h2>{{ __('ui.recent_installations') }}</h2><a href="{{ route('installations.index') }}" class="table-action">{{ __('ui.view') }}</a></div><div class="table-wrap"><table><thead><tr><th>{{ __('ui.field_hostname') }}</th><th>{{ __('ui.status') }}</th><th>{{ __('ui.last_seen') }}</th></tr></thead><tbody>@forelse($recent as $item)<tr><td><a href="{{ route('installations.show',$item) }}">{{ $item->hostname }}</a></td><td><span class="badge {{ $item->status->value }}">{{ __('ui.state_'.$item->status->value) }}</span></td><td>{{ $item->last_seen_at?->diffForHumans() ?? '—' }}</td></tr>@empty<tr><td colspan="3" class="empty">{{ __('ui.no_records') }}</td></tr>@endforelse</tbody></table></div></section>
    <section class="panel"><div class="section-heading"><h2>{{ __('ui.recent_releases') }}</h2><a href="{{ route('releases.index') }}" class="table-action">{{ __('ui.view') }}</a></div>@forelse($releases as $release)<div class="release"><div><strong>{{ $release->product->name }}</strong><span>{{ $release->is_security ? __('ui.security_update') : $release->channel->value }}</span></div><code dir="ltr">v{{ $release->version }}</code></div>@empty<p class="empty">{{ __('ui.no_records') }}</p>@endforelse</section>
</div>
@endsection
