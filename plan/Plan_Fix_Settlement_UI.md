# Plan Perbaikan — UX & Pengiriman Form Layar Rekonsiliasi (Settlement)

> **Dokumen Perencanaan Perbaikan**
> Proyek: **AurumFlow**
> Cakupan: layar rekonsiliasi Fase 3 (`/operator/settlement`) + komponen Alpine `settlementForm`,
> dan cacat sekeluarga pada layar kasir Fase 2 (`/operator/teller`) + komponen `tellerForm`.
> Status: `EXECUTED & VERIFIED` — lihat ADDENDUM di bawah; gate **9 suite · 355 assertion · 0 gagal**.
> Ditemukan saat uji manual Fase 3 (peladen menolak form dengan galat "wajib diisi").

| Item | Nilai |
|---|---|
| Peran dokumen ini | Perbaikan cacat UX + bug pengiriman form. **Bukan** fase baru; tidak menyentuh DDL, tidak menyentuh jalur uang |
| Sumber kebenaran | ERD → SAD → Roadmap → PRD → UI/UX (Layar C); lalu `plan/Plan_Fase_2_AurumFlow.md` & `plan/Plan_Fase_3_AurumFlow.md` |
| Berkas yang akan diubah | `app/Views/operator/settlement.php`, `app/Views/operator/teller.php`, `public/assets/js/app.js`, `tests/qc_fase_3.php`, `tests/qc_fase_2.php`, `docs/AurumFlow UI UX Documents.md`, ADDENDUM `plan/Plan_Fase_3_AurumFlow.md` · **saat eksekusi ditambah:** `tests/run_qc.sh`, `tests/manual/js_form_init_check.js`, `AGENTS.md`, `README.md` (lihat ADDENDUM V-7 & F-1) |
| Tidak diubah | `database/schema.sql`, `database/seed.sql`, ERD, `SettlementService`, `TellerService`, `SettlementController::readInput()`, `system_settings` |
| Keputusan pengguna | `dec-6a9126c94bc6beb8` (lihat §4) |
| Bahasa dokumen | Prosa Indonesia + istilah teknis Inggris (konvensi `Plan_Fase_*.md`) |

---

## 1. Ringkasan & diagnosis

### 1.1 Gejala yang dilaporkan

Operator mengisi layar rekonsiliasi, menekan **Eksekusi Settlement Harian**, lalu peladen
menolak dengan galat validasi "wajib diisi" untuk field berat, kadar, dan nominal — padahal
di layar field-field itu **terlihat sudah terisi**.

### 1.2 Diagnosis: tiga cacat, satu keluarga

| # | Cacat | Sifat | Akibat yang terlihat operator |
|---|---|---|---|
| **A** | **Ilusi placeholder** — `density_pct`, `shop_price_per_g`, `kas_split`, `brankas_split` memakai `placeholder` yang menyerupai data nyata (`93,50`, `2.080.000`, `35.157.500`, `2.186.500`), sementara `value`-nya kosong | UX / kontrak form | Operator menyangka sudah terisi, menekan tombol, dan mengirim string kosong → `422` "wajib diisi" |
| **B** | **`$refs` dibaca pada `init()`** — `settlementForm.init()` mengambil nilai awal lewat `this.$refs.form`, padahal Alpine mendaftarkan `x-ref` **setelah** `init()` komponen induk berjalan | Bug fungsional | Nilai yang sudah dirender peladen (`value="18,050"`, `value="200.000"`) **dihapus di peramban** oleh `x-model`; pratinjau kalkulasi terkunci di `Rp 0`; tombol submit `disabled`; isian yang diketik **hilang setelah `422`** |
| **C** | **`restoreAutoSplit(true)` pada `init()`** — memaksa saran sistem menimpa isian alokasi | Akan terlihat setelah B diperbaiki | Alokasi manual operator yang dikembalikan peladen pada `422` akan ditimpa saran sistem tanpa pemberitahuan |

Cacat **B** tidak akan terlihat dari luar HTTP: `x-model` mengubah **properti** `input.value`
di peramban, sedangkan **atribut** `value="…"` tetap utuh di HTML. Karena seluruh QC dan
verifikasi manual Fase 2/3 memeriksa HTML/kontrak peladen (§2.5), cacat ini lolos sampai
diuji manusia di peramban.

---

## 2. Temuan terverifikasi (bukti berkas:baris)

### 2.1 Yang **sudah** benar — dua koreksi atas asumsi laporan

| Asumsi di laporan | Kenyataan di repositori |
|---|---|
| "Input memakai `type="number"` sehingga peramban menghapus koma" | **Tidak terjadi.** Seluruh enam input layar rekonsiliasi sudah `type="text"` + `inputmode` + `autocomplete="off"` (`app/Views/operator/settlement.php:127-138`, `:148-159`, `:164-175`, `:181-192`, `:286-297`, `:305-316`). Tidak ada satu pun `type="number"` di `app/Views/` (verifikasi: `grep -rn 'type="number"' app/Views` → kosong). Ini **dikunci** dengan pemindaian QC baru (§6), bukan diubah |
| "Pastikan atribut `name` cocok dengan `total_dry_grams`, `shop_water_density_pct`, `coordination_fee`" | **Sudah cocok dengan kunci yang benar.** View `name=` ↔ `SettlementController::readInput()` (`:277-287`) ↔ `defaultInput()` (`:258-270`) ↔ `SettlementService::quote()` (`:106-135`) memakai `sold_grams`, `density_pct`, `shop_price_per_g`, `coord_fee`, `kas_split`, `brankas_split`. Nama yang dikutip laporan adalah **kolom** `daily_settlements` (`database/schema.sql:149-155`) — lapisan basis data, bukan nama field form. Mengganti nama field berarti mengubah controller + service + QC tanpa manfaat → **tidak dilakukan**; sebagai gantinya ditambah assertion paritas kunci lintas lapis (§6) dan tabel pemetaan (§3) |

Catatan tambahan: `coord_fee` yang kosong **sah** (`SettlementService::parseMoneyInput()` mengembalikan `0`
untuk string kosong — `:659-663`), jadi field itu tidak pernah menjadi sumber galat "wajib diisi".

