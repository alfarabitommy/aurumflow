<?php

declare(strict_types=1);

namespace AurumFlow\Services;

use AurumFlow\Config\Constants;
use AurumFlow\Config\Database;
use AurumFlow\Helpers\Formatter;
use AurumFlow\Helpers\Logger;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\CashMutation;
use AurumFlow\Models\CashRegister;
use AurumFlow\Models\Officer;
use AurumFlow\Models\OfficerLedgerEntry;
use AurumFlow\Models\SystemSetting;
use AurumFlow\Models\User;
use PDO;

/**
 * Buku hak mitra lapangan / aparat — Fase 10.
 *
 * Satu-satunya jalur use-case untuk: pembuatan akun petugas, perubahan tarif,
 * bonus manual (akrual), pencairan hak (kas keluar), dan akrual potongan di
 * dalam tutup buku. Mengapa kelas ini ada (bukan di controller): controller
 * menangani HTTP, model menangani SQL, sedangkan aturan uang hak petugas —
 * berapa yang dikreditkan, kapan laba berkurang, kapan kas keluar — adalah
 * use-case dengan invariant sendiri.
 *
 * Aturan uang (rencana Fase 10 § 4 — TIDAK boleh dilanggar):
 *   1. **Potongan = persentase laba kotor** (`max(0, pendapatan − modal hulu)`),
 *      dibulatkan setengah sen ke atas dengan gaya yang sama seperti fee
 *      talangan: `floor((basis_sen × pct_perseratus + 5000) / 10000)`.
 *   2. **Dua kanal pemotongan laba, masing-masing sekali**: potongan pada
 *      akrual tutup buku, bonus pada penyerapan akrual (`settlement_id` diisi
 *      tutup buku berikutnya). Pencairan **tidak pernah** menyentuh laba dan
 *      **tidak pernah** membuat voucher `operational_expenses` — karena itu
 *      tidak ada beban yang dihitung dua kali.
 *   3. **Pencairan = kas keluar + saldo turun** dalam satu transaksi, satu baris
 *      `cash_mutations` `out` (label `Hak petugas: <nama>`), dari pos kas yang
 *      dipilih operator dari daftar putih (`kas_meja`/`brankas`).
 *   4. **Parser dipinjam, tidak disalin**: `SettlementService::parseMoneyInput()`
 *      untuk nominal uang; `TellerService::moneyToCents()`/`centsToDecimal()`
 *      untuk konversi. Tidak ada parser uang ketiga di proyek ini.
 *   5. **Nominal hasil hitung tidak pernah dari klien**: saldo, `balance_after`,
 *      potongan, dan total penyerapan dihitung peladen dari baris terkunci.
 *   6. **Batas Σ tarif** hidup di `system_settings.officer_cut_total_cap_pct`
 *      (parameter bisnis tidak boleh hidup di kode).
 */
final class OfficerService
{
    /** Kunci `system_settings` batas atas Σ tarif potongan seluruh petugas aktif. */
    public const SETTING_CUT_TOTAL_CAP_PCT = 'officer_cut_total_cap_pct';

    /** Token pos kas pada form pencairan (bukan UUID — tidak pernah dari klien). */
    public const POS_TELLER = 'kas_meja';

    public const POS_BRANKAS = 'brankas';

    /** Label pos kas untuk tampilan & pesan. */
    public const POS_LABELS = [
        self::POS_TELLER   => CashRegister::TELLER_NAME,
        self::POS_BRANKAS  => CashRegister::BRANKAS_NAME,
    ];

    /** Prefiks label mutasi kas keluar untuk pencairan hak. */
    public const MUTATION_PREFIX = 'Hak petugas: ';

    /** Batas praktis `cash_mutations.source_destination` (VARCHAR 100). */
    public const MAX_LABEL_CHARS = 100;

    /** Panjang minimum nama petugas. */
    public const NAME_MIN_LENGTH = 3;

    /** Batas nilai kolom DECIMAL(14,2) dalam sen. */
    private const MAX_MONEY_CENTS = 99999999999999;

    private function __construct()
    {
    }

    // -----------------------------------------------------------------------
    // Tata kelola akun (Layar G, operator saja)
    // -----------------------------------------------------------------------

