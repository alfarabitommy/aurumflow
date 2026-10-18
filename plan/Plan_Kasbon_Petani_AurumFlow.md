# Plan Modul Kasbon Petani (*Miner Loan & Ledger*) — AurumFlow

> **Dokumen Perencanaan Eksekusi**
> Proyek: **AurumFlow** — sistem manajemen operasional privat untuk perdagangan emas aluvial dengan settlement **T+1**.
> Modul: **Kasbon / Piutang Petani** — permintaan Product Owner sebelum operasi lapangan.
> Status: **`APPROVED`** (`dec-454e02e31b373890`) — belum dieksekusi; T1 belum dimulai.
> Sumber kebenaran: ERD → SAD → Roadmap + Protokol QC → PRD → UI/UX → plan ini.
> Bahasa: prosa Indonesia + istilah teknis Inggris (konvensi `Plan_Fase_1..7`).

| Item | Nilai |
|---|---|
| Runtime | Native PHP **8.2+**, `declare(strict_types=1)`, tanpa framework, tanpa Composer/`vendor/` |
| Frontend | Tailwind **3.4.19** (hasil compile **di-commit**, **NOL kompilasi ulang**) + Alpine.js **3.17.4** lokal |
| Database | MySQL 8.0 / MariaDB — **10 → 13 tabel** (tiga tabel baru), **nol ALTER** pada tabel lama, **satu** kunci `system_settings` baru |
| Permukaan baru | `GET /operator/kasbon`, `POST /operator/kasbon`, `POST /operator/kasbon/angsuran`, `POST /operator/kasbon/petani` |
| Berkas baru | 3 model, 1 service, 1 controller, 1 view, 1 suite QC, 1 probe Node, 1 ringkasan eksekusi |
| Aset frontend | `app.js` **berubah** (komponen `kasbonForm`) ⇒ **`CACHE_NAME` naik ke `aurumflow-shell-v3`**; `app.min.css` **tidak berubah** |
| Baseline → target | **13 suite / 785 assertion** → **14 suite / ≥ 850 assertion**, 0 gagal |
| Keputusan pengguna | `dec-454e02e31b373890` — (1) batas harian = **Σ pokok yang dicairkan hari ini**; (2) **`KasbonController` baru** dengan `ALLOWED_ROLES = [operator, cashier]`; (3) **satu halaman `/operator/kasbon`** memuat semua form |

---

## 0. Executive Summary & File Touches

### 0.1 Konteks bisnis

Penambang datang ke meja bakar tanpa uang untuk kowi, solar, dan ongkos sungai. Hari ini meja kasir hanya punya satu jenis transaksi: **membeli emas**. Ketika penambang meminta panjar, kasir menyerahkan uang tunai tanpa catatan — kas berkurang, emas tidak bertambah, dan pada tutup buku selisih itu tidak dapat dijelaskan. Pemilik kehilangan dua hal sekaligus: **jejak piutang** dan **kendali eksposur** (tidak ada yang mencegah seorang penambang meminjam berkali-kali dalam sehari, atau kasir mencairkan melebihi uang yang benar-benar ada di laci).

Modul Kasbon menutup lubang itu dengan **buku besar piutang** yang:

1. mencatat setiap pencairan sebagai **kas keluar** dari `Kas Meja Bakar` (tiga tulisan atomik: baris pinjaman + saldo kas + mutasi `out`);
2. menegakkan **dua lapis validasi peladen** sebelum uang keluar — batas harian dari `system_settings` **dan** saldo fisik laci;
3. menerima pembayaran **tunai** maupun **Potong Nota** (dipotong dari uang tebus emas) dengan tombol cepat 20 %, 30 %, Lunas;
4. memberi kasir **autocomplete `<datalist>`** sehingga satu ketukan memilih petani beserta sisa utangnya — antrean tidak berhenti untuk membuka layar lain;
5. memberi operator **tab ke-6 "Buku Kasbon"**: buku besar real-time, pemakaian batas harian, dan daftar peminjam aktif.

Prinsip yang dipegang: **angka uang hanya dihitung peladen**. Form hanya mengirim pemilih petani, nominal, metode, dan catatan; sisa utang, batas harian, dan saldo kas dihitung ulang dari basis data di dalam transaksi yang mengunci baris kas.

### 0.2 Tujuan (tujuh hal yang wajib benar)

1. **Tiga tabel baru, nol ALTER.** `miners`, `miner_loans`, `miner_loan_ledger` — `CREATE TABLE IF NOT EXISTS` saja, sehingga `database/migrate.php` idempoten di instalasi lapangan dan `database/install.php` tidak berubah perilakunya.
2. **Kas keluar atomik.** Pencairan = satu `Database::transaction()`: kunci pos kas → kunci baris setting batas → validasi → `miner_loans` + `miner_loan_ledger` + `cash_registers` + `cash_mutations` (`out`). Gagal di titik mana pun → nol tulisan.
3. **Dua lapis validasi, pesan spesifik.** (a) `Σ pokok hari ini + pokok baru ≤ daily_loan_limit`; (b) `pokok ≤ saldo Kas Meja Bakar`. Masing-masing menolak dengan `422` **tanpa tulisan** dan pesan yang menyebut angka sisa.
4. **Dua mekanisme pembayaran.** Tunai (`cash`) dan Potong Nota (`nota`) — arah kas **sama-sama masuk** ke `Kas Meja Bakar` (satu mutasi `in`), karena nota dibayar penuh oleh jalur hulu dan angsuran dicatat terpisah (§ 2.6).
5. **Autocomplete tanpa dependensi baru.** Alpine.js + `<datalist>` native, format `Nama — Telepon/Lokasi | Sisa Utang: Rp X`, plus pendaftaran petani baru **di halaman yang sama** dan PRG yang mengembalikan fokus ke petani tersebut (`?miner=<uuid>`).
6. **RBAC berlapis (*allow-list*).** Hanya **dua** permukaan menerima `cashier`: `TellerController` dan `KasbonController`; setiap controller lain `302` ke beranda peran **tanpa tulisan** dan **tanpa mengonsumsi `_once`** (`requireRole` guard-first).
7. **Nol regresi.** Fase 1–7 tetap: `bash tests/run_qc.sh` exit 0, `SettlementService`/`TellerService`/`AllocationEngine`/`BridgeLoanService` **tidak disentuh**, `app.min.css` byte-identik.

### 0.3 Definisi Selesai (*Definition of Done*)

- [ ] `GET /operator/kasbon` sebagai **operator** → 200 memuat `<datalist>`, empat form, satu token `_once`, dan **tab Buku Kasbon** aktif; sebagai **kasir** → 200; sebagai **mitra** → 302 `/investor/dashboard`; anonim → 302 `/login`.
- [ ] `POST /operator/kasbon` (pencairan) → **302** PRG: `miner_loans` +1 (`status=open`, `balance_remaining = principal_amount`, `issued_on = hari ini`), `miner_loan_ledger` +1 (`disbursement`, `balance_after = pokok`), `cash_mutations` +1 (`out`, `Kasbon: <nama>`), saldo `Kas Meja Bakar` turun **tepat** pokok, `_once` dikonsumsi.
- [ ] Pokok melebihi sisa batas harian → **422** "Sisa batas kasbon hari ini Rp X"; melebihi saldo kas → **422** "Saldo Kas Meja Bakar hanya Rp Y"; keduanya **nol tulisan** (snapshot pinjaman/ledger/mutasi/saldo identik).
- [ ] `POST /operator/kasbon/angsuran` metode `cash` **dan** `nota` → 302: saldo kas **naik** tepat nominal, satu mutasi `in` (`Angsuran kasbon: <nama>`), `balance_remaining` turun, `miner_loan_ledger` +1 `repayment` dengan `payment_method` sesuai; nominal > sisa utang → **422** tanpa tulisan; nominal = sisa utang → `status=settled` + `settled_at` terisi.
- [ ] Satu pembayaran yang melewati dua kasbon terbuka memakai **FIFO** (kasbon tertua lebih dulu): dua baris ledger, **satu** mutasi kas sebesar total.
- [ ] `POST /operator/kasbon/petani` → 302 `?miner=<uuid>`; nama wajib, telepon/lokasi opsional; nomor telepon duplikat → 422 dengan pesan actionable; **nol** baris `users`/`capital_wallets` tercipta.
- [ ] Halaman kasbon **tidak memuat** kata `Brankas`, `laba bersih`, `pool mitra`, `omzet`, `net_settled_profit`, maupun `href` ke `/operator/settlement`, `/operator/capital`, `/operator/kasir`, `/dashboard`.
- [ ] `php database/migrate.php` dijalankan **dua kali** → exit 0, jalankan kedua "sudah diterapkan", tanpa menyentuh `storage/installed.lock`.
- [ ] `public/assets/css/app.min.css` **byte-identik** (ukuran + sha256 sama dengan baseline T1) dan `public/sw.js` menyebut `aurumflow-shell-v3` selaras dengan `AGENTS.md`.
- [ ] `bash tests/run_qc.sh` exit **0** (**14 suite**, ≥ **65** assertion baru, total ≥ **850**, 0 gagal); tidak ada `storage/logs/php-*.log`; `git status` bersih dari artefak.
- [ ] `docs/` (ERD, SAD, Roadmap + Protokol QC Manual 8, PRD, UI/UX), `AGENTS.md`, dan `README.md` diperbarui **lebih dulu** (T1); `# ADDENDUM` terisi saat eksekusi; `plan/Summary_Fase_8_Kasbon_AurumFlow.md` dibuat.

### 0.4 Di Luar Ruang Lingkup

