<?php
// api/banned_words.php — همگام‌سازی فهرست واژه‌های ممنوعه‌ی هر فروشگاه از برنامه به سرور، و پاک‌سازی محتوای ثبت‌شده
// POST {shop, text, clean?}: فهرست را ذخیره می‌کند؛ اگر clean=true همه‌ی محتواهای همان فروشگاه (عنوان، کپشن، هشتگ، توضیح) هم اصلاح می‌شوند.
// GET ?shop=: فهرست فعلی را برمی‌گرداند.
require __DIR__.'/../db.php';
require __DIR__.'/../lib_banned.php';
allowCors();
requireAuthOrAgentSecret();

if(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'){
  $in = jsonInput();
  $shop = trim((string)($in['shop'] ?? ''));
  $text = (string)($in['text'] ?? '');
  if($shop === '') jsonResponse(['error'=>'shop لازم است'], 400);
  if(strlen($text) > 5000) jsonResponse(['error'=>'فهرست خیلی بلند است'], 400);
  $map = bannedMap();
  $map[strtolower($shop)] = $text;
  setSetting('BANNED_WORDS_MAP', json_encode($map, JSON_UNESCAPED_UNICODE));
  $fixed = 0;
  if(!empty($in['clean'])){
    $pdo = db();
    $st = $pdo->prepare('SELECT id, title, caption, hashtags, source_content FROM content_items WHERE shop=?');
    $st->execute([$shop]);
    $upd = $pdo->prepare('UPDATE content_items SET title=?, caption=?, hashtags=?, source_content=? WHERE id=?');
    foreach($st->fetchAll() as $it){
      $t = bannedApply($it['title'], $shop);
      $c = bannedApply($it['caption'], $shop);
      $h = bannedApplyTags($it['hashtags'], $shop);
      $s = bannedApply($it['source_content'], $shop);
      if($t !== $it['title'] || $c !== $it['caption'] || $h !== $it['hashtags'] || $s !== $it['source_content']){
        $upd->execute([$t, $c, $h, $s, $it['id']]);
        $fixed++;
      }
    }
  }
  jsonResponse(['ok'=>true, 'fixed'=>$fixed]);
}

$shop = trim($_GET['shop'] ?? '');
$map = bannedMap();
$key = strtolower($shop);
jsonResponse(['text'=>array_key_exists($key, $map) ? $map[$key] : bannedDefaultText()]);
