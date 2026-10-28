# Audit — Biaya Koordinasi Lapangan / Pengamanan Wilayah (OPEX Non-Emas)

| | |
|---|---|
| **Tanggal audit** | 2025-10-19 |
| **Ruang lingkup** | Seluruh jalur kas keluar (`cash_mutations.mutation_type = 'out'`), mesin tutup buku, mesin alokasi modal, dan pencatatan beban koordinasi/aparat |
| **Kode yang diaudit** | `app/Services/*`, `app/Models/*`, `app/Controllers/*`, `app/Views/operator/*`, `database/*`, `tests/qc_*.php` |
| **Dokumen acuan** | ERD § 4, SAD § 4.1, PRD v3.0 § 3.2–3.5 & § 4.2, Roadmap & QC, `plan/Plan_Fase_2..8`, `AGENTS.md` |
| **Sifat dokumen** | **Audit baca-saja.** Tidak ada kode, skema, view, maupun test yang diubah |
| **Baseline QC** | 14 suite, 853 assertion, 0 gagal (`bash tests/run_qc.sh`) |
| **Lingkungan** | MariaDB (bukan MySQL 8.0 — item A-1 `Summary_Fase_1`) |

> **Keputusan pemilik sistem yang mendasari audit ini:** biaya koordinasi/pengamanan dibayarkan
> **tunai dari Kas Meja Bakar (laci kasir) langsung kepada aparat/petugas lapangan**.
> Konsekuensi: uang benar-benar meninggalkan laci, sehingga model pembukuan saat ini
> (yang menganggap beban itu *ditahan dari hasil penjualan ke toko*) menghasilkan
> ketidaksesuaian buku-vs-fisik yang tidak pernah terdeteksi. Bukti aritmetiknya di § 5.5.

---

## 1. Ringkasan Eksekutif

| Aspek | Status | Bukti ringkas |
|---|---|---|
| Rumus laba bersih sudah memotong beban koordinasi sebelum bagi hasil 80 : 20 | ✅ **Benar** | `SettlementService::quote()` — `net = revenue − hulu − coord_fee − recovery` (`app/Services/SettlementService.php:174-183`) |
| Beban koordinasi tercatat sebagai **kas keluar** (`cash_mutations.out`) | ❌ **Tidak ada** | `cash_mutations` diisi dari 5 sumber, tak satu pun OPEX (§ 3) |
| Beban koordinasi dapat dicatat pada hari **tanpa** tutup buku | ❌ **Tidak bisa** | `quote()` menolak bila `holding_mg <= 0` (`SettlementService.php:155-159`) |
| Ada jejak **penerima, tanggal bayar, pos kas pembayar** | ❌ **Tidak ada** | Hanya kolom agregat `daily_settlements.daily_coordination_fee` (`database/schema.sql:159`) |
| Ada tabel/model/route untuk OPEX umum (jamuan, gas, keamanan, lain-lain) | ❌ **Tidak ada** | `grep -rniE "opex\|expense\|biaya\|pengeluaran\|petty" app/ public/` → hanya label view |
| Ada rekap anggaran bulanan (PRD § 3.3: ± Rp 5.000.000/bulan) | ❌ **Tidak ada** | Tidak ada laporan/route operator untuk beban |
| Rekonsiliasi kas fisik (opname) malam | ❌ **Tidak ada** | Tidak ada fitur hitung fisik; `cash_registers.current_balance` murni buku |
| `consumable_opex` (jamuan/gas) terpakai | ⚠️ **Kolom mati** | Selalu ditulis `'0.00'` (`SettlementService.php:463`, item O-3 warisan Fase 3) |
| Integrasi ke mesin alokasi modal (`AllocationEngine`) | ✅ **Tidak perlu diubah** | Beban tidak menyentuh `capital_utilized` maupun pool 20 % (§ 8.5) |

**Kesimpulan:** secara **laba** sistem sudah benar (PRD § 3.3 terpenuhi), tetapi secara
**kas dan auditabilitas** belum ada sama sekali. Beban koordinasi hari ini adalah
*satu angka yang diketik operator di layar rekonsiliasi*, bukan *transaksi kas*.
Rekomendasi utama: **Opsi B — buku kas OPEX berpasangan ganda** (§ 7 dan § 8), dengan
satu perubahan rumus yang tidak dapat dihindari: `total_cash = pendapatan toko` (bukan
`pendapatan − beban aparat`).

---

## 2. Ruang Lingkup, Metode, dan Batasan

### 2.1 Metode

1. **Pemetaan jalur kas keluar** — menelusuri setiap pemanggil `CashMutation::insert()` dan
   `CashRegister::adjustBalance()` di `app/`.
2. **Penelusuran alur beban koordinasi** — dari `<input name="coord_fee">` sampai kolom
   `daily_settlements.daily_coordination_fee`.
3. **Audit rumus** — membaca ulang `SettlementService::quote()`/`settle()` dan
   `AllocationEngine::apply()` terhadap PRD § 3.3, § 3.4, § 4.2.
4. **Uji aritmetika kertas** — membuktikan perilaku buku-vs-fisik pada tiga skenario
   (§ 5.4–§ 5.5).
5. **Pemindaian kesenjangan** — mencari tabel/model/route/view OPEX yang mungkin sudah ada.
6. **Analisis dampak QC** — menandai assertion Fase 1–8 yang akan bergeser (§ 10).

### 2.2 Perintah pemindaian yang dipakai

```bash
grep -rn "CashMutation::insert" app/
grep -rniE "opex|expense|biaya|pengeluaran|petty|koordinasi" app/ public/
grep -rnE "coord_fee|COORD_FEE" app/ tests/
grep -rniE "opname|hitung fisik|selisih kas|cash count" .
```

### 2.3 Batasan

- Tidak ada eksekusi `bash tests/run_qc.sh` (suite menulis ke basis data `.env`, lihat
  peringatan `AGENTS.md`). Analisis QC bersifat **statis** (membaca assertion).
- Angka contoh memakai **Protokol QC Manual 3/4** yang sudah dibekukan
  (18,050 gr @ Rp 2.080.000; beban aparat Rp 200.000; modal belanja Rp 35.157.500),
  sehingga dapat dibandingkan langsung dengan `tests/qc_fase_3.php` dan `README.md` § 7.9.
- Dokumen ini **tidak** mengubah `docs/`. Bila rekomendasi disetujui, urutan wajibnya
  adalah `docs/` lebih dulu → ADDENDUM di `plan/` → baru kode (`AGENTS.md` § *Sumber kebenaran*).

---

## 3. Kondisi Saat Ini — Peta Seluruh Sumber Kas Keluar

`cash_mutations.mutation_type` hanya memiliki dua nilai: `in` dan `out`
(`database/schema.sql:133-145`; konstanta `CashMutation::TYPE_IN`/`TYPE_OUT` di
`app/Models/CashMutation.php:17-19`). Tidak ada kolom kategori — arah dan jenis transaksi
hanya dapat dibedakan dari prefiks `source_destination` (VARCHAR 100).

### 3.1 Lima sumber `out` yang ada

