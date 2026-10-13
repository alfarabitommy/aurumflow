<?php

declare(strict_types=1);

/*
 * Kelola Modal & Likuiditas — "Layar E" dokumen UI/UX (Fase 4).
 *
 * Prinsip yang dipegang:
 *   - Brankas Utama adalah rumah modal; Kas Meja Bakar adalah kas yang benar-
 *     benar dibelanjakan kasir. Karena itu setoran masuk ke Brankas dan ada
 *     Pindah Kas SATU ARAH (Brankas → Meja); arah balik sudah ditangani Smart
 *     Auto-Split saat settlement.
 *   - Simulator "Belanja Malam Ini" hanya pratinjau: isian terisi nilai nyata
 *     dari peladen (modal belanja hulu berstatus holding) dan bebas diubah,
 *     sedangkan tabel proyeksi yang dirender peladen tetap benar tanpa JS.
 *   - Kuota serapan hanya bermakna bagi Mitra Prioritas; dompet lain ditampilkan
 *     terkunci (readonly) dan peladen menolak kuota untuk tier selain prioritas.
 *   - Kontrol form (input/select) TIDAK PERNAH `:disabled`. Peramban
 *     mengeluarkan kontrol `disabled` dari data form, sehingga POST hanya
 *     membawa `_token` (payload ± 357 byte) dan peladen menjawab 422 "wajib
 *     diisi". Selama pengiriman dipakai `:readonly="submitting"` + kelas redup;
 *     `:disabled` hanya untuk <button> (tanpa data form).
 *
 * Data dari CapitalController: $registers, $wallets, $projection,
 * $projectionError, $operatorMissing, $operatorUserId, $operatorQuotaLabel,
 * $queue, $tierOptions, $noticeDays, $activeDeficit, $formToken, $alpineConfig,
 * $input, $error (+ $register dan $holding untuk header layout operator).
 */

use AurumFlow\Config\App;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\CapitalWallet;
use AurumFlow\Models\WithdrawalRequest;
use AurumFlow\Services\OnboardingService;

$registers = is_array($registers ?? null) ? $registers : [];
$wallets = is_array($wallets ?? null) ? $wallets : [];
$projection = is_array($projection ?? null) ? $projection : [];
$queue = is_array($queue ?? null) ? $queue : [];
$tierOptions = is_array($tierOptions ?? null) ? $tierOptions : [];
$input = is_array($input ?? null) ? $input : [];

$error = isset($error) && is_string($error) && $error !== '' ? $error : null;
$projectionError = isset($projectionError) && is_string($projectionError) && $projectionError !== ''
    ? $projectionError
    : null;

$formToken = isset($formToken) && is_string($formToken) ? $formToken : '';
$noticeDays = isset($noticeDays) ? (int) $noticeDays : 0;
$activeDeficit = isset($activeDeficit) && is_string($activeDeficit) ? $activeDeficit : '-';
$operatorMissing = (bool) ($operatorMissing ?? false);
$operatorUserId = isset($operatorUserId) && is_string($operatorUserId) ? $operatorUserId : '';

// Kuota dasar Pengelola (mis. `50,00 %`) — kebijakan sistem, bukan isian operator.
$operatorQuotaLabel = isset($operatorQuotaLabel) && is_string($operatorQuotaLabel) && $operatorQuotaLabel !== ''
    ? $operatorQuotaLabel
    : '-';

$teller = is_array($registers['teller'] ?? null) ? $registers['teller'] : [];
$brankas = is_array($registers['brankas'] ?? null) ? $registers['brankas'] : [];

$alpineConfig = isset($alpineConfig) && is_string($alpineConfig) ? $alpineConfig : '{}';

$value = static function (string $field) use ($input): string {
    $raw = $input[$field] ?? '';

    return is_string($raw) ? $raw : '';
};

$cashConfirm = 'Catat pergerakan kas ini?';
?>
<?php if ($error !== null): ?>
    <div class="af-alert af-alert--error" role="alert"><?= Security::e($error) ?></div>
<?php endif; ?>

