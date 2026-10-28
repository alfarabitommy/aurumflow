# Summary Fase 5 — AurumFlow

> **Status dokumen (pasca-Fase 9 — sinkronisasi dokumentasi).** Seluruh angka dan perilaku di
> bawah ini — termasuk (bila ada) penyebutan **13 tabel** atau kurang, **14 suite / 853
> assertion**, `CACHE_NAME` **v3**, dan isian **`coord_fee`** — adalah **potret fase tersebut**,
> bukan keadaan sekarang. Keadaan terkini: **14 tabel** (termasuk `operational_expenses`, nol `ALTER`),
> `CACHE_NAME` **`aurumflow-shell-v4`**, **15 suite / 900 assertion / 0 gagal**, beban operasional
> dicatat sebagai **voucher kas** di meja kasir lalu diserap otomatis oleh tutup buku
> (`total_cash = pendapatan`). Rujukan terkini: `plan/Summary_Fase_9_OPEX_AurumFlow.md`.


> **Laporan Eksekusi**
> Proyek: **AurumFlow** — sistem manajemen operasional privat untuk perdagangan emas aluvial dengan settlement **T+1**.
> Fase: **5 — Dasbor Blind Investor, Legal Waiver (Clickwrap), & PWA**
> Rencana: `plan/Plan_Fase_5_AurumFlow.md` (APPROVED · `dec-ef670a108317f925`)
> Status: **SELESAI** — `bash tests/run_qc.sh` **exit 0**, 11/11 suite, **598 assertion, 0 gagal**.

---

## 1. Hasil yang Dicapai

| Kriteria selesai (rencana § 1.2) | Bukti |
|---|---|
| `GET /join?token=…` menampilkan Layar A; token tak dikenal/sudah dipakai → 404 tanpa tulisan | `qc_fase_5` H.1–H.4, G.5, H.7 |
| `POST /join` = satu transaksi: hash PIN + jejak audit + token hangus | `qc_fase_5` P5.2, P5.19, H.6; `OnboardingService::claim()` |
| `/investor/login` benar → 302 dasbor; 5× salah → 429 + kunci 15 menit | `qc_fase_5` P5.7, P5.8, P5.9, C.2–C.9 |
| `/investor/dashboard` 200 hanya untuk mitra; bebas daftar terlarang | `qc_fase_5` D.1–D.3, P5.10, P5.11 |
| Modal Berputar = `min(utilized terakhir, balance_capital)`; Kas Cadangan = sisanya | `qc_fase_5` D.2 (Rp 6.204.265 / Rp 13.795.735 / 31,02 %), R.3 (klamp), R.4 (tanpa putaran) |
| Penarikan mandiri: dividen tanpa masa tunggu, modal H-7 hari kerja | `qc_fase_5` P5.16, P5.17, F.1 |
| `Σ` pengajuan terbuka ≤ saldo; dompet dari sesi; `_once` ganda diabaikan | `qc_fase_5` F.3, D.5, D.6 |
| Laporan ramah cetak + CSV (`text/csv`, BOM, pemisah `;`) | `qc_fase_5` P5.12, P5.13, P5.14 |
| `POST /operator/capital/invite` + token otomatis pada `createMitra()` | `qc_fase_5` G.1, G.4, G.5 |
| PWA: manifest valid, `sw.js` hanya cache aset statis, ikon 192/512 | `qc_fase_5` J.1–J.8, P5.21, P5.22 |
| Gating sadar peran (mitra → `/investor/dashboard`, operator → `/dashboard`) | `qc_fase_5` E.1–E.8, P5.15 |
| `docs/` + `AGENTS.md`/`README.md` selaras; **nol DDL** | `qc_fase_5` K.1–K.9 |

### Luaran berkas

