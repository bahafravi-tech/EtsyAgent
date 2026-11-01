<?php
// api/publish_now.php — انتشار فوری یک آیتم «محتوای معرفی» در پلتفرم‌های انتخاب‌شده، بدون صبر برای Cron و زمان‌بندی
// POST {id, platforms?:[...]}: پلتفرم‌هایی که قبلاً منتشر شده‌اند دوباره منتشر نمی‌شوند (پست تکراری ساخته نمی‌شود)
require __DIR__.'/../db.php';
allowCors();
requireAuthOrAgentSecret();
require __DIR__.'/../cron_lib.php';

$in = jsonInput();
$id = intval($in['id'] ?? 0);
if(!$id) jsonResponse(['error'=>'id لازم است'], 400);
$pdo = db();
$st = $pdo->prepare('SELECT id, status FROM content_items WHERE id=?'); $st->execute([$id]); $item = $st->fetch();
if(!$item) jsonResponse(['error'=>'آیتم پیدا نشد'], 404);

$allowed = ['telegram','instagram','pinterest','facebook','youtube'];
$cur = $pdo->prepare('SELECT platform, status FROM content_platforms WHERE content_id=?'); $cur->execute([$id]);
$existing = []; foreach($cur->fetchAll() as $r) $existing[$r['platform']] = $r['status'];
$want = isset($in['platforms']) && is_array($in['platforms']) ? array_values(array_intersect($in['platforms'], $allowed)) : array_keys($existing);
if(!$want) jsonResponse(['error'=>'هیچ پلتفرمی انتخاب نشده'], 400);

$toRun = [];
foreach($want as $p){
  if(($existing[$p] ?? '') === 'published') continue;
  $pdo->prepare('INSERT INTO content_platforms (content_id, platform, status) VALUES (?,?,"pending") ON DUPLICATE KEY UPDATE status="pending", error_text=NULL')->execute([$id, $p]);
  $toRun[] = $p;
}
if(!$toRun) jsonResponse(['error'=>'این آیتم در همه‌ی پلتفرم‌های انتخاب‌شده قبلاً منتشر شده است'], 409);

$pdo->prepare("UPDATE content_items SET status='approved', scheduled_at=NULL, updated_at=NOW() WHERE id=?")->execute([$id]);
$summary = runCronOnce($id);
$summary['skipped_already_published'] = array_values(array_diff($want, $toRun));
jsonResponse(['ok'=>true] + $summary);
