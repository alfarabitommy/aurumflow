<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AurumFlow — Runner Instalasi Fase 1
|--------------------------------------------------------------------------
| Tugas: menerapkan database/schema.sql (DDL ERD), database/seed.sql, dan
| membuat akun operator awal.
|
| Kenapa ada file ini padahal ada script QC?
|   Shared hosting sering tidak menyediakan CLI (atau menyediakannya tanpa
|   hak akses shell). Runner ini memungkinkan instalasi lewat browser.
|
| ATURAN KEAMANAN — WAJIB DIPATUHI:
|   1. INSTALL_TOKEN pada `.env` HARUS diisi. Selama kosong, script ini
|      menolak berjalan (QC 4.14).
|   2. Instalasi lewat browser hanya berjalan bila ?token=<INSTALL_TOKEN>
|      cocok (perbandingan hash_equals).
|   3. Setelah instalasi sukses, file ini WAJIB DIHAPUS dari hosting dan
|      INSTALL_TOKEN dikosongkan kembali.
|   4. Marker storage/installed.lock mencegah instalasi ulang tidak sengaja
|      (QC 4.15).
|
| PERINGATAN: menjalankan ulang setelah menghapus installed.lock akan
| MENIMPA password akun operator dengan nilai OPERATOR_PASSWORD saat itu.
*/

use AurumFlow\Config\App;
use AurumFlow\Config\Constants;
use AurumFlow\Config\Database;
use AurumFlow\Config\DatabaseException;
use AurumFlow\Config\Env;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\CapitalWallet;
use AurumFlow\Models\User;

require dirname(__DIR__) . '/app/Config/bootstrap.php';

$isCli = App::isCli();

/** Baca env dengan tipe string yang pasti (null -> default). */
$env = static fn (string $key, string $default = ''): string => Env::get($key) ?? $default;

/** Akhiri eksekusi dengan pesan yang sesuai untuk CLI maupun browser. */
$respond = static function (int $status, string $message, bool $ok = false) use ($isCli): never {
    if ($isCli) {
        fwrite($ok ? STDOUT : STDERR, $message . PHP_EOL);
        exit($ok ? 0 : 1);
    }

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
    }

    echo $message . PHP_EOL;
    exit($ok ? 0 : 1);
};

// ---------------------------------------------------------------------------
// 1. Validasi token (selalu diperiksa, termasuk pada CLI)
// ---------------------------------------------------------------------------
$expectedToken = $env('INSTALL_TOKEN');

if ($expectedToken === '') {
    $respond(
        500,
        "INSTALL DITOLAK: INSTALL_TOKEN belum diisi pada .env.\n"
        . 'Hasilkan token acak dengan: php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"'
        . "\nlalu isikan pada baris INSTALL_TOKEN=."
    );
}

if (!$isCli) {
    $providedToken = $_GET['token'] ?? '';

    if (!is_string($providedToken) || !hash_equals($expectedToken, $providedToken)) {
        $respond(403, 'INSTALL DITOLAK: token tidak valid.');
    }
}

// ---------------------------------------------------------------------------
// 2. Cegah instalasi ulang
// ---------------------------------------------------------------------------
$lockFile = STORAGE_PATH . '/installed.lock';

if (is_file($lockFile)) {
    $respond(
        409,
        "INSTALL DITOLAK: aplikasi sudah terpasang (marker: storage/installed.lock).\n"
        . 'Hapus marker tersebut secara sadar bila memang ingin memasang ulang.'
    );
}

// ---------------------------------------------------------------------------
// 3. Validasi kredensial operator awal
// ---------------------------------------------------------------------------
// Sesuai ERD, identitas pengguna adalah nomor telepon — tidak ada email.
$operatorName = $env('OPERATOR_FULL_NAME', 'Pengelola');
$operatorPhone = $env('OPERATOR_PHONE');
$operatorPassword = $env('OPERATOR_PASSWORD');

if ($operatorPhone === '') {
    $respond(500, 'INSTALL GAGAL: OPERATOR_PHONE belum diisi pada .env.');
}

if (preg_match('/^[0-9+\-\s]{8,20}$/', $operatorPhone) !== 1) {
    $respond(
        500,
        'INSTALL GAGAL: OPERATOR_PHONE tidak valid. '
        . 'Gunakan angka (boleh diawali + atau 0), panjang 8-20 karakter.'
    );
}

if ($operatorPassword === '') {
    $respond(500, 'INSTALL GAGAL: OPERATOR_PASSWORD belum diisi pada .env.');
}

if (!Security::passwordStrength($operatorPassword)) {
    $respond(
        500,
        'INSTALL GAGAL: OPERATOR_PASSWORD terlalu lemah. Minimal '
        . Security::PASSWORD_MIN_LENGTH
        . ' karakter dan memuat huruf serta angka.'
    );
}

// ---------------------------------------------------------------------------
// 4. Koneksi database
// ---------------------------------------------------------------------------
try {
    Database::getConnection();
} catch (DatabaseException $exception) {
    $respond(500, 'INSTALL GAGAL: ' . $exception->getMessage());
}

// ---------------------------------------------------------------------------
// 5. Terapkan skema dan seed
// ---------------------------------------------------------------------------
try {
    $schemaCount = Database::executeSqlFile(DATABASE_PATH . '/schema.sql');
    $seedCount = Database::executeSqlFile(DATABASE_PATH . '/seed.sql');
} catch (Throwable $exception) {
    $respond(500, 'INSTALL GAGAL saat menerapkan SQL: ' . $exception->getMessage());
}

