# DOKUMEN ROADMAP PENGEMBANGAN TEKNIS & PROTOKOL QC
**Nama Proyek:** AurumFlow  
**Versi:** 1.0.0  
**Metodologi:** Sprint-Based Incremental Delivery (6 Tahapan)  
**Target Rilis:** Siap Operasional Lapangan (Production Ready)  

---

## 1. Ikhtisar & Strategi Eksekusi

Roadmap ini membagi pembangunan sistem AurumFlow ke dalam enam fase terstruktur. Setiap tahapan diakhiri dengan **Uji Kendali Mutu (Quality Control / QC) Manual**. 

Parameter data uji (*test data*) pada setiap fase telah disiapkan secara eksplisit di bawah ini, lengkap dengan angka input dan hasil kalkulasi yang diharapkan (*expected output*). Masukkan angka tersebut ke sistem untuk memverifikasi apakah logika program sudah bekerja dengan sempurna sebelum melangkah ke fase berikutnya.

---

## 2. Peta Jalan Pengembangan (Phase by Phase)

```
[FASE 1] Fondasi Sistem & Basis Data
    │
    ▼
[FASE 2] Meja Kasir Hulu & Arus Kas Lapangan
    │
    ▼
[FASE 3] Likuidasi Toko & Mesin Settlement Harian
    │
    ▼
[FASE 4] Waterfall Engine & Tata Kelola Modal
    │
    ▼
[FASE 5] Blind Investor Portal, Legal Waiver, & PWA
    │
    ▼
[FASE 6] Uji Simulasi End-to-End & Deployment Shared Hosting
```

---

### FASE 1: Fondasi Sistem, Basis Data, & Arsitektur Inti
**Fokus:** Menyiapkan struktur direktori *native modern*, koneksi basis data aman (PDO), migrasi tabel, serta pustaka fungsi bantu (*helper*).

#### Daftar Tugas Teknis (Checklist)
* [ ] Konfigurasi direktori proyek sesuai rancangan SAD (folder `app/` terpisah dari `public/`).
* [ ] Inisialisasi koneksi PDO MySQL dengan penanganan eror berbasis *exception* dan mode *prepared statements* bawaan.
* [ ] Eksekusi skrip skema SQL (`aurumflow_erd_sql.md`) ke basis data lokal/pengujian.
* [ ] Pembuatan berkas router sederhana dan *Front Controller* (`public/index.php`).
* [ ] Implementasi pustaka keamanan: sanitasi XSS, fungsi *hash* sandi, dan validasi token CSRF.
* [ ] Pembuatan fungsi pemformatan: konversi mata uang Rupiah (`Rp XX.XXX.XXX`) dan pembulatan desimal timbangan (tiga digit di belakang koma).

#### Protokol QC Manual 1: Integritas Basis Data & Helper
Jalankan skrip pengujian atau panggil fungsi *helper* melalui peramban:

| Parameter Uji | Data Masukan (Input) | Hasil yang Diharapkan (Expected Output) | Status |
| :--- | :--- | :--- | :---: |
| **Koneksi PDO** | Inisialisasi koneksi `Database::getConnection()` | Objek PDO aktif, tidak ada galat koneksi. | [ ] |
| **Pencegahan SQL Injection** | Kueri pencarian dengan input karakter berbahaya: `' OR '1'='1` | Karakter lolos sebagai string literal, kueri aman. | [ ] |
| **Format Gramatur** | Nilai mentah: `14.85` | Terformat menjadi string: `14,850 gr`. | [ ] |
| **Format Rupiah** | Nilai mentah: `28937500` | Terformat menjadi string: `Rp 28.937.500`. | [ ] |
| **Seed Konfigurasi** | Pembacaan tabel `system_settings` | Kunci `operator_base_quota_pct` terbaca bernilai `50.00`. | [ ] |

---

### FASE 2: Meja Kasir Hulu & Manajemen Kas Lapangan
**Fokus:** Membangun antarmuka kasir meja bakar malam hari, kalkulasi harga tebus otomatis, pemotongan jasa bakar flat, dan pencatatan mutasi kas keluar.

