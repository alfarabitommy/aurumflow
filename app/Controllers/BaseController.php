<?php

declare(strict_types=1);

namespace AurumFlow\Controllers;

use AurumFlow\Config\App;

/**
 * Induk seluruh controller.
 *
 * Menyediakan rendering view, redirect, dan penjaga akses. Dispatcher pada
 * public/index.php hanya menerima class turunan dari BaseController,
 * sehingga tidak ada method sembarang yang dapat dipanggil dari URL.
 */
abstract class BaseController
{
    /**
     * Penjaga re-entrancy untuk abort(): bila halaman error sendiri gagal
     * dirender, jangan pernah mencoba merender lagi.
     */
    private static bool $aborting = false;

    /**
     * Render view di dalam layout.
     *
     * @param array<string, mixed> $data
     */
    protected function render(string $view, array $data = [], string $layout = 'app'): void
    {
        $viewFile = self::resolveView($view);
        $layoutFile = self::resolveView('layouts/' . $layout);

        $data['app_name'] = App::name();

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
            $this->redirect('/dashboard');
        }
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
