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

Sejak Fase 7 ada **tiga** peran (`users.role`): `operator` (Pengelola/Pemilik), `investor` (Mitra Pemodal), dan `cashier` (Kasir Meja Bakar — staf lapangan). Setiap peran punya pintu masuk kredensialnya sendiri dan **tidak** dapat memakai pintu peran lain.

| Fitur / Modul Operasional | Pengelola / Admin (Operator) | Kasir Meja Bakar (Cashier) | Mitra Pemodal (Investor) |
| :--- | :---: | :---: | :---: |
| Input Transaksi Beli Petani (Timbang Kering) | Penuh (CRUD) | Ya (hanya input & baca kas meja) | Terblokir Total |
| Terima Dana Talangan (*Bridge Liquidity*) | Ya | Ya (kebutuhan likuiditas malam) | Terblokir Total |
| Monitor Saldo Kas Meja Bakar & Emas *Holding* | Ya | Ya | Terblokir Total |
| Monitor Saldo `Brankas Utama` (rumah modal) | Ya | **Terblokir Total** | Terblokir Total |
| Input Penjualan Toko Emas & Unggah Nota | Penuh (CRUD) | Terblokir Total | Terblokir Total |
| Eksekusi Tutup Buku Harian (*Daily Settlement*) | Ya | Terblokir Total | Tidak |
| Akses Pengaturan Kuota Serapan Modal (*Waterfall Engine*) | Ya | Terblokir Total | Tidak |
| Akses Pengaturan Kuota Mitra Prioritas | Ya | Terblokir Total | Tidak |
| Tata Kelola Akun Kasir (buat, reset PIN, nonaktifkan) | Ya | Terblokir Total | Terblokir Total |
| Monitor Saldo Modal Pokok Sendiri | Ya | Tidak (bukan mitra modal) | Ya (Milik Sendiri) |
| Monitor Status Modal (Aktif Berputar vs Mengendap) | Ya | Terblokir Total | Ya (Milik Sendiri) |
| Riwayat Imbal Hasil (Dividen) Pribadi | Ya | Terblokir Total | Ya (Milik Sendiri) |
| Pengajuan Penarikan Modal Pokok (*Notice Period H-7*) | Tidak | Tidak | Ya |
| Ekspor Laporan Kas Sederhana (PDF/Excel) | Ya (Versi Lengkap) | Terblokir Total | Ya (Versi Ringkas) |

### 2.1. Batas Keras Peran Kasir (Fase 7)

* **Daftar izin eksplisit (*allow-list*).** Sesi `cashier` hanya diterima oleh tiga endpoint: `GET /operator/teller`, `POST /operator/teller`, dan `POST /operator/teller/talangan`. Seluruh rute keuangan lain (rekonsiliasi, kelola modal, dasbor ringkasan, tata kelola kasir, portal mitra) menolaknya dengan pengalihan `302` ke beranda perannya (`/operator/teller`) **tanpa satu pun tulisan** — berlaku untuk GET maupun POST.
* **Kredensial PIN 6 digit ber-pepper** (`Security::hashPin()`), tersimpan pada kolom `users.password_hash` yang sudah ada dengan pembatas percobaan `PinThrottle` (5 kegagalan / 15 menit per `nomor + IP`). PIN kasir **tidak pernah** sah di pintu kata sandi maupun di pintu PIN mitra.
* **Bukan mitra modal.** Akun kasir tidak memiliki `capital_wallets`, tidak menerima bagi hasil, dan tidak pernah dihitung `AllocationEngine`.
* **Tidak melihat bagi hasil pemilik.** Permukaan kasir tidak memuat saldo `Brankas Utama`, `laba bersih`, `pool mitra`, `omzet`, maupun data mitra pemodal.

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

### 3.5. Jembatan Likuiditas Malam (*Bridge Liquidity / Dana Talangan*)

Arus kas AurumFlow tidak simetris: **kas keluar malam** (menebus emas penambang) dan **kas masuk
siang** (toko emas mencairkan hasil). Bila kedatangan penambang melampaui saldo Kas Meja Bakar,
operasi berhenti padahal emas `holding` adalah aset yang pasti terjual keesokan harinya. **Dana
talangan** menutup celah itu.

