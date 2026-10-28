# SYSTEM ARCHITECTURE DOCUMENT (SAD)
**Proyek:** AurumFlow — Private Web App Manajemen Operasional & Kemitraan Emas Aluvial  
**Versi:** 1.0.0  
**Target Lingkungan:** Shared Hosting (cPanel / Apache / LiteSpeed)  
**Status:** Disetujui untuk Pengembangan  

---

## 1. Ikhtisar & Batasan Lingkungan (*Environment Constraints*)

AurumFlow dirancang untuk berjalan secara optimal pada infrastruktur **Shared Hosting** tanpa memerlukan *runtime persistent* terpisah (seperti Node.js daemon, Docker container, atau Redis).

### Batasan Shared Hosting & Solusi Desain
* **Alokasi RAM/CPU Terbatas (CloudLinux LVE):** Mengeliminasi ketergantungan *framework* berat untuk menjaga konsumsi memori di bawah 5 MB per *request* dan waktu eksekusi < 20 milidetik.
* **Ketiadaan Terminal Root / SSH:** Seluruh *dependency* eksternal ditiadakan atau dikompilasi lokal sebelum *deploy* (metode *upload & run*).
* **Ketiadaan Background Worker (Queue Daemon):** Seluruh kalkulasi bagi hasil, *settlement*, dan mutasi kas dieksekusi secara sinkron menggunakan transaksi atomik basis data (*ACID transactions*).

---

## 2. Pemilihan Teknologi (*Tech Stack*)

```
┌─────────────────────────────────────────────────────────────┐
│                      FRONTEND LAYER                         │
│  Tailwind CSS (Compiled CLI) + Alpine.js + PWA Manifest/SW  │
└──────────────────────────────┬──────────────────────────────┘
                               │ (Server-Rendered HTML + AJAX)
┌──────────────────────────────▼──────────────────────────────┐
│                    APPLICATION LAYER                        │
│    Native PHP 8.2+ (Front Controller + Custom Router)       │
│    • Session-Based Auth & CSRF Protection                   │
│    • Waterfall & Pro-Rata Allocation Engine                 │
│    • Blind Dashboard Authorization Guard                    │
└──────────────────────────────┬──────────────────────────────┘
                               │ (PDO Prepared Statements)
┌──────────────────────────────▼──────────────────────────────┐
│                      DATABASE LAYER                         │
│            MySQL 8.0 / MariaDB (InnoDB Engine)              │
│    • DECIMAL(14,2) untuk Finansial                          │
│    • DECIMAL(8,3) untuk Presisi Gramatur Emas               │
└─────────────────────────────────────────────────────────────┘
```

| Komponen | Pilihan Teknologi | Pertimbangan Teknis |
| :--- | :--- | :--- |
| **Bahasa Pemrograman** | PHP 8.2+ (Native Modern) | Performa murni, nol beban *overhead framework*, kompatibilitas bawaan pada semua *shared hosting*. |
| **Pola Arsitektur** | MVC Ringkas + *Front Controller* | Memisahkan logika bisnis, perutean (*routing*), dan tampilan tanpa mencampur aduk kode SQL di tag HTML. |
| **Akses Basis Data** | PDO (*PHP Data Objects*) | Wajib menggunakan *prepared statements* untuk mencegah celah *SQL Injection* secara mutlak. |
| **Basis Data** | MySQL 8.0 / MariaDB | *Engine* InnoDB mendukung transaksi aman (`BEGIN TRANSACTION`, `COMMIT`, `ROLLBACK`) untuk mencegah selisih kas. |
| **Styling UI** | Tailwind CSS v3 (CLI Standalone) | Dikompilasi dari komputer lokal menjadi satu berkas `style.min.css` kecil (< 30 KB) tanpa butuh Node.js di server *hosting*. |
| **Reaktivitas Klien** | Alpine.js (v3.x via CDN/Lokal) | Menangani kalkulasi instan di layar kasir hulu (berat kering $\times$ harga tebus $-$ jasa bakar) tanpa *reload* halaman. |
| **Kapabilitas Mobile** | PWA (*Progressive Web App*) | Dilengkapi `manifest.json` dan *Service Worker* dasar agar bisa diinstal ke layar beranda (*Add to Home Screen*) dan bekerja responsif layaknya aplikasi *native*. |
| **Penyimpanan Berkas** | Lokal Shared Hosting (`/uploads`) | Foto nota toko dikompresi di sisi peramban sebelum diunggah, disimpan di direktori berproteksi skrip. |

