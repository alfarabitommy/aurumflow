<?php

declare(strict_types=1);

namespace AurumFlow\Helpers;

use AurumFlow\Config\App;

/**
 * Pembatas percobaan login berbasis berkas (Fase 5).
 *
 * Mengapa ada: PIN mitra hanya enam digit (10⁶ kemungkinan), dan kata sandi
 * operator pun dapat ditebak berulang. Tanpa pembatas, satu skrip di internet
 * dapat mencoba ribuan kombinasi per menit.
 *
 * Mengapa berbasis berkas, bukan tabel baru: skema ERD adalah sumber kebenaran
 * dan fase ini **nol DDL** (lihat `AGENTS.md`). Berkas JSON kecil di
 * `storage/throttle/` — di luar document root — memberi ketahanan lintas sesi
 * dan lintas proses tanpa menambah tabel.
 *
 * Aturan yang dibekukan:
 *   - 5 kegagalan dalam 15 menit → kunci 15 menit untuk kombinasi yang sama.
 *   - Kunci dihitung per `sha256(nomor telepon|IP)`, sehingga satu penyerang
 *     tidak dapat mengunci akun orang lain dari IP berbeda, dan sebaliknya
 *     seorang pengguna yang salah ketik tidak mengunci dirinya di perangkat
 *     yang berbeda.
 *   - Login sukses menghapus entri.
 *   - Entri berumur > 24 jam dibersihkan (prune oportunistik).
 *
 * Penurunan mutu: bila `storage/throttle/` tidak dapat ditulis (hosting
 * read-only), pembatas **tidak** boleh menggagalkan login pengguna sah —
 * pencatatan jatuh ke session (masih berlaku per peramban) dan satu peringatan
 * ditulis ke log. Ini diuji QC (`qc_fase_5` grup C).
 */
final class PinThrottle
{
    /** Jumlah kegagalan yang memicu penguncian. */
    public const MAX_FAILS = 5;

    /** Jendela hitung kegagalan (detik). */
    public const WINDOW_SECONDS = 900;

    /** Lama penguncian setelah ambang terlampaui (detik). */
    public const LOCK_SECONDS = 900;

    /** Umur maksimum berkas sebelum dibersihkan (detik). */
    public const MAX_AGE_SECONDS = 86400;

    /** Kunci session untuk mode penurunan mutu. */
    private const SESSION_KEY = '_pin_throttle';

    /** Prefiks nama berkas — memudahkan pembersihan & pemindaian. */
    public const FILE_PREFIX = 'af-login-';

    /** @var string|null direktori uji (QC) yang menggantikan storage/throttle */
    private static ?string $directoryOverride = null;

    private function __construct()
    {
    }

    // -----------------------------------------------------------------------
    // API utama
    // -----------------------------------------------------------------------

    /**
     * Sisa detik penguncian untuk kombinasi ini (0 = boleh mencoba).
     */
    public static function check(string $phoneNumber, string $ipAddress): int
    {
        $entry = self::read($phoneNumber, $ipAddress);

        if ($entry === null) {
            return 0;
        }

        $remaining = (int) ($entry['locked_until'] ?? 0) - time();

        return $remaining > 0 ? $remaining : 0;
    }

    /**
     * Catat satu kegagalan; kembalikan sisa detik kunci setelah pencatatan
     * (0 = masih boleh mencoba, > 0 = terkunci).
     */
    public static function recordFailure(string $phoneNumber, string $ipAddress): int
    {
        $entry = self::read($phoneNumber, $ipAddress) ?? [
            'fails'        => 0,
            'first_at'     => time(),
            'locked_until' => 0,
        ];

        $now = time();

        // Jendela kedaluwarsa → mulai hitungan baru.
        if ((int) ($entry['locked_until'] ?? 0) > $now) {
            return (int) $entry['locked_until'] - $now;
        }

        if ($now - (int) ($entry['first_at'] ?? $now) > self::WINDOW_SECONDS) {
            $entry = ['fails' => 0, 'first_at' => $now, 'locked_until' => 0];
        }

        $entry['fails'] = (int) ($entry['fails'] ?? 0) + 1;

        if ($entry['fails'] >= self::MAX_FAILS) {
            $entry['locked_until'] = $now + self::LOCK_SECONDS;
            $entry['fails'] = self::MAX_FAILS;
        }

        self::write($phoneNumber, $ipAddress, $entry);

        $remaining = (int) $entry['locked_until'] - $now;

        return $remaining > 0 ? $remaining : 0;
    }

    /**
     * Hapus entri (dipanggil setelah login berhasil).
     */
    public static function clear(string $phoneNumber, string $ipAddress): void
    {
        $path = self::path($phoneNumber, $ipAddress);

        if ($path !== null && is_file($path)) {
            @unlink($path);
        }

        self::forgetSessionEntry($phoneNumber, $ipAddress);
    }

