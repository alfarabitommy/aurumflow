/* ==========================================================================
   AurumFlow — Service Worker (Fase 5)
   ==========================================================================
   Tujuan: membuat aplikasi dapat dipasang ke layar utama ("Tambahkan ke Layar
   Utama") dan tetap menampilkan kerangka tampilan saat jaringan buruk.

   ATURAN YANG TIDAK BOLEH DILANGGAR:

     1. Yang boleh masuk cache HANYA aset statis (daftar-putih di `SHELL`).
        HTML, JSON, dan CSV **tidak pernah** disimpan: halaman dasbor mitra
        menampilkan angka uang yang berubah setiap tutup buku, dan menyajikan
        saldo dari cache berarti menampilkan data basi.

     2. Permintaan yang bukan GET tidak pernah disentuh — mutasi (POST)
        selalu langsung ke jaringan.

     3. Navigasi memakai jaringan lebih dulu; bila gagal, halaman statis
        `offline.html` yang ditampilkan (tanpa data apa pun).

   PENTING saat mengubah daftar aset: naikkan `CACHE_NAME` supaya cache lama
   dibuang pada `activate`.
   ==========================================================================
   Fase 6: `app.js` dan `app.min.css` berubah (modal dana talangan + kelas
   `fixed`/`z-30`), jadi versi shell dinaikkan v1 -> v2. Tanpa kenaikan ini,
   operator yang pernah membuka layar operator tetap menerima `app.js` LAMA
   dari cache (aset statis dilayani cache-first), sehingga komponen
   `settlementForm` tidak mengenal `bridgeHasActive` dan baris fee talangan
   pada Layar C tetap tidak muncul — persis gejala yang dilaporkan pada uji
   manual Fase 6. Naikkan lagi setiap kali `app.js`/`app.min.css` berubah
   (dijaga assertion `qc_fase_6` G.17/G.18).
   ========================================================================== */

'use strict';

var CACHE_NAME = 'aurumflow-shell-v2';

/* Daftar-putih aset statis — relatif terhadap scope service worker. */
var SHELL = [
    './assets/css/app.min.css',
    './assets/js/app.js',
    './assets/js/alpine.min.js',
    './assets/fonts/plus-jakarta-sans-latin-400-normal.woff2',
    './assets/fonts/plus-jakarta-sans-latin-600-normal.woff2',
    './assets/fonts/plus-jakarta-sans-latin-700-normal.woff2',
    './assets/fonts/jetbrains-mono-latin-400-normal.woff2',
    './assets/fonts/jetbrains-mono-latin-700-normal.woff2',
    './assets/icons/icon-192.png',
    './assets/icons/icon-512.png',
    './assets/icons/icon-maskable-512.png',
    './assets/icons/apple-touch-icon-180.png',
    './offline.html'
];

/* Hanya jalur ini (di dalam scope) yang boleh dilayani dari cache. */
var STATIC_PREFIX = 'assets/';
var STATIC_EXACT = ['offline.html', 'manifest.json'];

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) {
            return cache.addAll(SHELL);
        }).then(function () {
            return self.skipWaiting();
        })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (names) {
            return Promise.all(names.map(function (name) {
                return name === CACHE_NAME ? null : caches.delete(name);
            }));
        }).then(function () {
            return self.clients.claim();
        })
    );
});

/**
 * Apakah permintaan ini boleh dilayani dari cache?
 *
 * Hanya GET, origin sama, dan URL-nya aset statis di daftar-putih. Semua
 * permintaan lain (halaman front controller, CSV, apa pun yang dinamis)
 * selalu ke jaringan.
 */
function isStaticAsset(request, url) {
    if (request.method !== 'GET') {
        return false;
    }

    if (url.origin !== self.location.origin) {
        return false;
    }

    var scopePath = new URL(self.registration.scope).pathname;

    if (url.pathname.indexOf(scopePath) !== 0) {
        return false;
    }

    var relative = url.pathname.slice(scopePath.length);

    if (STATIC_EXACT.indexOf(relative) !== -1) {
        return true;
    }

    return relative.indexOf(STATIC_PREFIX) === 0;
}

self.addEventListener('fetch', function (event) {
    var request = event.request;
    var url;

    try {
        url = new URL(request.url);
    } catch (error) {
        return;
    }

    /* 1. Navigasi halaman: jaringan lebih dulu, offline.html sebagai jatuhan. */
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(function () {
                return caches.match('./offline.html');
            })
        );

        return;
    }

    /* 2. Aset statis: cache lebih dulu, lalu jaringan (dan simpan salinannya). */
    if (isStaticAsset(request, url)) {
        event.respondWith(
            caches.match(request).then(function (cached) {
                if (cached) {
                    return cached;
                }

                return fetch(request).then(function (response) {
                    if (response && response.status === 200 && response.type === 'basic') {
                        var copy = response.clone();

                        caches.open(CACHE_NAME).then(function (cache) {
                            cache.put(request, copy);
                        });
                    }

                    return response;
                });
            })
        );

        return;
    }

    /* 3. Sisanya (HTML dinamis, CSV, POST): jaringan murni, tanpa cache. */
});
