# DOKUMEN SPESIFIKASI PERSYARATAN PRODUK (PRD)

**Nama Produk:** AurumFlow  
**Versi:** 3.0.0 (Spesifikasi Final Lapangan, Legalitas Digital, & Mesin Modal Dinamis)  
**Tipe Platform:** *Private Progressive Web Application* (PWA) — *Invite-Only*  
**Status:** Terkunci & Siap Masuk Tahap Desain Arsitektur (ERD, UI/UX, & Roadmap)  

---

## 1. Ringkasan Eksekutif & Batasan Privasi

### 1.1. Latar Belakang & Visi Produk
AurumFlow dirancang khusus untuk mengelola operasional harian perdagangan dan peleburan emas aluvial (emas dompeng) berbasis percepatan arus kas (*cash velocity*) dengan siklus pelunasan harian **T+1**:
* **Sore hingga Malam (Fase Hulu):** Penambang membawa amalgama mentah $\rightarrow$ proses pembakaran raksa secara tuntas $\rightarrow$ penimbangan berat kering (tanpa timbang air di depan penambang) $\rightarrow$ pemotongan biaya jasa bakar flat $\rightarrow$ pelunasan uang tunai langsung di meja.
* **Pagi Hari Berikutnya (Fase Hilir):** Emas padat dibawa ke toko emas rekanan $\rightarrow$ pengujian kadar riil dengan timbang air/densitas oleh toko $\rightarrow$ pencairan kas sesuai harga buka toko hari itu.
* **Siang Hari (Fase Settlement):** Pelaksanaan tutup buku harian (*daily closing*) $\rightarrow$ pencatatan laba kotor riil $\rightarrow$ pemotongan beban operasional rutin dan koordinasi aparat $\rightarrow$ penguncian bagi hasil dividen.

### 1.2. Kebijakan Privasi Tertutup (*Strict Privacy & Invite-Only*)
* **Tanpa Halaman Publik:** Portal web tidak menyediakan formulir pendaftaran terbuka (*self-signup*). Akses awal ke URL utama hanya menampilkan antarmuka otentikasi minimalis dengan input PIN/sandi.
* **Onboarding Berbasis Tautan Privat:** Akun mitra pemodal dibuatkan secara manual oleh Pengelola melalui panel konsol Admin. Sistem kemudian menerbitkan tautan unik ber-token (contoh: `aurumflow.id/join?token=xxxx`).
* **Isolasi Metrik Sensitif (*Blind Dashboard*):** Untuk mencegah gesekan sosial dan rasa iri, mitra pemodal dilarang keras melihat total omzet bulanan, akumulasi gramatur keseluruhan, margin laba kotor toko, identitas penambang hulu, maupun nominal laba bersih hak Pengelola ($80\%$).

---

## 2. Struktur Pengguna & Hak Akses (*Role-Based Permissions*)

| Fitur / Modul Operasional | Pengelola / Admin (Operator) | Mitra Pemodal (Investor) |
| :--- | :---: | :---: |
| Input Transaksi Beli Petani (Timbang Kering) | Penuh (CRUD) | Terblokir Total |
| Input Penjualan Toko Emas & Unggah Nota | Penuh (CRUD) | Terblokir Total |
| Eksekusi Tutup Buku Harian (*Daily Settlement*) | Ya | Tidak |
| Akses Pengaturan Kuota Serapan Modal (*Waterfall Engine*) | Ya | Tidak |
| Akses Pengaturan Kuota Kerabat Khusus | Ya | Tidak |
| Monitor Saldo Modal Pokok Sendiri | Ya | Ya (Milik Sendiri) |
| Monitor Status Modal (Aktif Berputar vs Mengendap) | Ya | Ya (Milik Sendiri) |
| Riwayat Imbal Hasil (Dividen) Pribadi | Ya | Ya (Milik Sendiri) |
| Pengajuan Penarikan Modal Pokok (*Notice Period H-7*) | Tidak | Ya |
| Ekspor Laporan Kas Sederhana (PDF/Excel) | Ya (Versi Lengkap) | Ya (Versi Ringkas) |

---

## 3. Logika Finansial & *Unit Economics*

### 3.1. Fleksibilitas Modal Kerja Dinamis (*Dynamic Capital Pool*)
* Sistem tidak mengunci batas nominal permodalan pada angka statis (seperti kelipatan kaku Rp 10.000.000).
* Setiap akun pemodal menyimpan saldo kas riil. Sistem secara otomatis menghitung persentase kepemilikan modal terhadap total *pool* mitra:
  $$\text{Rasio Modal Investor}_i = \frac{\text{Saldo Modal Investor}_i}{\sum \text{Saldo Modal Seluruh Mitra}}$$

