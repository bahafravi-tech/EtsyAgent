<?php
// api/marketing.php — endpoint واحد سیستم «بازاریابی خودکار» (فقط پنل، با نشست کوکی)

require __DIR__.'/../db.php';
require __DIR__.'/../lib_marketing.php';
require __DIR__.'/../lib_generate.php'; // برای generateCaptionForItem که applyPlan صداش می‌زنه
allowCors();
requireAuthOrAgentSecret(); // هم از پنل (کوکی نشست) هم مستقیم از خودِ Etsy Agent Pro (هدر X-Agent-Secret) صدا زده می‌شه
@set_time_limit(300);

// اگه جدول‌های مارکتینگ هنوز ساخته نشدن، پیام واضح بده نه خطای خام SQL
function ensureMarketingTables(){
  try{
    db()->query('SELECT 1 FROM marketing_plans LIMIT 1');
  }catch(PDOException $e){
    jsonResponse(['error'=>'جدول‌های مارکتینگ هنوز ساخته نشدن — schema_marketing.sql را از phpMyAdmin اجرا کن'], 500);
  }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if($method === 'GET'){
  ensureMarketingTables();
  $action = $_GET['action'] ?? '';
  $pdo = db();
  switch($action){
    case 'config':
      $cfgJson = getSetting('marketing_config', '{}');
      jsonResponse(['config'=>json_decode($cfgJson, true) ?: []]);
      break;
    case 'stats':
      $days = max(7, min(90, (int)($_GET['days'] ?? 30)));
      jsonResponse(['stats'=>buildStats($days)]);
      break;
    case 'latest_plan':
      $row = $pdo->query('SELECT * FROM marketing_plans ORDER BY id DESC LIMIT 1')->fetch();
      if(!$row){ jsonResponse(['plan'=>null]); break; }
      jsonResponse(['plan'=>formatPlanRow($row)]);
      break;
    case 'trend':
      jsonResponse(['trend'=>marketingPlanTrend()]);
      break;
    case 'plans':
      $rows = $pdo->query('SELECT id, trigger_source, period_days, summary, drafts_created, applied_at, created_at FROM marketing_plans ORDER BY id DESC LIMIT 12')->fetchAll();
      jsonResponse(['plans'=>$rows]);
      break;
    case 'last_collect':
      jsonResponse(['last_collect'=>getSetting('marketing_last_collect')]);
      break;
    case 'last_analysis':
      jsonResponse(['last_analysis'=>getSetting('marketing_last_analysis')]);
      break;
    default:
      jsonResponse(['error'=>'action نامعتبر'], 400);
  }
  exit;
}

if($method === 'POST'){
  ensureMarketingTables();
  $input = jsonInput();
  $action = $input['action'] ?? '';
  $pdo = db();
  switch($action){
    case 'save_config':
      $editable = ['auto_enabled','analyze_weekday','auto_create_drafts','auto_generate_captions',
        'max_drafts_per_plan','period_days','monthly_ad_budget','goals','audience','analysis_model'];
      $current = json_decode(getSetting('marketing_config', '{}'), true) ?: [];
      foreach($editable as $k){ if(array_key_exists($k, $input)) $current[$k] = $input[$k]; }
      setSetting('marketing_config', json_encode($current, JSON_UNESCAPED_UNICODE));
      jsonResponse(['ok'=>true]);
      break;

    case 'collect':
      try{
        $log = collectMetrics();
        jsonResponse(['ok'=>true, 'log'=>$log]);
      }catch(Throwable $e){
        jsonResponse(['ok'=>false, 'error'=>$e->getMessage()], 500);
      }
      break;

    // آمار لیستینگ‌های Etsy که از مرورگر کاربر (با OAuth موجود) گرفته شده —
    // چون مسیر بدون‌OAuth سمت سرور رو Etsy مسدود می‌کنه (۴۰۳ ضدربات)
    case 'submit_etsy_metrics':
      try{
        $listings = $input['listings'] ?? [];
        if(!is_array($listings)) jsonResponse(['ok'=>false, 'error'=>'listings نامعتبر'], 400);
        $result = submitEtsyListingMetrics($listings);
        setSetting('marketing_last_collect', date('c'));
        jsonResponse(['ok'=>true] + $result);
      }catch(Throwable $e){
        jsonResponse(['ok'=>false, 'error'=>$e->getMessage()], 500);
      }
      break;

    case 'analyze':
      try{
        $planId = runAnalysis('manual');
        jsonResponse(['ok'=>true, 'plan_id'=>$planId]);
      }catch(Throwable $e){
        jsonResponse(['ok'=>false, 'error'=>$e->getMessage()], 500);
      }
      break;

    case 'apply':
      try{
        $planId = (int)($input['plan_id'] ?? 0);
        if(!$planId) jsonResponse(['ok'=>false, 'error'=>'plan_id لازم است'], 400);
        $created = applyPlan($planId, !empty($input['generate_captions']));
        jsonResponse(['ok'=>true, 'drafts_created'=>$created]);
      }catch(Throwable $e){
        jsonResponse(['ok'=>false, 'error'=>$e->getMessage()], 500);
      }
      break;

    case 'save_ad_result':
      try{
        marketingSaveAdResult((int)($input['plan_id'] ?? 0), (int)($input['ad_index'] ?? -1), (string)($input['status'] ?? ''),
          $input['spent_usd'] ?? 0, (string)($input['note'] ?? ''));
        jsonResponse(['ok'=>true]);
      }catch(Throwable $e){
        jsonResponse(['ok'=>false, 'error'=>$e->getMessage()], 400);
      }
      break;

    case 'get_plan':
      $planId = (int)($input['plan_id'] ?? 0);
      $stmt = $pdo->prepare('SELECT * FROM marketing_plans WHERE id=?');
      $stmt->execute([$planId]);
      $row = $stmt->fetch();
      if(!$row) jsonResponse(['error'=>'پلن پیدا نشد'], 404);
      jsonResponse(['plan'=>formatPlanRow($row)]);
      break;

    default:
      jsonResponse(['error'=>'action نامعتبر'], 400);
  }
  exit;
}

jsonResponse(['error'=>'method_not_allowed'], 405);

function formatPlanRow($row){
  return [
    'id'=>(int)$row['id'], 'trigger_source'=>$row['trigger_source'], 'period_days'=>(int)$row['period_days'],
    'summary'=>$row['summary'], 'plan'=>json_decode($row['plan_json'], true),
    'stats'=>json_decode($row['stats_json'], true), 'model'=>$row['model'],
    'drafts_created'=>(int)$row['drafts_created'], 'applied_at'=>$row['applied_at'], 'created_at'=>$row['created_at'],
    'ad_results'=>(object)marketingAdResults((int)$row['id']),
  ];
}
