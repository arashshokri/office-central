@extends(auth()->check() ? 'layouts.app' : 'office-agent.guest')
@section('content')
@php
    $updating = $updating ?? false;
    $allowed = $allowed ?? false;
    $canManage = in_array(auth()->user()?->role, ['admin','general_manager'], true);
@endphp
<section class="office-license-state card" aria-labelledby="licenseStateTitle">
    <div class="card-body p-4 p-md-5">
        <div class="license-state-icon {{ $updating ? 'is-updating' : ($allowed ? 'is-active' : '') }}"><i class="fa-solid {{ $updating ? 'fa-arrows-rotate' : ($allowed ? 'fa-shield-halved' : 'fa-lock') }}" aria-hidden="true"></i></div>
        <h1 id="licenseStateTitle" class="h5 fw-bold mb-3">{{ $updating ? 'سامانه در حال بروزرسانی است' : ($allowed ? 'مجوز سامانه فعال است' : 'سامانه غیرفعال شده است') }}</h1>
        @if($updating)
            <p class="text-body-secondary small lh-lg">بروزرسانی در حال انجام است. پس از پایان نصب، دسترسی سامانه دوباره برقرار می‌شود.</p>
        @elseif($allowed)
            <p class="text-body-secondary small lh-lg">لایسنس روی این سرور معتبر و سامانه فعال است.</p>
        @else
            <p class="text-body-secondary small lh-lg">برای فعال‌سازی سامانه، با <strong class="text-body">واحد فروش یا نماینده فنی خود</strong> در ارتباط باشید.</p>
        @endif
        <div class="license-data-notice small"><i class="fa-solid fa-database" aria-hidden="true"></i><span>اطلاعات، فایل‌ها و دیتابیس شما حفظ شده‌اند.</span></div>
        @if($canManage && !$updating && !$allowed)
        <form method="post" action="{{ route('office-agent.reactivate') }}" class="mt-4">@csrf
            <label class="form-label small fw-semibold" for="license">لایسنس جدید</label>
            <input id="license" name="license_key" class="form-control @error('license_key') is-invalid @enderror" type="text" required minlength="10" maxlength="40" autocomplete="off" autocapitalize="characters" spellcheck="false" dir="ltr" placeholder="OFF-XXXX-XXXX-XXXX-XXXX">
            @error('license_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <button class="btn btn-primary w-100 mt-3"><i class="fa-solid fa-key ms-2" aria-hidden="true"></i>فعال‌سازی سامانه</button>
        </form>
        @elseif(!$allowed && !$updating && auth()->check())
            <p class="small text-body-secondary mt-3 mb-0">ثبت لایسنس جدید توسط مدیرکل یا مدیرسیستم انجام می‌شود.</p>
        @endif
        <div class="license-state-actions mt-4">
            @if($canManage)<a class="btn btn-outline-secondary btn-sm" href="{{ route('settings.system-update') }}"><i class="fa-solid fa-arrows-rotate ms-1"></i>{{ $updating ? 'پیگیری بروزرسانی' : 'وضعیت لایسنس' }}</a>
            @elseif(!auth()->check())<a class="btn btn-primary btn-sm" href="{{ route('login') }}">ورود مدیر سامانه</a>@endif
            @if($allowed)<a class="btn btn-outline-secondary btn-sm" href="/">بازگشت به Office</a>@endif
        </div>
    </div>
</section>
@endsection
@push('styles')
<style>
.office-license-state{width:100%;max-width:590px;margin:clamp(16px,5vh,60px) auto;border:1px solid var(--bs-border-color);border-radius:18px;background:var(--bs-body-bg);box-shadow:0 8px 30px #17203308}.office-license-state h1{font-size:1.1rem;line-height:1.8}.office-license-state p{line-height:2}.license-state-icon{width:54px;height:54px;display:grid;place-items:center;border-radius:16px;font-size:20px;color:var(--app-primary);background:color-mix(in srgb,var(--app-primary) 10%,var(--bs-body-bg));margin-bottom:24px}.license-state-icon.is-active{color:var(--bs-success);background:color-mix(in srgb,var(--bs-success) 10%,var(--bs-body-bg))}.license-data-notice{display:flex;align-items:center;gap:10px;padding:13px 15px;border:1px solid var(--bs-border-color);border-radius:10px;background:var(--bs-tertiary-bg);color:var(--bs-secondary-color)}.license-data-notice i{color:var(--app-primary)}.license-state-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.office-license-state .form-control{font-size:.85rem;min-height:45px}.office-license-state .btn{font-size:.8rem;padding:10px 14px}.office-license-state [hidden]{display:none!important}
</style>
@endpush
