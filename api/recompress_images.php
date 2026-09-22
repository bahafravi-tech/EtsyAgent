<?php
// api/recompress_images.php — همه‌ی عکس‌های قبلی (که قبل از فشرده‌سازی خودکار ساخته شدن) رو
// همون‌جا سرِ فایل خودشون، کوچیک و فشرده می‌کنه — بدون تغییر آدرس/اسم فایل، پس نیازی به آپدیت دیتابیس نیست.

require __DIR__.'/../db.php';
allowCors();
requireAuthOrAgentSecret();
@set_time_limit(280);

$pdo = db();
$items = $pdo->query("SELECT id, hero_image_path, images_json FROM content_items")->fetchAll();

$processed = 0; $skipped = 0; $savedBytes = 0; $errors = 0;
$uploadsDir = realpath(__DIR__.'/../uploads');

foreach($items as $item){
  $paths = [];
  if($item['hero_image_path']) $paths[] = $item['hero_image_path'];
  if($item['images_json']){
    $arr = json_decode($item['images_json'], true);
    if(is_array($arr)) foreach($arr as $p) if($p) $paths[] = $p;
  }
  $paths = array_unique($paths);

  foreach($paths as $relPath){
    $fullPath = realpath(__DIR__.'/../'.$relPath);
    // فقط داخل uploads/ کار کن، برای امنیت
    if(!$fullPath || strpos($fullPath, $uploadsDir) !== 0 || !file_exists($fullPath)){ $skipped++; continue; }

    $before = filesize($fullPath);
    // اگه از قبل کوچیکه (زیر ۲۰۰ کیلوبایت)، دست نزن — احتمالاً قبلاً فشرده شده
    if($before < 200*1024){ $skipped++; continue; }

    $bytes = file_get_contents($fullPath);
    $ok = resizeAndSaveJpeg($bytes, $fullPath);
    if($ok){
      clearstatcache(true, $fullPath);
      $after = filesize($fullPath);
      $savedBytes += max(0, $before - $after);
      $processed++;
    }else{
      $errors++;
    }
  }
}

// تامنیل‌های کش‌شده‌ی قدیمی هم دیگه با عکس اصلی نمی‌خونن — پاکشون کن تا thumb.php دوباره از روی نسخه‌ی جدید بسازتشون
$thumbDir = __DIR__.'/../uploads/thumbs';
if(is_dir($thumbDir)){
  foreach(glob($thumbDir.'/*.jpg') as $f) @unlink($f);
}

jsonResponse(['ok'=>true, 'processed'=>$processed, 'skipped'=>$skipped, 'errors'=>$errors, 'saved_mb'=>round($savedBytes/1024/1024, 2)]);
