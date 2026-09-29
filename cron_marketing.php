<?php
// cron_marketing.php — این فایل رو با یه Cron Job روزانه اجرا کن (مثلاً هر روز ساعت ۳ بامداد):
// php /home/USERNAME/etsyagent.afravi.com/cron_marketing.php
// (از cPanel > Cron Jobs — با همین دستور، نه لینک/URL. فقط از CLI اجرا می‌شه.)

if(php_sapi_name() !== 'cli'){
  http_response_code(403);
  die('این اسکریپت فقط از CLI قابل اجراست.');
}

require __DIR__.'/lib_marketing.php';

echo collectMetrics()."\n";

$mcfg = json_decode(getSetting('marketing_config', '{}'), true) ?: [];
if(empty($mcfg['auto_enabled'])){
  echo "حالت خودکار خاموش است — تحلیل انجام نشد.\n";
  exit;
}

$pdo = db();
$lastPlan = $pdo->query('SELECT created_at FROM marketing_plans ORDER BY id DESC LIMIT 1')->fetch();
$daysSinceLastPlan = $lastPlan ? (time() - strtotime($lastPlan['created_at'])) / 86400 : 999;
$todayIso = (int)(new DateTime('now'))->format('N');
$analyzeWeekday = (int)($mcfg['analyze_weekday'] ?? 1);

$shouldAnalyze = ($daysSinceLastPlan > 8) || ($todayIso === $analyzeWeekday && $daysSinceLastPlan >= 6);

if(!$shouldAnalyze){
  echo "امروز نوبت تحلیل نیست (آخرین پلن ".round($daysSinceLastPlan,1)." روز پیش).\n";
  exit;
}

try{
  echo "شروع تحلیل...\n";
  $planId = runAnalysis('cron');
  echo "پلن #{$planId} ساخته شد.\n";
  if(!empty($mcfg['auto_create_drafts'])){
    require_once __DIR__.'/lib_generate.php';
    $created = applyPlan($planId, !empty($mcfg['auto_generate_captions']));
    echo "{$created} پیش‌نویس ساخته شد.\n";
  }
}catch(Throwable $e){
  echo "خطا: ".$e->getMessage()."\n";
}
