# Summary Unify UI AurumFlow — Kerangka 2-Tier Pengelola

Laporan eksekusi untuk `plan/Plan_Unify_UI_AurumFlow.md` (keputusan PO:
`dec-9ab922a185e7cc38`, `dec-e6acc8117bba9602`).

**Hasil:** `/dashboard` dan seluruh layar `/operator/*` kini memakai satu kerangka
2-tier (`app/Views/layouts/operator.php`). Nol perubahan rute, middleware, skema
basis data, aset JS, dan CSS. QC: **13 suite, 785 assertion, 0 gagal** (baseline
sebelumnya 13 suite, 782 assertion).

---

## 1. Berkas yang diubah / ditambahkan

| Berkas | Aksi | Isi |
| :--- | :--- | :--- |
| `app/Controllers/DashboardController.php` | ubah (+12) | `private const LAYOUT = 'operator';` + `], self::LAYOUT);` pada `render()` |
| `app/Views/layouts/operator.php` | ubah (+53/−26) | baris 1 (subjudul adaptif, tombol Dashboard dihapus, identitas selalu terlihat), baris 2 (5 tab / 1 tab), baris 3 (widget dijaga kondisi) |
| `app/Views/dashboard/index.php` | ubah (+13/−10) | grid aksi cepat 1:1 dengan tab, teks roadmap lama diganti ringkasan operasional, dokumentasi berkas diperbarui |
| `tests/qc_fase_4.php` | ubah (+46) | assertion baru `H.11b`, `H.11c`, `H.12b` (grup H) |
| `AGENTS.md` | ubah | baseline **785 assertion** + rujukan dokumen unifikasi |
| `docs/AurumFlow UI UX Documents.md` | ubah | § 7.2 (satu kerangka 2-tier + tabel tab per peran) dan catatan Layar B (Fase 7 + Fase 8) |
| `docs/AurumFlow Roadmap and QC Documents.md` | ubah | baris navigasi sadar peran: **tab** Dashboard/Rekonsiliasi/Kelola Modal/Kelola Kasir disembunyikan bagi kasir |
| `docs/AurumFlow System Architecture Document.md` | ubah | pohon `Views/layouts` + kontrak baru "satu kerangka 2-tier untuk seluruh halaman pengelola" |
| `plan/Plan_Fase_1_AurumFlow.md` | ubah | ADDENDUM **A.4b** — penyimpangan tampilan Fase 8 beserta alasannya |
| `plan/Plan_Unify_UI_AurumFlow.md` | baru | rencana eksekusi (disetujui PO) |
| `plan/Summary_Unify_UI_AurumFlow.md` | baru | dokumen ini |

Tidak disentuh: `app/routes.php`, `public/index.php`, `app/Models/*`,
`app/Services/*`, `app/Views/layouts/app.php` (tetap ada — dijaga `qc_fase_5`
I.10), `public/assets/css/app.min.css`, `public/assets/js/app.js`,
`public/sw.js` (`CACHE_NAME` tetap `aurumflow-shell-v2`), `database/*`.

---

## 2. Perubahan arsitektur layout

### 2.1 Pemilihan layout dipindahkan ke controller (C1)

`BaseController::render($view, $data, $layout = 'app')` memilih berkas layout di
PHP, sehingga view tidak dapat memilihnya sendiri. `DashboardController` kini
mengikuti pola controller operator lain:

```php
private const LAYOUT = 'operator';
…
$this->render('dashboard/index', [ … ], self::LAYOUT);
```

`requireRole(Constants::ROLE_OPERATOR)` tetap **pernyataan pertama** method
(`/dashboard` tetap operator-only). `layouts/app.php` **tidak dihapus**: ia masih
default `render()` dan dijaga assertion `qc_fase_5` I.10 (lima layout wajib ada).

### 2.2 Baris 1 — identitas

| Sebelum | Sesudah |
| :--- | :--- |
| Subjudul hardcoded `Meja Hulu` | `$headerSubtitle` = `$title` halaman untuk operator, **tetap** `Meja Hulu` untuk kasir |
| Tombol pintasan `[Dashboard]` di header | dihapus — Dashboard kini tab pertama baris 2 |
| Identitas `hidden … sm:block` | `block min-w-0` + `truncate` per baris (nama & label peran) |
| `flex items-center justify-between gap-2` | `flex flex-wrap items-center justify-between gap-2` |

**Bug yang ditemukan & diperbaiki:** `.sm\:block` **tidak pernah ada** di
`public/assets/css/app.min.css` (kelas `sm:` yang terkompilasi hanya
`sm:grid-cols-2/3`), sementara `.hidden{display:none}` ada — sehingga nama +
label peran tidak pernah tampil di lebar mana pun. Penggantinya hanya memakai
kelas yang sudah ada di bundel (`block`, `min-w-0`, `truncate`), jadi tetap nol
kompilasi ulang.

