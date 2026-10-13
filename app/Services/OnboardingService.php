<?php

declare(strict_types=1);

namespace AurumFlow\Services;

use AurumFlow\Config\Database;
use AurumFlow\Helpers\Logger;
use AurumFlow\Helpers\Security;
use AurumFlow\Models\User;

/**
 * Onboarding mitra: klaim tautan undangan, kebijakan PIN, teks waiver (Fase 5).
 *
 * Mengapa kelas ini ada: seluruh aturan "siapa yang boleh menjadi mitra dan
 * bagaimana ia membuktikan dirinya" hidup di satu tempat — bukan disebar ke
 * controller dan view. Yang dipegang di sini:
 *
 *   1. **Teks Pakta Integritas punya satu pemilik** (`waiverClause()`), dipakai
 *      halaman onboarding dan diuji QC; PRD § 5.1 adalah sumber bunyinya.
 *   2. **Token undangan sekali pakai.** Klaim berjalan di dalam satu
 *      `Database::transaction()`, baris `users` dikunci lebih dulu, dan token
 *      di-NULL-kan bersamaan dengan penulisan jejak audit + hash PIN. Dua
 *      peramban yang membuka tautan sama: hanya satu yang berhasil.
 *   3. **Jejak audit dari peladen.** Waktu memakai jam peladen (WIB — sesi
 *      MySQL sudah di-set `App::utcOffset()`), IP dari `REMOTE_ADDR`, dan
 *      User-Agent dari header — tidak ada satu pun nilai dari formulir.
 *   4. **PIN lemah ditolak** (`pinPolicy()`), dan PIN di-hash ber-pepper
 *      (`Security::hashPin()`), sehingga tidak pernah sah di pintu kata sandi.
 */
final class OnboardingService
{
    /**
     * Versi teks Pakta Integritas.
     *
     * Naikkan bila bunyi klausul berubah. Nilai ini **belum** disimpan per
     * mitra (skema tidak punya kolomnya dan Fase 5 nol-DDL) — lihat
     * `plan/Plan_Fase_5_AurumFlow.md` O-1.
     */
    public const WAIVER_VERSION = '1.0.0';

    /** Token undangan: 64 heksadesimal (`Security::randomToken(32)`). */
    public const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

    /** Status undangan untuk layar operator. */
    public const STATE_NONE = 'none';

    public const STATE_PENDING = 'pending';

    public const STATE_ACTIVE = 'active';

    /** Pesan tunggal untuk tautan yang tidak dikenal / sudah dipakai. */
    public const INVALID_LINK_MESSAGE = 'Tautan undangan tidak berlaku atau sudah pernah dipakai. '
        . 'Hubungi pengelola untuk meminta tautan baru.';

    private const STATE_LABELS = [
        self::STATE_NONE    => 'Belum diundang',
        self::STATE_PENDING => 'Menunggu onboarding',
        self::STATE_ACTIVE  => 'Aktif',
    ];

    private function __construct()
    {
    }

    // -----------------------------------------------------------------------
    // Teks hukum
    // -----------------------------------------------------------------------

    /**
     * Klausul baku perlindungan pengelola (PRD § 5.1) — satu-satunya salinan
     * yang dipakai aplikasi.
     */
    public static function waiverClause(): string
    {
        return 'Saya memahami dan menyetujui sepenuhnya bahwa penyertaan modal ini '
            . 'dialokasikan pada operasional perdagangan dan pengolahan komoditas emas '
            . 'aluvial di sektor informal dengan risiko lapangan nyata (termasuk namun '
            . 'tidak terbatas pada risiko kahar/force majeure, razia/penyitaan jalur '
            . 'logistik, dan fluktuasi taksiran kadar). Saya membebaskan Pengelola dari '
            . 'segala bentuk tuntutan ganti rugi perdata maupun pidana atas kerugian '
            . 'yang timbul di lapangan di luar tindakan penipuan atau penggelapan yang '
            . 'terbukti secara hukum.';
    }

    // -----------------------------------------------------------------------
    // Undangan
    // -----------------------------------------------------------------------

    /**
     * Cari calon mitra dari token undangan; `null` bila tautan tidak berlaku.
     *
     * Pola token diperiksa lebih dulu sehingga token sampah tidak pernah
     * menyentuh basis data.
     *
     * @return array<string, mixed>|null
     */
    public static function findInvitee(string $token): ?array
    {
        $token = trim($token);

        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        $user = User::findByInviteToken($token);

        if ($user === null || (int) ($user['is_active'] ?? 0) !== 1) {
            return null;
        }

        // Tautan hanya berlaku sampai tanda tangan pertama.
        if (trim((string) ($user['onboarding_signed_at'] ?? '')) !== '') {
            return null;
        }

        return $user;
    }

