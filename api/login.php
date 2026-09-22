<?php
require __DIR__.'/../db.php';
startAgentSession();
$input = jsonInput();
$password = $input['password'] ?? '';
$c = cfg();
if(empty($c['DASHBOARD_PASSWORD']) || !hash_equals((string)$c['DASHBOARD_PASSWORD'], (string)$password)){
  jsonResponse(['error'=>'رمز اشتباه است'], 401);
}
$_SESSION['logged_in'] = true;
jsonResponse(['ok'=>true]);
