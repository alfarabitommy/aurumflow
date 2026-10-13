/*
 * AurumFlow — probe lapis Alpine untuk modul Dana Talangan (Fase 6).
 *
 *   node tests/manual/js_bridge_fee_check.js
 *
 * Mengapa berkas ini ada
 * ----------------------
 * Modal "Terima Dana Talangan" menampilkan perkiraan fee dan pengembalian di
 * sisi peramban (`tellerForm` di `public/assets/js/app.js`), sedangkan layar
 * rekonsiliasi menampilkan "Hak pengelola bersih" (`settlementForm`). Angka itu
 * hanya cermin: peladen selalu menghitung ulang lewat `BridgeLoanService` dan
 * `SettlementService`. Tetapi cermin yang berbeda satu sen akan membuat operator
 * mengambil keputusan dari angka yang salah — inilah yang diperiksa di sini.
 *
 * Probe ini menjalankan `public/assets/js/app.js` yang SEBENARNYA di atas stub
 * DOM minimal (pendekatan yang sama dengan `js_capital_simulator_check.js`) dan
 * memeriksa:
 *
 *   1. fee persen dihitung dengan rumus yang sama seperti peladen
 *      (`intdiv(pokok_sen x tarif_perseratus + 5000, 10000)`);
 *   2. fee nominal & isian kosong (tanpa fee) sesuai `quoteFee()`;
 *   3. nilai yang dirender peladen (jalur 422) dibaca ulang `init()`, termasuk
 *      radio bentuk fee;
 *   4. klamp penyerapan pada layar rekonsiliasi: `min(fee, hak pengelola)`,
 *      sisa `uncovered`, dan **pool mitra tidak pernah berubah**.
 *
 * Sengaja TIDAK dimasukkan ke `run_qc.sh`: gerbang QC tetap PHP-only (hosting
 * QC belum tentu punya Node.js). Rincian: plan/Plan_Fase_6_Talangan_AurumFlow.md
 * T8.
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
    BigInt,
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

['tellerForm', 'settlementForm'].forEach((name) => {
    if (typeof registry[name] !== 'function') {
        throw new Error('komponen ' + name + ' tidak terdaftar oleh app.js');
    }
});

const failures = [];
const checks = [];

function check(label, actual, expected) {
    checks.push(label);

    if (actual !== expected) {
        failures.push(label + ': diharapkan ' + JSON.stringify(expected)
            + ', diperoleh ' + JSON.stringify(actual));
    }
}

/** Stub elemen root komponen: hanya melayani selektor `[name="…"]`. */
function rootElement(fields) {
    return {
        querySelector(selector) {
            const match = /\[name="([^"]+)"\]/.exec(String(selector));

            return match ? (fields[match[1]] ?? null) : null;
        },
        querySelectorAll(selector) {
            const match = /\[name="([^"]+)"\]/.exec(String(selector));

            return match && fields[match[1] + '[]'] ? fields[match[1] + '[]'] : [];
        },
    };
}

function field(value, checked) {
    return { value, checked: checked === true };
}

/** Komponen Alpine dengan magic yang disuntikkan seperti aslinya. */
function buildComponent(name, config, fields) {
    const component = registry[name](config);

    component.$root = rootElement(fields);
    component.$refs = {};

    return component;
}

/** Komponen kasir dengan isian modal talangan apa adanya (tanpa DOM). */
function bridgeComponent() {
    const component = buildComponent('tellerForm', { fee: 2000000, saldo: 5000000000 }, {});

    component.init();

    return component;
}

// ---------------------------------------------------------------------------
// 1. Modal dana talangan: pratinjau fee (rumus identik dengan peladen)
//
// Rumus peladen (BridgeLoanService::quoteFee):
//   fee_sen = intdiv(pokok_sen * tarif_perseratus + 5000, 10000)
// ---------------------------------------------------------------------------

const teller = bridgeComponent();

teller.bridgeFeeType = 'fixed';
teller.bridgePrincipal = '10.000.000';
teller.bridgeFixed = '100.000';

