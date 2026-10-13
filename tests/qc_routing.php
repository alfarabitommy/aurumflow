<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| QC-5 — Routing, Session Auth, dan Hardening
|--------------------------------------------------------------------------
| Seluruh test dijalankan lewat HTTP sungguhan terhadap server bawaan PHP
| dengan router yang meniru public/.htaccess (lihat tests/server_router.php).
|
| Catatan kejujuran pengujian: aturan `.htaccess` pada Apache TIDAK diuji di
| sini — yang diuji adalah perilaku aplikasi terhadap request tersebut
| (403/404 + tidak ada kebocoran isi file). Lapisan Apache diverifikasi
| secara manual pada checklist serah terima.
*/

require __DIR__ . '/bootstrap.php';

use AurumFlow\Config\Env;

Qc::suite('5 — Routing & Session Auth (qc_routing.php)');

$credentials = QcFixture::ensureInstalled();
$sessionName = Env::get('SESSION_NAME') ?? 'AURUMFLOW_SESS';

/**
 * Baca nilai cookie session dari cookie jar (format Netscape).
 * Baris HttpOnly diawali `#HttpOnly_` sehingga prefix itu harus dilepas
 * lebih dulu sebelum baris komentar lain diabaikan.
 */
$sessionFromJar = static function (string $jar) use ($sessionName): ?string {
    if (!is_file($jar)) {
        return null;
    }

    $lines = file($jar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        return null;
    }

    foreach ($lines as $line) {
        $line = preg_replace('/^#HttpOnly_/', '', $line) ?? $line;

        if (str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode("\t", $line);

        if (count($parts) >= 7 && $parts[5] === $sessionName) {
            return $parts[6];
        }
    }

    return null;
};

QcHttp::start();

Qc::note('Server QC berjalan pada ' . QcHttp::baseUrl());

// ---------------------------------------------------------------------------
Qc::section('Akses tanpa autentikasi');

// 5.1
$root = QcHttp::request('GET', '/', ['cookie_jar' => QcHttp::cookieJar('anon'), 'fresh' => true]);

Qc::ok(
    '5.1',
    'GET / tanpa login diarahkan ke /login (302)',
    $root['status'] === 302 && $root['location'] === '/login',
    'status=' . $root['status'] . ' location=' . var_export($root['location'], true)
);

// 5.2
$loginPage = QcHttp::request('GET', '/login', ['cookie_jar' => QcHttp::cookieJar('anon')]);
$anonToken = QcHttp::csrfFrom($loginPage['body']);

Qc::ok(
    '5.2',
    'GET /login mengembalikan 200 dan memuat field CSRF',
    $loginPage['status'] === 200 && $anonToken !== null,
    'status=' . $loginPage['status'] . ' token=' . var_export($anonToken, true)
);

Qc::ok(
    '5.2b',
    'Halaman login memuat form POST ke /login dengan field phone_number',
    str_contains($loginPage['body'], 'method="post"')
        && str_contains($loginPage['body'], 'action="/login"')
        && str_contains($loginPage['body'], 'name="phone_number"'),
    'form login tidak sesuai ERD (field phone_number tidak ditemukan)'
);

// 5.3
$dashboardAnon = QcHttp::request('GET', '/dashboard', ['cookie_jar' => QcHttp::cookieJar('anon')]);

Qc::ok(
    '5.3',
    'GET /dashboard tanpa login diarahkan ke /login (302)',
    $dashboardAnon['status'] === 302 && $dashboardAnon['location'] === '/login',
    'status=' . $dashboardAnon['status'] . ' location=' . var_export($dashboardAnon['location'], true)
);

// 5.4
$notFound = QcHttp::request('GET', '/route-yang-tidak-ada', ['cookie_jar' => QcHttp::cookieJar('anon')]);

Qc::ok(
    '5.4',
    'Route tidak dikenal mengembalikan 404 tanpa stack trace',
    $notFound['status'] === 404
        && stripos($notFound['body'], 'Stack trace') === false
        && stripos($notFound['body'], 'Fatal error') === false
        && stripos($notFound['body'], '/home/') === false,
    'status=' . $notFound['status']
);

// ---------------------------------------------------------------------------
Qc::section('Proteksi CSRF');

// 5.6
$noCsrfJar = QcHttp::cookieJar('no-csrf');
QcHttp::request('GET', '/login', ['cookie_jar' => $noCsrfJar, 'fresh' => true]);

$noCsrf = QcHttp::request('POST', '/login', [
    'cookie_jar' => $noCsrfJar,
    'data'       => ['phone_number' => $credentials['phone'], 'password' => $credentials['password']],
]);

Qc::ok(
    '5.6',
    'POST /login tanpa token CSRF ditolak (419/403)',
    in_array($noCsrf['status'], [419, 403], true)
        && !str_contains((string) $noCsrf['location'], '/dashboard'),
    'status=' . $noCsrf['status']
);

// 5.7 — token dari session lain
$jarA = QcHttp::cookieJar('csrf-a');
$jarB = QcHttp::cookieJar('csrf-b');

$pageA = QcHttp::request('GET', '/login', ['cookie_jar' => $jarA, 'fresh' => true]);
$tokenA = QcHttp::csrfFrom($pageA['body']);

$pageB = QcHttp::request('GET', '/login', ['cookie_jar' => $jarB, 'fresh' => true]);
$tokenB = QcHttp::csrfFrom($pageB['body']);

Qc::ok(
    '5.7b',
    'Dua session berbeda menerima token CSRF yang berbeda',
    $tokenA !== null && $tokenB !== null && $tokenA !== $tokenB
);

$crossSession = QcHttp::request('POST', '/login', [
    'cookie_jar' => $jarB,
    'data'       => [
        'phone_number' => $credentials['phone'],
        'password'     => $credentials['password'],
        '_token'       => (string) $tokenA,
    ],
]);

Qc::ok(
    '5.7',
    'POST /login dengan token CSRF milik session lain ditolak',
    in_array($crossSession['status'], [419, 403], true)
        && !str_contains((string) $crossSession['location'], '/dashboard'),
    'status=' . $crossSession['status']
);

// ---------------------------------------------------------------------------
Qc::section('Login dan session');

// 5.5
$authJar = QcHttp::cookieJar('auth');
$loginForm = QcHttp::request('GET', '/login', ['cookie_jar' => $authJar, 'fresh' => true]);
$loginToken = QcHttp::csrfFrom($loginForm['body']);
$sessionBefore = $sessionFromJar($authJar);

$login = QcHttp::request('POST', '/login', [
    'cookie_jar' => $authJar,
    'data'       => [
        'phone_number' => $credentials['phone'],
        'password'     => $credentials['password'],
        '_token'       => (string) $loginToken,
    ],
]);

$sessionAfter = $sessionFromJar($authJar);

Qc::ok(
    '5.5',
    'POST /login dengan kredensial valid diarahkan ke /dashboard (302)',
    $login['status'] === 302 && $login['location'] === '/dashboard',
    'status=' . $login['status'] . ' location=' . var_export($login['location'], true)
);

// 5.8
Qc::ok(
    '5.8',
    'Session ID diregenerasi setelah login berhasil',
    $sessionBefore !== null && $sessionAfter !== null && $sessionBefore !== $sessionAfter,
    'sebelum=' . var_export($sessionBefore, true) . ' sesudah=' . var_export($sessionAfter, true)
);

// Login dengan password salah harus gagal dengan pesan generik.
$wrongJar = QcHttp::cookieJar('wrong-password');
$wrongForm = QcHttp::request('GET', '/login', ['cookie_jar' => $wrongJar, 'fresh' => true]);
$wrongToken = QcHttp::csrfFrom($wrongForm['body']);

$wrongLogin = QcHttp::request('POST', '/login', [
    'cookie_jar' => $wrongJar,
    'data'       => [
        'phone_number' => $credentials['phone'],
        'password'     => 'password-salah',
        '_token'       => (string) $wrongToken,
    ],
]);

Qc::ok(
    '5.5b',
    'POST /login dengan password salah gagal tanpa mengungkap penyebabnya',
    in_array($wrongLogin['status'], [401, 422], true)
        && str_contains($wrongLogin['body'], 'Nomor telepon atau password salah')
        && !str_contains($wrongLogin['body'], 'password-salah'),
    'status=' . $wrongLogin['status']
);

// 5.9
$dashboard = QcHttp::request('GET', '/dashboard', ['cookie_jar' => $authJar]);

Qc::ok(
    '5.9',
    'GET /dashboard dengan session login mengembalikan 200',
    $dashboard['status'] === 200,
    'status=' . $dashboard['status']
);

Qc::ok(
    '5.9b',
    'Dashboard menampilkan nomor telepon operator yang login',
    str_contains($dashboard['body'], $credentials['phone']),
    'body tidak memuat nomor ' . $credentials['phone']
);

Qc::ok(
    '5.9c',
    'Dashboard menampilkan peran operator dari kolom users.role',
    str_contains($dashboard['body'], 'Pengelola'),
    'cuplikan: ' . substr(strip_tags($dashboard['body']), 0, 300)
);

Qc::ok(
    '5.9d',
    'Dashboard membuktikan formatter dan pembacaan system_settings bekerja',
    str_contains($dashboard['body'], 'Rp 28.937.500')
        && str_contains($dashboard['body'], '14,850 gr')
        && str_contains($dashboard['body'], 'waterfall')
        && str_contains($dashboard['body'], '50.00'),
    'cuplikan: ' . substr(strip_tags($dashboard['body']), 0, 400)
);

// 5.11 — GET /logout tidak boleh mengubah state
$logoutViaGet = QcHttp::request('GET', '/logout', ['cookie_jar' => $authJar]);
$stillLoggedIn = QcHttp::request('GET', '/dashboard', ['cookie_jar' => $authJar]);

Qc::ok(
    '5.11',
    'GET /logout tidak menghapus session (mutasi hanya via POST)',
    $logoutViaGet['status'] === 404 && $stillLoggedIn['status'] === 200,
    'status logout=' . $logoutViaGet['status'] . ' status dashboard=' . $stillLoggedIn['status']
);

// 5.10 — logout yang sah
$logoutToken = QcHttp::csrfFrom($dashboard['body']);

$logout = QcHttp::request('POST', '/logout', [
    'cookie_jar' => $authJar,
    'data'       => ['_token' => (string) $logoutToken],
]);

$afterLogout = QcHttp::request('GET', '/dashboard', ['cookie_jar' => $authJar]);

Qc::ok(
    '5.10',
    'POST /logout menghapus session dan mengarahkan ke /login',
    $logout['status'] === 302
        && $logout['location'] === '/login'
        && $afterLogout['status'] === 302
        && $afterLogout['location'] === '/login',
    'logout=' . $logout['status'] . '/' . var_export($logout['location'], true)
    . ' dashboard=' . $afterLogout['status'] . '/' . var_export($afterLogout['location'], true)
);

// ---------------------------------------------------------------------------
Qc::section('Atribut cookie dan header keamanan');

// 5.12
$cookieHeader = QcHttp::headerValue($loginForm['headers'], 'Set-Cookie') ?? '';

Qc::ok(
    '5.12',
    'Cookie session memuat HttpOnly',
    stripos($cookieHeader, 'HttpOnly') !== false,
    'Set-Cookie=' . $cookieHeader
);

Qc::ok(
    '5.12b',
    'Cookie session memuat SameSite=Lax',
    stripos($cookieHeader, 'SameSite=Lax') !== false,
    'Set-Cookie=' . $cookieHeader
);

// 5.15
Qc::ok(
    '5.15',
    'Response memuat X-Content-Type-Options: nosniff',
    stripos((string) QcHttp::headerValue($loginPage['headers'], 'X-Content-Type-Options'), 'nosniff') !== false,
    $loginPage['headers']
);

Qc::ok(
    '5.15b',
    'Response memuat X-Frame-Options',
    QcHttp::headerValue($loginPage['headers'], 'X-Frame-Options') !== null,
    $loginPage['headers']
);

Qc::ok(
    '5.15c',
    'Response memuat Referrer-Policy',
    QcHttp::headerValue($loginPage['headers'], 'Referrer-Policy') !== null,
    $loginPage['headers']
);

// ---------------------------------------------------------------------------
Qc::section('Perlindungan file sensitif');

// 5.13
$envRequest = QcHttp::request('GET', '/.env', ['cookie_jar' => QcHttp::cookieJar('anon')]);

Qc::ok(
    '5.13',
    'GET /.env ditolak dan isinya tidak bocor',
    in_array($envRequest['status'], [403, 404], true)
        && !str_contains($envRequest['body'], 'DB_PASS')
        && !str_contains($envRequest['body'], 'OPERATOR_PASSWORD'),
    'status=' . $envRequest['status'] . ' body=' . substr($envRequest['body'], 0, 200)
);

$hidden = QcHttp::request('GET', '/.gitignore', ['cookie_jar' => QcHttp::cookieJar('anon')]);

Qc::ok(
    '5.13b',
    'GET /.gitignore ditolak',
    in_array($hidden['status'], [403, 404], true),
    'status=' . $hidden['status']
);

// 5.14
$traversal = QcHttp::request('GET', '/../app/Config/Database.php', ['cookie_jar' => QcHttp::cookieJar('anon')]);

Qc::ok(
    '5.14',
    'Path traversal ke app/Config/Database.php ditolak',
    in_array($traversal['status'], [403, 404], true)
        && !str_contains($traversal['body'], 'class Database')
        && !str_contains($traversal['body'], 'PDO::ATTR_ERRMODE'),
    'status=' . $traversal['status'] . ' body=' . substr($traversal['body'], 0, 200)
);

$encodedTraversal = QcHttp::request('GET', '/%2e%2e/app/Config/Database.php', ['cookie_jar' => QcHttp::cookieJar('anon')]);

Qc::ok(
    '5.14b',
    'Path traversal ter-encode (%2e%2e) ditolak',
    in_array($encodedTraversal['status'], [400, 403, 404], true),
    'status=' . $encodedTraversal['status']
);

$installPhp = QcHttp::request('GET', '/database/install.php', ['cookie_jar' => QcHttp::cookieJar('anon')]);

Qc::ok(
    '5.14c',
    'database/install.php tidak dapat dijangkau dari document root public/',
    in_array($installPhp['status'], [403, 404], true)
        && !str_contains($installPhp['body'], 'INSTALL SELESAI'),
    'status=' . $installPhp['status'] . ' body=' . substr($installPhp['body'], 0, 200)
);

$logs = QcHttp::request('GET', '/logs/app-' . date('Y-m-d') . '.log', ['cookie_jar' => QcHttp::cookieJar('anon')]);

Qc::ok(
    '5.14d',
    'Direktori log tidak dapat diakses lewat web (bukan 200)',
    $logs['status'] !== 200,
    'status=' . $logs['status']
);

// ---------------------------------------------------------------------------
Qc::section('Aset statis');

// Sejak Fase 2 aset CSS adalah hasil kompilasi Tailwind CLI (`app.min.css`)
// dan Alpine.js dikirim sebagai aset lokal (tanpa CDN) — lihat rencana § 3.1.
$css = QcHttp::request('GET', '/assets/css/app.min.css', ['cookie_jar' => QcHttp::cookieJar('anon')]);
$js = QcHttp::request('GET', '/assets/js/app.js', ['cookie_jar' => QcHttp::cookieJar('anon')]);
$alpine = QcHttp::request('GET', '/assets/js/alpine.min.js', ['cookie_jar' => QcHttp::cookieJar('anon')]);
$legacyCss = QcHttp::request('GET', '/assets/css/app.css', ['cookie_jar' => QcHttp::cookieJar('anon')]);

Qc::ok(
    '5.16',
    'Aset statis disajikan langsung oleh web server (200)',
    $css['status'] === 200 && str_contains($css['body'], ':root')
        && $js['status'] === 200 && str_contains($js['body'], 'use strict'),
    'css=' . $css['status'] . ' js=' . $js['status']
);

Qc::ok(
    '5.17',
    'Alpine.js tersedia sebagai aset lokal, bukan CDN',
    $alpine['status'] === 200 && str_contains($alpine['body'], 'Alpine'),
    'alpine=' . $alpine['status']
);

Qc::ok(
    '5.18',
    'CSS lama (app.css) sudah tidak tersedia',
    $legacyCss['status'] === 404,
    'app.css=' . $legacyCss['status']
);

Qc::finish('qc_routing');
