# Plan Fase 1 — AurumFlow

> **Dokumen Perencanaan Eksekusi**
> Proyek: **AurumFlow** — sistem manajemen operasional privat untuk perdagangan emas aluvial dengan settlement **T+1**.
> Fase: **1 — Foundation, Database, & Core Architecture**
> Status: `APPROVED FOR PLANNING` · Belum ada kode yang dieksekusi.

| Item | Nilai |
|---|---|
| Runtime | Native PHP **8.2+** (`declare(strict_types=1)`) |
| Database | MySQL **8.0**, storage engine **InnoDB** |
| Framework | **Tidak ada** (no Laravel/Symfony/CI). Tanpa Composer, tanpa `vendor/` |
| Target hosting | Shared hosting / cPanel (PHP via MultiPHP, MySQL via cPanel) |
| Arsitektur | Modern monolith, split `app/` (logic) ↔ `public/` (web root) |
| Bahasa dokumen | Prosa Indonesia + istilah teknis Inggris |
| Disusun | Fase 1 dari roadmap berjenjang |

---

## 1. Ringkasan Fase 1

### 1.1 Tujuan

Fase 1 membangun **fondasi teknis** yang menjadi tempat semua fitur bisnis berikutnya berdiri. Fase ini tidak menghasilkan fitur bisnis — ia menghasilkan **kontrak arsitektur** yang sudah teruji: koneksi database yang aman, routing yang dapat diprediksi, helper keamanan yang dipakai konsisten, dan formatter yang menjadi satu-satunya sumber kebenaran untuk tampilan angka Rupiah dan gramatur.

Secara konkret, Fase 1 harus menghasilkan:

1. **Pemisahan direktori yang aman** — seluruh logika bisnis berada di `app/`, hanya `public/` yang terekspos web.
2. **Lapisan database tunggal** — satu titik akses PDO dengan prepared statement wajib, exception-based error handling, dan konfigurasi `utf8mb4` penuh.
3. **Front controller** — satu pintu masuk `public/index.php` dengan route table sederhana dan inisialisasi session-based auth.
4. **Helper keamanan** — XSS escaping, password hashing, CSRF token, session hardening.
5. **Helper formatter** — format Rupiah (`Rp 28.937.500`) dan gramatur 3 desimal (`14,850 gr`) sebagai satu-satunya implementasi resmi di seluruh aplikasi.

### 1.2 Definisi Selesai (Definition of Done)

Fase 1 dinyatakan selesai apabila **seluruh** poin berikut terpenuhi:

- [ ] Struktur direktori sesuai §3 dan tidak ada file logika di dalam `public/`.
- [ ] `app/Config/Database.php` dapat membuka koneksi dan mengeksekusi prepared statement.
- [ ] `public/index.php` melayani minimal 4 route (`GET /login`, `POST /login`, `POST /logout`, `GET /dashboard`) + 404.
- [ ] Login dengan akun hasil seed berhasil, dan session ID ter-regenerasi setelah login.
- [ ] `app/Helpers/Security.php` dan `app/Helpers/Formatter.php` lulus seluruh test case di §6.
- [ ] `database/schema.sql` + `database/seed.sql` dapat dijalankan ulang (idempotent) tanpa error.
- [ ] **Seluruh test QC di §6 berstatus PASS.**
- [ ] Tidak ada credential, hash, atau path absolut yang ter-commit.

### 1.3 Di Luar Ruang Lingkup Fase 1

Secara eksplisit **tidak** dikerjakan di fase ini (dijadikan backlog fase berikutnya):

- Transaksi jual-beli, inventori, ledger, dan perhitungan settlement **T+1**.
- Modul laporan, ekspor, cetak nota/invoice.
- Manajemen user CRUD lengkap, role-permission granular, audit trail UI.
- Upload file nyata (folder `public/uploads/` hanya disiapkan).
- Integrasi pembayaran, email/SMTP, WhatsApp gateway.
- API publik / mobile client.

Menambah salah satu item di atas ke Fase 1 berarti **melebarkan scope secara signifikan** dan sebaiknya ditolak.

---

## 2. Prasyarat & Asumsi Environment

| Prasyarat | Status / Catatan |
|---|---|
| PHP CLI 8.2+ | **Belum terverifikasi di mesin dev saat ini** — binary `php` tidak terdeteksi. Seluruh script QC (`tests/qc_*.php`) butuh CLI. Wajib disiapkan (container/VM/laragon) sebelum T9 dieksekusi. |
| MySQL 8.0 | **Belum terverifikasi** — client `mysql` tidak terdeteksi di mesin dev. Butuh instance lokal atau akses langsung ke database hosting untuk QC. |
| Ekstensi PHP | Wajib: `pdo_mysql`, `mbstring`, `json`, `session`, `date`, `filter`. Direkomendasikan: `openssl`, `fileinfo`, `opcache`. |
| Git | Sudah tersedia (`2.43.0`), namun workspace **belum** berupa repository. Lihat §9 Open Item O-2. |
| Webserver | Apache + `.htaccess` (asumsi shared hosting). Bila nginx digunakan, ekuivalen `try_files` harus ditulis manual. |

**Asumsi arsitektural yang dikunci:**

- **A-1** — Document root hosting **dapat** diarahkan ke `public/`. Bila tidak bisa, dipakai `.htaccess` deny sebagai *defense in depth* (lihat §7).
- **A-2** — Database **sudah dibuat** oleh pengguna melalui cPanel. `schema.sql` **tidak** memuat `CREATE DATABASE`, hanya `USE` opsional.
- **A-3** — Timezone aplikasi dan database adalah **Asia/Jakarta (+07:00)**, konsisten antara PHP dan MySQL.
- **A-4** — Tidak ada queue, cron, atau background worker pada fase ini.
- **A-5** — Session disimpan di file filesystem (default PHP), bukan database.

---

## 3. Struktur Direktori & File

### 3.1 Pohon Target

```
aurumflow/
├── .env.example                        # template konfigurasi (di-commit)
├── .gitignore                          # .env, storage/logs/*, public/uploads/*
├── .htaccess                           # (optional) defense in depth — deny app/, database/, storage/, tests/
├── Plan_Fase_1_AurumFlow.md            # dokumen ini (lihat catatan pemindahan di §A.5)
├── README.md                           # cara install & menjalankan
│
├── app/                                # ── BACKEND LOGIC (tidak terekspos web) ──
│   ├── Config/
│   │   ├── Env.php                     # parser .env sederhana
│   │   ├── App.php                     # nama app, base_url, timezone, debug, kebijakan session
│   │   ├── Database.php                # PDO singleton + query helpers
│   │   └── bootstrap.php               # autoloader, timezone, error handler, session init
│   ├── Controllers/
│   │   ├── BaseController.php          # render(), redirect(), requireAuth(), requireGuest(), CSRF guard
│   │   ├── AuthController.php          # showLogin(), login(), logout()
│   │   └── DashboardController.php     # index()
│   ├── Models/                         # (+) di luar daftar eksplisit — lihat §3.2
│   │   └── User.php                    # findByUsername(), verifyPassword(), touchLogin()
│   ├── Helpers/
│   │   ├── Security.php                # XSS, password hashing, CSRF, session hardening
│   │   └── Formatter.php               # Rupiah & gramatur (+ inverse parser)
│   ├── Views/                          # plain PHP template, tanpa engine
│   │   ├── layouts/
│   │   │   ├── app.php                 # layout setelah login
│   │   │   └── auth.php                # layout halaman login
│   │   ├── auth/
│   │   │   └── login.php
│   │   ├── dashboard/
│   │   │   └── index.php
│   │   └── errors/
│   │       ├── 404.php
│   │       └── 500.php
│   └── routes.php                      # (+) route table — data saja, bukan logika
│
├── database/                           # (+) di luar daftar eksplisit — lihat §3.2
│   ├── schema.sql                      # DDL tabel Fase 1
│   ├── seed.sql                        # data awal (master & referensi)
│   └── install.php                     # runner one-shot untuk host tanpa CLI (WAJIB dihapus setelah dipakai)
│
├── public/                             # ── WEB ROOT / DOCROOT ──
│   ├── index.php                       # FRONT CONTROLLER: bootstrap → route → dispatch → render
│   ├── .htaccess                       # rewrite semua request ke index.php; blokir dotfile
│   ├── assets/
│   │   ├── css/
│   │   │   └── app.css
│   │   └── js/
│   │       └── app.js
│   └── uploads/                        # lokasi file user (belum dipakai di Fase 1)
│       └── .gitignore
│
├── storage/                            # (+) writable, DI LUAR docroot
│   └── logs/
│       └── .gitignore
│
└── tests/                              # (+) QC scripts, DI LUAR docroot
    ├── bootstrap.php                   # helper bersama untuk semua qc_*.php
    ├── qc_pdo.php
    ├── qc_sql_injection.php
    ├── qc_formatter.php
    ├── qc_seed.php
    ├── qc_routing.php
    └── qc_security.php
```

