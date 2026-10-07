# راه‌اندازی Office Central پشت Nginx Proxy Manager

پروکسی موجود، پورت‌های ۸۰ و ۴۴۳ سرور و گواهی SSL را مدیریت می‌کند. Central هیچ پورتی از میزبان را اشغال نمی‌کند. فقط کانتینر `web` وارد شبکهٔ مشترک می‌شود؛ دیتابیس، Redis و PHP روی شبکهٔ اختصاصی پروژه می‌مانند.

دامنهٔ ثابت این نصب **`scm.ponet.ir`** است و اسکریپت به‌صورت پیش‌فرض همین دامنه را تنظیم می‌کند. آدرس بیرونی برنامه و Agentها را روی همین دامنه نگه دارید. آدرس پنل پس از راه‌اندازی `https://scm.ponet.ir/login` است.

## ۱. پیدا کردن شبکهٔ پروکسی

روی **همان سروری که Nginx Proxy Manager اجرا می‌شود**، نام کانتینر و شبکه‌های آن را پیدا کنید:

```bash
docker ps --format 'table {{.Names}}\t{{.Image}}'
# نام واقعی کانتینر را به جای proxy-nginx بگذارید:
docker inspect proxy-nginx --format '{{range $name, $_ := .NetworkSettings.Networks}}{{println $name}}{{end}}'
```

پیش‌فرض Central شبکهٔ `proxynet` است. اگر شبکهٔ پروکسی نام دیگری دارد، همان نام را با `--proxy-network` به نصب بدهید. اگر لازم است شبکهٔ جدید ساخته شود، `--proxy-container` را هم با نام واقعی کانتینر NPM وارد کنید؛ اسکریپت شبکه را می‌سازد و پروکسی را به آن وصل می‌کند. اسکریپت NPM یا Docker را نصب نمی‌کند و هیچ شبکه یا کانتینر دیگری را حذف نمی‌کند.

برای ماندگار بودن اتصال، شبکهٔ مشترک را در فایل Compose خود NPM هم ثبت کنید. **نام سرویس موجود NPM و شبکه‌های فعلی‌اش را حفظ کنید** و `proxy` را به شبکه‌های همان سرویس اضافه کنید:

```yaml
services:
  app: # نام واقعی سرویس NPM در فایل خودتان
    networks:
      default: {}
      proxy: {}
networks:
  proxy:
    external: true
    name: proxynet
```

## ۲. نصب

نیازمندی‌ها: Linux یا WSL2 با Docker فعال، Docker Compose نسخهٔ ۲.۲۰ یا جدیدتر، Bash، Git و OpenSSL. در صورتی که کاربر شما به Docker دسترسی دارد، `sudo` لازم نیست.

```bash
git clone --branch v1.3.0 https://github.com/arashshokri/office-central.git office-central
cd office-central
sudo bash centralctl.sh install \
  --domain scm.ponet.ir \
  --email admin@example.com \
  --proxy-network proxynet
```

ایمیل نمونه را با ایمیل ادمین خود جایگزین کنید. نصب کلید برنامه، رمز دیتابیس و Redis و کلیدهای امضای Ed25519 را می‌سازد، ایمیج را بیلد می‌کند، migration را اجرا می‌کند و رمز ادمین را به‌صورت مخفی می‌پرسد. `.env` موجود هیچ‌وقت توسط نصب بازنویسی نمی‌شود. اگر نصب قطع شد، `sudo bash centralctl.sh resume` ادامه می‌دهد؛ کلیدهای امضا دوباره تولید نمی‌شوند.

اگر شبکه هنوز ساخته نشده است، به فرمان نصب این گزینه را هم اضافه کنید:

```bash
--proxy-container proxy-nginx
```

برای نصب بدون ترمینال، `--skip-admin` بدهید و بعداً در ترمینال `sudo bash centralctl.sh admin admin@example.com` را اجرا کنید.

## ۳. تنظیم پنل پروکسی

DNS دامنه را به IP سرور پروکسی وصل کنید. در پنل Nginx Proxy Manager، در بخش **Proxy Hosts → Add Proxy Host**:

| فیلد | مقدار |
| --- | --- |
| Domain Names | `scm.ponet.ir` |
| Scheme | `http` |
| Forward Hostname / IP | `office-central-web` |
| Forward Port | `80` |
| Access List | مطابق تنظیمات دسترسی شما؛ برای استفادهٔ Agent باید مسیرهای API قابل دسترس باشند |
| Cache Assets | خاموش، برای مشاهدهٔ تغییرات وب |

