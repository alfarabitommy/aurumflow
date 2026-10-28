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
       └───────────┬────────────┘            └───────────▲────────────┘
                   │ 1                                   │ 1..1
                   │                                     │ (jejak silang)
                   │ 1..N                                │
       ┌───────────▼────────────┐            ┌───────────┴────────────┐
       │      BRIDGE_LOANS      │            │  MINER_LOAN_LEDGER     │
       │ (talangan operasional, │            │ (riwayat kas kasbon,   │
       │  Fase 6)               │            │  Fase 8)               │
       └───────────▲────────────┘            └───────────▲────────────┘
                   │ 1..N                                │ 1..N
                   │                                     │
                   │ 0..1                    1..N ┌─────┴──────────────────┐
       ┌───────────┴────────────┐        ┌──────────►│      MINER_LOANS       │
       │   DAILY_SETTLEMENTS    │        │           │ (satu baris per        │
       └────────────────────────┘        │           │  pencairan + saldo)    │
                                         │           └───────────▲────────────┘
                                         │                       │ 1
                                         │                       │
                                         │                       │ 1..N
                                         │           ┌───────────┴────────────┐
                                         └───────────┤        MINERS          │
                                                     │ (petani/penambang)     │
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
| `miners` → `miner_loans` | 1 : N | Satu petani dapat menerima beberapa kasbon (pencairan) sepanjang waktu; pencairan baru **tidak** menutup yang lama, sehingga utang berjalan dibaca sebagai `SUM(balance_remaining)` baris `status='open'`. |
| `miner_loans` → `miner_loan_ledger` | 1 : N | Setiap pergerakan kas kasbon (pencairan atau angsuran) menghasilkan **satu** baris ledger dengan `balance_after` pinjaman tersebut. |
| `cash_mutations` → `miner_loan_ledger` | 1 : 1 | Satu baris kas = satu baris ledger (`mutation_id`), sehingga jejak piutang selalu dapat ditelusuri ke mutasi kas fisiknya. |
| `cash_registers` → `miner_loan_ledger` | 1 : N | Seluruh kas kasbon mengalir melalui pos `Kas Meja Bakar`: pencairan sebagai mutasi `out`, angsuran (tunai maupun potong nota) sebagai mutasi `in`. |
| `cash_registers` → `operational_expenses` | 1 : N | Pos kas pembayar beban operasional non-emas. Fase 9 membatasi pembayar ke `Kas Meja Bakar` (laci kasir); kolomnya tetap ada agar beban dari pos lain dapat dicatat tanpa migrasi. |
| `cash_mutations` → `operational_expenses` | 1 : 1 | Satu voucher beban = satu baris kas `out` (`mutation_id`), sehingga setiap rupiah beban dapat ditelusuri ke mutasi kas fisiknya. |
| `daily_settlements` → `operational_expenses` | 1 : N | Satu sesi tutup buku menyerap seluruh voucher yang belum terserap (`settlement_id IS NULL`) sebagai pengurang laba kotor. `ON DELETE SET NULL` membuat fakta kas tetap ada bila sesi dihapus. |

| `users` → `officers` | 1 : 1 | Satu akun berperan `officer` memiliki tepat satu akun buku hak (`officers.user_id` UNIQUE). Akun staf lain tidak pernah memiliki baris ini. |
| `officers` → `officer_ledger_entries` | 1 : N | Satu petugas memiliki banyak entri buku hak: kredit `cut` (akrual potongan tiap tutup buku), kredit `bonus` (suntikan operator), dan debit `disbursement` (pencairan tunai). |
| `daily_settlements` → `officer_ledger_entries` | 1 : N | Satu sesi tutup buku mengakrualkan potongan tiap petugas aktif (`cut`) dan menyerap akrual bonus yang belum terserap (`bonus`, ditandai `settlement_id`). FK `ON DELETE RESTRICT`: entri yang sudah menunjuk sesi tidak boleh membuat bonus aktif kembali (deduksi ganda). |
| `cash_registers` → `officer_ledger_entries` | 1 : N | Satu entri `disbursement` menunjuk pos kas pembayar (Kas Meja Bakar atau Brankas Utama). |
| `cash_mutations` → `officer_ledger_entries` | 1 : 1 | Satu baris kas keluar = satu baris buku hak (`mutation_id` UNIQUE), sehingga setiap pencairan dapat ditelusuri ke mutasi kas fisiknya. |
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
DROP TABLE IF EXISTS operational_expenses;
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

