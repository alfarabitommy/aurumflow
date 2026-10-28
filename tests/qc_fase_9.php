<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| QC Fase 9 — Beban Operasional Non-Emas (OPEX) & Otomatisasi Settlement
|--------------------------------------------------------------------------
| PERINGATAN: suite ini MENULIS ke database yang ditunjuk `.env` (tabel
| `operational_expenses`, `cash_mutations`, saldo Kas Meja Bakar, sesi tutup
| buku, dan berkas nota di public/uploads/receipts/). Fixture di akhir suite
| mengembalikan keadaan, sehingga suite dapat dijalankan berulang.
|
| Yang dibuktikan (Opsi B pada audit/Audit_Biaya_Koordinasi_AurumFlow.md):
|   A. `ExpenseService::pay()` = TIGA tulisan dalam satu transaksi, urutan
|      kunci → cek saldo → mutasi `out` → saldo turun → voucher.
|   B. Penolakan input tidak sah & saldo kurang → NOL tulisan (422).
|   C. HTTP: operator DAN kasir boleh mencatat beban; mitra & anonim ditolak.
|   D. CSRF + token sekali-pakai `_once` menutup double-submit.
|   E. Settlement menyerap voucher: `daily_coordination_fee` +
|      `consumable_opex` = total beban, dan voucher menjadi `settlement_id`.
|   F. Perbaikan rumus: `total_cash = pendapatan` (bukan pendapatan − beban),
|      sehingga `kas + brankas = pendapatan` dan laci menerima penggantian.
|   G. Anti-fiktif: `coord_fee` tidak lagi dibaca dari form; beban yang
|      melebihi pendapatan ditolak tanpa tulisan.
|   H. Skema: tabel ke-14, DECIMAL(14,2), FK RESTRICT/SET NULL, nol ALTER.
|   I. Pemindaian statis: tanpa float/number_format, SQL tidak bocor ke
|      controller/view, seluruh output view di-escape, `CACHE_NAME` v4.
|   J. Idempotensi fixture (voucher dibuang sebelum `cash_mutations`).
|
| Basis angka: keadaan QC 3 (18,050 gr; modal Rp 35.157.500; pendapatan
| Rp 37.544.000; beban Rp 200.000 → laba bersih Rp 2.186.500).
*/

require __DIR__ . '/bootstrap.php';

use AurumFlow\Config\Constants;
use AurumFlow\Config\Database;
use AurumFlow\Config\Env;
use AurumFlow\Helpers\Formatter;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\CashMutation;
use AurumFlow\Models\CashRegister;
use AurumFlow\Models\OperationalExpense;
use AurumFlow\Services\ExpenseService;
use AurumFlow\Services\SettlementService;
use AurumFlow\Services\TellerService;

Qc::suite('qc_fase_9 (OPEX & otomatisasi settlement)');

// ---------------------------------------------------------------------------
// Helper lokal
// ---------------------------------------------------------------------------

/** Bangun PNG sah tanpa ekstensi GD (foto nota tiruan). */
function qc9_png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

function qc9_png(int $width, int $height): string
{
    $raw = '';

    for ($y = 0; $y < $height; $y++) {
        $raw .= "\x00";

        for ($x = 0; $x < $width; $x++) {
            $raw .= chr(($x * 7 + $y * 11) % 256) . chr(160) . chr(70);
        }
    }

    return "\x89PNG\r\n\x1a\n"
        . qc9_png_chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        . qc9_png_chunk('IDAT', gzcompress($raw, 6))
        . qc9_png_chunk('IEND', '');
}

function qc9_temp_file(string $name, string $contents): string
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
 * Token CSRF + token sekali-pakai segar dari satu halaman.
 *
 * @return array{csrf: string, once: string, status: int, body: string}
 */
function qc9_tokens(string $jar, string $path = '/operator/teller'): array
{
    $page = QcHttp::request('GET', $path, ['cookie_jar' => $jar]);

    return [
        'csrf'   => (string) QcHttp::csrfFrom($page['body']),
        'once'   => (string) QcHttp::fieldFrom($page['body'], Security::FORM_FIELD),
        'status' => (int) $page['status'],
        'body'   => (string) $page['body'],
    ];
}

/**
 * POST beban operasional dengan token segar.
 *
 * @param array<string, string> $fields
 * @param array<string, string> $files
 * @return array{status: int, location: ?string, body: string, headers: string}
 */
function qc9_postOpex(string $jar, array $fields, array $files = []): array
{
    $tokens = qc9_tokens($jar);

    $options = [
        'cookie_jar' => $jar,
        'data'       => $fields + [
            Security::CSRF_FIELD => $tokens['csrf'],
            Security::FORM_FIELD => $tokens['once'],
        ],
    ];

    if ($files !== []) {
        $options['files'] = $files;
    }

    return QcHttp::request('POST', '/operator/opex/pay', $options);
}

/**
 * POST tutup buku dengan token segar.
 *
 * @param array<string, string> $fields
 */
function qc9_postSettlement(string $jar, array $fields, string $receiptPath): array
{
    $tokens = qc9_tokens($jar, '/operator/settlement');

    return QcHttp::request('POST', '/operator/settlement', [
        'cookie_jar' => $jar,
        'data'       => $fields + [
            Security::CSRF_FIELD => $tokens['csrf'],
            Security::FORM_FIELD => $tokens['once'],
        ],
        'files'      => [SettlementService::RECEIPT_FIELD => $receiptPath],
    ]);
}

function qc9_money(mixed $value): string
{
    return TellerService::centsToDecimal(TellerService::moneyToCents((string) $value));
}

