<?php
require __DIR__.'/../db.php';
allowCors();
requireAuthOrAgentSecret();

$input = jsonInput();
$ids = $input['ids'] ?? (isset($input['id']) ? [$input['id']] : []);
$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
if(!$ids) jsonResponse(['error'=>'id یا ids لازم است'], 400);

$pdo = db();
$placeholders = implode(',', array_fill(0, count($ids), '?'));

// عکس‌های آپلودشده‌ی هر آیتم رو هم از دیسک پاک کن
$stmt = $pdo->prepare("SELECT hero_image_path, images_json FROM content_items WHERE id IN ($placeholders)");
$stmt->execute($ids);
foreach($stmt->fetchAll() as $row){
  $paths = [];
  if(!empty($row['hero_image_path'])) $paths[] = $row['hero_image_path'];
  if(!empty($row['images_json'])){
    $imgs = json_decode($row['images_json'], true);
    if(is_array($imgs)) $paths = array_merge($paths, $imgs);
  }
  foreach(array_unique($paths) as $p){
    $fullPath = __DIR__.'/../'.$p;
    if(strpos(realpath($fullPath) ?: '', realpath(__DIR__.'/../uploads')) === 0 && file_exists($fullPath)){
      @unlink($fullPath);
    }
  }
}

$pdo->prepare("DELETE FROM content_platforms WHERE content_id IN ($placeholders)")->execute($ids);
$pdo->prepare("DELETE FROM content_items WHERE id IN ($placeholders)")->execute($ids);

jsonResponse(['ok'=>true, 'deleted'=>count($ids)]);
