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

    // -----------------------------------------------------------------------
    // Peran & migrasi aditif (Fase 7)
    // -----------------------------------------------------------------------

    /**
     * Nilai ENUM `users.role` menurut URUTAN — definisi kanonik di kode.
     *
     * Urutan bersifat **append-only**: nilai baru hanya boleh ditambahkan di
     * ujung daftar. MySQL/MariaDB menyimpan nilai ENUM sebagai indeks ordinal,
     * sehingga menyisipkan atau mengurutkan ulang nilai akan memetakan ulang
     * arti baris yang sudah ada.
     *
     * @var list<string>
     */
    public const ROLE_ENUM_VALUES = [
        Constants::ROLE_OPERATOR,
        Constants::ROLE_INVESTOR,
        Constants::ROLE_CASHIER,
        Constants::ROLE_OFFICER,
    ];

    /**
     * DDL literal `users.role` — SATU-SATUNYA tempat daftar nilai ditulis ulang.
     *
     * Urutannya wajib sama dengan `ROLE_ENUM_VALUES` (append-only). Nilainya
     * literal di kode dan **tidak pernah** berasal dari input, sesuai aturan
     * "tidak ada nilai dinamis yang diinterpolasi ke string SQL".
     */
    private const ROLE_ENUM_DDL = "ENUM('operator', 'investor', 'cashier', 'officer')";

    /**
     * Nilai ENUM `users.role` yang benar-benar berlaku di basis data.
     *
     * Dipakai skrip migrasi (`database/migrate.php`), fixture QC, dan assertion
     * agar "apa yang tertulis di schema.sql" dan "apa yang ada di server" tidak
     * pernah berbeda tanpa ketahuan.
     *
     * @return list<string> nilai sesuai urutan `COLUMN_TYPE`
     */
    public static function roleEnumValues(): array
    {
        $row = Database::fetchOne(
            'SELECT `COLUMN_TYPE` AS `column_type` FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column',
            ['table' => 'users', 'column' => 'role']
        );

        if ($row === null) {
            return [];
        }

        return self::parseEnumValues((string) ($row['column_type'] ?? ''));
    }

    /**
     * Apakah ENUM perlu diperluas agar memuat **seluruh** peran resmi?
     *
     * Predikat MURNI (tanpa SQL) supaya skrip migrasi dan QC dapat menguji
     * keputusan ini tanpa menyentuh basis data. Sejak Fase 10 pemeriksaannya
     * digeneralisasi: setiap nilai `ROLE_ENUM_VALUES` yang belum ada membuat
     * ENUM perlu diperluas (dulu hanya nilai `cashier` yang diperiksa).
     */
    public static function needsRoleEnumExtension(string $columnType): bool
    {
        $existing = self::parseEnumValues($columnType);

        foreach (self::ROLE_ENUM_VALUES as $role) {
            if (!in_array($role, $existing, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Perluas `users.role` menjadi empat nilai bila belum (idempoten, aditif).
     *
     * `ALTER` hanya dijalankan bila `COLUMN_TYPE` belum memuat seluruh nilai
     * `ROLE_ENUM_VALUES`, dan pernyataannya **hanya menambah** nilai di ujung
     * daftar: tipe, `NOT NULL`, `DEFAULT`, indeks, serta seluruh baris lama
     * tidak tersentuh.
     *
     * @return bool true = ALTER dijalankan, false = sudah selaras
     *
     * @throws \RuntimeException kolom `users.role` tidak ditemukan
     */
    public static function ensureRoleEnum(): bool
    {
        $row = Database::fetchOne(
            'SELECT `COLUMN_TYPE` AS `column_type` FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column',
            ['table' => 'users', 'column' => 'role']
        );

        if ($row === null) {
            throw new \RuntimeException(
                'Kolom `users`.`role` tidak ditemukan. Jalankan database/install.php lebih dahulu.'
            );
        }

        if (!self::needsRoleEnumExtension((string) ($row['column_type'] ?? ''))) {
            return false;
        }

        // Nilai sengaja ditulis literal (bukan diinterpolasi dari input) dan
        // HANYA ditambahkan di ujung agar tetap metadata in-place.
        Database::query(
            'ALTER TABLE `users` MODIFY COLUMN `role` ' . self::ROLE_ENUM_DDL
            . " NOT NULL DEFAULT 'investor'"
        );

        return true;
    }

    /**
     * Pisahkan nilai dari `COLUMN_TYPE` MySQL, mis. `enum('operator','investor')`.
     *
     * @return list<string>
     */
    private static function parseEnumValues(string $columnType): array
    {
        if (preg_match_all("/'((?:[^']|'')*)'/", $columnType, $matches) < 1) {
            return [];
        }

        $values = [];

        foreach ($matches[1] as $value) {
            $values[] = str_replace("''", "'", $value);
        }

        return $values;
    }

    // -----------------------------------------------------------------------
    // Tata kelola akun kasir (Fase 7)
    // -----------------------------------------------------------------------

    /**
     * Buat pengguna dengan kredensial **PIN ber-pepper** (peran kasir).
     *
     * Berbeda dari `create()` yang memakai kata sandi: PIN di-hash
     * `Security::hashPin()`, sehingga nilainya tidak pernah sah di pintu kata
     * sandi. Waiver clickwrap (`onboarding_signed_at`) sengaja **tidak** diisi —
     * itu artefak mitra pemodal, bukan staf. `APP_PIN_PEPPER` kosong membuat
     * `hashPin()` melempar, sehingga akun tidak pernah tersimpan (fail-closed).
     *
     * @return string id pengguna yang baru dibuat
     */
    public static function createWithPin(
        string $fullName,
        string $phoneNumber,
        string $pin,
        string $role = Constants::ROLE_CASHIER
    ): string {
        $id = Security::uuidV4();

        Database::insert('users', [
            'id'            => $id,
            'full_name'     => $fullName,
            'phone_number'  => $phoneNumber,
            'password_hash' => Security::hashPin($pin),
            'role'          => $role,
            'is_active'     => 1,
        ]);

        return $id;
    }

    /**
     * Ganti PIN pengguna (reset oleh operator) — satu pemilik hashing PIN.
     *
     * @return int jumlah baris terpengaruh (0 = pengguna tidak ditemukan)
     */
    public static function updatePin(string $userId, string $pin): int
    {
        return Database::update(
            'users',
            ['password_hash' => Security::hashPin($pin)],
            '`id` = :id',
            ['id' => $userId]
        );
    }

    /**
     * Aktifkan / nonaktifkan akun.
     *
     * Akun nonaktif tidak lagi ditemukan `findByPhoneNumber()` (yang hanya
     * mencari `is_active = 1`), sehingga tidak dapat masuk lewat pintu mana pun.
     *
     * @return int jumlah baris terpengaruh
     */
    public static function setActive(string $userId, bool $active): int
    {
        return Database::update(
            'users',
            ['is_active' => $active ? 1 : 0],
            '`id` = :id',
            ['id' => $userId]
        );
    }

    /**
     * Daftar pengguna menurut peran (layar "Kelola Kasir").
     *
     * Sengaja TIDAK memilih `password_hash`: hash PIN tidak pernah meninggalkan
     * model ini.
     *
     * @return list<array<string, mixed>>
     */
    public static function listByRole(string $role): array
    {
        return Database::fetchAll(
            'SELECT `id`, `full_name`, `phone_number`, `role`, `is_active`, '
            . '`onboarding_signed_at`, `created_at`, `updated_at` '
            . 'FROM `users` WHERE `role` = :role ORDER BY `full_name` ASC, `id` ASC',
            ['role' => $role]
        );
    }

    /**
     * Cari satu pengguna berdasarkan id **dan** peran sekaligus.
     *
     * Dipakai jalur tata kelola akun kasir: id kiriman formulir tidak boleh
     * cukup untuk menyentuh akun operator/mitra — perannya harus ikut cocok.
     * `password_hash` sengaja tidak dipilih (hash PIN tidak pernah keluar dari
     * model ini).
     *
     * @return array<string, mixed>|null
     */
    public static function findByIdAndRole(string $userId, string $role): ?array
    {
        return Database::fetchOne(
            'SELECT `id`, `full_name`, `phone_number`, `role`, `is_active`, '
            . '`created_at`, `updated_at` FROM `users` '
            . 'WHERE `id` = :id AND `role` = :role LIMIT 1',
            ['id' => $userId, 'role' => $role]
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