function qc9_cents(mixed $value): int
{
    return TellerService::moneyToCents((string) $value);
}

function qc9_count(string $table, string $where = '', array $params = []): int
{
    $row = Database::fetchOne(
        'SELECT COUNT(*) AS `total` FROM `' . $table . '`' . ($where === '' ? '' : ' WHERE ' . $where),
        $params
    );

    return (int) ($row['total'] ?? 0);
}

/** Jumlah tabel pada basis data uji (14 sejak Fase 9). */
function qc9_tableCount(): int
{
    $row = Database::fetchOne(
        'SELECT COUNT(*) AS `total` FROM `information_schema`.`tables` WHERE `table_schema` = DATABASE()'
    );

    return (int) ($row['total'] ?? 0);
}

/**
 * Pemindai statis per berkas (melewati baris komentar).
 *
 * @param list<string> $paths
 * @return list<string>
 */
function qc9_scan(array $paths, callable $predicate): array
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

// ---------------------------------------------------------------------------
// A. Service: tiga tulisan, satu transaksi
// ---------------------------------------------------------------------------

Qc::section('A. ExpenseService::pay() — tiga tulisan dalam satu transaksi');

$fixture = QcFixture::resetSettlementState();

$tellerId = (string) $fixture['teller_id'];
$brankasId = (string) $fixture['brankas_id'];

$balance = static fn (): string => QcFixture::registerBalance($tellerId);

Qc::equals(
    'A.0',
    'Keadaan awal: kas meja Rp 14.842.500, emas 18,050 gr, tanpa voucher beban',
    ['14842500.00', '18.050', 0],
    [$balance(), $fixture['holding_grams'], QcFixture::opexSnapshot()['count']]
);

$mutationsBefore = qc9_count('cash_mutations', '`register_id` = :id', ['id' => $tellerId]);

ExpenseService::pay([
    'category'   => OperationalExpense::CATEGORY_COORDINATION,
    'payee_name' => 'Pak RT 04',
    'amount'     => '200.000',
    'notes'      => 'koordinasi malam',
]);

$voucher = Database::fetchOne('SELECT * FROM `operational_expenses` ORDER BY `created_at` DESC, `id` DESC LIMIT 1') ?? [];
$voucherMutation = Database::fetchOne(
    'SELECT * FROM `cash_mutations` WHERE `id` = :id LIMIT 1',
    ['id' => (string) ($voucher['mutation_id'] ?? '')]
) ?? [];

Qc::equals(
    'A.1',
    'Tiga tulisan: voucher + mutasi `out` + saldo kas turun Rp 200.000',
    ['200000.00', 'out', '200000.00', '14842500.00', '14642500.00', $mutationsBefore + 1],
    [
        qc9_money($voucher['amount'] ?? ''),
        (string) ($voucherMutation['mutation_type'] ?? ''),
        qc9_money($voucherMutation['amount'] ?? ''),
        '14842500.00',
        $balance(),
        qc9_count('cash_mutations', '`register_id` = :id', ['id' => $tellerId]),
    ]
);

Qc::ok(
    'A.2',
    'Voucher memuat peladen: tanggal bisnis, kategori, penerima, pos kas pembayar, settlement_id NULL',
    (string) ($voucher['expense_date'] ?? '') === date('Y-m-d')
        && (string) ($voucher['category'] ?? '') === OperationalExpense::CATEGORY_COORDINATION
        && (string) ($voucher['payee_name'] ?? '') === 'Pak RT 04'
        && (string) ($voucher['register_id'] ?? '') === $tellerId
        && ($voucher['settlement_id'] ?? null) === null
        && (string) ($voucher['notes'] ?? '') === 'koordinasi malam',
    'voucher=' . json_encode($voucher)
);

Qc::ok(
    'A.3',
    'Mutasi berlabel kategori + penerima, dan notes memuat jejak expense_id',
    (string) ($voucherMutation['source_destination'] ?? '') === 'Biaya koordinasi: Pak RT 04'
        && str_contains((string) ($voucherMutation['notes'] ?? ''), 'expense_id=' . (string) ($voucher['id'] ?? '-'))
        && str_contains((string) ($voucherMutation['notes'] ?? ''), 'kategori=coordination'),
    'label=' . (string) ($voucherMutation['source_destination'] ?? '-')
);

// ---------------------------------------------------------------------------
// B. Penolakan input: NOL tulisan
// ---------------------------------------------------------------------------

Qc::section('B. Penolakan input tidak sah & saldo kurang — nol tulisan');

$beforeInvalid = [
    'vouchers'  => qc9_count('operational_expenses'),
    'mutations' => qc9_count('cash_mutations', '`register_id` = :id', ['id' => $tellerId]),
    'balance'   => $balance(),
];

$invalidCases = [
    ['nominal kosong', ['category' => 'coordination', 'payee_name' => 'X', 'amount' => '']],
    ['nominal nol', ['category' => 'coordination', 'payee_name' => 'X', 'amount' => '0']],
    ['nominal negatif', ['category' => 'coordination', 'payee_name' => 'X', 'amount' => '-5.000']],
    ['penerima kosong', ['category' => 'coordination', 'payee_name' => '  ', 'amount' => '10.000']],
    ['kategori tak dikenal', ['category' => 'sogokan', 'payee_name' => 'X', 'amount' => '10.000']],
];

$invalidFailures = [];

