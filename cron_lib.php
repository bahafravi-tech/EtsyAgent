<?php
// cron_lib.php — منطق واقعی انتشار خودکار (هم از cron_publish.php هم از api/run_cron_now.php صدا زده می‌شه)

require_once __DIR__.'/db.php';

function runCronOnce(){
  @set_time_limit(300); // کاروسل چندعکسی ممکنه هر عکس تا ۳۰ ثانیه طول بکشه، پس سقف رو بازتر می‌ذاریم
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
        $pdo->prepare("UPDATE content_platforms SET status='published', published_at=NOW() WHERE content_id=? AND platform=?")
            ->execute([$item['id'], $p['platform']]);
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
  $tags = $item['hashtags']
    ? implode(' ', array_map(fn($h) => '#'.preg_replace('/[^a-zA-Z0-9_]/','',trim($h)), explode(',', $item['hashtags'])))
    : '';
  return trim(($item['caption'] ?? '')."\n\n".$tags);
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
  if($res['ok'] && !empty($data['ok'])) return ['ok'=>true];
  return ['ok'=>false, 'message'=>$data['description'] ?? ($res['body'] ?? 'خطای نامشخص')];
}

function publishToInstagram($item){
  $c = cfg();
  if(empty($c['IG_BUSINESS_ACCOUNT_ID']) || empty($c['IG_ACCESS_TOKEN'])) return ['ok'=>false, 'message'=>'تنظیمات اینستاگرام کامل نیست'];

  $images = ($item['images_json'] ?? null) ? json_decode($item['images_json'], true) : [];
  if(!is_array($images) || !$images){
    $images = $item['hero_image_path'] ? [$item['hero_image_path']] : [];
  }
  if(!$images) return ['ok'=>false, 'message'=>'عکس هنوز ساخته نشده'];

  $siteUrl = rtrim(cfg()['SITE_URL'] ?? '', '/');
  // اینستاگرام حداکثر ۱۰ اسلاید توی هر کاروسل قبول می‌کنه
  $imageUrls = array_map(fn($p) => $siteUrl.'/api/image.php?path='.urlencode($p), array_slice($images, 0, 10));

  $caption = fullCaption($item);
  $token = $c['IG_ACCESS_TOKEN'];
  $igId = $c['IG_BUSINESS_ACCOUNT_ID'];

  // ساخت یه media container و صبر تا فیسبوک واقعاً پردازشش کنه (status_code=FINISHED)
  $createAndWait = function($params) use ($igId, $token){
    $createUrl = "https://graph.facebook.com/v19.0/{$igId}/media";
    $res1 = relayFetch($createUrl, 'POST', ['Content-Type'=>'application/x-www-form-urlencoded'], http_build_query(array_merge($params, ['access_token'=>$token])));
    $data1 = json_decode($res1['body'] ?? '', true);
    if(!$res1['ok'] || empty($data1['id'])){
      return ['ok'=>false, 'message'=>'خطا در ساخت container (کد '.($res1['status']??'?').'): '.($res1['body'] ?? 'پاسخ خالی')];
    }
    $containerId = $data1['id'];
    $statusUrl = "https://graph.facebook.com/v19.0/{$containerId}?fields=status_code&access_token={$token}";
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

  $pubUrl = "https://graph.facebook.com/v19.0/{$igId}/media_publish";
  $res2 = relayFetch($pubUrl, 'POST', ['Content-Type'=>'application/x-www-form-urlencoded'], http_build_query([
    'creation_id' => $finalContainerId, 'access_token' => $token,
  ]));
  $data2 = json_decode($res2['body'] ?? '', true);
  if($res2['ok'] && !empty($data2['id'])) return ['ok'=>true];
  return ['ok'=>false, 'message'=>$data2['error']['message'] ?? ($res2['body'] ?? 'خطا در انتشار')];
}

function publishToPinterestPlatform($item){
  $c = cfg();
  if(empty($c['PINTEREST_ACCESS_TOKEN']) || empty($c['PINTEREST_BOARD_ID'])) return ['ok'=>false, 'message'=>'تنظیمات پینترست کامل نیست'];
  $img = imageUrl($item);
  if(!$img) return ['ok'=>false, 'message'=>'عکس هنوز ساخته نشده'];

  $url = 'https://api.pinterest.com/v5/pins';
  $body = json_encode([
    'board_id' => $c['PINTEREST_BOARD_ID'],
    'title' => mb_substr($item['title'], 0, 100),
    'description' => mb_substr(fullCaption($item), 0, 500),
    'media_source' => ['source_type'=>'image_url', 'url'=>$img],
  ]);
  $res = relayFetch($url, 'POST', ['Content-Type'=>'application/json', 'Authorization'=>'Bearer '.$c['PINTEREST_ACCESS_TOKEN']], $body);
  $data = json_decode($res['body'] ?? '', true);
  if($res['ok'] && !empty($data['id'])) return ['ok'=>true];
  return ['ok'=>false, 'message'=>$data['message'] ?? ($res['body'] ?? 'خطای نامشخص')];
}