-- =====================================================================
-- SKEMA TOTAL: 14 TABEL
--   1-9  fondasi Fase 1 (system_settings … withdrawal_requests)
--   10   bridge_loans           (dana talangan, Fase 6)
--   11-13 miners, miner_loans, miner_loan_ledger (buku kasbon petani, Fase 8)
--   14   operational_expenses   (beban operasional non-emas / OPEX, Fase 9)
-- Tidak ada tabel lain di luar daftar ini; Fase 7 hanya menambah nilai ENUM.
-- =====================================================================

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
    -- Tiga peran (Fase 7): `operator` = Pengelola, `investor` = Mitra Pemodal,
    -- `cashier` = Kasir Meja Bakar (staf, PIN 6 digit, tanpa dompet modal).
    -- ATURAN: nilai ENUM hanya boleh DITAMBAHKAN DI UJUNG daftar. MySQL/MariaDB
    -- menyimpan nilai ENUM sebagai indeks ordinal, sehingga menyisipkan atau
    -- mengurutkan ulang nilai akan memetakan ulang arti baris yang sudah ada.
    role ENUM('operator', 'investor', 'cashier', 'officer') NOT NULL DEFAULT 'investor',
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
-- Sejak Fase 9 kedua kolom beban di bawah TIDAK diisi operator: nilainya
-- dijumlahkan peladen dari voucher `operational_expenses` yang belum terserap
-- (`settlement_id IS NULL`). `daily_coordination_fee` = subtotal non-konsumsi
-- (coordination/security/other), `consumable_opex` = subtotal `consumable`,
-- sehingga jumlah keduanya = total beban yang dipotong dari laba kotor.
-- Isian manual `coord_fee` dihapus agar beban tidak dapat difiktifkan.
-- ---------------------------------------------------------------------
CREATE TABLE daily_settlements (
    id CHAR(36) NOT NULL PRIMARY KEY,
    settlement_date DATE NOT NULL,
    total_dry_grams DECIMAL(8,3) NOT NULL COMMENT 'Total gram emas bersih yang dijual ke toko',
    shop_water_density_pct DECIMAL(5,2) NULL COMMENT 'Kadar emas hasil uji timbang air toko',
    shop_price_per_g DECIMAL(14,2) NOT NULL COMMENT 'Harga tebus toko hari itu',
    total_shop_revenue DECIMAL(14,2) NOT NULL COMMENT 'Total kas masuk dari toko',
    
    -- Komponen Beban Bersama & Mitigasi Defisit
    daily_coordination_fee DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Biaya aparat/lapangan — diisi dari voucher OPEX non-konsumsi (Fase 9)',
    consumable_opex DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Pengeluaran jamuan/gas — diisi dari voucher kategori consumable (Fase 9)',
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
-- 11. MINERS (master petani/penambang — Fase 8)
-- =====================================================================
-- Data dikumpulkan BERTAHAP: saat pertama kali meminjam, hanya `full_name`
-- yang wajib. `phone_number` dan `location` menyusul pada kedatangan
-- berikutnya, supaya kasir tidak menahan antrean dan petani baru tidak
-- merasa diinterogasi.
-- `phone_number` NULL-able namun UNIQUE: banyak baris NULL diperbolehkan
-- (petani tanpa nomor), sementara satu nomor tidak boleh menjadi dua
-- identitas piutang.
CREATE TABLE IF NOT EXISTS miners (
    id CHAR(36) NOT NULL,
    full_name VARCHAR(100) NOT NULL COMMENT 'Wajib — identitas minimum saat mendaftar',
    phone_number VARCHAR(20) NULL COMMENT 'Opsional; UNIQUE, NULL boleh berulang',
    location VARCHAR(100) NULL COMMENT 'Opsional — kampung/sungai/patok',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_miner_phone (phone_number),
    INDEX idx_miner_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 12. MINER_LOANS (satu baris per pencairan kasbon + saldo berjalan)
-- =====================================================================
-- `issued_on` adalah TANGGAL BISNIS pencairan (bukan `created_at`), karena
-- batas harian `system_settings.daily_loan_limit` dihitung per tanggal itu.
-- `balance_remaining` disimpan (bukan dihitung SUM saat baca) agar laporan
-- tidak perlu menjumlah ulang; invariant yang dijaga peladen:
--   principal_amount - SUM(angsuran ledger) = balance_remaining
CREATE TABLE IF NOT EXISTS miner_loans (
    id CHAR(36) NOT NULL,
    miner_id CHAR(36) NOT NULL,
    principal_amount DECIMAL(14,2) NOT NULL COMMENT 'Pokok saat dicairkan',
    balance_remaining DECIMAL(14,2) NOT NULL COMMENT 'Sisa utang berjalan',
    status ENUM('open', 'settled') NOT NULL DEFAULT 'open',
    issued_on DATE NOT NULL COMMENT 'Tanggal pencairan — dasar batas harian',
    issued_by CHAR(36) NULL COMMENT 'users.id pencatat (NULL bila akun dihapus)',
    settled_at DATETIME NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_loan_miner FOREIGN KEY (miner_id) REFERENCES miners (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_loan_issuer FOREIGN KEY (issued_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_loan_miner_status (miner_id, status),
    INDEX idx_loan_issued_on (issued_on),
    INDEX idx_loan_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 13. MINER_LOAN_LEDGER (riwayat kas kasbon — satu baris kas = satu baris)
-- =====================================================================
-- `amount` selalu positif; arah kas ditentukan `entry_type`:
--   disbursement => cash_mutations.type 'out'
--   repayment    => cash_mutations.type 'in'  (payment_method cash | nota)
-- "Potong Nota" sengaja TIDAK memotong `hulu_transactions`: pembelian hulu
-- tetap dibayar penuh dan angsuran dicatat sebagai kas masuk tersendiri,
-- sehingga modal belanja hulu pada settlement tetap benar.
CREATE TABLE IF NOT EXISTS miner_loan_ledger (
    id CHAR(36) NOT NULL,
    loan_id CHAR(36) NOT NULL,
    entry_type ENUM('disbursement', 'repayment') NOT NULL,
    payment_method ENUM('cash', 'nota') NULL COMMENT 'NULL untuk pencairan',
    amount DECIMAL(14,2) NOT NULL COMMENT 'Selalu positif; arah ditentukan entry_type',
    balance_after DECIMAL(14,2) NOT NULL COMMENT 'Sisa utang pinjaman ini setelah entri',
    register_id CHAR(36) NOT NULL,
    mutation_id CHAR(36) NOT NULL COMMENT 'Satu baris kas = satu baris ledger',
    recorded_by CHAR(36) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_ledger_loan FOREIGN KEY (loan_id) REFERENCES miner_loans (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_ledger_register FOREIGN KEY (register_id) REFERENCES cash_registers (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_ledger_mutation FOREIGN KEY (mutation_id) REFERENCES cash_mutations (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_ledger_recorder FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_ledger_loan (loan_id, created_at),
    INDEX idx_ledger_created (created_at),
    INDEX idx_ledger_register (register_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 14. TABEL BEBAN OPERASIONAL NON-EMAS (OPERATIONAL_EXPENSES — Fase 9)
-- =====================================================================
-- Beban koordinasi lapangan, pengamanan wilayah, konsumsi, dan biaya
-- lain-lain yang dibayarkan TUNAI dari laci kasir (PRD § 3.3).
--
-- Aturan yang tidak boleh dilanggar:
--   * Satu voucher = satu baris kas `out` (`mutation_id`, FK RESTRICT) —
--     pola yang sama dengan `miner_loan_ledger`.
--   * `amount` selalu positif; arah kas selalu keluar.
--   * Beban mengurangi laba kotor pada tutup buku, BUKAN mengurangi kas yang
--     dialokasikan: settlement mengkredit pendapatan toko penuh
--     (`total_cash = pendapatan`), karena uangnya sudah keluar lebih dahulu
--     dari laci. Mengurangi dua-duanya menyebabkan selisih buku-vs-fisik
--     tepat sebesar beban (lihat audit/Audit_Biaya_Koordinasi_AurumFlow.md § 5.5).
--   * `settlement_id` memakai ON DELETE SET NULL: beban adalah fakta kas yang
--     berdiri sendiri dan tetap ada bila sesi tutup buku dihapus.
--   * Nilai ENUM `category` bersifat append-only (tambahkan di ujung daftar).
CREATE TABLE IF NOT EXISTS operational_expenses (
    id CHAR(36) NOT NULL,
    expense_date DATE NOT NULL COMMENT 'Tanggal bisnis pembayaran (bukan created_at)',
    category ENUM('coordination', 'security', 'consumable', 'other') NOT NULL DEFAULT 'coordination',
    payee_name VARCHAR(100) NOT NULL COMMENT 'Nama/alias penerima (aparat, petugas, dst.)',
    amount DECIMAL(14,2) NOT NULL COMMENT 'Selalu positif; arah kas selalu keluar',
    register_id CHAR(36) NOT NULL COMMENT 'Pos kas pembayar (Fase 9: Kas Meja Bakar)',
    mutation_id CHAR(36) NOT NULL COMMENT 'Jejak silang ke cash_mutations',
    settlement_id CHAR(36) NULL COMMENT 'Sesi tutup buku yang menyerap beban ini',
    recorded_by CHAR(36) NULL COMMENT 'users.id pencatat',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_expense_register FOREIGN KEY (register_id) REFERENCES cash_registers (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_expense_mutation FOREIGN KEY (mutation_id) REFERENCES cash_mutations (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_expense_settlement FOREIGN KEY (settlement_id) REFERENCES daily_settlements (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_expense_recorder FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_expense_date (expense_date),
    INDEX idx_expense_open (settlement_id, expense_date),
    INDEX idx_expense_register (register_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 15. OFFICERS (akun buku hak mitra lapangan / aparat — Fase 10)
-- =====================================================================
-- 1 : 1 dengan akun login berperan `officer`. Baris ini adalah AKUN BUKU,
-- bukan tabel kredensial: PIN tetap ber-pepper pada `users.password_hash`.
--
-- Aturan yang tidak boleh dilanggar:
--   * `cut_pct` adalah persentase potongan dari **laba kotor** harian
--     (pendapatan toko − modal belanja hulu), bukan nominal tetap.
--   * `balance_payable` = Σ kredit (`cut` + `bonus`) − Σ debit (`disbursement`).
--     Uangnya terparkir di laci sampai operator mencairkannya, karena tutup
--     buku mengkredit pendapatan toko penuh (`total_cash = pendapatan`) dan
--     hanya memindahkan laba bersih ke brankas.
--   * Tidak ada kolom status: aktif/nonaktif milik `users.is_active`
--     (satu sumber kebenaran, pola Fase 7).
CREATE TABLE IF NOT EXISTS officers (
    id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL COMMENT 'Akun login petugas (1:1)',
    cut_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Potongan dari laba kotor (%)',
    balance_payable DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Saldo hak yang belum dicairkan',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_officer_user (user_id),
    KEY idx_officer_pct (cut_pct),
    CONSTRAINT fk_officer_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 16. OFFICER_LEDGER_ENTRIES (buku hak mitra lapangan — Fase 10)
-- =====================================================================
-- `amount` SELALU positif; arah ditentukan `entry_type`:
--   cut          kredit; akrual potongan sesi tutup buku
--                (settlement_id, cut_pct, base_amount WAJIB terisi)
--   bonus        kredit; suntikan operator. Selama `settlement_id` NULL entri
--                ini BELUM mengurangi laba; tutup buku berikutnya menyerapnya
--                (menandai settlement_id) tepat SATU kali — tanpa voucher
--                operational_expenses, sehingga tidak ada deduksi ganda.
--   disbursement debit; pencairan tunai (register_id + mutation_id terisi)
--
-- `settlement_id` memakai ON DELETE RESTRICT — BERBEDA dari
-- `operational_expenses.settlement_id` (SET NULL): SET NULL akan mengaktifkan
-- ulang bonus yang sudah terserap sehingga laba terpotong dua kali.
CREATE TABLE IF NOT EXISTS officer_ledger_entries (
    id CHAR(36) NOT NULL,
    officer_id CHAR(36) NOT NULL,
    entry_type ENUM('cut', 'bonus', 'disbursement') NOT NULL,
    amount DECIMAL(14,2) NOT NULL COMMENT 'Selalu positif; arah ditentukan entry_type',
    balance_after DECIMAL(14,2) NOT NULL COMMENT 'Saldo hak setelah entri ini',
    cut_pct DECIMAL(5,2) NULL COMMENT 'Snapshot tarif saat akrual (cut saja)',
    base_amount DECIMAL(14,2) NULL COMMENT 'Laba kotor basis potongan (cut saja; tidak pernah tampil di portal petugas)',
    settlement_id CHAR(36) NULL COMMENT 'cut: sesi akrual; bonus: sesi penyerap; disbursement: NULL',
    register_id CHAR(36) NULL COMMENT 'Pos kas pembayar (disbursement saja)',
    mutation_id CHAR(36) NULL COMMENT 'Jejak silang ke cash_mutations (disbursement saja)',
    recorded_by CHAR(36) NULL COMMENT 'users.id pencatat',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_entry_mutation (mutation_id),
    KEY idx_entry_officer (officer_id, created_at),
    KEY idx_entry_open (entry_type, settlement_id),
    KEY idx_entry_settlement (settlement_id, entry_type),
    KEY idx_entry_register (register_id),
    CONSTRAINT fk_entry_officer FOREIGN KEY (officer_id) REFERENCES officers (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_entry_settlement FOREIGN KEY (settlement_id) REFERENCES daily_settlements (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_entry_register FOREIGN KEY (register_id) REFERENCES cash_registers (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_entry_mutation FOREIGN KEY (mutation_id) REFERENCES cash_mutations (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_entry_recorder FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 17. DATA INISIALISASI DASAR (SEED DATA)
-- =====================================================================

INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('allocation_mode', 'waterfall', 'Pilihan mode: waterfall atau prorata'),
('operator_base_quota_pct', '50.00', 'Batas kuota serapan modal utama pengelola (%)'),
('default_burn_fee', '20000.00', 'Biaya flat jasa pembakaran amalgama per kedatangan'),
('default_burn_fee_operator_cut', '10000.00', 'Porsi upah kas langsung pengelola dari jasa bakar'),
('notice_period_days', '7', 'Masa tunggu penarikan modal pokok investor (hari)'),
('active_carryover_deficit', '0.00', 'Akumulasi defisit hari minus yang belum pulih'),
('daily_loan_limit', '500000.00', 'Batas total pokok kasbon yang boleh dicairkan per hari (Rp)'),
('officer_cut_total_cap_pct', '100.00', 'Batas atas jumlah persentase potongan seluruh mitra lapangan aktif (%)');

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
5. **Migrasi Aditif Peran Kasir (Fase 7):** penambahan nilai `'cashier'` pada `users.role`
   dilakukan sebagai `ALTER TABLE users MODIFY COLUMN role ENUM('operator','investor','cashier','officer') …`
   — **hanya menambah di ujung daftar**, sehingga menjadi perubahan metadata *in-place* pada
   MySQL 8.0 maupun MariaDB (tanpa penyalinan tabel, tanpa penulisan ulang baris). Instalasi
   lapangan menjalankan `php database/migrate.php` yang **terjaga** (`INFORMATION_SCHEMA`) dan
   **idempoten**; `database/schema.sql` sudah memuat nilai itu untuk instalasi baru sehingga
   **tidak ada tabel maupun kolom baru** dan tidak ada `ALTER` yang bersifat destruktif.
   Tidak ada kolom kredensial tambahan: PIN kasir disimpan ber-pepper pada kolom
   `users.password_hash` yang sudah ada (`role` adalah pembeda pintu masuk).
6. **Buku Kasbon Petani (Fase 8):** tabel **11–13** ditambahkan sebagai **tabel baru**
   (`miners`, `miner_loans`, `miner_loan_ledger`) — **nol `ALTER`** pada tabel lama, sehingga
   instalasi lapangan cukup menjalankan `php database/migrate.php` (migrasi **M2**, terjaga
   `INFORMATION_SCHEMA`, idempoten) atau `database/install.php` untuk instalasi baru. Tiga
   indeks melayani tiga jalur baca terpanas: `idx_loan_issued_on` (Σ pokok hari ini untuk
   batas harian), `idx_loan_miner_status` (utang berjalan per petani + kunci FIFO
   `WHERE miner_id = ? AND status = 'open' ORDER BY issued_on ASC, created_at ASC, id ASC`),
   dan `idx_ledger_created` (riwayat 20 entri terakhir). `miner_loan_ledger.mutation_id`
   memakai `ON DELETE RESTRICT` sehingga satu baris kas tidak dapat dihapus tanpa menghapus
   jejak piutangnya; `issued_by`/`recorded_by` memakai `ON DELETE SET NULL` karena akun staf
   boleh dihapus sementara riwayat kasbon wajib tetap terbaca.
7. **Beban Operasional Non-Emas (Fase 9):** tabel **14** (`operational_expenses`) ditambahkan
   sebagai **tabel baru** — **nol `ALTER`** pada tabel lama. Instalasi lapangan menjalankan
   `php database/migrate.php` (migrasi **M3**, terjaga `INFORMATION_SCHEMA`, idempoten; DDL-nya
   hidup di `OperationalExpense::ensureSchema()`). Dua indeks melayani dua jalur baca terpanas:
   `idx_expense_open (settlement_id, expense_date)` untuk "voucher belum terserap, urut
   `expense_date ASC, created_at ASC, id ASC`" yang dijalankan setiap render Layar C dan setiap
   tutup buku, dan `idx_expense_date` untuk rekap harian/bulanan. `mutation_id` memakai
   `ON DELETE RESTRICT` (satu baris kas tidak boleh dihapus tanpa jejak bebannya) sedangkan
   `settlement_id` memakai `ON DELETE SET NULL` (beban tetap ada bila sesi dihapus).