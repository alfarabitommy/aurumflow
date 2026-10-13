<?php

declare(strict_types=1);

/*
 * Layout laporan ramah cetak (Fase 5).
 *
 * Tujuan: hasil cetak/kertas (dan "Simpan sebagai PDF" peramban) yang bersih —
 * tanpa navigasi aplikasi, tanpa tombol selain aksi cetak/unduh yang memang
 * disembunyikan saat mencetak (`@media print`).
 *
 * Variabel dari BaseController::render(): $content, $title, $app_name,
 * $portfolio, $mitra, $printedLabel.
 */

use AurumFlow\Config\App;
use AurumFlow\Helpers\Security;

$mitra = is_array($mitra ?? null) ? $mitra : [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#07080B">
    <title><?= Security::e($title ?? '') ?> — <?= Security::e($app_name ?? 'AurumFlow') ?></title>
    <link rel="stylesheet" href="<?= Security::e(App::url('assets/css/app.min.css')) ?>">
    <link rel="manifest" href="<?= Security::e(App::url('manifest.json')) ?>">
    <style>
        /* Gaya khusus cetak: latar putih, teks hitam, tanpa navigasi.
           Ditulis inline (bukan lewat Tailwind) supaya tidak menambah kelas
           baru ke bundel CSS hanya untuk kebutuhan kertas. */
        @media print {
            .af-no-print { display: none !important; }
            body { background: #ffffff !important; color: #000000 !important; }
            .af-report-card { border-color: #999999 !important; box-shadow: none !important; }
        }
    </style>
</head>
<body class="bg-obsidian-950 text-pure" data-app-base="<?= Security::e(App::url()) ?>">
<main class="mx-auto max-w-2xl px-4 py-6">
    <div class="af-no-print mb-5 flex flex-wrap items-center justify-between gap-3">
        <a class="af-btn af-btn--ghost px-3 text-xs"
           href="<?= Security::e(App::url('investor/dashboard')) ?>">&larr; Kembali</a>

        <div class="flex gap-2">
            <button type="button" class="af-btn af-btn--ghost px-3 text-xs"
                    onclick="window.print()">Cetak / Simpan PDF</button>
            <a class="af-btn af-btn--primary px-3 text-xs"
               href="<?= Security::e(App::url('investor/report/csv')) ?>">Unduh CSV</a>
        </div>
    </div>

    <?= $content ?>
</main>
</body>
</html>
