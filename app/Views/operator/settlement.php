<?php

declare(strict_types=1);

/*
 * Rekonsiliasi & Settlement Hilir — "Layar C" dokumen UI/UX.
 *
 * Prinsip yang dipegang:
 *   - Bukti fisik wajib: foto nota toko dikompresi di peramban (HTML5 Canvas,
 *     target < 250 KB) sebelum diunggah; peladen tetap memvalidasi tipe dan
 *     ukurannya.
 *   - Smart Auto-Split: sistem MENYARANKAN alokasi kas (modal belanja hulu
 *     kembali ke Kas Meja Bakar, laba bersih ke Brankas Utama) tetapi kedua
 *     isian tetap milik operator. Jumlahnya wajib sama dengan kas yang
 *     diterima (pendapatan toko - beban aparat).
 *   - Tanpa JavaScript pun form tetap benar: dua isian alokasi yang dibiarkan
 *     kosong berarti "pakai saran sistem" (dihitung peladen).
 *   - Seluruh angka final dihitung ulang SettlementService; nominal apa pun
 *     dari peramban tidak dipercaya.
 *   - Kontrol form (input/select) TIDAK PERNAH `:disabled`. Peramban
 *     mengeluarkan kontrol `disabled` dari data form, sehingga form terkirim
 *     tanpa isian apa pun (hanya `_token`/`_once`) dan peladen menjawab 422
 *     "wajib diisi". Selama pengiriman dipakai `:readonly="submitting"` +
 *     kelas redup; `:disabled` hanya untuk <button> (tidak membawa data form).
 *
 * Data dari SettlementController: $batch, $registers, $deficit, $todayCount,
 * $lastSettlement, $formToken, $alpineConfig, $input, $error (+ $register dan
 * $holding untuk header layout operator).
 */

use AurumFlow\Config\App;
use AurumFlow\Helpers\Security;

$batch = is_array($batch ?? null) ? $batch : [];
$registers = is_array($registers ?? null) ? $registers : [];
$deficit = is_array($deficit ?? null) ? $deficit : [];
$input = is_array($input ?? null) ? $input : [];
$lastSettlement = is_array($lastSettlement ?? null) ? $lastSettlement : null;
$todayCount = isset($todayCount) ? (int) $todayCount : 0;

$error = isset($error) && is_string($error) && $error !== '' ? $error : null;
$formToken = isset($formToken) && is_string($formToken) ? $formToken : '';

$soldValue = isset($input['sold_grams']) && is_string($input['sold_grams']) ? $input['sold_grams'] : '';
$densityValue = isset($input['density_pct']) && is_string($input['density_pct']) ? $input['density_pct'] : '';
$priceValue = isset($input['shop_price_per_g']) && is_string($input['shop_price_per_g']) ? $input['shop_price_per_g'] : '';
$coordValue = isset($input['coord_fee']) && is_string($input['coord_fee']) ? $input['coord_fee'] : '';
$kasValue = isset($input['kas_split']) && is_string($input['kas_split']) ? $input['kas_split'] : '';
$brankasValue = isset($input['brankas_split']) && is_string($input['brankas_split']) ? $input['brankas_split'] : '';

$emptyBatch = (bool) ($batch['empty'] ?? true);

$teller = is_array($registers['teller'] ?? null) ? $registers['teller'] : [];
$brankas = is_array($registers['brankas'] ?? null) ? $registers['brankas'] : [];

// Konfigurasi Alpine.js disiapkan controller (integer dari kode kita sendiri).
$alpineConfig = isset($alpineConfig) && is_string($alpineConfig) ? $alpineConfig : '{}';

// Dana talangan aktif (Fase 6): kewajiban yang dikembalikan sesi ini. Fee-nya
// dibebankan hanya pada hak pengelola (lihat ringkasan bagi hasil).
$bridge = is_array($bridge ?? null) ? $bridge : [];
$bridgeRows = is_array($bridge['rows'] ?? null) ? $bridge['rows'] : [];
$bridgeCount = isset($bridge['count']) ? (int) $bridge['count'] : 0;
?>
<?php if ($error !== null): ?>
    <div class="af-alert af-alert--error" role="alert"><?= Security::e($error) ?></div>
<?php endif; ?>

