# Summary Copy Fix — AurumFlow

> **Status dokumen (pasca-Fase 9 — sinkronisasi dokumentasi).** Seluruh angka dan perilaku di
> bawah ini — termasuk (bila ada) penyebutan **13 tabel** atau kurang, **14 suite / 853
> assertion**, `CACHE_NAME` **v3**, dan isian **`coord_fee`** — adalah **potret fase tersebut**,
> bukan keadaan sekarang. Keadaan terkini: **14 tabel** (termasuk `operational_expenses`, nol `ALTER`),
> `CACHE_NAME` **`aurumflow-shell-v4`**, **15 suite / 900 assertion / 0 gagal**, beban operasional
> dicatat sebagai **voucher kas** di meja kasir lalu diserap otomatis oleh tutup buku
> (`total_cash = pendapatan`). Rujukan terkini: `plan/Summary_Fase_9_OPEX_AurumFlow.md`.


> **Laporan Perbaikan UI (pascarilis Fase 5)**
> Cacat: tombol **"Salin tautan"** pada kartu "Undangan & Onboarding Mitra" (`/operator/capital`) tidak berfungsi —
> konsol peramban menampilkan `Alpine Expression Error: Unexpected token 'try'`.
> Status: **SELESAI, LALU DIGANTIKAN.** Perbaikan komponen `Alpine.data('clipboardButton', …)` pada laporan ini
> terbukti rentan terhadap aset JS yang masih ter-cache peramban (`ReferenceError: clipboardButton is not defined`
> → tombol hilang). Pendekatan final memakai state literal + rantai Promise di dalam view:
> lihat **`plan/Summary_Copy_Inline_Fix.md`**. Dokumen ini dipertahankan sebagai riwayat analisis akar masalah
> (`try…catch` di atribut Alpine) yang masih berlaku.

---

## 1. Akar Masalah

Atribut Alpine (`@click`, `x-show`, `x-text`, `:class`) dievaluasi sebagai **ekspresi
JavaScript**, bukan blok pernyataan. Markup lama menaruh pernyataan `try…catch` langsung di
dalam `@click`:

```html
<!-- SEBELUM — tidak sah -->
<button
    @click="try { navigator.clipboard.writeText($refs.inviteLink.value); copied = true } catch (e) { copied = false }"
    x-text="copied ? 'Tersalin' : 'Salin tautan'"
>Salin tautan</button>
```

Alpine gagal meng-*parse* ekspresi itu (`Unexpected token 'try'`) dan melempar galat pada
saat klik; tombol tampak normal tetapi tidak melakukan apa pun — tanpa pesan apa pun kepada
operator. Masalah kedua tersembunyi di situ: nilai dibaca lewat `$refs.inviteLink`,
sedangkan tombol ini hidup di dalam `foreach` (satu tombol per mitra), sehingga beberapa
`x-ref` bernama sama saling menimpa — tombol baris kedua berpotensi menyalin tautan baris
pertama.

## 2. Perbaikan

**`public/assets/js/app.js`** — komponen Alpine baru (satu pemilik perilaku):

```js
function clipboardButton(text) {
    var value = typeof text === 'string' ? text : '';

    return {
        copied: false,
        copy: function () {
            var component = this;

            if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
                window.alert('Papan klip tidak tersedia di peramban ini — salin tautan manual dari kotak di atas.');
                return;
            }

            navigator.clipboard.writeText(value).then(function () {
                component.copied = true;
                window.setTimeout(function () { component.copied = false; }, 2000);
            }).catch(function () {
                window.alert('Gagal menyalin tautan — salin manual dari kotak di atas.');
            });
        }
    };
}

// Alpine.data('clipboardButton', clipboardButton)
```

**`app/Views/operator/capital.php`** — markup bersih, tanpa `try…catch` dan tanpa `$refs`:

```html
<div class="mt-2" x-data="clipboardButton('<?= Security::e($wallet['invite_url']) ?>')">
    <label class="block">
        <span class="af-label">Tautan undangan (kirim lewat WhatsApp)</span>
        <input type="text" readonly class="af-input af-input--sm af-num text-[0.7rem]"
               value="<?= Security::e($wallet['invite_url']) ?>" onclick="this.select()">
    </label>
    <button type="button" class="af-btn af-btn--ghost mt-2 px-3 py-1 text-[0.7rem]"
            :class="copied ? 'text-emerald-500' : ''"
            @click="copy()"
            x-text="copied ? 'Tersalin ✓' : 'Salin tautan'">Salin tautan</button>
</div>
```

Perubahan perilaku yang disengaja, di luar sekadar memperbaiki galat:

| Sebelum | Sesudah | Alasan |
|---|---|---|
| `$refs.inviteLink.value` | tautan dioper sebagai **argumen** komponen | `x-ref` bernama sama di dalam `foreach` saling menimpa |
| `try…catch` di atribut | `try`/`catch` hidup di komponen | atribut Alpine adalah ekspresi |
| Tidak ada umpan balik kegagalan | `copied` kembali `false` setelah 2 detik; kegagalan → pesan | operator tahu tautan benar-benar tersalin |
| — | papan klip tidak tersedia (HTTP, izin ditolak) → pesan "salin manual" | peramban hanya menyediakan `navigator.clipboard` pada konteks aman; jangan biarkan tombol diam |

Kotak tautan tetap `readonly` + `onclick="this.select()"`, sehingga jalur "salin manual"
(tanpa JavaScript, atau pada peramban tanpa papan klip) tetap bekerja.

## 3. Penjaga Regresi

| Penjaga | Cakupan |
|---|---|
| `qc_fase_5` **I.13** | Memindai **seluruh view** dan gagal bila ada `try`/`catch` di dalam atribut Alpine (`@click`, `@submit`, `@scroll`, `x-on:`, `x-data`, `x-init`, `x-show`) |
| `qc_fase_5` **J.8** | Memastikan `Alpine.data('clipboardButton', clipboardButton)` terdaftar di `app.js` |
| `qc_fase_5` **G.3** | Layar Kelola Modal tetap memuat tombol "Salin tautan" + form putar ulang tautan |
| `tests/manual/js_investor_check.js` (8 assertion baru) | `copy()` menyalin tautan dari peladen; `copied` menjadi `true` lalu kembali; papan klip tak tersedia dan izin ditolak → status tidak berubah + pengguna diberi tahu |
| `AGENTS.md` | Kontrak baru: **atribut Alpine adalah ekspresi**, logika bercabang hidup di `Alpine.data()`, nilai dari peladen dioper sebagai argumen (bukan `$refs`) |

Gerbang akhir: `bash tests/run_qc.sh` → exit 0, 11/11 suite, **595 assertion, 0 gagal**;
`node tests/manual/js_form_init_check.js` 39/39; `js_capital_simulator_check.js` 38/38;
`js_investor_check.js` **38/38**; `php -l` bersih; tidak ada `storage/logs/php-*.log` baru;
`git status` tanpa artefak.

## 4. Berkas yang Disentuh

| Berkas | Perubahan |
|---|---|
| `public/assets/js/app.js` | Komponen `clipboardButton` + registrasi `Alpine.data(...)` + butir 10 pada header |
| `app/Views/operator/capital.php` | Kartu F: `x-data="clipboardButton(…)"`, `@click="copy()"`, `x-text`/`:class` status; `x-ref` & `try…catch` dihapus |
| `tests/qc_fase_5.php` | Assertion `I.13` + `J.8` diperluas (126 assertion) |
| `tests/manual/js_investor_check.js` | Blok 2b: 8 assertion tombol salin (38 assertion) |
| `AGENTS.md`, `README.md`, `plan/Plan_Fase_5_AurumFlow.md`, `plan/Summary_Fase_5_AurumFlow.md` | Kontrak atribut Alpine + baseline angka diperbarui |

> Keputusan lama yang dipertahankan: pesan kegagalan memakai `window.alert()` sederhana
> (bukan komponen notifikasi baru) — proporsional untuk satu aksi salin, dan tidak menambah
> permukaan UI baru yang harus dirawat. Bila kelak ada beberapa tombol salin lain,
> komponen ini tinggal dipakai ulang dengan argumen berbeda.
