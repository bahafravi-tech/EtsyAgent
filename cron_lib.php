<?php
// cron_lib.php — منطق واقعی انتشار خودکار (هم از cron_publish.php هم از api/run_cron_now.php صدا زده می‌شه)

require_once __DIR__.'/db.php';
require_once __DIR__.'/lib_banned.php';

function runCronOnce(){
  @set_time_limit(300); // کاروسل چندعکسی ممکنه هر عکس تا ۳۰ ثانیه طول بکشه، پس سقف رو بازتر می‌ذاریم
  try{ refreshInstagramTokenIfNeeded(); }catch(Throwable $e){ /* تمدید ناموفق نباید انتشار را متوقف کند */ }
  $pdo = db();
  $items = $pdo->query("SELECT * FROM content_items WHERE status='approved' AND (scheduled_at IS NULL OR scheduled_at <= NOW())")->fetchAll();
  $summary = ['checked'=>count($items), 'attempted'=>0, 'published'=>0, 'failed'=>0, 'details'=>[]];

  foreach($items as $item){
    $pf = $pdo->prepare("SELECT * FROM content_platforms WHERE content_id=? AND status='pending'");
    $pf->execute([$item['id']]);
    $pendingPlatforms = $pf->fetchAll();
    if(!$pendingPlatforms) continue;

    $anySuccess = false;
    foreach($pendingPlatforms as $p){
      $summary['attempted']++;
      $result = publishToPlatform($item, $p['platform']);
      logPublish($item['id'], $p['platform'], $result['ok'], $result['message'] ?? '');
      $summary['details'][] = ['id'=>$item['id'], 'title'=>$item['title'], 'platform'=>$p['platform'], 'ok'=>$result['ok'], 'message'=>$result['message'] ?? ''];
      if($result['ok']){
        $pdo->prepare("UPDATE content_platforms SET status='published', published_at=NOW(), external_id=? WHERE content_id=? AND platform=?")
            ->execute([$result['external_id'] ?? null, $item['id'], $p['platform']]);
        $anySuccess = true;
        $summary['published']++;
      }else{
        $pdo->prepare("UPDATE content_platforms SET status='failed', error_text=? WHERE content_id=? AND platform=?")
            ->execute([$result['message'] ?? 'خطای نامشخص', $item['id'], $p['platform']]);
        $summary['failed']++;
      }
    }

    // اگه حداقل یه پلتفرم موفق بود، خودِ آیتم published می‌شه — منتظر بقیه نمی‌مونه.
    // برای پلتفرم شکست‌خورده، از پنل دکمه‌ی «تلاش مجدد» رو می‌زنی.
    if($anySuccess){
      $pdo->prepare("UPDATE content_items SET status='published', published_at=NOW() WHERE id=?")->execute([$item['id']]);
    }
  }
  return $summary;
}

function logPublish($contentId, $platform, $success, $message){
  db()->prepare('INSERT INTO publish_log (content_id, platform, success, message) VALUES (?,?,?,?)')
      ->execute([$contentId, $platform, $success ? 1 : 0, $message]);
}

function fullCaption($item){
  // واژه‌های ممنوعه‌ی همان فروشگاه (مثل Iran) قبل از انتشار جایگزین می‌شوند، حتی اگر در کپشن قدیمی مانده باشند
  $shop = $item['shop'] ?? '';
  $hashtags = bannedApplyTags($item['hashtags'] ?? '', $shop);
  $tags = $hashtags
    ? implode(' ', array_map(fn($h) => '#'.preg_replace('/[^a-zA-Z0-9_]/','',trim($h)), explode(',', $hashtags)))
    : '';
  return trim(bannedApply($item['caption'] ?? '', $shop)."\n\n".$tags);
}

function imageUrl($item){
  if(!$item['hero_image_path']) return null;
  $siteUrl = rtrim(cfg()['SITE_URL'] ?? '', '/');
  // مستقیم از پوشه‌ی uploads نمی‌ره — چون سرور/WAF گاهی به‌جای فایل کامل، جواب ناقص (206) می‌داد
  // که باعث رد شدن عکس توسط اینستاگرام می‌شد. از api/image.php رد می‌شه تا همیشه فایل کامل با 200 بیاد.
  return $siteUrl.'/api/image.php?path='.urlencode($item['hero_image_path']);
}

