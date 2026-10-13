/*
 * AurumFlow — probe lapis Alpine untuk nilai awal form operator.
 *
 *   node tests/manual/js_form_init_check.js
 *
 * Mengapa berkas ini ada
 * ----------------------
 * QC PHP (`tests/qc_*.php`) hanya melihat HTML yang dikirim peladen. Cacat
 * nyata pada layar operator muncul SETELAH Alpine berjalan di peramban:
 * `x-data.init()` membaca nilai awal lewat `this.$refs.form`, padahal Alpine
 * 3.17.4 baru mendaftarkan `x-ref` setelah `init()` komponen induk berjalan
 * (penelusuran DOM pre-order). Akibatnya model Alpine mulai dari `''` dan
 * `x-model` menghapus `value="…"` yang sudah dirender peladen.
 *
 * Berkas HTML tetap utuh, sehingga pemindaian statis maupun QcHttp tidak dapat
 * melihatnya. Probe ini menjalankan `public/assets/js/app.js` yang SEBENARNYA
 * di atas stub DOM minimal, dengan `$refs` sengaja dikosongkan untuk menirukan
 * urutan itu, lalu memeriksa perilaku yang dijanjikan form:
 *
 *   1. kode lama (`$refs`) membaca '' — akar masalah;
 *   2. kode baru membaca nilai pre-fill dari peladen;
 *   3. pratinjau kalkulasi langsung hidup tanpa mengetik ulang berat;
 *   4. isian hasil gagal validasi (422) — termasuk alokasi manual operator —
 *      tidak ditimpa saran sistem;
 *   5. status toggle jasa bakar mengikuti atribut `checked` dari peladen.
 *
 * Sengaja TIDAK dimasukkan ke `run_qc.sh`: gerbang QC harus tetap PHP-only
 * (hosting QC belum tentu punya Node.js). Preseden yang sama dipakai untuk
 * cermin kalkulasi Fase 2/3 dan kompilasi CSS.
 *
 * Rincian temuan: plan/Plan_Fix_Settlement_UI.md.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const APP_JS = path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'app.js');

const registry = {};
let alpineInitHandler = null;

const documentStub = {
    addEventListener(name, handler) {
        if (name === 'alpine:init') {
            alpineInitHandler = handler;
        }
    },
    querySelector: () => null,
    createElement: () => ({}),
    documentElement: {},
};

const windowStub = {
    Alpine: { data: (name, factory) => { registry[name] = factory; } },
    setTimeout: () => 0,
    clearTimeout: () => {},
    addEventListener: () => {},
};

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
    isFinite,
    parseInt,
    parseFloat,
    Error,
    Symbol,
    DataTransfer: undefined,
    FileReader: undefined,
    URL: { createObjectURL: () => 'blob:', revokeObjectURL: () => {} },
    navigator: { userAgent: 'node' },
};

sandbox.globalThis = sandbox;

vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(APP_JS, 'utf8'), sandbox, { filename: APP_JS });

if (typeof alpineInitHandler !== 'function') {
    throw new Error('handler alpine:init tidak terdaftar oleh app.js');
}

alpineInitHandler();

/** Stub elemen root komponen: hanya melayani selektor `[name="…"]`. */
function rootElement(fields) {
    return {
        querySelector(selector) {
            const match = /\[name="([^"]+)"\]/.exec(String(selector));

            return match ? (fields[match[1]] ?? null) : null;
        },
        // Grup radio (mis. `fee_type` pada modal dana talangan) diakses lewat
        // kunci `name[]`; `formRadioValue()` menelusurinya mencari yang tercentang.
        querySelectorAll(selector) {
            const match = /\[name="([^"]+)"\]/.exec(String(selector));

            return match && fields[match[1] + '[]'] ? fields[match[1] + '[]'] : [];
        },
    };
}

function field(value, checked) {
    return { value, checked: Boolean(checked) };
}

/** Kode lama: apa yang dilakukan `init()` sebelum perbaikan. */
function legacyValue($refs, name) {
    const form = $refs.form;
    const element = form && typeof form.elements !== 'undefined'
        ? form.elements.namedItem(name)
        : null;

    return element && typeof element.value === 'string' ? element.value : '';
}

/** Komponen Alpine dengan magic yang disuntikkan seperti aslinya.
 *
 * `$refs` sengaja KOSONG: itulah keadaan nyata saat `init()` berjalan. */
function buildComponent(factory, config, fields) {
    const component = registry[factory](config);

    component.$root = rootElement(fields);
    component.$refs = {};

    return component;
}

const failures = [];
let checks = 0;

function check(label, actual, expected) {
    checks += 1;

    if (actual !== expected) {
        failures.push(label + ': diharapkan ' + JSON.stringify(expected)
            + ', diperoleh ' + JSON.stringify(actual));
    }
}

