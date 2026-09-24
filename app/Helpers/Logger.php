<?php

declare(strict_types=1);

namespace AurumFlow\Helpers;

/**
 * Logger file sederhana tanpa dependensi (P SR-3-like subset).
 *
 * Format baris: `[YYYY-MM-DD HH:MM:SS] [level] pesan {context JSON}`
 *
 * Bila direktori log tidak dapat ditulis (mis. `open_basedir` pada shared
 * hosting — risiko R-4), penulisan otomatis jatuh ke `error_log()` bawaan
 * PHP sehingga tidak pernah memunculkan fatal error tambahan.
 */
final class Logger
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const CRITICAL = 'critical';

    private const MAX_MESSAGE_LENGTH = 4000;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        $line = sprintf('[%s] [%s] %s', date('Y-m-d H:i:s'), strtolower($level), self::flatten($message));

        if ($context !== []) {
            $encoded = json_encode(
                $context,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
            );

            if (is_string($encoded)) {
                $line .= ' ' . $encoded;
            }
        }

        self::write($line . PHP_EOL);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function debug(string $message, array $context = []): void
    {
        self::log(self::DEBUG, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::log(self::INFO, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log(self::WARNING, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::log(self::ERROR, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function critical(string $message, array $context = []): void
    {
        self::log(self::CRITICAL, $message, $context);
    }

    public static function directory(): string
    {
        return defined('LOG_PATH') ? (string) constant('LOG_PATH') : '';
    }

    /**
     * Path file log hari ini. Dipakai juga oleh script QC.
     */
    public static function currentFile(): string
    {
        $directory = self::directory();

        return $directory === '' ? '' : $directory . '/app-' . date('Y-m-d') . '.log';
    }

    /**
     * Paksa penulisan satu baris; mengembalikan false bila gagal total.
     * Dipakai script QC untuk memastikan jalur log benar-benar hidup.
     */
    public static function write(string $line): bool
    {
        $directory = self::directory();

        if ($directory !== '') {
            if (!is_dir($directory)) {
                // Jangan pakai @ agar kegagalan permission tidak memunculkan
                // error tambahan dari error handler kita sendiri.
                set_error_handler(static fn (): bool => true);
                $created = mkdir($directory, 0775, true);
                restore_error_handler();

                if (!$created && !is_dir($directory)) {
                    error_log(rtrim($line));

                    return false;
                }
            }

            if (is_writable($directory)) {
                set_error_handler(static fn (): bool => true);
                $written = file_put_contents(
                    $directory . '/app-' . date('Y-m-d') . '.log',
                    $line,
                    FILE_APPEND | LOCK_EX
                );
                restore_error_handler();

                if ($written !== false) {
                    return true;
                }
            }
        }

        // Fallback terakhir: error log bawaan SAPI (risiko R-4).
        error_log(rtrim($line));

        return false;
    }

    /**
     * Cegah pesan raksasa (mis. dump query) merusak file log.
     */
    private static function flatten(string $message): string
    {
        $message = str_replace(["\r\n", "\n", "\r"], ' | ', $message);

        if (strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $message = substr($message, 0, self::MAX_MESSAGE_LENGTH) . '…[dipotong]';
        }

        return $message;
    }
}
