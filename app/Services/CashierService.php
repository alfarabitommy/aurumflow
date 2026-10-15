<?php

declare(strict_types=1);

namespace AurumFlow\Services;

use AurumFlow\Config\Constants;
use AurumFlow\Config\Database;
use AurumFlow\Helpers\Formatter;
use AurumFlow\Helpers\Logger;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\User;

/**
 * Tata kelola akun kasir meja bakar (Fase 7) — use-case, bukan helper murni.
 *
 * Tiga aksi operator: **buat** akun kasir (+ PIN awal), **reset PIN**, dan
 * **aktif/nonaktifkan**. Aturan yang dipegang kelas ini:
 *
 *   1. **Kredensial PIN satu pintu.** PIN selalu di-hash `User::createWithPin()`/
 *      `User::updatePin()` (ber-pepper). Nilai teksnya tidak pernah dikembalikan,
 *      di-log, atau ditampilkan.
 *   2. **Kebijakan PIN dipakai ulang.** `OnboardingService::pinPolicy()` tetap
 *      satu pemilik aturan PIN lemah — tidak ada daftar larangan kedua di sini.
 *   3. **Akun kasir bukan mitra modal.** Tidak ada pemanggilan `CapitalWallet`
 *      di jalur ini: kasir tidak punya dompet dan tidak pernah menerima bagi
 *      hasil (dijaga assertion `qc_fase_7` A.8).
 *   4. **Satu perintah, satu transaksi.** Setiap aksi menulis lewat
 *      `Database::transaction()` sehingga kegagalan di tengah tidak meninggalkan
 *      akun setengah jadi.
 *   5. **Gagal-tertutup pada pepper.** `APP_PIN_PEPPER` kosong membuat
 *      `Security::hashPin()` melempar `RuntimeException` **sebelum** baris apa
 *      pun tersimpan.
 *
 * Pesan `InvalidArgumentException` siap tampil di layar; pesan teknis hanya
 * masuk log.
 */
final class CashierService
{
    /** Batas panjang nama kasir. */
    public const NAME_MIN_LENGTH = 3;

    private const NAME_MAX_LENGTH = 100;

    private function __construct()
    {
    }

    /**
     * Buat akun kasir baru beserta PIN awalnya.
     *
     * @param array<string, mixed> $input kunci: full_name, phone_number, pin, pin_confirm
     * @return array{user_id: string, full_name: string, phone_number: string}
     *
     * @throws \InvalidArgumentException pesan siap tampil
     * @throws \RuntimeException kegagalan lapisan sistem (mis. pepper kosong)
     */
    public static function create(array $input): array
    {
        $fullName = self::validName($input['full_name'] ?? null);
        $phoneNumber = self::validPhone($input['phone_number'] ?? null);
        $pin = self::stringValue($input['pin'] ?? null);
        $pinConfirm = self::stringValue($input['pin_confirm'] ?? null);

        OnboardingService::pinPolicy($pin, $pinConfirm);

        if (User::findByPhoneNumber($phoneNumber, false) !== null) {
            throw new \InvalidArgumentException(
                'Nomor telepon sudah terdaftar pada akun lain. Gunakan nomor yang berbeda.'
            );
        }

        /** @var array{user_id: string, full_name: string, phone_number: string} $result */
        $result = Database::transaction(static function () use (
            $fullName,
            $phoneNumber,
            $pin
        ): array {
            try {
                $userId = User::createWithPin($fullName, $phoneNumber, $pin, Constants::ROLE_CASHIER);
            } catch (\PDOException $exception) {
                // Balapan dua submit dengan nomor yang sama: UNIQUE uq_users_phone
                // menolak yang kedua. Pesan tetap ramah, nol baris tersimpan.
                throw new \InvalidArgumentException(
                    'Nomor telepon sudah terdaftar pada akun lain. Gunakan nomor yang berbeda.',
                    0,
                    $exception
                );
            }

            return [
                'user_id'      => $userId,
                'full_name'    => $fullName,
                'phone_number' => $phoneNumber,
            ];
        });

        Logger::info('Akun kasir dibuat', [
            'user_id'      => $result['user_id'],
            'phone_number' => $result['phone_number'],
            'oleh'         => (string) ($_SESSION['user_id'] ?? ''),
        ]);

        return $result;
    }

    /**
     * Reset PIN seorang kasir (PIN lama hangus, PIN baru langsung berlaku).
     *
     * @param array<string, mixed> $input kunci: user_id, pin, pin_confirm
     * @return array{user_id: string, full_name: string, phone_number: string}
     *
     * @throws \InvalidArgumentException pesan siap tampil
     * @throws \RuntimeException kegagalan lapisan sistem (mis. pepper kosong)
     */
    public static function resetPin(array $input): array
    {
        $target = self::findCashier($input['user_id'] ?? null);

        $pin = self::stringValue($input['pin'] ?? null);
        $pinConfirm = self::stringValue($input['pin_confirm'] ?? null);

        OnboardingService::pinPolicy($pin, $pinConfirm);

        Database::transaction(static function () use ($target, $pin): void {
            User::updatePin((string) $target['id'], $pin);
        });

        Logger::info('PIN kasir direset', [
            'user_id' => (string) $target['id'],
            'oleh'    => (string) ($_SESSION['user_id'] ?? ''),
        ]);

        return [
            'user_id'      => (string) $target['id'],
            'full_name'    => (string) $target['full_name'],
            'phone_number' => (string) $target['phone_number'],
        ];
    }

