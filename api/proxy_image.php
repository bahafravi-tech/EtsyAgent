<?php
// api/proxy_image.php — دانلود تصویر از CDN اتسی سمت سرور، تا مرورگر بتواند آن را بدون محدودیت CORS
// در Canvas، استودیو، ساخت ALT (دیدن تصویر) و آپلود دوباره به کار ببرد.
// POST {url} → خود تصویر (بایت‌ها). فقط آدرس‌های https دامنه‌ی etsystatic.com مجازند (پروکسی باز نیست).
require __DIR__.'/../db.php';
allowCors();
requireAuthOrAgentSecret();

$in = jsonInput();
$url = trim((string)($in['url'] ?? ''));
$p = parse_url($url);
$host = strtolower((string)($p['host'] ?? ''));
if(!$p || ($p['scheme'] ?? '') !== 'https' || !preg_match('/(^|\.)etsystatic\.com$/', $host)){
  jsonResponse(['error'=>'فقط تصویرهای Etsy (etsystatic.com) مجاز است'], 400);
}
$res = directFetch($url, 'GET');
if(!$res['ok'] || !is_string($res['body']) || $res['body'] === ''){
  jsonResponse(['error'=>'دانلود تصویر ناموفق بود (کد '.($res['status'] ?? 0).')'], 502);
}
if(strlen($res['body']) > 12*1024*1024) jsonResponse(['error'=>'تصویر خیلی بزرگ است'], 413);
$info = @getimagesizefromstring($res['body']);
if(!$info) jsonResponse(['error'=>'پاسخ دریافتی تصویر نبود'], 502);
header('Content-Type: '.$info['mime']);
header('Cache-Control: private, max-age=3600');
echo $res['body'];
exit;
