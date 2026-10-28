<?php

declare(strict_types=1);

namespace AurumFlow\Controllers;

use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Logger;
use AurumFlow\Helpers\Security;
use AurumFlow\Services\OnboardingService;

/**
 * Gerbang undangan mitra: `GET /join?token=…` (Layar A) dan `POST /join`.
 *
 * Halaman ini **publik** (dibuka mitra yang belum punya kredensial apa pun),
 * tetapi tidak membuka apa pun: satu-satunya hal yang dapat dilihat adalah
 * bahwa sebuah token undangan berlaku atau tidak, dan hanya bagi pihak yang
 * memegang token 64 heksadesimal.
 *
 * Aturan yang dipegang:
 *   1. Token divalidasi bentuknya sebelum menyentuh basis data
 *      (`OnboardingService::findInvitee()`), sehingga token sampah tidak
 *      pernah menjadi kueri.
 *   2. Persetujuan + PIN ditulis `OnboardingService::claim()` dalam **satu**
 *      transaksi, lalu token dihanguskan — tautan sekali pakai.
 *   3. Formulir **tidak** memakai token sekali-pakai `_once`: token undangan
 *      itu sendiri sudah sekali pakai, sehingga pengiriman ulang tidak mungkin
 *      menulis dua kali (percobaan kedua menemukan tautan sudah hangus).
 *   4. Kegagalan konfigurasi (pepper PIN belum diisi) tidak pernah
 *      dibocorkan ke pengunjung anonim: pesan generik + detail hanya ke log.
 */
final class InvestorOnboardingController extends BaseController
{
    private const VIEW = 'investor/onboarding';

    private const LAYOUT = 'auth';

    private const ROUTE = '/investor/login';

    /** Batas panjang token pada form (kolom `users.invite_token`). */
    private const TOKEN_MAX = 64;

    /**
     * Tampilkan Layar A untuk token yang sah.
     */
    public function show(): void
    {
        $this->refuseCashier();

        $token = self::query('token');
        $invitee = OnboardingService::findInvitee($token);

        if ($invitee === null) {
            self::abort(404, 'Tautan tidak berlaku', OnboardingService::INVALID_LINK_MESSAGE);
        }

        $this->renderForm(null, $token, $invitee, 200, []);
    }

    /**
     * Proses persetujuan + penetapan PIN.
     */
    public function submit(): void
    {
        $this->refuseCashier();

        // Pemeriksaan kedua di lapisan controller (pertahanan berlapis).
        $csrf = $_POST[Security::CSRF_FIELD] ?? null;

        if (!is_string($csrf) || !Security::verifyCsrf($csrf)) {
            self::abort(
                419,
                'Sesi kedaluwarsa',
                'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.'
            );
        }

        $token = self::post('token');
        $input = [
            'accepted'    => self::post('accept_waiver'),
            'pin'         => self::post('pin'),
            'pin_confirm' => self::post('pin_confirm'),
        ];

        try {
            $result = OnboardingService::claim($token, $input);
        } catch (\InvalidArgumentException $exception) {
            $invitee = OnboardingService::findInvitee($token);

            // Token sudah hangus / tidak dikenal: tampilkan halaman tautan
            // tidak berlaku, bukan formulir yang tidak mungkin berhasil.
            if ($invitee === null) {
                self::abort(404, 'Tautan tidak berlaku', $exception->getMessage());
            }

            $this->renderForm($exception->getMessage(), $token, $invitee, 422, $input);

            return;
        } catch (\RuntimeException $exception) {
            // Kebijakan rusak (mis. `APP_PIN_PEPPER` kosong) — operator melihat
            // sebabnya di log, mitra hanya diberi tahu untuk menghubungi pengelola.
            Logger::error('Onboarding mitra gagal pada lapisan sistem', [
                'alasan' => $exception->getMessage(),
            ]);

            $invitee = OnboardingService::findInvitee($token);

            if ($invitee === null) {
                self::abort(404, 'Tautan tidak berlaku', OnboardingService::INVALID_LINK_MESSAGE);
            }

            $this->renderForm(
                'Pendaftaran belum dapat diproses. Hubungi pengelola untuk mencoba lagi nanti.',
                $token,
                $invitee,
                503,
                $input
            );

            return;
        }

        $this->flash(
            'success',
            'PIN aktif untuk nomor ' . (string) $result['phone_number']
            . '. Silakan masuk ke portofolio Anda.'
        );

        $this->redirect(self::ROUTE);
    }

    // -----------------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------------

    /**
     * Kasir tidak pernah menyentuh permukaan onboarding mitra (Fase 7).
     *
     * Halaman ini memang **publik** — otorisasinya adalah token undangan 64
     * heksadesimal, bukan sesi. Namun halaman ini menampilkan data calon mitra
     * (nama & nomor telepon), dan itu termasuk "data investor" yang wajib
     * tertutup bagi staf. Karena itu sesi **selain mitra** (kasir, mitra
     * lapangan, pengelola) diarahkan ke beranda perannya **sebelum** token
     * dibaca: tidak ada data mitra yang dirender dan tidak ada PIN yang dapat
     * ditetapkan lewat sesi staf. Pengunjung anonim dan mitra (mis. sesi lama)
     * tidak terpengaruh — perilaku Fase 5 tetap utuh.
     */
    private function refuseCashier(): void
    {
        $role = (string) ($_SESSION['user_role'] ?? '');

        if ($role === '' || $role === Constants::ROLE_INVESTOR) {
            return;
        }

        $this->redirect(self::roleLandingPath($role));
    }

    /**
     * Render Layar A.
     *
     * @param array<string, string> $input isian terakhir (agar mitra tidak
     *                                     mengetik ulang; PIN tidak diisi kembali)
     */
    private function renderForm(
        ?string $error,
        string $token,
        array $invitee,
        int $status,
        array $input
    ): void {
        if ($status !== 200 && !headers_sent()) {
            http_response_code($status);
        }

        $this->render(self::VIEW, [
            'title'         => 'Aktivasi Portofolio',
            'error'         => $error,
            'token'         => $token,
            'full_name'     => (string) ($invitee['full_name'] ?? ''),
            'phone_number'  => (string) ($invitee['phone_number'] ?? ''),
            'waiver_clause' => OnboardingService::waiverClause(),
            'waiver_version' => OnboardingService::WAIVER_VERSION,
            'pin_length'    => Security::PIN_LENGTH,
            'accepted'      => ($input['accepted'] ?? '') !== '',
        ], self::LAYOUT);
    }

    private static function query(string $name): string
    {
        $value = $_GET[$name] ?? null;

        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        // Token undangan selalu 64 heksadesimal; pemotongan di sini menjaga
        // agar nilai raksasa dari URL tidak pernah diproses lebih jauh.
        return strlen($value) > self::TOKEN_MAX ? substr($value, 0, self::TOKEN_MAX) : $value;
    }

    private static function post(string $field): string
    {
        $value = $_POST[$field] ?? null;

        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        return strlen($value) > self::TOKEN_MAX ? substr($value, 0, self::TOKEN_MAX) : $value;
    }
}
