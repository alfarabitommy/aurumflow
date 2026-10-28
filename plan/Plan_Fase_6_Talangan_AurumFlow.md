# Plan Fase 6 — Modul Dana Talangan (Bridge Liquidity) — AurumFlow

> **Status dokumen (pasca-Fase 9 — sinkronisasi dokumentasi).** Seluruh angka dan perilaku di
> bawah ini — termasuk (bila ada) penyebutan **13 tabel** atau kurang, **14 suite / 853
> assertion**, `CACHE_NAME` **v3**, dan isian **`coord_fee`** — adalah **potret fase tersebut**,
> bukan keadaan sekarang. Keadaan terkini: **14 tabel** (termasuk `operational_expenses`, nol `ALTER`),
> `CACHE_NAME` **`aurumflow-shell-v4`**, **15 suite / 900 assertion / 0 gagal**, beban operasional
> dicatat sebagai **voucher kas** di meja kasir lalu diserap otomatis oleh tutup buku
> (`total_cash = pendapatan`). Rujukan terkini: `plan/Summary_Fase_9_OPEX_AurumFlow.md`.


> **Dokumen Perencanaan Eksekusi**
> Proyek: **AurumFlow** — sistem manajemen operasional privat untuk perdagangan emas aluvial dengan settlement **T+1**.
> Fase: **6 — Modul Dana Talangan (Bridge Liquidity)** — permintaan Product Owner sebelum *final deployment*.
> Status: `EXECUTED` · T1–T11 selesai. `bash tests/run_qc.sh` exit 0 (12/12 suite, 698 assertion).
> Laporan eksekusi: `plan/Summary_Fase_6_AurumFlow.md`; penyimpangan: `# ADDENDUM` di bawah.

| Item | Nilai |
|---|---|
| Runtime | Native PHP **8.2+** (`declare(strict_types=1)`), tanpa framework, tanpa Composer/`vendor/` |
| Frontend | Tailwind CSS **3.4.19** + Alpine.js **3.17.4** (aset lokal, tanpa CDN) — modal memakai state literal di view, bukan komponen baru |
| Database | MySQL 8.0 / MariaDB — **9 tabel ERD + 1 tabel baru (`bridge_loans`)**, **nol ALTER** pada tabel yang sudah ada |
| Scope baru | Route `POST /operator/teller/talangan`; `Models/BridgeLoan.php`, `Services/BridgeLoanService.php`; modal "Terima Dana Talangan" (Layar B2); kartu "Talangan Aktif" (Layar C); kelompok tulisan ke-7 pada tutup buku |
| Sumber kebenaran | ERD → SAD → Roadmap (FASE 6, Protokol QC Manual 6A) → PRD (§ 3.4, § 4.2, § 6 Modul 2) → UI/UX (Layar B & C) |
| Keputusan pengguna | `dec-c71090762d066f0f` (lihat § 2.1) |
| Bahasa dokumen | Prosa Indonesia + istilah teknis Inggris (konvensi `Plan_Fase_1..5`) |

---

## 1. Ringkasan Fase 6

### 1.1 Konteks bisnis

Meja Bakar (kasir hulu) bekerja **malam**, sementara kas masuk terjadi **siang** berikutnya saat toko emas mencairkan hasil penjualan (siklus T+1). Bila kedatangan penambang melebihi saldo kas meja pada malam hari, operasi berhenti — padahal emas *holding* adalah aset yang pasti terjual keesokan harinya.

**Dana talangan** adalah jembatan likuiditas untuk celah itu: pengelola meminjam kas tunai jangka pendek dari pihak luar (tetangga, tengkulak, aparat, keluarga), **mengembalikan pokok + fee** pada tutup buku siang berikutnya (satu siklus T+1), dan **fee dibebankan hanya kepada hak laba pengelola** — pool 20 % mitra tidak boleh tersentuh sepeser pun (PRD § 3.4).

### 1.2 Tujuan (lima hal yang wajib benar)

1. **Penerimaan tanpa akun.** Pemberi dana cukup dicatat sebagai `lender_name` (alias, tanpa akun/login). Penerimaan = **satu** `Database::transaction()`: kunci pos kas → `bridge_loans` (`status='active'`) → `cash_registers` saldo naik → `cash_mutations` `in`.
2. **Fee fleksibel, dihitung peladen.** Operator memilih **Persen (`pct`)** atau **Nominal (`fixed`)**. Untuk `pct`, `fee_amount` **selalu** dihitung ulang peladen dari pokok × tarif (sen integer, pembulatan half away from zero); nominal apa pun dari klien untuk kolom itu diabaikan.
3. **Auto-pay pada tutup buku.** `SettlementService::settle()` mengembalikan **pokok + fee** setiap talangan aktif: dompet kas meja turun persis sebesar pokok + fee, satu mutasi `out` per talangan, baris talangan menjadi `settled` + `settlement_id` terisi.
4. **Isolasi mutlak pool investor.** Fee diserap **hanya** dari hak pengelola: `investor_pool_20`, Σ `profit_earned`, dan setiap `capital_wallets.balance_profit` **identik** dengan/tanpa talangan. Dibuktikan assertion, bukan diasumsikan (grup E).
5. **Nol regresi pembukuan lama.** `operator_share_80 + investor_pool_20 == net_settled_profit` (invariant Fase 3 A.3), carry-over defisit (PRD § 4.2), distribusi pool `AllocationEngine` (Fase 4), dan tutup buku enam-kelompok-tulisan tetap utuh — talangan menjadi **kelompok tulisan ke-7**.

### 1.3 Definisi Selesai (*Definition of Done*)

- [ ] `POST /operator/teller/talangan` (operator) sukses = `302` PRG; saldo Kas Meja Bakar **+pokok** persis; **1** baris `bridge_loans` (`status='active'`, `fee_amount` kanonik); **1** mutasi `in` berlabel `Talangan dari <nama>`; `CSRF`/`_once` diperiksa; gagal → `422` **nol tulisan**; `_once` ganda → PRG tanpa tulisan; investor → `302 /investor/dashboard`.
- [ ] `fee_type='pct'` → `fee_amount` **dihitung peladen** (tarif dikirim klien sebagai *input*, bukan nominal); `fee_type='fixed'` → nominal diparse `SettlementService::parseMoneyInput()` (nilai 0 diizinkan = talangan tanpa fee).
- [ ] Tutup buku dengan ≥ 1 talangan aktif: baris talangan `settled` + `settlement_id` + `fee_absorbed` + `fee_uncovered`; saldo kas meja turun persis `Σ(pokok + fee)`; satu mutasi `out` per talangan; `operator_share_80 + investor_pool_20 == net_settled_profit` masih berlaku.
- [ ] Fee diserap `min(fee, operator_share_80)`; kelebihan dicatat `fee_uncovered` (dibayar tunai dari kas kerja pengelola), **tanpa** memblokir tutup buku dan **tanpa** menyentuh pool investor.
- [ ] Talangan yang diterima **setelah** settlement terakhir tetap `active` dan terbayar pada settlement berikutnya; urutan pembayaran **FIFO** (`created_at ASC, id ASC`).
- [ ] Penjagaan saldo: bila kas meja setelah alokasi tidak cukup menutup pengembalian → `422` **nol tulisan**, talangan tetap `active`.
- [ ] `bash tests/run_qc.sh` exit `0` (**12 suite**, target ≥ **690** assertion, 0 gagal); probe Node `js_form_init_check`, `js_capital_simulator_check`, `js_investor_check`, **`js_bridge_fee_check`** lulus; tanpa `storage/logs/php-*.log`.
- [ ] `docs/` (ERD tabel ke-10 + kardinalitas; PRD § 3.5/§ 6 Modul 5; Roadmap FASE 6A + Protokol QC Manual 6A; SAD § 3/§ 4.1/§ 4.5; UI/UX Layar B2 + Layar C) dan `AGENTS.md`/`README.md` diperbarui **lebih dulu** (T1); **tidak ada `ALTER TABLE`** pada tabel lama.
- [ ] `git status` bersih dari artefak (`tests/.tmp/`, `storage/installed.lock`, `*.log`, `storage/throttle/*`).

### 1.4 Di Luar Ruang Lingkup Fase 6

- **Pembayaran talangan manual / di luar settlement** (mis. pemberi dana menagih pukul 05.00). Fase ini hanya jalur auto-pay tutup buku — item **O-2**.
- **Bunga berbunga / fee per siklus bergulir** (talangan yang belum lunas menambah fee baru setiap siklus). Aturan PO: **flat fee per siklus (T+1)**, satu fee per peristiwa peminjaman — item **O-3**.
- **Kuitansi/tanda terima bertanda tangan** (unggah foto bukti serah terima dana talangan) dan **notifikasi** ke pemberi dana.
- **Pengaturan fee default di `system_settings`** (mis. `default_bridge_fee_pct`): tarif diketik per talangan; menambah kunci baru akan menjadi sumber kebenaran kedua tanpa diminta — item **O-4**.
- Item warisan yang tetap terbuka: mode alokasi `prorata` (O-1 Fase 4), kalender hari libur H-7 (O-2 Fase 4), versi/arsip waiver (O-1 Fase 5), PDF peladen + ekspor operator (O-3 Fase 5), `consumable_opex` & beban aparat sebagai `system_settings`, retensi berkas nota.
- **Checklist deployment** (cPanel, `--minify`, `INSTALL_TOKEN`) tetap milik `Protokol QC Manual 6` dokumen Roadmap — tidak digantikan oleh modul ini.

---

## 2. Keputusan & Asumsi Terkunci

### 2.1 Keputusan pengguna (`dec-c71090762d066f0f`)

| Keputusan | Pilihan | Konsekuensi |
|---|---|---|
| **Pembukuan fee** | **Hanya di tabel baru `bridge_loans`** (`fee_amount`, `fee_absorbed`, `fee_uncovered`, `settlement_id`) | `daily_settlements` **TIDAK disentuh** → invariant `operator_share_80 + investor_pool_20 == net_settled_profit` tetap utuh (qc_fase_3 A.3, qc_fase_4 F.4 tidak bergeser) dan **tidak ada `ALTER TABLE`** pada database lapangan yang sudah berjalan (`CREATE TABLE IF NOT EXISTS` hanya bekerja untuk tabel baru). "Hak pengelola bersih" = `operator_share_80 − Σ fee_absorbed`, disajikan di Layar C + pesan flash. Alternatif "tulis ulang `operator_share_80`" **ditolak** — lihat O-1 |
| **Fee tak tertutup** | **Tetap bayar penuh**; serap fee maksimal sebesar hak pengelola; selisih dibayar tunai dari kas meja & ditandai `fee_uncovered` | Tutup buku **tidak pernah diblokir** (carry-over defisit PRD § 4.2 tetap jalan). Fee diserap ≤ hak laba pengelola; kelebihan = kas kerja pengelola (bukan dana investor) dan selalu tampil + `Logger::warning`. Satu talangan = satu settlement = satu mutasi `out`. Alternatif "tunda fee" (mesin status baru) dan "tolak settlement `422`" **ditolak** — lihat O-1/O-2 |

### 2.2 Asumsi & keputusan arsitektural

