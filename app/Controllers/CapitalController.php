<?php

declare(strict_types=1);

namespace AurumFlow\Controllers;

use AurumFlow\Config\App;
use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Formatter;
use AurumFlow\Helpers\Logger;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\CapitalWallet;
use AurumFlow\Models\CashRegister;
use AurumFlow\Models\HuluTransaction;
use AurumFlow\Models\SystemSetting;
use AurumFlow\Models\WithdrawalRequest;
use AurumFlow\Services\AllocationEngine;
use AurumFlow\Services\CapitalService;
use AurumFlow\Services\OnboardingService;
use AurumFlow\Services\TellerService;

/**
 * Kelola Modal & Likuiditas — "Layar E" dokumen UI/UX (Fase 4).
 *
 * Aturan yang dipegang controller ini:
 *   1. Hanya peran `operator` yang boleh membuka maupun memutasi modal dan kas.
 *      Pelanggar diarahkan ke `/dashboard` (bukan halaman error), sesuai
 *      ekspektasi Protokol QC Manual 5.
 *   2. CSRF diperiksa DUA kali (front controller + di sini). Jalur yang
 *      MENGGERAKKAN KAS (setoran, Pindah Kas, penarikan) menambah token
 *      sekali-pakai `_once` supaya satu klik tidak pernah tercatat dua kali.
 *   3. Nominal TIDAK PERNAH dipercaya dari klien: yang dibaca dari form hanya
 *      pemilih dompet, nominal, kuota, jenis/keputusan penarikan, dan catatan.
 *      Saldo, serapan, dan distribusi dihitung ulang `CapitalService` /
 *      `AllocationEngine`.
 *   4. Sukses memakai POST-Redirect-GET; gagal validasi mengembalikan 422
 *      beserta isian form supaya operator tidak mengetik ulang.
 *   5. Simulator "Belanja Malam Ini" hanya pratinjau; proyeksi yang dirender
 *      peladen dihitung `AllocationEngine::planFromDatabase()`.
 */
final class CapitalController extends BaseController
{
    private const VIEW = 'operator/capital';

    private const LAYOUT = 'operator';

    private const ROUTE = '/operator/capital';

    /**
     * Seluruh field form layar ini. Nama diberi awalan bagian supaya isian yang
     * dikembalikan pada `422` tidak pernah tertukar antar kartu.
     *
     * @var list<string>
     */
    private const FIELDS = [
        'topup_wallet_id',
        'topup_amount',
        'topup_note',
        'mitra_mode',
        'mitra_full_name',
        'mitra_phone_number',
        'mitra_tier',
        'mitra_quota',
        'mitra_user_id',
        'quota_wallet_id',
        'quota_value',
        'transfer_amount',
        'transfer_note',
        'withdrawal_action',
        'withdrawal_wallet_id',
        'withdrawal_type',
        'withdrawal_amount',
        'withdrawal_note',
        'withdrawal_request_id',
        'invite_wallet_id',
        'invite_action',
    ];

    /**
     * Layar Kelola Modal (GET).
     */
    public function index(): void
    {
        $this->renderCapital(null, self::defaultInput());
    }

    /**
     * Setoran modal baru (POST) — kas masuk ke Brankas Utama.
     */
    public function topUp(): void
    {
        $this->guardOnce();
        $input = self::readInput();

        $this->execute(
            static fn (): array => CapitalService::topUp([
                'wallet_id' => $input['topup_wallet_id'],
                'amount'    => $input['topup_amount'],
                'note'      => $input['topup_note'],
            ]),
            $input,
            static fn (array $result): string => sprintf(
                'Setoran %s dari %s tersimpan: modal %s, %s %s.',
                self::money((int) $result['amount_cents']),
                (string) $result['owner'],
                Formatter::rupiah($result['wallet_before']),
                CashRegister::BRANKAS_NAME,
                Formatter::rupiah($result['brankas_after'])
            )
        );
    }