### 3.2. Belanja Aset Tetap vs Perkakas Habis Pakai
* **CAPEX Permanen (Pengelola):** Seluruh perkakas permanen (bangunan semi-permanen, timbangan presisi 3 desimal, blender las oksigen-elpiji, perlengkapan timbang air, dan sistem sirkulasi udara) disediakan mandiri oleh Pengelola ($100\%$ *skin in the game*).
* **Jasa Bakar Flat per Kedatangan:**  
  Konsumsi bahan habis pakai (*fast-moving*) seperti mangkok kowi, boraks/pijer, dan gas tidak dihitung per gram, melainkan dipungut sebagai **Biaya Jasa Bakar Flat per Transaksi** (misal Rp 20.000 per kedatangan):
  * Rp 8.000 – Rp 10.000 dialokasikan untuk pemulihan biaya kowi dan gas.
  * Sisa Rp 10.000 – Rp 12.000 langsung menjadi hak kas tunai Pengelola sebagai upah kerja teknis harian (terpisah dari pembagian dividen modal).
  * Biaya ini dipotong langsung dari uang tebusan yang dibayarkan ke penambang.

### 3.3. Beban Koordinasi Lapangan & Keamanan (Aparat)
* Sistem mengalokasikan anggaran koordinasi lingkungan dan aparat (sebagai asumsi dasar: Rp 5.000.000 per bulan atau Rp 200.000 per hari kerja operasional).
* Beban ini diperlakukan sebagai faktor pengurang laba kotor harian sebelum dividen dihitung, sehingga secara otomatis ditanggung bersama secara proporsional oleh seluruh modal yang berputar pada hari tersebut.

### 3.4. Skema Dividen Laba Bersih
* **$80\%$ Laba Bersih:** Hak penuh Pengelola (menanggung beban risiko fisik, kerja malam, fluktuasi kadar toko, dan penyusutan alat). Modal pribadi Pengelola tidak mengambil jatah dari porsi pool $20\%$.
* **$20\%$ Laba Bersih:** Hak *Pool* Mitra Pemodal, didistribusikan secara pro-rata hanya kepada modal yang terserap pada putaran belanja tersebut.

---

## 4. Mesin Alokasi Modal (*Waterfall & Pro-Rata Engine*)

Sistem menyediakan dua mode serapan kas belanja yang dapat dikonfigurasi melalui panel Admin:

```
                  [Kebutuhan Kas Belanja Sore Hari]
                                  │
                                  ▼
               ┌──────────────────────────────────────┐
               │         MESIN ALOKASI MODAL          │
               └──────────────────┬───────────────────┘
                                  │
                   ┌──────────────┴──────────────┐
                   ▼                             ▼
      [MODE 1: PRO-RATA MURNI]       [MODE 2: WATERFALL KHUSUS]
      • Kuota Pengelola: 50%         • Kuota Pengelola: 50%
      • Sisa 50%: Dibagi rata        • Kuota Kerabat: X% (Terkunci)
        ke seluruh Investor Umum     • Sisa Kuota: Dibagi rata
        secara proporsional            ke Investor Umum
```

### 4.1. Prioritas Kuota Pengelola (Batas Tetap 50%)
* Baik pada Mode 1 maupun Mode 2, modal kas pribadi Pengelola **selalu diprioritaskan menyerap $50\%$ dari total belanja harian** (selama saldo kas pribadi mencukupi).
* Jika saldo Pengelola kurang dari $50\%$, sistem menyerap seluruh sisa saldo kas Pengelola yang ada, lalu melimpahkan kekurangannya ke pool mitra pemodal.

### 4.2. Logika Penanganan Hari Minus (*Deficit Carry-Over Engine*)
Jika pada kondisi tertentu (misal cuaca ekstrem di hulu) laba kotor harian lebih kecil daripada potongan koordinasi aparat harian:
* Terjadi defisit bersih (misal minus Rp 50.000).
* Sistem **tidak memotong modal pokok mitra** dan tidak membuat saldo akun menjadi negatif.
* Nilai defisit diberi status `PENDING_RECOVERY` dan otomatis menjadi pengurang pertama pada laba kotor hari berikutnya saat operasional kembali normal.

### 4.3. Kebijakan Penarikan Dana (*Exit & Withdrawal Policy*)
* **Penarikan Dividen / Laba:** Dapat dicairkan berkala (dijadwalkan tanggal 1 setiap bulan atau berdasarkan pengajuan melalui dasbor).
* **Penarikan Modal Pokok:**
  * Wajib mengajukan permohonan melalui sistem dengan masa pemberitahuan (*Notice Period*) minimal **H-7 hari kerja**.
  * Selama masa tenggang, status modal akun diubah menjadi `WITHDRAWAL_QUEUED`. Sistem secara bertahap mengurangi serapan modal akun tersebut hingga kas likuid terkumpul utuh tanpa mengganggu stabilitas belanja harian.

