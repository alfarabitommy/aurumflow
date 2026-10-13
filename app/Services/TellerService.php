<?php

declare(strict_types=1);

namespace AurumFlow\Services;

use AurumFlow\Config\Database;
use AurumFlow\Helpers\Logger;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\CashMutation;
use AurumFlow\Models\CashRegister;
use AurumFlow\Models\HuluTransaction;
use AurumFlow\Models\SystemSetting;
use PDO;

/**
 * Mesin kas meja hulu: kalkulasi harga tebus dan pencatatan kas keluar.
 *
 * Mengapa kelas ini ada (bukan di controller atau model):
 *   Controller menangani HTTP, model menangani SQL. Operasi kas menyentuh
 *   TIGA tabel dalam satu transaksi dan memiliki aturan pembulatan uang
 *   sendiri — itu use-case, bukan SQL. Lihat § 10.1 rencana Fase 2.
 *
 * Aturan uang (A-4 pada rencana) — TIDAK boleh dilanggar:
 *   1. Berat diurai ke MILIGRAM (integer, 3 desimal) dan harga ke SEN
 *      (integer, 2 desimal). Tidak ada float pada jalur uang.
 *   2. `grossCents = round_half_away(weightMg * priceCents / 1000)`.
 *   3. `netCents = grossCents - feeCents`, dengan `feeCents` dari
 *      `system_settings` (bukan konstanta PHP, bukan nilai dari klien).
 *   4. Nilai masuk database sebagai STRING desimal kanonik.
 *
 * Rumus bisnis (PRD § 6 Modul 1):
 *   Subtotal Tebus    = Berat Kering x Harga Tebus
 *   Uang Bersih Keluar = Subtotal Tebus - Jasa Bakar Flat
 */
final class TellerService
{
    /** Kunci parameter bisnis pada `system_settings` (ERD § 4 point 10). */
    public const SETTING_BURN_FEE = 'default_burn_fee';

    public const SETTING_OPERATOR_CUT = 'default_burn_fee_operator_cut';

    /** Anotasi pada `notes` mutasi kas. */
    public const MUTATION_PREFIX = 'Tebus hulu: ';

    /** Default kolom `farmer_alias` menurut ERD. */
    public const DEFAULT_ALIAS = 'Penambang';

    /** Batas kolom `farmer_alias` VARCHAR(50). */
    public const MAX_ALIAS_CHARS = 50;

    /** `dry_weight` DECIMAL(8,3) -> maksimum 99.999,999 gr. */
    private const MAX_WEIGHT_MG = 99999999;

    /** `buy_price_per_g` DECIMAL(14,2) -> maksimum Rp 999.999.999.999,99. */
    private const MAX_PRICE_CENTS = 99999999999999;

    /** Batas nilai uang pada kolom DECIMAL(14,2). */
    private const MAX_MONEY_CENTS = 99999999999999;

    private function __construct()
    {
    }

    // -----------------------------------------------------------------------
    // Konfigurasi jasa bakar (satu-satunya sumber: system_settings)
    // -----------------------------------------------------------------------

    /**
     * Parameter jasa bakar aktif beserta pembagiannya.
     *
     * `consumable_cut` DIHITUNG sebagai sisa (`fee - operator_cut`) karena
     * ERD tidak menyediakan kunci setting untuk porsi kowi/gas. Dengan begitu
     * invariant `operator_cut + consumable_cut = fee` selalu terjaga.
     *
     * @return array{fee: string, fee_cents: int, operator_cut: string,
     *               operator_cut_cents: int, consumable_cut: string,
     *               consumable_cut_cents: int}
     */
    public static function configuredBurnFee(): array
    {
        $feeCents = self::decimalToCents((string) SystemSetting::get(self::SETTING_BURN_FEE, '0.00'));
        $operatorCutCents = self::decimalToCents((string) SystemSetting::get(self::SETTING_OPERATOR_CUT, '0.00'));

        if ($feeCents < 0 || $operatorCutCents < 0 || $operatorCutCents > $feeCents) {
            throw new \RuntimeException(
                'Konfigurasi jasa bakar tidak konsisten pada system_settings. Hubungi administrator.'
            );
        }

        $consumableCutCents = $feeCents - $operatorCutCents;

        return [
            'fee'                  => self::centsToDecimal($feeCents),
            'fee_cents'            => $feeCents,
            'operator_cut'         => self::centsToDecimal($operatorCutCents),
            'operator_cut_cents'   => $operatorCutCents,
            'consumable_cut'       => self::centsToDecimal($consumableCutCents),
            'consumable_cut_cents' => $consumableCutCents,
        ];
    }

