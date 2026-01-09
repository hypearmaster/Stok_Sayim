# Stok Sayım Sistemi (XAMPP + MySQL + PHP)

## Kurulum (5 dk)
1) XAMPP'ı aç → Apache + MySQL start
2) phpMyAdmin → SQL sekmesi → `schema.sql` dosyasının içeriğini çalıştır.
3) Bu klasörü komple şuraya kopyala:
   `C:\xampp\htdocs\stok_sayim\`
4) Tarayıcıdan aç:
   `http://localhost/stok_sayim/`

## Kullanım
- Kayıt Ekle / Güncelle: Aynı `Malzeme Kodu` girersen kaydı günceller (upsert).
- Excel/CSV İndir (Sunucu): CSV indirir, Excel direkt açar.
- Sayımı Bitir (Kilitle): Oturumu kapatır, artık ekleme/silme yapılmaz.
- Yeni Sayım Aç: Yeni bir oturum açar.

## Dosyalar
- index.html (arayüz)
- api.php (JSON API)
- export_csv.php (Excel uyumlu CSV)
- db.php (PDO bağlantı)
- helpers.php (yardımcılar)
- schema.sql (DB şeması)
