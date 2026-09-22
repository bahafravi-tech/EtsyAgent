<?php
// lib_generate.php — تولید کپشن (Claude) و عکس پس‌زمینه (Gemini) برای یک آیتم محتوا

function callClaudeAPI($prompt, $maxTokens=800){
  $c = cfg();
  if(empty($c['ANTHROPIC_API_KEY'])) return ['ok'=>false, 'error'=>'ANTHROPIC_API_KEY توی config.php تنظیم نشده'];
  $res = directFetch('https://api.anthropic.com/v1/messages', 'POST', [
    'Content-Type' => 'application/json',
    'x-api-key' => $c['ANTHROPIC_API_KEY'],
    'anthropic-version' => '2023-06-01',
  ], json_encode([
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => $maxTokens,
    'messages' => [['role'=>'user', 'content'=>$prompt]],
  ]));
  if(!$res['ok']) return ['ok'=>false, 'error'=>'درخواست Claude ناموفق بود: '.$res['body']];
  $data = json_decode($res['body'], true);
  $text = $data['content'][0]['text'] ?? null;
  if(!$text) return ['ok'=>false, 'error'=>$data['error']['message'] ?? 'پاسخ نامعتبر از Claude'];
  return ['ok'=>true, 'text'=>$text];
}

function generateCaptionForItem($id){
  $pdo = db();
  $stmt = $pdo->prepare('SELECT * FROM content_items WHERE id=?');
  $stmt->execute([$id]);
  $item = $stmt->fetch();
  if(!$item) return ['ok'=>false, 'error'=>'آیتم پیدا نشد'];

  $lang = $item['language'] ?: 'English';
  $langInstruction = "Write the caption and hashtags in natural, native {$lang} — not a literal word-for-word translation.";

  $prompt = "You are a social media copywriter for Beguchee, an Etsy shop for packaging templates and digital craft files.\n"
    ."Write an engaging Instagram/Pinterest caption INTRODUCING this upcoming product idea as something exciting that's coming soon — do not claim it is already for sale.\n"
    ."{$langInstruction}\n\n"
    ."Product idea: {$item['title']}\n"
    ."Details: {$item['source_content']}\n\n"
    ."Return ONLY valid JSON, no markdown fences: {\"caption\":\"2-4 short punchy paragraphs, emojis welcome, end with a soft call-to-action (e.g. follow for launch updates)\",\"hashtags\":[\"10 to 15 relevant hashtags, no # symbol\"]}";

  $result = callClaudeAPI($prompt, 800);
  if(!$result['ok']) return $result;

  $clean = trim(preg_replace('/```json|```/', '', $result['text']));
  $json = json_decode($clean, true);
  if(!is_array($json) || !isset($json['caption'])) return ['ok'=>false, 'error'=>'پاسخ JSON نامعتبر بود از Claude'];

  $hashtags = implode(',', array_map(fn($h) => '#'.ltrim(trim($h), '# '), $json['hashtags'] ?? []));
  $pdo->prepare('UPDATE content_items SET caption=?, hashtags=?, status=IF(status="draft","generated",status), updated_at=NOW() WHERE id=?')
      ->execute([$json['caption'], $hashtags, $id]);
  return ['ok'=>true];
}

// ثبت یه موضوع جدید و تولید خودکار کپشن+عکس براش (هم از Etsy Agent هم از فرم دستی پنل استفاده می‌شه)
function insertAndGenerateTopic($title, $sourceContent, $tag='', $keyword='', $research=null, $shop='', $language='en'){
  $pdo = db();
  $stmt = $pdo->prepare('INSERT INTO content_items (shop, language, title, tag, keyword, source_content, research_json, status) VALUES (?,?,?,?,?,?,?,"draft")');
  $stmt->execute([$shop, $language ?: 'en', $title, $tag, $keyword, $sourceContent, $research ? json_encode($research, JSON_UNESCAPED_UNICODE) : null]);
  $id = (int)$pdo->lastInsertId();

  $captionResult = generateCaptionForItem($id);
  $imageResult = generateImageForItem($id);

  $pdo->prepare('UPDATE content_items SET status="review", updated_at=NOW() WHERE id=? AND status!="draft"')->execute([$id]);
  if(!$captionResult['ok'] && !$imageResult['ok']){
    $pdo->prepare('UPDATE content_items SET status="draft" WHERE id=?')->execute([$id]);
  }
  return ['id'=>$id, 'caption_ok'=>$captionResult['ok'], 'caption_error'=>$captionResult['error'] ?? null, 'image_ok'=>$imageResult['ok'], 'image_error'=>$imageResult['error'] ?? null];
}

