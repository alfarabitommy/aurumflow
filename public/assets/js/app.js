/* ==========================================================================
   AurumFlow — app.js (Fase 1)
   Vanilla JS, tanpa dependensi. Dua perilaku saja:
     1. Konfirmasi untuk form yang meminta (data-confirm).
     2. Cegah submit ganda pada tombol yang sudah diklik.
   ========================================================================== */

(function () {
    'use strict';

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
})();
