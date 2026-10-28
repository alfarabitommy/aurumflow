<?php

declare(strict_types=1);

namespace AurumFlow\Controllers;

use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Logger;
use AurumFlow\Helpers\PinThrottle;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\User;

/**
 * Autentikasi berbasis session.
 *
 * Sesuai ERD, identitas login adalah `phone_number`. Aturan yang dipegang:
 *   - Pesan kegagalan login SELALU generik. Jangan pernah memberi tahu
 *     apakah nomor telepon atau password yang salah (mencegah enumerasi akun).
 *   - Session ID diregenerasi setelah login berhasil (anti session fixation).
 *   - Logout HANYA melalui POST + token CSRF.
 */
final class AuthController extends BaseController
{
    public function showLogin(): void
    {
        $this->requireGuest();

        $this->render('auth/login', [
            'title'        => 'Masuk',
            'error'        => null,
            'phone_number' => '',
        ], 'auth');
    }

    public function login(): void
    {
        $this->requireGuest();

        // Pemeriksaan kedua di lapisan controller (pertahanan berlapis).
        // Front controller sudah memeriksa ini sebelum dispatch; pemeriksaan
        // di sini menjaga controller tetap aman bila dipanggil dari jalur
        // lain di masa depan.
        $token = $_POST[Security::CSRF_FIELD] ?? null;

        if (!is_string($token) || !Security::verifyCsrf($token)) {
            self::abort(
                419,
                'Sesi kedaluwarsa',
                'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.'
            );
        }

        $phoneNumber = isset($_POST['phone_number']) && is_string($_POST['phone_number'])
            ? trim($_POST['phone_number'])
            : '';

        $password = isset($_POST['password']) && is_string($_POST['password'])
            ? $_POST['password']
            : '';

        if ($phoneNumber === '' || $password === '') {
            $this->renderLoginFailure('Nomor telepon dan password wajib diisi.', $phoneNumber, 422);

            return;
        }

        $user = User::findByPhoneNumber($phoneNumber);

        $ipAddress = User::clientIp() ?? '';
        $lockedSeconds = PinThrottle::check($phoneNumber, $ipAddress);

        if ($lockedSeconds > 0) {
            $this->renderLoginFailure(self::lockedMessage($lockedSeconds, true), $phoneNumber, 429);

            return;
        }

        // Doktrin "satu kredensial, satu pintu": akun kasir & mitra lapangan
        // memakai PIN ber-pepper (Fase 7 & Fase 10), sehingga hash-nya tidak
        // akan pernah cocok di sini. Penolakan eksplisit menjaga pintu kata
        // sandi tetap bukan pintu belakang ke akun mereka bila kelak seseorang
        // menuliskan kata sandi pada baris itu.
        if ($user === null
            || in_array((string) $user['role'], [Constants::ROLE_CASHIER, Constants::ROLE_OFFICER], true)
            || !User::verifyPassword($user, $password)
        ) {
            $lockedSeconds = PinThrottle::recordFailure($phoneNumber, $ipAddress);

            Logger::warning('Percobaan login gagal', [
                'phone_number' => $phoneNumber,
                'ip'           => User::clientIp(),
            ]);

            if ($lockedSeconds > 0) {
                $this->renderLoginFailure(self::lockedMessage($lockedSeconds, false), $phoneNumber, 429);

                return;
            }

            // Pesan generik: tidak membedakan akun tidak ada vs password salah.
            $this->renderLoginFailure('Nomor telepon atau password salah.', $phoneNumber, 401);

            return;
        }

        PinThrottle::clear($phoneNumber, $ipAddress);

        // Anti session fixation + lima kunci session: satu pemilik
        // (`BaseController::startUserSession()`), dipakai ketiga pintu masuk.
        $this->startUserSession($user);

        Logger::info('Login berhasil', [
            'user_id' => (string) $user['id'],
            'role'    => (string) $user['role'],
            'ip'      => User::clientIp(),
        ]);

        $this->redirect(self::roleLandingPath((string) $user['role']));
    }

    public function logout(): void
    {
        $token = $_POST[Security::CSRF_FIELD] ?? null;

        if (!is_string($token) || !Security::verifyCsrf($token)) {
            self::abort(
                419,
                'Sesi kedaluwarsa',
                'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.'
            );
        }

        $userId = isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : '';
        $role = isset($_SESSION['user_role']) ? (string) $_SESSION['user_role'] : '';

        if ($userId !== '') {
            Logger::info('Logout', ['user_id' => $userId]);
        }

        Security::destroySession();

        // Setiap peran kembali ke pintunya sendiri (kata sandi, PIN mitra, PIN
        // kasir) memakai pemetaan peran yang sama dengan requireRole().
        $this->redirect(self::roleLoginPath($role));
    }

    /**
     * Pesan ketika percobaan login sedang dikunci `PinThrottle`.
     *
     * Memakai sisa detik agar pengguna tahu harus menunggu berapa lama, tetapi
     * tidak pernah menyebut apakah nomornya terdaftar.
     */
    private static function lockedMessage(int $remainingSeconds, bool $fromStart): string
    {
        $minutes = max(1, (int) ceil($remainingSeconds / 60));

        return $fromStart
            ? 'Terlalu banyak percobaan masuk. Coba lagi dalam ' . $minutes . ' menit.'
            : 'Terlalu banyak percobaan masuk. Akun dikunci sementara — coba lagi dalam '
                . $minutes . ' menit.';
    }

    /**
     * Tampilkan ulang form login beserta pesan kesalahan.
     */
    private function renderLoginFailure(string $message, string $phoneNumber, int $status): void
    {
        if (!headers_sent()) {
            http_response_code($status);
        }

        $this->render('auth/login', [
            'title'        => 'Masuk',
            'error'        => $message,
            'phone_number' => $phoneNumber,
        ], 'auth');
    }
}
