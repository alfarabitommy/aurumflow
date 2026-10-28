<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Protokol QC Manual 4 — Alokasi Modal & Tata Kelola Likuiditas (Fase 4)
|--------------------------------------------------------------------------
| Implementasi otomatis dari "Protokol QC Manual 4" pada dokumen
| docs/AurumFlow Roadmap and QC Documents.md (FASE 4).
|
| Dokumen Roadmap meminta: daftarkan EMPAT dompet uji (Pengelola Rp 30 juta,
| Mitra Prioritas Rp 16 juta / kuota 20 %, Mitra Reguler A Rp 20 juta, Mitra
| Reguler B Rp 14 juta), lalu uji serapan modal untuk belanja Rp 35.157.500
| (data QC 2) dan distribusi pool mitra Rp 437.300 (data QC 3).
|
| Cakupan:
|   P4.x  Protokol manual 4 (angka persis seperti dokumen Roadmap)
|   A.    AllocationEngine murni (waterfall, clamp, sisa terbesar, luapan)
|   B.    Parsing kuota & nominal (satu pemilik aturan dengan Fase 3)
|   C.    Atomisitas tiga jalur uang (setoran, Pindah Kas, bayar penarikan)
|   D.    Guard lewat HTTP: peran, CSRF, `_once`, saldo, jatuh tempo H-7
|   E.    Peladen tidak mempercayai nominal dari klien
|   F.    Integrasi settlement: distribusi pool & saldo dompet
|   G.    Idempotensi & pengulangan suite
|   H.    Pemindaian statis, navigasi, dan kontrak form Layar E
|   I.    Integritas skema (tanpa perubahan DDL di Fase 4)
|   J.    Aturan tanggal H-7 hari kerja
|
| PERINGATAN: suite ini MENULIS ke database yang ditunjuk `.env` (capital_wallets,
| profit_distributions, withdrawal_requests, cash_registers Brankas Utama, dan
| berkas nota sementara di public/uploads/receipts/). Jangan arahkan ke database
| produksi. Konvensi exit code: 0 = lulus, 1 = gagal.
*/

require __DIR__ . '/bootstrap.php';

use AurumFlow\Config\Constants;
use AurumFlow\Config\Database;
use AurumFlow\Config\Env;
use AurumFlow\Helpers\Formatter;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\CapitalWallet;
use AurumFlow\Models\CashMutation;
use AurumFlow\Models\CashRegister;
use AurumFlow\Models\OperationalExpense;
use AurumFlow\Models\ProfitDistribution;
use AurumFlow\Models\SystemSetting;
use AurumFlow\Models\User;
use AurumFlow\Models\WithdrawalRequest;
use AurumFlow\Services\AllocationEngine;
use AurumFlow\Services\CapitalService;
use AurumFlow\Services\SettlementService;
use AurumFlow\Services\TellerService;

Qc::suite('Protokol QC Manual 4 — Alokasi Modal & Likuiditas (qc_fase_4.php)');

Qc::note('Sumber: docs/AurumFlow Roadmap and QC Documents.md — FASE 4');
Qc::note('Terminologi: family = Mitra Prioritas, external = Mitra Reguler, operator = Pengelola.');
Qc::note('PERINGATAN: suite ini menulis ke DB pada .env dan menggerakkan kas Brankas Utama.');

// ---------------------------------------------------------------------------
// Helper lokal
// ---------------------------------------------------------------------------

/**
 * Pemindai statis untuk daftar berkas eksplisit (pola yang sama dengan
 * `qc3_scan()`): `qc_scan_lines()` harness memindai seluruh app/ + public/
 * sekaligus dan predikatnya hanya menerima isi baris, sehingga tidak dapat
 * dibatasi per berkas.
 *
 * @param list<string> $paths
 * @return list<string> daftar pelanggaran (file:baris)
 */
function qc4_scan(array $paths, callable $predicate): array
{
    $offenders = [];

    foreach ($paths as $path) {
        $contents = file_get_contents($path);

        if ($contents === false) {
            $offenders[] = str_replace(BASE_PATH . '/', '', $path) . ' tidak terbaca';

            continue;
        }

        $lines = preg_split('/\R/', $contents);

        if ($lines === false) {
            continue;
        }

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if ($trimmed === ''
                || str_starts_with($trimmed, '*')
                || str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '/*')
                || str_starts_with($trimmed, '#')
            ) {
                continue;
            }

            if ($predicate($line) === true) {
                $offenders[] = str_replace(BASE_PATH . '/', '', $path) . ':' . ($index + 1) . '  ' . $trimmed;
            }
        }
    }

    return $offenders;
}

/** Nilai uang kanonik -> sen (integer). */
function qc4_cents(mixed $value): int
{
    $text = trim((string) $value);

    if (preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', $text, $matches) !== 1) {
        return 0;
    }

    $cents = (int) $matches[2] * 100 + (int) str_pad((string) ($matches[3] ?? ''), 2, '0', STR_PAD_RIGHT);

    return ($matches[1] ?? '') === '-' ? -$cents : $cents;
}

/** Nilai uang kanonik (dua desimal). */
function qc4_money(mixed $value): string
{
    $cents = qc4_cents($value);
    $sign = $cents < 0 ? '-' : '';
    $absolute = abs($cents);

    return $sign . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
}

/** Baris dompet beserta saldo & kuota terkininya. */
function qc4_wallet(string $walletId): ?array
{
    $row = CapitalWallet::findById($walletId);

    if ($row === null) {
        return null;
    }

    return [
        'tier'    => (string) $row['tier'],
        'capital' => qc4_money($row['balance_capital']),
        'profit'  => qc4_money($row['balance_profit']),
        'quota'   => qc4_money($row['locked_quota_pct']),
        'status'  => (string) $row['withdrawal_status'],
    ];
}

/** Satu kolom dompet tanpa risiko "array offset on null". */
function qc4_field(string $walletId, string $field): string
{
    $wallet = qc4_wallet($walletId);

    return $wallet === null ? '' : (string) ($wallet[$field] ?? '');
}

/** Chunk PNG (tanpa ekstensi GD — tidak tersedia di semua host). */
function qc4_png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

function qc4_png(int $width, int $height): string
{
    $raw = '';

    for ($y = 0; $y < $height; $y++) {
        $raw .= "\x00";

        for ($x = 0; $x < $width; $x++) {
            $raw .= chr(($x * 7 + $y * 5) % 256) . chr(150) . chr(80);
        }
    }

    return "\x89PNG\r\n\x1a\n"
        . qc4_png_chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        . qc4_png_chunk('IDAT', gzcompress($raw, 6))
        . qc4_png_chunk('IEND', '');
}

/** Tulis berkas uji di tests/.tmp (di-gitignore). */
function qc4_temp_file(string $name, string $contents): string
{
    $directory = TEST_PATH . '/.tmp';

    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $path = $directory . '/' . $name;
    file_put_contents($path, $contents);

    return $path;
}

/**
 * Keadaan lengkap yang dipantau grup C & G (kas, dompet, distribusi, berkas).
 *
 * @return array<string, mixed>
 */
function qc4_state(string $tellerId, string $brankasId, array $wallets): array
{
    $balances = [];

    foreach ($wallets as $key => $walletId) {
        $balances[$key] = qc4_wallet($walletId);
    }

    return [
        'teller'        => QcFixture::registerBalance($tellerId),
        'brankas'       => QcFixture::registerBalance($brankasId),
        'wallets'       => $balances,
        'distributions' => (int) (Database::fetchOne(
            'SELECT COUNT(*) AS `total` FROM `profit_distributions`'
        )['total'] ?? 0),
        'withdrawals'   => (int) (Database::fetchOne(
            'SELECT COUNT(*) AS `total` FROM `withdrawal_requests`'
        )['total'] ?? 0),
        'mutations'     => CashMutation::countFor($brankasId, CashMutation::TYPE_IN)
            + CashMutation::countFor($brankasId, CashMutation::TYPE_OUT),
        'receipts'      => count(QcFixture::receiptFiles()),
    ];
}

// ---------------------------------------------------------------------------
// Persiapan: keadaan akhir QC 2 + empat dompet Protokol QC Manual 4
// ---------------------------------------------------------------------------

Qc::section('Persiapan: keadaan QC 2 + empat dompet QC 4');

$fixture = QcFixture::resetSettlementState();
$wallets = QcFixture::seedQcWallets();

$tellerId = (string) $fixture['teller_id'];
$brankasId = (string) $fixture['brankas_id'];

Qc::equals(
    'P4.0a',
    'Keadaan awal QC 2 terbentuk: 2 nota holding, 18,050 gr, modal Rp 35.157.500',
    ['2', '18.050', '35157500.00'],
    [(string) $fixture['holding_count'], $fixture['holding_grams'], $fixture['holding_cost']]
);
Qc::equals(
    'P4.0b',
    'Kas meja Rp 14.842.500, brankas Rp 0, defisit berjalan Rp 0',
    ['14842500.00', '0.00', '0.00'],
    [
        $fixture['teller_balance'],
        $fixture['brankas_balance'],
        qc4_money(SystemSetting::get(SettlementService::SETTING_ACTIVE_DEFICIT, '0.00')),
    ]
);
$walletRows = static fn (array $ids): array => array_map(
    static fn (string $id): array => [
        qc4_field($id, 'tier'),
        qc4_field($id, 'capital'),
        qc4_field($id, 'profit'),
        qc4_field($id, 'quota'),
    ],
    $ids
);

Qc::equals(
    'P4.0c',
    'Empat dompet QC 4 siap: Pengelola 30 jt, Prioritas 16 jt/20 %, Reguler A 20 jt, Reguler B 14 jt',
    [
        ['operator', '30000000.00', '0.00', '0.00'],
        ['family', '16000000.00', '0.00', '20.00'],
        ['external', '20000000.00', '0.00', '0.00'],
        ['external', '14000000.00', '0.00', '0.00'],
    ],
    $walletRows([$wallets['operator'], $wallets['priority'], $wallets['reguler_a'], $wallets['reguler_b']])
);

// ---------------------------------------------------------------------------
// A. AllocationEngine murni
// ---------------------------------------------------------------------------

Qc::section('A. AllocationEngine: waterfall, clamp, sisa terbesar, aritmetika aman');

$qcWallets = [
    ['id' => 'op', 'tier' => CapitalWallet::TIER_OPERATOR, 'available_cents' => 3000000000, 'quota_hundredths' => 0],
    ['id' => 'fam', 'tier' => CapitalWallet::TIER_FAMILY, 'available_cents' => 1600000000, 'quota_hundredths' => 2000],
    ['id' => 'exa', 'tier' => CapitalWallet::TIER_EXTERNAL, 'available_cents' => 2000000000, 'quota_hundredths' => 0],
    ['id' => 'exb', 'tier' => CapitalWallet::TIER_EXTERNAL, 'available_cents' => 1400000000, 'quota_hundredths' => 0],
];

$planA = AllocationEngine::plan(3515750000, $qcWallets, 5000);
$utilizedA = [];

foreach ($planA['rows'] as $row) {
    $utilizedA[$row['id']] = $row['utilized_cents'];
}

