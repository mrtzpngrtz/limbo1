<?php
$db = new SQLite3(__DIR__ . '/limbo.db');
$row = $db->querySingle('SELECT * FROM cycles WHERE cycle < 9000 ORDER BY id DESC LIMIT 1', true);
header('Content-Type: application/json');
echo json_encode($row ?: null);
