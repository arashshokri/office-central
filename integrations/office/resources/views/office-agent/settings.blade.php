@extends('layouts.app')
@section('content')
@php
    $license = $licenseState['license'] ?? [];
    $active = $helperEnabled && ($decision['allowed'] ?? false);
    $formatDate = fn ($date) => $date ? \App\Support\JalaliDate::format(\Illuminate\Support\Carbon::parse($date), 'Y/m/d H:i') : '—';
    $expiry = array_key_exists('expires_at', $license) ? ($license['expires_at'] ? $formatDate($license['expires_at']) : 'لایسنس مادام العمر') : 'پس از اتصال نمایش داده می‌شود';
    $licenseCode = $license['display_key'] ?? null;
    if ($licenseCode && preg_match('/[•*…]/u', $licenseCode)) $licenseCode = null;
@endphp
<div class="office-update-page" id="officeSystemUpdate" data-check-url="{{ route('settings.system-update.check') }}" data-install-url="{{ route('settings.system-update.install') }}" data-status-url="{{ route('settings.system-update.status') }}" data-installed-version="{{ $installedVersion }}">
    <div class="d-flex align-items-center justify-content-between gap-3 mb-4 flex-wrap">
        <div class="d-flex align-items-center gap-3"><span class="office-update-heading-icon"><i class="fa-solid fa-arrows-rotate"></i></span><div><h1 class="h5 mb-1 fw-bold">بروزرسانی سامانه</h1><p class="text-body-secondary small mb-0">وضعیت لایسنس و نسخه‌های مجاز سامانهٔ شما</p></div></div>
        <a href="{{ route('settings.index') }}" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-right ms-1"></i>تنظیمات سامانه</a>
    </div>
    <div class="office-update-cards">
        @foreach([
            ['fa-shield-halved','وضعیت لایسنس:', $helperEnabled ? 'متصل' : 'متصل نشده'],
            ['fa-calendar-check','تاریخ فعالسازی', $formatDate($license['activated_at'] ?? null)],
            ['fa-calendar-days','اعتبار لایسنس', $expiry],
            ['fa-circle-check','وضعیت فعلی لایسنس', $active ? 'فعال' : ($helperEnabled ? 'غیرفعال' : 'در انتظار اتصال')],
            ['fa-cube','نسخهٔ نصب‌شده', 'v'.$installedVersion],
        ] as [$icon,$title,$value])
        <article class="office-update-stat"><span><i class="fa-solid {{ $icon }}"></i></span><div><small>{{ $title }}</small><strong>{{ $value }}</strong></div></article>
        @endforeach
    </div>
    <section class="card mb-3"><div class="card-body p-4">
        <div class="d-flex align-items-center gap-2 mb-3"><i class="fa-solid fa-key text-primary"></i><h2 class="h6 mb-0 fw-bold">تنظیمات لایسنس</h2></div>
        <p class="small text-body-secondary">اطلاعات لایسنس فعال سامانهٔ شما</p>
        <div class="row g-3">
            <div class="col-12"><label class="form-label small" for="licenseKey">لایسنس ثبت‌شده</label><div class="office-license-code"><input id="licenseKey" class="form-control" dir="ltr" readonly value="{{ $licenseCode ?? '' }}" placeholder="{{ $helperEnabled ? 'کد این لایسنس قدیمی ذخیره نشده است' : 'هنوز لایسنسی ثبت نشده است' }}">@if($licenseCode)<button class="btn btn-outline-secondary btn-sm" type="button" id="copyLicense" aria-label="کپی لایسنس"><i class="fa-regular fa-copy"></i><span>کپی</span></button>@endif</div></div>
            <div class="col-md-6"><label class="form-label small" for="licenseCustomer">صاحب مجوز</label><input id="licenseCustomer" class="form-control" readonly value="{{ $license['customer'] ?? '—' }}"></div>
            <div class="col-md-6"><label class="form-label small" for="licenseExpiry">تاریخ انقضا</label><input id="licenseExpiry" class="form-control" readonly value="{{ $expiry }}"></div>
        </div>
        @if($helperEnabled && !$licenseCode)<p class="small text-body-secondary mt-3 mb-0">برای نمایش کد کاملِ لایسنس قدیمی، با واحد فروش یا نماینده فنی خود در ارتباط باشید.</p>@endif
        @if($helperEnabled && !$active)<div class="alert alert-warning mt-3 mb-0 small">برای فعال‌سازی سامانه، با واحد فروش یا نماینده فنی خود در ارتباط باشید. <a class="alert-link" href="{{ route('office-agent.license') }}">ثبت لایسنس جدید</a></div>@endif
    </div></section>
    <section class="card"><div class="card-body p-4">
        <div class="d-flex align-items-center gap-2 mb-3"><i class="fa-solid fa-cloud-arrow-down text-primary"></i><h2 class="h6 mb-0 fw-bold">بروزرسانی سامانه</h2></div>
        @unless($helperEnabled)
        <p class="small text-body-secondary">برای فعال‌سازی این بخش، با واحد فروش یا نماینده فنی خود در ارتباط باشید.</p>
        @else
        <p class="small text-body-secondary mb-3">نسخه‌های مجاز لایسنس شما قابل نصب هستند. پیش از نصب، بکاپ تهیه می‌شود و دسترسی کاری در طول بروزرسانی موقتاً متوقف خواهد شد.</p>
        <div class="office-update-offer mb-3" id="updateOffer" hidden><div class="d-flex align-items-center gap-2 flex-wrap"><strong>بروزرسانی جدید در دسترس است</strong><span class="badge text-bg-warning" id="securityUpdate" hidden>بروزرسانی امنیتی</span></div><p id="releaseNotes" class="small text-body-secondary mt-2 mb-0"></p></div>
        <div class="office-update-actions">
            <button class="btn btn-outline-primary btn-sm" type="button" id="checkUpdate"><i class="fa-solid fa-magnifying-glass ms-1"></i>چک کردن بروزرسانی</button>
            <div class="office-install-action"><button class="btn btn-primary btn-sm" type="button" id="installUpdate" disabled><i class="fa-solid fa-download ms-1"></i>بروزرسانی سامانه</button><span id="versionComparison" class="small" hidden>نسخهٔ فعلی: <bdi id="currentVersion">v{{ $installedVersion }}</bdi><i class="fa-solid fa-arrow-left mx-2" aria-hidden="true"></i>نسخهٔ جدید: <bdi id="offeredVersion"></bdi></span></div>
        </div>
        <div class="office-update-progress mt-3" id="checkProgress" role="status" aria-live="polite" hidden><div class="d-flex justify-content-between gap-3 mb-2"><span id="checkMessage">در حال بررسی بروزرسانی…</span><bdi id="checkPercent">0%</bdi></div><div class="progress" role="progressbar" aria-label="پیشرفت بررسی بروزرسانی" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="checkProgressTrack"><div class="progress-bar" id="checkProgressBar" style="width:0%"></div></div></div>
        <div class="d-flex align-items-center gap-3 mt-3" id="updateProgressActions" hidden><button class="btn btn-outline-primary btn-sm" type="button" id="showUpdateProgress" hidden>مشاهدهٔ پیشرفت بروزرسانی</button><small class="text-body-secondary" id="operationSummary"></small></div>
        <pre class="alert alert-danger small mt-3 mb-0" id="checkError" role="alert" hidden></pre>
        @endunless
    </div></section>
