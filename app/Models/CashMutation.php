<?php

declare(strict_types=1);

namespace AurumFlow\Models;

use AurumFlow\Config\Database;

/**
 * Model tabel `cash_mutations` — riwayat mutasi kas per pos fisik.
 *
 * Fase 2 menulis mutasi `out` (pembayaran tebusan ke penambang). Sejak
 * Fase 3, mutasi `in` mencatat kas masuk dari likuidasi toko: pengembalian
 * modal belanja hulu ke `Kas Meja Bakar` dan laba bersih putaran ke
 * `Brankas Utama`.
 */
final class CashMutation
{
    public const TYPE_IN = 'in';

    public const TYPE_OUT = 'out';

    private function __construct()
    {
    }

    public static function insert(
        string $id,
        string $registerId,
        string $mutationType,
        string $amount,
        string $sourceDestination,
        ?string $notes = null
    ): void {
        Database::insert('cash_mutations', [
            'id'                 => $id,
            'register_id'        => $registerId,
            'mutation_type'      => $mutationType,
            'amount'             => $amount,
            'source_destination' => $sourceDestination,
            'notes'              => $notes,
        ]);
    }

    public static function countFor(string $registerId, string $mutationType): int
    {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS `total` FROM `cash_mutations` '
            . 'WHERE `register_id` = :register_id AND `mutation_type` = :mutation_type',
            ['register_id' => $registerId, 'mutation_type' => $mutationType]
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Total nominal mutasi pada satu pos kas, sebagai string desimal kanonik.
     */
    public static function sumFor(string $registerId, string $mutationType): string
    {
        $row = Database::fetchOne(
            'SELECT COALESCE(SUM(`amount`), 0) AS `total` FROM `cash_mutations` '
            . 'WHERE `register_id` = :register_id AND `mutation_type` = :mutation_type',
            ['register_id' => $registerId, 'mutation_type' => $mutationType]
        );

        $value = trim((string) ($row['total'] ?? '0'));

        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            return '0.00';
        }

        return $matches[1] . '.' . str_pad((string) ($matches[2] ?? ''), 2, '0', STR_PAD_RIGHT);
    }
}