Qc::equals(
    'A.1',
    'Waterfall QC 4: Pengelola 50 % = Rp 17.578.750',
    [1757875000, 1757875000],
    [$planA['operator_quota_cents'], $utilizedA['op']]
);
Qc::equals(
    'A.2',
    'Waterfall QC 4: Mitra Prioritas (kuota 20 %) = Rp 7.031.500',
    [703150000, 703150000],
    [$planA['stages']['family'], $utilizedA['fam']]
);
Qc::equals(
    'A.3',
    'Waterfall QC 4: sisa 30 % dibagi pro-rata saldo — A Rp 6.204.264,71 dan B Rp 4.342.985,29',
    [620426471, 434298529, 1054725000],
    [$utilizedA['exa'], $utilizedA['exb'], $planA['stages']['external']]
);
Qc::equals(
    'A.4',
    'Invariant serapan: Σ capital_utilized = modal belanja putaran, sisa tak terserap Rp 0',
    [3515750000, 0],
    [$planA['utilized_cents'], $planA['uncovered_cents']]
);
Qc::equals(
    'A.5',
    'Basis pool mitra = Rp 17.578.750 (Pengelola tidak dihitung)',
    [1757875000],
    [$planA['mitra_base_cents']]
);

$distributionA = AllocationEngine::distribute(43730000, [
    'fam' => $utilizedA['fam'],
    'exa' => $utilizedA['exa'],
    'exb' => $utilizedA['exb'],
]);

Qc::equals(
    'A.6',
    'Distribusi pool: Prioritas Rp 174.920, Reguler A Rp 154.341,18, Reguler B Rp 108.038,82',
    [17492000, 15434118, 10803882],
    [$distributionA['shares']['fam'], $distributionA['shares']['exa'], $distributionA['shares']['exb']]
);
Qc::equals(
    'A.7',
    'Invariant pool: Σ profit_earned = investor_pool_20 presisi tanpa selisih sen',
    [43730000, '437300.00'],
    [(int) $distributionA['total'], TellerService::centsToDecimal((int) $distributionA['total'])]
);
Qc::equals(
    'A.8',
    'Tampilan Rupiah pembulatan half-up (Rp 108.038,82 -> Rp 108.039; Rp 154.341,18 -> Rp 154.341)',
    ['Rp 108.039', 'Rp 154.341'],
    [
        Formatter::rupiah(TellerService::centsToDecimal($distributionA['shares']['exb'])),
        Formatter::rupiah(TellerService::centsToDecimal($distributionA['shares']['exa'])),
    ]
);

// Sisa terbesar: 2 sen untuk tiga dompet berbobot sama -> 1, 1, 0 (id menaik).
$tiePlan = AllocationEngine::distribute(2, ['a' => 1, 'b' => 1, 'c' => 1]);

Qc::equals(
    'A.9',
    'Sisa terbesar memutus seri dengan `id` menaik (2 sen -> 1/1/0)',
    [1, 1, 0, 2],
    [$tiePlan['shares']['a'], $tiePlan['shares']['b'], $tiePlan['shares']['c'], $tiePlan['total']]
);

// Clamp: kuota prioritas besar, saldo kecil, kebutuhan lebih besar dari saldo.
$clampPlan = AllocationEngine::plan(1000000, [
    ['id' => 'op', 'tier' => CapitalWallet::TIER_OPERATOR, 'available_cents' => 0],
    ['id' => 'fam', 'tier' => CapitalWallet::TIER_FAMILY, 'available_cents' => 300000, 'quota_hundredths' => 10000],
    ['id' => 'exa', 'tier' => CapitalWallet::TIER_EXTERNAL, 'available_cents' => 200000],
], 5000);

Qc::equals(
    'A.10',
    'Serapan dibatasi saldo; sisa kebutuhan dilaporkan sebagai uncovered',
    [0, 300000, 200000, 500000, 1000000],
    [
        $clampPlan['stages']['operator'],
        $clampPlan['stages']['family'],
        $clampPlan['stages']['external'],
        $clampPlan['uncovered_cents'],
        $clampPlan['need_cents'],
    ]
);

// Kuota terkunci hanya berlaku untuk Mitra Prioritas.
$quotaLeakPlan = AllocationEngine::plan(1000000, [
    ['id' => 'exa', 'tier' => CapitalWallet::TIER_EXTERNAL, 'available_cents' => 5000000, 'quota_hundredths' => 9000],
], 0);

Qc::equals(
    'A.11',
    'Kuota pada dompet Reguler diabaikan (pro-rata saldo atas sisa)',
    [1000000, 0],
    [$quotaLeakPlan['stages']['external'], $quotaLeakPlan['uncovered_cents']]
);

// Nilai ekstrem: hasil kali melampaui integer 64-bit pada aritmetika naif.
$hugePlan = AllocationEngine::plan(100000000000, [
    ['id' => 'exa', 'tier' => CapitalWallet::TIER_EXTERNAL, 'available_cents' => 100000000000],
    ['id' => 'exb', 'tier' => CapitalWallet::TIER_EXTERNAL, 'available_cents' => 100000000000],
], 0);

Qc::equals(
    'A.12',
    'Pembagian besar (Rp 1 miliar x Rp 1 miliar) tetap eksak tanpa luapan',
    [50000000000, 50000000000, 100000000000],
    [$hugePlan['rows'][0]['utilized_cents'], $hugePlan['rows'][1]['utilized_cents'], $hugePlan['stages']['external']]
);

// Determinis­me: urutan input acak tidak mengubah hasil.
$shuffled = [$qcWallets[3], $qcWallets[1], $qcWallets[0], $qcWallets[2]];
$planShuffled = AllocationEngine::plan(3515750000, $shuffled, 5000);
$shuffledUtilized = [];

foreach ($planShuffled['rows'] as $row) {
    $shuffledUtilized[$row['id']] = $row['utilized_cents'];
}

ksort($utilizedA);
ksort($shuffledUtilized);

Qc::equals(
    'A.13',
    'Hasil tidak bergantung urutan input dompet (deterministik)',
    $utilizedA,
    $shuffledUtilized
);

// Kebutuhan nol & tanpa dompet.
$emptyPlan = AllocationEngine::plan(0, $qcWallets, 5000);
$noWalletPlan = AllocationEngine::plan(5000000, [], 5000);

Qc::equals(
    'A.14',
    'Kebutuhan nol tidak menyerap apa pun; tanpa dompet seluruh kebutuhan jadi uncovered',
    [0, 0, 5000000],
    [$emptyPlan['utilized_cents'], $emptyPlan['uncovered_cents'], $noWalletPlan['uncovered_cents']]
);

Qc::throws(
    'A.15',
    'Kuota di luar rentang 0–100 % ditolak',
    static fn () => AllocationEngine::plan(1000, [], 10001),
    InvalidArgumentException::class
);
Qc::throws(
    'A.16',
    'Tier dompet tak dikenal ditolak (bukan diam-diam dianggap Reguler)',
    static fn () => AllocationEngine::plan(1000, [['id' => 'x', 'tier' => 'kerabat', 'available_cents' => 10]], 0),
    InvalidArgumentException::class
);
Qc::throws(
    'A.17',
    'Nilai modal negatif pada basis data ditolak',
    static fn () => AllocationEngine::plan(1000, [['id' => 'x', 'tier' => 'external', 'available_cents' => -1]], 0),
    InvalidArgumentException::class
);

// Mode alokasi yang belum didukung harus ditolak, bukan diabaikan.
SystemSetting::set(AllocationEngine::SETTING_MODE, 'prorata');

Qc::throws(
    'A.18',
    'Mode alokasi `prorata` ditolak dengan pesan jelas, belum diimplementasikan',
    static fn () => AllocationEngine::assertConfiguredMode(),
    RuntimeException::class
);

SystemSetting::set(AllocationEngine::SETTING_MODE, 'waterfall');
$modeOk = true;

try {
    AllocationEngine::assertConfiguredMode();
} catch (Throwable) {
    $modeOk = false;
}

Qc::ok('A.19', 'Mode `waterfall` diterima kembali setelah setting dikembalikan', $modeOk);

// ---------------------------------------------------------------------------
// B. Parsing kuota & nominal (satu pemilik aturan)
// ---------------------------------------------------------------------------

Qc::section('B. Parsing kuota serapan & nominal jalur uang');

$quotaCases = [
    '0'      => 0,
    '0.00'   => 0,
    '20'     => 2000,
    '20.00'  => 2000,
    '20.5'   => 2050,
    '100'    => 10000,
    '100.00' => 10000,
];

$quotaOk = true;
$quotaDetail = '';

foreach ($quotaCases as $input => $expected) {
    // Kunci array numerik menjadi `int` di PHP, sedangkan parser menerima string.
    $actual = AllocationEngine::quotaHundredths((string) $input);

    if ($actual !== $expected) {
        $quotaOk = false;
        $quotaDetail .= $input . ' => ' . $actual . ' (harusnya ' . $expected . ") \n";
    }
}

Qc::ok('B.1', 'Parser kuota menerima 0–100 dengan maksimum 2 desimal', $quotaOk, $quotaDetail);

Qc::equals(
    'B.2',
    'Kuota bolak-balik (perseratus <-> desimal kanonik)',
    ['20.00', '0.00', '100.00'],
    [
        AllocationEngine::hundredthsToDecimal(2000),
        AllocationEngine::hundredthsToDecimal(0),
        AllocationEngine::hundredthsToDecimal(10000),
    ]
);

Qc::throws(
    'B.3',
    'Kuota melebihi 100 % / format rusak ditolak',
    static fn () => AllocationEngine::quotaHundredths('120'),
    InvalidArgumentException::class
);
Qc::throws(
    'B.4',
    'Kuota dengan lebih dari 2 desimal ditolak',
    static fn () => AllocationEngine::quotaHundredths('20.123'),
    InvalidArgumentException::class
);

// Parser nominal Fase 4 WAJIB memakai parser yang sama dengan layar rekonsiliasi.
$moneyCases = [
    '10.000.000' => 1000000000,
    '1.234,50'   => 123450,
    '5.000'      => 500000,
    '0'          => 0,
];

$moneyOk = true;
$moneyDetail = '';

foreach ($moneyCases as $input => $expected) {
    $fromSettlement = SettlementService::parseMoneyInput((string) $input, 'Uji');
    $viaTeller = TellerService::moneyToCents(TellerService::centsToDecimal($expected));

    if ($fromSettlement !== $expected || $viaTeller !== $expected) {
        $moneyOk = false;
        $moneyDetail .= $input . ' => ' . $fromSettlement . ' / ' . $viaTeller . ' (harusnya ' . $expected . ")\n";
    }
}

Qc::ok('B.5', 'Parser nominal satu pemilik: SettlementService = CapitalService = TellerService', $moneyOk, $moneyDetail);

Qc::throws(
    'B.6',
    'Nominal jalur uang harus lebih besar dari nol (setoran Rp 0 ditolak)',
    static fn () => CapitalService::topUp(['wallet_id' => 'apa-saja', 'amount' => '0', 'note' => '']),
    InvalidArgumentException::class
);

// ---------------------------------------------------------------------------
// C. Atomisitas tiga jalur uang
// ---------------------------------------------------------------------------

Qc::section('C. Atomisitas: setoran modal, Pindah Kas, bayar penarikan');

$beforeC = qc4_state($tellerId, $brankasId, $wallets);

// (1) Setoran modal berhasil: dompet + brankas + satu mutasi `in`.
$topUp = CapitalService::topUp([
    'wallet_id' => $wallets['priority'],
    'amount'    => '10.000.000',
    'note'      => 'QC top-up',
]);

