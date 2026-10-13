<?php

declare(strict_types=1);

namespace AurumFlow\Models;

use AurumFlow\Config\Database;

/**
 * Model tabel `withdrawal_requests` — antrean penarikan dana mitra (Fase 4).
 *
 * Kebijakan exit (PRD § 4.3):
 *   - Penarikan **modal pokok** (`withdrawal_type = 'capital'`) wajib melewati
 *     masa tunggu `notice_period_days` (7) **hari kerja** sebelum boleh dibayar;
 *     dompetnya ditandai `capital_wallets.withdrawal_status = 'queued'` selama
 *     masa tenggang, dan hanya boleh ada SATU pengajuan modal terbuka per dompet
 *     (kolom enum dompet memang hanya mampu menyimpan satu keadaan).
 *   - Penarikan **dividen** (`withdrawal_type = 'profit'`) boleh dicairkan
 *     begitu disetujui.
 *
 * Status (`ENUM` ERD): `pending` → `approved` → `completed`, atau
 * `pending` → `rejected`. "Dibayar" tidak punya status tersendiri —
 * `completed` berarti kas sudah keluar brankas dan saldo dompet sudah turun.
 */
final class WithdrawalRequest
{
    public const TYPE_PROFIT = 'profit';

    public const TYPE_CAPITAL = 'capital';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_COMPLETED = 'completed';

    private function __construct()
    {
    }

    public static function typeLabel(string $type): string
    {
        return $type === self::TYPE_CAPITAL ? 'Modal Pokok' : 'Dividen';
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_APPROVED  => 'Disetujui',
            self::STATUS_REJECTED  => 'Ditolak',
            self::STATUS_COMPLETED => 'Selesai',
            default                => 'Menunggu',
        };
    }

    public static function insert(
        string $id,
        string $walletId,
        string $withdrawalType,
        string $amount,
        string $noticePeriodDue,
        ?string $notes = null
    ): void {
        Database::insert('withdrawal_requests', [
            'id'                => $id,
            'wallet_id'         => $walletId,
            'withdrawal_type'   => $withdrawalType,
            'amount'            => $amount,
            'status'            => self::STATUS_PENDING,
            'notice_period_due' => $noticePeriodDue,
            'notes'             => $notes,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findById(string $id): ?array
    {
        return Database::fetchOne(
            'SELECT `id`, `wallet_id`, `withdrawal_type`, `amount`, `status`, '
            . '`notice_period_due`, `notes` FROM `withdrawal_requests` WHERE `id` = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * Baca satu permohonan SAMBIL MENGUNCI barisnya.
     *
     * WAJIB di dalam `Database::transaction()`. Dua operator yang menekan
     * "Bayar" pada permohonan yang sama tidak boleh mencairkan dua kali.
     *
     * @return array<string, mixed>|null
     */
    public static function lockByIdForUpdate(string $id): ?array
    {
        return Database::fetchOne(
            'SELECT `id`, `wallet_id`, `withdrawal_type`, `amount`, `status`, '
            . '`notice_period_due`, `notes` FROM `withdrawal_requests` WHERE `id` = :id '
            . 'LIMIT 1 FOR UPDATE',
            ['id' => $id]
        );
    }

    /**
     * Seluruh permohonan belum selesai beserta pemiliknya (layar antrean).
     *
     * Urut jatuh tempo menaik supaya permohonan yang paling dekat cair berada
     * di atas; `pending` ditampilkan sebelum `approved` pada tanggal yang sama.
     *
     * @return list<array<string, mixed>>
     */
    public static function listOpenWithWallet(): array
    {
        return Database::fetchAll(
            'SELECT `r`.`id`, `r`.`wallet_id`, `r`.`withdrawal_type`, `r`.`amount`, `r`.`status`, '
            . '`r`.`notice_period_due`, `r`.`notes`, `w`.`tier`, `w`.`balance_capital`, '
            . '`w`.`balance_profit`, `u`.`full_name` FROM `withdrawal_requests` `r` '
            . 'INNER JOIN `capital_wallets` `w` ON `w`.`id` = `r`.`wallet_id` '
            . 'INNER JOIN `users` `u` ON `u`.`id` = `w`.`user_id` '
            . 'WHERE `r`.`status` IN (\'pending\', \'approved\') '
            . 'ORDER BY `r`.`notice_period_due` ASC, `r`.`created_at` ASC, `r`.`id` ASC'
        );
    }

    /**
     * Ubah status permohonan.
     */
    public static function setStatus(string $id, string $status, ?string $notes = null): int
    {
        $data = ['status' => $status];

        if ($notes !== null) {
            $data['notes'] = $notes;
        }

        return Database::update('withdrawal_requests', $data, '`id` = :id', ['id' => $id]);
    }

    // -----------------------------------------------------------------------
    // Sisi mitra — HANYA baris milik dompet sendiri (Fase 5)
    // -----------------------------------------------------------------------

    /**
     * Riwayat permohonan satu dompet (Layar D: kartu status pengajuan).
     *
     * Urutan deterministik: `created_at` DESC lalu `id` ASC — tanpa itu, dua
     * pengajuan pada detik yang sama dapat berurutan acak antar-render.
     *
     * @return list<array<string, mixed>>
     */
    public static function listForWallet(string $walletId): array
    {
        return Database::fetchAll(
            'SELECT `id`, `wallet_id`, `withdrawal_type`, `amount`, `status`, '
            . '`notice_period_due`, `notes`, `created_at` FROM `withdrawal_requests` '
            . 'WHERE `wallet_id` = :wallet_id ORDER BY `created_at` DESC, `id` ASC',
            ['wallet_id' => $walletId]
        );
    }

    /**
     * Jumlah nominal pengajuan yang masih terbuka (pending/approved) per dompet
     * dan jenis — string desimal kanonik.
     *
     * Dipakai invariant `CapitalService::requestWithdrawal()`: total pengajuan
     * terbuka ditambah pengajuan baru tidak boleh melebihi saldo jenis itu.
     */
    public static function sumOpenAmountForWallet(string $walletId, string $type): string
    {
        $type = $type === self::TYPE_CAPITAL ? self::TYPE_CAPITAL : self::TYPE_PROFIT;

        $row = Database::fetchOne(
            'SELECT COALESCE(SUM(`amount`), 0) AS `total` FROM `withdrawal_requests` '
            . 'WHERE `wallet_id` = :wallet_id AND `withdrawal_type` = :withdrawal_type '
            . 'AND `status` IN (\'pending\', \'approved\')',
            ['wallet_id' => $walletId, 'withdrawal_type' => $type]
        );

        $value = trim((string) ($row['total'] ?? '0'));

        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            return '0.00';
        }

        return $matches[1] . '.' . str_pad((string) ($matches[2] ?? ''), 2, '0', STR_PAD_RIGHT);
    }
}
