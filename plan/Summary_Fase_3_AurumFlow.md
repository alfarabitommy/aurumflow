# Summary Eksekusi Fase 3 — AurumFlow

> **Laporan eksekusi.** Mendampingi `plan/Plan_Fase_3_AurumFlow.md` (rencana +
> keputusan `dec-d784fcb5c5b0720a`). Dokumen ini mencatat **apa yang benar-benar
> dieksekusi, apa yang diuji, dan apa yang menyimpang** — termasuk hal yang
> tidak berjalan sesuai rencana.

| Item | Nilai |
|---|---|
| Status | **Selesai & terverifikasi** |
| Fase | 3 — Likuidasi Toko Emas & Mesin Settlement Harian |
| Runtime uji | PHP **8.3.6** (CLI) |
| Database uji | **MariaDB 12.3.2** — database `aurumflow_dev` (dari `.env`) |
| Database target | **MySQL 8.0 / InnoDB** (belum diverifikasi, lihat §8 A-1) |
| Frontend | Tailwind CSS **3.4.19** (dikompilasi lokal) + Alpine.js **3.17.4** + kompresi **HTML5 Canvas** |
| Kode baru | 6 berkas (3 kode + 1 view + 1 suite + 1 `.gitignore`) + dokumen ini |
| QC | **9 suite · 345 assertion · 0 gagal** (exit 0) |
| Skema | **tidak berubah** — 9 tabel ERD Fase 1 dipakai apa adanya (13 kolom `daily_settlements` sudah tersedia) |

---

## 1. Ringkasan hasil

Fase 3 menutup siklus **T+1**: emas padu yang disimpan semalam dijual ke toko
rekanan pagi hari, nota fisiknya difoto, lalu operator mengeksekusi tutup buku
yang mengubah emas menjadi kas siap putar.

1. **Layar rekonsiliasi** (`/operator/settlement`, GET+POST) — ringkasan emas
   `holding` + modal belanja hulu, lima isian penjualan toko, unggah foto nota
   (kamera), pratinjau kalkulasi langsung, dan blok Smart Auto-Split.
2. **Kompresi foto nota di peramban** (`compressImage()` pada
   `public/assets/js/app.js`) — HTML5 Canvas: kualitas JPEG diturunkan lalu
   dimensi diperkecil hingga **< 250 KB**, lalu isi `input[type=file]` diganti
   lewat `DataTransfer` sehingga POST multipart biasa mengirim gambar
   terkompresi. Bila Canvas/DataTransfer tidak tersedia, berkas asli tetap
   dikirim dan batas keras peladen yang memutuskan.
3. **Smart Auto-Split** — saran bawaan: modal belanja hulu kembali ke
   `Kas Meja Bakar`, laba bersih ke `Brankas Utama`; kedua isian dapat
   disunting operator dan peladen hanya menerimanya bila jumlahnya **persis**
   `pendapatan − beban aparat`.
4. **Tutup buku atomik** (`SettlementService::settle()`) — satu
   `Database::transaction()`: `daily_settlements` + `hulu_transactions`
   (`holding`→`settled`) + `cash_registers` ×2 + `cash_mutations` ×2 +
   `system_settings.active_carryover_deficit`.
5. **Deficit Carry-Over Engine** — laba bersih negatif tidak pernah menyentuh
   modal investor: bagi hasil 0/0, selisihnya masuk `carryover_deficit` dan
   menjadi `active_carryover_deficit`, dipotong lebih dulu pada settlement
   berikutnya.
6. **Unggah nota yang aman** — tipe ditebak dari isi berkas, nama dari UUID,
   batas keras, dan `unlink()` otomatis bila transaksi digulung.
7. **Suite QC otomatis** (`tests/qc_fase_3.php`) — Protokol QC Manual 3
   lengkap dengan gambar nota tiruan (tanpa kamera).

---

## 2. Cara mereproduksi verifikasi

