# Plan Fase 4 — AurumFlow

> **Dokumen Perencanaan Eksekusi**
> Proyek: **AurumFlow** — sistem manajemen operasional privat untuk perdagangan emas aluvial dengan settlement **T+1**.
> Fase: **4 — Mesin Alokasi Modal (Waterfall & Pro-Rata Engine) & Tata Kelola Likuiditas**
> Status: `APPROVED FOR PLANNING` · **belum ada kode PHP yang dieksekusi.**

| Item | Nilai |
|---|---|
| Runtime | Native PHP **8.2+** (`declare(strict_types=1)`), tanpa framework, tanpa Composer/`vendor/` |
| Frontend | Tailwind CSS **3.4.19** (CLI, dikompilasi lokal) + Alpine.js **3.17.4** (aset lokal, tanpa CDN) |
| Database | MySQL 8.0 / MariaDB — **9 tabel ERD tidak berubah, nol DDL, nol migrasi** |
| Scope baru | Route `/operator/capital` (GET + 5 POST), `AllocationEngine`, `CapitalService`, hook ke `SettlementService::settle()` |
| Sumber kebenaran | ERD → SAD → Roadmap (Protokol QC Manual 4) → PRD → UI/UX (Layar E) |
| Keputusan pengguna | `dec-3c33eba5e3945873` (lihat §2.1) |
| Bahasa dokumen | Prosa Indonesia + istilah teknis Inggris (konvensi `Plan_Fase_1..3`) |

---

## 1. Ringkasan Fase 4

### 1.1 Tujuan

Fase 3 menutup siklus T+1 dan menuliskan bagi hasil **80 : 20 hanya sebagai pembukuan**. Fase 4 memindahkan porsi **20 % pool mitra** ke tempat yang benar — `capital_wallets.balance_profit` masing-masing dompet yang modalnya terserap — dan mengubah uang setoran mitra menjadi kas yang benar-benar bisa dibelanjakan kasir.

Empat hal yang wajib benar:

1. **Waterfall Engine** — kebutuhan belanja satu putaran diserap berurutan: **Pengelola (kuota 50 % dari `system_settings.operator_base_quota_pct`) → Mitra Prioritas (kuota terkunci per dompet, `locked_quota_pct`) → Mitra Reguler (pro-rata sisa)**. Semua hitungan dalam **sen integer**; tidak ada float.
2. **Distribusi pool 20 % yang presisi** — dibagi pro-rata atas modal mitra yang benar-benar bekerja, dengan **largest remainder**, sehingga `Σ profit_earned == daily_settlements.investor_pool_20` **tanpa selisih sen**, dan `Σ capital_utilized == modal belanja putaran`. Dompet Pengelola menyerap tapi **tidak** mengambil jatah pool (PRD § 3.4) — serapannya tetap tercatat sebagai baris `capital_utilized`.
3. **Layar E `/operator/capital`** — Setor Modal Baru, Pindah Kas (Brankas → Meja), tabel portofolio + kuota terkunci, **What-If Simulator**, dan antrean penarikan H-7 dengan eksekusi bayar.
4. **Nol DDL.** `family` tetap `family` di database; yang berubah hanya label: **`family` → "Mitra Prioritas"**, **`external` → "Mitra Reguler"**, **`operator` → "Pengelola"**.

Secara konkret, Fase 4 harus menghasilkan:

1. `app/Services/AllocationEngine.php` — waterfall + pro-rata + `apply()` di dalam transaksi settlement.
2. `app/Services/CapitalService.php` — jalur uang baru: setoran masuk, pindah kas, penarikan (kas keluar brankas).
3. `app/Controllers/CapitalController.php` + `app/Views/operator/capital.php` — Layar E (empat bagian, mobile-first, "Obsidian Gold").
4. Navigasi operator **`[Kasir] | [Rekonsiliasi] | [Kelola Modal]`** + pintu role-aware di dashboard.
5. Suite QC otomatis `tests/qc_fase_4.php` — **Protokol QC Manual 4** dengan angka persis, plus 10 grup assertion (§ 6).

### 1.2 Definisi Selesai (Definition of Done)

- [ ] `GET /operator/capital` menampilkan Layar E; `POST /operator/capital/{topup,mitra,quota,transfer,withdrawal}` masing-masing ber-PRG + flash.
- [ ] Investor → `302` ke `/dashboard` untuk **semua** method (`GET` maupun `POST`), tanpa satu tulisan pun.
- [ ] Navigation header operator memuat tiga item: **Kasir | Rekonsiliasi | Kelola Modal**.
- [ ] Setoran modal (Top-Up) = **satu** `Database::transaction()`: `capital_wallets.balance_capital` + `cash_registers` (Brankas Utama) + `cash_mutations` (`in`). Gagal di tengah → **nol** perubahan (diuji, bukan diasumsikan).
- [ ] Pindah Kas Brankas → Meja = satu transaksi dua pos + **dua** mutasi (`out` brankas, `in` meja) dengan satu `transfer=<uuid>` pada `notes`.
- [ ] Waterfall QC 4 (empat dompet Roadmap) menghasilkan **Rp 17.578.750 / Rp 7.031.500 / Rp 6.204.264,71 / Rp 4.342.985,29**, `Σ capital_utilized = Rp 35.157.500`, `uncovered = Rp 0`.
- [ ] Pool `Rp 437.300` terdistribusi **Rp 174.920 / Rp 154.341,18 / Rp 108.038,82**, `Σ = Rp 437.300` tepat, dan `balance_profit` tiap dompet bertambah persis sebesar itu.
- [ ] Hari minus: `investor_pool_20 = 0.00` → baris `profit_distributions` bernilai `0.00`, **tidak ada** `balance_profit` yang bertambah, `balance_capital` **tidak pernah** berkurang.
- [ ] Penarikan modal pokok ditolak `422` sebelum `notice_period_due` (**H-7 hari kerja**, Sabtu/Minggu dilewati); setelah jatuh tempo dan disetujui, pembayaran menggerakkan kas Brankas + mutasi `out` + `balance_profit`/`balance_capital` turun + `status='completed'` **dalam satu transaksi**.
- [ ] `bash tests/run_qc.sh` exit `0` (**10 suite**), tanpa `storage/logs/php-*.log`, `git status` bersih dari artefak.
- [ ] `docs/` (UI/UX, PRD, Roadmap, SAD) sudah memuat keputusan Fase 4 (§ 5 T1) **dan** AGENTS.md/README diperbarui.
- [ ] Tidak ada perubahan `database/schema.sql`, `database/seed.sql`, maupun dokumen ERD.

### 1.3 Di Luar Ruang Lingkup Fase 4

- **Onboarding investor** (`invite_token`, halaman edukasi, clickwrap waiver, IP/User-Agent, PIN 6 digit) dan **dasbor blind investor** — Fase 5.
- **Pengajuan penarikan dari sisi investor** (tombol "Tarik Modal" di dasbor) — Fase 5; di Fase 4 pengajuan dicatat operator atas nama mitra.
- **Mode `allocation_mode = 'prorata'`** — Fase 4 hanya `waterfall`; nilai lain ditolak dengan pesan eksplisit (item O-1).
- Panel admin penuh untuk mengubah `system_settings` (kuota dasar pengelola, masa tunggu) via UI — masih phpMyAdmin.
- Ekspor laporan (PDF/CSV), PWA (`manifest.json`, `sw.js`, ikon), kalender hari libur untuk H-7 (item O-2).
- Item Fase 3 yang tetap terbuka: O-2 beban aparat sebagai `system_settings`, O-3 `consumable_opex`, O-6 retensi berkas nota.

