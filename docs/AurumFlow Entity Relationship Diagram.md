# DATABASE DESIGN DOCUMENT (DDD) & SKEMA SQL
**Proyek:** AurumFlow  
**Versi:** 1.0.0  
**Target RDBMS:** MySQL 8.0+ / MariaDB (Storage Engine: InnoDB)  
**Karakter Set:** `utf8mb4` | **Collation:** `utf8mb4_unicode_ci`  

---

## 1. Diagram Hubungan Entitas (ERD)

```
       ┌────────────────────────┐
       │     SYSTEM_SETTINGS    │
       └────────────────────────┘

       ┌────────────────────────┐
       │         USERS          │
       └───────────┬────────────┘
                   │ 1
                   │
                   │ 1..N
       ┌───────────▼────────────┐       1..N ┌────────────────────────┐
       │    CAPITAL_WALLETS     ├───────────►│  WITHDRAWAL_REQUESTS   │
       └───────────┬────────────┘            └────────────────────────┘
                   │ 1
                   │
                   │ 1..N
       ┌───────────▼────────────┐
       │  PROFIT_DISTRIBUTIONS  │
       └───────────▲────────────┘
                   │ 1..N
                   │
                   │ 1
       ┌───────────┴────────────┐
       │   DAILY_SETTLEMENTS    │
       └───────────▲────────────┘
                   │ 1
                   │
                   │ 0..N
       ┌───────────┴────────────┐
       │   HULU_TRANSACTIONS    │
       └────────────────────────┘

       ┌────────────────────────┐       1..N ┌────────────────────────┐
       │     CASH_REGISTERS     ├───────────►│     CASH_MUTATIONS     │
       └───────────┬────────────┘            └────────────────────────┘
                   │ 1
                   │
                   │ 1..N
       ┌───────────▼────────────┐
       │      BRIDGE_LOANS      │  (dana talangan operasional — Fase 6)
       └───────────▲────────────┘
                   │ 1..N
                   │
                   │ 0..1
       ┌───────────┴────────────┐
       │   DAILY_SETTLEMENTS    │
       └────────────────────────┘
```

---

## 2. Kardinalitas & Aturan Relasi Data

| Relasi Antar-Entitas | Kardinalitas | Aturan Integritas (*Integrity Rule*) |
| :--- | :---: | :--- |
| `users` → `capital_wallets` | 1 : N | Satu pengguna bisa memiliki satu dompet permodalan. Relasi disiapkan 1 : N jika di masa depan satu investor membuka portofolio berbeda. |
| `capital_wallets` → `withdrawal_requests` | 1 : N | Satu dompet dapat mengajukan beberapa riwayat penarikan (modal pokok maupun dividen). |
| `daily_settlements` → `hulu_transactions` | 1 : N | Satu sesi penutupan buku harian (*settlement*) merangkum banyak transaksi pembelian hulu yang statusnya menginap (`holding`). |
| `daily_settlements` → `profit_distributions` | 1 : N | Satu sesi penutupan buku mendistribusikan dividen ke banyak dompet pemodal yang modalnya terserap putaran tersebut. |
| `capital_wallets` → `profit_distributions` | 1 : N | Satu dompet modal menerima akumulasi dividen dari berbagai sesi *settlement*. |
| `cash_registers` → `cash_mutations` | 1 : N | Pos kas fisik (meja bakar atau brankas utama) mencatat seluruh riwayat mutasi masuk/keluar. |
| `cash_registers` → `bridge_loans` | 1 : N | Pos kas penerima dana talangan. Pengembalian selalu keluar dari pos yang sama (`Kas Meja Bakar`), karena dana talangan hanya **melintas** di kas operasional. |
| `daily_settlements` → `bridge_loans` | 1 : N | Satu sesi tutup buku mengembalikan pokok + fee seluruh dana talangan yang masih `active` (urutan FIFO). Baris talangan menyimpan `settlement_id` sebagai jejak audit pengembaliannya. |

---

## 3. Kamus Data Entitas (*Data Dictionary*)

