# Summary Eksekusi Fase 2 — AurumFlow

> **Laporan eksekusi.** Mendampingi `plan/Plan_Fase_2_AurumFlow.md` (rencana +
> keputusan `dec-96114fafe2590e91`). Dokumen ini mencatat **apa yang benar-benar
> dieksekusi, apa yang diuji, dan apa yang menyimpang** — termasuk hal yang
> tidak berjalan sesuai rencana.

| Item | Nilai |
|---|---|
| Status | **Selesai & terverifikasi** |
| Fase | 2 — Meja Kasir Hulu & Arus Kas Lapangan |
| Runtime uji | PHP **8.3.6** (CLI) |
| Database uji | **MariaDB 12.3.2** — database `aurumflow_dev` (dari `.env`) |
| Database target | **MySQL 8.0 / InnoDB** (belum diverifikasi, lihat §8 A-1) |
| Frontend | Tailwind CSS **3.4.19** (dikompilasi lokal) + Alpine.js **3.17.4** (aset lokal) |
| Kode baru | 12 berkas kode/dokumen (3.392 baris) + 7 berkas aset (156 KB) |
| Diff | 18 berkas dalam diff: **17 diubah** (+983/−450) + **1 dihapus** |
| QC | **8 suite · 254 assertion · 0 gagal** (exit 0) |
| Skema | **tidak berubah** — 9 tabel ERD Fase 1 dipakai apa adanya |

---

## 1. Ringkasan hasil

Fase 2 menghadirkan jalur kas keluar pertama yang menyentuh uang nyata, plus
sistem desain "Obsidian Gold" untuk seluruh antarmuka.

1. **Meja kasir hulu** — `/operator/teller` (GET + POST) dengan kalkulasi
   langsung, pratinjau kas bersih keluar, target sentuh ≥ 52 px, angka
   monospace `tabular-nums`, widget saldo kas aktif (atas) dan 3 transaksi
   terakhir (bawah).
2. **Konfirmasi dengan menggeser** — komponen swipe-to-confirm (pointer events,
   ambang geser, handle dinonaktifkan saat memproses) plus jalur cadangan
   tombol submit bila Alpine.js tidak aktif.
3. **Transaksi atomik** — `TellerService::payCash()` mengunci baris kas
   (`SELECT … FOR UPDATE`), memverifikasi saldo, lalu menulis
   `hulu_transactions` (status `holding`) + memotong `cash_registers` +
   mencatat `cash_mutations` (`out`) dalam satu `Database::transaction()`.
4. **Pemisahan jasa bakar** — `flat_burn_fee` 20.000,00 dari
   `system_settings`, dibagi menjadi `operator_burn_cut` 10.000,00 dan
   `consumable_cut` 10.000,00 (sisa), **tanpa kunci setting baru**.
5. **Aritmetika uang tanpa float** — berat ke miligram, harga ke sen, hasil
   sebagai string desimal kanonik; server menghitung ulang dan mengabaikan
   nominal kiriman klien.
6. **Anti double-submit dua lapis** — swipe di sisi klien + token sekali-pakai
   `_once` di sisi peladen, di samping CSRF yang tetap diperiksa dua kali.
7. **Tema diterapkan ke seluruh view** — teller, dashboard, login, dan halaman
   error; `public/assets/css/app.css` Fase 1 dipensiunkan dan dihapus.
8. **Guard peran** — investor yang membuka URL kasir diarahkan ke `/dashboard`
   (ekspektasi "Akses URL Kasir Ilegal" pada Protokol QC Manual 5 sudah
   terpenuhi lebih awal).

---

## 2. Cara mereproduksi verifikasi

```bash
# Prasyarat: PHP 8.2+ (pdo_mysql, curl), MariaDB/MySQL, dan Node hanya bila
# ingin mengompilasi ulang CSS (aset hasil build sudah di-commit).

bash tests/run_qc.sh          # 8 suite; menulis ke database .env
php tests/qc_fase_2.php       # suite Fase 2 saja (Protokol QC Manual 2)

# Server pengembangan
php -S 127.0.0.1:8000 -t public tests/server_router.php
#   login operator  -> /dashboard -> "Buka Meja Kasir Hulu"
```

