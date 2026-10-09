@extends('layouts.app')
@section('content')
<div class="page-title"><div><h1>{{ __('ui.edit_license') }}</h1><p>{{ __('ui.license_edit_help') }}</p></div><a class="secondary button" href="{{ route('licenses.show',$license) }}">{{ __('ui.back') }}</a></div>
<form class="panel form record-form" method="post" action="{{ route('licenses.update',$license) }}">@csrf @method('PUT')
    <label>{{ __('ui.customer') }}<input value="{{ $license->customer?->name }}" readonly></label>
    <label>{{ __('ui.product') }}<input value="{{ $license->product?->name }}" readonly><input type="hidden" data-plan-product value="{{ $license->product_id }}"></label>
    <label>{{ __('ui.license_name') }}<input name="display_name" maxlength="120" value="{{ old('display_name',$license->display_name) }}"></label>
    <label>{{ __('ui.edition') }}<input name="edition" maxlength="80" value="{{ old('edition',$license->edition) }}" placeholder="{{ __('ui.edition_example') }}"></label>
    <div><x-jalali-picker name="expires_at" :label="__('ui.field_expires_at')" :value="old('expires_at', $license->expires_at?->format('Y-m-d') ?? '')"/><small>{{ __('ui.expiry_optional_help') }}</small></div>
    @include('admin.feature-plan')
    <div class="form-actions"><button class="primary">{{ __('ui.save') }}</button><a href="{{ route('licenses.show',$license) }}">{{ __('ui.cancel') }}</a></div>
</form>
@endsection
