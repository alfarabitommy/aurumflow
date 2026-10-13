<?php

declare(strict_types=1);

namespace AurumFlow\Services;

use AurumFlow\Helpers\Formatter;
use AurumFlow\Models\CapitalWallet;
use AurumFlow\Models\ProfitDistribution;
use AurumFlow\Models\WithdrawalRequest;

/**
 * Sisi mitra pemodal: view-model dasbor blind & pengajuan penarikan (Fase 5).
 *
 * Kelas ini adalah **pintu sempit** antara basis data dan seluruh permukaan
 * investor (dasbor, laporan cetak, CSV). Karena itu ia memegang dua penjaga:
 *
 *   1. **Daftar terlarang.** Yang dibaca hanya dompet mitra itu sendiri, baris
 *      `profit_distributions` miliknya, dan pengajuannya sendiri. Tidak ada
 *      `hulu_transactions`, omzet toko, laba, porsi pengelola, kuota serapan
 *      global, kas fisik, maupun baris mitra lain — lihat daftar eksplisit di
 *      `AGENTS.md`. JOIN ke `daily_settlements` pun hanya mengambil
 *      `settlement_date` (dijaga `ProfitDistribution::walletSelect()`).
 *   2. **Dompet dari sesi, bukan dari form.** `requestWithdrawal()` menerima
 *      `user_id` pemilik sesi lalu mencari dompetnya sendiri; payload yang
 *      menunjuk dompet mitra lain tidak punya jalan masuk.
 *
 * Rumus yang dibekukan (plan Fase 5 § 4.3):
 *   Modal Berputar = min(capital_utilized baris terakhir, balance_capital)
 *   Kas Cadangan   = balance_capital − Modal Berputar
 *   Portofolio     = balance_capital + balance_profit
 *   Dividen Bulan Ini = Σ profit_earned pada bulan kalender berjalan (WIB)
 *   ROI Bulan Ini  = Dividen Bulan Ini ÷ balance_capital × 100
 *
 * Seluruh aritmetika uang memakai **sen integer** (`TellerService`); persentase
 * memakai **perseratus integer** (`AllocationEngine::hundredthsToDecimal()`),
 * sehingga tidak ada pembulatan biner yang dapat menggeser angka.
 */
final class InvestorService
{
    /** Jumlah baris riwayat yang ditampilkan di dasbor. */
    public const HISTORY_LIMIT = 12;

    /** Batas maksimum baris riwayat (laporan cetak memakai ini). */
    public const HISTORY_MAX = 60;

    private function __construct()
    {
    }

    // -----------------------------------------------------------------------
    // Dasbor & laporan
    // -----------------------------------------------------------------------