Keluaran lengkap tersimpan di `storage/logs/qc-fase2.log` (di-gitignore).

---

## 3. Hasil QC

| Suite | Assertion | Hasil | Cakupan |
|---|---:|---|---|
| `qc_pdo` | 20 | ✅ PASS | Koneksi, charset, timezone, transaksi commit/rollback |
| `qc_formatter` | 43 | ✅ PASS | Format Rupiah & gramatur |
| `qc_security` | 32 | ✅ PASS | XSS, password, CSRF, session, `uuidV4()` |
| `qc_seed` | 46 | ✅ PASS | Idempotensi DDL/seed, 9 tabel ERD, konvensi DECIMAL, guard installer |
| `qc_roadmap_protocol_1` | 6 | ✅ PASS | Protokol QC Manual 1 (1:1) |
| **`qc_fase_2`** | **61** | ✅ PASS | **Protokol QC Manual 2** + kalkulasi, parsing, atomisitas, saldo kurang, anti double-submit, guard peran, statis |
| `qc_sql_injection` | 15 | ✅ PASS | 8 payload injeksi, integritas data |
| `qc_routing` | 31 | ✅ PASS | Routing, CSRF, login/logout, cookie, header, proteksi file, aset |
| **Total** | **254** | **0 FAIL** | exit code 0 |

### 3.1 Protokol QC Manual 2 (dokumen Roadmap)

Isi saldo awal Kas Meja Bakar **Rp 50.000.000,00**, lalu dua transaksi berurutan
(dijalankan otomatis dengan angka persis seperti dokumen):

| Parameter Uji | Data Masukan | Hasil yang Diharapkan | Status |
|---|---|---|---|
| Nilai Tebus Kotor (QC 2.1) | 14,850 × Rp 1.950.000 | `Rp 28.957.500` | ✅ |
| Kas Keluar Bersih (QC 2.1) | Rp 28.957.500 − Rp 20.000 | `Rp 28.937.500` | ✅ |
| Alokasi Jasa Bakar | flat Rp 20.000 | `Rp 10.000` pengelola + `Rp 10.000` kowi/gas | ✅ |
| Status Data | `hulu_transactions` | kedua baris `holding`, `settlement_id` NULL | ✅ |
| Sisa Kas Meja (QC 2.1) | Rp 50.000.000 − Rp 28.937.500 | `Rp 21.062.500` | ✅ |
| Nilai Tebus Kotor (QC 2.2) | 3,200 × Rp 1.950.000 | `Rp 6.240.000` | ✅ |
| Kas Keluar Bersih (QC 2.2) | Rp 6.240.000 − Rp 20.000 | `Rp 6.220.000` | ✅ |
| Sisa Kas Meja (QC 2.2) | Rp 21.062.500 − Rp 6.220.000 | `Rp 14.842.500` | ✅ |
| Akumulasi Emas | 14,850 + 3,200 | `18,050 gr` (2 nota `holding`) | ✅ |
| Rekonsiliasi Fase 3 | 28.937.500 + 6.220.000 | `Rp 35.157.500` modal belanja hulu | ✅ |

Nilai disimpan sebagai string desimal kanonik (`'28937500.00'`, `'18.050'`)
sehingga assertion membandingkan angka presisi, bukan hasil pembulatan tampilan.

### 3.2 Bukti tambahan di luar protokol manual

- **Atomisitas (SAD §4.1).** Rantai tiga tulisan yang sama dengan `payCash()`
  digagalkan di ujung transaksi: baris hulu, saldo kas, dan mutasi semuanya
  tergulung (0 baris, saldo utuh). Ditambah dua jalur penolakan nyata: pos kas
  tidak ditemukan (gagal sebelum tulisan) dan saldo tidak cukup (gagal setelah
  baris kas dikunci) — keduanya tanpa efek samping.
- **Nominal klien diabaikan.** `payCash()` dipanggil dengan `net_cash_paid`,
  `flat_burn_fee`, dan `gross_tebus_total` palsu; yang tersimpan tetap hasil
  hitung server (Rp 1.950.000 / Rp 20.000 / Rp 1.930.000).
