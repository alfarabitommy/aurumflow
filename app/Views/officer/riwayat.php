<?php

declare(strict_types=1);

/*
 * Riwayat buku hak mitra lapangan (Fase 10) — `/officer/riwayat`.
 *
 * Hanya tanggal, jenis entri, nominal bertanda, saldo setelah entri, dan
 * catatan pengelola. Tanpa tarif, tanpa basis potongan, tanpa pos kas, tanpa
 * id mutasi — lihat `OfficerPortalService::history()`.
 *
 * Data dari OfficerPortalController: $identity, $summary, $entries.
 */

use AurumFlow\Config\App;
use AurumFlow\Helpers\Security;

$identity = is_array($identity ?? null) ? $identity : [];
$summary = is_array($summary ?? null) ? $summary : [];
$entries = is_array($entries ?? null) ? $entries : [];
?>
<section class="af-card">
    <div class="af-glow" aria-hidden="true"></div>

    <h1 class="af-card__title">Riwayat Hak Saya</h1>
    <p class="af-card__subtitle">
        <?= Security::e($identity['name'] ?? '') ?>
        &middot; saldo hak <span class="af-num text-emerald-cash"><?= Security::e($summary['balance_label'] ?? '-') ?></span>
    </p>

    <?php if ($entries === []): ?>
        <p class="mt-4 text-sm text-muted">Belum ada catatan pada buku hak Anda.</p>
    <?php else: ?>
        <ul class="mt-4 divide-y divide-surface-800">
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
        <a class="af-btn af-btn--ghost af-btn--block text-xs" href="<?= Security::e(App::url('officer/dashboard')) ?>">
            Kembali ke Dasbor
        </a>
    </p>
</section>
