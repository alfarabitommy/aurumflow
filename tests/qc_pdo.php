<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| QC-1 — PDO Connection (10 test case)
|--------------------------------------------------------------------------
| Membuktikan bahwa lapisan database benar-benar dikonfigurasi aman:
| exception-based, character set penuh, timezone selaras, prepared statement
| native, dan presisi DECIMAL tidak hilang.
*/

require __DIR__ . '/bootstrap.php';

use AurumFlow\Config\App;
use AurumFlow\Config\Database;
use AurumFlow\Config\DatabaseException;
use AurumFlow\Helpers\Logger;

Qc::suite('1 — PDO Connection (qc_pdo.php)');

// ---------------------------------------------------------------------------
Qc::section('Koneksi dan konfigurasi driver');

// 1.1
try {
    $pdo = Database::getConnection();
    Qc::ok('1.1', 'Database::getConnection() mengembalikan instance PDO', $pdo instanceof PDO);
} catch (Throwable $exception) {
    Qc::ok('1.1', 'Database::getConnection() mengembalikan instance PDO', false, $exception->getMessage());
    Qc::finish('qc_pdo');
}

// 1.2 — versi server
$version = (string) Database::getConnection()->query('SELECT VERSION()')->fetchColumn();
$isMaria = stripos($version, 'mariadb') !== false;

Qc::ok(
    '1.2',
    'Server database merespons dan melaporkan versi',
    preg_match('/^\d+\.\d+\.\d+/', $version) === 1,
    'Versi terdeteksi: ' . $version
);

Qc::note('Versi server: ' . $version);
Qc::note('Rencana menargetkan MySQL 8.0. Lingkungan QC ini memakai '
    . ($isMaria ? 'MariaDB (MySQL-compatible)' : 'MySQL') . '.');
Qc::note('Seluruh pernyataan QC (InnoDB, DECIMAL, JSON, FK, prepared statement) '
    . 'berperilaku sama pada kedua server; hanya T4 (target produksi) yang berbeda.');

$innodbSupport = (string) Database::getConnection()
    ->query("SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'")
    ->fetchColumn();

Qc::ok(
    '1.2b',
    'Storage engine InnoDB tersedia dan aktif',
    in_array(strtoupper($innodbSupport), ['DEFAULT', 'YES'], true),
    'SUPPORT=' . $innodbSupport
);

// 1.3
$charset = (string) Database::getConnection()->query('SELECT @@character_set_client')->fetchColumn();
Qc::equals('1.3', 'character_set_client = utf8mb4', 'utf8mb4', $charset);

// 1.4
$sessionTimezone = (string) Database::getConnection()->query('SELECT @@session.time_zone')->fetchColumn();
Qc::equals('1.4', 'Session time zone selaras dengan timezone aplikasi (' . App::utcOffset() . ')', App::utcOffset(), $sessionTimezone);

// 1.5
$sqlMode = (string) Database::getConnection()->query('SELECT @@sql_mode')->fetchColumn();
Qc::ok(
    '1.5',
    'sql_mode memuat STRICT_TRANS_TABLES',
    str_contains($sqlMode, 'STRICT_TRANS_TABLES'),
    'sql_mode=' . $sqlMode
);

// ---------------------------------------------------------------------------
Qc::section('Penanganan error');

// 1.6
Qc::throws(
    '1.6',
    'SQL tidak valid melempar PDOException (bukan warning)',
    static function (): void {
        Database::query('SELECT * FROM tabel_yang_tidak_ada_qc');
    },
    PDOException::class
);

// 1.7 — kredensial salah
$wrongSecret = 'password-salah-' . bin2hex(random_bytes(4));
$logBefore = is_file(Logger::currentFile()) ? (string) file_get_contents(Logger::currentFile()) : '';

putenv('DB_PASS=' . $wrongSecret);
Database::disconnect();

$leaked = false;
$generic = false;
$threw = false;

try {
    Database::getConnection();
} catch (DatabaseException $exception) {
    $threw = true;
    $generic = str_contains($exception->getMessage(), 'Koneksi database gagal');
    $leaked = str_contains($exception->getMessage(), $wrongSecret)
        || str_contains($exception->getMessage(), 'Access denied')
        || str_contains($exception->getMessage(), 'SQLSTATE');
} catch (Throwable $exception) {
    // Tipe exception lain tetap dianggap gagal.
    $threw = false;
}

putenv('DB_PASS');
Database::disconnect();

