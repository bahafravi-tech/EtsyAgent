<?php
// api/rename_shop.php — وقتی نام یک فروشگاه در برنامه عوض می‌شود، همه‌ی محتواهای آن فروشگاه (content_items.shop) هم به نام جدید منتقل می‌شوند
// تا با نام قبلی گم نشوند. ورودی (POST JSON): {old, new}
require __DIR__.'/../db.php';
allowCors();
requireAuthOrAgentSecret();

$in = jsonInput();
$old = trim((string)($in['old'] ?? ''));
$new = trim((string)($in['new'] ?? ''));
if($old === '' || $new === '') jsonResponse(['error'=>'old و new لازم است'], 400);
if($old === $new) jsonResponse(['ok'=>true, 'moved'=>0]);

$st = db()->prepare('UPDATE content_items SET shop=? WHERE shop=?');
$st->execute([$new, $old]);
$moved = $st->rowCount();

// نگاشت نام فروشگاه Etsy برای لینک پین‌ها هم با نام جدید بماند
$map = json_decode((string)getSetting('PIN_ETSY_SHOP_MAP', '{}'), true);
if(is_array($map) && isset($map[strtolower($old)])){
  if(!isset($map[strtolower($new)])) $map[strtolower($new)] = $map[strtolower($old)];
  unset($map[strtolower($old)]);
  setSetting('PIN_ETSY_SHOP_MAP', json_encode($map, JSON_UNESCAPED_UNICODE));
}
// فهرست واژه‌های ممنوعه هم با نام جدید بماند
$bm = json_decode((string)getSetting('BANNED_WORDS_MAP', '{}'), true);
if(is_array($bm) && array_key_exists(strtolower($old), $bm)){
  if(!array_key_exists(strtolower($new), $bm)) $bm[strtolower($new)] = $bm[strtolower($old)];
  unset($bm[strtolower($old)]);
  setSetting('BANNED_WORDS_MAP', json_encode($bm, JSON_UNESCAPED_UNICODE));
}
jsonResponse(['ok'=>true, 'moved'=>$moved]);
