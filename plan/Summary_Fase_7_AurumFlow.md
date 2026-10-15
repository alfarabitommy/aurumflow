# Summary Fase 7 — Modul Kasir Meja Bakar (*Strict Role Separation*) — AurumFlow

> **Laporan Eksekusi**
> Proyek: **AurumFlow** — sistem manajemen operasional privat untuk perdagangan emas aluvial dengan settlement **T+1**.
> Fase: **7** — rencana: `plan/Plan_Fase_7_Kasir_AurumFlow.md` (disetujui, `dec-a9b2beabe65ecde4`).
> Status: **`EXECUTED`** — T1–T12 selesai. `bash tests/run_qc.sh` **exit 0** (13/13 suite, **782 assertion**, 0 gagal) dan **4 probe Node lulus**.
> Penyimpangan dari rencana: `# ADDENDUM` pada dokumen rencana.

| Item | Nilai |
|---|---|
| Runtime | Native PHP **8.2+**, tanpa framework, tanpa Composer/`vendor/` |
| Frontend | Tailwind CSS 3.4.19 + Alpine.js 3.17.4 (aset lokal) — **`app.js`/`app.min.css` TIDAK berubah**, `CACHE_NAME` tetap `aurumflow-shell-v2` |
| Database | 10 tabel, **nol tabel baru, nol kolom baru**; satu `ALTER TABLE users MODIFY COLUMN role ENUM('operator','investor','cashier')` aditif di ujung daftar (migrasi `database/migrate.php`, idempoten) |
| Peran | `operator` · `investor` · **`cashier`** (baru) |
| Suite QC | 13 suite / **782 assertion** / 0 gagal (`qc_fase_7`: 77 assertion) |
| Probe Node | 47 + 38 + 45 + 65 assertion, 0 gagal |

---

## 1. Ringkasan hasil

Fase 7 memisahkan **kasir meja bakar** dari pemilik pembukuan tanpa menyentuh satu pun aritmetika
uang Fase 2–6:

1. **Peran ketiga yang aditif.** `users.role` kini `ENUM('operator','investor','cashier')` —
   nilai baru **di ujung** daftar (bukan penyisipan yang memetakan ulang indeks ordinal). Nol tabel
   baru, nol kolom baru; instalasi lapangan menjalankan `php database/migrate.php`
   (`User::ensureRoleEnum()`, terjaga `INFORMATION_SCHEMA`, idempoten, tidak menyentuh
   `storage/installed.lock`).
2. **Pintu PIN kasir `/cashier/login`.** Nomor telepon + PIN 6 digit, papan angka Alpine
   (`investorLogin`, komponen Fase 5 yang sudah terdaftar → tanpa komponen baru, tanpa kenaikan
   `CACHE_NAME`), pembatas percobaan `PinThrottle`, pesan gagal generik.
3. **Satu pemilik aturan pintu PIN.** `Services/PinLoginService.php` dipakai pintu mitra **dan**
   pintu kasir (urutan: isian kosong → throttle → bentuk PIN → `verifyPin()` + gerbang peran).
   Perilaku pintu mitra tidak berubah — dibuktikan suite `qc_fase_5` yang tetap 130/130.
4. **Isolasi *allow-list* + guard-first.** Hanya `GET /operator/teller`, `POST /operator/teller`,
   dan `POST /operator/teller/talangan` menerima `cashier` (`TellerController::ALLOWED_ROLES`).
   Seluruh controller lain memakai `requireRole(ROLE_OPERATOR)`/`(ROLE_INVESTOR)` dan **tidak
   pernah** mengizinkan `ROLE_CASHIER`; `requireRole(...)` adalah pernyataan pertama setiap method,
   sehingga rute terlarang dijawab `302` ke `/operator/teller` **tanpa tulisan** dan **tanpa
   mengonsumsi token `_once`** (GET maupun POST).
5. **Tata kelola akun kasir.** Layar **"Kelola Kasir"** (`GET /operator/kasir`, `POST
   /operator/kasir`, `/pin`, `/status`) dengan CSRF + `_once` + PRG, kebijakan PIN dipakai ulang
   (`OnboardingService::pinPolicy()`), dan gagal-tertutup saat `APP_PIN_PEPPER` kosong.
6. **Permukaan kasir bebas data pemilik.** Navigasi sadar peran; saldo `Brankas Utama`, bagi hasil,
   omzet, dompet mitra, dan angka `system_settings` tidak pernah dirender pada permukaan kasir —
   dibuktikan kanari angka + daftar terlarang (bukan asumsi).

---

## 2. Yang dibangun

### 2.1 Berkas baru

