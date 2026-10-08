@extends('layouts.app')
@section('content')
@php
    $license = $licenseState['license'] ?? [];
    $active = $helperEnabled && ($decision['allowed'] ?? false);
    $formatDate = fn ($date) => $date ? \App\Support\JalaliDate::format(\Illuminate\Support\Carbon::parse($date), 'Y/m/d H:i') : '—';
    $expiry = array_key_exists('expires_at', $license) ? ($license['expires_at'] ? $formatDate($license['expires_at']) : 'لایسنس مادام العمر') : 'پس از اتصال نمایش داده می‌شود';
@endphp
<div class="office-update-page">
    <div class="d-flex align-items-center justify-content-between gap-3 mb-4 flex-wrap">
        <div class="d-flex align-items-center gap-3"><span class="office-update-heading-icon"><i class="fa-solid fa-arrows-rotate"></i></span><div><h1 class="h5 mb-1 fw-bold">بروزرسانی سامانه</h1><p class="text-body-secondary small mb-0">وضعیت مجوز و نسخه‌های مجاز سامانهٔ شما</p></div></div>
        <a href="{{ route('settings.index') }}" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-right ms-1"></i>تنظیمات سامانه</a>
    </div>
    <div class="office-update-cards">
        @foreach([
            ['fa-shield-halved','وضعیت لایسنس', $helperEnabled ? 'متصل به مرکز' : 'هنوز متصل نشده'],
            ['fa-calendar-check','تاریخ فعالسازی', $formatDate($license['activated_at'] ?? null)],
            ['fa-calendar-days','اعتبار لایسنس', $expiry],
            ['fa-circle-check','وضعیت فعلی لایسنس', $active ? 'فعال' : ($helperEnabled ? 'غیرفعال' : 'در انتظار اتصال')],
            ['fa-cube','نسخهٔ نصب‌شده', 'v'.$installedVersion],
        ] as [$icon,$title,$value])
        <article class="office-update-stat"><span><i class="fa-solid {{ $icon }}"></i></span><div><small>{{ $title }}</small><strong>{{ $value }}</strong></div></article>
        @endforeach
    </div>
    <section class="card mb-3"><div class="card-body p-4">
        <div class="d-flex align-items-center gap-2 mb-3"><i class="fa-solid fa-key text-primary"></i><h2 class="h6 mb-0 fw-bold">تنظیمات لایسنس</h2><span class="badge text-bg-light border ms-auto">فقط خواندنی</span></div>
        <p class="small text-body-secondary">اطلاعات مجوز توسط مرکز تعیین می‌شوند. کد یک‌بارمصرف به‌صورت مخفی‌شده نمایش داده می‌شود.</p>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label small" for="licenseId">شناسهٔ لایسنس</label><input id="licenseId" class="form-control" dir="ltr" readonly value="{{ $license['id'] ?? '—' }}"></div>
            <div class="col-md-6"><label class="form-label small" for="licenseKey">لایسنس ثبت‌شده</label><input id="licenseKey" class="form-control" dir="ltr" readonly value="{{ $license['display_key'] ?? '—' }}"></div>
            <div class="col-md-6"><label class="form-label small" for="licenseCustomer">صاحب مجوز</label><input id="licenseCustomer" class="form-control" readonly value="{{ $license['customer'] ?? '—' }}"></div>
            <div class="col-md-6"><label class="form-label small" for="licenseExpiry">تاریخ انقضا</label><input id="licenseExpiry" class="form-control" readonly value="{{ $expiry }}"></div>
        </div>
        @if($helperEnabled && !$active)
        <div class="alert alert-warning mt-3 mb-0 small">{{ $decision['message'] ?? 'برای فعال‌سازی با واحد فروش تماس بگیرید.' }} <a href="{{ route('office-agent.license') }}">ثبت مجوز جایگزین</a></div>
        @endif
    </div></section>
    <section class="card"><div class="card-body p-4">
        <div class="d-flex align-items-center gap-2 mb-3"><i class="fa-solid fa-cloud-arrow-down text-primary"></i><h2 class="h6 mb-0 fw-bold">بروزرسانی سامانه</h2></div>
        @unless($helperEnabled)
        <p class="small text-body-secondary">مدیر سرور برای اتصال Office موجود فرمان زیر را اجرا و کد صادرشده در مرکز را وارد کند.</p>
        <pre class="office-update-command" id="helperCommand" dir="ltr">curl --fail --proto '=https' --tlsv1.2 https://update.ponet.ir/agent/install.sh -o office-install.sh &amp;&amp; sudo bash office-install.sh</pre>
        <button class="btn btn-primary btn-sm" type="button" id="copyHelper"><i class="fa-regular fa-copy ms-1"></i>کپی فرمان اتصال</button>
        @else
        <p class="small text-body-secondary">فقط نسخه‌ای که مدیر مرکز برای لایسنس شما مجاز کرده باشد دریافت می‌شود. پیش از نصب، بکاپ تهیه می‌شود؛ در طول نصب دسترسی کاری موقتاً متوقف خواهد شد.</p>
        <div class="office-update-offer" id="updateOffer" hidden><div><strong id="offeredVersion"></strong><span class="badge text-bg-warning me-2" id="securityUpdate" hidden>بروزرسانی امنیتی</span><p id="releaseNotes" class="small text-body-secondary mt-2 mb-0"></p></div></div>
        <div class="d-flex gap-2 flex-wrap mt-3">
            <button class="btn btn-outline-primary btn-sm" type="button" id="checkUpdate"><i class="fa-solid fa-magnifying-glass ms-1"></i>چک کردن بروزرسانی</button>
            <button class="btn btn-primary btn-sm" type="button" id="installUpdate" disabled><i class="fa-solid fa-download ms-1"></i>بروزرسانی سامانه</button>
        </div>
        <div class="office-update-progress mt-3" id="updateProgress" role="status" aria-live="polite" hidden><span class="spinner-border spinner-border-sm" id="updateSpinner" aria-hidden="true"></span><strong id="updateMessage"></strong><small id="updateStage" class="text-body-secondary"></small></div>
        <pre class="alert alert-danger small mt-3 mb-0" id="updateError" role="alert" style="white-space:pre-wrap;overflow-wrap:anywhere" hidden></pre>
        @endunless
    </div></section>
