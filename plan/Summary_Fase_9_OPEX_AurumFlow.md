# Summary Fase 9 — Beban Operasional (OPEX) & Otomatisasi Settlement

| | |
|---|---|
| **Status** | ✅ Selesai — `bash tests/run_qc.sh` exit 0, **15/15 suite, 900 assertion, 0 gagal** |
| **Dasar** | `audit/Audit_Biaya_Koordinasi_AurumFlow.md` **Opsi B** + arahan PO (modal di layar kasir, otomatisasi settlement, perbaikan rumus) |
| **Tabel baru** | **1** (`operational_expenses`, tabel ke-14) — **nol `ALTER`** pada tabel lama |
| **Migrasi** | **M3** pada `database/migrate.php` (idempoten, terjaga `INSTALL_TOKEN`) |
| **Rumus berubah** | `total_cash = pendapatan toko` (bukan `pendapatan − beban aparat`) |
| **Rumus TIDAK berubah** | laba bersih, bagi hasil 80 : 20, pool 20 %, pemulihan defisit, penyerapan fee talangan |
| **`CACHE_NAME`** | `aurumflow-shell-v3` → **`aurumflow-shell-v4`** |
| **CSS** | **nol kompilasi ulang** (seluruh kelas sudah ada di `app.min.css`) |

---

## 1. Ringkasan

Sebelum Fase 9 beban koordinasi/pengamanan hanya berupa **satu angka yang diketik operator**
di layar rekonsiliasi (`coord_fee`). Angka itu mengurangi pendapatan, sehingga:

- tidak ada baris kas (`cash_mutations.out`) — laci tidak pernah "tahu" uangnya keluar;
- tidak ada penerima, tanggal bayar, maupun pos kas pembayar;
- beban **tidak dapat dicatat** pada hari tanpa penjualan (`quote()` menolak tanpa emas
  `holding`), sehingga uang yang benar-benar keluar hilang dari pembukuan;
- saldo buku laci lebih tinggi daripada uang fisik sampai tutup buku berikutnya — dan
  pemeriksaan saldo (`TellerService::payCash()`, penjagaan pengembalian talangan) memakai
  saldo **buku**, sehingga dapat meloloskan belanja yang secara fisik tidak tertutup.

Fase 9 menjadikannya **voucher kas**: uang keluar dari laci saat itu juga, tercatat lengkap
dengan penerima dan kategori, lalu **diserap otomatis** oleh tutup buku sebagai pengurang laba.
Isian manual dihapus, sehingga beban tidak dapat difiktifkan.

---

## 2. Perubahan basis data

### 2.1 Tabel ke-14 — `operational_expenses`

Sumber kebenaran: `docs/AurumFlow Entity Relationship Diagram.md` § 4 (dokumen diperbarui
**lebih dahulu**), salinan DDL di `database/schema.sql`. DDL yang sama hidup di
`OperationalExpense::ensureSchema()` untuk jalur migrasi lapangan.

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | `CHAR(36)` | UUID v4 (`Security::uuidV4()`) |
| `expense_date` | `DATE` | **tanggal bisnis** pembayaran (bukan `created_at`) |
| `category` | `ENUM('coordination','security','consumable','other')` | append-only; `consumable` mengisi `daily_settlements.consumable_opex` |
| `payee_name` | `VARCHAR(100)` | wajib — beban tanpa penerima tidak dapat diaudit |
| `amount` | `DECIMAL(14,2)` | selalu positif; arah kas selalu keluar |
| `register_id` | `CHAR(36)` | pos kas pembayar (Fase 9: `Kas Meja Bakar`) |
| `mutation_id` | `CHAR(36)` | **satu voucher = satu baris kas** |
| `settlement_id` | `CHAR(36) NULL` | sesi yang menyerap; `NULL` = belum terserap |
| `recorded_by` | `CHAR(36) NULL` | `users.id` pencatat (dari sesi, bukan dari form) |
| `notes` | `TEXT` | catatan bebas (≤ 255 karakter) |
| `created_at` | `TIMESTAMP` | bawaan |

