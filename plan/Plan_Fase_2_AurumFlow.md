# Plan Fase 2 — AurumFlow

> **Dokumen Perencanaan Eksekusi**
> Proyek: **AurumFlow** — sistem manajemen operasional privat untuk perdagangan emas aluvial dengan settlement **T+1**.
> Fase: **2 — Meja Kasir Hulu & Arus Kas Lapangan**
> Status: `APPROVED FOR PLANNING` · **belum ada kode PHP yang dieksekusi.**

| Item | Nilai |
|---|---|
| Runtime | Native PHP **8.2+** (`declare(strict_types=1)`), tanpa framework, tanpa Composer/`vendor/` |
| Frontend | Tailwind CSS **3.4.19** (CLI, dikompilasi lokal) + Alpine.js **3.17.4** (aset lokal, tanpa CDN) |
| Database | MySQL **8.0 / InnoDB** — skema 9 tabel ERD **tidak berubah** |
| Target hosting | Shared hosting / cPanel (tanpa Node.js, tanpa background worker) |
| Scope baru | Route `/operator/teller` (GET+POST), layer `app/Services/`, tema *Obsidian Gold* untuk seluruh view |
| Sumber kebenaran | ERD → SAD → Roadmap (Protokol QC Manual 2) → PRD → UI/UX |
| Keputusan pengguna | `dec-96114fafe2590e91` (lihat §2) |
| Bahasa dokumen | Prosa Indonesia + istilah teknis Inggris (konvensi `Plan_Fase_1_AurumFlow.md`) |

---

## 1. Ringkasan Fase 2

### 1.1 Tujuan

Fase 1 menghasilkan *kontrak arsitektur* (koneksi PDO, routing, helper keamanan, formatter) tetapi **belum menyentuh uang**. Fase 2 adalah jalur kas keluar pertama yang nyata: operator di meja bakar malam hari menimbang emas kering, melihat **kas bersih keluar terhitung seketika**, mengonfirmasi dengan **menggeser** tombol (bukan mengklik), lalu sistem mencatat tiga hal dalam **satu transaksi atomik** — baris `hulu_transactions` berstatus `holding`, saldo `cash_registers` berkurang, dan riwayat `cash_mutations` bertipe `out`.

Fase ini juga menutup item terbuka **A-6** Fase 1 (Tailwind/Alpine belum disiapkan) dan menerapkan tema **Obsidian Gold** dari dokumen UI/UX ke seluruh antarmuka: kontras ekstrem untuk ruangan berasap, target sentuh *fat-finger friendly*, dan angka `font-mono tabular-nums` agar digit tidak bergeser saat nilai berubah.

Secara konkret, Fase 2 harus menghasilkan:

1. **Layar kasir hulu** (`/operator/teller`) — kalkulasi instan Alpine.js, swipe-to-confirm, widget saldo kas aktif di atas dan 3 transaksi terakhir di bawah.
2. **Logika transaksi atomik** di `app/Services/TellerService.php` — `beginTransaction`/`commit`/`rollBack` dengan penguncian baris kas, satu sumber kebenaran untuk perhitungan uang.
3. **Pemisahan jasa bakar** — `flat_burn_fee` 20.000,00 dibagi menjadi `operator_burn_cut` 10.000,00 dan `consumable_cut` 10.000,00, **tanpa** menambah kunci `system_settings` baru.
4. **Sistem desain Obsidian Gold** — Tailwind terkompilasi (`public/assets/css/app.min.css`, < 30 KB) + Alpine.js lokal, dipakai seluruh view; CSS ad-hoc Fase 1 dipensiunkan.
5. **Guard peran** — investor yang membuka URL kasir ditolak dan diarahkan ke `/dashboard` (Roadmap QC Manual 5, "Akses URL Kasir Ilegal").
6. **Suite QC otomatis** — `tests/qc_fase_2.php` menjalankan Protokol QC Manual 2 secara utuh dan mencetak tabel siap-salin ke dokumen Roadmap.

### 1.2 Definisi Selesai (Definition of Done)

Fase 2 dinyatakan selesai apabila **seluruh** poin berikut terpenuhi:

- [ ] `GET /operator/teller` menampilkan layar kasir; `POST /operator/teller` mencatat transaksi.
- [ ] Investor → `GET /operator/teller` dijawab `302` menuju `/dashboard`.
- [ ] Kalkulasi `Net Pay = (Berat Kering × Harga Tebus) − Jasa Bakar Flat` benar di klien **dan** dihitung ulang di server; angka kiriman klien tidak dipercaya.
- [ ] Satu POST = tiga tulisan dalam satu `Database::transaction()`; gagal di tengah berarti **nol** perubahan (diuji, bukan diasumsikan).
- [ ] `operator_burn_cut` = 10.000,00 dan `consumable_cut` = 10.000,00 tersimpan per transaksi, berasal dari `system_settings`.
- [ ] Saldo kas aktif tampil di atas layar dan 3 transaksi terakhir di bawah, keduanya dari data nyata.
- [ ] Tema Obsidian Gold berlaku di **seluruh** view (teller, dashboard, login, error); `public/assets/css/app.css` dihapus.
- [ ] `tests/qc_fase_2.php` lulus 100% dan Protokol QC Manual 2 tercetak 1:1.
- [ ] `bash tests/run_qc.sh` exit `0`, tanpa `storage/logs/php-*.log`, `git status` bersih.
- [ ] Tidak ada perubahan DDL/seed; `docs/` tidak perlu diubah (kecuali item **O-1** diambil).

### 1.3 Di Luar Ruang Lingkup Fase 2

Secara eksplisit **tidak** dikerjakan (backlog fase berikutnya):

- Likuidasi toko, kompresi foto nota, dan mesin settlement harian (**Fase 3**).
- Waterfall / Pro-Rata Engine, kuota serapan modal, penarikan dana H-7 (**Fase 4**).
- Dasbor *blind* investor, waiver/clickwrap, autentikasi PIN, ekspor laporan (**Fase 5**).
- PWA (`manifest.json`, `sw.js`, ikon) — item **A-6** hanya tuntas sebagian: Tailwind + Alpine masuk Fase 2, PWA tetap Fase 5.
- Segala bentuk AJAX/polling saldo, websocket, atau background worker.
- Meja kasir **tidak** menyentuh `capital_wallets`, `daily_settlements`, maupun `profit_distributions`.

---

## 2. Keputusan & Asumsi Terkunci

### 2.1 Keputusan pengguna (`dec-96114fafe2590e91`)

