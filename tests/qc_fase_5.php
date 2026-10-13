<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Protokol QC Manual 5 — Portal Blind Investor, Waiver, & PWA (Fase 5)
|--------------------------------------------------------------------------
| Implementasi otomatis dari "Protokol QC Manual 5" pada dokumen
| docs/AurumFlow Roadmap and QC Documents.md (FASE 5).
|
| Cakupan:
|   P5.x  Protokol manual 5 (perilaku & angka seperti dokumen Roadmap)
|   A.    OnboardingService: token sekali pakai, kebijakan PIN, jejak audit
|   A2.   Pepper PIN gagal-tertutup (fail-closed) pada proses terpisah
|   B.    Pintu PIN mitra: bentuk field, pesan generik, pemisahan dari /login
|   C.    PinThrottle: ambang, kunci, pembersihan, penurunan mutu
|   D.    Isolasi blind: kanari tidak bocor, dompet lain tidak terlihat,
|         payload `wallet_id` asing diabaikan, `_once` ganda tanpa tulisan
|   E.    Matriks gating peran & beranda per peran
|   F.    Penarikan mandiri: dividen tanpa masa tunggu, modal H-7 hari kerja,
|         batas pengajuan terbuka, pencairan tetap milik operator
|   G.    Undangan sisi operator: terbit otomatis, putar ulang, status
|   H.    Halaman `/join` (Layar A): waiver, jejak audit, tautan sekali pakai
|   I.    Pemindaian statis & kontrak form/view investor
|   J.    PWA: manifest, service worker (tanpa cache HTML), ikon, offline
|   K.    Nol DDL & keselarasan dokumen
|
| PERINGATAN: suite ini MENULIS ke database yang ditunjuk `.env` (kolom
| onboarding `users`, `profit_distributions`, `daily_settlements`,
| `withdrawal_requests`) dan ke disk (`storage/throttle/`). Jangan arahkan ke
| database produksi. Konvensi exit code: 0 = lulus, 1 = gagal.
|
| Catatan implementasi: `APP_PIN_PEPPER` disuntikkan lewat `putenv()` SEBELUM
| harness dimuat, supaya suite berperilaku sama pada clone yang `.env`-nya
| belum memuat pepper (nilai environment proses selalu menang atas `.env`,
| lihat `app/Config/Env.php`).
*/

putenv('APP_PIN_PEPPER=qc-fase-5-pepper-0f2b1c');

require __DIR__ . '/bootstrap.php';

use AurumFlow\Config\Constants;
use AurumFlow\Config\Database;
use AurumFlow\Config\Env;
use AurumFlow\Helpers\Formatter;
use AurumFlow\Helpers\PinThrottle;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\CapitalWallet;
use AurumFlow\Models\User;
use AurumFlow\Models\WithdrawalRequest;
use AurumFlow\Services\CapitalService;
use AurumFlow\Services\InvestorService;
use AurumFlow\Services\OnboardingService;
use AurumFlow\Services\TellerService;

Qc::suite('Protokol QC Manual 5 — Portal Blind Investor, Waiver, & PWA (qc_fase_5.php)');

Qc::note('Sumber: docs/AurumFlow Roadmap and QC Documents.md — FASE 5');
Qc::note('Keputusan pengguna: dec-ef670a108317f925 (ekspor cetak+CSV, pintu PIN terpisah, throttle berbasis berkas).');
Qc::note('PERINGATAN: suite ini menulis ke DB pada .env dan ke storage/throttle/.');

// ---------------------------------------------------------------------------
// Helper lokal
// ---------------------------------------------------------------------------

/**
 * Pemindai statis untuk sekumpulan berkas tertentu (pola qc2_scan/qc3_scan).
 *
 * @param list<string> $relativePaths
 * @return list<string> pelanggaran dalam bentuk `berkas:baris  isi`
 */
