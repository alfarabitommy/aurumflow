# Plan Fase 3 — AurumFlow

> **Status dokumen (pasca-Fase 9 — sinkronisasi dokumentasi).** Seluruh angka dan perilaku di
> bawah ini — termasuk (bila ada) penyebutan **13 tabel** atau kurang, **14 suite / 853
> assertion**, `CACHE_NAME` **v3**, dan isian **`coord_fee`** — adalah **potret fase tersebut**,
> bukan keadaan sekarang. Keadaan terkini: **14 tabel** (termasuk `operational_expenses`, nol `ALTER`),
> `CACHE_NAME` **`aurumflow-shell-v4`**, **15 suite / 900 assertion / 0 gagal**, beban operasional
> dicatat sebagai **voucher kas** di meja kasir lalu diserap otomatis oleh tutup buku
> (`total_cash = pendapatan`). Rujukan terkini: `plan/Summary_Fase_9_OPEX_AurumFlow.md`.


> **Dokumen Perencanaan Eksekusi**
> Proyek: **AurumFlow** — sistem manajemen operasional privat untuk perdagangan emas aluvial dengan settlement **T+1**.
> Fase: **3 — Likuidasi Toko Emas & Mesin Settlement Harian**
> Status: `APPROVED FOR PLANNING` · **belum ada kode PHP yang dieksekusi.**

| Item | Nilai |
|---|---|
| Runtime | Native PHP **8.2+** (`declare(strict_types=1)`), tanpa framework, tanpa Composer/`vendor/` |
| Frontend | Tailwind CSS **3.4.19** (CLI, dikompilasi lokal) + Alpine.js **3.17.4** (aset lokal, tanpa CDN) + kompresi **HTML5 Canvas** |
| Database | MySQL **8.0 / InnoDB** — **9 tabel ERD tidak berubah, nol DDL** |
| Target hosting | Shared hosting / cPanel (tanpa Node.js, tanpa background worker) |
| Scope baru | Route `/operator/settlement` (GET+POST), `app/Services/SettlementService.php`, pipeline unggah nota, **Smart Auto-Split**, **Deficit Carry-Over Engine** |
| Sumber kebenaran | ERD → SAD → Roadmap (Protokol QC Manual 3) → PRD → UI/UX (Layar C) |
| Keputusan pengguna | `dec-d784fcb5c5b0720a` (lihat §2.1) |
| Bahasa dokumen | Prosa Indonesia + istilah teknis Inggris (konvensi `Plan_Fase_1_AurumFlow.md` & `Plan_Fase_2_AurumFlow.md`) |

---

## 1. Ringkasan Fase 3

### 1.1 Tujuan

Fase 2 menghasilkan jalur **kas keluar** pertama (meja kasir hulu). Fase 3 menutup siklus **T+1**: pagi berikutnya emas padu dibawa ke toko rekanan, kadar diuji dengan timbang air, harga riil terbaca, nota fisik difoto — lalu operator mengeksekusi **tutup buku harian** yang mengubah emas di meja menjadi kas yang siap diputar kembali.

Empat hal yang wajib benar:

1. **Layar rekonsiliasi (Layar C)** — ringkasan emas `holding`, berat jual, kadar timbang air toko, harga jual riil, beban aparat, foto nota, pratinjau kalkulasi langsung, dan pratinjau bagi hasil 80 : 20.
2. **Kompresi nota di sisi peramban** — foto nota diperkecil & di-*encode* ulang lewat **HTML5 Canvas** hingga **< 250 KB** sebelum diunggah (target lunak; peladen menegakkan batas keras + validasi MIME, sesuai keputusan).
3. **Smart Auto-Split** — kas fisik yang benar-benar diterima (`pendapatan − beban aparat`) dialokasikan: saran bawaan = **"Modal Belanja Hulu" kembali ke `Kas Meja Bakar`** + **"Laba Bersih" ke `Brankas Utama`**, tetapi **kedua isian dapat diubah operator (Manual Override)** dan peladen hanya menerimanya bila jumlahnya **persis sama** dengan `pendapatan − beban aparat` hasil hitung peladen.
4. **Deficit Engine** — laba bersih negatif **tidak pernah** menyentuh modal investor: selisihnya dicatat pada `daily_settlements.carryover_deficit` dan disimpan sebagai `system_settings.active_carryover_deficit`, untuk dipotong lebih dulu pada settlement berikutnya yang profit.

Baris `hulu_transactions` berubah `holding` → `settled` sekaligus menerima `settlement_id` baru. Bagi hasil 80 % pengelola / 20 % pool investor **hanya bersifat pembukuan** — tidak ada dompet perorangan yang dikreditkan (itu Fase 4, PRD §3.4).

Secara konkret, Fase 3 harus menghasilkan:

1. **Layar rekonsiliasi** (`/operator/settlement`) — input penjualan toko + unggah nota terkompresi + Smart Auto-Split (Alpine.js) + pratinjau bagi hasil.
2. **Mesin settlement atomik** (`app/Services/SettlementService.php`) — satu `Database::transaction()` untuk `daily_settlements` + `hulu_transactions` + 2 × `cash_registers` + `cash_mutations` + `system_settings`.
3. **Deficit Carry-Over Engine** — `carryover_deficit`, `deficit_recovery_used`, dan invariant `pendapatan − aparat = modal dialokasikan + laba bersih + defisit dipulihkan`.
4. **Pipeline unggah nota yang aman** — validasi gambar nyata, nama berkas dari UUID (bukan nama kiriman klien), batas ukuran, pembersihan berkas yatim bila transaksi gagal.
5. **Suite QC otomatis** — `tests/qc_fase_3.php` menjalankan **Protokol QC Manual 3** (Skenario 3.1 & 3.2) dengan unggah gambar tiruan, lalu mencetak tabel siap-salin ke dokumen Roadmap.

### 1.2 Definisi Selesai (Definition of Done)

Fase 3 dinyatakan selesai apabila **seluruh** poin berikut terpenuhi:

- [ ] `GET /operator/settlement` menampilkan layar rekonsiliasi; `POST /operator/settlement` mencatat satu sesi tutup buku.
- [ ] Investor → `GET /operator/settlement` dijawab `302` menuju `/dashboard`; `POST` investor tidak menulis apa pun.
- [ ] Kalkulasi Skenario 3.1 benar **di klien dan di peladen**: pendapatan `Rp 37.544.000`, modal `Rp 35.157.500`, laba kotor `Rp 2.386.500`, laba bersih `Rp 2.186.500`, pengelola `Rp 1.749.200`, pool `Rp 437.300`.
- [ ] Skenario 3.2 (hari minus): laba bersih `-Rp 80.000`, `operator_share_80 = 0.00`, `investor_pool_20 = 0.00`, `carryover_deficit = 80000.00`, `active_carryover_deficit = '80000.00'`.
- [ ] Satu POST = **satu** `Database::transaction()`; gagal di tengah berarti **nol** perubahan (diuji, bukan diasumsikan) **dan** berkas nota yang sudah tersalin ikut dihapus.
- [ ] Seluruh baris `holding` menjadi `settled` dengan `settlement_id` terisi.
- [ ] Kas fisik terdistribusi sesuai Smart Auto-Split: `Kas Meja Bakar` `Rp 14.842.500` + `Rp 35.157.500` = **`Rp 50.000.000`**; `Brankas Utama` = `Rp 2.186.500`.
- [ ] Foto nota terunggah ke `public/uploads/receipts/` dengan nama `<uuid>.<ext>`, dan `daily_settlements.receipt_photo_url` menunjuk berkas yang benar-benar ada.
- [ ] `capital_wallets` dan `profit_distributions` **tidak disentuh** (nol baris ditulis) — Fase 4.
- [ ] `tests/qc_fase_3.php` lulus 100% dan Protokol QC Manual 3 tercetak 1:1.
- [ ] `bash tests/run_qc.sh` exit `0` (9 suite), tanpa `storage/logs/php-*.log`, `git status` bersih dari artefak.
- [ ] Tidak ada perubahan DDL/seed; `docs/` tidak diubah.

### 1.3 Di Luar Ruang Lingkup Fase 3

Secara eksplisit **tidak** dikerjakan (backlog fase berikutnya):