<?php if ($bridgeCount > 0): ?>
    <!--
        Fase 6 — kewajiban dana talangan yang harus dikembalikan sesi ini.
        Ditempatkan SEBELUM form supaya operator melihatnya sebelum mengisi
        angka penjualan: pengembalian dibayar dari Kas Meja Bakar setelah
        alokasi, dan fee-nya hanya menggerus hak pengelola.
    -->
    <section class="af-card mb-5">
        <h2 class="af-card__title">Talangan Aktif &amp; Beban Fee</h2>
        <p class="af-card__subtitle">
            Pokok + fee kembali ke pemberi dana pada tutup buku ini (T+1).
        </p>

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
                    </p>
                </li>
            <?php endforeach; ?>
        </ul>

        <p class="mt-3 flex items-baseline justify-between gap-3 border-t border-surface-800 pt-3 text-sm">
            <span class="text-muted">Total kembali dari Kas Meja Bakar</span>
            <span class="af-num font-semibold text-emerald-cash">
                <?= Security::e($bridge['total_label'] ?? '-') ?>
            </span>
        </p>

        <p class="mt-3 rounded-xl border border-gold-primary/30 bg-gold-primary/5 px-4 py-3 text-xs text-gold-glow">
            Fee talangan membebani <strong>hak pengelola (80 %)</strong> saja — pool mitra (20 %)
            tidak tersentuh, dan pokok talangan tidak dihitung sebagai pendapatan toko.
        </p>
    </section>
<?php endif; ?>

<section class="af-card">
    <div class="af-glow" aria-hidden="true"></div>

    <h1 class="af-card__title">Rekonsiliasi Penjualan Toko</h1>
    <p class="af-card__subtitle">Tutup buku harian: ubah emas semalam menjadi kas yang siap diputar.</p>

    <dl class="af-facts mt-4">
        <div>
            <dt>Emas semalam</dt>
            <dd class="af-num text-gold-primary"><?= Security::e($batch['gram_label'] ?? '-') ?></dd>
        </div>
        <div>
            <dt>Nota holding</dt>
            <dd class="af-num"><?= Security::e($batch['count'] ?? 0) ?></dd>
        </div>
        <div>
            <dt>Modal belanja hulu</dt>
            <dd class="af-num text-pure"><?= Security::e($batch['cost_label'] ?? '-') ?></dd>
        </div>
    </dl>

    <div class="mt-4 grid gap-2 border-t border-surface-800 pt-3 text-sm sm:grid-cols-2">
        <p class="flex items-baseline justify-between gap-3">
            <span class="text-muted">Saldo <?= Security::e($teller['name'] ?? 'Kas Meja Bakar') ?></span>
            <span class="af-num font-semibold text-emerald-cash"><?= Security::e($teller['saldo_label'] ?? '-') ?></span>
        </p>
        <p class="flex items-baseline justify-between gap-3">
            <span class="text-muted">Saldo <?= Security::e($brankas['name'] ?? 'Brankas Utama') ?></span>
            <span class="af-num font-semibold text-gold-primary"><?= Security::e($brankas['saldo_label'] ?? '-') ?></span>
        </p>
    </div>

    <?php if ((bool) ($deficit['active'] ?? false)): ?>
        <p class="mt-4 rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-xs text-red-200">
            Ada defisit berjalan <span class="af-num font-semibold"><?= Security::e($deficit['label'] ?? '-') ?></span>
            dari hari minus sebelumnya. Defisit dipotong lebih dulu dari laba hari ini — modal investor tidak disentuh.
        </p>
    <?php endif; ?>
</section>

<?php if ($emptyBatch): ?>
    <section class="af-card">
        <h2 class="af-card__title">Belum ada emas untuk disettle</h2>
        <p class="af-card__subtitle">
            Tutup buku hanya dapat dieksekusi bila ada transaksi hulu berstatus <em>holding</em>.
            Catat pembelian di meja kasir terlebih dahulu.
        </p>
    </section>
