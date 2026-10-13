<?php

declare(strict_types=1);

/*
 * Layout halaman publik (login & onboarding) — tema "Obsidian Gold".
 *
 * Memuat $content, $title, dan $app_name. Dipakai tiga permukaan:
 *   - `/login` (operator & akun lama, kata sandi)
 *   - `/investor/login` (mitra, PIN 6 digit — Fase 5)
 *   - `/join` (klaim tautan undangan, Fase 5)
 *
 * Sejak Fase 5 layout ini juga menampilkan pesan flash: halaman onboarding
 * mengakhiri prosesnya dengan PRG ke pintu PIN, dan pesan suksesnya harus
 * terlihat di sana. Navigasi aplikasi tetap tidak ada — pengguna belum masuk.
 */

use AurumFlow\Config\App;
use AurumFlow\Helpers\Security;

$flash = is_array($flash ?? null) ? $flash : [];
$flashMessage = isset($flash['message']) && is_string($flash['message']) ? $flash['message'] : '';

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
    <link rel="apple-touch-icon" sizes="180x180" href="<?= Security::e(App::url('assets/icons/apple-touch-icon-180.png')) ?>">
</head>
<body class="flex min-h-screen items-center justify-center bg-obsidian-950 px-4 py-10" data-app-base="<?= Security::e(App::url()) ?>">
<main class="relative w-full max-w-sm overflow-hidden rounded-2xl border border-gold-primary/20 bg-surface-900/90 p-7 shadow-luxury backdrop-blur-xl">
    <div class="af-glow" aria-hidden="true"></div>

    <p class="text-center text-lg font-bold tracking-[0.26em] text-gold-primary">
        <?= Security::e($app_name ?? 'AurumFlow') ?>
    </p>
    <p class="mb-6 mt-1 text-center text-xs uppercase tracking-[0.18em] text-muted">
        Private Wealth Management
    </p>

    <?php if ($flashMessage !== ''): ?>
        <div class="af-alert <?= Security::e($flashClass) ?> mb-4" role="status"><?= Security::e($flashMessage) ?></div>
    <?php endif; ?>

    <?= $content ?>
</main>

<script src="<?= Security::e(App::url('assets/js/app.js')) ?>" defer></script>
<script src="<?= Security::e(App::url('assets/js/alpine.min.js')) ?>" defer></script>
</body>
</html>

