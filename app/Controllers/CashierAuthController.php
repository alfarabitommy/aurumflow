<?php

declare(strict_types=1);

namespace AurumFlow\Controllers;

use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\User;
use AurumFlow\Services\PinLoginService;

/**
 * Pintu masuk kasir meja bakar: nomor telepon + PIN 6 digit (Fase 7).
 *
 * Mengapa ada pintu ketiga, bukan menumpang `/investor/login`:
 *   - **Satu kredensial, satu pintu.** Peran yang diterima berbeda, pesan
 *     bantuan berbeda, dan berandanya berbeda (`/operator/teller`, bukan
 *     dasbor mitra). Menyatukannya membuat gerbang peran dan pengalihan
 *     menjadi kabur — persis di tempat yang paling tidak boleh kabur.
 *   - **Kecepatan operasional.** Kasir bekerja satu tangan di samping
 *     timbangan; halaman ini hanya memuat nomor telepon, PIN, dan papan angka.
 *
 * Seluruh **aturan** PIN (bentuk, pembatas percobaan, gerbang peran, pesan
 * generik) hidup di `PinLoginService` — sama dengan pintu mitra, sehingga kedua
 * pintu tidak dapat berbeda pendapat. PIN kasir disimpan ber-pepper pada kolom
 * `users.password_hash` yang sudah ada, sehingga ia **tidak pernah** sah di
 * pintu kata sandi.
 */
final class CashierAuthController extends BaseController
{
    private const VIEW = 'cashier/login';

    private const LAYOUT = 'auth';

    /**
     * Form PIN kasir (GET).
     */
    public function showLogin(): void
    {
        $this->requireGuest();

        $this->renderLogin(null, '', 200);
    }

    /**
     * Proses masuk kasir (POST).
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
            Constants::ROLE_CASHIER,
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

        // Beranda kasir adalah meja kasir — satu-satunya permukaan yang menerima
        // perannya, sehingga rantai pengalihan tidak pernah berputar.
        $this->redirect(self::roleLandingPath(Constants::ROLE_CASHIER));
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
            'title'        => 'Masuk Meja Kasir',
            'error'        => $error,
            'phone_number' => $phoneNumber,
            'pin_length'   => Security::PIN_LENGTH,
        ], self::LAYOUT);
    }
}
