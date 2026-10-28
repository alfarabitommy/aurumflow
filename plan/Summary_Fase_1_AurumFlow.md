# Summary Eksekusi Fase 1 — AurumFlow

> **Status dokumen (pasca-Fase 9 — sinkronisasi dokumentasi).** Seluruh angka dan perilaku di
> bawah ini — termasuk (bila ada) penyebutan **13 tabel** atau kurang, **14 suite / 853
> assertion**, `CACHE_NAME` **v3**, dan isian **`coord_fee`** — adalah **potret fase tersebut**,
> bukan keadaan sekarang. Keadaan terkini: **14 tabel** (termasuk `operational_expenses`, nol `ALTER`),
> `CACHE_NAME` **`aurumflow-shell-v4`**, **15 suite / 900 assertion / 0 gagal**, beban operasional
> dicatat sebagai **voucher kas** di meja kasir lalu diserap otomatis oleh tutup buku
> (`total_cash = pendapatan`). Rujukan terkini: `plan/Summary_Fase_9_OPEX_AurumFlow.md`.


> **Laporan eksekusi.** Mendampingi `plan/Plan_Fase_1_AurumFlow.md` (rencana +
> addendum rekonsiliasi). Dokumen ini mencatat **apa yang benar-benar
> dieksekusi, apa yang diuji, dan apa yang menyimpang** — termasuk hal yang
> tidak berjalan sesuai rencana.

| Item | Nilai |
|---|---|
| Status | **Selesai & terverifikasi** |
| Fase | 1 — Fondasi Sistem, Basis Data, & Arsitektur Inti |
| Commit | `be6b45b` — *"Fase 1: fondasi AurumFlow sesuai DDL ERD otoritatif"* |
| Runtime uji | PHP **8.3.6** (CLI) |
| Database uji | **MariaDB 12.3.2** — database sementara `test_aurumflow_qc` |
| Database target | **MySQL 8.0 / InnoDB** (belum diverifikasi, lihat §9 A-1) |
| Kode | 6.461 baris PHP + SQL, 52 file ter-track |
| QC | **7 suite · 191 assertion · 0 gagal** |
| Kode aplikasi | Belum ada modul bisnis (sesuai lingkup Fase 1) |

---

## 1. Ringkasan hasil

Fase 1 menghasilkan fondasi yang dapat dibangun Fase 2 di atasnya:

1. **Lapisan database** — `Database::getConnection()` singleton PDO dengan
   `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`, `utf8mb4_unicode_ci`,
   timezone `+07:00`, serta helper query yang **wajib** memakai named parameter.
2. **Skema ERD penuh** — 9 tabel sesuai DDL otoritatif, dengan konvensi tipe
   data yang dibekukan: `DECIMAL(14,2)` uang, `DECIMAL(8,3)` gram,
   `DECIMAL(5,2)` persen, primary key UUID `CHAR(36)`.
3. **Front controller & routing** — satu pintu masuk `public/index.php` dengan
   route table sebagai data (`app/routes.php`), plus `.htaccess` yang
   memisahkan `app/` dari document root.
4. **Helper keamanan** — escaping XSS, `password_hash`, CSRF, `uuidV4()`,
   hardening cookie session, dan autentikasi berbasis session.
5. **Helper formatter** — Rupiah dan gramatur 3 desimal sebagai satu-satunya
   sumber kebenaran tampilan angka.
6. **Installer** — `database/install.php` dengan guard token ganda, aman
   dijalankan berulang, dan tidak destruktif.

---

## 2. Cara mereproduksi verifikasi

```bash
# Prasyarat: PHP 8.2+ dengan pdo_mysql, curl, openssl, session.
cp .env.example .env          # isi DB_* dan OPERATOR_*

# Verifikasi lengkap (mengubah data pada database di .env!)
bash tests/run_qc.sh          # exit 0 = semua lulus
```

Keluaran lengkap tersimpan di `storage/logs/qc-fase1.log` (di-gitignore).

Server pengembangan:

```bash
php -S 127.0.0.1:8000 -t public tests/server_router.php
```

---

## 3. Hasil QC

