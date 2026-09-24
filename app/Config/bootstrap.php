<?php

declare(strict_types=1);

namespace AurumFlow\Config;

use AurumFlow\Controllers\BaseController;
use AurumFlow\Helpers\Logger;

/*
|--------------------------------------------------------------------------
| AurumFlow — bootstrap
|--------------------------------------------------------------------------
| Titik inisialisasi tunggal untuk seluruh entry point (public/index.php,
| script CLI di tests/, database/install.php).
|
| Urutan di file ini SENGAJA tidak boleh diubah:
|   1. konstanta path         — dibutuhkan autoloader
|   2. autoloader             — dibutuhkan untuk memuat class apa pun
|   3. Env::load               — dibutuhkan App::boot()
|   4. App::boot()             — validasi environment + set timezone
|   5. error/exception handler — dipasang paling akhir agar kegagalan pada
|                                langkah 1-4 tetap terlihat jelas
|
| Session TIDAK dimulai di sini. Session dibuka eksplisit oleh front
| controller (atau oleh script QC) supaya perilakunya dapat dikontrol.
*/

// ---------------------------------------------------------------------------
// 1. Konstanta path
// ---------------------------------------------------------------------------
defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));
defined('APP_PATH') || define('APP_PATH', BASE_PATH . '/app');
defined('PUBLIC_PATH') || define('PUBLIC_PATH', BASE_PATH . '/public');
defined('STORAGE_PATH') || define('STORAGE_PATH', BASE_PATH . '/storage');
defined('LOG_PATH') || define('LOG_PATH', STORAGE_PATH . '/logs');
defined('VIEW_PATH') || define('VIEW_PATH', APP_PATH . '/Views');
defined('DATABASE_PATH') || define('DATABASE_PATH', BASE_PATH . '/database');
defined('TEST_PATH') || define('TEST_PATH', BASE_PATH . '/tests');

// ---------------------------------------------------------------------------
// 2. Autoloader manual (namespace AurumFlow\ -> app/)
// ---------------------------------------------------------------------------
spl_autoload_register(static function (string $class): void {
    $prefix = 'AurumFlow\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));

    // Tolak nama class yang memuat karakter aneh sebelum menyentuh filesystem.
    if ($relative === '' || preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative) !== 1) {
        return;
    }

    $candidate = APP_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
    $resolved = realpath($candidate);
    $root = realpath(APP_PATH);

    // Pertahanan path traversal: file hasil resolusi WAJIB berada di app/.
    if ($resolved === false || $root === false) {
        return;
    }

    if (!str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
        return;
    }

    require $resolved;
});

// ---------------------------------------------------------------------------
// 3. Muat konfigurasi environment
// ---------------------------------------------------------------------------
Env::load(BASE_PATH . '/.env');

// ---------------------------------------------------------------------------
// 4. Boot aplikasi
// ---------------------------------------------------------------------------
App::boot();

error_reporting(E_ALL);
ini_set('display_errors', App::debug() ? '1' : '0');
ini_set('display_startup_errors', App::debug() ? '1' : '0');
ini_set('log_errors', '1');

if (is_dir(LOG_PATH) && is_writable(LOG_PATH)) {
    ini_set('error_log', LOG_PATH . '/php-' . date('Y-m-d') . '.log');
}

// ---------------------------------------------------------------------------
// 5. Penanganan error
// ---------------------------------------------------------------------------

/**
 * Warning/notice/deprecated diubah menjadi ErrorException agar tidak ada
 * masalah yang lolos secara diam-diam. Kode yang benar-benar ingin
 * menekan error wajib memakai operator `@` (handler ini menghormatinya).
 */
set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    throw new \ErrorException($message, 0, $severity, $file, $line);
});

/**
 * Exception yang tidak tertangkap: selalu dicatat ke storage/logs, lalu
 * pengguna hanya melihat halaman generik ketika APP_DEBUG=false.
 */
set_exception_handler(static function (\Throwable $exception): void {
    Logger::error('Uncaught exception: ' . $exception->getMessage(), [
        'type'  => $exception::class,
        'file'  => $exception->getFile(),
        'line'  => $exception->getLine(),
        'trace' => $exception->getTraceAsString(),
    ]);

    if (App::isCli()) {
        fwrite(STDERR, PHP_EOL . '[AurumFlow] UNCAUGHT ' . $exception::class . ': '
            . $exception->getMessage() . PHP_EOL
            . $exception->getFile() . ':' . $exception->getLine() . PHP_EOL
            . $exception->getTraceAsString() . PHP_EOL);
        exit(1);
    }

    BaseController::abort(
        500,
        'Terjadi kesalahan',
        App::debug()
            ? $exception::class . ': ' . $exception->getMessage()
            : 'Permintaan tidak dapat diproses. Silakan coba lagi atau hubungi administrator.'
    );
});

/**
 * Fatal error (E_ERROR/E_PARSE) tidak melewati exception handler, jadi
 * ditangkap pada saat shutdown agar tetap tercatat.
 */
register_shutdown_function(static function (): void {
    $error = error_get_last();

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    if ($error === null || !in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    Logger::critical('Fatal error: ' . $error['message'], [
        'type' => $error['type'],
        'file' => $error['file'],
        'line' => $error['line'],
    ]);

    if (!App::isCli() && !headers_sent()) {
        http_response_code(500);
    }
});
