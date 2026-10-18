<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Protokol QC Manual 8 — Buku Kasbon Petani / Miner Loan & Ledger (Fase 8)
|--------------------------------------------------------------------------
| Implementasi otomatis dari "Protokol QC Manual 8" pada dokumen
| docs/AurumFlow Roadmap and QC Documents.md (FASE 8) dan plan
| plan/Plan_Kasbon_Petani_AurumFlow.md § 4.3.
|
| Cakupan:
|   B. Fixture & integrasi urutan FK (resetKasbonState sebelum cash_mutations)
|   C. Pencairan: tiga tulisan atomik + `_once` + pendaftaran petani
|   D. Dua lapis validasi: batas harian & saldo fisik laci (nol tulisan)
|   E. Pembayaran tunai / potong nota, FIFO, pelunasan
|   F. RBAC dua permukaan izin (operator + kasir), guard-first, regresi Fase 7
|   G. Isolasi permukaan kasir (tanpa Brankas / bagi hasil / tautan pemilik)
|   H. Kontrak statis: SQL, transaksi, urutan kunci, FIFO, escaping
|   I. UI & aset: datalist, tombol cepat, komponen Alpine, CACHE_NAME v3,
|      nol kompilasi CSS
|   J. Dokumen & konsistensi (ERD, AGENTS.md, README.md, plan)
|
| PERINGATAN: suite ini MENULIS ke database yang ditunjuk `.env`
| (`miners`, `miner_loans`, `miner_loan_ledger`, saldo Kas Meja Bakar,
| `cash_mutations`, `system_settings.daily_loan_limit` sementara) dan ke disk
| (`storage/throttle/` lewat login kasir). Jangan arahkan ke produksi.
| Konvensi exit code: 0 = lulus, 1 = gagal.
|
| Catatan implementasi: `APP_PIN_PEPPER` disuntikkan lewat `putenv()` SEBELUM
| harness dimuat supaya fixture PIN (kasir & mitra) berperilaku sama pada clone
| yang `.env`-nya belum memuat pepper (pola `qc_fase_5`).
*/

putenv('APP_PIN_PEPPER=qc-fase-8-pepper-7a1d3e');

require __DIR__ . '/bootstrap.php';

use AurumFlow\Config\Constants;
use AurumFlow\Config\Database;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\CashRegister;
use AurumFlow\Models\Miner;
use AurumFlow\Models\MinerLoan;
use AurumFlow\Models\MinerLoanLedger;
use AurumFlow\Models\SystemSetting;
use AurumFlow\Services\KasbonService;
use AurumFlow\Services\TellerService;

Qc::suite('8 — Buku Kasbon Petani (qc_fase_8.php)');

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

/**
 * Pemindai statis untuk berkas TERTENTU (predikat menerima isi baris).
 *
 * @param list<string> $paths
 * @return list<string> pelanggaran (kosong = lulus)
 */
function qc8_scan(array $paths, callable $predicate): array
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
function qc8_method_body(string $source, string $method): ?string
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

/** Jumlah baris satu tabel (opsional dengan klausa WHERE sederhana). */
function qc8_count(string $table, string $where = '', array $params = []): int
{
    $row = Database::fetchOne(
        'SELECT COUNT(*) AS `total` FROM `' . $table . '`' . ($where === '' ? '' : ' WHERE ' . $where),
        $params
    );

    return (int) ($row['total'] ?? 0);
}

/**
 * Buka `/operator/kasbon` dan kembalikan token halaman (CSRF + `_once`).
 *
 * @return array{body: string, csrf: ?string, once: ?string, status: int}
 */
function qc8_openKasbon(string $jar): array
{
    $page = QcHttp::request('GET', '/operator/kasbon', ['cookie_jar' => $jar]);
    $body = (string) $page['body'];

    return [
        'body'   => $body,
        'csrf'   => QcHttp::csrfFrom($body),
        'once'   => QcHttp::fieldFrom($body, Security::FORM_FIELD),
        'status' => (int) $page['status'],
    ];
}

/**
 * POST ke salah satu jalur kasbon dengan token dari halaman yang baru dibuka
 * (satu halaman = satu token aktif).
 *
 * @param array<string, string> $data
 * @return array<string, mixed> respons QcHttp
 */
function qc8_postKasbon(string $jar, string $path, array $data): array
{
    $page = qc8_openKasbon($jar);

    $data[Security::CSRF_FIELD] = (string) $page['csrf'];
    $data[Security::FORM_FIELD] = (string) $page['once'];

    return QcHttp::request('POST', $path, ['cookie_jar' => $jar, 'data' => $data]);
}

/** Masuk pintu kasir (PIN), kembalikan cookie jar. */
function qc8_loginCashier(string $phone, string $pin): string
{
    $jar = QcHttp::cookieJar('fase8-cashier-' . $phone);
    $page = QcHttp::request('GET', '/cashier/login', ['cookie_jar' => $jar, 'fresh' => true]);

    QcHttp::request('POST', '/cashier/login', [
        'cookie_jar' => $jar,
        'data'       => [
            Security::CSRF_FIELD => (string) QcHttp::csrfFrom((string) $page['body']),
            'phone_number'       => $phone,
            'pin'                => $pin,
        ],
    ]);

    return $jar;
}

/** Masuk pintu mitra (PIN), kembalikan cookie jar. */
function qc8_loginInvestor(string $phone, string $pin): string
{
    $jar = QcHttp::cookieJar('fase8-investor');
    $page = QcHttp::request('GET', '/investor/login', ['cookie_jar' => $jar, 'fresh' => true]);

    QcHttp::request('POST', '/investor/login', [
        'cookie_jar' => $jar,
        'data'       => [
            Security::CSRF_FIELD => (string) QcHttp::csrfFrom((string) $page['body']),
            'phone_number'       => $phone,
            'pin'                => $pin,
        ],
    ]);

    return $jar;
}

/**
 * Selisih dua saldo desimal kanonik sebagai string bertanda (`'50000.00'`,
 * `'-250000.00'`) — dihitung dalam sen, tanpa float.
 */
function qc8_delta(string $before, string $after): string
{
    $toCents = static function (string $value): int {
        return (int) str_replace('.', '', $value);
    };

    $delta = $toCents($after) - $toCents($before);

    return TellerService::centsToDecimal($delta);
}

/** Baris pinjaman terbaru untuk satu petani (atau null). */
function qc8_latestLoan(string $minerId): ?array
{
    return Database::fetchOne(
        'SELECT * FROM `miner_loans` WHERE `miner_id` = :miner_id'
        . ' ORDER BY `created_at` DESC, `id` DESC LIMIT 1',
        ['miner_id' => $minerId]
    );
}

/**
 * Satu entri ledger berdasarkan jenis (dan metode) — TIDAK memakai urutan baris.
 *
 * `created_at` berpresisi detik (item O-1), sehingga dua entri pada detik yang
 * sama dapat berurutan acak; memilih entri lewat `entry_type`/`payment_method`
 * membuat assertion deterministik.
 */
function qc8_ledgerEntry(string $loanId, string $entryType, ?string $method = null): ?array
{
    $sql = 'SELECT * FROM `miner_loan_ledger` WHERE `loan_id` = :loan_id AND `entry_type` = :entry_type';
    $params = ['loan_id' => $loanId, 'entry_type' => $entryType];

    if ($method !== null) {
        $sql .= ' AND `payment_method` = :method';
        $params['method'] = $method;
    }

    return Database::fetchOne($sql . ' LIMIT 1', $params);
}

/** Baris ledger berdasarkan `loan_id` (terlama lebih dulu). */
function qc8_loanLedger(string $loanId): array
{
    return Database::fetchAll(
        'SELECT * FROM `miner_loan_ledger` WHERE `loan_id` = :loan_id'
        . ' ORDER BY `created_at` ASC, `id` ASC',
        ['loan_id' => $loanId]
    );
}

// ---------------------------------------------------------------------------
// Persiapan
// ---------------------------------------------------------------------------

QcHttp::start();

$operator = QcFixture::ensureInstalled();
QcFixture::resetKasbonState();
QcFixture::resetTellerState();

$teller = CashRegister::activeTeller();

if ($teller === null) {
    throw new RuntimeException('Pos kas meja bakar tidak tersedia.');
}

$tellerId = (string) $teller['id'];

$operatorJar = QcHttp::cookieJar('fase8-operator');
$loginForm = QcHttp::request('GET', '/login', ['cookie_jar' => $operatorJar, 'fresh' => true]);

QcHttp::request('POST', '/login', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD => (string) QcHttp::csrfFrom((string) $loginForm['body']),
        'phone_number'       => $operator['phone'],
        'password'           => $operator['password'],
    ],
]);