Indeks: `idx_expense_date`, `idx_expense_open (settlement_id, expense_date)` — melayani
"voucher belum terserap, urut `expense_date ASC, created_at ASC, id ASC`" yang dijalankan
setiap render Layar C dan setiap tutup buku — serta `idx_expense_register`.

Kunci asing (alasannya eksplisit, karena tiap aturan punya konsekuensi QC):

| FK | `ON DELETE` | Alasan |
|---|---|---|
| `fk_expense_register` | `RESTRICT` | pos kas dengan riwayat beban tidak boleh hilang |
| `fk_expense_mutation` | `RESTRICT` | pola `miner_loan_ledger`: satu baris kas tidak boleh dihapus tanpa jejak bebannya |
| `fk_expense_settlement` | `SET NULL` | beban adalah **fakta kas** yang berdiri sendiri; sesi boleh dihapus (QC) tanpa menghapus jejak kas |
| `fk_expense_recorder` | `SET NULL` | akun staf boleh dihapus, riwayat kas wajib terbaca |

### 2.2 Migrasi M3

`database/migrate.php` menjalankan `OperationalExpense::ensureSchema()`
(`CREATE TABLE IF NOT EXISTS`) — **idempoten**, tidak menyentuh `storage/installed.lock`,
dan **tidak ada `ALTER TABLE`** di seluruh jalur (dijaga assertion `G.5` `qc_fase_9` serta
`4.7f` `qc_seed`). `QcFixture::ensureInstalled()` memanggil jalur yang sama, sehingga basis data
uji yang lebih tua dari skema tetap lengkap.

---

## 3. Model & service

### 3.1 `app/Models/OperationalExpense.php`

Satu pemilik: label kategori (`CATEGORY_LABELS`), prefiks label mutasi (`MUTATION_PREFIXES`),
daftar putih kategori, DDL, serta query baca/kunci/serap. SQL hanya di model (aturan repo).

| Method | Kegunaan |
|---|---|
| `ensureSchema()` / `exists()` | migrasi M3 + laporan migrasi |
| `insert()` | penjaga nama kolom + kolom wajib (pola `MinerLoanLedger::insert()`) |
| `unabsorbed()` | pratinjau Layar C (tanpa kunci) |
| `lockUnabsorbedForUpdate()` | **satu-satunya** pembacaan terkunci di dalam `settle()` |
| `absorb($settlementId, $ids)` | `UPDATE … WHERE settlement_id IS NULL` (pertahanan terakhir: voucher tidak pernah berpindah sesi) |
| `categories()`, `categoryLabel()`, `mutationPrefix()` | label manusiawi; nilai enum mentah tidak pernah dicetak |

### 3.2 `app/Services/ExpenseService.php` — `pay(array $input): void`

**Satu** `Database::transaction()` dengan urutan tetap (sesuai arahan PO):

```
kunci Kas Meja Bakar (lockTellerForUpdate)
  → cek saldo buku ≥ nominal        (jika tidak: RuntimeException, 422, nol tulisan)
  → INSERT cash_mutations (out)     ← baris kas lahir lebih dulu (FK diperiksa seketika)
  → adjustBalance(−nominal)
  → INSERT operational_expenses     ← voucher, settlement_id = NULL
```

- **Nominal dari klien** hanya `amount` (input), bukan angka turunan. Parser uang **dipinjam**:
  `SettlementService::parseMoneyInput()` + `TellerService::moneyToCents()`/`centsToDecimal()` —
  tidak ada parser ketiga.
- **Kategori** wajib salah satu dari empat nilai enum (`categoryInput()`); **penerima** wajib
  (`payeeInput()`, ≤ 100 karakter); **catatan** opsional (≤ 255 karakter).
- **Label mutasi** `Biaya koordinasi: <penerima>` (per kategori), dipotong agar
  `cash_mutations.source_destination` (VARCHAR 100) tidak terlampaui.
- **`notes` mutasi** memuat jejak mesin: `expense_id=…; kategori=…; tanggal=…; arah=keluar`.
- `recorded_by` diisi dari **sesi** (`TellerController::actorId()`), bukan dari `$_POST`.
- Beban pada hari **tanpa penjualan** tetap tercatat (mode kegagalan F-1 pada audit tertutup).