### 2.2 Cacat A — ilusi placeholder (terverifikasi)

`app/Views/operator/settlement.php:39-44` menyalurkan `$input` dari controller; untuk `density_pct`
dan `shop_price_per_g`, `SettlementController::defaultInput()` (`:262-269`) memang mengirim string kosong.
Placeholder-nya diambil apa adanya dari wireframe `docs/AurumFlow UI UX Documents.md:133-176`
(Layar C: `93.50 %`, `Rp 2.085.000`, `Rp 200.000`).

Bila field itu dikirim kosong, peladen menolak dengan pesan yang persis dilaporkan:

| Field | Pesan peladen saat kosong | Lokasi |
|---|---|---|
| `sold_grams` | `Total berat dijual ke toko wajib diisi. Contoh: 18,050` | `app/Services/SettlementService.php:121` |
| `shop_price_per_g` | `Harga jual riil toko per gram wajib diisi. Contoh: 2.080.000` | `app/Services/SettlementService.php:125` |
| `density_pct` | `Kadar timbang air toko wajib diisi. Contoh: 93,50` | `app/Services/SettlementService.php:737` |
| `coord_fee` | *(kosong = 0, tidak pernah galat)* | `app/Services/SettlementService.php:659-663` |
| `kas_split` + `brankas_split` | *(dua-duanya kosong = "pakai saran sistem", lihat `:136`)* | `app/Services/SettlementService.php:136` |

`placeholder` di tema "Obsidian Gold" dirender `placeholder:text-muted/50`
(`resources/css/tailwind.input.css:141`) — abu-abu di atas latar gelap, cukup menyerupai isian nyata
di layar operator. Untuk `sold_grams` hal ini diperparah karena `placeholder="18,050"`
**sama dengan nilai pre-fill sebenarnya**, sehingga operator tidak punya cara membedakan keduanya.

### 2.3 Cacat B — `$refs` dibaca pada `init()` (terverifikasi terhadap Alpine 3.17.4 yang di-vendor)

Rantai kejadian di peramban:

1. `settlementForm.init()` (`public/assets/js/app.js:718-725`) memanggil `this.inputValue(name)`,
   dan `inputValue()` (`:727-734`) membaca `this.$refs.form.elements.namedItem(name)`.
2. Alpine 3.17.4 (`public/assets/js/alpine.min.js`, `version:"3.17.4"`) menelusuri DOM secara
   **pre-order**: `function L(e,t){…if(t(e,()=>r=!0),r)return;let n=e.firstElementChild;for(;n;)L(n,t,!1),n=n.nextElementSibling}`
   → callback untuk elemen induk berjalan **sebelum** anak-anaknya.
3. Handler `x-data` menjalankan `init()` **secara sinkron**: `p("data",(e,{expression:t},{cleanup:r})=>{…let c=P(e,a);w(()=>{a.init&&D(e,a.init)})(),…})`.
4. `x-ref` baru mendaftarkan dirinya ke root `x-data` terdekat saat **elemennya** di-handle (yaitu sesudahnya):
   `si.inline=(e,{expression:t},{cleanup:r})=>{let n=re(e);n&&(n._x_refs||(n._x_refs={}),n._x_refs[t]=e,r(()=>delete n._x_refs[t]))}`.
   Magic `$refs` sendiri mememo-proxy `_x_refs` pada akses pertama:
   `b("refs",e=>(e._x_refs_proxy||(e._x_refs_proxy=I(vo(e))),e._x_refs_proxy))`.

   ⇒ saat `init()` berjalan, `this.$refs.form` **`undefined`** (form baru mendaftarkan ref-nya belakangan),
   sehingga `inputValue()` mengembalikan `''` untuk **keenam** field dan seluruh model Alpine mulai dari `''`.
5. Setiap `x-model` kemudian memaksa elemen mengikuti model. Efek pertama pada directive:
   `n(()=>{let d=c();t.includes("unintrusive")&&document.activeElement.isSameNode(e)||e._x_forceModelUpdate(d)})`
   → `_x_forceModelUpdate('')` → `Ce(e,"value",'')` → `Li()`:
   `function Li(e,t){if(nt(e))e.attributes.value===void 0&&(e.value=t);else if(De(e))…;else{…e.value=t===void 0?"":t}}`
   (`nt` = radio, `De` = checkbox) — untuk **input teks** cabang terakhir berjalan: `el.value = ''`.

Konsekuensi di peramban (inilah yang dialami operator):

- `Total Berat Dijual` yang sudah dirender peladen sebagai `value="18,050"` **menjadi kosong**, lalu
  yang tampil hanyalah placeholder `18,050` — operator melihat "angka yang sama" padahal `value`-nya `""`.
  Ini pula yang membuat galat "berat … wajib diisi" mungkin muncul (field itu benar-benar kosong saat dikirim).
- Pratinjau `x-text="revenueLabel"` / `netLabel` / `totalCashLabel` terkunci di `Rp 0` karena
  `soldMilligrams`/`shopPriceCents` = 0 (`app.js:736-776`).
- `canSubmit` (`app.js:850-856`) bernilai `false` karena `inputValid` (`:838-848`) mensyaratkan
  `soldMilligrams > 0 && shopPriceCents > 0 && density > 0` → tombol
  `:disabled="!canSubmit"` (`settlement.php:341-347`) **mati**, sehingga operator juga tidak mendapat
  umpan balik apa pun tentang apa yang kurang.
- Jalur `422` "isian terpelihara" (yang dijaga `SettlementController::renderSettlement()` dengan
  mengembalikan `$input`, `:241`) **tidak sampai ke mata operator**: nilai yang dikembalikan peladen
  kembali dihapus pada muat berikutnya, sehingga operator harus mengetik ulang tanpa tahu kesalahannya.

### 2.4 Cacat C — `restoreAutoSplit(true)` pada `init()`

