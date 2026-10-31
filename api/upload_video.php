<?php
// api/upload_video.php — ویدیوی ساخته‌شده در مرورگر (اسلایدشو از تصویرهای آیتم) را برای انتشار در یوتیوب ذخیره می‌کند
// POST multipart: id, file (mp4 یا webm، حداکثر ۸۰ مگابایت) — یا JSON {id, remove:true} برای حذف ویدیو
require __DIR__.'/../db.php';
allowCors();
requireAuthOrAgentSecret();

$pdo = db();
// ستون video_path اگر هنوز روی دیتابیس نیست، خودکار اضافه می‌شود
try{ $pdo->query('SELECT video_path FROM content_items LIMIT 1'); }
catch(PDOException $e){
  try{ $pdo->exec('ALTER TABLE content_items ADD COLUMN video_path VARCHAR(255) NULL'); }
  catch(PDOException $e2){ jsonResponse(['error'=>'ستون video_path اضافه نشد — در phpMyAdmin اجرا کن: ALTER TABLE content_items ADD COLUMN video_path VARCHAR(255) NULL;'], 500); }
}

$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$in = $isJson ? jsonInput() : $_POST;
$id = intval($in['id'] ?? 0);
if(!$id) jsonResponse(['error'=>'id لازم است'], 400);
$st = $pdo->prepare('SELECT id, video_path FROM content_items WHERE id=?'); $st->execute([$id]); $row = $st->fetch();
if(!$row) jsonResponse(['error'=>'آیتم پیدا نشد'], 404);

$dir = __DIR__.'/../uploads/videos';
$removeOld = function() use ($row){
  $old = (string)($row['video_path'] ?? '');
  if($old !== '' && strpos($old, 'uploads/videos/') === 0){ $f = __DIR__.'/../'.str_replace(['..','\\'], '', $old); if(is_file($f)) @unlink($f); }
};

if(!empty($in['remove'])){
  $removeOld();
  $pdo->prepare('UPDATE content_items SET video_path=NULL, updated_at=NOW() WHERE id=?')->execute([$id]);
  jsonResponse(['ok'=>true]);
}

if(empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK){
  $code = $_FILES['file']['error'] ?? -1;
  jsonResponse(['error'=>'فایل ویدیو دریافت نشد'.($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE ? ' (حجم از سقف آپلود هاست بیشتر است — upload_max_filesize را در cPanel بالا ببر)' : '')], 400);
}
if($_FILES['file']['size'] > 80*1024*1024) jsonResponse(['error'=>'حجم ویدیو بیش از ۸۰ مگابایت است'], 400);
$head = file_get_contents($_FILES['file']['tmp_name'], false, null, 0, 16);
$isWebm = substr($head, 0, 4) === "\x1A\x45\xDF\xA3";
$isMp4 = substr($head, 4, 4) === 'ftyp';
if(!$isWebm && !$isMp4) jsonResponse(['error'=>'فقط ویدیوی mp4 یا webm قبول می‌شود'], 400);

if(!is_dir($dir)) mkdir($dir, 0755, true);
$name = 'c'.$id.'_'.time().'_'.bin2hex(random_bytes(3)).($isMp4 ? '.mp4' : '.webm');
if(!move_uploaded_file($_FILES['file']['tmp_name'], $dir.'/'.$name)) jsonResponse(['error'=>'ذخیره‌ی ویدیو ناموفق بود'], 500);
$removeOld();
$path = 'uploads/videos/'.$name;
$pdo->prepare('UPDATE content_items SET video_path=?, updated_at=NOW() WHERE id=?')->execute([$path, $id]);
jsonResponse(['ok'=>true, 'path'=>$path]);