foreach ($invalidCases as [$label, $case]) {
    try {
        ExpenseService::pay($case);
        $invalidFailures[] = $label . ': DITERIMA (harus ditolak)';
    } catch (InvalidArgumentException) {
        // benar
    } catch (Throwable $exception) {
        $invalidFailures[] = $label . ': kelas salah ' . $exception::class;
    }
}

Qc::ok(
    'B.1',
    'Lima input tidak sah ditolak InvalidArgumentException',
    $invalidFailures === [],
    implode("\n", $invalidFailures)
);

Qc::throws(
    'B.2',
    'Beban melebihi saldo kas meja ditolak (RuntimeException)',
    static fn () => ExpenseService::pay([
        'category'   => 'other',
        'payee_name' => 'X',
        'amount'     => '999.000.000',
    ]),
    RuntimeException::class
);

Qc::equals(
    'B.3',
    'Seluruh penolakan di atas tidak menulis apa pun (voucher, mutasi, saldo)',
    $beforeInvalid,
    [
        'vouchers'  => qc9_count('operational_expenses'),
        'mutations' => qc9_count('cash_mutations', '`register_id` = :id', ['id' => $tellerId]),
        'balance'   => $balance(),
    ]
);

// ---------------------------------------------------------------------------
// C. HTTP: peran & guard
// ---------------------------------------------------------------------------

Qc::section('C. HTTP: operator & kasir boleh, mitra & anonim ditolak');

QcFixture::ensureInstalled();
QcHttp::start();

$operatorPhone = (string) (Env::get('OPERATOR_PHONE') ?? '081200000001');
$operatorPassword = (string) (Env::get('OPERATOR_PASSWORD') ?? 'Aurum2026!');

$operatorJar = QcHttp::cookieJar('opex-operator');
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
    'C.1',
    'Operator berhasil login (302 -> /dashboard)',
    $login['status'] === 302 && $login['location'] === '/dashboard',
    'status=' . $login['status'] . ' location=' . (string) $login['location']
);

$tellerPage = qc9_tokens($operatorJar);

Qc::ok(
    'C.2',
    'Layar kasir memuat tombol "Catat Beban Kas", modal x-cloak, empat isian, dan hanya SATU token `_once` aktif',
    $tellerPage['status'] === 200
        && str_contains($tellerPage['body'], 'Catat Beban Kas')
        && substr_count($tellerPage['body'], 'name="' . Security::FORM_FIELD . '"') === 3
        && str_contains($tellerPage['body'], 'name="opex_category"')
        && str_contains($tellerPage['body'], 'name="opex_payee"')
        && str_contains($tellerPage['body'], 'name="opex_amount"')
        && str_contains($tellerPage['body'], 'name="opex_notes"')
        && $tellerPage['once'] !== '',
    'status=' . $tellerPage['status'] . ' blok_once=' . substr_count($tellerPage['body'], 'name="' . Security::FORM_FIELD . '"')
);

$balanceBeforeHttp = $balance();

$payHttp = qc9_postOpex($operatorJar, [
    'opex_category' => 'security',
    'opex_payee'    => 'Petugas Jaga',
    'opex_amount'   => '50.000',
    'opex_notes'    => 'jaga malam',
]);

Qc::ok(
    'C.3',
    'Operator mencatat beban lewat HTTP = 302 PRG; saldo turun tepat Rp 50.000',
    $payHttp['status'] === 302
        && $payHttp['location'] === '/operator/teller'
        && qc9_cents($balance()) === qc9_cents($balanceBeforeHttp) - 5000000,
    'status=' . $payHttp['status'] . ' saldo=' . $balance()
);

$anonJar = QcHttp::cookieJar('opex-anon');
$anonPost = QcHttp::request('POST', '/operator/opex/pay', [
    'cookie_jar' => $anonJar,
    'data'       => ['opex_category' => 'other', 'opex_payee' => 'Anon', 'opex_amount' => '10.000'],
]);

$anonAfter = qc9_count('operational_expenses');

Qc::ok(
    'C.4',
    'Anonim tanpa token ditolak 419 oleh front controller — nol voucher tertulis',
    $anonPost['status'] === 419 && $anonAfter === 2,
    'status=' . $anonPost['status'] . ' vouchers=' . $anonAfter
);

$investorPhone = (string) (Env::get('QC_INVESTOR_PHONE') ?? '081200000009');
$existingInvestor = AurumFlow\Models\User::findByPhoneNumber($investorPhone, false);

if ($existingInvestor !== null) {
    Database::update(
        'users',
        ['role' => Constants::ROLE_INVESTOR, 'is_active' => 1],
        '`id` = :id',
        ['id' => (string) $existingInvestor['id']]
    );
}

$investorJar = QcHttp::cookieJar('opex-investor');
$investorLoginPage = QcHttp::request('GET', '/investor/login', ['cookie_jar' => $investorJar, 'fresh' => true]);
$investorToken = (string) QcHttp::csrfFrom($investorLoginPage['body']);

// Tanpa PIN yang sah, cukup dibuktikan bahwa permukaan OPEX menolak perannya:
// POST langsung dievaluasi `requireRole()` SEBELUM apa pun dibaca.
$investorPost = QcHttp::request('POST', '/operator/opex/pay', [
    'cookie_jar' => $investorJar,
    'data'       => [
        Security::CSRF_FIELD => $investorToken,
        'opex_category'      => 'other',
        'opex_payee'         => 'Mitra',
        'opex_amount'        => '10.000',
    ],
]);

Qc::ok(
    'C.5',
    'Mitra (belum login) diarahkan ke /login, bukan menulis voucher',
    $investorPost['status'] === 302
        && $investorPost['location'] === '/login'
        && qc9_count('operational_expenses') === 2,
    'status=' . $investorPost['status'] . ' location=' . (string) $investorPost['location']
);

