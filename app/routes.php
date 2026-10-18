<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Route table AurumFlow
|--------------------------------------------------------------------------
| File ini HANYA berisi data: pemetaan METHOD + path -> [Controller, method].
| Seluruh logika dispatch, pencocokan path, dan pemeriksaan CSRF berada di
| front controller `public/index.php` sesuai arsitektur Fase 1.
|
| Aturan penambahan route:
|   - Path selalu diawali '/' dan tanpa trailing slash (kecuali '/').
|   - Mutasi (login, logout, simpan data) WAJIB memakai POST.
|   - Nilai harus berupa pasangan class turunan BaseController + method
|     publik yang benar-benar ada; dispatcher memvalidasi keduanya.
*/

use AurumFlow\Controllers\AuthController;
use AurumFlow\Controllers\CapitalController;
use AurumFlow\Controllers\CashierAuthController;
use AurumFlow\Controllers\CashierController;
use AurumFlow\Controllers\DashboardController;
use AurumFlow\Controllers\ExportController;
use AurumFlow\Controllers\InvestorAuthController;
use AurumFlow\Controllers\InvestorController;
use AurumFlow\Controllers\InvestorOnboardingController;
use AurumFlow\Controllers\KasbonController;
use AurumFlow\Controllers\SettlementController;
use AurumFlow\Controllers\TellerController;

return [
    'GET' => [
        '/'                    => [DashboardController::class, 'home'],
        '/login'               => [AuthController::class, 'showLogin'],

        // Undangan mitra (Fase 5): halaman publik yang hanya berguna bagi
        // pemegang token 64 heksadesimal.
        '/join'                => [InvestorOnboardingController::class, 'show'],

        // Pintu PIN mitra — terpisah dari pintu kata sandi operator.
        '/investor/login'      => [InvestorAuthController::class, 'showLogin'],

        // Pintu PIN kasir meja bakar (Fase 7): permukaan masuk tersendiri dengan
        // daftar izin tersendiri (hanya meja kasir + dana talangan).
        '/cashier/login'       => [CashierAuthController::class, 'showLogin'],

        // Ringkasan operator. Sejak Fase 5 halaman ini memuat isi
        // `system_settings`, sehingga mitra diarahkan ke dasbornya sendiri.
        '/dashboard'           => [DashboardController::class, 'index'],

        // Dasbor blind mitra + ekspor ramah awam (Fase 5).
        '/investor/dashboard'  => [InvestorController::class, 'index'],
        '/investor/report'     => [ExportController::class, 'report'],
        '/investor/report/csv' => [ExportController::class, 'csv'],

        '/operator/teller'     => [TellerController::class, 'index'],
        '/operator/settlement' => [SettlementController::class, 'index'],
        '/operator/capital'    => [CapitalController::class, 'index'],

        // Kelola Kasir (Fase 7) — tata kelola akun staf, khusus operator.
        // Kasir yang membukanya diarahkan kembali ke meja kasir tanpa tulisan.
        '/operator/kasir'      => [CashierController::class, 'index'],

        // Buku Kasbon (Fase 8) — permukaan KEDUA yang menerima peran kasir:
        // pencairan panjar, angsuran tunai/potong nota, dan pendaftaran petani.
        '/operator/kasbon'     => [KasbonController::class, 'index'],
    ],

    'POST' => [
        '/login'               => [AuthController::class, 'login'],
        '/logout'              => [AuthController::class, 'logout'],
        '/join'                => [InvestorOnboardingController::class, 'submit'],
        '/investor/login'      => [InvestorAuthController::class, 'login'],

        // Pintu PIN kasir (Fase 7): nomor telepon + PIN 6 digit.
        '/cashier/login'       => [CashierAuthController::class, 'login'],

        // Penarikan mandiri mitra — jalur yang mengubah hak dana, jadi
        // memakai token sekali-pakai `_once` di controller.
        '/investor/withdrawal' => [InvestorController::class, 'withdrawal'],

        '/operator/teller'     => [TellerController::class, 'store'],

        // Dana talangan (Fase 6): kas masuk dari pihak luar tanpa akun, dengan
        // token sekali-pakai `_once` (satu halaman = satu token aktif).
        '/operator/teller/talangan' => [TellerController::class, 'storeBridge'],
        '/operator/settlement' => [SettlementController::class, 'store'],

        // Kelola modal (Fase 4 + Fase 5). Jalur yang menggerakkan kas (setoran,
        // Pindah Kas, penarikan) menambah token sekali-pakai `_once`; undangan
        // tidak perlu karena idempoten secara alami.
        '/operator/capital/topup'      => [CapitalController::class, 'topUp'],
        '/operator/capital/mitra'      => [CapitalController::class, 'storeMitra'],
        '/operator/capital/quota'      => [CapitalController::class, 'updateQuota'],
        '/operator/capital/transfer'   => [CapitalController::class, 'transfer'],
        '/operator/capital/withdrawal' => [CapitalController::class, 'withdrawal'],
        '/operator/capital/invite'     => [CapitalController::class, 'invite'],

        // Kelola Kasir (Fase 7). Mutasi identitas: buat akun kasir, reset PIN, dan
        // aktif/nonaktif — semuanya memakai token sekali-pakai `_once` + PRG.
        '/operator/kasir'              => [CashierController::class, 'store'],
        '/operator/kasir/pin'          => [CashierController::class, 'resetPin'],
        '/operator/kasir/status'       => [CashierController::class, 'setStatus'],

        // Buku Kasbon (Fase 8). Jalur kas: pencairan (`out`), angsuran
        // tunai/potong nota (`in`), dan pendaftaran petani (tanpa kas). Semua
        // memakai token sekali-pakai `_once` + PRG.
        '/operator/kasbon'             => [KasbonController::class, 'disburse'],
        '/operator/kasbon/angsuran'    => [KasbonController::class, 'repay'],
        '/operator/kasbon/petani'      => [KasbonController::class, 'storeMiner'],
    ],
];