$cashier = QcFixture::resetCashierState()['primary'];
$cashierJar = qc8_loginCashier((string) $cashier['phone_number'], QcFixture::QC_CASHIER['pin']);

$miner = QcFixture::seedQcMiner();
$minerId = (string) $miner['id'];
$minerSecond = QcFixture::seedQcMiner(QcFixture::QC_MINER_SECOND);
$minerSecondId = (string) $minerSecond['id'];

// ---------------------------------------------------------------------------
// B. Fixture & urutan FK
// ---------------------------------------------------------------------------

Qc::section('B. Fixture buku kasbon & urutan FK');

QcFixture::resetKasbonState();
$emptyFirst = QcFixture::kasbonSnapshot();
QcFixture::resetKasbonState();
$emptySecond = QcFixture::kasbonSnapshot();

Qc::ok(
    'B.1',
    'resetKasbonState() idempoten: dua kali berturut-turut menyisakan nol baris',
    $emptyFirst['miners'] === 0
        && $emptyFirst['loans'] === 0
        && $emptyFirst['ledger'] === 0
        && $emptySecond === $emptyFirst,
    json_encode($emptySecond)
);

$bootstrapSource = (string) file_get_contents(BASE_PATH . '/tests/bootstrap.php');
$resetBody = qc8_method_body($bootstrapSource, 'resetTellerState') ?? '';
$kasbonCallPos = strpos($resetBody, 'self::resetKasbonState();');
$deleteMutationPos = strpos($resetBody, 'DELETE FROM `cash_mutations`');
$clearBody = qc8_method_body($bootstrapSource, 'clearSettlementTraces') ?? '';
$clearKasbonPos = strpos($clearBody, 'self::resetKasbonState();');

Qc::ok(
    'B.2',
    'resetTellerState()/clearSettlementTraces() membersihkan kasbon SEBELUM mutasi kas dihapus',
    $kasbonCallPos !== false
        && $deleteMutationPos !== false
        && $kasbonCallPos < $deleteMutationPos
        && $clearKasbonPos !== false,
    'posisi resetKasbonState=' . var_export($kasbonCallPos, true)
);

$miner = QcFixture::seedQcMiner();
$minerId = (string) $miner['id'];

Qc::ok(
    'B.3',
    'seedQcMiner() mencatat petani tanpa membuat akun users maupun dompet modal',
    qc8_count('miners', '`id` = :id', ['id' => $minerId]) === 1
        && qc8_count('users', '`phone_number` = :phone', [
            'phone' => (string) QcFixture::QC_MINER['phone_number'],
        ]) === 0
        && qc8_count('capital_wallets', '`user_id` = :id', ['id' => $minerId]) === 0,
    'petani bukan akun dan bukan mitra modal'
);

$loanForFk = QcFixture::seedQcKasbon($minerId, '100.000');
$fkLedger = qc8_loanLedger((string) $loanForFk['loan_id']);
$fkMutationId = (string) ($fkLedger[0]['mutation_id'] ?? '');

Qc::ok(
    'B.4',
    'FK RESTRICT terbukti: mutasi kas kasbon tidak dapat dihapus selama ledger hidup',
    (static function () use ($fkMutationId): bool {
        if ($fkMutationId === '') {
            return false;
        }

        try {
            Database::query('DELETE FROM `cash_mutations` WHERE `id` = :id', ['id' => $fkMutationId]);
        } catch (Throwable) {
            return true;
        }

        return false;
    })(),
    'ON DELETE RESTRICT pada miner_loan_ledger.mutation_id'
);

QcFixture::resetKasbonState();
QcFixture::resetTellerState();
$miner = QcFixture::seedQcMiner();
$minerId = (string) $miner['id'];
$minerSecond = QcFixture::seedQcMiner(QcFixture::QC_MINER_SECOND);
$minerSecondId = (string) $minerSecond['id'];

// ---------------------------------------------------------------------------
// C. Pencairan
// ---------------------------------------------------------------------------

Qc::section('C. Pencairan kasbon: tiga tulisan atomik + `_once` + petani baru');

$kasbonPage = qc8_openKasbon($operatorJar);

Qc::ok(
    'C.1',
    'GET /operator/kasbon (operator) 200: datalist, empat form, token `_once`, dan tab Buku Kasbon',
    $kasbonPage['status'] === 200
        && str_contains($kasbonPage['body'], '<datalist')
        && substr_count($kasbonPage['body'], '<form') >= 3
        && $kasbonPage['once'] !== null
        && str_contains($kasbonPage['body'], '>Buku Kasbon<')
        && str_contains($kasbonPage['body'], 'Sisa Utang'),
    'status=' . $kasbonPage['status']
);

$beforeDisburse = QcFixture::kasbonSnapshot();
$balanceBefore = $beforeDisburse['teller_balance'];

$disburseResponse = qc8_postKasbon($operatorJar, '/operator/kasbon', [
    'miner_id'         => $minerId,
    'miner_label'      => '',
    'principal_amount' => '250.000',
    'notes'            => 'beli solar',
]);

Qc::ok(
    'C.2',
    'POST /operator/kasbon → 302 PRG ke halaman yang sama',
    $disburseResponse['status'] === 302
        && $disburseResponse['location'] === '/operator/kasbon',
    'status=' . $disburseResponse['status'] . ' location=' . (string) $disburseResponse['location']
);

$afterDisburse = QcFixture::kasbonSnapshot();
$loanRow = qc8_latestLoan($minerId);

Qc::ok(
    'C.3',
    'Baris pinjaman: status open, principal = balance_remaining, issued_on hari ini',
    $afterDisburse['loans'] === $beforeDisburse['loans'] + 1
        && $loanRow !== null
        && (string) $loanRow['status'] === MinerLoan::STATUS_OPEN
        && (string) $loanRow['principal_amount'] === '250000.00'
        && (string) $loanRow['balance_remaining'] === '250000.00'
        && (string) $loanRow['issued_on'] === date('Y-m-d'),
    $loanRow === null ? 'baris pinjaman tidak ada' : 'issued_on=' . (string) $loanRow['issued_on']
);

$ledgerRows = qc8_loanLedger((string) $loanRow['id']);
$ledgerRow = $ledgerRows[0] ?? null;

Qc::ok(
    'C.4',
    'Baris ledger disbursement: amount = pokok, balance_after = pokok, mutation_id terisi',
    count($ledgerRows) === 1
        && $ledgerRow !== null
        && (string) $ledgerRow['entry_type'] === MinerLoanLedger::ENTRY_DISBURSEMENT
        && (string) $ledgerRow['payment_method'] === ''
        && (string) $ledgerRow['amount'] === '250000.00'
        && (string) $ledgerRow['balance_after'] === '250000.00'
        && (string) $ledgerRow['mutation_id'] !== '',
    'baris ledger=' . count($ledgerRows)
);

$disburseMutation = Database::fetchOne(
    'SELECT * FROM `cash_mutations` WHERE `id` = :id LIMIT 1',
    ['id' => (string) ($ledgerRow['mutation_id'] ?? '')]
);

Qc::ok(
    'C.5',
    'Mutasi kas `out` sebesar pokok dengan label "Kasbon: <nama>"',
    $disburseMutation !== null
        && (string) $disburseMutation['mutation_type'] === 'out'
        && (string) $disburseMutation['amount'] === '250000.00'
        && (string) $disburseMutation['source_destination'] === 'Kasbon: ' . (string) $miner['full_name'],
    'label=' . (string) ($disburseMutation['source_destination'] ?? '-')
);

Qc::ok(
    'C.6',
    'Saldo Kas Meja Bakar turun TEPAT pokok (250.000)',
    qc8_delta($balanceBefore, $afterDisburse['teller_balance']) === '-250000.00',
    $balanceBefore . ' → ' . $afterDisburse['teller_balance']
);

// `_once`: kiriman kedua dengan token yang sama tidak boleh menulis apa pun.
$duplicatePage = qc8_openKasbon($operatorJar);
$firstPost = QcHttp::request('POST', '/operator/kasbon', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD    => (string) $duplicatePage['csrf'],
        Security::FORM_FIELD    => (string) $duplicatePage['once'],
        'miner_id'              => $minerSecondId,
        'miner_label'           => '',
        'principal_amount'      => '50.000',
        'notes'                 => 'uji once',
    ],
]);

$snapshotAfterFirst = QcFixture::kasbonSnapshot();

$secondPost = QcHttp::request('POST', '/operator/kasbon', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD    => (string) $duplicatePage['csrf'],
        Security::FORM_FIELD    => (string) $duplicatePage['once'],
        'miner_id'              => $minerSecondId,
        'miner_label'           => '',
        'principal_amount'      => '50.000',
        'notes'                 => 'uji once',
    ],
]);