| # | Service::method | Pos kas | Label `source_destination` | Tulisan dalam satu transaksi | Rujukan |
|---|---|---|---|---|---|
| 1 | `TellerService::payCash()` | Kas Meja Bakar | `Tebus hulu: <alias>` | `hulu_transactions` + `cash_registers` + `cash_mutations` | `app/Services/TellerService.php:208-289`, mutasi `:259-266`, prefiks `:44` |
| 2 | `CapitalService::transferToTeller()` | Brankas Utama (out) → Kas Meja Bakar (in) | `Pindah kas ke Kas Meja Bakar` | 2 mutasi + 2 saldo | `app/Services/CapitalService.php:176-278`, `:220-236` |
| 3 | `CapitalService::payWithdrawal()` | Brankas Utama | `Penarikan modal/dividen: <mitra>` | dompet + brankas + mutasi `out` + status `completed` | `app/Services/CapitalService.php:759-906`, `:843-850` |
| 4 | `BridgeLoanService::settleLoans()` | Kas Meja Bakar | `Kembali talangan: <pemberi>` | `bridge_loans` + saldo + mutasi `out` (per talangan) | `app/Services/BridgeLoanService.php:569-576`, prefiks `:51` |
| 5 | `KasbonService::disburse()` | Kas Meja Bakar | `Kasbon: <petani>` | `miner_loans` + `miner_loan_ledger` + saldo + mutasi `out` | `app/Services/KasbonService.php:399-406`, prefiks `:62` |

Sumber `in` (untuk kelengkapan): setoran modal (`CapitalService.php:122-129`), Pindah Kas
sisi meja (`:229-236`), penerimaan talangan (`BridgeLoanService.php:464-471`), angsuran
kasbon (`KasbonService.php:596-603`), dan dua mutasi tutup buku
(`SettlementService.php:505-527`).

### 3.2 Kesimpulan pemetaan

- **Tidak ada satu pun** `cash_mutations` `out` yang mewakili pengeluaran **non-emas**
  (koordinasi, keamanan, jamuan, gas, transport, konsumsi).
- Semua `out` yang ada adalah: pembelian aset dagang (emas), perpindahan antar pos,
  pembayaran hak mitra, pelunasan pinjaman luar, atau pencairan piutang petani.
- Karena itu **tidak ada** mekanisme yang membuat `cash_registers.current_balance`
  berkurang ketika uang laci benar-benar diserahkan ke aparat.

### 3.3 Pencarian modul OPEX

| Yang dicari | Hasil |
|---|---|
| Tabel `expenses` / `opex` / `petty_cash` | Tidak ada. Skema berisi **13 tabel** (`database/schema.sql:54,71,97,121,133,150,178,202,221,253,289,314,352`) |
| Model OPEX | Tidak ada (`app/Models/` berisi 13 model, semuanya untuk tabel di atas) |
| Route OPEX | Tidak ada (`app/routes.php`: 14 GET + 21 POST, semuanya sudah terpetakan) |
| Controller/Service OPEX | Tidak ada (`app/Controllers/`, `app/Services/`) |
| Laporan beban untuk operator | Tidak ada. `ExportController` hanya melayani mitra (`/investor/report`, `/investor/report/csv`) |
| Kunci `system_settings` untuk anggaran koordinasi | Tidak ada (`database/seed.sql:26-82`: `allocation_mode`, `operator_base_quota_pct`, `default_burn_fee`, `default_burn_fee_operator_cut`, `notice_period_days`, `active_carryover_deficit`, `daily_loan_limit`) |

---

## 4. Apa yang Sudah Ada untuk "Biaya Koordinasi"

### 4.1 Rantai lengkap (satu-satunya jejak)

```
app/Views/operator/settlement.php:254-269      <input name="coord_fee" placeholder="mis. 200.000">
        │
        ▼
app/Controllers/SettlementController.php:47    private const COORD_FEE_FIELD = '200.000'   ← saran tampilan
app/Controllers/SettlementController.php:338   defaultInput() → 'coord_fee' => COORD_FEE_FIELD
app/Controllers/SettlementController.php:355   readInput()    → 'coord_fee' => self::post('coord_fee')
        │
        ▼
app/Services/SettlementService.php:133         $coordFeeCents = self::parseMoneyInput(..., 'Beban aparat')
app/Services/SettlementService.php:176-183     afterFee = gross − fee;  net = afterFee − recovery
app/Services/SettlementService.php:197         $totalCashCents = $revenueCents - $coordFeeCents   ← HANYA pengurang kas
        │
        ▼
app/Services/SettlementService.php:462         'daily_coordination_fee' => $quote['coord_fee']
database/schema.sql:159                        `daily_coordination_fee` DECIMAL(14,2) DEFAULT 0.00
```

### 4.2 Sifat pencatatan: **pengurang pendapatan, bukan kas keluar**

- `$totalCashCents = $revenueCents - $coordFeeCents` (`:197`) adalah **satu-satunya** efek kas
  dari beban koordinasi. Angka itu menjadi patokan Smart Auto-Split:
  `brankas = max(0, net)`, `kas = total_cash − brankas` (`:207-208`), dengan validasi
  `kas + brankas == total_cash` (`:228-234`).
- **Tidak ada** `CashMutation::insert()` untuk beban koordinasi — tidak ada baris kas,
  tidak ada pengurangan `cash_registers.current_balance`.
- Artinya model sekarang mengasumsikan: *beban aparat sudah dipotong sebelum uang masuk laci*
  (toko membayar `pendapatan − beban`). Asumsi ini **bertentangan** dengan praktik lapangan
  yang dikonfirmasi (tunai keluar dari laci).
- Atribut yang **tidak** tercatat: penerima (nama aparat/kordinator), tanggal & jam bayar,
  pos kas pembayar, nomor bukti/kuitansi, catatan, pencatat (`users.id`).
- `daily_settlements.daily_coordination_fee` adalah angka **agregat per sesi tutup buku**,
  bukan per pembayaran. Satu malam dengan tiga penyerahan (aparat RT, keamanan, konsumsi)
  tetap menjadi satu angka.

### 4.3 `consumable_opex` — kolom warisan yang mati

`daily_settlements.consumable_opex` ada di ERD (`docs/AurumFlow Entity Relationship Diagram.md:226`)
dan di skema (`database/schema.sql:160`), tetapi selalu ditulis `'0.00'`
(`app/Services/SettlementService.php:463`). Tidak ada isian di form, tidak ada model yang
menulisnya. Ini **item O-3** yang diwariskan dari Fase 3 dan masih terbuka
(`plan/Summary_Fase_3_AurumFlow.md:289`, `plan/Summary_Fase_5_AurumFlow.md:133`).
Kolom ini, bila diaktifkan, menghadapi kesenjangan yang sama persis dengan beban koordinasi.

---

## 5. Dampak pada Settlement & Rekonsiliasi

### 5.1 Rumus yang berlaku sekarang (persis)

`SettlementService::quote()` — seluruhnya dalam **sen integer** (tanpa float), nilai ke
basis data selalu string desimal kanonik:

```
pendapatan      = berat_jual × harga_jual                      (:174, via TellerService::quote())
laba_kotor      = pendapatan − modal_belanja_hulu              (:175)   modal = Σ net_cash_paid holding
setelah_beban   = laba_kotor − beban_aparat                    (:176)
recovery        = min(defisit_berjalan, max(0, setelah_beban)) (:180)
laba_bersih     = setelah_beban − recovery                     (:181)
carryover_baru  = max(0, −laba_bersih)                         (:182)
defisit_berjalan= defisit_lama − recovery + carryover_baru     (:183)

pengelola_80    = laba_bersih > 0 ? round_half_away(laba_bersih × 80/100) : 0   (:192)
pool_20         = laba_bersih − pengelola_80  (selisih, bukan pembulatan terpisah) (:193)

kas_diterima    = pendapatan − beban_aparat                    (:197)   ← patokan alokasi
brankas_saran   = max(0, laba_bersih)                          (:207)
kas_saran       = kas_diterima − brankas_saran                 (:208)
```

