@extends('layouts.app')
@section('content')
@php($canManage = in_array(auth()->user()->role, ['super_admin','admin'], true))
<div class="page-title"><div><h1>{{ __('ui.releases') }}</h1><p>{{ __('ui.releases_overview_help') }}</p></div>@if($canManage)<div class="form-actions"><a class="secondary button" href="{{ route('repositories.index') }}">{{ __('ui.sync_now') }}</a><a class="primary button" href="{{ route('releases.create') }}">+ {{ __('ui.new_release') }}</a></div>@endif</div>
<section class="panel">
    @include('admin.search')
    <div class="list-summary">{{ __('ui.result_count', ['count' => $rows->total()]) }}</div>
    <div class="table-wrap"><table class="release-table"><thead><tr><th>{{ __('ui.product') }}</th><th>{{ __('ui.version') }}</th><th>{{ __('ui.release_notes') }}</th><th>{{ __('ui.requirements') }}</th><th>{{ __('ui.status') }}</th><th>{{ __('ui.actions') }}</th></tr></thead><tbody>
    @forelse($rows as $release)
    <tr>
        <td><strong>{{ $release->product?->name }}</strong><small>{{ __('ui.channel_'.$release->channel->value) }}</small></td>
        <td><a class="version-link" href="{{ route('releases.show',$release) }}" dir="ltr">v{{ $release->version }}</a>@if($release->is_security)<small class="security-label">{{ __('ui.security_update') }}</small>@endif</td>
        <td class="notes-cell">{{ \Illuminate\Support\Str::limit($release->release_notes ?: __('ui.no_release_notes'), 160) }}</td>
        <td><span class="badge {{ $release->runtime_manifest ? 'published' : '' }}">{{ __($release->runtime_manifest ? 'ui.protected_runtime' : 'ui.source_archive') }}</span><small>{{ $release->runtime_manifest['architecture'] ?? '—' }}</small><a href="{{ route('releases.show',$release) }}#requirements">{{ __('ui.show_requirements') }}</a></td>
        <td><span class="badge {{ $release->status->value }}">{{ __('ui.state_'.$release->status->value) }}</span><small>{{ __($release->isOfficeRuntimeReady() ? 'ui.ready_for_helper' : 'ui.not_ready_for_helper') }}</small></td>
        <td><div class="actions"><a class="table-action" href="{{ route('releases.show',$release) }}">{{ __('ui.view') }}</a>@if($canManage)<a class="table-action" href="{{ route('releases.edit',$release) }}">{{ __('ui.edit') }}</a>@if($release->status->value === 'draft')<form method="post" action="{{ route('releases.publish',$release) }}">@csrf<button>{{ __('ui.publish') }}</button></form>@endif<form method="post" action="{{ route('releases.destroy',$release) }}">@csrf @method('DELETE')<button class="danger-button" data-confirm="{{ __('ui.confirm_delete_record') }}">{{ __('ui.delete') }}</button></form>@endif</div></td>
    </tr>
    @empty<tr><td colspan="6" class="empty">{{ __('ui.no_records') }}</td></tr>@endforelse
    </tbody></table></div>{{ $rows->links() }}
</section>
@endsection
