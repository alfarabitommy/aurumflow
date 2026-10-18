<?php

declare(strict_types=1);

namespace AurumFlow\Controllers;

use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Formatter;
use AurumFlow\Helpers\Logger;
use AurumFlow\Helpers\Security;
use AurumFlow\Services\KasbonService;

/**
 * Buku Kasbon Petani — permukaan KEDUA yang menerima peran `cashier` (Fase 8).
 *
 * Aturan yang dipegang controller ini:
 *   1. **Daftar izin eksplisit (*allow-list*).** Sejak Fase 8 ada dua permukaan
 *      yang menerima `cashier`: `TellerController` (meja kasir + talangan) dan
 *      `KasbonController` (buku kasbon). Setiap controller lain tetap menolak
 *      peran itu, sehingga isolasi tidak pernah menjadi *deny-list*.
 *   2. `requireRole(...self::ALLOWED_ROLES)` adalah **pernyataan PERTAMA** setiap
 *      method publik (guard-first) — permintaan terlarang dijawab `302` ke beranda
 *      peran **tanpa tulisan** dan **tanpa mengonsumsi** token `_once`.
 *   3. CSRF diperiksa DUA kali (front controller + di sini) dan setiap mutasi
 *      memakai token sekali-pakai `_once`; sukses selalu PRG agar halaman
 *      berikutnya menerbitkan token baru.
 *   4. **Nominal tidak pernah dipercaya dari klien.** Form hanya mengirim pemilih
 *      petani, nominal, metode, dan catatan; batas harian, saldo kas, sisa utang,
 *      dan `balance_after` dihitung ulang `KasbonService`.
 *   5. Gagal validasi mengembalikan `422` beserta isian form supaya kasir tidak
 *      mengetik ulang di depan antrean.
 */
final class KasbonController extends BaseController
{
    private const VIEW = 'operator/kasbon';

    private const LAYOUT = 'operator';

    private const ROUTE = '/operator/kasbon';

    /**
     * Peran yang boleh membuka buku kasbon — daftar izin KEDUA untuk kasir.
     *
     * Kasir mencairkan panjar dan menerima angsuran di depan penambang, jadi
     * permukaan ini harus dapat dijangkau perannya; ia tetap tidak pernah melihat
     * rekonsiliasi, kelola modal, dasbor, maupun portal mitra.
     */
    private const ALLOWED_ROLES = [Constants::ROLE_OPERATOR, Constants::ROLE_CASHIER];

    /**
     * Layar Buku Kasbon (GET).
     */
    public function index(): void
    {
        $this->requireRole(...self::ALLOWED_ROLES);

        $selected = isset($_GET['miner']) && is_string($_GET['miner']) ? trim($_GET['miner']) : '';

        $this->renderKasbon(null, self::emptyInput(), 200, $selected);
    }

    /**
     * Cairkan satu kasbon (POST).
     */
    public function disburse(): void
    {
        // Guard-first: peran diperiksa SEBELUM token `_once` dikonsumsi.
        $this->requireRole(...self::ALLOWED_ROLES);
        $this->guardOnce('Kasbon ganda diabaikan — tidak ada pencatatan baru.');

        $input = self::readInput();

        try {
            $result = KasbonService::disburse([
                'miner_id'         => $input['miner_id'],
                'miner_label'      => $input['miner_label'],
                'principal_amount' => $input['principal_amount'],
                'notes'            => $input['notes'],
                'actor_id'         => self::actorId(),
            ]);
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            Logger::warning('Kasbon ditolak', [
                'user_id' => self::actorId() ?? '',
                'alasan'  => $exception->getMessage(),
            ]);

            $this->renderKasbon($exception->getMessage(), $input, 422);

            return;
        }

        $this->flash(
            'success',
            sprintf(
                'Kasbon %s untuk %s tersimpan — kas keluar %s, saldo kas %s, sisa batas hari ini %s.',
                Formatter::rupiah($result['principal']),
                (string) $result['miner_name'],
                Formatter::rupiah($result['principal']),
                Formatter::rupiah($result['balance_after']),
                Formatter::rupiah($result['remaining_limit'])
            )
        );

        $this->redirect(self::ROUTE);
    }