Lalu, di dalam transaksi `settle()`: bagi hasil dihitung **lebih dulu**, baru fee talangan
diserap dari porsi pengelola (`:240-241`), dan pool 20 % didistribusikan
`AllocationEngine::apply()` (`:497-503`) berdasarkan `Σ net_cash_paid` baris `holding`
(`capital_utilized`) — beban koordinasi **tidak** ikut menentukan serapan modal.

### 5.2 Apakah mesin settlement mengizinkan pemotongan beban operasional sebelum laba bersih?

**Ya — tetapi hanya lewat satu kanal**, yaitu `daily_coordination_fee`
(`:176`). Tidak ada kanal kedua:

- `consumable_opex` = `0.00` mati (§ 4.3).
- Tidak ada tabel beban yang dibaca `quote()`/`batchContext()`; `batchContext()`
  (`:313-324`) hanya mengembalikan `holding_mg`, `holding_cost_cents`,
  `active_deficit_cents`, dan `bridge_loans`.
- Jadi laba bersih = **margin kotor emas − beban aparat** (bukan murni margin kotor), dan
  beban itu **milik satu sesi tutup buku**, bukan milik transaksi kas.

### 5.3 Bagaimana rekonsiliasi kas malam menangani selisihnya?

**Tidak menangani.** Tidak ada mekanisme hitung fisik/opname di seluruh basis kode
(`grep -rniE "opname|hitung fisik|selisih kas|cash count"` → nihil). Yang ada hanya dua
pemeriksaan, dan keduanya **bukan** rekonsiliasi fisik:

| Pemeriksaan | Lokasi | Sifat |
|---|---|---|
| `kas + brankas == pendapatan − beban` | `SettlementService.php:228-234` | Validasi **form** (jumlah dua isian), bukan hitung uang |
| `saldo buku meja + kas ≥ Σ(pokok + fee talangan)` | `SettlementService.php:439-451` | Penjagaan **saldo buku**, bukan saldo fisik |

Akibatnya: bila beban aparat diserahkan tunai dari laci, angka buku
(`cash_registers.current_balance`) **lebih tinggi** daripada uang fisik sampai sesi tutup
buku berikutnya — dan bila hari itu tidak ada emas untuk disettle, selamanya.

### 5.4 Empat mode kegagalan konkret

| # | Skenario | Perilaku sistem sekarang |
|---|---|---|
| **F-1** | Beban dibayar pada hari **tanpa** penjualan ke toko (tidak ada emas `holding`) | Tidak dapat dicatat sama sekali. `quote()` melempar `RuntimeException` (`:155-159`) → tidak ada baris beban, tidak ada baris kas. Uang hilang dari pembukuan |
| **F-2** | Nominal yang dibayar **berbeda** dari yang diketik saat settlement | Selisih tidak pernah terdeteksi. Tidak ada hitung fisik; tidak ada pembanding voucher vs deklarasi |
| **F-3** | Beban dibayar lebih awal, lalu **sebelum** settlement ada pembelian hulu | `TellerService::payCash()` memeriksa `current_balance` **buku** (`TellerService.php:239-243`). Saldo buku masih memuat uang yang sudah diserahkan ke aparat → belanja hulu dapat melebihi uang fisik di laci (laci bisa minus secara fisik) |
| **F-4** | Salah ketik nominal di layar rekonsiliasi (mis. `2.000.000` alih-alih `200.000`) | Menggeser laba bersih, bagi hasil 80 : 20, defisit, dan alokasi kas **tanpa** jejak pembanding apa pun. Tidak ada voucher, tidak ada penerima, tidak ada bukti |

Mode F-3 juga melemahkan penjagaan pengembalian talangan (`:439-451`): saldo buku yang
*overstated* dapat meloloskan pengembalian pokok + fee yang secara fisik tidak tertutup.

### 5.5 Bukti aritmetika: mengapa menambah baris kas **wajib** disertai perubahan rumus

Memakai angka Protokol QC Manual 3 (hari normal, tanpa defisit, tanpa talangan):

| Besaran | Nilai |
|---|---|
| Modal belanja hulu (`Σ net_cash_paid` holding) | Rp 35.157.500 |
| Pendapatan toko (18,050 gr × Rp 2.080.000) | Rp 37.544.000 |
| Beban aparat | Rp 200.000 |
| Laba kotor | Rp 2.386.500 |
| **Laba bersih** | **Rp 2.186.500** |
| Pengelola 80 % / pool 20 % | Rp 1.749.200 / Rp 437.300 |
| Saran alokasi sekarang (`kas` / `brankas`) | Rp 35.157.500 / Rp 2.186.500 |

**Skenario A — status quo** (beban dibayar tunai dari laci, tidak ada baris kas,
`total_cash = pendapatan − beban`):

```
Buku    : 50.000.000 − 35.157.500 (hulu)                 = 14.842.500
          + 35.157.500 (kas)                              = 50.000.000
Fisik   : 50.000.000 − 35.157.500 (hulu) − 200.000 (aparat) + 37.544.000 (toko)
                                                        = 52.186.500
          − 2.186.500 (laba dipindah ke brankas)          = 50.000.000   ✅ seimbang
```

Kesimpulan penting: **status quo seimbang** — tetapi hanya karena beban tidak pernah
dicatat sebagai kas keluar, dan hanya bila nominal yang diketik **persis** sama dengan uang
yang benar-benar diserahkan, dan hanya pada hari ada settlement (F-1/F-2/F-4 tetap terbuka).

**Skenario B — menambah `cash_mutations.out` TANPA mengubah rumus** (kesalahan yang harus dihindari):

```
Buku    : 50.000.000 − 35.157.500 − 200.000 (baris kas baru) = 14.642.500
          + 35.157.500 (kas, masih = pendapatan − beban)     = 49.800.000
Fisik   : (sama dengan skenario A)                           = 50.000.000
Selisih buku vs fisik                                        =   200.000  ❌
```

Beban dipotong **dua kali**: sekali sebagai baris kas keluar, sekali lagi sebagai pengurang
kas yang dialokasikan. **Inilah sebabnya buku kas OPEX dan rumus settlement tidak boleh
dipisah rilisnya.**

**Skenario C — buku kas OPEX + `total_cash = pendapatan` (rekomendasi):**

```
Buku    : 50.000.000 − 35.157.500 − 200.000 (baris kas) = 14.642.500
          + 35.357.500 (kas = pendapatan − brankas)      = 50.000.000  ✅ = fisik
Brankas : 0 + 2.186.500 (laba bersih)                   =  2.186.500
Total buku = 52.186.500 = total fisik                   ✅
```

`kas` menjadi **Rp 35.357.500** = modal belanja (35.157.500) **+ beban yang sudah dibayar
dari laci** (200.000). Inilah "penggantian" (reimbursement) yang membuat laci utuh kembali
tanpa mengubah laba: laba tetap Rp 2.186.500 karena beban sudah dipotong di `:176`.

Invariant baru yang harus dipegang:

```
kas + brankas                = pendapatan toko (kas yang benar-benar diterima dari toko)
kas                          = modal belanja + Σ beban dibayar dari pos ini + defisit dipulihkan
brankas                      = max(0, laba bersih)                  (tidak berubah)
laba bersih                  = pendapatan − modal − Σ beban − pemulihan defisit   (tidak berubah)
Σ cash_mutations per pos     = Δ current_balance pos tersebut       (tidak berubah)
```