    // -----------------------------------------------------------------------
    // Kalkulasi (murni — dapat diuji tanpa database)
    // -----------------------------------------------------------------------

    /**
     * Hitung harga tebus untuk satu kedatangan penambang.
     *
     * @param array{fee_cents: int, operator_cut_cents: int}|null $burnFee
     *        null = baca dari `system_settings` (butuh database). Dikirim
     *        eksplisit oleh pemanggil yang ingin fungsi ini murni (QC).
     * @return array{weight: string, weight_mg: int, price: string, price_cents: int,
     *               gross: string, gross_cents: int, fee: string, fee_cents: int,
     *               operator_cut: string, operator_cut_cents: int,
     *               consumable_cut: string, consumable_cut_cents: int,
     *               net: string, net_cents: int, burn_fee_applied: bool}
     *
     * @throws \InvalidArgumentException input tidak sah (pesan siap tampil)
     */
    public static function quote(
        string $weightInput,
        string $priceInput,
        bool $burnFeeEnabled = true,
        ?array $burnFee = null
    ): array {
        $weightMg = self::parseWeightInput($weightInput);
        $priceCents = self::parsePriceInput($priceInput);
        $grossCents = self::grossCents($weightMg, $priceCents);

        $configured = $burnFee ?? self::configuredBurnFee();

        $feeCents = $burnFeeEnabled ? (int) $configured['fee_cents'] : 0;
        $operatorCutCents = $burnFeeEnabled ? (int) $configured['operator_cut_cents'] : 0;
        $consumableCutCents = $feeCents - $operatorCutCents;
        $netCents = $grossCents - $feeCents;

        if ($netCents <= 0) {
            throw new \InvalidArgumentException(
                'Kas keluar bersih harus lebih besar dari nol. Periksa berat, harga, dan jasa bakar.'
            );
        }

        if ($netCents > self::MAX_MONEY_CENTS) {
            throw new \InvalidArgumentException(
                'Nilai kas keluar melebihi kapasitas pembukuan. Pecah transaksi menjadi beberapa kedatangan.'
            );
        }

        return [
            'weight'               => self::milligramsToDecimal($weightMg),
            'weight_mg'            => $weightMg,
            'price'                => self::centsToDecimal($priceCents),
            'price_cents'          => $priceCents,
            'gross'                => self::centsToDecimal($grossCents),
            'gross_cents'          => $grossCents,
            'fee'                  => self::centsToDecimal($feeCents),
            'fee_cents'            => $feeCents,
            'operator_cut'         => self::centsToDecimal($operatorCutCents),
            'operator_cut_cents'   => $operatorCutCents,
            'consumable_cut'       => self::centsToDecimal($consumableCutCents),
            'consumable_cut_cents' => $consumableCutCents,
            'net'                  => self::centsToDecimal($netCents),
            'net_cents'            => $netCents,
            'burn_fee_applied'     => $burnFeeEnabled,
        ];
    }

    /**
     * Konversi nilai uang kanonik (`'21062500.00'`) menjadi sen (integer).
     *
     * Dipakai TellerController untuk mengirim saldo kas dan jasa bakar ke
     * peramban sebagai integer — pratinjau Alpine.js memakai satuan yang sama
     * dengan server, bukan float rupiah (risiko R-2).
     *
     * @throws \RuntimeException nilai rusak (bukan input pengguna)
     */
    public static function moneyToCents(string $decimal): int
    {
        return self::decimalToCents($decimal);
    }

    // -----------------------------------------------------------------------
    // Transaksi kas (tiga tabel, satu transaksi PDO — SAD § 4.1)
    // -----------------------------------------------------------------------

