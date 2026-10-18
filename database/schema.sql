-- ============================================================================
-- AurumFlow — Skema Database Fase 1
-- ============================================================================
-- SUMBER KEBENARAN: docs/AurumFlow Entity Relationship Diagram.md § 4
-- (Dokumen ini adalah salinan yang dapat dieksekusi dari DDL pada ERD.)
--
-- Target produksi : MySQL 8.0 / InnoDB
-- Kompatibel uji  : MariaDB 10.11+ (dipakai pada QC Fase 1)
--
-- Konvensi tipe data (ERD § 3.1) — WAJIB dipatuhi pada seluruh fase:
--   * Mata uang / finansial : DECIMAL(14,2)
--   * Timbangan emas        : DECIMAL(8,3)   (presisi 0,001 gram)
--   * Persentase            : DECIMAL(5,2)
--   * Primary key           : CHAR(36) UUID v4 (anti-enumeration)
--   * FLOAT/DOUBLE DILARANG untuk nilai moneter maupun berat.
--
-- ---------------------------------------------------------------------------
-- PERBEDAAN YANG DISENGAJA DARI ERD (dan alasannya)
-- ---------------------------------------------------------------------------
-- 1. ERD memakai `DROP TABLE IF EXISTS` di awal skrip. Di sini pernyataan itu
--    DIMATIKAN (lihat blok "RESET" di bawah) dan diganti `CREATE TABLE IF NOT
--    EXISTS`, karena:
--      - instalasi harus idempotent dan aman dijalankan ulang (QC 4.2);
--      - skrip ini dijalankan oleh database/install.php pada hosting produksi
--        tempat satu salah ketik berarti kehilangan seluruh pembukuan.
--    Untuk membangun ulang dari nol, buka komentar blok RESET secara sadar.
--
-- 2. ERD § 5.3 menyebut CHAR(36) "kompatibel dengan bin2hex(random_bytes(16))".
--    Itu tidak akurat: bin2hex(random_bytes(16)) menghasilkan 32 karakter hex
--    TANPA tanda hubung, bukan 36. Karena itu aplikasi memakai
--    Security::uuidV4() yang membentuk UUID v4 sesuai RFC 4122.
-- ============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- RESET (OPSIONAL — buka komentar hanya bila ingin membangun ulang dari nol)
-- ----------------------------------------------------------------------------
-- SET FOREIGN_KEY_CHECKS = 0;
-- DROP TABLE IF EXISTS profit_distributions;
-- DROP TABLE IF EXISTS hulu_transactions;
-- DROP TABLE IF EXISTS daily_settlements;
-- DROP TABLE IF EXISTS withdrawal_requests;
-- DROP TABLE IF EXISTS capital_wallets;
-- DROP TABLE IF EXISTS cash_mutations;
-- DROP TABLE IF EXISTS cash_registers;
-- DROP TABLE IF EXISTS users;
-- DROP TABLE IF EXISTS system_settings;
-- SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------------
-- 1. system_settings — pengaturan sistem (key/value)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_settings` (
    `setting_key`   VARCHAR(50)  NOT NULL,
    `setting_value` TEXT         NOT NULL,
    `description`   VARCHAR(255) NULL,
    `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. users — pengguna (operator, investor, & kasir)
