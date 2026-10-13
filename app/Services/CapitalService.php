<?php

declare(strict_types=1);

namespace AurumFlow\Services;

use AurumFlow\Config\Constants;
use AurumFlow\Config\Database;
use AurumFlow\Helpers\Logger;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\CapitalWallet;
use AurumFlow\Models\CashMutation;
use AurumFlow\Models\CashRegister;
use AurumFlow\Models\SystemSetting;
use AurumFlow\Models\User;
use AurumFlow\Models\WithdrawalRequest;

/**
 * Tata kelola modal & likuiditas (Fase 4): setoran, pindah kas, kuota, penarikan.
 *
 * Mengapa kelas ini ada (bukan di controller atau model):
 *   Setiap operasi di sini menggerakkan KAS dan/atau HAK MITRA sekaligus,
 *   sehingga wajib menjadi satu unit atomik dengan aturan uang sendiri — itu
 *   use-case, bukan SQL. Controller tetap hanya menangani HTTP.
 *
 * Tiga jalur uang (masing-masing SATU `Database::transaction()`):
 *   1. **Setoran modal** — `capital_wallets.balance_capital` + saldo `Brankas
 *      Utama` + `cash_mutations` (`in`). Tidak boleh ada modal tercatat tanpa
 *      kas fisiknya, atau sebaliknya.
 *   2. **Pindah Kas** — SATU ARAH `Brankas Utama` → `Kas Meja Bakar`, dua pos +
 *      dua mutasi. Arah balik sudah ditangani Smart Auto-Split saat settlement.
 *   3. **Bayar penarikan** — saldo dompet turun + kas keluar brankas + mutasi
 *      (`out`) + status permohonan `completed`.
 *
 * Urutan penguncian global (AGENTS.md, mencegah deadlock):
 *   `capital_wallets` (id menaik) → `withdrawal_requests` → `cash_registers`
 *   (nama menaik) → `hulu_transactions` → `system_settings`.
 *
 * Aturan uang: seluruh nominal diurai `SettlementService::parseMoneyInput()`
 * (parser yang sama dengan layar rekonsiliasi), dihitung dalam SEN integer,
 * dan ditulis sebagai string desimal kanonik lewat `TellerService`.
 */
final class CapitalService
{
    /** Kunci `system_settings` untuk masa tunggu penarikan modal pokok. */
    public const SETTING_NOTICE_DAYS = 'notice_period_days';

    /** Batas nilai kolom DECIMAL(14,2). */
    private const MAX_MONEY_CENTS = 99999999999999;

    /** Batas panjang `users.full_name` (VARCHAR(100)). */
    private const MAX_NAME_CHARS = 100;

    /** Batas panjang `notes` yang kita tulis sendiri. */
    private const MAX_NOTE_CHARS = 255;

    /** Pola nomor telepon — sama dengan `database/install.php`. */
    private const PHONE_PATTERN = '/^[0-9+\-\s]{8,20}$/';

    private function __construct()
    {
    }

    // -----------------------------------------------------------------------
    // 1. Setoran modal (Top-Up)
    // -----------------------------------------------------------------------

    /**
     * Catat setoran modal mitra: dompet + Brankas Utama + mutasi kas masuk.
     *
     * @param array<string, mixed> $input kunci: wallet_id, amount, note
     * @return array<string, mixed> ringkasan untuk flash & QC
     *
     * @throws \InvalidArgumentException input tidak sah (pesan siap tampil)
     * @throws \RuntimeException invariant sistem (dompet/pos kas tidak ada)
     */
    public static function topUp(array $input): array
    {
        $walletId = self::stringInput($input['wallet_id'] ?? null);

        if ($walletId === '') {
            throw new \InvalidArgumentException('Pilih mitra pemilik dompet tujuan setoran.');
        }

        $amountCents = self::amountInput($input['amount'] ?? null, 'Nominal setoran');
        $note = self::noteInput($input['note'] ?? null);
        $ownerName = CapitalWallet::ownerName($walletId);

        if ($ownerName === '') {
            throw new \InvalidArgumentException('Dompet modal tidak ditemukan. Muat ulang halaman lalu coba lagi.');
        }

        $mutationId = Security::uuidV4();
        $amount = TellerService::centsToDecimal($amountCents);

        /** @var array<string, mixed> $result */
        $result = Database::transaction(static function () use (
            $walletId,
            $amountCents,
            $amount,
            $note,
            $ownerName,
            $mutationId
        ): array {
            $wallet = CapitalWallet::lockByIdForUpdate($walletId);

            if ($wallet === null) {
                throw new \RuntimeException('Dompet modal tidak ditemukan pada basis data.');
            }

            $brankas = CashRegister::lockByNameForUpdate(CashRegister::BRANKAS_NAME);

            if ($brankas === null) {
                throw new \RuntimeException(
                    'Pos kas "' . CashRegister::BRANKAS_NAME . '" tidak ditemukan. Jalankan instalasi basis data.'
                );
            }

            $brankasId = (string) $brankas['id'];
            $brankasBefore = TellerService::moneyToCents((string) $brankas['current_balance']);

            CashMutation::insert(
                $mutationId,
                $brankasId,
                CashMutation::TYPE_IN,
                $amount,
                self::sourceLabel('Setoran modal', $ownerName),
                self::mutationNotes($walletId, $note)
            );

            CapitalWallet::adjustCapital($walletId, $amount);
            CashRegister::adjustBalance($brankasId, $amount);

            return [
                'wallet_id'      => $walletId,
                'owner'          => $ownerName,
                'tier'           => (string) $wallet['tier'],
                'amount_cents'   => $amountCents,
                'mutation_id'    => $mutationId,
                'wallet_before'  => (string) $wallet['balance_capital'],
                'wallet_after'   => TellerService::centsToDecimal(
                    TellerService::moneyToCents((string) $wallet['balance_capital']) + $amountCents
                ),
                'brankas_before' => TellerService::centsToDecimal($brankasBefore),
                'brankas_after'  => TellerService::centsToDecimal($brankasBefore + $amountCents),
            ];
        });

        Logger::info('Setoran modal tersimpan', [
            'wallet_id'      => $result['wallet_id'],
            'tier'           => $result['tier'],
            'nominal'        => $amount,
            'saldo_dompet'   => $result['wallet_after'],
            'brankas_akhir'  => $result['brankas_after'],
        ]);

        return $result;
    }