---

## 3. Struktur Direktori Proyek

Struktur folder memisahkan kode inti bisnis dari folder publik agar berkas logika tidak dapat diakses langsung melalui URL peramban:

```text
aurumflow/
├── app/                              # KODE INTI APLIKASI (Di luar DocumentRoot publik)
│   ├── Config/
│   │   ├── Database.php              # Inisialisasi koneksi PDO MySQL
│   │   └── Constants.php             # Parameter sistem (bunga, kuota serapan default)
│   ├── Controllers/
│   │   ├── AuthController.php        # Login kata sandi (operator & akun lama), logout
│   │   ├── InvestorAuthController.php     # Pintu PIN 6 digit mitra (Fase 5)
│   │   ├── InvestorOnboardingController.php # Klaim tautan undangan + clickwrap waiver (Fase 5)
│   │   ├── CashierAuthController.php # Pintu PIN 6 digit kasir meja bakar (Fase 7)
│   │   ├── TellerController.php      # Kasir meja hulu (timbang & bayar tunai, dana talangan)
│   │   ├── SettlementController.php  # Likuidasi toko & penutupan buku harian
│   │   ├── CapitalController.php     # Tata kelola modal: setoran, pindah kas, kuota, penarikan, undangan
│   │   ├── CashierController.php     # Kelola Kasir: buat akun, reset PIN, aktif/nonaktif (Fase 7)
│   │   ├── KasbonController.php      # Buku Kasbon petani: pencairan, angsuran, daftar petani (Fase 8)
│   │   ├── InvestorController.php    # Dasbor blind investor & permohonan penarikan (Fase 5)
│   │   └── ExportController.php      # Laporan ramah cetak + CSV (Fase 5: lingkup investor)
│   ├── Models/                       # SATU-SATUNYA tempat SQL hidup (lihat AGENTS.md)
│   │   ├── User / CashRegister / CashMutation / HuluTransaction / DailySettlement /
│   │   │   SystemSetting.php         # Fase 1-3
│   │   ├── CapitalWallet / ProfitDistribution / WithdrawalRequest.php   # Fase 4-5
│   │   ├── BridgeLoan.php            # Dana talangan operasional (Fase 6)
│   │   ├── Miner / MinerLoan / MinerLoanLedger.php   # Buku kasbon petani (Fase 8)
│   │   └── OperationalExpense.php    # Voucher beban operasional non-emas (Fase 9)
│   ├── Services/                     # Use-case bertransaksi (bukan helper murni)
│   │   ├── TellerService.php         # Kas keluar hulu — tiga tulisan, satu transaksi
│   │   ├── SettlementService.php     # Tutup buku harian — delapan kelompok tulisan
│   │   ├── AllocationEngine.php      # Waterfall & pro-rata harian (aritmetika sen integer)
│   │   ├── CapitalService.php        # Setoran modal, pindah kas, penarikan (Fase 4)
│   │   ├── OnboardingService.php     # Klaim undangan, kebijakan PIN, teks waiver (Fase 5)
│   │   ├── InvestorService.php       # View-model blind + pengajuan penarikan mitra (Fase 5)
│   │   ├── PinLoginService.php       # Satu pemilik aturan pintu PIN (mitra & kasir, Fase 7)
│   │   ├── CashierService.php        # Kelola akun kasir: buat, reset PIN, aktif/nonaktif (Fase 7)
│   │   ├── BridgeLoanService.php     # Fee talangan, penerimaan kas, pengembalian T+1 (Fase 6)
│   │   ├── KasbonService.php         # Kasbon petani: batas harian, pencairan, angsuran FIFO (Fase 8)
│   │   └── ExpenseService.php        # Beban operasional non-emas: kas keluar + voucher (Fase 9)
│   ├── Helpers/
│   │   ├── Security.php              # Sanitasi input, token CSRF, hashing (kata sandi & PIN + pepper)
│   │   ├── Formatter.php             # Format Rupiah, gramatur 3 desimal, persen
│   │   ├── PinThrottle.php           # Pembatas percobaan PIN/kata sandi berbasis berkas (Fase 5)
│   │   └── Logger.php                # Log berkas terstruktur (tanpa data sensitif)
│   └── Views/
│       ├── layouts/                  # app.php (shell default/legacy), auth.php,
│       │                             # operator.php (2-tier: dashboard + seluruh layar operator),
│       │                             # investor.php (Fase 5), report.php (ramah cetak)
│       ├── auth/                     # Halaman login kata sandi
│       ├── cashier/                  # Pintu PIN kasir meja bakar (Fase 7)
│       ├── operator/                 # Meja kasir (+ modal talangan, + modal beban kas), rekonsiliasi,
│       │                             # kelola modal, kelola kasir, buku kasbon petani (Fase 8)
│       └── investor/                 # login (PIN), onboarding (waiver), dashboard (blind), report
├── public/                           # FOLDER PUBLIK (Diarahkan ke public_html)
│   ├── assets/
│   │   ├── css/
│   │   │   └── app.min.css           # Berkas CSS hasil kompilasi Tailwind CLI
│   │   ├── js/
│   │   │   ├── alpine.min.js         # Library Alpine.js
│   │   │   └── app.js                # Inisialisasi PWA & helper kompresi foto nota
│   │   └── icons/                    # Ikon aplikasi PWA (192, 512, 512 maskable, apple-touch 180)
│   ├── uploads/
│   │   ├── receipts/                 # Direktori penyimpanan foto nota toko emas
│   │   └── .htaccess                 # Memblokir eksekusi skrip PHP di folder upload
│   ├── .htaccess                     # URL Rewriting menuju index.php
│   ├── index.php                     # Front Controller (Single Entry Point)
│   ├── manifest.json                 # Metadata PWA (start_url & scope relatif)
│   ├── offline.html                  # Halaman statis saat perangkat offline (Fase 5)
│   └── sw.js                         # Service Worker PWA (cache hanya aset statis)
└── tailwind.config.js                # Konfigurasi Tailwind lokal untuk kompilasi
```

