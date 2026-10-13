<?php

declare(strict_types=1);

/*
 * Layout meja kasir hulu (tema "Obsidian Gold", mobile-first).
 *
 * Variabel dari BaseController::render(): $content, $title, $app_name, $user,
 * $flash, plus data view kasir ($register, $holding). Variabel view memang
 * terlihat oleh layout karena BaseController me-require keduanya dalam satu
 * scope — sama seperti layout app.php yang memakai $user.
 *
 * Alasan layout terpisah: operator bekerja satu tangan di samping timbangan,
 * sehingga saldo kas harus selalu terlihat (header sticky) tanpa harus
 * menggulir, dan target sentuh dibuat lebih besar daripada halaman dashboard.
 */

use AurumFlow\Config\App;
use AurumFlow\Helpers\Security;

$register = is_array($register ?? null) ? $register : [];
$holding = is_array($holding ?? null) ? $holding : [];
$user = is_array($user ?? null) ? $user : [];
$flash = is_array($flash ?? null) ? $flash : [];
$flashMessage = isset($flash['message']) && is_string($flash['message']) ? $flash['message'] : '';

// Navigasi antar layar operator. Path aktif dibandingkan dengan REQUEST_URI
// supaya penanda "sedang dibuka" tetap benar pada pemasangan subdirektori.
$activePath = rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
$navItems = [
    ['path' => App::url('operator/teller'), 'label' => 'Kasir', 'active' => false],
    ['path' => App::url('operator/settlement'), 'label' => 'Rekonsiliasi', 'active' => false],
    ['path' => App::url('operator/capital'), 'label' => 'Kelola Modal', 'active' => false],
];

foreach ($navItems as $index => $item) {
    $itemPath = rtrim((string) $item['path'], '/');

    // Sub-halaman (mis. `/operator/capital/topup`) tetap menandai menu induknya.
    $navItems[$index]['active'] = $activePath === $itemPath
        || str_starts_with($activePath, $itemPath . '/');
}

$flashClass = match ((string) ($flash['kind'] ?? 'info')) {
    'success' => 'af-alert--success',
    'error'   => 'af-alert--error',
    default   => 'af-alert--info',
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#07080B">
    <title><?= Security::e($title ?? '') ?> — <?= Security::e($app_name ?? 'AurumFlow') ?></title>
    <link rel="stylesheet" href="<?= Security::e(App::url('assets/css/app.min.css')) ?>">
    <link rel="manifest" href="<?= Security::e(App::url('manifest.json')) ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= Security::e(App::url('assets/icons/icon-192.png')) ?>">
</head>
<body data-app-base="<?= Security::e(App::url()) ?>">
<header class="sticky top-0 z-20 border-b border-gold-primary/20 bg-surface-900/95 backdrop-blur-xl">
    <div class="mx-auto flex max-w-2xl items-center justify-between gap-2 px-4 py-2.5">
        <div class="leading-tight">
            <span class="block text-xs font-bold tracking-[0.22em] text-gold-primary">AURUMFLOW</span>
            <span class="block text-[0.7rem] uppercase tracking-[0.14em] text-muted">Meja Hulu</span>
        </div>

        <div class="flex items-center gap-2">
            <a class="af-btn af-btn--ghost px-3 text-xs"
               href="<?= Security::e(App::url('dashboard')) ?>">Dashboard</a>

            <form method="post" action="<?= Security::e(App::url('logout')) ?>" class="inline"
                  data-confirm="Keluar dari AurumFlow?">
                <?= Security::csrfField() ?>
                <button type="submit" class="af-btn af-btn--ghost px-3 text-xs">Keluar</button>
            </form>
        </div>
    </div>

    <!-- Navigasi antar layar operator (kasir malam <-> tutup buku pagi). -->
    <nav class="border-t border-surface-800 bg-obsidian-950/80">
        <div class="mx-auto flex max-w-2xl gap-2 px-4 py-2">
            <?php foreach ($navItems as $item): ?>
                <?php
                $navClass = $item['active']
                    ? 'af-btn bg-gold-primary px-3 text-xs text-obsidian-950'
                    : 'af-btn af-btn--ghost px-3 text-xs';
                $navAria = $item['active'] ? 'aria-current="page"' : '';
                ?>
                <a class="<?= Security::e($navClass) ?>" href="<?= Security::e($item['path']) ?>" <?= Security::e($navAria) ?>
                ><?= Security::e($item['label']) ?></a>
            <?php endforeach; ?>
        </div>
    </nav>

    <!-- Widget informasi #1: saldo kas aktif hari ini (kontras tinggi). -->
    <div class="border-t border-surface-800 bg-obsidian-950/80">
        <div class="mx-auto flex max-w-2xl items-end justify-between gap-3 px-4 py-3">
            <div>
                <p class="text-[0.68rem] font-semibold uppercase tracking-[0.16em] text-muted">
                    Saldo <?= Security::e($register['name'] ?? 'Kas') ?>
                </p>
                <p class="af-num text-2xl font-bold text-emerald-cash">
                    <?= Security::e($register['saldo_label'] ?? '-') ?>
                </p>
            </div>

            <div class="text-right">
                <p class="text-[0.68rem] font-semibold uppercase tracking-[0.16em] text-muted">Emas holding</p>
                <p class="af-num text-base font-semibold text-gold-primary">
                    <?= Security::e($holding['gram_label'] ?? '-') ?>
                </p>
                <p class="text-[0.7rem] text-muted">
                    <?= Security::e($holding['count'] ?? 0) ?> nota semalam
                </p>
            </div>
        </div>
    </div>
</header>

<main class="mx-auto grid max-w-2xl gap-5 px-4 py-5 pb-16">
    <?php if ($flashMessage !== ''): ?>
        <div class="af-alert <?= Security::e($flashClass) ?>" role="status"><?= Security::e($flashMessage) ?></div>
    <?php endif; ?>

    <?= $content ?>
</main>

<script src="<?= Security::e(App::url('assets/js/app.js')) ?>" defer></script>
<script src="<?= Security::e(App::url('assets/js/alpine.min.js')) ?>" defer></script>
</body>
</html>
