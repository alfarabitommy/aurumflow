# Summary Fase 10 — Modul Mitra Lapangan / Aparat (*Coordination Ledger & Blind Portal*) — AurumFlow

| | |
|---|---|
| **Fase** | 10 — Mitra Lapangan / Aparat |
| **Status** | **Selesai** — `bash tests/run_qc.sh` **exit 0** (16 suite, 989 assertion, 0 gagal) |
| **Rencana acuan** | `plan/Plan_Fase_10_Mitra_Lapangan_AurumFlow.md` |
| **Keputusan pengguna** | `dec-2b529bcb6a90f1db` — (D-1) bonus = **akrual tanpa voucher**; (D-2) `cap = max(0, laba kotor)`; (D-3) pencairan **operator saja**, dua pos kas |
| **Skema** | 2 tabel baru (ke-15 & ke-16) + nilai ENUM `'officer'` di ujung `users.role` + 1 kunci `system_settings`; **NOL ALTER** pada tabel 1–14 |
| **Migrasi** | `M4` di `database/migrate.php` (idempoten, gate `INSTALL_TOKEN`) |
| **Shell PWA** | `CACHE_NAME` = **`aurumflow-shell-v5`** |

---

## 1. Ringkasan hasil

Fase 10 menambahkan **buku hak mitra lapangan** dan **portal blind**-nya:

| Kemampuan | Bukti |
|---|---|
| Potongan persentase dari **laba kotor** diakrualkan tiap tutup buku, dipotong **sebelum** bagi hasil 80 : 20 | `qc_fase_10` C.1–C.3, D.1–D.3 |
| Bonus manual = akrual (kredit buku hak, **tanpa** kas & **tanpa** voucher OPEX) | `qc_fase_10` E.1–E.5, D.8 |
| Pencairan hak = satu mutasi `out` dari **Kas Meja Bakar atau Brankas Utama** | `qc_fase_10` F.1–F.4 |
| Portal blind `/officer/dashboard` + `/officer/riwayat` (tanpa tarif & basis) | `qc_fase_10` H.1–H.6 |
| Isolasi peran *allow-list* dengan **nol tulisan** pada penolakan | `qc_fase_10` G.4–G.8 |
| Angka protokol Fase 3–9 **tidak bergeser** saat tidak ada petugas aktif | `qc_fase_10` C.1, J.3, J.4 |

### 1.1 Perintah verifikasi (semua lulus)

```bash
bash tests/run_qc.sh          # 16/16 LULUS, 989 assertion, 0 gagal (exit 0)
php tests/qc_fase_10.php      # 89/89 PASS, 0 FAIL
php database/migrate.php      # M1–M4 "sudah diterapkan", dua kali exit 0
node tests/manual/js_form_init_check.js         # 48 assertion, 0 gagal
node tests/manual/js_bridge_fee_check.js        # 65 assertion, 0 gagal
node tests/manual/js_kasbon_check.js            # 24 assertion, 0 gagal
node tests/manual/js_capital_simulator_check.js # 38 assertion, 0 gagal
node tests/manual/js_investor_check.js          # 45/45 PASS
node tests/manual/js_settlement_officer_check.js # 46 assertion, 0 gagal (probe baru Fase 10)
```

Baseline QC bergerak dari **15 suite / 900 assertion** menjadi **16 suite / 989 assertion**
(+89 assertion baru dari `qc_fase_10`). Tidak ada `storage/logs/php-*.log`.

---

## 2. Dokumen yang diperbarui (T1 — dokumen lebih dulu)

