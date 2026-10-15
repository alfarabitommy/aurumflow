<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| QC-4 — Seed Reading & Integritas Skema ERD
|--------------------------------------------------------------------------
| Membuktikan bahwa DDL ERD diterapkan apa adanya: 10 tabel, konvensi tipe
| data (DECIMAL(14,2) / DECIMAL(8,3) / DECIMAL(5,2)), primary key UUID
| CHAR(36), seed system_settings lengkap, dan guard database/install.php.
*/

require __DIR__ . '/bootstrap.php';

use AurumFlow\Config\Constants;
use AurumFlow\Config\Database;
use AurumFlow\Config\Env;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\SystemSetting;
use AurumFlow\Models\User;

Qc::suite('4 — Seed Reading & Integritas Skema ERD (qc_seed.php)');

$lockFile = STORAGE_PATH . '/installed.lock';

// Bersihkan sisa marker agar hasil QC tidak bergantung eksekusi sebelumnya.
if (is_file($lockFile)) {
    unlink($lockFile);
}

/** Daftar tabel sesuai docs/AurumFlow Entity Relationship Diagram.md § 4. */
$erdTables = [
    'system_settings',
    'users',
    'capital_wallets',
    'cash_registers',
    'cash_mutations',
    'daily_settlements',
    'hulu_transactions',
    'profit_distributions',
    'withdrawal_requests',
    'bridge_loans',
];

// ---------------------------------------------------------------------------
Qc::section('Idempotensi schema.sql dan seed.sql');

$schemaFirst = 0;
$schemaSecond = 0;

try {
    $schemaFirst = Database::executeSqlFile(DATABASE_PATH . '/schema.sql');
    Qc::ok('4.1', 'schema.sql (DDL ERD) berjalan tanpa error (' . $schemaFirst . ' statement)', $schemaFirst >= 9);
} catch (Throwable $exception) {
    Qc::ok('4.1', 'schema.sql (DDL ERD) berjalan tanpa error', false, $exception->getMessage());
}

try {
    $schemaSecond = Database::executeSqlFile(DATABASE_PATH . '/schema.sql');
    Qc::ok(
        '4.2',
        'schema.sql aman dijalankan dua kali (' . $schemaSecond . ' statement)',
        $schemaSecond === $schemaFirst,
        'pertama=' . $schemaFirst . ' kedua=' . $schemaSecond
    );
} catch (Throwable $exception) {
    Qc::ok('4.2', 'schema.sql aman dijalankan dua kali', false, $exception->getMessage());
}

$settingsBefore = (int) Database::fetchOne('SELECT COUNT(*) AS `total` FROM `system_settings`')['total'];

try {
    $seedFirst = Database::executeSqlFile(DATABASE_PATH . '/seed.sql');
    Qc::ok('4.3', 'seed.sql berjalan tanpa error (' . $seedFirst . ' statement)', $seedFirst >= 8);
} catch (Throwable $exception) {
    Qc::ok('4.3', 'seed.sql berjalan tanpa error', false, $exception->getMessage());
}

$settingsAfterFirst = (int) Database::fetchOne('SELECT COUNT(*) AS `total` FROM `system_settings`')['total'];
$registersAfterFirst = (int) Database::fetchOne('SELECT COUNT(*) AS `total` FROM `cash_registers`')['total'];

try {
    Database::executeSqlFile(DATABASE_PATH . '/seed.sql');
} catch (Throwable $exception) {
    Qc::ok('4.4', 'seed.sql aman dijalankan dua kali tanpa duplikasi', false, $exception->getMessage());
}

$settingsAfterSecond = (int) Database::fetchOne('SELECT COUNT(*) AS `total` FROM `system_settings`')['total'];
$registersAfterSecond = (int) Database::fetchOne('SELECT COUNT(*) AS `total` FROM `cash_registers`')['total'];

