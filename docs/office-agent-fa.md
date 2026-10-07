# نصب و مجوز Office با helper

پنل مدیریتی scm.ponet.ir است؛ مشتری و helper فقط update.ponet.ir را استفاده می‌کنند. این نسخه helper کامپایل‌شدهٔ Linux، API نسخهٔ ۲ و اتصال داخل Office را اضافه می‌کند.

## وضعیت انتشار و پیش‌نیاز

کد و تست‌های مجوز اجرا شده‌اند؛ اجرای واقعی Docker، ساخت ionCube و نصب مشتری باید روی Linux آزمایشی بررسی شوند. این محیط به Docker سرور شما یا encoder تجاری دسترسی ندارد. نسخهٔ RC برای همین مرحله است. تا بستهٔ محافظت‌شده آماده نباشد، مجوز نصب خودکار قابل استفاده نیست. ZIP سورس GitHub برای نصب مشتری پذیرفته نمی‌شود.

برای محافظت PHP، encoder دارای مجوز ionCube برای PHP 8.4 و loader متناظر لازم است. خرید encoder خودکار انجام نمی‌شود. Docker به‌تنهایی سورس را پنهان نمی‌کند. UUID و machine-id جابه‌جایی متعارف را تشخیص می‌دهند؛ root یا کنترل hypervisor امکان دستکاری شناسه/اجرای نرم‌افزار را می‌دهد. تضمین ضدکپی مطلق نیاز به اعتماد قوی‌تری مثل TPM attestation دارد.

## ۱. Central و Nginx Proxy Manager

در checkout موجود Central:

~~~bash
cd /opt/office-central
sudo bash centralctl.sh update v1.4.0-rc.1
sudo bash centralctl.sh agent-build
sudo bash centralctl.sh doctor
~~~

update ابتدا بکاپ می‌گیرد و نسخه را build/migrate می‌کند. agent-build را یک‌بار پس از ارتقا از v1.3.0 اجرا کنید؛ خود فرمان start نسخهٔ جدید نیز helperهای amd64 و arm64 را با Go داخل Docker می‌سازد. داده‌ها و کلید امضا حفظ می‌شوند. اولین build نیاز به اینترنت دارد.

در NPM دو Proxy Host روی شبکهٔ مشترک proxynet:

| دامنه | Scheme | Forward hostname | Port |
|---|---|---|---|
| scm.ponet.ir | http | office-central-web | 80 |
| update.ponet.ir | http | office-central-update | 80 |

برای هر دو SSL و Force SSL فعال کنید. update-web فقط API نصب، لینک دانلود و helper را سرو می‌کند؛ login و مدیریت در این ورودی ۴۰۴ هستند. دامنهٔ دوم جایگزین احراز هویت نیست؛ API بسته‌ها به مجوز، کلید دستگاه و امضای درخواست نیاز دارد.

متغیرهای جدید پیش‌فرض:
- CENTRAL_AGENT_URL=https://update.ponet.ir
- CENTRAL_UPDATE_IP=172.29.87.21

اگر subnet داخلی را تغییر داده‌اید، IP دوم را هم داخل همان subnet و بدون تداخل تنظیم کنید.

~~~bash
curl --fail https://update.ponet.ir/agent/bootstrap.json
curl -I https://update.ponet.ir/login
~~~

کلید امضای Central را برای دستگاه نصب‌شده عوض نکنید. helper در نخستین ارتباط معتبر HTTPS آن را pin می‌کند. دریافت پاسخ شبکه اجازهٔ تعویض کلید را نمی‌دهد.

## ۲. اتصال داخل Office

سرویس امضا/اثبات زندهٔ سخت‌افزار، middleware قفل پنل/API، صفحهٔ فارسی /license با فرم مدیر و CSRF، توقف job/schedule و کنترل websocket/RDP در Caddy اضافه شده‌اند. هدف Docker جداگانهٔ customer لایهٔ سورس production را به ارث نمی‌برد.

برای checkout دیگری:

~~~bash
python3 /path/to/office-central/scripts/integrate-office.py /path/to/office
~~~

فایل‌های اتصال از قبل متفاوت باشند، ابزار متوقف می‌شود؛ --update فقط برای جایگزینی آگاهانهٔ همین فایل‌های اتصال است. تغییرات دیگر Office reset/commit نمی‌شوند.

نصب قدیمی مالک با OFFICE_LICENSE_ENABLED=false ادامه می‌یابد. بستهٔ customer marker اجباری دارد و خاموش‌کردن متغیر محیطی، مجوز آن را غیرفعال نمی‌کند.

## ۳. ساخت بستهٔ محافظت‌شده فقط روی ماشین سازنده

