# AurumFlow

Sistem manajemen operasional privat untuk **perdagangan emas aluvial** dengan
settlement **T+1**.

Repositori ini memuat Fase 1 (Fondasi Sistem, Basis Data, & Arsitektur Inti),
Fase 2 (Meja Kasir Hulu & Arus Kas Lapangan), dan Fase 3 (Likuidasi Toko &
Mesin Settlement Harian) beserta dokumen spesifikasi otoritatifnya.

| Dokumen | Peran |
|---|---|
| [`plan/Plan_Fase_1_AurumFlow.md`](plan/Plan_Fase_1_AurumFlow.md) | Rencana eksekusi Fase 1 + addendum rekonsiliasi |
| [`plan/Summary_Fase_1_AurumFlow.md`](plan/Summary_Fase_1_AurumFlow.md) | **Laporan eksekusi** Fase 1 |
| [`plan/Plan_Fase_2_AurumFlow.md`](plan/Plan_Fase_2_AurumFlow.md) | Rencana eksekusi Fase 2 (meja kasir hulu) |
| [`plan/Summary_Fase_2_AurumFlow.md`](plan/Summary_Fase_2_AurumFlow.md) | **Laporan eksekusi** Fase 2 |
| [`plan/Plan_Fase_3_AurumFlow.md`](plan/Plan_Fase_3_AurumFlow.md) | Rencana eksekusi Fase 3 (likuidasi toko & settlement) |
| [`plan/Summary_Fase_3_AurumFlow.md`](plan/Summary_Fase_3_AurumFlow.md) | **Laporan eksekusi** Fase 3 |
| [`plan/Plan_Fase_4_AurumFlow.md`](plan/Plan_Fase_4_AurumFlow.md) | Rencana eksekusi Fase 4 (mesin alokasi modal & likuiditas) |
| [`plan/Summary_Fase_4_AurumFlow.md`](plan/Summary_Fase_4_AurumFlow.md) | **Laporan eksekusi** Fase 4 |
| [`plan/Plan_Fase_5_AurumFlow.md`](plan/Plan_Fase_5_AurumFlow.md) | Rencana eksekusi Fase 5 (dasbor blind, waiver digital, PWA) |
| [`plan/Summary_Fase_5_AurumFlow.md`](plan/Summary_Fase_5_AurumFlow.md) | **Laporan eksekusi** Fase 5 |
| [`plan/Plan_Fix_Settlement_UI.md`](plan/Plan_Fix_Settlement_UI.md) | Perbaikan UX & pengiriman form layar rekonsiliasi/kasir (nilai awal Alpine, placeholder, assertion kontrak form) |
| [`plan/Summary_Fix_Settlement_UI.md`](plan/Summary_Fix_Settlement_UI.md) | **Laporan eksekusi** perbaikan UX & pengiriman form layar rekonsiliasi/kasir |
| [`plan/Summary_Fix_Disabled_Inputs.md`](plan/Summary_Fix_Disabled_Inputs.md) | **Laporan eksekusi** akar `422` "wajib diisi": kontrol `disabled` hilang dari payload form |
| `docs/AurumFlow Entity Relationship Diagram.md` | **Sumber kebenaran skema database** (ERD §3.1 tipe data, §4 DDL + seed) |
| `docs/AurumFlow System Architecture Document.md` | Struktur direktori, tech stack, aturan keamanan |
| `docs/AurumFlow Roadmap and QC Documents.md` | Isi per fase + protokol QC |
| `docs/AurumFlow Product Requirement Document v3.0.md` | Aturan bisnis & hak akses |
| `docs/AurumFlow UI UX Documents.md` | Tema "Obsidian Gold" & wireframe layar |

> **Status: Fase 1, Fase 2, Fase 3, & Fase 4 selesai dan terverifikasi** — 10 suite QC,
> **469 assertion, 0 gagal**.
> Waterfall Engine, penarikan dana, dan dasbor investor masuk Fase 4–5.

---

## 1. Ringkasan teknologi

| Aspek | Nilai |
|---|---|
| Runtime | Native PHP **8.2+** (diuji pada 8.3.6), tanpa framework |
| Database | **MySQL 8.0** / InnoDB untuk produksi |
| Autoloading | Manual (`spl_autoload_register`), nol beban framework |
| Konfigurasi | File `.env` dengan override dari environment variable |
| Arsitektur | MVC ringkas + front controller, document root hanya `public/` |
| Styling | Tailwind CSS **v3** (CLI, dikompilasi lokal; hasil di-commit) — tema "Obsidian Gold" |
| Reaktivitas | Alpine.js **v3** sebagai aset lokal (`public/assets/js/alpine.min.js`) |

Target: konsumsi memori < 5 MB per request, tanpa background worker — semua
kalkulasi dijalankan sinkron di dalam transaksi PDO (SAD §1).

---

## 2. Prasyarat

- PHP 8.2+ dengan ekstensi `pdo_mysql`, `openssl`, `json`, `session`, `ctype`,
  `filter`. Disarankan `curl` (untuk script QC) dan `fileinfo`.
- MySQL 8.0 (atau MariaDB 10.x+ untuk pengembangan).
- Webserver dengan dukungan `.htaccess` (Apache). Untuk nginx, aturan rewrite
  harus ditulis ulang manual.
- Git.

---

## 3. Struktur direktori

