<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Protokol QC Manual 6A — Modul Dana Talangan / Bridge Liquidity (Fase 6)
|--------------------------------------------------------------------------
| Implementasi otomatis "Protokol QC Manual 6A" pada dokumen
| docs/AurumFlow Roadmap and QC Documents.md (FASE 6A).
|
| Cakupan:
|   P6A.x  Protokol manual 6A (angka persis seperti dokumen Roadmap)
|   A.     Kalkulasi murni: fee nominal/persen, pembulatan, penyerapan FIFO
|   B.     Paritas parser (tarif fee vs kadar timbang air; nominal fee)
|   C.     Penerimaan talangan lewat HTTP: tiga tulisan, atomisitas, `_once`
|   D.     Auto-pay tutup buku: pokok + fee, satu mutasi `out` per talangan
|   E.     **Isolasi pool investor** (bukti diferensial: dengan vs tanpa talangan)
|   F.     Penjagaan saldo & batas input (422 tanpa tulisan)
|   G.     Kontrak view, pemindaian statis, dan route baru
|   H.     Integritas skema (tabel ke-10, nol ALTER pada tabel lama) & dokumen
|
| PERINGATAN: suite ini MENULIS ke database yang ditunjuk `.env`
| (bridge_loans, daily_settlements, hulu_transactions, cash_registers,
| cash_mutations, profit_distributions, capital_wallets, system_settings) DAN
| membuat/menghapus berkas di public/uploads/receipts/. Jangan arahkan ke
| database produksi. Konvensi exit code: 0 = lulus, 1 = gagal.
*/

require __DIR__ . '/bootstrap.php';

use AurumFlow\Config\App;
use AurumFlow\Config\Constants;
use AurumFlow\Config\Database;
use AurumFlow\Config\Env;
use AurumFlow\Helpers\Formatter;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\BridgeLoan;
use AurumFlow\Models\CapitalWallet;
use AurumFlow\Models\CashMutation;
use AurumFlow\Models\CashRegister;
use AurumFlow\Models\DailySettlement;
use AurumFlow\Models\HuluTransaction;
use AurumFlow\Models\ProfitDistribution;
use AurumFlow\Models\SystemSetting;
use AurumFlow\Models\User;
use AurumFlow\Services\BridgeLoanService;
use AurumFlow\Services\SettlementService;
use AurumFlow\Services\TellerService;

Qc::suite('Protokol QC Manual 6A — Dana Talangan / Bridge Liquidity (qc_fase_6.php)');

Qc::note('Sumber: docs/AurumFlow Roadmap and QC Documents.md — FASE 6A');
Qc::note('Basis angka: keadaan QC 3 (18,050 gr; modal Rp 35.157.500; pendapatan Rp 37.544.000).');
Qc::note('PERINGATAN: suite ini menulis ke DB pada .env dan ke public/uploads/receipts/.');

// ---------------------------------------------------------------------------
// Helper lokal
// ---------------------------------------------------------------------------

/**
 * Pemindai statis untuk daftar berkas eksplisit.
 *
 * `qc_scan_lines()` harness memindai seluruh app/ + public/ sekaligus dan
 * predikatnya hanya menerima isi baris (tanpa path), sehingga tidak dapat
 * dibatasi per berkas — pola yang sama dipakai QC Fase 2/3/5.
 *
 * @param list<string> $paths
 * @return list<string> daftar pelanggaran (file:baris)
 */
function qc6_scan(array $paths, callable $predicate): array
{
    $offenders = [];

    foreach ($paths as $path) {
        $contents = file_get_contents($path);

        if ($contents === false) {
            $offenders[] = str_replace(BASE_PATH . '/', '', $path) . ' tidak terbaca';

            continue;
        }

        $lines = preg_split('/\R/', $contents) ?: [];

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            // Baris komentar dilewati: dokumen kode boleh MENYEBUT larangan
            // tanpa dianggap melanggarnya.
            if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')
            ) {
                continue;
            }

            if ($predicate($line) === true) {
                $offenders[] = str_replace(BASE_PATH . '/', '', $path) . ':' . ($index + 1)
                    . ' — ' . $trimmed;
            }
        }
    }

    return $offenders;
}

/**
 * Potongan markup satu `<input>` berdasarkan `name`-nya (pola `qc3_input_block`).
 */
function qc6_input_block(string $view, string $name): ?string
{
    $chunks = preg_split('/<input\b/', $view);

    if ($chunks === false) {
        return null;
    }

    $needle = 'name="' . $name . '"';
    $found = null;

    foreach ($chunks as $chunk) {
        if (!str_contains($chunk, $needle)) {
            continue;
        }

        if ($found !== null) {
            return null;
        }

        $found = substr($chunk, 0, (int) strpos($chunk . '>', '>') + 1);
    }

    return $found;
}

/**
 * Ekspresi `x-show` pada elemen `<p>` yang memuat `$needle` (pola pemindaian
 * `qc6_input_block`).
 *
 * Dipakai assertion grup G4: yang diperiksa adalah **apa yang benar-benar
 * tertulis di markup**, bukan niat di komentar — sehingga pengikatan ulang baris
 * "Fee talangan" ke angka laba (bug UI yang dilaporkan pada uji manual Fase 6)
 * langsung tertangkap suite ini.
 */
function qc6_show_expression(string $view, string $needle): ?string
{
    $at = strpos($view, $needle);

    if ($at === false) {
        return null;
    }

    $tagStart = strrpos(substr($view, 0, $at), '<p');
    $tagEnd = strpos($view, '>', $at);

    if ($tagStart === false || $tagEnd === false) {
        return null;
    }

    $tag = substr($view, $tagStart, $tagEnd - $tagStart + 1);

    return preg_match('/x-show="([^"]+)"/', $tag, $matches) === 1 ? $matches[1] : null;
}

function qc6_png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

/**
 * PNG tiruan tanpa ekstensi GD (tidak tersedia di semua host).
 */
function qc6_png(int $width, int $height): string
{
    $raw = '';

    for ($y = 0; $y < $height; $y++) {
        $raw .= "\x00";

        for ($x = 0; $x < $width; $x++) {
            $raw .= chr(($x * 7 + $y * 5) % 256) . chr(190) . chr(70);
        }
    }

    return "\x89PNG\r\n\x1a\n"
        . qc6_png_chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        . qc6_png_chunk('IDAT', gzcompress($raw, 6))
        . qc6_png_chunk('IEND', '');
}

function qc6_temp_file(string $name, string $contents): string
{
    $directory = TEST_PATH . '/.tmp';

    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $path = $directory . '/' . $name;
    file_put_contents($path, $contents);

    return $path;
}

function qc6_money(mixed $value): string
{
    $text = trim((string) $value);

    if (preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', $text, $matches) !== 1) {
        return '0.00';
    }

    $sign = ($matches[1] ?? '') === '-' ? '-' : '';

    return $sign . $matches[2] . '.' . str_pad((string) ($matches[3] ?? ''), 2, '0', STR_PAD_RIGHT);
}

function qc6_cents(mixed $value): int
{
    return TellerService::moneyToCents(qc6_money($value));
}

/**
 * Token CSRF + token sekali-pakai segar dari sebuah halaman operator.
 *
 * @return array{csrf: string, once: string, status: int, body: string}
 */
function qc6_tokens(string $jar, string $path): array
{
    $page = QcHttp::request('GET', $path, ['cookie_jar' => $jar]);

    return [
        'csrf'   => (string) QcHttp::csrfFrom($page['body']),
        'once'   => (string) QcHttp::fieldFrom($page['body'], Security::FORM_FIELD),
        'status' => $page['status'],
        'body'   => $page['body'],
    ];
}

/**
 * Catat voucher beban Rp 200.000 lewat jalur kode nyata.
 *
 * Sejak Fase 9 beban operasional TIDAK lagi dikirim lewat form: tutup buku
 * menyerap voucher `operational_expenses` yang belum terserap. Basis angka
 * suite ini (beban Rp 200.000) dipertahankan lewat voucher ini.
 */
function qc6_seedExpense(): void
{
    QcFixture::seedOpexVoucher([
        'category'   => \AurumFlow\Models\OperationalExpense::CATEGORY_COORDINATION,
        'payee_name' => 'Aparat QC 6',
        'amount'     => '200.000',
    ]);
}

/**
 * Kirim form tutup buku dengan token segar (nota wajib).
 *
 * `$tokenPath` menentukan halaman asal token: token form (`_once`) milik SESI,
 * bukan milik halaman, sehingga layar kasir dapat dipakai ketika layar
 * rekonsiliasi tidak merender form (mis. tanpa emas `holding`) — CSRF-nya tetap
 * diperiksa, hanya saja tidak ada `_token` di halaman itu.
 *
 * @param array<string, string> $fields
 * @return array{status: int, headers: string, body: string, location: ?string}
 */
function qc6_settle(
    string $jar,
    array $fields,
    ?string $receiptPath = null,
    string $tokenPath = '/operator/settlement'
): array {
    $tokens = qc6_tokens($jar, $tokenPath);

    $options = [
        'cookie_jar' => $jar,
        'data'       => $fields + [
            Security::CSRF_FIELD => $tokens['csrf'],
            Security::FORM_FIELD => $tokens['once'],
        ],
    ];

    if ($receiptPath !== null) {
        $options['files'] = [SettlementService::RECEIPT_FIELD => $receiptPath];
    }

    return QcHttp::request('POST', '/operator/settlement', $options);
}

/**
 * Kirim form dana talangan.
 *
 * @param array<string, string> $fields
 * @param array{csrf: string, once: string}|null $tokens token yang dipakai ulang
 *        (untuk menguji kiriman ganda); `null` = ambil token segar.
 * @return array{status: int, headers: string, body: string, location: ?string}
 */
function qc6_talangan(string $jar, array $fields, ?array $tokens = null): array
{
    $tokens ??= qc6_tokens($jar, '/operator/teller');

    return QcHttp::request('POST', '/operator/teller/talangan', [
        'cookie_jar' => $jar,
        'data'       => $fields + [
            Security::CSRF_FIELD => $tokens['csrf'],
            Security::FORM_FIELD => $tokens['once'],
        ],
    ]);
}

/**
 * Baris talangan terbaru (untuk pemeriksaan jejak).
 *
 * @return array<string, mixed>|null
 */
function qc6_last_loan(): ?array
{
    return Database::fetchOne(
        'SELECT * FROM `bridge_loans` ORDER BY `created_at` ASC, `id` ASC LIMIT 1'
    );
}

/**
 * Mutasi kas satu pos berdasarkan tipe.
 *
 * @return list<array<string, mixed>>
 */
function qc6_mutations(string $registerId, string $type): array
{
    return Database::fetchAll(
        'SELECT `amount`, `source_destination`, `notes` FROM `cash_mutations` '
        . 'WHERE `register_id` = :register_id AND `mutation_type` = :type '
        . 'ORDER BY `created_at` ASC, `id` ASC',
        ['register_id' => $registerId, 'type' => $type]
    );
}