-- ----------------------------------------------------------------------------
-- Identitas login adalah `phone_number` (bukan username/email).
-- Kolom audit waiver menyimpan jejak persetujuan digital (clickwrap agreement).
-- Sejak Fase 7 ada tiga peran. ATURAN: nilai ENUM hanya boleh DITAMBAHKAN DI
-- UJUNG daftar (MySQL/MariaDB menyimpan ENUM sebagai indeks ordinal, sehingga
-- penyisipan di tengah memetakan ulang arti baris yang sudah ada); instalasi
-- lama menjalankan `php database/migrate.php` yang menjaga aturan itu.
CREATE TABLE IF NOT EXISTS `users` (
    `id`                   CHAR(36)     NOT NULL,
    `full_name`            VARCHAR(100) NOT NULL,
    `phone_number`         VARCHAR(20)  NOT NULL,
    `password_hash`        VARCHAR(255) NOT NULL,
    `role`                 ENUM('operator', 'investor', 'cashier') NOT NULL DEFAULT 'investor',
    `invite_token`         VARCHAR(64)  NULL,
    `is_active`            TINYINT(1)   NOT NULL DEFAULT 1,

    -- Audit jejak hukum digital (clickwrap agreement)
    `onboarding_signed_at` DATETIME     NULL,
    `signed_ip_address`    VARCHAR(45)  NULL,
    `signed_user_agent`    TEXT         NULL,

    `created_at`           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_phone` (`phone_number`),
    UNIQUE KEY `uq_users_invite_token` (`invite_token`),
    KEY `idx_user_role` (`role`),
    KEY `idx_user_token` (`invite_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. capital_wallets — dompet modal mitra pemodal
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `capital_wallets` (
    `id`                     CHAR(36)     NOT NULL,
    `user_id`                CHAR(36)     NOT NULL,
    `tier`                   ENUM('operator', 'family', 'external') NOT NULL DEFAULT 'external',
    `balance_capital`        DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Modal pokok yang dititipkan',
    `balance_profit`         DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Dividen yang belum ditarik',
    `locked_quota_pct`       DECIMAL(5,2)  NOT NULL DEFAULT 0.00 COMMENT 'Persentase serapan modal terkunci',

    -- Kebijakan penarikan dana (exit policy)
    `withdrawal_status`      ENUM('none', 'queued', 'completed') NOT NULL DEFAULT 'none',
    `withdrawal_request_date` DATETIME    NULL,

    `created_at`             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_wallet_user` (`user_id`),
    KEY `idx_wallet_tier` (`tier`),
    CONSTRAINT `fk_wallet_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. cash_registers — pos fisik kas operasional
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cash_registers` (
    `id`              CHAR(36)      NOT NULL,
    `register_name`   VARCHAR(50)   NOT NULL COMMENT 'Misal: Kas Meja Bakar, Brankas Rumah',
    `current_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `updated_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_register_name` (`register_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. cash_mutations — mutasi kas internal
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cash_mutations` (
    `id`                 CHAR(36)      NOT NULL,
    `register_id`        CHAR(36)      NOT NULL,
    `mutation_type`      ENUM('in', 'out') NOT NULL,
    `amount`             DECIMAL(14,2) NOT NULL,
    `source_destination` VARCHAR(100)  NOT NULL COMMENT 'Keterangan sumber/tujuan dana',
    `notes`              TEXT          NULL,
    `created_at`         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_mutation_register` (`register_id`),
    CONSTRAINT `fk_mutation_register` FOREIGN KEY (`register_id`) REFERENCES `cash_registers` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. daily_settlements — sesi tutup buku harian
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `daily_settlements` (
    `id`                    CHAR(36)      NOT NULL,
    `settlement_date`       DATE          NOT NULL,
    `total_dry_grams`       DECIMAL(8,3)  NOT NULL COMMENT 'Total gram emas bersih yang dijual ke toko',
    `shop_water_density_pct` DECIMAL(5,2) NULL COMMENT 'Kadar emas hasil uji timbang air toko',
    `shop_price_per_g`      DECIMAL(14,2) NOT NULL COMMENT 'Harga tebus toko hari itu',
    `total_shop_revenue`    DECIMAL(14,2) NOT NULL COMMENT 'Total kas masuk dari toko',

    -- Komponen beban bersama & mitigasi defisit
    `daily_coordination_fee` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Biaya aparat/lapangan',
    `consumable_opex`        DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Pengeluaran jamuan/gas',
    `deficit_recovery_used`  DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Potongan defisit hari sebelumnya',

    -- Kalkulasi bagi hasil
    `net_settled_profit`     DECIMAL(14,2) NOT NULL COMMENT 'Laba bersih yang siap dibagi',
    `operator_share_80`      DECIMAL(14,2) NOT NULL COMMENT 'Porsi hak operasional pengelola (80%)',
    `investor_pool_20`       DECIMAL(14,2) NOT NULL COMMENT 'Porsi hak pool mitra pemodal (20%)',
    `carryover_deficit`      DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Defisit berjalan jika hasil minus',

    `receipt_photo_url`      VARCHAR(255)  NULL COMMENT 'Path berkas foto nota toko resmi',
    `created_at`             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_settlement_date` (`settlement_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. hulu_transactions — transaksi pembelian hulu (meja kasir)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hulu_transactions` (
    `id`                 CHAR(36)      NOT NULL,
    `settlement_id`      CHAR(36)      NULL COMMENT 'Diisi saat transaksi dimasukkan ke sesi settlement',
    `farmer_alias`       VARCHAR(50)   NOT NULL DEFAULT 'Penambang' COMMENT 'Inisial/alias penambang',
    `dry_weight`         DECIMAL(8,3)  NOT NULL COMMENT 'Berat timbangan kering setelah bakar',
    `buy_price_per_g`    DECIMAL(14,2) NOT NULL COMMENT 'Harga tebus lapangan per gram',
    `gross_tebus_total`  DECIMAL(14,2) NOT NULL COMMENT 'Subtotal nilai emas sebelum jasa bakar',
    `flat_burn_fee`      DECIMAL(14,2) NOT NULL DEFAULT 20000.00 COMMENT 'Jasa bakar flat per kedatangan',
    `operator_burn_cut`  DECIMAL(14,2) NOT NULL DEFAULT 10000.00 COMMENT 'Hak upah langsung pengelola',
    `consumable_cut`     DECIMAL(14,2) NOT NULL DEFAULT 10000.00 COMMENT 'Alokasi penggantian kowi/gas',
    `net_cash_paid`      DECIMAL(14,2) NOT NULL COMMENT 'Kas keluar bersih yang diserahkan ke penambang',
    `status`             ENUM('holding', 'settled') NOT NULL DEFAULT 'holding',
    `created_at`         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hulu_status` (`status`),
    KEY `idx_hulu_created` (`created_at`),
    KEY `idx_hulu_status_created` (`status`, `created_at`),
    CONSTRAINT `fk_hulu_settlement` FOREIGN KEY (`settlement_id`) REFERENCES `daily_settlements` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 8. profit_distributions — distribusi dividen mitra
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `profit_distributions` (
    `id`               CHAR(36)      NOT NULL,
    `settlement_id`    CHAR(36)      NOT NULL,
    `wallet_id`        CHAR(36)      NOT NULL,
    `capital_utilized` DECIMAL(14,2) NOT NULL COMMENT 'Modal dari wallet ini yang terpakai belanja',
    `profit_earned`    DECIMAL(14,2) NOT NULL COMMENT 'Nominal dividen yang diterima dompet ini',
    `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_dist_wallet` (`wallet_id`),
    KEY `idx_dist_settlement` (`settlement_id`),
    CONSTRAINT `fk_dist_settlement` FOREIGN KEY (`settlement_id`) REFERENCES `daily_settlements` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_dist_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `capital_wallets` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 9. withdrawal_requests — permohonan penarikan dana
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `withdrawal_requests` (
    `id`               CHAR(36)      NOT NULL,
    `wallet_id`        CHAR(36)      NOT NULL,
    `withdrawal_type`  ENUM('profit', 'capital') NOT NULL,
    `amount`           DECIMAL(14,2) NOT NULL,
    `status`           ENUM('pending', 'approved', 'rejected', 'completed') NOT NULL DEFAULT 'pending',
    `notice_period_due` DATE         NOT NULL COMMENT 'Tanggal jatuh tempo pencairan (H-7)',
    `notes`            TEXT          NULL,
    `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_withdraw_wallet` (`wallet_id`),
    KEY `idx_withdraw_status` (`status`),
    CONSTRAINT `fk_withdraw_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `capital_wallets` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 10. bridge_loans — dana talangan operasional (Fase 6)
-- ----------------------------------------------------------------------------
-- Pinjaman kas tunai jangka pendek dari pihak LUAR (tanpa akun) untuk menutup
-- kas Meja Bakar yang habis pada malam hari. Pokok dikembalikan bersama fee
-- flat satu siklus (T+1) pada tutup buku siang berikutnya.
--
-- Aturan yang tidak boleh dilanggar (PRD § 3.5):
--   * Pokok BUKAN pendapatan — tidak pernah menyentuh `daily_settlements`.
--   * Fee diserap HANYA dari `daily_settlements.operator_share_80`;
--     `investor_pool_20` dan `capital_wallets.balance_profit` tidak pernah
--     terpengaruh adanya talangan.
--   * `fee_absorbed + fee_uncovered = fee_amount`, `fee_absorbed <= hak pengelola`.
--   * Sejak Fase 6 tutup buku menulis TUJUH kelompok tulisan; tabel ini adalah
--     kelompok ketujuh (lihat AGENTS.md).
CREATE TABLE IF NOT EXISTS `bridge_loans` (
    `id`               CHAR(36)      NOT NULL,
    `lender_name`      VARCHAR(50)   NOT NULL COMMENT 'Nama/alias pemberi dana (tanpa akun)',
    `principal_amount` DECIMAL(14,2) NOT NULL COMMENT 'Pokok yang diterima meja kas',
    `fee_type`         ENUM('pct', 'fixed') NOT NULL COMMENT 'Bentuk fee yang disepakati',
    `fee_rate_pct`     DECIMAL(5,2)  NULL COMMENT 'Terisi HANYA bila fee_type = pct',
    `fee_amount`       DECIMAL(14,2) NOT NULL COMMENT 'Fee hasil hitung peladen (kanonik)',
    `fee_absorbed`     DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Porsi fee diserap hak pengelola',
    `fee_uncovered`    DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Porsi fee melebihi hak pengelola (kas kerja pengelola)',
    `status`           ENUM('active', 'settled') NOT NULL DEFAULT 'active',
    `settlement_id`    CHAR(36)      NULL COMMENT 'Sesi tutup buku yang mengembalikannya',
    `register_id`      CHAR(36)      NOT NULL COMMENT 'Pos kas penerima dana',
    `received_at`      DATETIME      NOT NULL COMMENT 'Waktu penerimaan (jam peladen)',
    `notes`            TEXT          NULL,
    `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bridge_status_created` (`status`, `created_at`),
    KEY `idx_bridge_settlement` (`settlement_id`),
    KEY `idx_bridge_register` (`register_id`),
    CONSTRAINT `fk_bridge_settlement` FOREIGN KEY (`settlement_id`) REFERENCES `daily_settlements` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_bridge_register` FOREIGN KEY (`register_id`) REFERENCES `cash_registers` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 11. miners — master petani/penambang (Fase 8)
-- ----------------------------------------------------------------------------
-- Data dikumpulkan BERTAHAP: saat pertama kali meminjam, hanya `full_name` yang
-- wajib. `phone_number` dan `location` menyusul pada kedatangan berikutnya,
-- supaya kasir tidak menahan antrean dan petani baru tidak merasa diinterogasi.
--
-- `phone_number` NULL-able namun UNIQUE: banyak baris NULL diperbolehkan (petani
-- tanpa nomor), sementara satu nomor tidak boleh menjadi dua identitas piutang.
-- Nomor yang dipakai bersama dalam satu keluarga → daftarkan tanpa nomor.
CREATE TABLE IF NOT EXISTS `miners` (
    `id`           CHAR(36)     NOT NULL,
    `full_name`    VARCHAR(100) NOT NULL COMMENT 'Wajib — identitas minimum saat mendaftar',
    `phone_number` VARCHAR(20)  NULL COMMENT 'Opsional; UNIQUE, NULL boleh berulang',
    `location`     VARCHAR(100) NULL COMMENT 'Opsional — kampung/sungai/patok',
    `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_miner_phone` (`phone_number`),
    KEY `idx_miner_name` (`full_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 12. miner_loans — satu baris per pencairan kasbon + saldo berjalan (Fase 8)
-- ----------------------------------------------------------------------------
-- `issued_on` adalah TANGGAL BISNIS pencairan (bukan `created_at`), karena batas
-- harian `system_settings.daily_loan_limit` dihitung per tanggal itu.
--
-- `balance_remaining` disimpan (bukan SUM saat baca) supaya laporan tidak perlu
-- menjumlah ulang; invariant yang dijaga peladen:
--   principal_amount − SUM(angsuran pada ledger) = balance_remaining
--
-- `issued_by` memakai ON DELETE SET NULL (bukan RESTRICT) karena akun staf boleh
-- dihapus sementara riwayat kasbon wajib tetap terbaca; `miner_id` memakai
-- RESTRICT karena petani yang punya riwayat tidak boleh hilang.
CREATE TABLE IF NOT EXISTS `miner_loans` (
    `id`                CHAR(36)      NOT NULL,
    `miner_id`          CHAR(36)      NOT NULL,
    `principal_amount`  DECIMAL(14,2) NOT NULL COMMENT 'Pokok saat dicairkan',
    `balance_remaining` DECIMAL(14,2) NOT NULL COMMENT 'Sisa utang berjalan',
    `status`            ENUM('open', 'settled') NOT NULL DEFAULT 'open',
    `issued_on`         DATE          NOT NULL COMMENT 'Tanggal pencairan — dasar batas harian',
    `issued_by`         CHAR(36)      NULL COMMENT 'users.id pencatat (NULL bila akun dihapus)',
    `settled_at`        DATETIME      NULL,
    `notes`             TEXT          NULL,
    `created_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_loan_miner_status` (`miner_id`, `status`),
    KEY `idx_loan_issued_on` (`issued_on`),
    KEY `idx_loan_status` (`status`),
    CONSTRAINT `fk_loan_miner` FOREIGN KEY (`miner_id`) REFERENCES `miners` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_loan_issuer` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 13. miner_loan_ledger — riwayat kas kasbon (Fase 8)
-- ----------------------------------------------------------------------------
-- Satu baris kas = satu baris ledger (`mutation_id`), sehingga jejak piutang
-- selalu dapat ditelusuri ke mutasi kas fisiknya. `amount` SELALU positif; arah
-- kas ditentukan `entry_type`:
--   disbursement => cash_mutations.mutation_type 'out'
--   repayment    => cash_mutations.mutation_type 'in' (payment_method cash|nota)
--
-- "Potong Nota" sengaja TIDAK memotong `hulu_transactions`: pembelian hulu tetap
-- dibayar penuh dan angsuran dicatat sebagai kas masuk tersendiri, sehingga
-- `modal belanja hulu` pada settlement tetap benar (lihat AGENTS.md).
--
-- `mutation_id` memakai ON DELETE RESTRICT: satu baris kas tidak boleh dihapus
-- tanpa menghapus jejak piutangnya. Konsekuensi QC: baris ledger dibersihkan
-- SEBELUM `cash_mutations` (QcFixture::resetKasbonState()).
CREATE TABLE IF NOT EXISTS `miner_loan_ledger` (
    `id`             CHAR(36)      NOT NULL,
    `loan_id`        CHAR(36)      NOT NULL,
    `entry_type`     ENUM('disbursement', 'repayment') NOT NULL,
    `payment_method` ENUM('cash', 'nota') NULL COMMENT 'NULL untuk pencairan',
    `amount`         DECIMAL(14,2) NOT NULL COMMENT 'Selalu positif; arah ditentukan entry_type',
    `balance_after`  DECIMAL(14,2) NOT NULL COMMENT 'Sisa utang PINJAMAN INI setelah entri',
    `register_id`    CHAR(36)      NOT NULL,
    `mutation_id`    CHAR(36)      NOT NULL COMMENT 'Jejak silang ke cash_mutations',
    `recorded_by`    CHAR(36)      NULL,
    `notes`          TEXT          NULL,
    `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ledger_loan` (`loan_id`, `created_at`),
    KEY `idx_ledger_created` (`created_at`),
    KEY `idx_ledger_register` (`register_id`),
    CONSTRAINT `fk_ledger_loan` FOREIGN KEY (`loan_id`) REFERENCES `miner_loans` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_ledger_register` FOREIGN KEY (`register_id`) REFERENCES `cash_registers` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_ledger_mutation` FOREIGN KEY (`mutation_id`) REFERENCES `cash_mutations` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_ledger_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
