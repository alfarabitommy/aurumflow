# Plan Fase 5 — AurumFlow

> **Status dokumen (pasca-Fase 9 — sinkronisasi dokumentasi).** Seluruh angka dan perilaku di
> bawah ini — termasuk (bila ada) penyebutan **13 tabel** atau kurang, **14 suite / 853
> assertion**, `CACHE_NAME` **v3**, dan isian **`coord_fee`** — adalah **potret fase tersebut**,
> bukan keadaan sekarang. Keadaan terkini: **14 tabel** (termasuk `operational_expenses`, nol `ALTER`),
> `CACHE_NAME` **`aurumflow-shell-v4`**, **15 suite / 900 assertion / 0 gagal**, beban operasional
> dicatat sebagai **voucher kas** di meja kasir lalu diserap otomatis oleh tutup buku
> (`total_cash = pendapatan`). Rujukan terkini: `plan/Summary_Fase_9_OPEX_AurumFlow.md`.


> **Dokumen Perencanaan Eksekusi**
> Proyek: **AurumFlow** — sistem manajemen operasional privat untuk perdagangan emas aluvial dengan settlement **T+1**.
> Fase: **5 — Dasbor Blind Investor, Legal Waiver (Clickwrap), & PWA**
> Status: `APPROVED FOR PLANNING` · **belum ada kode PHP/JS yang dieksekusi.**

| Item | Nilai |
|---|---|
| Runtime | Native PHP **8.2+** (`declare(strict_types=1)`), tanpa framework, tanpa Composer/`vendor/` |
| Frontend | Tailwind CSS **3.4.19** (CLI, dikompilasi lokal) + Alpine.js **3.17.4** (aset lokal, tanpa CDN) + `manifest.json` + `sw.js` |
| Database | MySQL 8.0 / MariaDB — **9 tabel ERD tidak berubah, nol DDL, nol migrasi** |
| Scope baru | Route `/join`, `/investor/{login,dashboard,withdrawal,report,report/csv}`, `POST /operator/capital/invite`; `OnboardingService`, `InvestorService`, `PinThrottle` |
| Sumber kebenaran | ERD → SAD → Roadmap (Protokol QC Manual 5) → PRD (§ 5, § 6 Modul 3–4) → UI/UX (Layar A & D) |
| Keputusan pengguna | `dec-ef670a108317f925` (lihat §2.1) |
| Bahasa dokumen | Prosa Indonesia + istilah teknis Inggris (konvensi `Plan_Fase_1..4`) |

---

## 1. Ringkasan Fase 5

### 1.1 Tujuan

Fase 4 sudah memindahkan porsi **20 % pool mitra** ke `capital_wallets.balance_profit` dan menyediakan antrean penarikan H-7 — tetapi **mitra belum punya pintu masuk**: akun investor dibuat `CapitalService` dengan kata sandi acak yang tidak diungkapkan (A-14 Fase 4), `invite_token` sengaja dibiarkan `NULL`, dan dasbor `/dashboard` justru membocorkan `system_settings` (mode alokasi, kuota 50 %, defisit).

Fase 5 menutup lingkaran itu dengan lima hal yang wajib benar:

1. **Gerbang undangan (Layar A).** Operator menerbitkan tautan `?token=…` per mitra; mitra membuka tautan, membaca Edukasi & Pakta Integritas, mencentang persetujuan, lalu menetapkan **PIN 6 digit**. Peladen merekam `onboarding_signed_at`, `signed_ip_address`, `signed_user_agent` **dalam satu transaksi**, lalu **menghanguskan** token (sekali pakai).
2. **Autentikasi PIN.** `/investor/login` = `phone_number` + PIN 6 digit (papan angka, mobile-first). PIN **tidak pernah** dapat lolos lewat `/login` (kata sandi operator) karena disimpan sebagai hash ber-*pepper* — satu kredensial, satu pintu. Brute-force ditahan **throttle persisten berbasis berkas** di `storage/` (5 gagal → kunci 15 menit per `nomor + IP`).
3. **Dasbor blind (Layar D).** Mitra melihat **hanya** dompetnya, riwayat `profit_distributions` miliknya, dan turunannya. Tidak ada omzet, `hulu_transactions`, `operator_share_80`, `investor_pool_20`, nama/saldo mitra lain, maupun isi `system_settings`. "Modal Berputar" = `capital_utilized` pada baris distribusi **terakhir** miliknya (dibatasi `balance_capital`); "Kas Cadangan" = sisanya.
4. **Penarikan swalaya.** Dua tombol — **Tarik Keuntungan** (tanpa masa tunggu, jatuh tempo = hari pengajuan) dan **Tarik Modal** (H-7 hari kerja, status dompet `queued`). Keduanya hanya **mengajukan**; pencairan tetap milik operator (Fase 4).
5. **PWA & isolasi peran.** `manifest.json` + `sw.js` (cache **hanya aset statis**, tidak pernah HTML/AJAX), dan seluruh route investor dijaga `requireRole('investor')` sementara `/operator/*` melempar investor ke `/investor/dashboard` — bukan ke `/dashboard` yang berisi data internal.

### 1.2 Definisi Selesai (Definition of Done)

- [ ] `GET /join?token=…` menampilkan Layar A (edukasi + kotak waiver + PIN); token tak dikenal / sudah dipakai → halaman "tautan tidak berlaku", **nol** tulisan.
- [ ] `POST /join` sukses = **satu** `Database::transaction()`: `password_hash`, `onboarding_signed_at`, `signed_ip_address`, `signed_user_agent`, `invite_token = NULL`; PRG → `/investor/login` + flash.
- [ ] `POST /investor/login` benar → `302 /investor/dashboard`; 5× salah → `429` + kunci 15 menit; PIN tidak diterima `/login` (dan sebaliknya kata sandi operator tidak diterima `/investor/login`).
- [ ] `/investor/dashboard` 200 hanya untuk sesi investor; body-nya **tidak memuat** satu pun kata/angka terlarang (omzet, hulu, 80 %, kuota, mode alokasi, nama mitra lain) — diuji, bukan diasumsikan.
- [ ] "Modal Berputar" = `min(capital_utilized` baris terakhir`, balance_capital)`; "Kas Cadangan" = `balance_capital − Modal Berputar`; keduanya diuji untuk kasus *clamp* (utilized > modal) dan kasus tanpa putaran.
- [ ] `POST /investor/withdrawal`: dividen → `notice_period_due` = hari pengajuan; modal → `+notice_period_days` **hari kerja**; `Σ` pengajuan terbuka per jenis ≤ saldo; dompet **selalu** diambil dari sesi (payload `wallet_id` asing diabaikan); `_once` ganda → tanpa tulisan.
- [ ] `GET /investor/report` (ramah cetak) dan `GET /investor/report/csv` (`text/csv`, BOM, pemisah `;`) memuat **hanya** angka mitra yang bersangkutan.
- [ ] `POST /operator/capital/invite` (operator) menerbitkan token 64 heksadesimal; menerbitkan ulang **memutar** token (tautan lama mati); `CapitalService::createMitra()` otomatis menerbitkan token.
- [ ] `public/manifest.json` (JSON valid, `start_url` relatif), `public/sw.js` (tanpa cache HTML/AJAX), `public/offline.html`, ikon 192/512/maskable/apple-touch tersedia; seluruh layout memuat `<link rel="manifest">`.
- [ ] Investor → `302 /investor/dashboard` untuk **semua** method di `/operator/*` dan `/dashboard`; operator → `302 /dashboard` di `/investor/*`; anonim → `302 /login`.
- [ ] `bash tests/run_qc.sh` exit `0` (**11 suite**, target ≥ 580 assertion, 0 gagal), tanpa `storage/logs/php-*.log`, `git status` bersih dari artefak (`tests/.tmp/`, `storage/installed.lock`, `*.log`, `storage/throttle/*`).
- [ ] `docs/` (UI/UX Layar A & D, PRD § 5/§ 6, Roadmap FASE 5 + Protokol QC Manual 5, SAD § 3) dan `AGENTS.md`/`README.md` diperbarui lebih dulu (T1); `database/schema.sql`, `database/seed.sql`, dan dokumen ERD **tidak disentuh**.

### 1.3 Di Luar Ruang Lingkup Fase 5

- **Audit trail berversi waiver** (menyimpan `waiver_version` + riwayat persetujuan) — butuh DDL, item O-1.
- **PDF asli buatan peladen**, ekspor lengkap sisi operator, dan panel admin penuh `system_settings` — Fase 6.
- **Mode alokasi `prorata`** (O-1 Fase 4), kalender hari libur nasional H-7 (O-2 Fase 4).
- **Notifikasi otomatis** (WhatsApp/email) untuk tautan undangan — tautan disalin manual oleh operator.
- **Reset PIN mandiri** oleh mitra — sementara lewat "Buat ulang tautan" operator.
- Item Fase 2/3 yang tetap terbuka: `consumable_opex`, beban aparat sebagai `system_settings`, retensi berkas nota.