### 3.2 Catatan Penambahan di Luar Daftar Eksplisit

Scope awal menyebut `app/` berisi **Config, Controllers, Helpers, Views** dan `public/` berisi **assets, uploads, index.php**. Empat penambahan berikut diusulkan dan diberi tanda `(+)`:

| Penambahan | Alasan | Bila ditolak |
|---|---|---|
| `app/Models/User.php` | `AuthController` butuh lookup user + `password_verify()`. Menaruh SQL di controller melanggar pemisahan yang justru jadi tujuan Fase 1. | Query user boleh sementara berada di `AuthController` dan direfaktor di Fase 2. |
| `app/routes.php` | Memisahkan **data** route table dari **logika** dispatch, supaya menambah route tidak menyentuh front controller. | Route table dideklarasikan inline di `public/index.php`. |
| `database/` | Diminta eksplisit pada saat klarifikasi, agar QC "seed reading" dapat diuji end-to-end. | QC seed digantikan fixture inline. |
| `storage/` + `tests/` | Log harus writable dan di luar docroot. QC script harus dapat dijalankan tanpa membocorkan apa pun ke web. | Log ke `error_log()` bawaan; QC dijalankan manual. |

### 3.3 Aturan Akses Direktori

| Direktori | Terekspos web? | Aturan |
|---|---|---|
| `public/` | **Ya** | Satu-satunya docroot. |
| `app/`, `database/`, `storage/`, `tests/` | **Tidak** | Dilarang diakses via HTTP. Dilindungi posisi docroot; `.htaccess` deny sebagai lapisan kedua. |
| `public/uploads/` | Ya (nanti) | Eksekusi script **diblokir** (`php_flag engine off` / `RemoveHandler`), hanya boleh menyajikan file statis. |

---

## 4. Keputusan Teknis & Konvensi

### 4.1 Bootstrap — Tanpa Composer

- **Autoloader manual** via `spl_autoload_register()` di `app/Config/bootstrap.php`. Namespace root `AurumFlow\` dipetakan langsung ke direktori `app/` (PSR-4-like):
  - `AurumFlow\Config\Database` → `app/Config/Database.php`
  - `AurumFlow\Controllers\AuthController` → `app/Controllers/AuthController.php`
  - `AurumFlow\Helpers\Security` → `app/Helpers/Security.php`
  - `AurumFlow\Models\User` → `app/Models/User.php`
- Autoloader **wajib** memvalidasi bahwa path hasil resolusi benar-benar berada di dalam `app/` (`realpath()` + `str_starts_with()`) dan hanya meng-`require` file dengan ekstensi `.php`.
- `declare(strict_types=1)` di setiap file PHP di `app/`, `public/`, `tests/`.
- Tidak ada `composer.json`, tidak ada folder `vendor/`.

### 4.2 Konfigurasi & Secret

- `.env` (git-ignored) menyimpan seluruh nilai environment-specific:

  ```dotenv
  APP_NAME="AurumFlow"
  APP_ENV=local
  APP_DEBUG=true
  APP_URL=http://localhost:8000
  APP_TIMEZONE=Asia/Jakarta

  DB_HOST=127.0.0.1
  DB_PORT=3306
  DB_NAME=aurumflow
  DB_USER=aurumflow_user
  DB_PASS=
  DB_CHARSET=utf8mb4

  SESSION_NAME=AURUMFLOW_SESS
  SESSION_LIFETIME=7200

  INSTALL_TOKEN=          # hanya untuk database/install.php
  ```

- `.env.example` di-commit dengan nilai kosong/placeholder; `.env` masuk `.gitignore`.
- `Env` parser: line-based, abaikan baris kosong dan komentar `#`, split pada `=` pertama, trim, buang tanda kutip pembungkus, **tanpa** interpolasi variabel. Hasil di-cache di static array. Parser ditulis sendiri (bukan `parse_ini_file()`) agar perilaku deterministik terhadap karakter khusus.
- **Larangan keras:** tidak ada credential hardcoded di file PHP mana pun. `app/Config/Database.php` hanya membaca dari `Env`.

### 4.3 Penanganan Error & Logging

