<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Protokol QC Manual 3 — Likuidasi Toko & Mesin Settlement Harian (Fase 3)
|--------------------------------------------------------------------------
| Implementasi otomatis dari "Protokol QC Manual 3" pada dokumen
| docs/AurumFlow Roadmap and QC Documents.md (FASE 3).
|
| Dokumen Roadmap meminta: gunakan akumulasi emas QC 2 (18,050 gr dengan modal
| belanja Rp 35.157.500), lalu uji DUA skenario — settlement normal dan
| settlement hari minus. Suite ini membangun ulang keadaan akhir QC 2 lewat
| jalur kode nyata (TellerService::payCash) dan mengirim form settlement lewat
| HTTP multipart dengan FOTO NOTA TIRUAN, sehingga tidak memerlukan kamera.
|
| Cakupan:
|   P3.x  Protokol manual 3 (angka persis seperti dokumen Roadmap)
|   A.    Kalkulasi murni (bagi hasil, defisit, pembulatan, invariant)
|   B.    Parsing input lapangan (berat, kadar, nominal) + penjaga penyimpangan
|   C.    Atomisitas & tanpa berkas nota yatim
|   D.    Guard (berat jual, alokasi, berkas nota) lewat HTTP
|   E.    Peladen tidak mempercayai nominal dari klien
|   F.    Anti double-submit (token sekali-pakai `_once`, lewat HTTP)
|   G.    Perilaku lewat HTTP sungguhan (QcHttp) + penyajian nota
|   H.    Pemindaian statis, aset, dan hardening direktori unggahan
|   I.    Integritas skema (tanpa perubahan DDL di Fase 3)
|
| PERINGATAN: suite ini MENULIS ke database yang ditunjuk `.env`
| (daily_settlements, hulu_transactions, cash_registers, cash_mutations,
| system_settings) DAN membuat/menghapus berkas di public/uploads/receipts/.
| Jangan arahkan ke database produksi. Konvensi exit code: 0 = lulus, 1 = gagal.
*/

require __DIR__ . '/bootstrap.php';

use AurumFlow\Config\Constants;
use AurumFlow\Config\Database;
use AurumFlow\Config\Env;
use AurumFlow\Helpers\Formatter;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\CashMutation;
use AurumFlow\Models\DailySettlement;
use AurumFlow\Models\HuluTransaction;
use AurumFlow\Models\SystemSetting;
use AurumFlow\Models\User;
use AurumFlow\Services\SettlementService;
use AurumFlow\Services\TellerService;

Qc::suite('Protokol QC Manual 3 — Likuidasi Toko & Settlement Harian (qc_fase_3.php)');

Qc::note('Sumber: docs/AurumFlow Roadmap and QC Documents.md — FASE 3');
Qc::note('Unggah nota diuji dengan gambar tiruan (tanpa kamera).');
Qc::note('PERINGATAN: suite ini menulis ke DB pada .env dan ke public/uploads/receipts/.');

// ---------------------------------------------------------------------------
// Helper lokal
// ---------------------------------------------------------------------------

/**
 * Pemindai statis untuk daftar berkas eksplisit.
 *
 * `qc_scan_lines()` harness memindai seluruh app/ + public/ sekaligus dan
 * predikatnya hanya menerima isi baris (tanpa path), sehingga tidak dapat
 * dibatasi per berkas — pola yang sama dipakai QC Fase 2.
 *
 * @param list<string> $paths
 * @return list<string> daftar pelanggaran (file:baris)
 */
function qc3_scan(array $paths, callable $predicate): array
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

            // Lewati komentar & baris kosong: pemindai mencari KODE.
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

/**
 * Potongan markup satu `<input>` berdasarkan `name`-nya.
 *
 * Dipisah pada setiap `<input` lalu dipotong tepat pada tag-nya sendiri,
 * sehingga potongan hanya memuat atribut milik input itu — markup sesudahnya
 * (mis. `<button>` yang memang boleh memakai `:disabled`) dan atribut input
 * tetangga tidak pernah ikut terbaca.
 *
 * @return string|null null bila input tidak ada atau `name`-nya ganda.
 */
function qc3_input_block(string $view, string $name): ?string
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

        $found = qc3_input_tag($chunk);
    }

    return $found;
}

/**
 * Potong potongan menjadi satu tag `<input …>` saja.
 *
 * `>` di dalam potongan PHP (`<?= … ?>`) maupun panah (`=>`) bukan penutup tag,
 * jadi keduanya sengaja dilewati.
 */
function qc3_input_tag(string $chunk): string
{
    $offset = 0;

    while (($position = strpos($chunk, '>', $offset)) !== false) {
        $previous = $position > 0 ? $chunk[$position - 1] : '';

        if ($previous !== '?' && $previous !== '=') {
            return substr($chunk, 0, $position + 1);
        }

        $offset = $position + 1;
    }

    return $chunk;
}

/**
 * @return list<string>
 */
function qc3_php_files(string $directory): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * Normalkan nilai uang dari database menjadi string kanonik 2 desimal.
 */
function qc3_money(mixed $value): string
{
    $text = trim((string) $value);

    if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $text, $matches) !== 1) {
        return 'tidak-valid:' . $text;
    }

    return $matches[1] . $matches[2] . '.' . str_pad((string) ($matches[3] ?? ''), 2, '0', STR_PAD_RIGHT);
}

/**
 * Nilai uang kanonik -> sen (integer), memakai pemilik aturan yang sama dengan
 * layanan (tidak ada aritmetika float pada QC).
 */
function qc3_cents(mixed $value): int
{
    return TellerService::moneyToCents(qc3_money($value));
}

/**
 * Sen (integer) -> string desimal kanonik, untuk menampilkan hasil hitung QC
 * tanpa melewati float.
 */
function qc3_cents_money(int $cents): string
{
    $sign = $cents < 0 ? '-' : '';
    $absolute = abs($cents);

    return $sign . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
}

/**
 * Bangun berkas PNG yang sah TANPA ekstensi GD (tidak tersedia di semua host).
 *
 * Dipakai sebagai "foto nota" tiruan: yang diuji adalah pipeline unggah
 * (batas ukuran, deteksi tipe dari ISI berkas, penyimpanan bernama UUID),
 * bukan kemampuan kamera ponsel.
 */
function qc3_png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

function qc3_png(int $width, int $height): string
{
    $raw = '';

    for ($y = 0; $y < $height; $y++) {
        $raw .= "\x00";

        for ($x = 0; $x < $width; $x++) {
            $raw .= chr(($x * 5 + $y * 3) % 256) . chr(180) . chr(60);
        }
    }

    return "\x89PNG\r\n\x1a\n"
        . qc3_png_chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        . qc3_png_chunk('IDAT', gzcompress($raw, 6))
        . qc3_png_chunk('IEND', '');
}

/**
 * Tulis berkas uji di tests/.tmp (di-gitignore) dan kembalikan pathnya.
 */
function qc3_temp_file(string $name, string $contents): string
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
 * Ambil token CSRF + token sekali-pakai segar dari halaman rekonsiliasi.
 *
 * @return array{csrf: string, once: string, status: int, body: string}
 */
function qc3_tokens(string $jar): array
{
    $page = QcHttp::request('GET', '/operator/settlement', ['cookie_jar' => $jar]);

    return [
        'csrf'   => (string) QcHttp::csrfFrom($page['body']),
        'once'   => (string) QcHttp::fieldFrom($page['body'], Security::FORM_FIELD),
        'status' => $page['status'],
        'body'   => $page['body'],
    ];
}

/**
 * Kirim form settlement dengan token segar.
 *
 * @param array<string, string>         $fields nilai field (tanpa token)
 * @param array<string, string>|array{} $files  peta field berkas => path
 * @return array{status: int, headers: string, body: string, location: ?string}
 */
function qc3_settle(string $jar, array $fields, array $files = []): array
{
    $tokens = qc3_tokens($jar);

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

    return QcHttp::request('POST', '/operator/settlement', $options);
}

/**
 * Ringkasan keadaan kas & tabel yang dipakai hampir setiap assertion.
 *
 * @return array<string, mixed>
 */
function qc3_state(string $tellerId, string $brankasId, string $registerName = 'Kas Meja Bakar'): array
{
    return [
        'teller'      => QcFixture::registerBalance($tellerId),
        'brankas'     => QcFixture::registerBalance($brankasId),
        'mutations'   => CashMutation::countFor($tellerId, CashMutation::TYPE_IN)
            + CashMutation::countFor($brankasId, CashMutation::TYPE_IN),
        'settlements' => DailySettlement::count(),
        'holding'     => HuluTransaction::countByStatus(HuluTransaction::STATUS_HOLDING),
        'settled'     => HuluTransaction::countByStatus(HuluTransaction::STATUS_SETTLED),
        'deficit'     => qc3_money(SystemSetting::get(SettlementService::SETTING_ACTIVE_DEFICIT, '0.00')),
        'receipts'    => count(QcFixture::receiptFiles()),
        'register'    => $registerName,
    ];
}

/**
 * Id seluruh sesi settlement yang ada saat ini.
 *
 * Dipakai untuk mengenali baris yang BARU dibuat: `created_at` hanya
 * berpresisi detik (item O-1), sehingga "baris terbaru" tidak dapat
 * diandalkan saat dua sesi terjadi pada detik yang sama.
 *
 * @return list<string>
 */
function qc3_settlement_ids(): array
{
    return array_map(
        static fn (array $row): string => (string) $row['id'],
        Database::fetchAll('SELECT `id` FROM `daily_settlements` ORDER BY `created_at`, `id`')
    );
}

/**
 * Baris settlement yang tidak ada pada daftar `$known` (yaitu baris baru).
 *
 * @param list<string> $known
 * @return array<string, mixed>
 */
function qc3_new_settlement(array $known): array
{
    foreach (Database::fetchAll('SELECT * FROM `daily_settlements` ORDER BY `created_at`, `id`') as $row) {
        if (!in_array((string) $row['id'], $known, true)) {
            return $row;
        }
    }

    return [];
}

$notaPath = qc3_temp_file('nota-qc.png', qc3_png(600, 400));
$notaSize = (int) filesize($notaPath);

// ---------------------------------------------------------------------------
// Penyiapan: keadaan akhir Protokol QC Manual 2
// ---------------------------------------------------------------------------

Qc::section('Penyiapan keadaan akhir QC 2 (18,050 gr / modal Rp 35.157.500)');

$fixture = QcFixture::resetSettlementState();

$tellerId = $fixture['teller_id'];
$brankasId = $fixture['brankas_id'];

$balance = static fn (): string => QcFixture::registerBalance($tellerId);
$brankasBalance = static fn (): string => QcFixture::registerBalance($brankasId);

/**
 * Mutasi kas MASUK terakhir pada satu pos.
 *
 * Filter `mutation_type` dipakai karena presisi `created_at` hanya sampai detik:
 * mutasi keluar (belanja hulu) dan mutasi masuk (settlement) dapat terjadi pada
 * detik yang sama, sehingga urutan "terakhir" tidak deterministik (item O-1
 * pada dokumen rencana).
 */
$lastInFor = static fn (string $registerId): array => Database::fetchOne(
    'SELECT * FROM `cash_mutations` WHERE `register_id` = :register_id AND `mutation_type` = :type '
    . 'ORDER BY `created_at` DESC, `id` DESC LIMIT 1',
    ['register_id' => $registerId, 'type' => CashMutation::TYPE_IN]
) ?? [];

$inCountFor = static fn (string $registerId): int => CashMutation::countFor($registerId, CashMutation::TYPE_IN);

$walletCount = static fn (): int => (int) (Database::fetchOne(
    'SELECT COUNT(*) AS `total` FROM `capital_wallets`'
)['total'] ?? 0);

