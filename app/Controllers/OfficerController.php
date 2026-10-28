<?php

declare(strict_types=1);

namespace AurumFlow\Controllers;

use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Formatter;
use AurumFlow\Helpers\Logger;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\CashRegister;
use AurumFlow\Services\OfficerService;

/**
 * Layar "Kelola Mitra Lapangan" — tata kelola petugas & buku haknya (Fase 10).
 *
 * Aturan yang dipegang controller ini:
 *   1. **Hanya peran `operator`.** `requireRole(Constants::ROLE_OPERATOR)` adalah
 *      pernyataan PERTAMA setiap method — kasir, mitra, maupun petugas yang
 *      mencoba membuka/mengirim layar ini dijawab `302` ke beranda perannya
 *      **tanpa satu pun tulisan** dan tanpa mengonsumsi token `_once`.
 *   2. **CSRF dua lapis + `_once` + PRG.** Setiap mutasi (akun, tarif, PIN,
 *      status, bonus, pencairan) memakai token sekali-pakai; satu halaman =
 *      satu token aktif, sehingga setiap mutasi diikuti redirect.
 *   3. **Nominal dari klien tidak dipercaya sebagai hasil hitung.** Form hanya
 *      mengirim pemilih petugas, nominal, token pos kas, dan catatan; saldo,
 *      potongan, dan `balance_after` dihitung `OfficerService`.
 *   4. **PIN tidak pernah kembali.** Hasil aksi hanya menyebut nama/nomor.
 *   5. Kegagalan validasi mengembalikan `422` beserta isian yang masih terpakai.
 */
final class OfficerController extends BaseController
{
    private const VIEW = 'operator/petugas';

    private const LAYOUT = 'operator';

    private const ROUTE = '/operator/petugas';

    private const PIN_LENGTH = 6;

    /**
     * Layar Kelola Mitra Lapangan (GET).
     */
    public function index(): void
    {
        $this->renderOfficers(null, self::emptyInput());
    }

    /**
     * Buat akun petugas + akun bukunya (POST).
     */
    public function store(): void
    {
        $this->guardOnce('Permintaan ganda diabaikan — tidak ada akun baru.');

        $input = self::readInput();

        $this->execute(
            static fn (): array => OfficerService::create([
                'full_name'    => $input['officer_name'],
                'phone_number' => $input['officer_phone'],
                'pin'          => $input['officer_pin'],
                'pin_confirm'  => $input['officer_pin_confirm'],
                'cut_pct'      => $input['officer_cut_pct'],
            ]),
            $input,
            static fn (array $result): string => sprintf(
                'Mitra lapangan %s (%s) tersimpan dengan potongan %s. Sampaikan PIN awalnya '
                . 'secara langsung — PIN tidak dapat dilihat lagi dari layar ini.',
                (string) $result['full_name'],
                (string) $result['phone_number'],
                Formatter::persen((string) $result['cut_pct'])
            )
        );
    }

    /**
     * Ubah tarif potongan (POST).
     */
    public function updateCut(): void
    {
        $this->guardOnce('Permintaan ganda diabaikan — tarif tidak diubah.');

        $input = self::readInput();

        $this->execute(
            static fn (): array => OfficerService::updateCutPct([
                'officer_id' => $input['cut_officer_id'],
                'cut_pct'    => $input['cut_pct'],
            ]),
            $input,
            static fn (array $result): string => sprintf(
                'Tarif potongan %s kini %s.',
                (string) $result['full_name'],
                Formatter::persen((string) $result['cut_pct'])
            )
        );
    }

    /**
     * Reset PIN petugas (POST).
     */
    public function resetPin(): void
    {
        $this->guardOnce('Permintaan ganda diabaikan — PIN tidak diubah.');

        $input = self::readInput();

        $this->execute(
            static fn (): array => OfficerService::resetPin([
                'user_id'     => $input['pin_user_id'],
                'pin'         => $input['reset_pin'],
                'pin_confirm' => $input['reset_pin_confirm'],
            ]),
            $input,
            static fn (array $result): string => sprintf(
                'PIN %s (%s) diganti. PIN lama tidak berlaku lagi.',
                (string) $result['full_name'],
                (string) $result['phone_number']
            )
        );
    }

    /**
     * Aktifkan / nonaktifkan akun petugas (POST).
     */
    public function setStatus(): void
    {
        $this->guardOnce('Permintaan ganda diabaikan — status tidak diubah.');

        $input = self::readInput();

        $this->execute(
            static fn (): array => OfficerService::setStatus([
                'user_id' => $input['status_user_id'],
                'active'  => $input['status_active'],
            ]),
            $input,
            static fn (array $result): string => sprintf(
                'Akun %s kini %s.%s',
                (string) $result['full_name'],
                $result['active'] ? 'AKTIF' : 'NONAKTIF (tidak dapat masuk)',
                $result['active'] ? '' : ' Saldo haknya tetap dapat dicairkan.'
            )
        );
    }