Qc::ok(
    '4.4',
    'seed.sql aman dijalankan dua kali tanpa duplikasi baris',
    $settingsAfterFirst === $settingsAfterSecond
        && $registersAfterFirst === $registersAfterSecond
        && $settingsAfterSecond >= $settingsBefore,
    'settings sebelum=' . $settingsBefore
    . ' setelah-1x=' . $settingsAfterFirst
    . ' setelah-2x=' . $settingsAfterSecond
    . ' | registers 1x=' . $registersAfterFirst . ' 2x=' . $registersAfterSecond
);

// ---------------------------------------------------------------------------
Qc::section('Struktur tabel sesuai ERD');

$missingTables = [];

foreach ($erdTables as $table) {
    if (!QcFixture::tableExists($table)) {
        $missingTables[] = $table;
    }
}

Qc::ok(
    '4.5',
    'Seluruh ' . count($erdTables) . ' tabel ERD tersedia',
    $missingTables === [],
    'hilang: ' . implode(', ', $missingTables)
);

$placeholders = [];
$params = [];

foreach ($erdTables as $index => $table) {
    $placeholders[] = ':t' . $index;
    $params['t' . $index] = $table;
}

$inClause = implode(', ', $placeholders);

$engineRows = Database::fetchAll(
    'SELECT `TABLE_NAME`, `ENGINE`, `TABLE_COLLATION` FROM information_schema.tables '
    . 'WHERE table_schema = DATABASE() AND table_name IN (' . $inClause . ') '
    . 'ORDER BY table_name',
    $params
);

$engineProblems = [];

foreach ($engineRows as $row) {
    if (strtoupper((string) $row['ENGINE']) !== 'INNODB') {
        $engineProblems[] = $row['TABLE_NAME'] . ' engine=' . $row['ENGINE'];
    }

    if (strtolower((string) $row['TABLE_COLLATION']) !== 'utf8mb4_unicode_ci') {
        $engineProblems[] = $row['TABLE_NAME'] . ' collation=' . $row['TABLE_COLLATION'];
    }
}

Qc::ok(
    '4.6',
    'Seluruh tabel memakai InnoDB + utf8mb4_unicode_ci',
    count($engineRows) === count($erdTables) && $engineProblems === [],
    'terbaca=' . count($engineRows) . '/' . count($erdTables) . "\n" . implode("\n", $engineProblems)
);

// ---------------------------------------------------------------------------
Qc::section('Konvensi tipe data (ERD § 3.1)');

$floatColumns = Database::fetchAll(
    'SELECT `TABLE_NAME`, `COLUMN_NAME`, `DATA_TYPE` FROM information_schema.columns '
    . 'WHERE table_schema = DATABASE() AND `DATA_TYPE` IN (:f, :d)',
    ['f' => 'float', 'd' => 'double']
);

Qc::ok(
    '4.7',
    'Tidak ada kolom FLOAT/DOUBLE di seluruh skema',
    $floatColumns === [],
    implode("\n", array_map(
        static fn (array $row): string => $row['TABLE_NAME'] . '.' . $row['COLUMN_NAME'] . ' ' . $row['DATA_TYPE'],
        $floatColumns
    ))
);

// Uang & finansial -> DECIMAL(14,2)
$moneyExpectations = [
    ['capital_wallets', 'balance_capital', 14, 2],
    ['capital_wallets', 'balance_profit', 14, 2],
    ['cash_registers', 'current_balance', 14, 2],
    ['cash_mutations', 'amount', 14, 2],
    ['daily_settlements', 'shop_price_per_g', 14, 2],
    ['daily_settlements', 'total_shop_revenue', 14, 2],
    ['daily_settlements', 'net_settled_profit', 14, 2],
    ['daily_settlements', 'operator_share_80', 14, 2],
    ['daily_settlements', 'investor_pool_20', 14, 2],
    ['hulu_transactions', 'buy_price_per_g', 14, 2],
    ['hulu_transactions', 'gross_tebus_total', 14, 2],
    ['hulu_transactions', 'net_cash_paid', 14, 2],
    ['hulu_transactions', 'flat_burn_fee', 14, 2],
    ['profit_distributions', 'profit_earned', 14, 2],
    ['withdrawal_requests', 'amount', 14, 2],
    ['bridge_loans', 'principal_amount', 14, 2],
    ['bridge_loans', 'fee_amount', 14, 2],
    ['bridge_loans', 'fee_absorbed', 14, 2],
    ['bridge_loans', 'fee_uncovered', 14, 2],
];

