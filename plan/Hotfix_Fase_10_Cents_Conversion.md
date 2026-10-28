# Hotfix Fase 10 — Konversi Sen ↔ Desimal (Mitra Lapangan)

| | |
|---|---|
| **Jenis** | Hotfix produksi (tanpa perubahan skema, tanpa perubahan PWA shell) |
| **Ruang lingkup** | `app/Services/OfficerService.php` (lapisan tampilan) + penjaga QC `tests/qc_fase_10.php` |
| **Gejala yang dilaporkan** | Saldo hak / nominal buku hak tampil **100× lipat** (mis. Rp 50.000 menjadi Rp 5.000.000) |
| **Sebab sebenarnya** | `Formatter::rupiah()` menerima **rupiah**, tetapi empat tempat di `OfficerService` mengirim **sen** |
| **Bukan sebabnya** | Jalur `insert()`/`update()` — semuanya **sudah** memakai `TellerService::centsToDecimal()` |
| **Verifikasi** | `bash tests/run_qc.sh` → **16/16 LULUS, 989 assertion, 0 gagal**; `php tests/qc_fase_10.php` → **89/89 PASS** |
| **Status** | **Selesai** |

---

## 1. Ringkasan eksekusi

Laporan menyebut nilai sen mentah masuk ke kolom `DECIMAL(14,2)`
(`officers.balance_payable`, `officer_ledger_entries.amount`/`balance_after`). Audit
baris-per-baris menunjukkan **tidak ada** penulisan sen mentah: ketiga jalur tulis sudah
mengonversi lebih dulu. Penggelembungan 100× memang nyata, tetapi letaknya di **lapisan
tampilan**: empat pemanggilan `Formatter::rupiah(...)` menerima nilai bersatuan **sen**,
padahal helper itu memformat **rupiah**.

Perbaikan diterapkan **hanya** di lapisan tampilan. Melakukan pembagian lagi sebelum
`insert()` (sesuai instruksi awal) akan membagi **dua kali** dan membuat seluruh saldo hak
**100× terlalu kecil** — kerusakan data yang jauh lebih mahal daripada bug tampilan.

---

## 2. Audit jalur tulis (bukti: konversi sudah ada)

| Method | Nilai kanonik yang ditulis | Bukti |
|---|---|---|
| `OfficerService::injectBonus()` | `$amount = TellerService::centsToDecimal($amountCents)`; `balance_after => centsToDecimal($balanceAfter)`; `Officer::adjustBalance(..., centsToDecimal($amountCents))` | baris 286, 309, 314 |
| `OfficerService::disburse()` | `$amount = centsToDecimal($amountCents)`; `balance_after => centsToDecimal($balanceAfter)`; pos kas `centsToDecimal(-$amountCents)`; `adjustBalance(..., centsToDecimal(-$amountCents))` | baris 366, 420, 427, 434 |
| `OfficerService::accrueForSettlement()` | `amount => centsToDecimal($amountCents)`; `balance_after => centsToDecimal($balanceAfter)`; `base_amount => $quote['officer_base']` (desimal dari `quote()`); `adjustBalance(..., centsToDecimal($amountCents))` | baris 655, 656, 663 |
| `OfficerService::create()` | `cut_pct => AllocationEngine::hundredthsToDecimal()`; `balance_payable => '0.00'` | baris 134–138 |

Bukti empiris (basis data nyata, bonus Rp 50.000):

```
DB balance_payable        = 50000.00      ← benar (desimal kanonik)
DB entry amount/after     = 50000.00 / 50000.00
```

Karena itu **tidak ada** perubahan pada `insert()`/`update()` mana pun, dan **tidak ada**
migrasi data: nilai di basis data tidak pernah rusak.

---

## 3. Akar masalah & perbaikan (lapisan tampilan)

`Formatter::rupiah(mixed $value)` memformat **rupiah** (`number_format($value, 0)`), sedangkan
`TellerService::moneyToCents()`/perhitungan internal menghasilkan **sen**. Empat tempat
mengirim sen tanpa konversi:

| Lokasi | Sebelum (salah) | Sesudah (benar) |
|---|---|---|
| `overview()` — `rows[].balance_label` | `Formatter::rupiah($balanceCents)` | `Formatter::rupiah(TellerService::centsToDecimal($balanceCents))` |
| `overview()` — `summary.payable_label` | `Formatter::rupiah($payableCents)` | `Formatter::rupiah(TellerService::centsToDecimal($payableCents))` |
| `overview()` — `summary.bonus_open_label` | `Formatter::rupiah(self::openBonusCents())` | `Formatter::rupiah(TellerService::centsToDecimal(self::openBonusCents()))` |
| `recentEntries()` — `amount_label` | `Formatter::rupiah($amountCents)` | `Formatter::rupiah(TellerService::centsToDecimal($amountCents))` |

