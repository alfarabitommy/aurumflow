# Audit Sinkronisasi Dokumentasi — Fase 9 (OPEX & Otomatisasi Settlement)

| | |
|---|---|
| **Tanggal audit** | 2026-10-25 |
| **Pemicu** | Pemeriksaan paritas dokumentasi pasca-implementasi Fase 9 |
| **Lingkup** | Seluruh `*.md` di `docs/` (5 berkas) dan `plan/` (29 berkas), plus `AGENTS.md` dan `README.md` |
| **Di luar lingkup** | `audit/` (dokumen audit bisnis — sengaja beku sebagai catatan), kode & test (hanya diperiksa sebagai pembanding kebenaran) |
| **Metode** | Pemindaian teks (grep) 5 fakta kunci + pembacaan konteks + verifikasi silang ke kode/QC |
| **Hasil** | **8 kelompok temuan** + 3 temuan tambahan, **35 berkas terlacak** disunting (+1 laporan fase, +1 judul test), **0** sisa fakta basi pada dokumen normatif |
| **Gerbang akhir** | `bash tests/run_qc.sh` → **15/15 suite, 900 assertion, 0 gagal**, tanpa `storage/logs/php-*.log` |

---

## 1. Ringkasan Eksekutif

Implementasi Fase 9 sudah benar dan lulus QC, tetapi **dokumentasi tidak sepenuhnya mengikutinya**.
Audit ini menemukan bahwa pada pass dokumentasi awal hanya **3** berkas yang diperbarui
(`docs/AurumFlow Entity Relationship Diagram.md`, `AGENTS.md`, `README.md`), sedangkan **4 dokumen
normatif lain** — termasuk **PRD** dan **Roadmap/QC** — masih menggambarkan perilaku pra-Fase 9.

Temuan paling berisiko:

1. **PRD Modul 2 masih mendaftar “Beban Koordinasi Lapangan Hari Ini (Rp)” sebagai input transaksi**,
   padahal isian itu sudah dihapus dari kode. Dokumen aturan bisnis bertentangan dengan sistem.
2. **Roadmap (dokumen QC resmi) belum memiliki Fase 9 maupun Protokol QC Manual 9**, dan instruksi
   instalasinya masih menyebut “13 tabel”.
3. **Wireframe Layar C (UI/UX) masih menampilkan isian beban** yang sudah tidak ada, dan catatan
   implementasinya merujuk `SettlementController::COORD_FEE_FIELD` — konstanta yang sudah dihapus.
4. **AGENTS.md** masih menyebut tutup buku menyentuh “tujuh kelompok tulisan”; sejak Fase 9 menjadi
   **delapan** (penyerapan voucher).

Seluruh temuan sudah diperbaiki. Riwayat per fase di `plan/` **tidak ditulis ulang**; sebagai
gantinya setiap dokumen historis menerima banner status yang menyebut keadaan terkini (§ 6).

---

## 2. Checklist yang Diaudit

| # | Fakta yang harus konsisten | Nilai benar (Fase 9) | Sumber kebenaran |
|---|---|---|---|
| 1 | Jumlah tabel & nol `ALTER` | **14 tabel**, termasuk `operational_expenses` | `docs/…Entity Relationship Diagram.md` § 4, `database/schema.sql` |
| 2 | Versi shell aset | **`aurumflow-shell-v4`** | `public/sw.js` |
| 3 | Baseline QC | **15 suite / 900 assertion / 0 gagal** | `bash tests/run_qc.sh` |
| 4 | Logika settlement | `coord_fee` **dihapus** → voucher diserap otomatis; **`total_cash = pendapatan`** | `app/Services/SettlementService.php` |
| 5 | Tata letak UI | modal **`− Catat Beban Kas`** di `teller.php`, **bukan** halaman terpisah | `app/Views/operator/teller.php`, `app/routes.php` |

Perintah pemindaian yang dipakai:

```bash
grep -rn "13 tabel\|tiga belas\|SKEMA TOTAL: 13\|tabel ke-13\|11–13" docs/ plan/ AGENTS.md README.md
grep -rn "shell-v3\|CACHE_NAME.*v3"                              docs/ plan/ AGENTS.md README.md
grep -rn "14 suite\|853 assertion"                               docs/ plan/ AGENTS.md README.md
grep -rn "coord_fee\|beban aparat\|pendapatan − beban"            docs/ plan/ AGENTS.md README.md
grep -rn "operator/biaya\|halaman biaya\|halaman OPEX"            docs/ plan/ AGENTS.md README.md
grep -rn "tujuh kelompok\|kelompok tulisan"                       docs/ AGENTS.md README.md
```

---

## 3. Temuan Rinci

### T-1 — PRD v3.0 tidak mencerminkan mekanisme baru (tertinggi)

| Lokasi | Teks basi (sebelum) | Masalah |
|---|---|---|
| § 3.3 *Beban Koordinasi Lapangan & Keamanan* | Hanya dua butir: anggaran Rp 5 jt/bulan dan “pengurang laba kotor” | Tidak menjelaskan **bagaimana** beban dicatat; tidak menyebut kas keluar, voucher, atau `total_cash` |
| Modul 2 *Input Transaksi* | `• Beban Koordinasi Lapangan Hari Ini (Rp).` | Mengklaim ada isian manual yang **sudah dihapus** dari sistem |
| Modul 2 *Aksi Sistem* | `Laba Bersih = Laba Kotor − Beban Aparat − …` tanpa catatan kas | Pembaca menyimpulkan alokasi kas = pendapatan − beban (model lama) |
| § 7 catatan skema | “tabel 11–13” | Tabel ke-14 tidak tercatat |

**Perbaikan.** § 3.3 mendapat blok baru **“Mekanisme pencatatan (Fase 9 — wajib, menggantikan isian
manual)”** berisi empat jaminan: (a) beban = kas keluar saat diserahkan, (b) tanpa isian di layar
rekonsiliasi, (c) kas dialokasikan = pendapatan penuh, (d) beban > pendapatan ditolak `422`.
Modul 1 mendapat **Aksi Cepat Beban (Fase 9)**; Modul 2 memisahkan **input manual** vs **input
otomatis (voucher)** dan menambah invarian `Kas Meja + Brankas = Pendapatan Toko`.

### T-2 — Roadmap & Protocol QC belum memuat Fase 9

| Lokasi | Teks basi | Masalah |
|---|---|---|
| Instruksi instalasi (FASE 6B) | “**13 tabel**: sembilan tabel Fase 1, `bridge_loans`, dan `miners`/`miner_loans`/`miner_loan_ledger`” | Jumlah tabel salah; migrasi hanya M1/M2 |
| Struktur dokumen | Tidak ada `### FASE 9` maupun baris **Sprint 9** | Dokumen QC resmi berhenti di Fase 8 |
| Protokol QC Manual 3 | `• Beban Aparat / Lapangan : Rp 200.000` | Tidak menjelaskan bahwa nilai itu kini berasal dari voucher |
| Checklist Fase 8 | `CACHE_NAME dinaikkan ke aurumflow-shell-v3` | Benar secara historis, tetapi bisa dibaca sebagai keadaan sekarang |

**Perbaikan.** Instruksi instalasi menjadi **14 tabel** + **M1/M2/M3**; ditambahkan **FASE 9**
(9 butir checklist + **Protokol QC Manual 9** berisi 11 baris uji, seluruhnya `[x]`) dan **Sprint 9**
(estimasi total disesuaikan `~23–31 hari`); protokol 3 diberi anotasi sumber beban; baris `v3`
diberi catatan *“Versi shell terkini `aurumflow-shell-v4` — dinaikkan lagi oleh Fase 9”*.

### T-3 — SAD: peta arsitektur tidak lengkap

