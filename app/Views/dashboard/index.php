<?php

declare(strict_types=1);

/*
 * Dashboard — halaman asap (smoke page).
 *
 * Tujuannya bukan menampilkan data bisnis, melainkan membuktikan bahwa
 * session, koneksi database, model, view, dan formatter bekerja bersama di
 * atas skema ERD yang sesungguhnya. Sejak Fase 2, operator mendapat pintu
 * masuk ke meja kasir hulu; dasbor "blind" untuk investor dibangun di Fase 5.
 *
 * Isi dan nilai yang ditampilkan SENGAJA tidak berubah dari Fase 1 karena
 * diuji assertion QC HTTP (`qc_routing`): nomor telepon, label peran, sampel
 * formatter, dan nilai `system_settings` wajib tetap tercetak.
 *
 * Sejak Fase 7 identitas pengguna datang dari BaseController sebagai `$auth`
 * (satu pemilik: `startUserSession()`), bukan dari array `user` yang disusun
 * controller — sehingga layar ini tidak punya sumber identitas kedua.
 *
 * Sejak Fase 8 halaman ini dirender di dalam kerangka 2-tier
 * `layouts/operator.php` (dipilih `DashboardController::LAYOUT`), sehingga
 * header/tab-nya identik dengan layar operator lain dan grid aksi cepat di
 * bawah ini hanya mengulang empat tab operasional — lihat
 * `plan/Plan_Unify_UI_AurumFlow.md` (C5).
 */

use AurumFlow\Config\App;
use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Formatter;
use AurumFlow\Helpers\Security;

$auth = is_array($auth ?? null) ? $auth : [];
$settings = is_array($settings ?? null) ? $settings : [];
$settingLabels = is_array($settingLabels ?? null) ? $settingLabels : [];
$samples = is_array($samples ?? null) ? $samples : [];
$activeUserCount = isset($activeUserCount) ? (int) $activeUserCount : 0;

$sampleLabels = [
    'rupiah_besar'   => 'Rupiah — nilai besar',
    'rupiah_nol'     => 'Rupiah — nol',
    'rupiah_negatif' => 'Rupiah — negatif',
    'rupiah_desimal' => 'Rupiah — 2 desimal',
    'gram_normal'    => 'Gramatur — 3 desimal',
    'gram_bulat'     => 'Gramatur — ribuan',
    'gram_negatif'   => 'Gramatur — negatif',
];

$isOperator = ($auth['role'] ?? '') === Constants::ROLE_OPERATOR;
?>
<section class="af-card">
    <div class="af-glow" aria-hidden="true"></div>

    <h1 class="af-card__title">Selamat datang, <?= Security::e($auth['name'] ?? '') ?></h1>
    <p class="af-card__subtitle">
        Ringkasan operasional hari ini. Gunakan tab di baris atas untuk berpindah
        antar layar pengelola — kartu di bawah memuat parameter sistem yang sedang
        berlaku dan contoh format angka yang dipakai seluruh modul.
    </p>

    <?php if ($isOperator): ?>
        <!-- Urutan & label grid ini 1:1 dengan empat tab operasional pada
             layouts/operator.php (tab pertama, Dashboard, adalah halaman ini). -->
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <a class="af-btn af-btn--primary"
               href="<?= Security::e(App::url('operator/teller')) ?>">Kasir</a>
            <a class="af-btn af-btn--ghost"
               href="<?= Security::e(App::url('operator/settlement')) ?>">Rekonsiliasi</a>
            <a class="af-btn af-btn--ghost"
               href="<?= Security::e(App::url('operator/capital')) ?>">Kelola Modal</a>
            <a class="af-btn af-btn--ghost"
               href="<?= Security::e(App::url('operator/kasir')) ?>">Kelola Kasir</a>
        </div>
    <?php endif; ?>

    <dl class="af-facts mt-5">
        <div>
            <dt>Nomor telepon</dt>
            <dd><?= Security::e($auth['phone'] ?? '') ?></dd>
        </div>
        <div>
            <dt>Peran</dt>
            <dd><?= Security::e($auth['role_label'] ?? '') ?></dd>
        </div>
        <div>
            <dt>Pengguna aktif</dt>
            <dd><?= Security::e($activeUserCount) ?></dd>
        </div>
        <div>
            <dt>Tanggal operasi</dt>
            <dd><?= Security::e($tanggalOperasi ?? '-') ?></dd>
        </div>
        <div>
            <dt>Setting sistem</dt>
            <dd><?= Security::e(count($settings)) ?></dd>
        </div>
    </dl>
</section>

<section class="af-card">
    <h2 class="af-card__title">Uji Formatter</h2>
    <p class="af-card__subtitle">
        Hasil di bawah ini dihasilkan oleh <code>App\Helpers\Formatter</code>.
        Format ini dibekukan dan wajib dipakai seluruh modul berikutnya.
    </p>

    <table class="af-table mt-3">
        <thead>
            <tr>
                <th scope="col">Kasus</th>
                <th scope="col">Hasil</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($sampleLabels as $key => $label): ?>
            <tr>
                <td class="text-muted"><?= Security::e($label) ?></td>
                <td class="af-num text-pure"><?= Security::e($samples[$key] ?? '-') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="af-card">
    <h2 class="af-card__title">Konfigurasi Terbaca dari <code>system_settings</code></h2>
    <p class="af-card__subtitle">
        Dibaca melalui model <code>SystemSetting</code> — bukti bahwa seed ERD
        benar-benar dapat dibaca aplikasi.
    </p>

    <table class="af-table mt-3">
        <thead>
            <tr>
                <th scope="col">Setting</th>
                <th scope="col">Nilai</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($settingLabels as $key => $label): ?>
            <?php if (!array_key_exists($key, $settings)) { continue; } ?>
            <tr>
                <td class="text-muted"><?= Security::e($label) ?></td>
                <td class="af-num text-pure"><?= Security::e($settings[$key]) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="af-card">
    <h2 class="af-card__title">Parameter jasa bakar (dipakai meja kasir)</h2>
    <p class="af-card__subtitle">
        Dibaca satu per satu dari <code>system_settings</code>, bukan dari
        daftar lengkap — membuktikan <code>SystemSetting::get()</code> bekerja.
    </p>
    <p class="mt-3 text-sm text-muted">
        Jasa bakar flat: <span class="af-num text-gold-primary"><?= Security::e($burnFee ?? '-') ?></span>
        per kedatangan, dengan porsi upah langsung pengelola
        <span class="af-num text-pure"><?= Security::e($operatorCut ?? '-') ?></span>.
    </p>
    <p class="mt-2 text-sm text-muted">
        Contoh nilai tebus: <span class="af-num text-pure"><?= Security::e(Formatter::rupiah(28937500)) ?></span>
        untuk <span class="af-num text-pure"><?= Security::e(Formatter::gram(14.85)) ?></span>.
    </p>
</section>