    /**
     * Terima angsuran kasbon — tunai atau potong nota (POST).
     */
    public function repay(): void
    {
        // Guard-first: peran diperiksa SEBELUM token `_once` dikonsumsi.
        $this->requireRole(...self::ALLOWED_ROLES);
        $this->guardOnce('Angsuran ganda diabaikan — tidak ada pencatatan baru.');

        $input = self::readInput();

        try {
            $result = KasbonService::repay([
                'miner_id'       => $input['pay_miner_id'],
                'miner_label'    => $input['pay_miner_label'],
                'amount'         => $input['amount'],
                'payment_method' => $input['payment_method'],
                'notes'          => $input['pay_notes'],
                'actor_id'       => self::actorId(),
            ]);
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            Logger::warning('Angsuran kasbon ditolak', [
                'user_id' => self::actorId() ?? '',
                'alasan'  => $exception->getMessage(),
            ]);

            $this->renderKasbon($exception->getMessage(), $input, 422);

            return;
        }

        $this->flash(
            'success',
            sprintf(
                'Angsuran %s (%s) dari %s tersimpan — sisa utang %s, saldo kas %s.',
                Formatter::rupiah($result['amount']),
                (string) $result['method_label'],
                (string) $result['miner_name'],
                Formatter::rupiah($result['outstanding_after']),
                Formatter::rupiah($result['balance_after'])
            )
        );

        $this->redirect(self::ROUTE);
    }

    /**
     * Daftarkan petani baru (POST) — hanya nama yang wajib.
     *
     * PRG mengembalikan halaman dengan petani tersebut **terpilih** (`?miner=`),
     * sehingga kasir dapat langsung mengetik nominal tanpa mencari ulang namanya.
     */
    public function storeMiner(): void
    {
        // Guard-first: peran diperiksa SEBELUM token `_once` dikonsumsi.
        $this->requireRole(...self::ALLOWED_ROLES);
        $this->guardOnce('Permintaan ganda diabaikan — tidak ada petani baru.');

        $input = self::readInput();

        try {
            $miner = KasbonService::registerMiner([
                'full_name'    => $input['full_name'],
                'phone_number' => $input['phone_number'],
                'location'     => $input['location'],
            ]);
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            Logger::warning('Pendaftaran petani ditolak', [
                'user_id' => self::actorId() ?? '',
                'alasan'  => $exception->getMessage(),
            ]);

            $this->renderKasbon($exception->getMessage(), $input, 422);

            return;
        }

        $this->flash(
            'success',
            sprintf(
                'Petani %s terdaftar. Pilih nominal kasbon atau angsuran untuknya.',
                (string) $miner['full_name']
            )
        );

        $this->redirect(self::ROUTE . '?miner=' . rawurlencode((string) $miner['id']));
    }

    // -----------------------------------------------------------------------
    // Penjaga akses
    // -----------------------------------------------------------------------

    /**
     * Peran + CSRF (pemeriksaan kedua di lapisan controller).
     */
    private function guard(): void
    {
        $this->requireRole(...self::ALLOWED_ROLES);

        $token = $_POST[Security::CSRF_FIELD] ?? null;

        if (!is_string($token) || !Security::verifyCsrf($token)) {
            self::abort(
                419,
                'Sesi kedaluwarsa',
                'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.'
            );
        }
    }

