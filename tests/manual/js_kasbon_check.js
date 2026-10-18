/*
 * AurumFlow — probe lapis Alpine untuk Buku Kasbon Petani (Fase 8).
 *
 *   node tests/manual/js_kasbon_check.js
 *
 * Mengapa berkas ini ada
 * ----------------------
 * Halaman `/operator/kasbon` menampilkan sisa utang petani dan menyediakan
 * tombol cepat **20 % / 30 % / Lunas** yang mengisi isian nominal di peramban,
 * sedangkan peladen (`KasbonService`) selalu menghitung ulang dan memvalidasi
 * `0 < nominal <= sisa utang`. Cermin yang berbeda satu sen akan membuat kasir
 * mengetik angka yang salah di depan antrean — itulah yang diperiksa di sini.
 *
 * Probe ini menjalankan `public/assets/js/app.js` yang SEBENARNYA di atas stub
 * DOM minimal (pendekatan yang sama dengan `js_bridge_fee_check.js`) dan
 * memeriksa:
 *
 *   1. `pickPercent(20)`/`pickPercent(30)` dihitung dari sen integer sisa utang
 *      (half away from zero) — bukan dari teks yang diketik kasir;
 *   2. `payFull()` mengisi TEPAT sisa utang, dan pratinjau sisa setelah bayar
 *      tidak pernah negatif;
 *   3. nominal yang dihasilkan berbentuk `1.234.567` / `1.234.567,50` — tepat
 *      bentuk yang diterima `SettlementService::parseMoneyInput()` peladen;
 *   4. `init()` membaca isian yang dirender peladen (jalur `422`) tanpa
 *      menghapusnya, termasuk memilih petani dari label;
 *   5. label petani dipetakan ke `miner_id` lewat `data-miner-id`; label tak
 *      dikenal menghasilkan id kosong supaya peladen yang memutuskan.
 *
 * Sengaja TIDAK dimasukkan ke `run_qc.sh`: gerbang QC tetap PHP-only (hosting QC
 * belum tentu punya Node.js). Rincian: plan/Plan_Kasbon_Petani_AurumFlow.md T9.
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

if (typeof registry.kasbonForm !== 'function') {
    throw new Error('komponen kasbonForm tidak terdaftar oleh app.js');
}

const failures = [];
const checks = [];

function check(label, actual, expected) {
    checks.push(label);

    if (actual !== expected) {
        failures.push(label + ': diharapkan ' + JSON.stringify(expected)
            + ', diperoleh ' + JSON.stringify(actual));
    }
}

/** Opsi `<datalist>`: hanya `value` dan atribut data yang dibaca komponen. */
function minerOption(label, minerId, outstandingCents) {
    return {
        value: label,
        getAttribute(name) {
            if (name === 'data-miner-id') {
                return minerId;
            }

            return name === 'data-outstanding' ? String(outstandingCents) : null;
        },
    };
}

/** Stub root komponen: melayani `[name="…"]` dan `datalist option`. */
function rootStub(fields, options) {
    return {
        querySelector(selector) {
            const match = /\[name="([^"]+)"\]/.exec(String(selector));

            return match ? (fields[match[1]] ?? null) : null;
        },
        querySelectorAll(selector) {
            return String(selector).indexOf('datalist option') !== -1 ? options : [];
        },
    };
}

function field(value) {
    return { value };
}

/**
 * Komponen dengan nilai awal yang dirender peladen.
 *
 * @param {'disburse'|'repay'} mode
 * @param {Object} fields nilai DOM (`name` => string)
 * @param {Array} options isi `<datalist>`
 */
function component(mode, fields, options) {
    const instance = registry.kasbonForm({ mode: mode });

    instance.$root = rootStub(fields, options);
    instance.$refs = {};
    instance.init();

    return instance;
}

const BUDI_LABEL = 'Budi — 0812-3456-7890 | Sisa Utang: Rp 250.000';
const SARI_LABEL = 'Sari — Sungai Kandi | Sisa Utang: Rp 0';

const OPTIONS = [
    minerOption(BUDI_LABEL, '11111111-1111-4111-8111-111111111111', 25000000),
    minerOption(SARI_LABEL, '22222222-2222-4222-8222-222222222222', 0),
];

// ---------------------------------------------------------------------------
// 1. Tombol cepat: persentase dari sen integer
// ---------------------------------------------------------------------------

const repay = component('repay', {
    pay_miner_label: field(BUDI_LABEL),
    pay_miner_id: field('11111111-1111-4111-8111-111111111111'),
    amount: field(''),
}, OPTIONS);

check('init(): petani terpilih dikenali dari label', repay.minerId,
    '11111111-1111-4111-8111-111111111111');
check('init(): sisa utang petani terpilih (sen)', repay.outstandingCents, 25000000);

repay.pickPercent(20);
check('pickPercent(20) dari Rp 250.000', repay.amount, '50.000');