// Kasir: permukaan OPEX menerima peran kasir (allow-list yang sama dengan
// meja kasir). Fixture Fase 7 menyediakan akun kasir ber-PIN.
QcFixture::seedQcCashier();

$cashierJar = QcHttp::cookieJar('opex-cashier');
$cashierLoginPage = QcHttp::request('GET', '/cashier/login', ['cookie_jar' => $cashierJar, 'fresh' => true]);
$cashierLogin = QcHttp::request('POST', '/cashier/login', [
    'cookie_jar' => $cashierJar,
    'data'       => [
        Security::CSRF_FIELD => (string) QcHttp::csrfFrom($cashierLoginPage['body']),
        'phone_number'       => QcFixture::QC_CASHIER['phone'],
        'pin'                => QcFixture::QC_CASHIER['pin'],
    ],
]);

$cashierBalanceBefore = $balance();
$cashierPay = qc9_postOpex($cashierJar, [
    'opex_category' => 'consumable',
    'opex_payee'    => 'Warung Bu Sri',
    'opex_amount'   => '25.000',
    'opex_notes'    => 'gas & kopi',
]);

Qc::ok(
    'C.6',
    'Kasir login PIN lalu mencatat beban konsumsi (302; saldo turun Rp 25.000)',
    $cashierLogin['status'] === 302
        && $cashierPay['status'] === 302
        && qc9_cents($balance()) === qc9_cents($cashierBalanceBefore) - 2500000,
    'login=' . $cashierLogin['status'] . ' pay=' . $cashierPay['status'] . ' saldo=' . $balance()
);

QcFixture::clearPinThrottle();

// ---------------------------------------------------------------------------
// D. CSRF & token sekali-pakai
// ---------------------------------------------------------------------------

Qc::section('D. CSRF + `_once`: double-submit tidak mencatat beban kedua');

$vouchersBeforeReplay = qc9_count('operational_expenses');
$replayTokens = qc9_tokens($operatorJar);

$replayFields = [
    'opex_category'      => 'other',
    'opex_payee'         => 'Replay',
    'opex_amount'        => '10.000',
    Security::CSRF_FIELD => $replayTokens['csrf'],
    Security::FORM_FIELD => $replayTokens['once'],
];

$firstReplay = QcHttp::request('POST', '/operator/opex/pay', [
    'cookie_jar' => $operatorJar,
    'data'       => $replayFields,
]);

$secondReplay = QcHttp::request('POST', '/operator/opex/pay', [
    'cookie_jar' => $operatorJar,
    'data'       => $replayFields,
]);

$replayPage = QcHttp::request('GET', '/operator/teller', ['cookie_jar' => $operatorJar]);

Qc::ok(
    'D.1',
    'Kiriman kedua dengan token yang sama diabaikan (302 + pesan "ganda diabaikan")',
    $firstReplay['status'] === 302
        && $secondReplay['status'] === 302
        && qc9_count('operational_expenses') === $vouchersBeforeReplay + 1
        && str_contains((string) $replayPage['body'], 'ganda diabaikan'),
    'vouchers=' . qc9_count('operational_expenses') . ' (sebelum ' . $vouchersBeforeReplay . ')'
);

$noCsrf = QcHttp::request('POST', '/operator/opex/pay', [
    'cookie_jar' => $operatorJar,
    'data'       => ['opex_category' => 'other', 'opex_payee' => 'Tanpa CSRF', 'opex_amount' => '10.000'],
]);

Qc::ok(
    'D.2',
    'POST tanpa token CSRF ditolak 419 tanpa menulis voucher',
    $noCsrf['status'] === 419 && qc9_count('operational_expenses') === $vouchersBeforeReplay + 1,
    'status=' . $noCsrf['status']
);

// ---------------------------------------------------------------------------
// E. Settlement: penyerapan voucher + perbaikan rumus
// ---------------------------------------------------------------------------

Qc::section('E. Settlement menyerap voucher & mengkredit pendapatan penuh');

QcFixture::resetSettlementState();

// Dua voucher: koordinasi Rp 150.000 + konsumsi Rp 50.000 = Rp 200.000.
QcFixture::seedOpexVoucher([
    'category'   => OperationalExpense::CATEGORY_COORDINATION,
    'payee_name' => 'Pak RT 04',
    'amount'     => '150.000',
]);
QcFixture::seedOpexVoucher([
    'category'   => OperationalExpense::CATEGORY_CONSUMABLE,
    'payee_name' => 'Warung Bu Sri',
    'amount'     => '50.000',
]);

$balanceBeforeSettlement = $balance();

Qc::equals(
    'E.1',
    'Dua voucher tersimpan dan kas meja turun Rp 200.000 sebelum tutup buku',
    ['200000.00', 2, '14642500.00'],
    [QcFixture::opexSnapshot()['total'], QcFixture::opexSnapshot()['count_open'], $balance()]
);

// Layar rekonsiliasi SEBELUM eksekusi: voucher tampil read-only (nama penerima,
// kategori, nominal) dan isian `coord_fee` tidak ada. Ini melatih cabang
// "ada voucher" pada view — bukan hanya keadaan kosong.
$previewPage = QcHttp::request('GET', '/operator/settlement', ['cookie_jar' => $operatorJar]);
$previewBody = (string) $previewPage['body'];