/**
 * Mutasi kas PENGEMBALIAN talangan pada satu pos.
 *
 * Disaring dari label, bukan dari jumlah baris: pos kas meja juga memuat mutasi
 * tebusan hulu (`out`) dan kas masuk settlement (`in`), sehingga "baris
 * terakhir" atau "semua baris" tidak dapat dipakai (pelajaran `created_at`
 * berpresisi detik, item O-1).
 *
 * @return list<array<string, mixed>>
 */
function qc6_bridge_mutations(string $registerId): array
{
    return array_values(array_filter(
        qc6_mutations($registerId, CashMutation::TYPE_OUT),
        static fn (array $row): bool => str_starts_with(
            (string) $row['source_destination'],
            BridgeLoanService::LABEL_OUT_PREFIX
        )
    ));
}

/**
 * Mutasi kas MASUK dari sesi tutup buku (label `Settlement ...`).
 *
 * @return list<array<string, mixed>>
 */
function qc6_settlement_in_mutations(string $registerId): array
{
    return array_values(array_filter(
        qc6_mutations($registerId, CashMutation::TYPE_IN),
        static fn (array $row): bool => str_starts_with(
            (string) $row['source_destination'],
            SettlementService::MUTATION_PREFIX
        )
    ));
}

/**
 * Sesi tutup buku terbaru yang belum ada pada daftar `$known`.
 *
 * @param list<string> $known
 * @return array<string, mixed>|null
 */
function qc6_new_settlement(array $known): ?array
{
    $rows = Database::fetchAll(
        'SELECT * FROM `daily_settlements` ORDER BY `created_at` ASC, `id` ASC'
    );

    foreach ($rows as $row) {
        if (!in_array((string) $row['id'], $known, true)) {
            return $row;
        }
    }

    return null;
}

/**
 * @return list<string>
 */
function qc6_settlement_ids(): array
{
    return array_map(
        static fn (array $row): string => (string) $row['id'],
        Database::fetchAll('SELECT `id` FROM `daily_settlements`')
    );
}

/**
 * Saldo `balance_profit` seluruh dompet: peta wallet_id => nilai kanonik.
 *
 * @return array<string, string>
 */
function qc6_wallet_profits(): array
{
    $map = [];

    foreach (Database::fetchAll('SELECT `id`, `balance_profit` FROM `capital_wallets`') as $row) {
        $map[(string) $row['id']] = qc6_money($row['balance_profit']);
    }

    ksort($map);

    return $map;
}

/**
 * Hak laba pengelola SETELAH fee talangan dikurangi (turunan pembukuan).
 *
 * `daily_settlements.operator_share_80` sengaja TIDAK diubah oleh Fase 6
 * (keputusan `dec-c71090762d066f0f`), sehingga hak bersih = kolom itu dikurangi
 * `Σ fee_absorbed` baris `bridge_loans` sesi tersebut.
 */
function qc6_operator_take_home(string $settlementId, string $operatorShare): string
{
    $absorbed = 0;

    foreach (BridgeLoan::listForSettlement($settlementId) as $loan) {
        $absorbed += qc6_cents($loan['fee_absorbed'] ?? '0.00');
    }

    return TellerService::centsToDecimal(qc6_cents($operatorShare) - $absorbed);
}

// ---------------------------------------------------------------------------
// Fixture & login
// ---------------------------------------------------------------------------

$account = QcFixture::ensureInstalled();
QcHttp::start();

$operatorJar = QcHttp::cookieJar('bridge-operator');
$loginForm = QcHttp::request('GET', '/login', ['cookie_jar' => $operatorJar, 'fresh' => true]);

$login = QcHttp::request('POST', '/login', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD => (string) QcHttp::csrfFrom($loginForm['body']),
        'phone_number'       => (string) $account['phone'],
        'password'           => (string) $account['password'],
    ],
]);

Qc::ok(
    '6A.0',
    'Operator berhasil login (302 -> /dashboard)',
    $login['status'] === 302 && $login['location'] === '/dashboard',
    'status=' . $login['status'] . ' location=' . (string) $login['location']
);

// Investor uji (untuk membuktikan jalur talangan hanya milik operator).
$investorPhone = (string) (Env::get('QC_INVESTOR_PHONE') ?? '081200000009');
$investorPassword = 'QcInvestor2026!';
$existingInvestor = User::findByPhoneNumber($investorPhone, false);

if ($existingInvestor === null) {
    User::create('Mitra Uji QC 6', $investorPhone, $investorPassword, Constants::ROLE_INVESTOR);
} else {
    Database::update(
        'users',
        [
            'role'          => Constants::ROLE_INVESTOR,
            'is_active'     => 1,
            'password_hash' => Security::hashPassword($investorPassword),
        ],
        '`id` = :id',
        ['id' => (string) $existingInvestor['id']]
    );
}

$investorJar = QcHttp::cookieJar('bridge-investor');
$investorForm = QcHttp::request('GET', '/login', ['cookie_jar' => $investorJar, 'fresh' => true]);

$investorLogin = QcHttp::request('POST', '/login', [
    'cookie_jar' => $investorJar,
    'data'       => [
        Security::CSRF_FIELD => (string) QcHttp::csrfFrom($investorForm['body']),
        'phone_number'       => $investorPhone,
        'password'           => $investorPassword,
    ],
]);

Qc::ok(
    '6A.0b',
    'Akun mitra uji dapat login (investor aktif)',
    $investorLogin['status'] === 302,
    'status=' . $investorLogin['status']
);

$receiptPath = qc6_temp_file('bridge-receipt.png', qc6_png(120, 90));

// ---------------------------------------------------------------------------
// A. Kalkulasi murni (tanpa database)
// ---------------------------------------------------------------------------

Qc::section('A. Kalkulasi murni: fee nominal/persen, pembulatan, penyerapan FIFO');

$feePct = BridgeLoanService::quoteFee([
    'lender_name'      => 'Pak Haji Daud',
    'principal_amount' => '10.000.000',
    'fee_type'         => 'pct',
    'fee_rate_pct'     => '1,00',
    'notes'            => '',
]);

Qc::equals(
    'A.1',
    'Fee persen: 1,00 % x Rp 10.000.000 = Rp 100.000 (tarif tersimpan kanonik)',
    ['100000.00', '10000000.00', '10100000.00', '1.00'],
    [$feePct['fee'], $feePct['principal'], $feePct['total_return'], $feePct['fee_rate_pct']]
);

$feeFixed = BridgeLoanService::quoteFee([
    'lender_name'      => 'Pak Haji Daud',
    'principal_amount' => '10.000.000',
    'fee_type'         => 'fixed',
    'fee_rate_pct'     => '',
    'fee_fixed'        => '100.000',
]);

Qc::equals(
    'A.2',
    'Fee nominal Rp 100.000 menghasilkan angka yang sama persis dengan fee persen',
    [$feePct['fee'], $feePct['fee_cents'], $feePct['total_return'], 'pct'],
    [$feeFixed['fee'], $feeFixed['fee_cents'], $feeFixed['total_return'], $feePct['fee_type']]
);

Qc::ok(
    'A.2b',
    'Bentuk fee tersimpan sesuai pilihan; tarif hanya terisi untuk bentuk Persen',
    $feePct['fee_type'] === BridgeLoanService::FEE_TYPE_PCT
        && $feeFixed['fee_type'] === BridgeLoanService::FEE_TYPE_FIXED
        && $feePct['fee_rate_pct'] === '1.00'
        && $feeFixed['fee_rate_pct'] === null,
    'pct_rate=' . (string) $feePct['fee_rate_pct'] . ' fixed_rate='
        . var_export($feeFixed['fee_rate_pct'], true)
);

Qc::equals(
    'A.3',
    'Tarif persen disimpan tanpa nilai nominal dari klien: fee_amount selalu hasil hitung peladen',
    '100000.00',
    BridgeLoanService::quoteFee([
        'lender_name'      => 'Pak Haji Daud',
        'principal_amount' => '10.000.000',
        'fee_type'         => 'pct',
        'fee_rate_pct'     => '1,00',
        'fee_fixed'        => '100.000',
        'fee_amount'       => '1',
    ])['fee']
);

$roundingCases = [
    // [pokok, tarif, fee yang diharapkan]
    ['1.000.001', '3,33', '33300.03'],
    ['0,10', '5,00', '0.01'],
    ['1,00', '50,00', '0.50'],
    ['999,99', '0,01', '0.10'],
];

$roundingProblems = [];

foreach ($roundingCases as [$principal, $rate, $expected]) {
    $actual = BridgeLoanService::quoteFee([
        'lender_name'      => 'QC Bulat',
        'principal_amount' => $principal,
        'fee_type'         => 'pct',
        'fee_rate_pct'     => $rate,
    ])['fee'];

    if ($actual !== $expected) {
        $roundingProblems[] = $principal . ' x ' . $rate . '% -> ' . $actual . ' (harap ' . $expected . ')';
    }
}

Qc::ok(
    'A.4',
    'Pembulatan fee persen memakai half away from zero (4 kasus batas)',
    $roundingProblems === [],
    implode("\n", $roundingProblems)
);

Qc::throws(
    'A.5',
    'Tarif fee kosong ditolak saat bentuk fee = Persen',
    static fn () => BridgeLoanService::quoteFee([
        'lender_name'      => 'QC',
        'principal_amount' => '10.000.000',
        'fee_type'         => 'pct',
        'fee_rate_pct'     => '',
    ]),
    InvalidArgumentException::class
);

Qc::throws(
    'A.6',
    'Tarif fee nol ditolak (bentuk Persen wajib > 0)',
    static fn () => BridgeLoanService::parseFeeRateInput('0,00'),
    InvalidArgumentException::class
);

Qc::throws(
    'A.7',
    'Tarif fee di atas 100 % ditolak (batas kolom DECIMAL(5,2))',
    static fn () => BridgeLoanService::parseFeeRateInput('120'),
    InvalidArgumentException::class
);

Qc::throws(
    'A.8',
    'Tarif fee dengan lebih dari 2 desimal ditolak',
    static fn () => BridgeLoanService::parseFeeRateInput('1,005'),
    InvalidArgumentException::class
);

Qc::throws(
    'A.9',
    'Pokok talangan nol ditolak',
    static fn () => BridgeLoanService::quoteFee([
        'lender_name'      => 'QC',
        'principal_amount' => '0',
        'fee_type'         => 'fixed',
        'fee_fixed'        => '0',
    ]),
    InvalidArgumentException::class
);

Qc::throws(
    'A.10',
    'Nama pemberi dana kosong ditolak (talangan tanpa akun tetap butuh nama)',
    static fn () => BridgeLoanService::quoteFee([
        'lender_name'      => '   ',
        'principal_amount' => '10.000.000',
        'fee_type'         => 'fixed',
        'fee_fixed'        => '100.000',
    ]),
    InvalidArgumentException::class
);