$moneyViolations = QcFixture::checkDecimalConventions($moneyExpectations);

Qc::ok(
    '4.7b',
    'Kolom finansial memakai DECIMAL(14,2) (' . count($moneyExpectations) . ' kolom diperiksa)',
    $moneyViolations === [],
    implode("\n", $moneyViolations)
);

// Timbangan emas -> DECIMAL(8,3)
$gramExpectations = [
    ['daily_settlements', 'total_dry_grams', 8, 3],
    ['hulu_transactions', 'dry_weight', 8, 3],
];

$gramViolations = QcFixture::checkDecimalConventions($gramExpectations);

Qc::ok(
    '4.7c',
    'Kolom timbangan emas memakai DECIMAL(8,3)',
    $gramViolations === [],
    implode("\n", $gramViolations)
);

// Persentase -> DECIMAL(5,2)
$percentExpectations = [
    ['capital_wallets', 'locked_quota_pct', 5, 2],
    ['daily_settlements', 'shop_water_density_pct', 5, 2],
    ['bridge_loans', 'fee_rate_pct', 5, 2],
];

$percentViolations = QcFixture::checkDecimalConventions($percentExpectations);

Qc::ok(
    '4.7d',
    'Kolom persentase memakai DECIMAL(5,2)',
    $percentViolations === [],
    implode("\n", $percentViolations)
);

// Primary key UUID -> CHAR(36)
$pkRows = Database::fetchAll(
    'SELECT `TABLE_NAME`, `COLUMN_NAME`, `DATA_TYPE`, `CHARACTER_MAXIMUM_LENGTH` '
    . 'FROM information_schema.columns '
    . 'WHERE table_schema = DATABASE() AND `COLUMN_KEY` = :pk '
    . 'AND table_name IN (' . $inClause . ')',
    array_merge(['pk' => 'PRI'], $params)
);

$pkProblems = [];

foreach ($pkRows as $row) {
    $type = strtolower((string) $row['DATA_TYPE']);
    $length = (int) $row['CHARACTER_MAXIMUM_LENGTH'];
    $table = (string) $row['TABLE_NAME'];

    if ($table === 'system_settings') {
        // system_settings memakai natural key (setting_key VARCHAR(50)) sesuai ERD.
        if ($type !== 'varchar' || $length !== 50) {
            $pkProblems[] = 'system_settings.setting_key harus VARCHAR(50) tetapi '
                . strtoupper($type) . '(' . $length . ')';
        }

        continue;
    }

    if ($type !== 'char' || $length !== 36) {
        $pkProblems[] = $table . '.' . $row['COLUMN_NAME'] . ' harus CHAR(36) tetapi '
            . strtoupper($type) . '(' . $length . ')';
    }
}

Qc::ok(
    '4.7e',
    'Primary key memakai UUID CHAR(36) (kecuali natural key system_settings)',
    count($pkRows) === count($erdTables) && $pkProblems === [],
    'diperiksa=' . count($pkRows) . '/' . count($erdTables) . "\n" . implode("\n", $pkProblems)
);

// ---------------------------------------------------------------------------
Qc::section('Akun operator awal');

$credentials = QcFixture::ensureInstalled();

$operator = User::findByPhoneNumber($credentials['phone'], false);

Qc::ok(
    '4.8',
    'Akun operator awal ada dengan role operator dan aktif',
    $operator !== null
        && (string) $operator['role'] === Constants::ROLE_OPERATOR
        && (int) $operator['is_active'] === 1,
    $operator === null ? 'akun tidak ditemukan' : print_r($operator, true)
);

Qc::ok(
    '4.8b',
    'Primary key pengguna berformat UUID v4',
    $operator !== null && Security::isUuid((string) $operator['id']),
    $operator === null ? 'tidak ada' : (string) $operator['id']
);

