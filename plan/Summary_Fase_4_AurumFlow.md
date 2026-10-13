# Summary Fase 4 — AurumFlow

> **Laporan Eksekusi**
> Dokumen rencana: `plan/Plan_Fase_4_AurumFlow.md` (status: `EXECUTED`)
> Keputusan pengguna: `dec-3c33eba5e3945873`
> Fase: **4 — Mesin Alokasi Modal (Waterfall & Pro-Rata Engine) & Tata Kelola Likuiditas**
> Hasil gerbang: **`bash tests/run_qc.sh` exit 0 — 10/10 suite, 469 assertion, 0 gagal**

---

## 1. Ringkasan Hasil

Fase 4 selesai sesuai rencana, dengan **nol DDL** (`database/schema.sql`,
`database/seed.sql`, dan dokumen ERD tidak disentuh) dan seluruh kriteria
"Definisi Selesai" pada rencana § 1.2 terpenuhi.

Yang berubah secara nyata:

1. **Layar E `/operator/capital`** hidup: Setor Modal Baru, Portofolio Mitra +
   kuota terkunci, Tambah Mitra, Pindah Kas, Simulator "Belanja Malam Ini", dan
   Antrean Penarikan H-7. Navigasi operator kini tiga item:
   **Kasir | Rekonsiliasi | Kelola Modal**.
2. **`AllocationEngine`** menyerap kas belanja berlapis (Pengelola 50 % → kuota
   Mitra Prioritas per dompet → pro-rata Mitra Reguler) dan membagikan pool 20 %
   ke `capital_wallets.balance_profit` lewat `profit_distributions`, seluruhnya
   dalam **sen integer** dengan metode **sisa terbesar**.
3. **Tutup buku kini enam kelompok tulisan** dalam satu transaksi (lima kelompok
   Fase 3 + distribusi pool). Urutan penguncian global ditetapkan:
   `capital_wallets` → `cash_registers` → `hulu_transactions` → `system_settings`.
4. **Tiga jalur uang baru** — setoran modal (Brankas Utama), Pindah Kas satu arah
   Brankas → Meja, dan pencairan penarikan (kas keluar brankas) — masing-masing
   satu `Database::transaction()`.
5. **Terminologi**: `family` → **Mitra Prioritas**, `external` → **Mitra Reguler**,
   `operator` → **Pengelola**, dipetakan sekali di `CapitalWallet::TIER_LABELS`.

## 2. Angka Protokol QC Manual 4 (terverifikasi otomatis)

Dompet uji: Pengelola Rp 30.000.000 (kuota 50 %), Mitra Prioritas Rp 16.000.000
(kuota terkunci 20 %), Mitra Reguler A Rp 20.000.000, Mitra Reguler B
Rp 14.000.000. Belanja putaran Rp 35.157.500 (data QC 2), pool mitra Rp 437.300
(data QC 3).

| Parameter | Nilai terbukti | Sen (otoritatif) |
|---|---|---|
| Serapan Pengelola (50 %) | Rp 17.578.750 | `1757875000` |
| Serapan Mitra Prioritas (20 %) | Rp 7.031.500 | `703150000` |
| Sisa untuk Mitra Reguler | Rp 10.547.250 | `1054725000` |
| Pro-rata Mitra Reguler A | Rp 6.204.264,71 | `620426471` |
| Pro-rata Mitra Reguler B | Rp 4.342.985,29 | `434298529` |
| Σ modal terserap | Rp 35.157.500 (= belanja) | `3515750000` |
| Sisa tak terserap | Rp 0 | `0` |
| Dividen Mitra Prioritas | Rp 174.920 | `17492000` |
| Dividen Mitra Reguler A | Rp 154.341,18 | `15434118` |
| Dividen Mitra Reguler B | Rp 108.038,82 | `10803882` |
| Σ dividen | Rp 437.300 = `investor_pool_20` | `43730000` |
| Jatah Pengelola dari pool | Rp 0 (PRD § 3.4) | `0` |

Angka-angka ini diuji ulang **di dua tempat**: perhitungan murni
(`qc_fase_4` grup A) dan hasil tulisan basis data setelah satu sesi tutup buku
nyata lewat HTTP (`qc_fase_4` grup F).

## 3. Berkas

### 3.1 Baru