Qc::throws(
    'A.11',
    'Nama pemberi dana lebih dari 50 karakter ditolak (batas kolom & label mutasi)',
    static fn () => BridgeLoanService::quoteFee([
        'lender_name'      => str_repeat('X', 51),
        'principal_amount' => '10.000.000',
        'fee_type'         => 'fixed',
        'fee_fixed'        => '100.000',
    ]),
    InvalidArgumentException::class
);

$markupLender = BridgeLoanService::quoteFee([
    'lender_name'      => 'Pak <script>alert(1)</script> Daud',
    'principal_amount' => '1.000.000',
    'fee_type'         => 'fixed',
    'fee_fixed'        => '0',
])['lender_name'];

Qc::ok(
    'A.12',
    'Markup pada nama pemberi dana dibersihkan sebelum disimpan',
    !str_contains($markupLender, '<') && str_contains($markupLender, 'Daud'),
    'tersimpan=' . $markupLender
);

// Penyerapan: sen integer, FIFO, dibatasi hak pengelola.
$oneLoan = [['id' => 'a', 'lender_name' => 'A', 'principal_cents' => 1000000000, 'fee_cents' => 10000000]];

$absorbedFull = BridgeLoanService::allocateAbsorption(174920000, $oneLoan);

Qc::equals(
    'A.13',
    'Fee Rp 100.000 diserap penuh oleh hak pengelola Rp 1.749.200 (hak bersih Rp 1.649.200)',
    [10000000, 0, 10000000],
    [
        $absorbedFull['absorbed_cents'],
        $absorbedFull['uncovered_cents'],
        $absorbedFull['total_return_cents'] - 1000000000,
    ]
);

Qc::ok(
    'A.14',
    'Invariant penyerapan: absorbed + uncovered = fee, absorbed <= hak pengelola',
    $absorbedFull['absorbed_cents'] + $absorbedFull['uncovered_cents'] === $absorbedFull['fee_cents']
        && $absorbedFull['absorbed_cents'] <= 174920000,
    'absorbed=' . $absorbedFull['absorbed_cents'] . ' uncovered=' . $absorbedFull['uncovered_cents']
);

$fifoLoans = [
    ['id' => 'a', 'lender_name' => 'A', 'principal_cents' => 1000000000, 'fee_cents' => 10000000],
    ['id' => 'b', 'lender_name' => 'B', 'principal_cents' => 500000000, 'fee_cents' => 6000000],
];

$fifoLimited = BridgeLoanService::allocateAbsorption(13000000, $fifoLoans);

Qc::equals(
    'A.15',
    'FIFO dengan hak terbatas: talangan pertama diserap penuh, sisanya ke berikutnya',
    [10000000, 0, 3000000, 3000000],
    [
        $fifoLimited['rows'][0]['absorbed_cents'],
        $fifoLimited['rows'][0]['uncovered_cents'],
        $fifoLimited['rows'][1]['absorbed_cents'],
        $fifoLimited['rows'][1]['uncovered_cents'],
    ]
);

Qc::ok(
    'A.16',
    'Invariant FIFO: Σ absorbed = min(Σ fee, hak pengelola) dan Σ absorbed + Σ uncovered = Σ fee',
    $fifoLimited['absorbed_cents'] === 13000000
        && $fifoLimited['absorbed_cents'] + $fifoLimited['uncovered_cents'] === $fifoLimited['fee_cents'],
    'absorbed=' . $fifoLimited['absorbed_cents'] . ' uncovered=' . $fifoLimited['uncovered_cents']
);

$noShare = BridgeLoanService::allocateAbsorption(0, $oneLoan);

Qc::equals(
    'A.17',
    'Hak pengelola nol (hari minus): fee tidak diserap sama sekali, seluruhnya uncovered',
    [0, 10000000],
    [$noShare['absorbed_cents'], $noShare['uncovered_cents']]
);

$noShareQuote = SettlementService::quote(
    [
        'sold_grams'       => '18,050',
        'density_pct'      => '93,50',
        'shop_price_per_g' => '2.080.000',
        'coord_fee'        => '200.000',
    ],
    [
        'holding_mg'           => 18050,
        'holding_cost_cents'   => 3515750000,
        'active_deficit_cents' => 0,
        'bridge_loans'         => [['id' => 'a', 'lender_name' => 'A', 'principal_cents' => 1000000000, 'fee_cents' => 10000000]],
    ]
);

Qc::equals(
    'A.18',
    'quote() memotong fee dari hak pengelola TANPA mengubah pool investor',
    ['1749200.00', '437300.00', '100000.00', '1649200.00'],
    [
        $noShareQuote['operator_share'],
        $noShareQuote['investor_pool'],
        $noShareQuote['bridge_fee'],
        $noShareQuote['operator_take_home'],
    ]
);

Qc::ok(
    'A.19',
    'Invariant Fase 3 tetap utuh: pengelola + pool = laba bersih (fee tidak menyentuhnya)',
    $noShareQuote['operator_share_cents'] + $noShareQuote['investor_pool_cents'] === $noShareQuote['net_cents'],
    'op=' . $noShareQuote['operator_share'] . ' pool=' . $noShareQuote['investor_pool']
        . ' net=' . $noShareQuote['net']
);

Qc::equals(
    'A.20',
    'Kembali talangan = Σ(pokok + fee) — pokok BUKAN pendapatan',
    '10100000.00',
    $noShareQuote['bridge_total_return']
);

// ---------------------------------------------------------------------------
// B. Paritas parser (tarif fee vs kadar timbang air; nominal fee)
// ---------------------------------------------------------------------------

Qc::section('B. Paritas parser: tarif fee, kadar timbang air, dan nominal');

$rateCases = ['1,00', '1.5', '0,01', '100', '50,25'];
$rateProblems = [];

foreach ($rateCases as $case) {
    try {
        $rate = BridgeLoanService::parseFeeRateInput($case);
    } catch (Throwable $exception) {
        $rateProblems[] = $case . ': tarif fee melempar ' . $exception->getMessage();

        continue;
    }

    try {
        $density = SettlementService::quote(
            [
                'sold_grams'       => '18,050',
                'density_pct'      => $case,
                'shop_price_per_g' => '2.080.000',
                'coord_fee'        => '200.000',
            ],
            ['holding_mg' => 18050, 'holding_cost_cents' => 3515750000, 'active_deficit_cents' => 0]
        )['density_hundredths'];
    } catch (Throwable $exception) {
        $rateProblems[] = $case . ': kadar timbang air melempar ' . $exception->getMessage();

        continue;
    }

    if ($rate !== $density) {
        $rateProblems[] = $case . ': tarif=' . $rate . ' kadar=' . $density;
    }
}

Qc::ok(
    'B.1',
    'Parser tarif fee sepakat dengan parser kadar timbang air (5 kasus sah)',
    $rateProblems === [],
    implode("\n", $rateProblems)
);

$invalidCases = ['', '-1', '1,234', 'abc', '101', '1.2.3', '1,0.5'];
$invalidProblems = [];

foreach ($invalidCases as $case) {
    $rateRejected = false;
    $densityRejected = false;

    try {
        BridgeLoanService::parseFeeRateInput($case);
    } catch (InvalidArgumentException $exception) {
        $rateRejected = true;
    }

    try {
        SettlementService::quote(
            [
                'sold_grams'       => '18,050',
                'density_pct'      => $case,
                'shop_price_per_g' => '2.080.000',
                'coord_fee'        => '200.000',
            ],
            ['holding_mg' => 18050, 'holding_cost_cents' => 3515750000, 'active_deficit_cents' => 0]
        );
    } catch (Throwable $exception) {
        $densityRejected = true;
    }

    if (!$rateRejected || !$densityRejected) {
        $invalidProblems[] = $case . ': tarif=' . ($rateRejected ? 'tolak' : 'TERIMA')
            . ' kadar=' . ($densityRejected ? 'tolak' : 'TERIMA');
    }
}

Qc::ok(
    'B.2',
    'Kedua parser menolak himpunan kasus yang sama (7 kasus tidak sah)',
    $invalidProblems === [],
    implode("\n", $invalidProblems)
);

$moneyProblems = [];

foreach (['' => 0, '10.000.000' => 1000000000, '1.234,56' => 123456, '0' => 0] as $raw => $expected) {
    $actual = BridgeLoanService::quoteFee([
        'lender_name'      => 'QC Nominal',
        'principal_amount' => '1.000',
        'fee_type'         => 'fixed',
        'fee_fixed'        => (string) $raw,
    ])['fee_cents'];

    if ($actual !== $expected) {
        $moneyProblems[] = '"' . $raw . '" -> ' . $actual . ' (harap ' . $expected . ')';
    }
}

Qc::ok(
    'B.3',
    'Nominal fee memakai parser yang sama dengan SettlementService (4 kasus, termasuk kosong = 0)',
    $moneyProblems === [],
    implode("\n", $moneyProblems)
);

$delegated = SettlementService::parseMoneyInput('1.234,56', 'Uji');
$viaQuote = BridgeLoanService::quoteFee([
    'lender_name'      => 'QC Nominal',
    'principal_amount' => '1.000',
    'fee_type'         => 'fixed',
    'fee_fixed'        => '1.234,56',
])['fee_cents'];

Qc::equals(
    'B.4',
    'Tidak ada salinan parser: nominal fee = SettlementService::parseMoneyInput()',
    $delegated,
    $viaQuote
);

// ---------------------------------------------------------------------------
// C. Penerimaan talangan lewat HTTP (tiga tulisan, satu transaksi)
// ---------------------------------------------------------------------------

Qc::section('C. Penerimaan talangan: tiga tulisan, atomisitas, token sekali-pakai');

$fixture = QcFixture::resetSettlementState();
$tellerId = (string) $fixture['teller_id'];
$brankasId = (string) $fixture['brankas_id'];

Qc::equals(
    'C.0',
    'Keadaan awal: kas meja Rp 14.842.500, emas 18,050 gr (modal Rp 35.157.500), tanpa talangan',
    ['14842500.00', '18.050', '35157500.00', 0],
    [
        QcFixture::registerBalance($tellerId),
        $fixture['holding_grams'],
        $fixture['holding_cost'],
        QcFixture::bridgeSnapshot()['count_active'],
    ]
);

$tellerPage = qc6_tokens($operatorJar, '/operator/teller');

Qc::ok(
    'C.1',
    'Layar kasir 200 memuat tombol "Terima Dana Talangan" dan modal (x-cloak)',
    $tellerPage['status'] === 200
        && str_contains($tellerPage['body'], 'Terima Dana Talangan')
        && str_contains($tellerPage['body'], 'x-cloak'),
    'status=' . $tellerPage['status']
);

$huluBefore = (string) (Database::fetchOne('SELECT COUNT(*) AS `total` FROM `hulu_transactions`')['total'] ?? '0');
$settlementsBefore = qc6_settlement_ids();

$receive = qc6_talangan($operatorJar, [
    'lender_name'      => 'Pak Haji Daud',
    'principal_amount' => '10.000.000',
    'fee_type'         => 'fixed',
    'fee_rate_pct'     => '',
    'fee_fixed'        => '100.000',
    'notes'            => 'talangan semalam',
]);

