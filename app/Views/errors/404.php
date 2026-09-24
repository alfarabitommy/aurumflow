<?php

declare(strict_types=1);

/*
 * Halaman 404 — berdiri sendiri (tanpa layout) karena dirender oleh
 * BaseController::abort() yang dapat dipanggil sebelum session/route normal
 * siap.
 */

use AurumFlow\Helpers\Security;

$status = isset($status) ? (int) $status : 404;
$title = isset($title) && is_string($title) ? $title : 'Halaman tidak ditemukan';
$message = isset($message) && is_string($message) ? $message : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Security::e($status) ?> — <?= Security::e($title) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="body--auth">
<main class="auth-card">
    <p class="error-code"><?= Security::e($status) ?></p>
    <h1 class="auth-card__brand"><?= Security::e($title) ?></h1>
    <p><?= Security::e($message) ?></p>
    <p><a class="btn btn--primary btn--block" href="/">Kembali ke halaman awal</a></p>
</main>
</body>
</html>
