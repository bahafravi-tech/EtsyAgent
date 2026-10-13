<?php
// go.php — لینک مقصد پین‌های فید RSS پینترست. Pinterest می‌خواهد لینک هر پین زیر دامنه‌ی تأییدشده (claim) باشد؛
// این صفحه فقط کاربر را به فروشگاه Etsy می‌فرستد (با جست‌وجوی عنوان محصول داخل فروشگاه).
require __DIR__.'/db.php';

$id = (int)($_GET['id'] ?? 0);
$target = 'https://www.etsy.com';
if($id > 0){
  $st = db()->prepare('SELECT shop, title FROM content_items WHERE id=?');
  $st->execute([$id]);
  $it = $st->fetch();
  if($it){
    $map = json_decode((string)getSetting('PIN_ETSY_SHOP_MAP', '{}'), true);
    if(!is_array($map)) $map = [];
    $key = strtolower(trim((string)$it['shop']));
    $etsyShop = $map[$key] ?? preg_replace('/\s+/', '', (string)$it['shop']);
    if($etsyShop !== ''){
      $words = preg_split('/[\s|,\-–—]+/u', (string)$it['title'], -1, PREG_SPLIT_NO_EMPTY);
      $q = implode(' ', array_slice($words ?: [], 0, 6));
      $target = 'https://www.etsy.com/shop/'.rawurlencode($etsyShop).($q !== '' ? '?search_query='.rawurlencode($q) : '');
    }
  }
}
header('Location: '.$target, true, 302);
exit;
