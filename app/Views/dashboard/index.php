<?php

declare(strict_types=1);

/*
 * Dashboard Fase 1 — halaman asap.
 *
 * Tujuannya bukan menampilkan data bisnis, melainkan membuktikan bahwa
 * session, koneksi database, model, view, dan formatter bekerja bersama
 * di atas skema ERD yang sesungguhnya.
 *
 * Dashboard "blind" untuk investor dibangun pada Fase 5.
 */

use AurumFlow\Helpers\Formatter;
use AurumFlow\Helpers\Security;

$user = is_array($user ?? null) ? $user : [];
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
?>
<section class="card">
    <h1 class="card__title">Selamat datang, <?= Security::e($user['name'] ?? '') ?></h1>
    <p class="card__subtitle">
        Fase 1 (Fondasi Sistem, Basis Data, &amp; Arsitektur Inti) sudah aktif.
        Halaman ini membuktikan bahwa session, skema ERD, model, dan formatter
        bekerja bersama.
    </p>

    <dl class="facts">
        <div class="facts__item">
            <dt>Nomor telepon</dt>
            <dd><?= Security::e($user['phone'] ?? '') ?></dd>
        </div>
        <div class="facts__item">
            <dt>Peran</dt>
            <dd><?= Security::e($user['role_label'] ?? '') ?></dd>
        </div>
        <div class="facts__item">
            <dt>Pengguna aktif</dt>
            <dd><?= Security::e($activeUserCount) ?></dd>
        </div>
        <div class="facts__item">
            <dt>Setting sistem</dt>
            <dd><?= Security::e(count($settings)) ?></dd>
        </div>
    </dl>
</section>

<section class="card">
    <h2 class="card__title">Uji Formatter</h2>
    <p class="card__subtitle">
        Hasil di bawah ini dihasilkan oleh <code>App\Helpers\Formatter</code>.
        Format ini dibekukan dan wajib dipakai seluruh modul berikutnya.
    </p>

    <table class="table">
        <thead>
            <tr>
                <th scope="col">Kasus</th>
                <th scope="col">Hasil</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($sampleLabels as $key => $label): ?>
            <tr>
                <td><?= Security::e($label) ?></td>
                <td><strong><?= Security::e($samples[$key] ?? '-') ?></strong></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="card">
    <h2 class="card__title">Konfigurasi Terbaca dari <code>system_settings</code></h2>
    <p class="card__subtitle">
        Dibaca melalui model <code>SystemSetting</code> — bukti bahwa seed ERD
        benar-benar dapat dibaca aplikasi.
    </p>

    <table class="table">
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
                <td><?= Security::e($label) ?></td>
                <td><code><?= Security::e($settings[$key]) ?></code></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="card card--muted">
    <h2 class="card__title">Contoh parameter jasa bakar (pratinjau Fase 2)</h2>
    <p class="card__subtitle">
        Dibaca satu per satu dari <code>system_settings</code>, bukan dari
        daftar lengkap — membuktikan <code>SystemSetting::get()</code> bekerja.
    </p>
    <p>
        Jasa bakar flat: <?= Security::e($burnFee ?? '-') ?> per kedatangan,
        dengan porsi upah langsung pengelola <?= Security::e($operatorCut ?? '-') ?>.
        Belum ada transaksi pada Fase 1.
    </p>
    <p>
        Contoh nilai tebus: <?= Security::e(Formatter::rupiah(28937500)) ?>
        untuk <?= Security::e(Formatter::gram(14.85)) ?>.
    </p>
</section>