```bash
# Prasyarat: PHP 8.2+ (pdo_mysql, curl, zlib, fileinfo), MariaDB/MySQL.
# Node hanya bila ingin mengompilasi ulang CSS (aset hasil build sudah di-commit).

bash tests/run_qc.sh          # 9 suite; menulis ke database .env + berkas nota uji
php tests/qc_fase_3.php       # suite Fase 3 saja (Protokol QC Manual 3)

# Server pengembangan
php -S 127.0.0.1:8000 -t public tests/server_router.php
#   login operator -> /dashboard -> "Buka Rekonsiliasi Toko"
```

Keluaran lengkap tersimpan di `storage/logs/qc-fase3.log` (di-gitignore).

---

## 3. Hasil QC

| Suite | Assertion | Hasil | Cakupan |
|---|---:|---|---|
| `qc_pdo` | 20 | ✅ PASS | Koneksi, charset, timezone, transaksi commit/rollback |
| `qc_formatter` | 43 | ✅ PASS | Format Rupiah & gramatur |
| `qc_security` | 32 | ✅ PASS | XSS, password, CSRF, session, `uuidV4()` |
| `qc_seed` | 46 | ✅ PASS | Idempotensi DDL/seed, 9 tabel ERD, konvensi DECIMAL, guard installer |
| `qc_roadmap_protocol_1` | 6 | ✅ PASS | Protokol QC Manual 1 (1:1) |
| `qc_fase_2` | 61 | ✅ PASS | Protokol QC Manual 2 (regresi Fase 2 tetap hijau) |
| **`qc_fase_3`** | **91** | ✅ PASS | **Protokol QC Manual 3** + kalkulasi murni, parsing, atomisitas, guard, anti double-submit, guard peran, penyajian nota, pemindaian statis, skema |
| `qc_sql_injection` | 15 | ✅ PASS | 8 payload injeksi, integritas data |
| `qc_routing` | 31 | ✅ PASS | Routing, CSRF, login/logout, cookie, header, proteksi file, aset |
| **Total** | **345** | **0 FAIL** | exit code 0 |

`qc_fase_3` dijalankan **tiga kali berturut-turut** dengan hasil identik
(91/91) untuk memastikan suite idempotent dan tidak bergantung waktu.

### 3.1 Protokol QC Manual 3 (dokumen Roadmap)

Fixture: keadaan akhir QC 2 dibangun ulang lewat jalur kode nyata
(`QcFixture::resetSettlementState()` → `TellerService::payCash()` dua kali),
sehingga terbentuk **18,050 gr** `holding` dengan modal belanja **Rp 35.157.500**
dan kas meja **Rp 14.842.500**. Foto nota diunggah sebagai gambar tiruan
(PNG dibuat tanpa ekstensi GD) lewat multipart.

| Parameter Uji | Data Masukan | Hasil yang Diharapkan | Status |
|---|---|---|---|
| Total Kas Masuk Toko (3.1) | 18,050 × Rp 2.080.000 | `Rp 37.544.000` | ✅ |
| Total Modal Belanja Hulu | 28.937.500 + 6.220.000 | `Rp 35.157.500` (`SUM(net_cash_paid)`) | ✅ |
| Laba Kotor Usaha (3.1) | Rp 37.544.000 − Rp 35.157.500 | `Rp 2.386.500` | ✅ |
| Laba Bersih Putaran (3.1) | Rp 2.386.500 − Rp 200.000 | `Rp 2.186.500` | ✅ |
| Hak Pengelola (80%) | 80% × Rp 2.186.500 | `Rp 1.749.200` | ✅ |
| Pool Investor (20%) | 20% × Rp 2.186.500 | `Rp 437.300` | ✅ |
| Status Transaksi Hulu | 2 baris `holding` | serentak `settled` + `settlement_id` | ✅ |
| Alokasi Kas Fisik (3.1) | meja + brankas = pendapatan − aparat | `Rp 35.157.500` / `Rp 2.186.500` | ✅ |
| Sisa Kas Meja (3.1) | Rp 14.842.500 + Rp 35.157.500 | **`Rp 50.000.000`** (modal pulih utuh) | ✅ |
| Hasil Bersih Hari Minus (3.2) | Rp 120.000 − Rp 200.000 | `-Rp 80.000` | ✅ |
| Saldo Investor Utuh (3.2) | bagi hasil 80 : 20 | `0,00 / 0,00`, `capital_wallets` & `profit_distributions` tidak disentuh | ✅ |
| Catatan Sistem (3.2) | `carryover_deficit` | `Rp 80.000` + `active_carryover_deficit = 80000.00` | ✅ |
| Alokasi Kas Hari Minus (3.2) | kas diterima Rp 1.850.000 < modal Rp 1.930.000 | meja `Rp 1.850.000`, brankas `Rp 0`, kas meja akhir `Rp 49.920.000` | ✅ |
| Settlement Esoknya (3.3) | Rp 570.000 − aparat Rp 200.000 − defisit Rp 80.000 | `Rp 290.000` → pengelola `Rp 232.000` / pool `Rp 58.000`; kas meja kembali `Rp 50.000.000`, brankas `Rp 290.000` | ✅ |
| Nota Fisik Tersimpan | unggahan multipart (tiruan) | `<uuid>.png` di `public/uploads/receipts/` | ✅ |

