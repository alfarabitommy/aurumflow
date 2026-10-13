<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Protokol QC Manual 2 — Transaksi Meja Kasir Malam (Fase 2)
|--------------------------------------------------------------------------
| Implementasi otomatis dari "Protokol QC Manual 2" pada dokumen
| docs/AurumFlow Roadmap and QC Documents.md (FASE 2).
|
| Tabel manual tersebut meminta: isi saldo awal Kas Meja Bakar sebesar
| Rp 50.000.000, lalu masukkan DUA transaksi secara berurutan. Suite ini
| menjalankannya lewat jalur kode yang sama dengan aplikasi (TellerService),
| bukan lewat INSERT langsung — sehingga yang diuji adalah perilaku sistem.
|
| Cakupan:
|   P2.x  Protokol manual 2 (angka persis seperti dokumen Roadmap)
|   A.    Kalkulasi murni & pembulatan uang
|   B.    Parsing input lapangan (berat & harga)
|   C.    Atomisitas transaksi (SAD § 4.1)
|   D.    Saldo kas tidak cukup & batas input
|   E.    Server tidak mempercayai nominal dari klien
|   F.    Anti double-submit (token sekali-pakai `_once`, diuji lewat HTTP)
|   G.    Perilaku lewat HTTP sungguhan (QcHttp), termasuk isolasi peran
|   H.    Pemindaian statis & aset
|   I.    Integritas skema
|
| PERINGATAN: suite ini MENULIS ke database yang ditunjuk `.env`
| (cash_registers, hulu_transactions, cash_mutations, users). Jangan
| arahkan ke database produksi. Konvensi exit code: 0 = lulus, 1 = gagal.
*/

require __DIR__ . '/bootstrap.php';

use AurumFlow\Config\Constants;
use AurumFlow\Config\Database;
use AurumFlow\Config\Env;
use AurumFlow\Helpers\Formatter;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\CashMutation;
use AurumFlow\Models\CashRegister;
use AurumFlow\Models\HuluTransaction;
use AurumFlow\Models\User;
use AurumFlow\Services\TellerService;

Qc::suite('Protokol QC Manual 2 — Transaksi Meja Kasir Malam (qc_fase_2.php)');

Qc::note('Sumber: docs/AurumFlow Roadmap and QC Documents.md — FASE 2');
Qc::note('PERINGATAN: suite ini menulis ke database pada .env (jangan ke produksi).');

// ---------------------------------------------------------------------------
// Helper lokal
// ---------------------------------------------------------------------------

/**
 * Pemindai statis untuk daftar berkas eksplisit.
 *
 * `qc_scan_lines()` pada harness memindai SELURUH app/ + public/ sekaligus dan
 * predikatnya hanya menerima isi baris (tanpa path). Karena itu ia tidak dapat
 * dipakai untuk memeriksa berkas Fase 2 saja — misalnya `(float)` di
 * Formatter.php memang sah untuk pemformatan tampilan, tetapi tidak boleh ada
 * pada jalur uang TellerService.
 *
 * @param list<string> $paths
 * @return list<string> daftar pelanggaran (file:baris)
 */
function qc2_scan(array $paths, callable $predicate): array
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
function qc2_input_block(string $view, string $name): ?string
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

        $found = qc2_input_tag($chunk);
    }

    return $found;
}

/**
 * Potong potongan menjadi satu tag `<input …>` saja.
 *
 * `>` di dalam potongan PHP (`<?= … ?>`) maupun panah (`=>`) bukan penutup tag,
 * jadi keduanya sengaja dilewati.
 */
function qc2_input_tag(string $chunk): string
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
function qc2_php_files(string $directory): array
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
 * Normalkan nilai uang dari database menjadi 2 desimal tanpa float
 * (SUM() mengembalikan skala yang dapat berbeda antar dialek server).
 */
function qc2_money(mixed $value): string
{
    $text = trim((string) $value);

    if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $text, $matches) !== 1) {
        return 'tidak-valid:' . $text;
    }

    return $matches[1] . '.' . str_pad((string) ($matches[2] ?? ''), 2, '0', STR_PAD_RIGHT);
}

// ---------------------------------------------------------------------------
// Penyiapan: Rp 50.000.000 pada Kas Meja Bakar
// ---------------------------------------------------------------------------

Qc::section('Penyiapan kas meja (setara instruksi manual phpMyAdmin)');

$fixture = QcFixture::resetTellerState();
$registerId = $fixture['register_id'];

$balance = static fn (): string => QcFixture::registerBalance($registerId);

/**
 * @return array<string, mixed>|null
 */
$huluRow = static fn (string $id): ?array => Database::fetchOne(
    'SELECT * FROM `hulu_transactions` WHERE `id` = :id LIMIT 1',
    ['id' => $id]
);

/**
 * @return list<array<string, mixed>>
 */
$mutations = static fn (): array => Database::fetchAll(
    'SELECT * FROM `cash_mutations` WHERE `register_id` = :register_id ORDER BY `created_at`, `id`',
    ['register_id' => $registerId]
);

$burnFee = TellerService::configuredBurnFee();

Qc::ok(
    'P2.0a',
    'Pos kas "Kas Meja Bakar" tersedia (seed ERD)',
    $fixture['register_name'] === CashRegister::TELLER_NAME,
    'nama=' . $fixture['register_name']
);

Qc::equals(
    'P2.0b',
    'Saldo awal kas meja = Rp 50.000.000,00',
    '50000000.00',
    $fixture['balance']
);

Qc::equals(
    'P2.0c',
    'Kas meja bersih dari transaksi sebelumnya (riwayat nol)',
    [0, 0, '0.000'],
    [
        HuluTransaction::holdingCount(),
        CashMutation::countFor($registerId, CashMutation::TYPE_OUT),
        HuluTransaction::holdingTotalGrams(),
    ]
);

Qc::equals(
    'P2.0d',
    'Jasa bakar dari system_settings: flat 20.000 = upah 10.000 + konsumabel 10.000',
    ['20000.00', '10000.00', '10000.00'],
    [$burnFee['fee'], $burnFee['operator_cut'], $burnFee['consumable_cut']]
);

// ---------------------------------------------------------------------------
// A & B. Kalkulasi murni dan parsing input (tanpa menyentuh database)
// ---------------------------------------------------------------------------

Qc::section('A/B. Kalkulasi uang & parsing input lapangan (tanpa database)');

// Parameter biaya eksplisit -> quote() murni, tidak membaca system_settings.
$feeSpec = ['fee_cents' => 2000000, 'operator_cut_cents' => 1000000];

$quoteA1 = TellerService::quote('14,850', '1.950.000', true, $feeSpec);
$quoteA2 = TellerService::quote('3,200', '1.950.000', true, $feeSpec);