    /**
     * Seluruh data yang boleh dilihat seorang mitra.
     *
     * @param int $historyLimit jumlah baris riwayat (di-clamp 1..HISTORY_MAX)
     * @return array<string, mixed> nilai siap render (label sudah diformat)
     *
     * @throws \RuntimeException akun tidak memiliki dompet modal
     */
    public static function dashboard(string $userId, int $historyLimit = self::HISTORY_LIMIT): array
    {
        $wallet = CapitalWallet::findByUserId($userId);

        if ($wallet === null) {
            throw new \RuntimeException(
                'Akun Anda belum memiliki dompet modal. Hubungi pengelola untuk melengkapinya.'
            );
        }

        $walletId = (string) $wallet['id'];
        $capitalCents = TellerService::moneyToCents((string) $wallet['balance_capital']);
        $profitCents = TellerService::moneyToCents((string) $wallet['balance_profit']);

        $latest = ProfitDistribution::latestForWallet($walletId);
        $utilizedCents = $latest === null
            ? 0
            : TellerService::moneyToCents((string) $latest['capital_utilized']);

        // Klamp: saldo bisa sudah diturunkan operator setelah putaran terakhir.
        $rotatingCents = min($utilizedCents, $capitalCents);
        $reserveCents = $capitalCents - $rotatingCents;

        [$monthFrom, $monthTo] = self::currentMonthRange();
        $monthProfitCents = TellerService::moneyToCents(
            ProfitDistribution::sumProfitForWalletBetween($walletId, $monthFrom, $monthTo)
        );
        $allTimeProfitCents = TellerService::moneyToCents(
            ProfitDistribution::sumProfitForWallet($walletId)
        );

        // Sisa yang benar-benar dapat diajukan = saldo − pengajuan yang belum selesai.
        $openProfitCents = TellerService::moneyToCents(
            WithdrawalRequest::sumOpenAmountForWallet($walletId, WithdrawalRequest::TYPE_PROFIT)
        );
        $openCapitalCents = TellerService::moneyToCents(
            WithdrawalRequest::sumOpenAmountForWallet($walletId, WithdrawalRequest::TYPE_CAPITAL)
        );

        $availableProfitCents = max(0, $profitCents - $openProfitCents);
        $availableCapitalCents = max(0, $capitalCents - $openCapitalCents);

        $ratioDecimal = self::shareDecimal($rotatingCents, $capitalCents);
        $roiDecimal = self::shareDecimal($monthProfitCents, $capitalCents);

        // Masa tunggu penarikan modal — kebijakan yang rusak tidak boleh
        // menggagalkan seluruh dasbor; mitra tetap melihat saldonya.
        $noticeDays = null;
        $noticeError = null;
        $capitalDueLabel = '—';

        try {
            $noticeDays = CapitalService::configuredNoticeDays();
            $capitalDueLabel = Formatter::tanggalId(
                CapitalService::workingDaysDueDate(date('Y-m-d'), $noticeDays)
            );
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            $noticeError = $exception->getMessage();
        }

        return [
            'wallet_id'   => $walletId,
            'tier'        => (string) $wallet['tier'],
            'tier_label'  => CapitalWallet::label((string) $wallet['tier']),
            'withdrawal_status' => (string) $wallet['withdrawal_status'],

            'capital_cents'  => $capitalCents,
            'profit_cents'   => $profitCents,
            'capital_label'  => self::money($capitalCents),
            'profit_label'   => self::money($profitCents),
            'total_label'    => self::money($capitalCents + $profitCents),
            'rotating_label' => self::money($rotatingCents),
            'reserve_label'  => self::money($reserveCents),
            'has_rounds'     => $latest !== null,
            'latest_date_label' => $latest === null
                ? null
                : Formatter::tanggalId((string) $latest['settlement_date']),

            'ratio_decimal' => $ratioDecimal,
            'ratio_label'   => Formatter::persen($ratioDecimal),

            'month_profit_label'    => self::money($monthProfitCents),
            'month_profit_positive' => $monthProfitCents > 0,
            'all_time_profit_label' => self::money($allTimeProfitCents),
            'roi_label'             => $capitalCents > 0 ? Formatter::persen($roiDecimal) : '—',

            'available_profit_cents'  => $availableProfitCents,
            'available_capital_cents' => $availableCapitalCents,
            'available_profit_label'  => self::money($availableProfitCents),
            'available_capital_label' => self::money($availableCapitalCents),
            'profit_field'    => self::fieldValue($availableProfitCents),
            'open_profit_label' => self::money($openProfitCents),
            'open_capital_label' => self::money($openCapitalCents),

            'notice_days'    => $noticeDays,
            'notice_error'   => $noticeError,
            'capital_due_label' => $capitalDueLabel,

            'history'  => self::historyView($walletId, $historyLimit),
            'requests' => self::requestView($walletId),
        ];
    }

    // -----------------------------------------------------------------------
    // Pengajuan penarikan
    // -----------------------------------------------------------------------

    /**
     * Ajukan penarikan atas nama mitra yang sedang masuk.
     *
     * Aturan uang tetap milik `CapitalService::requestWithdrawal()` — kelas ini
     * hanya (a) menyelesaikan dompet dari sesi dan (b) menyatakan bahwa
     * penarikan **dividen** tidak melewati masa tunggu (PRD § 4.3). Penarikan
     * modal pokok selalu memakai `notice_period_days` dari `system_settings`.
     *
     * @param array<string, mixed> $input kunci: withdrawal_type, amount, note
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException input tidak sah (pesan siap tampil)
     * @throws \RuntimeException dompet tidak ada
     */
    public static function requestWithdrawal(string $userId, array $input): array
    {
        $wallet = CapitalWallet::findByUserId($userId);

        if ($wallet === null) {
            throw new \RuntimeException(
                'Akun Anda belum memiliki dompet modal. Hubungi pengelola untuk melengkapinya.'
            );
        }

        $type = is_string($input['withdrawal_type'] ?? null) ? trim((string) $input['withdrawal_type']) : '';
        $isProfit = $type === WithdrawalRequest::TYPE_PROFIT;

        return CapitalService::requestWithdrawal([
            'wallet_id'       => (string) $wallet['id'],
            'withdrawal_type' => $type,
            'amount'          => $input['amount'] ?? null,
            'note'            => $input['note'] ?? null,
            // Dividen: jatuh tempo hari pengajuan. Modal: kebijakan sistem.
            'notice_days'     => $isProfit ? 0 : null,
        ]);
    }

    // -----------------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------------

