<?php

declare(strict_types=1);

/*
 * Layar G — "Kelola Mitra Lapangan" (`GET/POST /operator/petugas`, Fase 10).
 *
 * Prinsip:
 *   - **Khusus operator.** Kasir, mitra, dan petugas yang membuka rute ini
 *     dialihkan `OfficerController` ke beranda perannya sebelum satu byte pun
 *     dirender.
 *   - **PIN tidak pernah kembali.** Formulir reset selalu kosong; hanya nama dan
 *     nomor yang disebut pada flash.
 *   - **Nominal hasil hitung tidak pernah dikirim klien.** Form hanya memuat
 *     pemilih petugas, nominal, token pos kas, dan catatan; saldo hak, potongan,
 *     serta `balance_after` dihitung peladen.
 *   - Kontrol form **tidak pernah** `:disabled` (peramban mengeluarkan kontrol
 *     `disabled` dari data form → peladen menjawab `422`). Selama pengiriman
 *     dipakai `:readonly` + kelas redup; `:disabled` hanya untuk `<button>`.
 *   - Nilai awal berasal dari peladen (`value="…"`), placeholder contoh selalu
 *     berawalan `mis. `. Seluruh output dinamis lewat `Security::e()`.
 *
 * Data dari OfficerController: $overview, $registers, $formToken, $pinLength,
 * $input, $error.
 */

use AurumFlow\Config\App;
use AurumFlow\Helpers\Security;

$overview = is_array($overview ?? null) ? $overview : [];
$rows = is_array($overview['rows'] ?? null) ? $overview['rows'] : [];
$recent = is_array($overview['recent'] ?? null) ? $overview['recent'] : [];
$summary = is_array($overview['summary'] ?? null) ? $overview['summary'] : [];
$registers = is_array($registers ?? null) ? $registers : [];

$input = is_array($input ?? null) ? $input : [];
$error = isset($error) && is_string($error) && $error !== '' ? $error : null;
$formToken = isset($formToken) && is_string($formToken) ? $formToken : '';
$pinLength = isset($pinLength) ? (int) $pinLength : 6;

$field = static function (string $name, array $input): string {
    $value = $input[$name] ?? '';

    return is_string($value) ? $value : '';
};

$posOptions = $registers;
?>
<?php if ($error !== null): ?>
    <div class="af-alert af-alert--error" role="alert"><?= Security::e($error) ?></div>
<?php endif; ?>

<section class="af-card" x-data="{ submitting: false }">
    <div class="af-glow" aria-hidden="true"></div>

    <h1 class="af-card__title">Mitra Lapangan</h1>
    <p class="af-card__subtitle">
        Aparat / petugas lapangan menerima potongan persentase dari laba kotor harian.
        Potongan dibukukan sebagai saldo hak dan hanya berkurang saat dicairkan.
    </p>

    <dl class="af-facts mt-4">
        <div>
            <dt>Petugas aktif</dt>
            <dd class="af-num text-pure">
                <?= Security::e((int) ($summary['active'] ?? 0)) ?>/<?= Security::e((int) ($summary['count'] ?? 0)) ?>
            </dd>
        </div>
        <div>
            <dt>Total saldo hak</dt>
            <dd class="af-num text-emerald-cash"><?= Security::e($summary['payable_label'] ?? '-') ?></dd>
        </div>
        <div>
            <dt>Total tarif / batas</dt>
            <dd class="af-num text-gold-primary">
                <?= Security::e($summary['pct_total_label'] ?? '-') ?>
                <span class="text-muted">/ <?= Security::e($summary['cap_label'] ?? '-') ?></span>
            </dd>
        </div>
        <div>
            <dt>Bonus belum diserap</dt>
            <dd class="af-num text-pure"><?= Security::e($summary['bonus_open_label'] ?? '-') ?></dd>
        </div>
    </dl>
</section>