`init()` (`app.js:724`) memanggil `restoreAutoSplit(true)`; parameter `force = true`
mem-bypass penjaga "hanya bila kosong" (`app.js:907-913`). Selama Cacat B belum diperbaiki, efek ini
tersamar (isian selalu kosong). **Setelah** B diperbaiki, isian alokasi hasil `422` (mis. operator
sengaja menaruh `Rp 30.000.000` ke meja) akan langsung ditimpa saran sistem — regresi senyap pada
keputusan operator. Karena itu B dan C wajib diperbaiki bersama.

### 2.5 Mengapa QC & verifikasi manual sebelumnya tidak menangkapnya

| Lapis verifikasi | Yang diperiksa | Mengapa lolos |
|---|---|---|
| QC PHP (`tests/qc_fase_3.php` G.1 `:809-822`, H.11 `:1753-1764`) | HTML yang dikirim peladen: `18,050 gr`, `enctype`, `x-text="netLabel"`, `<noscript>` | Atribut `value="18,050"` **memang ada** di HTML; yang berubah hanya properti DOM di peramban |
| QC HTTP `qc3_settle()` (`:240`) | POST langsung via `QcHttp` (tanpa JS) | Tanpa JS, field terisi dan valid |
| Verifikasi manual Fase 3 (`Summary_Fase_3 §3.3`) | Komponen `settlementForm` dijalankan dengan **Node + stub DOM**, memanggil metode/getter secara langsung | Stub DOM tidak menirukan urutan directive Alpine, jadi `$refs` selalu "ada" dan tidak ada `x-model` yang menghapus nilai |
| Verifikasi manual Fase 2 (`Summary_Fase_2 §3.3`) | "status toggle jasa bakar dibaca dari atribut `checked` (render server)" | Benar hanya untuk jalur **tanpa JS**; jalur ber-JS kehilangan status itu (lihat Cacat B pada `tellerForm`) |

Karena browser driver tidak tersedia di lingkungan ini (tidak ada chromium/geckodriver/playwright/jsdom),
lapis peramban tetap berupa **checklist manual** — mengikuti keputusan yang sudah berlaku di Fase 2/3
("verifikasi JS/peramban tidak masuk `run_qc.sh`").

### 2.6 Cacat sekeluarga di layar kasir Fase 2 (dalam cakupan, lihat §4)

`tellerForm.init()` (`app.js:290-294`) memakai pola identik: `this.$refs.weight`, `this.$refs.price`,
`this.$refs.burnFee` — semuanya dibaca pada `init()`. Pada layar kasir, efeknya tidak muncul saat
muat pertama (nilai awal memang kosong, operator mengetik), tetapi **muncul pada `422`**:
`TellerController` mengembalikan isian melalui `'weight' => $input['weight']`
(`app/Controllers/TellerController.php:93-95`) dan atribut `checked` kotak centang
(`app/Views/operator/teller.php:120-130`), lalu keduanya dihapus di peramban — isian yang diketik
hilang **dan** toggle "Potong jasa bakar flat" balik ke `checked` (karena `applyFee` jatuh ke nilai
default `true`), sehingga sekali `422` dapat mengubah arti transaksi kas tanpa disadari operator.
Klaim `Summary_Fase_2` baris 217/O-3 ("Tanpa JS, status toggle dari server tetap benar setelah 422")
tetap benar untuk jalur tanpa JS, tetapi tidak lagi menyesatkan bila jalur ber-JS diperbaiki.

---

## 3. Kontrak form (field ↔ kolom DB ↔ parser)

Menjawab kebutuhan "nama field harus cocok": kunci form **adalah** kontrak antara view, controller,
dan service. Kolom basis data adalah lapisan lain; pemetaannya dicatat di sini, bukan dengan mengganti nama.

| `name` (form) | `value` saat GET pertama | `placeholder` baru | `inputmode` | Kolom basis data | Parser |
|---|---|---|---|---|---|
| `sold_grams` | `gramFieldValue(holdingTotals.grams)` → `18,050` | **dihapus** (nilai nyata selalu ada; ada teks bantuan "Maksimal …" di bawahnya) | `decimal` | `daily_settlements.total_dry_grams` | `TellerService::parseWeightInput()` |
| `density_pct` | `''` — wajib diisi operator (fakta dari nota toko) | `mis. 93,50` | `decimal` | `daily_settlements.shop_water_density_pct` | `SettlementService::parseDensity()` |
| `shop_price_per_g` | `''` — wajib diisi operator (fakta dari nota toko) | `mis. 2.080.000` | `numeric` | `daily_settlements.shop_price_per_g` | `TellerService::parsePriceInput()` |
| `coord_fee` | `SettlementController::COORD_FEE_FIELD` → `200.000` | `mis. 200.000` | `numeric` | `daily_settlements.daily_coordination_fee` | `SettlementService::parseMoneyInput()` |
| `kas_split` | `''` = "pakai saran sistem" (kontrak tanpa JavaScript, `Plan_Fase_3 §4.4`) | `mis. 35.157.500` | `numeric` | — (hanya batas jumlah, dialokasikan ke `cash_mutations`) | `SettlementService::parseMoneyInput()` |
| `brankas_split` | `''` = "pakai saran sistem" | `mis. 2.186.500` | `numeric` | — (idem) | `SettlementService::parseMoneyInput()` |

Aturan konvensi angka tetap milik `Plan_Fase_3 §4.2`: satu pemisah = desimal untuk berat & kadar,
titik = ribuan untuk nominal. `gramFieldValue()` (`SettlementController.php:362-374`) sudah menulis
ulang `18.050` (dari DB) menjadi `18,050` — format yang dibaca `AurumInput.weightMilligrams()`
(`app.js:101-134`) dan diterima `TellerService::parseWeightInput()`. **Tidak ada perubahan format.**

---

## 4. Keputusan yang dikunci (hasil `ask`, `dec-6a9126c94bc6beb8`)

