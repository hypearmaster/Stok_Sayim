<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$sessionId = (int)($_GET['session_id'] ?? 0);
if ($sessionId <= 0) $sessionId = get_open_session_id($pdo);

// session bilgisi
$stmt = $pdo->prepare("SELECT id, name, status, created_at, closed_at FROM count_sessions WHERE id=?");
$stmt->execute([$sessionId]);
$session = $stmt->fetch();
if (!$session) {
  http_response_code(404);
  echo "Session not found";
  exit;
}

$stmt = $pdo->prepare("
  SELECT 
    malzeme_kodu AS 'Malzeme Kodu',
    malzeme_ismi AS 'Malzeme İsmi',
    sayim AS 'Sayım',
    IFNULL(ozel_kod,'') AS 'Özel Kod',
    grup_kodu AS 'Grup Kodu',
    marka_kodu AS 'Marka Kodu',
    created_at AS 'Eklenme',
    IFNULL(updated_at,'') AS 'Güncelleme'
  FROM stock_counts
  WHERE session_id=?
  ORDER BY id DESC
");
$stmt->execute([$sessionId]);
$rows = $stmt->fetchAll();

$filename = 'stok_sayimi_session_' . $sessionId . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');

// Excel TR için BOM eklemek iyi olur
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// başlık
if (!empty($rows)) {
  fputcsv($out, array_keys($rows[0]), ';');
  foreach ($rows as $r) {
    fputcsv($out, array_values($r), ';');
  }
} else {
  fputcsv($out, ['Boş'], ';');
}

fclose($out);
exit;