function qc5_scan(array $relativePaths, callable $predicate): array
{
    $offenders = [];

    foreach ($relativePaths as $relative) {
        $path = BASE_PATH . '/' . $relative;

        if (!is_file($path)) {
            $offenders[] = $relative . ' (berkas tidak ditemukan)';

            continue;
        }

        $lines = preg_split('/\R/', (string) file_get_contents($path));

        if ($lines === false) {
            continue;
        }

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            // Baris komentar dilewati: pemindai mencari KODE, bukan prosa.
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
 * Berkas yang menyentuh jalur investor — sasaran pemindaian isolasi.
 *
 * @return list<string>
 */
function qc5_investor_files(): array
{
    return [
        'app/Controllers/InvestorController.php',
        'app/Controllers/InvestorAuthController.php',
        'app/Controllers/InvestorOnboardingController.php',
        'app/Controllers/ExportController.php',
        'app/Services/InvestorService.php',
        'app/Views/investor/login.php',
        'app/Views/investor/onboarding.php',
        'app/Views/investor/dashboard.php',
        'app/Views/investor/report.php',
        'app/Views/layouts/investor.php',
        'app/Views/layouts/report.php',
    ];
}

/**
 * Seluruh berkas view (untuk pemindaian atribut Alpine).
 *
 * @return list<string> path relatif terhadap akar proyek
 */
function qc5_all_view_files(): array
{
    $files = [];

    foreach (['layouts', 'auth', 'dashboard', 'investor', 'operator', 'errors'] as $folder) {
        foreach (glob(VIEW_PATH . '/' . $folder . '/*.php') ?: [] as $path) {
            $files[] = 'app/Views/' . $folder . '/' . basename($path);
        }
    }

    return $files;
}

/**
 * Cari "kanari" (angka/nama internal) di dalam isi halaman.
 *
 * @return list<string> kanari yang ditemukan
 */
function qc5_findCanaries(string $haystack): array
{
    $canaries = QcFixture::QC_CANARIES;
    $found = [];
    $needles = [
        'omzet toko'      => 'Rp ' . number_format((float) $canaries['revenue'], 0, ',', '.'),
        'hak pengelola'   => 'Rp ' . number_format((float) $canaries['operator'], 0, ',', '.'),
        'laba bersih'     => 'Rp ' . number_format((float) $canaries['net'], 0, ',', '.'),
        'gramatur'        => '18,050',
        'alias penambang' => $canaries['alias'],
    ];

    foreach ($needles as $label => $needle) {
        if (str_contains($haystack, $needle)) {
            $found[] = $label . ' (' . $needle . ')';
        }
    }

    return $found;
}

/**
 * Nama dompet lain (selain dompet yang diberikan) — harus tidak pernah muncul.
 *
 * @return list<string>
 */
function qc5_foreignWallets(string $ownWalletId): array
{
    $names = [];

    foreach (CapitalWallet::listWithUser() as $wallet) {
        if ((string) $wallet['id'] === $ownWalletId) {
            continue;
        }

        $name = trim((string) $wallet['full_name']);

        if ($name !== '') {
            $names[] = $name;
        }
    }

    return $names;
}

/**
 * Login mitra lewat pintu PIN, mengembalikan cookie jar yang siap dipakai.
 *
 * @return array{jar: string, response: array<string, mixed>}
 */
function qc5_loginInvestor(string $phone, string $pin, string $name): array
{
    $jar = QcHttp::cookieJar($name);
    $form = QcHttp::request('GET', '/investor/login', ['cookie_jar' => $jar, 'fresh' => true]);

    $response = QcHttp::request('POST', '/investor/login', [
        'cookie_jar' => $jar,
        'data'       => [
            Security::CSRF_FIELD => (string) QcHttp::csrfFrom($form['body']),
            'phone_number'       => $phone,
            'pin'                => $pin,
        ],
    ]);

    return ['jar' => $jar, 'response' => $response];
}

// ---------------------------------------------------------------------------
// Persiapan
// ---------------------------------------------------------------------------

Qc::section('Persiapan: mitra aktif, calon mitra, empat putaran, dan kanari');

$investor = QcFixture::seedQcInvestor();
$invitee = QcFixture::seedQcInvitee();

$walletId = (string) $investor['wallet_id'];
$investorPhone = (string) $investor['phone'];
$investorPin = (string) $investor['pin'];
$inviteeToken = (string) $invitee['invite_token'];

Qc::ok(
    'P0.1',
    'Mitra QC Fase 5 siap: dompet Rp 20 juta, empat putaran, PIN aktif',
    $walletId !== ''
        && count($investor['rounds']) === 4
        && User::verifyPin(User::findByPhoneNumber($investorPhone) ?? [], $investorPin),
    'putaran=' . count($investor['rounds']) . ' saldo dividen=' . $investor['profit']
);

Qc::ok(
    'P0.2',
    'Calon mitra QC menunggu onboarding (tautan 64 heksadesimal, belum tanda tangan)',
    preg_match(OnboardingService::TOKEN_PATTERN, $inviteeToken) === 1
        && OnboardingService::findInvitee($inviteeToken) !== null,
    'token=' . substr($inviteeToken, 0, 8) . '…'
);

Qc::ok(
    'P0.3',
    'Kanari internal ada di basis data (omzet toko, hak pengelola, gramatur, alias penambang)',
    (int) (Database::fetchOne(
        'SELECT COUNT(*) AS `total` FROM `hulu_transactions` WHERE `farmer_alias` = :alias',
        ['alias' => QcFixture::QC_CANARIES['alias']]
    )['total'] ?? 0) > 0
    && (int) (Database::fetchOne('SELECT COUNT(*) AS `total` FROM `daily_settlements`')['total'] ?? 0) === 4,
    'empat sesi settlement berisi angka kanari'
);

// ---------------------------------------------------------------------------
// A. OnboardingService — kebijakan PIN, token sekali pakai, jejak audit
// ---------------------------------------------------------------------------

Qc::section('A. OnboardingService: undangan sekali pakai & kebijakan PIN');

Qc::equals(
    'A.1',
    'Tanpa undangan dikenali sebagai "Belum diundang"',
    'Belum diundang',
    OnboardingService::inviteStateLabel(OnboardingService::effectiveInviteState(
        ['onboarding_signed_at' => null, 'invite_token' => null]
    ))
);

Qc::equals(
    'A.2',
    'Token aktif tanpa tanda tangan dikenali sebagai "Menunggu onboarding"',
    'Menunggu onboarding',
    OnboardingService::inviteStateLabel(OnboardingService::effectiveInviteState(
        ['onboarding_signed_at' => null, 'invite_token' => str_repeat('a', 64)]
    ))
);

Qc::equals(
    'A.3',
    'Sudah tanda tangan dikenali sebagai "Aktif" meski token masih menempel',
    'Aktif',
    OnboardingService::inviteStateLabel(OnboardingService::effectiveInviteState(
        ['onboarding_signed_at' => '2026-10-01 10:00:00', 'invite_token' => str_repeat('a', 64)]
    ))
);

Qc::ok(
    'A.4',
    'Token sampah / pola salah tidak pernah menyentuh basis data',
    OnboardingService::findInvitee('') === null
        && OnboardingService::findInvitee('token-pendek') === null
        && OnboardingService::findInvitee(str_repeat('Z', 64)) === null,
    'pola wajib 64 heksadesimal huruf kecil'
);

$weakPins = [
    'panjang kurang' => '12345',
    'panjang lebih'  => '1234567',
    'bukan angka'    => 'abcdef',
    'seluruh sama'   => '111111',
    'naik lurus'     => '123456',
    'turun lurus'    => '654321',
    'blok berulang'  => '123123',
];

$weakAccepted = [];

foreach ($weakPins as $label => $pin) {
    try {
        OnboardingService::pinPolicy($pin, $pin);
        $weakAccepted[] = $label . '=' . $pin;
    } catch (\InvalidArgumentException) {
        // diharapkan
    }
}

Qc::ok(
    'P5.1',
    'PIN lemah & pola salah ditolak seluruhnya (kebijakan A-4 disetujui pemilik produk)',
    $weakAccepted === [],
    $weakAccepted === [] ? count($weakPins) . ' pola diuji, 0 diterima' : 'diterima: ' . implode(', ', $weakAccepted)
);

Qc::throws(
    'A.5',
    'Konfirmasi PIN yang berbeda ditolak',
    static fn () => OnboardingService::pinPolicy('246810', '246811'),
    \InvalidArgumentException::class
);

Qc::ok(
    'A.6',
    'PIN sah enam digit diterima',
    (static function (): bool {
        try {
            OnboardingService::pinPolicy('246810', '246810');
            OnboardingService::pinPolicy('135790', '135790');

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    })(),
    '246810 & 135790'
);

Qc::ok(
    'A.7',
    'Klausul waiver punya satu pemilik (sesuai PRD § 5.1)',
    str_contains(OnboardingService::waiverClause(), 'membebaskan Pengelola dari segala bentuk tuntutan ganti rugi')
        && str_contains(OnboardingService::waiverClause(), 'sektor informal')
        && OnboardingService::WAIVER_VERSION !== '',
    'versi ' . OnboardingService::WAIVER_VERSION
);

// Validasi terjadi SEBELUM penulisan: token tetap hidup, akun tetap belum aktif.
$beforePolicy = QcFixture::investorSnapshot((string) $invitee['phone']);
$policyWrote = false;

try {
    OnboardingService::claim($inviteeToken, ['accepted' => '1', 'pin' => '111111', 'pin_confirm' => '111111']);
    $policyWrote = true;
} catch (\InvalidArgumentException) {
    // diharapkan
}

$afterPolicy = QcFixture::investorSnapshot((string) $invitee['phone']);

Qc::ok(
    'A.8',
    'PIN lemah tidak menulis apa pun (token & jejak audit utuh)',
    !$policyWrote
        && $afterPolicy['user']['invite_token'] === $beforePolicy['user']['invite_token']
        && $afterPolicy['user']['onboarding_signed_at'] === null,
    'token tetap ' . substr((string) $afterPolicy['user']['invite_token'], 0, 8) . '…'
);

Qc::throws(
    'A.9',
    'Centang persetujuan wajib (tanpa centang ditolak)',
    static fn () => OnboardingService::claim(
        $inviteeToken,
        ['accepted' => '', 'pin' => '135790', 'pin_confirm' => '135790']
    ),
    \InvalidArgumentException::class
);

Qc::throws(
    'A.10',
    'Token yang tidak dikenal ditolak',
    static fn () => OnboardingService::claim(
        str_repeat('b', 64),
        ['accepted' => '1', 'pin' => '135790', 'pin_confirm' => '135790']
    ),
    \InvalidArgumentException::class
);

// Klaim sungguhan calon mitra.
$claim = OnboardingService::claim($inviteeToken, [
    'accepted'    => '1',
    'pin'         => (string) $invitee['pin'],
    'pin_confirm' => (string) $invitee['pin'],
]);

$afterClaim = QcFixture::investorSnapshot((string) $invitee['phone']);

Qc::ok(
    'P5.2',
    'Audit jejak hukum terisi & tautan hangus setelah tanda tangan',
    $afterClaim['user']['onboarding_signed_at'] !== null
        && $afterClaim['user']['invite_token'] === null
        && (string) $claim['phone_number'] === (string) $invitee['phone'],
    'signed_at=' . (string) $afterClaim['user']['onboarding_signed_at']
);

Qc::ok(
    'P5.3',
    'Tautan sekali pakai: klaim kedua dengan token yang sama ditolak',
    (static function () use ($invitee, $inviteeToken): bool {
        try {
            OnboardingService::claim($inviteeToken, [
                'accepted'    => '1',
                'pin'         => (string) $invitee['pin'],
                'pin_confirm' => (string) $invitee['pin'],
            ]);

            return false;
        } catch (\InvalidArgumentException) {
            return true;
        }
    })(),
    'klaim ulang tidak menulis apa pun'
);

Qc::ok(
    'A.11',
    'PIN disimpan ber-pepper: sah lewat verifyPin, TIDAK sah lewat verifyPassword',
    (static function () use ($invitee): bool {
        $user = User::findByPhoneNumber((string) $invitee['phone']);
        $pin = (string) $invitee['pin'];

        return $user !== null
            && User::verifyPin($user, $pin)
            && !User::verifyPassword($user, $pin);
    })(),
    'pintu kata sandi tidak dapat dipakai menebak PIN'
);

Qc::ok(
    'A.12',
    'Hash PIN berbeda dari hash kata sandi biasa untuk masukan yang sama',
    Security::hashPin('246810') !== Security::hashPassword('246810'),
    'HMAC pepper diterapkan sebelum password_hash()'
);

Qc::ok(
    'A.13',
    'Panjang jejak User-Agent dibatasi untuk kolom audit',
    User::MAX_USER_AGENT_LENGTH === 512,
    'batas ' . User::MAX_USER_AGENT_LENGTH . ' karakter'
);

// ---------------------------------------------------------------------------
// A2. Pepper gagal-tertutup pada proses terpisah
// ---------------------------------------------------------------------------

Qc::section('A2. Pepper PIN: gagal-tertutup tanpa APP_PIN_PEPPER');

$probeScript = TEST_PATH . '/.tmp/qc_fase_5_pepper_probe.php';

if (!is_dir(dirname($probeScript))) {
    mkdir(dirname($probeScript), 0775, true);
}

file_put_contents($probeScript, <<<'PHP'
<?php

// Dibuat otomatis oleh tests/qc_fase_5.php — jangan disunting.
require dirname(__DIR__, 2) . '/app/Config/bootstrap.php';

use AurumFlow\Helpers\Security;

$hash = Security::hashPassword('246810');
$result = [
    'hash_pin'   => 'tidak-melempar',
    'verify_pin' => Security::verifyPin('246810', $hash) ? 'sah' : 'tolak',
    'configured' => Security::pinPepperConfigured() ? 'ada' : 'kosong',
];

try {
    Security::hashPin('246810');
} catch (RuntimeException $exception) {
    $result['hash_pin'] = 'melempar';
}

echo json_encode($result);
PHP);

$pepperOff = qc_run_php($probeScript, ['APP_PIN_PEPPER' => '']);

Qc::ok(
    'A.14',
    'Tanpa APP_PIN_PEPPER: hashPin() melempar (fail-closed) dan verifyPin() menolak',
    $pepperOff['code'] === 0
        && str_contains($pepperOff['output'], '"hash_pin":"melempar"')
        && str_contains($pepperOff['output'], '"verify_pin":"tolak"')
        && str_contains($pepperOff['output'], '"configured":"kosong"'),
    'keluaran: ' . trim($pepperOff['output'])
);

@unlink($probeScript);

// ---------------------------------------------------------------------------
// B. Pintu PIN mitra (HTTP)
// ---------------------------------------------------------------------------

Qc::section('B. Pintu PIN mitra: bentuk field, pesan generik, pemisahan dari /login');

QcHttp::start();

$pinForm = QcHttp::request('GET', '/investor/login', [
    'cookie_jar' => QcHttp::cookieJar('fase5-pin-form'),
    'fresh'      => true,
]);

Qc::ok(
    'B.1',
    'GET /investor/login terbuka untuk tamu (200) dan memakai field `pin`',
    $pinForm['status'] === 200
        && str_contains($pinForm['body'], 'name="pin"')
        && !str_contains($pinForm['body'], 'name="password"'),
    'status=' . $pinForm['status']
);

Qc::ok(
    'B.2',
    'Papan angka PIN adalah peningkatan (komponen Alpine `investorLogin`), isian tetap bekerja tanpa JS',
    str_contains($pinForm['body'], 'investorLogin(')
        && str_contains($pinForm['body'], 'pattern="[0-9]*"')
        && str_contains($pinForm['body'], 'inputmode="numeric"'),
    'isian `<input>` biasa + papan angka'
);

$pinCsrf = (string) QcHttp::csrfFrom($pinForm['body']);
$pinJar = QcHttp::cookieJar('fase5-pin-attempts');

// CSRF diambil dari sesi yang sama supaya yang diuji benar-benar validasi PIN.
$pinJarPage = QcHttp::request('GET', '/investor/login', ['cookie_jar' => $pinJar, 'fresh' => true]);
$pinCsrf = (string) QcHttp::csrfFrom($pinJarPage['body']);

Qc::equals(
    'B.3',
    'POST /investor/login tanpa CSRF ditolak 419',
    419,
    QcHttp::request('POST', '/investor/login', [
        'cookie_jar' => QcHttp::cookieJar('fase5-no-csrf'),
        'data'       => ['phone_number' => $investorPhone, 'pin' => $investorPin],
    ])['status']
);

Qc::equals(
    'B.4',
    'PIN bukan enam angka ditolak 422 (tanpa menyentuh kredensial)',
    422,
    QcHttp::request('POST', '/investor/login', [
        'cookie_jar' => $pinJar,
        'data'       => [
            Security::CSRF_FIELD => $pinCsrf,
            'phone_number'       => $investorPhone,
            'pin'                => '12345',
        ],
    ])['status']
);

$wrongPin = QcHttp::request('POST', '/investor/login', [
    'cookie_jar' => $pinJar,
    'data'       => [
        Security::CSRF_FIELD => $pinCsrf,
        'phone_number'       => $investorPhone,
        'pin'                => '999999',
    ],
]);

Qc::ok(
    'P5.4',
    'PIN salah ditolak dengan pesan generik (401, tanpa enumerasi akun)',
    $wrongPin['status'] === 401
        && str_contains($wrongPin['body'], 'Nomor telepon atau PIN salah'),
    'status=' . $wrongPin['status']
);

// Operator tidak dapat masuk lewat pintu PIN.
$operatorPhone = (string) (Env::get('OPERATOR_PHONE') ?? '081200000001');
$operatorPassword = (string) (Env::get('OPERATOR_PASSWORD') ?? 'Aurum2026!');

$operatorJar = QcHttp::cookieJar('fase5-operator');
$operatorForm = QcHttp::request('GET', '/login', ['cookie_jar' => $operatorJar, 'fresh' => true]);
$operatorCsrf = (string) QcHttp::csrfFrom($operatorForm['body']);

$operatorViaPinDoor = QcHttp::request('POST', '/investor/login', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD => $operatorCsrf,
        'phone_number'       => $operatorPhone,
        'pin'                => '246810',
    ],
]);

Qc::ok(
    'P5.5',
    'Akun operator ditolak di pintu PIN dengan pesan generik',
    $operatorViaPinDoor['status'] === 401
        && str_contains($operatorViaPinDoor['body'], 'Nomor telepon atau PIN salah'),
    'status=' . $operatorViaPinDoor['status']
);

// PIN mitra tidak sah di pintu kata sandi — CSRF diambil dari sesi yang sama.
$passwordJar = QcHttp::cookieJar('fase5-pin-at-password');
$passwordForm = QcHttp::request('GET', '/login', ['cookie_jar' => $passwordJar, 'fresh' => true]);

Qc::equals(
    'P5.6',
    'PIN mitra TIDAK sah di pintu kata sandi /login (401)',
    401,
    QcHttp::request('POST', '/login', [
        'cookie_jar' => $passwordJar,
        'data'       => [
            Security::CSRF_FIELD => (string) QcHttp::csrfFrom($passwordForm['body']),
            'phone_number'       => $investorPhone,
            'password'           => $investorPin,
        ],
    ])['status']
);

$operatorLogin = QcHttp::request('POST', '/login', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD => $operatorCsrf,
        'phone_number'       => $operatorPhone,
        'password'           => $operatorPassword,
    ],
]);

Qc::ok(
    'B.5',
    'Operator tetap masuk lewat /login dengan kata sandi (302 -> /dashboard)',
    $operatorLogin['status'] === 302 && $operatorLogin['location'] === '/dashboard',
    'status=' . $operatorLogin['status'] . ' location=' . (string) $operatorLogin['location']
);

