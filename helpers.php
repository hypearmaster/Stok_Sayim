<?php
declare(strict_types=1);

function json_response(array $data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function get_json_input(): array {
  $raw = file_get_contents('php://input') ?: '';
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function require_fields(array $data, array $fields): void {
  foreach ($fields as $f) {
    if (!isset($data[$f]) || $data[$f] === '') {
      json_response(['success' => false, 'message' => "Eksik alan: {$f}"], 422);
    }
  }
}

function normalize_str(string $s, int $max = 255): string {
  $s = trim($s);
  if ($s === '') return '';
  $s = preg_replace('/\s+/u', ' ', $s);
  if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
  return $s;
}

function get_open_session_id(PDO $pdo): int {
  $stmt = $pdo->query("SELECT id FROM count_sessions WHERE status='open' ORDER BY id DESC LIMIT 1");
  $row = $stmt->fetch();
  if ($row && isset($row['id'])) return (int)$row['id'];

  $pdo->exec("INSERT INTO count_sessions (name, status) VALUES ('Varsayılan Sayım', 'open')");
  return (int)$pdo->lastInsertId();
}

function assert_session_open(PDO $pdo, int $sessionId): void {
  $stmt = $pdo->prepare("SELECT status FROM count_sessions WHERE id=? LIMIT 1");
  $stmt->execute([$sessionId]);
  $row = $stmt->fetch();
  if (!$row) json_response(['success' => false, 'message' => 'Sayım oturumu bulunamadı.'], 404);
  if ($row['status'] !== 'open') {
    json_response(['success' => false, 'message' => 'Sayım bitmiş (kilitli). Yeni sayım açmalısın.'], 409);
  }
}