Qc::ok(
    '4.9',
    'password_verify() berhasil terhadap password operator dari environment',
    $operator !== null && Security::verifyPassword($credentials['password'], (string) $operator['password_hash'])
);

Qc::ok(
    '4.9b',
    'password_verify() menolak password yang salah untuk akun operator',
    $operator !== null && !Security::verifyPassword('password-yang-salah', (string) $operator['password_hash'])
);

Qc::ok(
    '4.9c',
    'password_hash akun operator tidak tersimpan sebagai teks biasa',
    $operator !== null
        && !str_contains((string) $operator['password_hash'], $credentials['password'])
        && str_starts_with((string) $operator['password_hash'], '$')
);

// ---------------------------------------------------------------------------
Qc::section('Pembacaan seed melalui lapisan aplikasi');

$settings = SystemSetting::all();
$rawCount = (int) Database::fetchOne('SELECT COUNT(*) AS `total` FROM `system_settings`')['total'];

Qc::ok('4.10a', 'SystemSetting::all() membaca tabel system_settings', $settings !== []);
Qc::equals('4.10b', 'Jumlah setting sesuai seed ERD', $rawCount, count($settings));

// Nilai seed ERD — diuji persis, termasuk yang dipakai Protokol QC Manual 1.
$expectedSettings = [
    'allocation_mode'               => 'waterfall',
    'operator_base_quota_pct'       => '50.00',
    'default_burn_fee'              => '20000.00',
    'default_burn_fee_operator_cut' => '10000.00',
    'notice_period_days'            => '7',
    'active_carryover_deficit'      => '0.00',
];

$settingProblems = [];

foreach ($expectedSettings as $key => $expected) {
    $actual = $settings[$key] ?? null;

    if ($actual !== $expected) {
        $settingProblems[] = $key . ' harus "' . $expected . '" tetapi ' . var_export($actual, true);
    }
}

Qc::ok(
    '4.10c',
    'Seluruh 6 nilai seed system_settings sesuai ERD',
    $settingProblems === [] && count($settings) === count($expectedSettings),
    'jumlah=' . count($settings) . ' diharapkan=' . count($expectedSettings) . "\n" . implode("\n", $settingProblems)
);

Qc::equals(
    '4.10d',
    'SystemSetting::get("operator_base_quota_pct") = 50.00',
    '50.00',
    SystemSetting::get('operator_base_quota_pct')
);

Qc::equals(
    '4.10e',
    'SystemSetting::get() mengembalikan default untuk kunci yang tidak ada',
    'nilai-default',
    SystemSetting::get('kunci-yang-tidak-ada', 'nilai-default')
);

$registers = Database::fetchAll('SELECT `register_name` FROM `cash_registers` ORDER BY `register_name`');
$registerNames = array_map(static fn (array $row): string => (string) $row['register_name'], $registers);

Qc::equals('4.10f', 'Seed cash_registers berisi 2 pos kas ERD', ['Brankas Utama', 'Kas Meja Bakar'], $registerNames);

$registerIdRows = Database::fetchAll('SELECT `id` FROM `cash_registers`');
$registerIdProblems = [];

foreach ($registerIdRows as $row) {
    if (!Security::isUuid((string) $row['id'])) {
        $registerIdProblems[] = (string) $row['id'];
    }
}

Qc::ok(
    '4.10g',
    'UUID pos kas dihasilkan fungsi UUID() dan berformat CHAR(36)',
    $registerIdProblems === [],
    implode("\n", $registerIdProblems)
);

// ---------------------------------------------------------------------------
Qc::section('Constraint database');

Qc::throws(
    '4.11',
    'Nomor telepon duplikat ditolak oleh constraint UNIQUE',
    static function () use ($credentials): void {
        User::create('Duplikat QC', $credentials['phone'], 'Duplikat2026!', Constants::ROLE_INVESTOR);
    },
    PDOException::class
);

