# Plan Fase 10 — Modul Mitra Lapangan / Aparat (*Coordination Ledger & Blind Portal*) — AurumFlow

| | |
|---|---|
| **Fase** | 10 — Mitra Lapangan / Aparat (Coordination Ledger & Blind Portal) |
| **Status** | Rencana eksekusi — belum ada kode yang ditulis |
| **Tanggal** | 2025-10-25 |
| **Keputusan pengguna** | `dec-2b529bcb6a90f1db` (bonus = akrual tanpa voucher; cap potongan = `max(0, laba kotor)`; pencairan = operator saja, dua pos kas) |
| **Basis QC saat ini** | 15 suite, 900 assertion, 0 gagal (`bash tests/run_qc.sh`) |
| **Target QC** | 16 suite, ≥ 1.030 assertion, 0 gagal |
| **Skema** | 2 tabel baru (ke-15, ke-16) + 1 nilai ENUM di ujung `users.role`; **nol ALTER** pada tabel lama |
| **Dokumen acuan** | ERD § 2–4, SAD § 4.1, PRD v3.0 § 3.2–3.4 & § 4.2, Roadmap & QC (Protokol 1–9), `audit/Audit_Biaya_Koordinasi_AurumFlow.md`, `AGENTS.md`, `plan/Plan_Fase_3/4/6/7/8/9` |
| **Sifat dokumen** | Rencana (**tanpa kode**): DDL dinyatakan sebagai tabel kolom/constraint, aritmetika dinyatakan sebagai rumus, alur dinyatakan sebagai langkah |

---

## 1. Ringkasan Fase 10

### 1.1 Konteks bisnis

Aparat / mitra lapangan (keamanan wilayah, koordinasi kampung, pengawas patok) dibayar
**berdasarkan persentase dari laba kotor harian** — bukan nominal tetap. Hari ini biaya itu
hanya bisa dicatat sebagai **voucher OPEX** (`operational_expenses`, Fase 9): nominal tetap,
satu penyerahan, tanpa konsep hak berjalan. Dua akibatnya:

1. **Tidak ada buku hak.** Uang aparat dibayar tunai di muka, padahal imbalannya berbasis
   persentase margin yang baru diketahui **setelah** tutup buku. Bila arus kas tipis, aparat
   menunggu tanpa jejak berapa yang masih menjadi haknya.
2. **Persentase tidak dapat dihitung peladen.** `coord_fee` sudah dihapus dari form (Fase 9)
   justru agar operator tidak bisa memfiktifkan beban; konsekuensinya potongan berbasis
   margin juga tidak punya kanal — padahal justru inilah bentuk yang paling sulit difiktifkan
   karena **basisnya angka peladen** (pendapatan toko − modal hulu).

Fase 10 menambahkan **buku hak (ledger) per mitra lapangan**, pemotongan persentase otomatis
di dalam tutup buku, bonus manual yang juga akrual, pencairan atas permintaan, dan **portal
blind** tempat petugas hanya melihat saldo hak serta riwayatnya sendiri.

### 1.2 Tujuan (enam hal yang wajib benar)

1. **Potongan berbasis margin, dihitung peladen.** `potongan_i = f(laba_kotor, pct_i)`;
   laba kotor = pendapatan toko − modal belanja hulu, keduanya dari baris **terkunci**
   (`FOR UPDATE`), bukan dari form.
2. **Dipotong sebelum bagi hasil 80 : 20.** `net = laba_kotor − Σbeban − Σbonus − Σpotongan −
   pemulihan_defisit`; pool mitra 20 % ikut mengecil secara proporsional, bukan hak pengelola saja.
3. **Buku hak bertambah, kas belum tentu keluar.** Kredit `cut`/`bonus` menaikkan
   `officers.balance_payable`; uangnya **terparkir di laci** sampai dicairkan.
4. **Pencairan = kas keluar + saldo hak turun**, satu transaksi, satu baris `cash_mutations`
   `out`, dari **Kas Meja Bakar atau Brankas Utama** (pemilih pos dari daftar putih, bukan UUID).
5. **Bonus manual adalah akrual, bukan pembayaran.** Bonus menaikkan saldo hak **dan**
   mengurangi laba pada tutup buku berikutnya — **tanpa** voucher `operational_expenses`
   (keputusan `dec-2b529bcb6a90f1db` opsi B), sehingga tidak ada beban yang dihitung dua kali.
6. **Isolasi blind petugas bersifat *allow-list*.** `ROLE_OFFICER` hanya boleh menyentuh
   `/officer/dashboard` dan `/officer/riwayat`; seluruh permukaan operator/investor/kasir
   menolaknya dengan `302` ke beranda perannya, **nol tulisan**.

### 1.3 Definisi Selesai (*Definition of Done*)

- `bash tests/run_qc.sh` **exit 0**; tidak ada `storage/logs/php-*.log`.
- `tests/qc_fase_10.php` terdaftar di `tests/run_qc.sh` dan lulus (grup A–J).
- Angka protokol Fase 3–9 **tidak bergeser** satu rupiah pun ketika tidak ada mitra lapangan
  aktif (dibuktikan diferensial, § 8.4).
- `docs/` diperbarui **lebih dulu** (ERD, Roadmap+QC, PRD, SAD, UI/UX), lalu ADDENDUM di
  `plan/Summary_Fase_10_Mitra_Lapangan_AurumFlow.md`.
- `AGENTS.md` memuat kontrak baru (§ 6.8) — termasuk urutan penguncian global yang diperluas
  dan daftar terlarang portal petugas.
- `php database/migrate.php` menjalankan **M4** dua kali tanpa efek samping.
- `git status` bersih (tanpa `tests/.tmp/`, `storage/throttle/`, `*.log`).

### 1.4 Di Luar Ruang Lingkup Fase 10

- Potongan berbasis berat gram / jumlah kedatangan petani (tetap berbasis margin rupiah).
- Cicilan/pinjaman petugas, denda, atau pemotongan silang ke kasbon petani.
- Persetujuan berjenjang (dual control) untuk bonus atau pencairan.
- Laporan/ekspor PDF potongan petugas; rekap bulanan anggaran OPEX (item O-9.1 tetap terbuka).
- Notifikasi otomatis (WhatsApp) saldo hak; reset PIN mandiri petugas.
- Kolom agregat potongan pada `daily_settlements` (ditolak demi nol ALTER — lihat O-10.4).
- Perubahan `AllocationEngine` (tidak perlu; § 6.2) dan `ExpenseService` (tidak perlu; § 6.3).

---

## 2. Keputusan & Asumsi Terkunci

### 2.1 Keputusan pengguna (`dec-2b529bcb6a90f1db`)

| # | Pertanyaan | Keputusan | Konsekuensi yang diterima sadar |
|---|---|---|---|
| **D-1** | Kapan voucher OPEX bonus lahir? | **Akrual tanpa voucher**: bonus hanya kredit `officer_ledger_entries`; `SettlementService` membaca akrual bonus yang **belum terserap** dari buku petugas | Menyimpang dari arahan literal "MUST generate `operational_expenses` voucher" — **intinya tetap dipenuhi** (bonus mengurangi laba pada tutup buku berikutnya, nominal dari peladen, sekali saja), tetapi bonus **tidak** tampil di daftar voucher OPEX. Konsekuensi: laporan OPEX mitra lapangan dibaca dari buku petugas, bukan dari `operational_expenses`. **Nol ALTER.** |
| **D-2** | Batas potongan | **`cap = max(0, laba kotor)`**; hari minus → potongan `0` | Kombinasi `Σbeban + Σbonus + Σpotongan` boleh melampaui laba kotor → `net < 0` → `carryover_deficit` bertambah. Petugas senior terhadap pemulihan defisit. Mitigasi: `Logger::warning` + badge peringatan di Layar C (§ 4.6). |
| **D-3** | Kewenangan pencairan | **Operator saja**, dari **Kas Meja Bakar atau Brankas Utama**, pemilih pos berupa token daftar putih | Allow-list kasir **tidak berubah** (tetap dua controller: `TellerController`, `KasbonController`) → assertion Fase 7/8 tetap utuh. |

**Alternatif yang ditolak (dan alasannya).**

| Alternatif | Alasan ditolak |
|---|---|
| Bonus = voucher akrual (`operational_expenses.mutation_id` NULL-able) | Menuntut ALTER pada tabel Fase 9 + amandemen kontrak "satu voucher = satu baris kas"; keuntungannya (visibilitas OPEX) dinilai tidak sepadan oleh pemilik sistem (`dec-2b529bcb6a90f1db`). |
| Bonus = voucher + mutasi kas **saat pencairan** (kas basis) | Pencairan campuran (potongan + bonus) menuntut pemisahan porsi per kredit → dua mekanisme baru dan risiko deduksi ganda; laba juga bergeser ke sesi yang salah. |
| Potongan ditulis sebagai voucher OPEX (nominal = hasil hitung) | **Deduksi ganda**: potongan sudah mengurangi `net`; voucher kedua memotong laba dua kali dan menurunkan pool mitra tanpa sebab (§ 5.5 kontradiksi). |
| Potongan "di bawah garis" hanya membebani `operator_share_80` | Melanggar PRD § 3.3/§ 3.4: beban lapangan adalah pengurang laba kotor sebelum dividen, bukan beban pengelola saja (putusan yang sama dengan Opsi D pada audit OPEX). |
| `ROLE_OFFICER` tanpa akun `users` (petugas sebagai baris `officers` saja) | Portal blind menuntut login, throttling, dan audit jejak — semuanya sudah dimiliki `users` + `PinLoginService`. |

### 2.2 Asumsi & keputusan arsitektural

| Kode | Keputusan | Alasan |
|---|---|---|
| **A-1** | Petugas = baris `users` berperan `officer` (nilai ENUM **ditambahkan di ujung**) + satu baris `officers` (1:1) | Mengikuti pola Fase 5/7: identitas login `phone_number`, kredensial PIN ber-pepper, `users.is_active` sebagai satu-satunya saklar aktif/nonaktif |
| **A-2** | `cut_pct` disimpan **per petugas** (`officers.cut_pct`), bukan di `Constants` | Parameter bisnis tidak boleh hidup di kode (doktrin `Constants.php`) |
| **A-3** | Batas atas `Σ cut_pct` adalah kunci `system_settings.officer_cut_total_cap_pct` (default `100.00`) | Sama alasannya: parameter bisnis hidup di `system_settings` |
| **A-4** | Basis potongan = `max(0, laba kotor)`; **tanpa** memotong beban lebih dulu | Keputusan D-2; juga menjaga potongan tetap "persentase margin", bukan persentase sisa laba |
| **A-5** | Pembulatan potongan memakai gaya yang sama dengan fee talangan: `floor((basis_sen × pct_perseratus + 5000) / 10000)` — setengah sen ke atas (half away from zero, non-negatif) | Satu gaya aritmetika di seluruh jalur uang; `BridgeLoanService` sudah memakainya |
| **A-6** | Bila `Σ potongan` melampaui `cap`, potongan **diskalakan** dengan **metode sisa terbesar** lewat `AllocationEngine::distribute()` — bukan implementasi kedua | Satu pemilik metode sisa terbesar (sudah dipakai `AllocationEngine`) |
| **A-7** | Bonus = entri buku `entry_type='bonus'`, tanpa mutasi kas, tanpa voucher | Keputusan D-1 |
| **A-8** | Penyerapan bonus (pengurangan laba) **menandai** entri dengan `settlement_id` sesi itu, di dalam transaksi tutup buku, setelah dikunci `FOR UPDATE` | Meniru pola penyerapan voucher Fase 9: satu beban tidak pernah diserap dua kali |
| **A-9** | Pencairan **tidak** menyentuh laba sama sekali (murni pertukaran aset ↔ kewajiban) | Kewajiban sudah dibebankan saat akrual; mutasi kas bukan beban |
| **A-10** | `officer_ledger_entries.settlement_id` memakai `ON DELETE RESTRICT` (bukan `SET NULL`) | `SET NULL` akan **mengaktifkan ulang** bonus yang sudah terserap → deduksi ganda pada tutup buku berikutnya. Konsekuensi QC: entri buku petugas dibersihkan sebelum `daily_settlements` |
| **A-11** | Portal petugas **tidak merender** `cut_pct` maupun `base_amount` | `potongan = pct × laba_kotor` dapat dibalik menjadi laba kotor; pelarangan ini menutup jalur hitung-balik dari layar (§ 8.2 grup H) |
| **A-12** | `ExpenseService` **tidak berubah** | Voucher OPEX tetap untuk pihak non-petugas (RT, keamanan, konsumsi). Uang petugas bukan beban saat dibayar — ia pelunasan kewajiban |