> **Catatan penting (kontrol anti-fraud).** Begitu `total_cash = pendapatan`, isian
> `coord_fee` **tidak boleh lagi diketik operator**: menaikkan beban palsu akan menurunkan
> laba (brankas menerima lebih sedikit) sementara `kas = pendapatan − brankas` naik — uang
> tetap tinggal di laci. Karena itu beban **wajib diturunkan dari voucher** (`Σ` baris
> `operational_expenses` yang belum terserap), bukan dari input klien. Ini sejalan dengan
> prinsip yang sudah berlaku: *nominal dari klien tidak dipercaya* (`AGENTS.md`).

---

## 6. Kesenjangan terhadap Kebutuhan Lapangan

| Kebutuhan operasional | Kondisi sekarang |
|---|---|
| Mencatat **nominal tunai tetap** per penyerahan (mis. Rp 150.000 kepada dua petugas) | Satu angka agregat per sesi, tanpa rincian penerima |
| Mencatat beban pada **malam hari**, sebelum ada penjualan | Tidak mungkin (butuh `holding`) |
| Mengetahui **sisa saldo laci yang sebenarnya** setelah membayar aparat | Tidak mungkin (buku ≠ fisik) |
| Rekap **bulanan** terhadap anggaran PRD § 3.3 (Rp 5.000.000/bulan ≈ Rp 200.000/hari) | Tidak ada tabel, route, atau laporan |
| Bukti audit: siapa menerima, kapan, dari pos kas mana, dicatat oleh siapa | Tidak ada satu pun atribut ini |
| Memisahkan **jenis** beban (koordinasi, keamanan, jamuan, gas, lain-lain) | Tidak ada dimensi kategori |
| Menutup celah penyalahgunaan (biaya fiktif) | Tidak ada pembanding voucher |

---

## 7. Opsi Arsitektur

### Opsi A — Buku voucher OPEX **tanpa** baris kas

Tabel `operational_expenses` dicatat sebagai *memo* (penerima, tanggal, nominal, kategori).
Settlement membaca `Σ` voucher sebagai `coord_fee` (menggantikan isian manual). **Tidak**
menulis `cash_mutations` dan **tidak** mengubah rumus.

| Aspek | Penilaian |
|---|---|
| Dampak QC Fase 1–8 | **Nol** (tidak ada angka protokol yang bergeser) |
| Jejak audit (penerima/tanggal/kategori/anggaran) | ✅ diperoleh |
| Saldo buku laci vs fisik | ❌ tetap *overstated* → F-1 selesai, **F-3 tetap** |
| Menutup celah biaya fiktif | ⚠️ sebagian (deklarasi harus sama dengan voucher) |
| Nilai tambah | Sedang — cepat, tetapi tidak menyelesaikan masalah kas |

### Opsi B — Buku kas OPEX berpasangan ganda (**rekomendasi**)

Voucher + `cash_mutations.out` + `cash_registers` turun, dalam satu transaksi. Settlement
mengkredit pendapatan penuh (`total_cash = pendapatan`) dan menurunkan beban dari voucher.

| Aspek | Penilaian |
|---|---|
| Dampak QC Fase 1–8 | **Sedang** — beberapa angka protokol bergeser (§ 10) |
| Jejak audit | ✅ lengkap (voucher + baris kas + penerima + pos + pencatat) |
| Saldo buku laci vs fisik | ✅ selalu sama; F-1/F-2/F-3/F-4 tertutup |
| Menutup celah biaya fiktif | ✅ beban berasal dari voucher, bukan input klien |
| Kepatuhan | ✅ sejalan `AGENTS.md`: uang integer, satu transaksi, guard-first, `_once` + PRG |
| Nilai tambah | **Tinggi** — menyelesaikan masalah yang diaudit |

### Opsi C — Pos kas kecil terpisah (*petty cash register*)

Menambah `cash_registers` ketiga ("Kas Kecil Operasional"), diisi lewat Pindah Kas dari
Brankas; beban dibayar dari pos itu.

| Aspek | Penilaian |
|---|---|
| Dampak QC | Sedang–tinggi (asumsi "dua pos kas" tersebar di `lockByNamesForUpdate`, view, QC) |
| Saldo laci | ✅ laci tidak pernah dipakai untuk OPEX |
| Masalah | **Tidak menyelesaikan double-count**: selama settlement masih memotong beban dari kas yang dialokasikan, `total_cash = pendapatan − beban` tetap salah ketika uang benar-benar keluar dari pos mana pun (§ 5.5 skenario B berlaku umum). Menambah pos + jalur pendanaan = dua mekanisme baru tanpa manfaat tambahan |
| Nilai tambah | Rendah untuk kasus ini |

### Opsi D — Beban "di bawah garis", dibebankan hanya ke hak pengelola

Beban tidak lagi mengurangi laba bersih; dibebankan langsung ke `operator_share_80`
(mirip perlakuan `fee_uncovered` talangan).

| Aspek | Penilaian |
|---|---|
| Kepatuhan | ❌ **Melanggar PRD § 3.3** — beban koordinasi harus menjadi pengurang laba kotor sebelum dividen, ditanggung proporsional oleh seluruh modal yang berputar |
| Konsekuensi | `laba bersih` lebih besar → pool 20 % mitra menerima bagi hasil dari laba yang belum dipotong beban nyata |
| Putusan | **Ditolak** |

### 7.1 Matriks keputusan

| Kriteria | A | **B** | C | D |
|---|---|---|---|---|
| Menutup F-1 (hari tanpa settlement) | ✅ | ✅ | ✅ | ✅ |
| Menutup F-2 (selisih tak terdeteksi) | ⚠️ | ✅ | ⚠️ | ⚠️ |
| Menutup F-3 (belanja melebihi fisik) | ❌ | ✅ | ✅ | ❌ |
| Menutup F-4 (biaya fiktif) | ⚠️ | ✅ | ⚠️ | ❌ |
| Dampak QC Fase 1–8 | nol | sedang | sedang–tinggi | sedang |
| Kepatuhan PRD § 3.3 | ✅ | ✅ | ✅ | ❌ |
| Kompleksitas baru | 1 tabel + 1 service | 1 tabel + 1 service + 1 perubahan rumus | 2 mekanisme + asumsi baru | — |

**Rekomendasi: Opsi B.** Bila ingin rilis bertahap tanpa menyentuh angka protokol:
rilis **A lebih dulu** (voucher + penurunan `coord_fee` dari voucher, rumus tetap), lalu
**B** sebagai perubahan tersendiri yang sudah direncanakan re-baseline QC-nya. Yang
**tidak boleh** dilakukan adalah menulis baris kas tanpa mengubah rumus (§ 5.5 skenario B).

---

## 8. Rekomendasi Rinci (Opsi B)

### 8.1 Skema — tabel ke-14, **nol ALTER**

Mengikuti preseden Fase 8 (`miner_loan_ledger`, `database/schema.sql:352-376`):
satu baris kas = satu baris buku, dihubungkan `mutation_id`.