    /**
     * Bayar tunai ke penambang dan catat seluruh jejaknya.
     *
     * Urutan di dalam transaksi:
     *   1. kunci baris kas meja (`SELECT ... FOR UPDATE`);
     *   2. verifikasi saldo mencukupi (SEBELUM ada tulisan apa pun);
     *   3. INSERT `hulu_transactions` (status `holding`);
     *   4. UPDATE saldo `cash_registers` (saldo - net);
     *   5. INSERT `cash_mutations` (type `out`).
     *
     * Setiap kegagalan menggulung SELURUH langkah (tidak ada kas hilang).
     *
     * @param array{weight: string, price: string, farmer_alias?: string|null,
     *              burn_fee_enabled?: bool} $input
     * @return array{hulu_id: string, register_id: string, register_name: string,
     *               balance_before: string, balance_after: string,
     *               mutation_id: string, alias: string} + hasil `quote()`
     *
     * @throws \InvalidArgumentException input tidak sah
     * @throws \RuntimeException invariant dilanggar (kas tidak ada / saldo kurang)
     */
    public static function payCash(array $input): array
    {
        $weightInput = isset($input['weight']) && is_string($input['weight']) ? $input['weight'] : '';
        $priceInput = isset($input['price']) && is_string($input['price']) ? $input['price'] : '';
        $burnFeeEnabled = (bool) ($input['burn_fee_enabled'] ?? true);

        $alias = self::normaliseAlias($input['farmer_alias'] ?? null);
        $burnFee = self::configuredBurnFee();
        $quote = self::quote($weightInput, $priceInput, $burnFeeEnabled, $burnFee);

        $huluId = Security::uuidV4();
        $mutationId = Security::uuidV4();

        /** @var array<string, mixed> $result */
        $result = Database::transaction(static function (PDO $pdo) use (
            $quote,
            $alias,
            $huluId,
            $mutationId
        ): array {
            $register = CashRegister::lockTellerForUpdate();

            if ($register === null) {
                throw new \RuntimeException(
                    'Pos kas "' . CashRegister::TELLER_NAME . '" tidak ditemukan. Hubungi administrator.'
                );
            }

            $registerId = (string) $register['id'];
            $balanceBeforeCents = self::decimalToCents((string) $register['current_balance']);

            if ($balanceBeforeCents < $quote['net_cents']) {
                throw new \RuntimeException(
                    'Saldo kas meja tidak cukup untuk pembayaran ini. Isi kas terlebih dahulu atau pecah transaksi.'
                );
            }

            HuluTransaction::insert(
                $huluId,
                $alias,
                $quote['weight'],
                $quote['price'],
                $quote['gross'],
                $quote['fee'],
                $quote['operator_cut'],
                $quote['consumable_cut'],
                $quote['net']
            );

            CashRegister::adjustBalance($registerId, self::centsToDecimal(-$quote['net_cents']));

            CashMutation::insert(
                $mutationId,
                $registerId,
                CashMutation::TYPE_OUT,
                $quote['net'],
                self::mutationLabel($alias),
                self::mutationNotes($huluId, $quote)
            );

            return [
                'hulu_id'        => $huluId,
                'mutation_id'    => $mutationId,
                'register_id'    => $registerId,
                'register_name'  => (string) $register['register_name'],
                'alias'          => $alias,
                'balance_before' => self::centsToDecimal($balanceBeforeCents),
                'balance_after'  => self::centsToDecimal($balanceBeforeCents - $quote['net_cents']),
            ];
        });

        Logger::info('Transaksi kas meja tersimpan', [
            'hulu_id'         => $result['hulu_id'],
            'register'        => $result['register_name'],
            'berat_gr'        => $quote['weight'],
            'harga_per_gr'    => $quote['price'],
            'kas_keluar'      => $quote['net'],
            'saldo_sesudah'   => $result['balance_after'],
        ]);

        return $result + $quote;
    }

    // -----------------------------------------------------------------------
    // Parsing input lapangan
    // -----------------------------------------------------------------------

