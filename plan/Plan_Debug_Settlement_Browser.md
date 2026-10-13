# Plan Debug — Nilai Awal Form di Wipe Peramban (Settlement & Kasir)

> ## ⚠️ SUPERSEDED — jangan dieksekusi
>
> Akar masalah sebenarnya **bukan** wipe nilai awal maupun aset stale, melainkan
> `:disabled="submitting"` pada kontrol form: peramban mengeluarkan kontrol `disabled`
> dari data form, sehingga POST hanya membawa `_token`/`_once` (± 357 byte) dan peladen
> menjawab `422` "wajib diisi". Rencana hidrasi 3 lapis, `data-af-initial`, `$nextTick`/
> `alpine:initialized`, dan cache-busting `?v=` di dokumen ini **tidak diimplementasikan**.
>
> Perbaikan yang benar beserta buktinya: **`plan/Summary_Fix_Disabled_Inputs.md`**.
> Dokumen ini dipertahankan hanya sebagai jejak bukti & daftar hipotesis yang sudah gugur
> (H1–H5 § 4 semuanya tidak terbukti; yang terbukti adalah kontrol `disabled` hilang dari payload).
>
> **Dokumen Perencanaan Perbaikan (delta)**
> Proyek: **AurumFlow**
> Cakupan: `settlementForm` + `tellerForm` (`public/assets/js/app.js`), `app/Views/operator/settlement.php`,
> `app/Views/operator/teller.php`, layout aset (`app/Views/layouts/*.php`), harness (QC + probe).
> Status: `APPROVED FOR PLANNING` · **belum ada kode PHP/JS yang diubah.**
> Melanjutkan `plan/Plan_Fix_Settlement_UI.md` (sudah dieksekusi): lapis-lapis di sana **tetap dipakai**
> (atribut `mis. `, `x-model.fill`, `querySelector`, `restoreAutoSplit(false)`) tetapi **belum cukup** —
> dokumen ini menjelaskan mengapa, dan apa yang harus ditambahkan.

| Item | Nilai |
|---|---|
| Gejala | Di Chrome/Chromium nyata, `sold_grams` & `coord_fee` **terlihat kosong** tepat setelah `/operator/settlement` dimuat; submit menghasilkan `422` dengan payload sangat kecil (`Content-Length ≈ 357` byte) |
| Kontradiksi | QC PHP **355/355 lulus** dan probe Node `tests/manual/js_form_init_check.js` **29/29 lulus** — kedua lapis tidak dapat melihat siklus hidup Alpine di peramban |
| Akar yang sudah pasti | Penghapusan nilai oleh Alpine bersifat **penulisan properti** (`input.value`), bukan atribut; QC/probe berbasis HTML & pemanggilan metode manual tidak melihatnya |
| Keputusan pengguna | `dec-14827488320397ae` — (A) probe CDP otomatis untuk dijalankan pengguna, (A) cache-busting aset `?v=` masuk lingkup |
| Bahasa dokumen | Prosa Indonesia + istilah teknis Inggris (konvensi `Plan_Fase_*.md`) |

---

## 1. Gejala & bukti yang sudah ada

| # | Bukti | Sumber |
|---|---|---|
| E-1 | `sold_grams` & `coord_fee` kosong begitu halaman selesai dimuat (dua field yang **di-pre-fill peladen**) | laporan uji manual di peramban |
| E-2 | Submit → `422`, payload ± 357 byte | laporan. Ukuran ini berarti POST **tanpa berkas nota** dan **tanpa nilai field** (multipart lengkap akan jauh lebih besar), jadi pengiriman terjadi tanpa field terisi |
| E-3 | QC PHP 9 suite · 355 assertion · 0 gagal | `storage/logs/qc-fase3.log` |
| E-4 | Probe Node 29/29 lulus (termasuk pembacaan nilai awal) | `node tests/manual/js_form_init_check.js` |
| E-5 | `x-model.fill`, `querySelector`, `restoreAutoSplit(false)` sudah terpasang | `app/Views/operator/settlement.php`, `public/assets/js/app.js` |

