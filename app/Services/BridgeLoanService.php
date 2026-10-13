<?php

declare(strict_types=1);

namespace AurumFlow\Services;

use AurumFlow\Config\Database;
use AurumFlow\Helpers\Formatter;
use AurumFlow\Helpers\Logger;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\BridgeLoan;
use AurumFlow\Models\CashMutation;
use AurumFlow\Models\CashRegister;
use PDO;

/**
 * Mesin dana talangan (Fase 6): fee, penerimaan kas malam, pengembalian T+1.
 *
 * Mengapa kelas ini ada (bukan di controller atau model):
 *   Controller menangani HTTP, model menangani SQL. Penerimaan talangan
 *   menyentuh TIGA tabel dalam satu transaksi dan membawa aturan uang sendiri
 *   (fee nominal atau persen, pembulatan half away from zero, penyerapan yang
 *   HANYA boleh menyentuh hak pengelola) — itu use-case, bukan SQL. Pola yang
 *   sama dengan `TellerService` (Fase 2) dan `CapitalService` (Fase 4).
 *
 * Aturan uang (rencana Fase 6 § 4) — TIDAK boleh dilanggar:
 *   1. Pokok & fee diurai ke SEN integer; tidak ada float pada jalur uang.
 *      Nilai masuk database sebagai string desimal kanonik.
 *   2. Fee `pct` SELALU dihitung peladen:
 *      `intdiv(pokok_sen × tarif_perseratus + 5000, 10000)` (half away from zero).
 *   3. Penyerapan fee memakai FIFO greedy `min(fee, sisa hak pengelola)`,
 *      sehingga `Σ absorbed = min(Σ fee, operator_share_80)` PERSIS dan
 *      `Σ absorbed + Σ uncovered = Σ fee`.
 *   4. `investor_pool_20` tidak pernah disentuh kelas ini: fee dibebankan hanya
 *      pada porsi 80 % pengelola (PRD § 3.4/§ 3.5).
 *
 * Konversi uang tetap milik `TellerService` (`moneyToCents()`/`centsToDecimal()`),
 * dan parser nominal tetap milik `SettlementService::parseMoneyInput()` —
 * jangan membuat salinan ketiga dari keduanya.
 */
final class BridgeLoanService
{
    /** Bentuk fee yang diterima (kolom `bridge_loans.fee_type`). */
    public const FEE_TYPE_PCT = BridgeLoan::FEE_TYPE_PCT;

    public const FEE_TYPE_FIXED = BridgeLoan::FEE_TYPE_FIXED;

    /** Awalan keterangan mutasi kas (kolom `source_destination` VARCHAR(100)). */
    public const LABEL_IN_PREFIX = 'Talangan dari ';

    public const LABEL_OUT_PREFIX = 'Kembali talangan: ';

    /** Batas kolom `lender_name` VARCHAR(50) (pola `farmer_alias`). */
    public const MAX_LENDER_CHARS = 50;

    /** `fee_rate_pct` DECIMAL(5,2) -> maksimum 100,00 % = 10000 perseratus. */
    public const MAX_RATE_HUNDREDTHS = 10000;

    /** Batas nilai kolom DECIMAL(14,2) — dipakai penjaga meluap. */
    private const MAX_MONEY_CENTS = 99999999999999;

    private function __construct()
    {
    }

    // -----------------------------------------------------------------------
    // Kalkulasi (murni — dapat diuji tanpa database)
    // -----------------------------------------------------------------------