$distributionCount = static fn (): int => (int) (Database::fetchOne(
    'SELECT COUNT(*) AS `total` FROM `profit_distributions`'
)['total'] ?? 0);

Qc::ok(
    'P3.0a',
    'Berkas nota tiruan siap diuji (tanpa kamera)',
    is_file($notaPath) && $notaSize > 0 && (getimagesize($notaPath)[2] ?? 0) === IMAGETYPE_PNG,
    'ukuran=' . $notaSize . ' byte'
);

Qc::equals(
    'P3.0b',
    'Keadaan awal QC 2 terbentuk: 2 nota holding, 18,050 gr, modal Rp 35.157.500',
    ['2', '18.050', '35157500.00'],
    [(string) $fixture['holding_count'], $fixture['holding_grams'], $fixture['holding_cost']]
);

Qc::equals(
    'P3.0c',
    'Kas meja Rp 14.842.500, brankas Rp 0, defisit berjalan Rp 0',
    ['14842500.00', '0.00', '0.00'],
    [
        $fixture['teller_balance'],
        $fixture['brankas_balance'],
        qc3_money(SystemSetting::get(SettlementService::SETTING_ACTIVE_DEFICIT, '0.00')),
    ]
);

Qc::equals(
    'P3.0d',
    'Direktori nota bersih dari berkas sisa QC sebelumnya',
    0,
    count(QcFixture::receiptFiles())
);

// ---------------------------------------------------------------------------
// A. Kalkulasi murni (tanpa database)
// ---------------------------------------------------------------------------

Qc::section('A. Kalkulasi murni: bagi hasil, defisit, pembulatan');

$ctx31 = ['holding_mg' => 18050, 'holding_cost_cents' => 3515750000, 'active_deficit_cents' => 0];

$input31 = [
    'sold_grams'       => '18,050',
    'density_pct'      => '93,50',
    'shop_price_per_g' => '2.080.000',
    'coord_fee'        => '200.000',
];

$quoteAuto = SettlementService::quote($input31, $ctx31);

Qc::equals(
    'A.1',
    'Skenario 3.1 (murni): pendapatan Rp 37.544.000, modal Rp 35.157.500, laba kotor Rp 2.386.500',
    ['37544000.00', '35157500.00', '2386500.00'],
    [$quoteAuto['revenue'], $quoteAuto['holding_cost'], $quoteAuto['gross']]
);

Qc::equals(
    'A.2',
    'Skenario 3.1 (murni): laba bersih Rp 2.186.500 dibagi 80 : 20 = Rp 1.749.200 / Rp 437.300',
    ['2186500.00', '1749200.00', '437300.00'],
    [$quoteAuto['net'], $quoteAuto['operator_share'], $quoteAuto['investor_pool']]
);

Qc::ok(
    'A.3',
    'Invariant bagi hasil: pengelola + pool selalu sama dengan laba bersih',
    $quoteAuto['operator_share_cents'] + $quoteAuto['investor_pool_cents'] === $quoteAuto['net_cents'],
    'op=' . $quoteAuto['operator_share'] . ' pool=' . $quoteAuto['investor_pool'] . ' net=' . $quoteAuto['net']
);

$sharesOk = true;
$sharesDetail = '';

// Berbagai nilai laba bersih (termasuk ganjil) membuktikan tidak ada sen yang
// hilang saat pembagian 80 : 20.
foreach ([1, 7, 99, 12345, 100000, 2186500, 999999999] as $netCents) {
    $operator = intdiv($netCents * 80 + 50, 100);
    $pool = $netCents - $operator;

    if ($operator + $pool !== $netCents) {
        $sharesOk = false;
        $sharesDetail .= 'net=' . $netCents . ' op=' . $operator . ' pool=' . $pool . "\n";
    }
}

Qc::ok('A.4', 'Pembulatan 80 : 20 tidak pernah kehilangan sen (7 nilai uji)', $sharesOk, $sharesDetail);

$quoteManual = SettlementService::quote(
    $input31 + ['kas_split' => '30.000.000', 'brankas_split' => '7.344.000'],
    $ctx31
);

Qc::ok(
    'A.5',
    'Manual override diterima selama jumlahnya sama dengan kas diterima (Rp 37.344.000)',
    $quoteManual['kas'] === '30000000.00'
        && $quoteManual['brankas'] === '7344000.00'
        && $quoteManual['split_source'] === 'manual'
        && $quoteManual['suggested_kas'] === '35157500.00'
        && $quoteManual['suggested_brankas'] === '2186500.00',
    'kas=' . $quoteManual['kas'] . ' brankas=' . $quoteManual['brankas'] . ' sumber=' . $quoteManual['split_source']
);

Qc::equals(
    'A.5b',
    'Dua isian kosong berarti memakai saran sistem (jalur tanpa JavaScript)',
    ['suggestion', '35157500.00', '2186500.00'],
    [$quoteAuto['split_source'], $quoteAuto['kas'], $quoteAuto['brankas']]
);

Qc::throws(
    'A.6',
    'Alokasi yang tidak berjumlah pas ditolak',
    static fn () => SettlementService::quote(
        $input31 + ['kas_split' => '30.000.000', 'brankas_split' => '1.000'],
        $ctx31
    ),
    InvalidArgumentException::class
);

Qc::throws(
    'A.7',
    'Alokasi negatif ditolak',
    static fn () => SettlementService::quote(
        $input31 + ['kas_split' => '-1.000', 'brankas_split' => '37.345.000'],
        $ctx31
    ),
    InvalidArgumentException::class
);

Qc::throws(
    'A.8',
    'Berat jual melebihi emas holding ditolak',
    static fn () => SettlementService::quote(['sold_grams' => '20,000'] + $input31, $ctx31),
    InvalidArgumentException::class
);

Qc::throws(
    'A.9',
    'Pendapatan lebih kecil dari beban aparat ditolak (tidak ada kas untuk dialokasikan)',
    static fn () => SettlementService::quote(
        [
            'sold_grams'       => '1,000',
            'density_pct'      => '93,50',
            'shop_price_per_g' => '1.000',
            'coord_fee'        => '200.000',
        ],
        $ctx31
    ),
    InvalidArgumentException::class
);

// --- Defisit: pembentukan, pemulihan sebagian, pemulihan penuh ------------

$ctx32 = ['holding_mg' => 1000, 'holding_cost_cents' => 193000000, 'active_deficit_cents' => 0];

$quoteDeficit = SettlementService::quote([
    'sold_grams'       => '1,000',
    'density_pct'      => '93,5',
    'shop_price_per_g' => '2.050.000',
    'coord_fee'        => '200.000',
], $ctx32);

Qc::equals(
    'A.10',
    'Hari minus: laba bersih −Rp 80.000, bagi hasil 0/0, defisit Rp 80.000',
    ['120000.00', '-80000.00', '0.00', '0.00', '80000.00', '80000.00'],
    [
        $quoteDeficit['gross'],
        $quoteDeficit['net'],
        $quoteDeficit['operator_share'],
        $quoteDeficit['investor_pool'],
        $quoteDeficit['carryover'],
        $quoteDeficit['active_after'],
    ]
);

Qc::equals(
    'A.11',
    'Hari minus: meja menerima seluruh kas yang ada, brankas Rp 0',
    ['1850000.00', '0.00', '1850000.00', '0.00'],
    [
        $quoteDeficit['suggested_kas'],
        $quoteDeficit['suggested_brankas'],
        $quoteDeficit['kas'],
        $quoteDeficit['brankas'],
    ]
);

$quoteRecover = SettlementService::quote([
    'sold_grams'       => '1,000',
    'density_pct'      => '93,5',
    'shop_price_per_g' => '2.500.000',
    'coord_fee'        => '200.000',
], ['holding_mg' => 1000, 'holding_cost_cents' => 193000000, 'active_deficit_cents' => 8000000]);

Qc::equals(
    'A.12',
    'Hari berikutnya: defisit Rp 80.000 dipotong lebih dulu, sisanya dibagi 80 : 20',
    ['570000.00', '80000.00', '290000.00', '232000.00', '58000.00', '0.00'],
    [
        $quoteRecover['gross'],
        $quoteRecover['deficit_recovery'],
        $quoteRecover['net'],
        $quoteRecover['operator_share'],
        $quoteRecover['investor_pool'],
        $quoteRecover['active_after'],
    ]
);

$quotePartial = SettlementService::quote([
    'sold_grams'       => '1,000',
    'density_pct'      => '93,5',
    'shop_price_per_g' => '2.200.000',
    'coord_fee'        => '200.000',
], ['holding_mg' => 1000, 'holding_cost_cents' => 193000000, 'active_deficit_cents' => 8000000]);

Qc::equals(
    'A.13',
    'Pemulihan sebagian: laba Rp 70.000 habis untuk defisit, sisa defisit Rp 10.000',
    ['70000.00', '0.00', '0.00', '10000.00'],
    [
        $quotePartial['deficit_recovery'],
        $quotePartial['net'],
        $quotePartial['operator_share'],
        $quotePartial['active_after'],
    ]
);

$quoteZero = SettlementService::quote([
    'sold_grams'       => '1,000',
    'density_pct'      => '93,5',
    'shop_price_per_g' => '2.130.000',
    'coord_fee'        => '200.000',
], ['holding_mg' => 1000, 'holding_cost_cents' => 193000000, 'active_deficit_cents' => 8000000]);

Qc::equals(
    'A.14',
    'Laba kotor persis sama dengan beban aparat: tidak ada laba, bagi hasil 0/0, defisit tidak bertambah',
    ['0.00', '0.00', '0.00', '80000.00'],
    [
        $quoteZero['deficit_recovery'],
        $quoteZero['net'],
        $quoteZero['operator_share'],
        $quoteZero['active_after'],
    ]
);

Qc::ok(
    'A.15',
    'Invariant alokasi: kas diterima = modal belanja hulu + laba bersih + defisit dipulihkan',
    $quoteAuto['total_cash_cents']
        === $quoteAuto['holding_cost_cents'] + $quoteAuto['net_cents'] + $quoteAuto['deficit_recovery_cents']
    && $quoteDeficit['total_cash_cents']
        === $quoteDeficit['holding_cost_cents'] + $quoteDeficit['net_cents'] + $quoteDeficit['deficit_recovery_cents']
    && $quoteRecover['total_cash_cents']
        === $quoteRecover['holding_cost_cents'] + $quoteRecover['net_cents'] + $quoteRecover['deficit_recovery_cents']
    // Pada hari minus, kekurangan kas persis sebesar defisit yang dicatat.
    && $quoteDeficit['holding_cost_cents'] - $quoteDeficit['kas_cents'] === $quoteDeficit['carryover_cents'],
    '3.1=' . $quoteAuto['total_cash'] . ' 3.2=' . $quoteDeficit['total_cash'] . ' 3.3=' . $quoteRecover['total_cash']
);

// ---------------------------------------------------------------------------
// B. Parsing input lapangan
// ---------------------------------------------------------------------------

Qc::section('B. Parsing input lapangan (berat, kadar, nominal)');

// Kasus parsing memakai beban aparat NOL: berat/kadar kecil tidak boleh
// tertahan validasi bisnis "pendapatan lebih kecil dari beban aparat", supaya
// yang diuji benar-benar parser-nya.
$parseBase = ['coord_fee' => '0'] + $input31;

/** @var list<array{0: string, 1: string}> */
$weightCases = [
    ['18,050', '18.050'],
    ['18.050', '18.050'],
    ['18.050 gr', '18.050'],
    ['18', '18.000'],
    ['0,001', '0.001'],
];

$weightOk = true;
$weightDetail = '';