### 3.3 Perubahan `SettlementService`

| Titik | Sebelum | Sesudah |
|---|---|---|
| `batchContext()` | `holding_mg`, `holding_cost_cents`, `active_deficit_cents`, `bridge_loans` | **+ `coord_fee_cents`**, `consumable_opex_cents`, `open_expenses` (dari `OperationalExpense::unabsorbed()`) |
| `quote()` sumber beban | `parseMoneyInput($input['coord_fee'])` | `coordFeeCents($input, $context)` — **konteks menang**; input hanya seam kalkulasi murni |
| `quote()` kas dialokasikan | `$totalCashCents = $revenueCents − $coordFeeCents` | **`$totalCashCents = $revenueCents`** |
| `quote()` penjagaan | `total_cash < 0` | `coord_fee > revenue` → `422` "Beban operasional melebihi pendapatan toko" |
| `settle()` penguncian | dompet → pos kas → talangan → hulu → setting | + **`operational_expenses`** (`lockUnabsorbedForUpdate()`) setelah hulu, sebelum `system_settings` |
| `settle()` penulisan | `daily_coordination_fee = coord_fee`, `consumable_opex = '0.00'` | `daily_coordination_fee` = subtotal non-konsumsi, `consumable_opex` = subtotal `consumable` (**O-3 tertutup**) |
| `settle()` penyerapan | — | `OperationalExpense::absorb()` + invariant "jumlah baris berubah = jumlah baris terkunci" (jika tidak → rollback) |
| `mutationRows()` notes | ringkasan laba/pool | **+ `beban_voucher`, `beban_total`, `konsumsi_gas`, `aparat`** |

`AllocationEngine` **tidak disentuh**: beban bukan modal belanja, sehingga `capital_utilized`
tetap `Σ net_cash_paid` baris `holding` dan pool 20 % tidak terpengaruh.

---

## 4. Bukti rumus (uji aritmetika + QC)

Angka baku Protokol QC Manual 3: 18,050 gr @ Rp 2.080.000; modal Rp 35.157.500; beban Rp 200.000.

| Besaran | Sebelum Fase 9 | Sesudah Fase 9 |
|---|---|---|
| Pendapatan toko | 37.544.000 | 37.544.000 |
| Beban operasional | 200.000 (diketik) | 200.000 (voucher) |
| **Laba bersih** | **2.186.500** | **2.186.500** (tidak berubah) |
| Pengelola 80 % / pool 20 % | 1.749.200 / 437.300 | **tidak berubah** |
| Kas dialokasikan (`total_cash`) | 37.344.000 | **37.544.000** |
| Saran `kas` (Kas Meja Bakar) | 35.157.500 | **35.357.500** |
| Saran `brankas` | 2.186.500 | 2.186.500 |
| **Saldo laci akhir** | 50.000.000 | **50.000.000** (sama) |

### 4.1 Mengapa rumus harus berubah — bukti double-count

**(a) Status quo** (beban dibayar tunai dari laci, tanpa baris kas):

```
Buku  : 50.000.000 − 35.157.500 (hulu)                  = 14.842.500
        + 35.157.500 (kas)                               = 50.000.000
Fisik : 50.000.000 − 35.157.500 − 200.000 + 37.544.000  = 52.186.500
        − 2.186.500 (laba dipindah ke brankas)           = 50.000.000   ✅
```

Seimbang — tetapi hanya karena beban **tidak pernah** dicatat sebagai kas keluar, dan hanya bila
nominal yang diketik persis sama dengan uang yang diserahkan.

**(b) Menambah baris kas TANPA mengubah rumus** (jebakan yang dihindari):

```
Buku  : 50.000.000 − 35.157.500 − 200.000 (baris kas)   = 14.642.500
        + 35.157.500 (kas masih = pendapatan − beban)    = 49.800.000
Fisik : (sama dengan (a))                                = 50.000.000
Selisih buku vs fisik                                    =    200.000  ❌ setiap hari
```

