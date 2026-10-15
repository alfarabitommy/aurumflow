# DOKUMEN SPESIFIKASI DESAIN UI/UX (UI/UX SPECIFICATION)
**Nama Produk:** AurumFlow  
**Versi:** 1.0.0  
**Gaya Desain:** Private WealthTech & Dark Obsidian Luxury  
**Pustaka Komponen Acuan:** Tailwind CSS v3 + Alpine.js (Mobile-First PWA)  
**Status:** Terkunci & Siap Implementasi Frontend  

---

## 1. Identitas Visual & Sistem Desain (Design System)

Untuk menghadirkan kesan mewah, eksklusif (private banking), sekaligus tangguh saat dipakai di ruangan meja bakar malam hari, AurumFlow menerapkan palet visual bertema **Obsidian Gold**:

| Token | Hex Code | Penggunaan Utama |
| :--- | :--- | :--- |
| **bg-obsidian-950** | `#07080B` | Latar belakang utama aplikasi |
| **bg-surface-900** | `#0F1117` | Kartu elemen (Card) & Modal |
| **bg-surface-800** | `#181C26` | Input field & kontainer sekunder |
| **gold-primary** | `#D4AF37` | Aksen utama (Champagne Gold), brand, angka saldo |
| **gold-glow** | `#F3E5AB` | Border tipis kartu & hover state |
| **emerald-cash** | `#10B981` | Saldo kas likuid, nominal laba bersih, status tunai |
| **text-pure** | `#F8FAFC` | Judul, angka nominal primer, teks sorotan |
| **text-muted** | `#94A3B8` | Label sekunder, tanggal, keterangan audit |

### 1.1. Tipografi
* **Font Antarmuka (Interface):** `Plus Jakarta Sans` atau `Inter` — bersih, modern, dan nyaman di mata pada perangkat bergerak.
* **Font Data Finansial & Timbangan:** `JetBrains Mono` (diterapkan dengan kelas CSS `font-mono tabular-nums`). Setiap digit angka timbangan dan nominal rupiah memiliki lebar karakter yang seragam sehingga tidak bergeser saat nilai ter-update.

### 1.2. Efek Kemewahan (Luxury Elevation & Glassmorphism)
* Seluruh kartu (cards) pada dasbor investor menggunakan efek *subtle glassmorphism*:
  * `backdrop-blur-md bg-[#0F1117]/80`
  * Garis batas ultra-tipis: `border border-[#D4AF37]/20`
  * Bayangan halus: `shadow-[0_8px_32px_rgba(0,0,0,0.6)]`
  * Efek pendaran emas lembut di balik kartu ringkasan saldo utama: radial gradient `rgba(212, 175, 55, 0.05)`.

---

## 2. Prinsip Ergonomi Pengguna (Dual-Context UX)

1. **Sisi Pengelola (Konteks: Meja Bakar Malam Hari):**
   * **Kontras Ekstrem:** Memakai latar hitam pekat `#07080B` dengan teks angka putih/emas terang agar tetap terbaca jelas di bawah pencahayaan ruangan semi-permanen yang minim dan berasap.
   * **Fat-Finger Friendly:** Area sentuh (*touch target*) tombol konfirmasi dan input minimal **52px**.
   * **Mekanisme Geser (Swipe-to-Action):** Tombol pembayaran tunai di kasir hulu menggunakan mekanisme geser (*slide to confirm*) untuk meniadakan risiko tertekan ganda (*accidental double submit*).
2. **Sisi Investor (Konteks: Eksekutif & Pemodal Pasif):**
   * **Bebas Kerumitan Operasional:** Menghilangkan grafik spekulasi atau istilah tambang yang rumit. Tampilan fokus pada metrik kunci: modal aman, modal sedang bekerja, dan dividen yang bertambah.
   * **Kerahasiaan Menyeluruh (Zero Information Leak):** Tidak ada elemen antarmuka yang menampilkan total omzet, total laba kotor, atau data pemodal lain.

---

## 3. Cetak Biru Layar Antarmuka (Wireframes & Layouts)

### Layar A: Gerbang Akses & Onboarding Investor (Private Luxury Gate)
Layar pertama yang dibuka oleh calon mitra melalui tautan privat ber-token (`?token=xxx`).

```
┌─────────────────────────────────────────┐
│              [AURUMFLOW]                │
│       Private Wealth Management         │
│                                         │
│   ┌─────────────────────────────────┐   │
│   │ Selamat Datang, Bpk. Budi       │   │
│   │ Kemitraan Modal Putar Emas      │   │
│   └─────────────────────────────────┘   │
│                                         │
│ ── [IKHTISAR KERJA SAMA] ────────────── │
│ • Siklus Putaran Kas Harian (T+1)       │
│ • Komoditas Emas Padat (Bukan Margin)   │
│ • Pembagian Dividen Pro-Rata Harian     │
│                                         │
│ ┌─────────────────────────────────────┐ │
│ │ PAKTA INTEGRITAS & PEMBEBASAN RISIKO│ │
│ │ [Scrollable Box - 120px]            │ │
│ │ "Saya menyetujui seluruh profil     │ │
│ │ risiko operasional lapangan..."     │ │
│ └─────────────────────────────────────┘ │
│                                         │
│ [X] Saya Memahami & Menyetujui Syarat   │
│                                         │
│ [ Buat 6-Digit PIN Akses Portofolio ]   │
│                                         │
│ ┌─────────────────────────────────────┐ │
│ │      AKTIFKAN PORTOFOLIO SAYA       │ │
│ └─────────────────────────────────────┘ │
│   Audit Trail: IP & Waktu Terkunci      │
└─────────────────────────────────────────┘
```