| Berkas | Perubahan |
|---|---|
| `docs/AurumFlow Entity Relationship Diagram.md` | `users.role` → **empat nilai**; § 2 kardinalitas: 4 relasi Fase 10; § 4 tabel **15** `officers` + **16** `officer_ledger_entries` (DDL + komentar aturan); blok SEED menjadi **17** dan memuat `officer_cut_total_cap_pct`; catatan migrasi ENUM empat nilai |
| `docs/AurumFlow Roadmap and QC Documents.md` | **FASE 10** (checklist 11 butir + **Protokol QC Manual 10** 15 baris) + **Sprint 10** pada matriks estimasi (total ~25–34 hari) |
| `docs/AurumFlow Product Requirement Document v3.0.md` | § 2 → empat peran + kolom matriks hak akses "Mitra Lapangan"; **§ 3.6 baru** "Mitra Lapangan & Buku Hak" (basis, tarif, cap, uang terparkir di laci, bonus akrual, pencairan, portal blind) |
| `docs/AurumFlow System Architecture Document.md` | **§ 4.8** komponen, urutan penguncian global yang diperluas, sembilan kelompok tulisan, isolasi allow-list |
| `docs/AurumFlow UI UX Documents.md` | **§ 9** Layar G (Kelola Mitra Lapangan), Layar C (kartu read-only), Layar H (portal blind) + aturan form/PWA |
| `AGENTS.md` | 9 kontrak baru Fase 10 (dua kanal pemotongan, pencairan bukan beban, cap & batas Σ tarif, `settlement_id` RESTRICT, daftar terlarang portal petugas, pintu PIN, `_once` Fase 10); urutan penguncian global diperluas; "delapan" → **sembilan** kelompok tulisan; `CACHE_NAME` **v5**; fixture & urutan pembersihan Fase 10 (`0896%`, entri → `officers`); migrasi **M4**; baseline **16 suite / 989 assertion**; daftar fase + Fase 10 |
| `README.md` | tabel migrasi **M4**, baseline & daftar suite (+`qc_fase_10`, 89 assertion), **§ 7a** "Buku hak mitra lapangan (Fase 10)" (route, aritmetika, dua kanal, isolasi blind, angka contoh) |

---

## 3. Skema basis data (T2 & T3)

### 3.1 Tabel baru — **nol ALTER** pada tabel 1–14

| Objek | Isi |
|---|---|
| **`officers`** (ke-15) | `id` CHAR(36) PK · `user_id` CHAR(36) UNIQUE (FK `users` RESTRICT) · `cut_pct` DECIMAL(5,2) · `balance_payable` DECIMAL(14,2) · `created_at`/`updated_at` · `KEY idx_officer_pct` |
| **`officer_ledger_entries`** (ke-16) | `id` · `officer_id` (FK RESTRICT) · `entry_type` ENUM(`cut`,`bonus`,`disbursement`) · `amount` DECIMAL(14,2) · `balance_after` DECIMAL(14,2) · `cut_pct` DECIMAL(5,2) NULL · `base_amount` DECIMAL(14,2) NULL · `settlement_id` (FK **RESTRICT**) · `register_id` (FK RESTRICT) · `mutation_id` (FK RESTRICT, **UNIQUE**) · `recorded_by` (FK SET NULL) · `notes` · `created_at` · 4 indeks |
| **`users.role`** | `ENUM('operator','investor','cashier','officer')` — nilai baru **di ujung** (append-only) |
| **`system_settings`** | kunci baru `officer_cut_total_cap_pct` = `100.00` |

`officer_ledger_entries.settlement_id` sengaja **RESTRICT** (berbeda dari
`operational_expenses` yang `SET NULL`): `SET NULL` akan mengaktifkan ulang bonus yang sudah
diserap sehingga laba terpotong dua kali.

### 3.2 Migrasi **M4**

| Langkah | Isi | Pemilik |
|---|---|---|
| M4a | `users.role` menerima `'officer'` (uji `INFORMATION_SCHEMA`, idempoten) | `User::ensureRoleEnum()` + `User::needsRoleEnumExtension()` (digeneralisasi: memeriksa **seluruh** `ROLE_ENUM_VALUES`) |
| M4b | dua tabel baru `CREATE TABLE IF NOT EXISTS` | `Officer::ensureSchema()` (mengembalikan 2) |
| M4c | kunci `officer_cut_total_cap_pct` | `SystemSetting::ensure()` |