    /**
     * Tambah mitra baru atau dompet untuk pengguna yang sudah ada (POST).
     */
    public function storeMitra(): void
    {
        $this->guard();
        $input = self::readInput();

        $mode = $input['mitra_mode'] === 'dompet' ? 'dompet' : 'mitra';

        if ($mode === 'dompet') {
            $this->execute(
                static fn (): array => CapitalService::createWalletForUser([
                    'user_id' => $input['mitra_user_id'],
                    'tier'    => $input['mitra_tier'],
                    'quota'   => $input['mitra_quota'],
                ]),
                $input,
                static fn (array $result): string => sprintf(
                    'Dompet %s untuk %s berhasil dibuat.',
                    CapitalWallet::label((string) $result['tier']),
                    (string) $result['full_name']
                )
            );

            return;
        }

        $this->execute(
            static fn (): array => CapitalService::createMitra([
                'full_name'    => $input['mitra_full_name'],
                'phone_number' => $input['mitra_phone_number'],
                'tier'         => $input['mitra_tier'],
                'quota'        => $input['mitra_quota'],
            ]),
            $input,
            static fn (array $result): string => sprintf(
                'Mitra %s (%s) terdaftar dengan tautan undangan. Salin tautannya pada kartu '
                . '"Undangan & Onboarding Mitra", lalu catat setoran pertamanya di "Setor Modal Baru".',
                (string) $result['full_name'],
                CapitalWallet::label((string) $result['tier'])
            )
        );
    }

    /**
     * Ubah kuota serapan terkunci (POST) — hanya Mitra Prioritas.
     */
    public function updateQuota(): void
    {
        $this->guard();
        $input = self::readInput();

        $this->execute(
            static fn (): array => CapitalService::updateQuota([
                'wallet_id' => $input['quota_wallet_id'],
                'quota'     => $input['quota_value'],
            ]),
            $input,
            static fn (array $result): string => sprintf(
                'Kuota serapan %s disetel %s%%.',
                CapitalWallet::label((string) $result['tier']),
                rtrim(rtrim(AllocationEngine::hundredthsToDecimal((int) $result['quota_hundredths']), '0'), '.')
            )
        );
    }

    /**
     * Pindah kas Brankas Utama → Kas Meja Bakar (POST).
     */
    public function transfer(): void
    {
        $this->guardOnce();
        $input = self::readInput();

        $this->execute(
            static fn (): array => CapitalService::transferToTeller([
                'amount' => $input['transfer_amount'],
                'note'   => $input['transfer_note'],
            ]),
            $input,
            static fn (array $result): string => sprintf(
                'Pindah kas %s dari %s ke %s berhasil (kas meja %s, brankas %s).',
                self::money((int) $result['amount_cents']),
                CashRegister::BRANKAS_NAME,
                CashRegister::TELLER_NAME,
                Formatter::rupiah($result['teller_after']),
                Formatter::rupiah($result['brankas_after'])
            )
        );
    }

    /**
     * Terbitkan / putar ulang tautan undangan seorang mitra (POST — Fase 5).
     *
     * Tidak memakai token sekali-pakai `_once`: aksinya **idempoten secara
     * alami** (memutar token tidak menggandakan data apa pun), dan operator
     * yang menekan dua kali hanya mendapatkan tautan baru — bukan dua akun.
     */
    public function invite(): void
    {
        $this->guard();
        $input = self::readInput();

        $this->execute(
            static fn (): array => CapitalService::issueInvite([
                'wallet_id' => $input['invite_wallet_id'],
                'mode'      => $input['invite_action'] === 'reset' ? 'reset' : 'issue',
            ]),
            $input,
            static fn (array $result): string => sprintf(
                'Tautan undangan %s %s dibuat. Salin dan kirimkan lewat WhatsApp.',
                (string) $result['full_name'],
                $result['already_signed'] ? 'ulang' : ''
            )
        );
    }

