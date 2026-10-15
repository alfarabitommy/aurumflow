# Plan Fix Dashboard UI — Pintasan "Kelola Kasir"

Perbaikan UX pascarilis Fase 7: kartu sambutan `/dashboard` belum punya pintasan
ke layar provisioning kasir yang baru (`/operator/kasir`).

- **Ruang lingkup:** satu berkas view. Nol perubahan controller, model, rute,
  skema, aset statis.
- **Keputusan yang sudah diambil (dec-93ae9a6bfd32cb6a):**
  1. Tata letak: `grid gap-3 sm:grid-cols-2` → 1 kolom di ponsel, 2×2 sejak
     ≥640px. Hanya kelas yang **sudah ada** di `public/assets/css/app.min.css`.
  2. Kelas tombol: `af-btn--ghost` (kelas sekunder yang sudah dipakai dua tombol
     lain). `af-btn--outline` **tidak ada di repo** dan tidak akan dibuat.

## 1. Berkas sasaran

| Berkas | Aksi |
| --- | --- |
| `app/Views/dashboard/index.php` | ubah hanya blok `<?php if ($isOperator): ?>` pada baris 54–63 |

Tidak disentuh: `app/Controllers/DashboardController.php`, `app/routes.php`,
`resources/css/tailwind.input.css`, `public/assets/css/app.min.css`,
`public/assets/js/app.js`, `public/sw.js`, `AGENTS.md`, `docs/`, `database/`.

## 2. Penyesuaian UI

Tambahkan tombol keempat, berlabel **"Kelola Kasir"**, menuju
`App::url('operator/kasir')`, diurutan **setelah** "Kelola Modal & Likuiditas"
(urutan ini sama dengan `Views/layouts/operator.php`: Kasir → Rekonsiliasi →
Kelola Modal → Kelola Kasir).

Hasil akhir blok (satu-satunya perubahan kode):

```php
<?php if ($isOperator): ?>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <a class="af-btn af-btn--primary"
           href="<?= Security::e(App::url('operator/teller')) ?>">Buka Meja Kasir Hulu</a>
        <a class="af-btn af-btn--ghost"
           href="<?= Security::e(App::url('operator/settlement')) ?>">Buka Rekonsiliasi Toko</a>
        <a class="af-btn af-btn--ghost"
           href="<?= Security::e(App::url('operator/capital')) ?>">Kelola Modal &amp; Likuiditas</a>
        <a class="af-btn af-btn--ghost"
           href="<?= Security::e(App::url('operator/kasir')) ?>">Kelola Kasir</a>
    </div>
<?php endif; ?>
```

Aturan yang tetap dipatuhi: semua output dinamis lewat `Security::e(...)`
(termasuk `App::url()`), entitas `&amp;` dipertahankan, tidak ada `number_format()`
maupun SQL di view.

## 3. Styling & tata letak

- Kontainer: `sm:grid-cols-3` → `sm:grid-cols-2`, sehingga 4 tombol membentuk
  2×2 yang simetris pada ≥640px dan satu kolom penuh pada ponsel. `gap-3` dan
  `mt-4` tidak berubah.
- Kelas tombol: `af-btn af-btn--ghost` — identik dengan dua tombol sekunder
  yang ada (outline gelap; hover border emas + teks emas).
- **Nol kompilasi ulang Tailwind.** `sm:grid-cols-2`, `gap-3`, dan
  `af-btn--ghost` sudah ada di `public/assets/css/app.min.css`; pola
  `grid gap-3 sm:grid-cols-2` sudah dipakai `Views/investor/dashboard.php:104`
  dan `Views/operator/kasir.php:95`. Karena `app.js`/`app.min.css` tidak
  berubah, `CACHE_NAME` di `public/sw.js` **tetap** `aurumflow-shell-v2` dan
  `AGENTS.md` tidak perlu disunting (assertion `qc_fase_6` G.17/G.18 dan
  `qc_fase_7` F.7 tetap lulus apa adanya).