Pembatalan/*void* kasbon atau koreksi nominal (butuh jejak reversal), bunga/denda keterlambatan, hapus/nonaktifkan petani (butuh `miners.is_active` + aturan "tidak boleh ada utang berjalan"), potong nota **otomatis** di dalam form timbang (lihat § 2.6, butuh ALTER `hulu_transactions`), `miner_id` pada `hulu_transactions`, impor/ekspor CSV buku kasbon sisi operator, cetak kuitansi/tanda terima bertanda tangan, sisa utang per petani di layar kasir (`/operator/teller`), penagihan otomatis/WhatsApp, laporan umur piutang (*aging*), dan panel admin `system_settings` untuk `daily_loan_limit` (masih lewat `seed.sql`/`migrate.php`, seperti parameter lain).

### 0.5 Keputusan Terkunci & Asumsi Arsitektural

| ID | Keputusan | Alasan |
|---|---|---|
| **D-1** | Batas harian = **Σ pokok yang dicairkan hari ini** (`issued_on = CURDATE()`), bukan per transaksi | `dec-454e02e31b373890`; sepuluh kasbon Rp 400.000 tidak boleh lolos pada batas Rp 500.000. Ditampilkan sebagai "pemakaian batas hari ini". |
| **D-2** | **`KasbonController` baru** dengan `private const ALLOWED_ROLES = [Constants::ROLE_OPERATOR, Constants::ROLE_CASHIER];` | `dec-454e02e31b373890`; dua permukaan izin **eksplisit**, `TellerController` tetap 3 guard. |
| **D-3** | **Satu halaman** `/operator/kasbon` memuat semua form + buku besar | `dec-454e02e31b373890`; satu sumber markup, nol duplikasi. |
| **D-4** | Komponen Alpine **`kasbonForm`** didaftarkan di `app.js` ⇒ `CACHE_NAME` → **`aurumflow-shell-v3`** | State kompleks (petani terpilih, sisa utang, metode, pratinjau) **dan** aritmetika uang yang harus dapat diperiksa probe Node melalui seam `registry` (pola `js_bridge_fee_check.js`). Kenaikan versi shell wajib oleh AGENTS.md — didokumentasikan + diuji (§ 4.4). Alternatif *inline* `x-data` **ditolak**: cermin uang tidak dapat diuji. |
| **D-5** | Satu baris kas = satu baris ledger; **saldo berjalan disimpan** di `miner_loans.balance_remaining` | Laporan tidak perlu menjumlah ulang; invariant diuji (`pokok − Σ angsuran == balance_remaining`, `balance_after` entri terakhir = saldo). |
| **D-6** | **Potong Nota tidak mengubah `hulu_transactions`** | Pembelian hulu tetap dibayar penuh (`net_cash_paid`) dan angsuran dicatat sebagai mutasi `in` terpisah; efek netto pada laci identik dengan fisik (`−net + angsuran`), dan `modal belanja hulu` pada settlement tetap benar. |
| **D-7** | `miners.phone_number` **NULL-able + UNIQUE** | NULL boleh berulang (MySQL), jadi petani tanpa nomor tetap dapat didaftarkan (koleksi bertahap), sementara nomor yang sama tidak membuat dua identitas piutang. Nomor keluarga dipakai bersama → daftarkan tanpa nomor. |
| **D-8** | Tombol cepat 20 %/30 % adalah **preset UI** di kode (`KasbonService::QUICK_PAY_PCTS`) | Bukan parameter bisnis (tidak mengubah hak siapa pun, hanya mengisi isian yang tetap divalidasi peladen). Yang wajib configurable tetap `daily_loan_limit` di `system_settings`. |
| **D-9** | Nama modul = **Fase 8 — Kasbon Petani** | Docblock `layouts/operator.php` menyebut pekerjaan *unifikasi kerangka UI* sebagai "Fase 8"; T1 memperjelas kalimat itu menjadi "unifikasi kerangka UI" agar tidak ada dua "Fase 8". |

### 0.6 Peta Berkas

**Baru**

| Berkas | Isi |
|---|---|
| `app/Models/Miner.php` | SQL `miners` + `ensureSchema()` (DDL 3 tabel) + daftar autocomplete |
| `app/Models/MinerLoan.php` | SQL `miner_loans`: insert, kunci FIFO, Σ harian, daftar peminjam, penerapan angsuran |
| `app/Models/MinerLoanLedger.php` | SQL `miner_loan_ledger`: insert + riwayat terbaru |
| `app/Services/KasbonService.php` | Aturan uang & transaksi: `disburse()`, `repay()`, `registerMiner()`, `resolveMiner()`, `viewModel()` |
| `app/Controllers/KasbonController.php` | 4 method publik + `guardOnce()` + `renderKasbon()` |
| `app/Views/operator/kasbon.php` | 7 kartu: batas harian, kasbon baru, angsuran, daftar petani, buku besar |
| `tests/qc_fase_8.php` | Protokol QC Manual 8 (grup B–J, § 4.3) |
| `tests/manual/js_kasbon_check.js` | Probe Node lapis Alpine (cermin nominal & init) |
| `plan/Plan_Kasbon_Petani_AurumFlow.md` | Dokumen ini |
| `plan/Summary_Fase_8_Kasbon_AurumFlow.md` | Laporan eksekusi (T12) |

**Berubah**

| Berkas | Perubahan |
|---|---|
| `docs/*.md` (5 dokumen) | ERD § 2/§ 3/§ 4 (3 tabel + `daily_loan_limit`), SAD § struktur, Roadmap FASE 8 + Protokol QC Manual 8, PRD (modul baru), UI/UX (layar Buku Kasbon + tab) — **dikerjakan lebih dulu** |
| `database/schema.sql` | 3 `CREATE TABLE IF NOT EXISTS` di ujung berkas (nol ALTER) |
| `database/seed.sql` | `daily_loan_limit` = `500000.00` (pola `INSERT … SELECT … WHERE NOT EXISTS`) |
| `database/migrate.php` | Migrasi **M2** (3 tabel + 1 setting), idempoten, gate `INSTALL_TOKEN` tetap |
| `app/Models/SystemSetting.php` | `ensure(string $key, string $value, string $description): bool` (idempoten) |
| `app/routes.php` | 4 entry (1 GET + 3 POST) |
| `app/Views/layouts/operator.php` | Tab **Buku Kasbon** (ke-6 bagi operator, ke-2 bagi kasir) + komentar |
| `public/assets/js/app.js` | Komponen `kasbonForm` + registrasi + header daftar fitur |
| `public/sw.js` | `CACHE_NAME = 'aurumflow-shell-v3'` |
| `AGENTS.md`, `README.md` | Peta 13 tabel, dua permukaan izin kasir, v3, perintah migrasi, status fase |
| `tests/bootstrap.php` | `QcFixture::resetKasbonState()`, `seedQcMiner()`, `kasbonSnapshot()`, `purgeQcMiners()`; `resetTellerState()` memanggil `resetKasbonState()` lebih dulu; `ensureInstalled()` menyelaraskan skema kasbon |
| `tests/run_qc.sh` | Suite `qc_fase_8` + komentar header |
| `tests/qc_seed.php`, `tests/qc_fase_6.php`, `tests/qc_fase_7.php` | Lihat § 4.4 |

**Tidak berubah (dijaga assertion)**

`app/Services/TellerService.php`, `SettlementService.php`, `AllocationEngine.php`, `BridgeLoanService.php`, `CashierService.php`; `app/Views/operator/{teller,settlement,capital,kasir}.php`; `app/Views/layouts/{app,auth,investor,report}.php`; `public/assets/css/app.min.css`; seluruh DDL tabel lama.

---

## 1. Skema & Perubahan Database (Migrasi)

### 1.1 Tiga tabel baru

Ditambahkan **di ujung** `database/schema.sql` (setelah `bridge_loans`), gaya dan konvensi sama: PK `CHAR(36)` UUID v4, uang `DECIMAL(14,2)`, `ENGINE=InnoDB`, `utf8mb4_unicode_ci`, `CREATE TABLE IF NOT EXISTS`.

```sql
-- 11. miners — master petani/penambang (koleksi data bertahap)
CREATE TABLE IF NOT EXISTS `miners` (
    `id`           CHAR(36)     NOT NULL,
    `full_name`    VARCHAR(100) NOT NULL COMMENT 'Wajib — satu-satunya identitas yang dituntut saat mendaftar',
    `phone_number` VARCHAR(20)  NULL COMMENT 'Opsional (D-7); UNIQUE, NULL boleh berulang',
    `location`     VARCHAR(100) NULL COMMENT 'Opsional — kampung/sungai/patok',
    `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_miner_phone` (`phone_number`),
    KEY `idx_miner_name` (`full_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. miner_loans — satu baris per pencairan kasbon + saldo berjalan
CREATE TABLE IF NOT EXISTS `miner_loans` (
    `id`                CHAR(36)      NOT NULL,
    `miner_id`          CHAR(36)      NOT NULL,
    `principal_amount`  DECIMAL(14,2) NOT NULL COMMENT 'Pokok saat dicairkan',
    `balance_remaining` DECIMAL(14,2) NOT NULL COMMENT 'Invariant: pokok − Σ angsuran (D-5)',
    `status`            ENUM('open','settled') NOT NULL DEFAULT 'open',
    `issued_on`         DATE          NOT NULL COMMENT 'Tanggal pencairan — dasar batas harian (D-1)',
    `issued_by`         CHAR(36)      NULL COMMENT 'users.id pencatat',
    `settled_at`        DATETIME      NULL,
    `notes`             TEXT          NULL,
    `created_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_loan_miner_status` (`miner_id`, `status`),
    KEY `idx_loan_issued_on` (`issued_on`),
    KEY `idx_loan_status` (`status`),
    CONSTRAINT `fk_loan_miner`  FOREIGN KEY (`miner_id`)  REFERENCES `miners` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_loan_issuer` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. miner_loan_ledger — riwayat kas kasbon (satu baris kas = satu baris ledger)
CREATE TABLE IF NOT EXISTS `miner_loan_ledger` (
    `id`             CHAR(36)      NOT NULL,
    `loan_id`        CHAR(36)      NOT NULL,
    `entry_type`     ENUM('disbursement','repayment') NOT NULL,
    `payment_method` ENUM('cash','nota') NULL COMMENT 'NULL untuk pencairan',
    `amount`         DECIMAL(14,2) NOT NULL COMMENT 'Selalu positif; arah ditentukan entry_type',
    `balance_after`  DECIMAL(14,2) NOT NULL COMMENT 'Sisa utang PINJAMAN INI setelah entri',
    `register_id`    CHAR(36)      NOT NULL,
    `mutation_id`    CHAR(36)      NOT NULL COMMENT 'Jejak silang ke cash_mutations',
    `recorded_by`    CHAR(36)      NULL,
    `notes`          TEXT          NULL,
    `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ledger_loan` (`loan_id`, `created_at`),
    KEY `idx_ledger_created` (`created_at`),
    KEY `idx_ledger_register` (`register_id`),
    CONSTRAINT `fk_ledger_loan`     FOREIGN KEY (`loan_id`)     REFERENCES `miner_loans` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_ledger_register` FOREIGN KEY (`register_id`) REFERENCES `cash_registers` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_ledger_mutation` FOREIGN KEY (`mutation_id`) REFERENCES `cash_mutations` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_ledger_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 1.2 `system_settings.daily_loan_limit`

Parameter bisnis ⇒ hidup di `system_settings`, **bukan** `app/Config/Constants.php` (AGENTS.md: satu sumber kebenaran). Seed (`database/seed.sql`), idempoten dengan pola yang sudah dipakai:

```sql
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`)
SELECT 'daily_loan_limit', '500000.00', 'Batas total pokok kasbon yang boleh dicairkan per hari (Rp)'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `setting_key` = 'daily_loan_limit');
```

Aturan nilai:

- `daily_loan_limit = 0` ⇒ **kasbon dinonaktifkan** (pesan eksplisit, bukan galat teknis) — diuji `qc_fase_8 D.2`.
- Nilai rusak/negatif/tidak ada ⇒ `RuntimeException` fail-closed dengan pesan administrator (bukan diam-diam dianggap tak terbatas).
- Batas bersifat **inklusif** (`Σ + pokok == limit` diterima) — diuji `D.6`.

### 1.3 Invarian data yang dijaga

| Invarian | Dijaga oleh | Diuji |
|---|---|---|
| `0 ≤ balance_remaining ≤ principal_amount` | `KasbonService::repay()` (sen integer) | E.1, E.6 |
| `principal_amount − Σ(angsuran) == balance_remaining` | satu jalur mutasi (`repay`), baris dikunci | E.1, E.7 |
| `balance_after` entri terakhir == `balance_remaining` | setiap tulisan ledger memakai nilai hasil hitung yang sama | C.4, E.1 |
| `status='settled'` ⇔ `balance_remaining = 0` ∧ `settled_at ≠ NULL` | `repay()` menulis ketiganya sekaligus | E.5 |
| `Σ ledger.amount` = Σ mutasi kas kasbon = Σ perubahan `cash_registers` | satu transaksi, satu mutasi per operasi | C.3–C.6, E.1 |
| `Σ pokok issued_on = hari ini ≤ daily_loan_limit` | dicek di dalam transaksi ber-kunci | D.1, D.6 |

### 1.4 Semantik `ON DELETE` (dan konsekuensi QC)

| Kunci | Perilaku | Alasan |
|---|---|---|
| `miner_loans.miner_id` → `miners` | **RESTRICT** | Petani yang punya riwayat tidak boleh hilang; ledger tetap terbaca |
| `miner_loan_ledger.loan_id` → `miner_loans` | **RESTRICT** | Riwayat tidak boleh yatim |
| `miner_loan_ledger.mutation_id` → `cash_mutations` | **RESTRICT** | Satu baris kas tidak boleh dihapus tanpa menghapus jejak piutangnya |
| `issued_by` / `recorded_by` → `users` | **SET NULL** | `QcFixture::purgeQcCashierAccounts()` menghapus akun kasir QC; jejak kasbon tetap ada dengan pencatat "—" |

Konsekuensi urutan pembersihan QC (**wajib**): `miner_loan_ledger` → `miner_loans` → `miners`, dan **`resetKasbonState()` harus berjalan sebelum** `DELETE FROM cash_mutations` di `QcFixture::resetTellerState()`/`clearSettlementTraces()` — pola yang sama dengan `resetBridgeState()` sejak Fase 6.

### 1.5 Urutan penguncian global (diperluas)

AGENTS.md menetapkan: `capital_wallets` → `cash_registers` → `bridge_loans` → `hulu_transactions` → `system_settings`. Modul ini **menyisipkan** `miner_loans` setelah `bridge_loans` (keduanya tabel pinjaman ber-`ORDER BY … ASC, id ASC`):

```
capital_wallets → cash_registers → bridge_loans → miner_loans → hulu_transactions → system_settings
```

Jalur kasbon menyentuh `cash_registers` → `miner_loans` → `system_settings` **tanpa** dompet, bridge, maupun hulu, sehingga urutan relatifnya konsisten dengan settlement (yang juga mengunci pos kas sebelum `system_settings`) — tidak ada siklus tunggu. Baris setting yang dikunci pun **berbeda** (`daily_loan_limit` vs `active_carryover_deficit`), jadi jalur ini tidak memblokir tutup buku lebih lama dari satu transaksi kasir. Diuji struktural (`H.5`).

### 1.6 Migrasi lapangan

| Jalur | Perilaku |
|---|---|
| `database/install.php` | Tidak berubah — `executeSqlFile(schema.sql)`, `executeSqlFile(seed.sql)` otomatis membuat 3 tabel + 1 setting |
| `database/migrate.php` | **M2 baru**: (a) `Miner::ensureSchema()` — tiga `CREATE TABLE IF NOT EXISTS` (DDL tetap di `app/Models/`, pola `User::ensureRoleEnum()`); (b) `SystemSetting::ensure('daily_loan_limit', '500000.00', …)`. Gate `INSTALL_TOKEN`, CLI-only, **tidak** menyentuh `storage/installed.lock`, laporan "diterapkan / sudah diterapkan" |
| `QcFixture::ensureInstalled()` | Memanggil `Miner::ensureSchema()` + `SystemSetting::ensure(…)` **sebelum** `schema.sql` (pola `ensureRoleEnum()`), sehingga QC tetap lulus walau basis data uji lebih tua dari `schema.sql` |

`SystemSetting::ensure()` (publik, idempoten) memakai `INSERT … WHERE NOT EXISTS` setara seed; pemanggil nyata: `database/migrate.php` + `QcFixture::ensureInstalled()`.

### 1.7 Dokumentasi lebih dulu (T1 — gerbang wajib)

| Dokumen | Perubahan |
|---|---|
| `docs/AurumFlow Entity Relationship Diagram.md` | § 1 diagram + § 2 kardinalitas + § 4 DDL tabel 11–13 + seed `daily_loan_limit`; kalimat "10 tabel" → "13 tabel" |
| `docs/AurumFlow System Architecture Document.md` | Struktur `app/Models`, `app/Services`, `app/Controllers`, `Views/operator` + tabel baru |
| `docs/AurumFlow Roadmap and QC Documents.md` | FASE 8 (lingkup, DoD) + **Protokol QC Manual 8** (skenario manual siap salin) |
| `docs/AurumFlow Product Requirement Document v3.0.md` | Modul "Kasbon Petani": batas harian, dua lapis validasi, potong nota, hak akses operator/kasir |
| `docs/AurumFlow UI UX Documents.md` | Layar Buku Kasbon, tab ke-6, format label autocomplete, tombol cepat |
| `AGENTS.md` | Peta 13 tabel; **dua** permukaan izin kasir; posisi `miner_loans` pada urutan kunci; pengecualian baca `SystemSetting` terbatas `daily_loan_limit`; `CACHE_NAME` v3; status Fase 8 |
| `README.md` | Bagian modul kasbon, perintah `php database/migrate.php` (M2), operasi harian kasir |

### 1.8 Yang **tidak** berubah (dan mengapa)

- **`hulu_transactions` nol ALTER** — tidak ada `miner_id`: potong nota dicatat sebagai angsuran, bukan sebagai harga beli yang lebih murah (D-6). Ini menjaga `modal belanja hulu = Σ net_cash_paid` dan seluruh angka settlement Fase 3–6.
- **`SettlementService`/`AllocationEngine` nol perubahan** — piutang petani **bukan** modal belanja hulu dan **bukan** beban; ia hanya mengurangi/menambah `cash_registers.current_balance` (kas fisik). Bagi hasil 80 : 20 tidak terpengaruh, sehingga `investor_pool_20` dan `balance_profit` mustahil bergeser oleh kasbon.
- **`TellerService::payCash()` nol perubahan** — jalur bayar tunai tetap penuh; angsuran adalah operasi terpisah di halaman kasbon.
- **`cash_mutations` skema tidak berubah** — memakai `mutation_type` `in`/`out` yang sudah ada; jejak silang ke ledger ada di `miner_loan_ledger.mutation_id`.
- **`app.min.css` nol kompilasi** — seluruh kelas yang dipakai view baru sudah ada di berkas terkompilasi (§ 3.5).

---

## 2. Integrasi Arsitektural (Controller, Model, Service)

### 2.1 Peta route (`app/routes.php` — tetap data saja)

```php
// Buku Kasbon (Fase 8): permukaan KEDUA yang menerima peran cashier.
// Semua mutasi memakai token sekali-pakai `_once` + PRG.
'GET' => [
    '/operator/kasbon'           => [KasbonController::class, 'index'],
],
'POST' => [
    '/operator/kasbon'           => [KasbonController::class, 'disburse'],
    '/operator/kasbon/angsuran'  => [KasbonController::class, 'repay'],
    '/operator/kasbon/petani'    => [KasbonController::class, 'storeMiner'],
],
```

Tidak ada perubahan `public/index.php` (dispatch + CSRF non-GET sudah generik).

### 2.2 `KasbonController` (`app/Controllers/KasbonController.php`)

```php
private const VIEW   = 'operator/kasbon';
private const LAYOUT = 'operator';
private const ROUTE  = '/operator/kasbon';

/** Permukaan KEDUA yang menerima peran kasir (D-2). */
private const ALLOWED_ROLES = [Constants::ROLE_OPERATOR, Constants::ROLE_CASHIER];