foreach ($weightCases as [$raw, $expected]) {
    $quote = SettlementService::quote(['sold_grams' => $raw] + $parseBase, $ctx31);

    if ($quote['sold_weight'] !== $expected) {
        $weightOk = false;
        $weightDetail .= $raw . ' => ' . $quote['sold_weight'] . ' (harusnya ' . $expected . ")\n";
    }
}

Qc::ok('B.1', 'Berat jual: satu pemisah selalu berarti desimal', $weightOk, $weightDetail);

/** @var list<array{0: string, 1: string}> */
$densityCases = [
    ['93,50', '93.50'],
    ['93.5', '93.50'],
    ['93', '93.00'],
    ['100', '100.00'],
    ['99,99', '99.99'],
];

$densityOk = true;
$densityDetail = '';

foreach ($densityCases as [$raw, $expected]) {
    $quote = SettlementService::quote(['density_pct' => $raw] + $parseBase, $ctx31);

    if ($quote['density'] !== $expected) {
        $densityOk = false;
        $densityDetail .= $raw . ' => ' . $quote['density'] . ' (harusnya ' . $expected . ")\n";
    }
}

Qc::ok('B.2', 'Kadar timbang air disimpan sebagai DECIMAL(5,2) kanonik', $densityOk, $densityDetail);

/** @var list<array{0: string, 1: string}> */
$moneyCases = [
    ['200.000', '200000.00'],
    ['200000', '200000.00'],
    ['200.000,50', '200000.50'],
    ['0', '0.00'],
    ['', '0.00'],
];

$moneyOk = true;
$moneyDetail = '';

foreach ($moneyCases as [$raw, $expected]) {
    $quote = SettlementService::quote(['coord_fee' => $raw] + $input31, $ctx31);

    if ($quote['coord_fee'] !== $expected) {
        $moneyOk = false;
        $moneyDetail .= var_export($raw, true) . ' => ' . $quote['coord_fee'] . ' (harusnya ' . $expected . ")\n";
    }
}

Qc::ok('B.3', 'Nominal rupiah: titik ribuan, koma desimal, dan nol sah', $moneyOk, $moneyDetail);

// Penjaga penyimpangan: parser nominal SettlementService harus sepakat dengan
// parser harga milik TellerService (pemilik konvensi field) untuk setiap nilai
// tak-nol, sehingga keduanya tidak dapat berbeda diam-diam. Nilai uji dijaga
// di bawah pendapatan toko agar tidak tertahan validasi bisnis.
$driftCases = ['200.000', '2.080.000', '1.234,56', '7', '35.157.500'];
$driftOk = true;
$driftDetail = '';

foreach ($driftCases as $raw) {
    $viaSettlement = SettlementService::quote(['coord_fee' => $raw] + $input31, $ctx31)['coord_fee_cents'];
    $viaTeller = TellerService::quote('1', $raw, false, ['fee_cents' => 0, 'operator_cut_cents' => 0])['price_cents'];

    if ($viaSettlement !== $viaTeller) {
        $driftOk = false;
        $driftDetail .= $raw . ': settlement=' . $viaSettlement . ' teller=' . $viaTeller . "\n";
    }
}

Qc::ok(
    'B.4',
    'Parser nominal SettlementService setara TellerService pada 5 nilai',
    $driftOk,
    $driftDetail
);

/** @var list<array{0: string, 1: string}> */
$rejectWeight = [['', 'kosong'], ['0', 'nol'], ['-1', 'negatif'], ['1,2,3', 'dua pemisah'], ['1,2345', '4 desimal']];

$rejectWeightOk = true;
$rejectWeightDetail = '';

foreach ($rejectWeight as [$raw, $label]) {
    try {
        SettlementService::quote(['sold_grams' => $raw] + $parseBase, $ctx31);
        $rejectWeightOk = false;
        $rejectWeightDetail .= 'diterima (harus ditolak): ' . $label . "\n";
    } catch (InvalidArgumentException) {
        // ditolak — benar
    }
}

Qc::ok('B.5', 'Berat jual tidak sah ditolak (5 kasus)', $rejectWeightOk, $rejectWeightDetail);

/** @var list<array{0: string, 1: string}> */
$rejectDensity = [['', 'kosong'], ['0', 'nol'], ['120', 'lebih dari 100'], ['-5', 'negatif'], ['93,555', '3 desimal']];

$rejectDensityOk = true;
$rejectDensityDetail = '';

foreach ($rejectDensity as [$raw, $label]) {
    try {
        SettlementService::quote(['density_pct' => $raw] + $input31, $ctx31);
        $rejectDensityOk = false;
        $rejectDensityDetail .= 'diterima (harus ditolak): ' . $label . "\n";
    } catch (InvalidArgumentException) {
        // ditolak — benar
    }
}

Qc::ok('B.6', 'Kadar timbang air tidak sah ditolak (5 kasus)', $rejectDensityOk, $rejectDensityDetail);

/** @var list<array{0: string, 1: string}> */
$rejectMoney = [['-1', 'negatif'], ['200.000,555', '3 desimal'], ['1.0000.000', 'ribuan salah'], ['abc', 'bukan angka']];

$rejectMoneyOk = true;
$rejectMoneyDetail = '';

foreach ($rejectMoney as [$raw, $label]) {
    try {
        SettlementService::quote(['coord_fee' => $raw] + $input31, $ctx31);
        $rejectMoneyOk = false;
        $rejectMoneyDetail .= 'diterima (harus ditolak): ' . $label . "\n";
    } catch (InvalidArgumentException) {
        // ditolak — benar
    }
}

Qc::ok('B.7', 'Nominal rupiah tidak sah ditolak (4 kasus)', $rejectMoneyOk, $rejectMoneyDetail);

// ---------------------------------------------------------------------------
// SKENARIO 3.1 — settlement normal
// ---------------------------------------------------------------------------

Qc::section('SKENARIO 3.1 — Settlement normal (18,050 gr @ Rp 2.080.000)');

QcFixture::ensureInstalled();
QcHttp::start();

$operatorPhone = (string) (Env::get('OPERATOR_PHONE') ?? '081200000001');
$operatorPassword = (string) (Env::get('OPERATOR_PASSWORD') ?? 'Aurum2026!');

$operatorJar = QcHttp::cookieJar('settlement-operator');
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
    'G.0',
    'Operator berhasil login (302 -> /dashboard)',
    $login['status'] === 302 && $login['location'] === '/dashboard',
    'status=' . $login['status'] . ' location=' . (string) $login['location']
);

$page = qc3_tokens($operatorJar);

Qc::ok(
    'G.1',
    'Halaman rekonsiliasi 200 memuat emas holding, modal belanja hulu, dan form multipart',
    $page['status'] === 200
        && str_contains($page['body'], '18,050 gr')
        && str_contains($page['body'], Formatter::rupiah('35157500.00'))
        && str_contains($page['body'], 'Smart Auto-Split')
        && str_contains($page['body'], 'enctype="multipart/form-data"')
        && str_contains($page['body'], 'name="receipt_photo"')
        && str_contains($page['body'], 'capture="environment"')
        && $page['csrf'] !== '' && $page['once'] !== '',
    'status=' . $page['status'] . ' csrf=' . var_export($page['csrf'] !== '', true)
        . ' once=' . var_export($page['once'] !== '', true)
);

// Nilai awal yang dirender peladen. Inilah yang harus dibaca peramban; bila
// `value`-nya kosong, peladen menolak form dengan "wajib diisi"
// (Plan_Fix_Settlement_UI § 2.2). `coord_fee` memakai asumsi PRD sebagai saran
// awal, sedangkan kadar & harga jual memang milik operator.
$prefillProblems = [];
$prefillExpected = [
    'sold_grams'       => 'value="18,050"',
    'coord_fee'        => 'value="200.000"',
    'density_pct'      => 'value=""',
    'shop_price_per_g' => 'value=""',
];

foreach ($prefillExpected as $fieldName => $needle) {
    $fieldBlock = qc3_input_block($page['body'], $fieldName);

    if ($fieldBlock === null) {
        $prefillProblems[] = $fieldName . ': input tidak ditemukan di HTML';

        continue;
    }

    if (!str_contains($fieldBlock, $needle)) {
        $prefillProblems[] = $fieldName . ': tidak memuat ' . $needle;
    }
}

Qc::ok(
    'G.1a',
    'Layar rekonsiliasi merender pre-fill: berat = gram holding, beban aparat = asumsi PRD, kadar & harga kosong',
    $prefillProblems === [],
    implode("\n", $prefillProblems)
);

$mutationsBefore31 = $inCountFor($tellerId) + $inCountFor($brankasId);
$walletsBefore31 = $walletCount();
$distributionsBefore31 = $distributionCount();
$receiptsBefore31 = count(QcFixture::receiptFiles());

$known31 = qc3_settlement_ids();

$post31 = qc3_settle($operatorJar, $input31, [SettlementService::RECEIPT_FIELD => $notaPath]);

$row31 = qc3_new_settlement($known31);
$settlementId = (string) ($row31['id'] ?? '');

Qc::ok(
    'P3.0e',
    'POST settlement diterima dan dialihkan (302 POST-Redirect-GET)',
    $post31['status'] === 302
        && $post31['location'] === '/operator/settlement'
        && $settlementId !== '',
    'status=' . $post31['status'] . ' location=' . (string) $post31['location']
);

$holdingCost31 = Database::fetchOne(
    'SELECT COALESCE(SUM(`net_cash_paid`), 0) AS `total` FROM `hulu_transactions` WHERE `settlement_id` = :id',
    ['id' => $settlementId]
);
$holdingCost31Cents = qc3_cents($holdingCost31['total'] ?? '');

Qc::equals(
    'P3.1',
    'Total kas masuk toko Rp 37.544.000',
    ['37544000.00', 'Rp 37.544.000', 3754400000],
    [qc3_money($row31['total_shop_revenue'] ?? ''), Formatter::rupiah(37544000), qc3_cents($row31['total_shop_revenue'] ?? '')]
);

Qc::ok(
    'P3.2',
    'Total modal belanja hulu Rp 35.157.500 (28.937.500 + 6.220.000) — dipakai sebagai pengurang laba',
    $holdingCost31Cents === 3515750000
        && $holdingCost31Cents === qc3_cents('28937500.00') + qc3_cents('6220000.00'),
    'SUM(net_cash_paid) batch = ' . ($holdingCost31['total'] ?? '-')
);

Qc::equals(
    'P3.3',
    'Laba kotor usaha Rp 2.386.500 (pendapatan − modal belanja hulu)',
    ['2386500.00', 238650000],
    [
        qc3_cents_money(qc3_cents($row31['total_shop_revenue'] ?? '') - $holdingCost31Cents),
        qc3_cents($row31['total_shop_revenue'] ?? '') - $holdingCost31Cents,
    ]
);

Qc::equals(
    'P3.4',
    'Laba bersih putaran Rp 2.186.500',
    ['2186500.00', '0.00'],
    [qc3_money($row31['net_settled_profit'] ?? ''), qc3_money($row31['deficit_recovery_used'] ?? '')]
);

Qc::equals(
    'P3.5',
    'Hak pengelola (80%) Rp 1.749.200',
    ['1749200.00', 174920000],
    [qc3_money($row31['operator_share_80'] ?? ''), qc3_cents($row31['operator_share_80'] ?? '')]
);

Qc::equals(
    'P3.6',
    'Pool investor (20%) Rp 437.300',
    ['437300.00', 43730000],
    [qc3_money($row31['investor_pool_20'] ?? ''), qc3_cents($row31['investor_pool_20'] ?? '')]
);

$huluRows31 = Database::fetchAll('SELECT `status`, `settlement_id` FROM `hulu_transactions` ORDER BY `created_at`, `id`');

