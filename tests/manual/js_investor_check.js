/*
 * AurumFlow — probe lapis Alpine untuk portal mitra (Fase 5).
 *
 *   node tests/manual/js_investor_check.js
 *
 * Mengapa berkas ini ada
 * ----------------------
 * Tiga hal pada portal mitra hidup di sisi peramban dan tidak dapat dilihat QC
 * PHP (HTML yang dikirim peladen tetap benar, sedangkan perilakunya baru
 * muncul setelah Alpine berjalan):
 *
 *   1. **Papan angka PIN** (`investorLogin`) hanya boleh mengisi enam digit,
 *      menolak digit ke-7, dan menghapus satu digit terakhir — tanpa pernah
 *      menyentuh aturan keamanan apa pun (kebijakan PIN, pepper, dan pembatas
 *      percobaan tetap milik peladen).
 *   2. **Dasbor mitra** (`investorDashboard`) hanya *menampilkan* nominal yang
 *      sudah diformat peladen: "isi seluruh dana" mengambil angka dari
 *      konfigurasi (bukan menghitung ulang), teks ketersediaan/jatuh
 *      tempo/konfirmasi mengikuti jenis penarikan, dan tidak ada aritmetika
 *      uang yang dihitung di peramban.
 *   3. **Registrasi Service Worker** hanya berjalan pada konteks aman
 *      (HTTPS/localhost) dan memakai `data-app-base` sebagai scope, sehingga
 *      pemasangan pada subdirektori tidak mendaftarkan scope yang salah.
 *
 * Probe ini menjalankan `public/assets/js/app.js` yang SEBENARNYA di atas stub
 * DOM minimal (pendekatan yang sama dengan `js_form_init_check.js`).
 *
 * Sengaja TIDAK dimasukkan ke `run_qc.sh`: gerbang QC tetap PHP-only (hosting
 * QC belum tentu punya Node.js). Rincian: plan/Plan_Fase_5_AurumFlow.md T9.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const APP_JS = path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'app.js');
const APP_SOURCE = fs.readFileSync(APP_JS, 'utf8');

const failures = [];
const checks = [];

function check(label, actual, expected) {
    checks.push(label);

    if (actual !== expected) {
        failures.push(label + ': diharapkan ' + JSON.stringify(expected)
            + ', diperoleh ' + JSON.stringify(actual));
    }
}

/**
 * Bangun sandbox yang menjalankan app.js apa adanya.
 *
 * @param {object} overrides penyesuaian untuk uji PWA (navigator/location/…)
 * @returns {{registry: object, loadHandlers: Array<Function>}}
 */
function runApp(overrides) {
    const registry = {};
    const loadHandlers = [];
    let alpineInitHandler = null;

    const documentStub = Object.assign({
        addEventListener(name, handler) {
            if (name === 'alpine:init') {
                alpineInitHandler = handler;
            }
        },
        querySelector: () => null,
        createElement: () => ({}),
        documentElement: {},
        body: { getAttribute: () => null },
    }, (overrides || {}).document);

    const windowStub = Object.assign({
        Alpine: { data: (name, factory) => { registry[name] = factory; } },
        setTimeout: () => 0,
        clearTimeout: () => {},
        addEventListener(name, handler) {
            if (name === 'load') {
                loadHandlers.push(handler);
            }
        },
    }, (overrides || {}).window);

    const sandbox = {
        window: windowStub,
        document: documentStub,
        console,
        setTimeout: windowStub.setTimeout,
        clearTimeout: windowStub.clearTimeout,
        Math,
        Number,
        String,
        Array,
        Object,
        JSON,
        RegExp,
        Date,
        Promise,
        BigInt,
        isFinite,
        parseInt,
        parseFloat,
        Error,
        Symbol,
        DataTransfer: undefined,
        FileReader: undefined,
        URL: { createObjectURL: () => 'blob:', revokeObjectURL: () => {} },
        navigator: Object.assign({ userAgent: 'node' }, (overrides || {}).navigator),
        location: (overrides || {}).location,
    };

    // `location` hanya ada bila diuji: app.js memakai `location.protocol`
    // setelah lolos pemeriksaan konteks aman.
    if (!sandbox.location) {
        delete sandbox.location;
    }

    sandbox.globalThis = sandbox;

    vm.createContext(sandbox);
    vm.runInContext(APP_SOURCE, sandbox, { filename: APP_JS });

    if (typeof alpineInitHandler !== 'function') {
        throw new Error('handler alpine:init tidak terdaftar oleh app.js');
    }

    alpineInitHandler();

    // Jalankan handler `load` (registrasi service worker memakainya).
    loadHandlers.forEach((handler) => handler());

    return { registry, loadHandlers, window: windowStub };
}

