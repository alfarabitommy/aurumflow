<?php

declare(strict_types=1);

namespace AurumFlow\Controllers;

use AurumFlow\Helpers\Logger;
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

        if ($user === null || !User::verifyPassword($user, $password)) {
            Logger::warning('Percobaan login gagal', [
                'phone_number' => $phoneNumber,
                'ip'           => User::clientIp(),
            ]);

            // Pesan generik: tidak membedakan akun tidak ada vs password salah.
            $this->renderLoginFailure('Nomor telepon atau password salah.', $phoneNumber, 401);

            return;
        }

        // Anti session fixation: ganti ID session lalu terbitkan token CSRF baru.
        Security::regenerateSession();
        Security::rotateCsrf();

        $_SESSION['user_id']       = (string) $user['id'];
        $_SESSION['user_name']     = (string) $user['full_name'];
        $_SESSION['user_phone']    = (string) $user['phone_number'];
        $_SESSION['user_role']     = (string) $user['role'];
        $_SESSION['login_at']      = time();

        Logger::info('Login berhasil', [
            'user_id' => (string) $user['id'],
            'role'    => (string) $user['role'],
            'ip'      => User::clientIp(),
        ]);

        $this->redirect('/dashboard');
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

        if ($userId !== '') {
            Logger::info('Logout', ['user_id' => $userId]);
        }

        Security::destroySession();

        $this->redirect('/login');
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
