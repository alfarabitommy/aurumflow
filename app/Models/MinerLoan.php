<?php

declare(strict_types=1);

namespace AurumFlow\Models;

use AurumFlow\Config\Database;

/**
 * Model tabel `miner_loans` — satu baris per pencairan kasbon petani (Fase 8).
 *
 * `balance_remaining` disimpan (bukan dihitung SUM saat baca) supaya laporan
 * tidak perlu menjumlah ulang; invariant yang dijaga `KasbonService`:
 *   principal_amount − Σ(angsuran pada ledger) = balance_remaining
 *
 * `issued_on` adalah TANGGAL BISNIS pencairan (bukan `created_at`) karena batas
 * harian `system_settings.daily_loan_limit` dihitung per tanggal itu.
 *
 * Urutan FIFO pada `lockOpenByMinerForUpdate()` mengikuti pola `BridgeLoan`:
 * `created_at` berpresisi detik (item O-1), sehingga `id` yang unik menutup
 * kemungkinan dua baris pada detik yang sama berurutan acak.
 */
final class MinerLoan
{
    public const STATUS_OPEN = 'open';

    public const STATUS_SETTLED = 'settled';

    /** Kolom yang boleh ditulis (penjaga salah ketik nama kolom). */
    private const COLUMNS = [
        'id',
        'miner_id',
        'principal_amount',
        'balance_remaining',
        'status',
        'issued_on',
        'issued_by',
        'settled_at',
        'notes',
    ];

    /** Kolom NOT NULL tanpa DEFAULT pada DDL (ERD § 4 point 12). */
    private const REQUIRED_COLUMNS = [
        'id',
        'miner_id',
        'principal_amount',
        'balance_remaining',
        'issued_on',
    ];

    /** Kolom yang dibaca (tanpa `SELECT *`). */
    private const READ_COLUMNS = '`id`, `miner_id`, `principal_amount`, `balance_remaining`, '
        . '`status`, `issued_on`, `issued_by`, `settled_at`, `notes`, `created_at`, `updated_at`';

    private function __construct()
    {
    }

    /**
     * Catat satu pencairan (dipanggil DI DALAM transaksi `KasbonService::disburse()`).
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
                'Kolom miner_loans tidak dikenal: ' . implode(', ', $unknown)
            );
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($data));

        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'Kolom miner_loans wajib belum diisi: ' . implode(', ', $missing)
            );
        }

        Database::insert('miner_loans', $data);
    }

    /**
     * Kunci seluruh kasbon `open` milik satu petani, urutan FIFO.
     *
     * WAJIB dipanggil DI DALAM `Database::transaction()`. `FOR UPDATE`
     * menyerialkan pembayaran sehingga dua terminal tidak dapat melunasi kasbon
     * yang sama dua kali (pola `BridgeLoan::lockActiveForUpdate()`).
     *
     * @return list<array<string, mixed>>
     */
    public static function lockOpenByMinerForUpdate(string $minerId): array
    {
        return Database::fetchAll(
            'SELECT ' . self::READ_COLUMNS . ' FROM `miner_loans`'
            . ' WHERE `miner_id` = :miner_id AND `status` = :status'
            . ' ORDER BY `issued_on` ASC, `created_at` ASC, `id` ASC FOR UPDATE',
            ['miner_id' => $minerId, 'status' => self::STATUS_OPEN]
        );
    }

    /**
     * Total pokok yang dicairkan pada satu tanggal bisnis (dasar batas harian).
     *
     * @param string $date tanggal `Y-m-d` (dihitung peladen, bukan dari klien)
     * @return string desimal kanonik (`'0.00'` bila belum ada)
     */
    public static function sumDisbursedOn(string $date): string
    {
        $row = Database::fetchOne(
            'SELECT COALESCE(SUM(`principal_amount`), 0) AS `total` FROM `miner_loans`'
            . ' WHERE `issued_on` = :issued_on',
            ['issued_on' => $date]
        );

        return self::canonical((string) ($row['total'] ?? '0'));
    }

    /**
     * Terapkan hasil angsuran pada satu kasbon (dipanggil DI DALAM transaksi).
     *
     * `$balance` adalah string desimal kanonik hasil hitung sen integer
     * `KasbonService`; `$settled` menandai pelunasan (`settled_at` diisi jam
     * peladen). Syarat `status = 'open'` membuat baris yang sudah lunas tidak
     * dapat diubah lagi (pertahanan berlapis).
     *
     * @return int jumlah baris terpengaruh (0 = baris tidak ditemukan/lunas)
     */
    public static function applyRepayment(string $loanId, string $balance, bool $settled): int
    {
        return Database::query(
            'UPDATE `miner_loans` SET `balance_remaining` = :balance, `status` = :status,'
            . ' `settled_at` = :settled_at'
            . ' WHERE `id` = :id AND `status` = :expected',
            [
                'balance'    => $balance,
                'status'     => $settled ? self::STATUS_SETTLED : self::STATUS_OPEN,
                'settled_at' => $settled ? date('Y-m-d H:i:s') : null,
                'id'         => $loanId,
                'expected'   => self::STATUS_OPEN,
            ]
        )->rowCount();
    }

    /**
     * Petani dengan utang berjalan, terbesar lebih dulu (kartu "Peminjam Aktif").
     *
     * @return list<array<string, mixed>> kunci: miner_id, full_name, phone_number,
     *         location, outstanding, loan_count, last_issued_on
     */
    public static function activeBorrowers(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return Database::fetchAll(
            'SELECT `l`.`miner_id`, `m`.`full_name`, `m`.`phone_number`, `m`.`location`,'
            . ' SUM(`l`.`balance_remaining`) AS `outstanding`,'
            . ' COUNT(*) AS `loan_count`, MAX(`l`.`issued_on`) AS `last_issued_on`'
            . ' FROM `miner_loans` `l`'
            . ' INNER JOIN `miners` `m` ON `m`.`id` = `l`.`miner_id`'
            . ' WHERE `l`.`status` = :status'
            . ' GROUP BY `l`.`miner_id`, `m`.`full_name`, `m`.`phone_number`, `m`.`location`'
            . ' ORDER BY SUM(`l`.`balance_remaining`) DESC, `m`.`full_name` ASC'
            . ' LIMIT ' . $limit,
            ['status' => self::STATUS_OPEN]
        );
    }

    /**
     * Total sisa utang seluruh petani (kartu ringkasan) — desimal kanonik.
     */
    public static function outstandingTotal(): string
    {
        $row = Database::fetchOne(
            'SELECT COALESCE(SUM(`balance_remaining`), 0) AS `total` FROM `miner_loans`'
            . ' WHERE `status` = :status',
            ['status' => self::STATUS_OPEN]
        );

        return self::canonical((string) ($row['total'] ?? '0'));
    }

    /**
     * Nilai agregat MySQL (`'250000.00'`, `'0'`) -> desimal kanonik.
     */
    private static function canonical(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            return '0.00';
        }

        return $matches[1] . '.' . str_pad((string) ($matches[2] ?? ''), 2, '0', STR_PAD_RIGHT);
    }
}