    /**
     * Berat kering: satu pemisah (`.` atau `,`) selalu berarti DESIMAL.
     *
     * `Formatter::parseGram()` sengaja TIDAK dipakai di sini: pada format
     * Indonesia titik adalah pemisah ribuan, sehingga
     * `Formatter::parseGram('14.850')` mengembalikan 14.850 gr (1000x lipat).
     * Aturan field ini dibekukan pada § 4.4 rencana (risiko R-3).
     *
     * @return int berat dalam miligram (integer, 3 desimal)
     */
    private static function parseWeightInput(string $raw): int
    {
        $value = trim($raw);

        if ($value === '') {
            throw new \InvalidArgumentException('Berat kering wajib diisi.');
        }

        if (preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException('Berat kering memuat karakter yang tidak dikenal.');
        }

        if (str_contains($value, '-')) {
            throw new \InvalidArgumentException('Berat kering tidak boleh negatif.');
        }

        $digits = preg_replace('/[^0-9.,]/', '', $value) ?? '';

        if ($digits === '') {
            throw new \InvalidArgumentException('Berat kering harus berupa angka. Contoh: 14,850');
        }

        if (str_contains($digits, ',') && str_contains($digits, '.')) {
            throw new \InvalidArgumentException('Gunakan satu pemisah desimal saja. Contoh: 14,850');
        }

        $digits = str_replace(',', '.', $digits);

        if (substr_count($digits, '.') > 1) {
            throw new \InvalidArgumentException('Berat kering tidak valid. Contoh: 14,850');
        }

        if (preg_match('/^(\d{1,5})(?:\.(\d{1,3}))?$/', $digits, $matches) !== 1) {
            throw new \InvalidArgumentException(
                'Berat kering tidak valid atau memuat lebih dari 3 desimal. Contoh: 14,850'
            );
        }

        $milligrams = (int) $matches[1] * 1000
            + (int) str_pad((string) ($matches[2] ?? ''), 3, '0', STR_PAD_RIGHT);

        if ($milligrams <= 0) {
            throw new \InvalidArgumentException('Berat kering harus lebih besar dari nol.');
        }

        if ($milligrams > self::MAX_WEIGHT_MG) {
            throw new \InvalidArgumentException(
                'Berat kering melebihi kapasitas timbangan (maksimal 99.999,999 gr).'
            );
        }

        return $milligrams;
    }

    /**
     * Harga tebus per gram: titik adalah pemisah RIBUAN, koma adalah desimal
     * (konsisten dengan `Formatter::parseRupiah()` dan kebiasaan lapangan).
     *
     * @return int harga dalam sen (integer, 2 desimal)
     */
    private static function parsePriceInput(string $raw): int
    {
        $value = trim($raw);

        if ($value === '') {
            throw new \InvalidArgumentException('Harga tebus per gram wajib diisi.');
        }

        if (preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException('Harga tebus memuat karakter yang tidak dikenal.');
        }

        if (str_contains($value, '-')) {
            throw new \InvalidArgumentException('Harga tebus tidak boleh negatif.');
        }

        $digits = preg_replace('/[^0-9.,]/', '', $value) ?? '';

        if ($digits === '') {
            throw new \InvalidArgumentException('Harga tebus harus berupa angka. Contoh: 1.950.000');
        }

        $fraction = '';

        if (str_contains($digits, ',')) {
            if (substr_count($digits, ',') > 1) {
                throw new \InvalidArgumentException('Harga tebus memuat lebih dari satu koma. Contoh: 1.950.000');
            }

            [$integer, $fraction] = explode(',', $digits, 2);
        } else {
            $integer = $digits;
        }

        if ($fraction !== '' && preg_match('/^\d{1,2}$/', $fraction) !== 1) {
            throw new \InvalidArgumentException('Desimal harga paling banyak 2 angka. Contoh: 1.950.000,50');
        }

        if ($integer === '' || $integer === '.') {
            throw new \InvalidArgumentException('Harga tebus harus berupa angka. Contoh: 1.950.000');
        }

        if (str_contains($integer, '.')
            && preg_match('/^\d{1,3}(?:\.\d{3})+$/', $integer) !== 1
        ) {
            throw new \InvalidArgumentException('Format ribuan harga tidak dikenal. Contoh: 1.950.000');
        }

        $plain = str_replace('.', '', $integer);

        if (preg_match('/^\d+$/', $plain) !== 1 || ltrim($plain, '0') === '') {
            throw new \InvalidArgumentException('Harga tebus harus lebih besar dari nol.');
        }

        if (strlen($plain) > 12) {
            throw new \InvalidArgumentException('Harga tebus melebihi kapasitas pembukuan.');
        }

        $cents = (int) $plain * 100
            + (int) str_pad($fraction, 2, '0', STR_PAD_RIGHT);

        if ($cents <= 0) {
            throw new \InvalidArgumentException('Harga tebus harus lebih besar dari nol.');
        }

        if ($cents > self::MAX_PRICE_CENTS) {
            throw new \InvalidArgumentException(
                'Harga tebus melebihi kapasitas kolom (maksimal Rp 999.999.999.999,99 per gram).'
            );
        }

        return $cents;
    }