| Berkas | Peran |
|---|---|
| `app/Controllers/CashierAuthController.php` | Pintu PIN kasir (`showLogin`, `login`), layout `auth` |
| `app/Controllers/CashierController.php` | Layar **Kelola Kasir** (`index`, `store`, `resetPin`, `setStatus`) — `requireRole(operator)` di setiap method |
| `app/Services/PinLoginService.php` | Satu pemilik aturan pintu PIN (mitra + kasir) |
| `app/Services/CashierService.php` | Use-case tata kelola akun kasir (satu transaksi per aksi, PIN ber-pepper, tanpa `capital_wallets`) |
| `app/Views/cashier/login.php` | Form PIN kasir + papan angka (`x-data="investorLogin(…)"`, `x-cloak`) |
| `app/Views/operator/kasir.php` | Daftar kasir + buat akun + reset PIN + aktif/nonaktif |
| `database/migrate.php` | Migrasi aditif CLI (gate `INSTALL_TOKEN`, idempoten, tanpa instalasi ulang) |
| `tests/qc_fase_7.php` | Protokol QC Manual 7 (grup A–G, 77 assertion) |
| `plan/Plan_Fase_7_Kasir_AurumFlow.md`, `plan/Summary_Fase_7_AurumFlow.md` | Rencana + laporan ini |

### 2.2 Berkas yang berubah (ringkas)

| Berkas | Perubahan |
|---|---|
| `docs/` (ERD, SAD, Roadmap, PRD, UI/UX) | ENUM tiga nilai + aturan *append-only*; pohon direktori & catatan penempatan; **FASE 7 + Protokol QC Manual 7**; matriks hak akses tiga peran + Modul 6; Layar A3 (pintu PIN kasir) + navbar sadar peran |
| `database/schema.sql`, `database/install.php` | ENUM tiga nilai; langkah penyelarasan ENUM + verifikasi tiga peran pada laporan instalasi |
| `app/Config/Constants.php` | `ROLE_CASHIER`, `ROLE_LABELS`, `roleLabel()` (satu pemilik label peran) |
| `app/Models/User.php` | `ROLE_ENUM_VALUES`, `roleEnumValues()`, `needsRoleEnumExtension()`, `ensureRoleEnum()`, `createWithPin()`, `updatePin()`, `setActive()`, `listByRole()`, `findByIdAndRole()` |
| `app/Controllers/BaseController.php` | `startUserSession()`, `roleLoginPath()`, `roleLandingPath()` + kasir, `$auth` disuntikkan `render()` |
| `app/Controllers/AuthController.php` | Menolak akun `cashier` di pintu kata sandi; memakai `startUserSession()`/`roleLoginPath()` |
| `app/Controllers/InvestorAuthController.php` | Adaptor tipis di atas `PinLoginService` (perilaku identik) |
| `app/Controllers/TellerController.php` | `ALLOWED_ROLES = [operator, cashier]` pada keempat titik guard |
| `app/Controllers/InvestorOnboardingController.php` | `refuseCashier()` — sesi kasir tidak pernah melihat permukaan onboarding mitra |
| `app/Controllers/DashboardController.php` | Label peran pindah ke `Constants::roleLabel()`; tidak lagi menyusun identitas sendiri |
| `app/routes.php` | 6 entry baru (pintu kasir + Kelola Kasir) |
| `app/Views/layouts/operator.php` | Navbar sadar peran + identitas `$auth` |
| `app/Views/layouts/app.php`, `app/Views/dashboard/index.php` | Membaca `$auth` |
| `app/Views/operator/teller.php` | Kalimat footer modal talangan tidak lagi memuat angka bagi hasil |
| `tests/bootstrap.php` | `ensureInstalled()` menyelaraskan ENUM; fixture kasir (`QC_CASHIER`, `QC_CASHIER_SECOND`, `seedQcCashier()`, `resetCashierState()`, `purgeQcCashierAccounts()`, `cashierSnapshot()`) |
| `tests/qc_fase_5.php` | Pemindai view + pemetaan layout mencakup `cashier/` |
| `tests/qc_seed.php` | Assertion ENUM menerima `cashier` (aditif) |
| `tests/run_qc.sh` | Suite ke-13 + judul/log Fase 7 |
| `tests/manual/js_bridge_fee_check.js` | Ekspektasi kondisi peringatan diselaraskan (lihat ADDENDUM A-3) |
| `AGENTS.md`, `README.md` | Peran, kontrak baru, perintah (`php database/migrate.php`, `php tests/qc_fase_7.php`), tabel suite, status fase |

**Tidak berubah (disengaja):** `public/assets/js/app.js`, `public/assets/js/alpine.min.js`,
`public/assets/css/app.min.css`, `public/sw.js`, `tailwind.config.js`, `database/seed.sql`,
seluruh service/aritmetika uang Fase 2–6, dan seluruh berkas investor.

---

## 3. Bukti gerbang mutu

