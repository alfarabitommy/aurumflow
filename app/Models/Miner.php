<?php

declare(strict_types=1);

namespace AurumFlow\Models;

use AurumFlow\Config\Database;

/**
 * Model tabel `miners` — master petani/penambang (Fase 8).
 *
 * Profil dikumpulkan BERTAHAP (rencana Fase 8 § 1.1): saat pertama kali meminjam,
 * hanya `full_name` yang wajib. `phone_number` dan `location` opsional supaya
 * kasir tidak menahan antrean dan petani baru tidak merasa diinterogasi.
 *
 * `phone_number` NULL-able namun UNIQUE (keputusan D-7): banyak baris NULL
 * diperbolehkan (petani tanpa nomor), sementara satu nomor tidak boleh menjadi
 * dua identitas piutang. Nomor yang dipakai bersama dalam satu keluarga →
 * daftarkan tanpa nomor (lihat `KasbonService::registerMiner()`).
 *
 * Kelas ini juga memegang DDL tiga tabel kasbon (`ensureSchema()`), mengikuti
 * pola `User::ensureRoleEnum()`: SQL hanya hidup di `app/Models/`, dan jalur
 * migrasi lapangan (`database/migrate.php` M2) tidak menulis DDL sendiri.
 */
final class Miner
{
    /** Kolom yang boleh ditulis (penjaga salah ketik nama kolom). */
    private const COLUMNS = [
        'id',
        'full_name',
        'phone_number',
        'location',
    ];

    /** Kolom NOT NULL tanpa DEFAULT pada DDL (ERD § 4 point 11). */
    private const REQUIRED_COLUMNS = ['id', 'full_name'];

    /** Kolom yang dibaca (tanpa `SELECT *`). */
    private const READ_COLUMNS = '`id`, `full_name`, `phone_number`, `location`, `created_at`, `updated_at`';

