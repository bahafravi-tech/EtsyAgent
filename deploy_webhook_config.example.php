<?php
// این فایل رو کپی کن به اسم deploy_webhook_config.php (کنار همین فایل، روی سرور)
// و مقادیر واقعی رو پر کن. این فایل هرگز نباید وارد گیت بشه (ریپو Publicه).

// از cPanel → Security → Manage API Tokens بساز (اسمش مهم نیست، مثلاً github-deploy)
define('CPANEL_USERNAME', 'rxgqxbxs');
define('CPANEL_API_TOKEN', 'توکنی که از cPanel گرفتی');
define('CPANEL_HOST', 'https://etsyagent.afravi.com:2083');
define('CPANEL_REPO_ROOT', '/home/rxgqxbxs/repositories/etsyagent.afravi.com');

// یه رشته‌ی تصادفی دلخواه بساز (مثلاً با همون روش RELAY_SECRET) — این دقیقاً همون
// چیزیه که توی تنظیمات Webhook گیت‌هاب هم به‌عنوان Secret وارد می‌کنی
define('GITHUB_WEBHOOK_SECRET', 'یه رشته‌ی تصادفی طولانی بساز');