* **Pemberi dana tidak memerlukan akun.** Cukup dicatat nama/aliasnya (`bridge_loans.lender_name`);
  tidak ada pendaftaran, tidak ada login, tidak ada data identitas lain (§ 1.2).
* **Pokok bukan pendapatan.** Kas talangan hanya **melintas**: masuk ke Kas Meja Bakar malam,
  dikembalikan pada tutup buku siang berikutnya. Pokok **tidak pernah** masuk `daily_settlements`,
  tidak menambah omzet, tidak menambah laba, dan tidak pernah menjadi `profit_distributions`.
* **Fee flat satu siklus (T+1).** Pengelola memasukkan fee dalam bentuk **Nominal (Rp)** atau
  **Persen (%)**; persentase dihitung peladen dari pokok (`fee_amount` selalu hasil hitung peladen,
  bukan kiriman peramban). Fee jatuh pada tutup buku berikutnya bersama pokoknya.
* **Pembebanan fee HANYA pada hak pengelola — pemisahan hak mitra bersifat mutlak.** Fee diserap
  **eksklusif** dari porsi $80\%$ pengelola (`operator_share_80`) pada sesi tutup buku yang
  mengembalikannya. Porsi pool $20\%$ mitra, $\sum$ `profit_earned`, dan setiap
  `capital_wallets.balance_profit` **tidak boleh berubah sepeser pun** karena adanya talangan
  (rujukan § 3.4). Urutan rumus mengunci hal ini: bagi hasil 80 : 20 dihitung lebih dulu, **baru**
  fee talangan dikurangkan dari porsi pengelola.
* **Fee melebihi hak pengelola** (mis. hari minus sehingga hak pengelola Rp 0): pokok + fee tetap
  dikembalikan penuh kepada pemberi dana (uangnya memang ada di kas meja), fee diserap maksimal
  sebesar hak pengelola hari itu, dan **selisihnya dibayar dari kas kerja pengelola** serta dicatat
  `bridge_loans.fee_uncovered` — **bukan** dari dana mitra. Pool $20\%$ tetap `0,00` pada hari
  minus dan modal pokok mitra tidak pernah dipotong (§ 4.2).

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
      • Sisa 50%: Dibagi rata        • Kuota Mitra Prioritas: X% per
        ke seluruh Mitra Reguler       dompet (Terkunci)
        secara proporsional          • Sisa Kuota: Dibagi rata
                                       secara proporsional ke
                                       seluruh Mitra Reguler
