<?php
// APP_VERSION: 1.0.6
// etsy_oauth_token.php — تبادل سمت‌سرور کد OAuth با access token
//
// دلیل وجودش: ۱) endpoint توکن اتسی (api.etsy.com/v3/public/oauth/token) هدر CORS
// لازم برای فراخوانی مستقیم از جاوااسکریپت مرورگر رو نمی‌فرسته. ۲) خودِ اتسی
// درخواست‌های سمت‌سرور از IP هاست‌های ایرانی رو به‌خاطر تحریم بلاک می‌کنه (صفحه‌ی
// captcha-delivery با referer به سیاست تحریم اتسی) — پس این درخواست باید از طریق
// رله‌ی Cloudflare Worker (همون relay-worker.js) عبور کنه، نه مستقیم.
//
// پیش‌نیاز: فایل etsy_oauth_config.php رو کنار همین فایل بساز (از روی
// etsy_oauth_config.example.php) و RELAY_URL/RELAY_SECRET واقعی رو توش بذار.

header('Content-Type: application/json; charset=utf-8');

// اجازه‌ی فراخوانی از همین دامنه (برای fetch از index.html)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$required = ['client_id', 'redirect_uri', 'code', 'code_verifier'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['error' => 'missing_field', 'field' => $field]);
        exit;
    }
}

$configFile = __DIR__ . '/etsy_oauth_config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'missing_relay_config', 'detail' => 'etsy_oauth_config.php را از روی etsy_oauth_config.example.php بساز']);
    exit;
}
require $configFile;

$etsyBody = http_build_query([
    'grant_type'    => 'authorization_code',
    'client_id'     => $input['client_id'],
    'redirect_uri'  => $input['redirect_uri'],
    'code'          => $input['code'],
    'code_verifier' => $input['code_verifier'],
]);

// به‌جای تماس مستقیم (که اتسی به‌خاطر تحریم IP هاست رو بلاک می‌کنه)، از رله عبور می‌کنیم
$relayPayload = json_encode([
    'url'     => 'https://api.etsy.com/v3/public/oauth/token',
    'method'  => 'POST',
    'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
    'body'    => $etsyBody,
]);

$ch = curl_init(RELAY_URL);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $relayPayload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-Relay-Secret: ' . RELAY_SECRET,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false || $response === '') {
    http_response_code(502);
    echo json_encode([
        'error'     => 'relay_empty_response',
        'detail'    => $curlErr ?: 'رله جواب خالی برگردوند',
        'http_code' => $httpCode,
    ]);
    exit;
}

http_response_code($httpCode ?: 502);
echo $response;
