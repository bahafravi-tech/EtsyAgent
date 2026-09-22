# Beguchee Content Agent — راهنمای نصب

بک‌اندی که وقتی یه پیشنهاد محصول رو توی فایل Etsy Agent تأیید می‌کنی، خودکار براش کپشن +
عکس معرفی می‌سازه، و بعد از تأیید تو توی پنل، به‌صورت خودکار (با Cron) توی تلگرام/اینستاگرام/
پینترست منتشرش می‌کنه. دقیقاً همون معماری فی‌چاپ: PHP+MySQL روی هاست اشتراکی، بدون Node.js.

## ۱. آپلود فایل‌ها

همه‌ی این پوشه رو (به‌جز `config.example.php`) روی یه ساب‌دامین (مثلاً `agent.beguchee.ir`)
آپلود کن، دقیقاً همون ساختاری که هست:

```
agent.beguchee.ir/
├── config.php          ← خودت می‌سازی، پایین توضیح داده شده
├── db.php
├── lib_generate.php
├── cron_publish.php
├── schema.sql
├── .htaccess
├── uploads/
│   └── .htaccess
├── panel/
│   └── index.html
└── api/
    └── *.php
```

## ۲. دیتابیس

توی cPanel > MySQL Databases یه دیتابیس و یوزر بساز (و کامل بهش دسترسی بده).
بعد از phpMyAdmin وارد اون دیتابیس شو و `schema.sql` رو Import کن.

## ۳. config.php

فایل `config.example.php` رو کپی کن به اسم `config.php` (کنار همون فایل، توی روت پروژه)
و همه‌ی مقادیر رو پر کن:

- **DB_HOST/DB_NAME/DB_USER/DB_PASS** — از مرحله‌ی ۲
- **DASHBOARD_PASSWORD** — رمزی که برای ورود به `panel/index.html` استفاده می‌کنی
- **AGENT_SECRET** — یه رشته‌ی تصادفی طولانی بساز (مثلاً با `openssl rand -hex 32`)؛
  همین رشته رو باید توی تنظیمات فایل Etsy Agent هم (فیلد «Agent Secret») بذاری —
  این دو باید دقیقاً یکی باشن.
- **ANTHROPIC_API_KEY** — کلید Claude API
- **GEMINI_API_KEY** — کلید Gemini API (برای عکس پس‌زمینه)
- **IG_BUSINESS_ACCOUNT_ID / IG_ACCESS_TOKEN** — از یه System User توی Meta Business Manager بساز؛
  همون System User باید صریحاً Full Access روی خودِ Page (نه فقط اپ) داشته باشه
- **PINTEREST_ACCESS_TOKEN / PINTEREST_BOARD_ID**
- **TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID**
- **RELAY_URL / RELAY_SECRET** — پایین توضیح داده شده
- **SITE_URL** — آدرس کامل همین ساب‌دامین، بدون اسلش آخر

## ۴. رله‌ی فیلترینگ (Cloudflare Worker)

هاست ایرانیه، و این دامنه‌ها فیلترن: `graph.facebook.com` (اینستاگرام)،
`api.pinterest.com`، `generativelanguage.googleapis.com` (Gemini)، و گاهی `api.telegram.org`.

**اگه برای فی‌چاپ از قبل یه Worker با این ساختار داری، لازم نیست جدید بسازی** — چون
دقیقاً همین ۴ دامنه رو فی‌چاپ هم لازم داشت. فقط همون `RELAY_URL` و `RELAY_SECRET` رو
اینجا هم توی `config.php` بذار.

اگه نداری: کد `relay-worker.js` رو توی [workers.cloudflare.com](https://workers.cloudflare.com)
به‌عنوان یه Worker جدید (رایگان) دیپلوی کن، مقدار `RELAY_SECRET` توی خودِ فایل رو با یه
رشته‌ی تصادفی عوض کن، و همون آدرس Worker (چیزی مثل `https://xxx.workers.dev`) رو به‌عنوان
`RELAY_URL` بذار.

(کلید Claude لازم نیست از رله رد بشه — `api.anthropic.com` فیلتر نیست.)

## ۵. Cron Job

توی cPanel > Cron Jobs، یه Cron هر ۱۵ دقیقه اضافه کن:

```
*/15 * * * * php /home/USERNAME/agent.beguchee.ir/cron_publish.php
```

(مسیر واقعی رو از خودِ cPanel یا با `pwd` توی File Manager پیدا کن.)

## ۶. وصل کردن به فایل Etsy Agent

توی فایل Etsy Agent (همون HTML)، برو تنظیمات > بک‌اند سوشال مدیا، و پر کن:
- آدرس بک‌اند: `https://agent.beguchee.ir`
- Agent Secret: همون رشته‌ای که توی `config.php` گذاشتی

از این به بعد، هر بار یه پیشنهاد محصول رو تأیید کنی، خودکار به این بک‌اند فرستاده می‌شه.

## ۷. استفاده‌ی روزمره

1. توی فایل Etsy Agent، یه پیشنهاد محصول رو تأیید کن
2. برو `agent.beguchee.ir/panel/` و با DASHBOARD_PASSWORD وارد شو
3. آیتم توی تب «⏳ آماده‌ی بررسی» ظاهر می‌شه — کپشن/عکس رو ببین، اگه لازم بود دوباره بسازشون یا دستی ویرایش کن
4. بزن «✅ تأیید و صف انتشار» — از همون‌جا به بعد، Cron هر ۱۵ دقیقه امتحان می‌کنه توی
   تلگرام/اینستاگرام/پینترست منتشرش کنه
5. اگه یه پلتفرم خاص شکست خورد، پیام خطاش زیر کارت نشون داده می‌شه؛ با «🔁 تلاش مجدد» دوباره
   امتحانش کن — نیازی نیست بقیه‌ی پلتفرم‌ها رو هم دوباره بفرستی

## نکات امنیتی

- `config.php` هرگز نباید جای دیگه‌ای (گیت، مستندات، پیام) قرار بگیره — رمزهای واقعی توشه
- `.htaccess` توی روت پروژه دسترسی مستقیم به `config.php`/`db.php`/`lib_generate.php`/
  `cron_publish.php`/`schema.sql` رو مسدود می‌کنه
- `uploads/.htaccess` اجرای PHP توی اون پوشه رو غیرفعال می‌کنه (فقط عکس استاتیکه)
- پنل با کوکی نشست ۳۰ روزه کار می‌کنه؛ اگه بعد از مدتی خودکار خارجت کرد، یعنی
  `session.gc_maxlifetime` سمت هاست کمتر از انتظاره — با پشتیبانی هاست هماهنگ کن

## محدودیت‌های شناخته‌شده (V1)

- عکس معرفی فقط پس‌زمینه‌ست، بدون متن روش (چون مدل‌های تصویرساز فارسی/انگلیسی رو
  کیفیت پایین می‌نویسن) — اگه بعداً خواستی برندینگ/عنوان روی عکس بچسبونی، باید یه مرحله‌ی
  «finalize» شبیه فی‌چاپ (چسباندن متن توی مرورگر با Canvas) اضافه بشه
- فیسبوک و یوتیوب توی این نسخه پیاده نشدن (چون فی‌چاپ هم هنوز کامل نداره) — فقط
  تلگرام/اینستاگرام/پینترست
- اینستاگرام فقط یه عکس تکی می‌فرسته (نه کروسل) — چون فعلاً فقط یه hero image تولید می‌شه