    // -----------------------------------------------------------------------
    // 2. Pindah Kas (Brankas Utama -> Kas Meja Bakar)
    // -----------------------------------------------------------------------

    /**
     * Pindahkan likuiditas dari brankas ke meja bakar.
     *
     * Satu arah dengan sengaja: sisa kas malam sudah ditempatkan otomatis oleh
     * Smart Auto-Split pada saat tutup buku, sehingga tidak ada arah balik.
     *
     * @param array<string, mixed> $input kunci: amount, note
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException input tidak sah
     * @throws \RuntimeException saldo brankas kurang / pos kas tidak ada
     */
    public static function transferToTeller(array $input): array
    {
        $amountCents = self::amountInput($input['amount'] ?? null, 'Nominal pindah kas');
        $note = self::noteInput($input['note'] ?? null);
        $amount = TellerService::centsToDecimal($amountCents);
        $transferId = Security::uuidV4();

        /** @var array<string, mixed> $result */
        $result = Database::transaction(static function () use (
            $amountCents,
            $amount,
            $note,
            $transferId
        ): array {
            $registers = CashRegister::lockByNamesForUpdate([
                CashRegister::BRANKAS_NAME,
                CashRegister::TELLER_NAME,
            ]);

            $brankas = $registers[CashRegister::BRANKAS_NAME] ?? null;
            $teller = $registers[CashRegister::TELLER_NAME] ?? null;

            if ($brankas === null || $teller === null) {
                throw new \RuntimeException(
                    'Pos kas "' . CashRegister::BRANKAS_NAME . '" atau "' . CashRegister::TELLER_NAME
                    . '" tidak ditemukan. Jalankan instalasi basis data.'
                );
            }

            $brankasId = (string) $brankas['id'];
            $tellerId = (string) $teller['id'];
            $brankasBefore = TellerService::moneyToCents((string) $brankas['current_balance']);
            $tellerBefore = TellerService::moneyToCents((string) $teller['current_balance']);

            if ($brankasBefore < $amountCents) {
                throw new \RuntimeException(
                    'Saldo ' . CashRegister::BRANKAS_NAME . ' hanya '
                    . TellerService::centsToDecimal($brankasBefore)
                    . ' — tidak cukup untuk pindah kas ini.'
                );
            }

            $notes = self::transferNotes($transferId, $amount, $note);

            CashMutation::insert(
                $transferId,
                $brankasId,
                CashMutation::TYPE_OUT,
                $amount,
                'Pindah kas ke ' . CashRegister::TELLER_NAME,
                $notes
            );

            CashMutation::insert(
                Security::uuidV4(),
                $tellerId,
                CashMutation::TYPE_IN,
                $amount,
                'Pindah kas dari ' . CashRegister::BRANKAS_NAME,
                $notes
            );

            CashRegister::adjustBalance($brankasId, TellerService::centsToDecimal(-$amountCents));
            CashRegister::adjustBalance($tellerId, $amount);

            return [
                'transfer_id'    => $transferId,
                'amount_cents'   => $amountCents,
                'brankas_before' => TellerService::centsToDecimal($brankasBefore),
                'brankas_after'  => TellerService::centsToDecimal($brankasBefore - $amountCents),
                'teller_before'  => TellerService::centsToDecimal($tellerBefore),
                'teller_after'   => TellerService::centsToDecimal($tellerBefore + $amountCents),
            ];
        });

        Logger::info('Pindah kas tersimpan', [
            'transfer_id'   => $result['transfer_id'],
            'nominal'       => $amount,
            'brankas_akhir' => $result['brankas_after'],
            'kas_meja_akhir' => $result['teller_after'],
        ]);

        return $result;
    }

    // -----------------------------------------------------------------------
    // 3. Mitra & dompet
    // -----------------------------------------------------------------------