// ---------------------------------------------------------------------------
// 6. Siapkan akun operator + dompet Pengelola + verifikasi pos kas (atomik)
// ---------------------------------------------------------------------------
// Dibungkus satu transaksi: membuat akun operator, menyiapkan dompet modalnya,
// dan memastikan pos kas tersedia adalah satu unit penyiapan. Bila salah satu
// gagal, tidak boleh ada kondisi setengah jadi. (SAD § 4.1 mewajibkan transaksi
// PDO untuk operasi yang menyentuh kas.)
//
// Dompet Pengelola (tier `operator`) adalah rumah prioritas serapan modal 50 %
// pada mesin alokasi Fase 4; tanpa dompet ini pencatatan serapan modal Pengelola
// tidak punya tempat. `ensureOperatorWallet()` idempotent sehingga instalasi
// ulang tidak membuat dompet ganda.
try {
    $operatorAction = Database::transaction(static function () use (
        $operatorName,
        $operatorPhone,
        $operatorPassword
    ): array {
        $existing = User::findByPhoneNumber($operatorPhone, false);

        if ($existing === null) {
            $operatorId = User::create($operatorName, $operatorPhone, $operatorPassword, Constants::ROLE_OPERATOR);
            $action = 'dibuat';
        } else {
            $operatorId = (string) $existing['id'];

            Database::update(
                'users',
                [
                    'full_name'     => $operatorName,
                    'password_hash' => Security::hashPassword($operatorPassword),
                    'role'          => Constants::ROLE_OPERATOR,
                    'is_active'     => 1,
                ],
                '`id` = :id',
                ['id' => $operatorId]
            );

            $action = 'diperbarui';
        }

        return [
            'action'    => $action,
            'user_id'   => $operatorId,
            'wallet_id' => CapitalWallet::ensureOperatorWallet($operatorId),
        ];
    });

    $registerRow = Database::fetchOne('SELECT COUNT(*) AS `total` FROM `cash_registers`');
    $registerCount = (int) ($registerRow['total'] ?? 0);

    if ($registerCount < 2) {
        $respond(
            500,
            'INSTALL GAGAL: seed pos kas tidak lengkap (ditemukan '
            . $registerCount . ', seharusnya 2). Periksa database/seed.sql.'
        );
    }
} catch (Throwable $exception) {
    $respond(500, 'INSTALL GAGAL saat menyiapkan akun operator: ' . $exception->getMessage());
}

// ---------------------------------------------------------------------------
// 7. Pastikan ENUM users.role selaras schema.sql (Fase 7)
// ---------------------------------------------------------------------------
// `CREATE TABLE IF NOT EXISTS` tidak menyentuh tabel yang sudah ada, sehingga
// basis data yang dipasang sebelum Fase 7 belum mengenal peran 'cashier'.
// Pemeriksaan ini menjaga agar salinan schema.sql yang basi tidak lolos diam-
// diam: instalasi baru harus langsung konsisten dengan ERD tiga peran.
try {
    if (User::ensureRoleEnum()) {
        // Tabel lama diperluas di tempat: nilai baru ditambahkan di ujung ENUM.
        // Tidak ada baris yang ditulis ulang (lihat User::ensureRoleEnum()).
        $schemaCount++;
    }
} catch (Throwable $exception) {
    $respond(500, 'INSTALL GAGAL saat menyelaraskan ENUM users.role: ' . $exception->getMessage());
}

$roleValues = User::roleEnumValues();

if ($roleValues !== User::ROLE_ENUM_VALUES) {
    $respond(
        500,
        'INSTALL GAGAL: users.role = [' . implode(', ', $roleValues) . '] '
        . 'tidak sesuai ERD [' . implode(', ', User::ROLE_ENUM_VALUES) . ']. '
        . 'Periksa database/schema.sql.'
    );
}

// ---------------------------------------------------------------------------
// 8. Tulis marker instalasi
// ---------------------------------------------------------------------------
if (!is_dir(STORAGE_PATH)) {
    set_error_handler(static fn (): bool => true);
    $created = mkdir(STORAGE_PATH, 0775, true);
    restore_error_handler();

    if (!$created && !is_dir(STORAGE_PATH)) {
        $respond(500, 'INSTALL GAGAL: direktori storage/ tidak dapat dibuat.');
    }
}

$marker = json_encode(
    [
        'installed_at' => date('c'),
        'database'     => $env('DB_NAME'),
        'operator'     => $operatorPhone,
    ],
    JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);

set_error_handler(static fn (): bool => true);
$locked = file_put_contents($lockFile, ($marker === false ? '{}' : $marker) . PHP_EOL, LOCK_EX);
restore_error_handler();

if ($locked === false) {
    $respond(500, 'INSTALL GAGAL: marker storage/installed.lock tidak dapat ditulis.');
}

// ---------------------------------------------------------------------------
// 9. Selesai
// ---------------------------------------------------------------------------
$respond(
    200,
    "INSTALL SELESAI.\n"
    . 'Database   : ' . $env('DB_NAME') . "\n"
    . 'Schema     : ' . $schemaCount . " statement\n"
    . 'Seed       : ' . $seedCount . " statement\n"
    . 'Pos kas    : ' . $registerCount . " baris\n"
    . 'Peran      : [' . implode(', ', $roleValues) . "]\n"
    . 'Akun operator : ' . $operatorPhone . ' -> ' . $operatorAction['action']
    . ' (id ' . $operatorAction['user_id'] . ")\n"
    . 'Dompet Pengelola : ' . $operatorAction['wallet_id'] . " (tier operator)\n"
    . "Password awal wajib diganti setelah login pertama.\n\n"
    . "TINDAK LANJUT WAJIB:\n"
    . "  1. HAPUS file database/install.php dan database/migrate.php dari hosting.\n"
    . "  2. Kosongkan INSTALL_TOKEN pada .env.\n"
    . '  3. Pastikan storage/installed.lock TIDAK dapat diakses via web.',
    true
);