> **Catatan penempatan (Fase 4).** `AllocationEngine.php` berada di `app/Services/`, bukan
> `app/Helpers/` seperti sketsa awal dokumen ini: mesin alokasi memuat *use-case* (membaca dompet
> terkunci, menulis `profit_distributions`) sehingga mengikuti pola `TellerService` /
> `SettlementService`. Penyimpangan ini dicatat pada ADDENDUM `plan/Plan_Fase_4_AurumFlow.md`.
> Seluruh SQL tetap hanya hidup di `app/Models/`; controller tidak pernah menyentuh query.

> **Catatan penempatan (Fase 5).** Tiga tambahan di luar daftar awal dokumen ini:
> `InvestorAuthController.php` dan `InvestorOnboardingController.php` (dua permukaan masuk baru —
> PIN harian dan klaim undangan — yang tidak dicampur ke `AuthController` agar pintu kata sandi
> tidak tersentuh), `Services/{OnboardingService,InvestorService}.php` (use-case beraturan sendiri,
> pola `CapitalService`), dan `Helpers/PinThrottle.php` (infrastruktur keamanan, sekamar dengan
> `Security`/`Logger`). Penyimpanan berkas pembatas berada di `storage/throttle/` — **di luar**
> `public/`, sehingga tidak dapat dijangkau peramban. Rincian & alasan: ADDENDUM
> `plan/Plan_Fase_5_AurumFlow.md`.

> **Catatan penempatan (Fase 6).** `Models/BridgeLoan.php` mengikuti aturan "SQL hanya di
> `app/Models/`", sedangkan `Services/BridgeLoanService.php` memuat *use-case* beraturan sendiri
> (menghitung fee dari tarif atau nominal, menerima kas dengan transaksi tiga tulisan, dan
> mengembalikan pokok + fee di dalam transaksi tutup buku) — pola yang sama dengan
> `CapitalService`/`AllocationEngine`, bukan helper murni. Tidak ada berkas investor yang tersentuh.
> Rincian & alasan: `plan/Plan_Fase_6_Talangan_AurumFlow.md`.

> **Catatan penempatan (Fase 7).** `CashierAuthController.php` mengikuti alasan yang sama dengan
> `InvestorAuthController.php`: pintu masuk dengan semantik kredensial yang berbeda (PIN kasir)
> tidak dicampur ke `AuthController` agar pintu kata sandi tidak tersentuh, sedangkan aturan
> pintu PIN (bentuk PIN, pembatas percobaan, pesan generik, gerbang peran) hidup **satu kali**
> di `Services/PinLoginService.php` yang dipakai kedua pintu. `CashierController.php` +
> `Services/CashierService.php` memuat use-case tata kelola akun kasir (buat, reset PIN,
> aktif/nonaktif) — tidak disatukan ke `CapitalController` karena akun staf bukan tata kelola
> modal. `Views/cashier/` adalah permukaan masuk tersendiri (layout `auth`), dan tidak ada
> berkas investor maupun berkas kas yang tersentuh. Rincian & alasan:
> `plan/Plan_Fase_7_Kasir_AurumFlow.md`.

