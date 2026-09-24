<?php

declare(strict_types=1);

namespace AurumFlow\Config;

use AurumFlow\Helpers\Logger;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Satu-satunya titik akses database untuk seluruh aplikasi.
 *
 * Kontrak keamanan yang TIDAK boleh dilanggar oleh kode pemanggil:
 *   1. Setiap nilai dinamis HARUS dikirim sebagai named parameter (:nama).
 *      Dilarang menyisipkan variabel apa pun ke dalam string SQL.
 *   2. `$where` pada update() adalah fragmen SQL yang ditulis programmer di
 *      kode — bukan input pengguna. Nilainya tetap wajib lewat placeholder.
 *   3. Nama tabel dan kolom divalidasi terhadap pola identifier sederhana
 *      sebelum dibungkus backtick, sehingga tidak dapat dipakai sebagai
 *      vektor injeksi lewat nama kolom.
 *
 * Konfigurasi driver sengaja tidak terikat MySQL secara kaku — seluruh
 * perbedaan environment (hosting MySQL 8.0 vs database pengujian) berada
 * di `.env`, bukan di kode.
 */
final class Database
{
    /** Identifier MySQL/MariaDB yang aman. */
    private const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private static ?PDO $connection = null;

    private function __construct()
    {
    }

    /**
     * Koneksi PDO singleton per request (QC 1.8).
     */
    public static function getConnection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        self::$connection = self::create();

