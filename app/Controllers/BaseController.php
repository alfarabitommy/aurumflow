<?php

declare(strict_types=1);

namespace AurumFlow\Controllers;

use AurumFlow\Config\App;
use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Security;

/**
 * Induk seluruh controller.
 *
 * Menyediakan rendering view, redirect, dan penjaga akses. Dispatcher pada
 * public/index.php hanya menerima class turunan dari BaseController,
 * sehingga tidak ada method sembarang yang dapat dipanggil dari URL.
 */
abstract class BaseController
{
    /** Kunci session untuk pesan sekali pakai (flash message). */
    private const FLASH_KEY = '_flash';

    /**
     * Penjaga re-entrancy untuk abort(): bila halaman error sendiri gagal
     * dirender, jangan pernah mencoba merender lagi.
     */
    private static bool $aborting = false;

    /**
     * Render view di dalam layout.
     *
     * Pesan flash (bila ada) diambil sekali pakai dan diteruskan ke layout
     * sebagai `$flash` — pola POST-Redirect-GET.
     *
     * @param array<string, mixed> $data
     */
    protected function render(string $view, array $data = [], string $layout = 'app'): void
    {
        $viewFile = self::resolveView($view);
        $layoutFile = self::resolveView('layouts/' . $layout);

        $data['app_name'] = App::name();
        $data['flash'] = $this->takeFlash();

        extract($data, EXTR_SKIP);

        ob_start();
        require $viewFile;
        $content = (string) ob_get_clean();

        require $layoutFile;
    }

    /**
     * Redirect HTTP lalu hentikan eksekusi.
     */
    protected function redirect(string $path, int $status = 302): never
    {
        if (!headers_sent()) {
            header('Location: ' . $path, true, $status);
        }

        exit;
    }

    /**
     * Halaman hanya untuk pengguna yang sudah login.
     */
    protected function requireAuth(): void
    {
        if (!isset($_SESSION['user_id'])) {
            $this->redirect('/login');
        }
    }

    /**
     * Halaman hanya untuk pengguna yang belum login.
     */
    protected function requireGuest(): void
    {
        if (isset($_SESSION['user_id'])) {
            $this->redirect(self::roleLandingPath((string) ($_SESSION['user_role'] ?? '')));
        }
    }

    /**
     * Halaman hanya untuk peran tertentu (lihat `Constants::ROLE_*`).
     *
     * Pelanggar diarahkan ke **beranda perannya**, bukan diberi halaman error —
     * sesuai ekspektasi "Akses URL Kasir Ilegal" pada Protokol QC Manual 5.
     * Sejak Fase 5 tujuan itu bergantung peran: seorang mitra yang membuka
     * `/operator/*` atau `/dashboard` (yang memuat `system_settings`) harus
     * mendarat di dasbornya sendiri, bukan di halaman internal pengelola.
     */
    protected function requireRole(string ...$roles): void
    {
        $this->requireAuth();

        $role = isset($_SESSION['user_role']) ? (string) $_SESSION['user_role'] : '';

        if (!in_array($role, $roles, true)) {
            $this->redirect(self::roleLandingPath($role));
        }
    }

    /**
     * Beranda per peran — SATU-SATUNYA pemetaan peran → tujuan pengalihan.
     *
     * Dipakai `requireRole()`, `requireGuest()`, `AuthController::login()`,
     * `AuthController::logout()`, dan `DashboardController::home()` supaya
     * tidak ada dua tempat yang dapat berbeda pendapat tentang "rumah" sebuah
     * peran. Peran tak dikenal (sesi rusak) diperlakukan seperti operator,
     * sehingga `/dashboard` yang sudah operator-only akan mengalihkannya lagi.
     */
    public static function roleLandingPath(string $role): string
    {
        return $role === Constants::ROLE_INVESTOR ? '/investor/dashboard' : '/dashboard';
    }

    /**
     * Simpan pesan sekali pakai untuk halaman berikutnya (POST-Redirect-GET).
     */
    protected function flash(string $kind, string $message): void
    {
        if (!Security::sessionIsActive()) {
            return;
        }

        $_SESSION[self::FLASH_KEY] = ['kind' => $kind, 'message' => $message];
    }

    /**
     * Hentikan request dengan halaman error. Dipakai front controller
     * (404) maupun exception handler (500), karena itu bersifat statis.
     *
     * View yang dipilih: `errors/{status}.php` bila ada, jika tidak
     * `errors/error.php` yang generik (mis. untuk 403/419).
     */
    public static function abort(int $status, string $title, string $message): never
    {
        if (self::$aborting) {
            // Fallback terakhir tanpa view sama sekali.
            if (!headers_sent()) {
                http_response_code($status);
                header('Content-Type: text/plain; charset=utf-8');
            }

            echo $title . "\n" . $message;
            exit(1);
        }

        self::$aborting = true;

        if (App::isCli()) {
            fwrite(STDERR, '[AurumFlow][' . $status . '] ' . $title . ': ' . $message . PHP_EOL);
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code($status);
        }

        $view = is_file(VIEW_PATH . '/errors/' . $status . '.php')
            ? 'errors/' . $status
            : 'errors/error';

        try {
            $file = self::resolveView($view);
            $data = ['status' => $status, 'title' => $title, 'message' => $message];

            extract($data, EXTR_SKIP);

            require $file;
        } catch (\Throwable) {
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=utf-8');
            }

            echo $title . "\n" . $message;
        }

        exit(1);
    }

    /**
     * Ambil pesan flash sekali pakai. Selalu mengembalikan bentuk yang sama
     * supaya view tidak perlu memeriksa keberadaan kunci.
     *
     * @return array{kind: string, message: string}
     */
    private function takeFlash(): array
    {
        $empty = ['kind' => 'info', 'message' => ''];

        if (!Security::sessionIsActive()) {
            return $empty;
        }

        $flash = $_SESSION[self::FLASH_KEY] ?? null;
        unset($_SESSION[self::FLASH_KEY]);

        if (!is_array($flash)) {
            return $empty;
        }

        $message = isset($flash['message']) && is_string($flash['message']) ? $flash['message'] : '';
        $kind = isset($flash['kind']) && is_string($flash['kind']) ? $flash['kind'] : 'info';

        if ($message === '') {
            return $empty;
        }

        return ['kind' => $kind, 'message' => $message];
    }

    /**
     * Resolusi nama view ke path absolut, dengan pertahanan path traversal.
     */
    private static function resolveView(string $name): string
    {
        if (preg_match('#^[A-Za-z0-9_\-/]+$#', $name) !== 1 || str_contains($name, '..')) {
            throw new \RuntimeException('Nama view tidak valid.');
        }

        $resolved = realpath(VIEW_PATH . '/' . $name . '.php');
        $root = realpath(VIEW_PATH);

        if ($resolved === false || $root === false) {
            throw new \RuntimeException('View tidak ditemukan: ' . $name);
        }

        if (!str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('View di luar direktori yang diizinkan: ' . $name);
        }

        return $resolved;
    }
}
