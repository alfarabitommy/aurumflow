<?php

declare(strict_types=1);

namespace AurumFlow\Services;

use AurumFlow\Config\Database;
use AurumFlow\Helpers\Formatter;
use AurumFlow\Helpers\Logger;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\CashMutation;
use AurumFlow\Models\CashRegister;
use AurumFlow\Models\Miner;
use AurumFlow\Models\MinerLoan;
use AurumFlow\Models\MinerLoanLedger;
use AurumFlow\Models\SystemSetting;
use PDO;

/**
 * Mesin buku kasbon / piutang petani (Fase 8).
 *
 * Mengapa kelas ini ada (bukan di controller atau model):
 *   Controller menangani HTTP, model menangani SQL. Pencairan dan angsuran
 *   menyentuh EMPAT tabel dalam satu transaksi (`miner_loans`,
 *   `miner_loan_ledger`, `cash_registers`, `cash_mutations`) dan memiliki aturan
 *   uang sendiri (batas harian, alokasi FIFO, konversi sen) — itu use-case,
 *   bukan SQL. Pola yang sama dengan `TellerService` dan `BridgeLoanService`.
 *
 * Aturan uang (rencana Fase 8 § 2.4) — TIDAK boleh dilanggar:
 *   1. Seluruh nominal adalah SEN integer; nilai masuk database sebagai string
 *      desimal kanonik. Tidak ada float pada jalur uang.
 *   2. Parsing input memakai `SettlementService::parseMoneyInput()` (aturan titik
 *      = ribuan, koma = desimal) dan konversi memakai `TellerService` — tidak ada
 *      salinan parser ketiga.
 *   3. **Dua lapis validasi** sebelum uang keluar: (a) Σ pokok hari ini + pokok
 *      baru ≤ `system_settings.daily_loan_limit`, (b) pokok ≤ saldo Kas Meja Bakar.
 *      Keduanya diperiksa DI DALAM transaksi ber-kunci.
 *   4. Angsuran memotong kasbon tertua lebih dulu (FIFO), menulis satu baris
 *      ledger per kasbon tersentuh, dan SATU mutasi kas masuk sebesar total.
 *
 * Urutan penguncian: `cash_registers` → `miner_loans` → `system_settings`
 * (AGENTS.md). Jalur ini tidak menyentuh dompet modal, `bridge_loans`, maupun
 * `hulu_transactions`, sehingga tidak membentuk siklus tunggu dengan tutup buku.
 */
final class KasbonService
{
    /** Kunci parameter bisnis pada `system_settings` (ERD § 4 point 14). */
    public const SETTING_DAILY_LIMIT = 'daily_loan_limit';

    /** Metode pembayaran (kolom `miner_loan_ledger.payment_method`). */
    public const METHOD_CASH = 'cash';

    public const METHOD_NOTA = 'nota';

    /** Label tampilan metode — satu pemilik (dipakai view & flash). */
    public const METHOD_LABELS = [
        self::METHOD_CASH => 'Tunai',
        self::METHOD_NOTA => 'Potong Nota',
    ];

    /** Awalan keterangan mutasi kas. */
    public const LABEL_OUT_PREFIX = 'Kasbon: ';

    public const LABEL_IN_PREFIX = 'Angsuran kasbon: ';

    /** Batas kolom `miners.full_name` yang ditegakkan layanan (kolom VARCHAR(100)). */
    public const MAX_NAME_CHARS = 60;

    public const MAX_LOCATION_CHARS = 100;

    public const MAX_PHONE_CHARS = 20;

    /** Ukuran maksimum daftar `<datalist>` (petani berutang lebih dulu). */
    public const AUTOCOMPLETE_LIMIT = 200;

    /** Batas kartu "Peminjam Aktif" & riwayat buku besar. */
    public const BORROWER_LIMIT = 50;

    public const LEDGER_LIMIT = 20;

    /**
     * Preset tombol cepat (keputusan D-8): 20 % dan 30 % dari sisa utang.
     * "Lunas" tidak masuk daftar karena berarti 100 % (nominal = sisa utang).
     *
     * Preset ini **bukan** parameter bisnis: ia hanya mengisi isian yang tetap
     * divalidasi peladen (`0 < nominal ≤ sisa utang`), sehingga tidak ada hak
     * siapa pun yang berubah karenanya. Yang wajib configurable tetap
     * `daily_loan_limit` di `system_settings`.
     */
    public const QUICK_PAY_PCTS = [20, 30];

    /** Batas nilai kolom DECIMAL(14,2). */
    private const MAX_MONEY_CENTS = 99999999999999;