```text
aurumflow/
├── .env.example              # template konfigurasi (di-commit)
├── .env                      # konfigurasi nyata (TIDAK di-commit)
├── .htaccess                 # deny app/ database/ storage/ tests/ resources/ (defense in depth)
├── tailwind.config.js        # konfigurasi Tailwind (token "Obsidian Gold")
├── plan/                     # dokumen perencanaan & laporan eksekusi
│   ├── Plan_Fase_1_AurumFlow.md
│   ├── Summary_Fase_1_AurumFlow.md
│   ├── Plan_Fase_2_AurumFlow.md
│   ├── Summary_Fase_2_AurumFlow.md
│   ├── Plan_Fase_3_AurumFlow.md
│   ├── Summary_Fase_3_AurumFlow.md
│   ├── Plan_Fase_4_AurumFlow.md
│   ├── Summary_Fase_4_AurumFlow.md
│   ├── Plan_Fase_5_AurumFlow.md
│   ├── Summary_Fase_5_AurumFlow.md
│   ├── Plan_Fase_6_Talangan_AurumFlow.md
│   ├── Summary_Fase_6_AurumFlow.md
│   └── Summary_UX_Fix.md     # perbaikan UX kolom kuota + determinisme dompet QC
├── docs/                     # 5 dokumen spesifikasi otoritatif
├── resources/css/            # sumber Tailwind (TIDAK disajikan ke peramban)
│   └── tailwind.input.css
├── app/                      # KODE INTI — di luar document root
│   ├── Config/
│   │   ├── Env.php           # parser .env
│   │   ├── App.php           # nama, base URL, timezone, kebijakan session
│   │   ├── Constants.php     # konstanta kode (peran, konvensi tampilan)
│   │   ├── Database.php      # PDO singleton + query helpers + transaksi
│   │   ├── DatabaseException.php
│   │   └── bootstrap.php     # autoloader, error handler, timezone
│   ├── Controllers/
│   │   ├── BaseController.php      # render, redirect, guard (sadar peran), flash
│   │   ├── AuthController.php
│   │   ├── InvestorAuthController.php      # pintu PIN 6 digit mitra (Fase 5)
│   │   ├── InvestorOnboardingController.php # klaim tautan undangan + waiver (Fase 5)
│   │   ├── CapitalController.php   # kelola modal (GET + 6 POST: setoran/mitra/kuota/transfer/penarikan/undangan)
│   │   ├── DashboardController.php # ringkasan operator (operator-only sejak Fase 5)
│   │   ├── ExportController.php    # laporan ramah cetak + CSV investor (Fase 5)
│   │   ├── InvestorController.php  # dasbor blind + penarikan mandiri (Fase 5)
│   │   ├── SettlementController.php  # rekonsiliasi toko (GET + POST)
│   │   └── TellerController.php    # meja kasir hulu (GET + 2 POST: bayar tunai, dana talangan)
│   ├── Helpers/
│   │   ├── Security.php      # XSS, password & PIN ber-pepper, CSRF, token `_once`, UUID, session
│   │   ├── Formatter.php     # Rupiah, gramatur 3 desimal, persen
│   │   ├── PinThrottle.php   # pembatas percobaan login berbasis berkas (Fase 5)
│   │   └── Logger.php        # log file + fallback error_log
│   ├── Models/               # satu-satunya tempat SQL tinggal
│   │   ├── User.php
│   │   ├── SystemSetting.php
│   │   ├── CashRegister.php  # pos kas fisik (meja + brankas)
│   │   ├── HuluTransaction.php
│   │   ├── CashMutation.php
│   │   ├── DailySettlement.php  # sesi tutup buku harian
│   │   ├── CapitalWallet.php    # dompet modal + label tier (Mitra Prioritas/Reguler)
│   │   ├── ProfitDistribution.php  # distribusi pool 20 % per dompet
│   │   ├── WithdrawalRequest.php   # antrean penarikan (H-7 hari kerja)
│   │   └── BridgeLoan.php          # dana talangan operasional (Fase 6)
│   ├── Services/
│   │   ├── TellerService.php      # kalkulasi uang + transaksi atomik kas masuk hulu
│   │   ├── SettlementService.php  # tutup buku: nota, defisit, bagi hasil, alokasi kas, talangan
│   │   ├── AllocationEngine.php   # waterfall & pro-rata (sen integer, sisa terbesar)
│   │   ├── CapitalService.php     # setoran modal, pindah kas, kuota, penarikan
│   │   ├── OnboardingService.php  # klaim undangan, kebijakan PIN, teks waiver (Fase 5)
│   │   ├── InvestorService.php    # view-model blind + penarikan mitra (Fase 5)
│   │   └── BridgeLoanService.php  # fee talangan (nominal/persen), penerimaan kas, pengembalian T+1 (Fase 6)
│   ├── Views/
│   │   ├── layouts/          # app.php, auth.php, operator.php, investor.php, report.php
│   │   ├── auth/login.php
│   │   ├── dashboard/index.php
│   │   ├── investor/login.php       # pintu PIN 6 digit
│   │   ├── investor/onboarding.php  # Layar A: edukasi + waiver
│   │   ├── investor/dashboard.php   # Layar D: dasbor blind
│   │   ├── investor/report.php      # laporan ramah cetak
│   │   ├── operator/teller.php      # kasir + modal "Terima Dana Talangan" (Fase 6)
│   │   ├── operator/settlement.php  # rekonsiliasi & unggah nota terkompresi + kartu talangan
│   │   ├── operator/capital.php     # kelola modal + simulator + antrean + undangan
│   │   └── errors/           # 404.php, 500.php, error.php
│   └── routes.php            # route table (data saja)
├── database/
│   ├── schema.sql            # DDL ERD (10 tabel)
│   ├── seed.sql              # system_settings + cash_registers
│   └── install.php           # runner instalasi (WAJIB dihapus setelah dipakai)
├── public/                   # ← DOCUMENT ROOT
│   ├── index.php             # front controller
│   ├── .htaccess
│   ├── manifest.json         # metadata PWA (Fase 5)
│   ├── sw.js                 # service worker: cache aset statis saja (Fase 5)
│   ├── offline.html          # halaman jatuhan saat offline (Fase 5)
│   ├── assets/css/app.min.css   # hasil kompilasi Tailwind (di-commit)
│   ├── assets/js/alpine.min.js  # Alpine.js (aset lokal, tanpa CDN)
│   ├── assets/js/app.js         # perilaku form + cermin Formatter + komponen kasir/rekonsiliasi/modal/investor + kompresi nota (Canvas) + registrasi SW
│   ├── assets/fonts/            # Plus Jakarta Sans & JetBrains Mono (woff2)
│   ├── assets/icons/            # ikon PWA 192/512/maskable/apple-touch (Fase 5)
│   └── uploads/
│       ├── receipts/         # foto nota toko (nama UUID, isi tidak di-commit)
│       └── .htaccess         # eksekusi skrip diblokir
├── storage/
│   ├── logs/                 # writable, di luar docroot
│   └── throttle/             # pembatas percobaan login (Fase 5; isi tidak di-commit)
└── tests/                    # harness QC + 12 suite
```

**Aturan penting:** hanya isi `public/` yang boleh diakses lewat HTTP.