    /**
     * Buat akun mitra baru beserta dompetnya **dan tautan undangannya**.
     *
     * Akun tetap belum dapat dipakai sampai mitra menyelesaikan onboarding:
     * kata sandi diisi token acak yang tidak diungkapkan, dan satu-satunya
     * jalan masuk adalah tautan `?token=…` yang diterbitkan di sini (Fase 5).
     * Token dibuat **di dalam transaksi yang sama** dengan akun + dompet, agar
     * tidak pernah ada mitra yang terlanjur ada tanpa tautan undangan.
     *
     * @param array<string, mixed> $input kunci: full_name, phone_number, tier, quota
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException input tidak sah / nomor sudah terdaftar
     */
    public static function createMitra(array $input): array
    {
        $fullName = self::nameInput($input['full_name'] ?? null);
        $phone = self::phoneInput($input['phone_number'] ?? null);
        $tier = self::stringInput($input['tier'] ?? null);

        if (!in_array($tier, CapitalWallet::selectableTiers(), true)) {
            throw new \InvalidArgumentException('Pilih jenis mitra: Mitra Prioritas atau Mitra Reguler.');
        }

        $quotaHundredths = self::quotaInput($input['quota'] ?? null, $tier);

        try {
            /** @var array<string, mixed> $result */
            $result = Database::transaction(static function () use (
                $fullName,
                $phone,
                $tier,
                $quotaHundredths
            ): array {
                if (User::findByPhoneNumber($phone, false) !== null) {
                    throw new \InvalidArgumentException('Nomor telepon ' . $phone . ' sudah terdaftar.');
                }

                $userId = User::create(
                    $fullName,
                    $phone,
                    Security::randomToken(32),
                    Constants::ROLE_INVESTOR
                );

                $walletId = CapitalWallet::insert(
                    $userId,
                    $tier,
                    AllocationEngine::hundredthsToDecimal($quotaHundredths)
                );

                $inviteToken = User::issueInviteToken($userId);

                return [
                    'user_id'          => $userId,
                    'wallet_id'        => $walletId,
                    'full_name'        => $fullName,
                    'phone_number'     => $phone,
                    'tier'             => $tier,
                    'quota_hundredths' => $quotaHundredths,
                    'invite_token'     => $inviteToken,
                ];
            });
        } catch (\PDOException $exception) {
            // Nomor telepon UNIQUE pada ERD: dua operator yang menyimpan form
            // hampir bersamaan dapat lolos pemeriksaan awal.
            throw new \InvalidArgumentException('Nomor telepon ' . $phone . ' sudah terdaftar.', 0, $exception);
        }

        Logger::info('Mitra modal baru terdaftar', [
            'user_id'   => $result['user_id'],
            'wallet_id' => $result['wallet_id'],
            'tier'      => $result['tier'],
        ]);

        return $result;
    }

    /**
     * Terbitkan (atau putar ulang) tautan undangan seorang mitra (Fase 5).
     *
     * Dipakai tombol "Buat tautan" / "Buat ulang tautan" di layar Kelola Modal.
     * Menerbitkan ulang **mematikan** tautan lama; untuk mitra yang sudah
     * onboarding, ini sekaligus jalur reset PIN — tanda tangan berikutnya
     * menimpa jejak audit (keterbatasan yang dicatat pada PRD § 5.3).
     *
     * @param array<string, mixed> $input kunci: wallet_id, mode (issue|reset)
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException dompet tidak ditemukan / bukan akun mitra
     */
    public static function issueInvite(array $input): array
    {
        $walletId = self::stringInput($input['wallet_id'] ?? null);
        $mode = self::stringInput($input['mode'] ?? null);

        if ($walletId === '') {
            throw new \InvalidArgumentException('Pilih mitra yang tautan undangannya ingin dibuat.');
        }

        if (!in_array($mode, ['issue', 'reset'], true)) {
            throw new \InvalidArgumentException('Aksi undangan tidak dikenal.');
        }

        /** @var array<string, mixed> $result */
        $result = Database::transaction(static function () use ($walletId): array {
            $wallet = CapitalWallet::lockByIdForUpdate($walletId);

            if ($wallet === null) {
                throw new \RuntimeException('Dompet modal tidak ditemukan. Muat ulang halaman lalu coba lagi.');
            }

            $owner = Database::fetchOne(
                'SELECT `id`, `full_name`, `phone_number`, `role`, `onboarding_signed_at` '
                . 'FROM `users` WHERE `id` = :id LIMIT 1',
                ['id' => (string) $wallet['user_id']]
            );

            if ($owner === null) {
                throw new \RuntimeException('Pemilik dompet tidak ditemukan pada basis data.');
            }

            if ((string) $owner['role'] !== Constants::ROLE_INVESTOR) {
                throw new \InvalidArgumentException(
                    'Tautan undangan hanya untuk akun mitra pemodal, bukan akun Pengelola.'
                );
            }

            $token = User::issueInviteToken((string) $owner['id']);

            return [
                'wallet_id'      => $walletId,
                'user_id'        => (string) $owner['id'],
                'full_name'      => (string) $owner['full_name'],
                'phone_number'   => (string) $owner['phone_number'],
                'invite_token'   => $token,
                'already_signed' => trim((string) ($owner['onboarding_signed_at'] ?? '')) !== '',
            ];
        });

        Logger::info('Tautan undangan mitra diterbitkan', [
            'user_id'   => $result['user_id'],
            'wallet_id' => $result['wallet_id'],
            'ulang'     => $result['already_signed'],
        ]);

        return $result;
    }

