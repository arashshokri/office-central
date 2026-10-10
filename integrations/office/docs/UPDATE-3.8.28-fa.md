# رفع خطای `/root/.docker: read-only file system`

این خطا از مسیر تنظیمات Docker/Buildx در سرویس helper بود. سرویس با `ProtectHome=true` پوشهٔ `/root` را محافظت می‌کند، ولی Buildx برای ذخیرهٔ وضعیت ساخت به مسیر پیش‌فرض `~/.docker` نیاز داشت. محافظت سرویس حفظ شده و تنظیمات به `<helper-root>/agent/private/docker` و زیرپوشهٔ `buildx` منتقل شده‌اند. مسیرهای نصب متصل و نصب تازه به‌ترتیب معمولاً `/var/lib/office-helper` و `/opt/office` هستند.

helper نسخهٔ `1.4.0-rc.19` داخل Central نسخهٔ `v1.4.0-rc.19` و Office نسخهٔ `v3.8.28` منتشر شده است. تنظیم مسیر در خود فرمان ساخت هم اعمال می‌شود؛ به تنظیمات shell یا قابل‌نوشتن‌کردن `/root` وابسته نیست. مسیر خصوصی با دسترسی `0700` ایجاد و پیش از ساخت بررسی می‌شود. فایل `config.json` یا احراز هویت موجود در این مسیر بازنویسی نمی‌شود و وارد build context نمی‌شود.

ابتدا روی سرور Central:

```bash
cd /opt/office-central
sudo bash centralctl.sh update v1.4.0-rc.19
```

پس از پایان یا شکست عملیات قبلی، روی سرور Office:

```bash
curl --fail --proto '=https' --tlsv1.2 https://update.ponet.ir/agent/install.sh -o office-install.sh
sudo bash office-install.sh
```

هویت نصب، لایسنس ثبت‌شده، دیتابیس و فایل‌های مشتری حفظ می‌شوند. کد نصب مصرف‌شده را دوباره وارد نکنید. پس از بروزرسانی helper، در پنل Office بروزرسانی متوقف‌شده را دوباره تأیید کنید. نسخهٔ مجاز فعلی، از جمله `3.8.25` یا `3.8.27`، با helper جدید قابل ساخت است؛ برای رفع این خطا اجباری به تغییر نسخهٔ مجاز مشتری نیست. برای دریافت خود Office نسخهٔ `3.8.28` آن را در Central دریافت، منتشر و برای لایسنس مشتری مجاز کنید.

تنظیمات سرویس را می‌توان بدون نمایش لایسنس بررسی کرد:

```bash
sudo systemctl show office-agent -p Environment -p ProtectHome -p ProtectSystem
```

باید مسیرهای `DOCKER_CONFIG` و `BUILDX_CONFIG` داخل `agent/private/docker` باشند و حفاظت‌ها فعال بمانند. برای ساخت از رجیستری خصوصی، مدیر سرور می‌تواند در همین مسیر خصوصی با `docker --config <helper-root>/agent/private/docker login` احراز هویت کند. تنظیمات عمومی `/root/.docker` به‌صورت خودکار کپی نمی‌شوند.

آزمون CI این خطا را با Docker واقعی در سرویس systemd با همان حفاظت‌ها بازتولید می‌کند، سپس ساخت یک ایمیج `scratch` را با مسیر خصوصی اجرا و بارگذاری آن در همان Docker Engine را بررسی می‌کند. این آزمون برای ساخت به مخزن یا دانلود وابستگی نیاز ندارد.

مراجع رسمی: [مسیر تنظیمات Docker](https://docs.docker.com/reference/cli/docker/#change-the-docker-directory)، [تنظیمات Buildx](https://docs.docker.com/build/building/variables/#buildx_config).