Linux سازنده به Docker/BuildKit، Python 3، encoder دارای مجوز و loader PHP 8.4 نیاز دارد. سورس Office فقط روی ماشین سازنده است.

~~~bash
python3 scripts/build-office-package.py \
  --office /path/to/office \
  --encoder /opt/ioncube/ioncube_encoder \
  --loader /opt/ioncube/ioncube_loader_lin_8.4_ts.so \
  --arch amd64 \
  --output /srv/releases/office-runtime.zip
~~~

مسیر encoder نمونه است؛ binary واقعی باید هدف -84 را پشتیبانی کند. FrankenPHP از PHP نوع ZTS استفاده می‌کند؛ Loader باید نسخهٔ Thread-Safe با نام ioncube_loader_lin_8.4_ts.so و با معماری، PHP 8.4 و Debian/glibc ایمیج سازگار باشد. نسخه از Office/VERSION خوانده می‌شود. برای arm64 باید معماری سازنده، loader و همهٔ dependency imageها متناظر باشند.

سازنده assets و Blade را آماده، PHP اختصاصی و Blade کامپایل‌شده را کدگذاری و سورس Blade را با placeholder جایگزین می‌کند. customer از php-base شروع می‌شود؛ لایهٔ خام production در خروجی مشتری نیست. vendor متن‌باز و assets عمومی وب کدگذاری نمی‌شوند.

ZIP فقط manifest.json و پنج Docker archive دارد: app، MariaDB، Redis، Guacamole و guacd. نسخه، معماری، image ID و SHA-256 ثبت و پیش از نصب بررسی می‌شوند. هیچ اسکریپت دلخواهی از ZIP اجرا نمی‌شود.

بسته را در Releases پنل وارد و منتشر کنید؛ نسخهٔ فرم و manifest یکسان باشند. برای بستهٔ بزرگ‌تر از سقف آپلود وب:

~~~bash
docker cp /srv/releases/office-runtime.zip office-central-app-1:/tmp/office-runtime.zip
docker compose --project-name office-central exec -T app \
  php artisan office:import-runtime /tmp/office-runtime.zip --product=office --publish
docker compose --project-name office-central exec -T app rm /tmp/office-runtime.zip
~~~

محصول فعال با slug دقیق office باید وجود داشته باشد. نسخهٔ منتشرشده تغییرپذیر نیست؛ برای اصلاح، نسخهٔ جدید بسازید.

## ۴. صدور کد نصب

در Central مشتری و محصول فعال، نوع «نصب خودکار یک‌بار مصرف»، Release محافظت‌شده، آدرس HTTPS مشتری و ایمیل مدیر اولیه را انتخاب کنید. IP bind و port نیز مشخص می‌شوند.

اگر NPM روی Docker مشتری است، نام شبکهٔ مشترک واقعی را وارد کنید؛ شبکه از قبل وجود داشته باشد و NPM هم عضو آن باشد. NPM مشتری به office-web:8080 در آن شبکه وصل می‌شود. بدون شبکهٔ پروکسی، وب پیش‌فرض روی 127.0.0.1:8080 host است.

کد کامل فقط یک‌بار نشان داده می‌شود. begin کد را رزرو می‌کند؛ تأیید سلامت برنامه، DB، Redis، storage و migrationها آن را مصرف می‌کند. شکست دانلود/نصب/شبکه قابل resume روی همان دستگاه است. کد رزرو شده روی دستگاه دیگری کار نمی‌کند؛ مدیر کد جدید می‌دهد. مصرف کد، مجوز دائمی اجرای نصب را revoke نمی‌کند.

## ۵. نصب مشتری

Ubuntu/Debian، systemd، اینترنت HTTPS و DMI UUID معتبر لازم است. VPS جدید باید UUID یکتا داشته باشد؛ UUID و machine-id یکسانِ کپی‌شده قابل تشخیص مطمئن نیستند. حدود ۲۰ GiB فضای آزاد برای آرشیو، ایمیج، DB و بکاپ توصیه می‌شود.

~~~bash
sudo apt-get update
sudo apt-get install -y curl python3 ca-certificates
curl --fail --proto '=https' --tlsv1.2 \
  https://update.ponet.ir/agent/install.sh -o office-install.sh
sudo bash office-install.sh
~~~

launcher helper را دریافت و SHA-256 را بررسی می‌کند. روی Ubuntu/Debian تازه، Docker از مخزن رسمی امضاشده نصب می‌شود. Docker موجود حذف یا جایگزین نمی‌شود؛ اگر تنها Compose plugin کم باشد، plugin سازگار با نصب فعلی را نصب کنید.

