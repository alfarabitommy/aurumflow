# Summary Eksekusi — Perbaikan UX & Pengiriman Form Layar Rekonsiliasi & Kasir

> **Laporan eksekusi** (bukan rencana). Rencana & ADDENDUM rinci: `plan/Plan_Fix_Settlement_UI.md`.
> Proyek: **AurumFlow** · Cakupan: `/operator/settlement` (`settlementForm`) + `/operator/teller` (`tellerForm`).
> Status: **SELESAI & TERVERIFIKASI (gate PHP + probe Alpine)** · `2026-09-30`.
> Keputusan pengguna: `dec-6a9126c94bc6beb8` (cakupan & strategi pre-fill), `dec-77e076f7cb075385` (perbaikan gerbang QC + commit probe).

| Item | Nilai |
|---|---|
| Gate QC | **9 suite · 355 assertion · 0 gagal · exit 0** (sebelumnya 9 suite · 345 assertion) |
| Assertion baru | **+10**: `qc_fase_3` 91 → 97, `qc_fase_2` 61 → 65 |
| Probe lapis Alpine | `tests/manual/js_form_init_check.js` — **29 assertion, 0 gagal** (di luar `run_qc.sh`) |
| DDL / `system_settings` | **Tidak berubah** (nol migrasi); tidak ada kunci parameter baru |
| Jalur uang | **Tidak disentuh**: `SettlementService`, `TellerService`, `SettlementController::readInput()`, aturan validasi tetap utuh |
| Berkas disentuh | 2 view, 1 berkas JS, 2 suite QC, 1 harness, 1 probe baru, 5 dokumen |

---

## 1. Ringkasan hasil

Bug uji manual Fase 3 ("peladen menolak form dengan galat *wajib diisi* padahal UI terlihat terisi")
ternyata **tiga cacat sekeluarga**, bukan satu:

| # | Cacat | Perbaikan |
|---|---|---|
| **A** | **Ilusi placeholder** — `density_pct`, `shop_price_per_g`, `kas_split`, `brankas_split` punya `value=""` tetapi `placeholder` menyerupai data (`93,50`, `2.080.000`, `35.157.500`, `2.186.500`) yang disalin apa adanya dari wireframe Layar C | Semua placeholder numerik → awalan `mis. `; `sold_grams` (yang memang selalu terisi) placeholder-nya **dihapus**; label kadar & harga diberi penanda "wajib" |
| **B** | **`$refs` dibaca di `init()`** — `init()` membaca nilai awal lewat `this.$refs.form`, padahal Alpine 3.17.4 baru mendaftarkan `x-ref` **setelah** `init()` komponen induk berjalan (penelusuran DOM pre-order). Model mulai dari `''` lalu `x-model` menghapus `value="…"` yang dirender peladen | Pembaca bersama `formField()`/`formFieldValue()`/`formFieldChecked()` memakai `querySelector` (`$refs` tetap sebagai cadangan) + `x-model.fill` pada isian bernilai awal |
| **C** | **`restoreAutoSplit(true)` di `init()`** — memaksa saran sistem menimpa isian alokasi yang dikembalikan peladen pada `422` (baru terlihat setelah B diperbaiki) | `init()` memakai `restoreAutoSplit(false)`; tombol "Saran otomatis" tetap `true` |

Dampak yang hilang setelah perbaikan: field yang terlihat terisi tetapi terkirim kosong, pratinjau
kalkulasi terkunci di `Rp 0`, tombol submit `disabled` tanpa penjelasan, dan **isian operator (termasuk
alokasi manual) hilang setelah `422`** — pada layar rekonsiliasi **dan** layar kasir.

> Catatan cakupan: `type="number"` yang dituduh pada laporan **tidak pernah ada** di `app/Views/` —
> keenam isian sudah `type="text"` + `inputmode` sejak semula. Yang dilakukan: mengunci aturan itu
> dengan pemindaian QC (`H.13`/`H.14`/`H.10`), bukan mengubahnya.

---

## 2. Akar penyebab (dengan bukti)