- **A-1 — Satu tabel baru, nol ALTER.** Hanya `bridge_loans` yang ditambah; sembilan tabel ERD lain **tidak berubah satu kolom pun** (dijaga assertion H.5). Alasannya: `database/schema.sql` memakai `CREATE TABLE IF NOT EXISTS` sehingga tabel baru terpasang aman di database lapangan, sementara `ALTER` tidak pernah dijalankan ulang oleh `install.php` — kolom baru akan hilang diam-diam di instalasi lama.
- **A-2 — Pokok talangan adalah uang pinjaman, bukan pendapatan.** Karena itu penerimaan pokok **tidak pernah** masuk perhitungan `daily_settlements` (bukan omzet, bukan laba) dan tidak pernah masuk `profit_distributions`. Yang masuk pembukuan hanya **fee**.
- **A-3 — Fee diserap setelah bagi hasil, bukan sebelum.** Urutan rumus tetap: `net → operator 80 % → pool 20 %`, **baru** `hak_pengelola_bersih = operator_share_80 − fee_absorbed`. Kalau fee dikurangkan sebelum bagi hasil, pool 20 % ikut menanggungnya — persis yang dilarang PO (aturan 4). Karena itu `quote()` menghasilkan `investor_pool_*` **sebelum** menyentuh fee, dan fee tidak pernah muncul pada jalur `AllocationEngine`.
- **A-4 — Penyerapan FIFO greedy, satu pemilik rumus.** `BridgeLoanService::allocateAbsorption(int $operatorShareCents, array $loans)` (murni, integer): untuk setiap talangan berurutan `created_at ASC, id ASC`, `absorbed_i = min(fee_i, sisa)` dengan `sisa` mulai dari hak pengelola → dengan begitu `Σ absorbed = min(Σ fee, hak pengelola)` **persis**, dan `uncovered_i = fee_i − absorbed_i`. `SettlementService::quote()` memanggil fungsi ini (lewat konteks) sehingga pratinjau, penyimpanan, dan QC memakai angka yang sama — tidak ada salinan rumus.
- **A-5 — Pengembalian dibayar dari Kas Meja Bakar, satu mutasi per talangan.** Kas talangan **masuk** ke `cash_registers` pos Kas Meja Bakar (`CashRegister::TELLER_NAME`), maka pengembalian keluar dari pos yang sama; totalnya `pokok + fee`. Brankas Utama **tidak pernah** dipakai membayar talangan (brankas memuat laba bersih tempat pool investor berhak — mengeluarkan kas dari sana mengaburkan pemisahan hak). Satu talangan = satu mutasi `out` agar jejak audit per pemberi dana dapat ditelusuri (`bridge_loan_id` pada `notes`).
- **A-6 — Pengembalian TIDAK mengubah invariant alokasi kas.** Sembilan invariant Fase 3 tetap: `kas_split + brankas_split == total_cash`. Pengembalian talangan adalah **arus kas terpisah** (dana masuk malam, keluar siang) dengan mutasinya sendiri, sehingga total kas yang diterima hari itu tetap teralokasi penuh. Rumus `Smart Auto-Split` **tidak berubah**.
- **A-7 — Kelompok tulisan ke-7 + urutan penguncian diperluas.** Urutan penguncian global menjadi: `capital_wallets` (`id` menaik) → `cash_registers` (nama menaik) → **`bridge_loans` (FIFO, `status='active'`)** → `hulu_transactions` → `system_settings`. Ini **perpanjangan lurus** dari urutan lama (setiap pasangan yang sudah ada tidak bertukar posisi) dan konsisten dengan jalur penerimaan malam (`cash_registers` → `bridge_loans`, tanpa `hulu`). Di `settle()` penguncian talangan diletakkan **setelah** kedua pos kas dan **sebelum** `HuluTransaction::holdingRowsForUpdate()`.
- **A-8 — Tutup buku tetap satu transaksi.** `bridge_loans` + delta `cash_registers` + mutasi `out` ditulis **di dalam** `Database::transaction()` milik `settle()` (pola `AllocationEngine::apply($pdo, …)`). Tidak ada `beginTransaction()` manual, dan berkas nota yang sudah tersalin tetap di-`unlink()` bila transaksi digulung.
- **A-9 — Penjagaan saldo kas sebelum tulisan apa pun.** Setelah alokasi kas dihitung dan sebelum baris pertama ditulis: `saldo_meja_setelah_alokasi >= Σ(pokok + fee)`, jika tidak → `RuntimeException` (`422`, nol tulisan, talangan tetap `active`). Alasannya: alokasi kas adalah isian operator (`kas_split`/`brankas_split`), sehingga pengembalian talangan dapat melampaui kas yang benar-benar diterima meja.
- **A-10 — Kontrak form berlaku sama.** Modal dirender **terbuka** (Alpine mati pun terlihat) dengan nilai awal dari peladen (`value="…"`), dibaca Alpine `init()` lewat `formFieldValue()` (**bukan** `$refs`), contoh numerik berawalan `mis. `, kontrol form **tidak pernah** `:disabled` (`:readonly="submitting"` + kelas redup), modal `x-cloak`, dan setiap mutasi diikuti **PRG**.
- **A-11 — Interaksi sekali pakai hidup di view.** Modal talangan memakai state literal `x-data="{ open: …, feeType: 'fixed', … }"` + rantai ekspresi langsung di `@click` — **bukan** `Alpine.data('bridgeForm', …)`. Alasannya pengalaman Fase 5: komponen baru di `app.js` dapat belum ada di aset yang di-cache peramban → `ReferenceError` → modal tidak pernah muncul. Hanya cermin pratinjau (aritmetika) yang ditambahkan ke `app.js` dan diuji probe Node.
- **A-12 — Nominal fee dari klien tidak dipercaya.** Untuk `fee_type='pct'` klien hanya mengirim **tarif**; `fee_amount` dihitung peladen. Untuk `fee_type='fixed'` nominal adalah *input* (seperti `buy_price_per_g`) dan diparse `SettlementService::parseMoneyInput()`. Form **tidak pernah** mengirim `settlement_id`, `bridge_loan_id`, saldo, atau `fee_absorbed`.
- **A-13 — Label pemberi dana aman terhadap batas kolom.** `lender_name` `VARCHAR(50)` (pola `farmer_alias`), disanitasi `Security::sanitize()`, wajib diisi. Dengan begitu label mutasi `Talangan dari ` (14) + 50 = 64 dan `Kembali talangan: ` (18) + 50 = 68 — keduanya **tidak pernah** terpotong oleh `cash_mutations.source_destination` `VARCHAR(100)`.
- **A-14 — Fee boleh nol, tarif tidak.** `fixed` kosong = `0.00` (talangan tanpa fee — sah). `pct` wajib `> 0` dan `≤ 100,00`, satu pemisah = desimal, maksimum 2 desimal, sufiks `%` diabaikan (konvensi `SettlementService::parseDensity()`); parser sendiri (`BridgeLoanService::parseFeeRateInput()`) dengan **tabel kasus bersama** di grup B supaya dua parser tidak dapat menyimpang diam-diam (pola `parseMoneyInput()` vs `parsePriceInput()`).
- **A-15 — `received_at` dari peladen.** `date('Y-m-d H:i:s')` (WIB, pola `onboarding_signed_at`), tidak pernah dari form.
- **A-16 — Investasi privasi diperluas.** `bridge_loans`, `BridgeLoan`, dan istilah tampilan "talangan" masuk **daftar terlarang** berkas investor (`qc_fase_5` grup I diperluas + grup G `qc_fase_6`), karena likuiditas/kredit pengelola adalah informasi internal operator (blokir metrik sensitif PRD § 1.2).
- **A-17 — Dokumentasi lebih dulu (aturan `AGENTS.md`).** Setiap berkas `docs/` yang tersentuh diperbarui pada **T1** sebelum kode, lalu setiap penyimpangan dicatat pada `# ADDENDUM` dokumen ini.
- **A-18 — Suite lama diperbarui secara sadar.** Menambah tabel ke-10 menggeser assertion yang menghitung tabel: `qc_fase_5` K.1 (daftar tabel eksak) dan `qc_seed` `$erdTables` (jumlah tabel/PK/kolom). Keduanya diperbarui dalam satu commit dengan alasan eksplisit (§ 9.4) — bukan dibiarkan gagal dan bukan dilemahkan menjadi "≥".

---

## 3. Skema Basis Data

### 3.1 Tabel baru — `bridge_loans` (DDL final)

```sql
-- ----------------------------------------------------------------------------
-- 10. bridge_loans — dana talangan operasional (Fase 6)
-- ----------------------------------------------------------------------------
-- Pinjaman kas tunai jangka pendek dari pihak LUAR (tanpa akun) untuk menutup
-- kas Meja Bakar yang habis pada malam hari. Pokok dikembalikan + fee flat satu
-- siklus (T+1) pada tutup buku siang berikutnya.
--
-- Prinsip yang tidak boleh dilanggar:
--   * Pokok BUKAN pendapatan: ia tidak pernah menyentuh daily_settlements.
--   * Fee HANYA diserap operator_share_80; investor_pool_20 tidak tersentuh.
--   * Uang selalu DECIMAL(14,2), persen DECIMAL(5,2), PK CHAR(36) UUID v4.
CREATE TABLE IF NOT EXISTS `bridge_loans` (
    `id`               CHAR(36)      NOT NULL,
    `lender_name`      VARCHAR(50)   NOT NULL COMMENT 'Nama/alias pemberi dana (tanpa akun)',
    `principal_amount` DECIMAL(14,2) NOT NULL COMMENT 'Pokok yang diterima meja kas',
    `fee_type`         ENUM('pct','fixed') NOT NULL COMMENT 'Bentuk fee yang disepakati',
    `fee_rate_pct`     DECIMAL(5,2)  NULL COMMENT 'Terisi HANYA bila fee_type = pct',
    `fee_amount`       DECIMAL(14,2) NOT NULL COMMENT 'Fee hasil hitung peladen (kanonik)',
    `fee_absorbed`     DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Porsi fee yang diserap hak pengelola',
    `fee_uncovered`    DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Porsi fee melebihi hak pengelola (kas kerja pengelola)',
    `status`           ENUM('active','settled') NOT NULL DEFAULT 'active',
    `settlement_id`    CHAR(36)      NULL COMMENT 'Sesi tutup buku yang mengembalikannya',
    `register_id`      CHAR(36)      NOT NULL COMMENT 'Pos kas penerima dana',
    `received_at`      DATETIME      NOT NULL COMMENT 'Waktu penerimaan (jam peladen)',
    `notes`            TEXT          NULL,
    `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bridge_status_created` (`status`, `created_at`),
    KEY `idx_bridge_settlement` (`settlement_id`),
    KEY `idx_bridge_register` (`register_id`),
    CONSTRAINT `fk_bridge_settlement` FOREIGN KEY (`settlement_id`) REFERENCES `daily_settlements` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_bridge_register` FOREIGN KEY (`register_id`) REFERENCES `cash_registers` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Alasan tiap keputusan desain**

