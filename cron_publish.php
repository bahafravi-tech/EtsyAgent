<?php
// cron_publish.php — این فایل رو با Cron Job هر ۱۵ دقیقه اجرا کن:
// php /home/USERNAME/etsyagent.afravi.com/cron_publish.php
// (یا از cPanel > Cron Jobs با همین دستور — نه لینک/URL)

require __DIR__.'/cron_lib.php';
runCronOnce();