    /**
     * Aktifkan / nonaktifkan akun kasir.
     *
     * Akun nonaktif tidak lagi ditemukan `User::findByPhoneNumber()` sehingga
     * tidak dapat masuk lewat pintu mana pun.
     *
     * @param array<string, mixed> $input kunci: user_id, active
     * @return array{user_id: string, full_name: string, active: bool}
     *
     * @throws \InvalidArgumentException pesan siap tampil
     */
    public static function setStatus(array $input): array
    {
        $target = self::findCashier($input['user_id'] ?? null);

        $rawActive = self::stringValue($input['active'] ?? null);

        // Nilai enum dari form harus eksplisit: isian kosong/asing tidak boleh
        // diam-diam diartikan "aktifkan kembali".
        if ($rawActive !== '0' && $rawActive !== '1') {
            throw new \InvalidArgumentException('Status akun tidak valid.');
        }

        $active = $rawActive === '1';

        Database::transaction(static function () use ($target, $active): void {
            User::setActive((string) $target['id'], $active);
        });

        Logger::info('Status akun kasir diubah', [
            'user_id' => (string) $target['id'],
            'aktif'   => $active ? 'ya' : 'tidak',
            'oleh'    => (string) ($_SESSION['user_id'] ?? ''),
        ]);

        return [
            'user_id'   => (string) $target['id'],
            'full_name' => (string) $target['full_name'],
            'active'    => $active,
        ];
    }

    /**
     * Daftar kasir untuk layar Kelola Kasir.
     *
     * View-model: pemformatan dilakukan di sini (satu pemilik angka/tanggal
     * lewat `Formatter`), bukan di controller maupun view.
     *
     * @return list<array{id: string, name: string, phone: string, is_active: bool,
     *                    status_label: string, updated_label: string}>
     */
    public static function listCashiers(): array
    {
        $rows = [];

        foreach (User::listByRole(Constants::ROLE_CASHIER) as $user) {
            $active = (int) ($user['is_active'] ?? 0) === 1;

            $rows[] = [
                'id'            => (string) ($user['id'] ?? ''),
                'name'          => (string) ($user['full_name'] ?? ''),
                'phone'         => (string) ($user['phone_number'] ?? ''),
                'is_active'     => $active,
                'status_label'  => $active ? 'Aktif' : 'Nonaktif',
                'updated_label' => Formatter::tanggalId((string) ($user['updated_at'] ?? ''), true),
            ];
        }

        return $rows;
    }

    // -----------------------------------------------------------------------
    // Validasi
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    private static function findCashier(mixed $userId): array
    {
        $id = self::stringValue($userId);

        if (!Security::isUuid($id)) {
            throw new \InvalidArgumentException('Akun kasir tidak ditemukan.');
        }

        // Peran ikut dicocokkan: id kiriman formulir tidak boleh cukup untuk
        // menyentuh akun operator atau mitra.
        $user = User::findByIdAndRole($id, Constants::ROLE_CASHIER);

        if ($user === null) {
            throw new \InvalidArgumentException('Akun kasir tidak ditemukan.');
        }

        return $user;
    }

    /**
     * Nama kasir: tidak kosong, tanpa markup, panjang 3–100 karakter.
     *
     * @throws \InvalidArgumentException
     */
    private static function validName(mixed $value): string
    {
        $name = Security::sanitize(self::stringValue($value));

        if (strlen($name) < self::NAME_MIN_LENGTH || strlen($name) > self::NAME_MAX_LENGTH) {
            throw new \InvalidArgumentException(
                'Nama kasir wajib diisi ' . self::NAME_MIN_LENGTH . '–' . self::NAME_MAX_LENGTH . ' karakter.'
            );
        }

        return $name;
    }

    /**
     * Nomor telepon mengikuti aturan yang sama dengan akun operator pada
     * `database/install.php`: angka, boleh diawali `+`/`0`, 8–20 karakter.
     *
     * @throws \InvalidArgumentException
     */
    private static function validPhone(mixed $value): string
    {
        $phone = self::stringValue($value);

        if (preg_match('/^[0-9+\-\s]{8,20}$/', $phone) !== 1) {
            throw new \InvalidArgumentException(
                'Nomor telepon tidak valid. Gunakan angka (boleh diawali + atau 0), panjang 8–20 karakter.'
            );
        }

        return $phone;
    }

    private static function stringValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
