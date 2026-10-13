<?php

declare(strict_types=1);

namespace AurumFlow\Models;

use AurumFlow\Config\Database;

/**
 * Model tabel `cash_registers` — pos fisik kas operasional.
 *
 * Nama pos kas meja ditulis SEKALI di sini (`TELLER_NAME`) supaya tidak
 * tersebar sebagai string ajaib di controller maupun view. Alternatif
 * (kunci `system_settings` baru atau nilai `.env`) sengaja tidak dipakai pada
 * Fase 2 — lihat keputusan `dec-96114fafe2590e91` dan item O-6 pada
 * `plan/Plan_Fase_2_AurumFlow.md`.
 */
final class CashRegister
{
    /** Pos kas yang dipakai meja kasir hulu (lihat seed ERD § 4 point 10). */
    public const TELLER_NAME = 'Kas Meja Bakar';

    /**
     * Pos kas penyimpan laba bersih putaran (Fase 3).
     *
     * Smart Auto-Split menaruh "laba bersih" di sini dan mengembalikan modal
     * belanja hulu ke `TELLER_NAME`.
     */
    public const BRANKAS_NAME = 'Brankas Utama';

    private function __construct()
    {
    }

    /**
     * Baris pos kas berdasarkan nama (tanpa penguncian).
     *
     * @return array<string, mixed>|null
     */
    public static function findByName(string $registerName): ?array
    {
        return Database::fetchOne(
            'SELECT `id`, `register_name`, `current_balance`, `updated_at` '
            . 'FROM `cash_registers` WHERE `register_name` = :register_name LIMIT 1',
            ['register_name' => $registerName]
        );
    }

    /**
     * Pos kas meja bakar — dipakai layar kasir untuk menampilkan saldo.
     *
     * @return array<string, mixed>|null
     */
    public static function activeTeller(): ?array
    {
        return self::findByName(self::TELLER_NAME);
    }

    /**
     * Pos kas brankas utama — penyimpan laba bersih putaran (Fase 3).
     *
     * @return array<string, mixed>|null
     */
    public static function findBrankas(): ?array
    {
        return self::findByName(self::BRANKAS_NAME);
    }

    /**
     * Kunci baris pos kas meja bakar untuk durasi transaksi.
     *
     * WAJIB dipanggil DI DALAM `Database::transaction()`. `FOR UPDATE`
     * menyerialkan belanja kas sehingga dua terminal tidak dapat
     * membelanjakan saldo yang sama (risiko R-5).
     *
     * @return array<string, mixed>|null
     */
    public static function lockTellerForUpdate(): ?array
    {
        return self::lockByNameForUpdate(self::TELLER_NAME);
    }

    /**
     * Kunci satu baris pos kas berdasarkan nama.
     *
     * @return array<string, mixed>|null
     */
    public static function lockByNameForUpdate(string $registerName): ?array
    {
        return Database::fetchOne(
            'SELECT `id`, `register_name`, `current_balance` '
            . 'FROM `cash_registers` WHERE `register_name` = :register_name LIMIT 1 FOR UPDATE',
            ['register_name' => $registerName]
        );
    }

    /**
     * Kunci beberapa pos kas sekaligus dengan URUTAN TETAP (nama menaik).
     *
     * Urutan yang selalu sama mencegah deadlock: settlement menyentuh dua pos
     * (meja + brankas) sementara jalur kasir menyentuh satu pos, sehingga
     * tanpa urutan tetap dua proses dapat saling menunggu (risiko R-5 pada
     * rencana Fase 3). Panggilan terpisah — bukan satu `IN (...)` — dipakai
     * karena MySQL tidak menjamin urutan penguncian pada klausa `IN`.
     *
     * @param list<string> $names
     * @return array<string, array<string, mixed>> peta nama => baris
     */
    public static function lockByNamesForUpdate(array $names): array
    {
        $ordered = [];

        foreach ($names as $name) {
            if (is_string($name) && $name !== '') {
                $ordered[$name] = true;
            }
        }

        $ordered = array_keys($ordered);
        sort($ordered, SORT_STRING);

        $rows = [];

        foreach ($ordered as $name) {
            $row = self::lockByNameForUpdate($name);

            if ($row !== null) {
                $rows[$name] = $row;
            }
        }

        return $rows;
    }

    /**
     * Ubah saldo secara relatif (`current_balance = current_balance + delta`).
     *
     * Relatif, bukan absolut: nilai yang dibaca sebelum transaksi tetap sah
     * walau baris sempat terkunci lama. `$delta` adalah string desimal
     * kanonik (`-28937500.00`) agar DECIMAL tidak kehilangan presisi.
     *
     * @return int jumlah baris terpengaruh (0 = pos kas tidak ditemukan)
     */
    public static function adjustBalance(string $registerId, string $delta): int
    {
        return Database::query(
            'UPDATE `cash_registers` SET `current_balance` = `current_balance` + :delta '
            . 'WHERE `id` = :register_id',
            ['delta' => $delta, 'register_id' => $registerId]
        )->rowCount();
    }
}