```sql
-- 14. operational_expenses — buku kas beban operasional non-emas (Fase 9)
CREATE TABLE IF NOT EXISTS `operational_expenses` (
    `id`            CHAR(36)      NOT NULL,
    `expense_date`  DATE          NOT NULL COMMENT 'Tanggal bisnis pembayaran (bukan created_at)',
    `category`      ENUM('coordination', 'security', 'consumable', 'other')
                                  NOT NULL DEFAULT 'coordination',
    `payee_name`    VARCHAR(100)  NOT NULL COMMENT 'Nama/alias penerima (aparat, petugas, dst.)',
    `amount`        DECIMAL(14,2) NOT NULL COMMENT 'Selalu positif; arah kas selalu keluar',
    `register_id`   CHAR(36)      NOT NULL COMMENT 'Pos kas pembayar (Fase 9: Kas Meja Bakar)',
    `mutation_id`   CHAR(36)      NOT NULL COMMENT 'Jejak silang ke cash_mutations',
    `settlement_id` CHAR(36)      NULL COMMENT 'Sesi tutup buku yang menyerap beban ini',
    `recorded_by`   CHAR(36)      NULL COMMENT 'users.id pencatat',
    `notes`         TEXT          NULL,
    `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_expense_date` (`expense_date`),
    KEY `idx_expense_open` (`settlement_id`, `expense_date`),
    KEY `idx_expense_register` (`register_id`),
    CONSTRAINT `fk_expense_register` FOREIGN KEY (`register_id`) REFERENCES `cash_registers` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_expense_mutation` FOREIGN KEY (`mutation_id`) REFERENCES `cash_mutations` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_expense_settlement` FOREIGN KEY (`settlement_id`) REFERENCES `daily_settlements` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_expense_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Alasan pilihan kunci asing:

| FK | Aturan | Alasan |
|---|---|---|
| `register_id` | `RESTRICT` | Pos kas dengan riwayat beban tidak boleh hilang |
| `mutation_id` | `RESTRICT` | **Pola `miner_loan_ledger.mutation_id`**: satu baris kas tidak boleh dihapus tanpa jejak bebannya. Konsekuensi QC: hapus `operational_expenses` **sebelum** `cash_mutations` |
| `settlement_id` | `SET NULL` | **Pola `hulu_transactions.settlement_id`**: beban adalah fakta kas yang berdiri sendiri; bila sesi QC dihapus, fakta kasnya tetap ada dan `clearSettlementTraces()` tidak perlu urutan FK baru |
| `recorded_by` | `SET NULL` | **Pola `miner_loans.issued_by`**: akun staf boleh dihapus, riwayat kas wajib terbaca |

`category` adalah ENUM: **nilai baru hanya boleh ditambahkan di ujung daftar** (aturan
append-only yang sama dengan `users.role`), agar baris lama tidak bergeser arti.

### 8.2 Service — `ExpenseService::pay()`

Satu `Database::transaction()`, tiga tulisan, urutan penguncian konsisten:

```
0. Validasi murni (tanpa DB): nominal > 0 & ≤ kapasitas DECIMAL(14,2),
   payee_name wajib & ≤ 100 karakter, category ∈ daftar putih,
   notes opsional (di-escape saat tampil).
1. Database::transaction():
   a. CashRegister::lockTellerForUpdate()            ← satu-satunya kunci (tidak ada dompet)
   b. guard: saldo_buku ≥ nominal, jika tidak → RuntimeException (422, nol tulisan)
   c. CashMutation::insert(..., TYPE_OUT, nominal, 'Biaya koordinasi: <penerima>', notes)
   d. CashRegister::adjustBalance(registerId, −nominal)
   e. INSERT operational_expenses                   ← SETELAH mutasi (FK mutation_id)
