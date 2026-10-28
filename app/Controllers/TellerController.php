<?php

declare(strict_types=1);

namespace AurumFlow\Controllers;

use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Formatter;
use AurumFlow\Helpers\Logger;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\CashRegister;
use AurumFlow\Models\HuluTransaction;
use AurumFlow\Models\OperationalExpense;
use AurumFlow\Services\BridgeLoanService;
use AurumFlow\Services\ExpenseService;
use AurumFlow\Services\TellerService;

/**
 * Meja Kasir Hulu — "Quick Teller Mode" (Roadmap FASE 2, PRD Modul 1).
 *
 * Aturan yang dipegang controller ini:
 *   1. **Daftar izin eksplisit (*allow-list*).** Sejak Fase 7 layar ini menerima
 *      peran `operator` **dan** `cashier` (`self::ALLOWED_ROLES`) — dan inilah
 *      satu-satunya permukaan yang boleh dibuka kasir: meja kasir, tampilan
 *      saldo Kas Meja Bakar + Emas holding, penerimaan dana talangan, dan
 *      pencatatan beban operasional (Fase 9). Setiap controller lain tetap
 *      menolak `ROLE_CASHIER`, sehingga isolasi bersifat *allow-list*.
 *   2. CSRF diperiksa DUA kali (front controller + di sini) — pertahanan
 *      berlapis seperti AuthController.
 *   3. Submit ganda dicegah token sekali-pakai `_once` (bukan hanya oleh
 *      komponen swipe di sisi peramban).
 *   4. Nominal TIDAK PERNAH diambil dari klien sebagai hasil hitung. Yang dibaca
 *      dari form hanya berat, harga, alias, status toggle jasa bakar, serta —
 *      pada jalur OPEX — kategori, penerima, nominal, dan catatan. Seluruh
 *      angka turunan dihitung ulang service.
 *   5. Sukses memakai POST-Redirect-GET; gagal validasi mengembalikan 422
 *      beserta isi form supaya operator tidak mengetik ulang timbangan.
 */
final class TellerController extends BaseController
{
    private const VIEW = 'operator/teller';

    private const LAYOUT = 'operator';

    private const ROUTE = '/operator/teller';

    private const RECENT_LIMIT = 3;

    /**
     * Peran yang boleh membuka meja kasir — SATU-SATUNYA daftar izin kasir.
     *
     * Kasir adalah staf lapangan: ia menimbang, membayar, dan boleh meminjam kas
     * (dana talangan), tetapi tidak pernah melihat rekonsiliasi, kelola modal,
     * dasbor ringkasan, tata kelola akun, maupun portal mitra.
     */
    private const ALLOWED_ROLES = [Constants::ROLE_OPERATOR, Constants::ROLE_CASHIER];

    /**
     * Layar kasir (GET).
     */
    public function index(): void
    {
        $this->renderTeller(null, self::emptyInput());
    }

    /**
     * Catat pembayaran tunai (POST).
     */
    public function store(): void
    {
        $this->requireRole(...self::ALLOWED_ROLES);
        $this->assertPostSecurity('Transaksi ganda diabaikan — tidak ada pencatatan baru.');

        $input = self::readInput();

        try {
            $result = TellerService::payCash([
                'weight'           => $input['weight'],
                'price'            => $input['price'],
                'farmer_alias'     => $input['farmer_alias'],
                'burn_fee_enabled' => $input['burn_fee_enabled'],
            ]);
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            // Penolakan yang dapat ditindaklanjuti operator: tampilkan kembali
            // form (422) tanpa satu pun perubahan kas.
            Logger::warning('Transaksi kasir ditolak', [
                'user_id' => (string) ($_SESSION['user_id'] ?? ''),
                'alasan'  => $exception->getMessage(),
            ]);

            $this->renderTeller($exception->getMessage(), $input, 422);

            return;
        }

        $this->flash(
            'success',
            sprintf(
                'Tersimpan: %s gr untuk %s — kas keluar %s, saldo kas tersisa %s.',
                Formatter::gram($result['weight']),
                (string) $result['alias'],
                Formatter::rupiah($result['net']),
                Formatter::rupiah($result['balance_after'])
            )
        );

        $this->redirect(self::ROUTE);
    }