Nilai dibandingkan dalam **sen integer** dan string desimal kanonik
(`'37544000.00'`, `'18.050'`), bukan lewat pembulatan tampilan.

### 3.2 Bukti tambahan di luar protokol manual

- **Kalkulasi murni (grup A).** Invariant `operator + pool == net` untuk 7 nilai
  laba (termasuk ganjil); hari minus → `0/0` + `carryover`; pemulihan **penuh**
  (Rp 80.000 → sisa defisit 0) dan **sebagian** (laba Rp 70.000 habis untuk
  defisit, sisa Rp 10.000); hari dengan laba kotor = beban aparat (net 0, defisit
  tidak bertambah); manual override diterima/ditolak sesuai jumlah; invariant
  alokasi `kas diterima = modal + laba bersih + defisit dipulihkan` pada ketiga
  skenario.
- **Parsing input lapangan (grup B).** Berat `'18,050'`/`'18.050'`/`'18.050 gr'`
  → 18,050 gr; kadar `'93,50'`/`'93.5'`/`'100'` → `93.50`/`93.00`/`100.00`;
  nominal `'200.000'`/`'200000'`/`'200.000,50'`/`'0'`/`''`. Ditolak: berat
  kosong/nol/negatif/dua pemisah/4 desimal; kadar kosong/nol/>100/3 desimal;
  nominal negatif/3 desimal/ribuan salah/bukan angka. **Penjaga penyimpangan:**
  parser nominal `SettlementService` dibandingkan langsung dengan parser harga
  `TellerService` pada 5 nilai — keduanya tidak dapat berbeda diam-diam.
- **Atomisitas (grup C).** Kegagalan nyata di tengah transaksi (baris kunci
  defisit dihapus, unggahan valid sehingga berkas sudah tersalin) → `422`, **nol**
  baris `daily_settlements`, status hulu tidak berubah, saldo kedua pos utuh, nol
  mutasi, dan **berkas nota yang sudah tersalin ikut terhapus**. Ditambah rantai
  tulisan lengkap (settlement + `settleBatch()` + `SystemSetting::set()`) yang
  digagalkan di ujung transaksi → seluruhnya tergulung.
- **Guard (grup D).** Enam kasus ditolak `422` tanpa tulisan dan tanpa berkas
  yatim: alokasi tidak pas, berat jual > emas `holding`, kadar > 100 %, nota tidak
  dilampirkan, berkas bukan gambar (teks bernama `.png`), berkas melebihi batas.
- **Peladen tidak percaya klien (grup E).** POST berisi `total_shop_revenue`,
  `holding_cost`, `net_settled_profit`, `operator_share_80`, `investor_pool_20`,
  `carryover_deficit`, `deficit_recovery_used`, `active_carryover_deficit`, dan
  `settlement_id` palsu → nilai tersimpan tetap hasil hitung peladen.
- **Anti double-submit (grup F).** POST sah → 1 sesi; replay token `_once` yang
  sama → 0 sesi baru, saldo tetap, pesan "ganda diabaikan"; token lama tetap mati
  setelah halaman dirender ulang; POST tanpa `_once` atau tanpa CSRF → `419`.