$investorLogin = qc5_loginInvestor($investorPhone, $investorPin, 'fase5-investor');
$investorJar = (string) $investorLogin['jar'];

Qc::ok(
    'P5.7',
    'PIN benar -> 302 ke /investor/dashboard',
    $investorLogin['response']['status'] === 302
        && $investorLogin['response']['location'] === '/investor/dashboard',
    'status=' . $investorLogin['response']['status']
        . ' location=' . (string) $investorLogin['response']['location']
);

// ---------------------------------------------------------------------------
// C. PinThrottle
// ---------------------------------------------------------------------------

Qc::section('C. PinThrottle: ambang, kunci, pembersihan, penurunan mutu');

PinThrottle::clearAll();

Qc::ok(
    'C.1',
    'Berkas pembatas berada di storage/ (di luar document root), bukan di public/',
    str_starts_with((string) PinThrottle::directory(), BASE_PATH . '/storage/')
        && !str_starts_with((string) PinThrottle::directory(), PUBLIC_PATH),
    'direktori=' . (string) PinThrottle::directory()
);

$lockPhone = '089800000199';
$lockIp = '203.0.113.9';
$remainingAfter = [];

for ($attempt = 1; $attempt <= PinThrottle::MAX_FAILS; $attempt++) {
    $remainingAfter[$attempt] = PinThrottle::recordFailure($lockPhone, $lockIp);
}

Qc::ok(
    'C.2',
    'Empat kegagalan pertama belum mengunci',
    (int) ($remainingAfter[4] ?? -1) === 0 && (int) ($remainingAfter[1] ?? -1) === 0,
    'ambang=' . PinThrottle::MAX_FAILS . ' kegagalan'
);

Qc::ok(
    'C.3',
    'Kegagalan ke-' . PinThrottle::MAX_FAILS . ' mengunci (sisa > 0 detik)',
    (int) ($remainingAfter[PinThrottle::MAX_FAILS] ?? 0) > 0,
    'sisa=' . (int) ($remainingAfter[PinThrottle::MAX_FAILS] ?? 0) . ' detik'
);

Qc::ok(
    'C.4',
    'check() melaporkan sisa kunci & jumlah kegagalan tercatat',
    PinThrottle::check($lockPhone, $lockIp) > 0
        && PinThrottle::failureCount($lockPhone, $lockIp) === PinThrottle::MAX_FAILS,
    'gagal tercatat=' . PinThrottle::failureCount($lockPhone, $lockIp)
);

Qc::ok(
    'C.5',
    'Kombinasi nomor+IP lain tidak ikut terkunci',
    PinThrottle::check($lockPhone, '198.51.100.7') === 0
        && PinThrottle::check('089800000198', $lockIp) === 0,
    'kunci bersifat per nomor+IP'
);

PinThrottle::clear($lockPhone, $lockIp);

Qc::ok(
    'C.6',
    'Kunci hilang setelah entri dibersihkan (jalur login sukses)',
    PinThrottle::check($lockPhone, $lockIp) === 0
        && PinThrottle::failureCount($lockPhone, $lockIp) === 0,
    'entri dihapus'
);

// Bertahan lintas sesi: entri hidup di berkas, bukan session.
$_SESSION = [];
PinThrottle::recordFailure($lockPhone, $lockIp);

Qc::ok(
    'C.7',
    'Entri bertahan pada berkas meski session dikosongkan (bukan session-only)',
    PinThrottle::count() > 0
        && PinThrottle::failureCount($lockPhone, $lockIp) === 1,
    'berkas aktif=' . PinThrottle::count()
);

// Prune: entri tua dibuang.
$staleFile = (string) PinThrottle::directory() . DIRECTORY_SEPARATOR . PinThrottle::FILE_PREFIX . 'stale-uji.json';
file_put_contents($staleFile, json_encode(['fails' => 1, 'first_at' => time() - 90000, 'locked_until' => 0]));
touch($staleFile, time() - PinThrottle::MAX_AGE_SECONDS - 60);

$pruned = PinThrottle::prune();

Qc::ok(
    'C.8',
    'prune() membuang berkas kedaluwarsa (> 24 jam)',
    $pruned >= 1 && !is_file($staleFile),
    'dibuang=' . $pruned
);

// Penurunan mutu: direktori tidak dapat ditulis. Diuji pada proses terpisah
// karena jalur session hanya hidup bila ada session — persis seperti request
// sungguhan, dan tidak dapat dipaksakan pada proses QC yang sudah mencetak.
$degradeProbe = TEST_PATH . '/.tmp/qc_fase_5_throttle_probe.php';

file_put_contents($degradeProbe, <<<'PHP'
<?php

// Dibuat otomatis oleh tests/qc_fase_5.php — jangan disunting.
require dirname(__DIR__, 2) . '/app/Config/bootstrap.php';

use AurumFlow\Helpers\Formatter;
use AurumFlow\Helpers\PinThrottle;
use AurumFlow\Helpers\Security;

ob_start();
Security::startSession();

$directory = dirname(__DIR__) . '/.tmp/throttle-readonly-probe';

if (!is_dir($directory)) {
    mkdir($directory, 0775, true);
}

chmod($directory, 0500);

PinThrottle::useDirectoryForTesting($directory);
PinThrottle::clearAll();

$remaining = 0;

for ($attempt = 1; $attempt <= PinThrottle::MAX_FAILS; $attempt++) {
    $remaining = PinThrottle::recordFailure('089800000197', '203.0.113.10');
}

$check = PinThrottle::check('089800000197', '203.0.113.10');
$mode = is_writable($directory) ? 'berkas' : 'session';

PinThrottle::useDirectoryForTesting(null);
chmod($directory, 0775);

// Bersihkan direktori uji supaya tidak meninggalkan artefak di tests/.tmp.
foreach (glob($directory . '/*') ?: [] as $leftover) {
    @unlink($leftover);
}

@rmdir($directory);

echo json_encode([
    'remaining' => $remaining,
    'check' => $check,
    'mode' => $mode,
]);
PHP);

$degraded = qc_run_php($degradeProbe, [
    'APP_PIN_PEPPER'    => 'qc-fase-5-pepper-0f2b1c',
    'SESSION_SAVE_PATH' => TEST_PATH . '/.tmp',
]);

@unlink($degradeProbe);

$degradedCheck = preg_match('/"check":(\d+)/', $degraded['output'], $degradedMatch) === 1
    && (int) $degradedMatch[1] > 0;
$degradedRemaining = preg_match('/"remaining":(\d+)/', $degraded['output'], $remainingMatch) === 1
    && (int) $remainingMatch[1] > 0;

Qc::ok(
    'C.9',
    'Direktori tak-writable: kunci tetap berlaku (berkas atau session), tanpa fatal error',
    $degraded['code'] === 0 && $degradedCheck && $degradedRemaining,
    'keluaran: ' . trim($degraded['output'])
);

// Pembatas juga melindungi pintu kata sandi dan dijawab 429 lewat HTTP.
$lockJar = QcHttp::cookieJar('fase5-lock');
$lockForm = QcHttp::request('GET', '/investor/login', ['cookie_jar' => $lockJar, 'fresh' => true]);
$lockCsrf = (string) QcHttp::csrfFrom($lockForm['body']);
$lockStatuses = [];

for ($attempt = 1; $attempt <= PinThrottle::MAX_FAILS; $attempt++) {
    $lockStatuses[] = QcHttp::request('POST', '/investor/login', [
        'cookie_jar' => $lockJar,
        'data'       => [
            Security::CSRF_FIELD => $lockCsrf,
            'phone_number'       => $investorPhone,
            'pin'                => '999999',
        ],
    ])['status'];
}

$lockedAttempt = end($lockStatuses);

Qc::ok(
    'P5.8',
    'Lima PIN salah berturut-turut: percobaan ke-5 dijawab 429',
    $lockedAttempt === 429 && $lockStatuses[0] === 401,
    'status=' . implode(',', $lockStatuses)
);

$stillLocked = QcHttp::request('POST', '/investor/login', [
    'cookie_jar' => $lockJar,
    'data'       => [
        Security::CSRF_FIELD => $lockCsrf,
        'phone_number'       => $investorPhone,
        'pin'                => $investorPin,
    ],
]);

Qc::ok(
    'P5.9',
    'Selama masa kunci, PIN yang benar pun ditolak (429)',
    $stillLocked['status'] === 429 && str_contains($stillLocked['body'], 'Terlalu banyak percobaan'),
    'status=' . $stillLocked['status']
);

PinThrottle::clearAll();

// ---------------------------------------------------------------------------
// D. Isolasi blind (HTTP)
// ---------------------------------------------------------------------------

Qc::section('D. Isolasi blind: kanari & dompet lain tidak pernah terlihat');

$dashboard = QcHttp::request('GET', '/investor/dashboard', ['cookie_jar' => $investorJar]);

Qc::ok(
    'D.1',
    'Dasbor mitra terbuka (200)',
    $dashboard['status'] === 200,
    'status=' . $dashboard['status']
);

$dashboardCanaries = qc5_findCanaries($dashboard['body']);
$dashboardForeign = [];

foreach (qc5_foreignWallets($walletId) as $name) {
    if (str_contains($dashboard['body'], $name)) {
        $dashboardForeign[] = $name;
    }
}

Qc::ok(
    'P5.10',
    'Isolasi laba pengelola: omzet toko, hak 80 %, laba bersih, gramatur, dan nama penambang TIDAK muncul',
    $dashboardCanaries === [],
    $dashboardCanaries === [] ? 'nol kebocoran' : 'bocor: ' . implode(', ', $dashboardCanaries)
);

Qc::ok(
    'P5.11',
    'Isolasi mitra lain: nama dompet lain (mitra & Pengelola) tidak muncul di dasbor',
    $dashboardForeign === [],
    $dashboardForeign === []
        ? count(qc5_foreignWallets($walletId)) . ' nama diuji'
        : 'bocor: ' . implode(', ', $dashboardForeign)
);

Qc::ok(
    'D.2',
    'Dasbor memuat angka milik mitra sendiri (portofolio, modal berputar, kas cadangan, rasio)',
    str_contains($dashboard['body'], 'Rp 20.000.000')
        && str_contains($dashboard['body'], 'Rp 6.204.265')
        && str_contains($dashboard['body'], 'Rp 13.795.735')
        && str_contains($dashboard['body'], '31,02 %'),
    'modal 20 juta, berputar 6.204.265, cadangan 13.795.735, rasio 31,02 %'
);

Qc::ok(
    'D.3',
    'Dasbor tidak menjelaskan mesin internal (mode alokasi, kuota dasar, defisit)',
    !str_contains($dashboard['body'], 'operator_base_quota_pct')
        && !str_contains($dashboard['body'], 'allocation_mode')
        && !str_contains($dashboard['body'], 'active_carryover_deficit')
        && !str_contains($dashboard['body'], 'waterfall'),
    'ruang lingkup tampilan mitra'
);

$reportPage = QcHttp::request('GET', '/investor/report', ['cookie_jar' => $investorJar]);
$reportCanaries = qc5_findCanaries($reportPage['body']);

Qc::ok(
    'P5.12',
    'Laporan ramah cetak terbuka (200), memuat angka mitra, dan bebas kanari',
    $reportPage['status'] === 200
        && $reportCanaries === []
        && str_contains($reportPage['body'], 'Rp 20.000.000'),
    'status=' . $reportPage['status']
        . ($reportCanaries === [] ? '' : ' bocor: ' . implode(', ', $reportCanaries))
);

$csvResponse = QcHttp::request('GET', '/investor/report/csv', ['cookie_jar' => $investorJar]);