public function index(): void;      // GET  — render + ?miner=<uuid> preselect
public function disburse(): void;   // POST — pencairan kasbon
public function repay(): void;      // POST — angsuran tunai / potong nota
public function storeMiner(): void; // POST — daftarkan petani baru

private function guardOnce(string $duplicateMessage): void;          // requireRole → CSRF → _once
private function renderKasbon(?string $error, array $input, int $status = 200): void;
```

Aturan yang dipegang (mengikuti `CashierController` + `TellerController`):

1. **`requireRole(...self::ALLOWED_ROLES)` adalah pernyataan PERTAMA** setiap method publik (`index()` termasuk, lewat `renderKasbon()` yang memanggilnya lagi) — permintaan terlarang `302` ke beranda peran, **nol tulisan**, **`_once` tidak dikonsumsi** (guard-first).
2. **CSRF dua lapis** (front controller + di sini) + **token sekali-pakai `_once`** untuk ketiga jalur mutasi.
3. **Nominal dari klien tidak dipercaya**: form hanya mengirim `miner_label`/`miner_id`, `principal_amount`/`amount`, `payment_method`, `notes`. Sisa utang, batas harian, saldo kas, dan `balance_after` dihitung ulang `KasbonService`.
4. **PRG wajib**: sukses → `flash()` + `redirect(self::ROUTE)` (atau `?miner=<uuid>` setelah petani baru) supaya halaman berikutnya menerbitkan `_once` baru.
5. **Gagal validasi → `422`** + form terisi kembali (nilai dirender peladen, dibaca `kasbonForm::init()` dari DOM).

### 2.3 Model (SQL hanya di `app/Models/`)

| Model | Method publik | Pemanggil nyata |
|---|---|---|
| `Miner` | `insert(array $data): void` | `KasbonService::registerMiner()` |
| | `findById(string $id): ?array` | `KasbonService::resolveMiner()` |
| | `findByPhone(string $phone): ?array` | `KasbonService::registerMiner()` (anti-duplikat) |
| | `listForAutocomplete(int $limit): array` | `KasbonService::minerOptions()` / `viewModel()` |
| | `ensureSchema(): bool` | `database/migrate.php` (M2), `QcFixture::ensureInstalled()` |
| `MinerLoan` | `insert(array $data): void` | `KasbonService::disburse()` |
| | `lockOpenByMinerForUpdate(string $minerId): array` | `KasbonService::repay()` (FIFO) |
| | `sumDisbursedOn(string $date): string` | `KasbonService::usageToday()` + `disburse()` (di dalam kunci) |
| | `applyRepayment(string $loanId, string $balance, bool $settled): int` | `KasbonService::repay()` |
| | `activeBorrowers(int $limit): array` | `KasbonService::viewModel()` |
| | `outstandingTotal(): string` | `KasbonService::usageToday()` (ringkasan kartu) |
| `MinerLoanLedger` | `insert(array $data): void` | `KasbonService::disburse()`/`repay()` |
| | `recent(int $limit): array` | `KasbonService::viewModel()` |

Ketentuan SQL: seluruh nilai dinamis lewat **named parameter**; `ORDER BY … ASC, id ASC` untuk FIFO (deterministik walau `created_at` berpresisi detik — item O-1); `FOR UPDATE` hanya di dalam `Database::transaction()`.

### 2.4 `KasbonService` — aturan uang & transaksi

```php
final class KasbonService
{
    public const SETTING_DAILY_LIMIT = 'daily_loan_limit';
    public const METHOD_CASH = 'cash';       // angsuran tunai
    public const METHOD_NOTA = 'nota';       // potong nota emas
    public const LABEL_OUT_PREFIX = 'Kasbon: ';            // + nama ≤ 60 → ≤ 68 char (kolom 100)
    public const LABEL_IN_PREFIX  = 'Angsuran kasbon: ';   // + nama ≤ 60 → ≤ 77 char (kolom 100)
    public const MAX_NAME_CHARS = 60;
    public const MAX_LOCATION_CHARS = 100;
    public const MAX_PHONE_CHARS = 20;
    public const AUTOCOMPLETE_LIMIT = 200;   // ukuran maksimum <datalist>
    public const BORROWER_LIMIT = 50;
    public const LEDGER_LIMIT = 20;
    public const QUICK_PAY_PCTS = [20, 30];  // preset UI (D-8); "Lunas" = 100 %