check('nominal: pokok terbaca sebagai sen', teller.bridgePrincipalCents, 1000000000);
check('nominal: fee Rp 100.000', teller.bridgeFeeCents, 10000000);
check('nominal: total kembali Rp 10.100.000', teller.bridgeReturnCents, 1010000000);
check('nominal: label fee', teller.bridgeFeeLabel, 'Rp 100.000');
check('nominal: label kembali', teller.bridgeReturnLabel, 'Rp 10.100.000');

teller.bridgeFeeType = 'pct';
teller.bridgeRate = '1,00';

check('persen: 1,00 % x Rp 10.000.000 = Rp 100.000', teller.bridgeFeeCents, 10000000);
check('persen: hasil identik dengan nominal', teller.bridgeFeeCents, 10000000);

teller.bridgeRate = '0,01';
teller.bridgePrincipal = '999,99';

check('persen: 0,01 % x Rp 999,99 = Rp 0,10', teller.bridgeFeeCents, 10);

teller.bridgePrincipal = '0,10';
teller.bridgeRate = '5,00';

check('persen: pembulatan half away from zero (0,5 sen -> 1 sen)', teller.bridgeFeeCents, 1);

teller.bridgePrincipal = '1,00';
teller.bridgeRate = '50,00';

check('persen: pembulatan 50 sen tepat', teller.bridgeFeeCents, 50);

teller.bridgePrincipal = '1.000.001';
teller.bridgeRate = '3,33';

check('persen: Rp 1.000.001 x 3,33 % = Rp 33.300,03', teller.bridgeFeeCents, 3330003);

teller.bridgeRate = '';

check('persen tanpa tarif: fee 0 (peladen menolak, pratinjau tidak mengarang)', teller.bridgeFeeCents, 0);

teller.bridgeFeeType = 'fixed';
teller.bridgeFixed = '';

check('nominal kosong = talangan tanpa fee', teller.bridgeFeeCents, 0);

teller.bridgePrincipal = '';

check('pokok kosong: tanpa fee & tanpa pengembalian', teller.bridgeReturnCents, 0);
check('tombol kirim terkunci saat pokok kosong', teller.bridgeSubmitReady, false);

teller.bridgePrincipal = '5.000.000';
teller.bridgeFixed = '50.000';

check('tombol kirim terkunci saat nama pemberi dana kosong', teller.bridgeSubmitReady, false);

teller.bridgeLender = 'Pak Haji Daud';

check('tombol kirim aktif saat nama & pokok terisi', teller.bridgeSubmitReady, true);

teller.bridgeSubmitting = true;

check('tombol kirim terkunci selama pengiriman', teller.bridgeSubmitReady, false);

// ---------------------------------------------------------------------------
// 2. Nilai yang dirender peladen dibaca ulang `init()` (jalur 422)
// ---------------------------------------------------------------------------

const restoredFields = {
    lender_name: field('Pak Haji Daud'),
    principal_amount: field('10.000.000'),
    fee_type: field('pct'),
    'fee_type[]': [
        { value: 'fixed', checked: false },
        { value: 'pct', checked: true },
    ],
    fee_rate_pct: field('1,00'),
    fee_fixed: field('999.999'),
    notes: field('talangan semalam'),
};

const restored = buildComponent('tellerForm', { fee: 2000000, saldo: 5000000000 }, restoredFields);

restored.init();

check('init: nama pemberi dana dari DOM', restored.bridgeLender, 'Pak Haji Daud');
check('init: pokok dari DOM', restored.bridgePrincipal, '10.000.000');
check('init: radio bentuk fee tercentang peladen (pct)', restored.bridgeFeeType, 'pct');
check('init: tarif dari DOM', restored.bridgeRate, '1,00');
check('init: nominal dari DOM', restored.bridgeFixed, '999.999');
check('init: catatan dari DOM', restored.bridgeNotes, 'talangan semalam');
check('init: fee dihitung dari bentuk yang dicentang peladen', restored.bridgeFeeCents, 10000000);

// Tanpa radio tercentang: jatuh ke `fixed` (perilaku bawaan form).
const noRadio = buildComponent('tellerForm', { fee: 2000000, saldo: 5000000000 }, {});

noRadio.init();

check('init tanpa radio: bentuk fee jatuh ke nominal', noRadio.bridgeFeeType, 'fixed');