    /**
     * Penjaga mutasi kas: tambah token sekali-pakai `_once` (anti double-submit).
     */
    private function guardOnce(string $duplicateMessage): void
    {
        $this->guard();

        $once = $_POST[Security::FORM_FIELD] ?? null;
        $status = Security::consumeFormToken(is_string($once) ? $once : null);

        if ($status === Security::FORM_DUPLICATE) {
            Logger::warning('Permintaan kasbon ganda diabaikan', [
                'user_id' => self::actorId() ?? '',
            ]);

            $this->flash('warning', $duplicateMessage);

            $this->redirect(self::ROUTE);
        }

        if ($status !== Security::FORM_OK) {
            self::abort(
                419,
                'Sesi kedaluwarsa',
                'Token konfirmasi tidak valid atau sudah dipakai. Muat ulang halaman lalu coba lagi.'
            );
        }
    }

    // -----------------------------------------------------------------------
    // Render
    // -----------------------------------------------------------------------

    /**
     * Render layar Buku Kasbon beserta seluruh widget-nya.
     *
     * @param array<string, string> $input isian form yang dipertahankan pada 422
     * @param string $selectedMinerId id petani terpilih (`?miner=`), boleh kosong
     */
    private function renderKasbon(
        ?string $error,
        array $input,
        int $status = 200,
        string $selectedMinerId = ''
    ): void {
        $this->requireRole(...self::ALLOWED_ROLES);

        if ($status !== 200 && !headers_sent()) {
            http_response_code($status);
        }

        $model = KasbonService::viewModel($selectedMinerId);

        // Petani terpilih mengisi kedua form (kasbon baru & angsuran) supaya
        // kasir tidak memilih dua kali untuk satu penambang.
        if ($selectedMinerId !== '' && $selectedMinerId === $model['selectedMiner']['id']) {
            if ($input['miner_id'] === '') {
                $input['miner_id'] = $model['selectedMiner']['id'];
            }

            if ($input['miner_label'] === '') {
                $input['miner_label'] = $model['selectedMiner']['label'];
            }

            if ($input['pay_miner_id'] === '') {
                $input['pay_miner_id'] = $model['selectedMiner']['id'];
            }

            if ($input['pay_miner_label'] === '') {
                $input['pay_miner_label'] = $model['selectedMiner']['label'];
            }
        }

        $this->render(self::VIEW, [
            'title'        => 'Buku Kasbon',
            'usage'        => $model['usage'],
            'minerOptions' => $model['minerOptions'],
            'borrowers'    => $model['borrowers'],
            'ledger'       => $model['ledger'],
            'quickPcts'    => $model['quickPcts'],
            'methods'      => $model['methods'],
            'formToken'    => Security::issueFormToken(),
            'input'        => $input,
            'error'        => $error,
        ], self::LAYOUT);
    }

    // -----------------------------------------------------------------------
    // Isian form
    // -----------------------------------------------------------------------

    /**
     * Isian awal form (dipakai juga sebagai daftar field yang dibaca).
     *
     * @return array<string, string>
     */
    private static function emptyInput(): array
    {
        return [
            // Kartu "Kasbon Baru"
            'miner_id'         => '',
            'miner_label'      => '',
            'principal_amount' => '',
            'notes'            => '',
            // Kartu "Bayar Kasbon"
            'pay_miner_id'     => '',
            'pay_miner_label'  => '',
            'amount'           => '',
            'payment_method'   => KasbonService::METHOD_CASH,
            'pay_notes'        => '',
            // Kartu "Daftarkan Petani Baru"
            'full_name'        => '',
            'phone_number'     => '',
            'location'         => '',
        ];
    }

    /**
     * Baca isian form. Tidak ada nominal hasil hitung, id pinjaman, maupun
     * tanggal bisnis yang diterima dari klien.
     *
     * @return array<string, string>
     */
    private static function readInput(): array
    {
        $input = self::emptyInput();

        foreach ($input as $field => $default) {
            $value = $_POST[$field] ?? null;
            $input[$field] = is_string($value) ? trim($value) : $default;
        }

        return $input;
    }

    /**
     * `users.id` sesi untuk jejak audit (`issued_by`/`recorded_by`).
     */
    private static function actorId(): ?string
    {
        $id = $_SESSION['user_id'] ?? null;

        return is_string($id) && Security::isUuid($id) ? $id : null;
    }
}
