<?php
require __DIR__.'/../db.php';
allowCors();
requireAuthOrAgentSecret();

$input = jsonInput();
$id = intval($input['id'] ?? 0);
$platform = $input['platform'] ?? '';
if(!$id || !$platform) jsonResponse(['error'=>'id و platform لازم است'], 400);

$pdo = db();
$pdo->prepare('UPDATE content_platforms SET status="pending", error_text=NULL WHERE content_id=? AND platform=?')
    ->execute([$id, $platform]);
// اگه آیتم قبلاً published شده بود (چون یه پلتفرم دیگه موفق بود) برش‌گردون به approved تا این پلتفرم هم دوباره امتحان بشه
$pdo->prepare("UPDATE content_items SET status='approved' WHERE id=? AND status='published'")->execute([$id]);

jsonResponse(['ok'=>true]);
