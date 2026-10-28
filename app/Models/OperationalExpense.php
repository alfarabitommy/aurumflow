<?php

declare(strict_types=1);

namespace AurumFlow\Models;

use AurumFlow\Config\Database;

/**
 * Model tabel `operational_expenses` — beban operasional non-emas (Fase 9).
 *
 * Aturan yang dipegang tabel ini (audit/Audit_Biaya_Koordinasi_AurumFlow.md
 * Opsi B + AGENTS.md):
 *   - **Satu voucher = satu baris kas** (`mutation_id`), pola yang sama dengan
 *     `miner_loan_ledger`: satu baris kas tidak boleh dihapus tanpa menghapus
 *     jejak bebannya (FK `ON DELETE RESTRICT`).
 *   - `amount` SELALU positif; arah kas selalu keluar (`TYPE_OUT`).
 *   - Voucher yang belum diserap (`settlement_id IS NULL`) adalah sumber
 *     `daily_coordination_fee`/`consumable_opex` pada tutup buku berikutnya.
 *     Nilainya TIDAK PERNAH berasal dari isian operator (anti-fiktif).
 *   - `settlement_id` memakai `ON DELETE SET NULL`: beban adalah fakta kas yang
 *     berdiri sendiri, sehingga sesi tutup buku boleh dihapus tanpa
 *     menghilangkan jejak kasnya.
 *
 * Kelas ini juga memegang DDL tabel (`ensureSchema()`), mengikuti pola
 * `Miner::ensureSchema()`/`User::ensureRoleEnum()`: SQL hanya hidup di
 * `app/Models/`, dan jalur migrasi lapangan (`database/migrate.php` M3) tidak
 * menulis DDL sendiri.
 */
final class OperationalExpense
{
    /** Beban koordinasi lapangan / aparat (PRD § 3.3). */
    public const CATEGORY_COORDINATION = 'coordination';

    /** Pengamanan wilayah. */
    public const CATEGORY_SECURITY = 'security';

    /** Jamuan/gas/konsumsi — mengisi kolom `daily_settlements.consumable_opex`. */
    public const CATEGORY_CONSUMABLE = 'consumable';

    /** Biaya lain-lain yang tidak masuk tiga kategori di atas. */
    public const CATEGORY_OTHER = 'other';

    /**
     * Label kategori — SATU-SATUNYA pemilik label (pola
     * `CapitalWallet::TIER_LABELS`). Nilai enum mentah tidak pernah dicetak.
     *
     * @var array<string, string>
     */
    public const CATEGORY_LABELS = [
        self::CATEGORY_COORDINATION => 'Koordinasi Lapangan',
        self::CATEGORY_SECURITY     => 'Pengamanan Wilayah',
        self::CATEGORY_CONSUMABLE   => 'Konsumsi / Gas',
        self::CATEGORY_OTHER        => 'Lain-lain',
    ];

    /**
     * Prefiks label mutasi kas per kategori. `cash_mutations.source_destination`
     * hanya VARCHAR(100), sehingga nama penerima dipotong setelah prefiks.
     *
     * @var array<string, string>
     */
    public const MUTATION_PREFIXES = [
        self::CATEGORY_COORDINATION => 'Biaya koordinasi: ',
        self::CATEGORY_SECURITY     => 'Biaya pengamanan: ',
        self::CATEGORY_CONSUMABLE   => 'Biaya konsumsi: ',
        self::CATEGORY_OTHER        => 'Biaya lain-lain: ',
    ];

    /** Batas kolom `payee_name` (VARCHAR 100). */
    public const MAX_PAYEE_CHARS = 100;

    /** Batas praktis `source_destination` (VARCHAR 100). */
    public const MAX_LABEL_CHARS = 100;

    /** Batas catatan bebas (kolom TEXT; dibatasi agar layar tetap terbaca). */
    public const MAX_NOTES_CHARS = 255;