| Pilihan | Alasan |
|---|---|
| `lender_name` tanpa tabel `lenders` | Aturan PO: pemberi dana **tidak** punya akun/login. Relasi ke `users` akan memaksa akun fiktif dan membocorkan jalur login baru (A-13, O-5) |
| `fee_type` ENUM + `fee_rate_pct` NULL-able | Tarif hanya bermakna untuk `pct`; `NULL` eksplisit mencegah tarif "hantu" yang dibaca rumus lain. `fee_amount` selalu diisi (bentuk kanonik) sehingga pembaca tidak perlu tahu bentuknya |
| `fee_amount` hasil hitung peladen | Satu sumber kebenaran nominal; `fee_rate_pct` disimpan sebagai jejak perjanjian (audit), bukan sebagai sumber hitung saat baca |
| `fee_absorbed` / `fee_uncovered` terpisah | Membuktikan aturan PO secara aritmetika: `absorbed + uncovered == fee_amount` **dan** `absorbed ≤ operator_share_80` sesi itu, tanpa perlu membaca ulang `daily_settlements` |
| `status` ENUM, bukan kolom boolean | Pola schema yang sama dengan `hulu_transactions.status` / `withdrawal_requests.status`; memberi ruang status ketiga bila O-3 (fee bergulir) diterima |
| `settlement_id` + FK `ON DELETE RESTRICT` | Sama seperti `profit_distributions`: tautan audit ke sesi tutup buku tidak boleh hilang diam-diam. Konsekuensinya fixture QC **wajib** membuang `bridge_loans` sebelum `daily_settlements` (§ 9.3) |
| `register_id` + FK `ON DELETE RESTRICT` | Menyimpan **dari mana** dana diterima; pengembalian selalu ke pos yang sama (A-5) |
| indeks `(status, created_at)` | Jalur baca terpanas: "talangan aktif, urutan FIFO" (`WHERE status='active' ORDER BY created_at ASC`) — dipakai **setiap** tutup buku dan setiap render Layar B/C |
| Tanpa kolom `repaid_at` | `updated_at` + `daily_settlements.created_at` sudah menandai waktu; kolom tambahan tanpa pemakai = kode mati (kriteria `AGENTS.md`) |

### 3.2 Rekonsiliasi `docs/` — **ERD lebih dulu**

| Dokumen | Perubahan |
|---|---|
| `docs/AurumFlow Entity Relationship Diagram.md` | **§ 4**: tambah blok `CREATE TABLE bridge_loans` sebagai nomor 10 (blok "DATA INISIALISASI DASAR" menjadi nomor 11, isinya tidak berubah); **§ 1**: kotak relasi `CASH_REGISTERS ──1..N── BRIDGE_LOANS` dan `DAILY_SETTLEMENTS ──1..N── BRIDGE_LOANS`; **§ 2**: dua baris kardinalitas (`cash_registers → bridge_loans`: pos fisik penerima dana; `daily_settlements → bridge_loans`: satu sesi tutup buku mengembalikan banyak talangan); **§ 5**: catatan bahwa indeks `(status, created_at)` adalah kunci kinerja jalur tutup buku |
| `docs/AurumFlow System Architecture Document.md` | **§ 3** struktur: `Models/BridgeLoan.php`, `Services/BridgeLoanService.php`, `Views/operator/*` (modal & kartu); **§ 4.1** urutan penguncian global diperluas (`… → cash_registers → bridge_loans → hulu_transactions → system_settings`) + kelompok tulisan tutup buku menjadi **tujuh**; **§ 4.5 baru**: kontrak dana talangan (uang tanpa float, satu mutasi per talangan, fee hanya dari hak pengelola) |
| `docs/AurumFlow Roadmap and QC Documents.md` | **FASE 6** dipecah tegas: **FASE 6A — Modul Dana Talangan** (checklist teknis baru) dan **FASE 6B — Uji Simulasi End-to-End & Deployment** (checklist lama, tidak dikurangi); **Protokol QC Manual 6A** (Dana Talangan) ditulis sebagai tabel siap-salin 1:1 dengan § 9.1; **Protokol QC Manual 6** (produksi) diberi satu butir tambahan: uji satu siklus talangan pada server produksi |
| `docs/AurumFlow Product Requirement Document v3.0.md` | **§ 3.5 baru — Dana Talangan (Bridge Liquidity)**: definisi, tanpa akun, fee flat per siklus (%), syarat bahwa fee dibebankan **hanya** pada hak pengelola 80 %, dan larangan tegas menyentuh pool 20 %; **§ 4.2**: satu paragraf bahwa hari minus **tidak** membatalkan kewajiban pokok + fee (fee melebihi hak pengelola dibayar dari kas kerja pengelola dan dicatat `fee_uncovered`, modal mitra tetap tidak tersentuh); **§ 6 Modul 5**: rumus `pokok → fee → pengembalian → penyerapan → hak pengelola bersih`; **§ 8**: tambah "talangan" ke daftar istilah yang dilarang muncul di permukaan investor |
| `docs/AurumFlow UI UX Documents.md` | **Layar B** diberi sub-bagian **B2 — Modal "Terima Dana Talangan"** (tombol di bawah kartu timbang, modal 3 isian + pemilih bentuk fee, catatan "pokok bukan pendapatan") dan kartu ringkas **"Talangan Aktif"**; **Layar C** diberi kartu **"Talangan Aktif & Beban Fee"** + baris "Hak pengelola bersih" pada ringkasan bagi hasil |
| `database/schema.sql` | Salinan eksekutabel DDL ERD di atas (dengan komentar `SUMBER KEBENARAN: … § 4 point 10`) |
| `database/seed.sql` | **Tidak berubah** — tidak ada baris talangan yang di-seed; tabel baru terpasang oleh `schema.sql` (A-1) |

### 3.3 Yang **tidak** berubah (dijaga assertion)

- `daily_settlements` — jumlah & nama kolom identik dengan Fase 3 (assertion **H.5**); karena itu `DailySettlement::COLUMNS`/`REQUIRED_COLUMNS` **tidak disentuh**.
- `users`, `capital_wallets`, `cash_registers`, `cash_mutations`, `hulu_transactions`, `profit_distributions`, `withdrawal_requests`, `system_settings` — nol DDL.
- `system_settings` **tidak** mendapat kunci baru (tarif per talangan, bukan parameter global — O-4).

---

## 4. Aturan Bisnis & Aritmetika

### 4.1 Siklus hidup talangan

```
              POST /operator/teller/talangan  (malam)
                       │  satu Database::transaction()
                       ▼
  ┌──────────────────────────────────────────────────────────────┐
  │ bridge_loans: status='active'                                 │
  │   lender_name, principal_amount, fee_type, fee_rate_pct?,     │
  │   fee_amount (dihitung peladen), received_at (jam peladen)    │
  │ cash_registers[Kas Meja Bakar] += pokok                       │
  │ cash_mutations: 'in'  — label 'Talangan dari <nama>'          │
  └───────────────────────────────┬──────────────────────────────┘
                                  │  tutup buku siang (T+1)
                                  ▼
  ┌──────────────────────────────────────────────────────────────┐
  │ SettlementService::settle() — transaksi yang sama             │
  │   absorption  = BridgeLoanService::allocateAbsorption(hak, …) │
  │   kembalikan  = pokok + fee_amount                            │
  │   cash_registers[Kas Meja Bakar] -= kembalikan                │
  │   cash_mutations: 'out' — label 'Kembali talangan: <nama>'    │
  │   bridge_loans: status='settled', settlement_id,              │
  │                 fee_absorbed, fee_uncovered                   │
  └──────────────────────────────────────────────────────────────┘
```

Aturan transisi: **hanya** `active → settled`. Tidak ada jalur `settled → *`. Talangan yang gagal terbayar (settlement `422`) **tetap** `active` dan dicoba lagi pada tutup buku berikutnya (FIFO menempatkannya di depan).

### 4.2 Rumus fee (integer sen — tanpa float)

```
pokok_sen   = parseMoneyInput(principal_amount)             # > 0
tarif_perseratus = parseFeeRateInput(fee_rate_pct)          # > 0, ≤ 10000  (= 100,00 %)
fee_sen     = (pct)   intdiv(pokok_sen × tarif_perseratus + 5000, 10000)   # half away from zero
              (fixed) parseMoneyInput(fee_fixed)            # ≥ 0, kosong = 0
fee_amount  = centsToDecimal(fee_sen)
```

Penjagaan meluap sebelum perkalian (pola `TellerService::grossCents()`):

```php
if ($rateHundredths > 0 && $principalCents > intdiv(PHP_INT_MAX, $rateHundredths)) {
    throw new \InvalidArgumentException('Nilai talangan di luar jangkauan perhitungan.');
}
```

Contoh kanonik (dipakai QC):

| Pokok | Bentuk | Tarif / Nominal | `fee_amount` |
|---|---|---|---|
| Rp 10.000.000,00 | `pct` | 1,00 % | **Rp 100.000,00** |
| Rp 10.000.000,00 | `fixed` | Rp 100.000 | **Rp 100.000,00** (identik — assertion A.12) |
| Rp 1.000.001,00 | `pct` | 3,33 % | Rp 33.300,03 (pembulatan half away from zero) |
| Rp 5.000.000,00 | `fixed` | *(kosong)* | Rp 0,00 |

### 4.3 Penyerapan fee ke hak pengelola (aturan PO #4)

```
hak_pengelola_sen   = quote['operator_share_cents']            # 80 % laba bersih, TIDAK diubah
[bridge_allocation] = allocateAbsorption(hak_pengelola_sen, loans)   # FIFO greedy
fee_absorbed_sen    = Σ absorbed_i = min(Σ fee_i, hak_pengelola_sen)
fee_uncovered_sen   = Σ fee_i − fee_absorbed_sen
hak_bersih_sen      = hak_pengelola_sen − fee_absorbed_sen ≥ 0     # tidak pernah negatif
investor_pool_sen   = quote['investor_pool_cents']             # TIDAK disentuh — titik
```

Invariant yang dijaga (diuji):

1. `fee_absorbed_sen + fee_uncovered_sen == Σ fee_i`
2. `fee_absorbed_sen ≤ hak_pengelola_sen` dan `hak_bersih_sen ≥ 0`
3. `Σ profit_earned == investor_pool_20` dan `investor_pool_20 == 20 % laba bersih` — **identik** dengan/tanpa talangan
4. `operator_share_80 + investor_pool_20 == net_settled_profit` (invariant Fase 3, **tidak** runtuh)

### 4.4 Kas: satu mutasi per talangan

| Peristiwa | Pos kas | Mutasi | Label | `notes` (jejak audit) |
|---|---|---|---|---|
| Terima (malam) | Kas Meja Bakar `+pokok` | `1 × in` | `Talangan dari <nama>` | `bridge_loan_id=…; pokok=…; jenis=pct\|fixed; tarif=…; fee=…` |
| Kembali (tutup buku) | Kas Meja Bakar `−(pokok + fee)` | `1 × out` | `Kembali talangan: <nama>` | `bridge_loan_id=…; settlement_id=…; pokok=…; fee=…; fee_diserap=…; fee_tak_tertutup=…` |

Dua mutasi settlement `in` yang sudah ada tetap ditulis dan `notes`-nya **ditambah** ringkasan talangan (`; talangan=<n>; fee_talangan=…; fee_diserap=…; fee_tak_tertutup=…`) sehingga satu baris mutasi cukup untuk memahami sesi tutup buku.

### 4.5 Batas & penjagaan

| Batas | Nilai | Perlakuan |
|---|---|---|
| `principal_amount` | `> 0`, ≤ `DECIMAL(14,2)` | `parseMoneyInput()` (maks 12 digit, ≤ 2 desimal) |
| `fee_rate_pct` | `> 0`, ≤ `100,00` | `parseFeeRateInput()`; satu pemisah = desimal; sufiks `%` diabaikan |
| `fee_fixed` | `≥ 0` (kosong = 0) | `SettlementService::parseMoneyInput()` — **pemilik parser yang sama**, tanpa salinan |
| `lender_name` | wajib, ≤ 50 karakter | `Security::sanitize()` + batas karakter UTF-8 (pola `TellerService::normaliseAlias()`) |
| `notes` | opsional | `Security::sanitize()`, `TEXT` |
| Saldo kas meja | `≥ Σ(pokok + fee)` setelah alokasi | `RuntimeException` → `422`, **nol tulisan** (A-9) |