    /** Potongan lokasi pada label autocomplete (agar terbaca di ponsel). */
    private const LOCATION_LABEL_CHARS = 40;

    private function __construct()
    {
    }

    // -----------------------------------------------------------------------
    // Konfigurasi batas harian (satu-satunya sumber: system_settings)
    // -----------------------------------------------------------------------

    /**
     * Batas kasbon harian dari `system_settings`.
     *
     * Fail-closed: kunci tidak terdaftar atau nilainya rusak/negatif →
     * `RuntimeException` dengan pesan administrator, **bukan** dianggap tak
     * terbatas. Nilai `0` adalah kebijakan bisnis yang sah ("kasbon
     * dinonaktifkan") dan dikembalikan apa adanya.
     *
     * @return array{cents: int, label: string, disabled: bool}
     *
     * @throws \RuntimeException kunci tidak terdaftar / nilai rusak
     */
    public static function dailyLimit(): array
    {
        $raw = SystemSetting::get(self::SETTING_DAILY_LIMIT);

        if ($raw === null) {
            throw new \RuntimeException(
                'Kunci setting "' . self::SETTING_DAILY_LIMIT . '" tidak terdaftar. '
                . 'Jalankan "php database/migrate.php" lebih dahulu.'
            );
        }

        if (str_contains($raw, '-')) {
            throw new \RuntimeException(
                'Batas kasbon harian pada system_settings bernilai negatif. Perbaiki konfigurasi terlebih dahulu.'
            );
        }

        // Nilai tersimpan adalah desimal kanonik (`'500000.00'`), jadi konversinya
        // memakai `TellerService::moneyToCents()` — BUKAN `parseMoneyInput()` yang
        // menganggap titik sebagai pemisah RIBUAN untuk input lapangan.
        try {
            $cents = TellerService::moneyToCents($raw);
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException(
                'Batas kasbon harian pada system_settings tidak valid: ' . $exception->getMessage()
            );
        }

        if ($cents > self::MAX_MONEY_CENTS) {
            throw new \RuntimeException('Batas kasbon harian melebihi kapasitas pembukuan.');
        }

        return [
            'cents'    => $cents,
            'label'    => Formatter::rupiah(TellerService::centsToDecimal($cents)),
            'disabled' => $cents === 0,
        ];
    }

    /**
     * Pemakaian batas hari ini + ringkasan utang berjalan.
     *
     * @return array{used: string, used_cents: int, limit: string, limit_cents: int,
     *               remaining: string, remaining_cents: int, percent: int,
     *               outstanding: string, disabled: bool}
     */
    public static function usageToday(): array
    {
        $limit = self::dailyLimit();
        $usedCents = TellerService::moneyToCents(MinerLoan::sumDisbursedOn(date('Y-m-d')));
        $remainingCents = max(0, $limit['cents'] - $usedCents);
        $percent = $limit['cents'] > 0
            ? (int) min(100, intdiv($usedCents * 100, $limit['cents']))
            : 100;

        return [
            'used'            => TellerService::centsToDecimal($usedCents),
            'used_cents'      => $usedCents,
            'limit'           => TellerService::centsToDecimal($limit['cents']),
            'limit_cents'     => $limit['cents'],
            'remaining'       => TellerService::centsToDecimal($remainingCents),
            'remaining_cents' => $remainingCents,
            'percent'         => $percent,
            'outstanding'     => MinerLoan::outstandingTotal(),
            'disabled'        => $limit['disabled'],
        ];
    }

    // -----------------------------------------------------------------------
    // Pendaftaran petani (tanpa kas, tanpa akun)
    // -----------------------------------------------------------------------

