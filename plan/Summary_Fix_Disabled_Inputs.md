# Summary — Akar Sebenarnya 422 "Wajib Diisi": Kontrol `disabled` Hilang dari Payload

> **Laporan eksekusi perbaikan.** Menggantikan dugaan hidrasi pada `plan/Plan_Debug_Settlement_Browser.md`
> (kini berstatus **SUPERSEDED** — lihat § 2).
> Proyek: **AurumFlow** · Cakupan: `app/Views/operator/settlement.php`, `app/Views/operator/teller.php`,
> assertion QC, probe Node.
> Status: **SELESAI & TERVERIFIKASI** (gate PHP + uji negatif) · `2026-09-30`.

| Item | Nilai |
|---|---|
| Gejala nyata | Submit `/operator/settlement` → `422` "wajib diisi" dengan payload hanya ± **357 byte** |
| Akar masalah | Kontrol form ber-`:disabled="submitting"` **dikeluarkan peramban dari data form** saat `@submit` menyalakan `submitting` — isian **dan berkas nota** tidak pernah terkirim |
| Perbaikan | 11 kontrol form: `:disabled="submitting"` → **`:readonly="submitting"`** + kelas redup; tombol submit tetap `:disabled` |
| Gate QC | **9 suite · 357 assertion · 0 gagal · exit 0** (sebelumnya 355) |
| Assertion baru | `qc_fase_3 H.18`, `qc_fase_2 H.13` (+2) · probe Node 29 → **39** assertion |
| DDL / jalur uang / validasi peladen | **Tidak berubah** — akar masalah murni di markup peramban |

---

## 1. Akar masalah (bukti: payload 357 byte)

Kontrol form yang `disabled` **tidak ikut** disusun ke dalam data form (perilaku standar HTML).
Rantainya:

1. Operator menekan **Eksekusi Settlement Harian** (tombol aktif karena `canSubmit` benar).
2. Event `submit` memanggil `@submit="onSubmit()"` → `settlementForm.onSubmit()` menyalakan
   `submitting = true`.
3. Binding `:disabled="submitting"` pada **seluruh** isian — termasuk `<input type="file"
   name="receipt_photo">` — memasang atribut `disabled` sebelum peramban menyusun data form
   (baik seketika maupun pada flush mikrotask setelah handler selesai).
4. Peramban menyerialkan form **tanpa** kontrol ber-`disabled` → yang tersisa hanya dua field
   tersembunyi tanpa binding itu: `_token` (CSRF) dan `_once` (token sekali-pakai) → **± 357 byte**.
5. Peladen menerima form kosong → `422` dengan pesan "wajib diisi" untuk berat/kadar/harga.

Ini menjelaskan seluruh bukti yang membingungkan sebelumnya sekaligus:

| Bukti | Penjelasan |
|---|---|
| Payload hanya ± 357 byte | Hanya `_token` + `_once` yang dikirim; enam isian dan berkas nota terbuang |
| **Berkas nota tidak ada** di payload | `receipt_photo` juga ber-`:disabled="submitting"` — jadi bukan hanya "belum dipilih" |
| UI terlihat terisi, tetapi peladen menerima kosong | Penghapusan terjadi **saat penyerialan form**, bukan saat halaman dimuat |
| QC PHP & probe Node hijau | QC PHP mem-POST langsung tanpa peramban (tanpa JS tidak ada `disabled`); probe Node tidak pernah memodelkan penyerialan form |

---

## 2. Mengapa teori sebelumnya keliru (dicatat jujur)

| Teori | Status |
|---|---|
| **Hidrasi 3 lapis berbasis atribut** + `data-af-initial` + `$nextTick`/`alpine:initialized` (`plan/Plan_Debug_Settlement_Browser.md` § 5) | **Tidak dijalankan.** Diarahkan ke gejala "nilai awal ter-wipe saat halaman dimuat"; gejala itu tidak lagi tampak di peramban dan **bukan** penyebab payload kecil. Dokumen ditandai `SUPERSEDED` |
| **Cache-busting `?v=`** untuk aset (`dec-14827488320397ae` butir 2) | **Tidak dijalankan.** Bukan penyebab bug ini. Tetap kandidat peningkatan terpisah (aset kini memang tanpa versi) — dicatat sebagai item terbuka, bukan bagian perbaikan ini |
| **Probe CDP peramban** (`dec-14827488320397ae` butir 1) | **Tidak dibuat.** Tetap berguna untuk kelas bug "hanya terlihat di peramban", tetapi bukan jalur perbaikan di sini; verifikasi manual § 6 dipakai |
| Perbaikan sebelumnya (`querySelector`, `x-model.fill`, `restoreAutoSplit(false)`) | **Dipertahankan.** Sudah bekerja (termasuk jalur `422`) dan tidak terkait akar masalah ini |

Pelajaran yang dituangkan ke QC: sepanjang menyangkut data form, "lulus" dari lapis mana pun
(HTML, POST langsung, pemanggilan metode) **bukan** bukti bahwa peramban benar-benar mengirim isian.

