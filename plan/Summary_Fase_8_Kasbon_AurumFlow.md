# Summary Fase 8 — Modul Kasbon / Piutang Petani (*Miner Loan & Ledger*) — AurumFlow

> **Status dokumen (pasca-Fase 9 — sinkronisasi dokumentasi).** Seluruh angka dan perilaku di
> bawah ini — termasuk (bila ada) penyebutan **13 tabel** atau kurang, **14 suite / 853
> assertion**, `CACHE_NAME` **v3**, dan isian **`coord_fee`** — adalah **potret fase tersebut**,
> bukan keadaan sekarang. Keadaan terkini: **14 tabel** (termasuk `operational_expenses`, nol `ALTER`),
> `CACHE_NAME` **`aurumflow-shell-v4`**, **15 suite / 900 assertion / 0 gagal**, beban operasional
> dicatat sebagai **voucher kas** di meja kasir lalu diserap otomatis oleh tutup buku
> (`total_cash = pendapatan`). Rujukan terkini: `plan/Summary_Fase_9_OPEX_AurumFlow.md`.


> **Laporan Eksekusi**
> Proyek: **AurumFlow** — sistem manajemen operasional privat perdagangan emas aluvial (settlement **T+1**).
> Modul: **Kasbon / Piutang Petani** — permintaan Product Owner sebelum operasi lapangan.
> Status: **`EXECUTED`** — seluruh tugas **T1–T12** selesai. `bash tests/run_qc.sh` **exit 0**
> (**14 suite, 853 assertion, 0 gagal**) dan probe Node `js_kasbon_check.js` lulus (24 assertion).
> Rencana & penyimpangan: `plan/Plan_Kasbon_Petani_AurumFlow.md` (bagian **`# ADDENDUM`**).

| Item | Hasil |
|---|---|
| Keputusan pengguna | `dec-454e02e31b373890` — batas harian = Σ pokok hari ini; `KasbonController` baru; satu halaman `/operator/kasbon` |
| Skema | **13 tabel** (tiga tabel baru: `miners`, `miner_loans`, `miner_loan_ledger`), **nol ALTER** pada tabel lama, satu kunci `system_settings.daily_loan_limit` |
| Migrasi lapangan | `php database/migrate.php` **M2** — idempoten (dijalankan dua kali: "dibuat" lalu "sudah ada"), gate `INSTALL_TOKEN`, tidak menyentuh `storage/installed.lock` |
| Permukaan baru | `GET /operator/kasbon`, `POST /operator/kasbon`, `POST /operator/kasbon/angsuran`, `POST /operator/kasbon/petani` — **permukaan KEDUA** yang menerima peran `cashier` |
| Aset | `app.js` + komponen `kasbonForm`; `CACHE_NAME` → **`aurumflow-shell-v3`**; `app.min.css` **byte-identik** (24.264 byte, sha256 `cbf9ac80…`) |
| QC | **14 suite / 853 assertion / 0 gagal / exit 0**, tanpa `storage/logs/php-*.log` |

---

## 1. Ringkasan Perilaku yang Dibangun

### 1.1 Pencairan (kas keluar)

`POST /operator/kasbon` → `KasbonService::disburse()` — **satu** `Database::transaction()`:

```
kunci cash_registers (lockTellerForUpdate)
  → kunci baris system_settings.daily_loan_limit (getForUpdate)
  → Σ pokok issued_on = hari ini
  → (a) Σ + pokok ≤ daily_loan_limit     → 422 "Sisa batas kasbon hari ini …"
  → (b) pokok ≤ saldo fisik laci         → 422 "Saldo Kas Meja Bakar hanya …"
  → INSERT miner_loans (open, balance = pokok, issued_on, issued_by)
  → cash_registers −pokok
  → INSERT cash_mutations (out, "Kasbon: <nama>")
  → INSERT miner_loan_ledger (disbursement, balance_after = pokok)
```

Batas harian bersifat **inklusif**; `daily_loan_limit = 0` berarti kasbon
dinonaktifkan (pesan eksplisit, bukan galat teknis); nilai rusak/negatif →
fail-closed. Kedua penolakan terjadi **sebelum** satu pun tulisan.

### 1.2 Angsuran (kas masuk) — FIFO