| Berkas baru | Peran |
|---|---|
| `app/Helpers/PinThrottle.php` | Pembatas percobaan login berbasis berkas (5 gagal → kunci 15 menit per nomor+IP) |
| `app/Services/OnboardingService.php` | Klaim tautan undangan, kebijakan PIN, teks waiver (satu pemilik) |
| `app/Services/InvestorService.php` | View-model blind (Layar D) + pengajuan penarikan dari sisi sesi |
| `app/Controllers/InvestorAuthController.php` | Pintu PIN 6 digit (`/investor/login`) |
| `app/Controllers/InvestorOnboardingController.php` | Gerbang undangan (`/join`) |
| `app/Controllers/InvestorController.php` | Dasbor blind + penarikan mandiri |
| `app/Controllers/ExportController.php` | Laporan ramah cetak + CSV (lingkup investor) |
| `app/Views/layouts/{investor,report}.php` | Kerangka portal mitra & kerangka cetak |
| `app/Views/investor/{login,onboarding,dashboard,report}.php` | Layar A2, Layar A, Layar D, laporan |
| `public/{manifest.json,sw.js,offline.html}`, `public/assets/icons/*.png` | PWA (ikon 192/512/maskable/apple-touch) |
| `tests/qc_fase_5.php` | Protokol QC Manual 5 (129 assertion) |
| `tests/manual/js_investor_check.js` | Probe Alpine portal mitra + tombol salin tautan (43 assertion, di luar `run_qc.sh`) |
| `storage/throttle/.gitignore` | Struktur folder pembatas (isinya state runtime) |

Berkas yang diubah: `BaseController` (beranda per peran), `AuthController` (throttle + landing sadar peran),
`DashboardController` (`/dashboard` operator-only), `CapitalController` (kartu undangan + `invite()`),
`CapitalService` (token otomatis, `notice_days` opsional, invariant Σ pengajuan terbuka),
`User`, `CapitalWallet`, `ProfitDistribution`, `WithdrawalRequest`, `Security` (PIN ber-pepper),
`Formatter` (`persen()`), `App` (`pin_pepper`, `absoluteUrl()`), `routes.php`, `app.js`, `app.min.css`,
lima layout, `views/operator/capital.php` (kartu F), `.env.example`, `.gitignore`,
`tests/{bootstrap,run_qc,qc_seed,qc_fase_2,qc_fase_3,qc_fase_4}.php`.

---

## 2. Angka Nyata (temuan lingkungan: hari ini `2026-10-01`)

Dasbor mitra QC (`balance_capital` Rp 20.000.000; empat putaran: hari ini, −1, −2, dan −40 hari):

| Metrik dasbor | Nilai |
|---|---|
| Total Nilai Portofolio | **Rp 20.379.341** (`balance_capital` + `balance_profit` Rp 379.341,18) |
| Modal Berputar | **Rp 6.204.265** (`capital_utilized` terakhir Rp 6.204.264,71) |
| Kas Cadangan | **Rp 13.795.735** |
| Rasio aktif | **31,02 %** |
| Dividen bulan berjalan | **Rp 154.341** (hanya putaran bertanggal Oktober) |
| ROI bulan berjalan | **0,77 %** |
| Total keuntungan diterima | **Rp 379.341** |

Angka kanari yang **tidak pernah** muncul di permukaan investor (diuji di HTML, laporan, dan CSV):
omzet toko Rp 37.544.000, hak pengelola Rp 1.749.200, laba bersih Rp 2.186.500, gramatur 18,050 gr,
dan alias penambang `QC Kanari Penambang`.

Keadaan undangan: token 64 heksadesimal; memutar ulang mematikan tautan lama (`/join` → 404);
setelah tanda tangan `invite_token = NULL`, `signed_ip_address = 127.0.0.1`,
`signed_user_agent` = UA kiriman, dan PIN 6 digit langsung berlaku.

---

## 3. Verifikasi