---

## 3. Perbaikan yang diterapkan

| Berkas | Perubahan |
|---|---|
| `app/Views/operator/settlement.php` | **7 kontrol**: `sold_grams`, `density_pct`, `shop_price_per_g`, `coord_fee`, `receipt_photo`, `kas_split`, `brankas_split` → `:disabled="submitting"` diganti **`:readonly="submitting"`** + `:class="{ 'opacity-70 cursor-not-allowed': submitting }"`. Tombol submit → `:disabled="!canSubmit \|\| submitting"`. Tombol **"Saran otomatis"** tetap `:disabled="submitting"` (elemen `<button>`, tidak membawa data form). Butir aturan ditambahkan ke docblock berkas |
| `app/Views/operator/teller.php` | **4 kontrol**: `farmer_alias`, `dry_weight`, `buy_price_per_g`, `burn_fee_enabled` → perlakuan sama. Tombol submit **tidak diubah**: `:disabled="!canPay"` dan `canPay()` sudah memuat `!submitting` (`public/assets/js/app.js:408-410`) — diverifikasi, bukan diasumsikan. Butir aturan ditambahkan ke docblock |
| `tests/qc_fase_3.php` | Assertion **H.18** (7 kontrol rekonsiliasi) + helper `qc3_input_tag()` |
| `tests/qc_fase_2.php` | Assertion **H.13** (4 kontrol kasir + `canPay` masih memuat `!submitting`) + helper `qc2_input_tag()` |
| `tests/manual/js_form_init_check.js` | +10 assertion (total 39): jalur submit tidak membersihkan model, `onSubmit()`/`confirm()` menyalakan `submitting`, `canPay` benar dengan saldo realistis |

Catatan teknis yang penting untuk pemeliharaan:

- **`readonly` diabaikan peramban untuk `type="file"` dan `type="checkbox"`.** Pada kedua elemen itu
  kelas redup yang menandai status "sedang dikirim"; yang krusial adalah **tidak** `disabled`, sehingga
  berkas nota dan status jasa bakar tetap ikut terkirim. Ditulis sebagai komentar di view agar tidak
  ada yang "mengembalikan" `:disabled` demi keseragaman.
- **`:disabled` pada `<button>` tetap benar** dan sengaja dipertahankan pada tombol submit — tombol
  tidak membawa data form, sehingga mengunci tombol selama pengiriman justru mencegah double-submit.
- **Tanpa kompilasi ulang Tailwind**: kelas `opacity-70` dan `cursor-not-allowed` sudah ada di
  `public/assets/css/app.min.css` (dipakai layar kasir). `opacity-50` **tidak** dipakai justru karena
  belum ter-build.
- **Jalur tanpa JavaScript tidak berubah**: binding Alpine tidak aktif, jadi tidak ada `disabled`
  maupun `readonly` — form terkirim apa adanya seperti sebelumnya.

---

## 4. QC & harness

### 4.1 Assertion baru

| ID | Suite | Yang dijaga |
|---|---|---|
| `H.18` | qc_fase_3 | 7 kontrol rekonsiliasi memakai `:readonly="submitting"` dan **tidak** memakai `:disabled`; tombol submit tetap `:disabled="!canSubmit \|\| submitting"` |
| `H.13` | qc_fase_2 | 4 kontrol kasir memakai `:readonly="submitting"` dan **tidak** memakai `:disabled`; `canPay()` masih memuat `!submitting` |
| probe Node | `tests/manual/js_form_init_check.js` | Setelah `onSubmit()`/`confirm()`: flag `submitting` menyala **dan** seluruh model (berat, kadar, harga, beban aparat, dua alokasi) tetap utuh |

Helper `qc3_input_block()`/`qc2_input_block()` dipersempit menjadi **satu tag** (`qc3_input_tag()` /
`qc2_input_tag()`): `>` di dalam `<?= … ?>` atau `=>` tidak lagi dianggap penutup tag. Bug helper ini
muncul sendiri saat assertion baru pertama dijalankan — potongan tiap input ikut menyeret markup
sesudahnya (termasuk `<button :disabled=…>` yang memang boleh), sehingga terjadi positif palsu.

### 4.2 Uji negatif (penjaga terbukti menggigit)

Salinan repo di `/tmp` dimutasi, lalu suite dijalankan di sana:

| Mutasi | Hasil |
|---|---|
| Kembalikan `:disabled="submitting"` pada `sold_grams` | `H.18` **FAIL** — "sold_grams: memakai :disabled — kontrol hilang dari payload"; suite `exit 1` |
| Buang `:readonly="submitting"` dari `receipt_photo` | `H.18` **FAIL** — "receipt_photo: tanpa :readonly="submitting"" |
| Longgarkan tombol submit menjadi `:disabled="!canSubmit"` | `H.18` **FAIL** — "tombol submit kehilangan :disabled="!canSubmit \|\| submitting"" |
| `:disabled="submitting"` pada `burn_fee_enabled` (kasir) | `H.13` **FAIL**; suite `exit 1` |