* **Micro-interactions:** Kotak centang persetujuan mengaktifkan tombol aksi dengan transisi pendar emas (*golden shimmer effect*). Tombol terkunci selama kotak teks syarat hukum belum digulir hingga bawah.

> **Catatan implementasi (Fase 5).** Yang **diwajibkan peladen** hanya dua: kotak centang
> tercentang, dan PIN enam digit yang sah. Penguncian tombol sampai kotak waiver digulir
> adalah kenyamanan di sisi peramban, **bukan** kontrol keamanan — peladen tidak dapat
> (dan tidak mencoba) memverifikasi bahwa kotak benar-benar digulir, sehingga tombolnya
> tetap `type="submit"` dan form tetap dapat dikirim tanpa JavaScript.
> Rincian teknis: `plan/Plan_Fase_5_AurumFlow.md` § 4.1 dan `OnboardingService`.

---

### Layar B: Meja Kasir Malam Pengelola (Quick Teller Mode)
Layar kerja utama operator di samping timbangan digital.

```
┌─────────────────────────────────────────┐
│ ≡ AURUMFLOW MEJA HULU        Kas: 18,5M │
├─────────────────────────────────────────┤
│                                         │
│ Inisial Petani (Opsional)               │
│ [ P-04 / Anto                 ]         │
│                                         │
│ Berat Kering Timbangan (Gram)           │
│ ┌─────────────────────────────────────┐ │
│ │ 14.850 gr                           │ │  <- Teks Besar Monospace
│ └─────────────────────────────────────┘ │
│                                         │
│ Harga Tebus / Gram                      │
│ ┌─────────────────────────────────────┐ │
│ │ Rp 1.950.000                        │ │
│ └─────────────────────────────────────┘ │
│                                         │
│ [✓] Potong Jasa Bakar Flat: Rp 20.000   │
│                                         │
│ ── TOTAL KAS BERSIH KELUAR ──────────── │
│                                         │
│         Rp 28.937.500                   │  <- Font 32px Warna Hijau Kas
│                                         │
│ ┌─────────────────────────────────────┐ │
│ │ >>> GESER UNTUK BAYAR TUNAI >>>     │ │  <- Slider Swipe to Confirm
│ └─────────────────────────────────────┘ │
│                                         │
│ [ Riwayat 3 Transaksi Terakhir ]        │
│ • 20:15 WIB | 6.210 gr | Rp 12.089.500  │
│ • 19:40 WIB | 3.100 gr | Rp  6.025.000  │
└─────────────────────────────────────────┘
```

* **Reaktivitas Alpine.js:** Saat digit gram atau harga diubah, angka "TOTAL KAS BERSIH KELUAR" langsung terhitung seketika tanpa jeda reload halaman.

> **Catatan Fase 7 — Layar B dipakai dua peran.** Layar ini adalah satu-satunya permukaan yang
> boleh dibuka **kasir** (lihat § 7). Bagi kasir, navbar hanya memuat menu **Kasir** + **Keluar**,
> tombol **Dashboard** tidak dirender, dan kartu/header hanya menampilkan saldo **Kas Meja Bakar**
> serta Emas *holding* — `Brankas Utama` tidak pernah muncul di markup. Judul header tetap
> "AURUMFLOW · Meja Hulu" agar kasir tidak perlu menyesuaikan kebiasaan kerja.

---

### Layar B2: Modal "Terima Dana Talangan" (Bridge Liquidity — Fase 6)
Dipakai ketika kas meja habis pada malam hari: pengelola meminjam kas tunai dari pihak luar
(**tanpa akun**), lalu mengembalikan pokok + fee pada tutup buku siang berikutnya (T+1).

```
┌─────────────────────────────────────────┐
│ ≡ AURUMFLOW MEJA HULU        Kas:  2,1M │
├─────────────────────────────────────────┤
│ ┌─────────────────────────────────────┐ │
│ │  TALANGAN AKTIF                     │ │
│ │  Pak Haji Daud                      │ │
│ │  Pokok Rp 10.000.000 · Fee Rp 100.000│ │
│ │  Kembali pada tutup buku berikutnya │ │
│ └─────────────────────────────────────┘ │
│ [ + Terima Dana Talangan ]              │  <- Membuka modal
└─────────────────────────────────────────┘

                ┌─────────────────────────────────────────┐
                │  Terima Dana Talangan                   │
                ├─────────────────────────────────────────┤
                │  Nama / Alias Pemberi Dana *            │
                │  [ mis. Pak Haji Daud                 ] │
                │                                         │
                │  Pokok Dana yang Diterima *             │
                │  [ mis. 10.000.000                    ] │
                │                                         │
                │  Bentuk Fee                             │
                │  (•) Nominal (Rp)   ( ) Persen (%)      │
                │  [ mis. 100.000   ] / [ mis. 1,00    ]  │
                │                                         │
                │  Catatan (opsional)                     │
                │  [                                    ] │
                │                                         │
                │  Perkiraan fee        : Rp   100.000    │  <- Cermin Alpine
                │  Kembali saat tutup buku: Rp 10.100.000 │  <- Cermin Alpine
                │                                         │
                │  ⓘ Pokok talangan BUKAN pendapatan;     │
                │    kembali pada tutup buku berikutnya    │
                │    bersama fee (T+1).                    │
                │                                         │
                │  [ Batal ]   [ Terima & Catat Kas Masuk ]│
                └─────────────────────────────────────────┘
```