// ---------------------------------------------------------------------------
// 3. Layar rekonsiliasi: fee hanya menggerus hak pengelola
//
// Angka baku QC 3: laba bersih Rp 2.186.500 -> pengelola Rp 1.749.200,
// pool Rp 437.300 (sen: 174920000 / 43730000).
// ---------------------------------------------------------------------------

function settlementComponent(bridgeFeeCents, bridgeCount) {
    const component = buildComponent('settlementForm', {
        holding_mg: 18050,
        holding_cost_cents: 3515750000,
        active_deficit_cents: 0,
        bridge_fee_cents: bridgeFeeCents,
        bridge_count: bridgeCount,
        receipt_soft_bytes: 256000,
        receipt_hard_bytes: 4194304,
    }, {
        sold_grams: field('18,050'),
        density_pct: field('93,50'),
        shop_price_per_g: field('2.080.000'),
        coord_fee: field('200.000'),
        kas_split: field(''),
        brankas_split: field(''),
    });

    component.init();

    return component;
}

const settlement = settlementComponent(10000000, 1);

check('rekonsiliasi: hak pengelola (80 %)', settlement.operatorCents, 174920000);
check('rekonsiliasi: pool mitra (20 %)', settlement.poolCents, 43730000);
check('rekonsiliasi: fee diserap penuh', settlement.bridgeAbsorbedCents, 10000000);
check('rekonsiliasi: tanpa fee tak tertutup', settlement.bridgeUncoveredCents, 0);
check('rekonsiliasi: hak pengelola bersih', settlement.operatorTakeHomeCents, 164920000);
check('rekonsiliasi: label hak pengelola bersih', settlement.operatorTakeHomeLabel, 'Rp 1.649.200');
check('rekonsiliasi: label fee (pengurang)', settlement.bridgeFeeLabel, '- Rp 100.000');
check('rekonsiliasi: jumlah talangan tampil', settlement.bridgeCountLabel, '1');

// Fee melebihi hak pengelola (hak libur/minus): klamp, bukan pool.
const clamped = settlementComponent(200000000, 3);

check('klamp: fee Rp 2.000.000 > hak Rp 1.749.200', clamped.bridgeAbsorbedCents, 174920000);
check('klamp: sisa fee tak tertutup', clamped.bridgeUncoveredCents, 25080000);
check('klamp: hak pengelola bersih nol (tidak negatif)', clamped.operatorTakeHomeCents, 0);
check('klamp: label sisa tak tertutup', clamped.bridgeUncoveredLabel, 'Rp 250.800');
check('klamp: jumlah talangan tampil', clamped.bridgeCountLabel, '3');

// POOL MITRA tidak pernah terpengaruh oleh besaran fee (isolasi).
check('isolasi: pool tetap Rp 437.300 saat fee kecil', settlement.poolCents, 43730000);
check('isolasi: pool tetap Rp 437.300 saat fee melebihi hak pengelola', clamped.poolCents, 43730000);
check('isolasi: fee tidak mengubah hak pengelola bruto',
    clamped.operatorCents, settlement.operatorCents);

// Tanpa talangan: tidak ada baris fee (view menyembunyikannya lewat bridgeCount).
const noBridge = settlementComponent(0, 0);

check('tanpa talangan: fee nol', noBridge.bridgeFeeCents, 0);
check('tanpa talangan: hak pengelola bersih = hak pengelola', noBridge.operatorTakeHomeCents, 174920000);
check('tanpa talangan: jumlah talangan nol', noBridge.bridgeCountLabel, '0');

// ---------------------------------------------------------------------------
// 4. Regresi UI (temuan uji manual Fase 6): baris "Fee talangan" dan
//    "Hak pengelola bersih" TIDAK BOLEH bergantung pada laba sesi ini.
//
//    Ekspresi `x-show` diambil APA ADANYA dari view lalu dievaluasi seperti
//    Alpine (`new Function('s', 'with (s) { return (expr) }')`), untuk tiga
//    keadaan isian: form kosong, hari sehat, dan hari minus.
// ---------------------------------------------------------------------------

const VIEW = path.join(__dirname, '..', '..', 'app', 'Views', 'operator', 'settlement.php');
const viewSource = fs.readFileSync(VIEW, 'utf8');

/**
 * Ekspresi `x-show` pada elemen `<p>` yang memuat `$needle`.
 */
