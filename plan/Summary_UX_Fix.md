# Summary UX Fix — Kolom "Kuota Serapan" pada Layar Kelola Modal

> **Status dokumen (pasca-Fase 9 — sinkronisasi dokumentasi).** Seluruh angka dan perilaku di
> bawah ini — termasuk (bila ada) penyebutan **13 tabel** atau kurang, **14 suite / 853
> assertion**, `CACHE_NAME` **v3**, dan isian **`coord_fee`** — adalah **potret fase tersebut**,
> bukan keadaan sekarang. Keadaan terkini: **14 tabel** (termasuk `operational_expenses`, nol `ALTER`),
> `CACHE_NAME` **`aurumflow-shell-v4`**, **15 suite / 900 assertion / 0 gagal**, beban operasional
> dicatat sebagai **voucher kas** di meja kasir lalu diserap otomatis oleh tutup buku
> (`total_cash = pendapatan`). Rujukan terkini: `plan/Summary_Fase_9_OPEX_AurumFlow.md`.


> **Perbaikan UX pascarilis (Fase 4)**
> Pemicu: temuan pengujian manual — Pengelola melihat kotak isian kuota `0,00 %`
> yang menyesatkan, padahal mesin alokasi memakai kuota dasar **50 %** dari
> `system_settings`.
> Hasil gerbang: **`bash tests/run_qc.sh` exit 0 — 10/10 suite, 469 assertion, 0 gagal**

---

## 1. Masalah

Tabel **Portofolio Mitra** menampilkan **satu bentuk sel yang sama untuk semua
tier**: `<input>` angka kuota. Akibatnya:

* **Pengelola** (`tier='operator'`) melihat isian `0,00 %` — padahal dompet ini
  menyerap lebih dulu sebesar `operator_base_quota_pct` (50 %). Angka nol di
  situ bukan data, melainkan artefak: kolom `locked_quota_pct` memang hanya
  bermakna untuk Mitra Prioritas.
* **Mitra Reguler** (`tier='external'`) juga melihat isian `0,00 %` yang tidak
  pernah dipakai — tier ini selalu pro-rata saldo atas sisa kebutuhan.
* Isian yang "terkunci" lewat `:readonly` tetap terbaca sebagai **kolom yang
  gagal disimpan**, bukan sebagai kebijakan.

## 2. Perubahan

### 2.1 `app/Controllers/CapitalController.php`

* Menambahkan pembacaan **kuota dasar Pengelola** dari sumber yang sama dengan
  mesin alokasi (`AllocationEngine::configuredOperatorQuota()` →
  `system_settings.operator_base_quota_pct`) lewat helper baru
  `operatorQuotaLabel()`.
* Nilainya dikirim ke view sebagai `operatorQuotaLabel` (mis. `'50,00 %'`).
  Bila kebijakan tidak terbaca, label berisi `—` (bukan `0,00 %` yang
  menyesatkan; penyebabnya sudah tampil pada banner kesalahan kebijakan).
* Data per dompet `is_priority` dihapus dari `$walletViews` — view kini bercabang
  langsung pada `tier`, sehingga tidak ada lagi field yang hanya dipakai untuk
  menyalakan/mematikan `:readonly`.

### 2.2 `app/Views/operator/capital.php` — sel "Kuota Serapan" bercabang per tier

| Tier | Tampilan baru | Ada `<input>`? |
|---|---|---|
| `operator` (Pengelola) | Badge **`50,00 % (Sistem)`** + keterangan "Kuota dasar pengelola dari Pengaturan Sistem." | **Tidak** |
| `family` (Mitra Prioritas) | `<form>` + `<input name="quota_value">` + tombol **Simpan** (persis seperti sebelumnya) | **Ya** (satu-satunya) |
| `external` (Mitra Reguler) | Penanda **`Pro-rata`** (miring, warna muted) + keterangan "Tanpa kuota — mengikuti sisa kebutuhan." | **Tidak** |

Catatan adaptasi dari contoh pada permintaan:

* `Formatter::number()` **tidak ada** di kelas `Formatter` (hanya `rupiah()`,
  `gram()`, `tanggalId()`, `parseRupiah()`, `parseGram()`). Label dibentuk dengan
  rantai yang sudah dipakai layar yang sama (`quotaFieldValue()` — kanonik dua
  desimal + koma + `%`), sehingga aturan "angka tampilan hanya lewat `Formatter`"
  tetap terjaga tanpa menambah API baru.