Qc::throws(
    '4.12',
    'cash_mutations menolak register_id yang tidak ada (FK RESTRICT)',
    static function (): void {
        Database::insert('cash_mutations', [
            'id'                 => Security::uuidV4(),
            'register_id'        => Security::uuidV4(),
            'mutation_type'      => 'in',
            'amount'             => '1000.00',
            'source_destination' => 'QC FK',
        ]);
    },
    PDOException::class
);

Qc::throws(
    '4.12b',
    'capital_wallets menolak user_id yang tidak ada (FK RESTRICT)',
    static function (): void {
        Database::insert('capital_wallets', [
            'id'      => Security::uuidV4(),
            'user_id' => Security::uuidV4(),
            'tier'    => 'external',
        ]);
    },
    PDOException::class
);

Qc::throws(
    '4.12c',
    'Nilai ENUM di luar definisi ditolak (users.role)',
    static function (): void {
        Database::insert('users', [
            'id'            => Security::uuidV4(),
            'full_name'     => 'Role Salah QC',
            'phone_number'  => '089999999999',
            'password_hash' => Security::hashPassword('RoleSalah2026!'),
            'role'          => 'superadmin',
        ]);
    },
    PDOException::class
);

// Sejak Fase 7 peran `cashier` resmi ada — aditif, tanpa menggeser dua peran
// lama (`qc_fase_7` membuktikan urutan ENUM & migrasi idempotennya).
Qc::ok(
    '4.12c.2',
    'Peran kasir (Fase 7) diterima ENUM dan ditolak bila peran lama tergeser',
    (static function (): bool {
        $values = User::roleEnumValues();

        return $values === ['operator', 'investor', 'cashier']
            && in_array(Constants::ROLE_CASHIER, $values, true);
    })(),
    'ENUM users.role = ' . implode(', ', User::roleEnumValues())
);

Qc::throws(
    '4.12d',
    'hulu_transactions menolak status di luar holding/settled',
    static function (): void {
        Database::insert('hulu_transactions', [
            'id'                => Security::uuidV4(),
            'farmer_alias'      => 'QC',
            'dry_weight'        => '1.000',
            'buy_price_per_g'   => '1000000.00',
            'gross_tebus_total' => '1000000.00',
            'net_cash_paid'     => '980000.00',
            'status'            => 'draft',
        ]);
    },
    PDOException::class
);

// ---------------------------------------------------------------------------
Qc::section('Presisi nilai pada skema ERD');

$moneyProbe = Database::fetchOne('SELECT CAST(:value AS DECIMAL(14,2)) AS `money`', ['value' => '999999999999.99']);

Qc::equals(
    '4.13a',
    'DECIMAL(14,2) menampung nilai maksimum tanpa kehilangan presisi',
    '999999999999.99',
    (string) $moneyProbe['money']
);

$gramProbe = Database::fetchOne('SELECT CAST(:value AS DECIMAL(8,3)) AS `gram`', ['value' => '99999.999']);

Qc::equals(
    '4.13b',
    'DECIMAL(8,3) menyimpan timbangan hingga 0,001 gram',
    '99999.999',
    (string) $gramProbe['gram']
);

$pctProbe = Database::fetchOne('SELECT CAST(:value AS DECIMAL(5,2)) AS `pct`', ['value' => '50.00']);

Qc::equals('4.13c', 'DECIMAL(5,2) menyimpan persentase kuota (50.00)', '50.00', (string) $pctProbe['pct']);

// ---------------------------------------------------------------------------
Qc::section('Konsistensi timezone database');

$offset = Database::fetchOne('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS `delta`');

Qc::equals(
    '4.13d',
    'Jam database berada pada offset timezone aplikasi (Asia/Jakarta = +07:00)',
    7 * 3600,
    (int) ($offset['delta'] ?? 0)
);

$probeName = 'QC Probe ' . bin2hex(random_bytes(4));

Database::insert('cash_registers', [
    'id'              => Security::uuidV4(),
    'register_name'   => $probeName,
    'current_balance' => 0.00,
]);

$probeRow = Database::fetchOne(
    'SELECT `id`, `updated_at` FROM `cash_registers` WHERE `register_name` = :name',
    ['name' => $probeName]
);