2. Logger::info() di luar transaksi (pola TellerService::payCash).
```

> Urutan c → d → e penting: `operational_expenses.mutation_id` memakai FK yang diperiksa
> InnoDB **seketika**, jadi baris kas harus lahir lebih dulu — sama dengan urutan
> `CashMutation::insert()` → `MinerLoanLedger::insert()` pada
> `KasbonService::disburse()` (`app/Services/KasbonService.php:399-415`).

- **Nominal tidak pernah dipercaya dari klien sebagai hasil hitung**: form hanya mengirim
  nominal, penerima, kategori, tanggal, catatan. Tidak ada angka turunan.
- **Parsing uang** didelegasikan ke `SettlementService::parseMoneyInput()` dan konversi ke
  `TellerService::moneyToCents()`/`centsToDecimal()` — tidak boleh ada parser ketiga
  (`AGENTS.md`).
- **Label mutasi** `Biaya koordinasi: <nama>` mengikuti pola prefiks yang sudah ada
  (`Tebus hulu: `, `Kasbon: `, `Kembali talangan: `) sehingga dapat difilter laporan.
- **`source_destination` VARCHAR(100)**: nama penerima wajib dipotong agar prefiks + nama
  tidak melebihi 100 karakter (pola `KasbonService::cut()`).

### 8.3 Route, Controller, dan View

| Komponen | Usulan |
|---|---|
| Route | `GET /operator/biaya` → `ExpenseController::index`; `POST /operator/biaya` → `ExpenseController::store` |
| Peran | `requireRole(Constants::ROLE_OPERATOR)` sebagai **pernyataan pertama** (guard-first). Kasir **tidak** termasuk allow-list → permukaannya tetap dua (`TellerController`, `KasbonController`) |
| CSRF | Diperiksa front controller + controller (dua lapis), ditambah `_once` (`Security::issueFormToken()`/`consumeFormToken()`) + **PRG wajib** |
| Layout | `layouts/operator.php`; tab ke-7 bagi operator (lihat § 10 butir 9) |
| View | `app/Views/operator/biaya.php` — kartu saldo laci, form pembayaran, daftar voucher belum terserap, rekap bulan berjalan vs anggaran |
| Nomor untuk tampilan | **Hanya** `Formatter::rupiah()`/`tanggalId()`; `Security::e()` untuk setiap output dinamis |
| Alpine | Bila butuh state, gunakan **komponen yang sudah terdaftar** (`tellerForm`/`kasbonForm`) atau state literal di view — **jangan** `Alpine.data()` baru yang berisiko belum ada di aset ter-cache (`AGENTS.md`, pengalaman `clipboardButton`) |

### 8.4 Integrasi settlement

**a. Pembacaan voucher** — perluas `batchContext()` (`SettlementService.php:313-324`)
dengan kunci `coord_fee_cents` (jumlah sen seluruh voucher `settlement_id IS NULL`) dan
`open_expenses` (daftar vouchernya, urut `expense_date ASC, created_at ASC, id ASC`, untuk
pratinjau di layar). `quote()` kemudian membaca `$context['coord_fee_cents']` — bukan
`$input['coord_fee']`.

**b. Penguncian** — di `settle()`, setelah `HuluTransaction::holdingRowsForUpdate()`
(`:399`) dan sebelum `SystemSetting::getForUpdate()` (`:419`), kunci voucher:

```
SELECT ... FROM operational_expenses
WHERE settlement_id IS NULL
ORDER BY expense_date ASC, created_at ASC, id ASC
FOR UPDATE
```

Urutan penguncian global menjadi:
`capital_wallets` → `cash_registers` → `bridge_loans` → `miner_loans` →
`hulu_transactions` → **`operational_expenses`** → `system_settings`.
Wajib dicatat di `AGENTS.md` agar tidak ada jalur baru yang mengunci terbalik.

**c. Rumus** — satu perubahan, di `quote()`:

```php
// SEBELUM (:197)
$totalCashCents = $revenueCents - $coordFeeCents;
// SESUDAH
$totalCashCents = $revenueCents;   // kas yang benar-benar diterima dari toko
```

dengan `$coordFeeCents` **berasal dari `$context['coord_fee_cents']`** (hasil penjumlahan
voucher), bukan dari `$input['coord_fee']`. Untuk menjaga kompatibilitas uji murni
(`qc_fase_3` grup A/B memanggil `quote()` langsung dengan `coord_fee`), `quote()` boleh
tetap menerima `$input['coord_fee']` **hanya bila konteks tidak menyediakannya** — seam uji,
bukan jalur produksi. `SettlementController::readInput()` berhenti mengirim `coord_fee`.

**d. Penyerapan voucher** — dua langkah yang keduanya di dalam transaksi `settle()`:
(i) `DailySettlement::insert()` (`:455-470`) menulis `daily_coordination_fee` dari jumlah
voucher yang terkunci (bukan dari input); (ii) **setelah** baris sesi lahir, `UPDATE
operational_expenses SET settlement_id = :id WHERE settlement_id IS NULL` menandai voucher
terserap (FK `settlement_id` baru terpenuhi di titik ini). Bila `consumable_opex` ikut
diaktifkan, kolom itu diisi dari subtotal voucher kategori `consumable`.

**e. Penjagaan** — tambahkan di `quote()`:

```
Σ beban (voucher) ≤ pendapatan toko   → bila tidak: 422 "Pendapatan toko tidak menutup beban…"
```
(pengganti penjagaan lama `totalCashCents < 0` di `:199-203`, yang menjadi kode mati setelah
`total_cash = pendapatan`).

**f. `AllocationEngine` tidak berubah.** Beban bukan modal belanja: `capital_utilized` tetap
`Σ net_cash_paid` baris `holding`, serapan dompet dan pool 20 % tidak terpengaruh, dan
`investor_pool_20` tetap `20 % × laba bersih` yang **sudah** dipotong beban.

### 8.5 Angka contoh setelah perubahan (Protokol QC Manual 3)

| Besaran | Sekarang | Opsi B |
|---|---|---|
| Pendapatan toko | 37.544.000 | 37.544.000 |
| Beban aparat (dari voucher) | 200.000 | 200.000 |
| Laba bersih | 2.186.500 | **2.186.500** (tidak berubah) |
| Pengelola 80 % / pool 20 % | 1.749.200 / 437.300 | **tidak berubah** |
| Kas diterima (`total_cash`) | 37.344.000 | **37.544.000** |
| Saran `kas` (Kas Meja Bakar) | 35.157.500 | **35.357.500** |
| Saran `brankas` | 2.186.500 | **2.186.500** (tidak berubah) |
| Saldo laci akhir | 50.000.000 | **50.000.000** |
| Saldo buku = fisik? | hanya bila beban tak pernah keluar dari laci | **selalu** |

Perhatikan: **laba, bagi hasil, dan seluruh distribusi mitra tidak berubah sama sekali** —
yang berubah hanya berapa banyak kas yang harus kembali ke laci (karena laci sudah
mengeluarkan uang). Ini mempersempit risiko re-baseline: hanya angka `total_cash`,
`suggested_kas`, dan penjagaan talangan yang bergeser.

### 8.6 Perlakuan `consumable_opex` (opsional, disarankan sekalian)

Aktifkan `consumable_opex` lewat jalur yang sama: voucher kategori `consumable` dijumlahkan
ke kolom itu saat settlement. Ini menutup item **O-3** yang terbuka sejak Fase 3 tanpa
menambah tabel kedua. Bila tidak ingin memperluas lingkup, biarkan `'0.00'` dan catat sebagai
keputusan sadar.

---

## 9. Kepatuhan Protokol Repo

| Aturan `AGENTS.md` | Konsekuensi pada usulan ini |
|---|---|
| **`docs/` lebih dulu**, lalu ADDENDUM `plan/` | ERD § 4 (tabel ke-14 + komentar `daily_coordination_fee`), PRD § 3.3 (perjelas "dibayar tunai dari laci"), Roadmap (Sprint 9), SAD (direktori) harus diperbarui **sebelum** kode. Tambahkan catatan ADDENDUM berisi alasan perubahan rumus `total_cash` |
| **SQL hanya di `app/Models/`** | Voucher dibaca/ditulis lewat `OperationalExpense` model; `ExpenseService` tidak menulis query. `SELECT ... FOR UPDATE` milik model (`lockOpenForUpdate()`) |
| **Uang integer, tanpa float** | `amount` DECIMAL(14,2); perhitungan sen lewat `TellerService::moneyToCents()`/`centsToDecimal()` |
| **Mutasi hanya POST + CSRF**, jalur kas memakai `_once` + PRG | `POST /operator/biaya` mengikuti pola `CapitalController::topUp()` |
| **Guard-first** | `requireRole()` mendahului CSRF kedua, `consumeFormToken()`, dan transaksi |
| **Urutan penguncian global** | `operational_expenses` disisipkan **setelah** `hulu_transactions`, **sebelum** `system_settings` — perbarui daftar di `AGENTS.md` |
| **`CACHE_NAME` wajib naik bila `app.js`/`app.min.css` berubah** | Bila view baru membutuhkan komponen Alpine atau kelas CSS baru → naikkan ke `aurumflow-shell-v4` **dan** ubah `AGENTS.md` (assertion `G.17`–`G.18` `qc_fase_6` membandingkan keduanya, `tests/qc_fase_6.php:1944-1960`) |
| **Isolasi blind mitra** | Layar beban **operator-only**; tidak ada berkas jalur mitra yang boleh menyentuh `operational_expenses`, `daily_coordination_fee`, atau istilah "koordinasi/aparat" |
| **Isolasi allow-list kasir** | `ExpenseController` memakai `requireRole(ROLE_OPERATOR)`; kasir yang membuka `/operator/biaya` → `302` ke `/operator/teller` tanpa tulisan |
| **Nominal dari klien tidak dipercaya** | Beban settlement **diturunkan dari voucher**, bukan dari isian; nominal voucher divalidasi peladen |
| **Tidak ada kode mati** | Setiap method publik baru (`pay()`, `openForSettlement()`, `monthlyRecap()`) harus punya pemanggil nyata atau assertion QC |

---

## 10. Dampak QC & Rencana Uji

### 10.1 Assertion Fase 1–8 yang bergeser (Opsi B)

| # | Berkas / assertion | Perubahan yang dituntut |
|---|---|---|
| 1 | `tests/qc_fase_3.php:512-513` — `suggested_kas` = `35157500.00`, `suggested_brankas` = `2186500.00` | `suggested_kas` → `35357500.00`; `suggested_brankas` tetap |
| 2 | `tests/qc_fase_3.php:665-673` — `total_cash_cents` protokol 3.1/3.2/3.3 | Naik Rp 200.000 (beban aparat) pada tiap protokol |
| 3 | `tests/qc_fase_3.php:2140` — daftar konvensi DECIMAL | Tambahkan `['operational_expenses','amount',14,2]` |
| 4 | `tests/qc_fase_3.php:2185` — kehadiran `consumable_opex` | Tidak berubah (kolom tetap ada) |
| 5 | `tests/qc_fase_6.php` grup 6A.5 dan penjagaan pengembalian talangan | Ambang `saldo meja setelah alokasi ≥ Σ(pokok+fee)` bergeser +Rp 200.000; tinjau setiap angka harapan |
| 6 | `tests/qc_fase_7.php:1108-1117` — payload harness | `coord_fee`/`consumable_opex` tetap boleh dikirim (diabaikan peladen) **atau** payload disesuaikan; jangan sampai berubah menjadi `422` |
| 7 | `tests/bootstrap.php:594-637` `resetTellerState()` | **Wajib** `DELETE FROM operational_expenses` **sebelum** `DELETE FROM cash_mutations` (FK `RESTRICT`). Panggil `resetExpenseState()` paling awal, pola `resetKasbonState()` (`:610`) |
| 8 | `tests/bootstrap.php:731-764` `clearSettlementTraces()` | idem; `settlement_id` memakai `SET NULL` sehingga tidak menambah urutan hapus sesi |
| 9 | `tests/qc_fase_8.php:1286-1305` (F.11) — nav sadar peran | Assertion bersifat **kehadiran label**, jadi tab ke-7 tidak menggagalkannya; tetapi judul assertion + komentar `AGENTS.md` ("enam tab") harus diperbarui |

### 10.2 Suite baru `qc_fase_9.php`

Minimal sepuluh kelompok:

| Grup | Cakupan |
|---|---|
| A | Pembayaran beban: satu transaksi, tiga tulisan, saldo laci turun, mutasi `out` berlabel benar |
| B | Penolakan: nominal 0/negatif, melebihi saldo, penerima kosong, kategori tak dikenal → `422`, **nol tulisan** (potret diferensial) |
| C | Guard-first: kasir & mitra → `302`, nol tulisan, token `_once` **tidak** termakan |
| D | CSRF/`_once`: tanpa token → `419`; kirim ulang → tidak mencatat beban kedua |
| E | Beban pada hari **tanpa** emas `holding` tetap tercatat (menutup F-1) |
| F | Settlement menyerap voucher: `settlement_id` terisi, `daily_coordination_fee` = Σ voucher, `consumable_opex` sesuai kategori |
| G | Rumus: `kas + brankas == pendapatan`, `laba` tidak berubah, `brankas` = `max(0, net)`, defisit tetap |
| H | Skema: tabel ke-14 ada, `DECIMAL(14,2)`, FK `RESTRICT`/`SET NULL` sesuai, **nol ALTER** pada tabel lama |
| I | Isolasi: layar beban tidak muncul pada sesi kasir/mitra; istilah terlarang tidak bocor |
| J | Idempotensi `database/migrate.php` (M3: tabel + kunci `system_settings` anggaran bila dibuat) |

### 10.3 Pembersihan data QC (urutan wajib)

Mengikuti pelajaran Fase 6 & 8 (`AGENTS.md` butir pembersihan):

```
operational_expenses  →  miner_loan_ledger → miner_loans → miners
                      →  bridge_loans → profit_distributions → daily_settlements
                      →  cash_mutations
