<?php

declare(strict_types=1);

namespace AurumFlow\Config;

/**
 * Konfigurasi aplikasi yang sudah di-resolve dari environment.
 *
 * Seluruh nilai dibaca SEKALI saat boot lalu dibekukan. Tidak ada
 * credential yang ditulis di kode — semuanya berasal dari `.env` atau
 * variabel environment proses.
 */
final class App
{
    /** Versi PHP minimum yang didukung arsitektur ini. */
    public const MIN_PHP_VERSION_ID = 80200;

    private static bool $booted = false;

    /** @var array<string, mixed> */
    private static array $config = [];

    private function __construct()
    {
    }

    /**
     * Resolve konfigurasi, validasi environment, dan set timezone proses.
     * Idempotent: pemanggilan kedua tidak melakukan apa-apa.
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        if (PHP_VERSION_ID < self::MIN_PHP_VERSION_ID) {
            self::abort(
                'AurumFlow membutuhkan PHP 8.2 atau lebih baru. '
                . 'Versi yang terdeteksi: ' . PHP_VERSION
                . ' (PHP_VERSION_ID=' . PHP_VERSION_ID . ').'
            );
        }

        $timezone = self::readString('APP_TIMEZONE', 'Asia/Jakarta');

        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            self::abort('Nilai APP_TIMEZONE tidak dikenal: "' . $timezone . '".');
        }

        self::$config = [
            'name'              => self::readString('APP_NAME', 'AurumFlow'),
            'env'               => self::readString('APP_ENV', 'production'),
            'debug'             => Env::bool('APP_DEBUG', false),
            'url'               => rtrim(self::readString('APP_URL', ''), '/'),
            'timezone'          => $timezone,
            'session_name'      => self::readString('SESSION_NAME', 'AURUMFLOW_SESS'),
            'session_lifetime'  => Env::int('SESSION_LIFETIME', 7200),
            'session_save_path' => self::readString('SESSION_SAVE_PATH', ''),
            // Kunci rahasia PIN mitra (Fase 5). Sengaja boleh kosong di sini:
            // yang menolak adalah lapisan yang MEMBUAT PIN (`Security::hashPin()`),
            // bukan boot aplikasi — supaya operator tetap bisa masuk dan
            // memperbaiki konfigurasi, bukan terkunci di luar sistem.
            'pin_pepper'        => self::readString('APP_PIN_PEPPER', ''),
        ];

        date_default_timezone_set($timezone);

        self::$booted = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$config[$key] ?? $default;
    }

    public static function name(): string
    {
        return (string) self::get('name', 'AurumFlow');
    }

    public static function env(): string
    {
        return (string) self::get('env', 'production');
    }

    public static function isProduction(): bool
    {
        return self::env() === 'production';
    }

    public static function debug(): bool
    {
        return (bool) self::get('debug', false);
    }

    public static function baseUrl(): string
    {
        return (string) self::get('url', '');
    }

    public static function timezone(): string
    {
        return (string) self::get('timezone', 'Asia/Jakarta');
    }

    /**
     * Offset UTC timezone aplikasi dalam format MySQL, contoh `+07:00`.
     * Dipakai database/Config agar jam PHP dan jam MySQL tidak pernah
     * bergeser (lihat risiko R-8 pada dokumen rencana).
     */
    public static function utcOffset(): string
    {
        $zone = new \DateTimeZone(self::timezone());

        return (new \DateTimeImmutable('now', $zone))->format('P');
    }

    public static function isCli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }

    /**
     * URL internal yang sadar-subbdirektori, untuk aset maupun route.
     *
     * Tanpa ini, pemasangan pada https://domain.com/aurumflow/ akan memuat
     * CSS/JS dan mengirim form ke root domain, lalu gagal senyap.
     */
    public static function url(string $path = ''): string
    {
        $path = ltrim($path, '/');

        if (self::isCli()) {
            return '/' . $path;
        }

        $scriptDir = rtrim(
            str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))),
            '/'
        );

        return $scriptDir . '/' . $path;
    }

    /**
     * URL absolut untuk dikirim keluar aplikasi (Fase 5: tautan undangan mitra
     * yang disalin operator ke WhatsApp).
     *
     * Memakai `APP_URL` bila diisi. Bila kosong, host diambil dari request
     * sebagai upaya terakhir — cukup untuk lingkungan pengembangan, sementara
     * produksi dianjurkan mengisi `APP_URL` supaya tautan tidak bergantung pada
     * header `Host` (yang dapat dipalsukan).
     */
    public static function absoluteUrl(string $path = ''): string
    {
        $relative = self::url($path);

        if (self::isCli()) {
            return $relative;
        }

        $base = self::baseUrl();

        if ($base === '') {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

            if (preg_match('/^[A-Za-z0-9.\-]+(?::\d{1,5})?$/', $host) !== 1) {
                return $relative;
            }

            $base = (self::isHttps() ? 'https://' : 'http://') . $host;
        }

        return rtrim($base, '/') . $relative;
    }

    public static function isHttps(): bool
    {
        if (self::isCli()) {
            return false;
        }

        $https = $_SERVER['HTTPS'] ?? null;

        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    /**
     * Baca nilai string dari env, fallback ke default bila kosong.
     */
    private static function readString(string $key, string $default): string
    {
        $value = Env::get($key);

        if ($value === null) {
            return $default;
        }

        return $value;
    }

    /**
     * Hentikan proses sebelum autoloader/handler siap. Dipakai hanya untuk
     * kegagalan environment yang fatal dan tidak dapat dipulihkan.
     */
    private static function abort(string $message): void
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, '[AurumFlow] FATAL: ' . $message . PHP_EOL);
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }

        echo '[AurumFlow] FATAL: ' . $message;
        exit(1);
    }
}