    /**
     * Buat akun petugas + akun bukunya dalam satu transaksi.
     *
     * @param array<string, mixed> $input kunci: full_name, phone_number, pin,
     *        pin_confirm, cut_pct
     * @return array{officer_id: string, user_id: string, full_name: string,
     *               phone_number: string, cut_pct: string}
     *
     * @throws \InvalidArgumentException pesan siap tampil
     * @throws \RuntimeException kegagalan lapisan sistem (mis. pepper kosong)
     */
    public static function create(array $input): array
    {
        $fullName = self::validName($input['full_name'] ?? null);
        $phoneNumber = self::validPhone($input['phone_number'] ?? null);
        $pin = self::stringValue($input['pin'] ?? null);
        $pinConfirm = self::stringValue($input['pin_confirm'] ?? null);
        $cutHundredths = self::parsePercentInput($input['cut_pct'] ?? null, 'Persentase potongan');

        OnboardingService::pinPolicy($pin, $pinConfirm);

        if (User::findByPhoneNumber($phoneNumber, false) !== null) {
            throw new \InvalidArgumentException(
                'Nomor telepon sudah terdaftar pada akun lain. Gunakan nomor yang berbeda.'
            );
        }

        self::assertCutTotalWithinCap($cutHundredths, null);

        /** @var array{officer_id: string, user_id: string, full_name: string, phone_number: string, cut_pct: string} $result */
        $result = Database::transaction(static function () use (
            $fullName,
            $phoneNumber,
            $pin,
            $cutHundredths
        ): array {
            try {
                $userId = User::createWithPin($fullName, $phoneNumber, $pin, Constants::ROLE_OFFICER);
            } catch (\PDOException $exception) {
                throw new \InvalidArgumentException(
                    'Nomor telepon sudah terdaftar pada akun lain. Gunakan nomor yang berbeda.',
                    0,
                    $exception
                );
            }

            $officerId = Security::uuidV4();

            Officer::insert([
                'id'              => $officerId,
                'user_id'         => $userId,
                'cut_pct'         => AllocationEngine::hundredthsToDecimal($cutHundredths),
                'balance_payable' => '0.00',
            ]);

            return [
                'officer_id'   => $officerId,
                'user_id'      => $userId,
                'full_name'    => $fullName,
                'phone_number' => $phoneNumber,
                'cut_pct'      => AllocationEngine::hundredthsToDecimal($cutHundredths),
            ];
        });

        Logger::info('Akun mitra lapangan dibuat', [
            'officer_id' => $result['officer_id'],
            'phone_number' => $result['phone_number'],
            'cut_pct'    => $result['cut_pct'],
            'oleh'       => (string) ($_SESSION['user_id'] ?? ''),
        ]);

        return $result;
    }

    /**
     * Ubah tarif potongan seorang petugas.
     *
     * @param array<string, mixed> $input kunci: officer_id, cut_pct
     * @return array{officer_id: string, full_name: string, cut_pct: string}
     */
    public static function updateCutPct(array $input): array
    {
        $officer = self::findOfficerWithUser($input['officer_id'] ?? null);
        $cutHundredths = self::parsePercentInput($input['cut_pct'] ?? null, 'Persentase potongan');

        self::assertCutTotalWithinCap($cutHundredths, (string) $officer['id']);

        Database::transaction(static function () use ($officer, $cutHundredths): void {
            Officer::updateCutPct((string) $officer['id'], AllocationEngine::hundredthsToDecimal($cutHundredths));
        });

        Logger::info('Tarif potongan mitra lapangan diubah', [
            'officer_id' => (string) $officer['id'],
            'cut_pct'    => AllocationEngine::hundredthsToDecimal($cutHundredths),
            'oleh'       => (string) ($_SESSION['user_id'] ?? ''),
        ]);

        return [
            'officer_id' => (string) $officer['id'],
            'full_name'  => (string) $officer['full_name'],
            'cut_pct'    => AllocationEngine::hundredthsToDecimal($cutHundredths),
        ];
    }

    /**
     * Reset PIN petugas (PIN lama langsung hangus).
     *
     * @param array<string, mixed> $input kunci: user_id, pin, pin_confirm
     * @return array{officer_id: string, full_name: string, phone_number: string}
     */
    public static function resetPin(array $input): array
    {
        $officer = self::findOfficerWithUser($input['user_id'] ?? null);
        $pin = self::stringValue($input['pin'] ?? null);
        $pinConfirm = self::stringValue($input['pin_confirm'] ?? null);

        OnboardingService::pinPolicy($pin, $pinConfirm);

        Database::transaction(static function () use ($officer, $pin): void {
            User::updatePin((string) $officer['user_id'], $pin);
        });

        Logger::info('PIN mitra lapangan direset', [
            'officer_id' => (string) $officer['id'],
            'oleh'       => (string) ($_SESSION['user_id'] ?? ''),
        ]);

        return [
            'officer_id'   => (string) $officer['id'],
            'full_name'    => (string) $officer['full_name'],
            'phone_number' => (string) $officer['phone_number'],
        ];
    }

    /**
     * Aktifkan / nonaktifkan akun petugas.
     *
     * Petugas nonaktif **berhenti berakrual** tetapi saldo haknya tetap dapat
     * dicairkan (kewajiban lama tetap dibayar).
     *
     * @param array<string, mixed> $input kunci: user_id, active
     * @return array{officer_id: string, full_name: string, active: bool}
     */
    public static function setStatus(array $input): array
    {
        $officer = self::findOfficerWithUser($input['user_id'] ?? null);
        $rawActive = self::stringValue($input['active'] ?? null);

        if ($rawActive !== '0' && $rawActive !== '1') {
            throw new \InvalidArgumentException('Status akun tidak valid.');
        }

        $active = $rawActive === '1';

        Database::transaction(static function () use ($officer, $active): void {
            User::setActive((string) $officer['user_id'], $active);
        });

        Logger::info('Status akun mitra lapangan diubah', [
            'officer_id' => (string) $officer['id'],
            'aktif'      => $active ? 'ya' : 'tidak',
            'oleh'       => (string) ($_SESSION['user_id'] ?? ''),
        ]);

        return [
            'officer_id' => (string) $officer['id'],
            'full_name'  => (string) $officer['full_name'],
            'active'     => $active,
        ];
    }