    /**
     * Antrean penarikan: ajukan / setujui / tolak / bayar (POST).
     */
    public function withdrawal(): void
    {
        $this->guardOnce();
        $input = self::readInput();

        $action = $input['withdrawal_action'];

        if ($action === 'setujui' || $action === 'tolak') {
            $decision = $action === 'setujui' ? 'approve' : 'reject';

            $this->execute(
                static fn (): array => CapitalService::decideWithdrawal([
                    'request_id' => $input['withdrawal_request_id'],
                    'decision'   => $decision,
                ]),
                $input,
                static fn (array $result): string => sprintf(
                    'Pengajuan penarikan %s sebesar %s %s.',
                    WithdrawalRequest::typeLabel((string) $result['withdrawal_type']),
                    self::money((int) $result['amount_cents']),
                    $decision === 'approve' ? 'disetujui' : 'ditolak'
                )
            );

            return;
        }

        if ($action === 'bayar') {
            $this->execute(
                static fn (): array => CapitalService::payWithdrawal([
                    'request_id' => $input['withdrawal_request_id'],
                ]),
                $input,
                static fn (array $result): string => sprintf(
                    'Penarikan %s sebesar %s dibayarkan dari %s (sisa %s).',
                    WithdrawalRequest::typeLabel((string) $result['withdrawal_type']),
                    self::money((int) $result['amount_cents']),
                    CashRegister::BRANKAS_NAME,
                    Formatter::rupiah($result['brankas_after'])
                )
            );

            return;
        }

        $this->execute(
            static fn (): array => CapitalService::requestWithdrawal([
                'wallet_id'       => $input['withdrawal_wallet_id'],
                'withdrawal_type' => $input['withdrawal_type'],
                'amount'          => $input['withdrawal_amount'],
                'note'            => $input['withdrawal_note'],
            ]),
            $input,
            static fn (array $result): string => sprintf(
                'Pengajuan %s sebesar %s dicatat. Jatuh tempo pencairan %s (H-%d hari kerja).',
                WithdrawalRequest::typeLabel((string) $result['withdrawal_type']),
                self::money((int) $result['amount_cents']),
                Formatter::tanggalId((string) $result['notice_due'], false),
                (int) $result['notice_days']
            )
        );
    }

    // -----------------------------------------------------------------------
    // Penjaga akses
    // -----------------------------------------------------------------------

    /**
     * Peran + CSRF (pemeriksaan kedua di lapisan controller).
     */
    private function guard(): void
    {
        $this->requireRole(Constants::ROLE_OPERATOR);

        $token = $_POST[Security::CSRF_FIELD] ?? null;

        if (!is_string($token) || !Security::verifyCsrf($token)) {
            self::abort(
                419,
                'Sesi kedaluwarsa',
                'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.'
            );
        }
    }

    /**
     * Penjaga untuk jalur yang MENGGERAKKAN KAS: tambah token sekali-pakai.
     */
    private function guardOnce(): void
    {
        $this->guard();

        $once = $_POST[Security::FORM_FIELD] ?? null;
        $status = Security::consumeFormToken(is_string($once) ? $once : null);

        if ($status === Security::FORM_DUPLICATE) {
            Logger::warning('Permintaan modal ganda diabaikan', [
                'user_id' => (string) ($_SESSION['user_id'] ?? ''),
            ]);

            $this->flash('warning', 'Permintaan ganda diabaikan — tidak ada pencatatan baru.');

            $this->redirect(self::ROUTE);
        }

        if ($status !== Security::FORM_OK) {
            self::abort(
                419,
                'Sesi kedaluwarsa',
                'Token konfirmasi tidak valid atau sudah dipakai. Muat ulang halaman lalu coba lagi.'
            );
        }
    }

    // -----------------------------------------------------------------------
    // Eksekusi & render
    // -----------------------------------------------------------------------

    /**
     * Jalankan satu operasi modal, lalu PRG (sukses) atau 422 + isian (gagal).
     *
     * @param callable():array<string, mixed> $action
     * @param array<string, string> $input
     * @param callable(array<string, mixed>):string $message
     */
    private function execute(callable $action, array $input, callable $message): void
    {
        try {
            $result = $action();
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            if ($exception instanceof \RuntimeException) {
                Logger::error('Operasi modal gagal pada lapisan sistem', [
                    'user_id' => (string) ($_SESSION['user_id'] ?? ''),
                    'alasan'  => $exception->getMessage(),
                ]);
            }

            $this->renderCapital($exception->getMessage(), $input, 422);

            return;
        }

        $this->flash('success', $message($result));
        $this->redirect(self::ROUTE);
    }