**Kesimpulan awal:** perbaikan sebelumnya menambahkan *peserta balapan* baru, bukan pemilik kata
terakhir. E-3 dan E-4 lulus karena keduanya memeriksa HTML atau memanggil metode secara manual —
tidak ada yang memodelkan urutan directive Alpine dan penulisan properti yang menghapus nilai.

---

## 2. Rantai yang sudah pasti (bukan dugaan) — bukti dari `alpine.min.js` 3.17.4

Seluruh kutipan dari `public/assets/js/alpine.min.js` (`version:"3.17.4"`).

```text
# 1. Urutan di dalam handler x-model sebuah elemen:
#    (a) pemasangan listener  (b) cek modifier .fill  (c) definisi _x_model
#    (d) registrasi + eksekusi effect pertama
if(t.includes("fill") && ([void 0,null,""].includes(c()) || …) && l(gt(e,t,{target:e},c())))   # (b)
…
e._x_forceModelUpdate = d => { … h(()=>{ … : Ce(e,"value",d) }) }                             # (c)
n(()=>{ let d = c(); … || e._x_forceModelUpdate(d) })                                          # (d)

# 2. Pembaca nilai yang dipakai .fill = PROPERTI DOM (bukan atribut)
function gt(e,t,r,n){ … else { … i = r.target.value … } }        # r.target === elemen input

# 3. Penulis yang menghapus nilai = PROPERTI DOM juga (input teks)
function Ce(e,t,r,n=[]){ … case "value": Li(e,r); break; … }
function Li(e,t){ if(nt(e)) …; else if(De(e)) …; else { if(e.value===t && …) return;
                                                          e.value = t === void 0 ? "" : t } }

# 4. Konfirmasi bahwa atribut TIDAK tersentuh (hanya properti)
#    → getAttribute('value') tetap memuat nilai yang dirender peladen

# 5. Hook terakhir sebelum operator dapat mengetik
te(document,"alpine:initialized")     # dipancarkan ke document setelah pass awal selesai
b("nextTick",()=>_e)                  # $nextTick tersedia sebagai magic
```

**Invariant yang menjelaskan seluruh gejala:**
> Wipe yang terlihat operator hanya mungkin terjadi bila **`input.value` sudah kosong pada saat cek
> `.fill` berjalan**. Karena `.fill` membaca `r.target.value` (properti itu sendiri), ia hanya bisa
> *mencegah* wipe yang kebetulan belum terjadi — ia tidak pernah bisa *memperbaiki* wipe.

Konsekuensinya: perbaikan tidak boleh berupa "menang balapan", melainkan **"memegang penulisan terakhir"**.

---

## 3. Mengapa perbaikan sebelumnya gagal

| Asumsi lama | Kenyataan (per § 2) |
|---|---|
| `init()` pasti berjalan sebelum `x-model` menulis nilai | Tidak dijamin, dan **tidak cukup** walau benar: penulisan `x-model` terjadi **per elemen**, sedangkan `init()` kita berjalan **satu kali** untuk seluruh komponen |
| `x-model.fill` adalah jaring pengaman | `.fill` = peserta balapan: dievaluasi **sekali** per elemen, dan membaca **properti** yang justru dihapus penulis lain |
| `$root.querySelector` salah scope | Kemungkinan besar **tidak**. `x-data` ada pada `<section>` yang memuat `<form>`, jadi scope benar. Masalahnya **waktu** (satu titik) dan **sumber** (properti, bukan atribut) |
| "Alpine mengevaluasi `.fill` sebelum DOM selesai dilukis" | Bukan itu mekanismenya: Alpine mulai setelah parse selesai (`DOMContentLoaded`), jadi ini bukan balapan paint |
| "Probe Node hijau ⟹ peramban aman" | Probe memanggil `init()` secara manual dan **tidak memodelkan** urutan directive maupun penulisan properti → **hijau palsu**. Preseden: `Plan_Fix_Settlement_UI § 2.5` |

