<?php

declare(strict_types=1);

namespace AurumFlow\Controllers;

use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Logger;
use AurumFlow\Helpers\PinThrottle;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\User;

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
 * Aturan yang dipegang:
 *   1. PIN **ber-pepper** (`Security::verifyPin()`), sehingga PIN tidak pernah
 *      sah di `POST /login` — pembatas percobaan punya satu pintu.
 *   2. `PinThrottle` membatasi 5 kegagalan / 15 menit per `nomor + IP`; pesan
 *      `429` menyebut sisa menit, tanpa mengungkap apakah nomor terdaftar.
 *   3. Session ID diregenerasi + token CSRF diputar setelah login berhasil.
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

        $phoneNumber = self::post('phone_number');
        $pin = self::post('pin');

        if ($phoneNumber === '' || $pin === '') {
            $this->renderLogin('Nomor telepon dan PIN wajib diisi.', $phoneNumber, 422);

            return;
        }

        $ipAddress = User::clientIp() ?? '';
        $lockedSeconds = PinThrottle::check($phoneNumber, $ipAddress);

        if ($lockedSeconds > 0) {
            $this->renderLogin(self::lockedMessage($lockedSeconds), $phoneNumber, 429);

            return;
        }

        if (preg_match('/^[0-9]{' . Security::PIN_LENGTH . '}$/', $pin) !== 1) {
            $this->renderLogin(
                'PIN harus tepat ' . Security::PIN_LENGTH . ' angka.',
                $phoneNumber,
                422
            );

            return;
        }

        $user = User::findByPhoneNumber($phoneNumber);

        if ($user === null
            || (string) $user['role'] !== Constants::ROLE_INVESTOR
            || !User::verifyPin($user, $pin)
        ) {
            $lockedSeconds = PinThrottle::recordFailure($phoneNumber, $ipAddress);

            Logger::warning('Percobaan login mitra gagal', [
                'phone_number' => $phoneNumber,
                'ip'           => $ipAddress,
            ]);

            $message = $lockedSeconds > 0
                ? self::lockedMessage($lockedSeconds)
                : 'Nomor telepon atau PIN salah.';

            $this->renderLogin($message, $phoneNumber, $lockedSeconds > 0 ? 429 : 401);

            return;
        }

        PinThrottle::clear($phoneNumber, $ipAddress);

        Security::regenerateSession();
        Security::rotateCsrf();

        $_SESSION['user_id']    = (string) $user['id'];
        $_SESSION['user_name']  = (string) $user['full_name'];
        $_SESSION['user_phone'] = (string) $user['phone_number'];
        $_SESSION['user_role']  = (string) $user['role'];
        $_SESSION['login_at']   = time();

        Logger::info('Login mitra berhasil', [
            'user_id' => (string) $user['id'],
            'ip'      => $ipAddress,
        ]);

        $this->redirect(self::roleLandingPath((string) $user['role']));
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

    private static function lockedMessage(int $remainingSeconds): string
    {
        $minutes = max(1, (int) ceil($remainingSeconds / 60));

        return 'Terlalu banyak percobaan masuk. Coba lagi dalam ' . $minutes . ' menit.';
    }

    private static function post(string $field): string
    {
        $value = $_POST[$field] ?? null;

        return is_string($value) ? trim($value) : '';
    }
}
