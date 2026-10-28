<?php

declare(strict_types=1);

/*
 * Layout portal blind mitra lapangan (Fase 10) — tema "Obsidian Gold".
 *
 * Dipakai HANYA oleh `/officer/dashboard` dan `/officer/riwayat`. Berbeda dari
 * `layouts/operator.php`, layout ini tidak memuat widget apa pun milik
 * pengelola: tidak ada saldo pos, tidak ada emas, tidak ada tautan ke permukaan
 * operator/investor. Navigasinya dua tab, dan seluruh isinya berasal dari
 * `OfficerPortalService` (saldo hak + riwayat entri) ditambah identitas sesi.
 *
 * Variabel dari BaseController::render(): $content, $title, $app_name, $auth,
 * $flash, ditambah $identity/$summary/$entries dari OfficerPortalController.
 */

use AurumFlow\Config\App;
use AurumFlow\Helpers\Security;

$auth = is_array($auth ?? null) ? $auth : [];
$flash = is_array($flash ?? null) ? $flash : [];
$identity = is_array($identity ?? null) ? $identity : [];
$flashMessage = isset($flash['message']) && is_string($flash['message']) ? $flash['message'] : '';

$activePath = rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');

$navItems = [
    ['path' => App::url('officer/dashboard'), 'label' => 'Dasbor'],
    ['path' => App::url('officer/riwayat'), 'label' => 'Riwayat'],
];

foreach ($navItems as $index => $item) {
    $itemPath = rtrim((string) $item['path'], '/');

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
    <link rel="apple-touch-icon" sizes="180x180" href="<?= Security::e(App::url('assets/icons/apple-touch-icon-180.png')) ?>">
</head>
<body data-app-base="<?= Security::e(App::url()) ?>">
<header class="sticky top-0 z-20 border-b border-gold-primary/20 bg-surface-900/95 backdrop-blur-xl">
    <div class="mx-auto flex max-w-2xl flex-wrap items-center justify-between gap-2 px-4 py-2.5">
        <div class="leading-tight">
            <span class="block text-xs font-bold tracking-[0.22em] text-gold-primary">AURUMFLOW</span>
            <span class="block text-[0.7rem] uppercase tracking-[0.14em] text-muted">Portal Mitra Lapangan</span>
        </div>

        <div class="flex items-center gap-2">
            <span class="block min-w-0 text-right text-[0.7rem] leading-tight">
                <span class="block truncate font-semibold text-pure">
                    <?= Security::e($identity['name'] ?? ($auth['name'] ?? '')) ?>
                </span>
                <span class="block truncate text-muted"><?= Security::e($auth['role_label'] ?? '') ?></span>
            </span>

            <form method="post" action="<?= Security::e(App::url('logout')) ?>" class="inline"
                  data-confirm="Keluar dari AurumFlow?">
                <?= Security::csrfField() ?>
                <button type="submit" class="af-btn af-btn--ghost px-3 text-xs">Keluar</button>
            </form>
        </div>
    </div>

    <nav class="border-t border-surface-800 bg-obsidian-950/80">
        <div class="mx-auto flex max-w-2xl gap-2 overflow-x-auto px-4 py-2">
            <?php foreach ($navItems as $item): ?>
                <?php
                $navClass = $item['active']
                    ? 'af-btn shrink-0 bg-gold-primary px-3 text-xs text-obsidian-950'
                    : 'af-btn af-btn--ghost shrink-0 px-3 text-xs';
                ?>
                <a class="<?= Security::e($navClass) ?>" href="<?= Security::e($item['path']) ?>"<?php if ($item['active']): ?> aria-current="page"<?php endif; ?>
                ><?= Security::e($item['label']) ?></a>
            <?php endforeach; ?>
        </div>
    </nav>
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