#### Daftar Tugas Teknis (Checklist)
* [ ] Pembuatan antarmuka kasir hulu dengan Tailwind CSS (tema gelap kontras tinggi) dan reaktivitas Alpine.js.
* [ ] Implementasi kalkulasi instan di sisi klien: $\text{Net Pay} = (\text{Berat Kering} \times \text{Harga Tebus}) - \text{Jasa Bakar}$.
* [ ] Komponen tombol geser (*swipe to confirm*) untuk konfirmasi pembayaran tunai.
* [ ] Logika transaksi atomik PDO (`beginTransaction` / `commit`):
  * Masukkan baris baru ke tabel `hulu_transactions` (status: `holding`).
  * Kurangi saldo pada tabel `cash_registers` (Kas Meja Bakar).
  * Catat riwayat kas ke tabel `cash_mutations`.

#### Protokol QC Manual 2: Transaksi Meja Kasir Malam
Isi saldo awal Kas Meja Bakar melalui phpMyAdmin/form sebesar **Rp 50.000.000**, lalu input dua transaksi berikut secara berurutan:

```
[DATA TRANSAKSI QC 2.1]
• Alias Petani     : Pak Anto
• Berat Kering     : 14,850 gr
• Harga Tebus Acuan: Rp 1.950.000 / gr
• Jasa Bakar Flat  : Rp 20.000 (Aktif)

[HASIL YANG WAJIB MUNCUL PADA QC 2.1]
✓ Nilai Tebus Kotor : 14,850 × Rp 1.950.000 = Rp 28.957.500
✓ Kas Keluar Bersih : Rp 28.957.500 – Rp 20.000 = Rp 28.937.500
✓ Alokasi Jasa Bakar: Rp 10.000 (Upah Pengelola) & Rp 10.000 (Penggantian Kowi/Gas)
✓ Sisa Kas Meja     : Rp 50.000.000 – Rp 28.937.500 = Rp 21.062.500
✓ Status Data       : Tersimpan di tabel 'hulu_transactions' dengan status 'holding'
```

```
[DATA TRANSAKSI QC 2.2]
• Alias Petani     : Petani B-02
• Berat Kering     : 3,200 gr
• Harga Tebus Acuan: Rp 1.950.000 / gr
• Jasa Bakar Flat  : Rp 20.000 (Aktif)

[HASIL YANG WAJIB MUNCUL PADA QC 2.2]
✓ Nilai Tebus Kotor : 3,200 × Rp 1.950.000 = Rp 6.240.000
✓ Kas Keluar Bersih : Rp 6.240.000 – Rp 20.000 = Rp 6.220.000
✓ Sisa Kas Meja     : Rp 21.062.500 – Rp 6.220.000 = Rp 14.842.500
✓ Akumulasi Emas    : Total stok berstatus 'holding' menjadi 18,050 gr
```

---

### FASE 3: Likuidasi Toko Emas & Mesin Settlement Harian
**Fokus:** Penjualan emas padu ke toko langganan, kompresi foto nota, penutupan buku (*closing*), penanganan biaya aparat, serta mitigasi hari minus (*loss carry-over*).

#### Daftar Tugas Teknis (Checklist)
* [ ] Antarmuka input hasil penjualan toko emas (bobot riil toko, kadar timbang air, dan harga per gram).
* [ ] Skrip kompresi gambar berbasis HTML5 Canvas di sisi klien (memastikan ukuran nota < 250 KB sebelum unggah).
* [ ] Logika perhitungan Laba Bersih: $\text{Pendapatan Toko} - \text{Total Modal Tebus} - \text{Biaya Aparat} - \text{Defisit Kemarin}$.
* [ ] Eksekusi pembagian hasil: alokasi 80% ke Pengelola dan 20% ke Pool Investor.
* [ ] Pembaruan status seluruh transaksi pembelian terkait dari `holding` menjadi `settled`.
* [ ] Logika penanganan hari minus: jika hasil perhitungan bernilai negatif, simpan selisih ke kolom `carryover_deficit` tanpa memotong saldo modal investor.

#### Protokol QC Manual 3: Simulasi Settlement
Gunakan akumulasi emas dari QC 2 (**18,050 gr** dengan modal belanja **Rp 35.157.500**), lalu lakukan uji coba dua skenario berikut:

