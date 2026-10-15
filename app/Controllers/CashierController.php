<?php

declare(strict_types=1);

namespace AurumFlow\Controllers;

use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Logger;
use AurumFlow\Helpers\Security;
use AurumFlow\Services\CashierService;

/**
 * Layar "Kelola Kasir" — tata kelola akun kasir meja bakar (Fase 7).
 *
 * Aturan yang dipegang controller ini:
 *   1. **Hanya peran `operator`.** `requireRole(Constants::ROLE_OPERATOR)`
 *      adalah pernyataan pertama setiap method — kasir yang mencoba membuka
 *      layar ini (atau mengirim formnya) dijawab `302` ke beranda perannya
 *      **tanpa satu pun tulisan** dan tanpa mengonsumsi token `_once`.
 *   2. **CSRF dua lapis + `_once`.** Front controller memeriksa CSRF; di sini
 *      diperiksa sekali lagi, ditambah token sekali-pakai karena ini mutasi
 *      identitas (bukan sekadar mutasi kas).
 *   3. **PIN tidak pernah kembali.** Hasil aksi hanya menyebut nama/nomor;
 *      hash PIN tidak pernah dibaca view (`User::listByRole()` sengaja tidak
 *      memilih `password_hash`).
 *   4. **Gerbang peran pada target.** `CashierService` mencocokkan `role` saat
 *      memuat akun sasaran, sehingga id kiriman formulir tidak dapat dipakai
 *      menyentuh akun operator/mitra.
 *   5. Sukses memakai POST-Redirect-GET; kegagalan validasi mengembalikan `422`
 *      beserta isian form yang masih terpakai.
 */
final class CashierController extends BaseController
{
    private const VIEW = 'operator/kasir';

    private const LAYOUT = 'operator';

    private const ROUTE = '/operator/kasir';

    private const PIN_RESET_LENGTH = 6;

    /**
     * Layar Kelola Kasir (GET).
     */
    public function index(): void
    {
        $this->renderCashiers(null, self::emptyInput());
    }

    /**
     * Buat akun kasir baru (POST).
     */
    public function store(): void
    {
        $this->guardOnce('Permintaan ganda diabaikan — tidak ada akun baru.');

        $input = self::readInput();

        $this->execute(
            static fn (): array => CashierService::create([
                'full_name'    => $input['cashier_name'],
                'phone_number' => $input['cashier_phone'],
                'pin'          => $input['cashier_pin'],
                'pin_confirm'  => $input['cashier_pin_confirm'],
            ]),
            $input,
            static fn (array $result): string => sprintf(
                'Akun kasir %s (%s) tersimpan. Sampaikan PIN awalnya secara langsung — '
                . 'PIN tidak dapat dilihat lagi dari layar ini.',
                (string) $result['full_name'],
                (string) $result['phone_number']
            )
        );
    }

    /**
     * Reset PIN kasir (POST).
     */
    public function resetPin(): void
    {
        $this->guardOnce('Permintaan ganda diabaikan — PIN tidak diubah.');

        $input = self::readInput();

        $this->execute(
            static fn (): array => CashierService::resetPin([
                'user_id'     => $input['pin_user_id'],
                'pin'         => $input['reset_pin'],
                'pin_confirm' => $input['reset_pin_confirm'],
            ]),
            $input,
            static fn (array $result): string => sprintf(
                'PIN kasir %s (%s) diganti. PIN lama tidak berlaku lagi.',
                (string) $result['full_name'],
                (string) $result['phone_number']
            )
        );
    }

    /**
     * Aktifkan / nonaktifkan akun kasir (POST).
     */
    public function setStatus(): void
    {
        $this->guardOnce('Permintaan ganda diabaikan — status tidak diubah.');

        $input = self::readInput();

        $this->execute(
            static fn (): array => CashierService::setStatus([
                'user_id' => $input['status_user_id'],
                'active'  => $input['status_active'],
            ]),
            $input,
            static fn (array $result): string => sprintf(
                'Akun kasir %s kini %s.',
                (string) $result['full_name'],
                $result['active'] ? 'AKTIF' : 'NONAKTIF (tidak dapat masuk)'
            )
        );
    }