> **Catatan penempatan (Fase 9).** `Models/OperationalExpense.php` mengikuti aturan "SQL hanya di
> `app/Models/`" (termasuk DDL untuk migrasi **M3**), sedangkan
> `Services/ExpenseService.php` memuat *use-case* beraturan sendiri: mencatat kas keluar non-emas
> dalam **satu** transaksi (kunci pos kas → cek saldo → mutasi `out` → saldo turun → voucher),
> pola yang sama dengan `KasbonService`. Tidak ada controller baru: jalurnya
> `POST /operator/opex/pay` ditangani `TellerController::storeOpex()` supaya daftar izin kasir
> tetap **dua** controller (`TellerController`, `KasbonController`) dan modalnya hidup di komponen
> `tellerForm` yang sudah terdaftar. Beban **tidak** menambah tabel penengah pada tutup buku:
> penyerapannya hanya `UPDATE operational_expenses SET settlement_id`. Rincian & alasan:
> `plan/Summary_Fase_9_OPEX_AurumFlow.md`.

> **Catatan penempatan (Fase 8).** `KasbonController.php` + `Services/KasbonService.php` memuat
> use-case buku piutang petani (batas harian, pencairan, angsuran FIFO), sedangkan SQL hidup di
> tiga model baru (`Miner`, `MinerLoan`, `MinerLoanLedger`) — pola yang sama dengan
> `BridgeLoan`/`BridgeLoanService`. Controller ini adalah **permukaan kedua** yang menerima peran
> `cashier` (setelah `TellerController`), sehingga isolasi peran tetap bersifat *allow-list*.
> `app.min.css` **tidak dikompilasi ulang**: view baru hanya memakai kelas yang sudah ada di
> berkas hasil kompilasi. Rincian & alasan: `plan/Plan_Kasbon_Petani_AurumFlow.md`.

---

## 4. Keamanan Sistem & Standar Transaksi

### 4.1. Proteksi Basis Data & Transaksi Finansial
* Seluruh operasi mutasi kas keluar (pembayaran penambang) dan kas masuk (likuidasi toko) wajib dibungkus dalam blok transaksi PDO:
  ```php
  $pdo->beginTransaction();
  try {
      // 1. Catat transaksi emas
      // 2. Kurangi kas aktif meja bakar
      // 3. Tambah inventaris gramatur
      $pdo->commit();
  } catch (Exception $e) {
      $pdo->rollBack();
      // Log error & batalkan seluruh perubahan
  }
  ```
* Nilai moneter disimpan menggunakan tipe data `DECIMAL(14,2)` untuk mencegah kesalahan pembulatan bilangan pecahan (*floating-point rounding errors*).
* Berat emas disimpan menggunakan tipe data `DECIMAL(8,3)` guna mempertahankan presisi timbangan hingga 0,001 gram.
* **Urutan penguncian global (semua jalur uang).** Setiap transaksi yang mengunci lebih dari satu baris
  memakai urutan yang sama agar tidak ada dua jalur yang saling menunggu (*deadlock*):
  `capital_wallets` (`id` menaik) → `cash_registers` (nama menaik) → **`bridge_loans`**
  (`status='active'`, `created_at ASC, id ASC`) → `hulu_transactions` → `system_settings`.
  Jalur penerimaan dana talangan menyentuh `cash_registers` → `bridge_loans` (tanpa `hulu`), sehingga
  urutan relatifnya konsisten dengan jalur tutup buku.
* **Kelompok tulisan tutup buku (Fase 6): tujuh.** Satu sesi `SettlementService::settle()` menulis
  `daily_settlements` + `hulu_transactions` + `cash_registers` ×2 + `cash_mutations` (2 × `in`) +
  `system_settings` (defisit) + `profit_distributions`/`capital_wallets.balance_profit`
  (`AllocationEngine`) + **`bridge_loans` + `cash_registers` (pengembalian) + `cash_mutations`
  (`out` per talangan)** — seluruhnya dalam **satu** `Database::transaction()`.

