<?php

declare(strict_types=1);

/*
 * Halaman error generik (mis. 400, 403, 409, 419).
 *
 * Dipakai BaseController::abort() untuk status yang tidak punya view
 * khusus. Sama seperti 404/500, view ini berdiri sendiri.
 */

use AurumFlow\Helpers\Security;

$status = isset($status) ? (int) $status : 400;
$title = isset($title) && is_string($title) ? $title : 'Permintaan tidak dapat diproses';
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
    <p><a class="btn btn--primary btn--block" href="/login">Kembali ke halaman masuk</a></p>
</main>
</body>
</html>
