<?php

declare(strict_types=1);

namespace AurumFlow\Config;

/**
 * Parser `.env` minimalis tanpa dependensi.
 *
 * Alasan tidak memakai parse_ini_file(): fungsi tersebut menafsirkan
 * karakter khusus (`,`, `"`, `|`, `&`, `~`, `!`, `{`, `}`, `(`, `)`)
 * sebagai sintaks INI sehingga nilai password dapat berubah tanpa
 * disadari. Parser di sini sengaja bodoh dan deterministik.
 *
 * Presedensi: variabel environment proses (getenv) SELALU menang atas
 * isi file `.env`. Ini memungkinkan override per-environment pada shared
 * hosting tanpa perlu menulis atau menyunting file.
 */
final class Env
{
    /** Hanya KEY dalam format ini yang diterima. */
    private const KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var array<string, string> */
    private static array $values = [];

    private static bool $loaded = false;

    private function __construct()
    {
    }

    /**
     * Baca seluruh pasangan KEY=VALUE dari file. Aman dipanggil berkali-kali.
     * File yang tidak ada BUKAN error — deployment yang hanya mengandalkan
     * environment variable tetap sah.
     */
    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;

        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Baris kosong dan komentar penuh dilewati.
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $separator = strpos($line, '=');

            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));

            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                continue;
            }

            self::$values[$key] = self::normalizeValue(trim(substr($line, $separator + 1)));
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $fromProcess = getenv($key);

        // getenv() mengembalikan false bila variabel tidak ada. Nilai string
        // kosong tetap dianggap sebagai nilai yang disengaja.
        if ($fromProcess !== false) {
            return $fromProcess;
        }

        return self::$values[$key] ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        $normalized = strtolower(trim($value));

        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }

    /**
     * Hanya untuk keperluan diagnostik/QC. Tidak pernah memuat kredensial
     * ke halaman web — jangan dipakai di jalur produksi.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::$values;
    }

    /**
     * Buang tanda kutip pembungkus bila ada. Nilai tanpa kutip dibiarkan apa
     * adanya sehingga password yang memuat `#`, `"`, atau spasi tetap utuh.
     */
    private static function normalizeValue(string $value): string
    {
        $length = strlen($value);

        if ($length < 2) {
            return $value;
        }

        $first = $value[0];
        $last = $value[$length - 1];

        if (($first === '"' || $first === "'") && $first === $last) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
