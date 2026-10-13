<?php

declare(strict_types=1);

namespace AurumFlow\Services;

use AurumFlow\Helpers\Security;
use AurumFlow\Models\CapitalWallet;
use AurumFlow\Models\HuluTransaction;
use AurumFlow\Models\ProfitDistribution;
use AurumFlow\Models\SystemSetting;
use PDO;

/**
 * Mesin alokasi modal (Fase 4): waterfall serapan kas + distribusi pool 20 %.
 *
 * Mengapa kelas ini ada (bukan di controller atau model):
 *   Aturan serapan modal milik use-case, bukan SQL: ia membaca kebijakan
 *   (`system_settings`), membagi kebutuhan kas berlapis (Pengelola → Mitra
 *   Prioritas → Mitra Reguler), lalu menulis dividen ke banyak dompet. Pola
 *   yang sama dengan `TellerService` (Fase 2) dan `SettlementService` (Fase 3),
 *   dan sengaja diletakkan di `app/Services/` karena memuat operasi basis data
 *   terkunci — lihat ADDENDUM `plan/Plan_Fase_4_AurumFlow.md` § 10.1.
 *
 * Aturan uang (rencana Fase 4 § 4.1) — TIDAK boleh dilanggar:
 *   1. Seluruh aritmetika memakai SEN INTEGER. Tidak ada float, tidak ada
 *      `number_format`, tidak ada pembagian yang menghasilkan pecahan.
 *   2. Pembagian pro-rata memakai metode SISA TERBESAR sehingga jumlahnya
 *      PERSIS: `Σ capital_utilized + uncovered == kebutuhan kas` dan
 *      `Σ profit_earned == investor_pool_20`.
 *   3. Pembagian besar (`kebutuhan × saldo`) dihitung dengan pembagian panjang
 *      berbasis limb (radix 10^4) supaya tidak pernah meluap `PHP_INT_MAX`
 *      walaupun kedua operand bernilai triliunan sen.
 *
 * Urutan serapan (rencana § 4.2):
 *   1. Pengelola (`tier='operator'`)   : kuota `operator_base_quota_pct` (50 %).
 *   2. Mitra Prioritas (`tier='family'`): kuota terkunci per dompet.
 *   3. Mitra Reguler (`tier='external'`): pro-rata saldo atas sisa kebutuhan.
 *   4. Sisa tak terserap (`uncovered`)  : dilaporkan, tidak pernah dipaksakan
 *      ke dompet mana pun (serapan selalu `min(kuota, saldo, sisa)`).
 *
 * Distribusi pool (PRD § 3.4 & § 4.6):
 *   - Hanya dompet mitra (`family`/`external`) yang menerima pool; dompet
 *     Pengelola tetap DICATAT serapannya dengan `profit_earned = 0.00`.
 *   - Baris hanya ditulis untuk dompet dengan `capital_utilized > 0`, sehingga
 *     instalasi tanpa dompet bermodal tidak menulis apa pun (perilaku Fase 3
 *     tetap sah).
 *
 * Mode alokasi: hanya `waterfall` yang diimplementasikan pada Fase 4. Nilai
 * `allocation_mode` lain ditolak dengan pesan siap tampil dan NOL tulisan
 * (item O-1 pada rencana).
 */
final class AllocationEngine
{
    /** Mode alokasi yang diimplementasikan (ERD § 4 point 10). */
    public const MODE_WATERFALL = 'waterfall';

    /** Kunci `system_settings` yang dibaca mesin ini. */
    public const SETTING_MODE = 'allocation_mode';

    public const SETTING_OPERATOR_QUOTA = 'operator_base_quota_pct';

    /** Batas nilai kolom DECIMAL(14,2). */
    private const MAX_MONEY_CENTS = 99999999999999;

    /** Basis limb untuk pembagian panjang (4 digit desimal per limb). */
    private const LIMB_BASE = 10000;

    private function __construct()
    {
    }

    // -----------------------------------------------------------------------
    // Kebijakan (system_settings)
    // -----------------------------------------------------------------------

