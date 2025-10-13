<?php
require_once __DIR__ . '/db.php';
header('Content-Type: text/plain');

try {
  $pdo = db();
  echo "DB: OK\n\n";

  $tables = ['users','locations','tasks','tickets','counters','system_settings'];
  foreach ($tables as $t) {
    $st = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t));
    echo ($st->fetchColumn() ? "[OK] " : "[MISS] ") . $t . "\n";
  }

  echo "\nColumns:\n";
  $expect = [
    'users'     => ['id','name','email','role','manager_id','phone','mobile','active','password_hash','created_at'],
    'locations' => ['id','site_code','name','address','phone_ext','birthdate','active','created_at'],
    'tasks'     => ['id','title','assignee_id','created_by','completed','created_at'],
  ];
  foreach ($expect as $table=>$cols) {
    echo "\n$table:\n";
    $colsDb = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN,0);
    foreach ($cols as $c) {
      echo in_array($c,$colsDb, true) ? "  [OK] $c\n" : "  [MISS] $c\n";
    }
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo "DB ERROR: " . $e->getMessage() . "\n";
}
