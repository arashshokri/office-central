@php($uiSettings = \App\Models\AppSetting::values())
<!doctype html>
<html lang="fa" dir="rtl"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $uiSettings['site_title'] }} — وضعیت سامانه</title>
    <script>try{const theme=localStorage.getItem('app-theme')==='dark'?'dark':'light';document.documentElement.dataset.theme=theme;document.documentElement.setAttribute('data-bs-theme',theme);}catch{}</script>
    <link href="{{ asset('vendor/vazirmatn/vazirmatn.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/bootstrap/css/bootstrap.rtl.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/fontawesome/css/all.min.css') }}" rel="stylesheet">
    <style>
        :root{--app-primary:{{ $uiSettings['primary_color'] }};--app-background:{{ $uiSettings['background_color'] }}}
        body{margin:0;font-family:Vazirmatn,Tahoma,sans-serif;background:var(--app-background);color:var(--bs-body-color)}
        html[data-theme=dark] body{background:#0f172a}.license-guest{min-height:100dvh;display:grid;place-items:center;padding:24px 16px}.license-guest>div{width:min(560px,100%)}
        .license-brand{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:24px;font-size:14px;font-weight:700;color:var(--bs-body-color)}.license-brand img{width:32px;height:32px}
        .btn-primary{background:var(--app-primary);border-color:var(--app-primary)}
    </style>
    @stack('styles')
</head><body><main class="license-guest"><div><div class="license-brand"><img src="{{ asset('favicon.svg') }}" alt=""><span>{{ $uiSettings['site_title'] }}</span></div>@yield('content')</div></main>
<script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>@stack('scripts')
</body></html>
