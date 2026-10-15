<?php

declare(strict_types=1);

namespace AurumFlow\Controllers;

use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\User;
use AurumFlow\Services\PinLoginService;

/**
 * Pintu masuk mitra pemodal: nomor telepon + PIN 6 digit (Fase 5).
 *
 * Mengapa terpisah dari `AuthController` (kata sandi operator):
 *   - **Semantik kredensial berbeda.** PIN enam digit divalidasi bentuknya,
 *     kata sandi tidak; menyatukannya dalam satu field membuat pesan kesalahan
 *     dan validasi menjadi kabur.
 *   - **Pintu ini tidak boleh membocorkan apa pun.** Pesan gagal selalu
 *     generik, dan hanya akun berperan `investor` yang dapat masuk lewat sini.
 *
 * Sejak Fase 7 seluruh **aturan** pintu PIN hidup di `PinLoginService` (bentuk
 * PIN, `PinThrottle`, gerbang peran, pesan generik, jejak log) karena pintu
 * kasir memakai aturan yang sama. Controller ini hanya menyediakan permukaan
 * khas mitra: view, layout, dan berandanya sendiri.
 */
final class InvestorAuthController extends BaseController
{
    private const VIEW = 'investor/login';

    private const LAYOUT = 'auth';

    /**
     * Form PIN.
     */
    public function showLogin(): void
    {
        $this->requireGuest();

        $this->renderLogin(null, '', 200);
    }

    /**
     * Proses masuk mitra.
     */
    public function login(): void
    {
        $this->requireGuest();

        // Pemeriksaan kedua di lapisan controller (pertahanan berlapis),
        // sama seperti pintu kata sandi: front controller sudah memeriksa.
        $token = $_POST[Security::CSRF_FIELD] ?? null;

        if (!is_string($token) || !Security::verifyCsrf($token)) {
            self::abort(
                419,
                'Sesi kedaluwarsa',
                'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.'
            );
        }

        $result = PinLoginService::attempt(
            [
                'phone_number' => $_POST['phone_number'] ?? null,
                'pin'          => $_POST['pin'] ?? null,
            ],
            Constants::ROLE_INVESTOR,
            User::clientIp() ?? ''
        );

        if ($result['outcome'] !== PinLoginService::OK) {
            $this->renderLogin(
                (string) $result['message'],
                (string) $result['phone_number'],
                (int) $result['http_status']
            );

            return;
        }

        $this->startUserSession((array) $result['user']);

        $this->redirect(self::roleLandingPath(Constants::ROLE_INVESTOR));
    }

    // -----------------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------------

    /**
     * Render form PIN beserta pesan kesalahan (bila ada).
     *
     * Nilai nomor telepon dikembalikan supaya mitra tidak perlu mengetik ulang;
     * PIN tidak pernah dikembalikan — bahkan tidak dalam bentuk field terisi.
     */
    private function renderLogin(?string $error, string $phoneNumber, int $status): void
    {
        if ($status !== 200 && !headers_sent()) {
            http_response_code($status);
        }

        $this->render(self::VIEW, [
            'title'        => 'Masuk Portofolio',
            'error'        => $error,
            'phone_number' => $phoneNumber,
            'pin_length'   => Security::PIN_LENGTH,
        ], self::LAYOUT);
    }
}
