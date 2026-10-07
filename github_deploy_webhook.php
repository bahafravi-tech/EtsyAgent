<?php
// APP_VERSION: 1.1.0
// github_deploy_webhook.php — با هر push روی main، خودکار "Update from Remote" + "Deploy HEAD Commit"
// رو از طریق cPanel UAPI اجرا می‌کنه (ماژول‌های VersionControl و VersionControlDeployment)، بدون نیاز به باز کردن cPanel.
//
// راه‌اندازی:
// ۱. deploy_webhook_config.php رو از روی deploy_webhook_config.example.php بساز و پر کن (فقط روی سرور، توی گیت نیست).
// ۲. توی گیت‌هاب: Settings → Webhooks → Add webhook
//    Payload URL: https://etsyagent.afravi.com/github_deploy_webhook.php
//    Content type: application/json
//    Secret: دقیقاً همون GITHUB_WEBHOOK_SECRET
//    Events: فقط "push"
// نتیجه‌ی هر اجرا توی deploy_webhook.log (کنار همین فایل) ثبت می‌شه — برای عیب‌یابی.

header('Content-Type: application/json; charset=utf-8');

function deployLog($line){
    @file_put_contents(__DIR__ . '/deploy_webhook.log', '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND);
}

$configFile = __DIR__ . '/deploy_webhook_config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'missing_config', 'detail' => 'deploy_webhook_config.php را بساز']);
    exit;
}
require $configFile;

$rawBody = file_get_contents('php://input');

$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $rawBody, GITHUB_WEBHOOK_SECRET);
if (!$signature || !hash_equals($expected, $signature)) {
    deployLog('REJECTED invalid_signature (secret توی گیت‌هاب با GITHUB_WEBHOOK_SECRET این فایل یکی نیست)');
    http_response_code(401);
    echo json_encode(['error' => 'invalid_signature']);
    exit;
}

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event === 'ping') {
    deployLog('PING ok — وبهوک درست وصله');
    echo json_encode(['pong' => true]);
    exit;
}
if ($event !== 'push') {
    echo json_encode(['skipped' => 'not_a_push_event', 'event' => $event]);
    exit;
}

$payload = json_decode($rawBody, true) ?: [];
$ref = $payload['ref'] ?? '';
if ($ref !== 'refs/heads/main') {
    echo json_encode(['skipped' => 'not_main_branch', 'ref' => $ref]);
    exit;
}

// تماس با cPanel UAPI: https://host:2083/execute/<Module>/<function>?params
function cpanelUapi($module, $function, $params)
{
    $url = rtrim(CPANEL_HOST, '/') . '/execute/' . $module . '/' . $function . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: cpanel ' . CPANEL_USERNAME . ':' . CPANEL_API_TOKEN],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $json = json_decode((string)$body, true);
    return [
        'hint'      => in_array($httpCode, [401, 403], true)
            ? 'احراز هویت cPanel رد شد — CPANEL_API_TOKEN و CPANEL_USERNAME را بررسی کن و CPANEL_HOST را روی نام سرور (مثل https://cp91.hostmihan.com:2083) بگذار، نه دامنه‌ی سایت'
            : null,
        'http_code' => $httpCode,
        'ok'        => is_array($json) && ($json['status'] ?? 0) == 1,
        'errors'    => is_array($json) ? ($json['errors'] ?? null) : null,
        'raw'       => is_array($json) ? null : substr((string)$body, 0, 300),
        'curl_error'=> $err,
    ];
}

// ۱) Update from Remote (git pull روی شاخه‌ی main)
$update = cpanelUapi('VersionControl', 'update', ['repository_root' => CPANEL_REPO_ROOT, 'branch' => 'main']);
// ۲) Deploy HEAD Commit (اجرای .cpanel.yml) — فقط اگه مرحله‌ی اول جواب داد
$deploy = $update['ok'] ? cpanelUapi('VersionControlDeployment', 'create', ['repository_root' => CPANEL_REPO_ROOT]) : null;

$summary = 'update=' . ($update['ok'] ? 'OK' : 'FAIL(' . $update['http_code'] . ')')
         . ' deploy=' . ($deploy ? ($deploy['ok'] ? 'QUEUED' : 'FAIL(' . $deploy['http_code'] . ')') : 'SKIPPED');
deployLog($summary . ' ' . json_encode(['update' => $update, 'deploy' => $deploy], JSON_UNESCAPED_UNICODE));

http_response_code(($update['ok'] && $deploy && $deploy['ok']) ? 200 : 502);
echo json_encode(['update' => $update, 'deploy' => $deploy], JSON_UNESCAPED_UNICODE);
