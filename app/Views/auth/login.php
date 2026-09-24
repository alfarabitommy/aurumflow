<?php

declare(strict_types=1);

/*
 * Form login.
 *
 * Identitas login adalah nomor telepon (sesuai ERD: users.phone_number).
 * Nilai yang ditampilkan berasal dari request sebelumnya, jadi SEMUA output
 * dinamis wajib melewati Security::e().
 */

use AurumFlow\Config\App;
use AurumFlow\Helpers\Security;

$error = isset($error) && is_string($error) && $error !== '' ? $error : null;
$phoneNumber = isset($phone_number) && is_string($phone_number) ? $phone_number : '';
?>
<?php if ($error !== null): ?>
    <div class="alert alert--error" role="alert"><?= Security::e($error) ?></div>
<?php endif; ?>

<form method="post" action="<?= Security::e(App::url('login')) ?>" class="form" novalidate>
    <?= Security::csrfField() ?>

    <label class="field">
        <span class="field__label">Nomor Telepon</span>
        <input
            type="tel"
            name="phone_number"
            value="<?= Security::e($phoneNumber) ?>"
            maxlength="20"
            inputmode="tel"
            autocomplete="tel"
            placeholder="08xxxxxxxxxx"
            autofocus
            required
        >
    </label>

    <label class="field">
        <span class="field__label">Password</span>
        <input
            type="password"
            name="password"
            autocomplete="current-password"
            required
        >
    </label>

    <button type="submit" class="btn btn--primary btn--block">Masuk</button>
</form>

<p class="hint">
    Akses bersifat privat dan undangan tertutup. Hubungi pengelola bila Anda
    belum memiliki akun.
</p>