function publishToPlatform($item, $platform){
  switch($platform){
    case 'telegram': return publishToTelegram($item);
    case 'instagram': return publishToInstagram($item);
    case 'pinterest': return publishToPinterestPlatform($item);
    case 'facebook': return publishToFacebook($item);
    case 'youtube': return publishToYouTube($item);
    default: return ['ok'=>false, 'message'=>'پلتفرم ناشناخته: '.$platform];
  }
}

function publishToTelegram($item){
  $c = cfg();
  if(empty($c['TELEGRAM_BOT_TOKEN']) || empty($c['TELEGRAM_CHAT_ID'])) return ['ok'=>false, 'message'=>'تنظیمات تلگرام کامل نیست'];
  $caption = mb_substr(fullCaption($item), 0, 1000);
  $img = imageUrl($item);
  $method = $img ? 'sendPhoto' : 'sendMessage';
  $url = "https://api.telegram.org/bot{$c['TELEGRAM_BOT_TOKEN']}/{$method}";
  $body = $img
    ? ['chat_id'=>$c['TELEGRAM_CHAT_ID'], 'photo'=>$img, 'caption'=>$caption]
    : ['chat_id'=>$c['TELEGRAM_CHAT_ID'], 'text'=>$caption];
  $res = relayFetch($url, 'POST', ['Content-Type'=>'application/json'], json_encode($body));
  $data = json_decode($res['body'] ?? '', true);
  if($res['ok'] && !empty($data['ok'])) return ['ok'=>true, 'external_id'=>$data['result']['message_id'] ?? null];
  return ['ok'=>false, 'message'=>$data['description'] ?? ($res['body'] ?? 'خطای نامشخص')];
}

// توکن/شناسه‌ی اینستاگرام: اول از تنظیمات ذخیره‌شده توسط برنامه (api/instagram_token.php)، وگرنه از config.php
function igToken(){ $t = (string)getSetting('IG_ACCESS_TOKEN', ''); return $t !== '' ? $t : (string)(cfg()['IG_ACCESS_TOKEN'] ?? ''); }
function igAccountId(){ $t = (string)getSetting('IG_BUSINESS_ACCOUNT_ID', ''); return $t !== '' ? $t : (string)(cfg()['IG_BUSINESS_ACCOUNT_ID'] ?? ''); }
// توکن «ورود با اینستاگرام» (با IG شروع می‌شود) از graph.instagram.com کار می‌کند؛ توکن فیسبوک از graph.facebook.com
function igGraphBase($token){ return (strpos((string)$token, 'IG') === 0) ? 'https://graph.instagram.com/v21.0' : 'https://graph.facebook.com/v19.0'; }
// توکن اینستاگرام ۶۰ روز اعتبار دارد؛ هر ۲۰ روز خودکار تمدید می‌شود (فقط توکن‌های «ورود با اینستاگرام»)
function refreshInstagramTokenIfNeeded(){
  $t = igToken();
  if(strpos($t, 'IG') !== 0) return;
  $last = (int)getSetting('IG_TOKEN_REFRESHED_AT', '0');
  if(!$last){ setSetting('IG_TOKEN_REFRESHED_AT', (string)time()); return; }
  if(time() - $last < 20*86400) return;
  $res = relayFetch('https://graph.instagram.com/refresh_access_token?grant_type=ig_refresh_token&access_token='.urlencode($t), 'GET');
  $d = json_decode($res['body'] ?? '', true);
  if($res['ok'] && !empty($d['access_token'])){
    setSetting('IG_ACCESS_TOKEN', $d['access_token']);
    setSetting('IG_TOKEN_REFRESHED_AT', (string)time());
  }
}

