<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| QC-6 — Security Helper (XSS, password, CSRF, session)
|--------------------------------------------------------------------------
| Session dibuka SEBELUM output apa pun supaya session_start() tidak
| memunculkan warning "headers already sent" pada SAPI CLI.
*/

require __DIR__ . '/bootstrap.php';

use AurumFlow\Helpers\Security;

// Output dibuffer sejak awal. session_regenerate_id() dan setcookie() menolak
// berjalan setelah header terkirim, dan pada SAPI CLI "header terkirim" berarti
// "sudah ada output". Buffer ini menjaga sesi tetap dapat diuji seperti pada
// request HTTP sungguhan (di mana output belum dikirim saat login diproses).
ob_start();

$sessionDirectory = TEST_PATH . '/.tmp/sessions';

if (!is_dir($sessionDirectory)) {
    mkdir($sessionDirectory, 0775, true);
}

// Lingkungan CLI: session tidak perlu cookie, dan simpan di folder sementara
// supaya tidak mengotori session.save_path sistem.
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.save_path', $sessionDirectory);

Security::startSession();

Qc::suite('6 — Security Helper (qc_security.php)');

// ---------------------------------------------------------------------------
Qc::section('XSS / output escaping');

Qc::equals(
    '6.1',
    'Tag script ter-escape',
    '&lt;script&gt;alert(1)&lt;/script&gt;',
    Security::e('<script>alert(1)</script>')
);

Qc::equals(
    '6.2',
    'Kutip ganda dan tag img ter-escape',
    '&quot;&gt;&lt;img src=x onerror=alert(1)&gt;',
    Security::e('"><img src=x onerror=alert(1)>')
);

Qc::equals('6.3', 'Apostrof ter-escape menjadi &#039;', '&#039; OR 1=1 --', Security::e("' OR 1=1 --"));

Qc::equals('6.4', 'null menghasilkan string kosong tanpa warning', '', Security::e(null));

Qc::equals('6.5', 'Teks multibyte dan emoji tidak rusak', 'Émile 🥇', Security::e('Émile 🥇'));

Qc::equals('6.5b', 'Angka tetap dapat ditampilkan', '42', Security::e(42));

Qc::equals(
    '6.5c',
    'sanitize() menghapus tag dan karakter kontrol tanpa merusak multibyte',
    'Émile bersih',
    Security::sanitize("  <b>Émile</b>\x07 bersih  ")
);

// ---------------------------------------------------------------------------
Qc::section('Password hashing');

$plain = 'Rahasia2026!';
$hash = Security::hashPassword($plain);

Qc::ok('6.6', 'verifyPassword() menerima password yang benar', Security::verifyPassword($plain, $hash));
Qc::ok('6.6b', 'verifyPassword() menolak password yang salah', !Security::verifyPassword('salah-sekali', $hash));

$info = password_get_info($hash);

Qc::ok(
    '6.7',
    'Hash memakai algoritma PASSWORD_DEFAULT dan berformat benar',
    strlen($hash) >= 60 && ($info['algo'] ?? null) !== null && $info['algo'] !== '',
    'hash=' . $hash . ' algo=' . var_export($info['algo'] ?? null, true)
);

$secondHash = Security::hashPassword($plain);

Qc::ok('6.8', 'Dua hash untuk password yang sama berbeda (salt acak)', $hash !== $secondHash);

Qc::ok('6.9', 'verifyPassword() dengan hash korup mengembalikan false tanpa error', !Security::verifyPassword($plain, 'bukan-hash-sama-sekali'));

Qc::ok(
    '6.9b',
    'passwordStrength() menolak password lemah dan menerima yang kuat',
    !Security::passwordStrength('abc') && !Security::passwordStrength('abcdefgh') && Security::passwordStrength('abc12345'),
    'abc=' . var_export(Security::passwordStrength('abc'), true)
    . ' abcdefgh=' . var_export(Security::passwordStrength('abcdefgh'), true)
    . ' abc12345=' . var_export(Security::passwordStrength('abc12345'), true)
);

// ---------------------------------------------------------------------------
Qc::section('CSRF');

$token = Security::csrfToken();

Qc::ok(
    '6.10',
    'csrfToken() menghasilkan 64 karakter hex',
    preg_match('/^[0-9a-f]{64}$/', $token) === 1,
    'token=' . $token
);

Qc::ok('6.10b', 'csrfToken() stabil selama satu session', $token === Security::csrfToken());

Qc::ok('6.11', 'verifyCsrf() menerima token yang benar', Security::verifyCsrf($token));

Qc::ok(
    '6.12',
    'verifyCsrf() menolak token salah, null, dan string kosong',
    !Security::verifyCsrf(bin2hex(random_bytes(32)))
        && !Security::verifyCsrf(null)
        && !Security::verifyCsrf('')
);

$field = Security::csrfField();