Qc::ok(
    'C.2',
    'POST talangan sukses = 302 PRG ke /operator/teller',
    $receive['status'] === 302 && $receive['location'] === '/operator/teller',
    'status=' . $receive['status'] . ' location=' . (string) $receive['location']
);

$loan = qc6_last_loan();
$inMutations = qc6_mutations($tellerId, CashMutation::TYPE_IN);

Qc::ok(
    'C.3',
    'Kas Meja Bakar bertambah persis pokok (Rp 10.000.000)',
    QcFixture::registerBalance($tellerId) === '24842500.00',
    'saldo=' . QcFixture::registerBalance($tellerId)
);

Qc::equals(
    'C.4',
    'Satu baris bridge_loans: status active, fee kanonik, pos kas & waktu dari peladen',
    ['active', '100000.00', '0.00', '0.00', 'fixed', $tellerId],
    [
        (string) ($loan['status'] ?? ''),
        qc6_money($loan['fee_amount'] ?? ''),
        qc6_money($loan['fee_absorbed'] ?? ''),
        qc6_money($loan['fee_uncovered'] ?? ''),
        (string) ($loan['fee_type'] ?? ''),
        (string) ($loan['register_id'] ?? ''),
    ]
);

Qc::ok(
    'C.5',
    'Tepat satu mutasi kas "in" berlabel "Talangan dari Pak Haji Daud" dengan jejak bridge_loan_id',
    count($inMutations) === 1
        && $inMutations[0]['source_destination'] === 'Talangan dari Pak Haji Daud'
        && qc6_money($inMutations[0]['amount']) === '10000000.00'
        && str_contains((string) $inMutations[0]['notes'], 'bridge_loan_id=' . (string) $loan['id']),
    'mutasi=' . count($inMutations) . ' label=' . (string) ($inMutations[0]['source_destination'] ?? '-')
);

$huluAfter = (string) (Database::fetchOne('SELECT COUNT(*) AS `total` FROM `hulu_transactions`')['total'] ?? '0');

Qc::ok(
    'C.6',
    'Talangan tidak menyentuh tabel lain: hulu, sesi tutup buku, brankas, dompet mitra',
    $huluAfter === $huluBefore
        && qc6_settlement_ids() === $settlementsBefore
        && QcFixture::registerBalance($brankasId) === '0.00'
        && (string) (Database::fetchOne('SELECT COUNT(*) AS `total` FROM `profit_distributions`')['total'] ?? '0') === '0',
    'hulu=' . $huluBefore . '->' . $huluAfter
);

// Fee persen dihitung peladen walau klien mengirim fee_amount palsu.
$pctReceive = qc6_talangan($operatorJar, [
    'lender_name'      => 'Bu Ratna',
    'principal_amount' => '5.000.000',
    'fee_type'         => 'pct',
    'fee_rate_pct'     => '2,00',
    'fee_fixed'        => '999.999',
    'fee_amount'       => '1',
]);

$pctLoan = Database::fetchOne(
    'SELECT * FROM `bridge_loans` WHERE `lender_name` = :name LIMIT 1',
    ['name' => 'Bu Ratna']
);

Qc::equals(
    'C.7',
    'Fee persen dihitung peladen (2 % x Rp 5.000.000 = Rp 100.000); nominal palsu diabaikan',
    ['pct', '100000.00', '2.00'],
    [
        (string) ($pctLoan['fee_type'] ?? ''),
        qc6_money($pctLoan['fee_amount'] ?? ''),
        (string) ($pctLoan['fee_rate_pct'] ?? ''),
    ]
);

// Atomisitas: isian tidak sah → 422 tanpa satu pun tulisan.
$beforeInvalid = [
    'loans' => (string) (Database::fetchOne('SELECT COUNT(*) AS `total` FROM `bridge_loans`')['total'] ?? '0'),
    'mutations' => (string) (Database::fetchOne('SELECT COUNT(*) AS `total` FROM `cash_mutations`')['total'] ?? '0'),
    'meja' => QcFixture::registerBalance($tellerId),
];

$invalidReceive = qc6_talangan($operatorJar, [
    'lender_name'      => '',
    'principal_amount' => '10.000.000',
    'fee_type'         => 'fixed',
    'fee_rate_pct'     => '',
    'fee_fixed'        => '100.000',
]);

$afterInvalid = [
    'loans' => (string) (Database::fetchOne('SELECT COUNT(*) AS `total` FROM `bridge_loans`')['total'] ?? '0'),
    'mutations' => (string) (Database::fetchOne('SELECT COUNT(*) AS `total` FROM `cash_mutations`')['total'] ?? '0'),
    'meja' => QcFixture::registerBalance($tellerId),
];

Qc::ok(
    'C.8',
    'Nama pemberi dana kosong -> 422 tanpa satu pun tulisan (bridge_loans, mutasi, saldo)',
    $invalidReceive['status'] === 422 && $afterInvalid === $beforeInvalid,
    'status=' . $invalidReceive['status'] . ' sebelum=' . json_encode($beforeInvalid)
        . ' sesudah=' . json_encode($afterInvalid)
);

Qc::ok(
    'C.9',
    'Jalur gagal validasi mengembalikan isian & alasan (modal terbuka kembali)',
    str_contains($invalidReceive['body'], 'Nama pemberi dana wajib diisi')
        && str_contains($invalidReceive['body'], 'value="10.000.000"'),
    'alasan tertulis=' . (str_contains($invalidReceive['body'], 'wajib diisi') ? 'ya' : 'tidak')
);

$noCsrf = QcHttp::request('POST', '/operator/teller/talangan', [
    'cookie_jar' => $operatorJar,
    'data'       => ['lender_name' => 'Tanpa CSRF', 'principal_amount' => '1.000.000', 'fee_type' => 'fixed'],
]);

$csrfWrites = (string) (Database::fetchOne('SELECT COUNT(*) AS `total` FROM `bridge_loans`')['total'] ?? '0');

Qc::ok(
    'C.10',
    'POST tanpa token CSRF -> 419 tanpa tulisan (pertahanan front controller)',
    $noCsrf['status'] === 419 && $csrfWrites === $afterInvalid['loans'],
    'status=' . $noCsrf['status'] . ' baris=' . $csrfWrites
);

$reusedTokens = qc6_tokens($operatorJar, '/operator/teller');
$firstOnce = qc6_talangan($operatorJar, [
    'lender_name'      => 'Kiriman Ganda',
    'principal_amount' => '1.000.000',
    'fee_type'         => 'fixed',
    'fee_rate_pct'     => '',
    'fee_fixed'        => '10.000',
], $reusedTokens);

$loansAfterFirst = (string) (Database::fetchOne('SELECT COUNT(*) AS `total` FROM `bridge_loans`')['total'] ?? '0');

$secondOnce = qc6_talangan($operatorJar, [
    'lender_name'      => 'Kiriman Ganda',
    'principal_amount' => '1.000.000',
    'fee_type'         => 'fixed',
    'fee_rate_pct'     => '',
    'fee_fixed'        => '10.000',
], $reusedTokens);

$loansAfterSecond = (string) (Database::fetchOne('SELECT COUNT(*) AS `total` FROM `bridge_loans`')['total'] ?? '0');

Qc::ok(
    'C.11',
    'Token `_once` sekali pakai: kiriman kedua dijawab PRG tanpa tulisan baru',
    $firstOnce['status'] === 302
        && $secondOnce['status'] === 302
        && $secondOnce['location'] === '/operator/teller'
        && $loansAfterFirst === $loansAfterSecond,
    'pertama=' . $firstOnce['status'] . ' kedua=' . $secondOnce['status']
        . ' baris=' . $loansAfterFirst . '->' . $loansAfterSecond
);

$investorAttempt = QcHttp::request('POST', '/operator/teller/talangan', [
    'cookie_jar' => $investorJar,
    'data'       => [
        // Token dibaca dari halaman yang MEMANG boleh dibuka mitra: front
        // controller memeriksa CSRF lebih dulu, sehingga tanpa token sah
        // permintaannya berhenti di 419 sebelum gerbang peran diuji.
        Security::CSRF_FIELD => (string) QcHttp::csrfFrom(
            QcHttp::request('GET', '/investor/dashboard', ['cookie_jar' => $investorJar])['body']
        ),
        'lender_name'        => 'Mitra Curiga',
        'principal_amount'   => '1.000.000',
        'fee_type'           => 'fixed',
        'fee_rate_pct'       => '',
        'fee_fixed'          => '10.000',
    ],
]);

$loansAfterInvestor = (string) (Database::fetchOne('SELECT COUNT(*) AS `total` FROM `bridge_loans`')['total'] ?? '0');

Qc::ok(
    'C.12',
    'Mitra pemodal ditolak: 302 ke /investor/dashboard tanpa satu pun tulisan',
    $investorAttempt['status'] === 302
        && $investorAttempt['location'] === '/investor/dashboard'
        && $loansAfterInvestor === $loansAfterSecond,
    'status=' . $investorAttempt['status'] . ' location=' . (string) $investorAttempt['location']
);

// Escaping nama pada layar kasir (jangan sampai markup dari nama dieksekusi).
$escapeName = 'Pak "Daud" & Co';
QcFixture::resetTellerState();
qc6_talangan($operatorJar, [
    'lender_name'      => $escapeName,
    'principal_amount' => '1.000.000',
    'fee_type'         => 'fixed',
    'fee_rate_pct'     => '',
    'fee_fixed'        => '10.000',
]);

$escapePage = QcHttp::request('GET', '/operator/teller', ['cookie_jar' => $operatorJar]);

Qc::ok(
    'C.13',
    'Nama pemberi dana tampil ter-escape pada kartu "Talangan Aktif" (tanpa HTML mentah)',
    str_contains($escapePage['body'], 'Pak &quot;Daud&quot; &amp; Co')
        && !str_contains($escapePage['body'], 'Pak "Daud" & Co'),
    'escape ditemukan=' . (str_contains($escapePage['body'], '&quot;') ? 'ya' : 'tidak')
);

// ---------------------------------------------------------------------------
// D. Auto-pay pada tutup buku
// ---------------------------------------------------------------------------

Qc::section('D. Auto-pay: pokok + fee kembali, satu mutasi `out` per talangan');

$fixture = QcFixture::resetSettlementState();
$tellerId = (string) $fixture['teller_id'];
$brankasId = (string) $fixture['brankas_id'];

QcFixture::seedBridgeLoan([
    'lender_name'      => 'Pak Haji Daud',
    'principal_amount' => '10.000.000',
    'fee_type'         => 'fixed',
    'fee_rate_pct'     => '',
    'fee_fixed'        => '100.000',
    'notes'            => 'talangan semalam',
]);

$loanId = (string) (qc6_last_loan()['id'] ?? '');
$knownSettlements = qc6_settlement_ids();

qc6_seedExpense();

$settle = qc6_settle($operatorJar, [
    'sold_grams'       => '18,050',
    'density_pct'      => '93,50',
    'shop_price_per_g' => '2.080.000',
    'coord_fee'        => '200.000',
    'kas_split'        => '',
    'brankas_split'    => '',
], $receiptPath);