    /**
     * Buat dompet untuk pengguna yang sudah ada (mis. tombol "Siapkan dompet
     * Pengelola" pada instalasi lama yang dibuat sebelum Fase 4).
     *
     * Aturan tier mengikuti peran akun: akun `operator` hanya boleh memiliki
     * dompet tier `operator`, akun `investor` hanya tier mitra. Tanpa aturan ini
     * siapa pun bisa memberi tier `operator` (prioritas 50 %) ke akun mitra.
     *
     * @param array<string, mixed> $input kunci: user_id, tier, quota
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException input tidak sah
     */
    public static function createWalletForUser(array $input): array
    {
        $userId = self::stringInput($input['user_id'] ?? null);
        $tier = self::stringInput($input['tier'] ?? null);

        if ($userId === '') {
            throw new \InvalidArgumentException('Pilih pemilik dompet terlebih dahulu.');
        }

        if (!isset(CapitalWallet::TIER_LABELS[$tier])) {
            throw new \InvalidArgumentException('Tier dompet modal tidak dikenal.');
        }

        $user = Database::fetchOne(
            'SELECT `id`, `full_name`, `role` FROM `users` WHERE `id` = :id LIMIT 1',
            ['id' => $userId]
        );

        if ($user === null) {
            throw new \InvalidArgumentException('Pemilik dompet tidak ditemukan.');
        }

        $role = (string) ($user['role'] ?? '');

        if ($role === Constants::ROLE_OPERATOR && $tier !== CapitalWallet::TIER_OPERATOR) {
            throw new \InvalidArgumentException('Akun Pengelola hanya boleh memiliki dompet tier Pengelola.');
        }

        if ($role !== Constants::ROLE_OPERATOR && $tier === CapitalWallet::TIER_OPERATOR) {
            throw new \InvalidArgumentException('Tier Pengelola hanya untuk akun Pengelola.');
        }

        if (CapitalWallet::findByUserId($userId) !== null) {
            throw new \InvalidArgumentException('Pengguna tersebut sudah memiliki dompet modal.');
        }

        $quotaHundredths = self::quotaInput($input['quota'] ?? null, $tier);
        $walletId = CapitalWallet::insert($userId, $tier, AllocationEngine::hundredthsToDecimal($quotaHundredths));

        Logger::info('Dompet modal dibuat', [
            'wallet_id' => $walletId,
            'user_id'   => $userId,
            'tier'      => $tier,
        ]);

        return [
            'wallet_id'        => $walletId,
            'user_id'          => $userId,
            'full_name'        => (string) ($user['full_name'] ?? ''),
            'tier'             => $tier,
            'quota_hundredths' => $quotaHundredths,
        ];
    }

    /**
     * Ubah kuota serapan terkunci sebuah dompet.
     *
     * Hanya Mitra Prioritas yang boleh memiliki kuota bukan-nol (rencana A-15);
     * tier lain ditolak, bukan diabaikan diam-diam.
     *
     * @param array<string, mixed> $input kunci: wallet_id, quota
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException input tidak sah
     */
    public static function updateQuota(array $input): array
    {
        $walletId = self::stringInput($input['wallet_id'] ?? null);

        if ($walletId === '') {
            throw new \InvalidArgumentException('Dompet yang diubah kuotanya tidak dikenali.');
        }

        /** @var array<string, mixed> $result */
        $result = Database::transaction(static function () use ($walletId, $input): array {
            $wallet = CapitalWallet::lockByIdForUpdate($walletId);

            if ($wallet === null) {
                throw new \RuntimeException('Dompet modal tidak ditemukan. Muat ulang halaman lalu coba lagi.');
            }

            $tier = (string) $wallet['tier'];
            $hundredths = self::quotaInput($input['quota'] ?? null, $tier);

            if ($tier !== CapitalWallet::TIER_FAMILY && $hundredths > 0) {
                throw new \InvalidArgumentException('Kuota serapan hanya dapat diisi untuk Mitra Prioritas.');
            }

            CapitalWallet::setLockedQuota($walletId, AllocationEngine::hundredthsToDecimal($hundredths));

            return [
                'wallet_id'        => $walletId,
                'tier'             => $tier,
                'quota_hundredths' => $hundredths,
                'quota_before'     => (string) $wallet['locked_quota_pct'],
            ];
        });

        Logger::info('Kuota serapan dompet diperbarui', [
            'wallet_id' => $result['wallet_id'],
            'tier'      => $result['tier'],
            'kuota'     => AllocationEngine::hundredthsToDecimal((int) $result['quota_hundredths']),
        ]);

        return $result;
    }

    // -----------------------------------------------------------------------
    // 4. Penarikan dana (antrean + eksekusi bayar)
    // -----------------------------------------------------------------------