```

`resetExpenseState()` dipanggil **paling awal** oleh `resetTellerState()` dan
`clearSettlementTraces()`, persis seperti `resetKasbonState()`
(`tests/bootstrap.php:610`, `:735`, `:1649-1668`).

---

## 11. Risiko & Mitigasi

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| R-1 | **Double count** — baris kas ditulis tanpa mengubah `total_cash` | Buku vs fisik selisih tepat sebesar beban (§ 5.5 B) | Rilis buku kas **dan** perubahan rumus dalam satu perubahan; assertion diferensial `kas + brankas == pendapatan` |
| R-2 | **Biaya fiktif** — beban diketik operator untuk menahan uang di laci | Uang laci berlebih, laba turun palsu | Beban **diturunkan dari voucher**; `readInput()` berhenti menerima `coord_fee`; assertion `daily_coordination_fee == Σ voucher` |
| R-3 | **Deadlock** karena urutan penguncian baru | Transaksi gagal acak | Sisipkan `operational_expenses` setelah `hulu_transactions` pada daftar global; uji dua transaksi serentak di `qc_fase_9` |
| R-4 | **FK `RESTRICT` merantai pembersihan QC** | Suite gagal pada run kedua | `resetExpenseState()` paling awal; `settlement_id` memakai `SET NULL` |
| R-5 | **Aset ter-cache** — komponen Alpine baru tidak ada di `app.js` lama | Elemen `x-cloak x-show` tak pernah muncul (gejala Fase 6) | Pakai komponen terdaftar/state literal; bila `app.js` berubah → `CACHE_NAME` v4 + `AGENTS.md` |
| R-6 | **Kebocoran ke mitra** — istilah "koordinasi/aparat" di jalur investor | Melanggar isolasi blind | Pemindai `I.*`/`E.*` diperluas ke berkas baru |
| R-7 | **Kasir mendapat akses beban** | Melanggar allow-list dua permukaan | `requireRole(ROLE_OPERATOR)` guard-first + assertion `F.*` |
| R-8 | **`total_cash < 0` menjadi kode mati** (`:199-203`) | Kode mati dilarang `AGENTS.md` | Ganti dengan penjagaan "pendapatan menutup beban" (§ 8.4e) |
| R-9 | **Voucher menumpuk lintas hari** (beban malam belum diserap) | Satu sesi menanggung beban beberapa malam → laba terlihat anjlok | Tampilkan jumlah voucher & rentang tanggal di layar rekonsiliasi sebelum eksekusi; keputusan kebijakan § 12 |
| R-10 | **Beban dari pos selain laci** (Brankas) | Rumus `kas`/`brankas` menjadi tidak umum (butuh `brankas = net + Σ fee_brankas`) | Fase 9 membatasi pembayar ke Kas Meja Bakar; generalisasi dicatat sebagai item terbuka |
| R-11 | **`daily_loan_limit`-style kunci anggaran** belum ada | Tidak ada peringatan saat anggaran bulanan terlampaui | Opsional: kunci `system_settings.monthly_coordination_budget` (aditif) + badge peringatan, bukan penolakan keras |

---

## 12. Keputusan yang Masih Terbuka

| # | Pertanyaan | Opsi | Dampak |
|---|---|---|---|
| K-1 | Apakah `coord_fee` sepenuhnya menjadi **read-only** hasil voucher? | (a) read-only penuh; (b) tetap dapat diketik sebagai *koreksi* dengan selisih tercatat | (a) menutup R-2; (b) memberi kelonggaran lapangan tetapi membuka kembali celah |
| K-2 | Jendela penyerapan voucher | (a) semua voucher `settlement_id IS NULL` (lintas hari); (b) hanya `expense_date = settlement_date` | (a) menutup F-1 tetapi membuat satu sesi menanggung beban beberapa malam; (b) membiarkan beban hari libur menggantung |
| K-3 | Pembayar selain Kas Meja Bakar | (a) hanya laci (Fase 9); (b) semua pos + rumus umum `brankas = net + Σ fee_brankas` | (a) sederhana & sesuai praktik; (b) perluasan rumus + QC lebih luas |
| K-4 | `consumable_opex` diaktifkan sekarang? | (a) ya, lewat kategori `consumable`; (b) tetap `0.00` (item O-3 warisan ditutup nanti) | (a) menutup item O-3 warisan; (b) lingkup lebih kecil |
| K-5 | Perlu kunci anggaran bulanan `system_settings`? | (a) ya (badge peringatan); (b) tidak | (a) mendukung PRD § 3.3 (Rp 5 jt/bulan); (b) tidak ada pengendalian anggaran |
| K-6 | Perlukah lampiran bukti (foto kuitansi) per pembayaran? | (a) ya, pola `receipt_photo_url`; (b) tidak, cukup catatan | (a) bukti audit kuat, butuh `storeReceipt()`-serupa; (b) lebih ringan |
| K-7 | Beban dibayar **malam** atau **siang** (terkait T+1)? | mempengaruhi `expense_date` vs `settlement_date` | Menentukan jendela K-2 |

---

## 13. Lampiran — Indeks Rujukan Kode

### 13.1 Jalur kas (`cash_mutations`)

| Berkas:baris | Isi |
|---|---|
| `app/Models/CashMutation.php:17,19` | `TYPE_IN`, `TYPE_OUT` |
| `app/Models/CashMutation.php:27-47` | `insert()` — satu-satunya penulis tabel |
| `app/Services/TellerService.php:208-289` | `payCash()` — belanja hulu (mutasi `out` di `:259-266`) |
| `app/Services/TellerService.php:239-243` | Penjagaan saldo **buku** laci |
| `app/Services/CapitalService.php:122-129` | Setoran modal (mutasi `in`) |
| `app/Services/CapitalService.php:220-236` | Pindah Kas (mutasi `out` + `in`) |
| `app/Services/CapitalService.php:843-850` | Bayar penarikan (mutasi `out`) |
| `app/Services/BridgeLoanService.php:464-471` | Penerimaan talangan (mutasi `in`) |
| `app/Services/BridgeLoanService.php:569-576` | Pengembalian talangan (mutasi `out`) |
| `app/Services/KasbonService.php:399-406` | Pencairan kasbon (mutasi `out`) |
| `app/Services/KasbonService.php:596-603` | Angsuran kasbon (mutasi `in`) |
| `app/Services/SettlementService.php:505-527` | Dua mutasi `in` tutup buku |

### 13.2 Beban koordinasi

| Berkas:baris | Isi |
|---|---|
| `app/Views/operator/settlement.php:254-269` | Isian `coord_fee` ("Potongan Koordinasi / Aparat Hari Ini") |
| `app/Views/operator/settlement.php:316-318` | Baris pratinjau "Beban aparat" |
| `app/Controllers/SettlementController.php:43-47` | `COORD_FEE_FIELD = '200.000'` (saran PRD § 3.3) |
| `app/Controllers/SettlementController.php:330-338` | `defaultInput()` |
| `app/Controllers/SettlementController.php:349-355` | `readInput()` |
| `app/Services/SettlementService.php:133` | Parsing `coord_fee` |
| `app/Services/SettlementService.php:176` | `afterFee = gross − fee` |
| `app/Services/SettlementService.php:197` | `total_cash = revenue − fee` |
| `app/Services/SettlementService.php:462-463` | Penulisan `daily_coordination_fee`, `consumable_opex = '0.00'` |
| `database/schema.sql:159-160` | Kolom `daily_coordination_fee`, `consumable_opex` |
| `docs/AurumFlow Entity Relationship Diagram.md:225-226` | DDL sumber kebenaran |
| `docs/AurumFlow Product Requirement Document v3.0.md:70-72` | PRD § 3.3 — beban koordinasi sebagai pengurang laba kotor |
| `docs/AurumFlow Product Requirement Document v3.0.md:140-151` | PRD § 4.2 — hari minus & defisit |

### 13.3 Mesin alokasi & penguncian

| Berkas:baris | Isi |
|---|---|
| `app/Services/SettlementService.php:357-550` | `settle()` — satu transaksi tujuh kelompok tulisan |
| `app/Services/SettlementService.php:377-403` | Urutan kunci: dompet → pos kas → talangan → hulu |
| `app/Services/SettlementService.php:419-426` | Kunci `active_carryover_deficit` |
| `app/Services/SettlementService.php:439-451` | Penjagaan saldo untuk pengembalian talangan |
| `app/Services/SettlementService.php:497-503` | `AllocationEngine::apply()` |
| `app/Models/CashRegister.php:78-95` | `lockTellerForUpdate()`, `lockByNameForUpdate()` |
| `app/Models/CashRegister.php:109-133` | `lockByNamesForUpdate()` — urutan nama menaik |
| `app/Models/CashRegister.php:144-151` | `adjustBalance()` — delta relatif |
| `app/Models/SystemSetting.php:76-89,100-117,129-147` | `getForUpdate()`, `set()`, `ensure()` |

### 13.4 Preseden yang dipakai sebagai pola

| Preseden | Berkas | Yang ditiru |
|---|---|---|
| Buku kas + FK `mutation_id` `RESTRICT` | `database/schema.sql:352-376` (`miner_loan_ledger`) | Skema `operational_expenses` |
| `settlement_id` `SET NULL` | `database/schema.sql:178-197` (`hulu_transactions`) | Relasi voucher → sesi tutup buku |
| `issued_by` `SET NULL` | `database/schema.sql:314-334` (`miner_loans`) | `recorded_by` |
| Service + satu transaksi + guard saldo | `app/Services/KasbonService.php:281-467` | `ExpenseService::pay()` |
| Controller guard-first + `_once` + PRG | `app/Controllers/KasbonController.php:53-108` | `ExpenseController` |
| Fixture pembersihan berurutan | `tests/bootstrap.php:594-637,731-764,1649-1668` | `resetExpenseState()` |

### 13.5 Istilah

| Istilah | Arti dalam dokumen ini |
|---|---|
| **Beban koordinasi** | Biaya tetap tunai kepada aparat/kordinator/keamanan wilayah |
| **OPEX non-emas** | Semua pengeluaran kas yang **bukan** pembelian emas hulu (koordinasi, keamanan, jamuan, gas) |
| **Voucher** | Satu baris `operational_expenses` = satu penyerahan tunai |
| **Terserap** | Voucher yang sudah `settlement_id`-nya terisi oleh sesi tutup buku |
| **Buku vs fisik** | `cash_registers.current_balance` vs uang tunai yang benar-benar ada di laci |
| **Double count** | Beban dipotong dua kali: sebagai baris kas keluar **dan** sebagai pengurang kas yang dialokasikan |

---

## 14. Penutup

- **Laba sudah benar, kas belum tercatat.** `daily_coordination_fee` memenuhi PRD § 3.3 untuk
  perhitungan bagi hasil, tetapi ia bukan transaksi kas: tidak ada `cash_mutations`,
  tidak ada pengurangan saldo laci, tidak ada penerima, tidak ada tanggal bayar.
- **Dengan praktik tunai-dari-laci yang dikonfirmasi, model sekarang hanya seimbang secara
  kebetulan** — ketika nominal yang diketik sama persis dengan uang yang diserahkan, dan
  hanya pada hari ada settlement.
- **Rekomendasi: Opsi B** — tabel ke-14 `operational_expenses` + `ExpenseService::pay()`
  (satu transaksi, tiga tulisan) + route `/operator/biaya` operator-only + perubahan rumus
  `total_cash = pendapatan`. Laba, bagi hasil 80 : 20, pool 20 %, dan seluruh distribusi
  mitra **tidak berubah**; yang berubah hanya jumlah kas yang harus kembali ke laci.
- **Yang tidak boleh dilakukan:** menulis baris kas OPEX tanpa mengubah rumus
  (§ 5.5 skenario B menghasilkan selisih buku-vs-fisik tepat sebesar beban setiap hari).
- **Urutan eksekusi wajib:** `docs/` → ADDENDUM `plan/` → skema + `database/migrate.php` (M3)
  → model + service → controller + view → integrasi settlement → `qc_fase_9` +
  re-baseline assertion § 10.1 → `bash tests/run_qc.sh` exit 0 tanpa `storage/logs/php-*.log`.

**Dokumen ini tidak mengubah kode, skema, view, maupun test. Keputusan implementasi menunggu
persetujuan pemilik sistem atas § 7 dan § 12.**