### 2.3 Baris 2 — navigasi tab tersegmen (5 / 1)

```
Operator : [Dashboard] [Kasir] [Rekonsiliasi] [Kelola Modal] [Kelola Kasir]
Kasir    : [Kasir]
```

* Daftar dibangun bertahap: entri `Dashboard` dan tiga entri pengelola hidup di
  dalam `if (!$isCashier)`, entri `Kasir` selalu ada. Format literal
  `'label' => 'Kasir'`, `'label' => 'Rekonsiliasi'`, `'label' => 'Kelola Modal'`,
  `'label' => 'Kelola Kasir'` + `App::url('operator/capital')` dipertahankan
  agar `qc_fase_4` H.11 tetap lulus.
* **Tab aktif dari URI**, bukan dari controller:
  `$activePath = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/')`,
  lalu cocok persis **atau** berawalan `$itemPath . '/'` sehingga
  `/operator/capital/topup` tetap menandai **Kelola Modal**.
* **Perbaikan saat eksekusi (deviasi kecil dari rencana § 4.3):** rencana
  mengusulkan `<?= Security::e($navAria) ?>`, tetapi `Security::e()` memakai
  `ENT_QUOTES` sehingga atributnya tercetak `aria-current=&quot;page&quot;`
  (nilai aksesibilitas tidak sah). Atribut kini ditulis sebagai markup statis
  lewat `<?php if ($item['active']): ?> aria-current="page"<?php endif; ?>` —
  tidak ada `<?=` tanpa `Security::e()`, dan pembaca layar menerima
  `aria-current="page"` yang benar.

### 2.4 Baris 3 — widget kas & emas

Dibungkus `<?php if ($register !== [] || $holding !== []): ?>`.
`TellerController`, `SettlementController`, dan `CapitalController` selalu
mengirim keduanya; `DashboardController` tidak — sehingga `/dashboard` tampil
sebagai header 2-baris yang bersih, tanpa `Saldo Kas -` / `Emas holding -`.

### 2.5 Grid aksi cepat `/dashboard` (C5)

Grid `mt-4 grid gap-3 sm:grid-cols-2` dipertahankan (dibutuhkan `qc_fase_4`
H.12) dan kini **1:1** dengan empat tab operasional, urutan serta label sama
persis: `Kasir` (`af-btn--primary`), `Rekonsiliasi`, `Kelola Modal`,
`Kelola Kasir`. Teks roadmap lama ("Fase 1 … dan Fase 2 … sudah aktif") diganti
ringkasan operasional profesional. Baris yang diuji QC tidak berubah: nomor
telepon, label peran `Pengelola`, sampel formatter (`Rp 28.937.500`,
`14,850 gr`), tabel `system_settings` (`waterfall`, `50.00`), dan
`App::url('operator/capital')`.

### 2.6 Styling / mobile

* Hanya kelas yang sudah ada di `app.min.css`: `overflow-x-auto` pada kontainer
  tab, `shrink-0` pada setiap tab (`flex-shrink:0` ⇒ lebar max-content ⇒ label
  tidak pernah terlipat), `flex-wrap` + `min-w-0` + `truncate` pada baris 1.
* **Nol** `whitespace-nowrap`, `flex-nowrap`, `flex-1`, `-mx-4`, `sm:block`
  (tidak ada di bundel). `public/assets/css/app.min.css` dan
  `public/assets/js/app.js` tidak berubah ⇒ `CACHE_NAME` tetap
  `aurumflow-shell-v2` (`qc_fase_6` G.17/G.18, `qc_fase_7` F.7 lulus).
* Perkiraan lebar 5 tab ≈ 486 px vs konten `max-w-2xl` 640 px ⇒ tanpa scrollbar
  pada desktop/tablet; pada ponsel baris menggeser horizontal.

---

## 3. Hasil QC

Baseline sebelum: **13 suite / 782 assertion / 0 gagal**.
Assertion baru: `qc_fase_4` `H.11b` (wiring `LAYOUT`), `H.11c` (kontrak tab:
`Dashboard` + `Kelola Kasir` + `overflow-x-auto` + `shrink-0`), `H.12b`
(HTTP `GET /dashboard`: 200, kelima `href` tab, `aria-current="page"`, **tanpa**
`max-w-3xl` milik `layouts/app.php`).

Sesudah (`bash tests/run_qc.sh`, exit **0**):