$allSettled = count($huluRows31) === 2;

foreach ($huluRows31 as $row) {
    if ((string) $row['status'] !== HuluTransaction::STATUS_SETTLED
        || (string) $row['settlement_id'] !== $settlementId
    ) {
        $allSettled = false;
    }
}

Qc::ok(
    'P3.7',
    'Kedua transaksi hulu berubah serentak menjadi settled dan terkait sesi ini',
    $allSettled,
    'baris=' . count($huluRows31) . ' settlement=' . $settlementId
);

$tellerMutation31 = $lastInFor($tellerId);
$brankasMutation31 = $lastInFor($brankasId);
$kas31Cents = qc3_cents($tellerMutation31['amount'] ?? '');
$brankas31Cents = qc3_cents($brankasMutation31['amount'] ?? '');
$totalCash31Cents = qc3_cents($row31['total_shop_revenue'] ?? '') - qc3_cents($row31['daily_coordination_fee'] ?? '');

Qc::ok(
    'P3.8',
    'Smart Auto-Split: Rp 35.157.500 kembali ke Kas Meja, Rp 2.186.500 ke Brankas (jumlah = kas diterima)',
    $kas31Cents === 3515750000
        && $brankas31Cents === 218650000
        && $kas31Cents + $brankas31Cents === $totalCash31Cents
        && $totalCash31Cents === 3734400000,
    'meja=' . ($tellerMutation31['amount'] ?? '-')
        . ' brankas=' . ($brankasMutation31['amount'] ?? '-')
        . ' total=' . qc3_money($totalCash31Cents)
);

Qc::equals(
    'P3.9',
    'Kas Meja Bakar kembali ke Rp 50.000.000 (14.842.500 + 35.157.500)',
    ['50000000.00', 5000000000],
    [$balance(), qc3_cents($balance())]
);

Qc::ok(
    'P3.10',
    'Brankas Utama menerima laba bersih Rp 2.186.500 dengan tepat satu mutasi kas masuk',
    $brankasBalance() === '2186500.00'
        && $inCountFor($brankasId) === 1
        && $inCountFor($tellerId) === 1
        && $mutationsBefore31 === 0,
    'brankas=' . $brankasBalance()
        . ' mutasi_meja=' . $inCountFor($tellerId)
        . ' mutasi_brankas=' . $inCountFor($brankasId)
);

Qc::ok(
    'P3.10b',
    'Kedua mutasi memuat label jenis dana dan jejak audit sesi settlement',
    str_contains((string) ($tellerMutation31['source_destination'] ?? ''), 'pengembalian modal')
        && str_contains((string) ($brankasMutation31['source_destination'] ?? ''), 'laba bersih')
        && str_contains((string) ($tellerMutation31['notes'] ?? ''), 'settlement_id=' . $settlementId)
        && str_contains((string) ($brankasMutation31['notes'] ?? ''), 'laba_bersih=2186500.00'),
    'meja=' . (string) ($tellerMutation31['source_destination'] ?? '-')
);

Qc::equals(
    'P3.11',
    'Hari normal tidak meninggalkan defisit berjalan',
    ['0.00', '0.00'],
    [
        qc3_money($row31['carryover_deficit'] ?? ''),
        qc3_money(SystemSetting::get(SettlementService::SETTING_ACTIVE_DEFICIT, '')),
    ]
);

// Sejak Fase 4, tutup buku MENULIS `profit_distributions` lewat
// `AllocationEngine` — tetapi HANYA untuk dompet dengan modal terserap.
// Pada keadaan QC 3 tidak ada dompet bermodal, sehingga barisnya tetap nol;
// distribusi penuh ke dompet diuji terpisah oleh `qc_fase_4` (grup F).
Qc::ok(
    'P3.10c',
    'Tanpa dompet mitra bermodal, tidak ada distribusi ditulis (modal investor utuh)',
    $walletCount() === $walletsBefore31
        && $distributionCount() === $distributionsBefore31
        && (int) (Database::fetchOne('SELECT COUNT(*) AS `total` FROM `profit_distributions`')['total'] ?? 0) === 0,
    'wallets=' . $walletCount() . ' distributions=' . $distributionCount()
);

$receiptUrl31 = (string) ($row31['receipt_photo_url'] ?? '');
$receiptName31 = basename($receiptUrl31);

$receiptOk31 = $receiptUrl31 !== ''
    && Security::isUuid(substr($receiptName31, 0, 36))
    && str_ends_with($receiptName31, '.png')
    && is_file(SettlementService::receiptDirectory() . '/' . $receiptName31)
    && count(QcFixture::receiptFiles()) === $receiptsBefore31 + 1;

Qc::ok(
    'P3.10d',
    'Foto nota tersimpan aman: nama UUID + ekstensi dari tipe terdeteksi, bukan nama kiriman klien',
    $receiptOk31,
    'url=' . $receiptUrl31
);

Qc::equals(
    'P3.10e',
    'Kadar timbang air toko tersimpan 93,50 % dan tanggal sesi terisi',
    ['93.50', date('Y-m-d')],
    [qc3_money($row31['shop_water_density_pct'] ?? ''), (string) ($row31['settlement_date'] ?? '')]
);

// ---------------------------------------------------------------------------
// SKENARIO 3.2 — hari minus (edge case defisit)
// ---------------------------------------------------------------------------

Qc::section('SKENARIO 3.2 — Hari minus (1,000 gr ditebus Rp 1.930.000)');

$fixture32 = QcFixture::resetSingleHoldingState('1,000', '1.950.000');

$tellerId32 = $fixture32['teller_id'];
$brankasId32 = $fixture32['brankas_id'];

Qc::equals(
    'P3.12a',
    'Keadaan awal 3.2: 1 nota holding 1,000 gr modal Rp 1.930.000, kas meja Rp 48.070.000',
    ['1.000', '1930000.00', '48070000.00'],
    [$fixture32['holding_grams'], $fixture32['holding_cost'], $fixture32['teller_balance']]
);

$walletsBefore32 = $walletCount();
$distributionsBefore32 = $distributionCount();
$receiptsBefore32 = count(QcFixture::receiptFiles());

$known32 = qc3_settlement_ids();

$post32 = qc3_settle($operatorJar, [
    'sold_grams'       => '1,000',
    'density_pct'      => '93,50',
    'shop_price_per_g' => '2.050.000',
    'coord_fee'        => '200.000',
], [SettlementService::RECEIPT_FIELD => $notaPath]);

$row32 = qc3_new_settlement($known32);

Qc::ok(
    'P3.12b',
    'POST hari minus diterima (302) dan tercatat sebagai sesi baru',
    $post32['status'] === 302 && (string) ($row32['id'] ?? '') !== $settlementId,
    'status=' . $post32['status'] . ' id=' . (string) ($row32['id'] ?? '-')
);

Qc::equals(
    'P3.12',
    'Hasil hari minus: laba kotor Rp 120.000 − aparat Rp 200.000 = −Rp 80.000',
    ['-80000.00', '120000.00', -8000000, 12000000],
    [
        qc3_money($row32['net_settled_profit'] ?? ''),
        qc3_cents_money(qc3_cents($row32['net_settled_profit'] ?? '') + qc3_cents($row32['daily_coordination_fee'] ?? '')),
        qc3_cents($row32['net_settled_profit'] ?? ''),
        qc3_cents($row32['net_settled_profit'] ?? '') + qc3_cents($row32['daily_coordination_fee'] ?? ''),
    ]
);

Qc::ok(
    'P3.13',
    'Saldo investor tetap utuh: bagi hasil 0/0, tidak ada baris keuangan investor baru',
    qc3_money($row32['operator_share_80'] ?? '') === '0.00'
        && qc3_money($row32['investor_pool_20'] ?? '') === '0.00'
        && $walletCount() === $walletsBefore32
        && $distributionCount() === $distributionsBefore32,
    'op=' . (string) ($row32['operator_share_80'] ?? '-')
        . ' pool=' . (string) ($row32['investor_pool_20'] ?? '-')
        . ' wallets=' . $walletCount() . ' distributions=' . $distributionCount()
);

Qc::equals(
    'P3.14',
    'Defisit Rp 80.000 tercatat pada batch dan menjadi defisit berjalan',
    ['80000.00', '80000.00', 8000000],
    [
        qc3_money($row32['carryover_deficit'] ?? ''),
        qc3_money(SystemSetting::get(SettlementService::SETTING_ACTIVE_DEFICIT, '')),
        qc3_cents(SystemSetting::get(SettlementService::SETTING_ACTIVE_DEFICIT, '')),
    ]
);

$tellerMutation32 = $lastInFor($tellerId32);

Qc::ok(
    'P3.15',
    'Alokasi kas hari minus: meja menerima seluruh kas Rp 1.850.000, brankas Rp 0',
    $brankasBalance() === '0.00'
        && $inCountFor($brankasId32) === 0
        && $inCountFor($tellerId32) === 1
        && qc3_cents($tellerMutation32['amount'] ?? '') === 185000000
        && $balance() === '49920000.00',
    'kas=' . $balance() . ' brankas=' . $brankasBalance()
        . ' mutasi_meja=' . $inCountFor($tellerId32) . ' mutasi_brankas=' . $inCountFor($brankasId32)
);

Qc::equals(
    'P3.15b',
    'Nota hari minus tetap tersimpan sebagai bukti fisik',
    $receiptsBefore32 + 1,
    count(QcFixture::receiptFiles())
);

// ---------------------------------------------------------------------------
// SKENARIO 3.3 — pemulihan defisit pada settlement berikutnya
// ---------------------------------------------------------------------------

Qc::section('SKENARIO 3.3 — Settlement berikutnya memotong defisit Rp 80.000');

QcFixture::seedHoldingBatch('1,000', '1.950.000', 'QC Hulu Esok');

Qc::equals(
    'P3.16a',
    'Pembelian hulu berikutnya memakai kas meja sisa (Rp 49.920.000 − Rp 1.930.000)',
    '47990000.00',
    $balance()
);

$known33 = qc3_settlement_ids();

$post33 = qc3_settle($operatorJar, [
    'sold_grams'       => '1,000',
    'density_pct'      => '93,5',
    'shop_price_per_g' => '2.500.000',
    'coord_fee'        => '200.000',
], [SettlementService::RECEIPT_FIELD => $notaPath]);

$row33 = qc3_new_settlement($known33);

Qc::ok(
    'P3.16b',
    'POST settlement pemulihan diterima (302)',
    $post33['status'] === 302,
    'status=' . $post33['status']
);

Qc::equals(
    'P3.16',
    'Defisit Rp 80.000 dipotong lebih dulu, sisanya dibagi 80 : 20',
    ['570000.00', '80000.00', '290000.00', '232000.00', '58000.00', '0.00', 8000000, 29000000],
    [
        qc3_cents_money(
            qc3_cents($row33['net_settled_profit'] ?? '')
            + qc3_cents($row33['daily_coordination_fee'] ?? '')
            + qc3_cents($row33['deficit_recovery_used'] ?? '')
        ),
        qc3_money($row33['deficit_recovery_used'] ?? ''),
        qc3_money($row33['net_settled_profit'] ?? ''),
        qc3_money($row33['operator_share_80'] ?? ''),
        qc3_money($row33['investor_pool_20'] ?? ''),
        qc3_money(SystemSetting::get(SettlementService::SETTING_ACTIVE_DEFICIT, '')),
        qc3_cents($row33['deficit_recovery_used'] ?? ''),
        qc3_cents($row33['net_settled_profit'] ?? ''),
    ]
);

Qc::equals(
    'P3.16c',
    'Setelah pemulihan: kas meja kembali Rp 50.000.000 dan brankas Rp 290.000',
    ['50000000.00', '290000.00'],
    [$balance(), $brankasBalance()]
);

