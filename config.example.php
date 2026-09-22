<?php
// این فایل رو کپی کن به config.php و مقادیر واقعی رو پر کن.
// config.php هرگز نباید جایی به‌جز خودِ سرور قرار بگیره.
return [
  // دیتابیس (از cPanel > MySQL Databases بساز)
  'DB_HOST' => 'localhost',
  'DB_NAME' => '',
  'DB_USER' => '',
  'DB_PASS' => '',

  // رمز ورود به پنل تصمیم (panel/index.html)
  'DASHBOARD_PASSWORD' => 'change-me',

  // رمز مشترک بین فایل Etsy Agent (مرورگر) و این بک‌اند — یه رشته‌ی طولانی و تصادفی بساز
  'AGENT_SECRET' => 'change-me-too',

  // برای تولید کپشن (فیلتر نیست، مستقیم قابل تماسه)
  'ANTHROPIC_API_KEY' => '',

  // برای تولید عکس پس‌زمینه (فیلتره، باید از رله عبور کنه)
  'GEMINI_API_KEY' => '',

  // اینستاگرام / فیسبوک
  'IG_BUSINESS_ACCOUNT_ID' => '',
  'IG_ACCESS_TOKEN' => '',

  // پینترست
  'PINTEREST_ACCESS_TOKEN' => '',
  'PINTEREST_BOARD_ID' => '',

  // تلگرام
  'TELEGRAM_BOT_TOKEN' => '',
  'TELEGRAM_CHAT_ID' => '',

  // رله‌ی Cloudflare Worker برای عبور از فیلترینگ ایران روی facebook/pinterest/gemini/telegram
  // اگه از قبل برای فی‌چاپ ساختی، همون RELAY_URL/RELAY_SECRET رو اینجا هم بذار —
  // فقط باید مطمئن بشی دامنه‌های بالا توی ALLOWED_HOSTS خودِ Worker هست (که هستن، چون فی‌چاپ هم دقیقاً همینا رو لازم داشت).
  'RELAY_URL' => '',
  'RELAY_SECRET' => '',

  // آدرس کامل این بک‌اند، بدون اسلش انتهایی (برای ساخت لینک عکس‌های آپلودشده)
  'SITE_URL' => 'https://agent.beguchee.ir',
];
