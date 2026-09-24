<?php

declare(strict_types=1);

/*
 * Layout utama untuk halaman setelah login.
 *
 * Variabel yang tersedia (dikirim oleh BaseController::render()):
 *   $content   string  HTML hasil render view (SUDAH di-escape di view asal,
 *                      karena itu TIDAK di-escape lagi di sini)
 *   $title     string  judul halaman
 *   $app_name  string  nama aplikasi
 *   $user      array   data pengguna yang sedang login
 */

use AurumFlow\Config\App;
use AurumFlow\Helpers\Security;

$user = is_array($user ?? null) ? $user : [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Security::e($title ?? '') ?> — <?= Security::e($app_name ?? 'AurumFlow') ?></title>
    <link rel="stylesheet" href="<?= Security::e(App::url('assets/css/app.css')) ?>">
</head>
<body>
<header class="topbar">
    <div class="topbar__brand"><?= Security::e($app_name ?? 'AurumFlow') ?></div>

    <nav class="topbar__nav">
        <span class="topbar__user">
            <?= Security::e($user['name'] ?? '') ?>
            <small><?= Security::e($user['role_label'] ?? ($user['role'] ?? '')) ?></small>
        </span>

        <form method="post" action="<?= Security::e(App::url('logout')) ?>" class="inline-form" data-confirm="Keluar dari AurumFlow?">
            <?= Security::csrfField() ?>
            <button type="submit" class="btn btn--ghost">Keluar</button>
        </form>
    </nav>
</header>

<main class="container">
    <?= $content ?>
</main>

<script src="<?= Security::e(App::url('assets/js/app.js')) ?>" defer></script>
</body>
</html>