### 2.3 Dua kanal pemotongan — matriks anti deduksi ganda

| Kanal | Pemicu | Nominal dari | Mengurangi laba? | Baris kas? | Tabel bukti | Dibayar kapan |
|---|---|---|---|---|---|---|
| **Potongan % (cut)** | tutup buku | peladen: `pct × max(0, laba kotor)` | **ya**, sekali, saat akrual | **tidak** (uang terparkir di laci) | `officer_ledger_entries(entry_type='cut')` + `officers.balance_payable` | saat pencairan |
| **Bonus manual (bonus)** | tombol operator | operator (nominal), divalidasi peladen | **ya**, sekali, pada tutup buku **berikutnya** (`settlement_id` masih NULL) | **tidak** | `officer_ledger_entries(entry_type='bonus')` | saat pencairan |
| **Pencairan (disbursement)** | tombol operator | operator (nominal ≤ saldo hak) | **tidak pernah** | **ya**, satu baris `out` | `officer_ledger_entries(entry_type='disbursement', mutation_id)` | — (inilah pembayarannya) |
| **Voucher OPEX (Fase 9)** | tombol kasir/operator | operator, kas keluar **sekarang** | ya, pada tutup buku berikutnya | ya | `operational_expenses` | — (tunai langsung) |

**Aturan tunggal yang mencegah semua deduksi ganda:** *setiap rupiah yang menjadi hak petugas
mengurangi laba tepat satu kali* — potongan pada **akrual**, bonus pada **penyerapan akrual**;
pencairan tidak pernah menyentuh laba. Sebaliknya, *setiap rupiah tunai yang keluar dari pos
kas tepat satu baris mutasi* — voucher OPEX pada saat bayar, pencairan hak pada saat cair.

---

## 3. Skema Basis Data

### 3.1 Ringkasan perubahan skema

| # | Objek | Jenis | Catatan |
|---|---|---|---|
| 15 | `officers` | **tabel baru** | akun buku hak: `user_id` 1:1, `cut_pct`, `balance_payable` |
| 16 | `officer_ledger_entries` | **tabel baru** | riwayat hak: `cut`, `bonus`, `disbursement` |
| — | `users.role` | **satu nilai ENUM baru di ujung** | `ENUM('operator','investor','cashier','officer')` |
| — | `system_settings.officer_cut_total_cap_pct` | baris seed baru | default `100.00` |
| — | tabel lain (1–14) | **tidak disentuh** | **nol ALTER**; dijaga assertion `A.*` |

### 3.2 Tabel ke-15 — `officers` (akun buku hak mitra lapangan)

| Kolom | Tipe | Constraint | Alasan |
|---|---|---|---|
| `id` | `CHAR(36)` | PK | UUID v4 (`Security::uuidV4()`) — anti-enumerasi |
| `user_id` | `CHAR(36)` | `NOT NULL`, `UNIQUE (uq_officer_user)`, FK → `users.id` `ON DELETE RESTRICT ON UPDATE CASCADE` | 1:1 dengan akun login. RESTRICT: petugas yang punya riwayat buku tidak boleh hilang (pola `miner_loans.miner_id`) |
| `cut_pct` | `DECIMAL(5,2)` | `NOT NULL DEFAULT 0.00` | Persentase potongan dari laba kotor; 0 = potongan dinonaktifkan tanpa kehilangan baris |
| `balance_payable` | `DECIMAL(14,2)` | `NOT NULL DEFAULT 0.00` | **Saldo Hak** berjalan; invariant `Σ kredit − Σ debit` (§ 4.7) |
| `created_at`, `updated_at` | `TIMESTAMP` | default / `ON UPDATE CURRENT_TIMESTAMP` | pola seluruh tabel |
| indeks | — | `KEY idx_officer_pct (cut_pct)` | daftar petugas berpotongan > 0 untuk pratinjau & akrual |

Catatan: **tidak ada kolom `is_active`** — saklar aktif/nonaktif milik `users.is_active`
(satu sumber kebenaran, pola Fase 7). Petugas nonaktif **berhenti berakrual** tetapi
`balance_payable` tetap dapat dicairkan.

### 3.3 Tabel ke-16 — `officer_ledger_entries` (buku hak)

| Kolom | Tipe | Constraint | Alasan |
|---|---|---|---|
| `id` | `CHAR(36)` | PK | UUID v4 |
| `officer_id` | `CHAR(36)` | `NOT NULL`, FK → `officers.id` `ON DELETE RESTRICT` | riwayat wajib hidup selama bukunya ada |
| `entry_type` | `ENUM('cut','bonus','disbursement')` | `NOT NULL` | **append-only** (nilai baru hanya di ujung). Arah: `cut`/`bonus` = kredit, `disbursement` = debit |
| `amount` | `DECIMAL(14,2)` | `NOT NULL` | **selalu positif**; arah ditentukan `entry_type` (pola `miner_loan_ledger`) |
| `balance_after` | `DECIMAL(14,2)` | `NOT NULL` | saldo hak **setelah** entri — jejak yang dapat diaudit tanpa menjumlah ulang |
| `cut_pct` | `DECIMAL(5,2)` | `NULL` | terisi **hanya** untuk `cut`; snapshot tarif saat akrual (tarif boleh berubah kemudian) |
| `base_amount` | `DECIMAL(14,2)` | `NULL` | terisi **hanya** untuk `cut`: laba kotor yang menjadi basis. **Tidak pernah dirender** di portal petugas (A-11) |
| `settlement_id` | `CHAR(36)` | `NULL`, FK → `daily_settlements.id` `ON DELETE RESTRICT ON UPDATE CASCADE` | `cut` → sesi akrualnya (**selalu terisi**); `bonus` → sesi yang **menyerapnya** (`NULL` = belum diserap); `disbursement` → selalu `NULL` |
| `register_id` | `CHAR(36)` | `NULL`, FK → `cash_registers.id` `ON DELETE RESTRICT` | terisi **hanya** untuk `disbursement`: pos kas pembayar |
| `mutation_id` | `CHAR(36)` | `NULL`, `UNIQUE (uq_entry_mutation)`, FK → `cash_mutations.id` `ON DELETE RESTRICT` | terisi **hanya** untuk `disbursement`: satu baris kas = satu baris buku (pola `miner_loan_ledger`, diperketat UNIQUE karena pencairan tidak pernah memecah satu mutasi) |
| `recorded_by` | `CHAR(36)` | `NULL`, FK → `users.id` `ON DELETE SET NULL` | pencatat (`users.id` sesi, bukan kiriman form) |
| `notes` | `TEXT` | `NULL` | catatan operator (alasan bonus / keterangan pencairan) |
| `created_at` | `TIMESTAMP` | default `CURRENT_TIMESTAMP` | presisi detik → **jangan** mengandalkan "baris terbaru" (item O-1) |
| indeks | — | `KEY idx_entry_officer (officer_id, created_at)`, `KEY idx_entry_open (entry_type, settlement_id)`, `KEY idx_entry_settlement (settlement_id, entry_type)`, `KEY idx_entry_register (register_id)` | `idx_entry_open` melayani pembacaan akrual bonus yang belum terserap (jalur terpanas tutup buku) |

### 3.4 Kursi keputusan kunci asing

| FK | Aturan | Alasan (dan konsekuensi QC) |
|---|---|---|
| `officers.user_id` | `RESTRICT` | akun petugas berriwayat tidak boleh dihapus; QC memakai `DELETE` bertahap (`officer_ledger_entries` → `officers` → `users`) |
| `officer_ledger_entries.officer_id` | `RESTRICT` | pola `fk_ledger_loan` |
| `officer_ledger_entries.settlement_id` | **`RESTRICT`** (beda dari `operational_expenses.settlement_id` yang `SET NULL`) | lihat **A-10**: `SET NULL` mengaktifkan ulang bonus terserap → deduksi ganda. Konsekuensi: `QcFixture` **wajib** membersihkan entri buku petugas **sebelum** `daily_settlements` |
| `officer_ledger_entries.mutation_id` | `RESTRICT` + `UNIQUE` | satu baris kas tidak boleh dihapus tanpa jejak hak; dan satu mutasi pencairan tidak boleh dipakai dua entri |
| `officer_ledger_entries.register_id` | `RESTRICT` | pola `fk_expense_register` |
| `officer_ledger_entries.recorded_by` | `SET NULL` | akun staf boleh dihapus, riwayat hak wajib terbaca |

**Invariant yang ditegakkan service + QC (tidak bisa dinyatakan sebagai constraint DDL):**

- `entry_type='cut'` ⇒ `settlement_id`, `cut_pct`, `base_amount` **NOT NULL**; `register_id`,
  `mutation_id` **NULL**.
- `entry_type='bonus'` ⇒ `cut_pct`, `base_amount`, `register_id`, `mutation_id` **NULL**
  (`settlement_id` NULL sampai diserap).
- `entry_type='disbursement'` ⇒ `register_id` **dan** `mutation_id` **NOT NULL**;
  `settlement_id`, `cut_pct`, `base_amount` **NULL**.
- Satu `cut` per petugas per sesi tutup buku (tidak dijadikan `UNIQUE (settlement_id, officer_id)`
  karena baris `bonus` sah berbagi pasangan yang sama setelah diserap) — ditegakkan oleh satu
  `INSERT` per roster di dalam transaksi + assertion `qc_fase_10` (hitung baris).

### 3.5 `users.role` — nilai keempat (append-only)

- Kanonik di kode: `Constants::ROLE_OFFICER = 'officer'`;
  `User::ROLE_ENUM_VALUES` menjadi `['operator','investor','cashier','officer']`.
- `Constants::ROLE_LABELS` mendapat `'officer' => 'Mitra Lapangan (Petugas)'`
  (**satu pemilik label**; nilai enum mentah tidak pernah dicetak).