Qc::equals(
    'A.1',
    'Transaksi 1: nilai tebus kotor Rp 28.957.500 / net Rp 28.937.500',
    ['28957500.00', '28937500.00'],
    [$quoteA1['gross'], $quoteA1['net']]
);

Qc::equals(
    'A.2',
    'Transaksi 2: nilai tebus kotor Rp 6.240.000 / net Rp 6.220.000',
    ['6240000.00', '6220000.00'],
    [$quoteA2['gross'], $quoteA2['net']]
);

$quoteRound = TellerService::quote('0,333', '1.950.000,33', true, $feeSpec);

Qc::equals(
    'A.3',
    'Pembulatan half away from zero pada sisa sub-sen (0,333 gr x Rp 1.950.000,33)',
    ['649350.11', '629350.11'],
    [$quoteRound['gross'], $quoteRound['net']]
);

$quoteOff = TellerService::quote('14,850', '1.950.000', false, $feeSpec);

Qc::equals(
    'A.4',
    'Toggle jasa bakar nonaktif: fee 0, split 0/0, net = nilai tebus kotor',
    ['0.00', '0.00', '0.00', '28957500.00'],
    [$quoteOff['fee'], $quoteOff['operator_cut'], $quoteOff['consumable_cut'], $quoteOff['net']]
);

Qc::ok(
    'A.5',
    'Invariant jasa bakar: upah pengelola + konsumabel = fee',
    $quoteA1['operator_cut_cents'] + $quoteA1['consumable_cut_cents'] === $quoteA1['fee_cents'],
    'split=' . $quoteA1['operator_cut'] . ' + ' . $quoteA1['consumable_cut']
);

// --- Parsing berat & harga -------------------------------------------------

$weightParsing = [
    ['14,850', '14.850'],
    ['14.850', '14.850'],
    ['14.850 gr', '14.850'],
    ['14', '14.000'],
    ['0,001', '0.001'],
];

$weightFailures = [];

foreach ($weightParsing as [$input, $expected]) {
    try {
        // Jasa bakar dinonaktifkan agar tabel ini murni menguji PARSING:
        // nilai terkecil (0,001 gr) memang tidak cukup menutup fee Rp 20.000.
        $actual = TellerService::quote($input, '1.950.000', false, $feeSpec)['weight'];
    } catch (Throwable $exception) {
        $weightFailures[] = '"' . $input . '" -> exception: ' . $exception->getMessage();

        continue;
    }

    if ($actual !== $expected) {
        $weightFailures[] = '"' . $input . '" -> ' . $actual . ' (harusnya ' . $expected . ')';
    }
}

Qc::ok(
    'B.1',
    'Berat: satu pemisah selalu berarti desimal (14,850 / 14.850 / "14.850 gr")',
    $weightFailures === [],
    implode("\n", $weightFailures)
);

Qc::equals(
    'B.2',
    'Harga: titik pemisah ribuan, koma desimal (Rp 1.950.000,50)',
    '1950000.50',
    TellerService::quote('1,000', '1.950.000,50', true, $feeSpec)['price']
);

$invalidWeights = ['', '0', '-5', '14,85,0', '14,8500', '999999', '100000,000', 'abc'];
$acceptedWeights = [];

foreach ($invalidWeights as $input) {
    try {
        TellerService::quote($input, '1.950.000', true, $feeSpec);
        $acceptedWeights[] = $input;
    } catch (InvalidArgumentException) {
        // diharapkan: input tidak sah harus ditolak
    }
}

Qc::ok(
    'B.3',
    'Berat tidak sah ditolak (kosong, nol, negatif, dua pemisah, >3 desimal, di luar kapasitas)',
    $acceptedWeights === [],
    'diterima padahal seharusnya ditolak: ' . implode(', ', $acceptedWeights)
);

$invalidPrices = ['', '0', '1.95.0', '1.950.000,123', '9999999999999', 'abc', '-1.950.000'];
$acceptedPrices = [];

foreach ($invalidPrices as $input) {
    try {
        TellerService::quote('14,850', $input, true, $feeSpec);
        $acceptedPrices[] = $input;
    } catch (InvalidArgumentException) {
        // diharapkan
    }
}

Qc::ok(
    'B.4',
    'Harga tidak sah ditolak (kosong, nol, negatif, ribuan salah, >2 desimal, di luar kapasitas)',
    $acceptedPrices === [],
    'diterima padahal seharusnya ditolak: ' . implode(', ', $acceptedPrices)
);

// ---------------------------------------------------------------------------
// Transaksi 1 — QC Manual 2.1
// ---------------------------------------------------------------------------

Qc::section('Transaksi 1 — Pak Anto, 14,850 gr @ Rp 1.950.000 (QC Manual 2.1)');

$tx1 = TellerService::payCash([
    'weight'           => '14,850',
    'price'            => '1.950.000',
    'farmer_alias'     => 'Pak Anto',
    'burn_fee_enabled' => true,
]);

$row1 = $huluRow((string) $tx1['hulu_id']);

Qc::ok(
    'P2.1a',
    'Baris hulu_transactions tersimpan dengan status holding (belum masuk settlement)',
    $row1 !== null && (string) $row1['status'] === 'holding' && $row1['settlement_id'] === null,
    'status=' . (string) ($row1['status'] ?? '-') . ' settlement_id=' . var_export($row1['settlement_id'] ?? null, true)
);

Qc::equals(
    'P2.1b',
    'Nilai tebus kotor tersimpan Rp 28.957.500,00',
    '28957500.00',
    (string) ($row1['gross_tebus_total'] ?? '')
);

Qc::equals(
    'P2.1c',
    'Kas keluar bersih tersimpan Rp 28.937.500,00',
    '28937500.00',
    (string) ($row1['net_cash_paid'] ?? '')
);

Qc::equals(
    'P2.1d',
    'Tampilan Formatter::rupiah() untuk kas keluar bersih',
    'Rp 28.937.500',
    Formatter::rupiah($tx1['net'])
);

Qc::equals(
    'P2.2a',
    'Alokasi jasa bakar: flat 20.000 = upah pengelola 10.000 + kowi/gas 10.000',
    ['20000.00', '10000.00', '10000.00'],
    [
        (string) ($row1['flat_burn_fee'] ?? ''),
        (string) ($row1['operator_burn_cut'] ?? ''),
        (string) ($row1['consumable_cut'] ?? ''),
    ]
);

Qc::equals(
    'P2.2b',
    'Identitas transaksi tersimpan apa adanya (alias & berat)',
    ['Pak Anto', '14.850'],
    [(string) ($row1['farmer_alias'] ?? ''), (string) ($row1['dry_weight'] ?? '')]
);

Qc::equals(
    'P2.4a',
    'Sisa kas meja Rp 50.000.000 - Rp 28.937.500 = Rp 21.062.500,00',
    '21062500.00',
    $balance()
);

$mutations1 = $mutations();