---

## 2. Keputusan & Asumsi Terkunci

### 2.1 Keputusan pengguna (`dec-ef670a108317f925`)

| Keputusan | Pilihan | Konsekuensi |
|---|---|---|
| Bentuk ekspor | **Hybrid**: halaman laporan HTML ramah-cetak (`Ctrl+P` → *Save as PDF*) + endpoint unduh **CSV** | Nol dependensi (tanpa Composer); kedua permukaan dapat diuji lewat HTTP. Tombol Layar D menyimpang dari label wireframe "Unduh Laporan Bulanan (PDF)" → label disesuaikan dan dicatat di `docs/UI-UX` (T1) |
| Pintu login investor | **Halaman terpisah `/investor/login`** (papan angka PIN); `/login` tidak berubah | Nol risiko pada `qc_routing`/`qc_sql_injection` yang menguji `/login` + field `password`; dua permukaan login hidup berdampingan |
| Pengerasan PIN | **Throttle persisten berbasis berkas** di `storage/` (5 gagal → kunci 15 menit per nomor + IP) | Butuh komponen baru (`PinThrottle`), pembersihan berkas di QC, dan `.gitignore`; dipakai bersama `/login` agar throttle tidak bisa dilewati pintu kata sandi |

### 2.2 Asumsi & keputusan arsitektural yang dikunci

- **A-1 — Sumber "modal bekerja" tetap `capital_utilized`.** Skema ERD tidak punya kolom status modal; dasbor merekonstruksinya dari baris `profit_distributions` **terakhir** milik dompet: urutan `daily_settlements.settlement_date DESC, profit_distributions.created_at DESC, profit_distributions.id DESC` (deterministik meski `created_at` berpresisi detik — item O-1).
- **A-2 — Klamp "Modal Berputar".** `modalBerputar = min(capital_utilized_terakhir, balance_capital)`; `kasCadangan = balance_capital − modalBerputar`. Tanpa klamp, mitra yang menarik modal setelah putaran terakhir akan melihat angka negatif. Bila belum ada satu pun baris distribusi → keduanya `0` dan layar menampilkan keadaan "belum ada putaran".
- **A-3 — Angka turunan Layar D.**
  - `Total Nilai Portofolio = balance_capital + balance_profit`.
  - `Dividen bulan ini = Σ profit_earned` yang `settlement_date`-nya berada pada **bulan kalender berjalan** (zona `APP_TIMEZONE`, WIB).
  - `ROI bulan ini = dividen bulan ini ÷ balance_capital × 100`, tampil 2 desimal; `balance_capital = 0` → `—` (tidak ada pembagian nol).
  - `Total keuntungan diterima = Σ profit_earned` sepanjang waktu (tanpa batas bulan).
  - Rasio alokasi = `modalBerputar ÷ balance_capital × 100` (2 desimal).
- **A-4 — PIN = tepat 6 digit.** Ditolak: bukan 6 digit, seluruh digit sama (`111111`), barisan naik/turun lurus (`123456`, `654321`, `012345`), dan blok 3 digit yang sama diulang (`123123`). Alasannya: PIN adalah kredensial dengan ruang 10⁶ — kebijakan ini murah dan menghapus tebakan paling umum. *Alternatif (terbuka untuk ditolak saat review): terima seluruh PIN 6 digit dan andalkan throttle saja — lihat O-6.*
- **A-5 — PIN disimpan ber-*pepper*** di kolom yang sama (`users.password_hash`): `password_hash(hash_hmac('sha256', $pin, APP_PIN_PEPPER))`. Akibat yang diinginkan: PIN **tidak pernah** memuaskan `Security::verifyPassword()`, sehingga `/login` bukan pintu kedua bagi PIN (menutup bypass throttle). Bila `APP_PIN_PEPPER` kosong saat onboarding → `RuntimeException` dengan pesan siap tampil (**fail-closed**), bukan diam-diam menyimpan PIN tanpa pepper. Memutar pepper = seluruh PIN lama mati (operator memakai "Buat ulang tautan").
- **A-6 — Throttle satu pemilik, dua pintu.** `PinThrottle` (berkas) dipakai `/investor/login` **dan** `/login`. Ambang: `MAX_FAILS = 5`, `WINDOW = 900 s`, `LOCK = 900 s`, kunci per `sha256(phone|ip)`; login sukses menghapus entri; entri ber-`mtime` > 24 jam dibersihkan (prune oportunistik). Bila direktori tidak dapat ditulis → penurunan mutu ke penghitung **berbasis sesi** + satu `Logger::warning` (aplikasi tidak boleh mati hanya karena `storage/` read-only) — dan kondisi itu diuji.
- **A-7 — Tautan undangan hanya dibuat peladen.** Token = `Security::randomToken(32)` (64 heksadesimal, cocok `VARCHAR(64)` UNIQUE). Diterbitkan otomatis saat `CapitalService::createMitra()` **di dalam transaksi yang sama** dengan pembuatan akun + dompet, dan dapat diterbitkan/diputar ulang operator (`POST /operator/capital/invite`, aksi `issue`/`reset`). Token **sekali pakai**: di-NULL-kan saat tanda tangan.
- **A-8 — `reset` untuk onboarding ulang.** Mode `reset` (dipakai untuk mitra yang sudah tanda tangan atau lupa PIN) menerbitkan token baru tanpa menghapus kolom audit; tanda tangan berikutnya **menimpa** jejak audit lama. Konsekuensi hukum (riwayat persetujuan hilang) dicatat sebagai O-2 — yang dipertahankan adalah bukti persetujuan **terakhir**, bukan arsip.
- **A-9 — Satu transaksi onboarding.** `password_hash` + `onboarding_signed_at` + `signed_ip_address` + `signed_user_agent` + `invite_token = NULL` ditulis lewat **satu** `Database::transaction()`; gagal di tengah → token tetap hidup dan akun tetap belum dapat dipakai (diuji dengan memaksa kegagalan).
- **A-10 — Waktu & jejak dari peladen, bukan klien.** `onboarding_signed_at = date('Y-m-d H:i:s')` (WIB; sesi MySQL memang di-set `App::utcOffset()`), IP = `User::clientIp()` (hanya `REMOTE_ADDR`, sesuai Fase 1), UA = `$_SERVER['HTTP_USER_AGENT']` dipotong 512 karakter. Tidak ada nilai waktu/IP/UA yang dibaca dari form.
- **A-11 — Gating sadar peran.** `requireRole()` mengarahkan pelanggar ke **beranda perannya** (`investor` → `/investor/dashboard`, selain itu → `/dashboard`); `/dashboard` menjadi **operator-only** — investor tidak boleh melihat `system_settings`, dan sebelum Fase 5 mereka bisa. `AuthController::login()`, `requireGuest()`, `DashboardController::home()`, dan `logout()` mengikuti aturan yang sama. Ini **mengubah perilaku Fase 2–4** dan karena itu assertion terkait di tiga suite lama diperbarui (A-18).
- **A-12 — Dompet diambil dari sesi, bukan dari form.** `/investor/*` tidak pernah menerima `wallet_id`; `InvestorService` menyelesaikannya lewat `CapitalWallet::findByUserId($_SESSION['user_id'])`. Payload asing diabaikan tanpa jejak tulisan (diuji).
- **A-13 — Penarikan investor = pengajuan, bukan pencairan.** Dividen: `notice_period_due` = hari pengajuan (`notice_period_days = 0`), boleh dibayar operator setelah disetujui (PRD § 4.3). Modal pokok: `+notice_period_days` hari kerja (Sabtu/Minggu dilewati) + `withdrawal_status='queued'`. Pencairan tetap `CapitalService::payWithdrawal()` (Fase 4) — mitra tidak pernah menggerakkan kas.
- **A-14 — Satu pemilik aturan penarikan tetap `CapitalService::requestWithdrawal()`.** Fase 5 menambah dua hal kecil di dalamnya: (a) kunci input opsional `notice_days` (dipakai investor untuk dividen = 0; operator tetap memakai `system_settings`), dan (b) invariant **Σ pengajuan terbuka per jenis ≤ saldo** (sebelumnya dua pengajuan dividen masing-masing ≤ saldo bisa hidup bersamaan; aman karena `payWithdrawal()` tetap memeriksa saldo, tetapi menyesatkan di antrean). Perubahan ini menyentuh jalur operator → `qc_fase_4` disesuaikan bila ekspektasinya bergeser (didokumentasikan, disengaja).
- **A-15 — Ekspor tanpa teks dari klien.** CSV dibentuk **hanya** dari nilai yang dihitung peladen (tanggal, nominal, status), sehingga formula injection struktural mustahil; pemisah `;`, BOM UTF-8, angka konvensi Indonesia (`6.204.264,71`) agar terbuka rapi di Excel lokal. Nama mitra tidak ikut CSV (tidak ada gunanya bagi mitra sendiri) tetapi ada di laporan cetak.
- **A-16 — PWA konservatif.** Service worker **hanya** menyentuh aset statis (`/assets/**`, ikon, `offline.html`) dengan daftar-putih; seluruh respons non-aset (HTML front controller, `report/csv`) **tidak pernah** disimpan di cache dan selalu jaringan. Navigasi: jaringan lebih dulu, halaman `offline.html` sebagai jatuhan. Registrasi hanya bila `https:` **atau** `localhost`/`127.0.0.1`. Manifest memakai `start_url: "./"` + `scope: "./"` sehingga pemasangan subdirektori tetap benar tanpa membuat berkas dinamis.
- **A-17 — Ikon = berkas biner yang di-commit.** Dibuat sekali oleh skrip sekali-pakai di luar repositori (Node `zlib`), hasilnya di-commit; QC memverifikasi tanda tangan PNG + dimensi IHDR dengan membaca byte (**tanpa** ekstensi GD — tidak tersedia di semua host).
- **A-18 — Suite lama diperbarui secara sadar.** `qc_fase_2`, `qc_fase_3`, `qc_fase_4` kini menegaskan: investor → `/operator/*` = `302 /investor/dashboard`, dan CSRF untuk POST-uji investor dibaca dari `/investor/dashboard` (bukan `/dashboard`).
- **A-19 — Satu pemilik tampilan persen.** Method baru `Formatter::persen(mixed $value, int $decimals = 2)` menghasilkan `'31,02 %'`; dipakai dasbor + laporan. Migrasi Layar E ke method ini **tidak wajib** (menghindari perubahan tak perlu pada assertion Fase 4).
- **A-20 — Kontrak form berlaku sama.** Nilai awal dirender peladen (`value="…"`) dan dibaca Alpine `init()` lewat `formFieldValue()`; contoh numerik berawalan `mis. `; `x-model.fill` untuk isian bernilai awal; kontrol form **tidak pernah** `:disabled` (hanya `<button>`); mutasi = `_once` + PRG.
- **A-21 — QC dapat dijalankan berulang.** Fixture Fase 5 bersifat idempotent: hapus **seluruh** `profit_distributions` + `withdrawal_requests` lalu tanam kembali keadaan tetap milik mitra QC, netralkan dompet lain (`neutraliseWallets()`), kosongkan direktori throttle, dan pulihkan kolom onboarding akun QC. `QcFixture::ensureInstalled()` ikut membersihkan throttle supaya uji "login gagal" di suite lama tidak pernah menyentuh ambang kunci.
- **A-22 — Dokumentasi lebih dulu (aturan `AGENTS.md`).** Setiap penyimpangan dari `docs/` diperbarui di dokumen sumbernya pada T1, lalu dicatat pada `# ADDENDUM` dokumen ini. ERD tidak disentuh (nol DDL) dan itu diuji.

