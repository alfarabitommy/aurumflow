<?php

declare(strict_types=1);

/*
 * Laporan portofolio ramah awam (Fase 5, PRD § 6 Modul 4).
 *
 * Sumber angka: `InvestorService::dashboard()` — pintu yang sama dengan dasbor,
 * sehingga laporan tidak dapat memuat apa pun yang tidak boleh dilihat mitra.
 * Kolomnya sengaja memakai bahasa sehari-hari ("Modal Diputar", "Imbal Hasil
 * Masuk") tanpa istilah akuntansi.
 *
 * Data dari ExportController: $portfolio, $mitra, $printedLabel.
 */

use AurumFlow\Helpers\Security;

$portfolio = is_array($portfolio ?? null) ? $portfolio : [];
$mitra = is_array($mitra ?? null) ? $mitra : [];
$printedLabel = isset($printedLabel) && is_string($printedLabel) ? $printedLabel : '';
$history = is_array($portfolio['history'] ?? null) ? $portfolio['history'] : [];
?>
<section class="af-report-card rounded-2xl border border-gold-primary/20 bg-surface-900/90 p-5">
    <p class="text-xs font-bold tracking-[0.22em] text-gold-primary">AURUMFLOW</p>
    <h1 class="mt-1 text-lg font-semibold text-pure">Laporan Portofolio Mitra</h1>
    <p class="mt-1 text-xs text-muted">
        Nama: <span class="text-pure"><?= Security::e($mitra['name'] ?? '') ?></span>
        &middot; Dicetak: <?= Security::e($printedLabel) ?>
    </p>

    <table class="af-table mt-4">
        <thead>
            <tr>
                <th scope="col">Tanggal Putaran</th>
                <th scope="col">Modal Diputar</th>
                <th scope="col">Imbal Hasil Masuk</th>
                <th scope="col">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($history === []): ?>
                <tr>
                    <td colspan="4">Belum ada putaran modal yang tercatat.</td>
                </tr>
            <?php endif; ?>

            <?php foreach ($history as $row): ?>
                <tr>
                    <td><?= Security::e($row['date_label'] ?? '') ?></td>
                    <td class="af-num"><?= Security::e($row['utilized_label'] ?? '') ?></td>
                    <td class="af-num text-emerald-cash"><?= Security::e($row['profit_label'] ?? '') ?></td>
                    <td>Terverifikasi</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="af-report-card mt-4 rounded-2xl border border-gold-primary/20 bg-surface-900/90 p-5">
    <h2 class="af-label">Ringkasan</h2>
    <dl class="af-facts mt-2">
        <div>
            <dt>Total Modal Dititipkan</dt>
            <dd class="af-num text-gold-primary"><?= Security::e($portfolio['capital_label'] ?? '-') ?></dd>
        </div>
        <div>
            <dt>Total Keuntungan Diterima</dt>
            <dd class="af-num text-emerald-cash"><?= Security::e($portfolio['all_time_profit_label'] ?? '-') ?></dd>
        </div>
        <div>
            <dt>Modal Sedang Berputar</dt>
            <dd class="af-num"><?= Security::e($portfolio['rotating_label'] ?? '-') ?></dd>
        </div>
        <div>
            <dt>Sisa Saldo Kas Mengendap</dt>
            <dd class="af-num"><?= Security::e($portfolio['reserve_label'] ?? '-') ?></dd>
        </div>
    </dl>

    <p class="mt-3 text-[0.68rem] leading-relaxed text-muted">
        &ldquo;Modal Diputar&rdquo; adalah modal Anda yang benar-benar dipakai untuk membeli emas
        pada putaran hari itu; sisanya menjadi kas cadangan yang tidak menanggung risiko putaran.
        Imbal hasil dihitung hanya atas modal yang berputar.
    </p>
</section>
