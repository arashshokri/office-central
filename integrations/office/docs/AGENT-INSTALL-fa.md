# نصب Office با agent خود محصول

Office اکنون agent کامپایل‌شده، helper ساخت بسته و اتصال مجوز را در همین مخزن دارد.
برای ساخت این ابزارها نیازی به checkout پروژه Central نیست.

- `agent/*.go`: نصب، ادامه نصب، دریافت نسخه، سرویس systemd، اثبات زنده UUID و فعال‌سازی مجدد.
- `office-install.sh`: ورودی نصب مشتری؛ فقط باینری معتبر را از update.ponet.ir می‌گیرد.
- `scripts/office-helper.py`: ابزار مالک برای ورودی پوشه، ZIP سورس یا مخزن GitHub.
- `app/Services/OfficeLicense.php`: بررسی امضای مجوز و سخت‌افزار داخل Office.
- `app/Providers/OfficeLicenseServiceProvider.php`: صفحه قفل و فعال‌سازی مجدد بدون حذف داده.

## ۱. ساخت باینری‌های agent

روی Linux سازنده با Go:

~~~bash
bash scripts/build-office-agent.sh
~~~

باینری‌های amd64 و arm64 و checksum در `dist/office-agent` ساخته می‌شوند.
Central همین کد پروتکل را برای سروکردن agent مشتری می‌سازد؛ کلید امضا در Central است و داخل سورس یا باینری نیست.

## ۲. ورودی نسخهٔ محصول و ساخت بسته مشتری

ساخت روی Linux مورد اعتماد مالک با Docker/BuildKit، Python 3، encoder دارای مجوز ionCube برای PHP 8.4
و Loader نوع ZTS همان معماری انجام می‌شود. ابزار مسیر سورس مالک را تغییر نمی‌دهد؛ snapshot موقت می‌سازد.
کد ورودی هنگام Docker build اجرا می‌شود؛ فقط سورس مورد اعتماد خودتان را بدهید.

پوشهٔ محلی:

~~~bash
python3 scripts/office-helper.py build \
  --source /srv/src/office \
  --encoder /opt/ioncube/ioncube_encoder \
  --loader /opt/ioncube/ioncube_loader_lin_8.4_ts.so \
  --output /srv/releases/office-runtime-3.8.19-amd64.zip
~~~

برای ZIP سورس، مقدار `--source /srv/uploads/office-source.zip` بدهید.
برای GitHub، `--source https://github.com/arashshokri/office --ref v3.8.19` بدهید؛
ref باید تگ یا commit مشخص باشد. برای مخزن خصوصی از credential helper خود Git استفاده کنید؛
توکن را داخل URL یا آرگومان قرار ندهید. نسخهٔ نهایی از `VERSION` سورس خوانده می‌شود.
helper اتصال مجوز و agent این نسخه را در snapshot اعمال می‌کند و بستهٔ محافظت‌شده می‌سازد.
مشتری ZIP سورس یا دسترسی GitHub شما را دریافت نمی‌کند.

## ۳. رساندن نسخه به Central

دو راه دارید:

1. ZIP خروجی محافظت‌شده را در «نسخه‌ها»ی scm.ponet.ir برای محصول با slug دقیق `office` بارگذاری و منتشر کنید.
2. ZIP را با نام دقیق `office-runtime-VERSION-amd64.zip` به GitHub Release همان تگ محصول پیوست کنید.
   در Central مخزن را برای محصول Office متصل و «همگام‌سازی» را بزنید. Central فایل نصب پیوست‌شده را
   می‌گیرد، manifest، نسخه، SHA-256 و نقش هر image را بررسی می‌کند؛ Source code.zip را نصب نمی‌کند.
   برای arm64 مقدار CENTRAL_RUNTIME_ARCHITECTURE=arm64 در Central و نام asset متناظر لازم است.

آپلود ZIP سورس به صفحهٔ نسخه‌ها جایگزین مرحلهٔ ساخت نیست. ساخت Docker/encoder را داخل PHP وب یا روی مشتری اجرا نکنید.

## ۴. مشتری و کد نصب

در Central مشتری فعال و لایسنس «نصب خودکار یک‌بار مصرف» بسازید؛ نسخه منتشرشده،
آدرس HTTPS مشتری، ایمیل مدیر اولیه و نام واقعی شبکه مشترک NPM مشتری را تعیین کنید.
دامنه update.ponet.ir در NPM مرکزی به `http://office-central-update:80` متصل باشد.

روی Ubuntu/Debian مشتری با UUID یکتا، systemd و اینترنت:

~~~bash
sudo apt-get update && sudo apt-get install -y curl python3 ca-certificates
curl --fail --proto '=https' --tlsv1.2 https://update.ponet.ir/agent/install.sh -o office-install.sh &&
sudo bash office-install.sh
~~~

کد را فقط هنگام درخواست helper وارد کنید. نصب ناموفق کد را مصرف نمی‌کند؛ همان دستگاه قابل resume است.
کد بعد از سلامت برنامه، دیتابیس، Redis، storage و migration مصرف می‌شود و مجوز اجرای نصب باقی می‌ماند.
NPM مشتری در همان شبکه به `http://office-web:8080` وصل شود و SSL و Force SSL فعال باشند.
اطلاعات اولیه مدیر در `/opt/office/initial-admin.json` فقط برای root ذخیره می‌شود.

~~~bash
sudo office-agent status
sudo office-agent resume
sudo office-agent update
~~~

## ۵. انتقال و فعال‌سازی مجدد

کپی روی سخت‌افزار جدید دسترسی آن کپی را قفل می‌کند. نصب اولیه دست‌نخورده می‌ماند.
مدیر مشتری در `/license` کد جدید همان مشتری/محصول را وارد می‌کند یا root فرمان زیر را اجرا می‌کند:

~~~bash
sudo office-agent reactivate
~~~

هیچ volume یا دیتابیس در این مسیر حذف نمی‌شود. انتقال نصب قبلی با credentials اصلی و `--adopt-env` انجام می‌شود؛
هرگز برای رفع قفل از `down -v`، `migrate:fresh` یا حذف پوشه اطلاعات استفاده نکنید.

## وضعیت اعتبارسنجی

آزمون‌های پروتکل، UUID، عدم مصرف کد در شکست و بررسی بسته خودکار هستند.
تا encoder و Docker در دسترس نباشد، ساخت بستهٔ واقعی و نصب کامل مشتری تأیید نشده است.
پیش از تحویل، نصب، قطع شبکه، resume، کلون سخت‌افزار و فعال‌سازی مجدد را روی VM آزمایشی با داده نمونه اجرا کنید.