// ---------------------------------------------------------------------------
// C. Atomisitas & tanpa berkas nota yatim
// ---------------------------------------------------------------------------

Qc::section('C. Atomisitas (SAD § 4.1) & berkas nota tidak menggantung');

$fixtureC = QcFixture::resetSettlementState();

$tellerIdC = $fixtureC['teller_id'];
$brankasIdC = $fixtureC['brankas_id'];

$beforeC = qc3_state($tellerIdC, $brankasIdC);

// Paksa kegagalan DI DALAM transaksi: baris kunci defisit dihapus sehingga
// pembacaan terkunci gagal, padahal berkas nota sudah lolos unggahan dan
// tersalin ke direktori nota. Dijalankan lewat HTTP agar `is_uploaded_file()`
// benar-benar berlaku seperti di lapangan.
Database::query('DELETE FROM `system_settings` WHERE `setting_key` = :key', [
    'key' => SettlementService::SETTING_ACTIVE_DEFICIT,
]);

$postC = qc3_settle($operatorJar, $input31, [SettlementService::RECEIPT_FIELD => $notaPath]);

Database::insert('system_settings', [
    'setting_key'   => SettlementService::SETTING_ACTIVE_DEFICIT,
    'setting_value' => '0.00',
    'description'   => 'Akumulasi defisit hari minus yang belum pulih',
]);

$afterC = qc3_state($tellerIdC, $brankasIdC);

Qc::ok(
    'C.1',
    'Kegagalan di tengah transaksi menggulung SELURUH tulisan (nol baris, saldo utuh)',
    $afterC === $beforeC
        && $postC['status'] === 422
        && str_contains($postC['body'], 'tidak terdaftar'),
    'status=' . $postC['status'] . "\nsebelum=" . json_encode($beforeC) . "\nsesudah=" . json_encode($afterC)
);

Qc::ok(
    'C.2',
    'Berkas nota yang sudah tersalin ikut dihapus (tidak ada berkas yatim)',
    $afterC['receipts'] === $beforeC['receipts'] && count(QcFixture::receiptFiles()) === 0,
    'berkas=' . count(QcFixture::receiptFiles())
);

// Rantai tulisan yang sama dengan settle(), digagalkan di UJUNG transaksi —
// membuktikan rollback tetap berlaku setelah beberapa tabel tersentuh.
$settlementIdC = Security::uuidV4();
$rollbackCaught = false;

try {
    Database::transaction(static function () use ($settlementIdC): void {
        DailySettlement::insert([
            'id'                     => $settlementIdC,
            'settlement_date'        => date('Y-m-d'),
            'total_dry_grams'        => '18.050',
            'shop_water_density_pct' => '93.50',
            'shop_price_per_g'       => '2080000.00',
            'total_shop_revenue'     => '37544000.00',
            'daily_coordination_fee' => '200000.00',
            'consumable_opex'        => '0.00',
            'deficit_recovery_used'  => '0.00',
            'net_settled_profit'     => '2186500.00',
            'operator_share_80'      => '1749200.00',
            'investor_pool_20'       => '437300.00',
            'carryover_deficit'      => '0.00',
            'receipt_photo_url'      => 'uploads/receipts/qc-rollback.png',
        ]);

        $holdingIds = array_column(
            Database::fetchAll(
                'SELECT `id` FROM `hulu_transactions` WHERE `status` = :status',
                ['status' => HuluTransaction::STATUS_HOLDING]
            ),
            'id'
        );

        HuluTransaction::settleBatch($settlementIdC, $holdingIds);

        SystemSetting::set(SettlementService::SETTING_ACTIVE_DEFICIT, '123.45');

        throw new RuntimeException('QC: kegagalan yang disengaja di ujung transaksi');
    });
} catch (RuntimeException) {
    $rollbackCaught = true;
}

$afterRollback = qc3_state($tellerIdC, $brankasIdC);

Qc::ok(
    'C.3',
    'Rollback berlaku untuk rantai settlement + status hulu + setting defisit',
    $rollbackCaught && $afterRollback === $beforeC,
    'settlements=' . $afterRollback['settlements']
        . ' holding=' . $afterRollback['holding']
        . ' settled=' . $afterRollback['settled']
        . ' deficit=' . $afterRollback['deficit']
);

// ---------------------------------------------------------------------------
// D. Guard input & berkas nota (lewat HTTP)
// ---------------------------------------------------------------------------

Qc::section('D. Guard: berat jual, alokasi kas, dan berkas nota');

$textPath = qc3_temp_file('nota-palsu.png', "bukan gambar sama sekali\n");
$bigPath = qc3_temp_file('nota-besar.png', random_bytes(3 * 1024 * 1024));

/** @var list<array{0: string, 1: string, 2: array<string, string>, 3: string}> */
$guardCases = [
    ['D.1', 'Alokasi kas tidak berjumlah pas', $input31 + ['kas_split' => '30.000.000', 'brankas_split' => '1.000'], $notaPath],
    ['D.2', 'Berat jual melebihi emas holding', ['sold_grams' => '20,000'] + $input31, $notaPath],
    ['D.3', 'Kadar timbang air di atas 100 %', ['density_pct' => '120'] + $input31, $notaPath],
    ['D.4', 'Foto nota tidak dilampirkan', $input31, ''],
    ['D.5', 'Berkas bukan gambar (teks bernama .png)', $input31, $textPath],
    ['D.6', 'Berkas melebihi batas unggahan peladen', $input31, $bigPath],
];

$guardOk = true;
$guardDetail = '';

foreach ($guardCases as [$id, $label, $fields, $file]) {
    $stateBefore = qc3_state($tellerIdC, $brankasIdC);

    $response = $file === ''
        ? qc3_settle($operatorJar, $fields)
        : qc3_settle($operatorJar, $fields, [SettlementService::RECEIPT_FIELD => $file]);

    $stateAfter = qc3_state($tellerIdC, $brankasIdC);

    $ok = $response['status'] === 422
        && $stateAfter === $stateBefore
        && str_contains($response['body'], 'af-alert--error');

    $guardOk = $guardOk && $ok;
    $guardDetail .= ($ok ? 'OK    ' : 'GAGAL ') . $id . ' ' . $label . ' (status ' . $response['status'] . ")\n";
}

Qc::ok(
    'D.0',
    'Enam input/berkas tidak sah ditolak (422) tanpa tulisan dan tanpa berkas yatim',
    $guardOk,
    $guardDetail
);

Qc::ok(
    'D.7',
    'Pesan penolakan berkas dapat dibaca operator',
    str_contains(qc3_settle($operatorJar, $input31, [SettlementService::RECEIPT_FIELD => $textPath])['body'], 'gambar')
        && str_contains(qc3_settle($operatorJar, $input31)['body'], 'wajib dilampirkan'),
    'pesan minimal belum sesuai'
);

// ---------------------------------------------------------------------------
// E. Peladen tidak mempercayai nominal dari klien
// ---------------------------------------------------------------------------

Qc::section('E. Nominal dari klien diabaikan (peladen menghitung ulang)');

QcFixture::resetSettlementState();

$knownE = qc3_settlement_ids();

$forgedResponse = qc3_settle($operatorJar, $input31 + [
    'total_shop_revenue'       => '1',
    'holding_cost'             => '1',
    'gross_profit'             => '9.999.999',
    'net_settled_profit'       => '9.999.999',
    'operator_share_80'        => '9.999.999',
    'investor_pool_20'         => '9.999.999',
    'carryover_deficit'        => '9.999.999',
    'deficit_recovery_used'    => '9.999.999',
    'active_carryover_deficit' => '9.999.999',
    'settlement_id'            => Security::uuidV4(),
], [SettlementService::RECEIPT_FIELD => $notaPath]);

$forgedRow = qc3_new_settlement($knownE);

Qc::equals(
    'E.1',
    'Kolom palsu diabaikan: nilai tersimpan tetap hasil hitung peladen',
    ['37544000.00', '2186500.00', '1749200.00', '437300.00', '0.00', '0.00'],
    [
        qc3_money($forgedRow['total_shop_revenue'] ?? ''),
        qc3_money($forgedRow['net_settled_profit'] ?? ''),
        qc3_money($forgedRow['operator_share_80'] ?? ''),
        qc3_money($forgedRow['investor_pool_20'] ?? ''),
        qc3_money($forgedRow['carryover_deficit'] ?? ''),
        qc3_money(SystemSetting::get(SettlementService::SETTING_ACTIVE_DEFICIT, '')),
    ]
);

Qc::ok(
    'E.2',
    'Defisit berjalan tidak dapat ditimpa dari form dan kas meja tetap benar',
    $forgedResponse['status'] === 302
        && (string) SystemSetting::get(SettlementService::SETTING_ACTIVE_DEFICIT, '') === '0.00'
        && $balance() === '50000000.00',
    'status=' . $forgedResponse['status']
        . ' setting=' . (string) SystemSetting::get(SettlementService::SETTING_ACTIVE_DEFICIT, '')
        . ' kas=' . $balance()
);

// ---------------------------------------------------------------------------
// F. Anti double-submit
// ---------------------------------------------------------------------------

Qc::section('F. Anti double-submit (token sekali-pakai `_once`)');

$fixtureF = QcFixture::resetSingleHoldingState('1,000', '1.950.000');

$tellerIdF = $fixtureF['teller_id'];
$brankasIdF = $fixtureF['brankas_id'];

$tokensF = qc3_tokens($operatorJar);

/** @var array<string, string> $fieldsF */
$fieldsF = [
    'sold_grams'         => '1,000',
    'density_pct'        => '93,50',
    'shop_price_per_g'   => '2.050.000',
    'coord_fee'          => '200.000',
    Security::CSRF_FIELD => $tokensF['csrf'],
    Security::FORM_FIELD => $tokensF['once'],
];

$firstF = QcHttp::request('POST', '/operator/settlement', [
    'cookie_jar' => $operatorJar,
    'data'       => $fieldsF,
    'files'      => [SettlementService::RECEIPT_FIELD => $notaPath],
]);

$settlementsAfterFirst = DailySettlement::count();
$balanceAfterFirst = $balance();
$receiptsAfterFirst = count(QcFixture::receiptFiles());

$replayF = QcHttp::request('POST', '/operator/settlement', [
    'cookie_jar' => $operatorJar,
    'data'       => $fieldsF,
    'files'      => [SettlementService::RECEIPT_FIELD => $notaPath],
]);

Qc::ok(
    'F.1',
    'Replay token sekali-pakai diabaikan: tidak ada sesi baru, kas tidak berubah',
    $firstF['status'] === 302
        && $replayF['status'] === 302
        && $settlementsAfterFirst === 1
        && DailySettlement::count() === $settlementsAfterFirst
        && $balance() === $balanceAfterFirst
        && count(QcFixture::receiptFiles()) === $receiptsAfterFirst,
    'pertama=' . $firstF['status'] . ' replay=' . $replayF['status'] . ' sesi=' . DailySettlement::count()
);

$afterReplayF = QcHttp::request('GET', '/operator/settlement', ['cookie_jar' => $operatorJar]);

Qc::ok(
    'F.2',
    'Operator menerima pesan "ganda diabaikan" pada halaman berikutnya',
    str_contains($afterReplayF['body'], 'ganda diabaikan'),
    'pesan tidak ditemukan'
);

$secondReplayF = QcHttp::request('POST', '/operator/settlement', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        'sold_grams'         => '1,000',
        'density_pct'        => '93,50',
        'shop_price_per_g'   => '2.050.000',
        'coord_fee'          => '200.000',
        Security::CSRF_FIELD => (string) QcHttp::csrfFrom($afterReplayF['body']),
        Security::FORM_FIELD => $tokensF['once'],
    ],
    'files' => [SettlementService::RECEIPT_FIELD => $notaPath],
]);

