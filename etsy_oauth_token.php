<?php
// APP_VERSION: 1.0.2
// etsy_oauth_token.php — تبادل سمت‌سرور کد OAuth با access token
//
// دلیل وجودش: endpoint توکن اتسی (api.etsy.com/v3/public/oauth/token) هدر CORS
// لازم برای فراخوانی مستقیم از جاوااسکریپت مرورگر رو نمی‌فرسته، پس این تبادل
// باید سمت سرور انجام بشه. این فایل رو کنار index.html روی همین دامنه آپلود کن.

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

$body = http_build_query([
    'grant_type'    => 'authorization_code',
    'client_id'     => $input['client_id'],
    'redirect_uri'  => $input['redirect_uri'],
    'code'          => $input['code'],
    'code_verifier' => $input['code_verifier'],
]);

$ch = curl_init('https://api.etsy.com/v3/public/oauth/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
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
        'error'  => 'upstream_empty_response',
        'detail' => $curlErr ?: 'Etsy returned an empty body',
        'http_code' => $httpCode,
    ]);
    exit;
}

http_response_code($httpCode ?: 502);
echo $response;
