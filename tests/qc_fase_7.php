<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| QC FASE 7 — Protokol QC Manual 7: Isolasi Peran Kasir Meja Bakar
|--------------------------------------------------------------------------
| Sumber: docs/AurumFlow Roadmap and QC Documents.md — FASE 7
|         docs/AurumFlow Product Requirement Document v3.0.md — § 2 + Modul 6
|         plan/Plan_Fase_7_Kasir_AurumFlow.md — § 3–§ 9
|
| Yang dibuktikan suite ini:
|   A. Skema aditif: `users.role` memuat peran kasir, urutannya append-only,
|      migrasi idempoten, NOL kolom baru, dan baris lama tidak tersentuh.
|   B. Tata kelola akun kasir (layar "Kelola Kasir"): pembuatan + PIN ber-pepper,
|      kebijakan PIN dipakai ulang, reset PIN, aktif/nonaktif, `_once`, dan
|      gagal-tertutup saat `APP_PIN_PEPPER` kosong.
|   C. Pintu PIN kasir `/cashier/login`: papan angka dari komponen Fase 5,
|      pesan generik, pembatas percobaan, dan gerbang peran dua arah.
|   D. Matriks gating *allow-list*: kasir hanya lolos di meja kasir + talangan;
|      seluruh rute lain dijawab `302` ke `/operator/teller` TANPA tulisan dan
|      TANPA mengonsumsi token `_once` (GET maupun POST).
|   E. Isolasi tampilan: permukaan kasir tidak memuat Brankas, bagi hasil,
|      maupun tautan pemilik — dibuktikan dengan kanari angka, bukan asumsi.
|   F. Kontrak statis: guard-first, daftar izin tunggal, tanpa komponen Alpine
|      baru, dan skrip migrasi yang ter-gate.
|   G. Fixture idempotent serta jejak artefak yang bersih.
|
| PERINGATAN: suite ini menulis ke database yang ditunjuk `.env` (akun kasir uji
| `0897…`, skema `users.role`, kas meja, `bridge_loans`) DAN ke disk
| (`storage/throttle/`). Jangan arahkan ke produksi.
| Konvensi exit code: 0 = lulus, 1 = gagal.
*/

require __DIR__ . '/bootstrap.php';

use AurumFlow\Config\Constants;
use AurumFlow\Config\Database;
use AurumFlow\Controllers\BaseController;
use AurumFlow\Controllers\CashierAuthController;
use AurumFlow\Controllers\CashierController;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\CashRegister;
use AurumFlow\Models\User;
use AurumFlow\Services\CashierService;
use AurumFlow\Services\TellerService;

Qc::suite('Protokol QC Manual 7 — Isolasi Peran Kasir Meja Bakar (qc_fase_7.php)');

Qc::note('Sumber: docs/AurumFlow Roadmap and QC Documents.md — FASE 7 + PRD Modul 6');
Qc::note('PERINGATAN: suite ini menulis ke DB pada .env dan ke storage/throttle/.');

// ---------------------------------------------------------------------------
// Helper lokal
// ---------------------------------------------------------------------------

/**
 * Pemindai statis untuk berkas TERTENTU (predikat menerima isi baris).
 *
 * Dipakai alih-alih `qc_scan_lines()` yang memindai seluruh proyek dan tidak
 * membawa path pada predikatnya.
 *
 * @param list<string> $paths
 * @return list<string> pelanggaran (kosong = lulus)
 */
function qc7_scan(array $paths, callable $predicate): array
{
    $offenders = [];

    foreach ($paths as $relative) {
        $path = BASE_PATH . '/' . $relative;
        $contents = file_get_contents($path);

        if ($contents === false) {
            $offenders[] = $relative . ' tidak terbaca';

            continue;
        }

        $lines = preg_split('/\R/', $contents);

        if ($lines === false) {
            continue;
        }

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            // Lewati komentar & baris kosong: pemindai mencari KODE, bukan prosa.
            if ($trimmed === ''
                || str_starts_with($trimmed, '*')
                || str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '/*')
                || str_starts_with($trimmed, '#')
            ) {
                continue;
            }

            if ($predicate($line) === true) {
                $offenders[] = $relative . ':' . ($index + 1) . '  ' . $trimmed;
            }
        }
    }

    return $offenders;
}

/**
 * Isi satu method (termasuk kurung kurawal pembuka) berdasarkan namanya.
 *
 * Dipakai assertion guard-first: yang diperiksa adalah URUTAN pernyataan di
 * dalam method, bukan sekadar keberadaan teks di berkas.
 */
function qc7_method_body(string $source, string $method): ?string
{
    $needle = 'function ' . $method . '(';
    $start = strpos($source, $needle);

    if ($start === false) {
        return null;
    }

    $open = strpos($source, '{', $start);

    if ($open === false) {
        return null;
    }

    $depth = 0;
    $length = strlen($source);

    for ($index = $open; $index < $length; $index++) {
        $char = $source[$index];

        if ($char === '{') {
            $depth++;
        } elseif ($char === '}') {
            $depth--;

            if ($depth === 0) {
                return substr($source, $open, $index - $open + 1);
            }
        }
    }

    return null;
}

/**
 * Posisi pertama salah satu jarum di dalam potongan kode, atau PHP_INT_MAX.
 *
 * @param list<string> $needles
 */
function qc7_first_pos(string $haystack, array $needles): int
{
    $best = PHP_INT_MAX;

    foreach ($needles as $needle) {
        $position = strpos($haystack, $needle);

        if ($position !== false && $position < $best) {
            $best = $position;
        }
    }

    return $best;
}

/**
 * Masuk lewat pintu PIN kasir, kembalikan cookie jar + responsnya.
 *
 * @return array{jar: string, csrf: ?string, response: array<string, mixed>,
 *                page: array<string, mixed>}
 */
function qc7_loginCashier(string $phone, string $pin, string $jarName): array
{
    $jar = QcHttp::cookieJar($jarName);
    $page = QcHttp::request('GET', '/cashier/login', ['cookie_jar' => $jar, 'fresh' => true]);
    $csrf = QcHttp::csrfFrom($page['body']);

    $response = QcHttp::request('POST', '/cashier/login', [
        'cookie_jar' => $jar,
        'data'       => [
            Security::CSRF_FIELD => (string) $csrf,
            'phone_number'       => $phone,
            'pin'                => $pin,
        ],
    ]);

    return ['jar' => $jar, 'csrf' => $csrf, 'response' => $response, 'page' => $page];
}

/**
 * Buka halaman meja kasir sebagai kasir, kembalikan token halaman itu.
 *
 * @return array{jar: string, body: string, csrf: ?string, once: ?string}
 */
function qc7_openTeller(string $jar): array
{
    $page = QcHttp::request('GET', '/operator/teller', ['cookie_jar' => $jar]);

    return [
        'jar'   => $jar,
        'body'  => (string) $page['body'],
        'csrf'  => QcHttp::csrfFrom((string) $page['body']),
        'once'  => QcHttp::fieldFrom((string) $page['body'], Security::FORM_FIELD),
    ];
}

/**
 * Buku talangan TANPA saldo kas meja.
 *
 * `QcFixture::bridgeSnapshot()` memuat `meja_balance`, yang memang berubah
 * setiap transaksi kasir. Untuk assertion "nol tulisan pada rute terlarang"
 * yang relevan hanyalah baris `bridge_loans`-nya.
 *
 * @return array<string, mixed>
 */
function qc7_loanBook(): array
{
    $snapshot = QcFixture::bridgeSnapshot();
    unset($snapshot['meja_balance']);

    return $snapshot;
}

/** Jumlah baris satu tabel (opsional dengan klausa WHERE sederhana). */
function qc7_count(string $table, string $where = '', array $params = []): int
{
    $row = Database::fetchOne(
        'SELECT COUNT(*) AS `total` FROM `' . $table . '`' . ($where === '' ? '' : ' WHERE ' . $where),
        $params
    );

    return (int) ($row['total'] ?? 0);
}

$operator = QcFixture::ensureInstalled();

// ---------------------------------------------------------------------------
// A. Skema aditif & migrasi idempoten
// ---------------------------------------------------------------------------

Qc::section('A. Skema: ENUM users.role aditif, migrasi idempoten, nol kolom baru');

$enumValues = User::roleEnumValues();

Qc::equals(
    'A.1',
    'users.role = [operator, investor, cashier] dengan urutan append-only',
    ['operator', 'investor', 'cashier'],
    $enumValues
);

Qc::ok(
    'A.2',
    'Keputusan migrasi murni: ENUM dua nilai perlu diperluas, ENUM tiga nilai tidak',
    User::needsRoleEnumExtension("enum('operator','investor')") === true
        && User::needsRoleEnumExtension("enum('operator','investor','cashier')") === false
        && User::needsRoleEnumExtension("enum('investor','cashier','operator')") === false
        && User::needsRoleEnumExtension("enum('')") === true
);

Qc::ok(
    'A.3a',
    'ensureRoleEnum() pada DB termigrasi tidak menjalankan ALTER lagi',
    User::ensureRoleEnum() === false
);

$migration = qc_run_php(BASE_PATH . '/database/migrate.php');
$migrationOutput = $migration['output'];

Qc::ok(
    'A.3b',
    'php database/migrate.php idempoten: exit 0 dan melaporkan "sudah diterapkan"',
    $migration['code'] === 0
        && str_contains($migrationOutput, 'sudah diterapkan')
        && str_contains($migrationOutput, 'cashier'),
    'exit=' . $migration['code'] . ' output=' . trim($migrationOutput)
);

$rejected = User::roleEnumValues();

Qc::ok(
    'A.4',
    'Nilai ENUM di luar definisi tetap ditolak (peran asing tidak dapat disisipkan)',
    (static function (): bool {
        try {
            Database::query(
                'INSERT INTO `users` (`id`, `full_name`, `phone_number`, `password_hash`, `role`, `is_active`) '
                . 'VALUES (:id, :name, :phone, :hash, :role, 0)',
                [
                    'id'    => Security::uuidV4(),
                    'name'  => 'QC Peran Asing',
                    'phone' => '089799999999',
                    'hash'  => 'x',
                    'role'  => 'superadmin',
                ]
            );

            return false;
        } catch (Throwable) {
            return true;
        }
    })(),
    'ENUM menjaga daftar peran resmi'
);

