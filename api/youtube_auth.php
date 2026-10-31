<?php
// api/youtube_auth.php — اتصال کانال یوتیوب با OAuth گوگل
//  GET  (با رمز برنامه)                 → وضعیت اتصال، نام کانال، آدرس بازگشت (Redirect URI)
//  POST {client_id, client_secret}       → ذخیره‌ی مشخصات اپ گوگل
//  POST {action:"begin"}                 → آدرس صفحه‌ی اجازه‌ی گوگل (برنامه آن را در تب تازه باز می‌کند)
//  POST {privacy:"public|unlisted|private"} → نوع انتشار ویدیوها
//  POST {disconnect:true}                → قطع اتصال
//  GET  ?code=…&state=…                  → بازگشت از گوگل (بدون رمز؛ با state یک‌بارمصرف تأیید می‌شود)
require __DIR__.'/../db.php';
require __DIR__.'/../cron_lib.php';

function ytRedirectUri(){ return rtrim(cfg()['SITE_URL'] ?? '', '/').'/api/youtube_auth.php'; }
function ytPage($ok, $msg){
  header('Content-Type: text/html; charset=utf-8');
  $c = $ok ? '#16a34a' : '#dc2626';
  echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>YouTube</title>'
     . '<body style="font-family:Tahoma,sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">'
     . '<div style="background:#fff;border-radius:16px;padding:28px 32px;max-width:480px;box-shadow:0 8px 30px rgba(0,0,0,.08);line-height:2;text-align:center">'
     . '<div style="font-size:44px">'.($ok ? '✅' : '❌').'</div><div style="color:'.$c.';font-weight:700;font-size:17px">'.htmlspecialchars($msg).'</div>'
     . '<div style="color:#64748b;font-size:13px;margin-top:8px">این تب را ببند و در برنامه «بررسی وضعیت» را بزن.</div></div></body></html>';
  exit;
}
function ytChannel($token){
  $res = relayFetch('https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true', 'GET', ['Authorization'=>'Bearer '.$token]);
  $d = json_decode($res['body'] ?? '', true);
  if(!empty($d['items'][0]['id'])) return ['ok'=>true, 'id'=>$d['items'][0]['id'], 'title'=>$d['items'][0]['snippet']['title'] ?? ''];
  $msg = $d['error']['message'] ?? ($res['body'] ?? 'پاسخی نیامد');
  if(isset($d['items']) && !$d['items']) $msg = 'این حساب گوگل کانال یوتیوب ندارد — اول در یوتیوب یک کانال بساز';
  if(stripos((string)($res['body'] ?? ''), 'Host not allowed') !== false) $msg = 'رله‌ی Cloudflare دامنه‌های گوگل را مجاز نمی‌داند — کد جدید relay-worker.js را در Worker بگذار.';
  return ['ok'=>false, 'message'=>$msg];
}

// ── بازگشت از گوگل ──
if(isset($_GET['code']) || isset($_GET['error'])){
  $state = (string)($_GET['state'] ?? '');
  $saved = (string)getSetting('YT_OAUTH_STATE', '');
  $exp = (int)getSetting('YT_OAUTH_STATE_EXP', '0');
  if($saved === '' || !hash_equals($saved, $state) || $exp < time()) ytPage(false, 'لینک اتصال منقضی یا نامعتبر است — از داخل برنامه دوباره «اتصال به یوتیوب» را بزن.');
  setSetting('YT_OAUTH_STATE', '');
  if(isset($_GET['error'])) ytPage(false, 'اجازه داده نشد: '.$_GET['error']);
  $res = relayFetch('https://oauth2.googleapis.com/token', 'POST', ['Content-Type'=>'application/x-www-form-urlencoded'], http_build_query([
    'code'=>(string)$_GET['code'], 'client_id'=>(string)getSetting('YT_CLIENT_ID', ''), 'client_secret'=>(string)getSetting('YT_CLIENT_SECRET', ''),
    'redirect_uri'=>ytRedirectUri(), 'grant_type'=>'authorization_code']));
  $d = json_decode($res['body'] ?? '', true);
  if(empty($d['access_token'])) ytPage(false, 'گرفتن توکن از گوگل ناموفق بود: '.($d['error_description'] ?? ($d['error'] ?? mb_substr((string)($res['body'] ?? ''), 0, 200))));
  if(!empty($d['refresh_token'])) setSetting('YT_REFRESH_TOKEN', $d['refresh_token']);
  elseif((string)getSetting('YT_REFRESH_TOKEN', '') === '') ytPage(false, 'گوگل refresh token نداد — در myaccount.google.com/permissions دسترسی این اپ را بردار و دوباره وصل شو.');
  setSetting('YT_ACCESS_TOKEN', $d['access_token']);
  setSetting('YT_ACCESS_EXPIRES', (string)(time() + (int)($d['expires_in'] ?? 3600)));
  $ch = ytChannel($d['access_token']);
  if($ch['ok']){ setSetting('YT_CHANNEL_TITLE', $ch['title']); setSetting('YT_CHANNEL_ID', $ch['id']); ytPage(true, 'کانال «'.$ch['title'].'» وصل شد.'); }
  ytPage(true, 'اتصال انجام شد (نام کانال خوانده نشد: '.$ch['message'].')');
}