<?php else: ?>
    <section class="af-card" x-data="settlementForm(<?= Security::e($alpineConfig) ?>)">
        <h2 class="af-card__title">Hasil Penjualan ke Toko</h2>
        <p class="af-card__subtitle">Isi angka dari timbangan air dan nota toko, lalu lampirkan fotonya.</p>

        <form
            x-ref="form"
            method="post"
            action="<?= Security::e(App::url('operator/settlement')) ?>"
            enctype="multipart/form-data"
            class="mt-4 grid gap-4"
            novalidate
            data-confirm="Eksekusi settlement harian dan kunci bagi hasil 80 : 20?"
            @keydown.enter.prevent
            @submit="onSubmit()"
        >
            <?= Security::csrfField() ?>
            <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">

            <label class="block">
                <span class="af-label">Total Berat Dijual Toko (gram)</span>
                <!--
                    Tanpa `placeholder`: field ini SELALU terisi dari peladen
                    (total gram holding). Placeholder numerik di sini hanya
                    tampak bila operator mengosongkannya, dan angka contohnya
                    mudah disangka sebagai data yang sudah terisi.
                -->
                <input
                    x-model.fill="soldGrams"
                    @input="refreshSuggestion()"
                    type="text"
                    name="sold_grams"
                    value="<?= Security::e($soldValue) ?>"
                    inputmode="decimal"
                    autocomplete="off"
                    class="af-input"
                    :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                    :readonly="submitting"
                >
                <span class="mt-1 block text-[0.7rem] text-muted">
                    Maksimal <span class="af-num"><?= Security::e($batch['gram_label'] ?? '-') ?></span>
                    (emas berstatus holding).
                </span>
            </label>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="af-label">
                        Kadar Timbang Air Toko (%)
                        <span class="text-[0.68rem] font-semibold uppercase tracking-[0.16em] text-gold-glow">wajib</span>
                    </span>
                    <input
                        x-model.fill="densityPercent"
                        @input="refreshSuggestion()"
                        type="text"
                        name="density_pct"
                        value="<?= Security::e($densityValue) ?>"
                        inputmode="decimal"
                        autocomplete="off"
                        placeholder="mis. 93,50"
                        class="af-input"
                        :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                        :readonly="submitting"
                    >
                </label>

                <label class="block">
                    <span class="af-label">
                        Harga Jual Riil / Gram
                        <span class="text-[0.68rem] font-semibold uppercase tracking-[0.16em] text-gold-glow">wajib</span>
                    </span>
                    <input
                        x-model.fill="shopPrice"
                        @input="refreshSuggestion()"
                        type="text"
                        name="shop_price_per_g"
                        value="<?= Security::e($priceValue) ?>"
                        inputmode="numeric"
                        autocomplete="off"
                        placeholder="mis. 2.080.000"
                        class="af-input"
                        :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                        :readonly="submitting"
                    >
                </label>
            </div>

            <label class="block">
                <span class="af-label">Potongan Koordinasi / Aparat Hari Ini</span>
                <input
                    x-model.fill="coordFee"
                    @input="refreshSuggestion()"
                    type="text"
                    name="coord_fee"
                    value="<?= Security::e($coordValue) ?>"
                    inputmode="numeric"
                    autocomplete="off"
                    placeholder="mis. 200.000"
                    class="af-input af-input--sm"
                    :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                    :readonly="submitting"
                >
            </label>

            <div>
                <span class="af-label">Bukti Nota Fisik Toko</span>
                <!--
                    `readonly` diabaikan peramban untuk `type="file"` (begitu pula untuk
                    kotak centang), jadi di sini kelas redup yang menandai "sedang dikirim".
                    Yang WAJIB: jangan pernah `:disabled` — kontrol `disabled` dikeluarkan
                    peramban dari payload, sehingga berkas nota (dan seluruh isian lain)
                    tidak pernah sampai ke peladen.
                -->
                <input
                    x-ref="receipt"
                    @change="onReceiptChange($event)"
                    type="file"
                    name="receipt_photo"
                    accept="image/*"
                    capture="environment"
                    class="block min-h-touch w-full cursor-pointer rounded-xl border border-surface-800 bg-surface-800 px-3 py-2.5 text-sm text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-gold-primary file:px-3 file:py-2 file:text-sm file:font-semibold file:text-obsidian-950"
                    :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                    :readonly="submitting"
                >
                <span class="mt-1 block text-[0.7rem] text-muted">
                    Foto dikompresi otomatis di peramban menuju target &lt; 250 KB sebelum diunggah.
                </span>
                <span x-cloak x-show="compressing" class="mt-1 block text-xs font-semibold text-gold-glow">
                    Mengompresi foto nota&hellip;
                </span>
                <span x-cloak x-show="receiptInfo !== ''" class="af-num mt-1 block text-xs text-emerald-cash" x-text="receiptInfo"></span>
                <span x-cloak x-show="receiptWarning !== ''" class="mt-1 block text-xs font-semibold text-red-300" x-text="receiptWarning"></span>
            </div>

            <!-- Pratinjau kalkulasi (Alpine.js). Server menghitung ulang. -->
            <dl class="mt-1 grid gap-1.5 rounded-xl border border-surface-800 bg-surface-800/40 p-4 text-sm">
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-muted">Kas masuk toko</dt>
                    <dd class="af-num text-pure" x-text="revenueLabel">Rp 0</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-muted">Modal belanja hulu</dt>
                    <dd class="af-num text-muted">- <?= Security::e($batch['cost_label'] ?? '-') ?></dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-muted">Laba kotor</dt>
                    <dd class="af-num text-pure" x-text="grossLabel">Rp 0</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-muted">Beban aparat</dt>
                    <dd class="af-num text-muted" x-text="coordFeeLabel">- Rp 0</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-muted">Pemulihan defisit</dt>
                    <dd class="af-num text-muted" x-text="deficitLabel">- Rp 0</dd>
                </div>
            </dl>

            <div class="rounded-xl border border-emerald-cash/30 bg-emerald-cash/5 p-4">
                <p class="text-[0.68rem] font-semibold uppercase tracking-[0.16em] text-muted">
                    Laba bersih putaran
                </p>
                <p class="af-num mt-1 text-3xl font-bold text-emerald-cash" x-text="netLabel">Rp 0</p>

                <div class="mt-3 grid gap-1.5 border-t border-emerald-cash/20 pt-3 text-sm">
                    <p class="flex items-baseline justify-between gap-3">
                        <span class="text-muted">Hak pengelola (80 %)</span>
                        <span class="af-num font-semibold text-pure" x-text="operatorLabel">Rp 0</span>
                    </p>
                    <!--
                        Fase 6: fee dana talangan hanya menggerus hak pengelola.
                        Kedua baris ini dikendalikan `bridgeHasActive` (jumlah
                        talangan aktif dari PELADEN) sehingga tidak pernah
                        bergantung pada laba sesi ini: pada hari sehat barisnya
                        tetap tampil ("Hak pengelola 80 % - fee = bersih").
                        Hanya peringatan di bawah yang mengikuti angka laba.
                    -->
                    <p
                        x-cloak
                        x-show="bridgeHasActive"
                        class="flex items-baseline justify-between gap-3"
                    >
                        <span class="text-muted">
                            Fee talangan (<span x-text="bridgeCountLabel">0</span>)
                        </span>
                        <span class="af-num font-semibold text-red-300" x-text="bridgeFeeLabel">- Rp 0</span>
                    </p>
                    <p
                        x-cloak
                        x-show="bridgeHasActive"
                        class="flex items-baseline justify-between gap-3"
                    >
                        <span class="text-muted">Hak pengelola bersih</span>
                        <span class="af-num font-semibold text-pure" x-text="operatorTakeHomeLabel">Rp 0</span>
                    </p>
                    <p class="flex items-baseline justify-between gap-3">
                        <span class="text-muted">Pool investor (20 %)</span>
                        <span class="af-num font-semibold text-gold-primary" x-text="poolLabel">Rp 0</span>
                    </p>
                    <p
                        x-cloak
                        x-show="bridgeWarnUncovered"
                        class="text-[0.7rem] leading-relaxed text-red-200"
                    >
                        Sebagian fee talangan (<span class="af-num" x-text="bridgeUncoveredLabel">Rp 0</span>)
                        melebihi hak laba pengelola dan dibayar dari kas meja — pool mitra tetap utuh.
                    </p>
                </div>
            </div>

            <!--
                Smart Auto-Split: saran sistem, tetapi tetap milik operator.
                Jumlah kedua isian wajib sama dengan kas yang diterima.
            -->
            <div class="rounded-xl border border-gold-primary/30 bg-gold-primary/5 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-[0.68rem] font-semibold uppercase tracking-[0.16em] text-gold-glow">
                            Alokasi Kas Fisik (Smart Auto-Split)
                        </p>
                        <p class="mt-1 text-xs text-muted">
                            Kas diterima <span class="af-num font-semibold text-pure" x-text="totalCashLabel">Rp 0</span>
                        </p>
                    </div>
                    <button
                        type="button"
                        class="af-btn af-btn--ghost px-3 text-xs"
                        @click.prevent="restoreAutoSplit(true)"
                        :disabled="submitting"
                    >
                        Saran otomatis
                    </button>
                </div>

                <div class="mt-3 grid gap-3">
                    <label class="block">
                        <span class="af-label">Kembali ke Kas Meja Bakar</span>
                        <input
                            x-model.fill="kasInput"
                            @input="splitTouched = true"
                            type="text"
                            name="kas_split"
                            value="<?= Security::e($kasValue) ?>"
                            inputmode="numeric"
                            autocomplete="off"
                            placeholder="mis. 35.157.500"
                            class="af-input af-input--sm"
                            :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                            :readonly="submitting"
                        >
                        <span class="mt-1 block text-[0.7rem] text-muted">
                            Saran sistem: <span class="af-num" x-text="suggestedKasLabel">-</span>
                        </span>
                    </label>

                    <label class="block">
                        <span class="af-label">Laba Bersih ke Brankas Utama</span>
                        <input
                            x-model.fill="brankasInput"
                            @input="splitTouched = true"
                            type="text"
                            name="brankas_split"
                            value="<?= Security::e($brankasValue) ?>"
                            inputmode="numeric"
                            autocomplete="off"
                            placeholder="mis. 2.186.500"
                            class="af-input af-input--sm"
                            :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                            :readonly="submitting"
                        >
                        <span class="mt-1 block text-[0.7rem] text-muted">
                            Saran sistem: <span class="af-num" x-text="suggestedBrankasLabel">-</span>
                        </span>
                    </label>
                </div>

                <p class="mt-3 flex items-baseline justify-between gap-3 border-t border-gold-primary/20 pt-3 text-xs">
                    <span class="text-muted">Sisa yang harus dialokasikan</span>
                    <span
                        class="af-num font-semibold"
                        :class="remainingCents === 0 ? 'text-emerald-cash' : 'text-red-300'"
                        x-text="remainingLabel"
                    >Rp 0</span>
                </p>

                <p x-cloak x-show="!splitBalanced" class="mt-2 text-xs text-red-300">
                    Jumlah kedua isian harus sama dengan kas yang diterima. Perbaiki atau tekan &ldquo;Saran otomatis&rdquo;.
                </p>

                <p x-cloak x-show="soldMilligrams > holdingMg" class="mt-2 text-xs text-red-300">
                    Berat jual melebihi emas holding (<span class="af-num" x-text="holdingLabel"></span>).
                </p>
            </div>

            <button
                type="submit"
                class="af-btn af-btn--primary af-btn--block"
                :disabled="!canSubmit || submitting"
            >
                Eksekusi Settlement Harian
            </button>

            <noscript>
                <button type="submit" class="af-btn af-btn--primary af-btn--block">
                    Eksekusi Settlement Harian
                </button>
                <p class="mt-2 text-xs text-muted">
                    JavaScript nonaktif: biarkan kedua isian alokasi kosong agar sistem memakai saran otomatisnya.
                </p>
            </noscript>
        </form>
    </section>

    <section class="af-card">
        <h2 class="af-card__title">Catatan Tutup Buku</h2>
        <ul class="mt-3 grid gap-1.5 text-xs text-muted">
            <li>
                Modal belanja hulu (<span class="af-num"><?= Security::e($batch['cost_label'] ?? '-') ?></span>)
                kembali ke <?= Security::e($teller['name'] ?? 'Kas Meja Bakar') ?>;
                laba bersih ke <?= Security::e($brankas['name'] ?? 'Brankas Utama') ?>.
                Keduanya dapat disesuaikan sebelum eksekusi.
            </li>
            <li>Settlement hari ini: <span class="af-num"><?= Security::e($todayCount) ?></span> sesi.</li>
            <?php if ($lastSettlement !== null): ?>
                <li>
                    Sesi terakhir <span class="af-num"><?= Security::e($lastSettlement['tanggal'] ?? '-') ?></span>
                    &middot; pendapatan <span class="af-num"><?= Security::e($lastSettlement['revenue_label'] ?? '-') ?></span>
                    &middot; laba bersih <span class="af-num"><?= Security::e($lastSettlement['net_label'] ?? '-') ?></span>
                    &middot; nota <?= ($lastSettlement['has_receipt'] ?? false) ? 'terlampir' : 'tanpa berkas' ?>.
                </li>
            <?php endif; ?>
            <li>Bagi hasil 80 : 20 dicatat sebagai pembukuan; distribusi ke tiap dompet investor dilakukan pada fase berikutnya.</li>
        </ul>
    </section>
<?php endif; ?>