Qc::ok(
    '4.13e',
    'updated_at terisi otomatis oleh database',
    $probeRow !== null && is_string($probeRow['updated_at']) && $probeRow['updated_at'] !== '',
    $probeRow === null ? 'baris tidak ditemukan' : print_r($probeRow, true)
);

Database::query('DELETE FROM `cash_registers` WHERE `register_name` = :name', ['name' => $probeName]);

// ---------------------------------------------------------------------------
Qc::section('Guard pada database/install.php');

// 4.14 — INSTALL_TOKEN kosong harus menolak, termasuk pada CLI.
$noToken = qc_run_php(DATABASE_PATH . '/install.php', ['INSTALL_TOKEN' => '']);

Qc::ok(
    '4.14',
    'install.php menolak berjalan ketika INSTALL_TOKEN kosong',
    $noToken['code'] !== 0 && str_contains($noToken['output'], 'INSTALL DITOLAK'),
    'exit=' . $noToken['code'] . "\n" . trim($noToken['output'])
);

// 4.15 — marker instalasi harus menolak eksekusi ulang meski token benar.
$token = bin2hex(random_bytes(16));

file_put_contents($lockFile, '{"qc":"marker"}');

$withLock = qc_run_php(DATABASE_PATH . '/install.php', ['INSTALL_TOKEN' => $token]);

unlink($lockFile);

Qc::ok(
    '4.15',
    'install.php menolak berjalan ketika installed.lock sudah ada',
    $withLock['code'] !== 0 && str_contains($withLock['output'], 'sudah terpasang'),
    'exit=' . $withLock['code'] . "\n" . trim($withLock['output'])
);

// 4.15b — guard token lewat HTTP pada skenario document root salah konfigurasi
// (mitigasi R-1/R-9). Server sementara dijalankan dengan docroot = root proyek
// sehingga database/install.php benar-benar dapat dijangkau lewat HTTP.
//
// INSTALL_TOKEN diberikan sebagai environment variable proses (bukan ditulis ke
// .env) — ini juga cara yang benar untuk produksi: file .env tetap kosong dan
// token hanya hidup selama instalasi.
putenv('INSTALL_TOKEN=' . $token);

$server = qc_temp_server(BASE_PATH);

$wrongToken = QcHttp::fetch($server['url'] . '/database/install.php?token=token-palsu');

Qc::ok(
    '4.15b',
    'install.php lewat HTTP menolak token yang salah (403)',
    $wrongToken['status'] === 403 && !str_contains($wrongToken['body'], 'INSTALL SELESAI'),
    'status=' . $wrongToken['status'] . ' body=' . substr($wrongToken['body'], 0, 160)
);

$noParam = QcHttp::fetch($server['url'] . '/database/install.php');

Qc::ok(
    '4.15c',
    'install.php lewat HTTP menolak permintaan tanpa parameter token',
    in_array($noParam['status'], [403, 500], true)
        && !str_contains($noParam['body'], 'INSTALL SELESAI'),
    'status=' . $noParam['status'] . ' body=' . substr($noParam['body'], 0, 160)
);

$goodToken = QcHttp::fetch($server['url'] . '/database/install.php?token=' . urlencode($token));

Qc::ok(
    '4.15d',
    'install.php lewat HTTP berhasil dengan token yang benar',
    $goodToken['status'] === 200 && str_contains($goodToken['body'], 'INSTALL SELESAI'),
    'status=' . $goodToken['status'] . ' body=' . substr($goodToken['body'], 0, 200)
);

$httpLock = is_file($lockFile);

if ($httpLock) {
    unlink($lockFile);
}

Qc::ok('4.15e', 'Instalasi lewat HTTP menulis marker instalasi', $httpLock);

$server['stop']();

putenv('INSTALL_TOKEN');

Qc::note('Server sementara dihentikan. Test 4.15b-4.15e membuktikan guard token '
    . 'bekerja BAHKAN ketika .htaccess tidak melindungi direktori database/.');