کد به‌صورت مخفی پرسیده می‌شود؛ در آرگومان خط فرمان نگذارید. helper بستهٔ تعیین‌شده را دانلود، ایمیج‌های آماده را load، secrets اولیه را ایجاد، migration و admin را اجرا و پس از سلامت نصب، مصرف کد را تأیید می‌کند.

اطلاعات مدیر اولیه در /opt/office/initial-admin.json با دسترسی root ذخیره و هنگام نصب در ترمینال چاپ می‌شود. حساب مدیر موجود overwrite نمی‌شود.

~~~bash
sudo office-agent status
sudo office-agent update
sudo office-agent resume
sudo office-agent reactivate
sudo journalctl -u office-agent -n 100 --no-pager
~~~

اگر نصب پیش از ذخیرهٔ binary سیستمی متوقف شد، launcher را دوباره اجرا کنید. مسیر پیش‌فرض /opt/office است؛ --root باید ثابت بماند. هم‌زمان office-deploy.sh را روی نصب managed اجرا نکنید.

## ۶. انتقال و حفظ داده‌ها

برای افزودن helper به نصب قدیمی:

~~~bash
sudo bash office-install.sh --adopt-env /path/to/original/.env.docker
~~~

APP_KEY، credentials و نام DB و تنظیمات قبلی حفظ می‌شوند. حجم‌های leave-panel_db_data و leave-panel_app_storage ثابت هستند. وجود داده بدون env معتبر باعث توقف می‌شود، نه جایگزینی credentials.

قبل از migration روی دادهٔ موجود، writerها موقتاً متوقف، dump DB و snapshot storage و checksum تهیه می‌شود. کانتینر DB باید قابل بازیابی باشد. شکست snapshot writerهای قبلی را برمی‌گرداند؛ شکست migration سرویس کسب‌وکار را تا resume/بازیابی بررسی‌شده متوقف نگه می‌دارد. بکاپ در /opt/office/backups است. هیچ down -v، migrate:fresh یا حذف volume انجام نمی‌شود.

برای انتقال managed، DB/storage، env و پوشهٔ /opt/office/agent را حفظ و helper را در مقصد راه‌اندازی کنید. اثبات زندهٔ UUID/machine-id جدید حتی بدون اینترنت با مجوز قبلی ناسازگار است؛ پنل قفل می‌شود. پس از اتصال، Central رویداد clone را ثبت می‌کند؛ نصب اصلی فعال می‌ماند.

اگر systemd unit در مقصد نیست، launcher را دوباره اجرا کنید تا daemon نصب شود. نصب کاملِ قفل‌شده، بدون نصب مجدد یا حذف اطلاعات به فعال‌سازی هدایت می‌شود. مدیر در /license کد جدید همان مشتری/محصول را وارد می‌کند؛ بعد از سلامت محلی و تأیید مرکز دسترسی باز می‌شود. کد جایگزین یک‌بار مصرف است. نسخهٔ هدف متفاوت باشد، سپس office-agent update اجرا کنید.

قطع اینترنت، وضعیت مجوز را عوض نمی‌کند؛ آخرین وضعیت امضاشدهٔ همان دستگاه حفظ می‌شود. خرابی helper محلی، امضای نامعتبر یا نبود اثبات سخت‌افزار دسترسی را محدود می‌کند. بازکردن قفل نیازمند پاسخ امضاشدهٔ مرکز است.

## ۷. آزمون روی سرور آزمایشی

۱. Central آزمایشی با هر دو ورودی NPM و HTTPS.
۲. ساخت واقعی customer با encoder/loader؛ بررسی Laravel و Blade.
۳. نصب VM A؛ شکست عمدی download/health نباید کد را مصرف کند.
۴. retry همان نصب را ادامه دهد؛ مصرف‌شده روی نصب مستقل رد شود.
۵. clone به VM B با UUID متفاوت؛ پنل/API/websocket/RDP قفل و A فعال بماند.
۶. کد جایگزین B را باز کند؛ داده‌ها و فایل‌های مشتری یکسان بمانند.
۷. قطع اینترنت A قفل نکند؛ state دستکاری‌شده و clone آفلاین رد شوند.
۸. تغییر نسخه، بکاپ، شکست migration و resume بررسی شوند.

پیشنهاد تکمیلی: TPM attestation و کلید غیرقابل‌استخراج، انتقال مجاز با تأیید فروش، نمایش اعلان clone به مدیر و برنامهٔ چرخش کنترل‌شدهٔ کلید امضا.

منابع: [نصب رسمی Docker](https://docs.docker.com/engine/install/ubuntu/)، [ionCube Encoder](https://www.ioncube.com/php_encoder.php)، [Loader](https://www.ioncube.com/loaders.php).
