<?php

declare(strict_types=1);

namespace AurumFlow\Helpers;

use AurumFlow\Config\App;

/**
 * Kumpulan helper keamanan: output escaping, password hashing, CSRF,
 * dan hardening session.
 *
 * Prinsip yang dipegang:
 *   - Escape saat OUTPUT, bukan sanitize saat input. Data mentah disimpan
 *     apa adanya di database; hanya lapisan tampilan yang meng-escape.
 *   - `sanitize()` hanya untuk input yang memang tidak boleh memuat markup.
 *   - Semua token memakai sumber acak kriptografis (random_bytes), bukan
 *     rand()/mt_rand() — lihat QC 6.17/6.18.
 *
 * Catatan kompatibilitas: file ini TIDAK memakai extension mbstring agar
 * tetap berjalan pada shared hosting minimal. htmlspecialchars() dengan
 * charset UTF-8 sudah menangani teks multibyte dan emoji dengan benar.
 */
final class Security
{
    /** Nama field hidden pada form. */
    public const CSRF_FIELD = '_token';

    /** Kunci session penyimpan token (publik agar dapat diuji QC 6.13). */
    public const CSRF_SESSION_KEY = '_csrf_token';

    /** Kunci session penyimpan waktu terbit token. */
    public const CSRF_TIME_KEY = '_csrf_time';

    /** Panjang minimum password (kebijakan ringan, didokumentasikan). */
    public const PASSWORD_MIN_LENGTH = 8;

    private function __construct()
    {
    }

    // -----------------------------------------------------------------------
    // XSS / output escaping
    // -----------------------------------------------------------------------