// ---------------------------------------------------------------------------
// 1. Muat pertama: nilai dari peladen (sold_grams 18,050 / coord_fee 200.000),
//    kadar & harga masih kosong, dua alokasi kosong (= "pakai saran sistem").
// ---------------------------------------------------------------------------

const initialFields = {
    sold_grams: field('18,050'),
    density_pct: field(''),
    shop_price_per_g: field(''),
    coord_fee: field('200.000'),
    kas_split: field(''),
    brankas_split: field(''),
};

const settlement = buildComponent('settlementForm', {
    holding_mg: 18050,
    holding_cost_cents: 3515750000,
    active_deficit_cents: 0,
    receipt_soft_bytes: 256000,
    receipt_hard_bytes: 4194304,
}, initialFields);

settlement.init();

check('lama: sold_grams lewat $refs', legacyValue(settlement.$refs, 'sold_grams'), '');
check('baru: soldGrams', settlement.soldGrams, '18,050');
check('baru: coordFee', settlement.coordFee, '200.000');
check('baru: densityPercent', settlement.densityPercent, '');
check('baru: shopPrice', settlement.shopPrice, '');
check('baru: saran kas terisi otomatis', settlement.kasInput, '0');
check('baru: pratinjau belum berjalan tanpa kadar & harga', settlement.revenueLabel, 'Rp 0');

// Kadar & harga diisi operator: pratinjau harus langsung menghitung tanpa
// mengetik ulang berat (inti keluhan "harus mengetik ulang semuanya").
const pricedFields = {
    sold_grams: field('18,050'),
    density_pct: field('93,50'),
    shop_price_per_g: field('2.080.000'),
    coord_fee: field('200.000'),
    kas_split: field(''),
    brankas_split: field(''),
};

const priced = buildComponent('settlementForm', {
    holding_mg: 18050,
    holding_cost_cents: 3515750000,
    active_deficit_cents: 0,
}, pricedFields);

priced.init();

check('pratinjau: pendapatan', priced.revenueLabel, 'Rp 37.544.000');
check('pratinjau: laba bersih', priced.netLabel, 'Rp 2.186.500');
check('pratinjau: hak pengelola 80%', priced.operatorLabel, 'Rp 1.749.200');
check('pratinjau: pool investor 20%', priced.poolLabel, 'Rp 437.300');
check('pratinjau: total kas fisik', priced.totalCashLabel, 'Rp 37.344.000');
check('pratinjau: alokasi saran meja', priced.suggestedKasLabel, 'Rp 35.157.500');
check('pratinjau: alokasi saran brankas', priced.suggestedBrankasLabel, 'Rp 2.186.500');
check('tombol submit aktif dengan nota', (priced.receiptReady = true, priced.canSubmit), true);

// ---------------------------------------------------------------------------
// 2. Jalur 422: peladen mengembalikan isian operator (termasuk alokasi manual
//    Rp 30.000.000 / Rp 6.000.000). Semuanya harus dipertahankan.
// ---------------------------------------------------------------------------

const echoFields = {
    sold_grams: field('18,000'),
    density_pct: field('93,50'),
    shop_price_per_g: field('2.080.000'),
    coord_fee: field('200.000'),
    kas_split: field('30.000.000'),
    brankas_split: field('6.000.000'),
};

const echoed = buildComponent('settlementForm', {
    holding_mg: 18050,
    holding_cost_cents: 3515750000,
    active_deficit_cents: 0,
}, echoFields);

echoed.init();

check('422: berat diketik operator', echoed.soldGrams, '18,000');
check('422: kadar', echoed.densityPercent, '93,50');
check('422: harga', echoed.shopPrice, '2.080.000');
check('422: alokasi manual meja TIDAK ditimpa saran', echoed.kasInput, '30.000.000');
check('422: alokasi manual brankas TIDAK ditimpa saran', echoed.brankasInput, '6.000.000');

// ---------------------------------------------------------------------------
// 3. Layar kasir: berat/harga kosong pada GET (memang milik operator), dan
//    toggle jasa bakar mengikuti `checked` dari peladen (false = dimatikan).
// ---------------------------------------------------------------------------

const tellerFields = {
    dry_weight: field(''),
    buy_price_per_g: field(''),
    burn_fee_enabled: field('1', false),
};

// `saldo` dalam SEN (seperti config dari TellerController): Rp 50.000.000.
const teller = buildComponent('tellerForm', { fee: 2000000, saldo: 5000000000 }, tellerFields);

teller.init();

check('kasir: berat', teller.weight, '');
check('kasir: harga', teller.price, '');
check('kasir: toggle jasa bakar dari server', teller.applyFee, false);
check('kasir: tombol siap saat saldo cukup', (teller.weight = '14,850', teller.price = '1.950.000', teller.canPay), true);