- `User::needsRoleEnumExtension()` **digeneralisasi**: bernilai benar bila salah satu nilai
  `ROLE_ENUM_VALUES` belum ada di `COLUMN_TYPE`; `ensureRoleEnum()` menulis ulang ENUM dengan
  urutan **persis** `ROLE_ENUM_VALUES` (nilai tetap literal di kode, tidak pernah dari input).
  Ini menjaga M1 tetap idempoten dan aman diulang pada instalasi lapangan.
- Migrasi lapangan **M4** menjalankan ulang `User::ensureRoleEnum()` → melaporkan
  "diterapkan"/"sudah diterapkan" dengan empat nilai.

### 3.6 Kunci `system_settings` baru

| Kunci | Nilai awal | Deskripsi | Pemilik validasi |
|---|---|---|---|
| `officer_cut_total_cap_pct` | `100.00` | Batas atas `Σ cut_pct` seluruh petugas aktif | `OfficerService` saat simpan tarif; `quote()` saat akrual (fail-closed `422` bila dilanggar) |

Ditambahkan lewat `SystemSetting::ensure()` di migrasi **M4** (idempoten; nilai operator yang
sudah disesuaikan tidak pernah ditimpa).

### 3.7 Migrasi lapangan **M4** (`php database/migrate.php`)

| Langkah | Isi | Sifat |
|---|---|---|
| **M4a** | `User::ensureRoleEnum()` — ENUM empat nilai | idempoten, terjaga `INFORMATION_SCHEMA` |
| **M4b** | `Officer::ensureSchema()` → dua `CREATE TABLE IF NOT EXISTS` (DDL hidup di `app/Models/Officer.php`, **bukan** di skrip migrasi) | idempoten, **nol ALTER** |
| **M4c** | `SystemSetting::ensure('officer_cut_total_cap_pct', '100.00', …)` | idempoten (`WHERE NOT EXISTS`) |

Gate `INSTALL_TOKEN` tetap sama; skrip tidak menyentuh `storage/installed.lock`, tidak menulis
ulang baris data, dan tetap boleh dihapus dari hosting setelah dipakai.

### 3.8 Yang **tidak** berubah (dijaga assertion)

- Tabel 1–14: nol kolom baru, nol ALTER (khususnya `daily_settlements`, `operational_expenses`,
  `capital_wallets`, `cash_registers`, `cash_mutations`).
- `daily_settlements.daily_coordination_fee` **tetap** berisi total voucher OPEX non-konsumsi
  saja — potongan & bonus petugas **tidak** masuk kolom itu (assertion: nilainya tidak berubah
  ketika petugas aktif). Total potongan tersedia dari `Σ officer_ledger_entries` per sesi.
- `net_settled_profit`, `operator_share_80`, `investor_pool_20` tetap **satu-satunya** angka
  bagi hasil; komposisinya kini sudah dipotong hak petugas (§ 4.2).
- `AllocationEngine` **tidak disentuh**: `capital_utilized` tetap `Σ net_cash_paid` baris
  `holding`; hak petugas bukan modal belanja.
- `ExpenseService` **tidak disentuh**: voucher OPEX tetap untuk pihak non-petugas.
- `profit_distributions` tidak berubah bentuk; nilainya mengecil karena `investor_pool_20` mengecil.
- Skema **nol `FLOAT`/`DOUBLE`** (QC 4.7 tetap lulus).

### 3.9 Rekonsiliasi `docs/` — **dokumen lebih dulu** (wajib, `AGENTS.md`)

| Dokumen | Bagian yang ditambah/diubah |
|---|---|
| `docs/AurumFlow Entity Relationship Diagram.md` | § 1 diagram + § 2 kardinalitas (`users` 1—1 `officers` 1—N `officer_ledger_entries`) + § 4 tabel **15** & **16** (DDL + komentar aturan) + `users.role` empat nilai |
| `docs/AurumFlow Roadmap and QC Documents.md` | **FASE 10** (checklist + Protokol QC Manual 10) + baris Sprint 10 pada matriks estimasi |
| `docs/AurumFlow Product Requirement Document v3.0.md` | § 3.6 baru: "Mitra Lapangan — Buku Hak & Persentase Margin"; amendemen § 3.3 (voucher OPEX ≠ hak petugas) dan § 4.2 (urutan potongan) |
| `docs/AurumFlow System Architecture Document.md` | komponen baru (2 model, 2 service, 3 controller, 3 view, 1 layout), urutan penguncian global, struktur direktori |
| `docs/AurumFlow UI UX Documents.md` | **Layar G** (Kelola Mitra Lapangan), **Layar H** (Dasbor Blind Petugas), pembaruan Layar C (kartu potongan read-only) |
| `README.md` | peta route, migrasi M4, baseline QC baru, alur fase 10 |
| `AGENTS.md` | § 6.8 kontrak baru (urutan kunci, dua kanal potongan, daftar terlarang portal petugas, `CACHE_NAME` v5, `ROLE_OFFICER`) |

Bila kode kelak memerlukan penyimpangan, aturannya tetap: `docs/` dulu → ADDENDUM di
`plan/Summary_Fase_10_Mitra_Lapangan_AurumFlow.md` → baru kode.

---

## 4. Aturan Bisnis & Aritmetika

### 4.1 Siklus hidup saldo hak

| Peristiwa | `entry_type` | `balance_payable` | Uang fisik |
|---|---|---|---|
| Tutup buku: potongan % per petugas aktif | `cut` | **+ potongan** | tetap di laci (kas sesi ini memuat potongan) |
| Operator menekan "Beri Bonus" | `bonus` | **+ bonus** | tetap di laci |
| Operator mencairkan (parsial/penuh) | `disbursement` | **− nominal** | keluar dari pos kas terpilih (satu mutasi `out`) |
| Petugas dinonaktifkan | — | tidak berubah (tetap dapat dicairkan) | — |
| `cut_pct` diubah | — | tidak berubah; tarif baru berlaku pada akrual berikutnya (snapshot lama tersimpan di `cut_pct`) | — |

Aturan tambahan:

- **Potongan hanya untuk petugas aktif** (`users.is_active = 1`) dan `cut_pct > 0`.
  Petugas dengan potongan hasil hitung `0` **tidak** menghasilkan baris (menghindari derau).
- **Pencairan** boleh parsial, berulang, dari pos berbeda, kapan saja; tidak ada masa tunggu.
- **Nominal dari klien tidak pernah dipercaya sebagai hasil hitung**: form pencairan hanya
  mengirim `officer_id`, `nominal`, `pos_kas` (token `kas_meja`/`brankas`), dan `catatan`;
  saldo, `balance_after`, dan nilai kanonik dihitung peladen.

### 4.2 Rumus potongan & laba (sen integer, tanpa float)

Notasi: `R` pendapatan toko, `H` modal belanja hulu, `O` Σ voucher OPEX belum terserap,
`B` Σ akrual bonus belum terserap, `C` Σ potongan petugas, `Δ` defisit berjalan, `pct_i`
tarif petugas ke-i dalam **perseratus persen** (2,50 % → `250`).

```
G   = R − H                                   (laba kotor; boleh negatif)
basis_i = max(0, G)                           (A-4: hari minus → 0)
cut_i   = floor((basis_i × pct_i + 5000) / 10000)      (A-5, setengah sen ke atas)
C0      = Σ cut_i
cap     = max(0, G)                           (D-2)
C       = C0 > cap ? skalakan(C0 → cap, bobot = cut_i) : C0      (A-6, sisa terbesar)
A       = G − O − B − C                       (sebelum pemulihan defisit)
Rec     = min(Δ, max(0, A))
net     = A − Rec
Δ'      = Δ − Rec + max(0, −net)
operator80 = net > 0 ? floor((net × 80 + 50) / 100) : 0        (pola Fase 3, tidak berubah)
pool20     = net > 0 ? net − operator80 : 0
total_cash = R                                (aturan Fase 9, tidak berubah)
saran_brankas = max(0, net)
saran_kas     = total_cash − saran_brankas
```

Properti yang dibuktikan QC (`qc_fase_10` grup C):

1. `operator80 + pool20 = net` untuk setiap `net ≥ 0`; `0 + 0` untuk `net ≤ 0`.
2. `Σ cut_i = C ≤ max(0, G)` selalu; skala hanya muncul bila `Σ pct > 100` (ditolak di
   input, jadi hanya dapat terjadi pada data rusak → fail-closed).
3. `operator80`, `pool20` **tidak** pernah naik ketika petugas ditambahkan (monoton turun).
4. `kas + brankas = R` tetap berlaku untuk setiap kombinasi `O, B, C, Δ`.
5. Tanpa petugas aktif & tanpa akrual bonus (`C = 0`, `B = 0`): seluruh keluaran `quote()`
   **identik** dengan Fase 9 (diferensial pada nilai desimal kanonik).

### 4.3 Bonus manual sebagai akrual (keputusan D-1) — mekanisme

1. Operator menekan **"Beri Bonus"** di Layar G: `officer_id`, `nominal`, `catatan` (alasan).
2. Satu `Database::transaction()`: kunci baris `officers` (`FOR UPDATE`) → baca `balance_payable`
   → tulis `officer_ledger_entries(entry_type='bonus', settlement_id=NULL, amount, balance_after)`
   → `UPDATE officers SET balance_payable = balance_payable + amount`.
   **Tidak ada** `cash_mutations`, **tidak ada** `operational_expenses`, **tidak ada** perubahan
   `cash_registers` (assertion diferensial: ketiga himpunan tidak berubah sepeser pun).
3. Pada tutup buku berikutnya `SettlementService` mengunci entri `entry_type='bonus' AND
   settlement_id IS NULL` (FIFO `created_at, id`), menjumlahkannya menjadi `B`, memasukkannya
   ke rumus (§ 4.2), lalu **menandai** entri itu `settlement_id = sesi` (jumlah baris yang
   berubah wajib sama dengan yang dikunci; bila tidak → seluruh transaksi digulung).
4. Sesudah ditandai, entri **tidak pernah** diserap lagi → bonus tidak mungkin mengurangi laba
   dua kali, termasuk bila operator mencairkannya berulang kali.

**Mengapa ini tetap memenuhi niat arahan PO** (walau tanpa voucher): bonus (a) menaikkan buku
hak, (b) mengurangi laba **pada tutup buku berikutnya**, (c) nominal & basisnya dari peladen,
(d) tidak dapat difiktifkan lewat form rekonsiliasi karena bukan `coord_fee`. Yang hilang
hanyalah visibilitasnya di daftar voucher `operational_expenses`; penggantinya adalah kolom
`created_at`/`recorded_by`/`notes` pada buku petugas + kartu "Potongan & Bonus Petugas" di
Layar C. Konsekuensi ini dicatat sebagai **O-10.1** bila kelak pemilik sistem ingin
memindahkannya ke jalur voucher (menuntut ALTER `operational_expenses`).

### 4.4 Pencairan (keputusan D-3)

