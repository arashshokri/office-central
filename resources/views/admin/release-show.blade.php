@extends('layouts.app')
@section('content')
@php($manifest = $release->deploymentManifest())
<div class="page-title"><div><h1>{{ $release->product?->name }} <bdi>v{{ $release->version }}</bdi></h1><p>{{ __('ui.release_details_help') }}</p></div><div class="form-actions">@if(auth()->user()->role !== 'viewer')<a class="primary button" href="{{ route('releases.edit',$release) }}">{{ __('ui.edit') }}</a>@endif<a class="secondary button" href="{{ route('releases.index') }}">{{ __('ui.back') }}</a></div></div>
<div class="management-metrics">
    <article><small>{{ __('ui.status') }}</small><strong>{{ __('ui.state_'.$release->status->value) }}</strong></article>
    <article><small>{{ __('ui.package_type') }}</small><strong>{{ __($release->runtime_manifest ? 'ui.protected_runtime' : 'ui.source_archive') }}</strong></article>
    <article><small>{{ __('ui.package_size') }}</small><strong dir="ltr">{{ number_format(($release->package_size ?? 0)/1048576, 1) }} MB</strong></article>
</div>
<div class="detail-grid">
    <section class="panel"><div class="section-heading"><h2>{{ __('ui.release_notes') }}</h2>@if($release->is_security)<span class="badge suspended">{{ __('ui.security_update') }}</span>@endif</div><div class="release-notes">{{ $release->release_notes ?: __('ui.no_release_notes') }}</div></section>
    <section class="panel"><h2>{{ __('ui.package_information') }}</h2><dl class="detail-list">
        <div><dt>{{ __('ui.channel') }}</dt><dd>{{ __('ui.channel_'.$release->channel->value) }}</dd></div>
        <div><dt>{{ __('ui.package') }}</dt><dd class="break-code"><bdi>{{ $release->package_filename ?? '—' }}</bdi></dd></div>
        <div><dt>{{ __('ui.published_at') }}</dt><dd>{{ \App\Support\PanelDate::format($release->published_at) }}</dd></div>
        <div><dt>{{ __('ui.source_reference') }}</dt><dd><bdi>{{ $release->source_reference ?? '—' }}</bdi></dd></div>
        <div><dt>SHA-256</dt><dd class="break-code"><code dir="ltr">{{ $release->package_sha256 ?? '—' }}</code></dd></div>
    </dl></section>
</div>
<section class="panel" id="requirements"><div class="section-heading"><div><h2>{{ __('ui.requirements') }}</h2><p>{{ __('ui.requirements_manifest_help') }}</p></div><span class="badge {{ $release->isOfficeUpdateReady() ? 'published' : 'draft' }}">{{ __($release->isOfficeUpdateReady() ? 'ui.ready_for_helper' : 'ui.not_ready_for_helper') }}</span></div>
@if($manifest)
    <div class="license-version-summary"><div><small>{{ __('ui.architecture') }}</small><strong dir="ltr">{{ $manifest['architecture'] ?? '—' }}</strong></div><div><small>{{ __('ui.source_protection') }}</small><strong dir="ltr">{{ $manifest['source_protection'] ?? '—' }}</strong></div><div><small>{{ __('ui.runtime_format') }}</small><strong dir="ltr">{{ $manifest['format'] ?? '—' }}</strong></div></div>
    @if($release->source_manifest)<p class="muted">{{ __('ui.source_update_agent_help') }}</p>@else
    <div class="table-wrap"><table><thead><tr><th>{{ __('ui.component_role') }}</th><th>{{ __('ui.docker_image') }}</th><th>SHA-256</th></tr></thead><tbody>@forelse($manifest['images'] ?? [] as $image)<tr><td><bdi>{{ $image['role'] ?? '—' }}</bdi></td><td><code dir="ltr">{{ $image['ref'] ?? '—' }}</code></td><td><code dir="ltr">{{ \Illuminate\Support\Str::limit($image['sha256'] ?? '', 16) }}</code></td></tr>@empty<tr><td colspan="3" class="empty">{{ __('ui.no_records') }}</td></tr>@endforelse</tbody></table></div>@endif
@else<p class="muted">{{ __('ui.no_ready_update_release') }}</p>@if($readinessError ?? null)<div class="flash error">{{ __('ui.package_readiness_error') }}: {{ $readinessError }}</div>@endif @endif
<p class="muted">{{ __('ui.runtime_components_note') }}</p>
</section>
@endsection
