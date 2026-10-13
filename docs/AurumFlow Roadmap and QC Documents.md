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
[FASE 6A] Modul Dana Talangan (Bridge Liquidity)
    │
    ▼
[FASE 6B] Uji Simulasi End-to-End & Deployment Shared Hosting
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
✓ Catatan Fase 4     : sejak settlement memanggil AllocationEngine, baris `profit_distributions`
                       boleh muncul — tetapi hanya untuk dompet dengan modal terserap, dan pada
                       hari minus seluruh nilainya 0.00 (modal pokok tetap tidak tersentuh)
✓ Catatan Sistem     : Kolom carryover_deficit pada batch tercatat Rp 80.000
✓ Settlement Esoknya : Otomatis memotong Rp 80.000 sebelum laba baru dibagi 80:20
```

---

### FASE 4: Mesin Alokasi Modal (Waterfall & Pro-Rata Engine) & Tata Kelola Likuiditas
**Fokus:** Distribusi beban serapan modal belanja ke masing-masing dompet, proteksi kuota prioritas pengelola (50%), distribusi pool dividen 20% secara pro-rata dengan presisi sen, pengelolaan setoran/penarikan kas modal, serta mekanisme penarikan dana (*notice period* H-7 hari kerja).

**Terminologi (Fase 4).** Nama tier pada basis data tidak berubah; yang dipakai di antarmuka dan dokumen adalah label berikut:

| Nilai DB (`capital_wallets.tier`) | Label dokumen & UI | Peran | Jatah pool 20% |
| :--- | :--- | :--- | :--- |
| `operator` | **Pengelola** | Diserap pertama sebesar 50% | Tidak menerima |
| `family` | **Mitra Prioritas** | Kuota serapan terkunci per dompet | Ya, pro-rata modal bekerja |
| `external` | **Mitra Reguler** | Pro-rata saldo atas sisa kebutuhan | Ya, pro-rata modal bekerja |

#### Daftar Tugas Teknis (Checklist)
* [ ] Modul tata kelola modal di panel Pengelola (`/operator/capital`): form **Setor Modal Baru**, **Pindah Kas** (Brankas Utama → Kas Meja Bakar), tabel portofolio + isian kuota terkunci (hanya Mitra Prioritas), **What-If Simulator**, dan **Antrean Penarikan**.
* [ ] Setoran modal atomik: satu transaksi menambah `capital_wallets.balance_capital` **dan** saldo `cash_registers` (Brankas Utama) serta menulis `cash_mutations` (`in`).
* [ ] Algoritma alokasi bertingkat (*Waterfall Allocation Algorithm*) — **seluruhnya dalam sen integer, tanpa float**:
  1. Serap kas belanja dari dompet Pengelola sebesar **50%** (`system_settings.operator_base_quota_pct`).
  2. Serap dari **setiap** dompet Mitra Prioritas sebesar kuota terkuncinya (`locked_quota_pct`).
  3. Bagikan sisa kebutuhan secara **pro-rata saldo** ke Mitra Reguler (metode sisa terbesar).
  4. Laporkan **sisa tak terserap** bila saldo seluruh dompet tidak mencukupi (tidak ada penyerapan melebihi saldo dompet).
* [ ] Distribusi dividen: pool 20% dibagikan pro-rata atas modal mitra yang benar-benar bekerja, dengan **metode sisa terbesar** sehingga `Σ profit_earned = investor_pool_20` presisi tanpa selisih sen; dompet Pengelola dikecualikan dari pool tetapi tetap dicatat serapannya (`profit_earned = 0.00`).
* [ ] Fitur pengajuan penarikan dana (`withdrawal_requests`) dengan validasi tanggal jatuh tempo otomatis (**+7 hari kerja**, Sabtu/Minggu dilewati) dan eksekusi bayar atomik (kas keluar Brankas Utama + mutasi + saldo dompet turun + status `completed`).

#### Protokol QC Manual 4: Verifikasi Logika Serapan Kas, Bagi Hasil, & Tata Kelola Modal

Daftarkan empat dompet pengujian ke tabel `capital_wallets`:

```
[DOMBET UJI]
• Pengelola        (tier 'operator'): Saldo Rp 30.000.000 — kuota 50% (dari system_settings)
• Mitra Prioritas  (tier 'family')  : Saldo Rp 16.000.000 — kuota terkunci 20%
• Mitra Reguler A  (tier 'external'): Saldo Rp 20.000.000
• Mitra Reguler B  (tier 'external'): Saldo Rp 14.000.000
• Total Pool Tersedia               : Rp 80.000.000
```

Jalankan pengujian serapan untuk kebutuhan kas belanja sebesar **Rp 35.157.500** (data belanja QC 2) dan distribusi pool mitra **Rp 437.300** (dari QC 3). Angka pembanding yang otoritatif adalah nilai **sen**; angka Rupiah hanyalah tampilan.

```
[HASIL SERAPAN MODAL YANG WAJIB DIHITUNG SISTEM]
✓ Serapan Modal Pengelola (50%)      : 50% × Rp 35.157.500 = Rp 17.578.750  (1757875000 sen)
✓ Serapan Modal Mitra Prioritas (20%) : 20% × Rp 35.157.500 = Rp  7.031.500  (703150000 sen)
✓ Sisa Kebutuhan Kas (30%)           : Rp 10.547.250 (Dialihkan ke Mitra Reguler)
✓ Σ modal terserap                   : Rp 35.157.500 (persis sama dengan belanja)
✓ Sisa tak terserap                  : Rp 0