| # | Pertanyaan | Pilihan yang dikunci |
|---|---|---|
| 1 | Pre-fill untuk field yang nilainya tidak diketahui peladen (`density_pct`, `shop_price_per_g`) | **A — hanya pre-fill yang diketahui peladen; sisanya dihilangkan ilusinya.** `sold_grams` = gram holding, `coord_fee` = default PRD. `density_pct`/`shop_price_per_g` tetap kosong tetapi placeholder menjadi contoh yang tak terbaca sebagai data (`mis. …`) + penanda "wajib diisi". **Tidak** menambahkan `system_settings` baru, **tidak** mengisi angka karangan |
| 2 | Cakupan perbaikan bug `$refs`-pada-`init()` | **A — perbaiki `settlementForm` dan `tellerForm`** (§2.6) |
| 3 | Bahasa dokumen | **Indonesia**, mengikuti konvensi `plan/Plan_Fase_*.md` |

Konsekuensi yang perlu disadari: setelah perbaikan, operator **tetap harus mengetik** kadar dan harga
jual tokonya setiap hari — memang begitu kontraknya (`defaultInput()` sengaja mengosongkannya).
Yang berubah: sekarang jelas bahwa field itu memang kosong, dan peladen tidak lagi menerima kiriman
kosong secara membingungkan.

---

## 5. Rencana perubahan per berkas

### 5.1 `app/Views/operator/settlement.php`

1. `sold_grams` (`:127-138`): pertahankan `type="text"`, `inputmode="decimal"`, `autocomplete="off"`,
   `value="<?= Security::e($soldValue) ?>"` (sudah benar — pre-fill gram holding). **Hapus** `placeholder="18,050"`
   (nilai nyata ada; placeholder hanya meninggalkan ilusi bila field dikosongkan operator).
2. `density_pct` (`:148-159`): `value` tetap `$densityValue` (kosong pada GET), `placeholder` → `mis. 93,50`;
   tambahkan penanda wajib pada label (`Kadar Timbang Air Toko (%) <span class="…">wajib</span>`).
   **Jangan** menambah atribut `required` HTML5 — form memakai `novalidate` (`:117`), jadi atribut itu
   akan mati dan menyesatkan; kontraknya tetap teks + validasi peladen.
3. `shop_price_per_g` (`:164-175`): `placeholder` → `mis. 2.080.000`; penanda wajib pada label; sisanya tidak berubah.
4. `coord_fee` (`:181-192`): `value="<?= Security::e($coordValue) ?>"` sudah pre-fill `200.000`; `placeholder` → `mis. 200.000`
   agar konsisten dengan aturan pemindaian QC (§6).
5. `kas_split` (`:286-297`) & `brankas_split` (`:305-316`): `value` **tetap kosong** pada GET
   (kontrak "kosong = saran sistem", `Plan_Fase_3 §4.4`) dan tetap diisi ulang dari `$input` pada `422`;
   `placeholder` → `mis. 35.157.500` / `mis. 2.186.500`. Teks bantuan "Saran sistem: …" di bawah tiap field tetap.
6. Tidak ada perubahan pada CSRF/`_once`, `enctype`, `capture="environment"`, blok `<noscript>`, atau pratinjau `x-text`.

### 5.2 `public/assets/js/app.js` — `settlementForm`

1. Ganti pembacaan nilai awal agar **tidak bergantung waktu pendaftaran `x-ref`**:
   `initialValue(name)` melakukan `querySelector('[name="…"]')` pada root komponen (`this.$root || this.$el`),
   dengan cadangan `this.$refs.form` / `form.elements.namedItem(name)`. Nama field di-query dari DOM,
   bukan dari string yang membawa data pengguna (tetap aman).
2. `init()` mempertahankan urutan baca (empat field) lalu `restoreAutoSplit(false)` — **bukan** `true` —
   supaya isian alokasi hasil `422` tidak ditimpa saran sistem (Cacat C). Tombol "Saran otomatis"
   (`settlement.php:273-280`) tetap memakai `restoreAutoSplit(true)`.
3. Pengerasan deklaratif: tambahkan modifier `.fill` pada keenam `x-model` (`x-model.fill="…"`).
   Semantik Alpine 3.17.4: `if(t.includes("fill")&&([void 0,null,""].includes(c())||…))l(gt(e,t,{target:e},c()))` —
   model mengambil nilai elemen bila model masih kosong, dan blok ini dievaluasi **sebelum** efek yang
   memaksa elemen mengikuti model. Jadi bila `init()` gagal mengenali DOM karena alasan apa pun,
   `x-model` tidak akan mengosongkan isian peladen. (Perilaku normal tidak berubah: nilai model dan DOM sama.)
4. `onSubmit()`, `onReceiptChange()`, kompresi Canvas, dan seluruh getter kalkulasi **tidak diubah**.
5. Komponen `tellerForm` **tidak** memakai `settlementForm`; tetap terpisah (lihat §5.3).

### 5.3 `public/assets/js/app.js` — `tellerForm` (Fase 2, keputusan §4 butir 2 = A)

1. `init()` (`:290-294`) membaca `weight`, `price`, dan `burnFee.checked` lewat helper pembacaan DOM
   yang sama (`$root.querySelector('[name="dry_weight"]')`, `'[name="buy_price_per_g"]'`,
   `'[name="burn_fee_enabled"]'`) dengan cadangan `$refs`.
2. Tambahkan `.fill` pada `x-model` berat & harga di `app/Views/operator/teller.php:86-98` dan `:104-118`
   (kotak centang tidak perlu `.fill`; statusnya dibaca dari `checked`).
3. De-ilusi placeholder layar kasir (temuan §2.2 untuk view kedua): `:94` → `mis. 14,850`, `:114` → `mis. 1.950.000`
   (placeholder `"P-04 / Anto"` pada inisial petani tidak diubah — bukan angka).
4. Tidak ada perubahan pada `TellerService`, `TellerController`, atau jalur kas.

### 5.4 Yang **tidak** diubah (dan alasannya)

| Tidak diubah | Alasan |
|---|---|
| `SettlementController::readInput()` / `defaultInput()` / kunci `name` field | Sudah konsisten lintas lapis; mengganti nama hanya menambah risiko tanpa manfaat (§2.1) |
| `SettlementService`, `TellerService`, aturan parsing & pembulatan | Cacat ada di lapis tampilan/pengiriman; jalur uang tidak boleh disentuh dalam perbaikan UX |
| `database/schema.sql`, `database/seed.sql`, `system_settings` | Keputusan §4 butir 1 = A: tanpa kunci parameter baru, tanpa DDL. `O-2` (`Plan_Fase_3 §9`) tetap terbuka |
| Validasi "wajib diisi" peladen | Justru **pertahanan** yang membuat bug ini terdeteksi; perbaikan tidak melemahkannya |

