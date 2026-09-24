<?php

declare(strict_types=1);

namespace AurumFlow\Helpers;

use AurumFlow\Config\App;
use AurumFlow\Config\Constants;

/**
 * Satu-satunya sumber kebenaran untuk tampilan angka pada AurumFlow.
 *
 * Semua view WAJIB memakai helper ini; dilarang memanggil number_format()
 * langsung agar format tidak pernah menyimpang antar halaman.
 *
 * Konvensi yang dibekukan (lihat Open Items O-3/O-4 pada dokumen rencana):
 *   - Rupiah  : `Rp 28.937.500` — pemisah ribuan '.', desimal ',',
 *               prefix `Rp `, default 0 desimal.
 *               Nilai negatif ditulis `-Rp 5.000` (tanda di depan prefix).
 *   - Gramatur: `14,850 gr` — selalu 3 desimal, pemisah ribuan '.',
 *               desimal ','. Nilai negatif `-2,500 gr`.
 *   - Pembulatan: half away from zero (perilaku round() bawaan PHP),
 *     setara half-up untuk nilai positif. Relevan untuk berat emas.
 *
 * Implementasi sengaja TIDAK memakai setlocale()/localeconv() karena
 * environment shared hosting tidak dapat dijamin (QC 3.4).
 *
 * Catatan round-trip (QC 3.2/3.3): parse(format(x)) === x hanya dijamin
 * bila x memang dapat direpresentasikan pada jumlah desimal yang diminta.
 * Contoh: rupiah(1234.5) menghasilkan "Rp 1.235" sehingga parse-nya
 * mengembalikan 1235.0 — selisih itu memang disengaja, bukan bug.
 */
final class Formatter
{
    public const MONEY_PREFIX = 'Rp ';
    public const GRAM_SUFFIX = ' ' . Constants::GRAM_UNIT;

    public const DEFAULT_MONEY_DECIMALS = Constants::MONEY_DECIMALS;
    public const DEFAULT_GRAM_DECIMALS = Constants::GRAM_DECIMALS;

    private const MAX_DECIMALS = 6;

    private function __construct()
    {
    }

    // -----------------------------------------------------------------------
    // Rupiah
    // -----------------------------------------------------------------------

    /**
     * Format nilai menjadi Rupiah, contoh `Rp 28.937.500`.
     */
    public static function rupiah(mixed $value, int $decimals = self::DEFAULT_MONEY_DECIMALS): string
    {
        $number = self::toFloat($value);
        $decimals = self::clampDecimals($decimals);

        $sign = $number < 0 ? '-' : '';

        return $sign . self::MONEY_PREFIX
            . number_format(abs($number), $decimals, ',', '.');
    }

    /**
     * Kebalikan rupiah(): terima `Rp 28.937.500` maupun `1.234,50`.
     */
    public static function parseRupiah(mixed $value): float
    {
        return self::toFloat($value);
    }

    // -----------------------------------------------------------------------
    // Gramatur
    // -----------------------------------------------------------------------

    /**
     * Format nilai menjadi gramatur 3 desimal, contoh `14,850 gr`.
     */
    public static function gram(mixed $value, int $decimals = self::DEFAULT_GRAM_DECIMALS): string
    {
        $number = self::toFloat($value);
        $decimals = self::clampDecimals($decimals);

        $sign = $number < 0 ? '-' : '';

        return $sign
            . number_format(abs($number), $decimals, ',', '.')
            . self::GRAM_SUFFIX;
    }

    /**
     * Kebalikan gram(): terima `14,850 gr` maupun `1.000,000 gr`.
     */
    public static function parseGram(mixed $value): float
    {
        return self::toFloat($value);
    }

    // -----------------------------------------------------------------------
    // Tanggal
    // -----------------------------------------------------------------------

    /**
     * Format tanggal gaya Indonesia. Selalu memakai timezone aplikasi.
     */
    public static function tanggalId(string $datetime, bool $withTime = false): string
    {
        $trimmed = trim($datetime);

        if ($trimmed === '') {
            return '-';
        }

        try {
            $date = new \DateTimeImmutable($trimmed, new \DateTimeZone(App::timezone()));
        } catch (\Exception) {
            return '-';
        }

        return $date->format($withTime ? 'd/m/Y H:i' : 'd/m/Y');
    }

    // -----------------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------------

    /**
     * Normalisasi input menjadi float.
     *
     * String numerik polos (`"1234.5"`) diperlakukan apa adanya. Format
     * Indonesia (`"Rp 28.937.500"`, `"14,850 gr"`) baru diurai khusus,
     * sehingga tidak ada ambiguitas titik sebagai desimal atau ribuan.
     */
    private static function toFloat(mixed $value): float
    {
        if ($value === null) {
            return 0.0;
        }

        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return 0.0;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return 0.0;
        }

        if (is_numeric($trimmed)) {
            return (float) $trimmed;
        }

        return self::parseLocaleNumber($trimmed);
    }

    private static function parseLocaleNumber(string $value): float
    {
        $digits = preg_replace('/[^0-9,.\-]/', '', $value) ?? '';

        $negative = str_contains($digits, '-');
        $digits = str_replace('-', '', $digits);

        if (str_contains($digits, ',')) {
            // Ada koma -> koma adalah pemisah desimal, titik adalah ribuan.
            $digits = str_replace('.', '', $digits);
            $digits = str_replace(',', '.', $digits);
        } else {
            // Tanpa koma -> titik selalu pemisah ribuan pada format kita.
            $digits = str_replace('.', '', $digits);
        }

        if ($digits === '' || !is_numeric($digits)) {
            return 0.0;
        }

        $number = (float) $digits;

        return $negative ? -$number : $number;
    }

    private static function clampDecimals(int $decimals): int
    {
        if ($decimals < 0) {
            return 0;
        }

        if ($decimals > self::MAX_DECIMALS) {
            return self::MAX_DECIMALS;
        }

        return $decimals;
    }
}