    /**
     * Escape nilai untuk konteks HTML body/atribut. Gunakan untuk SETIAP
     * output dinamis di view: `<?= Security::e($row['name']) ?>`.
     *
     * Menerima `mixed` (bukan hanya string) supaya view tidak pernah
     * memunculkan TypeError saat menampilkan angka atau null.
     */
    public static function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Bersihkan input yang tidak boleh memuat markup sama sekali.
     *
     * Bukan pengganti escaping. Penghapusan karakter kontrol dilakukan per
     * byte dengan rentang yang tidak beririsan dengan byte lanjutan UTF-8,
     * sehingga teks multibyte tetap utuh.
     */
    public static function sanitize(string $value): string
    {
        $value = str_replace("\0", '', $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? $value;
        $value = strip_tags($value);

        return trim($value);
    }

    // -----------------------------------------------------------------------
    // Password hashing
    // -----------------------------------------------------------------------

    public static function hashPassword(string $plain): string
    {
        $hash = password_hash($plain, PASSWORD_DEFAULT);

        if ($hash === false) {
            throw new \RuntimeException('Gagal membuat hash password.');
        }

        return $hash;
    }

    public static function verifyPassword(string $plain, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }

        return password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    /**
     * Kebijakan password minimum: panjang >= 8 dan memuat huruf serta angka.
     * Dipakai database/install.php sebelum membuat akun admin awal.
     */
    public static function passwordStrength(string $plain): bool
    {
        if (strlen($plain) < self::PASSWORD_MIN_LENGTH) {
            return false;
        }

        if (preg_match('/[A-Za-z]/', $plain) !== 1) {
            return false;
        }

        return preg_match('/[0-9]/', $plain) === 1;
    }

    // -----------------------------------------------------------------------
    // CSRF
    // -----------------------------------------------------------------------

    /**
     * Ambil token CSRF session, buat bila belum ada.
     */
    public static function csrfToken(): string
    {
        if (!self::sessionIsActive()) {
            throw new \RuntimeException('CSRF token membutuhkan session aktif.');
        }

        $existing = $_SESSION[self::CSRF_SESSION_KEY] ?? null;

        if (!is_string($existing) || $existing === '') {
            return self::issueCsrfToken();
        }

        return $existing;
    }

    /**
     * Field hidden siap pakai.
     */
    public static function csrfField(): string
    {
        return '<input type="hidden" name="' . self::CSRF_FIELD . '" value="'
            . self::e(self::csrfToken()) . '">';
    }

    /**
     * Validasi token dari request. Perbandingan memakai hash_equals()
     * sehingga aman terhadap timing attack.
     */
    public static function verifyCsrf(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        if (!self::sessionIsActive()) {
            return false;
        }

        $stored = $_SESSION[self::CSRF_SESSION_KEY] ?? null;
        $issuedAt = $_SESSION[self::CSRF_TIME_KEY] ?? null;

        if (!is_string($stored) || $stored === '') {
            return false;
        }

        if (!is_int($issuedAt) || $issuedAt <= 0) {
            return false;
        }

        $lifetime = (int) App::get('session_lifetime', 7200);

        if ($lifetime > 0 && (time() - $issuedAt) > $lifetime) {
            return false;
        }

        return hash_equals($stored, $token);
    }

    /**
     * Buang token lama dan terbitkan yang baru. Dipanggil setelah
     * login/logout untuk mencegah session fixation.
     */
    public static function rotateCsrf(): void
    {
        if (!self::sessionIsActive()) {
            return;
        }

        unset($_SESSION[self::CSRF_SESSION_KEY], $_SESSION[self::CSRF_TIME_KEY]);

        self::issueCsrfToken();
    }

    // -----------------------------------------------------------------------
    // Session
    // -----------------------------------------------------------------------

    /**
     * Buka session dengan atribut cookie yang diperkeras.
     * Harus dipanggil sebelum ada output apa pun dikirim ke browser.
     */
    public static function startSession(): void
    {
        if (self::sessionIsActive()) {
            return;
        }

        $savePath = (string) App::get('session_save_path', '');

        if ($savePath !== '' && is_dir($savePath) && is_writable($savePath)) {
            session_save_path($savePath);
        }

        $name = (string) App::get('session_name', 'AURUMFLOW_SESS');

        if ($name !== '') {
            session_name($name);
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            // Hanya tanda secure bila memang HTTPS; kalau tidak, cookie
            // tidak akan pernah dikirim pada lingkungan development.
            'secure'   => App::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    /**
     * Ganti ID session sambil mempertahankan data. Panggil tepat setelah
     * autentikasi berhasil.
     */
    public static function regenerateSession(): void
    {
        if (self::sessionIsActive()) {
            session_regenerate_id(true);
        }
    }

    /**
     * Hapus seluruh data session dan cookie-nya.
     */
    public static function destroySession(): void
    {
        if (!self::sessionIsActive()) {
            return;
        }

        $_SESSION = [];

        $cookie = session_get_cookie_params();

        if ((bool) ini_get('session.use_cookies')) {
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $cookie['path'] ?? '/',
                'domain'   => $cookie['domain'] ?? '',
                'secure'   => (bool) ($cookie['secure'] ?? false),
                'httponly' => (bool) ($cookie['httponly'] ?? true),
                'samesite' => $cookie['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
        unset($_SESSION);
    }

    // -----------------------------------------------------------------------
    // Utilitas
    // -----------------------------------------------------------------------

    /**
     * Token acak kriptografis (hex). 32 byte = 64 karakter hex.
     */
    public static function randomToken(int $bytes = 32): string
    {
        if ($bytes < 16) {
            throw new \InvalidArgumentException('Panjang token minimal 16 byte.');
        }

        return bin2hex(random_bytes($bytes));
    }

    /**
     * UUID v4 sesuai RFC 4122, cocok untuk primary key CHAR(36).
     *
     * Dipakai untuk seluruh primary key pada skema AurumFlow (ERD § 3.1)
     * supaya nomor urut transaksi tidak dapat ditebak.
     *
     * Catatan: ERD § 5.3 menyebut `bin2hex(random_bytes(16))` sebagai padanan
     * yang kompatibel. Itu tidak akurat — fungsi tersebut menghasilkan 32
     * karakter hex TANPA tanda hubung, sehingga tidak dapat disimpan pada
     * kolom CHAR(36). Karena itu versi ini membentuk UUID yang benar.
     */
    public static function uuidV4(): string
    {
        $bytes = random_bytes(16);

        // Set bit versi (4) dan varian (RFC 4122).
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * Validasi format UUID (versi apa pun) — dipakai script QC dan guard
     * sebelum menyimpan nilai ke kolom CHAR(36).
     */
    public static function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }

    public static function sessionIsActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    // -----------------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------------

    private static function issueCsrfToken(): string
    {
        $token = self::randomToken(32);

        $_SESSION[self::CSRF_SESSION_KEY] = $token;
        $_SESSION[self::CSRF_TIME_KEY] = time();

        return $token;
    }
}