    // -----------------------------------------------------------------------
    // Bonus manual (akrual — tanpa mutasi kas, tanpa voucher OPEX)
    // -----------------------------------------------------------------------

    /**
     * Suntik bonus manual ke saldo hak petugas.
     *
     * SATU transaksi, dua tulisan: `officer_ledger_entries` (bonus) + saldo.
     * **Tidak ada** `cash_mutations`, **tidak ada** `operational_expenses`, dan
     * `cash_registers` tidak tersentuh: uangnya masih di laci. Bonus mengurangi
     * laba pada tutup buku berikutnya (penyerapan akrual), tepat satu kali.
     *
     * @param array<string, mixed> $input kunci: officer_id, amount, notes, recorded_by
     * @return array<string, mixed>
     */
    public static function injectBonus(array $input): array
    {
        $amountCents = SettlementService::parseMoneyInput($input['amount'] ?? null, 'Nominal bonus');

        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Nominal bonus harus lebih besar dari nol.');
        }

        if ($amountCents > self::MAX_MONEY_CENTS) {
            throw new \InvalidArgumentException('Nominal bonus melebihi kapasitas pembukuan.');
        }

        $notes = self::notesInput($input['notes'] ?? null);
        $actorId = self::actorId($input['recorded_by'] ?? null);
        $amount = TellerService::centsToDecimal($amountCents);

        /** @var array<string, mixed> $summary */
        $summary = Database::transaction(static function (PDO $pdo) use (
            $input,
            $amountCents,
            $amount,
            $notes,
            $actorId
        ): array {
            $officer = self::lockOfficerRow($input['officer_id'] ?? null);
            $balanceCents = TellerService::moneyToCents((string) $officer['balance_payable']);
            $balanceAfter = $balanceCents + $amountCents;

            if ($balanceAfter > self::MAX_MONEY_CENTS) {
                throw new \RuntimeException('Saldo hak petugas melebihi kapasitas pembukuan.');
            }

            OfficerLedgerEntry::insert([
                'id'            => Security::uuidV4(),
                'officer_id'    => (string) $officer['id'],
                'entry_type'    => OfficerLedgerEntry::ENTRY_BONUS,
                'amount'        => $amount,
                'balance_after' => TellerService::centsToDecimal($balanceAfter),
                'recorded_by'   => $actorId,
                'notes'         => $notes,
            ]);

            Officer::adjustBalance((string) $officer['id'], TellerService::centsToDecimal($amountCents));

            return [
                'officer_id'     => (string) $officer['id'],
                'full_name'      => (string) $officer['full_name'],
                'amount'         => $amount,
                'balance_before' => TellerService::centsToDecimal($balanceCents),
                'balance_after'  => TellerService::centsToDecimal($balanceAfter),
            ];
        });

        Logger::info('Bonus mitra lapangan dicatat', [
            'officer_id'    => $summary['officer_id'],
            'nominal'       => $summary['amount'],
            'saldo_sesudah' => $summary['balance_after'],
            'oleh'          => (string) ($_SESSION['user_id'] ?? ''),
        ]);