$afterTopUp = qc4_state($tellerId, $brankasId, $wallets);

Qc::equals(
    'C.1',
    'Setoran modal menambah saldo dompet & Brankas dalam satu transaksi',
    ['26000000.00', '10000000.00', $beforeC['mutations'] + 1],
    [
        qc4_wallet($wallets['priority'])['capital'] ?? '',
        QcFixture::registerBalance($brankasId),
        $afterTopUp['mutations'],
    ]
);
Qc::equals(
    'C.2',
    'Mutasi setoran bertipe `in`, menyebut nama mitra, dan memuat jejak wallet_id',
    ['in', true, true],
    [
        (string) (Database::fetchOne(
            'SELECT `mutation_type` FROM `cash_mutations` WHERE `id` = :id LIMIT 1',
            ['id' => (string) $topUp['mutation_id']]
        )['mutation_type'] ?? ''),
        str_contains(
            (string) (Database::fetchOne(
                'SELECT `source_destination` FROM `cash_mutations` WHERE `id` = :id LIMIT 1',
                ['id' => (string) $topUp['mutation_id']]
            )['source_destination'] ?? ''),
            'Setoran modal'
        ),
        str_contains(
            (string) (Database::fetchOne(
                'SELECT `notes` FROM `cash_mutations` WHERE `id` = :id LIMIT 1',
                ['id' => (string) $topUp['mutation_id']]
            )['notes'] ?? ''),
            'wallet_id=' . $wallets['priority']
        ),
    ]
);

// (2) Pindah Kas: dua pos + dua mutasi dalam satu transaksi.
$transfer = CapitalService::transferToTeller(['amount' => '4.000.000', 'note' => 'QC pindah kas']);
$afterTransfer = qc4_state($tellerId, $brankasId, $wallets);

Qc::equals(
    'C.3',
    'Pindah Kas memindahkan Rp 4.000.000 dari Brankas ke Meja Bakar',
    // Mutasi pos brankas bertambah 2 sejak awal grup: `in` setoran + `out` pindah kas.
    ['6000000.00', '18842500.00', $beforeC['mutations'] + 2],
    [QcFixture::registerBalance($brankasId), QcFixture::registerBalance($tellerId), $afterTransfer['mutations']]
);
Qc::equals(
    'C.4',
    'Pindah Kas menulis dua mutasi (out brankas + in meja) dengan satu transfer id',
    [1, 1],
    [
        (int) (Database::fetchOne(
            'SELECT COUNT(*) AS `total` FROM `cash_mutations` WHERE `notes` LIKE :pattern AND `mutation_type` = :type',
            ['pattern' => '%transfer=' . $transfer['transfer_id'] . '%', 'type' => CashMutation::TYPE_OUT]
        )['total'] ?? 0),
        (int) (Database::fetchOne(
            'SELECT COUNT(*) AS `total` FROM `cash_mutations` WHERE `notes` LIKE :pattern AND `mutation_type` = :type',
            ['pattern' => '%transfer=' . $transfer['transfer_id'] . '%', 'type' => CashMutation::TYPE_IN]
        )['total'] ?? 0),
    ]
);

// (3) Gagal di tengah: pos kas brankas sengaja dihilangkan -> nol perubahan.
$beforeFail = qc4_state($tellerId, $brankasId, $wallets);
Database::query('UPDATE `cash_registers` SET `register_name` = :temp WHERE `id` = :id', [
    'temp' => 'Brankas Utama (QC)',
    'id'   => $brankasId,
]);

$failCaught = false;

try {
    CapitalService::topUp(['wallet_id' => $wallets['reguler_b'], 'amount' => '1.000.000', 'note' => '']);
} catch (Throwable) {
    $failCaught = true;
}

$afterFail = qc4_state($tellerId, $brankasId, $wallets);

Database::query('UPDATE `cash_registers` SET `register_name` = :name WHERE `id` = :id', [
    'name' => CashRegister::BRANKAS_NAME,
    'id'   => $brankasId,
]);

Qc::ok(
    'C.5',
    'Setoran gagal di tengah transaksi: nol tulisan (dompet & kas utuh)',
    $failCaught && $afterFail === $beforeFail,
    'sebelum=' . json_encode($beforeFail) . "\nsesudah=" . json_encode($afterFail)
);

$beforeTransferFail = qc4_state($tellerId, $brankasId, $wallets);

Qc::throws(
    'C.6',
    'Pindah Kas melebihi saldo brankas ditolak tanpa tulisan',
    static fn () => CapitalService::transferToTeller(['amount' => '99.000.000', 'note' => '']),
    RuntimeException::class
);
Qc::equals('C.7', 'Saldo tidak berubah setelah Pindah Kas ditolak', $beforeTransferFail, qc4_state($tellerId, $brankasId, $wallets));

// ---------------------------------------------------------------------------
// D. Guard lewat HTTP: peran, CSRF, `_once`, saldo, jatuh tempo
// ---------------------------------------------------------------------------

Qc::section('D. Guard lewat HTTP (peran, CSRF, `_once`, saldo, jatuh tempo)');

QcHttp::start();

// Identitas operator dibaca dari `.env` (BUKAN lewat `ensureInstalled()`, yang
// menerapkan ulang skema dan akan menghapus dompet yang baru disiapkan).
$operatorPhone = (string) (Env::get('OPERATOR_PHONE') ?? '081200000001');
$operatorPassword = (string) (Env::get('OPERATOR_PASSWORD') ?? 'Aurum2026!');
$operatorJar = QcHttp::cookieJar('capital-operator');
$loginForm = QcHttp::request('GET', '/login', ['cookie_jar' => $operatorJar, 'fresh' => true]);

$login = QcHttp::request('POST', '/login', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD => (string) QcHttp::csrfFrom($loginForm['body']),
        'phone_number'       => $operatorPhone,
        'password'           => $operatorPassword,
    ],
]);

Qc::ok(
    'D.1',
    'Operator berhasil login (302 -> /dashboard)',
    $login['status'] === 302 && $login['location'] === '/dashboard',
    'status=' . $login['status'] . ' location=' . (string) $login['location']
);

$capitalPage = QcHttp::request('GET', '/operator/capital', ['cookie_jar' => $operatorJar]);

Qc::ok(
    'D.2',
    'Layar Kelola Modal terbuka untuk operator (200)',
    $capitalPage['status'] === 200,
    'status=' . $capitalPage['status']
);

$csrf = (string) QcHttp::csrfFrom($capitalPage['body']);
$once = (string) QcHttp::fieldFrom($capitalPage['body'], Security::FORM_FIELD);

Qc::ok(
    'D.3',
    'Layar memuat CSRF + token sekali-pakai `_once`',
    $csrf !== '' && $once !== '',
    'csrf=' . ($csrf !== '' ? 'ada' : 'kosong') . ' once=' . ($once !== '' ? 'ada' : 'kosong')
);

// Tanpa CSRF -> 419 (front controller menolak lebih dulu).
$noCsrf = QcHttp::request('POST', '/operator/capital/topup', [
    'cookie_jar' => $operatorJar,
    'data'       => ['topup_wallet_id' => $wallets['reguler_a'], 'topup_amount' => '1.000.000'],
]);

Qc::equals('D.4', 'POST tanpa token CSRF ditolak 419', 419, $noCsrf['status']);

// Tanpa `_once` -> 419 (pertahanan lapis kedua pada jalur kas).
$noOnce = QcHttp::request('POST', '/operator/capital/topup', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD  => $csrf,
        'topup_wallet_id'     => $wallets['reguler_a'],
        'topup_amount'        => '1.000.000',
    ],
]);

Qc::equals('D.5', 'POST jalur kas tanpa `_once` ditolak 419', 419, $noOnce['status']);

// Guard peran: investor tidak boleh membuka maupun memutasi.
$investorPhone = '089900000901';
$existingInvestor = User::findByPhoneNumber($investorPhone, false);

if ($existingInvestor === null) {
    User::create('QC Investor Modal', $investorPhone, 'Investor2026!', Constants::ROLE_INVESTOR);
} else {
    Database::update(
        'users',
        ['role' => Constants::ROLE_INVESTOR, 'is_active' => 1, 'password_hash' => Security::hashPassword('Investor2026!')],
        '`id` = :id',
        ['id' => (string) $existingInvestor['id']]
    );
}

$investorJar = QcHttp::cookieJar('capital-investor');
$investorForm = QcHttp::request('GET', '/login', ['cookie_jar' => $investorJar, 'fresh' => true]);
$investorLogin = QcHttp::request('POST', '/login', [
    'cookie_jar' => $investorJar,
    'data'       => [
        Security::CSRF_FIELD => (string) QcHttp::csrfFrom($investorForm['body']),
        'phone_number'       => $investorPhone,
        'password'           => 'Investor2026!',
    ],
]);
$investorPage = QcHttp::request('GET', '/operator/capital', ['cookie_jar' => $investorJar]);

Qc::ok(
    'D.6',
    'Investor diarahkan ke /investor/dashboard saat membuka layar modal',
    $investorLogin['status'] === 302 && $investorPage['status'] === 302 && $investorPage['location'] === '/investor/dashboard',
    'login=' . $investorLogin['status'] . ' halaman=' . $investorPage['status'] . ' location=' . (string) $investorPage['location']
);

// Sejak Fase 5 "rumah" mitra adalah /investor/dashboard (Fase 5 A-11), jadi
// token CSRF untuk uji penyusupan dibaca dari dasbor mitra.
$investorDashboard = QcHttp::request('GET', '/investor/dashboard', ['cookie_jar' => $investorJar]);
$beforeInvestorPost = qc4_state($tellerId, $brankasId, $wallets);

$investorPost = QcHttp::request('POST', '/operator/capital/topup', [
    'cookie_jar' => $investorJar,
    'data'       => [
        Security::CSRF_FIELD       => (string) QcHttp::csrfFrom($investorDashboard['body']),
        Security::FORM_FIELD       => $once,
        'topup_wallet_id'          => $wallets['reguler_a'],
        'topup_amount'             => '1.000.000',
    ],
]);

Qc::ok(
    'D.7',
    'POST investor tidak menulis apa pun (302 /investor/dashboard, kas & dompet utuh)',
    $investorPost['status'] === 302
        && $investorPost['location'] === '/investor/dashboard'
        && qc4_state($tellerId, $brankasId, $wallets) === $beforeInvestorPost,
    'status=' . $investorPost['status'] . ' location=' . (string) $investorPost['location']
);

Qc::ok(
    'D.8',
    'Tanpa login diarahkan ke /login',
    (static function (): bool {
        $anonJar = QcHttp::cookieJar('capital-anon');
        $anon = QcHttp::request('GET', '/operator/capital', ['cookie_jar' => $anonJar, 'fresh' => true]);

        return $anon['status'] === 302 && $anon['location'] === '/login';
    })(),
    'layar /operator/capital menuntut autentikasi'
);

// Setoran nyata lewat HTTP + penjagaan `_once`.
$freshPage = QcHttp::request('GET', '/operator/capital', ['cookie_jar' => $operatorJar]);
$freshCsrf = (string) QcHttp::csrfFrom($freshPage['body']);
$freshOnce = (string) QcHttp::fieldFrom($freshPage['body'], Security::FORM_FIELD);

$beforeHttpTopUp = qc4_state($tellerId, $brankasId, $wallets);

