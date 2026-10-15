<?php

declare(strict_types=1);

/*
 * Meja Kasir Hulu — "Quick Teller Mode" (dokumen UI/UX "Layar B").
 *
 * Prinsip yang dipegang:
 *   - Kontras ekstrem + angka monospace tabular: dibaca di ruangan gelap
 *     berasap, dan digit tidak bergeser saat nilai berubah.
 *   - Fat-finger friendly: seluruh input dan tombol >= 52 px.
 *   - Konfirmasi lewat GESER, bukan klik, supaya tidak ada pembayaran
 *     karena tersentuh tidak sengaja.
 *   - Nilai pada pratinjau hanya cermin sisi klien; server selalu menghitung
 *     ulang dan tidak mempercayai nominal apa pun dari form ini.
 *   - Kontrol form (input/select) TIDAK PERNAH `:disabled`. Peramban
 *     mengeluarkan kontrol `disabled` dari data form, sehingga berat/harga
 *     (dan berkas) tidak pernah sampai ke peladen dan peladen menjawab 422
 *     "wajib diisi". Selama pengiriman dipakai `:readonly="submitting"` +
 *     kelas redup; `:disabled` hanya untuk <button> (tanpa data form).
 *   - Modal "Terima Dana Talangan" (Layar B2, Fase 6) memakai KOMPONEN YANG
 *     SUDAH ADA (`tellerForm`) — bukan `Alpine.data()` baru — supaya aset
 *     `app.js` yang di-cache peramban tidak dapat membuat modalnya hilang.
 *     Kedua form (bayar tunai & talangan) berada di dalam satu akar `x-data`.
 *
 * Data dari TellerController: $register, $burnFee, $holding, $recentRows,
 * $bridge, $bridgeInput, $bridgeError, $bridgeOpen, $formToken, $input, $error.
 */

use AurumFlow\Config\App;
use AurumFlow\Helpers\Security;

$register = is_array($register ?? null) ? $register : [];
$burnFee = is_array($burnFee ?? null) ? $burnFee : [];
$recentRows = is_array($recentRows ?? null) ? $recentRows : [];
$input = is_array($input ?? null) ? $input : [];
$error = isset($error) && is_string($error) && $error !== '' ? $error : null;
$formToken = isset($formToken) && is_string($formToken) ? $formToken : '';

$weightValue = isset($input['weight']) && is_string($input['weight']) ? $input['weight'] : '';
$priceValue = isset($input['price']) && is_string($input['price']) ? $input['price'] : '';
$aliasValue = isset($input['farmer_alias']) && is_string($input['farmer_alias']) ? $input['farmer_alias'] : '';
$applyFee = (bool) ($input['burn_fee_enabled'] ?? true);

// Dana talangan (Fase 6): daftar kewajiban aktif + isian modal yang dikembalikan
// peladen pada jalur gagal validasi (422).
$bridge = is_array($bridge ?? null) ? $bridge : [];
$bridgeRows = is_array($bridge['rows'] ?? null) ? $bridge['rows'] : [];
$bridgeCount = isset($bridge['count']) ? (int) $bridge['count'] : 0;
$bridgeInput = is_array($bridgeInput ?? null) ? $bridgeInput : [];
$bridgeError = isset($bridgeError) && is_string($bridgeError) && $bridgeError !== '' ? $bridgeError : null;
$bridgeOpen = (bool) ($bridgeOpen ?? false);

$bridgeLenderValue = isset($bridgeInput['lender_name']) && is_string($bridgeInput['lender_name'])
    ? $bridgeInput['lender_name']
    : '';
$bridgePrincipalValue = isset($bridgeInput['principal_amount']) && is_string($bridgeInput['principal_amount'])
    ? $bridgeInput['principal_amount']
    : '';
$bridgeRateValue = isset($bridgeInput['fee_rate_pct']) && is_string($bridgeInput['fee_rate_pct'])
    ? $bridgeInput['fee_rate_pct']
    : '';
$bridgeFixedValue = isset($bridgeInput['fee_fixed']) && is_string($bridgeInput['fee_fixed'])
    ? $bridgeInput['fee_fixed']
    : '';
$bridgeNotesValue = isset($bridgeInput['notes']) && is_string($bridgeInput['notes'])
    ? $bridgeInput['notes']
    : '';

// Bentuk fee yang dicentang: nilai enum dari peladen, bukan teks kiriman klien
// (hanya `pct`/`fixed` yang pernah dicetak; sisanya jatuh ke `fixed`).
$bridgeFeeType = isset($bridgeInput['fee_type']) && (string) $bridgeInput['fee_type'] === 'pct' ? 'pct' : 'fixed';
$pctChecked = $bridgeFeeType === 'pct' ? 'checked' : '';
$fixedChecked = $bridgeFeeType === 'fixed' ? 'checked' : '';

