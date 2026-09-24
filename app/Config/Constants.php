<?php

declare(strict_types=1);

namespace AurumFlow\Config;

/**
 * Konstanta yang hidup di KODE.
 *
 * PENTING — pembagian tanggung jawab yang sengaja:
 *   Parameter bisnis (kuota serapan modal, jasa bakar flat, masa tunggu
 *   penarikan) TIDAK boleh didefinisikan di sini. Menurut ERD, parameter
 *   tersebut disimpan di tabel `system_settings` agar dapat diubah operator
 *   tanpa deploy. Menaruhnya di dua tempat akan menciptakan dua sumber
 *   kebenaran yang cepat atau lambat akan berbeda.
 *
 * Karena itu file ini hanya memuat nilai yang memang milik kode: nilai enum
 * peran dan konvensi tampilan angka.
 */
final class Constants
{
    /** Peran pengguna — harus sama persis dengan ENUM `users`.`role` pada ERD. */
    public const ROLE_OPERATOR = 'operator';

    public const ROLE_INVESTOR = 'investor';

    /** Konvensi tampilan Rupiah (ERD/PRD: tanpa desimal, `Rp 28.937.500`). */
    public const MONEY_DECIMALS = 0;

    /** Konvensi tampilan timbangan emas (ERD § 3.1: presisi 0,001 gram). */
    public const GRAM_DECIMALS = 3;

    /** Label satuan berat emas. */
    public const GRAM_UNIT = 'gr';

    private function __construct()
    {
    }
}
