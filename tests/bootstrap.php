<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AurumFlow — Harness QC Fase 1
|--------------------------------------------------------------------------
| Dipakai oleh seluruh script tests/qc_*.php.
|
| Menyediakan tiga hal:
|   Qc        — assertion + pelaporan PASS/FAIL + exit code.
|   QcHttp    — server bawaan PHP untuk menguji front controller lewat HTTP
|               sungguhan (bukan simulasi), termasuk cookie session.
|   QcFixture — memastikan skema, seed, dan akun admin tersedia sehingga
|               setiap script QC dapat dijalankan sendiri-sendiri.
|
| Konvensi: exit code 0 = seluruh assertion lulus, 1 = ada yang gagal.
*/

require dirname(__DIR__) . '/app/Config/bootstrap.php';

use AurumFlow\Config\App;
use AurumFlow\Config\Constants;
use AurumFlow\Config\Database;
use AurumFlow\Config\Env;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\User;

// ---------------------------------------------------------------------------
// Qc — harness assertion
// ---------------------------------------------------------------------------
final class Qc
{
    private static int $passed = 0;

    private static int $failed = 0;

    public static function suite(string $name): void
    {
        echo PHP_EOL . str_repeat('=', 72) . PHP_EOL;
        echo 'QC ' . $name . PHP_EOL;
        echo str_repeat('=', 72) . PHP_EOL;
    }

    public static function section(string $title): void
    {
        echo PHP_EOL . '-- ' . $title . PHP_EOL;
    }

    public static function ok(string $id, string $label, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            self::$passed++;
            echo '  [PASS] ' . $id . '  ' . $label . PHP_EOL;

            return;
        }

        self::$failed++;
        echo '  [FAIL] ' . $id . '  ' . $label . PHP_EOL;

        if ($detail !== '') {
            foreach (explode("\n", rtrim($detail)) as $line) {
                echo '           ' . $line . PHP_EOL;
            }
        }
    }

    public static function equals(string $id, string $label, mixed $expected, mixed $actual): void
    {
        self::ok(
            $id,
            $label,
            $expected === $actual,
            'expected: ' . self::dump($expected) . "\n" . 'actual:   ' . self::dump($actual)
        );
    }

    public static function closeTo(string $id, string $label, float $expected, float $actual, float $tolerance): void
    {
        self::ok(
            $id,
            $label,
            abs($expected - $actual) <= $tolerance,
            'expected: ' . $expected . ' (toleransi ' . $tolerance . ')' . "\n" . 'actual:   ' . $actual
        );
    }

    public static function throws(string $id, string $label, callable $callback, string $expectedClass): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            self::ok(
                $id,
                $label,
                $exception instanceof $expectedClass,
                'exception: ' . $exception::class . ' — ' . $exception->getMessage()
            );

            return;
        }

        self::ok($id, $label, false, 'Tidak ada exception; diharapkan ' . $expectedClass);
    }

    public static function note(string $message): void
    {
        echo '  [CATATAN] ' . $message . PHP_EOL;
    }

    public static function finish(string $suiteName): never
    {
        $total = self::$passed + self::$failed;

        echo PHP_EOL . str_repeat('-', 72) . PHP_EOL;
        printf(
            "HASIL %s: %d/%d PASS, %d FAIL%s",
            $suiteName,
            self::$passed,
            $total,
            self::$failed,
            PHP_EOL
        );
        echo str_repeat('-', 72) . PHP_EOL;

        exit(self::$failed === 0 ? 0 : 1);
    }

    private static function dump(mixed $value): string
    {
        if (is_string($value)) {
            return '"' . $value . '"';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_float($value) || is_int($value)) {
            return (string) $value;
        }

        return print_r($value, true);
    }
}

// ---------------------------------------------------------------------------
// QcHttp — server bawaan PHP + klien HTTP
// ---------------------------------------------------------------------------
final class QcHttp
{
    private static mixed $process = null;

    private static int $port = 0;

    private static string $logFile = '';

