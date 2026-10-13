<?php

declare(strict_types=1);

namespace AurumFlow\Controllers;

use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Formatter;
use AurumFlow\Helpers\Logger;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\WithdrawalRequest;
use AurumFlow\Services\InvestorService;
use AurumFlow\Services\TellerService;

/**
 * Dasbor blind mitra (Layar D) & pengajuan penarikan mandiri (Fase 5).
 *
 * Isolasi data dipegang di tiga titik:
 *   1. `requireRole(Constants::ROLE_INVESTOR)` pada **setiap** method — mitra
 *      tidak pernah melihat layar operator, dan operator tidak masuk ke sini.
 *   2. Seluruh data disusun `InvestorService` dari dompet milik sesi; controller
 *      ini tidak pernah menyentuh model dompet, transaksi hulu, kas, atau
 *      setelan sistem secara langsung.
 *   3. Formulir tidak membawa `wallet_id`: dompet selalu diselesaikan dari
 *      `$_SESSION['user_id']`, sehingga payload yang menunjuk dompet mitra lain
 *      tidak punya jalan masuk.
 *
 * Konvensi yang sama dengan layar operator: CSRF diperiksa dua kali, jalur yang
 * mengubah data memakai token sekali-pakai `_once`, sukses = PRG + flash, dan
 * gagal validasi = `422` dengan isian terpelihara.
 */
final class InvestorController extends BaseController
{
    private const VIEW = 'investor/dashboard';

    private const LAYOUT = 'investor';

    private const ROUTE = '/investor/dashboard';

    /** Field form layar ini. */
    private const FIELDS = [
        'withdrawal_type',
        'withdrawal_amount',
        'withdrawal_note',
    ];

    /**
     * Layar D (GET).
     */
    public function index(): void
    {
        $this->renderDashboard(null, self::defaultInput(), 200);
    }

    /**
     * Ajukan penarikan dividen atau modal pokok (POST).
     */
    public function withdrawal(): void
    {
        $this->guardOnce();
        $input = self::readInput();

        try {
            $result = InvestorService::requestWithdrawal(self::userId(), [
                'withdrawal_type' => $input['withdrawal_type'],
                'amount'          => $input['withdrawal_amount'],
                'note'            => $input['withdrawal_note'],
            ]);
        } catch (\InvalidArgumentException $exception) {
            $this->renderDashboard($exception->getMessage(), $input, 422);

            return;
        } catch (\RuntimeException $exception) {
            Logger::error('Pengajuan penarikan mitra gagal pada lapisan sistem', [
                'user_id' => self::userId(),
                'alasan'  => $exception->getMessage(),
            ]);

            $this->renderDashboard($exception->getMessage(), $input, 422);

            return;
        }

        Logger::info('Pengajuan penarikan mitra tercatat', [
            'user_id'  => self::userId(),
            'request_id' => (string) $result['request_id'],
            'jenis'    => (string) $result['withdrawal_type'],
        ]);

        $this->flash('success', sprintf(
            'Pengajuan %s sebesar %s dicatat. %s',
            WithdrawalRequest::typeLabel((string) $result['withdrawal_type']),
            Formatter::rupiah(TellerService::centsToDecimal((int) $result['amount_cents'])),
            (int) $result['notice_days'] > 0
                ? 'Kas siap pada ' . Formatter::tanggalId((string) $result['notice_due'])
                    . ' (H-' . (int) $result['notice_days'] . ' hari kerja).'
                : 'Pengelola akan memproses pencairannya.'
        ));

        $this->redirect(self::ROUTE);
    }

    // -----------------------------------------------------------------------
    // Penjaga akses
    // -----------------------------------------------------------------------

    /**
     * Penjaga peran saja — untuk halaman GET yang tidak membawa POST.
     *
     * Dipisah dari `guard()` dengan sengaja: halaman GET tidak punya token
     * CSRF di `$_POST`, sehingga memakai penjaga versi POST di jalur render
     * akan menolak halaman yang sebenarnya sah (419).
     */
    private function requireInvestor(): void
    {
        $this->requireRole(Constants::ROLE_INVESTOR);
    }

    /**
     * Peran + CSRF (pemeriksaan kedua di lapisan controller).
     */
    private function guard(): void
    {
        $this->requireInvestor();

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
     * Penjaga untuk jalur yang mengubah data: tambah token sekali-pakai.
     */
    private function guardOnce(): void
    {
        $this->guard();

        $once = $_POST[Security::FORM_FIELD] ?? null;
        $status = Security::consumeFormToken(is_string($once) ? $once : null);

        if ($status === Security::FORM_DUPLICATE) {
            Logger::warning('Pengajuan penarikan ganda diabaikan', [
                'user_id' => self::userId(),
            ]);

            $this->flash('warning', 'Pengajuan ganda diabaikan — tidak ada pencatatan baru.');

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
    // Render
    // -----------------------------------------------------------------------

    /**
     * Render Layar D.
     *
     * Bila akun mitra belum memiliki dompet modal, layar tetap terbuka dengan
     * penjelasan — bukan halaman error 500 yang tidak menjelaskan apa pun.
     *
     * @param array<string, string> $input
     */
    private function renderDashboard(?string $error, array $input, int $status): void
    {
        $this->requireInvestor();

        $portfolio = null;
        $setupError = null;

        try {
            $portfolio = InvestorService::dashboard(self::userId());
        } catch (\RuntimeException $exception) {
            $setupError = $exception->getMessage();
        }

        if ($status !== 200 && !headers_sent()) {
            http_response_code($status);
        }

        $this->render(self::VIEW, [
            'title'        => 'Portofolio Saya',
            'portfolio'    => $portfolio,
            'setupError'   => $setupError,
            'input'        => $input,
            'error'        => $error,
            'formToken'    => Security::issueFormToken(),
            'mitra'        => [
                'name'  => (string) ($_SESSION['user_name'] ?? ''),
                'phone' => (string) ($_SESSION['user_phone'] ?? ''),
            ],
            'withdrawalTypes' => [
                [
                    'value' => WithdrawalRequest::TYPE_PROFIT,
                    'label' => WithdrawalRequest::typeLabel(WithdrawalRequest::TYPE_PROFIT),
                ],
                [
                    'value' => WithdrawalRequest::TYPE_CAPITAL,
                    'label' => WithdrawalRequest::typeLabel(WithdrawalRequest::TYPE_CAPITAL),
                ],
            ],
        ], self::LAYOUT);
    }

    // -----------------------------------------------------------------------
    // Input
    // -----------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private static function defaultInput(): array
    {
        $input = array_fill_keys(self::FIELDS, '');
        $input['withdrawal_type'] = WithdrawalRequest::TYPE_PROFIT;

        return $input;
    }

    /**
     * @return array<string, string>
     */
    private static function readInput(): array
    {
        $input = [];

        foreach (self::FIELDS as $field) {
            $value = $_POST[$field] ?? null;
            $input[$field] = is_string($value) ? trim($value) : '';
        }

        if (!in_array($input['withdrawal_type'], [
            WithdrawalRequest::TYPE_PROFIT,
            WithdrawalRequest::TYPE_CAPITAL,
        ], true)) {
            $input['withdrawal_type'] = WithdrawalRequest::TYPE_PROFIT;
        }

        return $input;
    }

    /**
     * Identitas mitra yang sedang masuk — satu-satunya sumber dompet.
     */
    private static function userId(): string
    {
        return (string) ($_SESSION['user_id'] ?? '');
    }
}