`POST /operator/kasbon/angsuran` → `KasbonService::repay()`:

* kunci pos kas → kunci kasbon `open` milik petani
  (`ORDER BY issued_on ASC, created_at ASC, id ASC`);
* potong kasbon **tertua** lebih dulu; `status='settled'` + `settled_at` saat sisa
  mencapai nol;
* **satu baris ledger per kasbon tersentuh**, tetapi **satu** mutasi `in` sebesar
  total (uang fisik masuk satu kali);
* nominal > sisa utang → `422` tanpa tulisan.

Dua metode: `cash` (tunai) dan `nota` (potong nota). **Potong nota tidak memotong
`hulu_transactions`**: pembelian hulu tetap dibayar penuh, angsuran dicatat
sebagai kas masuk tersendiri — efek netto laci identik dengan uang yang
diserahkan, sementara `modal belanja hulu`, laba, dan pool mitra 20 % tidak
terdistorsi.

### 1.3 Petani (koleksi data bertahap)

`POST /operator/kasbon/petani` → hanya `full_name` yang wajib; `phone_number`
(UNIQUE, NULL boleh berulang) dan `location` opsional. Nomor duplikat ditolak
`422` dengan menyebut nama pemilik nomor. PRG mengembalikan halaman dengan petani
tersebut **terpilih** (`?miner=<uuid>`) sehingga kasir langsung mengetik nominal.
Tidak ada baris `users`/`capital_wallets` yang dibuat — petani bukan mitra modal.

### 1.4 Resolusi petani (peladen tidak mempercayai klien)

`KasbonService::resolveMiner()` empat tingkat: `miner_id` UUID sah → label
kandidat (teks sebelum `" | "`) → nama eksak → `422`. Form tetap benar tanpa
JavaScript; label yang berubah antara render dan submit tetap aman karena jalur
utama adalah `miner_id` yang diisi `data-miner-id`.

---

## 2. Berkas yang Berubah

### 2.1 Baru

| Berkas | Isi |
|---|---|
| `app/Models/Miner.php` | SQL `miners` + `ensureSchema()` (DDL tiga tabel, pemilik migrasi M2) + `listForAutocomplete()` + `findByName()` |
| `app/Models/MinerLoan.php` | SQL `miner_loans`: insert, `lockOpenByMinerForUpdate()` (FIFO), `sumDisbursedOn()`, `applyRepayment()`, `activeBorrowers()`, `outstandingTotal()` |
| `app/Models/MinerLoanLedger.php` | SQL `miner_loan_ledger`: insert, `recent()`, `listForLoan()` |
| `app/Services/KasbonService.php` | Batas harian, pencairan, angsuran FIFO, pendaftaran petani, resolver, view-model, label autocomplete |
| `app/Controllers/KasbonController.php` | 4 method publik (guard-first), `guardOnce()`, `renderKasbon()` |
| `app/Views/operator/kasbon.php` | 7 kartu: batas harian, kasbon baru, angsuran (+tombol cepat), daftar petani, peminjam aktif, riwayat, petunjuk |
| `tests/qc_fase_8.php` | Protokol QC Manual 8 (grup B–J, **66 assertion**) |
| `tests/manual/js_kasbon_check.js` | Probe Alpine: cermin tombol cepat & `init()` (**24 assertion**) |
| `plan/Plan_Kasbon_Petani_AurumFlow.md` | Rencana + `# ADDENDUM` (A-1…A-11) |
| `plan/Summary_Fase_8_Kasbon_AurumFlow.md` | Laporan ini |

### 2.2 Berubah