Qc::ok(
    'P2.5a',
    'Tercatat 1 mutasi kas keluar (type "out") senilai kas keluar bersih',
    count($mutations1) === 1
        && (string) $mutations1[0]['mutation_type'] === CashMutation::TYPE_OUT
        && (string) $mutations1[0]['amount'] === '28937500.00',
    'jumlah=' . count($mutations1) . ' amount=' . (string) ($mutations1[0]['amount'] ?? '-')
);

Qc::ok(
    'P2.5b',
    'Mutasi kas mengaitkan dirinya ke baris hulu & memuat alias penambang',
    str_contains((string) ($mutations1[0]['notes'] ?? ''), (string) $tx1['hulu_id'])
        && str_contains((string) ($mutations1[0]['source_destination'] ?? ''), 'Pak Anto'),
    'notes=' . (string) ($mutations1[0]['notes'] ?? '-')
);

// ---------------------------------------------------------------------------
// Transaksi 2 — QC Manual 2.2
// ---------------------------------------------------------------------------

Qc::section('Transaksi 2 — Petani B-02, 3,200 gr @ Rp 1.950.000 (QC Manual 2.2)');

$tx2 = TellerService::payCash([
    'weight'           => '3,200',
    'price'            => '1.950.000',
    'farmer_alias'     => 'Petani B-02',
    'burn_fee_enabled' => true,
]);

$row2 = $huluRow((string) $tx2['hulu_id']);

Qc::equals(
    'P2.6a',
    'Nilai tebus kotor Rp 6.240.000,00 dan kas keluar bersih Rp 6.220.000,00',
    ['6240000.00', '6220000.00'],
    [(string) ($row2['gross_tebus_total'] ?? ''), (string) ($row2['net_cash_paid'] ?? '')]
);

Qc::equals(
    'P2.6b',
    'Status transaksi 2 tetap holding dengan pembagian jasa bakar 10.000/10.000',
    ['holding', '10000.00', '10000.00'],
    [
        (string) ($row2['status'] ?? ''),
        (string) ($row2['operator_burn_cut'] ?? ''),
        (string) ($row2['consumable_cut'] ?? ''),
    ]
);

Qc::equals(
    'P2.7a',
    'Sisa kas meja Rp 21.062.500 - Rp 6.220.000 = Rp 14.842.500,00',
    '14842500.00',
    $balance()
);

Qc::equals(
    'P2.8a',
    'Akumulasi emas berstatus holding = 18.050 gr dari 2 nota',
    ['18.050', 2],
    [HuluTransaction::holdingTotalGrams(), HuluTransaction::holdingCount()]
);

Qc::equals(
    'P2.8b',
    'Tampilan Formatter::gram() untuk akumulasi',
    '18,050 gr',
    Formatter::gram(HuluTransaction::holdingTotalGrams())
);

// Rekonsiliasi silang untuk Protokol QC Manual 3 (diambil SEBELUM uji HTTP
// menambah baris, supaya angkanya tetap cocok dengan dokumen Roadmap).
$aggregate = Database::fetchOne(
    'SELECT COALESCE(SUM(`net_cash_paid`), 0) AS `net`, COALESCE(SUM(`flat_burn_fee`), 0) AS `fee`, '
    . 'COALESCE(SUM(`gross_tebus_total`), 0) AS `gross` FROM `hulu_transactions` WHERE `status` = :status',
    ['status' => HuluTransaction::STATUS_HOLDING]
);

$protocolSnapshot = [
    'balance_after_tx1' => (string) $tx1['balance_after'],
    'balance_after_tx2' => (string) $tx2['balance_after'],
    'holding_grams'     => HuluTransaction::holdingTotalGrams(),
    'holding_count'     => HuluTransaction::holdingCount(),
    'net_sum'           => qc2_money($aggregate['net'] ?? 0),
    'fee_sum'           => qc2_money($aggregate['fee'] ?? 0),
    'gross_sum'         => qc2_money($aggregate['gross'] ?? 0),
];

Qc::equals(
    'P2.9a',
    'Total modal belanja hulu Rp 35.157.500,00 (net) + jasa bakar Rp 40.000,00',
    ['35157500.00', '40000.00', '35197500.00'],
    [$protocolSnapshot['net_sum'], $protocolSnapshot['fee_sum'], $protocolSnapshot['gross_sum']]
);

Qc::equals(
    'P2.10a',
    'Saldo kas diformat untuk header layar kasir',
    'Rp 14.842.500',
    Formatter::rupiah($protocolSnapshot['balance_after_tx2'])
);

$recent = HuluTransaction::recent(3);

Qc::ok(
    'P2.10b',
    'Widget riwayat mengembalikan kedua transaksi uji (bukan lebih dari 3 baris)',
    count($recent) === 2
        && array_sum(array_map(
            static fn (array $row): int => in_array(
                (string) $row['id'],
                [(string) $tx1['hulu_id'], (string) $tx2['hulu_id']],
                true
            ) ? 1 : 0,
            $recent
        )) === 2,
    'baris=' . count($recent)
);

Qc::equals(
    'P2.10c',
    'Batas riwayat dihormati (LIMIT 1 -> satu baris)',
    1,
    count(HuluTransaction::recent(1))
);

// ---------------------------------------------------------------------------
// C. Atomisitas transaksi
// ---------------------------------------------------------------------------

Qc::section('C. Atomisitas (SAD § 4.1) — tidak boleh ada kas yang menggantung');

$rowsBefore = HuluTransaction::holdingCount();
$mutationsBefore = CashMutation::countFor($registerId, CashMutation::TYPE_OUT);
$balanceBefore = $balance();

$rolledBack = false;

try {
    // Rantai tulisan yang PERSIS sama dengan TellerService::payCash()
    // (insert hulu -> potong kas -> catat mutasi) di dalam commit boundary yang
    // sama, lalu gagal di ujung. Assertion membuktikan ketiganya tergulung
    // bersama: tidak ada kas terpotong tanpa jejak transaksi.
    Database::transaction(static function () use ($quoteA2, $registerId): void {
        $huluId = Security::uuidV4();

        HuluTransaction::insert(
            $huluId,
            'Uji Rollback',
            $quoteA2['weight'],
            $quoteA2['price'],
            $quoteA2['gross'],
            $quoteA2['fee'],
            $quoteA2['operator_cut'],
            $quoteA2['consumable_cut'],
            $quoteA2['net']
        );

        CashRegister::adjustBalance($registerId, '-1000.00');

        CashMutation::insert(
            Security::uuidV4(),
            $registerId,
            CashMutation::TYPE_OUT,
            '1000.00',
            'Uji rollback QC'
        );

        throw new RuntimeException('injeksi kegagalan uji atomisitas');
    });
} catch (RuntimeException $exception) {
    $rolledBack = str_contains($exception->getMessage(), 'injeksi kegagalan uji atomisitas');
}