<section class="af-card mt-5" x-data="{ submitting: false }">
    <h2 class="af-card__title">Tambah Mitra Lapangan</h2>
    <p class="af-card__subtitle">
        Petugas masuk lewat <span class="af-num">/officer/login</span> dengan PIN
        <?= Security::e($pinLength) ?> angka dan hanya melihat saldo hak serta riwayatnya sendiri.
    </p>

    <form
        method="post"
        action="<?= Security::e(App::url('operator/petugas')) ?>"
        class="mt-4 grid gap-4"
        novalidate
        @submit="submitting = true"
    >
        <?= Security::csrfField() ?>
        <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">

        <label class="block">
            <span class="af-label">Nama Petugas</span>
            <input
                type="text"
                name="officer_name"
                value="<?= Security::e($field('officer_name', $input)) ?>"
                maxlength="100"
                autocomplete="off"
                placeholder="mis. Pak Yusuf (keamanan wilayah)"
                class="af-input af-input--sm"
                :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                :readonly="submitting"
                required
            >
        </label>

        <label class="block">
            <span class="af-label">Nomor Telepon (identitas login)</span>
            <input
                type="tel"
                name="officer_phone"
                value="<?= Security::e($field('officer_phone', $input)) ?>"
                maxlength="20"
                inputmode="tel"
                autocomplete="off"
                placeholder="mis. 081234567890"
                class="af-input af-input--sm"
                :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                :readonly="submitting"
                required
            >
        </label>

        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="af-label">PIN <?= Security::e($pinLength) ?> Angka</span>
                <input
                    type="password"
                    name="officer_pin"
                    value="<?= Security::e($field('officer_pin', $input)) ?>"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    maxlength="<?= Security::e($pinLength) ?>"
                    autocomplete="off"
                    placeholder="<?= Security::e(str_repeat('•', $pinLength)) ?>"
                    class="af-input af-input--sm af-num tracking-[0.3em]"
                    :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                    :readonly="submitting"
                    required
                >
            </label>

            <label class="block">
                <span class="af-label">Ulangi PIN</span>
                <input
                    type="password"
                    name="officer_pin_confirm"
                    value="<?= Security::e($field('officer_pin_confirm', $input)) ?>"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    maxlength="<?= Security::e($pinLength) ?>"
                    autocomplete="off"
                    placeholder="<?= Security::e(str_repeat('•', $pinLength)) ?>"
                    class="af-input af-input--sm af-num tracking-[0.3em]"
                    :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                    :readonly="submitting"
                    required
                >
            </label>
        </div>

        <label class="block">
            <span class="af-label">Potongan dari Laba Kotor (%)</span>
            <input
                type="text"
                name="officer_cut_pct"
                value="<?= Security::e($field('officer_cut_pct', $input)) ?>"
                maxlength="6"
                inputmode="decimal"
                autocomplete="off"
                placeholder="mis. 2,50"
                class="af-input af-input--sm af-num"
                :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                :readonly="submitting"
                required
            >
            <span class="mt-1 block text-[0.7rem] text-muted">
                Persentase dihitung dari laba kotor harian (pendapatan toko &minus; modal belanja hulu).
                Pada hari minus potongan otomatis nol.
            </span>
        </label>

        <p class="text-[0.7rem] leading-relaxed text-muted">
            PIN lemah ditolak (mis. <span class="af-num">111111</span>,
            <span class="af-num">123456</span>, <span class="af-num">123123</span>). Sampaikan PIN
            kepada petugas secara langsung — setelah tersimpan, PIN tidak dapat dilihat lagi dari layar ini.
        </p>

        <button type="submit" class="af-btn af-btn--primary af-btn--block" :disabled="submitting">
            Simpan Mitra Lapangan
        </button>
    </form>
</section>