---

## 2. Keputusan & Asumsi Terkunci

### 2.1 Keputusan pengguna (`dec-3c33eba5e3945873`)

| Keputusan | Pilihan | Konsekuensi |
|---|---|---|
| Provisioning dompet & mitra | **Dompet Pengelola otomatis dibuat `database/install.php` + form ringkas "Tambah Mitra"** (membuat baris `users` role `investor` + `capital_wallets`) di `/operator/capital` | Layar langsung dapat dipakai operator; `invite_token`/`onboarding_signed_at` dibiarkan kosong karena onboarding tetap milik Fase 5 |
| Kedalaman penarikan | **Antrean + eksekusi bayar**: kas keluar Brankas Utama + mutasi `out` + `balance_profit`/`balance_capital` turun + `status='completed'`; penarikan **modal pokok** menunggu jatuh tempo **H-7** | Menambah satu jalur mutasi kas (brankas) dengan penguncian dompet; payout mustahil dobel |
| Kas setoran | **Setor → Brankas Utama**, plus fitur **"Pindah Kas" Brankas → Kas Meja Bakar** (dua pos + dua mutasi) | Menutup siklus operasional (setor → pindah → belanja malam → settlement) tanpa sunting DB manual |

### 2.2 Asumsi arsitektural yang dikunci

- **A-1 — Alokasi bersifat *notional* dan *ex-post*.** Skema ERD tidak punya kolom "modal sedang bekerja", sehingga `balance_capital` **tidak** didebit saat belanja malam. Alokasi dihitung saat **settlement** atas modal yang benar-benar keluar (`Σ net_cash_paid` baris `holding` yang disettle) dan dicatat pada `profit_distributions.capital_utilized`. "Modal yang bekerja" untuk Fase 5 direkonstruksi dari baris `profit_distributions` milik sesi terakhir.
- **A-2 — Kebutuhan kas = pemilik tunggal yang sama dengan Fase 3.** `needCents` = `quote['holding_cost_cents']` (`Σ net_cash_paid` baris `holding` terkunci) — bukan angka dari klien, bukan dari gram.
- **A-3 — Aritmetika sen integer + largest remainder.** Semua persentase dihitung `intdiv` lalu sisa sen dibagikan ke sisa pecahan terbesar (seri: saldo lebih besar → `id` menaik). Invariant yang diuji: `Σ capital_utilized + uncovered == need`; `Σ profit_earned == investor_pool_20` (bila ada dompet mitra yang menyerap).
- **A-4 — Urutan waterfall & pembatasan (clamp).**
  1. **Pengelola** (`tier='operator'`): `capacity = floor(need × operator_base_quota_pct / 10000)`; serap `= min(capacity, saldo, sisa)`. Dompet operator ganda diserap berurutan (saldo menurun, `id` menaik).
  2. **Mitra Prioritas** (`tier='family'`): per dompet, `capacity_i = floor(need × locked_quota_pct_i / 10000)`; serap `= min(capacity_i, saldo_i, sisa)`; urutan tetap: kuota menurun → saldo menurun → `id` menaik. Kuota per dompet (bukan pool), jadi jumlah kuota prioritas boleh > 50 % — selalu dibatasi `sisa`.
  3. **Mitra Reguler** (`tier='external'`): pro-rata saldo atas `sisa`, dengan largest remainder.
  4. **Sisa tak terserap** (`Σ saldo < need`) dilaporkan sebagai **`uncovered`** — tidak ditulis sebagai baris, dicatat pada `notes` mutasi + `Logger::warning`. Jaminan: tidak ada alokasi melebihi saldo dompet.
- **A-5 — Pengelola tidak mengambil jatah pool** (PRD § 3.4), tetapi **serapannya tetap dicatat** sebagai baris `profit_distributions` (`profit_earned = 0.00`) — inilah bukti "Serapan Modal Pengelola 50 %" pada Protokol QC Manual 4 dapat diverifikasi dari basis data.
- **A-6 — Baris hanya ditulis untuk dompet dengan `capital_utilized > 0`.** Akibat penting: instalasi/pengujian **tanpa dompet bermodal tidak menulis apa pun** → perilaku Fase 3 tetap sah (lihat A-17).
- **A-7 — Urutan penguncian global (berlaku semua jalur uang Fase 4):** `capital_wallets` (`id` ASC) → `cash_registers` (nama ASC, `lockByNamesForUpdate()`) → `hulu_transactions` (`FOR UPDATE`) → `system_settings` (`getForUpdate`). Semua di dalam `Database::transaction()`; tidak ada `beginTransaction()` manual. Karena itu `settle()` mengunci dompet **sebelum** pos kas.
- **A-8 — Tiga jalur uang baru, masing-masing SATU transaksi.**
  - **Top-Up**: kunci dompet → kunci Brankas → `cash_mutations` (in) → `balance_capital += amount` → `cash_registers += amount`.
  - **Pindah Kas** (Brankas → Meja): kunci dua pos (urutan tetap) → cek saldo brankas → dua `UPDATE` saldo → dua `cash_mutations` (`out` brankas, `in` meja).
  - **Bayar Penarikan**: kunci dompet → kunci Brankas → cek saldo kas & sisa saldo dompet → `balance_profit`/`balance_capital -= amount` → `cash_registers -= amount` → `cash_mutations` (out) → `withdrawal_requests.status='completed'` → (`capital` saja) `withdrawal_status='completed'`.
- **A-9 — Notice period = `system_settings.notice_period_days` (7) hari **kerja**, melewati Sabtu & Minggu** (PRD § 4.3 "H-7 hari kerja"). Tanpa kalender hari libur nasional (item O-2). `withdrawal_status='queued'` + `withdrawal_request_date` hanya untuk penarikan **modal pokok**; maksimum **satu** pengajuan modal terbuka per dompet.
- **A-10 — `allocation_mode` harus `'waterfall'`.** Nilai lain → `RuntimeException` dengan pesan siap tampil dan **nol tulisan** (settlement digulung). Mode `prorata` PRD § 4 belum diimplementasikan (item O-1).
- **A-11 — Label tier punya satu pemilik:** `CapitalWallet::TIER_LABELS` (`operator`→"Pengelola", `family`→"Mitra Prioritas", `external`→"Mitra Reguler"). View, controller, dan flash **tidak pernah** mencetak nilai enum mentah.
- **A-12 — Konvensi sama dengan Fase 2/3.** `requireRole('operator')` → pelanggar diarahkan ke `/dashboard`; sukses = PRG + flash; gagal validasi = `422` + isian terpelihara; seluruh output dinamis `Security::e()`; seluruh angka tampilan `Formatter`; `_once` hanya pada jalur yang menggerakkan kas (topup, transfer, bayar penarikan); **kontrol form tidak pernah `:disabled`** (`:readonly="submitting"` + kelas redup); placeholder numerik berawalan `mis. `.
- **A-13 — Peladen satu-satunya kalkulator.** What-If Simulator hanya pratinjau klien (`capitalSimulator` di `app.js`); yang tersimpan selalu hasil hitung ulang `AllocationEngine` dari basis data.
- **A-14 — Baris "Tambah Mitra" tanpa setoran.** Membuat `users` (role `investor`) + `capital_wallets` (tier dipilih, kuota 0 bila `external`); `password_hash` = hash dari token acak yang tidak diungkapkan (akun belum dapat dipakai sampai Fase 5) dan `invite_token` dibiarkan `NULL` — Fase 5 pemiliknya. Setoran dicatat terpisah di kartu "Setor Modal Baru".
- **A-15 — `capital_wallets.locked_quota_pct` dompet `external` dipaksa `0.00`** dan isian kuota di layar bersifat `readonly` untuk non-Prioritas; peladen mengabaikan/menolak nilai kuota untuk tier selain `family`.
- **A-16 — Satu pemilik konversi uang.** `TellerService::centsToDecimal()` dijadikan `public` (pasangan `moneyToCents()`), dan salinan privat di `SettlementService` dihapus agar tidak ada salinan ketiga di `AllocationEngine`/`CapitalService`.
- **A-17 — Assertion Fase 3 yang berubah makna** (wajib, bukan opsional): `qc_fase_3.php` P3.10c ("Modal investor TIDAK disentuh") dan kolom detail tabel cetak "capital_wallets & profit_distributions tidak disentuh" **berubah makna** sejak settlement memanggil `AllocationEngine`. Karena tidak ada dompet bermodal pada keadaan QC 3 (A-6), hasil nyatanya tetap **0 baris distribusi**; yang diperbarui adalah label/penjelasan assertion + kolom detail Protokol QC Manual 3 pada Roadmap.
- **A-18 — Tanpa kode mati.** Setiap method publik baru punya pemanggil nyata atau assertion QC (aturan `AGENTS.md`).
- **A-19 — Cermin Rupiah JS diperbaiki.** `AurumFormat.rupiah()` kini **membulatkan** (bukan memotong) sen ke rupiah penuh, agar sama dengan `number_format()` milik `Formatter`. Tanpa ini, angka pro-rata berdesimal (mis. `Rp 108.038,82`) akan berbeda Rp 1 antara pratinjau klien dan peladen.
- **A-20 — QC tetap dapat dijalankan berulang.** Fixture baru (`QcFixture::resetCapitalState()`, `QcFixture::seedQcWallets()`) bersifat idempotent, dan karena `QcFixture::ensureInstalled()` menerapkan ulang `schema.sql`, keadaan dompet selalu dapat dikembalikan ke titik nol.