/** Stub elemen root komponen: hanya melayani selektor `[name="…"]`. */
function rootElement(fields) {
    return {
        querySelector(selector) {
            const match = /\[name="([^"]+)"\]/.exec(String(selector));

            return match ? (fields[match[1]] ?? null) : null;
        },
    };
}

function field(value) {
    return { value, checked: false, focus() {} };
}

function build(registry, name, config, fields) {
    const component = registry[name](config);

    component.$root = rootElement(fields);
    component.$refs = {};

    return component;
}

// ---------------------------------------------------------------------------
// 1. Papan angka PIN (`investorLogin`)
// ---------------------------------------------------------------------------

const loginRun = runApp({});
const pinField = field('12');

const login = build(loginRun.registry, 'investorLogin', { pinLength: 6 }, { pin: pinField });

check('komponen investorLogin terdaftar', typeof loginRun.registry.investorLogin, 'function');
check('papan angka memuat 10 tombol', login.digits.length, 10);
check('digits berisi 1..9 dan 0', login.digits.join(''), '1234567890');

login.press('3');
check('press() menambahkan digit', pinField.value, '123');

login.erase();
check('erase() menghapus digit terakhir', pinField.value, '12');

for (let i = 0; i < 10; i++) {
    login.press('9');
}

check('PIN tidak pernah melebihi 6 digit', pinField.value.length, 6);
check('isi PIN tepat 6 digit', pinField.value, '129999');

for (let i = 0; i < 10; i++) {
    login.erase();
}

check('erase() mengosongkan sampai habis tanpa galat', pinField.value, '');

// ---------------------------------------------------------------------------
// 2. Dasbor mitra (`investorDashboard`)
// ---------------------------------------------------------------------------

const dashboardRun = runApp({});
const amountField = field('');

const dashboard = build(dashboardRun.registry, 'investorDashboard', {
    profit_label: '379.341,18',
    capital_label: '20.000.000,00',
    capital_due: '09/10/2026',
    initial_type: 'profit',
}, { withdrawal_amount: amountField });

check('komponen investorDashboard terdaftar', typeof dashboardRun.registry.investorDashboard, 'function');
check('jenis awal mengikuti konfigurasi peladen', dashboard.type, 'profit');

check('ketersediaan awal memakai label peladen', dashboard.availableText, 'Tersedia: 379.341,18');

dashboard.fillAll();
check('fillAll() mengisi label dividen dari peladen', dashboard.amount, '379.341,18');

dashboard.choose('capital');
check('choose("capital") mengganti jenis penarikan', dashboard.type, 'capital');
check('ketersediaan ikut berganti', dashboard.availableText, 'Tersedia: 20.000.000,00');

dashboard.fillAll();
check('fillAll() mengisi label modal dari peladen', dashboard.amount, '20.000.000,00');

check(
    'dueText menyebut H-7 hari kerja untuk modal',
    dashboard.dueText,
    'Estimasi kas siap: 09/10/2026 (masa tunggu H-7 hari kerja).'
);

check(
    'confirmText menyebut jatuh tempo yang dirender peladen',
    dashboard.confirmText,
    'Ajukan penarikan modal pokok? Kas siap 09/10/2026 (H-7 hari kerja).'
);

dashboard.choose('profit');
check('choose("profit") kembali ke dividen', dashboard.type, 'profit');

check(
    'dueText menjelaskan dividen tanpa masa tunggu',
    dashboard.dueText,
    'Dana diproses pengelola setelah disetujui; dividen tidak melewati masa tunggu.'
);

check(
    'confirmText dividen tidak menyebut jatuh tempo',
    dashboard.confirmText,
    'Ajukan penarikan keuntungan? Pengelola akan memprosesnya.'
);

