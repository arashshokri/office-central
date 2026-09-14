# راهنمای ساده مدیر

ابتدا یک دامنه پایدار مانند `license.company.com` انتخاب و یک رکورد `A` با TTL برابر 300 به IP سرور Central وصل کنید. این نام هویت دائمی سرویس است؛ هنگام تغییر سرور فقط IP رکورد DNS را عوض کنید. Agentها همیشه به `https://license.company.com` وصل می‌شوند.

```bash
git clone <private-repository> office-central
cd office-central
sudo ./centralctl.sh install
sudo ./centralctl.sh status
sudo ./centralctl.sh doctor
```

برای نسخه جدید از Tag بررسی‌شده استفاده کنید: `sudo ./centralctl.sh update central-v1.1.0`. پیش از Update به‌طور خودکار Backup گرفته می‌شود. Backup دستی با `sudo ./centralctl.sh backup` و بازگردانی با `sudo ./centralctl.sh restore /path/to/archive.tar.gz` انجام می‌شود. Rollback کد با `sudo ./centralctl.sh rollback` است.

فایل `.env`، کلید امضای Central، رمزهای PostgreSQL/Redis و FQDN را بدون برنامه Migration تغییر ندهید. TLS باید برای همان FQDN از Let's Encrypt یا ACME صادر و خودکار تمدید شود. Agent به Certificate موقت Pin نمی‌شود؛ اعتبار Lease با کلید Ed25519 مستقل بررسی می‌شود.
