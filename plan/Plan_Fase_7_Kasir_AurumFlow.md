# Plan Fase 7 — Modul Kasir Meja Bakar (*Strict Role Separation*) — AurumFlow

> **Dokumen Perencanaan Eksekusi**
> Proyek: **AurumFlow** — sistem manajemen operasional privat untuk perdagangan emas aluvial dengan settlement **T+1**.
> Fase: **7 — Modul Kasir Meja Bakar (Strict Role Separation)** — permintaan Product Owner sebelum operasi lapangan.
> Status: **`EXECUTED`** — disetujui Product Owner (`dec-a9b2beabe65ecde4`), T1–T12 selesai. `bash tests/run_qc.sh` exit 0 (13/13 suite, 782 assertion) dan 4 probe Node lulus.
> Laporan eksekusi: `plan/Summary_Fase_7_AurumFlow.md`; penyimpangan: `# ADDENDUM` di bawah.

| Item | Nilai |
|---|---|
| Runtime | Native PHP **8.2+** (`declare(strict_types=1)`), tanpa framework, tanpa Composer/`vendor/` |
| Frontend | Tailwind CSS **3.4.19** + Alpine.js **3.17.4** (aset lokal) — papan angka PIN kasir **memakai ulang komponen `investorLogin` yang sudah terdaftar**, jadi **`app.js` tidak berubah dan `CACHE_NAME` tidak naik** |
| Database | MySQL 8.0 / MariaDB — **10 tabel, nol tabel baru, nol kolom baru**; satu `ALTER TABLE users MODIFY COLUMN role ENUM(...)` yang **aditif di ujung daftar** (bukan destruktif) + skrip migrasi idempoten |
| Scope baru | Peran `cashier`; route `GET/POST /cashier/login`; route `GET/POST /operator/kasir`, `POST /operator/kasir/pin`, `POST /operator/kasir/status`; `CashierAuthController`, `CashierController`, `PinLoginService`, `CashierService`; navbar sadar peran |
| Sumber kebenaran | ERD → SAD → Roadmap (FASE 7 + Protokol QC Manual 7) → PRD (hak akses per peran) → UI/UX (meja kasir) |
| Keputusan pengguna | `dec-a9b2beabe65ecde4` — (1) provisioning kasir lewat layar operator **"Kelola Kasir"** (`GET/POST /operator/kasir`); (2) pelanggaran rute diblok dengan **302 ke beranda kasir** (`/operator/teller`), **nol tulisan** |
| Bahasa dokumen | Prosa Indonesia + istilah teknis Inggris (konvensi `Plan_Fase_1..6`) |

---

## 1. Ringkasan Fase 7

### 1.1 Konteks bisnis

Meja Bakar kini dijaga **orang lain**, bukan pengelola: penambang datang malam, timbangan berjalan cepat, dan pengelola tidak selalu berada di lokasi. Hari ini satu-satunya pintu masuk staf adalah `POST /login` dengan **kata sandi**, dan sesi apa pun berperan `operator` **melihat seluruh pembukuan** — `Brankas Utama`, mode alokasi modal, kuota 50 %, defisit berjalan, dompet mitra, bagi hasil 80 : 20, dan layar tutup buku. Memberikan kredensial pengelola kepada kasir berarti menyerahkan pembukuan pemilik, dan itu melanggar prinsip least privilege pada PRD (hak akses per peran).

Fase 7 memisahkan peran itu secara **struktural, bukan sekadar kosmetik**: peran baru `cashier` dengan **kredensial PIN 6 digit ber-pepper**, **pintu masuk sendiri** (`/cashier/login`, papan angka Alpine untuk kecepatan satu tangan di meja timbangan), **daftar izin eksplisit** (meja kasir + dana talangan saja), dan **dinding** di semua rute keuangan lain — dijaga di peladen, dibuktikan assertion.

### 1.2 Tujuan (enam hal yang wajib benar)

1. **Peran ketiga yang aditif.** `users.role` menerima `'cashier'` **tanpa** menulis ulang baris lama, **tanpa** kolom baru, **tanpa** mengubah urutan nilai ENUM yang sudah ada. Satu skrip migrasi idempoten yang dipakai ulang oleh QC (`User::ensureRoleEnum()`).
2. **Pintu PIN cepat, terpisah dari pintu kata sandi.** `GET/POST /cashier/login` (nomor telepon + PIN 6 digit) memakai `Security::verifyPin()`, `PinThrottle`, `OnboardingService::pinPolicy()`, dan papan angka Alpine — **tanpa** menyentuh perilaku `/login` (operator) maupun `/investor/login` (mitra).
3. **Isolasi izin mutlak (*allow-list*).** Kasir **hanya** boleh: `GET /operator/teller`, `POST /operator/teller`, `POST /operator/teller/talangan`. Semua rute lain (settlement, kelola modal + 6 sub-POST, dashboard, kelola kasir, seluruh rute investor) → **302 ke `/operator/teller` tanpa satu pun tulisan** — berlaku untuk GET **dan** POST.
4. **Permukaan kasir bebas data pemilik.** Tidak ada `Brankas Utama`, saldo brankas, `laba bersih`, `pool mitra`, `omzet`, dompet mitra, maupun bagi hasil yang dapat dilihat sesi kasir — dibuktikan dengan **kanari angka** dan **pemindaian daftar terlarang** atas berkas yang terjangkau kasir.
5. **Navbar sadar peran, satu pemilik.** `layouts/operator.php` menyembunyikan **Rekonsiliasi**, **Kelola Modal**, **Kelola Kasir**, dan tombol **Dashboard** bagi kasir; layout yang sama tetap utuh untuk operator (nol regresi).
6. **Operator tidak kehilangan apa pun.** Seluruh perilaku Fase 1–6 tetap: `bash tests/run_qc.sh` exit 0 dengan suite baru, dan laporan `plan/Summary_Fase_7_AurumFlow.md`.

### 1.3 Definisi Selesai (*Definition of Done*)

- [ ] `GET /cashier/login` → 200 memuat field `phone_number`, `pin`, CSRF, dan papan angka `x-cloak`; **tidak pernah** memuat nilai PIN; memakai layout `auth` yang memuat `alpine.min.js`.
- [ ] `POST /cashier/login` dengan nomor + PIN sah → **302 `/operator/teller`**, session ID diregenerasi, CSRF diputar; PIN salah → **401** pesan generik `Nomor telepon atau PIN salah.`; PIN kosong/salah bentuk → **422**; 5 kegagalan / 15 menit per `nomor + IP` → **429** (`PinThrottle`) dengan sisa menit, **tanpa** mengungkap apakah nomor terdaftar.
- [ ] PIN kasir **tidak sah** di `POST /login` (kata sandi) **dan** tidak sah di `POST /investor/login`; akun operator/mitra **tidak sah** di `POST /cashier/login`.
- [ ] `GET /operator/teller` sebagai kasir → **200** (saldo **Kas Meja Bakar** + Emas holding); sebagai anonim → 302 `/login`; sebagai mitra → 302 `/investor/dashboard` (perilaku lama).
- [ ] `POST /operator/teller` dan `POST /operator/teller/talangan` sebagai kasir → **302** PRG dengan **tulisan yang benar** (kas turun / naik persis, satu mutasi kas, `_once` dikonsumsi).
- [ ] `GET`/`POST` ke `/operator/settlement`, `/operator/capital` (+ `topup`, `mitra`, `quota`, `transfer`, `withdrawal`, `invite`), `/operator/kasir`, `/dashboard`, `/investor/*` sebagai kasir → **302 `/operator/teller`**, snapshot kas/dompet/settlement **identik**, token `_once` **tidak** dikonsumsi (bukti guard mendahului efek samping).
- [ ] `POST /logout` sebagai kasir → **302 `/cashier/login`** (bukan `/login`).
- [ ] Halaman kasir (`/operator/teller`) **tidak memuat** string `Brankas`, kanari saldo brankas, `laba bersih`, `pool mitra`, `omzet`, maupun tautan ke `/operator/settlement`, `/operator/capital`, `/dashboard`, `/operator/kasir`.
- [ ] Layar operator **"Kelola Kasir"** (`GET/POST /operator/kasir`): membuat kasir + PIN awal (kebijakan PIN dipakai ulang), reset PIN, aktivasi/deaktivasi — semuanya `requireRole(operator)` + CSRF + `_once` + PRG; `APP_PIN_PEPPER` kosong → gagal dengan pesan dan **nol baris**; akun kasir **tidak** mendapat `capital_wallets`.
- [ ] `database/schema.sql` + ERD memuat ENUM tiga nilai **dengan urutan `operator,investor,cashier`**; `php database/migrate.php` idempoten (dijalankan dua kali → exit 0, tanpa perubahan kedua); baris `users` lama utuh setelah `ALTER`.
- [ ] `bash tests/run_qc.sh` exit **0** (**13 suite**, ≥ **70** assertion baru pada `qc_fase_7`, total ≥ **774** assertion, 0 gagal); tanpa `storage/logs/php-*.log`.
- [ ] `docs/` (ERD, SAD, Roadmap + Protokol QC Manual 7, PRD, UI/UX), `AGENTS.md`, dan `README.md` diperbarui **lebih dulu** (T1); addendum rekonsiliasi diisi pada `# ADDENDUM` dokumen ini.
- [ ] `git status` bersih dari artefak (`tests/.tmp/`, `storage/installed.lock`, `*.log`, `storage/throttle/*`).

### 1.4 Di Luar Ruang Lingkup Fase 7

PIN diubah sendiri oleh kasir (self-service), pemaksaan ganti PIN pada login pertama, pembatalan sesi kasir yang sedang aktif saat akun dinonaktifkan (*session epoch* — butuh DDL), jadwal shift / batas jam kerja kasir, log audit PIN ke tabel (bukan hanya `Logger`), kasir sebagai mitra pemodal bersamaan (butuh dua nomor telepon), kuota/jumlah kasir, kuitansi bertanda tangan, dan papan angka PIN sebagai komponen `Alpine.data()` baru.