- Waterfall / Pro-Rata Engine, kuota serapan modal, `capital_wallets`, `profit_distributions` (Fase 4).
- Penarikan dana H-7 / `withdrawal_requests` (Fase 4).
- Dasbor *blind* investor, waiver/clickwrap digital, autentikasi PIN, ekspor laporan (Fase 5).
- PWA (`manifest.json`, `sw.js`, ikon) — tetap Fase 5.
- **Item O-1**: menaikkan `hulu_transactions.created_at` ke `TIMESTAMP(3)` — **ditunda** menurut keputusan `dec-d784fcb5c5b0720a`. Fase 3 tetap netral-skema.
- Kunci `system_settings` baru (mis. nominal beban aparat harian) — beban aparat tetap isian operator (item O-2).
- Isian `consumable_opex` (tetap `0.00`, item O-3).
- Kebijakan retensi/backup berkas nota (item O-6).

---

## 2. Keputusan & Asumsi Terkunci

### 2.1 Keputusan pengguna (`dec-d784fcb5c5b0720a`)

| Keputusan | Pilihan | Konsekuensi |
|---|---|---|
| Batas ukuran nota di peladen | **Target lunak + batas keras** — Canvas mengejar < 250 KB; peladen menolak bukan-gambar dan berkas > 4 MB | Operator tanpa JavaScript tetap dapat menutup buku; ukuran berkas dicatat & ditampilkan |
| Aturan berat jual vs berat `holding` | **`0 < berat jual ≤ gram holding`**, seluruh batch disettle | Toleransi timbang air toko; ditolak bila melebihi stok atau ≤ 0 |
| Item O-1 (`created_at` presisi) | **Ditunda** — Fase 3 netral-skema | Tanpa perubahan `docs/`, `schema.sql`, maupun seed; QC yang sensitif urutan tetap bersifat himpunan (seperti Fase 2) |

### 2.2 Asumsi arsitektural yang dikunci

- **A-1 — Peladen satu-satunya kalkulator.** Klien hanya mengirim: berat jual, kadar timbang air, harga jual/gram, beban aparat, dua nominal alokasi, dan berkas nota. Pendapatan, modal belanja hulu, laba kotor, laba bersih, bagi hasil, dan total alokasi **dihitung ulang** di `SettlementService`. Nominal kiriman klien untuk kolom mana pun diabaikan.
- **A-2 — "Total Modal Belanja Hulu" = `SUM(net_cash_paid)` baris `holding`.** Bukan `gross_tebus_total`, bukan angka dari klien — persis `Rp 35.157.500` pada data Protokol QC Manual 2 (net setelah jasa bakar).
- **A-3 — Aritmetika uang integer, tanpa float.** Berat → miligram, rupiah → sen. Parsing berat/harga **dan** pembulatan pendapatan didelegasikan ke `TellerService::quote()` (jasa bakar nonaktif) agar aturan pembulatan tetap **satu pemilik** (kontrak `AGENTS.md`). Settlement hanya menambah parser **nominal rupiah** (boleh nol, konvensi field sama) — QC grup **B** menguji kedua parser dengan satu tabel kasus yang sama sehingga keduanya tidak dapat menyimpang.
- **A-4 — Bagi hasil tidak boleh berselisih sen.** `operator = round_half_away(net × 80 / 100)`, `investor = net − operator`, sehingga invariant **`operator + investor == net`** selalu berlaku (diuji QC grup A).
- **A-5 — Rumus defisit.** `X = laba kotor − beban aparat`; `used = min(active_deficit, max(0, X))`; `net = X − used`; `carryover_deficit` baris = `max(0, −net)`; `active_carryover_deficit_baru = active_deficit − used + carryover_deficit`. Bagi hasil = `0 / 0` setiap kali `net ≤ 0`.
- **A-6 — Saran Smart Auto-Split.** `total = pendapatan − beban aparat`; `brankas = max(0, net)`; `kas = total − brankas`. Pada hari normal ini berarti "modal belanja hulu kembali ke meja + laba bersih ke brankas" (`kas = min(total, modal)`), dan pada hari minus seluruh kas yang ada kembali ke meja (`brankas = 0`). Saat defisit **dipulihkan**, `kas` juga memuat defisit yang pulih sehingga meja kembali utuh — karena itu `kas = min(total, modal)` **bukan** rumus umum, hanya kebetulan pada dua kasus pertama. Invariant pembukuan: **`total == modal belanja hulu + net + used`**.
- **A-7 — Berkas dahulu, transaksi kemudian.** Nota dipindahkan ke tempat final **sebelum** transaksi PDO dibuka; bila transaksi melempar `Throwable`, berkas di-`unlink()`. Tidak ada berkas yatim dan tidak ada baris settlement yang menunjuk foto hilang.
- **A-8 — Urutan penguncian tetap** (mencegah deadlock dengan jalur kasir): `cash_registers` (urut nama menaik) → `hulu_transactions` (`FOR UPDATE`) → baris `active_carryover_deficit` (`FOR UPDATE`). Semua di dalam **satu** `Database::transaction()`; tidak ada `beginTransaction()` manual.
- **A-9 — Idempotensi.** Form membawa `_token` (CSRF, diperiksa dua lapis) **dan** token sekali-pakai `_once`. Replay **tidak** menulis apa pun: operator dialihkan (PRG) dengan pesan "transaksi ganda diabaikan" — kontrak yang sama dengan Fase 2.
- **A-10 — Konvensi sama dengan Fase 2.** `requireRole('operator')` → pelanggar diarahkan ke `/dashboard`; sukses = PRG + flash; gagal validasi = `422` + isian terpelihara; seluruh output dinamis lewat `Security::e()`; seluruh angka tampilan lewat `Formatter`.
- **A-11 — `consumable_opex` tetap `0.00`** dan beban aparat tetap isian operator. Tidak ada kunci `system_settings` baru (ERD & seed tidak disentuh) — dicatat sebagai item O-2.
- **A-12 — Satu hari boleh punya lebih dari satu sesi tutup buku** (skema tidak memiliki `UNIQUE` pada `settlement_date`). Settle ganda pada batch yang sama mustahil karena barisnya sudah menjadi `settled`.
- **A-13 — Timezone & logging** mengikuti Fase 2 (A-8): aplikasi dan DB pada `Asia/Jakarta (+07:00)`; `settlement_date` = tanggal WIB hari itu. Setiap settlement sukses/gagal dicatat lewat `Logger` (tanpa data sensitif).

---

## 3. Struktur File

### 3.1 File baru

| File | Peran |
|---|---|
| `plan/Plan_Fase_3_AurumFlow.md` | Dokumen ini |
| `app/Services/SettlementService.php` | `quote()` (murni, tanpa DB) + `settle()` (satu `Database::transaction()`) + pipeline nota + mesin defisit |
| `app/Models/DailySettlement.php` | `insert()`, `find()`, `latest()`, `countFor()` — SQL tabel `daily_settlements` |
| `app/Controllers/SettlementController.php` | `index()` (GET), `store()` (POST, PRG + flash) |
| `app/Views/operator/settlement.php` | Layar C: rekonsiliasi + Smart Auto-Split + tangkap nota |
| `public/uploads/receipts/.gitignore` | `*` + `!.gitignore` (direktori ikut git, isinya tidak) |
| `tests/qc_fase_3.php` | **Protokol QC Manual 3** otomatis + assertion tambahan (§6) |

### 3.2 File yang diubah

| File | Perubahan |
|---|---|
| `app/routes.php` | + `GET /operator/settlement`, + `POST /operator/settlement` |
| `app/Models/HuluTransaction.php` | + `holdingRowsForUpdate()`, + `holdingBatchTotals()` (gram + modal), + `settleBatch(string $settlementId, array $ids)`, + `countByStatus()` |
| `app/Models/CashRegister.php` | + `BRANKAS_NAME = 'Brankas Utama'`, + `findBrankas()`, + `lockByNameForUpdate()` |
| `app/Models/CashMutation.php` | Dipakai apa adanya (`TYPE_IN` sudah ada); komentar kelas diperbarui (mutasi `in` kini nyata) |
| `app/Models/SystemSetting.php` | + `set(string $key, string $value)`, + `getForUpdate(string $key)` — hanya dipanggil di dalam transaksi |
| `app/Services/TellerService.php` | **Tanpa perubahan perilaku.** Hanya bila diperlukan: docblock menunjuk `quote()` sebagai pemilik aturan pembulatan lintas-fase |
| `public/assets/js/app.js` | + `compressImage()` (Canvas), + parser persen & nominal, + `Alpine.data('settlementForm')` |
| `public/assets/css/app.min.css` | **Hasil build** ulang (kelas layar rekonsiliasi); target tetap < 30 KB |
| `app/Views/layouts/operator.php` | Navigasi Kasir ↔ Rekonsiliasi + widget saldo `Brankas Utama` |
| `app/Views/dashboard/index.php` | Tautan *role-aware* "Buka Rekonsiliasi Toko" (hanya `operator`) |
| `public/uploads/.htaccess` | + `RemoveHandler`/`RemoveType` untuk ekstensi skrip (hardening cPanel PHP-FPM/LiteSpeed, SAD §4.3) |
| `public/uploads/.gitignore` | + pengecualian `receipts/` (pola `*` saat ini juga menelan direktori baru) |
| `.gitignore` (root) | + `!/public/uploads/receipts/`, + `!/public/uploads/receipts/.gitignore` |
| `tests/bootstrap.php` | + `QcFixture::resetSettlementState()`, + `QcFixture::resetSingleHoldingState()`, + dukungan multipart pada `QcHttp` (`'files' => [...]`) |
| `tests/server_router.php` | Meniru `public/uploads/.htaccess`: tolak ekstensi skrip di bawah `uploads/` (harness dev setara produksi) |
| `tests/run_qc.sh` | + `qc_fase_3` setelah `qc_fase_2`; header/ringkasan log |
| `README.md` | Perintah build CSS, pohon direktori, tabel QC (9 suite), peringatan tulis DB **dan** disk |
| `AGENTS.md` | Baseline suite/assertion, route `/operator/settlement`, aturan unggah nota, peringatan QC |