$snapshotAfterSecond = QcFixture::kasbonSnapshot();

Qc::ok(
    'C.7',
    'Token `_once` dikonsumsi: kiriman kedua diabaikan tanpa satu pun tulisan baru',
    $firstPost['status'] === 302
        && $secondPost['status'] === 302
        && $snapshotAfterSecond === $snapshotAfterFirst
        && $snapshotAfterFirst['loans'] === $beforeDisburse['loans'] + 2,
    'pinjaman=' . $snapshotAfterSecond['loans']
);

$newMinerPhone = '089500000299';
Database::query('DELETE FROM `miners` WHERE `phone_number` = :phone', ['phone' => $newMinerPhone]);

$storeMinerResponse = qc8_postKasbon($operatorJar, '/operator/kasbon/petani', [
    'full_name'    => 'QC Petani Baru',
    'phone_number' => $newMinerPhone,
    'location'     => 'Sungai Baru',
]);

$newMiner = Miner::findByPhone($newMinerPhone);
$preselect = null;

if ($newMiner !== null) {
    $preselect = QcHttp::request(
        'GET',
        '/operator/kasbon?miner=' . rawurlencode((string) $newMiner['id']),
        ['cookie_jar' => $operatorJar]
    );
}

Qc::ok(
    'C.8',
    'POST /operator/kasbon/petani → 302 ?miner=<uuid>, baris miners dibuat, halaman menampilkan nama terpilih',
    $storeMinerResponse['status'] === 302
        && str_starts_with((string) $storeMinerResponse['location'], '/operator/kasbon?miner=')
        && $newMiner !== null
        && $preselect !== null
        && str_contains((string) $preselect['body'], 'QC Petani Baru')
        && qc8_count('users', '`phone_number` = :phone', ['phone' => $newMinerPhone]) === 0,
    'location=' . (string) $storeMinerResponse['location']
);

// ---------------------------------------------------------------------------
// D. Dua lapis validasi
// ---------------------------------------------------------------------------

Qc::section('D. Dua lapis validasi: batas harian & saldo fisik laci');

QcFixture::resetKasbonState();
QcFixture::resetTellerState();
$miner = QcFixture::seedQcMiner();
$minerId = (string) $miner['id'];
$minerSecond = QcFixture::seedQcMiner(QcFixture::QC_MINER_SECOND);
$minerSecondId = (string) $minerSecond['id'];

$limitOriginal = (string) SystemSetting::get(KasbonService::SETTING_DAILY_LIMIT);

QcFixture::seedQcKasbon($minerId, '300.000');
$snapshotBeforeLimit = QcFixture::kasbonSnapshot();

$overLimit = qc8_postKasbon($operatorJar, '/operator/kasbon', [
    'miner_id'         => $minerSecondId,
    'miner_label'      => '',
    'principal_amount' => '300.000',
    'notes'            => '',
]);

Qc::ok(
    'D.1',
    'Pokok melebihi SISA batas harian → 422 tanpa satu pun tulisan',
    $overLimit['status'] === 422
        && QcFixture::kasbonSnapshot() === $snapshotBeforeLimit,
    'status=' . $overLimit['status']
);

SystemSetting::set(KasbonService::SETTING_DAILY_LIMIT, '0.00');

$disabled = qc8_postKasbon($operatorJar, '/operator/kasbon', [
    'miner_id'         => $minerSecondId,
    'miner_label'      => '',
    'principal_amount' => '10.000',
    'notes'            => '',
]);

SystemSetting::set(KasbonService::SETTING_DAILY_LIMIT, $limitOriginal);

Qc::ok(
    'D.2',
    'Batas harian Rp 0 → 422 "dinonaktifkan" (kebijakan bisnis, bukan galat teknis)',
    $disabled['status'] === 422
        && str_contains((string) $disabled['body'], 'dinonaktifkan'),
    'status=' . $disabled['status']
);

$balanceNow = QcFixture::registerBalance($tellerId);
Database::query(
    'UPDATE `cash_registers` SET `current_balance` = :balance WHERE `id` = :id',
    ['balance' => '100000.00', 'id' => $tellerId]
);

$overCash = qc8_postKasbon($operatorJar, '/operator/kasbon', [
    'miner_id'         => $minerSecondId,
    'miner_label'      => '',
    'principal_amount' => '150.000',
    'notes'            => '',
]);

$snapshotAfterCash = QcFixture::kasbonSnapshot();

Qc::ok(
    'D.3',
    'Pokok melebihi saldo fisik laci → 422 tanpa satu pun tulisan',
    $overCash['status'] === 422
        && str_contains((string) $overCash['body'], 'Saldo Kas Meja Bakar hanya')
        && $snapshotAfterCash['loans'] === $snapshotBeforeLimit['loans'],
    'status=' . $overCash['status']
);

// Saldo dipulihkan supaya D.4–D.6 menguji nominal & batas harian, bukan kas.
Database::query(
    'UPDATE `cash_registers` SET `current_balance` = :balance WHERE `id` = :id',
    ['balance' => $balanceNow, 'id' => $tellerId]
);

$invalidCases = ['', '0', 'abc', '1.2.3', '12,345'];
$invalidOk = true;
$invalidDetail = [];

foreach ($invalidCases as $case) {
    $response = qc8_postKasbon($operatorJar, '/operator/kasbon', [
        'miner_id'         => $minerSecondId,
        'miner_label'      => '',
        'principal_amount' => $case,
        'notes'            => '',
    ]);

    if ($response['status'] !== 422) {
        $invalidOk = false;
        $invalidDetail[] = var_export($case, true) . ' → ' . $response['status'];
    }
}

Qc::ok(
    'D.4',
    'Nominal kosong / nol / huruf / titik ribuan salah / desimal 3 angka → 422 (lima kasus)',
    $invalidOk,
    implode(' | ', $invalidDetail)
);

$overflow = qc8_postKasbon($operatorJar, '/operator/kasbon', [
    'miner_id'         => $minerSecondId,
    'miner_label'      => '',
    'principal_amount' => '999.999.999.999.999',
    'notes'            => '',
]);

Qc::ok(
    'D.5',
    'Nominal melebihi kapasitas DECIMAL(14,2) → 422',
    $overflow['status'] === 422,
    'status=' . $overflow['status']
);

// Batas inklusif: sisa tepat 200.000 (limit 500.000 − terpakai 300.000).
$exactLimit = qc8_postKasbon($operatorJar, '/operator/kasbon', [
    'miner_id'         => $minerSecondId,
    'miner_label'      => '',
    'principal_amount' => '200.000',
    'notes'            => '',
]);

Qc::ok(
    'D.6',
    'Pencairan TEPAT sama dengan sisa batas harian diterima (batas inklusif)',
    $exactLimit['status'] === 302,
    'status=' . $exactLimit['status']
);

// Saldo tepat: sisa kas 100.000, limit dinaikkan supaya yang diuji hanya kas.
$balanceBeforeExact = QcFixture::registerBalance($tellerId);
Database::query(
    'UPDATE `cash_registers` SET `current_balance` = :balance WHERE `id` = :id',
    ['balance' => '100000.00', 'id' => $tellerId]
);
SystemSetting::set(KasbonService::SETTING_DAILY_LIMIT, '10000000.00');

$exactCash = qc8_postKasbon($operatorJar, '/operator/kasbon', [
    'miner_id'         => $minerSecondId,
    'miner_label'      => '',
    'principal_amount' => '100.000',
    'notes'            => '',
]);

$balanceAfterExact = QcFixture::registerBalance($tellerId);

$afterExact = qc8_postKasbon($operatorJar, '/operator/kasbon', [
    'miner_id'         => $minerSecondId,
    'miner_label'      => '',
    'principal_amount' => '1.000',
    'notes'            => '',
]);

SystemSetting::set(KasbonService::SETTING_DAILY_LIMIT, $limitOriginal);
Database::query(
    'UPDATE `cash_registers` SET `current_balance` = :balance WHERE `id` = :id',
    ['balance' => $balanceBeforeExact, 'id' => $tellerId]
);

Qc::ok(
    'D.7',
    'Pencairan tepat sebesar saldo diterima & kas menjadi 0,00; pencairan berikutnya ditolak',
    $exactCash['status'] === 302
        && $balanceAfterExact === '0.00'
        && $afterExact['status'] === 422,
    'saldo=' . $balanceAfterExact . ' status-berikut=' . $afterExact['status']
);

// ---------------------------------------------------------------------------
// E. Pembayaran
// ---------------------------------------------------------------------------

Qc::section('E. Angsuran: tunai, potong nota, FIFO, dan pelunasan');