---

## 2. Keputusan & Asumsi Terkunci

### 2.1 Keputusan pengguna (`dec-a9b2beabe65ecde4`)

| # | Keputusan | Konsekuensi pada rencana |
|---|---|---|
| U-1 | Provisioning kasir **lewat layar operator** `GET/POST /operator/kasir` (buat kasir + PIN awal, reset PIN, aktivasi/deaktivasi) | §4.5 + T7; butuh `CashierController`, `CashierService`, view `operator/kasir.php`, 4 entry route, dan satu item navbar baru |
| U-2 | Pelanggaran rute → **302 ke `/operator/teller`**, nol tulisan | §5; tidak ada `app/Views/errors/403.php` baru; konsisten `AGENTS.md` ("pelanggar diarahkan ke beranda perannya") dan Roadmap (Protokol QC Manual 5, "Akses URL Kasir Ilegal") |

### 2.2 Asumsi & keputusan arsitektural

| # | Keputusan | Alasan (satu pemilik / risiko) |
|---|---|---|
| D-1 | `users.role` diperluas **di ujung daftar**: `ENUM('operator','investor','cashier')` — **tidak pernah** menyisipkan di tengah atau mengurutkan ulang | MySQL/MariaDB menyimpan nilai ENUM sebagai indeks ordinal; penambahan di ujung adalah perubahan **metadata in-place** (tanpa penyalinan tabel pada kedua mesin), sedangkan penyisipan di tengah memaksa *rebuild* dan berpotensi memetakan ulang nilai lama |
| D-2 | Papan angka PIN kasir **memakai ulang komponen `investorLogin`** yang terdaftar sejak Fase 5 — **tanpa** komponen `Alpine.data()` baru, **tanpa** perubahan `app.js` | `AGENTS.md`: komponen baru dapat belum ada di aset `app.js` yang ter-cache peramban → `x-data` gagal init dan tombol/keypad hilang. Karena `app.js`/`app.min.css` tidak berubah, `CACHE_NAME` **tidak** dinaikkan (tetap `aurumflow-shell-v2`) |
| D-3 | Berkas yang terjangkau kasir memakai **daftar terlarang**: `Brankas`, `laba bersih`, `pool mitra`, `investor_pool`, `omzet`, `net_settled_profit`, `profit_distributions` | Untuk memenuhinya, satu kalimat di modal talangan `teller.php` ditulis ulang **sekali** (tanpa percabangan peran): "Fee dibebankan **hanya** pada hak pengelola; dana mitra tidak tersentuh." — angka `80 %`/`20 %` tetap lengkap di `/operator/settlement` (dan assertion `qc_fase_6` untuk teks itu memang berada di view settlement) |
| D-4 | Kredensial kasir hidup di **kolom yang sudah ada**: `users.password_hash` menyimpan `Security::hashPin()` (PIN ber-pepper). **Nol kolom baru**; `role` adalah pembeda | Konsisten dengan mitra Fase 5; konsekuensi yang disengaja: PIN kasir **tidak pernah** sah di pintu kata sandi karena hash tersimpan adalah hash dari HMAC (pepper tidak ada di basis data) |
| D-5 | Tidak ada lapisan middleware baru | Front controller tetap *data-only*; **satu pemilik** penjaga tetap `BaseController::requireRole()` (dipakai GET dan POST) |
| D-6 | Aturan pintu PIN (throttle, pesan generik, gerbang peran, bentuk PIN) pindah ke **satu pemilik**: `app/Services/PinLoginService.php`, dipakai pintu mitra **dan** pintu kasir | Dua pintu PIN dengan aturan yang disalin akan cepat berbeda; perilaku pintu mitra **harus identik** dan itu dijaga suite `qc_fase_5` (grup A/D/E) |
| D-7 | Label peran punya **satu pemilik**: `Constants::ROLE_LABELS` + `Constants::roleLabel()`; `DashboardController::ROLE_LABELS` dihapus | Pola yang sama dengan `CapitalWallet::TIER_LABELS`; nilai enum mentah tidak pernah dicetak |
| D-8 | Identitas tampilan (`$auth`) dan bootstrap sesi (`BaseController::startUserSession()`) menjadi **satu pemilik** | Hari ini blok tulis-sesi disalin di `AuthController` dan `InvestorAuthController`; pintu kasir akan menjadi salinan ketiga. Konsolidasi kecil ini dijaga `qc_routing` 5.5/5.8/5.9b/5.9c + `qc_fase_5` E.* |
| D-9 | Pembatalan sesi saat akun kasir dinonaktifkan **ditunda** | Butuh kolom `users.session_epoch` (DDL) — di luar prinsip aditif Fase 7. Dicatat sebagai item terbuka O-1 |
| D-10 | Kasir **tidak** mendapat `capital_wallets` | Kasir bukan mitra modal; tanpa ini `AllocationEngine` dapat menyerap modal ke dompet kasir. Dijaga assertion |

---

## 3. Skema Basis Data

### 3.1 Perubahan `users.role` (aditif, bukan destruktif)

**ERD lebih dulu** (aturan `AGENTS.md`): `docs/AurumFlow Entity Relationship Diagram.md` § 4 diubah lebih dahulu menjadi `role ENUM('operator', 'investor', 'cashier') NOT NULL DEFAULT 'investor'`, **lalu** `database/schema.sql` disalin mengikutinya.

```sql
-- database/schema.sql (§2 users) — instalasi BARU
`role` ENUM('operator', 'investor', 'cashier') NOT NULL DEFAULT 'investor',
```

Untuk instalasi lapangan (skema sudah ada, `CREATE TABLE IF NOT EXISTS` tidak menyentuh ENUM) dijalankan migrasi idempoten:

```sql
-- DDL final (satu-satunya perubahan skema Fase 7)
ALTER TABLE `users`
    MODIFY COLUMN `role` ENUM('operator', 'investor', 'cashier') NOT NULL DEFAULT 'investor';
```

**Aturan pengamanan "nol ALTER destruktif":**

1. **Hanya menambah di ujung.** Tiga nilai lama tetap, urutannya tetap, `NULL`/`DEFAULT`/`KEY idx_user_role` tidak disentuh.
2. **Terjaga (guarded).** `ALTER` hanya dijalankan bila `COLUMN_TYPE` hasil `INFORMATION_SCHEMA.COLUMNS` belum memuat `'cashier'`.
3. **Idempoten.** Dijalankan dua kali → kedua kali tanpa perubahan dan tetap `exit 0`.
4. **Tidak ada tulisan data.** Tidak ada `UPDATE users`; baris lama (termasuk operator produksi) tidak pernah ditulis ulang. Dibuktikan snapshot sebelum/sesudah.
5. **Tanpa kolom baru** pada tabel apa pun; `capital_wallets`, `cash_registers`, `bridge_loans`, dan 7 tabel lain tidak berubah sama sekali.
6. **Waktu eksekusi.** Dijalankan di luar jam operasi, setelah backup; `SHOW COLUMNS FROM users LIKE 'role'` diverifikasi. (Mesin dev ini MariaDB — lihat risiko R-2 dan item terbuka `A-1` Fase 1.)

### 3.2 Pemilik logika migrasi (SQL tetap di `app/Models/`)

`AGENTS.md`: SQL hanya di `app/Models/` dan `app/Config/Database.php`. Karena itu DDL **tidak** ditulis di skrip CLI, melainkan di model:

```php
// app/Models/User.php  (Fase 7)
public const ROLE_ENUM_VALUES = [Constants::ROLE_OPERATOR, Constants::ROLE_INVESTOR, Constants::ROLE_CASHIER];

/** @return list<string> nilai ENUM users.role dari INFORMATION_SCHEMA */
public static function roleEnumValues(): array;            // baca saja

/** Predikat murni (tanpa SQL) — seam yang dapat diuji: dipakai skrip migrasi & QC */
public static function needsRoleEnumExtension(string $columnType): bool;

/** Jalankan ALTER terjaga bila perlu. @return bool true = ALTER dijalankan */
public static function ensureRoleEnum(): bool;
```

Pemakai nyata `ensureRoleEnum()` (bukan kode mati):

| Pemakai | Kapan |
|---|---|
| `database/migrate.php` (baru, CLI) | Instalasi lapangan: `php database/migrate.php` — memakai `Database::getConnection()` + `ensureRoleEnum()`, mencetak `diterapkan` / `sudah diterapkan`, gate `INSTALL_TOKEN` sama seperti `database/install.php`, **tidak** menyentuh `storage/installed.lock` |
| `QcFixture::ensureInstalled()` | Setiap suite QC memastikan ENUM selaras dengan `schema.sql` sebelum menguji (pola yang sama dengan `CapitalWallet::ensureOperatorWallet()`) |
| `database/install.php` (T2) | Setelah menerapkan `schema.sql`: gagal-cepat dengan pesan jelas bila ENUM tidak memuat `cashier` (menjaga salinan `schema.sql` yang basi) |

### 3.3 Kredensial PIN kasir

| Aspek | Nilai | Catatan |
|---|---|---|
| Kolom | `users.password_hash` (ada) | Isi = `password_hash(hash_hmac('sha256', pin, APP_PIN_PEPPER))` |
| Panjang | `Security::PIN_LENGTH` = 6 | Satu konstanta, dipakai validasi, kebijakan, dan view |
| Kebijakan | `OnboardingService::pinPolicy($pin, $pin)` | **Satu pemilik** aturan PIN lemah; dipakai layar Kelola Kasir untuk PIN awal & reset |
| Pepper | `APP_PIN_PEPPER` | Kosong → `Security::hashPin()` **melempar** → pembuatan akun kasir gagal dengan pesan, **nol baris** (fail-closed) |
| Kolom audit | `onboarding_signed_at`, `signed_ip_address`, `signed_user_agent` = `NULL` untuk kasir | Waiver clickwrap adalah artefak **mitra pemodal**, bukan staf; tidak diisi, tidak ditampilkan |
| `invite_token` | `NULL` | Alur undangan `/join` tidak dipakai (keputusan U-1) |
| `is_active` | Deaktivasi kasir = `0` | `User::findByPhoneNumber(onlyActive: true)` (dipakai pintu PIN) otomatis menolak |

