<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Router untuk server bawaan PHP (`php -S`) — HANYA untuk QC
|--------------------------------------------------------------------------
| Server bawaan PHP tidak membaca .htaccess. File ini meniru aturan yang
| sama supaya QC dapat menguji front controller lewat HTTP sungguhan pada
| mesin tanpa Apache:
|
|   1. Tolak dotfile dan percobaan path traversal (setara RewriteRule [F]).
|   2. Sajikan file statis yang benar-benar ada DI DALAM public/.
|   3. Sisanya serahkan ke public/index.php (setara RewriteRule index.php [L]).
|
| File ini tidak pernah dipakai di produksi.
*/

$publicPath = realpath(dirname(__DIR__) . '/public');

if ($publicPath === false) {
    http_response_code(500);
    echo 'public/ tidak ditemukan';

    return true;
}

$uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($uri) && $uri !== '' ? $uri : '/';

// 1. Dotfile dan path traversal.
if (str_contains($path, '..') || preg_match('#(^|/)\.#', $path) === 1) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden\n";

    return true;
}

// 2. File statis nyata di dalam public/ (assets, uploads).
$candidate = $publicPath . str_replace('/', DIRECTORY_SEPARATOR, $path);
$resolved = realpath($candidate);

if ($resolved !== false
    && is_file($resolved)
    && str_starts_with($resolved, $publicPath . DIRECTORY_SEPARATOR)
) {
    return false;
}

// 3. Front controller.
require $publicPath . '/index.php';

return true;