dashboard.choose('capital-salah');
check('jenis tak dikenal jatuh kembali ke dividen', dashboard.type, 'profit');

// Pengikatan state Alpine ke markup (yang membuat nilai di atas benar-benar
// terkirim saat form dikirim).
const dashboardView = fs.readFileSync(
    path.join(__dirname, '..', '..', 'app', 'Views', 'investor', 'dashboard.php'),
    'utf8'
);
const loginView = fs.readFileSync(
    path.join(__dirname, '..', '..', 'app', 'Views', 'investor', 'login.php'),
    'utf8'
);

check('view dasbor mengikat x-model="amount"', dashboardView.includes('x-model="amount"'), true);
check('view dasbor memakai komponen investorDashboard', dashboardView.includes('investorDashboard('), true);
check('view dasbor mengirim token sekali-pakai', dashboardView.includes('Security::FORM_FIELD'), true);
check('view pintu PIN memakai komponen investorLogin', loginView.includes('investorLogin('), true);

// Bootstrap Alpine milik layout: tanpa `alpine.min.js`, `x-data`/`x-text`/`x-cloak`
// pada view di atas tidak pernah dievaluasi — tombol ber-`x-text` dirender kosong
// dan papan angka tidak pernah muncul (plan/Audit_Investor_Login_Empty_Box.md § 3).
const authLayout = fs.readFileSync(
    path.join(__dirname, '..', '..', 'app', 'Views', 'layouts', 'auth.php'),
    'utf8'
);

check(
    'layout auth memuat bootstrap Alpine',
    authLayout.includes('assets/js/alpine.min.js'),
    true
);
check(
    'view pintu PIN menyembunyikan papan angka sampai Alpine siap',
    /class="grid grid-cols-3 gap-2"[^>]*x-cloak/.test(loginView),
    true
);

// ---------------------------------------------------------------------------
// 2b. Tombol salin tautan undangan (inline `x-data` + rantai Promise)
//
// Riwayat singkat: tombol ini pernah memakai komponen `Alpine.data(
// 'clipboardButton', …)`. Bila aset JS yang di-cache peramban belum memuat
// komponen itu, `x-data="clipboardButton(…)"` gagal init
// (`ReferenceError: clipboardButton is not defined`) dan tombolnya HILANG —
// `x-text` tidak pernah dievaluasi. Perbaikan berikutnya menanam seluruh
// perilakunya di view dengan state literal, sehingga tidak ada lagi
// ketergantungan pada komponen yang bisa tidak dikenal.
// ---------------------------------------------------------------------------

const capitalView = fs.readFileSync(
    path.join(__dirname, '..', '..', 'app', 'Views', 'operator', 'capital.php'),
    'utf8'
);
const appSource = fs.readFileSync(APP_JS, 'utf8');

// Baris komentar dibuang lebih dulu: prosa pada view menyebut nama komponen
// lama untuk menjelaskan riwayatnya, dan pemindaian harus menilai KODE-nya
// (perilaku yang sama dengan `qc_scan_lines()` di harness PHP).
const capitalViewCode = capitalView
    .split(/\r?\n/)
    .filter((line) => !/^\s*(\/\/|\*|\/\*|#)/.test(line))
    .join('\n');

check(
    'view memakai state literal x-data="{ copied: false }"',
    capitalViewCode.includes('x-data="{ copied: false }"'),
    true
);
check(
    'view tidak lagi bergantung pada komponen clipboardButton',
    capitalViewCode.includes('clipboardButton'),
    false
);
check(
    'app.js tidak lagi mendaftarkan komponen clipboardButton',
    appSource.includes('clipboardButton'),
    false
);
check(
    'umpan balik visual "Tersalin ✓" dipertahankan',
    capitalView.includes('x-text="copied ? \'Tersalin ✓\' : \'Salin tautan\'"'),
    true
);
check(
    'kelas visual saat tersalin dipertahankan',
    capitalView.includes(':class="copied ? \'text-emerald-500\' : \'\'"'),
    true
);
check(
    'atribut @click tidak memuat pernyataan try/catch',
    /@click="[^"]*\b(try|catch)\b/.test(capitalViewCode),
    false
);

// Ekspresi `@click` diuji persis seperti Alpine mengevaluasinya:
// `with ($data) { return <ekspresi> }` — bentuk inilah yang menolak `try`
// ("Unexpected token 'try'"). Tautan PHP diganti contoh URL, karena berkas
// view yang dibaca di sini belum dirender.
// Tag PHP di dalam string ikut cocok lewat `.*?` (isinya memuat tanda kutip
// PHP seperti `$wallet['invite_url']` yang tidak ada pada HTML terkirim).
const clickPattern = /@click="(navigator\.clipboard\.writeText\(.*?'\)\.then\(\(\) => \{[^"]*\}\))"/;
const clickMatch = clickPattern.exec(capitalViewCode);

