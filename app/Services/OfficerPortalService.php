<?php

declare(strict_types=1);

namespace AurumFlow\Services;

use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Formatter;
use AurumFlow\Models\Officer;
use AurumFlow\Models\OfficerLedgerEntry;
use AurumFlow\Models\User;

/**
 * Portal blind mitra lapangan — BACA-SAJA (Fase 10).
 *
 * Satu-satunya pintu data untuk peran `officer`. Kelas ini sengaja sempit:
 * identitas petugas, saldo hak, ringkasan bulan berjalan, dan riwayat buku
 * haknya sendiri. Tidak ada satu pun angka milik pengelola, mitra pemodal,
 * maupun toko yang boleh melewati kelas ini.
 *
 * Aturan keras (AGENTS.md + rencana Fase 10 § 8.2 grup H):
 *   - `cut_pct` dan `base_amount` **tidak pernah** dikembalikan ke view: tarif
 *     dikali basis dapat dibalik menjadi basis, sehingga menampilkannya sama
 *     dengan membocorkan margin pemilik sistem.
 *   - Nama pos kas, id mutasi, dan id sesi buku besar tidak pernah dirender.
 *   - Nomor telepon selalu tersamar.
 *   - Seluruh output diformat di sini lewat `Formatter` (satu pemilik angka)
 *     dan di-escape view lewat `Security::e()`.
 */
final class OfficerPortalService
{
    /** Jumlah entri yang ditampilkan pada kartu ringkas dasbor. */
    public const DASHBOARD_LIMIT = 50;

    /** Jumlah entri maksimum halaman riwayat. */
    public const HISTORY_LIMIT = 300;

    private function __construct()
    {
    }

    /**
     * Ringkasan dasbor petugas.
     *
     * @param string $userId `users.id` sesi (selalu dari session, bukan form)
     * @return array<string, mixed>
     */
    public static function summary(string $userId): array
    {
        $officer = Officer::findByUserId($userId);

        if ($officer === null) {
            return [
                'found'            => false,
                'balance_label'    => Formatter::rupiah(0),
                'month_cut_label'  => Formatter::rupiah(0),
                'month_bonus_label' => Formatter::rupiah(0),
                'month_paid_label' => Formatter::rupiah(0),
                'month_count'      => 0,
                'updated_label'    => '-',
            ];
        }

        $monthStart = date('Y-m-01');
        $totals = OfficerLedgerEntry::totalsForOfficer((string) $officer['id'], $monthStart);

        return [
            'found'             => true,
            'balance_label'     => Formatter::rupiah((string) ($officer['balance_payable'] ?? '0.00')),
            'month_cut_label'   => Formatter::rupiah((string) ($totals[OfficerLedgerEntry::ENTRY_CUT] ?? '0.00')),
            'month_bonus_label' => Formatter::rupiah((string) ($totals[OfficerLedgerEntry::ENTRY_BONUS] ?? '0.00')),
            'month_paid_label'  => Formatter::rupiah((string) ($totals[OfficerLedgerEntry::ENTRY_DISBURSEMENT] ?? '0.00')),
            'month_count'       => (int) ($totals['count'] ?? 0),
            'updated_label'     => Formatter::tanggalId((string) ($officer['updated_at'] ?? ''), true),
        ];
    }

    /**
     * Riwayat buku hak petugas (terbaru lebih dahulu).
     *
     * @return list<array{type_label: string, amount_label: string,
     *               balance_label: string, tanggal: string, notes: string}>
     */
    public static function history(string $userId, int $limit = self::DASHBOARD_LIMIT): array
    {
        $officer = Officer::findByUserId($userId);

        if ($officer === null) {
            return [];
        }

        $limit = max(1, min(self::HISTORY_LIMIT, $limit));
        $rows = OfficerLedgerEntry::forOfficer((string) $officer['id'], $limit);
        $entries = [];

        foreach ($rows as $row) {
            $type = (string) ($row['entry_type'] ?? '');
            $isCredit = $type !== OfficerLedgerEntry::ENTRY_DISBURSEMENT;
            $notes = isset($row['notes']) && is_string($row['notes']) ? trim($row['notes']) : '';

            $entries[] = [
                'type_label'    => OfficerLedgerEntry::typeLabel($type),
                'amount_label'  => ($isCredit ? '+ ' : '− ') . Formatter::rupiah((string) ($row['amount'] ?? '0.00')),
                'balance_label' => Formatter::rupiah((string) ($row['balance_after'] ?? '0.00')),
                'tanggal'       => Formatter::tanggalId((string) ($row['created_at'] ?? ''), true),
                'notes'         => $notes,
            ];
        }

        return $entries;
    }

    /**
     * Identitas ringkas untuk header portal (nama + nomor tersamar).
     *
     * @return array{name: string, phone_masked: string, has_account: bool}
     */
    public static function identity(string $userId): array
    {
        $user = User::findByIdAndRole($userId, Constants::ROLE_OFFICER);

        if ($user === null) {
            return ['name' => '', 'phone_masked' => '', 'has_account' => false];
        }

        return [
            'name'         => (string) ($user['full_name'] ?? ''),
            'phone_masked' => self::maskPhone((string) ($user['phone_number'] ?? '')),
            'has_account'  => true,
        ];
    }

    /**
     * Samarkan nomor telepon: `081234567890` → `08••••••••90`.
     */
    public static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        $length = strlen($digits);

        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return substr($digits, 0, 2) . str_repeat('•', max(0, $length - 4)) . substr($digits, -2);
    }
}