| Langkah | Isi | Gagal → |
|---|---|---|
| 0 | Validasi murni: `officer_id` UUID sah, `nominal > 0`, `nominal ≤ 999.999.999.999,99`, `pos_kas ∈ {kas_meja, brankas}`, `catatan` ≤ 255 | `422`, nol tulisan |
| 1 | `requireRole(ROLE_OPERATOR)` (guard-first, sebelum CSRF kedua) | `302` ke `/dashboard` |
| 2 | CSRF + `_once` (`Security::consumeFormToken`), duplikat → flash "ganda diabaikan" + PRG | `419` / `302` |
| 3 | `Database::transaction()`: kunci pos kas terpilih (`lockByNameForUpdate`) → kunci baris `officers` → cek `nominal ≤ balance_payable` **dan** `nominal ≤ saldo pos` | `422`, nol tulisan |
| 4 | `cash_mutations` `out` (label `Hak petugas: <nama>`, ≤ 100 karakter) | — |
| 5 | `cash_registers.current_balance − nominal` (relatif, string desimal kanonik) | — |
| 6 | `officer_ledger_entries(disbursement, register_id, mutation_id, balance_after)` | — |
| 7 | `officers.balance_payable − nominal` | — |
| 8 | `Logger::info` di luar transaksi + PRG (token baru terbit di halaman berikutnya) | — |

Urutan 4 → 5 → 6 wajib: `mutation_id` memakai FK yang diperiksa InnoDB seketika (pola
`ExpenseService::pay()` dan `KasbonService::disburse()`).

### 4.5 Perubahan di dalam `SettlementService`

**`batchContext()` (pratinjau tanpa kunci)** menambah tiga kunci konteks:

| Kunci | Isi |
|---|---|
| `officers` | roster petugas aktif ber-`cut_pct > 0` (id, nama, `cut_pct`) — untuk pratinjau Alpine |
| `officer_bonus_cents` | Σ akrual bonus yang belum terserap |
| `open_officer_bonuses` | daftar akrual bonus (tanggal, nama, nominal) untuk kartu read-only di Layar C |

**`quote()`** menambah keluaran: `officer_count`, `officer_base`(+`_cents`), `officer_cut`
(+`_cents`), `officer_bonus`(+`_cents`), `officer_total`(+`_cents`), `officer_cap`(+`_cents`),
`officer_cut_scaled` (bool), dan `officer_allocation` (baris per petugas: id, nama, `cut_pct`,
`amount_cents`). Seluruh kunci lama **tetap ada** dengan nama yang sama (kompatibilitas
`qc_fase_3` grup A/B).

**`settle()`** — langkah bertambah, urutan penguncian global menjadi:

```
1.  capital_wallets            (lockAllForUpdate, id menaik)
2.  cash_registers ×2          (lockByNamesForUpdate, urutan tetap)
3.  bridge_loans aktif         (FIFO)
4.  hulu_transactions holding  (batch lock)
5.  operational_expenses       (FIFO, settlement_id IS NULL)
6.  officers                   (FOR UPDATE seluruh baris, id menaik)      ← BARU
    officer_ledger_entries bonus belum terserap (FOR UPDATE, FIFO)        ← BARU
7.  system_settings            (getForUpdate active_carryover_deficit)
```

Tulisan (kini **sembilan kelompok** dalam satu transaksi):

```
(a) daily_settlements                       (daily_coordination_fee, consumable_opex TIDAK berubah isinya)
(b) operational_expenses.settlement_id      (penyerapan voucher — Fase 9)
(c) hulu_transactions status='settled'      (settleBatch)
(d) cash_registers ×2 + (e) cash_mutations ×2
(f) system_settings.active_carryover_deficit
(g) profit_distributions + capital_wallets.balance_profit     (AllocationEngine::apply)
(h) officer_ledger_entries (cut, satu baris per petugas) + officers.balance_payable  ← BARU
(i) officer_ledger_entries.settlement_id (penyerapan bonus)                          ← BARU
```

Penjagaan tambahan (fail-closed, semua `422` tanpa tulisan):

- `Σ cut_pct` petugas aktif > `officer_cut_total_cap_pct` → tolak dengan pesan konfigurasi.
- `O + B + C > R` → tolak ("beban + hak petugas melebihi pendapatan toko").
- `B > 0` tetapi baris akrual tidak dapat ditandai lengkap → gulung seluruh transaksi.

### 4.6 Penjagaan & peringatan

| Situasi | Perlakuan |
|---|---|
| Hari minus (`G ≤ 0`) | potongan `0`; tidak ada baris `cut`; `net` negatif → `carryover_deficit` (mekanisme Fase 3 tidak berubah) |
| `O + B + C > G` (D-2) | diizinkan; `net` boleh negatif. `Logger::warning` `'Hak petugas + beban melebihi laba kotor'` + badge peringatan di Layar C |
| Saldo pos < nominal pencairan | `422` "Saldo <pos> (…) tidak cukup untuk pencairan hak (…)" — nol tulisan |
| `nominal > balance_payable` | `422` "Nominal melebihi saldo hak petugas (…)" — nol tulisan |
| Petugas tanpa `users.is_active` | tidak berakrual; tombol bonus & pencairan operator tetap tersedia (kewajiban lama tetap dibayar) |
| Bonus di hari tanpa emas `holding` | tercatat sebagai akrual, diserap tutup buku berikutnya (pola F-1 audit) |
| `APP_PIN_PEPPER` kosong | pembuatan akun petugas **gagal dengan pesan** (fail-closed), nol baris |

### 4.7 Invariant yang tidak boleh runtuh

1. `officers.balance_payable = Σ(cut + bonus) − Σ(disbursement)` untuk setiap petugas.
2. `balance_after` entri ke-n = saldo setelah entri itu; rantai harus konsisten dari saldo awal 0.
3. `Σ amount` entri `disbursement` satu petugas = Σ `cash_mutations` `out` yang dirujuk
   `mutation_id`-nya (1:1, dijaga UNIQUE).
4. `Δ saldo pos kas = Σ mutasi pos itu` untuk setiap pos (termasuk pencairan).
5. `kas + brankas = R` per sesi tutup buku (aturan Fase 9, tidak berubah).
6. **`meja = baseline_meja + Rec + Σ balance_payable`** bila seluruh pencairan keluar dari
   Kas Meja Bakar, `net ≥ 0`, dan tidak ada talangan (bukti: § 5.3). Bila ada pencairan dari
   Brankas: `meja = baseline_meja + Rec + Σ balance_payable − Σ pencairan_dari_brankas`.
7. `Σ potongan yang dibebankan pada laba seumur hidup = Σ entri cut` (tidak ada potongan yang
   masuk lewat jalur lain) dan `Σ bonus yang dibebankan = Σ entri bonus yang settlement_id-nya
   terisi` (tidak ada bonus yang dibebankan dua kali).

---

## 5. Formula Proofs & Cash-Flow Invariants

### 5.1 Basis angka

Angka protokol yang sudah dibekukan (Protokol QC Manual 3/4/9) + satu petugas 2,5 %:

| Besaran | Nilai |
|---|---|
| Saldo awal `Kas Meja Bakar` / `Brankas Utama` | Rp 50.000.000 / Rp 0 |
| Modal belanja hulu (`Σ net_cash_paid` holding) | Rp 35.157.500 (**H**) |
| Berat dijual × harga jual | 18,050 gr × Rp 2.080.000 |
| Pendapatan toko (**R**) | Rp 37.544.000 |
| Voucher OPEX belum terserap (**O**) | Rp 200.000 |
| Bonus manual petugas, disuntik sebelum tutup buku (**B**) | Rp 150.000 |
| Petugas A, `cut_pct` = 2,50 % | **C** = 2,50 % × Rp 2.386.500 = **Rp 59.662,50** |
| Pencairan sesudah tutup buku, dari Kas Meja Bakar | Rp 100.000 |

### 5.2 Skenario lengkap — langkah demi langkah (buku vs fisik)

**(1) Tutup buku — perhitungan peladen**

| Langkah | Rumus | Nilai |
|---|---|---|
| Laba kotor | `G = R − H` | Rp 2.386.500,00 |
| Basis potongan | `max(0, G)` | Rp 2.386.500,00 |
| Potongan A | `floor((238.650.000 × 250 + 5.000)/10.000)` sen | Rp 59.662,50 (5.966.250 sen) |
| Cap | `max(0, G)` = Rp 2.386.500 → **tidak mengikat** | — |
| Sebelum defisit | `A = G − O − B − C` | Rp 1.976.837,50 |
| Pemulihan defisit | `Rec = min(0, …)` | Rp 0 |
| **Laba bersih** | `net = A` | **Rp 1.976.837,50** |
| Pengelola 80 % | `floor((197.683.750 × 80 + 50)/100)` sen | Rp 1.581.470,00 |
| Pool mitra 20 % | `net − operator80` | Rp 395.367,50 |
| Kas diterima | `total_cash = R` | Rp 37.544.000,00 |
| Saran brankas | `max(0, net)` | Rp 1.976.837,50 |
| Saran kas meja | `total_cash − brankas` | Rp 35.567.162,50 |

Verifikasi silang: `saran_kas = H + O + B + C + Rec` =
35.157.500 + 200.000 + 150.000 + 59.662,50 + 0 = **Rp 35.567.162,50** ✔

**(2) Pergerakan kas fisik & buku**

| Kejadian | Kas Meja (fisik = buku) | Brankas | Total |
|---|---|---|---|
| Saldo awal | 50.000.000,00 | 0,00 | 50.000.000,00 |
| Bayar hulu (siang) | − 35.157.500,00 → 14.842.500,00 | — | 14.842.500,00 |
| Bayar voucher OPEX | − 200.000,00 → 14.642.500,00 | — | 14.642.500,00 |
| Tutup buku: alokasi `kas` | + 35.567.162,50 → **50.209.662,50** | — | — |
| Tutup buku: alokasi `brankas` | — | + 1.976.837,50 → **1.976.837,50** | — |
| **Setelah tutup buku** | **50.209.662,50** | **1.976.837,50** | **52.186.500,00** |
| Pencairan Rp 100.000 (kas meja) | − 100.000,00 → **50.109.662,50** | 1.976.837,50 | **52.086.500,00** |

**(3) Uji identitas**

| Identitas | Perhitungan | Hasil |
|---|---|---|
| Uang dari toko terbagi habis | `kas + brankas = 35.567.162,50 + 1.976.837,50` | **= R = 37.544.000,00** ✔ |
| Laci memuat cadangan hak petugas (sebelum pencairan) | `50.209.662,50 − 50.000.000` | **= saldo hak 209.662,50** ✔ (= C + B) |
| Laci sesudah pencairan | `50.109.662,50 − 50.000.000` | **= saldo hak 109.662,50** ✔ (209.662,50 − 100.000) |
| Total buku = modal awal + laba ditahan + kewajiban petugas | `50.000.000 + 1.976.837,50 + 109.662,50` | **= 52.086.500,00** ✔ = total buku |
| Kewajiban tidak hilang & tidak berlebih | `Σ kredit (59.662,50 + 150.000) − Σ debit (100.000)` | **= 109.662,50** ✔ |

Kunci pembacaan: **`kas` sengaja memuat `O + B + C`** karena `total_cash = R` (aturan Fase 9).
Karena itu laci selalu berakhir memegang uang yang menjadi hak petugas, dan identitas
`meja = baseline + Rec + Σ balance_payable` berlaku setiap kali seluruh pencairan keluar dari laci.

