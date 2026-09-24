<?php

declare(strict_types=1);

namespace AurumFlow\Models;

use AurumFlow\Config\Database;

/**
 * Model tabel `system_settings` (nama sesuai ERD).
 *
 * Parameter bisnis yang dapat diubah operator tanpa deploy: mode alokasi,
 * kuota serapan modal pengelola, jasa bakar flat, masa tunggu penarikan.
 *
 * Fase 1 hanya membaca. Penulisan dari panel admin masuk fase berikutnya.
 */
final class SystemSetting
{
    private function __construct()
    {
    }

    /**
     * Seluruh setting sebagai peta setting_key => setting_value.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $rows = Database::fetchAll(
            'SELECT `setting_key`, `setting_value` FROM `system_settings` ORDER BY `setting_key`'
        );

        $settings = [];

        foreach ($rows as $row) {
            $key = (string) ($row['setting_key'] ?? '');

            if ($key === '') {
                continue;
            }

            $settings[$key] = (string) ($row['setting_value'] ?? '');
        }

        return $settings;
    }

    /**
     * Satu nilai setting. Mengembalikan $default bila kunci tidak ada.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $row = Database::fetchOne(
            'SELECT `setting_value` FROM `system_settings` WHERE `setting_key` = :setting_key LIMIT 1',
            ['setting_key' => $key]
        );

        if ($row === null) {
            return $default;
        }

        return (string) ($row['setting_value'] ?? $default);
    }
}
