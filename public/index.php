<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AurumFlow — Front Controller
|--------------------------------------------------------------------------
| Satu-satunya pintu masuk request web.
|
| Alur:
|   bootstrap -> security header -> session -> resolusi path -> cocokkan
|   route -> (mutasi: verifikasi CSRF) -> dispatch ke controller -> render
|
| Semua kegagalan berakhir di BaseController::abort() sehingga pengguna
| selalu menerima halaman HTML, bukan stack trace.
*/

use AurumFlow\Controllers\BaseController;
use AurumFlow\Helpers\Security;

require dirname(__DIR__) . '/app/Config/bootstrap.php';

// ---------------------------------------------------------------------------
// Security header dasar
// ---------------------------------------------------------------------------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------
Security::startSession();

// ---------------------------------------------------------------------------
// Resolusi method + path
// ---------------------------------------------------------------------------
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$rawPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($rawPath) && $rawPath !== '' ? rawurldecode($rawPath) : '/';

// Tolak percobaan path traversal dan byte null sebelum apa pun dijalankan.
if (str_contains($path, '..') || str_contains($path, "\0")) {
    BaseController::abort(400, 'Permintaan tidak valid', 'Path request tidak dapat diproses.');
}

$path = str_replace('\\', '/', $path);
$path = preg_replace('#/{2,}#', '/', $path) ?? '/';

// Dukung pemasangan di subdirektori (mis. https://domain.com/aurumflow/).
$scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');

if ($scriptDir !== '' && str_starts_with($path, $scriptDir)) {
    $path = substr($path, strlen($scriptDir));
}

if ($path !== '/') {
    $path = rtrim($path, '/');

    if ($path === '') {
        $path = '/';
    }
}

// ---------------------------------------------------------------------------
// Pencocokan route
// ---------------------------------------------------------------------------
/** @var array<string, array<string, array{0: class-string, 1: string}>> $routes */
$routes = require APP_PATH . '/routes.php';

$handler = $routes[$method][$path] ?? null;

if ($handler === null) {
    BaseController::abort(
        404,
        'Halaman tidak ditemukan',
        'Alamat yang Anda tuju tidak tersedia. Periksa kembali tautan Anda.'
    );
}

// ---------------------------------------------------------------------------
// Proteksi CSRF untuk seluruh method yang mengubah state
// ---------------------------------------------------------------------------
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    $submitted = $_POST[Security::CSRF_FIELD] ?? null;

    if (!is_string($submitted) || !Security::verifyCsrf($submitted)) {
        BaseController::abort(
            419,
            'Sesi kedaluwarsa',
            'Token keamanan tidak valid atau sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.'
        );
    }
}

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------
[$controllerClass, $action] = $handler;

if (!class_exists($controllerClass)
    || !is_subclass_of($controllerClass, BaseController::class)
    || !method_exists($controllerClass, $action)
) {
    BaseController::abort(
        500,
        'Konfigurasi route tidak valid',
        'Route "' . $method . ' ' . $path . '" menunjuk ke controller yang tidak dapat dipanggil.'
    );
}

/** @var BaseController $controller */
$controller = new $controllerClass();
$controller->{$action}();