- `set_error_handler()` → konversi warning/notice menjadi `ErrorException`.
- `set_exception_handler()` → catat stack trace ke `storage/logs/`, lalu tampilkan halaman `errors/500.php`.
- `register_shutdown_function()` → tangkap fatal error (`E_ERROR`) yang tidak tertangkap handler biasa.
- `APP_DEBUG=false` (produksi) → pesan generik ke pengguna, **tidak pernah** membocorkan DSN, credential, query, atau path absolut.
- Format log: `[YYYY-MM-DD HH:MM:SS] [level] pesan {context JSON}` ditulis dengan `file_put_contents(..., FILE_APPEND | LOCK_EX)` ke `storage/logs/app-YYYY-MM-DD.log`, permission `0644`.
- Security header dasar dikirim dari front controller: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`.

### 4.4 Konvensi Database

- Seluruh tabel: InnoDB, `utf8mb4`, collation `utf8mb4_unicode_ci`.
- Primary key: `BIGINT UNSIGNED AUTO_INCREMENT`.
- **Uang** → `DECIMAL(18,2)`. **Gramatur** → `DECIMAL(12,3)`. **Dilarang** memakai `FLOAT`/`DOUBLE` untuk nilai moneter maupun berat.
- Timestamp: `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`.
- `FOREIGN KEY` eksplisit dengan `ON DELETE RESTRICT` (default aman untuk data finansial).
- Simpan nilai mentah di database; format **hanya** di layer tampilan melalui `Formatter`.

### 4.5 Konvensi Routing

- URL bersih, tanpa ekstensi: `/login`, `/dashboard`, `/logout`.
- Method HTTP dihormati: `GET` untuk tampil, `POST` untuk mutasi. Tidak ada mutasi lewat `GET`.
- Pola dinamis memakai placeholder `{param}` dan dicocokkan dengan regex non-greedy, contoh `/transaksi/{id}` di fase berikutnya. Fase 1 hanya perlu route statis + 404.
- Route yang tidak cocok → `errors/404.php` dengan HTTP status `404`.

---

## 5. Tugas Eksekusi (Step-by-Step)

> Dikerjakan **berurutan**. Setiap tugas menyebutkan file target, isi/API minimum, dan kriteria selesai. Fase 1 tidak boleh melompat ke T7 sebelum T1–T6 hijau.

### T1 — Fondasi Konfigurasi & Bootstrap

**File:** `.env.example`, `.gitignore`, `app/Config/Env.php`, `app/Config/App.php`, `app/Config/bootstrap.php`

**Langkah:**
1. Tulis `.gitignore`: `.env`, `/storage/logs/*.log`, `/storage/logs/!.gitignore`, `/public/uploads/*`, `!/public/uploads/.gitignore`, `*.log`, `.DS_Store`.
2. Tulis `.env.example` sesuai §4.2 tanpa nilai rahasia.
3. Implementasi `Env::load(string $path): void` dan `Env::get(string $key, ?string $default = null): ?string`, plus `Env::int()/bool()` untuk casting.
4. Implementasi `App` sebagai konfigurasi statis yang sudah di-*resolve* (nama, `base_url`, `timezone`, `debug`, `env`).
5. `bootstrap.php` berisi, berurutan: `strict_types`, `require Env.php` + load `.env`, register autoloader `AurumFlow\`, `date_default_timezone_set()`, set error/exception handler, definisikan konstanta `BASE_PATH` dan `APP_PATH`, lalu **tidak** memulai session (session dimulai eksplisit di front controller agar test dapat mengontrolnya).

**Kriteria selesai:** `php -r "require 'app/Config/bootstrap.php'; var_dump(AurumFlow\Config\Env::get('APP_NAME'));"` menampilkan nama aplikasi tanpa warning.

---

### T2 — `app/Config/Database.php`

**File:** `app/Config/Database.php`

**Langkah:**
1. Class `Database` dengan **private constructor** dan `private static ?PDO $instance`, method `connection(): PDO` (singleton per request).
2. DSN: `mysql:host={DB_HOST};port={DB_PORT};dbname={DB_NAME};charset={DB_CHARSET}` — **tanpa** string concatenation nilai user ke DSN selain konfigurasi internal.
3. `PDO::ATTR_*` yang **wajib** diset:

   | Attribute | Nilai | Alasan |
   |---|---|---|
   | `ATTR_ERRMODE` | `ERRMODE_EXCEPTION` | Error tidak pernah silent |
   | `ATTR_DEFAULT_FETCH_MODE` | `FETCH_ASSOC` | Hasil konsisten |
   | `ATTR_EMULATE_PREPARES` | `false` | Prepared statement **native**, kunci anti SQL injection |
   | `ATTR_STRINGIFY_FETCHES` | `false` | Tipe data numerik tetap numerik |
   | `ATTR_PERSISTENT` | `false` | Hindari kebocoran state di shared hosting |
   | `MYSQL_ATTR_INIT_COMMAND` | `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone='+07:00'` | Charset + timezone konsisten |

4. Bungkus konstruksi PDO dengan `try/catch (PDOException)`: catat detail ke log, lempar `DatabaseException` (custom, extends `RuntimeException`) dengan pesan generik untuk pengguna.
5. Query helpers — **semuanya** memakai `prepare()` + `bindValue()`/`execute()`:
   - `query(string $sql, array $params = []): PDOStatement`
   - `fetchOne(string $sql, array $params = []): ?array`
   - `fetchAll(string $sql, array $params = []): array`
   - `insert(string $table, array $data): int` → mengembalikan `lastInsertId()`
   - `update(string $table, array $data, string $where, array $whereParams): int`
   - `transaction(callable $fn): mixed` → `beginTransaction()` / `commit()` / `rollBack()` pada `Throwable`
6. `insert()`/`update()` **wajib** memvalidasi nama kolom dengan regex `^[A-Za-z_][A-Za-z0-9_]*$` dan membungkusnya dengan backtick — nilai tetap dikirim sebagai parameter.
7. `close()` opsional untuk memutus koneksi eksplisit.

**Kriteria selesai:** `tests/qc_pdo.php` PASS (§6.1).

---

### T3 — Front Controller & Routing

**File:** `public/index.php`, `public/.htaccess`, `app/routes.php`, `app/Controllers/BaseController.php`

**Langkah:**
1. `public/.htaccess`: aktifkan `RewriteEngine`, arahkan semua request yang bukan file/direktori nyata ke `index.php` (`RewriteCond %{REQUEST_FILENAME} !-f` / `!-d`), blokir akses ke file tersembunyi (`^\.`), matikan directory listing (`Options -Indexes`).
2. `app/routes.php` mengembalikan array route table murni (data):

   ```php
   return [
       'GET'  => [
           '/login'     => [AuthController::class, 'showLogin'],
           '/dashboard' => [DashboardController::class, 'index'],
       ],
       'POST' => [
           '/login'  => [AuthController::class, 'login'],
           '/logout' => [AuthController::class, 'logout'],
       ],
   ];
   ```

3. `public/index.php` menjalankan urutan: require `bootstrap.php` → kirim security header → `session_start()` dengan cookie param dari §T4 → ambil `REQUEST_METHOD` → ambil path dari `parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)` → normalisasi (buang trailing slash kecuali `/`, buang `base_url` bila aplikasi di subdirektori) → cocokkan route → bila `POST`/`PUT`/`PATCH`/`DELETE` lakukan pemeriksaan CSRF → dispatch ke controller → render. Route tidak ditemukan → 404. `Throwable` apa pun → log + 500.
4. Dispatch: `[$class, $method] = $handler; (new $class())->{$method}(...)`. Validasi bahwa class benar-benar turunan `BaseController` dan method ada — jangan pernah memanggil handler dari input pengguna.
5. `BaseController` menyediakan:
   - `render(string $view, array $data = [], string $layout = 'app'): void` — ekstrak `$data`, `ob_start()`, include view, bungkus dengan layout.
   - `redirect(string $path, int $status = 302): never` — `header('Location: ...')` + `exit`.
   - `requireAuth(): void` — bila `$_SESSION['user_id']` kosong → redirect `/login`.
   - `requireGuest(): void` — bila sudah login → redirect `/dashboard`.
   - `json(array $payload, int $status = 200): void` — untuk kebutuhan AJAX fase berikutnya.
6. Layout `errors/404.php` dan `errors/500.php` minimal, tanpa dependensi database.

**Kriteria selesai:** `tests/qc_routing.php` PASS (§6.5).

---

### T4 — `app/Helpers/Security.php`

**File:** `app/Helpers/Security.php`

**Langkah:**
1. **XSS / output escaping**
   - `e(?string $value): string` → `htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
   - `sanitize(string $value): string` → trim + hapus karakter kontrol + `strip_tags()` **hanya** untuk input yang memang tidak boleh memuat markup. Default kebijakan tetap: *escape saat output*, bukan sanitize saat input.
2. **Password hashing**
   - `hashPassword(string $plain): string` → `password_hash($plain, PASSWORD_DEFAULT)`.
   - `verifyPassword(string $plain, string $hash): bool` → `password_verify()`.
   - `needsRehash(string $hash): bool` → `password_needs_rehash($hash, PASSWORD_DEFAULT)`.
3. **CSRF**
   - `csrfToken(): string` → generate `bin2hex(random_bytes(32))` bila belum ada di `$_SESSION['_csrf_token']`; simpan juga `$_SESSION['_csrf_time']`.
   - `csrfField(): string` → `<input type="hidden" name="_token" value="...">` dengan nilai ter-escape.
   - `verifyCsrf(?string $token): bool` → `hash_equals()` terhadap token session; tolak bila kosong, bila umur token melebihi `SESSION_LIFETIME`, atau bila `$_SESSION` tidak aktif.
   - `rotateCsrf(): void` → regenerasi token setelah login/logout (mencegah fixation).
4. **Session hardening**
   - `startSession(): void` — panggil `session_set_cookie_params([...])` **sebelum** `session_start()`: `httponly => true`, `samesite => 'Lax'`, `secure => (bool) filter_input(INPUT_SERVER, 'HTTPS')`, `path => '/'`.
   - `regenerateSession(): void` → `session_regenerate_id(true)`, dipanggil setelah login sukses.
   - `destroySession(): void` → kosongkan `$_SESSION`, hapus cookie session, `session_destroy()`.
5. **Utilitas lain**
   - `randomToken(int $bytes = 32): string` → `bin2hex(random_bytes($bytes))`.
   - `passwordStrength(string $plain): bool` → minimum panjang 8, kombinasi huruf dan angka (aturan ringan, didokumentasikan).
   - `fingerprint(): string` → hash ringan dari `User-Agent` untuk deteksi session hijacking dasar (opsional, dicatat bila tidak aktif).

**Kriteria selesai:** `tests/qc_security.php` PASS (§6.6).

---

### T5 — `app/Helpers/Formatter.php`

**File:** `app/Helpers/Formatter.php`

**Langkah:**
1. **Rupiah** — `rupiah(int|float|string $value, int $decimals = 0): string`
   - Normalisasi input string (terima `1.234,50` atau `1234.50`), lalu `number_format($v, $decimals, ',', '.')`.
   - Hasil: `Rp 28.937.500` (desimal 0), `Rp 1.234,50` (desimal 2).
   - Nilai `null`/`''` → `Rp 0`.
2. **Gramatur** — `gram(int|float|string $value, int $decimals = 3): string`
   - `number_format($v, 3, ',', '.') . ' gr'`.
   - Hasil: `14,850 gr`, `1.000,000 gr`, `0,000 gr`.
   - Pembulatan *half-up* (default `number_format`) dan **didokumentasikan** karena relevan untuk berat emas.
3. **Inverse parser** (wajib, untuk round-trip test dan input form)
   - `parseRupiah(string $formatted): float` — buang `Rp`, spasi, dan pemisah ribuan; ubah `,` menjadi `.`.
   - `parseGram(string $formatted): float` — buang ` gr`, buang pemisah ribuan, ubah `,` menjadi `.`.
4. **Tanggal** — `tanggalId(string $datetime, bool $withTime = false): string` → `d/m/Y` atau `d/m/Y H:i` (waktu Asia/Jakarta). Ringan, dipakai konsisten di seluruh view.
5. **Larangan:** tidak memakai `setlocale()`/`localeconv()` (tergantung environment hosting) dan tidak memakai `number_format` tanpa separator eksplisit.

**Kriteria selesai:** `tests/qc_formatter.php` PASS (§6.3).

---

### T6 — Skema Database & Seed

**File:** `database/schema.sql`, `database/seed.sql`, `database/install.php`

**Langkah:**
1. `schema.sql` — DDL idempotent (`CREATE TABLE IF NOT EXISTS`). Tabel Fase 1:

   | Tabel | Kolom inti | Catatan |
   |---|---|---|
   | `users` | `id`, `name`, `username` (UNIQUE), `email` (UNIQUE), `password_hash` (VARCHAR 255), `role` ENUM('admin','kasir','owner'), `is_active` TINYINT(1) DEFAULT 1, `must_change_password` TINYINT(1) DEFAULT 0, `last_login_at` DATETIME NULL, `created_at`, `updated_at` | Sumber identitas login & session auth |
   | `settings` | `id`, `key` (UNIQUE), `value` TEXT, `type` ENUM('string','int','decimal','bool','json'), `description`, `updated_at` | Konfigurasi aplikasi yang dapat diubah tanpa deploy |
   | `activity_logs` | `id`, `user_id` (FK → users, NULL untuk anonim), `action` VARCHAR(100), `entity` VARCHAR(100) NULL, `entity_id` BIGINT UNSIGNED NULL, `context` JSON NULL, `ip_address` VARBINARY(16) NULL, `user_agent` VARCHAR(255) NULL, `created_at` | Jejak audit dasar; index pada `(user_id, created_at)` |

   Tidak ada `CREATE DATABASE` (asumsi A-2). Opsional satu baris `USE` yang dikomentari.

2. `seed.sql` — data awal, idempotent (`INSERT ... ON DUPLICATE KEY UPDATE` atau `INSERT IGNORE` dengan key unik):
   - **`settings`**: `company_name`, `company_address`, `currency` = `IDR`, `gram_decimals` = `3`, `money_decimals` = `0`, `settlement_days` = `1` (T+1), `settlement_cutoff_time` = `15:00`, `gold_unit_label` = `gr`, `app_version` = `1.0.0-fase1`.
   - **Referensi kadar/purity emas**: `24K (99,99%)`, `23K`, `22K`, `18K` bila tabel referensi sudah masuk scope; jika belum, cukup sebagai baris `settings` bertipe `json`.
   - **Akun admin awal**: dibuat **bukan** dari hash statis di file `.sql`, melainkan oleh `database/install.php` memakai `Security::hashPassword()` agar hash selalu valid dan unik per environment. `seed.sql` hanya menyediakan INSERT `settings` dan data referensi.
   - `must_change_password = 1` untuk akun admin awal, dengan password default yang **wajib** diganti saat login pertama (pemaksaan dilakukan di Fase 2).

3. `database/install.php` — runner untuk host tanpa CLI:
   - Guard ketat: hanya berjalan bila `Env::get('INSTALL_TOKEN')` terisi **dan** `$_GET['token']` cocok (`hash_equals`), atau bila dijalankan dari CLI.
   - Baca `schema.sql` → eksekusi → baca `seed.sql` → eksekusi → buat akun admin → tulis `storage/installed.lock`.
   - Bila `installed.lock` sudah ada → tolak jalan (harus dihapus manual).
   - Splitter SQL sederhana: buang komentar `--` dan `/* */`, split pada `;` di akhir baris. **Batasan didokumentasikan**: tidak menangani `;` di dalam string literal atau stored procedure — schema Fase 1 tidak memakai keduanya.
   - **WAJIB dihapus** dari hosting setelah instalasi. Dicatat di README dan di §8.
4. Jalankan melalui CLI bila tersedia: `php tests/qc_seed.php`.

**Kriteria selesai:** `tests/qc_seed.php` PASS (§6.4).

---

### T7 — Controller, Model, dan View

**File:** `app/Controllers/AuthController.php`, `app/Controllers/DashboardController.php`, `app/Models/User.php`, `app/Views/**`

**Langkah:**
1. `User::findByUsername(string $username): ?array` → prepared statement `WHERE username = :username AND is_active = 1 LIMIT 1`.
2. `User::verifyPassword(array $user, string $plain): bool` → delegasi ke `Security::verifyPassword()`; bila `true` dan `needsRehash()` → `User::updatePasswordHash()`.
3. `User::touchLogin(int $id, string $ip): void` → update `last_login_at`; insert ke `activity_logs` dengan action `auth.login`.
4. `AuthController::showLogin()` → `requireGuest()`, render `auth/login` dengan layout `auth` + `Security::csrfField()`.
5. `AuthController::login()` → `requireGuest()` → `Security::verifyCsrf($_POST['_token'])` → validasi input minimal → `User::findByUsername()` → `verifyPassword()` → sukses: `Security::regenerateSession()`, `Security::rotateCsrf()`, set `$_SESSION['user_id']`, `$_SESSION['role']`, `touchLogin()`, redirect `/dashboard`. Gagal: pesan generik *"Username atau password salah"* (tanpa membedakan mana yang salah) + isi ulang `username` saja.
6. `AuthController::logout()` → **method POST** + verifikasi CSRF → `Security::destroySession()` → redirect `/login`.
7. `DashboardController::index()` → `requireAuth()` → tampilkan ringkasan kosong + bukti formatter bekerja (contoh nilai Rupiah & gram) sebagai smoke test visual.
8. View: PHP murni dengan `<?= Security::e($var) ?>` untuk **setiap** output dinamis. Tidak ada logika bisnis di view.

**Kriteria selesai:** login akun seed berhasil melalui browser/HTTP test, ter-redirect ke `/dashboard`, dan `/dashboard` tanpa login ter-redirect ke `/login`.

---

### T8 — Aset Statis & Hardening Direktori

**File:** `public/assets/css/app.css`, `public/assets/js/app.js`, `public/uploads/.gitignore`, `.htaccess` root (opsional), `README.md`

**Langkah:**
1. `app.css` minimal (reset ringan, layout dasar, styling form login & tabel). Tanpa framework CSS.
2. `app.js` minimal (konfirmasi submit form logout, guard double-submit). Vanilla JS, tanpa dependensi.
3. `public/uploads/.gitignore` → `*` + `!.gitignore`.
4. `.htaccess` root (hanya bila docroot tidak dapat diarahkan ke `public/`) → deny akses ke `app/`, `database/`, `storage/`, `tests/`, `plan/`, `docs/`, `.env`.
5. `README.md` → prasyarat, langkah instalasi, cara menjalankan QC, dan **peringatan hapus `database/install.php`**.

**Kriteria selesai:** mengakses `/../app/Config/Database.php` dan `/.env` via HTTP mengembalikan 403/404, bukan isi file.

---

### T9 — Script QC & Eksekusi Verifikasi

**File:** `tests/bootstrap.php`, `tests/qc_*.php` (6 file)

**Langkah:**
1. `tests/bootstrap.php` → require `app/Config/bootstrap.php`, sediakan helper assertion: `assertTrue()`, `assertSame()`, `assertThrows()`, dan pelaporan PASS/FAIL dengan `exit(1)` bila ada satu saja yang gagal.
2. Tulis keenam script sesuai §6.
3. Jalankan seluruhnya, rekam output ke `storage/logs/qc-fase1.log`.
4. Semua test harus hijau sebelum Fase 1 ditandatangani.

**Kriteria selesai:** keenam script exit code `0`.

---

## 6. Quality Control (QC) Fase 1

Semua script ditulis sebagai **PHP CLI** di `tests/`, dijalankan dengan `php tests/qc_xxx.php`, dan mengembalikan `exit(0)` = PASS / `exit(1)` = FAIL.

### 6.1 QC-1 — PDO Connection (`qc_pdo.php`)

| # | Test case | Ekspektasi |
|---|---|---|
| 1.1 | `Database::connection()` dengan kredensial valid | Instance `PDO` terbentuk, tidak ada exception |
| 1.2 | `SELECT VERSION()` | Mengembalikan versi MySQL 8.0.x |
| 1.3 | `SHOW VARIABLES LIKE 'character_set_client'` | `utf8mb4` |
| 1.4 | `SELECT @@session.time_zone` | `+07:00` |
| 1.5 | `SELECT @@sql_mode` | Memuat `STRICT_TRANS_TABLES` |
| 1.6 | Eksekusi SQL invalid (`SELECT * FROM tabel_tidak_ada`) | Melempar `PDOException`, bukan warning |
| 1.7 | Kredensial salah (host/user/pass salah) | Melempar `DatabaseException`; pesan **tidak** memuat password |
| 1.8 | `Database::connection()` dipanggil 3× | Instance yang sama (singleton terbukti) |
| 1.9 | `fetchOne()` pada query dengan 0 hasil | Mengembalikan `null`, bukan `false` |
| 1.10 | `insert()` lalu `fetchOne()` | Nilai `DECIMAL` kembali sebagai string/numerik presisi, **bukan** float yang terpotong |

### 6.2 QC-2 — SQL Injection (`qc_sql_injection.php`)

**Payload yang diuji** (dikirim ke `User::findByUsername()` dan ke form login):

```
' OR '1'='1
admin'--
admin' OR 1=1 LIMIT 1--
'; DROP TABLE users;--
' UNION SELECT 1,password_hash,1 FROM users--
' OR SLEEP(5)--
\x27 OR 1=1/*
```

| # | Test case | Ekspektasi |
|---|---|---|
| 2.1 | Setiap payload pada `findByUsername()` | Mengembalikan `null` / 0 baris |
| 2.2 | Setiap payload pada `POST /login` | Login **gagal**, tetap di halaman login |
| 2.3 | Setelah seluruh payload dijalankan | Tabel `users` masih ada, jumlah baris tidak berubah |
| 2.4 | Payload `SLEEP(5)` | Respons < 2 detik (proves literal, bukan dieksekusi) |
| 2.5 | Payload DROP/UPDATE/DELETE | `settings` dan `activity_logs` tidak berubah |
| 2.6 | **Static scan** seluruh file `app/**` dan `public/**` dengan regex | Tidak ada `$_GET`/`$_POST`/`$_REQUEST`/`$_COOKIE` yang diinterpolasi langsung ke string SQL |
| 2.7 | Verifikasi konfigurasi | `ATTR_EMULATE_PREPARES === false` |
| 2.8 | Payload berisi byte multibyte/invalid UTF-8 | Tidak memicu error, tetap diperlakukan sebagai literal |
| 2.9 | Verifikasi XSS pada halaman hasil | Payload tercermin dalam bentuk ter-escape, bukan HTML mentah |

### 6.3 QC-3 — Formatter (`qc_formatter.php`)

**Format Rupiah** — `Formatter::rupiah()`:

| Input | Ekspektasi |
|---|---|
| `0` | `Rp 0` |
| `500` | `Rp 500` |
| `1234.5` | `Rp 1.235` (half-up, desimal 0) |
| `28937500` | `Rp 28.937.500` |
| `1000000000` | `Rp 1.000.000.000` |
| `-5000` | `-Rp 5.000` (format negatif didokumentasikan) |
| `1234.567`, desimal 2 | `Rp 1.234,57` |
| `null` / `''` | `Rp 0` |
| string `"1.234,50"` | `Rp 1.235` (parsing input Indonesia) |

**Format Gramatur** — `Formatter::gram()`:

| Input | Ekspektasi |
|---|---|
| `0` | `0,000 gr` |
| `14.85` | `14,850 gr` |
| `14.8500` | `14,850 gr` |
| `1000` | `1.000,000 gr` |
| `0.001` | `0,001 gr` |
| `1234.5678` | `1.234,568 gr` (half-up) |
| `-2.5` | `-2,500 gr` |

| # | Test case | Ekspektasi |
|---|---|---|
| 3.1 | Seluruh baris tabel di atas | Output **sama persis** (string equality) |
| 3.2 | **Round-trip** `parseRupiah(rupiah($x))` | Kembali ke nilai semula (toleransi 0,01) |
| 3.3 | **Round-trip** `parseGram(gram($x))` | Kembali ke nilai semula (toleransi 0,0005) |
| 3.4 | Jalankan ulang setelah `setlocale(LC_ALL, 'de_DE.UTF-8')` dan `'C'` | Output **tidak berubah** |
| 3.5 | `rupiah()` tanpa argumen desimal | Default 0 desimal |
| 3.6 | `gram()` tanpa argumen desimal | Default 3 desimal |
| 3.7 | Tidak ada pemanggilan `number_format` tanpa separator eksplisit | Static scan lulus |

### 6.4 QC-4 — Seed Reading (`qc_seed.php`)

| # | Test case | Ekspektasi |
|---|---|---|
| 4.1 | Jalankan `schema.sql` | Sukses, tanpa error |
| 4.2 | Jalankan `schema.sql` **dua kali** | Sukses (idempotent), tanpa error |
| 4.3 | Jalankan `seed.sql` | Sukses |
| 4.4 | Jalankan `seed.sql` **dua kali** | Sukses, tidak ada duplikat baris |
| 4.5 | Tabel `users`, `settings`, `activity_logs` ada | Terkonfirmasi via `information_schema` |
| 4.6 | Engine & charset seluruh tabel | `InnoDB` + `utf8mb4_unicode_ci` |
| 4.7 | Kolom uang di `information_schema.columns` | Bertipe `decimal` — **tidak ada** `float`/`double` |
| 4.8 | Akun admin awal ada | 1 baris, `role = 'admin'`, `is_active = 1`, `must_change_password = 1` |
| 4.9 | `password_verify()` dengan password default | `true` |
| 4.10 | `settings` dibaca lewat `Database::fetchAll()` | Memuat `settlement_days = 1` (T+1), `gram_decimals = 3`, `currency = IDR` |
| 4.11 | `INSERT` username duplikat | Ditolak oleh constraint `UNIQUE` |
| 4.12 | `INSERT` `activity_logs` dengan `user_id` tidak ada | Ditolak oleh `FOREIGN KEY` |
| 4.13 | Waktu `created_at` baris baru | Konsisten dengan Asia/Jakarta (+07:00) |
| 4.14 | `install.php` tanpa token valid | Menolak berjalan (403), tanpa mengeksekusi DDL |
| 4.15 | `install.php` saat `installed.lock` sudah ada | Menolak berjalan |

### 6.5 QC-5 — Routing & Session Auth (`qc_routing.php`)

| # | Test case | Ekspektasi |
|---|---|---|
| 5.1 | `GET /` (belum login) | Redirect ke `/login` (302) |
| 5.2 | `GET /login` | HTTP `200`, form login tampil, memuat hidden `_token` |
| 5.3 | `GET /dashboard` tanpa login | Redirect `302` ke `/login`, `Location` benar |
| 5.4 | `GET /route-tidak-ada` | HTTP `404`, halaman 404 tampil, tanpa stack trace |
| 5.5 | `POST /login` dengan CSRF valid + kredensial benar | Redirect `302` ke `/dashboard` |
| 5.6 | `POST /login` **tanpa** CSRF token | HTTP `419`/`403`, login ditolak |
| 5.7 | `POST /login` dengan CSRF token berbeda (dari session lain) | Ditolak |
| 5.8 | Session ID sebelum vs sesudah login | Berbeda (regenerate terbukti) |
| 5.9 | `GET /dashboard` dengan session login | HTTP `200`, menampilkan nama user ter-escape |
| 5.10 | `POST /logout` dengan CSRF valid | Session terhapus, redirect `/login`; `GET /dashboard` kembali redirect |
| 5.11 | `GET /logout` | Tidak menghapus session (mutasi hanya via POST) |
| 5.12 | Cookie session | Memuat flag `HttpOnly` dan `SameSite=Lax` |
| 5.13 | `GET /.env` dan `/../app/Config/Database.php` | `403`/`404`, isi file tidak bocor |
| 5.14 | Akses langsung `/index.php/../../app/Config/Database.php` | Ditolak (path traversal gagal) |
| 5.15 | Response header | Memuat `X-Content-Type-Options` dan `X-Frame-Options` |

### 6.6 QC-6 — Security Helper (`qc_security.php`)

| # | Test case | Ekspektasi |
|---|---|---|
| 6.1 | `e('<script>alert(1)</script>')` | `&lt;script&gt;alert(1)&lt;/script&gt;` |
| 6.2 | `e('"><img src=x onerror=alert(1)>')` | Kutip dan tag ter-escape |
| 6.3 | `e("' OR 1=1 --")` | Apostrof menjadi `&#039;` |
| 6.4 | `e(null)` | String kosong, tanpa deprecation warning |
| 6.5 | `e()` dengan UTF-8 multibyte (`"Émile 🥇"`) | Tidak rusak, tanpa mojibake |
| 6.6 | `hashPassword()` lalu `verifyPassword()` | `true` |
| 6.7 | Panjang hash hasil | ≥ 60 karakter, format `PASSWORD_DEFAULT` dikenali `password_get_info()` |
| 6.8 | Dua hash untuk password sama | Berbeda (salt acak terbukti) |
| 6.9 | `verifyPassword()` dengan hash korup | `false`, tanpa error |
| 6.10 | `csrfToken()` | 64 karakter hex; stabil dalam satu session |
| 6.11 | `verifyCsrf()` dengan token benar | `true` |
| 6.12 | `verifyCsrf()` dengan token salah / `null` / string kosong | `false` |
| 6.13 | `verifyCsrf()` setelah token melewati `SESSION_LIFETIME` | `false` |
| 6.14 | `rotateCsrf()` | Token berubah, token lama tidak berlaku |
| 6.15 | `regenerateSession()` | `session_id()` berubah sebelum/sesudah |
| 6.16 | `destroySession()` | `$_SESSION` kosong dan cookie session terhapus |
| 6.17 | `randomToken()` dipanggil 1000× | Tidak ada nilai duplikat |
| 6.18 | Static scan `app/**` | Tidak ada `md5()`/`sha1()` untuk password, tidak ada `rand()`/`mt_rand()` untuk token keamanan |

### 6.7 Gate Penerimaan Fase 1

| Gate | Syarat |
|---|---|
| **G-1** | QC-1 s.d. QC-6 seluruhnya PASS, exit code `0` |
| **G-2** | Tidak ada `E_WARNING`/`E_NOTICE`/`E_DEPRECATED` di log saat suite berjalan |
| **G-3** | `.env`, `storage/logs/*.log`, dan `public/uploads/*` **tidak** ikut ter-commit |
| **G-4** | `public/` satu-satunya direktori yang dapat diakses via HTTP |
| **G-5** | `database/install.php` dihapus dari hosting produksi |
| **G-6** | Review diff akhir bersih — hanya file yang direncanakan, tanpa file sementara |

---

## 7. Risiko Shared Hosting & Mitigasi

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| R-1 | Document root **tidak** dapat diarahkan ke `public/` (hosting hanya mengizinkan root proyek) | `app/`, `.env`, `database/` dapat diakses langsung lewat URL | `.htaccess` root dengan `Require all denied` pada `app/`, `database/`, `storage/`, `tests/` + blokir dotfile. Bila memungkinkan, pindahkan proyek ke luar `public_html` dan taruh hanya isi `public/` di dalamnya. |
| R-2 | Tidak ada CLI / shell (`php` binary tidak tersedia di host) | QC script tidak dapat dijalankan, migrasi manual menyulitkan | `database/install.php` dengan `INSTALL_TOKEN`; QC dijalankan lokal terhadap salinan database, bukan di host. |
| R-3 | MySQL user tanpa privilege `CREATE DATABASE` / `CREATE USER` | `schema.sql` gagal total | Asumsi A-2: database dibuat via cPanel. `schema.sql` hanya `CREATE TABLE IF NOT EXISTS`. |
| R-4 | `open_basedir` membatasi akses path | Log tidak dapat ditulis ke `storage/` | Fallback: bila `storage/logs` tidak writable, kirim ke `error_log()` bawaan dan catat peringatan sekali di halaman admin. |
| R-5 | `disable_functions` mematikan fungsi yang dipakai | Fatal error saat runtime | Hindari fungsi yang sering diblokir (`exec`, `shell_exec`, `proc_open`, `system`). Verifikasi daftar `disable_functions` host sebelum deploy. Fase 1 **tidak** butuh fungsi tersebut. |
| R-6 | Versi PHP host masih < 8.2 | Syntax error (`readonly`, enum, `never`) | Pin versi via cPanel MultiPHP. Tambahkan pengecekan `PHP_VERSION_ID` di `bootstrap.php` yang berhenti dengan pesan jelas bila < 80200. |
| R-7 | Session path tidak writable / `session.save_path` aneh | Login selalu gagal | Deteksi di `bootstrap.php`; bila `session_start()` gagal, tampilkan pesan diagnostik, bukan white screen. |
| R-8 | Timezone MySQL ≠ PHP | Tanggal transaksi T+1 bergeser | `MYSQL_ATTR_INIT_COMMAND` dengan `time_zone='+07:00'` + `date_default_timezone_set('Asia/Jakarta')`, diverifikasi oleh QC 1.4. |
| R-9 | `install.php` tertinggal di produksi | Risiko eksekusi DDL ulang / pembuatan admin tak sah | Guard token + `installed.lock` + **wajib hapus**; dicatat di README dan §8. |
| R-10 | `public/uploads/` mengeksekusi PHP yang di-upload | Remote code execution | `php_flag engine off` + `RemoveHandler .php` di `public/uploads/.htaccess`; validasi MIME saat fitur upload dibangun di fase berikutnya. |
| R-11 | Mesin dev belum punya `php`/`mysql` CLI (terverifikasi) | Fase 1 tidak dapat diverifikasi end-to-end | Siapkan environment PHP 8.2 + MySQL 8.0 (container/VM) **sebelum** T9. Ini blocker, bukan nice-to-have. |

---

## 8. Checklist Serah Terima Fase 1

- [ ] Struktur direktori §3 terbentuk sesuai pohon target.
- [ ] T1–T9 selesai dan masing-masing kriteria selesai terpenuhi.
- [ ] QC-1 s.d. QC-6 PASS, output tersimpan di `storage/logs/qc-fase1.log`.
- [ ] Gate G-1 s.d. G-6 lulus.
- [ ] `.gitignore` terbukti bekerja (`.env` tidak ter-track).
- [ ] `README.md` memuat langkah instalasi + peringatan hapus `install.php`.
- [ ] Seluruh risiko R-1 s.d. R-11 ditinjau dan mitigasinya diterapkan atau dicatat sebagai backlog.
- [ ] Tidak ada `TODO`/`FIXME`/kode mati yang tertinggal di `app/`.
- [ ] Diff akhir ditinjau: hanya file yang direncanakan.

---

## 9. Open Items (Perlu Keputusan Sebelum Fase 2)

| # | Item | Pilihan | Rekomendasi |
|---|---|---|---|
| O-1 | Apakah `app/Models/` dipertahankan untuk Fase 1? | Pertahankan / Hapus (query di controller) | **Pertahankan** — konsisten dengan tujuan pemisahan layer. |
| O-2 | Apakah `git init` termasuk lingkup Fase 1? Workspace saat ini **belum** berupa repository. | Ya, inisialisasi + commit awal / Tidak, di luar scope | **Ya** — `.gitignore` sudah dirancang, commit awal memberi baseline yang dapat di-rollback. |
| O-3 | Format nilai negatif Rupiah | `-Rp 5.000` / `Rp -5.000` / `(Rp 5.000)` | **`-Rp 5.000`** (paling lugas). Harus dibekukan karena QC 3.1 menguji string persis. |
| O-4 | Kebijakan pembulatan gramatur | Half-up / Half-even / Truncate | **Half-up**, didokumentasikan di `Formatter`. Relevan untuk berat emas. |
| O-5 | Apakah `activity_logs` masuk Fase 1 atau Fase 2? | Fase 1 (skema saja) / Fase 2 | **Fase 1 skema saja** — tabel dibuat, penulisan log diperluas di Fase 2. |
| O-6 | Tabel referensi kadar emas (24K/23K/22K/18K) | Tabel terpisah / Baris `settings` JSON / Fase 2 | **Fase 2** — kecuali sudah dibutuhkan untuk seed Fase 1. |

---

## 10. Lampiran — Alur Request Fase 1

```
Request HTTP
     │
     ▼
public/.htaccess  ──(file/dir nyata?)──►  sajikan langsung (assets, uploads)
     │ tidak
     ▼
public/index.php  (FRONT CONTROLLER)
     │
     ├─ require app/Config/bootstrap.php
     │     ├─ Env::load('.env')
     │     ├─ spl_autoload_register('AurumFlow\')
     │     ├─ date_default_timezone_set('Asia/Jakarta')
     │     └─ set_error_handler / set_exception_handler / shutdown handler
     │
     ├─ Security::startSession()      (cookie HttpOnly + SameSite=Lax)
     ├─ kirim security header
     │
     ├─ $path = parse_url(REQUEST_URI); normalisasi
     ├─ $route = routes.php[ METHOD ][ $path ]  ──(null)──► 404 view
     │
     ├─ jika method = POST/PUT/PATCH/DELETE
     │     └─ Security::verifyCsrf($_POST['_token'])  ──(gagal)──► 419/403
     │
     ├─ dispatch ke BaseController turunan
     │     ├─ requireAuth() / requireGuest()  ──(gagal)──► redirect
     │     ├─ Model → Database (PDO prepared statement)
     │     └─ render(view, data, layout)
     │
     └─ Throwable ──► log ke storage/logs/ ──► 500 view (pesan generik jika APP_DEBUG=false)
```

---

# ADDENDUM — Rekonsiliasi dengan Dokumen Otoritatif (`docs/`)

> **Status: WAJIB DIBACA.** Bagian §1–§10 di atas ditulis **sebelum** folder
> `docs/` tersedia di workspace. Setelah folder tersebut muncul, ditemukan
> bahwa beberapa keputusan pada rencana awal **berbeda dari spesifikasi
> otoritatif**. Addendum ini mencatat rekonsiliasinya.
>
> Keputusan yang disetujui pengguna: `dec-b6694540f88bc67d`
> (1) adopsi penuh DDL ERD, (2) login via `phone_number`,
> (3) pertahankan seluruh tambahan kecuali tabel `activity_logs`.

## A.1 Sumber kebenaran (urutan presedensi)

| Prioritas | Dokumen | Cakupan |
|---|---|---|
| 1 | `docs/AurumFlow Entity Relationship Diagram.md` | **Skema database** — ERD §3.1 (tipe data), §4 (DDL + seed) |
| 2 | `docs/AurumFlow System Architecture Document.md` | Struktur direktori, tech stack, aturan keamanan |
| 3 | `docs/AurumFlow Roadmap and QC Documents.md` | Isi tiap fase + protokol QC |
| 4 | `docs/AurumFlow Product Requirement Document v3.0.md` | Aturan bisnis & hak akses |
| 5 | `plan/Plan_Fase_1_AurumFlow.md` (dokumen ini) | Rencana eksekusi Fase 1 |
| 6 | `docs/AurumFlow UI UX Documents.md` | Acuan Fase 2+ (Tailwind/Alpine/PWA) |

## A.2 Pertentangan yang ditemukan dan koreksinya

| Area | Rencana awal (§1–§10) | Dokumen otoritatif | Tindakan |
|---|---|---|---|
| Nama method koneksi | `Database::connection()` | `Database::getConnection()` (Roadmap, Protokol QC 1) | **Diubah** ke `getConnection()` |
| Tabel setting | `settings(id, key, value, type, …)` | `system_settings(setting_key PK, setting_value, description, updated_at)` | **Diubah** total |
| Kunci seed yang diuji | `settlement_days`, `gram_decimals` | `operator_base_quota_pct = 50.00` + 5 kunci lain | **Diubah** ke 6 kunci seed ERD |
| PK pengguna | `BIGINT AUTO_INCREMENT` | `CHAR(36)` UUID v4 (anti-enumeration) | **Diubah**, ditambah `Security::uuidV4()` |
| Identitas login | `username` + `email` | `phone_number` (UNIQUE), tanpa email | **Diubah** di model, controller, view, installer, QC |
| Peran | `admin`, `kasir`, `owner` | `operator`, `investor` | **Diubah** |
| Tipe uang | `DECIMAL(18,2)` | `DECIMAL(14,2)` | **Diubah** |
| Tipe berat | `DECIMAL(12,3)` | `DECIMAL(8,3)` | **Diubah** |
| Tipe persentase | tidak didefinisikan | `DECIMAL(5,2)` | **Ditambahkan** |
| Cakupan tabel | 3 tabel (`users`, `settings`, `activity_logs`) | 9 tabel ERD | **Diubah** — DDL ERD penuh |
| `activity_logs` | dibuat (karangan) | **tidak ada di ERD** | **Dihapus** |
| `app/Config/` | `App.php`, `Env.php`, `bootstrap.php` | `Database.php` + `Constants.php` | **Ditambahkan** `Constants.php` |

## A.3 Yang dipertahankan dari rencana awal

Seluruh butir berikut **tidak ada di pohon direktori SAD** tetapi disetujui
dipertahankan karena terbukti berguna dan bersifat aditif (tidak menyimpang
dari kontrak data):

1. `.env` + `app/Config/Env.php` — kredensial tidak hardcoded; override lewat
   environment variable (presedensi `getenv()`).
2. `app/Helpers/Logger.php` — diwajibkan §4.3, tidak diberi rumah oleh SAD.
3. `app/Models/` — pemisahan layer (User, SystemSetting).
4. `app/routes.php` — route table sebagai data.
5. `database/install.php` — instalasi pada hosting tanpa CLI (guard token).
6. `tests/` — harness QC + 7 suite (191 assertion).
7. `storage/logs/` — log di luar docroot.

## A.4 Penyimpangan yang disengaja dari ERD (beserta alasan)

1. **`DROP TABLE IF EXISTS` dimatikan.** ERD memulai skripnya dengan
   `SET FOREIGN_KEY_CHECKS = 0` + 9× `DROP TABLE`. Untuk `database/install.php`
   yang dijalankan di hosting produksi, itu berarti satu salah ketik =
   kehilangan seluruh pembukuan. Diganti `CREATE TABLE IF NOT EXISTS`
   (idempotent, QC 4.2). Blok RESET tetap tersedia sebagai komentar.
2. **`bin2hex(random_bytes(16))` tidak dipakai sebagai UUID.** ERD §5.3
   menyatakan fungsi tersebut "kompatibel dengan CHAR(36)". Itu **tidak
   akurat**: hasilnya 32 karakter hex tanpa tanda hubung. Diganti
   `Security::uuidV4()` yang membentuk UUID v4 sesuai RFC 4122 (QC 6.19).
3. **`Constants.php` tidak memuat parameter bisnis.** Kuota serapan, jasa
   bakar, dan masa tunggu penarikan tetap di `system_settings` sesuai ERD —
   menduplikasinya sebagai konstanta PHP akan menciptakan dua sumber
   kebenaran. `Constants.php` hanya memuat nilai enum peran dan konvensi
   tampilan angka.
4. **Tailwind/Alpine/PWA ditunda ke Fase 2.** Checklist FASE 1 pada Roadmap
   tidak menyebutnya; SAD §3 mencantumkannya untuk UI. Fase 1 tetap memakai
   CSS/JS minimal (`public/assets/`).

## A.5 Struktur direktori final

```
aurumflow/
├── .env / .env.example
├── .gitignore, .htaccess, README.md
├── plan/                      # dokumen perencanaan & laporan eksekusi
│   ├── Plan_Fase_1_AurumFlow.md
│   └── Summary_Fase_1_AurumFlow.md
├── docs/                      # 5 dokumen otoritatif (ERD, SAD, PRD, Roadmap, UI/UX)
├── app/
│   ├── Config/                # Env, App, Constants, Database, DatabaseException, bootstrap
│   ├── Controllers/           # BaseController, AuthController, DashboardController
│   ├── Helpers/               # Security, Formatter, Logger
│   ├── Models/                # User, SystemSetting
│   ├── Views/                 # layouts/, auth/, dashboard/, errors/
│   └── routes.php
├── database/                  # schema.sql (DDL ERD), seed.sql, install.php
├── public/                    # index.php, .htaccess, assets/{css,js}, uploads/
├── storage/logs/
└── tests/                     # bootstrap, server_router, 7× qc_*.php, run_qc.sh
```

> **Catatan pemindahan dokumen.** Pohon §3.1 di atas masih menempatkan
> `Plan_Fase_1_AurumFlow.md` di root proyek — itu kondisi saat rencana disusun.
> Kedua dokumen perencanaan kini berada di `plan/` agar terpisah dari kode:
> `plan/Plan_Fase_1_AurumFlow.md` dan `plan/Summary_Fase_1_AurumFlow.md`
> (laporan eksekusi). `.htaccess` root juga diperbarui untuk menolak akses ke
> `plan/` dan `docs/`.

## A.6 Hasil QC final

| Suite | Assertion | Hasil |
|---|---|---|
| `qc_pdo` (termasuk transaksi commit/rollback) | 20 | PASS |
| `qc_formatter` | 43 | PASS |
| `qc_security` (termasuk `uuidV4()`) | 32 | PASS |
| `qc_seed` (skema ERD + guard installer) | 46 | PASS |
| `qc_roadmap_protocol_1` (Protokol QC Manual 1) | 6 | PASS |
| `qc_sql_injection` | 15 | PASS |
| `qc_routing` | 29 | PASS |
| **Total** | **191** | **0 FAIL** |

Protokol QC Manual 1 dari dokumen Roadmap kini dijalankan otomatis dan
keluarannya dapat disalin langsung ke tabel pada dokumen tersebut.

## A.7 Item terbuka setelah rekonsiliasi

| # | Item | Catatan |
|---|---|---|
| A-1 | Verifikasi ulang terhadap **MySQL 8.0** sebelum go-live | QC saat ini berjalan pada MariaDB 12.3.2. Perilaku InnoDB/utf8mb4/DECIMAL/JSON/FK identik, tetapi `bash tests/run_qc.sh` wajib diulang pada MySQL 8.0. |
| A-2 | Kolom audit waiver (`onboarding_signed_at`, `signed_ip_address`, `signed_user_agent`) | Sudah ada di skema; penulisan saat onboarding digital masuk Fase 5. |
| A-3 | `tier` pada `capital_wallets` dan mode alokasi | Kolom/mode sudah ada di skema & seed; logika Waterfall/Pro-Rata masuk Fase 4. |
| A-4 | Enam tabel non-`users`/`system_settings` | Sudah dibuat, belum disentuh kode aplikasi (baru dipakai Fase 2–4). |
| A-5 | Validasi encoding input | Input dengan byte invalid UTF-8 saat ini gagal aman (ditolak), belum ramah pengguna. Lihat catatan QC 2.8. |

---

*Addendum ini adalah bagian tak terpisahkan dari kontrak Fase 1.*
