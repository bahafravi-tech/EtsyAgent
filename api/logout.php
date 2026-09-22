<?php
require __DIR__.'/../db.php';
startAgentSession();
$_SESSION = [];
session_destroy();
jsonResponse(['ok'=>true]);
