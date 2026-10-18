<?php

declare(strict_types=1);

/*
 * Layar "Buku Kasbon" — `GET/POST /operator/kasbon` (Fase 8, UI/UX § 8).
 *
 * Prinsip:
 *   - **Satu halaman untuk semua pekerjaan piutang**: batas harian, pencairan,
 *     angsuran (tunai / potong nota), pendaftaran petani, daftar peminjam, dan
 *     riwayat buku besar. Kasir tidak perlu berpindah layar di depan antrean.
 *   - **Nol kompilasi CSS**: seluruh kelas di bawah ini sudah ada di
 *     `public/assets/css/app.min.css` (dipakai view Fase 2–7). Tidak ada nilai
 *     arbitrary baru.
 *   - **Nilai awal dari peladen**, dibaca `kasbonForm::init()` lewat DOM (bukan
 *     `$refs`); isian bernilai awal memakai `x-model.fill`. Placeholder numerik
 *     selalu berawalan `mis. `.
 *   - Kontrol form **tidak pernah** `:disabled` (peramban mengeluarkan kontrol
 *     `disabled` dari data form → peladen menjawab `422`). Selama pengiriman
 *     dipakai `:readonly` + kelas redup; `:disabled` hanya untuk `<button>`.
 *   - Autocomplete memakai `<datalist>` native; setiap opsi membawa
 *     `data-miner-id` dan `data-outstanding` (sen integer) sehingga tombol cepat
 *     tidak pernah menghitung dari teks yang diketik kasir.
 *   - Seluruh output dinamis lewat `Security::e()`.
 *
 * Data dari KasbonController: $usage, $minerOptions, $borrowers, $ledger,
 * $quickPcts, $methods, $formToken, $input, $error.
 */

use AurumFlow\Config\App;
use AurumFlow\Helpers\Security;

$usage = is_array($usage ?? null) ? $usage : [];
$minerOptions = is_array($minerOptions ?? null) ? $minerOptions : [];
$borrowers = is_array($borrowers ?? null) ? $borrowers : [];
$ledger = is_array($ledger ?? null) ? $ledger : [];
$quickPcts = is_array($quickPcts ?? null) ? $quickPcts : [];
$methods = is_array($methods ?? null) ? $methods : [];
$input = is_array($input ?? null) ? $input : [];
$error = isset($error) && is_string($error) && $error !== '' ? $error : null;
$formToken = isset($formToken) && is_string($formToken) ? $formToken : '';

/** Nilai isian yang dipertahankan peladen pada jalur 422. */
$value = static function (array $source, string $key): string {
    return isset($source[$key]) && is_string($source[$key]) ? $source[$key] : '';
};

$minerIdValue = $value($input, 'miner_id');
$minerLabelValue = $value($input, 'miner_label');
$principalValue = $value($input, 'principal_amount');
$notesValue = $value($input, 'notes');

$payMinerIdValue = $value($input, 'pay_miner_id');
$payMinerLabelValue = $value($input, 'pay_miner_label');
$amountValue = $value($input, 'amount');
$payNotesValue = $value($input, 'pay_notes');
$methodValue = $value($input, 'payment_method');

if ($methodValue === '') {
    $methodValue = 'cash';
}

$nameValue = $value($input, 'full_name');
$phoneValue = $value($input, 'phone_number');
$locationValue = $value($input, 'location');

$disabled = (bool) ($usage['disabled'] ?? false);
$percent = isset($usage['percent']) ? (int) $usage['percent'] : 0;
?>
<?php if ($error !== null): ?>
    <div class="af-alert af-alert--error" role="alert"><?= Security::e($error) ?></div>
<?php endif; ?>

<?php if ($disabled): ?>
    <div class="af-alert af-alert--info" role="status">
        Kasbon harian sedang dinonaktifkan (batas Rp 0). Angsuran tetap dapat diterima.
    </div>
<?php endif; ?>

