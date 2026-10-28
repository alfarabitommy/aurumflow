# Plan — Refactor UI "Kelola Mitra Lapangan" (Compact Card)

> **Status:** approved (belum dieksekusi) · **Fase:** 10 (pascarilis, UI/UX only) · **Pemilik:** Frontend
> **Berkas sasaran:** `app/Views/operator/petugas.php` (518 baris → ± 520 baris, susunan ulang total)
> **Referensi gaya:** `app/Views/operator/teller.php` (modal talangan/opex), `resources/css/tailwind.input.css`

## 0. Ringkasan satu paragraf

`petugas.php` hari ini merender **enam formulir penuh** untuk **setiap** petugas (tarif, status,
bonus, pencairan, PIN) secara inline dan selalu terbuka di dalam `<ul><li>`, sehingga satu layar
dengan 5 petugas menghasilkan ± 30 form terkembang dan scrolling vertikal yang ekstrem. Refactor ini
mengubah daftar menjadi **grid kartu ringkas 1→2 kolom**, memindahkan **dua aksi harian** (Beri Bonus,
Cairkan Hak) ke **modal Alpine per kartu**, dan menyembunyikan **tiga aksi langka** (Ubah Tarif, Reset
PIN, Nonaktifkan/Aktifkan) ke dalam `<details>` "⚙ Pengaturan Akun". **Nol perubahan peladen**: tidak
ada Controller, Service, Model, Route, `app.js`, `sw.js`, maupun `app.min.css` yang tersentuh.

## 1. Batas keras (frozen surface)

| Wilayah | Status | Catatan |
| --- | --- | --- |
| `OfficerController`, `OfficerService`, `Models/Officer*`, `app/routes.php` | **BEKU** | `GET /operator/petugas` + 5 POST tidak berubah |
| `public/assets/js/app.js` | **BEKU** | Tanpa `Alpine.data()` baru — sesuai AGENTS.md (komponen baru di aset ter-cache = tombol hilang total) |
| `public/assets/css/app.min.css` | **BEKU** | Hanya kelas yang **sudah terkompilasi** (audit § 5.1) |
| `public/sw.js` / `CACHE_NAME = 'aurumflow-shell-v5'` | **BEKU** | Tidak naik versi karena aset statis tidak berubah; `qc_fase_6` G.17/G.18 tetap hijau |
| `app/Views/layouts/operator.php` | **BEKU** | Kontainer `<main class="… max-w-2xl …">` = lebar efektif 42rem |
| `docs/`, ERD, skema | **BEKU** | Nol perubahan skema (UI murni) |
| `tests/` | **opsional, aditif** | Lihat § 10 (usulan assertion penjaga, tidak wajib untuk kelulusan) |

## 2. Baseline — audit `petugas.php` saat ini