* **Dua isian fee, satu bentuk dipilih.** Kedua isian (nominal & persen) **selalu dikirim** — hanya
  bentuk yang dipilih yang dipakai peladen. Kontrol form **tidak pernah** `disabled` (peramban
  mengeluarkan kontrol `disabled` dari data form, sehingga POST akan kosong dan peladen menjawab
  `422` "wajib diisi"); selama pengiriman dipakai `:readonly` + kelas redup.
* **Angka contoh berawalan `mis. `** — placeholder adalah contoh, bukan data. Isian yang bernilai awal
  dari peladen (mis. setelah `422`) dibaca ulang Alpine lewat `querySelector` pada `init()`,
  bukan `$refs`.
* Modal memakai state komponen kasir yang sudah ada (`tellerForm`) dan tetap **tersembunyi**
  (`x-cloak`) bila Alpine gagal dimuat — tidak menyisakan kotak kosong.
* **Fee dihitung peladen.** Pratinjau "Perkiraan fee" hanyalah cermin sisi peramban; yang tersimpan
  adalah hasil hitung `BridgeLoanService` (sen integer, pembulatan *half away from zero*).

---

### Layar C: Rekonsiliasi & Settlement Hilir (Morning Settlement)
Layar tutup buku setelah penjualan emas padu ke toko emas langganan selesai.

```
┌─────────────────────────────────────────┐
│ ≡ REKONSILIASI PENJUALAN TOKO           │
├─────────────────────────────────────────┤
│ Status Emas Semalam: 24.160 gr (4 Nota) │
│                                         │
│ ┌─────────────────────────────────────┐ │
│ │ TALANGAN AKTIF & BEBAN FEE          │ │  <- Fase 6
│ │ Pak Haji Daud                       │ │
│ │ Pokok Rp 10.000.000 · Fee Rp 100.000│ │
│ │ Diterima 08/10 23:14 WIB            │ │
│ │ Total kembali dari Kas Meja: 10,1 Jt│ │
│ │ Fee membebani hak pengelola 80 % —  │ │
│ │ pool mitra tidak tersentuh.         │ │
│ └─────────────────────────────────────┘ │
│                                         │
│ Total Berat Dijual Toko (Gram)          │
│ [ 24.160 gr                           ] │
│                                         │
│ Kadar Timbang Air Toko (%)              │
│ [ 93.50 %                             ] │
│                                         │
│ Harga Jual Riil Toko / Gram             │
│ [ Rp 2.085.000                        ] │
│                                         │
│ Bukti Nota Fisik Toko                   │
│ ┌─────────────────────────────────────┐ │
│ │ [📷 Ambil Foto Nota dari Kamera]    │ │
│ │ Preview: nota-180926.jpg (180 KB)   │ │
│ └─────────────────────────────────────┘ │
│                                         │
│ Potongan Koordinasi / Aparat Hari Ini   │
│ [ Rp 200.000                          ] │
│                                         │
│ ── KALKULASI HASIL PUTARAN ──────────── │
│ Kas Masuk Toko    : Rp 50.373.600       │
│ Modal Belanja Hulu: (Rp 47.092.000)     │
│ Laba Kotor        : Rp  3.281.600       │
│ Beban Lapangan    : (Rp   200.000)      │
│ Laba Bersih Putaran: Rp 3.081.600       │
│                                         │
│ ┌─────────────────────────────────────┐ │
│ │ Hak Pengelola (80%): Rp 2.465.280   │ │
│ │ Fee Talangan       : − Rp   100.000 │ │  <- Fase 6 (mirror Alpine)
│ │ Hak Pengelola Bersih: Rp 2.365.280  │ │
│ │ Pool Investor (20%): Rp   616.320   │ │  <- TIDAK berubah
│ └─────────────────────────────────────┘ │
│                                         │
│ ┌─────────────────────────────────────┐ │
│ │     EKSEKUSI SETTLEMENT HARIAN      │ │
│ └─────────────────────────────────────┘ │
└─────────────────────────────────────────┘
```

> **Catatan implementasi (perbaikan UX pascarilis).** Angka pada wireframe di atas adalah
> **ilustrasi**, bukan nilai awal form: `24.160 gr`, `93.50 %`, `Rp 2.085.000`, dan
> `Rp 200.000` tidak pernah dijadikan nilai bawaan di sisi peramban. Yang di-pre-fill peladen
> hanya total berat emas `holding` (dari `hulu_transactions`) dan `coord_fee` memakai asumsi
> PRD (`SettlementController::COORD_FEE_FIELD`); **kadar timbang air & harga jual riil toko
> wajib diisi operator** setiap hari. Setiap contoh nilai pada form ditulis dengan awalan
> `mis.` agar tidak terbaca sebagai data yang sudah terisi. Rincian, bukti, dan assertion
> penjaganya ada di `plan/Plan_Fix_Settlement_UI.md`.

