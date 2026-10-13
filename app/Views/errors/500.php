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
    <meta name="theme-color" content="#07080B">
    <title><?= Security::e($status) ?> — <?= Security::e($title) ?></title>
    <link rel="stylesheet" href="/assets/css/app.min.css">
</head>
<body class="flex min-h-screen items-center justify-center bg-obsidian-950 px-4 py-10">
<main class="relative w-full max-w-sm overflow-hidden rounded-2xl border border-gold-primary/20 bg-surface-900/90 p-7 text-center shadow-luxury">
    <div class="af-glow" aria-hidden="true"></div>
    <p class="af-num text-4xl font-bold text-gold-primary"><?= Security::e($status) ?></p>
    <h1 class="mt-2 text-lg font-bold text-pure"><?= Security::e($title) ?></h1>
    <p class="mt-2 text-sm text-muted"><?= Security::e($message) ?></p>
    <p class="mt-6 text-xs leading-relaxed text-muted">
        Detail teknis sudah dicatat pada log server. Sertakan waktu kejadian
        saat menghubungi administrator.
    </p>
</main>
</body>
</html>