    public static function start(): void
    {
        if (self::$process !== null) {
            return;
        }

        self::$port = self::freePort();

        $publicPath = PUBLIC_PATH;
        $router = TEST_PATH . '/server_router.php';

        self::ensureTmpDirectory();
        self::$logFile = TEST_PATH . '/.tmp/server.log';

        $command = [
            PHP_BINARY,
            '-d', 'display_errors=1',
            '-S', '127.0.0.1:' . self::$port,
            '-t', $publicPath,
            $router,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', self::$logFile, 'a'],
            2 => ['file', self::$logFile, 'a'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Tidak dapat menjalankan server bawaan PHP untuk QC.');
        }

        self::$process = $process;

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        register_shutdown_function(static function (): void {
            QcHttp::stop();
        });

        self::waitUntilReady();
    }

    public static function stop(): void
    {
        if (!is_resource(self::$process)) {
            self::$process = null;

            return;
        }

        $status = proc_get_status(self::$process);

        if ($status !== false && $status['running']) {
            // Hentikan seluruh process group agar tidak ada server yatim.
            proc_terminate(self::$process, defined('SIGKILL') ? SIGKILL : 9);
        }

        proc_close(self::$process);
        self::$process = null;
    }

    public static function baseUrl(): string
    {
        return 'http://127.0.0.1:' . self::$port;
    }

    public static function cookieJar(string $name): string
    {
        self::ensureTmpDirectory();

        return TEST_PATH . '/.tmp/cookies-' . $name . '.txt';
    }

    /**
     * @param array<string, string> $options headers, data, cookie_jar, fresh
     * @return array{status: int, headers: string, body: string, location: ?string}
     */
    public static function request(string $method, string $path, array $options = []): array
    {
        self::start();

        return self::fetch(self::baseUrl() . $path, $method, $options);
    }

    /**
     * Request ke URL absolut — dipakai untuk menguji server sementara
     * (mis. install.php saat document root salah konfigurasi).
     *
     * @param array<string, string> $options
     * @return array{status: int, headers: string, body: string, location: ?string}
     */
    public static function fetch(string $url, string $method = 'GET', array $options = []): array
    {
        $jar = $options['cookie_jar'] ?? self::cookieJar('default');

        if (($options['fresh'] ?? false) === true && is_file($jar)) {
            unlink($jar);
        }

        $handle = curl_init();

        curl_setopt_array($handle, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
            CURLOPT_PATH_AS_IS     => true,
        ]);

        if (isset($options['headers']) && is_array($options['headers'])) {
            curl_setopt($handle, CURLOPT_HTTPHEADER, $options['headers']);
        }

        if ($method === 'POST') {
            $data = $options['data'] ?? [];
            curl_setopt($handle, CURLOPT_POSTFIELDS, is_string($data) ? $data : http_build_query($data));
        }

        $raw = curl_exec($handle);

        if ($raw === false) {
            $error = curl_error($handle);
            curl_close($handle);

            throw new RuntimeException('Request QC gagal: ' . $method . ' ' . $url . ' — ' . $error);
        }

        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        $headerText = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        return [
            'status'   => $status,
            'headers'  => $headerText,
            'body'     => $body,
            'location' => self::headerValue($headerText, 'Location'),
        ];
    }

    public static function headerValue(string $headers, string $name): ?string
    {
        if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $headers, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Ambil nilai field hidden CSRF dari HTML halaman.
     */
    public static function csrfFrom(string $html): ?string
    {
        $pattern = '/name="' . preg_quote(Security::CSRF_FIELD, '/') . '"\s+value="([^"]*)"/i';

        if (preg_match($pattern, $html, $matches) === 1) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return null;
    }

    private static function waitUntilReady(): void
    {
        $deadline = microtime(true) + 10.0;

        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $error, 0.4);

            if (is_resource($socket)) {
                fclose($socket);

                return;
            }

            usleep(120000);
        }

        $log = is_file(self::$logFile) ? (string) file_get_contents(self::$logFile) : '';

