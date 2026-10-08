@extends('layouts.app')
@section('content')
<div class="page-title"><div><h1>{{ __('ui.admin_users') }}</h1><p>{{ __('ui.admin_users_help') }}</p></div><a class="primary button" href="{{ route('users.create') }}">+ {{ __('ui.create_user') }}</a></div>
<div class="management-metrics">
    <section class="panel"><span>{{ __('ui.total_users') }}</span><strong>{{ $total }}</strong><x-icon name="users"/></section>
    <section class="panel"><span>{{ __('ui.active_users') }}</span><strong>{{ $activeCount }}</strong><x-icon name="shield"/></section>
    <section class="panel"><span>{{ __('ui.two_factor_users') }}</span><strong>{{ $twoFactorCount }}</strong><x-icon name="key"/></section>
</div>
<section class="panel">
    <div class="section-heading"><h2>{{ __('ui.user_accounts') }}</h2><span class="muted">{{ __('ui.users_roles_help') }}</span></div>
    <div class="table-wrap"><table><thead><tr><th>{{ __('ui.name') }}</th><th>{{ __('ui.role') }}</th><th>{{ __('ui.status') }}</th><th>{{ __('ui.two_factor_authentication') }}</th><th>{{ __('ui.actions') }}</th></tr></thead><tbody>
    @forelse($users as $managedUser)
        <tr><td><div class="user-identity"><span class="user-avatar">{{ mb_substr($managedUser->name,0,1) }}</span><div><strong>{{ $managedUser->name }}</strong>@if(auth()->id()===$managedUser->id)<span class="self-label">{{ __('ui.current_account') }}</span>@endif<small dir="ltr">{{ $managedUser->email }}</small></div></div></td>
            <td><span class="role-pill">{{ __('ui.role_'.$managedUser->role) }}</span></td>
            <td><span class="badge {{ $managedUser->active ? 'active' : 'inactive' }}">{{ $managedUser->active ? __('ui.enabled') : __('ui.disabled') }}</span></td>
            <td><span class="muted">{{ $managedUser->two_factor_confirmed_at ? __('ui.enabled') : __('ui.not_configured') }}</span></td>
            <td><a class="table-action" href="{{ route('users.edit',$managedUser) }}">{{ __('ui.edit_user') }}</a></td></tr>
    @empty<tr><td colspan="5" class="empty">{{ __('ui.no_records') }}</td></tr>@endforelse
    </tbody></table></div>{{ $users->links() }}
</section>
@endsection
