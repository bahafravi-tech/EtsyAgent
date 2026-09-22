<?php
require __DIR__.'/../db.php';
allowCors();
require __DIR__.'/../lib_generate.php';
requireAuthOrAgentSecret();

$input = jsonInput();
$id = intval($input['id'] ?? 0);
if(!$id) jsonResponse(['error'=>'id لازم است'], 400);

$result = generateCaptionForItem($id);
jsonResponse($result, $result['ok'] ? 200 : 500);