    /**
     * Kuota dasar Pengelola dalam perseratus persen (`50.00` → `5000`).
     *
     * @throws \RuntimeException kunci setting tidak terdaftar / nilainya rusak
     */
    public static function configuredOperatorQuota(): int
    {
        $raw = SystemSetting::get(self::SETTING_OPERATOR_QUOTA);

        if ($raw === null) {
            throw new \RuntimeException(
                'Kunci setting "' . self::SETTING_OPERATOR_QUOTA
                . '" tidak terdaftar. Jalankan instalasi basis data.'
            );
        }

        return self::quotaHundredths($raw);
    }

    /**
     * Pastikan mode alokasi yang aktif memang didukung Fase 4.
     *
     * @throws \RuntimeException mode lain (mis. `prorata`) belum tersedia
     */
    public static function assertConfiguredMode(): void
    {
        $mode = trim((string) (SystemSetting::get(self::SETTING_MODE, self::MODE_WATERFALL) ?? ''));

        if ($mode !== self::MODE_WATERFALL) {
            throw new \RuntimeException(
                'Mode alokasi modal "' . $mode . '" belum didukung. Setel '
                . self::SETTING_MODE . ' ke "' . self::MODE_WATERFALL . '".'
            );
        }
    }

    // -----------------------------------------------------------------------
    // Kalkulasi murni
    // -----------------------------------------------------------------------