### 5.3 Bukti umum (bukan sekadar contoh)

1. **Potongan tidak memengaruhi keseimbangan kas.** `kas + brankas = R` tidak bergantung pada
   `O, B, C`; ia hanya bergantung pada `R` dan saran `brankas = max(0, net)`. Mengubah besaran
   potongan hanya memindahkan porsi antara `brankas` (laba) dan `kas` (penggantian + cadangan
   hak) — totalnya tetap `R`. Karena itu potongan tidak mungkin membuat buku ≠ fisik.
2. **Pencairan bukan beban.** Satu pencairan menulis tepat dua efek: `cash_registers −x` dan
   `officers.balance_payable −x` (plus satu baris `cash_mutations` sebagai bukti kas). Tidak ada
   baris laba yang tersentuh, sehingga total buku turun `x` **dan** kewajiban turun `x` —
   kekayaan bersih tidak berubah. Itulah sebabnya mencairkan hak petugas tidak boleh dan tidak
   dapat menggeser `net`, `operator_share_80`, `investor_pool_20`, maupun dompet mitra.
3. **Bonus dibebankan sekali.** Entri `bonus` hanya masuk rumus selama `settlement_id IS NULL`.
   Setelah ditandai, ia tidak lagi muncul di `batchContext()` maupun `settle()`; kebenaran ini
   diuji diferensial (settle kedua tidak mengubah `net` karena bonus).

### 5.4 Bukti tidak ada deduksi ganda (tabel efek)

| Peristiwa | Efek laba | Efek kas | Efek kewajiban | Total buku |
|---|---|---|---|---|
| Potongan 2,5 % saat tutup buku | `net −59.662,50` | `kas +59.662,50` (tetap di laci) | `+59.662,50` | **tidak berubah** |
| Bonus Rp 150.000 disuntik | — (belum) | — | `+150.000` | **tidak berubah** |
| Bonus diserap tutup buku | `net −150.000` | `kas +150.000` | tetap `+150.000` | **tidak berubah** |
| Pencairan Rp 100.000 | — | pos `−100.000` | `−100.000` | **−100.000** (aset turun = kewajiban turun) |

### 5.5 Kontradiksi — angka bila aturan ini dilanggar

| Pelanggaran | `net` | Pengelola 80 % | Pool 20 % | Total buku | Kesimpulan |
|---|---|---|---|---|---|
| **Benar** (rencana ini) | 1.976.837,50 | 1.581.470,00 | 395.367,50 | 52.186.500,00 | seimbang, hak petugas 209.662,50 |
| Potongan **juga** ditulis sebagai voucher OPEX | 1.917.175,00 | 1.533.740,00 | 383.435,00 | 52.186.500,00 | **laba & pool terpotong dua kali** (pengelola −47.730,00; mitra −11.932,50) padahal kewajiban petugas tetap 209.662,50 → uang 59.662,50 di laci tidak lagi punya pemilik |
| Bonus **dipotong lagi** saat pencairan (voucher dibuat di jalur pencairan) | 1.826.837,50 | 1.461.470,00 | 365.367,50 | 52.186.500,00 | laba & pool terpotong dua kali sebesar bonus (pengelola −120.000,00; mitra −30.000,00) |
| Pencairan ditulis sebagai beban (`net −100.000`) | 1.876.837,50 | 1.501.470,00 | 375.367,50 | 52.086.500,00 | beban fiktif: hak petugas adalah kewajiban yang **sudah** dibebankan |
| Potongan juga mengurangi `total_cash` (`R − C`) | 1.976.837,50 | 1.581.470,00 | 395.367,50 | 52.126.837,50 | **buku < fisik** tepat sebesar potongan (kegagalan yang sama dengan audit OPEX § 5.5 skenario B) |

### 5.6 Kasus tepi yang wajib dibuktikan QC

| Kasus | Perilaku yang diharapkan |
|---|---|
| Hari minus (`G = −80.000`) | `cut = 0`; tidak ada baris `cut`; `net = −80.000`; `carryover` naik (mekanisme Fase 3) |
| `G = 0` tepat | `cut = 0`; `net = −(O + B)` → defisit; nol baris potongan |
| Cap mengikat (`Σ pct > 100` pada data rusak) | skala turun lewat sisa terbesar; `Σ cut = cap`; peringatan log; jumlah sen tidak hilang |
| Pembulatan setengah sen | basis `Rp 1.000.001,00` × 2,50 % → `Rp 25.000,03` (2.500.002,5 sen → 2.500.003 sen) |
| Petugas nonaktif | tidak berakrual; saldo tetap; pencairan & bonus tetap dapat dilakukan |
| `cut_pct = 0` | tidak ada baris; angka protokol Fase 3–9 tidak bergeser |
| Tanpa petugas & tanpa bonus | seluruh keluaran `quote()` identik dengan Fase 9 |
| `O + B + C > R` | `422` tanpa tulisan |
| Pencairan > saldo hak | `422` tanpa tulisan |
| Pencairan > saldo pos terpilih | `422` tanpa tulisan (pos lain tidak dipakai diam-diam) |
| Dua pencairan serentak dari pos berbeda | keduanya berhasil bila saldo cukup; `balance_after` tetap konsisten (uji serialisasi) |

---

## 6. Arsitektur & Peta Komponen

### 6.1 Model

| Berkas | Status | Isi |
|---|---|---|
| `app/Models/Officer.php` | **baru** | DDL tabel ke-15 & ke-16 (`ensureSchema()`, `exists()`), daftar putih kolom, `insert()`, `lockAllForUpdate()` (id menaik), `lockByIdForUpdate()`, `activeRoster()` (join `users`), `findByUserId()`, `updateCutPct()`, `adjustBalance()`, `listWithBalances()` |
| `app/Models/OfficerLedgerEntry.php` | **baru** | daftar putih kolom + `insert()` (validasi kolom wajib per `entry_type`), `lockUnabsorbedBonusesForUpdate()` (FIFO), `absorbBonuses(settlementId, ids)`, `unabsorbedBonuses()`, `forOfficer()` (halaman portal, limit), `sumByType()` |
| `app/Models/User.php` | berubah | `ROLE_ENUM_VALUES` (+`officer`), `needsRoleEnumExtension()` digeneralisasi, `ensureRoleEnum()` empat nilai |
| `app/Models/SystemSetting.php` | tak berubah | dipakai `ensure()` untuk kunci baru |
| model lain | tak berubah | — |

### 6.2 Service

| Berkas | Status | Tanggung jawab |
|---|---|---|
| `app/Services/OfficerService.php` | **baru** | Tata kelola operator + aritmetika hak: `create()` (akun `users` + baris `officers`, satu transaksi), `updateCutPct()`, `resetPin()`, `setStatus()`, `injectBonus()`, `disburse()`, `overview()` (view-model Layar G), `previewCuts(int $grossCents)` (pratinjau Layar C), `accrueForSettlement(PDO, string $settlementId, array $roster, int $grossCents, int $capCents)`, `absorbBonuses(PDO, string $settlementId, array $rows)`, `mutationLabel()` |
| `app/Services/OfficerPortalService.php` | **baru** | **Baca-saja blind**: `summary(string $userId)` (nama, saldo hak, ringkasan bulan berjalan) + `history(string $userId, int $limit)`; memformat lewat `Formatter`. **Dilarang** menyebut istilah terlarang (§ 8.2 grup H) |
| `app/Services/SettlementService.php` | berubah | `batchContext()` (roster + bonus), `quote()` (potongan/bonus/cap), `settle()` (dua kelompok tulisan baru + dua kunci baru + penjagaan) |
| `app/Services/PinLoginService.php` | berubah kecil | `AUDIENCE_LABELS['officer'] => 'petugas lapangan'` (aturan pintu tidak berubah) |
| `app/Services/AllocationEngine.php` | **tak berubah** | `capital_utilized` tetap modal belanja; `distribute()` **dipakai ulang** oleh `OfficerService` untuk skala cap (satu pemilik sisa terbesar) |
| `app/Services/ExpenseService.php` | **tak berubah** | voucher OPEX tetap untuk pihak non-petugas |
| `app/Services/BridgeLoanService.php` | **tak berubah** | — |

### 6.3 Controller

| Berkas | Status | Method & penjaga |
|---|---|---|
| `app/Controllers/OfficerController.php` | **baru** (Layar G) | `index` (GET, `requireRole(ROLE_OPERATOR)`), `store` (buat akun+tarif), `updateCut` (`POST /operator/petugas/potongan`), `resetPin`, `setStatus`, `bonus`, `disburse` — **semua** method publik: `requireRole(ROLE_OPERATOR)` sebagai pernyataan **pertama**, lalu CSRF kedua, lalu `_once`, lalu PRG |
| `app/Controllers/OfficerAuthController.php` | **baru** | `showLogin` (`requireGuest`), `login` (`PinLoginService::attempt($cred, ROLE_OFFICER, ip)` → `startUserSession` → `roleLandingPath(ROLE_OFFICER)`) |
| `app/Controllers/OfficerPortalController.php` | **baru** (Layar H) | `index` (`/officer/dashboard`), `history` (`/officer/riwayat`) — keduanya `requireRole(ROLE_OFFICER)` guard-first; **tanpa** POST sama sekali |
| `app/Controllers/SettlementController.php` | berubah | meneruskan roster + akrual bonus + baris potongan ke view; angka bagi hasil di flash menyebut potongan petugas |
| `app/Controllers/AuthController.php` | berubah | pintu kata sandi **menolak eksplisit** `ROLE_CASHIER` **dan** `ROLE_OFFICER` (satu kredensial, satu pintu) |
| `app/Controllers/InvestorOnboardingController.php` | berubah | `refuseCashier()` → menolak **setiap** peran selain `investor` (petugas tidak pernah dibuat lewat tautan undangan) |
| `app/Controllers/BaseController.php` | berubah | `roleLandingPath()`: `officer → /officer/dashboard`; `roleLoginPath()`: `officer → /officer/login` |
| controller lain | tak berubah | allow-list kasir tetap dua controller |

### 6.4 Route (`app/routes.php` — tetap data saja)

| Method | Path | Handler |
|---|---|---|
| GET | `/officer/login` | `OfficerAuthController::showLogin` |
| POST | `/officer/login` | `OfficerAuthController::login` |
| GET | `/officer/dashboard` | `OfficerPortalController::index` |
| GET | `/officer/riwayat` | `OfficerPortalController::history` |
| GET | `/operator/petugas` | `OfficerController::index` |
| POST | `/operator/petugas` | `OfficerController::store` |
| POST | `/operator/petugas/potongan` | `OfficerController::updateCut` |
| POST | `/operator/petugas/pin` | `OfficerController::resetPin` |
| POST | `/operator/petugas/status` | `OfficerController::setStatus` |
| POST | `/operator/petugas/bonus` | `OfficerController::bonus` |
| POST | `/operator/petugas/cair` | `OfficerController::disburse` |

Jumlah: 5 GET baru (4 `/officer/*` + 1 `/operator/petugas`) + 6 POST baru = **11 route baru**
(route lama tidak berubah: 14 GET + 21 POST → 19 GET + 27 POST).

