<?php

declare(strict_types=1);

namespace AurumFlow\Models;

use AurumFlow\Config\Database;

/**
 * Model tabel `daily_settlements` — sesi tutup buku harian (Fase 3).
 *
 * Satu baris = satu sesi settlement: hasil penjualan emas ke toko, beban
 * koordinasi, pemulihan defisit hari sebelumnya, bagi hasil 80 : 20, dan
 * defisit baru bila hasilnya minus (lihat `SettlementService`).
 *
 * Seluruh nilai moneter/berat dikirim sebagai STRING desimal kanonik
 * (`'37544000.00'`, `'18.050'`, `'93.50'`) sehingga MySQL mengonversinya
 * secara eksak tanpa pembulatan floating-point (ERD § 3.1).
 *
 * Relasi: `hulu_transactions.settlement_id` menunjuk baris di sini
 * (1 : N, lihat ERD § 2).
 */
final class DailySettlement
{
    /**
     * Seluruh kolom yang boleh ditulis. Daftar ini adalah penjaga terhadap
     * salah ketik nama kolom: `Database::insert()` akan menerima kolom apa
     * pun yang berupa identifier sah, sehingga validasi dilakukan di sini.
     */
    private const COLUMNS = [
        'id',
        'settlement_date',
        'total_dry_grams',
        'shop_water_density_pct',
        'shop_price_per_g',
        'total_shop_revenue',
        'daily_coordination_fee',
        'consumable_opex',
        'deficit_recovery_used',
        'net_settled_profit',
        'operator_share_80',
        'investor_pool_20',
        'carryover_deficit',
        'receipt_photo_url',
    ];

    /** Kolom NOT NULL tanpa DEFAULT pada DDL ERD § 4. */
    private const REQUIRED_COLUMNS = [
        'id',
        'settlement_date',
        'total_dry_grams',
        'shop_price_per_g',
        'total_shop_revenue',
        'net_settled_profit',
        'operator_share_80',
        'investor_pool_20',
    ];

    private function __construct()
    {
    }

    /**
     * Catat satu sesi tutup buku.
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
                'Kolom daily_settlements tidak dikenal: ' . implode(', ', $unknown)
            );
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($data));

        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'Kolom daily_settlements wajib belum diisi: ' . implode(', ', $missing)
            );
        }

        Database::insert('daily_settlements', $data);
    }

    /**
     * Sesi tutup buku terbaru (untuk widget riwayat).
     *
     * Presisi `created_at` adalah detik (item O-1), sehingga urutan
     * disempurnakan dengan `settlement_date` lalu `id` yang unik.
     *
     * @return list<array<string, mixed>>
     */
    public static function latest(int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));

        return Database::fetchAll(
            'SELECT * FROM `daily_settlements` '
            . 'ORDER BY `settlement_date` DESC, `created_at` DESC, `id` DESC LIMIT :limit',
            ['limit' => $limit]
        );
    }

    /**
     * Jumlah sesi tutup buku pada satu tanggal.
     */
    public static function countForDate(string $date): int
    {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS `total` FROM `daily_settlements` WHERE `settlement_date` = :date',
            ['date' => $date]
        );

        return (int) ($row['total'] ?? 0);
    }

    public static function count(): int
    {
        $row = Database::fetchOne('SELECT COUNT(*) AS `total` FROM `daily_settlements`');

        return (int) ($row['total'] ?? 0);
    }
}