---

## 5. Teller UI/UX & Service — penerimaan dana

### 5.1 Route & alur `POST /operator/teller/talangan`

```
Front controller: POST → verifikasi CSRF (_token) → dispatch TellerController::storeBridge
  1. requireRole('operator')                     → investor: 302 /investor/dashboard
  2. CSRF diperiksa KEDUA kali (pertahanan berlapis, pola TellerController::store)
  3. `_once` (Security::FORM_FIELD)               → DUPLICATE: PRG + 'Talangan ganda diabaikan'
                                                  → bukan OK : 419
  4. baca form: lender_name, principal_amount, fee_type, fee_rate_pct, fee_fixed, notes
       (TIDAK ada nominal fee hasil hitung, settlement_id, atau bridge_loan_id dari klien — A-12)
  5. BridgeLoanService::receive($input)
       Database::transaction()
         a. CashRegister::lockTellerForUpdate()          ← pos kas dikunci lebih dulu (A-7)
         b. BridgeLoanService::quoteFee($input)          ← validasi + fee sen (murni)
         c. BridgeLoan::insert(... 'active', received_at = date('Y-m-d H:i:s'))
         d. CashRegister::adjustBalance(meja, +pokok)
         e. CashMutation::insert(..., 'in', <pokok>, 'Talangan dari ' . nama, notes)
  6. Logger::info('Dana talangan diterima', [...])       ← tanpa PIN/kata sandi apa pun
  7. PRG → /operator/teller + flash (nama, pokok, fee)
```

Kegagalan `InvalidArgumentException`/`RuntimeException` → `renderTeller()` dengan status `422`, isian terpelihara, **nol tulisan**.

### 5.2 Modal "Terima Dana Talangan" (Layar B2)

Tombol `af-btn--primary` **"Terima Dana Talangan"** ditempatkan di bawah kartu timbang (di dalam kartu `af-card` kedua, bukan di dalam form pembayaran — dua form terpisah, satu halaman, satu token aktif).

```
┌────────────────────────────────────────────────────────────┐
│  Terima Dana Talangan                            (x-cloak)  │
├────────────────────────────────────────────────────────────┤
│  Nama / alias pemberi dana *                                │
│  [ mis. Pak Haji Daud                                    ]  │
│                                                             │
│  Pokok dana yang diterima *                                 │
│  [ mis. 10.000.000                                       ]  │
│                                                             │
│  Bentuk fee                                                 │
│  ( ) Nominal (Rp)     ( ) Persen (%)      ← radio, x-model  │
│  [ mis. 100.000 ]   /  [ mis. 1,00 ]      ← dua isian,      │
│                                              keduanya POST  │
│  Catatan (opsional)                                         │
│  [                                    ]                     │
│                                                             │
│  Perkiraan fee            Rp 100.000      ← cermin Alpine   │
│  Kembali saat tutup buku  Rp 10.100.000   ← cermin Alpine   │
│                                                             │
│  ⓘ Pokok talangan BUKAN pendapatan; kembali pada tutup buku │
│    berikutnya bersama fee (T+1).                            │
│                                                             │
│  [ Batal ]                    [ Terima & Catat Kas Masuk ]  │
└────────────────────────────────────────────────────────────┘
```

Kontrak form (A-10/A-11):

| Aspek | Ketentuan |
|---|---|
| State | literal `x-data="{ open: false, feeType: 'fixed', submitting: false, … }"` — **tanpa** komponen `Alpine.data` baru |
| Modal tertutup | `x-cloak` + `x-show="open"` sehingga **tidak** menyisakan kotak hantu bila Alpine gagal dimuat (pelajaran J.13 Fase 5) |
| Nilai awal | dirender peladen (`value="…"`), dibaca `init()` lewat `formFieldValue(this, '<name>')`, **bukan** `$refs` (G.1a/G.3a/H.10–H.17) |
| Isian bernilai awal | `x-model.fill` supaya `x-model` tidak menghapus nilai yang dirender peladen |
| Kontrol | **TIDAK PERNAH** `:disabled` (kedua isian fee selalu ikut terkirim; peladen memilih menurut `fee_type`); selama kirim `:readonly="submitting"` + `opacity-70 cursor-not-allowed` |
| Tombol | `:disabled="submitting"` sah untuk `<button>` |
| Placeholder | numerik selalu berawalan `mis. ` (contoh, bukan data) |
| Ekspresi Alpine | hanya ekspresi (rantai Promise sah, pernyataan `try`/`if` di tengah **tidak** — J.10–J.12 Fase 5) |
| Buka modal saat error | `storeBridge()` mengirim `bridgeOpen = true` + `bridgeError` + isian terpelihara, sehingga operator melihat alasan penolakan tanpa mengetik ulang |
| Cermin pratinjau | `feeCents`/`totalReturnCents` dihitung dengan aturan yang sama (integer, half away from zero) dan **diuji** probe Node terhadap tabel kasus PHP (`js_bridge_fee_check.js`) — pratinjau tidak boleh berbohong |

### 5.3 Kartu "Talangan Aktif" di meja kasir

Ringkas, satu `SELECT` tanpa kunci (`BridgeLoan::listActive()`): nama pemberi dana, pokok, fee, waktu terima, total kewajiban, dan penanda "kembali pada tutup buku berikutnya". Bila kosong: satu baris "Tidak ada dana talangan berjalan." Tujuannya bukan estetika — pengelola **wajib** melihat kewajibannya saat menerima dana baru (mencegah dua talangan tanpa sadar).

### 5.4 Service: `BridgeLoanService`

| Method | Sifat | Isi |
|---|---|---|
| `quoteFee(array $input): array` | **murni** (dapat diuji tanpa DB) | validasi + normalisasi (`lender_name`, `principal_amount`, `fee_type`, `fee_rate_pct`, `fee_fixed`, `notes`), hitung `fee_cents`, kembalikan bentuk kanonik (`principal`, `fee`, `fee_amount`, `fee_rate_pct`, `total_return`, `total_return_cents`, `fee_type`, `lender_name`, `notes`) |
| `receive(array $input): array` | **mutasi** | satu `Database::transaction()`: kunci pos kas → insert `bridge_loans` → `adjustBalance(+pokok)` → mutasi `in`; mengembalikan ringkasan untuk flash & QC |
| `parseFeeRateInput(mixed $raw): int` | publik (untuk QC grup B) | tarif → perseratus persen |
| `allocateAbsorption(int $operatorShareCents, array $loans): array` | **murni** | FIFO greedy → `[ ['id','fee_cents','absorbed_cents','uncovered_cents'], … ]` + total |
| `settleLoans(PDO $pdo, string $settlementId, array $plan, string $registerId): array` | **mutasi di dalam transaksi pemanggil** | `UPDATE bridge_loans` (status/settlement_id/fee_absorbed/fee_uncovered) + `adjustBalance(−kembali)` + satu mutasi `out` per talangan; pola `AllocationEngine::apply($pdo, …)` |
| `activeLoans(): array` / `activeTotals(): array` | baca tanpa kunci | konteks pratinjau Layar B/C |

Konstanta: `LABEL_IN_PREFIX = 'Talangan dari '`, `LABEL_OUT_PREFIX = 'Kembali talangan: '`, `MUTATION_PREFIX` (dipakai `notes`), `FEE_TYPE_PCT = 'pct'`, `FEE_TYPE_FIXED = 'fixed'`, `STATUS_ACTIVE/STATUS_SETTLED`, `MAX_LENDER_CHARS = 50`, `MAX_RATE_HUNDREDTHS = 10000`.

### 5.5 Atomisitas & keamanan penerimaan

- Tiga tulisan (`bridge_loans`, `cash_registers`, `cash_mutations`) **selalu** dalam satu `Database::transaction()`; kegagalan mana pun menggulung seluruhnya (tidak ada kas yang muncul tanpa jejak pinjaman, dan sebaliknya).
- Nominal **selalu** dari `quoteFee()` — bukan dari kiriman klien (A-12).
- `Logger::info` tanpa data sensitif; `Logger::warning` untuk penolakan yang dapat ditindaklanjuti.
- Handler wajib turunan `BaseController` dengan method nyata; route baru didaftarkan di `app/routes.php` (POST saja) dan dispatch tetap di `public/index.php`.

---

## 6. Settlement UI/UX & Service — auto-pay fee

### 6.1 Kartu "Talangan Aktif & Beban Fee" (Layar C)

Ditempatkan **setelah** kartu "Rekonsiliasi Penjualan Toko" dan **sebelum** kartu hasil penjualan, agar operator melihat kewajiban sebelum mengisi angka:

```
┌────────────────────────────────────────────────────────────┐
│  Talangan Aktif & Beban Fee                                 │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ Pak Haji Daud   Pokok Rp 10.000.000  Fee Rp 100.000  │  │
│  │ Diterima 08/10 23:14 WIB                             │  │
│  └──────────────────────────────────────────────────────┘  │
│  Total kembali dari Kas Meja Bakar        Rp 10.100.000    │
│  Fee membebani HAK PENGELOLA 80 % — pool mitra tidak       │
│  tersentuh.                                                │
└────────────────────────────────────────────────────────────┘
```

Bila tidak ada talangan aktif → kartu tidak dirender sama sekali (bukan kartu kosong).

Pada ringkasan bagi hasil (mirror Alpine, sesuai kontrak Fase 3 bahwa pratinjau hanya cermin):

```
Hak pengelola 80 %          Rp 1.749.200
Fee talangan (1 talangan) − Rp   100.000
────────────────────────────────────────
Hak pengelola bersih        Rp 1.649.200
Hak pool mitra 20 %         Rp   437.300   ← tidak terpengaruh
```

`SettlementController::renderSettlement()` mengirim `bridge` (`count`, `total_principal_label`, `total_fee_label`, `rows[]`) dan `alpineConfig` bertambah `bridge_fee_cents` + `bridge_count` (integer dari kode kita sendiri, tetap dicetak `Security::e()`).

### 6.2 Perubahan `SettlementService`

**`batchContext()`** — konteks pratinjau bertambah (kompatibel mundur: pemanggil lama yang tidak mengirim kunci ini mendapat `0`/`[]`):

```php
return [
    'holding_mg'           => …,
    'holding_cost_cents'   => …,
    'active_deficit_cents' => …,
    'bridge_loans'         => BridgeLoanService::activeLoans(),   // ['id','fee_cents'] FIFO
];
```

**`quote(array $input, ?array $context = null)`** — keluaran bertambah (rumus Fase 3 **tidak berubah baris pun**):

```php
'bridge_count'             => $bridgeCount,
'bridge_fee'               => centsToDecimal($feeCents),
'bridge_fee_cents'         => $feeCents,
'bridge_absorbed'          => centsToDecimal($absorbedCents),
'bridge_absorbed_cents'    => $absorbedCents,
'bridge_uncovered'         => centsToDecimal($uncoveredCents),
'bridge_uncovered_cents'   => $uncoveredCents,
'bridge_total_return'      => centsToDecimal($feeCents + $principalCents),
'bridge_total_return_cents'=> $feeCents + $principalCents,
'bridge_allocation'        => [ ['id'=>…, 'fee_cents'=>…, 'absorbed_cents'=>…, 'uncovered_cents'=>…], … ],
'operator_take_home'       => centsToDecimal($operatorCents - $absorbedCents),
'operator_take_home_cents' => $operatorCents - $absorbedCents,
```