---

## 6. QC tambahan

Prinsip: gunakan pemindai lokal `qc3_scan()` (`tests/qc_fase_3.php:71`) / `qc2_scan()`
(`tests/qc_fase_2.php:68`) — keduanya melewati baris komentar (risiko R-9 `Plan_Fase_3 §7`).
Semua assertion baru bersifat **statis** atau **HTTP**, sehingga gerbang tetap PHP-only.

### 6.1 `tests/qc_fase_3.php`

| ID | Assertion | Cara |
|---|---|---|
| H.13 | Tidak ada `type="number"` di `app/Views/operator/` (dan khususnya layar rekonsiliasi) — penjaga permanen atas Cacat "number trap" | `qc3_scan()` pada view operator |
| H.14 | Keenam input bernama (`sold_grams`, `density_pct`, `shop_price_per_g`, `coord_fee`, `kas_split`, `brankas_split`) memakai `type="text"` + `inputmode` + `autocomplete="off"` | Regex per field pada isi `settlement.php` |
| H.15 | Setiap `placeholder` numerik memakai awalan contoh (`mis. `), dan `sold_grams` tidak lagi memakai placeholder numerik | Ekstraksi `placeholder="…"` pada view operator |
| H.16 | **Paritas kunci lintas lapis**: himpunan `name="…"` pada view == himpunan kunci `self::post('…')` pada `SettlementController::readInput()` | `preg_match_all()` pada kedua berkas |
| H.17 | `init()` `settlementForm` tidak membaca nilai awal lewat `$refs` (wajib melewati `querySelector`), dan `restoreAutoSplit(true)` hanya muncul pada tombol — bukan pada `init()` | Pemindaian blok komponen pada `app.js` |
| G.2 (di § G) | `GET /operator/settlement` merender nilai pre-fill yang benar: `body` memuat `value="18,050"` pada `sold_grams` **dan** `value="200.000"` pada `coord_fee` | `qc3_tokens($operatorJar)` (`:807`) — sudah tersedia; assertion baru di sebelah G.1 |

### 6.2 `tests/qc_fase_2.php`

| ID | Assertion |
|---|---|
| H.10 | Layar kasir: tidak ada `type="number"` (pemindai `qc2_scan()`) |
| H.11 | `name="dry_weight"`/`name="buy_price_per_g"` memakai `type="text"` + `inputmode` + `autocomplete="off"` + `x-model.fill` |
| H.12 | `tellerForm.init()` membaca `weight`/`price`/`burnFee` lewat DOM (tanpa `$refs`) |
| G.3a | `GET /operator/teller` merender `checked` pada `burn_fee_enabled` dan membiarkan berat/harga kosong (jalur tanpa JavaScript tetap utuh) |

### 6.3 Baseline

Baseline sebelum perbaikan: **9 suite, 345 assertion, 0 gagal** (`AGENTS.md`). Perbaikan ini
menambah **10 assertion** — `qc_fase_3` 91 → **97** (+`G.1a`, +`H.13`–`H.17`) dan
`qc_fase_2` 61 → **65** (+`G.3a`, +`H.10`–`H.12`) — sehingga gate menjadi
**9 suite · 355 assertion · 0 gagal**. Tidak ada suite baru dan tidak ada perubahan pada
`SUITES` di `tests/run_qc.sh`.

---

## 7. Verifikasi (reproduksi → perbaikan)

### 7.1 Reproduksi sebelum perbaikan (wajib, untuk membuktikan diagnosis)

```bash
php -S 127.0.0.1:8000 -t public tests/server_router.php
# login operator -> /operator/settlement
```

1. Buka DevTools: `document.querySelector('[name="sold_grams"]').value` → **`''`**, sementara
   `getAttribute('value')` → `"18,050"`. Ini membuktikan Cacat B (atribut utuh, properti terhapus).
2. Panel pratinjau: `Kas masuk toko`/`Laba bersih putaran` tetap `Rp 0` tanpa interaksi operator.
3. Tombol "Eksekusi Settlement Harian" dalam keadaan `disabled` sebelum foto nota dipilih → bukti
   `canSubmit` gagal karena `soldMilligrams`/`shopPriceCents` = 0.
4. Nonaktifkan JavaScript (atau blokir `public/assets/js/alpine.min.js`), lalu submit: peladen menolak
   dengan pesan "wajib diisi" untuk kadar & harga — mereproduksi keluhan operator dan menegaskan
   bahwa Cacat A berdiri sendiri, terlepas dari Cacat B.

Jika langkah 1 ternyata **tidak** menunjukkan properti kosong, hentikan dan tinjau ulang §2.3 sebelum
mengubah kode: perbaikan §5 tetap benar, tetapi narasi diagnosis (dan beratnya cakupan) perlu direvisi.

### 7.2 Verifikasi setelah perbaikan (peramban, manual)

| # | Langkah | Hasil yang diharapkan |
|---|---|---|
| 1 | Muat `/operator/settlement` | `Total Berat Dijual` = `18,050` (nilai nyata, bukan placeholder); `Potongan Koordinasi` = `200.000` |
| 2 | Tanpa mengetik apa pun | Pratinjau menampilkan `Rp 0` **hanya** karena kadar & harga memang belum diisi; setelah `density_pct` & `shop_price_per_g` diisi, seluruh angka (`Kas masuk toko`, `Laba kotor`, `Laba bersih putaran`, 80 : 20, alokasi saran) **langsung** terisi tanpa mengetik ulang field berat |
| 3 | Perhatikan placeholder | Tidak ada satu pun placeholder yang terbaca sebagai data (`mis. 93,50`) |
| 4 | Pilih foto nota, lalu submit | 302 + flash sukses; nilai tersimpan identik dengan hasil hitung peladen |
| 5 | Sengaja kosongkan `density_pct`, submit, isi setelah `422` | Pesan galat muncul, nilai lain (termasuk berat) **tetap terisi** setelah muat ulang — bukti jalur `422` terpelihara |
| 6 | Ubah alokasi manual (`kas_split`), picu `422` (mis. tanpa foto), muat ulang | Isian alokasi manual **tidak** ditimpa saran sistem (Cacat C) |
| 7 | Ulangi langkah 1-2 pada `/operator/teller`; lalu picu `422` (mis. berat > saldo) | Isian berat/harga tetap terisi dan toggle jasa bakar tetap pada pilihan operator (§2.6) |