---

## 3. Peta Route & Permukaan Baru

### 3.1 Route (`app/routes.php`)

```php
'GET' => [
    '/'                     => [DashboardController::class, 'home'],          // sadar peran
    '/login'                => [AuthController::class, 'showLogin'],          // TIDAK berubah
    '/join'                 => [InvestorOnboardingController::class, 'show'], // Layar A
    '/investor/login'       => [InvestorAuthController::class, 'showLogin'],  // papan angka PIN
    '/dashboard'            => [DashboardController::class, 'index'],         // operator saja
    '/investor/dashboard'   => [InvestorController::class, 'index'],          // Layar D
    '/investor/report'      => [ExportController::class, 'report'],           // ramah cetak
    '/investor/report/csv'  => [ExportController::class, 'csv'],              // unduh CSV
    '/operator/teller'      => [TellerController::class, 'index'],
    '/operator/settlement'  => [SettlementController::class, 'index'],
    '/operator/capital'     => [CapitalController::class, 'index'],
],
'POST' => [
    '/login'                => [AuthController::class, 'login'],
    '/logout'               => [AuthController::class, 'logout'],             // sadar peran
    '/join'                 => [InvestorOnboardingController::class, 'submit'],
    '/investor/login'       => [InvestorAuthController::class, 'login'],
    '/investor/withdrawal'  => [InvestorController::class, 'withdrawal'],     // `_once`
    '/operator/teller'      => [TellerController::class, 'store'],
    '/operator/settlement'  => [SettlementController::class, 'store'],
    '/operator/capital/topup'      => [CapitalController::class, 'topUp'],
    '/operator/capital/mitra'      => [CapitalController::class, 'storeMitra'],
    '/operator/capital/quota'      => [CapitalController::class, 'updateQuota'],
    '/operator/capital/transfer'   => [CapitalController::class, 'transfer'],
    '/operator/capital/withdrawal' => [CapitalController::class, 'withdrawal'],
    '/operator/capital/invite'     => [CapitalController::class, 'invite'],   // BARU
],
```

### 3.2 Berkas baru & yang berubah

| Berkas | Isi |
|---|---|
| `app/Controllers/InvestorAuthController.php` | `showLogin()`, `login()` — PIN 6 digit, throttle, pesan gagal generik |
| `app/Controllers/InvestorOnboardingController.php` | `show()` (validasi token), `submit()` (waiver + PIN) |
| `app/Controllers/InvestorController.php` | `index()` (Layar D), `withdrawal()` (POST, `_once`) |
| `app/Controllers/ExportController.php` | `report()`, `csv()` — ruang lingkup Fase 5: **investor saja** (nama berkas mengikuti SAD; ekspor operator menyusul) |
| `app/Services/OnboardingService.php` | `findInvitee()`, `claim()`, `pinPolicy()`, `waiverClause()` — satu pemilik aturan onboarding |
| `app/Services/InvestorService.php` | `dashboard(string $userId)` (view-model blind) & `requestWithdrawal(string $userId, array $input)` |
| `app/Helpers/PinThrottle.php` | `check()`, `recordFailure()`, `clear()`, `prune()`, `storagePath()` |
| `app/Views/layouts/investor.php` | Kerangka mitra (header brand + nama mitra + keluar, tanpa nav operator, manifest + SW) |
| `app/Views/layouts/report.php` | Kerangka laporan ramah cetak (`@media print`, tanpa navigasi aplikasi) |
| `app/Views/investor/{login,onboarding,dashboard,report}.php` | Pintu PIN, Layar A, Layar D, laporan |
| `public/{manifest.json,sw.js,offline.html}` + `public/assets/icons/*.png` | PWA |
| `tests/qc_fase_5.php`, `tests/manual/js_investor_check.js` | QC (grup A–K) + probe Node |
| Berubah | `BaseController`, `AuthController`, `DashboardController`, `CapitalController`, `routes.php`, `CapitalService`, `User`, `CapitalWallet`, `ProfitDistribution`, `WithdrawalRequest`, `Formatter`, `App`, `Security`, `app.js`, `app.min.css`, `views/operator/capital.php`, `views/layouts/{app,auth}.php`, `.env.example`, `.gitignore`, `tests/bootstrap.php`, `tests/run_qc.sh`, `tests/qc_fase_{2,3,4}.php` |

---

## 4. Keputusan Teknis & Konvensi Fase 5

### 4.1 Alur onboarding (Layar A)

```
GET /join?token=<64 hex>
  ├─ token tidak cocok pola → tampilan "tautan tidak berlaku" (404)
  ├─ User::findByInviteToken(token) == null / is_active = 0 → idem
  └─ valid → Layar A: edukasi (T+1, komoditas, beban & bagi hasil)
                        + kotak waiver (gulir) + centang + PIN ×2
POST /join  (CSRF dari front controller; tanpa `_once` — token sendiri sekali pakai)
  → OnboardingService::claim(token, pin, pinConfirm, ip, ua)
      Database::transaction()
        a. kunci & baca ulang baris users by invite_token (FOR UPDATE)
        b. validasi PIN (A-4) + kecocokan konfirmasi
        c. users.password_hash = Security::hashPin(pin)
        d. users.onboarding_signed_at = date('Y-m-d H:i:s')
        e. users.signed_ip_address   = User::clientIp()
        f. users.signed_user_agent   = substr($_SERVER['HTTP_USER_AGENT'], 0, 512)
        g. users.invite_token        = NULL          ← sekali pakai
  → PRG /investor/login + flash "PIN aktif. Silakan masuk."
```

### 4.2 Alur login PIN & throttle

```
GET  /investor/login → form (nomor telepon + PIN 6 digit + papan angka Alpine), CSRF, contoh `mis. `
POST /investor/login
  1. throttle.check(phone, ip) → terkunci? 429 + "Terlalu banyak percobaan. Coba lagi dalam N menit."
  2. validasi bentuk: nomor 8–20 karakter pola install.php, PIN ^[0-9]{6}$ → 422 bila gagal
  3. User::findByPhoneNumber(phone) — null / role != investor / PIN salah
        → throttle.recordFailure() → 401 + pesan generik "Nomor telepon atau PIN salah."
  4. sukses → throttle.clear() → Security::regenerateSession() + rotateCsrf()
        → $_SESSION['user_id'|'user_role'|...] → 302 /investor/dashboard
```