    /**
     * Terima dana talangan dari pihak luar (POST) — Fase 6.
     *
     * Satu transaksi basis data (`BridgeLoanService::receive()`): kunci pos kas
     * → baris `bridge_loans` (`active`) → saldo Kas Meja Bakar naik → mutasi
     * `in`. Nominal fee dihitung ulang peladen; nilai apa pun dari form untuk
     * kolom itu tidak dipercaya.
     */
    public function storeBridge(): void
    {
        $this->requireRole(...self::ALLOWED_ROLES);
        $this->assertPostSecurity('Talangan ganda diabaikan — tidak ada pencatatan baru.');

        $input = self::readBridgeInput();

        try {
            $result = BridgeLoanService::receive($input);
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            Logger::warning('Dana talangan ditolak', [
                'user_id' => (string) ($_SESSION['user_id'] ?? ''),
                'alasan'  => $exception->getMessage(),
            ]);

            $this->renderTeller(null, self::emptyInput(), 422, [
                'input' => $input,
                'error' => $exception->getMessage(),
                'open'  => true,
            ]);

            return;
        }

        $this->flash(
            'success',
            sprintf(
                'Dana talangan %s diterima: pokok %s, fee %s. Kas %s bertambah menjadi %s.',
                (string) $result['lender_name'],
                Formatter::rupiah($result['principal']),
                Formatter::rupiah($result['fee']),
                (string) $result['register_name'],
                Formatter::rupiah($result['balance_after'])
            )
        );

        $this->redirect(self::ROUTE);
    }

    /**
     * Catat beban operasional non-emas (POST) — Fase 9.
     *
     * Satu transaksi basis data (`ExpenseService::pay()`): kunci pos kas → cek
     * saldo → mutasi `out` → saldo Kas Meja Bakar turun → voucher
     * `operational_expenses`. Voucher itulah yang diserap tutup buku berikutnya
     * sebagai pengurang laba kotor (bukan pengurang kas yang dialokasikan,
     * karena uangnya sudah keluar dari laci di sini).
     */
    public function storeOpex(): void
    {
        $this->requireRole(...self::ALLOWED_ROLES);
        $this->assertPostSecurity('Pencatatan beban ganda diabaikan — tidak ada pencatatan baru.');

        $input = self::readOpexInput();

        try {
            ExpenseService::pay($input + ['recorded_by' => self::actorId()]);
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            Logger::warning('Beban operasional ditolak', [
                'user_id' => (string) ($_SESSION['user_id'] ?? ''),
                'alasan'  => $exception->getMessage(),
            ]);

            $this->renderTeller(null, self::emptyInput(), 422, null, [
                'input' => $input,
                'error' => $exception->getMessage(),
                'open'  => true,
            ]);

            return;
        }

        $register = CashRegister::activeTeller();

        $this->flash(
            'success',
            sprintf(
                'Beban %s untuk %s dicatat. Saldo %s kini %s.',
                OperationalExpense::categoryLabel($input['category']),
                $input['payee_name'],
                $register === null ? CashRegister::TELLER_NAME : (string) $register['register_name'],
                Formatter::rupiah($register['current_balance'] ?? 0)
            )
        );

        $this->redirect(self::ROUTE);
    }

    /**
     * Pemeriksaan keamanan POST bersama ketiga jalur meja kasir (bayar tunai,
     * terima talangan, catat beban) — satu pemilik aturan: CSRF (pertahanan
     * berlapis di atas front controller) dan token sekali-pakai `_once`
     * anti double-submit.
     *
     * Tidak pernah kembali: permintaan tanpa token sah diakhiri `419`, dan
     * kiriman ganda diakhiri POST-Redirect-GET dengan pesan `$duplicateMessage`.
     */
    private function assertPostSecurity(string $duplicateMessage): void
    {
        $token = $_POST[Security::CSRF_FIELD] ?? null;

        if (!is_string($token) || !Security::verifyCsrf($token)) {
            self::abort(
                419,
                'Sesi kedaluwarsa',
                'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.'
            );
        }

        // Token sekali-pakai: menutup celah double-submit yang tidak dapat
        // ditutup JavaScript (retry jaringan, tombol Back, dua tap cepat).
        $once = $_POST[Security::FORM_FIELD] ?? null;
        $onceStatus = Security::consumeFormToken(is_string($once) ? $once : null);

        if ($onceStatus === Security::FORM_DUPLICATE) {
            Logger::warning('Submit kasir ganda diabaikan', [
                'user_id' => (string) ($_SESSION['user_id'] ?? ''),
            ]);

            $this->flash('warning', $duplicateMessage);

            $this->redirect(self::ROUTE);
        }

        if ($onceStatus !== Security::FORM_OK) {
            self::abort(
                419,
                'Sesi kedaluwarsa',
                'Token konfirmasi tidak valid atau sudah dipakai. Muat ulang halaman lalu coba lagi.'
            );
        }
    }

