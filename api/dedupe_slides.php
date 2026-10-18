<?php
// api/dedupe_slides.php — حذف تصویرهای تکراری یک آیتم (یک مسیر چندبار در فهرست، یا دو فایل با محتوای یکسان)
// POST {id} → {ok, images, removed}. اولین نمونه‌ی هر تصویر می‌ماند و ترتیب بقیه عوض نمی‌شود.
require __DIR__.'/../db.php';
allowCors();
requireAuthOrAgentSecret();

$input = jsonInput();
$id = intval($input['id'] ?? 0);
if(!$id) jsonResponse(['error'=>'id لازم است'], 400);

$pdo = db();
try{
  $stmt = $pdo->prepare('SELECT images_json, hero_image_path FROM content_items WHERE id=?');
  $stmt->execute([$id]);
  $row = $stmt->fetch();
}catch(PDOException $e){
  jsonResponse(['error'=>'ستون images_json روی دیتابیس نیست — ALTER TABLE content_items ADD COLUMN images_json TEXT NULL AFTER hero_image_path;'], 500);
}
if(!$row) jsonResponse(['error'=>'آیتم پیدا نشد'], 404);

$images = ($row['images_json'] ?? null) ? json_decode($row['images_json'], true) : [];
if(!is_array($images) || !$images) $images = $row['hero_image_path'] ? [$row['hero_image_path']] : [];

$seenPath = []; $seenHash = []; $keep = []; $dropFiles = [];
foreach($images as $p){
  if(!is_string($p) || $p === '') continue;
  if(isset($seenPath[$p])) continue;           // همان مسیر دوباره
  $seenPath[$p] = true;
  $full = __DIR__.'/../'.$p;
  $h = is_file($full) ? md5_file($full) : null;
  if($h !== null && isset($seenHash[$h])){ $dropFiles[] = $p; continue; }   // فایل دیگر با محتوای یکسان
  if($h !== null) $seenHash[$h] = true;
  $keep[] = $p;
}
$removed = count($images) - count($keep);
if($removed > 0){
  $pdo->prepare('UPDATE content_items SET images_json=?, hero_image_path=?, updated_at=NOW() WHERE id=?')
      ->execute([json_encode($keep), $keep[0] ?? null, $id]);
  // فایل‌های تکراریِ واقعی (نه مسیرِ مشترک با عکس نگه‌داشته‌شده) از دیسک پاک می‌شوند
  $uploads = realpath(__DIR__.'/../uploads');
  foreach($dropFiles as $p){
    $full = __DIR__.'/../'.$p;
    if($uploads && is_file($full) && strpos(realpath($full) ?: '', $uploads) === 0 && !in_array($p, $keep, true)) @unlink($full);
  }
}
jsonResponse(['ok'=>true, 'images'=>$keep, 'removed'=>$removed]);
