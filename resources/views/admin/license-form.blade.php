@extends('layouts.app') @section('content')<div class="page-title"><h1>{{ __('ui.new_license') }}</h1></div><form class="panel form" method="post" action="{{ route('licenses.store') }}">@csrf
<label>نوع مجوز<select name="activation_mode"><option value="installer_once">نصب خودکار یک‌بار مصرف</option><option value="legacy">قدیمی</option></select></label>
<label>آدرس HTTPS پنل مشتری<input type="url" name="deployment[app_url]" placeholder="https://office.customer.ir"></label>
<label>ایمیل مدیر اولیه<input type="email" name="deployment[admin_email]"></label>
<label>نام مدیر<input name="deployment[admin_name]" value="مدیر سامانه"></label>
<label>IP اتصال داخلی<input name="deployment[bind_ip]" value="127.0.0.1"></label>
<label>پورت وب<input name="deployment[port]" type="number" value="8080"></label>
<label>شبکه مشترک پروکسی (اختیاری)<input name="deployment[proxy_network]" placeholder="proxynet"></label>
<p>کد نصب پس از تأیید سلامت نصب مصرف می‌شود. نسخه باید بستهٔ محافظت‌شدهٔ Office باشد.</p>
<label>Customer<select name="customer_id" required>@foreach($customers as $x)<option value="{{ $x->id }}">{{ $x->name }}</option>@endforeach</select></label><label>Product<select name="product_id" required>@foreach($products as $x)<option value="{{ $x->id }}">{{ $x->name }}</option>@endforeach</select></label><label>Release<select name="release_id"><option value="">—</option>@foreach($releases as $x)<option value="{{ $x->id }}">{{ $x->version }} / {{ $x->channel->value }}</option>@endforeach</select></label><label>Max installations<input name="max_installations" type="number" min="1" value="1" required></label><label>Expires at<input name="expires_at" type="date"></label><button class="primary">{{ __('ui.generate') }}</button></form>@endsection