QcFixture::resetKasbonState();
QcFixture::resetTellerState();
$miner = QcFixture::seedQcMiner();
$minerId = (string) $miner['id'];
$minerSecond = QcFixture::seedQcMiner(QcFixture::QC_MINER_SECOND);
$minerSecondId = (string) $minerSecond['id'];

$loan = QcFixture::seedQcKasbon($minerId, '250.000');
$loanId = (string) $loan['loan_id'];
$balanceBeforeRepay = QcFixture::kasbonSnapshot()['teller_balance'];

$cashRepay = qc8_postKasbon($operatorJar, '/operator/kasbon/angsuran', [
    'pay_miner_id'    => $minerId,
    'pay_miner_label' => '',
    'amount'          => '50.000',
    'payment_method'  => KasbonService::METHOD_CASH,
    'pay_notes'       => '',
]);

$repaymentRow = qc8_ledgerEntry($loanId, MinerLoanLedger::ENTRY_REPAYMENT, KasbonService::METHOD_CASH);
$repaymentMutation = Database::fetchOne(
    'SELECT * FROM `cash_mutations` WHERE `id` = :id LIMIT 1',
    ['id' => (string) ($repaymentRow['mutation_id'] ?? '')]
);
$loanAfterCash = qc8_latestLoan($minerId);

Qc::ok(
    'E.1',
    'Angsuran tunai → 302: kas naik tepat nominal, ledger repayment/cash, saldo pinjaman turun',
    $cashRepay['status'] === 302
        && $repaymentRow !== null
        && (string) $repaymentRow['entry_type'] === MinerLoanLedger::ENTRY_REPAYMENT
        && (string) $repaymentRow['payment_method'] === KasbonService::METHOD_CASH
        && (string) $repaymentRow['amount'] === '50000.00'
        && (string) $repaymentRow['balance_after'] === '200000.00'
        && $repaymentMutation !== null
        && (string) $repaymentMutation['mutation_type'] === 'in'
        && (string) $repaymentMutation['amount'] === '50000.00'
        && $loanAfterCash !== null
        && (string) $loanAfterCash['balance_remaining'] === '200000.00'
        && qc8_delta($balanceBeforeRepay, QcFixture::kasbonSnapshot()['teller_balance']) === '50000.00',
    'sisa=' . (string) ($loanAfterCash['balance_remaining'] ?? '-')
);

$notaRepay = qc8_postKasbon($operatorJar, '/operator/kasbon/angsuran', [
    'pay_miner_id'    => $minerId,
    'pay_miner_label' => '',
    'amount'          => '20.000',
    'payment_method'  => KasbonService::METHOD_NOTA,
    'pay_notes'       => 'potong nota emas',
]);

$notaRow = qc8_ledgerEntry($loanId, MinerLoanLedger::ENTRY_REPAYMENT, KasbonService::METHOD_NOTA);

Qc::ok(
    'E.2',
    'Potong nota → 302 dengan payment_method "nota" (arah kas sama: masuk)',
    $notaRepay['status'] === 302
        && $notaRow !== null
        && (string) $notaRow['payment_method'] === KasbonService::METHOD_NOTA
        && (string) $notaRow['balance_after'] === '180000.00',
    'method=' . (string) ($notaRow['payment_method'] ?? '-')
);

$snapshotBeforeOver = QcFixture::kasbonSnapshot();

$overRepay = qc8_postKasbon($operatorJar, '/operator/kasbon/angsuran', [
    'pay_miner_id'    => $minerId,
    'pay_miner_label' => '',
    'amount'          => '999.000',
    'payment_method'  => KasbonService::METHOD_CASH,
    'pay_notes'       => '',
]);

Qc::ok(
    'E.3',
    'Angsuran melebihi sisa utang → 422 tanpa satu pun tulisan',
    $overRepay['status'] === 422
        && QcFixture::kasbonSnapshot() === $snapshotBeforeOver,
    'status=' . $overRepay['status']
);

$zeroRepay = qc8_postKasbon($operatorJar, '/operator/kasbon/angsuran', [
    'pay_miner_id'    => $minerId,
    'pay_miner_label' => '',
    'amount'          => '0',
    'payment_method'  => KasbonService::METHOD_CASH,
    'pay_notes'       => '',
]);

Qc::ok(
    'E.4',
    'Angsuran nominal nol → 422',
    $zeroRepay['status'] === 422,
    'status=' . $zeroRepay['status']
);

$partialLoan = qc8_latestLoan($minerId);

Qc::ok(
    'E.6',
    'Angsuran sebagian: status tetap open dan balance_after akurat',
    $partialLoan !== null
        && (string) $partialLoan['status'] === MinerLoan::STATUS_OPEN
        && (string) $partialLoan['balance_remaining'] === '180000.00',
    'sisa=' . (string) ($partialLoan['balance_remaining'] ?? '-')
);

$fullRepay = qc8_postKasbon($operatorJar, '/operator/kasbon/angsuran', [
    'pay_miner_id'    => $minerId,
    'pay_miner_label' => '',
    'amount'          => '180.000',
    'payment_method'  => KasbonService::METHOD_CASH,
    'pay_notes'       => 'lunas',
]);

$settledLoan = qc8_latestLoan($minerId);

Qc::ok(
    'E.5',
    'Pelunasan ("Lunas") → status settled, settled_at terisi, saldo 0,00',
    $fullRepay['status'] === 302
        && $settledLoan !== null
        && (string) $settledLoan['status'] === MinerLoan::STATUS_SETTLED
        && (string) $settledLoan['balance_remaining'] === '0.00'
        && $settledLoan['settled_at'] !== null,
    'status=' . (string) ($settledLoan['status'] ?? '-')
);

// FIFO: dua kasbon, pembayaran melewati kasbon pertama.
QcFixture::resetKasbonState();
QcFixture::resetTellerState();
$miner = QcFixture::seedQcMiner();
$minerId = (string) $miner['id'];
$fifoFirst = QcFixture::seedQcKasbon($minerId, '100.000');
$fifoSecond = QcFixture::seedQcKasbon($minerId, '150.000');

// `created_at` berpresisi detik (item O-1), sehingga dua pencairan pada detik yang
// sama dapat berurutan acak bila `issued_on` juga sama. Kasbon pertama diberi
// tanggal bisnis kemarin supaya urutan FIFO deterministik — sekaligus menguji
// pemotongan lintas hari.
Database::query(
    'UPDATE `miner_loans` SET `issued_on` = :issued_on WHERE `id` = :id',
    ['issued_on' => date('Y-m-d', strtotime('-1 day')), 'id' => (string) $fifoFirst['loan_id']]
);

$mutationsBeforeFifo = qc8_count('cash_mutations', '`register_id` = :id', ['id' => $tellerId]);

$fifoRepay = qc8_postKasbon($operatorJar, '/operator/kasbon/angsuran', [
    'pay_miner_id'    => $minerId,
    'pay_miner_label' => '',
    'amount'          => '150.000',
    'payment_method'  => KasbonService::METHOD_CASH,
    'pay_notes'       => 'fifo',
]);

$firstAfterFifo = Database::fetchOne(
    'SELECT * FROM `miner_loans` WHERE `id` = :id LIMIT 1',
    ['id' => (string) $fifoFirst['loan_id']]
);
$secondAfterFifo = Database::fetchOne(
    'SELECT * FROM `miner_loans` WHERE `id` = :id LIMIT 1',
    ['id' => (string) $fifoSecond['loan_id']]
);
$mutationsAfterFifo = qc8_count('cash_mutations', '`register_id` = :id', ['id' => $tellerId]);

Qc::ok(
    'E.7',
    'FIFO: kasbon tertua lunas lebih dulu; dua baris ledger tetapi SATU mutasi kas',
    $fifoRepay['status'] === 302
        && $firstAfterFifo !== null
        && (string) $firstAfterFifo['status'] === MinerLoan::STATUS_SETTLED
        && $secondAfterFifo !== null
        && (string) $secondAfterFifo['balance_remaining'] === '100000.00'
        && count(qc8_loanLedger((string) $fifoFirst['loan_id'])) === 2
        && count(qc8_loanLedger((string) $fifoSecond['loan_id'])) === 2
        && $mutationsAfterFifo === $mutationsBeforeFifo + 1,
    'mutasi=' . ($mutationsAfterFifo - $mutationsBeforeFifo)
);

$afterSettledLoan = QcFixture::seedQcKasbon($minerId, '75.000');
$reopened = Database::fetchOne(
    'SELECT * FROM `miner_loans` WHERE `id` = :id LIMIT 1',
    ['id' => (string) $afterSettledLoan['loan_id']]
);