<section class="af-card mt-5">
    <h2 class="af-card__title">Daftar Mitra Lapangan</h2>
    <p class="af-card__subtitle">
        <?= Security::e(count($rows)) ?> akun terdaftar. Petugas nonaktif berhenti menerima potongan,
        tetapi saldo haknya tetap dapat dicairkan.
    </p>

    <?php if ($rows === []): ?>
        <p class="mt-3 text-sm text-muted">Belum ada mitra lapangan. Buat akun pertama di atas.</p>
    <?php else: ?>
        <ul class="mt-3 divide-y divide-surface-800">
            <?php foreach ($rows as $row): ?>
                <?php
                $isActive = (bool) ($row['is_active'] ?? false);
                $statusClass = $isActive ? 'text-emerald-cash' : 'text-muted';
                ?>
                <li class="grid gap-3 py-4" x-data="{ submitting: false }">
                    <div class="flex items-baseline justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-pure">
                                <?= Security::e($row['name'] ?? '') ?>
                            </p>
                            <p class="af-num text-xs text-muted"><?= Security::e($row['phone'] ?? '') ?></p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="af-num text-sm font-semibold text-emerald-cash">
                                <?= Security::e($row['balance_label'] ?? '-') ?>
                            </p>
                            <p class="text-[0.68rem]">
                                <span class="af-num text-gold-primary"><?= Security::e($row['cut_pct_label'] ?? '-') ?></span>
                                <span class="<?= Security::e($statusClass) ?>">&middot; <?= Security::e($row['status_label'] ?? '') ?></span>
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <!-- Ubah tarif potongan -->
                        <form
                            method="post"
                            action="<?= Security::e(App::url('operator/petugas/potongan')) ?>"
                            class="grid gap-2"
                            novalidate
                            @submit="submitting = true"
                        >
                            <?= Security::csrfField() ?>
                            <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">
                            <input type="hidden" name="cut_officer_id" value="<?= Security::e($row['id'] ?? '') ?>">

                            <label class="block">
                                <span class="af-label">Potongan Baru (%)</span>
                                <input
                                    type="text"
                                    name="cut_pct"
                                    value="<?= Security::e($row['cut_pct_input'] ?? '') ?>"
                                    maxlength="6"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    placeholder="mis. 2,50"
                                    class="af-input af-input--sm af-num"
                                    :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                                    :readonly="submitting"
                                    required
                                >
                            </label>

                            <button type="submit" class="af-btn af-btn--ghost" :disabled="submitting">
                                Ubah Tarif
                            </button>
                        </form>

                        <!-- Aktif / nonaktif -->
                        <form
                            method="post"
                            action="<?= Security::e(App::url('operator/petugas/status')) ?>"
                            class="grid content-start gap-2"
                            data-confirm="<?= Security::e($isActive
                                ? 'Nonaktifkan petugas ini? Ia berhenti menerima potongan, tetapi saldo haknya tetap dapat dicairkan.'
                                : 'Aktifkan kembali petugas ini?') ?>"
                            @submit="submitting = true"
                        >
                            <?= Security::csrfField() ?>
                            <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">
                            <input type="hidden" name="status_user_id" value="<?= Security::e($row['user_id'] ?? '') ?>">
                            <input type="hidden" name="status_active" value="<?= Security::e($isActive ? '0' : '1') ?>">

                            <span class="af-label">Status Akun</span>
                            <p class="text-[0.7rem] leading-relaxed text-muted">
                                <?= Security::e($isActive
                                    ? 'Aktif — potongan ikut dihitung setiap tutup buku.'
                                    : 'Nonaktif — login ditolak dan potongan berhenti.') ?>
                            </p>

                            <button type="submit" class="af-btn af-btn--ghost" :disabled="submitting">
                                <?= Security::e($isActive ? 'Nonaktifkan' : 'Aktifkan') ?>
                            </button>
                        </form>
                    </div>

                    <details class="rounded-xl border border-surface-800 bg-surface-800/30 p-3">
                        <summary class="cursor-pointer text-xs font-semibold text-gold-primary">
                            Bonus &amp; Pencairan Hak
                        </summary>

                        <div class="mt-3 grid gap-4 sm:grid-cols-2">
                            <!-- Bonus manual (akrual) -->
                            <form
                                method="post"
                                action="<?= Security::e(App::url('operator/petugas/bonus')) ?>"
                                class="grid content-start gap-2"
                                novalidate
                                data-confirm="Catat bonus ini? Bonus menambah saldo hak dan mengurangi laba bersih pada tutup buku berikutnya."
                                @submit="submitting = true"
                            >
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">
                                <input type="hidden" name="bonus_officer_id" value="<?= Security::e($row['id'] ?? '') ?>">

                                <label class="block">
                                    <span class="af-label">Nominal Bonus</span>
                                    <input
                                        type="text"
                                        name="bonus_amount"
                                        value=""
                                        maxlength="20"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        placeholder="mis. 150.000"
                                        class="af-input af-input--sm af-num"
                                        :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                                        :readonly="submitting"
                                        required
                                    >
                                </label>

                                <label class="block">
                                    <span class="af-label">Alasan / Catatan</span>
                                    <input
                                        type="text"
                                        name="bonus_notes"
                                        value=""
                                        maxlength="255"
                                        autocomplete="off"
                                        placeholder="mis. bantuan koordinasi mingguan"
                                        class="af-input af-input--sm"
                                        :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                                        :readonly="submitting"
                                    >
                                </label>

                                <button type="submit" class="af-btn af-btn--ghost" :disabled="submitting">
                                    Beri Bonus
                                </button>
                            </form>

                            <!-- Pencairan hak -->
                            <form
                                method="post"
                                action="<?= Security::e(App::url('operator/petugas/cair')) ?>"
                                class="grid content-start gap-2"
                                novalidate
                                data-confirm="Cairkan saldo hak petugas ini? Kas pos terpilih akan berkurang."
                                @submit="submitting = true"
                            >
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">
                                <input type="hidden" name="disburse_officer_id" value="<?= Security::e($row['id'] ?? '') ?>">

                                <label class="block">
                                    <span class="af-label">Nominal Pencairan</span>
                                    <input
                                        type="text"
                                        name="disburse_amount"
                                        value=""
                                        maxlength="20"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        placeholder="mis. 100.000"
                                        class="af-input af-input--sm af-num"
                                        :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                                        :readonly="submitting"
                                        required
                                    >
                                    <span class="mt-1 block text-[0.7rem] text-muted">
                                        Saldo hak tersedia: <span class="af-num"><?= Security::e($row['balance_label'] ?? '-') ?></span>
                                    </span>
                                </label>

                                <label class="block">
                                    <span class="af-label">Sumber Kas</span>
                                    <select
                                        name="disburse_pos"
                                        class="af-input af-input--sm"
                                        :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                                    >
                                        <?php foreach ($posOptions as $token => $option): ?>
                                            <option value="<?= Security::e($token) ?>">
                                                <?= Security::e($option['label'] ?? '') ?>
                                                (saldo <?= Security::e($option['saldo_label'] ?? '-') ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>

                                <label class="block">
                                    <span class="af-label">Catatan (opsional)</span>
                                    <input
                                        type="text"
                                        name="disburse_notes"
                                        value=""
                                        maxlength="255"
                                        autocomplete="off"
                                        placeholder="mis. diambil tunai"
                                        class="af-input af-input--sm"
                                        :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                                        :readonly="submitting"
                                    >
                                </label>

                                <button type="submit" class="af-btn af-btn--primary" :disabled="submitting">
                                    Cairkan Hak
                                </button>
                            </form>
                        </div>
                    </details>

                    <!-- Reset PIN -->
                    <form
                        method="post"
                        action="<?= Security::e(App::url('operator/petugas/pin')) ?>"
                        class="grid gap-2 sm:grid-cols-3"
                        novalidate
                        data-confirm="Ganti PIN petugas ini? PIN lama tidak berlaku lagi."
                        @submit="submitting = true"
                    >
                        <?= Security::csrfField() ?>
                        <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">
                        <input type="hidden" name="pin_user_id" value="<?= Security::e($row['user_id'] ?? '') ?>">

                        <label class="block">
                            <span class="af-label">PIN Baru</span>
                            <input
                                type="password"
                                name="reset_pin"
                                inputmode="numeric"
                                pattern="[0-9]*"
                                maxlength="<?= Security::e($pinLength) ?>"
                                autocomplete="off"
                                placeholder="<?= Security::e(str_repeat('•', $pinLength)) ?>"
                                class="af-input af-input--sm af-num tracking-[0.3em]"
                                :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                                :readonly="submitting"
                                required
                            >
                        </label>

                        <label class="block">
                            <span class="af-label">Ulangi PIN Baru</span>
                            <input
                                type="password"
                                name="reset_pin_confirm"
                                inputmode="numeric"
                                pattern="[0-9]*"
                                maxlength="<?= Security::e($pinLength) ?>"
                                autocomplete="off"
                                placeholder="<?= Security::e(str_repeat('•', $pinLength)) ?>"
                                class="af-input af-input--sm af-num tracking-[0.3em]"
                                :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                                :readonly="submitting"
                                required
                            >
                        </label>

                        <div class="grid content-end">
                            <button type="submit" class="af-btn af-btn--ghost" :disabled="submitting">
                                Reset PIN
                            </button>
                        </div>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="af-card mt-5">
    <h2 class="af-card__title">Buku Hak Terbaru</h2>
    <p class="af-card__subtitle">Dua puluh catatan terakhir lintas petugas.</p>

    <?php if ($recent === []): ?>
        <p class="mt-3 text-sm text-muted">Belum ada catatan buku hak.</p>
    <?php else: ?>
        <ul class="mt-3 divide-y divide-surface-800">
            <?php foreach ($recent as $entry): ?>
                <li class="grid gap-1 py-3">
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="truncate text-sm font-semibold text-pure">
                            <?= Security::e($entry['name'] ?? '') ?>
                        </p>
                        <p class="af-num shrink-0 text-sm font-semibold text-gold-primary">
                            <?= Security::e($entry['amount_label'] ?? '-') ?>
                        </p>
                    </div>
                    <div class="flex items-baseline justify-between gap-3 text-[0.7rem] text-muted">
                        <span><?= Security::e($entry['type_label'] ?? '') ?> &middot; <?= Security::e($entry['tanggal'] ?? '-') ?></span>
                        <span class="af-num">Saldo <?= Security::e($entry['balance_label'] ?? '-') ?></span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