---

## 3a. Membangun ulang CSS (Tailwind)

`public/assets/css/app.min.css` adalah **hasil kompilasi** dan ikut di-commit,
sehingga server hosting tidak memerlukan Node.js. Setelah mengubah kelas pada
`app/Views/**` atau token pada `tailwind.config.js`, kompilasi ulang:

```bash
npx --yes tailwindcss@3.4.19 \
  -c tailwind.config.js \
  -i resources/css/tailwind.input.css \
  -o public/assets/css/app.min.css --minify
```

Tanpa jaringan/Node, unduh binary standalone `tailwindcss-linux-x64` dari
halaman rilis Tailwind CSS v3 dan jalankan perintah yang sama (letakkan di luar
repositori agar tidak ikut ter-commit).

---

## 4. Instalasi

### 4.1 Siapkan konfigurasi

```bash
cp .env.example .env
```

| Variabel | Keterangan |
|---|---|
| `APP_URL` | URL aplikasi (dipakai juga untuk membentuk tautan undangan mitra) |
| `APP_DEBUG` | `false` di produksi (pesan generik; detail hanya ke log) |
| `APP_PIN_PEPPER` | **Wajib sejak Fase 5.** Kunci rahasia untuk PIN mitra (6 digit). Bila kosong, pembuatan PIN **ditolak** (fail-closed). Jangan diputar setelah mitra onboarding — seluruh PIN lama menjadi tidak sah |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | Kredensial MySQL 8.0 |
| `OPERATOR_FULL_NAME`, `OPERATOR_PHONE`, `OPERATOR_PASSWORD` | Akun operator awal |
| `INSTALL_TOKEN` | Token acak untuk mengizinkan instalasi lewat browser |

Hasilkan `APP_PIN_PEPPER` dengan:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

> **Identitas login adalah nomor telepon** (`users.phone_number`), bukan
> username atau email — sesuai ERD. Tidak ada kolom email pada skema. Operator
> masuk lewat `/login` dengan kata sandi; mitra masuk lewat `/investor/login`
> dengan PIN 6 digit (Fase 5).

Hasilkan token dengan:

```bash
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
```

### 4.2 Siapkan database

Buat database kosong melalui cPanel/phpMyAdmin. Aplikasi **tidak** menjalankan
`CREATE DATABASE` (asumsi A-2).

### 4.3 Arahkan document root

Arahkan document root domain ke folder `public/`. Bila hosting tidak
mengizinkan, `.htaccess` di root proyek menolak akses ke `app/`, `database/`,
`storage/`, dan `tests/`.

### 4.4 Jalankan instalasi

```bash
php database/install.php
```

Atau lewat browser (hosting tanpa CLI):

```
https://domain-anda.com/database/install.php?token=<INSTALL_TOKEN>
```

Instalasi akan: menerapkan `schema.sql` (10 tabel ERD), `seed.sql`
(6 `system_settings` + 2 `cash_registers`), membuat akun operator, dan menulis
marker `storage/installed.lock` — penyiapan akun + verifikasi pos kas
dibungkus dalam **satu transaksi** PDO.

### 4.5 TINDAK LANJUT WAJIB setelah instalasi

1. **HAPUS** `database/install.php` dari hosting.
2. Kosongkan kembali `INSTALL_TOKEN` pada `.env`.
3. Pastikan `storage/` tidak dapat diakses lewat web.
4. Ganti password operator setelah login pertama.

---

## 5. Menjalankan server pengembangan

```bash
# Meniru aturan .htaccess (termasuk penyajian aset & penolakan dotfile):
php -S 127.0.0.1:8000 -t public tests/server_router.php
```

---

## 6. Menjalankan QC

```bash
bash tests/run_qc.sh
```

`run_qc.sh` memakai `set -o pipefail`, jadi suite yang gagal benar-benar
membuat skrip `exit 1` (sebelumnya status pipeline diambil dari `tee` sehingga
kegagalan tersamarkan). Keluaran lengkapnya ditulis ke
`storage/logs/qc-fase5.log`.

Semua suite juga dapat dijalankan sendiri-sendiri:

```bash
php tests/qc_pdo.php
php tests/qc_formatter.php
php tests/qc_security.php
php tests/qc_seed.php
php tests/qc_roadmap_protocol_1.php
php tests/qc_fase_2.php
php tests/qc_fase_3.php
php tests/qc_fase_4.php
php tests/qc_fase_5.php
php tests/qc_fase_6.php
php tests/qc_sql_injection.php
php tests/qc_routing.php
```

| Suite | Assertion | Cakupan |
|---|---:|---|
| `qc_pdo` | 20 | Koneksi, charset, timezone, exception, singleton, presisi DECIMAL, transaksi commit/rollback |
| `qc_formatter` | 43 | Format Rupiah & gramatur (kesamaan string persis), round-trip, locale, tanggal operasi |
| `qc_security` | 32 | XSS escaping, password hashing, CSRF, session, `uuidV4()`, sumber acak |
| `qc_seed` | 46 | Idempotensi DDL/seed, 10 tabel ERD, konvensi DECIMAL, FK, ENUM, guard installer |
| `qc_roadmap_protocol_1` | 6 | **Protokol QC Manual 1** dari dokumen Roadmap (5 baris, 1:1) |
| `qc_fase_2` | 66 | **Protokol QC Manual 2** + kalkulasi uang, parsing input, atomisitas, saldo kurang, anti double-submit, guard peran, pemindaian statis, kontrak form kasir (`type`/`inputmode`/`x-model.fill`, `init()` tanpa `$refs`, kontrol tidak `:disabled`) |
| `qc_fase_3` | 98 | **Protokol QC Manual 3** (3.1 normal, 3.2 hari minus, 3.3 pemulihan defisit) + Smart Auto-Split, mesin defisit, unggah nota (gambar tiruan), atomisitas & berkas yatim, pemindaian statis, kontrak form rekonsiliasi (pre-fill, placeholder contoh, paritas kunci lintas lapis, `init()` tanpa `$refs`, kontrol tidak `:disabled`) |
| `qc_fase_4` | 112 | **Protokol QC Manual 4** (waterfall 50 %/kuota prioritas/pro-rata, distribusi pool 20 % presisi sen, hari minus) + atomisitas setoran & Pindah Kas, antrean penarikan H-7 hari kerja, guard peran & `_once`, pemindaian statis, kontrak form kelola modal |
| `qc_fase_5` | 130 | **Protokol QC Manual 5** (undangan sekali pakai + audit jejak hukum, kebijakan PIN & pepper gagal-tertutup, pembatas percobaan, isolasi blind dengan kanari, penarikan mandiri H-7, ekspor cetak/CSV, PWA, kontrak form & gating sadar peran, bootstrap Alpine pada setiap layout ber-Alpine) |
| `qc_fase_6` | 105 | **Protokol QC Manual 6A** (dana talangan: fee nominal/persen dihitung peladen, penerimaan kas tiga tulisan + atomisitas & `_once`, auto-pay tutup buku dengan satu mutasi `out` per talangan, **isolasi pool mitra dibuktikan diferensial** (dengan vs tanpa talangan), hari minus & `fee_uncovered`, FIFO lintas siklus, penjagaan saldo, tabel ke-10 tanpa ALTER, **regresi UI**: baris fee talangan tidak pernah bergantung pada laba) |
| `qc_sql_injection` | 15 | 8 payload injeksi (model + HTTP), integritas data, pemindaian statis |
| `qc_routing` | 31 | Routing, CSRF, login/logout, cookie, header, proteksi file sensitif, aset |
| **Total** | **704** | **0 FAIL** |