</div>
@endsection
@push('styles')
<style>
.office-update-page{max-width:1440px;margin-inline:auto}.office-update-heading-icon{width:46px;height:46px;display:grid;place-items:center;border-radius:14px;color:var(--app-primary);background:color-mix(in srgb,var(--app-primary) 10%,var(--bs-body-bg));font-size:1.1rem}.office-update-cards{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:20px}.office-update-stat{display:flex;align-items:center;gap:12px;padding:18px 14px;border:1px solid var(--bs-border-color);border-radius:14px;background:var(--bs-body-bg);min-width:0}.office-update-stat>span{width:40px;height:40px;flex:0 0 40px;display:grid;place-items:center;border-radius:12px;color:var(--app-primary);background:color-mix(in srgb,var(--app-primary) 8%,var(--bs-body-bg))}.office-update-stat>div{display:grid;gap:6px;min-width:0}.office-update-stat small{font-size:.68rem;color:var(--bs-secondary-color)}.office-update-stat strong{font-size:.8rem;overflow-wrap:anywhere}.office-update-page .card{border:1px solid var(--bs-border-color);box-shadow:0 3px 12px #17203305;border-radius:14px}.office-update-page input[readonly]{background:var(--bs-tertiary-bg);font-size:.8rem}.office-update-command{white-space:pre-wrap;overflow-wrap:anywhere;background:var(--bs-tertiary-bg);border:1px solid var(--bs-border-color);padding:16px;border-radius:10px;font-size:.78rem}.office-update-offer{padding:16px;border:1px solid color-mix(in srgb,var(--app-primary) 25%,var(--bs-border-color));border-radius:12px;background:color-mix(in srgb,var(--app-primary) 4%,var(--bs-body-bg))}.office-update-progress{padding:14px;background:var(--bs-tertiary-bg);border-radius:10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:.8rem}.office-update-page [hidden]{display:none!important}.office-update-progress small{width:100%}@media(max-width:1200px){.office-update-cards{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:700px){.office-update-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:420px){.office-update-cards{grid-template-columns:1fr}}
</style>
@endpush
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('copyHelper')?.addEventListener('click', async function(){try{await navigator.clipboard.writeText(document.getElementById('helperCommand').textContent);this.textContent='فرمان کپی شد';}catch{this.textContent='فرمان بالا را انتخاب و کپی کنید';}});
    const check=document.getElementById('checkUpdate'), install=document.getElementById('installUpdate');if(!check)return;
    const progress=document.getElementById('updateProgress'),message=document.getElementById('updateMessage'),error=document.getElementById('updateError'),spinner=document.getElementById('updateSpinner');
    let timer, running=false, reloadScheduled=false;
    const api=async(url,method='GET')=>{const response=await fetch(url,{method,headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},credentials:'same-origin',cache:'no-store'});let data;try{data=await response.json();}catch{throw new Error('پاسخ سامانه در دسترس نیست؛ ممکن است سرویس در حال راه‌اندازی نسخهٔ جدید باشد.');}if(!response.ok)throw new Error(data.message||'درخواست ناموفق بود: HTTP '+response.status);return data;};
    const fail=e=>{error.hidden=false;error.textContent=e.message;};
    const renderJob=job=>{if(!job.status)return;const wasRunning=running;progress.hidden=false;message.textContent=job.message||'';document.getElementById('updateStage').textContent='مرحله: '+(job.stage||'—')+(job.version?' • نسخهٔ '+job.version:'');running=job.status==='running';spinner.hidden=!running;check.disabled=running;install.disabled=running;error.hidden=!job.error;if(job.error)error.textContent=job.error;if(job.status==='success'){install.disabled=true;if(wasRunning&&!reloadScheduled){reloadScheduled=true;window.setTimeout(()=>location.reload(),2500);}}else if(running){clearTimeout(timer);timer=setTimeout(poll,2500);}};
    const poll=async()=>{try{renderJob(await api(@json(route('settings.system-update.status'))));}catch(e){progress.hidden=false;message.textContent='در انتظار راه‌اندازی سرویس…';fail(e);timer=setTimeout(poll,5000);}};
    check.addEventListener('click',async()=>{check.disabled=true;error.hidden=true;try{const data=await api(@json(route('settings.system-update.check')),'POST');if(data.status){renderJob(data);return;}progress.hidden=false;spinner.hidden=true;const offer=data.update||{};document.getElementById('updateOffer').hidden=!offer.available;document.getElementById('offeredVersion').textContent=offer.version?'نسخهٔ '+offer.version:'';document.getElementById('releaseNotes').textContent=offer.notes||'';document.getElementById('securityUpdate').hidden=!offer.security;message.textContent=offer.available?'نسخهٔ مجاز آمادهٔ دریافت است.':'نسخهٔ جدیدتری برای لایسنس شما باز نشده است.';install.disabled=!offer.available;}catch(e){fail(e);}finally{if(!running)check.disabled=false;}});
    install.addEventListener('click',async()=>{if(!confirm('بروزرسانی با تهیهٔ بکاپ آغاز شود؟ دسترسی کاری برای مدت نصب متوقف می‌شود.'))return;install.disabled=true;error.hidden=true;try{renderJob(await api(@json(route('settings.system-update.install')),'POST'));}catch(e){fail(e);install.disabled=false;}});
    renderJob(@json($job));
});
</script>
@endpush
