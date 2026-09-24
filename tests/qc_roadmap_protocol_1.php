<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Protokol QC Manual 1 — Integritas Basis Data & Helper
|--------------------------------------------------------------------------
| Implementasi otomatis dari tabel "Protokol QC Manual 1" pada dokumen
| docs/AurumFlow Roadmap and QC Documents.md (FASE 1).
|
| Tabel tersebut dirancang untuk dijalankan manual lewat peramban. Script ini
| menjalankan kelima barisnya secara otomatis sehingga checklist pada dokumen
| Roadmap dapat ditandai berdasarkan bukti, bukan ingatan.
|
| Setiap baris memakai parameter uji dan hasil yang diharapkan PERSIS seperti
| tertulis pada dokumen Roadmap.
*/

require __DIR__ . '/bootstrap.php';

use AurumFlow\Config\Database;
use AurumFlow\Helpers\Formatter;
use AurumFlow\Models\SystemSetting;
use AurumFlow\Models\User;

Qc::suite('Protokol QC Manual 1 — Integritas Basis Data & Helper (qc_roadmap_protocol_1.php)');

Qc::note('Sumber: docs/AurumFlow Roadmap and QC Documents.md — FASE 1');
Qc::note('Setiap baris di bawah memetakan 1:1 ke tabel protokol pada dokumen tersebut.');

// ---------------------------------------------------------------------------
Qc::section('Tabel Protokol QC Manual 1');

/**
 * Hasil tiap baris protokol dikumpulkan lebih dulu supaya dapat dicetak
 * sebagai tabel yang bentuknya sama dengan dokumen Roadmap.
 */
$protocol = [];

// --- Baris 1: Koneksi PDO ---------------------------------------------------
$pdoCheck = 'GAGAL';
$pdoDetail = '';

try {
    $connection = Database::getConnection();
    $version = (string) $connection->query('SELECT VERSION()')->fetchColumn();

    $pdoCheck = $connection instanceof PDO ? 'LULUS' : 'GAGAL';
    $pdoDetail = 'PDO aktif, server: ' . $version;
} catch (Throwable $exception) {
    $pdoDetail = $exception::class . ': ' . $exception->getMessage();
}

$protocol[] = [
    'Koneksi PDO',
    'Database::getConnection()',
    'Objek PDO aktif, tidak ada galat koneksi',
    $pdoCheck,
    $pdoDetail,
];

Qc::ok(
    'P1.1',
    'Koneksi PDO — Database::getConnection() mengembalikan objek PDO aktif',
    $pdoCheck === 'LULUS',
    $pdoDetail
);

// --- Baris 2: Pencegahan SQL Injection -------------------------------------
$injectionInput = "' OR '1'='1";
$injectionCheck = 'GAGAL';
$injectionDetail = '';

try {
    $result = User::findByPhoneNumber($injectionInput);

    if ($result === null) {
        $injectionCheck = 'LULUS';
        $injectionDetail = 'Input lolos sebagai string literal; 0 baris dikembalikan';
    } else {
        $injectionDetail = 'BAHAYA: kueri mengembalikan baris untuk payload injeksi';
    }
} catch (Throwable $exception) {
    $injectionDetail = $exception::class . ': ' . $exception->getMessage();
}

// Bukti tambahan: tabel masih utuh setelah payload destruktif dijalankan.
$destructiveInput = "'; DROP TABLE users;--";
$tableIntact = false;

try {
    User::findByPhoneNumber($destructiveInput);
    $tableIntact = QcFixture::tableExists('users');
} catch (Throwable $exception) {
    $injectionDetail .= ' | payload destruktif: ' . $exception::class;
}

if ($injectionCheck === 'LULUS' && $tableIntact) {
    $injectionDetail .= '; tabel users tetap utuh setelah payload DROP';
} elseif (!$tableIntact) {
    $injectionCheck = 'GAGAL';
    $injectionDetail .= '; tabel users TIDAK utuh';
}

$protocol[] = [
    'Pencegahan SQL Injection',
    $injectionInput,
    'Karakter lolos sebagai string literal, kueri aman',
    $injectionCheck,
    $injectionDetail,
];