### 3.4 Yang **tidak** berubah (dijaga assertion)

- Tipe kolom konvensi ERD § 3.1 (`DECIMAL(14,2)`, `DECIMAL(8,3)`, `DECIMAL(5,2)`, `CHAR(36)`) — QC 4.7 tetap berlaku.
- Jumlah kolom `users` tetap **13** (bukti "nol kolom baru").
- `capital_wallets`, `cash_registers`, `cash_mutations`, `hulu_transactions`, `daily_settlements`, `profit_distributions`, `withdrawal_requests`, `bridge_loans`, `system_settings`: **nol perubahan**.
- Urutan penguncian global, atomisitas satu-transaksi, dan semua aritmetika uang Fase 2–6: tidak tersentuh. Kasir hanya memakai jalur `TellerService::payCash()` dan `BridgeLoanService::receive()` yang sudah ada.

---

## 4. Alur Autentikasi

### 4.1 Peta pintu masuk (tiga pintu, satu kredensial satu pintu)

| Pintu | Kredensial | Peran yang diterima | Beranda |
|---|---|---|---|
| `GET/POST /login` | kata sandi | `operator` (tetap) — sejak Fase 7 **menolak** `cashier` secara eksplisit | `/dashboard` |
| `GET/POST /investor/login` | PIN 6 digit | `investor` (tetap) | `/investor/dashboard` |
| **`GET/POST /cashier/login`** (baru) | PIN 6 digit | `cashier` (hanya) | `/operator/teller` |

`AuthController::login()` mendapat satu pertahanan berlapis tambahan: akun ber-`role = cashier` diperlakukan seperti kata sandi salah (throttle dicatat, pesan tetap generik). Alasannya doktrin `AGENTS.md` "satu kredensial, satu pintu": bila kelak seseorang menuliskan kata sandi pada baris kasir, pintu kata sandi tidak boleh menjadi pintu belakang ke akun kasir.

### 4.2 Route (`app/routes.php` — tetap data saja)

```php
// GET
'/cashier/login'    => [CashierAuthController::class, 'showLogin'],
'/operator/kasir'   => [CashierController::class, 'index'],

// POST
'/cashier/login'          => [CashierAuthController::class, 'login'],
'/operator/kasir'         => [CashierController::class, 'store'],
'/operator/kasir/pin'     => [CashierController::class, 'resetPin'],
'/operator/kasir/status'  => [CashierController::class, 'setStatus'],
```

Front controller tetap memeriksa CSRF untuk semua method non-GET (tidak diubah).

### 4.3 `app/Services/PinLoginService.php` — satu pemilik aturan pintu PIN

```php
final class PinLoginService
{
    public const OK = 'ok';  public const LOCKED = 'locked';
    public const FORMAT = 'format';  public const INVALID = 'invalid';

    /**
     * @param array<string, mixed> $credential {'phone_number': string, 'pin': string}
     * @return array{outcome: string, user: ?array<string,mixed>, message: string,
     *                http_status: int, remaining_seconds: int, phone_number: string}
     */
    public static function attempt(array $credential, string $expectedRole, string $ipAddress): array;
}
```

Urutan di dalam `attempt()` (tidak boleh diubah, dan dipakai kedua pintu):

1. Nomor/PIN kosong → `FORMAT` (422, "wajib diisi").
2. `PinThrottle::check($phone, $ip)` → bila terkunci: `LOCKED` (429, "coba lagi dalam N menit", **tanpa** mengungkap apakah nomor terdaftar).
3. Bentuk PIN salah (`^[0-9]{Security::PIN_LENGTH}$`) → `FORMAT` (422, "PIN harus tepat 6 angka").
4. `User::findByPhoneNumber()` + `role === $expectedRole` + `User::verifyPin()` → gagal: `PinThrottle::recordFailure()`, `Logger::warning()` (**tanpa** PIN), `INVALID` (401, `Nomor telepon atau PIN salah.`; 429 bila kegagalan kelima).
5. Sukses: `PinThrottle::clear()`, `Logger::info()`, `OK`.

`InvestorAuthController` disesuaikan menjadi adaptor tipis (view/beranda/label tetap) — **perilaku, pesan, dan kode status identik**, dijaga `qc_fase_5` grup A.* (termasuk 5 gagal → kunci, `verifyPin` sah/`verifyPassword` tolak, PIN tidak sah di `/login`) dan grup E.*

`CashierAuthController` memanggil **service yang sama** dengan `expectedRole = Constants::ROLE_CASHIER`; anggotanya: `showLogin()`, `login()`, `renderLogin(?string $error, string $phoneNumber, int $status)`, `VIEW = 'cashier/login'`, `LAYOUT = 'auth'`. Nomor telepon dikembalikan agar tidak diketik ulang; **PIN tidak pernah** dikembalikan.

### 4.4 View `app/Views/cashier/login.php` (memakai ulang papan angka Fase 5)

Struktur mengikuti `investor/login.php`, dengan tiga perbedaan yang disengaja:

- `x-data="investorLogin({ pinLength: 6 })"` — **komponen Fase 5 yang sudah terdaftar** (keputusan D-2). `press()`/`erase()` di `app.js` mencari field lewat `formField(this, 'pin')`, sehingga nama field `pin` sudah cukup; `x-cloak` tetap menjaga papan angka tak terlihat bila Alpine gagal dimuat.
- Judul tombol: **"Masuk Meja Kasir"**; tautan bantuan: "PIN terkunci atau lupa? Hubungi pengelola."
- `action="<?= Security::e(App::url('cashier/login')) ?>"`, `<?= Security::csrfField() ?>`, `placeholder="mis. 081234567890"`, dan seluruh output dinamis lewat `Security::e()`.

Tidak ada perubahan pada `public/assets/js/app.js`, `public/assets/js/alpine.min.js`, maupun `public/sw.js`.

### 4.5 Bootstrap sesi & destinasi per peran (satu pemilik)

```php
// BaseController
/** Tulis session pengguna setelah kredensial sah: regenerate + rotate CSRF + 5 kunci session. */
protected function startUserSession(array $user): void;   // dipakai ketiga pintu

public static function roleLandingPath(string $role): string; // investor -> /investor/dashboard
                                                              // cashier  -> /operator/teller
                                                              // lain     -> /dashboard
public static function roleLoginPath(string $role): string;    // investor -> /investor/login
                                                              // cashier  -> /cashier/login
                                                              // lain     -> /login
```

`AuthController::logout()` memakai `roleLoginPath()`; `DashboardController::home()`, `requireGuest()`, dan `requireRole()` tetap memakai `roleLandingPath()`. Karena beranda kasir (`/operator/teller`) menerima peran kasir, **tidak ada kemungkinan loop pengalihan**; rute `/dashboard` (operator-only) tetap mengalihkan kasir ke `/operator/teller`.

### 4.6 Layar operator "Kelola Kasir" (`GET/POST /operator/kasir`)

- `CashierController` (`LAYOUT = 'operator'`, view `operator/kasir.php`), seluruh method diawali `requireRole(Constants::ROLE_OPERATOR)`.
- `CashierService` (use-case): validasi nama + format nomor (`/^[0-9+\-\s]{8,20}$/` seperti `install.php`), `OnboardingService::pinPolicy($pin, $pinConfirm)`, lalu `Database::transaction()` → `User::createWithPin()`; duplikat nomor ditangkap dari UNIQUE `uq_users_phone` → `422` "Nomor sudah terdaftar." **tanpa** menulis apa pun.
- Method model baru (SQL hanya di model): `User::createWithPin($fullName, $phone, $pin, $role)`, `User::updatePin($userId, $pin)`, `User::setActive($userId, bool)`, `User::listByRole($role)`.
- Setiap mutasi: CSRF + token sekali-pakai `_once` + PRG + flash; **PIN tidak pernah** muncul di HTML respons maupun flash (hanya nama, nomor, status, `updated_at`).
- Kasir **tidak** mendapat `capital_wallets` — tidak ada pemanggilan `CapitalWallet::*` di jalur ini (dijaga assertion).

---

## 5. Role Guard / Middleware

### 5.1 Matriks izin (satu-satunya sumber)

| Rute | Method | operator | cashier | investor | anonim |
|---|---|---|---|---|---|
| `/operator/teller` | GET, POST | ✅ | ✅ | ⛔ → `/investor/dashboard` | ⛔ → `/login` |
| `/operator/teller/talangan` | POST | ✅ | ✅ | ⛔ → `/investor/dashboard` | ⛔ → `/login` |
| `/operator/settlement` | GET, POST | ✅ | ⛔ → `/operator/teller` | ⛔ → `/investor/dashboard` | ⛔ → `/login` |
| `/operator/capital` + 6 sub-POST | GET, POST | ✅ | ⛔ → `/operator/teller` | ⛔ → `/investor/dashboard` | ⛔ → `/login` |
| `/operator/kasir` + 3 sub-POST | GET, POST | ✅ | ⛔ → `/operator/teller` | ⛔ → `/investor/dashboard` | ⛔ → `/login` |
| `/dashboard`, `/` | GET | ✅ | ⛔ → `/operator/teller` | ⛔ → `/investor/dashboard` | `/login` |
| `/investor/*` | GET, POST | ⛔ → `/dashboard` | ⛔ → `/operator/teller` | ✅ | ⛔ → `/login` |
| `/join`, `/investor/login`, `/cashier/login` | GET, POST | sudah masuk → beranda peran (`requireGuest()`) | idem | idem | ✅ |

### 5.2 Implementasi (tanpa middleware baru)