Qc::ok(
    'P5.13',
    'CSV terunduh: Content-Type text/csv + attachment + BOM UTF-8',
    $csvResponse['status'] === 200
        && stripos((string) $csvResponse['headers'], 'text/csv') !== false
        && stripos((string) $csvResponse['headers'], 'attachment') !== false
        && str_starts_with($csvResponse['body'], "\xEF\xBB\xBF"),
    'status=' . $csvResponse['status']
);

Qc::ok(
    'P5.14',
    'CSV memuat pemisah `;`, empat baris putaran, ringkasan, dan bebas kanari',
    substr_count($csvResponse['body'], ';') >= 16
        && substr_count($csvResponse['body'], 'Terverifikasi') === 4
        && str_contains($csvResponse['body'], 'Total Modal Dititipkan')
        && str_contains($csvResponse['body'], 'Rp 20.000.000')
            === false
        && qc5_findCanaries($csvResponse['body']) === [],
    'baris terverifikasi=' . substr_count($csvResponse['body'], 'Terverifikasi')
);

Qc::ok(
    'D.4',
    'Nama dompet lain juga tidak muncul pada CSV',
    (static function () use ($csvResponse, $walletId): bool {
        foreach (qc5_foreignWallets($walletId) as $name) {
            if (str_contains($csvResponse['body'], $name)) {
                return false;
            }
        }

        return true;
    })(),
    'satu-satunya nama pada CSV adalah nama pemilik sesi'
);

// Payload `wallet_id` asing: dompet tetap milik sesi.
$foreignWallet = '';

foreach (CapitalWallet::listWithUser() as $wallet) {
    if ((string) $wallet['id'] !== $walletId) {
        $foreignWallet = (string) $wallet['id'];

        break;
    }
}

$dashboardPage = QcHttp::request('GET', '/investor/dashboard', ['cookie_jar' => $investorJar]);
$dashCsrf = (string) QcHttp::csrfFrom($dashboardPage['body']);
$dashOnce = (string) QcHttp::fieldFrom($dashboardPage['body'], Security::FORM_FIELD);

$forged = QcHttp::request('POST', '/investor/withdrawal', [
    'cookie_jar' => $investorJar,
    'data'       => [
        Security::CSRF_FIELD    => $dashCsrf,
        Security::FORM_FIELD    => $dashOnce,
        'withdrawal_wallet_id'  => $foreignWallet,
        'withdrawal_type'       => WithdrawalRequest::TYPE_PROFIT,
        'withdrawal_amount'     => '10.000',
    ],
]);

$foreignRow = $foreignWallet === '' ? null : CapitalWallet::findById($foreignWallet);

Qc::ok(
    'D.5',
    'Payload `wallet_id` dompet lain diabaikan — pengajuan tetap masuk ke dompet sesi',
    $forged['status'] === 302
        && (int) (Database::fetchOne(
            'SELECT COUNT(*) AS `total` FROM `withdrawal_requests` WHERE `wallet_id` = :wallet_id',
            ['wallet_id' => $walletId]
        )['total'] ?? 0) === 1
        && ($foreignRow === null
            || (string) $foreignRow['withdrawal_status'] === CapitalWallet::WITHDRAWAL_NONE),
    'dompet asing=' . ($foreignWallet === '' ? '(tidak ada)' : substr($foreignWallet, 0, 8) . '…')
);

$beforeDuplicate = QcFixture::investorSnapshot();

$duplicateOnce = QcHttp::request('POST', '/investor/withdrawal', [
    'cookie_jar' => $investorJar,
    'data'       => [
        Security::CSRF_FIELD => $dashCsrf,
        Security::FORM_FIELD => $dashOnce,
        'withdrawal_type'    => WithdrawalRequest::TYPE_PROFIT,
        'withdrawal_amount'  => '10.000',
    ],
]);

$afterDuplicate = QcFixture::investorSnapshot();

Qc::ok(
    'D.6',
    'Token `_once` yang dikirim ulang: pengajuan ganda diabaikan (tanpa tulisan baru)',
    $duplicateOnce['status'] === 302
        && $afterDuplicate['withdrawals'] === $beforeDuplicate['withdrawals'],
    'jumlah pengajuan tetap ' . $afterDuplicate['withdrawals']
);

Qc::ok(
    'D.7',
    'Tanpa login, seluruh route investor diarahkan ke /login',
    (static function (): bool {
        $anonJar = QcHttp::cookieJar('fase5-anon');

        foreach (['/investor/dashboard', '/investor/report', '/investor/report/csv'] as $path) {
            $anon = QcHttp::request('GET', $path, ['cookie_jar' => $anonJar, 'fresh' => true]);

            if ($anon['status'] !== 302 || $anon['location'] !== '/login') {
                return false;
            }
        }

        return true;
    })(),
    'dashboard, laporan, dan CSV menuntut autentikasi'
);

// ---------------------------------------------------------------------------
// E. Matriks gating peran
// ---------------------------------------------------------------------------

Qc::section('E. Gating sadar peran: beranda per peran & /dashboard operator-only');

Qc::ok(
    'E.1',
    'Mitra -> /operator/* (GET) diarahkan ke /investor/dashboard',
    (static function () use ($investorJar): bool {
        foreach (['/operator/teller', '/operator/settlement', '/operator/capital'] as $path) {
            $response = QcHttp::request('GET', $path, ['cookie_jar' => $investorJar]);

            if ($response['status'] !== 302 || $response['location'] !== '/investor/dashboard') {
                return false;
            }
        }

        return true;
    })(),
    'tiga layar operator'
);

Qc::ok(
    'P5.15',
    'Mitra -> POST /operator/* diarahkan ke /investor/dashboard tanpa menulis apa pun',
    (static function () use ($investorJar, $dashCsrf, $dashOnce, $walletId): bool {
        $before = QcFixture::capitalSnapshot();

        $post = QcHttp::request('POST', '/operator/capital/topup', [
            'cookie_jar' => $investorJar,
            'data'       => [
                Security::CSRF_FIELD => $dashCsrf,
                Security::FORM_FIELD => $dashOnce,
                'topup_wallet_id'    => $walletId,
                'topup_amount'       => '1.000.000',
            ],
        ]);

        return $post['status'] === 302
            && $post['location'] === '/investor/dashboard'
            && QcFixture::capitalSnapshot() === $before;
    })(),
    'guard peran mendahului `_once`'
);

Qc::ok(
    'E.2',
    'Mitra -> /dashboard diarahkan ke /investor/dashboard (tidak melihat system_settings)',
    (static function () use ($investorJar): bool {
        $page = QcHttp::request('GET', '/dashboard', ['cookie_jar' => $investorJar]);

        return $page['status'] === 302 && $page['location'] === '/investor/dashboard';
    })(),
    'halaman ringkasan operator bukan untuk mitra'
);

Qc::ok(
    'E.3',
    'Operator -> route investor diarahkan ke /dashboard',
    (static function () use ($operatorJar): bool {
        $page = QcHttp::request('GET', '/investor/dashboard', ['cookie_jar' => $operatorJar]);

        return $page['status'] === 302 && $page['location'] === '/dashboard';
    })(),
    'requireRole(Constants::ROLE_INVESTOR)'
);

Qc::ok(
    'E.4',
    'Operator tetap melihat /dashboard (200) beserta isi setelan',
    (static function () use ($operatorJar): bool {
        $page = QcHttp::request('GET', '/dashboard', ['cookie_jar' => $operatorJar]);

        return $page['status'] === 200
            && str_contains($page['body'], 'Mode alokasi modal')
            && str_contains($page['body'], 'waterfall');
    })(),
    'perilaku lama tidak berubah'
);

Qc::ok(
    'E.5',
    '`/` sadar peran: sesi mitra mendarat di dasbornya',
    (static function () use ($investorJar): bool {
        $page = QcHttp::request('GET', '/', ['cookie_jar' => $investorJar]);

        return $page['status'] === 302 && $page['location'] === '/investor/dashboard';
    })(),
    'roleLandingPath() satu pemilik'
);

Qc::ok(
    'E.6',
    'Mitra yang sudah masuk tidak dapat membuka /investor/login lagi',
    (static function () use ($investorJar): bool {
        $page = QcHttp::request('GET', '/investor/login', ['cookie_jar' => $investorJar]);

        return $page['status'] === 302 && $page['location'] === '/investor/dashboard';
    })(),
    'requireGuest() sadar peran'
);

$logoutPage = QcHttp::request('GET', '/investor/dashboard', ['cookie_jar' => $investorJar]);
$logout = QcHttp::request('POST', '/logout', [
    'cookie_jar' => $investorJar,
    'data'       => [Security::CSRF_FIELD => (string) QcHttp::csrfFrom($logoutPage['body'])],
]);

Qc::ok(
    'E.7',
    'Logout mitra mendarat di /investor/login (bukan pintu kata sandi)',
    $logout['status'] === 302 && $logout['location'] === '/investor/login',
    'location=' . (string) $logout['location']
);

$reLogin = qc5_loginInvestor($investorPhone, $investorPin, 'fase5-investor-2');
$investorJar = (string) $reLogin['jar'];

Qc::ok(
    'E.8',
    'Mitra dapat masuk kembali setelah logout (302 -> /investor/dashboard)',
    $reLogin['response']['status'] === 302 && $reLogin['response']['location'] === '/investor/dashboard',
    'status=' . $reLogin['response']['status']
);

// ---------------------------------------------------------------------------
// F. Penarikan mandiri mitra
// ---------------------------------------------------------------------------

Qc::section('F. Penarikan mandiri: dividen tanpa masa tunggu, modal H-7 hari kerja');

Database::query('DELETE FROM `withdrawal_requests` WHERE `wallet_id` = :wallet_id', ['wallet_id' => $walletId]);

/**
 * Ambil CSRF + `_once` terbaru dari dasbor (satu halaman = satu token).
 *
 * @return array{csrf: string, once: string}
 */
function qc5_dashboardTokens(string $jar): array
{
    $page = QcHttp::request('GET', '/investor/dashboard', ['cookie_jar' => $jar]);

    return [
        'csrf' => (string) QcHttp::csrfFrom($page['body']),
        'once' => (string) QcHttp::fieldFrom($page['body'], Security::FORM_FIELD),
    ];
}

$tokens = qc5_dashboardTokens($investorJar);

$profitRequest = QcHttp::request('POST', '/investor/withdrawal', [
    'cookie_jar' => $investorJar,
    'data'       => [
        Security::CSRF_FIELD => $tokens['csrf'],
        Security::FORM_FIELD => $tokens['once'],
        'withdrawal_type'    => WithdrawalRequest::TYPE_PROFIT,
        'withdrawal_amount'  => '100.000',
        'withdrawal_note'    => 'qc tarik keuntungan',
    ],
]);

$profitRow = Database::fetchOne(
    'SELECT `id`, `withdrawal_type`, `amount`, `status`, `notice_period_due` FROM `withdrawal_requests` '
    . 'WHERE `wallet_id` = :wallet_id AND `withdrawal_type` = :type ORDER BY `id` DESC LIMIT 1',
    ['wallet_id' => $walletId, 'type' => WithdrawalRequest::TYPE_PROFIT]
);

Qc::ok(
    'P5.16',
    'Tarik Keuntungan: tercatat `profit` + `pending` + jatuh tempo HARI INI',
    $profitRequest['status'] === 302
        && $profitRow !== null
        && (string) $profitRow['status'] === WithdrawalRequest::STATUS_PENDING
        && (string) $profitRow['notice_period_due'] === date('Y-m-d')
        && (string) $profitRow['amount'] === '100000.00',
    'due=' . (string) ($profitRow['notice_period_due'] ?? '-')
);

$tokens = qc5_dashboardTokens($investorJar);

$capitalRequest = QcHttp::request('POST', '/investor/withdrawal', [
    'cookie_jar' => $investorJar,
    'data'       => [
        Security::CSRF_FIELD => $tokens['csrf'],
        Security::FORM_FIELD => $tokens['once'],
        'withdrawal_type'    => WithdrawalRequest::TYPE_CAPITAL,
        'withdrawal_amount'  => '1.000.000',
        'withdrawal_note'    => 'qc tarik modal',
    ],
]);