```
[SKENARIO 3.1: SETTLEMENT NORMAL (CUAN BERJALAN)]
• Total Berat Dijual Toko : 18,050 gr
• Kadar Timbang Air Toko   : 93,50%
• Harga Jual Riil Toko     : Rp 2.080.000 / gr
• Beban Aparat / Lapangan  : Rp 200.000
• File Foto Nota           : Unggah satu foto sembarang (pastikan terkompresi)

[HASIL YANG WAJIB DIKELUARKAN SISTEM 3.1]
✓ Total Kas Masuk Toko     : 18,050 × Rp 2.080.000 = Rp 37.544.000
✓ Total Modal Belanja Hulu : Rp 28.937.500 + Rp 6.220.000 = Rp 35.157.500
✓ Laba Kotor Usaha         : Rp 37.544.000 – Rp 35.157.500 = Rp 2.386.500
✓ Laba Bersih Putaran      : Rp 2.386.500 – Rp 200.000 = Rp 2.186.500
✓ Hak Pengelola (80%)      : 80% × Rp 2.186.500 = Rp 1.749.200
✓ Pool Investor (20%)      : 20% × Rp 2.186.500 = Rp 437.300
✓ Status Transaksi Hulu    : Berubah serentak menjadi 'settled'
```

```
[SKENARIO 3.2: SETTLEMENT HARI MINUS (EDGE CASE DEFISIT)]
• Kondisi Masuk Hulu : 1,000 gr ditebus bersih Rp 1.930.000
• Penjualan Toko     : 1,000 gr terjual Rp 2.050.000 (Laba kotor: Rp 120.000)
• Potongan Aparat    : Rp 200.000

[HASIL YANG WAJIB DIKELUARKAN SISTEM 3.2]
✓ Hasil Bersih       : Rp 120.000 – Rp 200.000 = -Rp 80.000 (Minus / Defisit)
✓ Saldo Investor     : Tetap utuh (TIDAK terpotong sepeser pun)
✓ Catatan Sistem     : Kolom carryover_deficit pada batch tercatat Rp 80.000
✓ Settlement Esoknya : Otomatis memotong Rp 80.000 sebelum laba baru dibagi 80:20
```

---

### FASE 4: Mesin Alokasi Modal (Waterfall & Pro-Rata Engine)
**Fokus:** Distribusi beban serapan modal belanja ke masing-masing dompet, proteksi kuota prioritas pengelola (50%), serta mekanisme penarikan dana (*notice period* H-7).

#### Daftar Tugas Teknis (Checklist)
* [ ] Modul pengaturan modal di panel Pengelola (slider kuota serapan terkunci).
* [ ] Algoritma alokasi bertingkat (*Waterfall Allocation Algorithm*):
  1. Serap kas belanja dari dompet Pengelola sebesar 50%.
  2. Serap kas belanja dari dompet Kerabat sesuai persentase terkunci.
  3. Bagikan sisa kebutuhan belanja secara pro-rata ke investor umum.
* [ ] Distribusi dividen: membagikan porsi 20% pool mitra secara adil sesuai rasio modal yang terpakai pada putaran tersebut.
* [ ] Fitur pengajuan penarikan dana modal (*withdrawal request*) dengan validasi tanggal jatuh tempo otomatis (H+7 hari kerja).

#### Protokol QC Manual 4: Verifikasi Logika Serapan Kas & Bagi Hasil
Daftarkan empat dompet pengujian ke tabel `capital_wallets` dengan saldo:
* **Pengelola (Kamu):** Saldo Rp 30.000.000 (Batas Kuota: 50%)
* **Kerabat:** Saldo Rp 16.000.000 (Batas Kuota: 20%)
* **Investor A:** Saldo Rp 20.000.000 (Mitra Umum)
* **Investor B:** Saldo Rp 14.000.000 (Mitra Umum)
* **Total Pool Tersedia:** Rp 80.000.000

Jalankan pengujian serapan untuk kebutuhan kas belanja sebesar **Rp 35.157.500** (mengambil data belanja QC 2) dan dividen pool mitra **Rp 437.300** (dari QC 3):

