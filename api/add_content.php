<?php
require __DIR__.'/../db.php';
allowCors();
require __DIR__.'/../lib_generate.php';
requireAuthOrAgentSecret();

$input = jsonInput();
$title = trim($input['title'] ?? '');
if(!$title) jsonResponse(['error'=>'title لازم است'], 400);

$sourceContent = trim($input['source_content'] ?? '');
$why = trim($input['why_sellable'] ?? '');
$price = $input['price'] ?? null;
$keyword = trim($input['keyword'] ?? '');
$tag = trim($input['tag'] ?? '');
$research = $input['research'] ?? null;
$shop = trim($input['shop'] ?? '');
$language = trim($input['language'] ?? 'en');

$fullContent = $sourceContent;
if($why) $fullContent .= "\n\nWhy this sells: ".$why;
if($price) $fullContent .= "\nSuggested price: \$".$price;

$result = insertAndGenerateTopic($title, $fullContent, $tag, $keyword, $research, $shop, $language);

jsonResponse(array_merge(['ok'=>true], $result));
