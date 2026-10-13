# Audit — Kotak Kosong di Pintu PIN Mitra (`/investor/login`)

**Jenis:** investigasi baca-saja (*read-only*), tanpa perubahan kode.
**Cakupan:** `app/Views/investor/login.php`, `app/Views/layouts/auth.php`, `app/Views/investor/onboarding.php`,
`public/assets/js/app.js`, `public/assets/js/alpine.min.js`, `resources/css/tailwind.input.css`,
`public/assets/css/app.min.css`, `public/sw.js`, suite QC terkait.
**Snapshot:** pohon kerja `be6b45b` + perubahan belum di-commit (seluruh berkas Fase 2–5 masih *untracked*/*modified*).
**Kesimpulan singkat:** bukan kontainer yang lupa disembunyikan dan bukan galat ekspresi Alpine —
**`layouts/auth.php` sama sekali tidak memuat `alpine.min.js`**, sehingga komponen `investorLogin`
tidak pernah *boot* dan tombol hapus papan angka (`x-text="'⌫'"`) dirender sebagai tombol kosong.

---

## 1. Ringkasan eksekutif

Di antara isian **PIN 6 Angka** dan tombol **Masuk Portofolio**, pada `/investor/login` terlihat satu
persegi panjang bersudut tumpul berbingkai tanpa teks/ikon. Elemen itu bukan wadah tersembunyi yang
lupa ditutup, melainkan **satu-satunya tombol statis pada papan angka**:

```
app/Views/investor/login.php:78-79
<button type="button" class="af-btn af-btn--ghost py-3 text-base"
        @click="erase()" x-text="'⌫'"></button>
```

Labelnya **hanya** berasal dari `x-text`. Karena Alpine.js tidak pernah dimuat di layout `auth`,
direktif itu tidak pernah dievaluasi ⇒ `textContent` tetap kosong ⇒ yang tersisa hanyalah
kotak `.af-btn` (berbingkai, `rounded-xl`, `min-height: 52px`).

Dampaknya bukan sekadar kosmetik: **seluruh papan angka PIN mati** (tombol 1–9 & 0 tidak pernah
ada, karena isinya hidup di dalam `<template x-for>` yang `display:none`), dan `:disabled="submitting"`
pada tombol kirim tidak pernah aktif. Halaman `/join` (layout yang sama) juga kehilangan
pendengar `@scroll` untuk pakta integritas.

Perbaikan akar masalah: **satu baris** — tambahkan `<script src=".../alpine.min.js" defer>` pada
`app/Views/layouts/auth.php`, persis mengikuti pola yang sudah dipakai tiga layout lain.
Tidak perlu menaikkan `CACHE_NAME` (`sw.js`), karena halaman selalu diambil dari jaringan.

---

## 2. Bukti (jejak investigasi)

### 2.1 Rantai render `/investor/login` → layout `auth`

| Langkah | Berkas:baris | Isi |
| --- | --- | --- |
| View pintu PIN | `app/Controllers/InvestorAuthController.php:32` | `private const VIEW = 'investor/login';` |
| Layout dipilih | `app/Controllers/InvestorAuthController.php:34` | `private const LAYOUT = 'auth';` |
| Dipakai saat render | `app/Controllers/InvestorAuthController.php:150-155` | `$this->render(self::VIEW, [...], self::LAYOUT);` |
| Onboarding ikut | `app/Controllers/InvestorOnboardingController.php:35` | `private const LAYOUT = 'auth';` |

Jadi tiga permukaan memakai layout `auth`: `/login` (kata sandi operator), `/investor/login`
(PIN mitra), dan `/join` (klaim undangan).

### 2.2 Perbandingan tag `<script>` antar-layout — inti temuan

| Layout | `<script>` yang dimuat |
| --- | --- |
| `app/Views/layouts/app.php` | `:81` `assets/js/app.js` **+ `:82` `assets/js/alpine.min.js`** |
| `app/Views/layouts/operator.php` | `:130` `assets/js/app.js` **+ `:131` `assets/js/alpine.min.js`** |
| `app/Views/layouts/investor.php` | `:73` `assets/js/app.js` **+ `:74` `assets/js/alpine.min.js`** |
| **`app/Views/layouts/auth.php`** | **`:60` `assets/js/app.js` — DAN ITU SAJA** |

```html
<!-- app/Views/layouts/auth.php:58-62 -->
</main>

<script src="<?= Security::e(App::url('assets/js/app.js')) ?>" defer></script>
</body>
</html>
```

`layouts/auth.php` adalah **satu-satunya** dari empat layout aplikasi yang tidak memuat Alpine.
Tidak ada jalur lain yang menyuntikkan Alpine: `public/index.php` tidak menulis tag `<script>`,
dan `app.js` tidak membuat/menyuntik skrip (tidak ada `createElement('script')` maupun
`appendChild`); ia hanya **mendengarkan** peristiwa yang dipancarkan Alpine:

```javascript
// public/assets/js/app.js:1573-1581
document.addEventListener('alpine:init', function () {
    if (window.Alpine && typeof window.Alpine.data === 'function') {
        window.Alpine.data('tellerForm', tellerForm);
        window.Alpine.data('settlementForm', settlementForm);
        window.Alpine.data('capitalSimulator', capitalSimulator);
        window.Alpine.data('investorLogin', investorLogin);          // <- tidak pernah terdaftar di /investor/login
        window.Alpine.data('investorDashboard', investorDashboard);
    }
});
```

Komponennya sendiri ada dan benar (`investorLogin` di `app.js:1445-1475`, `digits` = 10 tombol,
`press()`/`erase()` membaca isian lewat `formField()`), dan `alpine.min.js` (55.891 byte) tersedia
di server — hanya saja tidak pernah diminta oleh halaman ini.

### 2.3 Markup papan angka apa adanya

```
app/Views/investor/login.php:72-84
72      <!-- Papan angka: hanya mempercepat pengisian di ponsel. -->
73      <div class="grid grid-cols-3 gap-2" aria-hidden="true">
74          <template x-for="digit in digits" :key="digit">
75              <button type="button" class="af-btn af-btn--ghost py-3 text-base"
76                      x-text="digit" @click="press(digit)"></button>
77          </template>
78          <button type="button" class="af-btn af-btn--ghost py-3 text-base"
79                  @click="erase()" x-text="'⌫'"></button>
80      </div>
81
82      <button type="submit" class="af-btn af-btn--primary af-btn--block" :disabled="submitting">
83          Masuk Portofolio
84      </button>
```

Direktif Alpine lain di halaman yang sama:
`login.php:31` `x-data="investorLogin({ pinLength: … })"`, `login.php:61` `x-ref="pinField"`,
`login.php:82` `:disabled="submitting"`.

### 2.4 Mengapa hanya SATU kotak yang terlihat

1. `<template>` adalah **elemen non-render** menurut *HTML Standard — Rendering* (daftar elemen
   `display: none` di stylesheet UA). Di `public/assets/css/app.min.css` tidak ada aturan
   `template{…}` sama sekali, jadi tidak ada yang menimpanya — isi `<template x-for>` **tidak pernah**
   ikut dirender sebelum Alpine mengkloningnya.
2. Karena Alpine absen, kloning itu tidak pernah terjadi ⇒ **0 tombol angka**.
3. Yang tersisa hanyalah `<button>` statis baris 78-79: elemen biasa dengan `x-text="'⌫'"` yang
   tidak dievaluasi, dan **tanpa teks literal** di dalam tag ⇒ `textContent === ''`.
4. Grid `grid-cols-3` lalu hanya punya satu anak yang terlihat ⇒ satu kotak bersudut tumpul,
   selebar **satu kolom grid**.

### 2.5 Geometri kotak (cocok dengan laporan)

Gaya kotak berasal dari komponen tema, bukan dari kelas tambahan:

```css
/* resources/css/tailwind.input.css:148-158 */
.af-btn {
    @apply inline-flex min-h-touch cursor-pointer select-none items-center justify-center gap-2
           rounded-xl border border-transparent px-5 text-sm font-semibold tracking-wide transition;
}
.af-btn--ghost {
    @apply border-surface-800 text-pure hover:border-gold-primary/40 hover:text-gold-primary;
}
```

* `min-h-touch` = **52px** (`tailwind.config.js:56` → `touch: '52px'`) — terkompilasi sebagai
  `.af-btn{…min-height:52px…border-radius:.75rem;border-width:1px…}` di `app.min.css`.
* `.af-btn--ghost` mengisi `border-color` dengan `surface-800` ⇒ bingkai terlihat walau isinya kosong.
* Lebar = satu kolom grid: kartu `max-w-sm` (384px) − `p-7` (2×28px) − 2 × `gap-2` (8px) ⇒ ≈ 104px
  per kolom di layar lebar; pada viewport 360–390px (body `px-4`) ⇒ **± 85–95px**.

Laporan “≈ 70–80px × 40px” konsisten dengan satu sel grid; tinggi terhitung sebenarnya **52px**
(`min-h-touch`), bukan 40px — selisih yang wajar untuk pengamatan visual.

### 2.6 Apa yang TIDAK berubah tanpa Alpine (mengapa QC tetap hijau)

* Isian PIN tetap `<input type="password" name="pin" inputmode="numeric" pattern="[0-9]*">` — dapat
  diketik manual; validasi bentuk/`maxlength` dan seluruh keamanan tetap di peladen (`PinThrottle`).
* Penangan `submit` di `app.js:33-56` (konfirmasi `data-confirm` + cegah kirim ganda) tidak
  bergantung pada Alpine, jadi form tetap dapat dikirim.
* Karena itu tidak ada satu pun assertion QC yang gagal — lihat § 6.1.

---

## 3. Analisis akar masalah

| # | Hipotesis | Verdict | Bukti |
| --- | --- | --- | --- |
| 1 | **Alpine.js tidak pernah dimuat oleh layout `auth`** | **AKAR MASALAH (yakin)** | § 2.2; `layouts/auth.php:60` hanya `app.js`; tiga layout lain memuat keduanya |
| 2 | Kontainer lupa `x-show`/`x-cloak` | **Bukan** | Semua wadah di `login.php:72-80` memang harus tampil; tidak ada `x-cloak` di halaman ini; aturan `[x-cloak]{display:none!important}` (`tailwind.input.css:94-96`) tidak relevan |
| 3 | Galat ekspresi Alpine (`Unexpected token 'try'`, dll.) | **Bukan** | Direktif di halaman ini hanya ekspresi sederhana (`press(digit)`, `erase()`, `'⌫'`, `submitting`) — tidak ada pernyataan/blok |
| 4 | `x-text` ada tapi ikon/teks hilang karena CSS (`text-transparent`, `overflow`) | **Bukan** | `.af-btn--ghost` hanya mengatur `border-color`/`color`; tidak ada aturan yang menyembunyikan teks |
| 5 | Aset JS basi di cache peramban/PWA (komponen tak dikenal ⇒ `ReferenceError`) | **Bukan penyebab di sini** | Skrip bootstrap-nya sendiri tidak ada; `app.js` segar pun tidak akan menolong. (Anggapan ini tetap layak diperiksa di lapangan — lihat § 3.2) |
| 6 | Tombol hapus memang dirancang tanpa teks | **Bukan** | Labelnya sengaja `⌫` (`x-text="'⌫'"`); tanpa Alpine ia kehilangan satu-satunya sumber teksnya |

### 3.1 Rantai sebab (ringkas)

```
layouts/auth.php tidak memuat alpine.min.js
        ↓
window.Alpine tidak ada; x-data="investorLogin(…)" tidak di-boot
        ↓
x-for pada <template> tidak pernah mengkloning 10 tombol angka   → papan angka tidak ada
x-text="'⌫'" tidak pernah dievaluasi                             → tombol hapus kosong = kotak misterius
:disabled="submitting" tidak pernah dievaluasi                   → tombol kirim selalu aktif
@scroll (onboarding) tidak pernah terpasang                      → pelacakan pakta integritas mati
```

### 3.2 Cara membedakan kedua mode kegagalan (untuk verifikasi lapangan)

| Gejala di DevTools | Penyebab | Tindakan |
| --- | --- | --- |
| `window.Alpine` → `undefined`; tidak ada `ReferenceError` di console | **Kasus audit ini**: layout tidak memuat Alpine | Perbaikan § 4 Opsi A |
| Ada `ReferenceError: investorLogin is not defined` (atau serupa) **dan** `window.Alpine` ada | Aset `app.js` yang disajikan/di-cache belum memuat komponen | Naikkan `CACHE_NAME` di `public/sw.js:26` + paksa muat ulang |

### 3.3 Mengapa bug ini lolos dari QC

* `tests/qc_fase_5.php:550-557` (**B.2**) hanya memastikan **string** `investorLogin(` dan
  `pattern="[0-9]*"`/`inputmode="numeric"` ada di HTML — bootstrap-nya tidak diperiksa.
* `tests/qc_fase_5.php:2139` (**J.8**) hanya memastikan `app.js` berisi
  `Alpine.data('investorLogin', investorLogin)`.
* `tests/manual/js_investor_check.js:168` membangun komponen secara manual (`registry[name](config)`
  + `$root` tiruan) dan `:266` cukup memeriksa `loginView.includes('investorLogin(')` ⇒ probe Node
  ini pun tidak dapat menangkap layout yang tidak memuat Alpine.
* Tidak ada assertion mana pun tentang daftar `<script>` di `layouts/auth.php`
  (`grep alpine.min.js tests/` hanya menemukan `qc_routing.php:410` yang mengunduh asetnya langsung).

---

## 4. Opsi penyelesaian

### Opsi A — Perbaikan akar masalah (disarankan, minimal)

Tambahkan Alpine ke `app/Views/layouts/auth.php`, meniru urutan yang **sudah** dipakai
`layouts/investor.php:73-74` (dan `app.php:81-82`, `operator.php:130-131`):

```php
<!-- app/Views/layouts/auth.php — ganti baris 60 dengan dua baris ini -->
<script src="<?= Security::e(App::url('assets/js/app.js')) ?>" defer></script>
<script src="<?= Security::e(App::url('assets/js/alpine.min.js')) ?>" defer></script>
```

**Mengapa aman** (bukan sekadar “mengikuti yang lain”):

* Keduanya `defer` ⇒ dieksekusi berurutan sesuai posisi di dokumen, **sebelum** `DOMContentLoaded`.
  `app.js` mendaftarkan pendengar `alpine:init` saat dievaluasi (`app.js:1573`), sedangkan Alpine
  memulai siklusnya menunggu `DOMContentLoaded` ⇒ pendengar sudah terpasang. Pola yang sama sudah
  terbukti bekerja di tiga layout lain.
* Tanpa Alpine pun halaman tetap berfungsi (isian `<input>` biasa) — perbaikan ini **menambah**
  kemampuan, tidak menggantinya.
* Tidak perlu menaikkan `CACHE_NAME`: dokumen HTML selalu diambil dari jaringan
  (`public/sw.js:112-121`, mode `navigate` ⇒ network-first), dan daftar aset SW belum berubah.
* Aman terhadap assertion yang ada: B.1/B.2 tetap hijau (markup tak berubah), J.8 tidak tersentuh.

**Dampak** (sengaja, dan perlu diketahui):

* `/investor/login` — papan angka 10 tombol muncul & berfungsi, `⌫` tampil, `:disabled="submitting"` aktif.
* `/join` — `@scroll` pakta integritas berfungsi, `:disabled="submitting"` aktif.
* `/login` (operator) — memuat +55.891 byte `alpine.min.js` tanpa satu pun direktif Alpine di
  view-nya (`app/Views/auth/login.php` bersih dari `x-*`). Muatan tambahan ini juga sudah masuk
  daftar-putih SW (`public/sw.js:32`), jadi pada kunjungan berikutnya dilayani dari cache.
* Perbedaan perilaku `:disabled="submitting"` konsisten dengan AGENTS.md: `:disabled` **hanya** boleh
  pada `<button>` (tidak membawa data form). Tidak ada kontrol form yang diberi `:disabled` di sini.

**Varian A′ (jika muatan `alpine.min.js` di `/login` dianggap mengganggu):** muat Alpine hanya bila
view memang membutuhkannya (mis. variabel layout `$withAlpine` yang diisi controller). Ini menambah
satu jalur logika pada layout demi ±56 KB sekali unduh di halaman yang tidak memakainya — kompleksitas
tidak sebanding; **tidak disarankan**.

### Opsi B — Pengerasan opsional (berlapis di atas Opsi A)

Tujuannya bukan memperbaiki kasus hari ini, melainkan memastikan halaman **tidak pernah** lagi
menampilkan kotak hantu bila JS gagal dimuat/diblokir.

**B1 — `x-cloak` pada wadah papan angka.** Aturan `[x-cloak]{display:none!important}` sudah ada di
lapisan dasar (`resources/css/tailwind.input.css:94-96`) dan memakai `!important`, sehingga menang
atas utilitas `grid`:

```php
<!-- app/Views/investor/login.php:73 -->
<div class="grid grid-cols-3 gap-2" aria-hidden="true" x-cloak>
```

* Efek tanpa Alpine: papan angka (termasuk tombol hapus) tetap **tersembunyi** ⇒ tidak ada kotak kosong.
* Efek dengan Alpine: atribut dilepas saat init ⇒ tampil normal, tanpa kedipan.
* Batasan jujur: bila Alpine **ada** tetapi komponennya gagal (`ReferenceError`), `x-cloak` tidak akan
  pernah dilepas ⇒ papan angka hilang permanen (isian PIN tetap bekerja). Karena itu B1 hanya
  masuk akal **bersama** Opsi A, dan bukan penggantinya.
* Ditempatkan pada wadah `<div>`, **bukan** per tombol — satu atribut, satu titik kegagalan.

**B2 — teks fallback statis pada tombol hapus** (alternatif/pendamping B1):

```php
<!-- app/Views/investor/login.php:78-79 -->
<button type="button" class="af-btn af-btn--ghost py-3 text-base"
        @click="erase()" x-text="'⌫'">⌫</button>
```

* Dengan Alpine, `x-text` mengganti isi teks dengan glyph yang sama ⇒ tidak ada duplikasi.
* Tanpa Alpine, pengguna melihat `⌫`, bukan kotak kosong — namun tombolnya **mati**
  (`type="button"` tanpa penangan). Karena wadahnya `aria-hidden="true"`, tombol ini memang tidak
  pernah masuk urutan fokus pembaca layar, jadi tidak ada regresi aksesibilitas; tetapi kontrol mati
  yang tetap terlihat lebih buruk daripada disembunyikan ⇒ **B1 lebih disukai, B2 opsional**.

Catatan aset: B1/B2 hanya menyentuh berkas PHP, jadi **tidak** memerlukan kenaikan `CACHE_NAME`.
Sebaliknya, bila kelak ada perubahan pada `public/assets/js/app.js`, ingat bahwa aset statis
dilayani **cache-first** (`public/sw.js:123-146`) sehingga `CACHE_NAME` (`public/sw.js:26`) **wajib**
dinaikkan agar klien tidak tertahan pada salinan lama.

### Opsi C — Ditolak

* **Hapus papan angka.** Bertabrakan dengan niat yang tertulis di doc comment view itu sendiri
  (“Papan angka Alpine adalah **peningkatan** pengalaman: isian tetap `<input>` biasa yang berfungsi
  tanpa JavaScript”) dan dengan hasil Fase 5 yang didokumentasikan di AGENTS.md/`docs/`.
* **Bungkus seluruh papan angka dengan `x-show="false"`.** Sama saja dengan B1 tetapi lebih keruh
  (`x-show` mengubah gaya inline, bukan menyembunyikan sampai Alpine siap).
* **Memindahkan papan angka ke komponen baru / lazy-load Alpine.** Menambah kompleksitas dan
  menghidupkan kembali mode kegagalan yang sudah pernah memakan korban di proyek ini
  (lihat riwayat `clipboardButton` pada AGENTS.md dan komentar `app.js:2147-2151`).

---

## 5. Matriks dampak perbaikan

| Aspek | Tanpa perbaikan | Setelah Opsi A | A + B1 |
| --- | --- | --- | --- |
| Kotak kosong di `/investor/login` | Tampil | Hilang | Hilang |
| Papan angka PIN di ponsel | Mati | Berfungsi | Berfungsi |
| `:disabled="submitting"` (login) | Inert | Aktif | Aktif |
| Pakta integritas `/join` (`@scroll`) | Mati | Berfungsi | Berfungsi |
| Perilaku bila JS gagal dimuat | Kotak hantu | Kotak hantu | Papan angka tersembunyi |
| Aksesibilitas | Tidak berubah (`<input>` tetap satu-satunya kontrol wajib; papan angka `aria-hidden="true"`) | Tidak berubah | Tidak berubah |
| `CACHE_NAME` | — | Tidak perlu naik | Tidak perlu naik |
| Berkas tersentuh | — | `app/Views/layouts/auth.php` (1 baris) | + `app/Views/investor/login.php` (1 atribut) |

---

## 6. Penjaga regresi yang diusulkan (belum diimplementasikan)

### 6.1 Mengapa perlu

Seluruh suite saat ini **hijau meski bug ini ada** (§ 3.3). Yang tidak terjaga adalah hubungan
`layout → bootstrap Alpine`, tepat di titik yang gagal hari ini.

### 6.2 Usulan 1 — assertion statis di `tests/qc_fase_5.php` (dianjurkan)

Nomor `J.13` masih bebas (nomor terakhir yang terpakai: `J.12` pada baris 1951). Bentuk
tabel-pemetaan lebih disukai daripada satu pengecekan tunggal karena ia menangkap **seluruh kelas
bug** ini, termasuk layout/view yang ditambahkan kemudian:

```php
Qc::ok(
    'J.13',
    'Setiap layout yang merender view ber-Alpine memuat alpine.min.js (bootstrap tidak boleh hilang)',
    (static function (): bool {
        // View yang memakai direktif Alpine  ->  layout yang merendernya.
        $peta = [
            'investor/login.php'      => 'auth.php',      // x-data, x-for, x-text
            'investor/onboarding.php' => 'auth.php',      // x-data, @submit, @scroll
            'investor/dashboard.php'  => 'investor.php',  // x-data, x-model
            'auth/login.php'          => 'auth.php',      // tanpa direktif: pemetaan tetap aman
        ];

        foreach ($peta as $view => $layout) {
            $viewFile = VIEW_PATH . '/' . $view;
            $layoutFile = VIEW_PATH . '/layouts/' . $layout;

            if (!is_file($viewFile) || !is_file($layoutFile)) {
                return false;
            }

            $rendered = (string) file_get_contents($viewFile);
            $shell = (string) file_get_contents($layoutFile);

            // View tanpa direktif Alpine tidak menuntut apa pun dari layout.
            if (!str_contains($rendered, 'x-data') && !str_contains($rendered, 'x-for')
                && !str_contains($rendered, 'x-text') && !str_contains($rendered, 'x-model')
                && !str_contains($rendered, 'x-show')) {
                continue;
            }

            if (!str_contains($shell, 'assets/js/alpine.min.js')) {
                return false;
            }
        }

        return true;
    })(),
    'layout tanpa bootstrap Alpine membuat tombol ber-x-text dirender kosong'
);
```

Sifat assertion ini: **gagal pada kode hari ini** (karena `auth.php` belum memuat Alpine) dan
**lulus setelah Opsi A**. Ia sejalan dengan pendekatan suite lain yang memakai `file_get_contents`
(lihat `qc_fase_5.php:2101`, `:2143`). Ingat konvensi suite: `VIEW_PATH`/`PUBLIC_PATH` sudah
tersedia di bootstrap QC, dan tidak ada berkas yang ditulis.

### 6.3 Usulan 2 — probe Node `tests/manual/js_investor_check.js`

Tambahkan satu pemeriksaan di dekat baris 266 (bagian pengikatan view → state Alpine):

```javascript
const authLayout = fs.readFileSync(
    path.join(__dirname, '..', '..', 'app', 'Views', 'layouts', 'auth.php'),
    'utf8'
);

check('layout auth memuat bootstrap Alpine', authLayout.includes('assets/js/alpine.min.js'), true);
```

Probe ini **bukan** bagian dari `tests/run_qc.sh` (butuh Node), jadi sifatnya pendamping, bukan gerbang.

### 6.4 Assertion yang harus tetap hijau

Setelah Opsi A (dan B1 bila dipilih), assertion berikut tidak boleh berubah status:
`qc_fase_5` **B.1** (`/investor/login` 200 + `name="pin"`), **B.2** (papan angka = peningkatan),
**J.8** (registrasi komponen di `app.js`), serta `qc_routing` (aset `assets/js/alpine.min.js` 200).
Tidak ada assertion yang saat ini bergantung pada **ketiadaan** `alpine.min.js` di layout mana pun.

---

## 7. Langkah verifikasi untuk pelaksana (belum dijalankan dalam audit ini)

```bash
# 1. Lint berkas yang diubah
php -l app/Views/layouts/auth.php
php -l app/Views/investor/login.php        # hanya bila memakai B1/B2

# 2. Gerbang kelulusan (MENULIS ke database pada .env — pastikan target database uji)
bash tests/run_qc.sh

# 3. Probe lapis Alpine
node tests/manual/js_investor_check.js

# 4. Pemeriksaan manual: tiru .htaccess
php -S 127.0.0.1:8000 -t public tests/server_router.php
#    buka /investor/login pada viewport 360px, lalu di console:
#    window.Alpine                       // harus object (bukan undefined)
#    document.querySelectorAll('.af-btn--ghost').length   // 11 (10 angka + 1 hapus)
#    getComputedStyle(document.querySelector('.af-btn--ghost')).minHeight  // 52px
#    klik beberapa tombol; cek isian name="pin" bertambah, maksimal 6 digit
#    /join: gulir kotak pakta integritas sampai bawah, tombol kirim jadi nonaktif saat dikirim
```

Bukti cepat tanpa peramban (setelah perbaikan) yang boleh dipakai sebagai regresi dokumenter:

```bash
curl -s http://127.0.0.1:8000/investor/login | grep -c 'assets/js/alpine.min.js'   # harus 1
```

---

## 8. Temuan sampingan (di luar lingkup, tidak diusulkan diperbaiki di sini)

1. **State `opened` yang tidak pernah dibaca.** `app/Views/investor/onboarding.php:55` menyiapkan
   `opened: false` dan `:64` menyetelnya saat kotak pakta integritas digulir, tetapi tidak ada satu
   pun direktif yang membacanya (`x-show`/`:class`/`x-text` tidak memakainya). Ini sisa kode mati
   relatif terhadap aturan “Tidak ada kode mati” di AGENTS.md. Bila dikembalikan fungsinya
   (mis. mengunci tombol sampai klausul terbaca habis), ingat bahwa `:disabled` **hanya** boleh
   dipakai pada `<button>`, bukan pada kontrol pembawa data form.
2. **`x-ref="pinField"` tidak terpakai.** `app/Views/investor/login.php:61` mendaftarkan ref itu,
   tetapi tidak ada kode yang membacanya — `investorLogin.press()`/`erase()` memakai
   `formField(this, 'pin')` yang menelusuri DOM dari `$root` (`app.js:289-304`). Tidak berbahaya;
   disimpan hanya sebagai catatan agar tidak dikira sumber masalah.
3. **`/login` (operator) memuat `app.js` tanpa Alpine.** Halaman ini tidak memakai direktif Alpine
   sama sekali, jadi tidak ada gejala visual Hari ini — tetapi setelah Opsi A perbedaan ini hilang
   dengan sendirinya.

---

## 9. Batasan audit ini

* Investigasi **baca-saja**: tidak ada berkas PHP/JS/CSS yang diubah, tidak ada kode proyek yang
  dieksekusi (tanpa `php -S`, `run_qc.sh`, `node`, atau rendering view). Tidak ada commit.
* Seluruh kesimpulan berasal dari pembacaan statis sumber: layout, controller, view, CSS terkompilasi
  (`public/assets/css/app.min.css`), konfigurasi Tailwind, `public/sw.js`, dan suite QC.
  Satu-satunya berkas yang ditulis oleh audit ini adalah dokumen ini sendiri.
* Nomor baris mengacu pada snapshot pohon kerja saat audit (perubahan Fase 2–5 belum di-commit);
  periksa ulang bila berkas bergeser.