    /**
     * Buang berkas yang sudah tua. Dipanggil sesekali (bukan setiap request)
     * agar tidak menjadi beban I/O pada hosting kecil.
     */
    public static function prune(): int
    {
        $directory = self::directory();
        $removed = 0;

        if ($directory === null) {
            return 0;
        }

        $files = glob($directory . DIRECTORY_SEPARATOR . self::FILE_PREFIX . '*.json');

        if ($files === false) {
            return 0;
        }

        $cutoff = time() - self::MAX_AGE_SECONDS;

        foreach ($files as $file) {
            $mtime = @filemtime($file);

            if ($mtime !== false && $mtime < $cutoff && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    // -----------------------------------------------------------------------
    // Diagnostics & QC
    // -----------------------------------------------------------------------

    /**
     * Direktori penyimpanan efektif (null = tidak tersedia).
     */
    public static function directory(): ?string
    {
        if (self::$directoryOverride !== null) {
            return self::$directoryOverride;
        }

        $base = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'throttle';

        return $base;
    }

    /**
     * Arahkan penyimpanan ke direktori lain — **hanya untuk pengujian**.
     */
    public static function useDirectoryForTesting(?string $directory): void
    {
        self::$directoryOverride = $directory;
    }

    /**
     * Hapus seluruh berkas pembatas (fixture QC).
     */
    public static function clearAll(): int
    {
        $directory = self::directory();
        $removed = 0;

        if ($directory === null) {
            return 0;
        }

        $files = glob($directory . DIRECTORY_SEPARATOR . self::FILE_PREFIX . '*.json');

        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Jumlah berkas pembatas yang ada (QC).
     */
    public static function count(): int
    {
        $directory = self::directory();

        if ($directory === null) {
            return 0;
        }

        $files = glob($directory . DIRECTORY_SEPARATOR . self::FILE_PREFIX . '*.json');

        return $files === false ? 0 : count($files);
    }

    /**
     * Berapa kali sebuah kombinasi dicoba gagal (0 bila tidak ada entri).
     */
    public static function failureCount(string $phoneNumber, string $ipAddress): int
    {
        $entry = self::read($phoneNumber, $ipAddress);

        return $entry === null ? 0 : (int) ($entry['fails'] ?? 0);
    }

    // -----------------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------------

    /**
     * Kunci entri: hash dari nomor + IP. Nomor tidak pernah tertulis ke disk
     * dalam bentuk terbuka — berkas pembatas bukan tempat data pribadi.
     */
    private static function entryName(string $phoneNumber, string $ipAddress): string
    {
        return self::FILE_PREFIX . hash('sha256', trim($phoneNumber) . '|' . trim($ipAddress)) . '.json';
    }

    private static function path(string $phoneNumber, string $ipAddress): ?string
    {
        $directory = self::directory();

        if ($directory === null) {
            return null;
        }

        return $directory . DIRECTORY_SEPARATOR . self::entryName($phoneNumber, $ipAddress);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function read(string $phoneNumber, string $ipAddress): ?array
    {
        $path = self::path($phoneNumber, $ipAddress);

        if ($path === null || !is_file($path)) {
            return self::sessionEntry($phoneNumber, $ipAddress);
        }

        $raw = @file_get_contents($path);

        if ($raw === false || $raw === '') {
            return self::sessionEntry($phoneNumber, $ipAddress);
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : self::sessionEntry($phoneNumber, $ipAddress);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function write(string $phoneNumber, string $ipAddress, array $entry): void
    {
        // 1 dari 20 permintaan melakukan pembersihan berkas tua.
        if (random_int(1, 20) === 1) {
            self::prune();
        }

        $directory = self::directory();
        $path = self::path($phoneNumber, $ipAddress);

        if ($directory === null || $path === null) {
            self::writeSessionEntry($phoneNumber, $ipAddress, $entry);

            return;
        }

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            self::warnOnce('Direktori pembatas tidak dapat dibuat: ' . $directory);
            self::writeSessionEntry($phoneNumber, $ipAddress, $entry);

            return;
        }

        $payload = json_encode($entry, JSON_THROW_ON_ERROR);

        if (@file_put_contents($path, $payload, LOCK_EX) === false) {
            self::warnOnce('Berkas pembatas tidak dapat ditulis: ' . $path);
            self::writeSessionEntry($phoneNumber, $ipAddress, $entry);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function sessionEntry(string $phoneNumber, string $ipAddress): ?array
    {
        if (!Security::sessionIsActive()) {
            return null;
        }

        $all = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_array($all)) {
            return null;
        }

        $entry = $all[self::entryName($phoneNumber, $ipAddress)] ?? null;

        return is_array($entry) ? $entry : null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function writeSessionEntry(string $phoneNumber, string $ipAddress, array $entry): void
    {
        if (!Security::sessionIsActive()) {
            return;
        }

        if (!is_array($_SESSION[self::SESSION_KEY] ?? null)) {
            $_SESSION[self::SESSION_KEY] = [];
        }

        $_SESSION[self::SESSION_KEY][self::entryName($phoneNumber, $ipAddress)] = $entry;
    }

    private static function forgetSessionEntry(string $phoneNumber, string $ipAddress): void
    {
        if (!Security::sessionIsActive() || !is_array($_SESSION[self::SESSION_KEY] ?? null)) {
            return;
        }

        unset($_SESSION[self::SESSION_KEY][self::entryName($phoneNumber, $ipAddress)]);
    }

    /**
     * Satu peringatan per proses — log tidak boleh dibanjiri percobaan login.
     */
    private static function warnOnce(string $message): void
    {
        static $warned = false;

        if ($warned) {
            return;
        }

        $warned = true;

        Logger::warning('Pembatas percobaan login turun mutu ke session', [
            'alasan' => $message,
            'app'    => App::name(),
        ]);
    }
}