        throw new RuntimeException(
            'Server QC tidak siap pada port ' . self::$port . '.' . PHP_EOL . $log
        );
    }

    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === false) {
            throw new RuntimeException('Tidak dapat mencari port bebas: ' . $error);
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $position = strrpos($name, ':');

        if ($position === false) {
            throw new RuntimeException('Format nama socket tidak terduga: ' . $name);
        }

        return (int) substr($name, $position + 1);
    }

    private static function ensureTmpDirectory(): void
    {
        $directory = TEST_PATH . '/.tmp';

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }
}

// ---------------------------------------------------------------------------
// QcFixture — pastikan prasyarat data tersedia untuk script QC mandiri
// ---------------------------------------------------------------------------
final class QcFixture
{
    /**
     * Terapkan skema + seed dan pastikan akun operator ada. Idempotent.
     *
     * @return array{phone: string, password: string, name: string}
     */
    public static function ensureInstalled(): array
    {
        Database::executeSqlFile(DATABASE_PATH . '/schema.sql');
        Database::executeSqlFile(DATABASE_PATH . '/seed.sql');

        $name = Env::get('OPERATOR_FULL_NAME') ?? 'Pengelola AurumFlow';
        $phone = Env::get('OPERATOR_PHONE') ?? '081200000001';
        $password = Env::get('OPERATOR_PASSWORD') ?? 'Aurum2026!';

        if ($name === '') {
            $name = 'Pengelola AurumFlow';
        }

        if ($phone === '') {
            $phone = '081200000001';
        }

        if ($password === '') {
            $password = 'Aurum2026!';
        }

        $existing = User::findByPhoneNumber($phone, false);

        if ($existing === null) {
            User::create($name, $phone, $password, Constants::ROLE_OPERATOR);
        } else {
            // Pastikan password QC selalu dikenal, apa pun kondisi database.
            Database::update(
                'users',
                [
                    'password_hash' => Security::hashPassword($password),
                    'role'          => Constants::ROLE_OPERATOR,
                    'is_active'     => 1,
                ],
                '`id` = :id',
                ['id' => (string) $existing['id']]
            );
        }

        return ['phone' => $phone, 'password' => $password, 'name' => $name];
    }

    public static function tableExists(string $table): bool
    {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS `total` FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = :table',
            ['table' => $table]
        );

        return (int) ($row['total'] ?? 0) > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function columnDefinition(string $table, string $column): ?array
    {
        return Database::fetchOne(
            'SELECT `DATA_TYPE`, `COLUMN_TYPE`, `IS_NULLABLE`, `NUMERIC_PRECISION`, `NUMERIC_SCALE` '
            . 'FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column',
            ['table' => $table, 'column' => $column]
        );
    }

    /**
     * Verifikasi konvensi tipe data ERD § 3.1 untuk sekumpulan kolom.
     *
     * @param list<array{0: string, 1: string, 2: int, 3: int}> $expectations
     *        Daftar [tabel, kolom, presisi, skala].
     * @return list<string> daftar pelanggaran (kosong = lulus)
     */
    public static function checkDecimalConventions(array $expectations): array
    {
        $violations = [];

        foreach ($expectations as [$table, $column, $precision, $scale]) {
            $definition = self::columnDefinition($table, $column);

            if ($definition === null) {
                $violations[] = $table . '.' . $column . ' tidak ditemukan';

                continue;
            }

            $actualType = strtolower((string) $definition['DATA_TYPE']);
            $actualPrecision = (int) $definition['NUMERIC_PRECISION'];
            $actualScale = (int) $definition['NUMERIC_SCALE'];

            if ($actualType !== 'decimal' || $actualPrecision !== $precision || $actualScale !== $scale) {
                $violations[] = sprintf(
                    '%s.%s harus DECIMAL(%d,%d) tetapi %s(%d,%d)',
                    $table,
                    $column,
                    $precision,
                    $scale,
                    strtoupper($actualType),
                    $actualPrecision,
                    $actualScale
                );
            }
        }

        return $violations;
    }
}

