# راهنمای ساده مدیر

دامنهٔ ثابت سرویس `scm.ponet.ir` است. رکورد `A` را به IP سرور Nginx Proxy Manager وصل کنید. این نام هویت دائمی سرویس است؛ هنگام تغییر سرور فقط IP رکورد DNS را عوض کنید. Agentها همیشه به `https://scm.ponet.ir` وصل می‌شوند.

اگر Nginx Proxy Manager دارید، [راهنمای فارسی Docker و NPM](docker-npm-fa.md) را دنبال کنید. Central پورت عمومی اشغال نمی‌کند؛ مقصد داخل پروکسی `http://office-central-web:80` روی شبکهٔ مشترک `proxynet` یا شبکهٔ انتخابی شما است. دامنه و SSL را در پنل پروکسی تنظیم کنید.

```bash
git clone <private-repository> office-central
cd office-central
sudo ./centralctl.sh install
sudo ./centralctl.sh status
sudo ./centralctl.sh doctor
```

برای نسخه جدید از Tag بررسی‌شده استفاده کنید: `sudo ./centralctl.sh update v1.2.0`. پیش از Update به‌طور خودکار Backup گرفته می‌شود. Backup دستی با `sudo ./centralctl.sh backup` و بازگردانی با `sudo ./centralctl.sh restore /path/to/archive.tar.gz` انجام می‌شود. Rollback کد با `sudo ./centralctl.sh rollback` است.

فایل `.env`، کلید امضای Central، رمزهای PostgreSQL/Redis و FQDN را بدون برنامه Migration تغییر ندهید. TLS باید برای همان FQDN از Let's Encrypt یا ACME صادر و خودکار تمدید شود. Agent به Certificate موقت Pin نمی‌شود؛ اعتبار وضعیت دسترسی با کلید Ed25519 مستقل بررسی می‌شود. قطع Central یا اینترنت هیچ‌وقت Office را قفل نمی‌کند و Agent آخرین وضعیت امضاشده را بدون انقضا نگه می‌دارد.
