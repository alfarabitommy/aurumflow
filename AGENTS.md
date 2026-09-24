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
  Peran hanya `operator` dan `investor`.
- **Angka untuk tampilan** hanya lewat `Formatter` (`rupiah()`, `gram()`,
  `tanggalId()`). Dilarang memanggil `number_format()` langsung.
- **Setiap output dinamis di view** wajib `Security::e(...)`.
- **Parameter bisnis** (kuota serapan, jasa bakar, masa tunggu penarikan)
  hidup di `system_settings`, **bukan** di `app/Config/Constants.php` —
  jangan buat dua sumber kebenaran.
- **Mutasi hanya via POST + token CSRF.** Front controller memeriksa CSRF
  untuk semua method non-GET sebelum dispatch, dan `AuthController` memeriksa
  sekali lagi sebagai pertahanan berlapis. Jangan hapus salah satunya.
- Route table di `app/routes.php` (data saja); dispatch di `public/index.php`.
  Handler wajib turunan `BaseController` dengan method yang benar-benar ada.

## Perintah

```bash
php -l <file.php>            # lint; tidak ada phpstan/phpcs di proyek ini

bash tests/run_qc.sh         # QC lengkap — gerbang kelulusan (exit 0 = lulus)
php tests/qc_seed.php        # satu suite saja

# Server pengembangan: meniru .htaccess (403 untuk dotfile & path traversal)
php -S 127.0.0.1:8000 -t public tests/server_router.php

php database/install.php     # instalasi; INSTALL_TOKEN wajib diisi, jika tidak exit 1
```

Peringatan yang sudah terverifikasi:

- `bash tests/run_qc.sh` **menulis** ke database yang ditunjuk `.env` (skema,
  seed, akun operator, baris uji). Jangan arahkan ke produksi.
  Menulis: `qc_seed`, `qc_roadmap_protocol_1`, `qc_sql_injection`, `qc_routing`.
  Murni: `qc_pdo`, `qc_formatter`, `qc_security`.
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

Baseline saat ini: **7 suite, 191 assertion, 0 gagal**. Rincian dan item
terbuka ada di `plan/Summary_Fase_1_AurumFlow.md`.

## Menambah test

Ikuti pola `tests/qc_*.php`: `require __DIR__ . '/bootstrap.php'`, pakai
`Qc::ok()/equals()/throws()`, akhiri dengan `Qc::finish('<nama_suite>')`
(exit 0/1), lalu daftarkan di array `SUITES` pada `tests/run_qc.sh`.

Pemindaian statis memakai `qc_scan_lines()`, yang **melewati baris komentar** —
jangan mengandalkan komentar untuk menyembunyikan pelanggaran.

Untuk menjalankan skrip PHP lain dengan environment yang ditimpa, pakai
`qc_run_php()`. **Jangan** memanggil `proc_open()` sendiri dengan array env:
PHP membuang variabel yang bernilai string kosong, sehingga override seperti
`['INSTALL_TOKEN' => '']` sampai ke anak dalam keadaan tidak ada dan test
diam-diam menguji nilai dari `.env`. `qc_run_php()` menerapkan override lewat
`putenv()` di dalam proses anak agar nilai kosong benar-benar berlaku.

Untuk test session di CLI: panggil `ob_start()` sebelum output apa pun.
`session_regenerate_id()` dan `setcookie()` gagal setelah header terkirim.

## Di luar lingkup Fase 1

Meja kasir, settlement, Waterfall/Pro-Rata Engine, dasbor investor, ekspor
laporan, upload nota, dan Tailwind/Alpine/PWA. Skema 9 tabel sudah siap,
sehingga fase berikutnya tidak memerlukan migrasi struktur.