Beban dipotong **dua kali**: sekali sebagai kas keluar, sekali lagi sebagai pengurang kas yang
dialokasikan. Inilah alasan buku kas OPEX dan perubahan rumus dirilis bersama.

**(c) Implementasi Fase 9** (`total_cash = pendapatan`):

```
Buku  : 50.000.000 − 35.157.500 − 200.000 (baris kas)   = 14.642.500
        + 35.357.500 (kas = pendapatan − brankas)        = 50.000.000  ✅ = fisik
Brankas: 0 + 2.186.500                                   =  2.186.500
Total buku 52.186.500 = total fisik                     ✅
```

Invariant yang dipegang sekarang (dijaga `qc_fase_9` `E.7`/`F.3`):

```
kas + brankas      = pendapatan toko
kas                = modal belanja + beban dibayar dari pos ini + defisit dipulihkan
brankas            = max(0, laba bersih)
laba bersih        = pendapatan − modal − beban − pemulihan defisit      (tidak berubah)
pendapatan         = modal + beban + laba bersih + defisit dipulihkan
```

### 4.2 Anti-fiktif

Karena `total_cash = pendapatan`, beban yang **diketik** akan menurunkan laba sementara `kas`
tetap menerima pendapatan penuh — uang tertahan di laci. Karena itu:

1. `coord_fee` **dihapus** dari view, controller, dan `readInput()` (dijaga `H.8` `qc_fase_9`);
2. `quote()` memakai `$context['coord_fee_cents']` (jumlah voucher) — seam `$input` hanya
   dipakai kalkulasi murni tanpa basis data dan tidak pernah dikirim controller;
3. `settle()` menghitung ulang dari baris **terkunci** dan menandai voucher terserap di dalam
   transaksi yang sama.

---

## 5. UI & route

### 5.1 Layar kasir (`/operator/teller`) — modal, bukan halaman baru

- Kartu **"Beban Operasional"** dengan tombol **`− Catat Beban Kas`**.
- Modal (`x-cloak`, di dalam akar `x-data="tellerForm(…)"` yang **sudah ada** — bukan komponen
  Alpine baru) memuat: **Kategori** (`<select>` dari enum), **Nama Penerima**, **Nominal Tunai**,
  **Catatan (opsional)**, tombol `Catat Beban & Kurangi Kas` yang hanya hidup bila nominal > 0
  dan penerima terisi (`opexSubmitReady`).
- Kontrol form **tidak pernah `:disabled`** (hanya `:readonly="opexSubmitting"`); `select`
  dibiarkan tanpa `disabled` agar kategorinya tetap terkirim.
- Jalur 422 mengembalikan isian + alasan dan membuka kembali modal (pola modal talangan).

### 5.2 Layar rekonsiliasi (`/operator/settlement`) — read-only

- Isian **"Potongan Koordinasi / Aparat Hari Ini" dihapus**.
- Kartu **"Beban Operasional Terserap (otomatis)"**: daftar voucher (penerima, kategori, tanggal,
  nominal), total, dan penjelasan singkat mengapa alokasi kas menerima pendapatan penuh.
- Baris pratinjau `Beban aparat` → **`Beban operasional`**, nilainya dari peladen
  (`opex_fee_cents` pada `alpineConfig`), bukan dari isian.
- Placeholder contoh alokasi kas diperbarui ke `mis. 35.357.500`.

### 5.3 Route

| Method | Path | Handler | Peran |
|---|---|---|---|
| `POST` | `/operator/opex/pay` | `TellerController::storeOpex()` | `operator` **dan** `cashier` (`ALLOWED_ROLES`) |

`requireRole(...)` adalah **pernyataan pertama** (guard-first), lalu CSRF (dua lapis) + token
sekali-pakai `_once` + PRG. Permukaan kasir tetap **dua controller** (`TellerController`,
`KasbonController`); `TellerController` kini punya **empat** titik guard (dijaga `F.2`
`qc_fase_7`).

### 5.4 Frontend

