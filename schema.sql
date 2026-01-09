-- Veritabanı: stok_sayim
CREATE DATABASE IF NOT EXISTS stok_sayim CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE stok_sayim;

-- Sayım oturumu (sayım bittiğinde kilitlemek için)
CREATE TABLE IF NOT EXISTS count_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL DEFAULT 'Varsayılan Sayım',
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB;

-- Tek bir açık oturum garanti (uygulama tarafı kontrol eder)
INSERT INTO count_sessions (name, status)
SELECT 'Varsayılan Sayım', 'open'
WHERE NOT EXISTS (SELECT 1 FROM count_sessions WHERE status='open');

-- Stok sayım kayıtları
CREATE TABLE IF NOT EXISTS stock_counts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id INT UNSIGNED NOT NULL,
  malzeme_kodu VARCHAR(64) NOT NULL,
  malzeme_ismi VARCHAR(255) NOT NULL,
  sayim INT UNSIGNED NOT NULL DEFAULT 0,
  ozel_kod VARCHAR(64) NULL,
  grup_kodu VARCHAR(64) NOT NULL,
  marka_kodu VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uq_session_item (session_id, malzeme_kodu),
  CONSTRAINT fk_stock_session FOREIGN KEY (session_id) REFERENCES count_sessions(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;