```

> **Catatan terminologi (Fase 4).** Label antarmuka **Mitra Prioritas** dan **Mitra Reguler** tidak
> mengubah skema basis data: kolom `capital_wallets.tier` tetap `ENUM('operator','family','external')`
> dengan pemetaan `family` → Mitra Prioritas dan `external` → Mitra Reguler. Rincian ada di § 4.4.

### 4.1. Prioritas Kuota Pengelola (Batas Tetap 50%)
* Baik pada Mode 1 maupun Mode 2, modal kas pribadi Pengelola **selalu diprioritaskan menyerap $50\%$ dari total belanja harian** (selama saldo kas pribadi mencukupi).
* Jika saldo Pengelola kurang dari $50\%$, sistem menyerap seluruh sisa saldo kas Pengelola yang ada, lalu melimpahkan kekurangannya ke pool mitra pemodal.
* Setelah kuota Pengelola, **setiap Mitra Prioritas** menyerap hingga batas kuota terkuncinya sendiri (`capital_wallets.locked_quota_pct`), lalu **Mitra Reguler** menerima sisa secara pro-rata saldo.
* Setiap penyerapan dibatasi `min(kuota, saldo dompet, sisa kebutuhan)` sehingga tidak ada dompet yang terserap melebihi saldonya. Kebutuhan yang tidak tertutup seluruh dompet dilaporkan sebagai **sisa tak terserap** pada catatan mutasi dan log — bukan diambil diam-diam dari dompet mana pun.

### 4.2. Logika Penanganan Hari Minus (*Deficit Carry-Over Engine*)
Jika pada kondisi tertentu (misal cuaca ekstrem di hulu) laba kotor harian lebih kecil daripada potongan koordinasi aparat harian:
* Terjadi defisit bersih (misal minus Rp 50.000).
* Sistem **tidak memotong modal pokok mitra** dan tidak membuat saldo akun menjadi negatif.
* Nilai defisit diberi status `PENDING_RECOVERY` dan otomatis menjadi pengurang pertama pada laba kotor hari berikutnya saat operasional kembali normal.
* **Hari minus tidak membatalkan kewajiban talangan (§ 3.5).** Rumus defisit di atas **tidak
  berubah** karena adanya dana talangan: defisit dihitung dari laba kotor seperti biasa, dan
  `carryover_deficit` tetap `max(0, −laba bersih)`. Yang bergeser hanya tempat penyerapan **fee**
  talangan: pada hari minus hak pengelola bernilai nol, sehingga `fee_absorbed = 0,00` dan seluruh
  fee tercatat `fee_uncovered` (dibayar dari kas kerja pengelola). Pokok + fee tetap dikembalikan
  penuh kepada pemberi dana, `investor_pool_20` tetap `0,00`, dan `balance_capital` mitra tetap
  tidak tersentuh.

### 4.3. Kebijakan Penarikan Dana (*Exit & Withdrawal Policy*)
* **Penarikan Dividen / Laba:** Dapat dicairkan berkala (dijadwalkan tanggal 1 setiap bulan atau berdasarkan pengajuan melalui dasbor).
  * Sejak Fase 5 mitra mengajukan sendiri dari dasbor ("Tarik Keuntungan"). Pengajuan dividen
    **tidak** melewati masa tunggu: `notice_period_due` = hari pengajuan, dan pencairannya
    dilakukan operator setelah disetujui (§ 4.7).
* **Penarikan Modal Pokok:**
  * Wajib mengajukan permohonan melalui sistem dengan masa pemberitahuan (*Notice Period*) minimal **H-7 hari kerja**.
  * Selama masa tenggang, status modal akun diubah menjadi `WITHDRAWAL_QUEUED`. Sistem secara bertahap mengurangi serapan modal akun tersebut hingga kas likuid terkumpul utuh tanpa mengganggu stabilitas belanja harian.
  * Sejak Fase 5 mitra mengajukan sendiri dari dasbor ("Tarik Modal"); mengajukan **modal pokok**
    kedua sebelum yang pertama selesai ditolak `422` (kolom `withdrawal_status` hanya mampu
    menyimpan satu keadaan).
* **Batas pengajuan terbuka per jenis** (berlaku untuk pengajuan operator maupun mitra, Fase 5):
  `Σ nominal pengajuan berstatus `pending`/`approved` per dompet **per jenis**` + nominal baru ≤
  saldo jenis itu (`balance_profit` untuk dividen, `balance_capital` untuk modal pokok). Tanpa
  batas ini tiga pengajuan dividen masing-masing "masih di bawah saldo" dapat menumpuk di antrean
  dan menyesatkan mitra, meskipun `payWithdrawal()` tetap menolak pencairan yang melebihi saldo.

---

### 4.4. Terminologi Tier & Label Antarmuka (Nol Migrasi)

Agar bahasa di lapangan seragam — dan agar label mitra tidak lagi menyiratkan hubungan keluarga
biologis yang tidak selalu benar — label antarmuka dipetakan sekali di kode
(`CapitalWallet::TIER_LABELS`) dan **tidak pernah** mengubah skema:

| Nilai DB (`capital_wallets.tier`) | Label UI | Peran dalam *Waterfall* | Jatah pool 20 % |
| :--- | :--- | :--- | :--- |
| `operator` | **Pengelola** | Diserap lebih dulu sebesar `operator_base_quota_pct` (50 %) | Tidak menerima (§ 3.4) |
| `family` | **Mitra Prioritas** | Kuota serapan terkunci per dompet (`locked_quota_pct`) | Ya, pro-rata modal yang bekerja |
| `external` | **Mitra Reguler** | Pro-rata saldo atas sisa kebutuhan | Ya, pro-rata modal yang bekerja |

Kolom `capital_wallets.tier` tetap `ENUM('operator','family','external')`, `daily_settlements` dan
tabel lain tidak berubah, dan tidak ada migrasi basis data pada fase ini.

### 4.5. Alur Atomik Setoran Modal (Top-Up) & Pindah Kas

**Setoran modal baru** dicatat dalam **satu transaksi** basis data (ACID):

```
Investor menyerahkan kas → Operator mencatat setoran di /operator/capital
  1. capital_wallets.balance_capital  += nominal setoran
  2. cash_registers (Brankas Utama)   += nominal setoran
  3. cash_mutations (type = 'in')     ← jejak audit: sumber, nominal, waktu