| Bagian | Baris | Masalah |
| --- | --- | --- |
| Kartu ringkasan (`af-facts`, 4 fakta) | 53–85 | Baik, dipertahankan |
| Kartu "Tambah Mitra Lapangan" (form #1) | 87–206 | Selalu terbuka, ± 120 baris, mendorong daftar ke bawah |
| Daftar `<ul><li>` + 5 form per petugas | 208–490 | **Akar keluhan**: 5 form × N petugas selalu terbuka |
| Buku Hak Terbaru (20 entri) | 492–518 | Baik, cukup dirapatkan |

### 2.1 Enam form yang WAJIB dipertahankan (brief menyebut "5" tetapi menyebut enam nama — checklist ini menutup keenamnya)

| # | Aksi | `action` (`App::url`) | Field identitas | Field data | `data-confirm` |
| --- | --- | --- | --- | --- | --- |
| F1 | `store` | `operator/petugas` | — | `officer_name`, `officer_phone`, `officer_pin`, `officer_pin_confirm`, `officer_cut_pct` | — |
| F2 | `updateCut` | `operator/petugas/potongan` | `cut_officer_id` (**officer.id**) | `cut_pct` | — |
| F3 | `resetPin` | `operator/petugas/pin` | `pin_user_id` (**user.id**) | `reset_pin`, `reset_pin_confirm` | "Ganti PIN petugas ini? PIN lama tidak berlaku lagi." |
| F4 | `setStatus` | `operator/petugas/status` | `status_user_id` (**user.id**) | `status_active` (`'0'`/`'1'`) | teks kondisional aktif/nonaktif |
| F5 | `bonus` | `operator/petugas/bonus` | `bonus_officer_id` (**officer.id**) | `bonus_amount`, `bonus_notes` | "Catat bonus ini? …" |
| F6 | `disburse` | `operator/petugas/cair` | `disburse_officer_id` (**officer.id**) | `disburse_amount`, `disburse_pos`, `disburse_notes` | "Cairkan saldo hak petugas ini? …" |

Setiap form juga membawa `Security::csrfField()` + `<input type="hidden" name="Security::FORM_FIELD"
value="$formToken">`. Atribut yang **tidak boleh berubah**: `method="post"`, `action`, urutan/`name`
field, `maxlength` (`officer_name` 100, `officer_phone` 20, `cut_pct` 6, `bonus_amount`/`disburse_amount`
20, catatan 255, PIN `$pinLength` + `pattern="[0-9]*"`), `novalidate` (F1, F2, F3, F5, F6), dan string
`data-confirm` apa adanya.

### 2.2 Data peladen yang tersedia (tanpa perubahan controller)

`$overview['rows'][*]` = `id`, `user_id`, `name`, `phone`, `cut_pct_label`, `cut_pct_input`,
`balance_label`, `is_active`, `status_label`, `updated_label`.
`$overview['summary']` = `count`, `active`, `payable_label`, `pct_total_label`, `cap_label`,
`bonus_open_label`. `$overview['recent']`, `$registers` (token → `label` + `saldo_label`),
`$formToken`, `$pinLength`, `$input`, `$error`.
**Semua angka tampilan sudah diformat `Formatter`; view tidak pernah memformat ulang** (larangan
`number_format()` di view).

## 3. Keputusan yang sudah diambil (decision record)

| # | Keputusan | Alternatif ditolak | Alasan |
| --- | --- | --- | --- |
| D1 | **State modal per kartu** via literal `x-data` di view | `Alpine.data('officerCards')` di `app.js`; `Alpine.store` global | Aset `app.js`/`app.min.css` disajikan *cache-first*; komponen baru yang belum ada di shell peramban = `ReferenceError` → tombol & modal hilang total (regresi nyata Fase 5/6). Literal `x-data` = nol perubahan aset, nol kenaikan `CACHE_NAME`, nol probe Node baru |
| D2 | Form "Tambah Mitra" masuk **`<details>` "Tambah Mitra Baru"** (tertutup default) | Modal; tetap terbuka | Menghapus ± 120 baris dari pandangan tanpa menambah kompleksitas state; otomatis `open` saat store gagal validasi |
| D3 | **Strict allow-list** kelas Tailwind yang sudah terkompilasi | Satu kali rebuild CSS | Rebuild memaksa `CACHE_NAME` v5→v6 + `sw.js` + catatan `AGENTS.md` + `qc_fase_6` G.17/G.18 + audit ulang semua assertion aset statis. Rasio risiko/manfaat buruk untuk refactor markup |

## 4. Strategi state Alpine.js (D1)

### 4.1 Bentuk state (literal, satu per petugas)

Setiap petugas dibungkus **satu `<div>` polos** (bukan `af-card`) yang memiliki:

```
x-data="{ bonusOpen: <bool dari peladen>, cairOpen: <bool dari peladen>, submitting: false }"
```

* `submitting` tetap dipakai `:class="{ 'opacity-70 cursor-not-allowed': submitting }"` +
  `:readonly="submitting"` pada **semua kontrol**, dan `:disabled="submitting"` **hanya** pada
  `<button>` (kontrak AGENTS.md; kontrol `disabled` hilang dari payload → `422`).
* Boolean awal **dirender peladen** sebagai literal `true`/`false` — **tidak pernah** string dinamis,
  sehingga tidak ada risiko escaping di dalam ekspresi Alpine.
* Tidak ada id/nama/nominal yang dimasukkan ke ekspresi Alpine; nilai identitas tetap hidup di
  `<input type="hidden">` yang dirender peladen.

### 4.2 Mengapa modal **tidak** boleh berada di dalam `.af-card`

`.af-card` memakai `backdrop-blur-xl` (`backdrop-filter`) dan `overflow-hidden`. Keduanya
menjadikan kartu sebagai *containing block* bagi `position: fixed` **dan** memotong luapannya →
`fixed inset-0` akan menutup kartu saja, bukan viewport. Karena itu susunannya:

```
<div class="grid gap-3 sm:grid-cols-2">            ← grid kartu
  <div x-data="…">                                 ← pembungkus polos (grid item)
      <article class="af-card …"> … tombol aksi … </article>
      <div x-cloak x-show="bonusOpen" class="fixed inset-0 z-30 …"> modal bonus </div>
      <div x-cloak x-show="cairOpen"  class="fixed inset-0 z-30 …"> modal pencairan </div>
  </div>
  …
</div>
```

Modal tetap anak dari pembungkus polos (di luar `af-card`), dan berada di luar alur grid karena
`position: fixed`. Pola ini identik dengan `teller.php` (modal di dalam `<div x-data="tellerForm(…)">`
polos, bukan di dalam kartu).

### 4.3 Membuka/menutup

| Pemicu | Ekspresi | Catatan |
| --- | --- | --- |
| Tombol "Beri Bonus" | `@click="bonusOpen = true"` | `type="button"` (bukan submit) |
| Tombol "Cairkan Hak" | `@click="cairOpen = true"` | `type="button"` |
| Tombol "Tutup" di modal | `@click="bonusOpen = false"` | `af-btn af-btn--ghost px-3 text-xs` |
| Klik latar | `@click.self="bonusOpen = false"` | pada elemen overlay |
| `Esc` | `@keydown.escape.window="bonusOpen = false"` | ekspresi murni; tanpa `try`/blok (`Alpine Expression Error`) |

Atribut aksesibilitas: `role="dialog"`, `aria-modal="true"`,
`aria-labelledby="af-bonus-<officer.id>"` (id unik per kartu, UUID aman). **`x-cloak` wajib** supaya
tidak ada kotak hantu bila Alpine gagal dimuat (pelajaran Fase 5). Tanpa focus-trap — konsisten
dengan modal Fase 6/9 yang sudah ada; dicatat sebagai batasan, bukan regresi.

### 4.4 Membuka ulang modal yang benar setelah `422` (tanpa perubahan controller)

`OfficerController::execute()` merender ulang view dengan `$error` + `$input` yang masih terisi, tetapi
**tidak** memberi tahu form mana yang gagal. Diskriminator yang andal: tepat **satu** field identitas
yang non-kosong (karena `readInput()` mengisi field lain dengan default `''`). Turunkan di awal view:

| `$input[...]` non-kosong (urutan cek) | Form gagal | Yang otomatis terbuka |
| --- | --- | --- |
| `cut_officer_id` | F2 | `<details open>` "Pengaturan Akun" petugas itu |
| `pin_user_id` | F3 | `<details open>` |
| `status_user_id` | F4 | `<details open>` |
| `bonus_officer_id` | F5 | `bonusOpen: true` |
| `disburse_officer_id` | F6 | `cairOpen: true` |
| `officer_name`/`officer_phone` | F1 | `<details open>` "Tambah Mitra Baru" |

Pencocokan baris: field `*_officer_id` ↔ `$row['id']`, field `*_user_id` ↔ `$row['user_id']`.
Efek samping yang diinginkan: **nilai yang gagal ikut terisi ulang** di modal yang sama
(`bonus_amount`, `bonus_notes`, `disburse_amount`, `disburse_notes`, `cut_pct`, `status_active`
sudah berupa hidden input; `disburse_pos` memilih ulang `<option>` yang dikirim). Untuk itu view
memakai helper per baris: `$val(field)` mengembalikan `$input[field]` **hanya** bila baris ini yang
gagal, selain itu `''` (F5/F6) atau nilai bawaan peladen `cut_pct_input` (F2).
**PIN tetap tidak pernah dikembalikan**: `reset_pin`, `reset_pin_confirm`, `officer_pin*` tidak
pernah dirender ulang dari `$input` (kontrak "PIN tidak pernah kembali").
Pesan `$error` dirender **dua kali**: banner halaman (sudah ada) **dan** di dalam modal/`<details>`
yang terbuka, karena banner di balik overlay tidak terbaca.

## 5. Spesifikasi struktur Tailwind (D3)

### 5.1 Audit allow-list kelas (sudah diverifikasi terhadap `public/assets/css/app.min.css`)

| Kebutuhan | Kelas terpakai | Status |
| --- | --- | --- |
| Grid kartu | `grid gap-3 sm:grid-cols-2` | ✅ terkompilasi |
| Kartu | `af-card flex flex-col gap-3 p-4` (+ `border-surface-800/60` untuk nonaktif) | ✅ |
| Nama & telepon | `truncate text-sm font-semibold text-pure`, `af-num text-xs text-muted` | ✅ |
| Badge aktif | `rounded-full bg-emerald-cash/15 px-2 py-1 text-[0.68rem] font-semibold uppercase tracking-[0.14em] text-emerald-cash` | ✅ |
| Badge nonaktif | `rounded-full bg-surface-800/60 px-2 py-1 … text-muted` | ✅ |
| Hero saldo | `af-num text-3xl font-bold text-emerald-cash` (label `text-[0.68rem] font-semibold uppercase tracking-[0.16em] text-muted`) | ✅ |
| Baris tarif | `af-num text-xs text-gold-primary` + `text-xs text-muted` | ✅ |
| Tombol aksi | `grid gap-2 sm:grid-cols-2` + `af-btn af-btn--primary` / `af-btn--ghost` | ✅ |
| `<details>` | `rounded-xl border border-surface-800 bg-surface-800/40 p-3` + `summary` `cursor-pointer text-xs font-semibold text-gold-primary` | ✅ |
| Overlay modal | `fixed inset-0 z-30 overflow-y-auto bg-obsidian-950/85 px-4 py-6 backdrop-blur-sm` | ✅ (resep `teller.php`) |
| Panel modal | `af-card mx-auto mt-6 max-w-md` | ✅ |
| Teks bantu | `text-[0.7rem] leading-relaxed text-muted`, `mt-1 block` | ✅ |

**Kelas yang TIDAK tersedia → jangan dipakai** (terverifikasi absen): `.grid-cols-2` (hanya
`sm:grid-cols-2`), `.flex-1`, `.bg-surface-800/30` (pakai `/40`), `.border-gold-primary/40` (hanya
varian `hover:`), `.sm:col-span-2`/`col-span-*`, `.z-40`/`.z-50`, `.max-w-lg`.
Konsekuensi desain: tombol berdampingan memakai `grid gap-2 sm:grid-cols-2` (bukan `flex` +
`flex-1`), *empty state* diletakkan **di luar** grid (tidak butuh `col-span`).

### 5.2 Z-index & lapisan

`layout/operator.php` header `sticky … z-20` → overlay modal memakai **`z-30`** (satu-satunya nilai
di atas header yang tersedia). Tidak ada modal bertumpuk di halaman ini, jadi satu tingkat cukup.

### 5.3 Wireframe kartu petugas

```
┌────────────────────────────────────────────────┐
│ Nama Petugas            [ AKTIF ]  ← badge     │  header: name + phone + badge
│ 0812… (af-num)                                 │
│ ────────────────────────────────────────────── │
│ SALDO HAK                                      │  hero stat (paling dominan)
│ Rp 1.250.000          ← text-3xl text-emerald  │
│ Potongan 2,50 % dari laba kotor                │  cut_pct + konteks
│ ────────────────────────────────────────────── │
│ [ Beri Bonus ]        [ Cairkan Hak ]          │  2 aksi harian → modal
│ ▸ ⚙ Pengaturan Akun                            │  <details> aksi langka
│     • Ubah Tarif   (form F2)                   │
│     • Reset PIN    (form F3)                   │
│     • Nonaktifkan  (form F4)                   │
│ diperbarui 12 Agu 2025 · 14:03                 │  updated_label
└────────────────────────────────────────────────┘
```

Urutan baca (mobile, 1 kolom): badge & nama → saldo hak → potongan → aksi harian → pengaturan →
metadata. Pada `≥640px` grid 2 kolom; kartu memakai `flex flex-col` + `gap-3` tanpa tinggi paksa
(kartu bertetangga boleh berbeda tinggi; tidak memakai `h-full`).

### 5.4 Susunan halaman setelah refactor

1. **Kartu ringkasan** (53–85, tidak diubah) — 4 fakta `af-facts`.
2. **`<details>` "Tambah Mitra Baru"** (`af-card mt-5` di dalam `<details>`, `x-data="{ submitting: false }"`) — berisi F1 apa adanya.
3. **Grid kartu petugas** (`af-card mt-5` sebagai seksi, di dalamnya `grid gap-3 sm:grid-cols-2`) — judul seksi + hitungan + *empty state* (di luar grid).
4. **Buku Hak Terbaru** (492–518) — dipertahankan, dipadatkan (tetap `<ul divide-y>`).

## 6. Checklist pemetaan 6 form (gate implementasi)

| ☐ | Form | Ditempatkan di | Field identitas utuh | Token `_once` + CSRF | Tombol | Repopulasi `422` |
| --- | --- | --- | --- | --- | --- | --- |
| ☐ P1 | `store` → `operator/petugas` | `<details>` "Tambah Mitra Baru" | — | ✅ | "Simpan Mitra Lapangan" (`af-btn--primary af-btn--block`) | `$input` (perilaku lama dipertahankan, termasuk `officer_pin`) |
| ☐ P2 | `updateCut` → `operator/petugas/potongan` | `<details>` "⚙ Pengaturan Akun" | `cut_officer_id` = `$row['id']` | ✅ | "Ubah Tarif" (`af-btn--ghost`) | `cut_pct` (fallback `cut_pct_input`) |
| ☐ P3 | `resetPin` → `operator/petugas/pin` | `<details>` "⚙ Pengaturan Akun" | `pin_user_id` = `$row['user_id']` | ✅ | "Reset PIN" (`af-btn--ghost`) | **tidak pernah** (PIN) |
| ☐ P4 | `setStatus` → `operator/petugas/status` | `<details>` "⚙ Pengaturan Akun" | `status_user_id`, `status_active` | ✅ | "Nonaktifkan"/"Aktifkan" (`af-btn--ghost`) | `status_active` dari `$input` |
| ☐ P5 | `bonus` → `operator/petugas/bonus` | **Modal** "Beri Bonus" | `bonus_officer_id` = `$row['id']` | ✅ | "Beri Bonus" (`af-btn--primary`) | `bonus_amount`, `bonus_notes` |
| ☐ P6 | `disburse` → `operator/petugas/cair` | **Modal** "Cairkan Hak" | `disburse_officer_id` = `$row['id']` | ✅ | "Cairkan Hak" (`af-btn--primary`) | `disburse_amount`, `disburse_pos`, `disburse_notes` |

Tambahan yang tidak boleh hilang pada P6: `<select name="disburse_pos">` merender **semua**
`$posOptions` dengan `option['label']` + `(saldo option['saldo_label'])`, dan petunjuk "Saldo hak
tersedia: `balance_label`". Pada P5: petunjuk bahwa bonus mengurangi laba bersih tutup buku
berikutnya (sudah ada di `data-confirm` + catatan).

## 7. Invarian integritas form (dijaga, bukan sekadar disalin)

1. `requireRole` / CSRF / `_once` / PRG — tidak tersentuh (frozen surface).
2. **Satu halaman = satu token `_once` aktif**: `$formToken` yang sama dipakai keenam form; setiap
   mutasi diikuti redirect oleh controller. Jangan menambah/menggandakan penerbitan token di view.
3. **Kontrol tidak pernah `:disabled`**; hanya `:readonly` + kelas redup. `:disabled` hanya `<button>`.
4. **Nilai awal dari peladen** (`value="…"`), placeholder selalu berawalan `mis. `, tanpa `x-model`
   pada isian yang dirender peladen (kecuali sudah memakai `x-model.fill` — tidak dipakai di sini).
5. **Setiap output dinamis lewat `Security::e()`**, termasuk `data-confirm` dan `aria-labelledby`.
6. **PIN tidak pernah dirender kembali** dari `$input` untuk P3.
7. `novalidate` dipertahankan: isian `required` di dalam `<details>` tertutup tidak dapat difokuskan
   peramban, sehingga bubble validasi native memang tidak boleh diandalkan.
8. `data-confirm` **dipertahankan apa adanya** (termasuk untuk P5/P6 di dalam modal). Konsekuensi:
   konfirmasi native muncul sekali lagi di atas modal — diterima sebagai jaring pengaman uang; opsi
   menghapusnya untuk P5 dicatat sebagai tindak lanjut, bukan bagian refactor ini.

## 8. Langkah implementasi (urut, satu berkas)

| # | Langkah | Keluaran |
| --- | --- | --- |
| S1 | Perbarui docblock berkas: struktur baru, alasan penempatan modal di luar `af-card`, aturan state per kartu | Komentar menjelaskan "mengapa" (gaya repo) |
| S2 | Turunkan variabel view: `$failedAction`, `$failedOfficerId`, `$settingsOpenFor` (per baris), helper `$val()` | Tanpa sentuh controller |
| S3 | Kartu ringkasan tetap; bungkus F1 dalam `<details class="af-card mt-5">` + `summary` "Tambah Mitra Baru" + `open` bila F1 gagal | ± 120 baris terlipat |
| S4 | Ganti `<ul>/<li>` → `grid gap-3 sm:grid-cols-2` + pembungkus `x-data` polos per petugas | Grid kartu |
| S5 | Anatomi kartu: header + badge, hero saldo (`balance_label`), baris `cut_pct_label`, `updated_label` | Hierarki visual |
| S6 | Dua tombol aksi harian (`type="button"`, `@click`) | Pemicu modal |
| S7 | Dua modal per kartu (overlay `z-30` + panel `af-card max-w-md`) berisi F5 & F6 | Aksi harian fokus |
| S8 | `<details>` "⚙ Pengaturan Akun" per kartu berisi F2, F3, F4 (+ `open` bila salah satunya gagal) | Aksi langka tersembunyi |
| S9 | *Empty state* di luar grid; rapatkan Buku Hak Terbaru | Kerapian |
| S10 | Audit allow-list kelas + `php -l` | Gate § 9 |

Perkiraan: satu berkas berubah, ± 500–540 baris, nol berkas lain.

## 9. Verifikasi (gate sebelum "selesai")

1. `php -l app/Views/operator/petugas.php` → tanpa error.
2. **Gate allow-list kelas**: petakan setiap kelas utilitas baru pada view ke `app.min.css`
   (`grep -o '\.kelas{'`) dan pastikan **nol kelas hilang**; daftar terlarang § 5.1 tidak muncul.
   Jika ada yang hilang → ganti dengan padanan terkompilasi, **jangan** rebuild CSS.
3. `git status` → hanya `app/Views/operator/petugas.php` (plus `plan/Plan_Fase_10_UI_Refactor.md`).
   `app.js`, `sw.js`, `app.min.css`, `CACHE_NAME` tidak berubah.
4. **Uji manual** (`php -S 127.0.0.1:8000 -t public tests/server_router.php`, login operator):
   1. Grid tampil 1 kolom di <640px, 2 kolom di ≥640px; badge aktif/nonaktif benar.
   2. Klik "Beri Bonus" → modal muncul, latar gelap, `Esc`/klik latar/"Tutup" menutup; fokus tidak
      melompat keluar viewport; halaman di belakang **tidak** ikut ter-scroll aneh.
   3. "Cairkan Hak" → nominal > saldo hak / > saldo pos → `422` **dan modal yang sama terbuka lagi**
      dengan isian terisi + pesan error di dalam modal.
   4. Bonus nominal tidak sah (mis. `abc`, `-5.000`) → `422`, modal bonus terbuka lagi.
   5. "Ubah Tarif" tak sah (mis. `150`) → `422`, `<details>` "Pengaturan Akun" otomatis terbuka.
   6. Reset PIN tidak cocok → `422`, `<details>` terbuka, isian PIN **kosong**.
   7. Nonaktifkan lalu Aktifkan → label tombol & badge berubah setelah PRG.
   8. Tambah petugas baru: form gagal → `<details>` "Tambah Mitra Baru" terbuka dengan isian terisi.
   9. Kunci: setelah satu submit sukses, submit ulang cepat (tombol terkunci) tidak memicu
      `419`/duplikat.
5. `bash tests/run_qc.sh` → **16 suite, 0 gagal** (baseline 989 assertion; `qc_fase_10` G.1 tetap
   hijau karena `name="officer_cut_pct"`, `name="disburse_pos"`, dan `name="_once"` tetap dirender).
6. Tidak ada `storage/logs/php-*.log` baru setelah uji manual + QC.
7. Probe Node tidak dijalankan/diperlukan (nol perubahan `app.js`).

## 10. Usulan assertion penjaga (opsional, aditif — `tests/qc_fase_10.php`)

Ditambahkan sebagai grup **I** (pemindaian statis `petugas.php`), memakai pola `qc2_scan()`/`qc3_scan()`:

| ID | Assertion |
| --- | --- |
| I.1 | `petugas.php` memuat **tepat** enam target `App::url('operator/petugas…')` (store, potongan, pin, status, bonus, cair) — tidak kurang, tidak dobel |
| I.2 | Setiap `<form … method="post">` di `petugas.php` diikuti `Security::csrfField()` **dan** `name="<?= Security::FORM_FIELD ?>"` (hitung form = hitung field `_once`) |
| I.3 | Tidak ada `:disabled` pada `<input>/<select>/<textarea>` (regex blok kontrol, pola `qc_fase_2` H.13) |
| I.4 | `disburse_pos` dirender dengan `foreach ($posOptions …)` dan `balance_label` muncul di kartu |
| I.5 | Setiap modal memuat `x-cloak` + `role="dialog"` |

Bila diterima: perbarui baris baseline di `AGENTS.md` ("16 suite, 989 assertion") → `989 + N`, dan
catat di `plan/Summary_Fase_10_Mitra_Lapangan_AurumFlow.md`. Disarankan sebagai langkah **kedua**
(pasca-approve visual), supaya refactor markup tidak terkunci sebelum bentuknya disepakati.

## 11. Risiko & mitigasi

| Risiko | Dampak | Mitigasi |
| --- | --- | --- |
| Modal `fixed` terkurung `backdrop-filter`/`overflow-hidden` kartu | Overlay hanya menutup kartu | Modal **selalu** saudara `.af-card` di dalam pembungkus polos (§ 4.2) |
| `Alpine Expression Error` (pernyataan, bukan ekspresi) | Tombol diam-diam mati | Hanya ekspresi (`bonusOpen = true`, `bonusOpen = false`); tanpa `try`/blok |
| Kelas Tailwind belum terkompilasi | Gaya hilang tanpa error | Gate audit § 9.2; daftar terlarang § 5.1 |
| Aset ter-cache menyajikan JS lama | — | Nol perubahan `app.js`/`app.min.css`; `CACHE_NAME` tetap **v5** |
| Duplikasi DOM 2 modal × N petugas | DOM besar bila petugas > ~40 | Diterima (kasus nyata ≤ 10); paginasi/pencarian dicatat sebagai tindak lanjut |
| Konfirmasi ganda (`data-confirm` di dalam modal) | Sedikit friksi | Disengaja (jaring uang); opsi penghapusan dicatat, bukan dikerjakan |
| Isian `required` di `<details>` tertutup | Bubble native tidak tercapai | `novalidate` dipertahankan (validasi peladen) |
| Token `_once` ganda/hilang | `419` acak | Satu `$formToken` per halaman, tidak ada penerbitan baru di view; setiap mutasi PRG |

## 12. Di luar lingkup / tindak lanjut

- Pencarian/filter/paginasi kartu petugas; pengurutan.
- Riwayat hak per petugas di dalam kartu (butuh query baru → perubahan backend).
- Pratinjau `balance_after` di sisi klien untuk modal pencairan (butuh komponen `app.js` + `CACHE_NAME`).
- Penghapusan `data-confirm` untuk aksi di dalam modal.
- Penyeragaman gaya kartu untuk `kasir.php` (sprawl serupa) — refactor terpisah.

## 13. Definition of done

- [ ] Hanya `app/Views/operator/petugas.php` + dokumen plan ini yang berubah.
- [ ] Checklist P1–P6 (§ 6) tercentang, atribut form identik dengan baseline (§ 2.1).
- [ ] `422` membuka kembali modal/`<details>` yang benar untuk **setiap** dari 5 aksi (bukan hanya 1).
- [ ] `PIN` tidak pernah dirender ulang; nol `:disabled` pada kontrol; nol `number_format()`.
- [ ] Audit allow-list kelas lolos; `app.js`/`app.min.css`/`sw.js` tidak tersentuh.
- [ ] `bash tests/run_qc.sh` exit 0; tidak ada `storage/logs/php-*.log`; `git status` bersih.