$capitalRow = Database::fetchOne(
    'SELECT `id`, `status`, `notice_period_due` FROM `withdrawal_requests` '
    . 'WHERE `wallet_id` = :wallet_id AND `withdrawal_type` = :type ORDER BY `id` DESC LIMIT 1',
    ['wallet_id' => $walletId, 'type' => WithdrawalRequest::TYPE_CAPITAL]
);

$expectedDue = CapitalService::workingDaysDueDate(date('Y-m-d'), 7);
$walletAfterCapital = CapitalWallet::findById($walletId);

Qc::ok(
    'P5.17',
    'Tarik Modal: H-7 hari kerja + dompet ditandai `queued`',
    $capitalRequest['status'] === 302
        && $capitalRow !== null
        && (string) $capitalRow['notice_period_due'] === $expectedDue
        && (string) $walletAfterCapital['withdrawal_status'] === CapitalWallet::WITHDRAWAL_QUEUED,
    'due=' . (string) ($capitalRow['notice_period_due'] ?? '-') . ' (harap ' . $expectedDue . ')'
);

Qc::ok(
    'F.1',
    'Jatuh tempo H-7 jatuh pada HARI KERJA dan tidak lebih awal dari +7 hari kalender',
    (int) date('N', (int) strtotime($expectedDue)) <= 5
        && $expectedDue >= date('Y-m-d', strtotime('+7 days')),
    'tanggal=' . $expectedDue . ' hari ke-' . date('N', (int) strtotime($expectedDue))
);

$tokens = qc5_dashboardTokens($investorJar);

$secondCapital = QcHttp::request('POST', '/investor/withdrawal', [
    'cookie_jar' => $investorJar,
    'data'       => [
        Security::CSRF_FIELD => $tokens['csrf'],
        Security::FORM_FIELD => $tokens['once'],
        'withdrawal_type'    => WithdrawalRequest::TYPE_CAPITAL,
        'withdrawal_amount'  => '500.000',
    ],
]);

Qc::ok(
    'F.2',
    'Pengajuan modal pokok kedua ditolak 422 dan tidak menulis baris baru',
    $secondCapital['status'] === 422
        && (int) (Database::fetchOne(
            'SELECT COUNT(*) AS `total` FROM `withdrawal_requests` WHERE `wallet_id` = :wallet_id '
            . 'AND `withdrawal_type` = :type',
            ['wallet_id' => $walletId, 'type' => WithdrawalRequest::TYPE_CAPITAL]
        )['total'] ?? 0) === 1,
    'status=' . $secondCapital['status']
);

$tokens = qc5_dashboardTokens($investorJar);
$beforeOverdraw = QcFixture::investorSnapshot();

$overdraw = QcHttp::request('POST', '/investor/withdrawal', [
    'cookie_jar' => $investorJar,
    'data'       => [
        Security::CSRF_FIELD => $tokens['csrf'],
        Security::FORM_FIELD => $tokens['once'],
        'withdrawal_type'    => WithdrawalRequest::TYPE_PROFIT,
        'withdrawal_amount'  => '99.000.000',
    ],
]);

Qc::ok(
    'P5.18',
    'Nominal melebihi saldo ditolak 422 tanpa tulisan',
    $overdraw['status'] === 422
        && QcFixture::investorSnapshot()['withdrawals'] === $beforeOverdraw['withdrawals'],
    'status=' . $overdraw['status']
);

$tokens = qc5_dashboardTokens($investorJar);

$overOpen = QcHttp::request('POST', '/investor/withdrawal', [
    'cookie_jar' => $investorJar,
    'data'       => [
        Security::CSRF_FIELD => $tokens['csrf'],
        Security::FORM_FIELD => $tokens['once'],
        'withdrawal_type'    => WithdrawalRequest::TYPE_PROFIT,
        'withdrawal_amount'  => '300.000',
    ],
]);

Qc::ok(
    'F.3',
    'Invariant Σ pengajuan terbuka ≤ saldo (pengajuan yang menumpuk ditolak 422)',
    $overOpen['status'] === 422
        && (int) (Database::fetchOne(
            'SELECT COUNT(*) AS `total` FROM `withdrawal_requests` WHERE `wallet_id` = :wallet_id '
            . 'AND `withdrawal_type` = :type',
            ['wallet_id' => $walletId, 'type' => WithdrawalRequest::TYPE_PROFIT]
        )['total'] ?? 0) === 1,
    'status=' . $overOpen['status']
);

$dashboardAfterRequests = QcHttp::request('GET', '/investor/dashboard', ['cookie_jar' => $investorJar]);

Qc::ok(
    'F.4',
    'Dasbor menampilkan sisa dana yang masih dapat diajukan (saldo − pengajuan terbuka)',
    str_contains($dashboardAfterRequests['body'], 'Rp 279.341'),
    'saldo 379.341,18 − pengajuan terbuka 100.000'
);

$tokens = qc5_dashboardTokens($investorJar);

Qc::ok(
    'F.5',
    'Pencairan tetap milik operator: mitra ditolak di layar penarikan operator',
    (static function () use ($investorJar, $tokens, $capitalRow): bool {
        $post = QcHttp::request('POST', '/operator/capital/withdrawal', [
            'cookie_jar' => $investorJar,
            'data'       => [
                Security::CSRF_FIELD       => $tokens['csrf'],
                Security::FORM_FIELD       => $tokens['once'],
                'withdrawal_action'        => 'bayar',
                'withdrawal_request_id'    => (string) $capitalRow['id'],
            ],
        ]);

        return $post['status'] === 302 && $post['location'] === '/investor/dashboard';
    })(),
    'mitra tidak pernah menggerakkan kas'
);

// ---------------------------------------------------------------------------
// G. Undangan sisi operator
// ---------------------------------------------------------------------------

Qc::section('G. Undangan sisi operator: terbit otomatis, putar ulang, status');

$autoMitra = CapitalService::createMitra([
    'full_name'    => 'QC Mitra Undangan',
    'phone_number' => '089800000103',
    'tier'         => CapitalWallet::TIER_EXTERNAL,
    'quota'        => '0',
]);

Qc::ok(
    'G.1',
    'createMitra() menerbitkan token undangan otomatis (status menunggu)',
    preg_match(OnboardingService::TOKEN_PATTERN, (string) $autoMitra['invite_token']) === 1
        && OnboardingService::findInvitee((string) $autoMitra['invite_token']) !== null
        && Database::fetchOne(
            'SELECT `onboarding_signed_at` FROM `users` WHERE `id` = :id',
            ['id' => (string) $autoMitra['user_id']]
        )['onboarding_signed_at'] === null,
    'token=' . substr((string) $autoMitra['invite_token'], 0, 8) . '…'
);

$operatorCapital = QcHttp::request('GET', '/operator/capital', ['cookie_jar' => $operatorJar]);
$operatorCsrf = (string) QcHttp::csrfFrom($operatorCapital['body']);

Qc::ok(
    'G.2',
    'Kartu "Undangan & Onboarding Mitra" tampil dengan ketiga status',
    $operatorCapital['status'] === 200
        && str_contains($operatorCapital['body'], 'Undangan &amp; Onboarding Mitra')
        && str_contains($operatorCapital['body'], 'Menunggu onboarding')
        && str_contains($operatorCapital['body'], 'Aktif'),
    'status=' . $operatorCapital['status']
);

Qc::ok(
    'G.3',
    'Layar menampilkan tautan undangan absolut (`join?token=…`) dan form putar ulang',
    str_contains($operatorCapital['body'], '/join?token=')
        && str_contains($operatorCapital['body'], 'operator/capital/invite')
        && str_contains($operatorCapital['body'], 'Salin tautan'),
    'tautan dibentuk App::absoluteUrl()'
);

$oldToken = (string) $autoMitra['invite_token'];

$rotate = QcHttp::request('POST', '/operator/capital/invite', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD => $operatorCsrf,
        'invite_wallet_id'   => (string) $autoMitra['wallet_id'],
        'invite_action'      => 'reset',
    ],
]);

$newToken = (string) (Database::fetchOne(
    'SELECT `invite_token` FROM `users` WHERE `id` = :id',
    ['id' => (string) $autoMitra['user_id']]
)['invite_token'] ?? '');

Qc::ok(
    'G.4',
    'Putar ulang tautan menghasilkan token baru 64 heksadesimal',
    $rotate['status'] === 302
        && $newToken !== $oldToken
        && preg_match(OnboardingService::TOKEN_PATTERN, $newToken) === 1,
    'lama=' . substr($oldToken, 0, 8) . '… baru=' . substr($newToken, 0, 8) . '…'
);

Qc::ok(
    'G.5',
    'Tautan lama langsung mati (404, halaman "tautan tidak berlaku")',
    QcHttp::request('GET', '/join?token=' . $oldToken, [
        'cookie_jar' => QcHttp::cookieJar('fase5-old-token'),
    ])['status'] === 404,
    'token lama tidak lagi dikenali'
);

Qc::ok(
    'G.6',
    'Mitra tidak boleh memanggil route undangan operator',
    (static function () use ($investorJar, $tokens, $walletId): bool {
        $post = QcHttp::request('POST', '/operator/capital/invite', [
            'cookie_jar' => $investorJar,
            'data'       => [
                Security::CSRF_FIELD => $tokens['csrf'],
                'invite_wallet_id'   => $walletId,
                'invite_action'      => 'issue',
            ],
        ]);

        return $post['status'] === 302 && $post['location'] === '/investor/dashboard';
    })(),
    'requireRole(operator) pada route undangan'
);

Qc::ok(
    'G.7',
    'Akun Pengelola tidak dapat diberi tautan undangan',
    (static function () use ($operatorPhone): bool {
        $operator = User::findByPhoneNumber($operatorPhone, false);
        $wallet = $operator === null ? null : CapitalWallet::findByUserId((string) $operator['id']);

        if ($wallet === null) {
            return false;
        }

        try {
            CapitalService::issueInvite(['wallet_id' => (string) $wallet['id'], 'mode' => 'issue']);

            return false;
        } catch (\InvalidArgumentException) {
            return true;
        }
    })(),
    'pesan siap tampil, tanpa tulisan'
);

// ---------------------------------------------------------------------------
// H. Halaman onboarding `/join` (Layar A)
// ---------------------------------------------------------------------------

Qc::section('H. Halaman onboarding `/join`: waiver, jejak audit, tautan sekali pakai');

$joinJar = QcHttp::cookieJar('fase5-join');
$joinForm = QcHttp::request('GET', '/join?token=' . $newToken, ['cookie_jar' => $joinJar, 'fresh' => true]);

Qc::ok(
    'H.1',
    'GET /join dengan token aktif menampilkan edukasi + klausul waiver + dua isian PIN',
    $joinForm['status'] === 200
        && str_contains($joinForm['body'], 'Pakta Integritas')
        && str_contains($joinForm['body'], 'membebaskan Pengelola')
        && str_contains($joinForm['body'], 'name="pin"')
        && str_contains($joinForm['body'], 'name="pin_confirm"'),
    'status=' . $joinForm['status']
);

Qc::ok(
    'H.2',
    'Halaman onboarding tidak membocorkan angka operasional apa pun',
    qc5_findCanaries($joinForm['body']) === [],
    'hanya edukasi & persetujuan'
);

Qc::ok(
    'H.3',
    'Token tidak dikenal ditolak 404',
    QcHttp::request('GET', '/join?token=' . str_repeat('c', 64), [
        'cookie_jar' => QcHttp::cookieJar('fase5-bad-token'),
    ])['status'] === 404,
    'halaman "tautan tidak berlaku"'
);

$joinCsrf = (string) QcHttp::csrfFrom($joinForm['body']);