</div>
<div class="modal fade" id="officeUpdateConfirm" tabindex="-1" aria-labelledby="officeUpdateConfirmTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-6 fw-bold" id="officeUpdateConfirmTitle">تأیید بروزرسانی سامانه</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button></div><div class="modal-body"><p id="confirmUpdateQuestion">می‌خواهید سامانه به نسخهٔ جدید بروزرسانی شود؟</p><div class="office-update-confirm-versions"><span>نسخهٔ فعلی<strong dir="ltr" id="confirmCurrentVersion"></strong></span><i class="fa-solid fa-arrow-left" aria-hidden="true"></i><span>نسخهٔ جدید<strong dir="ltr" id="confirmNewVersion"></strong></span></div><p class="small text-body-secondary mb-0 mt-3">ابتدا بکاپ تهیه می‌شود. در طول نصب دسترسی کاری موقتاً متوقف می‌شود؛ اطلاعات و دیتابیس شما حفظ می‌شوند. بستن پنجره، عملیات را متوقف نمی‌کند.</p>        <div class="office-update-progress mt-3" id="updateProgress" role="status" aria-live="polite" hidden>
            <div class="d-flex align-items-center justify-content-between gap-3 mb-2"><div><span class="spinner-border spinner-border-sm" id="updateSpinner" aria-hidden="true"></span> <strong id="updateMessage"></strong></div><bdi id="installPercent">0%</bdi></div>
            <div class="progress" role="progressbar" aria-label="پیشرفت نصب بروزرسانی" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="installProgressTrack"><div class="progress-bar" id="installProgressBar" style="width:0%"></div></div>
            <small id="updateStage" class="text-body-secondary"></small><small class="text-body-secondary">پیشرفت بر اساس مراحل نصب است؛ هنگام ساخت نسخه ممکن است مدتی ثابت بماند. ۱۰۰٪ یعنی نصب و بررسی سلامت با موفقیت تمام شده است.</small>
        </div>
        <pre class="alert alert-danger small mt-3 mb-0" id="updateError" role="alert" hidden></pre>