    /**
     * Render layar kasir beserta seluruh data widget.
     *
     * @param array<string, mixed> $input
     * @param array{input: array<string, mixed>, error: ?string, open: bool}|null $bridge
     *        Keadaan modal dana talangan: isian terpelihara + alasan penolakan.
     *        `null` = modal tertutup dengan isian kosong (permintaan GET).
     * @param array{input: array<string, mixed>, error: ?string, open: bool}|null $opex
     *        Keadaan modal beban operasional (Fase 9) — pola yang sama.
     */
    private function renderTeller(?string $error, array $input, int $status = 200, ?array $bridge = null, ?array $opex = null): void
    {
        $this->requireRole(...self::ALLOWED_ROLES);

        $register = CashRegister::activeTeller();

        if ($register === null) {
            self::abort(
                500,
                'Pos kas tidak tersedia',
                'Pos kas "' . CashRegister::TELLER_NAME . '" belum terdaftar. Jalankan instalasi basis data.'
            );
        }

        if ($status !== 200 && !headers_sent()) {
            http_response_code($status);
        }

        $burnFee = TellerService::configuredBurnFee();
        $holdingGrams = HuluTransaction::holdingTotalGrams();

        // Konfigurasi untuk Alpine.js: HANYA integer dari kode kita (tidak
        // pernah teks kiriman pengguna), dan tetap dilewatkan Security::e()
        // saat dicetak agar view tidak pernah mengeluarkan nilai dinamis tanpa
        // escaping. Status toggle jasa bakar tidak ikut: sumbernya atribut
        // `checked` pada kotak centang (lihat app.js tellerForm::init()).
        $alpineConfig = json_encode([
            'fee'   => (int) $burnFee['fee_cents'],
            'saldo' => TellerService::moneyToCents((string) $register['current_balance']),
        ], JSON_THROW_ON_ERROR);

        $bridgeState = $bridge ?? ['input' => self::emptyBridgeInput(), 'error' => null, 'open' => false];
        $opexState = $opex ?? ['input' => self::emptyOpexInput(), 'error' => null, 'open' => false];

        $this->render(self::VIEW, [
            'title' => 'Meja Kasir Hulu',

            'register' => [
                'name'        => (string) $register['register_name'],
                'saldo_label' => Formatter::rupiah($register['current_balance']),
            ],

            'burnFee' => [
                'fee_label'            => Formatter::rupiah($burnFee['fee']),
                'operator_cut_label'   => Formatter::rupiah($burnFee['operator_cut']),
                'consumable_cut_label' => Formatter::rupiah($burnFee['consumable_cut']),
            ],

            'holding' => [
                'count'      => HuluTransaction::holdingCount(),
                'gram_label' => Formatter::gram($holdingGrams),
            ],

            'recentRows' => self::mapRecentRows(),

            'bridge' => BridgeLoanService::viewModel(),

            'bridgeInput' => $bridgeState['input'],

            'bridgeError' => is_string($bridgeState['error'] ?? null) ? $bridgeState['error'] : null,

            'bridgeOpen' => (bool) ($bridgeState['open'] ?? false),

            // Beban operasional (Fase 9). Kategori berasal dari kode kita
            // sendiri (enum append-only) dan dicetak lewat Security::e().
            'opex' => [
                'categories' => array_map(
                    static fn (string $category): array => [
                        'value' => $category,
                        'label' => OperationalExpense::categoryLabel($category),
                    ],
                    OperationalExpense::categories()
                ),
                'input'      => $opexState['input'],
                'error'      => is_string($opexState['error'] ?? null) ? $opexState['error'] : null,
                'open'       => (bool) ($opexState['open'] ?? false),
            ],

            'formToken' => Security::issueFormToken(),

            'alpineConfig' => $alpineConfig,

            'input' => $input,

            'error' => $error,
        ], self::LAYOUT);
    }

