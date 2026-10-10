# اصلاح راه‌اندازی وب پس از بروزرسانی

نسخهٔ Central `v1.4.0-rc.21` و Office `v3.8.30` دسترسی فایل‌های بستهٔ سورس
را در سرویس هلپر با `UMask=0077` اصلاح می‌کنند. پوشهٔ موقت، کلید دستگاه و
تنظیمات خصوصی همچنان خصوصی می‌مانند. وب‌سرور با کاربر `www-data` اجرا می‌شود.
بررسی فایل Caddy و تنظیمات وب‌سرور پیش از توقف دسترسی و تغییر دیتابیس انجام می‌شود.

## بازیابی خطای Caddyfile: permission denied در نسخهٔ ۳.۸.۲۹

اگر عملیات در مرحلهٔ `services` مانده و وب‌سرور مرتب راه‌اندازی مجدد می‌شود،
ابتدا دسترسی فقط دو فایل تنظیمات همان کانتینر را اصلاح کنید:

```bash
(
  set -e
  office_fix_dir=$(mktemp -d)
  sudo docker cp office-web:/etc/caddy/Caddyfile "$office_fix_dir/Caddyfile"
  sudo docker cp office-web:/usr/local/etc/php/conf.d/zz-leave-panel.ini "$office_fix_dir/zz-leave-panel.ini"
  sudo chmod 0644 "$office_fix_dir/Caddyfile" "$office_fix_dir/zz-leave-panel.ini"
  sudo docker cp "$office_fix_dir/Caddyfile" office-web:/etc/caddy/Caddyfile
  sudo docker cp "$office_fix_dir/zz-leave-panel.ini" office-web:/usr/local/etc/php/conf.d/zz-leave-panel.ini
  sudo docker restart office-web
)
sudo docker logs --tail 40 office-web
sudo docker inspect --format 'status={{.State.Status}} health={{if .State.Health}}{{.State.Health.Status}}{{end}}' office-web
```

این اقدام تنظیمات داخل کانتینر را قابل خواندن می‌کند. اصلاح دائمی پس از دریافت
هلپر جدید و بازسازی بسته انجام می‌شود. منتظر `health=healthy` بمانید؛ اگر خطای
دیگری وجود دارد، خروجی را بررسی کنید. وضعیت maintenance را دستی پاک نکنید.

سپس Central را بروزرسانی کنید (فایل دانلودی `office-install.sh` را در خارج
از مخزن نگه دارید؛ `git status --short` باید تمیز باشد):

```bash
cd /opt/office-central
sudo bash centralctl.sh update v1.4.0-rc.21
```

هلپر را از دامنهٔ بروزرسانی دریافت و تازه کنید. از پوشهٔ موقت استفاده می‌شود
تا فایل دانلودی مانع بروزرسانی بعدی مخزن نشود:

```bash
(
  set -e
  office_download_dir=$(mktemp -d)
  curl --fail --proto '=https' --tlsv1.2 https://update.ponet.ir/agent/install.sh -o "$office_download_dir/office-install.sh"
  sudo bash "$office_download_dir/office-install.sh"
)
sudo office-agent update --expected-version 3.8.29
sudo office-agent update-status
```

مجوز نسخهٔ `3.8.29` باید روی همین لایسنس باقی بماند. هلپر جدید همان ZIP امضاشده
را با دسترسی صحیح بازسازی می‌کند. نصب دیتابیس جدید یا کلون پروژه لازم نیست.
۱۰۰٪ تنها پس از سلامت نسخه و ثبت نتیجه در Central اعلام می‌شود. اگر نسخهٔ
`3.8.30` را دریافت و منتشر کرده و روی لایسنس مجاز کرده‌اید، در فرمان آخر همان
شمارهٔ `3.8.30` را بنویسید.