<section class="af-card">
    <div class="af-glow" aria-hidden="true"></div>

    <h1 class="af-card__title">Buku Kasbon Petani</h1>
    <p class="af-card__subtitle">
        Panjar tunai kepada penambang dicatat sebagai piutang. Pencairan dibatasi
        <span class="af-num"><?= Security::e($usage['limit_label'] ?? '-') ?></span> per hari
        dan tidak boleh melebihi uang fisik di laci.
    </p>

    <dl class="af-facts mt-4">
        <div class="flex items-baseline justify-between gap-3">
            <dt class="af-label">Terpakai hari ini</dt>
            <dd class="af-num text-sm font-semibold text-pure">
                <?= Security::e($usage['used_label'] ?? '-') ?>
                <span class="text-muted">dari <?= Security::e($usage['limit_label'] ?? '-') ?></span>
            </dd>
        </div>

        <div class="mt-2 h-2 w-full rounded-full bg-surface-800">
            <div class="h-2 rounded-full bg-gold-primary" style="width: <?= (int) $percent ?>%"></div>
        </div>

        <div class="mt-3 flex items-baseline justify-between gap-3">
            <dt class="af-label">Sisa batas</dt>
            <dd class="af-num text-sm font-semibold text-emerald-cash">
                <?= Security::e($usage['remaining_label'] ?? '-') ?>
            </dd>
        </div>

        <div class="mt-2 flex items-baseline justify-between gap-3">
            <dt class="af-label">Utang berjalan</dt>
            <dd class="af-num text-sm font-semibold text-gold-primary">
                <?= Security::e($usage['outstanding_label'] ?? '-') ?>
            </dd>
        </div>
    </dl>
</section>

<!-- ------------------------------------------------------------------ -->
<!-- Kartu 2: pencairan kasbon baru                                      -->
<!-- ------------------------------------------------------------------ -->
<section class="af-card mt-5" x-data="kasbonForm({ mode: 'disburse' })">
    <h2 class="af-card__title">Kasbon Baru</h2>
    <p class="af-card__subtitle">
        Pilih petani (ketik namanya untuk mencari), lalu isi nominal panjar.
    </p>

    <form
        method="post"
        action="<?= Security::e(App::url('operator/kasbon')) ?>"
        class="mt-4 grid gap-4"
        novalidate
        data-confirm="Cairkan kasbon ini? Kas Meja Bakar akan berkurang."
        @submit="submitting = true"
    >
        <?= Security::csrfField() ?>
        <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">

        <label class="block">
            <span class="af-label">Petani / Penambang</span>
            <input
                type="text"
                name="miner_label"
                list="kasbon-loan-miners"
                value="<?= Security::e($minerLabelValue) ?>"
                x-model.fill="minerLabel"
                @input="pickMiner()"
                maxlength="160"
                autocomplete="off"
                placeholder="mis. Budi — 0812… | Sisa Utang: Rp 250.000"
                class="af-input"
                :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                :readonly="submitting"
                required
            >
            <input type="hidden" name="miner_id" value="<?= Security::e($minerIdValue) ?>" x-model="minerId">

            <datalist id="kasbon-loan-miners">
                <?php foreach ($minerOptions as $option): ?>
                    <option
                        value="<?= Security::e($option['label'] ?? '') ?>"
                        data-miner-id="<?= Security::e($option['id'] ?? '') ?>"
                        data-outstanding="<?= (int) ($option['outstanding_cents'] ?? 0) ?>"
                    ></option>
                <?php endforeach; ?>
            </datalist>

            <span class="mt-1 block text-[0.68rem] text-muted">
                Format daftar: nama — telepon/lokasi | <span class="af-num">Sisa Utang</span>.
                Belum terdaftar? Daftarkan di kartu bawah.
            </span>
        </label>

        <label class="block">
            <span class="af-label">Nominal Kasbon (Rp)</span>
            <input
                type="text"
                name="principal_amount"
                value="<?= Security::e($principalValue) ?>"
                x-model.fill="amount"
                inputmode="numeric"
                autocomplete="off"
                placeholder="mis. 250.000"
                class="af-input af-num"
                :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                :readonly="submitting"
                required
            >
        </label>

        <label class="block">
            <span class="af-label">Catatan (opsional)</span>
            <input
                type="text"
                name="notes"
                value="<?= Security::e($notesValue) ?>"
                maxlength="200"
                autocomplete="off"
                placeholder="mis. beli kowi + solar"
                class="af-input af-input--sm"
                :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                :readonly="submitting"
            >
        </label>

        <button type="submit" class="af-btn af-btn--primary af-btn--block" :disabled="submitting">
            Cairkan Kasbon
        </button>
    </form>