| Suite | Assertion | Hasil | Cakupan |
|---|---:|---|---|
| `qc_pdo` | 20 | ✅ PASS | Koneksi, charset, timezone, exception, singleton, presisi DECIMAL, **transaksi commit/rollback** |
| `qc_formatter` | 43 | ✅ PASS | Format Rupiah & gramatur (kesamaan string persis), round-trip, independensi locale, tanggal operasi |
| `qc_security` | 32 | ✅ PASS | XSS escaping, password hashing, CSRF + kedaluwarsa, session, `uuidV4()`, sumber acak, pemindaian statis |
| `qc_seed` | 46 | ✅ PASS | Idempotensi DDL/seed, 9 tabel ERD, konvensi DECIMAL, UUID PK, FK RESTRICT, ENUM, 8 guard installer |
| `qc_roadmap_protocol_1` | 6 | ✅ PASS | **Protokol QC Manual 1** dokumen Roadmap, 5 baris dipetakan 1:1 |
| `qc_sql_injection` | 15 | ✅ PASS | 8 payload injeksi (model + HTTP), integritas data, pemindaian statis |
| `qc_routing` | 29 | ✅ PASS | Routing, CSRF lintas-session, login/logout, cookie, header, proteksi file sensitif |
| **Total** | **191** | **0 FAIL** | exit code 0 |

### 3.1 Protokol QC Manual 1 (dokumen Roadmap)

Dijalankan otomatis oleh `qc_roadmap_protocol_1.php`, memakai parameter dan
harapan **persis** seperti tertulis di dokumen:

| Parameter Uji | Data Masukan | Hasil yang Diharapkan | Status |
|---|---|---|---|
| Koneksi PDO | `Database::getConnection()` | Objek PDO aktif, tanpa galat | ✅ |
| Pencegahan SQL Injection | `' OR '1'='1` | Lolos sebagai literal, kueri aman | ✅ |
| Format Gramatur | `14.85` | `14,850 gr` | ✅ |
| Format Rupiah | `28937500` | `Rp 28.937.500` | ✅ |
| Seed Konfigurasi | `system_settings` | `operator_base_quota_pct` = `50.00` | ✅ |

### 3.2 Bukti tambahan di luar protokol

- **Transaksi PDO** (diwajibkan SAD §4.1) diuji commit **dan** rollback —
  bukan hanya diasumsikan bekerja.
- **Kebocoran kredensial**: QC 1.7 memastikan pesan ke pengguna generik dan
  password yang salah **tidak** masuk ke file log.
- **Reflected XSS**: payload `<script>` dikirim ke `POST /login` dan
  diverifikasi kembali dalam bentuk ter-escape.
- **Guard installer lewat HTTP**: server sementara dengan document root =
  root proyek dipakai untuk membuktikan guard token bekerja **bahkan ketika
  `.htaccess` tidak melindungi** `database/`.
- **Smoke test HTTP manual**: `/dashboard` tanpa login → `302 /login`; login →
  `302 /dashboard`; dashboard menampilkan `Rp 28.937.500`, `14,850 gr`,
  `waterfall`, `50.00`, `Pengelola`.

**Kualitas sinyal:** tidak ada `E_WARNING`/`E_NOTICE`/`E_DEPRECATED` selama
suite berjalan (tidak ada `storage/logs/php-*.log`). Isi `storage/logs/` hanya
berisi kegagalan yang **memang disengaja** oleh test (1 `critical` dari uji
kredensial salah, 10 `warning` dari uji login gagal, 2 `info` login sukses).

---

## 4. Inventaris file yang dibuat

### 4.1 `app/` — logika backend (22 file)

