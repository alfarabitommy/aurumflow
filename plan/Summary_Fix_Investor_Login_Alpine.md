# Summary — Kotak Kosong `/investor/login`: Bootstrap Alpine pada Layout `auth`

> **Laporan eksekusi perbaikan.** Menindaklanjuti `plan/Audit_Investor_Login_Empty_Box.md`
> (investigasi baca-saja, disetujui) — paket yang dijalankan: **Opsi A + Opsi B1 + penjaga regresi § 6.2 & § 6.3**.
> Proyek: **AurumFlow** · Cakupan: `app/Views/layouts/auth.php`, `app/Views/investor/login.php`,
> assertion QC `qc_fase_5`, probe Node `js_investor_check`, sinkronisasi `AGENTS.md`/`README.md`.
> Status: **SELESAI & TERVERIFIKASI** (gate PHP penuh + uji negatif) · `2026-10-08`.

| Item | Nilai |
|---|---|
| Gejala nyata | Persegi tumpul kosong (tanpa teks/ikon) di antara isian **PIN 6 Angka** dan tombol **Masuk Portofolio** pada `/investor/login` |
| Akar masalah | `app/Views/layouts/auth.php` **tidak pernah memuat `alpine.min.js`** (satu-satunya dari empat layout) ⇒ komponen `investorLogin` tidak di-boot; tombol hapus `<button … x-text="'⌫'">` tanpa teks literal dirender **kosong** |
| Perbaikan (A) | `<script src="…/alpine.min.js" defer>` ditambahkan pada `layouts/auth.php:61`, setelah `app.js` — identik dengan `app.php:81-82`, `operator.php:130-131`, `investor.php:73-74` |
| Pengerasan (B1) | `x-cloak` pada wadah papan angka (`login.php:73`), memakai aturan `[x-cloak]{display:none!important}` yang sudah ada |
| Gate QC | **11 suite · 599 assertion · 0 gagal · exit 0** (sebelumnya 598) |
| Assertion baru | `qc_fase_5 J.13` (+1) · probe Node `js_investor_check` **43 → 45** |
| Uji negatif | Tanpa tag Alpine: `qc_fase_5` **129/130 · 1 FAIL (J.13) · exit 1** dan probe **44/45 · 1 FAIL** ⇒ penjaga benar-benar menggigit (bukan hampa) |
| DDL / jalur uang / validasi peladen / CSS / `app.js` | **Tidak berubah** — akar masalah murni urutan bootstrap skrip di peramban |
| Cache PWA | **Tidak** menaikkan `CACHE_NAME`: perbaikan hanya menyentuh PHP, sedangkan HTML selalu diambil dari jaringan (`sw.js` mode `navigate`) dan daftar aset SW tidak berubah |
| Dampak tambahan | Papan angka PIN hidup di ponsel, `:disabled="submitting"` aktif di dua pintu, dan `/join` kembali mendapat `@scroll` pakta integritas |

---

## 1. Perubahan berkas

| Berkas | Baris | Perubahan |
|---|---|---|
| `app/Views/layouts/auth.php` | `:61` (+1) | `<script src="<?= Security::e(App::url('assets/js/alpine.min.js')) ?>" defer></script>` setelah tag `app.js` |
| `app/Views/investor/login.php` | `:73` (1 atribut) | `<div class="grid grid-cols-3 gap-2" aria-hidden="true" x-cloak>` — **tidak ada** tombol/direktif yang dihapus |
| `tests/qc_fase_5.php` | `:1973-2012` | Assertion statis **`J.13`**: peta view-ber-Alpine → layout-nya, lalu setiap layout wajib memuat `assets/js/alpine.min.js` |
| `tests/manual/js_investor_check.js` | `:268-285` | +2 check: `layout auth memuat bootstrap Alpine` dan `view pintu PIN menyembunyikan papan angka sampai Alpine siap` |
| `AGENTS.md` | `:205-212`, `:325-330` | Kontrak baru “layout ber-Alpine wajib memuat `alpine.min.js`” (dijaga `J.13` + probe) · baseline **599 assertion** · daftar rincian menyertakan laporan ini |
| `README.md` | `:306`, `:309`, `:321` | `qc_fase_5` 129 → **130**, Total 598 → **599**, probe `js_investor_check` 43 → **45 assertion** |
| `plan/Audit_Investor_Login_Empty_Box.md` | — | **Tidak diubah**: dokumen audit tetap menjadi catatan titik-waktu (§ 9 “tidak ada berkas yang diubah” berlaku saat investigasi) |

