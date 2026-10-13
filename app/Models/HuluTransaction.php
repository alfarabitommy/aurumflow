<?php

declare(strict_types=1);

namespace AurumFlow\Models;

use AurumFlow\Config\Database;

/**
 * Model tabel `hulu_transactions` — pembelian emas di meja kasir hulu.
 *
 * Seluruh nilai moneter dan berat dikirim sebagai STRING desimal kanonik
 * (`'28937500.00'`, `'14.850'`) sehingga MySQL mengonversinya secara eksak
 * dan tidak ada pembulatan floating-point (ERD § 3.1, QC 1.10).
 */
final class HuluTransaction
{
    public const STATUS_HOLDING = 'holding';

    /** Status setelah baris disettle pada sesi tutup buku (Fase 3). */
    public const STATUS_SETTLED = 'settled';

    /** Batas tampilan riwayat pada layar kasir. */
    public const RECENT_LIMIT_MAX = 20;

    private function __construct()
    {
    }

    /**
     * Catat satu transaksi pembelian hulu (selalu berstatus `holding`).
     *
     * `settlement_id` sengaja dibiarkan NULL: pengisiannya dilakukan oleh
     * mesin settlement harian pada Fase 3 (ERD: relasi 1 : N).
     */
    public static function insert(
        string $id,
        string $farmerAlias,
        string $dryWeight,
        string $buyPricePerGram,
        string $grossTebusTotal,
        string $flatBurnFee,
        string $operatorBurnCut,
        string $consumableCut,
        string $netCashPaid
    ): void {
        Database::insert('hulu_transactions', [
            'id'                => $id,
            'settlement_id'     => null,
            'farmer_alias'      => $farmerAlias,
            'dry_weight'        => $dryWeight,
            'buy_price_per_g'   => $buyPricePerGram,
            'gross_tebus_total' => $grossTebusTotal,
            'flat_burn_fee'     => $flatBurnFee,
            'operator_burn_cut' => $operatorBurnCut,
            'consumable_cut'    => $consumableCut,
            'net_cash_paid'     => $netCashPaid,
            'status'            => self::STATUS_HOLDING,
        ]);
    }

    /**
     * Transaksi terbaru untuk widget riwayat pada layar kasir.
     *
     * Urutan memakai `created_at DESC, id DESC`. Presisi kolom `created_at`
     * adalah detik, sehingga transaksi pada detik yang sama dapat berurutan
     * tidak deterministik — dicatat sebagai item O-1 pada dokumen rencana.
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(int $limit = 3): array
    {
        $limit = max(1, min(self::RECENT_LIMIT_MAX, $limit));

        return Database::fetchAll(
            'SELECT `id`, `farmer_alias`, `dry_weight`, `buy_price_per_g`, '
            . '`net_cash_paid`, `status`, `created_at` '
            . 'FROM `hulu_transactions` ORDER BY `created_at` DESC, `id` DESC LIMIT :limit',
            ['limit' => $limit]
        );
    }

    /**
     * Total gram emas yang masih berstatus `holding` (stok fisik di meja).
     *
     * Selalu dikembalikan sebagai string 3 desimal (`'18.050'`) tanpa
     * melewati float, sehingga dapat dipakai langsung untuk assertion QC.
     */
    public static function holdingTotalGrams(): string
    {
        $row = Database::fetchOne(
            'SELECT COALESCE(SUM(`dry_weight`), 0) AS `total` '
            . 'FROM `hulu_transactions` WHERE `status` = :status',
            ['status' => self::STATUS_HOLDING]
        );

        return self::normaliseGrams((string) ($row['total'] ?? '0'));
    }

    public static function holdingCount(): int
    {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS `total` FROM `hulu_transactions` WHERE `status` = :status',
            ['status' => self::STATUS_HOLDING]
        );