| File | Peran |
|---|---|
| `Config/bootstrap.php` | Autoloader manual (`spl_autoload_register` + guard path), timezone, error/exception/shutdown handler |
| `Config/Env.php` | Parser `.env` berbasis baris (tanpa Composer); `getenv()` menang atas file |
| `Config/App.php` | Konfigurasi ter-resolve: nama, base URL, timezone, kebijakan session, `utcOffset()`, `url()` |
| `Config/Constants.php` | Nilai milik kode: enum peran + konvensi desimal tampilan |
| `Config/Database.php` | PDO singleton, query helper, transaksi, `executeSqlFile()` + `splitStatements()` |
| `Config/DatabaseException.php` | Exception lapisan database dengan pesan generik |
| `Controllers/BaseController.php` | `render()`, `redirect()`, `requireAuth()`, `requireGuest()`, `abort()` |
| `Controllers/AuthController.php` | Login via `phone_number`, logout POST + CSRF |
| `Controllers/DashboardController.php` | Halaman asap: bukti session + DB + formatter |
| `Helpers/Security.php` | `e()`, `sanitize()`, password, CSRF, session, `uuidV4()`, `isUuid()` |
| `Helpers/Formatter.php` | `rupiah()`, `gram()`, parser kebalikannya, `tanggalId()` |
| `Helpers/Logger.php` | Log file harian dengan fallback `error_log()` |
| `Models/User.php` | `findByPhoneNumber()`, `verifyPassword()`, `create()`, `countActive()`, `clientIp()` |
| `Models/SystemSetting.php` | `all()`, `get()` untuk `system_settings` |
| `routes.php` | Route table (data saja) |
| `Views/` (7 file) | `layouts/`, `auth/login`, `dashboard/index`, `errors/{404,500,error}` |

### 4.2 `database/` (3 file)

| File | Peran |
|---|---|
| `schema.sql` | DDL ERD — 9 tabel, idempotent (`CREATE TABLE IF NOT EXISTS`) |
| `seed.sql` | 6 `system_settings` + 2 `cash_registers`, idempotent |
| `install.php` | Runner instalasi: guard token, marker lock, akun operator dalam transaksi |

### 4.3 `public/` — document root (6 file)

`index.php` (front controller + CSRF + dispatch), `.htaccess` (rewrite),
`assets/css/app.css`, `assets/js/app.js`, `uploads/.htaccess` (blokir eksekusi
skrip), `uploads/.gitignore`.

### 4.4 `tests/` — QC (10 file)

`bootstrap.php` (harness `Qc`, `QcHttp`, `QcFixture`), `server_router.php`
(meniru `.htaccess` untuk `php -S`), 7× `qc_*.php`, `run_qc.sh`.

### 4.5 Root & lain-lain

`.env.example`, `.gitignore`, `.htaccess` (deny `app/ database/ storage/
tests/`), `README.md`, `storage/logs/.gitignore`, `plan/` + `docs/` (dokumen).

---

## 5. Deviasi dari rencana yang disetujui

### 5.1 Lingkungan pengujian — **tidak sesuai instruksi, disengaja**

Instruksi: *"jika PHP CLI dan MySQL/MariaDB client tidak tersedia,
konfigurasikan SQLite sementara untuk QC Fase 1"*.

Temuan: PHP CLI **tersedia** (8.3.6). Client MySQL tidak ada, tetapi
**server MariaDB sudah berjalan** di `127.0.0.1:3306`. Sebaliknya,
`pdo_sqlite` **tidak terpasang** dan `sudo` diblokir (`no new privileges`),
sehingga fallback SQLite **secara teknis tidak dapat dibangun**.

Tindakan: QC dijalankan pada database sementara `test_aurumflow_qc` di MariaDB
(MySQL-compatible). **Ini lebih baik** untuk constraint "arsitektur tetap
kompatibel MySQL 8.0" karena menguji jalur `pdo_mysql` + InnoDB nyata, bukan
shim. Produksi tidak berubah: hanya nilai `.env`.

### 5.2 Rekonsiliasi dokumen otoritatif

Folder `docs/` muncul **setelah** rencana disetujui dan bertentangan dengan
rencana pada 9 titik (nama method koneksi, nama tabel setting, identitas login,
tipe DECIMAL, primary key, cakupan tabel, dll.). Seluruh pertentangan dilaporkan
sebelum commit, lalu direkonsiliasi sesuai keputusan
**`dec-b6694540f88bc67d`**. Rinciannya di
`plan/Plan_Fase_1_AurumFlow.md` §A.1–A.7.

### 5.3 File yang ditambahkan di luar rencana