| Bukti | Isi |
|---|---|
| `public/assets/js/alpine.min.js` (`version:"3.17.4"`) | `function L(e,t){…t(e,()=>r=!0)…anak}` (pre-order) · `p("data",…)` menjalankan `init()` sinkron · `si.inline` (`x-ref`) baru mendaftar saat elemennya ditangani · `$refs` mememo-proxy `_x_refs` pada akses pertama · `Li(e,t)` untuk input teks mengeksekusi `e.value = t` |
| `app/Services/SettlementService.php` | `:121` ("Total berat dijual… wajib diisi"), `:125` ("Harga jual riil… wajib diisi"), `:737` ("Kadar timbang air… wajib diisi") — `coord_fee` kosong **sah** (`:659-663`) sehingga tidak pernah menjadi sumber galat |
| Mengapa lolos sebelumnya | HTML tetap memuat `value="18,050"` (hanya **properti DOM** yang dihapus), sehingga QC PHP, `QcHttp`, dan probe HTTP Fase 3 tidak dapat melihatnya; verifikasi Node Fase 3 memanggil metode tanpa menirukan urutan directive Alpine |

---

## 3. Perbaikan yang diterapkan

| Berkas | Perubahan |
|---|---|
| `app/Views/operator/settlement.php` | Keenam isian angka: `type="text"` + `inputmode` + `autocomplete="off"` + **`x-model.fill`** (6×); `sold_grams` tanpa placeholder (nilai nyata selalu ada) + komentar alasannya; `density_pct`/`shop_price_per_g`/`coord_fee`/`kas_split`/`brankas_split` → `mis. …`; penanda "wajib" pada kadar & harga. **Tanpa** atribut `required` HTML5 (form `novalidate`, penegakan tetap di peladen) |
| `app/Views/operator/teller.php` | `x-model.fill` pada `dry_weight` & `buy_price_per_g`; placeholder → `mis. 14,850` / `mis. 1.950.000` (placeholder inisial petani tidak diubah — bukan angka) |
| `public/assets/js/app.js` | Helper baru `formField()` (baris ~279), `formFieldValue()` (~299), `formFieldChecked()` (~309) — `querySelector` pada root komponen, cadangan `$refs.form`; `tellerForm.init()` (~350) membaca berat/harga/status toggle dari DOM; `settlementForm.init()` (~785) membaca **keenam** field lalu `restoreAutoSplit(false)` (~793); `inputValue()` lama dihapus (tanpa kode mati); docblock `restoreAutoSplit()` menjelaskan `false` (init) vs `true` (tombol) |
| `tests/qc_fase_3.php` | Helper `qc3_input_block()` + assertion `G.1a`, `H.13`–`H.17` |
| `tests/qc_fase_2.php` | Helper `qc2_input_block()` + assertion `G.3a`, `H.10`–`H.12` |
| `tests/run_qc.sh` | `set -o pipefail` (temuan F-1 di bawah) |
| `tests/manual/js_form_init_check.js` | **Baru** — probe Node + stub DOM atas `app.js` asli (29 assertion), sengaja di luar `run_qc.sh` |
| `plan/Plan_Fix_Settlement_UI.md` | Rencana + **ADDENDUM lengkap** (deviasi V-1…V-7, temuan F-1…F-3, angka gate) |
| `plan/Plan_Fase_2_AurumFlow.md`, `plan/Plan_Fase_3_AurumFlow.md` | Satu baris ADDENDUM tiap fase yang merujuk perbaikan ini |
| `docs/AurumFlow UI UX Documents.md` | Catatan pada Layar C: angka wireframe adalah **ilustrasi**, bukan nilai awal form |
| `AGENTS.md`, `README.md` | 1 butir kontrak form (nilai awal dari peladen, bukan placeholder) + baseline 345 → 355 + perintah probe |