---

## 5. Alur Pengikatan Hukum Digital (*Digital Onboarding & Waiver*)

```
[Admin Daftarkan Investor] ──► [Kirim Tautan Privat via WhatsApp]
                                                │
                                                ▼
                                   [Investor Buka Tautan]
                                                │
                                                ▼
                                  [Halaman Edukasi Operasional]
                                  • Karakteristik Emas Dompeng
                                  • Mekanisme T+1 (Tanpa Spekulasi)
                                  • Skema Beban & Bagi Hasil
                                                │
                                                ▼
                                 [Lembar Persetujuan Hukum (Clickwrap)]
                                 • Pernyataan Risiko Sektor Informal
                                 • Pembebasan Tanggung Jawab Hukum
                                                │
                                                ▼
                                [Sistem Rekam Timestamp, IP, & Device ID]
                                                │
                                                ▼
                                    [Aktivasi PIN Akses Dasbor]
```

### 5.1. Klausul Baku Perlindungan Pengelola (*Waiver of Liability*)
Setiap mitra pemodal wajib menyetujui pernyataan berikut sebelum dapat mengakses dasbor:
> *"Saya memahami dan menyetujui sepenuhnya bahwa penyertaan modal ini dialokasikan pada operasional perdagangan dan pengolahan komoditas emas aluvial di sektor informal dengan risiko lapangan nyata (termasuk namun tidak terbatas pada risiko kahar/force majeure, razia/penyitaan jalur logistik, dan fluktuasi taksiran kadar). Saya membebaskan Pengelola dari segala bentuk tuntutan ganti rugi perdata maupun pidana atas kerugian yang timbul di lapangan di luar tindakan penipuan atau penggelapan yang terbukti secara hukum."*

---

## 6. Spesifikasi Modul Fungsional (MVP)

### Modul 1: Meja Kasir Hulu (*Upstream Counter*)
* **Input Transaksi:**
  * Alias Penambang (teks ringkas).
  * Berat Timbangan Kering ($W_{\text{kering}}$ dalam gram, presisi 2–3 desimal).
  * Harga Tebus Lapangan Acuan (Rp/gram).
  * *Toggle* Jasa Bakar Flat (default: Aktif, Rp 20.000).
* **Otomasi Perhitungan:**
  $$\text{Subtotal Tebus} = W_{\text{kering}} \times \text{Harga Tebus Lapangan}$$
  $$\text{Uang Bersih Keluar (Net Pay)} = \text{Subtotal Tebus} - \text{Biaya Jasa Bakar}$$
* **Aksi Sistem:** Tombol **"Bayar Tunai & Catat"** langsung memotong kas aktif meja dan menambah stok fisik emas padu dengan status `HOLDING_OVERNIGHT`.

### Modul 2: Likuidasi Toko & Penutupan Buku (*Settlement Engine*)
* **Input Transaksi:**
  * Berat Total Penjualan Toko (gram).
  * Kadar Taksiran Timbang Air Toko (%).
  * Harga Jual Riil Toko (Rp/gram).
  * Beban Koordinasi Lapangan Hari Ini (Rp).
  * Unggah Foto Nota Toko Asli.
* **Aksi Sistem:** Tombol **"Eksekusi Settlement Harian"** menjalankan kalkulasi:
  $$\text{Laba Bersih} = \text{Laba Kotor} - \text{Beban Aparat} - \text{Pemulihan Defisit Sebelumnya}$$
  * Mengalokasikan $80\%$ ke saldo laba Pengelola.
  * Mengalokasikan $20\%$ ke akun mitra pemodal yang modalnya terserap secara pro-rata.
  * Mengembalikan modal pokok belanja ke status kas likuid siap putar.

### Modul 3: Dasbor Privat Pemodal (*Blind Investor Portal*)
* **Komponen Antarmuka:**
  * Saldo Modal Pokok (Rincian: Sedang Berputar vs Mengendap).
  * Akumulasi Imbal Hasil Bulan Berjalan (Nominal Rp dan ROI %).
  * Riwayat Tanggal Pencairan Dividen.
  * Tombol Pengajuan Penarikan Modal (*Notice H-7*).
* **Restriksi Ketat:** Terisolasi dari informasi transaksi penambang, total omzet usaha, dan data pemodal lain.

### Modul 4: Ekspor Laporan Ramah Awam (*Layperson-Friendly Export*)
* Format laporan (PDF dan Spreadsheet) yang menyajikan bahasa ringkas tanpa istilah akuntansi yang rumit:
  * Kolom Transaksi: Tanggal Putaran, Modal Diputar (Rp), Imbal Hasil Masuk (Rp), Status Modal.
  * Ringkasan Visual: Total Modal Dititipkan, Total Keuntungan Diterima, Sisa Saldo Kas Mengendap.