const tellerEchoFields = {
    dry_weight: field('14,850'),
    buy_price_per_g: field('1.950.000'),
    burn_fee_enabled: field('1', false),
};

const tellerEcho = buildComponent('tellerForm', { fee: 2000000, saldo: 5000000000 }, tellerEchoFields);

tellerEcho.init();

check('kasir 422: berat terpelihara', tellerEcho.weight, '14,850');
check('kasir 422: harga terpelihara', tellerEcho.price, '1.950.000');
check('kasir 422: toggle tetap nonaktif', tellerEcho.applyFee, false);
check('kasir 422: net tanpa jasa bakar', tellerEcho.netLabel, 'Rp 28.957.500');

const tellerFeeOnFields = {
    dry_weight: field('14,850'),
    buy_price_per_g: field('1.950.000'),
    burn_fee_enabled: field('1', true),
};

const tellerFeeOn = buildComponent('tellerForm', { fee: 2000000, saldo: 5000000000 }, tellerFeeOnFields);

tellerFeeOn.init();

check('kasir: toggle aktif dari server', tellerFeeOn.applyFee, true);
check('kasir: net dengan jasa bakar (QC 2.1)', tellerFeeOn.netLabel, 'Rp 28.937.500');

// ---------------------------------------------------------------------------
// 4. Jalur submit: `submitting` menyala, tetapi DATA TIDAK BOLEH HILANG.
//
// Kontrol `disabled` dikeluarkan peramban dari data form — itulah yang dulu
// mengirim form kosong (hanya `_token`/`_once`) dan memicu 422 "wajib diisi".
// Sisi markup dijaga assertion H.18 (`qc_fase_3`) & H.13 (`qc_fase_2`); di sini
// dibuktikan komponen tidak membersihkan model/dataset saat pengiriman.
// ---------------------------------------------------------------------------

priced.receiptReady = true;
priced.onSubmit();

check('submit: flag submitting menyala', priced.submitting, true);
check('submit: berat tetap di model', priced.soldGrams, '18,050');
check('submit: kadar tetap di model', priced.densityPercent, '93,50');
check('submit: harga tetap di model', priced.shopPrice, '2.080.000');
check('submit: beban aparat tetap di model', priced.coordFee, '200.000');
check('submit: alokasi tetap terisi', priced.kasInput !== '' && priced.brankasInput !== '', true);

tellerEcho.confirm();

check('kasir submit: flag submitting menyala', tellerEcho.submitting, true);
check('kasir submit: berat tetap di model', tellerEcho.weight, '14,850');
check('kasir submit: harga tetap di model', tellerEcho.price, '1.950.000');

// ---------------------------------------------------------------------------
// 5. Modal "Terima Dana Talangan" (Fase 6): isian yang dirender peladen dibaca
//    `init()` — jalur 422 tidak boleh menghapus isian operator. Cermin angka
//    fee-nya diuji terpisah oleh `js_bridge_fee_check.js`.
// ---------------------------------------------------------------------------

const bridgeFields = {
    lender_name: field('Pak Haji Daud'),
    principal_amount: field('10.000.000'),
    fee_type: field('pct'),
    'fee_type[]': [
        { value: 'fixed', checked: false },
        { value: 'pct', checked: true },
    ],
    fee_rate_pct: field('1,00'),
    fee_fixed: field(''),
    notes: field('talangan semalam'),
};

const bridgeEcho = buildComponent('tellerForm', { fee: 2000000, saldo: 5000000000 }, bridgeFields);

bridgeEcho.init();

check('talangan 422: nama pemberi dana terpelihara', bridgeEcho.bridgeLender, 'Pak Haji Daud');
check('talangan 422: pokok terpelihara', bridgeEcho.bridgePrincipal, '10.000.000');
check('talangan 422: bentuk fee dari radio peladen', bridgeEcho.bridgeFeeType, 'pct');
check('talangan 422: tarif terpelihara', bridgeEcho.bridgeRate, '1,00');
check('talangan 422: catatan terpelihara', bridgeEcho.bridgeNotes, 'talangan semalam');
check('talangan 422: pratinjau fee konsisten', bridgeEcho.bridgeFeeLabel, 'Rp 100.000');
check('talangan 422: pratinjau pengembalian', bridgeEcho.bridgeReturnLabel, 'Rp 10.100.000');

bridgeEcho.bridgeSubmitting = true;

check('talangan: isian pokok tidak dibersihkan saat pengiriman',
    bridgeEcho.bridgePrincipal === '10.000.000' && bridgeEcho.bridgeSubmitReady === false,
    true);

console.log('assertion: ' + checks + ', gagal: ' + failures.length);

if (failures.length > 0) {
    failures.forEach((failure) => console.log('  [GAGAL] ' + failure));
    process.exit(1);
}

console.log('OK — seluruh perilaku nilai awal & pratinjau sesuai harapan');
