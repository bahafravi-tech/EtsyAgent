<?php
// api/fetch_remote_image.php — وقتی عکس محصول از دامنه‌ای میاد که CORS اجازه‌ی پردازش توی Canvas مرورگر رو نمی‌ده،
// این مسیر سمت سرور (بدون محدودیت CORS) دانلودش می‌کنه و به لیست اسلایدها اضافه می‌کنه.
// توجه: چون سمت سرور، لوگو/متن برند روی این عکس چسبانده نمی‌شه (برخلاف آپلود دستی از مرورگر).

require __DIR__.'/../db.php';
allowCors();
requireAuthOrAgentSecret();

$input = jsonInput();
$id = intval($input['id'] ?? 0);
$url = trim($input['url'] ?? '');
if(!$id || !$url) jsonResponse(['error'=>'id و url لازم است'], 400);
if(!preg_match('#^https?://#i', $url)) jsonResponse(['error'=>'آدرس نامعتبر است'], 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM content_items WHERE id=?');
$stmt->execute([$id]);
$item = $stmt->fetch();
if(!$item) jsonResponse(['error'=>'آیتم پیدا نشد'], 404);

$res = directFetch($url, 'GET');
if(!$res['ok'] || !$res['body']) jsonResponse(['error'=>'دانلود عکس ناموفق بود'], 500);

$dir = __DIR__.'/../uploads';
if(!is_dir($dir)) mkdir($dir, 0755, true);
$filename = 'remote_'.$id.'_'.time().'.jpg';
resizeAndSaveJpeg($res['body'], $dir.'/'.$filename);
$newPath = 'uploads/'.$filename;

try{
  $pdo->beginTransaction();
  $stmt = $pdo->prepare('SELECT images_json FROM content_items WHERE id=? FOR UPDATE');
  $stmt->execute([$id]);
  $row = $stmt->fetch();
  $images = ($row['images_json'] ?? null) ? json_decode($row['images_json'], true) : [];
  if(!is_array($images)) $images = [];
  $images[] = $newPath;
  $pdo->prepare('UPDATE content_items SET hero_image_path=?, images_json=?, status=IF(status="draft","generated",status), updated_at=NOW() WHERE id=?')
      ->execute([$images[0], json_encode($images), $id]);
  $pdo->commit();
}catch(PDOException $e){
  if($pdo->inTransaction()) $pdo->rollBack();
  $pdo->prepare('UPDATE content_items SET hero_image_path=?, status=IF(status="draft","generated",status), updated_at=NOW() WHERE id=?')
      ->execute([$newPath, $id]);
}

jsonResponse(['ok'=>true, 'path'=>$newPath]);
