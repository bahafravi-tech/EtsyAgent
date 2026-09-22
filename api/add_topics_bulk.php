<?php
// api/add_topics_bulk.php — آپلود گروهی موضوع از یه فایل CSV
// (اگه فایلت xlsx هست، توی اکسل/گوگل‌شیت: File > Save As / Export > CSV)
// ستون‌های مورد انتظار (ردیف اول = هدر): title, source_content, tag, language (اختیاری: fa/en)
// همچنین می‌تونی shop/language پیش‌فرض رو برای کل فایل به‌صورت فیلد فرم بفرستی (اگه ستون language خالی بود ازون استفاده می‌شه)
// فقط ثبت می‌کنه (status=draft) — تولید کپشن/عکس رو خودت بعداً از پنل/تب محتوای معرفی برای هرکدوم می‌زنی،
// تا یه فایل بزرگ باعث timeout سرور نشه.

require __DIR__.'/../db.php';
allowCors();
requireAuthOrAgentSecret();

if(empty($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK){
  jsonResponse(['error'=>'فایل CSV دریافت نشد'], 400);
}

$defaultShop = trim($_POST['shop'] ?? '');
$defaultLanguage = trim($_POST['language'] ?? 'en');

$path = $_FILES['csv']['tmp_name'];
$handle = fopen($path, 'r');
if(!$handle) jsonResponse(['error'=>'باز کردن فایل ناموفق بود'], 400);

// اگه فایل با BOM شروع بشه (معمول توی خروجی اکسل)، حذفش کن
$bom = fread($handle, 3);
if($bom !== "\xEF\xBB\xBF") rewind($handle);

$header = fgetcsv($handle);
if(!$header){ fclose($handle); jsonResponse(['error'=>'فایل خالیه یا فرمتش درست نیست'], 400); }
$header = array_map(fn($h)=>strtolower(trim($h)), $header);
$titleIdx = array_search('title', $header);
$contentIdx = array_search('source_content', $header);
$tagIdx = array_search('tag', $header);
$langIdx = array_search('language', $header);

if($titleIdx === false){
  fclose($handle);
  jsonResponse(['error'=>'ستون title توی فایل پیدا نشد — ردیف اول باید هدر ستون‌ها باشه (title, source_content, tag, language)'], 400);
}

$added = 0;
$skipped = 0;
$ids = [];
$stmt = db()->prepare('INSERT INTO content_items (shop, language, title, tag, source_content, status) VALUES (?,?,?,?,?,"draft")');
while(($row = fgetcsv($handle)) !== false){
  $title = trim($row[$titleIdx] ?? '');
  if(!$title){ $skipped++; continue; }
  $sourceContent = $contentIdx !== false ? trim($row[$contentIdx] ?? '') : '';
  $tag = $tagIdx !== false ? trim($row[$tagIdx] ?? '') : '';
  $language = ($langIdx !== false && trim($row[$langIdx] ?? '')) ? trim($row[$langIdx]) : $defaultLanguage;

  $stmt->execute([$defaultShop, $language, $title, $tag, $sourceContent]);
  $ids[] = (int)db()->lastInsertId();
  $added++;
}
fclose($handle);

jsonResponse(['ok'=>true, 'added'=>$added, 'skipped'=>$skipped, 'ids'=>$ids]);
