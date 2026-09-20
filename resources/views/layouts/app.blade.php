<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='fa'?'rtl':'ltr' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('ui.app') }}</title><link rel="stylesheet" href="{{ asset('assets/app.css') }}"><link rel="stylesheet" href="{{ asset('assets/control-center.css') }}"><script src="{{ asset('assets/app.js') }}" defer></script>
</head>
<body>
<div class="mobile-backdrop" data-menu-close></div>
<div class="shell">
    <aside id="centralSidebar">
        <div class="brand"><span>OC</span><div><strong>{{ __('ui.app') }}</strong><small>Control Plane</small></div></div>
        <div class="central-health"><i></i><span>panel.ponet.ir</span><small>{{ __('ui.central_online') }}</small></div>
        <nav>
            @php($navigation = [
                ['dashboard','dashboard','⌂'],['customers.index','customers','◉'],['products.index','products','◇'],
                ['releases.index','releases','↟'],['licenses.index','licenses','⌁'],['installations.index','installations','▣'],
                ['security.index','security_events','⚑'],['audit.index','audit_logs','≡'],
            ])
            @foreach($navigation as [$route,$label,$icon])
                <a class="{{ request()->routeIs(str_replace('.index','.*',$route))||request()->routeIs($route)?'active':'' }}" href="{{ route($route) }}"><i>{{ $icon }}</i><span>{{ __('ui.'.$label) }}</span></a>
            @endforeach
            @if(in_array(auth()->user()->role,['super_admin','admin'],true))
                <div class="nav-separator">{{ __('ui.system') }}</div>
                <a class="{{ request()->routeIs('repositories.*')?'active':'' }}" href="{{ route('repositories.index') }}"><i>⌘</i><span>{{ __('ui.repositories') }}</span></a>
            @endif
            @if(auth()->user()->role==='super_admin')
                <a class="{{ request()->routeIs('users.*')?'active':'' }}" href="{{ route('users.index') }}"><i>♙</i><span>{{ __('ui.admin_users') }}</span></a>
            @endif
        </nav>
        <div class="aside-foot"><span>Office Central</span><strong>v{{ config('office.version') }}</strong></div>
    </aside>
    <main>
        <header>
            <button class="menu" type="button" data-menu-toggle aria-controls="centralSidebar" aria-expanded="false">☰</button>
            <div class="header-context"><strong>{{ __('ui.control_center') }}</strong><small>{{ now()->translatedFormat('l، j F Y') }}</small></div>
            <div class="spacer"></div>
            <button class="icon-button" type="button" data-theme-toggle title="{{ __('ui.change_theme') }}">◐</button>
            <form method="post" action="{{ route('locale',app()->getLocale()==='fa'?'en':'fa') }}">@csrf<button class="ghost">{{ __('ui.language') }}</button></form>
            <a class="user-chip" href="{{ route('security.settings') }}"><span>{{ mb_substr(auth()->user()->name,0,1) }}</span><div><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->role }}</small></div></a>
            <form method="post" action="{{ route('logout') }}">@csrf<button class="icon-button" title="{{ __('ui.logout') }}">⇥</button></form>
        </header>
        <section class="content">
            @if(session('success'))<div class="flash success"><i>✓</i><span>{{ session('success') }}</span></div>@endif
            @if($errors->any())<div class="flash error"><i>!</i><span>{{ $errors->first() }}</span></div>@endif
            @yield('content')
        </section>
    </main>
</div>
<dialog class="confirm-dialog" data-confirm-dialog><form method="dialog"><div class="dialog-icon">!</div><h2>{{ __('ui.confirm_action') }}</h2><p data-confirm-message></p><div><button value="cancel" class="secondary">{{ __('ui.cancel') }}</button><button value="confirm" class="danger-button">{{ __('ui.confirm') }}</button></div></form></dialog>
</body>
</html>