Qc::ok(
    '6.11b',
    'csrfField() menghasilkan input hidden yang benar',
    str_contains($field, 'type="hidden"')
        && str_contains($field, 'name="' . Security::CSRF_FIELD . '"')
        && str_contains($field, 'value="' . $token . '"'),
    $field
);

// Token kedaluwarsa.
$issuedAt = $_SESSION[Security::CSRF_TIME_KEY] ?? null;
$_SESSION[Security::CSRF_TIME_KEY] = time() - 100000;

Qc::ok(
    '6.13',
    'verifyCsrf() menolak token yang melewati SESSION_LIFETIME',
    !Security::verifyCsrf($token),
    'lifetime=' . var_export(AurumFlow\Config\App::get('session_lifetime'), true)
);

$_SESSION[Security::CSRF_TIME_KEY] = is_int($issuedAt) ? $issuedAt : time();

// Rotasi token.
$previous = Security::csrfToken();
Security::rotateCsrf();
$rotated = Security::csrfToken();

Qc::ok('6.14', 'rotateCsrf() menerbitkan token berbeda', $rotated !== $previous);
Qc::ok('6.14b', 'Token lama tidak berlaku setelah rotasi', !Security::verifyCsrf($previous));
Qc::ok('6.14c', 'Token baru berlaku setelah rotasi', Security::verifyCsrf($rotated));

// ---------------------------------------------------------------------------
Qc::section('Session');

$beforeRegenerate = session_id();

Security::regenerateSession();
$afterRegenerate = session_id();

Qc::ok(
    '6.15',
    'regenerateSession() mengubah session id',
    $beforeRegenerate !== '' && $afterRegenerate !== '' && $beforeRegenerate !== $afterRegenerate,
    'sebelum=' . $beforeRegenerate . ' sesudah=' . $afterRegenerate
);

// ---------------------------------------------------------------------------
Qc::section('Sumber acak untuk token');

$tokens = [];

for ($i = 0; $i < 1000; $i++) {
    $tokens[Security::randomToken(16)] = true;
}

Qc::ok('6.17', 'randomToken() tidak menghasilkan duplikat pada 1000 percobaan', count($tokens) === 1000);

// UUID v4 — primary key seluruh skema ERD (CHAR(36)).
$uuid = Security::uuidV4();

Qc::ok(
    '6.19',
    'uuidV4() menghasilkan format UUID v4 yang sah',
    preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid) === 1,
    'uuid=' . $uuid
);

Qc::ok('6.19b', 'uuidV4() menghasilkan panjang tepat 36 karakter', strlen($uuid) === 36, 'panjang=' . strlen($uuid));

$uuidSet = [];

for ($i = 0; $i < 2000; $i++) {
    $uuidSet[Security::uuidV4()] = true;
}

Qc::ok('6.19c', 'uuidV4() tidak menghasilkan duplikat pada 2000 percobaan', count($uuidSet) === 2000);

Qc::ok(
    '6.19d',
    'isUuid() menerima UUID v4 dan menolak nilai lain',
    Security::isUuid($uuid)
        && !Security::isUuid(bin2hex(random_bytes(16)))
        && !Security::isUuid('')
        && !Security::isUuid('081200000001')
);

// ---------------------------------------------------------------------------
Qc::section('Pemeriksaan statis');

$weakCrypto = qc_scan_lines(
    static fn (string $line): bool => preg_match('/\b(md5|sha1)\s*\(/', $line) === 1
);

Qc::ok(
    '6.18',
    'Tidak ada md5()/sha1() di app/ maupun public/',
    $weakCrypto === [],
    implode("\n", $weakCrypto)
);

$weakRandom = qc_scan_lines(
    static fn (string $line): bool => preg_match('/\b(mt_rand|rand|uniqid|str_shuffle|srand|mt_srand)\s*\(/', $line) === 1
);

Qc::ok(
    '6.18b',
    'Tidak ada rand()/mt_rand()/uniqid() untuk token keamanan',
    $weakRandom === [],
    implode("\n", $weakRandom)
);

$passwordHashing = qc_scan_lines(
    static fn (string $line): bool => str_contains($line, 'password_hash(')
        && !str_contains($line, 'PASSWORD_DEFAULT')
        && !str_contains($line, 'function')
);

Qc::ok(
    '6.18c',
    'password_hash() selalu memakai PASSWORD_DEFAULT',
    $passwordHashing === [],
    implode("\n", $passwordHashing)
);

// destroySession() dijalankan paling akhir karena menghapus session aktif.
$_SESSION['uji'] = 'nilai';

Security::destroySession();

Qc::ok(
    '6.16',
    'destroySession() mengosongkan session dan mengakhiri status aktif',
    session_status() !== PHP_SESSION_ACTIVE && (!isset($_SESSION) || $_SESSION === [])
);

Qc::finish('qc_security');