| Berkas | Perubahan |
|---|---|
| `public/assets/js/app.js` | `settlementForm`: `coordFee` dihapus, `opexFeeCents` dari konfigurasi, `totalCashCents = revenueCents`, `inputValid` memakai `opexFeeCents <= revenueCents`, label `opexFeeLabel`; `tellerForm`: state modal OPEX (`opexOpen`, `opexCategory`, `opexPayee`, `opexAmount`, `opexNotes`) + `init()` membaca DOM + `opexSubmitReady`. **Tidak ada `Alpine.data()` baru** (tetap enam komponen) |
| `public/sw.js` | `CACHE_NAME` → **`aurumflow-shell-v4`** (wajib: `app.js` berubah) |
| `app/Views/operator/teller.php` | kartu + modal OPEX |
| `app/Views/operator/settlement.php` | isian beban dihapus, ringkasan read-only |
| `public/assets/css/app.min.css` | **tidak disentuh** — seluruh kelas (`af-card`, `af-input`, `af-btn--ghost`, `af-alert--error`, dst.) sudah ada |

---

## 6. QC

### 6.1 Hasil

```
LULUS : 15/15  qc_pdo qc_formatter qc_security qc_seed qc_roadmap_protocol_1
                qc_fase_2 qc_fase_3 qc_fase_4 qc_fase_5 qc_fase_6 qc_fase_7
                qc_fase_8 qc_fase_9 qc_sql_injection qc_routing
GAGAL : 0/15
```

**Total: 900 assertion** (sebelumnya 853; +47 dari `qc_fase_9`). Tidak ada
`storage/logs/php-*.log` yang terisi.

| Suite | Assertion |
|---|---:|
| `qc_pdo` | 20 |
| `qc_formatter` | 43 |
| `qc_security` | 32 |
| `qc_seed` | 48 |
| `qc_roadmap_protocol_1` | 6 |
| `qc_fase_2` | 66 |
| `qc_fase_3` | 98 |
| `qc_fase_4` | 115 |
| `qc_fase_5` | 130 |
| `qc_fase_6` | 105 |
| `qc_fase_7` | 78 |
| `qc_fase_8` | 66 |
| **`qc_fase_9`** | **47** |
| `qc_sql_injection` | 15 |
| `qc_routing` | 31 |
| **Total** | **900** |

Probe lapis Alpine (di luar `run_qc.sh`, butuh Node) semuanya lulus:
`js_form_init_check` (48), `js_capital_simulator_check` (38), `js_investor_check` (45),
`js_bridge_fee_check` (65), `js_kasbon_check` (24).

### 6.2 `qc_fase_9` — cakupan

| Grup | Assertion | Isi |
|---|---|---|
| **A** | `A.0`–`A.3` | Tiga tulisan satu transaksi: voucher + mutasi `out` + saldo turun; tanggal/kategori/penerima/pos dari peladen; label & notes berjejak `expense_id` |
| **B** | `B.1`–`B.3` | Lima input tidak sah ditolak; beban > saldo ditolak `RuntimeException`; **nol tulisan** (potret diferensial) |
| **C** | `C.1`–`C.6` | Operator mencatat lewat HTTP (302 PRG); anonim `419`; mitra ditolak; **kasir login PIN lalu mencatat** |
| **D** | `D.1`–`D.2` | Replay `_once` tidak mencatat beban kedua; tanpa CSRF `419` |
| **E** | `E.1`–`E.10` | Pratinjau read-only dengan voucher (`E.1b`); dua voucher terserap: `daily_coordination_fee` 150.000 + `consumable_opex` 50.000; laba tetap 2.186.500; `kas` 35.357.500; saldo laci 50.000.000; notes berisi ringkasan beban; layar read-only & **tanpa** `coord_fee` |
| **F** | `F.1`–`F.5` | `total_cash = pendapatan`; invariant pembukuan; beban > pendapatan ditolak; konteks rusak ditolak |
| **G** | `G.1`–`G.5` | Tabel ke-14, `DECIMAL(14,2)`, aturan FK `RESTRICT`/`SET NULL`, ERD & skema sinkron, **nol ALTER** |
| **H** | `H.1`–`H.9` | Tanpa float/`number_format`, satu `Database::transaction()`, SQL tidak bocor, output di-escape, `CACHE_NAME` v4 + 6 komponen Alpine, route nyata, **guard-first**, anti-fiktif, label kategori |
| **I** | `I.1`–`I.2` | `resetExpenseState()` idempoten; voucher tidak menghalangi pembersihan `cash_mutations` |