Exit code `0` = seluruh assertion lulus, `1` = ada yang gagal.

Lapis Alpine (nilai awal form operator) tidak dapat dilihat QC PHP — HTML yang
dikirim peladen tetap benar, sedangkan `x-model` baru menghapus nilainya setelah
Alpine berjalan di peramban. Untuk itu tersedia probe Node yang menjalankan
`public/assets/js/app.js` **asli** di atas stub DOM:

```bash
node tests/manual/js_form_init_check.js         # 47 assertion; tidak bagian dari run_qc.sh
node tests/manual/js_capital_simulator_check.js # cermin waterfall JS vs peladen (Fase 4)
node tests/manual/js_investor_check.js          # 45 assertion: papan angka PIN, dasbor mitra, bootstrap Alpine layout, salin tautan, scope SW (Fase 5)
node tests/manual/js_bridge_fee_check.js        # 63 assertion: cermin fee talangan & klamp hak pengelola (Fase 6)
```

`qc_roadmap_protocol_1`, `qc_fase_2`, dan `qc_fase_3` mencetak tabel yang
bentuknya sama dengan tabel protokol pada dokumen Roadmap, sehingga checklist
setiap fase dapat ditandai berdasarkan bukti:

```
| Parameter Uji            | Data Masukan               | Hasil yang Diharapkan            | Status |
| Koneksi PDO              | Database::getConnection()  | Objek PDO aktif, tanpa galat     | [✓]    |
| Pencegahan SQL Injection | ' OR '1'='1                | Lolos sebagai literal, kueri aman| [✓]    |
| Format Gramatur          | 14.85                      | 14,850 gr                        | [✓]    |
| Format Rupiah            | 28937500                   | Rp 28.937.500                    | [✓]    |
| Seed Konfigurasi         | Pembacaan system_settings  | operator_base_quota_pct = 50.00  | [✓]    |

| Parameter Uji                  | Data Masukan                 | Hasil yang Diharapkan    | Status |
| Nilai Tebus Kotor (QC 2.1)     | 14,850 x Rp 1.950.000        | Rp 28.957.500            | [✓]    |
| Kas Keluar Bersih (QC 2.1)     | Rp 28.957.500 - Rp 20.000    | Rp 28.937.500            | [✓]    |
| Sisa Kas Meja (QC 2.1)         | Rp 50.000.000 - Rp 28.937.500| Rp 21.062.500            | [✓]    |
| Nilai Tebus Kotor (QC 2.2)     | 3,200 x Rp 1.950.000         | Rp 6.240.000             | [✓]    |
| Sisa Kas Meja (QC 2.2)         | Rp 21.062.500 - Rp 6.220.000 | Rp 14.842.500            | [✓]    |
| Akumulasi Emas (QC 2.2)        | 14,850 + 3,200               | 18,050 gr (2 nota)       | [✓]    |

| Parameter Uji                | Data Masukan                  | Hasil yang Diharapkan     | Status |
| Total Kas Masuk Toko (3.1)   | 18,050 x Rp 2.080.000         | Rp 37.544.000             | [✓]    |
| Laba Kotor Usaha (3.1)       | Rp 37.544.000 - Rp 35.157.500 | Rp 2.386.500              | [✓]    |
| Laba Bersih Putaran (3.1)    | Rp 2.386.500 - Rp 200.000     | Rp 2.186.500              | [✓]    |
| Hak Pengelola 80% (3.1)      | 80% x Rp 2.186.500            | Rp 1.749.200              | [✓]    |
| Pool Investor 20% (3.1)      | 20% x Rp 2.186.500            | Rp 437.300                | [✓]    |
| Hasil Bersih Hari Minus(3.2) | Rp 120.000 - Rp 200.000       | -Rp 80.000 (defisit)      | [✓]    |
| Settlement Esoknya (3.3)     | Rp 570.000 - aparat - defisit | Rp 290.000 dibagi 80 : 20 | [✓]    |
```

> Suite `qc_seed`, `qc_roadmap_protocol_1`, **`qc_fase_2`**, **`qc_fase_3`**,
> **`qc_fase_4`**, `qc_sql_injection`, dan `qc_routing` **mengubah data** pada
> database yang ditunjuk `.env` (membuat skema, seed, akun operator, **saldo
> kas, transaksi hulu, sesi settlement, dompet modal, distribusi dividen, dan
> permohonan penarikan**). **Jangan arahkan ke database produksi.**
>
> `qc_fase_2` menyetel saldo "Kas Meja Bakar" ke Rp 50.000.000 dan membersihkan
> `hulu_transactions` + `cash_mutations` pos tersebut lewat
> `QcFixture::resetTellerState()`, sehingga suite dapat dijalankan berulang.
>
> `qc_fase_3` **juga menulis berkas**: foto nota tiruan dibuat di `tests/.tmp/`
> dan disalin ke `public/uploads/receipts/`. Fixture
> (`QcFixture::resetSettlementState()`, `QcFixture::clearSettlementTraces()`)
> membersihkan jejaknya, termasuk berkas nota, sehingga suite dapat dijalankan
> berulang dan tidak meninggalkan artefak di repositori.
>
> `qc_fase_4` menyiapkan empat dompet uji dan menggerakkan kas `Brankas Utama`
> (setoran modal, Pindah Kas, pencairan penarikan). Fixture
> (`QcFixture::resetCapitalState()`, `QcFixture::seedQcWallets()`) mengembalikan
> saldo, kuota, dan status dompet ke titik awal serta membersihkan akun mitra uji
> berawalan `0899`, sehingga suite dapat dijalankan berulang.

