# رفع خطای ساخت Office و بروزرسانی helper

Central نسخهٔ `v1.4.0-rc.18` و Office نسخهٔ `v3.8.27` این اصلاحات را دارند. helper جدید می‌تواند نسخهٔ مجاز فعلی مشتری، از جمله `3.8.25`، را هم بسازد؛ نیازی به تغییر فایل یا checksum نسخه‌های منتشرشده نیست.

در سرور Central:

```bash
cd /opt/office-central
sudo bash centralctl.sh update v1.4.0-rc.18
```

در سرور Office، پس از پایان یا شکست عملیات قبلی، پیش‌نیاز ساخت را بررسی کنید:

```bash
docker buildx version
```

اگر Docker از مخزن رسمی Docker روی Ubuntu نصب شده است و افزونه وجود ندارد:

```bash
sudo apt-get update
sudo apt-get install -y docker-buildx-plugin
docker buildx version
```

اگر بسته پیدا نشد، خروجی `docker version` و `apt-cache policy docker-ce docker.io docker-buildx-plugin docker-buildx` را برای مدیر سرور بفرستید تا افزونهٔ سازگار با روش نصب فعلی انتخاب شود. حذف و نصب مجدد Docker لازم نیست. راهنمای رسمی: https://docs.docker.com/engine/install/ubuntu/

سپس helper را بروزرسانی کنید:

```bash
curl --fail --proto '=https' --tlsv1.2 https://update.ponet.ir/agent/install.sh -o office-install.sh
sudo bash office-install.sh
```

هویت، لایسنس ثبت‌شده، دیتابیس، فایل‌ها و تنظیمات پروکسی نصب موجود حفظ می‌شوند. کد نصب مصرف‌شده را دوباره وارد نکنید. در Office «چک کردن بروزرسانی» را بزنید، نسخهٔ مجاز را تأیید و عملیات متوقف‌شده را دوباره شروع کنید. برای نصب خود Office نسخهٔ `3.8.27`، ابتدا آن را در Central دریافت، منتشر و برای لایسنس مشتری مجاز کنید.

ساخت سورس با BuildKit و هدف `managed` انجام می‌شود؛ مراحل مستقل مربوط به بستهٔ کدگذاری‌شده وارد این ساخت نمی‌شوند. مرحلهٔ جاری به‌صورت زنده نمایش داده می‌شود و درصد پس از تکمیل مراحل ساخت افزایش می‌یابد. درصد تخمین زمان نیست؛ رسیدن به ۱۰۰٪ نیازمند نصب، سلامت سرویس و ثبت موفق نتیجه است.

حداکثر زمان ساخت ۴۵ دقیقه و حداکثر زمان بدون خروجی جدید ۵ دقیقه است. خطاهای `BUILD_FAILED`، `BUILD_TIMEOUT` و `BUILD_IDLE_TIMEOUT` انتهای خروجی و مسیر لاگ خصوصی را دارند. نبودن افزونه با `BUILDX_REQUIRED` پیش از شروع ساخت اعلام می‌شود. فایل لاگ در `<helper-root>/agent/private/build-<checksum-prefix>.log` با دسترسی فقط مدیر سرور ذخیره می‌شود. حجم آن محدود است و خروجی انتهایی حتی برای لاگ بزرگ حفظ می‌شود.

ساخت پیش از بکاپ، maintenance و migration انجام می‌شود. شکست در این مرحله دیتابیس یا سرویس‌های مشتری را تغییر نمی‌دهد. هشدار «legacy builder is deprecated» در نسخه‌های قبلی به‌تنهایی علت شکست نیست؛ علت اصلی باید از انتهای لاگ ساخت خوانده شود. لاگ قدیمی ممکن است فقط ابتدای خروجی را نشان دهد؛ این نقص در helper جدید رفع شده است.