- **Guard peran & penyajian nota (grup G).** Anonim → `302 /login`; investor →
  `302 /dashboard` dan POST-nya tidak menulis apa pun; foto nota dapat diambil
  kembali (`200`, isi utuh); `/uploads/receipts/<nama>.php` → `403`.
- **Pemindaian statis (grup H).** Tanpa float/double pada jalur uang, tanpa
  `number_format()`, tanpa `beginTransaction/commit/rollBack` manual, tanpa SQL
  di controller/view, seluruh output view lewat `Security::e()`, tanpa CDN,
  `app.min.css` 22.160 byte (< 30 KB), `app.js` memuat kompresor Canvas +
  `settlementForm`, `move_uploaded_file()` hanya di `SettlementService`, dan
  nama berkas kiriman klien tidak pernah menjadi path.
- **Integritas skema (grup I).** Konvensi `DECIMAL` ERD untuk 13 kolom yang
  disentuh, 9 tabel tetap utuh, `receipt_photo_url` VARCHAR + `settlement_id`
  CHAR(36) — **tanpa perubahan DDL**.

### 3.3 Verifikasi manual tambahan (di luar gate QC PHP)

Cermin kalkulasi di peramban (`settlementForm` pada `public/assets/js/app.js`)
dijalankan dengan **Node** memakai komponen yang sebenarnya (stub DOM
minimal):

```
3.1 normal      revenue Rp 37.544.000 · laba Rp 2.186.500 · 80:20 Rp 1.749.200/Rp 437.300
                alokasi saran Rp 35.157.500 / Rp 2.186.500 · tombol aktif      ✓
3.2 hari minus  net -Rp 80.000 · bagi hasil Rp 0/Rp 0 · alokasi Rp 1.850.000/Rp 0 · tombol aktif  ✓
3.3 pemulihan   pemulihan Rp 80.000 · net Rp 290.000 · alokasi Rp 2.010.000/Rp 290.000 · tombol aktif ✓
alokasi tidak pas / berat > holding / tanpa nota  -> tombol TERKUNCI          ✓
```

Angka pratinjau identik dengan hasil hitung peladen pada ketiga skenario, dan
ketiga kondisi terkunci berperilaku benar.

> Verifikasi Node ini **tidak** dijadikan bagian dari `run_qc.sh` agar gate tetap
> hanya bergantung pada PHP (hosting QC tidak dijamin punya Node.js) — sama
> dengan keputusan pada Fase 2.

---

## 4. Inventaris file

### 4.1 Baru

| Berkas | Peran |
|---|---|
| `app/Services/SettlementService.php` | `quote()` (murni) + `settle()` (atomik) + pipeline nota + mesin defisit |
| `app/Models/DailySettlement.php` | Daftar kolom berpenjaga + `insert()`/`find()`/`latest()`/`countForDate()`/`count()` |
| `app/Controllers/SettlementController.php` | Layar rekonsiliasi: GET (widget) & POST (PRG + flash) |
| `app/Views/operator/settlement.php` | Layar C: rekonsiliasi, unggah nota, pratinjau, Smart Auto-Split |
| `public/uploads/receipts/.gitignore` | Direktori ikut git, isi tidak |
| `tests/qc_fase_3.php` | Protokol QC Manual 3 otomatis (91 assertion) |

### 4.2 Diubah

`app/routes.php` (+2 route) · `app/Models/HuluTransaction.php`
(`holdingRowsForUpdate()`, `holdingTotals()`, `settleBatch()`, `countByStatus()`,
`STATUS_SETTLED`) · `app/Models/CashRegister.php` (`BRANKAS_NAME`,
`findBrankas()`, `lockByNameForUpdate()`, `lockByNamesForUpdate()`) ·
`app/Models/SystemSetting.php` (`getForUpdate()`, `set()`) ·
`app/Models/CashMutation.php` (docblock) · `public/assets/js/app.js`
(`compressImage()`, parser persen/nominal, komponen `settlementForm`) ·
`public/assets/css/app.min.css` (rebuild; 22.160 byte) ·
`app/Views/layouts/operator.php` (navigasi Kasir ↔ Rekonsiliasi) ·
`app/Views/dashboard/index.php` (tautan "Buka Rekonsiliasi Toko") ·
`public/uploads/.htaccess` (`RemoveHandler`/`RemoveType`) ·
`public/uploads/.gitignore` + `.gitignore` root (pengecualian `receipts/`) ·
`tests/bootstrap.php` (fixture settlement + `QcHttp` multipart) ·
`tests/server_router.php` (hardening `uploads/`) · `tests/run_qc.sh`
(+`qc_fase_3`, log `qc-fase3.log`) · `README.md` · `AGENTS.md`.

