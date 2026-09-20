@extends('layouts.app')

@section('content')
<div class="page-title"><div><h1>{{ __('ui.admin_users') }}</h1><p>{{ __('ui.admin_users_help') }}</p></div></div>

<div class="detail-grid user-management-grid">
    <section class="panel">
        <div class="section-heading"><h2>{{ __('ui.create_admin_user') }}</h2></div>
        <form class="stack-form" method="post" action="{{ route('users.store') }}">@csrf
            <label>{{ __('ui.name') }}<input name="name" value="{{ old('name') }}" required></label>
            <label>{{ __('ui.email') }}<input type="email" name="email" value="{{ old('email') }}" required></label>
            <label>{{ __('ui.role') }}<select name="role"><option value="viewer">viewer</option><option value="admin">admin</option><option value="super_admin">super_admin</option></select></label>
            <label>{{ __('ui.password') }}<input type="password" name="password" minlength="12" required></label>
            <label>{{ __('ui.password_confirmation') }}<input type="password" name="password_confirmation" minlength="12" required></label>
            <button class="primary">{{ __('ui.create') }}</button>
        </form>
    </section>
    <section class="panel user-list-panel">
        <div class="section-heading"><h2>{{ __('ui.admin_users') }}</h2></div>
        <div class="user-list">
            @foreach($users as $managedUser)
            <form method="post" action="{{ route('users.update',$managedUser) }}">@csrf @method('PUT')
                <div><strong>{{ $managedUser->name }}</strong><small dir="ltr">{{ $managedUser->email }}</small></div>
                <select name="role"><option @selected($managedUser->role==='viewer')>viewer</option><option @selected($managedUser->role==='admin')>admin</option><option @selected($managedUser->role==='super_admin')>super_admin</option></select>
                <select name="active"><option value="1" @selected($managedUser->active)>{{ __('ui.enabled') }}</option><option value="0" @selected(!$managedUser->active)>{{ __('ui.disabled') }}</option></select>
                <span class="two-factor-dot {{ $managedUser->two_factor_confirmed_at ? 'enabled' : '' }}" title="2FA"></span>
                <button>{{ __('ui.save') }}</button>
            </form>
            @endforeach
        </div>
        {{ $users->links() }}
    </section>
</div>
@endsection
