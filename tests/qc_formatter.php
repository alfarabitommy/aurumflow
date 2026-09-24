<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| QC-3 — Formatter (Rupiah & Gramatur)
|--------------------------------------------------------------------------
| Mengunci format pada tingkat KARAKTER. Setiap penyimpangan sekecil apa pun
| (pemisah, spasi, posisi tanda minus) akan menggagalkan test — ini disengaja,
| karena Formatter adalah satu-satunya sumber kebenaran tampilan angka.
*/

require __DIR__ . '/bootstrap.php';

use AurumFlow\Helpers\Formatter;

Qc::suite('3 — Formatter (qc_formatter.php)');

// ---------------------------------------------------------------------------
Qc::section('Format Rupiah');

$rupiahCases = [
    [0, 0, 'Rp 0'],
    [500, 0, 'Rp 500'],
    [1234.5, 0, 'Rp 1.235'],
    [28937500, 0, 'Rp 28.937.500'],
    [1000000000, 0, 'Rp 1.000.000.000'],
    [-5000, 0, '-Rp 5.000'],
    [1234.567, 2, 'Rp 1.234,57'],
    [null, 0, 'Rp 0'],
    ['', 0, 'Rp 0'],
    ['1.234,50', 0, 'Rp 1.235'],
    ['Rp 28.937.500', 0, 'Rp 28.937.500'],
];

$index = 0;

foreach ($rupiahCases as [$input, $decimals, $expected]) {
    $index++;
    Qc::equals(
        '3.1.' . $index,
        'rupiah(' . var_export($input, true) . ', ' . $decimals . ') === ' . $expected,
        $expected,
        Formatter::rupiah($input, $decimals)
    );
}

// ---------------------------------------------------------------------------
Qc::section('Format gramatur');

$gramCases = [
    [0, 3, '0,000 gr'],
    [14.85, 3, '14,850 gr'],
    [14.8500, 3, '14,850 gr'],
    [1000, 3, '1.000,000 gr'],
    [0.001, 3, '0,001 gr'],
    [1234.5678, 3, '1.234,568 gr'],
    [-2.5, 3, '-2,500 gr'],
    [null, 3, '0,000 gr'],
    ['14,850 gr', 3, '14,850 gr'],
];

$index = 0;

foreach ($gramCases as [$input, $decimals, $expected]) {
    $index++;
    Qc::equals(
        '3.2.' . $index,
        'gram(' . var_export($input, true) . ', ' . $decimals . ') === ' . $expected,
        $expected,
        Formatter::gram($input, $decimals)
    );
}

// ---------------------------------------------------------------------------
Qc::section('Round-trip parse(format(x))');

$rupiahRoundTrip = [0, 500, 1234, 28937500, 1000000000, -5000];

foreach ($rupiahRoundTrip as $value) {
    Qc::closeTo(
        '3.3.' . $value,
        'parseRupiah(rupiah(' . $value . ')) kembali ke nilai semula',
        (float) $value,
        Formatter::parseRupiah(Formatter::rupiah($value)),
        0.01
    );
}

$gramRoundTrip = [0.0, 14.85, 1000.0, 0.001, 1234.5678];

foreach ($gramRoundTrip as $value) {
    Qc::closeTo(
        '3.4.' . $value,
        'parseGram(gram(' . $value . ')) kembali ke nilai semula',
        $value,
        Formatter::parseGram(Formatter::gram($value)),
        0.0005
    );
}

Qc::note('Round-trip rupiah hanya dijamin untuk nilai yang memang bulat pada '
    . 'jumlah desimal yang diminta (mis. rupiah(1234.5) sengaja menjadi "Rp 1.235").');

// ---------------------------------------------------------------------------
Qc::section('Independensi locale');

$rupiahBefore = Formatter::rupiah(28937500);
$gramBefore = Formatter::gram(14.85);

$attempted = [];

foreach (['de_DE.UTF-8', 'de_DE', 'id_ID.UTF-8', 'C'] as $locale) {
    $applied = setlocale(LC_ALL, $locale);

    if ($applied !== false) {
        $attempted[] = $applied;
    }
}

setlocale(LC_ALL, 'C');

Qc::equals('3.5', 'Output Rupiah tidak berubah setelah setlocale()', $rupiahBefore, Formatter::rupiah(28937500));
Qc::equals('3.6', 'Output gramatur tidak berubah setelah setlocale()', $gramBefore, Formatter::gram(14.85));

Qc::note('Locale yang berhasil diterapkan: ' . ($attempted === [] ? '(tidak ada)' : implode(', ', $attempted)));

// ---------------------------------------------------------------------------
Qc::section('Nilai default dan pemakaian separator eksplisit');

Qc::equals('3.7', 'rupiah() tanpa argumen desimal memakai 0 desimal', 'Rp 1.235', Formatter::rupiah(1234.5));
Qc::equals('3.8', 'gram() tanpa argumen desimal memakai 3 desimal', '14,850 gr', Formatter::gram(14.85));

$badNumberFormat = qc_scan_lines(static function (string $line): bool {
    if (!str_contains($line, 'number_format(')) {
        return false;
    }

    // Pemakaian sah wajib menyebut kedua separator secara eksplisit.
    return !str_contains($line, "','") || !str_contains($line, "'.'");
});

Qc::ok(
    '3.9',
    'Tidak ada number_format() tanpa separator eksplisit',
    $badNumberFormat === [],
    implode("\n", $badNumberFormat)
);

$localeUsage = qc_scan_lines(
    static fn (string $line): bool => preg_match('/\b(setlocale|localeconv)\s*\(/', $line) === 1
);

Qc::ok(
    '3.10',
    'Formatter tidak bergantung pada setlocale/localeconv',
    $localeUsage === [],
    implode("\n", $localeUsage)
);

// ---------------------------------------------------------------------------
Qc::section('Format tanggal operasi (Formatter::tanggalId)');

Qc::equals('3.11', 'tanggalId("2026-09-24") === "24/09/2026"', '24/09/2026', Formatter::tanggalId('2026-09-24'));
Qc::equals(
    '3.12',
    'tanggalId() dengan waktu memakai format d/m/Y H:i',
    '24/09/2026 15:30',
    Formatter::tanggalId('2026-09-24 15:30:00', true)
);
Qc::equals('3.13', 'tanggalId() mengembalikan "-" untuk string kosong', '-', Formatter::tanggalId(''));
Qc::equals('3.14', 'tanggalId() mengembalikan "-" untuk input tidak valid', '-', Formatter::tanggalId('bukan-tanggal'));

// Tanggal hanya-tanggal tidak boleh bergeser karena konversi timezone.
Qc::equals('3.15', 'Tanggal tidak bergeser oleh konversi timezone', '01/01/2026', Formatter::tanggalId('2026-01-01'));

Qc::equals(
    '3.16',
    'tanggalId() memakai timezone aplikasi',
    'Asia/Jakarta',
    AurumFlow\Config\App::timezone()
);

Qc::finish('qc_formatter');
