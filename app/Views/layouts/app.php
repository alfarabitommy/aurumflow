<?php

declare(strict_types=1);

/*
 * Layout utama untuk halaman setelah login (tema "Obsidian Gold").
 *
 * Variabel yang tersedia (dikirim oleh BaseController::render()):
 *   $content   string  HTML hasil render view (SUDAH di-escape di view asal,
 *                      karena itu TIDAK di-escape lagi di sini)
 *   $title     string  judul halaman
 *   $app_name  string  nama aplikasi
 *   $auth      array   identitas pengguna yang sedang login ({id,name,phone,role,role_label})
 *   $flash     array   pesan sekali pakai {kind, message} — boleh kosong
 */

use AurumFlow\Config\App;
use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Security;

$auth = is_array($auth ?? null) ? $auth : [];
$flash = is_array($flash ?? null) ? $flash : [];
$flashMessage = isset($flash['message']) && is_string($flash['message']) ? $flash['message'] : '';

$flashClass = match ((string) ($flash['kind'] ?? 'info')) {
    'success' => 'af-alert--success',
    'error'   => 'af-alert--error',
    default   => 'af-alert--info',
};

$isOperator = ($auth['role'] ?? '') === Constants::ROLE_OPERATOR;
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
<body data-app-base="<?= Security::e(App::url()) ?>">
<header class="sticky top-0 z-20 border-b border-gold-primary/20 bg-surface-900/90 backdrop-blur-xl">
    <div class="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-3 px-4 py-3">
        <span class="text-sm font-bold tracking-[0.22em] text-gold-primary">AURUMFLOW</span>

        <nav class="flex items-center gap-3">
            <a class="text-xs font-semibold text-muted transition hover:text-gold-primary"
               href="<?= Security::e(App::url('dashboard')) ?>">Dashboard</a>

            <?php if ($isOperator): ?>
                <a class="text-xs font-semibold text-muted transition hover:text-gold-primary"
                   href="<?= Security::e(App::url('operator/teller')) ?>">Kasir Hulu</a>
            <?php endif; ?>

            <span class="text-right text-xs leading-tight">
                <span class="block font-semibold text-pure"><?= Security::e($auth['name'] ?? '') ?></span>
                <span class="block text-muted"><?= Security::e($auth['role_label'] ?? '') ?></span>
            </span>

            <form method="post" action="<?= Security::e(App::url('logout')) ?>" class="inline"
                  data-confirm="Keluar dari AurumFlow?">
                <?= Security::csrfField() ?>
                <button type="submit" class="af-btn af-btn--ghost px-3 text-xs">Keluar</button>
            </form>
        </nav>
    </div>
</header>

<main class="mx-auto grid max-w-3xl gap-5 px-4 py-6 pb-16">
    <?php if ($flashMessage !== ''): ?>
        <div class="af-alert <?= Security::e($flashClass) ?>" role="status"><?= Security::e($flashMessage) ?></div>
    <?php endif; ?>

    <?= $content ?>
</main>

<script src="<?= Security::e(App::url('assets/js/app.js')) ?>" defer></script>
<script src="<?= Security::e(App::url('assets/js/alpine.min.js')) ?>" defer></script>
</body>
</html>