$httpTopUp = QcHttp::request('POST', '/operator/capital/topup', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD => $freshCsrf,
        Security::FORM_FIELD => $freshOnce,
        'topup_wallet_id'    => $wallets['reguler_a'],
        'topup_amount'       => '2.500.000',
        'topup_note'         => 'setoran via HTTP',
    ],
]);

$afterHttpTopUp = qc4_state($tellerId, $brankasId, $wallets);

Qc::ok(
    'D.9',
    'Setoran Rp 2.500.000 lewat HTTP tercatat (PRG 302 -> /operator/capital)',
    $httpTopUp['status'] === 302
        && $httpTopUp['location'] === '/operator/capital'
        && qc4_wallet($wallets['reguler_a'])['capital'] === '22500000.00',
    'status=' . $httpTopUp['status'] . ' saldo=' . (qc4_wallet($wallets['reguler_a'])['capital'] ?? '-')
);

$replay = QcHttp::request('POST', '/operator/capital/topup', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD => $freshCsrf,
        Security::FORM_FIELD => $freshOnce,
        'topup_wallet_id'    => $wallets['reguler_a'],
        'topup_amount'       => '2.500.000',
    ],
]);

$afterReplay = qc4_state($tellerId, $brankasId, $wallets);

Qc::ok(
    'D.10',
    'Replay token `_once` diabaikan (PRG + nol tulisan tambahan)',
    $replay['status'] === 302 && $afterReplay === $afterHttpTopUp,
    'status=' . $replay['status'] . ' location=' . (string) $replay['location']
);

// Nominal melebihi saldo dompet dan kas brankas.
$beforeGuards = qc4_state($tellerId, $brankasId, $wallets);
$guardsPage = QcHttp::request('GET', '/operator/capital', ['cookie_jar' => $operatorJar]);
$guardsCsrf = (string) QcHttp::csrfFrom($guardsPage['body']);
$guardsOnce = (string) QcHttp::fieldFrom($guardsPage['body'], Security::FORM_FIELD);

$bigWithdrawal = QcHttp::request('POST', '/operator/capital/withdrawal', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD     => $guardsCsrf,
        Security::FORM_FIELD     => $guardsOnce,
        'withdrawal_action'      => 'ajukan',
        'withdrawal_wallet_id'   => $wallets['reguler_b'],
        'withdrawal_type'        => WithdrawalRequest::TYPE_PROFIT,
        'withdrawal_amount'      => '999.000.000',
    ],
]);

Qc::ok(
    'D.11',
    'Penarikan melebihi saldo dompet ditolak 422 tanpa tulisan',
    $bigWithdrawal['status'] === 422
        && qc4_state($tellerId, $brankasId, $wallets) === $beforeGuards
        && str_contains($bigWithdrawal['body'], 'melebihi saldo'),
    'status=' . $bigWithdrawal['status']
);

$quotaPage = QcHttp::request('GET', '/operator/capital', ['cookie_jar' => $operatorJar]);
$quotaCsrf = (string) QcHttp::csrfFrom($quotaPage['body']);

$quotaOnExternal = QcHttp::request('POST', '/operator/capital/quota', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD => $quotaCsrf,
        'quota_wallet_id'    => $wallets['reguler_a'],
        'quota_value'        => '35',
    ],
]);

Qc::ok(
    'D.12',
    'Kuota pada dompet Mitra Reguler ditolak 422 (bukan diabaikan diam-diam)',
    $quotaOnExternal['status'] === 422
        && (qc4_wallet($wallets['reguler_a'])['quota'] ?? '') === '0.00',
    'status=' . $quotaOnExternal['status'] . ' kuota=' . (qc4_wallet($wallets['reguler_a'])['quota'] ?? '-')
);

$quotaPriority = QcHttp::request('POST', '/operator/capital/quota', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD => (string) QcHttp::csrfFrom(
            QcHttp::request('GET', '/operator/capital', ['cookie_jar' => $operatorJar])['body']
        ),
        'quota_wallet_id'    => $wallets['priority'],
        'quota_value'        => '30',
    ],
]);

Qc::ok(
    'D.13',
    'Kuota Mitra Prioritas tersimpan (PRG + nilai 30,00)',
    $quotaPriority['status'] === 302 && (qc4_wallet($wallets['priority'])['quota'] ?? '') === '30.00',
    'status=' . $quotaPriority['status'] . ' kuota=' . (qc4_wallet($wallets['priority'])['quota'] ?? '-')
);

// Kembalikan kuota prioritas ke 20 % agar protokol manual tetap dapat diulang.
QcFixture::setWalletState($wallets['priority'], qc4_wallet($wallets['priority'])['capital'] ?? '0.00', '0.00', '20.00');

// Nomor telepon ganda ditolak.
$duplicatePhone = QcHttp::request('POST', '/operator/capital/mitra', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD => (string) QcHttp::csrfFrom(
            QcHttp::request('GET', '/operator/capital', ['cookie_jar' => $operatorJar])['body']
        ),
        'mitra_mode'         => 'mitra',
        'mitra_full_name'    => 'Mitra Ganda',
        'mitra_phone_number' => '089900000103',
        'mitra_tier'         => CapitalWallet::TIER_EXTERNAL,
    ],
]);

Qc::equals(
    'D.14',
    'Nomor telepon yang sudah terdaftar ditolak 422',
    422,
    $duplicatePhone['status']
);

// Layar yang dirender benar-benar memuat keempat bagian + navigasi tiga layar
// (200 saja tidak cukup: halaman kosong juga 200).
$renderedCapital = (string) $capitalPage['body'];

$sectionMarkers = ['Setor Modal Baru', 'Portofolio Mitra', 'Pindah Kas ke Meja Bakar', 'Simulator', 'Antrean Penarikan'];
$missingMarkers = array_values(array_filter(
    $sectionMarkers,
    static fn (string $marker): bool => !str_contains($renderedCapital, $marker)
));

Qc::ok(
    'D.15',
    'Layar E merender seluruh bagian: setoran, portofolio, pindah kas, simulator, antrean penarikan',
    $missingMarkers === [],
    'bagian yang hilang: ' . implode(', ', $missingMarkers)
);

$navRendered = str_contains($renderedCapital, '>Kasir<')
    && str_contains($renderedCapital, '>Rekonsiliasi<')
    && str_contains($renderedCapital, '>Kelola Modal<');

Qc::ok(
    'D.16',
    'Navigasi header memuat tiga layar operator (Kasir | Rekonsiliasi | Kelola Modal)',
    $navRendered,
    'penanda navigasi pada HTML'
);

$portfolioRendered = str_contains($renderedCapital, 'QC Mitra Prioritas')
    && str_contains($renderedCapital, 'Mitra Prioritas')
    && str_contains($renderedCapital, 'Mitra Reguler')
    && str_contains($renderedCapital, '20,00');

Qc::ok(
    'D.17',
    'Tabel portofolio menampilkan label tier & kuota terkunci dari basis data',
    $portfolioRendered,
    'baris portofolio pada HTML'
);

$projectionRendered = str_contains($renderedCapital, 'Proyeksi peladen')
    && str_contains($renderedCapital, 'Sisa tak terserap')
    && str_contains($renderedCapital, 'Pengelola (prioritas kuota)');

Qc::ok(
    'D.18',
    'Proyeksi serapan dirender peladen (tabel tetap ada walau JavaScript nonaktif)',
    $projectionRendered,
    'tabel proyeksi pada HTML'
);

// Halaman yang dirender tidak boleh membocorkan diagnostik PHP (server QC
// dijalankan dengan `display_errors=1`, sehingga notice sekecil apa pun akan
// tampak di badan halaman).
$diagnostics = array_values(array_filter(
    ['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Uncaught', 'Stack trace'],
    static fn (string $needle): bool => str_contains($renderedCapital, $needle)
));

Qc::ok(
    'D.19',
    'Layar E bersih dari diagnostik PHP (warning/notice/deprecated)',
    $diagnostics === [],
    implode(', ', $diagnostics)
);

// Sel kuota serapan bersifat per-tier (perbaikan UX pascarilis): hanya Mitra
// Prioritas yang boleh menampilkan isian; Pengelola menampilkan badge kebijakan
// sistem, dan Mitra Reguler menampilkan penanda "Pro-rata". Sebelumnya semua tier
// mendapat kotak isian `0,00 %` yang menyesatkan bagi Pengelola (mesin memakai
// kuota dasar 50 % dari `system_settings`).
// Hitungan dilakukan PER DOMPET QC (bukan global): dompet buatan operator yang
// ber-tier Mitra Prioritas tetap sah menampilkan isian kuota.
$quotaFormFor = static fn (string $walletId): int => substr_count(
    $renderedCapital,
    'name="quota_wallet_id" value="' . $walletId . '"'
);

Qc::equals(
    'D.20',
    'Tanpa isian kuota untuk dompet Pengelola & kedua Mitra Reguler QC',
    [0, 0, 0],
    [
        $quotaFormFor($wallets['operator']),
        $quotaFormFor($wallets['reguler_a']),
        $quotaFormFor($wallets['reguler_b']),
    ]
);

Qc::ok(
    'D.21',
    'Isian kuota tersedia TEPAT satu untuk dompet Mitra Prioritas QC (form kuota aktif)',
    $quotaFormFor($wallets['priority']) === 1
        && str_contains($renderedCapital, 'operator/capital/quota')
        && str_contains($renderedCapital, 'Kuota dasar pengelola dari Pengaturan Sistem.'),
    'form kuota dompet prioritas=' . $quotaFormFor($wallets['priority'])
);

Qc::ok(
    'D.22',
    'Baris Pengelola menampilkan badge kuota sistem (bukan isian `0,00 %`)',
    str_contains($renderedCapital, '50,00 % (Sistem)')
        && str_contains($renderedCapital, 'Kuota dasar pengelola dari Pengaturan Sistem.'),
    'badge kuota sistem pada HTML'
);

Qc::ok(
    'D.23',
    'Baris Mitra Reguler menampilkan penanda "Pro-rata" tanpa isian',
    substr_count($renderedCapital, '>Pro-rata<') >= 2
        && str_contains($renderedCapital, 'Tanpa kuota &mdash; mengikuti sisa kebutuhan.'),
    'penanda pro-rata pada HTML: ' . substr_count($renderedCapital, '>Pro-rata<')
);

// Badge harus mengikuti kebijakan hidup, bukan angka yang ditulis tetap di view.
SystemSetting::set(AllocationEngine::SETTING_OPERATOR_QUOTA, '35.00');
$quotaChangedPage = (string) QcHttp::request('GET', '/operator/capital', ['cookie_jar' => $operatorJar])['body'];
SystemSetting::set(AllocationEngine::SETTING_OPERATOR_QUOTA, '50.00');

Qc::ok(
    'D.24',
    'Badge Pengelola mengikuti `system_settings.operator_base_quota_pct` (bukan hardcode)',
    str_contains($quotaChangedPage, '35,00 % (Sistem)')
        && !str_contains($quotaChangedPage, '50,00 % (Sistem)'),
    'badge setelah kebijakan diubah ke 35,00 %'
);

// ---------------------------------------------------------------------------
// E. Peladen tidak mempercayai nominal dari klien
// ---------------------------------------------------------------------------

Qc::section('E. Peladen tidak mempercayai nominal dari klien');

$beforeClientTrust = qc4_state($tellerId, $brankasId, $wallets);
$trustPage = QcHttp::request('GET', '/operator/capital', ['cookie_jar' => $operatorJar]);

