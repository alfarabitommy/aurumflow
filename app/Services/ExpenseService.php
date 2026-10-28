<?php

declare(strict_types=1);

namespace AurumFlow\Services;

use AurumFlow\Config\Database;
use AurumFlow\Helpers\Logger;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\CashMutation;
use AurumFlow\Models\CashRegister;
use AurumFlow\Models\OperationalExpense;
use PDO;

/**
 * Beban operasional non-emas / OPEX — Fase 9.
 *
 * Satu-satunya jalur mutasi kas untuk pengeluaran non-emas (koordinasi
 * lapangan, pengamanan wilayah, konsumsi/gas, lain-lain). Mengapa kelas ini
 * ada (bukan di controller):
 *   Controller menangani HTTP, model menangani SQL. Pencatatan beban menyentuh
 *   TIGA kelompok tulisan dalam satu transaksi (`operational_expenses`,
 *   `cash_mutations`, `cash_registers`) dan memiliki aturan uang sendiri — itu
 *   use-case. Pola yang sama dengan `TellerService`/`KasbonService`.
 *
 * Aturan yang tidak boleh dilanggar (audit/Audit_Biaya_Koordinasi_AurumFlow.md
 * Opsi B + AGENTS.md):
 *   1. **Satu `Database::transaction()`** dengan urutan tetap:
 *      kunci pos kas → cek saldo → mutasi `out` → saldo turun → voucher beban.
 *      Urutan itu wajib: `operational_expenses.mutation_id` memakai FK yang
 *      diperiksa InnoDB seketika, jadi baris kas harus lahir lebih dulu
 *      (sama dengan `KasbonService::disburse()`).
 *   2. **Nominal tidak pernah dipercaya sebagai hasil hitung.** Form hanya
 *      mengirim kategori, penerima, nominal, dan catatan; tanggal bisnis dan
 *      seluruh nilai kanonik dihitung peladen.
 *   3. **Parser uang dipinjam**, tidak disalin: `SettlementService::parseMoneyInput()`
 *      untuk parsing dan `TellerService::moneyToCents()`/`centsToDecimal()`
 *      untuk konversi. Tidak ada parser ketiga di proyek ini.
 *   4. **Saldo tidak boleh minus.** Beban yang melebihi isi laci ditolak
 *      sebelum satu tulisan pun dibuat (422 tanpa tulisan).
 *   5. Voucher ditulis dengan `settlement_id = NULL`; tutup buku berikutnya
 *      yang menyerapnya (`SettlementService::settle()`), sehingga beban pada
 *      hari tanpa penjualan tetap tercatat (mode kegagalan F-1 pada audit).
 */
final class ExpenseService
{
    /** Batas nilai kolom DECIMAL(14,2). */
    private const MAX_MONEY_CENTS = 99999999999999;

    private function __construct()
    {
    }

    /**
     * Catat satu beban operasional: kas keluar + voucher, satu transaksi.
     *
     * @param array<string, mixed> $input kunci: category, payee_name, amount,
     *        notes, recorded_by (users.id sesi — bukan kiriman form)
     *
     * @throws \InvalidArgumentException input tidak sah (pesan siap tampil)
     * @throws \RuntimeException invariant dilanggar (pos kas hilang / saldo kurang)
     */
    public static function pay(array $input): void
    {
        $amountCents = SettlementService::parseMoneyInput($input['amount'] ?? null, 'Nominal beban');

        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Nominal beban harus lebih besar dari nol.');
        }

        if ($amountCents > self::MAX_MONEY_CENTS) {
            throw new \InvalidArgumentException(
                'Nominal beban melebihi kapasitas pembukuan. Pecah menjadi beberapa penyerahan.'
            );
        }

        $category = self::categoryInput($input['category'] ?? null);
        $payeeName = self::payeeInput($input['payee_name'] ?? null);
        $notes = self::notesInput($input['notes'] ?? null);

        $amount = TellerService::centsToDecimal($amountCents);
        $expenseDate = date('Y-m-d');
        $expenseId = Security::uuidV4();
        $mutationId = Security::uuidV4();
        $label = self::mutationLabel($category, $payeeName);
        $mutationNotes = self::mutationNotes($expenseId, $category, $expenseDate);
        $actorId = self::actorId($input['recorded_by'] ?? null);