| File | Alasan |
|---|---|
| `app/Helpers/Logger.php` | §4.3 mewajibkan logging tetapi tidak memberi rumah; SAD tidak mencantumkannya |
| `app/Config/DatabaseException.php` | Agar exception dapat di-autoload sendiri saat konstruksi koneksi gagal |
| `app/Views/errors/error.php` | Halaman generik untuk 400/403/419 (404 & 500 punya view sendiri) |
| `app/Models/` | Pemisahan layer: SQL tidak boleh ada di controller |
| `app/routes.php` | Memisahkan data route dari logika dispatch |
| `tests/server_router.php` | `php -S` tidak membaca `.htaccess`; dipakai agar QC menguji HTTP nyata |
| `plan/` | Pemisahan dokumen perencanaan dari kode (permintaan lanjutan) |

### 5.4 API yang sengaja **tidak** dibuat

| API pada rencana | Alasan tidak dibuat |
|---|---|
| `BaseController::json()` | Tidak ada pemakai di Fase 1; rencana sendiri melarang kode mati (§8) |
| `Security::fingerprint()` | Rencana menandainya opsional; berisiko memutus session saat User-Agent berubah |
| `User::findById()`, `User::userAgent()` | Dibuat saat eksplorasi, lalu **dihapus** pada review akhir karena nol pemakai |
| `activity_logs` | Tidak ada di ERD — dihapus dan digantikan logging file |

### 5.5 Penyimpangan yang disengaja dari ERD

1. **`DROP TABLE IF EXISTS` dimatikan.** ERD membuka skripnya dengan 9×
   `DROP TABLE`; untuk installer di hosting produksi itu berarti satu salah
   ketik = kehilangan seluruh pembukuan. Diganti `CREATE TABLE IF NOT EXISTS`
   (idempotent, diuji QC 4.2). Blok RESET tetap tersedia sebagai komentar.
2. **UUID tidak memakai `bin2hex(random_bytes(16))`** — lihat §6.
3. **`Constants.php` tidak memuat parameter bisnis.** Kuota serapan dan jasa
   bakar tetap di `system_settings` sesuai ERD; menduplikasinya sebagai
   konstanta PHP akan menciptakan dua sumber kebenaran.
4. **Tailwind/Alpine/PWA ditunda ke Fase 2** — checklist FASE 1 pada Roadmap
   tidak menyebutnya.

---

## 6. Temuan terhadap dokumen otoritatif

Dua masalah pada dokumen yang saya temukan saat implementasi:

1. **ERD §5.3 keliru.** Dokumen menyatakan kolom `CHAR(36)` "kompatibel dengan
   fungsi bawaan PHP `bin2hex(random_bytes(16))`". Fungsi itu menghasilkan
   **32** karakter hex tanpa tanda hubung — tidak dapat disimpan pada
   `CHAR(36)`. Diimplementasikan `Security::uuidV4()` yang membentuk UUID v4
   sesuai RFC 4122 (diuji QC 6.19, termasuk bit versi & varian).
2. **`Database::getConnection()` tidak pernah didefinisikan.** Nama itu
   disebut pada tabel Protokol QC Manual 1 dokumen Roadmap, tetapi tidak ada
   dokumen yang menetapkan lapisan database. Nama tersebut yang dipakai.

Keduanya sudah dicatat pada addendum rencana (§A.4) agar dokumen sumber dapat
diperbaiki.

---

## 7. Review akhir diff

Kode mati yang ditemukan dan dibersihkan sebelum commit akhir:

| Simbol | Status | Tindakan |
|---|---|---|
| `Formatter::tanggalId()` | 0 pemakai | **Dipakai nyata** — dashboard menampilkan "Tanggal operasi" (konsep inti sistem T+1) + 6 assertion QC baru |
| `User::findById()` | 0 pemakai | Dihapus |
| `User::userAgent()` | 0 pemakai (yatim setelah `activity_logs` dihapus) | Dihapus — kolom `signed_user_agent` ditulis pada Fase 5 |

Simbol yang hanya dipakai QC (`Security::isUuid()`, `Logger::currentFile()`,
`Database::disconnect()`) **dipertahankan** karena merupakan API pendukung
pengujian berbagi-pakai, bukan jalur mati.