`QcFixture::ensureInstalled()` memanggil jalur yang sama, sehingga basis data uji yang lebih tua
dari skema tetap lengkap.

---

## 4. Aritmetika yang diterapkan (sen integer, tanpa float)

```
G       = R − H                                    (laba kotor; boleh negatif)
basis   = max(0, G)                                (D-2: hari minus → 0)
cut_i   = floor((basis_sen × pct_perseratus + 5000) / 10000)
C       = Σ cut_i,  diskalakan bila > basis        (sisa terbesar via AllocationEngine::distribute)
A       = G − O − B − C                            (O = voucher OPEX, B = bonus akrual terserap)
Rec     = min(defisit, max(0, A));  net = A − Rec
80 : 20 = shareOf(net, 80) (tidak berubah) ; total_cash = R (tidak berubah)
```

Angka contoh (Protokol 10, seluruhnya diuji): pendapatan `37.544.000`, modal hulu `35.157.500`,
OPEX `200.000`, bonus `150.000`, petugas 2,5 % → potongan **59.662,50**, laba bersih
**1.976.837,50** (pengelola 1.581.470,00 / pool 395.367,50), saldo hak **209.662,50**,
Kas Meja Bakar **50.209.662,50** (= baseline + saldo hak), brankas 1.976.837,50.

**Dua kanal pemotongan, masing-masing sekali** (tidak ada deduksi ganda):
potongan → dibebankan saat **akrual** (`cut` + `settlement_id`), bonus → dibebankan saat
**penyerapan akrual** (`bonus` + `settlement_id` diisi tutup buku berikutnya), pencairan →
**tidak pernah** menyentuh laba (hanya kas ↔ kewajiban). Tidak ada satu pun voucher
`operational_expenses` yang dibuat untuk keduanya.

---

## 5. Berkas kode

### 5.1 Baru

| Berkas | Isi |
|---|---|
| `app/Models/Officer.php` | DDL dua tabel + `ensureSchema()`/`exists()`/`ledgerExists()`, daftar putih kolom, `lockAllForUpdate()`, `userProfiles()`, `adjustBalance()`, `updateCutPct()`, `listWithUsers()`, `countAll()` |
| `app/Models/OfficerLedgerEntry.php` | konstanta `ENTRY_*` + `TYPE_LABELS`, `insert()` (**kolom wajib/terlarang per jenis**), `lockUnabsorbedBonusesForUpdate()`, `absorbBonuses()`, `forOfficer()`, `totalsForOfficer()`, `countAll()` |
| `app/Services/OfficerService.php` | tata kelola akun (`create`, `updateCutPct`, `resetPin`, `setStatus`), `injectBonus()`, `disburse()`, `roster()`, `capHundredths()`, `previewCuts()` (**fungsi murni**), `accrueForSettlement()`, `absorbBonuses()`, `overview()`, `recentEntries()`, `openBonusCents()`, `parsePercentInput()`, `percentFieldValue()`, `mutationLabel()` |
| `app/Services/OfficerPortalService.php` | baca-saja blind: `summary()`, `history()`, `identity()`, `maskPhone()` (tanpa menyentuh utilitas pemilik sistem) |
| `app/Controllers/OfficerController.php` | Layar G: `index`, `store`, `updateCut`, `resetPin`, `setStatus`, `bonus`, `disburse` — semuanya `requireRole(ROLE_OPERATOR)` **guard-first** + CSRF kedua + `_once` + PRG |
| `app/Controllers/OfficerAuthController.php` | pintu PIN `/officer/login` lewat `PinLoginService` |
| `app/Controllers/OfficerPortalController.php` | `/officer/dashboard`, `/officer/riwayat` (GET saja, identitas dari session) |
| `app/Views/layouts/officer.php` | layout blind (dua tab, tanpa tautan operator/investor) |
| `app/Views/officer/login.php` | pintu PIN (komponen `investorLogin` yang sudah terdaftar, papan angka `x-cloak`) |
| `app/Views/officer/dashboard.php` | kartu saldo hak + ringkasan bulan + 50 entri terakhir |
| `app/Views/officer/riwayat.php` | riwayat lengkap (≤ 300 entri) |
| `app/Views/operator/petugas.php` | Layar G (ringkasan, form akun, daftar + aksi, `<details>` bonus & pencairan, buku hak terbaru) |
| `tests/qc_fase_10.php` | **suite baru** grup A–J (89 assertion) |
| `tests/manual/js_settlement_officer_check.js` | **probe Node baru** (46 assertion): cermin potongan & bonus mitra lapangan pada `settlementForm`, cap, hari minus, penjagaan kirim, dan penanda markup |