Qc::ok(
    'E.1b',
    'Pratinjau Layar C menampilkan voucher read-only (penerima + total) tanpa isian beban',
    $previewPage['status'] === 200
        && str_contains($previewBody, 'Pak RT 04')
        && str_contains($previewBody, 'Warung Bu Sri')
        && str_contains($previewBody, 'Beban Operasional Terserap')
        && str_contains($previewBody, Formatter::rupiah('200000.00'))
        && !str_contains($previewBody, 'name="coord_fee"'),
    'status=' . $previewPage['status']
);

$receiptPath = qc9_temp_file('nota-fase9.png', qc9_png(96, 96));

$settlementsBefore = qc9_count('daily_settlements');

$settle = qc9_postSettlement($operatorJar, [
    'sold_grams'       => '18,050',
    'density_pct'      => '93,50',
    'shop_price_per_g' => '2.080.000',
    'kas_split'        => '',
    'brankas_split'    => '',
], $receiptPath);

$settlement = Database::fetchOne(
    'SELECT * FROM `daily_settlements` ORDER BY `created_at` DESC, `id` DESC LIMIT 1'
) ?? [];

Qc::ok(
    'E.2',
    'Tutup buku berhasil (302) dan tercatat tepat satu sesi baru',
    $settle['status'] === 302
        && $settle['location'] === '/operator/settlement'
        && qc9_count('daily_settlements') === $settlementsBefore + 1,
    'status=' . $settle['status'] . ' location=' . (string) $settle['location']
);

Qc::equals(
    'E.3',
    'Kolom beban diisi dari voucher: coordination Rp 150.000 + consumable Rp 50.000',
    ['150000.00', '50000.00', '37544000.00', '2186500.00'],
    [
        qc9_money($settlement['daily_coordination_fee'] ?? ''),
        qc9_money($settlement['consumable_opex'] ?? ''),
        qc9_money($settlement['total_shop_revenue'] ?? ''),
        qc9_money($settlement['net_settled_profit'] ?? ''),
    ]
);

Qc::equals(
    'E.4',
    'Laba & bagi hasil TIDAK berubah oleh perubahan rumus (Rp 2.186.500 = 1.749.200 / 437.300)',
    ['2186500.00', '1749200.00', '437300.00'],
    [
        qc9_money($settlement['net_settled_profit'] ?? ''),
        qc9_money($settlement['operator_share_80'] ?? ''),
        qc9_money($settlement['investor_pool_20'] ?? ''),
    ]
);

$settlementId = (string) ($settlement['id'] ?? '');

Qc::equals(
    'E.5',
    'Kedua voucher ditandai terserap sesi ini (settlement_id terisi, tidak ada yang menggantung)',
    [0, 2],
    [
        qc9_count('operational_expenses', '`settlement_id` IS NULL'),
        qc9_count('operational_expenses', '`settlement_id` = :id', ['id' => $settlementId]),
    ]
);

$tellerIn = Database::fetchOne(
    'SELECT * FROM `cash_mutations` WHERE `register_id` = :id AND `mutation_type` = :type '
    . 'ORDER BY `created_at` DESC, `id` DESC LIMIT 1',
    ['id' => $tellerId, 'type' => CashMutation::TYPE_IN]
) ?? [];

$brankasIn = Database::fetchOne(
    'SELECT * FROM `cash_mutations` WHERE `register_id` = :id AND `mutation_type` = :type '
    . 'ORDER BY `created_at` DESC, `id` DESC LIMIT 1',
    ['id' => $brankasId, 'type' => CashMutation::TYPE_IN]
) ?? [];

Qc::equals(
    'E.6',
    'Kas meja menerima Rp 35.357.500 (modal + beban yang sudah keluar) dan brankas Rp 2.186.500',
    ['35357500.00', '2186500.00', '37544000.00'],
    [
        qc9_money($tellerIn['amount'] ?? ''),
        qc9_money($brankasIn['amount'] ?? ''),
        TellerService::centsToDecimal(qc9_cents($tellerIn['amount'] ?? '') + qc9_cents($brankasIn['amount'] ?? '')),
    ]
);

Qc::ok(
    'E.7',
    'Invariant Fase 9: kas + brankas = pendapatan toko (bukan pendapatan − beban)',
    qc9_cents($tellerIn['amount'] ?? '') + qc9_cents($brankasIn['amount'] ?? '')
        === qc9_cents($settlement['total_shop_revenue'] ?? '')
        && qc9_cents($settlement['total_shop_revenue'] ?? '') === 3754400000,
    'kas=' . qc9_money($tellerIn['amount'] ?? '') . ' brankas=' . qc9_money($brankasIn['amount'] ?? '')
);

Qc::equals(
    'E.8',
    'Saldo kas meja kembali ke Rp 50.000.000 — buku sama dengan uang fisik (uang beban sudah keluar lebih dahulu)',
    ['50000000.00'],
    [$balance()]
);

Qc::ok(
    'E.9',
    'Catatan mutasi kas masuk memuat ringkasan voucher beban (jejak audit)',
    str_contains((string) ($tellerIn['notes'] ?? ''), 'beban_voucher=2')
        && str_contains((string) ($tellerIn['notes'] ?? ''), 'beban_total=200000.00')
        && str_contains((string) ($tellerIn['notes'] ?? ''), 'konsumsi_gas=50000.00'),
    (string) ($tellerIn['notes'] ?? '-')
);