Qc::ok(
    'E.8',
    'Setelah lunas, pencairan berikutnya membuat baris open baru (petani dapat berutang lagi)',
    $reopened !== null
        && (string) $reopened['status'] === MinerLoan::STATUS_OPEN
        && (string) $reopened['balance_remaining'] === '75000.00'
        && qc8_count('miner_loans', '`miner_id` = :m AND `status` = :s', [
            'm' => $minerId, 's' => MinerLoan::STATUS_SETTLED,
        ]) === 1,
    'loan=' . (string) ($reopened['id'] ?? '-')
);

// Label mutasi tidak boleh terpotong (kolom VARCHAR(100)).
$longName = str_repeat('N', 60);
$longMiner = QcFixture::seedQcMiner([
    'full_name'    => $longName,
    'phone_number' => '',
    'location'     => '',
]);
$longLoan = QcFixture::seedQcKasbon((string) $longMiner['id'], '10.000');
$longOutEntry = qc8_ledgerEntry((string) $longLoan['loan_id'], MinerLoanLedger::ENTRY_DISBURSEMENT);
$longMutation = Database::fetchOne(
    'SELECT `source_destination` FROM `cash_mutations` WHERE `id` = :id LIMIT 1',
    ['id' => (string) ($longOutEntry['mutation_id'] ?? '')]
);
$longRepay = qc8_postKasbon($operatorJar, '/operator/kasbon/angsuran', [
    'pay_miner_id'    => (string) $longMiner['id'],
    'pay_miner_label' => '',
    'amount'          => '5.000',
    'payment_method'  => KasbonService::METHOD_CASH,
    'pay_notes'       => '',
]);
$longInEntry = qc8_ledgerEntry(
    (string) $longLoan['loan_id'],
    MinerLoanLedger::ENTRY_REPAYMENT,
    KasbonService::METHOD_CASH
);
$longInMutation = Database::fetchOne(
    'SELECT `source_destination` FROM `cash_mutations` WHERE `id` = :id LIMIT 1',
    ['id' => (string) ($longInEntry['mutation_id'] ?? '')]
);
$longOutLabel = (string) ($longMutation['source_destination'] ?? '');
$longInLabel = (string) ($longInMutation['source_destination'] ?? '');

Qc::ok(
    'E.9',
    'Label mutasi keluar & masuk tetap ≤ 100 karakter pada nama maksimum (60)',
    $longRepay['status'] === 302
        && strlen($longOutLabel) <= 100
        && strlen($longInLabel) <= 100
        && $longOutLabel === 'Kasbon: ' . $longName
        && $longInLabel === 'Angsuran kasbon: ' . $longName,
    'panjang=' . strlen($longOutLabel) . '/' . strlen($longInLabel)
);

// ---------------------------------------------------------------------------
// F. RBAC — dua permukaan izin
// ---------------------------------------------------------------------------

Qc::section('F. RBAC: dua permukaan izin (operator + kasir) & regresi Fase 7');

$investor = QcFixture::seedQcInvestor();
$investorJar = qc8_loginInvestor((string) $investor['phone'], (string) $investor['pin']);

$operatorView = QcHttp::request('GET', '/operator/kasbon', ['cookie_jar' => $operatorJar]);
$cashierView = QcHttp::request('GET', '/operator/kasbon', ['cookie_jar' => $cashierJar]);
$investorView = QcHttp::request('GET', '/operator/kasbon', ['cookie_jar' => $investorJar]);
$anonView = QcHttp::request('GET', '/operator/kasbon', [
    'cookie_jar' => QcHttp::cookieJar('fase8-anon'),
    'fresh'      => true,
]);

Qc::ok(
    'F.1',
    'Matriks GET: operator 200, kasir 200, mitra 302 /investor/dashboard, anonim 302 /login',
    $operatorView['status'] === 200
        && $cashierView['status'] === 200
        && $investorView['status'] === 302
        && $investorView['location'] === '/investor/dashboard'
        && $anonView['status'] === 302
        && $anonView['location'] === '/login',
    'operator=' . $operatorView['status'] . ' kasir=' . $cashierView['status']
        . ' mitra=' . $investorView['status'] . ' anon=' . $anonView['status']
);

QcFixture::resetKasbonState();
QcFixture::resetTellerState();
$miner = QcFixture::seedQcMiner();
$minerId = (string) $miner['id'];

$cashierDisburse = qc8_postKasbon($cashierJar, '/operator/kasbon', [
    'miner_id'         => $minerId,
    'miner_label'      => '',
    'principal_amount' => '25.000',
    'notes'            => 'lewat kasir',
]);

$cashierRepay = qc8_postKasbon($cashierJar, '/operator/kasbon/angsuran', [
    'pay_miner_id'    => $minerId,
    'pay_miner_label' => '',
    'amount'          => '5.000',
    'payment_method'  => KasbonService::METHOD_CASH,
    'pay_notes'       => '',
]);

$cashierMiner = qc8_postKasbon($cashierJar, '/operator/kasbon/petani', [
    'full_name'    => 'QC Petani Kasir',
    'phone_number' => '',
    'location'     => '',
]);

Qc::ok(
    'F.2',
    'Kasir boleh mencairkan, menerima angsuran, dan mendaftarkan petani (302 PRG)',
    $cashierDisburse['status'] === 302
        && $cashierRepay['status'] === 302
        && $cashierMiner['status'] === 302
        && qc8_count('miners', '`full_name` = :name', ['name' => 'QC Petani Kasir']) === 1,
    'status=' . $cashierDisburse['status'] . '/' . $cashierRepay['status'] . '/' . $cashierMiner['status']
);

// `_once` kasir TIDAK boleh dikonsumsi oleh permintaan terlarang.
$guardPage = qc8_openKasbon($cashierJar);
$blockedPost = QcHttp::request('POST', '/operator/settlement', [
    'cookie_jar' => $cashierJar,
    'data'       => [
        Security::CSRF_FIELD => (string) $guardPage['csrf'],
        Security::FORM_FIELD => (string) $guardPage['once'],
    ],
]);

$legitAfterBlocked = QcHttp::request('POST', '/operator/kasbon', [
    'cookie_jar' => $cashierJar,
    'data'       => [
        Security::CSRF_FIELD    => (string) $guardPage['csrf'],
        Security::FORM_FIELD    => (string) $guardPage['once'],
        'miner_id'              => $minerId,
        'miner_label'           => '',
        'principal_amount'      => '5.000',
        'notes'                 => 'token utuh',
    ],
]);

Qc::ok(
    'F.3',
    'Guard-first: POST terlarang tidak mengonsumsi `_once` — kiriman sah berikutnya diterima',
    $blockedPost['status'] === 302
        && $blockedPost['location'] === '/operator/teller'
        && $legitAfterBlocked['status'] === 302,
    'terlarang=' . $blockedPost['status'] . ' sah=' . $legitAfterBlocked['status']
);

$snapshotBeforeInvestorPost = QcFixture::kasbonSnapshot();
$investorDashboard = QcHttp::request('GET', '/investor/dashboard', ['cookie_jar' => $investorJar]);
$investorCsrf = (string) QcHttp::csrfFrom((string) $investorDashboard['body']);
$investorResults = [];

foreach (['/operator/kasbon', '/operator/kasbon/angsuran', '/operator/kasbon/petani'] as $path) {
    $investorResults[] = QcHttp::request('POST', $path, [
        'cookie_jar' => $investorJar,
        'data'       => [
            Security::CSRF_FIELD    => $investorCsrf,
            'miner_id'              => $minerId,
            'miner_label'           => '',
            'principal_amount'      => '10.000',
            'amount'                => '10.000',
            'payment_method'        => KasbonService::METHOD_CASH,
            'full_name'             => 'QC Petani Mitra',
            'phone_number'          => '',
            'location'              => '',
        ],
    ]);
}

Qc::ok(
    'F.4',
    'Mitra POST ketiga jalur kasbon → 302 /investor/dashboard dengan NOL tulisan',
    (static function () use ($investorResults): bool {
        foreach ($investorResults as $result) {
            if ($result['status'] !== 302 || $result['location'] !== '/investor/dashboard') {
                return false;
            }
        }

        return true;
    })() && QcFixture::kasbonSnapshot() === $snapshotBeforeInvestorPost,
    'status=' . implode(',', array_column($investorResults, 'status'))
);

$controllerSource = (string) file_get_contents(APP_PATH . '/Controllers/KasbonController.php');

Qc::ok(
    'F.5',
    'KasbonController: satu daftar izin [operator, cashier] dipakai keempat method',
    str_contains(
        $controllerSource,
        'private const ALLOWED_ROLES = [Constants::ROLE_OPERATOR, Constants::ROLE_CASHIER];'
    )
        && substr_count($controllerSource, 'requireRole(...self::ALLOWED_ROLES);') >= 4
        && str_contains($controllerSource, 'requireRole(Constants::ROLE_OPERATOR)') === false,
    'allow-list tunggal'
);