    /**
     * Render Layar E beserta seluruh data pendukung.
     *
     * @param array<string, string> $input
     */
    private function renderCapital(?string $error, array $input, int $status = 200): void
    {
        $this->requireRole(Constants::ROLE_OPERATOR);

        $teller = CashRegister::activeTeller();
        $brankas = CashRegister::findBrankas();

        if ($teller === null || $brankas === null) {
            self::abort(
                500,
                'Pos kas tidak tersedia',
                'Pos kas "' . CashRegister::TELLER_NAME . '" atau "' . CashRegister::BRANKAS_NAME
                . '" belum terdaftar. Jalankan instalasi basis data.'
            );
        }

        if ($status !== 200 && !headers_sent()) {
            http_response_code($status);
        }

        $wallets = CapitalWallet::listWithUser();
        $totals = HuluTransaction::holdingTotals();

        // Proyeksi serapan: dihitung peladen sehingga tabel tetap benar tanpa
        // JavaScript. Bila kebijakan alokasi rusak, layar tetap terbuka dengan
        // pesan, bukan halaman 500.
        $projectionError = null;
        $projection = null;

        try {
            $projection = AllocationEngine::planFromDatabase();
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            $projectionError = $exception->getMessage();
        }

        // Masa tunggu penarikan dibaca terpisah supaya kebijakan yang rusak tidak
        // ikut menyembunyikan proyeksi serapan yang sebenarnya sehat.
        $noticeDays = 0;

        try {
            $noticeDays = CapitalService::configuredNoticeDays();
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            $projectionError ??= $exception->getMessage();
        }

        // Kuota dasar Pengelola dibaca dari sumber yang SAMA dengan mesin alokasi
        // (`system_settings.operator_base_quota_pct`) supaya badge pada tabel
        // portofolio tidak pernah berbeda dari angka yang benar-benar dipakai.
        $operatorQuotaLabel = self::operatorQuotaLabel();

        $operatorUserId = (string) ($_SESSION['user_id'] ?? '');
        $hasOperatorWallet = false;
        $walletViews = [];
        $simulatorWallets = [];

        foreach ($wallets as $wallet) {
            $tier = (string) ($wallet['tier'] ?? '');
            $tierLabel = CapitalWallet::label($tier);

            if ($tier === CapitalWallet::TIER_OPERATOR) {
                $hasOperatorWallet = true;
            }

            $walletId = (string) ($wallet['id'] ?? '');
            $capital = (string) ($wallet['balance_capital'] ?? '0.00');
            $profit = (string) ($wallet['balance_profit'] ?? '0.00');
            $quotaHundredths = self::quotaHundredthsOf((string) ($wallet['locked_quota_pct'] ?? '0.00'));

            // Undangan & onboarding (Fase 5). Akun Pengelola tidak punya tautan
            // undangan — ia masuk lewat /login dengan kata sandi.
            $inviteToken = trim((string) ($wallet['invite_token'] ?? ''));
            $inviteState = OnboardingService::effectiveInviteState($wallet);
            $canInvite = $tier !== CapitalWallet::TIER_OPERATOR;

            $walletViews[] = [
                'id'            => $walletId,
                'name'          => (string) ($wallet['full_name'] ?? ''),
                'phone'         => (string) ($wallet['phone_number'] ?? ''),
                'tier'          => $tier,
                'tier_label'    => $tierLabel,
                'capital_label' => Formatter::rupiah($capital),
                'profit_label'  => Formatter::rupiah($profit),
                'quota_value'   => self::quotaFieldValue($quotaHundredths),
                'quota_label'   => self::quotaFieldValue($quotaHundredths) . ' %',
                'withdrawal_status' => (string) ($wallet['withdrawal_status'] ?? CapitalWallet::WITHDRAWAL_NONE),
                'can_invite'      => $canInvite,
                'invite_state'    => $inviteState,
                'invite_state_label' => OnboardingService::inviteStateLabel($inviteState),
                // Tautan lengkap hanya bila token ada. Halaman ini operator-only
                // dan operatorlah pihak yang mengirim tautannya ke mitra.
                'invite_url'      => $canInvite && $inviteToken !== ''
                    ? App::absoluteUrl('join') . '?token=' . $inviteToken
                    : '',
                'invite_action'   => $inviteState === OnboardingService::STATE_ACTIVE ? 'reset' : 'issue',
                'invite_button'   => $inviteState === OnboardingService::STATE_NONE
                    ? 'Buat tautan'
                    : ($inviteState === OnboardingService::STATE_ACTIVE ? 'Buat ulang tautan' : 'Putar ulang tautan'),
                'onboarding_signed_label' => trim((string) ($wallet['onboarding_signed_at'] ?? '')) === ''
                    ? ''
                    : Formatter::tanggalId((string) $wallet['onboarding_signed_at'], true),
            ];

            $simulatorWallets[] = [
                'id'                => $walletId,
                'name'              => (string) ($wallet['full_name'] ?? ''),
                'tier'              => $tier,
                'tier_label'        => $tierLabel,
                'available_cents'   => (int) TellerService::moneyToCents($capital),
                'quota_hundredths'  => $quotaHundredths,
            ];
        }

        $projectionView = self::projectionView($projection);

        $alpineConfig = json_encode([
            'operator_quota_hundredths' => self::operatorQuotaHundredths(),
            'need_cents'                => $projectionView['need_cents'],
            'wallets'                   => $simulatorWallets,
        ], JSON_THROW_ON_ERROR);

        $this->render(self::VIEW, [
            'title' => 'Kelola Modal & Likuiditas',

            // Kontrak layout operator (header sticky).
            'register' => [
                'name'        => (string) $teller['register_name'],
                'saldo_label' => Formatter::rupiah($teller['current_balance']),
            ],
            'holding' => [
                'count'      => $totals['count'],
                'gram_label' => Formatter::gram($totals['grams']),
            ],

            'registers' => [
                'teller' => [
                    'name'        => (string) $teller['register_name'],
                    'saldo_label' => Formatter::rupiah($teller['current_balance']),
                ],
                'brankas' => [
                    'name'        => (string) $brankas['register_name'],
                    'saldo_label' => Formatter::rupiah($brankas['current_balance']),
                ],
            ],

            'wallets'          => $walletViews,
            'projection'       => $projectionView,
            'projectionError'  => $projectionError,
            'operatorMissing'  => !$hasOperatorWallet,
            'operatorUserId'   => $operatorUserId,
            'operatorQuotaLabel' => $operatorQuotaLabel,
            'queue'            => self::queueView(),
            'tierOptions'      => array_map(
                static fn (string $tier): array => ['value' => $tier, 'label' => CapitalWallet::label($tier)],
                CapitalWallet::selectableTiers()
            ),
            'noticeDays'       => $noticeDays,
            'activeDeficit'    => Formatter::rupiah((string) (SystemSetting::get('active_carryover_deficit', '0.00') ?? '0.00')),
            'formToken'        => Security::issueFormToken(),
            'alpineConfig'     => $alpineConfig,
            'input'            => $input,
            'error'            => $error,
        ], self::LAYOUT);
    }