// 4.15f — kredensial operator yang lemah ditolak.
$weakOperator = qc_run_php(DATABASE_PATH . '/install.php', [
    'INSTALL_TOKEN'     => $token,
    'OPERATOR_PHONE'    => '081200000999',
    'OPERATOR_PASSWORD' => 'lemah',
]);

Qc::ok(
    '4.15f',
    'install.php menolak OPERATOR_PASSWORD yang lemah',
    $weakOperator['code'] !== 0 && str_contains($weakOperator['output'], 'terlalu lemah'),
    'exit=' . $weakOperator['code'] . "\n" . trim($weakOperator['output'])
);

// 4.15g — nomor telepon operator yang tidak valid ditolak.
$badPhone = qc_run_php(DATABASE_PATH . '/install.php', [
    'INSTALL_TOKEN'  => $token,
    'OPERATOR_PHONE' => 'bukan-nomor',
]);

Qc::ok(
    '4.15g',
    'install.php menolak OPERATOR_PHONE yang tidak valid',
    $badPhone['code'] !== 0 && str_contains($badPhone['output'], 'tidak valid'),
    'exit=' . $badPhone['code'] . "\n" . trim($badPhone['output'])
);

Qc::ok(
    '4.15h',
    'Penolakan di atas tidak menulis marker instalasi',
    !is_file($lockFile),
    'marker ditemukan padahal instalasi harus ditolak'
);

// 4.16 — jalur sukses end-to-end lewat CLI.
$success = qc_run_php(DATABASE_PATH . '/install.php', ['INSTALL_TOKEN' => $token]);

$lockWritten = is_file($lockFile);

if ($lockWritten) {
    unlink($lockFile);
}

Qc::ok(
    '4.16',
    'install.php berhasil dengan token benar (skema ERD + seed + akun operator)',
    $success['code'] === 0 && str_contains($success['output'], 'INSTALL SELESAI'),
    'exit=' . $success['code'] . "\n" . trim($success['output'])
);

Qc::ok('4.16b', 'install.php menulis marker storage/installed.lock', $lockWritten);

Qc::ok(
    '4.16c',
    'install.php melaporkan 2 pos kas hasil seed',
    str_contains($success['output'], 'Pos kas    : 2 baris'),
    trim($success['output'])
);

$envAfterInstall = Env::get('DB_NAME');
Qc::ok(
    '4.16d',
    'Konfigurasi database tetap terbaca setelah instalasi',
    $envAfterInstall !== null && $envAfterInstall !== ''
);

// Pastikan tidak ada sisa data QC.
//
// Urutannya penting sejak Fase 5: `capital_wallets.user_id`,
// `withdrawal_requests.wallet_id`, dan `profit_distributions.wallet_id` memakai
// FK ON DELETE RESTRICT (ERD § 4), sehingga baris anak harus dibuang lebih dulu
// — pola yang sama dengan `QcFixture::purgeQcInvestorAccounts()`.
$qcPattern = ['pattern' => '%QC%'];

Database::query(
    'DELETE FROM `profit_distributions` WHERE `wallet_id` IN ('
    . 'SELECT `id` FROM `capital_wallets` WHERE `user_id` IN ('
    . 'SELECT `id` FROM `users` WHERE `full_name` LIKE :pattern))',
    $qcPattern
);

Database::query(
    'DELETE FROM `withdrawal_requests` WHERE `wallet_id` IN ('
    . 'SELECT `id` FROM `capital_wallets` WHERE `user_id` IN ('
    . 'SELECT `id` FROM `users` WHERE `full_name` LIKE :pattern))',
    $qcPattern
);

Database::query(
    'DELETE FROM `capital_wallets` WHERE `user_id` IN (SELECT `id` FROM `users` WHERE `full_name` LIKE :pattern)',
    $qcPattern
);

Database::query('DELETE FROM `users` WHERE `full_name` LIKE :pattern', $qcPattern);
Database::query('DELETE FROM `cash_registers` WHERE `register_name` LIKE :pattern', ['pattern' => 'QC Probe%']);

Qc::finish('qc_seed');