---

## 4. Hipotesis, terurut, dengan bukti pembeda

| # | Hipotesis | Mekanisme | Bukti pembeda | Tindakan bila terbukti |
|---|---|---|---|---|
| **H1** | **Restorasi form-state Chrome** menimpa nilai yang dirender peladen saat reload (riwayat pengiriman kosong operator; `autocomplete="off"` tidak menghentikan restorasi nilai) | Chrome mengembalikan nilai "kotor" milik sesi ke `input.value` sebelum Alpine berjalan → `.fill` membaca `''` | Atribut utuh **+** properti kosong **+** **incognito/hard reload → benar** | Perbaikan § 5 (hidrasi berbasis atribut memperbaikinya) |
| **H2** | **Urutan/pass lebih awal**: penulisan `x-model` mendahului pembacaan kita dan `.fill` (mis. elemen diproses dua kali) | Properti sudah kosong saat cek `.fill` | Atribut utuh **+** properti kosong **juga di profil bersih** | Perbaikan § 5 + jejak T1 untuk merekam urutannya |
| **H3** | **`app.js` ter-cache (stale)** — aset tanpa versi | Peramban menjalankan `app.js` versi lama (tanpa hidrasi baru) | `sha256` `app.js` yang dimuat ≠ isi disk; `Alpine.version`; hard reload mengubah perilaku | Perbaikan § 5 + cache-busting `?v=` (T5) |
| **H4** | **Alpine berhenti sebagian** (ada exception) sehingga `:disabled` tidak pernah dipasang | Wipe terjadi, tetapi tombol submit tetap aktif → operator bisa mengirim form kosong → `422` payload kecil (E-2) | Error di konsol; `button[type=submit].disabled`; apakah seluruh `x-text` pratinjau terisi | Bereskan exception dulu; wipe menjadi gejala |
| **H5** | **Peladen merender nilai kosong** (batch/DB berbeda, HTML stale) | Tidak ada yang perlu di-wipe — nilainya memang kosong | View-source memuat `value=""` | **Berhenti**: ini bukan bug Alpine; tangani sumbernya |

> T0 memutuskan hipotesis mana yang berlaku **sebelum** kode disentuh. Perbaikan § 5 benar untuk
> H1–H3 dan tetap bermanfaat untuk H4, tetapi H5 mengubah lingkup.

---

## 5. Strategi perbaikan — tahan urutan, tiga lapis, berbasis atribut

**Prinsip:** *atribut sebagai sumber kebenaran, penulisan model sebagai kata terakhir.*

### 5.1 View — sumber yang tidak dapat disentuh Alpine

| Berkas | Perubahan |
|---|---|
| `app/Views/operator/settlement.php` | Setiap isian bernilai awal diberi `data-af-initial="<?= Security::e($…Value) ?>"` **berdampingan** dengan `value="…"` yang sudah ada, memakai **variabel PHP yang sama** (`$soldValue`, `$densityValue`, `$priceValue`, `$coordValue`, `$kasValue`, `$brankasValue`) — satu sumber, dicetak dua kali |
| `app/Views/operator/teller.php` | Idem untuk `weight` (`$weightValue`), `price` (`$priceValue`), dan kotak centang `burn_fee_enabled` (`data-af-initial="<?= $applyFee ? '1' : '0' ?>"`) |
| Alasan | `value="…"` dipertahankan untuk jalur **tanpa JavaScript**; `data-af-initial` adalah atribut yang **tidak pernah** ditulis Alpine (bukti § 2 butir 4), sehingga menjadi sumber yang imun terhadap wipe |

### 5.2 JS — pembaca berbasis atribut + penerapan yang terlambat

1. **`formFieldInitial(component, name)`** (menggantikan `formFieldValue()`): baca `data-af-initial`
   → cadangan `getAttribute('value')`. **Tidak** memakai `.value` sebagai sumber pertama. `formField()`
   (pencarian elemen) tetap dipakai.
