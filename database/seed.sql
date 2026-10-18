-- ============================================================================
-- AurumFlow — Seed Data Fase 1
-- ============================================================================
-- SUMBER KEBENARAN: docs/AurumFlow Entity Relationship Diagram.md § 4 point 10
-- (blok "DATA INISIALISASI DASAR (SEED DATA)").
--
-- Dijalankan SETELAH database/schema.sql.
--
-- Sifat file ini:
--   * Idempotent — aman dijalankan berkali-kali (QC 4.3 / 4.4). Pola
--     `INSERT ... SELECT ... FROM DUAL WHERE NOT EXISTS (...)` dipakai sebagai
--     ganti INSERT IGNORE agar kesalahan nyata (mis. nilai terlalu panjang)
--     TIDAK ikut tertelan secara diam-diam.
--   * Tidak memuat akun pengguna. Akun operator awal dibuat oleh
--     database/install.php memakai Security::hashPassword() sehingga hash
--     selalu valid dan unik per environment.
--   * Tidak memuat nilai uang/berat transaksional.
-- ============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- system_settings — 6 kunci inisialisasi (kunci operator_base_quota_pct diuji
-- oleh Protokol QC Manual 1 pada dokumen Roadmap).
-- ----------------------------------------------------------------------------
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`)
SELECT 'allocation_mode', 'waterfall', 'Pilihan mode: waterfall atau prorata'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `setting_key` = 'allocation_mode');

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`)
SELECT 'operator_base_quota_pct', '50.00', 'Batas kuota serapan modal utama pengelola (%)'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `setting_key` = 'operator_base_quota_pct');

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`)
SELECT 'default_burn_fee', '20000.00', 'Biaya flat jasa pembakaran amalgama per kedatangan'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `setting_key` = 'default_burn_fee');

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`)
SELECT 'default_burn_fee_operator_cut', '10000.00', 'Porsi upah kas langsung pengelola dari jasa bakar'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `setting_key` = 'default_burn_fee_operator_cut');

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`)
SELECT 'notice_period_days', '7', 'Masa tunggu penarikan modal pokok investor (hari)'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `setting_key` = 'notice_period_days');

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`)
SELECT 'active_carryover_deficit', '0.00', 'Akumulasi defisit hari minus yang belum pulih'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `setting_key` = 'active_carryover_deficit');

-- ----------------------------------------------------------------------------
-- cash_registers — 2 pos kas fisik awal
-- ----------------------------------------------------------------------------
-- `register_name` UNIQUE sehingga penjaga idempotensi memakai nama pos,
-- bukan UUID yang selalu berbeda setiap kali skrip dijalankan.
INSERT INTO `cash_registers` (`id`, `register_name`, `current_balance`)
SELECT UUID(), 'Kas Meja Bakar', 0.00
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `cash_registers` WHERE `register_name` = 'Kas Meja Bakar');

INSERT INTO `cash_registers` (`id`, `register_name`, `current_balance`)
SELECT UUID(), 'Brankas Utama', 0.00
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `cash_registers` WHERE `register_name` = 'Brankas Utama');

-- ----------------------------------------------------------------------------
-- daily_loan_limit — batas total pokok kasbon per hari (Fase 8)
-- ----------------------------------------------------------------------------
-- Batas bersifat INKLUSIF (Σ pokok hari ini + pokok baru == limit diterima) dan
-- dihitung per `miner_loans.issued_on`. Nilai 0 berarti kasbon dinonaktifkan;
-- nilai rusak/negatif ditolak peladen dengan pesan (fail-closed), bukan
-- dianggap tak terbatas.
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`)
SELECT 'daily_loan_limit', '500000.00', 'Batas total pokok kasbon yang boleh dicairkan per hari (Rp)'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `setting_key` = 'daily_loan_limit');