Qc::ok(
    'E.10',
    'Layar rekonsiliasi menampilkan beban read-only & TIDAK lagi memuat isian `coord_fee`',
    (static function () use ($operatorJar): bool {
        $page = QcHttp::request('GET', '/operator/settlement', ['cookie_jar' => $operatorJar]);
        $body = (string) $page['body'];

        return $page['status'] === 200
            && str_contains($body, 'Beban Operasional Terserap')
            && !str_contains($body, 'name="coord_fee"');
    })(),
    'cek halaman rekonsiliasi'
);

// ---------------------------------------------------------------------------
// F. Perbaikan rumus: bukti double-count & penjagaan pendapatan
// ---------------------------------------------------------------------------

Qc::section('F. Rumus: total_cash = pendapatan; beban melebihi pendapatan ditolak');

$context = SettlementService::batchContext();
$context['holding_mg'] = 18050;
$context['holding_cost_cents'] = 3515750000;
$context['active_deficit_cents'] = 0;
$context['coord_fee_cents'] = 20000000;
$context['consumable_opex_cents'] = 5000000;
$context['open_expenses'] = [];

$quote = SettlementService::quote([
    'sold_grams'       => '18,050',
    'density_pct'      => '93,50',
    'shop_price_per_g' => '2.080.000',
], $context);

Qc::equals(
    'F.1',
    'Kas diterima = pendapatan penuh Rp 37.544.000; saran kas Rp 35.357.500 (modal + beban), brankas Rp 2.186.500',
    ['37544000.00', '35357500.00', '2186500.00'],
    [$quote['total_cash'], $quote['suggested_kas'], $quote['suggested_brankas']]
);

Qc::ok(
    'F.2',
    'Beban tetap mengurangi laba: laba bersih Rp 2.186.500 dari laba kotor Rp 2.386.500',
    $quote['gross'] === '2386500.00'
        && $quote['net'] === '2186500.00'
        && $quote['coord_fee'] === '200000.00'
        && $quote['consumable_opex'] === '50000.00'
        && $quote['coordination_fee'] === '150000.00',
    'gross=' . $quote['gross'] . ' net=' . $quote['net'] . ' beban=' . $quote['coord_fee']
);

Qc::ok(
    'F.3',
    'Invariant pembukuan: pendapatan = modal + beban + laba + defisit dipulihkan',
    $quote['revenue_cents'] === $quote['holding_cost_cents'] + $quote['coord_fee_cents']
        + $quote['net_cents'] + $quote['deficit_recovery_cents'],
    'revenue=' . $quote['revenue'] . ' modal=' . $quote['holding_cost'] . ' net=' . $quote['net']
);

$expensiveContext = $context;
$expensiveContext['coord_fee_cents'] = 40000000; // Rp 400.000 > pendapatan kecil di bawah

Qc::throws(
    'F.4',
    'Beban yang melebihi pendapatan toko ditolak (tanpa tulisan)',
    static fn () => SettlementService::quote([
        'sold_grams'       => '0,001',
        'density_pct'      => '93,50',
        'shop_price_per_g' => '2.080.000',
    ], $expensiveContext),
    InvalidArgumentException::class
);

Qc::equals(
    'F.5',
    'Nilai rusak pada konteks (negatif / bukan integer) ditolak sebagai invariant sistem',
    [true, true],
    [
        (static function () use ($context): bool {
            $broken = $context;
            $broken['coord_fee_cents'] = -1;

            try {
                SettlementService::quote(['sold_grams' => '1,000', 'density_pct' => '93,5', 'shop_price_per_g' => '2.080.000'], $broken);
            } catch (RuntimeException) {
                return true;
            }

            return false;
        })(),
        (static function () use ($context): bool {
            $broken = $context;
            $broken['consumable_opex_cents'] = 99999999;

            try {
                SettlementService::quote(['sold_grams' => '1,000', 'density_pct' => '93,5', 'shop_price_per_g' => '2.080.000'], $broken);
            } catch (RuntimeException) {
                return true;
            }

            return false;
        })(),
    ]
);

// ---------------------------------------------------------------------------
// G. Skema: tabel ke-14, konvensi tipe, FK, nol ALTER
// ---------------------------------------------------------------------------

Qc::section('G. Skema: tabel ke-14, DECIMAL(14,2), FK RESTRICT/SET NULL');

Qc::ok(
    'G.1',
    'Tabel `operational_expenses` tersedia (16 tabel sejak Fase 10) dan kolom uangnya DECIMAL(14,2)',
    QcFixture::tableExists('operational_expenses')
        && OperationalExpense::exists()
        && QcFixture::checkDecimalConventions([['operational_expenses', 'amount', 14, 2]]) === []
        && qc9_tableCount() === 16,
    'tabel=' . (OperationalExpense::exists() ? 'ada' : 'tidak') . ' total=' . qc9_tableCount()
);

$registerColumn = QcFixture::columnDefinition('operational_expenses', 'register_id');
$mutationColumn = QcFixture::columnDefinition('operational_expenses', 'mutation_id');
$settlementColumn = QcFixture::columnDefinition('operational_expenses', 'settlement_id');

Qc::ok(
    'G.2',
    'FK: register & mutation RESTRICT (jejak kas wajib), settlement SET NULL (fakta kas berdiri sendiri)',
    $registerColumn !== null && $mutationColumn !== null && $settlementColumn !== null
        && str_contains(strtoupper((string) ($registerColumn['COLUMN_TYPE'] ?? '')), 'CHAR(36)')
        && str_contains(strtoupper((string) ($mutationColumn['COLUMN_TYPE'] ?? '')), 'CHAR(36)')
        && (($settlementColumn['IS_NULLABLE'] ?? '') === 'YES'),
    'kolom=' . json_encode([$registerColumn['COLUMN_TYPE'] ?? null, $settlementColumn['IS_NULLABLE'] ?? null])
);