function generateImageForItem($id){
  $pdo = db();
  $stmt = $pdo->prepare('SELECT * FROM content_items WHERE id=?');
  $stmt->execute([$id]);
  $item = $stmt->fetch();
  if(!$item) return ['ok'=>false, 'error'=>'آیتم پیدا نشد'];
  $c = cfg();
  if(empty($c['GEMINI_API_KEY'])) return ['ok'=>false, 'error'=>'GEMINI_API_KEY توی config.php تنظیم نشده'];

  $prompt = "A clean, elegant product-photography style background image for an Etsy shop social media post about: {$item['title']}. "
    ."Soft natural lighting, minimal composition, absolutely no text, no logos, no watermarks, warm neutral tones, "
    ."square 1:1 aspect ratio, professional e-commerce aesthetic, shallow depth of field. Make this shot visually distinct from a typical first shot — vary the angle, prop styling, or color mood slightly for variety in a carousel.";

  $model = 'gemini-2.5-flash-image';
  $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=".$c['GEMINI_API_KEY'];
  $res = relayFetch($url, 'POST', ['Content-Type'=>'application/json'], json_encode([
    'contents' => [['parts' => [['text'=>$prompt]]]],
  ]));
  if(!$res['ok']) return ['ok'=>false, 'error'=>'درخواست Gemini ناموفق بود: '.$res['body']];

  $data = json_decode($res['body'], true);
  $b64 = null;
  foreach(($data['candidates'][0]['content']['parts'] ?? []) as $part){
    if(isset($part['inlineData']['data'])){ $b64 = $part['inlineData']['data']; break; }
    if(isset($part['inline_data']['data'])){ $b64 = $part['inline_data']['data']; break; }
  }
  if(!$b64) return ['ok'=>false, 'error'=>'عکسی توی پاسخ Gemini نبود — پاسخ کامل: '.substr($res['body'],0,300)];

  $bytes = base64_decode($b64);
  $dir = __DIR__.'/uploads';
  if(!is_dir($dir)) mkdir($dir, 0755, true);
  // اینستاگرام فقط JPEG واقعی قبول می‌کنه، و ابعاد بزرگ رو هم محدود می‌کنیم تا حجم پایین بمونه (پست سریع‌تر)
  $filename = 'hero_'.$id.'_'.time().'.jpg';
  resizeAndSaveJpeg($bytes, $dir.'/'.$filename);
  $newPath = 'uploads/'.$filename;

  // به لیست اسلایدهای موجود اضافه می‌شه (مثل کاروسل فی‌چاپ)، نه جایگزینی عکس قبلی
  $images = ($item['images_json'] ?? null) ? json_decode($item['images_json'], true) : [];
  if(!is_array($images)) $images = [];
  $images[] = $newPath;

  try{
    $pdo->prepare('UPDATE content_items SET hero_image_path=?, images_json=?, status=IF(status="draft","generated",status), updated_at=NOW() WHERE id=?')
        ->execute([$images[0], json_encode($images), $id]);
  }catch(PDOException $e){
    // ستون images_json هنوز روی دیتابیس اضافه نشده — حداقل عکس تکی رو ذخیره کن تا کرش نکنه
    $pdo->prepare('UPDATE content_items SET hero_image_path=?, status=IF(status="draft","generated",status), updated_at=NOW() WHERE id=?')
        ->execute([$newPath, $id]);
    return ['ok'=>true, 'path'=>$newPath, 'warning'=>'ستون images_json روی دیتابیس نیست — فقط عکس تکی ذخیره شد. برای پشتیبانی از چند عکس، این خط رو توی phpMyAdmin اجرا کن: ALTER TABLE content_items ADD COLUMN images_json TEXT NULL AFTER hero_image_path;'];
  }
  return ['ok'=>true, 'path'=>$newPath];
}