| Berkas | Peran |
|---|---|
| `app/Models/CapitalWallet.php` | SQL dompet modal + pemetaan tier → label UI |
| `app/Models/ProfitDistribution.php` | SQL distribusi pool per dompet |
| `app/Models/WithdrawalRequest.php` | SQL antrean penarikan |
| `app/Services/AllocationEngine.php` | Waterfall + pro-rata (sen integer, sisa terbesar, aritmetika limb anti-luapan) |
| `app/Services/CapitalService.php` | Setoran, Pindah Kas, tambah mitra/dompet, kuota, penarikan (H-7 hari kerja) |
| `app/Controllers/CapitalController.php` | GET `/operator/capital` + 5 POST |
| `app/Views/operator/capital.php` | Layar E (lima bagian) |
| `tests/qc_fase_4.php` | Protokol QC Manual 4 otomatis (112 assertion) |
| `tests/manual/js_capital_simulator_check.js` | Probe Node: cermin simulator JS ↔ peladen (38 assertion) |
| `plan/Plan_Fase_4_AurumFlow.md`, `plan/Summary_Fase_4_AurumFlow.md` | Rencana + laporan ini |

### 3.2 Diubah

| Berkas | Perubahan |
|---|---|
| `app/routes.php` | + `GET /operator/capital`, + 5 POST (`topup`, `mitra`, `quota`, `transfer`, `withdrawal`) |
| `app/Views/layouts/operator.php` | Nav tiga item + penanda sub-halaman aktif |
| `app/Views/dashboard/index.php` | Tombol role-aware "Kelola Modal & Likuiditas" |
| `app/Services/SettlementService.php` | Kunci dompet sebelum pos kas; `AllocationEngine::apply()` sebagai kelompok tulisan keenam; ringkasan alokasi pada `notes` mutasi + log; delegasi `centsToDecimal()` |
| `app/Services/TellerService.php` | `centsToDecimal()` menjadi publik (satu pemilik konversi uang) |
| `app/Controllers/SettlementController.php` | Pesan sukses menyebut jumlah dompet penerima pool |
| `app/Helpers/Formatter.php` | *(tidak diubah)* — cermin JS-nya yang diselaraskan |
| `public/assets/js/app.js` | `AurumAlloc` (cermin engine, BigInt) + `capitalSimulator`; `AurumFormat.rupiah()` kini **membulatkan** (bukan memotong) sen |
| `public/assets/css/app.min.css` | Hasil build ulang (22,6 KB, tetap < 30 KB) |
| `database/install.php` | Membuat **dompet Pengelola** (`tier='operator'`) dalam transaksi yang sama + melaporkannya |
| `tests/bootstrap.php` | `QcFixture::ensureOperatorWallet` (via `ensureInstalled`), `seedQcWallets()`, `resetCapitalState()`, `setWalletState()`, `capitalSnapshot()`; `clearSettlementTraces()` membersihkan `profit_distributions` (FK RESTRICT) dan menolkan `balance_profit` |
| `tests/qc_fase_3.php` | Label assertion `P3.10c` + kolom detail tabel protokol disesuaikan maknanya (tidak ada angka yang berubah) |
| `tests/run_qc.sh` | + `qc_fase_4`; log menjadi `storage/logs/qc-fase4.log` |
| `docs/` | UI/UX **Layar E**; PRD § 4.4–§ 4.7 + istilah; Roadmap FASE 4 + angka eksak (menggantikan tabel persen bulat); SAD § 3 (Models/Services + alasan penempatan `AllocationEngine`) |
| `AGENTS.md`, `README.md` | Route, kontrak enam tulisan, urutan penguncian, aturan H-7, peringatan QC, baseline 10 suite / 469 assertion |

### 3.3 Dihapus

Tidak ada berkas dihapus. Kode mati yang **tidak** lolos aturan `AGENTS.md`
dihapus sebelum gerbang akhir: `ProfitDistribution::listForWallet()`,
`ProfitDistribution::countForSettlement()`, `WithdrawalRequest::listForWallet()`,
`WithdrawalRequest::countByStatus()`, `WithdrawalRequest::count()` (kelimanya
belum punya pemanggil maupun assertion).

## 4. QC — Hasil Gerbang

| Suite | Assertion | Status |
|---|---:|---|
| `qc_pdo` | 20 | LULUS |
| `qc_formatter` | 43 | LULUS |
| `qc_security` | 32 | LULUS |
| `qc_seed` | 46 | LULUS |
| `qc_roadmap_protocol_1` | 6 | LULUS |
| `qc_fase_2` | 66 | LULUS |
| `qc_fase_3` | 98 | LULUS |
| **`qc_fase_4`** | **112** | **LULUS** |
| `qc_sql_injection` | 15 | LULUS |
| `qc_routing` | 31 | LULUS |
| **Total** | **469** | **0 gagal, `exit 0`** |

Di luar `run_qc.sh` (butuh Node):

- `node tests/manual/js_form_init_check.js` → 39 assertion, 0 gagal.
- `node tests/manual/js_capital_simulator_check.js` → 38 assertion, 0 gagal
  (termasuk paritas waterfall JS ↔ `AllocationEngine` untuk angka Protokol 4,
  kasus sisa terbesar 1/1/0, pembulatan Rupiah half-up, dan parser nominal).

