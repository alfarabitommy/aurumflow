<?php

declare(strict_types=1);

namespace AurumFlow\Controllers;

use AurumFlow\Config\Constants;
use AurumFlow\Helpers\Formatter;
use AurumFlow\Models\SystemSetting;
use AurumFlow\Models\User;

/**
 * Halaman setelah login.
 *
 * Pada Fase 1 dashboard sengaja hanya menjadi halaman asap (smoke page):
 * membuktikan bahwa koneksi database, session, model, view, dan formatter
 * benar-benar bekerja end-to-end. Modul bisnis masuk fase berikutnya.
 */
final class DashboardController extends BaseController
{
    private const ROLE_LABELS = [
        Constants::ROLE_OPERATOR => 'Pengelola (Operator)',
        Constants::ROLE_INVESTOR => 'Mitra Pemodal (Investor)',
    ];

    private const SETTING_LABELS = [
        'allocation_mode'                => 'Mode alokasi modal',
        'operator_base_quota_pct'        => 'Kuota serapan modal pengelola (%)',
        'default_burn_fee'               => 'Jasa bakar flat per kedatangan',
        'default_burn_fee_operator_cut'  => 'Porsi upah pengelola dari jasa bakar',
        'notice_period_days'             => 'Masa tunggu penarikan modal (hari)',
        'active_carryover_deficit'       => 'Akumulasi defisit berjalan',
    ];

    /**
     * Titik masuk `/`.
     */
    public function home(): void
    {
        if (isset($_SESSION['user_id'])) {
            $this->redirect('/dashboard');
        }

        $this->redirect('/login');
    }

    public function index(): void
    {
        $this->requireAuth();

        $role = (string) ($_SESSION['user_role'] ?? '');

        $burnFee = SystemSetting::get('default_burn_fee', '0.00');
        $operatorCut = SystemSetting::get('default_burn_fee_operator_cut', '0.00');

        $this->render('dashboard/index', [
            'title' => 'Dashboard',

            'user' => [
                'id'         => (string) ($_SESSION['user_id'] ?? ''),
                'name'       => (string) ($_SESSION['user_name'] ?? ''),
                'phone'      => (string) ($_SESSION['user_phone'] ?? ''),
                'role'       => $role,
                'role_label' => self::ROLE_LABELS[$role] ?? $role,
            ],

            'activeUserCount' => User::countActive(),

            // Tanggal operasi — konsep inti pada sistem bersettlement T+1.
            // Sekaligus pemakai nyata Formatter::tanggalId().
            'tanggalOperasi' => Formatter::tanggalId(date('Y-m-d')),

            'settings'       => SystemSetting::all(),
            'settingLabels'  => self::SETTING_LABELS,

            // Contoh pembacaan setting langsung (bukan lewat all()) sekaligus
            // bukti bahwa parameter bisnis memang dapat dibaca per kunci.
            'burnFee'        => Formatter::rupiah($burnFee),
            'operatorCut'    => Formatter::rupiah($operatorCut),

            // Bukti nyata bahwa Formatter bekerja, sekaligus contoh format
            // yang dibekukan pada dokumen rencana (O-3 dan O-4).
            'samples' => [
                'rupiah_besar'   => Formatter::rupiah(28937500),
                'rupiah_nol'     => Formatter::rupiah(0),
                'rupiah_negatif' => Formatter::rupiah(-5000),
                'rupiah_desimal' => Formatter::rupiah(1234.567, 2),
                'gram_normal'    => Formatter::gram(14.85),
                'gram_bulat'     => Formatter::gram(1000),
                'gram_negatif'   => Formatter::gram(-2.5),
            ],
        ]);
    }
}