```php
// TellerController — daftar izin eksplisit, satu tempat
private const ALLOWED_ROLES = [Constants::ROLE_OPERATOR, Constants::ROLE_CASHIER];
// index(), store(), storeBridge(), renderTeller():
$this->requireRole(...self::ALLOWED_ROLES);

// SettlementController, CapitalController, DashboardController, CashierController,
// InvestorController, ExportController, InvestorOnboardingController:
$this->requireRole(Constants::ROLE_OPERATOR);   // atau ROLE_INVESTOR
// tidak satu pun menyebut ROLE_CASHIER  ← inti isolasi
```

**Aturan guard-first (wajib).** `requireRole(...)` adalah **pernyataan pertama** setiap method publik controller — sebelum CSRF kedua, sebelum `Security::consumeFormToken()`, sebelum transaksi basis data. Konsekuensi yang diuji: `POST /operator/settlement` oleh kasir mengembalikan `302` **dan token `_once` sesi kasir tetap aktif**, sehingga tidak ada jalur di mana permintaan terlarang "membakar" hak pengirimnya atau menulis setengah jalan.

### 5.3 Empat lapis pertahanan

1. **Lapis rute/controller** (utama): `requireRole()` pada setiap method; kasir hanya lolos di `TellerController`.
2. **Lapis sesi (otoritatif):** `$_SESSION['user_role']` **hanya** ditulis `startUserSession()` dari baris `users` hasil autentikasi peladen. Tidak ada `$_GET`/`$_POST`/`$_COOKIE` yang dapat memengaruhinya.
3. **Lapis data:** dua jalur tulis yang terjangkau kasir (`TellerService::payCash()`, `BridgeLoanService::receive()`) hanya menyentuh `CashRegister::TELLER_NAME` (`Kas Meja Bakar`); tidak ada `findBrankas()`/`BRANKAS_NAME` di keduanya. Jadi bug pada lapis 1 pun tidak dapat menggerakkan kas `Brankas Utama` dari sesi kasir (dijaga assertion statis).
4. **Lapis tampilan:** tidak ada angka pemilik yang dirender di permukaan kasir (§6), sehingga kebocoran informasi tidak mungkin terjadi meski rute lain kelak salah dibuka.

### 5.4 Pemetaan GET dan POST → **tolak**, bukan "404"

Tidak ada rute baru untuk "menolak": kasir yang membuka rute terlarang benar-benar **mencapai controller**, lalu `requireRole()` mengalihkannya. Ini disengaja agar (a) tidak ada permukaan 404 yang dapat dipakai menebak keberadaan modul, dan (b) perilakunya identik dengan pola mitra Fase 5 yang sudah diuji (`qc_fase_5` E.1/P5.15). Kode status `403` tetap tersedia lewat `BaseController::abort(403, …)` bila kelak PO menghendaki halaman keras — dicatat sebagai item terbuka O-2.

---

## 6. UI/UX Adjustments

### 6.1 Identitas tampilan: `$auth` (satu pemilik)

`BaseController::render()` menyuntikkan (seperti `app_name` dan `flash`):

```php
$data['auth'] = [
  'id' => …, 'name' => …, 'phone' => …, 'role' => …,
  'role_label' => Constants::roleLabel($role),   // 'Kasir Meja Bakar', 'Pengelola (Operator)', …
];
```

`layouts/operator.php`, `layouts/app.php`, dan `dashboard/index.php` membacanya (dashboard tidak lagi menyusun `'user' =>` sendiri) sehingga tidak ada dua tempat yang mendefinisikan "pengguna saat ini". `Constants::ROLE_LABELS` + `roleLabel()` menjadi satu-satunya pemilik label peran (`DashboardController::ROLE_LABELS` dihapus).

### 6.2 Navbar sadar peran — `app/Views/layouts/operator.php`

```php
$isCashier = ($auth['role'] ?? '') === Constants::ROLE_CASHIER;

$navItems = [
  ['path' => App::url('operator/teller'), 'label' => 'Kasir'],           // selalu
];
if (!$isCashier) {
    $navItems[] = ['path' => App::url('operator/settlement'), 'label' => 'Rekonsiliasi'];
    $navItems[] = ['path' => App::url('operator/capital'),    'label' => 'Kelola Modal'];
    $navItems[] = ['path' => App::url('operator/kasir'),      'label' => 'Kelola Kasir'];
}
```

- **Kasir:** navbar berisi **hanya** "Kasir" + tombol "Keluar"; tautan **Dashboard** di header **tidak** dirender; label header menampilkan nama kasir + "Kasir Meja Bakar" agar jelas akun siapa yang aktif.
- **Operator:** keempat item + Dashboard tampil seperti sebelumnya (nol regresi; dijaga assertion pasangan).
- Sorotan menu aktif (`REQUEST_URI`) tetap; struktur HTML, kelas `af-*`, dan target sentuh ≥ 52 px tidak berubah → **tanpa rebuild Tailwind** (kelas yang dipakai sudah ada; `tailwind.config.js` sudah memindai `./app/Views/**/*.php` sehingga folder `cashier/` ikut tercakup bila kelak ada kelas baru).

### 6.3 Header/kartu saldo — kasir hanya melihat Kas Meja Bakar

- Widget sticky header memakai `$register` yang diisi `CashRegister::activeTeller()` (`Kas Meja Bakar`) — **tidak berubah**.
- Kartu "Emas holding" (jumlah nota + gram) tetap — sesuai daftar izin PO.
- **Brankas Utama tidak pernah dirender** di permukaan kasir: `TellerController` tidak pernah memanggil `findBrankas()`; jalur ini sudah bebas Brankas hari ini dan Fase 7 menguncinya dengan assertion.
- Kartu "Dana Talangan"/modal "Terima Dana Talangan" tetap tampil (kasir **wajib** bisa meminjam kas malam). Teks footer modal ditulis ulang sekali (D-3) agar tidak memuat angka bagi hasil.
- Rincian jasa bakar (`upah pengelola` + `penggantian kowi/gas`) **tetap tampil**: itu komposisi harga yang dibayar penambang (transparansi di depan timbangan), bukan angka laba pemilik — keputusan eksplisit, dicatat O-3.

### 6.4 Layar `operator/kasir.php` (baru)

- Kartu "Buat Akun Kasir": nama, nomor telepon, PIN 6 digit, konfirmasi PIN (input `type="password"`, `inputmode="numeric"`, `pattern="[0-9]*"`), tombol kirim.
- Daftar kasir: nama, nomor, status (Aktif/Nonaktif), `updated_at`, tombol **Reset PIN** dan **Aktifkan/Nonaktifkan** (masing-masing form POST terpisah + `Security::csrfField()` + `_once` + konfirmasi `data-confirm`).
- Nilai awal form berasal dari peladen (`value="…"`), placeholder numerik selalu berawalan `mis. ` — kontrak form `AGENTS.md` berlaku penuh (tidak ada `:disabled` pada kontrol data form).

---

## 7. Peta Route & Berkas

### 7.1 Berkas baru

| Berkas | Isi |
|---|---|
| `app/Controllers/CashierAuthController.php` | Pintu PIN kasir (`showLogin`, `login`), layout `auth` |
| `app/Controllers/CashierController.php` | Layar operator "Kelola Kasir" (`index`, `store`, `resetPin`, `setStatus`) |
| `app/Services/PinLoginService.php` | Satu pemilik aturan pintu PIN (throttle, bentuk PIN, gerbang peran, pesan) |
| `app/Services/CashierService.php` | Use-case pembuatan/reset/deaktivasi akun kasir (satu transaksi per aksi) |
| `app/Views/cashier/login.php` | Form PIN kasir + papan angka (memakai ulang `investorLogin`) |
| `app/Views/operator/kasir.php` | Layar kelola kasir |
| `database/migrate.php` | Skrip migrasi CLI idempoten (ENUM) |
| `tests/qc_fase_7.php` | Suite QC Fase 7 (13 suite) |
| `plan/Plan_Fase_7_Kasir_AurumFlow.md`, `plan/Summary_Fase_7_AurumFlow.md` | Dokumen ini + laporan eksekusi |

### 7.2 Berkas yang berubah

| Berkas | Perubahan |
|---|---|
| `docs/AurumFlow Entity Relationship Diagram.md` | `users.role` 3 nilai + catatan "ENUM aditif, jangan menyisipkan di tengah" (T1, **paling awal**) |
| `docs/AurumFlow System Architecture Document.md` | Pohon direktori (dua controller, dua service, dua view) + catatan penjagaan peran |
| `docs/AurumFlow Roadmap and QC Documents.md` | Blok **FASE 7** + tabel **Protokol QC Manual 7** + baris sprint |
| `docs/AurumFlow Product Requirement Document v3.0.md` | Definisi peran & matriks least privilege (kasir: meja kasir + talangan saja) |
| `docs/AurumFlow UI UX Documents.md` | Pintu PIN kasir, navbar kasir, larangan menampilkan Brankas/bagi hasil |
| `database/schema.sql` | ENUM 3 nilai |
| `database/install.php` | Gagal-cepat bila ENUM tidak memuat `cashier` |
| `app/Config/Constants.php` | `ROLE_CASHIER`, `ROLE_LABELS`, `roleLabel()` |
| `app/Models/User.php` | `roleEnumValues()`, `needsRoleEnumExtension()`, `ensureRoleEnum()`, `createWithPin()`, `updatePin()`, `setActive()`, `listByRole()` |
| `app/Controllers/BaseController.php` | `startUserSession()`, `roleLoginPath()`, `roleLandingPath()` + cashier, `render()` menyuntik `$auth` |
| `app/Controllers/AuthController.php` | Tolak `cashier` di pintu kata sandi; pakai `startUserSession()`/`roleLoginPath()` |
| `app/Controllers/InvestorAuthController.php` | Adaptor tipis di atas `PinLoginService` (perilaku identik) |
| `app/Controllers/TellerController.php` | `ALLOWED_ROLES = [operator, cashier]` pada keempat titik guard |
| `app/Controllers/DashboardController.php` | `ROLE_LABELS` dihapus; `Constants::roleLabel()`; `$auth` |
| `app/routes.php` | 6 entry route baru (§4.2) |
| `app/Views/layouts/operator.php` | Navbar sadar peran + header `auth` |
| `app/Views/operator/teller.php` | Kalimat footer modal talangan ditulis ulang (D-3) |
| `app/Views/layouts/app.php`, `app/Views/dashboard/index.php` | Baca `$auth` |
| `tests/bootstrap.php` | `QC_CASHIER`, `seedQcCashier()`, `resetCashierState()`, `purgeQcCashierAccounts()`; `ensureInstalled()` memanggil `User::ensureRoleEnum()` |
| `tests/qc_fase_5.php` | `qc5_all_view_files()` + folder `cashier`; peta `J.13` + `cashier/login.php => auth.php` |
| `tests/run_qc.sh` | `SUITES` + `qc_fase_7`; judul & `LOG_FILE` (Fase 7) |
| `AGENTS.md`, `README.md` | Peran, kontrak, perintah, status fase, tabel suite |

