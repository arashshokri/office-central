# نصب اولین مشتری با helper

ارتقای Central، خودِ بستهٔ Office را تولید نمی‌کند. ابتدا بستهٔ محافظت‌شده را روی ماشین سازنده بسازید و یک بار روی Linux آزمایشی نصب/کلون/فعال‌سازی را بررسی کنید. تا encoder دارای مجوز ionCube و بستهٔ منتشرشده آماده نباشند، نصب مشتری از مسیر helper آماده نیست.

## ۱. آماده‌سازی Central

روی سرور مرکزی موجود:

~~~bash
cd /opt/office-central &&
sudo bash centralctl.sh update v1.4.0-rc.3 &&
sudo bash centralctl.sh doctor
~~~

در NPM مرکزی، update.ponet.ir را به http://office-central-update:80 روی proxynet متصل و SSL و Force SSL را فعال کنید. scm.ponet.ir همچنان به office-central-web:80 می‌رود.

~~~bash
curl --fail https://update.ponet.ir/agent/bootstrap.json
~~~

پاسخ باید کلید عمومی و SHA-256 هر دو helper معماری amd64 و arm64 را داشته باشد.

## ۲. ساخت بسته فقط روی ماشین مالک

این مرحله روی Linux سازنده با Docker/BuildKit، Python 3، سورس Office، encoder دارای مجوز برای PHP 8.4 و Loader نوع ZTS انجام می‌شود. مسیرهای زیر نمونه‌اند و باید با مسیر واقعی جایگزین شوند. سورس را روی سرور مشتری نبرید.

~~~bash
cd /path/to/office-central
python3 scripts/integrate-office.py /path/to/Office
python3 scripts/build-office-package.py \
  --office /path/to/Office \
  --encoder /opt/ioncube/ioncube_encoder \
  --loader /opt/ioncube/ioncube_loader_lin_8.4_ts.so \
  --arch amd64 \
  --output /srv/releases/office-runtime.zip
~~~

اتصال داخل پوشهٔ Office سیستم فعلی قبلاً اعمال شده است؛ اجرای integrate-office برای checkout دیگری لازم است. اگر همین فایل‌های اتصال را عمداً جایگزین می‌کنید، از --update استفاده کنید. نسخه از Office/VERSION خوانده می‌شود و باید نسخهٔ منتشرنشدهٔ جدید باشد. این ZIP حاوی Docker imageهای آماده و manifest است؛ ZIP سورس GitHub پذیرفته نمی‌شود.

## ۳. انتشار بسته در Central

در محصولات، محصول فعال Office با slug دقیق office بسازید. سپس ZIP تولیدشده را از صفحهٔ «نسخه جدید» وارد و منتشر کنید. برای بستهٔ بزرگ، فایل ZIP را به سرور مرکزی منتقل و آنجا اجرا کنید:

~~~bash
cd /opt/office-central &&
docker cp /srv/releases/office-runtime.zip office-central-app-1:/tmp/office-runtime.zip &&
docker compose --project-name office-central exec -T --user root app \
  sh -c 'chown www-data:www-data /tmp/office-runtime.zip && chmod 600 /tmp/office-runtime.zip' &&
docker compose --project-name office-central exec -T app \
  php artisan office:import-runtime /tmp/office-runtime.zip --product=office --publish
~~~

فقط پس از موفقیت import، کپی موقت /tmp/office-runtime.zip داخل کانتینر را حذف کنید. نسخهٔ منتشرشده قابل جایگزینی نیست؛ اگر همان شماره قبلاً از GitHub وارد شده، نسخهٔ جدیدی برای بستهٔ محافظت‌شده بسازید.

## ۴. مشتری، دامنه و لایسنس

۱. مشتری فعال بسازید.
۲. «لایسنس جدید» را باز کنید؛ نوع را «نصب خودکار یک‌بار مصرف» بگذارید.
۳. محصول Office و نسخه‌ای با برچسب «بستهٔ محافظت‌شدهٔ نصب» انتخاب کنید.
۴. آدرس HTTPS مشتری و ایمیل مدیر اولیه را وارد کنید.
۵. اگر NPM مشتری داخل Docker است، نام شبکهٔ مشترک موجود آن را در مجوز وارد کنید. NPM باید عضو همین شبکه باشد؛ مقصد آن http://office-web:8080 است. DNS دامنهٔ مشتری به سرور مشتری اشاره کند و SSL و Force SSL فعال باشند.
۶. کد را بسازید و همان لحظه در محل امن نگه دارید؛ نمایش کامل کد فقط یک‌بار است.

بدون شبکهٔ پروکسی، Office روی 127.0.0.1:8080 میزبان قرار می‌گیرد؛ باید reverse proxy با HTTPS روی همان میزبان آماده باشد. helper، Office و پیش‌نیاز Docker را نصب می‌کند؛ DNS یا NPM مشتری را خودکار ایجاد نمی‌کند.

## ۵. نصب روی سرور مشتری

پس از تأیید مرحلهٔ آزمایشی، روی Ubuntu/Debian مشتری با systemd، UUID معتبر و فضای کافی اجرا کنید:

~~~bash
sudo apt-get update &&
sudo apt-get install -y curl python3 ca-certificates &&
curl --fail --proto '=https' --tlsv1.2 \
  https://update.ponet.ir/agent/install.sh -o office-install.sh &&
sudo bash office-install.sh
~~~

helper کد نصب را به‌صورت مخفی می‌پرسد، بستهٔ همان لایسنس را می‌گیرد و پس از سلامت نصب کد را مصرف می‌کند. اطلاعات ورود مدیر تازه در /opt/office/initial-admin.json با دسترسی root ذخیره می‌شوند. صفحهٔ لایسنس Central همین دستور را با دکمهٔ کپی نشان می‌دهد.

شکست نصب روی همان دستگاه با office-agent resume یا اجرای دوبارهٔ launcher قابل ادامه است. انتقال سخت‌افزار به مجوز جدید همان مشتری/محصول نیاز دارد؛ فعال‌سازی از /license داخل Office انجام می‌شود و دیتابیس حفظ می‌شود. برای افزودن helper به نصب دارای داده، راهنمای [adopt و بکاپ](office-agent-fa.md) را اجرا کنید.
