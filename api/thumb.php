<?php
// api/thumb.php — نسخه‌ی کوچیک و کم‌کیفیت‌تر عکس‌ها، فقط برای نمایش سریع توی گرید محتوای معرفی
// (بار اول واقعاً می‌سازدش و کش می‌کنه؛ دفعات بعد مستقیم از کش می‌ده)
// نسخه‌ی مقاوم‌تر: هر جای پردازش شکست بخوره، به‌جای هیچی، عکس اصلی رو کامل سرو می‌کنه — هیچ‌وقت نباید broken image ببینی

error_reporting(0);

function serveOriginal($fullPath){
  header('Content-Type: image/jpeg');
  header('Cache-Control: public, max-age=604800');
  readfile($fullPath);
  exit;
}

$path = $_GET['path'] ?? '';
$path = str_replace(['..','\\'], '', $path);
if(strpos($path, 'uploads/') !== 0){ http_response_code(400); exit('bad path'); }

$fullPath = __DIR__.'/../'.$path;
if(!file_exists($fullPath)){ http_response_code(404); exit('not found'); }

if(!function_exists('imagecreatetruecolor')) serveOriginal($fullPath);

$thumbDir = __DIR__.'/../uploads/thumbs';
if(!is_dir($thumbDir)){
  if(!@mkdir($thumbDir, 0755, true) && !is_dir($thumbDir)) serveOriginal($fullPath);
}
$thumbPath = $thumbDir.'/'.md5($path).'.jpg';

if(!file_exists($thumbPath) || filemtime($thumbPath) < filemtime($fullPath)){
  $info = @getimagesize($fullPath);
  $src = null;
  if($info){
    switch($info[2]){
      case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($fullPath); break;
      case IMAGETYPE_PNG:  $src = @imagecreatefrompng($fullPath); break;
      case IMAGETYPE_WEBP: if(function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($fullPath); break;
    }
  }
  if(!$src) serveOriginal($fullPath);

  $w = @imagesx($src); $h = @imagesy($src);
  if(!$w || !$h) serveOriginal($fullPath);

  $TARGET = 220;
  $scale = $TARGET / max($w, $h);
  $nw = max(1, (int)round($w*$scale));
  $nh = max(1, (int)round($h*$scale));
  $dst = @imagecreatetruecolor($nw, $nh);
  if(!$dst) serveOriginal($fullPath);
  @imagecopyresampled($dst, $src, 0,0,0,0, $nw,$nh, $w,$h);
  $ok = @imagejpeg($dst, $thumbPath, 55);
  @imagedestroy($src);
  @imagedestroy($dst);
  if(!$ok || !file_exists($thumbPath)) serveOriginal($fullPath);
}

header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=604800');
readfile($thumbPath);