    /**
     * Rentang bulan kalender berjalan pada zona waktu aplikasi (WIB).
     *
     * @return array{0: string, 1: string} [awal, akhir] dalam format `YYYY-MM-DD`
     */
    private static function currentMonthRange(): array
    {
        return [date('Y-m-01'), date('Y-m-t')];
    }

    /**
     * Persentase (`bagian ÷ dasar × 100`) sebagai string dua desimal.
     *
     * Dihitung dalam **perseratus integer** supaya tidak ada pembulatan biner;
     * `dasar = 0` dikembalikan `'0.00'` (pemanggil menampilkan `—` bila perlu).
     */
    private static function shareDecimal(int $partCents, int $baseCents): string
    {
        if ($baseCents <= 0 || $partCents <= 0) {
            return '0.00';
        }

        return AllocationEngine::hundredthsToDecimal(intdiv($partCents * 10000, $baseCents));
    }

    /**
     * Riwayat putaran milik mitra (tanggal putaran, modal diputar, imbal hasil).
     *
     * @return list<array<string, mixed>>
     */
    private static function historyView(string $walletId, int $limit): array
    {
        $rows = [];

        foreach (ProfitDistribution::listForWallet($walletId, $limit) as $row) {
            $utilizedCents = TellerService::moneyToCents((string) $row['capital_utilized']);
            $profitCents = TellerService::moneyToCents((string) $row['profit_earned']);

            $rows[] = [
                'date_label'     => Formatter::tanggalId((string) $row['settlement_date']),
                'utilized_label' => self::money($utilizedCents),
                'profit_label'   => self::money($profitCents),
                'profit_cents'   => $profitCents,
            ];
        }

        return $rows;
    }

    /**
     * Riwayat pengajuan penarikan milik mitra.
     *
     * @return list<array<string, mixed>>
     */
    private static function requestView(string $walletId): array
    {
        $rows = [];

        foreach (WithdrawalRequest::listForWallet($walletId) as $request) {
            $type = (string) $request['withdrawal_type'];
            $status = (string) $request['status'];

            $rows[] = [
                'id'           => (string) $request['id'],
                'type_label'   => WithdrawalRequest::typeLabel($type),
                'amount_label' => Formatter::rupiah($request['amount'] ?? 0),
                'status'       => $status,
                'status_label' => WithdrawalRequest::statusLabel($status),
                'due_label'    => (string) $request['notice_period_due'] === ''
                    ? '—'
                    : Formatter::tanggalId((string) $request['notice_period_due']),
                'created_label' => Formatter::tanggalId((string) ($request['created_at'] ?? '')),
                'pending'      => in_array($status, [
                    WithdrawalRequest::STATUS_PENDING,
                    WithdrawalRequest::STATUS_APPROVED,
                ], true),
                'payable_note' => self::payableNote($type, (string) $request['notice_period_due'], $status),
            ];
        }

        return $rows;
    }

    /**
     * Keterangan singkat untuk mitra: apa yang ditunggu pengajuan ini.
     */
    private static function payableNote(string $type, string $dueDate, string $status): string
    {
        if ($status === WithdrawalRequest::STATUS_COMPLETED) {
            return 'Sudah dibayarkan pengelola.';
        }

        if ($status === WithdrawalRequest::STATUS_REJECTED) {
            return 'Ditolak pengelola.';
        }

        if ($type === WithdrawalRequest::TYPE_CAPITAL && $dueDate !== '' && $dueDate > date('Y-m-d')) {
            return 'Menunggu jatuh tempo ' . Formatter::tanggalId($dueDate) . ' (H-7 hari kerja).';
        }

        return $status === WithdrawalRequest::STATUS_APPROVED
            ? 'Disetujui — menunggu pencairan pengelola.'
            : 'Menunggu persetujuan pengelola.';
    }

    /**
     * Sen (integer) menjadi label Rupiah tanpa pecahan.
     *
     * Konversinya lewat `TellerService::centsToDecimal()` (string desimal
     * kanonik) supaya jalur uang tetap integer dari ujung ke ujung — tidak ada
     * pembagian biner di antara basis data dan layar.
     */
    private static function money(int $cents): string
    {
        return Formatter::rupiah(TellerService::centsToDecimal($cents));
    }

    /**
     * Nilai awal isian nominal pada gaya angka lapangan (`154.341,18`),
     * tanpa awalan `Rp ` — dua desimal karena isian ini memang dapat berisi sen.
     */
    private static function fieldValue(int $cents): string
    {
        return trim(str_replace(
            Formatter::MONEY_PREFIX,
            '',
            Formatter::rupiah(TellerService::centsToDecimal($cents), 2)
        ));
    }
}
