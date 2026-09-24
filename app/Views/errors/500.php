<?php

declare(strict_types=1);

/*
 * Halaman 500 — berdiri sendiri.
 *
 * View ini adalah jaring terakhir: ia tidak boleh bergantung pada database,
 * session, atau class apa pun selain Security (yang tidak punya dependensi
 * runtime) agar tidak pernah ikut gagal saat dirender.
 */

use AurumFlow\Helpers\Security;

$status = isset($status) ? (int) $status : 500;
$title = isset($title) && is_string($title) ? $title : 'Terjadi kesalahan';
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
    <p class="hint">
        Detail teknis sudah dicatat pada log server. Sertakan waktu kejadian
        saat menghubungi administrator.
    </p>
</main>
</body>
</html>