`bridge_allocation` dihitung **sekali** di `quote()` (A-4) dan dipakai apa adanya oleh `settle()` — tidak ada perhitungan kedua yang bisa menyimpang.

**`settle()`** — urutan menjadi:

```
0. quote() tolak-cepat (kini termasuk fee + penyerapan)
1. simpan foto nota (di luar transaksi, tetap)
2. Database::transaction():
     a. CapitalWallet::lockAllForUpdate()                       (lama)
     b. CashRegister::lockByNamesForUpdate([meja, brankas])      (lama)
     c. BridgeLoan::lockActiveForUpdate()      ← BARU (FIFO, A-7)
     d. HuluTransaction::holdingRowsForUpdate()                  (lama)
     e. SystemSetting::getForUpdate(deficit)                      (lama)
     f. quote() dari himpunan TERKUNCI
        → penjagaan saldo: (saldo meja + kas_split) ≥ Σ(pokok+fee)   ← BARU (A-9)
     g. DailySettlement::insert()                                 (lama)
     h. HuluTransaction::settleBatch()                            (lama)
     i. CashRegister::adjustBalance(meja, +kas)                   (lama)
        CashRegister::adjustBalance(brankas, +brankas)            (lama)
     j. BridgeLoanService::settleLoans($pdo, …)   ← BARU: ±kembalikan, status, 1 mutasi out/talangan
     k. AllocationEngine::apply($pdo, …)                          (lama, pool 20 % — TIDAK tersentuh fee)
     l. 2 mutasi 'in' (notes bertambah ringkasan talangan)         (lama + notes)
     m. SystemSetting::set(active_carryover_deficit)               (lama)
3. gulung + hapus nota bila gagal (tetap)
```

Kelompok tulisan tutup buku menjadi **tujuh**: dompet modal → pos kas ×2 → batch hulu → `daily_settlements` → `bridge_loans` (+delta kas + mutasi `out`) → `AllocationEngine` → `system_settings` (+2 mutasi `in`). `AGENTS.md` dan SAD § 4.1 diperbarui pada T1.

### 6.3 Mutasi & pesan

- Mutasi `out` per talangan: `amount = pokok + fee` (kanonik), `mutation_type='out'`, `source_destination='Kembali talangan: <nama>'`, `notes` lengkap (§ 4.4).
- Ringkasan flash sukses (`SettlementController::bridgeSuffix()`, pola `allocationSuffix()`):
  - normal → ` Talangan: 1 pengembalian Rp 10.100.000 (fee Rp 100.000 dibebankan ke hak pengelola).`
  - `fee_uncovered > 0` → ` Talangan: 1 pengembalian Rp 10.100.000 — Rp 20.000 dari fee melebihi hak pengelola dan dibayar dari kas meja.`
- `Logger::warning` saat `fee_uncovered > 0` (perlu perhatian manusia) dan `Logger::info` untuk setiap pengembalian; angka `fee_absorbed`/`fee_uncovered` termasuk pada log ringkasan sesi.

### 6.4 Penjagaan saldo (A-9)

```php
$tellerAfterAllocationCents = TellerService::moneyToCents((string) $teller['current_balance'])
    + (int) $quote['kas_cents'];

if ($tellerAfterAllocationCents < (int) $quote['bridge_total_return_cents']) {
    throw new \RuntimeException(
        'Saldo Kas Meja Bakar setelah alokasi (Rp …) tidak cukup mengembalikan dana talangan (Rp …). '
        . 'Naikkan alokasi Kas Meja Bakar atau lunasi talangan secara tunai lebih dulu.'
    );
}
```

Diperiksa **sebelum** tulisan pertama → `422`, nol tulisan, talangan tetap `active`.

### 6.5 Berkas yang berubah di sisi settlement

| Berkas | Perubahan |
|---|---|
| `app/Services/SettlementService.php` | konteks `bridge_loans`, 12 kunci keluaran `quote()`, langkah 2c/2j pada `settle()`, penjagaan A-9, `notes` mutasi bertambah ringkasan talangan |
| `app/Controllers/SettlementController.php` | data view `bridge` + `alpineConfig.bridge_*`, `bridgeSuffix()` pada flash, pesan error 422 tetap |
| `app/Views/operator/settlement.php` | kartu "Talangan Aktif & Beban Fee" + baris "Hak pengelola bersih" (mirror Alpine) |
| `public/assets/js/app.js` | `settlementForm` bertambah `bridgeFeeCents`/`bridgeCount` + getter `operatorTakeHomeCents` (cermin `min(fee, operator)`); **tanpa** aritmetika baru di luar cermin itu |
| `app/Models/BridgeLoan.php` (baru) | SQL saja: `insert()`, `listActive()`, `activeTotals()`, `lockActiveForUpdate()`, `listForSettlement(string $settlementId)`, `countActive()` |

---

## 7. Peta Route & Berkas

### 7.1 Route (`app/routes.php`)

```php
'POST' => [
    …
    '/operator/teller'            => [TellerController::class, 'store'],        // lama
    '/operator/teller/talangan'   => [TellerController::class, 'storeBridge'],  // BARU (Fase 6)
    …
],
```

GET tidak ditambah: modal hidup di `/operator/teller` (satu halaman, satu token aktif).

### 7.2 Berkas baru & yang berubah

| Berkas | Isi |
|---|---|
| `app/Models/BridgeLoan.php` | SQL-only: insert/lock/list/totals (§ 6.5) |
| `app/Services/BridgeLoanService.php` | `quoteFee()`, `receive()`, `parseFeeRateInput()`, `allocateAbsorption()`, `settleLoans()`, `activeLoans()`, konstanta label/status |
| `tests/qc_fase_6.php` | Protokol QC Manual 6A + grup **A–H** (§ 9.2) |
| `tests/manual/js_bridge_fee_check.js` | Probe Node: cermin fee (pct/fixed/pembulatan/klamp) vs tabel kasus PHP |
| Berubah | `database/schema.sql`, `app/routes.php`, `app/Controllers/TellerController.php`, `app/Controllers/SettlementController.php`, `app/Services/SettlementService.php`, `app/Views/operator/teller.php`, `app/Views/operator/settlement.php`, `public/assets/js/app.js`, `tests/bootstrap.php`, `tests/run_qc.sh`, `tests/qc_seed.php`, `tests/qc_fase_5.php`, `tests/manual/js_form_init_check.js`, `AGENTS.md`, `README.md` |
| **Tidak berubah** | `database/seed.sql`, `app/Models/DailySettlement.php`, `app/Models/CashRegister.php`, `app/Models/CashMutation.php`, `app/Services/AllocationEngine.php`, `app/Services/TellerService.php`, `app/Services/CapitalService.php`, seluruh berkas investor |

Catatan aset: modal **hanya** memakai kelas `af-*` yang sudah ada → `app.min.css` **tidak perlu** dikompilasi ulang kecuali muncul utilitas Tailwind baru (diperiksa pada T10 dengan `git status public/assets/css/app.min.css`).

---

## 8. Tugas Eksekusi (Step-by-Step)

### T1 — Dokumentasi lebih dulu (aturan `AGENTS.md`)

Kerjakan **seluruh** tabel § 3.2 sebelum satu baris kode: ERD (tabel ke-10 + kardinalitas) → SAD (struktur, urutan penguncian, kelompok tulisan ke-7, § 4.5 baru) → Roadmap (FASE 6A/6B + Protokol QC Manual 6A) → PRD (§ 3.5, § 4.2, § 6 Modul 5, § 8) → UI/UX (Layar B2, Layar C).

*Kriteria:* setiap perubahan menyebut bagian + alasan; tidak ada janji dokumen yang berbeda dari § 3–§ 6 rencana ini.

### T2 — Skema

- `database/schema.sql`: tambah blok `bridge_loans` (§ 3.1) + komentar sumber kebenaran.
- **Tidak** menyentuh blok lain; verifikasi `php -l` tidak berlaku untuk SQL → verifikasi lewat `php tests/qc_seed.php` (skema dibangun ulang) dan assertion H.1–H.5.
- Perbarui `AGENTS.md` (kontrak + kriteria selesai) & `README.md` (peta tabel, perintah, peringatan QC menulis `bridge_loans`).

### T3 — `app/Models/BridgeLoan.php`

SQL-only, seluruh nilai uang sebagai **string desimal kanonik**, named parameter, tanpa interpolasi:

| Method | Kueri |
|---|---|
| `insert(array $data)` | daftar kolom eksplisit (`COLUMNS` penjaga salah ketik, pola `DailySettlement`) |
| `listActive(): array` | `WHERE status='active' ORDER BY created_at ASC, id ASC` |
| `activeTotals(): array` | `COUNT(*)`, `SUM(principal_amount)`, `SUM(fee_amount)` baris aktif |
| `lockActiveForUpdate(): array` | idem `listActive()` + `FOR UPDATE` — **wajib** di dalam transaksi |
| `listForSettlement(string $settlementId): array` | jejak audit (dipakai QC, bukan UI) |
| `countActive(): int` | kartu ringkas Layar B/C |

### T4 — `app/Services/BridgeLoanService.php`

Urutan implementasi: `quoteFee()` (murni) → `parseFeeRateInput()` → `allocateAbsorption()` (murni) → `receive()` (transaksi) → `settleLoans()` (dipanggil dari transaksi settlement) → `activeLoans()`/`activeTotals()`. Seluruh aritmetika integer; konversi uang **wajib** lewat `TellerService::moneyToCents()`/`centsToDecimal()` (satu pemilik, tidak ada salinan ketiga).

### T5 — `SettlementService` (quote + settle)

Konteks `bridge_loans`, 12 kunci keluaran baru, langkah 2c/2j, penjagaan A-9, `notes` mutasi. **Wajib**: seluruh assertion `qc_fase_3` (P3.x, A–I) tetap lulus tanpa diubah — bukti bahwa rumus Fase 3 tidak bergeser.

### T6 — Controller

- `TellerController::storeBridge()` + helper privat `assertPostSecurity()` dipakai bersama `store()` (satu pemilik pemeriksaan CSRF+`_once`, status & flash perilaku **identik** dengan sebelumnya → `qc_fase_2` tetap lulus).
- Data view teller bertambah: `bridgeRows`, `bridgeTotals`, `bridgeOpen`, `bridgeError`, `bridgeInput`.
- `SettlementController`: data view `bridge`, `alpineConfig.bridge_*`, `bridgeSuffix()`.

### T7 — View & Alpine

- `views/operator/teller.php`: kartu "Talangan Aktif" + tombol + modal (§ 5.2) dengan **seluruh** kontrak A-10/A-11 terpenuhi.
- `views/operator/settlement.php`: kartu "Talangan Aktif & Beban Fee" + baris "Hak pengelola bersih".
- `public/assets/js/app.js`: `settlementForm` bertambah cermin fee; **tidak** menambah `Alpine.data` untuk modal.
- Seluruh output dinamis `Security::e(...)`; seluruh angka `Formatter::rupiah/gram/persen/tanggalId`; tidak ada `number_format()` langsung; tidak ada SQL di view.

### T8 — Fixture & QC baru

- `tests/bootstrap.php`: `resetBridgeState()` (hapus `bridge_loans` — **sebelum** `daily_settlements` karena FK `RESTRICT`), sisipkan `DELETE FROM bridge_loans` di `clearSettlementTraces()`, tambah `seedBridgeLoan(array $input)` (memakai `BridgeLoanService::receive()` — jalur kode nyata, bukan INSERT langsung) dan `bridgeSnapshot()`.
- `tests/qc_fase_6.php` (grup A–H) + `tests/manual/js_bridge_fee_check.js`.
- `tests/run_qc.sh`: sisipkan `qc_fase_6` setelah `qc_fase_5`; header/ringkasan **12 suite**.
- `tests/qc_seed.php` / `tests/qc_fase_5.php`: penyesuaian tabel ke-10 (§ 9.4).