$trustTopUp = QcHttp::request('POST', '/operator/capital/topup', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD => (string) QcHttp::csrfFrom($trustPage['body']),
        Security::FORM_FIELD => (string) QcHttp::fieldFrom($trustPage['body'], Security::FORM_FIELD),
        'topup_wallet_id'    => $wallets['reguler_b'],
        'topup_amount'       => '1.000.000',
        // Kolom yang TIDAK pernah dibaca peladen: saldo & tier kiriman klien.
        'balance_capital'    => '999.000.000',
        'tier'               => CapitalWallet::TIER_OPERATOR,
        'balance_profit'     => '500.000.000',
    ],
]);

$afterClientTrust = qc4_state($tellerId, $brankasId, $wallets);
$expectedBrankas = qc4_money(TellerService::centsToDecimal(
    TellerService::moneyToCents((string) $beforeClientTrust['brankas']) + 100000000
));

Qc::equals(
    'E.1',
    'Hanya nominal & pemilih dompet yang dibaca; kolom saldo kiriman klien diabaikan',
    ['15000000.00', $expectedBrankas],
    [qc4_field($wallets['reguler_b'], 'capital'), QcFixture::registerBalance($brankasId)]
);
Qc::ok(
    'E.2',
    'Tier & saldo dividen dompet tidak berubah walau klien mengirim `tier=operator`',
    qc4_field($wallets['reguler_b'], 'tier') === CapitalWallet::TIER_EXTERNAL
        && $trustTopUp['status'] === 302
        && qc4_field($wallets['reguler_b'], 'profit') === '0.00',
    'tier=' . qc4_field($wallets['reguler_b'], 'tier')
);

// ---------------------------------------------------------------------------
// F. Integrasi settlement: distribusi pool & saldo dompet
// ---------------------------------------------------------------------------

Qc::section('F. Integrasi settlement: distribusi pool 20 % ke dompet mitra');

// Kembalikan keadaan QC 2 (emas 18,050 gr + modal 35.157.500) dan setel ulang
// keempat dompet ke angka protokol; kas brankas dibersihkan lebih dulu.
$fixtureF = QcFixture::resetSettlementState();
$wallets = QcFixture::seedQcWallets();
$tellerId = (string) $fixtureF['teller_id'];
$brankasId = (string) $fixtureF['brankas_id'];

$notaPath = qc4_temp_file('nota-fase4.png', qc4_png(96, 96));

$settlementPage = QcHttp::request('GET', '/operator/settlement', ['cookie_jar' => $operatorJar]);
$settlementCsrf = (string) QcHttp::csrfFrom($settlementPage['body']);
$settlementOnce = (string) QcHttp::fieldFrom($settlementPage['body'], Security::FORM_FIELD);

$settlementIdsBefore = array_map(
    static fn (array $row): string => (string) $row['id'],
    Database::fetchAll('SELECT `id` FROM `daily_settlements`')
);

// Sejak Fase 9 beban TIDAK lagi dikirim lewat form: voucher kas Rp 200.000
// inilah yang diserap sesi tutup buku sebagai pengurang laba kotor.
QcFixture::seedOpexVoucher([
    'category'   => OperationalExpense::CATEGORY_COORDINATION,
    'payee_name' => 'Aparat QC 4',
    'amount'     => '200.000',
]);

$settlementPost = QcHttp::request('POST', '/operator/settlement', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD       => $settlementCsrf,
        Security::FORM_FIELD       => $settlementOnce,
        'sold_grams'               => '18,050',
        'density_pct'              => '93,50',
        'shop_price_per_g'         => '2.080.000',
        'coord_fee'                => '200.000',
        'kas_split'                => '',
        'brankas_split'            => '',
    ],
    'files'      => [SettlementService::RECEIPT_FIELD => $notaPath],
]);

Qc::ok(
    'F.1',
    'Tutup buku berhasil dieksekusi (PRG 302)',
    $settlementPost['status'] === 302 && $settlementPost['location'] === '/operator/settlement',
    'status=' . $settlementPost['status'] . ' location=' . (string) $settlementPost['location']
);

$newSettlements = array_values(array_filter(
    Database::fetchAll('SELECT `id`, `investor_pool_20`, `net_settled_profit` FROM `daily_settlements`'),
    static fn (array $row): bool => !in_array((string) $row['id'], $settlementIdsBefore, true)
));

Qc::equals('F.2', 'Tepat satu sesi tutup buku baru tercatat', 1, count($newSettlements));

$settlementId = (string) ($newSettlements[0]['id'] ?? '');
$rows = ProfitDistribution::listForSettlement($settlementId);
$byWallet = [];

foreach ($rows as $row) {
    $byWallet[(string) $row['wallet_id']] = [
        'utilized' => qc4_cents($row['capital_utilized']),
        'profit'   => qc4_cents($row['profit_earned']),
    ];
}

Qc::equals(
    'F.3',
    'Empat baris distribusi: serapan modal tercatat per dompet (termasuk Pengelola)',
    [
        [1757875000, 0],
        [703150000, 17492000],
        [620426471, 15434118],
        [434298529, 10803882],
    ],
    [
        array_values($byWallet[$wallets['operator']] ?? []),
        array_values($byWallet[$wallets['priority']] ?? []),
        array_values($byWallet[$wallets['reguler_a']] ?? []),
        array_values($byWallet[$wallets['reguler_b']] ?? []),
    ]
);

Qc::equals(
    'F.4',
    'Invariant: Σ capital_utilized = modal belanja putaran; Σ profit_earned = investor_pool_20',
    ['35157500.00', '437300.00', '437300.00'],
    [
        ProfitDistribution::sumUtilized($settlementId),
        ProfitDistribution::sumProfit($settlementId),
        qc4_money((string) ($newSettlements[0]['investor_pool_20'] ?? '0.00')),
    ]
);

Qc::equals(
    'F.5',
    'Dompet mitra menerima dividen tepat sebesar porsinya; dompet Pengelola tetap Rp 0',
    ['30000000.00', '16000000.00', '20000000.00', '14000000.00', '0.00', '174920.00', '154341.18', '108038.82'],
    [
        qc4_wallet($wallets['operator'])['capital'] ?? '',
        qc4_wallet($wallets['priority'])['capital'] ?? '',
        qc4_wallet($wallets['reguler_a'])['capital'] ?? '',
        qc4_wallet($wallets['reguler_b'])['capital'] ?? '',
        qc4_wallet($wallets['operator'])['profit'] ?? '',
        qc4_wallet($wallets['priority'])['profit'] ?? '',
        qc4_wallet($wallets['reguler_a'])['profit'] ?? '',
        qc4_wallet($wallets['reguler_b'])['profit'] ?? '',
    ]
);

Qc::ok(
    'F.6',
    'Modal pokok mitra TIDAK berkurang sepeser pun oleh alokasi (ex-post, bukan pemotongan)',
    qc4_wallet($wallets['priority'])['capital'] === '16000000.00'
        && qc4_wallet($wallets['reguler_a'])['capital'] === '20000000.00'
        && qc4_wallet($wallets['reguler_b'])['capital'] === '14000000.00',
    'prioritas=' . (qc4_wallet($wallets['priority'])['capital'] ?? '-')
);

$brankasMutation = Database::fetchOne(
    'SELECT `notes` FROM `cash_mutations` WHERE `register_id` = :id ORDER BY `created_at` DESC, `id` DESC LIMIT 1',
    ['id' => $brankasId]
);

Qc::ok(
    'F.7',
    'Catatan mutasi kas brankas memuat ringkasan alokasi (dompet, pool terdistribusi, sisa)',
    str_contains((string) ($brankasMutation['notes'] ?? ''), 'dompet_mitra=3')
        && str_contains((string) ($brankasMutation['notes'] ?? ''), 'pool_terdistribusi=437300.00')
        && str_contains((string) ($brankasMutation['notes'] ?? ''), 'sisa_tak_terserap=0.00'),
    'notes=' . (string) ($brankasMutation['notes'] ?? '-')
);

// Hari minus: pool 0 -> serapan tetap tercatat, dividen 0.00, modal utuh.
$fixtureLoss = QcFixture::resetSingleHoldingState('1,000', '1.950.000');
$walletsLoss = QcFixture::seedQcWallets();
$tellerLoss = (string) $fixtureLoss['teller_id'];

QcFixture::seedOpexVoucher([
    'category'   => OperationalExpense::CATEGORY_COORDINATION,
    'payee_name' => 'Aparat QC 4 (minus)',
    'amount'     => '200.000',
]);

$lossPage = QcHttp::request('GET', '/operator/settlement', ['cookie_jar' => $operatorJar]);
$lossPost = QcHttp::request('POST', '/operator/settlement', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD       => (string) QcHttp::csrfFrom($lossPage['body']),
        Security::FORM_FIELD       => (string) QcHttp::fieldFrom($lossPage['body'], Security::FORM_FIELD),
        'sold_grams'               => '1,000',
        'density_pct'              => '93,50',
        'shop_price_per_g'         => '2.050.000',
        'coord_fee'                => '200.000',
        'kas_split'                => '',
        'brankas_split'            => '',
    ],
    'files'      => [SettlementService::RECEIPT_FIELD => $notaPath],
]);

$lossSettlement = Database::fetchOne(
    'SELECT `id`, `net_settled_profit`, `investor_pool_20`, `carryover_deficit` '
    . 'FROM `daily_settlements` ORDER BY `created_at` DESC, `id` DESC LIMIT 1'
);
$lossId = (string) ($lossSettlement['id'] ?? '');
$lossRows = ProfitDistribution::listForSettlement($lossId);
$lossProfits = 0;

foreach ($lossRows as $row) {
    $lossProfits += qc4_cents($row['profit_earned']);
}

Qc::equals(
    'F.8',
    'Hari minus: laba −Rp 80.000, pool 0, seluruh profit_earned 0.00',
    ['-80000.00', '0.00', 0],
    [qc4_money((string) ($lossSettlement['net_settled_profit'] ?? '')), qc4_money((string) ($lossSettlement['investor_pool_20'] ?? '')), $lossProfits]
);
Qc::equals(
    'F.9',
    'Hari minus: saldo dividen & modal mitra tidak berubah; defisit berjalan Rp 80.000',
    ['0.00', '16000000.00', '80000.00'],
    [
        qc4_wallet($walletsLoss['priority'])['profit'] ?? '',
        qc4_wallet($walletsLoss['priority'])['capital'] ?? '',
        qc4_money(SystemSetting::get(SettlementService::SETTING_ACTIVE_DEFICIT, '0.00')),
    ]
);
Qc::ok(
    'F.10',
    'Hari minus tetap mencatat serapan modal (jejak audit), bukan menghapusnya',
    $lossPost['status'] === 302 && count($lossRows) === 4,
    'status=' . $lossPost['status'] . ' baris=' . count($lossRows)
);

// ---------------------------------------------------------------------------
// G. Idempotensi & pengulangan
// ---------------------------------------------------------------------------

Qc::section('G. Idempotensi & pengulangan suite');

Qc::ok(
    'G.1',
    'Fixture dompet idempotent: dijalankan dua kali tidak menggandakan dompet',
    (static function (): bool {
        $first = QcFixture::seedQcWallets();
        $countAfterFirst = CapitalWallet::count();
        $second = QcFixture::seedQcWallets();

        return $first === $second && CapitalWallet::count() === $countAfterFirst;
    })(),
    'jumlah dompet stabil setelah seed diulang'
);