Qc::ok(
    'D.1',
    'Tutup buku dengan talangan aktif = 302 PRG',
    $settle['status'] === 302 && $settle['location'] === '/operator/settlement',
    'status=' . $settle['status'] . ' location=' . (string) $settle['location']
);

$settlement = qc6_new_settlement($knownSettlements);
$settlementId = (string) ($settlement['id'] ?? '');
$settledLoan = Database::fetchOne('SELECT * FROM `bridge_loans` WHERE `id` = :id', ['id' => $loanId]);
$outMutations = qc6_bridge_mutations($tellerId);
$settlementIn = qc6_settlement_in_mutations($tellerId);

Qc::equals(
    'D.2',
    'Baris talangan menjadi settled + settlement_id + fee_absorbed/fee_uncovered',
    ['settled', $settlementId, '100000.00', '0.00'],
    [
        (string) ($settledLoan['status'] ?? ''),
        (string) ($settledLoan['settlement_id'] ?? ''),
        qc6_money($settledLoan['fee_absorbed'] ?? ''),
        qc6_money($settledLoan['fee_uncovered'] ?? ''),
    ]
);

Qc::equals(
    'D.3',
    'Kas Meja Bakar turun persis pokok + fee (14.842.500 + 10.000.000 + 35.157.500 - 10.100.000)',
    '49900000.00',
    QcFixture::registerBalance($tellerId)
);

Qc::ok(
    'D.4',
    'Tepat satu mutasi "out" per talangan dengan label & notes lengkap',
    count($outMutations) === 1
        && $outMutations[0]['source_destination'] === 'Kembali talangan: Pak Haji Daud'
        && qc6_money($outMutations[0]['amount']) === '10100000.00'
        && str_contains((string) $outMutations[0]['notes'], 'settlement_id=' . $settlementId)
        && str_contains((string) $outMutations[0]['notes'], 'fee_diserap=100000.00'),
    'mutasi=' . count($outMutations) . ' label=' . (string) ($outMutations[0]['source_destination'] ?? '-')
);

Qc::equals(
    'D.5',
    'Invariant Fase 3 utuh pada baris yang tersimpan: pengelola + pool = laba bersih',
    [qc6_money($settlement['net_settled_profit'] ?? ''), '1749200.00', '437300.00'],
    [
        qc6_money(
            TellerService::centsToDecimal(
                qc6_cents($settlement['operator_share_80'] ?? '') + qc6_cents($settlement['investor_pool_20'] ?? '')
            )
        ),
        qc6_money($settlement['operator_share_80'] ?? ''),
        qc6_money($settlement['investor_pool_20'] ?? ''),
    ]
);

Qc::equals(
    'D.6',
    'Hak pengelola bersih = operator_share_80 - Σ fee_absorbed (Rp 1.649.200)',
    '1649200.00',
    qc6_operator_take_home($settlementId, (string) ($settlement['operator_share_80'] ?? '0.00'))
);

Qc::ok(
    'D.7',
    'Catatan mutasi kas masuk sesi ini memuat ringkasan talangan (talangan=1, fee_diserap)',
    str_contains((string) ($settlementIn[0]['notes'] ?? ''), 'talangan=1')
        && str_contains((string) ($settlementIn[0]['notes'] ?? ''), 'fee_diserap=100000.00'),
    (string) ($settlementIn[0]['notes'] ?? '-')
);

// Talangan yang diterima SETELAH tutup buku tetap aktif sampai siklus berikutnya.
$afterSettlementReceive = qc6_talangan($operatorJar, [
    'lender_name'      => 'Pak Dedi',
    'principal_amount' => '3.000.000',
    'fee_type'         => 'fixed',
    'fee_rate_pct'     => '',
    'fee_fixed'        => '30.000',
]);

$activeAfter = Database::fetchAll(
    'SELECT `lender_name`, `status`, `fee_absorbed`, `fee_uncovered` FROM `bridge_loans` WHERE `status` = :status',
    ['status' => BridgeLoan::STATUS_ACTIVE]
);

Qc::equals(
    'D.8',
    'Talangan pasca-tutup buku tetap active dengan fee belum diserap (dibayar siklus berikutnya)',
    [[ 'Pak Dedi', 'active', '0.00', '0.00' ]],
    array_map(
        static fn (array $row): array => [
            (string) $row['lender_name'],
            (string) $row['status'],
            qc6_money($row['fee_absorbed']),
            qc6_money($row['fee_uncovered']),
        ],
        $activeAfter
    )
);

// Siklus berikutnya: dua talangan aktif (yang baru) + satu kedatangan hulu baru.
$secondBatch = QcFixture::seedHoldingBatch('1,000', '1.950.000', 'QC Talangan Lanjutan');
$knownSettlements = qc6_settlement_ids();

qc6_seedExpense();

$secondSettle = qc6_settle($operatorJar, [
    'sold_grams'       => '1,000',
    'density_pct'      => '93,50',
    'shop_price_per_g' => '2.300.000',
    'coord_fee'        => '200.000',
    'kas_split'        => '',
    'brankas_split'    => '',
], $receiptPath);

$secondRow = qc6_new_settlement($knownSettlements);
$secondId = (string) ($secondRow['id'] ?? '');
$settledOnSecond = BridgeLoan::listForSettlement($secondId);
$outMutationsSecond = qc6_bridge_mutations($tellerId);

Qc::ok(
    'D.9',
    'Siklus kedua: talangan Pak Dedi lunas, satu mutasi "out" untuknya (FIFO)',
    $secondSettle['status'] === 302
        && count($settledOnSecond) === 1
        && (string) $settledOnSecond[0]['lender_name'] === 'Pak Dedi'
        && count($outMutationsSecond) === 2,
    'settled=' . count($settledOnSecond) . ' mutasi_kembali=' . count($outMutationsSecond)
        . ' status=' . $secondSettle['status']
);

Qc::equals(
    'D.10',
    'fee_absorbed siklus kedua = fee penuh talangan (Rp 30.000)',
    '30000.00',
    qc6_money($settledOnSecond[0]['fee_absorbed'] ?? '')
);

// ---------------------------------------------------------------------------
// E. Isolasi pool investor (bukti diferensial)
// ---------------------------------------------------------------------------

Qc::section('E. Isolasi pool investor: sesi dengan talangan vs tanpa talangan');

$settleFields = [
    'sold_grams'       => '18,050',
    'density_pct'      => '93,50',
    'shop_price_per_g' => '2.080.000',
    'coord_fee'        => '200.000',
    'kas_split'        => '',
    'brankas_split'    => '',
];

// (1) Sesi TANPA talangan.
QcFixture::resetSettlementState();
$wallets = QcFixture::seedQcWallets();
$knownSettlements = qc6_settlement_ids();

qc6_seedExpense();

$plainSettle = qc6_settle($operatorJar, $settleFields, $receiptPath);
$plainRow = qc6_new_settlement($knownSettlements);
$plainId = (string) ($plainRow['id'] ?? '');

$plainProfits = qc6_wallet_profits();
$plainPool = qc6_money($plainRow['investor_pool_20'] ?? '');
$plainSumProfit = ProfitDistribution::sumProfit($plainId);
$plainMutationNote = (string) (Database::fetchOne(
    'SELECT `notes` FROM `cash_mutations` WHERE `register_id` = :id AND `mutation_type` = :type '
    . 'ORDER BY `created_at` DESC, `id` DESC LIMIT 1',
    ['id' => $tellerId, 'type' => CashMutation::TYPE_IN]
)['notes'] ?? '');

// (2) Sesi IDENTIK dengan talangan Rp 10.000.000 + fee Rp 100.000.
QcFixture::resetSettlementState();
$wallets = QcFixture::seedQcWallets();
QcFixture::seedBridgeLoan([
    'lender_name'      => 'Pak Haji Daud',
    'principal_amount' => '10.000.000',
    'fee_type'         => 'fixed',
    'fee_rate_pct'     => '',
    'fee_fixed'        => '100.000',
]);

$knownSettlements = qc6_settlement_ids();

qc6_seedExpense();

$bridgeSettle = qc6_settle($operatorJar, $settleFields, $receiptPath);
$bridgeRow = qc6_new_settlement($knownSettlements);
$bridgeId = (string) ($bridgeRow['id'] ?? '');

$bridgeProfits = qc6_wallet_profits();

Qc::ok(
    'E.1',
    'Kedua sesi tutup buku tersimpan (prasyarat perbandingan)',
    $plainSettle['status'] === 302 && $bridgeSettle['status'] === 302
        && $plainId !== '' && $bridgeId !== '',
    'plain=' . $plainSettle['status'] . ' bridge=' . $bridgeSettle['status']
);

Qc::equals(
    'E.2',
    'investor_pool_20 IDENTIK dengan/tanpa talangan (Rp 437.300)',
    [$plainPool, '437300.00'],
    [$plainPool, qc6_money($bridgeRow['investor_pool_20'] ?? '')]
);

Qc::equals(
    'E.3',
    'Laba bersih & porsi pengelola juga identik: fee tidak menyentuh bagi hasil 80 : 20',
    [
        qc6_money($plainRow['net_settled_profit'] ?? ''),
        qc6_money($plainRow['operator_share_80'] ?? ''),
    ],
    [
        qc6_money($bridgeRow['net_settled_profit'] ?? ''),
        qc6_money($bridgeRow['operator_share_80'] ?? ''),
    ]
);

Qc::equals(
    'E.4',
    'Σ profit_earned identik (Rp 437.300) — fee tidak mengurangi dividen mitra',
    [$plainSumProfit, '437300.00'],
    [$plainSumProfit, ProfitDistribution::sumProfit($bridgeId)]
);

Qc::equals(
    'E.5',
    'Kenaikan balance_profit SETIAP dompet identik dengan/tanpa talangan',
    $plainProfits,
    $bridgeProfits
);

Qc::equals(
    'E.6',
    'Dividen terdistribusi persis seperti Protokol 4 (Prioritas/Reguler A/Reguler B; Pengelola 0)',
    ['174920.00', '154341.18', '108038.82', '0.00'],
    [
        $bridgeProfits[(string) $wallets['priority']] ?? '',
        $bridgeProfits[(string) $wallets['reguler_a']] ?? '',
        $bridgeProfits[(string) $wallets['reguler_b']] ?? '',
        $bridgeProfits[(string) $wallets['operator']] ?? '',
    ]
);

Qc::equals(
    'E.7',
    'Hanya hak pengelola yang turun, tepat sebesar fee (Rp 1.649.200 vs Rp 1.749.200)',
    ['1649200.00', '1749200.00'],
    [
        qc6_operator_take_home($bridgeId, (string) ($bridgeRow['operator_share_80'] ?? '0.00')),
        qc6_operator_take_home($plainId, (string) ($plainRow['operator_share_80'] ?? '0.00')),
    ]
);