### 5.2 Berubah

| Berkas | Perubahan |
|---|---|
| `app/Config/Constants.php` | `ROLE_OFFICER = 'officer'` + label "Mitra Lapangan (Petugas)" |
| `app/Models/User.php` | `ROLE_ENUM_VALUES` (+officer), `ROLE_ENUM_DDL` (literal DDL satu tempat), `needsRoleEnumExtension()` digeneralisasi, `ensureRoleEnum()` memakai konstanta DDL |
| `app/Services/SettlementService.php` | `batchContext()` membaca roster + akrual bonus + cap; `quote()` memotong `O + B + C` dan menambah 13 kunci keluaran; `settle()` mengunci `officers` + bonus, menulis akrual & penyerapan, mencatat log/peringatan; `mutationRows()` mencatat ringkasan petugas pada `notes` |
| `app/Controllers/BaseController.php` | `roleLandingPath()`/`roleLoginPath()` memetakan `officer` |
| `app/Controllers/AuthController.php` | pintu kata sandi menolak **kasir dan petugas** secara eksplisit |
| `app/Controllers/InvestorOnboardingController.php` | `refuseCashier()` → menolak **setiap peran selain investor** |
| `app/Controllers/SettlementController.php` | meneruskan roster/bonus ke Layar C + `officer_pcts`/`officer_bonus_cents` ke Alpine + `officerSuffix()` pada flash sukses |
| `app/Services/PinLoginService.php` | `AUDIENCE_LABELS['officer']` |
| `app/Views/operator/settlement.php` | kartu read-only "Potongan & Bonus Mitra Lapangan" + dua baris pratinjau (bonus & potongan) |
| `app/Views/layouts/operator.php` | tab ke-7 "Mitra Lapangan" (operator saja) |
| `app/routes.php` | 11 route baru (5 GET + 6 POST) |
| `database/schema.sql` | ENUM empat nilai + tabel 15 & 16 |
| `database/seed.sql` | `officer_cut_total_cap_pct` |
| `database/migrate.php` | **M4** (M4a/M4b/M4c) + laporan ringkasan |
| `public/assets/js/app.js` | `settlementForm`: `officerPcts`/`officerBonusCents`, `officerCutCents`, `officerCount`, label bonus/potongan, `afterFeeCents` diperluas, `inputValid` menambah batas beban+hak ≤ pendapatan |
| `public/sw.js` | `CACHE_NAME` v4 → **v5** |
| `tests/bootstrap.php` | `Officer::ensureSchema()` + kunci cap di `ensureInstalled()`; `resetOfficerState()` dipanggil `resetTellerState()`/`clearSettlementTraces()`; fixture baru `seedQcOfficer`/`seedQcBonus`/`resetOfficerState`/`neutraliseOfficers`/`officerSnapshot` |
| `tests/run_qc.sh` | `qc_fase_10` setelah `qc_fase_9`; judul & komentar 16 suite |
| `tests/qc_fase_5/6/7/8/9/seed` | penyesuaian sengaja: 16 tabel, ENUM empat nilai, `CACHE_NAME` v5, 8 kunci `system_settings` |

