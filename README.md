# AurumFlow

Sistem manajemen operasional privat untuk **perdagangan emas aluvial** dengan
settlement **T+1**.

Repositori ini memuat Fase 1 (Fondasi Sistem, Basis Data, & Arsitektur Inti)
beserta dokumen spesifikasi otoritatifnya.

| Dokumen | Peran |
|---|---|
| `docs/AurumFlow Entity Relationship Diagram.md` | **Sumber kebenaran skema database** (ERD §3.1 tipe data, §4 DDL + seed) |
| `docs/AurumFlow System Architecture Document.md` | Struktur direktori, tech stack, aturan keamanan |
| `docs/AurumFlow Roadmap and QC Documents.md` | Isi per fase + protokol QC |
| `docs/AurumFlow Product Requirement Document v3.0.md` | Aturan bisnis & hak akses |
| `docs/AurumFlow UI UX Documents.md` | Acuan UI Fase 2+ |
| `Plan_Fase_1_AurumFlow.md` | Rencana eksekusi Fase 1 + addendum rekonsiliasi |

> **Status: Fase 1 selesai dan terverifikasi** — 7 suite QC, 185 assertion, 0 gagal.
> Belum ada modul bisnis; meja kasir, settlement, dan Waterfall Engine masuk
> Fase 2–4.

---

## 1. Ringkasan teknologi

