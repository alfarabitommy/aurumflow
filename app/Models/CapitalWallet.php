<?php

declare(strict_types=1);

namespace AurumFlow\Models;

use AurumFlow\Config\Database;
use AurumFlow\Helpers\Security;

/**
 * Model tabel `capital_wallets` — dompet modal mitra (Fase 4).
 *
 * Dua tanggung jawab yang sengaja hidup di sini:
 *   1. SQL tabel `capital_wallets` (satu-satunya tempat query tabel ini).
 *   2. **Pemetaan tier ke label antarmuka** (ERD § 4, PRD § 4.4). Database
 *      tetap menyimpan `ENUM('operator','family','external')`; yang dibaca
 *      operator adalah "Pengelola", "Mitra Prioritas", dan "Mitra Reguler".
 *      Label tidak boleh ditulis ulang di controller/view supaya tidak ada dua
 *      sumber kebenaran yang cepat atau lambat akan berbeda.
 *
 * Seluruh nilai uang/kuota dikirim sebagai STRING desimal kanonik
 * (`'30000000.00'`, `'20.00'`) sehingga kolom DECIMAL tidak kehilangan presisi.
 * Perhitungan selalu di lapisan service dalam sen/perseratus integer.
 *
 * Penguncian: `lockAllForUpdate()` (id menaik) WAJIB dipanggil sebelum pos kas
 * pada setiap transaksi yang menyentuh dompet — urutan penguncian global ada di
 * `AGENTS.md` dan mencegah deadlock dengan jalur penarikan.
 */
final class CapitalWallet
{
    /** Nilai `capital_wallets.tier` menurut ERD. */
    public const TIER_OPERATOR = 'operator';

    public const TIER_FAMILY = 'family';

    public const TIER_EXTERNAL = 'external';

    /** Nilai `capital_wallets.withdrawal_status` menurut ERD. */
    public const WITHDRAWAL_NONE = 'none';

    public const WITHDRAWAL_QUEUED = 'queued';

    public const WITHDRAWAL_COMPLETED = 'completed';

    /**
     * Label antarmuka per tier — SATU-SATUNYA sumber label.
     *
     * Istilah produk (keputusan Fase 4): tier `family` tampil sebagai **Mitra
     * Prioritas** dan `external` sebagai **Mitra Reguler** — label antarmuka
     * ditulis di sini saja, bukan berulang di controller/view.
     *
     * @var array<string, string>
     */
    public const TIER_LABELS = [
        self::TIER_OPERATOR => 'Pengelola',
        self::TIER_FAMILY   => 'Mitra Prioritas',
        self::TIER_EXTERNAL => 'Mitra Reguler',
    ];

    private function __construct()
    {
    }

    // -----------------------------------------------------------------------
    // Label & daftar tier
    // -----------------------------------------------------------------------

    /**
     * Label tampilan sebuah tier. Nilai tak dikenal dikembalikan apa adanya
     * supaya data rusak terlihat apa adanya, bukan disembunyikan.
     */
    public static function label(string $tier): string
    {
        return self::TIER_LABELS[$tier] ?? $tier;
    }

    /**
     * Tier yang dapat dipilih operator saat menambah mitra (tanpa `operator`,
     * yang hanya milik akun Pengelola).
     *
     * @return list<string>
     */
    public static function selectableTiers(): array
    {
        return [self::TIER_FAMILY, self::TIER_EXTERNAL];
    }

    // -----------------------------------------------------------------------
    // Pembacaan
    // -----------------------------------------------------------------------