| Lokasi | Teks basi | Masalah |
|---|---|---|
| Struktur direktori `Models/` | 13 model (Fase 1–8) | `OperationalExpense.php` tidak ada |
| Struktur direktori `Services/` | 10 service | `ExpenseService.php` tidak ada |
| `Services/` komentar | `SettlementService.php # … tujuh kelompok tulisan` | Sejak Fase 9 menjadi **delapan** |
| `Views/operator/` komentar | “meja kasir (+ modal talangan)” | Modal beban tidak disebut |
| Catatan penempatan | Berhenti di Fase 7 | Alasan tidak membuat controller baru (allow-list dua permukaan) tidak tercatat |

**Perbaikan.** Dua berkas baru masuk peta; hitungan kelompok tulisan dikoreksi; ditambahkan
**Catatan penempatan (Fase 9)** yang menjelaskan `ExpenseService` sebagai *use-case* dan mengapa
`POST /operator/opex/pay` ditangani `TellerController::storeOpex()`.

### T-4 — UI/UX: wireframe menampilkan isian yang sudah tidak ada

| Lokasi | Teks basi | Masalah |
|---|---|---|
| Wireframe Layar C | `│ Potongan Koordinasi / Aparat Hari Ini │` + `[ Rp 200.000 ]` | Menggambarkan kontrol yang **tidak dirender lagi** |
| Catatan implementasi Layar C | “… dan `coord_fee` memakai asumsi PRD (`SettlementController::COORD_FEE_FIELD`)” | Merujuk konstanta yang **sudah dihapus** dari kode |
| Layar B | Tidak ada kartu/tombol beban | Operator/kasir tidak punya rujukan UI |
| Daftar layar | Berhenti di **B2** (modal talangan) | Modal beban tidak berdokumen |

**Perbaikan.** Wireframe Layar C: baris isian diganti blok **“BEBAN OPERASIONAL TERSERAP
(OTOMATIS)”** (daftar penerima + kategori + total + catatan “alokasi kas memakai pendapatan
PENUH”); catatan `COORD_FEE_FIELD` diganti **catatan implementasi Fase 9** berisi angka
(kas **35.357.500**, brankas **2.186.500**, laba tidak berubah) dan tautan bukti aritmetika;
Layar B mendapat catatan kartu; **Layar B3** baru ditambahkan lengkap dengan wireframe dan aturan
form (tanpa `:disabled`, `x-cloak`, validasi peladen).

### T-5 — ERD: kolom beban tanpa keterangan sumber

| Lokasi | Teks basi | Masalah |
|---|---|---|
| DDL `daily_settlements` | `COMMENT 'Biaya aparat/lapangan'`, `COMMENT 'Pengeluaran jamuan/gas'` | Tidak menjelaskan bahwa nilainya diisi dari voucher (dan pemisahan kategori) |

**Perbaikan.** Komentar kolom menyebut sumbernya; blok tabel mendapat penjelasan eksplisit
(`daily_coordination_fee` = non-konsumsi, `consumable_opex` = `consumable`, jumlahnya = total beban;
isian manual dihapus agar tidak dapat difiktifkan).

### T-6 — AGENTS.md: kontrak tutup buku kurang satu kelompok

| Lokasi | Teks basi | Masalah |
|---|---|---|
| Butir “Tutup buku …” | “**tujuh** kelompok tulisan”, daftar tanpa `operational_expenses` | Agen/dev dapat melewatkan penguncian & penyerapan voucher |
| Butir “Di luar lingkup” | Tidak memuat item OPEX terbuka | Pekerjaan tersisa tidak terlacak |

**Perbaikan.** Menjadi **delapan** kelompok + `OperationalExpense::lockUnabsorbedForUpdate()` dan
`OperationalExpense::absorb()` pada urutan langkah; daftar di luar lingkup ditambah lima item
(anggaran bulanan, lampiran kuitansi, beban dari pos lain, rekap OPEX, pembatalan voucher) dengan
rujukan ke O-9.1…O-9.5.

### T-7 — README: fakta instalasi, alur kas, dan baseline