| Keputusan | Pilihan | Konsekuensi |
|---|---|---|
| Cakupan tema | **Seluruh view** — teller, dashboard, login, error | Diff lebih besar; suite QC yang meng-assert HTML/aset harus diperbarui; `public/assets/css/app.css` dihapus. |
| Rumah logika transaksi | **`app/Services/TellerService.php`** (layer baru) | Dicatat sebagai penambahan di luar SAD (§11.1), pola sama dengan tabel penambahan pada `Plan_Fase_1` §3.2. |
| Kas aktif | Konstanta `CashRegister::TELLER_NAME = 'Kas Meja Bakar'` | Tanpa perubahan skema/seed/`docs/`. Alternatif yang ditolak dicatat di §10 O-6. |

### 2.2 Asumsi arsitektural yang dikunci

- **A-1 — Jasa bakar tidak di-hardcode.** Dibaca dari `system_settings`: `default_burn_fee` (`20000.00`) dan `default_burn_fee_operator_cut` (`10000.00`). Karena ERD tidak menyediakan kunci untuk porsi konsumabel, `consumable_cut = flat_burn_fee − operator_burn_cut` dihitung, bukan disimpan sebagai setting baru.
- **A-2 — Tailwind dikompilasi lokal.** `npx --yes tailwindcss@3.4.19 -c tailwind.config.js -i resources/css/tailwind.input.css -o public/assets/css/app.min.css --minify`. Hasil minified **di-commit**. Server hosting tidak pernah membutuhkan Node.js. Fallback offline: binary standalone `tailwindcss-linux-x64` (≈ 100 MB) di `tools/` yang di-gitignore — hanya dipakai bila jaringan mesin pengembang tidak tersedia.
- **A-3 — Alpine.js di-vendor lokal.** `public/assets/js/alpine.min.js` versi **3.17.4** dari tarball npm, dengan header versi + lisensi. Dilarang memakai CDN: lokasi lapangan bersinyal lemah dan ketergantungan pihak ketiga menambah titik gagal.
- **A-4 — Uang dihitung dengan aritmetika integer berskala.** Berat dalam miligram (×10³), harga dalam sen (×10²); hasil kali berada pada skala 10⁵ rupiah sehingga pembagian ke skala 10² (rupiah–sen) hanya menyisakan pembulatan *half away from zero* yang eksplisit. Tidak ada `float`/`double` pada jalur uang, tidak membutuhkan ekstensi `bcmath`. Nilai dikirim ke DB sebagai **string desimal kanonik** (`'28937500.00'`) lewat `Database::insert()`/`update()` (binding `PDO::PARAM_STR`, konsisten dengan QC 1.10).
- **A-5 — Guard server-side anti double-submit.** Token sekali-pakai `_once` di session (terpisah dari CSRF `_token`). Token yang di-*replay* **tidak** menulis apa pun: operator dialihkan kembali ke layar kasir dengan pesan "transaksi ganda diabaikan". Ini menutup celah yang tidak dapat ditutup JavaScript klien (retry jaringan, tombol Back, dua tap cepat).
- **A-6 — Font self-host.** `Plus Jakarta Sans` (400/600/700) + `JetBrains Mono` (400/700) sebagai `woff2` di `public/assets/fonts/`, dengan fallback *system stack*. Alternatif Google Fonts dicatat di §10 O-4.
- **A-7 — Tanpa AJAX.** Saldo di header dirender server-side; Alpine.js hanya untuk kalkulasi lokal, komponen swipe, dan UX input.
- **A-8 — Timezone & logging.** Tetap mengikuti `Plan_Fase_1` A-3: aplikasi dan DB pada `Asia/Jakarta (+07:00)`. Setiap transaksi sukses/gagal dicatat lewat `Logger` (tanpa data sensitif).
- **A-9 — Session file-lock PHP** menyerialkan request dalam satu session, sehingga replay token pada dua request simultan tetap aman. Ini *diasumsikan* pada shared hosting default dan diverifikasi manual (§9 G-6).

---

## 3. Struktur File

### 3.1 File baru

| File | Peran |
|---|---|
| `tailwind.config.js` | Token desain: `bg-obsidian-950 #07080B`, `bg-surface-900 #0F1117`, `bg-surface-800 #181C26`, `gold-primary #D4AF37`, `gold-glow #F3E5AB`, `emerald-cash #10B981`, `text-pure #F8FAFC`, `text-muted #94A3B8`; `fontFamily.sans`/`mono`; `content: ['./app/Views/**/*.php', './public/assets/js/*.js']` |
| `resources/css/tailwind.input.css` | `@tailwind base/components/utilities` + `@layer base` (body obsidian, angka `tabular-nums`) + `@layer components` (`.af-card`, `.af-input`, `.af-btn`, `.af-alert`, `.af-num`) + `@font-face` |
| `public/assets/css/app.min.css` | **Hasil build**, di-commit (target < 30 KB sesuai SAD §2) |
| `public/assets/js/alpine.min.js` | Alpine.js 3.17.4 vendored (header versi + lisensi) |
| `public/assets/fonts/*.woff2` | 5 berkas font self-host (A-6) |
| `app/Services/TellerService.php` | `quote()` (murni, tanpa DB) + `payCash()` (satu `Database::transaction()`) |
| `app/Models/CashRegister.php` | `TELLER_NAME`, `activeTeller()`, `balanceOf()`, `adjustBalance()`, `lockForUpdate()` |
| `app/Models/HuluTransaction.php` | `insert()`, `recent()`, `holdingTotalGrams()`, `holdingCount()` |
| `app/Models/CashMutation.php` | `insert()`, `recentFor()`, `countFor()` |
| `app/Controllers/TellerController.php` | `index()` (GET), `store()` (POST, PRG + flash) |
| `app/Views/operator/teller.php` | Layar kasir (implementasi "Layar B" dokumen UI/UX) |
| `app/Views/layouts/operator.php` | Layout mobile-first + header sticky saldo kas |
| `tests/qc_fase_2.php` | **Protokol QC Manual 2** otomatis (§6) |
| `plan/Plan_Fase_2_AurumFlow.md` | Dokumen ini |

