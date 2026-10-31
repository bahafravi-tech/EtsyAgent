<?php
// api/facebook_token.php — ثبت/بررسی توکن صفحه‌ی فیسبوک از داخل برنامه
// POST {token, page_id?}: اگر توکن «صفحه» باشد همان ذخیره می‌شود؛ اگر توکن «کاربر» باشد، توکن صفحه از /me/accounts گرفته می‌شود.
// GET: وضعیت فعلی (نام صفحه و معتبر بودن توکن)
require __DIR__.'/../db.php';
require __DIR__.'/../cron_lib.php';
allowCors();
requireAuthOrAgentSecret();

function fbGet($path, $token){
  $sep = strpos($path, '?') === false ? '?' : '&';
  $res = relayFetch(FB_GRAPH.$path.$sep.'access_token='.urlencode($token), 'GET');
  $d = json_decode($res['body'] ?? '', true);
  if(!is_array($d)) $d = [];
  if(stripos((string)($res['body'] ?? ''), 'Host not allowed') !== false) $d['error']['message'] = 'رله‌ی Cloudflare دامنه‌ی graph.facebook.com را مجاز نمی‌داند.';
  return $d;
}

if(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'){
  $in = jsonInput();
  if(!empty($in['disconnect'])){ setSetting('FB_PAGE_TOKEN', ''); setSetting('FB_PAGE_ID', ''); setSetting('FB_PAGE_NAME', ''); jsonResponse(['ok'=>true]); }
  $token = trim((string)($in['token'] ?? ''));
  $want = trim((string)($in['page_id'] ?? ''));
  if(strlen($token) < 30) jsonResponse(['error'=>'توکن خالی یا خیلی کوتاه است'], 400);

  // ۱) توکن کاربر است؟ فهرست صفحه‌ها را بگیر
  $acc = fbGet('/me/accounts?fields=id,name,access_token,tasks&limit=100', $token);
  $pages = $acc['data'] ?? [];
  $pageId = ''; $pageToken = ''; $pageName = '';
  if($pages){
    $pick = null;
    foreach($pages as $pg){ if($want !== '' && (string)$pg['id'] === $want){ $pick = $pg; break; } }
    if(!$pick && count($pages) === 1) $pick = $pages[0];
    if(!$pick) jsonResponse(['error'=>'این حساب چند صفحه دارد — شناسه‌ی صفحه (Page ID) را هم وارد کن', 'pages'=>array_map(fn($p)=>['id'=>$p['id'], 'name'=>$p['name']], $pages)], 400);
    $pageId = (string)$pick['id']; $pageToken = (string)$pick['access_token']; $pageName = (string)$pick['name'];
  }else{
    // ۲) شاید خودش توکن صفحه باشد
    $me = fbGet('/me?fields=id,name', $token);
    if(empty($me['id'])) jsonResponse(['error'=>'فیسبوک این توکن را نپذیرفت: '.($me['error']['message'] ?? ($acc['error']['message'] ?? 'نامشخص'))], 400);
    if($want !== '' && (string)$me['id'] !== $want) jsonResponse(['error'=>'این توکن مال صفحه‌ی «'.$me['name'].'» ('.$me['id'].') است، نه صفحه‌ای که وارد کردی'], 400);
    $pageId = (string)$me['id']; $pageToken = $token; $pageName = (string)($me['name'] ?? '');
  }
  // آزمایش: توکن صفحه باید بتواند صفحه را بخواند
  $chk = fbGet('/'.rawurlencode($pageId).'?fields=id,name', $pageToken);
  if(empty($chk['id'])) jsonResponse(['error'=>'توکن صفحه کار نکرد: '.($chk['error']['message'] ?? 'نامشخص')], 400);
  setSetting('FB_PAGE_ID', $pageId); setSetting('FB_PAGE_TOKEN', $pageToken); setSetting('FB_PAGE_NAME', $pageName);
  jsonResponse(['ok'=>true, 'page_id'=>$pageId, 'page_name'=>$pageName]);
}

$out = ['configured'=>fbPageId() !== '' && fbPageToken() !== '', 'page_id'=>fbPageId(), 'page_name'=>(string)getSetting('FB_PAGE_NAME', ''), 'valid'=>null, 'message'=>''];
if($out['configured']){
  $chk = fbGet('/'.rawurlencode(fbPageId()).'?fields=id,name', fbPageToken());
  $out['valid'] = !empty($chk['id']);
  if($out['valid']) $out['page_name'] = (string)($chk['name'] ?? $out['page_name']);
  else $out['message'] = $chk['error']['message'] ?? 'نامشخص';
}
jsonResponse($out);
