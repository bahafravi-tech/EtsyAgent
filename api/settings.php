<?php
require __DIR__.'/../db.php';
requireAuth();

// کلیدهای حساس (API key/توکن) عمداً اینجا نیستن — فقط توی config.php روی سرور می‌مونن.
// این فقط برای تنظیماتی‌یه که ممکنه بخوای بدون دست‌زدن به config.php عوض کنی.
$editableKeys = ['RELAY_URL', 'RELAY_SECRET'];

if($_SERVER['REQUEST_METHOD'] === 'POST'){
  $input = jsonInput();
  foreach($editableKeys as $k){
    if(isset($input[$k])) setSetting($k, $input[$k]);
  }
  jsonResponse(['ok'=>true]);
}

$out = [];
foreach($editableKeys as $k) $out[$k] = getSetting($k, '');
jsonResponse($out);
