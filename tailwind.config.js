/**
 * ============================================================================
 * AurumFlow — konfigurasi Tailwind CSS (Fase 2)
 * ============================================================================
 * Sumber kebenaran token warna: docs/AurumFlow UI UX Documents.md § 1
 * ("Obsidian Gold" — Private WealthTech & Dark Obsidian Luxury).
 *
 * File ini HANYA dipakai saat kompilasi di mesin pengembang:
 *
 *   npx --yes tailwindcss@3.4.19 \
 *     -c tailwind.config.js \
 *     -i resources/css/tailwind.input.css \
 *     -o public/assets/css/app.min.css --minify
 *
 * Hasil kompilasi (public/assets/css/app.min.css) DI-COMMIT, sehingga server
 * shared hosting tidak memerlukan Node.js sama sekali (SAD § 2).
 *
 * Catatan: seluruh kelas dibangun lengkap di markup (tanpa kelas dinamis),
 * supaya JIT Tailwind dapat menemukannya — lihat risiko R-10 pada rencana.
 */

/** @type {import('tailwindcss').Config} */
module.exports = {
    content: [
        './app/Views/**/*.php',
        './public/assets/js/*.js',
    ],
    theme: {
        extend: {
            colors: {
                // --- Palet Obsidian Gold (UI/UX § 1) ---
                obsidian: {
                    950: '#07080B', // latar belakang utama aplikasi
                },
                surface: {
                    900: '#0F1117', // kartu & modal
                    800: '#181C26', // input field & kontainer sekunder
                },
                gold: {
                    primary: '#D4AF37', // aksen utama (Champagne Gold)
                    glow: '#F3E5AB',    // border tipis kartu & hover state
                },
                emerald: {
                    cash: '#10B981',    // saldo kas likuid & nominal laba bersih
                },
                // Nama kunci sengaja dipilih supaya kelas hasilnya sama persis
                // dengan token pada dokumen UI/UX: `text-pure`, `text-muted`.
                pure: '#F8FAFC',  // judul & angka nominal primer
                muted: '#94A3B8', // label sekunder & keterangan audit
            },
            fontFamily: {
                sans: ['"Plus Jakarta Sans"', 'Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
                mono: ['"JetBrains Mono"', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'Consolas', 'monospace'],
            },
            minHeight: {
                touch: '52px', // target sentuh minimum (UI/UX § 2.1)
            },
            boxShadow: {
                luxury: '0 8px 32px rgba(0, 0, 0, 0.6)',
            },
        },
    },
    plugins: [],
};