> **Catatan implementasi (Fase 6 — baris dana talangan).** Dua baris pada ringkasan bagi hasil —
> **"Fee talangan (n)"** dan **"Hak pengelola bersih"** — tampil selama sesi itu punya talangan
> aktif, **tanpa pernah bergantung pada laba/rugi yang sedang diketik**. Pada hari sehat barisnya
> tetap terlihat (mis. `Hak pengelola 80 % Rp 1.749.200` − `Fee talangan (1) − Rp 100.000` =
> `Hak pengelola bersih Rp 1.649.200`, dengan `Pool investor 20 % Rp 437.300` tidak tersentuh);
> pada hari minus keduanya menampilkan `Rp 0`. Yang **mengikuti** angka laba hanyalah peringatan
> *"Sebagian fee talangan … melebihi hak laba pengelola"*, karena peringatan itulah yang maknanya
> bergantung pada kecukupan hak pengelola hari itu. Salah mengikatkan baris ke keadaan *uncovered*
> membuat baris fee seolah hilang pada hari sehat — perilaku itu kini dijaga assertion
> `qc_fase_6` G.13–G.16 dan probe `tests/manual/js_bridge_fee_check.js` (yang mengevaluasi
> ekspresi `x-show` di markup untuk tiga keadaan isian: kosong, hari sehat, hari minus).

---

### Layar D: Dasbor Privat Investor (The Luxury Blind Dashboard)
Antarmuka pemodal pasif yang dirancang layaknya aplikasi portofolio aset privat.

```
┌─────────────────────────────────────────┐
│ AURUMFLOW                Mitra: Bpk Budi│
│ PRIVATE PORTFOLIO                       │
├─────────────────────────────────────────┤
│                                         │
│ ┌─────────────────────────────────────┐ │
│ │ TOTAL NILAI PORTOFOLIO              │ │
│ │ Rp 23.450.000                       │ │ <- Emas Champagne + Bold
│ │                                     │ │
│ │ Status Alokasi Hari Ini:            │ │
│ │ [████████████░░░░░░░░] 60% Aktif    │ │
│ │ • Berputar di Emas : Rp 12.000.000  │ │
│ │ • Kas Cadangan     : Rp  8.000.000  │ │
│ └─────────────────────────────────────┘ │
│                                         │
│ ┌──────────────────┐ ┌────────────────┐ │
│ │ DIVIDEN BULAN INI│ │ PERFORMA (ROI) │ │
│ │ Rp 3.450.000     │ │ 17,25% / bln   │ │
│ │ ↑ Terverifikasi  │ │ Berjalan       │ │
│ └──────────────────┘ └────────────────┘ │
│                                         │
│ ── TINDAKAN DANA ────────────────────── │
│ ┌─────────────────────────────────────┐ │
│ │ [ Tarik Keuntungan ] [ Tarik Modal ]│ │
│ └─────────────────────────────────────┘ │
│                                         │
│ ── CATATAN IMBAL HASIL TERAKHIR ─────── │
│ • 18 Sep | Modal Putar: 12M | +Rp 148K  │
│ • 17 Sep | Modal Putar: 10M | +Rp 125K  │
│ • 16 Sep | Modal Putar: 14M | +Rp 182K  │
│                                         │
│ ┌─────────────────────────────────────┐ │
│ │ [ Lihat & Cetak Laporan ]           │ │
│ │ [ Unduh CSV ]                       │ │
│ └─────────────────────────────────────┘ │
└─────────────────────────────────────────┘
```

> **Catatan implementasi (Fase 5).**
>
> * **Label ekspor berubah.** Wireframe awal menyebut satu tombol "Unduh Laporan Bulanan
>   (PDF)". Proyek ini tanpa Composer sehingga pustaka PDF eksternal tidak tersedia;
>   yang disediakan adalah **luar biasa sederhana dan tahan hosting**: halaman laporan
>   HTML ramah cetak (`GET /investor/report`, tombol "Cetak / Simpan PDF" memanggil
>   `window.print()`) **dan** unduhan `GET /investor/report/csv`. Keduanya menyajikan
>   bahasa awam tanpa istilah akuntansi (PRD § 6 Modul 4).
> * **Angka "Status Alokasi Hari Ini" punya satu arti yang dibekukan:**
>   `Modal Berputar = min(capital_utilized` pada baris `profit_distributions` terakhir
>   milik dompet`, balance_capital)`; `Kas Cadangan = balance_capital − Modal Berputar`.
>   Baris terakhir ditentukan `daily_settlements.settlement_date DESC`, lalu
>   `profit_distributions.created_at DESC`, lalu `id DESC` — skema tidak punya kolom
>   status modal apa pun, sehingga serapan yang tercatat itulah satu-satunya sumber.
>   Tanpa klamp, mitra yang menarik modal setelah putaran terakhir akan melihat angka
>   negatif.
> * **Total Nilai Portofolio = `balance_capital + balance_profit`** (modal dititipkan
>   ditambah dividen yang belum ditarik).
> * **"Dividen Bulan Ini"** = `Σ profit_earned` yang `settlement_date`-nya berada pada
>   bulan kalender berjalan (WIB). **ROI** = `dividen bulan ini ÷ balance_capital × 100`
>   (dua desimal; modal `0` ditampilkan `—`, bukan pembagian nol).
> * **Dialog penarikan.** Estimasi tanggal "Kas Siap" dihitung dan dirender **peladen**
>   (`CapitalService::workingDaysDueDate()`, Sabtu/Minggu dilewati) — tidak ada cermin
>   aritmetika tanggal di peramban yang bisa menyimpang. Tombol **Tarik Keuntungan**
>   tidak melewati masa tunggu; **Tarik Modal** mengikuti H-7 (PRD § 4.3).
> * **Nilai awal form** dirender peladen sebagai `value="…"` dan dibaca Alpine `init()`
>   lewat `formFieldValue()` (bukan `$refs`); contoh nilai berawalan `mis. `; kontrol
>   form tidak pernah `:disabled` (hanya `<button>`), dan setiap mutasi memakai
>   token sekali-pakai `_once` + PRG.
> * **Larangan tampilan (blind):** tidak ada omzet, gramatur, jumlah nota, nama
>   penambang, laba kotor, laba bersih pengelola, kuota serapan modal, maupun nama/saldo
>   mitra lain — di halaman mana pun yang dapat dibuka investor.

