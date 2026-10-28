# AGENTS.md — AurumFlow

Instruksi kerja untuk agen/kontributor di repositori ini. Prosedur rinci ada di
`README.md`; keputusan & riwayat ada di `plan/`.

## Sumber kebenaran

Urutan presedensi saat dokumen saling bertentangan (rinci: `plan/Plan_Fase_1_AurumFlow.md` §A.1):

1. `docs/AurumFlow Entity Relationship Diagram.md` — **skema database**.
   `database/schema.sql` adalah salinan DDL-nya, bukan sebaliknya.
2. `docs/AurumFlow System Architecture Document.md` — struktur direktori, tech stack.
3. `docs/AurumFlow Roadmap and QC Documents.md` — lingkup per fase + protokol QC.
4. `docs/AurumFlow Product Requirement Document v3.0.md` — aturan bisnis.
5. `plan/Plan_Fase_1_AurumFlow.md` — rencana eksekusi + addendum rekonsiliasi.

**Jangan menyimpang dari `docs/` secara diam-diam.** Bila kode membutuhkan
tabel, kolom, tipe, atau nama yang berbeda: perbarui `docs/` lebih dulu, lalu
catat di bagian ADDENDUM `plan/Plan_Fase_1_AurumFlow.md` beserta alasannya.
Bila `docs/` ternyata salah, laporkan — jangan diam-diam mengikutinya.

## Batas kepemilikan

Hanya `public/` yang boleh dijangkau web. `app/`, `database/`, `storage/`,
`tests/`, `plan/`, `docs/` tidak.