        return (int) ($row['total'] ?? 0);
    }

    // -----------------------------------------------------------------------
    // Batch tutup buku (Fase 3)
    // -----------------------------------------------------------------------

    /**
     * Kunci dan ambil SELURUH emas yang belum disettle.
     *
     * WAJIB dipanggil DI DALAM `Database::transaction()`. `FOR UPDATE`
     * mencegah dua sesi tutup buku (atau satu settlement dan satu transaksi
     * kasir) bekerja pada himpunan baris yang sama sekaligus.
     *
     * Filter `settlement_id IS NULL` bersifat pertahanan berlapis: baris
     * `settled` selalu punya `settlement_id`, tetapi bila ada data lama yang
     * menyimpang, baris itu tidak akan ikut disettle dua kali.
     *
     * @return list<array<string, mixed>>
     */
    public static function holdingRowsForUpdate(): array
    {
        return Database::fetchAll(
            'SELECT `id`, `dry_weight`, `net_cash_paid` FROM `hulu_transactions` '
            . 'WHERE `status` = :status AND `settlement_id` IS NULL '
            . 'ORDER BY `created_at`, `id` FOR UPDATE',
            ['status' => self::STATUS_HOLDING]
        );
    }

    /**
     * Ringkasan batch `holding` TANPA penguncian — untuk tampilan layar
     * rekonsiliasi sebelum operator menekan eksekusi.
     *
     * Nilai dikembalikan sebagai string desimal kanonik (`'18.050'`,
     * `'35157500.00'`) agar tidak pernah melewati float.
     *
     * @return array{count: int, grams: string, cost: string}
     */
    public static function holdingTotals(): array
    {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS `count`, COALESCE(SUM(`dry_weight`), 0) AS `grams`, '
            . 'COALESCE(SUM(`net_cash_paid`), 0) AS `cost` '
            . 'FROM `hulu_transactions` WHERE `status` = :status AND `settlement_id` IS NULL',
            ['status' => self::STATUS_HOLDING]
        );

        return [
            'count' => (int) ($row['count'] ?? 0),
            'grams' => self::normaliseGrams((string) ($row['grams'] ?? '0')),
            'cost'  => self::normaliseMoney((string) ($row['cost'] ?? '0')),
        ];
    }

    /**
     * Pindahkan satu batch ke status `settled` dan kaitkan ke sesi tutup buku.
     *
     * Hanya id yang sudah dikunci lewat `holdingRowsForUpdate()` yang boleh
     * dikirim ke sini, sehingga himpunan yang disettle persis sama dengan
     * himpunan yang dipakai menghitung laba.
     *
     * @param list<string> $ids
     * @return int jumlah baris yang berubah
     */
    public static function settleBatch(string $settlementId, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $placeholders = [];
        $params = ['settlement_id' => $settlementId];

        foreach (array_values($ids) as $index => $id) {
            $key = 'id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        return Database::query(
            'UPDATE `hulu_transactions` SET `status` = :settled, `settlement_id` = :settlement_id '
            . 'WHERE `status` = :holding AND `settlement_id` IS NULL '
            . 'AND `id` IN (' . implode(', ', $placeholders) . ')',
            $params + [
                'settled' => self::STATUS_SETTLED,
                'holding' => self::STATUS_HOLDING,
            ]
        )->rowCount();
    }

    public static function countByStatus(string $status): int
    {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS `total` FROM `hulu_transactions` WHERE `status` = :status',
            ['status' => $status]
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Normalkan nilai uang dari database menjadi 2 desimal tanpa float.
     */
    private static function normaliseMoney(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            return '0.00';
        }

        return $matches[1] . '.' . str_pad((string) ($matches[2] ?? ''), 2, '0', STR_PAD_RIGHT);
    }

    /**
     * Normalkan nilai gram dari database menjadi 3 desimal.
     *
     * SUM() pada MySQL/MariaDB sudah berskala 3, tetapi nilai `'0'`
     * (saat belum ada baris) tetap perlu diseragamkan agar pembandingan
     * string pada QC tidak bergantung pada dialek server.
     */
    private static function normaliseGrams(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $value, $matches) !== 1) {
            return '0.000';
        }

        $fraction = str_pad((string) ($matches[2] ?? ''), 3, '0', STR_PAD_RIGHT);

        return $matches[1] . '.' . $fraction;
    }
}