</div><div class="modal-footer"><button class="btn btn-outline-secondary btn-sm" type="button" id="cancelUpdate" data-bs-dismiss="modal">انصراف</button><button class="btn btn-primary btn-sm" type="button" id="confirmInstall">تأیید و بروزرسانی</button></div></div></div></div>
@endsection
@push('styles')
<style>
.office-update-page{max-width:1440px;margin-inline:auto}.office-update-heading-icon{width:46px;height:46px;display:grid;place-items:center;border-radius:14px;color:var(--app-primary);background:color-mix(in srgb,var(--app-primary) 10%,var(--bs-body-bg));font-size:1.1rem}.office-update-cards{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:20px}.office-update-stat{display:flex;align-items:center;gap:12px;padding:18px 14px;border:1px solid var(--bs-border-color);border-radius:14px;background:var(--bs-body-bg);min-width:0}.office-update-stat>span{width:40px;height:40px;flex:0 0 40px;display:grid;place-items:center;border-radius:12px;color:var(--app-primary);background:color-mix(in srgb,var(--app-primary) 8%,var(--bs-body-bg))}.office-update-stat>div{display:grid;gap:6px;min-width:0}.office-update-stat small{font-size:.68rem;color:var(--bs-secondary-color)}.office-update-stat strong{font-size:.8rem;overflow-wrap:anywhere}.office-update-page .card{border:1px solid var(--bs-border-color);box-shadow:0 3px 12px #17203305;border-radius:14px}.office-update-page input[readonly]{background:var(--bs-tertiary-bg);font-size:.8rem}.office-license-code{display:flex;gap:10px;align-items:center}.office-license-code .form-control{font-family:ui-monospace,monospace;letter-spacing:.04em}.office-license-code .btn{display:flex;align-items:center;gap:6px;flex:0 0 auto}.office-update-offer{padding:16px;border:1px solid color-mix(in srgb,var(--app-primary) 25%,var(--bs-border-color));border-radius:12px;background:color-mix(in srgb,var(--app-primary) 4%,var(--bs-body-bg));font-size:.8rem}.office-update-actions,.office-install-action{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.office-update-progress{padding:14px;background:var(--bs-tertiary-bg);border:1px solid var(--bs-border-color);border-radius:10px;font-size:.8rem}.office-update-progress .progress{height:7px;background:var(--bs-border-color)}.office-update-progress .progress-bar{background:var(--app-primary);transition:width .25s ease}.office-update-progress small{display:block;margin-top:7px}#officeUpdateConfirm [hidden],.office-update-page [hidden]{display:none!important}#updateError{white-space:pre-wrap;overflow-wrap:anywhere}#officeUpdateConfirm .modal-content{border:1px solid var(--bs-border-color);border-radius:16px;background:var(--bs-body-bg)}.office-update-confirm-versions{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px;border-radius:12px;background:var(--bs-tertiary-bg);font-size:.8rem}.office-update-confirm-versions span{display:grid;gap:8px;color:var(--bs-secondary-color)}.office-update-confirm-versions strong{font-size:1rem;color:var(--bs-body-color)}.office-update-confirm-versions>i{color:var(--app-primary)}
@media(max-width:1200px){.office-update-cards{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:700px){.office-update-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.office-install-action{align-items:flex-start;flex-direction:column}}@media(max-width:420px){.office-update-cards{grid-template-columns:1fr}.office-license-code{flex-wrap:wrap}}
@media(prefers-reduced-motion:reduce){.office-update-progress .progress-bar{transition:none}}
</style>
@endpush
@push('scripts')
<script>window.officeUpdateInitial = @json(['job'=>$job,'offer'=>$licenseState['update'] ?? null]);</script>
<script src="{{ asset('js/office-system-update.js') }}?v={{ $installedVersion }}" defer></script>
@endpush