        /** @var array<string, mixed> $summary */
        $summary = Database::transaction(static function (PDO $pdo) use (
            $expenseId,
            $expenseDate,
            $category,
            $payeeName,
            $amount,
            $amountCents,
            $label,
            $mutationNotes,
            $mutationId,
            $notes,
            $actorId
        ): array {
            // 1. Kunci pos kas meja (satu-satunya kunci; beban tidak menyentuh
            //    dompet modal, talangan, maupun hulu).
            $register = CashRegister::lockTellerForUpdate();

            if ($register === null) {
                throw new \RuntimeException(
                    'Pos kas "' . CashRegister::TELLER_NAME . '" tidak ditemukan. Jalankan instalasi basis data.'
                );
            }

            $registerId = (string) $register['id'];
            $balanceCents = TellerService::moneyToCents((string) $register['current_balance']);

            // 2. Cek saldo SEBELUM menulis apa pun.
            if ($balanceCents < $amountCents) {
                throw new \RuntimeException(sprintf(
                    'Saldo %s (%s) tidak cukup untuk beban %s. Catat talangan atau isi kas lebih dahulu.',
                    (string) $register['register_name'],
                    TellerService::centsToDecimal($balanceCents),
                    $amount
                ));
            }

            // 3. Baris kas keluar.
            CashMutation::insert(
                $mutationId,
                $registerId,
                CashMutation::TYPE_OUT,
                $amount,
                $label,
                $mutationNotes
            );

            // 4. Saldo turun (relatif, string desimal kanonik).
            CashRegister::adjustBalance($registerId, TellerService::centsToDecimal(-$amountCents));

            // 5. Voucher beban — SETELAH mutasi (FK mutation_id diperiksa seketika).
            OperationalExpense::insert([
                'id'           => $expenseId,
                'expense_date' => $expenseDate,
                'category'     => $category,
                'payee_name'   => $payeeName,
                'amount'       => $amount,
                'register_id'  => $registerId,
                'mutation_id'  => $mutationId,
                'recorded_by'  => $actorId,
                'notes'        => $notes,
            ]);

            return [
                'expense_id'      => $expenseId,
                'mutation_id'     => $mutationId,
                'register_id'     => $registerId,
                'register_name'   => (string) $register['register_name'],
                'category'        => $category,
                'category_label'  => OperationalExpense::categoryLabel($category),
                'payee_name'      => $payeeName,
                'amount'          => $amount,
                'expense_date'    => $expenseDate,
                'balance_before'  => TellerService::centsToDecimal($balanceCents),
                'balance_after'   => TellerService::centsToDecimal($balanceCents - $amountCents),
            ];
        });

        Logger::info('Beban operasional dicatat', [
            'expense_id'   => $summary['expense_id'],
            'kategori'     => $summary['category'],
            'penerima'     => $summary['payee_name'],
            'nominal'      => $summary['amount'],
            'register'     => $summary['register_name'],
            'saldo_sesudah' => $summary['balance_after'],
        ]);
    }

    /**
     * Label mutasi kas: prefiks per kategori + nama penerima, dipotong agar
     * tidak melebihi `cash_mutations.source_destination` VARCHAR(100).
     */
    public static function mutationLabel(string $category, string $payeeName): string
    {
        $prefix = OperationalExpense::mutationPrefix($category);
        $room = OperationalExpense::MAX_LABEL_CHARS - self::charCount($prefix);

        return $prefix . self::cut($payeeName, max(1, $room));
    }

    // -----------------------------------------------------------------------
    // Parsing & validasi input lapangan
    // -----------------------------------------------------------------------

    /**
     * Kategori: hanya nilai enum dari `OperationalExpense::categories()`.
     *
     * @throws \InvalidArgumentException
     */
    private static function categoryInput(mixed $raw): string
    {
        $value = is_string($raw) ? trim($raw) : '';

        if ($value === '') {
            throw new \InvalidArgumentException('Kategori beban wajib dipilih.');
        }

        if (!in_array($value, OperationalExpense::categories(), true)) {
            throw new \InvalidArgumentException('Kategori beban tidak dikenal.');
        }

        return $value;
    }

    /**
     * Nama penerima wajib: beban tanpa nama penerima tidak dapat diaudit.
     *
     * @throws \InvalidArgumentException
     */
    private static function payeeInput(mixed $raw): string
    {
        if (!is_string($raw)) {
            throw new \InvalidArgumentException('Nama penerima beban wajib diisi.');
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $raw));

        if ($value === '') {
            throw new \InvalidArgumentException('Nama penerima beban wajib diisi.');
        }

        if (preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException('Nama penerima memuat karakter yang tidak dikenal.');
        }

        if (self::charCount($value) > OperationalExpense::MAX_PAYEE_CHARS) {
            throw new \InvalidArgumentException(
                'Nama penerima terlalu panjang (maksimal ' . OperationalExpense::MAX_PAYEE_CHARS . ' karakter).'
            );
        }

        return $value;
    }

    /**
     * Catatan opsional (teks bebas, dipotong pada batas aman layar).
     */
    private static function notesInput(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $value = trim($raw);

        if ($value === '') {
            return null;
        }

        return self::cut($value, OperationalExpense::MAX_NOTES_CHARS);
    }

    /**
     * `users.id` sesi untuk jejak audit (`recorded_by`).
     */
    private static function actorId(mixed $raw): ?string
    {
        if (!is_string($raw) || !Security::isUuid(trim($raw))) {
            return null;
        }

        return trim($raw);
    }

    /**
     * Jejak audit pada `cash_mutations.notes`: mengaitkan baris kas ke voucher.
     */
    private static function mutationNotes(string $expenseId, string $category, string $expenseDate): string
    {
        return sprintf(
            'expense_id=%s; kategori=%s; tanggal=%s; arah=keluar',
            $expenseId,
            $category,
            $expenseDate
        );
    }

    private static function charCount(string $value): int
    {
        return function_exists('mb_strlen') ? (int) mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private static function cut(string $value, int $maxChars): string
    {
        if (self::charCount($value) <= $maxChars) {
            return $value;
        }

        return function_exists('mb_substr')
            ? (string) mb_substr($value, 0, $maxChars, 'UTF-8')
            : substr($value, 0, $maxChars);
    }
}