- Catatan lebar: layout `app.php` memakai `max-w-3xl` (768px), jadi baris 4
  kolom hanya memberi ~184px per tombol dan label panjang ("Buka Rekonsiliasi
  Toko", "Kelola Modal & Likuiditas") akan membungkus — 2×2 adalah bentuk
  simetris yang benar untuk lebar ini.

## 4. Validasi — tanpa perubahan backend

Tidak ada perubahan controller/model/rute/skema, karena:

1. `/dashboard` sudah dibatasi operator: `DashboardController::index()` memanggil
   `requireRole(Constants::ROLE_OPERATOR)` sebagai pernyataan pertama, dan view
   menyembunyikan seluruh blok tombol di balik `$isOperator` (baris 42 & 54).
   Kasir yang mencoba `/dashboard` dijawab `302` ke `/operator/teller` tanpa
   tulisan (`qc_fase_7` D.*).
2. `/operator/kasir` sudah punya penjaganya sendiri: `CashierController::index()`
   memanggil `requireRole(Constants::ROLE_OPERATOR)`, jadi tombol ini hanya
   memindahkan navigasi — bukan jalur otorisasi baru.
3. Rute `GET /operator/kasir` sudah terdaftar di `app/routes.php`; `App::url()`
   merakitnya (aman untuk pemasangan subdirektori).
4. Tombol ini tidak mungkin membocorkan tautan permukaan pemilik ke kasir:
   assertion `qc_fase_7` E.3/E.4 memindai body layar kasir (meja kasir +
   layoutnya), bukan `Views/dashboard/index.php`.

Regresi yang sudah diperiksa di kode tes:

- `qc_fase_4` (baris ~1830) menuntut view dashboard tetap memuat
  `App::url('operator/capital')` → tetap terpenuhi.
- `qc_routing` 5.9/5.9b/5.9c/5.9d hanya menuntut status `200`, nomor telepon,
  label peran "Pengelola", dan angka formatter/settings → tidak ada teks yang
  dihapus.
- Tidak ada assertion (grep `tests/*.php`) yang menghitung jumlah `af-btn` di
  dashboard atau mengikat `sm:grid-cols-3`; kelas `af-btn--outline` tidak
  disebut di mana pun.

## 5. Langkah eksekusi & verifikasi

1. Terapkan perubahan pada `app/Views/dashboard/index.php` (blok baris 54–63).
2. `php -l app/Views/dashboard/index.php`.
3. `bash tests/run_qc.sh` → **exit 0**, baseline 13 suite / 782 assertion
   (tidak berubah; tidak ada suite baru di rencana ini) dan tidak muncul
   `storage/logs/php-*.log` baru.
4. Verifikasi manual dengan router dev:
   `php -S 127.0.0.1:8000 -t public tests/server_router.php`, login operator →
   `GET /dashboard`: empat tombol, 2×2 pada lebar ≥640px, satu kolom pada
   ponsel; tombol "Kelola Kasir" mendarat di `/operator/kasir`.
5. `git status` bersih (tanpa `tests/.tmp/`, `storage/installed.lock`, `*.log`).

## 6. Di luar ruang lingkup (tidak dikerjakan)

- Menambah pintasan "Kelola Kasir" pada navbar `Views/layouts/app.php`
  (navbar itu memang ringkas — hanya Dashboard + Kasir Hulu; navbar operator
  sudah lengkap). Perubahan ini opsional dan butuh keputusan terpisah.
- Menyentuh `app.min.css`/`CACHE_NAME`/assertion QC: tidak diperlukan oleh
  keputusan tata letak di atas.

## 7. Status

- [x] Berkas rencana ditulis (dokumen ini)
- [x] Perubahan view diterapkan (`app/Views/dashboard/index.php`, blok `$isOperator`)
- [x] `php -l` bersih
- [x] `bash tests/run_qc.sh` → exit 0, **13/13 suite LULUS, 782 PASS / 0 FAIL**
      (baseline tidak bergeser), tanpa `storage/logs/php-*.log` baru
- [x] Verifikasi manual lewat router dev (`php -S 127.0.0.1:8123 -t public tests/server_router.php`):
      login operator → `GET /dashboard` memuat `grid gap-3 sm:grid-cols-2` dan **empat** tombol
      (`href="/operator/kasir"` → `GET /operator/kasir` = `200`)

Catatan eksekusi: `app.js`/`app.min.css` tidak disentuh, sehingga `CACHE_NAME`
tetap `aurumflow-shell-v2` dan assertion `qc_fase_6` G.17/G.18 serta
`qc_fase_7` F.7 lulus tanpa perubahan.