### 6.3 Assertion lama yang diperbarui (dan alasannya)

| Suite | Assertion | Perubahan |
|---|---|---|
| `qc_seed` | `4.5`/`4.7f` | daftar tabel 13 → **14**; `CREATE TABLE` count 13 → **14** |
| `qc_fase_3` | `A.5`, `A.5b` | manual override harus berjumlah **pendapatan penuh** 37.544.000; saran kas 35.357.500 |
| `qc_fase_3` | `A.11` | hari minus: meja menerima pendapatan penuh Rp 2.050.000 |
| `qc_fase_3` | `A.15` | invariant baru: `total_cash = modal + beban + laba + defisit dipulihkan` |
| `qc_fase_3` | `G.1a` | prefill `coord_fee` dihapus; diganti penjagaan kartu read-only |
| `qc_fase_3` | `P3.8`, `P3.15` | kas menerima 35.357.500 / 2.050.000; `total_cash` = pendapatan |
| `qc_fase_3` | `H.14`, `H.16`, `H.18` | "keenam isian" → **kelima**; `coord_fee` keluar dari paritas kunci |
| `qc_fase_3/4/6` | flow settlement | `QcFixture::seedOpexVoucher()` mempertahankan basis angka "beban Rp 200.000" (kini voucher, bukan isian) |
| `qc_fase_4` | `F.*` | idem — dua titik settlement diberi voucher |
| `qc_fase_5` | `K.1` | daftar tabel 13 → 14 |
| `qc_fase_6` | `D.*`, `E.*`, `F.*` | voucher sebelum setiap tutup buku; alokasi penjagaan `69.800.000` → `70.000.000` (kas diterima = pendapatan penuh) |
| `qc_fase_6` | `H.7`, `G.17`, `G.18` | tabel 14; `CACHE_NAME` v4 |
| `qc_fase_7` | `F.2`, `F.7` | empat titik guard `ALLOWED_ROLES`; `CACHE_NAME` v4 |
| `qc_fase_8` | `I.5` | `CACHE_NAME` v4 |
| `tests/manual/js_form_init_check.js` | 4 assertion | beban dari `opex_fee_cents`; `coordFee` tidak ada lagi; total kas & saran kas baru |
| `tests/manual/js_bridge_fee_check.js` | parameter `settlementComponentWithFields` | argumen beban menjadi **sen konfigurasi** |

### 6.4 Fixture

- `QcFixture::resetExpenseState()` — hapus `operational_expenses` **lalu** mutasi kas miliknya
  (FK `RESTRICT`), idempoten. Dipanggil **paling awal** oleh `resetTellerState()` dan
  `clearSettlementTraces()`, bersanding dengan `resetKasbonState()`.
- `QcFixture::seedOpexVoucher()` — mencatat beban lewat **jalur nyata**
  `ExpenseService::pay()`, bukan INSERT langsung.
- `QcFixture::opexSnapshot()` — potret untuk assertion "nol tulisan".
- Urutan pembersihan lengkap: `operational_expenses` → `miner_loan_ledger` → `miner_loans`
  → `miners` → `bridge_loans` → `profit_distributions` → `daily_settlements` → `cash_mutations`.

---

## 7. Berkas yang berubah

**Baru**

| Berkas | Isi |
|---|---|
| `app/Models/OperationalExpense.php` | model + DDL + query baca/kunci/serap |
| `app/Services/ExpenseService.php` | `pay()` — tiga tulisan, satu transaksi |
| `tests/qc_fase_9.php` | 47 assertion |
| `plan/Summary_Fase_9_OPEX_AurumFlow.md` | dokumen ini |

**Diubah**

