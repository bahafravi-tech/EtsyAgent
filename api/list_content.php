<?php
require __DIR__.'/../db.php';
allowCors();
requireAuthOrAgentSecret();

$status = $_GET['status'] ?? null;
$shop = $_GET['shop'] ?? null;
$category = $_GET['category'] ?? null;
$pdo = db();

$where = [];
$params = [];
if($status){ $where[] = 'status=?'; $params[] = $status; }
if($shop){ $where[] = 'shop=?'; $params[] = $shop; }
if($category){ $where[] = 'tag=?'; $params[] = $category; }
$sql = 'SELECT * FROM content_items';
if($where) $sql .= ' WHERE '.implode(' AND ', $where);
$sql .= ' ORDER BY created_at DESC LIMIT 500';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

$pstmt = $pdo->prepare('SELECT platform, status, published_at, error_text FROM content_platforms WHERE content_id=?');
foreach($items as &$item){
  $pstmt->execute([$item['id']]);
  $item['platforms'] = $pstmt->fetchAll();
  $item['research'] = $item['research_json'] ? json_decode($item['research_json'], true) : null;
  unset($item['research_json']);
  $images = ($item['images_json'] ?? null) ? json_decode($item['images_json'], true) : null;
  if(!is_array($images) || !$images) $images = $item['hero_image_path'] ? [$item['hero_image_path']] : [];
  $item['images'] = $images;
  unset($item['images_json']);
}
unset($item);

// لیست فروشگاه‌ها و دسته‌های متمایزی که تا حالا محتوا براشون ساخته شده — برای پر کردن فیلترهای UI
$shopsStmt = $pdo->query("SELECT DISTINCT shop FROM content_items WHERE shop!='' ORDER BY shop");
$shops = array_column($shopsStmt->fetchAll(), 'shop');
$catsStmt = $pdo->query("SELECT DISTINCT tag FROM content_items WHERE tag!='' ORDER BY tag");
$categories = array_column($catsStmt->fetchAll(), 'tag');

// تعداد هر وضعیت (برای نشون دادن عدد کنار هر تب، فارغ از اینکه الان کدوم تب فعاله) — با همون فیلتر شاپ/دسته اگه باشه
$countWhere = [];
$countParams = [];
if($shop){ $countWhere[] = 'shop=?'; $countParams[] = $shop; }
if($category){ $countWhere[] = 'tag=?'; $countParams[] = $category; }
$countSql = 'SELECT status, COUNT(*) as c FROM content_items';
if($countWhere) $countSql .= ' WHERE '.implode(' AND ', $countWhere);
$countSql .= ' GROUP BY status';
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($countParams);
$counts = ['draft'=>0,'generated'=>0,'review'=>0,'approved'=>0,'published'=>0,'rejected'=>0,'failed'=>0];
foreach($countStmt->fetchAll() as $row){ $counts[$row['status']] = (int)$row['c']; }
$counts['all'] = array_sum($counts);

jsonResponse(['items'=>$items, 'shops'=>$shops, 'categories'=>$categories, 'counts'=>$counts]);
