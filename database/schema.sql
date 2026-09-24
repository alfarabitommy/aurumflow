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
-- 2. users — pengguna (operator & investor)
-- ----------------------------------------------------------------------------
-- Identitas login adalah `phone_number` (bukan username/email).
-- Kolom audit waiver menyimpan jejak persetujuan digital (clickwrap agreement).
CREATE TABLE IF NOT EXISTS `users` (
    `id`                   CHAR(36)     NOT NULL,
    `full_name`            VARCHAR(100) NOT NULL,
    `phone_number`         VARCHAR(20)  NOT NULL,
    `password_hash`        VARCHAR(255) NOT NULL,
    `role`                 ENUM('operator', 'investor') NOT NULL DEFAULT 'investor',
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