### T9 — Isolasi investor

Tambahkan `BridgeLoan`, `bridge_loans`, dan istilah tampilan `talangan`/`dana talangan` ke daftar terlarang § 4.6 pada `AGENTS.md` **dan** ke pemindai `qc_fase_5` grup I; `qc_fase_6` grup G mengulang pemindaian yang sama untuk berkas baru. Nol perubahan pada `Views/investor/*`, `Views/layouts/{investor,report}.php`, `Services/InvestorService.php`, `Controllers/Investor*`, `Controllers/ExportController.php`.

### T10 — Gerbang penuh

```bash
php -l <setiap berkas yang diubah>
bash tests/run_qc.sh                      # exit 0 — 12 suite, 0 gagal
node tests/manual/js_form_init_check.js
node tests/manual/js_capital_simulator_check.js
node tests/manual/js_investor_check.js
node tests/manual/js_bridge_fee_check.js
test ! -e storage/logs/php-*.log && echo 'log bersih'
git status --short                        # tanpa artefak (tests/.tmp/, installed.lock, *.log)
git diff --stat                           # hanya berkas yang direncanakan § 7.2
```

### T11 — Serah terima

`plan/Summary_Fase_6_AurumFlow.md` (angka nyata, jumlah assertion, item terbuka), pembaruan baseline `AGENTS.md`/`README.md` (**12 suite**), dan `# ADDENDUM — Catatan Eksekusi` pada dokumen ini untuk setiap penyimpangan + alasannya.

---

## 9. Quality Control — Protokol QC Manual 6A (Dana Talangan)

### 9.1 Tabel protokol manual (siap disalin ke dokumen Roadmap)

Angka acuan memakai keadaan QC Fase 3 yang sudah baku (emosi *holding* 18,050 gr, modal belanja **Rp 35.157.500**, pendapatan toko **Rp 37.544.000**, laba bersih **Rp 2.186.500**, pengelola **Rp 1.749.200**, pool **Rp 437.300**) supaya tidak ada dua sumber kebenaran angka.

| # | Parameter Uji | Data Masukan | Hasil yang Diharapkan | Status |
|---|---|---|---|---|
| 6A.1 | **Terima talangan (fee nominal)** | Nama `Pak Haji Daud`, pokok `10.000.000`, bentuk **Nominal**, fee `100.000` | Kas Meja Bakar **+Rp 10.000.000**; 1 baris `bridge_loans` `status='active'`, `fee_amount = 100000.00`; 1 mutasi `in` berlabel `Talangan dari Pak Haji Daud` | [ ] |
| 6A.2 | **Terima talangan (fee persen)** | Pokok `10.000.000`, bentuk **Persen**, tarif `1,00` | `fee_amount = 100000.00` (dihitung peladen) — identik dengan 6A.1 walau nominal fee tidak dikirim klien | [ ] |
| 6A.3 | **Nominal tidak dipercaya** | Kirim `fee_amount=1` (palsu) bersama tarif `1,00` | Yang tersimpan tetap **Rp 100.000**; nilai palsu tidak berjejak | [ ] |
| 6A.4 | **Penjagaan isian** | Nama kosong / pokok `0` / tarif `0` / tarif `120` / fee nominal `-5` | `422` + pesan; **nol** tulisan pada `bridge_loans`, `cash_registers`, `cash_mutations` | [ ] |
| 6A.5 | **Tutup buku mengembalikan pokok + fee** | Settlement 18,050 gr @ `2.080.000`, beban aparat `200.000`, talangan 6A.1 aktif | Kas Meja Bakar **turun Rp 10.100.000**; `bridge_loans.status='settled'` + `settlement_id` terisi; 1 mutasi `out` `Kembali talangan: Pak Haji Daud`; `fee_absorbed = 100000.00`, `fee_uncovered = 0.00` | [ ] |
| 6A.6 | **Hak pengelola terpotong, pool utuh** | Bandingkan sesi tanpa talangan vs 6A.5 | `operator_share_80` **Rp 1.749.200** (tak berubah), hak pengelola bersih **Rp 1.649.200**; `investor_pool_20` tetap **Rp 437.300**; Σ `profit_earned` tetap **Rp 437.300**; tiap `balance_profit` naik persis seperti sesi tanpa talangan | [ ] |
| 6A.7 | **Fee melebihi hak pengelola** | Settlement dengan laba bersih kecil (hak pengelola < fee) | Fee diserap maksimal sebesar hak pengelola; sisanya `fee_uncovered` + `Logger::warning`; pool **tetap** 20 % laba bersih; kas meja tetap membayar pokok + fee penuh | [ ] |
| 6A.8 | **Hari minus** | Settlement rugi (mis. 18,050 gr @ `1.900.000`) dengan talangan aktif | `investor_pool_20 = 0.00`, tak ada `balance_profit` bertambah, `carryover_deficit` sesuai rumus Fase 3; `fee_absorbed = 0.00`, `fee_uncovered = 100000.00`; talangan **tetap lunas pokok + fee** | [ ] |
| 6A.9 | **FIFO & talangan pasca-settlement** | (a) Dua talangan aktif; (b) talangan baru diterima **setelah** tutup buku | (a) keduanya `settled` berurutan FIFO, Σ `fee_absorbed` = min(Σ fee, hak pengelola); (b) talangan baru **tetap** `active`, tidak ikut terbayar | [ ] |
| 6A.10 | **Saldo kas meja tidak cukup** | Alokasi kas manual terlalu kecil | `422` + pesan yang menyebut kekurangan; **nol** tulisan; talangan tetap `active` | [ ] |
| 6A.11 | **Isolasi permukaan investor** | Buka `/investor/dashboard`, `/investor/report`, `/investor/report/csv` | Tidak ada kata `talangan` maupun angka pokok/fee talangan di body/CSV | [ ] |

### 9.2 Grup assertion `tests/qc_fase_6.php`

| Grup | Cakupan (contoh ID) |
|---|---|
| **A** — Kalkulasi murni | A.1 fee nominal; A.2 fee persen; A.3 pembulatan half away from zero (tabel kasus); A.4 penjaga meluap (`principal` maks × tarif maks); A.5 `fee_amount` pct tidak pernah dari klien; A.6 tarif `0`/`>100`/desimal >2 ditolak; A.7 pokok `0`/negatif ditolak; A.8 `lender_name` kosong/`>50` karakter/markup → sanitasi & batas; A.9 `absorbed + uncovered == fee` dan `absorbed ≤ hak`; A.10 FIFO dengan hak terbatas; A.11 hak = 0 → absorbed 0; A.12 `pct 1,00 %` ≡ `fixed 100.000` |
| **B** — Paritas parser | B.1 tabel kasus bersama `BridgeLoanService::parseFeeRateInput()` vs kadar timbang air (lewat `SettlementService::quote()` `density`); B.2 paritas parser nominal (`parseMoneyInput`) termasuk kasus nol & format ribuan |
| **C** — Penerimaan (HTTP + layanan) | C.1 sukses → `302`, saldo +pokok, 1 baris `active`, 1 mutasi `in` (label+notes); C.2 gagal validasi → nol tulisan (tiga tabel); C.3 CSRF hilang → `419`; C.4 `_once` ganda → tanpa tulisan; C.5 investor → `302 /investor/dashboard`; C.6 nominal klien diabaikan; C.7 nama tampil ter-escape; C.8 tabel lain tidak tersentuh |
| **D** — Auto-pay | D.1 `settled` + `settlement_id` + `fee_absorbed`/`fee_uncovered`; D.2 kas meja turun persis Σ(pokok+fee); D.3 satu mutasi `out`/talangan (label + notes lengkap); D.4 `op + pool == net` masih berlaku; D.5 talangan pasca-settlement tetap `active`; D.6 dua talangan → dua baris + dua mutasi, urutan FIFO |
| **E** — **Isolasi investor (bukti utama)** | E.1 `investor_pool_20` identik dengan/tanpa talangan; E.2 Σ `profit_earned` identik; E.3 kenaikan `balance_profit` **tiap** dompet identik (Pengelola tetap 0); E.4 hak pengelola turun persis sebesar fee; E.5 `fee_uncovered` > 0 → pool & dompet utuh + ada `Logger::warning`; E.6 hari minus → pool 0,00, tak ada dividen, carry-over sesuai rumus lama; E.7 berkas investor bersih dari istilah talangan |
| **F** — Penjagaan & batas | F.1 saldo meja kurang → `422` nol tulisan + talangan `active`; F.2 tanpa emas holding → settlement tetap ditolak seperti Fase 3 (talangan tak terbayar); F.3 pokok melebihi kapasitas kolom → `422`; F.4 fee `0` (`fixed` kosong) → `fee_amount/fee_absorbed = 0.00`, mutasi `out` = pokok; F.5 `fee_rate_pct` tidak terisi saat `fixed` → `422` bila `pct` tapi tarif kosong |
| **G** — Kontrak view & pemindaian statis | G.1 tanpa SQL di controller/view; G.2 tanpa `float`/`number_format(`/`FLOAT`/`DOUBLE` di berkas baru; G.3 CSRF + `_once` pada kedua jalur POST; G.4 kontrol form tidak pernah `:disabled`; G.5 placeholder numerik `mis. `; G.6 seluruh output dinamis `Security::e()`; G.7 angka tampilan via `Formatter`; G.8 route terdaftar & handler ada; G.9 modal `x-cloak` + tanpa `Alpine.data` baru untuk interaksi sekali pakai; G.10 daftar terlarang investor (§ A-16) |
| **H** — Skema, dokumentasi & idempotensi | H.1 `bridge_loans` ada dengan 15 kolom + PK `CHAR(36)`; H.2 `checkDecimalConventions` `principal_amount`/`fee_amount`/`fee_absorbed`/`fee_uncovered` = (14,2) dan `fee_rate_pct` = (5,2); H.3 enum `fee_type`/`status` persis; H.4 FK `settlement`/`register` = `RESTRICT`; H.5 **`daily_settlements` tetap 15 kolom** (keputusan `dec-c71090762d066f0f`); H.6 total tabel = **10**; H.7 suite dapat dijalankan berulang (fixture membersihkan `bridge_loans` sebelum `daily_settlements`); H.8 `docs/` memuat tabel ke-10 + istilah talangan, `AGENTS.md`/`README.md` memuat kontrak baru; H.9 `database/seed.sql` tidak memuat baris talangan |

### 9.3 Fixture & idempotensi

```php
// tests/bootstrap.php
QcFixture::resetBridgeState(): void        // DELETE FROM bridge_loans  (sebelum daily_settlements — FK RESTRICT)
QcFixture::seedBridgeLoan(array $input)    // lewat BridgeLoanService::receive() — jalur kode nyata
QcFixture::bridgeSnapshot(): array         // baris aktif + total pokok/fee + saldo meja
```

Aturan urutan (dokumentasikan di `AGENTS.md` seperti pelajaran `profit_distributions`): pembersihan data QC berurutan `bridge_loans` → `profit_distributions` → `daily_settlements` → `hulu_transactions`. `clearSettlementTraces()` menyisipkan `DELETE FROM bridge_loans` di baris pertama; **`resetTellerState()` juga memanggil `resetBridgeState()`** karena ia mengosongkan mutasi pos kas meja dan mengembalikan saldonya ke Rp 50.000.000 — tanpa itu, talangan lama tetap `active` padahal mutasi kas masuknya sudah lenyap, sehingga suite memeriksa pengembalian yang tidak pernah diterima. `resetSettlementState()`/`resetCapitalState()`/`resetInvestorState()` tidak perlu diubah karena memanggil fixture tersebut.