| Aspek | Nilai |
|---|---|
| Runtime | Native PHP **8.2+** (diuji pada 8.3.6), tanpa framework |
| Database | **MySQL 8.0** / InnoDB untuk produksi |
| Autoloading | Manual (`spl_autoload_register`), nol beban framework |
| Konfigurasi | File `.env` dengan override dari environment variable |
| Arsitektur | MVC ringkas + front controller, document root hanya `public/` |
| Styling | CSS/JS minimal (Tailwind CLI & Alpine.js dijadwalkan Fase 2) |

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
├── .htaccess                 # deny app/ database/ storage/ tests/ (defense in depth)
├── Plan_Fase_1_AurumFlow.md
├── docs/                     # 5 dokumen spesifikasi otoritatif
├── app/                      # KODE INTI — di luar document root
│   ├── Config/
│   │   ├── Env.php           # parser .env
│   │   ├── App.php           # nama, base URL, timezone, kebijakan session
│   │   ├── Constants.php     # konstanta kode (peran, konvensi tampilan)
│   │   ├── Database.php      # PDO singleton + query helpers
│   │   ├── DatabaseException.php
│   │   └── bootstrap.php     # autoloader, error handler, timezone
│   ├── Controllers/
│   │   ├── BaseController.php
│   │   ├── AuthController.php
│   │   └── DashboardController.php
│   ├── Helpers/
│   │   ├── Security.php      # XSS, password, CSRF, UUID, session
│   │   ├── Formatter.php     # Rupiah & gramatur 3 desimal
│   │   └── Logger.php        # log file + fallback error_log
│   ├── Models/
│   │   ├── User.php
│   │   └── SystemSetting.php
│   ├── Views/
│   │   ├── layouts/          # app.php, auth.php
│   │   ├── auth/login.php
│   │   ├── dashboard/index.php
│   │   └── errors/           # 404.php, 500.php, error.php
│   └── routes.php            # route table (data saja)
├── database/
│   ├── schema.sql            # DDL ERD (9 tabel)
│   ├── seed.sql              # system_settings + cash_registers
│   └── install.php           # runner instalasi (WAJIB dihapus setelah dipakai)
├── public/                   # ← DOCUMENT ROOT
│   ├── index.php             # front controller
│   ├── .htaccess
│   ├── assets/{css,js}/
│   └── uploads/              # eksekusi skrip diblokir
├── storage/logs/             # writable, di luar docroot
└── tests/                    # harness QC + 7 suite
```

**Aturan penting:** hanya isi `public/` yang boleh diakses lewat HTTP.

---

## 4. Instalasi

### 4.1 Siapkan konfigurasi

```bash
cp .env.example .env
```

| Variabel | Keterangan |
|---|---|
| `APP_URL` | URL aplikasi |
| `APP_DEBUG` | `false` di produksi (pesan generik; detail hanya ke log) |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | Kredensial MySQL 8.0 |
| `OPERATOR_FULL_NAME`, `OPERATOR_PHONE`, `OPERATOR_PASSWORD` | Akun operator awal |
| `INSTALL_TOKEN` | Token acak untuk mengizinkan instalasi lewat browser |

> **Identitas login adalah nomor telepon** (`users.phone_number`), bukan
> username atau email — sesuai ERD. Tidak ada kolom email pada skema.

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

Instalasi akan: menerapkan `schema.sql` (9 tabel ERD), `seed.sql`
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

Semua suite juga dapat dijalankan sendiri-sendiri:

```bash
php tests/qc_pdo.php
php tests/qc_formatter.php
php tests/qc_security.php
php tests/qc_seed.php
php tests/qc_roadmap_protocol_1.php
php tests/qc_sql_injection.php
php tests/qc_routing.php
```

| Suite | Assertion | Cakupan |
|---|---:|---|
| `qc_pdo` | 20 | Koneksi, charset, timezone, exception, singleton, presisi DECIMAL, transaksi commit/rollback |
| `qc_formatter` | 37 | Format Rupiah & gramatur (kesamaan string persis), round-trip, locale |
| `qc_security` | 32 | XSS escaping, password hashing, CSRF, session, `uuidV4()`, sumber acak |
| `qc_seed` | 46 | Idempotensi DDL/seed, 9 tabel ERD, konvensi DECIMAL, FK, ENUM, guard installer |
| `qc_roadmap_protocol_1` | 6 | **Protokol QC Manual 1** dari dokumen Roadmap (5 baris, 1:1) |
| `qc_sql_injection` | 15 | 8 payload injeksi (model + HTTP), integritas data, pemindaian statis |
| `qc_routing` | 29 | Routing, CSRF, login/logout, cookie, header, proteksi file sensitif |
| **Total** | **185** | **0 FAIL** |

Exit code `0` = seluruh assertion lulus, `1` = ada yang gagal. `run_qc.sh`
menulis keluaran lengkap ke `storage/logs/qc-fase1.log`.

`qc_roadmap_protocol_1` mencetak tabel yang bentuknya sama dengan tabel
"Protokol QC Manual 1" pada dokumen Roadmap, sehingga checklist FASE 1 dapat
ditandai berdasarkan bukti:

```
| Parameter Uji            | Data Masukan               | Hasil yang Diharapkan            | Status |
| Koneksi PDO              | Database::getConnection()  | Objek PDO aktif, tanpa galat     | [✓]    |
| Pencegahan SQL Injection | ' OR '1'='1                | Lolos sebagai literal, kueri aman| [✓]    |
| Format Gramatur          | 14.85                      | 14,850 gr                        | [✓]    |
| Format Rupiah            | 28937500                   | Rp 28.937.500                    | [✓]    |
| Seed Konfigurasi         | Pembacaan system_settings  | operator_base_quota_pct = 50.00  | [✓]    |
```

> Suite `qc_seed`, `qc_roadmap_protocol_1`, `qc_sql_injection`, dan `qc_routing`
> **mengubah data** pada database yang ditunjuk `.env` (membuat skema, seed,
> dan akun operator). **Jangan arahkan ke database produksi.**

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

### 7.6 Alur operasional yang dituju (Fase 2+)

```
Kas Meja Bakar (cash_registers)
   └─ hulu_transactions (status holding)
         └─ daily_settlements (tutup buku harian)
               ├─ profit_distributions (pro-rata ke capital_wallets)
               └─ withdrawal_requests (penarikan H-7)
```

Fase 1 hanya menyiapkan skema dan fondasinya; logika bisnis belum ada.

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

---

## 9. Catatan lingkungan pengembangan ini

Mesin dev yang dipakai menyusun Fase 1 **tidak** memiliki MySQL 8.0. QC
dijalankan terhadap **MariaDB 12.3.2** (MySQL-compatible) pada database
sementara `test_aurumflow_qc`, diatur melalui `.env`.

Konsekuensinya:

- Seluruh pernyataan QC (InnoDB, utf8mb4, DECIMAL, JSON, FOREIGN KEY, ENUM,
  prepared statement, transaksi) berperilaku sama pada MariaDB dan MySQL 8.0.
- **Sebelum go-live**, arahkan `.env` ke MySQL 8.0 sungguhan lalu jalankan
  ulang `bash tests/run_qc.sh` sebagai verifikasi akhir. Tidak ada perubahan
  kode yang diperlukan. (Item terbuka **A-1** pada addendum rencana.)