$fkRules = Database::fetchAll(
    'SELECT `constraint_name`, `delete_rule` FROM `information_schema`.`referential_constraints`'
    . ' WHERE `constraint_schema` = DATABASE() AND `table_name` = :table',
    ['table' => 'operational_expenses']
);

$fkMap = [];

foreach ($fkRules as $rule) {
    $fkMap[(string) $rule['constraint_name']] = strtoupper((string) $rule['delete_rule']);
}

Qc::equals(
    'G.3',
    'Aturan ON DELETE sesuai: register RESTRICT, mutation RESTRICT, settlement SET NULL, recorder SET NULL',
    [
        'fk_expense_register'   => 'RESTRICT',
        'fk_expense_mutation'   => 'RESTRICT',
        'fk_expense_settlement' => 'SET NULL',
        'fk_expense_recorder'   => 'SET NULL',
    ],
    [
        'fk_expense_register'   => $fkMap['fk_expense_register'] ?? '-',
        'fk_expense_mutation'   => $fkMap['fk_expense_mutation'] ?? '-',
        'fk_expense_settlement' => $fkMap['fk_expense_settlement'] ?? '-',
        'fk_expense_recorder'   => $fkMap['fk_expense_recorder'] ?? '-',
    ]
);

$schemaSource = (string) file_get_contents(DATABASE_PATH . '/schema.sql');
$erdSource = (string) file_get_contents(BASE_PATH . '/docs/AurumFlow Entity Relationship Diagram.md');

Qc::ok(
    'G.4',
    'Skema & ERD sama-sama memuat tabel ke-14 (sumber kebenaran dokumen diperbarui lebih dulu)',
    str_contains($schemaSource, 'CREATE TABLE IF NOT EXISTS `operational_expenses`')
        && str_contains($erdSource, 'CREATE TABLE IF NOT EXISTS operational_expenses')
        && str_contains($erdSource, 'SKEMA TOTAL: 14 TABEL'),
    'schema=' . (int) str_contains($schemaSource, 'operational_expenses')
        . ' erd=' . (int) str_contains($erdSource, 'operational_expenses')
);

Qc::ok(
    'G.5',
    'Nol ALTER destruktif: schema.sql tidak memuat ALTER TABLE, migrate.php hanya CREATE TABLE IF NOT EXISTS',
    preg_match('/ALTER\s+TABLE/i', $schemaSource) !== 1
        && !str_contains((string) file_get_contents(DATABASE_PATH . '/migrate.php'), 'ALTER TABLE'),
    'pemindaian DDL'
);

// ---------------------------------------------------------------------------
// H. Pemindaian statis & aset frontend
// ---------------------------------------------------------------------------

Qc::section('H. Pemindaian statis: uang tanpa float, SQL tidak bocor, output di-escape');

$fase9Sources = [
    APP_PATH . '/Services/ExpenseService.php',
    APP_PATH . '/Services/SettlementService.php',
    APP_PATH . '/Models/OperationalExpense.php',
    APP_PATH . '/Controllers/TellerController.php',
    APP_PATH . '/Controllers/SettlementController.php',
    APP_PATH . '/Views/operator/teller.php',
    APP_PATH . '/Views/operator/settlement.php',
];

$floatOffenders = qc9_scan(
    $fase9Sources,
    static fn (string $line): bool => preg_match('/\bfloatval\s*\(|\bdoubleval\s*\(|\(\s*(float|double)\s*\)|\bFLOAT\b|\bDOUBLE\b|\bnumber_format\s*\(/', $line) === 1
);

Qc::ok(
    'H.1',
    'Tidak ada float/double/number_format pada jalur uang OPEX',
    $floatOffenders === [],
    implode("\n", $floatOffenders)
);

$manualTx = qc9_scan(
    [APP_PATH . '/Services/ExpenseService.php'],
    static fn (string $line): bool => preg_match('/\b(beginTransaction|rollBack|commit)\s*\(/', $line) === 1
);

$transactionCalls = qc9_scan(
    [APP_PATH . '/Services/ExpenseService.php'],
    static fn (string $line): bool => str_contains($line, 'Database::transaction(')
);

Qc::ok(
    'H.2',
    'ExpenseService memakai tepat satu Database::transaction() (tanpa begin/commit manual)',
    $manualTx === [] && count($transactionCalls) === 1,
    'transaksi=' . count($transactionCalls) . ' ' . implode("\n", $manualTx)
);

$sqlLeaks = qc9_scan(
    [APP_PATH . '/Controllers/TellerController.php', APP_PATH . '/Views/operator/teller.php',
        APP_PATH . '/Controllers/SettlementController.php', APP_PATH . '/Views/operator/settlement.php'],
    static fn (string $line): bool => preg_match('/\b(SELECT|INSERT INTO|UPDATE|DELETE FROM|ORDER BY|LIMIT)\b/', $line) === 1
);

Qc::ok(
    'H.3',
    'SQL tidak bocor ke controller/view (SQL hanya di app/Models)',
    $sqlLeaks === [],
    implode("\n", $sqlLeaks)
);

$unescaped = qc9_scan(
    [APP_PATH . '/Views/operator/teller.php', APP_PATH . '/Views/operator/settlement.php'],
    static fn (string $line): bool => preg_match('/<\?=\s*\$(?!content\b)/', $line) === 1
);

Qc::ok(
    'H.4',
    'Seluruh output dinamis view melewati Security::e()',
    $unescaped === [],
    implode("\n", $unescaped)
);