Qc::ok(
    'C.1',
    'Kegagalan di tengah transaksi menggulung seluruh tulisan (baris & kas utuh)',
    $rolledBack
        && HuluTransaction::holdingCount() === $rowsBefore
        && CashMutation::countFor($registerId, CashMutation::TYPE_OUT) === $mutationsBefore
        && $balance() === $balanceBefore,
    'gulung=' . var_export($rolledBack, true)
        . ' baris=' . HuluTransaction::holdingCount()
        . ' mutasi=' . CashMutation::countFor($registerId, CashMutation::TYPE_OUT)
        . ' saldo=' . $balance()
);

// Pos kas tidak ada -> ditolak SEBELUM ada tulisan (jalur payCash nyata).
Database::query(
    'UPDATE `cash_registers` SET `register_name` = :temporary WHERE `id` = :register_id',
    ['temporary' => 'Kas Meja Bakar (uji)', 'register_id' => $registerId]
);

$missingRegisterThrew = false;

try {
    TellerService::payCash([
        'weight'       => '1,000',
        'price'        => '1.950.000',
        'farmer_alias' => 'Uji Pos Kas Hilang',
    ]);
} catch (RuntimeException) {
    $missingRegisterThrew = true;
}

Database::query(
    'UPDATE `cash_registers` SET `register_name` = :name WHERE `id` = :register_id',
    ['name' => CashRegister::TELLER_NAME, 'register_id' => $registerId]
);

Qc::ok(
    'C.2',
    'Pos kas tidak ditemukan: ditolak, tanpa baris transaksi & saldo tidak berubah',
    $missingRegisterThrew
        && HuluTransaction::holdingCount() === $rowsBefore
        && $balance() === $balanceBefore,
    'ditolak=' . var_export($missingRegisterThrew, true) . ' baris=' . HuluTransaction::holdingCount()
);

// ---------------------------------------------------------------------------
// D. Saldo kas tidak cukup & batas input
// ---------------------------------------------------------------------------

Qc::section('D. Saldo kas tidak cukup & batas input');

$insufficientThrew = false;

try {
    // 50 kg x Rp 1.950.000 jauh melampaui saldo kas meja saat ini.
    TellerService::payCash([
        'weight'       => '50000',
        'price'        => '1.950.000',
        'farmer_alias' => 'Uji Saldo Kurang',
    ]);
} catch (RuntimeException $exception) {
    $insufficientThrew = str_contains($exception->getMessage(), 'Saldo kas meja tidak cukup');
}

Qc::ok(
    'D.1',
    'Pembayaran melebihi saldo ditolak tanpa mengubah kas maupun riwayat',
    $insufficientThrew
        && HuluTransaction::holdingCount() === $rowsBefore
        && CashMutation::countFor($registerId, CashMutation::TYPE_OUT) === $mutationsBefore
        && $balance() === $balanceBefore,
    'ditolak=' . var_export($insufficientThrew, true) . ' saldo=' . $balance()
);

$longAliasThrew = false;

try {
    TellerService::payCash([
        'weight'       => '1,000',
        'price'        => '1.950.000',
        'farmer_alias' => str_repeat('A', 51),
    ]);
} catch (InvalidArgumentException) {
    $longAliasThrew = true;
}

Qc::ok(
    'D.2',
    'Alias melebihi 50 karakter (VARCHAR(50)) ditolak sebelum transaksi dibuka',
    $longAliasThrew && HuluTransaction::holdingCount() === $rowsBefore && $balance() === $balanceBefore,
    'ditolak=' . var_export($longAliasThrew, true)
);

// ---------------------------------------------------------------------------
// E. Server tidak mempercayai nominal dari klien
// ---------------------------------------------------------------------------

Qc::section('E. Nominal dari klien diabaikan (server menghitung ulang)');

$forged = TellerService::payCash([
    'weight'            => '1,000',
    'price'             => '1.950.000',
    'farmer_alias'      => 'Uji Nominal Palsu',
    'burn_fee_enabled'  => true,
    // Dikirim "klien" palsu: service TIDAK boleh membaca kolom uang ini.
    'net_cash_paid'     => '1.00',
    'flat_burn_fee'     => '0.00',
    'gross_tebus_total' => '1.00',
]);

$forgedRow = $huluRow((string) $forged['hulu_id']);

Qc::equals(
    'E.1',
    'Kolom uang dihitung server dari system_settings, bukan dari kiriman klien',
    ['1950000.00', '20000.00', '1930000.00'],
    [
        (string) ($forgedRow['gross_tebus_total'] ?? ''),
        (string) ($forgedRow['flat_burn_fee'] ?? ''),
        (string) ($forgedRow['net_cash_paid'] ?? ''),
    ]
);

Qc::equals(
    'E.2',
    'Saldo berkurang tepat sebesar net hasil hitung server',
    '12912500.00',
    $balance()
);

// ---------------------------------------------------------------------------
// I. Integritas skema
// ---------------------------------------------------------------------------

Qc::section('I. Integritas skema (tanpa perubahan DDL di Fase 2)');

$decimalViolations = QcFixture::checkDecimalConventions([
    ['hulu_transactions', 'dry_weight', 8, 3],
    ['hulu_transactions', 'buy_price_per_g', 14, 2],
    ['hulu_transactions', 'gross_tebus_total', 14, 2],
    ['hulu_transactions', 'flat_burn_fee', 14, 2],
    ['hulu_transactions', 'operator_burn_cut', 14, 2],
    ['hulu_transactions', 'consumable_cut', 14, 2],
    ['hulu_transactions', 'net_cash_paid', 14, 2],
    ['cash_registers', 'current_balance', 14, 2],
    ['cash_mutations', 'amount', 14, 2],
]);

Qc::ok(
    'I.1',
    'Kolom uang/berat tabel kasir memakai konvensi DECIMAL ERD § 3.1',
    $decimalViolations === [],
    implode("\n", $decimalViolations)
);

// ---------------------------------------------------------------------------
// H. Pemindaian statis & aset
// ---------------------------------------------------------------------------

Qc::section('H. Pemindaian statis & aset frontend');

$fase2Sources = [
    APP_PATH . '/Services/TellerService.php',
    APP_PATH . '/Models/CashRegister.php',
    APP_PATH . '/Models/HuluTransaction.php',
    APP_PATH . '/Models/CashMutation.php',
    APP_PATH . '/Controllers/TellerController.php',
    APP_PATH . '/Views/operator/teller.php',
    APP_PATH . '/Views/layouts/operator.php',
    PUBLIC_PATH . '/assets/js/app.js',
];

$missingSources = array_values(array_filter($fase2Sources, static fn (string $path): bool => !is_file($path)));

Qc::ok(
    'H.0',
    'Seluruh berkas Fase 2 tersedia',
    $missingSources === [],
    implode("\n", array_map(static fn (string $path): string => str_replace(BASE_PATH . '/', '', $path), $missingSources))
);