    /**
     * Daftarkan satu petani — hanya nama yang wajib (koleksi data bertahap).
     *
     * Tidak menyentuh kas, tidak membuat baris `users`, dan tidak memberi dompet
     * modal: petani bukan mitra pemodal.
     *
     * @param array{full_name?: mixed, phone_number?: mixed, location?: mixed} $input
     * @return array{id: string, full_name: string, phone_number: ?string, location: ?string}
     *
     * @throws \InvalidArgumentException input tidak sah (pesan siap tampil)
     */
    public static function registerMiner(array $input): array
    {
        $name = self::normaliseText($input['full_name'] ?? null, 'Nama petani', self::MAX_NAME_CHARS);

        if (self::charCount($name) < 2) {
            throw new \InvalidArgumentException('Nama petani minimal 2 karakter.');
        }

        $phone = self::normaliseText($input['phone_number'] ?? null, 'Nomor telepon', self::MAX_PHONE_CHARS);

        if ($phone !== '' && preg_match('/^[0-9+\-\s]{8,20}$/', $phone) !== 1) {
            throw new \InvalidArgumentException(
                'Nomor telepon tidak valid. Gunakan angka (boleh diawali + atau 0), 8–20 karakter.'
            );
        }

        if ($phone !== '') {
            $existing = Miner::findByPhone($phone);

            if ($existing !== null) {
                throw new \InvalidArgumentException(
                    'Nomor ' . $phone . ' sudah terdaftar untuk "' . (string) $existing['full_name']
                    . '". Pilih petani itu dari daftar, atau daftarkan tanpa nomor bila nomornya dipakai bersama.'
                );
            }
        }

        $location = self::normaliseText(
            $input['location'] ?? null,
            'Lokasi',
            self::MAX_LOCATION_CHARS
        );

        $id = Security::uuidV4();

        Miner::insert([
            'id'           => $id,
            'full_name'    => $name,
            'phone_number' => $phone === '' ? null : $phone,
            'location'     => $location === '' ? null : $location,
        ]);

        Logger::info('Petani kasbon didaftarkan', [
            'miner_id' => $id,
            'nama'     => $name,
            'telepon'  => $phone === '' ? '(kosong)' : $phone,
            'lokasi'   => $location === '' ? '(kosong)' : $location,
        ]);

        return [
            'id'           => $id,
            'full_name'    => $name,
            'phone_number' => $phone === '' ? null : $phone,
            'location'     => $location === '' ? null : $location,
        ];
    }

    // -----------------------------------------------------------------------
    // Pencairan (satu transaksi, dua lapis validasi)
    // -----------------------------------------------------------------------