### 3.3 File yang dihapus

Tidak ada.

### 3.4 Pohon target (perubahan ditandai)

```text
aurumflow/
├── .gitignore                                  # (~) pengecualian receipts/
├── app/
│   ├── Controllers/SettlementController.php    # (+)
│   ├── Models/
│   │   ├── DailySettlement.php                 # (+)
│   │   ├── HuluTransaction.php                 # (~) batch holding: lock/ambil/settle
│   │   ├── CashRegister.php                    # (~) Brankas + lock per nama
│   │   └── SystemSetting.php                   # (~) set() / getForUpdate()
│   ├── Services/SettlementService.php          # (+)
│   └── Views/
│       ├── layouts/operator.php                # (~) navigasi + saldo brankas
│       ├── dashboard/index.php                 # (~) tautan rekonsiliasi
│       └── operator/settlement.php             # (+)
├── public/
│   ├── assets/
│   │   ├── css/app.min.css                     # (~) hasil build ulang
│   │   └── js/app.js                           # (~) kompresor Canvas + settlementForm
│   └── uploads/
│       ├── .gitignore                          # (~) pengecualian receipts/
│       ├── .htaccess                           # (~) RemoveHandler/RemoveType
│       └── receipts/.gitignore                 # (+)
└── tests/
    ├── bootstrap.php                           # (~) fixture settlement + QcHttp multipart
    ├── server_router.php                       # (~) hardening uploads
    ├── run_qc.sh                               # (~) + qc_fase_3
    └── qc_fase_3.php                           # (+) Protokol QC Manual 3
```

---

## 4. Keputusan Teknis & Konvensi Fase 3

### 4.1 Aritmetika uang (lanjutan A-4 Fase 2)

Aturan yang dibekukan, diuji grup **A** pada §6.2:

1. Berat jual & harga jual diurai oleh **`TellerService::quote($berat, $harga, false, ['fee_cents' => 0, 'operator_cut_cents' => 0])`** — pemilik tunggal aturan parsing berat/harga dan pembulatan `round_half_away(mg × cents / 1000)`. Hasilnya dipakai apa adanya: `weight` → `total_dry_grams`, `price` → `shop_price_per_g`, `gross` → `total_shop_revenue`.
2. Nominal rupiah (beban aparat, alokasi kas, alokasi brankas) diurai oleh parser **nominal** milik `SettlementService`: titik = pemisah ribuan, koma = desimal, maksimum 2 desimal, **nol diizinkan** (`'0'`/kosong → `0` sen). Konvensinya identik dengan `TellerService::parsePriceInput()`; grup QC **B** membandingkan kedua parser pada satu tabel kasus.
3. Modal belanja hulu dibaca dari DB sebagai `SUM(net_cash_paid)` baris `holding` lalu dikonversi ke sen — bukan dihitung dari gram.
4. Semua perhitungan lanjutan (laba kotor, defisit, bagi hasil, alokasi) memakai **sen integer**; nilai ke DB selalu string desimal kanonik (`'35157500.00'`, `'18.050'`, `'93.50'`).
5. Dilarang `float`/`double` pada jalur uang; QC memindai berkas Fase 3.
6. Tidak ada `number_format()` di luar `Formatter`.

### 4.2 Parsing input lapangan layar rekonsiliasi

| Field | `inputmode` | Normalisasi | Contoh |
|---|---|---|---|
| Berat jual toko | `decimal` | Aturan berat Fase 2 (satu pemisah = desimal, maks 3 desimal, ≤ `99.999,999` gr) | `18,050` → 18,050 gr |
| Kadar timbang air toko | `decimal` | Satu pemisah = desimal, maks 2 desimal, `> 0` dan `≤ 100` | `93,50` → `'93.50'` |
| Harga jual riil / gram | `numeric` | Aturan harga Fase 2 (titik = ribuan) | `2.080.000` → `'2080000.00'` |
| Beban aparat / lapangan | `numeric` | Parser nominal §4.1 (boleh `0`) | `200.000` → `'200000.00'` |
| Alokasi Kas Meja / Brankas | `numeric` | Parser nominal §4.1 (boleh `0`); **jumlah keduanya wajib sama** dengan `pendapatan − aparat` hasil hitung peladen | `35.157.500` / `2.186.500` |
| Foto nota | `file` | `image/*`, `capture="environment"`; divalidasi ulang di peladen (§4.6) | — |

Input tak sah **ditolak** (`422`) dengan pesan yang dapat dibaca operator, dan **tidak** menulis apa pun.

### 4.3 Mesin defisit (PRD §4.2)

```
X        = laba_kotor − beban_aparat                     (sen)
used     = min(active_carryover_deficit, max(0, X))
net      = X − used
carryover_hari_ini = max(0, −net)                        → kolom carryover_deficit
active_baru        = active_carryover_deficit − used + carryover_hari_ini
```

- `deficit_recovery_used` = `used` (kolom `daily_settlements`).
- Bagi hasil dihitung **hanya bila `net > 0`**; bila tidak, `operator_share_80 = 0.00` dan `investor_pool_20 = 0.00`, dan `capital_wallets` **tidak disentuh sama sekali** (PRD: "tidak memotong modal pokok mitra").
- `net_settled_profit` menyimpan nilai `net` apa adanya, termasuk saat negatif (sesuai ekspektasi Protokol 3.2: "Hasil Bersih −Rp 80.000").
- `system_settings.active_carryover_deficit` selalu ditulis pada setiap settlement (0.00 saat pulih penuh).

### 4.4 Smart Auto-Split

- `total_fisik = pendapatan_toko − beban_aparat` (dihitung peladen; wajib `≥ 0`, jika tidak → `422` dengan pesan "Pendapatan toko lebih kecil dari beban aparat — tidak ada kas yang dapat dialokasikan").
- Saran bawaan: `brankas = max(0, net)`, `kas = total_fisik − brankas`.
- Kedua isian **boleh diubah operator**. Validasi peladen: keduanya `≥ 0` dan `kas + brankas == total_fisik`. Bila tidak, `422` dan tidak ada tulisan.
- **Dua isian yang sama-sama kosong berarti "pakai saran sistem"** — peladen menghitung sendiri alokasinya. Inilah yang membuat form tetap benar tanpa JavaScript: operator cukup mengisi penjualan toko.
- Alasan invariant A-6: pada hari normal `total_fisik = modal + laba_bersih`, sehingga saran bawaan mengembalikan modal ke meja dan menaruh laba di brankas; pada hari minus `total_fisik < modal`, sehingga meja menerima seluruh kas yang ada dan **kekurangan kas itu tepat sebesar defisit yang dicatat**.

### 4.5 Alur atomik & penguncian (SAD §4.1)

`SettlementService::settle()` menjalankan **satu** `Database::transaction()` dengan urutan:

1. `SELECT ... FROM cash_registers WHERE register_name = :nama FOR UPDATE` untuk **`Kas Meja Bakar`** lalu **`Brankas Utama`** (urutan nama menaik, tetap) — mencegah deadlock dengan jalur kasir.
2. `SELECT id, dry_weight, net_cash_paid FROM hulu_transactions WHERE status = 'holding' AND settlement_id IS NULL FOR UPDATE` — batch yang ditutup buku.
   - kosong → `RuntimeException` ("Tidak ada emas holding…") **sebelum** ada tulisan.
