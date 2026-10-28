<?php

declare(strict_types=1);

/*
 * Layar H — Dasbor blind mitra lapangan (Fase 10).
 *
 * Isi yang DIIZINKAN hanya: identitas petugas (nama + nomor tersamar), saldo
 * hak, ringkasan bulan berjalan (potongan / bonus / pencairan), dan riwayat
 * entri buku hak. Tidak ada tarif, tidak ada basis potongan, tidak ada angka
 * milik pengelola maupun mitra pemodal — lihat `OfficerPortalService`.
 *
 * Data dari OfficerPortalController: $identity, $summary, $entries.
 */

use AurumFlow\Config\App;
use AurumFlow\Helpers\Security;

$identity = is_array($identity ?? null) ? $identity : [];
$summary = is_array($summary ?? null) ? $summary : [];
$entries = is_array($entries ?? null) ? $entries : [];
$hasAccount = (bool) ($identity['has_account'] ?? false);
?>
<section class="af-card">
    <div class="af-glow" aria-hidden="true"></div>

    <h1 class="af-card__title">Saldo Hak Saya</h1>
    <p class="af-card__subtitle">
        <?= Security::e($identity['name'] ?? '') ?>
        <?php if (($identity['phone_masked'] ?? '') !== ''): ?>
            &middot; <span class="af-num"><?= Security::e($identity['phone_masked']) ?></span>
        <?php endif; ?>
    </p>

    <?php if (!$hasAccount): ?>
        <p class="mt-4 rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-xs text-red-200">
            Akun Anda belum terhubung ke buku hak. Hubungi pengelola untuk menyelesaikannya.
        </p>
    <?php else: ?>
        <div class="mt-4 rounded-xl border border-emerald-cash/30 bg-emerald-cash/5 p-4">
            <p class="text-[0.68rem] font-semibold uppercase tracking-[0.16em] text-muted">
                Saldo hak yang belum dicairkan
            </p>
            <p class="af-num mt-1 text-3xl font-bold text-emerald-cash">
                <?= Security::e($summary['balance_label'] ?? '-') ?>
            </p>
            <p class="mt-2 text-[0.7rem] text-muted">
                Diperbarui <?= Security::e($summary['updated_label'] ?? '-') ?> WIB.
                Pencairan dilakukan pengelola kapan pun Anda memintanya.
            </p>
        </div>

        <dl class="af-facts mt-4">
            <div>
                <dt>Potongan bulan ini</dt>
                <dd class="af-num text-pure"><?= Security::e($summary['month_cut_label'] ?? '-') ?></dd>
            </div>
            <div>
                <dt>Bonus bulan ini</dt>
                <dd class="af-num text-pure"><?= Security::e($summary['month_bonus_label'] ?? '-') ?></dd>
            </div>
            <div>
                <dt>Sudah dicairkan</dt>
                <dd class="af-num text-gold-primary"><?= Security::e($summary['month_paid_label'] ?? '-') ?></dd>
            </div>
        </dl>

        <p class="mt-3 text-[0.7rem] leading-relaxed text-muted">
            Saldo hak bertambah dari potongan harian dan bonus yang ditetapkan pengelola,
            lalu berkurang saat dicairkan. <span class="af-num"><?= Security::e((int) ($summary['month_count'] ?? 0)) ?></span>
            catatan pada bulan berjalan.
        </p>
    <?php endif; ?>
</section>

<section class="af-card">
    <h2 class="af-card__title">Buku Hak Terakhir</h2>
    <p class="af-card__subtitle">Lima puluh catatan terbaru. Buka tab Riwayat untuk daftar lengkap.</p>

    <?php if ($entries === []): ?>
        <p class="mt-3 text-sm text-muted">Belum ada catatan. Saldo hak akan muncul setelah tutup buku pertama.</p>
    <?php else: ?>
        <ul class="mt-3 divide-y divide-surface-800">
            <?php foreach ($entries as $entry): ?>
                <li class="grid gap-1 py-3">
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="truncate text-sm font-semibold text-pure">
                            <?= Security::e($entry['type_label'] ?? '') ?>
                        </p>
                        <p class="af-num shrink-0 text-sm font-semibold text-gold-primary">
                            <?= Security::e($entry['amount_label'] ?? '-') ?>
                        </p>
                    </div>
                    <div class="flex items-baseline justify-between gap-3 text-[0.7rem] text-muted">
                        <span><?= Security::e($entry['tanggal'] ?? '-') ?> WIB</span>
                        <span class="af-num">Saldo <?= Security::e($entry['balance_label'] ?? '-') ?></span>
                    </div>
                    <?php if (($entry['notes'] ?? '') !== ''): ?>
                        <p class="text-[0.7rem] leading-relaxed text-muted"><?= Security::e($entry['notes']) ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p class="mt-4">
        <a class="af-btn af-btn--ghost af-btn--block text-xs" href="<?= Security::e(App::url('officer/riwayat')) ?>">
            Buka Riwayat Lengkap
        </a>
    </p>
</section>