    /**
     * Cairkan satu kasbon dan catat seluruh jejaknya.
     *
     * Urutan di dalam transaksi (rencana Fase 8 § 2.4):
     *   1. kunci pos kas meja (`SELECT … FOR UPDATE`);
     *   2. kunci baris `system_settings.daily_loan_limit` — menyerialkan
     *      pemeriksaan batas sehingga dua terminal tidak dapat melewatinya
     *      bersama;
     *   3. hitung Σ pokok hari ini; (a) batas harian, (b) saldo fisik laci;
     *   4. INSERT `miner_loans` (status `open`, saldo = pokok);
     *   5. UPDATE saldo kas (turun) + INSERT mutasi `out` + INSERT ledger.
     *
     * @param array{miner_id?: mixed, miner_label?: mixed, principal_amount?: mixed,
     *              notes?: mixed, actor_id?: mixed} $input
     * @return array<string, mixed> ringkasan untuk flash & QC
     *
     * @throws \InvalidArgumentException input tidak sah (pesan siap tampil)
     * @throws \RuntimeException invariant dilanggar (pos kas tidak ada / batas rusak)
     */
    public static function disburse(array $input): array
    {
        $miner = self::resolveMiner(
            is_string($input['miner_id'] ?? null) ? (string) $input['miner_id'] : '',
            is_string($input['miner_label'] ?? null) ? (string) $input['miner_label'] : ''
        );

        $principalCents = SettlementService::parseMoneyInput(
            $input['principal_amount'] ?? null,
            'Nominal kasbon'
        );

        if ($principalCents <= 0) {
            throw new \InvalidArgumentException('Nominal kasbon harus lebih besar dari nol.');
        }

        if ($principalCents > self::MAX_MONEY_CENTS) {
            throw new \InvalidArgumentException('Nominal kasbon melebihi kapasitas pembukuan.');
        }

        $notes = self::normaliseNotes($input['notes'] ?? null);
        $actorId = self::actorId($input['actor_id'] ?? null);
        $limit = self::dailyLimit();

        if ($limit['disabled']) {
            throw new \InvalidArgumentException(
                'Kasbon harian dinonaktifkan (batas harian Rp 0). Hubungi pengelola.'
            );
        }

        $principal = TellerService::centsToDecimal($principalCents);
        $loanId = Security::uuidV4();
        $mutationId = Security::uuidV4();
        $issuedOn = date('Y-m-d');

        /** @var array<string, mixed> $result */
        $result = Database::transaction(static function (PDO $pdo) use (
            $principalCents,
            $principal,
            $loanId,
            $mutationId,
            $issuedOn,
            $miner,
            $notes,
            $actorId,
            $limit
        ): array {
            // 1. Kunci pos kas lebih dulu (urutan penguncian global).
            $register = CashRegister::lockTellerForUpdate();

            if ($register === null) {
                throw new \RuntimeException(
                    'Pos kas "' . CashRegister::TELLER_NAME . '" tidak ditemukan. Hubungi administrator.'
                );
            }

            // 2. Kunci baris batas harian: pemeriksaan Σ pokok hari ini harus
            //    terjadi di bawah kunci yang sama agar dua terminal tidak dapat
            //    melewati batas bersama.
            $lockedLimit = SystemSetting::getForUpdate(self::SETTING_DAILY_LIMIT);

            if ($lockedLimit === null) {
                throw new \RuntimeException(
                    'Kunci setting "' . self::SETTING_DAILY_LIMIT . '" tidak terdaftar. '
                    . 'Jalankan "php database/migrate.php" lebih dahulu.'
                );
            }

            $limitCents = TellerService::moneyToCents($lockedLimit);

            if ($limitCents === 0) {
                throw new \InvalidArgumentException(
                    'Kasbon harian dinonaktifkan (batas harian Rp 0). Hubungi pengelola.'
                );
            }

            $registerId = (string) $register['id'];
            $balanceBeforeCents = TellerService::moneyToCents((string) $register['current_balance']);

            // 3a. Lapis pertama: batas harian (inklusif).
            $usedCents = TellerService::moneyToCents(MinerLoan::sumDisbursedOn($issuedOn));
            $remainingCents = max(0, $limitCents - $usedCents);

            if ($principalCents > $remainingCents) {
                throw new \InvalidArgumentException(
                    'Sisa batas kasbon hari ini '
                    . Formatter::rupiah(TellerService::centsToDecimal($remainingCents))
                    . ' (terpakai ' . Formatter::rupiah(TellerService::centsToDecimal($usedCents))
                    . ' dari ' . Formatter::rupiah(TellerService::centsToDecimal($limitCents))
                    . '). Kurangi nominal atau lanjutkan besok.'
                );
            }

            // 3b. Lapis kedua: saldo fisik laci.
            if ($principalCents > $balanceBeforeCents) {
                throw new \RuntimeException(
                    'Saldo Kas Meja Bakar hanya '
                    . Formatter::rupiah(TellerService::centsToDecimal($balanceBeforeCents))
                    . '. Setor kas atau kurangi nominal kasbon.'
                );
            }

            // 4. Baris pinjaman.
            MinerLoan::insert([
                'id'                => $loanId,
                'miner_id'          => (string) $miner['id'],
                'principal_amount'  => $principal,
                'balance_remaining' => $principal,
                'status'            => MinerLoan::STATUS_OPEN,
                'issued_on'         => $issuedOn,
                'issued_by'         => $actorId,
                'settled_at'        => null,
                'notes'             => $notes,
            ]);

            // 5. Kas keluar + mutasi + ledger (satu baris kas = satu baris ledger).
            CashRegister::adjustBalance($registerId, TellerService::centsToDecimal(-$principalCents));

            CashMutation::insert(
                $mutationId,
                $registerId,
                CashMutation::TYPE_OUT,
                $principal,
                self::LABEL_OUT_PREFIX . (string) $miner['full_name'],
                self::disbursementNotes($loanId, $principal, $remainingCents, $limitCents)
            );

            MinerLoanLedger::insert([
                'id'             => Security::uuidV4(),
                'loan_id'        => $loanId,
                'entry_type'     => MinerLoanLedger::ENTRY_DISBURSEMENT,
                'payment_method' => null,
                'amount'         => $principal,
                'balance_after'  => $principal,
                'register_id'    => $registerId,
                'mutation_id'    => $mutationId,
                'recorded_by'    => $actorId,
                'notes'          => $notes,
            ]);

            return [
                'loan_id'        => $loanId,
                'mutation_id'    => $mutationId,
                'register_id'    => $registerId,
                'register_name'  => (string) $register['register_name'],
                'issued_on'      => $issuedOn,
                'balance_before' => TellerService::centsToDecimal($balanceBeforeCents),
                'balance_after'  => TellerService::centsToDecimal($balanceBeforeCents - $principalCents),
                'remaining_cents' => $remainingCents - $principalCents,
                'limit_cents'    => $limitCents,
            ];
        });

        Logger::info('Kasbon dicairkan', [
            'loan_id'       => $result['loan_id'],
            'petani'        => (string) $miner['full_name'],
            'pokok'         => $principal,
            'register'      => $result['register_name'],
            'saldo_sesudah' => $result['balance_after'],
        ]);

        return $result + [
            'miner_id'        => (string) $miner['id'],
            'miner_name'      => (string) $miner['full_name'],
            'principal'       => $principal,
            'principal_cents' => $principalCents,
            'remaining_limit' => TellerService::centsToDecimal((int) $result['remaining_cents']),
        ];
    }

