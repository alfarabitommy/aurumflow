<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| QC-2 — SQL Injection
|--------------------------------------------------------------------------
| Diuji pada dua lapisan:
|   1. Lapisan model (User::findByPhoneNumber) — payload harus jadi literal.
|   2. Lapisan HTTP (POST /login) — jalur yang benar-benar dipakai penyerang.
| Ditambah pemeriksaan statis: tidak boleh ada superglobal yang disisipkan ke
| dalam string SQL di seluruh app/ dan public/.
*/

require __DIR__ . '/bootstrap.php';

use AurumFlow\Config\Database;
use AurumFlow\Models\User;

Qc::suite('2 — SQL Injection (qc_sql_injection.php)');

$payloads = [
    "' OR '1'='1",
    "081200000001'--",
    "081200000001' OR 1=1 LIMIT 1--",
    "'; DROP TABLE users;--",
    "' UNION SELECT 1,password_hash,1 FROM users--",
    "' OR SLEEP(5)--",
    "081200000001'/**/OR/**/1=1#",
    "' AND (SELECT 1 FROM (SELECT SLEEP(0))x)--",
];

$credentials = QcFixture::ensureInstalled();

// ---------------------------------------------------------------------------
Qc::section('Lapisan model — User::findByPhoneNumber');

$countsBefore = [
    'users'           => (int) Database::fetchOne('SELECT COUNT(*) AS `total` FROM `users`')['total'],
    'system_settings' => (int) Database::fetchOne('SELECT COUNT(*) AS `total` FROM `system_settings`')['total'],
    'cash_mutations'  => (int) Database::fetchOne('SELECT COUNT(*) AS `total` FROM `cash_mutations`')['total'],
];

$modelFailures = [];

$startedAt = microtime(true);

foreach ($payloads as $payload) {
    try {
        $result = User::findByPhoneNumber($payload);

        if ($result !== null) {
            $modelFailures[] = $payload . ' -> mengembalikan baris id ' . ($result['id'] ?? '?');
        }
    } catch (Throwable $exception) {
        $modelFailures[] = $payload . ' -> melempar ' . $exception::class . ': ' . $exception->getMessage();
    }
}

$elapsed = microtime(true) - $startedAt;

Qc::ok(
    '2.1',
    'Seluruh payload injeksi pada findByPhoneNumber() mengembalikan null (' . count($payloads) . ' payload)',
    $modelFailures === [],
    implode("\n", $modelFailures)
);

Qc::ok(
    '2.4',
    'Payload SLEEP() tidak dieksekusi (total waktu < 2 detik)',
    $elapsed < 2.0,
    'waktu=' . round($elapsed, 4) . ' detik'
);

// 2.8 — input dengan byte invalid UTF-8
$invalidUtf8 = "081200\xC3\x28' OR 1=1--";
$invalidOutcome = 'null';

try {
    $invalidResult = User::findByPhoneNumber($invalidUtf8);
    $invalidOutcome = $invalidResult === null ? 'null' : 'ROW_RETURNED';
} catch (PDOException $exception) {
    $invalidOutcome = 'PDOException';
} catch (Throwable $exception) {
    $invalidOutcome = $exception::class;
}

Qc::ok(
    '2.8',
    'Input invalid UTF-8 tidak pernah menghasilkan autentikasi berhasil',
    $invalidOutcome !== 'ROW_RETURNED',
    'hasil=' . $invalidOutcome
);

Qc::note('Input invalid UTF-8 menghasilkan: ' . $invalidOutcome . '. '
    . ($invalidOutcome === 'PDOException'
        ? 'Strict mode utf8mb4 menolak byte tersebut — kegagalan aman, tetapi belum ramah '
          . 'pengguna. Validasi encoding input dijadwalkan fase berikutnya.'
        : 'Ditangani sebagai literal.'));

// ---------------------------------------------------------------------------
Qc::section('Integritas data setelah serangan');

$countsAfter = [
    'users'           => (int) Database::fetchOne('SELECT COUNT(*) AS `total` FROM `users`')['total'],
    'system_settings' => (int) Database::fetchOne('SELECT COUNT(*) AS `total` FROM `system_settings`')['total'],
    'cash_mutations'  => (int) Database::fetchOne('SELECT COUNT(*) AS `total` FROM `cash_mutations`')['total'],
];

Qc::ok(
    '2.3',
    'Tabel users masih ada dengan jumlah baris tidak berubah',
    QcFixture::tableExists('users') && $countsAfter['users'] === $countsBefore['users'],
    'sebelum=' . $countsBefore['users'] . ' sesudah=' . $countsAfter['users']
);

Qc::ok(
    '2.5',
    'Tabel system_settings tidak berubah',
    $countsAfter['system_settings'] === $countsBefore['system_settings'],
    'sebelum=' . $countsBefore['system_settings'] . ' sesudah=' . $countsAfter['system_settings']
);

Qc::ok(
    '2.5c',
    'Tabel cash_mutations tidak berubah',
    $countsAfter['cash_mutations'] === $countsBefore['cash_mutations'],
    'sebelum=' . $countsBefore['cash_mutations'] . ' sesudah=' . $countsAfter['cash_mutations']
);