### 7.3 Verifikasi tanpa JavaScript

Matikan JavaScript, muat ulang layar: field terisi dari peladen, submit dengan kadar & harga terisi,
dan biarkan dua isian alokasi kosong → peladen memakai saran sistem (`Plan_Fase_3 §4.4`). Perilaku ini
**tidak boleh berubah**.

### 7.4 Gerbang penerimaan

```bash
bash tests/run_qc.sh                          # exit 0
git status --porcelain                        # hanya berkas §5 + dokumen ini
ls storage/logs/php-*.log                     # tidak ada
```

---

## 8. Risiko & mitigasi

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| F-1 | Perbaikan `.fill`/`querySelector` salah menafsirkan urutan Alpine | Isian kosong lagi setelah deploy | Langkah §7.1-1 dijalankan **sebelum** perubahan sebagai pembanding, §7.2 sebagai pembanding sesudah; `.fill` membuat perilaku benar pada kedua urutan |
| F-2 | `restoreAutoSplit(false)` di `init()` lupa diganti di tombol | Saran otomatis tak bisa dipanggil manual | Assertion H.17 memastikan `restoreAutoSplit(true)` tetap ada pada tombol, dan `init()` tidak memakainya |
| F-3 | Pemindai statis baru salah tuduh (kelas CSS/teks bantuan) | QC gagal palsu | Pakai `qc3_scan()`/`qc2_scan()` (lewati komentar) + daftar berkas eksplisit (R-9) |
| F-4 | Operator terbiasa "mengandalkan" placeholder lalu mengeluh field wajib kosong | Friksi adopsi singkat | Pelabelan "wajib" + placeholder `mis. …`; pesan galat peladen sudah menjelaskan contoh nilai |
| F-5 | Perubahan menyentuh `tellerForm` (Fase 2) di luar fase aktif | Regresi layar kasir | Perubahan hanya pada `init()` + atribut view; QC Fase 2 dijalankan penuh, tanpa perubahan `TellerService` |
| F-6 | Lapis peramban tidak terotomasi di lingkungan ini | Cacat sekeluarga bisa lolos lagi | Checklist §7.2 dicatat di `Summary_Fase_3`/`Summary_Fase_2` §3.3 (mengikuti preseden: verifikasi JS di luar `run_qc.sh`) |

---

## 9. Item terbuka

| # | Item | Status setelah perbaikan ini |
|---|---|---|
| **O-2** | Nominal beban aparat sebagai kunci `system_settings` | **Tetap terbuka** — `coord_fee` masih default tampilan dari konstanta `SettlementController::COORD_FEE_FIELD` |
| **N-1** | Default kadar/harga jual toko dari sesi sebelumnya (`daily_settlements` terakhir) | **Ditunda** (pilihan B pada `dec-6a9126c94bc6beb8` tidak diambil). Bila keluhan input berulang muncul, ini kandidat pertama; menuntut catatan di `docs/` |
| **N-2** | Verifikasi otomasi lapis peramban (Playwright/CDP) untuk urutan directive Alpine | **Ditunda** — tidak ada driver di lingkungan ini; preseden Fase 2/3 tetap dipertahankan |
| **N-3** | Pemeriksaan menyeluruh layar lain atas pola `$refs` pada `init()` | Setelah §5, `grep -n '\$refs' public/assets/js/app.js` harus bersisa hanya pemakaian di dalam *event handler* (`:355`, `:420`), bukan `init()` |

---

## 10. Kriteria selesai (Definition of Done)

- [ ] Diagnosis §2 direproduksi persis seperti §7.1 (bukti: properti `value` kosong, atribut utuh).
- [ ] `app/Views/operator/settlement.php`: keenam field `type="text"` + `inputmode`; pre-fill `sold_grams`
      (gram holding) & `coord_fee`; tidak ada placeholder yang menyerupai data.
- [ ] `app/Views/operator/teller.php`: placeholder numerik berawalan `mis. `; tidak ada `type="number"`.
- [ ] `settlementForm.init()` & `tellerForm.init()` membaca DOM tanpa bergantung pendaftaran `x-ref`;
      `.fill` terpasang; `init()` memakai `restoreAutoSplit(false)`.
- [ ] Assertion baru §6 ada dan lulus; `bash tests/run_qc.sh` **exit 0**; tanpa `storage/logs/php-*.log`.
- [ ] Checklist §7.2 & §7.3 dijalankan operator/pengembang dan hasilnya dicatat.
- [ ] `docs/AurumFlow UI UX Documents.md` (Layar C) diberi catatan bahwa angka pada wireframe adalah
      **ilustrasi**, bukan nilai awal form.
- [ ] ADDENDUM dokumen ini + `plan/Plan_Fase_3_AurumFlow.md` (dan `Plan_Fase_2` untuk bagian kasir) terisi.
- [ ] `git status` bersih; tidak ada artefak sementara; tidak ada berkas di luar daftar §5.

---

## 11. Lampiran — potongan sumber Alpine 3.17.4 yang menjadi dasar diagnosis

Seluruh kutipan diambil dari `public/assets/js/alpine.min.js` (`version:"3.17.4"`, aset lokal, tanpa CDN).

