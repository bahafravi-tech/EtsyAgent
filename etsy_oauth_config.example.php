<?php
// این فایل رو کپی کن به اسم etsy_oauth_config.php (کنار همین فایل، روی سرور)
// و مقادیر واقعی رله‌ی Cloudflare Worker خودت رو بذار.
// این فایل هرگز نباید وارد گیت بشه — چون ریپو Publicه.

define('RELAY_URL', 'https://xxx.workers.dev');
define('RELAY_SECRET', 'همون رشته‌ای که توی relay-worker.js به‌عنوان RELAY_SECRET گذاشتی');