    // -----------------------------------------------------------------------
    // Penjaga akses
    // -----------------------------------------------------------------------

    /**
     * Peran + CSRF (pemeriksaan kedua di lapisan controller).
     *
     * `requireRole()` sengaja menjadi pernyataan PERTAMA: permintaan dari sesi
     * kasir/mitra berhenti di sini, sebelum token `_once` miliknya dikonsumsi.
     */
    private function guard(): void
    {
        $this->requireRole(Constants::ROLE_OPERATOR);

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
     * Penjaga untuk mutasi identitas: tambah token sekali-pakai `_once`.
     */
    private function guardOnce(string $duplicateMessage): void
    {
        $this->guard();

        $once = $_POST[Security::FORM_FIELD] ?? null;
        $status = Security::consumeFormToken(is_string($once) ? $once : null);

        if ($status === Security::FORM_DUPLICATE) {
            Logger::warning('Permintaan kelola kasir ganda diabaikan', [
                'user_id' => (string) ($_SESSION['user_id'] ?? ''),
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
    // Eksekusi & render
    // -----------------------------------------------------------------------

    /**
     * Jalankan satu aksi tata kelola, lalu PRG (sukses) atau 422 + isian (gagal).
     *
     * @param callable():array<string, mixed> $action
     * @param array<string, string> $input
     * @param callable(array<string, mixed>):string $message
     */
    private function execute(callable $action, array $input, callable $message): void
    {
        try {
            $result = $action();
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            if ($exception instanceof \RuntimeException) {
                Logger::error('Tata kelola kasir gagal pada lapisan sistem', [
                    'user_id' => (string) ($_SESSION['user_id'] ?? ''),
                    'alasan'  => $exception->getMessage(),
                ]);
            }

            $this->renderCashiers($exception->getMessage(), $input, 422);

            return;
        }

        $this->flash('success', $message($result));
        $this->redirect(self::ROUTE);
    }

    /**
     * Render layar Kelola Kasir.
     *
     * @param array<string, string> $input
     */
    private function renderCashiers(?string $error, array $input, int $status = 200): void
    {
        $this->requireRole(Constants::ROLE_OPERATOR);

        if ($status !== 200 && !headers_sent()) {
            http_response_code($status);
        }

        $this->render(self::VIEW, [
            'title'       => 'Kelola Kasir',
            'rows'        => CashierService::listCashiers(),
            'formToken'   => Security::issueFormToken(),
            'pinLength'   => self::PIN_RESET_LENGTH,
            'input'       => $input,
            'error'       => $error,
        ], self::LAYOUT);
    }

    /**
     * Isian awal form.
     *
     * @return array<string, string>
     */
    private static function emptyInput(): array
    {
        return [
            'cashier_name'         => '',
            'cashier_phone'        => '',
            'cashier_pin'          => '',
            'cashier_pin_confirm'  => '',
            'pin_user_id'          => '',
            'reset_pin'            => '',
            'reset_pin_confirm'    => '',
            'status_user_id'       => '',
            'status_active'        => '',
        ];
    }

    /**
     * Baca isian form.
     *
     * Yang dibaca hanya data akun & PIN; tidak ada peran, id dompet, atau
     * nominal uang yang datang dari klien (peran selalu `cashier`, nomor target
     * selalu divalidasi `CashierService`).
     *
     * @return array<string, string>
     */
    private static function readInput(): array
    {
        $empty = self::emptyInput();

        foreach ($empty as $field => $default) {
            $value = $_POST[$field] ?? null;
            $empty[$field] = is_string($value) ? trim($value) : $default;
        }

        return $empty;
    }
}
