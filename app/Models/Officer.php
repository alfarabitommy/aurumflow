<?php

declare(strict_types=1);

namespace AurumFlow\Models;

use AurumFlow\Config\Database;

/**
 * Model tabel `officers` + DDL tabel `officer_ledger_entries` — Fase 10.
 *
 * `officers` adalah AKUN BUKU mitra lapangan (1 : 1 dengan akun login
 * `users` berperan `officer`): tarif potongan (`cut_pct`) dan saldo hak
 * berjalan (`balance_payable`). Riwayatnya hidup di tabel ke-16
 * (`officer_ledger_entries`, model `OfficerLedgerEntry`).
 *
 * Aturan yang dipegang tabel ini (lihat AGENTS.md):
 *   - Potongan dihitung dari **laba kotor** (pendapatan toko − modal belanja
 *     hulu), bukan nominal tetap, dan hanya dikreditkan pada tutup buku.
 *   - `balance_payable = Σ(cut + bonus) − Σ(disbursement)`; invariant ini
 *     dijaga `OfficerService` dan diuji `qc_fase_10` grup I.
 *   - Tidak ada kolom status: aktif/nonaktif milik `users.is_active`
 *     (satu sumber kebenaran, pola Fase 7). Petugas nonaktif berhenti
 *     berakrual tetapi saldonya tetap dapat dicairkan.
 *
 * Kelas ini juga memegang DDL kedua tabel (pola `Miner::ensureSchema()` /
 * `OperationalExpense::ensureSchema()`): SQL hanya di `app/Models/`, dan
 * `database/migrate.php` (M4) tidak menulis DDL sendiri.
 */
final class Officer
{
    /** Batas panjang nama (mengikuti `users.full_name`). */
    public const MAX_NAME_CHARS = 100;

    /** Kolom yang boleh ditulis (penjaga salah ketik nama kolom). */
    private const COLUMNS = [
        'id',
        'user_id',
        'cut_pct',
        'balance_payable',
    ];

    /** Kolom NOT NULL tanpa DEFAULT pada DDL tabel ke-15. */
    private const REQUIRED_COLUMNS = [
        'id',
        'user_id',
    ];

    /** Kolom yang dibaca (tanpa `SELECT *`). */
    private const READ_COLUMNS = '`id`, `user_id`, `cut_pct`, `balance_payable`, `created_at`, `updated_at`';