در تب SSL گواهی دامنه را درخواست یا انتخاب کنید و **Force SSL** را روشن کنید. اتصال داخلی NPM به Central همچنان `http` است. در تب Advanced برای آپلود بسته‌های ZIP:

```nginx
client_max_body_size 1024m;
proxy_read_timeout 300s;
```

سپس آدرس `https://scm.ponet.ir/login` را باز کنید.

اگر فعلاً مثل نمونهٔ Office فقط HTTP دارید، موقع نصب `--scheme http` بدهید. پس از فعال شدن SSL:

```bash
sudo bash centralctl.sh proxy --scheme https
sudo bash centralctl.sh start
```

## ۴. کار روی وب و مدیریت پروژه

```bash
sudo bash centralctl.sh                   # منوی مدیریت
sudo bash centralctl.sh start             # بیلد کد محلی، migration و راه‌اندازی
sudo bash centralctl.sh update            # بکاپ و دریافت آخرین main از GitHub
sudo bash centralctl.sh update TAG         # انتخاب تگی که تنظیمات جدید پروکسی را دارد
sudo bash centralctl.sh backup
sudo bash centralctl.sh rollback          # بازگشت کد به کامیت قبل؛ دیتابیس به عقب برنمی‌گردد
sudo bash centralctl.sh logs web
sudo bash centralctl.sh logs app
sudo bash centralctl.sh doctor
sudo bash centralctl.sh health
sudo bash centralctl.sh proxy             # نمایش تنظیمات دقیق پنل NPM
```

تغییرات PHP و Blade داخل ایمیج هستند؛ بعد از ویرایش `start` بزنید تا بیلد و کش‌ها تازه شوند. دیتابیس، پکیج‌ها و storage در volume می‌مانند. آپدیت و rollback قبل از تغییر کد بکاپ می‌گیرند؛ بکاپ برای سازگاری دیتابیس و فایل‌ها، سرویس‌های نویسنده را موقتاً متوقف و سپس به وضعیت قبلی برمی‌گرداند. آپدیت روی مخزن دارای تغییر محلی انجام نمی‌شود؛ تغییرات را ابتدا commit یا ذخیره کنید.

برای انتخاب شبکهٔ دیگر بدون تغییر دامنهٔ ثابت:

```bash
sudo bash centralctl.sh proxy --network proxynet
sudo bash centralctl.sh start
```

`restart` فقط سرویس‌ها را ری‌استارت می‌کند؛ برای اعمال تغییر `.env`، شبکه، دامنه یا کد از `start` استفاده کنید.

## رفع اشکال

- **502 در NPM:** هر دو کانتینر باید در یک شبکه باشند؛ مقصد `office-central-web` و پورت `80` است، نه پورت PHP `9000`. خروجی `status` و `logs web` را بررسی کنید.
- **ورود یا لینک اشتباه:** Scheme داخل NPM باید `http` باشد؛ URL برنامه باید با آدرس بیرونی تطبیق داشته باشد. بعد از تغییر دامنه یا SSL، `proxy` و `start` را اجرا کنید.
- **تداخل subnet:** قبل از نصب `.env.example` یا بعد از نصب `.env` را ویرایش کنید؛ `CENTRAL_INTERNAL_SUBNET` و `CENTRAL_WEB_IP` باید به یک subnet آزاد اشاره کنند. مقدار دوم داخل همان subnet باشد. اگر شبکهٔ اختصاصی قبلاً ساخته شده، با `docker compose down` کانتینرها و شبکهٔ پروژه را متوقف/حذف کنید و سپس `start` بزنید؛ **گزینهٔ `-v` اضافه نکنید** تا داده‌ها حفظ شوند.
- **413 هنگام آپلود:** `client_max_body_size` را در تب Advanced پنل NPM تنظیم کنید.
- **خطا بعد از migration:** لاگ‌ها را بررسی کنید. rollback کد، migration یا داده‌ها را معکوس نمی‌کند؛ بازیابی بکاپ نیازمند بررسی سازگاری نسخه و تأیید `RESTORE` است.

مرجع شبکهٔ مشترک: [راهنمای رسمی Nginx Proxy Manager](https://nginxproxymanager.com/advanced-config/#best-practice-use-a-docker-network).