- **Anti double-submit.** POST sah → 1 baris; replay token `_once` yang sama →
  0 baris baru, saldo tidak berubah, operator menerima pesan "transaksi ganda
  diabaikan"; token lama tetap mati setelah halaman di-render ulang; POST tanpa
  `_once` atau tanpa CSRF → `419`.
- **Guard peran.** Investor: `GET /operator/teller` → `302 /dashboard`;
  `POST /operator/teller` dengan CSRF sah milik sesinya → `302` tanpa
  mencatat transaksi.
- **Parsing input lapangan.** `'14,850'`, `'14.850'`, `'14.850 gr'` → 14,850 gr;
  `'1.950.000,50'` → Rp 1.950.000,50; ditolak: kosong, nol, negatif, dua
  pemisah, >3 desimal, >kapasitas kolom, dan harga dengan ribuan salah.
- **Pembulatan.** `0,333 gr × Rp 1.950.000,33` → `Rp 649.350,11`
  (half away from zero pada sisa sub-sen).
- **Pemindaian statis Fase 2.** Tanpa `float`/`double` pada jalur uang, tanpa
  `number_format()`, tanpa `beginTransaction()/commit()/rollBack()` manual,
  tanpa SQL di controller/view, seluruh output view lewat `Security::e()`,
  tanpa aset CDN, dan tidak ada referensi `app.css` yang tertinggal.
- **Kualitas sinyal:** tidak ada `E_WARNING`/`E_NOTICE`/`E_DEPRECATED` selama
  suite berjalan (tidak ada `storage/logs/php-*.log`).

### 3.3 Verifikasi manual tambahan (di luar gate QC PHP)

Cermin format/parser di peramban dijalankan dengan Node (`node --check` lulus)
memakai komponen `tellerForm()` yang sebenarnya:

```
netLabel    = Rp 28.937.500   (transaksi QC 2.1)  ✓
netLabel    = Rp 6.220.000    (transaksi QC 2.2)  ✓
fee off     = Rp 28.957.500   (net = gross saat toggle jasa bakar nonaktif) ✓
14.850      = 14,850 gr       (bukan 14.850 gr)   ✓
saldo kecil = canPay false, insufficient true     ✓
input palsu = net Rp 0, canPay false              ✓
```

Pemeriksaan HTTP manual juga mengonfirmasi halaman kasir memuat
`x-data="tellerForm({"fee":2000000,"saldo":…})"` (ter-decode benar oleh peramban),
token `_once` 64 karakter, kotak centang jasa bakar `checked`, komponen geser,
serta daftar 3 transaksi terakhir dengan format `14,850 gr` / `Rp 28.937.500`.

> Verifikasi Node ini **tidak** dijadikan bagian dari `run_qc.sh` agar gate tetap
> hanya bergantung pada PHP (hosting QC tidak dijamin punya Node.js).

---

## 4. Inventaris file

### 4.1 Baru — kode & dokumen (12 berkas, 3.392 baris)

| Berkas | Baris | Peran |
|---|---:|---|
| `app/Services/TellerService.php` | 563 | Kalkulasi uang (integer) + transaksi atomik kas |
| `app/Controllers/TellerController.php` | 249 | Layar kasir: GET (widget) & POST (PRG + flash) |
| `app/Models/CashRegister.php` | 86 | `TELLER_NAME`, lock `FOR UPDATE`, ubah saldo |
| `app/Models/HuluTransaction.php` | 126 | Insert hulu, riwayat, akumulasi holding |
| `app/Models/CashMutation.php` | 73 | Mutasi kas + rekap untuk QC |
| `app/Views/operator/teller.php` | 268 | Layar kasir (swipe, kalkulasi, riwayat) |
| `app/Views/layouts/operator.php` | 98 | Layout kasir + saldo sticky |
| `tests/qc_fase_2.php` | 1273 | Protokol QC Manual 2 otomatis |
| `resources/css/tailwind.input.css` | 214 | Sumber Tailwind (token, komponen, font) |
| `tailwind.config.js` | 64 | Token "Obsidian Gold" |
| `plan/Plan_Fase_2_AurumFlow.md` | — | Rencana eksekusi Fase 2 |
| `plan/Summary_Fase_2_AurumFlow.md` | — | Laporan ini |

### 4.2 Baru — aset frontend (7 berkas, ±156 KB)