---

## 7. Konvensi yang dibekukan

### 7.1 Tipe data database (ERD §3.1) — WAJIB

| Nilai | Tipe | Catatan |
|---|---|---|
| Mata uang / finansial | `DECIMAL(14,2)` | Mencegah *floating-point rounding error* |
| Timbangan emas | `DECIMAL(8,3)` | Presisi hingga **0,001 gram** |
| Persentase | `DECIMAL(5,2)` | Mis. `50.00`, `92.50` |
| Primary key | `CHAR(36)` UUID v4 | Anti-enumeration |

**`FLOAT` dan `DOUBLE` dilarang** untuk nilai moneter maupun berat (diuji
QC 4.7).

### 7.2 Format angka — wajib lewat `Formatter`

```php
Formatter::rupiah(28937500);      // "Rp 28.937.500"
Formatter::rupiah(-5000);         // "-Rp 5.000"
Formatter::rupiah(1234.567, 2);   // "Rp 1.234,57"
Formatter::gram(14.85);           // "14,850 gr"
Formatter::gram(1000);            // "1.000,000 gr"
Formatter::parseRupiah("Rp 28.937.500"); // 28937500.0
Formatter::parseGram("14,850 gr");       // 14.85
```

Dilarang memanggil `number_format()` langsung di view atau controller.

### 7.3 Keamanan — wajib lewat `Security`

```php
Security::e($value);              // escape SETIAP output dinamis di view
Security::csrfField();            // field hidden pada setiap form POST
Security::verifyCsrf($token);     // validasi (dilakukan front controller)
Security::hashPassword($plain);   // password_hash(PASSWORD_DEFAULT)
Security::uuidV4();               // primary key CHAR(36)
```

### 7.4 Database — wajib lewat `Database`

```php
Database::getConnection();           // PDO singleton
Database::fetchOne($sql, $params);   // null bila tidak ada baris
Database::fetchAll($sql, $params);
Database::insert($table, $data);
Database::update($table, $data, $where, $whereParams);
Database::transaction($callback);    // WAJIB untuk operasi kas (SAD §4.1)
```

Setiap nilai dinamis **wajib** dikirim sebagai named parameter. Dilarang
menyisipkan variabel ke dalam string SQL.

### 7.5 Parameter bisnis tinggal di database

Kuota serapan modal, jasa bakar flat, dan masa tunggu penarikan **tidak**
didefinisikan di `Constants.php` melainkan di `system_settings`, agar operator
dapat mengubahnya tanpa deploy. `Constants.php` hanya memuat nilai enum peran
dan konvensi tampilan angka.

### 7.6 Alur operasional

```
Kas Meja Bakar (cash_registers)
   └─ hulu_transactions (status holding)      ← Fase 2: meja kasir hulu
         └─ daily_settlements (tutup buku harian)   ← Fase 3: rekonsiliasi toko
               ├─ profit_distributions (pro-rata ke capital_wallets)  ← Fase 4
               └─ withdrawal_requests (penarikan H-7)                 ← Fase 4
```

Siklus kas lengkap dalam satu hari operasional:

| Waktu | Modul | Tulisan database (satu transaksi PDO) |
|---|---|---|
| Kapan saja | `/operator/capital` (Fase 4) | setoran: `capital_wallets.balance_capital` + `cash_registers` (Brankas +) + `cash_mutations` (`in`) — Pindah Kas: `cash_registers` ×2 + `cash_mutations` (`out`,`in`) — pencairan: `capital_wallets` (dividen/modal −) + `cash_registers` (Brankas −) + `cash_mutations` (`out`) + `withdrawal_requests.status` |
| Sore–malam | `/operator/teller` (Fase 2) | `hulu_transactions` (`holding`) + `cash_registers` (Kas Meja −) + `cash_mutations` (`out`) |
| Sore–malam (kas habis) | `/operator/teller` → **Terima Dana Talangan** (Fase 6) | `bridge_loans` (`active`) + `cash_registers` (Kas Meja + pokok) + `cash_mutations` (`in`) |
| Pagi–siang | `/operator/settlement` (Fase 3 + 4 + 6) | `daily_settlements` + `hulu_transactions` (`holding`→`settled` + `settlement_id`) + `cash_registers` (Kas Meja + / Brankas +) + `cash_mutations` (`in` ×2) + `system_settings.active_carryover_deficit` + `profit_distributions` + `capital_wallets.balance_profit` + `bridge_loans` (`settled` + `fee_absorbed`/`fee_uncovered`) + `cash_registers` (Kas Meja − pokok + fee) + `cash_mutations` (`out` per talangan) |

> Sejak Fase 4, tutup buku menyentuh **enam kelompok tulisan** dalam satu
> transaksi: lima kelompok Fase 3 ditambah distribusi pool 20 % oleh
> `AllocationEngine::apply()`. Dompet `tier='operator'` menyerap modal tetapi
> tidak menerima jatah pool (serapannya dicatat `profit_earned = 0.00`).
>
> **Fase 6 menambah kelompok ketujuh**: pengembalian dana talangan
> (`BridgeLoanService::settleLoans()`) — baris `bridge_loans`, saldo Kas Meja
> Bakar, dan satu `cash_mutations` (`out`) per talangan, semuanya di dalam
> transaksi yang sama.

Setiap pembayaran tunai di meja hulu menulis **tiga hal dalam satu transaksi
PDO** (`TellerService::payCash()`):

1. baris baru `hulu_transactions` berstatus `holding`;
2. saldo `cash_registers` (Kas Meja Bakar) berkurang sebesar **kas keluar
   bersih**;
