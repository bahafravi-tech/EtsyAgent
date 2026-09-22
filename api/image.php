<?php
// api/image.php — عکس رو مستقیم با PHP تحویل می‌ده (نه استاتیک از پوشه‌ی uploads)
// چون سرور/WAF داشت به بعضی درخواست‌ها با 206 Partial Content جواب می‌داد (که باعث رد شدن عکس توسط اینستاگرام می‌شد)،
// این مسیر همیشه فایل کامل رو با 200 برمی‌گردونه، فارغ از اینکه درخواست‌کننده Range بخواد یا نه.

$path = $_GET['path'] ?? '';
$path = str_replace(['..','\\'], '', $path);
if(strpos($path, 'uploads/') !== 0){ http_response_code(400); exit('bad path'); }

$fullPath = __DIR__.'/../'.$path;
if(!file_exists($fullPath)){ http_response_code(404); exit('not found'); }

// عمداً هیچ Range/If-Range/If-Modified-Since ای رو در نظر نمی‌گیریم — همیشه کل فایل با 200
header('Content-Type: image/jpeg');
header('Content-Length: '.filesize($fullPath));
header('Accept-Ranges: none');
header('Cache-Control: public, max-age=604800');
readfile($fullPath);