### 4.2. Arsitektur Isolasi Data (*Blind Dashboard Isolation*)
* Pengecekan otorisasi dilakukan secara ketat pada tingkat peladen (*backend level*):
  ```php
  // Logika di Controller saat peran = investor
  if ($currentUser['role'] === 'investor') {
      // Kueri HANYA mengambil data yang berelasi dengan wallet_id miliknya
      // Melarang pemanggilan kolom total laba kotor, kuota pengelola, atau nota hulu
  }
  ```
* Seluruh respons JSON atau variabel tampilan disanitasi sebelum dirender agar metadata internal tidak bocor melalui *source code* peramban.
* **Penegakan berlapis (Fase 5).** Isolasi bukan sekadar niat di komentar, melainkan dijaga tiga hal:
  1. **Gerbang peran** — setiap route investor memanggil `requireRole(Constants::ROLE_INVESTOR)`;
     pelanggar diarahkan ke beranda perannya (investor → `/investor/dashboard`, selain itu →
     `/dashboard`), bukan diberi halaman error.
  2. **Query sadar-kolom** — method model khusus investor memilih kolom satu per satu (tidak ada
     `SELECT *`, tidak ada agregat sesi). Bila sebuah baris distribusi perlu tanggal putarannya,
     yang di-JOIN hanyalah `daily_settlements.settlement_date` — bukan omzet, laba, atau defisit.
  3. **Pemindaian & assertion** — `tests/qc_fase_5.php` memindai berkas investor terhadap daftar
     terlarang (`hulu_transactions`, `HuluTransaction`, `farmer_alias`, `total_shop_revenue`,
     `net_settled_profit`, `operator_share_80`, `investor_pool_20`, `SystemSetting`, `CashRegister`,
     `CashMutation`, dan sejak Fase 6: `bridge_loans`, `BridgeLoan`, istilah tampilan "talangan")
     **dan** memeriksa isi HTML, laporan cetak, serta CSV agar tidak memuat angka
     maupun nama mitra lain. Daftar terlarang yang sama juga tercatat di `AGENTS.md`.
* **Dompet diambil dari sesi.** Tidak ada route investor yang menerima `wallet_id` dari formulir;
  `InvestorService` menyelesaikannya dari `$_SESSION['user_id']`, sehingga payload yang menunjuk
  dompet mitra lain tidak berpengaruh (diuji lewat HTTP).

### 4.3. Autentikasi PIN Mitra & Pembatasan Percobaan (Fase 5)
* **PIN 6 digit ber-pepper.** `users.password_hash` menyimpan
  `password_hash(hash_hmac('sha256', pin, APP_PIN_PEPPER))`. Akibat yang disengaja: PIN **tidak
  pernah** sah di pintu kata sandi `POST /login`, sehingga pembatasan percobaan tidak bisa
  dilewati lewat pintu lain. `APP_PIN_PEPPER` kosong → onboarding **ditolak** (fail-closed).
* **Kebijakan PIN lemah** ditolak: panjang bukan 6 digit, seluruh digit sama (`111111`), barisan
  naik/turun lurus (`123456`, `654321`), dan blok tiga digit berulang (`123123`).
* **Pembatas percobaan** (`app/Helpers/PinThrottle.php`): 5 kegagalan dalam 15 menit mengunci
  kombinasi `nomor telepon + IP` selama 15 menit; jawaban `429` menyebut sisa menit. Berkasnya
  hidup di `storage/throttle/` (di luar document root), ditulis atomik (`LOCK_EX`), dan entri
  berumur > 24 jam dibersihkan. Bila direktori tidak dapat ditulis, sistem turun mutu ke
  penghitung sesi + satu peringatan log — bukan menggagalkan login.
* **Token undangan sekali pakai.** `users.invite_token` (64 heksadesimal) di-NULL-kan pada saat
  tanda tangan; jejak audit (`onboarding_signed_at`, `signed_ip_address`, `signed_user_agent`)
  diambil dari peladen, bukan dari formulir.

### 4.4. Keamanan Direktori Unggahan (*Uploads Hardening*)
Folder `public/uploads/receipts/` dilengkapi berkas `.htaccess` independen berisi:
```apache
# Matikan eksekusi script di folder unggahan
php_flag engine off
Options -Indexes -ExecCGI
AddHandler cgi-script .php .php3 .php4 .php5 .phtml .pl .py .jsp .asp .sh .cgi
<Files ^(*.jpeg|*.jpg|*.png)>
    order deny,allow
    allow from all
</Files>
```
Aturan ini mencegah peretasan melalui *web shell* jika ada berkas berbahaya yang menyamar sebagai foto nota.

### 4.5. Jembatan Likuiditas Dana Talangan (Fase 6)