`public/assets/js/app.js`, `public/assets/js/alpine.min.js`, `public/assets/css/app.min.css`, `public/sw.js`, `tailwind.config.js`: **tidak berubah** (D-2).

---

## 8. Tugas Eksekusi (Step-by-Step)

| # | Tugas | Keluaran | Verifikasi |
|---|---|---|---|
| **T1** | **Dokumentasi lebih dulu** (aturan `AGENTS.md`): ERD → SAD → Roadmap (+ Protokol QC Manual 7) → PRD → UI/UX; lalu `AGENTS.md`/`README.md` | `docs/` mutakhir sebelum kode | ENUM di ERD == kode di rencana T2; tidak ada penyimpangan diam-diam |
| **T2** | Skema & migrasi: `schema.sql` (ENUM), `User::roleEnumValues/needsRoleEnumExtension/ensureRoleEnum`, `database/migrate.php`, guard di `install.php` | ENUM 3 nilai, migrasi idempoten | `php -l`, jalankan migrasi 2× (`exit 0`, tanpa perubahan kedua) |
| **T3** | `Constants`: `ROLE_CASHIER` + `ROLE_LABELS` + `roleLabel()`; hapus `DashboardController::ROLE_LABELS` | satu pemilik label | `qc_routing` 5.9c tetap lulus (`Pengelola`) |
| **T4** | `User`: `createWithPin()`, `updatePin()`, `setActive()`, `listByRole()` | jalur kredensial kasir | `php -l` + assertion B.* |
| **T5** | `BaseController`: `startUserSession()`, `roleLoginPath()`, `roleLandingPath()` cashier, `$auth` di `render()`; `AuthController` + `InvestorAuthController` memakainya | satu pemilik bootstrap sesi | `qc_routing` 5.5/5.8/5.9b/5.9c, `qc_fase_5` A./D./E. |
| **T6** | `PinLoginService` + `CashierAuthController` + `app/Views/cashier/login.php` + route `/cashier/login` | pintu PIN kasir | assertion C.*; `qc_fase_5` A.* tetap hijau |
| **T7** | `CashierService` + `CashierController` + `app/Views/operator/kasir.php` + 3 route POST | provisioning kasir | assertion B.* |
| **T8** | `TellerController::ALLOWED_ROLES` + guard-first ditinjau pada **semua** controller | isolasi izin | assertion D.* |
| **T9** | View: navbar sadar peran (`layouts/operator.php`), `$auth` di `layouts/app.php`/`dashboard/index.php`, tulis ulang footer modal `teller.php` | permukaan kasir bersih | assertion E.* |
| **T10** | Fixture + `tests/qc_fase_7.php` + `run_qc.sh` + perubahan suite lama (`qc_fase_5` pemindai, `qc_seed` ENUM) | suite ke-13 | `php tests/qc_fase_7.php` exit 0 |
| **T11** | Gerbang penuh: `bash tests/run_qc.sh` + 4 probe Node | 13 suite lulus | exit 0, tanpa `storage/logs/php-*.log` |
| **T12** | Serah terima: `plan/Summary_Fase_7_AurumFlow.md`, addendum dokumen ini, jalur migrasi lapangan | dokumen + checklist | `git status` bersih |

**Urutan saling ketergantungan:** T1 → T2 → T3/T4 → T5 → T6/T7 → T8 → T9 → T10 → T11 → T12. T8 dan T9 tidak boleh mendahului T1 (dokumen dulu), dan T10 tidak boleh mendahului T8/T9 (suite menguji kode akhir).

---

## 9. Quality Control — Protokol QC Manual 7 (Kasir Meja Bakar)

### 9.1 Tabel protokol manual (siap disalin ke `docs/AurumFlow Roadmap and QC Documents.md`)

| Skenario | Langkah | Hasil yang Diharapkan | [ ] |
|---|---|---|---|
| **Buat akun kasir** | Operator → `/operator/kasir` → isi nama, nomor, PIN `246810` + konfirmasi | Akun muncul di daftar; PIN tidak pernah tampil di layar; PIN lemah (`111111`, `123456`, `123123`) ditolak `422` | [ ] |
| **Login cepat kasir** | Buka `/cashier/login` di ponsel, isi nomor + PIN lewat papan angka | 302 → `/operator/teller`; header menampilkan **Kas Meja Bakar** + Emas holding | [ ] |
| **Kasir catat pembelian** | Geser tombol "Bayar Tunai & Catat" | Flash sukses; saldo Kas Meja Bakar turun persis; muncul di "nota semalam" | [ ] |
| **Kasir tarik dana talangan malam** | `/operator/teller` → **Terima Dana Talangan** → pokok + fee | Saldo Kas Meja Bakar **naik**; baris talangan tampil; **tidak ada** angka Brankas/bagi hasil di layar | [ ] |
| **Kasir coba tutup buku** | Buka `/operator/settlement` dan kirim form-nya | Dialihkan ke `/operator/teller`; **tidak ada** settlement baru, kas tidak berubah | [ ] |
| **Kasir coba kelola modal** | Buka `/operator/capital`, `/operator/kasir`, `/dashboard` | Dialihkan ke `/operator/teller`; tidak ada setoran/pindah kas/dompet berubah; tidak ada satu pun angka `system_settings` terlihat | [ ] |
| **Navbar kasir** | Perhatikan navbar & header | Hanya **Kasir** + **Keluar**; tanpa **Rekonsiliasi**, **Kelola Modal**, **Kelola Kasir**, tanpa tombol **Dashboard** | [ ] |
| **Kasir tidak melihat Brankas** | Cari kata "Brankas" di seluruh layar & modal | Tidak ditemukan di mana pun pada permukaan kasir | [ ] |
| **PIN kasir bukan kata sandi** | Coba `POST /login` dengan nomor + PIN kasir, lalu `POST /investor/login` | `401` pesan generik; tidak ada sesi terbentuk di kedua pintu | [ ] |
| **Kasir dinonaktifkan** | Operator → **Nonaktifkan** → kasir coba login lagi | `401` pesan generik; sesi baru tidak terbentuk (akun nonaktif) | [ ] |
| **Pembatas percobaan** | Salah PIN 5× dari perangkat yang sama | Percobaan ke-5 → `429` "coba lagi dalam N menit"; pesan tidak mengungkap apakah nomor terdaftar | [ ] |
| **Operator tanpa regresi** | Login `/login`, buka keempat layar operator | Seluruh halaman & fungsi Fase 2–6 tetap utuh (termasuk riwayat talangan & tutup buku) | [ ] |

### 9.2 Grup assertion `tests/qc_fase_7.php` (target ≥ 70 assertion, 0 gagal)

**A. Skema & migrasi (aditif, idempoten) — 8 assertion**

| ID | Assertion |
|---|---|
| A.1 | `User::roleEnumValues()` === `['operator','investor','cashier']` (urutan **tepat**, bukti aditif) |
| A.2 | `needsRoleEnumExtension("enum('operator','investor')")` → `true`; `…('operator','investor','cashier')` → `false` |
| A.3 | `ensureRoleEnum()` pada DB termigrasi → `false` (tidak ada ALTER ulang) dan `php database/migrate.php` (via `qc_run_php()`) **exit 0** dengan output "sudah diterapkan" |
| A.4 | ENUM tetap menolak nilai di luar definisi (`'superadmin'` → exception) — kompatibel `qc_seed` 4.7 |
| A.5 | Jumlah kolom `users` tetap 13 (nol kolom baru) |
| A.6 | Daftar nilai ENUM di ERD, `schema.sql`, `migrate.php`, dan DB **identik** (perbandingan string daftar nilai) |
| A.7 | Snapshot peran seluruh baris `users` sebelum/sesudah `ensureRoleEnum()` **identik** (`GROUP_CONCAT(id,role)`) |
| A.8 | Akun kasir **tidak** memiliki baris `capital_wallets` (jumlah dompet untuk `user_id` kasir = 0) |

**B. Provisioning kasir — "Kelola Kasir" (12 assertion)**

| ID | Assertion |
|---|---|
| B.1 | `GET /operator/kasir`: operator 200; anonim 302 `/login`; mitra 302 `/investor/dashboard`; **kasir 302 `/operator/teller`** (nol tulisan) |
| B.2 | `POST /operator/kasir` (operator, CSRF + `_once`) → 302 PRG; baris baru: `role='cashier'`, `is_active=1`, `onboarding_signed_at` NULL, `invite_token` NULL |
| B.3 | Kredensial tersimpan ber-pepper: `User::verifyPin` **sah**, `User::verifyPassword` **tolak** |
| B.4 | `APP_PIN_PEPPER` kosong (`qc_run_php()` + override) → gagal dengan pesan, **nol baris** baru (fail-closed) |
| B.5 | PIN lemah (`111111`/`123456`/`123123`) → `422` nol tulisan; pesan dari `OnboardingService::pinPolicy()` |
| B.6 | Konfirmasi PIN tidak sama → `422` |
| B.7 | Nomor duplikat → `422` nol tulisan (tidak ada UPDATE pada akun lama — dibandingkan snapshot) |
| B.8 | Nomor/nama tidak sah → `422` |
| B.9 | `POST /operator/kasir/pin`: PIN lama tidak berlaku lagi, PIN baru berlaku; body respons **tidak memuat** PIN (lama/baru) |
| B.10 | `POST /operator/kasir/status`: nonaktif → `is_active=0` & login kasir gagal; aktif kembali → login berhasil |
| B.11 | Tanpa `_once` → `419`; `_once` ganda → PRG **tanpa** baris/kolom berubah (snapshot identik) |
| B.12 | `Logger` mencatat pembuatan/reset/deaktivasi tanpa PIN (baca berkas log hari ini) |