Aturan yang dipegang sama seperti `AuthController`: pesan gagal **generik** (tanpa enumerasi akun), `pin` tidak pernah di-log, dan `/login` memakai `PinThrottle` yang sama sehingga brute-force tidak bisa berpindah pintu.

### 4.3 Rumus Layar D (beku)

```
utilizedTerakhir  = profit_distributions.capital_utilized  (baris terakhir, A-1)
modalBerputar     = min(utilizedTerakhir, balance_capital)          (A-2)
kasCadangan       = balance_capital − modalBerputar
totalPortofolio   = balance_capital + balance_profit
dividenBulanIni   = Σ profit_earned  WHERE settlement_date ∈ bulan berjalan (WIB)
roiBulanIni       = dividenBulanIni ÷ balance_capital × 100  (2 desimal; 0 → "—")
rasioAktif        = modalBerputar ÷ balance_capital × 100     (2 desimal; 0 → "—")
totalKeuntungan   = Σ profit_earned  (sepanjang waktu)
riwayat           = N baris terakhir (tanggal putaran, modal diputar, imbal hasil, terverifikasi)
```

Angka tampilan **wajib** lewat `Formatter` (`rupiah()`, `tanggalId()`, `persen()`); perhitungan dalam **sen integer** (pola `TellerService::moneyToCents()`/`centsToDecimal()`), bukan float.

### 4.4 Aturan penarikan swalaya

| Jenis | Batas | Jatuh tempo | Efek dompet | Status |
|---|---|---|---|---|
| **Tarik Keuntungan** (`profit`) | `Σ` pengajuan terbuka (pending+approved) + nominal ≤ `balance_profit` | hari pengajuan | tidak berubah | `pending` |
| **Tarik Modal** (`capital`) | `Σ` pengajuan terbuka + nominal ≤ `balance_capital`; dan tidak ada pengajuan modal terbuka | `+notice_period_days` **hari kerja** | `withdrawal_status='queued'` + `withdrawal_request_date` | `pending` |

Pencairan tetap di `/operator/capital` (Fase 4): `approved` → `Bayar` → kas brankas turun + mutasi `out` + saldo dompet turun + `completed` dalam satu transaksi.

### 4.5 PWA

| Berkas | Isi |
|---|---|
| `public/manifest.json` | `name`/`short_name` AurumFlow, `display: standalone`, `orientation: portrait`, `lang: id`, `theme_color`/`background_color` `#07080B`, `start_url: "./"`, `scope: "./"`, `icons`: 192, 512, 512 *maskable*, `apple-touch-icon` 180 — semuanya path relatif |
| `public/sw.js` | `CACHE_NAME = 'aurumflow-shell-v1'`; `install` → precache daftar-putih (CSS, `app.js`, `alpine.min.js`, font, ikon, `offline.html`); `fetch` → hanya `GET` same-origin **di dalam daftar-putih** yang dilayani cache, navigasi jaringan-dulu (jatuh ke `offline.html`), sisanya jaringan murni; `activate` → buang cache versi lama; permintaan non-GET tidak pernah disentuh |
| `public/offline.html` | Halaman statis tanpa data sensitif |
| Registrasi | `app.js`: hanya bila `'serviceWorker' in navigator` **dan** (`https:` atau `localhost`/`127.0.0.1`); `scope` = `App::url('')` |

### 4.6 Isolasi blind — daftar terlarang untuk berkas investor

Static scan (grup I) menolak, pada `Controllers/Investor*`, `Services/InvestorService.php`, `ExportController.php`, `Views/investor/*`, `Views/layouts/{investor,report}.php`:

`HuluTransaction`, `hulu_transactions`, `farmer_alias`, `daily_settlements.total_shop_revenue|net_settled_profit|operator_share_80|investor_pool_20|daily_coordination_fee|deficit_recovery_used|carryover_deficit`, `SystemSetting`, `CashRegister`, `CashMutation`, `sumProfit(`/`sumUtilized(` (agregat sesi), `SELECT *`, serta istilah tampilan "omzet", "laba kotor", "hulu", "pool", "pengelola 80".

Kolom yang **boleh** dibaca investor: `users.full_name` (diri sendiri), `capital_wallets.{id,balance_capital,balance_profit,locked_quota_pct,withdrawal_status,withdrawal_request_date}` (diri sendiri), `profit_distributions.{settlement_id,capital_utilized,profit_earned,created_at}` (dompet sendiri), `daily_settlements.settlement_date` (**hanya** kolom ini), `withdrawal_requests.*` (dompet sendiri).

> Catatan: `locked_quota_pct` hanya ditampilkan sebagai label tier/kebijakan; angka kuota global, `operator_base_quota_pct`, dan `allocation_mode` tidak pernah dirender.

---

## 5. Tugas Eksekusi (Step-by-Step)

### T1 — Dokumentasi lebih dulu (aturan `AGENTS.md`)

| Berkas | Perubahan |
|---|---|
| `docs/AurumFlow UI UX Documents.md` | **Layar A** difinalkan: papan angka PIN 6 digit, tombol terkunci sampai kotak waiver digulir (UX; peladen hanya memverifikasi centang), catatan audit trail; **Layar D** disesuaikan: definisi Modal Berputar/Kas Cadangan (A-1/A-2), ROI & dividen bulan berjalan (A-3), tombol ekspor menjadi **"Lihat & Cetak Laporan" + "Unduh CSV"** (menggantikan label "PDF"), kartu "Tarik Keuntungan / Tarik Modal" + dialog H-7, spesifikasi layout `investor.php`; bagian baru: **anatomi PWA** (manifest, SW, offline, ikon) |
| `docs/AurumFlow Product Requirement Document v3.0.md` | § 5: alur undangan diperjelas (token sekali pakai, PIN ber-pepper, throttle 5/15 menit, satu transaksi audit); § 4.3: penarikan **dividen** tanpa masa tunggu, maksimum satu pengajuan terbuka **per jenis**; § 6 Modul 3: rumus Modal Berputar/Kas Cadangan/ROI; Modul 4: format ekspor final (cetak + CSV); § 8: penegasan isolasi + throttle |
| `docs/AurumFlow Roadmap and QC Documents.md` | **FASE 5**: checklist teknis disesuaikan (undangan, PIN+throttle, dasbor blind, penarikan swalaya, ekspor, PWA) dan **Protokol QC Manual 5 ditulis ulang** menjadi tabel yang dapat dijalankan otomatis (§ 6.1); **FASE 2/3/4**: baris "Akses URL Kasir Ilegal" diperbarui menjadi `302 /investor/dashboard` (A-18) |
| `docs/AurumFlow System Architecture Document.md` | § 3 struktur: `InvestorAuthController`, `InvestorOnboardingController`, `InvestorController`, `ExportController`, `Services/{OnboardingService,InvestorService}.php`, `Helpers/PinThrottle.php`, `Views/layouts/{investor,report}.php`, `Views/investor/*`, `public/{manifest.json,sw.js,offline.html,assets/icons/}` + catatan alasan penempatan; § 4.2 diperluas: isolasi dibuktikan dua lapis (kode + assertion) |
| `docs/AurumFlow Entity Relationship Diagram.md` | **TIDAK diubah** — dinyatakan eksplisit dan dijaga assertion QC grup K |
| `AGENTS.md` | Route baru + peta peran; kontrak **PIN ber-pepper → `/login` bukan pintu kedua**; throttle 5/15 menit & lokasi `storage/throttle/`; token undangan sekali pakai + `reset`; aturan pengalihan sadar peran & `/dashboard` operator-only; daftar terlarang isolasi blind; PWA (cache hanya aset statis); peringatan QC (suite menulis kolom onboarding `users`, `withdrawal_requests`, `storage/throttle/`); baseline suite/assertion **11 suite** |
| `README.md` | Tabel route, pohon direktori, tabel QC (**11 suite**), `APP_PIN_PEPPER` pada `.env.example`, catatan PWA (perlu HTTPS untuk *Add to Home Screen*), peringatan tulis DB/disk |

*Kriteria:* setiap perubahan menyebut bagian + alasan; tidak ada label wireframe yang bertentangan dengan implementasi (khususnya "PDF").

### T2 — Model (SQL saja)