    /**
     * Status onboarding seorang mitra (dipakai kartu undangan layar operator).
     *
     * @param array<string, mixed> $user baris `capital_wallets` ⋈ `users`
     */
    public static function effectiveInviteState(array $user): string
    {
        if (trim((string) ($user['onboarding_signed_at'] ?? '')) !== '') {
            return self::STATE_ACTIVE;
        }

        if (trim((string) ($user['invite_token'] ?? '')) !== '') {
            return self::STATE_PENDING;
        }

        return self::STATE_NONE;
    }

    public static function inviteStateLabel(string $state): string
    {
        return self::STATE_LABELS[$state] ?? $state;
    }

    /**
     * Klaim token undangan: tulis PIN + jejak audit, lalu hanguskan token.
     *
     * @param array<string, mixed> $input kunci: accepted, pin, pin_confirm
     * @return array<string, mixed> ringkasan untuk flash
     *
     * @throws \InvalidArgumentException pesan siap tampil
     * @throws \RuntimeException `APP_PIN_PEPPER` belum dikonfigurasi
     */
    public static function claim(string $token, array $input): array
    {
        $token = trim($token);

        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new \InvalidArgumentException(self::INVALID_LINK_MESSAGE);
        }

        if (!self::isAccepted($input['accepted'] ?? null)) {
            throw new \InvalidArgumentException(
                'Centang pernyataan Pakta Integritas & Pembebasan Risiko sebelum melanjutkan.'
            );
        }

        $pin = self::stringValue($input['pin'] ?? null);
        self::pinPolicy($pin, self::stringValue($input['pin_confirm'] ?? null));

        $ipAddress = User::clientIp();
        $userAgent = User::userAgent();

        /** @var array<string, mixed> $result */
        $result = Database::transaction(static function () use ($token, $pin, $ipAddress, $userAgent): array {
            $user = User::lockByInviteTokenForUpdate($token);

            if ($user === null
                || (int) ($user['is_active'] ?? 0) !== 1
                || trim((string) ($user['onboarding_signed_at'] ?? '')) !== ''
            ) {
                throw new \InvalidArgumentException(self::INVALID_LINK_MESSAGE);
            }

            User::completeOnboarding((string) $user['id'], $pin, $ipAddress, $userAgent);

            return [
                'user_id'      => (string) $user['id'],
                'full_name'    => (string) $user['full_name'],
                'phone_number' => (string) $user['phone_number'],
                'signed_at'    => date('Y-m-d H:i:s'),
            ];
        });

        Logger::info('Onboarding mitra selesai', [
            'user_id' => $result['user_id'],
            'ip'      => $ipAddress,
        ]);

        return $result;
    }

    // -----------------------------------------------------------------------
    // Kebijakan PIN
    // -----------------------------------------------------------------------

    /**
     * Aturan PIN: tepat enam digit, konfirmasi sama, dan bukan pola lemah.
     *
     * Ditolak (ruang PIN hanya 10⁶, sehingga tebakan pertama penyerang selalu
     * pola-pola ini): seluruh digit sama, barisan naik/turun lurus, dan blok
     * tiga digit yang diulang.
     *
     * @throws \InvalidArgumentException pesan siap tampil
     */
    public static function pinPolicy(string $pin, string $confirm): void
    {
        if (preg_match('/^[0-9]{' . Security::PIN_LENGTH . '}$/', $pin) !== 1) {
            throw new \InvalidArgumentException(
                'PIN harus tepat ' . Security::PIN_LENGTH . ' angka (contoh: 246810).'
            );
        }

        if ($confirm !== $pin) {
            throw new \InvalidArgumentException('Konfirmasi PIN tidak sama dengan PIN yang diisi.');
        }

        if (self::isWeakPin($pin)) {
            throw new \InvalidArgumentException(
                'PIN terlalu mudah ditebak. Hindari angka yang berulang sama '
                . '(111111), barisan berurutan (123456), atau pola berulang (123123).'
            );
        }
    }

    /**
     * Pola PIN lemah. Dipisah supaya aturannya dapat dibaca sebagai satu daftar.
     */
    private static function isWeakPin(string $pin): bool
    {
        // Seluruh digit sama: 000000 … 999999.
        if (preg_match('/^(\d)\1{5}$/', $pin) === 1) {
            return true;
        }

        $digits = array_map('intval', str_split($pin));

        // Barisan naik/turun lurus: 123456, 654321, 012345, 543210.
        $ascending = true;
        $descending = true;

        for ($i = 1; $i < count($digits); $i++) {
            if ($digits[$i] !== $digits[$i - 1] + 1) {
                $ascending = false;
            }

            if ($digits[$i] !== $digits[$i - 1] - 1) {
                $descending = false;
            }
        }

        if ($ascending || $descending) {
            return true;
        }

        // Blok tiga digit yang diulang: 123123, 456456.
        return substr($pin, 0, 3) === substr($pin, 3, 3);
    }

    // -----------------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------------

    /**
     * Kotak centang hanya sah bila hadir dengan nilai yang dikenal peramban.
     * Tidak ada nilai dari formulir yang dipercaya lebih jauh dari ini.
     */
    private static function isAccepted(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'on', 'yes', 'true'], true);
    }

    private static function stringValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