    /**
     * Suntik bonus manual (POST) — akrual, bukan pembayaran.
     */
    public function bonus(): void
    {
        $this->guardOnce('Permintaan ganda diabaikan — bonus tidak dicatat.');

        $input = self::readInput();

        $this->execute(
            static fn (): array => OfficerService::injectBonus([
                'officer_id'  => $input['bonus_officer_id'],
                'amount'      => $input['bonus_amount'],
                'notes'       => $input['bonus_notes'],
                'recorded_by' => self::actorId(),
            ]),
            $input,
            static fn (array $result): string => sprintf(
                'Bonus %s dicatat untuk %s. Saldo hak menjadi %s; bonus mengurangi laba bersih '
                . 'pada tutup buku berikutnya (sekali saja).',
                Formatter::rupiah((string) $result['amount']),
                (string) $result['full_name'],
                Formatter::rupiah((string) $result['balance_after'])
            )
        );
    }

    /**
     * Cairkan saldo hak petugas (POST) — kas keluar + saldo turun.
     */
    public function disburse(): void
    {
        $this->guardOnce('Permintaan ganda diabaikan — pencairan tidak diulang.');

        $input = self::readInput();

        $this->execute(
            static fn (): array => OfficerService::disburse([
                'officer_id'  => $input['disburse_officer_id'],
                'amount'      => $input['disburse_amount'],
                'pos_kas'     => $input['disburse_pos'],
                'notes'       => $input['disburse_notes'],
                'recorded_by' => self::actorId(),
            ]),
            $input,
            static fn (array $result): string => sprintf(
                'Hak %s dicairkan %s dari %s. Saldo hak tersisa %s.',
                (string) $result['full_name'],
                Formatter::rupiah((string) $result['amount']),
                (string) $result['register_name'],
                Formatter::rupiah((string) $result['balance_after'])
            )
        );
    }

    // -----------------------------------------------------------------------
    // Penjaga akses
    // -----------------------------------------------------------------------

    /**
     * Peran + CSRF (pemeriksaan kedua di lapisan controller).
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
     * Penjaga untuk mutasi: tambah token sekali-pakai `_once`.
     */
    private function guardOnce(string $duplicateMessage): void
    {
        $this->guard();

        $once = $_POST[Security::FORM_FIELD] ?? null;
        $status = Security::consumeFormToken(is_string($once) ? $once : null);

        if ($status === Security::FORM_DUPLICATE) {
            Logger::warning('Permintaan kelola mitra lapangan ganda diabaikan', [
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
     * Jalankan satu aksi, lalu PRG (sukses) atau 422 + isian (gagal).
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
                Logger::error('Tata kelola mitra lapangan gagal pada lapisan sistem', [
                    'user_id' => (string) ($_SESSION['user_id'] ?? ''),
                    'alasan'  => $exception->getMessage(),
                ]);
            }

            $this->renderOfficers($exception->getMessage(), $input, 422);

            return;
        }

        $this->flash('success', $message($result));
        $this->redirect(self::ROUTE);
    }

    /**
     * Render Layar G.
     *
     * @param array<string, string> $input
     */
    private function renderOfficers(?string $error, array $input, int $status = 200): void
    {
        $this->requireRole(Constants::ROLE_OPERATOR);

        if ($status !== 200 && !headers_sent()) {
            http_response_code($status);
        }

        $teller = CashRegister::activeTeller();
        $brankas = CashRegister::findBrankas();
        $overview = OfficerService::overview();

        $this->render(self::VIEW, [
            'title'      => 'Mitra Lapangan',
            'overview'   => $overview,
            'registers'  => [
                OfficerService::POS_TELLER => [
                    'label'       => CashRegister::TELLER_NAME,
                    'saldo_label' => Formatter::rupiah($teller['current_balance'] ?? 0),
                ],
                OfficerService::POS_BRANKAS => [
                    'label'       => CashRegister::BRANKAS_NAME,
                    'saldo_label' => Formatter::rupiah($brankas['current_balance'] ?? 0),
                ],
            ],
            'formToken'  => Security::issueFormToken(),
            'pinLength'  => self::PIN_LENGTH,
            'input'      => $input,
            'error'      => $error,
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
            'officer_name'         => '',
            'officer_phone'        => '',
            'officer_pin'          => '',
            'officer_pin_confirm'  => '',
            'officer_cut_pct'      => '',
            'cut_officer_id'       => '',
            'cut_pct'              => '',
            'pin_user_id'          => '',
            'reset_pin'            => '',
            'reset_pin_confirm'    => '',
            'status_user_id'       => '',
            'status_active'        => '',
            'bonus_officer_id'     => '',
            'bonus_amount'         => '',
            'bonus_notes'          => '',
            'disburse_officer_id'  => '',
            'disburse_amount'      => '',
            'disburse_pos'         => OfficerService::POS_TELLER,
            'disburse_notes'       => '',
        ];
    }

    /**
     * Baca isian form (tanpa peran, tanpa id dompet, tanpa nominal hasil hitung).
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

    /**
     * `users.id` sesi untuk jejak audit pencatatan.
     */
    private static function actorId(): string
    {
        $value = $_SESSION['user_id'] ?? '';

        return is_string($value) ? $value : '';
    }
}
