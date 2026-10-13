# Summary Copy Inline Fix — AurumFlow

> **Laporan Perbaikan UI (pascarilis Fase 5, lanjutan `Summary_Copy_Fix.md`)**
> Cacat: tombol **"Salin tautan"** (`/operator/capital`, kartu "Undangan & Onboarding Mitra") **hilang seluruhnya** —
> konsol menampilkan `ReferenceError: clipboardButton is not defined`, sehingga evaluasi `x-text` gagal dan tombol tidak dirender.
> Status: **SELESAI** — `bash tests/run_qc.sh` exit 0 (11/11 suite, **598 assertion, 0 gagal**) + probe Node 39/38/43.

---

## 1. Akar Masalah (dan mengapa perbaikan sebelumnya belum cukup)

Rantai kejadian yang sama, dua lapis:

| Tahap | Gejala | Sebab |
|---|---|---|
| Perbaikan pertama | `Alpine Expression Error: Unexpected token 'try'` | `try…catch` **pernyataan** ditaruh di atribut `@click`; Alpine menempelkan `return` di depan ekspresi atribut, sehingga `return try { … }` tidak sah |
| Perbaikan kedua | `ReferenceError: clipboardButton is not defined` → **tombol hilang** | `x-data="clipboardButton(…)"` menunjuk komponen yang baru ditambahkan ke `app.js`; bila berkas JS itu masih versi lama di cache peramban (atau gagal dimuat), pabrik komponennya tidak ada. `x-data` gagal init ⇒ `x-text`/`:class` **tidak pernah dievaluasi** ⇒ elemen tombol tidak muncul sama sekali |

Pelajaran yang menentukan bentuk perbaikan ini: **atribut `x-data` boleh bergantung pada komponen yang mungkin belum ada di aset yang di-cache; state literal tidak.** Kerusakannya pun jauh lebih buruk daripada gagal-klik: pengguna kehilangan tombolnya, bukan sekadar pesan galat.

## 2. Perbaikan (bulletproof & cache-independent)

**`app/Views/operator/capital.php`** — seluruh perilaku tombol hidup di markup; tidak ada komponen baru yang perlu ter-registrasi lebih dulu:

```html
<div class="mt-2" x-data="{ copied: false }">
    <label class="block">
        <span class="af-label">Tautan undangan (kirim lewat WhatsApp)</span>
        <input type="text" readonly class="af-input af-input--sm af-num text-[0.7rem]"
               value="<?= Security::e($wallet['invite_url']) ?>" onclick="this.select()">
    </label>
    <button type="button" class="af-btn af-btn--ghost mt-2 px-3 py-1 text-[0.7rem]"
            :class="copied ? 'text-emerald-500' : ''"
            @click="navigator.clipboard.writeText('<?= Security::e($wallet['invite_url']) ?>').then(() => { copied = true; setTimeout(() => copied = false, 2000); })"
            x-text="copied ? 'Tersalin ✓' : 'Salin tautan'">Salin tautan</button>
</div>
```

**`public/assets/js/app.js`** — komponen `clipboardButton` beserta registrasinya **dihapus** (butir 10 pada header ikut hilang; penomoran bagian kembali 1–9). Tidak ada sisa rujukan pada berkas itu.

Markup yang benar-benar dirender (probe HTTP sebagai operator, 3 mitra menunggu onboarding):

| Diperiksa | Hasil |
|---|---|
| `x-data="{ copied: false }"` | 3 |
| `@click="navigator.clipboard.writeText('<absolut URL>').then(() => { copied = true; setTimeout(() => copied = false, 2000); })"` | 3 |
| `x-text="copied ? 'Tersalin ✓' : 'Salin tautan'"` | 3 |
| `:class="copied ? 'text-emerald-500' : ''"` | 3 |
| sisa `try`/`catch` pada atribut klik | **0** |
| sisa `clipboardButton` | **0** |

Jalur manual tetap tersedia tanpa JavaScript: kotak tautan `readonly` dengan `onclick="this.select()"`.

## 3. Trade-off yang disengaja (perlu diketahui)