---

### Layar E: Kelola Modal & Likuiditas (Capital Control)
Layar operator untuk mengelola modal mitra: menerima setoran, mengarahkan kas ke meja bakar, menguji rencana serapan sebelum belanja malam, dan mengelola antrean penarikan.

```
┌─────────────────────────────────────────────┐
│ ≡ AURUMFLOW MEJA HULU                       │
│ [ Kasir ] [ Rekonsiliasi ] [ Kelola Modal ] │  <- nav aktif
├─────────────────────────────────────────────┤
│ Kas Meja: 14.842.500   Brankas: 2.186.500   │
│ Emas holding: 18,050 gr (2 nota)            │
├─────────────────────────────────────────────┤
│ ── A. SETOR MODAL BARU ────────────────────  │
│ Mitra                                       │
│ [ Bpk. Ahmad — Mitra Prioritas        ▾ ]   │
│ Nominal Setoran                             │
│ [ mis. 10.000.000                      ]    │
│ Catatan (opsional)                          │
│ [ mis. setoran tunai 18 Sep            ]    │
│ ┌─────────────────────────────────────┐     │
│ │       CATAT SETORAN KE BRANKAS      │     │
│ └─────────────────────────────────────┘     │
├─────────────────────────────────────────────┤
│ ── B. PORTOFOLIO MITRA ────────────────────  │
│ Tier            | Modal      | Dividen | Kuota Serapan      │
│ Pengelola       | 30.000.000 |       0 | 50,00 % (Sistem)   │  <- badge kebijakan
│ Mitra Prioritas | 16.000.000 | 174.920 | [ 20,00 ] % Simpan │  <- satu-satunya isian
│ Mitra Reguler A | 20.000.000 | 154.341 | Pro-rata           │  <- penanda, bukan isian
│ Mitra Reguler B | 14.000.000 | 108.038 | Pro-rata           │  <- penanda, bukan isian
│ [+ Tambah Mitra Baru]                       │
├─────────────────────────────────────────────┤
│ ── C. PINDAH KAS KE MEJA BAKAR ────────────  │
│ Nominal  [ mis. 5.000.000 ]  → Brankas − 5M │
├─────────────────────────────────────────────┤
│ ── D. SIMULATOR "BELANJA MALAM INI" ───────  │
│ Rencana Belanja                              │
│ [ 35.157.500 ]  <- prefill modal holding     │
│                                              │
│ Pengelola (50%)        : Rp 17.578.750       │
│ Mitra Prioritas (20%)  : Rp  7.031.500       │
│ Mitra Reguler (pro-rata): Rp 10.547.250      │
│   • A 20/34            : Rp  6.204.265       │
│   • B 14/34            : Rp  4.342.985       │
│ Sisa tak terserap      : Rp          0       │
├─────────────────────────────────────────────┤
│ ── E. ANTREAN PENARIKAN (H-7) ─────────────  │
│ Bpk. Ahmad | Modal | 10.000.000 | 25 Sep     │
│            | menunggu | [Setujui][Tolak]     │
│          Bayar  ← aktif hanya setelah jatuh  │
└─────────────────────────────────────────────┘
```

* **Ringkasan halaman (empat bagian).**
  * **A. Setor Modal Baru** — setor tunai mitra masuk ke `Brankas Utama`; satu transaksi menambah `capital_wallets.balance_capital` **dan** saldo pos kas, serta mencatat `cash_mutations` (`in`). Brankas adalah rumah modal; kasir hanya membelanjakan dari Kas Meja Bakar.
  * **B. Portofolio Mitra** — daftar dompet beserta modal, dividen terkumpul, dan kuota serapan. **Kolom kuota bersifat per-tier, bukan satu kotak isian untuk semua:**
    * **Pengelola** — bukan isian: ditampilkan sebagai **badge kebijakan sistem** (`50,00 % (Sistem)`) karena mesin alokasi memakai `system_settings.operator_base_quota_pct`, bukan kuota per dompet. Kotak isian `0,00 %` di sini menyesatkan (nilainya bukan nol, melainkan kebijakan).
    * **Mitra Prioritas** — **satu-satunya tier dengan isian**: kuota terkunci `locked_quota_pct` milik dompet itu.
    * **Mitra Reguler** — bukan isian: ditampilkan sebagai penanda **"Pro-rata"**; tier ini selalu mengikuti sisa kebutuhan dan peladen menolak kuota bukan-nol untuknya.
    Tombol "Tambah Mitra Baru" membuat akun mitra sekaligus dompetnya (praktis, sebelum onboarding privat Fase 5 menyala).
  * **C. Pindah Kas ke Meja Bakar** — satu arah (**Brankas Utama → Kas Meja Bakar**), satu transaksi dua pos + dua mutasi. Arah balik tidak disediakan karena sisa kas malam sudah ditempatkan Smart Auto-Split saat settlement.
  * **D. Simulator "Belanja Malam Ini"** — kalkulator Alpine.js. Isian **terisi awal dari peladen** dengan modal belanja hulu yang nyata (`SUM(net_cash_paid)` baris `holding`) dan **bebas diubah** untuk menguji rencana apa pun. Keluarannya: serapan Pengelola, Mitra Prioritas, Mitra Reguler (rincian per dompet), dan sisa tak terserap. Tabel proyeksi yang dirender peladen menjadi acuan cetak bila JavaScript nonaktif.
  * **E. Antrean Penarikan (H-7)** — status permohonan, tanggal jatuh tempo, dan aksi `Setujui`/`Tolak`/`Bayar`. Tombol `Bayar` baru aktif setelah jatuh tempo (**7 hari kerja**, Sabtu/Minggu dilewati) untuk penarikan modal pokok; penarikan dividen dapat dibayar setelah disetujui. Pencairan mengurangi saldo dompet dan mengeluarkan kas dari `Brankas Utama`.