2. **`formFieldChecked(component, name, fallback)`**: menjadi berbasis atribut (`hasAttribute('checked')`)
   — atribut `checked` adalah keputusan peladen, dan penulisan Alpine (`e.checked = …`) juga hanya properti.
3. **`scheduleHydration(component)`** memanggil `component.hydrateFromServer()` pada tiga titik idempoten:

```js
function scheduleHydration(component) {
    component.hydrateFromServer();                                  // 1) langsung (atribut imun)
    component.$nextTick(function () { component.hydrateFromServer(); });   // 2) setelah pass awal
    document.addEventListener(                                        // 3) setelah SELURUH tree selesai
        'alpine:initialized',
        function () { component.hydrateFromServer(); },
        { once: true }
    );
}
```

4. **`hydrateFromServer()`** di `settlementForm` **dan** `tellerForm`: satu aturan per field —
   *"hanya mengisi, tidak pernah mengosongkan, tidak pernah menimpa ketikan operator"*:

```js
// nilai atribut -> model; lewati bila peladen memang kosong atau model sudah terisi
this.soldGrams = hydrateField(this.soldGrams, formFieldInitial(this, 'sold_grams'));
…
this.restoreAutoSplit(false);   // no-op bila kedua isian sudah terisi
```

5. **Yang dipertahankan sebagai lapis tambahan** (bukan lagi satu-satunya pertahanan):
   `x-model.fill`, `querySelector` lewat `formField()`, `restoreAutoSplit(false)`.
6. **Yang sengaja TIDAK dilakukan:** menulis penanda `data-af-hydrated` saat runtime (menyentuh DOM
   yang sedang dipantau `MutationObserver` Alpine — risiko re-init), memindahkan nilai awal ke JSON
   konfigurasi Alpine (duplikasi sumber), memakai `setTimeout`, atau membuat peladen mengarang nilai.
7. **Tanpa kode mati:** `formFieldValue()` dihapus karena seluruh pemanggilnya beralih ke
   `formFieldInitial()`.

### 5.3 Cache-busting aset (keputusan `dec-14827488320397ae`)

| Berkas | Perubahan |
|---|---|
| `app/Config/App.php` | Metode baru `asset(string $path): string` = `url($path)` + `?v=<filemtime>` (bila berkas ada; tanpa query bila tidak) |
| `app/Views/layouts/app.php` (baris 40, 78, 79), `operator.php` (52, 123, 124), `auth.php` (22, 38) | `App::url('assets/…')` → `App::asset('assets/…')` |
| Keamanan QC | `tests/qc_routing.php` mengambil aset **berdasarkan path** (`/assets/js/app.js`) sehingga tetap lulus; `tests/server_router.php` sudah memakai `parse_url(…, PHP_URL_PATH)` sehingga query string aman |

---

## 6. Rencana eksekusi (T0–T7)

### T0 — Diagnosis lebih dulu (TANPA mengubah kode)

Kumpulkan bukti dan isi tabel H1–H5 pada ADDENDUM:

1. **Atribut vs properti** (bukti penentu): di konsol peramban
   `document.querySelector('[name="sold_grams"]').value` **vs** `.getAttribute('value')` **vs** `.defaultValue`.
   - properti `''` + atribut `18,050` ⟹ penulisan properti oleh skrip (Alpine) → § 5 berlaku.
   - properti `''` + atribut `''` ⟹ peladen merender kosong/HTML stale → H5, **berhenti & ubah lingkup**.
2. **Kontrol**: (a) incognito/profil bersih, (b) hard reload, (c) JavaScript dimatikan.
   H1 ⟹ benar di (a)/(b); H2 ⟹ tetap wipe di (a)/(b).
3. **Kesehatan Alpine**: error di konsol, `Alpine.version`, tombol submit `disabled`?, semua `x-text`
   pratinjau terisi?, dan `document.querySelector('section[x-data]')._xDataStack ?? el._x_dataStack` ada?
   (H4)
4. **Keaslian aset**: `sha256sum public/assets/js/app.js` (disk) vs isi `app.js` di tab Network/`fetch`;
   pastikan `formFieldValue`/`hydrateFromServer` sesuai versi yang diharapkan (H3).
