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
 * Fase 1 membaca saja. Sejak Fase 3, `set()` dipakai mesin settlement untuk
 * menulis satu kunci: `active_carryover_deficit` (defisit hari minus yang
 * belum pulih). Panel admin penuh masuk fase berikutnya.
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

    /**
     * Baca satu nilai setting SAMBIL MENGUNCI barisnya (Fase 3).
     *
     * WAJIB dipanggil DI DALAM `Database::transaction()`. Dipakai mesin
     * defisit: tanpa penguncian, dua sesi tutup buku serentak dapat membaca
     * `active_carryover_deficit` yang sama lalu memotongnya dua kali
     * (risiko R-6 pada rencana Fase 3).
     */
    public static function getForUpdate(string $key): ?string
    {
        $row = Database::fetchOne(
            'SELECT `setting_value` FROM `system_settings` WHERE `setting_key` = :setting_key '
            . 'LIMIT 1 FOR UPDATE',
            ['setting_key' => $key]
        );

        if ($row === null) {
            return null;
        }

        return (string) ($row['setting_value'] ?? null);
    }

    /**
     * Tulis satu nilai setting (Fase 3).
     *
     * Hanya dipakai di dalam `Database::transaction()` pada mesin settlement.
     * Nilai diperlakukan sebagai string desimal kanonik sehingga DECIMAL tidak
     * kehilangan presisi.
     *
     * @throws \RuntimeException kunci tidak terdaftar (seed tidak lengkap)
     */
    public static function set(string $key, string $value): void
    {
        $exists = Database::fetchOne(
            'SELECT `setting_key` FROM `system_settings` WHERE `setting_key` = :setting_key LIMIT 1',
            ['setting_key' => $key]
        );

        if ($exists === null) {
            throw new \RuntimeException('Kunci setting "' . $key . '" tidak terdaftar pada basis data.');
        }

        Database::update(
            'system_settings',
            ['setting_value' => $value],
            '`setting_key` = :setting_key',
            ['setting_key' => $key]
        );
    }
}