### 9.4 Perubahan wajib pada suite lama (disengaja)

| Berkas | Assertion | Perubahan | Alasan |
|---|---|---|---|
| `tests/qc_seed.php` | `$erdTables` (9 → **10**) + turunannya (jumlah tabel, PK, kolom, engine, seed) | tambah `'bridge_loans'` | Skema ERD resmi bertambah satu tabel; assertion ini memeriksa **daftar tabel ERD**, bukan jumlah tetap 9 |
| `tests/qc_fase_5.php` | `K.1` (`$expectedTables` eksak = 9) | tambah `bridge_loans` + label menjadi "Skema 10 tabel ERD (Fase 6 menambah `bridge_loans`; nol DDL pada tabel lama)" | Assertion ini membandingkan **seluruh** tabel basis data; tanpa pembaruan ia gagal karena alasan yang sah |
| `tests/qc_fase_5.php` | grup `I` daftar terlarang berkas investor | tambah `BridgeLoan`, `bridge_loans`, `talangan` | Privasi investor diperluas A-16 (satu pemilik daftar) |
| `tests/qc_fase_4.php` | `I.5` ("Sembilan tabel ERD tetap ada") | **tidak diubah** — ia menghitung keberadaan sembilan tabel lama + `!tableExists('cash_transfers')`, keduanya tetap benar | Menghindari churn; diverifikasi saat gerbang T10 |
| `tests/run_qc.sh` | daftar `SUITES` | tambah `qc_fase_6` setelah `qc_fase_5`, header/ringkasan 12 suite | Gerbang kelulusan |

### 9.5 Gate penerimaan

`bash tests/run_qc.sh` **exit 0** dengan **12 suite** (`qc_pdo`, `qc_formatter`, `qc_security`, `qc_seed`, `qc_roadmap_protocol_1`, `qc_fase_2`, `qc_fase_3`, `qc_fase_4`, `qc_fase_5`, **`qc_fase_6`**, `qc_sql_injection`, `qc_routing`), 0 gagal, tanpa `storage/logs/php-*.log`, dan keempat probe Node lulus. Baseline baru dicatat di `Summary_Fase_6` + `AGENTS.md` (target ≥ **690** assertion; perkiraan `qc_fase_6` ≈ **95**).

---

## 10. Risiko & Mitigasi

| ID | Risiko | Mitigasi |
|---|---|---|
| R-1 | **Fee menyentuh pool investor** (jalan termahal bila benar-benar terjadi) | A-3: fee diserap **setelah** bagi hasil; `AllocationEngine` tidak pernah menerima fee; grup E membandingkan langsung sesi dengan/tanpa talangan (pool, Σ `profit_earned`, `balance_profit` per dompet) |
| R-2 | **Invariant Fase 3 runtuh** (`op + pool == net`) | Tabungan: fee **tidak** ditulis ke `daily_settlements` (keputusan `dec-c71090762d066f0f`); assertion A.3 `qc_fase_3` + D.4 `qc_fase_6` |
| R-3 | **Deadlock urutan penguncian** (jalur baru vs settlement/penarikan) | A-7: `bridge_loans` disisipkan setelah `cash_registers` dan sebelum `hulu_transactions` — perpanjangan lurus urutan lama; jalur penerimaan malam (`cash_registers → bridge_loans`) tidak pernah beradu urutan; didokumentasikan di `AGENTS.md` |
| R-4 | **Kas tidak cukup untuk pengembalian** (alokasi manual) | A-9: penjagaan saldo sebelum tulisan pertama, `422` + pesan actionable, talangan tetap `active` (diuji F.1) |
| R-5 | **Talangan lunas dua kali** (double submit / retry jaringan) | `_once` sekali pakai di jalur penerimaan (C.4); pengembalian hanya menyentuh baris `FOR UPDATE` berstatus `active` di dalam transaksi (`status='settled'` menutup jalur kedua) |
| R-6 | **Talangan yatim** (baris `active` tanpa kemungkinan terbayar karena settlement selalu gagal) | Talangan tetap `active` + kartu "Talangan Aktif" di Layar B/C; O-2 (jalur pembayaran manual) dicatat sebagai keputusan PO |
| R-7 | **Modal talangan dianggap pendapatan** (omzet/laba menggelembung) | A-2 + assertion D (pokok tidak pernah masuk `daily_settlements`); `total_shop_revenue`/`net_settled_profit` dibandingkan dengan sesi tanpa talangan |
| R-8 | **Kebocoran privasi investor** (talangan = sinyal likuiditas pengelola) | A-16 + grup E.7/G.10 + daftar terlarang `qc_fase_5` diperluas |
| R-9 | **Pratinjau Alpine berbohong** (fee/klamp berbeda dari peladen) | Cermin integer dengan aturan sama + probe Node `js_bridge_fee_check.js` atas tabel kasus yang sama dengan grup A |
| R-10 | **Modal `x-data` hilang karena `app.js` di-cache** (pelajaran Fase 5) | A-11: state literal di view, **tanpa** `Alpine.data` baru; modal `x-cloak` sehingga tidak menyisakan kotak hantu; probe `js_form_init_check` diperluas |
| R-11 | **Kontrol form ter-`:disabled`** → POST hanya membawa `_token` (payload ± 357 byte) dan peladen menjawab `422` | A-10 + assertion G.4 (kontrol `:disabled` dilarang; hanya `<button>`) |
| R-12 | **QC tidak idempoten** (FK `RESTRICT`/suite berulang) | § 9.3: `bridge_loans` dibuang sebelum `daily_settlements`; `seedBridgeLoan()` memakai jalur layanan nyata; assertion H.7 |
| R-13 | **Suite lama gagal karena tabel ke-10** | § 9.4: dua assertion diperbarui dalam satu commit, beralasan; `qc_fase_4` I.5 diverifikasi tidak terpengaruh |
| R-14 | **Kode mati** pada model/service baru | Setiap method publik punya pemanggil nyata atau assertion QC (kriteria `AGENTS.md`); `listForSettlement()` dijaga assertion D.1 |

---

## 11. Item Terbuka (perlu keputusan sebelum/di awal eksekusi)

| ID | Item | Rekomendasi |
|---|---|---|
| O-1 | Representasi pembukuan fee (`bridge_loans` saja — dipilih; vs kolom `daily_settlements.bridge_fee_deducted`; vs menulis ulang `operator_share_80`) | Diterima seperti keputusan `dec-c71090762d066f0f`. Bila PO kemudian ingin potongan **terlihat langsung** pada baris `daily_settlements`, itu perubahan lanjutan ber-DDL + migrasi idempoten (bukan bagian Fase 6) |
| O-2 | Tidak ada jalur pembayaran talangan **di luar** tutup buku (pemberi dana menagih malam/di luar jam settlement) | Ditunda; sementara operator mencatat pengembalian manual di luar sistem. Bila dibutuhkan: aksi "Bayar Talangan" di `/operator/capital` (jalur kas brankas/meja) — butuh keputusan PO |
| O-3 | Fee pada talangan yang belum lunas: apakah siklus berikutnya menambah fee baru? | Rekomendasi: **tidak** — satu fee per peristiwa peminjaman (`flat fee per siklus`), karena talangan selalu lunas pada tutup buku berikutnya kecuali settlement gagal. Perlu penegasan PO |
| O-4 | Tidak ada tarif fee default di `system_settings` | Diterima (parameter per transaksi, bukan parameter global). Menambahkan `default_bridge_fee_pct` = 1 kunci seed + nilai awal form; menunggu permintaan |
| O-5 | Tidak ada batas jumlah/nominal talangan aktif (operator dapat menumpuk pinjaman) | Rekomendasi: dibiarkan (kebutuhan nyata: kas habis dua kali dalam semalam), tetapi **ditampilkan** jelas pada Layar B/C. Bila PO ingin batas, tambahkan invariant `Σ pokok aktif ≤ X` pada `receive()` |
| O-6 | Keberadaan `fee_uncovered` dianggap terlalu longgar (fee melebihi hak pengelola tetap dibayar tunai) | Diterima: alternatif (tunda fee / tolak settlement) menambah mesin status atau memblokir tutup buku pada hari minus (PRD § 4.2). Ditinjau bila PO memilih kebijakan lain |
| O-7 | Nama pemberi dana disimpan apa adanya (bukan data pribadi terverifikasi) | Diterima: kolom `VARCHAR(50)`, disanitasi; tidak ada KTP/identitas yang diminta (aturan PO: cukup nama/alias). Bila PO butuh identitas, itu fase lanjutan + peninjauan privasi |
| O-8 | `registry` talangan tidak mencatat siapa yang meminjam (selalu pengelola) | Diterima: hanya satu peran operator per instalasi (`Constants::ROLE_OPERATOR`); kolom `user_id` akan jadi kolom mati |

---

## 12. Checklist Serah Terima Fase 6

- [ ] Seluruh kriteria § 1.3 terpenuhi dengan bukti perintah (`bash tests/run_qc.sh`, empat probe Node, `php -l`, `git status`, `git diff --stat`).
- [ ] `docs/` (ERD tabel ke-10, SAD § 3/§ 4.1/§ 4.5, Roadmap FASE 6A/6B + Protokol 6A, PRD § 3.5/§ 4.2/§ 6/§ 8, UI/UX Layar B2/C) dan `AGENTS.md`/`README.md` selaras dengan kode — **tidak ada penyimpangan senyap**.
- [ ] Nol `ALTER TABLE` pada tabel lama; `database/seed.sql` tidak berubah; `daily_settlements` tetap 15 kolom.
- [ ] `plan/Summary_Fase_6_AurumFlow.md` memuat angka nyata, jumlah assertion, dan item terbuka.
- [ ] `# ADDENDUM` dokumen ini diisi untuk setiap penyimpangan eksekusi + alasannya.
- [ ] Tidak ada kode mati; tidak ada `storage/logs/php-*.log`; `storage/` tidak ter-commit.

---

## 13. Lampiran — Alur Request

### 13.1 `POST /operator/teller/talangan`

```
Front controller: POST → CSRF → dispatch TellerController::storeBridge
  1. requireRole('operator')
  2. CSRF (kedua kali) → 419 bila invalid
  3. `_once` → DUPLICATE: PRG + 'Talangan ganda diabaikan' | INVALID: 419
  4. baca lender_name, principal_amount, fee_type, fee_rate_pct, fee_fixed, notes
  5. BridgeLoanService::receive()
       Database::transaction()
         a. CashRegister::lockTellerForUpdate()
         b. quoteFee() → validasi, fee sen, bentuk kanonik
         c. BridgeLoan::insert(status 'active', received_at = jam peladen)
         d. CashRegister::adjustBalance(meja, +pokok)
         e. CashMutation::insert('in', pokok, 'Talangan dari <nama>', notes)
  6. Logger::info('Dana talangan diterima', [...])
  7. PRG /operator/teller + flash
```

### 13.2 Tutup buku dengan talangan (`SettlementService::settle()`)

