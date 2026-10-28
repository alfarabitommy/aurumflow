/* ==========================================================================
   AurumFlow — app.js
   ==========================================================================
   1. Konfirmasi form (data-confirm) + cegah submit ganda.
   2. Cermin format angka `Formatter` (Rupiah & gram) di sisi peramban.
   3. Parser input lapangan (berat, harga, persen, nominal) yang setara dengan
      TellerService / SettlementService.
   4. Komponen Alpine.js `tellerForm` untuk meja kasir hulu (Fase 2).
   5. Kompresi foto nota berbasis HTML5 Canvas (< 250 KB) — Fase 3.
   6. Komponen Alpine.js `settlementForm` untuk layar rekonsiliasi (Fase 3).
   7. Cermin alokasi modal `AurumAlloc` + komponen `capitalSimulator`
      untuk layar Kelola Modal (Fase 4).
   8. Komponen Alpine.js `investorLogin` (papan angka PIN) & `investorDashboard`
      untuk portal mitra (Fase 5).
   9. Komponen Alpine.js `kasbonForm` untuk Buku Kasbon petani (Fase 8):
      autocomplete `<datalist>`, tombol cepat 20 %/30 %/Lunas, pratinjau sisa.
  10. Registrasi Service Worker PWA (Fase 5) — hanya cache aset statis; HTML,
      JSON, dan CSV tidak pernah disimpan di cache.

   PENTING: seluruh angka yang DITAMPILKAN halaman tetap berasal dari server
   (`Formatter`). Fungsi di berkas ini hanya untuk pratinjau langsung saat
   operator mengetik; server selalu menghitung ulang lewat TellerService /
   SettlementService dan TIDAK mempercayai nilai apa pun dari klien.
   Kompresi Canvas juga hanya kemudahan: peladen tetap memvalidasi tipe &
   ukuran berkas (rencana Fase 3 § 4.6–§ 4.7).
   ========================================================================== */

