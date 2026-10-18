<?php

declare(strict_types=1);

/*
 * Layout kerangka 2-tier pengelola (tema "Obsidian Gold", mobile-first).
 *
 * Variabel dari BaseController::render(): $content, $title, $app_name, $auth,
 * $flash, plus data view operator ($register, $holding). Variabel view memang
 * terlihat oleh layout karena BaseController me-require keduanya dalam satu
 * scope — sama seperti layout app.php yang memakai $auth.
 *
 * Baris 1 — identitas: logo + subjudul halaman, nama & label peran, tombol
 * Keluar. Baris 2 — navigasi tab tersegmen. Baris 3 — widget saldo kas & emas
 * holding (hanya bila view menyediakan datanya).
 *
 * Alasan baris 3 selalu terlihat: operator bekerja satu tangan di samping
 * timbangan, sehingga saldo kas harus terbaca tanpa menggulir (header sticky),
 * dan target sentuh dibuat lebih besar daripada halaman lain.
 *
 * Sejak Fase 7 layout ini melayani **dua peran** dan karena itu navigasinya
 * sadar peran: kasir hanya melihat meja kasir (satu-satunya permukaan yang
 * menerima perannya), sementara operator melihat seluruh tab. Penyembunyian
 * menu bersifat kosmetik — rute terlarangnya tetap dijaga peladen dan dijawab
 * `302` ke beranda peran tanpa tulisan.
 *
 * Sejak Fase 8 layout ini juga merender `/dashboard` (lihat
 * `plan/Plan_Unify_UI_AurumFlow.md`), sehingga seluruh halaman pengelola
 * memakai satu kerangka: tab pertama adalah Dashboard, subjudul baris 1
 * mengikuti `$title`, dan baris widget tidak dirender bila view dashboard tidak
 * mengirim `$register`/`$holding`.
 */

use AurumFlow\Config\App;
use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Security;

$register = is_array($register ?? null) ? $register : [];
$holding = is_array($holding ?? null) ? $holding : [];
$auth = is_array($auth ?? null) ? $auth : [];
$flash = is_array($flash ?? null) ? $flash : [];
$flashMessage = isset($flash['message']) && is_string($flash['message']) ? $flash['message'] : '';

// Identitas & peran sesi. Nilainya datang dari session (bukan formulir),
// disuntikkan BaseController::render() sebagai `$auth`.
$role = isset($auth['role']) && is_string($auth['role']) ? $auth['role'] : '';
$isCashier = $role === Constants::ROLE_CASHIER;

// Subjudul baris 1: operator mengikuti judul halaman (nilai peladen, di-escape
// saat dicetak), kasir tetap "Meja Hulu" supaya kebiasaan kerjanya tidak
// berubah (dokumen UI/UX § 7.2).
$headerSubtitle = $isCashier ? 'Meja Hulu' : (string) ($title ?? '');

// Navigasi antar layar operator. Path aktif dibandingkan dengan REQUEST_URI
// supaya penanda "sedang dibuka" tetap benar pada pemasangan subdirektori.
$activePath = rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');

$navItems = [];

if (!$isCashier) {
    // Tab pertama = beranda operator. Kasir tidak pernah menerimanya karena
    // `/dashboard` operator-only; penjaga sesungguhnya tetap di peladen
    // (`DashboardController::index()` → `requireRole(ROLE_OPERATOR)`).
    $navItems[] = ['path' => App::url('dashboard'), 'label' => 'Dashboard', 'active' => false];
}

$navItems[] = ['path' => App::url('operator/teller'), 'label' => 'Kasir', 'active' => false];

if (!$isCashier) {
    $navItems[] = ['path' => App::url('operator/settlement'), 'label' => 'Rekonsiliasi', 'active' => false];
    $navItems[] = ['path' => App::url('operator/capital'), 'label' => 'Kelola Modal', 'active' => false];
    $navItems[] = ['path' => App::url('operator/kasir'), 'label' => 'Kelola Kasir', 'active' => false];
}

foreach ($navItems as $index => $item) {
    $itemPath = rtrim((string) $item['path'], '/');

    // Sub-halaman (mis. `/operator/capital/topup`) tetap menandai menu induknya.
    $navItems[$index]['active'] = $activePath === $itemPath
        || str_starts_with($activePath, $itemPath . '/');
}