    /**
     * Validasi input dan hitung fee satu talangan.
     *
     * Bentuk kembalian selalu memuat nilai kanonik **dan** sen, sehingga
     * pemanggil (controller, view, QC) tidak perlu mengurai ulang.
     *
     * `fee_amount` dari peramban **tidak pernah** dipercaya: untuk
     * `fee_type = 'pct'` nominal dihitung dari tarif (rencana § 4.2).
     *
     * @param array<string, mixed> $input kunci: lender_name, principal_amount,
     *        fee_type, fee_rate_pct, fee_fixed, notes
     * @return array{lender_name: string, principal: string, principal_cents: int,
     *               fee_type: string, fee_rate_pct: ?string, fee: string,
     *               fee_cents: int, total_return: string, total_return_cents: int,
     *               notes: ?string}
     *
     * @throws \InvalidArgumentException input tidak sah (pesan siap tampil)
     */
    public static function quoteFee(array $input): array
    {
        $lender = self::normaliseLender($input['lender_name'] ?? null);
        $principalCents = SettlementService::parseMoneyInput(
            $input['principal_amount'] ?? null,
            'Pokok dana talangan'
        );

        if ($principalCents <= 0) {
            throw new \InvalidArgumentException(
                'Pokok dana talangan harus lebih besar dari nol. Contoh: 10.000.000'
            );
        }

        $feeType = isset($input['fee_type']) && is_string($input['fee_type'])
            ? trim($input['fee_type'])
            : '';

        if ($feeType !== self::FEE_TYPE_PCT && $feeType !== self::FEE_TYPE_FIXED) {
            throw new \InvalidArgumentException(
                'Bentuk fee harus dipilih: Nominal (Rp) atau Persen (%).'
            );
        }

        $rateHundredths = null;
        $feeCents = 0;

        if ($feeType === self::FEE_TYPE_PCT) {
            $rateHundredths = self::parseFeeRateInput($input['fee_rate_pct'] ?? null);

            if ($rateHundredths > intdiv(PHP_INT_MAX, $principalCents)) {
                throw new \InvalidArgumentException('Nilai talangan di luar jangkauan perhitungan.');
            }

            // Half away from zero: +5000 lalu dibagi 10^4 (tarif = perseratus persen).
            $feeCents = intdiv($principalCents * $rateHundredths + 5000, 10000);
        } else {
            $feeCents = SettlementService::parseMoneyInput($input['fee_fixed'] ?? null, 'Fee talangan');
        }

        if ($feeCents > self::MAX_MONEY_CENTS) {
            throw new \InvalidArgumentException(
                'Nilai fee talangan melebihi kapasitas pembukuan. Periksa bentuk dan besaran fee.'
            );
        }

        $totalReturnCents = $principalCents + $feeCents;

        if ($totalReturnCents > self::MAX_MONEY_CENTS) {
            throw new \InvalidArgumentException(
                'Pokok + fee melebihi kapasitas pembukuan. Pecah talangan menjadi dua pencatatan.'
            );
        }

        return [
            'lender_name'        => $lender,
            'principal'          => TellerService::centsToDecimal($principalCents),
            'principal_cents'    => $principalCents,
            'fee_type'           => $feeType,
            'fee_rate_pct'       => $rateHundredths === null
                ? null
                : self::hundredthsToDecimal($rateHundredths),
            'fee'                => TellerService::centsToDecimal($feeCents),
            'fee_cents'          => $feeCents,
            'total_return'       => TellerService::centsToDecimal($totalReturnCents),
            'total_return_cents' => $totalReturnCents,
            'notes'              => self::normaliseNotes($input['notes'] ?? null),
        ];
    }