Tidak ada berkas lain yang disentuh: tanpa perubahan pada `app.js`, CSS/Tailwind, `sw.js`, skema,
service, controller, atau view lain. **Belum ada commit** (pohon kerja Fase 2–5 memang masih belum di-commit).

### 1.1 Mengapa Opsi A aman (bukan sekadar "ikut layout lain")

Kedua skrip `defer` dieksekusi berurutan sesuai posisi dokumen dan selesai **sebelum** `DOMContentLoaded`.
`app.js` mendaftarkan pendengar `alpine:init` saat dievaluasi (`app.js:1573-1581`), sedangkan Alpine
menunggu `DOMContentLoaded` untuk memulai ⇒ `window.Alpine.data('investorLogin', …)` sudah terpasang
ketika `x-data="investorLogin({ pinLength: 6 })"` dievaluasi. Urutan `app.js` lalu `alpine.min.js`
sengaja **disamakan** dengan tiga layout lain — bukan dibalik.

---

## 2. Verifikasi

```bash
php -l app/Views/layouts/auth.php      # No syntax errors detected
php -l app/Views/investor/login.php    # No syntax errors detected
php -l tests/qc_fase_5.php             # No syntax errors detected
node --check tests/manual/js_investor_check.js   # OK

bash tests/run_qc.sh                   # EXIT=0
#   LULUS : 11/11  qc_pdo qc_formatter qc_security qc_seed qc_roadmap_protocol_1
#                   qc_fase_2 qc_fase_3 qc_fase_4 qc_fase_5 qc_sql_injection qc_routing
#   GAGAL : 0/11        TOTAL = 599 assertion

node tests/manual/js_investor_check.js          # 45/45 PASS, 0 FAIL
node tests/manual/js_form_init_check.js         # 39 assertion, 0 gagal
node tests/manual/js_capital_simulator_check.js # 38 assertion, 0 gagal
```

Rincian per suite (total 599): `qc_pdo` 20, `qc_formatter` 43, `qc_security` 32, `qc_seed` 46,
`qc_roadmap_protocol_1` 6, `qc_fase_2` 66, `qc_fase_3` 98, `qc_fase_4` 112, **`qc_fase_5` 130**,
`qc_sql_injection` 15, `qc_routing` 31.
Database target: `aurumflow_dev` (`APP_ENV=local`) — sesuai peringatan AGENTS.md bahwa QC menulis ke
DB `.env`, unggahan nota, dan `storage/`.

Dua probe Node lain ikut dijalankan meski tidak menyentuh berkas yang diubah: keduanya hijau
(39/0 dan 38/0), jadi tidak ada regresi pada permukaan Alpine lain.

---

## 3. Uji negatif — bukti penjaga mengigit

Penjaga regresi yang hijau selamanya tidak membuktikan apa pun. Karena itu tag Alpine **dicabut
sementara** dari `layouts/auth.php`, suite dijalankan, lalu dikembalikan (dan difoto ulang hijau):

| Kondisi | `php tests/qc_fase_5.php` | `node js_investor_check.js` |
|---|---|---|
| Pre-fix (tag Alpine dicabut) | **129/130 · 1 FAIL** — `J.13` FAIL · **exit 1** | **44/45 · 1 FAIL** — `layout auth memuat bootstrap Alpine` |
| Post-fix (tag Alpine kembali) | **130/130 · 0 FAIL** · exit 0 | **45/45 · 0 FAIL** |

Verifikasi akhir setelah pengembalian: `bash tests/run_qc.sh` → **exit 0, 11/11, 599 assertion, 0 gagal**
dan `qc_fase_5` 130/130 (bukan hasil run lama — tree dikembalikan ke isi yang identik lalu digerbang ulang).

---

## 4. Dokumentasi disinkronkan

* `AGENTS.md` — kontrak baru di bagian “Kontrak yang mudah dilanggar” (§ di dekat kontrak Alpine lain),
  berisi sebab-akibat yang bisa terulang dan penjaganya (`J.13` + probe). Baseline angka diperbarui
  **598 → 599 assertion**, dan laporan ini ditambahkan ke daftar rincian.
* `README.md` — tabel suite: `qc_fase_5` **129 → 130** (deskripsi menyebut “bootstrap Alpine pada
  setiap layout ber-Alpine”), **Total 598 → 599**, daftar probe Node `js_investor_check` **43 → 45 assertion**.
* `plan/Plan_Fase_5_AurumFlow.md`, `plan/Summary_Fase_5_AurumFlow.md`, dan
  `plan/Summary_Copy_Inline_Fix.md` **sengaja tidak diubah**: angka 598 di sana adalah *catatan
  historis* hasil gate saat fase/perbaikan itu dieksekusi, bukan baseline hidup. Baseline hidup
  adalah `AGENTS.md` + `README.md`.

