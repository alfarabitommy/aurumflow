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
use AurumFlow\Controllers\DashboardController;

return [
    'GET' => [
        '/'          => [DashboardController::class, 'home'],
        '/login'     => [AuthController::class, 'showLogin'],
        '/dashboard' => [DashboardController::class, 'index'],
    ],

    'POST' => [
        '/login'  => [AuthController::class, 'login'],
        '/logout' => [AuthController::class, 'logout'],
    ],
];