Qc::ok(
    'P1.2',
    'Pencegahan SQL Injection — payload ' . $injectionInput . ' menjadi literal',
    $injectionCheck === 'LULUS',
    $injectionDetail
);

// --- Baris 3: Format Gramatur ----------------------------------------------
$gramInput = 14.85;
$gramExpected = '14,850 gr';
$gramActual = Formatter::gram($gramInput);
$gramCheck = $gramActual === $gramExpected ? 'LULUS' : 'GAGAL';

$protocol[] = [
    'Format Gramatur',
    (string) $gramInput,
    $gramExpected,
    $gramCheck,
    'Formatter::gram(' . $gramInput . ') => ' . $gramActual,
];

Qc::ok(
    'P1.3',
    'Format Gramatur — ' . $gramInput . ' menjadi "' . $gramExpected . '"',
    $gramCheck === 'LULUS',
    'hasil: ' . $gramActual
);

// --- Baris 4: Format Rupiah -------------------------------------------------
$rupiahInput = 28937500;
$rupiahExpected = 'Rp 28.937.500';
$rupiahActual = Formatter::rupiah($rupiahInput);
$rupiahCheck = $rupiahActual === $rupiahExpected ? 'LULUS' : 'GAGAL';

$protocol[] = [
    'Format Rupiah',
    (string) $rupiahInput,
    $rupiahExpected,
    $rupiahCheck,
    'Formatter::rupiah(' . $rupiahInput . ') => ' . $rupiahActual,
];

Qc::ok(
    'P1.4',
    'Format Rupiah — ' . $rupiahInput . ' menjadi "' . $rupiahExpected . '"',
    $rupiahCheck === 'LULUS',
    'hasil: ' . $rupiahActual
);

// --- Baris 5: Seed Konfigurasi ---------------------------------------------
QcFixture::ensureInstalled();

$quota = SystemSetting::get('operator_base_quota_pct');
$quotaCheck = $quota === '50.00' ? 'LULUS' : 'GAGAL';

$protocol[] = [
    'Seed Konfigurasi',
    'Pembacaan tabel system_settings',
    'Kunci operator_base_quota_pct terbaca "50.00"',
    $quotaCheck,
    'SystemSetting::get("operator_base_quota_pct") => ' . var_export($quota, true),
];

Qc::ok(
    'P1.5',
    'Seed Konfigurasi — system_settings.operator_base_quota_pct = 50.00',
    $quotaCheck === 'LULUS',
    'hasil: ' . var_export($quota, true)
);

// ---------------------------------------------------------------------------
Qc::section('Rekap tabel protokol (dapat disalin ke dokumen Roadmap)');

$widths = [26, 30, 42, 8];

echo PHP_EOL;
printf(
    "  | %-{$widths[0]}s | %-{$widths[1]}s | %-{$widths[2]}s | %-{$widths[3]}s |" . PHP_EOL,
    'Parameter Uji',
    'Data Masukan',
    'Hasil yang Diharapkan',
    'Status'
);
echo '  |' . str_repeat('-', $widths[0] + 2)
    . '|' . str_repeat('-', $widths[1] + 2)
    . '|' . str_repeat('-', $widths[2] + 2)
    . '|' . str_repeat('-', $widths[3] + 2) . '|' . PHP_EOL;

foreach ($protocol as [$parameter, $input, $expected, $status, $detail]) {
    printf(
        "  | %-{$widths[0]}s | %-{$widths[1]}s | %-{$widths[2]}s | %-{$widths[3]}s |" . PHP_EOL,
        $parameter,
        $input,
        $expected,
        '[' . ($status === 'LULUS' ? '✓' : ' ') . ']'
    );
    echo '      -> ' . $detail . PHP_EOL;
}

echo PHP_EOL;

$failed = array_filter($protocol, static fn (array $row): bool => $row[3] !== 'LULUS');

Qc::ok(
    'P1.6',
    'Seluruh ' . count($protocol) . ' baris Protokol QC Manual 1 berstatus LULUS',
    $failed === [],
    'gagal: ' . implode(', ', array_map(static fn (array $row): string => $row[0], $failed))
);

Qc::finish('qc_roadmap_protocol_1');