    /**
     * Bagi kebutuhan kas satu putaran ke seluruh dompet (tanpa menulis apa pun).
     *
     * @param int $needCents kebutuhan kas belanja putaran (sen, >= 0)
     * @param list<array<string, mixed>> $wallets baris dompet: `id`, `tier`, dan
     *        salah satu dari (`balance_capital` string desimal | `available_cents`
     *        int) + (`locked_quota_pct` string desimal | `quota_hundredths` int)
     * @param int $operatorQuotaHundredths kuota Pengelola dalam perseratus persen
     * @return array{
     *     need_cents: int, operator_quota_cents: int, utilized_cents: int,
     *     uncovered_cents: int, mitra_base_cents: int,
     *     stages: array{operator: int, family: int, external: int},
     *     rows: list<array{id: string, tier: string, available_cents: int,
     *                      quota_hundredths: int, utilized_cents: int}>
     * }
     *
     * @throws \InvalidArgumentException input tidak sah (pesan siap tampil)
     */
    public static function plan(int $needCents, array $wallets, int $operatorQuotaHundredths): array
    {
        self::assertMoney($needCents, 'Kebutuhan kas alokasi');

        if ($operatorQuotaHundredths < 0 || $operatorQuotaHundredths > 10000) {
            throw new \InvalidArgumentException('Kuota dasar Pengelola berada di luar rentang 0–100 %.');
        }

        $rows = [];

        foreach ($wallets as $wallet) {
            $rows[] = self::normaliseWallet($wallet);
        }

        $operatorQuotaCents = self::shareOf($needCents, $operatorQuotaHundredths);
        $remaining = $needCents;
        $stages = [
            CapitalWallet::TIER_OPERATOR => 0,
            CapitalWallet::TIER_FAMILY   => 0,
            CapitalWallet::TIER_EXTERNAL => 0,
        ];

        // Tahap 1 — Pengelola: satu kuota kelompok, diisi dompet dengan saldo
        // terbesar lebih dulu supaya hasilnya deterministik.
        $operatorIndexes = self::indexesByTier($rows, CapitalWallet::TIER_OPERATOR);
        usort($operatorIndexes, static function (int $a, int $b) use ($rows): int {
            if ($rows[$a]['available_cents'] !== $rows[$b]['available_cents']) {
                return $rows[$b]['available_cents'] <=> $rows[$a]['available_cents'];
            }

            return $rows[$a]['id'] <=> $rows[$b]['id'];
        });

        foreach ($operatorIndexes as $index) {
            $capacity = $operatorQuotaCents - $stages[CapitalWallet::TIER_OPERATOR];
            $absorbed = self::clamp($capacity, $rows[$index]['available_cents'], $remaining);

            $rows[$index]['utilized_cents'] = $absorbed;
            $stages[CapitalWallet::TIER_OPERATOR] += $absorbed;
            $remaining -= $absorbed;
        }

        // Tahap 2 — Mitra Prioritas: kuota terkunci PER DOMPET (boleh berjumlah
        // lebih dari 50 %; selalu dibatasi sisa kebutuhan).
        $familyIndexes = self::indexesByTier($rows, CapitalWallet::TIER_FAMILY);
        usort($familyIndexes, static function (int $a, int $b) use ($rows): int {
            if ($rows[$a]['quota_hundredths'] !== $rows[$b]['quota_hundredths']) {
                return $rows[$b]['quota_hundredths'] <=> $rows[$a]['quota_hundredths'];
            }

            if ($rows[$a]['available_cents'] !== $rows[$b]['available_cents']) {
                return $rows[$b]['available_cents'] <=> $rows[$a]['available_cents'];
            }

            return $rows[$a]['id'] <=> $rows[$b]['id'];
        });

        foreach ($familyIndexes as $index) {
            $capacity = self::shareOf($needCents, $rows[$index]['quota_hundredths']);
            $absorbed = self::clamp($capacity, $rows[$index]['available_cents'], $remaining);

            $rows[$index]['utilized_cents'] = $absorbed;
            $stages[CapitalWallet::TIER_FAMILY] += $absorbed;
            $remaining -= $absorbed;
        }

        // Tahap 3 — Mitra Reguler: pro-rata saldo atas sisa kebutuhan.
        $externalIndexes = self::indexesByTier($rows, CapitalWallet::TIER_EXTERNAL);
        $externalAvailable = 0;

        foreach ($externalIndexes as $index) {
            $externalAvailable += $rows[$index]['available_cents'];
        }

        if ($remaining > 0 && $externalAvailable > 0) {
            $weights = [];

            foreach ($externalIndexes as $index) {
                $weights[$rows[$index]['id']] = $rows[$index]['available_cents'];
            }

            $shares = self::proRata($remaining, $weights);

            foreach ($externalIndexes as $index) {
                $absorbed = $shares[$rows[$index]['id']] ?? 0;

                $rows[$index]['utilized_cents'] = $absorbed;
                $stages[CapitalWallet::TIER_EXTERNAL] += $absorbed;
                $remaining -= $absorbed;
            }
        }

        $utilized = 0;
        $mitraBase = 0;

        foreach ($rows as $row) {
            $utilized += $row['utilized_cents'];

            if ($row['tier'] !== CapitalWallet::TIER_OPERATOR) {
                $mitraBase += $row['utilized_cents'];
            }
        }

        return [
            'need_cents'           => $needCents,
            'operator_quota_cents' => $operatorQuotaCents,
            'utilized_cents'       => $utilized,
            'uncovered_cents'      => $remaining,
            'mitra_base_cents'     => $mitraBase,
            'stages'               => $stages,
            'rows'                 => $rows,
        ];
    }

    /**
     * Bagi pool dividen pro-rata atas modal mitra yang bekerja.
     *
     * Metode sisa terbesar menjamin `Σ nilai == $poolCents` (dibatasi jumlah
     * bobot) sehingga tidak ada sen yang hilang atau berlebih.
     *
     * @param int $poolCents pool yang dibagi (sen, >= 0)
     * @param array<string, int> $mitraUtilized peta id dompet => modal bekerja (sen)
     * @return array{shares: array<string, int>, total: int}
     */
    public static function distribute(int $poolCents, array $mitraUtilized): array
    {
        self::assertMoney($poolCents, 'Pool dividen');

        $weights = [];

        foreach ($mitraUtilized as $walletId => $utilizedCents) {
            $id = (string) $walletId;
            $value = (int) $utilizedCents;

            if ($value < 0) {
                throw new \InvalidArgumentException('Modal bekerja tidak boleh negatif.');
            }

            if ($value > 0) {
                $weights[$id] = $value;
            }
        }

        if ($poolCents === 0 || $weights === []) {
            return ['shares' => [], 'total' => 0];
        }

        $shares = self::proRata($poolCents, $weights);
        $total = 0;

        foreach ($shares as $value) {
            $total += $value;
        }

        return ['shares' => $shares, 'total' => $total];
    }