Qc::equals(
    'H.4',
    'POST /join tanpa centang persetujuan ditolak 422',
    422,
    QcHttp::request('POST', '/join', [
        'cookie_jar' => $joinJar,
        'data'       => [
            Security::CSRF_FIELD => $joinCsrf,
            'token'              => $newToken,
            'pin'                => '135790',
            'pin_confirm'        => '135790',
        ],
    ])['status']
);

$joinPost = QcHttp::request('POST', '/join', [
    'cookie_jar' => $joinJar,
    'headers'    => ['User-Agent: QC-Fase5-Agent/1.0 (uji audit)'],
    'data'       => [
        Security::CSRF_FIELD => $joinCsrf,
        'token'              => $newToken,
        'accept_waiver'      => '1',
        'pin'                => '135790',
        'pin_confirm'        => '135790',
    ],
]);

$signedUser = Database::fetchOne(
    'SELECT `id`, `invite_token`, `onboarding_signed_at`, `signed_ip_address`, `signed_user_agent` '
    . 'FROM `users` WHERE `id` = :id',
    ['id' => (string) $autoMitra['user_id']]
);

Qc::ok(
    'H.5',
    'POST /join sukses: 302 -> /investor/login (PRG)',
    $joinPost['status'] === 302 && $joinPost['location'] === '/investor/login',
    'status=' . $joinPost['status'] . ' location=' . (string) $joinPost['location']
);

Qc::ok(
    'P5.19',
    'Jejak audit terekam: waktu, IP (127.0.0.1), dan User-Agent kiriman',
    $signedUser !== null
        && $signedUser['onboarding_signed_at'] !== null
        && (string) $signedUser['signed_ip_address'] === '127.0.0.1'
        && str_contains((string) $signedUser['signed_user_agent'], 'QC-Fase5-Agent/1.0'),
    'ip=' . (string) ($signedUser['signed_ip_address'] ?? '-')
);

Qc::ok(
    'H.6',
    'Token undangan hangus setelah tanda tangan & PIN baru langsung berlaku',
    $signedUser !== null
        && $signedUser['invite_token'] === null
        && User::verifyPin(User::findByPhoneNumber('089800000103') ?? [], '135790')
        && !User::verifyPin(User::findByPhoneNumber('089800000103') ?? [], '135791'),
    'sekali pakai + hash ber-pepper'
);

Qc::ok(
    'H.7',
    'Tautan yang sudah dipakai tidak dapat diklaim ulang (404)',
    QcHttp::request('GET', '/join?token=' . $newToken, [
        'cookie_jar' => QcHttp::cookieJar('fase5-used-token'),
    ])['status'] === 404,
    'nol tulisan'
);

Qc::ok(
    'H.8',
    'Pesan sukses onboarding tampil di pintu PIN (flash dari PRG)',
    (static function () use ($joinJar): bool {
        $page = QcHttp::request('GET', '/investor/login', ['cookie_jar' => $joinJar]);

        return $page['status'] === 200 && str_contains($page['body'], 'PIN aktif');
    })(),
    'layout auth menampilkan flash'
);

Qc::ok(
    'H.9',
    'Mitra baru dapat masuk lewat pintu PIN setelah onboarding',
    (static function (): bool {
        $login = qc5_loginInvestor('089800000103', '135790', 'fase5-new-investor');

        return $login['response']['status'] === 302
            && $login['response']['location'] === '/investor/dashboard';
    })(),
    'alur undangan -> PIN -> dasbor berjalan'
);

// ---------------------------------------------------------------------------
// I. Pemindaian statis & kontrak form/view investor
// ---------------------------------------------------------------------------

Qc::section('I. Pemindaian statis: daftar terlarang, kontrak form, & rute');

$forbiddenTokens = [
    'HuluTransaction',
    'hulu_transactions',
    'farmer_alias',
    'total_shop_revenue',
    'net_settled_profit',
    'operator_share_80',
    'investor_pool_20',
    'daily_coordination_fee',
    'deficit_recovery_used',
    'carryover_deficit',
    'SystemSetting',
    'CashRegister',
    'CashMutation',
    // Fase 6: likuiditas/kredit pengelola (dana talangan) juga internal.
    'BridgeLoan',
    'bridge_loans',
    'talangan',
    'sumProfit(',
    'sumUtilized(',
    'SELECT *',
];

$violations = qc5_scan(qc5_investor_files(), static function (string $line) use ($forbiddenTokens): bool {
    foreach ($forbiddenTokens as $token) {
        if (str_contains($line, $token)) {
            return true;
        }
    }

    return false;
});

Qc::ok(
    'P5.20',
    'Berkas investor bebas daftar terlarang (hulu, omzet, laba pengelola, kas, setelan, talangan)',
    $violations === [],
    $violations === []
        ? count(qc5_investor_files()) . ' berkas bersih'
        : implode(' | ', array_slice($violations, 0, 3))
);

$controllerSource = (string) file_get_contents(APP_PATH . '/Controllers/InvestorController.php')
    . (string) file_get_contents(APP_PATH . '/Controllers/ExportController.php');

Qc::ok(
    'I.1',
    'Empat method publik controller investor, semuanya terlindungi requireRole(investor)',
    substr_count($controllerSource, 'public function ') === 4
        && substr_count($controllerSource, 'requireRole(Constants::ROLE_INVESTOR)') >= 3,
    'method publik=' . substr_count($controllerSource, 'public function ')
        . ' guard=' . substr_count($controllerSource, 'requireRole(Constants::ROLE_INVESTOR)')
);

$controlDisabled = qc5_scan(
    ['app/Views/investor/dashboard.php', 'app/Views/investor/login.php', 'app/Views/investor/onboarding.php'],
    static fn (string $line): bool => str_contains($line, ':disabled')
        && preg_match('/<(input|select|textarea)/i', $line) === 1
);

Qc::ok(
    'I.2',
    'Kontrol form investor TIDAK PERNAH `:disabled` (hanya `<button>`)',
    $controlDisabled === [],
    $controlDisabled === [] ? 'tidak ada kontrol form ter-disable' : implode(' | ', $controlDisabled)
);

Qc::ok(
    'I.3',
    'Isian yang dikirim memakai `:readonly="submitting"` + kelas redup',
    qc5_scan(
        ['app/Views/investor/dashboard.php'],
        static fn (string $line): bool => str_contains($line, ':readonly="submitting"')
    ) !== []
    && str_contains((string) file_get_contents(APP_PATH . '/Views/investor/dashboard.php'), 'opacity-70 cursor-not-allowed'),
    'konvensi yang sama dengan layar operator'
);

Qc::ok(
    'I.4',
    'Placeholder contoh berawalan `mis. ` pada form investor',
    str_contains((string) file_get_contents(APP_PATH . '/Views/investor/dashboard.php'), 'placeholder="mis. ')
        && str_contains((string) file_get_contents(APP_PATH . '/Views/investor/onboarding.php'), 'placeholder="mis. '),
    'contoh, bukan data'
);

Qc::ok(
    'I.5',
    'Seluruh output dinamis pada view investor lewat Security::e()',
    qc5_scan(
        [
            'app/Views/investor/dashboard.php',
            'app/Views/investor/report.php',
            'app/Views/investor/onboarding.php',
            'app/Views/investor/login.php',
        ],
        static fn (string $line): bool => str_contains($line, '<?= $')
    ) === [],
    'layout sengaja tidak discan: `<?= $content ?>` memang HTML yang sudah di-escape'
);

Qc::ok(
    'I.6',
    'Tidak ada number_format()/float/double pada berkas Fase 5',
    qc5_scan(
        array_merge(qc5_investor_files(), [
            'app/Helpers/PinThrottle.php',
            'app/Services/OnboardingService.php',
            'app/Models/ProfitDistribution.php',
            'app/Models/WithdrawalRequest.php',
        ]),
        static fn (string $line): bool => str_contains($line, 'number_format(')
            || str_contains($line, 'float')
            || str_contains($line, 'double')
    ) === [],
    'format angka tetap milik Formatter'
);

Qc::ok(
    'I.7',
    'Tidak ada SQL di controller/view (semua query hidup di app/Models)',
    qc5_scan(
        array_merge(qc5_investor_files(), ['app/Views/operator/capital.php']),
        // Pola SQL di proyek ini SELALU memakai backtick untuk identifier,
        // sehingga `<select>`/`</select>` pada HTML tidak ikut tertangkap.
        static fn (string $line): bool => preg_match('/\b(SELECT\s+`|INSERT INTO `|UPDATE `|DELETE FROM `)/i', $line) === 1
    ) === [],
    'aturan AGENTS.md'
);

Qc::ok(
    'I.8',
    'Jalur penarikan mitra memakai token sekali-pakai `_once`',
    str_contains((string) file_get_contents(APP_PATH . '/Views/investor/dashboard.php'), 'Security::FORM_FIELD')
        && str_contains((string) file_get_contents(APP_PATH . '/Controllers/InvestorController.php'), 'consumeFormToken'),
    'anti double-submit di sisi peladen'
);

$routes = require APP_PATH . '/routes.php';
$expectedRoutes = [
    'GET /join',
    'GET /investor/login',
    'POST /investor/login',
    'GET /investor/dashboard',
    'POST /investor/withdrawal',
    'GET /investor/report',
    'GET /investor/report/csv',
    'POST /operator/capital/invite',
];

$missingRoutes = [];

foreach ($expectedRoutes as $route) {
    [$method, $path] = explode(' ', $route, 2);

    if (!isset($routes[$method][$path])) {
        $missingRoutes[] = $route;
    }
}

Qc::ok(
    'I.9',
    'Seluruh delapan route Fase 5 terdaftar di routes.php',
    $missingRoutes === [],
    $missingRoutes === [] ? '8/8 terdaftar' : 'hilang: ' . implode(', ', $missingRoutes)
);

Qc::ok(
    'I.13',
    'Tidak ada pernyataan `try`/`catch`/`if` di dalam atribut Alpine (harus ekspresi saja)',
    (static function (): array {
        $offenders = [];
        $attributes = ['@click', '@submit', '@scroll', 'x-on:', 'x-data', 'x-init', 'x-show'];

        foreach (qc5_all_view_files() as $relative) {
            $lines = preg_split('/\R/', (string) file_get_contents(BASE_PATH . '/' . $relative)) ?: [];

            foreach ($lines as $index => $line) {
                foreach ($attributes as $attribute) {
                    if (!str_contains($line, $attribute . '=')) {
                        continue;
                    }

                    // `try` diikuti '{' hanya sah di dalam <script>/berkas .js.
                    if (preg_match('/' . preg_quote($attribute, '/') . '="[^"]*\b(try|catch)\s*[\({]/', $line) === 1) {
                        $offenders[] = $relative . ':' . ($index + 1) . '  ' . trim($line);
                    }
                }
            }
        }

        return $offenders;
    })() === [],
    'Alpine mengevaluasi atribut sebagai ekspresi; pernyataan `try` memicu SyntaxError'
);

Qc::ok(
    'I.10',
    'Seluruh layout menautkan manifest PWA & menyediakan data-app-base',
    (static function (): bool {
        foreach (['app.php', 'auth.php', 'operator.php', 'investor.php', 'report.php'] as $layout) {
            $html = (string) file_get_contents(VIEW_PATH . '/layouts/' . $layout);

            if (!str_contains($html, 'manifest.json') || !str_contains($html, 'data-app-base')) {
                return false;
            }
        }

        return true;
    })(),
    '5 layout: app, auth, operator, investor, report'
);

Qc::ok(
    'I.11',
    'Tautan undangan dibentuk peladen lewat App::absoluteUrl()',
    str_contains((string) file_get_contents(APP_PATH . '/Controllers/CapitalController.php'), 'App::absoluteUrl'),
    'tidak ada perakitan URL manual di view'
);