    // -----------------------------------------------------------------------
    // Angsuran (tunai / potong nota) — FIFO
    // -----------------------------------------------------------------------

    /**
     * Terima pembayaran kasbon, potong kasbon tertua lebih dulu.
     *
     * Satu transaksi: kunci pos kas → kunci kasbon `open` petani (FIFO) → alokasi
     * sen integer → satu baris ledger per kasbon tersentuh + SATU mutasi `in`
     * sebesar total → saldo kas naik.
     *
     * @param array{miner_id?: mixed, miner_label?: mixed, amount?: mixed,
     *              payment_method?: mixed, notes?: mixed, actor_id?: mixed} $input
     * @return array<string, mixed> ringkasan untuk flash & QC
     *
     * @throws \InvalidArgumentException input tidak sah (pesan siap tampil)
     * @throws \RuntimeException invariant dilanggar
     */
    public static function repay(array $input): array
    {
        $miner = self::resolveMiner(
            is_string($input['miner_id'] ?? null) ? (string) $input['miner_id'] : '',
            is_string($input['miner_label'] ?? null) ? (string) $input['miner_label'] : ''
        );

        $amountCents = SettlementService::parseMoneyInput($input['amount'] ?? null, 'Nominal angsuran');

        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Nominal angsuran harus lebih besar dari nol.');
        }

        $method = is_string($input['payment_method'] ?? null)
            ? trim((string) $input['payment_method'])
            : self::METHOD_CASH;

        if (!array_key_exists($method, self::METHOD_LABELS)) {
            throw new \InvalidArgumentException('Metode pembayaran tidak dikenal.');
        }

        $notes = self::normaliseNotes($input['notes'] ?? null);
        $actorId = self::actorId($input['actor_id'] ?? null);
        $amount = TellerService::centsToDecimal($amountCents);
        $mutationId = Security::uuidV4();
        $minerName = (string) $miner['full_name'];

        /** @var array<string, mixed> $result */
        $result = Database::transaction(static function (PDO $pdo) use (
            $amountCents,
            $amount,
            $method,
            $notes,
            $actorId,
            $miner,
            $minerName,
            $mutationId
        ): array {
            $register = CashRegister::lockTellerForUpdate();

            if ($register === null) {
                throw new \RuntimeException(
                    'Pos kas "' . CashRegister::TELLER_NAME . '" tidak ditemukan. Hubungi administrator.'
                );
            }

            // FIFO: kasbon tertua lebih dulu (issued_on, created_at, id).
            $loans = MinerLoan::lockOpenByMinerForUpdate((string) $miner['id']);

            if ($loans === []) {
                throw new \InvalidArgumentException(
                    $minerName . ' tidak memiliki kasbon berjalan.'
                );
            }

            $outstandingCents = 0;

            foreach ($loans as $loan) {
                $outstandingCents += TellerService::moneyToCents((string) $loan['balance_remaining']);
            }

            if ($amountCents > $outstandingCents) {
                throw new \InvalidArgumentException(
                    'Sisa utang ' . $minerName . ' hanya '
                    . Formatter::rupiah(TellerService::centsToDecimal($outstandingCents))
                    . '. Kurangi nominal angsuran.'
                );
            }

            $registerId = (string) $register['id'];
            $balanceBeforeCents = TellerService::moneyToCents((string) $register['current_balance']);

            // Alokasi FIFO dalam sen integer; sisa pembayaran selalu habis
            // karena nominal sudah divalidasi ≤ total utang.
            $remainingToPay = $amountCents;
            $allocations = [];
            $settledLoanIds = [];

            foreach ($loans as $loan) {
                if ($remainingToPay <= 0) {
                    break;
                }

                $loanBalanceCents = TellerService::moneyToCents((string) $loan['balance_remaining']);
                $payCents = min($remainingToPay, $loanBalanceCents);

                if ($payCents <= 0) {
                    continue;
                }

                $newBalanceCents = $loanBalanceCents - $payCents;
                $settled = $newBalanceCents === 0;
                $loanId = (string) $loan['id'];

                $affected = MinerLoan::applyRepayment(
                    $loanId,
                    TellerService::centsToDecimal($newBalanceCents),
                    $settled
                );

                if ($affected !== 1) {
                    throw new \RuntimeException(
                        'Kasbon ' . $loanId . ' tidak dapat diperbarui (mungkin sudah lunas). Seluruh perubahan dibatalkan.'
                    );
                }

                $allocations[] = [
                    'loan_id'       => $loanId,
                    'paid_cents'    => $payCents,
                    'balance_after' => $newBalanceCents,
                ];

                if ($settled) {
                    $settledLoanIds[] = $loanId;
                }

                $remainingToPay -= $payCents;
            }

            if ($remainingToPay !== 0) {
                throw new \RuntimeException(
                    'Alokasi angsuran tidak habis (' . TellerService::centsToDecimal($remainingToPay)
                    . '). Seluruh perubahan dibatalkan.'
                );
            }

            // Uang fisik masuk SATU kali, walau pembayaran menyentuh beberapa kasbon.
            CashMutation::insert(
                $mutationId,
                $registerId,
                CashMutation::TYPE_IN,
                $amount,
                self::LABEL_IN_PREFIX . $minerName,
                self::repaymentNotes($allocations, $method, $notes)
            );

            foreach ($allocations as $allocation) {
                MinerLoanLedger::insert([
                    'id'             => Security::uuidV4(),
                    'loan_id'        => (string) $allocation['loan_id'],
                    'entry_type'     => MinerLoanLedger::ENTRY_REPAYMENT,
                    'payment_method' => $method,
                    'amount'         => TellerService::centsToDecimal((int) $allocation['paid_cents']),
                    'balance_after'  => TellerService::centsToDecimal((int) $allocation['balance_after']),
                    'register_id'    => $registerId,
                    'mutation_id'    => $mutationId,
                    'recorded_by'    => $actorId,
                    'notes'          => $notes,
                ]);
            }

            CashRegister::adjustBalance($registerId, $amount);

            return [
                'mutation_id'     => $mutationId,
                'register_id'     => $registerId,
                'register_name'   => (string) $register['register_name'],
                'balance_before'  => TellerService::centsToDecimal($balanceBeforeCents),
                'balance_after'   => TellerService::centsToDecimal($balanceBeforeCents + $amountCents),
                'outstanding_before_cents' => $outstandingCents,
                'outstanding_after_cents'  => $outstandingCents - $amountCents,
                'loan_count'      => count($allocations),
                'settled_count'   => count($settledLoanIds),
            ];
        });

