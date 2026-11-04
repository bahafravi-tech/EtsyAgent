<?php
// api/move_content.php — انتقال محتواهای انتخاب‌شده به فروشگاه دیگر
// POST {ids:[...], shop:"نام فروشگاه مقصد"}  → فقط همین آیتم‌ها جابه‌جا می‌شوند (نه همه‌ی محتوای فروشگاه)
// انتشارهای انجام‌شده سر جایشان می‌مانند؛ پلتفرم‌های در انتظار، بعد از این با حساب‌های فروشگاه مقصد منتشر می‌شوند.
require __DIR__.'/../db.php';
allowCors();
requireAuthOrAgentSecret();

$in = jsonInput();
$ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['ids'] ?? [])))));
$shop = trim((string)($in['shop'] ?? ''));
if(!$ids) jsonResponse(['error'=>'هیچ آیتمی انتخاب نشده'], 400);
if($shop === '') jsonResponse(['error'=>'فروشگاه مقصد مشخص نیست'], 400);
if(mb_strlen($shop) > 190) jsonResponse(['error'=>'نام فروشگاه خیلی طولانی است'], 400);

$ph = implode(',', array_fill(0, count($ids), '?'));
$st = db()->prepare("UPDATE content_items SET shop=? WHERE id IN ($ph)");
$st->execute(array_merge([$shop], $ids));
jsonResponse(['ok'=>true, 'moved'=>$st->rowCount(), 'shop'=>$shop]);
