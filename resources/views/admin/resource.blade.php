@extends('layouts.app')

@section('content')
@php($canManage = in_array(auth()->user()->role, ['super_admin', 'admin'], true))
@php($hasActions = isset($showRoute) || ($canManage && (isset($actions) || isset($editRoute) || isset($deleteRoute))))
<div class="page-title">
    <div><h1>{{ $title }}</h1><p>{{ __('ui.manage_records') }}</p></div>
    @if($canManage && isset($createRoute))<a class="primary button" href="{{ $createRoute }}">+ {{ __('ui.create') }}</a>@endif
</div>
<section class="panel">
    <div class="table-wrap">
        <table>
            <thead><tr>
                @foreach($columns as $column)<th>{{ __('ui.field_'.str_replace('.', '_', $column)) }}</th>@endforeach
                @if($hasActions)<th>{{ __('ui.actions') }}</th>@endif
            </tr></thead>
            <tbody>
            @forelse($rows as $row)
                <tr>
                    @foreach($columns as $column)
                        @php($value=data_get($row,$column))
                        <td>
                            @if($column === 'license_key_encrypted')
                                @if($value)<div class="license-code-cell"><code id="licenseKey{{ $row->id }}" dir="ltr">{{ $value }}</code><button type="button" data-copy-target="licenseKey{{ $row->id }}">{{ __('ui.copy') }}</button></div>
                                @else<span class="muted">{{ __('ui.legacy_code_unavailable_short') }}</span>@endif
                            @elseif($column === 'status' && is_string($value))
                                <span class="badge {{ $value }}">{{ __('ui.state_'.$value) }}</span>
                            @elseif($value instanceof \BackedEnum)
                                <span class="badge {{ $value->value }}">{{ __($column === 'channel' ? 'ui.channel_'.$value->value : 'ui.state_'.$value->value) }}</span>
                            @elseif($value instanceof \Carbon\CarbonInterface)
                                <span title="{{ $value->toDateTimeString() }}">{{ $value->diffForHumans() }}</span>
                            @elseif(str_contains($column,'sha256'))
                                <code title="{{ $value }}">{{ $value?substr($value,0,12).'…':'—' }}</code>
                            @else
                                {{ $value ?? '—' }}
                            @endif
                        </td>
                    @endforeach
                    @if($hasActions)
                    <td class="actions">
                        @isset($showRoute)<a class="table-action" href="{{ route($showRoute,$row) }}">{{ __('ui.view') }}</a>@endisset
                        @if($canManage && isset($editRoute))<a class="table-action" href="{{ route($editRoute,$row) }}">{{ __('ui.edit') }}</a>@endif
                        @if($canManage && ($actions ?? null)==='releases' && $row->status->value==='draft')
                            <form method="post" action="{{ route('releases.publish',$row) }}">@csrf<button>{{ __('ui.publish') }}</button></form>
                        @endif
                        @if($canManage && isset($deleteRoute))<form method="post" action="{{ route($deleteRoute,$row) }}">@csrf @method('DELETE')<button class="danger-button" data-confirm="{{ __($deleteRoute === 'licenses.destroy' ? 'ui.confirm_delete_license' : 'ui.confirm_delete_record') }}">{{ __('ui.delete') }}</button></form>@endif
                    </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ count($columns)+1 }}" class="empty">{{ __('ui.no_records') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}
</section>
@endsection
