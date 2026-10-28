<?php

declare(strict_types=1);

namespace AurumFlow\Controllers;

use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\User;
use AurumFlow\Services\PinLoginService;

/**
 * Pintu masuk mitra lapangan: nomor telepon + PIN 6 digit (Fase 10).
 *
 * Mengapa ada pintu keempat, bukan menumpang pintu lain:
 *   - **Satu kredensial, satu pintu.** Peran yang diterima berbeda, pesan
 *     bantuan berbeda, dan berandanya berbeda (`/officer/dashboard` — dasbor
 *     blind yang hanya memuat saldo hak petugas itu sendiri).
 *   - **Isolasi data.** Petugas tidak boleh melihat satu pun permukaan
 *     pengelola, mitra pemodal, maupun meja kasir.
 *
 * Seluruh **aturan** PIN (bentuk, pembatas percobaan, gerbang peran, pesan
 * generik) hidup di `PinLoginService` — sama dengan pintu mitra & kasir,
 * sehingga ketiga pintu tidak dapat berbeda pendapat. PIN disimpan ber-pepper
 * pada kolom `users.password_hash`, sehingga ia **tidak pernah** sah di pintu
 * kata sandi.
 */
final class OfficerAuthController extends BaseController
{
    private const VIEW = 'officer/login';

    private const LAYOUT = 'auth';

    /**
     * Form PIN petugas (GET).
     */
    public function showLogin(): void
    {
        $this->requireGuest();

        $this->renderLogin(null, '', 200);
    }

    /**
     * Proses masuk petugas (POST).
     */
    public function login(): void
    {
        $this->requireGuest();

        // Pemeriksaan kedua di lapisan controller (pertahanan berlapis):
        // front controller sudah memeriksa CSRF sebelum dispatch.
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
            Constants::ROLE_OFFICER,
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

        // Beranda petugas adalah dasbor blind — satu-satunya permukaan yang
        // menerima perannya, sehingga rantai pengalihan tidak pernah berputar.
        $this->redirect(self::roleLandingPath(Constants::ROLE_OFFICER));
    }

    /**
     * Render form PIN beserta pesan kesalahan (bila ada).
     *
     * Nomor telepon dikembalikan agar tidak perlu diketik ulang; PIN tidak
     * pernah dikembalikan — bahkan tidak dalam bentuk field terisi.
     */
    private function renderLogin(?string $error, string $phoneNumber, int $status): void
    {
        if ($status !== 200 && !headers_sent()) {
            http_response_code($status);
        }

        $this->render(self::VIEW, [
            'title'        => 'Masuk Mitra Lapangan',
            'error'        => $error,
            'phone_number' => $phoneNumber,
            'pin_length'   => Security::PIN_LENGTH,
        ], self::LAYOUT);
    }
}