$flashClass = match ((string) ($flash['kind'] ?? 'info')) {
    'success' => 'af-alert--success',
    'error'   => 'af-alert--error',
    default   => 'af-alert--info',
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#07080B">
    <title><?= Security::e($title ?? '') ?> — <?= Security::e($app_name ?? 'AurumFlow') ?></title>
    <link rel="stylesheet" href="<?= Security::e(App::url('assets/css/app.min.css')) ?>">
    <link rel="manifest" href="<?= Security::e(App::url('manifest.json')) ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= Security::e(App::url('assets/icons/icon-192.png')) ?>">
</head>
<body data-app-base="<?= Security::e(App::url()) ?>">
<header class="sticky top-0 z-20 border-b border-gold-primary/20 bg-surface-900/95 backdrop-blur-xl">
    <div class="mx-auto flex max-w-2xl flex-wrap items-center justify-between gap-2 px-4 py-2.5">
        <div class="leading-tight">
            <span class="block text-xs font-bold tracking-[0.22em] text-gold-primary">AURUMFLOW</span>
            <span class="block text-[0.7rem] uppercase tracking-[0.14em] text-muted"><?= Security::e($headerSubtitle) ?></span>
        </div>

        <div class="flex items-center gap-2">
            <!-- Tidak ada tombol pintasan Dashboard di sini: tujuan itu sudah
                 menjadi tab pertama baris-2 (lihat Plan_Unify_UI_AurumFlow § 4.2). -->
            <span class="block min-w-0 text-right text-[0.7rem] leading-tight">
                <span class="block truncate font-semibold text-pure"><?= Security::e($auth['name'] ?? '') ?></span>
                <span class="block truncate text-muted"><?= Security::e($auth['role_label'] ?? '') ?></span>
            </span>

            <form method="post" action="<?= Security::e(App::url('logout')) ?>" class="inline"
                  data-confirm="Keluar dari AurumFlow?">
                <?= Security::csrfField() ?>
                <button type="submit" class="af-btn af-btn--ghost px-3 text-xs">Keluar</button>
            </form>
        </div>
    </div>

    <!-- Navigasi antar layar pengelola (dashboard <-> kasir <-> tutup buku).
         `overflow-x-auto` + `shrink-0` per tab: pada ponsel baris ini menggeser
         horizontal dan label tidak pernah terlipat. Kelas keduanya sudah ada di
         public/assets/css/app.min.css — tanpa kompilasi ulang Tailwind. -->
    <nav class="border-t border-surface-800 bg-obsidian-950/80">
        <div class="mx-auto flex max-w-2xl gap-2 overflow-x-auto px-4 py-2">
            <?php foreach ($navItems as $item): ?>
                <?php
                $navClass = $item['active']
                    ? 'af-btn shrink-0 bg-gold-primary px-3 text-xs text-obsidian-950'
                    : 'af-btn af-btn--ghost shrink-0 px-3 text-xs';
                ?>
                <a class="<?= Security::e($navClass) ?>" href="<?= Security::e($item['path']) ?>"<?php if ($item['active']): ?> aria-current="page"<?php endif; ?>
                ><?= Security::e($item['label']) ?></a>
            <?php endforeach; ?>
        </div>
    </nav>

    <?php if ($register !== [] || $holding !== []): ?>
    <!-- Widget informasi #1: saldo kas aktif hari ini (kontras tinggi).
         Pengelola mengirim `$register`/`$holding` dari controller-nya; dashboard
         tidak, sehingga baris ini tidak menyisakan placeholder kosong. -->
    <div class="border-t border-surface-800 bg-obsidian-950/80">
        <div class="mx-auto flex max-w-2xl items-end justify-between gap-3 px-4 py-3">
            <div>
                <p class="text-[0.68rem] font-semibold uppercase tracking-[0.16em] text-muted">
                    Saldo <?= Security::e($register['name'] ?? 'Kas') ?>
                </p>
                <p class="af-num text-2xl font-bold text-emerald-cash">
                    <?= Security::e($register['saldo_label'] ?? '-') ?>
                </p>
            </div>

            <div class="text-right">
                <p class="text-[0.68rem] font-semibold uppercase tracking-[0.16em] text-muted">Emas holding</p>
                <p class="af-num text-base font-semibold text-gold-primary">
                    <?= Security::e($holding['gram_label'] ?? '-') ?>
                </p>
                <p class="text-[0.7rem] text-muted">
                    <?= Security::e($holding['count'] ?? 0) ?> nota semalam
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>
</header>

<main class="mx-auto grid max-w-2xl gap-5 px-4 py-5 pb-16">
    <?php if ($flashMessage !== ''): ?>
        <div class="af-alert <?= Security::e($flashClass) ?>" role="status"><?= Security::e($flashMessage) ?></div>
    <?php endif; ?>

    <?= $content ?>
</main>

<script src="<?= Security::e(App::url('assets/js/app.js')) ?>" defer></script>
<script src="<?= Security::e(App::url('assets/js/alpine.min.js')) ?>" defer></script>
</body>
</html>