```
bash tests/run_qc.sh
LULUS : 13/13  qc_pdo qc_formatter qc_security qc_seed qc_roadmap_protocol_1 qc_fase_2
               qc_fase_3 qc_fase_4 qc_fase_5 qc_fase_6 qc_fase_7 qc_sql_injection qc_routing
GAGAL : 0/13
```

| Suite | Assertion |
|---|---:|
| `qc_pdo` | 20 |
| `qc_formatter` | 43 |
| `qc_security` | 32 |
| `qc_seed` | 47 (**+1**: ENUM menerima `cashier`) |
| `qc_roadmap_protocol_1` | 6 |
| `qc_fase_2` | 66 |
| `qc_fase_3` | 98 |
| `qc_fase_4` | 112 |
| `qc_fase_5` | 130 (tidak berubah — pemindai diperluas, perilaku pintu mitra utuh) |
| `qc_fase_6` | 105 |
| **`qc_fase_7`** | **77 (baru)** |
| `qc_sql_injection` | 15 |
| `qc_routing` | 31 |
| **Total** | **782** (semula 704) |

Probe lapis Alpine (butuh Node; di luar `run_qc.sh`):

| Probe | Assertion | Catatan |
|---|---:|---|
| `js_form_init_check` | 47 | lulus |
| `js_capital_simulator_check` | 38 | lulus |
| `js_investor_check` | 45 | lulus — komponen `investorLogin` yang dipakai pintu kasir tetap teruji |
| `js_bridge_fee_check` | 65 | lulus (ekspektasi diselaraskan: `bridgeWarnUncovered`) |

### 3.1 Cakupan Protokol QC Manual 7 (grup `qc_fase_7`)

| Grup | Assertion | Isi |
|---|---:|---|
| **A** | 8 | ENUM tiga nilai & urutan *append-only*; predikat migrasi murni; `migrate.php` idempoten; nilai asing tetap ditolak; **12 kolom** `users` (nol kolom baru); empat sumber ENUM sepakat (ERD, `schema.sql`, DDL model, basis data); baris lama utuh; kasir tanpa dompet modal |
| **B** | 14 | Kelola Kasir: operator 200 / anonim 302 / kasir 302; pembuatan akun tanpa jejak waiver; PIN ber-pepper dua arah; **fail-closed** tanpa pepper (nol baris); PIN lemah & konfirmasi salah; nomor duplikat tidak menulis ulang akun lama; nama/nomor tidak sah; reset PIN; aktif/nonaktif (login ditolak saat nonaktif); `_once` wajib & anti submit ganda; jejak log tanpa PIN |
| **C** | 13 | Pintu kasir: form + papan angka + `x-cloak`; PIN tidak pernah dirender; 302 ke `/operator/teller`; pesan generik; 422; **429** (kunci) & PIN benar tetap ditolak saat terkunci; gerbang peran (operator & mitra 401, nomor tak dikenal 401); PIN kasir tidak sah di `/login` dan `/investor/login`; CSRF 419; `requireGuest()`; logout kembali ke `/cashier/login` |
| **D** | 16 | **Inti isolasi**: kasir 200 di meja kasir, POST bayar tunai & talangan benar-benar menulis; tujuh rute terlarang GET → 302 tanpa tulisan; POST settlement → 302 tanpa tulisan **dan `_once` tetap utuh**; enam POST kelola modal; tiga POST kelola kasir; `/dashboard` tanpa isi `system_settings`; `/` → beranda kasir; anonim & mitra tetap seperti semula; operator lima layar 200; forge peran via form tidak berpengaruh; `/join` tertutup; 404 tanpa stack trace |
| **E** | 8 | Permukaan kasir tanpa kata `Brankas`, tanpa kanari `Rp 77.777.777` (Brankas disetel khas lalu dipulihkan), tanpa href ke rute pemilik, tanpa label menu pemilik, tanpa istilah laba/omzet; **pasangan positif** operator memuat keempat tautan; izin PO tetap ada (Emas holding, jasa bakar, modul talangan); pemindaian berkas terjangkau kasir |
| **F** | 10 | Enam route Fase 7 valid; satu daftar izin `ALLOWED_ROLES`; controller administratif tidak pernah **mengizinkan** kasir; guard-first (POST, GET, urutan konsumsi `_once`); label peran satu pemilik; tanpa komponen Alpine baru (tepat 5 registrasi); `CACHE_NAME` tetap v2 & `AGENTS.md` sepakat; `migrate.php` CLI-only, gate token, tidak menyentuh marker instalasi |
| **G** | 6 | Pembersihan akun kasir uji tanpa FK; fixture idempotent; keadaan akhir deterministik; `storage/throttle/` bersih; fixture kasir tidak menggeser dompet modal/buku talangan; tanpa `php-*.log` |

---