| Lokasi | Teks basi | Masalah |
|---|---|---|
| Struktur direktori | `schema.sql # DDL ERD (13 tabel)` | Salah |
| § 4 instalasi | “menerapkan `schema.sql` (13 tabel ERD)” | Salah |
| § 6 QC | Tanpa baseline | Pembaca tidak tahu angka acuan |
| § 7.6 diagram alur kas | `operational_expenses` tidak ada | Alur beban tidak terlihat |
| Tabel tulisan DB | Tanpa baris beban & tanpa penyerapan voucher | Jalur kas non-emas tidak terdokumentasi |
| § 7.9 catatan kelompok | Berhenti di “kelompok ketujuh” (Fase 6) | Kelompok kedelapan tidak disebut |

**Perbaikan.** Semua butir di atas diperbaiki; ditambah baseline **15 suite / 900 assertion** pada
§ 6 dan catatan “kelompok kedelapan” pada § 7.9.

### T-8 — `plan/` (28 dokumen historis): fakta keadaan-saat-itu mudah disalahbaca

Dokumen-dokumen ini menyatakan **keadaan pada fasenya** (mis. `Summary_Fase_8`: “13 tabel”,
“14 suite / 853 assertion”, “`CACHE_NAME` v3”; `Plan_Kasbon`: target “10 → 13 tabel”). Secara
historis **benar**, tetapi pembaca/agen yang mencari keadaan sekarang dapat mengambil angka basi.

**Perbaikan (kebijakan, bukan penulisan ulang).** Setiap dokumen historis di `plan/` menerima
banner seragam di bawah judul H1:

> **Status dokumen (pasca-Fase 9 — sinkronisasi dokumentasi).** Seluruh angka dan perilaku di
> bawah ini — termasuk (bila ada) penyebutan **13 tabel** atau kurang, **14 suite / 853
> assertion**, `CACHE_NAME` **v3**, dan isian **`coord_fee`** — adalah **potret fase tersebut**,
> bukan keadaan sekarang. Keadaan terkini: **14 tabel** (termasuk `operational_expenses`, nol
> `ALTER`), `CACHE_NAME` **`aurumflow-shell-v4`**, **15 suite / 900 assertion / 0 gagal**, beban
> operasional dicatat sebagai **voucher kas** di meja kasir lalu diserap otomatis oleh tutup buku
> (`total_cash = pendapatan`). Rujukan terkini: `plan/Summary_Fase_9_OPEX_AurumFlow.md`.

Efek sampingnya menguntungkan: pencarian `14 tabel`, `shell-v4`, atau `900 assertion` kini
menemukan **kebenaran yang berlaku** di setiap dokumen, lengkap dengan konteksnya.

---

## 4. Temuan Tambahan (di luar checklist, tetap diperbaiki)

| # | Berkas | Temuan | Tindakan |
|---|---|---|---|
| X-1 | `plan/Plan_Kasbon_Petani_AurumFlow.md` | **Dua tabel markdown rusak**: pipe mentah di dalam backtick (`pickPercent(20\|30)`, `SELECT\|INSERT INTO\|…`) sehingga kolom bergeser saat dirender | Pipe di-escape (`\|`) |
| X-2 | `tests/qc_fase_8.php` (judul assertion) | Judul `J.*` masih menyebut “13 tabel” walau assertion-nya memeriksa `tabel **11–13**` | Judul diperjelas (hanya teks, assertion tidak berubah) |
| X-3 | `plan/Summary_Fase_9_OPEX_AurumFlow.md` § 7 | Daftar berkas berubah hanya memuat ERD, bukan 4 dokumen yang disinkronkan | Tabel diperluas (PRD, Roadmap, SAD, UI/UX, banner `plan/`) |

---

## 5. Berkas yang Disunting

