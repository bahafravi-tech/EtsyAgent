<?php
// api/design_ai.php — فراخوانی متنیِ Claude (Opus/Sonnet) با کلید سرور؛ برای «🧰 طراحی با Claude» در استودیو محصولات
// ورودی (POST JSON): {prompt, max_tokens?, model?: "opus"|"sonnet"} — خروجی: {ok:true, text, model}

// خطای مرگبار PHP (timeout / حافظه / syntax) به‌جای صفحه‌ی خالی ۵۰۰، به‌صورت JSON با متن خطا برمی‌گرده
register_shutdown_function(function(){
  $e = error_get_last();
  if($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)){
    if(!headers_sent()){ http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['ok'=>false, 'error'=>'خطای PHP روی سرور: '.$e['message'].' ('.basename($e['file']).':'.$e['line'].')'], JSON_UNESCAPED_UNICODE);
  }
});

require __DIR__.'/../db.php';
require __DIR__.'/../lib_marketing.php'; // claudeStructuredRequest / claudeFriendlyError / claudeKeepAlive / marketingModelId
allowCors();
requireAuthOrAgentSecret();
@set_time_limit(300);

if(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') jsonResponse(['ok'=>false, 'error'=>'method_not_allowed'], 405);

$in = jsonInput();
$prompt = trim((string)($in['prompt'] ?? ''));
if($prompt === '' || strlen($prompt) > 60000) jsonResponse(['ok'=>false, 'error'=>'prompt خالی یا بیش از حد بلند است'], 400);
$modelKey = (($in['model'] ?? 'opus') === 'sonnet') ? 'sonnet' : 'opus';
$maxTokens = max(8000, min(16000, (int)($in['max_tokens'] ?? 12000)));

// تصویرهای ورودی (مثلاً یک قالب نمونه) برای تحلیل بصری
$images = [];
foreach((array)($in['images'] ?? []) as $im){
  $mt = is_array($im) ? ($im['media_type'] ?? '') : ''; $dt = is_array($im) ? ($im['data'] ?? '') : '';
  if(in_array($mt, ['image/jpeg','image/png','image/webp'], true) && is_string($dt) && strlen($dt) < 6000000 && count($images) < 3){
    $images[] = ['type'=>'image', 'source'=>['type'=>'base64', 'media_type'=>$mt, 'data'=>$dt]];
  }
}
$userContent = $images ? array_merge($images, [['type'=>'text', 'text'=>$prompt]]) : $prompt;

$apiKey = anthropicApiKey();
if($apiKey === '') jsonResponse(['ok'=>false, 'error'=>'ANTHROPIC_API_KEY توی config.php تنظیم نشده'], 500);

$model = marketingModelId($modelKey);
$isOpus = strpos($model, 'opus') !== false;
$body = [
  'model' => $model,
  'max_tokens' => $maxTokens,
  'messages' => [['role'=>'user', 'content'=>$userContent]],
  'thinking' => ['type'=>'adaptive'],
  'output_config' => ['effort'=>$isOpus ? 'high' : 'medium'],
];
$headers = [
  'Content-Type' => 'application/json',
  'x-api-key' => $apiKey,
  'anthropic-version' => '2023-06-01',
];
if($isOpus){ // fallback سمت سرور فقط برای Opus (همان الگوی تحلیل بازاریابی)
  $body['fallbacks'] = 'default';
  $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
}

$r = claudeStructuredRequest($body, $headers);
// ۴۰۰ (غیر از مشکل اعتبار): یک‌بار با درخواست ساده‌تر — بدون fallbacks/thinking/effort
if($r['status'] === 400 && stripos($r['raw'], 'credit balance') === false){
  unset($body['fallbacks'], $body['thinking'], $body['output_config'], $headers['anthropic-beta']);
  $r = claudeStructuredRequest($body, $headers);
}
if($r['curl_error']) jsonResponse(['ok'=>false, 'error'=>'خطای curl: '.$r['curl_error']], 502);
if($r['status'] < 200 || $r['status'] >= 300) jsonResponse(['ok'=>false, 'error'=>claudeFriendlyError($r['status'], $r['raw'])], 502);

$data = json_decode($r['raw'], true);
$stop = $data['stop_reason'] ?? null;
if($stop === 'refusal') jsonResponse(['ok'=>false, 'error'=>'مدل از پاسخ‌دادن امتناع کرد (refusal)'], 502);
if($stop === 'max_tokens') jsonResponse(['ok'=>false, 'error'=>'پاسخ به سقف توکن رسید و ناقص موند — دوباره امتحان کن'], 502);

$text = '';
foreach(($data['content'] ?? []) as $block){
  if(($block['type'] ?? '') === 'text' && isset($block['text'])) $text .= $block['text'];
}
if(trim($text) === '') jsonResponse(['ok'=>false, 'error'=>'متنی توی پاسخ Claude نبود'], 502);
jsonResponse(['ok'=>true, 'text'=>$text, 'model'=>$data['model'] ?? $model]);
