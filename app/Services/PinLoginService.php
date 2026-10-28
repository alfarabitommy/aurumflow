<?php

declare(strict_types=1);

namespace AurumFlow\Services;

use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Logger;
use AurumFlow\Helpers\PinThrottle;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\User;

/**
 * Satu pemilik aturan pintu PIN (Fase 7).
 *
 * Dua permukaan masuk memakai PIN 6 digit — `/investor/login` (mitra pemodal,
 * Fase 5) dan `/cashier/login` (kasir meja bakar, Fase 7). Aturan yang menjaga
 * keduanya **tidak boleh berbeda**, karena setiap perbedaan adalah celah:
 * pembatas percobaan, bentuk PIN, gerbang peran, pesan generik, dan jejak log.
 * Karena itu semuanya hidup di sini, dan controller pintu hanya merender atau
 * mengalihkan berdasarkan hasil `attempt()`.
 *
 * Yang **tidak** dilakukan kelas ini: menulis session (itu milik
 * `BaseController::startUserSession()`), menyimpan PIN, dan memutuskan tampilan.
 *
 * Kenapa PIN tidak pernah sah di pintu kata sandi: `User::verifyPin()` adalah
 * verifikasi PIN ber-pepper (`Security::verifyPin()`), sedangkan pintu kata
 * sandi memakai `User::verifyPassword()`. Dua pintu, dua kredensial — sehingga
 * pembatas percobaan tidak dapat dilewati lewat pintu lain.
 */
final class PinLoginService
{
    /** Kredensial sah untuk peran yang diminta. */
    public const OK = 'ok';

    /** Sedang terkunci `PinThrottle` (atau baru saja mencapai ambang). */
    public const LOCKED = 'locked';

    /** Isian kosong atau bentuk PIN salah — belum menyentuh basis data. */
    public const FORMAT = 'format';

    /** Nomor tidak dikenal, peran tidak cocok, atau PIN salah. */
    public const INVALID = 'invalid';

    /** Label audiens untuk pesan log — nilai enum mentah tidak pernah dicetak. */
    private const AUDIENCE_LABELS = [
        Constants::ROLE_INVESTOR => 'mitra',
        Constants::ROLE_CASHIER  => 'kasir',
        Constants::ROLE_OFFICER  => 'petugas lapangan',
    ];

    private function __construct()
    {
    }

    /**
     * Coba masuk lewat pintu PIN.
     *
     * Urutan langkah **tidak boleh diubah**: (1) isian kosong, (2) pembatas
     * percobaan, (3) bentuk PIN, (4) verifikasi + gerbang peran. Pemeriksaan
     * pembatas lebih dahulu membuat akun yang terkunci tidak dapat dipakai
     * mengukur apa pun (termasuk bentuk PIN yang sah).
     *
     * @param array<string, mixed> $credential kunci: `phone_number`, `pin`
     * @param string $expectedRole peran yang boleh masuk lewat pintu ini
     * @return array{outcome: string, user: array<string, mixed>|null, message: string,
     *                http_status: int, remaining_seconds: int, phone_number: string}
     */
    public static function attempt(array $credential, string $expectedRole, string $ipAddress): array
    {
        $phoneNumber = self::stringValue($credential['phone_number'] ?? null);
        $pin = self::stringValue($credential['pin'] ?? null);

        if ($phoneNumber === '' || $pin === '') {
            return self::result(self::FORMAT, 'Nomor telepon dan PIN wajib diisi.', 422, $phoneNumber);
        }

        $lockedSeconds = PinThrottle::check($phoneNumber, $ipAddress);

        if ($lockedSeconds > 0) {
            return self::result(self::LOCKED, self::lockedMessage($lockedSeconds), 429, $phoneNumber, $lockedSeconds);
        }

        if (preg_match('/^[0-9]{' . Security::PIN_LENGTH . '}$/', $pin) !== 1) {
            return self::result(
                self::FORMAT,
                'PIN harus tepat ' . Security::PIN_LENGTH . ' angka.',
                422,
                $phoneNumber
            );
        }

        $user = User::findByPhoneNumber($phoneNumber);

        if ($user === null
            || (string) ($user['role'] ?? '') !== $expectedRole
            || !User::verifyPin($user, $pin)
        ) {
            $lockedSeconds = PinThrottle::recordFailure($phoneNumber, $ipAddress);

            Logger::warning('Percobaan login gagal', [
                'pintu'        => $expectedRole,
                'audiens'      => self::AUDIENCE_LABELS[$expectedRole] ?? $expectedRole,
                'phone_number' => $phoneNumber,
                'ip'           => $ipAddress,
            ]);

            if ($lockedSeconds > 0) {
                return self::result(
                    self::LOCKED,
                    self::lockedMessage($lockedSeconds),
                    429,
                    $phoneNumber,
                    $lockedSeconds
                );
            }

            // Pesan generik: tidak membedakan akun tidak ada, peran tidak cocok,
            // atau PIN salah (mencegah enumerasi akun & peran).
            return self::result(self::INVALID, 'Nomor telepon atau PIN salah.', 401, $phoneNumber);
        }

        PinThrottle::clear($phoneNumber, $ipAddress);

        Logger::info('Login PIN berhasil', [
            'pintu'   => $expectedRole,
            'user_id' => (string) ($user['id'] ?? ''),
            'ip'      => $ipAddress,
        ]);

        return self::result(self::OK, '', 200, $phoneNumber, 0, $user);
    }

    /**
     * Pesan ketika percobaan masih dikunci `PinThrottle`.
     *
     * Menyebut sisa menit agar pengguna tahu harus menunggu berapa lama, tetapi
     * tidak pernah mengungkap apakah nomornya terdaftar.
     */
    public static function lockedMessage(int $remainingSeconds): string
    {
        $minutes = max(1, (int) ceil($remainingSeconds / 60));

        return 'Terlalu banyak percobaan masuk. Coba lagi dalam ' . $minutes . ' menit.';
    }

    /**
     * @param array<string, mixed>|null $user
     * @return array{outcome: string, user: array<string, mixed>|null, message: string,
     *                http_status: int, remaining_seconds: int, phone_number: string}
     */
    private static function result(
        string $outcome,
        string $message,
        int $httpStatus,
        string $phoneNumber,
        int $remainingSeconds = 0,
        ?array $user = null
    ): array {
        return [
            'outcome'          => $outcome,
            'user'             => $user,
            'message'          => $message,
            'http_status'      => $httpStatus,
            'remaining_seconds' => $remainingSeconds,
            'phone_number'     => $phoneNumber,
        ];
    }

    private static function stringValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
