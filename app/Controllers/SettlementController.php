<?php

declare(strict_types=1);

namespace AurumFlow\Controllers;

use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Formatter;
use AurumFlow\Helpers\Logger;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\CashRegister;
use AurumFlow\Models\DailySettlement;
use AurumFlow\Models\HuluTransaction;
use AurumFlow\Models\SystemSetting;
use AurumFlow\Services\BridgeLoanService;
use AurumFlow\Services\SettlementService;
use AurumFlow\Services\TellerService;

/**
 * Rekonsiliasi & Settlement Hilir — "Morning Settlement" (Roadmap FASE 3,
 * PRD Modul 2, UI/UX "Layar C").
 *
 * Aturan yang dipegang controller ini:
 *   1. Hanya peran `operator` yang boleh membuka maupun mengeksekusi tutup buku.
 *   2. CSRF diperiksa DUA kali (front controller + di sini) dan token
 *      sekali-pakai `_once` mencegah satu sesi tutup buku tercatat dua kali.
 *   3. Nominal TIDAK PERNAH dipercaya dari klien: yang dibaca dari form hanya
 *      berat jual, kadar, harga jual, beban aparat, dua alokasi kas, dan foto
 *      nota. Pendapatan, modal belanja hulu, laba, bagi hasil, dan total
 *      alokasi dihitung ulang `SettlementService`.
 *   4. Sukses memakai POST-Redirect-GET; gagal validasi mengembalikan 422
 *      beserta isian form supaya operator tidak mengetik ulang.
 */
final class SettlementController extends BaseController
{
    private const VIEW = 'operator/settlement';

    private const LAYOUT = 'operator';

    private const ROUTE = '/operator/settlement';

    /**
     * Saran beban aparat harian (PRD § 3.3: Rp 5.000.000/bulan ≈ Rp 200.000
     * per hari operasional). Angka ini hanya NILAI AWAL form — parameternya
     * tetap milik operator, bukan kunci `system_settings` baru (item O-2).
     */
    private const COORD_FEE_FIELD = '200.000';

    /**
     * Layar rekonsiliasi (GET).
     */
    public function index(): void
    {
        $this->renderSettlement(null, self::defaultInput());
    }

    /**
     * Eksekusi tutup buku harian (POST).
     */
    public function store(): void
    {
        $this->requireRole(Constants::ROLE_OPERATOR);

        // Pemeriksaan kedua di lapisan controller (pertahanan berlapis).
        $token = $_POST[Security::CSRF_FIELD] ?? null;

        if (!is_string($token) || !Security::verifyCsrf($token)) {
            self::abort(
                419,
                'Sesi kedaluwarsa',
                'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.'
            );
        }

        $once = $_POST[Security::FORM_FIELD] ?? null;
        $onceStatus = Security::consumeFormToken(is_string($once) ? $once : null);

        if ($onceStatus === Security::FORM_DUPLICATE) {
            Logger::warning('Eksekusi settlement ganda diabaikan', [
                'user_id' => (string) ($_SESSION['user_id'] ?? ''),
            ]);

            $this->flash('warning', 'Settlement ganda diabaikan — tidak ada pencatatan baru.');

            $this->redirect(self::ROUTE);
        }

        if ($onceStatus !== Security::FORM_OK) {
            self::abort(
                419,
                'Sesi kedaluwarsa',
                'Token konfirmasi tidak valid atau sudah dipakai. Muat ulang halaman lalu coba lagi.'
            );
        }

        $input = self::readInput();

        // Unggahan yang melebihi `post_max_size` membuat PHP mengosongkan
        // $_POST/$_FILES tanpa pesan apa pun — operator harus diberi tahu.
        $overflow = self::uploadOverflowMessage();

        if ($overflow !== null) {
            $this->renderSettlement($overflow, $input, 422);

            return;
        }

        try {
            $result = SettlementService::settle($input, self::readUpload());
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            if ($exception instanceof \RuntimeException) {
                // Invariant sistem (pos kas hilang, direktori nota tak writable):
                // operator tetap menerima pesan yang dapat ditindaklanjuti,
                // tetapi kejadiannya layak tercatat sebagai error.
                Logger::error('Settlement gagal pada lapisan sistem', [
                    'user_id' => (string) ($_SESSION['user_id'] ?? ''),
                    'alasan'  => $exception->getMessage(),
                ]);
            }

            $this->renderSettlement($exception->getMessage(), $input, 422);

            return;
        }

        $quote = $result['quote'];
        $allocation = is_array($result['allocation'] ?? null) ? $result['allocation'] : [];
        $bridge = is_array($result['bridge'] ?? null) ? $result['bridge'] : [];

        $this->flash(
            'success',
            sprintf(
                'Settlement tersimpan: pendapatan %s, laba bersih %s (pengelola %s / pool %s). '
                . 'Kas meja %s, brankas %s.%s%s',
                Formatter::rupiah($quote['revenue']),
                Formatter::rupiah($quote['net']),
                Formatter::rupiah($quote['operator_share']),
                Formatter::rupiah($quote['investor_pool']),
                Formatter::rupiah($result['teller_after']),
                Formatter::rupiah($result['brankas_after']),
                self::allocationSuffix($allocation, $quote),
                self::bridgeSuffix($bridge, $quote)
            )
        );

        $this->redirect(self::ROUTE);
    }