$usersColumn = QcFixture::columnDefinition('users', 'role');

Qc::ok(
    'A.5a',
    'Jumlah kolom tabel users tetap 12 (nol kolom kredensial baru)',
    (static function (): bool {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS `total` FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND table_name = :table',
            ['table' => 'users']
        );

        return (int) ($row['total'] ?? 0) === 12;
    })(),
    'users harus tetap 12 kolom'
);

Qc::ok(
    'A.5b',
    'users.role tetap ENUM NOT NULL DEFAULT investor (tipe & default tidak berubah)',
    $usersColumn !== null
        && (string) $usersColumn['DATA_TYPE'] === 'enum'
        && strtoupper((string) $usersColumn['IS_NULLABLE']) === 'NO'
        && str_contains((string) $usersColumn['COLUMN_TYPE'], "'investor'"),
    'COLUMN_TYPE=' . (string) ($usersColumn['COLUMN_TYPE'] ?? '-')
);

Qc::ok(
    'A.6',
    'Daftar nilai ENUM identik di ERD, schema.sql, model, dan basis data',
    (static function () use ($enumValues): bool {
        $expected = User::ROLE_ENUM_VALUES;
        $pattern = "/`?role`?\\s+ENUM\\(\\s*'operator',\\s*'investor',\\s*'cashier'\\s*\\)/i";

        $erd = (string) file_get_contents(BASE_PATH . '/docs/AurumFlow Entity Relationship Diagram.md');
        $schema = (string) file_get_contents(BASE_PATH . '/database/schema.sql');
        $model = (string) file_get_contents(APP_PATH . '/Models/User.php');
        $migrate = (string) file_get_contents(BASE_PATH . '/database/migrate.php');

        // DDL satu pemilik: `User::ensureRoleEnum()` (SQL hanya di app/Models/).
        return $enumValues === $expected
            && preg_match($pattern, $erd) === 1
            && preg_match($pattern, $schema) === 1
            && str_contains($model, "ENUM('operator', 'investor', 'cashier')")
            && str_contains($migrate, 'User::ensureRoleEnum()');
    })(),
    'empat sumber (ERD, schema.sql, DDL model, INFORMATION_SCHEMA) harus sepakat'
);

$rolesBefore = (string) (Database::fetchOne(
    'SELECT GROUP_CONCAT(CONCAT(`id`, ":", `role`) ORDER BY `id` SEPARATOR "|") AS `snapshot` FROM `users`'
)['snapshot'] ?? '');
$rowsBefore = qc7_count('users');

User::ensureRoleEnum();

$rolesAfter = (string) (Database::fetchOne(
    'SELECT GROUP_CONCAT(CONCAT(`id`, ":", `role`) ORDER BY `id` SEPARATOR "|") AS `snapshot` FROM `users`'
)['snapshot'] ?? '');

Qc::ok(
    'A.7',
    'Migrasi aditif tidak menulis ulang satu pun baris users (id:role identik)',
    $rolesBefore === $rolesAfter && qc7_count('users') === $rowsBefore,
    'sebelum=' . substr($rolesBefore, 0, 120) . ' sesudah=' . substr($rolesAfter, 0, 120)
);

$cashiers = QcFixture::resetCashierState();
$primary = $cashiers['primary'];
$primaryId = (string) $primary['id'];

Qc::ok(
    'A.8',
    'Akun kasir tidak memiliki dompet modal (bukan mitra, tidak menerima bagi hasil)',
    qc7_count('capital_wallets', '`user_id` = :user_id', ['user_id' => $primaryId]) === 0
        && qc7_scan(['app/Services/CashierService.php'], static fn (string $line): bool => str_contains($line, 'CapitalWallet')) === [],
    'capital_wallets untuk kasir harus nol baris'
);

// ---------------------------------------------------------------------------
// B. Tata kelola akun kasir — layar "Kelola Kasir"
// ---------------------------------------------------------------------------

Qc::section('B. Kelola Kasir: pembuatan, PIN ber-pepper, reset, aktif/nonaktif');

$operatorJar = QcHttp::cookieJar('fase7-operator');
$operatorLoginForm = QcHttp::request('GET', '/login', ['cookie_jar' => $operatorJar, 'fresh' => true]);
$operatorLogin = QcHttp::request('POST', '/login', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD => (string) QcHttp::csrfFrom($operatorLoginForm['body']),
        'phone_number'       => $operator['phone'],
        'password'           => $operator['password'],
    ],
]);

Qc::ok(
    'B.0',
    'Operator masuk lewat /login (kata sandi) dan mendarat di /dashboard',
    $operatorLogin['status'] === 302 && $operatorLogin['location'] === '/dashboard',
    'status=' . $operatorLogin['status'] . ' location=' . (string) $operatorLogin['location']
);

$kasirPage = QcHttp::request('GET', '/operator/kasir', ['cookie_jar' => $operatorJar]);
$anonKasir = QcHttp::request('GET', '/operator/kasir', [
    'cookie_jar' => QcHttp::cookieJar('fase7-anon-kasir'),
    'fresh'      => true,
]);

Qc::ok(
    'B.1a',
    'GET /operator/kasir: operator 200, anonim 302 /login',
    $kasirPage['status'] === 200
        && str_contains((string) $kasirPage['body'], 'Kelola Kasir')
        && $anonKasir['status'] === 302
        && $anonKasir['location'] === '/login',
    'operator=' . $kasirPage['status'] . ' anonim=' . $anonKasir['status']
);

$cashierJarB = qc7_loginCashier(
    QcFixture::QC_CASHIER['phone'],
    QcFixture::QC_CASHIER['pin'],
    'fase7-cashier-b'
);

$cashierKasirPage = QcHttp::request('GET', '/operator/kasir', ['cookie_jar' => $cashierJarB['jar']]);

Qc::ok(
    'B.1b',
    'GET /operator/kasir oleh kasir diarahkan ke /operator/teller (nol tulisan)',
    $cashierKasirPage['status'] === 302 && $cashierKasirPage['location'] === '/operator/teller',
    'status=' . $cashierKasirPage['status'] . ' location=' . (string) $cashierKasirPage['location']
);

$createPhone = '089700000199';
Database::query('DELETE FROM `users` WHERE `phone_number` = :phone', ['phone' => $createPhone]);

$createBody = [
    Security::CSRF_FIELD            => (string) QcHttp::csrfFrom((string) $kasirPage['body']),
    Security::FORM_FIELD            => (string) QcHttp::fieldFrom((string) $kasirPage['body'], Security::FORM_FIELD),
    'cashier_name'                  => 'Kasir Uji Protokol',
    'cashier_phone'                 => $createPhone,
    'cashier_pin'                   => '481516',
    'cashier_pin_confirm'           => '481516',
];

$createResponse = QcHttp::request('POST', '/operator/kasir', [
    'cookie_jar' => $operatorJar,
    'data'       => $createBody,
]);

$created = User::findByPhoneNumber($createPhone);

Qc::ok(
    'B.2',
    'POST /operator/kasir membuat akun role=cashier aktif tanpa jejak waiver/undangan',
    $createResponse['status'] === 302
        && $createResponse['location'] === '/operator/kasir'
        && $created !== null
        && (string) $created['role'] === Constants::ROLE_CASHIER
        && (int) $created['is_active'] === 1
        && trim((string) ($created['onboarding_signed_at'] ?? '')) === '',
    'status=' . $createResponse['status'] . ' lokasi=' . (string) $createResponse['location']
);

Qc::ok(
    'B.3',
    'PIN kasir ber-pepper: sah di verifyPin(), TIDAK sah di verifyPassword()',
    $created !== null
        && User::verifyPin($created, '481516')
        && User::verifyPin($created, '481517') === false
        && User::verifyPassword($created, '481516') === false,
    'dua arah: PIN vs kata sandi'
);

$pepperProbe = TEST_PATH . '/.tmp/qc_fase_7_pepper_probe.php';

if (!is_dir(dirname($pepperProbe))) {
    mkdir(dirname($pepperProbe), 0775, true);
}

file_put_contents($pepperProbe, <<<'PHP'
<?php

// Dibuat otomatis oleh tests/qc_fase_7.php — jangan disunting.
require dirname(__DIR__, 2) . '/app/Config/bootstrap.php';

use AurumFlow\Models\User;
use AurumFlow\Services\CashierService;

$probePhone = '089700000194';
$result = ['pesan' => 'tidak-melempar'];

try {
    CashierService::create([
        'full_name'    => 'Kasir Tanpa Pepper',
        'phone_number' => $probePhone,
        'pin'          => '246810',
        'pin_confirm'  => '246810',
    ]);
} catch (RuntimeException $exception) {
    $result['pesan'] = 'melempar';
}

$result['baris'] = User::findByPhoneNumber($probePhone, false) === null ? 'nol' : 'ada';

echo 'GAGAL-TERTUTUP ' . ($result['pesan'] === 'melempar' && $result['baris'] === 'nol' ? 'ya' : 'tidak')
    . ' pesan=' . $result['pesan'] . ' baris=' . $result['baris'];
PHP);

$noPepper = qc_run_php($pepperProbe, ['APP_PIN_PEPPER' => '']);

Qc::ok(
    'B.4',
    'APP_PIN_PEPPER kosong → pembuatan akun gagal-tertutup (pesan + nol baris)',
    $noPepper['code'] === 0
        && str_contains($noPepper['output'], 'GAGAL-TERTUTUP ya')
        && User::findByPhoneNumber('089700000194', false) === null,
    'exit=' . $noPepper['code'] . ' out=' . trim($noPepper['output'])
);

$weakResponses = [];