5. **Ukuran payload**: hitung `new FormData(document.querySelector('form')).size`-nya di peramban
   (atau jumlah byte key+value) — **jangan** submit sungguhan; cukup buktikan field kosong (E-2).

Keluaran T0: satu tabel bukti + veredikt hipotesis. Bila H3/H4/H5 menang, lingkup T2–T5 disesuaikan
**sebelum** menulis kode.

### T1 — Instrumentasi sementara (dihapus di T5)

`console.debug` berlabel pada: `init()`, `hydrateFromServer()` (mencatat `model sebelum`/`sesudah` dan
`atribut`/`properti`), callback `$nextTick`, dan listener `alpine:initialized`. Tujuannya merekam
**urutan nyata** antara penulisan `x-model` dan hidrasi kita di peramban pengguna.

### T2 — View

`data-af-initial` pada 6 isian rekonsiliasi + 3 isian kasir (§ 5.1). Tanpa perubahan lain: placeholder
`mis. `, penanda "wajib", dan `value="…"` tetap.

### T3 — `public/assets/js/app.js`

`formFieldInitial()`, `formFieldChecked()` berbasis atribut, `scheduleHydration()`,
`hydrateFromServer()` di kedua komponen, penghapusan `formFieldValue()`, dan penyambungan di `init()`
kedua komponen (§ 5.2).

### T4 — Harness

1. **Perluas `tests/manual/js_form_init_check.js`** (Node + stub DOM) agar memodelkan **kedua urutan**:
   - *wipe-before*: properti dikosongkan lebih dulu (menirukan penulisan `x-model`), atribut utuh →
     `hydrateFromServer()` harus memulihkan;
   - *wipe-after*: `hydrateFromServer()` berjalan dulu, lalu penulis `x-model` mengosongkan → hook
     `$nextTick`/`alpine:initialized` harus memulihkan;
   - idempoten (dipanggil 3× hasilnya sama), tidak menimpa ketikan operator (`model` non-kosong → tetap),
     dan kasus atribut kosong (layar kasir) **tidak** mengosongkan apa pun.
   Docblock wajib menyatakan ini **model** semantik Alpine (bukan peramban), agar tidak menjadi hijau palsu lagi.
2. **Berkas baru `tests/manual/browser_form_init_check.js`** — probe peramban sungguhan (keputusan
   `dec-14827488320397ae` butir 1):
   - Node ≥ 22 tanpa dependensi: `fetch` + `WebSocket` bawaan untuk CDP;
   - meluncurkan Chrome/Chromium `--headless=new` (cadangan `--headless`) dengan
     `--remote-debugging-port=0`; kandidat biner dari `CHROME_PATH` lalu jalur umum (linux/macOS);
     bila tidak ada biner → **keluar dengan pesan skip yang jelas** (kondisi mesin ini);
   - masuk sebagai operator memakai kredensial `.env` (`OPERATOR_PHONE`/`OPERATOR_PASSWORD`), lalu buka
     `/operator/settlement`; bila batch *holding* kosong (form tidak dirender) → laporkan sebagai skip;
   - memeriksa: `.value` vs `getAttribute('value')` untuk `sold_grams` & `coord_fee`, nilai model via
     `Alpine.$data(section)`, error konsol (`Runtime.exceptionThrown` + `Log.entryAdded`),
     status `disabled` tombol submit, `Alpine.version`, `sha256` `/assets/js/app.js` yang benar-benar
     dimuat, dan ukuran `FormData` (tanpa submit, tanpa tulisan ke basis data);
   - mencetak laporan PASS/FAIL dan `exit 1` bila ada field ter-wipe.

### T5 — QC & aset

Static assertions baru (memakai pemindai lokal `qc3_scan()`/`qc2_scan()`, tetap PHP-only):