    public static function dailyLimit(): array;                    // limit, cents
    public static function usageToday(): array;                    // used, remaining, percent, outstanding_total
    public static function registerMiner(array $input): array;     // id, full_name, phone, location
    public static function disburse(array $input): array;          // satu transaksi
    public static function repay(array $input): array;             // satu transaksi
    public static function resolveMiner(string $minerId, string $typedLabel): array;
    public static function minerOptions(): array;                  // label + id + outstanding_cents
    public static function viewModel(?string $selectedMinerId): array;
}
```

**Parsing uang — satu pemilik, bukan salinan ketiga.** `KasbonService` **mendelegasikan** ke `SettlementService::parseMoneyInput($raw, 'Nominal kasbon')` (aturan titik = ribuan, koma = desimal), lalu memakai `TellerService::moneyToCents()`/`centsToDecimal()` untuk konversi. Tidak ada implementasi parser baru (AGENTS.md: "jangan membuat salinan ketiga dari fungsi yang sama"). Aritmetika seluruhnya **sen integer**; simpan sebagai string desimal kanonik; **tanpa float**.

**`disburse($input)` — satu `Database::transaction()`:**

| # | Langkah | Catatan |
|---|---|---|
| 1 | `CashRegister::lockTellerForUpdate()` | pos kas `Kas Meja Bakar`; tidak ada → `RuntimeException` |
| 2 | `SystemSetting::getForUpdate(self::SETTING_DAILY_LIMIT)` | **terakhir** pada urutan kunci; menyerialkan pemeriksaan batas sehingga dua terminal tidak dapat melewati batas bersama |
| 3 | `Miner::findById()` (dari `resolveMiner()`) | petani harus ada |
| 4 | `MinerLoan::sumDisbursedOn(date('Y-m-d'))` | `used_cents` |
| 5 | Validasi batas: `used + pokok ≤ limit` | gagal → `InvalidArgumentException` "Sisa batas kasbon hari ini Rp X" |
| 6 | Validasi kas: `pokok ≤ saldo` | gagal → "Saldo Kas Meja Bakar hanya Rp Y" |
| 7 | `MinerLoan::insert()` (`open`, `balance = pokok`, `issued_on = hari ini`, `issued_by = $_SESSION['user_id']` bila UUID) | |
| 8 | `CashRegister::adjustBalance(-pokok)` | |
| 9 | `CashMutation::insert(out, pokok, 'Kasbon: <nama>', notes: loan_id; pokok; sisa batas)` | |
| 10 | `MinerLoanLedger::insert(disbursement, method NULL, pokok, balance_after = pokok)` | `mutation_id` dari langkah 9 |
| 11 | `Logger::info('Kasbon dicairkan', …)` setelah commit | |

**`repay($input)` — satu `Database::transaction()`:**

| # | Langkah | Catatan |
|---|---|---|
| 1 | `CashRegister::lockTellerForUpdate()` | uang masuk ke pos yang sama |
| 2 | `MinerLoan::lockOpenByMinerForUpdate($minerId)` | `status='open'`, `ORDER BY issued_on ASC, created_at ASC, id ASC` (FIFO, pola `BridgeLoan::lockActiveForUpdate()`) |
| 3 | Sisa utang = Σ `balance_remaining`; `nominal ≤ sisa` (ketat) | lebih → `422` "Sisa utang <nama> hanya Rp X" **tanpa tulisan** |
| 4 | Alokasi FIFO per baris pinjaman | tiap pinjaman: `balance' = balance − bayar`; `0` ⇒ `status=settled` + `settled_at` |
| 5 | `MinerLoanLedger::insert(repayment, method, potongan, balance_after)` **per pinjaman tersentuh** | riwayat per pinjaman utuh |
| 6 | `CashMutation::insert(in, nominal, 'Angsuran kasbon: <nama>', notes: loan ids; metode)` — **satu** baris | uang fisik masuk satu kali |
| 7 | `CashRegister::adjustBalance(+nominal)` | |

**`registerMiner($input)`** — nama wajib (2–60 karakter, `Security::sanitize`, UTF-8 valid); telepon opsional (`/^[0-9+\-\s]{8,20}$/`, sama dengan installer) dan duplikat → `InvalidArgumentException` yang **menyebut nama pemilik nomor** (bukan "sudah dipakai" buta); lokasi opsional ≤ 100; **tanpa** transaksi kas, **tanpa** baris `users`/`capital_wallets`; audit di `Logger::info`.

**`resolveMiner($minerId, $typedLabel)` — kontrak "peladen tidak mempercayai klien":**

1. `Security::isUuid($minerId)` ∧ `Miner::findById()` → **pakai** (jalur utama; `kasbonForm` mengisi `miner_id` dari `data-miner-id`);
2. jika tidak: bandingkan teks sebelum `" | "` dengan label kandidat (`Nama — Kontak`) → **pakai** bila tepat satu;
3. jika tidak: cocokkan `full_name` secara eksak → **pakai** bila tepat satu;
4. selain itu `InvalidArgumentException` "Pilih petani dari daftar atau daftarkan petani baru." (`422`).

Ini membuat form tetap benar tanpa JavaScript (datalist hanya saran; teks yang diketik divalidasi peladen) — diuji `C.8`/`D.4`.

### 2.5 RBAC: matriks izin & perubahan kontrak

| Permukaan | `operator` | `cashier` | `investor` | anonim |
|---|---|---|---|---|
| `GET /operator/kasbon` | 200 | **200** | 302 `/investor/dashboard` | 302 `/login` |
| `POST /operator/kasbon` (`disburse`) | 302 PRG | **302 PRG** | 302, **nol tulisan** | 302 `/login` |
| `POST /operator/kasbon/angsuran` | 302 PRG | **302 PRG** | 302, **nol tulisan** | 302 `/login` |
| `POST /operator/kasbon/petani` | 302 PRG | **302 PRG** | 302, **nol tulisan** | 302 `/login` |
| Semua rute Fase 1–7 lainnya | tetap | 302 `/operator/teller` | tetap | tetap |

Kontrak AGENTS.md yang **diperbarui** (bukan dilanggar): "satu-satunya permukaan yang menerima `cashier`" → **"dua permukaan, masing-masing dengan `ALLOWED_ROLES` eksplisit: `TellerController` (kasir + talangan) dan `KasbonController` (buku kasbon). Setiap controller lain memakai `requireRole(ROLE_OPERATOR)`/`(ROLE_INVESTOR)` dan tidak pernah menyebut `ROLE_CASHIER`."** Ditambah pengecualian sempit: berkas terjangkau kasir **boleh** membaca `SystemSetting` **hanya** untuk `KasbonService::SETTING_DAILY_LIMIT` (parameter operasional, bukan data pemilik) — diuji `G.5` dengan pemindai baris, sehingga pembacaan setting lain di masa depan langsung gagal.

### 2.6 Aliran kas & pembukuan (mengapa potong nota aman)

```
Pencairan   : cash_registers −pokok   |  cash_mutations out  |  miner_loans open + ledger(disbursement)
Angsuran    : cash_registers +nominal |  cash_mutations in   |  ledger(repayment) × pinjaman tersentuh
Potong nota : idem angsuran (method='nota')
```

**Skenario potong nota** — penambang menyerahkan 5 gr @ Rp 1.950.000 (neto tebus Rp 9.730.000) dan meminta potong nota Rp 300.000:

1. kasir mencatat pembelian hulu **seperti biasa** (Rp 9.730.000 keluar) — `hulu_transactions` tetap jujur tentang harga emas;
2. kasir mencatat angsuran `nota` Rp 300.000 di halaman kasbon — Rp 300.000 **masuk**;
3. efek netto laci: `−9.730.000 + 300.000 = −9.430.000`, **persis** uang yang diserahkan secara fisik;
4. `modal belanja hulu` pada tutup buku tetap Rp 9.730.000 ⇒ laba, bagi hasil 80 : 20, dan pool mitra **tidak** terdistorsi oleh piutang;
5. `cash_mutations` seimbang dengan `hulu_transactions` + angsuran, sehingga rekonsiliasi fisik pagi tetap dapat dipertanggungjawabkan.

Alternatif (memotong langsung di form timbang) **ditolak** untuk fase ini: ia menuntut ALTER `hulu_transactions` (`miner_id`, `loan_deduction`), perubahan `TellerService::payCash()` (jalur uang beku), dan mengubah arti `net_cash_paid` yang dipakai `SettlementService` — risiko regresi jauh lebih besar daripada satu ketukan tambahan. Dicatat sebagai item terbuka O-3.

