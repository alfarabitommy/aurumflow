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

---

### Layar C: Rekonsiliasi & Settlement Hilir (Morning Settlement)
Layar tutup buku setelah penjualan emas padu ke toko emas langganan selesai.

```
┌─────────────────────────────────────────┐
│ ≡ REKONSILIASI PENJUALAN TOKO           │
├─────────────────────────────────────────┤
│ Status Emas Semalam: 24.160 gr (4 Nota) │
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
│ │ Pool Investor (20%): Rp   616.320   │ │
│ └─────────────────────────────────────┘ │
│                                         │
│ ┌─────────────────────────────────────┐ │
│ │     EKSEKUSI SETTLEMENT HARIAN      │ │
│ └─────────────────────────────────────┘ │
└─────────────────────────────────────────┘
```

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
│ │ Unduh Laporan Bulanan Ringkas (PDF) │ │
│ └─────────────────────────────────────┘ │
└─────────────────────────────────────────┘
```

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