| Berkas | Ukuran | Catatan |
|---|---:|---|
| `public/assets/css/app.min.css` | 20.755 byte | **Hasil build** Tailwind, di-commit; target SAD < 30 KB terpenuhi |
| `public/assets/js/alpine.min.js` | 55.891 byte | Alpine.js 3.17.4, lisensi dipertahankan, tanpa CDN |
| `public/assets/fonts/plus-jakarta-sans-latin-{400,600,700}-normal.woff2` | 36.248 byte | Font antarmuka (3 berat) |
| `public/assets/fonts/jetbrains-mono-latin-{400,700}-normal.woff2` | 43.076 byte | Font data finansial & timbangan (2 berat) |

Catatan: `public/assets/js/app.js` **bukan** berkas baru — ia ditulis ulang dari
Fase 1 (bersama `public/assets/css/app.css` yang dihapus).

### 4.3 Diubah (17 berkas)

`app/routes.php` (+2 route) · `app/Controllers/BaseController.php`
(`requireRole()`, `flash()` + konsumsi `$flash`) · `app/Helpers/Security.php`
(token `_once`) · `app/Views/layouts/app.php`, `layouts/auth.php`,
`auth/login.php`, `dashboard/index.php`, `errors/{404,500,error}.php` (tema
"Obsidian Gold") · `public/assets/js/app.js` (378 baris) · `.htaccess`
(deny `resources/`) · `tests/bootstrap.php` (`QcFixture::resetTellerState()`,
`registerBalance()`, `QcHttp::fieldFrom()`) · `tests/qc_routing.php` (aset
statis) · `tests/run_qc.sh` (+`qc_fase_2`, nama log, ringkasan) · `README.md` ·
`AGENTS.md`.

### 4.4 Dihapus (1 berkas)

`public/assets/css/app.css` (2,9 KB) — digantikan `app.min.css`; QC 5.18
memastikan berkas lama benar-benar tidak lagi tersedia (404) dan tidak ada view
yang menunjuknya.

---

## 5. Deviasi dari rencana yang disetujui

| Area | Rencana | Yang dieksekusi | Alasan |
|---|---|---|---|
| Konfigurasi Alpine | Nilai inline `{fee: …, saldo: …}` pada `x-data` | JSON dari controller, dicetak lewat `Security::e()` | Menjaga kontrak "tidak ada output dinamis tanpa escaping" (QC H.4) sekaligus menyimpan data view di controller |
| Status toggle jasa bakar | Ikut pada konfigurasi Alpine | Dibaca dari atribut `checked` (render server) | Tanpa JS, status toggle dari server tetap benar setelah kegagalan validasi (422) |
| `Decimal(…)->float` di QC | — | Test memakai `TellerService::moneyToCents()` dan normalisasi string | Rencana melarang float pada jalur uang; test tidak boleh jadi pengecualian diam-diam |
| Pemindai statis | `qc_scan_lines()` | Pemindai lokal `qc2_scan()` untuk berkas Fase 2 | Predikat `qc_scan_lines()` tidak menerima path, sehingga `(float)` di `Formatter.php` (sah untuk tampilan) akan selalu ikut tertangkap |
| Verifikasi cermin JS | — | Manual dengan Node, didokumentasikan (§3.3) | Gate QC tetap PHP-only (hosting tanpa Node) |

Tidak ada penyimpangan yang mengubah skema, `system_settings`, atau `docs/`.

---

## 6. Temuan teknis

1. **`Formatter::parseGram()` tidak boleh dipakai untuk field berat.** Pada
   format Indonesia titik adalah pemisah ribuan, sehingga `'14.850'` terbaca
   14.850 gr (1000× lipat). Field lapangan memakai aturan sendiri (satu pemisah
   = desimal) di `TellerService` dan dicerminkan di `app.js`.
2. **`?>` di dalam komentar PHP menutup mode PHP.** Bentuk `<?= … ?>` yang
   muncul di komentar `tests/qc_fase_2.php` menyebabkan parse error; komentar
   ditulis ulang tanpa urutan karakter tersebut.