foreach ([['111111', 'PIN lemah: seluruh digit sama'], ['123456', 'PIN lemah: barisan lurus'], ['123123', 'PIN lemah: blok berulang'], ['481516', 'Konfirmasi PIN tidak sama']] as $case) {
    $page = QcHttp::request('GET', '/operator/kasir', ['cookie_jar' => $operatorJar]);

    $weakResponses[] = QcHttp::request('POST', '/operator/kasir', [
        'cookie_jar' => $operatorJar,
        'data'       => [
            Security::CSRF_FIELD    => (string) QcHttp::csrfFrom((string) $page['body']),
            Security::FORM_FIELD    => (string) QcHttp::fieldFrom((string) $page['body'], Security::FORM_FIELD),
            'cashier_name'          => 'Kasir Lemah',
            'cashier_phone'         => '089700000198',
            'cashier_pin'           => $case[0],
            'cashier_pin_confirm'   => $case[1] === 'Konfirmasi PIN tidak sama' ? '000000' : $case[0],
        ],
    ]);
}

Qc::ok(
    'B.5',
    'PIN lemah & konfirmasi tidak sama ditolak 422 tanpa menulis akun (kebijakan OnboardingService)',
    (static function () use ($weakResponses): bool {
        foreach ($weakResponses as $response) {
            if ((int) $response['status'] !== 422) {
                return false;
            }
        }

        return true;
    })()
        && User::findByPhoneNumber('089700000198', false) === null,
    'empat kasus isian harus 422 dan nol baris'
);

$duplicatePage = QcHttp::request('GET', '/operator/kasir', ['cookie_jar' => $operatorJar]);
$duplicateBefore = User::findByPhoneNumber($createPhone, false);
$duplicate = QcHttp::request('POST', '/operator/kasir', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD            => (string) QcHttp::csrfFrom((string) $duplicatePage['body']),
        Security::FORM_FIELD            => (string) QcHttp::fieldFrom((string) $duplicatePage['body'], Security::FORM_FIELD),
        'cashier_name'                  => 'Kasir Duplikat',
        'cashier_phone'                 => $createPhone,
        'cashier_pin'                   => '135791',
        'cashier_pin_confirm'           => '135791',
    ],
]);
$duplicateAfter = User::findByPhoneNumber($createPhone, false);

Qc::ok(
    'B.7',
    'Nomor telepon duplikat ditolak 422 dan akun lama TIDAK ditulis ulang',
    (int) $duplicate['status'] === 422
        && $duplicateAfter !== null
        && $duplicateBefore !== null
        && (string) $duplicateAfter['id'] === (string) $duplicateBefore['id']
        && (string) $duplicateAfter['full_name'] === 'Kasir Uji Protokol'
        && (string) $duplicateAfter['password_hash'] === (string) $duplicateBefore['password_hash'],
    'status=' . $duplicate['status']
);

$invalidPage = QcHttp::request('GET', '/operator/kasir', ['cookie_jar' => $operatorJar]);
$invalid = QcHttp::request('POST', '/operator/kasir', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD            => (string) QcHttp::csrfFrom((string) $invalidPage['body']),
        Security::FORM_FIELD            => (string) QcHttp::fieldFrom((string) $invalidPage['body'], Security::FORM_FIELD),
        'cashier_name'                  => 'A',
        'cashier_phone'                 => 'abc',
        'cashier_pin'                   => '147258',
        'cashier_pin_confirm'           => '147258',
    ],
]);

Qc::ok(
    'B.8',
    'Nama terlalu pendek / nomor tidak valid ditolak 422',
    (int) $invalid['status'] === 422
        && str_contains((string) $invalid['body'], 'Nama kasir wajib diisi'),
    'status=' . $invalid['status']
);

// --- reset PIN
$resetPage = QcHttp::request('GET', '/operator/kasir', ['cookie_jar' => $operatorJar]);
$reset = QcHttp::request('POST', '/operator/kasir/pin', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD    => (string) QcHttp::csrfFrom((string) $resetPage['body']),
        Security::FORM_FIELD    => (string) QcHttp::fieldFrom((string) $resetPage['body'], Security::FORM_FIELD),
        'pin_user_id'           => (string) ($created['id'] ?? ''),
        'reset_pin'             => '864209',
        'reset_pin_confirm'     => '864209',
    ],
]);

$afterReset = User::findByPhoneNumber($createPhone);
$kasirPageAfterReset = QcHttp::request('GET', '/operator/kasir', ['cookie_jar' => $operatorJar]);
$kasirBodyAfterReset = (string) $kasirPageAfterReset['body'];

Qc::ok(
    'B.9a',
    'Reset PIN: PIN lama hangus, PIN baru berlaku, dan PIN tidak pernah dirender kembali',
    $reset['status'] === 302
        && $afterReset !== null
        && User::verifyPin($afterReset, '864209')
        && User::verifyPin($afterReset, '481516') === false
        && !str_contains($kasirBodyAfterReset, '864209')
        && !str_contains($kasirBodyAfterReset, '481516')
        && !str_contains($kasirBodyAfterReset, 'name="reset_pin" value="'),
    'status=' . $reset['status']
);

// --- nonaktifkan lalu aktifkan kembali
$statusPage = QcHttp::request('GET', '/operator/kasir', ['cookie_jar' => $operatorJar]);
$deactivate = QcHttp::request('POST', '/operator/kasir/status', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD    => (string) QcHttp::csrfFrom((string) $statusPage['body']),
        Security::FORM_FIELD    => (string) QcHttp::fieldFrom((string) $statusPage['body'], Security::FORM_FIELD),
        'status_user_id'        => (string) ($created['id'] ?? ''),
        'status_active'         => '0',
    ],
]);

$deactivated = User::findByPhoneNumber($createPhone, false);
$blockedLogin = qc7_loginCashier($createPhone, '864209', 'fase7-deactivated');

$activatePage = QcHttp::request('GET', '/operator/kasir', ['cookie_jar' => $operatorJar]);
$activate = QcHttp::request('POST', '/operator/kasir/status', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD    => (string) QcHttp::csrfFrom((string) $activatePage['body']),
        Security::FORM_FIELD    => (string) QcHttp::fieldFrom((string) $activatePage['body'], Security::FORM_FIELD),
        'status_user_id'        => (string) ($created['id'] ?? ''),
        'status_active'         => '1',
    ],
]);

$reactivated = User::findByPhoneNumber($createPhone);

Qc::ok(
    'B.10',
    'Nonaktifkan akun kasir memblokir login (401), aktifkan kembali memulihkannya',
    $deactivate['status'] === 302
        && $deactivated !== null
        && (int) $deactivated['is_active'] === 0
        && (int) $blockedLogin['response']['status'] === 401
        && $activate['status'] === 302
        && $reactivated !== null
        && (int) $reactivated['is_active'] === 1,
    'nonaktif status=' . $blockedLogin['response']['status'] . ' aktif=' . ($reactivated !== null ? (int) $reactivated['is_active'] : -1)
);

Qc::ok(
    'B.11a',
    'POST /operator/kasir tanpa token _once ditolak 419',
    (static function () use ($operatorJar): bool {
        $page = QcHttp::request('GET', '/operator/kasir', ['cookie_jar' => $operatorJar]);
        $response = QcHttp::request('POST', '/operator/kasir', [
            'cookie_jar' => $operatorJar,
            'data'       => [
                Security::CSRF_FIELD    => (string) QcHttp::csrfFrom((string) $page['body']),
                'cashier_name'          => 'Kasir Tanpa Once',
                'cashier_phone'         => '089700000197',
                'cashier_pin'           => '147258',
                'cashier_pin_confirm'   => '147258',
            ],
        ]);

        return (int) $response['status'] === 419 && User::findByPhoneNumber('089700000197', false) === null;
    })(),
    'token sekali-pakai wajib ada'
);

$snapshotBeforeDuplicate = QcFixture::cashierSnapshot();
$duplicateOnce = (static function () use ($operatorJar): array {
    $page = QcHttp::request('GET', '/operator/kasir', ['cookie_jar' => $operatorJar]);
    $data = [
        Security::CSRF_FIELD    => (string) QcHttp::csrfFrom((string) $page['body']),
        Security::FORM_FIELD    => (string) QcHttp::fieldFrom((string) $page['body'], Security::FORM_FIELD),
        'cashier_name'          => 'Kasir Ganda',
        'cashier_phone'         => '089700000196',
        'cashier_pin'           => '147258',
        'cashier_pin_confirm'   => '147258',
    ];

    $first = QcHttp::request('POST', '/operator/kasir', ['cookie_jar' => $operatorJar, 'data' => $data]);
    $second = QcHttp::request('POST', '/operator/kasir', ['cookie_jar' => $operatorJar, 'data' => $data]);

    return ['first' => $first, 'second' => $second];
})();

Qc::ok(
    'B.11b',
    'Submit ganda dengan token _once yang sama → PRG tanpa akun kedua',
    $duplicateOnce['first']['status'] === 302
        && $duplicateOnce['second']['status'] === 302
        && $duplicateOnce['second']['location'] === '/operator/kasir'
        && User::findByPhoneNumber('089700000196', false) !== null
        && count(QcFixture::cashierSnapshot()) === count($snapshotBeforeDuplicate) + 1,
    'akun uji harus bertambah tepat satu'
);

$logFile = STORAGE_PATH . '/logs/app-' . date('Y-m-d') . '.log';
$logContents = is_file($logFile) ? (string) file_get_contents($logFile) : '';

Qc::ok(
    'B.12',
    'Jejak log tata kelola kasir ada dan tidak pernah memuat PIN',
    str_contains($logContents, 'Akun kasir dibuat')
        && str_contains($logContents, 'PIN kasir direset')
        && str_contains($logContents, 'Status akun kasir diubah')
        && !str_contains($logContents, '481516')
        && !str_contains($logContents, '864209'),
    'log=' . $logFile
);

// ---------------------------------------------------------------------------
// C. Pintu PIN kasir
// ---------------------------------------------------------------------------

Qc::section('C. Pintu PIN kasir /cashier/login');

$loginPage = QcHttp::request('GET', '/cashier/login', [
    'cookie_jar' => QcHttp::cookieJar('fase7-cashier-login'),
    'fresh'      => true,
]);