Catatan kepatuhan: konversi memakai `TellerService::centsToDecimal()` — bukan
`number_format()` baru — karena `AGENTS.md` mewajibkan **satu pemilik** konversi uang
(`qc_formatter` 3.9 juga menolak `number_format()` tanpa kedua separator eksplisit).

Bukti sesudah perbaikan (skenario yang sama):

```
balance_label = Rp 50.000      payable_label = Rp 50.000
recent        = + Rp 50.000    bonus_open    = Rp 50.000
```

Portal petugas (`/officer/dashboard`) **tidak terpengaruh**: sejak awal ia memakai string
desimal kanonik dari `OfficerLedgerEntry::totalsForOfficer()`.

---

## 4. Penjaga regresi (`tests/qc_fase_10.php`, 89 assertion)

| Assertion | Isi |
|---|---|
| **B.14** | Bonus Rp 50.000 tersimpan sebagai `50000.00` pada `amount`, `balance_after`, **dan** `balance_payable` — menutup kelas bug "sen mentah masuk DECIMAL" |
| **B.15** | Layar G menampilkan `Rp 50.000` pada keempat permukaan (baris, ringkasan, buku hak, bonus belum diserap) — menutup penggelembungan tampilan |
| **B.16** | Pemindai statis: **tidak boleh** ada `Formatter::rupiah(<variabel …Cents>)` tanpa `centsToDecimal()` pada `OfficerService`, `OfficerPortalService`, dan `OfficerController` |

Uji mutasi (bukti penjaga benar-benar menggigit):

| Keadaan | Hasil |
|---|---|
| Bug dikembalikan (`Formatter::rupiah($balanceCents)`) | `qc_fase_10` **87/89** — `B.15` **FAIL**, `B.16` **FAIL** |
| Perbaikan dipulihkan | `qc_fase_10` **89/89 PASS** |

---

## 5. Verifikasi akhir

```bash
bash tests/run_qc.sh                 # 16/16 LULUS, 989 assertion, 0 gagal (exit 0)
php tests/qc_fase_10.php             # 89/89 PASS, 0 FAIL
node tests/manual/js_settlement_officer_check.js   # 46 assertion, 0 gagal
node tests/manual/js_form_init_check.js            # 48 assertion, 0 gagal
node tests/manual/js_bridge_fee_check.js           # 65 assertion, 0 gagal
node tests/manual/js_kasbon_check.js               # 24 assertion, 0 gagal
node tests/manual/js_capital_simulator_check.js    # 38 assertion, 0 gagal
node tests/manual/js_investor_check.js             # 45/45 PASS
```

* `storage/logs/php-*.log`: **nihil**.
* `public/assets/js/app.js`: **tidak berubah** pada hotfix ini → `CACHE_NAME` tetap
  **`aurumflow-shell-v5`** (tidak perlu dinaikkan; `qc_fase_6` G.17–G.18, `qc_fase_7` F.7,
  `qc_fase_8` I.5, `qc_fase_9` H.5 tetap lulus).
* Skema & migrasi: **tidak berubah** (nol ALTER, M4 tetap).

---

## 6. Pemeriksaan lapangan (baca-saja)

Untuk memastikan basis data produksi tidak pernah menyimpan sen mentah:

```sql
-- Tidak boleh ada nilai yang berakhiran ratusan ribu pada baris uji Rp 50.000.
SELECT id, entry_type, amount, balance_after
FROM officer_ledger_entries
ORDER BY created_at DESC
LIMIT 20;

-- Total kewajiban harus sama dengan Σ kredit − Σ debit.
SELECT o.id, o.balance_payable,
       COALESCE(SUM(CASE WHEN e.entry_type = 'disbursement' THEN -e.amount ELSE e.amount END), 0) AS ledger_sum
FROM officers o
LEFT JOIN officer_ledger_entries e ON e.officer_id = o.id
GROUP BY o.id, o.balance_payable;
```

Bila `balance_payable` ≠ `ledger_sum`, jalankan `qc_fase_10` (grup I.3) untuk memastikan
masalahnya data lapangan, bukan kode.

---

## 7. Checklist

- [x] Audit ketiga jalur tulis: **tidak ada** sen mentah (konversi `centsToDecimal()` sudah ada).
- [x] Perbaikan diterapkan pada empat pemanggilan `Formatter::rupiah()` di `OfficerService`.
- [x] Tidak ada pembagian ganda (yang akan membuat saldo 100× terlalu kecil).
- [x] Penjaga QC B.14 (tulis), B.15 (tampil), B.16 (statis) ditambahkan dan diuji-mutasi.
- [x] `bash tests/run_qc.sh` **exit 0** — 16/16 suite, 989 assertion, 0 gagal.
- [x] Seluruh probe Alpine (6 berkas) lulus tanpa perubahan aset frontend.
- [x] Baseline angka di `AGENTS.md`, `README.md`, dan `plan/Summary_Fase_10_*` diselaraskan (989).
