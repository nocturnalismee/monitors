-- ============================================================
-- servmon: Reset data metrik (metrics) — siap eksekusi
-- ------------------------------------------------------------
-- Cara pakai (produksi DB `servmon`, path web root di bawah):
--   mysql -u root -p servmon < database/reset_metrics.sql
-- atau dari phpMyAdmin: import file ini.
--
-- Ganti nama database di baris USE bila berbeda.
-- ============================================================

USE servmon;

-- Hapus semua baris tabel metrics (partisi tetap ada, AUTO_INCREMENT di-reset).
TRUNCATE TABLE metrics;

-- Tabel turunan metrik — ikut di-reset agar konsisten:
TRUNCATE TABLE metrics_history;
TRUNCATE TABLE service_metrics;

-- State status service & server direset (agar tidak menyimpan status basi):
TRUNCATE TABLE server_service_states;
TRUNCATE TABLE server_states;

-- Reset pointer "metric terakhir" & last_seen di servers, supaya tidak menggantung
-- ke id yang sudah terhapus (server tampil 'pending'/normal kembali).
UPDATE servers SET latest_metric_id = NULL, last_seen_at = NULL;

-- ============================================================
-- OPSIONAL (hapus tanda komentar jika ingin ikut di-reset):
-- TRUNCATE TABLE alert_logs;
-- TRUNCATE TABLE alert_delivery_queue;
-- ============================================================