Qc::ok(
    'G.2',
    'Tidak ada baris distribusi ganda untuk (settlement_id, wallet_id) yang sama',
    (int) (Database::fetchOne(
        'SELECT COUNT(*) AS `total` FROM ('
        . 'SELECT `settlement_id`, `wallet_id` FROM `profit_distributions` '
        . 'GROUP BY `settlement_id`, `wallet_id` HAVING COUNT(*) > 1'
        . ') AS `dup`'
    )['total'] ?? 0) === 0,
    'pemeriksaan duplikat per sesi'
);

Qc::ok(
    'G.3',
    'Reset menyeluruh menolkan SELURUH dompet dan membuang dompet akun uji',
    (static function (): bool {
        QcFixture::resetCapitalState();

        $snapshot = QcFixture::capitalSnapshot();
        $zeroed = true;

        foreach ($snapshot['wallets'] as $wallet) {
            if (qc4_money($wallet['balance_capital']) !== '0.00'
                || qc4_money($wallet['balance_profit']) !== '0.00'
                || qc4_money($wallet['locked_quota_pct']) !== '0.00'
            ) {
                $zeroed = false;
            }
        }

        // Dompet akun uji (`0899…`) dibuang, dompet operator tetap ada.
        return $zeroed
            && $snapshot['distributions'] === 0
            && $snapshot['withdrawals'] === 0
            && CapitalWallet::findByUserId((string) (
                Database::fetchOne(
                    'SELECT `id` FROM `users` WHERE `phone_number` = :phone LIMIT 1',
                    ['phone' => '089900000102']
                )['id'] ?? 'tidak-ada'
            )) === null
            && CapitalWallet::findByUserId((string) (
                Database::fetchOne(
                    'SELECT `id` FROM `users` WHERE `phone_number` = :phone LIMIT 1',
                    ['phone' => (string) (Env::get('OPERATOR_PHONE') ?? '081200000001')]
                )['id'] ?? 'tidak-ada'
            )) !== null;
    })(),
    'setelah reset: semua dompet nol & dompet akun uji dibuang'
);

Qc::ok(
    'G.4',
    'Suite dapat dijalankan ulang: seed + setoran menghasilkan keadaan yang sama',
    (static function (): array {
        QcFixture::resetCapitalState();
        $fresh = QcFixture::seedQcWallets();
        $topUp = CapitalService::topUp([
            'wallet_id' => $fresh['reguler_a'],
            'amount'    => '1.000.000',
            'note'      => 'ulang',
        ]);
        $state = qc4_wallet($fresh['reguler_a']);

        return [$topUp['amount_cents'], $state['capital'] ?? ''];
    })() === [100000000, '21000000.00'],
    'putaran ulang seed + setoran'
);

// ---------------------------------------------------------------------------
// G.5 — Data manual operator TIDAK boleh menggeser angka protokol.
//
// Skenario nyata: saat pengujian lapangan, operator membuat mitra lewat form
// "Tambah Mitra" (tier family, kuota 20 %, sudah disetor). Fixture QC wajib
// menetralkan dompet itu sehingga serapan tetap jatuh ke dompet protokol —
// tanpa menghapus baris dompet milik operator.
// ---------------------------------------------------------------------------

$foreignUser = User::findByPhoneNumber('089900000777', false);

if ($foreignUser === null) {
    $foreignUserId = User::create(
        'Mitra Manual Lapangan',
        '089900000777',
        Security::randomToken(32),
        Constants::ROLE_INVESTOR
    );
} else {
    $foreignUserId = (string) $foreignUser['id'];
}

$foreignWallet = CapitalWallet::findByUserId($foreignUserId);
$foreignWalletId = $foreignWallet === null
    ? CapitalWallet::insert($foreignUserId, CapitalWallet::TIER_FAMILY)
    : (string) $foreignWallet['id'];

QcFixture::setWalletState($foreignWalletId, '90000000.00', '0.00', '20.00');

$foreignFixture = QcFixture::resetSettlementState();
$foreignWallets = QcFixture::seedQcWallets();

$foreignNeutralised = qc4_wallet($foreignWalletId);

Qc::equals(
    'G.5',
    'Fixture menetralkan dompet manual (kuota & saldo nol) tanpa menghapus barisnya',
    ['0.00', '0.00', '0.00', true],
    [
        (string) ($foreignNeutralised['capital'] ?? ''),
        (string) ($foreignNeutralised['profit'] ?? ''),
        (string) ($foreignNeutralised['quota'] ?? ''),
        CapitalWallet::findById($foreignWalletId) !== null,
    ]
);

$foreignPlan = AllocationEngine::plan(3515750000, [
    ['id' => $foreignWallets['operator'], 'tier' => CapitalWallet::TIER_OPERATOR, 'available_cents' => 3000000000],
    ['id' => $foreignWallets['priority'], 'tier' => CapitalWallet::TIER_FAMILY, 'available_cents' => 1600000000, 'quota_hundredths' => 2000],
    ['id' => $foreignWalletId, 'tier' => CapitalWallet::TIER_FAMILY, 'available_cents' => 0, 'quota_hundredths' => 0],
    ['id' => $foreignWallets['reguler_a'], 'tier' => CapitalWallet::TIER_EXTERNAL, 'available_cents' => 2000000000],
    ['id' => $foreignWallets['reguler_b'], 'tier' => CapitalWallet::TIER_EXTERNAL, 'available_cents' => 1400000000],
], 5000);

$foreignSums = [];

foreach ($foreignPlan['rows'] as $row) {
    $foreignSums[$row['id']] = $row['utilized_cents'];
}

Qc::equals(
    'G.6',
    'Angka protokol tidak bergeser walau dompet manual ikut terdaftar',
    [1757875000, 703150000, 620426471, 434298529, 0, 0],
    [
        $foreignSums[$foreignWallets['operator']] ?? -1,
        $foreignSums[$foreignWallets['priority']] ?? -1,
        $foreignSums[$foreignWallets['reguler_a']] ?? -1,
        $foreignSums[$foreignWallets['reguler_b']] ?? -1,
        $foreignSums[$foreignWalletId] ?? -1,
        $foreignPlan['uncovered_cents'],
    ]
);

// Bersihkan dompet simulasi (akun `0899…` dihapus oleh resetCapitalState).
QcFixture::resetCapitalState();
QcFixture::clearSettlementTraces();

// ---------------------------------------------------------------------------
// J. Aturan tanggal H-7 hari kerja
// ---------------------------------------------------------------------------

Qc::section('J. Jatuh tempo penarikan: H-7 HARI KERJA');

// 2026-09-18 adalah Jumat; 7 hari kerja berjalan melewati Sabtu & Minggu.
Qc::equals(
    'J.1',
    'Pengajuan Jumat 2026-09-18 + 7 hari kerja jatuh tempo Selasa 2026-09-29',
    '2026-09-29',
    CapitalService::workingDaysDueDate('2026-09-18', 7)
);
Qc::equals(
    'J.2',
    'Pengajuan Sabtu 2026-09-19 + 7 hari kerja = Selasa 2026-09-29 (Sabtu & Minggu dilewati)',
    '2026-09-29',
    CapitalService::workingDaysDueDate('2026-09-19', 7)
);
Qc::equals(
    'J.3',
    'Masa tunggu nol hari berarti tanggal yang sama',
    '2026-09-18',
    CapitalService::workingDaysDueDate('2026-09-18', 0)
);
Qc::equals(
    'J.4',
    'Satu hari kerja dari Jumat melompat ke Senin',
    '2026-09-21',
    CapitalService::workingDaysDueDate('2026-09-18', 1)
);
Qc::throws(
    'J.5',
    'Tanggal acuan rusak / masa tunggu negatif ditolak',
    static fn () => CapitalService::workingDaysDueDate('18-09-2026', 7),
    InvalidArgumentException::class
);
Qc::equals(
    'J.6',
    'Masa tunggu default dari system_settings = 7 hari kerja',
    [7, '7'],
    [CapitalService::configuredNoticeDays(), (string) SystemSetting::get(CapitalService::SETTING_NOTICE_DAYS, '')]
);

// Antrean penarikan: pengajuan, keputusan, dan gerbang jatuh tempo.
$fixtureJ = QcFixture::resetSingleHoldingState('1,000', '1.950.000');
$walletsJ = QcFixture::seedQcWallets();
$tellerJ = (string) $fixtureJ['teller_id'];

// Beri dompet Prioritas dividen agar ada yang bisa ditarik.
QcFixture::setWalletState($walletsJ['priority'], '16000000.00', '500000.00', '20.00');

$request = CapitalService::requestWithdrawal([
    'wallet_id'       => $walletsJ['priority'],
    'withdrawal_type' => WithdrawalRequest::TYPE_CAPITAL,
    'amount'          => '5.000.000',
    'note'            => 'QC H-7',
]);

$requestedState = qc4_wallet($walletsJ['priority']);
$dueDate = (string) $request['notice_due'];

Qc::equals(
    'J.7',
    'Pengajuan modal pokok menandai dompet `queued`, menyimpan permohonan, dan jatuh tempo jatuh di HARI KERJA',
    ['queued', 1, true, true],
    [
        (string) ($requestedState['status'] ?? ''),
        (int) (Database::fetchOne(
            'SELECT COUNT(*) AS `total` FROM `withdrawal_requests` WHERE `id` = :id',
            ['id' => (string) $request['request_id']]
        )['total'] ?? 0),
        $dueDate > date('Y-m-d'),
        (int) date('N', (int) strtotime($dueDate)) <= 5,
    ]
);

Qc::throws(
    'J.8',
    'Pengajuan modal kedua ditolak selama yang pertama belum selesai',
    static fn () => CapitalService::requestWithdrawal([
        'wallet_id'       => $walletsJ['priority'],
        'withdrawal_type' => WithdrawalRequest::TYPE_CAPITAL,
        'amount'          => '1.000.000',
    ]),
    InvalidArgumentException::class
);

$decided = CapitalService::decideWithdrawal([
    'request_id' => (string) $request['request_id'],
    'decision'   => 'approve',
]);

Qc::equals('J.9', 'Permohonan disetujui (status `approved`)', 'approved', (string) $decided['status']);

Qc::throws(
    'J.10',
    'Pencairan modal pokok sebelum jatuh tempo ditolak (H-7 hari kerja)',
    static fn () => CapitalService::payWithdrawal(['request_id' => (string) $request['request_id']]),
    InvalidArgumentException::class
);

Qc::equals(
    'J.11',
    'Penolakan jatuh tempo tidak mengubah saldo dompet maupun kas brankas',
    ['16000000.00', '500000.00', '0.00'],
    [
        qc4_wallet($walletsJ['priority'])['capital'] ?? '',
        qc4_wallet($walletsJ['priority'])['profit'] ?? '',
        QcFixture::registerBalance($brankasId),
    ]
);

// Dividen: boleh dibayar setelah disetujui (tanpa masa tunggu), tetapi kas
// brankas harus tersedia lebih dulu.
Database::query('UPDATE `cash_registers` SET `current_balance` = :balance WHERE `id` = :id', [
    'balance' => '1000000.00',
    'id'      => $brankasId,
]);