        return self::$connection;
    }

    /**
     * Buang koneksi. Hanya dipakai script QC dan skenario instalasi;
     * aplikasi normal tidak perlu memanggilnya.
     */
    public static function disconnect(): void
    {
        self::$connection = null;
    }

    // -----------------------------------------------------------------------
    // Query helpers — semuanya prepared statement
    // -----------------------------------------------------------------------

    /**
     * @param array<string|int, mixed> $params
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $statement = self::getConnection()->prepare($sql);

        foreach ($params as $key => $value) {
            $placeholder = is_int($key)
                ? $key + 1
                : (str_starts_with((string) $key, ':') ? (string) $key : ':' . $key);

            $statement->bindValue($placeholder, $value, self::paramType($value));
        }

        $statement->execute();

        return $statement;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null null bila tidak ada baris (QC 1.9)
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return list<array<string, mixed>>
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = self::query($sql, $params)->fetchAll();

        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function insert(string $table, array $data): int
    {
        self::assertIdentifier($table, 'tabel');

        if ($data === []) {
            throw new \InvalidArgumentException('Database::insert() membutuhkan minimal satu kolom.');
        }

        $columns = array_keys($data);
        self::assertIdentifiers($columns, 'kolom');

        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`)'
            . ' VALUES (:' . implode(', :', $columns) . ')';

        $params = [];

        foreach ($data as $column => $value) {
            $params[':' . $column] = $value;
        }

        self::query($sql, $params);

        return (int) self::getConnection()->lastInsertId();
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, mixed>  $whereParams
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        self::assertIdentifier($table, 'tabel');

        if ($data === []) {
            throw new \InvalidArgumentException('Database::update() membutuhkan minimal satu kolom.');
        }

        $assignments = [];
        $params = [];

        foreach ($data as $column => $value) {
            self::assertIdentifier($column, 'kolom');
            $assignments[] = '`' . $column . '` = :set_' . $column;
            $params[':set_' . $column] = $value;
        }

        foreach ($whereParams as $key => $value) {
            $placeholder = is_int($key)
                ? $key + 1
                : (str_starts_with((string) $key, ':') ? (string) $key : ':' . $key);

            $params[$placeholder] = $value;
        }

        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $assignments) . ' WHERE ' . $where;

        return self::query($sql, $params)->rowCount();
    }

    /**
     * Jalankan callback di dalam transaksi. Rollback otomatis pada Throwable.
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::getConnection();
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    // -----------------------------------------------------------------------
    // Utilitas instalasi (dipakai database/install.php, bukan jalur request)
    // -----------------------------------------------------------------------

    /**
     * Jalankan seluruh statement pada satu file .sql.
     * Mengembalikan jumlah statement yang dieksekusi.
     */
    public static function executeSqlFile(string $path): int
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('File SQL tidak ditemukan atau tidak dapat dibaca: ' . $path);
        }

        $sql = file_get_contents($path);

        if ($sql === false) {
            throw new \RuntimeException('Gagal membaca file SQL: ' . $path);
        }

        $pdo = self::getConnection();
        $executed = 0;

        foreach (self::splitStatements($sql) as $statement) {
            $pdo->exec($statement);
            $executed++;
        }

        return $executed;
    }

    /**
     * Pemecah statement SQL.
     *
     * Sadar-konteks: titik koma di dalam string literal, identifier
     * ber-backtick, dan komentar tidak dianggap pemisah. Batasan yang
     * didokumentasikan: tidak menangani DELIMITER/stored procedure karena
     * skema Fase 1 tidak memakainya.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);

        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                    $buffer .= $char;
                }

                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }

                continue;
            }

            if ($inSingle || $inDouble) {
                $buffer .= $char;

                if ($char === '\\' && $next !== '') {
                    $buffer .= $next;
                    $i++;

                    continue;
                }

                if (($inSingle && $char === "'") || ($inDouble && $char === '"')) {
                    $inSingle = false;
                    $inDouble = false;
                }

                continue;
            }

            if ($inBacktick) {
                $buffer .= $char;

                if ($char === '`') {
                    $inBacktick = false;
                }

                continue;
            }

            // Komentar baris: `-- ` (wajib diikuti spasi) dan `#`.
            if (($char === '-' && $next === '-' && ($sql[$i + 2] ?? ' ') === ' ')
                || ($char === '-' && $next === '-' && $i + 2 >= $length)
                || $char === '#'
            ) {
                $inLineComment = true;
                $i += ($char === '-') ? 1 : 0;

                continue;
            }

            if ($char === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;

                continue;
            }

            if ($char === "'") {
                $inSingle = true;
                $buffer .= $char;

                continue;
            }

            if ($char === '"') {
                $inDouble = true;
                $buffer .= $char;

                continue;
            }

            if ($char === '`') {
                $inBacktick = true;
                $buffer .= $char;

                continue;
            }

            if ($char === ';') {
                $trimmed = trim($buffer);

                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }

                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        $trimmed = trim($buffer);

        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    // -----------------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------------

    private static function create(): PDO
    {
        $host = self::envOrDefault('DB_HOST', '127.0.0.1');
        $port = Env::int('DB_PORT', 3306);
        $name = self::envOrDefault('DB_NAME', '');
        $user = self::envOrDefault('DB_USER', '');
        $password = Env::get('DB_PASS', '') ?? '';
        $charset = self::envOrDefault('DB_CHARSET', 'utf8mb4');
        $collation = self::envOrDefault('DB_COLLATION', 'utf8mb4_unicode_ci');

        if ($name === '') {
            throw new DatabaseException('Konfigurasi database belum lengkap. Hubungi administrator.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        $options = [
            // Error tidak pernah silent — kunci QC 1.6.
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Prepared statement NATIVE, bukan emulasi di sisi klien —
            // kunci pertahanan SQL injection (QC 2.7).
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_PERSISTENT         => false,
            PDO::ATTR_TIMEOUT            => 5,
        ];

        // Konstanta ini hanya ada pada driver MySQL. Menjaganya dengan
        // defined() membuat lapisan ini tidak fatal bila driver berganti.
        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = sprintf(
                'SET NAMES %s COLLATE %s',
                $charset,
                $collation
            );
        }

        try {
            $pdo = new PDO($dsn, $user, $password, $options);
        } catch (PDOException $exception) {
            // DSN tidak memuat password; pesan server juga tidak. Tetap
            // dicatat lengkap untuk operator, tapi pengguna hanya melihat
            // pesan generik.
            Logger::critical('Koneksi database gagal', [
                'dsn'   => $dsn,
                'sqlstate' => (string) $exception->getCode(),
                'error' => $exception->getMessage(),
            ]);

            throw new DatabaseException(
                'Koneksi database gagal. Hubungi administrator.',
                0,
                $exception
            );
        }

        // Timezone session diselaraskan dengan timezone aplikasi sehingga
        // NOW() dan date() PHP tidak pernah berbeda (risiko R-8).
        $pdo->exec("SET time_zone = '" . App::utcOffset() . "'");

        self::assertRuntimeConfiguration($pdo);

        return $pdo;
    }

    /**
     * Verifikasi asumsi keamanan pada koneksi hidup. Gagal cepat di sini
     * lebih baik daripada diam-diam berjalan tanpa prepared statement.
     */
    private static function assertRuntimeConfiguration(PDO $pdo): void
    {
        $emulated = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);

        if ($emulated === true || $emulated === 1 || $emulated === '1') {
            throw new DatabaseException(
                'Konfigurasi database tidak aman: prepared statement emulasi aktif. Hubungi administrator.'
            );
        }
    }

    private static function envOrDefault(string $key, string $default): string
    {
        $value = Env::get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return $value;
    }

    /**
     * PDO::PARAM_STR untuk float agar DECIMAL tidak kehilangan presisi
     * (QC 1.10) — MySQL mengonversi string desimal secara eksak.
     */
    private static function paramType(mixed $value): int
    {
        return match (true) {
            $value === null  => PDO::PARAM_NULL,
            is_bool($value)  => PDO::PARAM_BOOL,
            is_int($value)   => PDO::PARAM_INT,
            default          => PDO::PARAM_STR,
        };
    }

    private static function assertIdentifiers(array $identifiers, string $label): void
    {
        foreach ($identifiers as $identifier) {
            self::assertIdentifier((string) $identifier, $label);
        }
    }

    private static function assertIdentifier(string $identifier, string $label): void
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
            throw new \InvalidArgumentException(
                'Nama ' . $label . ' tidak valid: hanya huruf, angka, dan garis bawah yang diizinkan.'
            );
        }
    }
}
