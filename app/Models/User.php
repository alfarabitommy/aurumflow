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

    /**
     * Verifikasi PIN 6 digit mitra (Fase 5).
     *
     * PIN disimpan **ber-pepper** (`Security::hashPin()`), sehingga nilai ini
     * tidak pernah sah di jalur `verifyPassword()` — itulah alasan keberadaan
     * dua method terpisah: satu kredensial, satu pintu.
     *
     * Sengaja TANPA rehash otomatis: PIN tidak pernah di-hash ulang dari teks
     * aslinya di jalur login (tidak ada alasan menyimpan teksnya lebih lama).
     *
     * @param array<string, mixed> $user
     */
    public static function verifyPin(array $user, string $pin): bool
    {
        $hash = (string) ($user['password_hash'] ?? '');

        if ($hash === '') {
            return false;
        }

        return Security::verifyPin($pin, $hash);
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

    // -----------------------------------------------------------------------
    // Undangan & onboarding (Fase 5)
    // -----------------------------------------------------------------------

    /**
     * Cari calon mitra lewat token undangan (halaman `/join`).
     *
     * Mengembalikan seluruh kolom yang dibutuhkan Layar A — dan **sekaligus**
     * `password_hash` supaya pemanggil dapat memastikan akun ini belum pernah
     * onboarding (hash berisi token acak yang tidak diungkapkan).
     *
     * @return array<string, mixed>|null
     */
    public static function findByInviteToken(string $token): ?array
    {
        return Database::fetchOne(
            'SELECT `id`, `full_name`, `phone_number`, `password_hash`, `role`, `is_active`, '
            . '`invite_token`, `onboarding_signed_at` FROM `users` '
            . 'WHERE `invite_token` = :invite_token LIMIT 1',
            ['invite_token' => $token]
        );
    }

    /**
     * Baca satu baris undangan SAMBIL MENGUNCI (klaim token).
     *
     * WAJIB di dalam `Database::transaction()`: dua peramban yang membuka
     * tautan yang sama pada saat bersamaan tidak boleh sama-sama menuliskan
     * PIN — yang kedua menemukan token sudah hangus.
     *
     * @return array<string, mixed>|null
     */
    public static function lockByInviteTokenForUpdate(string $token): ?array
    {
        return Database::fetchOne(
            'SELECT `id`, `full_name`, `phone_number`, `password_hash`, `role`, `is_active`, '
            . '`invite_token`, `onboarding_signed_at` FROM `users` '
            . 'WHERE `invite_token` = :invite_token LIMIT 1 FOR UPDATE',
            ['invite_token' => $token]
        );
    }

    /**
     * Terbitkan (atau putar ulang) token undangan seorang mitra.
     *
     * Panjang 64 heksadesimal = `Security::randomToken(32)`, pas dengan kolom
     * `invite_token VARCHAR(64)`. Pemanggilan kedua **mematikan** tautan lama
     * tanpa menghapus jejak audit — lihat `plan/Plan_Fase_5_AurumFlow.md` A-8.
     *
     * @return string token baru (yang dikirim ke mitra lewat WhatsApp)
     */
    public static function issueInviteToken(string $userId): string
    {
        $token = Security::randomToken(32);

        Database::update('users', ['invite_token' => $token], '`id` = :id', ['id' => $userId]);

        return $token;
    }

    /**
     * Tutup onboarding: PIN + jejak audit + token hangus, dalam satu UPDATE.
     *
     * Dipanggil `OnboardingService` **di dalam** transaksinya, sehingga
     * kegagalan apa pun setelah baris ini menggulung seluruhnya (token tetap
     * hidup, akun tetap belum dapat dipakai).
     *
     * Waktu memakai jam peladen (WIB) dan IP hanya dari `REMOTE_ADDR`
     * (`clientIp()`) — tidak ada satu pun nilai audit yang berasal dari form.
     */
    public static function completeOnboarding(
        string $userId,
        string $pin,
        ?string $ipAddress,
        ?string $userAgent
    ): void {
        Database::update(
            'users',
            [
                'password_hash'        => Security::hashPin($pin),
                'invite_token'         => null,
                'onboarding_signed_at' => date('Y-m-d H:i:s'),
                'signed_ip_address'    => $ipAddress,
                'signed_user_agent'    => $userAgent,
            ],
            '`id` = :id',
            ['id' => $userId]
        );
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

    /** Batas panjang jejak `signed_user_agent` yang disimpan (kolom TEXT). */
    public const MAX_USER_AGENT_LENGTH = 512;

    /**
     * User-Agent peramban untuk kolom audit `signed_user_agent`.
     *
     * Nilainya diambil apa adanya dari header dan HANYA dipotong panjangnya:
     * ia bukti audit, bukan input yang diproses — dan selalu dirender lewat
     * `Security::e()` bila kelak ditampilkan. Method ini hidup di sini
     * (bersama `clientIp()`) supaya seluruh pembacaan header audit punya satu
     * pemilik.
     */
    public static function userAgent(): ?string
    {
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (!is_string($agent)) {
            return null;
        }

        $agent = trim($agent);

        if ($agent === '') {
            return null;
        }

        // Pemotongan per byte: `mbstring` tidak tersedia dan field ini hanya
        // arsip — batas keras lebih penting daripada keutuhan karakter terakhir.
        return substr($agent, 0, self::MAX_USER_AGENT_LENGTH);
    }
}