| Berkas | Perubahan |
|---|---|
| `docs/AurumFlow Entity Relationship Diagram.md` | tabel 14 + relasi + pertimbangan teknis + `SKEMA TOTAL: 14 TABEL`; komentar `daily_settlements` menjelaskan beban diisi dari voucher |
| `docs/AurumFlow Product Requirement Document v3.0.md` | § 3.3 (mekanisme voucher/kas keluar, `total_cash`), Modul 1 (aksi cepat `− Catat Beban Kas`), Modul 2 (beban = input otomatis), § 7 (tabel ke-14) |
| `docs/AurumFlow Roadmap and QC Documents.md` | **FASE 9** (checklist + **Protokol QC Manual 9**), **Sprint 9**, instruksi instalasi 14 tabel + M1/M2/M3, catatan protokol 3 |
| `docs/AurumFlow System Architecture Document.md` | `ExpenseService` + `OperationalExpense` pada struktur direktori, "delapan kelompok tulisan", catatan penempatan Fase 9 |
| `docs/AurumFlow UI UX Documents.md` | kartu + tombol di Layar B, **Layar B3** (modal), wireframe Layar C (beban read-only), catatan implementasi Fase 9 |
| `plan/*.md` (28 dokumen historis) | banner **"Status dokumen (pasca-Fase 9)"** — menyatakan angka di dalamnya adalah potret fase tersebut dan menyebut keadaan terkini (14 tabel, v4, 15/900) |
| `AGENTS.md` | 14 tabel, urutan kunci + `operational_expenses`, aturan OPEX & anti-fiktif, `CACHE_NAME` v4, allow-list kasir, baseline 15 suite/900 assertion, perintah `qc_fase_9`, fixture |
| `README.md` | § 4.4 (M3), § 4.5, § 7.9 (rumus kas diterima), § 7.14 (allow-list), § 7.16 (modul OPEX), tabel suite, `CACHE_NAME` v4 |
| `database/schema.sql` | tabel ke-14 (nol ALTER) |
| `database/migrate.php` | M3 + laporan |
| `app/Controllers/TellerController.php` | `storeOpex()`, `readOpexInput()`, state modal pada `renderTeller()` |
| `app/Controllers/SettlementController.php` | `coord_fee` dihapus dari input; ringkasan OPEX read-only; `opex_fee_cents` ke Alpine |
| `app/Services/SettlementService.php` | `batchContext()`, `coordFeeCents()`, `expenseTotals()`, `total_cash = revenue`, penyerapan voucher, notes mutasi |
| `app/Views/operator/teller.php` | kartu + modal "Catat Beban Kas" |
| `app/Views/operator/settlement.php` | isian beban dihapus; kartu read-only; label pratinjau |
| `app/routes.php` | `POST /operator/opex/pay` |
| `public/assets/js/app.js` | state OPEX (teller) + `opexFeeCents`/rumus (settlement) |
| `public/sw.js` | `CACHE_NAME` v4 |
| `tests/bootstrap.php` | `resetExpenseState()`, `seedOpexVoucher()`, `opexSnapshot()`, `ensureSchema()` |
| `tests/run_qc.sh` | suite `qc_fase_9` + banner Fase 9 |
| `tests/qc_seed.php`, `qc_fase_3.php`, `qc_fase_4.php`, `qc_fase_5.php`, `qc_fase_6.php`, `qc_fase_7.php`, `qc_fase_8.php` | lihat § 6.3 |
| `tests/manual/js_form_init_check.js`, `js_bridge_fee_check.js` | lihat § 6.3 |

---

## 8. Keputusan yang diambil (dan alasannya)

