<?php

declare(strict_types=1);

// ===== CORS =====
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = [
  'http://127.0.0.1:5500',
  'http://localhost:5500',
  'http://192.168.1.100:5500'
];

if (in_array($origin, $allowed, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Vary: Origin");
}
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  exit;
}

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$action = $_GET['action'] ?? '';

try {
  // ===== SESSION =====
  if ($action === 'session') {
    $sessionId = get_open_session_id($pdo);
    $stmt = $pdo->prepare("SELECT id, name, status, created_at, closed_at FROM count_sessions WHERE id=?");
    $stmt->execute([$sessionId]);
    json_response(['success' => true, 'data' => $stmt->fetch()]);
  }

  // ===== LIST =====
  if ($action === 'list') {
    $sessionId = get_open_session_id($pdo);
    $stmt = $pdo->prepare("
      SELECT id, malzeme_kodu, malzeme_ismi, sayim, ozel_kod, grup_kodu, marka_kodu, created_at, updated_at
      FROM stock_counts
      WHERE session_id=?
      ORDER BY id DESC
    ");
    $stmt->execute([$sessionId]);
    json_response(['success' => true, 'session_id' => $sessionId, 'data' => $stmt->fetchAll()]);
  }

  // ===== REF_SEARCH - Gelişmiş kelime kelime arama =====
  if ($action === 'ref_search') {
    $query = trim($_GET['q'] ?? '');
    if ($query === '') {
      json_response(['success' => false, 'message' => 'Arama sorgusu boş'], 422);
    }

    // Kelime kelime ayır ve gereksiz boşlukları temizle
    $keywords = array_filter(array_map('trim', explode(' ', $query)));
    
    if (empty($keywords)) {
      json_response(['success' => false, 'message' => 'Geçerli arama kelimesi yok'], 422);
    }

    // Her kelime için LIKE koşulu oluştur
    $conditions = [];
    $params = [];
    foreach ($keywords as $keyword) {
      $conditions[] = "malzeme_aciklamasi LIKE ?";
      $params[] = '%' . $keyword . '%';
    }
    
    // Tüm kelimelerin geçmesi gerekiyor (AND ile)
    $whereClause = implode(' AND ', $conditions);

    $sql = "
      SELECT
        malzeme_kodu,
        malzeme_aciklamasi,
        COALESCE(ozel_kod, '') AS ozel_kod,
        COALESCE(CAST(grup_kodu AS CHAR), '') AS grup_kodu,
        COALESCE(CAST(marka_kodu AS CHAR), '') AS marka_kodu
      FROM ref_items
      WHERE {$whereClause}
      ORDER BY LENGTH(malzeme_aciklamasi) ASC
      LIMIT 50
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Integer'ları format'la
    foreach ($rows as &$row) {
      if (isset($row['grup_kodu']) && is_numeric($row['grup_kodu'])) {
        $row['grup_kodu'] = str_pad((string)$row['grup_kodu'], 2, '0', STR_PAD_LEFT);
      }
      if (isset($row['marka_kodu']) && is_numeric($row['marka_kodu'])) {
        $row['marka_kodu'] = str_pad((string)$row['marka_kodu'], 2, '0', STR_PAD_LEFT);
      }
    }

    json_response(['success' => true, 'data' => $rows, 'count' => count($rows), 'keywords' => $keywords]);
  }

  // ===== REF_GET - Ref_items'dan bilgi çek =====
  if ($action === 'ref_get') {
    $code = trim($_GET['code'] ?? '');
    if ($code === '') {
      json_response(['success' => false, 'message' => 'Malzeme kodu boş'], 422);
    }

    $stmt = $pdo->prepare("
      SELECT
        malzeme_kodu,
        malzeme_aciklamasi,
        COALESCE(ozel_kod, '') AS ozel_kod,
        COALESCE(CAST(grup_kodu AS CHAR), '') AS grup_kodu,
        COALESCE(CAST(marka_kodu AS CHAR), '') AS marka_kodu
      FROM ref_items
      WHERE malzeme_kodu = ?
      LIMIT 1
    ");
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      json_response(['success' => false, 'message' => 'Kod ref listesinde bulunamadı'], 404);
    }

    // Integer değerleri string'e çevir (1 -> "01" formatı için)
    if (isset($row['grup_kodu']) && is_numeric($row['grup_kodu'])) {
      $row['grup_kodu'] = str_pad((string)$row['grup_kodu'], 2, '0', STR_PAD_LEFT);
    }
    
    if (isset($row['marka_kodu']) && is_numeric($row['marka_kodu'])) {
      $row['marka_kodu'] = str_pad((string)$row['marka_kodu'], 2, '0', STR_PAD_LEFT);
    }

    // Debug için log
    error_log('Ref data for ' . $code . ': ' . json_encode($row));

    json_response(['success' => true, 'data' => $row]);
  }

  // ===== ADD - Yeni kayıt veya güncelleme =====
  if ($action === 'add') {
    $sessionId = get_open_session_id($pdo);
    assert_session_open($pdo, $sessionId);

    $data = get_json_input();
    require_fields($data, ['malzemeKodu', 'malzemeSayimi']);

    $malzemeKodu = normalize_str((string)$data['malzemeKodu'], 64);
    $sayim = (int)$data['malzemeSayimi'];
    
    if ($sayim < 0) {
      json_response(['success' => false, 'message' => 'Sayım 0 veya daha büyük olmalı.'], 422);
    }

    // ✅ AYNI KOD VAR MI KONTROL ET
    $checkStmt = $pdo->prepare("
      SELECT id FROM stock_counts 
      WHERE session_id = ? AND malzeme_kodu = ?
      LIMIT 1
    ");
    $checkStmt->execute([$sessionId, $malzemeKodu]);
    if ($checkStmt->fetch()) {
      json_response(['success' => false, 'message' => 'Bu ürün kodu zaten ekli! Düzenlemek için tablodan düzenle butonunu kullan.'], 409);
    }

    // Ref'den bilgileri çek
    $refStmt = $pdo->prepare("
      SELECT 
        malzeme_aciklamasi, 
        COALESCE(ozel_kod, '') AS ozel_kod,
        COALESCE(CAST(grup_kodu AS CHAR), '') AS grup_kodu,
        COALESCE(CAST(marka_kodu AS CHAR), '') AS marka_kodu
      FROM ref_items 
      WHERE malzeme_kodu=? 
      LIMIT 1
    ");
    $refStmt->execute([$malzemeKodu]);
    $refData = $refStmt->fetch(PDO::FETCH_ASSOC);

    // Ref'de yoksa, kullanıcı girdisini kullan (yeni ürün gibi davran)
    if (!$refData) {
      $malzemeIsmi = normalize_str((string)($data['malzemeIsmi'] ?? ''), 255);
      $ozelKod = normalize_str((string)($data['ozelKod'] ?? ''), 64);
      $grupKodu = normalize_str((string)($data['grupKodu'] ?? ''), 64);
      $markaKodu = normalize_str((string)($data['markaKodu'] ?? ''), 64);

      if ($malzemeIsmi === '' || $grupKodu === '' || $markaKodu === '') {
        json_response(['success' => false, 'message' => 'Ref listede yok. Malzeme ismi, grup ve marka kodu gerekli.'], 422);
      }
    } else {
      // ✅ REF'DE VARSA: Ref'deki dolu alanları kullan, boş olanları kullanıcıdan al
      $malzemeIsmi = $refData['malzeme_aciklamasi'] ?: normalize_str((string)($data['malzemeIsmi'] ?? ''), 255);
      
      // Özel kod: Ref'de boşsa kullanıcıdan al
      $ozelKod = $refData['ozel_kod'] ?: normalize_str((string)($data['ozelKod'] ?? ''), 64);
      
      // Grup kodu: Ref'de boşsa kullanıcıdan al
      $grupKodu = $refData['grup_kodu'];
      if (!$grupKodu || $grupKodu === '') {
        $grupKodu = normalize_str((string)($data['grupKodu'] ?? ''), 64);
      } else if (is_numeric($grupKodu)) {
        $grupKodu = str_pad((string)$grupKodu, 2, '0', STR_PAD_LEFT);
      }
      
      // Marka kodu: Ref'de boşsa kullanıcıdan al
      $markaKodu = $refData['marka_kodu'];
      if (!$markaKodu || $markaKodu === '') {
        $markaKodu = normalize_str((string)($data['markaKodu'] ?? ''), 64);
      } else if (is_numeric($markaKodu)) {
        $markaKodu = str_pad((string)$markaKodu, 2, '0', STR_PAD_LEFT);
      }

      // Son kontrol: Hala boşsa hata ver
      if ($malzemeIsmi === '' || $grupKodu === '' || $markaKodu === '') {
        json_response(['success' => false, 'message' => 'Malzeme ismi, grup ve marka kodu gerekli.'], 422);
      }
    }

    // ✅ SADECE INSERT YAP (UPSERT YOK)
    $sql = "
      INSERT INTO stock_counts (session_id, malzeme_kodu, malzeme_ismi, sayim, ozel_kod, grup_kodu, marka_kodu)
      VALUES (:session_id, :malzeme_kodu, :malzeme_ismi, :sayim, :ozel_kod, :grup_kodu, :marka_kodu)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':session_id' => $sessionId,
      ':malzeme_kodu' => $malzemeKodu,
      ':malzeme_ismi' => $malzemeIsmi,
      ':sayim' => $sayim,
      ':ozel_kod' => ($ozelKod === '' ? null : $ozelKod),
      ':grup_kodu' => $grupKodu,
      ':marka_kodu' => $markaKodu,
    ]);

    json_response(['success' => true, 'message' => 'Kayıt eklendi.']);
  }

  // ===== EDIT - Mevcut kaydı düzenle =====
  if ($action === 'edit') {
    $sessionId = get_open_session_id($pdo);
    assert_session_open($pdo, $sessionId);

    $data = get_json_input();
    require_fields($data, ['id', 'malzemeIsmi', 'malzemeSayimi', 'grupKodu', 'markaKodu']);

    $id = (int)$data['id'];
    $malzemeIsmi = normalize_str((string)$data['malzemeIsmi'], 255);
    $sayim = (int)$data['malzemeSayimi'];
    $ozelKod = normalize_str((string)($data['ozelKod'] ?? ''), 64);
    $grupKodu = normalize_str((string)$data['grupKodu'], 64);
    $markaKodu = normalize_str((string)$data['markaKodu'], 64);

    if ($sayim < 0) {
      json_response(['success' => false, 'message' => 'Sayım 0 veya daha büyük olmalı.'], 422);
    }

    $stmt = $pdo->prepare("
      UPDATE stock_counts 
      SET malzeme_ismi=?, sayim=?, ozel_kod=?, grup_kodu=?, marka_kodu=?, updated_at=CURRENT_TIMESTAMP
      WHERE id=? AND session_id=?
    ");
    $stmt->execute([
      $malzemeIsmi,
      $sayim,
      ($ozelKod === '' ? null : $ozelKod),
      $grupKodu,
      $markaKodu,
      $id,
      $sessionId
    ]);

    if ($stmt->rowCount() === 0) {
      json_response(['success' => false, 'message' => 'Kayıt bulunamadı veya güncellenmedi.'], 404);
    }

    json_response(['success' => true, 'message' => 'Kayıt güncellendi.']);
  }

  // ===== QUICK_ADD - Hızlı stok artır =====
  if ($action === 'quick_add') {
    $sessionId = get_open_session_id($pdo);
    assert_session_open($pdo, $sessionId);

    $data = get_json_input();
    require_fields($data, ['malzemeKodu', 'eklenecekSayim']);

    $malzemeKodu = normalize_str((string)$data['malzemeKodu'], 64);
    $eklenecek = (int)$data['eklenecekSayim'];

    if ($eklenecek <= 0) {
      json_response(['success' => false, 'message' => 'Eklenecek sayı 0dan büyük olmalı'], 422);
    }

    $stmt = $pdo->prepare("
      SELECT id, sayim 
      FROM stock_counts 
      WHERE session_id = ? AND malzeme_kodu = ?
      LIMIT 1
    ");
    $stmt->execute([$sessionId, $malzemeKodu]);
    $row = $stmt->fetch();

    if (!$row) {
      json_response(['success' => false, 'message' => 'Bu ürün kodu ile kayıt bulunamadı'], 404);
    }

    $stmt = $pdo->prepare("
      UPDATE stock_counts 
      SET sayim = sayim + ?, updated_at = CURRENT_TIMESTAMP
      WHERE id = ?
    ");
    $stmt->execute([$eklenecek, $row['id']]);

    json_response([
      'success' => true,
      'message' => 'Stok güncellendi',
      'yeni_sayim' => $row['sayim'] + $eklenecek
    ]);
  }

  // ===== DELETE =====
  if ($action === 'delete') {
    $sessionId = get_open_session_id($pdo);
    assert_session_open($pdo, $sessionId);

    $data = get_json_input();
    require_fields($data, ['id']);
    $id = (int)$data['id'];

    $stmt = $pdo->prepare("DELETE FROM stock_counts WHERE id=? AND session_id=?");
    $stmt->execute([$id, $sessionId]);

    json_response(['success' => true, 'message' => 'Silindi.']);
  }

  // ===== CLEAR =====
  if ($action === 'clear') {
    $sessionId = get_open_session_id($pdo);
    assert_session_open($pdo, $sessionId);

    $pdo->prepare("DELETE FROM stock_counts WHERE session_id=?")->execute([$sessionId]);
    json_response(['success' => true, 'message' => 'Tüm kayıtlar temizlendi.']);
  }

  // ===== FINISH =====
  if ($action === 'finish') {
    $sessionId = get_open_session_id($pdo);

    $stmt = $pdo->prepare("UPDATE count_sessions SET status='closed', closed_at=CURRENT_TIMESTAMP WHERE id=? AND status='open'");
    $stmt->execute([$sessionId]);

    json_response(['success' => true, 'message' => 'Sayım bitirildi. Artık kayıt eklenmez.', 'session_id' => $sessionId]);
  }

  // ===== NEW_SESSION =====
  if ($action === 'new_session') {
    $stmt = $pdo->query("SELECT id FROM count_sessions WHERE status='open' ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch();
    if ($row) {
      $pdo->prepare("UPDATE count_sessions SET status='closed', closed_at=CURRENT_TIMESTAMP WHERE id=?")->execute([(int)$row['id']]);
    }
    $pdo->exec("INSERT INTO count_sessions (name, status) VALUES ('Yeni Sayım', 'open')");
    $newId = (int)$pdo->lastInsertId();
    json_response(['success' => true, 'message' => 'Yeni sayım açıldı.', 'session_id' => $newId]);
  }

  json_response(['success' => false, 'message' => 'Geçersiz action.'], 400);
} catch (Throwable $e) {
  json_response(['success' => false, 'message' => 'Sunucu hatası: ' . $e->getMessage()], 500);
}