3. baris `cash_mutations` bertipe `out` sebagai jejak audit.

Kas meja hanya berkurang sebesar `net_cash_paid` — jasa bakar flat Rp 20.000
tetap di meja sebagai pemulihan kowi/gas + upah pengelola, dan dibagi ke kolom
`operator_burn_cut` (Rp 10.000) serta `consumable_cut` (Rp 10.000).

### 7.7 Aritmetika uang (Fase 2) — WAJIB

Jalur uang **tidak** memakai `float`: berat diurai ke miligram (integer) dan
harga ke sen (integer), lalu `grossCents = round_half_away(mg × cents / 1000)`.
Nilai masuk database sebagai string desimal kanonik (`'28937500.00'`).
Pratinjau di peramban (`public/assets/js/app.js`) hanya cermin; **server selalu
menghitung ulang** dari `system_settings` dan mengabaikan nominal kiriman klien.

Aturan field input lapangan:

| Field | Konvensi |
|---|---|
| Berat kering | Satu pemisah (`.` atau `,`) berarti **desimal**. Maks 3 desimal, maks 99.999,999 gr |
| Harga tebus/gram | Titik = pemisah **ribuan**, koma = desimal. Maks 2 desimal |
| Alias penambang | Opsional, maks 50 karakter, default `Penambang` sepanjang ERD |

> `Formatter::parseGram()` **tidak** dipakai untuk field berat: pada format
> Indonesia titik adalah pemisah ribuan, sehingga `parseGram('14.850')`
> menghasilkan 14.850 gr (1000× lipat).

### 7.8 Anti double-submit (Fase 2)

Selain swipe-to-confirm, form kasir membawa token sekali-pakai `_once`
(`Security::issueFormToken()` / `Security::consumeFormToken()`). Token yang
dikirim ulang **tidak** mencatat transaksi kedua: operator dialihkan kembali
dengan pesan "transaksi ganda diabaikan". CSRF tetap diperiksa dua lapis
(front controller + controller). Layar rekonsiliasi memakai token yang sama.

### 7.9 Smart Auto-Split & mesin defisit (Fase 3)

Kas fisik yang diterima hari itu adalah `pendapatan toko − beban aparat`.
Sistem **menyarankan** alokasinya:

| Pos | Saran bawaan |
|---|---|
| `Kas Meja Bakar` | `kas diterima − laba bersih` (praktis: modal belanja hulu kembali) |
| `Brankas Utama` | `max(0, laba bersih)` |

Kedua isian **milik operator** (manual override) dan peladen hanya
menerimanya bila jumlahnya **persis sama** dengan kas yang diterima; kalau
tidak, `422` tanpa satu pun tulisan. Dua isian yang dibiarkan kosong berarti
"pakai saran sistem" (dihitung peladen), sehingga form tetap benar tanpa
JavaScript.

Rumus defisit (PRD §4.2):

```
X      = laba kotor − beban aparat
used   = min(defisit_berjalan, max(0, X))
net    = X − used                     → bagi hasil 80 : 20 hanya bila net > 0
defisit_hari_ini = max(0, −net)       → daily_settlements.carryover_deficit
defisit_berjalan = defisit_berjalan − used + defisit_hari_ini
```

Hari minus **tidak pernah** memotong modal investor: `operator_share_80` dan
`investor_pool_20` diisi `0.00`, `balance_capital` tidak disentuh, dan baris
`profit_distributions` (bila ada) bernilai `0.00` sehingga `balance_profit` tidak
bertambah. Defisit dipotong lebih dulu pada settlement berikutnya yang profit.

### 7.10 Foto nota (unggah) — Fase 3

| Lapisan | Aturan |
|---|---|
| Peramban | `public/assets/js/app.js` (`compressImage()`) memperkecil gambar lewat HTML5 Canvas (kualitas JPEG diturunkan, lalu dimensi) menuju target **< 250 KB**, lalu mengganti isi `input[type=file]` via `DataTransfer` |
| Peladen | `SettlementService::storeReceipt()` — hanya hasil `move_uploaded_file()`, tipe ditebak dari **isi** gambar (`getimagesize`), whitelist JPG/PNG/WEBP, batas keras 4 MB, nama berkas dari `Security::uuidV4()` (nama kiriman klien tidak pernah menjadi path) |
| Penyimpanan | `public/uploads/receipts/` — eksekusi skrip diblokir `.htaccess` (`php_flag engine off`, `RemoveHandler`, `FilesMatch deny`); isi tidak di-commit |
| Kegagalan | Bila transaksi digulung, berkas yang sudah tersalin `unlink()` — tidak ada berkas yatim |

### 7.11 Alokasi modal & likuiditas (Fase 4)

**Waterfall** — kebutuhan belanja satu putaran (`SUM(net_cash_paid)` baris `holding`) diserap
berurutan, seluruhnya dalam **sen integer**:

| Tahap | Rumus | Batas |
|---|---|---|
| 1. Pengelola (`tier='operator'`) | `floor(need × operator_base_quota_pct / 100)` | `min(kuota, saldo dompet, sisa)` |
| 2. Mitra Prioritas (`tier='family'`) | `floor(need × locked_quota_pct / 100)` per dompet | idem |
| 3. Mitra Reguler (`tier='external'`) | pro-rata saldo atas `sisa` | metode sisa terbesar |
| 4. Sisa tak terserap | dilaporkan (`notes` mutasi + log) | tidak ada penyerapan melebihi saldo |

**Distribusi pool 20 %** — pro-rata atas modal mitra yang bekerja (`capital_utilized`), memakai
metode sisa terbesar sehingga `Σ profit_earned = investor_pool_20` **tanpa selisih sen**. Dompet
`operator` dikecualikan dari pool. Contoh angka Protokol QC Manual 4 (dompet 30/16/20/14 juta,
belanja Rp 35.157.500, pool Rp 437.300): serapan `17.578.750 / 7.031.500 / 6.204.264,71 /
4.342.985,29`, dividen `174.920 / 154.341,18 / 108.038,82`.

**Label tier** — `family` → **Mitra Prioritas**, `external` → **Mitra Reguler**,
`operator` → **Pengelola** (satu pemilik: `CapitalWallet::TIER_LABELS`). Tidak ada migrasi skema.