</section>

<!-- ------------------------------------------------------------------ -->
<!-- Kartu 3: angsuran (tunai / potong nota)                             -->
<!-- ------------------------------------------------------------------ -->
<section class="af-card mt-5" x-data="kasbonForm({ mode: 'repay' })">
    <h2 class="af-card__title">Bayar Kasbon</h2>
    <p class="af-card__subtitle">
        Tunai (uang diterima kasir) atau <strong>Potong Nota</strong> (dipotong dari uang tebus
        emas). Pembayaran memotong kasbon tertua lebih dulu.
    </p>

    <form
        method="post"
        action="<?= Security::e(App::url('operator/kasbon/angsuran')) ?>"
        class="mt-4 grid gap-4"
        novalidate
        data-confirm="Catat angsuran kasbon ini?"
        @submit="submitting = true"
    >
        <?= Security::csrfField() ?>
        <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">

        <label class="block">
            <span class="af-label">Petani / Penambang</span>
            <input
                type="text"
                name="pay_miner_label"
                list="kasbon-pay-miners"
                value="<?= Security::e($payMinerLabelValue) ?>"
                x-model.fill="minerLabel"
                @input="pickMiner()"
                maxlength="160"
                autocomplete="off"
                placeholder="mis. Budi — 0812… | Sisa Utang: Rp 250.000"
                class="af-input"
                :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                :readonly="submitting"
                required
            >
            <input type="hidden" name="pay_miner_id" value="<?= Security::e($payMinerIdValue) ?>" x-model="minerId">

            <datalist id="kasbon-pay-miners">
                <?php foreach ($minerOptions as $option): ?>
                    <option
                        value="<?= Security::e($option['label'] ?? '') ?>"
                        data-miner-id="<?= Security::e($option['id'] ?? '') ?>"
                        data-outstanding="<?= (int) ($option['outstanding_cents'] ?? 0) ?>"
                    ></option>
                <?php endforeach; ?>
            </datalist>
        </label>

        <fieldset class="grid gap-2">
            <legend class="af-label px-1">Metode Pembayaran</legend>

            <?php foreach ($methods as $methodKey => $methodLabel): ?>
                <label class="flex items-center gap-2">
                    <input
                        type="radio"
                        name="payment_method"
                        value="<?= Security::e((string) $methodKey) ?>"
                        class="accent-[#D4AF37]"
                        <?php if ((string) $methodKey === $methodValue): ?>checked<?php endif; ?>
                        :readonly="submitting"
                    >
                    <span class="text-sm text-pure"><?= Security::e((string) $methodLabel) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <label class="block">
            <span class="af-label">Nominal Angsuran (Rp)</span>
            <input
                type="text"
                name="amount"
                value="<?= Security::e($amountValue) ?>"
                x-model.fill="amount"
                inputmode="numeric"
                autocomplete="off"
                placeholder="mis. 50.000"
                class="af-input af-num"
                :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                :readonly="submitting"
                required
            >
        </label>

        <div class="flex flex-wrap gap-2">
            <?php foreach ($quickPcts as $pct): ?>
                <button
                    type="button"
                    class="af-btn af-btn--ghost min-h-touch shrink-0 px-3 text-xs"
                    @click="pickPercent(<?= (int) $pct ?>)"
                    :disabled="submitting"
                >
                    <?= Security::e((int) $pct) ?> %
                </button>
            <?php endforeach; ?>

            <button
                type="button"
                class="af-btn af-btn--ghost min-h-touch shrink-0 px-3 text-xs"
                @click="payFull()"
                :disabled="submitting"
            >
                Lunas
            </button>
        </div>

        <p class="text-[0.7rem] leading-relaxed text-muted" aria-live="polite">
            Sisa utang petani terpilih: <span class="af-num" x-text="outstandingLabel || '—'">—</span>
            &middot; sisa setelah bayar: <span class="af-num" x-text="afterLabel">—</span>
        </p>

        <label class="block">
            <span class="af-label">Catatan (opsional)</span>
            <input
                type="text"
                name="pay_notes"
                value="<?= Security::e($payNotesValue) ?>"
                maxlength="200"
                autocomplete="off"
                placeholder="mis. potong nota emas 3 gram"
                class="af-input af-input--sm"
                :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                :readonly="submitting"
            >
        </label>

        <button type="submit" class="af-btn af-btn--primary af-btn--block" :disabled="submitting">
            Catat Angsuran
        </button>
    </form>
