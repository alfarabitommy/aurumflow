<?php

declare(strict_types=1);

/*
 * Pintu PIN kasir meja bakar — `GET/POST /cashier/login` (Fase 7).
 *
 * Prinsip:
 *   - **Papan angka memakai KOMPONEN YANG SUDAH ADA** (`investorLogin`, terdaftar
 *     sejak Fase 5) — bukan `Alpine.data()` baru. Alasannya persis butir
 *     `AGENTS.md`: komponen baru dapat belum ada di `app.js` yang ter-cache
 *     peramban, sehingga `x-data` gagal init dan papan angka/tombol hilang.
 *     Karena `app.js` tidak berubah, `CACHE_NAME` pun tidak perlu dinaikkan.
 *   - PIN tidak pernah dikembalikan ke halaman (tidak ada `value` untuk field
 *     PIN), sedangkan nomor telepon dikembalikan agar tidak diketik ulang.
 *   - Papan angka hanyalah kemudahan di ponsel: isian tetap `<input>` biasa yang
 *     berfungsi tanpa JavaScript; pembatas percobaan dihitung peladen
 *     (`PinThrottle`), bukan peramban.
 *   - Seluruh output dinamis lewat `Security::e()`.
 *
 * Data dari CashierAuthController: $error, $phone_number, $pin_length.
 */

use AurumFlow\Config\App;
use AurumFlow\Helpers\Security;

$error = isset($error) && is_string($error) && $error !== '' ? $error : null;
$phoneNumber = isset($phone_number) && is_string($phone_number) ? $phone_number : '';
$pinLength = isset($pin_length) ? (int) $pin_length : 6;
?>
<?php if ($error !== null): ?>
    <div class="af-alert af-alert--error" role="alert"><?= Security::e($error) ?></div>
<?php endif; ?>

<form
    x-data="investorLogin({ pinLength: <?= (int) $pinLength ?> })"
    method="post"
    action="<?= Security::e(App::url('cashier/login')) ?>"
    class="grid gap-4"
    novalidate
    @submit="submitting = true"
>
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
            placeholder="mis. 081234567890"
            class="af-input af-input--sm"
            autofocus
            required
        >
    </label>

    <label class="block">
        <span class="af-label">PIN <?= Security::e($pinLength) ?> Angka</span>
        <input
            type="password"
            name="pin"
            x-ref="pinField"
            inputmode="numeric"
            pattern="[0-9]*"
            maxlength="<?= Security::e($pinLength) ?>"
            autocomplete="off"
            placeholder="<?= Security::e(str_repeat('•', $pinLength)) ?>"
            class="af-input af-input--sm text-center af-num tracking-[0.4em]"
            required
        >
    </label>

    <!-- Papan angka: hanya mempercepat pengisian di ponsel. -->
    <div class="grid grid-cols-3 gap-2" aria-hidden="true" x-cloak>
        <template x-for="digit in digits" :key="digit">
            <button type="button" class="af-btn af-btn--ghost py-3 text-base"
                    x-text="digit" @click="press(digit)"></button>
        </template>
        <button type="button" class="af-btn af-btn--ghost py-3 text-base"
                @click="erase()" x-text="'⌫'"></button>
    </div>

    <button type="submit" class="af-btn af-btn--primary af-btn--block" :disabled="submitting">
        Masuk Meja Kasir
    </button>
</form>

<p class="mt-5 text-center text-xs leading-relaxed text-muted">
    PIN terkunci atau lupa? Hubungi pengelola untuk memintanya direset.
</p>
