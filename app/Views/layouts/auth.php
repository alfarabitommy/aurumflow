<?php

declare(strict_types=1);

/*
 * Layout halaman publik (login).
 *
 * Hanya memuat $content, $title, dan $app_name — sengaja tidak ada navigasi
 * karena pengguna belum terautentikasi.
 */

use AurumFlow\Config\App;
use AurumFlow\Helpers\Security;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Security::e($title ?? '') ?> — <?= Security::e($app_name ?? 'AurumFlow') ?></title>
    <link rel="stylesheet" href="<?= Security::e(App::url('assets/css/app.css')) ?>">
</head>
<body class="body--auth">
<main class="auth-card">
    <h1 class="auth-card__brand"><?= Security::e($app_name ?? 'AurumFlow') ?></h1>
    <?= $content ?>
</main>

<script src="<?= Security::e(App::url('assets/js/app.js')) ?>" defer></script>
</body>
</html>
