<?php

declare(strict_types=1);

namespace AurumFlow\Models;

use AurumFlow\Config\Database;
use AurumFlow\Services\TellerService;

/**
 * Model tabel `bridge_loans` — dana talangan operasional (Fase 6).
 *
 * Satu baris = satu peristiwa peminjaman kas tunai jangka pendek dari pihak
 * LUAR (tanpa akun) untuk menutup Kas Meja Bakar yang habis pada malam hari.
 * Pokok dikembalikan bersama fee flat satu siklus (T+1) pada tutup buku siang
 * berikutnya (lihat `BridgeLoanService`).
 *
 * Aturan yang dipegang tabel ini (rencana Fase 6 § 3.1, PRD § 3.5):
 *   - Pokok **bukan** pendapatan: ia hanya melintas di kas operasional dan
 *     tidak pernah masuk `daily_settlements`.
 *   - `fee_absorbed` diserap hanya dari `operator_share_80`; `fee_uncovered`
 *     adalah porsi fee yang melebihi hak pengelola (dibayar dari kas kerja
 *     pengelola). `investor_pool_20` tidak pernah tersentuh.
 *
 * Seluruh nilai moneter dikirim sebagai STRING desimal kanonik
 * (`'10000000.00'`) sehingga MySQL mengonversinya eksak tanpa floating-point
 * (ERD § 3.1).
 *
 * Urutan FIFO (`created_at ASC, id ASC`) dipakai jalur baca terpanas: karena
 * `created_at` berpresisi detik (item O-1), `id` yang unik menutup kemungkinan
 * dua baris pada detik yang sama berurutan acak.
 */