### 6.5 Matriks izin (satu-satunya sumber)

| Permukaan | operator | kasir | investor | officer | anonim |
|---|---|---|---|---|---|
| `/operator/petugas` (GET + 6 POST) | ✅ | ❌ `302 /operator/teller` | ❌ `302 /investor/dashboard` | ❌ `302 /officer/dashboard` | ❌ `302 /login` |
| `/officer/dashboard`, `/officer/riwayat` | ❌ `302 /dashboard` | ❌ `302 /operator/teller` | ❌ `302 /investor/dashboard` | ✅ | ❌ `302 /login` |
| `/dashboard` (lama) | ✅ | ❌ | ❌ | ❌ `302 /officer/dashboard` | ❌ |
| `/operator/teller`, `/operator/kasbon` (allow-list kasir) | ✅ | ✅ | ❌ | ❌ `302 /officer/dashboard` | ❌ |
| `/operator/{settlement,capital,kasir}` | ✅ | ❌ | ❌ | ❌ | ❌ |
| `/investor/*` | ❌ | ❌ | ✅ | ❌ | ❌ |
| `/login` (kata sandi) | ✅ | ❌ `401` | ❌ `401` | ❌ **`401`** | — |
| `/cashier/login` | ❌ `401` | ✅ | ❌ `401` | ❌ `401` | — |
| `/investor/login` | ❌ `401` | ❌ `401` | ✅ | ❌ `401` | — |
| `/officer/login` | ❌ `401` | ❌ `401` | ❌ `401` | ✅ | — |
| `/join` (undangan) | — | ❌ "tautan tidak berlaku" | ✅ | ❌ "tautan tidak berlaku" | ✅ bila token sah |

Empat lapis pertahanan (pola Fase 7): (1) `requireRole` guard-first di setiap method,
(2) CSRF front controller, (3) CSRF kedua di controller, (4) `_once` + PRG.

### 6.6 View & aset

| Berkas | Status | Isi |
|---|---|---|
| `app/Views/layouts/officer.php` | **baru** | layout blind: identitas + tombol keluar + dua tab (`Dashboard`, `Riwayat`). **Tanpa** widget kas, emas, atau istilah pemilik |
| `app/Views/officer/login.php` | **baru** | pintu PIN (bentuk identik Layar A3, memakai `layouts/auth.php` + papan angka `investorLogin` yang sudah terdaftar) |
| `app/Views/officer/dashboard.php` | **baru** | kartu saldo hak + ringkasan bulan berjalan + 50 entri terakhir |
| `app/Views/officer/riwayat.php` | **baru** | riwayat lengkap (≤ 300 entri) |
| `app/Views/operator/petugas.php` | **baru** | Layar G |
| `app/Views/layouts/operator.php` | berubah | tab ke-7 "Mitra Lapangan" (operator saja) |
| `app/Views/operator/settlement.php` | berubah | kartu read-only "Potongan & Bonus Mitra Lapangan" + baris potongan pada rincian laba |
| `public/assets/js/app.js` | berubah | `settlementForm`: roster petugas + potongan + bonus masuk pratinjau; komponen baru `officerForm` **hanya bila** dipakai > 1 tempat (jika tidak: state literal di view, pelajaran `clipboardButton`) |
| `public/sw.js` | berubah | `CACHE_NAME` **v4 → `aurumflow-shell-v5`** (wajib, aset statis cache-first) |
| `public/assets/css/app.min.css` | berubah **hanya bila** ada kelas baru | kompilasi ulang Tailwind (hasilnya di-commit) |

### 6.7 Berkas lain yang berubah

`app/Config/Constants.php` (`ROLE_OFFICER` + label), `database/schema.sql` (tabel 15–16),
`database/seed.sql` (`officer_cut_total_cap_pct`), `database/migrate.php` (M4),
`tests/bootstrap.php` (fixture Fase 10), `tests/run_qc.sh` (+`qc_fase_10`), `tests/qc_fase_7.php`
(ENUM empat nilai), `tests/qc_seed.php`/`qc_routing.php`/`qc_sql_injection.php` (route &
pembersihan baru), `docs/*`, `README.md`, `AGENTS.md`.

### 6.8 Kontrak baru untuk `AGENTS.md` (ringkas, akan ditulis penuh saat eksekusi)

1. **Dua kanal potongan laba**: potongan % dibebankan saat **akrual**, bonus saat **penyerapan**;
   **tidak pernah** sebagai voucher OPEX dan **tidak pernah** saat pencairan.
2. **Urutan penguncian global** diperluas: `capital_wallets` → `cash_registers` →
   `bridge_loans` → `miner_loans` → `hulu_transactions` → `operational_expenses` →
   **`officers` → `officer_ledger_entries` (bonus belum terserap)** → `system_settings`.
3. **Uang petugas terparkir di laci**: `kas` memuat `Σ potongan + Σ bonus`; identitas
   `meja = baseline + Rec + Σ balance_payable` saat semua pencairan dari laci.
4. **Tutup buku = sembilan kelompok tulisan** dalam satu transaksi (a–i pada § 4.5).
5. **`users.role` empat nilai, append-only** (`officer` di ujung) + migrasi M4.
6. **Portal petugas adalah daftar izin**: hanya `/officer/dashboard` + `/officer/riwayat`
   (GET, tanpa POST), dan berkasnya **dilarang** menyebut istilah pada § 8.2 grup H.
7. **`cut_pct` & `base_amount` tidak pernah dirender di portal petugas.**
8. **`CACHE_NAME aurumflow-shell-v5`** (satu pemilik kebenaran bersama `sw.js`).
9. **Pencairan & bonus memakai `_once` + PRG**; allow-list kasir tidak berubah.
10. **Nominal hasil hitung tidak pernah dari klien**: potongan, bonus terserap, saldo hak, dan
    `balance_after` selalu dihitung peladen dari baris terkunci.

---

## 7. UI/UX

### 7.1 Layar G — Kelola Mitra Lapangan (`/operator/petugas`, operator saja)

Urutan kartu (mobile-first, satu kolom; meja ≥ 1024 px menjadi dua kolom):

1. **Kartu ringkasan**: jumlah petugas aktif, Σ saldo hak (Rp), Σ potongan sesi terakhir.
2. **Form "Tambah Petugas"**: nama, nomor telepon, PIN 6 digit + konfirmasi, `cut_pct`.
   Nilai awal dari peladen (`value="…"`), placeholder contoh berawalan `mis. `,
   `x-model.fill` untuk isian bernilai awal, dan `init()` memanggil `restoreAutoSplit(false)`
   bila komponen dipakai.
3. **Tabel petugas**: nama · telepon · `cut_pct` · saldo hak · status · aksi
   (Ubah tarif · Reset PIN · Aktif/Nonaktif · **Beri Bonus** · **Cairkan**).
4. **Modal "Beri Bonus"**: nominal, catatan alasan, peringatan *"bonus mengurangi laba pada
   tutup buku berikutnya dan menambah saldo hak petugas"*.
5. **Modal "Cairkan Hak"**: nominal (default = sisa saldo), pemilih pos (**Kas Meja Bakar /
   Brankas Utama** — nilai token, bukan UUID), catatan; menampilkan saldo pos terpilih.
6. **Tabel "Buku Hak Terbaru"**: 20 entri terakhir (tanggal · petugas · jenis · nominal · saldo).

Aturan tampilan yang tidak boleh dilanggar: seluruh output `Security::e()`, angka hanya lewat
`Formatter::rupiah()`/`persen()`/`tanggalId()`, kontrol form **tidak pernah** `:disabled`
(hanya `:readonly="submitting"`), `:disabled` hanya untuk `<button>`, dan setiap mutasi
diikuti PRG.

### 7.2 Layar C — tambahan read-only

- Kartu **"Potongan Mitra Lapangan (n petugas)"**: baris per petugas (nama · `pct` · nominal),
  total, dan baris **"Bonus akrual diserap"** (total + daftar tanggal/nama/nominal).
- Baris potongan masuk ke rincian laba: `laba kotor → beban OPEX → bonus akrual → potongan
  petugas → pemulihan defisit → laba bersih`.
- **Tanpa isian baru**: tidak ada input potongan, bonus, atau apa pun yang berasal dari klien
  (aturan anti-fiktif Fase 9 diperluas).
- Badge peringatan (kuning) bila `O + B + C > G` ("hak petugas & beban melebihi laba kotor").

### 7.3 Layar H — Dasbor Blind Mitra Lapangan (`/officer/dashboard`)

| Bagian | Isi | Larangan |
|---|---|---|
| Header | Nama petugas, label peran "Mitra Lapangan (Petugas)", tombol keluar | nomor telepon lengkap (ditampilkan tersamar `08•• •••• 12`) |
| Kartu utama | **Saldo Hak** (Rp) + tanggal pembaruan terakhir | saldo kas, emas, omzet, laba |
| Kartu kedua | Ringkasan bulan berjalan: Σ potongan, Σ bonus, Σ pencairan | persentase (`cut_pct`), basis (`base_amount`) |
| Riwayat | tanggal · jenis ("Potongan Harian"/"Bonus"/"Pencairan") · nominal bertanda · saldo setelah · catatan operator | sesi tutup buku, pos kas, id mutasi, nominal emas |

Tombol **tidak ada** selain keluar dan tautan ke Riwayat; tidak ada form apa pun (nol POST).

### 7.4 Catatan aset & PWA

`app.js` berubah ⇒ `CACHE_NAME` **wajib** naik ke `aurumflow-shell-v5` (tanpa itu shell lama
akan menjalankan `settlementForm` versi Fase 9 dan pratinjau potongan tidak pernah muncul).
Daftar-putih `sw.js` tidak berubah (tidak ada aset statis baru), `manifest.json` tidak berubah.

---

## 8. QC Strategy & Assertions

### 8.1 Protokol QC Manual 10 (siap disalin ke Roadmap)