---

## 5. Kebersihan artefak

| Artefak | Keadaan |
|---|---|
| `storage/throttle/*.json` | Berkas penghitung dari run verifikasi dihapus; direktori kini hanya berisi `.gitignore` |
| `tests/.tmp/` | Berisi cookie jar QC — **gitignored** (`*.tmp`), tidak muncul di `git status`; dibiarkan (dimiliki harness) |
| `*.log` | Tidak ada `php-*.log` baru. `storage/logs/qc-*.log` & `app-*.log` adalah keluaran QC yang **gitignored** |
| `public/uploads/receipts/` | Bersih dari berkas nota tiruan (fixture QC memurnikannya) |
| `storage/installed.lock` | Tidak ada |
| `git status` | Tidak ada artefak baru yang terbaca git. Satu-satunya entri untracked di `storage/` adalah `storage/throttle/.gitignore`, yang **sudah ada sejak 1 Oktober** (sebelum eksekusi ini) dan justru berkas yang menjaga direktori tetap terlacak |
| `storage/logs/php-2026-09-30.log` | **Sudah ada sebelum eksekusi ini** (dua fatal `php -r` ad-hoc tertanggal 30 Sep 2026). Sengaja **tidak dihapus** — bukan keluaran saya, dan menghapus artefak debug milik orang lain bukan wewenang perbaikan ini. Bila kriteria AGENTS.md (“tidak ada `storage/logs/php-*.log`”) ingin dipenuhi secara harfiah, berkas itu boleh dihapus manual |

---

## 6. Catatan & item terbuka (di luar lingkup, tidak diubah)

1. **B2 tidak diterapkan** (dan memang tidak disetujui): teks fallback statis `⌫` di dalam tombol
   hapus tidak ditambahkan. B1 (`x-cloak`) dipilih karena lebih bersih: bila JS gagal dimuat,
   papan angka tersembunyi sepenuhnya tanpa menyisakan kontrol mati yang terlihat.
2. **Printer probe Node mencetak setiap label sebagai `[OK]` lebih dulu, lalu mencetak daftar
   kegagalan (`js_investor_check.js:469-475`)** — sehingga check yang gagal muncul dua kali
   (satu `[OK]`, satu `[GAGAL]`). Perilaku lama; baris ringkasan `HASIL …` adalah acuan. Tidak
   diubah agar diff tetap fokus.
3. **`QcFixture::clearPinThrottle()` (`tests/bootstrap.php:1333`) tidak punya pemanggil**;
   `qc_fase_5` memanggil `PinThrottle::clearAll()` langsung (`:684`, `:795`, `:884`), padahal
   AGENTS.md menyebut fixture itu sebagai bagian reset Fase 5. Ketidaksesuaian dokumentasi
   pramasalah ini dicatat untuk pass pemeliharaan berikutnya — tidak diubah di sini.
4. **Temuan sampingan audit § 8 tetap terbuka:** state `opened` yang tidak pernah dibaca di
   `investor/onboarding.php:55`, dan `x-ref="pinField"` di `investor/login.php:61` yang tidak
   dibaca kode mana pun (`formField()` menelusuri DOM dari `$root`).
5. **Verifikasi di peramban belum dijalankan** (tidak ada peramban di lingkungan ini). Bukti yang
   ada bersifat statis + probe Node. Pemeriksaan manual yang disarankan tetap seperti audit § 7:
   buka `/investor/login` pada viewport 360px, pastikan `window.Alpine` bukan `undefined`,
   `document.querySelectorAll('.af-btn--ghost').length === 11` (10 angka + 1 hapus), dan isian
   `name="pin"` berhenti pada 6 digit.
6. **Bila kelak `public/assets/js/app.js` diubah**, ingat `sw.js` melayani aset statis
   *cache-first* ⇒ `CACHE_NAME` (`aurumflow-shell-v1`) **wajib** dinaikkan. Perbaikan ini tidak
   menyentuh `app.js`, jadi tidak perlu.

---

## 7. Rujukan

* `plan/Audit_Investor_Login_Empty_Box.md` — investigasi baca-saja: bukti, akar masalah (§ 3),
  opsi penyelesaian (§ 4), penjaga yang diusulkan (§ 6).
* `AGENTS.md` — kontrak baru “Layout yang merender view ber-Alpine wajib memuat `alpine.min.js`”.
* `tests/qc_fase_5.php` `J.13`; `tests/manual/js_investor_check.js` § 2.
