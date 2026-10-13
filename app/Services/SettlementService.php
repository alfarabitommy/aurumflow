<?php

declare(strict_types=1);

namespace AurumFlow\Services;

use AurumFlow\Helpers\Logger;
use AurumFlow\Helpers\Security;
use AurumFlow\Config\Database;
use AurumFlow\Models\BridgeLoan;
use AurumFlow\Models\CapitalWallet;
use AurumFlow\Models\CashMutation;
use AurumFlow\Models\CashRegister;
use AurumFlow\Models\DailySettlement;
use AurumFlow\Models\HuluTransaction;
use AurumFlow\Models\SystemSetting;
use PDO;

/**
 * Mesin tutup buku harian (Fase 3): likuidasi toko, pemulihan defisit, bagi hasil.
 *
 * Mengapa kelas ini ada (bukan di controller atau model):
 *   Controller menangani HTTP, model menangani SQL. Satu sesi tutup buku
 *   menyentuh ENAM kelompok tulisan dalam satu transaksi (`daily_settlements`,
 *   `hulu_transactions`, `cash_registers` ×2, `cash_mutations` ×2,
 *   `system_settings`, dan sejak Fase 4: `profit_distributions` +
 *   `capital_wallets.balance_profit` lewat `AllocationEngine`) dan memiliki
 *   aturan uang sendiri — itu use-case, bukan SQL. Pola yang sama dengan
 *   `TellerService` (Fase 2).
 *
 * Aturan uang (rencana Fase 3 § 4.1) — TIDAK boleh dilanggar:
 *   1. Berat & harga jual diurai oleh `TellerService::quote()` sehingga aturan
 *      parsing/pembulatan hanya punya SATU pemilik. Pendapatan toko = nilai
 *      tebus kotor dari fungsi itu.
 *   2. Modal belanja hulu dibaca dari `SUM(net_cash_paid)` baris `holding`
 *      (bukan dari klien, bukan dari gram).
 *   3. Seluruh perhitungan lanjutan memakai SEN integer; nilai ke database
 *      selalu string desimal kanonik.
 *   4. Tidak ada float pada jalur uang.
 *
 * Permukaan publik kelas ini sengaja sempit: `quote()` + `batchContext()`
 * (kalkulasi & pratinjau), `settle()` (satu-satunya jalur mutasi tutup buku),
 * dan `receiptDirectory()` (dipakai fixture QC). Validasi serta penyimpanan
 * berkas nota bersifat internal.
 *
 * Rumus bisnis (PRD § 6 Modul 2 + Roadmap Protokol QC Manual 3):
 *   Pendapatan Toko = Berat Dijual x Harga Jual Riil
 *   Laba Kotor      = Pendapatan Toko - Modal Belanja Hulu
 *   Laba Bersih     = Laba Kotor - Beban Aparat - Pemulihan Defisit
 *   Bagi hasil      = 80% Pengelola, 20% Pool Investor (pembukuan saja sampai
 *                     Fase 4 mendistribusikan ke dompet masing-masing)
 *   Hari minus      = defisit masuk `carryover_deficit` TANPA memotong modal
 *                     investor (PRD § 4.2)
 */
final class SettlementService
{
    /** Kunci `system_settings` untuk defisit berjalan (ERD § 4 point 10). */
    public const SETTING_ACTIVE_DEFICIT = 'active_carryover_deficit';

    /** Nama field berkas pada form multipart. */
    public const RECEIPT_FIELD = 'receipt_photo';

    /** Direktori nota, relatif terhadap `public/`. */
    public const RECEIPT_DIR = 'uploads/receipts';

    /** Target lunak kompresi peramban (dokumen Roadmap: "di bawah 250 KB"). */
    public const RECEIPT_SOFT_LIMIT_BYTES = 256000;

    /** Batas keras yang ditegakkan peladen. */
    public const RECEIPT_HARD_LIMIT_BYTES = 4194304;

    /** Porsi pengelola dari laba bersih (sisanya pool investor). */
    public const OPERATOR_SHARE_PCT = 80;

    /** Awalan keterangan mutasi kas masuk. */
    public const MUTATION_PREFIX = 'Settlement ';

    /** `total_dry_grams` DECIMAL(8,3) -> maksimum 99.999,999 gr. */
    private const MAX_WEIGHT_MG = 99999999;

    /** Batas nilai kolom DECIMAL(14,2). */
    private const MAX_MONEY_CENTS = 99999999999999;

    /** `shop_water_density_pct` DECIMAL(5,2) -> maksimum 100,00 %. */
    private const MAX_DENSITY_HUNDREDTHS = 10000;