```

Bila salah satu langkah gagal, seluruh perubahan digulung: tidak boleh ada modal yang tercatat
tanpa kas fisiknya, atau kas yang bertambah tanpa hak mitra.

**Pindah Kas** memindahkan likuiditas dari brankas ke meja bakar dalam **satu transaksi dua pos**:

```
Brankas Utama (−)  →  Kas Meja Bakar (+)   + 2 × cash_mutations ('out' dan 'in')
```

Arah pindah kas bersifat **satu arah** (Brankas → Meja). Arah sebaliknya tidak disediakan karena
sisa kas malam sudah ditempatkan secara otomatis oleh *Smart Auto-Split* pada Modul 2 (laba bersih
ke brankas, modal belanja kembali ke meja).

**Alokasi bersifat pembukuan (*ex-post*).** Kebutuhan belanja malam hanya diketahui setelah meja
kasir tutup, sehingga `balance_capital` **tidak** dipotong saat pembelian hulu berlangsung. Serapan
modal dihitung saat *settlement* dan dicatat sebagai `profit_distributions.capital_utilized`
per dompet. "Modal yang sedang bekerja" pada dasbor investor (§ 6 Modul 3) direkonstruksi dari baris
tersebut.

### 4.6. Distribusi Pool 20 % & Pembulatan Sen

1. **Kebutuhan kas putaran** = `SUM(net_cash_paid)` seluruh transaksi `hulu_transactions` yang
   disettle (dihitung peladen; nominal dari peramban tidak dipercaya).
2. **Serapan bertingkat (*waterfall*)**, semua dalam **sen integer**:

   $$A_\text{Pengelola} = \left\lfloor \frac{K \times q_\text{op}}{100} \right\rfloor, \quad
     A_i = \left\lfloor \frac{K \times q_i}{100} \right\rfloor, \quad
     \text{pro-rata}_j = \frac{s_j}{\sum s}$$

   dengan $K$ = kebutuhan kas, $q_\text{op}$ = `operator_base_quota_pct`, $q_i$ =
   `locked_quota_pct` dompet Mitra Prioritas, dan $s$ = saldo Mitra Reguler. Setiap penyerapan
   dibatasi `min(kuota, saldo dompet, sisa kebutuhan)`.
3. **Pool 20 %** dibagikan pro-rata atas modal mitra yang benar-benar bekerja (dompet `operator`
   dikecualikan, § 3.4), memakai **metode sisa terbesar (*largest remainder*)** sehingga
   $\sum \text{profit\_earned} = \text{investor\_pool\_20}$ **tanpa selisih sen**.
4. Dompet Pengelola tetap dicatat barisnya (`profit_earned = 0.00`) sebagai bukti serapan modalnya.
5. Hari minus (`net_settled_profit ≤ 0`): pool bernilai `0.00`, tidak ada `balance_profit` yang
   bertambah, dan **modal pokok mitra tidak pernah dipotong** (§ 4.2).

### 4.7. Kebijakan Penarikan pada Layar Kelola Modal

* **Pengajuan** dicatat operator atas nama mitra **atau — sejak Fase 5 — diajukan sendiri oleh
  mitra** dari dasbor (§ 5.2). Jatuh tempo pengajuan modal dihitung dari
  `system_settings.notice_period_days` (7) **hari kerja** — Sabtu dan Minggu dilewati; pengajuan
  dividen berjatuh tempo pada hari pengajuan itu juga.
* **Status**: `pending` → `approved` → `completed`, atau `pending` → `rejected`. Maksimum satu
  pengajuan **modal pokok** terbuka per dompet (kolom `withdrawal_status` pada dompet).
* **Pencairan** (aksi *Bayar*) mengurangi `balance_profit` atau `balance_capital` dompet, mengeluarkan
  kas dari `Brankas Utama`, dan menulis `cash_mutations` (`out`) — semuanya dalam satu transaksi.
  Penarikan **modal pokok** hanya boleh dibayar setelah jatuh tempo; penarikan **dividen** boleh
  dibayar begitu disetujui (PRD § 4.3).

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

### 5.2. Ketentuan Teknis Onboarding & Autentikasi PIN (Fase 5)

Alur di atas diimplementasikan dengan aturan yang dibekukan berikut. Sumber tunggal teksnya ada
di kode (`OnboardingService::WAIVER_VERSION` + `waiverClause()`), bukan disalin ulang di view.

| Aspek | Ketentuan |
| :--- | :--- |
| **Tautan privat** | `GET /join?token=<64 heksadesimal>`; token dibuat peladen (`Security::randomToken(32)`) saat akun mitra dibuat (§ 4.5) atau saat operator menekan "Buat tautan" di `/operator/capital` |
| **Sekali pakai** | Token di-NULL-kan pada saat tanda tangan berhasil. Membuka tautan itu lagi (atau membagikannya ke orang lain) menampilkan halaman "tautan tidak berlaku" **tanpa** tulisan apa pun. Mengirim ulang formulir juga tidak pernah menulis dua kali |
| **Onboarding ulang** | Operator dapat memutar token ("Buat ulang tautan") — tautan lama langsung mati. Cara ini sekaligus menjadi jalur **reset PIN**; tanda tangan berikutnya menimpa jejak audit (arsip riwayat persetujuan belum ada, lihat § 5.3) |
| **Satu transaksi** | `password_hash` + `onboarding_signed_at` + `signed_ip_address` + `signed_user_agent` + `invite_token = NULL` ditulis dalam **satu** `Database::transaction()`; gagal di tengah berarti token tetap hidup dan akun tetap belum dapat dipakai |
| **Jejak dari peladen** | Waktu memakai jam peladen (WIB), IP dari `REMOTE_ADDR` (header `X-Forwarded-For` **tidak** dipercaya), User-Agent dipotong 512 karakter. Tidak ada satu pun nilai itu yang dibaca dari formulir |
| **PIN** | Tepat 6 digit. Ditolak: panjang bukan 6, seluruh digit sama (`111111`), barisan naik/turun lurus (`123456`, `654321`), dan blok tiga digit berulang (`123123`) |
| **Penyimpanan PIN** | Kolom yang sama dengan kata sandi operator, tetapi **ber-pepper**: `password_hash(hash_hmac('sha256', pin, APP_PIN_PEPPER))`. Akibat yang disengaja: PIN **tidak pernah** memuaskan pintu kata sandi `/login`, sehingga pembatasan percobaan tidak bisa dilewati lewat pintu lain. Bila `APP_PIN_PEPPER` kosong saat mitra membuat PIN, sistem **menolak** (fail-closed) alih-alih menyimpan PIN tanpa pepper |
| **Pintu masuk** | `GET`/`POST /investor/login` (nomor telepon + PIN 6 digit, papan angka di peramban). Pesan gagal selalu generik sehingga akun tidak dapat dienumerasi |
| **Pembatasan percobaan** | 5 kegagalan dalam 15 menit mengunci kombinasi nomor + IP selama 15 menit (`PinThrottle`, berkas di `storage/throttle/`, di luar document root). Pintu `/login` memakai pembatas yang sama. Bila direktori tidak dapat ditulis, sistem turun mutu ke penghitung sesi dan menulis satu peringatan ke log — bukan menggagalkan login |

### 5.3. Keterbatasan yang Diketahui (Fase 5)

* **Versi teks waiver belum tersimpan.** Kolom yang tersedia hanya merekam waktu, IP, dan
  User-Agent. Bila klausul berubah, tidak ada cara membuktikan versi mana yang disetujui mitra
  lama; itu menuntut kolom/tabel baru dan berada di luar nol-migrasi fase ini.
* **Onboarding ulang menimpa jejak audit.** Hanya bukti persetujuan **terakhir** yang tersimpan
  (lihat § 5.2 "Onboarding ulang").
* **Papan angka PIN bersifat kosmetik.** Peramban tanpa JavaScript tetap dapat memakai isian PIN
  biasa; yang menjaga keamanan adalah kebijakan PIN, pembatas percobaan, dan pepper.

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
* **Rumus yang dibekukan (Fase 5).** Skema tidak memiliki kolom "modal sedang bekerja"; satu-satunya
  bukti serapan adalah baris `profit_distributions` milik dompet itu sendiri (§ 4.5):
  * `Modal Berputar = min(capital_utilized` pada baris distribusi **terakhir** dompet itu`,
    balance_capital)` — baris terakhir ditentukan `settlement_date DESC`, lalu `created_at DESC`,
    lalu `id DESC`. Klamp `min(...)` mencegah angka negatif bagi mitra yang menarik modal setelah
    putaran terakhir.
  * `Kas Cadangan = balance_capital − Modal Berputar`.
  * `Total Nilai Portofolio = balance_capital + balance_profit`.
  * `Dividen Bulan Ini = Σ profit_earned` yang `settlement_date`-nya di bulan kalender berjalan (WIB);
    `ROI Bulan Ini = Dividen Bulan Ini ÷ balance_capital × 100` (dua desimal; modal `0` → `—`).
* **Restriksi Ketat:** Terisolasi dari informasi transaksi penambang, total omzet usaha, dan data pemodal lain.
  Daftar terlarangnya eksplisit pada `AGENTS.md` dan dijaga pemindaian statis + assertion HTTP
  (`qc_fase_5`): tidak ada `hulu_transactions`/`HuluTransaction`, nama penambang, gramatur,
  `total_shop_revenue`, `net_settled_profit`, `operator_share_80`, `investor_pool_20`,
  `allocation_mode`, `operator_base_quota_pct`, kas fisik, maupun baris mitra lain — di HTML, CSV,
  maupun laporan cetak.

### Modul 4: Ekspor Laporan Ramah Awam (*Layperson-Friendly Export*)
* Format laporan yang menyajikan bahasa ringkas tanpa istilah akuntansi yang rumit:
  * Kolom Transaksi: Tanggal Putaran, Modal Diputar (Rp), Imbal Hasil Masuk (Rp), Status.
  * Ringkasan Visual: Total Modal Dititipkan, Total Keuntungan Diterima, Sisa Saldo Kas Mengendap.
* **Bentuk ekspor final (Fase 5).** Proyek ini tidak memakai Composer, sehingga pustaka PDF
  eksternal tidak tersedia dan **tidak** dipakai (butuh berkas pihak ketiga di hosting tanpa
  vendor/). Yang disediakan dasbor:
  * **Laporan ramah cetak** — `GET /investor/report`, halaman HTML tanpa navigasi aplikasi dengan
    gaya `@media print`; tombol "Cetak / Simpan PDF" memanggil `window.print()` sehingga peramban
    pengguna yang menghasilkan PDF-nya. Berisi nama mitra (miliknya sendiri) + kolom di atas +
    ringkasan.
  * **Unduhan CSV** — `GET /investor/report/csv` (`Content-Type: text/csv`, BOM UTF-8, pemisah `;`,
    angka konvensi Indonesia seperti `6.204.264,71`) supaya terbuka rapi di Excel lokal.
  * Kolom **Status** bernilai `Terverifikasi` pada setiap baris: baris itu ada karena
    `profit_distributions` mencatat serapan dan dividennya — mitra tidak perlu memahami tabelnya.
  * Tidak ada teks yang berasal dari klien pada berkas ekspor (hanya tanggal, nominal, dan status
    yang dihitung peladen), sehingga formula injection tidak mungkin terjadi.

### Modul 5: Jembatan Likuiditas Dana Talangan (*Bridge Liquidity*) — Fase 6

* **Konteks.** Dipakai ketika Kas Meja Bakar habis pada malam hari; pengelola meminjam kas tunai
  jangka pendek dari pihak luar (tanpa akun) dan mengembalikannya pada tutup buku berikutnya.
* **Penerimaan (Layar B).** Tombol **"Terima Dana Talangan"** membuka modal: nama/alias pemberi
  dana, pokok, bentuk fee (**Nominal** / **Persen**), nilai fee, dan catatan opsional. Satu
  transaksi basis data: `bridge_loans` (`status='active'`) + Kas Meja Bakar bertambah pokok +
  `cash_mutations` (`in`).
* **Otomasi.**
  $$\text{Fee} = \text{Pokok} \times \text{Tarif}\;(\text{bila Persen}), \qquad
    \text{Kembali} = \text{Pokok} + \text{Fee}$$
  $$\text{Hak Pengelola Bersih} = \text{operator\_share\_80} - \min(\text{Fee},\ \text{operator\_share\_80}),
    \qquad \text{investor\_pool\_20}\ \text{tidak berubah}$$
* **Aksi Sistem pada Tutup Buku (Layar C).** Sistem menghitung pengembalian pokok + fee,
  memotong fee dari hak pengelola, dan menulis mutasi kas **`out`** dari Kas Meja Bakar (satu mutasi
  per talangan, `bridge_loan_id` terekam pada `notes`). Baris talangan menjadi `status='settled'` +
  `settlement_id`. Talangan yang belum lunas (mis. sesi tutup buku gagal) tetap `active` dan
  terbayar pada tutup buku berikutnya, urutan **FIFO** (terlama lebih dahulu).
* **Isolasi.** Tidak ada satu pun route investor yang membaca `bridge_loans`; istilah "talangan"
  dilarang muncul pada dasbor, laporan cetak, maupun CSV mitra (§ 8; daftar terlarang
  `AGENTS.md`).

---

### Modul 6: Peran Kasir Meja Bakar & Isolasi Izin (*Strict Role Separation*) — Fase 7

* **Konteks.** Meja Bakar dijaga staf, bukan pemilik: memberi kredensial pengelola kepada kasir
  berarti menyerahkan seluruh pembukuan (saldo `Brankas Utama`, mode alokasi, kuota 50 %,
  dompet mitra, bagi hasil 80 : 20). Modul ini memisahkan peran secara struktural.
* **Peran & kredensial.** Peran ketiga `cashier` pada `users.role` (aditif). Kasir masuk lewat
  `GET/POST /cashier/login` dengan **nomor telepon + PIN 6 digit** (papan angka Alpine, papan
  yang sama dengan mitra). PIN disimpan ber-pepper pada kolom `users.password_hash` yang sudah
  ada, dengan pembatas percobaan `PinThrottle`; PIN kasir tidak sah di pintu kata sandi maupun
  pintu PIN mitra.
* **Izin (*allow-list*).** Kasir **hanya** boleh: input pembelian hulu (`GET/POST
  /operator/teller`), melihat saldo Kas Meja Bakar + Emas *holding*, dan **menerima dana
  talangan** (`POST /operator/teller/talangan`). Seluruh rute lain — rekonsiliasi, kelola modal,
  dasbor ringkasan, tata kelola kasir, portal mitra — menolaknya dengan `302` ke `/operator/teller`
  **tanpa tulisan**.
* **Tata kelola akun.** Pengelola membuat/menonaktifkan akun kasir dan mereset PIN-nya dari layar
  **"Kelola Kasir"** (`/operator/kasir`); kasir **tidak** memiliki dompet modal dan tidak pernah
  menerima bagi hasil.
* **Isolasi permukaan.** Navigasi kasir hanya memuat meja kasir; header/kartu kasir tidak pernah
  menampilkan `Brankas Utama`, laba bersih, `pool mitra`, omzet, maupun data mitra pemodal.

---

## 7. Desain Skema Basis Data (*Entity Schema*)

```
┌───────────────────────────┐         ┌───────────────────────────┐
│           USERS           │         │      CAPITAL_WALLETS      │
├───────────────────────────┤         ├───────────────────────────┤
│ id (PK, UUID)             │         │ id (PK, UUID)             │
│ full_name (varchar)       │◄────────┤ user_id (FK)              │
│ phone_number (varchar)    │         │ tier: operator/family/ext │
│ role (operator/investor/cashier) │         │ balance_capital (decimal) │
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