---

## 3. Terminologi & Pemetaan (nol migrasi)

| DB (`capital_wallets.tier`) | Label UI | Peran dalam waterfall | Pool 20 % |
|---|---|---|---|
| `operator` | **Pengelola** | Prioritas kuota dari `system_settings.operator_base_quota_pct` (50,00 %) | Tidak menerima |
| `family` | **Mitra Prioritas** | Kuota terkunci per dompet (`locked_quota_pct`) | Ya, pro-rata modal bekerja |
| `external` | **Mitra Reguler** | Pro-rata saldo atas sisa kebutuhan | Ya, pro-rata modal bekerja |

`capital_wallets.tier` **tidak diubah** (`ENUM('operator','family','external')`), `schema.sql` dan `seed.sql` tidak disentuh, dokumen ERD tidak disentuh. Pemetaan hanya hidup di `CapitalWallet::TIER_LABELS` + dokumen PRD/UI-UX (dan dinyatakan eksplisit, tidak diam-diam).

---

## 4. Keputusan Teknis & Konvensi Fase 4

### 4.1 Aritmetika alokasi (rumus yang dibekukan)

```
needCents            = Σ net_cash_paid (sen) baris holding yang disettle
opQuotaHundredths    = system_settings.operator_base_quota_pct → 5000  (50,00 %)
opCapacityCents      = intdiv(needCents * opQuotaHundredths, 10000)          // 1.757.875.000
famCapacityCents_i   = intdiv(needCents * quotaHundredths_i, 10000)          // per dompet
absorbed_i           = min(capacity_i, saldo_capital_i, sisa)
sisa                 = needCents − Σ absorbed
uncovered            = sisa bila tidak ada saldo dompet yang mampu menutupnya
proRata(cents, saldo[]) = floor(cents × saldo_i / Σ saldo) + largest remainder
```

Aturan pembagian **largest remainder** (dipakai untuk Mitra Reguler dan untuk pool 20 %):
1. `base_i = intdiv(cents × saldo_i, Σ saldo)`; `rem_i = (cents × saldo_i) mod Σ saldo`.
2. `leftover = cents − Σ base_i`.
3. `leftover` sen dibagikan satu-satu ke `rem_i` terbesar; seri diputus `saldo_i` lebih besar, lalu `id` menaik.

**Ini berarti persentase pada Protokol QC Manual 4 versi lama (40,00 % / 35,29 % / 24,71 %) tidak dipakai** — angka persen bulat itu membuat tabel dokumen berbeda Rp 2 dari hasil sen yang eksak. Tabel Roadmap akan diganti nilai eksak (§ 6.1).

### 4.2 Angka acuan Protokol QC Manual 4 (empat dompet Roadmap)

Dompet uji (`balance_capital`): **Pengelola Rp 30.000.000** (kuota 50 % dari setting), **Mitra Prioritas Rp 16.000.000** (kuota terkunci 20 %), **Mitra Reguler A Rp 20.000.000**, **Mitra Reguler B Rp 14.000.000**. Belanja putaran = **Rp 35.157.500** (data QC 2), pool mitra 20 % = **Rp 437.300** (data QC 3).

**Serapan modal (waterfall):**

| Tahap | Perhitungan | Sen | Tampilan |
|---|---|---|---|
| Pengelola | 50 % × 35.157.500 | `1757875000` | **Rp 17.578.750** |
| Mitra Prioritas | 20 % × 35.157.500 | `703150000` | **Rp 7.031.500** |
| Sisa (30 %) | 35.157.500 − 24.610.250 | `1054725000` | **Rp 10.547.250** |
| → Mitra Reguler A | pro-rata 20/34 (`local 10/17`: `a=620426470`, `rem=10`) | `620426471` | **Rp 6.204.264,71** → Rp 6.204.265 |
| → Mitra Reguler B | sisa pembagian (`b=434298529`, `rem=7`) | `434298529` | **Rp 4.342.985,29** → Rp 4.342.985 |
| `Σ capital_utilized` | — | `3515750000` | **Rp 35.157.500** (= belanja, `uncovered = 0`) |

**Distribusi pool 20 % (`mitraBase = 703150000 + 620426471 + 434298529 = 1757875000`):**

| Dompet | `base` (sen) | `rem` | Final (sen) | Tampilan |
|---|---|---|---|---|
| Mitra Prioritas | `17492000` | 0 | `17492000` | **Rp 174.920** |
| Mitra Reguler A | `15434117` | `1155455000` | `15434118` | **Rp 154.341,18** → Rp 154.341 |
| Mitra Reguler B | `10803882` | sisa | `10803882` | **Rp 108.038,82** → Rp 108.039 |
| Pengelola | — | — | `0` | **Rp 0** (tidak menerima pool) |
| **Σ** | | | **`43730000`** | **Rp 437.300** = `investor_pool_20` |

> **Catatan penting (temuan dokumen).** `Σ modal bekerja mitra` (**Rp 17.578.750**) kebetulan **sama persis** dengan serapan Pengelola pada contoh ini (20 % + 30 % = 50 %). Angka itu **bukan** bukti kebenaran engine; QC wajib menguji kasus di mana kedua nilai berbeda (mis. kuota prioritas 30 %).

### 4.3 Parser input lapangan layar modal