| # | Keputusan | Alternatif yang ditolak |
|---|---|---|
| D-1 | **Modal di layar kasir**, bukan halaman `/operator/biaya` | Halaman terpisah (arahan PO): menambah satu perjalanan bagi kasir yang sedang memegang uang tunai |
| D-2 | OPEX ditangani `TellerController::storeOpex()` | `ExpenseController` baru: akan menjadi controller ketiga dengan `ALLOWED_ROLES`, melanggar invarian "dua permukaan izin kasir" (`F.3b` `qc_fase_7`) |
| D-3 | State modal hidup di `tellerForm` | `Alpine.data('opexForm')` baru: berisiko belum ada di aset ter-cache → modal hilang (pelajaran Fase 6) |
| D-4 | `total_cash = pendapatan` | Mempertahankan `− beban` (double-count, § 4.1b) |
| D-5 | `coord_fee` **dihapus** dari input settlement | Menyimpannya sebagai koreksi manual: membuka kembali celah biaya fiktif |
| D-6 | Voucher menyerap **semua** yang `settlement_id IS NULL` | Hanya `expense_date = hari ini`: beban hari libur akan menggantung selamanya |
| D-7 | `consumable` mengisi `consumable_opex` | Membiarkan `0.00`: item O-3 tetap terbuka padahal jalurnya sudah ada |
| D-8 | Seam `$input['coord_fee']` dipertahankan **hanya** untuk `quote()` tanpa konteks | Menghapus total: memaksa menulis ulang uji kalkulasi murni tanpa menambah cakupan |
| D-9 | `settlement_id` FK `SET NULL` | `RESTRICT` (pola `bridge_loans`): menambah satu urutan wajib pada pembersihan QC tanpa manfaat |
| D-10 | Tidak ada kunci `system_settings` baru | Anggaran bulanan (K-5 audit): butuh panel admin yang belum ada; dicatat sebagai item terbuka |

---

## 9. Di luar lingkup (item terbuka)

| # | Item | Catatan |
|---|---|---|
| O-9.1 | Anggaran bulanan OPEX (`monthly_coordination_budget`) + badge peringatan | Audit § 12 K-5; butuh panel `system_settings` |
| O-9.2 | Lampiran foto kuitansi per pembayaran | Audit § 12 K-6; pola `storeReceipt()` sudah ada |
| O-9.3 | Beban dari pos selain Kas Meja Bakar | Rumus umum `brankas = net + Σ fee_brankas` (audit § 12 K-3); Fase 9 membatasi ke laci |
| O-9.4 | Rekap/laporan OPEX operator (harian/bulanan, ekspor) | Butuh layar laporan operator |
| O-9.5 | Pembatalan/*void* voucher | Beban adalah fakta kas; koreksi dilakukan dengan voucher lawan (belum ada UI) |
| O-9.6 | Kalender hari libur H-7 (Fase 6B), mode `prorata`, PDF peladen | Warisan fase sebelumnya |

---

## 10. Cara memverifikasi

```bash
# 1. Lint seluruh berkas yang berubah
php -l app/Models/OperationalExpense.php
php -l app/Services/ExpenseService.php
php -l app/Services/SettlementService.php
php -l app/Controllers/TellerController.php
php -l app/Controllers/SettlementController.php
php -l app/Views/operator/teller.php
php -l app/Views/operator/settlement.php

# 2. Migrasi (idempoten; jalankan dua kali untuk membuktikan)
php database/migrate.php
php database/migrate.php

# 3. QC penuh (gerbang kelulusan)
bash tests/run_qc.sh          # exit 0, 15/15, 900 assertion

# 4. Probe lapis Alpine
node tests/manual/js_form_init_check.js
node tests/manual/js_bridge_fee_check.js
node tests/manual/js_kasbon_check.js
node tests/manual/js_capital_simulator_check.js
node tests/manual/js_investor_check.js

# 5. Uji manual (server pengembangan)
php -S 127.0.0.1:8000 -t public tests/server_router.php
#  → /operator/teller  : tombol "− Catat Beban Kas" → catat Rp 200.000
#  → /operator/settlement : kartu "Beban Operasional Terserap" (read-only),
#    isian "Potongan Koordinasi" TIDAK ada, alokasi saran kas naik Rp 200.000,
#    saldo kas meja kembali Rp 50.000.000 setelah eksekusi.
```

> **Peringatan:** `bash tests/run_qc.sh` **menulis** ke basis data yang ditunjuk `.env`
> (termasuk `operational_expenses`, saldo kas, sesi tutup buku, dan berkas nota di
> `public/uploads/receipts/`). Jangan arahkan ke produksi.