**C. Pintu PIN kasir — 13 assertion**

| ID | Assertion |
|---|---|
| C.1 | `GET /cashier/login` → 200; memuat `name="phone_number"`, `name="pin"`, field CSRF, dan `x-cloak` papan angka |
| C.2 | `GET /cashier/login` **tidak** memuat nilai PIN apa pun (tak ada `value=` pada field `pin`) |
| C.3 | `POST /cashier/login` (nomor + PIN sah) → 302 `/operator/teller`; session ID diregenerasi; CSRF diputar |
| C.4 | PIN salah → `401` + pesan generik `Nomor telepon atau PIN salah.`; body tidak memuat nomor+PIN yang dikirim |
| C.5 | PIN kosong → `422`; PIN bukan 6 angka (`12345`, `abcdef`) → `422` |
| C.6 | 5 kegagalan → `429` menyebut sisa menit; pesan **tidak** membedakan nomor terdaftar vs tidak |
| C.7 | Operator (nomor operator + PIN benar) → `401` (pintu kasir hanya peran `cashier`) |
| C.8 | Mitra → `401`; kasir nonaktif → `401`; nomor tidak dikenal → `401` |
| C.9 | PIN kasir tidak sah di `POST /login` (kata sandi) → `401/422`, tidak ada sesi; `$_SESSION['user_id']` tidak muncul |
| C.10 | PIN kasir tidak sah di `POST /investor/login` → `401` |
| C.11 | `POST /cashier/login` tanpa CSRF → `419`; token CSRF session lain → `419` |
| C.12 | Kasir yang sudah masuk membuka `GET /cashier/login` → 302 `/operator/teller` (`requireGuest()` sadar peran) |
| C.13 | `POST /logout` sebagai kasir → 302 **`/cashier/login`** (bukan `/login`) |

**D. Matriks gating & isolasi (inti) — 16 assertion**

| ID | Assertion |
|---|---|
| D.1 | Kasir → `GET /operator/teller` 200; memuat saldo **Kas Meja Bakar** + Emas holding |
| D.2 | Kasir → `POST /operator/teller` (berat/harga sah) → 302; kas turun persis, **1** `hulu_transactions` baru, **1** mutasi `out`, `_once` dikonsumsi |
| D.3 | Kasir → `POST /operator/teller/talangan` → 302; kas naik persis, **1** `bridge_loans` `active`, **1** mutasi `in` |
| D.4 | Kasir → `GET /operator/settlement` → 302 `/operator/teller`; snapshot settlement & kas **identik** |
| D.5 | Kasir → `POST /operator/settlement` (payload lengkap) → 302 `/operator/teller`, nol tulisan, **`_once` tidak dikonsumsi** (guard mendahului efek samping) |
| D.6 | Kasir → `GET /operator/capital` → 302 `/operator/teller` |
| D.7 | Kasir → `POST /operator/capital/{topup,mitra,quota,transfer,withdrawal,invite}` → 302 `/operator/teller`, `capitalSnapshot()` identik untuk keenamnya |
| D.8 | Kasir → `GET /operator/kasir` → 302 `/operator/teller`; nol baris `users` baru |
| D.9 | Kasir → `GET /dashboard` → 302 `/operator/teller`; body tidak memuat `Mode alokasi modal`/`waterfall` |
| D.10 | Kasir → `/investor/dashboard`, `/investor/report`, `/investor/report/csv` → 302 **`/operator/teller`** (bukan `/dashboard`) |
| D.11 | Kasir → `GET /` → 302 `/operator/teller` (`roleLandingPath()` satu pemilik) |
| D.12 | Anonim → `GET /operator/teller` → 302 `/login`; mitra → 302 `/investor/dashboard` (nol regresi; kompatibel `qc_fase_5` E.1) |
| D.13 | Operator → seluruh layar operator tetap 200 (`/dashboard`, teller, settlement, capital, kasir) |
| D.14 | Forge: POST/POST-query dengan `user_role=cashier` atau `role=operator` pada sesi kasir **tidak** mengubah `$_SESSION['user_role']` |
| D.15 | Sesi kasir tidak dapat membuka `/join` (kasir sudah masuk → `requireGuest()` mengalihkan ke `/operator/teller`) |
| D.16 | Rute tak dikenal sebagai kasir tetap `404` tanpa stack trace |

**E. Isolasi tampilan (daftar terlarang & kanari) — 8 assertion**

| ID | Assertion |
|---|---|
| E.1 | Body `GET /operator/teller` (kasir) **tidak** memuat string `Brankas` |
| E.2 | Kanari saldo Brankas (disetel khas oleh fixture, mis. `Rp 77.777.777`) **tidak** muncul di body kasir maupun di flash setelah transaksi |
| E.3 | Body kasir tidak memuat `href` ke `/operator/settlement`, `/operator/capital`, `/operator/kasir`, `/dashboard` |
| E.4 | Body kasir tidak memuat label `Rekonsiliasi` dan `Kelola Modal` |
| E.5 | Body kasir tidak memuat daftar terlarang: `laba bersih`, `pool mitra`, `investor_pool`, `omzet`, `net_settled_profit`, `profit_distributions` |
| E.6 | Body operator `GET /operator/teller` **memuat** tautan `/operator/settlement`, `/operator/capital`, `/operator/kasir`, dan `/dashboard` (pasangan positif — bukti gating bekerja, bukan layout rusak) |
| E.7 | Body kasir tetap memuat `Emas holding` dan rincian jasa bakar (izin PO terpenuhi) |
| E.8 | Pemindaian berkas terjangkau kasir (`TellerController.php`, `Views/operator/teller.php`, `Views/layouts/operator.php`, `Views/cashier/login.php`, `TellerService.php`, `BridgeLoanService.php`) tidak menemukan `BRANKAS_NAME`, `findBrankas`, `CapitalWallet`, `SystemSetting` (komentar dilewati oleh pemindai) |

**F. Statis & kontrak — 8 assertion**

| ID | Assertion |
|---|---|
| F.1 | Route table: 6 entry baru menunjuk controller turunan `BaseController` + method yang benar-benar ada |
| F.2 | Setiap method publik `TellerController` menyebut `requireRole(...)` dan daftar izinnya tepat `[ROLE_OPERATOR, ROLE_CASHIER]` |
| F.3 | `SettlementController`, `CapitalController`, `DashboardController`, `CashierController`, `InvestorController`, `ExportController` **tidak pernah** menyebut `ROLE_CASHIER` |
| F.4 | **Guard-first**: pernyataan pertama badan method untuk rute terlarang adalah `$this->requireRole(...)` (sebelum CSRF kedua/`consumeFormToken`/transaksi) |
| F.5 | `Constants::ROLE_LABELS` memuat tiga peran dan `DashboardController` tidak lagi mendefinisikan label peran sendiri |
| F.6 | View `cashier/login.php` memakai `x-data="investorLogin(` (komponen terdaftar Fase 5) dan **tidak** memakai `Alpine.data(` baru; `app.js` tidak memuat nama komponen baru |
| F.7 | `app.js` dan `app.min.css` **tidak berubah** sejak Fase 6 (dibandingkan catatan) → `CACHE_NAME` tetap `aurumflow-shell-v2`; `AGENTS.md` dan `public/sw.js` tetap menyebut versi yang sama (assertion G.17–G.18 Fase 6 tetap lulus) |
| F.8 | `database/migrate.php` bersifat CLI + gate `INSTALL_TOKEN`, tidak menulis `storage/installed.lock`, dan tidak berada di bawah document root |

**G. Fixture & idempotensi — 6 assertion**

| ID | Assertion |
|---|---|
| G.1 | `purgeQcCashierAccounts()` menghapus akun uji berawalan `0897` dan nol baris terkait (tanpa pelanggaran FK) |
| G.2 | `seedQcCashier()` idempotent: dua pemanggilan → satu akun, PIN tetap sah |
| G.3 | Suite dapat dijalankan **dua kali berurutan** dengan hasil identik (assertion kunci sama) |
| G.4 | `clearPinThrottle()` dipanggil sebelum uji pintu PIN sehingga urutan suite tidak saling meracuni |
| G.5 | Akun/dompet QC Fase 2–6 tidak terpengaruh (`capitalSnapshot()`, `settlementSnapshot()`, `bridgeSnapshot()` sama seperti baseline suite sebelumnya) |
| G.6 | Tidak ada berkas baru di `public/uploads/receipts/`, `storage/throttle/` bersih sesudah suite, tanpa `storage/logs/php-*.log` |

### 9.3 Fixture & pembersihan data

- Konstanta baru di `QcFixture`: `QC_CASHIER = ['phone' => '089700000101', 'pin' => '246810', 'name' => 'Kasir Uji QC 7']`, `QC_CASHIER_PREFIX = '0897%'`, `QC_CASHIER_LOCKED_PHONE` (untuk uji 429).
- `seedQcCashier()` — membuat/menyegarkan akun kasir (`User::createWithPin()` atau `updatePin()`), **idempotent**.
- `resetCashierState()` — `ensureInstalled()` + `seedQcCashier()` + `clearPinThrottle()`; dipanggil di awal suite.
- `purgeQcCashierAccounts()` — `DELETE FROM users WHERE phone_number LIKE '0897%'`; kasir tanpa `capital_wallets`/`withdrawal_requests`/`profit_distributions`, jadi tidak ada urutan FK khusus (berbeda dengan mitra).
- Urutan suite: `qc_fase_7` diletakkan **setelah `qc_fase_6`** dan **sebelum `qc_sql_injection`**; uji 429/401 sengaja menulis `storage/throttle/` → `clearPinThrottle()` wajib di akhir suite.

