<!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>وضعیت مجوز Office</title>
<style>body{font-family:Tahoma,sans-serif;background:#f4f6fb;color:#18243b;margin:0;padding:2rem}main{max-width:560px;margin:10vh auto;background:white;padding:2rem;border-radius:16px;box-shadow:0 10px 35px #19294118}input,button{box-sizing:border-box;width:100%;padding:14px;margin-top:12px;border-radius:8px;border:1px solid #ccd4e0}button{background:#2458c7;color:white;cursor:pointer}p{line-height:2}a{color:#2458c7}.error{color:#a21d26}</style></head>
<body><main><h1>{{ ($allowed ?? false) ? 'مجوز سامانه فعال است' : 'سامانه غیرفعال شده است' }}</h1>
<p>{{ $message ?? 'اطلاعات سامانه حفظ شده است. برای دریافت مجوز معتبر با واحد فروش یا نماینده فروش تماس بگیرید.' }}</p>
<p>اطلاعات و دیتابیس شما حفظ شده‌اند.</p>
@if(auth()->check() && auth()->user()->role === 'admin')
<form method="post" action="{{ route('office-agent.reactivate') }}">@csrf
<label for="license">لایسنس جدید</label><input id="license" name="license_key" type="password" required minlength="10" maxlength="40" autocomplete="off" dir="ltr">
@error('license_key')<p class="error">{{ $message }}</p>@enderror
<button>فعال‌سازی سامانه</button></form>
@elseif(auth()->check())
<p>برای ثبت لایسنس جدید با مدیر سامانه تماس بگیرید.</p>
@else
<a href="/login">ورود مدیر سامانه</a>
@endif
<a href="/license">بررسی مجوز</a></main></body></html>
