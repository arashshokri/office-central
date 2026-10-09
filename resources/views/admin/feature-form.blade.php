@extends('layouts.app')
@section('content')
<div class="page-title"><div><h1>{{ __($feature ? 'ui.edit_feature' : 'ui.new_feature') }}</h1><p>{{ __('ui.feature_catalog_help') }}</p></div><a class="secondary button" href="{{ route('features.index') }}">{{ __('ui.back') }}</a></div>
<form class="panel form record-form" method="post" action="{{ $feature ? route('features.update',$feature) : route('features.store') }}">@csrf @if($feature)@method('PUT')@endif
    <label>{{ __('ui.product') }}<select name="product_id" required @if($feature) aria-readonly="true" @endif>@foreach($products as $product)@if(!$feature || $product->id === $feature->product_id)<option value="{{ $product->id }}" @selected((string)old('product_id',$feature?->product_id) === (string)$product->id)>{{ $product->name }}</option>@endif @endforeach</select></label>
    <label>{{ __('ui.feature_key') }}<input name="key" dir="ltr" required maxlength="80" pattern="[a-z][a-z0-9_.-]{0,79}" placeholder="projects" value="{{ old('key',$feature?->key) }}" @readonly($feature !== null)>@error('key')<small class="field-error">{{ $message }}</small>@enderror<small>{{ __('ui.feature_key_help') }}</small></label>
    <label>{{ __('ui.name') }}<input name="name" maxlength="120" required value="{{ old('name',$feature?->name) }}"></label>
    <label>{{ __('ui.category') }}<input name="category" maxlength="120" value="{{ old('category',$feature?->category) }}" placeholder="{{ __('ui.feature_category_example') }}"></label>
    <label>{{ __('ui.status') }}<select name="active"><option value="1" @selected(old('active',$feature?->active ?? true))>{{ __('ui.state_active') }}</option><option value="0" @selected(!old('active',$feature?->active ?? true))>{{ __('ui.state_inactive') }}</option></select></label>
    <label>{{ __('ui.feature_policy') }}<select name="is_required"><option value="0" @selected(!old('is_required',$feature?->is_required))>{{ __('ui.optional_feature') }}</option><option value="1" @selected(old('is_required',$feature?->is_required))>{{ __('ui.required_feature') }}</option></select></label>
    <label>{{ __('ui.sort_order') }}<input name="sort_order" type="number" min="0" max="100000" value="{{ old('sort_order',$feature?->sort_order ?? 0) }}" required></label>
    <label class="wide-field">{{ __('ui.field_description') }}<textarea name="description" maxlength="5000" rows="4">{{ old('description',$feature?->description) }}</textarea></label>
    <p class="form-note">{{ __('ui.feature_planning_notice') }}</p><div class="form-actions"><button class="primary">{{ __('ui.save') }}</button><a href="{{ route('features.index') }}">{{ __('ui.cancel') }}</a></div>
</form>
@endsection