3. Total gram batch (miligram) dan modal belanja hulu (sen) dari himpunan yang terkunci.
4. Guard berat: `0 < berat_jual ≤ gram_holding` (A-2 / keputusan pengguna).
5. Baca `active_carryover_deficit` **dengan `FOR UPDATE`** sehingga dua settlement serentak tidak memotong defisit yang sama dua kali.
6. Hitung `X`, `used`, `net`, `carryover`, `active_baru`, bagi hasil (§4.3) dan validasi Smart Auto-Split (§4.4).
7. `INSERT daily_settlements` (13 kolom ERD, termasuk `receipt_photo_url`).
8. `UPDATE hulu_transactions SET status = 'settled', settlement_id = :id WHERE id IN (...)` untuk himpunan id yang terkunci (`HuluTransaction::settleBatch()`).
9. `UPDATE cash_registers` dua kali (relatif, `current_balance + delta`): `Kas Meja +kas`, `Brankas +brankas`.
10. `INSERT cash_mutations` **dua baris** `in`: pengembalian modal belanja hulu ke meja, dan laba bersih putaran ke brankas (`source_destination` memuat tanggal + jenis, `notes` memuat UUID settlement, gram, pendapatan untuk jejak audit).
11. `UPDATE system_settings SET setting_value = :active_baru WHERE setting_key = 'active_carryover_deficit'`.

Bila langkah mana pun melempar `Throwable`: transaksi digulung **dan** berkas nota yang sudah dipindahkan di-`unlink()` sebelum exception diteruskan. Pembatalan & perhitungan dilakukan **sebelum** transaksi dibuka sedapat mungkin.

### 4.6 Pipeline unggah nota (aman)

Di luar transaksi, sebelum `settle()` membuka transaksi:

1. Deteksi batas PHP: bila `CONTENT_LENGTH > post_max_size` (atau `$_FILES` kosong karena itu) → pesan jelas "Ukuran unggahan melebihi batas server" (`422`, tanpa tulisan).
2. Validasi entri `$_FILES['receipt_photo']`: `error === UPLOAD_ERR_OK`, `is_uploaded_file()`, `size > 0`, `size ≤ 4 MB` (batas keras), `getimagesize()` berhasil, dan `type` termasuk whitelist `[IMAGETYPE_JPEG => jpg, IMAGETYPE_PNG => png, IMAGETYPE_WEBP => webp]`. Cross-check `finfo_file()` terhadap MIME gambar.
3. Nama tujuan **dibentuk peladen**: `Security::uuidV4() . '.' . ekstensi_dari_tipe_terdeteksi`. Nama kiriman klien tidak pernah dipakai untuk path (mencegah `../../shell.php`); hanya dicatat pada log setelah `Security::sanitize()`.
4. Pastikan `public/uploads/receipts/` ada dan **writable**; buat bila belum; `move_uploaded_file()`, lalu `chmod 0644`.
5. `receipt_photo_url` = path relatif `uploads/receipts/<nama>` (≤ 255 karakter) dan ditampilkan lewat `App::url()` + `Security::e()`.
6. Direktori berada di bawah `public/uploads/.htaccess` (warisan aturan: mesin PHP mati, ekstensi skrip ditolak, `Options -Indexes`); `.htaccess` diperkuat `RemoveHandler`/`RemoveType` untuk cPanel PHP-FPM/LiteSpeed.
7. Kegagalan apa pun setelah berkas tersalin → `unlink()` (A-7), diuji di QC grup **C**.

### 4.7 Kompresi nota di peramban (HTML5 Canvas)

Berkas: `public/assets/js/app.js` (SAD §3 sudah menetapkan berkas ini sebagai rumah "helper kompresi foto nota"; Fase 2 juga menaruh `tellerForm` di sana). Alternatif `receipt-compressor.js` terpisah ditolak agar tidak ada rumah kedua untuk parser field & agar tidak menambah satu request.

- `compressImage(file, {maxBytes: 256000, maxEdge: 2000, minQuality: 0.5, minEdge: 1200})`:
  1. Baca gambar dengan `createImageBitmap(file, {imageOrientation: 'from-image'})` (hormati EXIF), fallback `Image` + `URL.createObjectURL` (selalu `revokeObjectURL`).
  2. Gambar ke `canvas` dengan sisi terpanjang ≤ `maxEdge`.
  3. `canvas.toBlob(cb, 'image/jpeg', q)`; selama `size > maxBytes` turunkan `q` sebesar 0.1 hingga `minQuality`, lalu turunkan `maxEdge` sebesar 200 hingga `minEdge`.
  4. Kembalikan blob terkecil yang berhasil; langkah pertama juga mencoba hanya bila sumber > ambang.
- Hasil kompresi **menggantikan** berkas pada input lewat `DataTransfer` + `DataTransfer.files`, sehingga POST multipart biasa membawa blob terkompresi tanpa AJAX.
- Bila Canvas/`DataTransfer` tidak tersedia atau kompresi gagal → berkas asli tetap terkirim, dan UI menampilkan peringatan; batas keras peladen tetap berlaku.
- UI menampilkan hasil: `nota-awal.jpg · 412 KB → 176 KB`. Nilai ukuran hanya untuk tampilan; peladen **tidak** mempercayainya.

### 4.8 Idempotensi / anti double-submit (A-9)

Sama seperti Fase 2: `Security::issueFormToken()` menerbitkan token sekali-pakai; `consumeFormToken()` mengembalikan `ok | duplicate | invalid`. Duplikat → `302` + flash "transaksi ganda diabaikan" **tanpa** tulisan; token tak dikenal → `419`. Tutup buku adalah mutasi kas, jadi aturan ini wajib.

### 4.9 Route & guard

| Method | Path | Handler | Guard |
|---|---|---|---|
| `GET` | `/operator/settlement` | `SettlementController::index` | `requireAuth()` + `requireRole('operator')` |
| `POST` | `/operator/settlement` | `SettlementController::store` | idem + CSRF (2 lapis) + `_once` |

Mutasi tetap **hanya** via POST + CSRF. `requireRole()` mengarahkan pelanggar ke `/dashboard` (bukan halaman error), selaras Protokol QC Manual 5.

### 4.10 Flash & pola POST-Redirect-GET

- Sukses → `flash('success', …)` (memuat pendapatan, laba bersih, bagi hasil, saldo akhir meja & brankas) lalu `302` ke `GET /operator/settlement`.
- Gagal validasi → `422` + render ulang form dengan isian terpelihara (operator tidak perlu mengetik ulang penjualan toko).
- Duplikat `_once` → `302` + flash peringatan.

---

## 5. Tugas Eksekusi (Step-by-Step)

> Dikerjakan **berurutan**. T1–T9 tidak boleh dilompati; setiap tugas menyebut file target dan kriteria selesai.

### T1 — Fondasi frontend & direktori unggahan

**File:** `public/assets/js/app.js`, `public/assets/css/app.min.css`, `public/uploads/receipts/.gitignore`, `public/uploads/.gitignore`, `public/uploads/.htaccess`, `.gitignore`

**Langkah:**
1. Tambah `compressImage()` (§4.7) dan parser persen/nominal ke `app.js`, tanpa mengubah perilaku `tellerForm` yang sudah ada.
2. Tambah komponen Alpine `settlementForm(config)` — nilai konfigurasi hanya dari kode kita (integer/boolean), tidak dari input pengguna.
3. Buat `public/uploads/receipts/` beserta `.gitignore` (`*` + `!.gitignore`).
4. Perbaiki `public/uploads/.gitignore` **dan** `.gitignore` root agar `receipts/` tidak tertelan pola `*` / `/public/uploads/*`.
5. Perkuat `public/uploads/.htaccess` dengan `RemoveHandler`/`RemoveType` untuk ekstensi skrip.
6. Kompilasi ulang: `npx --yes tailwindcss@3.4.19 -c tailwind.config.js -i resources/css/tailwind.input.css -o public/assets/css/app.min.css --minify`.

**Kriteria selesai:** `node --check public/assets/js/app.js` lulus; `app.min.css` < 30 KB dan memuat token tema; `git check-ignore public/uploads/receipts/.gitignore` **tidak** mencocokkan; `GET /resources/css/tailwind.input.css` tetap `403`.

### T2 — Model: batch holding, kas brankas, tulis setting

**File:** `app/Models/DailySettlement.php` (baru), `app/Models/HuluTransaction.php`, `app/Models/CashRegister.php`, `app/Models/SystemSetting.php`, `app/Models/CashMutation.php`