</section>

<!-- ------------------------------------------------------------------ -->
<!-- Kartu 4: pendaftaran petani baru (koleksi data bertahap)            -->
<!-- ------------------------------------------------------------------ -->
<section class="af-card mt-5" x-data="{ submitting: false }">
    <h2 class="af-card__title">Daftarkan Petani Baru</h2>
    <p class="af-card__subtitle">
        Cukup nama. Nomor telepon dan lokasi boleh menyusul pada kedatangan berikutnya.
    </p>

    <form
        method="post"
        action="<?= Security::e(App::url('operator/kasbon/petani')) ?>"
        class="mt-4 grid gap-4"
        novalidate
        @submit="submitting = true"
    >
        <?= Security::csrfField() ?>
        <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">

        <label class="block">
            <span class="af-label">Nama Petani (wajib)</span>
            <input
                type="text"
                name="full_name"
                value="<?= Security::e($nameValue) ?>"
                maxlength="60"
                autocomplete="off"
                placeholder="mis. Budi"
                class="af-input"
                :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                :readonly="submitting"
                required
            >
        </label>

        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="af-label">Telepon (opsional)</span>
                <input
                    type="tel"
                    name="phone_number"
                    value="<?= Security::e($phoneValue) ?>"
                    maxlength="20"
                    inputmode="tel"
                    autocomplete="off"
                    placeholder="mis. 081234567890"
                    class="af-input af-input--sm"
                    :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                    :readonly="submitting"
                >
            </label>

            <label class="block">
                <span class="af-label">Lokasi (opsional)</span>
                <input
                    type="text"
                    name="location"
                    value="<?= Security::e($locationValue) ?>"
                    maxlength="100"
                    autocomplete="off"
                    placeholder="mis. Sungai Kandi"
                    class="af-input af-input--sm"
                    :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                    :readonly="submitting"
                >
            </label>
        </div>

        <button type="submit" class="af-btn af-btn--ghost af-btn--block" :disabled="submitting">
            Simpan Petani
        </button>
    </form>
</section>

