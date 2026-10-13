<?php

declare(strict_types=1);

namespace AurumFlow\Controllers;

use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Formatter;
use AurumFlow\Services\InvestorService;

/**
 * Ekspor laporan ramah awam untuk mitra (Fase 5, PRD § 6 Modul 4).
 *
 * Dua permukaan, satu sumber angka:
 *   - `report()` — halaman HTML ramah cetak (`@media print`), tombol "Cetak /
 *     Simpan PDF" memakai `window.print()` peramban.
 *   - `csv()` — unduhan CSV dengan pemisah `;`, BOM UTF-8, dan angka konvensi
 *     Indonesia supaya terbuka rapi pada Excel berbahasa Indonesia.
 *
 * Mengapa bukan PDF buatan peladen: proyek ini tanpa Composer/`vendor/`, jadi
 * pustaka PDF pihak ketiga tidak tersedia dan tidak akan ditempatkan di
 * hosting. Keputusan ini tercatat pada plan Fase 5 § 2.1 (`dec-ef670a108317f925`).
 *
 * Isi laporan **hanya** data milik mitra yang sedang masuk — sumbernya
 * `InvestorService::dashboard()`, pintu yang sama dengan dasbor. Tidak ada teks
 * yang berasal dari klien pada berkas ekspor (hanya tanggal, nominal, dan
 * status yang dihitung peladen), sehingga formula injection tidak mungkin.
 */
final class ExportController extends BaseController
{
    private const VIEW = 'investor/report';

    private const LAYOUT = 'report';

    /**
     * Laporan ramah cetak (GET).
     */
    public function report(): void
    {
        $this->requireRole(Constants::ROLE_INVESTOR);

        $portfolio = InvestorService::dashboard(self::userId(), InvestorService::HISTORY_MAX);

        $this->render(self::VIEW, [
            'title'     => 'Laporan Portofolio',
            'portfolio' => $portfolio,
            'mitra'     => [
                'name'  => (string) ($_SESSION['user_name'] ?? ''),
                'phone' => (string) ($_SESSION['user_phone'] ?? ''),
            ],
            'printedLabel' => Formatter::tanggalId(date('Y-m-d')),
        ], self::LAYOUT);
    }

    /**
     * Unduhan CSV (GET).
     */
    public function csv(): void
    {
        $this->requireRole(Constants::ROLE_INVESTOR);

        $portfolio = InvestorService::dashboard(self::userId(), InvestorService::HISTORY_MAX);
        $rows = self::csvRows($portfolio);
        $body = '';

        foreach ($rows as $row) {
            $body .= implode(';', $row) . "\r\n";
        }

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . self::fileName() . '"');
            header('Cache-Control: no-store');
        }

        // BOM UTF-8 supaya Excel mengenali berkas sebagai UTF-8.
        echo "\xEF\xBB\xBF" . $body;

        exit;
    }

    // -----------------------------------------------------------------------
    // Penyusunan CSV
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $portfolio
     * @return list<list<string>>
     */
    private static function csvRows(array $portfolio): array
    {
        $rows = [
            ['Laporan Portofolio AurumFlow'],
            ['Nama Mitra', self::csvCell((string) ($_SESSION['user_name'] ?? ''))],
            ['Tanggal Laporan', Formatter::tanggalId(date('Y-m-d'))],
            [],
            ['Tanggal Putaran', 'Modal Diputar', 'Imbal Hasil Masuk', 'Status'],
        ];

        foreach ($portfolio['history'] as $row) {
            $rows[] = [
                self::csvCell((string) $row['date_label']),
                self::csvCell(self::plainMoney((string) $row['utilized_label'])),
                self::csvCell(self::plainMoney((string) $row['profit_label'])),
                'Terverifikasi',
            ];
        }

        $rows[] = [];
        $rows[] = ['Ringkasan', '', '', ''];
        $rows[] = ['Total Modal Dititipkan', self::csvCell(self::plainMoney((string) $portfolio['capital_label'])), '', ''];
        $rows[] = ['Total Keuntungan Diterima', self::csvCell(self::plainMoney((string) $portfolio['all_time_profit_label'])), '', ''];
        $rows[] = ['Modal Sedang Berputar', self::csvCell(self::plainMoney((string) $portfolio['rotating_label'])), '', ''];
        $rows[] = ['Sisa Saldo Kas Mengendap', self::csvCell(self::plainMoney((string) $portfolio['reserve_label'])), '', ''];

        return $rows;
    }

    /**
     * Label Rupiah menjadi angka polos konvensi Indonesia (`Rp 6.204.265`
     * → `6.204.265`). `Formatter` tetap satu-satunya pemilik format angkanya.
     */
    private static function plainMoney(string $label): string
    {
        return trim(str_replace(Formatter::MONEY_PREFIX, '', $label));
    }

    /**
     * Sel CSV: nilai yang kita tulis sendiri tidak pernah memuat pemisah,
     * kutip, atau baris baru — tetapi penjagaan ini tetap dilakukan supaya
     * perubahan format angka di masa depan tidak merusak struktur berkas.
     */
    private static function csvCell(string $value): string
    {
        if (strpbrk($value, ";\"\r\n") === false) {
            return $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }

    /**
     * Nama berkas unduhan: nomor telepon mitra (hanya digit) + tanggal.
     */
    private static function fileName(): string
    {
        $phone = preg_replace('/[^0-9]/', '', (string) ($_SESSION['user_phone'] ?? '')) ?? '';

        return 'aurumflow-portofolio-' . ($phone === '' ? 'mitra' : $phone) . '-' . date('Ymd') . '.csv';
    }

    private static function userId(): string
    {
        return (string) ($_SESSION['user_id'] ?? '');
    }
}