| ID | Suite | Yang dijaga |
|---|---|---|
| `H.18` | qc_fase_3 | Setiap isian bernilai awal punya `data-af-initial` dengan **ekspresi PHP yang sama** dengan `value=` (tidak ada sumber kedua), dan `x-model.fill` masih ada |
| `H.19` | qc_fase_3 | `app.js` memuat `formFieldInitial` + `hydrateFromServer` + `scheduleHydration` + hook `alpine:initialized`; `formFieldValue` (pembaca properti) **tidak** ada lagi |
| `H.20` | qc_fase_3 | Ketiga layout memakai `App::asset(` untuk CSS/JS (URL ber-versi) |
| `H.13` | qc_fase_2 | Layar kasir: `data-af-initial` ada pada dua isian angka + kotak centang jasa bakar, `x-model.fill` masih ada |

Hapus juga instrumen T1. Catat jumlah assertion baru (baseline sekarang **355**) di ADDENDUM.

### T6 — Matriks verifikasi (semua wajib)

| Skenario | Harapan |
|---|---|
| Profil bersih / incognito | `sold_grams` = `18,050`, `coord_fee` = `200.000` (nilai nyata) |
| Reload setelah `422` | Isian operator (termasuk alokasi manual) kembali terisi |
| Chrome mengembalikan ketikan lama (layar kasir) | Tidak dikosongkan oleh hidrasi (atribut kosong → dilewati) |
| Hard reload (Ctrl+Shift+R) | Sama seperti profil bersih |
| JavaScript mati | Form tetap benar (kontrak tanpa JS) |
| Layar kasir | Berat/harga kosong sesuai desain; toggle jasa bakar sesuai `checked` peladen |
| Pratinjau | Terhitung tanpa mengetik ulang berat |
| `bash tests/run_qc.sh` + probe Node + probe CDP | Hijau; `FormData` penuh (bukan ± 357 byte) |

### T7 — Dokumentasi

ADDENDUM dokumen ini (bukti T0 + hasil verifikasi), rujukan silang dari
`plan/Plan_Fix_Settlement_UI.md` dan `plan/Summary_Fix_Settlement_UI.md`, butir kontrak di `AGENTS.md`
menjadi *"nilai awal dibaca dari **atribut** dan diterapkan **setelah** pass Alpine"*, dan perintah
probe CDP di `README.md`/`AGENTS.md`.

---

## 7. Risiko & mitigasi

| # | Risiko | Mitigasi |
|---|---|---|
| D-1 | Aturan "hanya mengisi, tidak mengosongkan" membiarkan ketikan lama hasil restorasi Chrome di layar kasir | Disengaja: mengetik operator tidak boleh dihancurkan; jalur `422` tetap membawa kebenaran peladen melalui atribut |
| D-2 | Tiga titik hidrasi berpotensi bertabrakan dengan ketikan operator pada jendela mikrotask | Penjaga "model non-kosong → lewati"; hidrasi hanya menulis bila model kosong |
| D-3 | `alpine:initialized` hanya dipancarkan sekali per dokumen | Komponen yang dibuat belakangan tetap ditangani `$nextTick`; dicatat di komentar kode |
| D-4 | QC statis tidak dapat membuktikan perilaku peramban | Karena itu ada probe CDP; di mesin tanpa browser, verifikasi peramban tetap checklist manual dan itu dinyatakan apa adanya |
| D-5 | Aset stale menyamar sebagai bug kode (H3) | Cache-busting `?v=filemtime` (T5) + verifikasi `sha256` di probe (T4) |
| D-6 | Menambah `?v=` memecah asumsi QC/aset | `qc_routing` mengambil aset per-path; `tests/server_router.php` memakai `parse_url(…, PHP_URL_PATH)` — keduanya sudah aman; diverifikasi ulang di T5 |
| D-7 | Salah hipotesis (mis. H5) membuat perbaikan sia-sia | T0 wajib menghasilkan veredikt berbasis bukti sebelum T2 ditulis |

---

## 8. Kriteria selesai

- [ ] T0 menghasilkan tabel bukti yang memutuskan H1–H5, termasuk **properti vs atribut** (dengan kontrol
      incognito & hard reload) — dicatat di ADDENDUM.