// Konfigurasi Alpine.js disiapkan TellerController (integer & boolean dari
// kode kita sendiri). Dicetak lewat Security::e() seperti output dinamis lain.
$alpineConfig = isset($alpineConfig) && is_string($alpineConfig) ? $alpineConfig : '{}';

// Status awal kotak centang jasa bakar. Nilainya konstanta, tetapi tetap
// dirender lewat Security::e() supaya aturan "tidak ada output dinamis tanpa
// escaping" berlaku seragam di seluruh view.
$checkedAttribute = $applyFee ? 'checked' : '';
?>
<div x-data="tellerForm(<?= Security::e($alpineConfig) ?>)">
    <?php if ($error !== null): ?>
        <div class="af-alert af-alert--error" role="alert"><?= Security::e($error) ?></div>
    <?php endif; ?>

    <section class="af-card">
        <div class="af-glow" aria-hidden="true"></div>

        <h1 class="af-card__title">Timbang &amp; Bayar Tunai</h1>
        <p class="af-card__subtitle">Isi berat kering, lalu geser tombol untuk mencatat pembayaran.</p>

        <form
            x-ref="form"
            method="post"
            action="<?= Security::e(App::url('operator/teller')) ?>"
            class="mt-4 grid gap-4"
            novalidate
            @keydown.enter.prevent
            @submit="submitting = true"
        >
            <?= Security::csrfField() ?>
            <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">

            <label class="block">
                <span class="af-label">Inisial Petani (opsional)</span>
                <input
                    type="text"
                    name="farmer_alias"
                    value="<?= Security::e($aliasValue) ?>"
                    maxlength="50"
                    autocomplete="off"
                    placeholder="P-04 / Anto"
                    class="af-input af-input--sm"
                    :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                    :readonly="submitting"
                >
            </label>

            <label class="block">
                <span class="af-label">Berat Kering Timbangan (gram)</span>
                <input
                    x-ref="weight"
                    x-model.fill="weight"
                    type="text"
                    name="dry_weight"
                    value="<?= Security::e($weightValue) ?>"
                    inputmode="decimal"
                    autocomplete="off"
                    placeholder="mis. 14,850"
                    class="af-input"
                    autofocus
                    :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                    :readonly="submitting"
                >
                <span class="mt-1 block text-[0.7rem] text-muted">
                    Satu pemisah saja (koma = desimal). Contoh: <span class="af-num">14,850</span>
                </span>
            </label>

            <label class="block">
                <span class="af-label">Harga Tebus / Gram</span>
                <input
                    x-ref="price"
                    x-model.fill="price"
                    type="text"
                    name="buy_price_per_g"
                    value="<?= Security::e($priceValue) ?>"
                    inputmode="numeric"
                    autocomplete="off"
                    placeholder="mis. 1.950.000"
                    class="af-input"
                    :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                    :readonly="submitting"
                >
            </label>

            <label class="flex min-h-touch cursor-pointer items-center gap-3 rounded-xl border border-surface-800 bg-surface-800 px-4">
                <input
                    x-ref="burnFee"
                    x-model="applyFee"
                    type="checkbox"
                    name="burn_fee_enabled"
                    value="1"
                    class="h-6 w-6 accent-[#D4AF37]"
                    :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                    :readonly="submitting"
                    <?= Security::e($checkedAttribute) ?>
                >
                <span class="text-sm">
                    Potong jasa bakar flat
                    <strong class="af-num text-gold-primary"><?= Security::e($burnFee['fee_label'] ?? '-') ?></strong>
                </span>
            </label>

            <p class="-mt-2 text-[0.7rem] leading-relaxed text-muted">
                Bagi hasil jasa bakar: upah pengelola
                <span class="af-num"><?= Security::e($burnFee['operator_cut_label'] ?? '-') ?></span>
                &middot; penggantian kowi/gas
                <span class="af-num"><?= Security::e($burnFee['consumable_cut_label'] ?? '-') ?></span>
            </p>

            <!-- Pratinjau langsung (Alpine.js). Server menghitung ulang. -->
            <dl class="mt-1 grid gap-1.5 text-sm">
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-muted">Nilai tebus kotor</dt>
                    <dd class="af-num text-pure" x-text="grossLabel">Rp 0</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-muted">Jasa bakar flat</dt>
                    <dd class="af-num text-muted" x-text="feeLabel">- Rp 0</dd>
                </div>
            </dl>

            <div class="rounded-xl border border-emerald-cash/30 bg-emerald-cash/5 p-4">
                <p class="text-[0.68rem] font-semibold uppercase tracking-[0.16em] text-muted">
                    Total kas bersih keluar
                </p>
                <p class="af-num mt-1 text-3xl font-bold text-emerald-cash" x-text="netLabel">Rp 0</p>
                <p class="mt-1 text-xs text-muted">
                    Setara berat <span class="af-num" x-text="weightLabel">0,000 gr</span>
                </p>
            </div>

            <p x-cloak x-show="insufficient" class="text-xs font-semibold text-red-300">
                Saldo kas meja tidak cukup untuk pembayaran ini.
            </p>

            <!-- Komponen geser (swipe-to-confirm). Rasio emas pada border. -->
            <div
                x-ref="track"
                class="relative h-16 select-none overflow-hidden rounded-xl border border-surface-800 bg-surface-800"
                :class="canPay ? 'border-gold-primary/30' : 'opacity-70'"
            >
                <div
                    class="absolute inset-y-0 left-0 bg-emerald-cash/15 transition-[width] duration-75"
                    :style="`width: ${inset + 56}px`"
                    aria-hidden="true"
                ></div>

                <p
                    class="pointer-events-none absolute inset-0 flex items-center justify-center text-[0.7rem] font-semibold uppercase tracking-[0.2em] text-muted"
                    x-show="!confirmed"
                >
                    Geser untuk bayar tunai &rarr;
                </p>

                <p
                    class="pointer-events-none absolute inset-0 flex items-center justify-center text-[0.7rem] font-semibold uppercase tracking-[0.2em] text-emerald-cash"
                    x-cloak
                    x-show="confirmed"
                >
                    Memproses&hellip;
                </p>

                <button
                    type="button"
                    class="absolute left-1 top-1 flex h-14 w-14 items-center justify-center rounded-lg text-obsidian-950 shadow-lg transition-colors"
                    :class="canPay ? 'bg-emerald-cash cursor-grab active:cursor-grabbing' : 'bg-surface-900 text-muted cursor-not-allowed'"
                    :style="`transform: translateX(${inset}px)`"
                    style="touch-action: none"
                    @pointerdown="startDrag($event)"
                    @pointermove="dragMove($event)"
                    @pointerup="endDrag()"
                    @pointercancel="endDrag()"
                    @pointerleave="endDrag()"
                    @keydown.arrowright="inset = maxInset()"
                    @keydown.arrowleft="inset = 0"
                    @keydown.enter="confirm()"
                    aria-label="Geser untuk konfirmasi pembayaran tunai"
                >
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                              d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                    </svg>
                </button>
            </div>

            <!--
                Jalur cadangan: tampil hanya bila Alpine.js tidak aktif (mis. JS
                dimatikan). Saat Alpine hidup, tombol ini disembunyikan sehingga
                satu-satunya cara membayar adalah menggeser.
            -->
            <button
                x-data="{}"
                x-show="false"
                type="submit"
                class="af-btn af-btn--primary af-btn--block"
                :disabled="!canPay"
            >
                Bayar Tunai &amp; Catat
            </button>
        </form>
    </section>

    <!--
        Dana talangan (Fase 6 — Layar B2). Pokok BUKAN pendapatan: ia hanya
        melintas di kas meja dan dikembalikan pada tutup buku berikutnya bersama
        fee, yang dibebankan hanya pada hak pengelola.
    -->
    <section class="af-card mt-5">
        <h2 class="af-card__title">Dana Talangan</h2>
        <p class="af-card__subtitle">
            Kas meja habis? Catat pinjaman tunai dari pihak luar (tanpa akun).
        </p>

        <?php if ($bridgeCount === 0): ?>
            <p class="mt-3 text-sm text-muted">Tidak ada dana talangan berjalan.</p>
        <?php else: ?>
            <ul class="mt-3 divide-y divide-surface-800">
                <?php foreach ($bridgeRows as $row): ?>
                    <li class="py-2.5">
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="truncate text-sm font-semibold text-pure">
                                <?= Security::e($row['lender_name'] ?? '') ?>
                            </p>
                            <p class="af-num shrink-0 text-sm font-semibold text-gold-primary">
                                <?= Security::e($row['total_label'] ?? '-') ?>
                            </p>
                        </div>
                        <p class="text-xs text-muted">
                            Pokok <span class="af-num"><?= Security::e($row['principal_label'] ?? '-') ?></span>
                            &middot; fee <span class="af-num"><?= Security::e($row['fee_label'] ?? '-') ?></span>
                            &middot; <?= Security::e($row['jenis_label'] ?? '') ?>
                        </p>
                        <p class="text-[0.7rem] text-muted">
                            Diterima <?= Security::e($row['received_label'] ?? '-') ?> WIB
                            &middot; kembali pada tutup buku berikutnya
                        </p>
                    </li>
                <?php endforeach; ?>
            </ul>

            <p class="mt-3 flex items-baseline justify-between gap-3 border-t border-surface-800 pt-3 text-sm">
                <span class="text-muted">Total kembali dari kas meja</span>
                <span class="af-num font-semibold text-emerald-cash">
                    <?= Security::e($bridge['total_label'] ?? '-') ?>
                </span>
            </p>
        <?php endif; ?>

        <button
            type="button"
            class="af-btn af-btn--primary af-btn--block mt-4"
            @click="bridgeOpen = true"
        >
            Terima Dana Talangan
        </button>
    </section>

    <section class="af-card mt-5">
        <h2 class="af-card__title">3 Transaksi Terakhir</h2>
        <p class="af-card__subtitle">Riwayat pada pos <?= Security::e($register['name'] ?? 'kas meja') ?>.</p>

        <?php if ($recentRows === []): ?>
            <p class="mt-3 text-sm text-muted">Belum ada transaksi pada meja ini.</p>
        <?php else: ?>
            <ul class="mt-3 divide-y divide-surface-800">
                <?php foreach ($recentRows as $row): ?>
                    <li class="flex items-center justify-between gap-3 py-2.5">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-pure">
                                <?= Security::e($row['alias'] ?? '') ?>
                            </p>
                            <p class="text-xs text-muted">
                                <?= Security::e($row['waktu'] ?? '-') ?> WIB
                                &middot; <?= Security::e($row['status_label'] ?? '') ?>
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="af-num text-sm font-semibold text-gold-primary">
                                <?= Security::e($row['gram_label'] ?? '-') ?>
                            </p>
                            <p class="af-num text-xs text-emerald-cash">
                                <?= Security::e($row['net_label'] ?? '-') ?>
                            </p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <!-- Modal penerimaan dana talangan. `x-cloak` menjaganya tetap tersembunyi
         bila Alpine gagal dimuat (tidak menyisakan kotak hantu). -->
    <div
        x-cloak
        x-show="bridgeOpen"
        class="fixed inset-0 z-30 overflow-y-auto bg-obsidian-950/85 px-4 py-6 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        aria-labelledby="af-bridge-title"
        @click.self="bridgeOpen = false"
    >
        <div class="af-card mx-auto mt-6 max-w-md">
            <div class="flex items-start justify-between gap-3">
                <h2 id="af-bridge-title" class="af-card__title">Terima Dana Talangan</h2>
                <button
                    type="button"
                    class="af-btn af-btn--ghost px-3 text-xs"
                    @click="bridgeOpen = false"
                >
                    Tutup
                </button>
            </div>
            <p class="af-card__subtitle">
                Pokok talangan <strong>bukan</strong> pendapatan; ia kembali pada tutup buku berikutnya bersama fee.
            </p>

            <?php if ($bridgeError !== null): ?>
                <div class="af-alert af-alert--error mt-3" role="alert"><?= Security::e($bridgeError) ?></div>
            <?php endif; ?>

            <form
                method="post"
                action="<?= Security::e(App::url('operator/teller/talangan')) ?>"
                class="mt-4 grid gap-4"
                novalidate
                @submit="bridgeSubmitting = true"
            >
                <?= Security::csrfField() ?>
                <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">

                <label class="block">
                    <span class="af-label">Nama / Alias Pemberi Dana</span>
                    <input
                        type="text"
                        name="lender_name"
                        x-model.fill="bridgeLender"
                        value="<?= Security::e($bridgeLenderValue) ?>"
                        maxlength="50"
                        autocomplete="off"
                        placeholder="mis. Pak Haji Daud"
                        class="af-input"
                        :class="{ 'opacity-70 cursor-not-allowed': bridgeSubmitting }"
                        :readonly="bridgeSubmitting"
                    >
                </label>

                <label class="block">
                    <span class="af-label">Pokok Dana yang Diterima (Rp)</span>
                    <input
                        type="text"
                        name="principal_amount"
                        x-model.fill="bridgePrincipal"
                        value="<?= Security::e($bridgePrincipalValue) ?>"
                        inputmode="numeric"
                        autocomplete="off"
                        placeholder="mis. 10.000.000"
                        class="af-input"
                        :class="{ 'opacity-70 cursor-not-allowed': bridgeSubmitting }"
                        :readonly="bridgeSubmitting"
                    >
                </label>

                <fieldset class="rounded-xl border border-surface-800 bg-surface-800 px-4 py-3">
                    <legend class="af-label px-1">Bentuk Fee</legend>

                    <div class="grid gap-2 sm:grid-cols-2">
                        <!--
                            Dua radio + dua isian: keduanya SELALU ikut terkirim
                            (tidak pernah `:disabled`), dan peladen memilih
                            menurut `fee_type`.
                        -->
                        <label class="flex min-h-touch cursor-pointer items-center gap-3">
                            <input
                                type="radio"
                                name="fee_type"
                                value="fixed"
                                x-model="bridgeFeeType"
                                class="h-5 w-5 accent-[#D4AF37]"
                                :class="{ 'opacity-70 cursor-not-allowed': bridgeSubmitting }"
                                :readonly="bridgeSubmitting"
                                <?= Security::e($fixedChecked) ?>
                            >
                            <span class="text-sm">Nominal (Rp)</span>
                        </label>

                        <label class="flex min-h-touch cursor-pointer items-center gap-3">
                            <input
                                type="radio"
                                name="fee_type"
                                value="pct"
                                x-model="bridgeFeeType"
                                class="h-5 w-5 accent-[#D4AF37]"
                                :class="{ 'opacity-70 cursor-not-allowed': bridgeSubmitting }"
                                :readonly="bridgeSubmitting"
                                <?= Security::e($pctChecked) ?>
                            >
                            <span class="text-sm">Persen (%)</span>
                        </label>
                    </div>

                    <label class="mt-3 block">
                        <span class="af-label">Fee Nominal (Rp)</span>
                        <input
                            type="text"
                            name="fee_fixed"
                            x-model.fill="bridgeFixed"
                            value="<?= Security::e($bridgeFixedValue) ?>"
                            inputmode="numeric"
                            autocomplete="off"
                            placeholder="mis. 100.000"
                            class="af-input"
                            :class="{ 'opacity-70 cursor-not-allowed': bridgeSubmitting }"
                            :readonly="bridgeSubmitting"
                        >
                    </label>

                    <label class="mt-3 block">
                        <span class="af-label">Fee Persen (%)</span>
                        <input
                            type="text"
                            name="fee_rate_pct"
                            x-model.fill="bridgeRate"
                            value="<?= Security::e($bridgeRateValue) ?>"
                            inputmode="decimal"
                            autocomplete="off"
                            placeholder="mis. 1,00"
                            class="af-input"
                            :class="{ 'opacity-70 cursor-not-allowed': bridgeSubmitting }"
                            :readonly="bridgeSubmitting"
                        >
                        <span class="mt-1 block text-[0.7rem] text-muted">
                            Satu pemisah saja (koma = desimal). Contoh: <span class="af-num">1,00</span>
                        </span>
                    </label>
                </fieldset>

                <label class="block">
                    <span class="af-label">Catatan (opsional)</span>
                    <input
                        type="text"
                        name="notes"
                        x-model.fill="bridgeNotes"
                        value="<?= Security::e($bridgeNotesValue) ?>"
                        maxlength="255"
                        autocomplete="off"
                        placeholder="mis. talangan semalam, dikembalikan besok siang"
                        class="af-input af-input--sm"
                        :class="{ 'opacity-70 cursor-not-allowed': bridgeSubmitting }"
                        :readonly="bridgeSubmitting"
                    >
                </label>

                <!-- Pratinjau langsung (Alpine.js). Server menghitung ulang. -->
                <dl class="grid gap-1.5 border-t border-surface-800 pt-3 text-sm">
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-muted">Perkiraan fee</dt>
                        <dd class="af-num text-gold-primary" x-text="bridgeFeeLabel">Rp 0</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-muted">Kembali saat tutup buku</dt>
                        <dd class="af-num font-semibold text-emerald-cash" x-text="bridgeReturnLabel">Rp 0</dd>
                    </div>
                </dl>

                <p class="text-[0.7rem] leading-relaxed text-muted">
                    Fee dibebankan <strong>hanya</strong> pada hak pengelola; dana mitra tidak
                    tersentuh sama sekali.
                </p>

                <div class="grid gap-2 sm:grid-cols-2">
                    <button
                        type="button"
                        class="af-btn af-btn--ghost af-btn--block"
                        @click="bridgeOpen = false"
                    >
                        Batal
                    </button>
                    <button
                        type="submit"
                        class="af-btn af-btn--primary af-btn--block"
                        :disabled="!bridgeSubmitReady"
                    >
                        Terima &amp; Catat Kas Masuk
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