        Logger::info('Angsuran kasbon diterima', [
            'petani'         => $minerName,
            'nominal'        => $amount,
            'metode'         => $method,
            'kasbon_tersentuh' => $result['loan_count'],
            'register'       => $result['register_name'],
            'saldo_sesudah'  => $result['balance_after'],
        ]);

        return $result + [
            'miner_id'         => (string) $miner['id'],
            'miner_name'       => $minerName,
            'amount'           => $amount,
            'method'           => $method,
            'method_label'     => self::METHOD_LABELS[$method],
            'outstanding_before' => TellerService::centsToDecimal((int) $result['outstanding_before_cents']),
            'outstanding_after'  => TellerService::centsToDecimal((int) $result['outstanding_after_cents']),
        ];
    }

    // -----------------------------------------------------------------------
    // Resolusi petani (peladen tidak mempercayai klien)
    // -----------------------------------------------------------------------

    /**
     * Tentukan petani dari id (utama) atau teks label/ nama (cadangan).
     *
     * Empat tingkat, sesuai rencana Fase 8 § 2.4:
     *   1. `miner_id` UUID yang benar-benar ada → pakai;
     *   2. teks sebelum `" | "` sama persis dengan label kandidat → pakai;
     *   3. teks sama persis dengan satu `full_name` → pakai;
     *   4. selain itu `InvalidArgumentException` (422).
     *
     * Cadangan ini membuat form tetap benar tanpa JavaScript (datalist hanya
     * saran), dan label yang berubah antara render dan submit tetap aman karena
     * jalur utama adalah `miner_id`.
     *
     * @return array<string, mixed> baris `miners`
     *
     * @throws \InvalidArgumentException petani tidak dapat ditentukan
     */
    public static function resolveMiner(string $minerId, string $typedLabel): array
    {
        $minerId = trim($minerId);

        if ($minerId !== '' && Security::isUuid($minerId)) {
            $miner = Miner::findById($minerId);

            if ($miner !== null) {
                return $miner;
            }
        }

        $typed = trim($typedLabel);

        if ($typed !== '') {
            $beforePipe = trim(explode('|', $typed, 2)[0]);
            $matches = [];

            foreach (Miner::listForAutocomplete(self::AUTOCOMPLETE_LIMIT) as $candidate) {
                if (self::labelFor($candidate) === $beforePipe) {
                    $matches[] = $candidate;
                }
            }

            if (count($matches) === 1) {
                $miner = Miner::findById((string) $matches[0]['id']);

                if ($miner !== null) {
                    return $miner;
                }
            }

            $byName = Miner::findByName($beforePipe);

            if (count($byName) === 1) {
                return $byName[0];
            }
        }

        throw new \InvalidArgumentException(
            'Pilih petani dari daftar, atau daftarkan petani baru pada kartu "Daftarkan Petani Baru".'
        );
    }

    // -----------------------------------------------------------------------
    // View-model (label tampilan dihitung di sini, bukan di view)
    // -----------------------------------------------------------------------

    /**
     * Daftar petani untuk `<datalist>`, sudah berbentuk label siap cetak.
     *
     * Format label (satu pemilik, rencana Fase 8 § 3.3):
     *   `Nama — Telepon/Lokasi | Sisa Utang: Rp 250.000`
     * Segmen kontak dihilangkan seluruhnya bila telepon & lokasi kosong.
     *
     * @return list<array{id: string, label: string, outstanding_cents: int, name: string}>
     */
    public static function minerOptions(): array
    {
        $options = [];

        foreach (Miner::listForAutocomplete(self::AUTOCOMPLETE_LIMIT) as $row) {
            $outstandingCents = TellerService::moneyToCents((string) ($row['outstanding'] ?? '0'));

            $options[] = [
                'id'                => (string) $row['id'],
                'label'             => self::labelFor($row),
                'name'              => (string) $row['full_name'],
                'outstanding_cents' => $outstandingCents,
            ];
        }

        return $options;
    }

    /**
     * Data tampilan lengkap untuk halaman `/operator/kasbon`.
     *
     * @param string $selectedMinerId id petani yang dipilih (dari `?miner=`),
     *                                string kosong bila tidak ada
     * @return array<string, mixed>
     */
    public static function viewModel(string $selectedMinerId = ''): array
    {
        $usage = self::usageToday();
        $options = self::minerOptions();
        $selected = ['id' => '', 'label' => ''];

        if ($selectedMinerId !== '' && Security::isUuid($selectedMinerId)) {
            foreach ($options as $option) {
                if ($option['id'] === $selectedMinerId) {
                    $selected = ['id' => $option['id'], 'label' => $option['label']];
                    break;
                }
            }
        }

        $borrowers = [];

        foreach (MinerLoan::activeBorrowers(self::BORROWER_LIMIT) as $row) {
            $borrowers[] = [
                'miner_id'         => (string) $row['miner_id'],
                'name'             => (string) $row['full_name'],
                'contact'          => self::contactFor($row),
                'outstanding'      => Formatter::rupiah($row['outstanding'] ?? '0'),
                'loan_count'       => (int) ($row['loan_count'] ?? 0),
                'last_issued_on'   => Formatter::tanggalId((string) ($row['last_issued_on'] ?? '')),
            ];
        }

        $ledger = [];

        foreach (MinerLoanLedger::recent(self::LEDGER_LIMIT) as $row) {
            $isDisbursement = (string) $row['entry_type'] === MinerLoanLedger::ENTRY_DISBURSEMENT;

            $ledger[] = [
                'when'          => Formatter::tanggalId((string) ($row['created_at'] ?? ''), true),
                'name'          => (string) $row['full_name'],
                'type_label'    => $isDisbursement ? 'Kasbon cair' : 'Angsuran',
                'method_label'  => $isDisbursement
                    ? '—'
                    : (self::METHOD_LABELS[(string) ($row['payment_method'] ?? '')] ?? '—'),
                'amount_label'  => Formatter::rupiah($row['amount'] ?? '0'),
                'balance_after' => Formatter::rupiah($row['balance_after'] ?? '0'),
                'is_incoming'   => !$isDisbursement,
                'recorder'      => (string) ($row['recorder_name'] ?? '—'),
            ];
        }

        return [
            'usage' => [
                'used_label'      => Formatter::rupiah($usage['used']),
                'limit_label'     => Formatter::rupiah($usage['limit']),
                'remaining_label' => Formatter::rupiah($usage['remaining']),
                'outstanding_label' => Formatter::rupiah($usage['outstanding']),
                'percent'         => $usage['percent'],
                'disabled'        => $usage['disabled'],
            ],
            'minerOptions'  => $options,
            'selectedMiner' => $selected,
            'borrowers'     => $borrowers,
            'ledger'        => $ledger,
            'quickPcts'     => self::QUICK_PAY_PCTS,
            'methods'       => self::METHOD_LABELS,
        ];
    }

    // -----------------------------------------------------------------------
    // Label & catatan
    // -----------------------------------------------------------------------

    /**
     * Label satu petani untuk autocomplete.
     *
     * @param array<string, mixed> $miner baris `miners` (+ `outstanding` opsional)
     */
    public static function labelFor(array $miner): string
    {
        $name = (string) ($miner['full_name'] ?? '');
        $contact = self::contactFor($miner);
        $outstanding = Formatter::rupiah($miner['outstanding'] ?? '0');

        return ($contact === '' ? $name : $name . ' — ' . $contact)
            . ' | Sisa Utang: ' . $outstanding;
    }

    /**
     * Segmen kontak pada label: telepon, lokasi, atau keduanya.
     *
     * @param array<string, mixed> $miner
     */
    public static function contactFor(array $miner): string
    {
        $phone = trim((string) ($miner['phone_number'] ?? ''));
        $location = trim((string) ($miner['location'] ?? ''));

        if ($location !== '') {
            $location = self::cut($location, self::LOCATION_LABEL_CHARS);
        }

        if ($phone !== '' && $location !== '') {
            return $phone . ' · ' . $location;
        }

        return $phone !== '' ? $phone : $location;
    }

    /**
     * @param list<array{loan_id: string, paid_cents: int, balance_after: int}> $allocations
     */
    private static function repaymentNotes(array $allocations, string $method, ?string $notes): string
    {
        $parts = [];

        foreach ($allocations as $allocation) {
            $parts[] = $allocation['loan_id'] . '=' . TellerService::centsToDecimal((int) $allocation['paid_cents']);
        }

        return 'metode=' . $method . '; alokasi=' . implode(',', $parts)
            . ($notes === null ? '' : '; catatan=' . $notes);
    }

    private static function disbursementNotes(
        string $loanId,
        string $principal,
        int $remainingCents,
        int $limitCents
    ): string {
        return 'miner_loan_id=' . $loanId
            . '; pokok=' . $principal
            . '; batas_harian=' . TellerService::centsToDecimal($limitCents)
            . '; sisa_batas=' . TellerService::centsToDecimal($remainingCents);
    }

    // -----------------------------------------------------------------------
    // Pembersih input
    // -----------------------------------------------------------------------

    /**
     * Teks bebas: sanitasi markup, tolak UTF-8 rusak, batasi panjang kolom.
     */
    private static function normaliseText(mixed $raw, string $label, int $maxChars): string
    {
        if ($raw === null) {
            return '';
        }

        if (!is_string($raw)) {
            throw new \InvalidArgumentException($label . ' tidak valid.');
        }

        $text = trim(Security::sanitize($raw));

        if ($text === '') {
            return '';
        }

        if (preg_match('//u', $text) !== 1) {
            throw new \InvalidArgumentException($label . ' memuat karakter yang tidak dikenal.');
        }

        if (self::charCount($text) > $maxChars) {
            throw new \InvalidArgumentException($label . ' maksimal ' . $maxChars . ' karakter.');
        }

        return $text;
    }

    private static function normaliseNotes(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        if (!is_string($raw)) {
            throw new \InvalidArgumentException('Catatan tidak valid.');
        }

        $notes = trim(Security::sanitize($raw));

        if ($notes === '') {
            return null;
        }

        if (preg_match('//u', $notes) !== 1) {
            throw new \InvalidArgumentException('Catatan memuat karakter yang tidak dikenal.');
        }

        return self::cut($notes, 200);
    }

    /**
     * Jumlah karakter UTF-8 tanpa ekstensi mbstring (pola `TellerService`).
     *
     * Proyek ini tidak menjamin `mbstring` tersedia di shared hosting, sehingga
     * penghitungan karakter memakai `preg_match_all('/./us')`.
     */
    private static function charCount(string $text): int
    {
        $count = preg_match_all('/./us', $text);

        return $count === false ? 0 : (int) $count;
    }

    /**
     * Potong teks pada batas karakter UTF-8 (tanpa mbstring).
     */
    private static function cut(string $text, int $maxChars): string
    {
        if (self::charCount($text) <= $maxChars) {
            return $text;
        }

        if (preg_match('/^.{0,' . $maxChars . '}/us', $text, $matches) !== 1) {
            return '';
        }

        return (string) $matches[0];
    }

    /**
     * `users.id` pencatat dari session (controller yang meneruskan), atau null.
     */
    private static function actorId(mixed $raw): ?string
    {
        if (!is_string($raw) || !Security::isUuid(trim($raw))) {
            return null;
        }

        return trim($raw);
    }
}