function publishToInstagram($item){
  $c = cfg();
  if(igAccountId() === '' || igToken() === '') return ['ok'=>false, 'message'=>'تنظیمات اینستاگرام کامل نیست'];

  $images = ($item['images_json'] ?? null) ? json_decode($item['images_json'], true) : [];
  if(!is_array($images) || !$images){
    $images = $item['hero_image_path'] ? [$item['hero_image_path']] : [];
  }
  if(!$images) return ['ok'=>false, 'message'=>'عکس هنوز ساخته نشده'];

  $siteUrl = rtrim(cfg()['SITE_URL'] ?? '', '/');
  // اینستاگرام حداکثر ۱۰ اسلاید توی هر کاروسل قبول می‌کنه
  $imageUrls = array_map(fn($p) => $siteUrl.'/api/image.php?path='.urlencode($p), array_slice($images, 0, 10));

  $caption = fullCaption($item);
  $token = igToken();
  $igId = igAccountId();
  $base = igGraphBase($token);

  // ساخت یه media container و صبر تا فیسبوک واقعاً پردازشش کنه (status_code=FINISHED)
  $createAndWait = function($params) use ($igId, $token, $base){
    $createUrl = "{$base}/{$igId}/media";
    $res1 = relayFetch($createUrl, 'POST', ['Content-Type'=>'application/x-www-form-urlencoded'], http_build_query(array_merge($params, ['access_token'=>$token])));
    $data1 = json_decode($res1['body'] ?? '', true);
    if(!$res1['ok'] || empty($data1['id'])){
      return ['ok'=>false, 'message'=>'خطا در ساخت container (کد '.($res1['status']??'?').'): '.($res1['body'] ?? 'پاسخ خالی')];
    }
    $containerId = $data1['id'];
    $statusUrl = "{$base}/{$containerId}?fields=status_code&access_token={$token}";
    for($i=0; $i<15; $i++){
      sleep(2);
      $resStatus = relayFetch($statusUrl, 'GET');
      $dataStatus = json_decode($resStatus['body'] ?? '', true);
      $statusCode = $dataStatus['status_code'] ?? null;
      if($statusCode === 'FINISHED') return ['ok'=>true, 'id'=>$containerId];
      if($statusCode === 'ERROR' || $statusCode === 'EXPIRED'){
        return ['ok'=>false, 'message'=>'پردازش رسانه شکست خورد (status: '.$statusCode.')'];
      }
    }
    return ['ok'=>false, 'message'=>'بعد از ۳۰ ثانیه هنوز پردازش تموم نشده بود'];
  };

  if(count($imageUrls) === 1){
    $r = $createAndWait(['image_url'=>$imageUrls[0], 'caption'=>$caption]);
    if(!$r['ok']) return $r;
    $finalContainerId = $r['id'];
  }else{
    // هر عکس یه «آیتم کاروسل» جدا می‌شه (بدون caption مستقل)، بعد یه container والد همه‌شون رو کنار هم می‌ذاره
    $childIds = [];
    foreach($imageUrls as $url){
      $r = $createAndWait(['image_url'=>$url, 'is_carousel_item'=>'true']);
      if(!$r['ok']) return ['ok'=>false, 'message'=>'خطا در یکی از اسلایدهای کاروسل: '.$r['message']];
      $childIds[] = $r['id'];
    }
    $r = $createAndWait(['media_type'=>'CAROUSEL', 'children'=>implode(',', $childIds), 'caption'=>$caption]);
    if(!$r['ok']) return ['ok'=>false, 'message'=>'خطا در ساخت کاروسل نهایی: '.$r['message']];
    $finalContainerId = $r['id'];
  }

  $pubUrl = "{$base}/{$igId}/media_publish";
  $res2 = relayFetch($pubUrl, 'POST', ['Content-Type'=>'application/x-www-form-urlencoded'], http_build_query([
    'creation_id' => $finalContainerId, 'access_token' => $token,
  ]));
  $data2 = json_decode($res2['body'] ?? '', true);
  if($res2['ok'] && !empty($data2['id'])) return ['ok'=>true, 'external_id'=>$data2['id']];
  return ['ok'=>false, 'message'=>$data2['error']['message'] ?? ($res2['body'] ?? 'خطا در انتشار')];
}

