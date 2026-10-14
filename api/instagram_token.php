<?php
// api/instagram_token.php — ثبت/بررسی توکن اینستاگرام از داخل برنامه (بدون دست‌زدن به config.php)
// POST {token, ig_id?}: توکن را می‌آزماید و فقط اگر معتبر بود ذخیره می‌کند. GET: وضعیت فعلی.
// توکن «ورود با اینستاگرام» (شروع با IG) از graph.instagram.com کار می‌کند و خودکار هر ۲۰ روز تمدید می‌شود؛ توکن فیسبوک از graph.facebook.com.
require __DIR__.'/../db.php';
require __DIR__.'/../cron_lib.php';
allowCors();
requireAuthOrAgentSecret();

function igProbe($token, $igId){
  $base = igGraphBase($token);
  $isIgLogin = strpos($token, 'IG') === 0;
  $url = $isIgLogin
    ? $base.'/me?fields=user_id,username&access_token='.urlencode($token)
    : $base.'/'.rawurlencode($igId).'?fields=username&access_token='.urlencode($token);
  $res = relayFetch($url, 'GET');
  $d = json_decode($res['body'] ?? '', true);
  if($res['ok'] && is_array($d) && (!empty($d['username']) || !empty($d['user_id']))){
    return ['ok'=>true, 'username'=>$d['username'] ?? '', 'user_id'=>(string)($d['user_id'] ?? ($d['id'] ?? ''))];
  }
  $msg = $d['error']['message'] ?? ($res['body'] ?? 'پاسخی از اینستاگرام نیامد');
  if(stripos((string)$res['body'], 'Host not allowed') !== false) $msg = 'رله‌ی Cloudflare این دامنه را مجاز نمی‌داند — graph.instagram.com را به ALLOWED_HOSTS در Worker اضافه کن (فایل relay-worker.js).';
  return ['ok'=>false, 'message'=>$msg];
}

if(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'){
  $in = jsonInput();
  $token = trim((string)($in['token'] ?? ''));
  $igId = trim((string)($in['ig_id'] ?? ''));
  if(strlen($token) < 30) jsonResponse(['error'=>'توکن خالی یا خیلی کوتاه است'], 400);
  if($igId === '') $igId = igAccountId();
  $isIgLogin = strpos($token, 'IG') === 0;
  if(!$isIgLogin && $igId === '') jsonResponse(['error'=>'برای توکن فیسبوک، شناسه‌ی حساب اینستاگرام (IG business account ID) لازم است'], 400);
  $probe = igProbe($token, $igId);
  if(!$probe['ok']) jsonResponse(['error'=>'اینستاگرام این توکن را نپذیرفت: '.$probe['message']], 400);
  if($isIgLogin && $probe['user_id'] !== '') $igId = $probe['user_id'];
  setSetting('IG_ACCESS_TOKEN', $token);
  setSetting('IG_BUSINESS_ACCOUNT_ID', $igId);
  setSetting('IG_TOKEN_REFRESHED_AT', (string)time());
  jsonResponse(['ok'=>true, 'kind'=>$isIgLogin ? 'instagram_login' : 'facebook', 'username'=>$probe['username'], 'ig_id'=>$igId]);
}

$token = igToken();
$igId = igAccountId();
$out = ['configured'=>($token !== '' && $igId !== ''), 'kind'=>$token === '' ? '' : (strpos($token, 'IG') === 0 ? 'instagram_login' : 'facebook'), 'ig_id'=>$igId, 'username'=>'', 'valid'=>null, 'message'=>''];
$last = (int)getSetting('IG_TOKEN_REFRESHED_AT', '0');
$out['days_since_saved'] = $last ? (int)floor((time() - $last) / 86400) : null;
if($out['configured']){
  $probe = igProbe($token, $igId);
  $out['valid'] = $probe['ok'];
  $out['username'] = $probe['username'] ?? '';
  $out['message'] = $probe['ok'] ? '' : $probe['message'];
}
jsonResponse($out);
