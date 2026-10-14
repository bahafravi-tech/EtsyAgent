<?php
// api/pinterest_feed.php — فید RSS 2.0 برای «Auto-publish» پینترست (جایگزین API که فقط با Standard access عمومی می‌شود).
// عمومی است (بدون ورود)، چون خودِ Pinterest آن را می‌خواند. فقط آیتم‌هایی را می‌آورد که برای پینترست «منتشر» شده‌اند
// (حالت RSS در cron_lib.php: انتشار = اضافه شدن به همین فید). پارامتر اختیاری ?shop=نام‌فروشگاه برای یک فید جدا به‌ازای هر برد.
require __DIR__.'/../db.php';
require __DIR__.'/../lib_banned.php';

$pdo = db();
$siteUrl = rtrim(cfg()['SITE_URL'] ?? '', '/');
$shop = trim($_GET['shop'] ?? '');

$sql = "SELECT i.*, p.published_at AS pin_at FROM content_items i
        JOIN content_platforms p ON p.content_id=i.id AND p.platform='pinterest' AND p.status='published' AND p.external_id='rss-feed'
        WHERE i.hero_image_path IS NOT NULL AND i.hero_image_path!=''";
$params = [];
if($shop !== ''){ $sql .= ' AND i.shop=?'; $params[] = $shop; }
$sql .= ' ORDER BY p.published_at DESC, i.id DESC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$esc = function($s){ return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
$tagsLine = function($item){
  if(empty($item['hashtags'])) return '';
  return implode(' ', array_filter(array_map(function($h){
    $t = preg_replace('/[^a-zA-Z0-9_]/', '', trim($h));
    return $t === '' ? '' : '#'.$t;
  }, explode(',', bannedApplyTags($item['hashtags'], $item['shop'] ?? '')))));
};

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
?>
<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
  <title><?= $esc($shop !== '' ? $shop : 'Etsy Agent') ?></title>
  <link><?= $esc($siteUrl) ?>/</link>
  <description>Pins</description>
  <language>en</language>
  <atom:link href="<?= $esc($siteUrl) ?>/api/pinterest_feed.php<?= $shop !== '' ? '?shop='.$esc(rawurlencode($shop)) : '' ?>" rel="self" type="application/rss+xml" />
<?php foreach($rows as $r):
  $title = mb_substr(trim(bannedApply((string)$r['title'], $r['shop'] ?? '')), 0, 100);
  $desc = trim(bannedApply(trim((string)($r['caption'] ?: $r['source_content'])), $r['shop'] ?? '').' '.$tagsLine($r));
  $desc = mb_substr($desc, 0, 480);
  $link = $siteUrl.'/go.php?id='.(int)$r['id'];
  $img = $siteUrl.'/api/image.php?path='.urlencode($r['hero_image_path']);
  $ts = strtotime($r['pin_at'] ?: $r['created_at']) ?: time();
?>
  <item>
    <title><?= $esc($title) ?></title>
    <link><?= $esc($link) ?></link>
    <guid isPermaLink="false">pin-<?= (int)$r['id'] ?></guid>
    <pubDate><?= date(DATE_RSS, $ts) ?></pubDate>
    <description><![CDATA[<?= str_replace(']]>', ']]&gt;', $desc) ?><br/><img src="<?= $esc($img) ?>" />]]></description>
    <enclosure url="<?= $esc($img) ?>" type="image/jpeg" length="0" />
    <media:content url="<?= $esc($img) ?>" medium="image" type="image/jpeg" />
  </item>
<?php endforeach; ?>
</channel>
</rss>