Dua nilai yang **sengaja tetap kosong** pada GET: `density_pct` & `shop_price_per_g` (fakta dari
nota/timbangan toko, wajib diisi operator) dan `kas_split`/`brankas_split` (kosong = "pakai saran
sistem", kontrak tanpa JavaScript pada `Plan_Fase_3 § 4.4`).

---

## 4. Verifikasi

### 4.1 Assertion baru

| ID | Suite | Yang dijaga |
|---|---|---|
| `G.1a` | qc_fase_3 | `GET /operator/settlement` merender pre-fill: `sold_grams` = gram holding (`18,050`), `coord_fee` = `200.000`, kadar & harga `value=""` |
| `H.13` | qc_fase_3 | Tidak ada `type="number"` pada kedua layar operator |
| `H.14` | qc_fase_3 | Keenam isian: `type="text"` + `inputmode` sesuai + `autocomplete="off"` + `x-model.fill`, form tetap `novalidate`, tanpa `required` |
| `H.15` | qc_fase_3 | Semua placeholder numerik berawalan `mis. `, dan `sold_grams` tidak memakai placeholder |
| `H.16` | qc_fase_3 | Paritas kunci lintas lapis: view (6 + `receipt_photo`) = `readInput()` = `defaultInput()` |
| `H.17` | qc_fase_3 | `settlementForm.init()` membaca DOM tanpa `$refs`, memakai `restoreAutoSplit(false)`, dan helper memakai `querySelector` + cadangan `$refs` |
| `G.3a` | qc_fase_2 | `GET /operator/teller` merender `checked` pada toggle jasa bakar dan membiarkan berat/harga kosong |
| `H.10`–`H.12` | qc_fase_2 | Layar kasir: tanpa `type="number"`; dua isian angka ber-`x-model.fill`; `tellerForm.init()` membaca DOM tanpa `$refs` |

### 4.2 Hasil gate

```text
qc_pdo 20 · qc_formatter 43 · qc_security 32 · qc_seed 46 · qc_roadmap_protocol_1 6
qc_fase_2 65 · qc_fase_3 97 · qc_sql_injection 15 · qc_routing 31
Total 355 assertion · 0 FAIL · setiap suite exit 0 · run_qc.sh exit 0
```

### 4.3 Uji negatif (bukti penjaga benar-benar menggigit)

Salinan repo di `/tmp` sengaja dirusak, lalu suite dijalankan di sana:

| Mutasi yang disuntikkan | Hasil |
|---|---|
| `type="number"` pada `sold_grams` | `H.13` + `H.14` **FAIL**; gate: `GAGAL : 1/9  qc_fase_3`, **exit 1** |
| `placeholder="93,50"` (tanpa `mis. `) | `H.15` **FAIL** |
| `this.$refs.form` dikembalikan ke `init()` | `H.17` **FAIL** |
| `x-model.fill` dihapus dari `dry_weight` | `H.11` (`qc_fase_2`) **FAIL**, suite exit 1 |

### 4.4 Probe lapis Alpine — `node tests/manual/js_form_init_check.js` (29/29)

| Yang dibuktikan | Hasil |
|---|---|
| Kode **lama** (`this.$refs.form`) saat `init()` | `''` → **akar masalah tereproduksi**, bukan sekadar teori |
| Kode **baru** membaca nilai peladen | `soldGrams='18,050'`, `coordFee='200.000'` |
| Pratinjau hidup tanpa mengetik ulang berat | pendapatan `Rp 37.544.000` · laba `Rp 2.186.500` · 80 : 20 `Rp 1.749.200`/`Rp 437.300` · alokasi saran `Rp 35.157.500`/`Rp 2.186.500` — **identik** dengan angka verifikasi manual `Summary_Fase_3 § 3.3` |
| Jalur `422` mempertahankan isian manual | `kas_split='30.000.000'` & `brankas_split='6.000.000'` tidak ditimpa saran sistem |
| Layar kasir | isian & toggle jasa bakar bertahan; net `Rp 28.957.500` (toggle mati) dan `Rp 28.937.500` (toggle hidup, = QC Manual 2.1) |

### 4.5 Gerbang QC diperbaiki (temuan F-1)

`tests/run_qc.sh` memakai `if php tests/X.php 2>&1 | tee -a log` — status pipeline diambil dari `tee`,
sehingga suite yang gagal tetap dihitung **LULUS** dan skrip `exit 0`:

- **Sebelum:** `qc_fase_3` gagal `H.16` (`exit 1`) tetapi skrip mencetak `LULUS : 9/9` + `exit 0`.
- **Sesudah** (`set -o pipefail`): dengan regresi disuntikkan → `GAGAL : 1/9  qc_fase_3` dan **`exit=1`**;
  pada repo bersih → `LULUS : 9/9` dan `exit 0`.

---

## 5. Inventaris berkas

**Baru:** `tests/manual/js_form_init_check.js`, `plan/Plan_Fix_Settlement_UI.md` (rencana + ADDENDUM),
`plan/Summary_Fix_Settlement_UI.md` (berkas ini).

**Diubah:** `app/Views/operator/settlement.php`, `app/Views/operator/teller.php`,
`public/assets/js/app.js`, `tests/qc_fase_3.php`, `tests/qc_fase_2.php`, `tests/run_qc.sh`,
`AGENTS.md`, `README.md`, `docs/AurumFlow UI UX Documents.md`,
ADDENDUM `plan/Plan_Fase_2_AurumFlow.md` & `plan/Plan_Fase_3_AurumFlow.md`.

**Tidak disentuh:** `database/schema.sql`, `database/seed.sql`, `docs/AurumFlow Entity Relationship
Diagram.md`, `app/Services/*`, `app/Controllers/SettlementController.php` (kunci field),
`app/Models/*`, `public/assets/css/app.min.css` (tidak ada kelas Tailwind baru yang dipakai).

---

## 6. Deviasi dari rencana yang disetujui

| # | Rencana | Eksekusi | Alasan |
|---|---|---|---|
| V-1 | `init()` membaca "empat field" (§ 5.2) | Membaca **keenam** field, termasuk dua alokasi | Tanpa membaca `kas_split`/`brankas_split`, `restoreAutoSplit(false)` tetap menimpa alokasi manual hasil `422` → Cacat C tidak tertutup. Tujuan paragraf yang mengikat |
| V-2 | `H.16` membandingkan himpunan `name` view dengan `readInput()` | View punya **7** field bernama; `receipt_photo` berasal dari `$_FILES` | Ditemukan oleh assertion itu sendiri saat pertama dijalankan; penulisan akhir menurunkan field unggahan dari `SettlementService::RECEIPT_FIELD` |
| V-3 | Checklist peramban § 7.2 | **Belum dijalankan**; diganti probe Node + stub DOM (29 assertion) yang kini di-commit | Tidak ada chromium/geckodriver/Playwright di lingkungan ini; preseden Fase 2/3: verifikasi JS di luar `run_qc.sh` |
| V-4 | Assertion HTTP dinamai `G.2` | Dinamai **`G.1a`** | `G.2` sudah terpakai di `qc_fase_3.php` |
| V-5 | Penanda "wajib" pada label | Memakai kelas yang sudah ada (`text-[0.68rem] … tracking-[0.16em] text-gold-glow`) | Tidak perlu kompilasi ulang Tailwind; tidak ada kelas baru yang belum ter-build |
| V-6 | — | Uji negatif pada salinan `/tmp` | Membuktikan penjaga baru menggigit (lihat § 4.3) |
| V-7 | Berkas: view/JS/QC saja | Ditambah `tests/run_qc.sh`, probe, `AGENTS.md`, `README.md`, ADDENDUM kedua fase | Temuan F-1 + dokumen yang menyebut angka/klaim harus ikut diperbarui |

---

## 7. Temuan teknis

| # | Temuan | Status |
|---|---|---|
| **F-1** | `tests/run_qc.sh` menyamarkan kegagalan suite (status pipeline dari `tee`), sehingga klaim `AGENTS.md` "exit 0 = lulus" tidak berlaku | **Diperbaiki** (`set -o pipefail`), bukti end-to-end § 4.5 |
| **F-2** | Setelah `422`, `splitTouched` tetap `false` → menyunting salah satu isian penjualan akan menyegarkan saran alokasi lagi | **Dibiarkan** (disengaja): menandainya `true` saat init akan mematikan saran otomatis pada kasus paling umum (operator tidak menyentuh alokasi). Solusi satu baris bila dikeluhkan |
| **F-3** | `storage/logs/php-2026-09-30.log` (18:50) sudah ada **sebelum** perbaikan: `Fatal error: Class "AurumFlow\Tests\QcFixture" not found` dari percobaan `php -r` sesi sebelumnya | **Tidak diubah** (di luar cakupan). Eksekusi ini **tidak** menghasilkan `php-*.log` baru; berkas aman dihapus |

---

## 8. Cara mereproduksi verifikasi

```bash
# 1) Gate QC (menulis ke database di .env — pastikan targetnya database uji)
bash tests/run_qc.sh            # harapan: LULUS 9/9, 355 assertion, 0 gagal, exit 0

# 2) Lapis Alpine (butuh Node; di luar run_qc.sh)
node tests/manual/js_form_init_check.js   # harapan: 29 assertion, 0 gagal, exit 0

# 3) Verifikasi manual di peramban (checklist § 7.2 dokumen rencana)
php -S 127.0.0.1:8000 -t public tests/server_router.php
#   login operator -> /operator/settlement
#   - Total Berat Dijual terisi 18,050 (nilai nyata, bukan placeholder)
#   - isi kadar & harga -> seluruh pratinjau langsung terhitung
#   - picu 422 -> isian (termasuk alokasi manual) tetap terisi setelah muat ulang
```

---

## 9. Item terbuka & langkah berikutnya

| # | Item | Catatan |
|---|---|---|
| **1** | Checklist peramban § 7.2 (V-3) | Satu-satunya verifikasi yang belum dijalankan; butuh peramban di mesin operator/pengembang. Probe Node menutup logika, bukan rendering nyata |
| **N-1** | Default kadar/harga jual dari sesi sebelumnya (`daily_settlements` terakhir) | Ditunda (opsi B `dec-6a9126c94bc6beb8` tidak diambil). Kandidat pertama bila keluhan input berulang muncul; menuntut catatan di `docs/` |
| **N-2** | Otomasi lapis peramban (Playwright/CDP) | Ditunda; tidak ada driver di lingkungan ini |
| **O-2** | Beban aparat sebagai `system_settings` | **Tetap terbuka** — masih default tampilan di `SettlementController::COORD_FEE_FIELD` |
| **F-2 / F-3** | Nuansa `splitTouched`; log `php-*.log` lama | Lihat § 7 |

---

## 10. Yang **tidak** berubah (penjaga lingkup)

- **Nol DDL/seed**: `docs/AurumFlow Entity Relationship Diagram.md`, `database/schema.sql`, `database/seed.sql` utuh; tidak ada kunci `system_settings` baru.
- **Jalur uang & validasi peladen tak tersentuh**: `SettlementService`, `TellerService`, dan pesan "wajib diisi" tetap sama — validasi itulah yang membuat cacat ini terdeteksi, sehingga tidak dilemahkan.
- **Kontrak tanpa JavaScript tetap berlaku**: dua isian alokasi kosong = "pakai saran sistem"; `SettlementController` tetap mengembalikan isian pada `422`; CSRF + token `_once` tidak disentuh.
- **Angka final tetap milik peladen**: pratinjau Alpine hanya cermin; seluruh nominal dihitung ulang di peladen.

---

# Perbaikan Lanjutan — Baris Dana Talangan pada Pratinjau Layar C (Fase 6)

> **Laporan eksekusi** · `2026-10-12` · Cakupan: `app/Views/operator/settlement.php` +
> `settlementForm` (`public/assets/js/app.js`).
> Status: **SELESAI & TERVERIFIKASI** — 12/12 suite, 702 assertion, 0 gagal; probe Alpine lulus.
> *(Bagian di atas adalah perbaikan UX 2026-09-30; bagian ini menyusul pada layar yang sama,
> sehingga berkas ini dipertahankan sebagai satu riwayat, bukan ditimpa.)*

## 1. Laporan & hasil penelusuran

**Gejala yang dilaporkan (uji manual Fase 6).** Pada pratinjau "Hasil Penjualan ke Toko", baris
**"Fee talangan"** dan **"Hak pengelola bersih"** disebut hilang saat operator mengisi data untung
(mis. kadar 93,50 dan harga Rp 2.080.000), dan hanya tampak ketika form kosong atau dalam keadaan
defisit.

**Hasil reproduksi (bukan asumsi).** Ekspresi `x-show` diambil **apa adanya dari markup** lalu
dievaluasi seperti Alpine (`new Function(…, 'with (scope) { return (expr) }')`) terhadap komponen
`settlementForm` yang nyata, dengan konfigurasi yang benar-benar dirender peladen
(`{"holding_mg":18050,…,"bridge_fee_cents":10000000,"bridge_count":1,…}`):

| Keadaan isian | Baris "Fee talangan" | Baris "Hak pengelola bersih" | Peringatan "Sebagian fee talangan…" |
|---|---|---|---|
| form kosong | tampil | tampil | tampil |
| hari sehat (93,50 / 2.080.000) | **tampil** | **tampil** | tersembunyi |
| hari minus (93,50 / 1.900.000) | tampil | tampil | tampil |

Artinya **kedua baris tidak pernah bergantung pada laba** — syaratnya memang `bridgeCount > 0`,
nilai dari peladen. Yang benar-benar berubah mengikuti laba adalah **peringatan** yang kalimatnya
diawali *"Sebagian **fee talangan** …"*, sehingga baris fee tampak "muncul/hilang" bersama
peringatan itu. Pemicu yang paling mungkin pada sesi operator: sesudah tutup buku dijalankan
(PRG memuat ulang halaman), talangannya sudah `settled` sehingga `bridge_count = 0` dan barisnya
memang seharusnya hilang — perubahan keadaan halaman, bukan reaksi terhadap isian.

**Tetap ada dua kelemahan nyata pada markup lama**, dan itulah yang diperbaiki:

1. **Niat tampil/tidak tidak punya satu pemilik.** Kondisi `bridgeCount > 0` ditulis **dua kali**
   pada dua baris, dan peringatan memakai `bridgeUncoveredCents > 0` — tanpa nama, sehingga
   pengikatan ulang salah satu baris ke angka laba (persis dugaan pada laporan) tidak tertangkap
   apa pun.
2. **Tanpa penjaga regresi.** Tidak ada assertion/probe yang memastikan baris fee tetap tampil pada
   hari sehat.

## 2. Akar masalah tambahan (ditemukan pada tinjauan independen perubahan ini)

**Cache aset PWA belum dinaikkan — inilah sebab paling mungkin operator melihat baris itu "hilang".**

`public/sw.js` menyajikan aset statis **cache-first** (`SHELL` memuat `./assets/css/app.min.css` dan
`./assets/js/app.js`) dan berkas itu sendiri sudah memuat aturan: *"PENTING saat mengubah daftar
aset: naikkan `CACHE_NAME` supaya cache lama dibuang pada `activate`"*
(`plan/Audit_Investor_Login_Empty_Box.md:190-193` menyatakan aturan yang sama untuk `app.js`).
Fase 6 mengubah `app.js` (+ `app.min.css`) **tanpa** menaikkan `CACHE_NAME` (tetap
`aurumflow-shell-v1`), sehingga pada peramban yang pernah membuka layar operator:

* HTML view **baru** (selalu dari jaringan) tetapi `app.js` **lama** dari cache;
* `settlementForm` versi lama tidak mengenal `bridgeCount`/`bridgeUncoveredCents`/`bridgeHasActive`;
* `x-show` pada elemen `x-cloak` menjadi tidak dapat dievaluasi → elemen **tidak pernah muncul**.

Itu persis gejala yang dilaporkan ("baris fee talangan tidak tampil"), dan ia juga menjelaskan
mengapa penelusuran markup tidak menemukan kondisi yang terikat laba: **kondisinya memang tidak
pernah salah — JS-nya yang basi**. QC hanya memastikan string `CACHE_NAME` ada
(`qc_fase_5` J.*), sehingga kekhilafan ini tidak tertangkap.

**Tindakan:** `CACHE_NAME` dinaikkan **v1 → v2** (`public/sw.js`, beserta komentar alasannya) dan
dijaga dua assertion baru: `qc_fase_6` **G.17** (SW mem-precache `app.js` + `app.min.css` dengan
versi v2) dan **G.18** (versi shell satu pemilik: `AGENTS.md` menyebut versi yang sama dengan
`sw.js`). Aturannya kini juga tercatat sebagai kontrak di `AGENTS.md` dan `README.md` § 7.12:
**setiap perubahan `app.js`/`app.min.css` wajib menaikkan `CACHE_NAME`**.

> Konsekuensi operasional (dicatat agar tidak mengejutkan): operator yang perambannya masih memegang
> shell v1 perlu **satu kali muat ulang** setelah deploy supaya service worker baru mengambil alih
> (aset lama dibuang pada `activate`).

## 3. Perbaikan

| Berkas | Perubahan |
|---|---|
| `public/assets/js/app.js` (`settlementForm`) | Dua getter bernama sebagai **satu pemilik keputusan**: `bridgeHasActive` (`bridgeCount > 0`, dari peladen — **tidak** bergantung laba) dan `bridgeHasUncovered` (`bridgeUncoveredCents > 0`, satu-satunya yang boleh mengikuti laba). Tidak ada perubahan aritmetika: `bridgeAbsorbedCents`/`operatorTakeHomeCents`/label tetap sama |
| `app/Views/operator/settlement.php` | Kedua baris memakai `x-show="bridgeHasActive"`; peringatan memakai `x-show="bridgeHasUncovered"` (terpisah, tidak menular ke baris). Komentar markup menjelaskan invariannya |
| `tests/qc_fase_6.php` | Assertion baru **G.13–G.16** (gerbang `run_qc.sh`): kedua baris memakai kondisi talangan-aktif yang **sama**, kondisinya tidak menyebut `operatorCents`/`netCents`/`Uncovered`, peringatan terpisah, dan getter pemiliknya benar-benar ada di `app.js`. `G.7` diperbarui ke kondisi bernama |
| `tests/manual/js_bridge_fee_check.js` | Bagian **4 (regresi UI)**: mengevaluasi ekspresi `x-show` **dari markup** untuk tiga keadaan isian (kosong, hari sehat, hari minus) — baris harus `true` di ketiganya, peringatan hanya `true` saat fee tak tertutup — plus nilai baris pada hari sehat (`- Rp 100.000`, `Rp 1.649.200`, pool `Rp 437.300`) dan kasus tanpa talangan |
| Dokumen | `docs/AurumFlow UI UX Documents.md` (Layar C — catatan invarian), `AGENTS.md` (kontrak mudah dilanggar + baseline 702), `README.md` (tabel QC & probe) |

**Tidak disentuh:** `SettlementService`, `SettlementController`, `bridge_fee_cents`/`bridge_count`
pada `alpineConfig`, isi `app.min.css` (kelas yang dipakai tidak berubah — hanya **versi cache**
asetnya yang dinaikkan), dan seluruh jalur uang.

## 4. Bukti gate

```
bash tests/run_qc.sh                          → exit 0 · 12/12 suite · 704 assertion · 0 gagal
  qc_fase_6                                   → 105/105  (sebelumnya 99)
node tests/manual/js_bridge_fee_check.js      → 63/63    (sebelumnya 45)
node tests/manual/js_form_init_check.js       → 47/47
node tests/manual/js_capital_simulator_check.js → 38/38
node tests/manual/js_investor_check.js        → 45/45
```

**Uji negatif (bukti penjaga punya gigi).** Bug yang dilaporkan disuntikkan kembali
(`x-show="bridgeUncoveredCents > 0"` pada kedua baris) → probe menolak dengan **4 kegagalan**,
termasuk `regresi UI [hari sehat]: baris fee tetap tampil → false`. Setelah dikembalikan, seluruh
probe lulus lagi; perubahan disuntikkan hanya sementara (berkas dipulihkan dari salinan, tidak
tersisa di repositori).

## 5. Catatan untuk Fase 6B

* Baris "Fee talangan" pada hari sehat menampilkan **porsi yang diserap** (`min(fee, hak)`), bukan
  seluruh fee — itu disengaja agar aritmetika di layar menutup
  (`Hak pengelola 80 % − fee diserap = Hak pengelola bersih`). Selisihnya, bila ada, muncul sebagai
  peringatan `fee_uncovered` — satu-satunya elemen yang wajar berubah mengikuti laba.
* Bila kelak baris ini perlu disembunyikan lagi, ubah **`bridgeHasActive`** (satu tempat);
  jangan menulis ulang kondisi di markup — `qc_fase_6` G.13–G.16 akan menolaknya.