```text
# 1. Penelusuran DOM pre-order (induk sebelum anak) -> urutan inisialisasi directive
function L(e,t){if(typeof ShadowRoot=="function"&&e instanceof ShadowRoot){…}let r=!1;
  if(t(e,()=>r=!0),r)return;let n=e.firstElementChild;for(;n;)L(n,t,!1),n=n.nextElementSibling}

# 2. x-data menjalankan init() secara sinkron saat directive-nya di-handle
p("data",(e,{expression:t},{cleanup:r})=>{…let c=P(e,a);w(()=>{a.init&&D(e,a.init)})(),…})

# 3. x-ref baru mendaftar saat elemennya di-handle (yaitu SETELAH init() induk)
si.inline=(e,{expression:t},{cleanup:r})=>{let n=re(e);n&&(n._x_refs||(n._x_refs={}),
  n._x_refs[t]=e,r(()=>delete n._x_refs[t]))}

# 4. Magic $refs mememo-proxy _x_refs pada akses pertama (di sini: kosong, saat init())
b("refs",e=>(e._x_refs_proxy||(e._x_refs_proxy=I(vo(e))),e._x_refs_proxy));
function vo(e){let t=[];return A(e,r=>{r._x_refs&&t.push(r._x_refs)}),t}

# 5. Modifier .fill: model mengambil nilai elemen bila model masih kosong,
#    dievaluasi SEBELUM efek yang memaksa elemen mengikuti model
if(t.includes("fill")&&([void 0,null,""].includes(c())||…))l(gt(e,t,{target:e},c()))

# 6. Efek awal x-model memaksa elemen mengikuti model
n(()=>{let d=c();t.includes("unintrusive")&&document.activeElement.isSameNode(e)||e._x_forceModelUpdate(d)})

# 7. ...yang berujung pada pengosongan properti value untuk input teks
_x_forceModelUpdate=d=>{…h(()=>{…:Ce(e,"value",d)})}
function Ce(e,t,r,n=[]){…case"value":Li(e,r);break;…}
function Li(e,t){if(nt(e))e.attributes.value===void 0&&(e.value=t);else if(De(e))…;
  else{…e.value=t===void 0?"":t}}
```

`nt()` = radio (guard `attributes.value === void 0` hanya berlaku di sana), `De()` = checkbox,
sehingga untuk `type="text"` cabang terakhir berjalan: `e.value = t` → dengan model `''`, properti
`input.value` menjadi kosong sementara atribut `value="18,050"` tetap ada di HTML.

---

# ADDENDUM — Catatan Eksekusi

> **Status: terverifikasi (gate PHP).** Rencana di atas dieksekusi **tanpa** perubahan
> skema, seed, `system_settings`, `SettlementService`/`TellerService`, maupun aturan validasi.
> Gate akhir: **9 suite · 355 assertion · 0 gagal** (setiap suite `exit 0`),
> tanpa `storage/logs/php-*.log` baru, tanpa berkas nota sisa.
> **Satu langkah belum dapat dijalankan di lingkungan ini:** checklist peramban § 7.2
> (tidak ada chromedriver/geckodriver/Playwright) — lihat butir **V-3**.

| # | Area | Rencana | Eksekusi | Alasan |
|---|---|---|---|---|
| **V-1** | Pembacaan nilai awal `settlementForm.init()` | § 5.2: "urutan baca (empat field)" lalu `restoreAutoSplit(false)` | **Keenam** field dibaca, termasuk `kas_split` & `brankas_split` | Dengan hanya empat field, `kasInput`/`brankasInput` tetap `''` saat `restoreAutoSplit(false)` dijalankan, sehingga alokasi manual hasil `422` **tetap** ditimpa saran sistem — Cacat C tidak benar-benar tertutup. Kalimat "empat field" pada § 5.2 adalah kekeliruan rencana; tujuan yang mengikat adalah kalimat sesudahnya ("isian alokasi hasil 422 tidak ditimpa saran sistem") |
| **V-2** | Assertion `H.16` (paritas kunci) | § 6.1: himpunan `name="…"` pada view == kunci `readInput()` | View memiliki **tujuh** field bernama; `receipt_photo` diambil dari `$_FILES`, bukan `$_POST` | Ditemukan oleh assertion itu sendiri saat pertama dijalankan (mekanisme kerjanya terbukti). Penulisan akhir: view = 6 kunci form + `SettlementService::RECEIPT_FIELD` (diturunkan dari konstanta, bukan string literal) |
| **V-3** | Checklist peramban § 7.2 | Dijalankan pengembang di peramban | **Belum dijalankan**; sebagai gantinya tersedia probe Node + stub DOM atas `app.js` **asli**, yang kini **di-commit** sebagai `tests/manual/js_form_init_check.js` (29 assertion, dijalankan dengan `node tests/manual/js_form_init_check.js`) | Lingkungan ini tidak punya chromium/geckodriver/Playwright. Preseden Fase 2/3: verifikasi JS tidak masuk `run_qc.sh` |
| **V-4** | ID assertion | § 6.1 menamai assertion HTTP "G.2" | Dinamai **`G.1a`** | `G.2` sudah dipakai assertion lain di `qc_fase_3.php` (guard peran/penyajian berkas); sufiks huruf mengikuti gaya `P3.0e` yang sudah ada |
| **V-5** | Marker "wajib" pada label | Menambah penanda di teks label | Memakai `<span class="text-[0.68rem] font-semibold uppercase tracking-[0.16em] text-gold-glow">` | Seluruh kelas sudah ada di `public/assets/css/app.min.css` → **tidak perlu kompilasi ulang Tailwind**, tidak ada kelas baru yang belum ter-build |
| **V-6** | Pemindaian negatif | — (di luar rencana) | Salinan repo di `/tmp` dimutasi 4×: `type="number"` pada `sold_grams`, placeholder `"93,50"`, `this.$refs.form` di `init()`, `.fill` dihapus dari `dry_weight` | Membuktikan penjaga baru **benar-benar gagal** saat kontrak dilanggar (`qc_fase_3` 4 FAIL, `qc_fase_2` 1 FAIL, keduanya `exit 1`) — bukan assertion yang selalu lulus |
| **V-7** | Berkas dokumentasi & harness | § 5 hanya menyebut view/JS/QC | Ditambah: catatan ilustrasi pada `docs/AurumFlow UI UX Documents.md` (Layar C), 1 butir kontrak + baseline pada `AGENTS.md`, baseline & perintah probe pada `README.md`, ADDENDUM Fase 2 & Fase 3, `set -o pipefail` pada `tests/run_qc.sh`, dan probe `tests/manual/js_form_init_check.js` | Kriterian § 10 + `AGENTS.md`: dokumen yang menyebut angka/klaim harus ikut diperbarui agar tidak menyesatkan; temuan F-1 (gerbang QC palsu) mengubah berkas di luar § 5 dan disetujui terpisah (`dec-77e076f7cb075385`) |

