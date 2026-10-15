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
| Pemakaian:
|   php database/migrate.php
|
| Setelah dijalankan, HAPUS berkas ini dari hosting bersama `install.php`.
*/

use AurumFlow\Config\Env;
use AurumFlow\Models\User;

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
// 4. Laporan
// ---------------------------------------------------------------------------
$say('');
$say('RINGKASAN');
$say('  Nilai ENUM sebelum : [' . implode(', ', $before) . ']');
$say('  Nilai ENUM sesudah : [' . implode(', ', $after) . ']');
$say('  Perubahan skema    : ' . ($applied ? '1 migrasi diterapkan' : 'tidak ada (semua sudah diterapkan)'));
$say('  Baris data         : tidak ada yang ditulis ulang (migrasi aditif)');
$say('');
$say('MIGRASI SELESAI. Hapus berkas ini dari hosting setelah dipakai.');

exit(0);
