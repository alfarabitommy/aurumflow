<?php

declare(strict_types=1);

namespace AurumFlow\Models;

use AurumFlow\Config\Database;

/**
 * Model tabel `miner_loan_ledger` — riwayat kas kasbon petani (Fase 8).
 *
 * Aturan yang dipegang tabel ini (rencana Fase 8 § 1.1 + § 2.6):
 *   - **Satu baris kas = satu baris ledger** (`mutation_id`), sehingga jejak
 *     piutang selalu dapat ditelusuri ke mutasi kas fisiknya.
 *   - `amount` SELALU positif; arah kas ditentukan `entry_type`
 *     (`disbursement` → mutasi `out`, `repayment` → mutasi `in`).
 *   - `balance_after` adalah sisa utang PINJAMAN ITU setelah entri, bukan total
 *     utang petani (petani bisa punya beberapa kasbon sekaligus).
 *   - "Potong Nota" dicatat sebagai `repayment` + `payment_method='nota'`; ia
 *     TIDAK memotong `hulu_transactions` (lihat AGENTS.md).
 */
final class MinerLoanLedger
{
    public const ENTRY_DISBURSEMENT = 'disbursement';

    public const ENTRY_REPAYMENT = 'repayment';

    /** Kolom yang boleh ditulis (penjaga salah ketik nama kolom). */
    private const COLUMNS = [
        'id',
        'loan_id',
        'entry_type',
        'payment_method',
        'amount',
        'balance_after',
        'register_id',
        'mutation_id',
        'recorded_by',
        'notes',
    ];

    /** Kolom NOT NULL tanpa DEFAULT pada DDL (ERD § 4 point 13). */
    private const REQUIRED_COLUMNS = [
        'id',
        'loan_id',
        'entry_type',
        'amount',
        'balance_after',
        'register_id',
        'mutation_id',
    ];

    private function __construct()
    {
    }

    /**
     * Catat satu pergerakan kas kasbon (dipanggil DI DALAM transaksi
     * `KasbonService::disburse()`/`repay()`).
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
                'Kolom miner_loan_ledger tidak dikenal: ' . implode(', ', $unknown)
            );
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($data));

        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'Kolom miner_loan_ledger wajib belum diisi: ' . implode(', ', $missing)
            );
        }

        Database::insert('miner_loan_ledger', $data);
    }

    /**
     * Riwayat terbaru lintas petani (kartu "Buku Kasbon").
     *
     * `$limit` adalah integer hasil clamp di sini (bukan nilai dari klien),
     * sehingga penulisannya sebagai literal aman sekaligus menghindari
     * `LIMIT ?` yang perilakunya bergantung pada driver.
     *
     * @return list<array<string, mixed>> kunci: id, entry_type, payment_method,
     *         amount, balance_after, created_at, full_name, loan_issued_on,
     *         recorder_name
     */
    public static function recent(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        return Database::fetchAll(
            'SELECT `g`.`id`, `g`.`entry_type`, `g`.`payment_method`, `g`.`amount`,'
            . ' `g`.`balance_after`, `g`.`created_at`, `m`.`full_name`,'
            . ' `l`.`issued_on` AS `loan_issued_on`, `u`.`full_name` AS `recorder_name`'
            . ' FROM `miner_loan_ledger` `g`'
            . ' INNER JOIN `miner_loans` `l` ON `l`.`id` = `g`.`loan_id`'
            . ' INNER JOIN `miners` `m` ON `m`.`id` = `l`.`miner_id`'
            . ' LEFT JOIN `users` `u` ON `u`.`id` = `g`.`recorded_by`'
            . ' ORDER BY `g`.`created_at` DESC, `g`.`id` DESC'
            . ' LIMIT ' . $limit
        );
    }

    /**
     * Riwayat satu pinjaman (jejak audit per kasbon), terlama lebih dulu.
     *
     * @return list<array<string, mixed>>
     */
    public static function listForLoan(string $loanId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return Database::fetchAll(
            'SELECT `id`, `entry_type`, `payment_method`, `amount`, `balance_after`,'
            . ' `register_id`, `mutation_id`, `recorded_by`, `notes`, `created_at`'
            . ' FROM `miner_loan_ledger` WHERE `loan_id` = :loan_id'
            . ' ORDER BY `created_at` ASC, `id` ASC LIMIT ' . $limit,
            ['loan_id' => $loanId]
        );
    }
}