### 3.1. Standar Tipe Data Khusus
* **Mata Uang & Finansial:** Menggunakan `DECIMAL(14,2)` untuk mencegah kesalahan pembulatan angka pecahan (*floating-point rounding errors*).
* **Timbangan Emas:** Menggunakan `DECIMAL(8,3)` guna mempertahankan presisi timbangan digital hingga **0,001 gram**.
* **Persentase:** Menggunakan `DECIMAL(5,2)` (contoh: 92,50% atau 50,00%).
* **Identitas Unik (PK):** Menggunakan `CHAR(36)` untuk format UUID v4 agar nomor urut transaksi tidak dapat ditebak (*anti-enumeration attack*).

---

## 4. Skrip Skema SQL Lengkap (DDL)

```sql
-- =====================================================================
-- AURUMFLOW DATABASE INITIALIZATION SCRIPT
-- Storage Engine: InnoDB (Mendukung ACID Transaction & Foreign Key)
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS profit_distributions;
DROP TABLE IF EXISTS hulu_transactions;
DROP TABLE IF EXISTS daily_settlements;
DROP TABLE IF EXISTS withdrawal_requests;
DROP TABLE IF EXISTS capital_wallets;
DROP TABLE IF EXISTS cash_mutations;
DROP TABLE IF EXISTS cash_registers;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS system_settings;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 1. TABEL PENGATURAN SISTEM (SYSTEM_SETTINGS)
-- ---------------------------------------------------------------------
CREATE TABLE system_settings (
    setting_key VARCHAR(50) NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL,
    description VARCHAR(255) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. TABEL PENGGUNA (USERS)
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id CHAR(36) NOT NULL PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    phone_number VARCHAR(20) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('operator', 'investor') NOT NULL DEFAULT 'investor',
    invite_token VARCHAR(64) NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    
    -- Audit Jejak Hukum Digital (Clickwrap Agreement)
    onboarding_signed_at DATETIME NULL,
    signed_ip_address VARCHAR(45) NULL,
    signed_user_agent TEXT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_role (role),
    INDEX idx_user_token (invite_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. TABEL DOMPET MODAL (CAPITAL_WALLETS)
-- ---------------------------------------------------------------------
CREATE TABLE capital_wallets (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    tier ENUM('operator', 'family', 'external') NOT NULL DEFAULT 'external',
    balance_capital DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Modal pokok yang dititipkan',
    balance_profit DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Dividen yang belum ditarik',
    locked_quota_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Persentase serapan modal terkunci',
    
    -- Kebijakan Penarikan Dana (Exit Policy)
    withdrawal_status ENUM('none', 'queued', 'completed') NOT NULL DEFAULT 'none',
    withdrawal_request_date DATETIME NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_wallet_tier (tier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. TABEL POS FISIK KAS OPERASIONAL (CASH_REGISTERS)
-- ---------------------------------------------------------------------
CREATE TABLE cash_registers (
    id CHAR(36) NOT NULL PRIMARY KEY,
    register_name VARCHAR(50) NOT NULL UNIQUE COMMENT 'Misal: Kas Meja Bakar, Brankas Rumah',
    current_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. TABEL MUTASI KAS INTERNAL (CASH_MUTATIONS)
-- ---------------------------------------------------------------------
CREATE TABLE cash_mutations (
    id CHAR(36) NOT NULL PRIMARY KEY,
    register_id CHAR(36) NOT NULL,
    mutation_type ENUM('in', 'out') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    source_destination VARCHAR(100) NOT NULL COMMENT 'Keterangan sumber/tujuan dana',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mutation_register FOREIGN KEY (register_id) REFERENCES cash_registers (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. TABEL SESI TUTUP BUKU HARIAN (DAILY_SETTLEMENTS)
-- ---------------------------------------------------------------------
CREATE TABLE daily_settlements (
    id CHAR(36) NOT NULL PRIMARY KEY,
    settlement_date DATE NOT NULL,
    total_dry_grams DECIMAL(8,3) NOT NULL COMMENT 'Total gram emas bersih yang dijual ke toko',
    shop_water_density_pct DECIMAL(5,2) NULL COMMENT 'Kadar emas hasil uji timbang air toko',
    shop_price_per_g DECIMAL(14,2) NOT NULL COMMENT 'Harga tebus toko hari itu',
    total_shop_revenue DECIMAL(14,2) NOT NULL COMMENT 'Total kas masuk dari toko',
    
    -- Komponen Beban Bersama & Mitigasi Defisit
    daily_coordination_fee DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Biaya aparat/lapangan',
    consumable_opex DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Pengeluaran jamuan/gas',
    deficit_recovery_used DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Potongan defisit hari sebelumnya',
    
    -- Kalkulasi Bagi Hasil
    net_settled_profit DECIMAL(14,2) NOT NULL COMMENT 'Laba bersih yang siap dibagi',
    operator_share_80 DECIMAL(14,2) NOT NULL COMMENT 'Porsi hak operasional pengelola (80%)',
    investor_pool_20 DECIMAL(14,2) NOT NULL COMMENT 'Porsi hak pool mitra pemodal (20%)',
    carryover_deficit DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Defisit berjalan jika hasil minus',
    
    receipt_photo_url VARCHAR(255) NULL COMMENT 'Path berkas foto nota toko resmi',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_settlement_date (settlement_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. TABEL TRANSAKSI PEMBELIAN HULU (HULU_TRANSACTIONS)
-- ---------------------------------------------------------------------
CREATE TABLE hulu_transactions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    settlement_id CHAR(36) NULL COMMENT 'Diisi saat transaksi dimasukkan ke sesi settlement',
    farmer_alias VARCHAR(50) NOT NULL DEFAULT 'Penambang' COMMENT 'Inisial/alias penambang',
    dry_weight DECIMAL(8,3) NOT NULL COMMENT 'Berat timbangan kering setelah bakar',
    buy_price_per_g DECIMAL(14,2) NOT NULL COMMENT 'Harga tebus lapangan per gram',
    gross_tebus_total DECIMAL(14,2) NOT NULL COMMENT 'Subtotal nilai emas sebelum jasa bakar',
    flat_burn_fee DECIMAL(14,2) NOT NULL DEFAULT 20000.00 COMMENT 'Jasa bakar flat per kedatangan',
    operator_burn_cut DECIMAL(14,2) NOT NULL DEFAULT 10000.00 COMMENT 'Hak upah langsung pengelola',
    consumable_cut DECIMAL(14,2) NOT NULL DEFAULT 10000.00 COMMENT 'Alokasi penggantian kowi/gas',
    net_cash_paid DECIMAL(14,2) NOT NULL COMMENT 'Kas keluar bersih yang diserahkan ke penambang',
    status ENUM('holding', 'settled') NOT NULL DEFAULT 'holding',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_hulu_settlement FOREIGN KEY (settlement_id) REFERENCES daily_settlements (id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_hulu_status (status),
    INDEX idx_hulu_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. TABEL DISTRIBUSI DIVIDEN MITRA (PROFIT_DISTRIBUTIONS)
-- ---------------------------------------------------------------------
CREATE TABLE profit_distributions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    settlement_id CHAR(36) NOT NULL,
    wallet_id CHAR(36) NOT NULL,
    capital_utilized DECIMAL(14,2) NOT NULL COMMENT 'Modal dari wallet ini yang terpakai belanja',
    profit_earned DECIMAL(14,2) NOT NULL COMMENT 'Nominal dividen yang diterima dompet ini',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dist_settlement FOREIGN KEY (settlement_id) REFERENCES daily_settlements (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_dist_wallet FOREIGN KEY (wallet_id) REFERENCES capital_wallets (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_dist_wallet (wallet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. TABEL PERMOHONAN PENARIKAN DANA (WITHDRAWAL_REQUESTS)
-- ---------------------------------------------------------------------
CREATE TABLE withdrawal_requests (
    id CHAR(36) NOT NULL PRIMARY KEY,
    wallet_id CHAR(36) NOT NULL,
    withdrawal_type ENUM('profit', 'capital') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'completed') NOT NULL DEFAULT 'pending',
    notice_period_due DATE NOT NULL COMMENT 'Tanggal jatuh tempo pencairan (H-7)',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_withdraw_wallet FOREIGN KEY (wallet_id) REFERENCES capital_wallets (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 10. TABEL DANA TALANGAN OPERASIONAL (BRIDGE_LOANS)
-- =====================================================================
-- Pinjaman kas tunai jangka pendek dari pihak LUAR (tanpa akun) untuk menutup
-- kas Meja Bakar yang habis pada malam hari. Pokok dikembalikan bersama fee
-- flat satu siklus (T+1) pada tutup buku siang berikutnya.
--
-- Aturan yang tidak boleh dilanggar (PRD § 3.5):
--   * Pokok BUKAN pendapatan — tidak pernah menyentuh daily_settlements.
--   * Fee diserap HANYA dari porsi hak Pengelola (operator_share_80);
--     investor_pool_20 dan balance_profit mitra tidak pernah terpengaruh.
--   * fee_absorbed + fee_uncovered = fee_amount; fee_absorbed <= hak pengelola.
CREATE TABLE bridge_loans (
    id CHAR(36) NOT NULL PRIMARY KEY,
    lender_name VARCHAR(50) NOT NULL COMMENT 'Nama/alias pemberi dana (tanpa akun)',
    principal_amount DECIMAL(14,2) NOT NULL COMMENT 'Pokok yang diterima meja kas',
    fee_type ENUM('pct', 'fixed') NOT NULL COMMENT 'Bentuk fee yang disepakati',
    fee_rate_pct DECIMAL(5,2) NULL COMMENT 'Terisi HANYA bila fee_type = pct',
    fee_amount DECIMAL(14,2) NOT NULL COMMENT 'Fee hasil hitung peladen (kanonik)',
    fee_absorbed DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Porsi fee yang diserap hak Pengelola',
    fee_uncovered DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Porsi fee melebihi hak Pengelola (kas kerja Pengelola)',
    status ENUM('active', 'settled') NOT NULL DEFAULT 'active',
    settlement_id CHAR(36) NULL COMMENT 'Sesi tutup buku yang mengembalikannya',
    register_id CHAR(36) NOT NULL COMMENT 'Pos kas penerima dana',
    received_at DATETIME NOT NULL COMMENT 'Waktu penerimaan (jam peladen)',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_bridge_settlement FOREIGN KEY (settlement_id) REFERENCES daily_settlements (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_bridge_register FOREIGN KEY (register_id) REFERENCES cash_registers (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_bridge_status_created (status, created_at),
    INDEX idx_bridge_settlement (settlement_id),
    INDEX idx_bridge_register (register_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 11. DATA INISIALISASI DASAR (SEED DATA)
-- =====================================================================

INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('allocation_mode', 'waterfall', 'Pilihan mode: waterfall atau prorata'),
('operator_base_quota_pct', '50.00', 'Batas kuota serapan modal utama pengelola (%)'),
('default_burn_fee', '20000.00', 'Biaya flat jasa pembakaran amalgama per kedatangan'),
('default_burn_fee_operator_cut', '10000.00', 'Porsi upah kas langsung pengelola dari jasa bakar'),
('notice_period_days', '7', 'Masa tunggu penarikan modal pokok investor (hari)'),
('active_carryover_deficit', '0.00', 'Akumulasi defisit hari minus yang belum pulih');

INSERT INTO cash_registers (id, register_name, current_balance) VALUES
(UUID(), 'Kas Meja Bakar', 0.00),
(UUID(), 'Brankas Utama', 0.00);
```

---

## 5. Pertimbangan Teknis & Kinerja Basis Data

1. **Jaminan Integritas Saldo:** Relasi *Foreign Key* pada `users`, `capital_wallets`, dan `daily_settlements` menggunakan aturan `ON DELETE RESTRICT` untuk mencegah terhapusnya data pembukuan historis.
2. **Kinerja Kasir Hulu:** Indeks komposit pada `hulu_transactions(status, created_at)` memastikan kueri saat penutupan buku harian berlangsung cepat tanpa pembacaan penuh (*full-table scan*).
3. **Format Kunci UUID:** Kolom `CHAR(36)` memastikan kompabilitas langsung dengan fungsi bawaan PHP `bin2hex(random_bytes(16))` maupun `UUID()` bawaan MySQL di lingkungan shared hosting.
4. **Kinerja Jembatan Likuiditas (Fase 6):** Indeks komposit `bridge_loans(status, created_at)`
   melayani satu-satunya jalur baca terpanas tabel itu — "talangan aktif, urutan FIFO"
   (`WHERE status = 'active' ORDER BY created_at ASC, id ASC`) — yang dijalankan **setiap** sesi
   tutup buku dan setiap render Layar B/C, sehingga tidak ada pembacaan penuh tabel.