function showExpressionFor(needle) {
    const at = viewSource.indexOf(needle);

    if (at === -1) {
        return null;
    }

    const tagStart = viewSource.lastIndexOf('<p', at);
    const tagEnd = viewSource.indexOf('>', at);
    const tag = viewSource.slice(tagStart, tagEnd + 1);
    const match = /x-show="([^"]+)"/.exec(tag);

    return match ? match[1] : null;
}

/** Evaluasi ekspresi Alpine pada objek komponen. */
function alpineEval(scope, expression) {
    return new Function('__scope__', 'with (__scope__) { return (' + expression + ') }')(scope);
}

function settlementComponentWithFields(sold, density, price, coord, bridgeFee, bridgeCount) {
    const component = buildComponent('settlementForm', {
        holding_mg: 18050,
        holding_cost_cents: 3515750000,
        active_deficit_cents: 0,
        bridge_fee_cents: bridgeFee,
        bridge_count: bridgeCount,
        receipt_soft_bytes: 256000,
        receipt_hard_bytes: 4194304,
    }, {
        sold_grams: field(sold),
        density_pct: field(density),
        shop_price_per_g: field(price),
        coord_fee: field(coord),
        kas_split: field(''),
        brankas_split: field(''),
    });

    component.init();

    return component;
}

const feeRowExpr = showExpressionFor('Fee talangan (');
const takeHomeRowExpr = showExpressionFor('Hak pengelola bersih');
const uncoveredNoteExpr = showExpressionFor('Sebagian fee talangan (');

check('regresi UI: baris "Fee talangan" memakai kondisi talangan-aktif',
    feeRowExpr, 'bridgeHasActive');
check('regresi UI: baris "Hak pengelola bersih" memakai kondisi yang sama',
    takeHomeRowExpr, feeRowExpr);
check('regresi UI: peringatan fee tak tertutup punya kondisinya sendiri',
    uncoveredNoteExpr, 'bridgeHasUncovered');
check('regresi UI: baris fee tidak menyebut angka laba',
    /operatorCents|netCents|bridgeUncoveredCents/.test(String(feeRowExpr)), false);

const uiStates = [
    ['kosong', '', '', '', ''],
    ['hari sehat', '18,050', '93,50', '2.080.000', '200.000'],
    ['hari minus', '18,050', '93,50', '1.900.000', '200.000'],
];

uiStates.forEach(([label, sold, density, price, coord]) => {
    const scope = settlementComponentWithFields(sold, density, price, coord, 10000000, 1);

    check('regresi UI [' + label + ']: baris fee tetap tampil',
        alpineEval(scope, String(feeRowExpr)), true);
    check('regresi UI [' + label + ']: baris hak pengelola bersih tetap tampil',
        alpineEval(scope, String(takeHomeRowExpr)), true);
    check('regresi UI [' + label + ']: peringatan fee tak tertutup',
        alpineEval(scope, String(uncoveredNoteExpr)), label !== 'hari sehat');
});

// Nilai yang ditampilkan pada hari sehat harus terisi (bukan placeholder Rp 0).
const healthy = settlementComponentWithFields('18,050', '93,50', '2.080.000', '200.000', 10000000, 1);

check('regresi UI: nilai baris fee pada hari sehat', healthy.bridgeFeeLabel, '- Rp 100.000');
check('regresi UI: nilai hak pengelola bersih pada hari sehat', healthy.operatorTakeHomeLabel, 'Rp 1.649.200');
check('regresi UI: pool mitra tidak tersentuh fee', healthy.poolLabel, 'Rp 437.300');

// Tanpa talangan aktif: baris disembunyikan (kondisi tetap satu pemilik).
const none = settlementComponentWithFields('18,050', '93,50', '2.080.000', '200.000', 0, 0);

check('regresi UI: tanpa talangan baris fee disembunyikan',
    alpineEval(none, String(feeRowExpr)), false);
check('regresi UI: tanpa talangan peringatan pun disembunyikan',
    alpineEval(none, String(uncoveredNoteExpr)), false);

console.log('assertion: ' + checks.length + ', gagal: ' + failures.length);

if (failures.length > 0) {
    failures.forEach((failure) => console.log('  [GAGAL] ' + failure));
    process.exit(1);
}

console.log('OK — cermin fee dana talangan sesuai BridgeLoanService & SettlementService');