    /**
     * Bentuk data proyeksi serapan untuk view (seluruh angka sudah terformat
     * peladen supaya tabel tetap benar tanpa JavaScript).
     *
     * @param array<string, mixed>|null $projection
     * @return array<string, mixed>
     */
    private static function projectionView(?array $projection): array
    {
        if ($projection === null) {
            return [
                'available'       => false,
                'need_cents'      => 0,
                'need_label'      => Formatter::rupiah(0),
                'need_field'      => '',
                'rows'            => [],
                'uncovered_label' => Formatter::rupiah(0),
                'utilized_label'  => Formatter::rupiah(0),
                'stages'          => [
                    'operator' => Formatter::rupiah(0),
                    'family'   => Formatter::rupiah(0),
                    'external' => Formatter::rupiah(0),
                ],
            ];
        }

        $rows = [];

        foreach ($projection['rows'] as $row) {
            $rows[] = [
                'id'              => (string) $row['id'],
                'name'            => (string) ($row['name'] ?? ''),
                'tier'            => (string) $row['tier'],
                'tier_label'      => (string) ($row['tier_label'] ?? CapitalWallet::label((string) $row['tier'])),
                'utilized_cents'  => (int) $row['utilized_cents'],
                'utilized_label'  => self::money((int) $row['utilized_cents']),
                'available_label' => self::money((int) $row['available_cents']),
                'quota_label'     => self::quotaFieldValue((int) $row['quota_hundredths']) . ' %',
            ];
        }

        return [
            'available'       => true,
            'need_cents'      => (int) $projection['need_cents'],
            'need_label'      => self::money((int) $projection['need_cents']),
            // Nilai awal isian simulator: angka tanpa awalan "Rp " supaya kolom
            // tetap berupa angka lapangan (konvensi placeholder `mis. `).
            'need_field'      => self::fieldValue($projection['need_cents']),
            'rows'            => $rows,
            'uncovered_label' => self::money((int) $projection['uncovered_cents']),
            'utilized_label'  => self::money((int) $projection['utilized_cents']),
            'stages'          => [
                'operator' => self::money((int) $projection['stages'][CapitalWallet::TIER_OPERATOR]),
                'family'   => self::money((int) $projection['stages'][CapitalWallet::TIER_FAMILY]),
                'external' => self::money((int) $projection['stages'][CapitalWallet::TIER_EXTERNAL]),
            ],
        ];
    }