    /**
     * Keterangan tambahan pada pesan sukses: pengembalian dana talangan
     * (Fase 6) dan — bila ada — fee yang melebihi hak laba pengelola.
     *
     * @param array<string, mixed> $bridge ringkasan `BridgeLoanService::settleLoans()`
     * @param array<string, mixed> $quote
     */
    private static function bridgeSuffix(array $bridge, array $quote): string
    {
        $count = (int) ($bridge['count'] ?? 0);

        if ($count <= 0) {
            return '';
        }

        $suffix = sprintf(
            ' Talangan: %d pengembalian %s dari Kas Meja Bakar (fee %s dibebankan ke hak pengelola).',
            $count,
            Formatter::rupiah((string) ($bridge['total_return'] ?? '0.00')),
            Formatter::rupiah((string) ($bridge['absorbed'] ?? '0.00'))
        );

        if ((int) $quote['bridge_uncovered_cents'] > 0) {
            $suffix .= sprintf(
                ' Sebagian fee (%s) melebihi hak laba pengelola dan dibayar dari kas meja — pool mitra tetap utuh.',
                Formatter::rupiah((string) $quote['bridge_uncovered'])
            );
        }

        return $suffix;
    }

    /**
     * Keterangan tambahan pada pesan sukses: ke mana pool 20 % mengalir.
     *
     * @param array<string, mixed> $allocation ringkasan `AllocationEngine::apply()`
     * @param array<string, mixed> $quote
     */
    private static function allocationSuffix(array $allocation, array $quote): string
    {
        if ($allocation === []) {
            return '';
        }

        $wallets = (int) ($allocation['mitra_wallets'] ?? 0);

        if ($wallets > 0) {
            return sprintf(' Pool %s terbagi ke %d dompet mitra.', Formatter::rupiah($quote['investor_pool']), $wallets);
        }

        if ((int) ($allocation['profit_cents'] ?? 0) === 0) {
            return ' Belum ada dompet mitra bermodal yang terserap — pool belum terdistribusi.';
        }

        return '';
    }

