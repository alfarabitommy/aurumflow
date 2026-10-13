<?php

declare(strict_types=1);

namespace AurumFlow\Models;

use AurumFlow\Config\Database;

/**
 * Model tabel `profit_distributions` — distribusi pool 20 % per dompet (Fase 4).
 *
 * Satu baris = "modal sekian dari dompet ini bekerja pada sesi settlement itu,
 * dan dividen sekian menjadi haknya". Baris ini juga satu-satunya sumber
 * "modal sedang bekerja" (lihat `capital_utilized`), karena skema ERD memang
 * tidak memiliki kolom status modal yang berjalan.
 *
 * Baris ditulis **hanya** untuk dompet yang benar-benar menyerap modal
 * (`capital_utilized > 0`), termasuk dompet Pengelola dengan
 * `profit_earned = 0.00` (PRD § 3.4: modal pengelola tidak mengambil jatah pool
 * tetapi serapannya tetap tercatat). Instalasi tanpa dompet bermodal karena itu
 * tidak menulis apa pun — perilaku Fase 3 tetap sah.
 *
 * Relasi: `daily_settlements` 1 : N `profit_distributions` N : 1
 * `capital_wallets` (ERD § 2).
 */
final class ProfitDistribution
{
    private function __construct()
    {
    }

    /**
     * Catat satu baris distribusi.
     *
     * @param string $capitalUtilized sen sebagai string desimal kanonik (`'620426471.00'`)
     * @param string $profitEarned     idem
     */
    public static function insert(
        string $id,
        string $settlementId,
        string $walletId,
        string $capitalUtilized,
        string $profitEarned
    ): void {
        Database::insert('profit_distributions', [
            'id'               => $id,
            'settlement_id'    => $settlementId,
            'wallet_id'        => $walletId,
            'capital_utilized' => $capitalUtilized,
            'profit_earned'    => $profitEarned,
        ]);
    }

    /**
     * Seluruh baris satu sesi settlement, urut `id` (deterministik walau
     * `created_at` berpresisi detik — item O-1).
     *
     * @return list<array<string, mixed>>
     */
    public static function listForSettlement(string $settlementId): array
    {
        return Database::fetchAll(
            'SELECT `id`, `settlement_id`, `wallet_id`, `capital_utilized`, `profit_earned` '
            . 'FROM `profit_distributions` WHERE `settlement_id` = :settlement_id ORDER BY `id` ASC',
            ['settlement_id' => $settlementId]
        );
    }

    /**
     * Total dividen satu sesi sebagai string desimal kanonik.
     *
     * Invarian yang dijaga `AllocationEngine`: nilainya harus sama dengan
     * `daily_settlements.investor_pool_20` (diuji QC Fase 4 grup F).
     */
    public static function sumProfit(string $settlementId): string
    {
        return self::sumColumn('profit_earned', $settlementId);
    }

    /**
     * Total modal terserap satu sesi sebagai string desimal kanonik.
     */
    public static function sumUtilized(string $settlementId): string
    {
        return self::sumColumn('capital_utilized', $settlementId);
    }

    private static function sumColumn(string $column, string $settlementId): string
    {
        $column = $column === 'profit_earned' ? 'profit_earned' : 'capital_utilized';

        $row = Database::fetchOne(
            'SELECT COALESCE(SUM(`' . $column . '`), 0) AS `total` FROM `profit_distributions` '
            . 'WHERE `settlement_id` = :settlement_id',
            ['settlement_id' => $settlementId]
        );

        return self::canonical($row['total'] ?? '0');
    }

    // -----------------------------------------------------------------------
    // Sisi mitra — HANYA baris milik dompet sendiri (Fase 5)
    // -----------------------------------------------------------------------
    //
    // Dua method di bawah ini adalah SATU-SATUNYA jalan bagi dasbor investor
    // membaca tabel ini. Karena itu daftar kolomnya ditulis eksplisit dan
    // sengaja **tidak** menyentuh `daily_settlements` selain `settlement_date`:
    // omzet toko, laba, beban aparat, dan porsi pengelola tidak pernah ikut
    // terambil, sehingga kebocoran lewat `var_dump` view pun mustahil.