* **Uang tanpa float.** Pokok, tarif fee, dan nominal fee diurai ke **sen integer** dengan pembulatan
  *half away from zero* (`fee = intdiv(pokok_sen × tarif_perseratus + 5000, 10000)`); nilai masuk
  basis data sebagai **string desimal kanonik** lewat `TellerService::centsToDecimal()`. Tidak ada
  `float`, tidak ada `number_format()` pada jalur ini.
* **Penerimaan = tiga tulisan, satu transaksi.** `BridgeLoanService::receive()` mengunci pos kas
  (`CashRegister::lockTellerForUpdate()`), menyisipkan `bridge_loans` (`status='active'`),
  menambah saldo Kas Meja Bakar, lalu menulis `cash_mutations` (`in`) — semuanya di dalam
  `Database::transaction()`; kegagalan mana pun menggulung seluruhnya.
* **Pengembalian menyatu dengan tutup buku.** `SettlementService::settle()` memanggil
  `BridgeLoanService::settleLoans()` **di dalam** transaksi yang sama: satu mutasi `out` per
  talangan dari pos kas yang sama, baris talangan menjadi `settled` + `settlement_id` +
  `fee_absorbed` + `fee_uncovered`.
* **Pemisahan hak mitra bersifat struktural.** `AllocationEngine` tidak pernah menerima fee
  talangan: bagi hasil 80 : 20 dihitung lebih dulu, dan fee dikurangkan dari porsi pengelola
  (`min(fee, operator_share_80)`), sehingga `investor_pool_20`, Σ `profit_earned`, serta setiap
  `capital_wallets.balance_profit` tidak dapat berubah karena adanya talangan (PRD § 3.5).
* **Penjagaan saldo sebelum tulisan.** Bila `saldo meja setelah alokasi < Σ(pokok + fee)`, tutup buku
  ditolak `422` **tanpa satu pun tulisan** dan talangan tetap `active` untuk dicoba pada siklus
  berikutnya.

### 4.6. Pemisahan Peran Kasir & Isolasi Permukaan (Fase 7)

* **Tiga peran, satu pintu per kredensial.** `users.role` = `operator` (Pengelola, kata sandi di
  `POST /login`), `investor` (Mitra Pemodal, PIN di `POST /investor/login`), `cashier`
  (Kasir Meja Bakar, PIN di `POST /cashier/login`). PIN kasir disimpan **ber-pepper** pada kolom
  `users.password_hash` yang sudah ada, sehingga PIN itu tidak pernah sah di pintu kata sandi;
  pintu kata sandi juga **menolak** akun ber-`role = cashier` secara eksplisit.
* ***Allow-list*, bukan *deny-list*.** Permukaan yang menerima peran `cashier` adalah daftar izin
  **eksplisit** dan berjumlah **dua** (sejak Fase 8): `TellerController`
  (`GET /operator/teller`, `POST /operator/teller`, `POST /operator/teller/talangan`) dan
  `KasbonController` (`GET /operator/kasbon`, `POST /operator/kasbon`,
  `POST /operator/kasbon/angsuran`, `POST /operator/kasbon/petani`), keduanya dengan
  `ALLOWED_ROLES = [ROLE_OPERATOR, ROLE_CASHIER]`. Seluruh controller lain memakai
  `requireRole(Constants::ROLE_OPERATOR)` (atau `ROLE_INVESTOR`) dan **tidak pernah** menyebut
  `ROLE_CASHIER` (`qc_fase_7` F.3b, `qc_fase_8` F.6).
* **Guard-first.** `requireRole(...)` adalah **pernyataan pertama** setiap method publik controller —
  sebelum pemeriksaan CSRF kedua, sebelum `Security::consumeFormToken()`, dan sebelum transaksi
  basis data. Akibatnya sesi kasir yang mencoba rute terlarang dijawab `302` menuju beranda
  perannya (`/operator/teller`) **tanpa satu pun tulisan** dan **tanpa mengonsumsi token `_once`**
  miliknya. Pola ini berlaku untuk GET maupun POST.
* **Satu pemilik pemetaan peran.** `BaseController::roleLandingPath()` (beranda) dan
  `roleLoginPath()` (pintu masuk setelah keluar) adalah satu-satunya tempat peran dipetakan ke
  URL; `render()` menyuntikkan `$auth` sehingga layout tidak perlu membaca `$_SESSION` sendiri.
  Label peran hidup satu kali di `Constants::ROLE_LABELS`.