**Penarikan** — jatuh tempo `notice_period_days` (7) **hari kerja**, Sabtu/Minggu dilewati. Modal
pokok tidak boleh dibayar sebelum jatuh tempo (`422` tanpa tulisan); dividen boleh dibayar setelah
disetujui. Maksimum satu pengajuan modal terbuka per dompet.

**Simulator "Belanja Malam Ini"** — isian terisi awal dari peladen (modal `holding` riil) dan bebas
diubah; `capitalSimulator` (Alpine) mencerminkan `AllocationEngine`, sementara peladen tetap
menghitung ulang saat eksekusi.

### 7.12 Portal mitra: onboarding, PIN, dasbor blind, & PWA (Fase 5)

**Alur undangan (sekali pakai).** Operator membuat mitra di `/operator/capital` → `users.invite_token`
(64 heksadesimal) terbit otomatis; tautan `GET /join?token=…` dibuka mitra, ia membaca edukasi +
Pakta Integritas, mencentang, lalu menetapkan PIN 6 digit. Satu `Database::transaction()` menulis
`password_hash` (ber-pepper) + `onboarding_signed_at` + `signed_ip_address` + `signed_user_agent`
dan **menghanguskan** `invite_token`. Operator dapat memutar ulang token ("Buat ulang tautan") —
tautan lama langsung mati, sekaligus menjadi jalur reset PIN.

**PIN ber-pepper.** `users.password_hash` mitra = `password_hash(hash_hmac('sha256', pin,
APP_PIN_PEPPER))`, sehingga PIN tidak pernah sah di `POST /login` (kata sandi). PIN lemah
(`111111`, `123456`, `654321`, `123123`, bukan 6 digit) ditolak `422`. `APP_PIN_PEPPER` kosong →
onboarding ditolak.

**Pembatas percobaan.** `PinThrottle` (berkas JSON di `storage/throttle/`, di luar document root)
memakai aturan 5 gagal / 15 menit → kunci 15 menit per `nomor + IP`, dipakai `/login` **dan**
`/investor/login`. Bila `storage/throttle/` tidak writable, sistem turun mutu ke penghitung sesi +
satu `Logger::warning`. Berkasnya tidak di-commit (`.gitignore`).

**Dasbor blind (`/investor/dashboard`).** Rumus yang dibekukan:

| Metrik | Rumus |
|---|---|
| Modal Berputar | `min(capital_utilized` baris `profit_distributions` terakhir milik dompet`, balance_capital)` |
| Kas Cadangan | `balance_capital − Modal Berputar` |
| Total Nilai Portofolio | `balance_capital + balance_profit` |
| Dividen Bulan Ini | `Σ profit_earned` dengan `settlement_date` di bulan kalender berjalan |
| ROI Bulan Ini | `Dividen Bulan Ini ÷ balance_capital × 100` (modal `0` → `—`) |

Baris terakhir ditentukan `settlement_date DESC` → `created_at DESC` → `id DESC`. Dasbor, laporan
cetak, dan CSV **hanya** memuat data dompet mitra sendiri; daftar terlarangnya ada di `AGENTS.md`
(dijaga pemindaian statis + assertion HTTP `qc_fase_5`).

**Penarikan mandiri.** `POST /investor/withdrawal` (token `_once`, PRG) — tombol "Tarik Keuntungan"
(`notice_period_due` = hari pengajuan) dan "Tarik Modal" (`+notice_period_days` **hari kerja**,
dompet `queued`). Dompet **selalu** diambil dari session, tidak pernah dari form; `Σ` pengajuan
terbuka per jenis tidak boleh melebihi saldonya. Pencairan tetap milik operator (Fase 4).

**Ekspor.** `GET /investor/report` (HTML ramah cetak, `@media print`, tombol memanggil
`window.print()`) dan `GET /investor/report/csv` (`text/csv`, BOM UTF-8, pemisah `;`, angka gaya
Indonesia). Tidak ada teks dari klien pada CSV — hanya tanggal/nominal/status hitungan peladen.

**PWA.** `manifest.json` (`start_url`/`scope` relatif `"./"`), `sw.js` (cache **hanya** aset statis
berdaftar-putih; navigasi selalu jaringan, HTML/CSV tidak pernah di-cache), `offline.html`, dan ikon
192/512/maskable/apple-touch. Registrasi service worker hanya pada `https:`/`localhost`; tombol
"Tambahkan ke Layar Utama" baru muncul di HTTPS (persyaratan peramban, bukan bug).

> **Mengubah `app.js` atau `app.min.css`? Naikkan `CACHE_NAME` di `public/sw.js`.** Aset statis
> dilayani **cache-first**, sehingga tanpa kenaikan versi shell peramban operator tetap menjalankan
> JS/CSS lama bersama view baru — komponen Alpine kehilangan variabel baru (mis. `bridgeHasActive`)
> dan elemen `x-cloak x-show="…"` tidak pernah muncul. Versi shell saat ini
> **`aurumflow-shell-v2`** (dinaikkan dari v1 pada Fase 6); sinkronisasi versi antara `sw.js` dan
> `AGENTS.md` dijaga assertion `G.17`–`G.18` (`qc_fase_6`).

**Gating sadar peran.** `BaseController::roleLandingPath()` adalah satu-satunya pemetaan peran →
beranda: `investor` → `/investor/dashboard`, selain itu → `/dashboard`. `/dashboard` **operator-only**
(karena memuat `system_settings`).

### 7.13 Dana talangan / jembatan likuiditas (Fase 6) — WAJIB

Meja Bakar habis pada malam hari → pengelola meminjam kas tunai dari pihak luar (**tanpa akun**),
lalu mengembalikan pokok + fee pada tutup buku siang berikutnya (T+1).

**Tabel `bridge_loans`** (tabel ke-10; **nol ALTER** pada tabel lama, sehingga database lapangan tidak
butuh migrasi). Pokok **bukan** pendapatan: ia hanya melintas di Kas Meja Bakar dan tidak pernah
masuk `daily_settlements`.

**Penerimaan (Layar B2).** Tombol **"Terima Dana Talangan"** di `/operator/teller` membuka modal;
`POST /operator/teller/talangan` menjalankan `BridgeLoanService::receive()` — **satu**
`Database::transaction()`: kunci pos kas → `bridge_loans` (`active`) → Kas Meja Bakar `+pokok` →
`cash_mutations` (`in`).