### 9.4 Perubahan wajib pada suite lama (disengaja)

| Suite | Perubahan | Alasan |
|---|---|---|
| `tests/qc_fase_5.php` | `qc5_all_view_files()` menambah folder `cashier`; peta `J.13` menambah `'cashier/login.php' => 'auth.php'` | View baru ber-Alpine harus ikut dipindai (atribut Alpine = ekspresi, `x-cloak`, bootstrap layout) |
| `tests/qc_seed.php` | Tambah satu assertion: ENUM `users.role` **menerima** `cashier` (selain yang sudah menolak `superadmin`) | Bukti aditif pada suite skema |
| `tests/bootstrap.php` | `ensureInstalled()` memanggil `User::ensureRoleEnum()` | DB QC harus selaras `schema.sql` tanpa langkah manual |
| `tests/run_qc.sh` | `SUITES` + `qc_fase_7`; judul & `LOG_FILE` Fase 7 | Gerbang kelulusan mencakup suite baru |
| `AGENTS.md` / `README.md` | Peran, kontrak, perintah (`php database/migrate.php`, `php tests/qc_fase_7.php`), tabel suite, status fase | Dokumentasi adalah bagian definisi selesai |

Tambahan kontrak baru di `AGENTS.md` (satu paragraf per butir, gaya yang sudah ada): (1) daftar izin kasir & sisi terlarangnya; (2) **guard-first** — `requireRole()` selalu pernyataan pertama; (3) permukaan kasir tidak boleh menyebut `BRANKAS_NAME`/`findBrankas`/dompet mitra/`SystemSetting`; (4) dua pintu PIN → `PinLoginService` + `PinThrottle` + `Security::verifyPin()`; (5) `roleLandingPath()`/`roleLoginPath()` satu pemilik; (6) label peran di `Constants::ROLE_LABELS`; (7) papan angka kasir memakai komponen `investorLogin` — **komponen `Alpine.data()` baru wajib menaikkan `CACHE_NAME`**; (8) `users.role` ENUM **append-only**; (9) `_once` untuk setiap mutasi identitas.

### 9.5 Gate penerimaan

```bash
bash tests/run_qc.sh          # 13 suite, exit 0, ≥ 774 assertion, 0 gagal
node tests/manual/js_form_init_check.js
node tests/manual/js_capital_simulator_check.js
node tests/manual/js_investor_check.js        # papan angka PIN (komponen yang dipakai kasir)
node tests/manual/js_bridge_fee_check.js
php -l $(git ls-files '*.php')                # opsional, cepat
git status --short                            # hanya berkas yang direncanakan
```

---

## 10. Risiko & Mitigasi

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| R-1 | **`ALTER` ENUM di basis data lapangan** (data pembukuan nyata) | Tinggi bila gagal | Aditif di ujung (+ backup + di luar jam operasi); guard `INFORMATION_SCHEMA`; idempoten; A.5/A.7 membuktikan nol kolom baru & baris utuh; ERD diperbarui lebih dulu |
| R-2 | **MariaDB dev vs MySQL 8.0 produksi** (item `A-1` Fase 1) | Sedang | Kedua mesin mendukung `MODIFY COLUMN` ENUM aditif tanpa penyalinan tabel; verifikasi manual `SHOW COLUMNS` didokumentasikan pada protokol; QC tetap berjalan di MariaDB |
| R-3 | **Aset `app.js` ter-cache** membuat papan angka/elemen `x-cloak` hilang | Sedang | Papan angka kasir memakai komponen `investorLogin` yang **sudah** ada di cache peramban sejak Fase 5 (D-2); tidak ada komponen baru; F.6/F.7 mengunci aturan ini |
| R-4 | **Penyimpangan antar pintu PIN** (pesan/kode status/throttle berbeda) | Sedang | `PinLoginService` satu pemilik; `qc_fase_5` A.*/D.* menguji pintu mitra, `qc_fase_7` C.* menguji pintu kasir dengan aturan yang sama |
| R-5 | **Regresi operator** (T5/T6 menyentuh kode Fase 5) | Sedang | Perubahan kecil & lokal; dijaga `qc_routing` 5.5/5.8/5.9b/5.9c, `qc_fase_5` A./D./E., `qc_fase_2/3/4/6` penuh; fallback: kembalikan `$user` di dashboard tanpa mengubah kontrak `$auth` (dicatat di addendum) |
| R-6 | **Kebocoran lewat teks view** (mis. `pool mitra` di modal talangan) | Sedang | D-3 + tulis ulang satu kalimat; E.5 daftar terlarang; E.8 pemindaian berkas |
| R-7 | Kasir berbagi nomor telepon dengan mitra/pengguna lain | Rendah | UNIQUE `uq_users_phone` → `422` jelas; dicatat O-4 (butuh dua nomor) |
| R-8 | Akun kasir dinonaktifkan tetapi sesinya masih hidup | Sedang | Dicatat O-1 sebagai keterbatasan yang disengaja (butuh kolom `session_epoch`); operasional: ganti PIN + minta kasir keluar (logout) — prosedur ditulis di protokol |
| R-9 | PIN 6 digit diamati di meja (*shoulder surfing*) | Sedang | Di luar kendali perangkat lunak (permintaan PO: kecepatan operasional); `PinThrottle` + kebijakan PIN lemah tetap berlaku; dicatat O-5 |

---

## 11. Item Terbuka (perlu keputusan sebelum/di awal eksekusi)

| # | Item | Rekomendasi |
|---|---|---|
| O-1 | Sesi kasir yang sudah aktif tidak otomatis mati saat akun dinonaktifkan | Terima pada Fase 7 (tanpa DDL); tambah `users.session_epoch` + validasi per-request pada fase berikutnya |
| O-2 | Bila PO menghendaki **halaman 403 keras** (bukan 302) untuk rute terlarang | Tetap 302 (doktrin `AGENTS.md` + Protokol QC Manual 5); `abort(403, …)` sudah tersedia |
| O-3 | Apakah kasir boleh melihat rincian jasa bakar (`upah pengelola` + penggantian kowi/gas) | **Ya** — itu komposisi harga yang dibayar penambang, bukan laba pemilik; daftar terlarang tidak memuatnya |
| O-4 | Satu orang yang sekaligus mitra dan kasir | Pakai dua nomor telepon (UNIQUE `uq_users_phone`); rencanakan pemisahan peran pada fase berikutnya bila diperlukan |
| O-5 | Ganti PIN wajib pada login pertama | Tunda (butuh alur & kolom penanda); operator dapat mereset PIN kapan saja |
| O-6 | Log audit PIN ke tabel (bukan hanya `storage/logs/`) | Tunda; `Logger::info/warning` sudah cukup untuk operasi lapangan |
| O-7 | Penempatan menu "Kelola Kasir" (item navbar baru vs kartu di `/dashboard`) | Item navbar baru di blok operator (keputusan U-1) |
| O-8 | Pintu kata sandi `/login` menerima peran apa pun yang hash-nya cocok (menolak hanya `cashier` sejak Fase 7) | Batasi ketat ke `operator` pada fase berikutnya; sekarang berisiko memutus akun mitra lama yang dibuat dengan kata sandi |

---

## 12. Checklist Serah Terima Fase 7

- [ ] `docs/` (ERD, SAD, Roadmap + Protokol QC Manual 7, PRD, UI/UX) diperbarui **sebelum** kode.
- [ ] `database/schema.sql` memuat ENUM tiga nilai; `php database/migrate.php` diuji di salinan basis data (sebelum → sesudah) dan idempoten.
- [ ] Tiga pintu masuk berfungsi terpisah; PIN kasir tidak sah di `/login` dan `/investor/login`.
- [ ] Tujuh rute terlarang (2 GET + 5 POST + 1 halaman internal) menolak kasir dengan 302 ke `/operator/teller` **dan nol tulisan**.
- [ ] Navbar kasir hanya **Kasir** + **Keluar**; tidak ada Brankas, bagi hasil, atau tautan pemilik di permukaan kasir.
- [ ] `bash tests/run_qc.sh` exit 0 (13 suite, ≥ 774 assertion, 0 gagal); empat probe Node lulus.
- [ ] `plan/Summary_Fase_7_AurumFlow.md` ditulis; `# ADDENDUM` dokumen ini memuat setiap penyimpangan + alasannya.
- [ ] `AGENTS.md`/`README.md` memuat peran, kontrak, perintah, dan status fase terbaru.
- [ ] `git status` bersih dari artefak (`tests/.tmp/`, `storage/installed.lock`, `*.log`, `storage/throttle/*`).

---

## 13. Lampiran — Alur Request

### 13.1 `POST /cashier/login` (sukses)

```
peramban ── POST /cashier/login {_token, phone_number, pin}
   │
   ├─ public/index.php : cocokkan route → CashierAuthController::login
   ├─ front controller : verifyCsrf(_token) ── gagal → 419
   ├─ requireGuest()   : sudah masuk? → roleLandingPath(peran) → /operator/teller
   ├─ PinLoginService::attempt(['phone_number','pin'], ROLE_CASHIER, REMOTE_ADDR)
   │     ├─ PinThrottle::check      → terkunci → 429 (pesan sisa menit)
   │     ├─ bentuk PIN (6 angka)    → salah    → 422
   │     ├─ User::findByPhoneNumber + role === cashier + User::verifyPin
   │     │        └─ gagal → recordFailure + Logger::warning → 401 (generik) / 429
   │     └─ sah → clear throttle + Logger::info
   ├─ startUserSession($user) : regenerateSession + rotateCsrf + 5 kunci session
   └─ 302 Location: /operator/teller
```