| Field | `inputmode` | Aturan | Contoh |
|---|---|---|---|
| Nominal setoran / transfer / penarikan | `numeric` | Parser nominal Fase 3 (titik = ribuan, koma = desimal, maks 2 desimal, **> 0** untuk jalur uang) | `10.000.000` → `'10000000.00'` |
| Kuota terkunci Mitra Prioritas | `decimal` | Satu pemisah = desimal, maks 2 desimal, `0 ≤ q ≤ 100` (DECIMAL(5,2)); hanya berlaku untuk `tier='family'` | `20` → `'20.00'` |
| Nama mitra | teks | 3–100 karakter, `Security::e()` saat tampil, `mb_substr` saat ditulis | `Bpk. Ahmad` |
| Nomor telepon | `tel` | Pola `install.php`: `^[0-9+\-\s]{8,20}$`, wajib unik | `081234567890` |

### 4.4 Hook settlement (lima tulisan → **enam** kelompok tulisan)

`SettlementService::settle()` berubah **hanya** pada dua titik:

1. **Urutan penguncian**: di dalam transaksi, `CapitalWallet::lockAllForUpdate()` (id ASC) dipanggil **sebelum** `CashRegister::lockByNamesForUpdate()` (A-7). Sisa urutan Fase 3 tidak bergeser.
2. **Setelah seluruh tulisan lama** (`daily_settlements`, `hulu_transactions`, dua `cash_registers`, dua `cash_mutations`, `SystemSetting::set()`), panggil:
   ```php
   $allocation = AllocationEngine::apply($pdo, $settlementId, $quote['holding_cost_cents'], $quote['investor_pool_cents'], $lockedWallets);
   ```
   `apply()` menulis `profit_distributions` (satu baris per dompet dengan `capital_utilized > 0`) + `balance_profit += profit_earned`, lalu mengembalikan ringkasan (`wallets`, `profit_total`, `uncovered`, `pool_unallocated`).
3. Ringkasan alokasi masuk ke `notes` mutasi brankas, `Logger::info('Settlement harian tersimpan', … 'pool_dompet' => n)`, dan flash sukses ("…pool Rp 437.300 terbagi ke 3 dompet mitra"). Bila `uncovered > 0` atau `pool_unallocated` → `Logger::warning` (satu baris, tanpa data sensitif).

Kontrak `AGENTS.md` "Tutup buku selalu lima tulisan" **diperbarui menjadi enam kelompok** (bagian dari T1).

### 4.5 Alur request (ringkas)

```
POST /operator/capital/topup
  → requireRole('operator') → CSRF (front controller) → _once → validasi field
  → CapitalService::topUp(): Database::transaction()
      lock wallet(id) → lock 'Brankas Utama' → insert cash_mutations(in)
      → capital_wallets.balance_capital += amount → cash_registers += amount
  → PRG + flash

POST /operator/capital/transfer
  → CapitalService::transferToTeller(): transaction
      lockByNamesForUpdate(['Brankas Utama','Kas Meja Bakar']) → cek saldo
      → 2 × adjustBalance → 2 × cash_mutations(out,in) notes: transfer=<uuid>
  → PRG + flash        (422 bila saldo brankas < nominal)

POST /operator/capital/withdrawal   (aksi: ajukan|setujui|tolak|bayar)
  ajukan  : notice_period_due = +notice_period_days hari kerja (A-9); status pending;
            modal → withdrawal_status='queued' + withdrawal_request_date (maks 1 terbuka)
  setujui : pending → approved        tolak: pending → rejected (+ modal: kembali 'none')
  bayar   : approved && (profit || due ≤ today) → CapitalService::payWithdrawal()
            transaction: lock wallet → lock brankas → cek saldo → dompet −amount
            → brankas −amount → cash_mutations(out) → status='completed'
  → PRG + flash        (422: kas brankas kurang, saldo dompet kurang, belum jatuh tempo)
```

### 4.6 Layar E — struktur bagian

```
┌───────────────────────────────────────────────┐
│ ≡ KELOLA MODAL & LIKUIDITAS                   │  ← nav: Kasir | Rekonsiliasi | Kelola Modal
│ Kas Meja: Rp 14.842.500   Brankas: Rp 2.186.500
├───────────────────────────────────────────────┤
│ A. SETOR MODAL BARU            (POST /topup)  │  mitra (select), nominal, catatan
├───────────────────────────────────────────────┤
│ B. PORTOFOLIO MITRA                           │  tabel: tier | nama | modal | dividen |
│    [+ Tambah Mitra]            (POST /mitra)  │         kuota terkunci (readonly non-prioritas)
├───────────────────────────────────────────────┤
│ C. PINDAH KAS → MEJA           (POST /transfer)│
├───────────────────────────────────────────────┤
│ D. SIMULATOR "BELANJA MALAM INI"              │  isian dummy (prefill = modal holding riil)
│    Pengelola / Mitra Prioritas / Mitra Reguler│  Alpine `capitalSimulator` (sen integer)
│    + baris per dompet Reguler + sisa tak terserap |  tabel proyeksi peladen = tanpa JS
├───────────────────────────────────────────────┤
│ E. ANTREAN PENARIKAN (H-7)     (POST /withdrawal)│  status, jatuh tempo, sisa hari, aksi
└───────────────────────────────────────────────┘
```

Kontrak form (diuji QC grup H): nilai awal dirender peladen sebagai `value="…"` dan dibaca `init()` lewat `formFieldValue()`; contoh nilai berawalan `mis. `; `x-model.fill` untuk isian bernilai awal; tidak ada `:disabled`.

---

## 5. Tugas Eksekusi (Step-by-Step)

### T1 — Dokumentasi lebih dulu (aturan `AGENTS.md`: perbarui `docs/` sebelum menyimpang dari kode)

| Berkas | Perubahan |
|---|---|
| `docs/AurumFlow UI UX Documents.md` | **+ `### Layar E: Kelola Modal & Likuiditas (Capital Control)`** setelah Layar D: wireframe empat bagian (Setor, Portofolio+Kuota, Pindah Kas, Simulator, Antrean H-7), tabel pemetaan tier→label, aturan nilai awal form (`mis. `, dirender peladen, tanpa `:disabled`), acuan `plan/Plan_Fase_4_AurumFlow.md` |
| `docs/AurumFlow Product Requirement Document v3.0.md` | Istilah: "Kuota Kerabat Khusus" → "Kuota Mitra Prioritas" (§ 2); diagram § 4 → "Kuota Mitra Prioritas: X% per dompet (Terkunci)" + "Investor Umum" → "Mitra Reguler"; **+ § 4.4** tabel pemetaan tier (nol migrasi); **+ § 4.5** alur atomik setoran & Pindah Kas + catatan alokasi *ex-post* (A-1); **+ § 4.6** distribusi pool 20 % (largest remainder, invariant, Pengelola dikecualikan); § 4.3 dilengkapi status & H-7; § 7 sketsa skema `tier (operator/family/external)`; § 9 Pekan 3 "alokasi khusus kerabat" → "Mitra Prioritas" |
| `docs/AurumFlow Roadmap and QC Documents.md` | **FASE 4**: checklist teknis disesuaikan (waterfall per-dompet, setoran atomik, pindah kas, antrean H-7) dan **Protokol QC Manual 4 ditulis ulang** dengan terminologi baru + **tabel angka eksak § 4.2/§ 6.1** (menggantikan tabel persen bulat 40/35,29/24,71 %); **FASE 3**: kolom detail baris "Saldo Investor Utuh (3.2)" disesuaikan maknanya (A-17) |
| `docs/AurumFlow System Architecture Document.md` | § 3 struktur direktori: `app/Services/AllocationEngine.php` (dipindah dari `Helpers/`), `app/Services/CapitalService.php`, `app/Controllers/CapitalController.php`, `app/Models/{CapitalWallet,ProfitDistribution,WithdrawalRequest}.php`, `app/Views/operator/capital.php` + catatan alasan penempatan |
| `docs/AurumFlow Entity Relationship Diagram.md` | **TIDAK diubah** — dinyatakan eksplisit di dokumen ini dan dijaga assertion QC grup I |
| `AGENTS.md` | Route baru + nav tiga item; kontrak settlement **enam** kelompok tulisan; urutan penguncian § A-7; jalur uang baru & aturan `_once`; pemilik label tier; aturan H-7; peringatan QC (suite menulis `capital_wallets`, `profit_distributions`, `withdrawal_requests`); baseline suite/assertion |
| `README.md` | Tabel route, pohon direktori, tabel QC (**10 suite**), peringatan tulis DB/disk, perintah probe Node baru |