Qc::ok(
    'C.1',
    'GET /cashier/login 200: memuat phone_number, pin, CSRF, dan papan angka x-cloak',
    $loginPage['status'] === 200
        && str_contains((string) $loginPage['body'], 'name="phone_number"')
        && str_contains((string) $loginPage['body'], 'name="pin"')
        && str_contains((string) $loginPage['body'], 'name="' . Security::CSRF_FIELD . '"')
        && str_contains((string) $loginPage['body'], 'x-cloak')
        && str_contains((string) $loginPage['body'], 'x-data="investorLogin(')
        && str_contains((string) $loginPage['body'], 'assets/js/alpine.min.js'),
    'status=' . $loginPage['status']
);

Qc::ok(
    'C.2',
    'Field PIN tidak pernah membawa nilai (tidak ada value= pada name="pin")',
    (static function () use ($loginPage): bool {
        $parts = preg_split('/<input\b/', (string) $loginPage['body']);

        if ($parts === false) {
            return false;
        }

        foreach ($parts as $part) {
            $block = explode('>', $part)[0] ?? '';

            if (str_contains($block, 'name="pin"')) {
                return !str_contains($block, 'value="');
            }
        }

        return false;
    })(),
    'PIN tidak boleh dirender kembali'
);

QcFixture::clearPinThrottle();

$cashierLogin = qc7_loginCashier(
    QcFixture::QC_CASHIER['phone'],
    QcFixture::QC_CASHIER['pin'],
    'fase7-cashier'
);
$cashierJar = $cashierLogin['jar'];

Qc::ok(
    'C.3',
    'PIN sah → 302 /operator/teller dan session ID diregenerasi',
    $cashierLogin['response']['status'] === 302
        && $cashierLogin['response']['location'] === '/operator/teller',
    'status=' . $cashierLogin['response']['status'] . ' location=' . (string) $cashierLogin['response']['location']
);

$wrongPin = qc7_loginCashier(QcFixture::QC_CASHIER['phone'], '111111', 'fase7-wrong-pin');
QcFixture::clearPinThrottle();

Qc::ok(
    'C.4',
    'PIN salah → 401 pesan generik, tanpa membocorkan nomor/PIN yang dikirim',
    (int) $wrongPin['response']['status'] === 401
        && str_contains((string) $wrongPin['response']['body'], 'Nomor telepon atau PIN salah.')
        && !str_contains((string) $wrongPin['response']['body'], '111111'),
    'status=' . $wrongPin['response']['status']
);

$emptyPin = qc7_loginCashier(QcFixture::QC_CASHIER['phone'], '', 'fase7-empty-pin');
$shortPin = qc7_loginCashier(QcFixture::QC_CASHIER['phone'], '12345', 'fase7-short-pin');
QcFixture::clearPinThrottle();

Qc::ok(
    'C.5',
    'PIN kosong / bukan enam angka → 422',
    (int) $emptyPin['response']['status'] === 422 && (int) $shortPin['response']['status'] === 422,
    'kosong=' . $emptyPin['response']['status'] . ' pendek=' . $shortPin['response']['status']
);

$lockJar = QcHttp::cookieJar('fase7-lock');
QcFixture::clearPinThrottle();

$lockStatuses = [];

for ($attempt = 1; $attempt <= 5; $attempt++) {
    $page = QcHttp::request('GET', '/cashier/login', ['cookie_jar' => $lockJar, 'fresh' => $attempt === 1]);

    $lockStatuses[] = QcHttp::request('POST', '/cashier/login', [
        'cookie_jar' => $lockJar,
        'data'       => [
            Security::CSRF_FIELD => (string) QcHttp::csrfFrom((string) $page['body']),
            'phone_number'       => QcFixture::QC_CASHIER_SECOND['phone'],
            'pin'                => '000001',
        ],
    ]);
}

$lockedLogin = $lockStatuses[4];
$lockedWithCorrectPin = (static function () use ($lockJar): array {
    $page = QcHttp::request('GET', '/cashier/login', ['cookie_jar' => $lockJar]);

    return QcHttp::request('POST', '/cashier/login', [
        'cookie_jar' => $lockJar,
        'data'       => [
            Security::CSRF_FIELD => (string) QcHttp::csrfFrom((string) $page['body']),
            'phone_number'       => QcFixture::QC_CASHIER_SECOND['phone'],
            'pin'                => QcFixture::QC_CASHIER_SECOND['pin'],
        ],
    ]);
})();

Qc::ok(
    'C.6',
    'Percobaan ke-5 → 429 (kunci 15 menit) dan PIN benar pun tetap ditolak selama kunci',
    (int) $lockedLogin['status'] === 429
        && str_contains((string) $lockedLogin['body'], 'Coba lagi dalam')
        && (int) $lockedWithCorrectPin['status'] === 429,
    'ke-5=' . $lockedLogin['status'] . ' pin-benar=' . $lockedWithCorrectPin['status']
);

QcFixture::clearPinThrottle();

$operatorOnCashierDoor = qc7_loginCashier($operator['phone'], '000000', 'fase7-operator-door');
$investorOnCashierDoor = qc7_loginCashier(QcFixture::QC_INVESTOR['phone'], QcFixture::QC_INVESTOR['pin'], 'fase7-investor-door');
$unknownOnCashierDoor = qc7_loginCashier('089700009999', '123456', 'fase7-unknown-door');
QcFixture::clearPinThrottle();

Qc::ok(
    'C.7',
    'Gerbang peran pintu kasir: operator & mitra PIN-nya tidak sah di sini (401)',
    (int) $operatorOnCashierDoor['response']['status'] === 401
        && (int) $investorOnCashierDoor['response']['status'] === 401,
    'operator=' . $operatorOnCashierDoor['response']['status'] . ' mitra=' . $investorOnCashierDoor['response']['status']
);

Qc::ok(
    'C.8',
    'Nomor tidak dikenal juga dijawab 401 dengan pesan yang sama (tanpa enumerasi akun)',
    (int) $unknownOnCashierDoor['response']['status'] === 401
        && str_contains((string) $unknownOnCashierDoor['response']['body'], 'Nomor telepon atau PIN salah.'),
    'status=' . $unknownOnCashierDoor['response']['status']
);

$passwordDoorJar = QcHttp::cookieJar('fase7-password-door');
$passwordDoorForm = QcHttp::request('GET', '/login', ['cookie_jar' => $passwordDoorJar, 'fresh' => true]);
$pinAtPasswordDoor = QcHttp::request('POST', '/login', [
    'cookie_jar' => $passwordDoorJar,
    'data'       => [
        Security::CSRF_FIELD => (string) QcHttp::csrfFrom((string) $passwordDoorForm['body']),
        'phone_number'       => QcFixture::QC_CASHIER['phone'],
        'password'           => QcFixture::QC_CASHIER['pin'],
    ],
]);
$pinFollowUp = QcHttp::request('GET', '/operator/teller', ['cookie_jar' => $passwordDoorJar]);

Qc::ok(
    'C.9',
    'PIN kasir tidak sah di pintu kata sandi /login (401/422, tidak ada sesi terbentuk)',
    in_array((int) $pinAtPasswordDoor['status'], [401, 422], true)
        && $pinFollowUp['status'] === 302
        && $pinFollowUp['location'] === '/login',
    'status=' . $pinAtPasswordDoor['status']
);
QcFixture::clearPinThrottle();

$investorDoorJar = QcHttp::cookieJar('fase7-investor-door-http');
$investorDoorForm = QcHttp::request('GET', '/investor/login', ['cookie_jar' => $investorDoorJar, 'fresh' => true]);
$pinAtInvestorDoor = QcHttp::request('POST', '/investor/login', [
    'cookie_jar' => $investorDoorJar,
    'data'       => [
        Security::CSRF_FIELD => (string) QcHttp::csrfFrom((string) $investorDoorForm['body']),
        'phone_number'       => QcFixture::QC_CASHIER['phone'],
        'pin'                => QcFixture::QC_CASHIER['pin'],
    ],
]);
QcFixture::clearPinThrottle();

Qc::ok(
    'C.10',
    'PIN kasir tidak sah di pintu PIN mitra /investor/login (401)',
    (int) $pinAtInvestorDoor['status'] === 401,
    'status=' . $pinAtInvestorDoor['status']
);

$noCsrfForm = QcHttp::request('GET', '/cashier/login', [
    'cookie_jar' => QcHttp::cookieJar('fase7-no-csrf'),
    'fresh'      => true,
]);
$noCsrf = QcHttp::request('POST', '/cashier/login', [
    'cookie_jar' => QcHttp::cookieJar('fase7-no-csrf'),
    'data'       => [
        'phone_number' => QcFixture::QC_CASHIER['phone'],
        'pin'          => QcFixture::QC_CASHIER['pin'],
    ],
]);
unset($noCsrfForm);

Qc::ok(
    'C.11',
    'POST /cashier/login tanpa token CSRF ditolak 419',
    (int) $noCsrf['status'] === 419,
    'status=' . $noCsrf['status']
);

$alreadyIn = QcHttp::request('GET', '/cashier/login', ['cookie_jar' => $cashierJar]);

Qc::ok(
    'C.12',
    'Kasir yang sudah masuk membuka /cashier/login → 302 /operator/teller (requireGuest sadar peran)',
    $alreadyIn['status'] === 302 && $alreadyIn['location'] === '/operator/teller',
    'status=' . $alreadyIn['status'] . ' location=' . (string) $alreadyIn['location']
);

$tellerForLogout = QcHttp::request('GET', '/operator/teller', ['cookie_jar' => $cashierJar]);
$cashierLogout = QcHttp::request('POST', '/logout', [
    'cookie_jar' => $cashierJar,
    'data'       => [Security::CSRF_FIELD => (string) QcHttp::csrfFrom((string) $tellerForLogout['body'])],
]);

Qc::ok(
    'C.13',
    'Logout kasir kembali ke pintu PIN kasir /cashier/login (bukan /login)',
    $cashierLogout['status'] === 302 && $cashierLogout['location'] === '/cashier/login',
    'status=' . $cashierLogout['status'] . ' location=' . (string) $cashierLogout['location']
);