Qc::ok('1.7a', 'Kredensial salah melempar DatabaseException', $threw);
Qc::ok('1.7b', 'Pesan ke pengguna bersifat generik', $generic);
Qc::ok('1.7c', 'Pesan exception TIDAK membocorkan detail teknis', !$leaked);

$logAfter = is_file(Logger::currentFile()) ? (string) file_get_contents(Logger::currentFile()) : '';
$logDelta = substr($logAfter, strlen($logBefore));

Qc::ok(
    '1.7d',
    'File log TIDAK memuat password yang salah',
    !str_contains($logDelta, $wrongSecret),
    'Log memuat password! Periksa Logger/Database::create().'
);
Qc::ok(
    '1.7e',
    'Kegagalan koneksi tetap tercatat di log untuk operator',
    str_contains($logDelta, 'Koneksi database gagal'),
    'Delta log: ' . ($logDelta === '' ? '(kosong)' : $logDelta)
);

// Reconnect dengan kredensial yang benar.
Database::getConnection();

// ---------------------------------------------------------------------------
Qc::section('Singleton dan tipe hasil');

// 1.8
Qc::ok(
    '1.8',
    'connection() bersifat singleton (instance identik)',
    Database::getConnection() === Database::getConnection()
        && Database::getConnection() === Database::getConnection()
);

// 1.9
$empty = Database::fetchOne("SELECT 'x' AS v FROM DUAL WHERE 1 = 0");
Qc::ok('1.9', 'fetchOne() mengembalikan null (bukan false) saat tidak ada baris', $empty === null);

// 1.10 — presisi DECIMAL
Database::getConnection()->exec(
    'CREATE TEMPORARY TABLE `_qc_decimal` ('
    . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
    . '`money` DECIMAL(18,2) NOT NULL, '
    . '`gram` DECIMAL(12,3) NOT NULL, '
    . 'PRIMARY KEY (`id`)'
    . ') ENGINE=InnoDB'
);

Database::insert('_qc_decimal', ['money' => '28937500.00', 'gram' => '14.850']);

$decimalRow = Database::fetchOne('SELECT `money`, `gram` FROM `_qc_decimal` LIMIT 1');

Qc::equals('1.10a', 'Nilai DECIMAL(18,2) kembali utuh sebagai string', '28937500.00', $decimalRow['money'] ?? null);
Qc::equals('1.10b', 'Nilai DECIMAL(12,3) kembali utuh sebagai string', '14.850', $decimalRow['gram'] ?? null);

Database::getConnection()->exec('DROP TEMPORARY TABLE `_qc_decimal`');

// ---------------------------------------------------------------------------
Qc::section('Transaksi (wajib untuk operasi kas — SAD § 4.1)');

Database::getConnection()->exec(
    'CREATE TEMPORARY TABLE `_qc_tx` ('
    . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
    . '`note` VARCHAR(32) NOT NULL, '
    . 'PRIMARY KEY (`id`)'
    . ') ENGINE=InnoDB'
);

// 1.11 — commit
Database::transaction(static function (PDO $pdo): void {
    $pdo->exec("INSERT INTO `_qc_tx` (`note`) VALUES ('commit')");
});

$afterCommit = (int) Database::fetchOne('SELECT COUNT(*) AS `total` FROM `_qc_tx`')['total'];

Qc::ok('1.11', 'transaction() men-commit perubahan saat callback sukses', $afterCommit === 1, 'jumlah baris=' . $afterCommit);

// 1.12 — rollback saat callback melempar exception
$propagated = false;

try {
    Database::transaction(static function (PDO $pdo): void {
        $pdo->exec("INSERT INTO `_qc_tx` (`note`) VALUES ('rollback')");

        throw new RuntimeException('kegagalan yang disengaja untuk menguji rollback');
    });
} catch (RuntimeException) {
    $propagated = true;
}

$afterRollback = (int) Database::fetchOne('SELECT COUNT(*) AS `total` FROM `_qc_tx`')['total'];

Qc::ok('1.12a', 'transaction() melempar ulang exception dari callback', $propagated);
Qc::ok(
    '1.12b',
    'transaction() me-rollback perubahan saat callback gagal',
    $afterRollback === 1,
    'jumlah baris=' . $afterRollback . ' (seharusnya tetap 1)'
);
Qc::ok(
    '1.12c',
    'Tidak ada transaksi yang tertinggal aktif',
    Database::getConnection()->inTransaction() === false
);

Database::getConnection()->exec('DROP TEMPORARY TABLE `_qc_tx`');

Qc::finish('qc_pdo');