*Kriteria:* setiap perubahan `docs/` menyebut bagian yang diubah + alasannya; tidak ada istilah "Kerabat" yang tersisa di `docs/` (dijaga QC grup H).

### T2 — Model baru (SQL saja)

| Berkas | Permukaan |
|---|---|
| `app/Models/CapitalWallet.php` | `TIER_OPERATOR/TIER_FAMILY/TIER_EXTERNAL`, `TIER_LABELS`, `label()`; `listWithUser()` (JOIN `users`), `findByUserId()`, `insert()`, `lockAllForUpdate()` (id ASC), `lockByIdForUpdate()`, `adjustCapital()`, `adjustProfit()`, `setLockedQuota()`, `setWithdrawalState()`, `ensureOperatorWallet()` |
| `app/Models/ProfitDistribution.php` | `insertMany()`, `listForSettlement()`, `sumFor()`, `countFor()` |
| `app/Models/WithdrawalRequest.php` | `STATUS_*`, `TYPE_*`, `insert()`, `listOpen()`, `listAll()`, `find()`, `setStatus()`, `countOpenForWallet()` |

### T3 — `app/Services/AllocationEngine.php`

Murni + transaksional, satu pemilik aturan alokasi:
`plan(int $needCents, array $wallets, int $operatorQuotaHundredths): array` (murni; `wallets` = daftar `[id, tier, balance_capital_cents, locked_quota_hundredths, label]`), `distribute(int $poolCents, array $mitraUtilized): array` (murni), `planFromDatabase(int $needCents): array` (baca dompet + setting, tanpa kunci; untuk pratinjau Layar E), `apply(PDO $pdo, string $settlementId, int $needCents, int $poolCents, array $lockedWallets): array` (dipanggil dari dalam transaksi settlement). Konstanta `MODE_WATERFALL`/`SETTING_MODE`/`SETTING_OP_QUOTA`; tanpa SQL di luar `apply()` → `apply()` menulis lewat `ProfitDistribution`/`CapitalWallet` (aturan `AGENTS.md`: SQL hanya di `app/Models/`).

### T4 — `app/Services/CapitalService.php`

`topUp(array $input): array`, `transferToTeller(array $input): array`, `createMitra(array $input): array`, `createWalletForUser(array $input): array`, `updateQuota(array $input): array`, `requestWithdrawal(array $input): array`, `decideWithdrawal(array $input): array` (setujui/tolak), `payWithdrawal(array $input): array`, `workingDaysDueDate(string $from, int $days): string`. Semuanya uang → `Database::transaction()`; parsing persen & nominal; validasi pesan siap tampil; `Logger` untuk setiap mutasi.

### T5 — Controller & route

`app/Controllers/CapitalController.php`: `index()` (GET) + `topUp()`, `storeMitra()`, `updateQuota()`, `transfer()`, `withdrawal()` (POST). Semua `requireRole(Constants::ROLE_OPERATOR)`; `storeMitra()` menerima `mode` ∈ {`mitra`, `dompet`} (mode `dompet` = buat dompet untuk user yang sudah ada, termasuk tombol perbaikan "Siapkan dompet Pengelola" bila `install.php` lama belum membuatnya).

`app/routes.php`:
```php
'GET'  => [ '/operator/capital' => [CapitalController::class, 'index'] ],
'POST' => [
    '/operator/capital/topup'      => [CapitalController::class, 'topUp'],
    '/operator/capital/mitra'      => [CapitalController::class, 'storeMitra'],
    '/operator/capital/quota'      => [CapitalController::class, 'updateQuota'],
    '/operator/capital/transfer'   => [CapitalController::class, 'transfer'],
    '/operator/capital/withdrawal' => [CapitalController::class, 'withdrawal'],
],
```

### T6 — Layar E, navigasi, dan aset frontend

- `app/Views/operator/capital.php` (empat bagian § 4.6; seluruh output `Security::e()`; angka via `Formatter`; label via `CapitalWallet::label()`).
- `app/Views/layouts/operator.php` → item ketiga `['path' => App::url('operator/capital'), 'label' => 'Kelola Modal']`; header menyediakan `Kas Meja Bakar` **dan** `Brankas Utama` (controller baru mengirim `$register`/`$holding` seperti layar lain).
- `app/Views/dashboard/index.php` → tombol role-aware "Kelola Modal & Likuiditas" (hanya `operator`).
- `public/assets/js/app.js` → `AurumAlloc.plan()` (cermin `AllocationEngine` dalam sen integer) + `capitalSimulator(config)` + registrasi `Alpine.data('capitalSimulator', …)`; **perbaikan A-19** `AurumFormat.rupiah()` (pembulatan, bukan pemotongan).
- `public/assets/css/app.min.css` → kompilasi ulang (`npx --yes tailwindcss@3.4.19 -c tailwind.config.js …`), target tetap < 30 KB.

### T7 — Integrasi settlement

Sesuai § 4.4: `CapitalWallet::lockAllForUpdate()` sebelum pos kas; `AllocationEngine::apply()` setelah tulisan Fase 3; `TellerService::centsToDecimal()` dijadikan `public` + hapus duplikat privat di `SettlementService` (A-16); flash/log diperluas.

### T8 — Harness & QC

- `tests/bootstrap.php`: `QcFixture::ensureOperatorWallet()`, `seedQcWallets()` (Pengelola 30 M + Prioritas 16 M/20 % + Reguler A 20 M + Reguler B 14 M, akun uji berawalan `0899…`), `resetCapitalState()` (kosongkan `profit_distributions`/`withdrawal_requests`, kembalikan saldo/kuota/status dompet QC), `capitalSnapshot()`; `ensureInstalled()` ikut menyiapkan dompet Pengelola agar keadaan QC = keadaan produksi.
- `tests/qc_fase_4.php` — Protokol QC Manual 4 otomatis + grup A–J (§ 6), diakhiri `Qc::finish('qc_fase_4')`.
- `tests/run_qc.sh` → `qc_fase_4` setelah `qc_fase_3`; header/ringkasan **10 suite**.
- `tests/qc_fase_3.php` → penyesuaian A-17 (label + makna assertion, bukan angkanya).
- `database/install.php` → buat dompet Pengelola (`tier='operator'`) di dalam transaksi yang sudah ada + laporkan pada output instalasi.
- `tests/manual/js_capital_simulator_check.js` (Node, di luar `run_qc.sh` sesuai konvensi) → memverifikasi (a) cermin JS `AurumAlloc` menghasilkan tabel yang sama dengan angka § 4.2, (b) `AurumFormat.rupiah` membulatkan sama dengan `Formatter`.

### T9 — Gerbang penuh

`bash tests/run_qc.sh` (exit 0, 10 suite) → tidak ada `storage/logs/php-*.log` → `node tests/manual/js_capital_simulator_check.js` → `php -l` pada setiap berkas yang diubah → `git status` bersih (tanpa `tests/.tmp/`, `storage/installed.lock`, `*.log`) → tinjauan diff akhir (`git diff --stat`) memastikan hanya perubahan yang direncanakan.