| Berkas | Permukaan baru |
|---|---|
| `User` | `findByInviteToken(string $token): ?array`, `lockByInviteTokenForUpdate(string $token): ?array`, `issueInviteToken(string $userId): string`, `completeOnboarding(string $userId, string $pin, ?string $ip, ?string $ua): void` (dipanggil **di dalam** transaksi `OnboardingService`), `verifyPin(array $user, string $pin): bool` |
| `CapitalWallet` | `listWithUser()` diperluas: `u.invite_token`, `u.onboarding_signed_at` (layar operator); `findByUserId()` tetap jalur tunggal investor |
| `ProfitDistribution` | `latestForWallet(string $walletId): ?array`, `listForWallet(string $walletId, int $limit): array` (JOIN `daily_settlements` **hanya** `settlement_date`), `sumProfitForWalletBetween(string $walletId, string $from, string $to): string`, `sumProfitForWallet(string $walletId): string` |
| `WithdrawalRequest` | `listForWallet(string $walletId): array`, `sumOpenAmountForWallet(string $walletId, string $type): string` (dipakai invariant A-14) |

Semua nilai uang dikirim sebagai **string desimal kanonik**; setiap method baru punya pemanggil nyata atau assertion QC (A-19).

### T3 — Keamanan: PIN ber-pepper & throttle

- `app/Helpers/Security.php`: `hashPin(string $pin): string`, `verifyPin(string $pin, string $hash): bool`; membaca `APP_PIN_PEPPER` lewat `App::get('pin_pepper')`; `hash_hmac('sha256', $pin, $pepper)` sebelum `password_hash()`. Pepper kosong → `RuntimeException('APP_PIN_PEPPER belum diisi…')` saat **hashing** (fail-closed), bukan saat verifikasi.
- `app/Config/App.php`: `'pin_pepper' => Env::get('APP_PIN_PEPPER', '')` + `App::absoluteUrl(string $path = ''): string` (memakai `APP_URL`, jatuh ke `HTTP_HOST`, dipakai membentuk tautan undangan).
- `app/Helpers/PinThrottle.php`: konstanta `MAX_FAILS = 5`, `WINDOW_SECONDS = 900`, `LOCK_SECONDS = 900`, `MAX_AGE_SECONDS = 86400`; `check(string $phone, string $ip): int` (sisa detik kunci, 0 = bebas), `recordFailure(...)`, `clear(...)`, `prune()`, `storagePath()`; penulisan atomik `file_put_contents(..., LOCK_EX)`; fallback sesi + `Logger::warning` bila `storage/throttle/` tidak dapat ditulis.
- `.env.example`: blok baru `APP_PIN_PEPPER` + peringatan "jangan diputar setelah onboarding berjalan".
- `.gitignore`: `/storage/throttle/*` + `!/storage/throttle/.gitignore`; `storage/throttle/.gitignore` di-commit.
- `AuthController::login()` memakai `PinThrottle` yang sama (A-6) dengan **status lama dipertahankan** (`401` untuk kegagalan biasa) sehingga suite lama tidak bergeser.

### T4 — `app/Services/OnboardingService.php`

`findInvitee(string $token): ?array` (validasi pola 64 hex sebelum menyentuh DB → nol kueri untuk token sampah), `claim(string $token, array $input): array` (transaksi A-9, pesan siap tampil), `pinPolicy(string $pin, string $confirm): void` (A-4, `throws InvalidArgumentException`), `waiverClause(): string` + `WAIVER_VERSION` (**satu pemilik teks pakta integritas**, dipakai view dan diuji QC), `effectiveInviteState(array $user): string` (`none|pending|active`).

### T5 — `app/Services/InvestorService.php` + penyesuaian `CapitalService`

- `InvestorService::dashboard(string $userId): array` — menyusun seluruh view-model Layar D (§ 4.3) dari method model blind-safe; dompet wajib ada, bila tidak → `RuntimeException` berpesan siap tampil ("Akun Anda belum memiliki dompet modal. Hubungi pengelola.").
- `InvestorService::requestWithdrawal(string $userId, array $input): array` — resolusi dompet dari sesi (A-12), validasi jenis/nominal, delegasi ke `CapitalService::requestWithdrawal()`, dengan `notice_days = 0` untuk dividen.
- `CapitalService`: dukungan `notice_days` opsional (A-14a) + invariant Σ pengajuan terbuka (A-14b, memakai `WithdrawalRequest::sumOpenAmountForWallet()` **di dalam** transaksi, setelah dompet terkunci — urutan penguncian `capital_wallets` → `withdrawal_requests` → `cash_registers` tidak berubah); pesan 422 yang menyebut sisa yang masih dapat diajukan.
- `CapitalService::createMitra()` memanggil `User::issueInviteToken()` di dalam transaksi yang sama (A-7) dan mengembalikan `invite_token` pada hasilnya.

### T6 — Controller, gating, & route

- `BaseController`: `requireRole()` → beranda peran (A-11); `requireGuest()` → idem; tambahan `roleLandingPath(string $role): string` (**satu pemilik** pemetaan peran → beranda, dipakai `AuthController`, `DashboardController::home()`, dan `BaseController`).
- `AuthController`: `login()` mengarahkan ke beranda peran; `logout()` mengingat peran sebelum `destroySession()` lalu mengarahkan ke `/investor/login` (investor) atau `/login` (operator); `login()` memakai `PinThrottle`.
- `DashboardController`: `home()` sadar peran; `index()` → `requireRole(Constants::ROLE_OPERATOR)`.
- `InvestorAuthController`, `InvestorOnboardingController`, `InvestorController`, `ExportController` (semua method `requireRole(Constants::ROLE_INVESTOR)`, kecuali `/join` yang publik): pola `guard()`/`guardOnce()` disalin dari `CapitalController` (CSRF diperiksa dua kali; `_once` hanya pada `/investor/withdrawal`), sukses = PRG + flash, gagal = `422` + isian terpelihara + status HTTP benar (419/422/429).
- `CapitalController::invite()`: mode `issue|reset` (A-7/A-8), `requireRole('operator')`, tanpa `_once`, PRG + flash berisi status (`Tautan aktif`, `Tautan diputar ulang`).
- `routes.php`: tabel pada § 3.1.

### T7 — View & layout

- `layouts/investor.php`: header ringkas (brand, "Mitra: <nama>", Keluar), `<link rel="manifest">`, meta PWA, `app.js` + `alpine.min.js`.
- `views/investor/login.php`: form nomor telepon + PIN (`type="password"`, `inputmode="numeric"`, `maxlength="6"`, `autocomplete="off"`), papan angka Alpine (progressive enhancement), pesan gagal generik, tautan "Belum punya PIN? Hubungi pengelola", contoh berawalan `mis. `.
- `views/investor/onboarding.php`: Layar A (edukasi, kotak waiver dari `OnboardingService::waiverClause()`, centang, PIN ×2, tombol) + catatan audit.
- `views/investor/dashboard.php`: Layar D lengkap (kartu portofolio + bar alokasi, dividen bulan ini, ROI, dua tombol aksi + dialog konfirmasi H-7, riwayat 10 baris, tautan laporan) + kartu "Status pengajuan" dari `withdrawal_requests` miliknya.
- `views/investor/report.php` + `layouts/report.php`: laporan ramah awam (kolom: Tanggal Putaran, Modal Diputar, Imbal Hasil, Status *Terverifikasi*) + ringkasan (Total Modal Dititipkan, Total Keuntungan Diterima, Sisa Kas Mengendap, Modal Sedang Berputar) + tombol "Cetak / Simpan PDF" & "Unduh CSV".
- `views/operator/capital.php`: kartu baru **F. Undangan & Onboarding Mitra** (tabel: nama, telepon, status `Belum diundang|Menunggu|Aktif`, tautan `readonly` + tombol Salin, tombol Buat/Buat ulang tautan).
- `views/layouts/auth.php`: tautan "Masuk sebagai mitra" → `/investor/login`, `<link rel="manifest">`; `views/layouts/app.php`: tautan manifest.
- Seluruh output dinamis `Security::e()`; seluruh angka `Formatter`; kontrol form tidak pernah `:disabled`.

### T8 — Aset frontend & PWA

- `public/assets/js/app.js`: `Alpine.data('investorDashboard', …)` (dialog konfirmasi, papan angka PIN, tombol salin tautan undangan, pemilih jenis penarikan dengan estimasi H-7 **yang dirender peladen**), registrasi `serviceWorker` terjaga (A-16), tanpa cermin aritmetika baru yang bisa menyimpang dari peladen.
- `public/{manifest.json,sw.js,offline.html}` + `public/assets/icons/{icon-192,icon-512,icon-maskable-512,apple-touch-icon-180}.png` (ikon dibuat oleh skrip sekali-pakai di luar repo, hasil di-commit — A-17).
- `public/assets/css/app.min.css` dikompilasi ulang (`npx --yes tailwindcss@3.4.19 -c tailwind.config.js …`), tetap < 30 KB.

### T9 — Harness & QC