function publishToPinterestPlatform($item){
  $c = cfg();
  // حالت RSS: «انتشار» یعنی آیتم به فید api/pinterest_feed.php اضافه می‌شود و خودِ Pinterest (Auto-publish) روزانه پین می‌سازد
  if(getSetting('PINTEREST_MODE', $c['PINTEREST_MODE'] ?? 'api') === 'rss'){
    if(empty($item['hero_image_path'])) return ['ok'=>false, 'message'=>'عکس هنوز ساخته نشده'];
    return ['ok'=>true, 'external_id'=>'rss-feed'];
  }
  if(empty($c['PINTEREST_ACCESS_TOKEN']) || empty($c['PINTEREST_BOARD_ID'])) return ['ok'=>false, 'message'=>'تنظیمات پینترست کامل نیست'];
  $img = imageUrl($item);
  if(!$img) return ['ok'=>false, 'message'=>'عکس هنوز ساخته نشده'];

  $url = 'https://api.pinterest.com/v5/pins';
  $body = json_encode([
    'board_id' => $c['PINTEREST_BOARD_ID'],
    'title' => mb_substr(bannedApply($item['title'], $item['shop'] ?? ''), 0, 100),
    'description' => mb_substr(fullCaption($item), 0, 500),
    'media_source' => ['source_type'=>'image_url', 'url'=>$img],
  ]);
  $res = relayFetch($url, 'POST', ['Content-Type'=>'application/json', 'Authorization'=>'Bearer '.$c['PINTEREST_ACCESS_TOKEN']], $body);
  $data = json_decode($res['body'] ?? '', true);
  if($res['ok'] && !empty($data['id'])) return ['ok'=>true, 'external_id'=>$data['id']];
  return ['ok'=>false, 'message'=>$data['message'] ?? ($res['body'] ?? 'خطای نامشخص')];
}


// ════════════════════════ فیسبوک (صفحه‌ی بیزینسی) ════════════════════════
// توکن صفحه (Page Access Token) و شناسه‌ی صفحه از api/facebook_token.php ذخیره می‌شوند
function fbPageId(){ return (string)getSetting('FB_PAGE_ID', ''); }
function fbPageToken(){ return (string)getSetting('FB_PAGE_TOKEN', ''); }
const FB_GRAPH = 'https://graph.facebook.com/v21.0';

function itemImagePaths($item, $max=10){
  $images = ($item['images_json'] ?? null) ? json_decode($item['images_json'], true) : [];
  if(!is_array($images) || !$images) $images = !empty($item['hero_image_path']) ? [$item['hero_image_path']] : [];
  return array_slice(array_values(array_filter($images, 'is_string')), 0, $max);
}

function publishToFacebook($item){
  $page = fbPageId(); $token = fbPageToken();
  if($page === '' || $token === '') return ['ok'=>false, 'message'=>'تنظیمات فیسبوک کامل نیست (در برنامه: تنظیمات ← فیسبوک، توکن صفحه را ذخیره کن)'];
  $images = itemImagePaths($item, 10);
  $siteUrl = rtrim(cfg()['SITE_URL'] ?? '', '/');
  $urls = array_map(fn($p) => $siteUrl.'/api/image.php?path='.urlencode($p), $images);
  $message = fullCaption($item);
  $post = function($path, $params) use ($token){
    $res = relayFetch(FB_GRAPH.$path, 'POST', ['Content-Type'=>'application/x-www-form-urlencoded'], http_build_query(array_merge($params, ['access_token'=>$token])));
    $d = json_decode($res['body'] ?? '', true);
    return [$res, is_array($d) ? $d : []];
  };
  $err = fn($res, $d) => $d['error']['message'] ?? ($res['body'] ?? 'پاسخی از فیسبوک نیامد');

  if(!$urls){
    [$res, $d] = $post("/{$page}/feed", ['message'=>$message]);
    return !empty($d['id']) ? ['ok'=>true, 'external_id'=>$d['id']] : ['ok'=>false, 'message'=>$err($res, $d)];
  }
  if(count($urls) === 1){
    [$res, $d] = $post("/{$page}/photos", ['url'=>$urls[0], 'caption'=>$message]);
    $id = $d['post_id'] ?? ($d['id'] ?? null);
    return $id ? ['ok'=>true, 'external_id'=>$id] : ['ok'=>false, 'message'=>$err($res, $d)];
  }
  // چند عکس: هر عکس اول بدون انتشار آپلود می‌شود، بعد یک پست با همه‌ی عکس‌ها ساخته می‌شود
  $attached = [];
  foreach($urls as $k=>$u){
    [$res, $d] = $post("/{$page}/photos", ['url'=>$u, 'published'=>'false']);
    if(empty($d['id'])) return ['ok'=>false, 'message'=>'آپلود عکس '.($k+1).' به فیسبوک ناموفق بود: '.$err($res, $d)];
    $attached['attached_media['.$k.']'] = json_encode(['media_fbid'=>$d['id']]);
  }
  [$res, $d] = $post("/{$page}/feed", array_merge(['message'=>$message], $attached));
  return !empty($d['id']) ? ['ok'=>true, 'external_id'=>$d['id']] : ['ok'=>false, 'message'=>$err($res, $d)];
}