| Parameter Uji | Data Masukan | Hasil yang Diharapkan | Status |
|---|---|---|---|
| Buat petugas | Layar G → `Petugas QC` / `089800000101` / PIN `246810` / `2,50` | Akun `role='officer'` + baris `officers` (saldo 0); PIN tersimpan ber-pepper | [ ] |
| PIN lemah / pepper kosong | PIN `111111`; `APP_PIN_PEPPER` kosong | `422` pesan kebijakan PIN; gagal-tertutup tanpa baris | [ ] |
| Petugas masuk | `/officer/login` nomor + PIN | `302 /officer/dashboard`; saldo hak & riwayat terlihat | [ ] |
| Petugas menabrak dinding | Petugas membuka `/operator/settlement`, `/operator/petugas`, `/dashboard`, `/investor/dashboard` | `302 /officer/dashboard`; **nol** tulisan; token `_once` tidak termakan | [ ] |
| Pintu lain menolak petugas | `/login` (kata sandi), `/cashier/login`, `/investor/login` dengan nomor petugas | `401` generik; 5× gagal → `429` (PinThrottle bersama) | [ ] |
| Potongan otomatis | Tutup buku 18,050 gr @ `2.080.000`, OPEX `200.000`, petugas 2,5 % | `cut = 59.662,50`; laba bersih `1.976.837,50`; pengelola `1.581.470,00`; pool `395.367,50`; saldo hak petugas `59.662,50` | [ ] |
| Bonus manual | Layar G → Beri Bonus `150.000` | Saldo hak `209.662,50`; **nol** baris `operational_expenses`/`cash_mutations`; saldo laci tidak berubah | [ ] |
| Penyerapan bonus | Tutup buku berikutnya | Laba bersih turun `150.000` **sekali**; entri bonus `settlement_id` terisi; tutup buku ketiga tidak memotong lagi | [ ] |
| Pencairan (kas meja) | Cairkan `100.000` dari Kas Meja Bakar | Saldo hak `109.662,50`; Kas Meja −100.000; satu mutasi `out` `Hak petugas: …` | [ ] |
| Pencairan (brankas) | Cairkan sisanya dari Brankas Utama | Saldo hak `0`; Brankas turun; satu mutasi `out` | [ ] |
| Pencairan melebihi hak / saldo pos | `150.000` saat saldo `100.000`; atau nominal > saldo pos | `422`; **nol** tulisan (mutasi, saldo, entri) | [ ] |
| Kiriman ganda | POST ulang dengan `_once` sama | Flash "ganda diabaikan"; hanya satu entri | [ ] |
| Hari minus | Laba kotor `−80.000` | Potongan `0`, tidak ada entri `cut`; defisit berjalan naik | [ ] |
| Blind portal | Isi `/officer/dashboard` & `/officer/riwayat` | Tidak memuat omzet, laba kotor, laba bersih, pool, hulu, `cut_pct`, `base_amount`, nama pos kas | [ ] |
| Migrasi lapangan | `php database/migrate.php` dua kali | exit 0; M4 "diterapkan"/"sudah diterapkan"; `storage/installed.lock` tidak tersentuh | [ ] |
| Angka protokol lama | Jalankan Fase 3–9 tanpa petugas | Seluruh angka identik (diferensial) | [ ] |

### 8.2 Grup assertion `tests/qc_fase_10.php` (target ≥ 130 assertion)

| Grup | Fokus | Contoh assertion (id) |
|---|---|---|
| **A. Skema & migrasi** | 2 tabel baru, tipe & FK, ENUM empat nilai, nol ALTER, seed kunci | `A.1` tabel `officers`/`officer_ledger_entries` ada; `A.2` `balance_payable`/`amount` = `DECIMAL(14,2)`; `A.3` `cut_pct`/`base_amount` = `DECIMAL(5,2)`; `A.4` PK `CHAR(36)`; `A.5` FK RESTRICT/SET NULL sesuai § 3.4; `A.6` `UNIQUE(officers.user_id)`, `UNIQUE(mutation_id)`; `A.7` nol `FLOAT`/`DOUBLE` di skema; `A.8` `users.role` = 4 nilai **berurutan** (`officer` di ujung); `A.9` tabel 1–14 tanpa kolom baru (snapshot `information_schema`); `A.10` `officer_cut_total_cap_pct` ada; `A.11` `migrate.php` dua kali exit 0 |
| **B. Akun & kebijakan** | pembuatan akun, tarif, PIN, status | `B.1` create → akun + `officers` dalam satu transaksi; `B.2` PIN ber-pepper (`Security::verifyPin` benar, `verifyPassword` salah); `B.3` pepper kosong → gagal, nol baris; `B.4` PIN lemah ditolak; `B.5` nomor ganda ditolak; `B.6` `cut_pct` di luar `0..100` ditolak; `B.7` `Σ cut_pct > cap` ditolak; `B.8` reset PIN; `B.9` nonaktif → tidak berakrual tapi saldo tetap; `B.10` `officers` tidak pernah dibuat untuk peran non-officer |
| **C. Aritmetika potongan (murni)** | `quote()` tanpa DB | `C.1`–`C.6` rumus & pembulatan (termasuk `Rp 25.000,03`); `C.7` cap `max(0,G)`; `C.8` hari minus → 0; `C.9` `operator+pool=net`; `C.10` monoton turun saat petugas ditambah; `C.11` `Σ cut = cap` saat skala; `C.12` tanpa petugas ⇒ keluaran identik Fase 9 (diferensial) |
| **D. Akrual dalam tutup buku** | sembilan kelompok tulisan | `D.1` baris `cut` per petugas (jumlah, `amount`, `cut_pct`, `base_amount`, `balance_after`); `D.2` `balance_payable` = Σ kredit; `D.3` `net_settled_profit` = nilai `quote()`; `D.4` `daily_coordination_fee` **tidak** memuat potongan; `D.5` `profit_distributions` = pool baru; `D.6` nol baris `cut` untuk petugas nonaktif/pct 0; `D.7` transaksi gagal ⇒ nol tulisan (snapshot); `D.8` satu baris `cut` per petugas per sesi (hitung) |
| **E. Bonus akrual** | deduksi sekali | `E.1` inject → entri `bonus`, `settlement_id` NULL; `E.2` nol `cash_mutations`, nol `operational_expenses`, saldo pos tidak berubah (diferensial); `E.3` `balance_payable` naik; `E.4` settle → `net` turun sebesar bonus; `E.5` entri `settlement_id` terisi; `E.6` settle berikutnya tidak memotong lagi; `E.7` bonus pada hari tanpa `holding` → diserap sesi berikutnya; `E.8` bonus tidak pernah muncul di `daily_coordination_fee` |
| **F. Pencairan** | empat tulisan atomik | `F.1` mutasi `out` berlabel benar + `register_id` sesuai pilihan; `F.2` saldo pos turun tepat; `F.3` entri `disbursement` + `balance_after`; `F.4` `balance_payable` turun; `F.5` parsial lalu penuh; `F.6` dari Brankas; `F.7` semua kegagalan `422` + snapshot identik (nominal > hak, > saldo pos, ≤ 0, pos asing, UUID asing); `F.8` `UNIQUE(mutation_id)` menolak entri ganda |
| **G. HTTP & RBAC** | guard-first | `G.1` operator sukses (7 route); `G.2` kasir/investor/petugas/anonim `302`/`419` ke beranda masing-masing; `G.3` **nol tulisan** pada penolakan; `G.4` token `_once` **tidak termakan** oleh permintaan terlarang; `G.5` CSRF hilang → `419`; `G.6` `_once` ganda → satu tulisan; `G.7` PRG (`Location` benar); `G.8` petugas ditolak di `/login`, `/cashier/login`, `/investor/login`; `G.9` petugas lolos `/officer/login`; `G.10` `PinThrottle` 5 gagal → `429` (dan `clearPinThrottle()` di akhir suite) |
| **H. Isolasi blind** | pemindaian statis + HTTP | `H.1`–`H.6` pemindaian berkas portal (`Controllers/OfficerPortalController.php`, `Services/OfficerPortalService.php`, `Views/officer/*`, `Views/layouts/officer.php`) terhadap daftar terlarang: `HuluTransaction`, `hulu_transactions`, `farmer_alias`, `total_shop_revenue`, `net_settled_profit`, `operator_share_80`, `investor_pool_20`, `daily_coordination_fee`, `carryover_deficit`, `CapitalWallet`, `capital_wallets`, `SystemSetting`, `CashRegister`, `CashMutation`, `BridgeLoan`, `MinerLoan`, `OperationalExpense`, `DailySettlement`, `AllocationEngine`, `SettlementService`, `TellerService`, `expenseTotals`, `sumProfit(`, `SELECT *`, `cut_pct`, `base_amount`, serta istilah tampilan "omzet", "laba kotor", "laba bersih", "pool", "hulu", "talangan", "kasbon"; `H.7` HTML `/officer/dashboard` **tidak** memuat string terformat omzet/laba kotor/laba bersih (dibandingkan dengan nilai nyata dari DB); `H.8` HTML tidak memuat `cut_pct` dalam bentuk persen; `H.9` tidak ada route POST di portal; `H.10` layout petugas tidak memuat tautan operator/investor |
| **I. Aritmetika kas (diferensial)** | sinkronisasi buku vs fisik | `I.1` `Δ saldo pos = Σ mutasi pos` (setiap langkah); `I.2` `kas + brankas = pendapatan`; `I.3` `meja = baseline + Σ balance_payable` (skenario § 5.2); `I.4` sesudah pencairan identitas tetap; `I.5` total buku = baseline + net + Σ saldo hak; `I.6` pencairan tidak mengubah `net`/`operator_share_80`/`investor_pool_20`/`capital_wallets`; `I.7` `O+B+C > R` → `422` nol tulisan; `I.8` dua pencairan berurutan konsisten |
| **J. Fixture & kebersihan** | idempoten | `J.1` `resetOfficerState()` menghapus entri → `officers` → akun `0898%`; `J.2` dipanggil **sebelum** `daily_settlements` dihapus (FK RESTRICT); `J.3` `resetTellerState()`/`clearSettlementTraces()` memanggilnya; `J.4` suite dapat dijalankan dua kali (0 gagal); `J.5` angka protokol Fase 3–9 tidak bergeser setelah suite Fase 10; `J.6` tanpa artefak (`tests/.tmp/`, `storage/throttle/`) |

### 8.3 Fixture baru (`tests/bootstrap.php`)

| Fungsi | Isi |
|---|---|
| `seedQcOfficer(array $override = [])` | akun `089800000101` (`role='officer'`, PIN `246810`) + baris `officers` (`cut_pct=2.50`), dibuat lewat **jalur nyata** `OfficerService::create()` |
| `resetOfficerState()` | hapus `officer_ledger_entries` → `officers` → akun `0898%` (idempoten) |
| `neutraliseOfficers()` | set `cut_pct = 0.00` dan `balance_payable = 0.00` **seluruh** baris (pola `neutraliseWallets()`), supaya petugas buatan manual saat uji lapangan tidak menggeser angka protokol |
| `officerSnapshot()` | potret `officers`, entri, `cash_mutations`, saldo pos, Σ `operational_expenses` — untuk assertion "nol tulisan" |
| `seedQcBonus()` | bonus lewat jalur nyata `OfficerService::injectBonus()` |

Urutan pembersihan FK yang baru (wajib dicatat di `AGENTS.md`):

```
officer_ledger_entries → officers → (purge users 0898%)
→ operational_expenses → miner_loan_ledger → miner_loans → miners
→ bridge_loans → profit_distributions → daily_settlements → cash_mutations
```

`resetOfficerState()` dan `resetExpenseState()`/`resetKasbonState()` sama-sama dipanggil
**paling awal** oleh `resetTellerState()` dan `clearSettlementTraces()`, sehingga suite
Fase 2/3/4/6/9 melihat keadaan kas bersih tanpa hak petugas.

### 8.4 Perubahan wajib pada suite lama (disengaja)

| Suite | Perubahan | Alasan |
|---|---|---|
| `qc_fase_7` | assertion ENUM: `['operator','investor','cashier','officer']` (empat nilai, urutan) | M4 menambah nilai di ujung |
| `qc_fase_3/4/6/9` | **tidak ada perubahan angka**; ditambah satu assertion bahwa `quote()` tanpa petugas identik | bukti nol regresi (diferensial di `qc_fase_10` grup C.12/I) |
| `qc_seed` | pembersihan akhir menyertakan `officer_ledger_entries` → `officers` | urutan FK baru |
| `qc_routing` | daftar route bertambah (11 route) | route baru wajib cocok dengan handler nyata |
| `qc_sql_injection` | daftar titik uji ditambah: `/operator/petugas/bonus`, `/operator/petugas/cair`, `/officer/login` | jalur uang & jalur auth baru |
| `run_qc.sh` | `qc_fase_10` dijalankan **setelah** `qc_fase_9`, **sebelum** `qc_sql_injection` | mengikuti urutan fase |