    /**
     * Baris distribusi TERAKHIR milik satu dompet.
     *
     * Inilah sumber "Modal Berputar" pada Layar D (`capital_utilized`).
     * Urutan sengaja tiga tingkat: tanggal putaran, lalu `created_at`, lalu
     * `id` — `created_at` berpresisi detik sehingga dua tulisan pada detik yang
     * sama dapat berurutan acak (item O-1).
     *
     * @return array<string, mixed>|null
     */
    public static function latestForWallet(string $walletId): ?array
    {
        return Database::fetchOne(
            self::walletSelect() . ' WHERE `d`.`wallet_id` = :wallet_id '
            . 'ORDER BY `s`.`settlement_date` DESC, `d`.`created_at` DESC, `d`.`id` DESC LIMIT 1',
            ['wallet_id' => $walletId]
        );
    }

    /**
     * Riwayat distribusi milik satu dompet, baris terbaru lebih dulu.
     *
     * `$limit` selalu berupa integer hasil clamp di sini (bukan nilai dari
     * klien), sehingga penulisannya sebagai literal aman sekaligus menghindari
     * `LIMIT ?` yang perilakunya bergantung pada driver.
     *
     * @return list<array<string, mixed>>
     */
    public static function listForWallet(string $walletId, int $limit = 12): array
    {
        $limit = max(1, min(60, $limit));

        return Database::fetchAll(
            self::walletSelect() . ' WHERE `d`.`wallet_id` = :wallet_id '
            . 'ORDER BY `s`.`settlement_date` DESC, `d`.`created_at` DESC, `d`.`id` DESC '
            . 'LIMIT ' . $limit,
            ['wallet_id' => $walletId]
        );
    }

    /**
     * Total dividen satu dompet sepanjang waktu (string desimal kanonik).
     */
    public static function sumProfitForWallet(string $walletId): string
    {
        return self::sumProfitBetween($walletId, null, null);
    }

    /**
     * Total dividen satu dompet dalam rentang tanggal putaran (inklusif).
     *
     * Dipakai "Dividen Bulan Ini" di Layar D: batasnya tanggal kalender
     * (`YYYY-MM-DD`), bukan jam — itu yang membuat angkanya cocok dengan
     * tanggal putaran yang ditampilkan.
     */
    public static function sumProfitForWalletBetween(string $walletId, string $from, string $to): string
    {
        return self::sumProfitBetween($walletId, $from, $to);
    }

    private static function sumProfitBetween(string $walletId, ?string $from, ?string $to): string
    {
        $sql = 'SELECT COALESCE(SUM(`d`.`profit_earned`), 0) AS `total` '
            . 'FROM `profit_distributions` `d` '
            . 'INNER JOIN `daily_settlements` `s` ON `s`.`id` = `d`.`settlement_id` '
            . 'WHERE `d`.`wallet_id` = :wallet_id';
        $params = ['wallet_id' => $walletId];

        if ($from !== null && $to !== null) {
            $sql .= ' AND `s`.`settlement_date` BETWEEN :from_date AND :to_date';
            $params['from_date'] = $from;
            $params['to_date'] = $to;
        }

        $row = Database::fetchOne($sql, $params);

        return self::canonical($row['total'] ?? '0');
    }

    /**
     * Daftar kolom sisi mitra — dipakai `latestForWallet()` & `listForWallet()`.
     */
    private static function walletSelect(): string
    {
        return 'SELECT `d`.`id`, `d`.`settlement_id`, `d`.`capital_utilized`, '
            . '`d`.`profit_earned`, `d`.`created_at`, `s`.`settlement_date` '
            . 'FROM `profit_distributions` `d` '
            . 'INNER JOIN `daily_settlements` `s` ON `s`.`id` = `d`.`settlement_id`';
    }

    /**
     * Nilai agregat MySQL menjadi string desimal kanonik (`'0.00'`).
     */
    private static function canonical(mixed $value): string
    {
        $value = trim((string) $value);

        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            return '0.00';
        }

        return $matches[1] . '.' . str_pad((string) ($matches[2] ?? ''), 2, '0', STR_PAD_RIGHT);
    }
}