check('ekspresi @click ditemukan dan berbentuk rantai Promise', clickMatch !== null, true);

let pendingTimeout = null;
let copiedTexts = [];
let copiedState = null;
let inlinePromise = null;
let compileError = '';

if (clickMatch) {
    const expression = clickMatch[1].replace(/<\?=[^?]*\?>/g, 'https://contoh.test/join?token=abc123');

    copiedState = { copied: false };

    try {
        const evaluator = new Function(
            '$data',
            'navigator',
            'setTimeout',
            'with ($data) { return ' + expression + ' }'
        );

        inlinePromise = evaluator(
            copiedState,
            {
                clipboard: {
                    writeText: (text) => {
                        copiedTexts.push(text);

                        return Promise.resolve();
                    },
                },
            },
            (callback) => {
                pendingTimeout = callback;

                return 0;
            }
        );
    } catch (error) {
        compileError = String(error && error.message ? error.message : error);
    }
}

check('ekspresi @click dapat dikompilasi seperti Alpine (tanpa SyntaxError)', compileError, '');
check('ekspresi @click mengembalikan Promise', inlinePromise instanceof Promise, true);

if (inlinePromise) {
    inlinePromise.then(() => {
        check('klik mengirim tautan undangan ke papan klip', copiedTexts[0], 'https://contoh.test/join?token=abc123');
        check('status `copied` menjadi true', copiedState.copied, true);

        if (typeof pendingTimeout === 'function') {
            pendingTimeout();
        }

        check('status kembali false setelah jeda', copiedState.copied, false);
        check('timer umpan balik terdaftar', typeof pendingTimeout, 'function');

        finish();
    });
} else {
    finish();
}

// ---------------------------------------------------------------------------
// 3. Registrasi Service Worker (PWA)
// ---------------------------------------------------------------------------

const registered = [];

const pwaRun = runApp({
    navigator: {
        serviceWorker: {
            register: (url, options) => {
                registered.push({ url, options });

                return Promise.resolve();
            },
        },
    },
    location: { protocol: 'https:', hostname: 'contoh.test' },
    window: { isSecureContext: true },
    document: {
        body: {
            getAttribute: (name) => (name === 'data-app-base' ? '/aurumflow/' : null),
        },
    },
});

check('registrasi SW dilakukan pada konteks aman', registered.length, 1);
check('berkas SW diambil dari basis aplikasi', registered[0] && registered[0].url, '/aurumflow/sw.js');
check('scope SW mengikuti basis aplikasi', registered[0] && registered[0].options.scope, '/aurumflow/');

const insecure = [];

runApp({
    navigator: {
        serviceWorker: {
            register: (url) => {
                insecure.push(url);

                return Promise.resolve();
            },
        },
    },
    location: { protocol: 'http:', hostname: 'contoh.test' },
    window: { isSecureContext: false },
});

check('origin tidak aman tidak mendaftarkan service worker', insecure.length, 0);
check('komponen Fase 5 tetap terdaftar meski SW dilewati', typeof pwaRun.registry.investorDashboard, 'function');

// ---------------------------------------------------------------------------
// Ringkasan
// ---------------------------------------------------------------------------

function finish() {
console.log('Probe lapis Alpine — portal mitra (Fase 5)');
console.log('Berkas: ' + path.relative(process.cwd(), APP_JS));

checks.forEach((label) => console.log('  [OK] ' + label));
failures.forEach((message) => console.log('  [GAGAL] ' + message));

console.log('');
console.log('HASIL js_investor_check: ' + (checks.length - failures.length) + '/' + checks.length + ' PASS, '
    + failures.length + ' FAIL');

process.exit(failures.length === 0 ? 0 : 1);
}