Grup assertion baru di `qc_fase_4`: **P4** protokol manual, **A** engine murni,
**B** parsing, **C** atomisitas tiga jalur uang, **D** guard HTTP (peran, CSRF,
`_once`, saldo, jatuh tempo, layar terender), **E** peladen tidak mempercayai
nominal klien, **F** integrasi settlement (normal + hari minus), **G**
idempotensi/pengulangan, **H** pemindaian statis & kontrak form, **I** integritas
skema nol migrasi, **J** aturan H-7 hari kerja.

## 5. Temuan Selama Eksekusi

1. **`mbstring` tidak tersedia** pada PHP mesin ini (dan proyek memang belum
   pernah memakainya). `CapitalService` semula memakai `mb_strlen()`/`mb_substr()`
   → diganti helper `charLength()`/`truncate()` berbasis PCRE `/u`, sehingga
   pemotongan tetap pada batas karakter UTF-8 tanpa ekstensi tambahan.
2. **`?>` di dalam komentar PHP mengakhiri mode PHP** (kuirk lama bahasa ini).
   Satu komentar di `tests/qc_fase_4.php` memuat `` `<?php … ?>` `` dan membuat
   berkas gagal di-parse. Komentar diperbaiki + diberi catatan peringatan.
3. **FK `profit_distributions.settlement_id` bersifat `RESTRICT`**, sehingga
   `QcFixture::clearSettlementTraces()` (Fase 3) tidak lagi cukup: fixture harus
   menghapus distribusi lebih dulu dan menolkan `balance_profit`. Ini konsekuensi
   nyata dari integrasi Fase 4 dan sudah diperbaiki di harness.
4. **Cermin Rupiah JS memotong sen** (`Math.floor`) sementara `Formatter::rupiah()`
   membulatkan (`number_format`). Selama Fase 1–3 nilainya selalu bulat sehingga
   tidak terasa; pro-rata Fase 4 memunculkan selisih **Rp 1** (mis. `Rp 108.038,82`).
   Cermin diperbaiki menjadi half-up dan dijaga probe Node.
5. **Aritmetika pro-rata dapat meluap** pada `intdiv($pool × $utilized, $base)`
   begitu kedua operand besar (mis. pool Rp 10 juta × modal Rp 100 juta > 2⁶³).
   Engine memakai pembagian panjang berbasis limb (radix 10⁴) sebagai jalur
   cadangan; kasus `Rp 1 miliar × Rp 1 miliar` diuji eksplisit (assertion `A.12`).
6. **Dokumen Roadmap memakai persen yang sudah dibulatkan** (40,00 % / 35,29 % /
   24,71 %) sehingga tabel dividennya berbeda ± Rp 2 dari hasil sen eksak.
   Tabel diganti nilai sen otoritatif; "Σ modal bekerja mitra = Rp 17.578.750"
   juga ditandai sebagai kebetulan numerik (sama dengan serapan Pengelola), bukan
   bukti kebenaran engine.

## 6. Item Terbuka

| ID | Item | Status |
|---|---|---|
| O-1 | Mode `allocation_mode = 'prorata'` (PRD § 4) | Ditolak dengan pesan jelas; implementasi + panel admin menyusul |
| O-2 | Kalender hari libur nasional untuk H-7 | Fase 4 memakai hari kerja (Sabtu/Minggu dilewati) |
| O-3 | Pengajuan penarikan dari dasbor investor | Fase 5 |
| O-4 | Pindah Kas arah balik (Meja → Brankas) | Tidak perlu (Smart Auto-Split sudah menempatkan kas) |
| O-5 | Pro-rata Reguler memakai `balance_capital` penuh | Dipertahankan; ditinjau saat dasbor investor menyajikan modal aktif vs mengendap |
| O-6 | Pratinjau alokasi pool di Layar C | Ditunda; Layar C hanya menambah ringkasan pada flash |
| O-7 | Beberapa dompet `operator` | Diperlakukan sebagai satu kelompok dengan urutan tetap; belum ada kebutuhan nyata |
| O-8 | `UNIQUE(settlement_id, wallet_id)` pada `profit_distributions` | Tidak mungkin tanpa DDL; QC `G.2` memastikan tidak ada baris ganda per sesi |
| O-9 | Kuota untuk dompet `external` | Selalu `0.00`; peladen menolak kuota bukan-nol untuk tier non-prioritas |

## 7. Bukti Perintah

```bash
bash tests/run_qc.sh         # exit 0 — 10/10 suite, 469 assertion, 0 gagal
node tests/manual/js_form_init_check.js         # 39 assertion, 0 gagal
node tests/manual/js_capital_simulator_check.js # 38 assertion, 0 gagal
php -l <setiap berkas yang diubah>              # tanpa galat sintaks
git status                                       # tanpa artefak baru (tests/.tmp/, *.log ter-gitignore)
```