    /**
     * DDL tabel ke-15 — SALINAN dari `database/schema.sql` (sumber kebenaran
     * ERD). `CREATE TABLE IF NOT EXISTS` membuat `ensureSchema()` idempoten.
     */
    private const SCHEMA_OFFICERS = 'CREATE TABLE IF NOT EXISTS `officers` ('
        . ' `id` CHAR(36) NOT NULL,'
        . ' `user_id` CHAR(36) NOT NULL,'
        . ' `cut_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00,'
        . ' `balance_payable` DECIMAL(14,2) NOT NULL DEFAULT 0.00,'
        . ' `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . ' `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
        . ' PRIMARY KEY (`id`),'
        . ' UNIQUE KEY `uq_officer_user` (`user_id`),'
        . ' KEY `idx_officer_pct` (`cut_pct`),'
        . ' CONSTRAINT `fk_officer_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)'
        . ' ON DELETE RESTRICT ON UPDATE CASCADE'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    /**
     * DDL tabel ke-16 — buku hak. `settlement_id` sengaja `ON DELETE RESTRICT`
     * (bukan `SET NULL`): `SET NULL` mengaktifkan ulang bonus yang sudah
     * terserap sehingga laba terpotong dua kali.
     */
    private const SCHEMA_LEDGER = 'CREATE TABLE IF NOT EXISTS `officer_ledger_entries` ('
        . ' `id` CHAR(36) NOT NULL,'
        . ' `officer_id` CHAR(36) NOT NULL,'
        . ' `entry_type` ENUM(\'cut\', \'bonus\', \'disbursement\') NOT NULL,'
        . ' `amount` DECIMAL(14,2) NOT NULL,'
        . ' `balance_after` DECIMAL(14,2) NOT NULL,'
        . ' `cut_pct` DECIMAL(5,2) NULL,'
        . ' `base_amount` DECIMAL(14,2) NULL,'
        . ' `settlement_id` CHAR(36) NULL,'
        . ' `register_id` CHAR(36) NULL,'
        . ' `mutation_id` CHAR(36) NULL,'
        . ' `recorded_by` CHAR(36) NULL,'
        . ' `notes` TEXT NULL,'
        . ' `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . ' PRIMARY KEY (`id`),'
        . ' UNIQUE KEY `uq_entry_mutation` (`mutation_id`),'
        . ' KEY `idx_entry_officer` (`officer_id`, `created_at`),'
        . ' KEY `idx_entry_open` (`entry_type`, `settlement_id`),'
        . ' KEY `idx_entry_settlement` (`settlement_id`, `entry_type`),'
        . ' KEY `idx_entry_register` (`register_id`),'
        . ' CONSTRAINT `fk_entry_officer` FOREIGN KEY (`officer_id`) REFERENCES `officers` (`id`)'
        . ' ON DELETE RESTRICT ON UPDATE CASCADE,'
        . ' CONSTRAINT `fk_entry_settlement` FOREIGN KEY (`settlement_id`)'
        . ' REFERENCES `daily_settlements` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,'
        . ' CONSTRAINT `fk_entry_register` FOREIGN KEY (`register_id`)'
        . ' REFERENCES `cash_registers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,'
        . ' CONSTRAINT `fk_entry_mutation` FOREIGN KEY (`mutation_id`)'
        . ' REFERENCES `cash_mutations` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,'
        . ' CONSTRAINT `fk_entry_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`)'
        . ' ON DELETE SET NULL ON UPDATE CASCADE'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private function __construct()
    {
    }

    /**
     * Pastikan dua tabel Fase 10 tersedia (migrasi aditif M4).
     *
     * @return int jumlah pernyataan yang dijalankan (2)
     */
    public static function ensureSchema(): int
    {
        Database::query(self::SCHEMA_OFFICERS);
        Database::query(self::SCHEMA_LEDGER);

        return 2;
    }

    /**
     * Apakah tabel `officers` sudah ada? (laporan migrasi & QC)
     */
    public static function exists(): bool
    {
        $row = Database::fetchOne(
            'SELECT `table_name` FROM `information_schema`.`tables`'
            . ' WHERE `table_schema` = DATABASE() AND `table_name` = :table LIMIT 1',
            ['table' => 'officers']
        );

        return $row !== null;
    }

    /**
     * Apakah tabel `officer_ledger_entries` sudah ada?
     */
    public static function ledgerExists(): bool
    {
        $row = Database::fetchOne(
            'SELECT `table_name` FROM `information_schema`.`tables`'
            . ' WHERE `table_schema` = DATABASE() AND `table_name` = :table LIMIT 1',
            ['table' => 'officer_ledger_entries']
        );

        return $row !== null;
    }

    /**
     * Catat satu akun buku petugas (dipanggil DI DALAM transaksi `OfficerService`).
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
                'Kolom officers tidak dikenal: ' . implode(', ', $unknown)
            );
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($data));

        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'Kolom officers wajib belum diisi: ' . implode(', ', $missing)
            );
        }

        Database::insert('officers', $data);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findById(string $officerId): ?array
    {
        return Database::fetchOne(
            'SELECT ' . self::READ_COLUMNS . ' FROM `officers` WHERE `id` = :id LIMIT 1',
            ['id' => $officerId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findByUserId(string $userId): ?array
    {
        return Database::fetchOne(
            'SELECT ' . self::READ_COLUMNS . ' FROM `officers` WHERE `user_id` = :user_id LIMIT 1',
            ['user_id' => $userId]
        );
    }

    /**
     * Baca satu baris SAMBIL MENGUNCI — WAJIB di dalam transaksi.
     *
     * @return array<string, mixed>|null
     */
    public static function findByIdForUpdate(string $officerId): ?array
    {
        return Database::fetchOne(
            'SELECT ' . self::READ_COLUMNS . ' FROM `officers` WHERE `id` = :id LIMIT 1 FOR UPDATE',
            ['id' => $officerId]
        );
    }

    /**
     * Kunci SELURUH baris `officers` (id menaik) untuk tutup buku.
     *
     * Urutan penguncian global (AGENTS.md): `capital_wallets` → `cash_registers`
     * → `bridge_loans` → `miner_loans` → `hulu_transactions` →
     * `operational_expenses` → **`officers`** → `system_settings`.
     *
     * Mengunci seluruh baris (bukan hanya yang `cut_pct > 0`) membuat tarif
     * tidak dapat berubah di tengah perhitungan: potongan sesi ini memakai satu
     * snapshot yang konsisten dan disalin ke `cut_pct` tiap entri.
     *
     * @return list<array<string, mixed>>
     */
    public static function lockAllForUpdate(): array
    {
        return Database::fetchAll(
            'SELECT ' . self::READ_COLUMNS . ' FROM `officers` ORDER BY `id` ASC FOR UPDATE'
        );
    }

    /**
     * Profil akun login untuk sekumpulan `user_id` (nama + status aktif).
     *
     * Dipanggil SETELAH `lockAllForUpdate()`; sengaja tidak mengunci `users`
     * (tabel itu bukan bagian urutan penguncian jalur uang — status aktif boleh
     * berubah tanpa merusak angka sesi ini).
     *
     * @param list<string> $userIds
     * @return array<string, array{name: string, active: bool}>
     */
    public static function userProfiles(array $userIds): array
    {
        $ids = array_values(array_filter($userIds, static fn (mixed $id): bool => is_string($id) && $id !== ''));

        if ($ids === []) {
            return [];
        }

        $params = [];
        $placeholders = [];

        foreach ($ids as $index => $id) {
            $name = 'id_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $id;
        }

        $rows = Database::fetchAll(
            'SELECT `id`, `full_name`, `is_active` FROM `users` WHERE `id` IN ('
            . implode(', ', $placeholders) . ')',
            $params
        );

        $profiles = [];

        foreach ($rows as $row) {
            $profiles[(string) $row['id']] = [
                'name'   => (string) $row['full_name'],
                'active' => (int) ($row['is_active'] ?? 0) === 1,
            ];
        }

        return $profiles;
    }

    /**
     * Ubah saldo hak secara relatif (`balance_payable = balance_payable + delta`).
     *
     * Relatif, bukan absolut: nilai yang dibaca sebelum transaksi tetap sah
     * walau baris sempat terkunci lama.
     */
    public static function adjustBalance(string $officerId, string $delta): int
    {
        return Database::query(
            'UPDATE `officers` SET `balance_payable` = `balance_payable` + :delta WHERE `id` = :id',
            ['delta' => $delta, 'id' => $officerId]
        )->rowCount();
    }

    /**
     * Ubah tarif potongan.
     */
    public static function updateCutPct(string $officerId, string $cutPct): int
    {
        return Database::update(
            'officers',
            ['cut_pct' => $cutPct],
            '`id` = :id',
            ['id' => $officerId]
        );
    }

    /**
     * Daftar petugas + profil login untuk Layar G (operator).
     *
     * @return list<array<string, mixed>>
     */
    public static function listWithUsers(): array
    {
        return Database::fetchAll(
            'SELECT o.`id`, o.`user_id`, o.`cut_pct`, o.`balance_payable`, o.`updated_at`,'
            . ' u.`full_name`, u.`phone_number`, u.`is_active`'
            . ' FROM `officers` o INNER JOIN `users` u ON u.`id` = o.`user_id`'
            . ' ORDER BY u.`full_name` ASC, o.`id` ASC'
        );
    }

    /**
     * Jumlah baris `officers` (dipakai ringkasan & QC).
     */
    public static function countAll(): int
    {
        $row = Database::fetchOne('SELECT COUNT(*) AS `total` FROM `officers`');

        return (int) ($row['total'] ?? 0);
    }
}