### 8.5 Probe lapis Alpine (di luar `run_qc.sh`, butuh Node)

`tests/manual/js_settlement_officer_check.js` **baru**: mengevaluasi ekspresi `x-show`/`x-text`
**di markup** Layar C untuk tiga keadaan isian (tanpa petugas, dengan petugas, cap mengikat) dan
memastikan `settlementForm` membaca roster dari peladen (bukan placeholder), tanpa `try`
di dalam ekspresi Alpine (aturan ekspresi-vs-pernyataan). Probe lama
(`js_form_init_check.js`, `js_bridge_fee_check.js`, `js_kasbon_check.js`,
`js_capital_simulator_check.js`, `js_investor_check.js`) wajib tetap **lulus**.

### 8.6 Gate penerimaan

```bash
bash tests/run_qc.sh                    # exit 0, 16 suite, ≥ 1.030 assertion
php tests/qc_fase_10.php                # suite baru berdiri sendiri
php database/migrate.php                # M4 dua kali, exit 0
node tests/manual/js_settlement_officer_check.js
php -l <setiap berkas PHP baru/berubah>
grep -rn "FLOAT\|DOUBLE" database/schema.sql     # harus nihil
```

Kriteria keras yang tidak boleh dinegosiasikan: **tidak ada jalur kas tanpa mutasi**, **tidak ada
potongan/bonus yang mengurangi laba lebih dari sekali**, **tidak ada satu pun istilah pemilik
di portal petugas**, dan **angka protokol Fase 3–9 tidak bergeser** saat tidak ada petugas aktif.

---

## 9. Tugas Eksekusi (T1–T12)

| # | Tugas | Keluaran |
|---|---|---|
| **T1** | **Dokumentasi lebih dulu**: ERD (tabel 15–16, kardinalitas, ENUM), Roadmap (FASE 10 + Protokol 10 + Sprint 10), PRD § 3.6 + amendemen § 3.3/§ 4.2, SAD (komponen + urutan kunci), UI/UX (Layar G/H + Layar C) | `docs/` selaras sebelum kode |
| **T2** | Skema: `schema.sql` (2 tabel), `seed.sql` (kunci cap), `Constants::ROLE_OFFICER` + label, `User::ROLE_ENUM_VALUES`/`ensureRoleEnum()` digeneralisasi | DDL + kanonik kode |
| **T3** | Migrasi **M4** di `database/migrate.php` (M4a/b/c) + `Officer::ensureSchema()` | jalur lapangan idempoten |
| **T4** | Model: `Officer`, `OfficerLedgerEntry` (daftar putih kolom, kunci, penyerapan) | lapisan SQL |
| **T5** | `OfficerService`: akun, tarif, bonus, pencairan, pratinjau, akrual, penyerapan, view-model Layar G | use-case + aritmetika |
| **T6** | `SettlementService`: `batchContext()` + `quote()` + `settle()` (dua kelompok tulisan baru, dua kunci baru, penjagaan) | mesin tutup buku |
| **T7** | Pintu & RBAC: `OfficerAuthController`, `PinLoginService` (audience), `BaseController` (dua peta peran), `AuthController` (tolak petugas), `InvestorOnboardingController` (tolak non-investor), `routes.php` | autentikasi & isolasi |
| **T8** | `OfficerPortalService` + `OfficerPortalController` + layout & view petugas (blind) | portal |
| **T9** | Layar G (`operator/petugas.php`) + perubahan `operator/settlement.php` + `layouts/operator.php` (tab ke-7) + `app.js` (`settlementForm`) + `sw.js` (`v5`) + kompilasi CSS bila perlu | UI |
| **T10** | Fixture `tests/bootstrap.php` + `tests/qc_fase_10.php` (grup A–J) + `run_qc.sh` + penyesuaian `qc_fase_7`/`qc_seed`/`qc_routing`/`qc_sql_injection` + probe Alpine | QC |
| **T11** | `AGENTS.md` (10 kontrak baru § 6.8) + `README.md` (route, M4, baseline) | kontrak & dokumentasi kerja |
| **T12** | Gerbang penuh: `bash tests/run_qc.sh`, `migrate.php` dua kali, probe Node, `php -l`, tinjauan diff akhir, lalu tulis `plan/Summary_Fase_10_Mitra_Lapangan_AurumFlow.md` + ADDENDUM | serah terima |

---

## 10. Risiko & Mitigasi

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| **R-1** | Potongan & bonus **dipotong dua kali** (voucher + akrual) | laba & pool mitra turun tanpa sebab; uang laci tanpa pemilik (§ 5.5) | Dua kanal terpisah + `settlement_id` sebagai penanda "sudah dibebankan" + assertion `D.4`, `E.4`–`E.6`, `E.8` |
| **R-2** | Pencairan ditulis sebagai beban | beban fiktif; pool mitra tertekan | `disburse()` tidak pernah menyentuh `daily_settlements`/`profit_distributions`; assertion `I.6` |
| **R-3** | Shell PWA ter-cache menjalankan `settlementForm` lama | pratinjau potongan tidak muncul (gejala "baris hilang" Fase 6) | `CACHE_NAME` → `v5` **dalam commit yang sama**; assertion `G.17`–`G.18` `qc_fase_6` + probe Node |
| **R-4** | Kebocoran laba kotor lewat portal (`cut_pct` × dosis) | petugas dapat menghitung margin & laba | `cut_pct`/`base_amount` tidak dirender (A-11) + pemindaian `H.7`/`H.8`; risiko sisa dicatat sebagai O-10.5 |
| **R-5** | Deadlock jalur pencairan vs tutup buku | transaksi gagal sesekali | urutan kunci global diperluas (`officers` setelah `operational_expenses`) dan diuji `I.8` + dokumentasi `AGENTS.md` |
| **R-6** | `cut_pct` diubah saat tutup buku berjalan | potongan dihitung dengan tarif campuran | roster dikunci `FOR UPDATE` sebelum `quote()`; tarif disalin ke `cut_pct` tiap entri |
| **R-7** | FK `settlement_id` `SET NULL` (kalau keliru) | bonus terserap aktif kembali → deduksi ganda | A-10 (`RESTRICT`) + assertion `A.5` + urutan pembersihan fixture `J.2` |
| **R-8** | Potongan membuat laci tidak cukup belanja besok (`O+B+C > G`) | kas operasional macet | D-2 diterima sadar; peringatan log + badge Layar C; saldo laci tetap ditampilkan di meja kasir |
| **R-9** | Petugas nonaktif tetap terlihat "aktif" karena `officers` tanpa kolom status | akrual keliru | satu sumber kebenaran `users.is_active`; roster hanya membaca join aktif; assertion `B.9` |
| **R-10** | Query portal memakai JOIN yang membocorkan kolom | kebocoran lewat `SELECT *` | kolom eksplisit di model; larangan `SELECT *` pada grup H |

---

## 11. Item Terbuka

| # | Item | Status |
|---|---|---|
| **O-10.1** | Pindahkan bonus akrual ke jalur voucher `operational_expenses` (butuh ALTER `mutation_id` NULL-able + `officer_id`) agar tampil di laporan OPEX | **Terbuka** — ditolak pada `dec-2b529bcb6a90f1db` opsi B |
| **O-10.2** | Persetujuan berjenjang (dual control) untuk bonus & pencairan besar | Terbuka (butuh kolom approver/DDL) |
| **O-10.3** | Rekap bulanan hak petugas + ekspor CSV sisi operator | Terbuka (pola `ExportController` khusus operator) |
| **O-10.4** | Kolom agregat potongan pada `daily_settlements` (mis. `officer_cut_total`) | **Ditolak** demi nol ALTER; angka tersedia dari `Σ officer_ledger_entries` per sesi |
| **O-10.5** | Kebocoran tidak langsung: petugas mengetahui tarifnya sendiri sehingga dapat menaksir laba kotor bila ia tahu nominal potongannya | Terbuka — risiko diterima pemilik sistem; mitigasi: layar tidak menampilkan basis, tarif tidak dirender |
| **O-10.6** | Kalender hari libur untuk potongan (hari kerja vs hari penjualan) | Terbuka — saat ini potongan mengikuti **hari penjualan** (tutup buku), bukan kalender |
| **O-10.7** | Reset PIN mandiri petugas & pemaksaan ganti PIN login pertama | Terbuka (butuh `users.session_epoch`) |
| **O-10.8** | Notifikasi saldo hak (WhatsApp) | Terbuka (butuh gateway eksternal) |
| **O-10.9** | Rekonsiliasi opname kas fisik (selisih laci) — prasyarat tampilan "sisa laci yang benar" | Terbuka sejak audit OPEX § 5.3 |

---

## 12. Checklist Serah Terima Fase 10

- [ ] `docs/` (ERD, Roadmap+QC, PRD, SAD, UI/UX) sudah selaras **sebelum** kode ditulis.
- [ ] `database/schema.sql` memuat tabel 15 & 16; `seed.sql` memuat `officer_cut_total_cap_pct`.
- [ ] `database/migrate.php` **M4** idempoten (dua kali exit 0) dan melaporkan keempat nilai ENUM.
- [ ] `users.role` = `ENUM('operator','investor','cashier','officer')` (append-only, dibuktikan).
- [ ] Nol ALTER pada tabel 1–14; nol `FLOAT`/`DOUBLE`.
- [ ] `SettlementService` menulis **sembilan** kelompok tulisan dalam satu transaksi; urutan kunci
      global diperbarui di kode + `AGENTS.md`.
- [ ] `quote()` tanpa petugas identik dengan Fase 9 (diferensial, bukan asumsi).
- [ ] Bonus: nol mutasi kas, nol voucher, diserap tepat sekali, `settlement_id` `RESTRICT`.
- [ ] Pencairan: satu mutasi `out` per pencairan, dua pos, dua penjagaan saldo, `422` tanpa tulisan.
- [ ] `ROLE_OFFICER` hanya menyentuh `/officer/dashboard` & `/officer/riwayat`; seluruh permukaan
      lain menolak `302` tanpa tulisan dan tanpa memakan `_once`.
- [ ] Portal petugas bebas istilah terlarang (pemindaian statis + pemeriksaan HTML nyata).
- [ ] `CACHE_NAME` = `aurumflow-shell-v5` di `sw.js` **dan** `AGENTS.md`.
- [ ] `tests/qc_fase_10.php` terdaftar; `run_qc.sh` exit 0 (≥ 1.030 assertion, 0 gagal).
- [ ] Probe Alpine lama + baru lulus (di luar `run_qc.sh`).
- [ ] `plan/Summary_Fase_10_Mitra_Lapangan_AurumFlow.md` + ADDENDUM ditulis.
- [ ] `git status` bersih; tidak ada kode mati (setiap method publik baru punya pemanggil nyata
      atau assertion QC).
