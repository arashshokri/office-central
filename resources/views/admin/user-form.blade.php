@extends('layouts.app')
@section('content')
<div class="page-title"><div><h1>{{ __($managedUser ? 'ui.edit_user' : 'ui.create_user') }}</h1><p>{{ __('ui.admin_users_help') }}</p></div><a class="secondary button" href="{{ route('users.index') }}">{{ __('ui.back') }}</a></div>
<div class="user-editor-grid">
    <form class="panel stack-form" method="post" action="{{ $managedUser ? route('users.update',$managedUser) : route('users.store') }}">@csrf @if($managedUser) @method('PUT') @endif
        <div class="section-heading"><h2>{{ __('ui.account_information') }}</h2></div>
        <label>{{ __('ui.name') }}<input name="name" value="{{ old('name',$managedUser?->name) }}" maxlength="255" required>@error('name')<small class="field-error">{{ $message }}</small>@enderror</label>
        <label>{{ __('ui.email') }}<input type="email" name="email" value="{{ old('email',$managedUser?->email) }}" dir="ltr" maxlength="255" autocomplete="off" required>@error('email')<small class="field-error">{{ $message }}</small>@enderror</label>
        <label>{{ __('ui.role') }}<select name="role">@foreach(['viewer','admin','super_admin'] as $role)<option value="{{ $role }}" @selected(old('role',$managedUser?->role ?? 'viewer')===$role)>{{ __('ui.role_'.$role) }}</option>@endforeach</select></label>
        @if($managedUser)<label>{{ __('ui.status') }}<select name="active"><option value="1" @selected((string)old('active',(int)$managedUser->active)==='1')>{{ __('ui.enabled') }}</option><option value="0" @selected((string)old('active',(int)$managedUser->active)==='0')>{{ __('ui.disabled') }}</option></select></label>@endif
        <label>{{ __('ui.password') }}<input type="password" name="password" minlength="12" autocomplete="new-password" @required(!$managedUser)><small class="muted">{{ __($managedUser ? 'ui.keep_password_help' : 'ui.password_length_help') }}</small>@error('password')<small class="field-error">{{ $message }}</small>@enderror</label>
        <label>{{ __('ui.password_confirmation') }}<input type="password" name="password_confirmation" minlength="12" autocomplete="new-password" @required(!$managedUser)></label>
        <div class="form-actions"><button class="primary">{{ __('ui.save') }}</button><a href="{{ route('users.index') }}">{{ __('ui.cancel') }}</a></div>
    </form>
    <section class="panel role-guide"><div class="section-heading"><h2>{{ __('ui.access_levels') }}</h2><x-icon name="shield"/></div>
        @foreach(['viewer','admin','super_admin'] as $role)<article><strong>{{ __('ui.role_'.$role) }}</strong><p>{{ __('ui.role_help_'.$role) }}</p></article>@endforeach
        <p class="muted">{{ __('ui.user_security_help') }}</p>
    </section>
</div>
@endsection