allowCors();
requireAuthOrAgentSecret();

if(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'){
  $in = jsonInput();
  if(!empty($in['disconnect'])){
    foreach(['YT_REFRESH_TOKEN','YT_ACCESS_TOKEN','YT_ACCESS_EXPIRES','YT_CHANNEL_TITLE','YT_CHANNEL_ID'] as $k) setSetting($k, '');
    jsonResponse(['ok'=>true]);
  }
  if(isset($in['privacy'])){
    $p = (string)$in['privacy'];
    if(!in_array($p, ['public','unlisted','private'], true)) jsonResponse(['error'=>'نوع انتشار نامعتبر است'], 400);
    setSetting('YT_PRIVACY', $p); jsonResponse(['ok'=>true]);
  }
  if(isset($in['client_id']) || isset($in['client_secret'])){
    $cid = trim((string)($in['client_id'] ?? '')); $sec = trim((string)($in['client_secret'] ?? ''));
    if(!preg_match('/\.apps\.googleusercontent\.com$/', $cid)) jsonResponse(['error'=>'Client ID باید با .apps.googleusercontent.com تمام شود'], 400);
    if(strlen($sec) < 10) jsonResponse(['error'=>'Client Secret خالی یا کوتاه است'], 400);
    setSetting('YT_CLIENT_ID', $cid); setSetting('YT_CLIENT_SECRET', $sec);
    jsonResponse(['ok'=>true]);
  }
  if(($in['action'] ?? '') === 'begin'){
    $cid = (string)getSetting('YT_CLIENT_ID', '');
    if($cid === '') jsonResponse(['error'=>'اول Client ID و Client Secret اپ گوگل را ذخیره کن'], 400);
    $state = bin2hex(random_bytes(16));
    setSetting('YT_OAUTH_STATE', $state); setSetting('YT_OAUTH_STATE_EXP', (string)(time() + 900));
    $url = 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
      'client_id'=>$cid, 'redirect_uri'=>ytRedirectUri(), 'response_type'=>'code', 'access_type'=>'offline', 'prompt'=>'consent',
      'include_granted_scopes'=>'true', 'state'=>$state,
      'scope'=>'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly']);
    jsonResponse(['ok'=>true, 'url'=>$url]);
  }
  jsonResponse(['error'=>'درخواست نامعتبر'], 400);
}

$out = ['has_client'=>(string)getSetting('YT_CLIENT_ID', '') !== '' && (string)getSetting('YT_CLIENT_SECRET', '') !== '',
        'client_id'=>(string)getSetting('YT_CLIENT_ID', ''),
        'connected'=>(string)getSetting('YT_REFRESH_TOKEN', '') !== '',
        'channel'=>(string)getSetting('YT_CHANNEL_TITLE', ''), 'privacy'=>(string)getSetting('YT_PRIVACY', 'public'),
        'redirect_uri'=>ytRedirectUri(), 'valid'=>null, 'message'=>''];
if($out['connected']){
  $tok = ytAccessToken();
  if(!$tok['ok']){ $out['valid'] = false; $out['message'] = $tok['message']; }
  else{ $ch = ytChannel($tok['token']); $out['valid'] = $ch['ok']; if($ch['ok']){ $out['channel'] = $ch['title']; setSetting('YT_CHANNEL_TITLE', $ch['title']); } else $out['message'] = $ch['message']; }
}
jsonResponse($out);