repay.pickPercent(30);
check('pickPercent(30) dari Rp 250.000', repay.amount, '75.000');

repay.payFull();
check('payFull() mengisi tepat sisa utang', repay.amount, '250.000');
check('payFull(): pratinjau sisa setelah bayar', repay.afterLabel, '0');

// Pembulatan half away from zero pada sisa bersen.
const odd = component('repay', {
    pay_miner_label: field(BUDI_LABEL),
    pay_miner_id: field('11111111-1111-4111-8111-111111111111'),
    amount: field(''),
}, OPTIONS);

odd.outstandingCents = 123457;
odd.pickPercent(20);
check('pickPercent(20) pada sisa bersen (half-up)', odd.amount, '246,91');

odd.pickPercent(33);
check('pickPercent(33) tidak melebihi sisa utang', odd.afterLabel !== '', true);

// ---------------------------------------------------------------------------
// 2. Pratinjau tidak pernah negatif & nominal tidak melebihi sisa
// ---------------------------------------------------------------------------

repay.amount = '300.000';
check('nominal > sisa utang: pratinjau dijepit ke 0', repay.afterLabel, '0');

repay.amount = '100.000';
check('nominal sebagian: pratinjau sisa', repay.afterLabel, '150.000');

check('tanpa nominal: pratinjau placeholder', component('repay', {
    pay_miner_label: field(BUDI_LABEL),
    pay_miner_id: field('11111111-1111-4111-8111-111111111111'),
    amount: field(''),
}, OPTIONS).afterLabel, '—');

// ---------------------------------------------------------------------------
// 3. Bentuk nominal yang diterima peladen
// ---------------------------------------------------------------------------

const shaped = component('repay', {
    pay_miner_label: field(SARI_LABEL),
    pay_miner_id: field('22222222-2222-4222-8222-222222222222'),
    amount: field(''),
}, OPTIONS);

shaped.outstandingCents = 1234567;
shaped.payFull();
check('bentuk nominal: ribuan titik + koma desimal', shaped.amount, '12.345,67');
check('bentuk nominal: diterima pola parseMoneyInput',
    /^\d{1,3}(\.\d{3})*(,\d{1,2})?$/.test(shaped.amount), true);

shaped.outstandingCents = 2500000000;
shaped.payFull();
check('bentuk nominal: rupiah penuh tanpa koma', shaped.amount, '25.000.000');

// ---------------------------------------------------------------------------
// 4. Jalur 422: isian peladen tidak dihapus init()
// ---------------------------------------------------------------------------

const returned = component('repay', {
    pay_miner_label: field(BUDI_LABEL),
    pay_miner_id: field('11111111-1111-4111-8111-111111111111'),
    amount: field('75.000'),
}, OPTIONS);

check('jalur 422: label petani terbaca dari DOM', returned.minerLabel, BUDI_LABEL);
check('jalur 422: nominal terbaca dari DOM', returned.amount, '75.000');
check('jalur 422: id petani terbaca dari DOM', returned.minerId,
    '11111111-1111-4111-8111-111111111111');

const disburse = component('disburse', {
    miner_label: field(SARI_LABEL),
    miner_id: field('22222222-2222-4222-8222-222222222222'),
    principal_amount: field('100.000'),
}, OPTIONS);

check('mode disburse: membaca miner_label/miner_id/principal_amount', disburse.minerId,
    '22222222-2222-4222-8222-222222222222');
check('mode disburse: nominal terbaca dari DOM', disburse.amount, '100.000');

// ---------------------------------------------------------------------------
// 5. Pemetaan label -> id (peladen tetap pemutus akhir)
// ---------------------------------------------------------------------------

const unknown = component('repay', {
    pay_miner_label: field('Petani Tidak Dikenal | Sisa Utang: Rp 0'),
    pay_miner_id: field(''),
    amount: field(''),
}, OPTIONS);

unknown.pickMiner();
check('label tak dikenal: miner_id kosong (peladen yang memutuskan)', unknown.minerId, '');
check('label tak dikenal: sisa utang 0', unknown.outstandingCents, 0);
check('label tak dikenal: tombol cepat tidak mengisi nominal',
    (function () {
        unknown.pickPercent(20);

        return unknown.amount;
    })(), '');

const picked = component('repay', {
    pay_miner_label: field(''),
    pay_miner_id: field(''),
    amount: field(''),
}, OPTIONS);

picked.minerLabel = SARI_LABEL;
picked.pickMiner();
check('label dikenal: id terpetakan dari data-miner-id', picked.minerId,
    '22222222-2222-4222-8222-222222222222');
check('label dikenal: sisa utang nol', picked.outstandingCents, 0);

console.log('assertion: ' + checks.length + ', gagal: ' + failures.length);

if (failures.length > 0) {
    failures.forEach((failure) => console.log('  [GAGAL] ' + failure));
    process.exit(1);
}

console.log('OK — cermin Buku Kasbon sesuai KasbonService (sen integer, tanpa float)');
