<?php

declare(strict_types=1);

/*
 * Halaman 404 — berdiri sendiri (tanpa layout) karena dirender oleh
 * BaseController::abort() yang dapat dipanggil sebelum session/route normal
 * siap. Tema mengikuti "Obsidian Gold" agar tidak ada halaman putih menyilaukan
 * pada pemakaian malam di lapangan.
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
    <p class="mt-5">
        <a class="af-btn af-btn--primary af-btn--block" href="/">Kembali ke halaman awal</a>
    </p>
</main>
</body>
</html>