    /** Kolom yang boleh ditulis (penjaga salah ketik nama kolom). */
    private const COLUMNS = [
        'id',
        'expense_date',
        'category',
        'payee_name',
        'amount',
        'register_id',
        'mutation_id',
        'settlement_id',
        'recorded_by',
        'notes',
    ];

    /** Kolom NOT NULL tanpa DEFAULT pada DDL (ERD § 4 point 14). */
    private const REQUIRED_COLUMNS = [
        'id',
        'expense_date',
        'category',
        'payee_name',
        'amount',
        'register_id',
        'mutation_id',
    ];

    /** Kolom yang dibaca (tanpa `SELECT *`). */
    private const READ_COLUMNS = '`id`, `expense_date`, `category`, `payee_name`, `amount`,'
        . ' `register_id`, `mutation_id`, `settlement_id`, `recorded_by`, `notes`, `created_at`';

    /**
     * DDL tabel ke-14 — SALINAN dari `database/schema.sql` (sumber kebenaran
     * ERD). `CREATE TABLE IF NOT EXISTS` membuat `ensureSchema()` idempoten:
     * instalasi baru memakai `schema.sql`, instalasi lapangan memakai jalur ini
     * lewat `database/migrate.php` M3.
     */
    private const SCHEMA_STATEMENT = 'CREATE TABLE IF NOT EXISTS `operational_expenses` ('
        . ' `id` CHAR(36) NOT NULL,'
        . ' `expense_date` DATE NOT NULL,'
        . ' `category` ENUM(\'coordination\', \'security\', \'consumable\', \'other\')'
        . ' NOT NULL DEFAULT \'coordination\','
        . ' `payee_name` VARCHAR(100) NOT NULL,'
        . ' `amount` DECIMAL(14,2) NOT NULL,'
        . ' `register_id` CHAR(36) NOT NULL,'
        . ' `mutation_id` CHAR(36) NOT NULL,'
        . ' `settlement_id` CHAR(36) NULL,'
        . ' `recorded_by` CHAR(36) NULL,'
        . ' `notes` TEXT NULL,'
        . ' `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . ' PRIMARY KEY (`id`),'
        . ' KEY `idx_expense_date` (`expense_date`),'
        . ' KEY `idx_expense_open` (`settlement_id`, `expense_date`),'
        . ' KEY `idx_expense_register` (`register_id`),'
        . ' CONSTRAINT `fk_expense_register` FOREIGN KEY (`register_id`)'
        . ' REFERENCES `cash_registers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,'
        . ' CONSTRAINT `fk_expense_mutation` FOREIGN KEY (`mutation_id`)'
        . ' REFERENCES `cash_mutations` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,'
        . ' CONSTRAINT `fk_expense_settlement` FOREIGN KEY (`settlement_id`)'
        . ' REFERENCES `daily_settlements` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,'
        . ' CONSTRAINT `fk_expense_recorder` FOREIGN KEY (`recorded_by`)'
        . ' REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private function __construct()
    {
    }

    /**
     * Pastikan tabel `operational_expenses` tersedia (migrasi aditif M3).
     *
     * @return int jumlah pernyataan yang dijalankan (1) — pola `ensureSchema()`
     *             milik `Miner`, dipakai `database/migrate.php`
     */
    public static function ensureSchema(): int
    {
        Database::query(self::SCHEMA_STATEMENT);

        return 1;
    }

    /**
     * Apakah tabel `operational_expenses` sudah ada? (dipakai laporan migrasi
     * dan QC; tidak pernah dipakai jalur permintaan web)
     */
    public static function exists(): bool
    {
        $row = Database::fetchOne(
            'SELECT `table_name` FROM `information_schema`.`tables`'
            . ' WHERE `table_schema` = DATABASE() AND `table_name` = :table LIMIT 1',
            ['table' => 'operational_expenses']
        );

        return $row !== null;
    }