### T10 — Serah terima

`plan/Summary_Fase_4_AurumFlow.md` (hasil, angka nyata, assertion count, item terbuka), pembaruan baseline di `AGENTS.md`/`README.md`, dan bagian `# ADDENDUM — Catatan Eksekusi` pada dokumen ini (setiap penyimpangan dari rencana dicatat beserta alasannya).

---

## 6. Quality Control — Protokol QC Manual 4

### 6.1 Tabel protokol (1:1 dengan dokumen Roadmap setelah T1)

| Parameter Uji | Data Masukan | Hasil yang Diharapkan | Sen (otoritatif) |
|---|---|---|---|
| Serapan Pengelola (50 %) | 30.000.000 / kuota 50 % | Rp 17.578.750 | `1757875000` |
| Serapan Mitra Prioritas (kuota terkunci) | 16.000.000 / kuota 20 % | Rp 7.031.500 | `703150000` |
| Sisa untuk Mitra Reguler | 35.157.500 − 24.610.250 | Rp 10.547.250 | `1054725000` |
| Pro-rata Mitra Reguler A | 20/34 × sisa | Rp 6.204.265 (tampil) | `620426471` |
| Pro-rata Mitra Reguler B | sisa pembagian | Rp 4.342.985 (tampil) | `434298529` |
| Σ modal terserap | — | **Rp 35.157.500** (= belanja) | `3515750000` |
| Tak terserap (`uncovered`) | saldo cukup | Rp 0 | `0` |
| Dividen Mitra Prioritas | 17.492.000 / 1.757.875.000 × 437.300 | Rp 174.920 | `17492000` |
| Dividen Mitra Reguler A | pool pro-rata | Rp 154.341 | `15434118` |
| Dividen Mitra Reguler B | pool pro-rata | Rp 108.039 | `10803882` |
| Σ dividen | — | **Rp 437.300** = `investor_pool_20` | `43730000` |
| Jatah Pengelola dari pool | PRD § 3.4 | Rp 0 | `0` |
| Modal pokok mitra | seluruh skenario | **tidak pernah** berkurang | — |
| Hari minus (QC 3.2) | laba −80.000 | pool 0; `profit_earned = 0.00`; saldo dividen tetap | `0` |
| Setoran modal | Rp 10.000.000 tunai | Brankas +10.000.000, `balance_capital` +10.000.000, **1** mutasi `in`, 1 transaksi | — |
| Pindah Kas | Rp 5.000.000 | Brankas −5.000.000, Meja +5.000.000, **2** mutasi (`out`,`in`), 1 transaksi | — |
| Kuota prioritas | `20` pada dompet `family` | `locked_quota_pct = 20.00`; dompet `external` tetap `0.00` | — |
| Jatuh tempo H-7 | `notice_period_days = 7` | tanggal = +7 **hari kerja** (Sabtu/Minggu dilewati) | — |
| Bayar penarikan sebelum jatuh tempo | modal pokok, `approved` | `422`, **nol** tulisan | — |
| Otentikasi layar | sesi investor | `302 /dashboard`; tanpa tulisan | — |

### 6.2 Assertion tambahan di luar protokol manual (`tests/qc_fase_4.php`)

| Grup | Cakupan |
|---|---|
| **A** | `AllocationEngine` murni: urutan waterfall, clamp kuota/saldo, `uncovered`, determinisme (input sama → hasil sama; urutan input acak → hasil sama), `need = 0`, tanpa dompet, tanpa dompet operator, kuota prioritas > 100 %, Σ kuota prioritas > 50 %, largest remainder eksak untuk banyak kasus (termasuk seri), invariant `Σ utilized + uncovered == need`, `Σ profit == pool` |
| **B** | Parser: kuota persen (0–100, ≤ 2 desimal, tolak negatif/dua pemisah) dibandingkan dengan tabel kasus yang sama seperti parser persen Fase 3; nominal setoran/transfer/penarikan (jalur uang butuh > 0); batas `DECIMAL(14,2)` |
| **C** | Atomisitas: setoran/pindah kas/bayar penarikan masing-masing satu transaksi; paksa gagal di tengah (pos kas di-*rename*, saldo kurang) → **nol** perubahan pada dompet, kas, dan mutasi |
| **D** | Guard lewat HTTP (`QcHttp`): `requireRole` (GET+POST investor), CSRF hilang → `419`, `_once` diulang → "ganda diabaikan" tanpa tulisan, saldo brankas kurang → `422`, pembayaran sebelum jatuh tempo → `422`, kuota pada dompet non-prioritas → `422`/paksa 0, nomor telepon duplikat → `422` |
| **E** | Peladen tidak mempercayai klien: hanya `wallet_id`, nominal, kuota, dan aksi yang dibaca dari form; saldo/flash dihitung ulang; payload nilai dompet orang lain tidak berpengaruh |
| **F** | Integrasi settlement: setelah `settle()` pada keadaan QC 4 → baris `profit_distributions` = tabel § 4.2, `balance_profit` bertambah persis, `Σ == investor_pool_20`, `balance_capital` tidak berubah, `notes` mutasi brankas memuat ringkasan alokasi; hari minus → baris `0.00` |
| **G** | Idempotensi & pengulangan: fixture dapat dijalankan berulang; dua sesi settlement berurutan pada batch berbeda tidak saling menimpa; tidak ada baris distribusi ganda untuk `(settlement_id, wallet_id)` pada satu sesi |
| **H** | Pemindaian statis & kontrak view: tidak ada `:disabled` pada kontrol form `capital.php`; placeholder `mis. ` ada; seluruh output dinamis `Security::e()`; tidak ada `number_format(`/`float`/`double` di berkas baru; tanpa SQL di controller/view; `requireRole('operator')` di setiap method; `Database::transaction()` di tiga jalur uang; label tier lengkap; **tidak ada kata "Kerabat"** di `app/`, `public/`, `docs/`; navigasi memuat tiga item; route terdaftar di `routes.php` |
| **I** | Integritas skema: 9 tabel tetap; `tier ENUM('operator','family','external')`, `withdrawal_status ENUM('none','queued','completed')`, `withdrawal_requests.status ENUM('pending','approved','rejected','completed')` tidak berubah; `QcFixture::checkDecimalConventions()` untuk `balance_capital`, `balance_profit`, `locked_quota_pct`, `capital_utilized`, `profit_earned`, `amount` — **bukti nol DDL** |
| **J** | Aturan tanggal: `+7` hari kerja lintas akhir pekan (mis. pengajuan Jumat → bayar Senin pekan berikutnya untuk hari kerja ke-7), `notice_period_days = 0` → tanggal sama, nilai setting hilang → `RuntimeException` |

### 6.3 Gate penerimaan

`bash tests/run_qc.sh` **exit 0** dengan **10 suite** (urutan: `qc_pdo`, `qc_formatter`, `qc_security`, `qc_seed`, `qc_roadmap_protocol_1`, `qc_fase_2`, `qc_fase_3`, `qc_fase_4`, `qc_sql_injection`, `qc_routing`), 0 gagal, tanpa `storage/logs/php-*.log`, dan `node tests/manual/js_capital_simulator_check.js` lulus. Baseline assertion baru dicatat pada `Summary_Fase_4` dan `AGENTS.md` (target ≥ 380 assertion).

---

## 7. Risiko & Mitigasi

