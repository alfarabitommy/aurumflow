<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AurumFlow — Migrasi Aditif (CLI)
|--------------------------------------------------------------------------
| Dijalankan pada instalasi yang SUDAH berjalan ketika satu fase menambahkan
| sesuatu secara aditif pada skema. `storage/installed.lock` membuat
| `database/install.php` menolak dijalankan ulang, sehingga perubahan aditif
| butuh pintu sendiri yang sempit dan aman:
|
|   1. `INSTALL_TOKEN` pada `.env` HARUS diisi (gate sama dengan installer).
|      Selama kosong, skrip ini menolak berjalan — jadi permukaan yang tertinggal
|      di hosting tidak dapat dipakai siapa pun.
|   2. Setiap migrasi **idempoten** dan **terjaga**: ia memeriksa keadaan
|      INFORMATION_SCHEMA lebih dahulu, lalu menulis hanya bila perlu.
|   3. Skrip ini **tidak** menulis `storage/installed.lock`, tidak menyentuh
|      `schema.sql`/`seed.sql`, dan tidak pernah menulis ulang baris data.
|
| Migrasi Fase 7:
|   M1 — `users.role`: tambah nilai 'cashier' DI UJUNG daftar ENUM.
|        DDL-nya hidup di `User::ensureRoleEnum()` (SQL hanya di app/Models/).
|
| Migrasi Fase 8:
|   M2 — tiga tabel buku kasbon (`miners`, `miner_loans`, `miner_loan_ledger`)
|        + kunci `system_settings.daily_loan_limit`. DDL-nya hidup di
|        `Miner::ensureSchema()`, seed-nya di `SystemSetting::ensure()` —
|        keduanya `IF NOT EXISTS` / `WHERE NOT EXISTS`, sehingga idempoten.
|
| Migrasi Fase 9:
|   M3 — tabel `operational_expenses` (beban operasional non-emas / OPEX).
|        DDL-nya hidup di `OperationalExpense::ensureSchema()`
|        (`CREATE TABLE IF NOT EXISTS`), sehingga idempoten dan tidak pernah
|        mengubah tabel lama (nol ALTER).
|
| Migrasi Fase 10:
|   M4 — buku hak mitra lapangan: nilai ENUM `'officer'` di ujung
|        `users.role` (M4a, `User::ensureRoleEnum()`), dua tabel baru
|        `officers` + `officer_ledger_entries` (M4b, `Officer::ensureSchema()`),
|        dan kunci `system_settings.officer_cut_total_cap_pct` (M4c).
|        Kembali **nol ALTER** pada tabel lama.
|
| Pemakaian:
|   php database/migrate.php
|
| Setelah dijalankan, HAPUS berkas ini dari hosting bersama `install.php`.
*/

use AurumFlow\Config\Env;
use AurumFlow\Models\Miner;
use AurumFlow\Models\Officer;
use AurumFlow\Models\OperationalExpense;
use AurumFlow\Models\SystemSetting;
use AurumFlow\Models\User;
use AurumFlow\Services\OfficerService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Migrasi hanya dapat dijalankan lewat CLI.';
    exit(1);
}

require dirname(__DIR__) . '/app/Config/bootstrap.php';

$env = static function (string $key, string $default = ''): string {
    $value = Env::get($key);

    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
};

$say = static function (string $line): void {
    fwrite(STDOUT, $line . PHP_EOL);
};

$say('AurumFlow — migrasi aditif');
$say('==========================');

// ---------------------------------------------------------------------------
// 1. Gate token (selalu diperiksa)
// ---------------------------------------------------------------------------
if ($env('INSTALL_TOKEN') === '') {
    fwrite(
        STDERR,
        "MIGRASI DITOLAK: INSTALL_TOKEN belum diisi pada .env.\n"
        . "Isi token acak pada baris INSTALL_TOKEN= lebih dahulu (lihat README § 4.1).\n"
    );
    exit(1);
}

