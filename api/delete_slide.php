<?php
require __DIR__.'/../db.php';
allowCors();
requireAuthOrAgentSecret();

$input = jsonInput();
$id = intval($input['id'] ?? 0);
$path = $input['path'] ?? '';
if(!$id || !$path) jsonResponse(['error'=>'id و path لازم است'], 400);

$pdo = db();
try{
  $stmt = $pdo->prepare('SELECT images_json FROM content_items WHERE id=?');
  $stmt->execute([$id]);
  $row = $stmt->fetch();
}catch(PDOException $e){
  jsonResponse(['error'=>'ستون images_json روی دیتابیس نیست — این خط رو توی phpMyAdmin اجرا کن: ALTER TABLE content_items ADD COLUMN images_json TEXT NULL AFTER hero_image_path;'], 500);
}
if(!$row) jsonResponse(['error'=>'آیتم پیدا نشد'], 404);

$images = $row['images_json'] ? json_decode($row['images_json'], true) : [];
if(!is_array($images)) $images = [];
$images = array_values(array_filter($images, fn($p) => $p !== $path));

$newHero = $images[0] ?? null;
$pdo->prepare('UPDATE content_items SET images_json=?, hero_image_path=?, updated_at=NOW() WHERE id=?')
    ->execute([json_encode($images), $newHero, $id]);

// خودِ فایل رو هم از دیسک پاک کن (اختیاری، برای تمیزی)
$fullPath = __DIR__.'/../'.$path;
if(strpos(realpath($fullPath) ?: '', realpath(__DIR__.'/../uploads')) === 0 && file_exists($fullPath)){
  @unlink($fullPath);
}

jsonResponse(['ok'=>true, 'images'=>$images]);