$floatOffenders = qc2_scan(
    $fase2Sources,
    static fn (string $line): bool => preg_match('/\bfloatval\s*\(|\bdoubleval\s*\(|\(\s*(float|double)\s*\)|\bFLOAT\b|\bDOUBLE\b/', $line) === 1
);

Qc::ok(
    'H.1',
    'Tidak ada aritmetika float/double pada jalur uang Fase 2',
    $floatOffenders === [],
    implode("\n", $floatOffenders)
);

$numberFormatOffenders = qc2_scan(
    $fase2Sources,
    static fn (string $line): bool => str_contains($line, 'number_format(')
);

Qc::ok(
    'H.2',
    'Tidak ada number_format() di Fase 2 (wajib lewat Formatter)',
    $numberFormatOffenders === [],
    implode("\n", $numberFormatOffenders)
);

$manualTransactionOffenders = qc2_scan(
    $fase2Sources,
    static fn (string $line): bool => preg_match('/\b(beginTransaction|rollBack|commit)\s*\(/', $line) === 1
);

Qc::ok(
    'H.3',
    'Transaksi kas selalu lewat Database::transaction() (bukan begin/commit manual)',
    $manualTransactionOffenders === [],
    implode("\n", $manualTransactionOffenders)
);

$viewFiles = qc2_php_files(APP_PATH . '/Views');

$unescapedOffenders = qc2_scan(
    $viewFiles,
    // Pengecualian eksplisit: cetak `$content` pada layout memang HTML yang
    // sudah di-escape di view asalnya (lihat komentar BaseController::render()).
    static fn (string $line): bool => preg_match('/<\?=\s*\$(?!content\b)/', $line) === 1
);

Qc::ok(
    'H.4',
    'Seluruh output dinamis di view melewati Security::e() / helper aman',
    $unescapedOffenders === [],
    implode("\n", $unescapedOffenders)
);

$cdnOffenders = qc2_scan(
    $viewFiles,
    static fn (string $line): bool => preg_match('#(?:src|href)\s*=\s*["\']https?://#i', $line) === 1
);

Qc::ok(
    'H.5',
    'Tidak ada aset dari CDN pada view (aset selalu lokal)',
    $cdnOffenders === [],
    implode("\n", $cdnOffenders)
);

$legacyReference = qc2_scan(
    $viewFiles,
    static fn (string $line): bool => str_contains($line, 'app.css')
);

Qc::ok(
    'H.6',
    'View tidak lagi menunjuk assets/css/app.css dan berkas lamanya sudah dihapus',
    $legacyReference === [] && !is_file(PUBLIC_PATH . '/assets/css/app.css'),
    implode("\n", $legacyReference)
);

$cssPath = PUBLIC_PATH . '/assets/css/app.min.css';
$cssSize = is_file($cssPath) ? (int) filesize($cssPath) : 0;
$cssContents = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';

Qc::ok(
    'H.7',
    'CSS hasil kompilasi Tailwind tersedia, < 30 KB, memuat token tema Obsidian Gold',
    $cssSize > 0 && $cssSize < 30720
        && str_contains($cssContents, 'obsidian')
        && str_contains($cssContents, 'D4AF37')
        && str_contains($cssContents, 'tabular-nums'),
    'ukuran=' . $cssSize . ' byte'
);

$tellerView = (string) file_get_contents(APP_PATH . '/Views/operator/teller.php');

Qc::ok(
    'H.8',
    'Layar kasir memuat komponen geser, kalkulasi Alpine, token anti double-submit, dan font tabular',
    str_contains($tellerView, 'tellerForm(')
        && str_contains($tellerView, 'startDrag')
        && str_contains($tellerView, 'x-text="netLabel"')
        && str_contains($tellerView, 'Security::FORM_FIELD')
        && str_contains($tellerView, 'af-num'),
    'komponen swipe/kalkulasi tidak lengkap'
);

$sqlLeakOffenders = qc2_scan(
    [
        APP_PATH . '/Controllers/TellerController.php',
        APP_PATH . '/Views/operator/teller.php',
        APP_PATH . '/Views/layouts/operator.php',
    ],
    // Case-sensitive: SQL pada proyek ini selalu huruf besar, sehingga kelas
    // CSS seperti `select-none` tidak ikut tertangkap.
    static fn (string $line): bool => preg_match('/\b(SELECT|INSERT INTO|UPDATE|DELETE FROM|ORDER BY|LIMIT)\b/', $line) === 1
);

Qc::ok(
    'H.9',
    'SQL tidak bocor ke controller/view (hanya di app/Models + Config/Database)',
    $sqlLeakOffenders === [],
    implode("\n", $sqlLeakOffenders)
);

// ---------------------------------------------------------------------------
// H (lanjutan). Kontrak form kasir — Plan_Fix_Settlement_UI § 5.3
//
// Layar kasir memakai pola nilai-awal yang sama dengan layar rekonsiliasi:
// `type="number"` membuang pemisah ribuan, dan pembacaan `$refs` pada `init()`
// menghapus isian yang dikembalikan peladen setelah kegagalan validasi (422).
// ---------------------------------------------------------------------------

$appJs = (string) file_get_contents(PUBLIC_PATH . '/assets/js/app.js');

$numberTypeOffenders = qc2_scan(
    [APP_PATH . '/Views/operator/teller.php'],
    static fn (string $line): bool => str_contains($line, 'type="number"')
);

Qc::ok(
    'H.10',
    'Tidak ada type="number" pada layar kasir (peramban menghapus pemisah ribuan saat submit)',
    $numberTypeOffenders === [],
    implode("\n", $numberTypeOffenders)
);

$tellerFieldModes = [
    'dry_weight'       => 'decimal',
    'buy_price_per_g'  => 'numeric',
];

$tellerFieldProblems = [];

foreach ($tellerFieldModes as $fieldName => $inputmode) {
    $fieldBlock = qc2_input_block($tellerView, $fieldName);

    if ($fieldBlock === null) {
        $tellerFieldProblems[] = $fieldName . ': input tidak ditemukan / name ganda';

        continue;
    }

    if (!str_contains($fieldBlock, 'type="text"')) {
        $tellerFieldProblems[] = $fieldName . ': bukan type="text"';
    }

    if (!str_contains($fieldBlock, 'inputmode="' . $inputmode . '"')) {
        $tellerFieldProblems[] = $fieldName . ': inputmode bukan ' . $inputmode;
    }

    if (!str_contains($fieldBlock, 'autocomplete="off"')) {
        $tellerFieldProblems[] = $fieldName . ': autocomplete="off" hilang';
    }

    if (!str_contains($fieldBlock, 'x-model.fill="')) {
        $tellerFieldProblems[] = $fieldName . ': tanpa modifier .fill';
    }
}

