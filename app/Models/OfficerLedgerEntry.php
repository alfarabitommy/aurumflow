<?php

declare(strict_types=1);

namespace AurumFlow\Models;

use AurumFlow\Config\Database;

/**
 * Model tabel `officer_ledger_entries` — buku hak mitra lapangan (Fase 10).
 *
 * `amount` SELALU positif; arah ditentukan `entry_type`:
 *   - `cut`          kredit — akrual potongan persentase pada satu sesi tutup buku
 *   - `bonus`        kredit — suntikan operator; **belum** mengurangi laba selama
 *                    `settlement_id` masih NULL, dan diserap tepat SEKALI oleh
 *                    tutup buku berikutnya (tanpa voucher OPEX → tanpa deduksi ganda)
 *   - `disbursement` debit  — pencairan tunai (`register_id` + `mutation_id` terisi)
 *
 * Aturan kolom per jenis ditegakkan di sini (DDL tidak dapat menyatakannya):
 *   cut          ⇒ settlement_id, cut_pct, base_amount WAJIB; register/mutation NULL
 *   bonus        ⇒ cut_pct, base_amount, register_id, mutation_id WAJIB NULL
 *   disbursement ⇒ register_id + mutation_id WAJIB; settlement/pct/base NULL
 */
final class OfficerLedgerEntry
{
    /** Potongan persentase dari laba kotor (akrual tutup buku). */
    public const ENTRY_CUT = 'cut';

    /** Bonus manual operator (akrual; diserap tutup buku berikutnya). */
    public const ENTRY_BONUS = 'bonus';

    /** Pencairan hak (kas keluar + saldo turun). */
    public const ENTRY_DISBURSEMENT = 'disbursement';

    /**
     * Label jenis entri — SATU-SATUNYA pemilik label (pola
     * `CapitalWallet::TIER_LABELS`). Nilai enum mentah tidak pernah dicetak,
     * termasuk di portal petugas.
     *
     * @var array<string, string>
     */
    public const TYPE_LABELS = [
        self::ENTRY_CUT          => 'Potongan Harian',
        self::ENTRY_BONUS        => 'Bonus',
        self::ENTRY_DISBURSEMENT => 'Pencairan',
    ];

    /** Batas catatan bebas (kolom TEXT; dibatasi agar layar tetap terbaca). */
    public const MAX_NOTES_CHARS = 255;

    /** Kolom yang boleh ditulis (penjaga salah ketik nama kolom). */
    private const COLUMNS = [
        'id',
        'officer_id',
        'entry_type',
        'amount',
        'balance_after',
        'cut_pct',
        'base_amount',
        'settlement_id',
        'register_id',
        'mutation_id',
        'recorded_by',
        'notes',
    ];

    /** Kolom wajib untuk SETIAP jenis entri. */
    private const BASE_REQUIRED = [
        'id',
        'officer_id',
        'entry_type',
        'amount',
        'balance_after',
    ];

    /** Kolom wajib per jenis entri. */
    private const TYPE_REQUIRED = [
        self::ENTRY_CUT          => ['settlement_id', 'cut_pct', 'base_amount'],
        self::ENTRY_BONUS        => [],
        self::ENTRY_DISBURSEMENT => ['register_id', 'mutation_id'],
    ];

    /** Kolom yang DILARANG terisi per jenis entri (penjaga lintas kanal). */
    private const TYPE_FORBIDDEN = [
        self::ENTRY_CUT          => ['register_id', 'mutation_id'],
        self::ENTRY_BONUS        => ['register_id', 'mutation_id', 'settlement_id', 'cut_pct', 'base_amount'],
        self::ENTRY_DISBURSEMENT => ['settlement_id', 'cut_pct', 'base_amount'],
    ];

    /** Kolom yang dibaca (tanpa `SELECT *`). */
    private const READ_COLUMNS = '`id`, `officer_id`, `entry_type`, `amount`, `balance_after`,'
        . ' `cut_pct`, `base_amount`, `settlement_id`, `register_id`, `mutation_id`,'
        . ' `recorded_by`, `notes`, `created_at`';