* Warna penanda memakai token tema **`text-muted`** (#94A3B8 — nilai yang sama
  dengan `slate-400`) alih-alih `text-slate-400`, agar konsisten dengan sistem
  desain "Obsidian Gold" dan tidak menambah kelas warna di luar tema.
* `:readonly` pada isian Mitra Prioritas kini cukup `:readonly="submitting"`
  (dulu `:readonly="submitting || <true untuk tier lain>"`), dan **tidak ada**
  kontrol `:disabled` baru — kontrak H.1 tetap terjaga.

### 2.3 Dokumentasi

* `docs/AurumFlow UI UX Documents.md` — wireframe Layar E dan penjelasan bagian
  **B. Portofolio Mitra** kini menyebut ketiga varian sel kuota (badge sistem /
  satu-satunya isian / penanda pro-rata).
* `AGENTS.md` — kontrak baru: sel kuota bersifat per-tier; endpoint
  `POST /operator/capital/quota` tetap menolak kuota bukan-nol untuk tier
  non-prioritas (pertahanan lapis peladen), dijaga assertion `D.20`–`D.24`.

## 3. Penjaga regresi (assertion baru di `qc_fase_4`)

| ID | Yang dijaga |
|---|---|
| `D.20` | Tidak ada form kuota untuk dompet Pengelola & kedua Mitra Reguler QC |
| `D.21` | Tepat **satu** form kuota, dan itu milik dompet Mitra Prioritas |
| `D.22` | Baris Pengelola menampilkan badge `50,00 % (Sistem)` + keterangan kebijakan |
| `D.23` | Baris Mitra Reguler menampilkan penanda `Pro-rata` tanpa isian |
| `D.24` | Badge **mengikuti kebijakan hidup**: setelah `operator_base_quota_pct` diubah ke `35.00`, badge berubah menjadi `35,00 % (Sistem)` — membuktikan nilainya tidak di-hardcode di view |

Assertion dihitung **per dompet QC**, bukan global, supaya dompet yang dibuat
manual operator (lihat § 4) tidak membuat penjaga ini rapuh.

Endpoint `POST /operator/capital/quota` tidak berubah: permintaan kuota untuk
tier non-prioritas tetap dijawab `422` (assertion `D.12`), sehingga penyembunyian
isian murni lapisan UI — bukan pelemahan validasi.

## 4. Temuan tambahan saat verifikasi (penting)

Saat menjalankan QC setelah perbaikan, ditemukan **dompet manual operator**
("Pak Haji", `tier='family'`, kuota 20 %) yang dibuat lewat form "Tambah Mitra"
saat pengujian lapangan. Karena `database/schema.sql` memakai
`CREATE TABLE IF NOT EXISTS` (tanpa `DROP`), data itu bertahan antar-jalankan QC
dan **ikut menyerap modal** — angka protokol bergeser (Mitra Reguler A turun dari
Rp 6.204.264,71 menjadi Rp 2.068.088,24) dan `qc_fase_3` berisiko gagal juga.

Perbaikannya (di luar permintaan awal, tetapi wajib demi suite yang deterministik):

* `QcFixture::neutraliseWallets()` — menolkan saldo, dividen, kuota, dan status
  penarikan **seluruh** dompet **tanpa menghapus barisnya** (pola yang sama dengan
  `resetTellerState()` yang menolkan saldo Kas Meja Bakar). Dipanggil oleh
  `clearSettlementTraces()` dan `resetCapitalState()`.
* Assertion `G.5`/`G.6` — mereproduksi situasi nyata itu: dompet manual berkuota
  20 % + saldo besar harus dinetralkan fixture, dan angka protokol tetap eksak.

Konsekuensi yang perlu diketahui operator: **menjalankan `bash tests/run_qc.sh`
menolkan saldo & kuota semua dompet modal** pada database yang ditunjuk `.env`
(baris akun & dompet tetap ada; setoran dapat dicatat ulang lewat layar Kelola
Modal). Ini sudah diperingatkan di `AGENTS.md` dan `README.md`.

## 5. Bukti

```
bash tests/run_qc.sh                            # exit 0 — 10/10 suite, 469 assertion, 0 gagal
                                                # (qc_fase_4: 112 assertion)

# Kutipan HTML nyata dari GET /operator/capital:
#   Pengelola         | 50,00 % (Sistem) Kuota dasar pengelola dari Pengaturan Sistem.
#   Mitra Prioritas   | [ 20,00 ] % Simpan
#   Mitra Reguler A   | Pro-rata Tanpa kuota — mengikuti sisa kebutuhan.
#   Mitra Reguler B   | Pro-rata Tanpa kuota — mengikuti sisa kebutuhan.
```