    /**
     * Seluruh dompet beserta pemiliknya (untuk tabel portofolio).
     *
     * Urutan selalu sama: Pengelola dulu, lalu Mitra Prioritas, lalu Mitra
     * Reguler menurut nama — supaya tampilan dan QC tidak bergantung pada
     * urutan penyimpanan fisik.
     *
     * Sejak Fase 5 ikut membawa `invite_token` + `onboarding_signed_at` supaya
     * kartu "Undangan & Onboarding" pada layar Kelola Modal tidak perlu kueri
     * kedua per baris. Layar ini **operator-only**, jadi memperlihatkan token
     * undangan kepada pihak yang memang mengirimkannya adalah sah — token yang
     * sama tidak pernah dirender pada layar investor mana pun.
     *
     * @return list<array<string, mixed>>
     */
    public static function listWithUser(): array
    {
        return Database::fetchAll(
            'SELECT `w`.`id`, `w`.`user_id`, `w`.`tier`, `w`.`balance_capital`, '
            . '`w`.`balance_profit`, `w`.`locked_quota_pct`, `w`.`withdrawal_status`, '
            . '`w`.`withdrawal_request_date`, `u`.`full_name`, `u`.`phone_number`, '
            . '`u`.`invite_token`, `u`.`onboarding_signed_at` '
            . 'FROM `capital_wallets` `w` INNER JOIN `users` `u` ON `u`.`id` = `w`.`user_id` '
            . 'ORDER BY CASE `w`.`tier` WHEN \'operator\' THEN 0 WHEN \'family\' THEN 1 ELSE 2 END, '
            . '`u`.`full_name` ASC, `w`.`id` ASC'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findById(string $walletId): ?array
    {
        return Database::fetchOne(
            'SELECT `id`, `user_id`, `tier`, `balance_capital`, `balance_profit`, '
            . '`locked_quota_pct`, `withdrawal_status`, `withdrawal_request_date` '
            . 'FROM `capital_wallets` WHERE `id` = :id LIMIT 1',
            ['id' => $walletId]
        );
    }

    /**
     * Dompet milik seorang pengguna (relasi 1 : N disiapkan ERD, tetapi alur
     * saat ini memakai dompet pertama).
     *
     * @return array<string, mixed>|null
     */
    public static function findByUserId(string $userId): ?array
    {
        return Database::fetchOne(
            'SELECT `id`, `user_id`, `tier`, `balance_capital`, `balance_profit`, '
            . '`locked_quota_pct`, `withdrawal_status`, `withdrawal_request_date` '
            . 'FROM `capital_wallets` WHERE `user_id` = :user_id ORDER BY `id` ASC LIMIT 1',
            ['user_id' => $userId]
        );
    }

    public static function count(): int
    {
        $row = Database::fetchOne('SELECT COUNT(*) AS `total` FROM `capital_wallets`');

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Nama pemilik dompet (untuk keterangan mutasi kas & antrean penarikan).
     *
     * Sengaja TANPA `FOR UPDATE`: `source_destination` hanya keterangan audit,
     * sehingga tidak perlu ikut mengunci baris `users` di tengah transaksi kas.
     */
    public static function ownerName(string $walletId): string
    {
        $row = Database::fetchOne(
            'SELECT `u`.`full_name` FROM `capital_wallets` `w` '
            . 'INNER JOIN `users` `u` ON `u`.`id` = `w`.`user_id` '
            . 'WHERE `w`.`id` = :id LIMIT 1',
            ['id' => $walletId]
        );

        return trim((string) ($row['full_name'] ?? ''));
    }

    // -----------------------------------------------------------------------
    // Penguncian (WAJIB di dalam Database::transaction())
    // -----------------------------------------------------------------------

    /**
     * Kunci SELURUH dompet dengan urutan tetap `id` menaik.
     *
     * Satu query (bukan per baris) supaya himpunan yang terserap saat settlement
     * konsisten dengan himpunan yang dikunci. `id` adalah primary key InnoDB
     * sehingga urutan penguncian mengikuti indeks dan sama di semua sesi.
     *
     * @return list<array<string, mixed>>
     */
    public static function lockAllForUpdate(): array
    {
        return Database::fetchAll(
            'SELECT `id`, `user_id`, `tier`, `balance_capital`, `balance_profit`, '
            . '`locked_quota_pct` FROM `capital_wallets` ORDER BY `id` ASC FOR UPDATE'
        );
    }

    /**
     * Kunci satu dompet (jalur setoran, kuota, dan penarikan).
     *
     * @return array<string, mixed>|null
     */
    public static function lockByIdForUpdate(string $walletId): ?array
    {
        return Database::fetchOne(
            'SELECT `id`, `user_id`, `tier`, `balance_capital`, `balance_profit`, '
            . '`locked_quota_pct`, `withdrawal_status`, `withdrawal_request_date` '
            . 'FROM `capital_wallets` WHERE `id` = :id LIMIT 1 FOR UPDATE',
            ['id' => $walletId]
        );
    }

    // -----------------------------------------------------------------------
    // Mutasi
    // -----------------------------------------------------------------------

    /**
     * Buat dompet baru untuk seorang pengguna.
     *
     * @param string $tier salah satu dari `TIER_*`
     * @param string $quotaDecimal `locked_quota_pct` (kanonik, mis. `'20.00'`)
     *
     * @throws \InvalidArgumentException tier tidak dikenal
     */
    public static function insert(string $userId, string $tier, string $quotaDecimal = '0.00'): string
    {
        if (!isset(self::TIER_LABELS[$tier])) {
            throw new \InvalidArgumentException('Tier dompet modal tidak dikenal: ' . $tier);
        }

        $id = Security::uuidV4();

        Database::insert('capital_wallets', [
            'id'              => $id,
            'user_id'         => $userId,
            'tier'            => $tier,
            'balance_capital' => '0.00',
            'balance_profit'  => '0.00',
            'locked_quota_pct' => $quotaDecimal,
            'withdrawal_status' => self::WITHDRAWAL_NONE,
        ]);

        return $id;
    }

    /**
     * Pastikan akun Pengelola memiliki dompet `tier='operator'`. Idempotent:
     * dipakai `database/install.php` **dan** tombol "Siapkan dompet Pengelola"
     * pada instalasi lama yang belum memilikinya.
     *
     * @return string id dompet (baru atau yang sudah ada)
     */
    public static function ensureOperatorWallet(string $userId): string
    {
        $existing = self::findByUserId($userId);

        if ($existing !== null) {
            return (string) $existing['id'];
        }

        return self::insert($userId, self::TIER_OPERATOR);
    }

    /**
     * Ubah `balance_capital` secara relatif (`+ delta`).
     *
     * @param string $delta string desimal kanonik (`'10000000.00'`, `'-5000000.00'`)
     * @return int jumlah baris terpengaruh (0 = dompet tidak ditemukan)
     */
    public static function adjustCapital(string $walletId, string $delta): int
    {
        return self::adjustBalance($walletId, 'balance_capital', $delta);
    }

    /**
     * Ubah `balance_profit` secara relatif (distribusi pool 20 %).
     */
    public static function adjustProfit(string $walletId, string $delta): int
    {
        return self::adjustBalance($walletId, 'balance_profit', $delta);
    }

    /**
     * Tulis kuota serapan terkunci (hanya bermakna untuk Mitra Prioritas).
     */
    public static function setLockedQuota(string $walletId, string $quotaDecimal): int
    {
        return Database::update(
            'capital_wallets',
            ['locked_quota_pct' => $quotaDecimal],
            '`id` = :id',
            ['id' => $walletId]
        );
    }

    /**
     * Perbarui status penarikan modal pada dompet (kebijakan exit H-7).
     *
     * @param string $status salah satu dari `WITHDRAWAL_*`
     */
    public static function setWithdrawalState(string $walletId, string $status, ?string $requestDate): int
    {
        return Database::update(
            'capital_wallets',
            [
                'withdrawal_status'       => $status,
                'withdrawal_request_date' => $requestDate,
            ],
            '`id` = :id',
            ['id' => $walletId]
        );
    }

    /**
     * @param string $column hanya `balance_capital` atau `balance_profit`
     */
    private static function adjustBalance(string $walletId, string $column, string $delta): int
    {
        $column = $column === 'balance_profit' ? 'balance_profit' : 'balance_capital';

        return Database::query(
            'UPDATE `capital_wallets` SET `' . $column . '` = `' . $column . '` + :delta '
            . 'WHERE `id` = :id',
            ['delta' => $delta, 'id' => $walletId]
        )->rowCount();
    }
}