    /**
     * Render layar rekonsiliasi beserta seluruh data pendukung.
     *
     * @param array<string, mixed> $input
     */
    private function renderSettlement(?string $error, array $input, int $status = 200): void
    {
        $this->requireRole(Constants::ROLE_OPERATOR);

        $teller = CashRegister::activeTeller();
        $brankas = CashRegister::findBrankas();

        if ($teller === null || $brankas === null) {
            self::abort(
                500,
                'Pos kas tidak tersedia',
                'Pos kas "' . CashRegister::TELLER_NAME . '" atau "' . CashRegister::BRANKAS_NAME
                . '" belum terdaftar. Jalankan instalasi basis data.'
            );
        }

        if ($status !== 200 && !headers_sent()) {
            http_response_code($status);
        }

        $totals = HuluTransaction::holdingTotals();
        $context = SettlementService::batchContext();
        $deficitRaw = (string) (SystemSetting::get(SettlementService::SETTING_ACTIVE_DEFICIT, '0.00') ?? '0.00');

        // Dana talangan aktif (Fase 6): kewajiban yang harus dikembalikan sesi ini.
        // Fee-nya dibebankan HANYA ke hak pengelola — pool mitra tidak tersentuh.
        // Satu pembacaan untuk kartu & baris (lihat `viewModel()`).
        $bridgeTotals = BridgeLoanService::activeTotals();
        $bridgeView = BridgeLoanService::viewModel($bridgeTotals);

        // Konfigurasi Alpine.js: HANYA integer dari kode kita sendiri (tidak
        // pernah teks kiriman pengguna), tetap dicetak lewat Security::e()
        // supaya aturan "tidak ada output dinamis tanpa escaping" seragam.
        $alpineConfig = json_encode([
            'holding_mg'            => $context['holding_mg'],
            'holding_cost_cents'    => $context['holding_cost_cents'],
            'active_deficit_cents'  => $context['active_deficit_cents'],
            'bridge_fee_cents'      => TellerService::moneyToCents($bridgeTotals['fee']),
            'bridge_count'          => $bridgeTotals['count'],
            'receipt_soft_bytes'    => SettlementService::RECEIPT_SOFT_LIMIT_BYTES,
            'receipt_hard_bytes'    => SettlementService::RECEIPT_HARD_LIMIT_BYTES,
        ], JSON_THROW_ON_ERROR);

        $latest = DailySettlement::latest(1);

        // Bentuk data untuk komponen view. Disusun sekali di sini supaya
        // header layout (`$register`/`$holding`) dan badan layar
        // (`$registers`/`$batch`) membaca nilai yang sama.
        $tellerView = [
            'name'        => (string) $teller['register_name'],
            'saldo_label' => Formatter::rupiah($teller['current_balance']),
        ];

        $brankasView = [
            'name'        => (string) $brankas['register_name'],
            'saldo_label' => Formatter::rupiah($brankas['current_balance']),
        ];

        $holdingView = [
            'count'      => $totals['count'],
            'gram_label' => Formatter::gram($totals['grams']),
        ];

        $this->render(self::VIEW, [
            'title' => 'Rekonsiliasi & Settlement',

            // Kontrak layout operator (header sticky): saldo kas meja + emas.
            'register' => $tellerView,
            'holding'  => $holdingView,

            'batch' => [
                'empty'      => $totals['count'] === 0,
                'count'      => $holdingView['count'],
                'gram_label' => $holdingView['gram_label'],
                'cost_label' => Formatter::rupiah($totals['cost']),
            ],

            'registers' => [
                'teller'  => $tellerView,
                'brankas' => $brankasView,
            ],

            'deficit' => [
                'label'  => Formatter::rupiah($deficitRaw),
                'active' => TellerService::moneyToCents($deficitRaw) > 0,
            ],

            'bridge' => $bridgeView,

            'todayCount' => DailySettlement::countForDate(date('Y-m-d')),

            'lastSettlement' => $latest === [] ? null : [
                'tanggal'       => Formatter::tanggalId((string) ($latest[0]['settlement_date'] ?? ''), false),
                'net_label'     => Formatter::rupiah($latest[0]['net_settled_profit'] ?? 0),
                'revenue_label' => Formatter::rupiah($latest[0]['total_shop_revenue'] ?? 0),
                'has_receipt'   => isset($latest[0]['receipt_photo_url']) && (string) $latest[0]['receipt_photo_url'] !== '',
            ],

            'formToken' => Security::issueFormToken(),

            'alpineConfig' => $alpineConfig,

            'input' => $input,

            'error' => $error,
        ], self::LAYOUT);
    }