    /**
     * DDL tiga tabel kasbon — SALINAN dari `database/schema.sql` (sumber
     * kebenaran ERD). `CREATE TABLE IF NOT EXISTS` membuat `ensureSchema()`
     * idempoten: instalasi baru memakai `schema.sql`, instalasi lapangan
     * memakai jalur ini lewat `database/migrate.php`.
     *
     * @var list<string>
     */
    private const SCHEMA_STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS `miners` ('
        . ' `id` CHAR(36) NOT NULL,'
        . ' `full_name` VARCHAR(100) NOT NULL,'
        . ' `phone_number` VARCHAR(20) NULL,'
        . ' `location` VARCHAR(100) NULL,'
        . ' `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . ' `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
        . ' PRIMARY KEY (`id`),'
        . ' UNIQUE KEY `uq_miner_phone` (`phone_number`),'
        . ' KEY `idx_miner_name` (`full_name`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS `miner_loans` ('
        . ' `id` CHAR(36) NOT NULL,'
        . ' `miner_id` CHAR(36) NOT NULL,'
        . ' `principal_amount` DECIMAL(14,2) NOT NULL,'
        . ' `balance_remaining` DECIMAL(14,2) NOT NULL,'
        . ' `status` ENUM(\'open\', \'settled\') NOT NULL DEFAULT \'open\','
        . ' `issued_on` DATE NOT NULL,'
        . ' `issued_by` CHAR(36) NULL,'
        . ' `settled_at` DATETIME NULL,'
        . ' `notes` TEXT NULL,'
        . ' `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . ' `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
        . ' PRIMARY KEY (`id`),'
        . ' KEY `idx_loan_miner_status` (`miner_id`, `status`),'
        . ' KEY `idx_loan_issued_on` (`issued_on`),'
        . ' KEY `idx_loan_status` (`status`),'
        . ' CONSTRAINT `fk_loan_miner` FOREIGN KEY (`miner_id`) REFERENCES `miners` (`id`)'
        . ' ON DELETE RESTRICT ON UPDATE CASCADE,'
        . ' CONSTRAINT `fk_loan_issuer` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`)'
        . ' ON DELETE SET NULL ON UPDATE CASCADE'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS `miner_loan_ledger` ('
        . ' `id` CHAR(36) NOT NULL,'
        . ' `loan_id` CHAR(36) NOT NULL,'
        . ' `entry_type` ENUM(\'disbursement\', \'repayment\') NOT NULL,'
        . ' `payment_method` ENUM(\'cash\', \'nota\') NULL,'
        . ' `amount` DECIMAL(14,2) NOT NULL,'
        . ' `balance_after` DECIMAL(14,2) NOT NULL,'
        . ' `register_id` CHAR(36) NOT NULL,'
        . ' `mutation_id` CHAR(36) NOT NULL,'
        . ' `recorded_by` CHAR(36) NULL,'
        . ' `notes` TEXT NULL,'
        . ' `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . ' PRIMARY KEY (`id`),'
        . ' KEY `idx_ledger_loan` (`loan_id`, `created_at`),'
        . ' KEY `idx_ledger_created` (`created_at`),'
        . ' KEY `idx_ledger_register` (`register_id`),'
        . ' CONSTRAINT `fk_ledger_loan` FOREIGN KEY (`loan_id`) REFERENCES `miner_loans` (`id`)'
        . ' ON DELETE RESTRICT ON UPDATE CASCADE,'
        . ' CONSTRAINT `fk_ledger_register` FOREIGN KEY (`register_id`) REFERENCES `cash_registers` (`id`)'
        . ' ON DELETE RESTRICT ON UPDATE CASCADE,'
        . ' CONSTRAINT `fk_ledger_mutation` FOREIGN KEY (`mutation_id`) REFERENCES `cash_mutations` (`id`)'
        . ' ON DELETE RESTRICT ON UPDATE CASCADE,'
        . ' CONSTRAINT `fk_ledger_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`)'
        . ' ON DELETE SET NULL ON UPDATE CASCADE'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ];

    private function __construct()
    {
    }

    /**
     * Pastikan tiga tabel kasbon tersedia (migrasi aditif M2) — idempoten.
     *
     * Dipakai `database/migrate.php` dan `QcFixture::ensureInstalled()`. Karena
     * seluruh pernyataan memakai `CREATE TABLE IF NOT EXISTS`, menjalankannya
     * berulang tidak mengubah tabel yang sudah ada (tidak ada `ALTER`, tidak ada
     * penulisan ulang baris).
     *
     * @return int jumlah pernyataan yang dieksekusi (selalu 3 — bukti jalur berjalan)
     */
    public static function ensureSchema(): int
    {
        $executed = 0;

        foreach (self::SCHEMA_STATEMENTS as $statement) {
            Database::query($statement);
            $executed++;
        }

        return $executed;
    }

    /**
     * Catat satu petani (dipanggil DI DALAM `KasbonService::registerMiner()`).
     *
     * @param array<string, mixed> $data kolom terisi (lihat COLUMNS)
     *
     * @throws \InvalidArgumentException kolom tidak dikenal / kolom wajib hilang
     */
    public static function insert(array $data): void
    {
        $unknown = array_diff(array_keys($data), self::COLUMNS);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Kolom miners tidak dikenal: ' . implode(', ', $unknown)
            );
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($data));

        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'Kolom miners wajib belum diisi: ' . implode(', ', $missing)
            );
        }

        Database::insert('miners', $data);
    }

    /**
     * Satu petani berdasarkan id.
     *
     * @return array<string, mixed>|null
     */
    public static function findById(string $id): ?array
    {
        return Database::fetchOne(
            'SELECT ' . self::READ_COLUMNS . ' FROM `miners` WHERE `id` = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * Satu petani berdasarkan nomor telepon (anti-duplikat identitas piutang).
     *
     * @return array<string, mixed>|null
     */
    public static function findByPhone(string $phone): ?array
    {
        return Database::fetchOne(
            'SELECT ' . self::READ_COLUMNS . ' FROM `miners` WHERE `phone_number` = :phone_number LIMIT 1',
            ['phone_number' => $phone]
        );
    }

    /**
     * Daftar petani untuk `<datalist>` beserta utang berjalan masing-masing.
     *
     * Urutan sengaja: petani **berutang lebih dulu**, lalu nama A→Z — petani yang
     * paling sering dilayani selalu di puncak daftar, sementara petani tanpa
     * utang tetap dapat dipilih untuk kasbon baru.
     *
     * `$limit` adalah integer hasil clamp di sini (bukan nilai dari klien),
     * sehingga penulisannya sebagai literal aman sekaligus menghindari `LIMIT ?`
     * yang perilakunya bergantung pada driver (pola `ProfitDistribution`).
     *
     * @return list<array<string, mixed>> kunci: id, full_name, phone_number,
     *         location, outstanding, last_loan_at
     */
    public static function listForAutocomplete(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));

        return Database::fetchAll(
            'SELECT `m`.`id`, `m`.`full_name`, `m`.`phone_number`, `m`.`location`,'
            . ' COALESCE(SUM(CASE WHEN `l`.`status` = \'open\' THEN `l`.`balance_remaining` ELSE 0 END), 0)'
            . ' AS `outstanding`,'
            . ' MAX(`l`.`created_at`) AS `last_loan_at`'
            . ' FROM `miners` `m`'
            . ' LEFT JOIN `miner_loans` `l` ON `l`.`miner_id` = `m`.`id`'
            . ' GROUP BY `m`.`id`, `m`.`full_name`, `m`.`phone_number`, `m`.`location`'
            // MariaDB menolak alias agregat di dalam ekspresi ORDER BY
            // ("reference to group function"), jadi agregatnya diulang di sini.
            . ' ORDER BY (COALESCE(SUM(CASE WHEN `l`.`status` = \'open\''
            . ' THEN `l`.`balance_remaining` ELSE 0 END), 0) > 0) DESC, `m`.`full_name` ASC'
            . ' LIMIT ' . $limit
        );
    }

    /**
     * Petani yang namanya cocok dengan potongan teks (dipakai resolver peladen
     * ketika peramban tidak mengirim `miner_id`).
     *
     * @return list<array<string, mixed>>
     */
    public static function findByName(string $name, int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));

        return Database::fetchAll(
            'SELECT ' . self::READ_COLUMNS . ' FROM `miners`'
            . ' WHERE `full_name` = :full_name ORDER BY `created_at` ASC, `id` ASC'
            . ' LIMIT ' . $limit,
            ['full_name' => $name]
        );
    }
}