[DISTRIBUSI PRO-RATA MITRA REGULER (SISA 30% = Rp 10.547.250)]
• Rasio A : 20 Juta / (20 Juta + 14 Juta) = 10/17
  ↳ Modal A terpakai : Rp 6.204.264,71  (620426471 sen)
• Rasio B : sisa pembagian
  ↳ Modal B terpakai : Rp 4.342.985,29  (434298529 sen)
• Kedua nilai berjumlah PERSIS Rp 10.547.250 (metode sisa terbesar)

[DISTRIBUSI DIVIDEN POOL MITRA (TOTAL: Rp 437.300 = 43730000 sen)]
Basis = total modal mitra yang bekerja = Rp 7.031.500 + Rp 6.204.264,71 + Rp 4.342.985,29
       = Rp 17.578.750 (1757875000 sen)
• Mitra Prioritas (porsi 40,0000%) : Rp   174.920     (17492000 sen)
• Mitra Reguler A (porsi 35,2941%) : Rp   154.341,18  (15434118 sen)
• Mitra Reguler B (porsi 24,7059%) : Rp   108.038,82  (10803882 sen)
  ↳ ditampilkan pembulatan rupiah: Rp 154.341 dan Rp 108.039
• Pengelola                        : Rp 0 (modal pengelola tidak mengambil jatah pool)
✓ Σ terdistribusi : Rp 437.300 presisi tanpa selisih sen (= investor_pool_20)
```

> **Koreksi angka (Fase 4).** Tabel protokol ini sebelumnya memakai persentase yang sudah dibulatkan
> (40,00% / 35,29% / 24,71%), sehingga nilai Rupiahnya (Rp 154.339 dan Rp 108.041) berbeda ± Rp 2 dari
> hasil hitung yang benar. Angka **sen** di atas adalah acuan; pembagian wajib memakai metode sisa
> terbesar agar tidak ada sen yang hilang.

```
[SKENARIO 4.2: TATA KELOLA KAS MODAL]
✓ Setor Modal Baru Rp 10.000.000 → Brankas +10.000.000, balance_capital mitra +10.000.000,
                                   1 baris cash_mutations ('in'), 1 transaksi basis data
✓ Pindah Kas Rp 5.000.000        → Brankas −5.000.000, Kas Meja Bakar +5.000.000,
                                   2 baris cash_mutations ('out' & 'in'), 1 transaksi basis data
✓ Kuota Mitra Prioritas diubah 20 → locked_quota_pct = 20.00; dompet Mitra Reguler tetap 0.00

[SKENARIO 4.3: PENARIKAN DANA (H-7 HARI KERJA)]
✓ Pengajuan penarikan modal pokok tanggal Jumat → jatuh tempo = hari kerja ke-7 berikutnya
  (Sabtu & Minggu dilewati)
✓ Tombol "Bayar" → 422 "belum jatuh tempo" + nol tulisan (diuji lewat HTTP)
✓ Setelah jatuh tempo & disetujui → balance_capital dompet turun, Brankas −nominal,
  1 mutasi 'out', status permohonan 'completed' — semuanya dalam satu transaksi
✓ Nominal melebihi saldo dompet atau saldo kas brankas → 422 tanpa tulisan

