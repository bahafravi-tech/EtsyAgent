<?php
// lib_marketing.php — سیستم بازاریابی خودکار: جمع‌آوری آمار، خلاصه‌سازی، تحلیل با Claude، اجرای پلن
// نکته: متریک‌های دقیق Instagram/Pinterest ممکنه با تغییر نسخه‌ی API اون پلتفرم‌ها کمی فرق کنه —
// هر پلتفرم جدا try/catch شده تا خطای یکی بقیه رو متوقف نکنه.

require_once __DIR__.'/db.php';

function marketingLog($lines, $text){ $lines[] = $text; return $lines; }

// ══ ۱. جمع‌آوری آمار ══
function collectMetrics(){
  $log = [];
  $log[] = '📊 شروع جمع‌آوری آمار — '.date('Y-m-d H:i:s');

  // -- آمار پست‌های ۹۰ روز اخیر، حداکثر ۶۰ پست منتشرشده به‌ازای هر پلتفرم --
  $pdo = db();
  foreach(['telegram','instagram','pinterest'] as $platform){
    try{
      $stmt = $pdo->prepare("SELECT cp.content_id, cp.external_id FROM content_platforms cp
        JOIN content_items ci ON ci.id=cp.content_id
        WHERE cp.platform=? AND cp.status='published' AND cp.external_id IS NOT NULL
          AND cp.published_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
        ORDER BY cp.published_at DESC LIMIT 60");
      $stmt->execute([$platform]);
      $rows = $stmt->fetchAll();
      $ok=0; $fail=0;
      foreach($rows as $row){
        $m = fetchPostMetric($platform, $row['external_id']);
        if($m === null){ $fail++; continue; }
        upsertPostMetric($row['content_id'], $platform, $row['external_id'], $m);
        $ok++;
      }
      $log[] = "  {$platform}: {$ok} پست آمارش گرفته شد".($fail? "، {$fail} تا شکست خورد":'');
    }catch(Throwable $e){
      $log[] = "  ⚠️ {$platform}: خطا — ".$e->getMessage();
    }
  }

  // -- آمار روزانه‌ی خودِ حساب (فالوور/عضو) --
  foreach(['instagram','pinterest','telegram'] as $platform){
    try{
      $accountMetrics = fetchAccountMetrics($platform);
      foreach($accountMetrics as $metricName=>$value){
        upsertAccountMetric($platform, $metricName, $value);
      }
      if($accountMetrics) $log[] = "  {$platform} (حساب): ".implode(', ', array_map(fn($k,$v)=>"$k=$v", array_keys($accountMetrics), $accountMetrics));
    }catch(Throwable $e){
      $log[] = "  ⚠️ {$platform} (حساب): خطا — ".$e->getMessage();
    }
  }

  // -- آمار لیستینگ‌های Etsy (بدون OAuth — فقط با App API Key، بازدید + پسندیده) --
  try{
    $etsyResult = fetchEtsyListingMetrics();
    $log[] = "  etsy: {$etsyResult['count']} لیستینگ آمارش گرفته شد (بازدید مجموع: {$etsyResult['total_views']}, پسندیده مجموع: {$etsyResult['total_favorers']})";
  }catch(Throwable $e){
    $log[] = '  ⚠️ etsy: خطا — '.$e->getMessage();
  }

  setSetting('marketing_last_collect', date('c'));
  $log[] = '✅ جمع‌آوری آمار تمام شد';
  return implode("\n", $log);
}

function upsertPostMetric($contentId, $platform, $externalId, $m){
  db()->prepare('INSERT INTO marketing_post_metrics
      (content_id, platform, external_id, reach, views, likes, comments, saves, shares, clicks, raw_json, fetched_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())
      ON DUPLICATE KEY UPDATE content_id=VALUES(content_id), reach=VALUES(reach), views=VALUES(views),
        likes=VALUES(likes), comments=VALUES(comments), saves=VALUES(saves), shares=VALUES(shares),
        clicks=VALUES(clicks), raw_json=VALUES(raw_json), fetched_at=NOW()')
    ->execute([$contentId, $platform, $externalId,
      $m['reach']??0, $m['views']??0, $m['likes']??0, $m['comments']??0, $m['saves']??0, $m['shares']??0, $m['clicks']??0,
      json_encode($m, JSON_UNESCAPED_UNICODE)]);
}

function upsertAccountMetric($platform, $metric, $value){
  db()->prepare('INSERT INTO marketing_account_metrics (platform, metric, value, recorded_on) VALUES (?,?,?,CURDATE())
      ON DUPLICATE KEY UPDATE value=VALUES(value)')
    ->execute([$platform, $metric, (int)$value]);
}

// یه پست از یه پلتفرم خاص — null یعنی نتونستیم بگیریم (خطای دیگران، جمع‌آوری ادامه پیدا می‌کنه)
function fetchPostMetric($platform, $externalId){
  $c = cfg();
  if($platform==='instagram' && !empty($c['IG_ACCESS_TOKEN'])){
    $token = $c['IG_ACCESS_TOKEN'];
    $res = relayFetch("https://graph.facebook.com/v19.0/{$externalId}?fields=like_count,comments_count&access_token={$token}", 'GET');
    $data = json_decode($res['body'] ?? '', true);
    if(!$res['ok'] || !is_array($data) || isset($data['error'])) return null;
    $m = ['likes'=>$data['like_count']??0, 'comments'=>$data['comments_count']??0];
    // insights فقط برای بعضی نوع مدیاها و بعد از یه مدت در دسترسه — اگه نبود، فقط لایک/کامنت کافیه
    $res2 = relayFetch("https://graph.facebook.com/v19.0/{$externalId}/insights?metric=reach,saved,shares&access_token={$token}", 'GET');
    $data2 = json_decode($res2['body'] ?? '', true);
    if($res2['ok'] && !empty($data2['data'])){
      foreach($data2['data'] as $d){
        $val = $d['values'][0]['value'] ?? 0;
        if($d['name']==='reach') $m['reach']=$val;
        if($d['name']==='saved') $m['saves']=$val;
        if($d['name']==='shares') $m['shares']=$val;
      }
    }
    return $m;
  }
  if($platform==='pinterest' && !empty($c['PINTEREST_ACCESS_TOKEN'])){
    $end = date('Y-m-d'); $start = date('Y-m-d', strtotime('-30 days'));
    $url = "https://api.pinterest.com/v5/pins/{$externalId}/analytics?start_date={$start}&end_date={$end}&metric_types=IMPRESSION,PIN_CLICK,OUTBOUND_CLICK,SAVE";
    $res = relayFetch($url, 'GET', ['Authorization'=>'Bearer '.$c['PINTEREST_ACCESS_TOKEN']]);
    $data = json_decode($res['body'] ?? '', true);
    if(!$res['ok'] || !is_array($data)) return null;
    $daily = $data['all']['daily_metrics'] ?? [];
    $sum = ['views'=>0,'clicks'=>0,'saves'=>0];
    foreach($daily as $day){
      $sum['views'] += $day['metrics']['IMPRESSION'] ?? 0;
      $sum['clicks'] += ($day['metrics']['PIN_CLICK'] ?? 0) + ($day['metrics']['OUTBOUND_CLICK'] ?? 0);
      $sum['saves'] += $day['metrics']['SAVE'] ?? 0;
    }
    return $sum;
  }
  // تلگرام: Bot API آمار بازدید تکی برای پیام‌های معمولی نمی‌ده — این پلتفرم رو skip می‌کنیم (data_gap)
  return null;
}

function fetchAccountMetrics($platform){
  $c = cfg();
  if($platform==='instagram' && !empty($c['IG_ACCESS_TOKEN']) && !empty($c['IG_BUSINESS_ACCOUNT_ID'])){
    $res = relayFetch("https://graph.facebook.com/v19.0/{$c['IG_BUSINESS_ACCOUNT_ID']}?fields=followers_count&access_token={$c['IG_ACCESS_TOKEN']}", 'GET');
    $data = json_decode($res['body'] ?? '', true);
    if($res['ok'] && isset($data['followers_count'])) return ['followers'=>$data['followers_count']];
    return [];
  }
  if($platform==='pinterest' && !empty($c['PINTEREST_ACCESS_TOKEN'])){
    $res = relayFetch('https://api.pinterest.com/v5/user_account', 'GET', ['Authorization'=>'Bearer '.$c['PINTEREST_ACCESS_TOKEN']]);
    $data = json_decode($res['body'] ?? '', true);
    if($res['ok'] && isset($data['follower_count'])) return ['followers'=>$data['follower_count']];
    return [];
  }
  if($platform==='telegram' && !empty($c['TELEGRAM_BOT_TOKEN']) && !empty($c['TELEGRAM_CHAT_ID'])){
    $res = relayFetch("https://api.telegram.org/bot{$c['TELEGRAM_BOT_TOKEN']}/getChatMemberCount?chat_id=".urlencode($c['TELEGRAM_CHAT_ID']), 'GET');
    $data = json_decode($res['body'] ?? '', true);
    if($res['ok'] && !empty($data['ok'])) return ['members'=>$data['result']];
    return [];
  }
  return [];
}

// آمار لیستینگ‌های Etsy — فقط با App API Key (بدون OAuth)، بازدید + پسندیده
function fetchEtsyListingMetrics(){
  $c = cfg();
  if(empty($c['ETSY_API_KEY']) || empty($c['ETSY_SHOP_ID'])){
    throw new Exception('ETSY_API_KEY یا ETSY_SHOP_ID توی config.php تنظیم نشده');
  }
  $url = "https://openapi.etsy.com/v3/application/shops/{$c['ETSY_SHOP_ID']}/listings/active?limit=100";
  // openapi.etsy.com هم مثل api.etsy.com مشمول بلاک تحریمی روی هاست‌های ایرانه — باید از رله رد بشه
  $res = relayFetch($url, 'GET', ['x-api-key'=>$c['ETSY_API_KEY']]);
  $data = json_decode($res['body'] ?? '', true);
  if(!$res['ok'] || !isset($data['results'])) throw new Exception('پاسخ نامعتبر از Etsy: '.substr($res['body']??'',0,200));

  $count=0; $totalViews=0; $totalFav=0;
  foreach($data['results'] as $listing){
    $lid = (string)$listing['listing_id'];
    $views = (int)($listing['views'] ?? 0);
    $fav = (int)($listing['num_favorers'] ?? 0);
    db()->prepare('INSERT INTO marketing_post_metrics (content_id, platform, external_id, views, likes, raw_json, fetched_at)
        VALUES (NULL, "etsy", ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE views=VALUES(views), likes=VALUES(likes), raw_json=VALUES(raw_json), fetched_at=NOW()')
      ->execute([$lid, $views, $fav, json_encode(['title'=>$listing['title']??'', 'views'=>$views, 'num_favorers'=>$fav], JSON_UNESCAPED_UNICODE)]);
    $count++; $totalViews+=$views; $totalFav+=$fav;
  }
  upsertAccountMetric('etsy', 'listing_views_total', $totalViews);
  upsertAccountMetric('etsy', 'listing_favorers_total', $totalFav);
  upsertAccountMetric('etsy', 'active_listings', $count);
  return ['count'=>$count, 'total_views'=>$totalViews, 'total_favorers'=>$totalFav];
}

// ══ ۲. خلاصه‌سازی آمار برای مدل ══
function buildStats($days=30){
  $pdo = db();
  $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

  $totals = $pdo->prepare("SELECT
      COUNT(*) as published_count,
      SUM(CASE WHEN mm.content_id IS NOT NULL THEN 1 ELSE 0 END) as with_metrics,
      COALESCE(SUM(mm.reach),0) as total_reach,
      COALESCE(SUM(mm.likes+mm.comments+mm.saves+mm.shares+mm.clicks),0) as total_engagement
    FROM content_items ci
    LEFT JOIN marketing_post_metrics mm ON mm.content_id=ci.id
    WHERE ci.status='published' AND ci.published_at >= ?");
  $totals->execute([$since]);
  $totals = $totals->fetch();

  $pipelineRows = $pdo->query("SELECT status, COUNT(*) c FROM content_items GROUP BY status")->fetchAll();
  $pipeline = [];
  foreach($pipelineRows as $r) $pipeline[$r['status']] = (int)$r['c'];

  $platforms = [];
  $pfRows = $pdo->prepare("SELECT cp.platform,
      SUM(CASE WHEN cp.status='published' THEN 1 ELSE 0 END) published,
      SUM(CASE WHEN cp.status='failed' THEN 1 ELSE 0 END) failed,
      COALESCE(SUM(mm.reach),0) reach, COALESCE(SUM(mm.likes+mm.comments+mm.saves+mm.shares+mm.clicks),0) engagement
    FROM content_platforms cp
    JOIN content_items ci ON ci.id=cp.content_id
    LEFT JOIN marketing_post_metrics mm ON mm.content_id=cp.content_id AND mm.platform=cp.platform
    WHERE ci.published_at >= ? OR ci.published_at IS NULL
    GROUP BY cp.platform");
  $pfRows->execute([$since]);
  foreach($pfRows->fetchAll() as $r) $platforms[$r['platform']] = ['published'=>(int)$r['published'],'failed'=>(int)$r['failed'],'reach'=>(int)$r['reach'],'engagement'=>(int)$r['engagement']];

  // اتسی جدا از پلتفرم‌های محتوا حساب می‌شه (به content_items وصل نیست)
  $etsyAgg = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(views),0) v, COALESCE(SUM(likes),0) f FROM marketing_post_metrics WHERE platform='etsy'")->fetch();
  $platforms['etsy'] = ['active_listings'=>(int)$etsyAgg['c'], 'total_views'=>(int)$etsyAgg['v'], 'total_favorers'=>(int)$etsyAgg['f']];

  $accounts = [];
  $accRows = $pdo->query("SELECT platform, metric, MIN(value) first_val, MAX(recorded_on) last_date,
      SUBSTRING_INDEX(GROUP_CONCAT(value ORDER BY recorded_on DESC),',',1) last_val
    FROM marketing_account_metrics WHERE recorded_on >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY) GROUP BY platform, metric")->fetchAll();
  foreach($accRows as $r){
    $accounts[] = ['platform'=>$r['platform'],'metric'=>$r['metric'],'first'=>(int)$r['first_val'],'last'=>(int)$r['last_val'],'change'=>(int)$r['last_val']-(int)$r['first_val']];
  }

  // دسته‌بندی‌ها — همه‌ی tagهای موجود، حتی بدون پست در بازه
  $categories = [];
  $tagRows = $pdo->query("SELECT DISTINCT tag FROM content_items WHERE tag IS NOT NULL AND tag!=''")->fetchAll();
  foreach($tagRows as $t){
    $tag = $t['tag'];
    $stat = $pdo->prepare("SELECT
        SUM(CASE WHEN status='published' AND published_at>=? THEN 1 ELSE 0 END) published_in_period,
        COUNT(*) total, SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) draft,
        SUM(CASE WHEN status IN('approved') THEN 1 ELSE 0 END) queued, MAX(published_at) last_published
      FROM content_items WHERE tag=?");
    $stat->execute([$since, $tag]);
    $s = $stat->fetch();
    $engAgg = $pdo->prepare("SELECT COALESCE(AVG(mm.reach),0) avg_reach,
        COALESCE(SUM(mm.likes+mm.comments+mm.saves+mm.shares+mm.clicks)/NULLIF(SUM(mm.reach),0)*100,0) eng_rate
      FROM content_items ci JOIN marketing_post_metrics mm ON mm.content_id=ci.id WHERE ci.tag=?");
    $engAgg->execute([$tag]);
    $e = $engAgg->fetch();
    $categories[] = ['category'=>$tag, 'published_in_period'=>(int)$s['published_in_period'], 'total'=>(int)$s['total'],
      'draft'=>(int)$s['draft'], 'queued'=>(int)$s['queued'], 'last_published'=>$s['last_published'],
      'avg_reach'=>round((float)$e['avg_reach'],1), 'engagement_rate'=>round((float)$e['eng_rate'],2)];
  }

  // بهترین روز هفته / ساعت انتشار (بر اساس published_at)
  $byWeekday = []; $byHour = [];
  $wdRows = $pdo->prepare("SELECT DAYOFWEEK(ci.published_at) wd, AVG(mm.reach) avg_reach,
      AVG(mm.likes+mm.comments+mm.saves+mm.shares+mm.clicks) avg_eng
    FROM content_items ci JOIN marketing_post_metrics mm ON mm.content_id=ci.id
    WHERE ci.published_at IS NOT NULL GROUP BY wd");
  $wdRows->execute();
  foreach($wdRows->fetchAll() as $r){
    // DAYOFWEEK: ۱=یکشنبه...۷=شنبه در MySQL؛ به ISO (۱=دوشنبه...۷=یکشنبه) تبدیل می‌شه
    $iso = (int)$r['wd']===1 ? 7 : (int)$r['wd']-1;
    $byWeekday[] = ['weekday'=>$iso, 'avg_reach'=>round((float)$r['avg_reach'],1), 'avg_engagement'=>round((float)$r['avg_eng'],1)];
  }
  $hrRows = $pdo->prepare("SELECT HOUR(ci.published_at) hr, AVG(mm.reach) avg_reach
    FROM content_items ci JOIN marketing_post_metrics mm ON mm.content_id=ci.id
    WHERE ci.published_at IS NOT NULL GROUP BY hr");
  $hrRows->execute();
  foreach($hrRows->fetchAll() as $r) $byHour[] = ['hour'=>(int)$r['hr'], 'avg_reach'=>round((float)$r['avg_reach'],1)];

  // برترین/ضعیف‌ترین پست‌ها بر اساس نرخ تعامل
  $postRows = $pdo->prepare("SELECT ci.id, ci.title, ci.tag, cp.platform, mm.reach,
      (mm.likes+mm.comments+mm.saves+mm.shares+mm.clicks) engagement,
      IF(mm.reach>0, (mm.likes+mm.comments+mm.saves+mm.shares+mm.clicks)/mm.reach*100, 0) eng_rate
    FROM content_items ci JOIN content_platforms cp ON cp.content_id=ci.id
    JOIN marketing_post_metrics mm ON mm.content_id=ci.id AND mm.platform=cp.platform
    WHERE ci.published_at >= ? ORDER BY eng_rate DESC LIMIT 80");
  $postRows->execute([$since]);
  $allPosts = $postRows->fetchAll();
  $posts = array_map(fn($p)=>['id'=>(int)$p['id'],'title'=>$p['title'],'category'=>$p['tag'],'platform'=>$p['platform'],
    'reach'=>(int)$p['reach'],'engagement'=>(int)$p['engagement'],'engagement_rate'=>round((float)$p['eng_rate'],2)], $allPosts);
  $topPosts = array_slice($posts, 0, 5);
  $weakPosts = array_slice(array_reverse($posts), 0, 5);

  $upcomingRows = $pdo->query("SELECT title FROM content_items WHERE status IN('approved','draft') ORDER BY scheduled_at ASC LIMIT 30")->fetchAll();
  $upcoming = array_column($upcomingRows, 'title');

  // فهرست صریح داده‌هایی که نداریم — مدل باید بدونه به چی نمی‌شه تکیه کرد
  $dataGaps = [
    'آمار تکی پیام‌های تلگرام (بازدید/کلیک) در دسترس نیست — فقط تعداد اعضای کانال جمع‌آوری می‌شه.',
    'فروش و درآمد واقعی Etsy (تراکنش‌ها) جمع‌آوری نمی‌شه — فقط بازدید و پسندیده‌ی عمومی لیستینگ‌ها در دسترسه.',
  ];
  if((int)$totals['with_metrics'] < 5) $dataGaps[] = 'تعداد پست‌های دارای آمار کمتر از ۵ تاست — نتیجه‌گیری آماری قابل‌اتکا نیست.';

  return [
    'totals' => ['published_count'=>(int)$totals['published_count'], 'with_metrics'=>(int)$totals['with_metrics'],
      'total_reach'=>(int)$totals['total_reach'], 'total_engagement'=>(int)$totals['total_engagement']],
    'pipeline' => $pipeline,
    'platforms' => $platforms,
    'accounts' => $accounts,
    'categories' => $categories,
    'by_weekday' => $byWeekday,
    'by_hour' => $byHour,
    'top_posts' => $topPosts,
    'weak_posts' => $weakPosts,
    'posts' => array_slice($posts, 0, 80),
    'upcoming' => $upcoming,
    'data_gaps' => $dataGaps,
    'period_days' => $days,
  ];
}

// ══ ۳. تحلیل با Claude ══
function runAnalysis($triggerSource='manual'){
  $configJson = getSetting('marketing_config', '{}');
  $mcfg = json_decode($configJson, true) ?: [];
  $days = max(7, min(90, (int)($mcfg['period_days'] ?? 30)));
  $stats = buildStats($days);

  $schema = marketingPlanSchema();
  $goals = $mcfg['goals'] ?? '';
  $audience = $mcfg['audience'] ?? '';
  $budget = (int)($mcfg['monthly_ad_budget'] ?? 0);
  $maxTopics = (int)($mcfg['max_drafts_per_plan'] ?? 6);
  $existingCategories = array_column($stats['categories'], 'category');

  $systemPrompt = "نقش تو: استراتژیست ارشد مارکتینگ برای «Beguchee» — یه فروشگاه Etsy که قالب‌های دیجیتال SVG/DXF/PDF (برای برش لیزری، Cricut، Silhouette) می‌فروشه؛ محصولات دانلودی هستن، مقصد نهایی تبدیل هم خودِ صفحات لیستینگ روی Etsy‌ست (نه یه وب‌سایت جدا).\n"
    ."قواعد اجباری:\n"
    ."- هر ادعا باید به یه عدد مشخص از آمار ورودی تکیه کنه و اون عدد رو بیاره؛ هیچ عددی جعل نشه.\n"
    ."- اگه داده کم است (مثلاً کمتر از ۵ پست دارای آمار)، صریح بگو و به‌جای نتیجه‌گیری قطعی، آزمایش (experiment) طراحی کن.\n"
    ."- محتوای data_gaps ورودی رو حتماً توی warnings هم منعکس کن.\n"
    ."- مجموع content_mix باید دقیقاً ۱۰۰ باشه و فقط از دسته‌های موجود (".implode('، ', $existingCategories).") استفاده کنه؛ دسته‌ی کاملاً جدید فقط با دلیل قوی مجازه.\n"
    ."- تعداد topic_ideas حداکثر {$maxTopics} تا باشه، با عنوان‌های upcoming تکراری نباشه، دسته‌ترجیحاً از دسته‌های موجود، و weekday/time از روی by_weekday/by_hour بهترین بازه انتخاب بشه.\n"
    ."- مجموع بودجه‌ی پیشنهادی تبلیغات نباید از یک‌چهارم بودجه‌ی ماهانه (".round($budget/4)." واحد) بیشتر بشه؛ اگه بودجه صفره، فقط اقدامات رایگان (ارگانیک) پیشنهاد بده.\n"
    ."- محدودیت واقعی کانال‌های تبلیغاتی رو در نظر بگیر: حداقل بودجه‌ی روزانه‌ی Etsy Ads معمولاً حدود \$1 در روزه؛ تبلیغات Pinterest/Instagram هم حداقل بودجه‌ی مشخص خودشون رو دارن — پیشنهاد بودجه رو واقع‌بینانه بده.\n"
    ."- طبق تحقیق بازار Etsy: بیشترین خرید از جستجوی داخلی خودِ Etsy میاد، و بین شبکه‌های اجتماعی، Pinterest برای فروشنده‌های Etsy معمولاً مؤثرتر از Instagram است (ترافیک قصد-خرید بالاتر) — این اولویت رو توی content_mix و posting_schedule منعکس کن، مگر آمار واقعی چیز دیگه‌ای نشون بده.\n"
    ."- همه‌ی متن‌ها فارسی باشه.\n"
    .($goals ? "اهداف کسب‌وکار: {$goals}\n" : '')
    .($audience ? "مخاطب هدف: {$audience}\n" : '');

  $userPrompt = "این خلاصه‌ی آمار ".$days." روز اخیره:\n".json_encode($stats, JSON_UNESCAPED_UNICODE)."\n\nبر اساس این آمار، یه پلن بازاریابی کامل طبق schema بساز.";

  $result = callClaudeOpusStructured($systemPrompt, $userPrompt, $schema);
  if(!$result['ok']) throw new Exception($result['error']);

  $pdo = db();
  $stmt = $pdo->prepare('INSERT INTO marketing_plans (trigger_source, period_days, summary, plan_json, stats_json, model, created_at)
      VALUES (?,?,?,?,?,?,NOW())');
  $stmt->execute([$triggerSource, $days, $result['plan']['summary'] ?? '', json_encode($result['plan'], JSON_UNESCAPED_UNICODE),
    json_encode($stats, JSON_UNESCAPED_UNICODE), $result['model'] ?? 'claude-opus-5']);
  $planId = (int)$pdo->lastInsertId();
  setSetting('marketing_last_analysis', date('c'));
  return $planId;
}

function marketingPlanSchema(){
  $platformEnum = ['telegram','instagram','pinterest'];
  return [
    'type'=>'object','additionalProperties'=>false,
    'required'=>['summary','insights','goals_next_period','content_mix','posting_schedule','topic_ideas','ad_recommendations','experiments','warnings'],
    'properties'=>[
      'summary'=>['type'=>'string'],
      'insights'=>['type'=>'array','items'=>['type'=>'object','additionalProperties'=>false,'required'=>['title','detail','evidence'],
        'properties'=>['title'=>['type'=>'string'],'detail'=>['type'=>'string'],'evidence'=>['type'=>'string']]]],
      'goals_next_period'=>['type'=>'array','items'=>['type'=>'object','additionalProperties'=>false,'required'=>['metric','current','target'],
        'properties'=>['metric'=>['type'=>'string'],'current'=>['type'=>'string'],'target'=>['type'=>'string']]]],
      'content_mix'=>['type'=>'array','items'=>['type'=>'object','additionalProperties'=>false,'required'=>['category','share_percent','reason'],
        'properties'=>['category'=>['type'=>'string'],'share_percent'=>['type'=>'integer'],'reason'=>['type'=>'string']]]],
      'posting_schedule'=>['type'=>'array','items'=>['type'=>'object','additionalProperties'=>false,'required'=>['weekday','time','platforms','reason'],
        'properties'=>['weekday'=>['type'=>'integer'],'time'=>['type'=>'string'],'platforms'=>['type'=>'array','items'=>['type'=>'string','enum'=>$platformEnum]],'reason'=>['type'=>'string']]]],
      'topic_ideas'=>['type'=>'array','items'=>['type'=>'object','additionalProperties'=>false,'required'=>['title','category','platforms','weekday','time','goal','rationale'],
        'properties'=>['title'=>['type'=>'string'],'category'=>['type'=>'string'],'platforms'=>['type'=>'array','items'=>['type'=>'string','enum'=>$platformEnum]],
          'weekday'=>['type'=>'integer'],'time'=>['type'=>'string'],'goal'=>['type'=>'string'],'rationale'=>['type'=>'string']]]],
      'ad_recommendations'=>['type'=>'array','items'=>['type'=>'object','additionalProperties'=>false,
        'required'=>['content_id','channel','objective','audience','budget','duration_days','success_metric','rationale'],
        'properties'=>['content_id'=>['type'=>'integer'],'channel'=>['type'=>'string'],'objective'=>['type'=>'string'],'audience'=>['type'=>'string'],
          'budget'=>['type'=>'integer'],'duration_days'=>['type'=>'integer'],'success_metric'=>['type'=>'string'],'rationale'=>['type'=>'string']]]],
      'experiments'=>['type'=>'array','items'=>['type'=>'object','additionalProperties'=>false,'required'=>['hypothesis','how','metric'],
        'properties'=>['hypothesis'=>['type'=>'string'],'how'=>['type'=>'string'],'metric'=>['type'=>'string']]]],
      'warnings'=>['type'=>'array','items'=>['type'=>'string']],
    ],
  ];
}

function callClaudeOpusStructured($systemPrompt, $userPrompt, $schema, $retryNoFallback=true){
  $c = cfg();
  if(empty($c['ANTHROPIC_API_KEY'])) return ['ok'=>false, 'error'=>'ANTHROPIC_API_KEY توی config.php تنظیم نشده'];
  $model = 'claude-opus-5';
  $body = [
    'model' => $model,
    'max_tokens' => 16000,
    'system' => $systemPrompt,
    'messages' => [['role'=>'user', 'content'=>$userPrompt]],
    'thinking' => ['type'=>'adaptive'],
    'output_config' => ['effort'=>'high', 'format'=>['type'=>'json_schema', 'schema'=>$schema]],
    'fallbacks' => 'default',
  ];
  $headers = [
    'Content-Type' => 'application/json',
    'x-api-key' => $c['ANTHROPIC_API_KEY'],
    'anthropic-version' => '2023-06-01',
    'anthropic-beta' => 'server-side-fallback-2026-07-01',
  ];
  $ch = curl_init('https://api.anthropic.com/v1/messages');
  curl_setopt_array($ch, [
    CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTPHEADER=>array_map(fn($k,$v)=>"$k: $v", array_keys($headers), $headers),
    CURLOPT_POSTFIELDS=>json_encode($body),
    CURLOPT_TIMEOUT=>280,
  ]);
  $res = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if($err) return ['ok'=>false, 'error'=>'خطای curl: '.$err];
  $data = json_decode($res, true);

  // اگه 400 داد و متن خطا به fallback مربوط بود، یه‌بار بدون اون هدر/فیلد تکرار کن
  if($status===400 && $retryNoFallback && stripos($res, 'fallback')!==false){
    unset($body['fallbacks']); unset($headers['anthropic-beta']);
    return callClaudeOpusStructuredRaw($body, $headers);
  }
  if($status<200 || $status>=300) return ['ok'=>false, 'error'=>"خطای HTTP {$status} از Claude: ".substr($res,0,400)];

  $stopReason = $data['stop_reason'] ?? null;
  if($stopReason==='refusal') return ['ok'=>false, 'error'=>'مدل از پاسخ‌دادن امتناع کرد (refusal)'];
  if($stopReason==='max_tokens') return ['ok'=>false, 'error'=>'پاسخ به سقف توکن رسید و ناقص موند (max_tokens)'];

  $text = null;
  foreach(($data['content'] ?? []) as $block){
    if(($block['type'] ?? '')==='text' || isset($block['text'])){ $text = $block['text']; break; }
  }
  if(!$text) return ['ok'=>false, 'error'=>'متنی توی پاسخ Claude نبود: '.substr($res,0,300)];
  $clean = trim(preg_replace('/```json|```/', '', $text));
  $plan = json_decode($clean, true);
  if(!is_array($plan)) return ['ok'=>false, 'error'=>'پاسخ JSON قابل‌پارس نبود'];
  return ['ok'=>true, 'plan'=>$plan, 'model'=>$data['model'] ?? $model];
}

function callClaudeOpusStructuredRaw($body, $headers){
  $ch = curl_init('https://api.anthropic.com/v1/messages');
  curl_setopt_array($ch, [
    CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTPHEADER=>array_map(fn($k,$v)=>"$k: $v", array_keys($headers), $headers),
    CURLOPT_POSTFIELDS=>json_encode($body),
    CURLOPT_TIMEOUT=>280,
  ]);
  $res = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if($status<200 || $status>=300) return ['ok'=>false, 'error'=>"خطای HTTP {$status} از Claude (تلاش دوم): ".substr($res,0,400)];
  $data = json_decode($res, true);
  $text = null;
  foreach(($data['content'] ?? []) as $block){ if(isset($block['text'])){ $text=$block['text']; break; } }
  if(!$text) return ['ok'=>false, 'error'=>'متنی توی پاسخ Claude نبود (تلاش دوم)'];
  $plan = json_decode(trim(preg_replace('/```json|```/', '', $text)), true);
  if(!is_array($plan)) return ['ok'=>false, 'error'=>'پاسخ JSON قابل‌پارس نبود (تلاش دوم)'];
  return ['ok'=>true, 'plan'=>$plan, 'model'=>$data['model'] ?? 'claude-opus-5'];
}

// ══ ۴. اجرای پلن ══
function applyPlan($planId, $generateCaptions=false){
  $pdo = db();
  $stmt = $pdo->prepare('SELECT * FROM marketing_plans WHERE id=?');
  $stmt->execute([$planId]);
  $planRow = $stmt->fetch();
  if(!$planRow) throw new Exception('پلن پیدا نشد');
  if($planRow['applied_at']) throw new Exception('این پلن قبلاً اجرا شده');

  $plan = json_decode($planRow['plan_json'], true);
  $topics = $plan['topic_ideas'] ?? [];

  $existingTitles = array_map(fn($t)=>mb_strtolower(trim($t)), $pdo->query("SELECT title FROM content_items")->fetchAll(PDO::FETCH_COLUMN));

  $created = 0;
  foreach($topics as $topic){
    $normTitle = mb_strtolower(trim($topic['title'] ?? ''));
    if(!$normTitle || in_array($normTitle, $existingTitles)) continue;

    $scheduledAt = nextDateForWeekday((int)($topic['weekday'] ?? 1), $topic['time'] ?? '10:00');

    $ins = $pdo->prepare("INSERT INTO content_items (title, tag, source_content, status, origin, plan_id, scheduled_at)
        VALUES (?,?,?,'draft','marketing',?,?)");
    $ins->execute([$topic['title'], $topic['category'] ?? '', $topic['rationale'] ?? $topic['goal'] ?? '', $planId, $scheduledAt]);
    $newId = (int)$pdo->lastInsertId();

    foreach(($topic['platforms'] ?? []) as $platform){
      $pdo->prepare("INSERT IGNORE INTO content_platforms (content_id, platform, status) VALUES (?,?,'pending')")
          ->execute([$newId, $platform]);
    }
    $existingTitles[] = $normTitle;
    $created++;

    if($generateCaptions){
      try{ generateCaptionForItem($newId); }catch(Throwable $e){ /* خطای تولید کپشن این آیتم رو متوقف نمی‌کنه، پیش‌نویس می‌مونه */ }
    }
  }

  $pdo->prepare('UPDATE marketing_plans SET applied_at=NOW(), drafts_created=? WHERE id=?')->execute([$created, $planId]);
  return $created;
}

// نزدیک‌ترین تاریخ آینده (۱ تا ۷ روز بعد) با همون روز هفته‌ی ISO (۱=دوشنبه..۷=یکشنبه) و ساعت مشخص
function nextDateForWeekday($isoWeekday, $time){
  $isoWeekday = max(1, min(7, $isoWeekday));
  $time = preg_match('/^\d{1,2}:\d{2}$/', $time) ? $time : '10:00';
  $today = new DateTime('now');
  $todayIso = (int)$today->format('N');
  $diff = $isoWeekday - $todayIso;
  if($diff <= 0) $diff += 7; // همیشه آینده، نه امروز
  $target = (clone $today)->modify("+{$diff} days");
  return $target->format('Y-m-d').' '.$time.':00';
}