    /**
     * Nama penambang: dibersihkan dari markup, dibatasi panjang kolom.
     */
    private static function normaliseAlias(mixed $raw): string
    {
        if (!is_string($raw)) {
            return self::DEFAULT_ALIAS;
        }

        $alias = Security::sanitize($raw);

        if ($alias === '') {
            return self::DEFAULT_ALIAS;
        }

        if (preg_match('//u', $alias) !== 1) {
            throw new \InvalidArgumentException('Nama penambang memuat karakter yang tidak dikenal.');
        }

        $characters = preg_match_all('/./us', $alias);

        if ($characters === false || $characters > self::MAX_ALIAS_CHARS) {
            throw new \InvalidArgumentException(
                'Nama penambang maksimal ' . self::MAX_ALIAS_CHARS . ' karakter.'
            );
        }

        return $alias;
    }

    // -----------------------------------------------------------------------
    // Aritmetika uang berbasis integer
    // -----------------------------------------------------------------------

    /**
     * Nilai tebus kotor dalam sen.
     *
     * `weightMg x priceCents` berada pada skala 10^5 rupiah; membaginya
     * dengan 1000 menghasilkan sen dengan pembulatan half away from zero
     * (selalu positif di sini). Penjaga overflow dipakai karena hasil kali
     * dapat melampaui PHP_INT_MAX pada input ekstrem.
     */
    private static function grossCents(int $weightMg, int $priceCents): int
    {
        if ($priceCents > intdiv(PHP_INT_MAX, $weightMg)) {
            throw new \InvalidArgumentException('Nilai tebus di luar jangkauan perhitungan.');
        }

        $product = $weightMg * $priceCents;
        $cents = intdiv($product + 500, 1000);

        if ($cents > self::MAX_MONEY_CENTS) {
            throw new \InvalidArgumentException(
                'Nilai tebus melebihi kapasitas pembukuan. Pecah transaksi menjadi beberapa kedatangan.'
            );
        }

        return $cents;
    }

    /**
     * String desimal kanonik -> sen (integer). Dipakai untuk nilai dari
     * database/setting yang sudah pasti berskala 2.
     *
     * @throws \RuntimeException nilai rusak (bukan input pengguna)
     */
    private static function decimalToCents(string $value): int
    {
        $value = trim($value);

        if (preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            throw new \RuntimeException('Nilai uang tidak valid pada penyimpanan.');
        }

        $cents = (int) $matches[2] * 100
            + (int) str_pad((string) ($matches[3] ?? ''), 2, '0', STR_PAD_RIGHT);

        return ($matches[1] ?? '') === '-' ? -$cents : $cents;
    }

    /**
     * Sen (integer) menjadi string desimal kanonik (`'28937500.00'`).
     *
     * Pasangan `moneyToCents()`; SATU pemilik konversi uang untuk seluruh
     * aplikasi. Fase 3 memakainya lewat `SettlementService`; sejak Fase 4 juga
     * dipakai `AllocationEngine` dan `CapitalService` — jangan membuat salinan
     * ketiga dari fungsi yang sama.
     */
    public static function centsToDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function milligramsToDecimal(int $milligrams): string
    {
        return intdiv($milligrams, 1000) . '.'
            . str_pad((string) ($milligrams % 1000), 3, '0', STR_PAD_LEFT);
    }

    // -----------------------------------------------------------------------
    // Keterangan mutasi kas
    // -----------------------------------------------------------------------

    /**
     * `source_destination` VARCHAR(100) — maksimum 62 karakter pada kondisi
     * terburuk (prefix 12 + alias 50), sehingga tidak pernah terpotong.
     */
    private static function mutationLabel(string $alias): string
    {
        return self::MUTATION_PREFIX . $alias;
    }

    /**
     * Jejak audit pada kolom `notes`: mengaitkan mutasi kas ke baris hulu.
     *
     * @param array<string, mixed> $quote
     */
    private static function mutationNotes(string $huluId, array $quote): string
    {
        return sprintf(
            'hulu_transaction_id=%s; berat=%s gr; harga=%s/gr; jasa_bakar=%s; upah_pengelola=%s; konsumabel=%s',
            $huluId,
            (string) $quote['weight'],
            (string) $quote['price'],
            (string) $quote['fee'],
            (string) $quote['operator_cut'],
            (string) $quote['consumable_cut']
        );
    }
}