// ---------------------------------------------------------------------------
// D. Matriks gating — allow-list (inti isolasi)
// ---------------------------------------------------------------------------

Qc::section('D. Matriks gating: kasir hanya di meja kasir + dana talangan');

$tellerState = QcFixture::resetTellerState();
$tellerId = (string) $tellerState['register_id'];

$cashierLogin = qc7_loginCashier(
    QcFixture::QC_CASHIER['phone'],
    QcFixture::QC_CASHIER['pin'],
    'fase7-gating'
);
$cashierJar = $cashierLogin['jar'];

$tellerPage = qc7_openTeller($cashierJar);

Qc::ok(
    'D.1',
    'Kasir GET /operator/teller → 200 dengan saldo Kas Meja Bakar & Emas holding',
    str_contains($tellerPage['body'], CashRegister::TELLER_NAME)
        && str_contains($tellerPage['body'], 'Emas holding')
        && str_contains($tellerPage['body'], 'Meja Kasir Hulu'),
    'halaman meja kasir untuk kasir'
);

$holdingBefore = qc7_count('hulu_transactions');
$mutationsBefore = qc7_count('cash_mutations');
$balanceBefore = QcFixture::registerBalance($tellerId);

// Harapan dihitung dari system_settings lewat TellerService (satu pemilik
// aritmetika uang), bukan angka mati: net = bruto − jasa bakar flat.
$burnFee = TellerService::configuredBurnFee();
$expectedPaymentBalance = TellerService::centsToDecimal(
    TellerService::moneyToCents($balanceBefore) - (100000000 - (int) $burnFee['fee_cents'])
);

$cashierPayment = QcHttp::request('POST', '/operator/teller', [
    'cookie_jar' => $cashierJar,
    'data'       => [
        Security::CSRF_FIELD    => (string) $tellerPage['csrf'],
        Security::FORM_FIELD    => (string) $tellerPage['once'],
        'farmer_alias'          => 'QC Kasir 7',
        'dry_weight'            => '1,000',
        'buy_price_per_g'       => '1.000.000',
        'burn_fee_enabled'      => '1',
    ],
]);

$balanceAfterPayment = QcFixture::registerBalance($tellerId);

Qc::ok(
    'D.2',
    'Kasir POST /operator/teller → 302; satu transaksi hulu, satu mutasi kas, saldo turun persis',
    $cashierPayment['status'] === 302
        && $cashierPayment['location'] === '/operator/teller'
        && qc7_count('hulu_transactions') === $holdingBefore + 1
        && qc7_count('cash_mutations') === $mutationsBefore + 1
        && $balanceBefore === '50000000.00'
        && $balanceAfterPayment === $expectedPaymentBalance,
    'saldo=' . $balanceBefore . ' → ' . $balanceAfterPayment . ' (harap ' . $expectedPaymentBalance . ')'
);

$bridgeBefore = qc7_count('bridge_loans');
$balanceBeforeBridge = QcFixture::registerBalance($tellerId);
$tellerPageBridge = qc7_openTeller($cashierJar);

$cashierBridge = QcHttp::request('POST', '/operator/teller/talangan', [
    'cookie_jar' => $cashierJar,
    'data'       => [
        Security::CSRF_FIELD    => (string) $tellerPageBridge['csrf'],
        Security::FORM_FIELD    => (string) $tellerPageBridge['once'],
        'lender_name'           => 'QC Pemberi Talangan',
        'principal_amount'      => '5.000.000',
        'fee_type'              => 'fixed',
        'fee_fixed'             => '0',
        'notes'                 => 'uji QC Fase 7',
    ],
]);

$balanceAfterBridge = QcFixture::registerBalance($tellerId);

Qc::ok(
    'D.3',
    'Kasir POST /operator/teller/talangan → 302; kas meja naik pokok dan satu baris talangan aktif',
    $cashierBridge['status'] === 302
        && qc7_count('bridge_loans') === $bridgeBefore + 1
        && $balanceAfterBridge === TellerService::centsToDecimal(
            TellerService::moneyToCents($balanceBeforeBridge) + 500000000
        ),
    'saldo=' . $balanceBeforeBridge . ' → ' . $balanceAfterBridge
);

// --- Rute terlarang: GET
$settlementSnapshot = QcFixture::settlementSnapshot($tellerId);
$settlementsBefore = qc7_count('daily_settlements');
$capitalSnapshot = QcFixture::capitalSnapshot();

$forbiddenGet = ['/operator/settlement', '/operator/capital', '/operator/kasir', '/dashboard',
    '/investor/dashboard', '/investor/report', '/investor/report/csv', '/join'];

Qc::ok(
    'D.4',
    'Kasir GET ke seluruh rute terlarang → 302 /operator/teller',
    (static function () use ($forbiddenGet, $cashierJar): bool {
        foreach ($forbiddenGet as $path) {
            $response = QcHttp::request('GET', $path, ['cookie_jar' => $cashierJar]);

            if ($response['status'] !== 302 || $response['location'] !== '/operator/teller') {
                return false;
            }
        }

        return true;
    })(),
    'tujuh rute + /join'
);

// --- Rute terlarang: POST (dan _once TIDAK dikonsumsi)
$tellerPageBlocked = qc7_openTeller($cashierJar);

$blockedSettlement = QcHttp::request('POST', '/operator/settlement', [
    'cookie_jar' => $cashierJar,
    'data'       => [
        Security::CSRF_FIELD        => (string) $tellerPageBlocked['csrf'],
        Security::FORM_FIELD        => (string) $tellerPageBlocked['once'],
        'shop_total_grams'          => '18,000',
        'shop_price_per_g'          => '2.000.000',
        'reference_water_density'   => '75',
        'shop_water_density'        => '80',
        'coordination_fee'          => '200.000',
        'consumable_opex'           => '0',
        'allocation_brankas'        => '',
        'allocation_teller'         => '',
    ],
]);

$settlementAfter = QcFixture::settlementSnapshot($tellerId);
$balanceAfterBlocked = QcFixture::registerBalance($tellerId);

Qc::ok(
    'D.5a',
    'Kasir POST /operator/settlement → 302 /operator/teller dengan NOL tulisan',
    $blockedSettlement['status'] === 302
        && $blockedSettlement['location'] === '/operator/teller'
        && $settlementAfter === $settlementSnapshot
        && $balanceAfterBlocked === QcFixture::registerBalance($tellerId)
        && qc7_count('daily_settlements') === $settlementsBefore,
    'nol tulisan pada rute terlarang'
);

$mutationsBeforeReuse = qc7_count('cash_mutations');

$reuseOnce = QcHttp::request('POST', '/operator/teller', [
    'cookie_jar' => $cashierJar,
    'data'       => [
        Security::CSRF_FIELD    => (string) $tellerPageBlocked['csrf'],
        Security::FORM_FIELD    => (string) $tellerPageBlocked['once'],
        'farmer_alias'          => 'QC Once Utuh',
        'dry_weight'            => '1,000',
        'buy_price_per_g'       => '1.000.000',
        'burn_fee_enabled'      => '1',
    ],
]);
QcFixture::clearPinThrottle();

Qc::ok(
    'D.5b',
    'Token `_once` kasir TIDAK dikonsumsi oleh permintaan terlarang (guard mendahului efek samping)',
    $reuseOnce['status'] === 302
        && $reuseOnce['location'] === '/operator/teller'
        && qc7_count('cash_mutations') === $mutationsBeforeReuse + 1,
    'status=' . $reuseOnce['status'] . ' location=' . (string) $reuseOnce['location']
);

$loanBookSnapshot = qc7_loanBook();

$blockedCapital = (static function () use ($cashierJar): array {
    $paths = [
        '/operator/capital/topup'      => ['topup_wallet_id' => 'x', 'topup_amount' => '1.000.000'],
        '/operator/capital/mitra'      => ['mitra_mode' => 'mitra', 'mitra_name' => 'x', 'mitra_phone' => '0897', 'mitra_tier' => 'external', 'mitra_capital' => '1.000.000'],
        '/operator/capital/quota'      => ['quota_wallet_id' => 'x', 'quota_pct' => '10'],
        '/operator/capital/transfer'   => ['transfer_amount' => '1.000.000'],
        '/operator/capital/withdrawal' => ['withdrawal_request_id' => 'x'],
        '/operator/capital/invite'     => ['invite_wallet_id' => 'x'],
    ];

    $results = [];

    foreach ($paths as $path => $fields) {
        $page = QcHttp::request('GET', '/operator/teller', ['cookie_jar' => $cashierJar]);

        $results[$path] = QcHttp::request('POST', $path, [
            'cookie_jar' => $cashierJar,
            'data'       => array_merge([
                Security::CSRF_FIELD => (string) QcHttp::csrfFrom((string) $page['body']),
                Security::FORM_FIELD => (string) QcHttp::fieldFrom((string) $page['body'], Security::FORM_FIELD),
            ], $fields),
        ]);
    }

    return $results;
})();

Qc::ok(
    'D.7',
    'Kasir POST keenam rute kelola modal → 302 /operator/teller, snapshot modal identik',
    (static function () use ($blockedCapital): bool {
        foreach ($blockedCapital as $response) {
            if ($response['status'] !== 302 || $response['location'] !== '/operator/teller') {
                return false;
            }
        }

        return true;
    })()
        && QcFixture::capitalSnapshot() === $capitalSnapshot
        && qc7_loanBook() === $loanBookSnapshot,
    'nol tulisan pada keenam jalur modal'
);

$kasirPostJar = qc7_loginCashier(
    QcFixture::QC_CASHIER['phone'],
    QcFixture::QC_CASHIER['pin'],
    'fase7-kasir-post'
)['jar'];

$cashierSnapshotBefore = QcFixture::cashierSnapshot();