| Berkas | Perubahan |
|---|---|
| `docs/AurumFlow Entity Relationship Diagram.md` | Diagram + kardinalitas + DDL tabel 11–13 + seed `daily_loan_limit` + pertimbangan teknis § 5.6 |
| `docs/AurumFlow System Architecture Document.md` | Struktur direktori, catatan penempatan Fase 8, § 4.6 (dua permukaan izin), § 4.7 (buku kasbon), jumlah tab |
| `docs/AurumFlow Roadmap and QC Documents.md` | FASE 8 + **Protokol QC Manual 8** (13 baris) + Sprint 8 |
| `docs/AurumFlow Product Requirement Document v3.0.md` | **Modul 7** kasbon + catatan skema tiga tabel |
| `docs/AurumFlow UI UX Documents.md` | § 7.2 (6 tab / 2 tab) + **§ 8 Layar F: Buku Kasbon** |
| `AGENTS.md` | Peta 13 tabel; **dua permukaan izin kasir**; `miner_loans` pada urutan kunci; pengecualian sempit `SystemSetting` `daily_loan_limit`; `CACHE_NAME` v3; kontrak kasbon (5 butir); fixture kasbon; baseline 853; perintah `qc_fase_8`/`js_kasbon_check` |
| `README.md` | 13 tabel, migrasi M1+M2, § 7.15 kasbon, tabel suite (853), probe kasbon, isolasi dua permukaan |
| `database/schema.sql` | Tiga `CREATE TABLE IF NOT EXISTS` (nol ALTER) |
| `database/seed.sql` | `daily_loan_limit = 500000.00` (idempoten) |
| `database/migrate.php` | Migrasi **M2** + laporan ringkasan |
| `app/Models/SystemSetting.php` | `ensure(string $key, string $value, string $description): bool` (idempoten) |
| `app/routes.php` | 4 entry (1 GET + 3 POST) |
| `app/Views/layouts/operator.php` | Tab **Buku Kasbon** (ke-6 operator / ke-2 kasir) + docblock |
| `public/assets/js/app.js` | Komponen `kasbonForm` + `kasbonAmountText()`/`kasbonParseAmount()` + registrasi (6 komponen) |
| `public/sw.js` | `CACHE_NAME = 'aurumflow-shell-v3'` |
| `tests/bootstrap.php` | `resetKasbonState()`, `seedQcMiner()`, `seedQcKasbon()`, `kasbonSnapshot()`, `purgeQcMiners()`; integrasi `resetTellerState()`/`clearSettlementTraces()`/`ensureInstalled()` |
| `tests/run_qc.sh` | Suite `qc_fase_8` + header Fase 1–8 |
| `tests/qc_seed.php` | 13 tabel, +4 kolom uang, `daily_loan_limit`, assertion baru `4.7f` (semantik FK kasbon) |
| `tests/qc_fase_5.php` | K.1 daftar tabel 13 |
| `tests/qc_fase_6.php` | G.17/G.18 versi shell v3; H.7 daftar tabel 13 |
| `tests/qc_fase_7.php` | F.3b (dua permukaan izin), F.6 (6 komponen Alpine), F.7 (v3), E.8 (berkas kasbon ikut dipindai) |

### 2.3 Tidak Berubah (dijaga assertion)

`app/Services/TellerService.php`, `SettlementService.php`, `AllocationEngine.php`,
`BridgeLoanService.php`, `CashierService.php`; view `teller`/`settlement`/
`capital`/`kasir`; seluruh DDL tabel lama; **`public/assets/css/app.min.css`**
(sha256 diperiksa `qc_fase_8` I.6).

---

## 3. Hasil QC

```
bash tests/run_qc.sh   →   exit 0
LULUS : 14/14   GAGAL : 0/14
Log   : storage/logs/qc-fase7.log
```

| Suite | Assertion | Status |
|---|---:|---|
| `qc_pdo` | 20 | LULUS |
| `qc_formatter` | 43 | LULUS |
| `qc_security` | 32 | LULUS |
| `qc_seed` | 48 | LULUS |
| `qc_roadmap_protocol_1` | 6 | LULUS |
| `qc_fase_2` | 66 | LULUS |
| `qc_fase_3` | 98 | LULUS |
| `qc_fase_4` | 115 | LULUS |
| `qc_fase_5` | 130 | LULUS |
| `qc_fase_6` | 105 | LULUS |
| `qc_fase_7` | 78 | LULUS |
| **`qc_fase_8`** | **66** | **LULUS** |
| `qc_sql_injection` | 15 | LULUS |
| `qc_routing` | 31 | LULUS |
| **Total** | **853** | **0 gagal** |

Probe lapis Alpine (di luar `run_qc.sh`, butuh Node):

```
node tests/manual/js_kasbon_check.js   →   24 assertion, 0 gagal
```