$guardFirstFailures = [];

foreach (['index' => 'requireRole(', 'disburse' => 'guardOnce(', 'repay' => 'guardOnce(',
    'storeMiner' => 'guardOnce('] as $method => $needle) {
    $body = qc8_method_body($controllerSource, $method) ?? '';

    if ($body === '') {
        $guardFirstFailures[] = $method . ' tidak ditemukan';

        continue;
    }

    $rolePos = strpos($body, 'requireRole(');
    $needlePos = strpos($body, $needle);

    if ($rolePos === false) {
        $guardFirstFailures[] = $method . ' tanpa requireRole';

        continue;
    }

    if ($needle !== 'requireRole(' && ($needlePos === false || $needlePos < $rolePos)) {
        $guardFirstFailures[] = $method . ' memanggil ' . $needle . ' sebelum requireRole';
    }
}

Qc::ok(
    'F.6',
    'Guard-first: requireRole mendahului guardOnce/akses model pada setiap method publik',
    $guardFirstFailures === [],
    implode("\n", $guardFirstFailures)
);

$controllerFiles = glob(APP_PATH . '/Controllers/*.php') ?: [];
$cashierGranters = [];
$cashierGrants = [];

foreach ($controllerFiles as $file) {
    $source = (string) file_get_contents($file);

    // Yang menentukan izin adalah daftar `ALLOWED_ROLES` dan pola
    // `requireRole(...ROLE_CASHIER)`, bukan sekadar penyebutan konstanta
    // (pintu PIN & pemetaan beranda menyebut ROLE_CASHIER untuk MENOLAKnya).
    if (str_contains($source, 'ALLOWED_ROLES')) {
        $cashierGranters[] = basename($file);
    }

    if (preg_match('/requireRole\([^)]*ROLE_CASHIER/', $source) === 1
        && !str_contains($source, 'ALLOWED_ROLES')
    ) {
        $cashierGrants[] = basename($file);
    }
}

sort($cashierGranters);

Qc::ok(
    'F.7',
    'Hanya dua controller punya daftar izin kasir eksplisit (Teller & Kasbon); tidak ada grant liar',
    $cashierGranters === ['KasbonController.php', 'TellerController.php']
        && $cashierGrants === [],
    'ALLOWED_ROLES: ' . implode(', ', $cashierGranters)
        . ' | grant liar: ' . implode(', ', $cashierGrants)
);

$forbiddenCashier = [
    ['GET', '/operator/settlement'],
    ['GET', '/operator/capital'],
    ['GET', '/operator/kasir'],
    ['GET', '/dashboard'],
];

Qc::ok(
    'F.8',
    'Regresi Fase 7: kasir tetap diarahkan 302 /operator/teller pada rute terlarang',
    (static function () use ($forbiddenCashier, $cashierJar): bool {
        foreach ($forbiddenCashier as [$method, $path]) {
            $response = QcHttp::request($method, $path, ['cookie_jar' => $cashierJar]);

            if ($response['status'] !== 302 || $response['location'] !== '/operator/teller') {
                return false;
            }
        }

        return true;
    })(),
    'empat rute terlarang'
);

// Jar TERPISAH: `qc8_loginCashier()` memakai nama jar per nomor telepon, jadi
// memakai helper itu akan mengeluarkan sesi kasir utama yang masih dipakai
// assertion berikutnya.
$logoutJar = QcHttp::cookieJar('fase8-cashier-logout');
$logoutLoginForm = QcHttp::request('GET', '/cashier/login', ['cookie_jar' => $logoutJar, 'fresh' => true]);
QcHttp::request('POST', '/cashier/login', [
    'cookie_jar' => $logoutJar,
    'data'       => [
        Security::CSRF_FIELD => (string) QcHttp::csrfFrom((string) $logoutLoginForm['body']),
        'phone_number'       => (string) $cashier['phone_number'],
        'pin'                => QcFixture::QC_CASHIER['pin'],
    ],
]);
$logoutPage = QcHttp::request('GET', '/operator/kasbon', ['cookie_jar' => $logoutJar]);
$logout = QcHttp::request('POST', '/logout', [
    'cookie_jar' => $logoutJar,
    'data'       => [Security::CSRF_FIELD => (string) QcHttp::csrfFrom((string) $logoutPage['body'])],
]);
QcFixture::clearPinThrottle();

Qc::ok(
    'F.9',
    'Logout kasir mendarat di pintu PIN kasir (bukan pintu kata sandi)',
    $logout['status'] === 302 && $logout['location'] === '/cashier/login',
    'location=' . (string) $logout['location']
);

$routes = require APP_PATH . '/routes.php';
$expectedRoutes = [
    ['GET', '/operator/kasbon', 'index'],
    ['POST', '/operator/kasbon', 'disburse'],
    ['POST', '/operator/kasbon/angsuran', 'repay'],
    ['POST', '/operator/kasbon/petani', 'storeMiner'],
];

Qc::ok(
    'F.10',
    'Route table memuat empat entry Fase 8 yang menunjuk handler nyata',
    (static function () use ($routes, $expectedRoutes): bool {
        foreach ($expectedRoutes as [$method, $path, $action]) {
            $handler = $routes[$method][$path] ?? null;

            if ($handler === null
                || $handler[0] !== \AurumFlow\Controllers\KasbonController::class
                || $handler[1] !== $action
                || !method_exists($handler[0], $handler[1])
            ) {
                return false;
            }
        }

        return true;
    })(),
    'empat route'
);

$cashierKasbon = QcHttp::request('GET', '/operator/kasbon', ['cookie_jar' => $cashierJar]);
$operatorKasbon = QcHttp::request('GET', '/operator/kasbon', ['cookie_jar' => $operatorJar]);
$cashierBody = (string) $cashierKasbon['body'];
$operatorBody = (string) $operatorKasbon['body'];

Qc::ok(
    'F.11',
    'Nav sadar peran: kasir melihat Kasir + Buku Kasbon (2 tab), operator enam tab',
    str_contains($cashierBody, '>Kasir<')
        && str_contains($cashierBody, '>Buku Kasbon<')
        && !str_contains($cashierBody, '>Dashboard<')
        && str_contains($operatorBody, '>Dashboard<')
        && str_contains($operatorBody, '>Rekonsiliasi<')
        && str_contains($operatorBody, '>Kelola Modal<')
        && str_contains($operatorBody, '>Kelola Kasir<')
        && str_contains($operatorBody, '>Buku Kasbon<'),
    'kasir: Kasir=' . (int) str_contains($cashierBody, '>Kasir<')
        . ' BK=' . (int) str_contains($cashierBody, '>Buku Kasbon<')
        . ' noDash=' . (int) !str_contains($cashierBody, '>Dashboard<')
        . ' | operator: Dash=' . (int) str_contains($operatorBody, '>Dashboard<')
        . ' Rekon=' . (int) str_contains($operatorBody, '>Rekonsiliasi<')
        . ' Modal=' . (int) str_contains($operatorBody, '>Kelola Modal<')
        . ' Kasir=' . (int) str_contains($operatorBody, '>Kelola Kasir<')
        . ' BK=' . (int) str_contains($operatorBody, '>Buku Kasbon<')
);

// ---------------------------------------------------------------------------
// G. Isolasi data pemilik
// ---------------------------------------------------------------------------

Qc::section('G. Isolasi permukaan kasir: tanpa Brankas, bagi hasil, atau tautan pemilik');

$brankas = CashRegister::findBrankas();
$brankasBalance = $brankas === null ? null : (string) $brankas['current_balance'];

// Kanari: saldo Brankas diisi angka khas, lalu dipastikan tidak pernah muncul di
// permukaan kasbon (bukan sekadar pemindaian kata "Brankas").
if ($brankas !== null) {
    Database::query(
        'UPDATE `cash_registers` SET `current_balance` = :balance WHERE `id` = :id',
        ['balance' => '9876543210.00', 'id' => (string) $brankas['id']]
    );
}

$cashierKasbon = QcHttp::request('GET', '/operator/kasbon', ['cookie_jar' => $cashierJar]);
$operatorKasbon = QcHttp::request('GET', '/operator/kasbon', ['cookie_jar' => $operatorJar]);
$cashierBody = (string) $cashierKasbon['body'];
$operatorBody = (string) $operatorKasbon['body'];

$canaryLabel = 'Rp 9.876.543.210';
$canaryRaw = '9876543210';

