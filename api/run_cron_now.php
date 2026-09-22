<?php
// api/run_cron_now.php — اجرای فوری همون منطق cron_publish.php، برای تست بدون نیاز به صبر کردن
require __DIR__.'/../db.php';
allowCors();
requireAuthOrAgentSecret();

require __DIR__.'/../cron_lib.php';
$summary = runCronOnce();

jsonResponse(['ok'=>true] + $summary);