---

## 6. Assertion `qc_fase_10.php` (89 assertion, 10 grup)

| Grup | Cakupan | Jumlah |
|---|---|---|
| **A** | Skema: dua tabel baru, DECIMAL & CHAR(36), FK RESTRICT/SET NULL, UNIQUE, nol FLOAT/DOUBLE, ENUM empat nilai, **`operational_expenses.mutation_id` tetap NOT NULL** (bukti nol ALTER), kunci cap, migrasi M4, 16 tabel | 11 |
| **B** | Akun: pembuatan jalur nyata, PIN ber-pepper, **gagal-tertutup tanpa pepper** (skrip anak), PIN lemah, nomor ganda, tarif 0 %, batas Σ tarif, parsing %, reset PIN, nonaktif/aktif, status invalid, akun non-officer tanpa baris buku, **penjaga konversi sen ↔ desimal** (B.14–B.16) | 16 |
| **C** | Aritmetika murni: identitas dengan Fase 9, potongan 2,5 %, net & 80 : 20, pembulatan setengah sen (Rp 25.000,03), hari minus, monotonisitas, cap & skala sisa terbesar, penjagaan Σ tarif, bonus sekali, beban+hak > pendapatan, cap rusak | 11 |
| **D** | Tutup buku: 302 PRG, laba 1.976.837,50, baris `cut` lengkap, saldo hak 209.662,50, `daily_coordination_fee` tetap 200.000, petugas 0 % tanpa baris, bonus terserap, **nol voucher baru**, tepat 2 mutasi masuk, kas meja 50.209.662,50 | 11 |
| **E** | Bonus tidak pernah dua kali: konteks bersih, tutup buku kedua (laba 146.250), satu baris `bonus`, saldo 213.412,50, nol voucher tambahan | 5 |
| **F** | Pencairan: mutasi `out` berlabel, saldo pos, entri `disbursement`, saldo hak, dua pos kas, 4 penolakan `422` dengan **nol tulisan**, bonus negatif | 7 |
| **G** | HTTP/RBAC: operator 200, kasir & petugas masuk pintu PIN masing-masing, kasir ditolak GET+POST `302`, petugas ditolak di 5 permukaan, POST petugas ditolak, nol tulisan, anonim `419`, `_once` ganda → satu entri, tiga pintu lain `401`, PinThrottle `429` | 11 |
| **H** | Isolasi blind: pemindaian statis 4 berkas (33 istilah terlarang), portal 200, kanari HTML (omzet/laba/saldo pos/tarif), tanpa POST, layout tanpa tautan, tanpa model pemilik | 6 |
| **I** | Aritmetika kas: `Δ saldo = Σ mutasi` per pos, `Σ mutasi masuk = Σ pendapatan`, `balance_payable = Σ kredit − Σ debit`, pencairan tidak mengubah laba, identitas total buku | 5 |
| **J** | Fixture: `resetOfficerState()` idempoten, entri terhapus & saldo dinetralkan, konteks bersih, angka protokol Fase 3 utuh, suite membersihkan diri | 5 |

---

## 7. Penyimpangan & keputusan eksekusi (ADDENDUM)

### A-1.1 Prefiks nomor QC petugas = `0896%`, bukan `0898`

Rencana menyebut `0898…`; ternyata `0898%` sudah dipakai `QcFixture::QC_INVESTOR_PREFIX`
(mitra pemodal). Fixture Fase 10 memakai **`0896%`** (`089600000101`, `089600000102`) supaya
`purgeQcInvestorAccounts()` tidak menghapus akun petugas dan sebaliknya.

### A-1.2 `OfficerService::percentFieldValue()` tidak memakai `number_format()`