---

## 7. Desain Skema Basis Data (*Entity Schema*)

```
┌───────────────────────────┐         ┌───────────────────────────┐
│           USERS           │         │      CAPITAL_WALLETS      │
├───────────────────────────┤         ├───────────────────────────┤
│ id (PK, UUID)             │         │ id (PK, UUID)             │
│ full_name (varchar)       │◄────────┤ user_id (FK)              │
│ phone_number (varchar)    │         │ tier (operator/fam/ext)   │
│ role (operator/investor)  │         │ balance_capital (decimal) │
│ is_active (boolean)       │         │ balance_profit (decimal)  │
│ invite_token (varchar)    │         │ locked_quota_pct (decimal)│
│ onboarding_signed_at      │         │ withdrawal_status (enum)  │
│ signed_ip_address         │         │ withdrawal_request_date   │
│ signed_user_agent         │         └───────────────────────────┘
└───────────────────────────┘
              ▲
              │
┌─────────────┴─────────────┐         ┌───────────────────────────┐
│     HULU_COLLECTIONS      │         │     DAILY_SETTLEMENTS     │
├───────────────────────────┤         ├───────────────────────────┤
│ id (PK, UUID)             │         │ id (PK, UUID)             │
│ settlement_id (FK, null)  ├────────►│ settlement_date (date)    │
│ farmer_alias (varchar)    │         │ total_grams_sold (decimal)│
│ dry_weight (g, 3 dec)     │         │ shop_price_per_g (decimal)│
│ buy_price_per_g (decimal) │         │ total_revenue_shop (dec)  │
│ flat_burn_fee (decimal)   │         │ daily_coordination_fee    │
│ net_cash_paid (decimal)   │         │ deficit_recovery_used     │
│ status (holding/settled)  │         │ net_settled_profit (dec)  │
│ created_at (timestamp)    │         │ operator_share_80 (dec)   │
└───────────────────────────┘         │ investor_pool_20 (dec)    │
                                      │ carryover_deficit (dec)   │
                                      │ receipt_photo_url (text)  │
                                      └─────────────┬─────────────┘
                                                    │
                                                    ▼
                                      ┌───────────────────────────┐
                                      │   PROFIT_DISTRIBUTIONS    │
                                      ├───────────────────────────┤
                                      │ id (PK, UUID)             │
                                      │ settlement_id (FK)        │
                                      │ wallet_id (FK)            │
                                      │ capital_utilized (decimal)│
                                      │ profit_distributed (dec)  │
                                      │ distributed_at (timestamp)│
                                      └───────────────────────────┘
```

---

## 8. Persyaratan Non-Fungsional & Keamanan

1. **Otorisasi Sisi Peladen (*Server-Side Authorization*):** Validasi peran diverifikasi langsung pada setiap *endpoint* API. Akun mitra pemodal diblokir di tingkat basis data dari mengakses tabel `HULU_COLLECTIONS` dan nilai `operator_share_80`.
2. **Kinerja Antarmuka Lapangan:** Antarmuka kasir hulu dioptimalkan untuk pengoperasian satu tangan (*one-handed mobile UI*) dengan mode gelap kontras tinggi agar nyaman digunakan di ruangan semi-permanen pada malam hari.
3. **Kompresi Nota Otomatis:** Foto nota toko emas dikompresi otomatis di sisi peramban sebelum diunggah (maksimal 300 KB) untuk menghemat kuota dan mempercepat pengunggahan di area sinyal terbatas.

---

## 9. Tahapan Rencana Pengembangan (*Roadmap*)

| Pekan | Fokus Utama | Target Luaran (*Deliverables*) |
| :---: | :--- | :--- |
| **Pekan 1** | Basis Data & Kasir Hulu | • Skema basis data relasional & autentikasi PIN.<br>• Antarmuka kasir hulu (timbang kering & potong jasa bakar).<br>• Pelacakan kas tunai meja bakar. |
| **Pekan 2** | Likuidasi Toko & Settlement | • Form penjualan toko emas hilir & modul unggah nota.<br>• Mesin settlement harian & pemotongan biaya aparat.<br>• Logika penanganan hari minus (*deficit recovery*). |
| **Pekan 3** | Mesin Alokasi Modal Dinamis | • Implementasi mesin waterfall (prioritas Pengelola 50%).<br>• Perhitungan bagi hasil pro-rata harian otomatis.<br>• Sistem alokasi khusus kerabat. |
| **Pekan 4** | Portal Investor & Finalisasi | • Dasbor terisolasi untuk mitra pemodal (*blind dashboard*).<br>• Modul edukasi & persetujuan hukum digital (*clickwrap*).<br>• Fitur ekspor laporan ringkas ramah awam. |