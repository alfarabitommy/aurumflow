<?php

declare(strict_types=1);

/*
 * Layar A — Edukasi & Pakta Integritas (Fase 5).
 *
 * Halaman ini satu-satunya yang dapat dibuka mitra sebelum ia punya kredensial.
 * Karena itu:
 *   - Isinya **edukasi** + **persetujuan** + **penetapan PIN** saja; tidak ada
 *     satu pun angka operasional (omzet, gramatur, modal, bagi hasil) di sini.
 *   - Teks klausul berasal dari `OnboardingService::waiverClause()` (satu
 *     pemilik bunyi hukum), dirender lewat `Security::e()`.
 *   - Peladen hanya mewajibkan centang + PIN sah. Penguncian tombol sampai
 *     kotak teks digulir adalah kenyamanan di peramban, bukan kontrol keamanan,
 *     sehingga tombol tetap `type="submit"` dan form tetap bekerja tanpa JS.
 *
 * Data dari InvestorOnboardingController: $error, $token, $full_name,
 * $phone_number, $waiver_clause, $waiver_version, $pin_length, $accepted.
 */

use AurumFlow\Config\App;
use AurumFlow\Helpers\Security;

$error = isset($error) && is_string($error) && $error !== '' ? $error : null;
$token = isset($token) && is_string($token) ? $token : '';
$fullName = isset($full_name) && is_string($full_name) ? $full_name : '';
$phoneNumber = isset($phone_number) && is_string($phone_number) ? $phone_number : '';
$waiverClause = isset($waiver_clause) && is_string($waiver_clause) ? $waiver_clause : '';
$waiverVersion = isset($waiver_version) && is_string($waiver_version) ? $waiver_version : '';
$pinLength = isset($pin_length) ? (int) $pin_length : 6;
$accepted = (bool) ($accepted ?? false);
?>
<?php if ($error !== null): ?>
    <div class="af-alert af-alert--error" role="alert"><?= Security::e($error) ?></div>
<?php endif; ?>

<div class="rounded-xl border border-gold-primary/20 bg-surface-800/60 p-4">
    <p class="text-xs uppercase tracking-[0.16em] text-muted">Selamat datang</p>
    <p class="mt-1 text-base font-semibold text-pure"><?= Security::e($fullName) ?></p>
    <p class="af-num text-xs text-muted"><?= Security::e($phoneNumber) ?></p>
</div>

<section class="mt-4">
    <h2 class="af-label">Ikhtisar Kerja Sama</h2>
    <ul class="mt-2 grid gap-2 text-xs leading-relaxed text-muted">
        <li>&bull; Siklus putaran kas harian <strong class="text-pure">T+1</strong>: emas dibeli malam, dijual ke toko pagi berikutnya.</li>
        <li>&bull; Objek penyertaan adalah <strong class="text-pure">komoditas emas padat</strong> yang benar-benar diperjualbelikan, bukan instrumen spekulasi.</li>
        <li>&bull; Imbal hasil dibagikan <strong class="text-pure">harian (pro-rata)</strong> hanya atas modal yang benar-benar berputar.</li>
        <li>&bull; Modal pokok dapat diajukan kembali dengan masa tunggu <strong class="text-pure">H-7 hari kerja</strong>.</li>
    </ul>
</section>

<form method="post" action="<?= Security::e(App::url('join')) ?>" class="mt-5 grid gap-4" novalidate
      x-data="{ submitting: false, opened: false }" @submit="submitting = true">
    <?= Security::csrfField() ?>
    <input type="hidden" name="token" value="<?= Security::e($token) ?>">

    <div>
        <span class="af-label">Pakta Integritas &amp; Pembebasan Risiko</span>
        <div
            class="mt-1 max-h-40 overflow-y-auto rounded-xl border border-surface-800 bg-obsidian-950/70 p-3 text-xs leading-relaxed text-muted"
            tabindex="0"
            @scroll="if ($event.target.scrollTop + $event.target.clientHeight >= $event.target.scrollHeight - 4) opened = true"
        ><?= Security::e($waiverClause) ?></div>
        <p class="mt-1 text-[0.68rem] text-muted">
            Teks klausul versi <?= Security::e($waiverVersion) ?> — sama dengan yang tercatat pada pengelola.
        </p>
    </div>

    <label class="flex items-start gap-3 rounded-xl border border-surface-800 bg-surface-800/50 p-3">
        <input
            type="checkbox"
            name="accept_waiver"
            value="1"
            class="mt-0.5 h-5 w-5 shrink-0 rounded border-slate-600 bg-obsidian-950"
            <?php if ($accepted): ?>checked<?php endif; ?>
            required
        >
        <span class="text-xs leading-relaxed text-muted">
            Saya memahami dan menyetujui seluruh Pakta Integritas &amp; Pembebasan Risiko di atas.
        </span>
    </label>

    <label class="block">
        <span class="af-label">Buat PIN <?= Security::e($pinLength) ?> Angka</span>
        <input
            type="password"
            name="pin"
            inputmode="numeric"
            pattern="[0-9]*"
            maxlength="<?= Security::e($pinLength) ?>"
            autocomplete="new-password"
            placeholder="mis. 246810"
            class="af-input af-input--sm"
            required
        >
    </label>

    <label class="block">
        <span class="af-label">Ulangi PIN</span>
        <input
            type="password"
            name="pin_confirm"
            inputmode="numeric"
            pattern="[0-9]*"
            maxlength="<?= Security::e($pinLength) ?>"
            autocomplete="new-password"
            placeholder="mis. 246810"
            class="af-input af-input--sm"
            required
        >
    </label>

    <button type="submit" class="af-btn af-btn--primary af-btn--block" :disabled="submitting">
        Aktifkan Portofolio Saya
    </button>
</form>

<p class="mt-4 text-center text-[0.68rem] leading-relaxed text-muted">
    Dengan menekan tombol di atas, waktu (WIB), alamat IP, dan jenis peramban Anda
    dicatat sebagai jejak persetujuan. Tautan undangan ini langsung tidak berlaku lagi.
</p>