> **Catatan.** Sketsa di atas bersifat ilustratif; nama tabel/kolom yang otoritatif ada di
> `docs/AurumFlow Entity Relationship Diagram.md`. Kolom `capital_wallets.tier` tetap bernilai
> `operator` / `family` / `external`, dengan label antarmuka **Pengelola** / **Mitra Prioritas** /
> **Mitra Reguler** (§ 4.4) — tidak ada migrasi skema.

---

## 8. Persyaratan Non-Fungsional & Keamanan

1. **Otorisasi Sisi Peladen (*Server-Side Authorization*):** Validasi peran diverifikasi langsung pada setiap *endpoint* API. Akun mitra pemodal diblokir di tingkat basis data dari mengakses tabel `HULU_COLLECTIONS` dan nilai `operator_share_80`.
   * **Penegakan Fase 5:** setiap route investor dijaga `requireRole('investor')`, dan pemisahan data
     ditegakkan **dua lapis** — (a) query khusus investor memilih kolom secara eksplisit (tidak ada
     `SELECT *`, tidak ada JOIN ke kolom omzet/laba), dan (b) pemindaian statis + assertion HTTP
     atas isi halaman, CSV, dan laporan cetak memastikan daftar terlarang tidak pernah bocor.
     Investor yang membuka `/operator/*` atau `/dashboard` diarahkan ke `/investor/dashboard`
     (bukan halaman error), sesuai Protokol QC Manual 5.
   * **Penegakan Fase 6 (Dana Talangan):** `bridge_loans`, `BridgeLoan`, dan istilah tampilan
     "talangan" bergabung ke daftar terlarang berkas investor — likuiditas/kredit pengelola adalah
     informasi internal operator. Tidak ada route investor yang membaca tabel itu, dan assertion
     HTTP atas body dasbor, laporan cetak, serta CSV memastikan istilah tersebut tidak pernah bocor.
   * **Penegakan Fase 7 (Peran Kasir):** sesi `cashier` ditolak oleh seluruh controller keuangan
     (`SettlementController`, `CapitalController`, `DashboardController`, `CashierController`,
     jalan investor) lewat `requireRole(...)` yang menjadi **pernyataan pertama** setiap method —
     dijawab `302` ke `/operator/teller` **tanpa tulisan** dan tanpa mengonsumsi token `_once`.
     Permukaan kasir (`/operator/teller`) tidak boleh memuat `Brankas Utama`, `laba bersih`,
     `pool mitra`, `omzet`, maupun data mitra; dibuktikan assertion HTTP + kanari angka.