// ---------------------------------------------------------------------------
// 2. Koneksi
// ---------------------------------------------------------------------------
try {
    AurumFlow\Config\Database::getConnection();
} catch (Throwable $exception) {
    fwrite(STDERR, 'MIGRASI GAGAL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

// ---------------------------------------------------------------------------
// 3. M1 — users.role menerima peran kasir
// ---------------------------------------------------------------------------
$before = User::roleEnumValues();

if ($before === []) {
    fwrite(
        STDERR,
        "MIGRASI GAGAL: kolom `users`.`role` tidak ditemukan.\n"
        . "Jalankan database/install.php lebih dahulu.\n"
    );
    exit(1);
}

try {
    $applied = User::ensureRoleEnum();
} catch (Throwable $exception) {
    fwrite(STDERR, 'MIGRASI GAGAL pada M1 (users.role): ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$after = User::roleEnumValues();

$say(
    $applied
        ? '  [M1] users.role: diterapkan — ' . implode(', ', $after)
        : '  [M1] users.role: sudah diterapkan — ' . implode(', ', $after)
);

// ---------------------------------------------------------------------------
// 4. M2 — tiga tabel buku kasbon + batas harian (Fase 8)
// ---------------------------------------------------------------------------
// `CREATE TABLE IF NOT EXISTS` + `SystemSetting::ensure()` membuat langkah ini
// idempoten: menjalankan skrip dua kali tidak mengubah tabel yang sudah ada dan
// tidak menimpa `daily_loan_limit` yang mungkin sudah disesuaikan operator.
try {
    $kasbonStatements = Miner::ensureSchema();
} catch (Throwable $exception) {
    fwrite(STDERR, 'MIGRASI GAGAL pada M2 (tabel kasbon): ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

try {
    $settingCreated = SystemSetting::ensure(
        'daily_loan_limit',
        '500000.00',
        'Batas total pokok kasbon yang boleh dicairkan per hari (Rp)'
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'MIGRASI GAGAL pada M2 (daily_loan_limit): ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$dailyLimit = SystemSetting::get('daily_loan_limit');

$say(
    '  [M2] tabel kasbon: ' . $kasbonStatements . ' pernyataan CREATE TABLE IF NOT EXISTS dijalankan'
);
$say(
    '  [M2] daily_loan_limit: ' . ($settingCreated ? 'dibuat' : 'sudah ada')
    . ' — nilai ' . ($dailyLimit === null ? '(tidak terbaca)' : $dailyLimit)
);

// ---------------------------------------------------------------------------
// 5. M3 — tabel beban operasional non-emas (Fase 9)
// ---------------------------------------------------------------------------
// `CREATE TABLE IF NOT EXISTS` membuat langkah ini idempoten: menjalankan skrip
// dua kali tidak mengubah tabel yang sudah ada, dan tidak ada ALTER pada tabel
// lama (nol ALTER destruktif).
try {
    $expenseStatements = OperationalExpense::ensureSchema();
} catch (Throwable $exception) {
    fwrite(STDERR, 'MIGRASI GAGAL pada M3 (operational_expenses): ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$expenseTableReady = OperationalExpense::exists();

$say(
    '  [M3] operational_expenses: ' . $expenseStatements
    . ' pernyataan CREATE TABLE IF NOT EXISTS dijalankan'
);

// ---------------------------------------------------------------------------
// 6. M4 — buku hak mitra lapangan (Fase 10)
// ---------------------------------------------------------------------------
// Tiga langkah, semuanya aditif & idempoten:
//   M4a `users.role` menerima nilai 'officer' (di UJUNG daftar) — DDL hidup di
//       `User::ensureRoleEnum()`.
//   M4b dua tabel baru (`officers`, `officer_ledger_entries`) — DDL hidup di
//       `Officer::ensureSchema()`. Nol ALTER pada tabel lama.
//   M4c kunci `system_settings.officer_cut_total_cap_pct` — `SystemSetting::ensure()`.
try {
    $roleApplied = User::ensureRoleEnum();
} catch (Throwable $exception) {
    fwrite(STDERR, 'MIGRASI GAGAL pada M4a (users.role): ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$roleValues = User::roleEnumValues();

try {
    $officerStatements = Officer::ensureSchema();
} catch (Throwable $exception) {
    fwrite(STDERR, 'MIGRASI GAGAL pada M4b (tabel mitra lapangan): ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

try {
    $cutCapCreated = SystemSetting::ensure(
        OfficerService::SETTING_CUT_TOTAL_CAP_PCT,
        '100.00',
        'Batas atas jumlah persentase potongan seluruh mitra lapangan aktif (%)'
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'MIGRASI GAGAL pada M4c (officer_cut_total_cap_pct): ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$cutCap = SystemSetting::get(OfficerService::SETTING_CUT_TOTAL_CAP_PCT);

$say(
    '  [M4a] users.role: ' . ($roleApplied ? 'diterapkan' : 'sudah diterapkan')
    . ' — ' . implode(', ', $roleValues)
);
$say(
    '  [M4b] tabel mitra lapangan: ' . $officerStatements
    . ' pernyataan CREATE TABLE IF NOT EXISTS dijalankan (officers, officer_ledger_entries)'
);
$say(
    '  [M4c] officer_cut_total_cap_pct: ' . ($cutCapCreated ? 'dibuat' : 'sudah ada')
    . ' — nilai ' . ($cutCap === null ? '(tidak terbaca)' : $cutCap)
);

// ---------------------------------------------------------------------------
// 7. Laporan
// ---------------------------------------------------------------------------
$say('');
$say('RINGKASAN');
$say('  Nilai ENUM sebelum : [' . implode(', ', $before) . ']');
$say('  Nilai ENUM sesudah : [' . implode(', ', $after) . ']');
$say('  Tabel kasbon       : 3 (miners, miner_loans, miner_loan_ledger)');
$say('  Tabel beban (OPEX) : 1 (operational_expenses)');
$say('  Tabel mitra lapangan: 2 (officers, officer_ledger_entries)');
$say('  Batas kasbon harian: ' . ($dailyLimit === null ? '(tidak terbaca)' : $dailyLimit));
$say('  Batas potongan     : ' . ($cutCap === null ? '(tidak terbaca)' : $cutCap));
$say('  Tabel beban siap   : ' . ($expenseTableReady ? 'ya (operational_expenses)' : 'TIDAK'));
$say(
    '  Perubahan          : '
    . ($applied || $settingCreated || $cutCapCreated || $roleApplied ? '1+ migrasi diterapkan' : 'tidak ada (semua sudah diterapkan)')
);
$say('  Baris data         : tidak ada yang ditulis ulang (migrasi aditif)');
$say('');
$say('MIGRASI SELESAI. Hapus berkas ini dari hosting setelah dipakai.');

exit(0);