Qc::ok(
    'I.12',
    'Label tier tetap satu pemilik: tidak ada enum mentah pada berkas investor',
    qc5_scan(qc5_investor_files(), static fn (string $line): bool => str_contains($line, "'family'")
        || str_contains($line, "'external'")) === [],
    'label hanya dari CapitalWallet::label()'
);

// ---------------------------------------------------------------------------
// J. PWA
// ---------------------------------------------------------------------------

Qc::section('J. PWA: manifest, service worker, ikon, halaman offline');

Qc::ok(
    'J.9',
    'Berkas PWA dapat disajikan web server (manifest, service worker, offline) dengan status 200',
    (static function (): bool {
        $anonJar = QcHttp::cookieJar('fase5-pwa-assets');

        foreach (['/manifest.json', '/sw.js', '/offline.html'] as $asset) {
            $response = QcHttp::request('GET', $asset, ['cookie_jar' => $anonJar, 'fresh' => true]);

            if ($response['status'] !== 200 || !str_contains((string) $response['headers'], 'Content-Type')) {
                return false;
            }
        }

        return true;
    })(),
    'service worker harus dapat diambil dari akar scope PWA'
);

Qc::ok(
    'J.10',
    'Tombol "Salin tautan" swasembada: `x-data` literal + rantai Promise tanpa `try…catch`',
    (static function () use ($operatorCapital): bool {
        $view = (string) file_get_contents(VIEW_PATH . '/operator/capital.php');
        $body = (string) $operatorCapital['body'];

        // 1. State literal di view — tidak ada komponen yang bisa gagal terdaftar
        //    (kejadian nyata: aset JS yang di-cache membuat
        //    `x-data="clipboardButton(…)"` gagal init sehingga tombolnya hilang).
        if (!str_contains($view, 'x-data="{ copied: false }"')) {
            return false;
        }

        // 2. Rantai Promise utuh pada HTML yang benar-benar dikirim.
        if (preg_match(
            '#@click="navigator\.clipboard\.writeText\(\'[^\']*\'\)\.then\(\(\) => '
            . '\{ copied = true; setTimeout\(\(\) => copied = false, 2000\); \}\)"#',
            $body
        ) !== 1) {
            return false;
        }

        // 3. Tidak ada pernyataan `try`/`catch` pada atribut klik mana pun.
        if (preg_match('/@click="[^"]*\b(try|catch)\b/', $body) === 1) {
            return false;
        }

        // 4. Umpan balik teks & visual tetap ada dan benar-benar dirender.
        return str_contains($body, 'Tersalin ✓')
            && str_contains($body, ":class=\"copied ? 'text-emerald-500' : ''\"")
            && str_contains($body, 'Salin tautan');
    })(),
    'tidak ada komponen yang bisa gagal terdaftar lagi (anti-ReferenceError)'
);

Qc::ok(
    'J.11',
    'Tidak ada lagi ketergantungan pada komponen `clipboardButton` di kode view',
    qc5_scan(
        ['app/Views/operator/capital.php'],
        static fn (string $line): bool => str_contains($line, 'clipboardButton')
    ) === [],
    'pemindai melewati baris komentar sehingga riwayatnya tetap boleh terdokumentasi'
);

Qc::ok(
    'J.12',
    'Tautan pada atribut `@click` aman sebagai literal string JS (tanpa kutip/backslash)',
    (static function () use ($operatorCapital): bool {
        if (preg_match(
            '#@click="navigator\.clipboard\.writeText\(\'(https?://[A-Za-z0-9.\-]+(:\d{1,5})?/join\?token=[0-9a-f]{64})\'\)#',
            (string) $operatorCapital['body'],
            $matches
        ) !== 1) {
            return false;
        }

        $url = (string) $matches[1];

        // Token undangan selalu 64 heksadesimal dan host dibatasi
        // `App::absoluteUrl()`, sehingga URL tidak dapat memutus string JS.
        return !str_contains($url, '"')
            && !str_contains($url, '\\')
            && !str_contains($url, '<');
    })(),
    'pola diperiksa pada HTML terkirim, bukan pada berkas view'
);

Qc::ok(
    'J.13',
    'Setiap layout yang merender view ber-Alpine memuat alpine.min.js (bootstrap tidak boleh hilang)',
    (static function (): bool {
        // View yang memakai direktif Alpine  ->  layout yang merendernya.
        $peta = [
            'investor/login.php'      => 'auth.php',     // x-data, x-for, x-text
            'investor/onboarding.php' => 'auth.php',     // x-data, @submit, @scroll
            'investor/dashboard.php'  => 'investor.php', // x-data, x-model
            'auth/login.php'          => 'auth.php',     // tanpa direktif: pemetaan tetap aman
        ];

        foreach ($peta as $view => $layout) {
            $viewFile = VIEW_PATH . '/' . $view;
            $layoutFile = VIEW_PATH . '/layouts/' . $layout;

            if (!is_file($viewFile) || !is_file($layoutFile)) {
                return false;
            }

            $rendered = (string) file_get_contents($viewFile);
            $shell = (string) file_get_contents($layoutFile);

            // View tanpa direktif Alpine tidak menuntut apa pun dari layout.
            if (!str_contains($rendered, 'x-data') && !str_contains($rendered, 'x-for')
                && !str_contains($rendered, 'x-text') && !str_contains($rendered, 'x-model')
                && !str_contains($rendered, 'x-show') && !str_contains($rendered, 'x-cloak')
            ) {
                continue;
            }

            if (!str_contains($shell, 'assets/js/alpine.min.js')) {
                return false;
            }
        }

        return true;
    })(),
    'layout tanpa bootstrap Alpine membuat tombol ber-x-text dirender kosong'
);

$manifestRaw = is_file(PUBLIC_PATH . '/manifest.json')
    ? (string) file_get_contents(PUBLIC_PATH . '/manifest.json')
    : '';
$manifest = json_decode($manifestRaw, true);

Qc::ok(
    'J.1',
    'manifest.json valid & memuat atribut installability',
    is_array($manifest)
        && ($manifest['display'] ?? '') === 'standalone'
        && ($manifest['theme_color'] ?? '') === '#07080B'
        && ($manifest['background_color'] ?? '') === '#07080B'
        && ($manifest['start_url'] ?? '') === './'
        && ($manifest['scope'] ?? '') === './'
        && ($manifest['lang'] ?? '') === 'id',
    'start_url & scope relatif supaya pemasangan subdirektori benar'
);

Qc::ok(
    'P5.21',
    'Ikon manifest lengkap (192, 512, maskable, apple-touch) dengan path relatif',
    (static function () use ($manifest): bool {
        $icons = $manifest['icons'] ?? [];

        if (!is_array($icons) || count($icons) !== 4) {
            return false;
        }

        $purposes = [];

        foreach ($icons as $icon) {
            if (!str_starts_with((string) ($icon['src'] ?? ''), './')) {
                return false;
            }

            $purposes[] = (string) ($icon['purpose'] ?? '');
        }

        return in_array('maskable', $purposes, true) && in_array('any', $purposes, true);
    })(),
    'path relatif terhadap manifest'
);

$iconProblems = [];
$iconExpected = [
    'icon-192.png'             => 192,
    'icon-512.png'             => 512,
    'icon-maskable-512.png'    => 512,
    'apple-touch-icon-180.png' => 180,
];

foreach ($iconExpected as $file => $size) {
    $path = PUBLIC_PATH . '/assets/icons/' . $file;

    if (!is_file($path)) {
        $iconProblems[] = $file . ' tidak ada';

        continue;
    }

    $bytes = (string) file_get_contents($path, false, null, 0, 33);

    if (!str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
        $iconProblems[] = $file . ' bukan PNG';

        continue;
    }

    // Dimensi dibaca dari header IHDR — tanpa ekstensi GD.
    $dimensions = unpack('Nwidth/Nheight', substr($bytes, 16, 8));

    if (($dimensions['width'] ?? 0) !== $size || ($dimensions['height'] ?? 0) !== $size) {
        $iconProblems[] = $file . ' berukuran tidak sesuai';
    }
}

Qc::ok(
    'P5.22',
    'Ikon PWA ada dan ber-PNG sah dengan dimensi sesuai manifest',
    $iconProblems === [],
    $iconProblems === [] ? '4 ikon diverifikasi dari header IHDR' : implode(', ', $iconProblems)
);

$serviceWorker = is_file(PUBLIC_PATH . '/sw.js') ? (string) file_get_contents(PUBLIC_PATH . '/sw.js') : '';

Qc::ok(
    'J.2',
    'sw.js memakai daftar-putih aset statis dan memperlakukan navigasi secara khusus',
    $serviceWorker !== ''
        && str_contains($serviceWorker, 'SHELL')
        && str_contains($serviceWorker, 'offline.html')
        && str_contains($serviceWorker, "request.mode === 'navigate'"),
    'navigasi tidak pernah dilayani dari cache'
);

Qc::ok(
    'J.3',
    'sw.js hanya menyimpan GET same-origin berstatus 200 (HTML/CSV/POST tidak pernah di-cache)',
    str_contains($serviceWorker, "request.method !== 'GET'")
        && str_contains($serviceWorker, 'url.origin !== self.location.origin')
        && str_contains($serviceWorker, 'response.status === 200')
        && str_contains($serviceWorker, 'STATIC_PREFIX')
        && !str_contains($serviceWorker, 'cache.add(request)'),
    'daftar-putih eksplisit untuk aset statis'
);

Qc::ok(
    'J.4',
    'sw.js membuang cache versi lama saat activate',
    str_contains($serviceWorker, 'caches.keys()')
        && str_contains($serviceWorker, 'caches.delete(')
        && str_contains($serviceWorker, 'CACHE_NAME'),
    'versi cache dikelola eksplisit'
);

Qc::ok(
    'J.5',
    'Halaman offline tersedia, statis, dan tanpa data dinamis',
    is_file(PUBLIC_PATH . '/offline.html')
        && !str_contains((string) file_get_contents(PUBLIC_PATH . '/offline.html'), '<?=')
        && str_contains((string) file_get_contents(PUBLIC_PATH . '/offline.html'), 'Koneksi terputus'),
    'jatuhan tanpa angka keuangan'
);

Qc::ok(
    'J.6',
    'Registrasi service worker di app.js dijaga HTTPS/localhost',
    (static function (): bool {
        $js = (string) file_get_contents(PUBLIC_PATH . '/assets/js/app.js');

        return str_contains($js, "'serviceWorker' in navigator")
            && str_contains($js, 'isSecureContext')
            && str_contains($js, "location.protocol === 'https:'")
            && str_contains($js, 'navigator.serviceWorker.register');
    })(),
    'tidak mendaftar pada origin tidak aman'
);

Qc::ok(
    'J.7',
    'Total berkas PWA + ikon tetap ringan (< 400 KB)',
    (static function (): bool {
        $total = 0;
        $paths = [
            PUBLIC_PATH . '/manifest.json',
            PUBLIC_PATH . '/sw.js',
            PUBLIC_PATH . '/offline.html',
            PUBLIC_PATH . '/assets/icons/icon-192.png',
            PUBLIC_PATH . '/assets/icons/icon-512.png',
            PUBLIC_PATH . '/assets/icons/icon-maskable-512.png',
            PUBLIC_PATH . '/assets/icons/apple-touch-icon-180.png',
        ];

        foreach ($paths as $path) {
            if (!is_file($path)) {
                return false;
            }

            $total += (int) filesize($path);
        }

        return $total < 400 * 1024;
    })(),
    'aset ini di-commit, jadi ukurannya dijaga'
);