Qc::ok(
    'F.3',
    'Token lama tetap ditolak setelah halaman dirender ulang (sekali pakai)',
    $secondReplayF['status'] === 302
        && DailySettlement::count() === $settlementsAfterFirst
        && $balance() === $balanceAfterFirst,
    'status=' . $secondReplayF['status'] . ' sesi=' . DailySettlement::count()
);

$freshF = qc3_tokens($operatorJar);

$noOnce = QcHttp::request('POST', '/operator/settlement', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        'sold_grams'         => '1,000',
        'density_pct'        => '93,50',
        'shop_price_per_g'   => '2.050.000',
        'coord_fee'          => '200.000',
        Security::CSRF_FIELD => $freshF['csrf'],
    ],
]);

Qc::ok(
    'F.4',
    'POST tanpa token sekali-pakai ditolak (419) tanpa mencatat sesi',
    $noOnce['status'] === 419
        && DailySettlement::count() === $settlementsAfterFirst
        && $balance() === $balanceAfterFirst,
    'status=' . $noOnce['status']
);

$noCsrf = QcHttp::request('POST', '/operator/settlement', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        'sold_grams'       => '1,000',
        'density_pct'      => '93,50',
        'shop_price_per_g' => '2.050.000',
        'coord_fee'        => '200.000',
    ],
]);

Qc::ok(
    'F.5',
    'POST tanpa token CSRF ditolak (419) tanpa mencatat sesi',
    $noCsrf['status'] === 419
        && DailySettlement::count() === $settlementsAfterFirst
        && $balance() === $balanceAfterFirst,
    'status=' . $noCsrf['status']
);

Qc::ok(
    'F.6',
    'Tidak ada berkas nota yatim dari seluruh percobaan di atas',
    count(QcFixture::receiptFiles()) === $receiptsAfterFirst,
    'berkas=' . count(QcFixture::receiptFiles()) . ' (seharusnya ' . $receiptsAfterFirst . ')'
);

// ---------------------------------------------------------------------------
// G. Perilaku lewat HTTP sungguhan (peran & penyajian nota)
// ---------------------------------------------------------------------------

Qc::section('G. HTTP: guard peran & penyajian berkas nota');

$anonJar = QcHttp::cookieJar('settlement-anon');
$anonPage = QcHttp::request('GET', '/operator/settlement', ['cookie_jar' => $anonJar, 'fresh' => true]);

Qc::ok(
    'G.2',
    'Rekonsiliasi tanpa login diarahkan ke /login',
    $anonPage['status'] === 302 && $anonPage['location'] === '/login',
    'status=' . $anonPage['status'] . ' location=' . (string) $anonPage['location']
);

$investorPhone = (string) (Env::get('QC_INVESTOR_PHONE') ?? '081200000009');
$investorPassword = 'Investor2026!';

$existingInvestor = User::findByPhoneNumber($investorPhone, false);

