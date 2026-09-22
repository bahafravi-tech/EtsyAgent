<?php
require __DIR__.'/../db.php';
allowCors();
requireAuthOrAgentSecret();

$input = jsonInput();
$id = intval($input['id'] ?? 0);
if(!$id) jsonResponse(['error'=>'id لازم است'], 400);
$pdo = db();

$fields = [];
$params = [];
foreach(['caption','hashtags','title'] as $f){
  if(isset($input[$f])){ $fields[] = "$f=?"; $params[] = $input[$f]; }
}
if(array_key_exists('scheduled_at', $input)){
  $raw = trim((string)($input['scheduled_at'] ?? ''));
  if($raw === ''){
    $fields[] = 'scheduled_at=NULL';
  }else{
    $normalized = str_replace('T', ' ', $raw);
    if(strlen($normalized) === 16) $normalized .= ':00';
    $fields[] = 'scheduled_at=?';
    $params[] = $normalized;
  }
}
if($fields){
  $params[] = $id;
  $pdo->prepare('UPDATE content_items SET '.implode(',', $fields).', updated_at=NOW() WHERE id=?')->execute($params);
}

if(isset($input['status'])){
  $newStatus = $input['status'];
  $allowed = ['draft','generated','review','approved','published','rejected','failed'];
  if(!in_array($newStatus, $allowed, true)) jsonResponse(['error'=>'status نامعتبر است'], 400);

  $pdo->prepare('UPDATE content_items SET status=?, updated_at=NOW() WHERE id=?')->execute([$newStatus, $id]);

  if($newStatus === 'approved'){
    // اگه لیست پلتفرم مشخص نشده بود، پیش‌فرض هر ۳ تا (رفتار قبلی)
    $platforms = $input['platforms'] ?? ['telegram','instagram','pinterest'];
    foreach($platforms as $p){
      $pdo->prepare('INSERT INTO content_platforms (content_id, platform, status) VALUES (?,?,"pending") ON DUPLICATE KEY UPDATE status="pending", error_text=NULL')
          ->execute([$id, $p]);
    }
  }
}

// انتخاب/تغییر پلتفرم‌های هدف مستقل از تغییر status (وقتی کاربر چک‌باکس‌ها رو عوض می‌کنه)
if(isset($input['platforms']) && !isset($input['status'])){
  $platforms = $input['platforms'];
  foreach($platforms as $p){
    $pdo->prepare('INSERT INTO content_platforms (content_id, platform, status) VALUES (?,?,"pending") ON DUPLICATE KEY UPDATE error_text=error_text')
        ->execute([$id, $p]);
  }
  // پلتفرم‌هایی که از لیست برداشته شدن رو فقط اگه هنوز pending بودن (منتشرنشده) حذف کن — تاریخچه‌ی منتشرشده/ناموفق دست‌نخورده می‌مونه
  $placeholders = $platforms ? implode(',', array_fill(0, count($platforms), '?')) : "''";
  $delParams = array_merge([$id], $platforms);
  $pdo->prepare("DELETE FROM content_platforms WHERE content_id=? AND status='pending' AND platform NOT IN ($placeholders)")
      ->execute($delParams);
}

jsonResponse(['ok'=>true]);