$profitRequest = CapitalService::requestWithdrawal([
    'wallet_id'       => $walletsJ['priority'],
    'withdrawal_type' => WithdrawalRequest::TYPE_PROFIT,
    'amount'          => '300.000',
]);

CapitalService::decideWithdrawal(['request_id' => (string) $profitRequest['request_id'], 'decision' => 'approve']);

$paid = CapitalService::payWithdrawal(['request_id' => (string) $profitRequest['request_id']]);
$paidState = qc4_wallet($walletsJ['priority']);

Qc::equals(
    'J.12',
    'Dividen Rp 300.000 dibayar: saldo dividen turun, kas brankas keluar, status `completed`',
    ['200000.00', '700000.00', 'completed'],
    [
        $paidState['profit'] ?? '',
        QcFixture::registerBalance($brankasId),
        (string) (Database::fetchOne(
            'SELECT `status` FROM `withdrawal_requests` WHERE `id` = :id LIMIT 1',
            ['id' => (string) $paid['request_id']]
        )['status'] ?? ''),
    ]
);

Qc::throws(
    'J.13',
    'Pencairan ulang permohonan yang sudah selesai ditolak (tanpa pembayaran ganda)',
    static fn () => CapitalService::payWithdrawal(['request_id' => (string) $profitRequest['request_id']]),
    InvalidArgumentException::class
);

// Kas brankas kurang: ditolak tanpa tulisan.
Database::query('UPDATE `cash_registers` SET `current_balance` = :balance WHERE `id` = :id', [
    'balance' => '0.00',
    'id'      => $brankasId,
]);
QcFixture::setWalletState($walletsJ['priority'], '16000000.00', '500000.00', '20.00');

$poorRequest = CapitalService::requestWithdrawal([
    'wallet_id'       => $walletsJ['priority'],
    'withdrawal_type' => WithdrawalRequest::TYPE_PROFIT,
    'amount'          => '400.000',
]);
CapitalService::decideWithdrawal(['request_id' => (string) $poorRequest['request_id'], 'decision' => 'approve']);

$beforePoor = qc4_state($tellerJ, $brankasId, $walletsJ);

Qc::throws(
    'J.14',
    'Pencairan ditolak saat kas Brankas tidak cukup (tanpa tulisan)',
    static fn () => CapitalService::payWithdrawal(['request_id' => (string) $poorRequest['request_id']]),
    RuntimeException::class
);
Qc::equals('J.15', 'Keadaan kas & dompet utuh setelah pencairan ditolak', $beforePoor, qc4_state($tellerJ, $brankasId, $walletsJ));

// ---------------------------------------------------------------------------
// H. Pemindaian statis, navigasi, dan kontrak form Layar E
// ---------------------------------------------------------------------------

Qc::section('H. Pemindaian statis & kontrak form Layar E');

$capitalView = (string) file_get_contents(VIEW_PATH . '/operator/capital.php');

// Markup tanpa blok PHP: dipakai pemindai tag supaya blok pembuka PHP (termasuk
// docblock yang menyebut `:disabled`) tidak pernah terbaca sebagai tag HTML.
// CATATAN: jangan pernah menulis kurung tutup blok PHP di dalam komentar —
// token itu MENGAKHIRI mode PHP walau berada pada baris komentar.
$capitalMarkup = (string) preg_replace('/<\?.*?\?>/s', '@@PHP@@', $capitalView);
$layoutView = (string) file_get_contents(VIEW_PATH . '/layouts/operator.php');
$engineSource = (string) file_get_contents(APP_PATH . '/Services/AllocationEngine.php');
$serviceSource = (string) file_get_contents(APP_PATH . '/Services/CapitalService.php');
$controllerSource = (string) file_get_contents(APP_PATH . '/Controllers/CapitalController.php');
$appJs = (string) file_get_contents(PUBLIC_PATH . '/assets/js/app.js');

// H.1 — kontrol form tidak pernah `:disabled` (hanya <button>). Diperiksa per
// TAG, bukan per baris: atribut `:disabled` biasanya berada pada baris sendiri.
preg_match_all('/<[a-zA-Z][^>]*>/', $capitalMarkup, $tagMatches);

$disabledTags = array_values(array_filter(
    $tagMatches[0],
    static fn (string $tag): bool => str_contains($tag, ':disabled')
        && !str_starts_with(trim((string) preg_replace('/\s+/', ' ', $tag)), '<button')
));

Qc::ok(
    'H.1',
    'Kontrol form Layar E tidak pernah `:disabled` (`:disabled` hanya untuk <button>)',
    $disabledTags === [],
    implode("\n", $disabledTags)
);

// H.2 — placeholder numerik berawalan `mis. `.
$placeholderOffenders = qc4_scan([VIEW_PATH . '/operator/capital.php'], static function (string $line): bool {
    if (!str_contains($line, 'placeholder="')) {
        return false;
    }

    return preg_match('/placeholder="(?!mis\. )/', $line) === 1;
});

Qc::ok(
    'H.2',
    'Setiap placeholder pada Layar E berawalan `mis. ` (contoh, bukan data)',
    $placeholderOffenders === [],
    implode("\n", $placeholderOffenders)
);

// H.3 — simulasi bebas diedit: isian rencana belanja memakai x-model.fill,
// nilai awal dirender peladen, dan kontrolnya tidak pernah `disabled`.
$needPosition = strpos($capitalView, 'name="need_cents"');
$needBlock = $needPosition === false ? '' : substr($capitalView, max(0, $needPosition - 200), 700);

Qc::ok(
    'H.3',
    'Isian simulator terisi dari peladen (`value="…"`), terikat `x-model.fill`, dan tidak `disabled`',
    $needBlock !== ''
        && str_contains($needBlock, 'x-model.fill="needInput"')
        && str_contains($needBlock, "value=\"<?= Security::e(\$projection['need_field']")
        && str_contains($needBlock, ':readonly="submitting"')
        && !str_contains($needBlock, ':disabled'),
    $needBlock
);

// H.4 — komponen Alpine terdaftar.
Qc::ok(
    'H.4',
    'Komponen Alpine `capitalSimulator` terdaftar di app.js',
    str_contains($appJs, "Alpine.data('capitalSimulator', capitalSimulator)")
        && str_contains($appJs, 'function capitalSimulator('),
    'registrasi Alpine.data'
);

Qc::ok(
    'H.5',
    'Cermin `AurumAlloc` memakai BigInt agar presisi sen sama dengan peladen',
    str_contains($appJs, 'mulDiv: function') && str_contains($appJs, 'BigInt('),
    'aritmetika cermin'
);

Qc::ok(
    'H.6',
    'Tidak ada float/double/number_format di berkas Fase 4',
    qc4_scan(
        [APP_PATH . '/Services/AllocationEngine.php', APP_PATH . '/Services/CapitalService.php', APP_PATH . '/Controllers/CapitalController.php'],
        static fn (string $line): bool => preg_match('/(?<![A-Za-z_])float(?![A-Za-z_])|(?<![A-Za-z_])double(?![A-Za-z_])|number_format\s*\(|\(float\)/', $line) === 1
    ) === [],
    'pemindai jalur uang'
);

// Pola sengaja menuntut kata kunci SQL BERIKUTNYA (FROM/INTO/SET) supaya tag
// HTML `<select>` tidak terbaca sebagai perintah SELECT.
$sqlOffenders = qc4_scan(
    [APP_PATH . '/Controllers/CapitalController.php', VIEW_PATH . '/operator/capital.php'],
    static fn (string $line): bool => preg_match(
        '/\bSELECT\b.{0,60}\bFROM\b|\bINSERT\b.{0,30}\bINTO\b|\bDELETE\b.{0,30}\bFROM\b|\bUPDATE\b.{0,40}\bSET\b/i',
        $line
    ) === 1
);

Qc::ok(
    'H.7',
    'Tidak ada SQL di controller & view Layar E (aturan AGENTS.md)',
    $sqlOffenders === [],
    implode("\n", $sqlOffenders)
);

Qc::ok(
    'H.8',
    'Setiap method publik CapitalController memakai `requireRole` (guard peran)',
    substr_count($controllerSource, 'requireRole(Constants::ROLE_OPERATOR)') >= 2
        && str_contains($controllerSource, 'private function guard()')
        && str_contains($controllerSource, '$this->guard()')
        && str_contains($controllerSource, '$this->guardOnce()'),
    'jumlah pemanggilan=' . substr_count($controllerSource, 'requireRole(Constants::ROLE_OPERATOR)')
);

// Satu transaksi per use-case tulis: setoran, pindah kas, tambah mitra, kuota,
// ajukan penarikan, keputusan penarikan, bayar penarikan, dan penerbitan
// tautan undangan (Fase 5) = 8 (docblock tidak dihitung karena `qc4_scan`
// melewati baris komentar).
$transactionCalls = count(qc4_scan(
    [APP_PATH . '/Services/CapitalService.php'],
    static fn (string $line): bool => str_contains($line, 'Database::transaction(')
));

Qc::ok(
    'H.9',
    'Setiap jalur tulis CapitalService memakai `Database::transaction()` (8 use-case) tanpa beginTransaction manual',
    $transactionCalls === 8
        && !str_contains($serviceSource, 'beginTransaction')
        && !str_contains($engineSource, 'beginTransaction'),
    'transaksi pada CapitalService=' . $transactionCalls
);

Qc::ok(
    'H.10',
    'Urutan penguncian global: dompet dikunci SEBELUM pos kas pada settlement',
    (static function (): bool {
        $source = (string) file_get_contents(APP_PATH . '/Services/SettlementService.php');
        $walletLock = strpos($source, 'CapitalWallet::lockAllForUpdate()');
        $registerLock = strpos($source, 'CashRegister::lockByNamesForUpdate(');

        return $walletLock !== false && $registerLock !== false && $walletLock < $registerLock;
    })(),
    'posisi pemanggilan pada SettlementService'
);

Qc::ok(
    'H.11',
    'Navigasi operator memuat tiga layar: Kasir, Rekonsiliasi, Kelola Modal',
    str_contains($layoutView, "'label' => 'Kasir'")
        && str_contains($layoutView, "'label' => 'Rekonsiliasi'")
        && str_contains($layoutView, "'label' => 'Kelola Modal'")
        && str_contains($layoutView, "App::url('operator/capital')"),
    'navigasi layout operator'
);

Qc::ok(
    'H.11b',
    'Dashboard memakai kerangka 2-tier: controller menyatakan LAYOUT = operator dan meneruskannya',
    (static function (): bool {
        $source = (string) file_get_contents(APP_PATH . '/Controllers/DashboardController.php');

        return str_contains($source, "private const LAYOUT = 'operator';")
            && str_contains($source, 'self::LAYOUT');
    })(),
    'wiring layout dashboard (Plan_Unify_UI_AurumFlow C1)'
);

Qc::ok(
    'H.11c',
    'Baris navigasi layout operator memuat tab Dashboard dan menggeser horizontal di ponsel',
    str_contains($layoutView, "'label' => 'Dashboard'")
        && str_contains($layoutView, "'label' => 'Kelola Kasir'")
        && str_contains($layoutView, 'overflow-x-auto')
        && str_contains($layoutView, 'shrink-0'),
    'kontrak tab tersegmen (Plan_Unify_UI_AurumFlow § 4.3–4.4)'
);

Qc::ok(
    'H.12',
    'Dashboard menyediakan pintu role-aware ke layar modal',
    str_contains((string) file_get_contents(VIEW_PATH . '/dashboard/index.php'), "App::url('operator/capital')"),
    'tautan dashboard'
);