| Kelompok | Berkas | Sifat perubahan |
|---|---|---|
| **Normatif — PRD** | `docs/AurumFlow Product Requirement Document v3.0.md` | § 3.3 (blok *Mekanisme pencatatan* baru), Modul 1, Modul 2, § 7 |
| **Normatif — Roadmap/QC** | `docs/AurumFlow Roadmap and QC Documents.md` | Instruksi instalasi, **FASE 9**, **Protokol 9**, Sprint 9, anotasi protokol 3 & `v3` |
| **Normatif — SAD** | `docs/AurumFlow System Architecture Document.md` | Struktur direktori (3 tempat), catatan penempatan Fase 9 |
| **Normatif — UI/UX** | `docs/AurumFlow UI UX Documents.md` | Layar B (catatan), **Layar B3** (baru), wireframe & catatan Layar C |
| **Normatif — ERD** | `docs/AurumFlow Entity Relationship Diagram.md` | Komentar tabel/kolom beban |
| **Aturan repo** | `AGENTS.md` | Delapan kelompok tulisan, item OPEX di luar lingkup |
| **Onboarding** | `README.md` | 14 tabel (2×), baseline, diagram alur, tabel tulisan DB, kelompok kedelapan |
| **Historis** | `plan/*.md` (28 berkas) | Banner status (9 baris masing-masing) |
| **Laporan fase** | `plan/Summary_Fase_9_OPEX_AurumFlow.md` | § 7 daftar berkas |
| **Test (teks saja)** | `tests/qc_fase_8.php` | Judul assertion `J.*` |

Total diff dokumentasi: **35 berkas terlacak, +680 / −78 baris** (`docs plan AGENTS.md README.md`).
Ditambah satu laporan fase yang belum terlacak (`plan/Summary_Fase_9_OPEX_AurumFlow.md`) dan satu
judul assertion di `tests/qc_fase_8.php` — keduanya di luar himpunan `git diff` di atas.

---

## 6. Mengapa Riwayat `plan/` Tidak Ditulis Ulang

Pilihan yang diambil: **banner status**, bukan penulisan ulang angka.

| Pendekatan | Konsekuensi |
|---|---|
| Tulis ulang `Summary_Fase_8` menjadi “14 tabel / v4 / 900” | **Memalsukan catatan**: Fase 8 memang membawa skema 10 → 13 dan shell v2 → v3. Menghapus fakta itu merusak jejak keputusan & ADDENDUM |
| Hapus dokumen historis | Kehilangan rasional desain (mis. mengapa `miner_loan_ledger` memakai `RESTRICT`) |
| **Banner status (dipilih)** | Riwayat utuh; fakta terkini selalu hadir; pencarian tidak menghasilkan kesimpulan basi |

---

## 7. Verifikasi

```bash
# 1. Tidak ada fakta basi pada dokumen normatif
grep -rn "13 tabel\|shell-v3\|853 assertion" docs/
#   → 0 hasil, kecuali satu baris checklist Fase 8 yang dianotasi
#     "(Versi shell terkini aurumflow-shell-v4 — dinaikkan lagi oleh Fase 9)"

# 2. Fakta Fase 9 hadir di tempat yang tepat
grep -rc "14 tabel"              docs/ AGENTS.md README.md plan/Summary_Fase_9_OPEX_AurumFlow.md
grep -rc "aurumflow-shell-v4"    docs/ AGENTS.md README.md plan/Summary_Fase_9_OPEX_AurumFlow.md
grep -rc "900 assertion"         AGENTS.md README.md plan/Summary_Fase_9_OPEX_AurumFlow.md
grep -rc "Catat Beban Kas"       docs/ AGENTS.md README.md plan/Summary_Fase_9_OPEX_AurumFlow.md
grep -rc "total_cash = pendapatan" docs/ AGENTS.md README.md plan/Summary_Fase_9_OPEX_AurumFlow.md

# 3. Tidak ada halaman OPEX terpisah yang didokumentasikan
grep -rn "operator/biaya" docs/ plan/ AGENTS.md README.md
#   → hanya 1 hasil: daftar alternatif yang DITOLAK pada Summary_Fase_9 § 8 (D-1)

# 4. `coord_fee` hanya muncul sebagai hal yang dihapus/historis
grep -rn "coord_fee" docs/ README.md AGENTS.md
#   → seluruh kemunculan berbunyi "dihapus" / "tidak ada lagi" / "seam uji"

# 5. Seluruh tabel markdown konsisten (pipe ter-escape)
#   pemindaian docs/ + plan/ + AGENTS.md + README.md → 0 blok tidak konsisten

# 6. Gerbang kelulusan
bash tests/run_qc.sh
#   → LULUS 15/15 · 900 assertion · 0 gagal · tanpa storage/logs/php-*.log
```