```
bash tests/run_qc.sh
  LULUS : 11/11   GAGAL : 0/11   (log: storage/logs/qc-fase5.log)

  qc_pdo 20 · qc_formatter 43 · qc_security 32 · qc_seed 46 ·
  qc_roadmap_protocol_1 6 · qc_fase_2 66 · qc_fase_3 98 · qc_fase_4 112 ·
  qc_fase_5 125 · qc_sql_injection 15 · qc_routing 31
  ── TOTAL 598 assertion, 0 gagal

node tests/manual/js_form_init_check.js          → 39/39 PASS
node tests/manual/js_capital_simulator_check.js  → 38/38 PASS
node tests/manual/js_investor_check.js           → 43/43 PASS  (baru; 13 di antaranya dari perbaikan tombol salin)
```

- `php -l` bersih pada seluruh berkas PHP yang ditambah/diubah.
- Tidak ada `storage/logs/php-*.log` baru (yang ada bertanggal 2026-09-30, sebelum sesi ini).
- `git status` bersih dari artefak (`tests/.tmp/`, `storage/installed.lock`, `*.log`, `storage/throttle/*`).
- **Nol DDL**: 9 tabel ERD tetap, kolom `users` tetap 12 (invite/audit sudah ada sejak Fase 1),
  `database/schema.sql`, `database/seed.sql`, dan dokumen ERD tidak disentuh.

---

## 4. Penyimpangan dari Rencana (ringkas; rinci di ADDENDUM `Plan_Fase_5_AurumFlow.md`)

1. **Fixture mitra QC dipecah dua**: `seedQcInvestor()` (sudah onboarding, PIN aktif, empat putaran)
   dan `seedQcInvitee()` (menunggu onboarding). Rencana awal hanya menyebut satu fungsi; satu akun
   tidak dapat sekaligus "aktif" dan "menunggu", sedangkan keduanya perlu diuji.
2. **Penurunan mutu `PinThrottle` diuji pada proses terpisah.** Jalur fallback sesi hanya hidup bila
   ada session, dan membuat session di tengah proses QC yang sudah mencetak akan mengotori output →
   probe `qc_run_php()` dengan `SESSION_SAVE_PATH` di `tests/.tmp`.
3. **`ExportController` diskop ke investor saja** (nama berkas mengikuti SAD; ekspor lengkap operator
   tetap fase berikutnya).
4. **`InvestorController` memisahkan `requireInvestor()` (GET) dari `guard()` (POST).** Awalnya satu
   method; halaman GET tidak membawa token CSRF sehingga render pertama ditolak 419.
5. **`qc_seed` ikut diperbaiki**: pembersihan sisa data QC kini membuang baris anak (distribusi,
   penarikan, dompet) sebelum `users` — FK `ON DELETE RESTRICT` membuat suite gagal begitu akun mitra
   Fase 5 memiliki dompet.
6. **`qc_fase_4` disesuaikan**: jumlah jalur `Database::transaction()` di `CapitalService` menjadi 8
   (penerbitan tautan undangan), dan label assertion diperbarui.

---

## 5. Item Terbuka (lanjut ke Fase 6)

| ID | Item | Catatan |
|---|---|---|
| O-1 | Versi teks waiver belum tersimpan per mitra | Butuh kolom baru (`waiver_version`) — DDL |
| O-2 | Onboarding ulang menimpa jejak audit | Arsip persetujuan butuh tabel riwayat — DDL |
| O-3 | PDF asli buatan peladen & ekspor lengkap operator | Fase 5 memakai laporan ramah cetak + CSV (keputusan pengguna) |
| O-4 | Reset PIN mandiri oleh mitra | Sementara lewat "Buat ulang tautan" operator |
| O-5 | Ambang `PinThrottle` masih di kode | Parameter keamanan, bukan `system_settings` |
| O-6 | Mode alokasi `prorata`, kalender libur H-7, `consumable_opex`, retensi nota | Warisan Fase 3/4 |
| O-7 | Panel admin penuh `system_settings` | Masih phpMyAdmin |
| O-8 | Pemasangan `APP_PIN_PEPPER` pada deployment lama | WAJIB diisi sebelum mitra membuat PIN; rotasi = seluruh PIN mati |