| ID | Risiko | Mitigasi |
|---|---|---|
| R-1 | **Deadlock** antar jalur uang (settlement menyentuh dompet + dua pos kas; penarikan menyentuh dompet + brankas) | Urutan penguncian global A-7 di semua jalur; `lockAllForUpdate()` pakai `ORDER BY id ASC`; QC grup C/G menabrakkan dua sesi |
| R-2 | **Pembayaran penarikan dobel** (tombol ganda, back, retry jaringan) | `_once` (token sekali-pakai) + status `approved→completed` + pengecekan ulang saldo dompet/kas di dalam transaksi; QC grup D/G |
| R-3 | **Drift pembulatan** pada pro-rata berdesimal | Largest remainder (A-3) + invariant `Σ == pool`/`Σ == need` diuji grup A; tabel angka eksak § 4.2 dijadikan acuan dokumen |
| R-4 | **Basis data tanpa dompet** (instalasi lama / QC Fase 3) | A-6: baris hanya ditulis untuk `capital_utilized > 0`; `pool_unallocated` dilaporkan lewat log, bukan dianggap sukses senyap; QC grup F |
| R-5 | **Dompet Pengelola belum ada** pada instalasi lama (`storage/installed.lock` menolak `install.php`) | Layar E mendeteksi dan menampilkan CTA "Siapkan dompet Pengelola" (mode `dompet` pada `POST /operator/capital/mitra`); settlement tetap berjalan tanpa dompet |
| R-6 | **Salah arti "modal bekerja"** dibaca sebagai saldo dompet | Dokumentasi PRD/UI-UX menyatakan alokasi *ex-post* (A-1) + `capital_utilized` sebagai satu-satunya sumber; QC grup F menolak perubahan `balance_capital` |
| R-7 | **Tabel dokumen berbeda Rp 2** dari hasil sen eksak (persen bulat 40/35,29/24,71 %) | T1 mengganti tabel Roadmap dengan angka § 4.2; QC memakai sen sebagai otoritatif, rupiah hanya sebagai tampilan |
| R-8 | **Cermin JS ≠ peladen** (Rp 1 karena pemotongan sen) | A-19 + probe Node `tests/manual/js_capital_simulator_check.js` |
| R-9 | **QC menulis ke tabel dompet** pada DB yang ditunjuk `.env` | Fixture idempotent + peringatan di `README.md`/`AGENTS.md`; larangan mengarahkan ke produksi |

---

## 8. Checklist Serah Terima Fase 4

- [ ] Seluruh kriteria § 1.2 terpenuhi dengan bukti perintah (`bash tests/run_qc.sh`, `node tests/manual/…`, `git status`).
- [ ] `docs/` (UI/UX Layar E, PRD, Roadmap FASE 4, SAD § 3) dan `AGENTS.md`/`README.md` sudah selaras dengan kode — **tidak ada penyimpangan senyap**.
- [ ] `plan/Summary_Fase_4_AurumFlow.md` memuat angka nyata, jumlah assertion, dan item terbuka.
- [ ] `# ADDENDUM` dokumen ini diisi untuk setiap penyimpangan eksekusi + alasannya.
- [ ] Tidak ada kode mati: setiap method publik baru punya pemanggil atau assertion QC.

---

## 9. Item Terbuka (perlu keputusan sebelum/di awal eksekusi)

| ID | Item | Rekomendasi |
|---|---|---|
| O-1 | Mode `allocation_mode = 'prorata'` (PRD § 4) belum diimplementasikan | Tolak dengan pesan jelas di Fase 4; implementasi + panel admin di fase berikutnya |
| O-2 | Kalender hari libur nasional untuk H-7 | Fase 4 memakai hari kerja (Sabtu/Minggu dilewati); kalender libur menyusul |
| O-3 | Pengajuan penarikan dari dasbor investor | Fase 5 (bersama `invite_token`, PIN, waiver) |
| O-4 | Pindah Kas arah balik (Meja → Brankas) | Belum perlu: Smart Auto-Split settlement sudah menempatkan laba bersih di Brankas |
| O-5 | Pro-rata Reguler memakai `balance_capital` penuh (bukan "sisa yang tidak bekerja") | Dipertahankan; ditinjau bila Fase 5 menampilkan modal aktif vs mengendap |
| O-6 | Pratinjau alokasi pool di Layar C (rekonsiliasi) | Ditunda; Fase 4 hanya menambah ringkasan pada flash/log settlement |
| O-7 | Beberapa dompet `operator` (skema mengizinkan 1 : N) | Diperlakukan sebagai satu kelompok dengan urutan tetap (A-4) |
| O-8 | `UNIQUE(settlement_id, wallet_id)` pada `profit_distributions` | Tidak mungkin di Fase 4 (nol DDL); QC grup G memastikan tidak ada baris ganda per sesi |
| O-9 | Kuota untuk dompet `external` | Selalu `0.00` (A-15); perubahan berarti perubahan definisi waterfall |

---

## 10. Lampiran — Penambahan di Luar SAD & Alur Request

### 10.1 Penyimpangan yang disengaja dari daftar eksplisit SAD § 3

| SAD § 3 menyebut | Kenyataan Fase 4 | Alasan |
|---|---|---|
| `app/Helpers/AllocationEngine.php` | `app/Services/AllocationEngine.php` | Mengikuti pola `TellerService`/`SettlementService`: engine memuat use-case + pembacaan DB terkunci, bukan helper murni. `docs/` diperbarui lebih dulu (T1) lalu dicatat di sini |
| — | `app/Services/CapitalService.php` | Jalur uang baru (setoran, pindah kas, penarikan) wajib memiliki pemilik transaksi tunggal di luar controller |
| — | `app/Models/{CapitalWallet,ProfitDistribution,WithdrawalRequest}.php` | Aturan `AGENTS.md`: SQL hanya di `app/Models/` |

### 10.2 Alur request `POST /operator/capital/topup`

```
Front controller: method=POST → verifikasi CSRF (_token) → dispatch CapitalController::topUp
  1. requireRole('operator')                        → gagal: 302 /dashboard
  2. token sekali-pakai (_once)                     → FORM_DUPLICATE: PRG + "ganda diabaikan"
                                                    → FORM_INVALID : 419
  3. validasi field (wallet_id, amount, note)       → gagal: 422 + isian terpelihara
  4. CapitalService::topUp() → Database::transaction()
       a. CapitalWallet::lockByIdForUpdate(wallet_id)
       b. CashRegister::lockByNameForUpdate('Brankas Utama')
       c. CashMutation::insert(id, brankas, 'in', amount, 'Setoran modal — <nama>', notes)
       d. CapitalWallet::adjustCapital(+amount)
       e. CashRegister::adjustBalance(brankas, +amount)
  5. Logger::info('Setoran modal tersimpan', …)     → sukses: PRG + flash
```

### 10.3 Alur hook settlement

```
SettlementService::settle()
  ├─ di luar transaksi: quote() tolak-cepat, simpan nota (A-7 Fase 3)
  └─ Database::transaction()
       1. CapitalWallet::lockAllForUpdate()          ← BARU (id ASC, sebelum pos kas)
       2. CashRegister::lockByNamesForUpdate([...])
       3. HuluTransaction::holdingRowsForUpdate()
       4. SystemSetting::getForUpdate(active_carryover_deficit)
       5. quote() dari himpunan terkunci
       6. tulis daily_settlements + settleBatch + 2 × saldo kas + 2 × cash_mutations + defisit
       7. AllocationEngine::apply($pdo, $settlementId, holding_cost_cents, investor_pool_cents, $wallets)  ← BARU
            → profit_distributions (1 baris/dompet dengan utilized > 0)
            → balance_profit += profit_earned
            → ringkasan: {wallets, profit_total, uncovered, pool_unallocated}
  → flash + Logger (warning bila uncovered > 0 / pool tidak terbagi)
```