(function () {
    'use strict';

    // -----------------------------------------------------------------------
    // 1. Perilaku form
    // -----------------------------------------------------------------------

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        var message = form.getAttribute('data-confirm');

        if (message && !window.confirm(message)) {
            event.preventDefault();
            return;
        }

        // Submit ganda: nonaktifkan tombol SETELAH validasi lulus supaya
        // nilai tombol tetap ikut terkirim bila diperlukan.
        var submitter = form.querySelector('button[type="submit"]');

        if (submitter) {
            window.setTimeout(function () {
                submitter.disabled = true;
            }, 0);
        }
    });

    // -----------------------------------------------------------------------
    // 2. Cermin Formatter (Rupiah & gramatur)
    // -----------------------------------------------------------------------

    /** Pemisah ribuan '.', sama seperti Formatter::rupiah(). */
    function groupThousands(digits) {
        return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    var AurumFormat = {
        /**
         * Sen -> `Rp 28.937.500` (0 desimal, pemisah ribuan '.').
         * Nilai negatif ditulis `-Rp 5.000` — sama dengan Formatter.
         */
        rupiah: function (cents) {
            var rounded = Math.round(Number(cents) || 0);
            var negative = rounded < 0;
            var absolute = Math.abs(rounded);

            // Pembulatan ke rupiah PENUH memakai half-up — sama persis dengan
            // `number_format(abs($value), 0)` pada `Formatter::rupiah()`.
            // Sebelum Fase 4 di sini dipakai `Math.floor`, sehingga nilai dengan
            // sen (mis. Rp 108.038,82) tampil Rp 1 lebih kecil daripada peladen.
            return (negative ? '-Rp ' : 'Rp ')
                + groupThousands(String(Math.round(absolute / 100)));
        },

        /** Miligram -> `14,850 gr` (3 desimal, koma desimal). */
        gram: function (milligrams) {
            var rounded = Math.round(Number(milligrams) || 0);
            var negative = rounded < 0;
            var absolute = Math.abs(rounded);
            var fraction = String(absolute % 1000);

            while (fraction.length < 3) {
                fraction = '0' + fraction;
            }

            return (negative ? '-' : '')
                + groupThousands(String(Math.floor(absolute / 1000)))
                + ',' + fraction + ' gr';
        }
    };

    // -----------------------------------------------------------------------
    // 3. Parser input lapangan (aturan identik dengan TellerService § 4.4)
    // -----------------------------------------------------------------------

    var AurumInput = {
        /**
         * Berat kering -> miligram. Satu pemisah ('.' atau ',') berarti
         * DESIMAL; tanpa pemisah ribuan. Mengembalikan 0 bila tidak sah.
         */
        weightMilligrams: function (text) {
            if (typeof text !== 'string') {
                return 0;
            }

            var value = text.trim();

            if (value === '' || value.indexOf('-') !== -1) {
                return 0;
            }

            var digits = value.replace(/[^0-9.,]/g, '');

            if (digits === '' || (digits.indexOf(',') !== -1 && digits.indexOf('.') !== -1)) {
                return 0;
            }

            var parts = digits.replace(/,/g, '.').split('.');

            if (parts.length > 2) {
                return 0;
            }

            var whole = parts[0];
            var fraction = parts[1] || '';

            if (!/^\d{1,5}$/.test(whole) || !/^\d{0,3}$/.test(fraction)) {
                return 0;
            }

            var padded = (fraction + '000').slice(0, 3);

            return parseInt(whole, 10) * 1000 + parseInt(padded, 10);
        },

        /**
         * Harga tebus -> sen. Titik adalah pemisah RIBUAN, koma desimal
         * (sejalan dengan Formatter::parseRupiah()).
         */
        priceCents: function (text) {
            if (typeof text !== 'string') {
                return 0;
            }

            var value = text.trim();

            if (value === '' || value.indexOf('-') !== -1) {
                return 0;
            }

            var digits = value.replace(/[^0-9.,]/g, '');

            if (digits === '') {
                return 0;
            }

            var whole = digits;
            var fraction = '';

            if (digits.indexOf(',') !== -1) {
                var parts = digits.split(',');

                if (parts.length > 2) {
                    return 0;
                }

                whole = parts[0];
                fraction = parts[1] || '';
            }

            if (fraction !== '' && !/^\d{1,2}$/.test(fraction)) {
                return 0;
            }

            if (whole === '') {
                return 0;
            }

            if (whole.indexOf('.') !== -1 && !/^\d{1,3}(?:\.\d{3})+$/.test(whole)) {
                return 0;
            }

            var plain = whole.replace(/\./g, '');

            if (!/^\d+$/.test(plain) || plain.length > 12) {
                return 0;
            }

            return parseInt(plain, 10) * 100 + parseInt((fraction + '00').slice(0, 2), 10);
        },

        /**
         * Nominal rupiah yang BOLEH nol (beban aparat, alokasi kas).
         *
         * Konvensi field identik dengan `priceCents()`; bedanya nol sah dan
         * kosong berarti 0 (rencana Fase 3 § 4.1 butir 2). Server memakai
         * aturan yang sama pada `SettlementService`.
         */
        moneyCents: function (text) {
            if (typeof text !== 'string') {
                return 0;
            }

            if (text.trim() === '') {
                return 0;
            }

            return AurumInput.priceCents(text);
        },

        /**
         * Kadar persen -> perseratus persen (93,50 -> 9350), setara
         * DECIMAL(5,2) pada kolom `shop_water_density_pct`.
         *
         * Satu pemisah ('.' atau ',') berarti DESIMAL. Mengembalikan -1 bila
         * tidak sah supaya pemanggil dapat membedakan "tidak valid" dari 0.
         */
        percentHundredths: function (text) {
            if (typeof text !== 'string') {
                return -1;
            }

            var value = text.trim().replace(/\s*%\s*$/, '');

            if (value === '' || value.indexOf('-') !== -1) {
                return -1;
            }

            var digits = value.replace(/[^0-9.,]/g, '');

            if (digits === '' || (digits.indexOf(',') !== -1 && digits.indexOf('.') !== -1)) {
                return -1;
            }

            var parts = digits.replace(/,/g, '.').split('.');

            if (parts.length > 2) {
                return -1;
            }

            var whole = parts[0];
            var fraction = parts[1] || '';

            if (!/^\d{1,3}$/.test(whole) || !/^\d{0,2}$/.test(fraction)) {
                return -1;
            }

            return parseInt(whole, 10) * 100 + parseInt((fraction + '00').slice(0, 2), 10);
        }
    };

    /**
     * Cermin format & parser di atas dipakai komponen kasir di bawah. Tidak
     * diekspos ke `window`: satu-satunya pemakai adalah `tellerForm()`, dan
     * angka final tetap dihitung server.
     */

    // -----------------------------------------------------------------------
    // 4. Komponen kasir: kalkulasi langsung + geser untuk konfirmasi
    // -----------------------------------------------------------------------

    /**
     * Elemen form di dalam cakupan komponen Alpine.
     *
     * `$refs` sengaja TIDAK dijadikan sumber pertama. Alpine menelusuri DOM
     * secara pre-order dan baru mendaftarkan `x-ref` saat elemennya ditangani,
     * yaitu SESUDAH `x-data.init()` komponen induk berjalan. Karena itu
     * membaca `$refs.form` di dalam `init()` menghasilkan `undefined`, isian
     * yang sudah dirender peladen dianggap kosong, dan `x-model` menghapus
     * nilainya di peramban. `querySelector` membaca DOM apa adanya tanpa
     * bergantung urutan itu.
     *
     * `name` selalu literal dari kode kita sendiri (tidak pernah teks kiriman
     * pengguna), sehingga aman dirangkai menjadi selektor atribut.
     *
     * @param {{$root?: Element, $el?: Element, $refs?: Object}} component
     * @return {Element|null}
     */
    function formField(component, name) {
        var scope = component.$root || component.$el || null;
        var field = scope && typeof scope.querySelector === 'function'
            ? scope.querySelector('[name="' + name + '"]')
            : null;

        if (field) {
            return field;
        }

        var form = component.$refs ? component.$refs.form : null;

        return form && typeof form.elements !== 'undefined'
            ? form.elements.namedItem(name)
            : null;
    }

    /**
     * Nilai teks sebuah elemen form (`''` bila elemennya tidak ada).
     */
    function formFieldValue(component, name) {
        var field = formField(component, name);

        return field && typeof field.value === 'string' ? field.value : '';
    }

    /**
     * Status centang sebuah elemen form; jatuh ke `fallback` bila elemennya
     * tidak ada (mis. kotak centang tidak dirender).
     */
    function formFieldChecked(component, name, fallback) {
        var field = formField(component, name);

        return field && typeof field.checked === 'boolean' ? field.checked : fallback;
    }

    /**
     * Nilai radio yang sedang tercentang dalam satu grup `name`, dibaca dari DOM
     * (bukan dari atribut JavaScript). Dipakai modal dana talangan untuk bentuk
     * fee (`fixed`/`pct`): peladen merender atribut `checked` pada opsi yang
     * benar, dan `init()` membacanya supaya isian yang dikembalikan peladen
     * tidak tertimpa nilai bawaan komponen.
     *
     * @param {{$root?: Element, $el?: Element}} component
     * @return {string} nilai radio tercentang, atau `fallback` bila tidak ada
     */
    function formRadioValue(component, name, fallback) {
        var scope = component.$root || component.$el || null;

        if (!scope || typeof scope.querySelectorAll !== 'function') {
            return fallback;
        }

        var radios = scope.querySelectorAll('[name="' + name + '"]');

        for (var index = 0; index < radios.length; index += 1) {
            if (radios[index] && radios[index].checked) {
                return String(radios[index].value);
            }
        }

        return fallback;
    }

    /**
     * @param {{fee?: number, saldo?: number}} config
     *        Seluruh nilai berasal dari server (`system_settings` & saldo kas).
     *        TIDAK ada nilai dari input pengguna yang disisipkan ke sini.
     */
    function tellerForm(config) {
        var options = config || {};

        return {
            weight: '',
            price: '',
            // Nilai awal hanya sementara: init() membaca atribut `checked`
            // pada kotak centang, sehingga status dari server selalu menang.
            applyFee: true,

            feeCents: Number(options.fee) || 0,
            saldoCents: Number(options.saldo) || 0,

            inset: 0,
            dragging: false,
            startX: 0,
            submitting: false,
            confirmed: false,

            // --- Dana talangan (Fase 6) ---------------------------------------
            // Modal penerimaan kas dari pihak luar. Nilai awal hanya sementara;
            // init() membacanya dari DOM (nilai yang dirender peladen menang).
            bridgeOpen: false,
            bridgeSubmitting: false,
            bridgeLender: '',
            bridgePrincipal: '',
            bridgeFeeType: 'fixed',
            bridgeRate: '',
            bridgeFixed: '',
            bridgeNotes: '',

            // --- Beban operasional / OPEX (Fase 9) ----------------------------
            // Modal "Catat Beban Kas". Sama seperti talangan: state hidup di
            // komponen yang SUDAH terdaftar supaya aset yang di-cache peramban
            // tidak dapat membuat modalnya hilang.
            opexOpen: false,
            opexSubmitting: false,
            opexCategory: 'coordination',
            opexPayee: '',
            opexAmount: '',
            opexNotes: '',

            /**
             * Ambil nilai awal dari DOM (bukan dari atribut JavaScript) supaya
             * teks kiriman pengguna tidak pernah masuk ke dalam kode JS.
             *
             * Membaca DOM lewat `formFieldValue()` (bukan `$refs`) penting
             * untuk jalur gagal validasi: peladen mengembalikan isian operator
             * (`TellerController` § render 422) dan `x-model` akan menghapusnya
             * bila model dimulai dari `''` — termasuk toggle jasa bakar yang
             * bisa balik ke `checked` tanpa disadari.
             */
            init: function () {
                this.weight = formFieldValue(this, 'dry_weight');
                this.price = formFieldValue(this, 'buy_price_per_g');
                this.applyFee = formFieldChecked(this, 'burn_fee_enabled', this.applyFee);

                // Modal dana talangan: bentuk fee dibaca dari radio yang
                // dicentang peladen (`pct`/`fixed`), isian lain dari DOM.
                this.bridgeLender = formFieldValue(this, 'lender_name');
                this.bridgePrincipal = formFieldValue(this, 'principal_amount');
                this.bridgeRate = formFieldValue(this, 'fee_rate_pct');
                this.bridgeFixed = formFieldValue(this, 'fee_fixed');
                this.bridgeNotes = formFieldValue(this, 'notes');
                this.bridgeFeeType = formRadioValue(this, 'fee_type', this.bridgeFeeType) === 'pct'
                    ? 'pct'
                    : 'fixed';

                // Modal beban operasional (Fase 9): kategori dari `select` yang
                // dirender peladen, isian lain dari DOM.
                this.opexCategory = formFieldValue(this, 'opex_category') || this.opexCategory;
                this.opexPayee = formFieldValue(this, 'opex_payee');
                this.opexAmount = formFieldValue(this, 'opex_amount');
                this.opexNotes = formFieldValue(this, 'opex_notes');
            },

            get weightMilligrams() {
                return AurumInput.weightMilligrams(this.weight);
            },

            get priceCents() {
                return AurumInput.priceCents(this.price);
            },

            get grossCents() {
                var milligrams = this.weightMilligrams;
                var cents = this.priceCents;

                if (milligrams <= 0 || cents <= 0) {
                    return 0;
                }

                var product = milligrams * cents;

                if (!isFinite(product) || product > Number.MAX_SAFE_INTEGER) {
                    return 0;
                }

                return Math.round(product / 1000);
            },

            get appliedFeeCents() {
                return this.applyFee ? this.feeCents : 0;
            },

            get netCents() {
                return this.grossCents - this.appliedFeeCents;
            },

            get grossLabel() {
                return AurumFormat.rupiah(this.grossCents);
            },

            get feeLabel() {
                return '- ' + AurumFormat.rupiah(this.appliedFeeCents);
            },

            get netLabel() {
                return this.netCents > 0 ? AurumFormat.rupiah(this.netCents) : 'Rp 0';
            },

            get weightLabel() {
                return AurumFormat.gram(this.weightMilligrams);
            },

            get insufficient() {
                return this.netCents > 0 && this.netCents > this.saldoCents;
            },

            get canPay() {
                return this.netCents > 0 && !this.insufficient && !this.submitting;
            },

            // --- Cermin pratinjau dana talangan (Fase 6) -----------------------
            // Server selalu menghitung ulang (`BridgeLoanService::quoteFee()`);
            // nilai di bawah ini hanya supaya operator melihat besaran fee saat
            // mengetik. Rumusnya identik: sen integer, half away from zero.

            get bridgePrincipalCents() {
                return AurumInput.moneyCents(this.bridgePrincipal);
            },

            get bridgeFeeCents() {
                if (this.bridgeFeeType === 'pct') {
                    var principal = this.bridgePrincipalCents;
                    var rate = AurumInput.percentHundredths(this.bridgeRate);

                    if (principal <= 0 || rate <= 0) {
                        return 0;
                    }

                    var product = principal * rate;

                    // Di luar jangkauan bilangan aman JS: pratinjau mundur
                    // (0), peladen yang memutuskan.
                    if (!isFinite(product) || product > Number.MAX_SAFE_INTEGER) {
                        return 0;
                    }

                    return Math.floor((product + 5000) / 10000);
                }

                return AurumInput.moneyCents(this.bridgeFixed);
            },

            get bridgeReturnCents() {
                return this.bridgePrincipalCents + this.bridgeFeeCents;
            },

            get bridgeFeeLabel() {
                return AurumFormat.rupiah(this.bridgeFeeCents);
            },

            get bridgeReturnLabel() {
                return AurumFormat.rupiah(this.bridgeReturnCents);
            },

            get bridgeSubmitReady() {
                return this.bridgePrincipalCents > 0
                    && this.bridgeLender.trim() !== ''
                    && !this.bridgeSubmitting;
            },

            // --- Cermin modal beban operasional (Fase 9) ----------------------
            // Peladen tetap memvalidasi ulang (`ExpenseService::pay()`); nilai di
            // sini hanya untuk menyalakan tombol kirim.
            get opexAmountCents() {
                return AurumInput.moneyCents(this.opexAmount);
            },

            get opexSubmitReady() {
                return this.opexAmountCents > 0
                    && this.opexPayee.trim() !== ''
                    && !this.opexSubmitting;
            },

            /** Jarak geser maksimum: lebar lintasan dikurangi lebar pegangan. */
            maxInset: function () {
                var track = this.$refs.track;

                if (!track) {
                    return 0;
                }

                return Math.max(0, track.clientWidth - 64);
            },

            startDrag: function (event) {
                if (!this.canPay) {
                    return;
                }

                this.dragging = true;
                this.startX = event.clientX - this.inset;

                if (typeof event.target.setPointerCapture === 'function') {
                    event.target.setPointerCapture(event.pointerId);
                }
            },

            dragMove: function (event) {
                if (!this.dragging) {
                    return;
                }

                var max = this.maxInset();
                var next = event.clientX - this.startX;

                this.inset = Math.max(0, Math.min(next, max));
            },

            endDrag: function () {
                if (!this.dragging) {
                    return;
                }

                this.dragging = false;

                if (this.inset >= this.maxInset() - 8 && this.canPay) {
                    this.confirm();

                    return;
                }

                this.inset = 0;
            },

            /**
             * Kirim form lewat `requestSubmit()` bila tersedia agar event
             * `submit` (konfirmasi + penonaktifan tombol) tetap berjalan.
             * Token `_once` di server membuat pengiriman ulang tidak mungkin
             * mencatat transaksi kedua.
             */
            confirm: function () {
                if (!this.canPay) {
                    this.inset = 0;

                    return;
                }

                this.confirmed = true;
                this.submitting = true;

                var form = this.$refs.form;

                if (!form) {
                    return;
                }

                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();

                    return;
                }

                form.submit();
            }
        };
    }

    // -----------------------------------------------------------------------
    // 5. Kompresi foto nota (HTML5 Canvas) — Fase 3 § 4.7
    // -----------------------------------------------------------------------

    /** Target lunak dokumen Roadmap ("terkompresi di bawah 250 KB"). */
    var RECEIPT_SOFT_LIMIT_BYTES = 256000;

    /** Batas keras yang ditegakkan PELADEN (SettlementService). */
    var RECEIPT_HARD_LIMIT_BYTES = 4194304;

    /** Format ukuran berkas untuk tampilan operator (KB/MB). */
    function formatBytes(bytes) {
        var value = Number(bytes) || 0;

        if (value >= 1048576) {
            return (Math.round(value / 10485.76) / 100) + ' MB';
        }

        return Math.max(1, Math.round(value / 1024)) + ' KB';
    }

    /** Baca berkas gambar menjadi sumber yang dapat digambar ke canvas. */
    function loadImageSource(file) {
        if (typeof createImageBitmap === 'function') {
            return createImageBitmap(file, { imageOrientation: 'from-image' })
                .catch(function () {
                    return createImageBitmap(file);
                });
        }

        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var image = new Image();

            image.onload = function () {
                URL.revokeObjectURL(url);
                resolve(image);
            };

            image.onerror = function () {
                URL.revokeObjectURL(url);
                reject(new Error('Gambar tidak dapat dibaca peramban.'));
            };

            image.src = url;
        });
    }

    function canvasToBlob(canvas, type, quality) {
        return new Promise(function (resolve) {
            if (typeof canvas.toBlob !== 'function') {
                resolve(null);

                return;
            }

            canvas.toBlob(function (blob) {
                resolve(blob);
            }, type, quality);
        });
    }

    /** Perkecil sisi terpanjang ke `maxEdge` lalu gambar ke canvas baru. */
    function drawScaled(source, maxEdge) {
        var width = source.width;
        var height = source.height;

        if (!width || !height) {
            return null;
        }

        var longest = Math.max(width, height);
        var scale = longest > maxEdge ? (maxEdge / longest) : 1;

        var canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(width * scale));
        canvas.height = Math.max(1, Math.round(height * scale));

        var context = canvas.getContext('2d');

        if (!context) {
            return null;
        }

        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(source, 0, 0, canvas.width, canvas.height);

        return canvas;
    }

    /**
     * Kompresi bertahap: turunkan kualitas JPEG, lalu turunkan dimensi.
     *
     * Selalu mengembalikan hasil TERKECIL yang mampu dibuat peramban; bila
     * target tidak tercapai, pemanggil tetap mengirim berkas asli dan peladen
     * menegakkan batas kerasnya sendiri (rencana § 4.7).
     *
     * @param {File} file
     * @param {{maxBytes?: number, maxEdge?: number, minEdge?: number,
     *          minQuality?: number}} [options]
     * @return {Promise<{blob: Blob, changed: boolean, bytes: number,
     *                   originalBytes: number, width: number, height: number}>}
     */
    function compressImage(file, options) {
        var opts = options || {};
        var maxBytes = opts.maxBytes || RECEIPT_SOFT_LIMIT_BYTES;
        var maxEdge = opts.maxEdge || 2000;
        var minEdge = opts.minEdge || 1200;
        var minQuality = opts.minQuality || 0.5;

        if (!file || !file.size || file.size <= maxBytes) {
            return Promise.resolve({
                blob: file,
                changed: false,
                bytes: file && file.size ? file.size : 0,
                originalBytes: file && file.size ? file.size : 0,
                width: 0,
                height: 0
            });
        }

        return loadImageSource(file).then(function (source) {
            var best = null;
            var attempts = 0;
            var edge = Math.max(1, Number(maxEdge));
            var quality = 0.9;

            var step = function () {
                attempts += 1;

                if (attempts > 24) {
                    return Promise.resolve(best);
                }

                var canvas = drawScaled(source, edge);

                if (!canvas) {
                    return Promise.resolve(best);
                }

                var width = canvas.width;
                var height = canvas.height;

                return canvasToBlob(canvas, 'image/jpeg', quality).then(function (blob) {
                    if (!blob) {
                        return best;
                    }

                    if (!best || blob.size < best.size) {
                        best = blob;
                        best._width = width;
                        best._height = height;
                    }

                    if (blob.size <= maxBytes) {
                        return blob;
                    }

                    if (quality > minQuality + 0.001) {
                        quality = Math.max(minQuality, quality - 0.1);

                        return step();
                    }

                    if (edge > minEdge + 1) {
                        edge = Math.max(minEdge, edge - 200);
                        quality = 0.8;

                        return step();
                    }

                    return best;
                });
            };

            return step().then(function (blob) {
                if (typeof source.close === 'function') {
                    source.close();
                }

                if (!blob) {
                    return {
                        blob: file,
                        changed: false,
                        bytes: file.size,
                        originalBytes: file.size,
                        width: 0,
                        height: 0
                    };
                }

                return {
                    blob: blob,
                    changed: true,
                    bytes: blob.size,
                    originalBytes: file.size,
                    width: best && best._width ? best._width : 0,
                    height: best && best._height ? best._height : 0
                };
            });
        });
    }

    /**
     * Ganti isi input berkas dengan blob hasil kompresi memakai DataTransfer,
     * sehingga POST multipart biasa mengirim berkas terkompresi.
     *
     * @return {boolean} true bila penggantian berhasil
     */
    function replaceInputFile(input, blob, name) {
        if (typeof DataTransfer === 'undefined' || typeof File === 'undefined') {
            return false;
        }

        try {
            var replacement = new File([blob], name, {
                type: blob.type || 'image/jpeg',
                lastModified: Date.now()
            });

            var transfer = new DataTransfer();
            transfer.items.add(replacement);
            input.files = transfer.files;

            return input.files.length === 1;
        } catch (error) {
            return false;
        }
    }

    function jpegName(originalName) {
        var base = String(originalName || 'nota').replace(/\.[^.]*$/, '');

        return (base === '' ? 'nota' : base) + '.jpg';
    }

    // -----------------------------------------------------------------------
    // 6. Komponen rekonsiliasi toko: pratinjau + Smart Auto-Split
    // -----------------------------------------------------------------------

    /**
     * @param {{holding_mg?: number, holding_count?: number,
     *          holding_cost_cents?: number, active_deficit_cents?: number,
     *          coord_fee_cents?: number, receipt_soft_bytes?: number,
     *          receipt_hard_bytes?: number}} config
     *        Seluruh nilai berasal dari server (database & kode kita sendiri).
     *        TIDAK ada nilai dari input pengguna yang disisipkan ke sini.
     */
    function settlementForm(config) {
        var options = config || {};

        var softLimit = Number(options.receipt_soft_bytes) || RECEIPT_SOFT_LIMIT_BYTES;
        var hardLimit = Number(options.receipt_hard_bytes) || RECEIPT_HARD_LIMIT_BYTES;

        return {
            holdingMg: Number(options.holding_mg) || 0,
            holdingCostCents: Number(options.holding_cost_cents) || 0,
            activeDeficitCents: Number(options.active_deficit_cents) || 0,
            bridgeFeeCents: Number(options.bridge_fee_cents) || 0,
            bridgeCount: Number(options.bridge_count) || 0,

            /**
             * Beban operasional sesi ini (Fase 9) — SELURUHNYA dari peladen:
             * jumlah voucher `operational_expenses` yang belum terserap. Tidak
             * ada isian operator lagi (`coord_fee` dihapus), sehingga angka ini
             * tidak dapat dinaikkan dari peramban untuk menahan uang di laci.
             */
            opexFeeCents: Number(options.opex_fee_cents) || 0,

            /**
             * Hak mitra lapangan (Fase 10) — SELURUHNYA dari peladen: daftar
             * tarif (perseratus persen) petugas aktif + total akrual bonus yang
             * belum terserap. Cermin `OfficerService::previewCuts()`; peladen
             * menghitung ulang saat POST, jadi angka ini hanya pratinjau.
             */
            officerPcts: Array.isArray(options.officer_pcts) ? options.officer_pcts.map(Number) : [],
            officerBonusCents: Number(options.officer_bonus_cents) || 0,

            soldGrams: '',
            densityPercent: '',
            shopPrice: '',
            kasInput: '',
            brankasInput: '',

            receiptInfo: '',
            receiptWarning: '',
            receiptReady: false,
            compressing: false,
            submitting: false,

            /** Operator sudah menyunting alokasi sendiri? Bila ya, saran tidak menimpa. */
            splitTouched: false,

            /**
             * Nilai awal diambil dari DOM (bukan dari atribut JavaScript) agar
             * teks kiriman pengguna tidak pernah masuk ke dalam kode JS dan
             * agar halaman tetap benar tanpa JavaScript.
             *
             * KEENAM isian dibaca — termasuk dua alokasi kas — supaya isian
             * manual yang dikembalikan peladen pada `422` tidak tertimpa saran
             * sistem oleh `restoreAutoSplit()` di baris berikutnya.
             *
             * Pembacaan lewat `formFieldValue()` (bukan `$refs.form`) juga
             * mencegah `x-model` menghapus nilai yang sudah dirender peladen:
             * Alpine baru mendaftarkan `x-ref` setelah `init()` ini berjalan.
             */
            init: function () {
                this.soldGrams = formFieldValue(this, 'sold_grams');
                this.densityPercent = formFieldValue(this, 'density_pct');
                this.shopPrice = formFieldValue(this, 'shop_price_per_g');
                this.kasInput = formFieldValue(this, 'kas_split');
                this.brankasInput = formFieldValue(this, 'brankas_split');

                this.restoreAutoSplit(false);
            },

            get soldMilligrams() {
                return AurumInput.weightMilligrams(this.soldGrams);
            },

            get densityHundredths() {
                return AurumInput.percentHundredths(this.densityPercent);
            },

            get shopPriceCents() {
                return AurumInput.priceCents(this.shopPrice);
            },

            /** Cermin `SettlementService::quote()` — server tetap menghitung. */
            get revenueCents() {
                var milligrams = this.soldMilligrams;
                var cents = this.shopPriceCents;

                if (milligrams <= 0 || cents <= 0) {
                    return 0;
                }

                var product = milligrams * cents;

                if (!isFinite(product) || product > Number.MAX_SAFE_INTEGER) {
                    return 0;
                }

                return Math.round(product / 1000);
            },

            get grossCents() {
                return this.revenueCents - this.holdingCostCents;
            },

            /**
             * Basis potongan mitra lapangan = laba kotor yang benar-benar ada
             * (D-2: hari minus → 0, potongan tidak pernah melebihi laba kotor).
             */
            get grossPositiveCents() {
                return Math.max(0, this.grossCents);
            },

            /**
             * Σ potongan mitra lapangan — pembulatan setengah sen ke atas per
             * petugas, lalu dibatasi laba kotor. Sama dengan
             * `OfficerService::previewCuts()` di peladen.
             */
            get officerCutCents() {
                var basis = this.grossPositiveCents;

                if (basis <= 0 || this.officerPcts.length === 0) {
                    return 0;
                }

                var total = 0;

                for (var index = 0; index < this.officerPcts.length; index++) {
                    var pct = Number(this.officerPcts[index]) || 0;

                    if (pct > 0) {
                        total += Math.floor((basis * pct + 5000) / 10000);
                    }
                }

                return Math.min(total, basis);
            },

            get officerCount() {
                return this.officerPcts.length;
            },

            /**
             * Laba setelah beban operasional, bonus akrual, dan potongan petugas.
             * Keduanya mengurangi laba TEPAT SEKALI: potongan pada akrual sesi
             * ini, bonus saat diserap (tidak pernah sebagai voucher OPEX).
             */
            get afterFeeCents() {
                return this.grossCents - this.opexFeeCents - this.officerBonusCents - this.officerCutCents;
            },

            /** Pemulihan defisit: min(active, max(0, laba kotor - aparat)). */
            get deficitRecoveryCents() {
                return Math.min(this.activeDeficitCents, Math.max(0, this.afterFeeCents));
            },

            get netCents() {
                return this.afterFeeCents - this.deficitRecoveryCents;
            },

            get operatorCents() {
                var net = this.netCents;

                if (net <= 0) {
                    return 0;
                }

                var share = Math.round(net * 80 / 100);

                return share > net ? net : share;
            },

            get poolCents() {
                var net = this.netCents;

                return net <= 0 ? 0 : net - this.operatorCents;
            },

            /**
             * Kas fisik yang benar-benar diterima dari toko = PENDAPATAN PENUH.
             *
             * Fase 9: beban operasional sudah keluar tunai dari laci lebih
             * dahulu (`ExpenseService::pay()`), sehingga laci menerima
             * penggantiannya lewat alokasi `kas`. Beban hanya mengurangi laba
             * (`afterFeeCents`), bukan kas yang dialokasikan — mengurangi
             * keduanya membuat buku lebih rendah daripada uang fisik tepat
             * sebesar beban (audit § 5.5 skenario B).
             */
            get totalCashCents() {
                return this.revenueCents;
            },

            get kasCents() {
                return AurumInput.moneyCents(this.kasInput);
            },

            get brankasCents() {
                return AurumInput.moneyCents(this.brankasInput);
            },

            /** Saran: laba bersih ke brankas, sisanya (modal) kembali ke meja. */
            get suggestedBrankasCents() {
                return Math.max(0, this.netCents);
            },

            get suggestedKasCents() {
                return this.totalCashCents - this.suggestedBrankasCents;
            },

            get remainingCents() {
                return this.totalCashCents - this.kasCents - this.brankasCents;
            },

            get splitBalanced() {
                return this.totalCashCents >= 0
                    && this.kasCents >= 0
                    && this.brankasCents >= 0
                    && this.remainingCents === 0;
            },

            get inputValid() {
                var density = this.densityHundredths;

                return this.soldMilligrams > 0
                    && this.shopPriceCents > 0
                    && density > 0
                    && density <= 10000
                    && this.opexFeeCents <= this.revenueCents
                    && this.opexFeeCents + this.officerBonusCents + this.officerCutCents <= this.revenueCents
                    && this.soldMilligrams <= this.holdingMg
                    && this.totalCashCents >= 0;
            },

            get canSubmit() {
                return this.inputValid
                    && this.splitBalanced
                    && this.receiptReady
                    && !this.compressing
                    && !this.submitting;
            },

            get revenueLabel() {
                return AurumFormat.rupiah(this.revenueCents);
            },

            get grossLabel() {
                return AurumFormat.rupiah(this.grossCents);
            },

            get opexFeeLabel() {
                return '- ' + AurumFormat.rupiah(this.opexFeeCents);
            },

            get officerBonusLabel() {
                return '- ' + AurumFormat.rupiah(this.officerBonusCents);
            },

            get officerCutLabel() {
                return '- ' + AurumFormat.rupiah(this.officerCutCents);
            },

            get deficitLabel() {
                return '- ' + AurumFormat.rupiah(this.deficitRecoveryCents);
            },

            get netLabel() {
                return AurumFormat.rupiah(this.netCents);
            },

            get operatorLabel() {
                return AurumFormat.rupiah(this.operatorCents);
            },

            get poolLabel() {
                return AurumFormat.rupiah(this.poolCents);
            },

            // --- Dana talangan (Fase 6) ---------------------------------------
            // Cermin `SettlementService::quote()`: fee diserap HANYA dari hak
            // pengelola (`min(fee, hak)`), sehingga angka pool di sebelah baris
            // ini tidak pernah terpengaruh.
            //
            // TAMPIL/TIDAKNYA baris fee ditentukan `bridgeHasActive` (dari
            // peladen) — BUKAN dari laba sesi ini. Pada hari sehat, fee tetap
            // tampil lengkap ("Fee talangan (1) - Rp 100.000" dan "Hak pengelola
            // bersih"); yang boleh mengikuti angka laba hanyalah peringatan
            // "sebagian fee melebihi hak pengelola" (`bridgeHasUncovered`).
            get bridgeHasActive() {
                return this.bridgeCount > 0;
            },

            get bridgeHasUncovered() {
                return this.bridgeUncoveredCents > 0;
            },

            /**
             * Peringatan "fee melebihi hak laba pengelola" hanya ditampilkan bila
             * proyeksi bagi hasil sudah LENGKAP (`inputValid`) **dan** memang ada
             * bagian fee yang tak tertutup.
             *
             * Tanpa `inputValid`, peringatan itu menyala di layar kosong (harga
             * belum diisi ⇒ hak pengelola `Rp 0` ⇒ "fee melebihi hak laba")
             * sehingga terbaca seperti tuduhan, padahal labanya cuma belum
             * diketahui. Kebenarannya tetap milik peladen: setelah tutup buku
             * dieksekusi, `fee_uncovered` tampil pada pesan sukses.
             */
            get bridgeWarnUncovered() {
                return this.bridgeHasUncovered && this.inputValid;
            },

            get bridgeAbsorbedCents() {
                return Math.min(this.bridgeFeeCents, Math.max(0, this.operatorCents));
            },

            get bridgeUncoveredCents() {
                return Math.max(0, this.bridgeFeeCents - this.bridgeAbsorbedCents);
            },

            get operatorTakeHomeCents() {
                return this.operatorCents - this.bridgeAbsorbedCents;
            },

            get bridgeCountLabel() {
                return String(this.bridgeCount);
            },

            get bridgeFeeLabel() {
                return '- ' + AurumFormat.rupiah(this.bridgeAbsorbedCents);
            },

            get bridgeUncoveredLabel() {
                return AurumFormat.rupiah(this.bridgeUncoveredCents);
            },

            get operatorTakeHomeLabel() {
                return AurumFormat.rupiah(this.operatorTakeHomeCents);
            },

            get totalCashLabel() {
                return AurumFormat.rupiah(this.totalCashCents);
            },

            get remainingLabel() {
                return AurumFormat.rupiah(this.remainingCents);
            },

            get suggestedKasLabel() {
                return AurumFormat.rupiah(this.suggestedKasCents);
            },

            get suggestedBrankasLabel() {
                return AurumFormat.rupiah(this.suggestedBrankasCents);
            },

            get holdingLabel() {
                return AurumFormat.gram(this.holdingMg);
            },

            /**
             * Kembalikan kedua isian ke saran otomatis sistem.
             *
             * `init()` memanggilnya dengan `force = false` supaya isian yang
             * sudah dibawa peladen (mis. hasil `422`) dipertahankan; tombol
             * "Saran otomatis" pada layar memanggilnya dengan `true`.
             */
            restoreAutoSplit: function (force) {
                if (force === true || this.kasInput === '' || this.brankasInput === '') {
                    this.kasInput = this.centsToInput(this.suggestedKasCents);
                    this.brankasInput = this.centsToInput(this.suggestedBrankasCents);
                    this.splitTouched = false;
                }
            },

            /**
             * Perbarui saran alokasi selama operator belum menyunting sendiri.
             * Dipanggil setiap isian penjualan toko berubah, sehingga saran
             * selalu mengikuti angka terbaru tanpa menimpa keputusan manual.
             */
            refreshSuggestion: function () {
                if (this.splitTouched) {
                    return;
                }

                this.kasInput = this.centsToInput(this.suggestedKasCents);
                this.brankasInput = this.centsToInput(this.suggestedBrankasCents);
            },

            /**
             * Sen -> teks isian alokasi.
             *
             * Sejak Fase 10 nilai saran dapat memuat **sen** (potongan mitra
             * lapangan 2,5 % menghasilkan Rp 59.662,50). Sebelumnya fungsi ini
             * membuang sen (`Math.floor(value / 100)`), sehingga kedua isian
             * berjumlah Rp 1 lebih kecil daripada kas yang diterima: tombol
             * kirim terkunci dan peladen menjawab "alokasi harus berjumlah
             * persis". Sekarang sisa sen ikut ditulis sebagai koma desimal —
             * bentuk yang diterima `SettlementService::parseMoneyInput()` dan
             * `AurumInput.moneyCents()`.
             */
            centsToInput: function (cents) {
                var value = Math.max(0, Math.round(Number(cents) || 0));
                var whole = String(Math.floor(value / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                var rest = value % 100;

                if (rest === 0) {
                    return whole;
                }

                return whole + ',' + (rest < 10 ? '0' + rest : String(rest));
            },

            /**
             * Kompresi dipicu saat operator memilih/mengambil foto nota.
             * Bila kompresi tidak mungkin, berkas asli tetap dikirim dan
             * peladen yang memutuskan (rencana § 4.7).
             */
            onReceiptChange: function (event) {
                var input = event.target;
                var file = input.files && input.files[0] ? input.files[0] : null;

                this.receiptInfo = '';
                this.receiptWarning = '';
                this.receiptReady = false;

                if (!file) {
                    return;
                }

                if (!/^image\//.test(file.type)) {
                    this.receiptWarning = 'Berkas harus berupa gambar (JPG, PNG, atau WEBP).';
                    input.value = '';

                    return;
                }

                if (file.size > hardLimit) {
                    this.receiptWarning = 'Foto melebihi batas ' + formatBytes(hardLimit)
                        + '. Perkecil dulu di kamera ponsel.';
                    input.value = '';

                    return;
                }

                var self = this;

                if (file.size <= softLimit) {
                    this.receiptReady = true;
                    this.receiptInfo = file.name + ' · ' + formatBytes(file.size)
                        + ' — sudah di bawah target';
                    this.clearWarningAfterDelay();

                    return;
                }

                this.compressing = true;

                compressImage(file, { maxBytes: softLimit, maxEdge: 2000, minEdge: 1200, minQuality: 0.5 })
                    .then(function (result) {
                        self.compressing = false;
                        self.receiptReady = true;

                        var replaced = false;

                        if (result.changed) {
                            replaced = replaceInputFile(input, result.blob, jpegName(file.name));
                        }

                        self.receiptInfo = file.name + ' · ' + formatBytes(result.originalBytes)
                            + ' → ' + formatBytes(replaced ? result.bytes : file.size)
                            + (replaced
                                ? (result.bytes <= softLimit ? ' (target tercapai)' : ' (di atas target)')
                                : ' (kompresi tidak didukung peramban ini)');

                        if (!replaced && file.size > softLimit) {
                            self.receiptWarning = 'Peramban ini tidak dapat mengompresi gambar. '
                                + 'Pastikan ukuran berkas masih di bawah batas server.';
                        }
                    })
                    .catch(function () {
                        self.compressing = false;
                        self.receiptReady = true;
                        self.receiptInfo = file.name + ' · ' + formatBytes(file.size);
                        self.receiptWarning = 'Kompresi gambar gagal di peramban ini. '
                            + 'Berkas asli akan dikirim; peladen tetap memvalidasi ukurannya.';
                    });
            },

            clearWarningAfterDelay: function () {
                var self = this;

                window.setTimeout(function () {
                    self.receiptWarning = '';
                }, 4000);
            },

            /**
             * Submit hanya dipicu lewat `requestSubmit()` supaya event `submit`
             * (konfirmasi + penonaktifan tombol) tetap berjalan. Token `_once`
             * di peladen membuat pengiriman ulang tidak mungkin mencatat
             * settlement kedua.
             */
            onSubmit: function () {
                if (!this.canSubmit) {
                    return;
                }

                this.submitting = true;

                var self = this;

                window.setTimeout(function () {
                    self.submitting = false;
                }, 5000);
            }
        };
    }

    // -----------------------------------------------------------------------
    // 7. Cermin mesin alokasi modal + komponen layar Kelola Modal (Fase 4)
    // -----------------------------------------------------------------------

    /**
     * Cermin `AllocationEngine` dalam sen integer.
     *
     * Seluruh perkalian memakai BigInt supaya hasilnya SAMA PERSIS dengan
     * peladen: `kebutuhan × saldo` dapat melampaui presisi 53-bit `Number`
     * (mis. 35.157.500 × 20.000.000 sen), dan selisih satu sen pada pratinjau
     * akan membingungkan operator. Nilai kembali ke `Number` hanya untuk
     * ditampilkan (rentangnya kecil), sedangkan nilai final selalu dihitung
     * ulang peladen.
     */
    var AurumAlloc = {
        /**
         * `floor(left * right / divisor)` beserta sisanya.
         *
         * Memakai BigInt bila tersedia (semua peramban modern & Node) sehingga
         * hasilnya sama persis dengan peladen. Bila BigInt tidak ada, jatuh ke
         * aritmetika `Number` biasa — pratinjau tetap jalan dengan presisi
         * terbatas pada nilai ekstrem, dan angka yang tersimpan tetap dihitung
         * peladen.
         *
         * @return {{quotient: number, remainder: (number|bigint)}}
         */
        mulDiv: function (left, right, divisor) {
            var l = Math.max(0, Math.round(Number(left) || 0));
            var r = Math.max(0, Math.round(Number(right) || 0));
            var d = Math.max(1, Math.round(Number(divisor) || 1));

            if (typeof BigInt === 'function') {
                var product = BigInt(l) * BigInt(r);
                var bigDivisor = BigInt(d);

                return {
                    quotient: Number(product / bigDivisor),
                    remainder: product % bigDivisor
                };
            }

            var productNumber = l * r;

            return {
                quotient: Math.floor(productNumber / d),
                remainder: productNumber % d
            };
        },

        /** floor(cents * hundredths / 10000) — kuota adalah BATAS serapan. */
        shareFloor: function (cents, hundredths) {
            if (cents <= 0 || hundredths <= 0) {
                return 0;
            }

            return AurumAlloc.mulDiv(cents, hundredths, 10000).quotient;
        },

        /**
         * Bagi `total` menurut bobot dengan metode SISA TERBESAR.
         *
         * @param {number} total
         * @param {Array<{id: string, weight: number}>} entries
         * @return {Object} peta id -> bagian (sen)
         */
        proRata: function (total, entries) {
            var shares = {};
            var remainders = {};
            var sum = 0;
            var i;

            for (i = 0; i < entries.length; i++) {
                shares[entries[i].id] = 0;
                remainders[entries[i].id] = 0;
            }

            for (i = 0; i < entries.length; i++) {
                sum += Math.max(0, Math.round(Number(entries[i].weight) || 0));
            }

            if (sum <= 0 || total <= 0) {
                return shares;
            }

            var budget = Math.min(Math.round(Number(total) || 0), sum);
            var allocated = 0;

            for (i = 0; i < entries.length; i++) {
                var entry = entries[i];
                var part = AurumAlloc.mulDiv(budget, entry.weight, sum);

                shares[entry.id] = part.quotient;
                remainders[entry.id] = part.remainder;
                allocated += part.quotient;
            }

            var leftover = budget - allocated;

            if (leftover > 0) {
                var order = entries.slice().sort(function (a, b) {
                    if (remainders[a.id] !== remainders[b.id]) {
                        return remainders[a.id] > remainders[b.id] ? -1 : 1;
                    }

                    if (a.weight !== b.weight) {
                        return b.weight - a.weight;
                    }

                    return a.id < b.id ? -1 : 1;
                });

                for (i = 0; i < leftover && i < order.length; i++) {
                    shares[order[i].id] += 1;
                }
            }

            return shares;
        },

        /**
         * Serapan berlapis: Pengelola (kuota dasar) → Mitra Prioritas (kuota
         * terkunci per dompet) → Mitra Reguler (pro-rata saldo atas sisa).
         *
         * @param {number} needCents
         * @param {Array<{id, tier, available_cents, quota_hundredths}>} wallets
         * @param {number} operatorQuotaHundredths
         */
        plan: function (needCents, wallets, operatorQuotaHundredths) {
            var rows = [];
            var i;

            for (i = 0; i < (wallets || []).length; i++) {
                var wallet = wallets[i];

                rows.push({
                    id: String(wallet.id || ''),
                    name: String(wallet.name || ''),
                    tier: String(wallet.tier || ''),
                    tierLabel: String(wallet.tier_label || ''),
                    available: Math.max(0, Math.round(Number(wallet.available_cents) || 0)),
                    quota: wallet.tier === 'family'
                        ? Math.max(0, Math.round(Number(wallet.quota_hundredths) || 0))
                        : 0,
                    utilized: 0
                });
            }

            var need = Math.max(0, Math.round(Number(needCents) || 0));
            var operatorQuota = AurumAlloc.shareFloor(need, operatorQuotaHundredths);
            var stages = { operator: 0, family: 0, external: 0 };
            var remaining = need;

            function take(index, capacity) {
                var absorbed = Math.max(0, Math.min(capacity, rows[index].available, remaining));

                rows[index].utilized = absorbed;
                remaining -= absorbed;

                return absorbed;
            }

            var operatorIndexes = AurumAlloc.indexes(rows, 'operator').sort(function (a, b) {
                if (rows[a].available !== rows[b].available) {
                    return rows[b].available - rows[a].available;
                }

                return rows[a].id < rows[b].id ? -1 : 1;
            });

            for (i = 0; i < operatorIndexes.length; i++) {
                stages.operator += take(operatorIndexes[i], operatorQuota - stages.operator);
            }

            var familyIndexes = AurumAlloc.indexes(rows, 'family').sort(function (a, b) {
                if (rows[a].quota !== rows[b].quota) {
                    return rows[b].quota - rows[a].quota;
                }

                if (rows[a].available !== rows[b].available) {
                    return rows[b].available - rows[a].available;
                }

                return rows[a].id < rows[b].id ? -1 : 1;
            });

            for (i = 0; i < familyIndexes.length; i++) {
                stages.family += take(familyIndexes[i], AurumAlloc.shareFloor(need, rows[familyIndexes[i]].quota));
            }

            var externalIndexes = AurumAlloc.indexes(rows, 'external');
            var entries = [];

            for (i = 0; i < externalIndexes.length; i++) {
                entries.push({ id: rows[externalIndexes[i]].id, weight: rows[externalIndexes[i]].available });
            }

            if (remaining > 0 && entries.length > 0) {
                var shares = AurumAlloc.proRata(remaining, entries);

                for (i = 0; i < externalIndexes.length; i++) {
                    var absorbed = shares[rows[externalIndexes[i]].id] || 0;

                    rows[externalIndexes[i]].utilized = absorbed;
                    stages.external += absorbed;
                    remaining -= absorbed;
                }
            }

            var utilized = 0;
            var mitra = 0;

            for (i = 0; i < rows.length; i++) {
                utilized += rows[i].utilized;

                if (rows[i].tier !== 'operator') {
                    mitra += rows[i].utilized;
                }
            }

            return {
                needCents: need,
                operatorQuotaCents: operatorQuota,
                rows: rows,
                stages: stages,
                utilizedCents: utilized,
                mitraBaseCents: mitra,
                uncoveredCents: remaining
            };
        },

        indexes: function (rows, tier) {
            var indexes = [];
            var i;

            for (i = 0; i < rows.length; i++) {
                if (rows[i].tier === tier) {
                    indexes.push(i);
                }
            }

            return indexes;
        }
    };

    /**
     * Komponen `capitalSimulator` untuk Simulator "Belanja Malam Ini".
     *
     * Isian rencana belanja SUDAH terisi nilai nyata dari peladen (modal belanja
     * hulu berstatus holding) dan bebas diubah operator untuk menguji rencana
     * lain. Seluruh keluaran di sini hanya pratinjau; nilai yang tersimpan selalu
     * dihitung ulang `AllocationEngine` di peladen.
     *
     * @param {{operator_quota_hundredths?: number, need_cents?: number,
     *          wallets?: Array<Object>}} config
     *        Seluruh nilai berasal dari server (database & kode kita sendiri).
     *        TIDAK ada nilai dari input pengguna yang disisipkan ke sini.
     */
    function capitalSimulator(config) {
        var options = config || {};

        return {
            needInput: '',
            wallets: Array.isArray(options.wallets) ? options.wallets : [],
            operatorQuotaHundredths: Number(options.operator_quota_hundredths) || 0,
            submitting: false,

            /**
             * Nilai awal dibaca dari DOM (bukan dari atribut JavaScript) supaya
             * teks kiriman pengguna tidak pernah masuk ke kode JS dan halaman
             * tetap benar tanpa JavaScript — konvensi yang sama dengan
             * `tellerForm`/`settlementForm`.
             */
            init: function () {
                this.needInput = formFieldValue(this, 'need_cents');
            },

            get needCents() {
                return AurumInput.moneyCents(this.needInput);
            },

            get plan() {
                return AurumAlloc.plan(this.needCents, this.wallets, this.operatorQuotaHundredths);
            },

            get operatorLabel() {
                return AurumFormat.rupiah(this.plan.stages.operator);
            },

            get familyLabel() {
                return AurumFormat.rupiah(this.plan.stages.family);
            },

            get externalLabel() {
                return AurumFormat.rupiah(this.plan.stages.external);
            },

            get uncoveredLabel() {
                return AurumFormat.rupiah(this.plan.uncoveredCents);
            },

            get rows() {
                return this.plan.rows.map(function (row) {
                    return {
                        id: row.id,
                        name: row.name,
                        tierLabel: row.tierLabel,
                        utilizedLabel: AurumFormat.rupiah(row.utilized),
                        balanceLabel: AurumFormat.rupiah(row.available)
                    };
                });
            }
        };
    }

    // -----------------------------------------------------------------------
    // 8. Portal mitra (Fase 5)
    // -----------------------------------------------------------------------

    /**
     * Pintu PIN mitra.
     *
     * Papan angka hanyalah kemudahan di ponsel: isian PIN tetap `<input>`
     * biasa sehingga form berfungsi tanpa JavaScript. Yang menjaga keamanan
     * adalah kebijakan PIN, `APP_PIN_PEPPER`, dan `PinThrottle` di peladen —
     * tidak ada satu pun aturan keamanan yang hidup di berkas ini.
     *
     * @param {{pinLength?: number}} config
     */
    function investorLogin(config) {
        var options = config || {};
        var pinLength = typeof options.pinLength === 'number' && options.pinLength > 0
            ? options.pinLength
            : 6;

        return {
            submitting: false,
            digits: ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'],

            press: function (digit) {
                var field = formField(this, 'pin');

                if (!field || field.value.length >= pinLength) {
                    return;
                }

                field.value = field.value + String(digit);
            },

            erase: function () {
                var field = formField(this, 'pin');

                if (!field) {
                    return;
                }

                field.value = field.value.slice(0, -1);
            }
        };
    }

    /**
     * Dasbor mitra: pemilih jenis penarikan + ketersediaan dana.
     *
     * Seluruh *nominal* yang ditampilkan berasal dari peladen (`config`), dan
     * peladen tetap menghitung ulang setiap pengajuan. Komponen ini hanya
     * mengisi isian dengan angka yang sudah diformat peladen dan mengganti teks
     * jatuh tempo ketika jenis penarikan berganti — tidak ada aritmetika uang
     * yang dihitung ulang di peramban.
     *
     * @param {{profit_label?: string, capital_label?: string, capital_due?: string,
     *          initial_type?: string}} config
     */
    function investorDashboard(config) {
        var options = config || {};
        var profitLabel = typeof options.profit_label === 'string' ? options.profit_label : '';
        var capitalLabel = typeof options.capital_label === 'string' ? options.capital_label : '';
        var capitalDue = typeof options.capital_due === 'string' ? options.capital_due : '-';

        return {
            type: options.initial_type === 'capital' ? 'capital' : 'profit',
            amount: '',
            submitting: false,

            choose: function (type) {
                this.type = type === 'capital' ? 'capital' : 'profit';

                var field = formField(this, 'withdrawal_amount');

                if (field && typeof field.focus === 'function') {
                    field.focus();
                }
            },

            fillAll: function () {
                this.amount = this.type === 'capital' ? capitalLabel : profitLabel;
            },

            get availableText() {
                return 'Tersedia: ' + (this.type === 'capital' ? capitalLabel : profitLabel);
            },

            get dueText() {
                return this.type === 'capital'
                    ? 'Estimasi kas siap: ' + capitalDue + ' (masa tunggu H-7 hari kerja).'
                    : 'Dana diproses pengelola setelah disetujui; dividen tidak melewati masa tunggu.';
            },

            get confirmText() {
                return this.type === 'capital'
                    ? 'Ajukan penarikan modal pokok? Kas siap ' + capitalDue + ' (H-7 hari kerja).'
                    : 'Ajukan penarikan keuntungan? Pengelola akan memprosesnya.';
            }
        };
    }

    // -----------------------------------------------------------------------
    // 9. Komponen Alpine.js `kasbonForm` — Buku Kasbon petani (Fase 8)
    // -----------------------------------------------------------------------

    /**
     * Cermin format nominal untuk isian `amount` (bukan untuk tampilan kartu:
     * label rupiah di layar tetap berasal dari `Formatter` peladen).
     *
     * Sen (integer) -> `'250.000'` / `'12.345,67'` — tepat bentuk yang diterima
     * `SettlementService::parseMoneyInput()` (titik = ribuan, koma = desimal).
     * Aritmetika dilakukan pada integer sen, bukan float rupiah.
     *
     * @param {number} cents
     * @return {string}
     */
    function kasbonAmountText(cents) {
        var rounded = Math.round(Math.abs(Number(cents) || 0));
        var whole = Math.floor(rounded / 100);
        var fraction = rounded % 100;
        var text = groupThousands(String(whole));

        if (fraction > 0) {
            text += ',' + (fraction < 10 ? '0' + fraction : String(fraction));
        }

        return text;
    }

    /**
     * Baca nominal yang diketik kasir dengan aturan yang sama seperti peladen:
     * titik = pemisah ribuan, koma = desimal, satu koma saja.
     *
     * @param {string} text
     * @return {number} sen (0 bila tidak sah)
     */
    function kasbonParseAmount(text) {
        if (typeof text !== 'string') {
            return 0;
        }

        var value = text.trim();

        if (value === '' || value.indexOf('-') !== -1) {
            return 0;
        }

        var digits = value.replace(/[^0-9.,]/g, '');

        if (digits === '' || digits.indexOf(',') !== digits.lastIndexOf(',')) {
            return 0;
        }

        var parts = digits.split(',');
        var integer = parts[0] || '';
        var fraction = parts.length > 1 ? parts[1] : '';

        if (fraction.length > 2) {
            return 0;
        }

        if (integer.indexOf('.') !== -1 && !/^\d{1,3}(\.\d{3})+$/.test(integer)) {
            return 0;
        }

        var plain = integer.replace(/\./g, '');

        if (!/^\d+$/.test(plain)) {
            return 0;
        }

        var whole = Number(plain);

        if (!isFinite(whole) || whole > 999999999999) {
            return 0;
        }

        var cents = Number(plain) * 100;

        while (fraction.length < 2) {
            fraction += '0';
        }

        return cents + Number(fraction || 0);
    }

    /**
     * @param {{mode?: string}} config `mode`: 'disburse' (kasbon baru) atau
     *        'repay' (angsuran). Hanya penanda perilaku; SELURUH angka utang
     *        berasal dari atribut DOM `data-outstanding` yang dirender peladen.
     */
    function kasbonForm(config) {
        var options = config || {};

        return {
            mode: options.mode === 'disburse' ? 'disburse' : 'repay',

            minerLabel: '',
            minerId: '',
            outstandingCents: 0,
            amount: '',
            submitting: false,

            /** value label -> {id, cents}; dibangun dari <datalist> di DOM. */
            index: {},

            /**
             * Ambil nilai awal dari DOM (bukan `$refs`) supaya isian yang
             * dikembalikan peladen pada jalur 422 tidak terhapus `x-model`.
             */
            init: function () {
                // Nama field berbeda per kartu: pencairan memakai `miner_label` /
                // `miner_id` / `principal_amount`, angsuran memakai `pay_miner_*`
                // dan `amount` (satu halaman boleh memuat dua form).
                var prefix = this.mode === 'disburse' ? '' : 'pay_';
                var amountField = this.mode === 'disburse' ? 'principal_amount' : 'amount';

                this.minerLabel = formFieldValue(this, prefix + 'miner_label');
                this.minerId = formFieldValue(this, prefix + 'miner_id');
                this.amount = formFieldValue(this, amountField);

                var scope = this.$root || null;
                var options = scope && typeof scope.querySelectorAll === 'function'
                    ? scope.querySelectorAll('datalist option')
                    : [];

                for (var i = 0; i < options.length; i++) {
                    var option = options[i];
                    var label = String(option.value || '');
                    var id = String(option.getAttribute('data-miner-id') || '');
                    var cents = Number(option.getAttribute('data-outstanding') || '0');

                    if (label === '' || id === '') {
                        continue;
                    }

                    this.index[label] = {
                        id: id,
                        cents: isFinite(cents) ? cents : 0
                    };
                }

                if (this.minerLabel !== '') {
                    this.pickMiner();
                }
            },

            /** Petani terpilih -> id + sisa utang (dipakai tombol cepat). */
            pickMiner: function () {
                var entry = this.index[this.minerLabel] || null;

                this.minerId = entry ? entry.id : '';
                this.outstandingCents = entry ? entry.cents : 0;
            },

            /** Isi nominal = persentase sisa utang (sen integer, half-up). */
            pickPercent: function (percent) {
                var pct = Number(percent) || 0;

                if (this.outstandingCents <= 0 || pct <= 0) {
                    return;
                }

                this.amount = kasbonAmountText(Math.round(this.outstandingCents * pct / 100));
            },

            /** "Lunas": nominal = tepat sisa utang. */
            payFull: function () {
                if (this.outstandingCents <= 0) {
                    return;
                }

                this.amount = kasbonAmountText(this.outstandingCents);
            },

            get amountCents() {
                return kasbonParseAmount(this.amount);
            },

            get hasMiner() {
                return this.minerId !== '';
            },

            get outstandingLabel() {
                return this.hasMiner ? kasbonAmountText(this.outstandingCents) : '';
            },

            get afterLabel() {
                if (!this.hasMiner || this.amountCents <= 0) {
                    return '—';
                }

                var after = this.outstandingCents - this.amountCents;

                return kasbonAmountText(after > 0 ? after : 0);
            }
        };
    }

    // -----------------------------------------------------------------------
    // 10. PWA: Service Worker (Fase 5)
    // -----------------------------------------------------------------------

    /**
     * Daftarkan service worker.
     *
     * Hanya berjalan di HTTPS atau localhost: peramban menolak service worker
     * pada origin yang tidak aman, dan mendaftarkannya di lingkungan
     * pengembangan non-aman hanya menghasilkan galat di konsol.
     *
     * SWR di sini **tidak** menyentuh data keuangan: `sw.js` hanya menyimpan
     * aset statis (CSS, font, skrip, ikon) supaya aplikasi dapat dipasang ke
     * layar utama; HTML dasbor selalu diambil dari jaringan agar saldo tidak
     * pernah basi.
     */
    function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) {
            return;
        }

        var aman = window.isSecureContext === true
            || location.protocol === 'https:'
            || location.hostname === 'localhost'
            || location.hostname === '127.0.0.1';

        if (!aman) {
            return;
        }

        var scopeUrl = document.body.getAttribute('data-app-base') || '/';

        window.addEventListener('load', function () {
            navigator.serviceWorker.register(scopeUrl + 'sw.js', { scope: scopeUrl }).catch(function () {
                // Gagal mendaftar bukan kesalahan fatal aplikasi.
            });
        });
    }

    registerServiceWorker();

    document.addEventListener('alpine:init', function () {
        if (window.Alpine && typeof window.Alpine.data === 'function') {
            window.Alpine.data('tellerForm', tellerForm);
            window.Alpine.data('settlementForm', settlementForm);
            window.Alpine.data('capitalSimulator', capitalSimulator);
            window.Alpine.data('investorLogin', investorLogin);
            window.Alpine.data('investorDashboard', investorDashboard);
            window.Alpine.data('kasbonForm', kasbonForm);
        }
    });
})();