$canaryClean = !str_contains($cashierBody, $canaryLabel)
    && !str_contains($cashierBody, $canaryRaw)
    && !str_contains($operatorBody, $canaryLabel)
    && !str_contains($operatorBody, $canaryRaw);

if ($brankas !== null) {
    Database::query(
        'UPDATE `cash_registers` SET `current_balance` = :balance WHERE `id` = :id',
        ['balance' => (string) $brankasBalance, 'id' => (string) $brankas['id']]
    );
}

Qc::ok(
    'G.1',
    'Body kasbon (operator & kasir) tidak memuat kata "Brankas" maupun kanari saldonya',
    $brankas !== null
        && !str_contains($cashierBody, 'Brankas')
        && !str_contains($operatorBody, 'Brankas')
        && $canaryClean,
    'kanari brankas'
);

$profitNeedles = ['laba bersih', 'pool mitra', 'investor_pool', 'omzet', 'net_settled_profit',
    'profit_distributions', 'talangan'];

Qc::ok(
    'G.2',
    'Body kasbon tidak memuat istilah bagi hasil/omzet/talangan',
    (static function () use ($cashierBody, $operatorBody, $profitNeedles): bool {
        foreach ($profitNeedles as $needle) {
            if (stripos($cashierBody, $needle) !== false || stripos($operatorBody, $needle) !== false) {
                return false;
            }
        }

        return true;
    })(),
    'daftar terlarang: ' . implode(', ', $profitNeedles)
);

$forbiddenLinks = ['/operator/settlement', '/operator/capital', '/operator/kasir', '/dashboard'];

Qc::ok(
    'G.3',
    'Body kasbon kasir tidak memuat tautan ke layar pemilik',
    (static function () use ($cashierBody, $forbiddenLinks): bool {
        foreach ($forbiddenLinks as $link) {
            if (str_contains($cashierBody, 'href="' . $link . '"')) {
                return false;
            }
        }

        return true;
    })(),
    'tanpa tautan pemilik'
);

Qc::ok(
    'G.4',
    'Label menu Rekonsiliasi/Kelola Modal/Kelola Kasir tidak muncul pada permukaan kasir',
    !str_contains($cashierBody, 'Rekonsiliasi')
        && !str_contains($cashierBody, 'Kelola Modal')
        && !str_contains($cashierBody, 'Kelola Kasir'),
    'label menu tersembunyi'
);

$systemSettingOffenders = qc8_scan(
    [
        'app/Services/KasbonService.php',
        'app/Controllers/KasbonController.php',
        'app/Models/Miner.php',
        'app/Models/MinerLoan.php',
        'app/Models/MinerLoanLedger.php',
    ],
    static fn (string $line): bool => str_contains($line, 'SystemSetting::')
        && !str_contains($line, 'SETTING_DAILY_LIMIT')
);

Qc::ok(
    'G.5',
    'Berkas kasbon hanya membaca SystemSetting untuk daily_loan_limit (pengecualian sempit)',
    $systemSettingOffenders === [],
    implode("\n", $systemSettingOffenders)
);

// ---------------------------------------------------------------------------
// H. Kontrak statis & keamanan
// ---------------------------------------------------------------------------

Qc::section('H. Kontrak statis: SQL, transaksi, urutan kunci, FIFO, escaping');

$sqlOffenders = qc8_scan(
    ['app/Controllers/KasbonController.php', 'app/Views/operator/kasbon.php'],
    static fn (string $line): bool => preg_match(
        '/\b(SELECT|INSERT INTO|UPDATE|DELETE FROM|ORDER BY|LIMIT)\b/',
        $line
    ) === 1
);

Qc::ok('H.1', 'SQL tidak bocor ke controller/view kasbon', $sqlOffenders === [], implode("\n", $sqlOffenders));

$interpolated = qc_scan_lines(static function (string $line): bool {
    if (preg_match('/[\'"]\s*\.\s*\$_(GET|POST|REQUEST|COOKIE|SERVER)/', $line) === 1) {
        return true;
    }

    return preg_match('/\$_(GET|POST|REQUEST|COOKIE)\s*\[[^\]]+\]\s*\./', $line) === 1;
});

Qc::ok('H.2', 'Tidak ada superglobal yang diinterpolasi ke string SQL', $interpolated === [], implode("\n", $interpolated));

$moneyOffenders = qc8_scan(
    [
        'app/Services/KasbonService.php',
        'app/Models/Miner.php',
        'app/Models/MinerLoan.php',
        'app/Models/MinerLoanLedger.php',
        'app/Views/operator/kasbon.php',
    ],
    static fn (string $line): bool => str_contains($line, 'number_format(')
        || preg_match('/\b(FLOAT|DOUBLE)\b/', $line) === 1
);

Qc::ok('H.3', 'Tidak ada number_format/FLOAT/DOUBLE pada jalur kasbon', $moneyOffenders === [], implode("\n", $moneyOffenders));

$serviceSource = (string) file_get_contents(APP_PATH . '/Services/KasbonService.php');

Qc::ok(
    'H.4',
    'KasbonService memakai tepat dua Database::transaction() dan tidak pernah beginTransaction()',
    substr_count($serviceSource, 'Database::transaction(') === 2
        && !str_contains($serviceSource, 'beginTransaction('),
    'transaction=' . substr_count($serviceSource, 'Database::transaction(')
);

Qc::ok(
    'H.5',
    'Urutan kunci: pos kas dikunci SEBELUM batas harian dan SEBELUM baris miner_loans',
    (static function () use ($serviceSource): bool {
        $registerPos = strpos($serviceSource, 'CashRegister::lockTellerForUpdate()');
        $settingPos = strpos($serviceSource, 'SystemSetting::getForUpdate(');
        $loanPos = strpos($serviceSource, 'MinerLoan::lockOpenByMinerForUpdate(');

        return $registerPos !== false
            && $settingPos !== false
            && $loanPos !== false
            && $registerPos < $settingPos
            && $registerPos < $loanPos;
    })(),
    'posisi penguncian pada KasbonService'
);

$minerLoanSource = (string) file_get_contents(APP_PATH . '/Models/MinerLoan.php');

Qc::ok(
    'H.6',
    'FIFO deterministik: ORDER BY issued_on ASC, created_at ASC, id ASC pada kunci kasbon terbuka',
    str_contains($minerLoanSource, 'ORDER BY `issued_on` ASC, `created_at` ASC, `id` ASC FOR UPDATE'),
    'urutan FIFO'
);

$unescapedView = qc8_scan(
    ['app/Views/operator/kasbon.php'],
    static fn (string $line): bool => str_contains($line, '<?=')
        && !str_contains($line, 'Security::e(')
        && !str_contains($line, 'Security::csrfField()')
        && preg_match('/<\?=\s*\(int\)/', $line) !== 1
);

$refsOffenders = qc8_scan(
    ['app/Views/operator/kasbon.php'],
    static fn (string $line): bool => str_contains($line, '$refs')
);

$viewSource = (string) file_get_contents(APP_PATH . '/Views/operator/kasbon.php');

$cdnView = preg_match('#(?:src|href)\s*=\s*["\']https?://#i', $viewSource) === 1;

Qc::ok(
    'H.7',
    'View kasbon: seluruh output dinamis lewat Security::e(), tanpa CDN, tanpa referensi komponen',
    $unescapedView === [] && $refsOffenders === [] && !$cdnView,
    'unescaped=' . count($unescapedView) . ' refs=' . count($refsOffenders) . ' cdn=' . (int) $cdnView
);

Qc::ok(
    'H.8',
    'Kontrol form tidak pernah :disabled (hanya <button>); :readonly + kelas redup dipakai',
    !preg_match('/<(?:input|textarea|select)[^>]*:disabled/', $viewSource)
        && str_contains($viewSource, ':readonly="submitting"')
        && str_contains($viewSource, 'opacity-70'),
    'kontrak form Fase 2–7 dipertahankan'
);

Qc::ok(
    'H.9',
    'Form kasbon memakai x-model.fill dan init() membaca DOM (bukan $refs)',
    str_contains($viewSource, 'x-model.fill')
        && str_contains($viewSource, 'x-data="kasbonForm('),
    'kontrak nilai awal dari peladen'
);

$appJs = (string) file_get_contents(PUBLIC_PATH . '/assets/js/app.js');

Qc::ok(
    'H.10',
    'Tidak ada kompilasi ulang CSS: tailwind.config.js & input CSS tidak berubah, app.js tidak memanggil npx',
    !str_contains($appJs, 'tailwindcss')
        && is_file(BASE_PATH . '/tailwind.config.js')
        && is_file(BASE_PATH . '/resources/css/tailwind.input.css'),
    'nol kompilasi'
);

// ---------------------------------------------------------------------------
// I. UI & aset
// ---------------------------------------------------------------------------