    /**
     * Antrean penarikan yang belum selesai.
     *
     * "Sisa hari" dihitung dalam hari kalender untuk keterbacaan operator —
     * yang menentukan boleh-tidaknya pencairan adalah tanggal `notice_period_due`
     * yang dihitung CapitalService dalam HARI KERJA.
     *
     * @return list<array<string, mixed>>
     */
    private static function queueView(): array
    {
        $today = date('Y-m-d');
        $rows = [];

        foreach (WithdrawalRequest::listOpenWithWallet() as $request) {
            $type = (string) ($request['withdrawal_type'] ?? '');
            $status = (string) ($request['status'] ?? '');
            $dueDate = (string) ($request['notice_period_due'] ?? '');
            $isCapital = $type === WithdrawalRequest::TYPE_CAPITAL;
            $dueReached = $dueDate === '' || $dueDate <= $today;
            $remainingDays = $dueDate === '' ? 0 : (int) floor(
                (strtotime($dueDate . ' 12:00:00') - strtotime($today . ' 12:00:00')) / 86400
            );

            $rows[] = [
                'id'            => (string) ($request['id'] ?? ''),
                'wallet_id'     => (string) ($request['wallet_id'] ?? ''),
                'owner'         => (string) ($request['full_name'] ?? ''),
                'tier_label'    => CapitalWallet::label((string) ($request['tier'] ?? '')),
                'type'          => $type,
                'type_label'    => WithdrawalRequest::typeLabel($type),
                'amount_label'  => Formatter::rupiah($request['amount'] ?? 0),
                'status'        => $status,
                'status_label'  => WithdrawalRequest::statusLabel($status),
                'due_label'     => $dueDate === '' ? '-' : Formatter::tanggalId($dueDate, false),
                'due_iso'       => $dueDate,
                'remaining'     => $remainingDays > 0 ? 'sisa ' . $remainingDays . ' hari' : 'jatuh tempo',
                'pending'       => $status === WithdrawalRequest::STATUS_PENDING,
                'approved'      => $status === WithdrawalRequest::STATUS_APPROVED,
                'payable'       => $status === WithdrawalRequest::STATUS_APPROVED
                    && (!$isCapital || $dueReached),
                'blocked_note'  => $isCapital && !$dueReached
                    ? 'Pencairan menunggu jatuh tempo ' . Formatter::tanggalId($dueDate, false) . ' (H-7 hari kerja)'
                    : '',
            ];
        }

        return $rows;
    }