// ---------------------------------------------------------------------------
// Helper umum
// ---------------------------------------------------------------------------

/**
 * Jalankan kembali file QC ini sebagai subprocess dengan environment yang
 * ditimpa. Dipakai untuk menguji jalur gagal (kredensial salah, token kosong).
 *
 * @param array<string, string> $env
 * @return array{code: int, output: string}
 */
function qc_run_php(string $script, array $env = [], array $args = []): array
{
    $command = array_merge([PHP_BINARY, $script], $args);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        BASE_PATH,
        array_merge(getenv(), $env)
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Tidak dapat menjalankan subprocess QC.');
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $code = proc_close($process);

    return ['code' => $code, 'output' => $stdout . $stderr];
}

/**
 * Jalankan server web sementara dengan document root bebas.
 *
 * Dipakai untuk menguji skenario miskonfigurasi shared hosting: ketika
 * document root terpaksa diarahkan ke root proyek, `database/install.php`
 * menjadi dapat dijangkau lewat HTTP. Test ini membuktikan guard token
 * benar-benar bekerja pada jalur tersebut (mitigasi risiko R-1 dan R-9).
 *
 * @return array{url: string, stop: callable(): void}
 */
function qc_temp_server(string $documentRoot): array
{
    $port = QcHttp::freePort();
    $logFile = TEST_PATH . '/.tmp/temp-server-' . $port . '.log';

    if (!is_dir(dirname($logFile))) {
        mkdir(dirname($logFile), 0775, true);
    }

    $command = [
        PHP_BINARY,
        '-d', 'display_errors=1',
        '-S', '127.0.0.1:' . $port,
        '-t', $documentRoot,
    ];

    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
        $pipes,
        BASE_PATH
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Tidak dapat menjalankan server sementara untuk QC.');
    }

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    $stop = static function () use (&$process): void {
        if (is_resource($process)) {
            proc_terminate($process, defined('SIGKILL') ? SIGKILL : 9);
            proc_close($process);
            $process = null;
        }
    };

    register_shutdown_function($stop);

    $deadline = microtime(true) + 10.0;

    while (microtime(true) < $deadline) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.4);

        if (is_resource($socket)) {
            fclose($socket);
            break;
        }

        usleep(120000);
    }

    return ['url' => 'http://127.0.0.1:' . $port, 'stop' => $stop];
}

/**
 * Baca seluruh file PHP di app/ dan public/ untuk pemeriksaan statis.
 *
 * @return array<string, string> path => isi
 */
function qc_source_files(): array
{
    $files = [];
    $roots = [APP_PATH, PUBLIC_PATH];

    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $contents = file_get_contents($path);

            if ($contents !== false) {
                $files[$path] = $contents;
            }
        }
    }

    ksort($files);

    return $files;
}

/**
 * @return list<string> daftar lokasi (file:baris) yang melanggar
 */
function qc_scan_lines(callable $predicate): array
{
    $offenders = [];

    foreach (qc_source_files() as $path => $contents) {
        $lines = preg_split('/\R/', $contents);

        if ($lines === false) {
            continue;
        }

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            // Lewati baris komentar dan baris kosong: pemindai statis ini
            // mencari KODE, bukan prosa. Tanpa ini, docblock yang menyebut
            // nama fungsi (mis. "dilarang memanggil number_format()") akan
            // terhitung sebagai pelanggaran.
            if ($trimmed === ''
                || str_starts_with($trimmed, '*')
                || str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '/*')
                || str_starts_with($trimmed, '#')
            ) {
                continue;
            }

            if ($predicate($line) === true) {
                $offenders[] = str_replace(BASE_PATH . '/', '', $path) . ':' . ($index + 1)
                    . '  ' . $trimmed;
            }
        }
    }

    return $offenders;
}

// Pastikan timezone aplikasi benar-benar aktif di proses QC.
if (App::timezone() !== date_default_timezone_get()) {
    throw new RuntimeException('Timezone QC tidak konsisten dengan konfigurasi aplikasi.');
}
