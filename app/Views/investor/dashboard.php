<?php

declare(strict_types=1);

/*
 * Layar D — Dasbor Privat Investor ("The Luxury Blind Dashboard", Fase 5).
 *
 * Isolasi data adalah alasan utama halaman ini ada:
 *   - Seluruh angka berasal dari `InvestorService::dashboard()` (dompet sesi).
 *   - Tidak ada omzet, gramatur, nota, nama penambang, laba pengelola, kuota
 *     serapan, maupun baris mitra lain — di layar maupun di HTML sumbernya.
 *   - Nama mitra lain hanya muncul pada layar operator, tidak di sini.
 *
 * Kontrak form (sama dengan Layar B/C/E):
 *   - Nominal dikirim mentah; peladen menghitung ulang seluruh kebijakan.
 *   - Isian tidak pernah `:disabled` (peramban mengeluarkan kontrol `disabled`
 *     dari data form). Saat mengirim: `:readonly="submitting"` + kelas redup;
 *     `:disabled` hanya untuk `<button>`.
 *   - Konfirmasi memakai `data-confirm` (pola yang sudah dipakai tombol Keluar),
 *     dengan teks jatuh tempo **dirender peladen** — tidak ada cermin tanggal di
 *     peramban yang bisa menyimpang dari `CapitalService::workingDaysDueDate()`.
 *   - Estimasi H-7 diperbarui Alpine ketika jenis penarikan berganti; teks awal
 *     tetap versi peladen sehingga benar tanpa JavaScript.
 *
 * Data dari InvestorController: $portfolio, $setupError, $input, $error,
 * $formToken, $mitra, $withdrawalTypes.
 */

use AurumFlow\Config\App;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\WithdrawalRequest;

$portfolio = is_array($portfolio ?? null) ? $portfolio : null;
$setupError = isset($setupError) && is_string($setupError) ? $setupError : null;
$error = isset($error) && is_string($error) && $error !== '' ? $error : null;
$input = is_array($input ?? null) ? $input : [];
$formToken = isset($formToken) && is_string($formToken) ? $formToken : '';
$withdrawalTypes = is_array($withdrawalTypes ?? null) ? $withdrawalTypes : [];

$value = static function (string $field) use ($input): string {
    $raw = $input[$field] ?? '';

    return is_string($raw) ? $raw : '';
};

$history = is_array($portfolio['history'] ?? null) ? $portfolio['history'] : [];
$requests = is_array($portfolio['requests'] ?? null) ? $portfolio['requests'] : [];

// Lebar bar alokasi memakai string desimal dari peladen (`31.02`), bukan float.
$ratioWidth = is_array($portfolio) && isset($portfolio['ratio_decimal']) && is_string($portfolio['ratio_decimal'])
    ? $portfolio['ratio_decimal'] . '%'
    : '0%';

$alpineConfig = json_encode([
    'profit_label'  => (string) ($portfolio['available_profit_label'] ?? ''),
    'capital_label' => (string) ($portfolio['available_capital_label'] ?? ''),
    'capital_due'   => (string) ($portfolio['capital_due_label'] ?? '—'),
    'initial_type'  => $value('withdrawal_type') === WithdrawalRequest::TYPE_CAPITAL
        ? WithdrawalRequest::TYPE_CAPITAL
        : WithdrawalRequest::TYPE_PROFIT,
], JSON_THROW_ON_ERROR);
?>
<?php if ($error !== null): ?>
    <div class="af-alert af-alert--error" role="alert"><?= Security::e($error) ?></div>
<?php endif; ?>

<?php if ($setupError !== null): ?>
    <section class="af-card">
        <h1 class="af-card__title">Portofolio belum siap</h1>
        <p class="af-card__subtitle"><?= Security::e($setupError) ?></p>
    </section>