- [ ] Di peramban nyata: `sold_grams` & `coord_fee` terisi setelah muat, pratinjau hidup tanpa mengetik
      ulang, tombol submit aktif setelah nota dipilih, dan `422` mempertahankan isian operator.
- [ ] Payload submit berisi nilai (bukan ± 357 byte).
- [ ] `hydrateFromServer()` terbukti memulihkan **kedua** urutan pada harness Node (wipe-before & wipe-after),
      idempoten, dan tidak menimpa ketikan operator.
- [ ] Probe CDP tersedia dan lulus (atau jalur skip-nya jelas bila tidak ada browser).
- [ ] `bash tests/run_qc.sh` exit 0 tanpa fail; assertion baru § T5 tercatat; tanpa `storage/logs/php-*.log` baru.
- [ ] Jalur tanpa JavaScript tidak berubah; peladen tetap tidak mengarang nilai.
- [ ] Instrumen T1 sudah dihapus; `formFieldValue()` hilang (tanpa kode mati); `git status` bersih dari artefak.

---

## 9. Item terbuka

| # | Item | Catatan |
|---|---|---|
| **P-1** | Veredikt H1 vs H2 | Hanya T0 yang dapat memutuskan; keduanya ditangani perbaikan § 5 tanpa perubahan desain |
| **P-2** | Chrome headless vs headful untuk probe | Default `--headless=new`; bila restorasi state justru khas mode headful, probe menerima flag `--headful` (dijalankan pengguna) |
| **P-3** | Apakah cache-busting cukup tanpa `Cache-Control` eksplisit | Bila hosting menambah `expires` panjang, `?v=filemtime` tetap mengubah URL sehingga aset baru terambil |
| **P-4** | Automation peramban di gerbang QC | Ditolak untuk sekarang (`dec-14827488320397ae` butir 1 = manual); ditinjau kembali bila mesin QC punya browser |

---

## 10. Lampiran — kutipan sumber & perintah

```bash
# QC (gerbang)
bash tests/run_qc.sh                       # harapan: 9 suite, 0 gagal, exit 0

# Probe Node (model siklus hidup Alpine; selalu bisa dijalankan)
node tests/manual/js_form_init_check.js

# Probe peramban nyata (butuh Chrome/Chromium di mesin pengguna)
node tests/manual/browser_form_init_check.js

# Kontrol manual cepat di konsol peramban (T0)
document.querySelector('[name="sold_grams"]').value            # properti (bisa sudah di-wipe)
document.querySelector('[name="sold_grams"]').getAttribute('value')   # atribut (tahan wipe)
```

Kutipan bundel Alpine 3.17.4 yang menjadi dasar § 2 (verbatim, dari `public/assets/js/alpine.min.js`):

```text
p("model",(e,{modifiers:t,expression:r},{effect:n,cleanup:i})=>{ … if(t.includes("fill")&&([void 0,null,""]
  .includes(c())||De(e)&&Array.isArray(c())||e.tagName.toLowerCase()==="select"&&e.multiple)
  &&l(gt(e,t,{target:e},c()))) … e._x_forceModelUpdate=d=>{…:Ce(e,"value",d)…}
  … n(()=>{let d=c();t.includes("unintrusive")&&document.activeElement.isSameNode(e)||e._x_forceModelUpdate(d)})})
function Li(e,t){if(nt(e))…;else if(De(e))…;else{if(e.value===t&&(typeof t!="object"||t===null))return;
  e.value=t===void 0?"":t}}
function gt(e,t,r,n){…else{let i;return nt(e)?…:i=r.target.value,…}}
te(document,"alpine:initialized")
b("nextTick",()=>_e)
```

---

# ADDENDUM — Catatan Eksekusi

*(Diisi setelah T0–T7 dijalankan; sebelum itu kosong.)*

| Tanggal | Tahap | Hasil / perubahan | Alasan |
|---|---|---|---|
| — | — | — | — |