    /**
     * Daftar kategori yang sah — dipakai service (daftar putih) dan view.
     *
     * @return list<string>
     */
    public static function categories(): array
    {
        return array_keys(self::CATEGORY_LABELS);
    }

    /**
     * Label satu kategori. Nilai tak dikenal dikembalikan sebagai "Lain-lain"
     * supaya layar tidak pernah menampilkan nilai enum mentah.
     */
    public static function categoryLabel(string $category): string
    {
        return self::CATEGORY_LABELS[$category] ?? self::CATEGORY_LABELS[self::CATEGORY_OTHER];
    }

    /**
     * Prefiks label mutasi kas untuk satu kategori.
     */
    public static function mutationPrefix(string $category): string
    {
        return self::MUTATION_PREFIXES[$category] ?? self::MUTATION_PREFIXES[self::CATEGORY_OTHER];
    }

    /**
     * Catat satu voucher beban (dipanggil DI DALAM `ExpenseService::pay()`).
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
                'Kolom operational_expenses tidak dikenal: ' . implode(', ', $unknown)
            );
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($data));

        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'Kolom operational_expenses wajib belum diisi: ' . implode(', ', $missing)
            );
        }

        Database::insert('operational_expenses', $data);
    }

    /**
     * Voucher yang BELUM diserap sesi tutup buku mana pun, urut FIFO.
     *
     * Dipakai pratinjau Layar C (`SettlementService::batchContext()`) — tanpa
     * penguncian.
     *
     * @return list<array<string, mixed>>
     */
    public static function unabsorbed(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));

        return Database::fetchAll(
            'SELECT ' . self::READ_COLUMNS . ' FROM `operational_expenses`'
            . ' WHERE `settlement_id` IS NULL'
            . ' ORDER BY `expense_date` ASC, `created_at` ASC, `id` ASC'
            . ' LIMIT ' . $limit
        );
    }

    /**
     * Kunci seluruh voucher yang belum terserap — WAJIB di dalam
     * `Database::transaction()`.
     *
     * Urutan penguncian global: dompet → pos kas → talangan → kasbon → hulu →
     * **operational_expenses** → system_settings. `FOR UPDATE` menyerialkan dua
     * tutup buku serentak sehingga satu voucher tidak dapat diserap dua kali.
     *
     * @return list<array<string, mixed>>
     */
    public static function lockUnabsorbedForUpdate(): array
    {
        return Database::fetchAll(
            'SELECT ' . self::READ_COLUMNS . ' FROM `operational_expenses`'
            . ' WHERE `settlement_id` IS NULL'
            . ' ORDER BY `expense_date` ASC, `created_at` ASC, `id` ASC'
            . ' FOR UPDATE'
        );
    }

    /**
     * Tandai voucher sebagai terserap oleh satu sesi tutup buku.
     *
     * `WHERE settlement_id IS NULL` adalah pertahanan terakhir: voucher yang
     * sudah terserap tidak pernah berpindah sesi walau id dikirim ulang.
     *
     * @param list<string> $ids
     * @return int jumlah baris yang berubah (harus sama dengan count($ids))
     */
    public static function absorb(string $settlementId, array $ids): int
    {
        $ids = array_values(array_filter($ids, static fn (mixed $id): bool => is_string($id) && $id !== ''));

        if ($ids === []) {
            return 0;
        }

        // Named parameter per id (konvensi proyek: tidak ada nilai dinamis yang
        // diinterpolasi ke string SQL — hanya nama placeholder yang dibentuk).
        $params = ['settlement_id' => $settlementId];
        $placeholders = [];

        foreach ($ids as $index => $id) {
            $name = 'id_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $id;
        }

        return Database::query(
            'UPDATE `operational_expenses` SET `settlement_id` = :settlement_id'
            . ' WHERE `settlement_id` IS NULL AND `id` IN (' . implode(', ', $placeholders) . ')',
            $params
        )->rowCount();
    }
}
