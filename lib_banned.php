<?php
// lib_banned.php — واژه‌های ممنوعه (مثل «Iran») در کپشن، عنوان و هشتگ‌های محتوای معرفی: جایگزینی خودکار قبل از ساخت/انتشار.
// فهرست برای هر فروشگاه جدا در app_settings (کلید BANNED_WORDS_MAP) ذخیره می‌شود و از برنامه (api/banned_words.php) همگام می‌شود.
// فرمت هر خط: «واژه = جایگزین»؛ جایگزین خالی یعنی حذف واژه.
require_once __DIR__.'/db.php';

function bannedDefaultText(){ return "Iran = Persian\nIranian = Persian"; }

function bannedMap(){
  $m = json_decode((string)getSetting('BANNED_WORDS_MAP', '{}'), true);
  return is_array($m) ? $m : [];
}

function bannedPairs($shop){
  $map = bannedMap();
  $key = strtolower(trim((string)$shop));
  $text = array_key_exists($key, $map) ? $map[$key] : bannedDefaultText();
  $pairs = [];
  foreach(preg_split('/\r?\n/', (string)$text) as $line){
    $line = trim($line);
    if($line === '') continue;
    $parts = preg_split('/\s*(?:=|→|->)\s*/u', $line, 2);
    $w = trim($parts[0] ?? '');
    if($w === '') continue;
    $pairs[] = [$w, trim($parts[1] ?? '')];
  }
  return $pairs;
}

function bannedApply($text, $shop){
  $out = (string)$text;
  if($out === '') return $out;
  $changed = false;
  foreach(bannedPairs($shop) as $pair){
    $w = $pair[0]; $r = $pair[1];
    $re = '/(?<![\p{L}\p{N}])'.preg_quote($w, '/').'(?![\p{L}\p{N}])/iu';
    $new = preg_replace_callback($re, function($m) use ($r){
      if($r === '') return '';
      $s = $m[0];
      if(mb_strlen($s) > 1 && $s === mb_strtoupper($s)) return mb_strtoupper($r);
      $first = mb_substr($s, 0, 1);
      if($first === mb_strtoupper($first) && $first !== mb_strtolower($first)) return mb_strtoupper(mb_substr($r, 0, 1)).mb_substr($r, 1);
      return $r;
    }, $out);
    if($new !== null && $new !== $out){ $out = $new; $changed = true; }
  }
  return $changed ? trim(preg_replace('/[ \t]{2,}/', ' ', $out)) : $out;
}

// هشتگ‌ها: جایگزینی + حذف تکراری (مثلاً #Iran و #Persian هر دو به #Persian نرسند)
function bannedApplyTags($tagsCsv, $shop){
  $s = bannedApply($tagsCsv, $shop);
  if($s === (string)$tagsCsv) return $s;
  $seen = []; $out = [];
  foreach(explode(',', $s) as $t){
    $t = trim($t);
    if($t === '' || $t === '#') continue;
    $k = mb_strtolower($t);
    if(isset($seen[$k])) continue;
    $seen[$k] = 1; $out[] = $t;
  }
  return implode(',', $out);
}

// جمله‌ی اخطار برای پرامپت تولید کپشن
function bannedPromptLine($shop){
  $pairs = bannedPairs($shop);
  if(!$pairs) return '';
  $items = array_map(function($p){ return '"'.$p[0].'"'.($p[1] !== '' ? ' (write "'.$p[1].'" instead)' : ' (leave it out)'); }, $pairs);
  return "FORBIDDEN WORDS (Etsy and social platforms may restrict posts with them): never use these words anywhere in the caption or hashtags — ".implode('; ', $items).". Also avoid trademarked brand, character and celebrity names. Use neutral wording such as \"Persian\" for Persian culture.\n";
}