<section class="af-card">
    <div class="af-glow" aria-hidden="true"></div>

    <h1 class="af-card__title">Kelola Modal &amp; Likuiditas</h1>
    <p class="af-card__subtitle">
        Terima setoran mitra, arahkan kas ke meja bakar, dan uji rencana serapan sebelum belanja malam.
    </p>

    <dl class="af-facts mt-4">
        <div>
            <dt>Saldo <?= Security::e($brankas['name'] ?? 'Brankas Utama') ?></dt>
            <dd class="af-num text-gold-primary"><?= Security::e($brankas['saldo_label'] ?? '-') ?></dd>
        </div>
        <div>
            <dt>Saldo <?= Security::e($teller['name'] ?? 'Kas Meja Bakar') ?></dt>
            <dd class="af-num text-emerald-cash"><?= Security::e($teller['saldo_label'] ?? '-') ?></dd>
        </div>
        <div>
            <dt>Defisit berjalan</dt>
            <dd class="af-num"><?= Security::e($activeDeficit) ?></dd>
        </div>
        <div>
            <dt>Masa tunggu penarikan modal</dt>
            <dd class="af-num"><?= Security::e($noticeDays > 0 ? $noticeDays . ' hari kerja' : '-') ?></dd>
        </div>
    </dl>
</section>

<?php if ($operatorMissing): ?>
    <section class="af-card">
        <h2 class="af-card__title">Dompet Pengelola belum siap</h2>
        <p class="af-card__subtitle">
            Prioritaskan serapan modal Pengelola (50 %) dengan membuat dompet tier Pengelola untuk akun ini.
        </p>

        <form
            x-data="{ submitting: false }"
            method="post"
            action="<?= Security::e(App::url('operator/capital/mitra')) ?>"
            class="mt-4 grid gap-3"
            novalidate
            @submit="submitting = true"
        >
            <?= Security::csrfField() ?>
            <input type="hidden" name="mitra_mode" value="dompet">
            <input type="hidden" name="mitra_user_id" value="<?= Security::e($operatorUserId) ?>">
            <input type="hidden" name="mitra_tier" value="<?= Security::e(CapitalWallet::TIER_OPERATOR) ?>">

            <button type="submit" class="af-btn af-btn--primary af-btn--block" :disabled="submitting">
                Siapkan dompet Pengelola
            </button>
        </form>
    </section>
<?php endif; ?>