        return $summary;
    }

    // -----------------------------------------------------------------------
    // Pencairan hak (kas keluar + saldo turun)
    // -----------------------------------------------------------------------

    /**
     * Cairkan saldo hak petugas dari pos kas terpilih.
     *
     * SATU transaksi, empat tulisan dengan urutan tetap: kunci pos kas → kunci
     * baris `officers` → cek saldo → mutasi `out` → saldo pos turun → entri
     * buku → saldo hak turun. Urutan mutasi SEBELUM entri wajib: `mutation_id`
     * memakai FK yang diperiksa InnoDB seketika (pola `ExpenseService::pay()`).
     *
     * @param array<string, mixed> $input kunci: officer_id, amount, pos_kas,
     *        notes, recorded_by
     * @return array<string, mixed>
     */
    public static function disburse(array $input): array
    {
        $amountCents = SettlementService::parseMoneyInput($input['amount'] ?? null, 'Nominal pencairan');

        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Nominal pencairan harus lebih besar dari nol.');
        }

        if ($amountCents > self::MAX_MONEY_CENTS) {
            throw new \InvalidArgumentException('Nominal pencairan melebihi kapasitas pembukuan.');
        }

        $registerName = self::registerName($input['pos_kas'] ?? null);
        $notes = self::notesInput($input['notes'] ?? null);
        $actorId = self::actorId($input['recorded_by'] ?? null);
        $amount = TellerService::centsToDecimal($amountCents);

        /** @var array<string, mixed> $summary */
        $summary = Database::transaction(static function (PDO $pdo) use (
            $input,
            $registerName,
            $amountCents,
            $amount,
            $notes,
            $actorId
        ): array {
            $register = CashRegister::lockByNameForUpdate($registerName);

            if ($register === null) {
                throw new \RuntimeException(
                    'Pos kas "' . $registerName . '" tidak ditemukan. Jalankan instalasi basis data.'
                );
            }

            $officer = self::lockOfficerRow($input['officer_id'] ?? null);
            $balanceCents = TellerService::moneyToCents((string) $officer['balance_payable']);
            $registerCents = TellerService::moneyToCents((string) $register['current_balance']);

            if ($amountCents > $balanceCents) {
                throw new \InvalidArgumentException(sprintf(
                    'Nominal pencairan (%s) melebihi saldo hak petugas (%s).',
                    $amount,
                    TellerService::centsToDecimal($balanceCents)
                ));
            }

            if ($amountCents > $registerCents) {
                throw new \RuntimeException(sprintf(
                    'Saldo %s (%s) tidak cukup untuk pencairan hak (%s). Pilih pos kas lain atau cairkan sebagian.',
                    (string) $register['register_name'],
                    TellerService::centsToDecimal($registerCents),
                    $amount
                ));
            }

            $registerId = (string) $register['id'];
            $mutationId = Security::uuidV4();
            $label = self::mutationLabel((string) $officer['full_name']);
            $balanceAfter = $balanceCents - $amountCents;

            CashMutation::insert(
                $mutationId,
                $registerId,
                CashMutation::TYPE_OUT,
                $amount,
                $label,
                self::mutationNotes((string) $officer['id'], (string) $officer['user_id'])
            );

            CashRegister::adjustBalance($registerId, TellerService::centsToDecimal(-$amountCents));

            OfficerLedgerEntry::insert([
                'id'            => Security::uuidV4(),
                'officer_id'    => (string) $officer['id'],
                'entry_type'    => OfficerLedgerEntry::ENTRY_DISBURSEMENT,
                'amount'        => $amount,
                'balance_after' => TellerService::centsToDecimal($balanceAfter),
                'register_id'   => $registerId,
                'mutation_id'   => $mutationId,
                'recorded_by'   => $actorId,
                'notes'         => $notes,
            ]);

            Officer::adjustBalance((string) $officer['id'], TellerService::centsToDecimal(-$amountCents));

            return [
                'officer_id'     => (string) $officer['id'],
                'full_name'      => (string) $officer['full_name'],
                'amount'         => $amount,
                'register_id'    => $registerId,
                'register_name'  => (string) $register['register_name'],
                'mutation_id'    => $mutationId,
                'balance_before' => TellerService::centsToDecimal($balanceCents),
                'balance_after'  => TellerService::centsToDecimal($balanceAfter),
                'register_before' => TellerService::centsToDecimal($registerCents),
                'register_after' => TellerService::centsToDecimal($registerCents - $amountCents),
            ];
        });

        Logger::info('Hak mitra lapangan dicairkan', [
            'officer_id'     => $summary['officer_id'],
            'nominal'        => $summary['amount'],
            'register'       => $summary['register_name'],
            'saldo_sesudah'  => $summary['balance_after'],
            'oleh'           => (string) ($_SESSION['user_id'] ?? ''),
        ]);

        return $summary;
    }

    // -----------------------------------------------------------------------
    // Akrual potongan & penyerapan bonus di dalam tutup buku
    // -----------------------------------------------------------------------

    /**
     * Roster petugas untuk pratinjau & akrual.
     *
     * @param list<array<string, mixed>>|null $lockedOfficers hasil
     *        `Officer::lockAllForUpdate()` (null = baca tanpa kunci)
     * @return list<array{id: string, name: string, user_id: string, pct: string,
     *                    pct_hundredths: int}>
     */
    public static function roster(?array $lockedOfficers = null): array
    {
        $rows = $lockedOfficers ?? Database::fetchAll(
            'SELECT `id`, `user_id`, `cut_pct`, `balance_payable`, `created_at`, `updated_at`'
            . ' FROM `officers` ORDER BY `id` ASC'
        );

        if ($rows === []) {
            return [];
        }

        $userIds = [];

        foreach ($rows as $row) {
            $userIds[] = (string) ($row['user_id'] ?? '');
        }

        $profiles = Officer::userProfiles($userIds);
        $roster = [];

        foreach ($rows as $row) {
            $userId = (string) ($row['user_id'] ?? '');
            $profile = $profiles[$userId] ?? null;

            if ($profile === null || !$profile['active']) {
                continue;
            }

            $pct = (string) ($row['cut_pct'] ?? '0.00');
            $hundredths = AllocationEngine::quotaHundredths($pct);

            if ($hundredths <= 0) {
                continue;
            }

            $roster[] = [
                'id'             => (string) ($row['id'] ?? ''),
                'user_id'        => $userId,
                'name'           => $profile['name'],
                'pct'            => $pct,
                'pct_hundredths' => $hundredths,
            ];
        }

        return $roster;
    }

    /**
     * Batas atas Σ tarif potongan (perseratus persen) dari `system_settings`.
     *
     * @throws \RuntimeException nilai rusak pada basis data (fail-closed)
     */
    public static function capHundredths(): int
    {
        $raw = SystemSetting::get(self::SETTING_CUT_TOTAL_CAP_PCT, '100.00');

        try {
            return AllocationEngine::quotaHundredths((string) $raw);
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException(
                'Nilai "' . self::SETTING_CUT_TOTAL_CAP_PCT . '" pada basis data tidak valid. '
                . 'Perbaiki di system_settings sebelum menutup buku.',
                0,
                $exception
            );
        }
    }

    /**
     * Hitung potongan per petugas — FUNGSI MURNI (tanpa basis data).
     *
     * Dipakai `SettlementService::quote()` (pratinjau & eksekusi memakai rumus
     * yang sama) dan uji tanpa basis data (`qc_fase_10` grup C).
     *
     * @param list<array{id: string, name: string, pct_hundredths: int}> $roster
     * @return array{rows: list<array{id: string, name: string, pct_hundredths: int,
     *               amount_cents: int}>, cut_cents: int, cap_cents: int,
     *               sum_pct_hundredths: int, scaled: bool}
     */
    public static function previewCuts(array $roster, int $grossCents): array
    {
        $basisCents = max(0, $grossCents);
        $capCents = $basisCents;
        $sumPct = 0;
        $rows = [];
        $weights = [];

        foreach ($roster as $row) {
            $pct = (int) ($row['pct_hundredths'] ?? 0);

            if ($pct <= 0) {
                continue;
            }

            $sumPct += $pct;
            $amount = intdiv($basisCents * $pct + 5000, 10000);
            $id = (string) ($row['id'] ?? '');

            $rows[] = [
                'id'             => $id,
                'name'           => (string) ($row['name'] ?? ''),
                'pct_hundredths' => $pct,
                'amount_cents'   => $amount,
            ];

            $weights[$id] = $amount;
        }

        $cutCents = 0;

        foreach ($rows as $row) {
            $cutCents += (int) $row['amount_cents'];
        }

        $scaled = false;

        // D-2: potongan tidak pernah melebihi laba kotor. Skala hanya mungkin
        // terjadi bila Σ tarif > 100 % (data rusak) — nilai tetap dibatasi.
        if ($cutCents > $capCents) {
            $scaled = true;
            $shares = AllocationEngine::distribute($capCents, $weights)['shares'];
            $cutCents = 0;

            foreach ($rows as $index => $row) {
                $amount = (int) ($shares[(string) $row['id']] ?? 0);
                $rows[$index]['amount_cents'] = $amount;
                $cutCents += $amount;
            }
        }

        return [
            'rows'               => $rows,
            'cut_cents'          => $cutCents,
            'cap_cents'          => $capCents,
            'sum_pct_hundredths' => $sumPct,
            'scaled'             => $scaled,
        ];
    }

    /**
     * Tulis akrual potongan sesi tutup buku + perbarui saldo hak.
     *
     * WAJIB dipanggil DI DALAM transaksi `SettlementService::settle()` dengan
     * baris `officers` yang sudah dikunci (`Officer::lockAllForUpdate()`).
     *
     * @param list<array{id: string, name: string, pct_hundredths: int, amount_cents: int}> $rows
     * @param array<string, array<string, mixed>> $lockedById peta id => baris terkunci
     * @return array{count: int, total_cents: int}
     */
    public static function accrueForSettlement(
        string $settlementId,
        array $rows,
        array $lockedById,
        string $baseDecimal,
        ?string $recordedBy
    ): array {
        $totalCents = 0;
        $written = 0;

        foreach ($rows as $row) {
            $officerId = (string) ($row['id'] ?? '');
            $amountCents = (int) ($row['amount_cents'] ?? 0);

            if ($officerId === '' || $amountCents <= 0) {
                continue;
            }

            $locked = $lockedById[$officerId] ?? null;

            if ($locked === null) {
                throw new \RuntimeException(
                    'Baris petugas tidak berada dalam himpunan terkunci. Seluruh perubahan dibatalkan.'
                );
            }

            $balanceCents = TellerService::moneyToCents((string) ($locked['balance_payable'] ?? '0.00'));
            $balanceAfter = $balanceCents + $amountCents;

            OfficerLedgerEntry::insert([
                'id'            => Security::uuidV4(),
                'officer_id'    => $officerId,
                'entry_type'    => OfficerLedgerEntry::ENTRY_CUT,
                'amount'        => TellerService::centsToDecimal($amountCents),
                'balance_after' => TellerService::centsToDecimal($balanceAfter),
                'cut_pct'       => AllocationEngine::hundredthsToDecimal((int) ($row['pct_hundredths'] ?? 0)),
                'base_amount'   => $baseDecimal,
                'settlement_id' => $settlementId,
                'recorded_by'   => $recordedBy,
            ]);

            Officer::adjustBalance($officerId, TellerService::centsToDecimal($amountCents));

            $lockedById[$officerId]['balance_payable'] = TellerService::centsToDecimal($balanceAfter);
            $totalCents += $amountCents;
            $written++;
        }

        return ['count' => $written, 'total_cents' => $totalCents];
    }

    /**
     * Tandai akrual bonus sebagai terserap sesi ini (pengurang laba sekali).
     *
     * @param list<array<string, mixed>> $rows baris terkunci
     * @return array{count: int, total_cents: int}
     */
    public static function absorbBonuses(string $settlementId, array $rows): array
    {
        $ids = [];
        $totalCents = 0;

        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            $amt = (string) ($row['amount'] ?? '0.00');

            if ($id === '') {
                continue;
            }

            $ids[] = $id;
            $totalCents += TellerService::moneyToCents($amt);
        }

        $changed = OfficerLedgerEntry::absorbBonuses($settlementId, $ids);

        if ($changed !== count($ids)) {
            throw new \RuntimeException(
                'Jumlah akrual bonus yang diserap tidak sesuai (' . $changed . ' dari '
                . count($ids) . '). Seluruh perubahan dibatalkan.'
            );
        }

        return ['count' => $changed, 'total_cents' => $totalCents];
    }

    /**
     * Label mutasi kas keluar: prefiks + nama petugas, dipotong agar tidak
     * melebihi `cash_mutations.source_destination` VARCHAR(100).
     */
    public static function mutationLabel(string $officerName): string
    {
        $room = max(1, self::MAX_LABEL_CHARS - self::charCount(self::MUTATION_PREFIX));

        return self::MUTATION_PREFIX . self::cut($officerName, $room);
    }

    // -----------------------------------------------------------------------
    // View-model Layar G
    // -----------------------------------------------------------------------

    /**
     * Data Layar G: ringkasan + daftar petugas + buku hak terbaru.
     *
     * Pemformatan dilakukan di sini (satu pemilik angka/tanggal lewat
     * `Formatter`), bukan di controller maupun view.
     *
     * @return array{rows: list<array<string, mixed>>, recent: list<array<string, mixed>>,
     *               summary: array<string, mixed>}
     */
    public static function overview(): array
    {
        $officers = Officer::listWithUsers();
        $rows = [];
        $activeCount = 0;
        $payableCents = 0;
        $pctHundredths = 0;

        foreach ($officers as $officer) {
            $active = (int) ($officer['is_active'] ?? 0) === 1;
            $balanceCents = TellerService::moneyToCents((string) ($officer['balance_payable'] ?? '0.00'));
            $pct = (string) ($officer['cut_pct'] ?? '0.00');
            $hundredths = AllocationEngine::quotaHundredths($pct);

            if ($active) {
                $activeCount++;
                $pctHundredths += $hundredths;
            }

            $payableCents += $balanceCents;

            $rows[] = [
                'id'            => (string) ($officer['id'] ?? ''),
                'user_id'       => (string) ($officer['user_id'] ?? ''),
                'name'          => (string) ($officer['full_name'] ?? ''),
                'phone'         => (string) ($officer['phone_number'] ?? ''),
                'cut_pct_label' => Formatter::persen($pct),
                'cut_pct_input' => self::percentFieldValue($hundredths),
                // `Formatter::rupiah()` menerima RUPIAH, bukan sen. Nilai di sini
                // masih sen (hasil `moneyToCents()`), sehingga wajib dikonversi
                // lebih dulu — tanpa ini layar menampilkan 100x lipat.
                'balance_label' => Formatter::rupiah(TellerService::centsToDecimal($balanceCents)),
                'is_active'     => $active,
                'status_label'  => $active ? 'Aktif' : 'Nonaktif',
                'updated_label' => Formatter::tanggalId((string) ($officer['updated_at'] ?? ''), true),
            ];
        }

        $recent = [];

        foreach (self::recentEntries(20) as $entry) {
            $recent[] = $entry;
        }

        return [
            'rows'    => $rows,
            'recent'  => $recent,
            'summary' => [
                'count'              => count($rows),
                'active'             => $activeCount,
                'payable_label'      => Formatter::rupiah(TellerService::centsToDecimal($payableCents)),
                'pct_total_label'    => Formatter::persen(AllocationEngine::hundredthsToDecimal($pctHundredths)),
                'cap_label'          => Formatter::persen(AllocationEngine::hundredthsToDecimal(self::capHundredths())),
                'bonus_open_label'   => Formatter::rupiah(
                    TellerService::centsToDecimal(self::openBonusCents())
                ),
            ],
        ];
    }

    /**
     * Buku hak terbaru seluruh petugas (Layar G).
     *
     * @return list<array<string, mixed>>
     */
    public static function recentEntries(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        $rows = Database::fetchAll(
            'SELECT e.`id`, e.`entry_type`, e.`amount`, e.`balance_after`, e.`created_at`,'
            . ' u.`full_name`'
            . ' FROM `officer_ledger_entries` e'
            . ' INNER JOIN `officers` o ON o.`id` = e.`officer_id`'
            . ' INNER JOIN `users` u ON u.`id` = o.`user_id`'
            . ' ORDER BY e.`created_at` DESC, e.`id` DESC'
            . ' LIMIT ' . $limit
        );

        $entries = [];

        foreach ($rows as $row) {
            $type = (string) ($row['entry_type'] ?? '');
            $amountCents = TellerService::moneyToCents((string) ($row['amount'] ?? '0.00'));
            $isCredit = $type !== OfficerLedgerEntry::ENTRY_DISBURSEMENT;

            $entries[] = [
                'name'          => (string) ($row['full_name'] ?? ''),
                'type_label'    => OfficerLedgerEntry::typeLabel($type),
                'amount_label'  => ($isCredit ? '+ ' : '− ')
                    . Formatter::rupiah(TellerService::centsToDecimal($amountCents)),
                'balance_label' => Formatter::rupiah((string) ($row['balance_after'] ?? '0.00')),
                'tanggal'       => Formatter::tanggalId((string) ($row['created_at'] ?? ''), true),
            ];
        }

        return $entries;
    }

    /**
     * Total akrual bonus yang belum terserap (sen) — pratinjau Layar C.
     */
    public static function openBonusCents(): int
    {
        $total = 0;

        foreach (OfficerLedgerEntry::unabsorbedBonuses() as $row) {
            $total += TellerService::moneyToCents((string) ($row['amount'] ?? '0.00'));
        }

        return $total;
    }

    // -----------------------------------------------------------------------
    // Validasi & parsing
    // -----------------------------------------------------------------------

    /**
     * Pastikan Σ tarif seluruh petugas aktif + tarif baru ≤ batas sistem.
     *
     * @param string|null $excludeOfficerId petugas yang tarifnya sedang diubah
     *
     * @throws \InvalidArgumentException pesan siap tampil
     */
    private static function assertCutTotalWithinCap(int $newHundredths, ?string $excludeOfficerId): void
    {
        if ($newHundredths < 0 || $newHundredths > 10000) {
            throw new \InvalidArgumentException('Persentase potongan harus antara 0 dan 100 %.');
        }

        $cap = self::capHundredths();
        $total = $newHundredths;

        $rows = Database::fetchAll(
            'SELECT o.`id`, o.`cut_pct` FROM `officers` o'
            . ' INNER JOIN `users` u ON u.`id` = o.`user_id`'
            . ' WHERE u.`is_active` = 1'
        );

        foreach ($rows as $row) {
            if ($excludeOfficerId !== null && (string) $row['id'] === $excludeOfficerId) {
                continue;
            }

            $total += AllocationEngine::quotaHundredths((string) ($row['cut_pct'] ?? '0.00'));
        }

        if ($total > $cap) {
            throw new \InvalidArgumentException(sprintf(
                'Total persentase potongan seluruh mitra lapangan (%s) melebihi batas sistem (%s).',
                Formatter::persen(AllocationEngine::hundredthsToDecimal($total)),
                Formatter::persen(AllocationEngine::hundredthsToDecimal($cap))
            ));
        }
    }

    /**
     * Persentase dari form: satu pemisah desimal (`.` atau `,`), maksimum 2
     * desimal, rentang 0–100. Kosong berarti 0 (potongan dinonaktifkan).
     *
     * @return int perseratus persen
     *
     * @throws \InvalidArgumentException pesan siap tampil
     */
    public static function parsePercentInput(mixed $raw, string $label): int
    {
        $value = self::stringValue($raw);
        $value = trim((string) preg_replace('/\s*%\s*$/', '', $value));

        if ($value === '') {
            return 0;
        }

        if (preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException($label . ' memuat karakter yang tidak dikenal.');
        }

        if (str_contains($value, '-')) {
            throw new \InvalidArgumentException($label . ' tidak boleh negatif.');
        }

        $digits = preg_replace('/[^0-9.,]/', '', $value) ?? '';

        if ($digits === '') {
            throw new \InvalidArgumentException($label . ' harus berupa angka. Contoh: 2,50');
        }

        if (str_contains($digits, ',') && str_contains($digits, '.')) {
            throw new \InvalidArgumentException('Gunakan satu pemisah desimal saja. Contoh: 2,50');
        }

        $digits = str_replace(',', '.', $digits);

        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $digits, $matches) !== 1) {
            throw new \InvalidArgumentException($label . ' tidak valid atau lebih dari 2 desimal.');
        }

        $hundredths = (int) $matches[1] * 100
            + (int) str_pad((string) ($matches[2] ?? ''), 2, '0', STR_PAD_RIGHT);

        if ($hundredths > 10000) {
            throw new \InvalidArgumentException($label . ' tidak boleh melebihi 100 %.');
        }

        return $hundredths;
    }

    /**
     * Nilai isian form dari perseratus persen (`250` → `2,5`, `100` → `1`).
     *
     * Sengaja TIDAK memakai `number_format()`: nilai ini hanya untuk mengisi
     * ulang field setelah `422`, dan aritmetikanya sudah integer.
     */
    public static function percentFieldValue(int $hundredths): string
    {
        $whole = intdiv(max(0, $hundredths), 100);
        $fraction = max(0, $hundredths) % 100;

        if ($fraction === 0) {
            return (string) $whole;
        }

        return $whole . ',' . rtrim(str_pad((string) $fraction, 2, '0', STR_PAD_LEFT), '0');
    }

    /**
     * Nama pos kas dari token pos form (daftar putih).
     *
     * @throws \InvalidArgumentException token tidak dikenal
     */
    public static function registerName(mixed $raw): string
    {
        $token = self::stringValue($raw);

        if (!array_key_exists($token, self::POS_LABELS)) {
            throw new \InvalidArgumentException('Pos kas pencairan tidak dikenal.');
        }

        return self::POS_LABELS[$token];
    }

    /**
     * @return array<string, mixed>
     */
    private static function lockOfficerRow(mixed $officerId): array
    {
        $id = self::stringValue($officerId);

        if (!Security::isUuid($id)) {
            throw new \InvalidArgumentException('Akun mitra lapangan tidak ditemukan.');
        }

        $officer = Officer::findByIdForUpdate($id);

        if ($officer === null) {
            throw new \InvalidArgumentException('Akun mitra lapangan tidak ditemukan.');
        }

        $user = User::findByIdAndRole((string) $officer['user_id'], Constants::ROLE_OFFICER);

        if ($user === null) {
            throw new \InvalidArgumentException('Akun mitra lapangan tidak ditemukan.');
        }

        $officer['full_name'] = (string) $user['full_name'];
        $officer['phone_number'] = (string) $user['phone_number'];
        $officer['is_active'] = (int) ($user['is_active'] ?? 0) === 1;

        return $officer;
    }

    /**
     * Cari petugas (boleh dengan `officers.id` maupun `users.id`) + profilnya.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException tidak ditemukan
     */
    private static function findOfficerWithUser(mixed $id): array
    {
        $value = self::stringValue($id);

        if (!Security::isUuid($value)) {
            throw new \InvalidArgumentException('Akun mitra lapangan tidak ditemukan.');
        }

        $officer = Officer::findById($value);

        if ($officer === null) {
            $officer = Officer::findByUserId($value);
        }

        if ($officer === null) {
            throw new \InvalidArgumentException('Akun mitra lapangan tidak ditemukan.');
        }

        $user = User::findByIdAndRole((string) $officer['user_id'], Constants::ROLE_OFFICER);

        if ($user === null) {
            throw new \InvalidArgumentException('Akun mitra lapangan tidak ditemukan.');
        }

        $officer['full_name'] = (string) $user['full_name'];
        $officer['phone_number'] = (string) $user['phone_number'];
        $officer['is_active'] = (int) ($user['is_active'] ?? 0) === 1;

        return $officer;
    }

    /**
     * Nama petugas: tidak kosong, tanpa markup, panjang 3–100 karakter.
     *
     * @throws \InvalidArgumentException
     */
    private static function validName(mixed $value): string
    {
        $name = Security::sanitize(self::stringValue($value));

        if (self::charCount($name) < self::NAME_MIN_LENGTH || self::charCount($name) > Officer::MAX_NAME_CHARS) {
            throw new \InvalidArgumentException(
                'Nama mitra lapangan wajib diisi ' . self::NAME_MIN_LENGTH . '–'
                . Officer::MAX_NAME_CHARS . ' karakter.'
            );
        }

        return $name;
    }

    /**
     * Nomor telepon mengikuti aturan akun kasir/operator: angka, boleh diawali
     * `+`/`0`, 8–20 karakter.
     *
     * @throws \InvalidArgumentException
     */
    private static function validPhone(mixed $value): string
    {
        $phone = self::stringValue($value);

        if (preg_match('/^[0-9+\-\s]{8,20}$/', $phone) !== 1) {
            throw new \InvalidArgumentException(
                'Nomor telepon tidak valid. Gunakan angka (boleh diawali + atau 0), panjang 8–20 karakter.'
            );
        }

        return $phone;
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

        return self::cut($value, OfficerLedgerEntry::MAX_NOTES_CHARS);
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
     * Jejak audit pada `cash_mutations.notes`.
     */
    private static function mutationNotes(string $officerId, string $userId): string
    {
        return sprintf('officer_id=%s; user_id=%s; arah=keluar; jenis=pencairan_hak', $officerId, $userId);
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

    private static function stringValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