[SKENARIO 4.4: HARI MINUS & MODAL MITRA]
✓ Settlement dengan laba bersih ≤ 0 → investor_pool_20 = 0.00
✓ profit_distributions bernilai 0.00 (serapan tetap tercatat), balance_profit TIDAK bertambah
✓ balance_capital mitra TIDAK pernah berkurang pada skenario apa pun
```

---

### FASE 5: Dasbor Blind Investor, Legal Waiver, & PWA
**Fokus:** Gerbang pendaftaran privat berbasis token, pencatatan persetujuan hukum digital (*clickwrap waiver*), pembatasan akses data (*blind security*), dan konfigurasi PWA.

#### Daftar Tugas Teknis (Checklist)
* [ ] Modul buat akun investor di panel Admin dengan pembuatan tautan unik `?token=<64 heksadesimal>`; token terbit otomatis saat mitra dibuat dan dapat diputar ulang oleh operator ("Buat tautan" / "Buat ulang tautan") di `/operator/capital`.
* [ ] Halaman onboarding privat: penjelasan alur bisnis, siklus T+1, kotak syarat pembebasan tanggung jawab hukum (*waiver*), dan penetapan PIN 6 digit.
* [ ] Mekanisme penguncian audit hukum saat investor mencentang persetujuan (merekam: waktu WIB, alamat IP, dan *User Agent* peramban) — satu transaksi, lalu token dihanguskan (sekali pakai).
* [ ] Form pembuatan PIN enam digit (menolak PIN lemah) dan pintu login `/investor/login` dengan pembatasan percobaan 5 kegagalan → kunci 15 menit.
* [ ] Antarmuka dasbor investor bergaya *Dark Obsidian Luxury*: kartu portofolio, rasio modal aktif vs mengendap (`capital_utilized` terakhir, dibatasi saldo modal), dividen bulan berjalan, dan ROI.
* [ ] Pengamanan isolasi data: blokir akses API investor dari data omzet kotor, nama penambang, gramatur, laba 80% pengelola, kuota serapan, dan dompet mitra lain — termasuk pada ekspor.
* [ ] Pengajuan penarikan mandiri mitra: "Tarik Keuntungan" (jatuh tempo hari itu) dan "Tarik Modal" (H-7 hari kerja), keduanya hanya mengajukan — pencairan tetap milik operator.
* [ ] Fitur ekspor laporan keuangan sederhana (laporan ramah cetak + unduhan CSV).
* [ ] Konfigurasi `manifest.json` dan *Service Worker* dasar (hanya cache aset statis; HTML/CSV tidak pernah di-cache) untuk instalasi aplikasi di layar ponsel.

#### Protokol QC Manual 5: Pengujian Privasi & Keamanan (Security Audit)
Buka sesi penyamaran (*incognito browser*), lalu masuk menggunakan akun Investor A melalui `/investor/login` (nomor telepon + PIN 6 digit):

| Skenario Uji Privasi | Titik Pengujian | Hasil yang Diharapkan (Expected Outcome) | Status |
| :--- | :--- | :--- | :---: |
| **Audit Jejak Hukum** | Cek tabel `users` setelah menyetujui syarat | `onboarding_signed_at` (waktu WIB), `signed_ip_address` (`127.0.0.1` pada harness), dan `signed_user_agent` (UA kiriman) terisi; `invite_token` menjadi `NULL`. | [ ] |
| **Tautan Sekali Pakai** | Buka ulang `/join` dengan token yang sudah dipakai | Halaman "tautan tidak berlaku"; **nol** tulisan baru pada `users`. | [ ] |
| **PIN Lemah Ditolak** | Isi PIN `111111`, `123456`, `654321`, `123123` | `422` + pesan alasan; tidak ada satu pun tulisan. | [ ] |
| **Pembatasan Percobaan** | 5× PIN salah dalam 15 menit | Percobaan ke-5 dijawab `429` dan terkunci 15 menit; PIN benar tetap ditolak selama kunci. | [ ] |
| **Isolasi Laba Pengelola** | Buka halaman Inspect Element / Network Tab | Tidak ditemukan angka pendapatan 80% milik pengelola (`operator_share_80`), omzet toko, gramatur, maupun jumlah nota. | [ ] |
| **Isolasi Investor Lain** | Periksa tampilan dasbor Investor A | Hanya memunculkan saldo modal miliknya (Rp 20 Juta), tanpa ada nama/saldo Investor B atau Mitra Prioritas — berlaku juga untuk laporan cetak dan CSV. | [ ] |
| **Akses URL Kasir Ilegal** | Buka URL `/operator/teller` dan `/dashboard` secara langsung | Sistem otomatis menolak akses dan mengarahkan kembali (*redirect*) ke `/investor/dashboard`, tanpa satu pun tulisan. | [ ] |
| **Pintu PIN Terpisah** | Coba PIN di `/login` dan kata sandi di `/investor/login` | Keduanya gagal dengan pesan generik — PIN tidak pernah sah di pintu kata sandi. | [ ] |
| **Tarik Modal H-7** | Ajukan "Tarik Modal" hari Jumat | `notice_period_due` = hari kerja ke-7 (Sabtu/Minggu dilewati); dompet ditandai `queued`; pengajuan modal kedua ditolak. | [ ] |
| **Ekspor Ramah Awam** | Buka `/investor/report` dan `/investor/report/csv` | Laporan ramah cetak terbuka & siap dicetak; CSV terunduh (`text/csv`, BOM, pemisah `;`) dan hanya memuat angka mitra sendiri. | [ ] |
| **PWA Installability** | Buka peramban di ponsel pintar | Muncul tombol peramban untuk *Tambahkan ke Layar Utama* (*Add to Home Screen*); `manifest.json` valid dan ikon 192/512 tersedia. | [ ] |

> **Catatan angka Protokol 5 (Fase 5).** Angka acuan dasbor memakai dompet uji yang sama dengan
> Protokol 4 agar tidak ada dua sumber kebenaran: `balance_capital` **Rp 20.000.000**,
> `capital_utilized` terakhir **Rp 6.204.264,71** → "Modal Berputar" **Rp 6.204.265**, "Kas
> Cadangan" **Rp 13.795.735**, rasio **31,02 %**; klamp diuji dengan `capital_utilized` yang
> melebihi saldo modal. Angka tersebut dihasilkan otomatis oleh `tests/qc_fase_5.php`.

---

### FASE 6A: Modul Dana Talangan (*Bridge Liquidity*)
**Fokus:** Menutup celah likuiditas malam — kas Meja Bakar habis, pengelola meminjam kas tunai dari pihak luar (tanpa akun), lalu mengembalikan pokok + fee pada tutup buku siang berikutnya (T+1) **tanpa** menyentuh pool 20 % mitra.

#### Daftar Tugas Teknis (Checklist)
* [x] Tabel baru `bridge_loans` (nama pemberi dana, pokok, bentuk fee `pct`/`fixed`, `fee_amount`, `fee_absorbed`, `fee_uncovered`, status, `settlement_id`, pos kas penerima) — **nol ALTER** pada sembilan tabel lama.
* [x] Tombol + modal **"Terima Dana Talangan"** di `/operator/teller` (Layar B2): satu transaksi kas (`bridge_loans` + saldo Kas Meja Bakar + `cash_mutations` `in`).
* [x] Fee dihitung **peladen**: bentuk **Nominal (Rp)** atau **Persen (%)**, aritmetika sen integer dengan pembulatan *half away from zero*; nominal kiriman peramban tidak dipercaya.
* [x] Kartu **"Talangan Aktif & Beban Fee"** pada `/operator/settlement` (Layar C) beserta baris "Hak pengelola bersih" (`operator_share_80` − fee).
* [x] Auto-pay di dalam `SettlementService::settle()`: pengembalian pokok + fee, satu mutasi kas `out` per talangan, baris talangan menjadi `settled` + `settlement_id`.
* [x] Fee diserap **eksklusif** dari `operator_share_80`; `investor_pool_20`, Σ `profit_earned`, dan `balance_profit` setiap dompet **tidak berubah** (dibuktikan assertion diferensial, bukan asumsi).
* [x] Fee melebihi hak pengelola (mis. hari minus) → `fee_uncovered` dibayar dari kas kerja pengelola; tutup buku **tidak** diblokir dan rumus `carryover_deficit` tidak berubah.
* [x] Talangan yang belum lunas tetap `active` dan terbayar pada tutup buku berikutnya (urutan FIFO: `created_at ASC, id ASC`).
* [x] Penjagaan saldo: bila kas meja setelah alokasi tidak cukup menutup pengembalian → `422` tanpa satu pun tulisan.
* [x] Isolasi privasi diperluas: `bridge_loans`, `BridgeLoan`, dan istilah "talangan" masuk daftar terlarang berkas investor.

#### Protokol QC Manual 6A: Pengujian Dana Talangan (*Bridge Liquidity*)
Jalankan pada lingkungan lokal dengan angka baku QC 3 (emas *holding* 18,050 gr, modal belanja **Rp 35.157.500**, pendapatan toko **Rp 37.544.000**, laba bersih **Rp 2.186.500**, pengelola **Rp 1.749.200**, pool **Rp 437.300**):

| Parameter Uji | Data Masukan | Hasil yang Diharapkan | Status |
| :--- | :--- | :--- | :---: |
| **Terima Talangan (nominal)** | Pemberi dana `Pak Haji Daud`, pokok `10.000.000`, bentuk **Nominal**, fee `100.000` | Kas Meja Bakar **+Rp 10.000.000**; 1 baris `bridge_loans` `status='active'`, `fee_amount = 100000.00`; 1 mutasi `in` berlabel `Talangan dari Pak Haji Daud` | [x] |
| **Terima Talangan (persen)** | Pokok `10.000.000`, bentuk **Persen**, tarif `1,00` | `fee_amount = 100000.00` — dihitung peladen, identik dengan uji nominal | [x] |
| **Nominal tidak dipercaya** | Kirim `fee_amount=1` (palsu) bersama tarif `1,00` | Yang tersimpan tetap **Rp 100.000**; nilai palsu tidak berjejak di basis data | [x] |
| **Penjagaan isian** | Nama kosong / pokok `0` / tarif `0` / tarif `120` / fee negatif | `422` + pesan; **nol** tulisan pada `bridge_loans`, `cash_registers`, `cash_mutations` | [x] |
| **Auto-pay tutup buku** | Settlement 18,050 gr @ `2.080.000`, beban aparat `200.000`, talangan di atas aktif | Kas Meja Bakar **turun Rp 10.100.000**; talangan `settled` + `settlement_id`; 1 mutasi `out` `Kembali talangan: Pak Haji Daud`; `fee_absorbed = 100000.00`, `fee_uncovered = 0.00` | [x] |
| **Isolasi pool mitra** | Bandingkan sesi tanpa talangan vs sesi dengan talangan | `operator_share_80` tetap **Rp 1.749.200** (hak bersih **Rp 1.649.200**); `investor_pool_20` tetap **Rp 437.300**; Σ `profit_earned` tetap **Rp 437.300**; kenaikan `balance_profit` **setiap** dompet identik | [x] |
| **Fee melebihi hak pengelola** | Settlement dengan hak pengelola < fee | Fee diserap maksimal sebesar hak pengelola; sisanya `fee_uncovered` + `Logger::warning`; pool tetap 20 % laba bersih; pokok + fee tetap dibayar penuh | [x] |
| **Hari minus** | Settlement rugi dengan talangan aktif | `investor_pool_20 = 0.00`, tak ada `balance_profit` bertambah, `carryover_deficit` sesuai rumus lama; `fee_absorbed = 0.00`, `fee_uncovered` = fee penuh | [x] |
| **FIFO & talangan pasca-settlement** | (a) dua talangan aktif; (b) talangan baru setelah tutup buku | (a) keduanya `settled` berurutan FIFO, Σ `fee_absorbed` = min(Σ fee, hak pengelola); (b) talangan baru tetap `active` | [x] |
| **Kas tidak cukup** | Alokasi kas manual terlalu kecil | `422` + pesan menyebut kekurangan; **nol** tulisan; talangan tetap `active` | [x] |
| **Isolasi permukaan investor** | `/investor/dashboard`, `/investor/report`, `/investor/report/csv` | Tidak ada kata "talangan" maupun angka pokok/fee talangan pada body/CSV | [x] |

> **Catatan angka Protokol 6A.** Seluruh angka tabel di atas dihasilkan otomatis oleh
> `tests/qc_fase_6.php` (grup A–H) sehingga dokumen ini tidak memiliki dua sumber kebenaran.
> Pengembalian dibayar dari pos kas `Kas Meja Bakar` — pos yang sama dengan tempat dana masuk.

---

### FASE 6B: Uji Operasional Menyeluruh & Deployment Shared Hosting
**Fokus:** Pengujian simulasi end-to-end tanpa jeda selama tiga siklus harian, kompilasi aset produksi, serta unggah berkas ke peladen *shared hosting*.

#### Daftar Tugas Teknis (Checklist)
* [ ] Eksekusi simulasi perputaran kas tiga hari berturut-turut pada lingkungan lokal (Hulu $\rightarrow$ Hilir $\rightarrow$ Settlement $\rightarrow$ Mutasi Saldo), **termasuk minimal satu siklus dana talangan** (terima malam, kembali pada tutup buku siang).
* [ ] Eksekusi kompilasi Tailwind CSS mode produksi (`--minify`) untuk menghasilkan berkas `app.min.css`.
* [ ] Persiapan direktori cPanel: letakkan folder `app/` di luar `public_html`, dan isi folder `public/` ke dalam `public_html`.
* [ ] Penyesuaian path berkas `public_html/index.php` menuju direktori inti `../app/`.
* [ ] Pembuatan basis data MySQL dan akun basis data di cPanel MySQL Database Wizard.
* [ ] Impor skema tabel final via phpMyAdmin cPanel (10 tabel, termasuk `bridge_loans`) dan perbarui berkas konfigurasi `app/Config/Database.php`.
* [ ] Verifikasi konfigurasi `.htaccess` pada folder unggahan nota untuk mematikan eksekusi skrip PHP berbahaya.

#### Protokol QC Manual 6B: Checklist Akhir di Server Produksi
Lakukan verifikasi berikut langsung pada domain/subdomain aktif:

* [ ] Akses halaman utama: form login muncul dengan latar hitam pekat tanpa eror 500 (*Internal Server Error*).
* [ ] Jalankan satu transaksi uji hulu di kasir: waktu respons input < 500 milidetik pada jaringan seluler.
* [ ] Catat satu dana talangan uji dari kasir (`/operator/teller` → **Terima Dana Talangan**), lalu jalankan tutup buku: pokok + fee kembali, dan hak pengelola pada layar rekonsiliasi turun tepat sebesar fee sementara pool mitra tidak berubah.
* [ ] Ambil foto menggunakan kamera ponsel untuk nota toko: gambar otomatis terunggah ke folder `/uploads/receipts/` dan terkompresi di bawah 250 KB.
* [ ] Pastikan berkas PHP di dalam `/uploads/receipts/test.php` tidak dapat dieksekusi oleh peramban (muncul eror 403 *Forbidden*).
* [ ] Buka tautan dasbor investor dari ponsel: tampilan *Dark Obsidian* tampil rapi dan proporsional tanpa pergeseran tata letak horizontal (*zero horizontal overflow*), dan **tidak** memuat istilah "talangan".

---

## 3. Matriks Estimasi Waktu & Urutan Pengerjaan

| Tahapan Kerja | Estimasi Durasi | Target Capaian Utama |
| :--- | :---: | :--- |
| **Sprint 1 (Fase 1)** | 2 – 3 Hari | Struktur direktori *native*, koneksi PDO, migrasi tabel basis data, dan helper keamanan. |
| **Sprint 2 (Fase 2)** | 3 – 4 Hari | Meja kasir hulu responsif, kalkulasi potong jasa bakar, dan mutasi kas meja. |
| **Sprint 3 (Fase 3)** | 3 – 4 Hari | Modul rekonsiliasi toko, unggah nota terkompresi, dan mesin *settlement* harian. |
| **Sprint 4 (Fase 4)** | 3 – 4 Hari | Mesin alokasi *waterfall* (prioritas 50%), kalkulasi pro-rata, dan alur penarikan H-7. |
| **Sprint 5 (Fase 5)** | 4 – 5 Hari | Dasbor *blind investor*, lembar *waiver* hukum, autentikasi PIN, dan integrasi PWA. |
| **Sprint 6 (Fase 6A)** | 1 – 2 Hari | Modul dana talangan: tabel `bridge_loans`, modal penerimaan di kasir, auto-pay fee pada tutup buku, dan isolasi pool mitra. |
| **Sprint 6 (Fase 6B)** | 2 – 3 Hari | Simulasi perputaran tiga hari (termasuk satu siklus talangan), *hardening* keamanan cPanel, dan peluncuran produksi (*go-live*). |
| **Total Estimasi** | **~18 – 23 Hari** | **Sistem Siap Operasi Penuh di Lapangan** |