### 13.2 Kasir menabrak dinding (`POST /operator/capital/topup`)

```
kasir ── POST /operator/capital/topup {_token, _once, topup_wallet_id, topup_amount}
   │
   ├─ front controller : verifyCsrf → sah (kasir punya CSRF sendiri)
   ├─ CapitalController::topUp()
   │     └─ requireRole(Constants::ROLE_OPERATOR)          ← pernyataan PERTAMA
   │           └─ role ('cashier') bukan operator
   │                 → roleLandingPath('cashier') = '/operator/teller'
   └─ 302 Location: /operator/teller   (nol tulisan; _once TIDAK dikonsumsi)
```

### 13.3 Kasir mencatat dana talangan malam (`POST /operator/teller/talangan`)

```
kasir ── POST /operator/teller/talangan {_token, _once, lender_name, principal_amount, fee_*}
   ├─ TellerController::storeBridge()
   │     ├─ requireRole(ROLE_OPERATOR, ROLE_CASHIER)  → lolos
   │     ├─ assertPostSecurity() : CSRF → `_once` (duplikat → PRG tanpa tulisan)
   │     └─ BridgeLoanService::receive($input)        ← fee dihitung peladen, sen integer
   │           └─ Database::transaction(): kunci Kas Meja Bakar → bridge_loans(active)
   │                 → cash_registers (+pokok) → cash_mutations (in)     ← Brankas tidak tersentuh
   └─ flash + 302 /operator/teller (PRG; halaman menerbitkan `_once` baru)
```

---

# ADDENDUM — Catatan Eksekusi

> Fase 7 dieksekusi T1–T12 pada 2026-10-14. Laporan lengkap: `plan/Summary_Fase_7_AurumFlow.md`.
> `bash tests/run_qc.sh` **exit 0** (13/13 suite, **782 assertion**, 0 gagal) dan 4 probe Node lulus.
> Setiap penyimpangan dari rencana di atas dicatat di sini beserta alasannya (aturan `AGENTS.md`).

## A-1. Penyimpangan dari rencana

### A-1.1 `users` memiliki **12** kolom, bukan 13

Rencana § 3.4 dan assertion `A.5a` menyebut "jumlah kolom `users` tetap 13". Hitungan sebenarnya
(`INFORMATION_SCHEMA`, 12 baris) adalah: `id`, `full_name`, `phone_number`, `password_hash`,
`role`, `invite_token`, `is_active`, `onboarding_signed_at`, `signed_ip_address`,
`signed_user_agent`, `created_at`, `updated_at`. Angka rupanya berasal dari penghitungan kasar pada
saat menulis rencana.

**Tindakan:** assertion `A.5a` memakai angka **12** (yang benar), dan klaim "nol kolom baru"
dipertahankan karena jumlahnya tidak berubah oleh Fase 7 — dibuktikan langsung terhadap
`INFORMATION_SCHEMA`, bukan terhadap angka di dokumen.

### A-1.2 `/join` memerlukan penolakan khusus untuk kasir (`refuseCashier()`)

Rencana § 5.1 mengasumsikan seluruh permukaan investor menolak kasir lewat `requireGuest()`. Faktanya
`InvestorOnboardingController::show()/submit()` **sengaja publik** — otorisasinya adalah token
undangan 64 heksadesimal, bukan sesi.

Namun halaman itu merender **nama & nomor telepon calon mitra**, dan PRD/PO menetapkan "ANY view
exposing … investor data" sebagai terlarang bagi kasir. Karena itu ditambahkan deny-guard kecil
(`refuseCashier()`, pernyataan pertama kedua method) yang mengalihkan sesi kasir ke beranda perannya
**sebelum** token dibaca. Pengunjung anonim dan mitra (mis. sesi lama) tidak terpengaruh, sehingga
perilaku Fase 5 tetap utuh — dibuktikan `qc_fase_5` (130/130) dan `qc_fase_7` D.15.

Konsekuensi pada kontrak statis: assertion `F.3` diubah menjadi lebih presisi —
"controller administratif & finansial tidak pernah **MENGIZINKAN** peran kasir"
(pola `requireRole([^)]*ROLE_CASHIER`), bukan "tidak pernah menyebut ROLE_CASHIER" — sebab penolakan
memang harus menyebut peran yang ditolak.

### A-1.3 `findByIdAndRole()` ditambahkan pada model `User`

Rencana § 7.2 hanya mencantumkan `createWithPin`, `updatePin`, `setActive`, `listByRole`.
`CashierService::findCashier()` memerlukan pencarian **id + peran sekaligus** agar id kiriman
formulir tidak dapat dipakai menyentuh akun operator/mitra; memakai `listByRole()` + loop berarti
memuat seluruh kasir hanya untuk menemukan satu baris. Method tambahan ini punya pemanggil nyata
(`CashierService`) dan assertnya `qc_fase_7` B.9/B.10.

### A-1.4 `APP_PIN_PEPPER` kosong diuji lewat skrip anak (bukan `CashierService` langsung)

Assertion `B.4` memakai `qc_run_php()` dengan override `APP_PIN_PEPPER=''` dan skrip anak yang
dibuat ke `tests/.tmp/` (pola yang sudah dipakai `qc_fase_5` A.14), karena variabel environment
tidak dapat diubah di tengah proses yang sedang berjalan. Yang diuji tetap jalur produksi
(`CashierService::create()` → `Security::hashPin()`), bukan tiruan.

### A-1.5 Penyesuaian kecil pada rencana QC (bukan perubahan cakupan)

| Rencana | Kenyataan | Alasan |
|---|---|---|
| `A.5` satu assertion | `A.5a` + `A.5b` | Memisahkan "jumlah kolom" dari "tipe & default ENUM" |
| `B.9`, `B.11` | `B.9a`, `B.11a`, `B.11b` | Reset PIN dan anti-double-submit dipecah agar penyebab kegagalan terbaca |
| `D.5` | `D.5a` + `D.5b` | `D.5b` membuktikan token `_once` kasir tidak dikonsumsi permintaan terlarang |
| `D.6`, `D.10` | digabung ke `D.4` | Satu loop GET atas tujuh rute terlarang lebih ringkas |
| `F.4` | `F.4a`/`F.4b`/`F.4c` | Guard-first diuji per bentuk: POST, GET (delegasi ke `render*`), dan urutan konsumsi `_once` |
| `F.8` | `F.8a` + `F.8b` | Gate token dipisah dari sifat CLI/ketiadaan sentuhan marker |
| `E.8` daftar terlarang memuat `SystemSetting` | dihapus dari daftar | `TellerService` **memang** membaca `system_settings.default_burn_fee` — kebijakan jasa bakar milik meja kasir; yang dilarang adalah data pemilik (Brankas, dompet, laba) |
| `D.7`/`G.5` membandingkan `bridgeSnapshot()` utuh | membandingkan "buku talangan" tanpa `meja_balance` | Saldo kas meja sah berubah karena transaksi kasir; yang relevan adalah baris `bridge_loans` |
| `B.12` cek log | tetap, memakai berkas `storage/logs/app-*.log` | Log harian sudah memuat ketiga peristiwa tanpa PIN |
| target "≥ 70 assertion" | **77 assertion** | Cakupan lebih rinci, bukan cakupan baru |
| target "total ≥ 774" | **782** | `qc_seed` +1 (`4.12c.2`), `qc_fase_7` 77 |

### A-1.6 Probe `js_bridge_fee_check.js` punya 2 kegagalan WARISAN (bukan regresi Fase 7)

Saat gate T11 dijalankan, probe Fase 6 gagal 2 dari 63 assertion:
`uncoveredNoteExpr` diharapkan `bridgeHasUncovered`, sedangkan markup
`app/Views/operator/settlement.php` memakai `bridgeWarnUncovered` — dan
`bridgeWarnUncovered` = `bridgeHasUncovered && inputValid`, sehingga keadaan isian "kosong" tidak
menampilkan peringatan. Dokumen `AGENTS.md` (kontrak Fase 6) menyebut `bridgeHasUncovered` mentah,
sehingga **dokumen, markup, dan probe tidak sepakat di HEAD** — Fase 7 tidak menyentuh
`settlement.php` maupun `app.js` (dibuktikan assertion `F.6`/`F.7` dan `git diff`).

**Tindakan:** probe diselaraskan dengan perilaku nyata (nama kondisi + harapan per keadaan isian
`sudah-sah`), ditambah dua assertion baru yang menjaga **maksud** kontrak Fase 6: baris fee tetap
tidak boleh memakai kondisi turunan laba, dan `bridgeWarnUncovered` wajib tetap menurunkan
`bridgeHasUncovered`. `AGENTS.md` diperbarui agar menyebut nama ekspresi yang benar sebagai satu
pemilik kontrak. Probe kini **65 assertion, 0 gagal**.

### A-1.7 Nomor versi shell PWA tidak dinaikkan (disengaja)

`public/assets/js/app.js` dan `public/assets/css/app.min.css` **tidak berubah** (pintu kasir
memakai komponen `investorLogin` yang sudah terdaftar sejak Fase 5), sehingga `CACHE_NAME` tetap
`aurumflow-shell-v2`. Dijaga assertion `F.6` (tepat 5 registrasi `Alpine.data`, tanpa
`cashierLogin`) dan `F.7` (versi shell sama di `sw.js` dan `AGENTS.md`).

## A-2. Yang tidak dikerjakan (dan alasannya)

* Pencabutan sesi kasir saat akun dinonaktifkan (butuh kolom `users.session_epoch` → DDL) —
  item terbuka O-1 pada laporan.
* Ganti PIN wajib pada login pertama, kuota/jadwal shift kasir, audit PIN ke tabel —
  di luar ruang lingkup Fase 7 (rencana § 1.4).
* Papan angka kasir **tidak** dijadikan komponen `Alpine.data()` baru: alasan cache-aset peramban
  persis seperti yang dicatat `AGENTS.md`, dan dibuktikan assertion `F.6`/`F.7`.
