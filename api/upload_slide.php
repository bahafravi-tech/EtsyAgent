<?php
// api/upload_slide.php — آپلود دستی عکس (بعد از پردازش توی مرورگر: مربع‌شده + لوگو + آدرس)
// اضافه می‌شه به لیست اسلایدهای همون آیتم، دقیقاً مثل عکس‌های خودکارساخته‌شده

require __DIR__.'/../db.php';
allowCors();
requireAuthOrAgentSecret();

$id = intval($_POST['id'] ?? 0);
if(!$id) jsonResponse(['error'=>'id لازم است'], 400);
if(empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK){
  jsonResponse(['error'=>'فایل دریافت نشد'], 400);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM content_items WHERE id=?');
$stmt->execute([$id]);
$item = $stmt->fetch();
if(!$item) jsonResponse(['error'=>'آیتم پیدا نشد'], 404);

$dir = __DIR__.'/../uploads';
if(!is_dir($dir)) mkdir($dir, 0755, true);
$filename = 'manual_'.$id.'_'.time().'.jpg';
if(!move_uploaded_file($_FILES['file']['tmp_name'], $dir.'/'.$filename)){
  jsonResponse(['error'=>'ذخیره‌ی فایل ناموفق بود'], 500);
}
$newPath = 'uploads/'.$filename;

// از تراکنش + قفل ردیف (FOR UPDATE) استفاده می‌کنیم تا اگه چند عکس پشت‌سرهم و خیلی نزدیک به هم
// آپلود بشن، یکی‌شون لیست قبلی رو ننویسه روی اون یکی (race condition که باعث گم‌شدن بعضی عکس‌ها می‌شد)
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
  jsonResponse(['ok'=>true, 'path'=>$newPath, 'warning'=>'ستون images_json نیست — فقط تکی ذخیره شد. ALTER TABLE content_items ADD COLUMN images_json TEXT NULL AFTER hero_image_path;']);
}

jsonResponse(['ok'=>true, 'path'=>$newPath]);