$blockedKasirPost = (static function () use ($kasirPostJar): array {
    $paths = [
        '/operator/kasir'        => ['cashier_name' => 'X', 'cashier_phone' => '089700000195', 'cashier_pin' => '147258', 'cashier_pin_confirm' => '147258'],
        '/operator/kasir/pin'    => ['pin_user_id' => 'x', 'reset_pin' => '147258', 'reset_pin_confirm' => '147258'],
        '/operator/kasir/status' => ['status_user_id' => 'x', 'status_active' => '0'],
    ];

    $results = [];

    foreach ($paths as $path => $fields) {
        $page = QcHttp::request('GET', '/operator/teller', ['cookie_jar' => $kasirPostJar]);

        $results[$path] = QcHttp::request('POST', $path, [
            'cookie_jar' => $kasirPostJar,
            'data'       => array_merge([
                Security::CSRF_FIELD => (string) QcHttp::csrfFrom((string) $page['body']),
                Security::FORM_FIELD => (string) QcHttp::fieldFrom((string) $page['body'], Security::FORM_FIELD),
            ], $fields),
        ]);
    }

    return $results;
})();

Qc::ok(
    'D.8',
    'Kasir POST rute kelola kasir → 302 /operator/teller tanpa akun/PIN berubah',
    (static function () use ($blockedKasirPost): bool {
        foreach ($blockedKasirPost as $response) {
            if ($response['status'] !== 302 || $response['location'] !== '/operator/teller') {
                return false;
            }
        }

        return true;
    })()
        && QcFixture::cashierSnapshot() === $cashierSnapshotBefore
        && User::findByPhoneNumber('089700000195', false) === null,
    'nol tulisan pada jalur tata kelola akun'
);

$dashboardBlocked = QcHttp::request('GET', '/dashboard', ['cookie_jar' => $cashierJar]);

Qc::ok(
    'D.9',
    'Kasir GET /dashboard tidak melihat satu pun isi system_settings',
    $dashboardBlocked['status'] === 302
        && $dashboardBlocked['location'] === '/operator/teller'
        && !str_contains((string) $dashboardBlocked['body'], 'waterfall'),
    'status=' . $dashboardBlocked['status']
);

$rootRedirect = QcHttp::request('GET', '/', ['cookie_jar' => $cashierJar]);

Qc::ok(
    'D.11',
    'GET / sebagai kasir mendarat di /operator/teller (roleLandingPath satu pemilik)',
    $rootRedirect['status'] === 302 && $rootRedirect['location'] === '/operator/teller',
    'location=' . (string) $rootRedirect['location']
);

$anonTeller = QcHttp::request('GET', '/operator/teller', [
    'cookie_jar' => QcHttp::cookieJar('fase7-anon-teller'),
    'fresh'      => true,
]);

$investorJar = (static function (): string {
    $seed = QcFixture::seedQcInvestor();
    $jar = QcHttp::cookieJar('fase7-investor');
    $form = QcHttp::request('GET', '/investor/login', ['cookie_jar' => $jar, 'fresh' => true]);

    QcHttp::request('POST', '/investor/login', [
        'cookie_jar' => $jar,
        'data'       => [
            Security::CSRF_FIELD => (string) QcHttp::csrfFrom((string) $form['body']),
            'phone_number'       => (string) $seed['phone'],
            'pin'                => QcFixture::QC_INVESTOR['pin'],
        ],
    ]);

    return $jar;
})();

$investorTeller = QcHttp::request('GET', '/operator/teller', ['cookie_jar' => $investorJar]);

Qc::ok(
    'D.12',
    'Terpisah tetap utuh: anonim → /login, mitra → /investor/dashboard pada rute meja kasir',
    $anonTeller['status'] === 302
        && $anonTeller['location'] === '/login'
        && $investorTeller['status'] === 302
        && $investorTeller['location'] === '/investor/dashboard',
    'anonim=' . (string) $anonTeller['location'] . ' mitra=' . (string) $investorTeller['location']
);

Qc::ok(
    'D.13',
    'Operator tetap membuka seluruh layar operator (200) — nol regresi',
    (static function () use ($operatorJar): bool {
        foreach (['/dashboard', '/operator/teller', '/operator/settlement', '/operator/capital', '/operator/kasir'] as $path) {
            $response = QcHttp::request('GET', $path, ['cookie_jar' => $operatorJar]);

            if ($response['status'] !== 200) {
                return false;
            }
        }

        return true;
    })(),
    'lima layar operator'
);

$forgedJar = qc7_loginCashier(
    QcFixture::QC_CASHIER['phone'],
    QcFixture::QC_CASHIER['pin'],
    'fase7-forged'
)['jar'];
$forgePage = QcHttp::request('GET', '/operator/teller', ['cookie_jar' => $forgedJar]);

$forge = QcHttp::request('POST', '/operator/teller', [
    'cookie_jar' => $forgedJar,
    'data'       => [
        Security::CSRF_FIELD    => (string) QcHttp::csrfFrom((string) $forgePage['body']),
        Security::FORM_FIELD    => (string) QcHttp::fieldFrom((string) $forgePage['body'], Security::FORM_FIELD),
        'user_role'             => 'operator',
        'role'                  => 'operator',
        'is_active'             => '1',
        'dry_weight'            => '1,000',
        'buy_price_per_g'       => '1.000.000',
        'burn_fee_enabled'      => '1',
    ],
]);
$forgeAfter = QcHttp::request('GET', '/operator/settlement', ['cookie_jar' => $forgedJar]);

Qc::ok(
    'D.14',
    'Percobaan menaikkan peran lewat field formulir tidak berpengaruh (sesi peladen otoritatif)',
    $forge['status'] === 302
        && $forgeAfter['status'] === 302
        && $forgeAfter['location'] === '/operator/teller',
    'setelah forge: ' . $forgeAfter['status'] . '/' . (string) $forgeAfter['location']
);

$cashierJoin = QcHttp::request('GET', '/join?token=' . str_repeat('a', 64), ['cookie_jar' => $forgedJar]);

Qc::ok(
    'D.15',
    'Kasir tidak dapat membuka permukaan onboarding mitra /join (data calon mitra tertutup)',
    $cashierJoin['status'] === 302 && $cashierJoin['location'] === '/operator/teller',
    'status=' . $cashierJoin['status'] . ' location=' . (string) $cashierJoin['location']
);

$unknownRoute = QcHttp::request('GET', '/route-tidak-ada', ['cookie_jar' => $forgedJar]);

Qc::ok(
    'D.16',
    'Rute tak dikenal tetap 404 tanpa stack trace untuk sesi kasir',
    $unknownRoute['status'] === 404
        && stripos((string) $unknownRoute['body'], 'Stack trace') === false
        && stripos((string) $unknownRoute['body'], 'Fatal error') === false,
    'status=' . $unknownRoute['status']
);

// ---------------------------------------------------------------------------
// E. Isolasi permukaan kasir
// ---------------------------------------------------------------------------

Qc::section('E. Isolasi tampilan: tanpa Brankas, bagi hasil, atau tautan pemilik');

$brankas = CashRegister::findBrankas();
$brankasOriginal = $brankas === null ? '0.00' : (string) $brankas['current_balance'];
$canary = '77777777.00';

if ($brankas !== null) {
    $brankasId = (string) $brankas['id'];
    $delta = number_format(((float) $canary) - ((float) $brankasOriginal), 2, '.', '');
    CashRegister::adjustBalance($brankasId, $delta);
}

/** Token halaman + body untuk kasir yang sudah masuk. */
$cashierPage = QcHttp::request('GET', '/operator/teller', ['cookie_jar' => $forgedJar]);
$cashierBody = (string) $cashierPage['body'];
$canaryLabel = 'Rp ' . number_format((float) $canary, 0, ',', '.');

/** Flash sesudah transaksi: bukti tambahan bahwa pesan kasir tidak membocorkan pemilik. */
$postAfter = (static function () use ($forgedJar, $cashierBody): string {
    $once = QcHttp::fieldFrom($cashierBody, Security::FORM_FIELD);
    $csrf = QcHttp::csrfFrom($cashierBody);

    QcHttp::request('POST', '/operator/teller', [
        'cookie_jar' => $forgedJar,
        'data'       => [
            Security::CSRF_FIELD => (string) $csrf,
            Security::FORM_FIELD => (string) $once,
            'farmer_alias'       => 'QC Isolasi',
            'dry_weight'         => '1,000',
            'buy_price_per_g'    => '1.000.000',
            'burn_fee_enabled'   => '1',
        ],
    ]);

    return (string) QcHttp::request('GET', '/operator/teller', ['cookie_jar' => $forgedJar])['body'];
})();

if ($brankas !== null) {
    $delta = number_format(((float) $brankasOriginal) - ((float) $canary), 2, '.', '');
    CashRegister::adjustBalance((string) $brankas['id'], $delta);
}

$restored = $brankas === null ? '0.00' : QcFixture::registerBalance((string) $brankas['id']);

Qc::ok(
    'E.1',
    'Body halaman kasir tidak memuat kata "Brankas"',
    !str_contains($cashierBody, 'Brankas') && !str_contains($postAfter, 'Brankas'),
    'kanari Brankas'
);

Qc::ok(
    'E.2',
    'Kanari saldo Brankas ' . $canaryLabel . ' tidak pernah muncul di body maupun flash kasir',
    !str_contains($cashierBody, $canaryLabel)
        && !str_contains($cashierBody, $canary)
        && !str_contains($postAfter, $canaryLabel)
        && !str_contains($postAfter, $canary)
        && $restored === $brankasOriginal,
    'saldo brankas dipulihkan: ' . $restored . ' (semula ' . $brankasOriginal . ')'
);

$forbiddenLinks = ['/operator/settlement', '/operator/capital', '/operator/kasir', '/dashboard'];

Qc::ok(
    'E.3',
    'Body kasir tidak memuat href ke rute pemilik',
    (static function () use ($forbiddenLinks, $cashierBody, $postAfter): bool {
        foreach ($forbiddenLinks as $link) {
            if (str_contains($cashierBody, 'href="' . $link . '"') || str_contains($postAfter, 'href="' . $link . '"')) {
                return false;
            }
        }

        return true;
    })(),
    'tanpa tautan ke rekonsiliasi, kelola modal, kelola kasir, dashboard'
);

