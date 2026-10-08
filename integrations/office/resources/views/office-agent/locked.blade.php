<!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>وضعیت مجوز Office</title>
<style>body{font-family:Tahoma,sans-serif;background:#f4f6fb;color:#18243b;margin:0;padding:2rem}main{max-width:560px;margin:10vh auto;background:white;padding:2rem;border-radius:16px;box-shadow:0 10px 35px #19294118}input,button{box-sizing:border-box;width:100%;padding:14px;margin-top:12px;border-radius:8px;border:1px solid #ccd4e0}button{background:#2458c7;color:white;cursor:pointer}p{line-height:2}a{color:#2458c7}.error{color:#a21d26}</style></head>
<body><main><h1>{{ ($updating ?? false) ? 'سامانه در حال بروزرسانی است' : (isset($helperEnabled) && !$helperEnabled ? 'helper آمادهٔ اتصال است' : (($allowed ?? false) ? 'مجوز سامانه فعال است' : 'سامانه غیرفعال شده است')) }}</h1>
@if(isset($helperEnabled) && !$helperEnabled)
<p>Office شما نصب است و به نصب مجدد نیاز ندارد. مدیر سرور فرمان زیر را یک‌بار اجرا و کد اتصال صادرشده در Central را وارد کند.</p>
<pre id="helperCommand" dir="ltr" style="white-space:pre-wrap;overflow-wrap:anywhere">curl --fail --proto '=https' --tlsv1.2 https://update.ponet.ir/agent/install.sh -o office-install.sh &amp;&amp; sudo bash office-install.sh</pre>
<button type="button" onclick="navigator.clipboard.writeText(document.getElementById('helperCommand').textContent).then(()=>this.textContent='فرمان کپی شد')">نصب و اتصال helper — کپی فرمان</button>
<p>دیتابیس، فایل‌ها، کاربران، دامنه و تنظیمات فعلی حفظ می‌شوند.</p>
@else
<p>{{ ($allowed ?? false) ? 'helper متصل است و مجوز روی این سرور اعتبار دارد.' : ($message ?? 'برای دریافت مجوز معتبر با واحد فروش یا نماینده فروش تماس بگیرید.') }}</p>
<p>اطلاعات و دیتابیس شما حفظ شده‌اند.</p>
@if(($updating ?? false) && auth()->check() && auth()->user()->role === 'admin')
<a href="{{ route('settings.system-update') }}">پیگیری بروزرسانی سامانه</a>
@elseif(!($allowed ?? false) && auth()->check() && auth()->user()->role === 'admin')
<form method="post" action="{{ route('office-agent.reactivate') }}">@csrf
<label for="license">لایسنس جدید</label><input id="license" name="license_key" type="password" required minlength="10" maxlength="40" autocomplete="off" dir="ltr">
@error('license_key')<p class="error">{{ $message }}</p>@enderror
<button>فعال‌سازی سامانه</button></form>
@elseif(!($allowed ?? false) && auth()->check())
<p>برای ثبت لایسنس جدید با مدیر سامانه تماس بگیرید.</p>
@elseif(!auth()->check())
<a href="/login">ورود مدیر سامانه</a>
@endif
<a href="/license">بررسی مجوز</a>
@endif
@if(auth()->check())<p><a href="/">بازگشت به Office</a></p>@endif
</main></body></html>