// ════════════════════════ یوتیوب (ویدیوی ساخته‌شده از تصویرها) ════════════════════════
// اتصال با OAuth گوگل (api/youtube_auth.php). ویدیو را خود برنامه در مرورگر از تصویرهای آیتم می‌سازد
// و در content_items.video_path ذخیره می‌کند؛ اینجا فقط آپلود و انتشار انجام می‌شود.
function ytAccessToken(){
  $cached = (string)getSetting('YT_ACCESS_TOKEN', '');
  $exp = (int)getSetting('YT_ACCESS_EXPIRES', '0');
  if($cached !== '' && $exp > time() + 120) return ['ok'=>true, 'token'=>$cached];
  $cid = (string)getSetting('YT_CLIENT_ID', ''); $sec = (string)getSetting('YT_CLIENT_SECRET', ''); $rt = (string)getSetting('YT_REFRESH_TOKEN', '');
  if($cid === '' || $sec === '' || $rt === '') return ['ok'=>false, 'message'=>'یوتیوب هنوز وصل نشده (در برنامه: تنظیمات ← یوتیوب ← اتصال)'];
  $res = relayFetch('https://oauth2.googleapis.com/token', 'POST', ['Content-Type'=>'application/x-www-form-urlencoded'],
    http_build_query(['client_id'=>$cid, 'client_secret'=>$sec, 'refresh_token'=>$rt, 'grant_type'=>'refresh_token']));
  $d = json_decode($res['body'] ?? '', true);
  if(!empty($d['access_token'])){
    setSetting('YT_ACCESS_TOKEN', $d['access_token']);
    setSetting('YT_ACCESS_EXPIRES', (string)(time() + (int)($d['expires_in'] ?? 3600)));
    return ['ok'=>true, 'token'=>$d['access_token']];
  }
  $msg = $d['error_description'] ?? ($d['error'] ?? ($res['body'] ?? 'پاسخی از گوگل نیامد'));
  if(($d['error'] ?? '') === 'invalid_grant') $msg = 'اجازه‌ی یوتیوب باطل یا منقضی شده — در تنظیمات دوباره «اتصال به یوتیوب» را بزن (اگر اپ گوگل در حالت Testing است، اجازه‌ها بعد از ۷ روز باطل می‌شوند؛ آن را In production کن)';
  return ['ok'=>false, 'message'=>$msg];
}