    /**
     * Nilai awal form saat halaman pertama dibuka.
     *
     * Berat jual diisi total emas `holding` (saran, tetap boleh diubah) dan
     * beban aparat memakai asumsi PRD. Kadar, harga, serta dua alokasi kas
     * dibiarkan kosong: dua alokasi yang kosong berarti "pakai saran sistem"
     * (lihat `SettlementService::quote()`), sehingga form tetap benar walau
     * JavaScript mati.
     *
     * @return array<string, string>
     */
    private static function defaultInput(): array
    {
        $totals = HuluTransaction::holdingTotals();

        return [
            'sold_grams'       => self::gramFieldValue($totals['grams']),
            'density_pct'      => '',
            'shop_price_per_g' => '',
            'coord_fee'        => self::COORD_FEE_FIELD,
            'kas_split'        => '',
            'brankas_split'    => '',
        ];
    }

    /**
     * Baca input form (tanpa satu pun nominal hasil hitung dari klien).
     *
     * @return array<string, string>
     */
    private static function readInput(): array
    {
        return [
            'sold_grams'       => self::post('sold_grams'),
            'density_pct'      => self::post('density_pct'),
            'shop_price_per_g' => self::post('shop_price_per_g'),
            'coord_fee'        => self::post('coord_fee'),
            'kas_split'        => self::post('kas_split'),
            'brankas_split'    => self::post('brankas_split'),
        ];
    }

    private static function post(string $field): string
    {
        $value = $_POST[$field] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Entri `$_FILES` untuk foto nota (null bila tidak ada).
     *
     * @return array<string, mixed>|null
     */
    private static function readUpload(): ?array
    {
        $entry = $_FILES[SettlementService::RECEIPT_FIELD] ?? null;

        if (!is_array($entry)) {
            return null;
        }

        return [
            'name'     => isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : '',
            'type'     => isset($entry['type']) && is_string($entry['type']) ? $entry['type'] : '',
            'tmp_name' => isset($entry['tmp_name']) && is_string($entry['tmp_name']) ? $entry['tmp_name'] : '',
            'error'    => isset($entry['error']) ? (int) $entry['error'] : UPLOAD_ERR_NO_FILE,
            'size'     => isset($entry['size']) ? (int) $entry['size'] : 0,
        ];
    }

    /**
     * Kadar yang melebihi `post_max_size` membuat PHP mengosongkan seluruh
     * `$_POST`/`$_FILES`; tanpa deteksi ini operator hanya melihat form kosong
     * tanpa alasan (risiko R-3 rencana Fase 3).
     */
    private static function uploadOverflowMessage(): ?string
    {
        $length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        $postMax = self::phpSizeToBytes((string) ini_get('post_max_size'));

        if ($length <= 0 || $postMax <= 0 || $length <= $postMax) {
            return null;
        }

        if ($_POST !== [] || $_FILES !== []) {
            return null;
        }

        return 'Unggahan melebihi batas server (' . intdiv($postMax, 1048576)
            . ' MB termasuk data form). Perkecil foto nota lalu coba lagi.';
    }

    /**
     * Ubah notasi ukuran PHP (`8M`, `512K`, `1G`) menjadi byte.
     */
    private static function phpSizeToBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }

    /**
     * Nilai gram dari database (`'18.050'`) menjadi teks field lapangan.
     *
     * Konvensi field berat (rencana Fase 2 § 4.4): SATU pemisah berarti
     * desimal. Karena itu titik ribuan dari database harus ditulis ulang
     * sebagai koma, sebab `'18.050'` pada field akan terbaca 18,05 gr.
     */
    private static function gramFieldValue(string $grams): string
    {
        $label = Formatter::gram($grams);

        return trim((string) preg_replace('/\s*' . preg_quote(Constants::GRAM_UNIT, '/') . '$/', '', $label));
    }
}