- `tests/bootstrap.php`: `QcFixture::seedQcInvestor()`, `resetInvestorState()`, `clearPinThrottle()`, `investorSnapshot()`; `ensureInstalled()` ikut mengosongkan `storage/throttle/`; `QcHttp` memakai `options['headers']` yang sudah ada untuk mengirim `User-Agent` uji.
- `tests/qc_fase_5.php` — Protokol QC Manual 5 + grup A–K (§ 6), diakhiri `Qc::finish('qc_fase_5')`.
- `tests/run_qc.sh` → `qc_fase_5` setelah `qc_fase_4`; header/ringkasan **11 suite**.
- `tests/qc_fase_{2,3,4}.php` → A-18 (pengalihan investor + sumber CSRF).
- `tests/manual/js_investor_check.js` (Node, di luar `run_qc.sh`) → papan angka hanya menerima 6 digit, dialog konfirmasi tidak men-submit form saat digulir, dan tombol kirim (bukan `<input>`) yang dinonaktifkan selama `submitting`.

### T10 — Gerbang penuh

`bash tests/run_qc.sh` (exit 0, **11 suite**) → tidak ada `storage/logs/php-*.log` → `node tests/manual/js_form_init_check.js` + `js_capital_simulator_check.js` + `js_investor_check.js` → `php -l` pada setiap berkas yang diubah → `git status` bersih (tanpa `tests/.tmp/`, `storage/installed.lock`, `*.log`, `storage/throttle/*`) → tinjauan `git diff --stat` memastikan hanya perubahan yang direncanakan.

### T11 — Serah terima

`plan/Summary_Fase_5_AurumFlow.md` (hasil, angka nyata, jumlah assertion, item terbuka), pembaruan baseline `AGENTS.md`/`README.md`, dan `# ADDENDUM — Catatan Eksekusi` pada dokumen ini untuk setiap penyimpangan + alasannya.

---

## 6. Quality Control — Protokol QC Manual 5

### 6.1 Tabel protokol (1:1 dengan dokumen Roadmap setelah T1)

| Parameter Uji | Data Masukan | Hasil yang Diharapkan |
|---|---|---|
| Audit jejak hukum | `/join` dengan token valid + centang + PIN `246810` | Baris `users` terisi `onboarding_signed_at` (WIB), `signed_ip_address = 127.0.0.1`, `signed_user_agent` = UA kiriman; `invite_token = NULL` |
| Token sekali pakai | Buka ulang `/join` dengan token yang sama | Halaman "tautan tidak berlaku"; **nol** tulisan |
| Isolasi laba pengelola | Body HTML `/investor/dashboard` + `/investor/report` | Tidak ada `operator_share_80`, omzet, `hulu`, kuota 50 %, mode alokasi, atau angka sesi |
| Isolasi mitra lain | Body dasbor mitra A | Hanya saldo/nama mitra A; nama & saldo B, Prioritas, Pengelola tidak muncul; CSV juga bersih |
| Akses URL operator ilegal | Mitra A membuka `/operator/teller`, `/operator/capital`, `/dashboard` (GET + POST) | `302 /investor/dashboard`, **nol** tulisan |
| Akses URL investor ilegal | Operator membuka `/investor/dashboard` | `302 /dashboard` |
| PIN salah berulang | 5× PIN salah dalam 15 menit | Percobaan ke-5 → `429` + pesan "coba lagi dalam … menit"; PIN benar tetap ditolak selama kunci |
| PIN bukan 6 digit | `12345`, `1234567`, `abcdef`, `111111`, `123456` | `422` tanpa tulisan; PIN lemah ditolak dengan alasan |
| Modal Berputar vs Kas Cadangan | `capital_utilized = 6.204.264,71`, `balance_capital = 20.000.000,00` | Berputar **Rp 6.204.265**, Cadangan **Rp 13.795.735**, rasio **31,02 %** |
| Klamp modal bekerja | `capital_utilized = 25.000.000,00` > modal | Berputar = modal (Rp 20.000.000), Cadangan Rp 0, rasio 100,00 % |
| Tarik Keuntungan | nominal ≤ dividen | Baris `withdrawal_requests` `profit`, `pending`, `notice_period_due` = hari ini |
| Tarik Modal | nominal ≤ modal | `capital`, `pending`, `notice_period_due` = +7 **hari kerja**, dompet `queued` |
| Pengajuan melebihi saldo | nominal > saldo / Σ terbuka > saldo | `422`, **nol** tulisan |
| Ekspor CSV | `/investor/report/csv` | `text/csv` + `Content-Disposition: attachment`, BOM, pemisah `;`, jumlah baris & total = milik mitra A |
| PWA installability | `manifest.json`, `sw.js`, ikon, `offline.html` | JSON valid, `display: standalone`, ikon 192/512 (+maskable) ber-PNG sah, SW hanya men-cache aset statis |

### 6.2 Assertion tambahan di luar protokol manual (`tests/qc_fase_5.php`)

| Grup | Cakupan |
|---|---|
| **A** | `OnboardingService`: pola token, token tak dikenal/sudah dipakai/akun nonaktif, kebijakan PIN (6 digit, sama semua, barisan lurus, blok berulang, konfirmasi beda), penulisan 4 kolom audit + `invite_token=NULL`, `password_hash` memverifikasi PIN (dan **tidak** memverifikasi PIN tanpa pepper), atomisitas (paksa gagal → token & hash utuh), `WAIVER_VERSION` + klausul tunggal |
| **B** | Autentikasi PIN: `GET /investor/login` 200 tanpa sesi, field `pin` (bukan `password`), POST benar → `302 /investor/dashboard`, PIN salah → `401` generik, akun operator melalui pintu PIN → gagal generik, PIN tidak lolos `/login`, kata sandi lama (akun QC Fase 2–4) tetap lolos `/login`, CSRF hilang → `419` |
| **C** | `PinThrottle`: penghitungan, ambang → `429`, sisa detik, kunci bertahan lintas sesi (cookie baru tetap terkunci), `clear()` pada sukses, `prune()` membuang berkas tua, fallback sesi + `Logger::warning` saat direktori read-only, berkas tidak berada di `public/` |
| **D** | Isolasi blind (HTTP): body dasbor/laporan/CSV mitra A vs mitra B; payload `wallet_id` milik B pada `POST /investor/withdrawal` diabaikan (dompet A yang berubah, B utuh); `_once` ganda → tanpa tulisan; tanpa login → `302 /login`; operator → `302 /dashboard` |
| **E** | Guard peran & pengalihan sadar peran: matriks lengkap (GET/POST × investor/operator/anonim × `/dashboard`, `/investor/*`, `/operator/*`), `home()` sadar peran, `logout()` mendarat di pintu yang benar |
| **F** | Penarikan investor: perhitungan `notice_period_due` untuk hari Jumat/Sabtu/Senin (hari kerja vs kalender), sisa hari, pengajuan kedua per jenis ditolak saat menjumlah melebihi saldo, pengajuan modal kedua saat `queued` ditolak, pencairan tetap hanya via operator |
| **G** | Undangan sisi operator: `createMitra()` menghasilkan token 64 hex + status `pending`; `issue`/`reset` memutar token (tautan lama mati); investor tidak boleh memanggil route ini; sel tabel menampilkan tautan absolut; tidak ada nilai enum mentah di layar |
| **H** | Ekspor: laporan ramah cetak 200 + angka mitra A saja; CSV (`Content-Type`, `Content-Disposition`, BOM, `;`, jumlah baris, total, konvensi `1.234,56`); tidak ada teks dari klien pada CSV |
| **I** | Pemindaian statis & kontrak view: daftar terlarang § 4.6, `requireRole(Constants::ROLE_INVESTOR)` di setiap method investor, tidak ada `:disabled` pada kontrol form investor, `mis. ` ada, seluruh output dinamis `Security::e()`, tidak ada `number_format(`/`float`/`double` di berkas baru, tanpa SQL di controller/view, route terdaftar, `_once` pada jalur penarikan, `manifest` ditautkan di semua layout, `App::absoluteUrl` dipakai untuk tautan undangan |
| **J** | PWA: `manifest.json` valid (`json_decode`), `start_url`/`scope` relatif, `display`, warna tema; ikon ada + tanda tangan PNG + dimensi IHDR (192/512/180, maskable ber-`purpose: "maskable"`); `sw.js` memuat daftar-putih dan **tidak** memuat pola cache untuk HTML/AJAX (`mode === 'navigate'` selalu jaringan); `offline.html` ada & tanpa data dinamis; registrasi dijaga `https`/`localhost`; total aset PWA < 400 KB |
| **K** | Nol DDL & dokumentasi: 9 tabel tetap, kolom `users` (termasuk kolom audit) tidak berubah, `checkDecimalConventions()` untuk `amount`, `capital_utilized`, `profit_earned`, `balance_capital`, `balance_profit`; `docs/` memuat istilah Fase 5 (PIN, throttle, sekali pakai, PWA, Protokol 5) dan tidak lagi memuat janji "PDF" untuk dasbor; `AGENTS.md`/`README.md` memuat kontrak baru; `.env.example` memuat `APP_PIN_PEPPER` |

### 6.3 Gate penerimaan