// ارسال بدنه‌ی باینری بزرگ (ویدیو): از رله در حالت «جریان خام» تا Worker مجبور به base64 نشود
function rawUpload($url, $headers, $bytes, $timeout=600){
  $relayUrl = getSetting('RELAY_URL', cfg()['RELAY_URL'] ?? '');
  $relaySecret = getSetting('RELAY_SECRET', cfg()['RELAY_SECRET'] ?? '');
  $hdrs = [];
  if($relayUrl){
    $target = $relayUrl;
    $hdrs = ['X-Relay-Secret: '.$relaySecret, 'X-Relay-Url: '.$url, 'X-Relay-Method: POST', 'X-Relay-Headers: '.json_encode($headers), 'Content-Type: application/octet-stream'];
  }else{
    $target = $url;
    foreach($headers as $k=>$v) $hdrs[] = "$k: $v";
  }
  $ch = curl_init($target);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$bytes, CURLOPT_HTTPHEADER=>$hdrs, CURLOPT_TIMEOUT=>$timeout]);
  $res = curl_exec($ch); $err = curl_error($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  if($err) return ['ok'=>false, 'status'=>0, 'body'=>$err];
  return ['ok'=>$status>=200 && $status<300, 'status'=>$status, 'body'=>$res];
}

function publishToYouTube($item){
  $vp = (string)($item['video_path'] ?? '');
  if($vp === '') return ['ok'=>false, 'message'=>'ویدیوی این آیتم هنوز ساخته نشده — در برنامه جزئیات آیتم را باز کن و «🎬 ساخت ویدیو» را بزن، بعد «تلاش مجدد»'];
  $full = __DIR__.'/'.str_replace(['..','\\'], '', $vp);
  if(!is_file($full)) return ['ok'=>false, 'message'=>'فایل ویدیو روی سرور پیدا نشد: '.$vp];
  $tok = ytAccessToken(); if(!$tok['ok']) return $tok;
  $shop = $item['shop'] ?? '';
  $title = trim(preg_replace('/\s+/u', ' ', bannedApply((string)$item['title'], $shop)));
  $title = str_replace(['<','>'], '', $title);
  if(mb_strlen($title) > 100) $title = rtrim(mb_substr($title, 0, 99)).'…';
  $tags = array_values(array_filter(array_map(fn($t) => trim(str_replace(['<','>','#'], '', $t)), explode(',', bannedApplyTags($item['hashtags'] ?? '', $shop)))));
  $tags = array_slice($tags, 0, 15);
  $desc = str_replace(['<','>'], '', mb_substr(fullCaption($item), 0, 4900));
  $privacy = (string)getSetting('YT_PRIVACY', 'public');
  if(!in_array($privacy, ['public','unlisted','private'], true)) $privacy = 'public';
  $meta = ['snippet'=>['title'=>$title, 'description'=>$desc, 'tags'=>$tags, 'categoryId'=>'26'],
           'status'=>['privacyStatus'=>$privacy, 'selfDeclaredMadeForKids'=>false, 'embeddable'=>true]];
  $mime = preg_match('/\.mp4$/i', $full) ? 'video/mp4' : 'video/webm';
  $boundary = 'bgch'.bin2hex(random_bytes(8));
  $body = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n".json_encode($meta, JSON_UNESCAPED_UNICODE)."\r\n"
        . "--{$boundary}\r\nContent-Type: {$mime}\r\n\r\n".file_get_contents($full)."\r\n--{$boundary}--";
  $res = rawUpload('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=multipart&part=snippet,status',
    ['Authorization'=>'Bearer '.$tok['token'], 'Content-Type'=>'multipart/related; boundary='.$boundary], $body);
  $d = json_decode($res['body'] ?? '', true);
  if($res['ok'] && !empty($d['id'])) return ['ok'=>true, 'external_id'=>$d['id']];
  $msg = $d['error']['message'] ?? ($res['body'] ?? 'پاسخی از یوتیوب نیامد');
  if(stripos((string)$res['body'], 'Host not allowed') !== false) $msg = 'رله‌ی Cloudflare دامنه‌های گوگل را مجاز نمی‌داند — کد جدید relay-worker.js را در Worker بگذار.';
  if(stripos((string)$res['body'], 'quotaExceeded') !== false) $msg = 'سهمیه‌ی روزانه‌ی یوتیوب تمام شده (هر آپلود ۱۶۰۰ واحد از ۱۰۰۰۰) — فردا خودکار دوباره امتحان کن';
  return ['ok'=>false, 'message'=>mb_substr((string)$msg, 0, 500)];
}