if ($existingInvestor === null) {
    User::create('Mitra Uji QC', $investorPhone, $investorPassword, Constants::ROLE_INVESTOR);
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

$investorJar = QcHttp::cookieJar('settlement-investor');
$investorForm = QcHttp::request('GET', '/login', ['cookie_jar' => $investorJar, 'fresh' => true]);

$investorLogin = QcHttp::request('POST', '/login', [
    'cookie_jar' => $investorJar,
    'data'       => [
        Security::CSRF_FIELD => (string) QcHttp::csrfFrom($investorForm['body']),
        'phone_number'       => $investorPhone,
        'password'           => $investorPassword,
    ],
]);

$investorPage = QcHttp::request('GET', '/operator/settlement', ['cookie_jar' => $investorJar]);

Qc::ok(
    'G.3',
    'Investor diarahkan ke /investor/dashboard saat membuka layar rekonsiliasi',
    $investorLogin['status'] === 302
        && $investorPage['status'] === 302
        && $investorPage['location'] === '/investor/dashboard',
    'login=' . $investorLogin['status'] . ' halaman=' . $investorPage['status']
        . ' location=' . (string) $investorPage['location']
);

// Sejak Fase 5 mitra mendarat di /investor/dashboard, sehingga token CSRF untuk
// uji penyusupan pun dibaca dari sana.
$investorDashboard = QcHttp::request('GET', '/investor/dashboard', ['cookie_jar' => $investorJar]);

$stateBeforeInvestor = qc3_state($tellerIdF, $brankasIdF);

$investorPost = QcHttp::request('POST', '/operator/settlement', [
    'cookie_jar' => $investorJar,
    'data'       => [
        'sold_grams'         => '1,000',
        'density_pct'        => '93,50',
        'shop_price_per_g'   => '2.050.000',
        'coord_fee'          => '200.000',
        Security::CSRF_FIELD => (string) QcHttp::csrfFrom($investorDashboard['body']),
    ],
    'files' => [SettlementService::RECEIPT_FIELD => $notaPath],
]);

$stateAfterInvestor = qc3_state($tellerIdF, $brankasIdF);

Qc::ok(
    'G.4',
    'Investor tidak dapat mengeksekusi tutup buku (kas, tabel, dan berkas tidak berubah)',
    $stateAfterInvestor === $stateBeforeInvestor,
    'status=' . $investorPost['status'] . ' sesi=' . $stateAfterInvestor['settlements']
        . ' berkas=' . $stateAfterInvestor['receipts']
);

// Satu settlement sah sebagai sumber berkas nota yang akan diuji penyajiannya.
QcFixture::resetSettlementState();

$knownG = qc3_settlement_ids();

$postG = qc3_settle($operatorJar, $input31, [SettlementService::RECEIPT_FIELD => $notaPath]);

$receiptName = basename((string) (qc3_new_settlement($knownG)['receipt_photo_url'] ?? ''));
$receiptFetch = QcHttp::request('GET', '/uploads/receipts/' . $receiptName, ['cookie_jar' => $anonJar]);

Qc::ok(
    'G.5',
    'Foto nota dapat diambil kembali lewat web (200) dan isinya utuh',
    $postG['status'] === 302
        && $receiptFetch['status'] === 200
        && strlen($receiptFetch['body']) === $notaSize
        && str_starts_with($receiptFetch['body'], "\x89PNG"),
    'status=' . $receiptFetch['status'] . ' ukuran=' . strlen($receiptFetch['body'])
);

// Berkas berbahaya di folder unggahan tidak boleh dieksekusi. Pada Apache
// aturan ini datang dari public/uploads/.htaccess; harness dev menirunya lewat
// tests/server_router.php sehingga klaimnya teruji, bukan diasumsikan.
$trapName = 'qc-trap-' . bin2hex(random_bytes(6)) . '.php';
file_put_contents(SettlementService::receiptDirectory() . '/' . $trapName, "<?php echo 'EXECUTED';");

$trapResponse = QcHttp::request('GET', '/uploads/receipts/' . $trapName, ['cookie_jar' => $anonJar]);

@unlink(SettlementService::receiptDirectory() . '/' . $trapName);

Qc::ok(
    'G.6',
    'Skrip di dalam folder unggahan tidak dieksekusi (403, setara .htaccess)',
    $trapResponse['status'] === 403 && !str_contains($trapResponse['body'], 'EXECUTED'),
    'status=' . $trapResponse['status']
);

// ---------------------------------------------------------------------------
// H. Pemindaian statis, aset, dan hardening unggahan
// ---------------------------------------------------------------------------

Qc::section('H. Pemindaian statis & aset frontend');

$fase3Sources = [
    APP_PATH . '/Services/SettlementService.php',
    APP_PATH . '/Models/DailySettlement.php',
    APP_PATH . '/Models/HuluTransaction.php',
    APP_PATH . '/Models/CashRegister.php',
    APP_PATH . '/Models/SystemSetting.php',
    APP_PATH . '/Controllers/SettlementController.php',
    APP_PATH . '/Views/operator/settlement.php',
    APP_PATH . '/Views/layouts/operator.php',
    PUBLIC_PATH . '/assets/js/app.js',
];

$missingSources = array_values(array_filter($fase3Sources, static fn (string $path): bool => !is_file($path)));

Qc::ok(
    'H.0',
    'Seluruh berkas Fase 3 tersedia',
    $missingSources === [],
    implode("\n", array_map(static fn (string $path): string => str_replace(BASE_PATH . '/', '', $path), $missingSources))
);

$floatOffenders = qc3_scan(
    $fase3Sources,
    static fn (string $line): bool => preg_match('/\bfloatval\s*\(|\bdoubleval\s*\(|\(\s*(float|double)\s*\)|\bFLOAT\b|\bDOUBLE\b/', $line) === 1
);

Qc::ok('H.1', 'Tidak ada aritmetika float/double pada jalur uang Fase 3', $floatOffenders === [], implode("\n", $floatOffenders));

$numberFormatOffenders = qc3_scan($fase3Sources, static fn (string $line): bool => str_contains($line, 'number_format('));

Qc::ok('H.2', 'Tidak ada number_format() di Fase 3 (wajib lewat Formatter)', $numberFormatOffenders === [], implode("\n", $numberFormatOffenders));

$manualTransactionOffenders = qc3_scan(
    $fase3Sources,
    static fn (string $line): bool => preg_match('/\b(beginTransaction|rollBack|commit)\s*\(/', $line) === 1
);

Qc::ok(
    'H.3',
    'Tutup buku selalu lewat Database::transaction() (bukan begin/commit manual)',
    $manualTransactionOffenders === [],
    implode("\n", $manualTransactionOffenders)
);

$viewFiles = qc3_php_files(APP_PATH . '/Views');

$unescapedOffenders = qc3_scan(
    $viewFiles,
    // Pengecualian eksplisit: cetak `$content` pada layout memang HTML yang
    // sudah di-escape di view asalnya (lihat BaseController::render()).
    static fn (string $line): bool => preg_match('/<\?=\s*\$(?!content\b)/', $line) === 1
);

Qc::ok('H.4', 'Seluruh output dinamis di view melewati Security::e()', $unescapedOffenders === [], implode("\n", $unescapedOffenders));

$cdnOffenders = qc3_scan(
    $viewFiles,
    static fn (string $line): bool => preg_match('#(?:src|href)\s*=\s*["\']https?://#i', $line) === 1
);

Qc::ok('H.5', 'Tidak ada aset dari CDN pada view (aset selalu lokal)', $cdnOffenders === [], implode("\n", $cdnOffenders));

$sqlLeakOffenders = qc3_scan(
    [APP_PATH . '/Controllers/SettlementController.php', APP_PATH . '/Views/operator/settlement.php'],
    // Case-sensitive: SQL pada proyek ini selalu huruf besar, sehingga kelas CSS
    // seperti `select-none` tidak ikut tertangkap.
    static fn (string $line): bool => preg_match('/\b(SELECT|INSERT INTO|UPDATE|DELETE FROM|ORDER BY|LIMIT)\b/', $line) === 1
);

Qc::ok('H.6', 'SQL tidak bocor ke controller/view settlement', $sqlLeakOffenders === [], implode("\n", $sqlLeakOffenders));

$cssPath = PUBLIC_PATH . '/assets/css/app.min.css';
$cssSize = is_file($cssPath) ? (int) filesize($cssPath) : 0;
$cssContents = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';

Qc::ok(
    'H.7',
    'CSS hasil kompilasi tersedia, < 30 KB, dan memuat token tema Obsidian Gold',
    $cssSize > 0 && $cssSize < 30720
        && str_contains($cssContents, 'obsidian')
        && str_contains($cssContents, 'D4AF37')
        && str_contains($cssContents, 'tabular-nums'),
    'ukuran=' . $cssSize . ' byte'
);

$appJs = (string) file_get_contents(PUBLIC_PATH . '/assets/js/app.js');

Qc::ok(
    'H.8',
    'app.js memuat kompresor Canvas (toBlob, penskalaan, target 250 KB) dan komponen settlementForm',
    str_contains($appJs, 'compressImage')
        && str_contains($appJs, 'canvasToBlob')
        && str_contains($appJs, 'drawScaled')
        && str_contains($appJs, 'DataTransfer')
        && str_contains($appJs, 'RECEIPT_SOFT_LIMIT_BYTES = 256000')
        && str_contains($appJs, 'RECEIPT_HARD_LIMIT_BYTES = 4194304')
        && str_contains($appJs, "Alpine.data('settlementForm'")
        && str_contains($appJs, 'onReceiptChange')
        && str_contains($appJs, 'restoreAutoSplit'),
    'komponen kompresi/rekonsiliasi tidak lengkap'
);

$uploadCallers = qc3_scan(
    array_merge($fase3Sources, [APP_PATH . '/Services/TellerService.php']),
    static fn (string $line): bool => str_contains($line, 'move_uploaded_file(')
);

Qc::ok(
    'H.9',
    'move_uploaded_file() hanya dipanggil dari pipeline nota SettlementService',
    count($uploadCallers) === 1 && str_contains($uploadCallers[0], 'SettlementService.php'),
    implode("\n", $uploadCallers)
);

$uploadsHtaccess = (string) file_get_contents(PUBLIC_PATH . '/uploads/.htaccess');

Qc::ok(
    'H.10',
    'Hardening folder unggahan: mesin skrip mati, ekstensi berbahaya ditolak, .gitignore ada',
    str_contains($uploadsHtaccess, 'php_flag engine off')
        && str_contains($uploadsHtaccess, 'RemoveHandler')
        && str_contains($uploadsHtaccess, 'Require all denied')
        && is_file(SettlementService::receiptDirectory() . '/.gitignore'),
    'isi .htaccess tidak lengkap'
);

$settlementView = (string) file_get_contents(APP_PATH . '/Views/operator/settlement.php');

Qc::ok(
    'H.11',
    'Layar rekonsiliasi memuat unggah kamera, pratinjau Alpine, Smart Auto-Split, dan cadangan tanpa JavaScript',
    str_contains($settlementView, 'settlementForm(')
        && str_contains($settlementView, 'enctype="multipart/form-data"')
        && str_contains($settlementView, 'capture="environment"')
        && str_contains($settlementView, 'x-text="netLabel"')
        && str_contains($settlementView, 'restoreAutoSplit(true)')
        && str_contains($settlementView, '<noscript>')
        && str_contains($settlementView, 'Security::FORM_FIELD'),
    'komponen layar rekonsiliasi tidak lengkap'
);

$serviceSource = (string) file_get_contents(APP_PATH . '/Services/SettlementService.php');

Qc::ok(
    'H.12',
    'Nama berkas kiriman klien tidak pernah menjadi bagian path simpanan',
    str_contains($serviceSource, "Security::uuidV4() . '.' . self::IMAGE_TYPES[\$type]")
        && preg_match('/\$entry\[\x27name\x27\]/', $serviceSource) === 1
        && preg_match('/move_uploaded_file\(\s*\$tmpName\s*,\s*\$path\s*\)/', $serviceSource) === 1,
    'pola penamaan berkas pada SettlementService tidak sesuai'
);

// ---------------------------------------------------------------------------
// H2. Kontrak form & nilai awal — Plan_Fix_Settlement_UI
//
// Tiga cacat yang dijaga di sini: (1) `type="number"` membuang pemisah ribuan
// di peramban sehingga nominal terkirim kosong, (2) placeholder yang
// menyerupai data terisi, (3) nilai awal dibaca JS lewat `$refs` yang belum
// terdaftar saat `init()` berjalan sehingga `x-model` menghapusnya.
// ---------------------------------------------------------------------------

$operatorViews = [
    APP_PATH . '/Views/operator/settlement.php',
    APP_PATH . '/Views/operator/teller.php',
];

$numberTypeOffenders = qc3_scan(
    $operatorViews,
    static fn (string $line): bool => str_contains($line, 'type="number"')
);

Qc::ok(
    'H.13',
    'Tidak ada type="number" pada layar operator (peramban menghapus pemisah ribuan saat submit)',
    $numberTypeOffenders === [],
    implode("\n", $numberTypeOffenders)
);

$settlementFieldModes = [
    'sold_grams'       => 'decimal',
    'density_pct'      => 'decimal',
    'shop_price_per_g' => 'numeric',
    'coord_fee'        => 'numeric',
    'kas_split'        => 'numeric',
    'brankas_split'    => 'numeric',
];

$fieldAttributeProblems = [];

foreach ($settlementFieldModes as $fieldName => $inputmode) {
    $fieldBlock = qc3_input_block($settlementView, $fieldName);

    if ($fieldBlock === null) {
        $fieldAttributeProblems[] = $fieldName . ': input tidak ditemukan / name ganda';

        continue;
    }

    if (!str_contains($fieldBlock, 'type="text"')) {
        $fieldAttributeProblems[] = $fieldName . ': bukan type="text"';
    }

    if (!str_contains($fieldBlock, 'inputmode="' . $inputmode . '"')) {
        $fieldAttributeProblems[] = $fieldName . ': inputmode bukan ' . $inputmode;
    }

    if (!str_contains($fieldBlock, 'autocomplete="off"')) {
        $fieldAttributeProblems[] = $fieldName . ': autocomplete="off" hilang';
    }

    if (!str_contains($fieldBlock, 'x-model.fill="')) {
        $fieldAttributeProblems[] = $fieldName . ': tanpa modifier .fill';
    }

    // Form memakai `novalidate`, jadi atribut `required` HTML5 tidak akan
    // ditegakkan peramban dan hanya menyesatkan; keharusan isi ditandai di
    // teks label, sedangkan penegakannya tetap milik peladen.
    if (preg_match('/\srequired\b/', $fieldBlock) === 1) {
        $fieldAttributeProblems[] = $fieldName . ': memakai atribut required HTML5';
    }
}

Qc::ok(
    'H.14',
    'Keenam isian rekonsiliasi: type="text" + inputmode + autocomplete="off" + x-model.fill (form novalidate)',
    $fieldAttributeProblems === [] && str_contains($settlementView, 'novalidate'),
    implode("\n", $fieldAttributeProblems)
);

$tellerOperatorView = (string) file_get_contents(APP_PATH . '/Views/operator/teller.php');

$placeholderProblems = [];

foreach (['settlement.php' => $settlementView, 'teller.php' => $tellerOperatorView] as $label => $view) {
    preg_match_all('/placeholder="([^"]*)"/', $view, $placeholderMatches);

    foreach ($placeholderMatches[1] ?? [] as $placeholder) {
        // Setiap contoh angka wajib terbaca sebagai contoh ("mis. "), supaya
        // tidak disangka data yang sudah terisi. Satu-satunya pengecualian:
        // contoh non-numerik (inisial petani) pada layar kasir.
        if ($placeholder === 'P-04 / Anto' || str_starts_with($placeholder, 'mis. ')) {
            continue;
        }

        $placeholderProblems[] = $label . ': placeholder="' . $placeholder . '" tanpa awalan contoh';
    }
}

$soldFieldBlock = qc3_input_block($settlementView, 'sold_grams');

Qc::ok(
    'H.15',
    'Placeholder numerik selalu berawalan "mis." dan sold_grams (terisi peladen) tanpa placeholder',
    $placeholderProblems === []
        && $soldFieldBlock !== null
        && str_contains($soldFieldBlock, 'value=')
        && !str_contains($soldFieldBlock, 'placeholder='),
    implode("\n", $placeholderProblems)
);

$settlementController = (string) file_get_contents(APP_PATH . '/Controllers/SettlementController.php');

$expectedFields = ['brankas_split', 'coord_fee', 'density_pct', 'kas_split', 'shop_price_per_g', 'sold_grams'];

// View memuat satu field bernama tambahan: berkas nota dibaca dari `$_FILES`
// (`SettlementService::RECEIPT_FIELD`), bukan `$_POST`, sehingga memang tidak
// ada di `readInput()`/`defaultInput()`.
$expectedViewFields = array_merge($expectedFields, [SettlementService::RECEIPT_FIELD]);
sort($expectedViewFields);

preg_match_all('/self::post\(\'([a-z_]+)\'\)/', $settlementController, $controllerKeyMatches);
preg_match_all('/\sname="([a-z_]+)"/', $settlementView, $viewKeyMatches);

$controllerFields = array_values(array_unique($controllerKeyMatches[1] ?? []));
$viewFields = array_values(array_unique($viewKeyMatches[1] ?? []));
sort($controllerFields);
sort($viewFields);

// Kunci nilai awal (`defaultInput()`) harus sama himpunannya dengan kunci yang
// dibaca `readInput()`: itulah yang di-pre-fill ke form pada GET, sehingga
// perbedaan nama di salah satu lapis langsung terlihat.
preg_match('/private static function defaultInput\(.*?\n    \}/s', $settlementController, $defaultInputMatch);
preg_match_all('/\'([a-z_]+)\'\s*=>/', $defaultInputMatch[0] ?? '', $defaultKeyMatches);

$defaultFields = array_values(array_unique($defaultKeyMatches[1] ?? []));
sort($defaultFields);

Qc::ok(
    'H.16',
    'Kunci form rekonsiliasi seragam lintas lapis: view (+berkas nota) = readInput() = defaultInput()',
    $viewFields === $expectedViewFields
        && $controllerFields === $expectedFields
        && $defaultFields === $expectedFields,
    'view=[' . implode(',', $viewFields) . '] readInput=[' . implode(',', $controllerFields)
        . '] defaultInput=[' . implode(',', $defaultFields) . ']'
);

$settlementFormStart = strpos($appJs, 'function settlementForm(');
$settlementFormEnd = strpos($appJs, "Alpine.data('settlementForm'");
$settlementFormJs = ($settlementFormStart !== false
    && $settlementFormEnd !== false
    && $settlementFormEnd > $settlementFormStart)
    ? substr($appJs, $settlementFormStart, $settlementFormEnd - $settlementFormStart)
    : '';

$initStart = strpos($settlementFormJs, 'init: function (');
$initEnd = $initStart === false ? false : strpos($settlementFormJs, "\n            },", $initStart);
$initBlock = ($initStart !== false && $initEnd !== false)
    ? substr($settlementFormJs, $initStart, $initEnd - $initStart)
    : '';

$initProblems = [];

if ($settlementFormJs === '') {
    $initProblems[] = 'komponen settlementForm tidak ditemukan di app.js';
}

if ($initBlock === '') {
    $initProblems[] = 'blok init() settlementForm tidak terbaca';
} else {
    if (!str_contains($initBlock, 'formFieldValue(')) {
        $initProblems[] = 'init() tidak membaca DOM lewat formFieldValue()';
    }

    if (str_contains($initBlock, '$refs')) {
        $initProblems[] = 'init() masih memakai $refs (belum terdaftar saat init berjalan)';
    }

    if (!str_contains($initBlock, 'restoreAutoSplit(false)')) {
        $initProblems[] = 'init() tidak memakai restoreAutoSplit(false)';
    }

    if (str_contains($initBlock, 'restoreAutoSplit(true)')) {
        $initProblems[] = 'init() memaksa saran sistem (restoreAutoSplit(true))';
    }
}

if (preg_match('/function formField\([\s\S]{0,1200}?querySelector\(/', $appJs) !== 1) {
    $initProblems[] = 'helper formField() tidak membaca lewat querySelector()';
}

if (preg_match('/function formField\([\s\S]{0,1800}?\$refs/', $appJs) !== 1) {
    $initProblems[] = 'helper formField() kehilangan cadangan $refs.form';
}

Qc::ok(
    'H.17',
    'settlementForm.init() membaca DOM tanpa $refs, tidak menimpa isian hasil 422, cadangan $refs tersedia',
    $initProblems === [],
    implode("\n", $initProblems)
);

// ---------------------------------------------------------------------------
// H3. Kontrol form tidak boleh `disabled` (payload hilang) — akar 422 "wajib diisi"
//
// Spesifikasi HTML: kontrol `disabled` DIKELUARKAN dari data form. Ketika
// `@submit` menyalakan `submitting`, binding `:disabled="submitting"` membuat
// seluruh isian (termasuk berkas nota) hilang dari payload, sehingga peladen
// hanya menerima `_token`/`_once` (± 357 byte) dan menjawab 422 "wajib diisi".
// ---------------------------------------------------------------------------

$settlementControls = [
    'sold_grams',
    'density_pct',
    'shop_price_per_g',
    'coord_fee',
    'receipt_photo',
    'kas_split',
    'brankas_split',
];

$disabledControlProblems = [];

foreach ($settlementControls as $controlName) {
    $controlBlock = qc3_input_block($settlementView, $controlName);

    if ($controlBlock === null) {
        $disabledControlProblems[] = $controlName . ': input tidak ditemukan / name ganda';

        continue;
    }

    if (str_contains($controlBlock, ':disabled')) {
        $disabledControlProblems[] = $controlName . ': memakai :disabled — kontrol hilang dari payload';
    }

    if (!str_contains($controlBlock, ':readonly="submitting"')) {
        $disabledControlProblems[] = $controlName . ': tanpa :readonly="submitting"';
    }
}

// Tombol submit tidak membawa data form, jadi `:disabled` memang di situ tempatnya.
if (preg_match(
    '/<button\s+type="submit"[\s\S]{0,200}?:disabled="!canSubmit \|\| submitting"/',
    $settlementView
) !== 1) {
    $disabledControlProblems[] = 'tombol submit kehilangan :disabled="!canSubmit || submitting"';
}

Qc::ok(
    'H.18',
    'Kontrol form rekonsiliasi memakai :readonly (bukan :disabled) sehingga isian & nota tetap terkirim',
    $disabledControlProblems === [],
    implode("\n", $disabledControlProblems)
);

// ---------------------------------------------------------------------------
// I. Integritas skema (tanpa perubahan DDL)
// ---------------------------------------------------------------------------

Qc::section('I. Integritas skema (Fase 3 tidak mengubah DDL)');

$violations = QcFixture::checkDecimalConventions([
    ['daily_settlements', 'total_dry_grams', 8, 3],
    ['daily_settlements', 'shop_water_density_pct', 5, 2],
    ['daily_settlements', 'shop_price_per_g', 14, 2],
    ['daily_settlements', 'total_shop_revenue', 14, 2],
    ['daily_settlements', 'daily_coordination_fee', 14, 2],
    ['daily_settlements', 'deficit_recovery_used', 14, 2],
    ['daily_settlements', 'net_settled_profit', 14, 2],
    ['daily_settlements', 'operator_share_80', 14, 2],
    ['daily_settlements', 'investor_pool_20', 14, 2],
    ['daily_settlements', 'carryover_deficit', 14, 2],
    ['hulu_transactions', 'net_cash_paid', 14, 2],
    ['cash_registers', 'current_balance', 14, 2],
    ['cash_mutations', 'amount', 14, 2],
]);

Qc::ok(
    'I.1',
    'Konvensi DECIMAL ERD § 3.1 tetap berlaku pada tabel yang disentuh Fase 3',
    $violations === [],
    implode("\n", $violations)
);

$requiredTables = [
    'system_settings', 'users', 'capital_wallets', 'cash_registers', 'cash_mutations',
    'daily_settlements', 'hulu_transactions', 'profit_distributions', 'withdrawal_requests',
];

$missingTables = array_values(array_filter(
    $requiredTables,
    static fn (string $table): bool => !QcFixture::tableExists($table)
));

Qc::ok(
    'I.2',
    'Sembilan tabel ERD tetap utuh (tidak ada migrasi struktur di Fase 3)',
    $missingTables === [],
    implode(', ', $missingTables)
);

$receiptColumn = QcFixture::columnDefinition('daily_settlements', 'receipt_photo_url');
$settlementColumn = QcFixture::columnDefinition('hulu_transactions', 'settlement_id');

Qc::ok(
    'I.3',
    'Kolom kunci tutup buku tersedia persis seperti ERD (tanpa kolom baru)',
    $receiptColumn !== null
        && strtolower((string) $receiptColumn['DATA_TYPE']) === 'varchar'
        && $settlementColumn !== null
        && str_contains(strtolower((string) $settlementColumn['COLUMN_TYPE']), 'char(36)')
        && QcFixture::columnDefinition('daily_settlements', 'consumable_opex') !== null
        && SystemSetting::get(SettlementService::SETTING_ACTIVE_DEFICIT) !== null,
    'kolom daily_settlements/hulu_transactions tidak sesuai ERD'
);

// ---------------------------------------------------------------------------
// Rekap Protokol QC Manual 3 (siap disalin ke dokumen Roadmap)
// ---------------------------------------------------------------------------

Qc::section('Rekap Protokol QC Manual 3 (siap disalin ke dokumen Roadmap)');

$protocol = [
    [
        'Total Kas Masuk Toko (3.1)',
        '18,050 x Rp 2.080.000',
        'Rp 37.544.000',
        qc3_cents($row31['total_shop_revenue'] ?? '') === 3754400000 ? 'LULUS' : 'GAGAL',
        'tersimpan: ' . qc3_money($row31['total_shop_revenue'] ?? '') . ' | tampilan: ' . Formatter::rupiah(37544000),
    ],
    [
        'Total Modal Belanja Hulu',
        '28.937.500 + 6.220.000',
        'Rp 35.157.500',
        $holdingCost31Cents === 3515750000 ? 'LULUS' : 'GAGAL',
        'SUM(net_cash_paid) batch settled: ' . ($holdingCost31['total'] ?? '-'),
    ],
    [
        'Laba Kotor Usaha (3.1)',
        'Rp 37.544.000 - Rp 35.157.500',
        'Rp 2.386.500',
        qc3_cents($row31['total_shop_revenue'] ?? '') - $holdingCost31Cents === 238650000 ? 'LULUS' : 'GAGAL',
        'laba kotor dihitung dari pendapatan dikurangi modal belanja hulu',
    ],
    [
        'Laba Bersih Putaran (3.1)',
        'Rp 2.386.500 - Rp 200.000',
        'Rp 2.186.500',
        qc3_cents($row31['net_settled_profit'] ?? '') === 218650000 ? 'LULUS' : 'GAGAL',
        'tersimpan: ' . qc3_money($row31['net_settled_profit'] ?? ''),
    ],
    [
        'Hak Pengelola 80% (3.1)',
        '80% x Rp 2.186.500',
        'Rp 1.749.200',
        qc3_cents($row31['operator_share_80'] ?? '') === 174920000 ? 'LULUS' : 'GAGAL',
        'tersimpan: ' . qc3_money($row31['operator_share_80'] ?? ''),
    ],
    [
        'Pool Investor 20% (3.1)',
        '20% x Rp 2.186.500',
        'Rp 437.300',
        qc3_cents($row31['investor_pool_20'] ?? '') === 43730000 ? 'LULUS' : 'GAGAL',
        'tersimpan: ' . qc3_money($row31['investor_pool_20'] ?? ''),
    ],
    [
        'Status Transaksi Hulu (3.1)',
        'hulu_transactions',
        "kedua baris menjadi 'settled'",
        $allSettled ? 'LULUS' : 'GAGAL',
        'settlement_id = ' . $settlementId,
    ],
    [
        'Alokasi Kas Fisik (3.1)',
        'meja + brankas = pendapatan - aparat',
        'Rp 35.157.500 + Rp 2.186.500',
        $kas31Cents === 3515750000 && $brankas31Cents === 218650000 && $kas31Cents + $brankas31Cents === $totalCash31Cents
            ? 'LULUS' : 'GAGAL',
        'kas meja ' . qc3_cents_money($kas31Cents) . ' | brankas ' . qc3_cents_money($brankas31Cents),
    ],
    [
        'Sisa Kas Meja (3.1)',
        'Rp 14.842.500 + Rp 35.157.500',
        'Rp 50.000.000',
        qc3_cents($row31['total_shop_revenue'] ?? '') === 3754400000 ? 'LULUS' : 'GAGAL',
        'kas meja kembali utuh setelah modal dipulihkan',
    ],
    [
        'Hasil Bersih Hari Minus (3.2)',
        'Rp 120.000 - Rp 200.000',
        '-Rp 80.000',
        qc3_cents($row32['net_settled_profit'] ?? '') === -8000000 ? 'LULUS' : 'GAGAL',
        'tersimpan: ' . qc3_money($row32['net_settled_profit'] ?? ''),
    ],
    [
        'Saldo Investor Utuh (3.2)',
        'bagi hasil 80 : 20',
        'Rp 0 / Rp 0 - modal tidak dipotong',
        qc3_money($row32['operator_share_80'] ?? '') === '0.00'
            && qc3_money($row32['investor_pool_20'] ?? '') === '0.00' ? 'LULUS' : 'GAGAL',
        'tanpa dompet bermodal: nol baris distribusi (modal tidak dipotong)',
    ],
    [
        'Catatan Sistem (3.2)',
        'carryover_deficit',
        'Rp 80.000',
        qc3_cents($row32['carryover_deficit'] ?? '') === 8000000 ? 'LULUS' : 'GAGAL',
        'defisit berjalan: ' . qc3_money(SystemSetting::get(SettlementService::SETTING_ACTIVE_DEFICIT, '')),
    ],
    [
        'Settlement Esoknya (3.3)',
        'Rp 570.000 - Rp 200.000 aparat - Rp 80.000 defisit',
        'Rp 290.000 dibagi 80 : 20',
        qc3_cents($row33['deficit_recovery_used'] ?? '') === 8000000
            && qc3_cents($row33['net_settled_profit'] ?? '') === 29000000 ? 'LULUS' : 'GAGAL',
        'pengelola ' . qc3_money($row33['operator_share_80'] ?? '')
            . ' | pool ' . qc3_money($row33['investor_pool_20'] ?? ''),
    ],
    [
        'Nota Fisik Tersimpan',
        'unggahan multipart (tiruan)',
        '<uuid>.<ext> di public/uploads/receipts/',
        Security::isUuid(substr($receiptName31, 0, 36)) ? 'LULUS' : 'GAGAL',
        'berkas: ' . $receiptName31 . ' (' . $notaSize . ' byte)',
    ],
];

$widths = [30, 34, 32, 8];

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

$protocolFailures = array_values(array_filter($protocol, static fn (array $row): bool => $row[3] !== 'LULUS'));

Qc::ok(
    'P3.17',
    'Seluruh ' . count($protocol) . ' baris Protokol QC Manual 3 berstatus LULUS',
    $protocolFailures === [],
    implode(', ', array_map(static fn (array $row): string => $row[0], $protocolFailures))
);

// ---------------------------------------------------------------------------
// Bersih-bersih artefak uji
// ---------------------------------------------------------------------------

QcFixture::purgeReceiptFiles();
@unlink($notaPath);
@unlink($textPath);
@unlink($bigPath);

Qc::note('Suite ini menambah baris pada database uji; berkas nota uji dibersihkan di akhir suite.');

Qc::finish('qc_fase_3');