```
[HASIL SERAPAN MODAL YANG WAJIB DIHITUNG SISTEM]
✓ Serapan Modal Pengelola (50%) : 50% × Rp 35.157.500 = Rp 17.578.750
✓ Serapan Modal Kerabat (20%)   : 20% × Rp 35.157.500 = Rp  7.031.500
✓ Sisa Kebutuhan Kas (30%)      : Rp 10.547.250 (Dialihkan ke Investor A & B)

[DISTRIBUSI PRO-RATA INVESTOR UMUM (SISA 30% = Rp 10.547.250)]
• Rasio Investor A : 20 Juta / (20 Juta + 14 Juta) = 58,82%
  ↳ Modal A Terpakai: 58,82% × Rp 10.547.250 = Rp 6.204.265
• Rasio Investor B : 14 Juta / (20 Juta + 14 Juta) = 41,18%
  ↳ Modal B Terpakai: 41,18% × Rp 10.547.250 = Rp 4.342.985

[DISTRIBUSI DIVIDEN POOL MITRA (TOTAL: Rp 437.300)]
Dihitung pro-rata berdasarkan modal mitra yang bekerja hari itu:
Total Modal Bekerja Mitra = Rp 7.031.500 + Rp 6.204.265 + Rp 4.342.985 = Rp 17.578.750
• Kerabat     (Porsi 40,00%) : 40,00% × Rp 437.300 = Rp 174.920
• Investor A  (Porsi 35,29%) : 35,29% × Rp 437.300 = Rp 154.339
• Investor B  (Porsi 24,71%) : 24,71% × Rp 437.300 = Rp 108.041
(Total terdistribusi: Rp 437.300 presisi tanpa selisih sen)
```

---

### FASE 5: Dasbor Blind Investor, Legal Waiver, & PWA
**Fokus:** Gerbang pendaftaran privat berbasis token, pencatatan persetujuan hukum digital (*clickwrap waiver*), pembatasan akses data (*blind security*), dan konfigurasi PWA.

#### Daftar Tugas Teknis (Checklist)
* [ ] Modul buat akun investor di panel Admin dengan pembuatan tautan unik `?token=UUID`.
* [ ] Halaman onboarding privat: penjelasan alur bisnis, siklus T+1, dan kotak syarat pembebasan tanggung jawab hukum (*waiver*).
* [ ] Mekanisme penguncian audit hukum saat investor mencentang persetujuan (merekam: waktu WIB, alamat IP, dan *User Agent* peramban).
* [ ] Form pembuatan PIN enam digit dan alur login sesi aman.
* [ ] Antarmuka dasbor investor bergaya *Dark Obsidian Luxury*: kartu portofolio, rasio modal aktif vs mengendap, dan estimasi ROI.
* [ ] Pengamanan isolasi data: blokir akses API investor dari data omzet kotor, nama petani, dan laba 80% pengelola.
* [ ] Fitur ekspor laporan keuangan sederhana (format ramah orang awam).
* [ ] Konfigurasi `manifest.json` dan *Service Worker* dasar untuk instalasi aplikasi di layar ponsel.

#### Protokol QC Manual 5: Pengujian Privasi & Keamanan (Security Audit)
Buka sesi penyamaran (*incognito browser*), lalu masuk menggunakan akun Investor A:

| Skenario Uji Privasi | Titik Pengujian | Hasil yang Diharapkan (Expected Outcome) | Status |
| :--- | :--- | :--- | :---: |
| **Audit Jejak Hukum** | Cek tabel `users` setelah menyetujui syarat | Kolom `onboarding_signed_at`, `signed_ip_address`, dan `signed_user_agent` terisi data valid. | [ ] |
| **Isolasi Laba Pengelola** | Buka halaman Inspect Element / Network Tab | Tidak ditemukan angka pendapatan 80% milik pengelola (`operator_share_80`). | [ ] |
| **Isolasi Investor Lain** | Periksa tampilan dasbor Investor A | Hanya memunculkan saldo modal miliknya (Rp 20 Juta), tanpa ada nama/saldo Investor B atau Kerabat. | [ ] |
| **Akses URL Kasir Ilegal** | Buka URL `/operator/teller` secara langsung | Sistem otomatis menolak akses dan mengarahkan kembali (*redirect*) ke `/investor/dashboard`. | [ ] |
| **PWA Installability** | Buka peramban di ponsel pintar | Muncul tombol peramban untuk *Tambahkan ke Layar Utama* (*Add to Home Screen*). | [ ] |