Qc::ok(
    'H.11',
    'Dua isian angka layar kasir: type="text" + inputmode + autocomplete="off" + x-model.fill',
    $tellerFieldProblems === [],
    implode("\n", $tellerFieldProblems)
);

$tellerFormStart = strpos($appJs, 'function tellerForm(');
$tellerFormEnd = strpos($appJs, "Alpine.data('tellerForm'");
$tellerFormJs = ($tellerFormStart !== false
    && $tellerFormEnd !== false
    && $tellerFormEnd > $tellerFormStart)
    ? substr($appJs, $tellerFormStart, $tellerFormEnd - $tellerFormStart)
    : '';

$tellerInitStart = strpos($tellerFormJs, 'init: function (');
$tellerInitEnd = $tellerInitStart === false ? false : strpos($tellerFormJs, "\n            },", $tellerInitStart);
$tellerInitBlock = ($tellerInitStart !== false && $tellerInitEnd !== false)
    ? substr($tellerFormJs, $tellerInitStart, $tellerInitEnd - $tellerInitStart)
    : '';

$tellerInitProblems = [];

if ($tellerFormJs === '') {
    $tellerInitProblems[] = 'komponen tellerForm tidak ditemukan di app.js';
}

if ($tellerInitBlock === '') {
    $tellerInitProblems[] = 'blok init() tellerForm tidak terbaca';
} else {
    if (!str_contains($tellerInitBlock, "formFieldValue(this, 'dry_weight')")
        || !str_contains($tellerInitBlock, "formFieldValue(this, 'buy_price_per_g')")
    ) {
        $tellerInitProblems[] = 'init() tidak membaca berat/harga lewat formFieldValue()';
    }

    if (!str_contains($tellerInitBlock, "formFieldChecked(this, 'burn_fee_enabled'")) {
        $tellerInitProblems[] = 'init() tidak membaca status jasa bakar dari DOM';
    }

    if (str_contains($tellerInitBlock, '$refs')) {
        $tellerInitProblems[] = 'init() masih memakai $refs (belum terdaftar saat init berjalan)';
    }
}

Qc::ok(
    'H.12',
    'tellerForm.init() membaca DOM tanpa $refs: isian & toggle jasa bakar bertahan setelah 422',
    $tellerInitProblems === [],
    implode("\n", $tellerInitProblems)
);

// Kontrol `disabled` dikeluarkan peramban dari data form: dengan
// `:disabled="submitting"` pada isian, POST kasir hanya membawa `_token`/`_once`
// dan peladen menjawab 422 "Berat kering wajib diisi" (akar yang sama dengan
// layar rekonsiliasi). Selama pengiriman dipakai `:readonly="submitting"`.
$tellerControls = ['farmer_alias', 'dry_weight', 'buy_price_per_g', 'burn_fee_enabled'];

$tellerDisabledProblems = [];

foreach ($tellerControls as $controlName) {
    $controlBlock = qc2_input_block($tellerView, $controlName);

    if ($controlBlock === null) {
        $tellerDisabledProblems[] = $controlName . ': input tidak ditemukan / name ganda';

        continue;
    }

    if (str_contains($controlBlock, ':disabled')) {
        $tellerDisabledProblems[] = $controlName . ': memakai :disabled — kontrol hilang dari payload';
    }

    if (!str_contains($controlBlock, ':readonly="submitting"')) {
        $tellerDisabledProblems[] = $controlName . ': tanpa :readonly="submitting"';
    }
}

// Tombol submit memang memakai `:disabled`, tetapi `canPay` sudah memuat
// `!submitting` sehingga tombol terkunci selama pengiriman.
if (preg_match('/get canPay\(\) \{\s*return [^}]*!this\.submitting/s', $appJs) !== 1) {
    $tellerDisabledProblems[] = 'canPay() tidak lagi memuat !submitting (tombol bisa diklik dua kali)';
}

Qc::ok(
    'H.13',
    'Kontrol form kasir memakai :readonly (bukan :disabled) sehingga berat & harga tetap terkirim',
    $tellerDisabledProblems === [],
    implode("\n", $tellerDisabledProblems)
);

// ---------------------------------------------------------------------------
// G & F. Perilaku lewat HTTP sungguhan (termasuk anti double-submit)
// ---------------------------------------------------------------------------

Qc::section('G/F. Layar kasir lewat HTTP: login, guard peran, submit, replay token');

$credentials = QcFixture::ensureInstalled();
QcHttp::start();

$anonJar = QcHttp::cookieJar('teller-anon');
$anonPage = QcHttp::request('GET', '/operator/teller', ['cookie_jar' => $anonJar, 'fresh' => true]);

Qc::ok(
    'G.1',
    'Kasir hulu tanpa login diarahkan ke /login',
    $anonPage['status'] === 302 && $anonPage['location'] === '/login',
    'status=' . $anonPage['status'] . ' location=' . (string) $anonPage['location']
);

$operatorJar = QcHttp::cookieJar('teller-operator');
$loginForm = QcHttp::request('GET', '/login', ['cookie_jar' => $operatorJar, 'fresh' => true]);
$loginToken = QcHttp::csrfFrom($loginForm['body']);

$login = QcHttp::request('POST', '/login', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD => (string) $loginToken,
        'phone_number'       => $credentials['phone'],
        'password'           => $credentials['password'],
    ],
]);

Qc::ok(
    'G.2',
    'Operator berhasil login (302 -> /dashboard)',
    $login['status'] === 302 && $login['location'] === '/dashboard',
    'status=' . $login['status'] . ' location=' . (string) $login['location']
);

$balanceBeforePage = $balance();
$tellerPage = QcHttp::request('GET', '/operator/teller', ['cookie_jar' => $operatorJar]);

// Alias mana pun yang saat ini ada di riwayat boleh muncul: kolom created_at
// berpresisi detik, sehingga URUTAN antar transaksi pada detik yang sama tidak
// dijamin (item O-1 pada rencana). Yang diuji: widget benar-benar merender
// alias dari database, bukan angka karangan.
$recentAliases = array_map(
    static fn (array $row): string => (string) $row['farmer_alias'],
    HuluTransaction::recent(3)
);

$aliasRendered = false;

foreach ($recentAliases as $alias) {
    if ($alias !== '' && str_contains($tellerPage['body'], $alias)) {
        $aliasRendered = true;

        break;
    }
}

Qc::ok(
    'G.3',
    'Halaman kasir 200 memuat saldo kas terformat, komponen geser, dan alias dari riwayat',
    $tellerPage['status'] === 200
        && str_contains($tellerPage['body'], Formatter::rupiah($balanceBeforePage))
        && str_contains($tellerPage['body'], 'Geser untuk bayar tunai')
        && str_contains($tellerPage['body'], 'Transaksi Terakhir')
        && $aliasRendered,
    'status=' . $tellerPage['status'] . ' alias=' . implode('|', $recentAliases)
);