`bash tests/run_qc.sh` **exit 0** dengan **11 suite** (urutan: `qc_pdo`, `qc_formatter`, `qc_security`, `qc_seed`, `qc_roadmap_protocol_1`, `qc_fase_2`, `qc_fase_3`, `qc_fase_4`, `qc_fase_5`, `qc_sql_injection`, `qc_routing`), 0 gagal, tanpa `storage/logs/php-*.log`, dan ketiga probe Node lulus. Baseline assertion baru dicatat pada `Summary_Fase_5` dan `AGENTS.md` (target ≥ **580** assertion; perkiraan `qc_fase_5` ≈ 130).

---

## 7. Risiko & Mitigasi

| ID | Risiko | Mitigasi |
|---|---|---|
| R-1 | **PIN bisa di-brute-force lewat pintu lain** (`/login` memverifikasi hash yang sama) | A-5: PIN di-hash ber-pepper sehingga tidak pernah memuaskan `verifyPassword()`; `/login` pun memakai `PinThrottle` yang sama; diuji grup B/C |
| R-2 | **Throttle menghalangi pengguna sah / mengganggu suite lama** (uji "login gagal" Fase 1–4) | Ambang 5/15 menit; `ensureInstalled()` mengosongkan direktori throttle; pesan `429` menyebut sisa waktu; `Logger::warning` pada tiap penguncian |
| R-3 | **`storage/throttle/` read-only di shared hosting** | Fallback penghitung sesi + satu warning, bukan 500; diuji grup C; langkah `chmod` dicatat di README |
| R-4 | **Kebocoran data internal lewat dasbor** (JOIN ke `daily_settlements` yang kaya kolom) | `ProfitDistribution::listForWallet()` memilih kolom eksplisit (`settlement_date` saja) + daftar terlarang § 4.6 + assertion HTTP atas body/CSV (grup D/I) |
| R-5 | **Pengalihan sadar peran memecah suite lama** | A-18: `qc_fase_2/3/4` diperbarui dalam satu commit; `roleLandingPath()` menjadi satu-satunya sumber pemetaan |
| R-6 | **PIN pepper bocor/diputar** → semua PIN mati | Pepper hanya di `.env` (di luar version control), tidak pernah di-log; prosedur pemulihan = `reset` undangan oleh operator |
| R-7 | **Tautan undangan tersebar ulang** (WhatsApp) | Sekali pakai (di-NULL-kan saat tanda tangan) + tombol "Buat ulang tautan" yang mematikan tautan lama; `/join` memakai `Referrer-Policy` yang sudah ada dan tidak memuat aset eksternal |
| R-8 | **Service worker men-cache halaman keuangan** | Daftar-putih aset statis + navigasi selalu jaringan + `offline.html` statis; diuji grup J |
| R-9 | **QC tidak deterministik** karena baris `profit_distributions` sisa suite lain / `created_at` berpresisi detik | Fixture menghapus **seluruh** distribusi lalu menanam baris tetap; urutan "terakhir" memakai `settlement_date DESC, created_at DESC, id DESC`; klamp A-2 |
| R-10 | **Pengajuan penarikan menumpuk** (dua pengajuan dividen > saldo) | Invariant Σ pengajuan terbuka per jenis (A-14b) di dalam transaksi, setelah dompet terkunci; `payWithdrawal()` tetap memeriksa saldo sebagai lapis terakhir |
| R-11 | **Kode mati** pada model/harness baru | Aturan A-19: setiap method publik punya pemanggil atau assertion QC sebelum gerbang akhir |
| R-12 | **QC menulis ke basis data & disk** | Peringatan di `README.md`/`AGENTS.md` (suite menulis kolom onboarding `users`, `withdrawal_requests`, `storage/throttle/`); fixture idempotent; jangan arahkan ke produksi |

---

## 8. Checklist Serah Terima Fase 5

- [ ] Seluruh kriteria § 1.2 terpenuhi dengan bukti perintah (`bash tests/run_qc.sh`, tiga probe Node, `git status`, `php -l`).
- [ ] `docs/` (UI/UX Layar A/D + PWA, PRD § 4.3/§ 5/§ 6/§ 8, Roadmap FASE 5 + Protokol 5, SAD § 3/§ 4.2) dan `AGENTS.md`/`README.md` selaras dengan kode — **tidak ada penyimpangan senyap**; `database/` dan ERD tidak disentuh.
- [ ] `plan/Summary_Fase_5_AurumFlow.md` memuat angka nyata, jumlah assertion, dan item terbuka.
- [ ] `# ADDENDUM` dokumen ini diisi untuk setiap penyimpangan eksekusi + alasannya.
- [ ] Tidak ada kode mati; tidak ada `storage/logs/php-*.log`; `storage/throttle/` tidak ter-commit.

---

## 9. Item Terbuka (perlu keputusan sebelum/di awal eksekusi)

| ID | Item | Rekomendasi |
|---|---|---|
| O-1 | Versi teks waiver tidak tersimpan di basis data (butuh kolom baru) | Catat `WAIVER_VERSION` di dokumen + layar; tambahkan kolom saat Fase 6 mengizinkan DDL |
| O-2 | `reset` undangan menimpa jejak audit lama (riwayat persetujuan tidak diarsipkan) | Diterima untuk Fase 5; arsip persetujuan butuh tabel baru (Fase 6) |
| O-3 | PDF asli buatan peladen & ekspor lengkap operator | Ditunda; Fase 5 memakai laporan ramah cetak + CSV (keputusan pengguna) |
| O-4 | Reset PIN mandiri oleh mitra | Sementara lewat "Buat ulang tautan" operator; alur mandiri menyusul |
| O-5 | Ambang throttle hard-coded (`PinThrottle::*`) | Pertahankan di kode (parameter keamanan, bukan parameter bisnis `system_settings`); ditinjau bila hosting butuh penyesuaian |
| O-6 | Kebijakan PIN lemah (A-4) bisa dianggap terlalu ketat | Mudah dilonggarkan (satu method `pinPolicy()`); mintakan keputusan saat review |
| O-7 | Papan angka PIN masih dapat dilewati (peramban tanpa JS memakai input biasa) | Diterima: papan angka adalah peningkatan UX, bukan kontrol keamanan |
| O-8 | Mode alokasi `prorata` (warisan O-1 Fase 4) | Tetap ditunda |
| O-9 | Kalender hari libur nasional untuk H-7 (warisan O-2 Fase 4) | Tetap hari kerja (Sabtu/Minggu dilewati) |
| O-10 | Notifikasi otomatis tautan undangan | Tidak ada kanal pengiriman; operator menyalin tautan manual |

---

## 10. Lampiran — Alur Request & Penambahan di Luar SAD

### 10.1 Alur `POST /join`

```
Front controller: POST → verifikasi CSRF (_token) → dispatch InvestorOnboardingController::submit
  1. baca token (POST, hidden) + pin + pin_confirm
  2. OnboardingService::claim()
       Database::transaction()
         a. User::lockByInviteTokenForUpdate(token)      ← baris users dikunci lewat token
         b. validasi: token ada, is_active = 1, onboarding_signed_at IS NULL
         c. OnboardingService::pinPolicy(pin, pin_confirm)
         d. User::completeOnboarding(user_id, pin, clientIp(), ua 512)
              password_hash (ber-pepper) + onboarding_signed_at + signed_ip_address
              + signed_user_agent + invite_token = NULL
  3. Logger::info('Onboarding mitra selesai', ['user_id' => …, 'ip' => …])
  4. PRG → /investor/login + flash
```

### 10.2 Alur `POST /investor/withdrawal`

```
Front controller: POST → CSRF → dispatch InvestorController::withdrawal
  1. requireRole('investor')                            → gagal: 302 /investor/dashboard
  2. `_once` (Security::FORM_FIELD)                     → DUPLICATE: PRG + "ganda diabaikan"
                                                        → INVALID  : 419
  3. baca jenis (profit|capital), nominal, catatan      ← dompet TIDAK dibaca dari form
  4. InvestorService::requestWithdrawal(session user, input)
       a. CapitalWallet::findByUserId(session user_id)
       b. CapitalService::requestWithdrawal([... 'notice_days' => 0 bila profit])
            transaction: dompet FOR UPDATE → saldo − Σ pengajuan terbuka ≥ nominal
            → (capital) withdrawal_status='queued'
            → WithdrawalRequest::insert(status pending, notice_period_due)
  5. PRG → /investor/dashboard + flash (nominal, jenis, jatuh tempo)
```

### 10.3 Penambahan di luar daftar eksplisit SAD § 3