    /** Tipe gambar yang diterima, beserta ekstensi simpanannya. */
    private const IMAGE_TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];

    private function __construct()
    {
    }

    // -----------------------------------------------------------------------
    // Kalkulasi (murni bila `$context` dikirim eksplisit)
    // -----------------------------------------------------------------------

    /**
     * Hitung seluruh angka satu sesi tutup buku.
     *
     * Tidak menulis apa pun. Bila `$context` tidak dikirim, konteks batch
     * dibaca dari database (tanpa penguncian) — `settle()` selalu mengirim
     * konteks hasil pembacaan TERKUNCI sehingga angka yang ditulis berasal
     * dari himpunan baris yang benar-benar diamankan transaksi.
     *
     * @param array<string, mixed> $input kunci: sold_grams, density_pct,
     *        shop_price_per_g, coord_fee, kas_split, brankas_split
     * @param array{holding_mg: int, holding_cost_cents: int,
     *              active_deficit_cents: int}|null $context
     * @return array<string, mixed> nilai kanonik + sen untuk perhitungan lanjutan
     *
     * @throws \InvalidArgumentException input tidak sah (pesan siap tampil)
     * @throws \RuntimeException invariant dilanggar (tanpa emas holding, nilai rusak)
     */
    public static function quote(array $input, ?array $context = null): array
    {
        $soldInput = self::stringInput($input['sold_grams'] ?? null);
        $priceInput = self::stringInput($input['shop_price_per_g'] ?? null);

        if ($soldInput === '') {
            throw new \InvalidArgumentException('Total berat dijual ke toko wajib diisi. Contoh: 18,050');
        }

        if ($priceInput === '') {
            throw new \InvalidArgumentException('Harga jual riil toko per gram wajib diisi. Contoh: 2.080.000');
        }

        $densityHundredths = self::parseDensity($input['density_pct'] ?? null);
        $coordFeeCents = self::parseMoneyInput($input['coord_fee'] ?? null, 'Beban aparat');

        // Dua isian alokasi yang sama-sama kosong berarti "pakai saran sistem".
        // Ini yang membuat form tetap benar tanpa JavaScript: operator cukup
        // mengisi penjualan toko, dan peladen menyusun alokasinya.
        $kasRaw = self::stringInput($input['kas_split'] ?? null);
        $brankasRaw = self::stringInput($input['brankas_split'] ?? null);
        $splitFollowsSuggestion = ($kasRaw === '' && $brankasRaw === '');

        // Berat & harga: satu pemilik aturan (TellerService). Jasa bakar
        // nonaktif sehingga hasilnya murni "nilai tebus" = pendapatan toko.
        $priced = TellerService::quote($soldInput, $priceInput, false, [
            'fee_cents'          => 0,
            'operator_cut_cents' => 0,
        ]);

        $context ??= self::batchContext();

        $holdingMg = (int) ($context['holding_mg'] ?? 0);
        $holdingCostCents = (int) ($context['holding_cost_cents'] ?? 0);
        $activeDeficitCents = (int) ($context['active_deficit_cents'] ?? 0);

        if ($holdingMg <= 0) {
            throw new \RuntimeException(
                'Tidak ada emas berstatus holding untuk disettle. Catat pembelian hulu terlebih dahulu.'
            );
        }

        if ($holdingCostCents < 0 || $activeDeficitCents < 0) {
            throw new \RuntimeException('Nilai modal/defisit pada basis data tidak valid. Hubungi administrator.');
        }

        $soldMg = (int) $priced['weight_mg'];

        if ($soldMg > $holdingMg) {
            throw new \InvalidArgumentException(
                'Berat jual melebihi emas yang dipegang (maksimal '
                . self::milligramsToDecimal($holdingMg) . ' gr).'
            );
        }

        $revenueCents = (int) $priced['gross_cents'];
        $grossCents = $revenueCents - $holdingCostCents;
        $afterFeeCents = $grossCents - $coordFeeCents;

        // Defisit hari sebelumnya dipotong LEBIH DAHULU, dan hanya dari laba
        // yang benar-benar ada (tidak pernah menyentuh modal investor).
        $recoveryCents = min($activeDeficitCents, max(0, $afterFeeCents));
        $netCents = $afterFeeCents - $recoveryCents;
        $carryoverCents = max(0, -$netCents);
        $activeAfterCents = $activeDeficitCents - $recoveryCents + $carryoverCents;

        if ($netCents > self::MAX_MONEY_CENTS) {
            throw new \InvalidArgumentException(
                'Laba bersih melebihi kapasitas pembukuan. Pecah penjualan menjadi beberapa sesi.'
            );
        }

        // Bagi hasil HANYA saat laba positif (hari minus: 0 / 0).
        $operatorCents = $netCents > 0 ? self::shareOf($netCents, self::OPERATOR_SHARE_PCT) : 0;
        $poolCents = $netCents > 0 ? $netCents - $operatorCents : 0;

        // Kas fisik yang diterima hari ini. Bila pendapatan tidak menutup
        // beban aparat, tidak ada kas yang dapat dialokasikan.
        $totalCashCents = $revenueCents - $coordFeeCents;

        if ($totalCashCents < 0) {
            throw new \InvalidArgumentException(
                'Pendapatan toko lebih kecil dari beban aparat — tidak ada kas yang dapat dialokasikan.'
            );
        }

        // Saran Smart Auto-Split: laba bersih ke brankas, sisanya (modal
        // belanja hulu + defisit yang dipulihkan) kembali ke meja.
        $suggestedBrankasCents = max(0, $netCents);
        $suggestedKasCents = $totalCashCents - $suggestedBrankasCents;

        if ($suggestedKasCents < 0) {
            throw new \InvalidArgumentException(
                'Kas yang diterima lebih kecil dari kewajiban pengembalian modal. Periksa harga jual toko.'
            );
        }

        if ($splitFollowsSuggestion) {
            $kasCents = $suggestedKasCents;
            $brankasCents = $suggestedBrankasCents;
        } else {
            $kasCents = self::parseMoneyInput($kasRaw, 'Alokasi Kas Meja Bakar');
            $brankasCents = self::parseMoneyInput($brankasRaw, 'Alokasi Brankas Utama');
        }

        if ($kasCents < 0 || $brankasCents < 0) {
            throw new \InvalidArgumentException('Alokasi kas tidak boleh negatif.');
        }

        if ($kasCents + $brankasCents !== $totalCashCents) {
            throw new \InvalidArgumentException(
                'Alokasi kas harus berjumlah persis kas yang diterima ('
                . self::centsToDecimal($totalCashCents) . '). Selisih: '
                . self::centsToDecimal($totalCashCents - $kasCents - $brankasCents) . '.'
            );
        }

        // Dana talangan (Fase 6): fee diserap HANYA dari porsi pengelola, dan
        // penyerapannya dihitung SETELAH bagi hasil 80 : 20 sehingga pool mitra
        // tidak mungkin menanggungnya (PRD § 3.5). `$context['bridge_loans']`
        // sudah urut FIFO dari `batchContext()`/`lockActiveForUpdate()`.
        $bridgeLoans = is_array($context['bridge_loans'] ?? null) ? $context['bridge_loans'] : [];
        $bridge = BridgeLoanService::allocateAbsorption($operatorCents, $bridgeLoans);

        return [
            'sold_weight'              => (string) $priced['weight'],
            'sold_mg'                  => $soldMg,
            'holding_mg'               => $holdingMg,
            'density'                  => self::hundredthsToDecimal($densityHundredths),
            'density_hundredths'       => $densityHundredths,
            'price'                    => (string) $priced['price'],
            'price_cents'              => (int) $priced['price_cents'],
            'revenue'                  => self::centsToDecimal($revenueCents),
            'revenue_cents'            => $revenueCents,
            'holding_cost'             => self::centsToDecimal($holdingCostCents),
            'holding_cost_cents'       => $holdingCostCents,
            'gross'                    => self::centsToDecimal($grossCents),
            'gross_cents'              => $grossCents,
            'coord_fee'                => self::centsToDecimal($coordFeeCents),
            'coord_fee_cents'          => $coordFeeCents,
            'deficit_active'           => self::centsToDecimal($activeDeficitCents),
            'deficit_active_cents'     => $activeDeficitCents,
            'deficit_recovery'         => self::centsToDecimal($recoveryCents),
            'deficit_recovery_cents'   => $recoveryCents,
            'net'                      => self::centsToDecimal($netCents),
            'net_cents'                => $netCents,
            'operator_share'           => self::centsToDecimal($operatorCents),
            'operator_share_cents'     => $operatorCents,
            'investor_pool'            => self::centsToDecimal($poolCents),
            'investor_pool_cents'      => $poolCents,
            'carryover'                => self::centsToDecimal($carryoverCents),
            'carryover_cents'          => $carryoverCents,
            'active_after'             => self::centsToDecimal($activeAfterCents),
            'active_after_cents'       => $activeAfterCents,
            'total_cash'               => self::centsToDecimal($totalCashCents),
            'total_cash_cents'         => $totalCashCents,
            'kas'                      => self::centsToDecimal($kasCents),
            'kas_cents'                => $kasCents,
            'brankas'                  => self::centsToDecimal($brankasCents),
            'brankas_cents'            => $brankasCents,
            'split_source'             => $splitFollowsSuggestion ? 'suggestion' : 'manual',
            'suggested_kas'            => self::centsToDecimal($suggestedKasCents),
            'suggested_kas_cents'      => $suggestedKasCents,
            'suggested_brankas'        => self::centsToDecimal($suggestedBrankasCents),
            'suggested_brankas_cents'  => $suggestedBrankasCents,

            // Dana talangan (Fase 6) — fee membebani hak pengelola saja.
            'bridge_count'             => (int) $bridge['count'],
            'bridge_fee'               => self::centsToDecimal((int) $bridge['fee_cents']),
            'bridge_fee_cents'         => (int) $bridge['fee_cents'],
            'bridge_absorbed'          => self::centsToDecimal((int) $bridge['absorbed_cents']),
            'bridge_absorbed_cents'    => (int) $bridge['absorbed_cents'],
            'bridge_uncovered'         => self::centsToDecimal((int) $bridge['uncovered_cents']),
            'bridge_uncovered_cents'   => (int) $bridge['uncovered_cents'],
            'bridge_total_return'      => self::centsToDecimal((int) $bridge['total_return_cents']),
            'bridge_total_return_cents' => (int) $bridge['total_return_cents'],
            'bridge_allocation'        => $bridge['rows'],
            'operator_take_home'       => self::centsToDecimal(
                $operatorCents - (int) $bridge['absorbed_cents']
            ),
            'operator_take_home_cents' => $operatorCents - (int) $bridge['absorbed_cents'],
        ];
    }

    /**
     * Konteks batch TANPA penguncian (untuk pratinjau & validasi awal).
     *
     * Sejak Fase 6 konteks membawa pula daftar dana talangan aktif (FIFO),
     * sehingga pratinjau Layar C menghitung penyerapan fee dengan rumus yang
     * sama dengan eksekusinya (`settle()` mengirim baris TERKUNCI).
     *
     * @return array{holding_mg: int, holding_cost_cents: int, active_deficit_cents: int,
     *               bridge_loans: list<array<string, mixed>>}
     */
    public static function batchContext(): array
    {
        $totals = HuluTransaction::holdingTotals();
        $deficitRaw = SystemSetting::get(self::SETTING_ACTIVE_DEFICIT, '0.00');

        return [
            'holding_mg'            => self::gramsToMilligrams($totals['grams']),
            'holding_cost_cents'    => TellerService::moneyToCents($totals['cost']),
            'active_deficit_cents'  => TellerService::moneyToCents((string) ($deficitRaw ?? '0.00')),
            'bridge_loans'          => BridgeLoanService::activeLoans(),
        ];
    }

    // -----------------------------------------------------------------------
    // Transaksi tutup buku (lima tabel, satu transaksi PDO — SAD § 4.1)
    // -----------------------------------------------------------------------

    /**
     * Eksekusi tutup buku harian.
     *
     * Urutan:
     *   0. validasi awal tanpa berkas (tolak cepat);
     *   1. validasi + simpan foto nota (di luar transaksi);
     *   2. kunci seluruh dompet modal (`capital_wallets`, id menaik) — URUTAN
     *      PENGUNCIAN GLOBAL: dompet → pos kas → hulu → setting (AGENTS.md);
     *   3. kunci `cash_registers` (meja + brankas, urutan tetap) dan batch
     *      `hulu_transactions`;
     *   4. hitung ulang seluruh angka dari himpunan yang terkunci;
     *   5. kunci & baca `active_carryover_deficit`;
     *   6. tulis `daily_settlements`, ubah status hulu, distribusikan kas,
     *      catat dua mutasi `in`, perbarui defisit berjalan;
     *   7. distribusikan pool 20 % ke dompet mitra yang modalnya terserap
     *      (`AllocationEngine::apply()`) — Fase 4.
     *
     * Bila langkah mana pun gagal, transaksi digulung **dan** berkas nota yang
     * sudah tersalin dihapus (rencana A-7).
     *
     * @param array<string, mixed>          $input  field form (lihat `quote()`)
     * @param array<string, mixed>|null     $upload entri `$_FILES[RECEIPT_FIELD]`
     * @return array<string, mixed> ringkasan untuk flash & QC
     *
     * @throws \InvalidArgumentException input/berkas tidak sah
     * @throws \RuntimeException invariant dilanggar
     */
    public static function settle(array $input, ?array $upload): array
    {
        // 0. Tolak cepat sebelum ada berkas yang ditulis.
        self::quote($input, self::batchContext());

        // 1. Berkas nota lebih dulu supaya tidak ada baris settlement yang
        //    menunjuk foto hilang (A-7).
        $receipt = self::storeReceipt($upload);
        $settlementId = Security::uuidV4();

        try {
            /** @var array<string, mixed> $written */
            $written = Database::transaction(static function (PDO $pdo) use (
                $input,
                $settlementId,
                $receipt
            ): array {
                // 2. Urutan penguncian global dimulai dari dompet modal: seluruh
                //    dompet dikunci lebih dulu agar jalur penarikan (dompet →
                //    brankas) tidak pernah beradu urutan dengan jalur ini.
                $wallets = CapitalWallet::lockAllForUpdate();

                $registers = CashRegister::lockByNamesForUpdate([
                    CashRegister::TELLER_NAME,
                    CashRegister::BRANKAS_NAME,
                ]);

                $teller = $registers[CashRegister::TELLER_NAME] ?? null;
                $brankas = $registers[CashRegister::BRANKAS_NAME] ?? null;

                if ($teller === null || $brankas === null) {
                    throw new \RuntimeException(
                        'Pos kas "' . CashRegister::TELLER_NAME . '" atau "' . CashRegister::BRANKAS_NAME
                        . '" tidak ditemukan. Jalankan instalasi basis data.'
                    );
                }

                // 2c. Dana talangan aktif dikunci (FIFO) sebelum batch hulu —
                //     urutan penguncian global: dompet → pos kas → bridge_loans
                //     → hulu_transactions → system_settings (AGENTS.md).
                $bridgeRows = BridgeLoanService::presentRows(BridgeLoan::lockActiveForUpdate());

                $rows = HuluTransaction::holdingRowsForUpdate();

                if ($rows === []) {
                    throw new \RuntimeException(
                        'Tidak ada emas berstatus holding untuk disettle. Catat pembelian hulu terlebih dahulu.'
                    );
                }

                $holdingMg = 0;
                $holdingCostCents = 0;
                $ids = [];

                foreach ($rows as $row) {
                    $ids[] = (string) $row['id'];
                    $holdingMg += self::gramsToMilligrams((string) $row['dry_weight']);
                    $holdingCostCents += TellerService::moneyToCents((string) $row['net_cash_paid']);
                }

                // Kunci baris defisit: dua settlement serentak tidak boleh
                // memotong defisit yang sama dua kali (risiko R-6).
                $deficitRaw = SystemSetting::getForUpdate(self::SETTING_ACTIVE_DEFICIT);

                if ($deficitRaw === null) {
                    throw new \RuntimeException(
                        'Kunci setting "' . self::SETTING_ACTIVE_DEFICIT
                        . '" tidak terdaftar. Jalankan instalasi basis data.'
                    );
                }

                $quote = self::quote($input, [
                    'holding_mg'           => $holdingMg,
                    'holding_cost_cents'   => $holdingCostCents,
                    'active_deficit_cents' => TellerService::moneyToCents($deficitRaw),
                    'bridge_loans'         => $bridgeRows,
                ]);

                // Penjagaan saldo (rencana Fase 6 A-9): pengembalian talangan
                // dibayar dari kas meja SETELAH alokasi, sehingga sesi yang
                // menyisakan kas meja kurang dari kewajiban pengembalian harus
                // ditolak SEBELUM ada tulisan apa pun (talangan tetap active).
                $tellerAfterAllocationCents = TellerService::moneyToCents(
                    (string) $teller['current_balance']
                ) + (int) $quote['kas_cents'];

                if ($tellerAfterAllocationCents < (int) $quote['bridge_total_return_cents']) {
                    throw new \RuntimeException(
                        'Saldo Kas Meja Bakar setelah alokasi ('
                        . self::centsToDecimal($tellerAfterAllocationCents)
                        . ') tidak cukup mengembalikan dana talangan ('
                        . (string) $quote['bridge_total_return']
                        . '). Naikkan alokasi Kas Meja Bakar atau lunasi talangan secara tunai lebih dulu.'
                    );
                }

                $settlementDate = date('Y-m-d');

                DailySettlement::insert([
                    'id'                     => $settlementId,
                    'settlement_date'        => $settlementDate,
                    'total_dry_grams'        => $quote['sold_weight'],
                    'shop_water_density_pct' => $quote['density'],
                    'shop_price_per_g'       => $quote['price'],
                    'total_shop_revenue'     => $quote['revenue'],
                    'daily_coordination_fee' => $quote['coord_fee'],
                    'consumable_opex'        => '0.00',
                    'deficit_recovery_used'  => $quote['deficit_recovery'],
                    'net_settled_profit'     => $quote['net'],
                    'operator_share_80'      => $quote['operator_share'],
                    'investor_pool_20'       => $quote['investor_pool'],
                    'carryover_deficit'      => $quote['carryover'],
                    'receipt_photo_url'      => $receipt['url'],
                ]);

                $settledRows = HuluTransaction::settleBatch($settlementId, $ids);

                if ($settledRows !== count($ids)) {
                    throw new \RuntimeException(
                        'Jumlah transaksi hulu yang disettle tidak sesuai (' . $settledRows
                        . ' dari ' . count($ids) . '). Seluruh perubahan dibatalkan.'
                    );
                }

                CashRegister::adjustBalance((string) $teller['id'], $quote['kas']);
                CashRegister::adjustBalance((string) $brankas['id'], $quote['brankas']);

                // 6b. Pengembalian dana talangan (Fase 6): pokok + fee keluar
                //     dari pos kas yang sama dengan tempat dana masuk, satu
                //     mutasi `out` per talangan, di dalam transaksi yang sama.
                $bridge = BridgeLoanService::settleLoans(
                    $pdo,
                    $settlementId,
                    $quote['bridge_allocation'],
                    (string) $teller['id']
                );

                // 7. Distribusi pool 20 % ke dompet mitra yang modalnya bekerja
                //    (Fase 4). Dompet sudah dikunci di awal transaksi, dan
                //    seluruh angkanya dihitung ulang di dalam kunci yang sama.
                $allocation = AllocationEngine::apply(
                    $pdo,
                    $settlementId,
                    (int) $quote['holding_cost_cents'],
                    (int) $quote['investor_pool_cents'],
                    $wallets
                );

                $mutations = self::mutationRows(
                    (string) $teller['id'],
                    (string) $brankas['id'],
                    $settlementId,
                    $settlementDate,
                    $quote,
                    $allocation
                );
                $mutationIds = [];

                foreach ($mutations as $mutation) {
                    $id = Security::uuidV4();
                    $mutationIds[] = $id;

                    CashMutation::insert(
                        $id,
                        $mutation['register_id'],
                        CashMutation::TYPE_IN,
                        $mutation['amount'],
                        $mutation['label'],
                        $mutation['notes']
                    );
                }

                SystemSetting::set(self::SETTING_ACTIVE_DEFICIT, $quote['active_after']);

                return [
                    'settlement_id'   => $settlementId,
                    'settlement_date' => $settlementDate,
                    'settled_rows'    => $settledRows,
                    'mutation_count'  => count($mutationIds),
                    'allocation'      => $allocation,
                    'bridge'          => $bridge,
                    'teller_before'   => (string) $teller['current_balance'],
                    'teller_after'    => self::centsToDecimal(
                        TellerService::moneyToCents((string) $teller['current_balance'])
                            + $quote['kas_cents']
                            - (int) $bridge['total_return_cents']
                    ),
                    'brankas_before'  => (string) $brankas['current_balance'],
                    'brankas_after'   => self::centsToDecimal(
                        TellerService::moneyToCents((string) $brankas['current_balance']) + $quote['brankas_cents']
                    ),
                    'quote'           => $quote,
                ];
            });
        } catch (\Throwable $exception) {
            // Tidak boleh ada berkas yatim bila transaksi batal.
            self::removeReceipt($receipt);
            Logger::warning('Settlement ditolak', [
                'user_id' => (string) ($_SESSION['user_id'] ?? ''),
                'alasan'  => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $quote = $written['quote'];
        $allocation = is_array($written['allocation'] ?? null) ? $written['allocation'] : [];
        $bridge = is_array($written['bridge'] ?? null) ? $written['bridge'] : [];

        Logger::info('Settlement harian tersimpan', [
            'settlement_id'     => $written['settlement_id'],
            'tanggal'           => $written['settlement_date'],
            'berat_jual_gr'     => $quote['sold_weight'],
            'pendapatan_toko'   => $quote['revenue'],
            'modal_belanja'     => $quote['holding_cost'],
            'laba_bersih'       => $quote['net'],
            'defisit_dipulihkan' => $quote['deficit_recovery'],
            'defisit_berjalan'  => $quote['active_after'],
            'kas_meja_akhir'    => $written['teller_after'],
            'brankas_akhir'     => $written['brankas_after'],
            'pool_dompet'       => (int) ($allocation['mitra_wallets'] ?? 0),
            'pool_terdistribusi' => TellerService::centsToDecimal((int) ($allocation['profit_cents'] ?? 0)),
            'sisa_tak_terserap' => TellerService::centsToDecimal((int) ($allocation['uncovered_cents'] ?? 0)),
            'talangan_dibayar'  => (int) ($bridge['count'] ?? 0),
            'talangan_kembali'  => (string) ($bridge['total_return'] ?? '0.00'),
            'fee_diserap'       => (string) ($bridge['absorbed'] ?? '0.00'),
            'fee_tak_tertutup'  => (string) ($bridge['uncovered'] ?? '0.00'),
            'nota'              => $receipt['url'],
        ]);

        // Dua keadaan yang layak diperiksa manusia: modal belanja tidak tertutup
        // seluruh saldo dompet, dan pool 20 % tidak punya penerima (belum ada
        // dompet mitra bermodal). Keduanya TIDAK menggagalkan tutup buku —
        // buku tetap seimbang karena hanya pembukuan yang tertunda.
        if ((int) ($allocation['uncovered_cents'] ?? 0) > 0) {
            Logger::warning('Modal belanja tidak tertutup seluruh dompet', [
                'settlement_id'     => $written['settlement_id'],
                'sisa_tak_terserap' => TellerService::centsToDecimal((int) $allocation['uncovered_cents']),
            ]);
        }

        if ((bool) ($allocation['pool_unallocated'] ?? false)) {
            Logger::warning('Pool 20 % belum memiliki dompet mitra penerima', [
                'settlement_id' => $written['settlement_id'],
                'pool'          => $quote['investor_pool'],
            ]);
        }

        // Fee talangan yang melebihi hak laba pengelola layak diperiksa manusia:
        // ia dibayar dari kas kerja pengelola (bukan dari dana mitra), sehingga
        // pool 20 % tetap utuh dan modal mitra tidak tersentuh (PRD § 3.5).
        if ((int) $quote['bridge_uncovered_cents'] > 0) {
            Logger::warning('Fee talangan melebihi hak laba pengelola', [
                'settlement_id'    => $written['settlement_id'],
                'fee_talangan'     => $quote['bridge_fee'],
                'fee_tak_tertutup' => $quote['bridge_uncovered'],
                'hak_pengelola'    => $quote['operator_share'],
                'pool_mitra'       => $quote['investor_pool'],
            ]);
        }

        return $written + $receipt;
    }

    // -----------------------------------------------------------------------
    // Foto nota (validasi + penyimpanan aman)
    // -----------------------------------------------------------------------

    /**
     * Direktori penyimpanan nota (absolut).
     */
    public static function receiptDirectory(): string
    {
        return PUBLIC_PATH . '/' . self::RECEIPT_DIR;
    }

    /**
     * Simpan foto nota dari entri `$_FILES`.
     *
     * Aturan keamanan (rencana § 4.6):
     *   - hanya berkas hasil unggahan HTTP (`move_uploaded_file`);
     *   - tipe ditebak dari ISI gambar (`getimagesize`), bukan dari nama/MIME
     *     kiriman klien;
     *   - nama berkas DIBENTUK peladen dari UUID, sehingga nama kiriman klien
     *     tidak pernah menjadi bagian path (anti path traversal);
     *   - batas ukuran keras ditegakkan di sini (kompresi Canvas hanya
     *     kemudahan sisi klien).
     *
     * @param array<string, mixed>|null $upload
     * @return array{name: string, url: string, path: string, bytes: int,
     *               original_name: string}
     *
     * @throws \InvalidArgumentException berkas tidak sah
     * @throws \RuntimeException direktori tidak dapat ditulis
     */
    private static function storeReceipt(?array $upload): array
    {
        $entry = $upload ?? [];

        $error = isset($entry['error']) ? (int) $entry['error'] : UPLOAD_ERR_NO_FILE;
        $tmpName = isset($entry['tmp_name']) && is_string($entry['tmp_name']) ? $entry['tmp_name'] : '';
        $size = isset($entry['size']) ? (int) $entry['size'] : 0;

        if ($error === UPLOAD_ERR_NO_FILE || $tmpName === '') {
            throw new \InvalidArgumentException(
                'Foto nota toko wajib dilampirkan sebagai bukti fisik penjualan.'
            );
        }

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new \InvalidArgumentException(
                'Ukuran unggahan melebihi batas server. Perkecil foto lalu coba lagi.'
            );
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException(
                'Foto nota gagal diunggah. Coba ambil ulang dari kamera.'
            );
        }

        if ($size <= 0) {
            throw new \InvalidArgumentException('Foto nota kosong. Ambil ulang dari kamera.');
        }

        if ($size > self::RECEIPT_HARD_LIMIT_BYTES) {
            throw new \InvalidArgumentException(
                'Foto nota melebihi batas ' . self::formatBytes(self::RECEIPT_HARD_LIMIT_BYTES)
                . '. Perkecil foto lalu coba lagi.'
            );
        }

        if (!is_uploaded_file($tmpName)) {
            throw new \InvalidArgumentException('Berkas nota tidak valid sebagai hasil unggahan.');
        }

        $info = @getimagesize($tmpName);

        if (!is_array($info) || !isset($info[2], $info[0], $info[1])) {
            throw new \InvalidArgumentException(
                'Berkas nota tidak dikenali sebagai gambar (JPG, PNG, atau WEBP).'
            );
        }

        $type = (int) $info[2];

        if (!isset(self::IMAGE_TYPES[$type])) {
            throw new \InvalidArgumentException(
                'Format gambar nota tidak didukung. Gunakan JPG, PNG, atau WEBP.'
            );
        }

        if ((int) $info[0] < 32 || (int) $info[1] < 32) {
            throw new \InvalidArgumentException('Dimensi gambar nota terlalu kecil untuk dibaca.');
        }

        $directory = self::receiptDirectory();

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(
                'Direktori penyimpanan nota tidak dapat dibuat. Hubungi administrator.'
            );
        }

        if (!is_writable($directory)) {
            throw new \RuntimeException(
                'Direktori penyimpanan nota tidak dapat ditulis. Hubungi administrator.'
            );
        }

        $name = Security::uuidV4() . '.' . self::IMAGE_TYPES[$type];
        $path = $directory . '/' . $name;

        if (!move_uploaded_file($tmpName, $path)) {
            throw new \RuntimeException('Foto nota gagal disimpan. Coba ulang pengunggahan.');
        }

        @chmod($path, 0644);

        return [
            'name'          => $name,
            'url'           => self::RECEIPT_DIR . '/' . $name,
            'path'          => $path,
            'bytes'         => $size,
            'original_name' => Security::sanitize((string) ($entry['name'] ?? '')),
        ];
    }

    /**
     * Hapus berkas nota (pembatalan transaksi). Tidak pernah melempar.
     *
     * @param array<string, mixed> $receipt
     */
    private static function removeReceipt(array $receipt): void
    {
        $path = isset($receipt['path']) && is_string($receipt['path']) ? $receipt['path'] : '';

        if ($path === '' || !is_file($path)) {
            return;
        }

        // Hanya boleh menghapus di dalam direktori nota — berkas di luar itu
        // tidak pernah dibuat oleh peladen ini.
        $root = realpath(self::receiptDirectory());
        $resolved = realpath($path);

        if ($root === false || $resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            return;
        }

        @unlink($resolved);
    }

    // -----------------------------------------------------------------------
    // Parsing input lapangan
    // -----------------------------------------------------------------------

    /**
     * Nominal rupiah (beban aparat, alokasi kas) — titik = ribuan, koma =
     * desimal, maksimum 2 desimal, dan NOL diizinkan (rencana § 4.2).
     *
     * Aturan konvensinya identik dengan `TellerService::parsePriceInput()`;
     * perbedaannya hanya pada penerimaan nilai nol. QC grup B membandingkan
     * kedua parser pada tabel kasus yang sama sehingga keduanya tidak dapat
     * menyimpang.
     *
     * Sejak Fase 4 fungsi ini **publik**: `CapitalService` (setoran modal,
     * Pindah Kas, penarikan) memakai parser yang sama supaya aturan nominal
     * tidak memiliki dua salinan yang bisa berbeda diam-diam.
     *
     * @return int sen (integer)
     */
    public static function parseMoneyInput(mixed $raw, string $label): int
    {
        if (!is_string($raw)) {
            throw new \InvalidArgumentException($label . ' harus berupa angka.');
        }

        $value = trim($raw);

        if ($value === '') {
            return 0;
        }

        if (preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException($label . ' memuat karakter yang tidak dikenal.');
        }

        if (str_contains($value, '-')) {
            throw new \InvalidArgumentException($label . ' tidak boleh negatif.');
        }

        $digits = preg_replace('/[^0-9.,]/', '', $value) ?? '';

        if ($digits === '') {
            throw new \InvalidArgumentException($label . ' harus berupa angka. Contoh: 200.000');
        }

        $fraction = '';

        if (str_contains($digits, ',')) {
            if (substr_count($digits, ',') > 1) {
                throw new \InvalidArgumentException($label . ' memuat lebih dari satu koma. Contoh: 200.000');
            }

            [$integer, $fraction] = explode(',', $digits, 2);
        } else {
            $integer = $digits;
        }

        if ($fraction !== '' && preg_match('/^\d{1,2}$/', $fraction) !== 1) {
            throw new \InvalidArgumentException($label . ' memuat desimal lebih dari 2 angka.');
        }

        if ($integer === '' || $integer === '.') {
            throw new \InvalidArgumentException($label . ' harus berupa angka. Contoh: 200.000');
        }

        if (str_contains($integer, '.')
            && preg_match('/^\d{1,3}(?:\.\d{3})+$/', $integer) !== 1
        ) {
            throw new \InvalidArgumentException($label . ' memakai format ribuan yang tidak dikenal.');
        }

        $plain = str_replace('.', '', $integer);

        if (preg_match('/^\d+$/', $plain) !== 1) {
            throw new \InvalidArgumentException($label . ' harus berupa angka. Contoh: 200.000');
        }

        if (strlen($plain) > 12) {
            throw new \InvalidArgumentException($label . ' melebihi kapasitas pembukuan.');
        }

        $cents = (int) $plain * 100
            + (int) str_pad($fraction, 2, '0', STR_PAD_RIGHT);

        if ($cents > self::MAX_MONEY_CENTS) {
            throw new \InvalidArgumentException($label . ' melebihi kapasitas kolom pembukuan.');
        }

        return $cents;
    }

    /**
     * Kadar timbang air toko: satu pemisah (`.` atau `,`) berarti DESIMAL,
     * maksimum 2 desimal, `> 0` dan `<= 100` (DECIMAL(5,2)).
     *
     * @return int perseratus persen (`93,50` -> 9350)
     */
    private static function parseDensity(mixed $raw): int
    {
        $value = is_string($raw) ? trim($raw) : '';
        $value = trim((string) preg_replace('/\s*%\s*$/', '', $value));

        if ($value === '') {
            throw new \InvalidArgumentException('Kadar timbang air toko wajib diisi. Contoh: 93,50');
        }

        if (preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException('Kadar timbang air memuat karakter yang tidak dikenal.');
        }

        if (str_contains($value, '-')) {
            throw new \InvalidArgumentException('Kadar timbang air tidak boleh negatif.');
        }

        $digits = preg_replace('/[^0-9.,]/', '', $value) ?? '';

        if ($digits === '') {
            throw new \InvalidArgumentException('Kadar timbang air harus berupa angka. Contoh: 93,50');
        }

        if (str_contains($digits, ',') && str_contains($digits, '.')) {
            throw new \InvalidArgumentException('Gunakan satu pemisah desimal saja. Contoh: 93,50');
        }

        $digits = str_replace(',', '.', $digits);

        if (substr_count($digits, '.') > 1) {
            throw new \InvalidArgumentException('Kadar timbang air tidak valid. Contoh: 93,50');
        }

        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $digits, $matches) !== 1) {
            throw new \InvalidArgumentException('Kadar timbang air tidak valid atau lebih dari 2 desimal.');
        }

        $hundredths = (int) $matches[1] * 100
            + (int) str_pad((string) ($matches[2] ?? ''), 2, '0', STR_PAD_RIGHT);

        if ($hundredths <= 0) {
            throw new \InvalidArgumentException('Kadar timbang air harus lebih besar dari nol.');
        }

        if ($hundredths > self::MAX_DENSITY_HUNDREDTHS) {
            throw new \InvalidArgumentException('Kadar timbang air tidak boleh melebihi 100 %.');
        }

        return $hundredths;
    }

    private static function stringInput(mixed $raw): string
    {
        return is_string($raw) ? trim($raw) : '';
    }

    // -----------------------------------------------------------------------
    // Aritmetika uang berbasis integer
    // -----------------------------------------------------------------------

    /**
     * Porsi persentase dari laba bersih dengan pembulatan half away from zero.
     *
     * Selalu dipakai bersama pasangannya: pool = net - operator, sehingga
     * invariant `operator + pool == net` tidak mungkin berselisih sen.
     */
    private static function shareOf(int $cents, int $percent): int
    {
        if ($cents <= 0 || $percent <= 0) {
            return 0;
        }

        return intdiv($cents * $percent + 50, 100);
    }

    /**
     * Sen (integer) -> string desimal kanonik (`'2186500.00'`). Delegasi ke
     * `TellerService::centsToDecimal()` — satu pemilik konversi uang, dan sejak
     * Fase 4 dipakai juga oleh `AllocationEngine`/`CapitalService`.
     */
    private static function centsToDecimal(int $cents): string
    {
        return TellerService::centsToDecimal($cents);
    }

    private static function hundredthsToDecimal(int $hundredths): string
    {
        return intdiv($hundredths, 100) . '.' . str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Gram kanonik dari database (`'18.050'`) -> miligram (integer).
     *
     * @throws \RuntimeException nilai rusak (bukan input pengguna)
     */
    private static function gramsToMilligrams(string $decimal): int
    {
        $value = trim($decimal);

        if (preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $value, $matches) !== 1) {
            throw new \RuntimeException('Nilai berat emas pada basis data tidak valid.');
        }

        $milligrams = (int) $matches[1] * 1000
            + (int) str_pad((string) ($matches[2] ?? ''), 3, '0', STR_PAD_RIGHT);

        if ($milligrams > self::MAX_WEIGHT_MG) {
            throw new \RuntimeException('Nilai berat emas pada basis data melebihi kapasitas kolom.');
        }

        return $milligrams;
    }

    private static function milligramsToDecimal(int $milligrams): string
    {
        return intdiv($milligrams, 1000) . '.'
            . str_pad((string) ($milligrams % 1000), 3, '0', STR_PAD_LEFT);
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            $tenths = intdiv($bytes * 10, 1048576);

            return intdiv($tenths, 10) . ',' . ($tenths % 10) . ' MB';
        }

        return intdiv($bytes, 1024) . ' KB';
    }

    // -----------------------------------------------------------------------
    // Keterangan mutasi kas
    // -----------------------------------------------------------------------

    /**
     * Dua mutasi kas masuk: pengembalian modal ke meja, laba bersih ke brankas.
     *
     * Kolom `amount` NOT NULL dan selalu positif pada skema ERD, sehingga baris
     * bernilai Rp 0 (mis. laba bersih nol pada hari minus) tidak ditulis —
     * saldo tetap benar karena penambahan nol tidak mengubah apa pun.
     *
     * Sejak Fase 4, ringkasan alokasi modal ikut ditulis pada `notes` supaya
     * jejak audit kas menjelaskan ke mana pool 20 % mengalir.
     *
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $allocation ringkasan `AllocationEngine::apply()`
     * @return list<array{register_id: string, amount: string, label: string, notes: string}>
     */
    private static function mutationRows(
        string $tellerId,
        string $brankasId,
        string $settlementId,
        string $date,
        array $quote,
        array $allocation = []
    ): array {
        $notes = sprintf(
            'settlement_id=%s; tanggal=%s; berat=%s gr; pendapatan=%s; modal=%s; laba_bersih=%s; '
            . 'defisit_dipulihkan=%s; pengelola_80=%s; pool_20=%s',
            $settlementId,
            $date,
            (string) $quote['sold_weight'],
            (string) $quote['revenue'],
            (string) $quote['holding_cost'],
            (string) $quote['net'],
            (string) $quote['deficit_recovery'],
            (string) $quote['operator_share'],
            (string) $quote['investor_pool']
        );

        if ($allocation !== []) {
            $notes .= sprintf(
                '; dompet_mitra=%d; pool_terdistribusi=%s; sisa_tak_terserap=%s',
                (int) ($allocation['mitra_wallets'] ?? 0),
                TellerService::centsToDecimal((int) ($allocation['profit_cents'] ?? 0)),
                TellerService::centsToDecimal((int) ($allocation['uncovered_cents'] ?? 0))
            );
        }

        // Ringkasan dana talangan: satu baris mutasi cukup untuk memahami sesi
        // tutup buku tanpa membuka tabel `bridge_loans`.
        if ((int) ($quote['bridge_count'] ?? 0) > 0) {
            $notes .= sprintf(
                '; talangan=%d; fee_talangan=%s; fee_diserap=%s; fee_tak_tertutup=%s',
                (int) $quote['bridge_count'],
                (string) $quote['bridge_fee'],
                (string) $quote['bridge_absorbed'],
                (string) $quote['bridge_uncovered']
            );
        }

        $rows = [];

        if ((int) $quote['kas_cents'] > 0) {
            $rows[] = [
                'register_id' => $tellerId,
                'amount'      => (string) $quote['kas'],
                'label'       => self::MUTATION_PREFIX . $date . ' — pengembalian modal belanja hulu',
                'notes'       => $notes,
            ];
        }

        if ((int) $quote['brankas_cents'] > 0) {
            $rows[] = [
                'register_id' => $brankasId,
                'amount'      => (string) $quote['brankas'],
                'label'       => self::MUTATION_PREFIX . $date . ' — laba bersih putaran',
                'notes'       => $notes,
            ];
        }

        return $rows;
    }
}