<!-- ------------------------------------------------------------------ -->
<!-- Kartu 5: peminjam aktif                                             -->
<!-- ------------------------------------------------------------------ -->
<section class="af-card mt-5">
    <h2 class="af-card__title">Petani dengan Utang Berjalan</h2>
    <p class="af-card__subtitle">
        <?= Security::e(count($borrowers)) ?> petani berutang, terbesar lebih dulu.
    </p>

    <?php if ($borrowers === []): ?>
        <p class="mt-3 text-sm text-muted">Belum ada kasbon berjalan. Catat kasbon baru di atas.</p>
    <?php else: ?>
        <div class="mt-3 overflow-x-auto">
            <table class="af-table">
                <thead>
                    <tr class="text-left text-[0.68rem] uppercase tracking-wide text-muted">
                        <th class="py-2 pr-3">Petani</th>
                        <th class="py-2 pr-3">Sisa Utang</th>
                        <th class="py-2">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-surface-800">
                    <?php foreach ($borrowers as $row): ?>
                        <tr>
                            <td class="py-2 pr-3">
                                <span class="block truncate text-sm font-semibold text-pure">
                                    <?= Security::e($row['name'] ?? '') ?>
                                </span>
                                <span class="block truncate text-[0.68rem] text-muted">
                                    <?= Security::e($row['contact'] ?? '') ?>
                                </span>
                            </td>
                            <td class="py-2 pr-3">
                                <span class="af-num text-sm font-semibold text-gold-primary">
                                    <?= Security::e($row['outstanding'] ?? '-') ?>
                                </span>
                                <span class="block text-[0.68rem] text-muted">
                                    <?= Security::e($row['loan_count'] ?? 0) ?> kasbon &middot;
                                    terakhir <?= Security::e($row['last_issued_on'] ?? '-') ?>
                                </span>
                            </td>
                            <td class="py-2 text-right">
                                <a
                                    class="af-btn af-btn--ghost px-3 text-xs"
                                    href="<?= Security::e(App::url('operator/kasbon?miner=' . rawurlencode((string) ($row['miner_id'] ?? '')))) ?>"
                                >Pilih</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- ------------------------------------------------------------------ -->
<!-- Kartu 6: riwayat buku besar                                         -->
<!-- ------------------------------------------------------------------ -->
<section class="af-card mt-5">
    <h2 class="af-card__title">Riwayat Buku Kasbon</h2>
    <p class="af-card__subtitle">
        <?= Security::e(count($ledger)) ?> entri terakhir. Potong nota tercatat sebagai angsuran.
    </p>

    <?php if ($ledger === []): ?>
        <p class="mt-3 text-sm text-muted">Belum ada pergerakan kasbon.</p>
    <?php else: ?>
        <div class="mt-3 max-h-40 overflow-y-auto">
            <table class="af-table">
                <thead>
                    <tr class="text-left text-[0.68rem] uppercase tracking-wide text-muted">
                        <th class="py-2 pr-3">Waktu</th>
                        <th class="py-2 pr-3">Petani</th>
                        <th class="py-2 pr-3">Nominal</th>
                        <th class="py-2">Sisa</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-surface-800">
                    <?php foreach ($ledger as $row): ?>
                        <?php
                        $amountClass = !empty($row['is_incoming'])
                            ? 'text-emerald-cash'
                            : 'text-red-300';
                        ?>
                        <tr>
                            <td class="py-2 pr-3">
                                <span class="block text-xs text-muted"><?= Security::e($row['when'] ?? '-') ?></span>
                                <span class="block text-[0.68rem] text-muted">
                                    <?= Security::e($row['type_label'] ?? '-') ?> &middot;
                                    <?= Security::e($row['method_label'] ?? '-') ?>
                                </span>
                            </td>
                            <td class="py-2 pr-3">
                                <span class="block truncate text-sm text-pure"><?= Security::e($row['name'] ?? '') ?></span>
                                <span class="block truncate text-[0.68rem] text-muted">
                                    oleh <?= Security::e($row['recorder'] ?? '-') ?>
                                </span>
                            </td>
                            <td class="py-2 pr-3">
                                <span class="af-num text-sm font-semibold <?= Security::e($amountClass) ?>">
                                    <?= Security::e($row['amount_label'] ?? '-') ?>
                                </span>
                            </td>
                            <td class="py-2">
                                <span class="af-num text-xs text-muted"><?= Security::e($row['balance_after'] ?? '-') ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="af-card mt-5">
    <h2 class="af-card__title">Petunjuk Singkat</h2>
    <p class="af-card__subtitle">
        Potong nota berarti pembelian hulu tetap dibayar penuh; angsuran dicatat terpisah
        sebagai kas masuk, sehingga laba dan bagi hasil mitra tidak terpengaruh piutang.
    </p>
    <p class="mt-2 text-[0.7rem] leading-relaxed text-muted">
        Pencairan ditolak bila melebihi sisa batas harian atau uang fisik di laci. Pelunasan
        ditandai otomatis saat sisa utang mencapai nol.
    </p>
</section>