// KEADAAN AWAL yang dirender peladen untuk layar kasir: berat & harga memang
// KOSONG (operator mengetik dari timbangan/nota, lihat
// `TellerController::defaultInput()`), sedangkan toggle jasa bakar membawa
// status dari server. Keduanya harus terbaca peramban apa adanya — dasar
// klaim "isian terpelihara setelah 422" pada § O-3 Summary Fase 2.
$burnFeeBlockPage = qc2_input_block($tellerPage['body'], 'burn_fee_enabled');
$weightBlockPage = qc2_input_block($tellerPage['body'], 'dry_weight');
$priceBlockPage = qc2_input_block($tellerPage['body'], 'buy_price_per_g');

Qc::ok(
    'G.3a',
    'Layar kasir merender status jasa bakar (checked) dan membiarkan berat/harga kosong untuk diisi operator',
    $burnFeeBlockPage !== null
        && str_contains($burnFeeBlockPage, 'checked')
        && $weightBlockPage !== null
        && str_contains($weightBlockPage, 'value=""')
        && $priceBlockPage !== null
        && str_contains($priceBlockPage, 'value=""'),
    'burnFee=' . var_export($burnFeeBlockPage !== null, true)
        . ' weight=' . var_export($weightBlockPage !== null, true)
        . ' price=' . var_export($priceBlockPage !== null, true)
);

$pageToken = QcHttp::csrfFrom($tellerPage['body']);
$pageOnce = QcHttp::fieldFrom($tellerPage['body'], Security::FORM_FIELD);

Qc::ok(
    'G.4',
    'Form kasir adalah POST nyata dengan token CSRF + token sekali-pakai',
    $pageToken !== null && $pageOnce !== null
        && str_contains($tellerPage['body'], 'method="post"')
        && str_contains($tellerPage['body'], 'name="dry_weight"')
        && str_contains($tellerPage['body'], 'name="buy_price_per_g"'),
    'csrf=' . var_export($pageToken !== null, true) . ' once=' . var_export($pageOnce !== null, true)
);

$rowsBeforePost = HuluTransaction::holdingCount();
$balanceBeforePost = $balance();

$post = QcHttp::request('POST', '/operator/teller', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD => (string) $pageToken,
        Security::FORM_FIELD => (string) $pageOnce,
        'farmer_alias'       => 'QC HTTP',
        'dry_weight'         => '1,000',
        'buy_price_per_g'    => '1.950.000',
        'burn_fee_enabled'   => '1',
    ],
]);

$balanceAfterPost = $balance();
$centsDelta = TellerService::moneyToCents($balanceBeforePost) - TellerService::moneyToCents($balanceAfterPost);

Qc::ok(
    'G.5',
    'POST kasir (302) mencatat tepat 1 transaksi dan memotong kas Rp 1.930.000',
    $post['status'] === 302
        && HuluTransaction::holdingCount() === $rowsBeforePost + 1
        && $centsDelta === 193000000,
    'status=' . $post['status'] . ' baris=' . HuluTransaction::holdingCount() . ' selisih_sen=' . $centsDelta
);

$replay = QcHttp::request('POST', '/operator/teller', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD => (string) $pageToken,
        Security::FORM_FIELD => (string) $pageOnce,
        'farmer_alias'       => 'QC HTTP (ulang)',
        'dry_weight'         => '1,000',
        'buy_price_per_g'    => '1.950.000',
        'burn_fee_enabled'   => '1',
    ],
]);

Qc::ok(
    'F.1',
    'Replay token sekali-pakai diabaikan: tidak ada baris baru, kas tidak berkurang',
    $replay['status'] === 302
        && HuluTransaction::holdingCount() === $rowsBeforePost + 1
        && $balance() === $balanceAfterPost,
    'status=' . $replay['status'] . ' saldo=' . $balance()
);

$afterReplay = QcHttp::request('GET', '/operator/teller', ['cookie_jar' => $operatorJar]);

Qc::ok(
    'F.2',
    'Operator menerima pesan "transaksi ganda diabaikan" pada halaman berikutnya',
    str_contains($afterReplay['body'], 'ganda diabaikan'),
    'pesan tidak ditemukan'
);

// Token replay yang sama harus tetap ditolak walau halaman sudah di-render ulang
// (token baru diterbitkan untuk submit berikutnya, token lama tetap mati).
$secondReplay = QcHttp::request('POST', '/operator/teller', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD => (string) QcHttp::csrfFrom($afterReplay['body']),
        Security::FORM_FIELD => (string) $pageOnce,
        'farmer_alias'       => 'QC HTTP (ulang ke-2)',
        'dry_weight'         => '1,000',
        'buy_price_per_g'    => '1.950.000',
    ],
]);

Qc::ok(
    'F.3',
    'Token lama tetap ditolak setelah halaman di-render ulang (hanya boleh dipakai sekali)',
    $secondReplay['status'] === 302
        && HuluTransaction::holdingCount() === $rowsBeforePost + 1
        && $balance() === $balanceAfterPost,
    'status=' . $secondReplay['status'] . ' saldo=' . $balance()
);

$freshForm = QcHttp::request('GET', '/operator/teller', ['cookie_jar' => $operatorJar]);
$freshToken = QcHttp::csrfFrom($freshForm['body']);

$noOnce = QcHttp::request('POST', '/operator/teller', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        Security::CSRF_FIELD => (string) $freshToken,
        'farmer_alias'       => 'QC Tanpa Token Sekali',
        'dry_weight'         => '1,000',
        'buy_price_per_g'    => '1.950.000',
    ],
]);

Qc::ok(
    'F.4',
    'POST tanpa token sekali-pakai ditolak (419) tanpa mencatat transaksi',
    $noOnce['status'] === 419 && $balance() === $balanceAfterPost,
    'status=' . $noOnce['status'] . ' saldo=' . $balance()
);

$noCsrf = QcHttp::request('POST', '/operator/teller', [
    'cookie_jar' => $operatorJar,
    'data'       => [
        'farmer_alias'    => 'QC Tanpa CSRF',
        'dry_weight'      => '1,000',
        'buy_price_per_g' => '1.950.000',
    ],
]);

Qc::ok(
    'F.5',
    'POST tanpa token CSRF ditolak (419) tanpa mencatat transaksi',
    $noCsrf['status'] === 419 && $balance() === $balanceAfterPost,
    'status=' . $noCsrf['status']
);

// --- Isolasi peran: investor tidak boleh menyentuh kasir -------------------

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

$investorJar = QcHttp::cookieJar('teller-investor');
$investorForm = QcHttp::request('GET', '/login', ['cookie_jar' => $investorJar, 'fresh' => true]);