final class BridgeLoan
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SETTLED = 'settled';

    public const FEE_TYPE_PCT = 'pct';

    public const FEE_TYPE_FIXED = 'fixed';

    /**
     * Kolom yang boleh ditulis. Daftar ini penjaga terhadap salah ketik nama
     * kolom: `Database::insert()` menerima identifier apa pun yang sah,
     * sehingga validasi dilakukan di sini (pola `DailySettlement`).
     */
    private const COLUMNS = [
        'id',
        'lender_name',
        'principal_amount',
        'fee_type',
        'fee_rate_pct',
        'fee_amount',
        'fee_absorbed',
        'fee_uncovered',
        'status',
        'settlement_id',
        'register_id',
        'received_at',
        'notes',
    ];

    /** Kolom NOT NULL tanpa DEFAULT pada DDL (ERD § 4 point 10). */
    private const REQUIRED_COLUMNS = [
        'id',
        'lender_name',
        'principal_amount',
        'fee_type',
        'fee_amount',
        'register_id',
        'received_at',
    ];

    /** Kolom yang dibaca jalur aktif/pengembalian (tanpa `SELECT *`). */
    private const READ_COLUMNS = '`id`, `lender_name`, `principal_amount`, `fee_type`, '
        . '`fee_rate_pct`, `fee_amount`, `fee_absorbed`, `fee_uncovered`, `status`, '
        . '`settlement_id`, `register_id`, `received_at`, `notes`, `created_at`';

    private function __construct()
    {
    }

    /**
     * Catat satu talangan (dipanggil DI DALAM transaksi `BridgeLoanService`).
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
                'Kolom bridge_loans tidak dikenal: ' . implode(', ', $unknown)
            );
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($data));

        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'Kolom bridge_loans wajib belum diisi: ' . implode(', ', $missing)
            );
        }

        Database::insert('bridge_loans', $data);
    }

    /**
     * Seluruh talangan yang belum dikembalikan, urutan FIFO.
     *
     * Dipakai pratinjau Layar B/C dan konteks `SettlementService::batchContext()`.
     *
     * @return list<array<string, mixed>>
     */
    public static function listActive(): array
    {
        return Database::fetchAll(
            'SELECT ' . self::READ_COLUMNS . ' FROM `bridge_loans` '
            . 'WHERE `status` = :status ORDER BY `created_at` ASC, `id` ASC',
            ['status' => self::STATUS_ACTIVE]
        );
    }

    /**
     * Kunci seluruh talangan aktif untuk durasi transaksi tutup buku.
     *
     * WAJIB dipanggil DI DALAM `Database::transaction()`. `FOR UPDATE`
     * menyerialkan pengembalian sehingga dua sesi tutup buku tidak dapat
     * mengembalikan talangan yang sama dua kali (rencana § 6.2 langkah 2c).
     *
     * @return list<array<string, mixed>>
     */
    public static function lockActiveForUpdate(): array
    {
        return Database::fetchAll(
            'SELECT ' . self::READ_COLUMNS . ' FROM `bridge_loans` '
            . 'WHERE `status` = :status ORDER BY `created_at` ASC, `id` ASC FOR UPDATE',
            ['status' => self::STATUS_ACTIVE]
        );
    }

    /**
     * Ringkasan talangan aktif (kartu Layar B/C) — tanpa penguncian.
     *
     * @return array{count: int, principal: string, fee: string, total: string}
     */
    public static function activeTotals(): array
    {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS `total`, '
            . 'COALESCE(SUM(`principal_amount`), 0) AS `principal`, '
            . 'COALESCE(SUM(`fee_amount`), 0) AS `fee` '
            . 'FROM `bridge_loans` WHERE `status` = :status',
            ['status' => self::STATUS_ACTIVE]
        );

        $principal = self::canonical((string) ($row['principal'] ?? '0.00'));
        $fee = self::canonical((string) ($row['fee'] ?? '0.00'));

        return [
            'count'     => (int) ($row['total'] ?? 0),
            'principal' => $principal,
            'fee'       => $fee,
            'total'     => self::addMoney($principal, $fee),
        ];
    }

    /**
     * Talangan yang sudah dikembalikan pada satu sesi tutup buku (jejak audit).
     *
     * @return list<array<string, mixed>>
     */
    public static function listForSettlement(string $settlementId): array
    {
        return Database::fetchAll(
            'SELECT ' . self::READ_COLUMNS . ' FROM `bridge_loans` '
            . 'WHERE `settlement_id` = :settlement_id ORDER BY `created_at` ASC, `id` ASC',
            ['settlement_id' => $settlementId]
        );
    }

    /**
     * Tandai satu talangan selesai dikembalikan (dipanggil DI DALAM transaksi
     * `SettlementService::settle()`).
     *
     * `fee_absorbed` + `fee_uncovered` = `fee_amount`; keduanya dihitung
     * `BridgeLoanService::allocateAbsorption()` dan tidak pernah berasal dari
     * peramban.
     *
     * @return int jumlah baris terpengaruh (0 = baris tidak ditemukan)
     */
    public static function markSettled(
        string $id,
        string $settlementId,
        string $feeAbsorbed,
        string $feeUncovered
    ): int {
        return Database::query(
            'UPDATE `bridge_loans` SET `status` = :status, `settlement_id` = :settlement_id, '
            . '`fee_absorbed` = :fee_absorbed, `fee_uncovered` = :fee_uncovered '
            . 'WHERE `id` = :id AND `status` = :expected',
            [
                'status'        => self::STATUS_SETTLED,
                'settlement_id' => $settlementId,
                'fee_absorbed'  => $feeAbsorbed,
                'fee_uncovered' => $feeUncovered,
                'id'            => $id,
                'expected'      => self::STATUS_ACTIVE,
            ]
        )->rowCount();
    }

    // -----------------------------------------------------------------------
    // Konversi nilai uang (satu pemilik: TellerService)
    // -----------------------------------------------------------------------

    /**
     * Nilai agregat MySQL (`'10000000.00'`, `'100000'`) -> desimal kanonik.
     */
    private static function canonical(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            return '0.00';
        }

        return $matches[1] . '.' . str_pad((string) ($matches[2] ?? ''), 2, '0', STR_PAD_RIGHT);
    }

    /**
     * Penjumlahan dua desimal kanonik tanpa float (kolom `DECIMAL(14,2)`).
     *
     * Konversi uang tetap milik `TellerService` (satu pemilik) — model tidak
     * membuat salinan aturan pembulatan.
     */
    private static function addMoney(string $left, string $right): string
    {
        $leftCents = (int) str_replace('.', '', $left);
        $rightCents = (int) str_replace('.', '', $right);

        return TellerService::centsToDecimal($leftCents + $rightCents);
    }
}
