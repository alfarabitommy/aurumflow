<?php

declare(strict_types=1);

/*
 * Form login — tema "Obsidian Gold".
 *
 * Identitas login adalah nomor telepon (sesuai ERD: users.phone_number).
 * Nilai yang ditampilkan berasal dari request sebelumnya, jadi SEMUA output
 * dinamis wajib melewati Security::e().
 *
 * Nama field, atribut method/action, dan hidden `_token` SENGAJA tidak
 * berubah dari Fase 1: QC HTTP (`qc_routing`, `qc_sql_injection`) menguji
 * ketiganya.
 */

use AurumFlow\Config\App;
use AurumFlow\Helpers\Security;

$error = isset($error) && is_string($error) && $error !== '' ? $error : null;
$phoneNumber = isset($phone_number) && is_string($phone_number) ? $phone_number : '';
?>
<?php if ($error !== null): ?>
    <div class="af-alert af-alert--error" role="alert"><?= Security::e($error) ?></div>
<?php endif; ?>

<form method="post" action="<?= Security::e(App::url('login')) ?>" class="grid gap-4" novalidate>
    <?= Security::csrfField() ?>

    <label class="block">
        <span class="af-label">Nomor Telepon</span>
        <input
            type="tel"
            name="phone_number"
            value="<?= Security::e($phoneNumber) ?>"
            maxlength="20"
            inputmode="tel"
            autocomplete="tel"
            placeholder="08xxxxxxxxxx"
            class="af-input af-input--sm"
            autofocus
            required
        >
    </label>

    <label class="block">
        <span class="af-label">Password</span>
        <input
            type="password"
            name="password"
            autocomplete="current-password"
            class="af-input af-input--sm"
            required
        >
    </label>

    <button type="submit" class="af-btn af-btn--primary af-btn--block">Masuk</button>
</form>

<p class="mt-5 text-center text-xs leading-relaxed text-muted">
    Akses bersifat privat dan undangan tertutup. Hubungi pengelola bila Anda
    belum memiliki akun.
</p>

<p class="mt-2 text-center text-xs">
    <a class="font-semibold text-gold-primary underline decoration-dotted"
       href="<?= Security::e(App::url('investor/login')) ?>">Masuk sebagai mitra (PIN 6 digit)</a>
</p>