$dashboardPage = QcHttp::request('GET', '/dashboard', ['cookie_jar' => $operatorJar]);

Qc::ok(
    'H.12b',
    'GET /dashboard merender kerangka 2-tier yang sama: lima tab operator + penanda tab aktif',
    $dashboardPage['status'] === 200
        && str_contains((string) $dashboardPage['body'], 'aria-current="page"')
        && (static function () use ($dashboardPage): bool {
            $hrefs = ['/dashboard', '/operator/teller', '/operator/settlement', '/operator/capital', '/operator/kasir'];

            foreach ($hrefs as $href) {
                if (!str_contains((string) $dashboardPage['body'], 'href="' . $href . '"')) {
                    return false;
                }
            }

            return true;
        })()
        // `max-w-3xl` hanya dipakai layouts/app.php: shell 1-tier tidak boleh
        // lagi merender halaman ini.
        && !str_contains((string) $dashboardPage['body'], 'max-w-3xl'),
    'status=' . $dashboardPage['status'] . ' (Plan_Unify_UI_AurumFlow § 8)'
);

Qc::ok(
    'H.13',
    'Seluruh output dinamis view memakai `Security::e()` (tidak ada `<?=` tanpa escaping)',
    qc4_scan([VIEW_PATH . '/operator/capital.php'], static function (string $line): bool {
        if (!str_contains($line, '<?=')) {
            return false;
        }

        return !str_contains($line, 'Security::e(')
            && !str_contains($line, 'Security::csrfField()');
    }) === [],
    'pemindai escaping view'
);

Qc::ok(
    'H.14',
    'Istilah lama "Kerabat" tidak tersisa pada kode maupun dokumen',
    (static function (): array {
        $offenders = [];

        foreach ([BASE_PATH . '/docs', APP_PATH, PUBLIC_PATH . '/assets/js'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                if (preg_match('/\bkerabat\b/i', $contents) === 1) {
                    $offenders[] = str_replace(BASE_PATH . '/', '', $file->getPathname());
                }
            }
        }

        return $offenders;
    })() === [],
    'pemindaian istilah produk'
);

// ---------------------------------------------------------------------------
// I. Integritas skema (tanpa perubahan DDL)
// ---------------------------------------------------------------------------

Qc::section('I. Integritas skema: nol migrasi');

$decimalViolations = QcFixture::checkDecimalConventions([
    ['capital_wallets', 'balance_capital', 14, 2],
    ['capital_wallets', 'balance_profit', 14, 2],
    ['capital_wallets', 'locked_quota_pct', 5, 2],
    ['profit_distributions', 'capital_utilized', 14, 2],
    ['profit_distributions', 'profit_earned', 14, 2],
    ['withdrawal_requests', 'amount', 14, 2],
]);

Qc::ok('I.1', 'Konvensi DECIMAL(14,2)/DECIMAL(5,2) tetap utuh (nol migrasi)', $decimalViolations === [], implode("\n", $decimalViolations));

$tierColumn = QcFixture::columnDefinition('capital_wallets', 'tier');
$statusColumn = QcFixture::columnDefinition('withdrawal_requests', 'status');
$walletStatusColumn = QcFixture::columnDefinition('capital_wallets', 'withdrawal_status');

Qc::equals(
    'I.2',
    'Enum tier tetap `operator|family|external` (label hanya di antarmuka)',
    "enum('operator','family','external')",
    strtolower((string) ($tierColumn['COLUMN_TYPE'] ?? ''))
);
Qc::equals(
    'I.3',
    'Enum status permohonan penarikan tetap `pending|approved|rejected|completed`',
    "enum('pending','approved','rejected','completed')",
    strtolower((string) ($statusColumn['COLUMN_TYPE'] ?? ''))
);
Qc::equals(
    'I.4',
    'Enum status dompet tetap `none|queued|completed`',
    "enum('none','queued','completed')",
    strtolower((string) ($walletStatusColumn['COLUMN_TYPE'] ?? ''))
);

Qc::ok(
    'I.5',
    'Sembilan tabel ERD tetap ada (tidak ada tabel baru)',
    count(array_filter([
        'system_settings', 'users', 'capital_wallets', 'cash_registers', 'cash_mutations',
        'daily_settlements', 'hulu_transactions', 'profit_distributions', 'withdrawal_requests',
    ], static fn (string $table): bool => QcFixture::tableExists($table))) === 9
        && !QcFixture::tableExists('cash_transfers'),
    'daftar tabel'
);

// ---------------------------------------------------------------------------
// Pemulihan keadaan & tabel protokol manual
// ---------------------------------------------------------------------------

Qc::section('Pemulihan keadaan & tabel Protokol QC Manual 4');

QcFixture::resetCapitalState();
QcFixture::clearSettlementTraces();

$protocolState = QcFixture::seedQcWallets();
$planProtocol = AllocationEngine::plan(3515750000, [
    ['id' => $protocolState['operator'], 'tier' => CapitalWallet::TIER_OPERATOR, 'available_cents' => 3000000000],
    ['id' => $protocolState['priority'], 'tier' => CapitalWallet::TIER_FAMILY, 'available_cents' => 1600000000, 'quota_hundredths' => 2000],
    ['id' => $protocolState['reguler_a'], 'tier' => CapitalWallet::TIER_EXTERNAL, 'available_cents' => 2000000000],
    ['id' => $protocolState['reguler_b'], 'tier' => CapitalWallet::TIER_EXTERNAL, 'available_cents' => 1400000000],
], 5000);

$protocolUtilized = [];

foreach ($planProtocol['rows'] as $row) {
    $protocolUtilized[$row['id']] = $row['utilized_cents'];
}

$protocolPool = AllocationEngine::distribute(43730000, [
    $protocolState['priority'] => $protocolUtilized[$protocolState['priority']],
    $protocolState['reguler_a'] => $protocolUtilized[$protocolState['reguler_a']],
    $protocolState['reguler_b'] => $protocolUtilized[$protocolState['reguler_b']],
]);

$protocol = [
    [
        'Serapan Modal Pengelola (50 %)',
        '50% x Rp 35.157.500',
        'Rp 17.578.750',
        $protocolUtilized[$protocolState['operator']] === 1757875000 ? 'LULUS' : 'GAGAL',
        qc4_money(TellerService::centsToDecimal($protocolUtilized[$protocolState['operator']])),
    ],
    [
        'Serapan Modal Mitra Prioritas (20 %)',
        '20% x Rp 35.157.500',
        'Rp 7.031.500',
        $protocolUtilized[$protocolState['priority']] === 703150000 ? 'LULUS' : 'GAGAL',
        qc4_money(TellerService::centsToDecimal($protocolUtilized[$protocolState['priority']])),
    ],
    [
        'Sisa Kebutuhan Kas (30 %)',
        'Rp 35.157.500 - Rp 24.610.250',
        'Rp 10.547.250',
        $planProtocol['stages'][CapitalWallet::TIER_EXTERNAL] === 1054725000 ? 'LULUS' : 'GAGAL',
        qc4_money(TellerService::centsToDecimal($planProtocol['stages'][CapitalWallet::TIER_EXTERNAL])),
    ],
    [
        'Pro-rata Mitra Reguler A',
        '20/34 x sisa',
        'Rp 6.204.265 (620426471 sen)',
        $protocolUtilized[$protocolState['reguler_a']] === 620426471 ? 'LULUS' : 'GAGAL',
        qc4_money(TellerService::centsToDecimal($protocolUtilized[$protocolState['reguler_a']])),
    ],
    [
        'Pro-rata Mitra Reguler B',
        'sisa pembagian',
        'Rp 4.342.985 (434298529 sen)',
        $protocolUtilized[$protocolState['reguler_b']] === 434298529 ? 'LULUS' : 'GAGAL',
        qc4_money(TellerService::centsToDecimal($protocolUtilized[$protocolState['reguler_b']])),
    ],
    [
        'Total Modal Terserap',
        'jumlah seluruh dompet',
        'Rp 35.157.500 (persis modal belanja)',
        $planProtocol['utilized_cents'] === 3515750000 && $planProtocol['uncovered_cents'] === 0 ? 'LULUS' : 'GAGAL',
        'sisa tak terserap ' . qc4_money(TellerService::centsToDecimal($planProtocol['uncovered_cents'])),
    ],
    [
        'Dividen Mitra Prioritas',
        '40,0000 % x Rp 437.300',
        'Rp 174.920',
        ($protocolPool['shares'][$protocolState['priority']] ?? 0) === 17492000 ? 'LULUS' : 'GAGAL',
        qc4_money(TellerService::centsToDecimal($protocolPool['shares'][$protocolState['priority']] ?? 0)),
    ],
    [
        'Dividen Mitra Reguler A',
        'pro-rata modal bekerja',
        'Rp 154.341',
        ($protocolPool['shares'][$protocolState['reguler_a']] ?? 0) === 15434118 ? 'LULUS' : 'GAGAL',
        qc4_money(TellerService::centsToDecimal($protocolPool['shares'][$protocolState['reguler_a']] ?? 0)),
    ],
    [
        'Dividen Mitra Reguler B',
        'pro-rata modal bekerja',
        'Rp 108.039',
        ($protocolPool['shares'][$protocolState['reguler_b']] ?? 0) === 10803882 ? 'LULUS' : 'GAGAL',
        qc4_money(TellerService::centsToDecimal($protocolPool['shares'][$protocolState['reguler_b']] ?? 0)),
    ],
    [
        'Σ Dividen Pool Mitra',
        'pool 20 % putaran',
        'Rp 437.300 (tanpa selisih sen)',
        (int) $protocolPool['total'] === 43730000 ? 'LULUS' : 'GAGAL',
        'total terdistribusi ' . qc4_money(TellerService::centsToDecimal((int) $protocolPool['total'])),
    ],
    [
        'Jatah Pengelola dari Pool',
        'PRD § 3.4',
        'Rp 0 (modal pengelola di luar pool)',
        !isset($protocolPool['shares'][$protocolState['operator']]) ? 'LULUS' : 'GAGAL',
        'baris pengelola hanya mencatat serapan',
    ],
    [
        'Jatuh Tempo Penarikan Modal',
        'notice_period_days = 7 hari kerja',
        'Sabtu & Minggu dilewati',
        CapitalService::workingDaysDueDate('2026-09-18', 7) === '2026-09-29' ? 'LULUS' : 'GAGAL',
        'contoh: Jumat 2026-09-18 -> ' . CapitalService::workingDaysDueDate('2026-09-18', 7),
    ],
];

$widths = [34, 30, 32, 8];

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
        $status
    );
    echo '  |' . str_repeat(' ', $widths[0] + 2) . '|' . str_repeat(' ', $widths[1] + 2)
        . '|' . str_repeat(' ', $widths[2] + 2) . '| ' . $detail . PHP_EOL;
}

Qc::ok(
    'P4.99',
    'Seluruh baris Protokol QC Manual 4 berstatus LULUS',
    array_reduce($protocol, static fn (bool $carry, array $row): bool => $carry && $row[3] === 'LULUS', true),
    implode(', ', array_map(static fn (array $row): string => $row[0] . '=' . $row[3], $protocol))
);

QcFixture::resetCapitalState();
QcFixture::clearSettlementTraces();

Qc::finish('qc_fase_4');