Qc::ok(
    'J.8',
    'Komponen Alpine Fase 5 terdaftar (investorLogin & investorDashboard) — tombol salin tautan swasembada di view',
    (static function (): bool {
        $js = (string) file_get_contents(PUBLIC_PATH . '/assets/js/app.js');

        return str_contains($js, "Alpine.data('investorLogin', investorLogin)")
            && str_contains($js, "Alpine.data('investorDashboard', investorDashboard)")
            // Tombol salin tautan sengaja TIDAK lagi berupa komponen terdaftar:
            // aset yang di-cache peramban pernah membuat komponen tidak dikenal
            // (`ReferenceError: clipboardButton is not defined`) sehingga tombolnya
            // hilang. Markup-nya kini liter di view (lihat J.10).
            && !str_contains($js, 'clipboardButton');
    })(),
    'papan angka PIN + pemilih jenis penarikan; tanpa ketergantungan komponen baru'
);

// ---------------------------------------------------------------------------
// K. Nol DDL & keselarasan dokumen
// ---------------------------------------------------------------------------

Qc::section('K. Nol DDL & keselarasan dokumen');

$expectedTables = [
    'bridge_loans',
    'capital_wallets',
    'cash_mutations',
    'cash_registers',
    'daily_settlements',
    'hulu_transactions',
    'profit_distributions',
    'system_settings',
    'users',
    'withdrawal_requests',
];

$tableRows = Database::fetchAll(
    'SELECT `table_name` AS `name` FROM information_schema.tables WHERE table_schema = DATABASE()'
);
$tableNames = array_map(static fn (array $row): string => (string) $row['name'], $tableRows);
sort($tableNames);

Qc::equals(
    'K.1',
    'Skema 10 tabel ERD — Fase 6 menambah `bridge_loans`; tabel lama tidak berubah kolom',
    $expectedTables,
    $tableNames
);

$userColumns = Database::fetchAll(
    'SELECT `column_name` AS `name` FROM information_schema.columns '
    . 'WHERE table_schema = DATABASE() AND table_name = :table',
    ['table' => 'users']
);

$userColumnNames = array_map(static fn (array $row): string => (string) $row['name'], $userColumns);

Qc::ok(
    'K.2',
    'Kolom `users` tidak bertambah (invite_token + signed_* sudah ada sejak Fase 1)',
    in_array('invite_token', $userColumnNames, true)
        && in_array('onboarding_signed_at', $userColumnNames, true)
        && in_array('signed_ip_address', $userColumnNames, true)
        && in_array('signed_user_agent', $userColumnNames, true)
        && count($userColumnNames) === 12,
    'jumlah kolom=' . count($userColumnNames)
);

$decimalViolations = QcFixture::checkDecimalConventions([
    ['capital_wallets', 'balance_capital', 14, 2],
    ['capital_wallets', 'balance_profit', 14, 2],
    ['profit_distributions', 'capital_utilized', 14, 2],
    ['profit_distributions', 'profit_earned', 14, 2],
    ['withdrawal_requests', 'amount', 14, 2],
]);

Qc::ok(
    'K.3',
    'Konvensi DECIMAL(14,2) tetap dijaga pada kolom uang yang dipakai Fase 5',
    $decimalViolations === [],
    $decimalViolations === [] ? '5 kolom diperiksa' : implode('; ', $decimalViolations)
);

$uiUx = (string) file_get_contents(BASE_PATH . '/docs/AurumFlow UI UX Documents.md');
$prd = (string) file_get_contents(BASE_PATH . '/docs/AurumFlow Product Requirement Document v3.0.md');
$roadmap = (string) file_get_contents(BASE_PATH . '/docs/AurumFlow Roadmap and QC Documents.md');
$sad = (string) file_get_contents(BASE_PATH . '/docs/AurumFlow System Architecture Document.md');
$agents = (string) file_get_contents(BASE_PATH . '/AGENTS.md');
$readme = (string) file_get_contents(BASE_PATH . '/README.md');

Qc::ok(
    'K.4',
    'docs/ memuat keputusan Fase 5 (PIN ber-pepper, throttle, sekali pakai, PWA)',
    str_contains($prd, 'APP_PIN_PEPPER')
        && str_contains($prd, 'PinThrottle')
        && str_contains($prd, 'sekali pakai')
        && str_contains($sad, 'PinThrottle')
        && str_contains($roadmap, 'Protokol QC Manual 5')
        && str_contains($uiUx, 'Layar A2'),
    'PRD, SAD, Roadmap, UI/UX'
);

Qc::ok(
    'P5.23',
    'Dokumen tidak lagi menjanjikan PDF buatan peladen untuk dasbor mitra',
    !str_contains($uiUx, 'Unduh Laporan Bulanan Ringkas (PDF)')
        && str_contains($uiUx, 'Lihat & Cetak Laporan')
        && (str_contains($prd, 'Unduhan CSV') || str_contains($prd, 'Unduh CSV')),
    'keputusan pengguna dec-ef670a108317f925 tercermin di dokumen'
);

Qc::ok(
    'K.5',
    'AGENTS.md memuat kontrak baru (isolasi blind, PIN satu pintu, throttle, PWA)',
    str_contains($agents, 'Isolasi blind investor')
        && str_contains($agents, 'PinThrottle')
        && str_contains($agents, 'ber-pepper, satu pintu')
        && str_contains($agents, 'PWA hanya men-cache aset statis'),
    'dokumentasi kerja sama dengan kode'
);

Qc::ok(
    'K.6',
    'README memuat APP_PIN_PEPPER, storage/throttle, dan ringkasan Fase 5',
    str_contains($readme, 'APP_PIN_PEPPER')
        && str_contains($readme, 'storage/throttle')
        && str_contains($readme, 'Portal mitra'),
    'panduan operasional'
);

Qc::ok(
    'K.7',
    '.env.example memuat APP_PIN_PEPPER lengkap dengan peringatan rotasi',
    str_contains((string) file_get_contents(BASE_PATH . '/.env.example'), 'APP_PIN_PEPPER')
        && str_contains((string) file_get_contents(BASE_PATH . '/.env.example'), 'jangan diputar'),
    'konfigurasi baru terdokumentasi'
);

Qc::ok(
    'K.8',
    'Isi storage/throttle/ tidak ikut version control',
    str_contains((string) file_get_contents(BASE_PATH . '/.gitignore'), '/storage/throttle/*')
        && is_file(BASE_PATH . '/storage/throttle/.gitignore'),
    'state runtime, bukan artefak'
);

Qc::ok(
    'K.9',
    'Skema & ERD tidak disentuh (tidak ada kolom baru seperti waiver_version/pin_attempts)',
    str_contains((string) file_get_contents(DATABASE_PATH . '/schema.sql'), 'CREATE TABLE IF NOT EXISTS `users`')
        && !str_contains((string) file_get_contents(DATABASE_PATH . '/schema.sql'), 'waiver_version')
        && !str_contains((string) file_get_contents(DATABASE_PATH . '/schema.sql'), 'pin_attempts'),
    'nol migrasi'
);

// ---------------------------------------------------------------------------
// Pemulihan keadaan & perilaku tambahan
// ---------------------------------------------------------------------------

Qc::section('Pemulihan keadaan: fixture idempotent & klamp dasbor');

QcFixture::resetInvestorState();

$restored = QcFixture::investorSnapshot();

Qc::ok(
    'R.1',
    'Keadaan mitra QC dipulihkan (4 distribusi, 0 pengajuan, throttle kosong)',
    $restored['distributions'] === 4
        && $restored['withdrawals'] === 0
        && $restored['user']['onboarding_signed_at'] !== null
        && PinThrottle::count() === 0,
    'distribusi=' . $restored['distributions'] . ' pengajuan=' . $restored['withdrawals']
);

Qc::ok(
    'R.2',
    'Suite dapat dijalankan ulang: seed kedua menghasilkan angka dasbor yang sama',
    (static function (): bool {
        $first = QcFixture::seedQcInvestor();
        $firstDash = InvestorService::dashboard((string) $first['user_id']);
        $second = QcFixture::seedQcInvestor();
        $secondDash = InvestorService::dashboard((string) $second['user_id']);

        return $firstDash['rotating_label'] === $secondDash['rotating_label']
            && $firstDash['total_label'] === $secondDash['total_label']
            && $firstDash['reserve_label'] === $secondDash['reserve_label']
            && $firstDash['roi_label'] === $secondDash['roi_label'];
    })(),
    'dompet & empat putaran kembali ke titik yang sama'
);

Qc::ok(
    'R.3',
    'Klamp "Modal Berputar": `capital_utilized` melebihi modal tetap dibatasi saldo modal',
    (static function (): bool {
        $fixture = QcFixture::seedQcInvestor('25000000.00');
        $dash = InvestorService::dashboard((string) $fixture['user_id']);

        return $dash['rotating_label'] === 'Rp 20.000.000'
            && $dash['reserve_label'] === 'Rp 0'
            && $dash['ratio_label'] === '100,00 %';
    })(),
    'utilized 25 juta vs modal 20 juta'
);

Qc::ok(
    'R.4',
    'Tanpa putaran: dasbor tetap terbuka dengan Modal Berputar & Kas Cadangan nol',
    (static function (): bool {
        $fixture = QcFixture::seedQcInvestor();
        Database::query(
            'DELETE FROM `profit_distributions` WHERE `wallet_id` = :wallet_id',
            ['wallet_id' => (string) $fixture['wallet_id']]
        );

        $dash = InvestorService::dashboard((string) $fixture['user_id']);

        return $dash['has_rounds'] === false
            && $dash['rotating_label'] === 'Rp 0'
            && $dash['reserve_label'] === 'Rp 20.000.000';
    })(),
    'layar menampilkan keadaan "belum ada putaran"'
);

Qc::ok(
    'R.5',
    'Dividen bulan berjalan dihitung hanya dari tanggal putaran di bulan ini (baris 40 hari lalu dikecualikan)',
    (static function (): bool {
        $fixture = QcFixture::seedQcInvestor();
        $dash = InvestorService::dashboard((string) $fixture['user_id']);

        $expectedCurrentMonth = 0;
        $expectedAll = 0;

        foreach ($fixture['rounds'] as $round) {
            $cents = (int) TellerService::moneyToCents($round['profit']);
            $expectedAll += $cents;

            if (str_starts_with((string) $round['date'], date('Y-m'))) {
                $expectedCurrentMonth += $cents;
            }
        }

        return $dash['month_profit_label'] === Formatter::rupiah(
            TellerService::centsToDecimal($expectedCurrentMonth)
        )
            && $dash['all_time_profit_label'] === Formatter::rupiah(
                TellerService::centsToDecimal($expectedAll)
            );
    })(),
    'batas bulan kalender dihormati'
);

QcFixture::resetInvestorState();

// Tabel protokol manual 5 (bentuk ringkas seperti dokumen Roadmap).
Qc::note('Tabel Protokol QC Manual 5 (ringkas):');
Qc::note('  | Audit jejak hukum        | onboarding_signed_at + IP + User-Agent terisi   |');
Qc::note('  | Tautan sekali pakai      | klaim ulang ditolak, nol tulisan               |');
Qc::note('  | PIN lemah ditolak        | 111111/123456/654321/123123 -> ditolak         |');
Qc::note('  | Pembatasan percobaan     | 5 gagal -> 429 + kunci 15 menit                |');
Qc::note('  | Isolasi laba pengelola   | omzet/hak 80 %/gramatur/alias tidak muncul     |');
Qc::note('  | Isolasi mitra lain       | nama & saldo dompet lain tidak muncul          |');
Qc::note('  | Akses URL operator ilegal| mitra -> 302 /investor/dashboard               |');
Qc::note('  | Pintu PIN terpisah       | PIN tidak sah di /login, dan sebaliknya        |');
Qc::note('  | Tarik modal H-7          | jatuh tempo hari kerja ke-7, dompet queued     |');
Qc::note('  | Ekspor ramah awam        | laporan cetak 200 + CSV text/csv               |');
Qc::note('  | PWA installability       | manifest valid + ikon 192/512 + SW aset statis |');

Qc::finish('qc_fase_5');
