<?php
// api/pinterest_mode.php — انتخاب روش انتشار پینترست: 'api' (توکن + Standard access) یا 'rss' (فید خودکار، بدون API)
// و ثبت نام فروشگاه Etsy برای هر فروشگاه تا لینک پین‌ها به فروشگاه درست برود. با رمز Agent یا ورود پنل.
require __DIR__.'/../db.php';
allowCors();
requireAuthOrAgentSecret();

if(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'){
  $in = jsonInput();
  $mode = (($in['mode'] ?? '') === 'rss') ? 'rss' : 'api';
  setSetting('PINTEREST_MODE', $mode);
  $shopName = trim((string)($in['shop_name'] ?? ''));
  $etsyShop = trim((string)($in['etsy_shop'] ?? ''));
  if($shopName !== '' && $etsyShop !== ''){
    $map = json_decode((string)getSetting('PIN_ETSY_SHOP_MAP', '{}'), true);
    if(!is_array($map)) $map = [];
    $map[strtolower($shopName)] = $etsyShop;
    setSetting('PIN_ETSY_SHOP_MAP', json_encode($map, JSON_UNESCAPED_UNICODE));
  }
  jsonResponse(['ok'=>true, 'mode'=>$mode]);
}

$siteUrl = rtrim(cfg()['SITE_URL'] ?? '', '/');
$shop = trim($_GET['shop'] ?? '');
$sql = "SELECT COUNT(*) FROM content_platforms p JOIN content_items i ON i.id=p.content_id WHERE p.platform='pinterest' AND p.status='published' AND p.external_id='rss-feed'";
$params = [];
if($shop !== ''){ $sql .= ' AND i.shop=?'; $params[] = $shop; }
$st = db()->prepare($sql);
$st->execute($params);
jsonResponse([
  'mode' => getSetting('PINTEREST_MODE', cfg()['PINTEREST_MODE'] ?? 'api'),
  'feed_url' => $siteUrl.'/api/pinterest_feed.php'.($shop !== '' ? '?shop='.rawurlencode($shop) : ''),
  'site_url' => $siteUrl,
  'items_in_feed' => (int)$st->fetchColumn(),
]);