    /**
     * Pratinjau alokasi dari keadaan basis data (tanpa penguncian).
     *
     * Dipakai layar Kelola Modal: proyeksi serapan untuk kebutuhan kas riil
     * malam ini. Tidak menulis apa pun dan tidak menyentuh kebijakan mode —
     * `apply()` yang menegakkan mode.
     *
     * @param int|null $needCents null = baca modal belanja hulu berstatus holding
     * @return array<string, mixed> hasil `plan()` + `rows[].name` dan `rows[].tier_label`
     *
     * @throws \RuntimeException setting kuota tidak terdaftar / nilainya rusak
     */
    public static function planFromDatabase(?int $needCents = null): array
    {
        if ($needCents === null) {
            $totals = HuluTransaction::holdingTotals();
            $needCents = TellerService::moneyToCents($totals['cost']);
        }

        $wallets = CapitalWallet::listWithUser();
        $plan = self::plan($needCents, $wallets, self::configuredOperatorQuota());

        $names = [];
        $labels = [];

        foreach ($wallets as $wallet) {
            $id = (string) ($wallet['id'] ?? '');
            $names[$id] = (string) ($wallet['full_name'] ?? '');
            $labels[$id] = CapitalWallet::label((string) ($wallet['tier'] ?? ''));
        }

        foreach ($plan['rows'] as $index => $row) {
            $plan['rows'][$index]['name'] = $names[$row['id']] ?? '';
            $plan['rows'][$index]['tier_label'] = $labels[$row['id']] ?? CapitalWallet::label($row['tier']);
        }

        return $plan;
    }

    // -----------------------------------------------------------------------
    // Penerapan di dalam transaksi settlement
    // -----------------------------------------------------------------------

    /**
     * Tulis distribusi pool & kreditkan dividen ke dompet.
     *
     * WAJIB dipanggil DI DALAM `Database::transaction()` milik
     * `SettlementService::settle()`, dengan dompet yang SUDAH dikunci lewat
     * `CapitalWallet::lockAllForUpdate()` (urutan penguncian global: dompet →
     * pos kas → hulu → setting).
     *
     * @param PDO $pdo koneksi transaksi (bukti pemanggilan di dalam transaksi)
     * @param string $settlementId id sesi tutup buku yang baru ditulis
     * @param int $needCents modal belanja putaran (sen)
     * @param int $poolCents `daily_settlements.investor_pool_20` (sen)
     * @param list<array<string, mixed>> $lockedWallets baris hasil `lockAllForUpdate()`
     * @return array{
     *     written: int, mitra_wallets: int, utilized_cents: int, profit_cents: int,
     *     uncovered_cents: int, pool_unallocated: bool,
     *     by_label: array<string, int>
     * }
     *
     * @throws \RuntimeException mode alokasi tidak didukung / setting rusak
     * @throws \InvalidArgumentException nilai modal pada basis data tidak sah
     */
    public static function apply(
        PDO $pdo,
        string $settlementId,
        int $needCents,
        int $poolCents,
        array $lockedWallets
    ): array {
        self::assertConfiguredMode();

        $plan = self::plan($needCents, $lockedWallets, self::configuredOperatorQuota());

        $mitraUtilized = [];

        foreach ($plan['rows'] as $row) {
            if ($row['tier'] !== CapitalWallet::TIER_OPERATOR && $row['utilized_cents'] > 0) {
                $mitraUtilized[$row['id']] = $row['utilized_cents'];
            }
        }

        $distribution = self::distribute($poolCents, $mitraUtilized);
        $written = 0;
        $profitTotal = 0;
        $byLabel = [];

        foreach ($plan['rows'] as $row) {
            if ($row['utilized_cents'] <= 0) {
                continue;
            }

            $profit = (int) ($distribution['shares'][$row['id']] ?? 0);
            $label = CapitalWallet::label($row['tier']);

            ProfitDistribution::insert(
                Security::uuidV4(),
                $settlementId,
                $row['id'],
                TellerService::centsToDecimal($row['utilized_cents']),
                TellerService::centsToDecimal($profit)
            );

            if ($profit > 0) {
                CapitalWallet::adjustProfit($row['id'], TellerService::centsToDecimal($profit));
            }

            $written++;
            $profitTotal += $profit;
            $byLabel[$label] = ($byLabel[$label] ?? 0) + $profit;
        }

        return [
            'written'          => $written,
            'mitra_wallets'    => count($mitraUtilized),
            'utilized_cents'   => $plan['utilized_cents'],
            'profit_cents'     => $profitTotal,
            'uncovered_cents'  => $plan['uncovered_cents'],
            'pool_unallocated' => $poolCents > 0 && $mitraUtilized === [],
            'by_label'         => $byLabel,
        ];
    }