    /**
     * Tarif fee persen -> perseratus persen (`'1,00'` -> `100`).
     *
     * Konvensi field ini **sama** dengan kadar timbang air
     * (`SettlementService::parseDensity()`): satu pemisah (`.` atau `,`) berarti
     * DESIMAL, sufiks `%` diabaikan, maksimum 2 desimal, `> 0` dan `<= 100`.
     * QC grup B membandingkan kedua parser pada tabel kasus yang sama sehingga
     * keduanya tidak dapat menyimpang diam-diam — pola yang sama dengan
     * `parseMoneyInput()` vs `parsePriceInput()`.
     *
     * @throws \InvalidArgumentException tarif tidak sah (pesan siap tampil)
     */
    public static function parseFeeRateInput(mixed $raw): int
    {
        $value = is_string($raw) ? trim($raw) : '';
        $value = trim((string) preg_replace('/\s*%\s*$/', '', $value));

        if (!is_string($raw) || $value === '') {
            throw new \InvalidArgumentException('Tarif fee wajib diisi bila bentuk fee = Persen. Contoh: 1,00');
        }

        if (preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException('Tarif fee memuat karakter yang tidak dikenal.');
        }

        if (str_contains($value, '-')) {
            throw new \InvalidArgumentException('Tarif fee tidak boleh negatif.');
        }

        $digits = preg_replace('/[^0-9.,]/', '', $value) ?? '';

        if ($digits === '') {
            throw new \InvalidArgumentException('Tarif fee harus berupa angka. Contoh: 1,00');
        }

        if (str_contains($digits, ',') && str_contains($digits, '.')) {
            throw new \InvalidArgumentException('Gunakan satu pemisah desimal saja. Contoh: 1,00');
        }

        $digits = str_replace(',', '.', $digits);

        if (substr_count($digits, '.') > 1) {
            throw new \InvalidArgumentException('Tarif fee tidak valid. Contoh: 1,00');
        }

        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $digits, $matches) !== 1) {
            throw new \InvalidArgumentException('Tarif fee tidak valid atau lebih dari 2 desimal. Contoh: 1,00');
        }

        $hundredths = (int) $matches[1] * 100
            + (int) str_pad((string) ($matches[2] ?? ''), 2, '0', STR_PAD_RIGHT);

        if ($hundredths <= 0) {
            throw new \InvalidArgumentException('Tarif fee harus lebih besar dari nol. Contoh: 1,00');
        }

        if ($hundredths > self::MAX_RATE_HUNDREDTHS) {
            throw new \InvalidArgumentException('Tarif fee tidak boleh melebihi 100,00 %.');
        }

        return $hundredths;
    }

    /**
     * Penyerapan fee secara FIFO dibatasi hak laba pengelola.
     *
     * Murni dan deterministik: `$loans` **wajib** sudah urut FIFO
     * (`created_at ASC, id ASC`) — kedua pemasok barisnya
     * (`BridgeLoan::listActive()`/`lockActiveForUpdate()`) menegakkan urutan itu
     * lewat `ORDER BY`, dan `SettlementService::quote()` memanggil fungsi ini
     * sehingga pratinjau, penyimpanan, dan QC memakai rumus yang sama.
     *
     * @param int $operatorShareCents `operator_share_80` sesi itu (sen)
     * @param list<array<string, mixed>> $loans baris talangan (butuh id,
     *        lender_name, principal_cents, fee_cents)
     * @return array{rows: list<array{id: string, lender_name: string,
     *               principal_cents: int, fee_cents: int, absorbed_cents: int,
     *               uncovered_cents: int, return_cents: int}>, count: int,
     *               principal_cents: int, fee_cents: int, absorbed_cents: int,
     *               uncovered_cents: int, total_return_cents: int}
     */
    public static function allocateAbsorption(int $operatorShareCents, array $loans): array
    {
        $remaining = max(0, $operatorShareCents);
        $rows = [];
        $principalTotal = 0;
        $feeTotal = 0;
        $absorbedTotal = 0;
        $uncoveredTotal = 0;

        foreach ($loans as $loan) {
            $feeCents = max(0, (int) ($loan['fee_cents'] ?? 0));
            $principalCents = max(0, (int) ($loan['principal_cents'] ?? 0));
            $absorbed = min($feeCents, $remaining);

            $remaining -= $absorbed;

            $rows[] = [
                'id'              => (string) ($loan['id'] ?? ''),
                'lender_name'     => (string) ($loan['lender_name'] ?? ''),
                'principal_cents' => $principalCents,
                'fee_cents'       => $feeCents,
                'absorbed_cents'  => $absorbed,
                'uncovered_cents' => $feeCents - $absorbed,
                'return_cents'    => $principalCents + $feeCents,
            ];

            $principalTotal += $principalCents;
            $feeTotal += $feeCents;
            $absorbedTotal += $absorbed;
            $uncoveredTotal += $feeCents - $absorbed;
        }

        return [
            'rows'               => $rows,
            'count'              => count($rows),
            'principal_cents'    => $principalTotal,
            'fee_cents'          => $feeTotal,
            'absorbed_cents'     => $absorbedTotal,
            'uncovered_cents'    => $uncoveredTotal,
            'total_return_cents' => $principalTotal + $feeTotal,
        ];
    }

    // -----------------------------------------------------------------------
    // Baca (kartu Layar B/C + konteks pratinjau tutup buku)
    // -----------------------------------------------------------------------

    /**
     * Talangan aktif (FIFO) dalam bentuk siap pakai view & konteks `quote()`.
     *
     * Setiap baris memuat nilai kanonik (untuk `Formatter`) **dan** sen (untuk
     * aritmetika), sehingga tidak ada pemanggil yang perlu mengurai ulang.
     *
     * @return list<array<string, mixed>>
     */
    public static function activeLoans(): array
    {
        return self::presentRows(BridgeLoan::listActive());
    }

    /**
     * Baris `bridge_loans` (kanonik dari database) -> bentuk siap pakai
     * (kanonik + sen), urutannya dipertahankan apa adanya.
     *
     * Dipakai `activeLoans()` (pratinjau Layar B/C) **dan**
     * `SettlementService::settle()` untuk baris yang sudah TERKUNCI
     * (`BridgeLoan::lockActiveForUpdate()`), sehingga pratinjau dan eksekusi
     * memakai bentuk data yang sama.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function presentRows(array $rows): array
    {
        $presented = [];

        foreach ($rows as $row) {
            $presented[] = self::presentRow($row);
        }

        return $presented;
    }

    /**
     * Ringkasan talangan aktif untuk kartu Layar B/C.
     *
     * @return array{count: int, principal: string, fee: string, total: string}
     */
    public static function activeTotals(): array
    {
        return BridgeLoan::activeTotals();
    }

    /**
     * View-model kartu "Talangan Aktif" — satu pemilik pemetaan untuk Layar B
     * (meja kasir) **dan** Layar C (rekonsiliasi), sehingga tak ada dua salinan
     * label yang bisa berbeda diam-diam.
     *
     * `$totals` opsional: pemanggil yang sudah membaca `activeTotals()` (mis.
     * `SettlementController`) mengopernya supaya keputusan tampil/tidaknya baris
     * fee dan isi kartu berasal dari **satu** pembacaan — dua kueri terpisah
     * dapat berbeda bila operator lain mencatat talangan tepat di antara
     * keduanya, sehingga kartu dan baris pada layar yang sama saling bertentangan.
     *
     * Seluruh angka tampilan lewat `Formatter` (pola `InvestorService`).
     *
     * @param array{count: int, principal: string, fee: string, total: string}|null $totals
     * @return array{count: int, rows: list<array<string, string>>,
     *               principal_label: string, fee_label: string, total_label: string}
     */
    public static function viewModel(?array $totals = null): array
    {
        $totals ??= BridgeLoan::activeTotals();
        $rows = [];

        foreach (self::activeLoans() as $loan) {
            $rows[] = [
                'lender_name'     => (string) $loan['lender_name'],
                'principal_label' => Formatter::rupiah($loan['principal']),
                'fee_label'       => Formatter::rupiah($loan['fee']),
                'total_label'     => Formatter::rupiah($loan['total_return']),
                'received_label'  => Formatter::tanggalId((string) $loan['received_at'], true),
                'jenis_label'     => self::feeTypeLabel($loan),
            ];
        }

        return [
            'count'           => $totals['count'],
            'rows'            => $rows,
            'principal_label' => Formatter::rupiah($totals['principal']),
            'fee_label'       => Formatter::rupiah($totals['fee']),
            'total_label'     => Formatter::rupiah($totals['total']),
        ];
    }

    /**
     * Label bentuk fee: "Nominal" atau "Persen (1,00 %)" — nilai enum mentah
     * (`pct`/`fixed`) tidak pernah dicetak ke layar.
     *
     * @param array<string, mixed> $loan
     */
    private static function feeTypeLabel(array $loan): string
    {
        if ((string) ($loan['fee_type'] ?? '') !== self::FEE_TYPE_PCT) {
            return 'Nominal';
        }

        $rate = $loan['fee_rate_pct'] === null ? '0.00' : (string) $loan['fee_rate_pct'];

        return 'Persen (' . Formatter::persen($rate) . ')';
    }

    // -----------------------------------------------------------------------
    // Penerimaan kas (tiga tabel, satu transaksi PDO — SAD § 4.1)
    // -----------------------------------------------------------------------

    /**
     * Catat dana talangan yang diterima meja kas dan tambah saldonya.
     *
     * Urutan di dalam transaksi:
     *   1. kunci baris kas meja (`SELECT … FOR UPDATE`);
     *   2. verifikasi kapasitas kolom saldo (SEBELUM tulisan apa pun);
     *   3. INSERT `bridge_loans` (status `active`, `received_at` jam peladen);
     *   4. UPDATE saldo `cash_registers` (saldo + pokok);
     *   5. INSERT `cash_mutations` (type `in`) sebagai jejak audit.
     *
     * Setiap kegagalan menggulung SELURUH langkah: tidak boleh ada kas yang
     * bertambah tanpa jejak pinjaman, atau sebaliknya.
     *
     * @param array<string, mixed> $input field form (lihat `quoteFee()`)
     * @return array<string, mixed> ringkasan untuk flash & QC + hasil `quoteFee()`
     *
     * @throws \InvalidArgumentException input tidak sah
     * @throws \RuntimeException invariant dilanggar (pos kas hilang, saldo meluap)
     */
    public static function receive(array $input): array
    {
        $quote = self::quoteFee($input);
        $loanId = Security::uuidV4();
        $mutationId = Security::uuidV4();
        $receivedAt = date('Y-m-d H:i:s');

        /** @var array<string, mixed> $result */
        $result = Database::transaction(static function (PDO $pdo) use (
            $quote,
            $loanId,
            $mutationId,
            $receivedAt
        ): array {
            $register = CashRegister::lockTellerForUpdate();

            if ($register === null) {
                throw new \RuntimeException(
                    'Pos kas "' . CashRegister::TELLER_NAME . '" tidak ditemukan. Hubungi administrator.'
                );
            }

            $registerId = (string) $register['id'];
            $balanceBeforeCents = TellerService::moneyToCents((string) $register['current_balance']);

            if ($balanceBeforeCents + $quote['principal_cents'] > self::MAX_MONEY_CENTS) {
                throw new \RuntimeException(
                    'Saldo kas meja tidak dapat menampung pokok talangan ini. '
                    . 'Setor hasil penjualan toko terlebih dahulu.'
                );
            }

            BridgeLoan::insert([
                'id'               => $loanId,
                'lender_name'      => $quote['lender_name'],
                'principal_amount' => $quote['principal'],
                'fee_type'         => $quote['fee_type'],
                'fee_rate_pct'     => $quote['fee_rate_pct'],
                'fee_amount'       => $quote['fee'],
                'fee_absorbed'     => '0.00',
                'fee_uncovered'    => '0.00',
                'status'           => BridgeLoan::STATUS_ACTIVE,
                'settlement_id'    => null,
                'register_id'      => $registerId,
                'received_at'      => $receivedAt,
                'notes'            => $quote['notes'],
            ]);

            CashRegister::adjustBalance($registerId, $quote['principal']);

            CashMutation::insert(
                $mutationId,
                $registerId,
                CashMutation::TYPE_IN,
                $quote['principal'],
                self::LABEL_IN_PREFIX . $quote['lender_name'],
                self::receiveNotes($loanId, $quote)
            );

            return [
                'loan_id'        => $loanId,
                'mutation_id'    => $mutationId,
                'register_id'    => $registerId,
                'register_name'  => (string) $register['register_name'],
                'balance_before' => TellerService::centsToDecimal($balanceBeforeCents),
                'balance_after'  => TellerService::centsToDecimal(
                    $balanceBeforeCents + $quote['principal_cents']
                ),
            ];
        });

        Logger::info('Dana talangan diterima', [
            'loan_id'       => $result['loan_id'],
            'register'      => $result['register_name'],
            'pemberi_dana'  => $quote['lender_name'],
            'pokok'         => $quote['principal'],
            'jenis_fee'     => $quote['fee_type'],
            'fee'           => $quote['fee'],
            'saldo_sesudah' => $result['balance_after'],
        ]);

        return $result + $quote;
    }

    // -----------------------------------------------------------------------
    // Pengembalian pokok + fee (dipanggil DI DALAM transaksi tutup buku)
    // -----------------------------------------------------------------------

    /**
     * Kembalikan pokok + fee setiap talangan pada satu sesi tutup buku.
     *
     * Dipanggil `SettlementService::settle()` **di dalam** transaksinya, setelah
     * pos kas dikunci dan alokasi kas ditulis — pola `AllocationEngine::apply()`.
     * Untuk setiap talangan: saldo Kas Meja Bakar turun `pokok + fee`, satu
     * mutasi `out` ditulis (jejak audit per pemberi dana), dan barisnya menjadi
     * `settled` + `settlement_id` + `fee_absorbed`/`fee_uncovered`.
     *
     * @param PDO $pdo koneksi transaksi (bukti pemanggilan di dalam transaksi)
     * @param string $settlementId sesi tutup buku yang mengembalikannya
     * @param list<array<string, mixed>> $plan hasil `allocateAbsorption()['rows']` (FIFO)
     * @param string $registerId pos kas pembayar (Kas Meja Bakar)
     * @return array{count: int, principal: string, fee: string, absorbed: string,
     *               uncovered: string, total_return: string, total_return_cents: int,
     *               mutation_ids: list<string>, lender_names: list<string>}
     *
     * @throws \RuntimeException baris talangan tidak dapat ditandai selesai
     */
    public static function settleLoans(
        PDO $pdo,
        string $settlementId,
        array $plan,
        string $registerId
    ): array {
        $mutationIds = [];
        $lenderNames = [];
        $principalTotal = 0;
        $feeTotal = 0;
        $absorbedTotal = 0;
        $uncoveredTotal = 0;

        foreach ($plan as $row) {
            $loanId = (string) ($row['id'] ?? '');
            $lender = (string) ($row['lender_name'] ?? '');
            $principalCents = (int) ($row['principal_cents'] ?? 0);
            $feeCents = (int) ($row['fee_cents'] ?? 0);
            $absorbedCents = (int) ($row['absorbed_cents'] ?? 0);
            $uncoveredCents = (int) ($row['uncovered_cents'] ?? 0);
            $returnCents = (int) ($row['return_cents'] ?? $principalCents + $feeCents);

            if ($loanId === '') {
                throw new \RuntimeException(
                    'Rencana pengembalian talangan tidak lengkap (id kosong). Seluruh perubahan dibatalkan.'
                );
            }

            $updated = BridgeLoan::markSettled(
                $loanId,
                $settlementId,
                TellerService::centsToDecimal($absorbedCents),
                TellerService::centsToDecimal($uncoveredCents)
            );

            if ($updated !== 1) {
                throw new \RuntimeException(
                    'Talangan "' . $lender . '" tidak dapat ditandai selesai (status berubah). '
                    . 'Seluruh perubahan dibatalkan.'
                );
            }

            CashRegister::adjustBalance($registerId, TellerService::centsToDecimal(-$returnCents));

            $mutationId = Security::uuidV4();
            $mutationIds[] = $mutationId;
            $lenderNames[] = $lender;

            CashMutation::insert(
                $mutationId,
                $registerId,
                CashMutation::TYPE_OUT,
                TellerService::centsToDecimal($returnCents),
                self::LABEL_OUT_PREFIX . $lender,
                self::settleNotes($loanId, $settlementId, $principalCents, $feeCents, $absorbedCents, $uncoveredCents)
            );

            $principalTotal += $principalCents;
            $feeTotal += $feeCents;
            $absorbedTotal += $absorbedCents;
            $uncoveredTotal += $uncoveredCents;
        }

        return [
            'count'              => count($plan),
            'principal'          => TellerService::centsToDecimal($principalTotal),
            'fee'                => TellerService::centsToDecimal($feeTotal),
            'absorbed'           => TellerService::centsToDecimal($absorbedTotal),
            'uncovered'          => TellerService::centsToDecimal($uncoveredTotal),
            'total_return'       => TellerService::centsToDecimal($principalTotal + $feeTotal),
            'total_return_cents' => $principalTotal + $feeTotal,
            'mutation_ids'       => $mutationIds,
            'lender_names'       => $lenderNames,
        ];
    }

    // -----------------------------------------------------------------------
    // Normalisasi input lapangan
    // -----------------------------------------------------------------------

    /**
     * Nama/alias pemberi dana: wajib diisi, dibersihkan dari markup, dibatasi
     * panjang kolom (pola `TellerService::normaliseAlias()`).
     *
     * Tidak ada nilai bawaan: nama pemberi dana adalah inti pencatatannya
     * (fase ini tidak menyediakan akun/login untuknya).
     *
     * @throws \InvalidArgumentException nama kosong / tidak sah
     */
    private static function normaliseLender(mixed $raw): string
    {
        $lender = is_string($raw) ? Security::sanitize($raw) : '';

        if ($lender === '') {
            throw new \InvalidArgumentException('Nama pemberi dana wajib diisi. Contoh: Pak Haji Daud');
        }

        if (preg_match('//u', $lender) !== 1) {
            throw new \InvalidArgumentException('Nama pemberi dana memuat karakter yang tidak dikenal.');
        }

        $characters = preg_match_all('/./us', $lender);

        if ($characters === false || $characters > self::MAX_LENDER_CHARS) {
            throw new \InvalidArgumentException(
                'Nama pemberi dana maksimal ' . self::MAX_LENDER_CHARS . ' karakter.'
            );
        }

        return $lender;
    }

    private static function normaliseNotes(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $notes = Security::sanitize($raw);

        return $notes === '' ? null : $notes;
    }

    /**
     * Baris `bridge_loans` -> bentuk siap pakai (kanonik + sen).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function presentRow(array $row): array
    {
        $principalCents = TellerService::moneyToCents((string) ($row['principal_amount'] ?? '0.00'));
        $feeCents = TellerService::moneyToCents((string) ($row['fee_amount'] ?? '0.00'));

        return [
            'id'              => (string) ($row['id'] ?? ''),
            'lender_name'     => (string) ($row['lender_name'] ?? ''),
            'principal'       => TellerService::centsToDecimal($principalCents),
            'principal_cents' => $principalCents,
            'fee'             => TellerService::centsToDecimal($feeCents),
            'fee_cents'       => $feeCents,
            'total_return'    => TellerService::centsToDecimal($principalCents + $feeCents),
            'fee_type'        => (string) ($row['fee_type'] ?? ''),
            'fee_rate_pct'    => $row['fee_rate_pct'] === null ? null : (string) $row['fee_rate_pct'],
            'received_at'     => (string) ($row['received_at'] ?? ''),
            'notes'           => $row['notes'] === null ? null : (string) $row['notes'],
        ];
    }

    private static function hundredthsToDecimal(int $hundredths): string
    {
        return intdiv($hundredths, 100) . '.' . str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }

    // -----------------------------------------------------------------------
    // Keterangan mutasi kas
    // -----------------------------------------------------------------------

    /**
     * Jejak audit mutasi kas masuk (`source_destination` VARCHAR(100) sudah
     * diisi label; kolom `notes` memuat rinciannya).
     *
     * @param array<string, mixed> $quote
     */
    private static function receiveNotes(string $loanId, array $quote): string
    {
        return sprintf(
            'bridge_loan_id=%s; pokok=%s; jenis_fee=%s; tarif=%s; fee=%s; kembali_pada=tutup_buku_berikutnya',
            $loanId,
            (string) $quote['principal'],
            (string) $quote['fee_type'],
            $quote['fee_rate_pct'] === null ? '-' : (string) $quote['fee_rate_pct'],
            (string) $quote['fee']
        );
    }

    private static function settleNotes(
        string $loanId,
        string $settlementId,
        int $principalCents,
        int $feeCents,
        int $absorbedCents,
        int $uncoveredCents
    ): string {
        return sprintf(
            'bridge_loan_id=%s; settlement_id=%s; pokok=%s; fee=%s; fee_diserap=%s; fee_tak_tertutup=%s',
            $loanId,
            $settlementId,
            TellerService::centsToDecimal($principalCents),
            TellerService::centsToDecimal($feeCents),
            TellerService::centsToDecimal($absorbedCents),
            TellerService::centsToDecimal($uncoveredCents)
        );
    }
}
