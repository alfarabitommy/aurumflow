<?php

declare(strict_types=1);

namespace AurumFlow\Controllers;

use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Security;
use AurumFlow\Services\OfficerPortalService;

/**
 * Portal blind mitra lapangan (Fase 10) — BACA-SAJA.
 *
 * Dua halaman, keduanya `GET` dan hanya untuk peran `officer`:
 *   - `/officer/dashboard` (Layar H): identitas ringkas, saldo hak, ringkasan
 *     bulan berjalan, dan 50 entri terakhir.
 *   - `/officer/riwayat`: riwayat buku hak selengkapnya.
 *
 * Aturan yang dipegang controller ini:
 *   1. **Guard-first.** `requireRole(Constants::ROLE_OFFICER)` adalah pernyataan
 *      pertama setiap method; peran lain dijawab `302` ke beranda perannya.
 *   2. **Tidak ada POST.** Portal ini tidak pernah mengubah apa pun, sehingga
 *      tidak ada CSRF/`_once` yang perlu diperiksa — dan tidak ada jalan bagi
 *      petugas untuk memengaruhi saldonya sendiri.
 *   3. **Identitas dari session.** `users.id` selalu dibaca dari session
 *      (bukan form/parameter URL), sehingga satu petugas tidak mungkin membuka
 *      buku hak petugas lain dengan menebak id.
 *   4. **Angka milik pemilik sistem tidak pernah dirender** — seluruh data
 *      berasal dari `OfficerPortalService`, yang hanya mengembalikan saldo hak,
 *      ringkasan bulan berjalan, dan riwayat entri (tanpa tarif/basis).
 */
final class OfficerPortalController extends BaseController
{
    private const LAYOUT = 'officer';

    /**
     * Dasbor blind petugas (Layar H).
     */
    public function index(): void
    {
        $this->requireRole(Constants::ROLE_OFFICER);

        $userId = self::sessionUserId();
        $summary = OfficerPortalService::summary($userId);

        $this->render('officer/dashboard', [
            'title'    => 'Dasbor Mitra Lapangan',
            'identity' => OfficerPortalService::identity($userId),
            'summary'  => $summary,
            'entries'  => OfficerPortalService::history($userId, OfficerPortalService::DASHBOARD_LIMIT),
        ], self::LAYOUT);
    }

    /**
     * Riwayat buku hak (halaman penuh).
     */
    public function history(): void
    {
        $this->requireRole(Constants::ROLE_OFFICER);

        $userId = self::sessionUserId();

        $this->render('officer/riwayat', [
            'title'    => 'Riwayat Hak Saya',
            'identity' => OfficerPortalService::identity($userId),
            'summary'  => OfficerPortalService::summary($userId),
            'entries'  => OfficerPortalService::history($userId, OfficerPortalService::HISTORY_LIMIT),
        ], self::LAYOUT);
    }

    /**
     * `users.id` sesi — satu-satunya sumber identitas portal.
     *
     * Bila sesi rusak (bukan UUID), kembalikan string kosong: layanan portal
     * akan mengembalikan ringkasan kosong, sehingga tidak ada data petugas lain
     * yang mungkin tampil.
     */
    private static function sessionUserId(): string
    {
        $value = $_SESSION['user_id'] ?? '';

        return is_string($value) && Security::isUuid($value) ? $value : '';
    }
}