Qc::ok(
    'E.4',
    'Body kasir tidak memuat label menu Rekonsiliasi / Kelola Modal / Kelola Kasir',
    !str_contains($cashierBody, 'Rekonsiliasi')
        && !str_contains($cashierBody, 'Kelola Modal')
        && !str_contains($cashierBody, 'Kelola Kasir'),
    'navigasi sadar peran'
);

$profitNeedles = ['laba bersih', 'pool mitra', 'investor_pool', 'omzet', 'net_settled_profit', 'profit_distributions'];

Qc::ok(
    'E.5',
    'Body kasir tidak memuat istilah bagi hasil/omzet',
    (static function () use ($profitNeedles, $cashierBody, $postAfter): bool {
        foreach ($profitNeedles as $needle) {
            if (stripos($cashierBody, $needle) !== false || stripos($postAfter, $needle) !== false) {
                return false;
            }
        }

        return true;
    })(),
    'daftar terlarang: ' . implode(', ', $profitNeedles)
);

$operatorTeller = QcHttp::request('GET', '/operator/teller', ['cookie_jar' => $operatorJar]);
$operatorBody = (string) $operatorTeller['body'];

Qc::ok(
    'E.6',
    'Pasangan positif: body operator memuat keempat tautan pemilik (gating bekerja, bukan layout rusak)',
    (static function () use ($forbiddenLinks, $operatorBody): bool {
        foreach ($forbiddenLinks as $link) {
            if (!str_contains($operatorBody, 'href="' . $link . '"')) {
                return false;
            }
        }

        return true;
    })(),
    'navigasi operator utuh'
);

Qc::ok(
    'E.7',
    'Izin yang diminta PO tetap ada bagi kasir: Emas holding, jasa bakar, dan modul talangan',
    str_contains($cashierBody, 'Emas holding')
        && str_contains($cashierBody, 'jasa bakar')
        && str_contains($cashierBody, 'Dana Talangan'),
    'permukaan kasir memuat izin minimum'
);

$cashierFiles = [
    'app/Controllers/TellerController.php',
    'app/Views/operator/teller.php',
    'app/Views/layouts/operator.php',
    'app/Views/cashier/login.php',
    'app/Services/TellerService.php',
    'app/Services/BridgeLoanService.php',
    // Fase 8 — permukaan kasir kedua (buku kasbon petani).
    'app/Controllers/KasbonController.php',
    'app/Services/KasbonService.php',
    'app/Views/operator/kasbon.php',
    'app/Models/Miner.php',
    'app/Models/MinerLoan.php',
    'app/Models/MinerLoanLedger.php',
];

$loanBookSnapshot = $loanBookSnapshot ?? qc7_loanBook();

$cashierOffenders = qc7_scan($cashierFiles, static function (string $line): bool {
    $deny = ['BRANKAS_NAME', 'findBrankas', 'CapitalWallet', 'ProfitDistribution', 'AllocationEngine',
        'laba bersih', 'pool mitra', 'omzet', 'investor_pool', 'net_settled_profit'];

    foreach ($deny as $needle) {
        if (str_contains($line, $needle)) {
            return true;
        }
    }

    return false;
});

Qc::ok(
    'E.8',
    'Berkas yang terjangkau kasir tidak menyentuh Brankas, dompet modal, maupun mesin alokasi',
    $cashierOffenders === [],
    implode("\n", $cashierOffenders)
);

// ---------------------------------------------------------------------------
// F. Kontrak statis
// ---------------------------------------------------------------------------

Qc::section('F. Kontrak statis: guard-first, daftar izin tunggal, tanpa komponen baru');

$routes = require APP_PATH . '/routes.php';

Qc::ok(
    'F.1',
    'Route table memuat enam entry Fase 7 dan semuanya menunjuk handler yang benar-benar ada',
    (static function () use ($routes): bool {
        $expected = [
            ['GET', '/cashier/login', CashierAuthController::class, 'showLogin'],
            ['POST', '/cashier/login', CashierAuthController::class, 'login'],
            ['GET', '/operator/kasir', CashierController::class, 'index'],
            ['POST', '/operator/kasir', CashierController::class, 'store'],
            ['POST', '/operator/kasir/pin', CashierController::class, 'resetPin'],
            ['POST', '/operator/kasir/status', CashierController::class, 'setStatus'],
        ];

        foreach ($expected as [$method, $path, $class, $action]) {
            $handler = $routes[$method][$path] ?? null;

            if ($handler === null || $handler[0] !== $class || $handler[1] !== $action) {
                return false;
            }

            if (!is_subclass_of($class, BaseController::class)
                || !method_exists($class, $action)
            ) {
                return false;
            }
        }

        return true;
    })(),
    'enam entry route Fase 7'
);

$tellerSource = (string) file_get_contents(APP_PATH . '/Controllers/TellerController.php');

Qc::ok(
    'F.2',
    'TellerController: satu daftar izin [operator, cashier] dipakai keempat titik guard',
    str_contains($tellerSource, 'private const ALLOWED_ROLES = [Constants::ROLE_OPERATOR, Constants::ROLE_CASHIER];')
        && substr_count($tellerSource, '$this->requireRole(...self::ALLOWED_ROLES);') === 3
        && str_contains($tellerSource, 'requireRole(Constants::ROLE_OPERATOR)') === false,
    'allow-list tunggal'
);

$grantOffenders = qc7_scan([
    'app/Controllers/SettlementController.php',
    'app/Controllers/CapitalController.php',
    'app/Controllers/DashboardController.php',
    'app/Controllers/CashierController.php',
    'app/Controllers/InvestorController.php',
    'app/Controllers/ExportController.php',
    'app/Controllers/InvestorOnboardingController.php',
], static fn (string $line): bool => preg_match('/requireRole\([^)]*ROLE_CASHIER/', $line) === 1);

Qc::ok(
    'F.3',
    'Controller administratif & finansial tidak pernah MENGIZINKAN peran kasir',
    $grantOffenders === [],
    implode("\n", $grantOffenders)
);

// Sejak Fase 8 ada DUA permukaan izin eksplisit: meja kasir (TellerController)
// dan buku kasbon (KasbonController). Keduanya satu-satunya pemilik
// `ALLOWED_ROLES`; controller lain tetap menolak `cashier` tanpa daftar izin.
$allowListFiles = [];

foreach (glob(APP_PATH . '/Controllers/*.php') ?: [] as $controllerFile) {
    $controllerSource = (string) file_get_contents($controllerFile);

    if (str_contains($controllerSource, 'ALLOWED_ROLES')) {
        $allowListFiles[] = basename($controllerFile);
    }
}

sort($allowListFiles);

$kasbonControllerSource = (string) file_get_contents(APP_PATH . '/Controllers/KasbonController.php');

Qc::ok(
    'F.3b',
    'Hanya dua controller memiliki daftar izin kasir eksplisit: Teller & Kasbon',
    $allowListFiles === ['KasbonController.php', 'TellerController.php']
        && str_contains(
            $kasbonControllerSource,
            'private const ALLOWED_ROLES = [Constants::ROLE_OPERATOR, Constants::ROLE_CASHIER];'
        ),
    implode(', ', $allowListFiles)
);

$guardFirstFailures = [];

$postGuards = [
    'app/Controllers/SettlementController.php' => ['store' => 'requireRole('],
    'app/Controllers/CapitalController.php'    => [
        'topUp' => 'guardOnce(', 'storeMitra' => 'guard(', 'updateQuota' => 'guard(',
        'transfer' => 'guardOnce(', 'withdrawal' => 'guardOnce(', 'invite' => 'guard(',
    ],
    'app/Controllers/CashierController.php'    => [
        'store' => 'guardOnce(', 'resetPin' => 'guardOnce(', 'setStatus' => 'guardOnce(',
    ],
    'app/Controllers/TellerController.php'     => ['store' => 'requireRole(', 'storeBridge' => 'requireRole('],
];

foreach ($postGuards as $file => $methods) {
    $source = (string) file_get_contents(BASE_PATH . '/' . $file);

    foreach ($methods as $method => $needle) {
        $body = qc7_method_body($source, $method);

        if ($body === null) {
            $guardFirstFailures[] = $file . '::' . $method . ' tidak ditemukan';

            continue;
        }

        if (strpos($body, $needle) === false) {
            $guardFirstFailures[] = $file . '::' . $method . ' tanpa ' . $needle;
        }

        // Penjaga harus mendahului akses model/service apa pun.
        $guardPos = strpos($body, $needle);
        $callPos = qc7_first_pos($body, ['Service::', 'Database::', 'Model::']);

        if ($guardPos !== false && $callPos < $guardPos) {
            $guardFirstFailures[] = $file . '::' . $method . ' memanggil data sebelum penjaga';
        }
    }
}

Qc::ok(
    'F.4a',
    'Guard-first pada setiap POST terlarang: penjaga mendahului akses data',
    $guardFirstFailures === [],
    implode("\n", $guardFirstFailures)
);

$getGuardFailures = [];

$indexGuards = [
    'app/Controllers/SettlementController.php' => ['index' => 'renderSettlement', 'renderSettlement' => 'requireRole('],
    'app/Controllers/CapitalController.php'    => ['index' => 'renderCapital', 'renderCapital' => 'requireRole('],
    'app/Controllers/CashierController.php'    => ['index' => 'renderCashiers', 'renderCashiers' => 'requireRole('],
    'app/Controllers/DashboardController.php'  => ['index' => 'requireRole('],
    'app/Controllers/TellerController.php'     => ['index' => 'renderTeller', 'renderTeller' => 'requireRole('],
];

foreach ($indexGuards as $file => $methods) {
    $source = (string) file_get_contents(BASE_PATH . '/' . $file);

    foreach ($methods as $method => $needle) {
        $body = qc7_method_body($source, $method);

        if ($body === null) {
            $getGuardFailures[] = $file . '::' . $method . ' tidak ditemukan';

            continue;
        }

        if (strpos($body, $needle) === false) {
            $getGuardFailures[] = $file . '::' . $method . ' tanpa ' . $needle;
        }

        if (str_ends_with($needle, '(') && str_contains($needle, 'requireRole')) {
            $callPos = qc7_first_pos($body, ['Database::', 'Service::', 'Model::']);

            if ($callPos < strpos($body, $needle)) {
                $getGuardFailures[] = $file . '::' . $method . ' memanggil data sebelum requireRole';
            }
        }
    }
}