### 7.1 Cacat harness QC yang ditemukan setelah commit Fase 1

Ditemukan saat menulis `AGENTS.md`: **PHP membuang environment variable
bernilai string kosong** pada argumen env `proc_open()`. Akibatnya override
`['INSTALL_TOKEN' => '']` yang dipakai QC 4.14 sampai ke proses anak dalam
keadaan **tidak ada**, sehingga `Env::get()` jatuh kembali ke nilai di `.env`
dan test itu **vakum** — ia hanya lulus selama `.env` kebetulan memuat token
kosong. Begitu `.env` diisi token nyata, 4.14 langsung gagal.

Perbaikan: `qc_run_php()` tidak lagi mengirim override lewat environment,
melainkan sebagai JSON (`QC_ENV_OVERRIDES`) yang diterapkan dengan `putenv()`
di dalam proses anak lewat stub `auto_prepend_file` (`tests/.tmp/`).

Diverifikasi: dengan `INSTALL_TOKEN` **non-kosong** di `.env`, 4.14 kini tetap
`[PASS]` — membuktikan cabang "token kosong" benar-benar diuji.

Pelajaran yang dicatat di `AGENTS.md`: jangan memakai `proc_open()` dengan
array env untuk override; selalu lewat `qc_run_php()`.

---

## 8. Status Gate Penerimaan (rencana §6.7)

| Gate | Syarat | Status |
|---|---|---|
| G-1 | QC-1 s.d. QC-6 seluruhnya PASS | ✅ 7/7 suite, 191 assertion |
| G-2 | Tidak ada warning/notice/deprecated saat suite berjalan | ✅ tidak ada `php-*.log` |
| G-3 | `.env`, log, `public/uploads/*` tidak ter-commit | ✅ diverifikasi via `git check-ignore` |
| G-4 | `public/` satu-satunya direktori yang dapat diakses via HTTP | ✅ QC 5.13–5.14c |
| G-5 | `database/install.php` dihapus dari hosting produksi | ⏳ **belum** — belum ada deployment |
| G-6 | Review diff akhir bersih, tanpa file sementara | ✅ setelah §7 |

---

## 9. Item terbuka & langkah berikutnya

| # | Item | Dampak | Tindakan |
|---|---|---|---|
| **A-1** | QC berjalan pada MariaDB 12.3.2, bukan MySQL 8.0 | **Blocker go-live** | Arahkan `.env` ke MySQL 8.0 lalu jalankan ulang `bash tests/run_qc.sh`. Tidak ada perubahan kode. |
| A-2 | `database/install.php` masih ada di repositori | Risiko keamanan bila ter-deploy | Hapus dari hosting setelah instalasi; `INSTALL_TOKEN` dikosongkan |
| A-3 | Kolom audit waiver (`onboarding_signed_at`, `signed_ip_address`, `signed_user_agent`) belum ditulis | Belum fungsional | Fase 5 (onboarding digital) |
| A-4 | 6 tabel non-`users`/`system_settings` belum disentuh kode | Wajar | Fase 2–4 |
| A-5 | Input byte invalid UTF-8 gagal aman tetapi belum ramah pengguna | UX minor | Validasi encoding input di fase berikutnya (catatan QC 2.8) |
| A-6 | Tailwind/Alpine/PWA belum disiapkan | UI masih CSS/JS minimal | Fase 2 |
| A-7 | `must_change_password` tidak ada di ERD | Password awal tidak dipaksa ganti | Pertimbangkan menambahkannya ke ERD sebelum produksi |

---

## 10. Yang **tidak** ada di Fase 1 (sesuai lingkup)

Meja kasir hulu, kalkulasi harga tebus & jasa bakar, likuidasi toko, mesin
Waterfall/Pro-Rata, dasbor blind investor, pengajuan penarikan modal, ekspor
laporan, upload nota, dan PWA — semuanya Fase 2–5.

Skema untuk semuanya **sudah siap** (9 tabel), sehingga fase berikutnya tidak
memerlukan migrasi struktur.

---

*Dokumen ini adalah laporan eksekusi, bukan kontrak. Kontraknya ada di
`plan/Plan_Fase_1_AurumFlow.md`.*