* **Permukaan kasir bebas data pemilik.** Berkas yang terjangkau sesi kasir
  (`TellerController`, `KasbonController`, `Views/operator/teller.php`,
  `Views/operator/kasbon.php`, `Views/layouts/operator.php`, `Views/cashier/login.php`,
  `TellerService`, `BridgeLoanService`, `KasbonService`, dan ketiga model kasbon) tidak boleh
  menyebut `BRANKAS_NAME`/`findBrankas`, dompet modal (`CapitalWallet`), angka bagi hasil
  (`laba bersih`, `pool mitra`, `omzet`), maupun `profit_distributions`. Pengecualian sempit
  sejak Fase 8: `KasbonService` **boleh** membaca `SystemSetting` **hanya** untuk
  `SETTING_DAILY_LIMIT` — batas operasional yang memang perlu diketahui kasir — dan tidak untuk
  kunci lain (`qc_fase_8` G.5). Navigasi operator (Dashboard, Rekonsiliasi, Kelola Modal, Kelola
  Kasir) disembunyikan bagi kasir di `Views/layouts/operator.php`; yang tersisa hanya Kasir dan
  Buku Kasbon.
* **Satu kerangka 2-tier untuk seluruh halaman pengelola.** `/dashboard` dan semua layar
  `/operator/*` memakai `Views/layouts/operator.php`; layout-nya dipilih controller
  (`render($view, $data, self::LAYOUT)`), sehingga dashboard tidak lagi memakai shell 1-tier
  `Views/layouts/app.php` (layout itu tetap ada sebagai default `render()` dan dijaga
  `qc_fase_5` I.10). Baris bawah layout adalah navigasi tab tersegmen — **6 tab** untuk operator,
  **2 tab** untuk kasir — dengan penanda aktif diturunkan dari `REQUEST_URI` dan penggeseran
  horizontal pada ponsel.

### 4.7. Buku Kasbon Petani & Validasi Dua Lapis (Fase 8)

* **Kas keluar atomik.** Pencairan kasbon adalah **satu** `Database::transaction()`:
  kunci `cash_registers` (`lockTellerForUpdate()`) → kunci baris `system_settings`
  (`daily_loan_limit`, `getForUpdate()`) → hitung `Σ pokok hari ini` → dua validasi → tulis
  `miner_loans` + `miner_loan_ledger` + `cash_registers` (turun) + `cash_mutations` (`out`).
  Urutan penguncian global menjadi
  `capital_wallets → cash_registers → bridge_loans → miner_loans → hulu_transactions → system_settings`;
  jalur ini tidak menyentuh dompet, talangan, maupun hulu, sehingga tidak membentuk siklus tunggu
  dengan tutup buku.
* **Dua lapis validasi, keduanya di dalam kunci.** (a) `Σ pokok yang dicairkan hari ini + pokok
  baru ≤ daily_loan_limit` — mengunci baris setting membuat dua terminal tidak dapat melewati
  batas bersama; (b) `pokok ≤ saldo Kas Meja Bakar`. Pelanggaran → `422` **tanpa satu pun
  tulisan** dengan pesan yang menyebut angka sisa. `daily_loan_limit = 0` berarti kasbon
  dinonaktifkan (pesan eksplisit, bukan galat teknis).
* **Angsuran FIFO, satu mutasi kas.** Pembayaran (tunai maupun potong nota) mengunci
  `miner_loans` ber-`status='open'` milik petani dengan urutan
  `issued_on ASC, created_at ASC, id ASC` (pola `BridgeLoan::lockActiveForUpdate()`), memotong
  pinjaman tertua lebih dulu, menulis **satu** baris ledger per pinjaman tersentuh, dan **satu**
  `cash_mutations` masuk sebesar total — sehingga uang fisik yang diterima kasir selalu sama
  dengan jejak pembukuan.
* **Potong nota tidak memotong nota.** Pembelian hulu tetap dibayar penuh
  (`hulu_transactions.net_cash_paid`), dan angsuran potong nota dicatat sebagai kas masuk
  tersendiri. Efek netto laci identik dengan uang yang benar-benar diserahkan, sementara
  `modal belanja hulu` — dasar laba, bagi hasil 80 : 20, dan pool mitra — tetap utuh. Karena itu
  tabel `hulu_transactions` **tidak** diubah (nol `ALTER`).