**Fee dihitung peladen.** Bentuk `fixed` (nominal Rp) atau `pct` (%).

| Bentuk | Rumus (sen integer) |
|---|---|
| `fixed` | `fee = parseMoneyInput(fee_fixed)` — kosong berarti `0.00` |
| `pct` | `fee = intdiv(pokok_sen × tarif_perseratus + 5000, 10000)` — pembulatan *half away from zero*, tarif `0 < x ≤ 100,00` |

**Pembebanan fee: HANYA dari hak pengelola.** Bagi hasil 80 : 20 dihitung lebih dulu, **baru** fee
dikurangkan dari porsi pengelola:

```
fee_absorbed  = min(Σ fee talangan aktif, operator_share_80)     ← FIFO created_at, id
fee_uncovered = Σ fee − fee_absorbed                             ← dibayar dari kas kerja pengelola
hak_pengelola_bersih = operator_share_80 − fee_absorbed ≥ 0
investor_pool_20 → TIDAK PERNAH berubah karena talangan
```

**Auto-pay pada tutup buku.** `SettlementService::settle()` memanggil
`BridgeLoanService::settleLoans()` di dalam transaksi yang sama: Kas Meja Bakar turun
`Σ(pokok + fee)`, satu `cash_mutations` (`out`, label `Kembali talangan: <nama>`) per talangan, dan
baris `bridge_loans` menjadi `settled` + `settlement_id` + `fee_absorbed`/`fee_uncovered`. Talangan
yang belum lunas tetap `active` dan terbayar pada tutup buku berikutnya. Bila kas meja setelah
alokasi tidak cukup → `422` tanpa satu pun tulisan.

**Memasang tabel `bridge_loans` pada instalasi yang sudah berjalan.** `storage/installed.lock`
menolak `database/install.php` yang dijalankan dua kali, jadi tabel baru dipasang dengan
**mengimpor ulang `database/schema.sql`** (mis. lewat phpMyAdmin) — skripnya memakai
`CREATE TABLE IF NOT EXISTS`, sehingga hanya tabel yang belum ada yang dibuat dan **tidak ada
`ALTER TABLE`** pada tabel lama. Tidak ada langkah migrasi lain: sembilan tabel lama tetap utuh
(dijaga assertion `qc_fase_6` H.6/H.7).

---

## 8. Pemecahan masalah

| Gejala | Penyebab umum | Tindakan |
|---|---|---|
| Halaman putih | Output error dimatikan | Set `APP_DEBUG=true` sementara, periksa `storage/logs/` |
| `Koneksi database gagal` | Kredensial/DB salah | Periksa `DB_*` pada `.env`; detail ada di `storage/logs/` |
| Login selalu gagal | `session.save_path` tidak writable | Isi `SESSION_SAVE_PATH` ke folder writable |
| CSS tidak termuat | Document root bukan `public/` | Perbaiki document root, atau pakai `.htaccess` di root |
| `INSTALL DITOLAK: INSTALL_TOKEN belum diisi` | Token kosong | Isi `INSTALL_TOKEN` sementara saat instalasi |
| `Aplikasi sudah terpasang` | Marker ada | Hapus `storage/installed.lock` **secara sadar** (menimpa password operator) |
| `membutuhkan PHP 8.2` | Versi PHP host lebih lama | Pin versi lewat MultiPHP di cPanel |
| Kolom `username` tidak ada | Memakai kode versi pra-ERD | Login memakai `phone_number`; lihat §7 |
| Kasir menolak pembayaran | Saldo kas meja kurang / berat atau harga tidak sah | Pesan alasan tampil di layar kasir; isi ulang saldo lewat `cash_registers` |
| "Transaksi ganda diabaikan" | Token `_once` dipakai dua kali (Back/retry) | Normal — tidak ada pencatatan kedua; muat ulang layar kasir |
| Tema tidak rapi / tanpa warna | `app.min.css` belum dikompilasi ulang | Jalankan perintah build pada §3a |
| "Ukuran unggahan melebihi batas server" | Foto nota melebihi `upload_max_filesize`/`post_max_size` PHP | Perkecil foto (kompresi Canvas berjalan otomatis bila JS aktif) atau naikkan batas PHP |
| "Foto nota tidak dikenali sebagai gambar" | Berkas bukan gambar nyata (mis. dokumen diganti ekstensi) | Ambil ulang foto dari kamera |
| "Direktori penyimpanan nota tidak dapat ditulis" | `public/uploads/receipts/` tidak writable | `chmod` direktori (cukup 0755 milik user web) |
| "Defisit berjalan" muncul di layar rekonsiliasi | Hari minus sebelumnya belum pulih | Normal — defisit dipotong lebih dulu dari laba hari ini |
| Saldo brankas tidak cukup saat Pindah Kas / bayar penarikan | Kas modal belum disetor atau sudah dipindahkan | Catat setoran di `/operator/capital` atau pindahkan lebih sedikit |
| "belum jatuh tempo" saat menekan Bayar | Penarikan modal pokok masih dalam masa tunggu H-7 | Tunggu `notice_period_due`; dividen boleh dibayar setelah disetujui |
| Layar kelola modal melaporkan dompet Pengelola tidak ada | Instalasi lama dibuat sebelum Fase 4 | Tekan "Siapkan dompet Pengelola" pada layar yang sama |

---

## 9. Catatan lingkungan pengembangan ini

Mesin dev yang dipakai menyusun proyek ini **tidak** memiliki MySQL 8.0. QC
(8 suite) dijalankan terhadap **MariaDB 12.3.2** (MySQL-compatible) pada
database uji yang ditunjuk `.env` (dev: `aurumflow_dev`).

Konsekuensinya:

- Seluruh pernyataan QC (InnoDB, utf8mb4, DECIMAL, JSON, FOREIGN KEY, ENUM,
  prepared statement, transaksi `FOR UPDATE`) berperilaku sama pada MariaDB dan
  MySQL 8.0.
- **Sebelum go-live**, arahkan `.env` ke MySQL 8.0 sungguhan lalu jalankan
  ulang `bash tests/run_qc.sh` sebagai verifikasi akhir. Tidak ada perubahan
  kode yang diperlukan. (Item terbuka **A-1** pada addendum rencana.)