    /**
     * @return list<array{alias: string, gram_label: string, net_label: string,
     *                     waktu: string, status_label: string}>
     */
    private static function mapRecentRows(): array
    {
        $rows = [];

        foreach (HuluTransaction::recent(self::RECENT_LIMIT) as $row) {
            $rows[] = [
                'alias'        => (string) ($row['farmer_alias'] ?? ''),
                'gram_label'   => Formatter::gram($row['dry_weight'] ?? 0),
                'net_label'    => Formatter::rupiah($row['net_cash_paid'] ?? 0),
                'waktu'        => Formatter::tanggalId((string) ($row['created_at'] ?? ''), true),
                'status_label' => (string) ($row['status'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Nilai awal form saat halaman pertama dibuka.
     *
     * @return array{farmer_alias: string, weight: string, price: string, burn_fee_enabled: bool}
     */
    private static function emptyInput(): array
    {
        return [
            'farmer_alias'     => '',
            'weight'           => '',
            'price'            => '',
            'burn_fee_enabled' => true,
        ];
    }

    /**
     * Baca input form. Hanya berat, harga, alias, dan status toggle jasa
     * bakar yang diambil — tidak ada nominal uang dari klien.
     *
     * @return array{farmer_alias: string, weight: string, price: string, burn_fee_enabled: bool}
     */
    private static function readInput(): array
    {
        return [
            'farmer_alias' => isset($_POST['farmer_alias']) && is_string($_POST['farmer_alias'])
                ? trim($_POST['farmer_alias'])
                : '',
            'weight' => isset($_POST['dry_weight']) && is_string($_POST['dry_weight'])
                ? trim($_POST['dry_weight'])
                : '',
            'price' => isset($_POST['buy_price_per_g']) && is_string($_POST['buy_price_per_g'])
                ? trim($_POST['buy_price_per_g'])
                : '',
            'burn_fee_enabled' => isset($_POST['burn_fee_enabled']),
        ];
    }

    /**
     * Isian awal modal dana talangan.
     *
     * @return array{lender_name: string, principal_amount: string, fee_type: string,
     *               fee_rate_pct: string, fee_fixed: string, notes: string}
     */
    private static function emptyBridgeInput(): array
    {
        return [
            'lender_name'      => '',
            'principal_amount' => '',
            'fee_type'         => BridgeLoanService::FEE_TYPE_FIXED,
            'fee_rate_pct'     => '',
            'fee_fixed'        => '',
            'notes'            => '',
        ];
    }

    /**
     * Baca isian modal dana talangan.
     *
     * Yang dikirim klien hanya **input**: nama pemberi dana, pokok, bentuk fee,
     * tarif atau nominal fee, dan catatan. `fee_amount` hasil hitung tidak
     * pernah dibaca dari form (peladen menghitungnya), dan tidak ada
     * `settlement_id`/`bridge_loan_id` yang diterima.
     *
     * @return array{lender_name: string, principal_amount: string, fee_type: string,
     *               fee_rate_pct: string, fee_fixed: string, notes: string}
     */
    private static function readBridgeInput(): array
    {
        return [
            'lender_name'      => self::post('lender_name'),
            'principal_amount' => self::post('principal_amount'),
            'fee_type'         => self::post('fee_type'),
            'fee_rate_pct'     => self::post('fee_rate_pct'),
            'fee_fixed'        => self::post('fee_fixed'),
            'notes'            => self::post('notes'),
        ];
    }

    private static function post(string $field): string
    {
        $value = $_POST[$field] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Isian awal modal beban operasional (Fase 9).
     *
     * @return array{category: string, payee_name: string, amount: string, notes: string}
     */
    private static function emptyOpexInput(): array
    {
        return [
            'category'   => OperationalExpense::CATEGORY_COORDINATION,
            'payee_name' => '',
            'amount'     => '',
            'notes'      => '',
        ];
    }

    /**
     * Baca isian modal beban operasional.
     *
     * Yang dikirim klien hanya **input**: kategori, nama penerima, nominal, dan
     * catatan. Tanggal bisnis, `register_id`, `mutation_id`, dan `settlement_id`
     * tidak pernah dibaca dari form — peladen yang menentukan.
     *
     * @return array{category: string, payee_name: string, amount: string, notes: string}
     */
    private static function readOpexInput(): array
    {
        return [
            'category'   => self::post('opex_category'),
            'payee_name' => self::post('opex_payee'),
            'amount'     => self::post('opex_amount'),
            'notes'      => self::post('opex_notes'),
        ];
    }

    /**
     * `users.id` sesi untuk jejak audit (`recorded_by` pada voucher beban).
     */
    private static function actorId(): ?string
    {
        $id = $_SESSION['user_id'] ?? null;

        return is_string($id) && Security::isUuid($id) ? $id : null;
    }
}
