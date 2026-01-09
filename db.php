<?php
// db.php - XAMPP için basit PDO bağlantısı
// NOT: Bu dosyayı htdocs içine koyacaksın. Örn: C:\xampp\htdocs\stok_sayim\db.php

declare(strict_types=1);

$DB_HOST = '127.0.0.1';
$DB_NAME = 'stok_sayim';
$DB_USER = 'root';
$DB_PASS = ''; // XAMPP default boş

$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES => false,
];

try {
  $pdo = new PDO(
    "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
    $DB_USER,
    $DB_PASS,
    $options
  );
} catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'success' => false,
    'message' => 'DB bağlantı hatası: ' . $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
  exit;
}