* **Nominal tidak pernah dipercaya dari klien.** Form hanya mengirim pemilih petani, nominal,
  metode, dan catatan; sisa utang, batas harian, dan `balance_after` dihitung ulang dari basis
  data. Pemilih petani boleh berupa teks label (`miner_id` diisi peramban), dan peladen
  menyelesaikannya lewat `KasbonService::resolveMiner()` dengan empat tingkat: UUID sah →
  label kandidat → nama eksak → `422`.

### 4.8. Buku Hak Mitra Lapangan & Portal Blind (Fase 10)

* **Dua tabel baru, nol `ALTER`.** `officers` (akun buku: `user_id` 1:1, `cut_pct`,
  `balance_payable`) dan `officer_ledger_entries` (`cut`/`bonus`/`disbursement`,
  `balance_after`, `cut_pct`/`base_amount` untuk akrual, `register_id`/`mutation_id` untuk
  pencairan). Nilai ENUM `'officer'` ditambahkan **di ujung** `users.role`. Migrasi lapangan
  **M4** (`php database/migrate.php`) menjalankan tiga langkah idempoten: ENUM
  (`User::ensureRoleEnum()`), dua tabel (`Officer::ensureSchema()`), dan kunci
  `officer_cut_total_cap_pct` (`SystemSetting::ensure()`).
* **Dua kanal pemotongan laba, masing-masing sekali.** `SettlementService::quote()` menghitung
  potongan dari `max(0, laba kotor)` via `OfficerService::previewCuts()` (fungsi murni) dan
  memotong `O + B + C` sebelum pemulihan defisit serta bagi hasil. Potongan dibebankan saat
  **akrual**; bonus manual dibebankan saat **penyerapan akrual** (`settlement_id` diisi tutup
  buku berikutnya). Keduanya **bukan** voucher `operational_expenses` — itu akan memotong laba
  dua kali.
* **Tutup buku menulis sembilan kelompok tulisan** (tambahan Fase 10: akrual `cut` per petugas +
  saldo `officers`, dan penyerapan `bonus`). Urutan penguncian global diperluas:
  `capital_wallets → cash_registers → bridge_loans → miner_loans → hulu_transactions →
  operational_expenses → officers → officer_ledger_entries (bonus belum terserap) →
  system_settings`.
* **Pencairan hak bukan beban.** Satu transaksi: kunci pos kas terpilih (`kas_meja`/`brankas`
  dari daftar putih — bukan UUID kiriman klien) → kunci baris `officers` → cek saldo hak & saldo
  pos → mutasi `out` (`Hak petugas: <nama>`) → saldo pos turun → entri `disbursement`
  (`mutation_id` UNIQUE, satu baris kas = satu baris buku) → saldo hak turun. Laba tidak
  tersentuh sama sekali.
* **Komponen.** Model `Officer` + `OfficerLedgerEntry` (SQL & DDL), service `OfficerService`
  (tata kelola, bonus, pencairan, akrual, pratinjau) + `OfficerPortalService` (baca-saja blind),
  controller `OfficerController` (Layar G), `OfficerAuthController` (`/officer/login`), dan
  `OfficerPortalController` (Layar H). Layout baru `layouts/officer.php`.
* **Isolasi blind bersifat allow-list.** Hanya `/officer/dashboard` dan `/officer/riwayat`
  (GET, tanpa POST) menerima peran `officer`; seluruh permukaan lain menjawab `302` ke beranda
  perannya tanpa tulisan. Berkas portal **dilarang** menyentuh istilah pemilik sistem dan
  **tidak pernah** merender `cut_pct` maupun `base_amount` (tarif × basis = laba kotor).

---

## 5. Alur Deployment ke Shared Hosting

1. **Tahap Lokal (Mesin Pengembang):**
   * Eksekusi kompilasi Tailwind: `npx tailwindcss -i ./src/input.css -o ./public/assets/css/app.min.css --minify`.
   * Pengujian logika *router* dan kalkulator pada lingkungan lokal.
2. **Tahap Unggah (cPanel):**
   * Letakkan folder `app/` di direktori induk akun (sejajar dengan `public_html`, bukan di dalamnya).
   * Pindahkan seluruh isi folder `public/` ke dalam direktori `public_html`.
   * Sesuaikan path *include* pada `public_html/index.php` ke arah `../app/`.
3. **Tahap Basis Data:**
   * Buat basis data MySQL dan akun pengguna via cPanel Database Wizard.
   * Impor skema tabel SQL via phpMyAdmin.
   * Perbarui kredensial koneksi pada berkas `app/Config/Database.php`.