### 4.3 Hasil gate

```text
qc_pdo 20 · qc_formatter 43 · qc_security 32 · qc_seed 46 · qc_roadmap_protocol_1 6
qc_fase_2 66 · qc_fase_3 98 · qc_sql_injection 15 · qc_routing 31
Total 357 assertion · 0 FAIL · setiap suite exit 0 · run_qc.sh exit 0
```

Probe Node: **39 assertion, 0 gagal**. Catatan: probe sempat menangkap kesalahannya sendiri — nilai
`saldo` pada fixture-nya lebih kecil dari nilai transaksi sehingga `canPay()` benar-benar `false`;
fixture diperbaiki ke Rp 50.000.000 dalam sen (5.000.000.000) dan kini memverifikasi kontrol bisnis itu.

---

## 5. Verifikasi manual yang masih perlu dijalankan (peramban)

Tidak ada Chrome/Chromium di mesin kerja ini (hanya Firefox tanpa driver), jadi tabel ini untuk operator:

| # | Langkah | Hasil yang diharapkan |
|---|---|---|
| 1 | Isi kadar & harga, pilih foto nota, tekan **Eksekusi Settlement Harian**, lihat tab **Network** | Payload multipart memuat `sold_grams`, `density_pct`, `shop_price_per_g`, `coord_fee`, `kas_split`, `brankas_split`, **dan** `receipt_photo` (jauh lebih besar dari ± 357 byte) → `302` + flash sukses |
| 2 | Selama pengiriman berlangsung | Seluruh isian **redup & tidak dapat disunting**, tetapi nilainya tetap tampak; tombol submit mati |
| 3 | Setelah kegagalan validasi (`422`) | Pesan galat muncul **dan** isian (termasuk alokasi manual) masih terisi |
| 4 | Layar kasir: geser untuk bayar | Berat & harga terkirim; toggle jasa bakar mengikuti pilihan operator |
| 5 | JavaScript dimatikan | Form tetap terkirim seperti sebelumnya (tanpa `disabled`/`readonly`) |

---

## 6. Temuan & catatan

| # | Temuan | Catatan |
|---|---|---|
| B-1 | Penjaga double-submit sisi klien sekarang bersandar pada `submitting` + `:readonly`/kelas redup + penjaga tombol, ditambah token `_once` peladen | Berlapis dan tetap berlaku; token sekali-pakai peladen tetap pertahanan utama |
| B-2 | `settlementForm.onSubmit()` mengembalikan `submitting = false` setelah 5 detik; `tellerForm` tidak (halaman berpindah `302` atau dimuat ulang pada `422`) | Perilaku lama, tidak diubah; dicatat sebagai pengamatan |
| B-3 | `Plan_Debug_Settlement_Browser.md` | Ditandai **SUPERSEDED** sebagai jejak bukti; isinya (hidrasi 3 lapis, `data-af-initial`, cache-busting) **tidak** diimplementasikan |
| B-4 | Aset masih tanpa versi (`App::url('assets/js/app.js')`) | Bukan penyebab bug ini; item terbuka terpisah bila stale-asset pernah menimbulkan kebingungan |
| B-5 | `storage/logs/php-2026-09-30.log` (18:50) sisa sesi sebelumnya | Tidak dihasilkan oleh perbaikan ini; aman dihapus |

---

## 7. Yang **tidak** berubah (penjaga lingkup)

- **Nol DDL/seed**, tidak ada kunci `system_settings` baru, tidak ada perubahan pada
  `SettlementService`/`TellerService`/kontroler — akar masalah murni perilaku peramban.
- **Validasi "wajib diisi" tetap apa adanya**: peladen tetap satu-satunya yang memutuskan.
- **Tanpa JavaScript**: perilaku form tidak berubah (tidak ada `disabled` maupun `readonly`).
- **Perbaikan sebelumnya dipertahankan**: `x-model.fill`, pembacaan DOM tanpa `$refs`,
  `restoreAutoSplit(false)`, placeholder contoh `mis. …`.

---

## 8. Kriteria selesai

- [x] Akar masalah dinyatakan dengan bukti yang konsisten (payload ± 357 byte ⇒ kontrol terbuang
      saat penyerialan form, termasuk berkas nota).
- [x] Tidak ada lagi `:disabled` pada kontrol form (`<input>`/`<select>`) di kedua layar operator;
      tombol submit tetap dijaga.
- [x] Assertion `H.18`/`H.13` + 10 assertion probe Node membuktikan perbaikan (uji negatif: 4 mutasi
      semuanya tertangkap, suite `exit 1`).
- [x] `bash tests/run_qc.sh` **exit 0** — 9 suite · 357 assertion · 0 gagal; tanpa `storage/logs/php-*.log` baru.
- [x] Dokumen akar masalah ditulis; rencana hidrasi ditandai SUPERSEDED.
- [ ] Langkah § 5 dijalankan operator di peramban (tidak dapat dijalankan di mesin ini).
