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
use AurumFlow\Helpers\PinThrottle;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\BridgeLoan;
use AurumFlow\Models\CapitalWallet;
use AurumFlow\Models\CashRegister;
use AurumFlow\Models\HuluTransaction;
use AurumFlow\Models\SystemSetting;
use AurumFlow\Models\User;
use AurumFlow\Services\BridgeLoanService;
use AurumFlow\Services\OnboardingService;
use AurumFlow\Services\SettlementService;
use AurumFlow\Services\TellerService;

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
     * @param array<string, mixed> $options headers, data, files, cookie_jar, fresh
     *        `files` berisi peta nama-field => path berkas lokal; bila diisi,
     *        request dikirim sebagai MULTIPART (curl membentuk boundary) sehingga
     *        peladen melihat unggahan HTTP sungguhan — dipakai QC Fase 3 untuk
     *        menguji nota tanpa kamera.
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
     * @param array<string, mixed> $options
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
            $files = $options['files'] ?? [];

            if (is_array($files) && $files !== []) {
                // Array (bukan query string) membuat curl mengirim multipart.
                $fields = is_array($data) ? $data : [];

                foreach ($files as $field => $path) {
                    if (is_string($path) && is_file($path)) {
                        $fields[(string) $field] = new CURLFile($path);
                    }
                }

                curl_setopt($handle, CURLOPT_POSTFIELDS, $fields);
            } else {
                curl_setopt($handle, CURLOPT_POSTFIELDS, is_string($data) ? $data : http_build_query($data));
            }
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
        return self::fieldFrom($html, Security::CSRF_FIELD);
    }

    /**
     * Ambil nilai field hidden apa pun dari HTML halaman.
     *
     * Dipakai QC Fase 2 untuk token sekali-pakai `_once` (anti double-submit),
     * yang berbeda dari token CSRF.
     */
    public static function fieldFrom(string $html, string $field): ?string
    {
        $pattern = '/name="' . preg_quote($field, '/') . '"\s+value="([^"]*)"/i';

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
        // `schema.sql` memakai CREATE TABLE IF NOT EXISTS, sehingga basis data
        // yang dipasang sebelum Fase 7 tidak ikut mengenal peran 'cashier'.
        // Penyelarasan aditif ini memakai jalur yang sama dengan skrip migrasi
        // lapangan (`database/migrate.php`) — satu pemilik: User::ensureRoleEnum().
        User::ensureRoleEnum();

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
            $operatorId = User::create($name, $phone, $password, Constants::ROLE_OPERATOR);
        } else {
            $operatorId = (string) $existing['id'];

            // Pastikan password QC selalu dikenal, apa pun kondisi database.
            Database::update(
                'users',
                [
                    'password_hash' => Security::hashPassword($password),
                    'role'          => Constants::ROLE_OPERATOR,
                    'is_active'     => 1,
                ],
                '`id` = :id',
                ['id' => $operatorId]
            );
        }

        // Dompet Pengelola (Fase 4) mengikuti perilaku `database/install.php`,
        // sehingga keadaan QC sama dengan keadaan produksi: settlement memakai
        // prioritas serapan 50 % bila dompetnya bermodal.
        CapitalWallet::ensureOperatorWallet($operatorId);

        // Pembatas percobaan login (Fase 5) dikosongkan di sini supaya suite
        // yang sengaja memaksa login GAGAL (mis. `qc_routing`, `qc_fase_5`)
        // tidak pernah mewarisi kunci dari suite sebelumnya — ambang 5 gagal
        // per 15 menit akan membuat uji berikutnya gagal karena alasan yang
        // sama sekali berbeda.
        PinThrottle::clearAll();

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

    /**
     * Siapkan pos kas meja hulu untuk skenario QC Fase 2. Idempotent.
     *
     * Setara instruksi manual "Isi saldo awal Kas Meja Bakar melalui
     * phpMyAdmin sebesar Rp 50.000.000" pada Protokol QC Manual 2, sekaligus
     * membersihkan jejak transaksi supaya suite dapat dijalankan berkali-kali
     * dengan hasil yang sama.
     *
     * @return array{register_id: string, register_name: string, balance: string, opening_balance: string}
     */
    public static function resetTellerState(): array
    {
        self::ensureInstalled();

        $register = CashRegister::activeTeller();

        if ($register === null) {
            throw new RuntimeException('Pos kas "' . CashRegister::TELLER_NAME . '" tidak ada setelah seed.');
        }

        $registerId = (string) $register['id'];
        $openingBalance = '50000000.00';

        // Talangan lebih dulu (Fase 6): baris `bridge_loans` menunjuk pos kas &
        // sesi tutup buku, dan suite ini mengosongkan mutasi pos kas meja —
        // membiarkannya hidup berarti talangan tanpa kas masuk yang tercatat.
        self::resetBridgeState();

        // Bersihkan hanya jejak milik pos kas meja; pos lain tidak disentuh.
        Database::query(
            'DELETE FROM `cash_mutations` WHERE `register_id` = :register_id',
            ['register_id' => $registerId]
        );

        // Seluruh baris hulu berasal dari QC Fase 2 (belum ada fitur lain).
        Database::query('DELETE FROM `hulu_transactions`');

        Database::query(
            'UPDATE `cash_registers` SET `current_balance` = :balance WHERE `id` = :register_id',
            ['balance' => $openingBalance, 'register_id' => $registerId]
        );

        return [
            'register_id'     => $registerId,
            'register_name'   => (string) $register['register_name'],
            'balance'         => self::registerBalance($registerId),
            'opening_balance' => $openingBalance,
        ];
    }

    /**
     * Saldo terkini sebuah pos kas sebagai string desimal kanonik.
     */
    public static function registerBalance(string $registerId): string
    {
        $row = Database::fetchOne(
            'SELECT `current_balance` FROM `cash_registers` WHERE `id` = :register_id LIMIT 1',
            ['register_id' => $registerId]
        );

        return (string) ($row['current_balance'] ?? '0.00');
    }

    // -----------------------------------------------------------------------
    // Fixture Fase 3 — likuidasi toko & tutup buku
    // -----------------------------------------------------------------------

    /**
     * Bangun ulang KEADAAN AKHIR Protokol QC Manual 2 secara idempotent.
     *
     * Setara instruksi manual dokumen Roadmap: "Isi saldo awal Kas Meja Bakar
     * Rp 50.000.000, lalu input dua transaksi", tetapi dijalankan lewat jalur
     * kode nyata (`TellerService::payCash()`) sehingga yang diuji adalah
     * perilaku sistem, bukan INSERT langsung.
     *
     * Setelah fungsi ini: kas meja `14.842.500`, emas `holding` `18,050 gr`
     * dengan modal belanja `35.157.500`, brankas `0`, defisit berjalan `0`,
     * dan seluruh jejak settlement dihapus.
     *
     * @return array{teller_id: string, teller_balance: string,
     *               brankas_id: string, brankas_balance: string,
     *               holding_grams: string, holding_cost: string,
     *               holding_count: int}
     */
    public static function resetSettlementState(): array
    {
        $fixture = self::resetTellerState();

        self::clearSettlementTraces();

        // Dua kedatangan hulu yang sama dengan Protokol QC Manual 2.
        TellerService::payCash([
            'weight'       => '14,850',
            'price'        => '1.950.000',
            'farmer_alias' => 'Pak Anto',
        ]);

        TellerService::payCash([
            'weight'       => '3,200',
            'price'        => '1.950.000',
            'farmer_alias' => 'Petani B-02',
        ]);

        return self::settlementSnapshot($fixture['register_id']);
    }

    /**
     * Tambahkan satu kedatangan hulu pada KEADAAN SAAT INI (tanpa membersihkan).
     *
     * Dipakai skenario lanjutan (mis. "settlement esoknya" pada Protokol 3.3):
     * kas meja berkurang sesuai pembelian, dan emas baru berstatus `holding`.
     *
     * @return array<string, mixed> ringkasan `TellerService::payCash()`
     */
    public static function seedHoldingBatch(
        string $grams,
        string $price,
        string $alias = 'QC Hulu Lanjutan'
    ): array {
        return TellerService::payCash([
            'weight'       => $grams,
            'price'        => $price,
            'farmer_alias' => $alias,
        ]);
    }

    /**
     * Bersihkan seluruh jejak tutup buku dan berkas nota, lalu kembalikan
     * kas brankas ke nol dan defisit berjalan ke nol.
     *
     * Sejak Fase 4, satu sesi tutup buku juga menulis `profit_distributions`
     * dan mengkredit `capital_wallets.balance_profit`; keduanya harus
     * dibersihkan lebih dulu karena `profit_distributions.settlement_id`
     * memakai FK `ON DELETE RESTRICT` (ERD § 4) — tanpa itu penghapusan
     * `daily_settlements` gagal.
     *
     * Seluruh dompet modal juga DINETRALKAN (`neutraliseWallets()`): mesin
     * alokasi membaca SEMUA dompet, sehingga dompet yang dibuat manual saat
     * pengujian lapangan (kuota/saldo bukan nol) akan ikut menyerap modal dan
     * merusak keadaan yang diharapkan skenario QC. Menolkan nilai — bukan
     * menghapus baris dompet — menjaga data pengujian pemilik aplikasi.
     */
    public static function clearSettlementTraces(): void
    {
        // Talangan lebih dulu (Fase 6): `bridge_loans.settlement_id` memakai FK
        // `ON DELETE RESTRICT`, jadi baris anak harus dibuang sebelum sesi.
        self::resetBridgeState();

        // Distribusi lebih dulu: FK RESTRICT ke daily_settlements.
        Database::query('DELETE FROM `profit_distributions`');
        Database::query('DELETE FROM `daily_settlements`');

        self::neutraliseWallets();

        $brankas = CashRegister::findBrankas();

        if ($brankas !== null) {
            Database::query(
                'DELETE FROM `cash_mutations` WHERE `register_id` = :register_id',
                ['register_id' => (string) $brankas['id']]
            );

            Database::query(
                'UPDATE `cash_registers` SET `current_balance` = :balance WHERE `id` = :register_id',
                ['balance' => '0.00', 'register_id' => (string) $brankas['id']]
            );
        }

        SystemSetting::set(SettlementService::SETTING_ACTIVE_DEFICIT, '0.00');

        self::purgeReceiptFiles();
    }

    /**
     * Bersihkan seluruh jejak dana talangan (Fase 6). Idempotent.
     *
     * Wajib berjalan SEBELUM `daily_settlements` dihapus: `bridge_loans.settlement_id`
     * memakai FK `ON DELETE RESTRICT` (pola `profit_distributions`). Saldo pos kas
     * TIDAK disentuh di sini — itu urusan `resetTellerState()`.
     */
    public static function resetBridgeState(): void
    {
        Database::query('DELETE FROM `bridge_loans`');
    }

    /**
     * Catat satu dana talangan lewat jalur kode nyata (`BridgeLoanService::receive()`).
     *
     * @param array<string, mixed> $override nilai form yang ditimpa
     * @return array<string, mixed> ringkasan `receive()`
     */
    public static function seedBridgeLoan(array $override = []): array
    {
        return BridgeLoanService::receive($override + [
            'lender_name'      => 'Pak Haji Daud',
            'principal_amount' => '10.000.000',
            'fee_type'         => BridgeLoanService::FEE_TYPE_FIXED,
            'fee_rate_pct'     => '',
            'fee_fixed'        => '100.000',
            'notes'            => '',
        ]);
    }

    /**
     * Ringkasan keadaan dana talangan saat ini.
     *
     * @return array{count_active: int, count_settled: int, principal: string,
     *               fee: string, absorbed: string, uncovered: string,
     *               total_return: string, meja_balance: string}
     */
    public static function bridgeSnapshot(): array
    {
        $row = Database::fetchOne(
            'SELECT '
            . 'SUM(CASE WHEN `status` = :active THEN 1 ELSE 0 END) AS `active_count`, '
            . 'SUM(CASE WHEN `status` = :settled THEN 1 ELSE 0 END) AS `settled_count`, '
            . 'COALESCE(SUM(`principal_amount`), 0) AS `principal`, '
            . 'COALESCE(SUM(`fee_amount`), 0) AS `fee`, '
            . 'COALESCE(SUM(`fee_absorbed`), 0) AS `absorbed`, '
            . 'COALESCE(SUM(`fee_uncovered`), 0) AS `uncovered` '
            . 'FROM `bridge_loans`',
            ['active' => BridgeLoan::STATUS_ACTIVE, 'settled' => BridgeLoan::STATUS_SETTLED]
        );

        $teller = CashRegister::activeTeller();
        $principal = self::canonicalMoney((string) ($row['principal'] ?? '0'));
        $fee = self::canonicalMoney((string) ($row['fee'] ?? '0'));

        return [
            'count_active'  => (int) ($row['active_count'] ?? 0),
            'count_settled' => (int) ($row['settled_count'] ?? 0),
            'principal'     => $principal,
            'fee'           => $fee,
            'absorbed'      => self::canonicalMoney((string) ($row['absorbed'] ?? '0')),
            'uncovered'     => self::canonicalMoney((string) ($row['uncovered'] ?? '0')),
            'total_return'  => TellerService::centsToDecimal(
                TellerService::moneyToCents($principal) + TellerService::moneyToCents($fee)
            ),
            'meja_balance'  => $teller === null ? '0.00' : self::registerBalance((string) $teller['id']),
        ];
    }

    /**
     * Nilai agregat MySQL -> desimal kanonik 2 angka.
     */
    private static function canonicalMoney(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            return '0.00';
        }

        return $matches[1] . '.' . str_pad((string) ($matches[2] ?? ''), 2, '0', STR_PAD_RIGHT);
    }

    /**
     * Siapkan keadaan dengan SATU kedatangan hulu saja (skenario hari minus).
     *
     * Kas meja: Rp 50.000.000 − kas keluar bersih pembelian. Defisit berjalan
     * nol, brankas nol, dan tidak ada jejak settlement sebelumnya.
     *
     * @return array{teller_id: string, teller_balance: string,
     *               brankas_id: string, brankas_balance: string,
     *               holding_grams: string, holding_cost: string,
     *               holding_count: int}
     */
    public static function resetSingleHoldingState(
        string $grams = '1,000',
        string $price = '1.950.000'
    ): array {
        $fixture = self::resetTellerState();

        self::clearSettlementTraces();
        self::seedHoldingBatch($grams, $price, 'QC Hulu Tunggal');

        return self::settlementSnapshot($fixture['register_id']);
    }

    /**
     * Ringkasan keadaan settlement saat ini.
     *
     * @return array{teller_id: string, teller_balance: string,
     *               brankas_id: string, brankas_balance: string,
     *               holding_grams: string, holding_cost: string,
     *               holding_count: int}
     */
    public static function settlementSnapshot(string $tellerId): array
    {
        $brankas = CashRegister::findBrankas();

        if ($brankas === null) {
            throw new RuntimeException('Pos kas "' . CashRegister::BRANKAS_NAME . '" tidak ada setelah seed.');
        }

        $totals = HuluTransaction::holdingTotals();

        return [
            'teller_id'        => $tellerId,
            'teller_balance'   => self::registerBalance($tellerId),
            'brankas_id'       => (string) $brankas['id'],
            'brankas_balance'  => self::registerBalance((string) $brankas['id']),
            'holding_grams'    => $totals['grams'],
            'holding_cost'     => $totals['cost'],
            'holding_count'    => $totals['count'],
        ];
    }

    /**
     * Daftar berkas nota yang benar-benar ada di direktori unggahan.
     *
     * @return list<string> nama berkas (tanpa direktori), terurut
     */
    public static function receiptFiles(): array
    {
        $directory = SettlementService::receiptDirectory();

        if (!is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach ((array) scandir($directory) as $entry) {
            $name = (string) $entry;

            if ($name === '' || $name === '.' || $name === '..' || $name === '.gitignore') {
                continue;
            }

            if (is_file($directory . '/' . $name)) {
                $files[] = $name;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Hapus seluruh berkas nota (sisa QC sebelumnya). Mengembalikan jumlah
     * berkas yang dihapus.
     */
    public static function purgeReceiptFiles(): int
    {
        $directory = SettlementService::receiptDirectory();
        $removed = 0;

        foreach (self::receiptFiles() as $name) {
            if (@unlink($directory . '/' . $name)) {
                $removed++;
            }
        }

        return $removed;
    }

    // -----------------------------------------------------------------------
    // Fixture Fase 4 — dompet modal, distribusi dividen, antrean penarikan
    // -----------------------------------------------------------------------

    /**
     * Spesifikasi dompet uji Protokol QC Manual 4.
     *
     * Dompet Pengelola memakai AKUN OPERATOR ASLI (seperti "Pengelola (Kamu)"
     * pada dokumen Roadmap), sedangkan tiga mitra memakai akun uji berawalan
     * `0899` yang dibersihkan kembali oleh `resetCapitalState()`.
     *
     * @var list<array{key: string, phone: string, name: string, tier: string,
     *                 balance: string, quota: string}>
     */
    public const QC_WALLETS = [
        [
            'key'     => 'operator',
            'phone'   => '',
            'name'    => 'Pengelola',
            'tier'    => 'operator',
            'balance' => '30000000.00',
            'quota'   => '0.00',
        ],
        [
            'key'     => 'priority',
            'phone'   => '089900000102',
            'name'    => 'QC Mitra Prioritas',
            'tier'    => 'family',
            'balance' => '16000000.00',
            'quota'   => '20.00',
        ],
        [
            'key'     => 'reguler_a',
            'phone'   => '089900000103',
            'name'    => 'QC Mitra Reguler A',
            'tier'    => 'external',
            'balance' => '20000000.00',
            'quota'   => '0.00',
        ],
        [
            'key'     => 'reguler_b',
            'phone'   => '089900000104',
            'name'    => 'QC Mitra Reguler B',
            'tier'    => 'external',
            'balance' => '14000000.00',
            'quota'   => '0.00',
        ],
    ];

    /**
     * Netralkan SELURUH dompet modal: saldo, dividen, kuota, dan status
     * penarikan menjadi nol. Baris dompet TIDAK dihapus.
     *
     * Mengapa ada: `AllocationEngine` membaca SEMUA dompet pada setiap sesi
     * tutup buku. Dompet yang dibuat manual saat pengujian lapangan (mis. lewat
     * form "Tambah Mitra", lengkap dengan setoran & kuota) akan ikut menyerap
     * modal dan memakan kuota Mitra Prioritas, sehingga angka protokol QC
     * bergeser. Menolkan nilai menjaga skenario deterministik tanpa menghapus
     * data pengujian pemilik aplikasi — pola yang sama dengan
     * `resetTellerState()` yang menolkan saldo Kas Meja Bakar.
     */
    public static function neutraliseWallets(): void
    {
        Database::query(
            'UPDATE `capital_wallets` SET `balance_capital` = \'0.00\', `balance_profit` = \'0.00\', '
            . '`locked_quota_pct` = \'0.00\', `withdrawal_status` = \'none\', `withdrawal_request_date` = NULL'
        );
    }

    /**
     * Siapkan empat dompet Protokol QC Manual 4 dengan saldo & kuota persis
     * seperti dokumen Roadmap FASE 4. Idempotent dan **tidak menghapus apa pun**,
     * sehingga dapat dipanggil setelah fixture lain (mis. keadaan akhir QC 2).
     *
     * @return array<string, string> peta kunci dompet => wallet_id
     */
    public static function seedQcWallets(): array
    {
        $operatorPhone = Env::get('OPERATOR_PHONE') ?? '081200000001';
        $operatorUser = User::findByPhoneNumber($operatorPhone, false);

        if ($operatorUser === null) {
            $operatorId = User::create(
                Env::get('OPERATOR_FULL_NAME') ?? 'Pengelola AurumFlow',
                $operatorPhone,
                Env::get('OPERATOR_PASSWORD') ?? 'Aurum2026!',
                Constants::ROLE_OPERATOR
            );
        } else {
            $operatorId = (string) $operatorUser['id'];
        }

        $wallets = ['operator' => CapitalWallet::ensureOperatorWallet($operatorId)];

        foreach (self::QC_WALLETS as $spec) {
            if ($spec['key'] === 'operator') {
                continue;
            }

            $user = User::findByPhoneNumber($spec['phone'], false);

            if ($user === null) {
                $userId = User::create(
                    $spec['name'],
                    $spec['phone'],
                    Security::randomToken(32),
                    Constants::ROLE_INVESTOR
                );
            } else {
                $userId = (string) $user['id'];
            }

            $wallet = CapitalWallet::findByUserId($userId);
            $walletId = $wallet === null
                ? CapitalWallet::insert($userId, $spec['tier'])
                : (string) $wallet['id'];

            $wallets[$spec['key']] = $walletId;
        }

        foreach (self::QC_WALLETS as $spec) {
            self::setWalletState($wallets[$spec['key']], $spec['balance'], '0.00', $spec['quota']);
        }

        return $wallets;
    }

    /**
     * Kembalikan keadaan modal ke titik nol: buang distribusi & permohonan
     * penarikan, hapus akun mitra uji, dan nolkan seluruh dompet. Idempotent.
     *
     * Tidak menyentuh kas pos (meja/brankas) — itu urusan
     * `resetTellerState()`/`clearSettlementTraces()`.
     */
    public static function resetCapitalState(): void
    {
        self::ensureInstalled();

        Database::query('DELETE FROM `profit_distributions`');
        Database::query('DELETE FROM `withdrawal_requests`');

        $testUsers = Database::fetchAll(
            'SELECT `id` FROM `users` WHERE `phone_number` LIKE :prefix',
            ['prefix' => '0899%']
        );

        foreach ($testUsers as $user) {
            $userId = (string) ($user['id'] ?? '');

            if ($userId === '') {
                continue;
            }

            Database::query('DELETE FROM `capital_wallets` WHERE `user_id` = :user_id', ['user_id' => $userId]);
            Database::query('DELETE FROM `users` WHERE `id` = :user_id', ['user_id' => $userId]);
        }

        self::neutraliseWallets();
    }

    /**
     * Setel saldo & kuota satu dompet secara langsung (tanpa jalur uang).
     */
    public static function setWalletState(
        string $walletId,
        string $capital = '0.00',
        string $profit = '0.00',
        string $quota = '0.00'
    ): void {
        Database::query(
            'UPDATE `capital_wallets` SET `balance_capital` = :capital, `balance_profit` = :profit, '
            . '`locked_quota_pct` = :quota WHERE `id` = :id',
            ['capital' => $capital, 'profit' => $profit, 'quota' => $quota, 'id' => $walletId]
        );
    }

    /**
     * Ringkasan keadaan modal (dompet, distribusi, permintaan penarikan).
     *
     * @return array{wallets: list<array<string, mixed>>,
     *               distributions: int, withdrawals: int}
     */
    public static function capitalSnapshot(): array
    {
        return [
            'wallets'       => CapitalWallet::listWithUser(),
            'distributions' => (int) (Database::fetchOne(
                'SELECT COUNT(*) AS `total` FROM `profit_distributions`'
            )['total'] ?? 0),
            'withdrawals'   => (int) (Database::fetchOne(
                'SELECT COUNT(*) AS `total` FROM `withdrawal_requests`'
            )['total'] ?? 0),
        ];
    }

    // -----------------------------------------------------------------------
    // Fase 5 — mitra QC: onboarding, PIN, dasbor blind, ekspor
    // -----------------------------------------------------------------------

    /**
     * Mitra QC Fase 5 yang sudah onboarding — pemilik dasbor yang diuji.
     *
     * `capital` Rp 20.000.000 mengikuti dompet Protokol QC Manual 4, dan
     * putaran terakhirnya memakai `capital_utilized` Rp 6.204.264,71 sehingga
     * "Modal Berputar" dasbor menguji angka yang sama dengan dokumen Roadmap:
     * Rp 6.204.265 dari modal Rp 20.000.000 → rasio 31,02 %.
     */
    public const QC_INVESTOR = [
        'phone'   => '089800000101',
        'name'    => 'QC Mitra Fase Lima',
        'tier'    => 'family',
        'pin'     => '246810',
        'capital' => '20000000.00',
    ];

    /**
     * Calon mitra QC Fase 5 yang BELUM onboarding (tautan aktif, belum tanda
     * tangan) — objek uji untuk klaim undangan & kebijakan PIN.
     */
    public const QC_INVITEE = [
        'phone' => '089800000102',
        'name'  => 'QC Calon Mitra',
        'tier'  => 'external',
        'pin'   => '135790',
    ];

    /** Prefiks nomor akun uji Fase 5 (dibersihkan setiap kali fixture dijalankan). */
    public const QC_INVESTOR_PREFIX = '0898%';

    /**
     * Baris `profit_distributions` tetap untuk mitra QC Fase 5.
     *
     * Tanggalnya **relatif terhadap hari ini** supaya "Dividen Bulan Ini" dapat
     * diuji tanpa bergantung kalender: baris `-40` selalu di luar bulan
     * berjalan, sisanya di dalamnya (kecuali bila hari ini tanggal 1–2 — dan
     * untuk itu suite menghitung harapannya dari data, bukan dari angka mati).
     */
    public const QC_INVESTOR_ROUNDS = [
        ['offset_days' => 0, 'utilized' => '6204264.71', 'profit' => '154341.18'],
        ['offset_days' => -1, 'utilized' => '10000000.00', 'profit' => '100000.00'],
        ['offset_days' => -2, 'utilized' => '14000000.00', 'profit' => '50000.00'],
        ['offset_days' => -40, 'utilized' => '5000000.00', 'profit' => '75000.00'],
    ];

    /**
     * Angka & nama "kanari" pada baris `daily_settlements`/`hulu_transactions`
     * mitra QC. Semuanya **harus** tidak pernah muncul pada permukaan investor;
     * suite Fase 5 mencarinya di HTML dasbor, laporan, dan CSV.
     */
    public const QC_CANARIES = [
        'revenue'  => '37544000.00',
        'operator' => '1749200.00',
        'net'      => '2186500.00',
        'grams'    => '18.050',
        'alias'    => 'QC Kanari Penambang',
    ];

    /**
     * Siapkan mitra QC Fase 5 yang SUDAH onboarding: akun + PIN aktif + dompet
     * bermodal Rp 20 juta + empat putaran distribusi (lengkap dengan sesi
     * settlement berisi angka kanari).
     *
     * Idempotent: seluruh distribusi, permohonan penarikan, sesi settlement,
     * akun uji `0898…` lain, dan saldo dompet lain dinolkan lebih dulu — angka
     * dasbor karena itu selalu sama di setiap jalankan.
     *
     * @param string|null $firstUtilized nilai `capital_utilized` putaran
     *                                   terakhir (untuk menguji klamp)
     * @return array<string, mixed>
     */
    public static function seedQcInvestor(?string $firstUtilized = null): array
    {
        self::ensureInstalled();

        Database::query('DELETE FROM `profit_distributions`');
        Database::query('DELETE FROM `withdrawal_requests`');
        Database::query('DELETE FROM `daily_settlements`');
        Database::query(
            'DELETE FROM `hulu_transactions` WHERE `farmer_alias` = :alias',
            ['alias' => self::QC_CANARIES['alias']]
        );

        self::purgeQcInvestorAccounts();

        // Dompet lain dinetralkan: mereka tidak boleh muncul di dasbor mitra.
        self::neutraliseWallets();

        $user = User::findByPhoneNumber(self::QC_INVESTOR['phone'], false);

        if ($user === null) {
            $userId = User::create(
                self::QC_INVESTOR['name'],
                self::QC_INVESTOR['phone'],
                Security::randomToken(32),
                Constants::ROLE_INVESTOR
            );
        } else {
            $userId = (string) $user['id'];
        }

        $wallet = CapitalWallet::findByUserId($userId);
        $walletId = $wallet === null
            ? CapitalWallet::insert($userId, self::QC_INVESTOR['tier'])
            : (string) $wallet['id'];

        $profitTotal = 0;

        foreach (self::QC_INVESTOR_ROUNDS as $round) {
            $profitTotal += (int) TellerService::moneyToCents($round['profit']);
        }

        self::setWalletState($walletId, self::QC_INVESTOR['capital'], self::canonicalCents($profitTotal), '0.00');

        $rounds = [];

        foreach (self::QC_INVESTOR_ROUNDS as $index => $round) {
            $utilized = $index === 0 && $firstUtilized !== null ? $firstUtilized : $round['utilized'];
            $settlementId = self::insertQcSettlement((int) $round['offset_days'], $utilized, $round['profit']);

            Database::query(
                'INSERT INTO `profit_distributions` '
                . '(`id`, `settlement_id`, `wallet_id`, `capital_utilized`, `profit_earned`) '
                . 'VALUES (:id, :settlement_id, :wallet_id, :utilized, :profit)',
                [
                    'id'            => Security::uuidV4(),
                    'settlement_id' => $settlementId,
                    'wallet_id'     => $walletId,
                    'utilized'      => $utilized,
                    'profit'        => $round['profit'],
                ]
            );

            $rounds[] = [
                'settlement_id' => $settlementId,
                'date'          => date('Y-m-d', strtotime($round['offset_days'] . ' days')),
                'utilized'      => $utilized,
                'profit'        => $round['profit'],
            ];
        }

        // Kanari hulu: satu kedatangan yang sudah disettle (tanpa settlement_id)
        // supaya nama penambang + angka omzet benar-benar ada di basis data,
        // lalu dibuktikan TIDAK pernah bocor ke permukaan investor.
        Database::query(
            'INSERT INTO `hulu_transactions` '
            . '(`id`, `settlement_id`, `farmer_alias`, `dry_weight`, `buy_price_per_g`, '
            . '`gross_tebus_total`, `flat_burn_fee`, `operator_burn_cut`, `consumable_cut`, '
            . '`net_cash_paid`, `status`) VALUES '
            . '(:id, NULL, :alias, :grams, 1950000.00, 35197500.00, 20000.00, 10000.00, 10000.00, 35177500.00, \'settled\')',
            [
                'id'    => Security::uuidV4(),
                'alias' => self::QC_CANARIES['alias'],
                'grams' => self::QC_CANARIES['grams'],
            ]
        );

        // Mitra QC aktif: PIN ber-pepper siap dipakai, jejak audit terisi,
        // tautan undangan sudah hangus (keadaan sesudah onboarding).
        Database::update(
            'users',
            [
                'role'                 => Constants::ROLE_INVESTOR,
                'is_active'            => 1,
                'password_hash'        => Security::hashPin(self::QC_INVESTOR['pin']),
                'invite_token'         => null,
                'onboarding_signed_at' => date('Y-m-d H:i:s'),
                'signed_ip_address'    => '127.0.0.1',
                'signed_user_agent'    => 'QC Fixture Fase 5',
            ],
            '`id` = :id',
            ['id' => $userId]
        );

        PinThrottle::clearAll();

        return [
            'user_id'   => $userId,
            'wallet_id' => $walletId,
            'phone'     => self::QC_INVESTOR['phone'],
            'name'      => self::QC_INVESTOR['name'],
            'pin'       => self::QC_INVESTOR['pin'],
            'capital'   => self::QC_INVESTOR['capital'],
            'profit'    => self::canonicalCents($profitTotal),
            'rounds'    => $rounds,
        ];
    }

    /**
     * Siapkan calon mitra QC Fase 5 yang BELUM onboarding: tautan undangan
     * aktif, tanpa jejak tanda tangan, dan tanpa kredensial yang diketahui.
     *
     * Dipanggil setelah `seedQcInvestor()` (yang menetralkan seluruh dompet).
     *
     * @return array<string, mixed>
     */
    public static function seedQcInvitee(): array
    {
        $user = User::findByPhoneNumber(self::QC_INVITEE['phone'], false);

        if ($user === null) {
            $userId = User::create(
                self::QC_INVITEE['name'],
                self::QC_INVITEE['phone'],
                Security::randomToken(32),
                Constants::ROLE_INVESTOR
            );
        } else {
            $userId = (string) $user['id'];
        }

        $wallet = CapitalWallet::findByUserId($userId);
        $walletId = $wallet === null
            ? CapitalWallet::insert($userId, self::QC_INVITEE['tier'])
            : (string) $wallet['id'];

        self::setWalletState($walletId, '0.00', '0.00', '0.00');

        $inviteToken = Security::randomToken(32);

        Database::update(
            'users',
            [
                'role'                 => Constants::ROLE_INVESTOR,
                'is_active'            => 1,
                'password_hash'        => Security::hashPassword(Security::randomToken(32)),
                'invite_token'         => $inviteToken,
                'onboarding_signed_at' => null,
                'signed_ip_address'    => null,
                'signed_user_agent'    => null,
            ],
            '`id` = :id',
            ['id' => $userId]
        );

        PinThrottle::clearAll();

        return [
            'user_id'      => $userId,
            'wallet_id'    => $walletId,
            'phone'        => self::QC_INVITEE['phone'],
            'name'         => self::QC_INVITEE['name'],
            'pin'          => self::QC_INVITEE['pin'],
            'invite_token' => $inviteToken,
        ];
    }

    /**
     * Pulihkan keadaan mitra QC Fase 5 ke titik awal: mitra aktif + calon mitra
     * menunggu, pembatas login kosong.
     */
    public static function resetInvestorState(): void
    {
        self::seedQcInvestor();
        self::seedQcInvitee();
        PinThrottle::clearAll();
    }

    /**
     * Ringkasan keadaan mitra QC (untuk perbandingan sebelum/sesudah mutasi).
     *
     * @return array<string, mixed>
     */
    public static function investorSnapshot(?string $phone = null): array
    {
        $phone = $phone ?? self::QC_INVESTOR['phone'];
        $user = User::findByPhoneNumber($phone, false);

        if ($user === null) {
            return ['found' => false, 'withdrawals' => 0, 'distributions' => 0];
        }

        $userId = (string) $user['id'];
        $wallet = CapitalWallet::findByUserId($userId);
        $walletId = (string) ($wallet['id'] ?? '');

        // Kolom audit onboarding dibaca langsung: `User::findByPhoneNumber()`
        // sengaja tidak membawa `invite_token` (tidak ada kebutuhan produksi),
        // sedangkan fixture QC harus dapat membuktikannya utuh/hangus.
        $audit = Database::fetchOne(
            'SELECT `invite_token`, `onboarding_signed_at`, `signed_ip_address`, `signed_user_agent` '
            . 'FROM `users` WHERE `id` = :id LIMIT 1',
            ['id' => $userId]
        );

        $user['invite_token'] = $audit['invite_token'] ?? null;
        $user['signed_ip_address'] = $audit['signed_ip_address'] ?? null;
        $user['signed_user_agent'] = $audit['signed_user_agent'] ?? null;

        return [
            'found'    => true,
            'user'     => $user,
            'wallet'   => $wallet,
            'withdrawals' => (int) (Database::fetchOne(
                'SELECT COUNT(*) AS `total` FROM `withdrawal_requests` WHERE `wallet_id` = :wallet_id',
                ['wallet_id' => $walletId]
            )['total'] ?? 0),
            'distributions' => (int) (Database::fetchOne(
                'SELECT COUNT(*) AS `total` FROM `profit_distributions` WHERE `wallet_id` = :wallet_id',
                ['wallet_id' => $walletId]
            )['total'] ?? 0),
        ];
    }

    // -----------------------------------------------------------------------
    // Fase 7 — peran kasir meja bakar
    // -----------------------------------------------------------------------

    /**
     * Akun kasir QC Fase 7 — peran `cashier`, PIN ber-pepper, TANPA dompet modal.
     */
    public const QC_CASHIER = [
        'phone' => '089700000101',
        'name'  => 'QC Kasir Meja Bakar',
        'pin'   => '246810',
    ];

    /**
     * Akun kasir kedua: sasaran uji reset PIN & nonaktifkan (dan pembanding
     * "kasir lain" saat menilai kebocoran daftar).
     */
    public const QC_CASHIER_SECOND = [
        'phone' => '089700000102',
        'name'  => 'QC Kasir Cadangan',
        'pin'   => '975310',
    ];

    /** Prefiks nomor akun kasir uji (dibersihkan setiap kali fixture dijalankan). */
    public const QC_CASHIER_PREFIX = '0897%';

    /**
     * Siapkan dua akun kasir QC (idempotent).
     *
     * Akun kasir dibuat lewat `User::createWithPin()` — jalur yang sama dengan
     * layar "Kelola Kasir" — sehingga PIN tersimpan ber-pepper dan tidak pernah
     * sah di pintu kata sandi. Karena kasir bukan mitra modal, fixture ini
     * sengaja **tidak** membuat `capital_wallets`.
     *
     * @return array{primary: array<string, mixed>, secondary: array<string, mixed>}
     */
    public static function seedQcCashier(): array
    {
        self::ensureInstalled();
        self::purgeQcCashierAccounts();

        $primary = self::ensureCashier(self::QC_CASHIER);
        $secondary = self::ensureCashier(self::QC_CASHIER_SECOND);

        return ['primary' => $primary, 'secondary' => $secondary];
    }

    /**
     * Keadaan awal suite Fase 7: ENUM selaras, dua kasir uji aktif, pembatas
     * percobaan kosong (uji 401/429 yang disengaja tidak meracuni suite lain).
     *
     * @return array{primary: array<string, mixed>, secondary: array<string, mixed>}
     */
    public static function resetCashierState(): array
    {
        $cashiers = self::seedQcCashier();
        self::clearPinThrottle();

        return $cashiers;
    }

    /**
     * Hapus seluruh akun kasir uji berawalan `0897`.
     *
     * Tidak ada urutan FK yang dibutuhkan: kasir tidak punya dompet, permohonan
     * penarikan, maupun distribusi.
     */
    public static function purgeQcCashierAccounts(): int
    {
        return Database::query(
            'DELETE FROM `users` WHERE `phone_number` LIKE :prefix',
            ['prefix' => self::QC_CASHIER_PREFIX]
        )->rowCount();
    }

    /**
     * Snapshot ringkas akun kasir — dipakai assertion diferensial "nol tulisan".
     *
     * @return array<string, array<string, mixed>>
     */
    public static function cashierSnapshot(): array
    {
        $rows = Database::fetchAll(
            'SELECT `phone_number`, `full_name`, `role`, `is_active`, `password_hash` '
            . 'FROM `users` WHERE `phone_number` LIKE :prefix ORDER BY `phone_number` ASC',
            ['prefix' => self::QC_CASHIER_PREFIX]
        );

        $snapshot = [];

        foreach ($rows as $row) {
            $snapshot[(string) $row['phone_number']] = [
                'name'   => (string) $row['full_name'],
                'role'   => (string) $row['role'],
                'active' => (int) $row['is_active'],
                'hash'   => (string) $row['password_hash'],
            ];
        }

        return $snapshot;
    }

    /**
     * Buat / segarkan satu akun kasir uji.
     *
     * @param array{phone: string, name: string, pin: string} $definition
     * @return array<string, mixed>
     */
    private static function ensureCashier(array $definition): array
    {
        $existing = User::findByPhoneNumber($definition['phone'], false);

        if ($existing === null) {
            $userId = User::createWithPin(
                $definition['name'],
                $definition['phone'],
                $definition['pin'],
                Constants::ROLE_CASHIER
            );
        } else {
            $userId = (string) $existing['id'];

            Database::update(
                'users',
                [
                    'full_name' => $definition['name'],
                    'role'      => Constants::ROLE_CASHIER,
                    'is_active' => 1,
                ],
                '`id` = :id',
                ['id' => $userId]
            );

            User::updatePin($userId, $definition['pin']);
        }

        $row = User::findByPhoneNumber($definition['phone'], false);

        if ($row === null) {
            throw new RuntimeException('Fixture kasir Fase 7 gagal dibuat: ' . $definition['phone']);
        }

        return $row;
    }

    /**
     * Kosongkan direktori pembatas percobaan login.
     */
    public static function clearPinThrottle(): int
    {
        return PinThrottle::clearAll();
    }

    /**
     * Hapus akun uji Fase 5 selain dua akun fixture, beserta dompetnya.
     *
     * Dompet dihapus lebih dulu karena `capital_wallets.user_id` memakai FK
     * `ON DELETE RESTRICT` (ERD § 4).
     */
    private static function purgeQcInvestorAccounts(): void
    {
        $keep = [self::QC_INVESTOR['phone'], self::QC_INVITEE['phone']];

        $rows = Database::fetchAll(
            'SELECT `id`, `phone_number` FROM `users` WHERE `phone_number` LIKE :prefix',
            ['prefix' => self::QC_INVESTOR_PREFIX]
        );

        foreach ($rows as $row) {
            $phone = (string) $row['phone_number'];

            if (in_array($phone, $keep, true)) {
                continue;
            }

            $userId = (string) $row['id'];

            Database::query('DELETE FROM `capital_wallets` WHERE `user_id` = :user_id', ['user_id' => $userId]);
            Database::query('DELETE FROM `users` WHERE `id` = :user_id', ['user_id' => $userId]);
        }
    }

    /**
     * Sisipkan satu sesi settlement berisi angka "kanari" (omzet toko, hak
     * pengelola, laba bersih, gramatur) yang harus tidak pernah terlihat mitra.
     *
     * @return string id sesi
     */
    private static function insertQcSettlement(int $offsetDays, string $utilized, string $profit): string
    {
        $id = Security::uuidV4();
        $date = date('Y-m-d', strtotime($offsetDays . ' days'));

        Database::query(
            'INSERT INTO `daily_settlements` '
            . '(`id`, `settlement_date`, `total_dry_grams`, `shop_water_density_pct`, `shop_price_per_g`, '
            . '`total_shop_revenue`, `daily_coordination_fee`, `consumable_opex`, `deficit_recovery_used`, '
            . '`net_settled_profit`, `operator_share_80`, `investor_pool_20`, `carryover_deficit`, '
            . '`receipt_photo_url`) VALUES '
            . '(:id, :date, :grams, NULL, 2080000.00, :revenue, 200000.00, 0.00, 0.00, :net, :operator, :pool, 0.00, NULL)',
            [
                'id'       => $id,
                'date'     => $date,
                'grams'    => self::QC_CANARIES['grams'],
                'revenue'  => self::QC_CANARIES['revenue'],
                'net'      => self::QC_CANARIES['net'],
                'operator' => self::QC_CANARIES['operator'],
                'pool'     => $profit,
            ]
        );

        return $id;
    }

    /**
     * Sen (integer) menjadi string desimal kanonik.
     */
    private static function canonicalCents(int $cents): string
    {
        return TellerService::centsToDecimal($cents);
    }
}

// ---------------------------------------------------------------------------
// Helper umum
// ---------------------------------------------------------------------------

/**
 * Jalankan `php <script>` sebagai subprocess dengan environment yang ditimpa.
 *
 * CATATAN PENTING soal cara override diterapkan:
 * PHP tidak meneruskan environment variable yang bernilai string kosong lewat
 * argumen env `proc_open()` — variabel tersebut sampai ke anak dalam keadaan
 * TIDAK ADA, bukan bernilai "". Akibatnya override `['TOKEN' => '']` akan
 * diam-diam jatuh kembali ke nilai di `.env`, dan test yang seharusnya menguji
 * cabang "nilai kosong" malah menguji hal lain.
 *
 * Karena itu override TIDAK dikirim sebagai environment, melainkan sebagai
 * JSON (`QC_ENV_OVERRIDES`, selalu tidak kosong sehingga aman) lalu diterapkan
 * di dalam proses anak memakai `putenv()` lewat stub `auto_prepend_file`.
 *
 * @param array<string, string> $env nilai kosong diperlakukan sebagai "isi dengan string kosong"
 * @param list<string>          $args
 * @return array{code: int, output: string}
 */
function qc_run_php(string $script, array $env = [], array $args = []): array
{
    $command = [PHP_BINARY];
    $childEnv = getenv();

    if ($env !== []) {
        $stub = qc_env_stub_path();

        // Stub menerima override sebagai JSON. Variabel JSON selalu tidak
        // kosong (minimal "{}"), jadi tidak terkena masalah yang dijelaskan
        // pada docblock di atas.
        $childEnv['QC_ENV_OVERRIDES'] = json_encode($env) ?: '{}';

        $command[] = '-d';
        $command[] = 'auto_prepend_file=' . $stub;
    }

    $command[] = $script;

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(
        array_merge($command, $args),
        $descriptors,
        $pipes,
        BASE_PATH,
        $childEnv
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
 * Tulis (sekali) stub yang menerapkan override environment di proses anak.
 *
 * Stub ini hidup di tests/.tmp/ yang di-gitignore dan hanya dipakai QC;
 * tidak pernah menyentuh kode produksi.
 */
function qc_env_stub_path(): string
{
    $directory = TEST_PATH . '/.tmp';

    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $path = $directory . '/qc_env_stub.php';

    if (!is_file($path)) {
        file_put_contents($path, <<<'PHP'
<?php

// Dibuat otomatis oleh tests/bootstrap.php — jangan disunting.
$overrides = json_decode((string) getenv('QC_ENV_OVERRIDES'), true);

if (is_array($overrides)) {
    foreach ($overrides as $name => $value) {
        putenv($name . '=' . $value);
    }
}
PHP);
    }

    return $path;
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