    // -----------------------------------------------------------------------
    // Aritmetika integer (tanpa float)
    // -----------------------------------------------------------------------

    /**
     * Porsi persentase dengan pembulatan KE BAWAH.
     *
     * Sengaja floor, bukan half-up: kuota adalah BATAS serapan, sehingga
     * membulatkan ke atas dapat menyerap sedikit melebihi kuota yang dijanjikan.
     * Sisa sen tetap masuk ke tahap berikutnya (mutasi konservatif).
     */
    private static function shareOf(int $cents, int $hundredths): int
    {
        if ($cents <= 0 || $hundredths <= 0) {
            return 0;
        }

        return intdiv($cents * $hundredths, 10000);
    }

    /**
     * Bagi `$total` (sen) secara proporsional menurut bobot, memakai metode
     * SISA TERBESAR sehingga `Σ hasil == min($total, Σ bobot)`.
     *
     * @param array<string, int> $weights peta id => bobot (>= 0)
     * @return array<string, int> peta id => bagian (sen)
     */
    private static function proRata(int $total, array $weights): array
    {
        $shares = [];

        foreach (array_keys($weights) as $id) {
            $shares[$id] = 0;
        }

        $sum = 0;

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new \InvalidArgumentException('Bobot pro-rata tidak boleh negatif.');
            }

            if ($weight > 0 && $sum > self::MAX_MONEY_CENTS - $weight) {
                throw new \InvalidArgumentException('Total bobot pro-rata melebihi kapasitas pembukuan.');
            }

