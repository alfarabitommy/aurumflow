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
│   │   ├── AuthController.php        # Login token, pendaftaran privat, verifikasi waiver
│   │   ├── TellerController.php      # Kasir meja hulu (timbang & bayar tunai)
│   │   ├── SettlementController.php  # Likuidasi toko, penutupan buku, mesin waterfall
│   │   ├── InvestorController.php    # Dasbor blind investor & permohonan penarikan
│   │   └── ExportController.php      # Ekspor ringkasan kas (CSV / Cetak Ramah Kertas)
│   ├── Helpers/
│   │   ├── Security.php              # Sanitasi input, generator token CSRF, hashing
│   │   ├── Formatter.php             # Format mata uang Rupiah & format 3 desimal gram
│   │   └── AllocationEngine.php      # Fungsi matematika Waterfall & Pro-Rata harian
│   └── Views/
│       ├── layouts/                  # Kerangka utama HTML (Header, Dark Theme, Script)
│       ├── auth/                     # Halaman login privat & lembar edukasi/waiver
│       ├── operator/                 # Meja kasir, form nota toko emas, konfigurasi modal
│       └── investor/                 # Dasbor tertutup investor (blind view)
├── public/                           # FOLDER PUBLIK (Diarahkan ke public_html)
│   ├── assets/
│   │   ├── css/
│   │   │   └── app.min.css           # Berkas CSS hasil kompilasi Tailwind CLI
│   │   ├── js/
│   │   │   ├── alpine.min.js         # Library Alpine.js
│   │   │   └── app.js                # Inisialisasi PWA & helper kompresi foto nota
│   │   └── icons/                    # Ikon aplikasi PWA (192x192, 512x512)
│   ├── uploads/
│   │   ├── receipts/                 # Direktori penyimpanan foto nota toko emas
│   │   └── .htaccess                 # Memblokir eksekusi skrip PHP di folder upload
│   ├── .htaccess                     # URL Rewriting menuju index.php
│   ├── favicon.ico
│   ├── index.php                     # Front Controller (Single Entry Point)
│   ├── manifest.json                 # Metadata PWA
│   └── sw.js                         # Service Worker PWA
└── tailwind.config.js                # Konfigurasi Tailwind lokal untuk kompilasi
```

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

### 4.3. Keamanan Direktori Unggahan (*Uploads Hardening*)
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