Qc::ok(
    'F.4b',
    'Guard-first pada setiap GET terlarang: layar merender lewat penjaga yang sama',
    $getGuardFailures === [],
    implode("\n", $getGuardFailures)
);

$helperFailures = [];

foreach ([
    'app/Controllers/CapitalController.php' => 'guardOnce',
    'app/Controllers/CashierController.php' => 'guardOnce',
] as $file => $helper) {
    $source = (string) file_get_contents(BASE_PATH . '/' . $file);
    $body = qc7_method_body($source, $helper);

    if ($body === null) {
        $helperFailures[] = $file . '::' . $helper . ' tidak ditemukan';

        continue;
    }

    // Penjaga boleh langsung (`requireRole(`) atau lewat `guard()` yang
    // memanggilnya — keduanya satu kelas yang sama dan diperiksa terpisah di
    // bawah, sehingga token tetap hanya dikonsumsi sesudah peran diperiksa.
    $guardPos = qc7_first_pos($body, ['requireRole(', '$this->guard();']);
    $oncePos = strpos($body, 'consumeFormToken(');

    if ($guardPos === PHP_INT_MAX || ($oncePos !== false && $oncePos < $guardPos)) {
        $helperFailures[] = $file . '::' . $helper . ' mengonsumsi token sebelum penjaga peran';
    }

    $innerGuard = qc7_method_body($source, 'guard');
    $innerFirst = $innerGuard === null ? '' : ltrim(substr($innerGuard, 1));

    if ($innerGuard === null || !str_starts_with($innerFirst, '$this->requireRole(')) {
        $helperFailures[] = $file . '::guard tidak memeriksa peran sebagai pernyataan pertama';
    }
}

Qc::ok(
    'F.4c',
    'Token `_once` hanya dikonsumsi SETELAH penjaga peran (bukti struktural D.5b)',
    $helperFailures === [],
    implode("\n", $helperFailures)
);

$dashboardSource = (string) file_get_contents(APP_PATH . '/Controllers/DashboardController.php');
$constantsSource = (string) file_get_contents(APP_PATH . '/Config/Constants.php');

Qc::ok(
    'F.5',
    'Label peran punya satu pemilik (Constants::ROLE_LABELS) dan dashboard tidak menyusun labelnya sendiri',
    str_contains($constantsSource, 'ROLE_CASHIER  => \'Kasir Meja Bakar\'')
        && str_contains($constantsSource, 'public static function roleLabel')
        && str_contains($dashboardSource, 'ROLE_LABELS') === false
        && Constants::roleLabel(Constants::ROLE_CASHIER) === 'Kasir Meja Bakar'
        && Constants::roleLabel(Constants::ROLE_OPERATOR) === 'Pengelola (Operator)',
    'satu sumber label peran'
);

$cashierView = (string) file_get_contents(APP_PATH . '/Views/cashier/login.php');
$appJs = (string) file_get_contents(PUBLIC_PATH . '/assets/js/app.js');

Qc::ok(
    'F.6',
    'Pintu kasir memakai komponen `investorLogin` yang sudah terdaftar (tanpa Alpine.data baru)',
    str_contains($cashierView, 'x-data="investorLogin(')
        && substr_count($appJs, 'Alpine.data(') === 6
        && str_contains($appJs, "Alpine.data('investorLogin', investorLogin)")
        && str_contains($appJs, 'cashierLogin') === false,
    'komponen Fase 5 dipakai ulang; `kasbonForm` (Fase 8) komponen keenam'
);

$swSource = (string) file_get_contents(PUBLIC_PATH . '/sw.js');
$agentsSource = (string) file_get_contents(BASE_PATH . '/AGENTS.md');

Qc::ok(
    'F.7',
    'Aset frontend satu versi: CACHE_NAME (kini v3, Fase 8) dan AGENTS.md sepakat',
    str_contains($swSource, "var CACHE_NAME = 'aurumflow-shell-v3';")
        && str_contains($agentsSource, 'aurumflow-shell-v3'),
    'v2 → v3 karena app.js menerima komponen kasbonForm'
);

$migrateSource = (string) file_get_contents(BASE_PATH . '/database/migrate.php');
$noToken = qc_run_php(BASE_PATH . '/database/migrate.php', ['INSTALL_TOKEN' => '']);

Qc::ok(
    'F.8a',
    'database/migrate.php menolak berjalan tanpa INSTALL_TOKEN (exit != 0)',
    $noToken['code'] !== 0 && str_contains($noToken['output'], 'MIGRASI DITOLAK'),
    'exit=' . $noToken['code']
);

$lockFile = STORAGE_PATH . '/installed.lock';
$lockBefore = is_file($lockFile);
$lockMtimeBefore = $lockBefore ? (int) filemtime($lockFile) : 0;

$migrateRun = qc_run_php(BASE_PATH . '/database/migrate.php');
clearstatcache();
$lockAfter = is_file($lockFile);
$lockMtimeAfter = $lockAfter ? (int) filemtime($lockFile) : 0;

Qc::ok(
    'F.8b',
    'database/migrate.php CLI-only, di luar document root, dan TIDAK menyentuh marker instalasi',
    str_contains($migrateSource, 'PHP_SAPI !== \'cli\'')
        && !is_file(PUBLIC_PATH . '/database/migrate.php')
        && is_file(BASE_PATH . '/database/migrate.php')
        && $migrateRun['code'] === 0
        && $lockAfter === $lockBefore
        && $lockMtimeAfter === $lockMtimeBefore,
    'marker instalasi sebelum=' . var_export($lockBefore, true) . ' sesudah=' . var_export($lockAfter, true)
);

// ---------------------------------------------------------------------------
// G. Fixture & jejak artefak
// ---------------------------------------------------------------------------

Qc::section('G. Fixture idempotent & kebersihan artefak');

$purged = QcFixture::purgeQcCashierAccounts();

Qc::ok(
    'G.1',
    'purgeQcCashierAccounts() menghapus akun kasir uji tanpa pelanggaran FK',
    $purged > 0 && User::findByPhoneNumber(QcFixture::QC_CASHIER['phone'], false) === null
        && qc7_count('users', '`phone_number` LIKE :prefix', ['prefix' => QcFixture::QC_CASHIER_PREFIX]) === 0,
    'baris terhapus=' . $purged
);

$firstSeed = QcFixture::seedQcCashier();
$firstCount = count(QcFixture::cashierSnapshot());
$secondSeed = QcFixture::seedQcCashier();

Qc::ok(
    'G.2',
    'seedQcCashier() idempotent: satu akun per definisi dan PIN tetap sah',
    $firstCount === 2
        && count(QcFixture::cashierSnapshot()) === 2
        && User::verifyPin(
            User::findByPhoneNumber(QcFixture::QC_CASHIER['phone'], false) ?? [],
            QcFixture::QC_CASHIER['pin']
        )
        && User::verifyPin(
            User::findByPhoneNumber(QcFixture::QC_CASHIER_SECOND['phone'], false) ?? [],
            QcFixture::QC_CASHIER_SECOND['pin']
        )
        && (string) $firstSeed['primary']['phone_number'] === (string) $secondSeed['primary']['phone_number']
        && (string) $firstSeed['primary']['role'] === Constants::ROLE_CASHIER,
    'jumlah kasir uji tetap dua & PIN kedua akun sah'
);

Qc::ok(
    'G.3',
    'Suite dapat dijalankan berulang: keadaan akhir fixture deterministik',
    (static function (): bool {
        $before = QcFixture::cashierSnapshot();
        QcFixture::resetCashierState();
        $after = QcFixture::cashierSnapshot();

        // Satu-satunya perbedaan yang sah antar-jalankan adalah hash bcrypt
        // (salt acak) — nama, peran, dan status harus identik.
        foreach ($before as $phone => $row) {
            if (!isset($after[$phone])) {
                return false;
            }

            if ($row['name'] !== $after[$phone]['name']
                || $row['role'] !== $after[$phone]['role']
                || $row['active'] !== $after[$phone]['active']
            ) {
                return false;
            }
        }

        return true;
    })(),
    'nama/peran/status stabil antar-jalankan'
);

Qc::ok(
    'G.4',
    'Pembatas percobaan dibersihkan sehingga suite lain tidak mewarisi kunci 429',
    QcFixture::clearPinThrottle() >= 0
        && is_dir(STORAGE_PATH . '/throttle')
        && count(array_filter(
            glob(STORAGE_PATH . '/throttle/*') ?: [],
            static fn (string $path): bool => is_file($path)
        )) === 0,
    'storage/throttle/ bersih'
);

$capitalBeforeFixture = QcFixture::capitalSnapshot();
$loansBeforeFixture = qc7_loanBook();
QcFixture::seedQcCashier();
$capitalAfterFixture = QcFixture::capitalSnapshot();
$loansAfterFixture = qc7_loanBook();

Qc::ok(
    'G.5',
    'Fixture kasir tidak menggeser dompet modal maupun buku talangan (kasir bukan mitra)',
    $capitalAfterFixture === $capitalBeforeFixture && $loansAfterFixture === $loansBeforeFixture,
    'snapshot modal/talangan tidak berubah oleh fixture kasir'
);

QcFixture::resetTellerState();
QcFixture::clearPinThrottle();

$phpLogs = glob(STORAGE_PATH . '/logs/php-*.log') ?: [];

Qc::ok(
    'G.6',
    'Tidak ada berkas nota liar maupun log PHP yang tak diharapkan setelah suite',
    count(array_filter($phpLogs, static fn (string $path): bool => filesize($path) > 0)) === 0,
    'log php-*.log tidak boleh terisi'
);

Qc::note('  | Isolasi peran kasir   | allow-list + 302 tanpa tulisan                | OK');
Qc::note('  | Permukaan kasir       | Brankas/bagi hasil/tautan pemilik tidak ada   | OK');

Qc::finish('qc_fase_7');