| SAD § 3 menyebut | Kenyataan Fase 5 | Alasan |
|---|---|---|
| `InvestorController.php` (dasbor + penarikan) | Dipertahankan; **ditambah** `InvestorAuthController.php` & `InvestorOnboardingController.php` | Mengikuti SAD untuk dasbor/penarikan; dua permukaan baru (PIN & waiver) layak punya controller sendiri, sejajar `AuthController` |
| `ExportController.php` (ekspor kas lengkap) | Dipakai untuk ekspor **investor** lebih dulu | Satu berkas sesuai SAD; cakupan operator menyusul (O-3) |
| — | `Services/{OnboardingService,InvestorService}.php` | Use-case beraturan sendiri (klaim token, agregasi blind) — bukan helper murni (pola `CapitalService`) |
| — | `Helpers/PinThrottle.php` | Infrastruktur keamanan, bukan use-case bisnis; ditempatkan bersama `Security`/`Logger` |
| — | `public/{manifest.json,sw.js,offline.html,assets/icons/}` | SAD § 3 memang mencantumkannya; Fase 5 hanya mewujudkannya |

---

# ADDENDUM — Catatan Eksekusi

*(Diisi selama eksekusi: setiap penyimpangan dari rencana di atas dicatat di sini beserta alasannya, sesuai `AGENTS.md`.)*

**Status: belum dieksekusi. Belum ada berkas kode yang ditulis.**

---

# ADDENDUM — Catatan Eksekusi

*(Setiap penyimpangan dari rencana di atas dicatat di sini beserta alasannya, sesuai `AGENTS.md`.)*

**Status: Fase 5 dieksekusi penuh. `bash tests/run_qc.sh` exit 0 — 11/11 suite,
0 gagal, 598 assertion (per suite pada § A-3). Laporan lengkap:
`plan/Summary_Fase_5_AurumFlow.md`.**

## A-1. Penyimpangan dari rencana

| Rencana | Kenyataan | Alasan |
|---|---|---|
| § 5 T9 menyebut dua fixture: `seedQcInvestor()`, `resetInvestorState()` | Fixture dipecah menjadi **`seedQcInvestor()`** (mitra sudah onboarding: PIN aktif, jejak audit terisi, dompet Rp 20 juta + empat putaran) dan **`seedQcInvitee()`** (calon mitra: tautan aktif, belum tanda tangan) | Satu akun tidak dapat sekaligus "Aktif" (untuk uji PIN/dasbor) dan "Menunggu onboarding" (untuk uji klaim + label status). Memaksa keduanya lewat satu fungsi membuat urutan uji saling merusak |
| § 4.2 dan § 6.2 grup C: penurunan mutu `PinThrottle` diuji di proses QC yang sama | Diuji pada **proses terpisah** (`qc_run_php()` + `SESSION_SAVE_PATH` di `tests/.tmp`) | Jalur fallback sesi hanya hidup bila ada session; membuka session di tengah proses QC yang sudah mencetak akan mengotori keluaran, sedangkan proses terpisah justru meniru request sungguhan |
| § 3.2 menyebut `ExportController` dengan "ekspor operator menyusul" | Diterapkan apa adanya: berkas berisi `report()` + `csv()` untuk **investor**; ekspor operator tetap item terbuka O-3 | Nama berkas mengikuti SAD § 3, cakupan mengikuti ruang lingkup fase |
| § 5 T6: satu `guard()` untuk seluruh method `InvestorController` | Method dipecah: `requireInvestor()` (peran saja, untuk render GET) + `guard()` (peran + CSRF) + `guardOnce()` (`_once`) | Halaman GET tidak membawa `_POST['_token']`, sehingga penjaga versi POST menolak render pertama dengan `419`. Pola `CapitalController::renderCapital()` (requireRole langsung) tidak dapat disalin persis karena controller investor juga memverifikasi CSRF di dalam `guard()` |
| § 6.2 grup A: "atomisitas (paksa gagal → token & hash utuh)" | Diuji dengan dua jalur **tanpa injeksi paksa**: (a) PIN lemah ditolak sebelum penulisan apa pun (A.8), (b) klaim ulang dengan token yang sudah hangus ditolak tanpa tulisan (P5.3) | Tidak ada seam yang aman untuk memaksa kegagalan di tengah `Database::transaction()` tanpa menambah kode uji ke jalur produksi; kedua kasus menutup risiko yang dimaksud (penulisan parsial) |
| § 5 T9 memperkirakan `qc_fase_5` ≈ 130 assertion | Terealisasi **125** | Empat assertion digabung (matriks rute + pemindaian statis) agar tiap label tetap bermakna |
| § 6.1 tabel protokol memakai angka dividen bulan berjalan Rp 304.341 | Terealisasi **Rp 154.341** pada tanggal uji | Baris putaran −1 dan −2 berada di bulan sebelumnya (uji dijalankan 1 Oktober); suite menghitung harapannya dari tanggal baris, bukan angka mati — lihat assertion `R.5` |
| § 2.2 A-8 menyebut `reset` untuk onboarding ulang | Diterapkan, **tetapi** tombol pada Layar E hanya muncul setelah mitra berstatus `Aktif`; status `Menunggu` menampilkan "Putar ulang tautan" | Menghindari operator menekan "Buat ulang" pada tautan yang belum pernah dikirim |

## A-2. Temuan yang perlu diingat pada fase berikutnya

1. **`APP_PIN_PEPPER` wajib diisi pada deployment lama.** Instalasi yang sudah
   berjalan harus menambahkan variabel itu sebelum mitra pertama membuat PIN;
   tanpa pepper, halaman onboarding menolak dengan pesan "Hubungi pengelola"
   (detail aslinya hanya di log) dan `Security::hashPin()` melempar.
2. **Session di CLI.** `PinThrottle` memakai session sebagai fallback; pada
   proses CLI, session belum tentu aktif (`QC` membuka session di subprocess
   probe). Jangan menguji jalur itu di proses QC utama.
3. **`app/Config/App.php` menyimpan `pin_pepper` apa adanya.** Nilai kosong
   sengaja dibiarkan sampai lapisan yang MEMBUAT PIN, supaya operator tetap
   dapat masuk dan memperbaiki konfigurasi (bukan terkunci di luar sistem).
4. **Pembersihan data QC harus berurutan.** Fase 5 menambah pemilik dompet bagi
   akun uji, sehingga `DELETE FROM users` di `qc_seed` (dan fixture lain) wajib
   membuang `profit_distributions` → `withdrawal_requests` → `capital_wallets`
   lebih dulu.
5. **Kunci throttle lintas suite.** `QcFixture::ensureInstalled()` kini
   mengosongkan `storage/throttle/`: tanpa itu, uji "login gagal" di suite
   berikutnya dapat mewarisi kunci 15 menit dari suite sebelumnya.

## A-3. Baseline akhir

`bash tests/run_qc.sh` → **exit 0**, `11/11` suite (`qc_pdo` 20, `qc_formatter` 43,
`qc_security` 32, `qc_seed` 46, `qc_roadmap_protocol_1` 6, `qc_fase_2` 66,
`qc_fase_3` 98, `qc_fase_4` 112, `qc_fase_5` **129**, `qc_sql_injection` 15,
`qc_routing` 31), **598 assertion, 0 gagal**. Probe Node: `js_form_init_check`
39/39, `js_capital_simulator_check` 38/38, `js_investor_check` **43/43**.

## A-4. Perubahan wajib pada berkas fase sebelumnya (disengaja)

| Berkas | Perubahan | Penjaga |
|---|---|---|
| `app/Controllers/BaseController.php` | `roleLandingPath()` (satu pemilik peran → beranda); `requireRole()`/`requireGuest()` memakainya | `qc_fase_5` E.*, suite Fase 2–4 |
| `app/Controllers/AuthController.php` | `PinThrottle` pada login gagal; landing & logout sadar peran | `qc_routing`, `qc_fase_5` B.* |
| `app/Controllers/DashboardController.php` | `index()` kini `requireRole(operator)`; `home()` sadar peran | `qc_routing`, `qc_fase_5` E.2/E.4 |
| `app/Services/CapitalService.php` | Token undangan otomatis pada `createMitra()`; `notice_days` opsional (hanya dividen); invariant Σ pengajuan terbuka; `issueInvite()` | `qc_fase_4`, `qc_fase_5` G.*/F.* |
| `app/Models/CapitalWallet.php` | `listWithUser()` membawa `invite_token` + `onboarding_signed_at` (layar operator) | `qc_fase_4` H.*, `qc_fase_5` G.2 |
| `tests/qc_fase_2.php`, `qc_fase_3.php`, `qc_fase_4.php` | Investor → `/investor/dashboard`; sumber CSRF uji penyusupan dari dasbor mitra | suite terkait |
| `tests/qc_fase_4.php` | Assertion `H.9` (7 → **8** jalur `Database::transaction()` di `CapitalService`) | `qc_fase_4` |
| `tests/qc_seed.php` | Pembersihan sisa data QC membuang baris anak lebih dulu (FK RESTRICT) | `qc_seed` |
| `tests/bootstrap.php` | Fixture Fase 5 + `ensureInstalled()` mengosongkan throttle | seluruh suite |
