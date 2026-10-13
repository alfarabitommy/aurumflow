<?php

declare(strict_types=1);

/*
 * Layout portal mitra pemodal (Fase 5) — tema "Obsidian Gold", mobile-first.
 *
 * Mengapa layout terpisah dari `app.php`/`operator.php`:
 *   1. **Isolasi tampilan.** Mitra tidak boleh melihat satu pun item navigasi
 *      operasional (Kasir / Rekonsiliasi / Kelola Modal). Layout ini hanya
 *      memuat nama mitra dan tombol keluar.
 *   2. **PWA.** Halaman mitra adalah sasaran "Tambahkan ke Layar Utama",
 *      sehingga layout ini yang menautkan manifest + meta aplikasi.
 *
 * Variabel dari BaseController::render(): $content, $title, $app_name, $flash,
 * plus $mitra (nama & nomor) dari InvestorController.
 */

use AurumFlow\Config\App;
use AurumFlow\Helpers\Security;

$mitra = is_array($mitra ?? null) ? $mitra : [];
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
<body data-app-base="<?= Security::e(App::url()) ?>">
<header class="sticky top-0 z-20 border-b border-gold-primary/20 bg-surface-900/95 backdrop-blur-xl">
    <div class="mx-auto flex max-w-2xl items-center justify-between gap-3 px-4 py-3">
        <div class="leading-tight">
            <span class="block text-xs font-bold tracking-[0.22em] text-gold-primary">AURUMFLOW</span>
            <span class="block text-[0.7rem] uppercase tracking-[0.14em] text-muted">Private Portfolio</span>
        </div>

        <div class="text-right leading-tight">
            <span class="block text-xs font-semibold text-pure"><?= Security::e($mitra['name'] ?? '') ?></span>
            <form method="post" action="<?= Security::e(App::url('logout')) ?>" class="inline"
                  data-confirm="Keluar dari portofolio?">
                <?= Security::csrfField() ?>
                <button type="submit" class="mt-1 text-[0.7rem] font-semibold text-muted underline decoration-dotted transition hover:text-gold-primary">
                    Keluar
                </button>
            </form>
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