---

## 8. Sisa Referensi Historis yang Diterima (bukan diskrepansi)

| Berkas | Teks | Alasan diterima |
|---|---|---|
| `docs/AurumFlow Roadmap and QC Documents.md` | Fase 8: “`CACHE_NAME` dinaikkan ke `aurumflow-shell-v3`” | Fakta historis fase itu; sudah dianotasi “versi terkini v4” di baris yang sama |
| `plan/Summary_Fase_8_Kasbon_AurumFlow.md` | “13 tabel”, “14 suite / 853”, “v3” | Laporan fase; dilindungi banner status |
| `plan/Plan_Kasbon_Petani_AurumFlow.md` | Target “10 → 13 tabel”, “v3” | Rencana fase; dilindungi banner status |
| `plan/Summary_Fase_1…7` | Baseline suite fase masing-masing | Laporan fase; dilindungi banner status |
| `audit/Audit_Biaya_Koordinasi_AurumFlow.md` | Rekomendasi “Opsi B”, angka pra-implementasi | **Sengaja beku**: dokumen audit adalah dasar keputusan, bukan dokumentasi keadaan |

---

## 9. Rekomendasi Pencegahan

Diskrepansi ini muncul karena paritas dokumentasi **tidak dijaga otomatis** — hanya versi shell
yang dijaga assertion (`G.17`–`G.18` `qc_fase_6` mencocokkan `sw.js` ↔ `AGENTS.md`). Tiga usul
kecil, murah, dan berdampak:

| # | Usul | Bentuk |
|---|---|---|
| P-1 | Perluas penjagaan versi shell | Assertion `qc_fase_9` yang mencocokkan `sw.js` ↔ `README.md` ↔ `AGENTS.md` (saat ini README tidak dijaga) |
| P-2 | Penjaga fakta dokumen | Assertion yang memastikan `docs/AurumFlow Roadmap and QC Documents.md` memuat `FASE 9` dan `operational_expenses`, serta PRD memuat frasa `total_cash`/voucher — cukup 3–4 assertion, bukan pemindai semantik |
| P-3 | Checklist rilis fase | Tambahkan butir “**docs/ normative**” pada template `plan/Plan_Fase_N` (PRD, Roadmap, SAD, UI/UX, ERD) — pass dokumentasi awal Fase 9 hanya menyentuh ERD |

> Catatan: P-1…P-3 belum diimplementasikan pada audit ini karena lingkupnya dokumentasi.
> Ketiganya dapat ditambahkan ke `tests/qc_fase_9.php` pada perubahan berikutnya.

---

## 10. Kesimpulan

- **Implementasi tidak berubah** oleh audit ini: kode, skema, dan test hanya disentuh pada satu
  judul assertion (X-2). Gerbang QC tetap **15/15 · 900 assertion · 0 gagal**.
- **Dokumentasi normatif kini 100 % paritas** dengan Fase 9: 14 tabel (nol `ALTER`),
  `aurumflow-shell-v4`, 15/900, beban otomatis dari voucher, `total_cash = pendapatan`, dan modal
  `− Catat Beban Kas` di `teller.php` (bukan halaman baru).
- **Riwayat fase 1–8 tetap utuh** dan kini tidak dapat disalahbaca berkat banner status.
- Temuan paling penting untuk dicegah ke depan: **pass dokumentasi awal melewatkan PRD, Roadmap,
  SAD, dan UI/UX** — empat dokumen yang justru menjadi acuan desain & QC.