3. **PHP mengubah kunci array numerik menjadi integer.** Tabel kasus uji
   `['14' => '14.000']` berubah menjadi kunci `int`, sehingga parameter `string`
   pada `quote()` menerima `int`. Tabel uji memakai daftar pasangan
   (`[['14', '14.000'], …]`).
4. **Kelas CSS dapat memicu pemindai SQL.** `select-none` cocok dengan pola
   `SELECT` pada pemindaian yang tidak case-sensitive; pemindaian SQL dibuat
   case-sensitive (SQL proyek selalu huruf besar).
5. **`created_at` berpresisi detik (item O-1) terbukti nyata.** Banyak transaksi
   pada detik yang sama membuat urutan "3 terakhir" tidak deterministik, jadi
   assertion widget bersifat himpunan (memuat alias yang benar-benar ada di
   riwayat), bukan urutan. Rekomendasi tetap: naikkan presisi kolom sebelum
   Fase 3.
6. **Komentar `//` pada pemindaian statis memang dilewati** (`qc_scan_lines()`
   dan `qc2_scan()`), sehingga pelanggaran tidak dapat disembunyikan di
   komentar — perilaku ini dipertahankan pada pemindai baru.

---

## 7. Status Gate Penerimaan (rencana §6.3)

| Gate | Syarat | Status |
|---|---|---|
| **G-1** | 8 suite exit `0` | ✅ 8/8, 254 assertion, 0 gagal |
| **G-2** | Tanpa warning/notice/deprecated | ✅ tidak ada `storage/logs/php-*.log` |
| **G-3** | `git status` bersih dari artefak | ✅ hanya berkas yang direncanakan; `tests/.tmp/` & `*.log` ter-gitignore |
| **G-4** | Angka via `Formatter`, output dinamis via `Security::e()` | ✅ diverifikasi QC H.4 dan pemakaian `Formatter` di controller |
| **G-5** | Tidak ada perubahan skema/seed; `docs/` utuh | ✅ DDL & seed tidak disentuh |
| **G-6** | Verifikasi manual layar kasir | ✅ probe HTTP: saldo header, komponen geser, 3 baris riwayat konsisten |

---

## 8. Item terbuka & langkah berikutnya

| # | Item | Status setelah Fase 2 | Tindakan |
|---|---|---|---|
| **A-1** (Fase 1) | QC baru berjalan pada MariaDB 12.3.2 | **Blocker go-live** | Arahkan `.env` ke MySQL 8.0 lalu ulangi `bash tests/run_qc.sh` |
| **O-1** | `hulu_transactions.created_at` presisi detik | Terkonfirmasi (§6.5) | Naikkan ke `TIMESTAMP(3)` sebelum Fase 3 (butuh pembaruan ERD + addendum) |
| **O-2** | Guard `_once` | **Diterapkan & diuji** | — |
| **O-3** | PRG + flash, 422 + input terpelihara | **Diterapkan** | — |
| **O-4** | Font self-host | **Diterapkan** (79 KB, tanpa CDN) | — |
| **O-5** | Tampilkan pembagian jasa bakar | **Diterapkan** (baris informasi di layar kasir) | — |
| **O-6** | Kas aktif via konstanta nama | **Diterapkan** (`CashRegister::TELLER_NAME`) | Bila kelak ada multi-meja, pindahkan ke `system_settings`/ERD |
| A-2 (Fase 1) | `database/install.php` masih ada | Belum berubah | Hapus dari hosting setelah instalasi |
| A-6 (Fase 1) | PWA | Sebagian tertutup (Tailwind + Alpine selesai) | `manifest.json`/`sw.js` masuk Fase 5 |

---

## 9. Yang **tidak** ada di Fase 2 (sesuai lingkup)

Likuidasi toko & mesin settlement, unggah/kompresi nota, Waterfall & Pro-Rata
Engine, kuota serapan modal, penarikan dana H-7, dasbor blind investor, waiver
digital, ekspor laporan, dan PWA — semuanya Fase 3–5. Skema 9 tabel dan seluruh
kolom audit sudah tersedia, sehingga fase berikutnya tidak memerlukan migrasi
struktur.

---

*Dokumen ini adalah laporan eksekusi, bukan kontrak. Kontraknya ada di
`plan/Plan_Fase_2_AurumFlow.md`.*