### 2.7 Interaksi dengan modul lain

| Modul | Interaksi | Bukti nol regresi |
|---|---|---|
| Settlement (Fase 3–4) | hanya lewat `cash_registers.current_balance` | `qc_fase_3`/`qc_fase_4` lulus; tidak ada pemanggilan baru di `SettlementService` |
| Dana talangan (Fase 6) | tidak berbagi baris maupun kunci; talangan mengunci `bridge_loans`, kasbon mengunci `miner_loans` | `qc_fase_6` lulus; urutan kunci tetap konsisten |
| Kasir (Fase 7) | permukaan kedua, izin eksplisit | `qc_fase_7` (F.3/F.6/F.7/E.8 diperbarui, § 4.4) |
| Portal mitra (Fase 5) | **nol** — tidak ada rute/berkas investor yang berubah | `qc_fase_5` lulus; daftar terlarang investor tak tersentuh |

---

## 3. Logika UI/UX

### 3.1 Tab ke-6 — `app/Views/layouts/operator.php`

Sisipkan **satu** item setelah blok `if (!$isCashier) { … }`, sehingga:

- **operator** (6 tab): `Dashboard | Kasir | Rekonsiliasi | Kelola Modal | Kelola Kasir | Buku Kasbon`
- **kasir** (2 tab): `Kasir | Buku Kasbon`

```php
// Buku Kasbon (Fase 8): permukaan yang menerima operator DAN kasir.
// Diletakkan setelah blok khusus-operator agar menjadi tab KEENAM bagi
// operator dan tab KEDUA bagi kasir tanpa cabang tambahan.
$navItems[] = ['path' => App::url('operator/kasbon'), 'label' => 'Buku Kasbon', 'active' => false];
```

Kelas baris tab **tidak berubah** (`af-btn shrink-0 px-3 text-xs`, pembungkus `flex … overflow-x-auto`), jadi penggeseran horizontal di ponsel dan penanda `aria-current` bekerja seperti tab lain; sub-path (`/operator/kasbon/angsuran`) otomatis menandai induknya lewat logika `str_starts_with` yang sudah ada. Docblock layout diperbarui (peran, jumlah tab, dan penjelasan D-9).

### 3.2 Anatomi `app/Views/operator/kasbon.php`

Mobile-first, satu kolom, `af-card` bertumpuk (pola `teller.php`/`capital.php`):