Qc::ok(
    'E.8',
    'Catatan mutasi kas masuk sesi tanpa talangan tidak memuat ringkasan talangan',
    !str_contains($plainMutationNote, 'talangan='),
    'notes=' . $plainMutationNote
);

// Hari minus dengan talangan: pool 0,00, dividen nol, fee seluruhnya uncovered.
QcFixture::resetSettlementState();
$wallets = QcFixture::seedQcWallets();
QcFixture::seedBridgeLoan([
    'lender_name'      => 'Pak Haji Daud',
    'principal_amount' => '10.000.000',
    'fee_type'         => 'fixed',
    'fee_rate_pct'     => '',
    'fee_fixed'        => '100.000',
]);

$profitsBeforeLoss = qc6_wallet_profits();
$knownSettlements = qc6_settlement_ids();

qc6_seedExpense();

$lossSettle = qc6_settle($operatorJar, [
    'sold_grams'       => '18,050',
    'density_pct'      => '93,50',
    'shop_price_per_g' => '1.900.000',
    'coord_fee'        => '200.000',
    'kas_split'        => '',
    'brankas_split'    => '',
], $receiptPath);

$lossRow = qc6_new_settlement($knownSettlements);
$lossId = (string) ($lossRow['id'] ?? '');
$lossLoan = Database::fetchOne(
    'SELECT * FROM `bridge_loans` WHERE `settlement_id` = :id LIMIT 1',
    ['id' => $lossId]
);

Qc::equals(
    'E.9',
    'Hari minus + talangan: pool 0,00 dan hak pengelola 0,00 (rumus Fase 3 tidak berubah)',
    ['-1062500.00', '0.00', '0.00'],
    [
        qc6_money($lossRow['net_settled_profit'] ?? ''),
        qc6_money($lossRow['investor_pool_20'] ?? ''),
        qc6_money($lossRow['operator_share_80'] ?? ''),
    ]
);

Qc::equals(
    'E.10',
    'Hari minus: fee diserap 0,00 dan seluruhnya tercatat fee_uncovered (kas kerja pengelola)',
    ['0.00', '100000.00'],
    [qc6_money($lossLoan['fee_absorbed'] ?? ''), qc6_money($lossLoan['fee_uncovered'] ?? '')]
);

Qc::equals(
    'E.11',
    'Hari minus: carryover_deficit sesuai rumus lama (Rp 1.062.500) dan modal mitra tidak tersentuh',
    ['1062500.00', $profitsBeforeLoss, '1062500.00'],
    [
        qc6_money($lossRow['carryover_deficit'] ?? ''),
        qc6_wallet_profits(),
        qc6_money(Database::fetchOne(
            'SELECT `setting_value` FROM `system_settings` WHERE `setting_key` = :key',
            ['key' => SettlementService::SETTING_ACTIVE_DEFICIT]
        )['setting_value'] ?? '0'),
    ]
);

Qc::ok(
    'E.12',
    'Hari minus: pokok + fee tetap dibayar penuh kepada pemberi dana',
    (string) ($lossLoan['status'] ?? '') === 'settled',
    'status=' . (string) ($lossLoan['status'] ?? '-')
);

Qc::equals(
    'E.13',
    'Hari minus: kas meja setelah pengembalian = 14.842.500 + 10.000.000 + 34.095.000 - 10.100.000',
    '48837500.00',
    QcFixture::registerBalance($tellerId)
);

// Pemindaian berkas investor: istilah talangan tidak boleh bocor.
$investorFiles = array_merge(
    glob(APP_PATH . '/Controllers/Investor*.php') ?: [],
    glob(APP_PATH . '/Controllers/ExportController.php') ?: [],
    glob(APP_PATH . '/Services/InvestorService.php') ?: [],
    glob(APP_PATH . '/Views/investor/*.php') ?: [],
    glob(APP_PATH . '/Views/layouts/investor.php') ?: [],
    glob(APP_PATH . '/Views/layouts/report.php') ?: []
);

$investorLeaks = qc6_scan(
    $investorFiles,
    static fn (string $line): bool => preg_match(
        '/BridgeLoan|bridge_loans|talangan|fee_uncovered|fee_absorbed/i',
        $line
    ) === 1
);

Qc::ok(
    'E.14',
    'Berkas jalur investor bersih dari istilah dana talangan (daftar terlarang diperluas)',
    $investorLeaks === [],
    implode("\n", $investorLeaks)
);

// ---------------------------------------------------------------------------
// F. Penjagaan saldo & batas input
// ---------------------------------------------------------------------------

Qc::section('F. Penjagaan saldo kas & batas input (422 tanpa tulisan)');

// Penjagaan saldo: pokok talangan dibelanjakan seluruhnya malam itu, lalu
// operator mengalokasikan SELURUH kas masuk ke brankas — meja tinggal
// Rp 1.770.000, jauh di bawah kewajiban pengembalian Rp 20.100.000.
QcFixture::resetTellerState();
QcFixture::seedBridgeLoan([
    'lender_name'      => 'Pak Haji Daud',
    'principal_amount' => '20.000.000',
    'fee_type'         => 'fixed',
    'fee_rate_pct'     => '',
    'fee_fixed'        => '100.000',
]);

QcFixture::seedHoldingBatch('35,000', '1.950.000', 'QC Belanja Habis');

$tellerId = (string) (CashRegister::activeTeller()['id'] ?? '');

qc6_seedExpense();

$beforeGuard = [
    'meja' => QcFixture::registerBalance($tellerId),
    'active' => QcFixture::bridgeSnapshot()['count_active'],
    'settlements' => count(qc6_settlement_ids()),
];

$guard = qc6_settle($operatorJar, [
    'sold_grams'       => '35,000',
    'density_pct'      => '93,50',
    'shop_price_per_g' => '2.000.000',
    'coord_fee'        => '200.000',
    'kas_split'        => '0',
    'brankas_split'    => '70.000.000',
], $receiptPath);

$afterGuard = [
    'meja' => QcFixture::registerBalance($tellerId),
    'active' => QcFixture::bridgeSnapshot()['count_active'],
    'settlements' => count(qc6_settlement_ids()),
];

Qc::ok(
    'F.1',
    'Kas meja tidak cukup mengembalikan talangan -> 422 tanpa tulisan, talangan tetap active',
    $guard['status'] === 422 && $afterGuard === $beforeGuard,
    'status=' . $guard['status'] . ' sebelum=' . json_encode($beforeGuard)
        . ' sesudah=' . json_encode($afterGuard)
);

Qc::ok(
    'F.2',
    'Pesan penolakan menyebut kekurangan pengembalian (dapat ditindaklanjuti)',
    str_contains($guard['body'], 'tidak cukup mengembalikan dana talangan'),
    'pesan=' . (str_contains($guard['body'], 'talangan') ? 'ada' : 'tidak ada')
);

// Tanpa emas holding: tutup buku tetap ditolak dan talangan belum dibayar.
QcFixture::resetTellerState();
QcFixture::seedBridgeLoan([
    'lender_name'      => 'Pak Haji Daud',
    'principal_amount' => '10.000.000',
    'fee_type'         => 'fixed',
    'fee_rate_pct'     => '',
    'fee_fixed'        => '100.000',
]);

// Tanpa emas holding, layar rekonsiliasi TIDAK merender form (dan karenanya
// tidak memuat token), jadi token dibaca dari layar kasir: token form milik
// sesi, bukan milik halaman.
$noHolding = qc6_settle(
    $operatorJar,
    [
        'sold_grams'       => '1,000',
        'density_pct'      => '93,50',
        'shop_price_per_g' => '2.080.000',
        'coord_fee'        => '200.000',
        'kas_split'        => '',
        'brankas_split'    => '',
    ],
    $receiptPath,
    '/operator/teller'
);

Qc::ok(
    'F.3',
    'Tanpa emas holding: tutup buku ditolak 422 dan talangan tetap active (belum dibayar)',
    $noHolding['status'] === 422 && QcFixture::bridgeSnapshot()['count_active'] === 1,
    'status=' . $noHolding['status'] . ' aktif=' . QcFixture::bridgeSnapshot()['count_active']
);

// Pokok melebihi kapasitas kolom DECIMAL(14,2): ditolak tanpa tulisan.
$overflow = qc6_talangan($operatorJar, [
    'lender_name'      => 'Pak Besar',
    'principal_amount' => '9.999.999.999.999',
    'fee_type'         => 'fixed',
    'fee_rate_pct'     => '',
    'fee_fixed'        => '0',
]);

Qc::ok(
    'F.4',
    'Pokok melebihi kapasitas kolom ditolak 422 tanpa tulisan',
    $overflow['status'] === 422
        && QcFixture::bridgeSnapshot()['count_active'] === 1,
    'status=' . $overflow['status'] . ' aktif=' . QcFixture::bridgeSnapshot()['count_active']
);

$pctNoRate = qc6_talangan($operatorJar, [
    'lender_name'      => 'Pak Tarif',
    'principal_amount' => '1.000.000',
    'fee_type'         => 'pct',
    'fee_rate_pct'     => '',
    'fee_fixed'        => '10.000',
]);

Qc::ok(
    'F.5',
    'Bentuk fee Persen tanpa tarif ditolak 422 tanpa tulisan',
    $pctNoRate['status'] === 422 && QcFixture::bridgeSnapshot()['count_active'] === 1,
    'status=' . $pctNoRate['status'] . ' aktif=' . QcFixture::bridgeSnapshot()['count_active']
);

// Talangan tanpa fee (nominal kosong = 0,00) tetap sah dan mengembalikan pokok saja.
QcFixture::resetSettlementState();
$tellerId = (string) (CashRegister::activeTeller()['id'] ?? '');
$noFee = qc6_talangan($operatorJar, [
    'lender_name'      => 'Pak Baik Hati',
    'principal_amount' => '5.000.000',
    'fee_type'         => 'fixed',
    'fee_rate_pct'     => '',
    'fee_fixed'        => '',
]);

$noFeeLoan = Database::fetchOne(
    'SELECT * FROM `bridge_loans` WHERE `lender_name` = :name LIMIT 1',
    ['name' => 'Pak Baik Hati']
);

Qc::ok(
    'F.6',
    'Talangan tanpa fee (nominal kosong) sah: fee_amount 0,00 dan status active',
    $noFee['status'] === 302
        && qc6_money($noFeeLoan['fee_amount'] ?? '') === '0.00'
        && (string) ($noFeeLoan['status'] ?? '') === 'active',
    'status=' . $noFee['status'] . ' fee=' . qc6_money($noFeeLoan['fee_amount'] ?? '-')
);

$knownSettlements = qc6_settlement_ids();
qc6_seedExpense();

$noFeeSettle = qc6_settle($operatorJar, [
    'sold_grams'       => '18,050',
    'density_pct'      => '93,50',
    'shop_price_per_g' => '2.080.000',
    'coord_fee'        => '200.000',
    'kas_split'        => '',
    'brankas_split'    => '',
], $receiptPath);

$noFeeRow = qc6_new_settlement($knownSettlements);
$noFeeOut = qc6_bridge_mutations($tellerId);

