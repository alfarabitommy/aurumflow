<?php

declare(strict_types=1);

/*
 * Layar "Kelola Kasir" — `GET/POST /operator/kasir` (Fase 7, dokumen UI/UX § 7.4).
 *
 * Prinsip:
 *   - **Khusus operator.** Kasir/mitra yang membuka rute ini dialihkan
 *     `CashierController` ke beranda perannya sebelum satu byte pun dirender.
 *   - **PIN tidak pernah kembali.** Tidak ada kolom PIN yang terisi; formulir
 *     reset selalu kosong dan hanya nama/nomor yang disebut pada flash.
 *   - Kontrol form **tidak pernah** `:disabled` (peramban mengeluarkan kontrol
 *     `disabled` dari data form → peladen menjawab `422`). Selama pengiriman
 *     dipakai `:readonly` + kelas redup; `:disabled` hanya untuk `<button>`.
 *   - Nilai awal berasal dari peladen (`value="…"`), placeholder contoh selalu
 *     berawalan `mis. `.
 *   - Seluruh output dinamis lewat `Security::e()`.
 *
 * Data dari CashierController: $rows, $formToken, $pinLength, $input, $error.
 */

use AurumFlow\Config\App;
use AurumFlow\Helpers\Security;

$rows = is_array($rows ?? null) ? $rows : [];
$input = is_array($input ?? null) ? $input : [];
$error = isset($error) && is_string($error) && $error !== '' ? $error : null;
$formToken = isset($formToken) && is_string($formToken) ? $formToken : '';
$pinLength = isset($pinLength) ? (int) $pinLength : 6;

$nameValue = isset($input['cashier_name']) && is_string($input['cashier_name']) ? $input['cashier_name'] : '';
$phoneValue = isset($input['cashier_phone']) && is_string($input['cashier_phone']) ? $input['cashier_phone'] : '';
$pinValue = isset($input['cashier_pin']) && is_string($input['cashier_pin']) ? $input['cashier_pin'] : '';
$pinConfirmValue = isset($input['cashier_pin_confirm']) && is_string($input['cashier_pin_confirm'])
    ? $input['cashier_pin_confirm']
    : '';
?>
<?php if ($error !== null): ?>
    <div class="af-alert af-alert--error" role="alert"><?= Security::e($error) ?></div>
<?php endif; ?>

<section class="af-card" x-data="{ submitting: false }">
    <div class="af-glow" aria-hidden="true"></div>

    <h1 class="af-card__title">Kelola Kasir</h1>
    <p class="af-card__subtitle">
        Kasir meja bakar masuk lewat <span class="af-num">/cashier/login</span> dengan PIN
        <?= Security::e($pinLength) ?> angka, dan hanya dapat membuka meja kasir serta dana talangan.
    </p>

    <form
        method="post"
        action="<?= Security::e(App::url('operator/kasir')) ?>"
        class="mt-4 grid gap-4"
        novalidate
        @submit="submitting = true"
    >
        <?= Security::csrfField() ?>
        <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">

        <label class="block">
            <span class="af-label">Nama Kasir</span>
            <input
                type="text"
                name="cashier_name"
                value="<?= Security::e($nameValue) ?>"
                maxlength="100"
                autocomplete="off"
                placeholder="mis. Sari (kasir malam)"
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
                name="cashier_phone"
                value="<?= Security::e($phoneValue) ?>"
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
                    name="cashier_pin"
                    value="<?= Security::e($pinValue) ?>"
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
                    name="cashier_pin_confirm"
                    value="<?= Security::e($pinConfirmValue) ?>"
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

        <p class="text-[0.7rem] leading-relaxed text-muted">
            PIN lemah ditolak (mis. <span class="af-num">111111</span>,
            <span class="af-num">123456</span>, <span class="af-num">123123</span>). Sampaikan PIN
            kepada kasir secara langsung — setelah tersimpan, PIN tidak dapat dilihat lagi dari layar ini.
        </p>

        <button type="submit" class="af-btn af-btn--primary af-btn--block" :disabled="submitting">
            Simpan Akun Kasir
        </button>
    </form>
</section>

<section class="af-card mt-5">
    <h2 class="af-card__title">Daftar Kasir</h2>
    <p class="af-card__subtitle">
        <?= Security::e(count($rows)) ?> akun kasir terdaftar. Akun nonaktif tidak dapat masuk.
    </p>

    <?php if ($rows === []): ?>
        <p class="mt-3 text-sm text-muted">Belum ada akun kasir. Buat akun pertama di atas.</p>
    <?php else: ?>
        <ul class="mt-3 divide-y divide-surface-800">
            <?php foreach ($rows as $row): ?>
                <?php
                $isActive = (bool) ($row['is_active'] ?? false);
                $statusClass = $isActive
                    ? 'text-emerald-cash'
                    : 'text-muted';
                ?>
                <li class="grid gap-3 py-3">
                    <div class="flex items-baseline justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-pure">
                                <?= Security::e($row['name'] ?? '') ?>
                            </p>
                            <p class="af-num text-xs text-muted"><?= Security::e($row['phone'] ?? '') ?></p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="text-xs font-semibold <?= Security::e($statusClass) ?>">
                                <?= Security::e($row['status_label'] ?? '') ?>
                            </p>
                            <p class="text-[0.68rem] text-muted">
                                diubah <?= Security::e($row['updated_label'] ?? '-') ?>
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <!-- Reset PIN: PIN lama langsung hangus. -->
                        <form
                            method="post"
                            action="<?= Security::e(App::url('operator/kasir/pin')) ?>"
                            class="grid gap-2"
                            novalidate
                            data-confirm="Ganti PIN kasir ini? PIN lama tidak berlaku lagi."
                            @submit="submitting = true"
                        >
                            <?= Security::csrfField() ?>
                            <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">
                            <input type="hidden" name="pin_user_id" value="<?= Security::e($row['id'] ?? '') ?>">

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

                            <button type="submit" class="af-btn af-btn--ghost" :disabled="submitting">
                                Reset PIN
                            </button>
                        </form>

                        <!-- Aktif / nonaktif -->
                        <form
                            method="post"
                            action="<?= Security::e(App::url('operator/kasir/status')) ?>"
                            class="grid content-start gap-2"
                            data-confirm="<?= Security::e($isActive
                                ? 'Nonaktifkan akun kasir ini? Ia tidak akan dapat masuk lagi.'
                                : 'Aktifkan kembali akun kasir ini?') ?>"
                            @submit="submitting = true"
                        >
                            <?= Security::csrfField() ?>
                            <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">
                            <input type="hidden" name="status_user_id" value="<?= Security::e($row['id'] ?? '') ?>">
                            <input type="hidden" name="status_active" value="<?= Security::e($isActive ? '0' : '1') ?>">

                            <span class="af-label">Status Akun</span>
                            <p class="text-[0.7rem] leading-relaxed text-muted">
                                <?= Security::e($isActive
                                    ? 'Kasir aktif. Nonaktifkan bila staf sudah tidak bertugas.'
                                    : 'Kasir nonaktif — login ditolak. Aktifkan kembali bila bertugas lagi.') ?>
                            </p>

                            <button type="submit" class="af-btn af-btn--ghost" :disabled="submitting">
                                <?= Security::e($isActive ? 'Nonaktifkan' : 'Aktifkan') ?>
                            </button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