### 4.3 Dihapus

Tidak ada.

---

## 5. Deviasi dari rencana yang disetujui

| Area | Rencana | Yang dieksekusi | Alasan |
|---|---|---|---|
| Waktu kompilasi CSS | T1 | Akhir T5 | Tailwind JIT memindai `app/Views/**/*.php`; membangun lebih dulu akan menghasilkan CSS tanpa kelas layar baru |
| Saran Smart Auto-Split | Dirender server-side ke dalam kedua isian | Isian dirender **kosong**; "dua isian kosong" = pakai saran sistem, Alpine menyegarkan saran sampai operator menyunting | Saat halaman dibuka belum ada kadar/harga, jadi saran belum dapat dihitung tanpa mengarang angka; dengan cara ini form tetap benar tanpa JavaScript |
| Widget saldo brankas | Header sticky layout | Kartu ringkasan di layar rekonsiliasi | Header sudah memuat dua metrik; metrik ketiga berdesakan pada 360 px |
| Penguncian pos kas | `lockByNameForUpdate()` dipanggil berurutan | Ditambah `lockByNamesForUpdate()` yang mengurutkan nama sendiri | Urutan tetap menjadi milik model, bukan disiplin pemanggil |
| QC grup C | Paksa gagal di tengah transaksi (langsung ke service) | Lewat HTTP multipart dengan baris defisit dihapus | `is_uploaded_file()` hanya benar pada unggahan HTTP — versi langsung gagal di tahap berkas, bukan di transaksi |
| Deteksi baris baru | "baris terbaru" | `qc3_settlement_ids()` + `qc3_new_settlement()` | `created_at` presisi detik (O-1); pada 3.2→3.3 dua sesi lahir pada detik sama sehingga urutan tidak deterministik |
| Angka alokasi P3.16 | kas Rp 1.930.000 / brankas Rp 370.000 | kas Rp 2.010.000 / brankas Rp 290.000 | Salah hitung pada tabel rencana; rumus §4.4 (`brankas = laba bersih`) mengikat, dan slot uji `A.15` menegaskan invariantnya |

Tidak ada penyimpangan yang mengubah skema, `system_settings`, atau `docs/`.

---

## 6. Temuan teknis

1. **`is_uploaded_file()` tidak dapat dipalsukan.** Uji atomisitas yang
   menyentuh berkas wajib lewat HTTP; memanggil service langsung dengan
   `tmp_name` akan ditolak sebelum transaksi (aman, tetapi tidak menguji yang
   dimaksud).
2. **`created_at` presisi detik (item O-1) kembali menggigit.** Dua sesi
   settlement pada detik yang sama membuat "baris terbaru" acak — suite Fase 3
   pernah gagal sekali karena ini. Solusinya: mengenali baris baru lewat selisih
   ID, dan memfilter `mutation_type` saat membaca mutasi kas.
3. **Tailwind JIT mensyaratkan view sudah ada.** Kompilasi CSS harus menjadi
   langkah terakhir T5, bukan langkah pertama T1.
4. **Berkas uji tanpa GD.** Ekstensi `gd` tidak tersedia di mesin dev, sehingga
   `qc3_png()` membangun PNG (IHDR/IDAT/IEND + CRC) memakai `gzcompress()`
   supaya suite tetap portabel.
5. **Batas unggahan PHP lebih rendah dari rencana.** `upload_max_filesize=2M`
   di dev (rencana menyiapkan batas keras 4 MB). Berkas 3 MB ditolak PHP lebih
   dulu dengan pesan batas server — dua jalur, satu hasil: `422` tanpa tulisan.