            $sum += $weight;
        }

        $total = max(0, min($total, $sum));

        if ($total === 0 || $sum === 0) {
            return $shares;
        }

        $remainders = [];

        foreach ($weights as $id => $weight) {
            [$share, $remainder] = self::mulDiv($total, $weight, $sum);

            $shares[$id] = $share;
            $remainders[$id] = $remainder;
        }

        $allocated = 0;

        foreach ($shares as $share) {
            $allocated += $share;
        }

        $leftover = $total - $allocated;

        if ($leftover > 0) {
            $ids = array_keys($weights);

            usort($ids, static function (string $a, string $b) use ($remainders, $weights): int {
                if ($remainders[$a] !== $remainders[$b]) {
                    return $remainders[$b] <=> $remainders[$a];
                }

                if ($weights[$a] !== $weights[$b]) {
                    return $weights[$b] <=> $weights[$a];
                }

                return $a <=> $b;
            });

            foreach ($ids as $index => $id) {
                if ($index >= $leftover) {
                    break;
                }

                $shares[$id]++;
            }
        }

        return $shares;
    }

    /**
     * `floor($left * $right / $divisor)` beserta sisanya — bebas luapan.
     *
     * Jalur cepat dipakai bila hasil kali masih muat integer 64-bit; selebihnya
     * pembagian panjang berbasis limb (radix 10^4) yang hanya memakai
     * penjumlahan, perkalian kecil (< 10^8), dan `intdiv`.
     *
     * @return array{0: int, 1: int} [hasil bagi, sisa]
     *
     * @throws \InvalidArgumentException operand negatif / pembagi nol
     * @throws \RuntimeException hasil bagi melebihi kapasitas integer
     */
    private static function mulDiv(int $left, int $right, int $divisor): array
    {
        if ($left < 0 || $right < 0) {
            throw new \InvalidArgumentException('Aritmetika alokasi menerima nilai non-negatif saja.');
        }

        if ($divisor <= 0) {
            throw new \InvalidArgumentException('Pembagi alokasi harus lebih besar dari nol.');
        }

        if ($left === 0 || $right === 0) {
            return [0, 0];
        }

        if ($right <= intdiv(PHP_INT_MAX, $left)) {
            $product = $left * $right;

            return [intdiv($product, $divisor), $product % $divisor];
        }

        return self::divideLimbs(self::multiplyLimbs(self::limbsOf($left), self::limbsOf($right)), $divisor);
    }

    /**
     * Pecah bilangan menjadi limb basis 10^4 (paling tidak signifikan lebih dulu).
     *
     * @return list<int>
     */
    private static function limbsOf(int $value): array
    {
        $limbs = [];

        while ($value > 0) {
            $limbs[] = $value % self::LIMB_BASE;
            $value = intdiv($value, self::LIMB_BASE);
        }

        return $limbs === [] ? [0] : $limbs;
    }

    /**
     * Perkalian schoolbook dua bilangan limb. Setiap langkah < 10^8 sehingga
     * aman pada integer 64-bit.
     *
     * @param list<int> $left
     * @param list<int> $right
     * @return list<int>
     */
    private static function multiplyLimbs(array $left, array $right): array
    {
        $result = array_fill(0, count($left) + count($right) + 1, 0);

        foreach ($left as $i => $x) {
            if ($x === 0) {
                continue;
            }

            $carry = 0;

            foreach ($right as $j => $y) {
                $index = $i + $j;
                $value = $result[$index] + $x * $y + $carry;

                $result[$index] = $value % self::LIMB_BASE;
                $carry = intdiv($value, self::LIMB_BASE);
            }

            $index = $i + count($right);

            while ($carry > 0) {
                $value = $result[$index] + $carry;

                $result[$index] = $value % self::LIMB_BASE;
                $carry = intdiv($value, self::LIMB_BASE);
                $index++;
            }
        }

        while (count($result) > 1 && $result[count($result) - 1] === 0) {
            array_pop($result);
        }

        return $result;
    }

    /**
     * Pembagian panjang limb oleh satu pembagi skalar.
     *
     * @param list<int> $limbs
     * @return array{0: int, 1: int} [hasil bagi, sisa]
     *
     * @throws \RuntimeException pembagi terlalu besar / hasil bagi meluap
     */
    private static function divideLimbs(array $limbs, int $divisor): array
    {
        if ($divisor > intdiv(PHP_INT_MAX, self::LIMB_BASE)) {
            throw new \RuntimeException(
                'Pembagi alokasi melebihi kapasitas aritmetika aman. Pecah sesi settlement.'
            );
        }

        $remainder = 0;
        $quotient = 0;

        for ($index = count($limbs) - 1; $index >= 0; $index--) {
            $current = $remainder * self::LIMB_BASE + $limbs[$index];
            $digit = intdiv($current, $divisor);

            $remainder = $current % $divisor;

            if ($quotient > intdiv(PHP_INT_MAX - $digit, self::LIMB_BASE)) {
                throw new \RuntimeException(
                    'Hasil pembagian alokasi melebihi kapasitas integer. Pecah sesi settlement.'
                );
            }

            $quotient = $quotient * self::LIMB_BASE + $digit;
        }

        return [$quotient, $remainder];
    }

    /**
     * Batas serapan satu dompet: `min(kapasitas kuota, saldo, sisa kebutuhan)`.
     */
    private static function clamp(int $capacity, int $available, int $remaining): int
    {
        $limit = min($capacity, $available, $remaining);

        return $limit > 0 ? $limit : 0;
    }

    /**
     * @param array<string, mixed> $wallet
     * @return array{id: string, tier: string, available_cents: int, quota_hundredths: int, utilized_cents: int}
     */
    private static function normaliseWallet(array $wallet): array
    {
        $id = isset($wallet['id']) ? (string) $wallet['id'] : '';
        $tier = isset($wallet['tier']) ? (string) $wallet['tier'] : '';

        if ($id === '') {
            throw new \InvalidArgumentException('Dompet modal tanpa id tidak dapat dialokasikan.');
        }

        if (!isset(CapitalWallet::TIER_LABELS[$tier])) {
            throw new \InvalidArgumentException('Tier dompet modal tidak dikenal: ' . $tier);
        }

        $available = isset($wallet['available_cents'])
            ? (int) $wallet['available_cents']
            : TellerService::moneyToCents((string) ($wallet['balance_capital'] ?? '0.00'));

        if ($available < 0) {
            throw new \InvalidArgumentException('Saldo modal dompet tidak boleh negatif.');
        }

        self::assertMoney($available, 'Saldo modal dompet');

        $quota = isset($wallet['quota_hundredths'])
            ? (int) $wallet['quota_hundredths']
            : self::quotaHundredths((string) ($wallet['locked_quota_pct'] ?? '0.00'));

        if ($quota < 0 || $quota > 10000) {
            throw new \InvalidArgumentException('Kuota serapan dompet berada di luar rentang 0–100 %.');
        }

        // Kuota hanya bermakna bagi Mitra Prioritas: tier lain selalu 0 % agar
        // kebijakan waterfall tidak dapat dilumpuhkan lewat data yang menyimpang.
        if ($tier !== CapitalWallet::TIER_FAMILY) {
            $quota = 0;
        }

        return [
            'id'              => $id,
            'tier'            => $tier,
            'available_cents' => $available,
            'quota_hundredths' => $quota,
            'utilized_cents'  => 0,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int> indeks baris dengan tier tersebut
     */
    private static function indexesByTier(array $rows, string $tier): array
    {
        $indexes = [];

        foreach ($rows as $index => $row) {
            if ($row['tier'] === $tier) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    private static function assertMoney(int $cents, string $label): void
    {
        if ($cents < 0) {
            throw new \InvalidArgumentException($label . ' tidak boleh negatif.');
        }

        if ($cents > self::MAX_MONEY_CENTS) {
            throw new \InvalidArgumentException($label . ' melebihi kapasitas kolom pembukuan.');
        }
    }

    // -----------------------------------------------------------------------
    // Konversi persentase (DECIMAL(5,2) <-> perseratus)
    // -----------------------------------------------------------------------

    /**
     * Nilai `DECIMAL(5,2)` menjadi perseratus persen (`'20.00'` → `2000`).
     *
     * Konvensi sama dengan parser kadar pada `SettlementService`: satu pemisah
     * berarti desimal, maksimum 2 angka di belakang koma, rentang 0–100.
     *
     * @throws \InvalidArgumentException nilai rusak (bukan input pengguna)
     */
    public static function quotaHundredths(string $decimal): int
    {
        $value = trim($decimal);

        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            throw new \InvalidArgumentException('Nilai kuota serapan pada basis data tidak valid.');
        }

        $hundredths = (int) $matches[1] * 100
            + (int) str_pad((string) ($matches[2] ?? ''), 2, '0', STR_PAD_RIGHT);

        if ($hundredths > 10000) {
            throw new \InvalidArgumentException('Kuota serapan tidak boleh melebihi 100 %.');
        }

        return $hundredths;
    }

    /**
     * Kebalikan `quotaHundredths()`: `2000` → `'20.00'`.
     */
    public static function hundredthsToDecimal(int $hundredths): string
    {
        return intdiv($hundredths, 100) . '.' . str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }
}
