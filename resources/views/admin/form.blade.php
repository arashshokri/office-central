@extends('layouts.app')
@section('content')
<div class="page-title"><div><h1>{{ $title }}</h1><p>{{ __('ui.record_form_help') }}</p></div><a class="secondary button" href="{{ $backRoute }}">{{ __('ui.back') }}</a></div>
<form class="panel form record-form" method="post" action="{{ $action }}">@csrf
    @if(($method ?? 'POST') !== 'POST') @method($method) @endif
    @foreach($fields as $name=>$type)
    @php($value = old($name, data_get($model ?? null, $name, $name === 'status' ? 'active' : '')))
    <label><span>{{ __('ui.field_'.$name) }}</span>
        @if($type==='textarea')<textarea name="{{ $name }}" rows="4">{{ $value }}</textarea>
        @elseif(str_starts_with($type,'select:'))<select name="{{ $name }}">@foreach(explode(',',substr($type,7)) as $option)<option value="{{ $option }}" @selected($value === $option)>{{ __('ui.state_'.$option) }}</option>@endforeach</select>
        @else<input type="{{ $type }}" name="{{ $name }}" value="{{ $value }}" {{ $name==='name'?'required':'' }} @if(in_array($name,['email','phone','slug'])) dir="ltr" @endif>
        @endif
        @error($name)<small class="field-error">{{ $message }}</small>@enderror
        @if($name === 'slug')<small class="muted">{{ __('ui.product_slug_help') }}</small>@endif
    </label>
    @endforeach
    <div class="form-actions"><button class="primary">{{ __('ui.save') }}</button><a href="{{ $backRoute }}">{{ __('ui.cancel') }}</a></div>
</form>
@endsection