    /**
     * Catat pengajuan penarikan atas nama mitra.
     *
     * Jatuh tempo = tanggal pengajuan + `notice_period_days` **hari kerja**
     * (Sabtu/Minggu dilewati). Penarikan modal pokok menandai dompet
     * `withdrawal_status='queued'`, dan hanya boleh ada satu pengajuan modal
     * terbuka per dompet.
     *
     * @param array<string, mixed> $input kunci: wallet_id, withdrawal_type, amount, note
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException input tidak sah
     * @throws \RuntimeException dompet tidak ada
     */
    public static function requestWithdrawal(array $input): array
    {
        $walletId = self::stringInput($input['wallet_id'] ?? null);

        if ($walletId === '') {
            throw new \InvalidArgumentException('Pilih mitra yang mengajukan penarikan.');
        }

        $type = self::stringInput($input['withdrawal_type'] ?? null);

        if (!in_array($type, [WithdrawalRequest::TYPE_PROFIT, WithdrawalRequest::TYPE_CAPITAL], true)) {
            throw new \InvalidArgumentException('Pilih jenis penarikan: Dividen atau Modal Pokok.');
        }

        $amountCents = self::amountInput($input['amount'] ?? null, 'Nominal penarikan');
        $note = self::noteInput($input['note'] ?? null);
        $noticeDays = self::noticeDaysInput($input['notice_days'] ?? null, $type);
        $today = self::today();
        $dueDate = self::workingDaysDueDate($today, $noticeDays);
        $requestId = Security::uuidV4();

        /** @var array<string, mixed> $result */
        $result = Database::transaction(static function () use (
            $walletId,
            $type,
            $amountCents,
            $note,
            $noticeDays,
            $dueDate,
            $today,
            $requestId
        ): array {
            $wallet = CapitalWallet::lockByIdForUpdate($walletId);

            if ($wallet === null) {
                throw new \RuntimeException('Dompet modal tidak ditemukan. Muat ulang halaman lalu coba lagi.');
            }

            $availableCents = $type === WithdrawalRequest::TYPE_CAPITAL
                ? TellerService::moneyToCents((string) $wallet['balance_capital'])
                : TellerService::moneyToCents((string) $wallet['balance_profit']);

            if ($amountCents > $availableCents) {
                throw new \InvalidArgumentException(
                    'Nominal penarikan melebihi saldo yang tersedia ('
                    . TellerService::centsToDecimal($availableCents) . ').'
                );
            }

            // Batas pengajuan terbuka (Fase 5, PRD § 4.3): pengajuan yang masih
            // `pending`/`approved` sudah "memesan" sebagian saldo, sehingga
            // totalnya ditambah pengajuan ini tidak boleh melebihi saldo jenis
            // itu. Tanpa ini, tiga pengajuan dividen masing-masing "di bawah
            // saldo" dapat menumpuk di antrean.
            $openCents = TellerService::moneyToCents(
                WithdrawalRequest::sumOpenAmountForWallet($walletId, $type)
            );

            if ($openCents + $amountCents > $availableCents) {
                throw new \InvalidArgumentException(
                    'Masih ada pengajuan ' . WithdrawalRequest::typeLabel($type) . ' yang belum selesai sebesar '
                    . TellerService::centsToDecimal($openCents) . '; sisa yang dapat diajukan '
                    . TellerService::centsToDecimal(max(0, $availableCents - $openCents)) . '.'
                );
            }

            if ($type === WithdrawalRequest::TYPE_CAPITAL
                && (string) $wallet['withdrawal_status'] === CapitalWallet::WITHDRAWAL_QUEUED
            ) {
                throw new \InvalidArgumentException(
                    'Dompet ini sudah memiliki pengajuan penarikan modal yang belum selesai.'
                );
            }

            WithdrawalRequest::insert(
                $requestId,
                $walletId,
                $type,
                TellerService::centsToDecimal($amountCents),
                $dueDate,
                $note
            );

            if ($type === WithdrawalRequest::TYPE_CAPITAL) {
                CapitalWallet::setWithdrawalState($walletId, CapitalWallet::WITHDRAWAL_QUEUED, $today . ' ' . date('H:i:s'));
            }

            return [
                'request_id'      => $requestId,
                'wallet_id'       => $walletId,
                'withdrawal_type' => $type,
                'amount_cents'    => $amountCents,
                'notice_days'     => $noticeDays,
                'notice_due'      => $dueDate,
                'available_cents' => $availableCents,
            ];
        });

        Logger::info('Pengajuan penarikan tercatat', [
            'request_id' => $result['request_id'],
            'wallet_id'  => $result['wallet_id'],
            'jenis'      => $result['withdrawal_type'],
            'nominal'    => TellerService::centsToDecimal((int) $result['amount_cents']),
            'jatuh_tempo' => $result['notice_due'],
        ]);

        return $result;
    }

    /**
     * Setujui atau tolak pengajuan penarikan (tanpa menggerakkan kas).
     *
     * @param array<string, mixed> $input kunci: request_id, decision (approve|reject)
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException input tidak sah / status tidak sesuai
     * @throws \RuntimeException permohonan/dompet tidak ada
     */
    public static function decideWithdrawal(array $input): array
    {
        $requestId = self::stringInput($input['request_id'] ?? null);
        $decision = self::stringInput($input['decision'] ?? null);

        if ($requestId === '') {
            throw new \InvalidArgumentException('Permohonan penarikan tidak dikenali.');
        }

        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new \InvalidArgumentException('Keputusan penarikan tidak dikenali.');
        }

        $preview = WithdrawalRequest::findById($requestId);

        if ($preview === null) {
            throw new \RuntimeException('Permohonan penarikan tidak ditemukan.');
        }

        /** @var array<string, mixed> $result */
        $result = Database::transaction(static function () use ($requestId, $decision, $preview): array {
            // Urutan penguncian: dompet lebih dulu, baru baris permohonan.
            $wallet = CapitalWallet::lockByIdForUpdate((string) $preview['wallet_id']);

            if ($wallet === null) {
                throw new \RuntimeException('Dompet modal permohonan ini tidak ditemukan.');
            }

            $request = WithdrawalRequest::lockByIdForUpdate($requestId);

            if ($request === null) {
                throw new \RuntimeException('Permohonan penarikan tidak ditemukan.');
            }

            if ((string) $request['status'] !== WithdrawalRequest::STATUS_PENDING) {
                throw new \InvalidArgumentException(
                    'Permohonan ini sudah berstatus "' . WithdrawalRequest::statusLabel((string) $request['status'])
                    . '" dan tidak dapat diputuskan lagi.'
                );
            }

            $type = (string) $request['withdrawal_type'];
            $status = $decision === 'approve'
                ? WithdrawalRequest::STATUS_APPROVED
                : WithdrawalRequest::STATUS_REJECTED;

            WithdrawalRequest::setStatus($requestId, $status);

            if ($decision === 'reject' && $type === WithdrawalRequest::TYPE_CAPITAL) {
                CapitalWallet::setWithdrawalState(
                    (string) $preview['wallet_id'],
                    CapitalWallet::WITHDRAWAL_NONE,
                    null
                );
            }

            return [
                'request_id'      => $requestId,
                'wallet_id'       => (string) $preview['wallet_id'],
                'withdrawal_type' => $type,
                'status'          => $status,
                'amount_cents'    => TellerService::moneyToCents((string) $request['amount']),
                'notice_due'      => (string) $request['notice_period_due'],
            ];
        });