<?php else: ?>
    <section class="relative overflow-hidden rounded-2xl border border-gold-primary/20 bg-surface-900/90 p-5 shadow-luxury backdrop-blur-xl">
        <div class="af-glow" aria-hidden="true"></div>

        <p class="text-xs font-semibold uppercase tracking-wider text-muted">Total Nilai Portofolio</p>
        <p class="af-num mt-1 text-3xl font-bold text-gold-primary"><?= Security::e($portfolio['total_label'] ?? '-') ?></p>

        <div class="mt-4">
            <div class="mb-1.5 flex justify-between text-xs font-medium text-muted">
                <span>Modal Berputar (<?= Security::e($portfolio['ratio_label'] ?? '-') ?>)</span>
                <span class="af-num text-pure"><?= Security::e($portfolio['rotating_label'] ?? '-') ?></span>
            </div>
            <div class="h-2 w-full overflow-hidden rounded-full bg-slate-800">
                <div class="h-full rounded-full bg-gradient-to-r from-gold-primary to-amber-500"
                     style="width: <?= Security::e($ratioWidth) ?>"></div>
            </div>
            <div class="mt-2 flex justify-between text-xs text-muted">
                <span>Kas Cadangan</span>
                <span class="af-num text-pure"><?= Security::e($portfolio['reserve_label'] ?? '-') ?></span>
            </div>
        </div>

        <p class="mt-3 text-[0.68rem] leading-relaxed text-muted">
            Modal pokok: <span class="af-num text-pure"><?= Security::e($portfolio['capital_label'] ?? '-') ?></span>
            &middot; Dividen belum ditarik:
            <span class="af-num text-pure"><?= Security::e($portfolio['profit_label'] ?? '-') ?></span>
            <?php if (($portfolio['has_rounds'] ?? false) && ($portfolio['latest_date_label'] ?? null) !== null): ?>
                &middot; putaran terakhir <?= Security::e($portfolio['latest_date_label']) ?>
            <?php endif; ?>
        </p>
    </section>

    <div class="grid gap-3 sm:grid-cols-2">
        <section class="af-card">
            <p class="af-label">Dividen Bulan Ini</p>
            <p class="af-num mt-1 text-xl font-bold text-emerald-cash"><?= Security::e($portfolio['month_profit_label'] ?? '-') ?></p>
            <p class="mt-1 text-[0.68rem] text-muted">
                <?= ($portfolio['month_profit_positive'] ?? false)
                    ? 'Terverifikasi dari putaran yang sudah dibukukan'
                    : 'Belum ada imbal hasil pada bulan ini' ?>
            </p>
        </section>

        <section class="af-card">
            <p class="af-label">Performa (ROI Bulan Ini)</p>
            <p class="af-num mt-1 text-xl font-bold text-gold-primary"><?= Security::e($portfolio['roi_label'] ?? '—') ?></p>
            <p class="mt-1 text-[0.68rem] text-muted">Dihitung atas modal pokok Anda</p>
        </section>
    </div>

    <section class="af-card">
        <h2 class="af-card__title">Tindakan Dana</h2>
        <p class="af-card__subtitle">
            Pengajuan Anda dicatat di sini; pencairan dilakukan pengelola.
        </p>

        <form
            x-data="investorDashboard(<?= Security::e($alpineConfig) ?>)"
            method="post"
            action="<?= Security::e(App::url('investor/withdrawal')) ?>"
            class="mt-4 grid gap-3"
            novalidate
            @submit="submitting = true"
        >
            <?= Security::csrfField() ?>
            <input type="hidden" name="<?= Security::e(Security::FORM_FIELD) ?>" value="<?= Security::e($formToken) ?>">

            <div class="grid gap-2 sm:grid-cols-2">
                <button type="button" class="af-btn af-btn--ghost py-3 text-sm"
                        @click="choose('<?= Security::e(WithdrawalRequest::TYPE_PROFIT) ?>')">
                    Tarik Keuntungan
                </button>
                <button type="button" class="af-btn af-btn--ghost py-3 text-sm"
                        @click="choose('<?= Security::e(WithdrawalRequest::TYPE_CAPITAL) ?>')">
                    Tarik Modal
                </button>
            </div>

            <label class="block">
                <span class="af-label">Jenis Penarikan</span>
                <select name="withdrawal_type" x-model="type" class="af-input af-input--sm" :readonly="submitting">
                    <?php foreach ($withdrawalTypes as $option): ?>
                        <option value="<?= Security::e($option['value'] ?? '') ?>"
                            <?php if ($value('withdrawal_type') === ($option['value'] ?? '')): ?>selected<?php endif; ?>
                        ><?= Security::e($option['label'] ?? '') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="block">
                <span class="af-label">Nominal</span>
                <input
                    type="text"
                    name="withdrawal_amount"
                    x-ref="amountField"
                    x-model="amount"
                    inputmode="numeric"
                    value="<?= Security::e($value('withdrawal_amount')) ?>"
                    placeholder="mis. 500.000"
                    class="af-input af-input--sm"
                    :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                    :readonly="submitting"
                    required
                >
            </label>

            <div class="flex flex-wrap items-center justify-between gap-2 text-[0.68rem] text-muted">
                <button type="button" class="af-btn af-btn--ghost px-2 py-1 text-[0.68rem]" @click="fillAll()">
                    Isi seluruh dana yang tersedia
                </button>
                <span class="af-num" x-text="availableText"></span>
            </div>

            <p class="text-[0.68rem] leading-relaxed text-muted" x-text="dueText"></p>

            <label class="block">
                <span class="af-label">Catatan (opsional)</span>
                <input
                    type="text"
                    name="withdrawal_note"
                    value="<?= Security::e($value('withdrawal_note')) ?>"
                    maxlength="120"
                    placeholder="mis. kebutuhan sekolah"
                    class="af-input af-input--sm"
                    :class="{ 'opacity-70 cursor-not-allowed': submitting }"
                    :readonly="submitting"
                >
            </label>

            <button
                type="submit"
                class="af-btn af-btn--primary af-btn--block"
                :disabled="submitting"
                :data-confirm="confirmText"
                data-confirm="Ajukan penarikan dana ini? Pengelola akan memprosesnya."
            >
                Kirim Pengajuan
            </button>
        </form>
    </section>

    <section class="af-card">
        <h2 class="af-card__title">Catatan Imbal Hasil Terakhir</h2>

        <?php if ($history === []): ?>
            <p class="af-card__subtitle">Belum ada putaran modal yang tercatat untuk akun Anda.</p>
        <?php else: ?>
            <table class="af-table mt-3">
                <thead>
                    <tr>
                        <th scope="col">Tanggal</th>
                        <th scope="col">Modal Putar</th>
                        <th scope="col">Imbal Hasil</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $row): ?>
                        <tr>
                            <td><?= Security::e($row['date_label'] ?? '') ?></td>
                            <td class="af-num"><?= Security::e($row['utilized_label'] ?? '') ?></td>
                            <td class="af-num text-emerald-cash"><?= Security::e($row['profit_label'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="af-card">
        <h2 class="af-card__title">Status Pengajuan</h2>

        <?php if ($requests === []): ?>
            <p class="af-card__subtitle">Belum ada pengajuan penarikan.</p>
        <?php else: ?>
            <ul class="mt-3 grid gap-2 text-xs">
                <?php foreach ($requests as $request): ?>
                    <li class="rounded-xl border border-surface-800 bg-surface-800/40 p-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold text-pure"><?= Security::e($request['type_label'] ?? '') ?></span>
                            <span class="af-num text-pure"><?= Security::e($request['amount_label'] ?? '') ?></span>
                        </div>
                        <div class="mt-1 flex flex-wrap items-center justify-between gap-2 text-muted">
                            <span>Diajukan <?= Security::e($request['created_label'] ?? '') ?>
                                &middot; Kas siap <?= Security::e($request['due_label'] ?? '') ?></span>
                            <span><?= Security::e($request['status_label'] ?? '') ?></span>
                        </div>
                        <p class="mt-1 text-[0.68rem] text-muted"><?= Security::e($request['payable_note'] ?? '') ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="af-card">
        <h2 class="af-card__title">Laporan</h2>
        <p class="af-card__subtitle">Bahasa ringkas, tanpa istilah akuntansi.</p>
        <div class="mt-3 grid gap-2 sm:grid-cols-2">
            <a class="af-btn af-btn--ghost py-3 text-sm" href="<?= Security::e(App::url('investor/report')) ?>">
                Lihat &amp; Cetak Laporan
            </a>
            <a class="af-btn af-btn--primary py-3 text-sm" href="<?= Security::e(App::url('investor/report/csv')) ?>">
                Unduh CSV
            </a>
        </div>
    </section>
<?php endif; ?>