$operatorStillThere = User::findByPhoneNumber($credentials['phone'], false);

Qc::ok(
    '2.5b',
    'Akun operator tidak terhapus atau berubah',
    $operatorStillThere !== null && (string) $operatorStillThere['phone_number'] === $credentials['phone']
);

Qc::equals(
    '2.5d',
    'Seed system_settings masih utuh (operator_base_quota_pct = 50.00)',
    '50.00',
    AurumFlow\Models\SystemSetting::get('operator_base_quota_pct')
);

// ---------------------------------------------------------------------------
Qc::section('Lapisan HTTP — POST /login');

$httpFailures = [];
$httpNonGeneric = [];

foreach ($payloads as $index => $payload) {
    $jarName = 'injection-' . $index;

    $loginPage = QcHttp::request('GET', '/login', ['cookie_jar' => QcHttp::cookieJar($jarName), 'fresh' => true]);
    $token = QcHttp::csrfFrom($loginPage['body']);

    if ($token === null) {
        $httpFailures[] = $payload . ' -> token CSRF tidak ditemukan pada halaman login';

        continue;
    }

    $response = QcHttp::request('POST', '/login', [
        'cookie_jar' => QcHttp::cookieJar($jarName),
        'data'       => ['phone_number' => $payload, 'password' => $payload, '_token' => $token],
    ]);

    if ($response['status'] === 302 && str_contains((string) $response['location'], '/dashboard')) {
        $httpFailures[] = $payload . ' -> BERHASIL LOGIN MASUK DASHBOARD';
    }

    if (!str_contains($response['body'], 'Nomor telepon atau password salah')) {
        $httpNonGeneric[] = $payload . ' -> status ' . $response['status'] . ', pesan generik tidak ditemukan';
    }
}

Qc::ok(
    '2.2',
    'Tidak ada payload injeksi yang berhasil login lewat HTTP (' . count($payloads) . ' payload)',
    $httpFailures === [],
    implode("\n", $httpFailures)
);

Qc::ok(
    '2.2b',
    'Pesan kegagalan login selalu generik (tidak membocorkan nomor/password mana yang salah)',
    $httpNonGeneric === [],
    implode("\n", $httpNonGeneric)
);

// 2.9 — reflected XSS
$xssJar = QcHttp::cookieJar('xss');
$xssPayload = '<script>alert(1)</script>';

$loginPage = QcHttp::request('GET', '/login', ['cookie_jar' => $xssJar, 'fresh' => true]);
$token = QcHttp::csrfFrom($loginPage['body']);

$reflected = QcHttp::request('POST', '/login', [
    'cookie_jar' => $xssJar,
    'data'       => ['phone_number' => $xssPayload, 'password' => 'x', '_token' => (string) $token],
]);

Qc::ok(
    '2.9',
    'Nomor telepon yang dipantulkan kembali ke halaman login dalam bentuk ter-escape',
    str_contains($reflected['body'], '&lt;script&gt;alert(1)&lt;/script&gt;')
        && !str_contains($reflected['body'], '<script>alert(1)</script>'),
    'status=' . $reflected['status']
);

// ---------------------------------------------------------------------------
Qc::section('Pemeriksaan konfigurasi dan statis');

$emulated = Database::getConnection()->getAttribute(PDO::ATTR_EMULATE_PREPARES);

Qc::ok(
    '2.7',
    'PDO::ATTR_EMULATE_PREPARES = false (prepared statement native)',
    $emulated === false || $emulated === 0 || $emulated === '0',
    'nilai=' . var_export($emulated, true)
);

$interpolated = qc_scan_lines(static function (string $line): bool {
    if (preg_match('/\$_(GET|POST|REQUEST|COOKIE|FILES|SERVER|SESSION)/', $line) !== 1) {
        return false;
    }

    if (preg_match('/\b(SELECT|INSERT\s+INTO|UPDATE\s+\w|DELETE\s+FROM|WHERE|ORDER\s+BY|LIMIT)\b/i', $line) !== 1) {
        return false;
    }

    // Superglobal berada DI DALAM string literal SQL.
    return preg_match('/(["\'])[^"\']*\$_(GET|POST|REQUEST|COOKIE|FILES)/', $line) === 1;
});

Qc::ok(
    '2.6',
    'Tidak ada superglobal yang diinterpolasi ke dalam string SQL',
    $interpolated === [],
    implode("\n", $interpolated)
);

$concatenated = qc_scan_lines(
    static fn (string $line): bool => preg_match('/\b(query|exec)\s*\(/', $line) === 1
        && preg_match('/\$_(GET|POST|REQUEST|COOKIE)/', $line) === 1
);

Qc::ok(
    '2.6b',
    'Tidak ada query()/exec() yang menerima superglobal secara langsung',
    $concatenated === [],
    implode("\n", $concatenated)
);

Qc::ok(
    '2.6c',
    'Security::e() tetap menjadi satu-satunya jalur escaping di view login',
    str_contains(file_get_contents(APP_PATH . '/Views/auth/login.php') ?: '', 'Security::e('),
    'login.php tidak memakai Security::e()'
);

Qc::finish('qc_sql_injection');