`qc_formatter` 3.9 mewajibkan setiap `number_format()` menyebut **kedua** separator. Nilai isian
persen hanya untuk mengisi ulang field setelah `422`, jadi dihitung integer
(`intdiv`/`%` + `rtrim`), tanpa `number_format()`.

### A-1.3 `OfficerPortalService` tidak memakai `TellerService`

Daftar terlarang portal petugas memuat `TellerService`; pemindaian statis menandainya walau
hanya untuk konversi sen. Solusinya: `OfficerLedgerEntry::totalsForOfficer()` mengembalikan
**string desimal kanonik** (bukan sen), sehingga portal cukup memanggil `Formatter::rupiah()`.
Efek samping baik: satu pemilik konversi uang tetap model.

### A-1.4 Angka tutup buku kedua berbeda dari perkiraan rencana

Rencana memperkirakan laba kotor batch `1,000 gr` = Rp 130.000; kenyataannya **Rp 150.000**
karena `TellerService::quote()` mengurangi `net_cash_paid` sebesar porsi upah/konsumsi
(`Rp 20.000`), sehingga modal belanja tercatat **Rp 1.930.000**, bukan 1.950.000. Angka QC
diselaraskan: potongan `3.750`, laba bersih **146.250**, saldo hak **213.412,50**, sisa pencairan
**113.412,50**.

### A-1.5 Kanari `Rp 200.000` dihapus dari uji isolasi HTML

Nilai itu kebetulan sama dengan **total bonus petugas** (150.000 + 20.000 + 30.000) yang memang
milik petugas sendiri, sehingga bukan kebocoran. Kanari diganti nilai unik
(`1.581.470`, `395.367,50`).

### A-1.6 Definisi "cap" dipisah dua

`cap = max(0, laba kotor)` (D-2) membatasi **nominal** potongan; `officer_cut_total_cap_pct`
membatasi **Σ tarif** dan ditegakkan saat simpan tarif **serta** saat `quote()` (fail-closed).
Karena itu "skala sisa terbesar" hanya dapat terjadi akibat pembulatan per petugas (diuji
`C.7` dengan basis 101 sen), bukan karena tarif melebihi 100 %.

### A-1.7 Suite lama yang wajib disesuaikan (disengaja)

`qc_seed` (16 tabel, 8 kunci setting, ENUM 4 nilai), `qc_fase_5` (`K.1` daftar tabel),
`qc_fase_6` (`G.17`/`G.18`/`H.7`), `qc_fase_7` (`A.1`/`A.2`/`A.6`/`F.7`), `qc_fase_8` (`I.5`),
`qc_fase_9` (`G.1`/`H.5`). Tidak ada assertion yang dilonggarkan — hanya ekspektasi versi,
jumlah tabel, jumlah kunci, dan nilai ENUM.

### A-1.9 `centsToInput()` kini mempertahankan sen (bug nyata yang ditemukan probe)

Probe `tests/manual/js_settlement_officer_check.js` menemukan bahwa saran Smart Auto-Split
membuang sen (`Math.floor(value / 100)`), padahal potongan 2,5 % menghasilkan nilai bersen
(Rp 35.567.162,50 / Rp 1.976.837,50). Akibatnya kedua isian berjumlah **Rp 1 lebih kecil**
daripada kas yang diterima: `splitBalanced` palsu (tombol kirim terkunci) dan peladen akan
menolak dengan "alokasi harus berjumlah persis". Perbaikan: sisa sen ditulis sebagai koma
desimal — bentuk yang sudah diterima `SettlementService::parseMoneyInput()` dan
`AurumInput.moneyCents()`. Nilai bulat tetap dirender seperti sebelumnya, sehingga probe
Fase 2–9 tidak berubah (`48`/`65`/`24`/`38`/`45` assertion, 0 gagal).

### A-1.8 `app.min.css` tidak dikompilasi ulang