* **Terminologi tier (UI) vs database.** Tidak ada migrasi skema; yang berbeda hanya labelnya:

| Nilai DB (`capital_wallets.tier`) | Label pada layar | Peran dalam waterfall | Jatah pool 20 % |
| :--- | :--- | :--- | :--- |
| `operator` | **Pengelola** | Diserap lebih dulu sebesar `operator_base_quota_pct` (50 %) | Tidak menerima (PRD § 3.4) |
| `family` | **Mitra Prioritas** | Kuota serapan terkunci per dompet (`locked_quota_pct`) | Ya, pro-rata modal yang bekerja |
| `external` | **Mitra Reguler** | Pro-rata saldo atas sisa kebutuhan | Ya, pro-rata modal yang bekerja |

* **Aturan nilai awal form (sama dengan Layar B/C).** Seluruh nilai awal dirender **peladen** sebagai `value="…"` dan dibaca Alpine di `init()` lewat `formFieldValue()` (bukan `$refs`). Setiap contoh nilai ditulis dengan awalan `mis. `. Kontrol form (`<input>`, `<select>`) **tidak pernah** memakai `:disabled`; selama pengiriman dipakai `:readonly="submitting"` + kelas redup (`opacity-70 cursor-not-allowed`), sebab peramban mengeluarkan kontrol `disabled` dari data form dan peladen akan menjawab `422`. `:disabled` hanya untuk `<button>`.
* **Reaktivitas Alpine.js:** mengubah angka rencana belanja langsung memperbarui seluruh baris serapan tanpa *reload*; peladen tetap menghitung ulang semuanya saat eksekusi.
* **Pemetaan ke dokumen lain:** rumus waterfall & pembulatan sen ada di PRD § 4.4–§ 4.6, parameter QC di `docs/AurumFlow Roadmap and QC Documents.md` (FASE 4), dan rencana teknis di `plan/Plan_Fase_4_AurumFlow.md`.

---

## 4. Spesifikasi Alur Pengajuan Penarikan Modal Pokok (Exit Modal)

Ketika investor menekan tombol **"Tarik Modal"**, sistem menampilkan dialog konfirmasi transparan:

```
┌─────────────────────────────────────────┐
│ PENARIKAN MODAL POKOK                   │
├─────────────────────────────────────────┤
│ Sesuai regulasi operasional likuiditas: │
│ Penarikan modal membutuhkan masa tunggu │
│ Notice Period H-7 hari kerja agar modal │
│ emas selesai dilikuidasi bertahap.      │
│                                         │
│ Nominal Penarikan:                      │
│ [ Rp 10.000.000                       ] │
│                                         │
│ Estimasi Tanggal Kas Siap:              │
│ 25 September 2026                       │
│                                         │
│ [ X ] Batalkan   [ Ajukan Penarikan ]   │
└─────────────────────────────────────────┘
```

---

## 5. Komponen UI Tailwind CSS Siap Pakai

### 5.1. Komponen Kartu Saldo Mewah (Luxury Balance Card)
```html
<div class="relative overflow-hidden rounded-2xl bg-[#0F1117]/90 p-6 border border-[#D4AF37]/20 shadow-2xl backdrop-blur-xl">
  <!-- Soft Ambient Glow -->
  <div class="absolute -right-10 -top-10 h-36 w-36 rounded-full bg-[#D4AF37]/10 blur-3xl pointer-events-none"></div>
  
  <p class="text-xs font-semibold tracking-wider uppercase text-slate-400">Total Nilai Portofolio</p>
  <h2 class="mt-2 text-3xl font-bold tracking-tight text-white font-mono">
    Rp <span class="text-[#D4AF37]">23.450.000</span>
  </h2>

  <!-- Progress Bar Alokasi -->
  <div class="mt-6">
    <div class="flex justify-between text-xs text-slate-400 mb-1.5 font-medium">
      <span>Modal Berputar (60%)</span>
      <span class="text-white font-mono">Rp 12.000.000</span>
    </div>
    <div class="h-2 w-full overflow-hidden rounded-full bg-slate-800">
      <div class="h-full bg-gradient-to-r from-[#D4AF37] to-amber-500 rounded-full" style="width: 60%"></div>
    </div>
  </div>
</div>
```