| # | Kartu | Isi | Sumber angka |
|---|---|---|---|
| 1 | **Batas Kasbon Hari Ini** | `Terpakai Rp X dari Rp Y (Z %)` + `Sisa Rp R` + `Utang berjalan Rp T`; bilah `h-2 rounded-full` (lebar via `style` dari integer persen peladen) | `KasbonService::usageToday()` |
| 2 | **Kasbon Baru** | autocomplete petani (datalist #1) + `principal_amount` + `notes` + tombol kirim | `dailyLimit()`/`usageToday()` untuk sisa |
| 3 | **Bayar Kasbon** | autocomplete (datalist #2) + radio metode `Tunai`/`Potong Nota` + `amount` + tombol cepat **20 % / 30 % / Lunas** + pratinjau "Sisa setelah bayar" | `minerOptions()` (sisa utang per petani) |
| 4 | **Daftarkan Petani Baru** | `full_name` (wajib), `phone_number` (opsional), `location` (opsional) | — |
| 5 | **Petani dengan Utang Berjalan** | `af-table`: Nama · Kontak/Lokasi · Sisa Utang · Kasbon terakhir · tombol "Pilih" (`href` `?miner=<uuid>`) | `activeBorrowers()` |
| 6 | **Riwayat Buku Kasbon** | `af-table` 20 entri terakhir: Tanggal · Petani · Jenis · Nominal · Sisa setelah · Dicatat oleh | `MinerLoanLedger::recent()` |
| 7 | **Petunjuk singkat** | "Potong nota = pembelian hulu dibayar penuh, angsuran dicatat terpisah." | teks statis |

Semua output dinamis lewat `Security::e()`; seluruh angka tampilan lewat `Formatter::rupiah()`/`tanggalId()` (dilarang `number_format`).

### 3.3 Alpine.js + `<datalist>` — autocomplete petani

**Markup (pola yang mengikat):**

```php
<div x-data="kasbonForm({ mode: 'repay' })">
    <label class="af-label" for="kasbon-pay-miner">Petani / Penambang</label>
    <input id="kasbon-pay-miner" name="miner_label" type="text" list="kasbon-pay-miners"
           class="af-input" autocomplete="off" placeholder="mis. Budi — 0812… | Sisa Utang: Rp 250.000"
           x-model.fill="minerLabel" @input="pickMiner()"
           value="<?= Security::e($input['miner_label']) ?>">
    <input type="hidden" name="miner_id" x-model="minerId" value="<?= Security::e($input['miner_id']) ?>">

    <datalist id="kasbon-pay-miners">
        <?php foreach ($minerOptions as $option): ?>
            <option value="<?= Security::e($option['label']) ?>"
                    data-miner-id="<?= Security::e($option['id']) ?>"
                    data-outstanding="<?= (int) $option['outstanding_cents'] ?>"></option>
        <?php endforeach; ?>
    </datalist>
</div>
```

**Format label** (satu pemilik: `KasbonService::minerOptions()`), persis seperti permintaan PO:

```
Budi — 0812-3456-7890 | Sisa Utang: Rp 250.000        (telepon ada)
Sari — Sungai Kandi | Sisa Utang: Rp 0                (hanya lokasi)
Joko | Sisa Utang: Rp 120.000                         (tidak ada kontak)
```

Aturan: segmen kontak **dihilangkan seluruhnya** bila telepon & lokasi kosong; bila keduanya ada → `telepon · lokasi` (lokasi dipotong 40 karakter agar terbaca di ponsel); nominal memakai `Formatter::rupiah()`; daftar dibatasi `AUTOCOMPLETE_LIMIT = 200` dengan urutan **berutang lebih dulu**, lalu nama (A→Z), sehingga petani yang paling sering dilayani selalu di atas.

**Komponen `kasbonForm` (`public/assets/js/app.js`) — state & perilaku:**

| Bagian | Perilaku |
|---|---|
| `init()` | membaca **DOM** (bukan `$refs`): `formFieldValue(this,'miner_label')`, `formFieldValue(this,'amount')`, nilai radio metode lewat `formRadioValue()`; membangun `this.index` dari `this.$root.querySelectorAll('datalist option')` (`value → {id, outstandingCents}`) — **tidak ada** data petani yang disuntikkan sebagai JSON ke JS (nama adalah data pengguna, tetap di DOM hasil `Security::e()`) |
| `pickMiner()` | `minerId = index[minerLabel]?.id ?? ''`; `outstandingCents` menyusul; pratinjau diperbarui (mode `repay`) |
| `pickPercent(20|30)` | `amount = rupiahText(intdiv(outstandingCents × pct + 50, 100))` — **aritmetika sen integer**, half away from zero (cermin `KasbonService`) |
| `payFull()` | `amount = rupiahText(outstandingCents)` |
| `get afterLabel()` | `max(0, outstanding − amountCents)`; tanpa petani terpilih → `—` |
| `rupiahText(cents)` | `'1.234.567'` / `'1.234.567,50'` — **hanya** pemisah ribuan titik + koma desimal, tepat seperti yang diterima `parseMoneyInput()` peladen |
| `submitting` | `:readonly="submitting"` + `opacity-70 cursor-not-allowed` pada kontrol; `:disabled="submitting"` **hanya** pada `<button>` |

**Empat jaminan yang diuji:** (a) tanpa JavaScript form tetap berfungsi (datalist hanya saran; nominal diketik manual; peladen memvalidasi); (b) jalur `422` mengembalikan isian peladen dan `init()` **tidak** menghapusnya (pola `formFieldValue`, bukan `$refs`); (c) tidak ada `x-data` yang memanggil komponen tak terdaftar — `kasbonForm` terdaftar di `app.js` **dan** `CACHE_NAME` dinaikkan (D-4); (d) atribut Alpine berupa **ekspresi**, bukan blok pernyataan (`@click="pickPercent(20)"`, bukan `try { … }`).

### 3.4 Tombol cepat 20 % / 30 % / Lunas

- Hanya mengisi isian nominal; **tidak** mengirim apa pun. Peladen tetap menerima nominal dan memvalidasinya (`0 < nominal ≤ sisa utang`).
- Angka dihitung dari `data-outstanding` peladen (integer sen) — bukan dari teks yang diketik kasir.
- Bila sisa utang berubah karena perangkat lain (mis. operator melunasi lebih dulu), peladen menolak dengan `422` yang jelas, bukan menyimpan angka basi.
- "Lunas" mengisi **tepat** sisa utang → peladen menandai `settled` (uji `E.5`).

### 3.5 Nol kompilasi CSS — bukti & aturan

`public/assets/css/app.min.css` (24.264 byte, ter-commit) adalah hasil Tailwind JIT yang **hanya memuat kelas yang benar-benar dipakai view lama**. Karena itu view baru **wajib** dibangun dari inventaris kelas yang ada. Kelas yang boleh dipakai (semua terverifikasi ada di berkas):

- Komponen: `af-card`, `af-card__title`, `af-card__subtitle`, `af-glow`, `af-label`, `af-input`, `af-input--sm`, `af-num`, `af-facts`, `af-table`, `af-btn`, `af-btn--primary`, `af-btn--ghost`, `af-btn--block`, `af-alert`, `af-alert--error`, `af-alert--success`, `af-alert--info`.
- Utilitas: `grid`, `gap-1..5`, `mt-1..6`, `mb-4/5/6`, `p-3/4/5`, `px-1..4`, `py-1/2/3`, `flex`, `flex-wrap`, `items-center/end/start/baseline`, `justify-between/center`, `text-xs/sm/base/lg/xl/2xl/3xl`, `font-medium/semibold/bold`, `text-pure/muted/gold-primary/gold-glow/emerald-cash/red-200/red-300/obsidian-950`, `bg-surface-800/900`, `bg-gold-primary`, `bg-obsidian-950`, `border`, `border-t`, `border-b`, `border-surface-800`, `border-gold-primary/20`, `border-collapse`, `rounded`, `rounded-lg/xl/2xl/full`, `h-2`, `h-full`, `w-full`, `min-w-0`, `max-w-2xl`, `max-w-md`, `max-h-40`, `overflow-x-auto`, `overflow-y-auto`, `truncate`, `shrink-0`, `sticky`, `top-0`, `z-20`, `relative`, `absolute`, `inset-0`, `block`, `inline`, `hidden`, `text-right/center/left`, `uppercase`, `tracking-wide/wider`, `leading-tight/relaxed`, `select-none`, `cursor-not-allowed`, `opacity-70`, `transition`, `duration-75`, `divide-y`, `divide-surface-800`, `grid-cols-3`, `sm:grid-cols-2/3`, `min-h-touch`.

**Larangan:** nilai arbitrary (`text-[0.6rem]`, `w-[42%]`, `gap-[7px]`), kelas baru (`af-kasbon*`), `@apply` baru, dan **tidak boleh** menjalankan `npx tailwindcss`. Bilah pemakaian batas memakai lebar **`style`** dari integer peladen, bukan kelas dinamis — ini juga menjaga risiko R-10 ("seluruh kelas dibangun lengkap di markup").

Dijaga tiga assertion: `I.6` (ukuran + sha256 `app.min.css` identik), `I.7` (subset kelas: setiap token `class="…"` pada view baru ada di `app.min.css`), `I.10` (tidak ada kompilasi ulang: `resources/` tidak berubah).

### 3.6 Target sentuh, aksesibilitas, mobile-first

`af-btn--block` untuk aksi utama, `min-h-touch` (52 px, UI/UX § 2.1) pada tombol cepat, label eksplisit `af-label` + `for`, radio metode memakai `fieldset`/`legend` (`px-1`), `role="alert"` pada galat, `aria-current="page"` pada tab (sudah dari layout), pratinjau angka memakai `aria-live="polite"`, dan tidak ada kontrol `disabled` selain `<button>` (AGENTS.md — kontrol `disabled` hilang dari data form).

### 3.7 Isolasi data pemilik (permukaan kasir)

Daftar terlarang untuk `KasbonController`, `KasbonService`, `Miner`/`MinerLoan`/`MinerLoanLedger`, dan `Views/operator/kasbon.php`: `BRANKAS_NAME`, `findBrankas`, `CapitalWallet`, `ProfitDistribution`, `AllocationEngine`, `profit_distributions`, `net_settled_profit`, `investor_pool_20`, `daily_settlements`, serta istilah tampilan **"laba bersih", "pool mitra", "omzet", "talangan"**. Satu pengecualian sempit dan diuji: `SystemSetting::get(KasbonService::SETTING_DAILY_LIMIT)` — operator perlu melihat batas harian, kasir perlu tahu sisa batas yang sama; keduanya **bukan** data pemilik. Tidak ada `href` ke Rekonsiliasi/Kelola Modal/Kelola Kasir/Dashboard di halaman kasbon.

### 3.8 Aset & cache

`app.js` berubah ⇒ sesuai AGENTS.md `CACHE_NAME` **wajib** naik:

- `public/sw.js`: `var CACHE_NAME = 'aurumflow-shell-v3';`
- `AGENTS.md`: kalimat versi shell → **`aurumflow-shell-v3`**
- `public/assets/js/app.js`: header daftar fitur + `Alpine.data('kasbonForm', kasbonForm)` (total `Alpine.data(` menjadi **6**)
- `app.min.css` **tidak** berubah (tanpa kelas baru), daftar-putih precache `sw.js` **tidak** berubah (tanpa aset baru)

---

## 4. Assertion QC yang Diperlukan

### 4.1 Baseline & target

| Ukuran | Sebelum | Sesudah |
|---|---|---|
| Suite | 13 (`run_qc.sh`) | **14** (`+ qc_fase_8`) |
| Assertion | 785 | **≥ 850** (≥ 65 baru) |
| Gagal | 0 | **0** |
| `storage/logs/php-*.log` | tidak ada | **tidak ada** |

### 4.2 Fixture baru (`tests/bootstrap.php`)

| Fungsi | Tugas |
|---|---|
| `QcFixture::resetKasbonState(): void` | Hapus `miner_loan_ledger` → `miner_loans` → `miners` (urutan FK), idempoten. **Wajib dipanggil `resetTellerState()` dan `clearSettlementTraces()` sebelum `DELETE FROM cash_mutations`** (FK RESTRICT `mutation_id`) |
| `QcFixture::seedQcMiner(array $override = []): array` | Petani QC bernama berawalan `QC ` (+ opsional telepon `0895…`), idempoten |
| `QcFixture::seedQcKasbon(string $minerId, string $principal = '250000.00'): array` | Pencairan lewat **jalur nyata** `KasbonService::disburse()` (bukan INSERT langsung) |
| `QcFixture::kasbonSnapshot(): array` | Potret: jumlah pinjaman/ledger, Σ saldo, saldo pos kas, `usageToday()` — dipakai assertion "nol tulisan" |
| `QcFixture::purgeQcMiners(): int` | Hapus petani `QC %` beserta riwayatnya (urutan FK) — dipanggil `resetKasbonState()` |

### 4.3 Grup assertion `tests/qc_fase_8.php`

| Grup | ID | Assertion (ringkas) |
|---|---|---|
| **B. Fixture** | B.1 | `resetKasbonState()` idempoten (dua kali → snapshot sama, 0 baris) |
| | B.2 | `resetTellerState()` memanggil `resetKasbonState()` **sebelum** `DELETE FROM cash_mutations` (posisi `strpos` pada `bootstrap.php`) |
| | B.3 | `seedQcMiner()` tidak membuat baris `users`/`capital_wallets` |
| | B.4 | FK RESTRICT terbukti: menghapus mutasi kasbon saat ledger hidup → exception (`Qc::throws`) |
| **C. Pencairan** | C.1 | `GET /operator/kasbon` 200 (operator): `<datalist>`, 4 `<form>`, `_once`, tab Buku Kasbon |
| | C.2 | `POST /operator/kasbon` → 302 PRG + flash sukses |
| | C.3 | `miner_loans` +1: `open`, `principal = balance_remaining`, `issued_on = hari ini` |
| | C.4 | `miner_loan_ledger` +1 `disbursement`, `balance_after = pokok`, `payment_method NULL`, `mutation_id` terisi |
| | C.5 | `cash_mutations` +1 `out`, nominal = pokok, label `Kasbon: <nama>` |
| | C.6 | Saldo `Kas Meja Bakar` turun **tepat** pokok |
| | C.7 | `_once` dikonsumsi: kiriman kedua → 302 + flash "ganda" + **nol** baris baru |
| | C.8 | `POST /operator/kasbon/petani` → 302 `?miner=<uuid>`, baris `miners` +1, halaman menampilkan petani terpilih |
| **D. Dua lapis validasi** | D.1 | Melebihi **sisa batas harian** → 422 + snapshot identik |
| | D.2 | `daily_loan_limit = 0` → 422 "kasbon harian dinonaktifkan" |
| | D.3 | Melebihi **saldo kas fisik** → 422 + snapshot identik |
| | D.4 | Nominal kosong / `0` / huruf / titik ribuan salah / desimal 3 angka → 422 (lima kasus, satu assertion) |
| | D.5 | Nominal melebihi kapasitas `DECIMAL(14,2)` → 422 |
| | D.6 | Pencairan **tepat** sisa batas → 302 diterima (batas inklusif) |
| | D.7 | Pencairan **tepat** saldo kas → 302; saldo menjadi `0.00`; pencairan berikutnya 422 |
| **E. Pembayaran** | E.1 | Tunai → 302; ledger `repayment`+`cash`; satu mutasi `in`; saldo naik; `balance_remaining` turun |
| | E.2 | Potong nota → 302; `payment_method = 'nota'`; angka kas identik dengan tunai |
| | E.3 | Nominal > sisa utang → 422 + snapshot identik |
| | E.4 | Nominal `0`/kosong → 422 |
| | E.5 | "Lunas" (nominal = sisa) → `status = settled`, `settled_at` terisi, `balance_remaining = 0.00` |
| | E.6 | Angsuran sebagian → status tetap `open`, `balance_after` benar |
| | E.7 | FIFO dua kasbon: pembayaran melampaui kasbon pertama → 2 baris ledger, **1** mutasi `in`, kasbon tertua lunas lebih dulu |
| | E.8 | Setelah lunas, pencairan baru → baris `open` baru (petani dapat berutang lagi) |
| | E.9 | Label mutasi ≤ 100 karakter pada nama maksimum (60) — tunai & angsuran |
| **F. RBAC** | F.1 | Matriks GET: operator 200, kasir 200, mitra 302 `/investor/dashboard`, anonim 302 `/login` |
| | F.2 | Kasir POST ketiga endpoint → 302 dengan tulisan benar; operator idem |
| | F.3 | Mitra POST ketiga endpoint → 302, **nol tulisan**, `_once` **tidak** dikonsumsi |
| | F.4 | `KasbonController`: literal `ALLOWED_ROLES = [ROLE_OPERATOR, ROLE_CASHIER]` + `requireRole(...self::ALLOWED_ROLES)` di **4** method, tanpa `requireRole(Constants::ROLE_OPERATOR)` |
| | F.5 | **Guard-first**: `requireRole` mendahului `guardOnce`/akses model/service di tiap method (periksa isi method) |
| | F.6 | Pemindaian seluruh `app/Controllers`: hanya `TellerController` **dan** `KasbonController` menyebut `ROLE_CASHIER` |
| | F.7 | Regresi Fase 7: kasir GET/POST rute terlarang tetap 302 `/operator/teller`, nol tulisan |
| | F.8 | Regresi logout kasir → `/cashier/login` |
| | F.9 | Route table: 4 entry baru menunjuk handler yang ada + turunan `BaseController` |
| | F.10 | Nav sadar peran: kasir melihat 2 tab (`Kasir`, `Buku Kasbon`), operator 6 tab |
| **G. Isolasi data pemilik** | G.1 | Body kasbon (operator & kasir) tidak memuat `Brankas` maupun kanari saldo brankas |
| | G.2 | Tidak memuat `laba bersih`/`pool mitra`/`omzet`/`investor_pool`/`net_settled_profit` |
| | G.3 | Tidak memuat `href` ke `/operator/settlement`, `/operator/capital`, `/operator/kasir`, `/dashboard` |
| | G.4 | Tidak memuat label `Rekonsiliasi`/`Kelola Modal`/`Kelola Kasir` |
| | G.5 | `SystemSetting::` pada berkas kasbon **hanya** baris `SETTING_DAILY_LIMIT` (pemindai baris) |
| **H. Kontrak statis & keamanan** | H.1 | Tidak ada SQL di controller/view kasbon (pemindai `SELECT|INSERT INTO|UPDATE|DELETE FROM|ORDER BY|LIMIT`) |
| | H.2 | Tidak ada interpolasi `$_GET/$_POST/$_REQUEST` ke string SQL (pemindai `qc_scan_lines`) |
| | H.3 | Tidak ada `number_format(`, `FLOAT`, `DOUBLE` di jalur kasbon |
| | H.4 | `Database::transaction(` tepat **2** di `KasbonService`; tidak ada `beginTransaction(` |
| | H.5 | Urutan kunci: `lockTellerForUpdate()` **sebelum** `getForUpdate` (disburse) dan **sebelum** `lockOpenByMinerForUpdate` (repay) |
| | H.6 | FIFO: `ORDER BY issued_on ASC, created_at ASC, id ASC` ada di `MinerLoan::lockOpenByMinerForUpdate()` |
| | H.7 | Semua `<?=` di view kasbon lewat `Security::e()`/cast; nol CDN; nol `$refs` |
| | H.8 | Tidak ada `:disabled` pada `input`/`textarea`/`select` (hanya `<button>`); `:readonly` + `opacity-70` ada |
| | H.9 | `x-model.fill` dipakai; `init()` membaca DOM (`querySelector`/`formFieldValue`), bukan `$refs` |
| | H.10 | `npx`/`tailwindcss` tidak dipanggil; `resources/css/tailwind.input.css` & `tailwind.config.js` tidak berubah |
| **I. UI & aset** | I.1 | Layout: `'label' => 'Buku Kasbon'` + `App::url('operator/kasbon')` + `overflow-x-auto`/`shrink-0` utuh |
| | I.2 | View: `<datalist` + `data-miner-id` + `data-outstanding` hadir; format label memuat `\| Sisa Utang:` |
| | I.3 | View: tiga tombol cepat (`pickPercent(20)`, `pickPercent(30)`, `payFull()`) dengan `@click` **ekspresi** (bukan `try`/`if`) |
| | I.4 | `app.js`: `Alpine.data('kasbonForm', kasbonForm)` + `substr_count('Alpine.data(') === 6` |
| | I.5 | `sw.js`: `aurumflow-shell-v3`; `AGENTS.md` menyebut `v3`; daftar-putih precache tetap 5 aset |
| | I.6 | `app.min.css` byte-identik (ukuran + sha256 baseline yang dicatat T1) |
| | I.7 | Subset kelas: setiap kelas di view kasbon ada di `app.min.css` |
| | I.8 | Halaman kasbon dirender 200 di HTTP harness (tidak ada `parse error`/fatal), nol `storage/logs/php-*.log` baru |
| **J. Dokumen & konsistensi** | J.1 | ERD memuat `miners`, `miner_loans`, `miner_loan_ledger`, dan `daily_loan_limit` |
| | J.2 | `AGENTS.md`: "dua permukaan" izin kasir + peta 13 tabel + urutan kunci `miner_loans` |
| | J.3 | `README.md`: `/operator/kasbon` + `php database/migrate.php` (M2) |
| | J.4 | `plan/Plan_Kasbon_Petani_AurumFlow.md` ada; `# ADDENDUM` terisi saat eksekusi |

### 4.4 Perubahan suite lama (disengaja — wajib tercatat di ADDENDUM)

| Berkas | Assertion | Perubahan | Alasan |
|---|---|---|---|
| `tests/qc_seed.php` | docblock + `$erdTables` (4.5) | 10 → **13 tabel** (`miners`, `miner_loans`, `miner_loan_ledger`) | Skema bertambah |
| `tests/qc_seed.php` | 4.7b `$moneyExpectations` | +4 kolom `DECIMAL(14,2)`: `miner_loans.principal_amount`, `miner_loans.balance_remaining`, `miner_loan_ledger.amount`, `miner_loan_ledger.balance_after` | Konvensi tipe uang |
| `tests/qc_seed.php` | 4.10 `$expectedSettings` | +`daily_loan_limit => '500000.00'` (jumlah setting ikut berubah) | Kunci seed baru |
| `tests/qc_seed.php` | **4.16 (baru)** | Semantik FK: `miner_id`/`loan_id`/`mutation_id` `ON DELETE RESTRICT`, `issued_by`/`recorded_by` `SET NULL`, `uq_miner_phone` ada | Mencegah regresi skema |
| `tests/qc_fase_6.php` | G.17, G.18 | `aurumflow-shell-v2` → **`v3`** | `app.js` berubah (D-4) |
| `tests/qc_fase_7.php` | F.6 | `substr_count($appJs, 'Alpine.data(') === 5` → **`6`** | Komponen `kasbonForm` |
| `tests/qc_fase_7.php` | F.7 | `v2` → **`v3`** pada `sw.js` & `AGENTS.md` | Idem |
| `tests/qc_fase_7.php` | F.3 | Daftar controller "tidak boleh memberi izin kasir" tetap; **tambah F.3b**: hanya dua controller menyebut `ROLE_CASHIER` | Kontrak dua permukaan |
| `tests/qc_fase_7.php` | E.8 | `$cashierFiles` += `KasbonController.php`, `KasbonService.php`, `Views/operator/kasbon.php`, ketiga model kasbon; daftar terlarang **tetap** (tanpa `SystemSetting`) | Permukaan kasir baru ikut dipindai |
| `tests/qc_fase_7.php` | D.4 | Daftar rute terlarang **tidak berubah** (kasbon bukan rute terlarang bagi kasir) — diberi komentar penunjuk ke F.2 baru | Menghindari salah tafsir |
| `tests/run_qc.sh` | `SUITES` + header | +`qc_fase_8` setelah `qc_fase_7`; header "Fase 1 s.d. Fase 8" | Suite baru |

### 4.5 Probe Node baru (`tests/manual/js_kasbon_check.js`)

Menjalankan `public/assets/js/app.js` **yang sebenarnya** di atas stub DOM (pola `js_bridge_fee_check.js`, seam `registry.kasbonForm`), **tidak** masuk `run_qc.sh`:

1. `pickPercent(20)`/`pickPercent(30)` dari `outstanding = 250.000` → `50.000`/`75.000`; pembulatan half away from zero pada sisa bersen (mis. `123.457` → 20 % = `24.691`);
2. `payFull()` mengisi **tepat** sisa utang (tidak pernah melebihi — `afterLabel` tidak negatif);
3. `rupiahText()` menghasilkan bentuk yang diterima `parseMoneyInput()` (`1.234.567`, `1.234.567,50`) — termasuk **tidak** pernah menghasilkan titik desimal gaya Inggris;
4. `init()` membaca isian yang dirender peladen (jalur `422`): `miner_label`, `amount`, radio metode `nota` **tidak** terhapus;
5. `pickMiner()` memetakan label → `miner_id` dari `data-miner-id`; label tak dikenal → `minerId = ''` (peladen yang memutuskan).

### 4.6 Matriks regresi (yang mungkin pecah dan sudah diantisipasi)

| Assertion | Dampak | Mitigasi |
|---|---|---|
| `qc_fase_2 H.4` (semua `<?= $` di `Views/`) | otomatis memindai view baru | view wajib `Security::e()` / cast `(int)` |
| `qc_fase_4 H.11/H.11c` (nav) | memakai `str_contains`, bukan jumlah tab | aman tanpa perubahan |
| `qc_fase_3/4/6` snapshot kas | kasbon mengubah saldo `Kas Meja Bakar` | suite `qc_fase_8` **berjalan setelah** `qc_fase_7` dan **membersihkan diri** (`resetKasbonState()` + saldo dipulihkan) |
| `qc_fase_6 G.17/G.18`, `qc_fase_7 F.6/F.7` | versi shell & jumlah komponen | diperbarui (§ 4.4) |
| `qc_seed 4.10b` (jumlah setting) | 6 → 7 kunci | peta `$expectedSettings` diperbarui |
| `qc_routing` / `qc_sql_injection` | pemindaian global `app/`+`public/` | berkas baru otomatis tercakup — tidak ada SQL/interpolasi di luar model |

---

## 5. Urutan Eksekusi

| # | Tugas | Berkas utama | Gerbang (*gate*) |
|---|---|---|---|
| **T1** | **Dokumentasi lebih dulu** + rekam baseline: `docs/` (5 dokumen), `AGENTS.md`, `README.md`; catat `filesize` + `sha256` `app.min.css` dan `app.js` sebagai baseline `I.6` | `docs/*`, `AGENTS.md`, `README.md` | Tidak ada kode; dokumen menyebut 13 tabel, `daily_loan_limit`, tab ke-6, dua permukaan kasir, `v3` |
| **T2** | **Skema & migrasi**: 3 tabel di `schema.sql`, seed `daily_loan_limit`, `SystemSetting::ensure()`, `Miner::ensureSchema()`, migrasi **M2** di `migrate.php` | `database/*`, `app/Models/SystemSetting.php`, `app/Models/Miner.php` (kerangka) | `php -l`; `php database/migrate.php` **dua kali** → exit 0, keduanya "sudah diterapkan"; `storage/installed.lock` tidak tersentuh; `php tests/qc_seed.php` lulus setelah 4.5/4.7b/4.10/4.16 diperbarui |
| **T3** | **Model** `Miner`, `MinerLoan`, `MinerLoanLedger` (SQL ber-`named parameter`, FIFO, `FOR UPDATE`) | `app/Models/*` | `php -l`; tidak ada SQL di luar `app/Models/` |
| **T4** | **`KasbonService`**: `dailyLimit`, `usageToday`, `registerMiner`, `resolveMiner`, `disburse`, `repay`, `minerOptions`, `viewModel` | `app/Services/KasbonService.php` | `php -l`; delegasi parser ke `SettlementService::parseMoneyInput()`; dua `Database::transaction()` |
| **T5** | **Controller + route**: guard-first, `guardOnce`, PRG, `422`, `?miner=` | `app/Controllers/KasbonController.php`, `app/routes.php` | Server dev (`php -S … tests/server_router.php`): GET 200 operator/kasir, 302 mitra/anonim; nol tulisan pada permintaan terlarang |
| **T6** | **Fixture QC** + integrasi `resetTellerState()`/`clearSettlementTraces()`/`ensureInstalled()` | `tests/bootstrap.php` | `php tests/qc_fase_2.php` **dan** `qc_fase_3.php` tetap lulus (urutan FK benar) |
| **T7** | **Suite `qc_fase_8`** grup **B–H** + daftarkan di `run_qc.sh` | `tests/qc_fase_8.php`, `tests/run_qc.sh` | `php tests/qc_fase_8.php` exit 0 (grup B–H) |
| **T8** | **UI**: tab ke-6, view kasbon (7 kartu), komponen `kasbonForm`, `sw.js` v3, `AGENTS.md` v3 | `Views/layouts/operator.php`, `Views/operator/kasbon.php`, `public/assets/js/app.js`, `public/sw.js` | Uji manual di peramban sempit (360 px): autocomplete muncul, tombol cepat mengisi nominal, tab menggeser horizontal, **nol** kelas tanpa gaya |
| **T9** | **Probe Node** `js_kasbon_check.js` | `tests/manual/js_kasbon_check.js` | `node tests/manual/js_kasbon_check.js` → semua cek lulus (5 butir § 4.5) |
| **T10** | **Assertion UI & dokumen** (grup I–J) + perubahan suite lama (§ 4.4) | `qc_fase_8`, `qc_seed`, `qc_fase_6`, `qc_fase_7` | `php tests/qc_fase_8.php` exit 0 penuh; `qc_seed`/`qc_fase_6`/`qc_fase_7` exit 0 |
| **T11** | **Gerbang penuh** | — | `bash tests/run_qc.sh` exit **0** (14/14 suite, ≥ 850 assertion, 0 gagal); `storage/logs/php-*.log` tidak ada; `app.min.css` masih byte-identik |
| **T12** | **Laporan & pembersihan** | `plan/Summary_Fase_8_Kasbon_AurumFlow.md`, `# ADDENDUM` plan ini | `git status` bersih (tanpa `tests/.tmp/`, `storage/installed.lock`, `*.log`, `storage/throttle/*`); ringkasan menyebut angka QC final + item terbuka |

**Perintah verifikasi (ringkas):**

```bash
php -l app/Controllers/KasbonController.php   # dan seluruh berkas baru
php tests/qc_fase_8.php                       # suite modul saja
bash tests/run_qc.sh                          # gerbang kelulusan (exit 0)
node tests/manual/js_kasbon_check.js          # probe lapis Alpine (butuh Node)
php database/migrate.php                      # M2 idempoten (jalankan dua kali)
```

---

## Risiko

| ID | Risiko | Mitigasi |
|---|---|---|
| R-1 | **Batas harian ditembus dua terminal** yang mencairkan serentak | Pemeriksaan dilakukan **di dalam transaksi** yang mengunci baris `system_settings.daily_loan_limit` → serialisasi global; diuji `D.1`/`D.6` |
| R-2 | **Angka cermin berbeda** antara tombol cepat dan peladen (pembulatan) | Aritmetika sen integer di kedua sisi + probe Node (`js_kasbon_check.js`); peladen tidak pernah memakai angka kiriman klien |
| R-3 | **Cache shell basi** membuat `kasbonForm` tidak ada → autocomplete diam | `CACHE_NAME` → **v3** + assertion `I.5` (sw + AGENTS satu versi), pola temuan Fase 6 |
| R-4 | **Kelas CSS baru tanpa gaya** (Tailwind JIT) | Larangan kompilasi + assertion subset kelas `I.7` + `I.6` (sha256 CSS tetap) |
| R-5 | **Fixture QC gagal karena FK RESTRICT** baru (`mutation_id`) | `resetKasbonState()` dipanggil lebih dulu di `resetTellerState()`/`clearSettlementTraces()`; diuji `B.2`/`B.4` |
| R-6 | **Potong nota ganda** (hulu neto dipotong *dan* angsuran dicatat) | D-6: hulu selalu dibayar penuh; hanya satu jalur pemotongan (angsuran) — diuji `E.2` (efek kas sekali) |
| R-7 | **Kasir melihat data pemilik** lewat halaman baru | Daftar terlarang § 3.7 + pemindai `G.1`–`G.5`; tidak ada `SystemSetting` selain `daily_loan_limit` |
| R-8 | **Nama panjang memotong label mutasi** (kolom 100 karakter) | `MAX_NAME_CHARS = 60` (label terpanjang 77) + assertion `E.9` |
| R-9 | **Duplikasi identitas petani** | `uq_miner_phone` (NULL berulang) + pesan yang menyebut pemilik nomor; nama tanpa nomor tetap boleh |
| R-10 | **`$_GET['miner']` disalahgunakan** | `Security::isUuid()` + `findById()`; tidak ditemukan → diabaikan (tanpa halaman error, tanpa SQL dinamis) |

## Item Terbuka

| ID | Item | Rencana |
|---|---|---|
| O-1 | Nonaktifkan/arsipkan petani (`miners.is_active`) + aturan "tidak boleh ada utang berjalan" | Fase lanjutan (butuh kolom + endpoint status) |
| O-2 | Koreksi/*void* kasbon & angsuran (jejak reversal) | Fase lanjutan; butuh `reversal_of` pada ledger |
| O-3 | Potong nota otomatis di form timbang (`hulu_transactions.miner_id` + `loan_deduction`) | Fase lanjutan; menuntut ALTER + perubahan `TellerService::payCash()` |
| O-4 | Ekspor CSV buku kasbon sisi operator, umur piutang (*aging*) | Menunggu permintaan PO |
| O-5 | Panel admin `system_settings` untuk `daily_loan_limit` | Sama dengan parameter lain: masih lewat `seed.sql`/`migrate.php` |
| O-6 | Bunga/denda keterlambatan | Di luar model bisnis saat ini (pinjaman tanpa bunga) |

---

# ADDENDUM

*(Diisi saat eksekusi: setiap penyimpangan dari rencana ini — berkas, alasan, dan assertion yang menyesuaikan — dicatat di sini, sesuai presedensi `docs/` → `plan/`.)*

# ADDENDUM

Catatan eksekusi (T1–T12). Setiap butir adalah penyimpangan sadar dari rencana di
atas, beserta alasannya; semuanya sudah tercermin pada kode, dokumen, dan assertion.

**A-1 — Membaca batas harian memakai `TellerService::moneyToCents()`, bukan
`SettlementService::parseMoneyInput()`.** Nilai tersimpan adalah desimal kanonik
(`'500000.00'`), sedangkan `parseMoneyInput()` menganggap titik sebagai pemisah
**ribuan** (aturan input lapangan) sehingga `'500000.00'` ditolak. Pembagiannya
tetap satu pemilik: nilai dari basis data → `moneyToCents()`, nilai dari form →
`parseMoneyInput()`. Rencana § 2.4 hanya menyebut "delegasi ke parseMoneyInput".

**A-2 — Tanpa `mb_*`.** Ekstensi `mbstring` tidak tersedia di host pengembangan,
sehingga `KasbonService` menghitung/memotong karakter UTF-8 dengan
`preg_match_all('/./us')` dan `preg_split` (pola `TellerService::normaliseAlias()`),
bukan `mb_strlen()`/`mb_substr()`.

**A-3 — `requireRole(...)` ditulis eksplisit di setiap method publik.**
`disburse()`, `repay()`, dan `storeMiner()` memanggil `requireRole()` **lalu**
`guardOnce()` (yang juga memanggil `guard()` → `requireRole()`), sehingga kontrak
"pernyataan PERTAMA setiap method publik" benar-benar terbaca di kode. Akibatnya
literal `requireRole(...self::ALLOWED_ROLES);` muncul **enam** kali (4 method
publik + `guard()` + `renderKasbon()`); assertion `qc_fase_8` F.5 memakai `>= 4`
ditambah pemeriksaan urutan per method pada F.6.

**A-4 — "Dua permukaan izin" diuji lewat `ALLOWED_ROLES`, bukan penyebutan
`ROLE_CASHIER`.** Rencana § 4.3 (F.6) menulis "hanya dua controller menyebut
`ROLE_CASHIER`". Kenyataannya `AuthController` (menolak kasir di pintu kata sandi),
`BaseController` (`roleLandingPath`), `CashierAuthController` (pintu kasir), dan
`InvestorOnboardingController` juga menyebut konstanta itu untuk **menolak**.
Assertion `qc_fase_8` F.7 dan `qc_fase_7` F.3b karena itu memeriksa pemilik
`ALLOWED_ROLES` (tepat dua berkas) plus tidak adanya `requireRole(...ROLE_CASHIER)`
liar — semantiknya lebih tepat dan lebih ketat.

**A-5 — Uji FIFO memakai tanggal bisnis, bukan `sleep()`.** `created_at`
berpresisi detik (item O-1), sehingga dua pencairan pada detik yang sama dapat
berurutan acak dan "kasbon tertua" menjadi tidak deterministik. `qc_fase_8` E.7
memundurkan `issued_on` kasbon pertama ke kemarin (satu `UPDATE` setelah pencairan
lewat jalur nyata), yang sekaligus menguji pemotongan lintas hari.

**A-6 — Pepper & jar pada suite.** `qc_fase_8` menyuntikkan `APP_PIN_PEPPER` lewat
`putenv()` sebelum harness dimuat (pola `qc_fase_5`) agar fixture PIN kasir/mitra
selalu konsisten. Uji logout memakai cookie jar **terpisah**: helper
`qc8_loginCashier()` memakai nama jar per nomor telepon, sehingga memakainya untuk
uji logout akan mengeluarkan sesi kasir utama.

**A-7 — `qc_fase_5` K.1 ikut disesuaikan (tidak tercantum di § 4.4).** Assertion
itu membandingkan daftar tabel persis; tiga tabel baru membuatnya gagal. Daftar
diperluas menjadi 13 tabel dengan komentar penunjuk Fase 8. Total suite lama yang
disentuh: `qc_seed`, `qc_fase_5`, `qc_fase_6`, `qc_fase_7`, `run_qc.sh`.

**A-8 — Angka akhir QC.** **14 suite, 853 assertion, 0 gagal** (target rencana
≥ 850). Rincian: `qc_fase_8` 66 assertion baru; `qc_seed` 47 → 48 (assertion
`4.7f` semantik FK kasbon); `qc_fase_7` 77 → 78 (`F.3b` dua permukaan izin).
ID `4.16` pada `qc_seed` sudah dipakai guard installer, sehingga assertion baru
diberi ID `4.7f`.

**A-9 — Kelas radio memakai `accent-[#D4AF37]`.** `accent-gold-primary` tidak ada
di `app.min.css` (tailwind.config.js tidak mendefinisikan utilitas `accent`),
sedangkan `accent-[#D4AF37]` sudah dipakai view lain dan karenanya sudah ada di
berkas terkompilasi. `qc_fase_8` I.7 memeriksa 61 kelas dan semuanya ada.

**A-10 — Nama field per kartu diturunkan dari `mode` komponen.** Satu halaman
memuat dua form (pencairan & angsuran) dengan nama field berbeda (`miner_label`/
`miner_id`/`principal_amount` vs `pay_miner_label`/`pay_miner_id`/`amount`), jadi
`kasbonForm::init()` memilih prefiks dan nama field nominal berdasarkan `mode`
(`'disburse'`/`'repay'`). Probe `tests/manual/js_kasbon_check.js` menguji keduanya.

**A-11 — Tidak ada probe untuk `kasbonAmountText()`/`kasbonParseAmount()` secara
langsung.** Keduanya privat di dalam IIFE `app.js` (pola yang sama dengan
`AurumFormat`), sehingga probe memeriksanya lewat perilaku publik komponen
(`pickPercent`, `payFull`, `afterLabel`) — 24 assertion.
