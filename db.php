<?php
// db.php — اتصال دیتابیس + توابع مشترک

function cfg(){
  static $c = null;
  if($c === null){
    $path = __DIR__.'/config.php';
    if(!file_exists($path)){
      http_response_code(500);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['error'=>'config.php پیدا نشد — از config.example.php کپی کن و پرش کن']);
      exit;
    }
    $c = require $path;
  }
  return $c;
}

function db(){
  static $pdo = null;
  if($pdo === null){
    $c = cfg();
    $dsn = "mysql:host={$c['DB_HOST']};dbname={$c['DB_NAME']};charset=utf8mb4";
    $pdo = new PDO($dsn, $c['DB_USER'], $c['DB_PASS'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  }
  return $pdo;
}

function jsonResponse($data, $code=200){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function jsonInput(){
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

// اجازه‌ی فراخوانی از دامنه‌ی دیگه (فایل Etsy Agent ممکنه روی یه دامنه‌ی دیگه یا حتی file:// باز باشه)
function allowCors(){
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, X-Agent-Secret');
  if(($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS'){
    http_response_code(204);
    exit;
  }
}

// درخواست‌هایی که از فایل Etsy Agent (مرورگر) میان، با هدر X-Agent-Secret تأیید می‌شن
function requireAgentSecret(){
  $c = cfg();
  $given = $_SERVER['HTTP_X_AGENT_SECRET'] ?? '';
  if(!$c['AGENT_SECRET'] || !hash_equals((string)$c['AGENT_SECRET'], (string)$given)){
    jsonResponse(['error'=>'unauthorized'], 401);
  }
}

// نشست ۳۰ روزه‌ی پنل — هم کوکی هم gc_maxlifetime باید تنظیم بشن
function startAgentSession(){
  $days = 30;
  if(session_status() === PHP_SESSION_NONE){
    ini_set('session.gc_maxlifetime', (string)($days*86400));
    session_set_cookie_params([
      'lifetime' => $days*86400,
      'path' => '/',
      'secure' => true,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
    session_name('beguchee_agent_session');
    session_start();
  }
}

function requireAuth(){
  startAgentSession();
  if(empty($_SESSION['logged_in'])){
    jsonResponse(['error'=>'not_authenticated'], 401);
  }
}

// برای endpointهایی که هم پنل مستقل (با کوکی نشست) و هم خودِ فایل Etsy Agent
// (با هدر X-Agent-Secret، بدون نیاز به لاگین جدا) باید بتونن صداشون بزنن
function requireAuthOrAgentSecret(){
  startAgentSession();
  if(!empty($_SESSION['logged_in'])) return;
  $c = cfg();
  $given = $_SERVER['HTTP_X_AGENT_SECRET'] ?? '';
  if(!empty($c['AGENT_SECRET']) && hash_equals((string)$c['AGENT_SECRET'], (string)$given)) return;
  jsonResponse(['error'=>'unauthorized'], 401);
}

function getSetting($key, $default=null){
  $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');
  $stmt->execute([$key]);
  $row = $stmt->fetch();
  return $row ? $row['setting_value'] : $default;
}

function setSetting($key, $value){
  $stmt = db()->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
  $stmt->execute([$key, $value]);
}

// ---- HTTP fetch مستقیم (برای دامنه‌هایی که فیلتر نیستن، مثل api.anthropic.com) ----
// تبدیل هر عکسی (از هر فرمتی) به JPEG واقعی، با محدودکردن ابعاد به حداکثر ۱۰۸۰ پیکسل —
// هم برای اینستاگرام لازمه (فقط JPEG قبول می‌کنه)، هم حجم فایل رو برای پست سریع‌تر پایین نگه می‌داره
function resizeAndSaveJpeg($bytes, $destPath, $maxDim=1080, $quality=82){
  if(!function_exists('imagecreatefromstring')){
    file_put_contents($destPath, $bytes);
    return false;
  }
  $img = @imagecreatefromstring($bytes);
  if(!$img){
    file_put_contents($destPath, $bytes);
    return false;
  }
  $w = imagesx($img); $h = imagesy($img);
  $scale = min(1, $maxDim / max($w, $h)); // فقط کوچیک می‌کنیم، هیچ‌وقت بزرگ نمی‌کنیم
  $nw = max(1, (int)round($w*$scale));
  $nh = max(1, (int)round($h*$scale));
  $dst = imagecreatetruecolor($nw, $nh);
  imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // پس‌زمینه‌ی سفید برای شفافیت PNG
  imagecopyresampled($dst, $img, 0,0,0,0, $nw,$nh, $w,$h);
  $ok = imagejpeg($dst, $destPath, $quality);
  imagedestroy($img);
  imagedestroy($dst);
  if(!$ok) file_put_contents($destPath, $bytes);
  return $ok;
}

function directFetch($url, $method='GET', $headers=[], $body=null){
  $ch = curl_init($url);
  $hdrs = [];
  foreach($headers as $k=>$v) $hdrs[] = "$k: $v";
  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $hdrs,
    CURLOPT_TIMEOUT => 60,
  ];
  if($body !== null) $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body);
  curl_setopt_array($ch, $opts);
  $res = curl_exec($ch);
  $err = curl_error($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if($err) return ['ok'=>false, 'status'=>0, 'body'=>$err];
  return ['ok'=>$status>=200 && $status<300, 'status'=>$status, 'body'=>$res];
}

// ---- رله‌ی Cloudflare Worker — برای دامنه‌های فیلترشده از ایران (facebook/pinterest/gemini/telegram) ----
// همون الگوی فی‌چاپ: به‌جای تماس مستقیم، یه POST با {url, method, headers, body} به Worker می‌فرستیم.
function relayFetch($url, $method='GET', $headers=[], $body=null){
  $relayUrl = getSetting('RELAY_URL', cfg()['RELAY_URL'] ?? '');
  $relaySecret = getSetting('RELAY_SECRET', cfg()['RELAY_SECRET'] ?? '');
  if(!$relayUrl){
    // اگه رله تنظیم نشده، مستقیم امتحان کن (شاید فیلتر نباشه یا هاست خارج از ایرانه)
    return directFetch($url, $method, $headers, $body);
  }
  $payload = json_encode(['url'=>$url, 'method'=>$method, 'headers'=>$headers, 'body'=>$body]);
  $ch = curl_init($relayUrl);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Relay-Secret: '.$relaySecret],
    CURLOPT_TIMEOUT => 60,
  ]);
  $res = curl_exec($ch);
  $err = curl_error($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if($err) return ['ok'=>false, 'status'=>0, 'body'=>$err];
  return ['ok'=>$status>=200 && $status<300, 'status'=>$status, 'body'=>$res];
}