**Langkah:**
1. `DailySettlement`: `insert(array $data): void` (UUID v4 dari `Security::uuidV4()`), `find(string $id)`, `latest(int $limit)`, `countFor(string $date)`.
2. `HuluTransaction`: `holdingRowsForUpdate()` (`status='holding' AND settlement_id IS NULL … FOR UPDATE`), `holdingBatchTotals()` (gram miligram + modal sen, hanya baca), `settleBatch($settlementId, array $ids)` (`UPDATE … SET status='settled', settlement_id=:id WHERE id IN (:…))`, `countByStatus(string $status)`.
3. `CashRegister`: `BRANKAS_NAME = 'Brankas Utama'`, `findBrankas()`, `lockByNameForUpdate(string $name)`.
4. `SystemSetting`: `set(string $key, string $value): void`, `getForUpdate(string $key): ?string`.
5. Komentar kelas `CashMutation` disesuaikan: mutasi `in` (likuidasi toko) kini dipakai.

**Kriteria selesai:** `php -l` bersih; SQL **hanya** di `app/Models/` + `app/Config/Database.php`; seluruh nilai dinamis lewat named parameter; nama kolom persis ERD (tidak ada kolom baru).

### T3 — `SettlementService`

**File:** `app/Services/SettlementService.php`

**Langkah:**
1. `quote(array $input): array` — murni, tanpa DB (kecuali pembacaan setting defisit bila tidak dikirim eksplisit): kembalikan pendapatan, modal, laba kotor, `used`, laba bersih, bagi hasil, `carryover`, total fisik, saran alokasi, dan nilai sen untuk perhitungan lanjutan.
2. `settle(array $input, array $files): array` — validasi → simpan nota (§4.6) → `Database::transaction()` sesuai urutan §4.5 → kembalikan ringkasan untuk flash.
3. `Logger::info()` pada sukses (id settlement, gram, pendapatan, laba bersih, alokasi) dan `Logger::warning()` pada penolakan (alasan, tanpa data sensitif).
4. Tipe exception: `InvalidArgumentException` untuk input tidak sah, `RuntimeException` untuk pelanggaran invariant (tanpa baris holding, pos kas tidak ditemukan, direktori nota tak writable).

**Kriteria selesai:** `quote()` dapat diuji tanpa DB; `beginTransaction`/`commit`/`rollBack` tidak muncul di luar `Database::transaction()`; tidak ada `float` pada jalur uang; kegagalan tidak meninggalkan berkas yatim maupun baris menggantung.

### T4 — Controller, guard, dan route

**File:** `app/Controllers/SettlementController.php`, `app/routes.php`

**Langkah:**
1. `index()` — guard peran; ringkasan batch `holding` (gram, jumlah nota, modal belanja hulu); saldo `Kas Meja Bakar` + `Brankas Utama`; `active_carryover_deficit`; saran Smart Auto-Split yang dirender **server-side** (benar walau JS mati); token `_once`.
2. `store()` — pemeriksaan CSRF kedua, `_once` (duplikat → PRG + pesan), validasi, `settle()`, lalu `flash()` + redirect; gagal validasi → `422` + isian terpelihara.
3. Tambahkan dua route ke `app/routes.php` (data saja).

**Kriteria selesai:** investor → `302 /dashboard`; POST tanpa CSRF → `419`; POST tanpa `_once` → `419`; tidak ada query SQL di controller; hanya field **non-nominal** (berat, kadar, harga, aparat, dua alokasi) + berkas yang dibaca dari klien.

### T5 — Layar rekonsiliasi (Layar C)

**File:** `app/Views/operator/settlement.php`, `app/Views/layouts/operator.php`, `app/Views/dashboard/index.php`

**Langkah:**
1. Ringkasan emas `holding` di atas (gram terformat, jumlah nota, modal belanja hulu).
2. Lima isian (§4.2) + input berkas `accept="image/*" capture="environment"` + pembacaan hasil kompresi (`nota.jpg · 412 KB → 176 KB`).
3. Blok kalkulasi langsung: kas masuk toko → modal belanja hulu → laba kotor → beban aparat → pemulihan defisit → laba bersih → baris bagi hasil 80 % / 20 %.
4. Blok **Smart Auto-Split** (Alpine.js): dua isian nominal yang dapat disunting + tombol "kembalikan ke saran otomatis" + indikator hidup "sisa wajib Rp 0"; tombol eksekusi nonaktif selama jumlah belum cocok.
5. Tombol "EKSEKUSI SETTLEMENT HARIAN" dengan `data-confirm`; jalur cadangan `<noscript>` berupa tombol submit biasa (form tetap POST + `_token` + `_once`).
6. `layouts/operator.php`: navigasi Kasir ↔ Rekonsiliasi + widget saldo brankas. `dashboard/index.php`: tautan "Buka Rekonsiliasi Toko" hanya untuk `operator`.

**Kriteria selesai:** seluruh output dinamis lewat `Security::e()`; seluruh angka lewat `Formatter`; halaman tetap benar tanpa JavaScript; target sentuh ≥ 52 px; tanpa overflow horizontal pada 360 px.

### T6 — Harness QC

**File:** `tests/bootstrap.php`, `tests/server_router.php`

**Langkah:**
1. `QcFixture::resetSettlementState(): array` — idempotent: hapus `daily_settlements` & `profit_distributions`; nolkan `cash_mutations` kedua pos; set `Brankas Utama` = `0.00`; set `active_carryover_deficit` = `'0.00'`; hapus berkas nota sisa QC; lalu bangun ulang keadaan akhir Fase 2 **lewat jalur kode nyata** (`resetTellerState()` + dua `TellerService::payCash()` → `18,050` gr, modal `35.157.500`, kas `14.842.500`).
2. `QcFixture::resetSingleHoldingState(string $grams, string $price): array` — satu nota `holding` (mis. `1,000` gr @ `1.950.000` → net `1.930.000`), kas meja mengikuti.
3. `QcHttp`: dukung multipart — opsi `'files' => ['receipt_photo' => $path]` diubah menjadi `CURLFile` dan dikirim sebagai array (curl membentuk boundary sendiri).
4. `tests/server_router.php`: tolak ekstensi skrip di bawah `uploads/` (`403`) agar harness dev setara `public/uploads/.htaccess`.

**Kriteria selesai:** `php tests/qc_fase_2.php` tetap lulus (fixture baru tidak merusak yang lama); `qc_fase_3` dapat dijalankan berulang dengan hasil sama.

### T7 — QC Fase 3

**File:** `tests/qc_fase_3.php`

**Langkah:**
1. Tulis suite mengikuti pola `qc_fase_2.php` (`Qc::suite/section/ok/equals/closeTo/throws/finish`, pemindai lokal untuk berkas Fase 3).
2. Jalankan **Skenario 3.1** dan **Skenario 3.2** persis dengan angka dokumen Roadmap, melalui jalur HTTP multipart (nota tiruan) **dan** jalur service langsung untuk assertion yang lebih rinci.
3. Cetak tabel rekap Protokol QC Manual 3 (bentuk sama dengan dokumen Roadmap) — §6.1.
4. Tambahkan grup assertion A–I — §6.2.

**Kriteria selesai:** exit `0`, seluruh baris protokol `LULUS`, tanpa `E_WARNING`/`E_NOTICE`/`E_DEPRECATED`.

### T8 — Gerbang penuh

**File:** `tests/run_qc.sh`

**Langkah:** daftarkan `qc_fase_3` setelah `qc_fase_2`; perbarui header/ringkasan log; jalankan `bash tests/run_qc.sh`.

**Kriteria selesai:** 9 suite exit `0`; jumlah assertion naik dari 254; tidak ada `storage/logs/php-*.log`; `git status` bersih dari artefak (`tests/.tmp/`, `storage/installed.lock`, `*.log`).

### T9 — Dokumentasi & serah terima

**File:** `README.md`, `AGENTS.md`, `plan/Plan_Fase_3_AurumFlow.md`

**Langkah:**
1. `README.md`: perintah build, pohon direktori (`app/Services/SettlementService.php`, `public/uploads/receipts/`), tabel QC 9 suite, peringatan bahwa `qc_fase_3` menulis ke DB **dan** membuat/menghapus berkas di `public/uploads/receipts/`, tabel pemecahan masalah (unggah gagal, melebihi batas, nota tidak terbaca).
2. `AGENTS.md`: baseline suite/assertion, route `/operator/settlement`, aturan unggah nota (nama dari UUID, batas keras), aturan Smart Auto-Split & defisit.
3. Perbarui `ADDENDUM` dokumen ini dengan penyimpangan nyata saat eksekusi.
4. `plan/Summary_Fase_3_AurumFlow.md` (**opsional**, setelah verifikasi, mengikuti pola `Summary_Fase_2`).

**Kriteria selesai:** review diff akhir hanya memuat berkas §3; tanpa kode mati (setiap method publik baru punya pemanggil nyata atau assertion QC).

---

## 6. Quality Control — Protokol QC Manual 3

**Berkas:** `tests/qc_fase_3.php` — PHP CLI, `exit(0)` = PASS, `exit(1)` = FAIL, didaftarkan pada `tests/run_qc.sh`.

**Prasyarat fixture:** keadaan akhir Protokol QC Manual 2 dibangun ulang lewat jalur kode nyata — `Kas Meja Bakar` = `Rp 50.000.000`, dua transaksi hulu (14,850 gr @ Rp 1.950.000 dan 3,200 gr @ Rp 1.950.000), sehingga **18,050 gr** `holding` dengan modal belanja **Rp 35.157.500** dan kas meja **Rp 14.842.500**.

**Unggah nota tiruan:** suite membuat satu berkas PNG/JPEG kecil yang sah di `tests/.tmp/` lalu mengirimnya sebagai multipart — **tidak** memerlukan kamera.

> **Peringatan:** suite ini **menulis** ke database yang ditunjuk `.env` (`daily_settlements`, `hulu_transactions`, `cash_registers`, `cash_mutations`, `system_settings`) **dan** membuat/menghapus berkas di `public/uploads/receipts/`. Jangan arahkan ke produksi.

### 6.1 Tabel protokol (1:1 dengan dokumen Roadmap)

| # | Parameter Uji | Data Masukan | Hasil yang Diharapkan | Assertion |
|---|---|---|---|---|
| **P3.1** | Total kas masuk toko (3.1) | 18,050 × Rp 2.080.000 | `Rp 37.544.000` | `total_shop_revenue = 37544000.00` + `Formatter::rupiah(37544000) === 'Rp 37.544.000'` |
| **P3.2** | Total modal belanja hulu | 28.937.500 + 6.220.000 | `Rp 35.157.500` | `SUM(net_cash_paid)` baris `holding` = `35157500.00` **dan** dipakai sebagai pengurang laba kotor |
| **P3.3** | Laba kotor usaha | Rp 37.544.000 − Rp 35.157.500 | `Rp 2.386.500` | laba kotor terhitung = `2386500.00` |
| **P3.4** | Laba bersih putaran | Rp 2.386.500 − Rp 200.000 − Rp 0 | `Rp 2.186.500` | `net_settled_profit = 2186500.00`, `deficit_recovery_used = 0.00` |
| **P3.5** | Hak pengelola (80 %) | 80 % × Rp 2.186.500 | `Rp 1.749.200` | `operator_share_80 = 1749200.00` |
| **P3.6** | Pool investor (20 %) | 20 % × Rp 2.186.500 | `Rp 437.300` | `investor_pool_20 = 437300.00` + invariant `operator + pool = net` |
| **P3.7** | Status transaksi hulu | 2 baris `holding` | Berubah **serentak** menjadi `settled` | kedua baris `status = 'settled'`, `settlement_id` = id settlement baru |
| **P3.8** | Smart Auto-Split (saran bawaan) | pendapatan − aparat = Rp 37.344.000 | Kas `Rp 35.157.500` · Brankas `Rp 2.186.500` | saran peladen = `35157500.00` / `2186500.00`, jumlah = `37344000.00` |
| **P3.9** | Kas Meja Bakar setelah distribusi | Rp 14.842.500 + Rp 35.157.500 | `Rp 50.000.000` | `current_balance = 50000000.00` (round-trip modal) |
| **P3.10** | Brankas Utama setelah distribusi | Rp 0 + Rp 2.186.500 | `Rp 2.186.500`, 2 mutasi `in` | `current_balance = 2186500.00`; 2 baris `cash_mutations` bertipe `in` pada dua pos berbeda |
| **P3.11** | Catatan pada hari normal | — | tanpa defisit | `carryover_deficit = 0.00`, `active_carryover_deficit = '0.00'` |
| **P3.12** | (3.2) Hasil bersih hari minus | Rp 120.000 − Rp 200.000 | `-Rp 80.000` | `net_settled_profit = -80000.00` |
| **P3.13** | (3.2) Saldo investor utuh | — | **tidak** terpotong sepeser pun | `operator_share_80 = 0.00`, `investor_pool_20 = 0.00`, `profit_distributions` = 0 baris, `capital_wallets` tidak berubah |
| **P3.14** | (3.2) Catatan sistem | — | Kolom `carryover_deficit` = `Rp 80.000` | baris `carryover_deficit = 80000.00`; `active_carryover_deficit = '80000.00'` |
| **P3.15** | (3.2) Alokasi kas hari minus | total fisik Rp 1.850.000 < modal Rp 1.930.000 | Meja menerima seluruh kas; brankas `Rp 0` | `kas = 1850000.00`, `brankas = 0.00`, kas meja akhir `49920000.00` |
| **P3.16** | (3.3) Settlement berikutnya memotong defisit | 1,000 gr @ Rp 2.500.000 · aparat Rp 200.000 | Net `Rp 290.000` setelah memotong `Rp 80.000` | `deficit_recovery_used = 80000.00`, `net_settled_profit = 290000.00`, `operator = 232000.00`, `pool = 58000.00`, `active_carryover_deficit` kembali `'0.00'`; alokasi saran: kas `Rp 2.010.000` (modal Rp 1.930.000 + defisit pulih Rp 80.000), brankas `Rp 290.000`; kas meja kembali `Rp 50.000.000` |
| **P3.17** | Rekap protokol | seluruh baris | Semua baris `LULUS` | Cetak tabel siap-salin ke `docs/AurumFlow Roadmap and QC Documents.md` |

> **P3.16** adalah tambahan di luar dua skenario wajib: dokumen Roadmap mensyaratkan pada Skenario 3.2 bahwa "Settlement Esoknya otomatis memotong Rp 80.000 sebelum laba baru dibagi 80:20". Tanpa baris ini, mesin pemulihan defisit tidak punya bukti.

### 6.2 Assertion tambahan di luar protokol manual

| Grup | Cakupan |
|---|---|
| **A. Kalkulasi murni** (tanpa DB) | Pembulatan `round_half_away` pada sisa sub-sen pendapatan; invariant `operator + pool == net` untuk banyak nilai `net`; hari minus → `0/0` + carryover; pemulihan defisit **sebagian** (mis. `used = X`) dan **penuh**; `net = 0` tepat; penolakan `pendapatan < beban aparat`; penolakan alokasi negatif / jumlah tak cocok |
| **B. Parsing input lapangan** | `'18,050'`/`'18.050'` → 18,050 gr; kadar `'93,50'`/`'93.5'`/`'100'`; nominal aparat & alokasi termasuk `'0'`; ditolak: kosong, negatif, dua pemisah, > 3 desimal (berat) / > 2 desimal (uang), kadar `0` atau `> 100`, melebihi kapasitas kolom. **Penjaga penyimpangan:** untuk setiap nilai uji tak-nol, parser nominal `SettlementService` dan `TellerService` harus menghasilkan angka yang sama |
| **C. Atomisitas & tanpa berkas yatim** | Paksa kegagalan di tengah transaksi → **nol** baris `daily_settlements`, baris tetap `holding`, saldo kedua pos tak berubah, nol mutasi baru, `active_carryover_deficit` tak berubah, **dan** berkas nota yang sudah tersalin hilang dari disk |
| **D. Guard** | Tanpa baris `holding` → ditolak; `berat jual > gram holding` → ditolak; jumlah alokasi ≠ total fisik → ditolak; alokasi negatif → ditolak; berkas tidak ada / rusak → ditolak; berkas `text/plain` bernama `.jpg` → ditolak; berkas melebihi batas keras → ditolak |
| **E. Peladen tidak percaya klien** | POST dengan `total_shop_revenue`, `holding_cost`, `net_settled_profit`, `operator_share_80`, `investor_pool_20`, dan `active_carryover_deficit` palsu → nilai tersimpan tetap hasil hitung server |
| **F. Anti double-submit** | POST sah 1× → 1 settlement; **replay `_once` yang sama** → 0 baris baru, saldo tetap, pesan "transaksi ganda diabaikan"; POST tanpa `_once` → `419`; POST tanpa CSRF → `419` |
| **G. HTTP nyata (`QcHttp`, multipart)** | Anonim → `302 /login`; operator → `200` dan halaman memuat `18,050 gr`, modal belanja hulu, serta saran alokasi ter-render server; **investor → `302 /dashboard`** dan POST-nya tidak menulis apa pun; POST sukses → `302` (PRG); berkas nota tersimpan dapat diambil (`200`), sedangkan `/uploads/receipts/<nama>.php` → `403`; `receipt_photo_url` di DB menunjuk berkas yang benar-benar ada |
| **H. Pemindaian statis** | Tidak ada `float`/`double` pada jalur uang Fase 3; tidak ada `number_format()`; tidak ada `beginTransaction`/`commit`/`rollBack` manual; tidak ada SQL di controller/view; seluruh output dinamis view lewat `Security::e()`; tidak ada URL CDN; `app.min.css` < 30 KB; `app.js` memuat kompresor Canvas + `settlementForm`; `move_uploaded_file()` hanya di berkas pipeline unggah; ekstensi berkas tersimpan tidak pernah berasal dari nama kiriman klien |
| **I. Integritas skema** | `QcFixture::checkDecimalConventions()` untuk `daily_settlements` (`total_dry_grams` `DECIMAL(8,3)`, `shop_water_density_pct` `DECIMAL(5,2)`, kolom uang `DECIMAL(14,2)`, PK `CHAR(36)`) + `hulu_transactions`, `cash_registers`, `cash_mutations`; Fase 3 **tidak** mengubah DDL (13 kolom `daily_settlements` sudah ada sejak Fase 1) |

### 6.3 Gate penerimaan

| Gate | Syarat |
|---|---|
| **G-1** | 9 suite QC (`qc_fase_3` baru) exit `0`; jumlah assertion naik dari baseline 254 |
| **G-2** | Tidak ada `E_WARNING`/`E_NOTICE`/`E_DEPRECATED` selama suite berjalan (tanpa `storage/logs/php-*.log`) |
| **G-3** | `git status` bersih; tanpa `tests/.tmp/`, `storage/installed.lock`, `*.log`, tanpa berkas nota sisa |
| **G-4** | Seluruh angka tampilan berasal dari `Formatter`; seluruh output dinamis lewat `Security::e()` |
| **G-5** | Tidak ada perubahan skema/seed; `docs/` tidak berubah (O-1 ditunda) |
| **G-6** | Verifikasi manual satu tutup buku di server dev: baris `daily_settlements`, `hulu_transactions`, kedua `cash_registers`, `cash_mutations`, dan berkas nota saling konsisten; tidak ada overflow horizontal pada 360 px |

---

## 7. Risiko & Mitigasi

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| R-1 | `public/uploads/receipts/` tidak writable di shared hosting | Tutup buku gagal di akhir | Cek direktori **sebelum** transaksi; pesan operator yang jelas; tanpa tulisan separuh |
| R-2 | Perilaku kompresi berbeda antar peramban / JS mati | Nota besar ikut terunggah | Batas keras + validasi MIME di peladen; ukuran ditampilkan; QC menguji pipeline dengan unggahan tiruan, bukan peramban |
| R-3 | `upload_max_filesize`/`post_max_size` lebih kecil dari foto | `$_POST`/`$_FILES` kosong tanpa pesan | Deteksi `CONTENT_LENGTH` vs `post_max_size` dan kode galat `$_FILES` → pesan eksplisit (`422`) |
| R-4 | Berkas yatim bila transaksi gagal | Sampah disk tumbuh diam-diam | `unlink()` pada `Throwable` (A-7); diuji QC grup **C** |
| R-5 | Deadlock dengan jalur kasir | Tutup buku menggantung | Urutan penguncian tetap (A-8): kas → hulu → setting |
| R-6 | Dua settlement serentak memotong defisit yang sama | Defisit hilang dua kali | `SELECT … FOR UPDATE` pada baris `active_carryover_deficit` |
| R-7 | QC menulis berkas ke `public/uploads/receipts/` | Direktori produksi tercemar bila `.env` salah arah | Fixture membersihkan berkasnya sendiri; direktori gitignored; peringatan di suite, README, AGENTS.md |
| R-8 | Selisih pembulatan bagi hasil 80/20 | Total bagi hasil ≠ laba bersih | Porsi kedua diturunkan (`investor = net − operator`) + invariant diuji (A-4) |
| R-9 | Pemindaian statis baru tersandung kelas CSS/nama variabel | QC gagal palsu | Pakai pemindai lokal pola Fase 2 (lewati komentar, SQL case-sensitive, daftar berkas eksplisit) |
| R-10 | `app.min.css` melewati 30 KB setelah kelas baru | Target SAD dilanggar; lambat di sinyal lemah | QC H.7 memverifikasi ukuran; kelas ditulis lengkap (bukan runtime-constructed) |
| R-11 | MariaDB dev vs MySQL 8.0 produksi (item A-1 Fase 1) | Perilaku InnoDB/FK/`FOR UPDATE` belum diverifikasi di MySQL 8.0 | Tidak berubah Fase 3; ulangi `bash tests/run_qc.sh` pada MySQL 8.0 sebelum go-live |

---

## 8. Checklist Serah Terima Fase 3

- [ ] T1–T9 selesai dan berurutan.
- [ ] `bash tests/run_qc.sh` exit `0`; 9 suite; tanpa `php-*.log`.
- [ ] Protokol QC Manual 3 tercetak dan disalin ke dokumen Roadmap.
- [ ] `public/uploads/receipts/` ada dengan `.gitignore` & aturan `.htaccess` yang berlaku; berkas nota **tidak** ter-commit.
- [ ] `capital_wallets` & `profit_distributions` tidak disentuh (bukti QC P3.13).
- [ ] `README.md` + `AGENTS.md` memperbarui baseline, perintah build, dan aturan unggah nota.
- [ ] `ADDENDUM` dokumen ini terisi dengan penyimpangan nyata saat eksekusi.
- [ ] Review diff akhir: hanya berkas pada §3, tanpa artefak sementara.
- [ ] `plan/Summary_Fase_3_AurumFlow.md` (opsional) dibuat setelah verifikasi.

---

## 9. Item Terbuka (perlu keputusan sebelum/di awal eksekusi)

| # | Item | Rekomendasi |
|---|---|---|
| **O-1** | `hulu_transactions.created_at` presisi detik | **Ditunda** (`dec-d784fcb5c5b0720a`). QC yang sensitif urutan tetap bersifat himpunan, seperti Fase 2 |
| **O-2** | Nominal beban aparat sebagai kunci `system_settings` (mis. `daily_coordination_fee_default`) | **Belum** — menuntut pembaruan ERD + seed + addendum. Sementara: isian operator dengan saran tampilan `Rp 200.000` |
| **O-3** | Isian `consumable_opex` | **Belum** — kolom tetap `0.00`; jasa bakar sudah menutup kowi/gas |
| **O-4** | Ekstraksi helper `Money` bersama bila muncul konsumen uang ketiga | Tunda sampai ada konsumen ketiga; saat ini aturan pembulatan tetap milik `TellerService::quote()` dan parser nominal teruji setara (grup B) |
| **O-5** | Lebih dari satu sesi tutup buku per hari | Diizinkan (tanpa `UNIQUE` pada `settlement_date`); didokumentasikan, bukan bug |
| **O-6** | Retensi/backup berkas nota | Di luar lingkup; dicatat untuk operasional hosting (ukuran disk) |
| **O-7** | Verifikasi langsung `403` untuk `/uploads/receipts/test.php` | Dijalankan pada Apache produksi (Fase 6). Harness dev menyetarakan lewat `tests/server_router.php` (T6) |

---

## 10. Lampiran — Penambahan di Luar SAD & Alur Request

### 10.1 Penambahan di luar daftar eksplisit SAD §3

| Penambahan | Alasan | Bila ditolak |
|---|---|---|
| `app/Services/SettlementService.php` | Satu operasi menyentuh lima tabel dalam satu transaksi + aturan defisit & bagi hasil; tidak ada rumah yang tepat di antara Controller (HTTP) dan Model (SQL). SAD sendiri menempatkan mesin kalkulasi sebagai kelas tersendiri | Logika dipindah menjadi `DailySettlement::settle()` |
| `app/Models/DailySettlement.php` | Konvensi satu model per tabel (Fase 2); SQL `daily_settlements` tidak boleh tersebar | Query digabung ke `SettlementService` (melanggar "SQL hanya di Models") |
| `app/Views/operator/settlement.php` | Layar C dokumen UI/UX; tata letaknya berbeda dari layar kasir | Digabung ke `operator/teller.php` (layar menjadi terlalu padat untuk lapangan) |
| `public/uploads/receipts/` | SAD §3 & §4.3 sudah menyebut direktori ini sebagai lokasi nota | Nota disimpan di luar `public/` (butuh handler PHP untuk menyajikan berkas) |
| Kompresor Canvas di `public/assets/js/app.js` | SAD §3 menetapkan berkas ini sebagai rumah "helper kompresi foto nota"; Fase 2 juga menaruh `tellerForm` di sana | Berkas `receipt-compressor.js` terpisah (satu request tambahan & rumah kedua untuk parser field) |
| Hardening `tests/server_router.php` | Tanpa ini, harness dev **mengeksekusi** `.php` di dalam `uploads/`, sehingga klaim "unggahan aman" tidak teruji dan QC bisa lulus palsu | Uji `403` ditunda seluruhnya ke Fase 6 (Apache) dengan catatan risiko |

### 10.2 Alur request `POST /operator/settlement`

```text
POST /operator/settlement   (multipart: field + berkas nota)
   │
   ├─ public/index.php
   │     ├─ security header + session
   │     ├─ cocokkan route (method + path)
   │     ├─ CSRF (_token) ──(gagal)──► 419
   │     └─ dispatch SettlementController::store()
   │
   ├─ SettlementController
   │     ├─ requireAuth() + requireRole('operator') ──(investor)──► 302 /dashboard
   │     ├─ CSRF (pemeriksaan kedua) ──(gagal)──► 419
   │     ├─ Security::consumeFormToken(_once)
   │     │       ├─ duplikat ──► 302 + flash "ganda diabaikan"
   │     │       └─ tidak dikenal ──► 419
   │     ├─ validasi input ──(gagal)──► 422 + form terpelihara
   │     │
   │     └─ SettlementService::settle()
   │            ├─ validasi berkas ──(gagal)──► 422 (0 tulisan, 0 berkas)
   │            ├─ move_uploaded_file() → public/uploads/receipts/<uuid>.<ext>
   │            └─ Database::transaction()
   │                  ├─ SELECT cash_registers … FOR UPDATE (meja, lalu brankas)
   │                  ├─ SELECT hulu_transactions … FOR UPDATE (batch holding)
   │                  │       └─(kosong)──► exception ──► rollBack + unlink berkas
   │                  ├─ guard 0 < berat jual ≤ gram holding
   │                  ├─ SELECT active_carryover_deficit … FOR UPDATE
   │                  ├─ hitung laba kotor/laba bersih/defisit/bagi hasil
   │                  ├─ validasi Smart Auto-Split (jumlah wajib = pendapatan − aparat)
   │                  ├─ INSERT daily_settlements
   │                  ├─ UPDATE hulu_transactions (holding → settled + settlement_id)
   │                  ├─ UPDATE cash_registers ×2 (Kas Meja, Brankas)
   │                  ├─ INSERT cash_mutations ×2 (in)
   │                  └─ UPDATE system_settings.active_carryover_deficit
   │
   └─ flash success + 302 /operator/settlement (POST-Redirect-GET)
```

---

# ADDENDUM — Catatan Eksekusi

> **Status: terverifikasi.** Rencana di atas dieksekusi **tanpa** perubahan
> skema, seed, `system_settings`, maupun `docs/`. Laporan lengkap ada di
> `plan/Summary_Fase_3_AurumFlow.md`.
>
> Hasil gate: **9 suite · 345 assertion · 0 gagal** (exit 0), tanpa
> `storage/logs/php-*.log`, tanpa berkas nota sisa di `public/uploads/receipts/`.

| Area | Rencana | Eksekusi | Alasan |
|---|---|---|---|
| Waktu kompilasi CSS | Build pada T1 | Build di **akhir T5** | Tailwind JIT memindai `app/Views/**/*.php`; membangun sebelum `settlement.php` ada akan menghasilkan CSS tanpa kelas layar baru |
| Saran Smart Auto-Split | "Saran dirender server-side" ke dalam kedua isian | Kedua isian dirender **kosong**; peladen memperlakukan "dua isian kosong" sebagai *pakai saran sistem*, dan Alpine mengisi/menyegarkan saran (memakai `refreshSuggestion()` sampai operator menyunting sendiri) | Saat halaman pertama dibuka, kadar & harga toko belum diketahui sehingga saran belum dapat dihitung. Cara ini membuat jalur tanpa JavaScript benar tanpa mengarang angka |
| Widget saldo brankas | Di header sticky `layouts/operator.php` | Di kartu ringkasan layar rekonsiliasi (header hanya bertambah navigasi) | Header sudah memuat dua metrik; menambah metrik ketiga membuatnya berdesakan pada lebar 360 px |
| Penguncian pos kas | `lockByNameForUpdate()` dipanggil berurutan | Ditambah `CashRegister::lockByNamesForUpdate()` yang mengurutkan nama secara internal; `lockTellerForUpdate()` kini mendelegasikan ke sana | "Urutan tetap" menjadi milik model, bukan disiplin pemanggil; satu sumber SQL, tanpa perilaku baru pada jalur kasir |
| `formatBytes()` pada pesan | — | Aritmetika integer (`intdiv`), bukan pembagian float | Menjaga aturan "tanpa float" tetap berlaku pada seluruh berkas Fase 3, bukan hanya jalur uang |
| QC grup C (atomisitas) | Paksa gagal di tengah transaksi, berkas wajib terhapus | Dijalankan **lewat HTTP multipart** dengan baris `active_carryover_deficit` dihapus | `storeReceipt()` memakai `is_uploaded_file()`; kegagalan harus terjadi *setelah* berkas tersalin, dan `is_uploaded_file()` hanya bernilai true pada unggahan HTTP sungguhan |
| Deteksi baris baru di QC | "baris settlement terbaru" | `qc3_settlement_ids()` + `qc3_new_settlement()` | `created_at` berpresisi detik (O-1): pada Skenario 3.2→3.3 dua sesi dapat lahir pada detik yang sama sehingga urutan "terbaru" tidak deterministik — flake ini benar-benar muncul dua kali sebelum diperbaiki |
| Kasus uji parsing kadar/berat | Menggunakan beban aparat Rp 200.000 | Beban aparat `0` untuk kasus parsing | Berat kecil (mis. 0,001 gr) menghasilkan pendapatan di bawah beban aparat sehingga tertahan validasi bisnis, bukan oleh parser yang sedang diuji |
| Guard berkas besar | Batas keras 4 MB | Tetap 4 MB; pada PHP dev (`upload_max_filesize=2M`) berkas 3 MB ditolak lebih dulu oleh PHP (`UPLOAD_ERR_INI_SIZE`) dengan pesan batas server | Batas efektif adalah nilai terkecil antara keduanya; kedua jalur tetap menghasilkan `422` tanpa tulisan |
| Angka P3.16 pada rencana | Alokasi saran kas Rp 1.930.000 / brankas Rp 370.000 | Kas `Rp 2.010.000` / brankas `Rp 290.000` | Salah hitung pada tabel rencana: rumus A-6 (`brankas = net`) menaruh hanya laba bersih di brankas, sedangkan defisit yang dipulihkan ikut kembali ke meja. Rumus §4.4 yang mengikat; angka rencana diperbaiki |
| Permukaan publik layanan | `storeReceipt()` disebut sebagai API pipeline | `storeReceipt()`/`removeReceipt()` dijadikan **private**; publik hanya `quote()`, `batchContext()`, `settle()`, `receiptDirectory()` | Kriteria "tidak ada kode mati": API yang hanya dipakai di dalam kelasnya sendiri tidak perlu publik |
| Pembersihan kode mati | — | Dihapus: 5 getter Alpine tanpa pemakai (`soldLabel`, `densityLabel`, `autoSplitActive`, `kasLabel`, `brankasLabel`) dan `DailySettlement::find()` | Ditemukan pada review akhir diff; setiap method/getter baru dipastikan punya pemakai nyata |
| **Perbaikan pascarilis layar rekonsiliasi** (`plan/Plan_Fix_Settlement_UI.md`) | — | Placeholder numerik diberi awalan contoh (`mis. 93,50`), `sold_grams` tanpa placeholder, keenam `x-model` memakai `.fill`, `settlementForm.init()` membaca DOM lewat `querySelector` (bukan `$refs.form`) dan memanggil `restoreAutoSplit(false)`; assertion baru `G.1a` + `H.13`–`H.17` | Bug uji manual Fase 3: nilai yang **sudah** dirender peladen (`value="18,050"`, `value="200.000"`) dihapus `x-model` di peramban karena `$refs` belum terdaftar saat `init()` berjalan (urutan directive Alpine 3.17.4 — lihat § 2.3 dokumen tersebut), sementara placeholder numerik membuat field kosong tampak terisi. Gate setelah perbaikan: **9 suite · 355 assertion · 0 gagal** |

---

*Dokumen ini adalah kontrak eksekusi Fase 3. Penyimpangan yang terjadi saat implementasi dicatat pada addendum di atas dan pada `plan/Summary_Fase_3_AurumFlow.md`.*