| Suite | Hasil |
| :--- | :--- |
| `qc_pdo` | 20/20 PASS |
| `qc_formatter` | 43/43 PASS |
| `qc_security` | 32/32 PASS |
| `qc_seed` | 47/47 PASS |
| `qc_roadmap_protocol_1` | 6/6 PASS |
| `qc_fase_2` | 66/66 PASS |
| `qc_fase_3` | 98/98 PASS |
| `qc_fase_4` | **115/115 PASS** (sebelumnya 112) |
| `qc_fase_5` | 130/130 PASS |
| `qc_fase_6` | 105/105 PASS |
| `qc_fase_7` | 77/77 PASS |
| `qc_sql_injection` | 15/15 PASS |
| `qc_routing` | 31/31 PASS |
| **Total** | **785 assertion, 0 gagal — 13/13 suite LULUS** |

Lint: `php -l` bersih pada `app/Controllers/DashboardController.php`,
`app/Views/layouts/operator.php`, `app/Views/dashboard/index.php`,
`tests/qc_fase_4.php`. Tidak ada berkas `storage/logs/php-*.log`.
`git status` bersih dari artefak (`tests/.tmp/`, `storage/installed.lock`,
`*.log`).

Assertion yang dipertahankan hijau: `qc_fase_4` H.11/H.12, `qc_fase_7`
D.1/E.3/E.4/E.6/E.8, `qc_fase_5` I.10, `qc_routing` 5.9–5.9d, `qc_fase_2`
H.2/H.3/H.9, serta seluruh pemindai escaping view.

---

## 4. Checklist verifikasi manual

Dijalankan dengan `php -S 127.0.0.1:8123 -t public tests/server_router.php`
(operator `081200000001` / `Aurum2026!`, kasir QC `089700000101` / PIN `246810`):

| # | Pemeriksaan | Hasil |
| :-- | :--- | :--- |
| 1 | Operator `GET /dashboard` → 200, kelima `href` tab ada (`/dashboard`, `/operator/teller`, `/operator/settlement`, `/operator/capital`, `/operator/kasir`) | ✅ |
| 2 | `/dashboard`: tepat satu `aria-current="page"` (tab **Dashboard**) | ✅ |
| 3 | `/dashboard` **tanpa** `max-w-3xl` (shell 1-tier `layouts/app.php` tidak lagi merender) | ✅ |
| 4 | `/dashboard` **tanpa** widget `Emas holding` / `Saldo Kas -` (baris 3 tidak dirender) | ✅ |
| 5 | Subjudul baris 1 di `/dashboard` = `Dashboard`; di `/operator/teller` = `Meja Kasir Hulu` | ✅ |
| 6 | Tidak ada lagi label pintasan `Kasir Hulu` maupun tombol `Dashboard` di header | ✅ |
| 7 | `/operator/teller` (operator): widget `Emas holding` dirender, tab **Kasir** aktif (`af-btn shrink-0 bg-gold-primary … aria-current="page"`) | ✅ |
| 8 | Kasir `GET /operator/teller` → 200, **hanya** satu anchor tab `href="/operator/teller"` | ✅ |
| 9 | Body kasir **tanpa** `href="/dashboard"`, `Rekonsiliasi`, `Kelola Modal`, `Kelola Kasir` | ✅ |
| 10 | Subjudul kasir tetap `Meja Hulu` | ✅ |
| 11 | Kasir `GET /dashboard` → `302` ke `/operator/teller` (tanpa tulisan) | ✅ |
| 12 | Markup mobile: kontainer tab `flex … overflow-x-auto`, tiap tab `shrink-0`, tidak ada kelas `whitespace-nowrap`/`flex-nowrap`/`-mx-4`/`sm:block` | ✅ (tingkat markup) |
| 13 | Uji piksel 320 px & 360 px di peramban sungguhan: tab menggeser horizontal, label tidak terlipat, baris 1 tidak meluber | ⏳ belum dijalankan di perangkat — diferifikasi lewat kelas CSS terkompilasi (§2.6); langkah ini tetap disarankan sebelum rilis |

---

## 5. Item terbuka / catatan

1. **`layouts/app.php` kini tak dipakai rute mana pun.** Dipertahankan karena
   `qc_fase_5` I.10 mensyaratkan lima layout ada beserta `manifest.json` +
   `data-app-base`. Menghapusnya = mengubah kontrak QC → butuh keputusan
   terpisah.
2. **`aria-current` sebelumnya tidak sah** (`&quot;`) pada seluruh halaman
   operator — diperbaiki di sini (§2.3). Tidak ada assertion lama yang
   bergantung pada bentuk yang salah itu.
3. **Dashboard kehilangan ringkasan saldo kas** (baris 3). Menampilkannya
   kembali membutuhkan data `$register`/`$holding` dari controller — di luar
   lingkup "nol perubahan controller" yang disetujui.
4. **Uji piksel 320/360 px** (butir 13) masih perlu dijalankan di peramban/
   perangkat nyata.
5. Label grid aksi cepat kini identik dengan label tab (keputusan PO "align
   perfectly"). Bila PO ingin frasa deskriptif lama, itu perubahan satu baris
   per tombol dan tidak menyentuh QC.