Seluruh kelas yang dipakai Layar G/H sudah ada di `app.min.css` (komponen `af-*` + utilitas yang
dipakai view lain). Tidak ada nilai arbitrary dan tidak ada kelas baru. `qc_fase_8` `I.6`
(byte-identik) tetap lulus.

---

## 8. Item terbuka (warisan rencana, belum dikerjakan)

| # | Item | Status |
|---|---|---|
| O-10.1 | Bonus akrual dipindah ke jalur voucher `operational_expenses` (butuh ALTER `mutation_id` NULL-able + `officer_id`) agar tampil di laporan OPEX | Terbuka (ditolak pada `dec-2b529bcb6a90f1db` opsi B) |
| O-10.2 | Persetujuan berjenjang (dual control) untuk bonus & pencairan besar | Terbuka |
| O-10.3 | Rekap bulanan hak petugas + ekspor CSV sisi operator | Terbuka |
| O-10.4 | Kolom agregat potongan pada `daily_settlements` | Ditolak demi nol ALTER (angka tersedia dari `Σ officer_ledger_entries`) |
| O-10.5 | Petugas mengetahui tarifnya dari kontrak → dapat menaksir laba kotor | Risiko diterima; layar tidak menampilkan basis & tarif |
| O-10.6 | Kalender hari libur (potongan mengikuti hari penjualan, bukan kalender) | Terbuka |
| O-10.7 | Reset PIN mandiri petugas / pemaksaan ganti PIN | Terbuka (butuh `users.session_epoch`) |
| O-10.8 | Notifikasi saldo hak (WhatsApp) | Terbuka |
| O-10.9 | Rekonsiliasi opname kas fisik | Terbuka sejak audit OPEX § 5.3 |

---

## 9. Checklist serah terima

- [x] `docs/` (ERD, Roadmap+QC, PRD, SAD, UI/UX) diperbarui **sebelum** kode.
- [x] `database/schema.sql` memuat tabel 15 & 16; `seed.sql` memuat `officer_cut_total_cap_pct`.
- [x] `database/migrate.php` **M4** idempoten (dua kali exit 0) dan melaporkan empat nilai ENUM.
- [x] `users.role` = `ENUM('operator','investor','cashier','officer')` (append-only, dibuktikan A.7).
- [x] Nol ALTER pada tabel 1–14 (dibuktikan A.8) dan nol `FLOAT`/`DOUBLE` (A.6).
- [x] Tutup buku menulis **sembilan** kelompok tulisan; urutan kunci global diperluas & didokumentasikan.
- [x] `quote()` tanpa petugas identik dengan Fase 9 (C.1, J.4 — diferensial, bukan asumsi).
- [x] Bonus: nol mutasi kas, nol voucher, diserap tepat sekali, `settlement_id` RESTRICT (E.*, D.8).
- [x] Pencairan: satu mutasi `out` per pencairan, dua pos, dua penjagaan saldo, `422` tanpa tulisan (F.*).
- [x] `ROLE_OFFICER` hanya menyentuh `/officer/dashboard` & `/officer/riwayat`; permukaan lain `302` tanpa tulisan (G.4–G.8).
- [x] Portal petugas bebas istilah terlarang (H.1) dan HTML-nya bebas kanari (H.3).
- [x] `CACHE_NAME` = `aurumflow-shell-v5` di `sw.js` **dan** `AGENTS.md`.
- [x] `tests/qc_fase_10.php` terdaftar; `run_qc.sh` **exit 0** (16 suite, 989 assertion, 0 gagal).
- [x] Probe Alpine lulus: lama (48/65/24/38/45) **dan** baru
      `js_settlement_officer_check.js` (46 assertion), 0 gagal.
- [x] `plan/Summary_Fase_10_Mitra_Lapangan_AurumFlow.md` (dokumen ini) ditulis.
- [x] Tidak ada kode mati: setiap method publik baru dipakai controller/service atau diuji QC.