### Hasil probe Alpine (V-3) — 29 assertion, 0 gagal

```bash
node tests/manual/js_form_init_check.js
```

Menjalankan `public/assets/js/app.js` **asli** dengan stub DOM, menirukan urutan Alpine 3.17.4
(`x-ref` belum terdaftar ketika `init()` berjalan, `$refs` kosong):

| Yang dibuktikan | Hasil |
|---|---|
| Kode **lama** (`this.$refs.form`) membaca nilai awal | `''` — **akar masalah terkonfirmasi** (bukan sekadar dugaan teori) |
| Kode **baru** membaca `value="18,050"` / `value="200.000"` | `soldGrams='18,050'`, `coordFee='200.000'` |
| Pratinjau kalkulasi langsung hidup tanpa mengetik ulang berat | pendapatan `Rp 37.544.000`, laba bersih `Rp 2.186.500`, 80 : 20 `Rp 1.749.200` / `Rp 437.300`, alokasi saran `Rp 35.157.500` / `Rp 2.186.500` — **identik** dengan angka verifikasi manual `Summary_Fase_3` § 3.3 |
| Jalur `422` mempertahankan isian operator **termasuk alokasi manual** | `30.000.000` / `6.000.000` tidak ditimpa saran sistem |
| Layar kasir: isian & toggle jasa bakar setelah `422` | berat/harga terpelihara; toggle tetap nonaktif; net `Rp 28.957.500` (toggle mati) dan `Rp 28.937.500` (toggle hidup, = QC Manual 2.1) |

### Temuan yang perlu keputusan (tidak diubah dalam perbaikan ini)

| # | Temuan | Bukti | Usulan |
|---|---|---|---|
| **F-1** | **`tests/run_qc.sh` menyamarkan kegagalan suite.** Loop memakai `if php "tests/${suite}.php" 2>&1 \| tee -a "$LOG_FILE"` — status pipeline diambil dari `tee`, bukan dari `php`. Akibatnya suite yang gagal (`exit 1`) tetap dihitung "LULUS" dan skrip `exit 0`, sehingga klaim `AGENTS.md` "exit 0 = lulus" **tidak berlaku saat ini** | Sebelum perbaikan: `qc_fase_3` gagal `H.16` (`exit 1`) tetapi skrip tetap mencetak `LULUS : 9/9` dan `exit 0`; pada uji negatif (V-6) `qc_fase_3` `exit 1` (4 FAIL) dan skrip tetap melaporkan `9/9`. Demonstrasi satu baris: `bash -c 'if php -r "exit(1);" \| tee /dev/null >/dev/null; then echo MENYAMARKAN; fi'` → `MENYAMARKAN` | **DIPERBAIKI** (`dec-77e076f7cb075385`): `set -o pipefail` ditambahkan di samping `set -u` + catatan alasan. Bukti setelah perbaikan, end-to-end: salinan repo di `/tmp` disuntik `type="number"` pada `sold_grams` → `bash tests/run_qc.sh` melaporkan `GAGAL : 1/9  qc_fase_3` dan **`exit=1`**; pada repo bersih `LULUS : 9/9` dan `exit 0` |
| **F-2** | Setelah `422`, `splitTouched` tetap `false`, sehingga **menyunting** salah satu dari empat isian penjualan akan menyegarkan kembali saran alokasi (alokasi manual yang baru saja dipertahankan bisa tersegarkan lagi) | Perilaku `refreshSuggestion()` (`app.js`) menghormati `splitTouched` | Dibiarkan apa adanya: menandai `splitTouched = true` saat init akan **mematikan** saran otomatis pada kasus yang paling umum (operator tidak menyentuh alokasi). Kembalikan bila operator mengeluh — cukup satu baris di `init()` |
| **F-3** | `storage/logs/php-2026-09-30.log` (18:50) sudah ada **sebelum** perbaikan ini: `Fatal error: Class "AurumFlow\Tests\QcFixture" not found`, sisa percobaan `php -r` sesi sebelumnya. Eksekusi ini **tidak** menghasilkan `php-*.log` baru | `ls -lt storage/logs`; isi berkas | Hapus bila tidak diperlukan lagi (tidak dilakukan di sini: berkas di luar cakupan perubahan) |
| **F-4** (pascarilis) | **Akar sebenarnya `422` "wajib diisi":** `:disabled="submitting"` pada **kontrol form**. Peramban mengeluarkan kontrol `disabled` dari data form, sehingga POST hanya membawa `_token`/`_once` (± 357 byte) dan berkas nota ikut terbuang | Payload ± 357 byte di peramban sungguhan | **DIPERBAIKI** di `plan/Summary_Fix_Disabled_Inputs.md`: 11 kontrol → `:readonly="submitting"` + kelas redup, tombol submit tetap `:disabled`; dijaga `H.18`/`H.13` + probe Node. Rencana hidrasi `plan/Plan_Debug_Settlement_Browser.md` ditandai **SUPERSEDED** |

### Hasil gate (dicatat untuk kriteria § 10)

```
qc_pdo 20 · qc_formatter 43 · qc_security 32 · qc_seed 46 · qc_roadmap_protocol_1 6
qc_fase_2 65 · qc_fase_3 97 · qc_sql_injection 15 · qc_routing 31
Total 355 assertion · 0 FAIL · setiap suite exit 0
```

---

*Dokumen ini adalah kontrak eksekusi perbaikan; penyimpangan dan temuan di atas dicatat di
ADDENDUM ini dan dirujuk dari ADDENDUM `plan/Plan_Fase_2_AurumFlow.md` serta
`plan/Plan_Fase_3_AurumFlow.md`.*