1. **Tidak ada umpan balik bila penyalinan gagal.** Rantai Promise tidak memuat `.catch()`, sehingga penolakan izin (promise *rejected*) hanya muncul di konsol. Yang **tidak** lagi terjadi: tombol hilang.
2. **Konteks tidak aman tidak punya papan klip.** `navigator.clipboard` hanya tersedia pada HTTPS/localhost (peramban tidak menyediakannya di `http://` biasa). Pada `http://` — seperti `APP_URL` mesin dev saat ini — klik akan melempar `TypeError` di konsol dan status tidak berubah. Di produksi (HTTPS) jalur ini tidak terpicu. Bila operator perlu pesan "salin manual" untuk kasus itu, cukup satu penyesuaian kecil pada rantai yang sama, mis. `(navigator.clipboard?.writeText('…') ?? Promise.reject()).then(…).catch(() => alert('Salin manual dari kotak di atas'))` — **belum** diterapkan karena permintaan kali ini eksplisit meminta rantai murni tanpa penanganan galat.
3. **Tautan berada di dalam literal string JS.** Ini aman karena nilainya dibentuk peladen: `App::absoluteUrl()` membatasi host pada `[A-Za-z0-9.\-:]` dan token selalu 64 heksadesimal — tidak ada `'`, `"`, `\`, atau `<` yang bisa memutus string. Dijaga assertion `J.12` yang memeriksa pola URL pada HTML terkirim.

## 4. Penjaga Regresi

| Penjaga | Cakupan |
|---|---|
| `qc_fase_5` **I.13** | Seluruh view dipindai: tidak boleh ada `try`/`catch` di dalam atribut Alpine |
| `qc_fase_5` **J.10** | HTML terkirim wajib memuat `x-data="{ copied: false }"` + rantai Promise yang utuh + `x-text`/`:class` status, dan tidak boleh ada `try`/`catch` pada `@click` mana pun |
| `qc_fase_5` **J.11** | Tidak ada lagi rujukan `clipboardButton` **di kode** view (baris komentar dilewati agar riwayat tetap boleh terdokumentasi) |
| `qc_fase_5` **J.12** | URL pada atribut `@click` cocok pola aman (`https?://host[:port]/join?token=<64 heksadesimal>`) |
| `qc_fase_5` **J.8** | `app.js` **tidak** mendaftarkan komponen itu lagi (menjaga penghapusan tetap konsisten) |
| `js_investor_check.js` (+5 assertion, total 43) | Ekspresi `@click` diambil dari view, tag PHP diganti contoh URL, lalu **dikompilasi dan dijalankan persis seperti Alpine** (`with ($data) { return <ekspresi> }`) atas stub papan klip: kompilasi tanpa `SyntaxError`, mengembalikan Promise, mengisi papan klip, `copied` menjadi `true`, lalu kembali `false` setelah timer |

## 5. Berkas yang Disentuh

| Berkas | Perubahan |
|---|---|
| `app/Views/operator/capital.php` | `x-data="{ copied: false }"` (literal) + `@click` berisi rantai Promise; komentar menjelaskan riwayat dua bug |
| `public/assets/js/app.js` | Komponen `clipboardButton` + registrasi + butir header dihapus; penomoran bagian kembali 1–9 |
| `tests/qc_fase_5.php` | `J.8` disesuaikan, `J.10`–`J.12` baru (129 assertion) |
| `tests/manual/js_investor_check.js` | Blok 2b ditulis ulang: 5 assertion markup + uji kompilasi/eksekusi ekspresi (43 assertion) |
| `AGENTS.md`, `README.md`, `plan/Plan_Fase_5_AurumFlow.md`, `plan/Summary_Fase_5_AurumFlow.md` | Kontrak Alpine diperbarui (interaksi sekali pakai tinggal di view; komponen `Alpine.data()` hanya bila dipakai berulang/memuat state kompleks) + baseline angka 598 assertion |
| `plan/Summary_Copy_Fix.md` | Diberi catatan bahwa pendekatan komponen **digantikan** oleh laporan ini |

Gerbang akhir: `bash tests/run_qc.sh` → exit 0, 11/11 suite, **598 assertion, 0 gagal**;
`js_form_init_check.js` 39/39; `js_capital_simulator_check.js` 38/38; `js_investor_check.js` **43/43**;
`php -l` bersih; `node --check` bersih pada `app.js` & probe; tanpa `storage/logs/php-*.log` baru;
`git status` tanpa artefak.