    private function __construct()
    {
    }

    /**
     * Label satu jenis entri. Nilai tak dikenal dikembalikan sebagai "Bonus"
     * supaya layar tidak pernah mencetak nilai enum mentah.
     */
    public static function typeLabel(string $entryType): string
    {
        return self::TYPE_LABELS[$entryType] ?? self::TYPE_LABELS[self::ENTRY_BONUS];
    }

    /**
     * Jenis entri yang sah (daftar putih service & view).
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return array_keys(self::TYPE_LABELS);
    }

    /**
     * Catat satu baris buku hak.
     *
     * @param array<string, mixed> $data kolom terisi (lihat COLUMNS)
     *
     * @throws \InvalidArgumentException kolom tidak dikenal / wajib hilang / terlarang
     */
    public static function insert(array $data): void
    {
        $unknown = array_diff(array_keys($data), self::COLUMNS);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Kolom officer_ledger_entries tidak dikenal: ' . implode(', ', $unknown)
            );
        }

        $entryType = isset($data['entry_type']) && is_string($data['entry_type']) ? $data['entry_type'] : '';

        if (!in_array($entryType, self::types(), true)) {
            throw new \InvalidArgumentException('Jenis entri buku hak tidak dikenal.');
        }

        $missing = array_diff(
            array_merge(self::BASE_REQUIRED, self::TYPE_REQUIRED[$entryType]),
            array_keys($data)
        );

        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'Kolom officer_ledger_entries wajib belum diisi: ' . implode(', ', $missing)
            );
        }

        foreach (self::TYPE_FORBIDDEN[$entryType] as $column) {
            if (array_key_exists($column, $data)) {
                throw new \InvalidArgumentException(
                    'Kolom `' . $column . '` tidak boleh terisi untuk entri ' . $entryType . '.'
                );
            }
        }

        Database::insert('officer_ledger_entries', $data);
    }

    /**
     * Akrual bonus yang BELUM diserap tutup buku mana pun, urut FIFO.
     *
     * Tanpa penguncian — dipakai pratinjau Layar C.
     *
     * @return list<array<string, mixed>>
     */
    public static function unabsorbedBonuses(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));

        return Database::fetchAll(
            'SELECT ' . self::READ_COLUMNS . ' FROM `officer_ledger_entries`'
            . ' WHERE `entry_type` = :type AND `settlement_id` IS NULL'
            . ' ORDER BY `created_at` ASC, `id` ASC'
            . ' LIMIT ' . $limit,
            ['type' => self::ENTRY_BONUS]
        );
    }

    /**
     * Kunci seluruh akrual bonus yang belum terserap — WAJIB di dalam transaksi.
     *
     * Urutan penguncian global: … → `operational_expenses` → **`officers` →
     * `officer_ledger_entries`** → `system_settings`.
     *
     * @return list<array<string, mixed>>
     */
    public static function lockUnabsorbedBonusesForUpdate(): array
    {
        return Database::fetchAll(
            'SELECT ' . self::READ_COLUMNS . ' FROM `officer_ledger_entries`'
            . ' WHERE `entry_type` = :type AND `settlement_id` IS NULL'
            . ' ORDER BY `created_at` ASC, `id` ASC'
            . ' FOR UPDATE',
            ['type' => self::ENTRY_BONUS]
        );
    }

    /**
     * Tandai akrual bonus sebagai TERSERAP sesi tutup buku ini.
     *
     * `WHERE settlement_id IS NULL` adalah pertahanan terakhir: bonus yang sudah
     * diserap tidak pernah berpindah sesi walau id dikirim ulang.
     *
     * @param list<string> $ids
     * @return int jumlah baris yang berubah (harus sama dengan count($ids))
     */
    public static function absorbBonuses(string $settlementId, array $ids): int
    {
        $ids = array_values(array_filter($ids, static fn (mixed $id): bool => is_string($id) && $id !== ''));

        if ($ids === []) {
            return 0;
        }

        $params = ['settlement_id' => $settlementId, 'type' => self::ENTRY_BONUS];
        $placeholders = [];

        foreach ($ids as $index => $id) {
            $name = 'id_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $id;
        }

        return Database::query(
            'UPDATE `officer_ledger_entries` SET `settlement_id` = :settlement_id'
            . ' WHERE `settlement_id` IS NULL AND `entry_type` = :type'
            . ' AND `id` IN (' . implode(', ', $placeholders) . ')',
            $params
        )->rowCount();
    }

    /**
     * Riwayat buku hak satu petugas (portal & Layar G), terbaru lebih dahulu.
     *
     * @return list<array<string, mixed>>
     */
    public static function forOfficer(string $officerId, int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));

        return Database::fetchAll(
            'SELECT ' . self::READ_COLUMNS . ' FROM `officer_ledger_entries`'
            . ' WHERE `officer_id` = :officer_id'
            . ' ORDER BY `created_at` DESC, `id` DESC'
            . ' LIMIT ' . $limit,
            ['officer_id' => $officerId]
        );
    }

    /**
     * Ringkasan nominal per jenis entri untuk satu petugas.
     *
     * Nilai dikembalikan sebagai **string desimal kanonik** (bukan sen) supaya
     * portal blind dapat mencetaknya lewat `Formatter` tanpa perlu mengimpor
     * utilitas uang lain — portal petugas sengaja tidak menyentuh `TellerService`
     * (lihat daftar terlarang pada rencana Fase 10 § 8.2 grup H).
     *
     * @return array{cut: string, bonus: string, disbursement: string, count: int}
     */
    public static function totalsForOfficer(string $officerId, ?string $fromDate = null): array
    {
        $sql = 'SELECT `entry_type`, COALESCE(SUM(`amount`), 0) AS `amount`, COUNT(*) AS `rows`'
            . ' FROM `officer_ledger_entries` WHERE `officer_id` = :officer_id';
        $params = ['officer_id' => $officerId];

        if ($fromDate !== null && $fromDate !== '') {
            $sql .= ' AND `created_at` >= :from_date';
            $params['from_date'] = $fromDate . ' 00:00:00';
        }

        $sql .= ' GROUP BY `entry_type`';

        $totals = [
            self::ENTRY_CUT          => 0,
            self::ENTRY_BONUS        => 0,
            self::ENTRY_DISBURSEMENT => 0,
            'count'                  => 0,
        ];

        foreach (Database::fetchAll($sql, $params) as $row) {
            $type = (string) ($row['entry_type'] ?? '');
            $cents = \AurumFlow\Services\TellerService::moneyToCents((string) ($row['amount'] ?? '0.00'));

            if (array_key_exists($type, $totals)) {
                $totals[$type] = (int) $totals[$type] + $cents;
            }

            $totals['count'] = (int) $totals['count'] + (int) ($row['rows'] ?? 0);
        }

        return [
            self::ENTRY_CUT          => \AurumFlow\Services\TellerService::centsToDecimal((int) $totals[self::ENTRY_CUT]),
            self::ENTRY_BONUS        => \AurumFlow\Services\TellerService::centsToDecimal((int) $totals[self::ENTRY_BONUS]),
            self::ENTRY_DISBURSEMENT => \AurumFlow\Services\TellerService::centsToDecimal((int) $totals[self::ENTRY_DISBURSEMENT]),
            'count'                  => (int) $totals['count'],
        ];
    }

    /**
     * Jumlah baris (QC & ringkasan).
     */
    public static function countAll(?string $entryType = null): int
    {
        if ($entryType === null) {
            $row = Database::fetchOne('SELECT COUNT(*) AS `total` FROM `officer_ledger_entries`');

            return (int) ($row['total'] ?? 0);
        }

        $row = Database::fetchOne(
            'SELECT COUNT(*) AS `total` FROM `officer_ledger_entries` WHERE `entry_type` = :type',
            ['type' => $entryType]
        );

        return (int) ($row['total'] ?? 0);
    }
}
