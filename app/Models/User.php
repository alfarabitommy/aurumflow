<?php

declare(strict_types=1);

namespace AurumFlow\Models;

use AurumFlow\Config\Constants;
use AurumFlow\Config\Database;
use AurumFlow\Helpers\Security;

/**
 * Model tabel `users`.
 *
 * Sesuai ERD, identitas login adalah `phone_number` (bukan username/email)
 * dan primary key berupa UUID v4 (`CHAR(36)`).
 *
 * Seluruh akses SQL dibungkus di sini supaya controller tidak pernah
 * menyentuh query.
 */
final class User
{
    private function __construct()
    {
    }

    /**
     * Cari pengguna berdasarkan nomor telepon.
     *
     * Nilai `$phoneNumber` SELALU dikirim sebagai named parameter — ini titik
     * yang diuji oleh QC 2.1/2.4 (payload injeksi harus menjadi literal).
     *
     * @param bool $onlyActive true (default) untuk jalur autentikasi; false
     *                         dipakai database/install.php agar akun yang
     *                         dinonaktifkan tetap dapat ditemukan dan
     *                         dipulihkan tanpa membuat duplikat.
     * @return array<string, mixed>|null
     */
    public static function findByPhoneNumber(string $phoneNumber, bool $onlyActive = true): ?array
    {
        $sql = 'SELECT `id`, `full_name`, `phone_number`, `password_hash`, `role`, '
            . '`is_active`, `onboarding_signed_at` '
            . 'FROM `users` WHERE `phone_number` = :phone_number';

        if ($onlyActive) {
            $sql .= ' AND `is_active` = 1';
        }

        return Database::fetchOne($sql . ' LIMIT 1', ['phone_number' => $phoneNumber]);
    }

    /**
     * Verifikasi password dan upgrade hash bila algoritma default berubah.
     *
     * @param array<string, mixed> $user
     */
    public static function verifyPassword(array $user, string $plain): bool
    {
        $hash = (string) ($user['password_hash'] ?? '');

        if ($hash === '') {
            return false;
        }

        if (!Security::verifyPassword($plain, $hash)) {
            return false;
        }

        if (Security::needsRehash($hash)) {
            self::updatePasswordHash((string) $user['id'], $plain);
        }

        return true;
    }

    public static function updatePasswordHash(string $userId, string $plain): void
    {
        Database::update(
            'users',
            ['password_hash' => Security::hashPassword($plain)],
            '`id` = :id',
            ['id' => $userId]
        );
    }

    /**
     * Buat pengguna baru dengan UUID v4 sebagai primary key.
     *
     * @return string id pengguna yang baru dibuat
     */
    public static function create(
        string $fullName,
        string $phoneNumber,
        string $plainPassword,
        string $role = Constants::ROLE_INVESTOR
    ): string {
        $id = Security::uuidV4();

        Database::insert('users', [
            'id'            => $id,
            'full_name'     => $fullName,
            'phone_number'  => $phoneNumber,
            'password_hash' => Security::hashPassword($plainPassword),
            'role'          => $role,
            'is_active'     => 1,
        ]);

        return $id;
    }

    /**
     * Jumlah pengguna aktif — dipakai ringkasan dashboard.
     */
    public static function countActive(): int
    {
        $row = Database::fetchOne('SELECT COUNT(*) AS `total` FROM `users` WHERE `is_active` = 1');

        return (int) ($row['total'] ?? 0);
    }

    /**
     * IP klien untuk kolom audit `signed_ip_address`.
     *
     * SENGAJA hanya memakai REMOTE_ADDR. Header X-Forwarded-For tidak
     * dipercaya karena mudah dipalsukan dan tidak ada reverse proxy yang
     * dijamin pada shared hosting.
     */
    public static function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $ip;
    }
}
