@extends('layouts.app')

@section('content')
<div class="page-title">
    <div><h1>{{ $title }}</h1><p>{{ __('ui.manage_records') }}</p></div>
    @isset($createRoute)<a class="primary button" href="{{ $createRoute }}">+ {{ __('ui.create') }}</a>@endisset
</div>
<section class="panel">
    <div class="table-wrap">
        <table>
            <thead><tr>
                @foreach($columns as $column)<th>{{ __('ui.field_'.str_replace('.', '_', $column)) }}</th>@endforeach
                @if(isset($actions) || isset($showRoute))<th>{{ __('ui.actions') }}</th>@endif
            </tr></thead>
            <tbody>
            @forelse($rows as $row)
                <tr>
                    @foreach($columns as $column)
                        @php($value=data_get($row,$column))
                        <td>
                            @if($value instanceof \BackedEnum)
                                <span class="badge {{ $value->value }}">{{ __('ui.state_'.$value->value) }}</span>
                            @elseif($value instanceof \Carbon\CarbonInterface)
                                <span title="{{ $value->toDateTimeString() }}">{{ $value->diffForHumans() }}</span>
                            @elseif(str_contains($column,'sha256'))
                                <code title="{{ $value }}">{{ $value?substr($value,0,12).'…':'—' }}</code>
                            @else
                                {{ $value ?? '—' }}
                            @endif
                        </td>
                    @endforeach
                    @if(isset($actions) || isset($showRoute))
                    <td class="actions">
                        @isset($showRoute)<a class="table-action" href="{{ route($showRoute,$row) }}">{{ __('ui.view') }}</a>@endisset
                        @if(($actions ?? null)==='releases' && $row->status->value==='draft')
                            <form method="post" action="{{ route('releases.publish',$row) }}">@csrf<button>{{ __('ui.publish') }}</button></form>
                        @endif
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
