<?php
// APP_VERSION: 1.0.7
// github_deploy_webhook.php — با هر پوش به گیت‌هاب، خودکار "Update from Remote" +
// "Deploy HEAD Commit" رو از طریق cPanel UAPI صدا می‌زنه، بدون نیاز به باز کردن cPanel.
//
// راه‌اندازی:
// ۱. deploy_webhook_config.php رو از روی deploy_webhook_config.example.php بساز و پر کن.
// ۲. توی گیت‌هاب: Settings → Webhooks → Add webhook
//    Payload URL: https://etsyagent.afravi.com/github_deploy_webhook.php
//    Content type: application/json
//    Secret: همون GITHUB_WEBHOOK_SECRET
//    Events: فقط "push"

header('Content-Type: application/json; charset=utf-8');

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
    http_response_code(401);
    echo json_encode(['error' => 'invalid_signature']);
    exit;
}

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event !== 'push') {
    http_response_code(200);
    echo json_encode(['skipped' => 'not_a_push_event', 'event' => $event]);
    exit;
}

$payload = json_decode($rawBody, true) ?: [];
$ref = $payload['ref'] ?? '';
if ($ref !== 'refs/heads/main') {
    http_response_code(200);
    echo json_encode(['skipped' => 'not_main_branch', 'ref' => $ref]);
    exit;
}

function cpanelUapi($endpoint, $repoRoot)
{
    $url = rtrim(CPANEL_HOST, '/') . '/execute/Git/' . $endpoint . '?repository_root=' . urlencode($repoRoot);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: cpanel ' . CPANEL_USERNAME . ':' . CPANEL_API_TOKEN],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['http_code' => $httpCode, 'body' => $body, 'curl_error' => $err];
}

$update = cpanelUapi('update', CPANEL_REPO_ROOT);
$deploy = cpanelUapi('deploy', CPANEL_REPO_ROOT);

http_response_code(200);
echo json_encode(['update' => $update, 'deploy' => $deploy]);