## 4. Perilaku yang dapat diverifikasi manual

1. Operator → `/operator/kasir` → buat kasir (nama, nomor, PIN 6 digit) → akun tampil; PIN tidak
   pernah tampil setelahnya.
2. `/cashier/login` di ponsel → nomor + PIN lewat papan angka → mendarat di **Meja Hulu**.
3. Kasir menimbang & membayar; kas meja turun, nota bertambah; kasir menarik **Terima Dana
   Talangan**; kas meja naik.
4. Kasir membuka `/operator/settlement`, `/operator/capital`, `/operator/kasir`, `/dashboard`,
   `/investor/dashboard` → semuanya dialihkan kembali ke `/operator/teller`; kas, dompet, dan
   settlement tidak berubah.
5. Navbar kasir hanya **Kasir** + **Keluar**; kata "Brankas" tidak muncul di mana pun.
6. Operator membuka `/login` → seluruh fungsi Fase 2–6 utuh (rekonsiliasi, kelola modal, talangan).
7. Instalasi lama: `php database/migrate.php` → `diterapkan` (sekali) lalu `sudah diterapkan`.

---

## 5. Item terbuka & rekomendasi lanjutan

| # | Item | Rekomendasi |
|---|---|---|
| O-1 | Sesi kasir yang sudah aktif tidak otomatis mati saat akun dinonaktifkan | Tambahkan `users.session_epoch` + validasi per-request (butuh DDL) pada fase berikutnya; prosedur sementara: reset PIN + minta kasir keluar |
| O-2 | Halaman 403 keras (bukan 302) bila PO berubah pikiran | `BaseController::abort(403, …)` sudah tersedia; assertion `D.4`/`D.5a` menetapkan kontrak 302 saat ini |
| O-3 | Rincian jasa bakar (`upah pengelola` + kowi/gas) masih tampil bagi kasir | Disengaja: itu komposisi harga yang dibayar penambang, bukan angka laba pemilik |
| O-4 | Satu orang yang sekaligus mitra dan kasir | Pakai dua nomor telepon (UNIQUE `uq_users_phone`) |
| O-5 | Ganti PIN wajib pada login pertama kasir | Belum ada; operator dapat mereset PIN kapan saja |
| O-6 | Kuota/jumlah kasir & jadwal shift | Di luar lingkup Fase 7 |
| O-7 | Audit PIN ke tabel (bukan hanya `Logger`) | Tunda; log harian sudah memuat pembuatan/reset/deaktivasi tanpa PIN |
| O-8 | Pintu `/login` masih menerima peran apa pun yang hash-nya cocok (kini hanya menolak `cashier`) | Batasi ketat ke `operator` pada fase berikutnya; saat ini berisiko memutus akun mitra lama yang dibuat dengan kata sandi |

---

## 6. Definition of Done — status

- [x] `GET /cashier/login` 200 (form + papan angka `x-cloak`, tanpa nilai PIN, layout `auth` ber-Alpine).
- [x] `POST /cashier/login` → 302 `/operator/teller`; 401/422/429 sesuai aturan; dua pintu lain menolak PIN kasir.
- [x] Kasir 200 di meja kasir; bayar tunai & talangan benar-benar menulis; anonim/mitra tetap seperti semula.
- [x] Tujuh rute terlarang (GET+POST) → 302 `/operator/teller`, snapshot identik, `_once` tidak dikonsumsi.
- [x] Logout kasir → `/cashier/login`.
- [x] Permukaan kasir tanpa `Brankas`, kanari saldo brankas, bagi hasil, maupun tautan pemilik.
- [x] Layar Kelola Kasir lengkap (buat, reset PIN, aktif/nonaktif) + CSRF + `_once` + PRG; fail-closed tanpa pepper; kasir tanpa dompet.
- [x] `database/schema.sql` + ERD tiga nilai (urutan `operator,investor,cashier`); `php database/migrate.php` idempoten; baris lama utuh.
- [x] `bash tests/run_qc.sh` exit 0 — **13 suite, 782 assertion**, 0 gagal; tanpa `storage/logs/php-*.log`.
- [x] `docs/` diperbarui lebih dahulu (T1); addendum rencana memuat setiap penyimpangan.
- [x] `git status` hanya memuat berkas yang direncanakan (artefak QC ada di `tests/.tmp/` & `storage/throttle/` yang di-gitignore).

---

## 7. Perintah yang relevan setelah Fase 7

```bash
bash tests/run_qc.sh            # gerbang: 13 suite, 782 assertion, exit 0
php tests/qc_fase_7.php         # hanya isolasi peran kasir
php database/migrate.php        # instalasi lapangan (ENUM users.role) — idempoten
node tests/manual/js_bridge_fee_check.js   # probe lapis Alpine (di luar run_qc.sh)
```