Qc::ok(
    'F.7',
    'Talangan tanpa fee: pengembalian = pokok saja, fee_absorbed 0,00, hak pengelola utuh',
    $noFeeSettle['status'] === 302
        && count($noFeeOut) === 1
        && qc6_money($noFeeOut[0]['amount']) === '5000000.00'
        && qc6_operator_take_home(
            (string) ($noFeeRow['id'] ?? ''),
            (string) ($noFeeRow['operator_share_80'] ?? '0.00')
        ) === '1749200.00',
    'mutasi=' . count($noFeeOut) . ' hak_bersih=' . qc6_operator_take_home(
        (string) ($noFeeRow['id'] ?? ''),
        (string) ($noFeeRow['operator_share_80'] ?? '0.00')
    )
);

// ---------------------------------------------------------------------------
// G. Kontrak view, pemindaian statis, dan route
// ---------------------------------------------------------------------------

Qc::section('G. Kontrak view, pemindaian statis, dan route baru');

$tellerView = (string) file_get_contents(APP_PATH . '/Views/operator/teller.php');
$settlementView = (string) file_get_contents(APP_PATH . '/Views/operator/settlement.php');
$routes = (string) file_get_contents(APP_PATH . '/routes.php');
$newSources = [
    APP_PATH . '/Services/BridgeLoanService.php',
    APP_PATH . '/Controllers/TellerController.php',
    APP_PATH . '/Views/operator/teller.php',
];

$sqlLeaks = qc6_scan(array_merge($newSources, [APP_PATH . '/Views/operator/settlement.php']), static fn (
    string $line
): bool => preg_match('/\b(SELECT\s|INSERT INTO|UPDATE\s|DELETE FROM|ORDER BY|LIMIT\s)/', $line) === 1
    && !str_contains($line, 'in_array')
);

Qc::ok(
    'G.1',
    'Tidak ada SQL di view & controller (SQL hanya hidup di app/Models)',
    $sqlLeaks === [],
    implode("\n", $sqlLeaks)
);

$floatLeaks = qc6_scan(
    $newSources,
    static fn (string $line): bool => preg_match(
        '/\bfloatval\s*\(|\bdoubleval\s*\(|\(\s*(float|double)\s*\)|\bFLOAT\b|\bDOUBLE\b|number_format\s*\(/',
        $line
    ) === 1
);

Qc::ok(
    'G.2',
    'Tidak ada float/double/number_format pada jalur uang talangan',
    $floatLeaks === [],
    implode("\n", $floatLeaks)
);

Qc::ok(
    'G.3',
    'Kedua jalur POST memeriksa CSRF + token sekali-pakai `_once`',
    str_contains($routes, "'/operator/teller/talangan'")
        && str_contains($routes, 'storeBridge')
        && str_contains(file_get_contents(APP_PATH . '/Controllers/TellerController.php') ?: '', 'assertPostSecurity'),
    'route & guard talangan terdaftar'
);

$bridgeControls = ['lender_name', 'principal_amount', 'fee_type', 'fee_rate_pct', 'fee_fixed', 'notes'];
$controlProblems = [];

foreach ($bridgeControls as $control) {
    $block = $control === 'fee_type'
        ? (string) (preg_split('/<input\b/', $tellerView)[1] ?? '')
        : qc6_input_block($tellerView, $control);

    if ($block === '') {
        $controlProblems[] = $control . ': kontrol tidak ditemukan';

        continue;
    }

    if (preg_match('/<input\b[^>]*:disabled/', $block) === 1) {
        $controlProblems[] = $control . ': memakai :disabled (kontrol hilang dari payload)';
    }
}

Qc::ok(
    'G.4',
    'Kontrol modal talangan memakai :readonly (bukan :disabled) sehingga isian tetap terkirim',
    $controlProblems === []
        && substr_count($tellerView, ':readonly="bridgeSubmitting"') >= 6,
    implode("\n", $controlProblems)
);

Qc::ok(
    'G.5',
    'Placeholder numerik modal berawalan "mis. " (contoh, bukan data)',
    substr_count($tellerView, 'placeholder="mis. ') >= 4
        && !preg_match('/placeholder="\d/', $tellerView),
    'jumlah placeholder contoh=' . substr_count($tellerView, 'placeholder="mis. ')
);

$unescaped = qc6_scan(
    [APP_PATH . '/Views/operator/teller.php', APP_PATH . '/Views/operator/settlement.php'],
    static fn (string $line): bool => preg_match('/<\?=\s*\$(?!content\b)/', $line) === 1
);

Qc::ok(
    'G.6',
    'Seluruh output dinamis pada kedua view melewati Security::e()',
    $unescaped === [],
    implode("\n", $unescaped)
);

Qc::ok(
    'G.7',
    'Kartu talangan pada layar rekonsiliasi memuat kewajiban & catatan pemisahan hak',
    str_contains($settlementView, 'Talangan Aktif')
        && str_contains($settlementView, 'Hak pengelola bersih')
        && str_contains($settlementView, 'pool mitra')
        // Sejak perbaikan UI pascarilis, peringatan tak tertutup memakai
        // kondisi bernama `bridgeHasUncovered` (dijaga G.13–G.16).
        && str_contains($settlementView, 'x-show="bridgeWarnUncovered"'),
    'kartu & baris hak pengelola bersih tersedia'
);

$jsSource = (string) file_get_contents(PUBLIC_PATH . '/assets/js/app.js');

Qc::ok(
    'G.8',
    'Modal talangan memakai komponen tellerForm yang sudah ada (tanpa Alpine.data baru)',
    str_contains($tellerView, 'x-data="tellerForm(')
        && str_contains($tellerView, 'x-cloak')
        && !str_contains($jsSource, "Alpine.data('bridgeForm'")
        && !str_contains($jsSource, "Alpine.data('tellerBridgeForm'"),
    'komponen terdaftar: ' . (substr_count($jsSource, "Alpine.data('") + 0)
);

Qc::ok(
    'G.9',
    'Layar kasir merender kartu "Talangan Aktif" (kewajiban terlihat sebelum menerima dana baru)',
    str_contains($tellerView, 'Tidak ada dana talangan berjalan')
        && str_contains($tellerView, 'Total kembali dari kas meja'),
    'kartu talangan aktif tersedia'
);

Qc::ok(
    'G.10',
    'Handler route talangan benar-benar ada pada controller yang terdaftar',
    method_exists(\AurumFlow\Controllers\TellerController::class, 'storeBridge')
        && in_array('storeBridge', get_class_methods(\AurumFlow\Controllers\TellerController::class), true),
    'storeBridge tersedia'
);

$controllerSql = qc6_scan(
    [APP_PATH . '/Controllers/TellerController.php'],
    static fn (string $line): bool => preg_match('/\b(SELECT\s|INSERT INTO|UPDATE\s|DELETE FROM)\b/', $line) === 1
);

Qc::ok(
    'G.11',
    'Controller talangan tidak memuat SQL (satu pintu lewat model/service)',
    $controllerSql === [],
    implode("\n", $controllerSql)
);

$manualTransactions = qc6_scan(
    [APP_PATH . '/Services/BridgeLoanService.php', APP_PATH . '/Services/SettlementService.php'],
    static fn (string $line): bool => preg_match('/\b(beginTransaction|rollBack|commit)\s*\(/', $line) === 1
);

Qc::ok(
    'G.12',
    'Transaksi selalu lewat Database::transaction() (tanpa begin/commit manual)',
    $manualTransactions === [],
    implode("\n", $manualTransactions)
);

// ---------------------------------------------------------------------------
// G4. Regresi UI (temuan uji manual Fase 6): baris fee talangan TIDAK BOLEH
//     bergantung pada laba sesi ini. Hanya PERINGATAN fee tak tertutup yang
//     boleh mengikuti angka laba; barisnya sendiri tampil selama ada talangan
//     aktif. Ketiga ekspresi `x-show` diambil apa adanya dari view.
// ---------------------------------------------------------------------------

$feeRowExpr = qc6_show_expression($settlementView, 'Fee talangan (');
$takeHomeRowExpr = qc6_show_expression($settlementView, 'Hak pengelola bersih');
$uncoveredNoteExpr = qc6_show_expression($settlementView, 'Sebagian fee talangan (');

Qc::ok(
    'G.13',
    'Baris "Fee talangan" & "Hak pengelola bersih" memakai kondisi talangan-aktif yang sama',
    $feeRowExpr === 'bridgeHasActive' && $takeHomeRowExpr === $feeRowExpr,
    'fee=' . (string) $feeRowExpr . ' bersih=' . (string) $takeHomeRowExpr
);

Qc::ok(
    'G.14',
    'Kondisi baris fee tidak menyebut angka laba (bukan operatorCents/netCents/uncovered)',
    $feeRowExpr !== null
        && preg_match('/operatorCents|netCents|Uncovered|poolCents|bridgeFeeCents/', $feeRowExpr) !== 1,
    'ekspresi=' . (string) $feeRowExpr
);

Qc::ok(
    'G.15',
    'Peringatan fee tak tertutup memakai kondisinya sendiri (terpisah dari baris)',
    $uncoveredNoteExpr === 'bridgeWarnUncovered' && $uncoveredNoteExpr !== $feeRowExpr,
    'peringatan=' . (string) $uncoveredNoteExpr
);

Qc::ok(
    'G.16',
    'Kondisi bernama itu ada di `settlementForm` (satu pemilik keputusan tampil/tidak)',
    str_contains($jsSource, 'get bridgeHasActive()')
        && str_contains($jsSource, 'get bridgeHasUncovered()')
        && str_contains($jsSource, 'return this.bridgeCount > 0;')
        && str_contains($jsSource, 'return this.bridgeUncoveredCents > 0;')
        && str_contains($jsSource, 'get bridgeWarnUncovered()')
        && str_contains($jsSource, 'return this.bridgeHasUncovered && this.inputValid;'),
    'getter bridgeHasActive/bridgeHasUncovered/bridgeWarnUncovered tersedia di app.js'
);

// ---------------------------------------------------------------------------
// G5. Versi cache shell PWA (temuan tinjauan pascarilis).
//
// `public/sw.js` menyajikan aset statis CACHE-FIRST, sedangkan Fase 6 mengubah
// `app.js` + `app.min.css`. Tanpa kenaikan `CACHE_NAME`, operator yang pernah
// membuka layar operator tetap menjalankan `app.js` LAMA bersama view BARU —
// `bridgeHasActive` tidak ada di komponen, sehingga baris fee talangan tidak
// pernah muncul (gejala uji manual Fase 6). Assertion ini mengikat versi shell
// pada satu pemilik yang tercatat di `AGENTS.md`, supaya kenaikan yang lupa
// didokumentasikan (atau sebaliknya) langsung gagal.
// ---------------------------------------------------------------------------

$serviceWorker = (string) file_get_contents(PUBLIC_PATH . '/sw.js');
$agentsDocSource = (string) file_get_contents(BASE_PATH . '/AGENTS.md');