```
  0. quote(input, batchContext())          ← tolak-cepat, termasuk fee & penyerapan
  1. simpan foto nota (di luar transaksi)
  2. Database::transaction():
       a. capital_wallets        FOR UPDATE (id menaik)
       b. cash_registers         FOR UPDATE (nama menaik: meja, brankas)
       c. bridge_loans           FOR UPDATE (status='active', FIFO created_at, id)   ← BARU
       d. hulu_transactions      FOR UPDATE (batch holding)
       e. system_settings        FOR UPDATE (active_carryover_deficit)
       f. quote() dari himpunan terkunci
            ├─ absorption  = allocateAbsorption(operator_share_cents, loans)
            ├─ kembalikan  = Σ(pokok + fee)
            └─ penjagaan   : (saldo meja + kas_split) ≥ kembalikan, jika tidak → 422 (nol tulisan)
       g. daily_settlements      INSERT  (op 80 % / pool 20 % — rumus Fase 3 utuh)
       h. hulu_transactions      UPDATE  status 'settled' + settlement_id
       i. cash_registers         +kas (meja) / +brankas (brankas)
       j. bridge_loans           UPDATE status/settlement_id/fee_absorbed/fee_uncovered   ← BARU
          cash_registers         −(pokok + fee) per talangan                               ← BARU
          cash_mutations         'out' 1× per talangan ('Kembali talangan: <nama>')        ← BARU
       k. AllocationEngine::apply()   ← pool 20 % ke dompet mitra (TIDAK tersentuh fee)
       l. cash_mutations         'in' 2× (meja, brankas) + ringkasan talangan pada notes
       m. system_settings        SET active_carryover_deficit
  3. flash: 80 / 20 + talangan (+ notis fee tak tertutup bila ada) → PRG
```

---

# ADDENDUM — Catatan Eksekusi

*(Diisi selama eksekusi: setiap penyimpangan dari rencana di atas dicatat di sini beserta alasannya, sesuai `AGENTS.md`.)*

**Status: Fase 6 DIEKSEKUSI PENUH. `bash tests/run_qc.sh` exit 0 — 12/12 suite,
698 assertion, 0 gagal (`qc_fase_6` 99 assertion). Laporan lengkap:
`plan/Summary_Fase_6_AurumFlow.md`. Keputusan terkunci: `dec-c71090762d066f0f`.**

## A-1. Penyimpangan dari rencana

| Rencana | Kenyataan | Alasan |
|---|---|---|
| § 5.2 / A-11: modal talangan memakai **state literal** (`x-data="{ … }"`) di view | State hidup di komponen **`tellerForm` yang sudah terdaftar**; satu akar `x-data="tellerForm(…)"` membungkus kartu timbang **dan** kartu/modal talangan (root lama `<section>` menjadi `<div>` pembungkus) | Tujuan A-11 tetap tercapai — **tidak ada komponen `Alpine.data()` baru** (komponen baru dapat belum ada di `app.js` yang ter-cache, sehingga modal hilang). Keuntungan tambahan: cermin fee menjadi dapat diuji lewat seam `registry.tellerForm` yang sudah dipakai `js_form_init_check.js`; dengan state literal murni, probe Node tidak punya jalan masuk tanpa mengekspos helper internal ke `window` (yang sengaja dihindari proyek ini) |
| § 6.5: pemetaan kartu talangan (`mapBridgeView()`, `feeTypeLabel()`) ada di `TellerController` | Dipindahkan ke `BridgeLoanService::viewModel()` | Kartu yang sama dipakai Layar B (kasir) **dan** Layar C (rekonsiliasi); dua salinan label dapat menyimpang diam-diam. Preseden: `InvestorService::dashboard()` menyusun view-model dengan `Formatter` |
| § 5.4: `activeLoans()` sebagai satu-satunya penyedia baris siap pakai | ditambah `BridgeLoanService::presentRows(array $rows)` (publik) | `settle()` bekerja pada baris **TERKUNCI** (`BridgeLoan::lockActiveForUpdate()`), sedangkan pratinjau memakai baris tanpa kunci; keduanya kini melewati normalisasi yang sama sehingga bentuk data tidak dapat berbeda |
| § 6.2: konteks `quote()` diberi kunci `'bridge_loans'` berisi hasil `activeLoans()` | idem, dengan bentuk baris yang sama dari `presentRows()` (memuat `principal_cents`, `fee_cents`, `lender_name`, `id`) | `allocateAbsorption()` butuh sen + nama; satu bentuk baris untuk pratinjau, eksekusi, dan QC |
| § 9.2 grup F: penjagaan saldo diuji dengan "alokasi kas manual terlalu kecil" | skenario diubah: pokok talangan **dibelanjakan seluruhnya** malam itu, lalu seluruh kas masuk dialokasikan ke brankas (kas meja tinggal Rp 1.770.000 vs kewajiban Rp 20.100.000) | Kas talangan berada **di dalam** kas meja, sehingga memperkecil alokasi saja tidak pernah membuat meja tekor (penjagaan tidak akan pernah terpicu); skenario lama hanya akan menguji penolakan lain. Analisis angka lengkap ada di komentar assertion F.1 |
| § 9.2 grup C: "`_once` ganda → tanpa tulisan" | Ditulis sebagai: kiriman kedua dijawab **`302` PRG** (`FORM_DUPLICATE`) dan jumlah baris `bridge_loans` tidak berubah | Itu perilaku yang memang dirancang `assertPostSecurity()` (flash "Talangan ganda diabaikan" + PRG), sama seperti jalur kasir Fase 2 |
| § 9.2 grup C: investor → `302 /investor/dashboard` | Token CSRF diambil dari `/investor/dashboard` dan POST **tanpa** `_once` | Front controller memeriksa CSRF sebelum dispatch; tanpa token sah permintaannya berhenti di `419` dan gerbang peran tidak pernah teruji. `_once` tidak diperlukan karena `requireRole()` berjalan sebelum pemeriksaan token sekali-pakai |
| § 9.4: `qc_seed.php` hanya menambah `$erdTables` | Sekaligus menambah ekspektasi DECIMAL `bridge_loans` (4 kolom uang + 1 kolom persen) | Konvensi tipe ERD § 3.1 kini berlaku untuk tabel ke-10; tanpa itu tabel baru tidak ikut diperiksa |
| § 5.2 catatan "modal hanya memakai kelas `af-*` yang ada → CSS mungkin tidak perlu dikompilasi" | `app.min.css` **dikompilasi ulang** (24 KB) | Markup modal memang butuh kelas yang belum ada di bundel: `fixed`, `z-30`, `backdrop-blur-sm`, `bg-obsidian-950/85` |
| § 6.1: kartu talangan Layar C memuat baris "Hak pengelola bersih" | idem + notis `fee_uncovered` (muncul hanya bila > 0) | Menutup kasus PO #3 (fee melebihi hak pengelola) tanpa membuat angka menyesatkan |

## A-2. Temuan yang perlu diingat pada fase berikutnya

1. **Kas talangan ada di dalam kas meja.** Karena penerimaan menambah `Kas Meja Bakar` dan
   pengembalian keluar dari pos yang sama, penjagaan A-9 hanya terpicu ketika operator
   mengalokasikan kas masuk ke brankas lebih banyak daripada yang tersedia untuk pengembalian —
   bukan sekadar "alokasi kecil". Uji F.1 membangun keadaan itu secara eksplisit.
2. **Token `_once` milik SESI, bukan halaman.** Layar rekonsiliasi dapat memakai token yang
   diterbitkan layar kasir (dipakai fixture F.3 ketika tidak ada emas `holding` sehingga form tutup
   buku tidak dirender). Jangan menambah pemeriksaan per-halaman tanpa meninjau jalur ini.
3. **Pembersihan QC berurutan wajib.** `bridge_loans.settlement_id` memakai `ON DELETE RESTRICT`,
   sehingga `QcFixture::resetBridgeState()` (dipanggil `resetTellerState()` **dan**
   `clearSettlementTraces()`) harus berjalan sebelum `daily_settlements` dihapus.
4. **Pemasangan tabel ke-10 pada instalasi lama.** `storage/installed.lock` menolak
   `database/install.php` kedua, jadi jalurnya adalah **impor ulang `database/schema.sql`**
   (idempoten, tanpa `ALTER TABLE`). Sudah dicatat di `README.md` § 7.13 dan checklist FASE 6B.
5. **`daily_settlements` sengaja tidak bertambah kolom.** Bila PO kelak ingin potongan fee tampak
   langsung di baris tutup buku, itu perubahan DDL ber-migrasi — bukan penyesuaian kecil.
6. **`qc_fase_3`/`qc_fase_4` tidak disentuh** pada fase ini: invariant Fase 3 (`A.3`) dan ekspektasi
   angka Fase 4 tetap lulus apa adanya, sehingga perubahan Fase 6 terbukti tidak menggeser rumus
   bagi hasil.

## A-3. Baseline akhir

`bash tests/run_qc.sh` → **exit 0**, `12/12` suite (`qc_pdo` 20, `qc_formatter` 43,
`qc_security` 32, `qc_seed` 46, `qc_roadmap_protocol_1` 6, `qc_fase_2` 66, `qc_fase_3` 98,
`qc_fase_4` 112, `qc_fase_5` 130, `qc_fase_6` **99**, `qc_sql_injection` 15, `qc_routing` 31),
**698 assertion, 0 gagal**. Probe Node: `js_form_init_check` 47/47,
`js_capital_simulator_check` 38/38, `js_investor_check` 45/45, `js_bridge_fee_check` **45/45**.
Tidak ada `storage/logs/php-*.log` baru; `php -l` bersih pada seluruh berkas yang disentuh.

## A-4. Perbaikan pascarilis (uji manual PO) — baris fee talangan pada Layar C

**Temuan uji manual:** baris "Fee talangan" & "Hak pengelola bersih" dilaporkan hilang saat
operator mengisi angka untung (kadar 93,50 / harga Rp 2.080.000).

**Penelusuran (bukan asumsi):** ekspresi `x-show` diambil apa adanya dari markup lalu dievaluasi
seperti Alpine terhadap `settlementForm` nyata untuk tiga keadaan isian — **kedua baris sudah
`true` di ketiganya** (kosong, hari sehat, hari minus); yang benar-benar mengikuti laba hanyalah
**peringatan** "Sebagian fee talangan…" (kondisi `bridgeUncoveredCents > 0`), sehingga baris fee
tampak ikut hilang/ muncul. Pemicu lain yang mungkin: sesudah tutup buku dijalankan, PRG memuat
ulang halaman dengan `bridge_count = 0` (talangan sudah `settled`), jadi baris memang seharusnya
hilang — perubahan keadaan halaman, bukan reaksi terhadap isian.

**Perbaikan:** satu pemilik keputusan tampil/tidak — getter `bridgeHasActive` (dari peladen,
tidak bergantung laba) untuk kedua baris, dan `bridgeHasUncovered` untuk peringatan; markup tidak
lagi menuliskan kondisi dua kali. Dijaga assertion `qc_fase_6` **G.13–G.16** dan bagian 4 probe
`js_bridge_fee_check.js` (mengevaluasi ekspresi `x-show` dari markup untuk tiga keadaan isian);
uji negatif (bug disuntikkan kembali) menolak dengan 4 kegagalan. Baseline bergeser:
`qc_fase_6` 99 → **105**, probe 45 → **63**, total **704** assertion. Rincian:
`plan/Summary_Fix_Settlement_UI.md` (§ "Perbaikan Lanjutan — Baris Dana Talangan", 2026-10-12).

Kutipan § A-3 di atas sengaja dibiarkan sebagai catatan saat fase selesai; angka terkini ada pada
`AGENTS.md`, `README.md`, dan berkas ringkasan perbaikan ini.