6. **Validasi bisnis dapat menutupi uji parser.** Kasus parsing harus memakai
   beban aparat nol, karena berat kecil membuat pendapatan di bawah beban aparat
   sehingga yang menolak adalah aturan bisnis, bukan parser.
7. **`move_uploaded_file()` + `chmod 0644` cukup** untuk direktori uji; pada
   shared hosting direktori `public/uploads/receipts/` harus milik user web
   (pesan operator sudah eksplisit bila tidak writable).

---

## 7. Status Gate Penerimaan (rencana §6.3)

| Gate | Syarat | Status |
|---|---|---|
| **G-1** | 9 suite exit `0`; assertion naik dari 254 | ✅ 9/9, **345** assertion, 0 gagal |
| **G-2** | Tanpa warning/notice/deprecated | ✅ tidak ada `storage/logs/php-*.log` |
| **G-3** | `git status` bersih dari artefak | ✅ `tests/.tmp/` & `*.log` ter-gitignore; `public/uploads/receipts/` bersih (hanya `.gitignore`) |
| **G-4** | Angka via `Formatter`, output via `Security::e()` | ✅ diverifikasi QC H.4 + pemakaian `Formatter` di controller |
| **G-5** | Tidak ada perubahan skema/seed; `docs/` utuh | ✅ DDL, seed, dan `docs/` tidak disentuh |
| **G-6** | Verifikasi manual satu tutup buku | ✅ probe HTTP: GET layar 200 memuat 18,050 gr & modal Rp 35.157.500; POST → 302; baris DB, saldo meja/brankas, mutasi, dan berkas nota saling konsisten |

---

## 8. Item terbuka & langkah berikutnya

| # | Item | Status setelah Fase 3 | Tindakan |
|---|---|---|---|
| **A-1** (Fase 1) | QC baru berjalan pada MariaDB 12.3.2 | **Blocker go-live** | Arahkan `.env` ke MySQL 8.0 lalu ulangi `bash tests/run_qc.sh` |
| **O-1** | `hulu_transactions.created_at` presisi detik | Terkonfirmasi lagi (§6.2) | Naikkan ke `TIMESTAMP(3)` pada fase berikutnya (butuh pembaruan ERD + addendum) |
| **O-2** | Nominal beban aparat sebagai kunci `system_settings` | **Belum** — masih isian operator (saran Rp 200.000) | Tambahkan kunci + seed bila operator ingin mengubahnya tanpa mengetik |
| **O-3** | Isian `consumable_opex` | **Belum** — kolom tetap `0.00` | Jasa bakar sudah menutup kowi/gas |
| **O-4** | Helper `Money` bersama | **Belum** — aturan tetap milik `TellerService::quote()`; parser nominal dijaga kesetaraannya oleh QC B.4 | Ekstrak bila muncul konsumen uang ketiga |
| **O-5** | Lebih dari satu sesi tutup buku per hari | Diizinkan (tanpa `UNIQUE` pada `settlement_date`) | — |
| **O-6** | Retensi/backup berkas nota | **Belum** | Catat pada prosedur operasional hosting (ukuran disk) |
| **O-7** | Verifikasi `403` nyata pada Apache | Harness dev sudah setara (`tests/server_router.php`); QC H.10 + G.6 menegakkannya | Ulangi checklist Fase 6 di produksi |
| A-2 (Fase 1) | `database/install.php` masih ada | Belum berubah | Hapus dari hosting setelah instalasi |
| A-6 (Fase 1) | PWA | Belum berubah | `manifest.json`/`sw.js` masuk Fase 5 |

---

## 9. Yang **tidak** ada di Fase 3 (sesuai lingkup)

Waterfall/Pro-Rata Engine, kuota serapan modal, distribusi ke
`capital_wallets`/`profit_distributions`, penarikan dana H-7, dasbor blind
investor, waiver digital, ekspor laporan, dan PWA — semuanya Fase 4–5. Bagi
hasil 80 : 20 pada Fase 3 sengaja hanya pembukuan; dompet mitra belum disentuh
(QC P3.10c & P3.13 membuktikannya).

---

*Dokumen ini adalah laporan eksekusi, bukan kontrak. Kontraknya ada di
`plan/Plan_Fase_3_AurumFlow.md`.*