Qc::ok(
    'G.17',
    'Service worker mem-precache `app.js` & `app.min.css` dengan versi shell yang sudah dinaikkan (v5)',
    str_contains($serviceWorker, "'aurumflow-shell-v5'")
        && str_contains($serviceWorker, "'./assets/js/app.js'")
        && str_contains($serviceWorker, "'./assets/css/app.min.css'"),
    'CACHE_NAME = ' . (preg_match("/CACHE_NAME = '([^']+)'/", $serviceWorker, $cacheMatch) === 1
        ? $cacheMatch[1]
        : 'tidak ditemukan')
);

Qc::ok(
    'G.18',
    'Versi shell satu pemilik: `AGENTS.md` menyebut versi yang sama dengan sw.js',
    preg_match("/CACHE_NAME = '([^']+)'/", $serviceWorker, $owner) === 1
        && str_contains($agentsDocSource, '`' . $owner[1] . '`'),
    'versi=' . (string) ($owner[1] ?? '-')
);

// ---------------------------------------------------------------------------
// H. Integritas skema (tabel ke-10, nol ALTER) & dokumentasi
// ---------------------------------------------------------------------------

Qc::section('H. Integritas skema & dokumen (nol ALTER pada tabel lama)');

$bridgeColumns = Database::fetchAll(
    'SELECT `column_name` AS `name`, `DATA_TYPE` AS `type`, `IS_NULLABLE` AS `nullable` '
    . 'FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table',
    ['table' => 'bridge_loans']
);

$bridgeColumnNames = array_map(static fn (array $row): string => (string) $row['name'], $bridgeColumns);
sort($bridgeColumnNames);

Qc::equals(
    'H.1',
    'Tabel bridge_loans ada dengan 15 kolom persis seperti ERD § 4 point 10',
    [
        'created_at', 'fee_absorbed', 'fee_amount', 'fee_rate_pct', 'fee_type', 'fee_uncovered',
        'id', 'lender_name', 'notes', 'principal_amount', 'received_at', 'register_id',
        'settlement_id', 'status', 'updated_at',
    ],
    $bridgeColumnNames
);

$bridgeId = QcFixture::columnDefinition('bridge_loans', 'id');

Qc::ok(
    'H.2',
    'Primary key bridge_loans = CHAR(36) UUID v4 (anti-enumeration)',
    $bridgeId !== null
        && strtolower((string) $bridgeId['COLUMN_TYPE']) === 'char(36)'
        && strtoupper((string) $bridgeId['IS_NULLABLE']) === 'NO',
    'tipe=' . (string) ($bridgeId['COLUMN_TYPE'] ?? '-')
        . ' nullable=' . (string) ($bridgeId['IS_NULLABLE'] ?? '-')
);

$decimalViolations = QcFixture::checkDecimalConventions([
    ['bridge_loans', 'principal_amount', 14, 2],
    ['bridge_loans', 'fee_amount', 14, 2],
    ['bridge_loans', 'fee_absorbed', 14, 2],
    ['bridge_loans', 'fee_uncovered', 14, 2],
    ['bridge_loans', 'fee_rate_pct', 5, 2],
]);

Qc::ok(
    'H.3',
    'Konvensi DECIMAL ERD § 3.1: uang (14,2) dan persen (5,2) pada tabel baru',
    $decimalViolations === [],
    implode("\n", $decimalViolations)
);

$feeTypeColumn = QcFixture::columnDefinition('bridge_loans', 'fee_type');
$statusColumn = QcFixture::columnDefinition('bridge_loans', 'status');

Qc::equals(
    'H.4',
    'Enum bentuk fee & status persis seperti ERD',
    ["enum('pct','fixed')", "enum('active','settled')"],
    [
        strtolower((string) ($feeTypeColumn['COLUMN_TYPE'] ?? '')),
        strtolower((string) ($statusColumn['COLUMN_TYPE'] ?? '')),
    ]
);

$foreignKeys = Database::fetchAll(
    'SELECT `constraint_name` AS `name`, `delete_rule` AS `rule`, `referenced_table_name` AS `target` '
    . 'FROM information_schema.referential_constraints '
    . 'WHERE constraint_schema = DATABASE() AND table_name = :table ORDER BY `constraint_name`',
    ['table' => 'bridge_loans']
);

Qc::equals(
    'H.5',
    'FK talangan: sesi tutup buku & pos kas, keduanya ON DELETE RESTRICT (jejak audit terjaga)',
    [
        ['fk_bridge_register', 'RESTRICT', 'cash_registers'],
        ['fk_bridge_settlement', 'RESTRICT', 'daily_settlements'],
    ],
    array_map(
        static fn (array $row): array => [
            (string) $row['name'],
            (string) $row['rule'],
            (string) $row['target'],
        ],
        $foreignKeys
    )
);

$settlementColumns = Database::fetchAll(
    'SELECT `column_name` AS `name` FROM information_schema.columns '
    . 'WHERE table_schema = DATABASE() AND table_name = :table',
    ['table' => 'daily_settlements']
);

$settlementColumnCount = count($settlementColumns);
$bridgeInSettlement = array_filter(
    array_map(static fn (array $row): string => (string) $row['name'], $settlementColumns),
    static fn (string $name): bool => str_starts_with($name, 'bridge') || str_contains($name, 'talangan')
);

Qc::ok(
    'H.6',
    'NOL ALTER pada daily_settlements: kolomnya tetap 15 dan tidak memuat kolom fee talangan',
    $settlementColumnCount === 15 && $bridgeInSettlement === [],
    'kolom=' . $settlementColumnCount . ' tambahan=' . implode(',', $bridgeInSettlement)
);

$allTables = array_map(
    static fn (array $row): string => (string) $row['name'],
    Database::fetchAll('SELECT `table_name` AS `name` FROM information_schema.tables WHERE table_schema = DATABASE()')
);
sort($allTables);

Qc::equals(
    'H.7',
    'Skema kini 16 tabel ERD (+ officers & officer_ledger_entries pada Fase 10)',
    [
        'bridge_loans', 'capital_wallets', 'cash_mutations', 'cash_registers', 'daily_settlements',
        'hulu_transactions', 'miner_loan_ledger', 'miner_loans', 'miners',
        'officer_ledger_entries', 'officers',
        'operational_expenses', 'profit_distributions', 'system_settings', 'users', 'withdrawal_requests',
    ],
    $allTables
);

QcFixture::resetSettlementState();
$snapshotFirst = QcFixture::bridgeSnapshot();
$tellerFirst = QcFixture::registerBalance((string) (CashRegister::activeTeller()['id'] ?? ''));

QcFixture::resetSettlementState();
$snapshotSecond = QcFixture::bridgeSnapshot();
$tellerSecond = QcFixture::registerBalance((string) (CashRegister::activeTeller()['id'] ?? ''));

Qc::ok(
    'H.8',
    'Fixture idempotent: dua kali `resetSettlementState()` menghasilkan keadaan identik',
    $snapshotFirst === $snapshotSecond && $tellerFirst === $tellerSecond,
    'snapshot=' . json_encode($snapshotFirst)
);

Qc::ok(
    'H.9',
    'Fixture membersihkan bridge_loans sebelum daily_settlements (FK RESTRICT)',
    QcFixture::bridgeSnapshot()['count_active'] === 0
        && QcFixture::bridgeSnapshot()['count_settled'] === 0
        && count(qc6_settlement_ids()) === 0,
    'aktif=' . QcFixture::bridgeSnapshot()['count_active']
        . ' settled=' . QcFixture::bridgeSnapshot()['count_settled']
);

$erdDoc = (string) file_get_contents(BASE_PATH . '/docs/AurumFlow Entity Relationship Diagram.md');
$roadmapDoc = (string) file_get_contents(BASE_PATH . '/docs/AurumFlow Roadmap and QC Documents.md');
$prdDoc = (string) file_get_contents(BASE_PATH . '/docs/AurumFlow Product Requirement Document v3.0.md');
$sadDoc = (string) file_get_contents(BASE_PATH . '/docs/AurumFlow System Architecture Document.md');
$uiDoc = (string) file_get_contents(BASE_PATH . '/docs/AurumFlow UI UX Documents.md');
$agentsDoc = (string) file_get_contents(BASE_PATH . '/AGENTS.md');
$readmeDoc = (string) file_get_contents(BASE_PATH . '/README.md');
$seedDoc = (string) file_get_contents(BASE_PATH . '/database/seed.sql');

Qc::ok(
    'H.10',
    'ERD memuat tabel ke-10 + kardinalitas bridge_loans (sumber kebenaran lebih dulu)',
    str_contains($erdDoc, 'CREATE TABLE bridge_loans')
        && str_contains($erdDoc, 'cash_registers` → `bridge_loans`')
        && str_contains($erdDoc, 'daily_settlements` → `bridge_loans`'),
    'dokumen ERD diperbarui'
);

Qc::ok(
    'H.11',
    'Roadmap memuat FASE 6A + Protokol QC Manual 6A; PRD § 3.5; SAD § 4.5; UI/UX Layar B2',
    str_contains($roadmapDoc, 'FASE 6A')
        && str_contains($roadmapDoc, 'Protokol QC Manual 6A')
        && str_contains($prdDoc, '3.5. Jembatan Likuiditas')
        && str_contains($sadDoc, '4.5. Jembatan Likuiditas Dana Talangan')
        && str_contains($uiDoc, 'Layar B2'),
    'dokumen fasa 6 diperbarui'
);

Qc::ok(
    'H.12',
    'AGENTS.md/README memuat kontrak talangan & daftar terlarang yang diperluas',
    str_contains($agentsDoc, 'Dana talangan (Fase 6)')
        && str_contains($agentsDoc, '`BridgeLoan`/`bridge_loans`')
        && str_contains($readmeDoc, '7.13 Dana talangan'),
    'kontrak proyek diperbarui'
);

Qc::ok(
    'H.13',
    'seed.sql tidak memuat baris talangan (tabel baru terpasang lewat schema.sql)',
    !str_contains($seedDoc, 'bridge_loans'),
    'seed bersih'
);

Qc::ok(
    'H.14',
    'Nol tautan Compose/vendor baru: komponen talangan memakai native PHP',
    !is_file(BASE_PATH . '/composer.json') && !is_dir(BASE_PATH . '/vendor'),
    'composer.json/vendor tidak ada'
);

// ---------------------------------------------------------------------------
// Pemulihan keadaan
// ---------------------------------------------------------------------------

QcFixture::resetSettlementState();
QcFixture::purgeReceiptFiles();

Qc::ok(
    'P6A.9',
    'Pemulihan: tidak ada talangan aktif tersisa dan direktori nota bersih dari berkas QC',
    QcFixture::bridgeSnapshot()['count_active'] === 0
        && QcFixture::receiptFiles() === [],
    'aktif=' . QcFixture::bridgeSnapshot()['count_active']
        . ' nota=' . count(QcFixture::receiptFiles())
);

Qc::note('Angka acuan Protokol 6A (fee Rp 100.000 dari pokok Rp 10.000.000) dihasilkan suite ini.');

Qc::finish('qc_fase_6');