> `Services/` tidak memerlukan perubahan autoloader: `app/Config/bootstrap.php` memetakan `AurumFlow\` → `app/`, sehingga `AurumFlow\Services\TellerService` otomatis menunjuk `app/Services/TellerService.php`.

### 3.2 File yang diubah

| File | Perubahan |
|---|---|
| `app/routes.php` | + `GET /operator/teller`, + `POST /operator/teller` |
| `app/Controllers/BaseController.php` | + `requireRole(string ...$roles)`, + `flash(string $kind, string $message)`; `render()` mengonsumsi `$_SESSION['_flash']` menjadi `$flash` |
| `app/Helpers/Security.php` | + `issueFormToken()`, + `consumeFormToken(?string)` (idempotensi A-5) |
| `app/Controllers/DashboardController.php` | Tautan kasir *role-aware*; **tanpa** data bisnis untuk investor (isolasi Fase 5 tidak dilanggar lebih awal) |
| `app/Views/layouts/app.php`, `layouts/auth.php` | Tema Obsidian Gold |
| `app/Views/auth/login.php`, `dashboard/index.php`, `errors/{404,500,error}.php` | Tema; **nama field, hidden `_token`, dan teks nilai kunci tidak berubah** agar `qc_routing`/`qc_sql_injection` tetap sahih |
| `public/assets/js/app.js` | + formatter angka mirror `Formatter` (Rupiah/gram) untuk Alpine + guard submit ganda yang sudah ada |
| `.htaccess` (root) | Tambah `resources/` ke daftar deny (mitigasi R-1: docroot salah arah) |
| `tests/bootstrap.php` | + `QcFixture::resetTellerState()` (menyiapkan saldo Rp 50.000.000 dan membersihkan baris uji) |
| `tests/run_qc.sh` | + `qc_fase_2` pada array `SUITES`; nama/header log QC |
| `tests/qc_routing.php` | Assertion aset statis menunjuk `assets/css/app.min.css` + `assets/js/alpine.min.js` |
| `README.md` | Perintah build Tailwind, struktur `resources/` + `app/Services/`, tabel QC, baseline baru |
| `AGENTS.md` | Baseline suite/assertion, perintah build, `resources/`, `app/Services/`, peringatan DB QC |

### 3.3 File yang dihapus

| File | Alasan |
|---|---|
| `public/assets/css/app.css` | Dipensiunkan; digantikan `app.min.css`. Dihapus **setelah** seluruh view migrasi dan assertion aset `qc_routing.php` diperbarui. |

### 3.4 Pohon target (perubahan ditandai)

```text
aurumflow/
├── tailwind.config.js                     # (+) konfigurasi Tailwind lokal (SAD §3)
├── resources/                             # (+) sumber build CSS — TIDAK terekspos web
│   └── css/tailwind.input.css
├── app/
│   ├── Controllers/TellerController.php   # (+)
│   ├── Models/CashRegister.php            # (+)
│   ├── Models/HuluTransaction.php         # (+)
│   ├── Models/CashMutation.php            # (+)
│   ├── Services/TellerService.php         # (+)
│   └── Views/
│       ├── layouts/operator.php           # (+)
│       └── operator/teller.php            # (+)
├── public/assets/
│   ├── css/app.min.css                    # (+) hasil build, di-commit
│   ├── css/app.css                        # (−) dihapus
│   ├── fonts/*.woff2                      # (+)
│   └── js/alpine.min.js                   # (+)
└── tests/qc_fase_2.php                    # (+) Protokol QC Manual 2
```

---

## 4. Keputusan Teknis & Konvensi Fase 2

### 4.1 Build frontend

- Tailwind v3 (bukan v4): SAD §2 menyebut "Tailwind CSS v3 (CLI Standalone)", dan dokumen UI/UX memakai sintaks `@layer`/`content` v3.
- Perintah build **hanya** dijalankan di mesin pengembang; hasilnya di-commit. Tidak ada `package.json`, tidak ada `node_modules/` di repositori, tidak ada langkah build pada server.
- `resources/` di-deny pada `.htaccess` root karena berisi sumber yang tidak boleh disajikan sebagai statis.
- Ukuran `app.min.css` diverifikasi QC (< 30 KB) agar aset tidak membengkak diam-diam.

### 4.2 Token tema & ergonomi lapangan

- Latar `#07080B` (obsidian 950) dengan teks `#F8FAFC`; aksen `#D4AF37` untuk merek/saldo, `#10B981` untuk kas keluar bersih dan saldo likuid.
- Seluruh angka nominal dan timbangan memakai `font-mono tabular-nums` (kelas `.af-num`) — lebar digit seragam, tidak ada pergeseran saat nilai diperbarui.
- Target sentuh minimal **52 px** untuk tombol konfirmasi dan input (UI/UX §2.1).
- Glassmorphism halus pada kartu ringkasan: `border border-[#D4AF37]/20` + `shadow-[0_8px_32px_rgba(0,0,0,0.6)]`.

### 4.3 Aritmetika uang (A-4)

Aturan yang dibekukan, diuji grup **A** pada §6:

1. Berat diurai menjadi **miligram** (integer, 3 desimal), harga menjadi **sen** (integer, 2 desimal).
2. `grossCents = round_half_away(weightMg × priceCents / 1000)` — hasil kali memiliki skala eksak 10⁵ rupiah, sehingga pembulatan ke sen bersifat deterministik.
3. `netCents = grossCents − feeCents`; `feeCents` berasal dari `system_settings` (0 bila toggle jasa bakar nonaktif).
4. `operatorCutCents + consumableCutCents = feeCents` (invariant yang diuji QC P2.1/P2.3).
5. Nilai disimpan sebagai string `sprintf('%d.%02d')` dan **hanya** diformat untuk tampilan lewat `Formatter`.
6. Dilarang memakai `float` pada jalur uang; QC memindai `floatval`/`(float)`/`FLOAT`/`DOUBLE` pada file baru.

### 4.4 Parsing input lapangan (risiko R-3)

`Formatter::parseGram()` **tidak** dipakai untuk field berat karena semantiknya (tanpa koma → titik dianggap pemisah ribuan) akan membaca `14.850` sebagai **14.850 gr**, bukan 14,850 gr. Konvensi field input lapangan:

| Field | `inputmode` | Normalisasi | Contoh |
|---|---|---|---|
| Berat kering | `decimal` | buang spasi + sufiks `gr`; **satu** pemisah (`.` atau `,`) diperlakukan sebagai **desimal**; maksimal 3 desimal; nilai > 0 dan ≤ `99999.999` (batas `DECIMAL(8,3)`) | `14,850` → 14.850 · `14.850` → 14.850 · `14` → 14.000 |
| Harga tebus / gram | `numeric` | `Formatter::parseRupiah()` (titik = ribuan); > 0 dan ≤ `999999999999.99` (batas `DECIMAL(14,2)`) | `1.950.000` → 1950000 · `1950000` → 1950000 |
| Alias penambang | teks | `Security::sanitize()`, trim, maks 50 karakter (batas kolom), default ERD `'Penambang'` bila kosong | `Pak Anto` |

Input tak sah **ditolak** (HTTP `422`) dengan pesan yang dapat dibaca operator, dan **tidak** menulis apa pun. Byte invalid UTF-8 tetap gagal aman (catatan QC 2.8 pada Fase 1).

### 4.5 Alur atomik & penguncian (SAD §4.1)

`TellerService::payCash()` menjalankan **satu** `Database::transaction()` dengan urutan:

1. `SELECT ... FROM cash_registers WHERE register_name = :name FOR UPDATE` — mengunci baris kas sehingga dua terminal tidak dapat membelanjakan saldo yang sama.
2. Verifikasi saldo cukup (`current_balance >= netCents`), jika tidak → lempar exception (**sebelum** ada tulisan).
3. `INSERT hulu_transactions` (`status = 'holding'`, `settlement_id = NULL`).
4. `UPDATE cash_registers SET current_balance = current_balance - :net` untuk baris yang sama.
5. `INSERT cash_mutations` (`mutation_type = 'out'`, `amount = net_cash_paid`, `register_id` = kas meja, `source_destination` = "Pembayaran tebus hulu — {alias}", `notes` memuat UUID transaksi + berat + harga untuk jejak audit).

Tipe exception: `InvalidArgumentException` untuk input tidak sah, `RuntimeException` untuk pelanggaran invariant (kas tidak ditemukan, saldo kurang). Pembulatan & perhitungan dilakukan **sebelum** transaksi dibuka.

Catatan konsistensi bisnis: kas meja hanya berkurang sebesar **net cash paid**. Jasa bakar Rp 20.000 secara fisik tetap di meja sebagai pemulihan kowi/gas + upah pengelola, sehingga ia menjadi alokasi internal pada baris `hulu_transactions`, **bukan** mutasi `in` pada `cash_registers`. Ini konsisten dengan angka Protokol QC Manual 2 dan Manual 3 (`Total Modal Belanja Hulu = Rp 35.157.500` = jumlah net).

### 4.6 Idempotensi / anti double-submit (A-5)

- Form kasir membawa dua field tersembunyi: `_token` (CSRF, diperiksa front controller **dan** controller) dan `_once` (token sekali-pakai).
- `Security::issueFormToken()` menerbitkan token acak kriptografis (32 byte) + waktu terbit di session.
- `Security::consumeFormToken($token)`: `hash_equals()` terhadap token aktif; bila cocok → token dihapus dan token terakhir dicatat; bila token **sama dengan token terakhir yang sudah dikonsumsi** → `false` dengan status "duplikat" (bukan error) → controller mengalihkan (PRG) dengan pesan "Transaksi ganda diabaikan, tidak ada pencatatan baru".
- Token tidak dikenal/kosong → `419` (setara perilaku CSRF).
- Perilaku ini diuji sebagai grup **F** pada §6: replay **tidak** menghasilkan baris baru dan saldo tidak berubah.

### 4.7 Kas aktif

`CashRegister::TELLER_NAME = 'Kas Meja Bakar'` — satu-satunya tempat nama itu ditulis. `CashRegister::activeTeller()` melakukan lookup by name; bila baris tidak ada (seed terhapus), aplikasi gagal cepat dengan `RuntimeException` dan pesan operator, bukan diam-diam memakai saldo 0. `Brankas Utama` tidak disentuh Fase 2.

### 4.8 Flash & pola POST-Redirect-GET

- Sukses → `flash('success', ...)` lalu `303`/`302` ke `GET /operator/teller` (mencegah resubmit saat refresh).
- Gagal validasi → `422` + render ulang form dengan input terpelihara (operator tidak perlu mengetik ulang timbangan).
- `BaseController::render()` mengambil sekali pakai `$_SESSION['_flash']` menjadi variabel `$flash` (tidak ada API baru di view selain variabel ini).

### 4.9 Route & guard

| Method | Path | Handler | Guard |
|---|---|---|---|
| `GET` | `/operator/teller` | `TellerController::index` | `requireAuth()` + `requireRole('operator')` |
| `POST` | `/operator/teller` | `TellerController::store` | idem + CSRF (2 lapis) + `_once` |

Mutasi tetap **hanya** via POST + CSRF. `requireRole()` mengarahkan pelanggar ke `/dashboard` (peran `investor` tidak menerima halaman error, sesuai ekspektasi Roadmap QC Manual 5).

---

## 5. Tugas Eksekusi (Step-by-Step)

> Dikerjakan **berurutan**. T1–T8 tidak boleh dilompati; setiap tugas menyebut file target dan kriteria selesai.

### T1 — Fondasi frontend (Tailwind + Alpine)

**File:** `tailwind.config.js`, `resources/css/tailwind.input.css`, `public/assets/css/app.min.css`, `public/assets/js/alpine.min.js`, `public/assets/fonts/*.woff2`, `.htaccess`

**Langkah:**
1. Tulis `tailwind.config.js` dengan token §4.2 dan glob `content` yang mencakup seluruh view + JS aset.
2. Tulis `resources/css/tailwind.input.css`: direktif Tailwind, `@layer base` (body obsidian, `-webkit-text-size-adjust`, `tabular-nums` untuk angka), `@layer components` (`.af-card`, `.af-input`, `.af-btn`, `.af-alert`, `.af-num`), dan `@font-face` untuk berkas di `public/assets/fonts/` (URL absolut relatif `assets/fonts/...`).
3. Unduh + ekstrak Alpine.js **3.17.4** ke `public/assets/js/alpine.min.js` (pertahankan header versi + lisensi; catat versi sebagai komentar).
4. Unduh 5 berkas `woff2` (Plus Jakarta Sans 400/600/700, JetBrains Mono 400/700) ke `public/assets/fonts/`.
5. Jalankan build: `npx --yes tailwindcss@3.4.19 -c tailwind.config.js -i resources/css/tailwind.input.css -o public/assets/css/app.min.css --minify`.
6. Tambahkan `resources` ke `RewriteRule ^(app|database|storage|tests|plan|docs)(/|$) - [F,L]` pada `.htaccess` root.

**Kriteria selesai:** `app.min.css` ada, < 30 KB, memuat token `obsidian`/`gold-primary`; `GET /resources/css/tailwind.input.css` → `403`/`404`; `GET /assets/js/alpine.min.js` → `200`.

### T2 — Migrasi tema seluruh view

**File:** `app/Views/layouts/{app,auth,operator}.php`, `app/Views/auth/login.php`, `app/Views/dashboard/index.php`, `app/Views/errors/{404,500,error}.php`, `tests/qc_routing.php`, hapus `public/assets/css/app.css`

**Langkah:**
1. `layouts/app.php` — shell obsidian (topbar + konten + slot flash), memuat `app.min.css`, `alpine.min.js` (`defer`), dan `app.js` (`defer`).
2. `layouts/auth.php` — kartu login mewah (glassmorphism, pendaran emas).
3. `layouts/operator.php` — mobile-first, header sticky berisi saldo kas aktif (`Formatter::rupiah()`), target sentuh ≥ 52 px.
4. Migrasi `auth/login.php`, `dashboard/index.php`, `errors/*`. **Pertahankan** nama field (`phone_number`, `password`), hidden `_token` via `Security::csrfField()`, class `alert alert--error` bila diuji, dan seluruh teks nilai kunci (`Rp 28.937.500`, `14,850 gr`, `waterfall`, `50.00`, `Pengelola`) agar assertion suite lama tetap sahih.
5. `dashboard/index.php`: tambah tautan "Buka Meja Kasir" hanya untuk `role = operator`.
6. Perbarui assertion aset pada `tests/qc_routing.php` ke `app.min.css` + `alpine.min.js`, lalu hapus `public/assets/css/app.css`.

**Kriteria selesai:** `php tests/qc_routing.php` dan `php tests/qc_sql_injection.php` tetap exit `0`; tidak ada view yang memuat `app.css`; zero horizontal overflow pada lebar 360 px (verifikasi manual).

### T3 — Model kas & transaksi

**File:** `app/Models/CashRegister.php`, `app/Models/HuluTransaction.php`, `app/Models/CashMutation.php`

**Langkah:**
1. `CashRegister`: `TELLER_NAME`, `activeTeller()` (lookup by `register_name`), `lockForUpdate(string $id)` (`SELECT ... FOR UPDATE`), `balanceOf(string $id)`, `adjustBalance(string $id, string $deltaCanonical)` (UPDATE relatif `current_balance = current_balance + :delta`).
2. `HuluTransaction`: `insert(array $data): string` (UUID v4 via `Security::uuidV4()`), `recent(int $limit): array` (`ORDER BY created_at DESC, id DESC LIMIT n`), `holdingTotalGrams(): string`, `holdingCount(): int`.
3. `CashMutation`: `insert(array $data): void`, `recentFor(string $registerId, int $limit)`, `countFor(string $registerId, string $type)`.

**Kriteria selesai:** `php -l` bersih; SQL **hanya** ada di `app/Models/` + `app/Config/Database.php`; semua nilai dinamis lewat named parameter; nama kolom persis ERD (tidak ada kolom baru).

### T4 — `TellerService`

**File:** `app/Services/TellerService.php`

**Langkah:**
1. `quote(string $weightInput, string $priceInput, bool $burnFeeEnabled): array` — murni, tanpa DB: baca setting fee dari `SystemSetting`, terapkan aturan §4.3 & §4.4, kembalikan `weight` (string `DECIMAL(8,3)`), `price` (string `DECIMAL(14,2)`), `gross`, `fee`, `net`, `operator_cut`, `consumable_cut` (string kanonik) beserta nilai sen untuk perhitungan lanjutan.
2. `payCash(array $input): array` — validasi → `quote()` → `Database::transaction()` sesuai urutan §4.5, kembalikan ringkasan (`hulu_id`, `register_id`, `balance_before`, `balance_after`, `net_cash_paid`, `weight`, `alias`) untuk dipakai flash/redirect.
3. `Logger::info()` pada sukses (id transaksi, berat, net) dan `Logger::warning()` pada penolakan (alasan, **tanpa** data sensitif).

**Kriteria selesai:** kalkulasi murni dapat diuji tanpa DB; tidak ada `float` pada jalur uang; satu-satunya tempat `beginTransaction`/`commit`/`rollBack` berada adalah `Database::transaction()`.

### T5 — Controller, guard, dan route

**File:** `app/Controllers/TellerController.php`, `app/Controllers/BaseController.php`, `app/Helpers/Security.php`, `app/routes.php`

**Langkah:**
1. `BaseController::requireRole(string ...$roles)` + `flash()`/konsumsi `$_SESSION['_flash']` di `render()`.
2. `Security::issueFormToken()` + `Security::consumeFormToken()` (§4.6).
3. `TellerController::index()` — guard peran, saldo kas aktif, 3 transaksi terakhir, setting fee + status toggle untuk nilai awal form, token `_once`, render `operator/teller` dengan layout `operator`.
4. `TellerController::store()` — pemeriksaan CSRF kedua (pertahanan berlapis seperti `AuthController`), `_once`, validasi, `payCash()`, lalu `flash()` + redirect (PRG). Duplikat → redirect + pesan diabaikan. Gagal validasi → `422` + render ulang dengan input terpelihara.
5. Tambahkan dua route ke `app/routes.php` (data saja).

**Kriteria selesai:** investor → `302 /dashboard`; POST tanpa CSRF → `419`; POST tanpa `_once` → `419`; tidak ada query SQL di controller.

### T6 — Layar kasir hulu

**File:** `app/Views/operator/teller.php`

**Langkah:**
1. Widget saldo kas aktif di atas (`Formatter::rupiah()` + `emerald-cash`), dimuat server-side.
2. Field: alias penambang, berat kering (`inputmode="decimal"`, font besar monospace), harga tebus/gram, dan toggle jasa bakar flat (default aktif, nominal dari setting).
3. Blok "TOTAL KAS BERSIH KELUAR" — `x-data` Alpine menghitung ulang seketika memakai formatter mirror di `public/assets/js/app.js` (satu sumber format di sisi klien), nilai awal dirender server agar tetap benar tanpa JS.
4. Komponen **swipe-to-confirm** dari dokumen UI/UX §5.2: `touchstart`/`touchmove`/`touchend`, `translateX`, submit hanya bila ambang tercapai, lalu handle + tombol dinonaktifkan. Sertakan fallback `<noscript>` berupa tombol submit biasa (form tetap `method="post"` + `_token` + `_once`).
5. Daftar 3 transaksi terakhir (jam WIB, gram, net) dari `HuluTransaction::recent(3)`; semua output lewat `Security::e()`.

**Kriteria selesai:** form adalah POST nyata dengan CSRF + `_once`; setiap output dinamis lewat `Security::e()`; setiap angka lewat `Formatter`/formatter mirror; target sentuh ≥ 52 px.

### T7 — QC Fase 2

**File:** `tests/qc_fase_2.php`, `tests/bootstrap.php`, `tests/run_qc.sh`

**Langkah:**
1. Tambah `QcFixture::resetTellerState(): array` — set `cash_registers.current_balance` "Kas Meja Bakar" = `50000000.00`, hapus baris `hulu_transactions` + `cash_mutations` yang tertinggal dari run sebelumnya.
2. Tulis `qc_fase_2.php` mengikuti pola `qc_roadmap_protocol_1.php` (`Qc::suite/section/ok/equals/closeTo/throws/finish`), memakai `QcHttp` untuk bagian HTTP.
3. Daftarkan `qc_fase_2` pada `SUITES` di `tests/run_qc.sh` (setelah `qc_roadmap_protocol_1`), perbarui nama/header log.
4. Jalankan seluruh suite: `bash tests/run_qc.sh`.

**Kriteria selesai:** exit `0`, 8 suite lulus, tidak ada `storage/logs/php-*.log`, suite dapat dijalankan berulang.

### T8 — Dokumentasi & serah terima

**File:** `README.md`, `AGENTS.md`, `plan/Plan_Fase_2_AurumFlow.md`

**Langkah:**
1. `README.md`: perintah build Tailwind, struktur `resources/` + `app/Services/`, baris `qc_fase_2` pada tabel QC, peringatan bahwa suite menulis ke DB di `.env`.
2. `AGENTS.md`: baseline suite/assertion baru, perintah build CSS, aturan baru (`resources/`, `app/Services/`, `_once`).
3. Perbarui §11 dokumen ini dengan penyimpangan nyata yang terjadi saat eksekusi (pola addendum Fase 1).
4. `plan/Summary_Fase_2_AurumFlow.md` — laporan eksekusi (**opsional**, dibuat setelah verifikasi, mengikuti pola `Summary_Fase_1`).

**Kriteria selesai:** `bash tests/run_qc.sh` exit `0`; `git status` bersih tanpa artefak (`tests/.tmp/`, `storage/installed.lock`, `*.log`); review diff akhir hanya memuat file yang direncanakan.

---

## 6. Quality Control — Protokol QC Manual 2

**Berkas:** `tests/qc_fase_2.php` — PHP CLI, `exit(0)` = PASS, `exit(1)` = FAIL, didaftarkan pada `tests/run_qc.sh`.

**Prasyarat fixture:** saldo "Kas Meja Bakar" di-set **Rp 50.000.000,00** (via `QcFixture::resetTellerState()`, setara instruksi manual "isi saldo awal melalui phpMyAdmin"), lalu dua transaksi dijalankan **berurutan**.

> **Peringatan:** suite ini **menulis** ke database yang ditunjuk `.env` (menambah `hulu_transactions`, memotong `cash_registers`, menambah `cash_mutations`). Jangan arahkan ke produksi.

### 6.1 Tabel protokol (1:1 dengan dokumen Roadmap)

| # | Parameter Uji | Data Masukan | Hasil yang Diharapkan | Assertion |
|---|---|---|---|---|
| **P2.1** | Transaksi 1 — kas keluar bersih | Pak Anto · **14,850 gr** · Rp 1.950.000/gr · jasa bakar Rp 20.000 | Nilai tebus kotor `Rp 28.957.500` → kas keluar bersih `Rp 28.937.500`, tersimpan `gross_tebus_total = 28.957.500.00` dan `net_cash_paid = 28.937.500.00` | `gross`, `net` (string persis) + tampilan `Formatter::rupiah(28937500) === 'Rp 28.937.500'` |
| **P2.2** | Transaksi 1 — alokasi jasa bakar | idem | `Rp 10.000` upah pengelola + `Rp 10.000` penggantian kowi/gas | `operator_burn_cut = 10000.00`, `consumable_cut = 10000.00`, `flat_burn_fee = 20000.00`, `operator_cut + consumable_cut = flat_burn_fee` |
| **P2.3** | Transaksi 1 — status data | idem | Tersimpan di `hulu_transactions` dengan status `holding` | `status = 'holding'`, `settlement_id IS NULL`, `farmer_alias = 'Pak Anto'`, `dry_weight = 14.850` |
| **P2.4** | Transaksi 1 — sisa kas meja | idem | `Rp 50.000.000 − Rp 28.937.500 = Rp 21.062.500` | `cash_registers.current_balance = 21062500.00` (string persis) |
| **P2.5** | Transaksi 1 — mutasi kas | idem | Tercatat **1** baris `cash_mutations` | `mutation_type = 'out'`, `amount = 28937500.00`, `register_id` = id kas meja, `source_destination` memuat alias |
| **P2.6** | Transaksi 2 — kas keluar bersih | Petani B-02 · **3,200 gr** · Rp 1.950.000/gr · jasa bakar Rp 20.000 | Nilai tebus kotor `Rp 6.240.000` → kas keluar bersih `Rp 6.220.000` | `gross_tebus_total = 6240000.00`, `net_cash_paid = 6220000.00`, split `10000.00/10000.00`, `status = 'holding'` |
| **P2.7** | Transaksi 2 — sisa kas meja | idem | `Rp 21.062.500 − Rp 6.220.000 = Rp 14.842.500` | `current_balance = 14842500.00` (string persis) |
| **P2.8** | Transaksi 2 — akumulasi emas | idem | Total stok berstatus `holding` menjadi **18,050 gr** | `SUM(dry_weight) WHERE status='holding'` = `'18.050'` dan `holdingCount() = 2` |
| **P2.9** | Rekonsiliasi silang (untuk Fase 3) | akumulasi P2.1–P2.8 | Total modal belanja hulu `Rp 35.157.500` | `SUM(net_cash_paid) = 35157500.00`, `SUM(flat_burn_fee) = 40000.00`, `SUM(gross_tebus_total) = 35197500.00` |
| **P2.10** | Widget informasi | halaman kasir | Saldo kas aktif tampil dan 3 transaksi terakhir terdaftar | HTML memuat `Rp 14.842.500` dan baris riwayat (jumlah ≤ 3, memuat kedua transaksi uji) |
| **P2.11** | Rekap protokol | seluruh baris | Semua baris berstatus LULUS | Cetak tabel siap-salin ke `docs/AurumFlow Roadmap and QC Documents.md` |

### 6.2 Assertion tambahan di luar protokol manual

| Grup | Cakupan |
|---|---|
| **A. Kalkulasi murni** (`quote()`, tanpa DB) | Pembulatan *half away from zero* pada sisa sub-sen (mis. 0,333 gr × Rp 1.950.000,33); jasa bakar **nonaktif** → `fee = 0`, split `0/0`, `net = gross`; tolak berat ≤ 0 / bukan angka / > `99999.999`; tolak harga ≤ 0 / > `999999999999.99` |
| **B. Parsing input lapangan** | `'14,850'`, `'14.850'`, `'14.850 gr'` → 14,850; `'1.950.000'` → 1.950.000; `'14,85,0'`/`'--'`/string kosong ditolak dengan pesan jelas; byte invalid UTF-8 gagal aman |
| **C. Atomisitas (SAD §4.1)** | Paksa gagal di tengah transaksi (berat di luar batas kolom pada INSERT) → exception terlempar, **nol** baris `hulu_transactions` baru, saldo tak berubah, **nol** `cash_mutations` baru |
| **D. Saldo kurang** | `net > saldo` → transaksi ditolak, saldo & tabel transaksi tidak berubah, pesan "Saldo kas tidak cukup" |
| **E. Server tidak percaya klien** | POST dengan `net_cash_paid`/`flat_burn_fee` palsu → nilai tersimpan tetap hasil hitung server dari `system_settings` |
| **F. Anti double-submit** | POST sah 1× → 1 baris; **replay `_once` yang sama** → 0 baris baru, saldo tetap, dialihkan + pesan ganda diabaikan; POST tanpa CSRF → `419`; POST tanpa `_once` → `419` |
| **G. HTTP nyata (`QcHttp`)** | Operator login → `GET /operator/teller` `200` dan memuat saldo terformat; **investor → `302` ke `/dashboard`**; `POST` sukses → redirect PRG; login gagal → `401` |
| **H. Pemindaian statis** (`qc_scan_lines`) | Tidak ada `FLOAT`/`DOUBLE`/`floatval` untuk uang; tidak ada `number_format(` di luar `Formatter`; tidak ada interpolarsi `$_POST`/`$_GET`/`$_REQUEST` ke string SQL; seluruh output dinamis view memakai `Security::e()`; tidak ada URL CDN pada view; `app.min.css` < 30 KB dan memuat token tema |
| **I. Integritas skema** | `QcFixture::checkDecimalConventions()` untuk `hulu_transactions`, `cash_registers`, `cash_mutations` (uang `DECIMAL(14,2)`, berat `DECIMAL(8,3)`, PK `CHAR(36)`); Fase 2 **tidak** mengubah DDL |

### 6.3 Gate penerimaan

| Gate | Syarat |
|---|---|
| **G-1** | 8 suite QC (`qc_fase_2` baru) exit `0`; jumlah assertion naik dari baseline 191 |
| **G-2** | Tidak ada `E_WARNING`/`E_NOTICE`/`E_DEPRECATED` selama suite berjalan (tanpa `storage/logs/php-*.log`) |
| **G-3** | `git status` bersih; tanpa `tests/.tmp/`, `storage/installed.lock`, `*.log` |
| **G-4** | Seluruh angka tampilan berasal dari `Formatter`; seluruh output dinamis lewat `Security::e()` |
| **G-5** | Tidak ada perubahan skema/seed; `docs/` tidak berubah (kecuali **O-1** diambil) |
| **G-6** | Verifikasi manual di layar kasir: satu transaksi uji → saldo header, riwayat, dan isi tabel di phpMyAdmin konsisten; tidak ada overflow horizontal pada 360 px |

---

## 7. Risiko & Mitigasi

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| R-1 | Hosting tanpa Node.js / tanpa jaringan | CSS tidak dapat dibangun ulang | Hasil `app.min.css` di-commit; perintah build dipin versi di README; fallback binary standalone di `tools/` (gitignored) |
| R-2 | Angka preview Alpine.js berbeda dari angka server | Operator melihat nominal berbeda dari yang tersimpan | Satu formatter mirror di `public/assets/js/app.js`; QC grup E membuktikan server menghitung ulang |
| R-3 | Ambiguitas titik/koma pada field berat (`parseGram('14.850')` = 14.850 gr) | Timbangan terbaca 1000× lebih besar | Parser input lapangan khusus (§4.4) + QC grup B; `Formatter::parseGram()` tidak diubah |
| R-4 | Double submit / retry jaringan / tombol Back | Kas keluar dua kali untuk satu timbangan | Swipe + disable + token `_once` + session file-lock + QC grup F |
| R-5 | Dua terminal memakai saldo yang sama | Saldo kas minus / belanja melebihi kas | `SELECT ... FOR UPDATE` di dalam transaksi + guard saldo cukup |
| R-6 | `created_at` presisi detik → urutan "3 terakhir" seri | Urutan riwayat tidak deterministik dalam detik yang sama | QC seri-toleran (himpunan, bukan urutan); item **O-1** sebelum Fase 3 |
| R-7 | Migrasi tema memecahkan suite lama | Regression QC | Nama field/teks kunci dipertahankan; assertion aset diperbarui; seluruh 8 suite dijalankan ulang |
| R-8 | QC Fase 2 menulis ke DB di `.env` | Data dev/uji tercemar | Peringatan pada header suite, README, dan `AGENTS.md` |
| R-9 | MariaDB dev vs MySQL 8.0 produksi (item A-1 Fase 1) | Perilaku InnoDB/FK/`FOR UPDATE` belum diverifikasi di MySQL 8.0 | Tidak berubah Fase 2; ulangi `bash tests/run_qc.sh` pada MySQL 8.0 sebelum go-live |
| R-10 | Ukuran `app.min.css` membengkak | Target SAD < 30 KB terlampaui; halaman lambat di sinyal lemah | QC grup H memverifikasi ukuran; hindari kelas dinamis (bangun kelas lengkap di markup) |

---

## 8. Checklist Serah Terima Fase 2

- [ ] T1–T8 selesai dan berurutan.
- [ ] `bash tests/run_qc.sh` exit `0`; 8 suite; tanpa `php-*.log`.
- [ ] Protokol QC Manual 2 tercetak dan disalin ke dokumen Roadmap.
- [ ] `public/assets/css/app.css` terhapus; tidak ada referensi tersisa.
- [ ] `.htaccess` root menolak `resources/`.
- [ ] `README.md` + `AGENTS.md` memperbarui baseline suite/assertion dan perintah build.
- [ ] Review diff akhir: hanya file pada §3, tanpa artefak sementara.
- [ ] `plan/Summary_Fase_2_AurumFlow.md` (opsional) dibuat setelah verifikasi.

---

## 9. Item Terbuka (perlu keputusan sebelum/di awal eksekusi)

| # | Item | Rekomendasi |
|---|---|---|
| **O-1** | `hulu_transactions.created_at` presisi detik → urutan "3 transaksi terakhir" dapat seri dalam satu detik | Naikkan ke `TIMESTAMP(3)` **sebelum Fase 3** (butuh pembaruan ERD + addendum). Fase 2 tetap berjalan dengan QC seri-toleran. |
| **O-2** | Guard anti-double-submit `_once` (A-5) | Pertahankan — murah, tertutup test grup F, menutup celah yang tidak dapat ditutup JavaScript. |
| **O-3** | Pola pesan: PRG + flash untuk sukses, `422` + input terpelihara untuk gagal | Pertahankan (mencegah resubmit saat refresh). |
| **O-4** | Font self-host vs Google Fonts | Self-host — tanpa ketergantungan pihak ketiga, andal di lapangan. |
| **O-5** | Porsi jasa bakar (10.000/10.000) ditampilkan di layar kasir? | Tampilkan sebagai baris informasi kecil (audit operator); nilai tetap tersimpan di DB. |
| **O-6** | Alternatif penentuan kas aktif yang ditolak | (a) kunci `system_settings` baru — menuntut pembaruan ERD + seed + addendum; (b) `TELLER_REGISTER_NAME` di `.env` — menambah kanal konfigurasi kedua di luar DB. Ditunda sampai ada kebutuhan multi-meja. |

---

## 10. Lampiran — Penambahan di Luar SAD & Alur Request

### 10.1 Penambahan di luar daftar eksplisit SAD §3

| Penambahan | Alasan | Bila ditolak |
|---|---|---|
| `app/Services/TellerService.php` | Operasi kas menyentuh tiga tabel dalam satu transaksi; tidak ada rumah yang tepat di antara Controller (HTTP) dan Model (SQL). SAD sendiri menempatkan mesin kalkulasi (`Helpers/AllocationEngine.php`) sebagai kelas tersendiri. | Logika dipindah menjadi `HuluTransaction::payCash()`. |
| `resources/` | Tailwind butuh berkas sumber di luar docroot; `public/` hanya boleh memuat hasil build. | Berkas sumber diletakkan di root (`tailwind.input.css`) dan ditambahkan ke deny-list `.htaccess`. |
| `app/Views/layouts/operator.php` | Layar kasir butuh header sticky saldo kas dan tata letak mobile-first yang berbeda dari shell dashboard. | Pakai `layouts/app.php` dengan slot saldo di dalam view. |
| `tests/qc_fase_2.php` | Instruksi fase: mengotomatiskan Protokol QC Manual 2. | Protokol dijalankan manual lewat peramban. |
| `public/assets/fonts/` | Tipografi dokumen UI/UX (`Plus Jakarta Sans`, `JetBrains Mono`) tanpa CDN. | Kembali ke *system font stack* (digit monospace tetap dipertahankan). |

### 10.2 Alur request `POST /operator/teller`

```text
POST /operator/teller
   │
   ├─ public/index.php
   │     ├─ security header + session
   │     ├─ cocokkan route (method + path)
   │     ├─ CSRF (_token) ──(gagal)──► 419
   │     └─ dispatch TellerController::store()
   │
   ├─ TellerController
   │     ├─ requireAuth() + requireRole('operator') ──(investor)──► 302 /dashboard
   │     ├─ CSRF (pemeriksaan kedua) ──(gagal)──► 419
   │     ├─ Security::consumeFormToken(_once)
   │     │       ├─ duplikat ──► 302 + flash "ganda diabaikan"
   │     │       └─ tidak dikenal ──► 419
   │     ├─ validasi input ──(gagal)──► 422 + form terpelihara
   │     │
   │     └─ TellerService::payCash()
   │            └─ Database::transaction()
   │                  ├─ SELECT cash_registers … FOR UPDATE
   │                  ├─ cek saldo ──(kurang)──► exception ──► rollBack (0 perubahan)
   │                  ├─ INSERT hulu_transactions (holding)
   │                  ├─ UPDATE cash_registers (saldo − net)
   │                  └─ INSERT cash_mutations (out)
   │
   └─ flash success + 302 /operator/teller (POST-Redirect-GET)
```

---

# ADDENDUM — Catatan Eksekusi

> **Status: terverifikasi.** Rencana di atas dieksekusi **tanpa** perubahan
> skema, `system_settings`, maupun `docs/`. Laporan lengkap (daftar berkas,
> angka QC, deviasi, temuan) ada di `plan/Summary_Fase_2_AurumFlow.md`.

| Area | Rencana | Eksekusi | Alasan |
|---|---|---|---|
| Konfigurasi Alpine | Nilai inline pada `x-data` | JSON dari controller, dicetak via `Security::e()` | Menjaga kontrak "semua output dinamis di-escape" (QC H.4) |
| Toggle jasa bakar | Bagian konfigurasi Alpine | Atribut `checked` hasil render server | Status dari server tetap benar saat JS mati maupun setelah 422 |
| Pemindai statis | `qc_scan_lines()` | Pemindai lokal `qc2_scan()` untuk berkas Fase 2 | Predikat harness tidak menerima path, jadi tidak dapat dibatasi per berkas |
| Cermin Formatter di JS | "satu formatter JS" | Ditambah verifikasi manual dengan Node | Gate QC tetap PHP-only (hosting belum tentu punya Node.js) |
| **Perbaikan pascarilis nilai awal form** (`plan/Plan_Fix_Settlement_UI.md`) | — | `tellerForm.init()` membaca `weight`/`price`/toggle jasa bakar lewat DOM (`querySelector`), bukan `$refs`; `x-model.fill` pada dua isian angka; placeholder `mis. 14,850` / `mis. 1.950.000`; assertion baru `G.3a` + `H.10`–`H.12` | Isian yang diketik operator **hilang** dan toggle jasa bakar balik `checked` setelah kegagalan validasi (`422`) — akar yang sama dengan temuan layar rekonsiliasi (klaim § O-3 "isian terpelihara" hanya berlaku pada jalur tanpa JavaScript). Gate setelah perbaikan: **9 suite · 355 assertion · 0 gagal** |

Hasil gate: **8 suite · 254 assertion · 0 gagal** (exit 0), tanpa
`storage/logs/php-*.log`, tanpa perubahan DDL.

---

*Dokumen ini adalah kontrak eksekusi Fase 2. Penyimpangan yang terjadi saat implementasi dicatat pada addendum di atas dan pada `plan/Summary_Fase_2_AurumFlow.md`.*
