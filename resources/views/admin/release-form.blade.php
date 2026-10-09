@extends('layouts.app')
@section('content')
@php($published = $release?->status->value === 'published')
<div class="page-title"><div><h1>{{ __($release ? 'ui.edit_release' : 'ui.new_release') }}</h1><p>{{ __('ui.release_form_help') }}</p></div><a class="secondary button" href="{{ route('releases.index') }}">{{ __('ui.back') }}</a></div>
<form class="panel form record-form" method="post" enctype="multipart/form-data" action="{{ $release ? route('releases.update',$release) : route('releases.store') }}" data-release-upload data-upload-url="{{ route('release-uploads.store', [], false) }}" data-release-id="{{ $release?->id }}" data-max-upload-bytes="1073741824" data-upload-text="{{ json_encode(collect(['uploading','processing','saved','failed','tooLarge','sessionExpired','rateLimit','proxyError','networkError','retrying','conflict'])->mapWithKeys(fn($key) => [$key => __('ui.upload_'.$key)])) }}">@csrf @if($release) @method('PUT') @endif
    @if($published)<div class="flash">{{ __('ui.published_release_immutable') }}</div>@endif
    <label>{{ __('ui.product') }}
        @if($published)<input value="{{ $release->product?->name }}" readonly>
        @else<select name="product_id" required><option value="">{{ __('ui.select') }}</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string)old('product_id',$release?->product_id)===(string)$product->id)>{{ $product->name }}</option>@endforeach</select>@endif
        @error('product_id')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>{{ __('ui.version') }}<input name="version" dir="ltr" value="{{ old('version',$release?->version) }}" required placeholder="3.8.22" @readonly($published)>@error('version')<small class="field-error">{{ $message }}</small>@enderror</label>
    <label>{{ __('ui.channel') }}<select name="channel" @disabled($published)>@foreach(['stable','beta','alpha','internal'] as $channel)<option value="{{ $channel }}" @selected(old('channel',$release?->channel->value ?? 'stable')===$channel)>{{ __('ui.channel_'.$channel) }}</option>@endforeach</select></label>
    @unless($published)<label class="package-dropzone wide-field"><x-icon name="upload"/><strong>{{ __('ui.upload_package') }}</strong><span>{{ __('ui.upload_package_help') }}</span><input type="file" name="package" accept=".zip,application/zip" @required(!$release) data-package-file><small data-package-name>{{ __('ui.no_file_selected') }}</small>@if($release)<small class="muted">{{ __('ui.keep_current_package') }} <bdi>{{ $release->package_filename }}</bdi></small>@endif @error('package')<small class="field-error">{{ $message }}</small>@enderror</label>@endunless
    <label class="wide-field">{{ __('ui.release_notes') }}<textarea name="release_notes" rows="6">{{ old('release_notes',$release?->release_notes) }}</textarea></label>
    <input type="hidden" name="is_security" value="0"><label class="check-label"><input type="checkbox" name="is_security" value="1" @checked(old('is_security',$release?->is_security))>{{ __('ui.security_update') }}</label>
    <div class="form-actions"><button class="primary">{{ __('ui.save') }}</button><a href="{{ route('releases.index') }}">{{ __('ui.cancel') }}</a></div>
    <div class="upload-panel wide-field" data-upload-panel hidden>
        <div class="section-heading"><strong data-upload-status role="status" aria-live="polite"></strong><bdi data-upload-percent>0%</bdi></div>
        <progress max="100" value="0" data-upload-progress aria-label="{{ __('ui.upload_progress') }}"></progress>
        <p class="field-error" data-upload-error role="alert" hidden></p>
    </div>
</form>
@endsection