### 5.2. Komponen Slider Konfirmasi Meja Kasir (Swipe to Confirm)
```html
<div x-data="{ startX: 0, currentX: 0, maxSwipe: 220, isConfirmed: false }" class="relative w-full h-14 bg-[#181C26] rounded-xl border border-slate-700 p-1 overflow-hidden select-none">
  <!-- Background Text -->
  <div class="absolute inset-0 flex items-center justify-center text-xs font-semibold tracking-widest text-slate-400 uppercase pointer-events-none">
    Geser untuk Bayar Tunai &rarr;
  </div>
  
  <!-- Draggable Handle Button -->
  <div 
    @touchstart="startX = $event.touches[0].clientX"
    @touchmove="currentX = Math.max(0, Math.min($event.touches[0].clientX - startX, maxSwipe))"
    @touchend="if(currentX >= maxSwipe - 10) { isConfirmed = true; $refs.formPay.submit(); } else { currentX = 0; }"
    :style="`transform: translateX(${currentX}px)`"
    class="relative z-10 w-12 h-12 bg-emerald-500 hover:bg-emerald-400 rounded-lg flex items-center justify-center cursor-pointer shadow-lg transition-transform duration-75"
  >
    <svg class="w-6 h-6 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
  </div>
</div>
```
---

## 6. Layar Onboarding PIN & Anatomi PWA (Fase 5)

### 6.1 Layar A2: Pintu PIN Mitra (`/investor/login`)

Dipisahkan dari `### Layar A` karena fungsinya berbeda: Layar A adalah **klaim undangan
sekali pakai**, sedangkan layar ini adalah **pintu masuk harian** mitra.

```
┌─────────────────────────────────────────┐
│              [AURUMFLOW]                │
│       Private Wealth Management         │
├─────────────────────────────────────────┤
│ Nomor Telepon                           │
│ [ 08xxxxxxxxxx                        ] │
│                                         │
│ PIN 6 Digit                             │
│ [ ••••••                              ] │
│                                         │
│ ┌───┬───┬───┐                           │
│ │ 1 │ 2 │ 3 │   ← papan angka Alpine    │
│ ├───┼───┼───┤      (peningkatan UX;     │
│ │ 4 │ 5 │ 6 │       input tetap bekerja  │
│ ├───┼───┼───┤       tanpa JavaScript)   │
│ │ 7 │ 8 │ 9 │                           │
│ ├───┼───┼───┤                           │
│ │ ⌫ │ 0 │   │                           │
│ └───┴───┴───┘                           │
│                                         │
│ [        MASUK PORTOFOLIO        ]      │
│                                         │
│ Belum punya PIN? Hubungi pengelola      │
└─────────────────────────────────────────┘
```

| Aturan | Nilai |
| :--- | :--- |
| Field | `phone_number` (`type="tel"`) + `pin` (`type="password"`, `inputmode="numeric"`, `maxlength="6"`, `autocomplete="off"`) |
| Validasi peladen | `^[0-9]{6}$`; PIN lemah ditolak (lihat tabel di bawah) |
| Pesan gagal | Selalu generik: "Nomor telepon atau PIN salah." — tidak membedakan akun tidak ada, bukan investor, atau PIN salah |
| Pembatasan percobaan | 5 kegagalan → kunci 15 menit per kombinasi nomor + IP (`PinThrottle`); jawaban `429` menyebut sisa menit |
| Kredensial operator | Tidak dapat masuk lewat pintu ini; sebaliknya PIN tidak pernah lolos `/login` (kata sandi) |

**Kebijakan PIN lemah (ditolak saat pembuatan PIN di Layar A).**

| Pola | Contoh | Alasan |
| :--- | :--- | :--- |
| Panjang/pola salah | `12345`, `1234567`, `abcdef` | Bukan enam digit |
| Seluruh digit sama | `111111`, `000000` | Ruang tebakan 10¹ saja |
| Barisan naik/turun lurus | `123456`, `654321`, `012345` | Tebakan pertama setiap penyerang |
| Blok tiga digit diulang | `123123`, `456456` | Delapan-puluh tebakan pertama |

### 6.2 Anatomi PWA

| Berkas | Peran |
| :--- | :--- |
| `public/manifest.json` | `display: standalone`, `orientation: portrait`, `lang: id`, `theme_color`/`background_color` `#07080B`, `start_url: "./"`, `scope: "./"` (path **relatif** supaya pemasangan subdirektori tetap benar tanpa berkas dinamis) |
| `public/sw.js` | Service worker: hanya menyentuh **aset statis** (`/assets/**`, ikon, `offline.html`) lewat daftar-putih; navigasi selalu jaringan dahulu dengan `offline.html` sebagai jatuhan; **HTML/AJAX tidak pernah di-cache** |
| `public/offline.html` | Halaman statis tanpa data apa pun |
| `public/assets/icons/` | `icon-192.png`, `icon-512.png`, `icon-maskable-512.png`, `apple-touch-icon-180.png` |
| Registrasi | `public/assets/js/app.js` — hanya bila `https:` atau `localhost`/`127.0.0.1` |

> **Batasan yang disengaja.** Dasbor mitra menampilkan angka uang yang berubah setiap
> tutup buku, sehingga menyimpan halaman HTML di cache akan menyajikan saldo basi —
> persis jenis kesalahan yang dihindari "blind dashboard". Karena itu service worker
> **tidak pernah** men-cache HTML, JSON, atau CSV: yang boleh hidup offline hanya
> kerangka tampilan (CSS, font, skrip, ikon).