- Tanpa Composer, tanpa `vendor/`. Autoloader manual di
  `app/Config/bootstrap.php` memetakan `AurumFlow\` → `app/`. Jangan
  menambahkan `composer.json`.
- SQL hanya di `app/Models/` dan `app/Config/Database.php`. Jangan menaruh
  query di `app/Views/` atau `public/index.php`.

## Kontrak yang mudah dilanggar

- **Akses database** satu pintu: `Database::getConnection()` (nama ini dipakai
  Protokol QC Manual 1). Semua nilai dinamis lewat named parameter; dilarang
  menginterpolasi `$_GET`/`$_POST`/`$_REQUEST` ke dalam string SQL. Operasi
  yang menyentuh kas wajib `Database::transaction()` (SAD §4.1).
- **Tipe kolom**: uang `DECIMAL(14,2)`, gram `DECIMAL(8,3)`, persen
  `DECIMAL(5,2)`, primary key `CHAR(36)` UUID v4. `FLOAT`/`DOUBLE` dilarang —
  QC 4.7 memindai seluruh skema dan akan gagal.
- **Identitas login** adalah `users.phone_number`, bukan username/email.
  Sejak Fase 7 ada **tiga** peran — `operator`, `investor`, `cashier` — dan tiap
  peran punya **satu** pintu kredensial: `/login` (kata sandi), `/investor/login`
  (PIN mitra), `/cashier/login` (PIN kasir). Pintu kata sandi **menolak** akun
  `cashier` secara eksplisit. Label peran punya satu pemilik:
  `Constants::ROLE_LABELS` + `Constants::roleLabel()`.
- **Angka untuk tampilan** hanya lewat `Formatter` (`rupiah()`, `gram()`,
  `tanggalId()`). Dilarang memanggil `number_format()` langsung.
- **Nilai awal form operator berasal dari peladen, bukan dari placeholder.**
  Peladen merender `value="…"`; Alpine membacanya di `init()` lewat `querySelector`
  (`formFieldValue()` di `public/assets/js/app.js`), **bukan** `$refs` — Alpine
  mendaftarkan `x-ref` setelah `init()` komponen berjalan, sehingga `$refs.form`
  masih kosong dan `x-model` justru menghapus nilai yang dirender peladen.
  Placeholder numerik selalu berawalan `mis. ` (contoh, bukan data), isian
  bernilai awal memakai `x-model.fill`, dan `init()` memanggil
  `restoreAutoSplit(false)`. Dijaga oleh assertion `G.1a`/`G.3a` + `H.10`–`H.17`.
- **Setiap output dinamis di view** wajib `Security::e(...)`.
- **Parameter bisnis** (kuota serapan, jasa bakar, masa tunggu penarikan)
  hidup di `system_settings`, **bukan** di `app/Config/Constants.php` —
  jangan buat dua sumber kebenaran.
- **Mutasi hanya via POST + token CSRF.** Front controller memeriksa CSRF
  untuk semua method non-GET sebelum dispatch, dan `AuthController` memeriksa
  sekali lagi sebagai pertahanan berlapis. Jangan hapus salah satunya.
  Form kasir, layar rekonsiliasi, **dan jalur kas Fase 4** (setoran modal,
  Pindah Kas, bayar penarikan) menambah token sekali-pakai `_once`
  (`Security::issueFormToken()`/`consumeFormToken()`, field
  `Security::FORM_FIELD`) sebagai penjaga double-submit di sisi peladen.
  Satu halaman = satu token aktif: setiap mutasi **wajib** diikuti PRG supaya
  halaman berikutnya menerbitkan token baru.
- **Jalur uang tanpa float.** Hitung dalam miligram & sen (integer), simpan
  sebagai string desimal kanonik. `TellerService::quote()` memegang aturan
  pembulatan/parsing berat & harga; `payCash()` adalah satu-satunya jalur mutasi
  kas hulu. `SettlementService` memakai `TellerService::quote()` untuk parse
  berat/harga dan `moneyToCents()`/`centsToDecimal()` untuk nilai uang dari
  database, sehingga aturan itu tetap punya satu pemilik.
- **Kontrol form TIDAK PERNAH `:disabled`** — peramban mengeluarkan kontrol
  `disabled` dari data form, sehingga POST hanya membawa `_token`/`_once`
  (payload ± 357 byte) dan peladen menjawab `422` "wajib diisi". Selama
  pengiriman pakai `:readonly="submitting"` + kelas redup (`opacity-70
  cursor-not-allowed`); `:disabled` hanya untuk `<button>` (tidak membawa data
  form). Dijaga assertion `H.18` (`qc_fase_3`) & `H.13` (`qc_fase_2`). Catatan:
  `readonly` diabaikan peramban untuk `type="file"` dan `type="checkbox"` —
  yang penting keduanya **tidak** `disabled` agar berkas & toggle tetap terkirim.
- **Tutup buku (Fase 3 + Fase 4 + Fase 6 + Fase 9 + Fase 10) selalu sembilan
  kelompok tulisan dalam satu transaksi**: kunci dompet modal
  (`CapitalWallet::lockAllForUpdate()`, id menaik) → kedua pos kas
  (`CashRegister::lockByNamesForUpdate()`, urutan tetap)
  → talangan aktif (`BridgeLoan::lockActiveForUpdate()`, FIFO) → batch
  `HoldingRowsForUpdate()` → baris `active_carryover_deficit`
  (`SystemSetting::getForUpdate()`) → voucher beban
  (`OperationalExpense::lockUnabsorbedForUpdate()`, FIFO), lalu
  `daily_settlements` + `hulu_transactions` (`settleBatch()`) +
  `cash_registers` ×2 + `cash_mutations` ×2 + penyerapan voucher
  (`OperationalExpense::absorb()` → `settlement_id`) +
  `BridgeLoanService::settleLoans()` (baris `bridge_loans` + saldo
  `cash_registers` + mutasi `out` per talangan) + `SystemSetting::set()` +
  **`AllocationEngine::apply()`** (`profit_distributions` +
  `capital_wallets.balance_profit`), lalu **`OfficerService::accrueForSettlement()`**
  (baris `officer_ledger_entries` `entry_type='cut'` + saldo
  `officers.balance_payable`) dan **`OfficerService::absorbBonuses()`**
  (`officer_ledger_entries.settlement_id` untuk akrual bonus). Bagi hasil
  80 : 20 tetap pembukuan; yang masuk ke dompet hanya porsi **20 % pool mitra**.
- **Urutan penguncian global (semua jalur uang)**: `capital_wallets` (`id`
  menaik) → `cash_registers` (nama menaik) → `bridge_loans` (`status='active'`,
  `created_at ASC, id ASC`) → `miner_loans` (Fase 8: `status='open'`,
  `issued_on ASC, created_at ASC, id ASC`) → `hulu_transactions` →
  `operational_expenses` (Fase 9: `settlement_id IS NULL`, `expense_date ASC,
  created_at ASC, id ASC`) → **`officers`** (Fase 10: seluruh baris, `id`
  menaik) → **`officer_ledger_entries`** (`entry_type='bonus' AND
  settlement_id IS NULL`, `created_at ASC, id ASC`) → `system_settings`.
  Jalur pencairan hak petugas menyentuh `cash_registers` → `officers`, jalur
  bonus hanya `officers`, sehingga urutan relatifnya konsisten dengan jalur
  dompet/penarikan (dompet selalu lebih dulu).
  Jalur beban operasional hanya menyentuh `cash_registers` (lalu
  `operational_expenses`), sehingga urutan relatifnya konsisten.
  Jangan pernah mengunci dompet setelah pos kas — urutan terbalik membuka jalan
  deadlock dengan jalur penarikan. Jalur penerimaan talangan menyentuh
  `cash_registers` → `bridge_loans` (tanpa `hulu`), sedangkan jalur kasbon
  menyentuh `cash_registers` → `miner_loans` → `system_settings` (tanpa dompet,
  talangan, maupun hulu), sehingga urutan relatifnya konsisten.
- **Dana talangan (Fase 6) — fee HANYA dari hak pengelola.** Penerimaan =
  **satu** `Database::transaction()` (`BridgeLoanService::receive()`): kunci pos
  kas → baris `bridge_loans` `status='active'` (`fee_amount` **dihitung
  peladen**: `fixed` → nominal, `pct` → `intdiv(pokok_sen × tarif_perseratus +
  5000, 10000)`) → saldo Kas Meja Bakar `+pokok` → mutasi `in`. Pengembalian
  menyatu dengan tutup buku (`settleLoans()` di dalam transaksi `settle()`):
  `pokok + fee` keluar dari **pos yang sama** (Kas Meja Bakar), satu mutasi `out`
  per talangan, baris talangan menjadi `settled` + `settlement_id`. Fee diserap
  `min(fee, operator_share_80)` dari porsi operator; kelebihannya
  (`fee_uncovered`) dibayar dari kas kerja pengelola — **`investor_pool_20`,
  `profit_earnings`, dan tiap `capital_wallets.balance_profit` tidak boleh
  berubah sepeser pun** (dibuktikan assertion diferensial `qc_fase_6` grup E).
  Talangan yang belum lunas tetap `active` dan terbayar pada tutup buku
  berikutnya (FIFO). Bila `saldo meja setelah alokasi < Σ(pokok + fee)` → `422`
  tanpa tulisan.
- **Alokasi modal bersifat pembukuan (*ex-post*)**: `balance_capital` **tidak**
  didebit saat belanja hulu. Serapan per dompet dihitung saat settlement dan
  dicatat pada `profit_distributions.capital_utilized`; "modal sedang bekerja"
  dibaca dari baris itu, bukan dari kolom yang tidak ada.
- **Aritmetika alokasi**: sen integer + metode sisa terbesar. Invariant yang
  tidak boleh runtuh: `Σ capital_utilized + uncovered == modal belanja putaran`
  dan `Σ profit_earned == investor_pool_20` (bila ada dompet mitra yang
  menyerap). Dompet `tier='operator'` menyerap modal tetapi **tidak pernah**
  menerima jatah pool (PRD § 3.4) — serapannya dicatat `profit_earned = 0.00`.
  Rumus tinggal di `AllocationEngine` (satu pemilik), bukan di controller/view.
- **Sel "Kuota Serapan" di layar Kelola Modal bersifat per-tier**: hanya
  **Mitra Prioritas** yang menampilkan isian kuota; **Pengelola** menampilkan
  badge kebijakan sistem (`operator_base_quota_pct` dari `system_settings`) dan
  **Mitra Reguler** menampilkan penanda "Pro-rata" — tanpa `<input>`. Alasannya:
  isian `0,00 %` untuk Pengelola menyesatkan (mesin memakai kuota dasar 50 %),
  dan kuota Reguler tidak pernah dipakai. Endpoint `POST
  /operator/capital/quota` tetap menolak kuota bukan-nol untuk tier
  non-prioritas (pertahanan lapis peladen). Dijaga assertion `D.20`–`D.24`
  (`qc_fase_4`).
- **Label tier punya satu pemilik**: `CapitalWallet::TIER_LABELS` — `family` →
  **"Mitra Prioritas"**, `external` → **"Mitra Reguler"**, `operator` →
  **"Pengelola"**. Enum database tidak berubah
  (`ENUM('operator','family','external')`); nilai enum mentah tidak pernah
  dicetak ke layar.
- **Penarikan modal pokok = H-7 hari kerja** (Sabtu/Minggu dilewati,
  `notice_period_days` dari `system_settings`). Bayar sebelum jatuh tempo →
  `422` tanpa tulisan. Maksimum satu pengajuan modal terbuka per dompet
  (`capital_wallets.withdrawal_status` + `withdrawal_request_date`).
- **Jalur uang Fase 4 selalu satu `Database::transaction()`**: setoran modal
  (dompet + Brankas Utama + mutasi `in`), Pindah Kas **satu arah** Brankas → Kas
  Meja Bakar (dua pos + dua mutasi), dan bayar penarikan (dompet + Brankas +
  mutasi `out` + status `completed`).
- **Defisit tidak pernah memotong modal investor**: laba bersih negatif hanya
  menulis `daily_settlements.carryover_deficit` + `active_carryover_deficit`,
  dengan bagi hasil `0.00`. Rumus: `used = min(defisit, max(0, laba kotor −
  aparat))`, `net = laba kotor − aparat − used`,
  `defisit_baru = max(0, −net)`. Pada hari minus, `investor_pool_20 = 0.00`
  sehingga baris `profit_distributions` bernilai `0.00` dan tidak ada
  `balance_profit` yang bertambah.
- **Alokasi kas fisik wajib berjumlah pas** dengan **`pendapatan toko` (penuh)**
  sejak Fase 9 — bukan `pendapatan − beban aparat` lagi; dua isian alokasi yang
  kosong berarti "pakai saran sistem". Perbedaan apa pun → `422` tanpa tulisan.
  Alasannya aritmetika: beban operasional sudah keluar tunai dari laci lebih
  dahulu (`ExpenseService::pay()`), jadi laci harus menerima **penggantian**.
  Mengurangi laba **dan** kas yang dialokasikan membuat saldo buku lebih rendah
  daripada uang fisik tepat sebesar beban pada setiap sesi (bukti:
  `audit/Audit_Biaya_Koordinasi_AurumFlow.md` § 5.5 skenario B). Dijaga
  assertion `E.7`/`F.1` (`qc_fase_9`).
- **Beban operasional hanya berasal dari voucher, bukan isian (Fase 9).**
  Isian `coord_fee` **tidak ada lagi** di layar rekonsiliasi: `batchContext()`
  dan `settle()` membaca `Σ operational_expenses` yang belum terserap, dan
  `SettlementService::quote()` memakai `$context['coord_fee_cents']`. Seam
  `$input['coord_fee']` hanya tersisa untuk kalkulasi murni tanpa basis data
  (uji `qc_fase_3` grup A/B) dan **tidak pernah** dikirim controller. Tanpa
  aturan ini, operator dapat menaikkan beban untuk menahan uang di laci karena
  `kas = pendapatan − laba`. Dijaga assertion `H.8` (`qc_fase_9`).
- **Satu voucher beban = satu baris kas `out` (Fase 9).** `ExpenseService::pay()`
  menjalankan **satu** `Database::transaction()` dengan urutan tetap: kunci pos
  kas meja → cek saldo → mutasi `out` → saldo turun → voucher. Urutan itu wajib
  karena `operational_expenses.mutation_id` memakai FK yang diperiksa InnoDB
  seketika. `mutation_id` = `ON DELETE RESTRICT`, `settlement_id` = `ON DELETE
  SET NULL`. Dijaga assertion `A.1`–`A.3`/`G.3` (`qc_fase_9`).
- **Unggah nota**: nama berkas SELALU `Security::uuidV4()` + ekstensi dari tipe
  gambar terdeteksi (`getimagesize`) — nama kiriman klien tidak pernah menjadi
  path; hanya lewat `move_uploaded_file()`; batas keras 4 MB; berkas yang sudah
  tersalin wajib `unlink()` bila transaksi digulung. Direktori
  `public/uploads/receipts/` tidak boleh mengeksekusi skrip.
- **Nominal dari klien tidak dipercaya (Fase 3).** Layar rekonsiliasi hanya
  mengirim berat jual, kadar, harga jual, dua alokasi, dan berkas nota;
  pendapatan, modal belanja hulu, **beban operasional (dari voucher)**,
  laba, bagi hasil, dan defisit dihitung ulang `SettlementService`.
- **Kas hulu selalu tiga tulisan dalam satu transaksi**: kunci baris kas
  (`CashRegister::lockTellerForUpdate()`), cek saldo, lalu
  `hulu_transactions` + `cash_registers` + `cash_mutations` lewat
  `Database::transaction()`. Jangan pecah atau panggil `beginTransaction()`
  manual.
- **Nominal dari klien tidak dipercaya.** Form kasir hanya mengirim berat,
  harga, alias, dan status toggle jasa bakar; seluruh nominal dihitung ulang
  dari `system_settings`.
- **Rute operator (`/operator/teller`, `/operator/settlement`, `/operator/capital`
  beserta sub-POST-nya, termasuk `/operator/capital/invite`) hanya untuk peran
  `operator`** (`requireRole()`); pelanggar diarahkan ke **beranda perannya**
  (`/dashboard`), bukan diberi halaman error.
- **Rute investor (`/investor/dashboard`, `/investor/withdrawal`, `/investor/report`,
  `/investor/report/csv`) hanya untuk peran `investor`**; operator yang mencoba
  masuk diarahkan ke `/dashboard`. **`/dashboard` sendiri operator-only** —
  halaman itu menampilkan isi `system_settings` (mode alokasi, kuota 50 %, defisit),
  jadi investor diarahkan ke `/investor/dashboard`. Pemetaan peran → beranda
  **satu pemilik**: `BaseController::roleLandingPath()`; `AuthController::login()`,
  `requireGuest()`, `home()`, dan `logout()` mengikutinya. Dijaga assertion `E.*`
  (`qc_fase_5`) dan `D.*` (`qc_fase_2`/`qc_fase_3`/`qc_fase_4`).
- **PIN mitra 6 digit ber-pepper, satu pintu.** `users.password_hash` untuk mitra
  berisi `password_hash(hash_hmac('sha256', pin, APP_PIN_PEPPER))`. Konsekuensi
  yang disengaja: PIN **tidak pernah** sah di `POST /login` (kata sandi) — jadi
  pembatasan percobaan tak bisa dilewati lewat pintu lain. `APP_PIN_PEPPER` kosong
  → onboarding **gagal dengan pesan**, bukan menyimpan PIN tanpa pepper
  (fail-closed). Jangan menambahkan jalur verifikasi PIN lain tanpa memakai
  `Security::verifyPin()`. Kebijakan PIN lemah (digit sama, barisan lurus, blok
  tiga digit berulang) ada di `OnboardingService::pinPolicy()` — satu pemilik.
- **Pembatasan percobaan login milik `PinThrottle`** (`app/Helpers/PinThrottle.php`,
  berkas JSON di `storage/throttle/`): 5 gagal dalam 15 menit → kunci 15 menit per
  `nomor + IP`. Dipakai **kedua** pintu (`/login` dan `/investor/login`); jangan
  menambah pintu login baru tanpa memakainya. Berkasnya berada di `storage/`
  (di luar document root) dan **tidak boleh** ikut ter-commit; bila direktori tak
  dapat ditulis, sistem turun mutu ke penghitung sesi + satu `Logger::warning`.
- **Token undangan sekali pakai.** `users.invite_token` = `Security::randomToken(32)`
  (64 heksadesimal). Diterbitkan peladen saat mitra dibuat atau oleh operator
  (`POST /operator/capital/invite`, `issue`/`reset`), dan **di-NULL-kan** saat
  tanda tangan berhasil. Membuka ulang tautan lama → halaman "tautan tidak
  berlaku", nol tulisan (diuji `A.*`/`D.*` `qc_fase_5`).
- **Isolasi blind investor — daftar terlarang.** Berkas yang menyentuh jalur
  investor (`Controllers/Investor*`, `Controllers/ExportController.php`,
  `Services/InvestorService.php`, `Views/investor/*`, `Views/layouts/{investor,report}.php`)
  **dilarang** menyebut: `HuluTransaction`/`hulu_transactions`, `farmer_alias`,
  `total_shop_revenue`, `net_settled_profit`, `operator_share_80`, `investor_pool_20`,
  `daily_coordination_fee`, `deficit_recovery_used`, `carryover_deficit`,
  `SystemSetting`, `CashRegister`, `CashMutation`, `BridgeLoan`/`bridge_loans`,
  agregat sesi
  (`sumProfit(`/`sumUtilized(`), `SELECT *`, serta istilah tampilan "omzet",
  "laba kotor", "hulu", "pool", "talangan". JOIN ke `daily_settlements` **hanya** boleh
  mengambil `settlement_date`. Juga: **tidak ada route investor yang membaca
  `wallet_id` dari form** — dompet selalu diselesaikan dari session
  (`InvestorService`). Dijaga assertion `I.*` + `D.*` `qc_fase_5`.
- **PWA hanya men-cache aset statis.** `public/sw.js` memakai daftar-putih
  (CSS, font, `app.js`, `alpine.min.js`, ikon, `offline.html`); navigasi selalu
  jaringan dahulu, dan HTML/JSON/CSV **tidak pernah** masuk cache — saldo yang
  disajikan dari cache akan basi. `manifest.json` memakai `start_url`/`scope`
  relatif (`"./"`) supaya pemasangan subdirektori tetap benar. Registrasi service
  worker hanya pada `https:`/`localhost`.
- **Setiap perubahan `app.js`/`app.min.css` WAJIB menaikkan `CACHE_NAME` di
  `public/sw.js`.** Aset statis dilayani **cache-first**, jadi tanpa kenaikan
  versi shell peramban operator tetap menjalankan JS/CSS LAMA bersama view baru:
  komponen Alpine kehilangan variabel yang baru (mis. `bridgeHasActive`) sehingga
  elemen `x-cloak x-show="…"` **tidak pernah muncul** — itulah akar gejala "baris
  fee talangan hilang" pada uji manual Fase 6 (versi shell saat ini:
  **`aurumflow-shell-v5`**, dinaikkan dari v1 → v2 saat Fase 6, v2 → v3 saat
  Fase 8 menambahkan komponen `kasbonForm`, v3 → v4 saat Fase 9 mengubah
  state `tellerForm` (modal beban) dan `settlementForm` (beban dari voucher),
  lalu v4 → v5 saat Fase 10 menambahkan state hak mitra lapangan pada
  `settlementForm`).
  Versi yang tercatat
  pada butir ini adalah satu pemilik kebenaran: `AGENTS.md` dan `public/sw.js`
  harus menyebut versi yang sama (dijaga assertion `G.17`–`G.18` `qc_fase_6`).
  Menambah aset ke daftar-putih juga termasuk perubahan daftar aset.
- **Nominal dari klien tidak dipercaya (Fase 4).** Layar kelola modal hanya
  mengirim pemilih dompet, nominal, kuota, dan aksi; saldo, serapan, dan
  distribusi dihitung ulang `AllocationEngine`/`CapitalService`.
- **Layout yang merender view ber-Alpine wajib memuat `alpine.min.js`.** Tanpa
  bootstrap itu `x-data`/`x-text`/`x-cloak` tidak pernah dievaluasi: tombol yang
  labelnya hanya hidup di `x-text` dirender **kosong** (kotak berbingkai tanpa
  teks), dan papan angka tidak pernah muncul. `layouts/auth.php` sempat
  kehilangan tag itu saat Fase 5 menambahkan papan angka PIN — kotak kosong di
  antara isian PIN dan tombol kirim. Papan angka kini `x-cloak`, sehingga ia
  tetap tersembunyi bila Alpine gagal dimuat, bukan menyisakan kotak hantu.
  Dijaga assertion `J.13` (`qc_fase_5`) + probe `tests/manual/js_investor_check.js`.
- **Atribut Alpine adalah EKSPRESI, bukan blok pernyataan.** `@click`, `x-show`,
  `x-text`, dan `:class` dievaluasi sebagai ekspresi JavaScript (Alpine
  menempelkan `return`), sehingga `@click="try { … } catch { … }"` menghasilkan
  *Alpine Expression Error: Unexpected token 'try'* dan tombol yang diam-diam
  tidak melakukan apa pun. Rantai Promise (`…then(() => { … })`) **sah** sebagai
  ekspresi; pernyataan (`try`, `if` di tengah, blok `{ … }` di luar fungsi)
  tidak.
- **Interaksi sekali pakai hidup di view, bukan di komponen baru.** Tombol
  "Salin tautan" pada kartu undangan (`app/Views/operator/capital.php`)
  sengaja memakai state literal (`x-data="{ copied: false }"`) + rantai Promise
  langsung di `@click`, **bukan** `Alpine.data('clipboardButton', …)`. Alasannya
  pengalaman nyata: komponen yang baru ditambahkan ke `app.js` dapat belum ada
  di aset yang di-cache peramban, sehingga `x-data="komponen(…)"` gagal init
  (`ReferenceError`) dan **tombolnya hilang seluruhnya** — `x-text`/`:class`
  tidak pernah dievaluasi. Sebuah komponen `Alpine.data()` hanya layak dibuat
  bila ia dipakai beberapa tempat atau memuat state kompleks; dan bila
  `x-data` memanggil komponen, nilai dari peladen dioper sebagai **argumen**
  (bukan dibaca lewat `$refs`, yang saling menimpa di dalam `foreach`). Dijaga
  assertion `I.13` + `J.10`–`J.12` (`qc_fase_5`) dan probe
  `tests/manual/js_investor_check.js`.
- **Modal talangan memakai komponen kasir yang SUDAH terdaftar.** Modal "Terima
  Dana Talangan" (`app/Views/operator/teller.php`) berada di dalam satu akar
  `x-data="tellerForm(…)"` yang juga membungkus kartu timbang, sehingga state-nya
  (`bridgeOpen`, `bridgeFeeType`, `bridgePrincipal`, …) hidup di komponen Fase 2
  yang sudah ada — **bukan** komponen `Alpine.data()` baru. Alasannya sama dengan
  butir di atas (aset `app.js` yang di-cache peramban tidak boleh membuat modal
  hilang), ditambah satu alasan uji: probe `js_bridge_fee_check.js` dapat
  memeriksa cermin fee melalui seam `registry.tellerForm` yang sudah dipakai
  `js_form_init_check.js`. Modal tetap `x-cloak` sehingga tidak menyisakan kotak
  hantu bila Alpine gagal dimuat.
- **Baris fee talangan tampil selama talangannya aktif — jangan ikatkan ke laba.**
  Pada ringkasan bagi hasil `/operator/settlement`, dua baris (`Fee talangan (n)` dan
  `Hak pengelola bersih`) dikendalikan `bridgeHasActive` (jumlah talangan aktif dari
  **peladen**, `bridge_count`); hanya peringatan "sebagian fee talangan melebihi
  hak laba pengelola" yang memakai kondisi turunan laba — `bridgeWarnUncovered`,
  yaitu `bridgeHasUncovered && inputValid` (turun dari angka laba; muncul hanya
  saat isian sudah sah). Nama ekspresi itu adalah kontrak yang diuji probe: jangan
  menggantinya dengan `bridgeHasUncovered` mentah maupun mengikatkan baris fee ke
  ekspresi turunan laba (temuan uji manual Fase 6: baris fee seolah hilang pada
  hari sehat). Dijaga assertion `G.13`–`G.16` (`qc_fase_6`) + probe
  `tests/manual/js_bridge_fee_check.js`, yang mengevaluasi ekspresi `x-show`
  **di markup** untuk tiga keadaan isian.
- **Isolasi peran kasir (Fase 7–8) bersifat *allow-list*, bukan *deny-list*.** Permukaan yang
  menerima peran `cashier` adalah daftar izin **eksplisit** dan berjumlah **dua** (sejak Fase 8):
  `TellerController` (`GET /operator/teller`, `POST /operator/teller`,
  `POST /operator/teller/talangan`, `POST /operator/opex/pay`) dan
  `KasbonController` (`GET /operator/kasbon`,
  `POST /operator/kasbon`, `POST /operator/kasbon/angsuran`, `POST /operator/kasbon/petani`),
  keduanya lewat `ALLOWED_ROLES = [ROLE_OPERATOR, ROLE_CASHIER]`. Setiap controller lain
  memakai `requireRole(ROLE_OPERATOR)`/`(ROLE_INVESTOR)` dan **tidak pernah** menyebut
  `ROLE_CASHIER`. Pelanggar dijawab `302` ke beranda perannya (`/operator/teller` untuk kasir),
  **tanpa satu pun tulisan**. Dijaga assertion `F.6` (`qc_fase_8`) + `F.3b` (`qc_fase_7`).
- **`requireRole(...)` adalah pernyataan PERTAMA setiap method publik controller (*guard-first*).**
  Ia mendahului pemeriksaan CSRF kedua, `Security::consumeFormToken()`, dan transaksi basis
  data — sehingga permintaan terlarang tidak menulis apa pun **dan tidak mengonsumsi token
  `_once`** pengirimnya. Berlaku untuk GET maupun POST. Dijaga assertion `D.5`/`F.4` (`qc_fase_7`).
- **Permukaan kasir tidak boleh menyentuh data pemilik.** Berkas yang terjangkau sesi kasir
  (`TellerController`, `KasbonController`, `Views/operator/teller.php`,
  `Views/operator/kasbon.php`, `Views/layouts/operator.php`, `Views/cashier/login.php`,
  `TellerService`, `BridgeLoanService`, `KasbonService`, `Miner`/`MinerLoan`/`MinerLoanLedger`)
  dilarang menyebut `BRANKAS_NAME`/`findBrankas`, `CapitalWallet`, `SystemSetting`, serta istilah
  `laba bersih`, `pool mitra`, `omzet`, `net_settled_profit`, `profit_distributions`.
  **Pengecualian sempit (Fase 8):** `KasbonService` boleh membaca `SystemSetting` **hanya** untuk
  `SETTING_DAILY_LIMIT` — batas operasional yang memang perlu diketahui kasir — dan tidak untuk
  kunci lain (dijaga assertion `G.5` `qc_fase_8`, pemindai baris). Navigasi
  Rekonsiliasi/Kelola Modal/Kelola Kasir/Dashboard disembunyikan bagi kasir di
  `Views/layouts/operator.php`. Dijaga assertion `E.1`–`E.8` (`qc_fase_7`).
- **Dua pintu PIN, satu pemilik aturan.** `Services/PinLoginService.php` memegang bentuk PIN,
  `PinThrottle`, gerbang peran, pesan generik, dan `Logger` untuk pintu mitra **dan** pintu
  kasir; controller pintu hanya merender/mengalihkan. Jangan menambah pintu PIN baru tanpa
  memakai service ini (`Security::verifyPin()` tetap satu-satunya verifikator).
- **Pemetaan peran punya satu pemilik**: `BaseController::roleLandingPath()` (beranda) dan
  `BaseController::roleLoginPath()` (pintu setelah keluar); identitas pengguna di view
  disuntikkan `render()` sebagai `$auth` (layout tidak membaca `$_SESSION` sendiri).
- **`users.role` ENUM bersifat *append-only*.** Nilai baru hanya boleh ditambahkan **di ujung**
  daftar (`ENUM('operator','investor','cashier')`); MySQL/MariaDB menyimpan ENUM sebagai indeks
  ordinal sehingga menyisipkan atau mengurutkan ulang nilai akan memetakan ulang arti baris lama.
  Instalasi lapangan menjalankan `php database/migrate.php` (terjaga `INFORMATION_SCHEMA`,
  idempoten — dimiliki `User::ensureRoleEnum()`); `QcFixture::ensureInstalled()` memanggilnya.
- **Akun kasir bukan mitra modal.** Akun `cashier` tidak pernah mendapat baris
  `capital_wallets`, tidak dihitung `AllocationEngine`, dan tidak menerima bagi hasil. PIN kasir
  disimpan ber-pepper pada kolom `users.password_hash` yang sudah ada (nol kolom baru) dan
  **tidak pernah** sah di pintu kata sandi maupun pintu PIN mitra. Dijaga assertion `A.8`/`C.9`/`C.10`.
- **Mutasi identitas kasir (Kelola Kasir) juga memakai `_once` + PRG.** `POST /operator/kasir`,
  `/operator/kasir/pin`, dan `/operator/kasir/status` wajib CSRF + token sekali-pakai; PIN tidak
  pernah dirender kembali. `APP_PIN_PEPPER` kosong → pembuatan akun **gagal dengan pesan**, bukan
  menyimpan PIN tanpa pepper (fail-closed). Dijaga assertion `B.3`/`B.4`/`B.9`/`B.11`.
- **Mitra lapangan / aparat (Fase 10) — dua tabel baru, nol ALTER.** `officers`
  (akun buku: `user_id` 1:1, `cut_pct`, `balance_payable`) dan
  `officer_ledger_entries` (riwayat: `cut`/`bonus`/`disbursement`). Instalasi
  lapangan menjalankan `php database/migrate.php` (**M4**: ENUM `'officer'` di
  ujung `users.role`, dua tabel baru, kunci `officer_cut_total_cap_pct`).
- **Dua kanal pemotongan laba, masing-masing SEKALI.** Potongan persentase
  (`cut`) dibebankan saat **akrual** tutup buku dari `max(0, laba kotor)` —
  `floor((basis_sen × pct_perseratus + 5000) / 10000)`; bonus manual (`bonus`)
  dibebankan saat **penyerapan akrual** (`settlement_id` diisi tutup buku
  berikutnya). Keduanya **bukan** voucher `operational_expenses` dan tidak
  pernah ditulis sebagai voucher — itu akan memotong laba dua kali.
  `total_cash` tetap = pendapatan toko, sehingga `kas` memuat uang yang
  terparkir di laci untuk petugas (`meja = baseline + Rec + Σ balance_payable`).
- **Pencairan hak = kas keluar + saldo turun, TIDAK PERNAH beban.** Satu
  `Database::transaction()`: kunci pos kas terpilih (`kas_meja`/`brankas`,
  token daftar putih — bukan UUID) → kunci baris `officers` → cek saldo hak &
  saldo pos → mutasi `out` (`Hak petugas: <nama>`) → saldo pos turun → entri
  `disbursement` (`register_id` + `mutation_id`, UNIQUE) → saldo hak turun.
- **Potongan tidak pernah melebihi laba kotor** (`cap = max(0, laba kotor)`;
  hari minus → potongan 0) dan **Σ tarif** dibatasi
  `system_settings.officer_cut_total_cap_pct` (fail-closed `422`). Kombinasi
  beban + hak petugas > laba kotor **diizinkan** (keputusan pemilik sistem,
  D-2) tetapi dicatat `Logger::warning`.
- **`officer_ledger_entries.settlement_id` memakai `ON DELETE RESTRICT`**
  (beda dari `operational_expenses` yang `SET NULL`): `SET NULL` mengaktifkan
  ulang bonus yang sudah terserap → laba terpotong dua kali. Konsekuensi QC:
  `QcFixture::resetOfficerState()` (entri → `officers` → akun `0896%`) dipanggil
  **paling awal** oleh `resetTellerState()`/`clearSettlementTraces()`.
- **`ROLE_OFFICER` adalah permukaan *allow-list* dua halaman GET**:
  `/officer/dashboard` + `/officer/riwayat` (tanpa POST sama sekali). Berkasnya
  (`Controllers/OfficerPortalController.php`, `Services/OfficerPortalService.php`,
  `Views/officer/*`, `Views/layouts/officer.php`) **dilarang** menyebut
  `HuluTransaction`, `hulu_transactions`, `farmer_alias`, `total_shop_revenue`,
  `net_settled_profit`, `operator_share_80`, `investor_pool_20`,
  `daily_coordination_fee`, `carryover_deficit`, `CapitalWallet`, `SystemSetting`,
  `CashRegister`, `CashMutation`, `BridgeLoan`, `MinerLoan`, `OperationalExpense`,
  `DailySettlement`, `AllocationEngine`, `SettlementService`, `TellerService`,
  `SELECT *`, **`cut_pct`**, **`base_amount`**, serta istilah tampilan "omzet",
  "laba kotor", "laba bersih", "pool", "hulu", "talangan", "kasbon".
  Tarif & basis tidak pernah dirender karena `potongan ÷ tarif = laba kotor`.
  Dijaga assertion `H.1`–`H.6` (`qc_fase_10`).
- **Pintu PIN mitra lapangan** `/officer/login` memakai `PinLoginService`
  (satu pemilik aturan: bentuk PIN, `PinThrottle`, gerbang peran, pesan
  generik) dan PIN-nya ber-pepper sehingga **tidak sah** di `/login`,
  `/cashier/login`, maupun `/investor/login`. Pemetaan beranda/pintu per peran
  tetap satu pemilik: `BaseController::roleLandingPath()`/`roleLoginPath()`.
- **Mutasi Fase 10 (`/operator/petugas*`: akun, tarif, PIN, status, bonus,
  pencairan) memakai `_once` + PRG**, `requireRole(ROLE_OPERATOR)` sebagai
  pernyataan pertama, dan nominal hasil hitung (saldo hak, potongan,
  `balance_after`) selalu dihitung peladen.
- **Kasbon petani (Fase 8) — tiga tabel baru, nol ALTER.** `miners` (profil),
  `miner_loans` (satu baris per pencairan + `balance_remaining`), dan
  `miner_loan_ledger` (riwayat kas, **satu baris kas = satu baris ledger** lewat
  `mutation_id`). Instalasi lapangan menjalankan `php database/migrate.php`
  (migrasi **M2**, terjaga `INFORMATION_SCHEMA`, idempoten — dimiliki
  `Miner::ensureSchema()` + `SystemSetting::ensure()`); `QcFixture::ensureInstalled()`
  memanggil keduanya. `miner_loan_ledger.mutation_id` memakai
  `ON DELETE RESTRICT`, jadi pembersihan QC **wajib** berurutan:
  `miner_loan_ledger` → `miner_loans` → `miners` **sebelum** `cash_mutations`
  dihapus (`QcFixture::resetKasbonState()` dipanggil lebih dulu oleh
  `resetTellerState()`/`clearSettlementTraces()`, pola `resetBridgeState()`).
- **Pencairan kasbon = dua lapis validasi di dalam satu transaksi.** Kunci
  `cash_registers` (`lockTellerForUpdate()`) → kunci baris
  `system_settings.daily_loan_limit` (`getForUpdate()`) → hitung
  `Σ pokok issued_on = hari ini` → (a) `Σ + pokok ≤ daily_loan_limit`,
  (b) `pokok ≤ saldo kas fisik` → `miner_loans` + `miner_loan_ledger` +
  `cash_registers` (turun) + `cash_mutations` (`out`). Pelanggaran → `422`
  tanpa tulisan. `daily_loan_limit = 0` berarti kasbon **dinonaktifkan**;
  nilai rusak/negatif → fail-closed. Batas bersifat **inklusif**.
- **Angsuran kasbon FIFO, satu mutasi kas.** `KasbonService::repay()` mengunci
  `miner_loans` `status='open'` milik petani (`issued_on ASC, created_at ASC,
  id ASC`), memotong yang tertua lebih dulu, menulis **satu** baris ledger per
  pinjaman tersentuh, dan **satu** `cash_mutations` `in` sebesar total. Nominal
  > sisa utang → `422` tanpa tulisan; pelunasan → `status='settled'` +
  `settled_at`.
- **Potong nota tidak memotong nota.** `hulu_transactions` **tidak** diubah:
  pembelian hulu dibayar penuh dan angsuran (`payment_method='nota'`) dicatat
  sebagai kas masuk tersendiri, sehingga efek netto laci sama dengan uang yang
  diserahkan sementara `modal belanja hulu`, laba, dan pool mitra 20 % tidak
  terdistorsi. Karena itu piutang petani **bukan** modal belanja hulu dan
  **bukan** beban — settlement tidak pernah menyentuhnya.
- **Nominal kasbon tidak pernah dipercaya dari klien.** Form hanya mengirim
  pemilih petani (`miner_id`/`miner_label`), nominal, metode, dan catatan;
  sisa utang, batas harian, dan `balance_after` dihitung ulang peladen.
  `KasbonService::resolveMiner()` menyelesaikan petani empat tingkat (UUID sah →
  label kandidat → nama eksak → `422`), sehingga form tetap benar tanpa
  JavaScript. Parsing uang didelegasikan ke `SettlementService::parseMoneyInput()`
  dan konversi ke `TellerService::moneyToCents()`/`centsToDecimal()` — tidak ada
  salinan parser ketiga.
- **Aset frontend selalu lokal** (tanpa CDN); `resources/` hanya sumber build
  dan tidak boleh disajikan lewat web.
- Route table di `app/routes.php` (data saja); dispatch di `public/index.php`.
  Handler wajib turunan `BaseController` dengan method yang benar-benar ada.

## Perintah

```bash
php -l <file.php>            # lint; tidak ada phpstan/phpcs di proyek ini

bash tests/run_qc.sh         # QC lengkap — gerbang kelulusan (exit 0 = lulus)
php tests/qc_seed.php        # satu suite saja
php tests/qc_fase_9.php      # suite beban operasional (OPEX) saja
php tests/qc_fase_10.php     # suite buku hak mitra lapangan & portal blind
php tests/qc_fase_8.php      # suite buku kasbon petani saja
php tests/qc_fase_7.php      # suite peran kasir saja

# Probe lapis Alpine (butuh Node; TIDAK bagian dari run_qc.sh)
node tests/manual/js_form_init_check.js
node tests/manual/js_capital_simulator_check.js
node tests/manual/js_investor_check.js
node tests/manual/js_bridge_fee_check.js
node tests/manual/js_kasbon_check.js
node tests/manual/js_settlement_officer_check.js

# Server pengembangan: meniru .htaccess (403 untuk dotfile & path traversal)
php -S 127.0.0.1:8000 -t public tests/server_router.php

# Kompilasi ulang CSS tema "Obsidian Gold" (hasilnya di-commit)
npx --yes tailwindcss@3.4.19 -c tailwind.config.js \
  -i resources/css/tailwind.input.css -o public/assets/css/app.min.css --minify

php database/install.php     # instalasi; INSTALL_TOKEN wajib diisi, jika tidak exit 1
php database/migrate.php     # migrasi aditif lapangan (M1: ENUM users.role; M2: tabel
                             # kasbon + daily_loan_limit; M3: operational_expenses;
                             # M4: ENUM 'officer' + officers/officer_ledger_entries +
                             # officer_cut_total_cap_pct) — idempoten, gate INSTALL_TOKEN,
                             # tidak menyentuh storage/installed.lock
```

Peringatan yang sudah terverifikasi:

- `bash tests/run_qc.sh` **menulis** ke database yang ditunjuk `.env` (skema,
  seed, akun operator, **akun kasir uji**, saldo kas, transaksi hulu, sesi settlement, dompet modal,
  distribusi dividen, permohonan penarikan, **dana talangan**, **petani kasbon +
  pinjaman + ledger piutang**, **voucher beban operasional**, kolom onboarding
  `users`, baris uji)
  **dan ke disk** (`public/uploads/receipts/`, `storage/throttle/`).
  Jangan arahkan ke produksi.
  Menulis: `qc_seed`, `qc_roadmap_protocol_1`, `qc_fase_2`, `qc_fase_3`,
  `qc_fase_4`, `qc_fase_5`, `qc_fase_6`, `qc_fase_7`, `qc_fase_8`, `qc_fase_9`,
  `qc_sql_injection`, `qc_routing`.
  Murni: `qc_pdo`, `qc_formatter`, `qc_security`.
- `qc_fase_5` menulis kolom onboarding `users` (hash PIN, `onboarding_signed_at`,
  `signed_ip_address`, `signed_user_agent`, `invite_token`), membuat
  `profit_distributions` + `withdrawal_requests`, dan mengisi
  `storage/throttle/`. Fixture (`QcFixture::seedQcInvestor()`,
  `resetInvestorState()`, `clearPinThrottle()`) mengembalikan keadaan itu,
  sehingga suite dapat dijalankan berulang. **Ia menyimpan `APP_PIN_PEPPER`
  sementara** bila `.env` belum mengisinya — jangan hapus jalur itu tanpa
  mengganti mekanismenya, karena seluruh pembuatan PIN bergantung padanya.
- `qc_fase_2` menyetel saldo "Kas Meja Bakar" ke Rp 50.000.000 dan mengosongkan
  `hulu_transactions` + `cash_mutations` pos itu (`QcFixture::resetTellerState()`),
  jadi suite dapat dijalankan berulang. Ia juga menambah akun investor uji.
- `qc_fase_6` menyentuh `bridge_loans` **dan** saldo Kas Meja Bakar (penerimaan
  talangan menambah kas, pengembalian pada tutup buku menguranginya). Fixture
  `QcFixture::resetBridgeState()`/`seedBridgeLoan()` mengembalikan keadaan itu,
  dan **`resetTellerState()` juga memanggilnya** — tanpa itu, talangan lama tetap
  `active` padahal mutasi kas masuknya sudah dihapus, sehingga suite memeriksa
  pengembalian yang tidak pernah diterima. `bridge_loans` memakai FK
  `ON DELETE RESTRICT` ke `daily_settlements`, jadi pembersihan data QC **wajib**
  berurutan: `bridge_loans` → `profit_distributions` → `daily_settlements` →
  `hulu_transactions`.
- `qc_fase_3` **juga menyentuh disk**: foto nota tiruan disalin ke
  `public/uploads/receipts/`. Fixture
  (`QcFixture::resetSettlementState()`, `resetSingleHoldingState()`,
  `clearSettlementTraces()`, `purgeReceiptFiles()`) membangun ulang keadaan
  akhir QC 2 dan membersihkan berkas nota, jadi suite dapat dijalankan berulang.
  `QcHttp::request()` menerima opsi `files` untuk mengirim multipart (tanpa
  kamera) — `CURLFile`, bukan query string.
- `qc_fase_7` menyentuh **akun kasir** (`users` berawalan `0897`), skema
  `users.role`, `storage/throttle/` (uji `429`), dan memakai akun/dompet QC
  Fase 2–6 apa adanya (dibandingkan snapshot, **tidak** diubah). Fixture
  `QcFixture::seedQcCashier()`, `resetCashierState()`,
  `purgeQcCashierAccounts()`, `clearPinThrottle()`. Akun kasir tidak punya
  `capital_wallets`, sehingga pembersihannya tidak memerlukan urutan FK seperti
  mitra. Suite ini **wajib** mengakhiri dengan `clearPinThrottle()` agar uji
  `401`/`429` yang disengaja tidak meracuni suite berikutnya.
- `qc_fase_10` menulis buku hak mitra lapangan (`officers`,
  `officer_ledger_entries`), akun `0896%`, saldo Kas Meja Bakar/Brankas,
  `cash_mutations`, voucher OPEX, dan sesi tutup buku. Fixture
  `QcFixture::resetOfficerState()`/`seedQcOfficer()`/`seedQcBonus()` +
  `officerSnapshot()` mengembalikan keadaan; suite membersihkan diri di akhir
  sehingga suite berikutnya melihat keadaan kas yang sama. `resetTellerState()`
  dan `clearSettlementTraces()` **memanggil `resetOfficerState()` paling awal**.
- `qc_fase_4` menyentuh dompet modal: fixture `QcFixture::resetCapitalState()`
  + `seedQcWallets()` mengosongkan `profit_distributions`/`withdrawal_requests`
  dan mengembalikan saldo, kuota, serta status dompet QC (Pengelola 30 juta,
  Mitra Prioritas 16 juta/kuota 20 %, Mitra Reguler A 20 juta, Mitra Reguler B
  14 juta), sehingga suite dapat dijalankan berulang. Ia juga menggerakkan kas
  `Brankas Utama` (setoran, pindah kas, penarikan) dan membersihkan akun mitra
  uji berawalan `0899`.
- **Dompet modal buatan manual dinetralkan, bukan dihapus.** `schema.sql` memakai
  `CREATE TABLE IF NOT EXISTS` (tanpa `DROP`), sehingga data pengujian lapangan
  tetap ada antar-jalankan QC. Karena `AllocationEngine` membaca SEMUA dompet,
  `QcFixture::clearSettlementTraces()`/`resetCapitalState()` memanggil
  `neutraliseWallets()` yang menolkan saldo, dividen, kuota, dan status penarikan
  **seluruh** dompet (barisnya tetap ada), sama seperti `resetTellerState()`
  menolkan saldo Kas Meja Bakar. Tanpa itu, dompet manual berkuota akan menyerap
  modal dan menggeser angka protokol (dijaga assertion `G.5`/`G.6` `qc_fase_4`).
- `php -S -t public` **tanpa** `tests/server_router.php` masih bisa membuka
  halaman (server bawaan PHP jatuh ke `index.php`), tetapi menjawab 404 — bukan
  403 — untuk `.env` dan path traversal. Pakai router agar setara `.htaccess`;
  `QcHttp` di harness QC juga bergantung padanya.
- `database/install.php` **wajib dihapus** dari hosting setelah instalasi dan
  `INSTALL_TOKEN` dikosongkan. `storage/installed.lock` menolak instalasi ulang.
- Mesin dev ini memakai **MariaDB**, bukan MySQL 8.0. QC belum diverifikasi
  pada MySQL 8.0 — item A-1 pada `plan/Summary_Fase_1_AurumFlow.md`.
- Suite QC mengikuti DB di `.env`, jadi target QC berubah bila `.env` diubah.
  Pastikan tujuannya database uji, bukan data yang Anda sayangi.

## Kriteria selesai

- `bash tests/run_qc.sh` exit 0, dan tidak ada `storage/logs/php-*.log`.
- Perubahan skema disertai pembaruan ERD di `docs/` serta seed/QC terkait.
- Tidak ada kode mati: setiap method publik baru punya pemanggil nyata, atau
  assertion QC yang mengujinya. Ini yang menyebabkan `User::findById()` dan
  `User::userAgent()` dihapus, dan `Formatter::tanggalId()` dipakai dashboard.
- `git status` bersih, tanpa artefak (`tests/.tmp/`, `storage/installed.lock`,
  `*.log`).

Baseline saat ini: **16 suite, 989 assertion, 0 gagal**. Rincian dan item
terbuka ada di `plan/Summary_Fase_9_OPEX_AurumFlow.md` (lalu `Summary_Fase_8`), `Summary_Fase_7`, `Summary_Fase_6`,
`Summary_Fase_5`, `Summary_Fase_4`, `Summary_Fase_3`, `Summary_Fase_2`, dan
`Summary_Fase_1`), serta `plan/Plan_Fix_Settlement_UI.md`,
`plan/Summary_Fix_Disabled_Inputs.md`, dan
`plan/Summary_Fix_Investor_Login_Alpine.md` untuk perbaikan UX/form pascarilis.
Unifikasi kerangka 2-tier pengelola (`/dashboard` + seluruh layar `/operator/*`
memakai `layouts/operator.php`) dijelaskan di `plan/Plan_Unify_UI_AurumFlow.md`
dan `plan/Summary_Unify_UI_AurumFlow.md`.

## Menambah test

Ikuti pola `tests/qc_*.php`: `require __DIR__ . '/bootstrap.php'`, pakai
`Qc::ok()/equals()/throws()`, akhiri dengan `Qc::finish('<nama_suite>')`
(exit 0/1), lalu daftarkan di array `SUITES` pada `tests/run_qc.sh`.

Fixture Fase 5 ada di `QcFixture::seedQcInvestor()` (mitra **sudah** onboarding,
PIN aktif, dompet Rp 20 juta + empat putaran distribusi), `seedQcInvitee()`
(calon mitra: tautan aktif, belum tanda tangan), dan `resetInvestorState()`.
Keduanya idempotent dan wajib dipanggil berpasangan (yang pertama menetralkan
seluruh dompet, yang kedua menanam dompet calon mitra sesudahnya). `qc_fase_5`
menyuntik `APP_PIN_PEPPER` lewat `putenv()` sebelum harness dimuat supaya suite
berperilaku sama pada clone yang `.env`-nya belum memuat pepper.

Fixture Fase 7 ada di `QcFixture::seedQcCashier()` (akun kasir `089700000101`,
PIN aktif, **tanpa** dompet modal), `resetCashierState()`, dan
`purgeQcCashierAccounts()`. Karena kasir tidak punya dompet/penarikan/distribusi,
pembersihannya cukup satu `DELETE` berawalan `0897%` — tidak ada urutan FK
seperti mitra.

Fixture Fase 8 ada di `QcFixture::resetKasbonState()` (hapus `miner_loan_ledger` →
`miner_loans` → `miners`, idempoten), `seedQcMiner()` (petani `QC …`),
`seedQcKasbon()` (pencairan lewat **jalur nyata** `KasbonService::disburse()`,
bukan INSERT langsung), dan `kasbonSnapshot()` (potret untuk assertion "nol
tulisan"). `qc_fase_8` berjalan **setelah** `qc_fase_7` dan membersihkan diri,
sehingga suite berikutnya melihat keadaan kas yang sama.

Fixture Fase 9 ada di `QcFixture::resetExpenseState()` (hapus `operational_expenses`
lalu mutasi kas miliknya, idempoten), `seedOpexVoucher()` (beban lewat **jalur
nyata** `ExpenseService::pay()`, bukan INSERT langsung), dan `opexSnapshot()`
(potret untuk assertion "nol tulisan"). `qc_fase_9` menulis saldo Kas Meja Bakar,
`cash_mutations`, `operational_expenses`, dan sesi tutup buku, lalu membersihkan
diri di akhir sehingga suite berikutnya melihat keadaan kas yang sama. Suite Fase
3/4/6 memakai `seedOpexVoucher()` untuk mempertahankan basis angka "beban Rp
200.000" — sejak Fase 9 angka itu datang dari voucher, bukan dari isian form.

Fixture Fase 10 ada di `QcFixture::resetOfficerState()` (hapus **seluruh** entri
buku hak → `officers` berawalan `0896%` → akun `0896%`; petugas buatan manual
dinetralkan `neutraliseOfficers()`), `seedQcOfficer()` (akun `089600000101`,
PIN aktif, `cut_pct = 2,50`), `seedQcBonus()` (bonus lewat jalur nyata
`OfficerService::injectBonus()`), dan `officerSnapshot()` (potret untuk assertion
"nol tulisan"). **Prefiks `0896%`** dipakai karena `0897%` milik kasir, `0898%`
milik mitra pemodal, dan `0895%` milik petani. `resetOfficerState()` dipanggil
**paling awal** oleh `resetTellerState()` dan `clearSettlementTraces()` —
`officer_ledger_entries.settlement_id` memakai FK RESTRICT ke
`daily_settlements`.

Membersihkan data QC berurutan: `capital_wallets.user_id`,
`withdrawal_requests.wallet_id`, dan `profit_distributions.wallet_id` memakai FK
`ON DELETE RESTRICT`, jadi baris anak harus dibuang lebih dulu (lihat
`QcFixture::purgeQcInvestorAccounts()` dan pembersihan di akhir `qc_seed`).
Sejak Fase 6 urutan itu ditambah satu langkah di depan: `bridge_loans.settlement_id`
juga memakai `ON DELETE RESTRICT` ke `daily_settlements`, jadi
`QcFixture::resetBridgeState()` (dipanggil `resetTellerState()` dan
`clearSettlementTraces()`) wajib berjalan sebelum `daily_settlements` dihapus.
Sejak Fase 8 ada satu langkah lagi: `miner_loan_ledger.mutation_id` memakai
`ON DELETE RESTRICT` ke `cash_mutations`, jadi `QcFixture::resetKasbonState()`
dipanggil **paling awal** oleh `resetTellerState()` dan `clearSettlementTraces()` —
sebelum mutasi kas pos kas meja dihapus.
Sejak Fase 9 ada satu langkah tambahan: `operational_expenses.mutation_id` juga
memakai `ON DELETE RESTRICT`, jadi `QcFixture::resetExpenseState()` dipanggil
**paling awal** (bersama `resetKasbonState()`) oleh fixture yang sama. Sejak
Fase 10 ada satu lagi: `officer_ledger_entries` memakai RESTRICT ke
`daily_settlements` **dan** `cash_mutations`. Urutan pembersihan lengkap:
`officer_ledger_entries` → `officers` → (akun `0896%`) → `operational_expenses`
→ `miner_loan_ledger` → `miner_loans` → `miners` → `bridge_loans` →
`profit_distributions` → `daily_settlements` → `cash_mutations`.

Pemindaian statis memakai `qc_scan_lines()`, yang **melewati baris komentar** —
jangan mengandalkan komentar untuk menyembunyikan pelanggaran. Perhatikan bahwa
predikatnya hanya menerima isi baris (tanpa path), sehingga untuk memeriksa
berkas tertentu saja gunakan pemindai lokal seperti `qc2_scan()` pada
`tests/qc_fase_2.php` atau `qc3_scan()` pada `tests/qc_fase_3.php`.

`created_at` berpresisi detik (item O-1), jadi **jangan** mengandalkan "baris
terbaru" untuk mengenali baris yang baru ditulis: dua tulisan pada detik yang
sama dapat berurutan acak. `qc_fase_3.php` mengenali baris baru lewat
`qc3_settlement_ids()` + `qc3_new_settlement()`, dan memfilter `mutation_type`
saat membaca mutasi kas. Untuk gambar uji, `qc3_png()` membangun PNG tanpa
ekstensi GD (tidak tersedia di semua host).

Untuk menjalankan skrip PHP lain dengan environment yang ditimpa, pakai
`qc_run_php()`. **Jangan** memanggil `proc_open()` sendiri dengan array env:
PHP membuang variabel yang bernilai string kosong, sehingga override seperti
`['INSTALL_TOKEN' => '']` sampai ke anak dalam keadaan tidak ada dan test
diam-diam menguji nilai dari `.env`. `qc_run_php()` menerapkan override lewat
`putenv()` di dalam proses anak agar nilai kosong benar-benar berlaku.

Untuk test session di CLI: panggil `ob_start()` sebelum output apa pun.
`session_regenerate_id()` dan `setcookie()` gagal setelah header terkirim.

## Status & lingkup fase

- **Fase 1 (selesai):** fondasi — PDO, routing, keamanan, formatter, skema 9
  tabel, installer.
- **Fase 2 (selesai):** meja kasir hulu — `/operator/teller` (GET+POST),
  `TellerService` (transaksi atomik kas), tema Tailwind "Obsidian Gold" untuk
  seluruh view, Alpine.js lokal.
- **Fase 3 (selesai):** likuidasi toko & mesin settlement harian —
  `/operator/settlement` (GET+POST), `SettlementService` (tutup buku atomik,
  Smart Auto-Split, Deficit Carry-Over Engine), unggah foto nota dengan
  kompresi HTML5 Canvas di peramban (< 250 KB) dan validasi peladen.
- **Fase 4 (selesai):** mesin alokasi modal & tata kelola likuiditas —
  `/operator/capital` (GET + 5 POST), `AllocationEngine` (waterfall 50 % → kuota
  Mitra Prioritas → pro-rata Mitra Reguler, sen integer + sisa terbesar),
  distribusi pool 20 % ke `capital_wallets.balance_profit` +
  `profit_distributions` di dalam transaksi settlement, setoran modal atomik ke
  `Brankas Utama`, Pindah Kas satu arah Brankas → Kas Meja Bakar, dan antrean
  penarikan dengan jatuh tempo H-7 hari kerja beserta eksekusi bayarnya.
- **Fase 5 (selesai):** dasbor blind investor, waiver digital, & PWA —
  `/join` (klaim tautan undangan sekali pakai + clickwrap waiver + PIN 6 digit
  ber-pepper), `/investor/login` (pintu PIN + pembatas percobaan),
  `/investor/dashboard` (Layar D: Modal Berputar vs Kas Cadangan), penarikan
  mandiri mitra (`profit` tanpa masa tunggu, `capital` H-7 hari kerja),
  `/investor/report` + `/investor/report/csv` (lihat & cetak / unduh CSV),
  pembuatan tautan undangan operator (`POST /operator/capital/invite`),
  dan PWA (`manifest.json`, `sw.js` cache aset statis, `offline.html`, ikon).
- **Fase 6 (selesai):** modul dana talangan (*bridge liquidity*) —
  `bridge_loans` (tabel ke-10, nol ALTER pada tabel lama), modal **"Terima Dana
  Talangan"** di `/operator/teller` (`POST /operator/teller/talangan`,
  `BridgeLoanService::receive()` — tiga tulisan satu transaksi), fee **nominal
  atau persen** yang selalu dihitung peladen (sen integer, half away from zero),
  kartu "Talangan Aktif & Beban Fee" di `/operator/settlement`, dan **auto-pay**
  di dalam `SettlementService::settle()`: pokok + fee kembali dari Kas Meja
  Bakar, fee diserap **hanya** dari `operator_share_80`
  (`fee_uncovered` bila hak pengelola tidak cukup), `investor_pool_20` tidak
  tersentuh, talangan belum lunas tetap `active` (FIFO).
- **Fase 7 (selesai):** modul kasir meja bakar (*strict role separation*) —
  peran ketiga `cashier` pada `users.role` (**aditif di ujung ENUM**, nol tabel
  baru, nol kolom baru), pintu PIN cepat `/cashier/login`
  (`CashierAuthController`, papan angka `investorLogin` yang sudah terdaftar),
  satu pemilik aturan pintu PIN (`PinLoginService`, dipakai mitra **dan** kasir),
  **isolasi *allow-list*** (`TellerController::ALLOWED_ROLES` = meja kasir +
  talangan; semua rute lain `302` ke `/operator/teller` tanpa tulisan, GET
  maupun POST), layar operator **"Kelola Kasir"** (`/operator/kasir`: buat akun,
  reset PIN, aktif/nonaktif), navbar sadar peran, dan `database/migrate.php`
  (migrasi lapangan terjaga + idempoten).
- **Fase 8 (selesai):** modul kasbon / piutang petani (*miner loan & ledger*) —
  tiga **tabel baru** `miners`, `miner_loans`, `miner_loan_ledger` (nol ALTER),
  satu kunci `system_settings.daily_loan_limit`, halaman **`/operator/kasbon`**
  (pencairan + angsuran tunai/potong nota + pendaftaran petani + buku besar) dan
  tab **Buku Kasbon** (ke-6 operator, ke-2 kasir), pencairan dengan **dua lapis
  validasi** (batas harian + saldo fisik laci) dalam satu transaksi ber-kunci,
  angsuran **FIFO** satu mutasi kas, autocomplete `<datalist>` + komponen Alpine
  `kasbonForm` (tombol 20 %/30 %/Lunas), `CACHE_NAME` naik ke
  **`aurumflow-shell-v3`**, dan `database/migrate.php` migrasi **M2**.
- **Fase 9 (selesai):** modul beban operasional non-emas / OPEX — **tabel baru
  ke-14** `operational_expenses` (nol ALTER), `ExpenseService::pay()` (kunci pos
  kas → cek saldo → mutasi `out` → saldo turun → voucher) dipakai tombol
  **"Catat Beban Kas"** di layar kasir (modal, bukan halaman baru), dan
  **otomatisasi settlement**: isian `coord_fee` dihapus, beban diturunkan
  peladen dari voucher yang belum terserap (`settlement_id IS NULL`) sehingga
  tidak dapat difiktifkan. Rumus berubah menjadi `total_cash = pendapatan toko`
  (bukan `pendapatan − beban`) supaya laci menerima penggantian atas uang yang
  sudah keluar; laba & bagi hasil 80 : 20 **tidak berubah**. `consumable_opex`
  (item O-3 warisan Fase 3) kini terisi dari voucher kategori `consumable`.
  `CACHE_NAME` naik ke **`aurumflow-shell-v4`** dan `database/migrate.php`
  migrasi **M3**.
- **Fase 10 (selesai):** modul mitra lapangan / aparat (*coordination ledger &
  blind portal*) — **dua tabel baru** `officers` (akun buku: `cut_pct`,
  `balance_payable`) dan `officer_ledger_entries` (`cut`/`bonus`/`disbursement`)
  plus nilai ENUM `'officer'` di ujung `users.role` (nol ALTER pada tabel lama).
  Potongan persentase dari **laba kotor** diakrualkan tiap tutup buku (dipotong
  **sebelum** bagi hasil 80 : 20), bonus manual dibebankan saat penyerapan
  akrual (tanpa voucher OPEX → nol deduksi ganda), pencairan hak = kas keluar
  satu mutasi `out` dari Kas Meja Bakar **atau** Brankas Utama, dan **portal
  blind** `/officer/dashboard` + `/officer/riwayat` (PIN `/officer/login`,
  hanya saldo hak & riwayatnya sendiri). `CACHE_NAME` naik ke
  **`aurumflow-shell-v5`**, `database/migrate.php` migrasi **M4**, dan suite
  baru `tests/qc_fase_10.php` (grup A–J).
- **Di luar lingkup sampai fase berikutnya:** pembayaran talangan di luar tutup
  buku (manual), fee per siklus bergulir, kuitansi/tanda terima bertanda tangan,
  pembatalan/*void* kasbon, bunga/denda keterlambatan, arsip/nonaktif petani,
  potong nota otomatis di form timbang (butuh `hulu_transactions.miner_id`),
  tarif talangan default di `system_settings`, mode alokasi `prorata`, panel admin
  penuh untuk `system_settings`, PDF asli di sisi peladen, ekspor lengkap sisi
  operator, notifikasi otomatis tautan undangan, reset PIN mandiri mitra **dan
  kasir**, pemaksaan ganti PIN pada login pertama kasir, pencabutan sesi kasir
  saat akun dinonaktifkan (butuh `users.session_epoch`), jadwal shift kasir,
  arsip versi waiver + riwayat persetujuan (butuh DDL), kalender hari libur untuk
  H-7 (Fase 6B), **anggaran bulanan OPEX + badge peringatan** (`monthly_coordination_budget`),
  **lampiran foto kuitansi per voucher beban**, **beban dari pos selain Kas Meja Bakar**
  (rumus umum `brankas = net + Σ fee_brankas`), **rekap/laporan OPEX operator**, dan
  **pembatalan/*void* voucher** (Fase 9 dicatat sebagai item terbuka O-9.1…O-9.5 pada
  `plan/Summary_Fase_9_OPEX_AurumFlow.md` § 9).

Skema 9 tabel siap sejak Fase 1; tabel ke-10 (`bridge_loans`) ditambahkan pada
Fase 6 sebagai **tabel baru**, tabel **11–13** (`miners`, `miner_loans`,
`miner_loan_ledger`) pada Fase 8, dan tabel **ke-14** (`operational_expenses`)
pada Fase 9, serta tabel **15–16** (`officers`, `officer_ledger_entries`) pada
Fase 10 — semuanya **tabel baru, nol ALTER**. Fase 7 tidak menambah tabel
maupun kolom: satu-satunya perubahan skema saat itu adalah nilai ENUM baru
`'cashier'` yang ditambahkan **di ujung** `users.role` lewat
`php database/migrate.php` (idempoten, terjaga `INFORMATION_SCHEMA`) — sehingga
instalasi lapangan tidak memerlukan penulisan ulang baris. Sejak Fase 8 skrip
migrasi itu juga menjalankan **M2** (tiga tabel kasbon + `daily_loan_limit`),
sejak Fase 9 **M3** (`operational_expenses`), dan sejak Fase 10 **M4** (ENUM
`'officer'` + dua tabel buku hak + `officer_cut_total_cap_pct`), sehingga total
skema menjadi **16 tabel**.