2. **Isolasi Kredensial PIN:** PIN 6 digit disimpan ber-pepper (bukan hash polos) sehingga PIN tidak
   pernah memuaskan pintu kata sandi, dan percobaan login dibatasi **5 kegagalan → kunci 15 menit**
   per kombinasi nomor + IP pada kedua pintu masuk. Tautan undangan bersifat sekali pakai.
3. **Kinerja Antarmuka Lapangan:** Antarmuka kasir hulu dioptimalkan untuk pengoperasian satu tangan (*one-handed mobile UI*) dengan mode gelap kontras tinggi agar nyaman digunakan di ruangan semi-permanen pada malam hari.
4. **Kompresi Nota Otomatis:** Foto nota toko emas dikompresi otomatis di sisi peramban sebelum diunggah (maksimal 300 KB) untuk menghemat kuota dan mempercepat pengunggahan di area sinyal terbatas.
5. **PWA tanpa cache data keuangan:** `manifest.json` + `sw.js` hanya menyimpan aset statis
   (CSS, font, skrip, ikon) untuk kemampuan "Tambahkan ke Layar Utama"; HTML, JSON, dan CSV —
   termasuk seluruh halaman dasbor — selalu diambil dari jaringan agar saldo yang tampil tidak
   pernah basi.

---

## 9. Tahapan Rencana Pengembangan (*Roadmap*)

| Pekan | Fokus Utama | Target Luaran (*Deliverables*) |
| :---: | :--- | :--- |
| **Pekan 1** | Basis Data & Kasir Hulu | • Skema basis data relasional & autentikasi PIN.<br>• Antarmuka kasir hulu (timbang kering & potong jasa bakar).<br>• Pelacakan kas tunai meja bakar. |
| **Pekan 2** | Likuidasi Toko & Settlement | • Form penjualan toko emas hilir & modul unggah nota.<br>• Mesin settlement harian & pemotongan biaya aparat.<br>• Logika penanganan hari minus (*deficit recovery*). |
| **Pekan 3** | Mesin Alokasi Modal Dinamis | • Implementasi mesin waterfall (prioritas Pengelola 50%).<br>• Perhitungan bagi hasil pro-rata harian otomatis.<br>• Sistem alokasi khusus Mitra Prioritas. |
| **Pekan 4** | Portal Investor & Finalisasi | • Dasbor terisolasi untuk mitra pemodal (*blind dashboard*).<br>• Modul edukasi & persetujuan hukum digital (*clickwrap*).<br>• Fitur ekspor laporan ringkas ramah awam. |