<!-- ======================= A. SETOR MODAL BARU ======================= -->
<section class="af-card">
    <h2 class="af-card__title">Setor Modal Baru</h2>
    <p class="af-card__subtitle">
        Setoran tunai masuk ke <?= Security::e($brankas['name'] ?? 'Brankas Utama') ?> dan menambah modal mitra
        dalam satu transaksi.
    </p>

    <?php if ($wallets === []): ?>
        <p class="mt-3 text-sm text-muted">
            Belum ada dompet mitra. Tambahkan mitra terlebih dahulu pada bagian Portofolio Mitra.
        </p>
    <?php else: ?>
        <form
            x-data="{ submitting: false }"
            method="post"
            action="<?= Security::e(App::url('operator/capital/topup')) ?>"
            class="mt-4 grid gap-4"
            novalidate
            data-confirm="<?= Security::e($cashConfirm) ?>"
            @submit="submitting = true"
        >
            <?= Security::csrfField() ?>
            <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">

            <label class="block">
                <span class="af-label">Mitra Penyetor</span>
                <select
                    name="topup_wallet_id"
                    class="af-input af-input--sm"
                    :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                >
                    <?php foreach ($wallets as $wallet): ?>
                        <option
                            value="<?= Security::e($wallet['id'] ?? '') ?>"
                            <?= Security::e(($wallet['id'] ?? '') === $value('topup_wallet_id') ? 'selected' : '') ?>
                        ><?= Security::e(($wallet['name'] ?? '') . ' — ' . ($wallet['tier_label'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="block">
                <span class="af-label">Nominal Setoran</span>
                <input
                    type="text"
                    name="topup_amount"
                    value="<?= Security::e($value('topup_amount')) ?>"
                    inputmode="numeric"
                    autocomplete="off"
                    placeholder="mis. 10.000.000"
                    class="af-input"
                    :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                    :readonly="submitting"
                >
            </label>

            <label class="block">
                <span class="af-label">Catatan (opsional)</span>
                <input
                    type="text"
                    name="topup_note"
                    value="<?= Security::e($value('topup_note')) ?>"
                    maxlength="255"
                    autocomplete="off"
                    placeholder="mis. setoran tunai 18 Sep"
                    class="af-input af-input--sm"
                    :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                    :readonly="submitting"
                >
            </label>

            <button type="submit" class="af-btn af-btn--primary af-btn--block" :disabled="submitting">
                Catat Setoran ke Brankas
            </button>
        </form>
    <?php endif; ?>
</section>

<!-- ==================== B. PORTOFOLIO MITRA ==================== -->
<section class="af-card">
    <h2 class="af-card__title">Portofolio Mitra</h2>
    <p class="af-card__subtitle">Modal pokok, dividen terkumpul, dan kuota serapan terkunci.</p>

    <?php if ($wallets === []): ?>
        <p class="mt-3 text-sm text-muted">Belum ada dompet modal terdaftar.</p>
    <?php else: ?>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full border-collapse text-left text-xs">
                <thead class="text-[0.68rem] uppercase tracking-[0.14em] text-muted">
                    <tr class="border-b border-surface-800">
                        <th class="py-2 pr-3">Mitra</th>
                        <th class="py-2 pr-3 text-right">Modal</th>
                        <th class="py-2 pr-3 text-right">Dividen</th>
                        <th class="py-2">Kuota Serapan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($wallets as $wallet): ?>
                        <tr class="border-b border-surface-800/60 align-middle">
                            <td class="py-3 pr-3">
                                <span class="block font-semibold text-pure"><?= Security::e($wallet['name'] ?? '') ?></span>
                                <span class="block text-muted"><?= Security::e($wallet['tier_label'] ?? '') ?></span>
                            </td>
                            <td class="af-num py-3 pr-3 text-right text-gold-primary">
                                <?= Security::e($wallet['capital_label'] ?? '-') ?>
                            </td>
                            <td class="af-num py-3 pr-3 text-right text-emerald-cash">
                                <?= Security::e($wallet['profit_label'] ?? '-') ?>
                            </td>
                            <td class="py-3">
                                <?php if (($wallet['tier'] ?? '') === CapitalWallet::TIER_OPERATOR): ?>
                                    <!--
                                        Pengelola TIDAK punya kuota per dompet: mesin
                                        alokasi memakai kuota dasar dari
                                        `system_settings.operator_base_quota_pct`.
                                        Menampilkan isian `0,00 %` di sini menyesatkan,
                                        jadi nilainya tampil sebagai badge kebijakan.
                                    -->
                                    <span class="af-num font-semibold text-emerald-cash">
                                        <?= Security::e($operatorQuotaLabel ?? '-') ?> (Sistem)
                                    </span>
                                    <span class="block text-[0.7rem] text-muted">
                                        Kuota dasar pengelola dari Pengaturan Sistem.
                                    </span>
                                <?php elseif (($wallet['tier'] ?? '') === CapitalWallet::TIER_FAMILY): ?>
                                    <!--
                                        Mitra Prioritas: SATU-SATUNYA tier dengan kuota
                                        serapan terkunci yang dapat diubah operator.
                                    -->
                                    <form
                                        x-data="{ submitting: false }"
                                        method="post"
                                        action="<?= Security::e(App::url('operator/capital/quota')) ?>"
                                        class="flex items-center gap-2"
                                        novalidate
                                        @submit="submitting = true"
                                    >
                                        <?= Security::csrfField() ?>
                                        <input type="hidden" name="quota_wallet_id" value="<?= Security::e($wallet['id'] ?? '') ?>">
                                        <input
                                            type="text"
                                            name="quota_value"
                                            value="<?= Security::e($wallet['quota_value'] ?? '0,00') ?>"
                                            inputmode="decimal"
                                            autocomplete="off"
                                            aria-label="Kuota serapan <?= Security::e($wallet['name'] ?? '') ?>"
                                            class="af-input af-input--sm w-20 text-right"
                                            :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                                            :readonly="submitting"
                                        >
                                        <span class="text-muted">%</span>
                                        <button type="submit" class="af-btn af-btn--ghost px-2 text-[0.7rem]" :disabled="submitting">
                                            Simpan
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <!--
                                        Mitra Reguler selalu pro-rata saldo atas sisa
                                        kebutuhan, sehingga kolom kuota tidak berlaku
                                        baginya (peladen juga menolak kuota bukan-nol
                                        untuk tier ini).
                                    -->
                                    <span class="italic text-muted">Pro-rata</span>
                                    <span class="block text-[0.7rem] text-muted">
                                        Tanpa kuota &mdash; mengikuti sisa kebutuhan.
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <details class="mt-4 rounded-xl border border-surface-800 bg-surface-800/40 p-4">
        <summary class="cursor-pointer text-sm font-semibold text-gold-primary">Tambah Mitra Baru</summary>

        <form
            x-data="{ submitting: false }"
            method="post"
            action="<?= Security::e(App::url('operator/capital/mitra')) ?>"
            class="mt-3 grid gap-3"
            novalidate
            @submit="submitting = true"
        >
            <?= Security::csrfField() ?>
            <input type="hidden" name="mitra_mode" value="mitra">

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                    <span class="af-label">Nama Mitra</span>
                    <input
                        type="text"
                        name="mitra_full_name"
                        value="<?= Security::e($value('mitra_full_name')) ?>"
                        maxlength="100"
                        autocomplete="off"
                        placeholder="mis. Bpk. Ahmad"
                        class="af-input af-input--sm"
                        :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                        :readonly="submitting"
                    >
                </label>

                <label class="block">
                    <span class="af-label">Nomor Telepon (identitas login)</span>
                    <input
                        type="tel"
                        name="mitra_phone_number"
                        value="<?= Security::e($value('mitra_phone_number')) ?>"
                        inputmode="tel"
                        autocomplete="off"
                        placeholder="mis. 081234567890"
                        class="af-input af-input--sm"
                        :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                        :readonly="submitting"
                    >
                </label>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                    <span class="af-label">Jenis Mitra</span>
                    <select
                        name="mitra_tier"
                        class="af-input af-input--sm"
                        :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                    >
                        <?php foreach ($tierOptions as $option): ?>
                            <option
                                value="<?= Security::e($option['value'] ?? '') ?>"
                                <?= Security::e(($option['value'] ?? '') === $value('mitra_tier') ? 'selected' : '') ?>
                            ><?= Security::e($option['label'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="block">
                    <span class="af-label">Kuota Serapan (%, khusus Prioritas)</span>
                    <input
                        type="text"
                        name="mitra_quota"
                        value="<?= Security::e($value('mitra_quota')) ?>"
                        inputmode="decimal"
                        autocomplete="off"
                        placeholder="mis. 20"
                        class="af-input af-input--sm"
                        :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                        :readonly="submitting"
                    >
                </label>
            </div>

            <p class="text-[0.7rem] text-muted">
                Akun dibuat bersama tautan undangan privatnya (lihat kartu
                <strong class="text-pure">Undangan &amp; Onboarding Mitra</strong> di bawah). Mitra baru
                dapat masuk setelah ia menyetujui Pakta Integritas dan menetapkan PIN enam digit.
            </p>

            <button type="submit" class="af-btn af-btn--ghost af-btn--block" :disabled="submitting">
                Simpan Mitra &amp; Dompet
            </button>
        </form>
    </details>
</section>

<!-- ==================== C. PINDAH KAS KE MEJA ==================== -->
<section class="af-card">
    <h2 class="af-card__title">Pindah Kas ke Meja Bakar</h2>
    <p class="af-card__subtitle">
        Modal turun dari <?= Security::e($brankas['name'] ?? 'Brankas Utama') ?>
        ke <?= Security::e($teller['name'] ?? 'Kas Meja Bakar') ?> agar dapat dibelanjakan kasir malam ini.
        Satu arah &mdash; sisa kas malam ditempatkan otomatis saat tutup buku.
    </p>

    <form
        x-data="{ submitting: false }"
        method="post"
        action="<?= Security::e(App::url('operator/capital/transfer')) ?>"
        class="mt-4 grid gap-4"
        novalidate
        data-confirm="<?= Security::e($cashConfirm) ?>"
        @submit="submitting = true"
    >
        <?= Security::csrfField() ?>
        <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">

        <label class="block">
            <span class="af-label">Nominal Pindah Kas</span>
            <input
                type="text"
                name="transfer_amount"
                value="<?= Security::e($value('transfer_amount')) ?>"
                inputmode="numeric"
                autocomplete="off"
                placeholder="mis. 5.000.000"
                class="af-input"
                :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                :readonly="submitting"
            >
            <span class="mt-1 block text-[0.7rem] text-muted">
                Saldo brankas saat ini <span class="af-num"><?= Security::e($brankas['saldo_label'] ?? '-') ?></span>.
            </span>
        </label>

        <label class="block">
            <span class="af-label">Catatan (opsional)</span>
            <input
                type="text"
                name="transfer_note"
                value="<?= Security::e($value('transfer_note')) ?>"
                maxlength="255"
                autocomplete="off"
                placeholder="mis. bekal belanja malam ini"
                class="af-input af-input--sm"
                :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                :readonly="submitting"
            >
        </label>

        <button type="submit" class="af-btn af-btn--ghost af-btn--block" :disabled="submitting">
            Pindahkan ke Kas Meja Bakar
        </button>
    </form>
</section>

<!-- ============ D. SIMULATOR "BELANJA MALAM INI" ============ -->
<section class="af-card" x-data="capitalSimulator(<?= Security::e($alpineConfig) ?>)">
    <h2 class="af-card__title">Simulator &ldquo;Belanja Malam Ini&rdquo;</h2>
    <p class="af-card__subtitle">
        Isian terisi modal belanja hulu yang nyata dan bebas diubah untuk menguji rencana lain.
        Serapan dihitung ulang peladen saat tutup buku.
    </p>

    <?php if ($projectionError !== null): ?>
        <div class="af-alert af-alert--error mt-3" role="alert"><?= Security::e($projectionError) ?></div>
    <?php endif; ?>

    <label class="mt-4 block">
        <span class="af-label">Rencana Belanja Malam Ini</span>
        <input
            x-model.fill="needInput"
            type="text"
            name="need_cents"
            value="<?= Security::e($projection['need_field'] ?? '') ?>"
            inputmode="numeric"
            autocomplete="off"
            placeholder="mis. 35.157.500"
            class="af-input"
            :class="{ 'opacity-70 cursor-not-allowed': submitting }"
            :readonly="submitting"
        >
        <span class="mt-1 block text-[0.7rem] text-muted">
            Modal belanja hulu berstatus holding:
            <span class="af-num"><?= Security::e($projection['need_label'] ?? '-') ?></span>.
        </span>
    </label>

    <!-- Pratinjau langsung (Alpine.js). Server menghitung ulang saat settlement. -->
    <dl class="mt-4 grid gap-1.5 rounded-xl border border-surface-800 bg-surface-800/40 p-4 text-sm">
        <div class="flex items-baseline justify-between gap-3">
            <dt class="text-muted">Pengelola (prioritas kuota)</dt>
            <dd class="af-num text-pure" x-text="operatorLabel">Rp 0</dd>
        </div>
        <div class="flex items-baseline justify-between gap-3">
            <dt class="text-muted">Mitra Prioritas (kuota terkunci)</dt>
            <dd class="af-num text-gold-primary" x-text="familyLabel">Rp 0</dd>
        </div>
        <div class="flex items-baseline justify-between gap-3">
            <dt class="text-muted">Mitra Reguler (pro-rata sisa)</dt>
            <dd class="af-num text-emerald-cash" x-text="externalLabel">Rp 0</dd>
        </div>
        <div class="flex items-baseline justify-between gap-3 border-t border-surface-800 pt-2">
            <dt class="text-muted">Sisa tak terserap</dt>
            <dd class="af-num text-muted" x-text="uncoveredLabel">Rp 0</dd>
        </div>
    </dl>

    <div class="mt-3 rounded-xl border border-surface-800 bg-surface-800/40 p-4">
        <p class="text-[0.68rem] font-semibold uppercase tracking-[0.16em] text-muted">Rincian per dompet</p>
        <ul class="mt-2 grid gap-1.5 text-xs">
            <template x-for="row in rows" :key="row.id">
                <li class="flex items-baseline justify-between gap-3">
                    <span class="text-muted">
                        <span class="text-pure" x-text="row.name"></span>
                        &middot; <span x-text="row.tierLabel"></span>
                    </span>
                    <span class="af-num font-semibold text-pure" x-text="row.utilizedLabel"></span>
                </li>
            </template>
        </ul>
    </div>

    <?php if (($projection['available'] ?? false) === true && ($projection['rows'] ?? []) !== []): ?>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full border-collapse text-left text-xs">
                <caption class="py-2 text-left text-[0.7rem] text-muted">
                    Proyeksi peladen untuk modal belanja hulu saat ini
                    (<?= Security::e($projection['need_label'] ?? '-') ?>) &mdash; acuan cetak bila JavaScript nonaktif.
                </caption>
                <thead class="text-[0.68rem] uppercase tracking-[0.14em] text-muted">
                    <tr class="border-b border-surface-800">
                        <th class="py-2 pr-3">Dompet</th>
                        <th class="py-2 pr-3 text-right">Saldo Modal</th>
                        <th class="py-2 pr-3 text-right">Kuota</th>
                        <th class="py-2 text-right">Terserap</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projection['rows'] as $row): ?>
                        <tr class="border-b border-surface-800/60">
                            <td class="py-2 pr-3">
                                <span class="block text-pure"><?= Security::e($row['name'] ?? '') ?></span>
                                <span class="block text-muted"><?= Security::e($row['tier_label'] ?? '') ?></span>
                            </td>
                            <td class="af-num py-2 pr-3 text-right text-muted">
                                <?= Security::e($row['available_label'] ?? '-') ?>
                            </td>
                            <td class="af-num py-2 pr-3 text-right text-muted">
                                <?= Security::e($row['quota_label'] ?? '-') ?>
                            </td>
                            <td class="af-num py-2 text-right font-semibold text-gold-primary">
                                <?= Security::e($row['utilized_label'] ?? '-') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td class="py-2 pr-3 font-semibold text-pure" colspan="3">Total terserap</td>
                        <td class="af-num py-2 text-right font-semibold text-emerald-cash">
                            <?= Security::e($projection['utilized_label'] ?? '-') ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="py-2 pr-3 text-muted" colspan="3">Sisa tak terserap</td>
                        <td class="af-num py-2 text-right text-muted">
                            <?= Security::e($projection['uncovered_label'] ?? '-') ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- ================= E. ANTREAN PENARIKAN (H-7) ================= -->
<section class="af-card">
    <h2 class="af-card__title">Antrean Penarikan (H-<?= Security::e($noticeDays > 0 ? $noticeDays : 7) ?>)</h2>
    <p class="af-card__subtitle">
        Penarikan modal pokok menunggu <?= Security::e($noticeDays > 0 ? $noticeDays : 7) ?> hari kerja;
        penarikan dividen dapat dicairkan setelah disetujui.
    </p>

    <?php if ($queue === []): ?>
        <p class="mt-3 text-sm text-muted">Tidak ada permohonan penarikan yang menunggu.</p>
    <?php else: ?>
        <ul class="mt-3 divide-y divide-surface-800">
            <?php foreach ($queue as $request): ?>
                <li class="grid gap-2 py-3">
                    <div class="flex items-baseline justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-pure">
                                <?= Security::e($request['owner'] ?? '') ?>
                                <span class="text-muted">&middot; <?= Security::e($request['tier_label'] ?? '') ?></span>
                            </p>
                            <p class="text-xs text-muted">
                                <?= Security::e($request['type_label'] ?? '') ?>
                                &middot; <?= Security::e($request['status_label'] ?? '') ?>
                                &middot; jatuh tempo <?= Security::e($request['due_label'] ?? '-') ?>
                                (<?= Security::e($request['remaining'] ?? '-') ?>)
                            </p>
                        </div>
                        <p class="af-num shrink-0 text-sm font-semibold text-gold-primary">
                            <?= Security::e($request['amount_label'] ?? '-') ?>
                        </p>
                    </div>

                    <?php if (($request['blocked_note'] ?? '') !== ''): ?>
                        <p class="text-[0.7rem] text-muted"><?= Security::e($request['blocked_note'] ?? '') ?></p>
                    <?php endif; ?>

                    <div class="flex flex-wrap gap-2">
                        <?php if ((bool) ($request['pending'] ?? false)): ?>
                            <form
                                x-data="{ submitting: false }"
                                method="post"
                                action="<?= Security::e(App::url('operator/capital/withdrawal')) ?>"
                                class="inline"
                                novalidate
                                @submit="submitting = true"
                            >
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">
                                <input type="hidden" name="withdrawal_action" value="setujui">
                                <input type="hidden" name="withdrawal_request_id" value="<?= Security::e($request['id'] ?? '') ?>">
                                <button type="submit" class="af-btn af-btn--ghost px-3 text-xs" :disabled="submitting">
                                    Setujui
                                </button>
                            </form>

                            <form
                                x-data="{ submitting: false }"
                                method="post"
                                action="<?= Security::e(App::url('operator/capital/withdrawal')) ?>"
                                class="inline"
                                novalidate
                                data-confirm="Tolak permohonan penarikan ini?"
                                @submit="submitting = true"
                            >
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">
                                <input type="hidden" name="withdrawal_action" value="tolak">
                                <input type="hidden" name="withdrawal_request_id" value="<?= Security::e($request['id'] ?? '') ?>">
                                <button type="submit" class="af-btn af-btn--ghost px-3 text-xs" :disabled="submitting">
                                    Tolak
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ((bool) ($request['approved'] ?? false)): ?>
                            <form
                                x-data="{ submitting: false }"
                                method="post"
                                action="<?= Security::e(App::url('operator/capital/withdrawal')) ?>"
                                class="inline"
                                novalidate
                                data-confirm="<?= Security::e($cashConfirm) ?>"
                                @submit="submitting = true"
                            >
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">
                                <input type="hidden" name="withdrawal_action" value="bayar">
                                <input type="hidden" name="withdrawal_request_id" value="<?= Security::e($request['id'] ?? '') ?>">
                                <button
                                    type="submit"
                                    class="af-btn af-btn--primary px-3 text-xs"
                                    :disabled="submitting || <?= Security::e(($request['payable'] ?? false) ? 'false' : 'true') ?>"
                                >
                                    Bayar
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($wallets !== []): ?>
        <form
            x-data="{ submitting: false }"
            method="post"
            action="<?= Security::e(App::url('operator/capital/withdrawal')) ?>"
            class="mt-4 grid gap-3 border-t border-surface-800 pt-4"
            novalidate
            @submit="submitting = true"
        >
            <?= Security::csrfField() ?>
            <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">
            <input type="hidden" name="withdrawal_action" value="ajukan">

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                    <span class="af-label">Mitra</span>
                    <select
                        name="withdrawal_wallet_id"
                        class="af-input af-input--sm"
                        :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                    >
                        <?php foreach ($wallets as $wallet): ?>
                            <option
                                value="<?= Security::e($wallet['id'] ?? '') ?>"
                                <?= Security::e(($wallet['id'] ?? '') === $value('withdrawal_wallet_id') ? 'selected' : '') ?>
                            ><?= Security::e(($wallet['name'] ?? '') . ' — ' . ($wallet['tier_label'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="block">
                    <span class="af-label">Jenis Penarikan</span>
                    <select
                        name="withdrawal_type"
                        class="af-input af-input--sm"
                        :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                    >
                        <option
                            value="<?= Security::e(WithdrawalRequest::TYPE_PROFIT) ?>"
                            <?= Security::e($value('withdrawal_type') === WithdrawalRequest::TYPE_PROFIT ? 'selected' : '') ?>
                        >Dividen</option>
                        <option
                            value="<?= Security::e(WithdrawalRequest::TYPE_CAPITAL) ?>"
                            <?= Security::e($value('withdrawal_type') === WithdrawalRequest::TYPE_CAPITAL ? 'selected' : '') ?>
                        >Modal Pokok (H-<?= Security::e($noticeDays > 0 ? $noticeDays : 7) ?> hari kerja)</option>
                    </select>
                </label>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                    <span class="af-label">Nominal</span>
                    <input
                        type="text"
                        name="withdrawal_amount"
                        value="<?= Security::e($value('withdrawal_amount')) ?>"
                        inputmode="numeric"
                        autocomplete="off"
                        placeholder="mis. 10.000.000"
                        class="af-input af-input--sm"
                        :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                        :readonly="submitting"
                    >
                </label>

                <label class="block">
                    <span class="af-label">Catatan (opsional)</span>
                    <input
                        type="text"
                        name="withdrawal_note"
                        value="<?= Security::e($value('withdrawal_note')) ?>"
                        maxlength="255"
                        autocomplete="off"
                        placeholder="mis. permohonan via WhatsApp"
                        class="af-input af-input--sm"
                        :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                        :readonly="submitting"
                    >
                </label>
            </div>

            <button type="submit" class="af-btn af-btn--ghost af-btn--block" :disabled="submitting">
                Catat pengajuan penarikan
            </button>
        </form>
    <?php endif; ?>
</section>

<!-- ========== F. UNDANGAN & ONBOARDING MITRA (FASE 5) ========== -->
<section class="af-card">
    <h2 class="af-card__title">Undangan &amp; Onboarding Mitra</h2>
    <p class="af-card__subtitle">
        Terbitkan tautan privat mitra, lalu kirimkan lewat WhatsApp. Tautan hanya berlaku sampai
        mitra menetapkan PIN 6 digit; memutar ulang tautan mematikan tautan lama (sekaligus cara
        reset PIN, karena mitra akan menandatangani ulang).
    </p>

    <?php
    $invitableWallets = array_values(array_filter(
        $wallets,
        static fn (array $wallet): bool => (bool) ($wallet['can_invite'] ?? false)
    ));
    ?>

    <?php if ($invitableWallets === []): ?>
        <p class="mt-3 text-sm text-muted">Belum ada akun mitra yang dapat diundang.</p>
    <?php else: ?>
        <ul class="mt-3 grid gap-3">
            <?php foreach ($invitableWallets as $wallet): ?>
                <li class="rounded-xl border border-surface-800 bg-surface-800/40 p-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <span class="block text-sm font-semibold text-pure"><?= Security::e($wallet['name'] ?? '') ?></span>
                            <span class="af-num block text-[0.7rem] text-muted"><?= Security::e($wallet['phone'] ?? '') ?></span>
                        </div>
                        <span class="text-[0.7rem] font-semibold <?= Security::e(($wallet['invite_state'] ?? '') === OnboardingService::STATE_ACTIVE
                            ? 'text-emerald-cash'
                            : 'text-gold-primary') ?>">
                            <?= Security::e($wallet['invite_state_label'] ?? '') ?>
                        </span>
                    </div>

                    <?php if (($wallet['onboarding_signed_label'] ?? '') !== ''): ?>
                        <p class="mt-1 text-[0.68rem] text-muted">
                            Jejak persetujuan terakhir: <?= Security::e($wallet['onboarding_signed_label']) ?>
                        </p>
                    <?php endif; ?>

                    <?php if (($wallet['invite_url'] ?? '') !== ''): ?>
                        <?php
                        // Tombol salin sengaja **swasembada** di view ini:
                        //   1. `x-data` hanya literal (`{ copied: false }`) sehingga
                        //      tombol tetap dirender `x-text`/`:class`-nya walau
                        //      aset JS yang di-cache peramban sudah basi — kegagalan
                        //      registrasi komponen di `app.js` pernah membuat
                        //      `x-data="clipboardButton(…)"` gagal init dan tombolnya
                        //      HILANG sama sekali (`ReferenceError`), bukan sekadar
                        //      tidak berfungsi;
                        //   2. `@click` berisi **satu ekspresi** berupa rantai
                        //      Promise. Pernyataan `try…catch` TIDAK sah di atribut
                        //      Alpine karena Alpine menempelkan `return` di depannya
                        //      ("Unexpected token 'try'").
                        // Tautannya ditanam sebagai literal string dari peladen
                        // (`App::absoluteUrl()` + token heksadesimal), sehingga tidak
                        // memuat tanda kutip/backslash yang bisa memutus string.
                        ?>
                        <div class="mt-2" x-data="{ copied: false }">
                            <label class="block">
                                <span class="af-label">Tautan undangan (kirim lewat WhatsApp)</span>
                                <input
                                    type="text"
                                    readonly
                                    class="af-input af-input--sm af-num text-[0.7rem]"
                                    value="<?= Security::e($wallet['invite_url']) ?>"
                                    onclick="this.select()"
                                >
                            </label>
                            <button
                                type="button"
                                class="af-btn af-btn--ghost mt-2 px-3 py-1 text-[0.7rem]"
                                :class="copied ? 'text-emerald-500' : ''"
                                @click="navigator.clipboard.writeText('<?= Security::e($wallet['invite_url']) ?>').then(() => { copied = true; setTimeout(() => copied = false, 2000); })"
                                x-text="copied ? 'Tersalin ✓' : 'Salin tautan'"
                            >Salin tautan</button>
                        </div>
                    <?php else: ?>
                        <p class="mt-2 text-[0.7rem] text-muted">
                            Belum ada tautan aktif. Tekan tombol di bawah untuk menerbitkannya.
                        </p>
                    <?php endif; ?>

                    <form method="post" action="<?= Security::e(App::url('operator/capital/invite')) ?>"
                          class="mt-2" novalidate>
                        <?= Security::csrfField() ?>
                        <input type="hidden" name="invite_wallet_id" value="<?= Security::e($wallet['id'] ?? '') ?>">
                        <input type="hidden" name="invite_action" value="<?= Security::e($wallet['invite_action'] ?? 'issue') ?>">
                        <button type="submit" class="af-btn af-btn--ghost px-3 py-1 text-[0.7rem]">
                            <?= Security::e($wallet['invite_button'] ?? 'Buat tautan') ?>
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