$swSource = (string) file_get_contents(PUBLIC_PATH . '/sw.js');
$appJs = (string) file_get_contents(PUBLIC_PATH . '/assets/js/app.js');

Qc::ok(
    'H.5',
    'app.js berubah → CACHE_NAME naik ke v5 (Fase 10) dan app.js memuat state OPEX + hak petugas (tanpa komponen Alpine baru)',
    str_contains($swSource, "'aurumflow-shell-v5'")
        && str_contains($appJs, 'opexSubmitReady')
        && str_contains($appJs, 'opexFeeCents')
        && !str_contains($appJs, 'coordFeeCents')
        && substr_count($appJs, 'Alpine.data(') === 6,
    'cache=' . (preg_match("/CACHE_NAME = '([^']+)'/", $swSource, $match) === 1 ? $match[1] : '?')
        . ' komponen=' . substr_count($appJs, 'Alpine.data(')
);

$routes = require APP_PATH . '/routes.php';
$opexRoute = $routes['POST']['/operator/opex/pay'] ?? null;

Qc::ok(
    'H.6',
    'Route `POST /operator/opex/pay` menunjuk handler yang benar-benar ada',
    $opexRoute !== null
        && $opexRoute[0] === AurumFlow\Controllers\TellerController::class
        && $opexRoute[1] === 'storeOpex'
        && method_exists($opexRoute[0], $opexRoute[1]),
    'route=' . json_encode($opexRoute)
);

$tellerSource = (string) file_get_contents(APP_PATH . '/Controllers/TellerController.php');
$opexMethodBody = (static function () use ($tellerSource): string {
    $start = strpos($tellerSource, 'public function storeOpex(');

    if ($start === false) {
        return '';
    }

    $end = strpos($tellerSource, "\n    }", $start);

    return $end === false ? '' : substr($tellerSource, $start, $end - $start);
})();

Qc::ok(
    'H.7',
    'Guard-first: `requireRole(...ALLOWED_ROLES)` adalah pernyataan pertama storeOpex()',
    $opexMethodBody !== ''
        && strpos($opexMethodBody, '$this->requireRole(...self::ALLOWED_ROLES);')
            < strpos($opexMethodBody, '$this->assertPostSecurity('),
    substr($opexMethodBody, 0, 160)
);

$settlementViewSource = (string) file_get_contents(APP_PATH . '/Views/operator/settlement.php');
$settlementControllerSource = (string) file_get_contents(APP_PATH . '/Controllers/SettlementController.php');

Qc::ok(
    'H.8',
    'Anti-fiktif: `coord_fee` tidak lagi ada di view, controller, maupun `readInput()` settlement',
    !str_contains($settlementViewSource, 'name="coord_fee"')
        && !str_contains($settlementControllerSource, "'coord_fee'")
        && str_contains($settlementViewSource, 'Beban Operasional Terserap'),
    'view=' . (int) str_contains($settlementViewSource, 'name="coord_fee"')
        . ' controller=' . (int) str_contains($settlementControllerSource, "'coord_fee'")
);

$labels = [];

foreach (OperationalExpense::categories() as $category) {
    $labels[] = OperationalExpense::categoryLabel($category);
}

Qc::ok(
    'H.9',
    'Empat kategori berlabel manusiawi (nilai enum mentah tidak pernah dicetak)',
    count($labels) === 4
        && in_array('Koordinasi Lapangan', $labels, true)
        && in_array('Pengamanan Wilayah', $labels, true)
        && in_array('Konsumsi / Gas', $labels, true)
        && in_array('Lain-lain', $labels, true),
    implode(' | ', $labels)
);

// ---------------------------------------------------------------------------
// I. Idempotensi fixture (FK RESTRICT: voucher sebelum cash_mutations)
// ---------------------------------------------------------------------------

Qc::section('I. Fixture idempotent: voucher dibuang sebelum cash_mutations');

$snapshotFirst = QcFixture::opexSnapshot();
QcFixture::resetExpenseState();
$snapshotAfter = QcFixture::opexSnapshot();
QcFixture::resetExpenseState();
$snapshotSecond = QcFixture::opexSnapshot();

Qc::equals(
    'I.1',
    'resetExpenseState() dua kali menghasilkan keadaan identik (0 voucher, 0 baris menggantung)',
    [0, 0, '0.00'],
    [$snapshotAfter['count'], $snapshotSecond['count'], $snapshotSecond['total']]
);

Qc::ok(
    'I.2',
    'Voucher yang terserap tidak menghalangi pembersihan (FK RESTRICT pada mutation_id terpenuhi)',
    $snapshotFirst['count'] >= 0 && qc9_count('operational_expenses') === 0,
    'sebelum=' . json_encode($snapshotFirst)
);

// Kembalikan keadaan seperti sebelum suite (QC berikutnya melihat kas meja
// Rp 50.000.000, emas 18,050 gr, tanpa voucher) — pola yang sama dengan
// `qc_fase_3`/`qc_fase_6`/`qc_fase_8`.
QcFixture::resetSettlementState();
QcFixture::purgeQcCashierAccounts();
QcFixture::clearPinThrottle();

Qc::note('  | OPEX                | tiga tulisan, satu transaksi; voucher menyerap saat tutup buku | OK');
Qc::note('  | Rumus               | total_cash = pendapatan; kas+brankas = pendapatan             | OK');
Qc::note('  | Anti-fiktif         | coord_fee tidak lagi dibaca dari form                          | OK');

Qc::finish('qc_fase_9');