Cakupan `qc_fase_8` (grup): fixture & urutan FK (B), pencairan atomik + `_once` +
petani baru (C), dua lapis validasi (D), angsuran tunai/potong nota/FIFO/lunas
(E), RBAC dua permukaan + regresi Fase 7 (F), isolasi data pemilik dengan kanari
(G), kontrak statis SQL/transaksi/urutan kunci/FIFO/escaping (H), UI & aset
(datalist, tombol cepat, `CACHE_NAME` v3, **nol kompilasi CSS**) (I), dokumen &
konsistensi (J).

---

## 4. Verifikasi Manual yang Disarankan (Protokol QC Manual 8)

| Langkah | Harapan |
|---|---|
| `/operator/kasbon` → daftarkan petani (nama saja) | Kembali ke halaman dengan petani terpilih; telepon/lokasi boleh kosong |
| Cairkan Rp 250.000 | Kas Meja Bakar turun persis; baris piutang `open`; satu mutasi `out` |
| Cairkan melebihi sisa batas harian | `422` "Sisa batas kasbon hari ini …"; saldo & piutang tidak berubah |
| Cairkan melebihi saldo laci | `422` "Saldo Kas Meja Bakar hanya …"; nol tulisan |
| Tekan **20 %**, **30 %**, lalu **Lunas** | Nominal terisi otomatis; pratinjau sisa setelah bayar akurat; `Lunas` = tepat sisa utang |
| Angsuran **Potong Nota** | `payment_method = nota`; `hulu_transactions` **tidak** berubah; kas naik |
| Angsuran > sisa utang | `422` "Sisa utang … hanya Rp X"; nol tulisan |
| Dua kasbon lalu satu angsuran melewati yang pertama | Kasbon tertua lunas lebih dulu; dua baris ledger, satu mutasi kas |
| Login `/cashier/login` → tab **Buku Kasbon** | Bisa mencairkan/menerima angsuran; tidak ada Brankas/laba bersih/tautan pemilik |
| Login `/investor/login` → kirim form kasbon | `302` ke `/investor/dashboard`; nol tulisan |
| `php database/migrate.php` dua kali | Exit 0; jalankan kedua "sudah ada / sudah diterapkan" |

---

## 5. Item Terbuka (dibawa dari rencana)

| ID | Item | Rencana |
|---|---|---|
| O-1 | Nonaktifkan/arsipkan petani (`miners.is_active`) + aturan "tidak boleh ada utang berjalan" | Fase lanjutan (kolom + endpoint status) |
| O-2 | Koreksi/*void* kasbon & angsuran (jejak reversal) | Butuh `reversal_of` pada ledger |
| O-3 | Potong nota otomatis di form timbang (`hulu_transactions.miner_id` + `loan_deduction`) | Butuh ALTER + perubahan `TellerService::payCash()` |
| O-4 | Ekspor CSV buku kasbon & laporan umur piutang (*aging*) | Menunggu permintaan PO |
| O-5 | Panel admin `system_settings` untuk `daily_loan_limit` | Masih lewat `seed.sql`/`migrate.php`, seperti parameter lain |
| O-6 | Bunga/denda keterlambatan | Di luar model bisnis saat ini (pinjaman tanpa bunga) |

---

## 6. Catatan Operasional

* **Batas harian** diatur di `system_settings.daily_loan_limit` (default
  Rp 500.000). Mengubahnya butuh akses basis data (panel admin belum ada — O-5);
  nilai `0` menonaktifkan kasbon tanpa mengubah kode.
* **Kas fisik**: pencairan menolak bila uang di laci tidak cukup. Isi kas lewat
  **Pindah Kas** (`/operator/capital`) atau tutup buku sebelum melayani kasbon besar.
* **Tutup buku** tidak terpengaruh piutang: kasbon hanya menggerakkan kas fisik,
  bukan modal belanja hulu maupun beban, sehingga bagi hasil 80 : 20 tetap.
* **Membersihkan data uji**: hapus berurutan `miner_loan_ledger` → `miner_loans` →
  `miners` **sebelum** `cash_mutations` pos kas meja (FK `ON DELETE RESTRICT`).
  `QcFixture::resetKasbonState()` melakukannya otomatis.