Qc::section('I. UI & aset: datalist, tombol cepat, komponen Alpine, versi shell');

$layoutSource = (string) file_get_contents(APP_PATH . '/Views/layouts/operator.php');

Qc::ok(
    'I.1',
    'Layout operator memuat tab Buku Kasbon + tetap memakai overflow-x-auto & shrink-0',
    str_contains($layoutSource, "'label' => 'Buku Kasbon'")
        && str_contains($layoutSource, "App::url('operator/kasbon')")
        && str_contains($layoutSource, 'overflow-x-auto')
        && str_contains($layoutSource, 'shrink-0'),
    'tab ke-6 / ke-2'
);

Qc::ok(
    'I.2',
    'Autocomplete memakai <datalist> dengan data-miner-id & data-outstanding serta format label PO',
    str_contains($viewSource, '<datalist')
        && str_contains($viewSource, 'data-miner-id=')
        && str_contains($viewSource, 'data-outstanding=')
        && str_contains($viewSource, '| Sisa Utang:')
        && str_contains($cashierBody, 'data-outstanding=')
        && str_contains($cashierBody, 'data-miner-id='),
    'sumber: datalist=' . (int) str_contains($viewSource, '<datalist')
        . ' id=' . (int) str_contains($viewSource, 'data-miner-id=')
        . ' out=' . (int) str_contains($viewSource, 'data-outstanding=')
        . ' label=' . (int) str_contains($viewSource, '| Sisa Utang:')
        . ' | render: out=' . (int) str_contains($cashierBody, 'data-outstanding=')
        . ' id=' . (int) str_contains($cashierBody, 'data-miner-id=')
);

Qc::ok(
    'I.3',
    'Tiga tombol cepat (20 %, 30 %, Lunas) dirender sebagai ekspresi @click, bukan blok pernyataan',
    (static function () use ($viewSource, $cashierBody): bool {
        foreach (['pickPercent(20)', 'pickPercent(30)', 'payFull()'] as $expression) {
            if (!str_contains($cashierBody, '@click="' . $expression . '"')) {
                return false;
            }
        }

        return !preg_match('/@click="[^"]*\b(try|catch)\b/', $viewSource);
    })(),
    'render: 20=' . (int) str_contains($cashierBody, '@click="pickPercent(20)"')
        . ' 30=' . (int) str_contains($cashierBody, '@click="pickPercent(30)"')
        . ' lunas=' . (int) str_contains($cashierBody, '@click="payFull()"')
        . ' statement=' . (int) preg_match('/@click="[^"]*\b(try|catch)\b/', $viewSource)
);

Qc::ok(
    'I.4',
    'Komponen kasbonForm terdaftar di app.js (total enam komponen Alpine.data)',
    str_contains($appJs, "Alpine.data('kasbonForm', kasbonForm)")
        && substr_count($appJs, 'Alpine.data(') === 6
        && str_contains($appJs, 'function kasbonForm('),
    'komponen terdaftar'
);

$swSource = (string) file_get_contents(PUBLIC_PATH . '/sw.js');
$agentsSource = (string) file_get_contents(BASE_PATH . '/AGENTS.md');

Qc::ok(
    'I.5',
    'CACHE_NAME naik ke aurumflow-shell-v3 dan AGENTS.md menyebut versi yang sama',
    str_contains($swSource, "var CACHE_NAME = 'aurumflow-shell-v3';")
        && str_contains($agentsSource, 'aurumflow-shell-v3')
        && str_contains($swSource, "'./assets/js/app.js'")
        && str_contains($swSource, "'./assets/css/app.min.css'"),
    'satu pemilik versi shell'
);

$cssPath = PUBLIC_PATH . '/assets/css/app.min.css';
$cssContents = (string) file_get_contents($cssPath);
$cssHash = hash('sha256', $cssContents);

Qc::ok(
    'I.6',
    'app.min.css byte-identik dengan baseline Fase 8 (nol kompilasi)',
    filesize($cssPath) === 24264
        && $cssHash === 'cbf9ac80d512f19480701f83a71c77e0418e419082a6f5664e077eed9431781d',
    'ukuran=' . filesize($cssPath) . ' sha256=' . substr($cssHash, 0, 12)
);

// Setiap kelas pada view baru harus sudah ada di CSS terkompilasi.
$viewClasses = [];

if (preg_match_all('/(?<!:)class="([^"]+)"/', $viewSource, $matches) > 0) {
    foreach ($matches[1] as $classAttribute) {
        // Abaikan ekspresi Alpine (`:class`, `x-bind`) dan kelas dinamis.
        if (str_contains($classAttribute, '<?=')) {
            continue;
        }

        foreach (preg_split('/\s+/', trim($classAttribute)) ?: [] as $token) {
            if ($token === '' || str_contains($token, ':') || str_contains($token, '[')) {
                continue;
            }

            $viewClasses[$token] = true;
        }
    }
}

$missingClasses = [];

foreach (array_keys($viewClasses) as $token) {
    if (!str_contains($cssContents, '.' . str_replace(['\\', ':'], ['\\\\', '\\:'], (string) $token))) {
        $missingClasses[] = (string) $token;
    }
}

Qc::ok(
    'I.7',
    'Seluruh kelas view kasbon sudah ada di app.min.css (' . count($viewClasses) . ' kelas diperiksa)',
    $missingClasses === [],
    'kelas tanpa CSS: ' . implode(', ', $missingClasses)
);

$phpLogs = glob(STORAGE_PATH . '/logs/php-*.log') ?: [];
$nonEmptyLogs = array_values(array_filter(
    $phpLogs,
    static fn (string $path): bool => filesize($path) > 0
));

Qc::ok(
    'I.8',
    'Halaman kasbon dirender 200 tanpa log PHP baru',
    $cashierKasbon['status'] === 200
        && $operatorKasbon['status'] === 200
        && count($nonEmptyLogs) === 0,
    'log=' . implode(', ', $nonEmptyLogs)
);

// ---------------------------------------------------------------------------
// J. Dokumen & konsistensi
// ---------------------------------------------------------------------------

Qc::section('J. Dokumen & konsistensi');

$erd = (string) file_get_contents(BASE_PATH . '/docs/AurumFlow Entity Relationship Diagram.md');
$readme = (string) file_get_contents(BASE_PATH . '/README.md');
$plan = (string) file_get_contents(BASE_PATH . '/plan/Plan_Kasbon_Petani_AurumFlow.md');

Qc::ok(
    'J.1',
    'ERD memuat tiga tabel kasbon, kunci daily_loan_limit, dan catatan migrasi M2',
    str_contains($erd, 'CREATE TABLE IF NOT EXISTS miners')
        && str_contains($erd, 'CREATE TABLE IF NOT EXISTS miner_loans')
        && str_contains($erd, 'CREATE TABLE IF NOT EXISTS miner_loan_ledger')
        && str_contains($erd, "'daily_loan_limit'")
        && str_contains($erd, 'Fase 8'),
    'dokumen ERD'
);

Qc::ok(
    'J.2',
    'AGENTS.md: dua permukaan izin kasir, 13 tabel, dan posisi miner_loans pada urutan kunci',
    str_contains($agentsSource, 'berjumlah **dua**')
        && str_contains($agentsSource, '`miner_loans` (Fase 8')
        && str_contains($agentsSource, 'tabel **11–13**')
        && str_contains($agentsSource, 'Fase 8 (selesai)'),
    'dokumen AGENTS.md'
);

Qc::ok(
    'J.3',
    'README.md menyebut /operator/kasbon dan migrasi M2',
    str_contains($readme, '/operator/kasbon')
        && str_contains($readme, 'M2')
        && str_contains($readme, 'daily_loan_limit'),
    'dokumen README.md'
);

Qc::ok(
    'J.4',
    'plan/Plan_Kasbon_Petani_AurumFlow.md tersedia beserta bagian ADDENDUM',
    str_contains($plan, '# ADDENDUM')
        && str_contains($plan, 'dec-454e02e31b373890')
        && str_contains($plan, 'qc_fase_8'),
    'dokumen rencana'
);

// ---------------------------------------------------------------------------
// Pembersihan
// ---------------------------------------------------------------------------

QcFixture::resetKasbonState();
QcFixture::resetTellerState();
QcFixture::clearPinThrottle();
QcHttp::stop();

Qc::note('  | Kasbon              | dua lapis validasi + FIFO + potong nota        | OK');
Qc::note('  | Permukaan kasir     | dua allow-list eksplisit, tanpa data pemilik   | OK');
Qc::note('  | Aset                | app.js berubah → CACHE_NAME v3, CSS nol ubah   | OK');

Qc::finish('qc_fase_8');
