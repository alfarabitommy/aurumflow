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

    /** Kasir meja bakar (Fase 7) — staf lapangan, bukan mitra modal. */
    public const ROLE_CASHIER = 'cashier';

    /**
     * Label tampilan per peran — SATU-SATUNYA pemilik label peran.
     *
     * Nilai enum mentah (`operator`/`investor`/`cashier`) tidak pernah dicetak
     * ke layar; layout, dashboard, dan view lain membacanya lewat
     * `roleLabel()`. Pola yang sama dengan `CapitalWallet::TIER_LABELS`.
     *
     * @var array<string, string>
     */
    public const ROLE_LABELS = [
        self::ROLE_OPERATOR => 'Pengelola (Operator)',
        self::ROLE_INVESTOR => 'Mitra Pemodal (Investor)',
        self::ROLE_CASHIER  => 'Kasir Meja Bakar',
    ];

    /**
     * Label satu peran. Peran tak dikenal (sesi rusak) dikembalikan apa adanya
     * supaya layar tidak pernah kosong tanpa penjelasan.
     */
    public static function roleLabel(string $role): string
    {
        return self::ROLE_LABELS[$role] ?? $role;
    }

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