---

## 7. Peran Kasir Meja Bakar & Isolasi Tampilan (Fase 7)

### 7.1 Layar A3: Pintu PIN Kasir (`/cashier/login`)

Permukaan masuk baru bagi **kasir meja bakar** — orang yang menjaga timbangan malam, bukan pemilik
pembukuan. Bentuknya sengaja identik dengan Layar A2 (pintu PIN mitra) supaya kasir cukup sekali
belajar, tetapi kredensial, gerbang peran, dan berandanya berbeda.

```
┌──────────────────────────────────────┐
│           AURUMFLOW                  │
│    Private Wealth Management         │
│  ──────────────────────────────────  │
│  Nomor Telepon                       │
│  [ mis. 081234567890        ]        │
│                                      │
│  PIN 6 Angka                         │
│  [ ••••••                   ]        │
│                                      │
│  [ 1 ] [ 2 ] [ 3 ]                   │  <- Papan angka (x-cloak)
│  [ 4 ] [ 5 ] [ 6 ]                   │
│  [ 7 ] [ 8 ] [ 9 ]                   │
│  [     ] [ 0 ] [ ⌫ ]                 │
│                                      │
│  ┌────────────────────────────────┐  │
│  │      MASUK MEJA KASIR          │  │
│  └────────────────────────────────┘  │
│                                      │
│  PIN terkunci atau lupa?             │
│  Hubungi pengelola.                  │
└──────────────────────────────────────┘
```

| Aspek | Nilai |
| :--- | :--- |
| Route | `GET /cashier/login` (form) & `POST /cashier/login` (mutasi, CSRF wajib) |
| Field | `phone_number` (`type="tel"`) + `pin` (`type="password"`, `inputmode="numeric"`, `maxlength="6"`, `autocomplete="off"`) |
| Papan angka | Komponen Alpine **`investorLogin`** yang sudah terdaftar sejak Fase 5 — **bukan** komponen `Alpine.data()` baru, sehingga aset `app.js` yang ter-cache peramban tidak dapat membuat papan angka/tombol hilang (aturan `AGENTS.md`) |
| Gerbang peran | Hanya akun `role = cashier`; operator/mitra dijawab pesan generik `401` |
| Pesan gagal | Selalu generik: "Nomor telepon atau PIN salah." |
| Pembatasan percobaan | 5 kegagalan → kunci 15 menit per kombinasi nomor + IP (`PinThrottle`), dipakai bersama pintu mitra |
| Beranda sesudah masuk | `/operator/teller` (Meja Kasir) |
| Nilai awal | Nomor telepon dikembalikan peladen pada percobaan gagal; **PIN tidak pernah** dirender (tidak ada `value` pada field PIN) |

### 7.2 Navigasi sadar peran (`layouts/operator.php`)

| Elemen | Operator | Kasir |
| :--- | :---: | :---: |
| Menu **Kasir** (`/operator/teller`) | ✅ | ✅ |
| Menu **Rekonsiliasi** (`/operator/settlement`) | ✅ | ⛔ disembunyikan |
| Menu **Kelola Modal** (`/operator/capital`) | ✅ | ⛔ disembunyikan |
| Menu **Kelola Kasir** (`/operator/kasir`) | ✅ | ⛔ disembunyikan |
| Tombol **Dashboard** di header | ✅ | ⛔ disembunyikan |
| Label identitas di header | nama + "Pengelola (Operator)" | nama + "Kasir Meja Bakar" |
| Widget sticky header | Saldo Kas Meja Bakar + Emas holding | idem (tanpa Brankas) |
| Tombol **Keluar** | ✅ | ✅ |

Penyembunyian ini **kosmetik, bukan pengaman**: rute terlarang tetap dijaga peladen dan menjawab
`302` ke `/operator/teller` tanpa tulisan.

### 7.3 Data yang tidak boleh muncul di permukaan kasir

`Brankas Utama` (nama maupun saldo), laba bersih / `pool mitra` / omzet, dompet serta identitas
mitra, dan angka `system_settings` (mode alokasi, kuota 50 %, defisit berjalan). Daftar terlarang
lengkapnya ada di `AGENTS.md`; penerapannya dibuktikan `tests/qc_fase_7.php` (grup E) dengan
kanari angka, bukan hanya pemindaian markup.

### 7.4 Layar "Kelola Kasir" (`/operator/kasir`) — khusus operator

| Blok | Isi |
| :--- | :--- |
| Buat akun kasir | Nama, nomor telepon, PIN 6 digit + konfirmasi (`type="password"`, `inputmode="numeric"`), tombol kirim |
| Daftar kasir | Nama, nomor, status (Aktif/Nonaktif), `updated_at`, tombol **Reset PIN** dan **Aktifkan/Nonaktifkan** (form POST terpisah + `data-confirm`) |
| Kebijakan PIN | Dipakai ulang dari `OnboardingService::pinPolicy()` — PIN lemah ditolak dengan pesan yang sama seperti pembuatan PIN mitra |
| Kerahasiaan | PIN tidak pernah dirender kembali (tidak ada kolom terisi); flash hanya menyebut nama/nomor |
| Aturan form | Sama dengan Layar B/C: nilai awal dari peladen, placeholder contoh berawalan `mis. `, kontrol form **tidak pernah** `:disabled` |