---

### FASE 6: Uji Operasional Menyeluruh & Deployment Shared Hosting
**Fokus:** Pengujian simulasi end-to-end tanpa jeda selama tiga siklus harian, kompilasi aset produksi, serta unggah berkas ke peladen *shared hosting*.

#### Daftar Tugas Teknis (Checklist)
* [ ] Eksekusi simulasi perputaran kas tiga hari berturut-turut pada lingkungan lokal (Hulu $\rightarrow$ Hilir $\rightarrow$ Settlement $\rightarrow$ Mutasi Saldo).
* [ ] Eksekusi kompilasi Tailwind CSS mode produksi (`--minify`) untuk menghasilkan berkas `app.min.css`.
* [ ] Persiapan direktori cPanel: letakkan folder `app/` di luar `public_html`, dan isi folder `public/` ke dalam `public_html`.
* [ ] Penyesuaian path berkas `public_html/index.php` menuju direktori inti `../app/`.
* [ ] Pembuatan basis data MySQL dan akun basis data di cPanel MySQL Database Wizard.
* [ ] Impor skema tabel final via phpMyAdmin cPanel dan perbarui berkas konfigurasi `app/Config/Database.php`.
* [ ] Verifikasi konfigurasi `.htaccess` pada folder unggahan nota untuk mematikan eksekusi skrip PHP berbahaya.

#### Protokol QC Manual 6: Checklist Akhir di Server Produksi
Lakukan verifikasi berikut langsung pada domain/subdomain aktif:

* [ ] Akses halaman utama: form login muncul dengan latar hitam pekat tanpa eror 500 (*Internal Server Error*).
* [ ] Jalankan satu transaksi uji hulu di kasir: waktu respons input < 500 milidetik pada jaringan seluler.
* [ ] Ambil foto menggunakan kamera ponsel untuk nota toko: gambar otomatis terunggah ke folder `/uploads/receipts/` dan terkompresi di bawah 250 KB.
* [ ] Pastikan berkas PHP di dalam `/uploads/receipts/test.php` tidak dapat dieksekusi oleh peramban (muncul eror 403 *Forbidden*).
* [ ] Buka tautan dasbor investor dari ponsel: tampilan *Dark Obsidian* tampil rapi dan proporsional tanpa pergeseran tata letak horizontal (*zero horizontal overflow*).

---

## 3. Matriks Estimasi Waktu & Urutan Pengerjaan

| Tahapan Kerja | Estimasi Durasi | Target Capaian Utama |
| :--- | :---: | :--- |
| **Sprint 1 (Fase 1)** | 2 – 3 Hari | Struktur direktori *native*, koneksi PDO, migrasi tabel basis data, dan helper keamanan. |
| **Sprint 2 (Fase 2)** | 3 – 4 Hari | Meja kasir hulu responsif, kalkulasi potong jasa bakar, dan mutasi kas meja. |
| **Sprint 3 (Fase 3)** | 3 – 4 Hari | Modul rekonsiliasi toko, unggah nota terkompresi, dan mesin *settlement* harian. |
| **Sprint 4 (Fase 4)** | 3 – 4 Hari | Mesin alokasi *waterfall* (prioritas 50%), kalkulasi pro-rata, dan alur penarikan H-7. |
| **Sprint 5 (Fase 5)** | 4 – 5 Hari | Dasbor *blind investor*, lembar *waiver* hukum, autentikasi PIN, dan integrasi PWA. |
| **Sprint 6 (Fase 6)** | 2 – 3 Hari | Simulasi perputaran tiga hari, *hardening* keamanan cPanel, dan peluncuran produksi (*go-live*). |
| **Total Estimasi** | **~18 – 23 Hari** | **Sistem Siap Operasi Penuh di Lapangan** |