    /**
     * Nilai awal form saat halaman pertama dibuka.
     *
     * Hanya pilihan yang punya jawaban wajar yang diisi lebih dulu (jenis mitra
     * dan jenis penarikan); seluruh nominal dibiarkan kosong dengan contoh
     * berawalan `mis. `.
     *
     * @return array<string, string>
     */
    private static function defaultInput(): array
    {
        $input = array_fill_keys(self::FIELDS, '');

        $input['mitra_mode'] = 'mitra';
        $input['mitra_tier'] = CapitalWallet::TIER_EXTERNAL;
        $input['withdrawal_action'] = 'ajukan';
        $input['withdrawal_type'] = WithdrawalRequest::TYPE_PROFIT;
        $input['invite_action'] = 'issue';

        return $input;
    }

    /**
     * @return array<string, string>
     */
    private static function readInput(): array
    {
        $input = [];

        foreach (self::FIELDS as $field) {
            $input[$field] = self::post($field);
        }

        if (!in_array($input['withdrawal_action'], ['ajukan', 'setujui', 'tolak', 'bayar'], true)) {
            $input['withdrawal_action'] = 'ajukan';
        }

        return $input;
    }

    private static function post(string $field): string
    {
        $value = $_POST[$field] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Kuota `DECIMAL(5,2)` menjadi perseratus; nilai rusak ditampilkan sebagai 0.
     */
    private static function quotaHundredthsOf(string $decimal): int
    {
        try {
            return AllocationEngine::quotaHundredths($decimal);
        } catch (\InvalidArgumentException) {
            return 0;
        }
    }

    /**
     * Nilai kuota untuk field form: dua desimal, koma desimal (`'20,00'`),
     * konsisten dengan konvensi lapangan dokumen UI/UX.
     */
    private static function quotaFieldValue(int $hundredths): string
    {
        return str_replace('.', ',', AllocationEngine::hundredthsToDecimal($hundredths));
    }

    /**
     * Kuota Pengelola untuk konfigurasi Alpine; nilai rusak dilaporkan sebagai 0
     * supaya layar tetap terbuka (proyeksi peladen sudah menampilkan pesannya).
     */
    private static function operatorQuotaHundredths(): int
    {
        try {
            return AllocationEngine::configuredOperatorQuota();
        } catch (\InvalidArgumentException | \RuntimeException) {
            return 0;
        }
    }

    /**
     * Sen (integer) menjadi angka lapangan bergaya Indonesia (`'35.157.500'`)
     * tanpa awalan mata uang — dipakai sebagai nilai awal isian form.
     *
     * Memakai `Formatter::rupiah()` supaya konvensi ribuan tetap satu pemilik,
     * lalu membuang awalan `Rp `.
     */
    private static function fieldValue(int $cents): string
    {
        return trim(str_replace(Formatter::MONEY_PREFIX, '', self::money($cents)));
    }

    /**
     * Sen (integer) menjadi label Rupiah.
     *
     * Sengaja TIDAK memakai pembagian pecahan: nilainya dikonversi ke string
     * desimal kanonik lewat `TellerService` lalu diformat `Formatter`, sehingga
     * jalur uang tetap integer dari ujung ke ujung (rencana Fase 4 § 4.1).
     */
    private static function money(int $cents): string
    {
        return Formatter::rupiah(TellerService::centsToDecimal($cents));
    }

    /**
     * Label kuota dasar Pengelola untuk tabel portofolio (mis. `'50,00 %'`).
     *
     * Membaca kebijakan yang SAMA dengan `AllocationEngine`
     * (`system_settings.operator_base_quota_pct`) lalu memformatnya dengan
     * konvensi angka lapangan layar ini, sehingga badge tidak pernah
     * menampilkan angka yang berbeda dari yang benar-benar dipakai mesin
     * alokasi. Bila kebijakan tidak terbaca, badge menampilkan tanda pisah —
     * bukan `0,00 %` yang menyesatkan (penyebabnya sudah tampil pada banner
     * kesalahan kebijakan).
     */
    private static function operatorQuotaLabel(): string
    {
        try {
            return self::quotaFieldValue(AllocationEngine::configuredOperatorQuota()) . ' %';
        } catch (\InvalidArgumentException | \RuntimeException) {
            return '—';
        }
    }
}