        Logger::info('Keputusan penarikan tersimpan', [
            'request_id' => $result['request_id'],
            'jenis'      => $result['withdrawal_type'],
            'keputusan'  => $result['status'],
        ]);

        return $result;
    }

    /**
     * Bayar pengajuan penarikan yang sudah disetujui.
     *
     * Gerbang jatuh tempo: penarikan **modal pokok** hanya boleh dibayar pada
     * atau setelah `notice_period_due`; dividen boleh dibayar begitu disetujui.
     * Saldo dompet dan saldo kas diverifikasi ULANG di dalam transaksi supaya
     * dua terminal tidak dapat mencairkan dana yang sama.
     *
     * @param array<string, mixed> $input kunci: request_id
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException status/jatuh tempo/saldo tidak memenuhi
     * @throws \RuntimeException permohonan, dompet, atau pos kas tidak ada
     */
    public static function payWithdrawal(array $input): array
    {
        $requestId = self::stringInput($input['request_id'] ?? null);

        if ($requestId === '') {
            throw new \InvalidArgumentException('Permohonan penarikan tidak dikenali.');
        }

        $preview = WithdrawalRequest::findById($requestId);

        if ($preview === null) {
            throw new \RuntimeException('Permohonan penarikan tidak ditemukan.');
        }

        $today = self::today();
        $mutationId = Security::uuidV4();

        /** @var array<string, mixed> $result */
        $result = Database::transaction(static function () use (
            $requestId,
            $preview,
            $today,
            $mutationId
        ): array {
            $walletId = (string) $preview['wallet_id'];
            $wallet = CapitalWallet::lockByIdForUpdate($walletId);

            if ($wallet === null) {
                throw new \RuntimeException('Dompet modal permohonan ini tidak ditemukan.');
            }

            $request = WithdrawalRequest::lockByIdForUpdate($requestId);

            if ($request === null) {
                throw new \RuntimeException('Permohonan penarikan tidak ditemukan.');
            }

            if ((string) $request['status'] !== WithdrawalRequest::STATUS_APPROVED) {
                throw new \InvalidArgumentException('Hanya permohonan yang sudah disetujui yang dapat dibayar.');
            }

            $type = (string) $request['withdrawal_type'];
            $dueDate = (string) $request['notice_period_due'];

            if ($type === WithdrawalRequest::TYPE_CAPITAL && $dueDate > $today) {
                throw new \InvalidArgumentException(
                    'Penarikan modal pokok baru dapat dibayar pada ' . $dueDate
                    . ' (masa tunggu H-' . self::configuredNoticeDays() . ' hari kerja).'
                );
            }

            $amountCents = TellerService::moneyToCents((string) $request['amount']);
            $availableCents = $type === WithdrawalRequest::TYPE_CAPITAL
                ? TellerService::moneyToCents((string) $wallet['balance_capital'])
                : TellerService::moneyToCents((string) $wallet['balance_profit']);

            if ($amountCents > $availableCents) {
                throw new \InvalidArgumentException(
                    'Saldo dompet tidak lagi mencukupi untuk penarikan ini (tersedia '
                    . TellerService::centsToDecimal($availableCents) . ').'
                );
            }

            $brankas = CashRegister::lockByNameForUpdate(CashRegister::BRANKAS_NAME);

            if ($brankas === null) {
                throw new \RuntimeException(
                    'Pos kas "' . CashRegister::BRANKAS_NAME . '" tidak ditemukan. Jalankan instalasi basis data.'
                );
            }

            $brankasId = (string) $brankas['id'];
            $brankasBefore = TellerService::moneyToCents((string) $brankas['current_balance']);

            if ($brankasBefore < $amountCents) {
                throw new \RuntimeException(
                    'Saldo ' . CashRegister::BRANKAS_NAME . ' tidak cukup untuk pencairan ini (tersedia '
                    . TellerService::centsToDecimal($brankasBefore) . ').'
                );
            }

            $amount = TellerService::centsToDecimal($amountCents);
            $ownerName = CapitalWallet::ownerName($walletId);

            CashMutation::insert(
                $mutationId,
                $brankasId,
                CashMutation::TYPE_OUT,
                $amount,
                self::sourceLabel('Penarikan ' . strtolower(WithdrawalRequest::typeLabel($type)), $ownerName),
                self::mutationNotes($walletId, 'request_id=' . $requestId)
            );

            if ($type === WithdrawalRequest::TYPE_CAPITAL) {
                CapitalWallet::adjustCapital($walletId, TellerService::centsToDecimal(-$amountCents));
                CapitalWallet::setWithdrawalState(
                    $walletId,
                    CapitalWallet::WITHDRAWAL_COMPLETED,
                    (string) ($wallet['withdrawal_request_date'] ?? '') === ''
                        ? null
                        : (string) $wallet['withdrawal_request_date']
                );
            } else {
                CapitalWallet::adjustProfit($walletId, TellerService::centsToDecimal(-$amountCents));
            }

            CashRegister::adjustBalance($brankasId, TellerService::centsToDecimal(-$amountCents));
            WithdrawalRequest::setStatus($requestId, WithdrawalRequest::STATUS_COMPLETED);

            return [
                'request_id'      => $requestId,
                'wallet_id'       => $walletId,
                'withdrawal_type' => $type,
                'amount_cents'    => $amountCents,
                'mutation_id'     => $mutationId,
                'owner'           => $ownerName,
                'brankas_before'  => TellerService::centsToDecimal($brankasBefore),
                'brankas_after'   => TellerService::centsToDecimal($brankasBefore - $amountCents),
            ];
        });

        Logger::info('Penarikan dibayarkan', [
            'request_id'    => $result['request_id'],
            'wallet_id'     => $result['wallet_id'],
            'jenis'         => $result['withdrawal_type'],
            'nominal'       => TellerService::centsToDecimal((int) $result['amount_cents']),
            'brankas_akhir' => $result['brankas_after'],
        ]);

        return $result;
    }

    // -----------------------------------------------------------------------
    // Kalender kerja (H-7 hari kerja)
    // -----------------------------------------------------------------------

    /**
     * Tanggal jatuh tempo setelah `$days` HARI KERJA (Sabtu/Minggu dilewati).
     *
     * Murni terhadap tanggal acuan sehingga dapat diuji tanpa basis data
     * (QC grup J). Tidak ada kalender hari libur nasional pada Fase 4 —
     * item O-2 pada rencana.
     *
     * @param string $from tanggal acuan `Y-m-d`
     * @param int $days jumlah hari kerja (0 = hari yang sama)
     *
     * @throws \InvalidArgumentException tanggal/jumlah hari tidak sah
     */
    public static function workingDaysDueDate(string $from, int $days): string
    {
        $from = trim($from);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1) {
            throw new \InvalidArgumentException('Tanggal acuan masa tunggu tidak valid.');
        }

        if ($days < 0 || $days > 365) {
            throw new \InvalidArgumentException('Masa tunggu penarikan harus antara 0 dan 365 hari kerja.');
        }

        // Tengah hari menghindari pergeseran tanggal akibat zona waktu/DST.
        $timestamp = strtotime($from . ' 12:00:00');

        if ($timestamp === false) {
            throw new \InvalidArgumentException('Tanggal acuan masa tunggu tidak dapat dibaca.');
        }

        $remaining = $days;

        while ($remaining > 0) {
            $timestamp = strtotime('+1 day', $timestamp);
            $weekday = (int) date('N', $timestamp);

            if ($weekday <= 5) {
                $remaining--;
            }
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * Masa tunggu penarikan dari `system_settings` (default 7 hari kerja).
     *
     * @throws \RuntimeException nilai setting rusak
     */
    public static function configuredNoticeDays(): int
    {
        $raw = trim((string) (SystemSetting::get(self::SETTING_NOTICE_DAYS, '7') ?? '7'));

        if (preg_match('/^\d{1,3}$/', $raw) !== 1) {
            throw new \RuntimeException(
                'Nilai setting "' . self::SETTING_NOTICE_DAYS . '" tidak valid. Hubungi administrator.'
            );
        }

        return (int) $raw;
    }

    /**
     * Masa tunggu efektif sebuah pengajuan penarikan.
     *
     * Operator selalu mengikuti kebijakan sistem (`system_settings`). Jalur
     * mitra (Fase 5) boleh memakai masa tunggu **0** — tetapi hanya untuk
     * penarikan DIVIDEN, yang menurut PRD § 4.3 tidak melewati masa tunggu.
     * Penarikan modal pokok tidak pernah bisa diperpendek dari luar, sehingga
     * tidak ada jalur yang diam-diam melonggarkan kebijakan H-7.
     *
     * @param mixed  $raw  nilai `notice_days` dari pemanggil (boleh kosong)
     * @param string $type salah satu `WithdrawalRequest::TYPE_*`
     */
    private static function noticeDaysInput(mixed $raw, string $type): int
    {
        $configured = self::configuredNoticeDays();

        if ($type !== WithdrawalRequest::TYPE_PROFIT) {
            return $configured;
        }

        if ($raw === 0 || $raw === '0' || $raw === '') {
            return 0;
        }

        return $configured;
    }

    // -----------------------------------------------------------------------
    // Parsing & normalisasi input
    // -----------------------------------------------------------------------

    /**
     * Nominal jalur uang: parser yang sama dengan layar rekonsiliasi, tetapi
     * wajib LEBIH BESAR dari nol (setoran/pindah kas/penarikan tidak berguna
     * bila nol).
     */
    private static function amountInput(mixed $raw, string $label): int
    {
        $cents = SettlementService::parseMoneyInput($raw, $label);

        if ($cents <= 0) {
            throw new \InvalidArgumentException($label . ' harus lebih besar dari nol.');
        }

        if ($cents > self::MAX_MONEY_CENTS) {
            throw new \InvalidArgumentException($label . ' melebihi kapasitas kolom pembukuan.');
        }

        return $cents;
    }

    /**
     * Kuota serapan: satu pemisah (`.` atau `,`) berarti desimal, maksimum 2
     * desimal, rentang 0–100 %. Nilai kosong berarti 0 %.
     *
     * Tier selain Mitra Prioritas selalu 0 % (rencana A-15).
     */
    private static function quotaInput(mixed $raw, string $tier): int
    {
        $value = self::stringInput($raw);

        if ($tier !== CapitalWallet::TIER_FAMILY) {
            if ($value !== '' && preg_match('/^0+(?:[.,]0{1,2})?$/', $value) !== 1) {
                throw new \InvalidArgumentException('Kuota serapan hanya dapat diisi untuk Mitra Prioritas.');
            }

            return 0;
        }

        if ($value === '') {
            return 0;
        }

        $value = trim((string) preg_replace('/\s*%\s*$/', '', $value));

        if (str_contains($value, '-')) {
            throw new \InvalidArgumentException('Kuota serapan tidak boleh negatif.');
        }

        $digits = preg_replace('/[^0-9.,]/', '', $value) ?? '';

        if ($digits === '') {
            throw new \InvalidArgumentException('Kuota serapan harus berupa angka. Contoh: 20');
        }

        if (str_contains($digits, ',') && str_contains($digits, '.')) {
            throw new \InvalidArgumentException('Gunakan satu pemisah desimal saja. Contoh: 20,5');
        }

        $digits = str_replace(',', '.', $digits);

        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $digits, $matches) !== 1) {
            throw new \InvalidArgumentException('Kuota serapan tidak valid atau lebih dari 2 desimal.');
        }

        $hundredths = (int) $matches[1] * 100
            + (int) str_pad((string) ($matches[2] ?? ''), 2, '0', STR_PAD_RIGHT);

        if ($hundredths > 10000) {
            throw new \InvalidArgumentException('Kuota serapan tidak boleh melebihi 100 %.');
        }

        return $hundredths;
    }

    private static function nameInput(mixed $raw): string
    {
        $value = self::stringInput($raw);

        $length = self::charLength($value);

        if ($length < 3) {
            throw new \InvalidArgumentException('Nama mitra wajib diisi (minimal 3 karakter).');
        }

        if ($length > self::MAX_NAME_CHARS) {
            throw new \InvalidArgumentException('Nama mitra terlalu panjang (maksimal ' . self::MAX_NAME_CHARS . ' karakter).');
        }

        return $value;
    }

    private static function phoneInput(mixed $raw): string
    {
        $value = self::stringInput($raw);

        if (preg_match(self::PHONE_PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException(
                'Nomor telepon tidak valid. Gunakan angka (boleh diawali + atau 0), 8–20 karakter.'
            );
        }

        return $value;
    }

    private static function noteInput(mixed $raw): string
    {
        $value = self::stringInput($raw);

        return $value === '' ? '' : self::truncate($value, self::MAX_NOTE_CHARS);
    }

    private static function stringInput(mixed $raw): string
    {
        return is_string($raw) ? trim($raw) : '';
    }

    /**
     * Jumlah karakter UTF-8 tanpa ekstensi `mbstring`.
     *
     * Proyek ini sengaja bebas `mb_*` (extensi itu tidak tersedia di semua host
     * cPanel, dan tidak pernah dipakai fase sebelumnya). PCRE dengan flag `/u`
     * menghitung code point, jadi hasilnya sama dengan `mb_strlen()`.
     */
    private static function charLength(string $value): int
    {
        $count = preg_match_all('/./us', $value);

        return $count === false ? strlen($value) : $count;
    }

    /**
     * Potong string pada BATAS KARAKTER (bukan byte) supaya tidak ada karakter
     * UTF-8 yang terbelah — kolom VARCHAR menghitung karakter, bukan byte.
     */
    private static function truncate(string $value, int $chars): string
    {
        if ($chars <= 0) {
            return '';
        }

        // Batas cepat: jumlah byte selalu >= jumlah karakter.
        if (strlen($value) <= $chars) {
            return $value;
        }

        if (preg_match('/^.{0,' . $chars . '}/us', $value, $matches) === 1) {
            return $matches[0];
        }

        return substr($value, 0, $chars);
    }

    private static function today(): string
    {
        return date('Y-m-d');
    }

    // -----------------------------------------------------------------------
    // Keterangan mutasi & catatan audit
    // -----------------------------------------------------------------------

    /**
     * `source_destination` VARCHAR(100) — dipotong aman bila nama mitra panjang.
     */
    private static function sourceLabel(string $prefix, string $ownerName): string
    {
        $label = $ownerName === '' ? $prefix : $prefix . ' — ' . $ownerName;

        return self::truncate($label, 100);
    }

    private static function mutationNotes(string $walletId, string $note): string
    {
        $notes = 'wallet_id=' . $walletId . '; waktu=' . date('c') . '; oleh=' . self::actor();

        if ($note !== '') {
            $notes .= '; catatan=' . $note;
        }

        return $notes;
    }

    private static function transferNotes(string $transferId, string $amount, string $note): string
    {
        $notes = 'transfer=' . $transferId . '; nominal=' . $amount . '; waktu=' . date('c')
            . '; oleh=' . self::actor();

        if ($note !== '') {
            $notes .= '; catatan=' . $note;
        }

        return $notes;
    }

    private static function actor(): string
    {
        return (string) ($_SESSION['user_id'] ?? '');
    }
}