---

# ADDENDUM — Catatan Eksekusi

*(Diisi selama eksekusi: setiap penyimpangan dari rencana di atas dicatat di sini beserta alasannya, sesuai `AGENTS.md`.)*

**Status: Fase 4 dieksekusi penuh. `bash tests/run_qc.sh` exit 0 — 10/10 suite,
0 gagal (jumlah assertion per suite pada § A-4). Laporan lengkap:
`plan/Summary_Fase_4_AurumFlow.md`; perbaikan UX pascarilis ada di
`plan/Summary_UX_Fix.md` (§ A-5).**

## A-1. Penyimpangan dari rencana

| Rencana | Kenyataan | Alasan |
|---|---|---|
| § 3.2 menyebut `AllocationEngine` "murni + transaksional" dengan `apply(PDO $pdo, …)` | Dipertahankan **dengan** parameter `PDO $pdo` (tidak dipakai di badan fungsi) | Mengikuti gaya rumah: callback `Database::transaction()` memang menerima `$pdo`, dan tanda tangan itu sendiri menjadi pengingat bahwa `apply()` WAJIB dipanggil di dalam transaksi |
| § 3.2 hanya menyebut `insert()` pada `ProfitDistribution`/`WithdrawalRequest` | `insert`, `listForSettlement`, `sumProfit`, `sumUtilized`, `typeLabel`, `statusLabel`, `findById`, `lockByIdForUpdate`, `listOpenWithWallet`, `setStatus` | Metode yang tidak punya pemanggil **dihapus** sebelum gerbang akhir (`listForWallet`, `countForSettlement`, `countByStatus`, `count`) — aturan "tidak ada kode mati" pada `AGENTS.md` |
| § 5 T2 menyebut `quotaFieldValue`/`hundredthsToDecimal` di model | Parser/format kuota hidup di `AllocationEngine` (satu pemilik), `CapitalService` memakainya | Menghindari dua tempat yang mengurai persen dengan aturan berbeda |
| § 5 T8 hanya menyebut fixture Fase 4 baru | `QcFixture::clearSettlementTraces()` (Fase 3) **ikut diubah**: hapus `profit_distributions` lebih dulu + nolkan `balance_profit`/status penarikan | `profit_distributions.settlement_id` memakai FK `ON DELETE RESTRICT`; tanpa pembersihan itu reset fixture gagal (`qc_fase_3` tidak dapat dijalankan ulang) |
| § 5 T6 hanya menyebut view + nav + `capitalSimulator` | `public/assets/js/app.js` juga memperbaiki `AurumFormat.rupiah()` (pemotongan → pembulatan half-up) | Cermin klien dan `Formatter` sempat berbeda Rp 1 pada nilai berdesimal (mis. `Rp 108.038,82`); perbedaan itu baru tampak ketika pro-rata Fase 4 menghasilkan pecahan rupiah |
| § 4.1 hanya menyebut "sen integer" | Ditambah pembagian panjang berbasis limb (radix 10⁴) di `AllocationEngine::mulDiv()` | `intdiv($pool × $utilized, $base)` dapat meluap `PHP_INT_MAX` pada nilai yang realistis (pool Rp 10 juta × modal Rp 100 juta); diuji eksplisit oleh assertion `A.12` |

## A-2. Temuan lingkungan yang perlu diingat

1. **`mbstring` tidak tersedia.** PHP mesin ini tidak memuat ekstensi `mbstring`
   (dan seluruh kode Fase 1–3 memang belum pernah memakainya). `CapitalService`
   memakai helper `charLength()`/`truncate()` berbasis PCRE `/u` alih-alih
   `mb_strlen()`/`mb_substr()`. Jangan menambahkan `mb_*` pada fase berikutnya
   tanpa memverifikasi ekstensinya di host target.
2. **`?>` di dalam komentar PHP mengakhiri mode PHP.** Satu komentar di
   `tests/qc_fase_4.php` sempat memuat kurung tutup blok PHP dan membuat berkas
   gagal di-parse; komentar kini memuat catatan peringatan agar tidak terulang.
3. **`tests/manual/js_capital_simulator_check.js`** hanya dapat menguji perilaku
   lewat komponen Alpine (seperti probe Fase 3), karena `AurumAlloc`/`AurumFormat`
   sengaja privat di dalam IIFE `app.js`.

## A-3. Perubahan wajib pada berkas fase sebelumnya (disengaja)

| Berkas | Perubahan | Penjaga |
|---|---|---|
| `app/Services/SettlementService.php` | Kunci `capital_wallets` sebelum pos kas; panggil `AllocationEngine::apply()`; ringkasan alokasi pada `notes` mutasi; `centsToDecimal()` didelegasikan | `qc_fase_3` (98 assertion) + `qc_fase_4` grup F |
| `app/Services/TellerService.php` | `centsToDecimal()` menjadi publik | `qc_fase_2` + `qc_fase_3` |
| `app/Controllers/SettlementController.php` | Pesan sukses menyebut jumlah dompet penerima pool | `qc_fase_3` |
| `tests/bootstrap.php` | Fixture Fase 4 + pembersihan distribusi pada `clearSettlementTraces()` | Seluruh suite |
| `tests/qc_fase_3.php` | Label `P3.10c` + kolom detail tabel protokol (tanpa mengubah angka) | `qc_fase_3` |

## A-4. Baseline akhir

`bash tests/run_qc.sh` → **exit 0**, `10/10` suite (`qc_pdo` 20, `qc_formatter` 43,
`qc_security` 32, `qc_seed` 46, `qc_roadmap_protocol_1` 6, `qc_fase_2` 66,
`qc_fase_3` 98, `qc_fase_4` **112**, `qc_sql_injection` 15, `qc_routing` 31),
**469 assertion, 0 gagal**.

> `qc_fase_4` semula 105 assertion; bertambah menjadi 112 setelah perbaikan UX
> kolom kuota serapan (`D.20`–`D.24`) dan penjaga determinisme dompet manual
> (`G.5`–`G.6`) — lihat § A-5.

## A-5. Perbaikan UX & penjaga determinisme (pascarilis, di luar rencana)

| Temuan | Tindakan |
|---|---|
| Sel "Kuota Serapan" menampilkan isian `0,00 %` untuk Pengelola (padahal mesin memakai kuota dasar 50 % dari `system_settings`) dan untuk Mitra Reguler (yang selalu pro-rata) | Sel bercabang per tier: badge `50,00 % (Sistem)` untuk Pengelola, satu-satunya isian untuk Mitra Prioritas, penanda "Pro-rata" untuk Mitra Reguler. Controller mengirim `operatorQuotaLabel` dari `AllocationEngine::configuredOperatorQuota()` (sumber yang sama dengan mesin) |
| **Dompet manual operator** (dibuat lewat form "Tambah Mitra" saat pengujian lapangan) ikut menyerap modal karena `schema.sql` memakai `CREATE TABLE IF NOT EXISTS` — angka protokol bergeser dan `qc_fase_3` berisiko gagal | `QcFixture::neutraliseWallets()` menolkan saldo/dividen/kuota/status **seluruh** dompet tanpa menghapus barisnya; dipanggil `clearSettlementTraces()` + `resetCapitalState()`; dijaga assertion `G.5`/`G.6` |

Rincian lengkap: `plan/Summary_UX_Fix.md`.