$investorLogin = QcHttp::request('POST', '/login', [
    'cookie_jar' => $investorJar,
    'data'       => [
        Security::CSRF_FIELD => (string) QcHttp::csrfFrom($investorForm['body']),
        'phone_number'       => $investorPhone,
        'password'           => $investorPassword,
    ],
]);

$investorTeller = QcHttp::request('GET', '/operator/teller', ['cookie_jar' => $investorJar]);

Qc::ok(
    'G.6',
    'Investor diarahkan ke /investor/dashboard saat membuka URL kasir (Akses URL Kasir Ilegal)',
    $investorLogin['status'] === 302
        && $investorTeller['status'] === 302
        && $investorTeller['location'] === '/investor/dashboard',
    'login=' . $investorLogin['status'] . ' teller=' . $investorTeller['status']
        . ' location=' . (string) $investorTeller['location']
);

// Sejak Fase 5 "rumah" mitra adalah /investor/dashboard (bukan /dashboard yang
// operator-only), jadi token CSRF untuk uji penyusupan dibaca dari sana.
$investorDashboard = QcHttp::request('GET', '/investor/dashboard', ['cookie_jar' => $investorJar]);
$investorToken = QcHttp::csrfFrom($investorDashboard['body']);

$investorPost = QcHttp::request('POST', '/operator/teller', [
    'cookie_jar' => $investorJar,
    // Token sah milik sesi investor: yang diuji adalah guard PERAN, bukan CSRF.
    'data'       => [
        Security::CSRF_FIELD => (string) $investorToken,
        'farmer_alias'       => 'Investor Menyusup',
        'dry_weight'         => '1,000',
        'buy_price_per_g'    => '1.950.000',
    ],
]);

Qc::ok(
    'G.7',
    'Investor tidak dapat mengirim transaksi kasir (ditolak, kas tidak berubah)',
    $investorPost['status'] === 302
        && HuluTransaction::holdingCount() === $rowsBeforePost + 1
        && $balance() === $balanceAfterPost,
    'status=' . $investorPost['status'] . ' saldo=' . $balance()
);

// ---------------------------------------------------------------------------
// P2.11 & tabel protokol (siap disalin ke dokumen Roadmap)
// ---------------------------------------------------------------------------

Qc::section('Rekap Protokol QC Manual 2 (siap disalin ke dokumen Roadmap)');

$protocol = [
    [
        'Nilai Tebus Kotor (QC 2.1)',
        '14,850 x Rp 1.950.000',
        'Rp 28.957.500',
        (string) ($row1['gross_tebus_total'] ?? '') === '28957500.00' ? 'LULUS' : 'GAGAL',
        'tersimpan: ' . (string) ($row1['gross_tebus_total'] ?? '-') . ' | tampilan: ' . Formatter::rupiah(28957500),
    ],
    [
        'Kas Keluar Bersih (QC 2.1)',
        'Rp 28.957.500 - Rp 20.000',
        'Rp 28.937.500',
        (string) ($row1['net_cash_paid'] ?? '') === '28937500.00' ? 'LULUS' : 'GAGAL',
        'tersimpan: ' . (string) ($row1['net_cash_paid'] ?? '-'),
    ],
    [
        'Alokasi Jasa Bakar (QC 2.1)',
        'Rp 20.000 flat',
        'Rp 10.000 pengelola + Rp 10.000 kowi/gas',
        (string) ($row1['operator_burn_cut'] ?? '') === '10000.00'
            && (string) ($row1['consumable_cut'] ?? '') === '10000.00' ? 'LULUS' : 'GAGAL',
        'upah=' . (string) ($row1['operator_burn_cut'] ?? '-')
            . ' konsumabel=' . (string) ($row1['consumable_cut'] ?? '-'),
    ],
    [
        'Sisa Kas Meja (QC 2.1)',
        'Rp 50.000.000 - Rp 28.937.500',
        'Rp 21.062.500',
        $protocolSnapshot['balance_after_tx1'] === '21062500.00' ? 'LULUS' : 'GAGAL',
        'saldo setelah transaksi 1: ' . $protocolSnapshot['balance_after_tx1'],
    ],
    [
        'Status Data (QC 2.1 & 2.2)',
        "hulu_transactions",
        "Kedua baris berstatus 'holding'",
        (string) ($row1['status'] ?? '') === 'holding' && (string) ($row2['status'] ?? '') === 'holding' ? 'LULUS' : 'GAGAL',
        'settlement_id keduanya NULL',
    ],
    [
        'Nilai Tebus Kotor (QC 2.2)',
        '3,200 x Rp 1.950.000',
        'Rp 6.240.000',
        (string) ($row2['gross_tebus_total'] ?? '') === '6240000.00' ? 'LULUS' : 'GAGAL',
        'tersimpan: ' . (string) ($row2['gross_tebus_total'] ?? '-'),
    ],
    [
        'Kas Keluar Bersih (QC 2.2)',
        'Rp 6.240.000 - Rp 20.000',
        'Rp 6.220.000',
        (string) ($row2['net_cash_paid'] ?? '') === '6220000.00' ? 'LULUS' : 'GAGAL',
        'tersimpan: ' . (string) ($row2['net_cash_paid'] ?? '-'),
    ],
    [
        'Sisa Kas Meja (QC 2.2)',
        'Rp 21.062.500 - Rp 6.220.000',
        'Rp 14.842.500',
        $protocolSnapshot['balance_after_tx2'] === '14842500.00' ? 'LULUS' : 'GAGAL',
        'saldo setelah transaksi 2: ' . $protocolSnapshot['balance_after_tx2'],
    ],
    [
        'Akumulasi Emas (QC 2.2)',
        '14,850 + 3,200',
        '18,050 gr (2 nota holding)',
        $protocolSnapshot['holding_grams'] === '18.050' && $protocolSnapshot['holding_count'] === 2 ? 'LULUS' : 'GAGAL',
        'total holding: ' . $protocolSnapshot['holding_grams'] . ' gr dari ' . $protocolSnapshot['holding_count'] . ' nota',
    ],
    [
        'Rekonsiliasi Fase 3',
        '28.937.500 + 6.220.000',
        'Rp 35.157.500 modal belanja hulu',
        $protocolSnapshot['net_sum'] === '35157500.00' ? 'LULUS' : 'GAGAL',
        'net=' . $protocolSnapshot['net_sum'] . ' fee=' . $protocolSnapshot['fee_sum'] . ' kotor=' . $protocolSnapshot['gross_sum'],
    ],
];

$widths = [30, 26, 34, 8];

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
    'P2.11',
    'Seluruh ' . count($protocol) . ' baris Protokol QC Manual 2 berstatus LULUS',
    $protocolFailures === [],
    implode(', ', array_map(static fn (array $row): string => $row[0], $protocolFailures))
);

Qc::note('Suite ini menambah baris transaksi pada database uji (termasuk uji lewat HTTP).');
Qc::finish('qc_fase_2');
