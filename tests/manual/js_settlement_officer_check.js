/*
 * AurumFlow — probe lapis Alpine untuk buku hak mitra lapangan (Fase 10).
 *
 *   node tests/manual/js_settlement_officer_check.js
 *
 * Mengapa berkas ini ada
 * ----------------------
 * Layar rekonsiliasi menampilkan pratinjau **potongan mitra lapangan** dan
 * **bonus akrual** di sisi peramban (`settlementForm` di
 * `public/assets/js/app.js`), sedangkan peladen menghitung ulang lewat
 * `OfficerService::previewCuts()` dan `SettlementService::quote()`.
 *
 * Cermin yang menyimpang satu sen akan membuat operator mengeksekusi tutup buku
 * dengan angka yang salah pada layar — dan justru angka itu yang dibaca saat
 * memutuskan alokasi kas. Probe ini menjalankan `app.js` yang SEBENARNYA di atas
 * stub DOM minimal (pendekatan yang sama dengan `js_bridge_fee_check.js`) dan
 * memeriksa:
 *
 *   1. potongan = `floor((basis × pct_perseratus + 5000) / 10000)` dengan
 *      `basis = max(0, laba kotor)` — identik dengan `OfficerService`;
 *   2. hari minus → potongan 0;
 *   3. cap: Σ potongan tidak pernah melebihi laba kotor;
 *   4. bonus akrual mengurangi laba **sekali** (bukan dua kali), dan hanya
 *      selama peladen mengirimnya sebagai belum terserap;
 *   5. bagi hasil 80 : 20 tetap dihitung dari laba bersih setelah potongan;
 *   6. `total_cash` tetap = pendapatan toko (aturan Fase 9), sehingga saran
 *      `kas` memuat uang yang terparkir untuk petugas;
 *   7. penjagaan kirim: beban + bonus + potongan tidak boleh melebihi
 *      pendapatan toko;
 *   8. dua baris pratinjau di markup terikat ke properti komponen yang benar.
 *
 * Sengaja TIDAK dimasukkan ke `run_qc.sh`: gerbang QC tetap PHP-only (hosting
 * QC belum tentu punya Node.js). Rincian: plan/Plan_Fase_10_Mitra_Lapangan_AurumFlow.md
 * § 8.5.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const APP_JS = path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'app.js');
const SETTLEMENT_VIEW = path.join(__dirname, '..', '..', 'app', 'Views', 'operator', 'settlement.php');

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

if (typeof registry.settlementForm !== 'function') {
    throw new Error('komponen settlementForm tidak terdaftar oleh app.js');
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

/** Stub elemen root komponen: hanya melayani selektor `[name="…"]`. */
function rootElement(fields) {
    return {
        querySelector(selector) {
            const match = /\[name="([^"]+)"\]/.exec(String(selector));

            return match ? (fields[match[1]] ?? null) : null;
        },
        querySelectorAll() {
            return [];
        },
    };
}

function field(value) {
    return { value, checked: false };
}

/**
 * Komponen `settlementForm` dengan isian penjualan toko seperti dirender peladen.
 *
 * @param {object} config konfigurasi dari `SettlementController` (sen integer)
 * @param {object} fields isian DOM (jalur 422 / halaman pertama)
 */
function settlementComponent(config, fields) {
    const component = registry.settlementForm(Object.assign({
        holding_mg: 0,
        holding_cost_cents: 0,
        active_deficit_cents: 0,
        opex_fee_cents: 0,
        bridge_fee_cents: 0,
        bridge_count: 0,
        officer_pcts: [],
        officer_bonus_cents: 0,
        receipt_soft_bytes: 256000,
        receipt_hard_bytes: 4194304,
    }, config));

    component.$root = rootElement(fields || {});
    component.$refs = {};
    component.init();

    return component;
}

// ---------------------------------------------------------------------------
// 1. Angka Protokol QC Manual 10 (18,050 gr @ Rp 2.080.000; OPEX 200.000;
//    bonus akrual 150.000; satu petugas 2,50 %)
// ---------------------------------------------------------------------------

const protocolFields = {
    sold_grams: field('18,050'),
    density_pct: field('93,50'),
    shop_price_per_g: field('2.080.000'),
    kas_split: field(''),
    brankas_split: field(''),
};

const withOfficer = settlementComponent({
    holding_mg: 18050,
    holding_cost_cents: 3515750000,
    opex_fee_cents: 20000000,
    officer_pcts: [250],
    officer_bonus_cents: 15000000,
}, protocolFields);

check('pendapatan toko 18,050 gr x Rp 2.080.000', withOfficer.revenueCents, 3754400000);
check('laba kotor Rp 2.386.500', withOfficer.grossCents, 238650000);
check('basis potongan = max(0, laba kotor)', withOfficer.grossPositiveCents, 238650000);
check('satu petugas terhitung', withOfficer.officerCount, 1);
check('potongan 2,50 % = Rp 59.662,50 (5.966.250 sen)', withOfficer.officerCutCents, 5966250);
check('label potongan (pembulatan tampilan)', withOfficer.officerCutLabel, '- Rp 59.663');
check('label bonus akrual', withOfficer.officerBonusLabel, '- Rp 150.000');

check('laba setelah beban + bonus + potongan = Rp 1.976.837,50', withOfficer.afterFeeCents, 197683750);
check('laba bersih = Rp 1.976.837,50', withOfficer.netCents, 197683750);
check('hak pengelola 80 % = Rp 1.581.470', withOfficer.operatorCents, 158147000);
check('pool mitra 20 % = Rp 395.367,50', withOfficer.poolCents, 39536750);
check('hak pengelola + pool = laba bersih', withOfficer.operatorCents + withOfficer.poolCents, withOfficer.netCents);

check('total_cash tetap = pendapatan toko (aturan Fase 9)', withOfficer.totalCashCents, 3754400000);
check('saran brankas = laba bersih', withOfficer.suggestedBrankasCents, 197683750);
check('saran kas = pendapatan − laba (memuat hak petugas)', withOfficer.suggestedKasCents, 3556716250);
check('isian terisi dianggap sah', withOfficer.inputValid, true);
check('alokasi saran berjumlah pas', withOfficer.splitBalanced, true);

// ---------------------------------------------------------------------------
// 2. Tanpa petugas & tanpa bonus: kembali ke angka Fase 9 (diferensial)
// ---------------------------------------------------------------------------

const ohneOfficer = settlementComponent({
    holding_mg: 18050,
    holding_cost_cents: 3515750000,
    opex_fee_cents: 20000000,
}, protocolFields);

check('tanpa petugas: potongan 0', ohneOfficer.officerCutCents, 0);
check('tanpa petugas: jumlah petugas 0', ohneOfficer.officerCount, 0);
check('tanpa petugas: label potongan nol', ohneOfficer.officerCutLabel, '- Rp 0');
check('tanpa petugas: laba bersih = Fase 9 (Rp 2.186.500)', ohneOfficer.netCents, 218650000);
check('petugas menurunkan laba bersih (monoton turun)',
    withOfficer.netCents < ohneOfficer.netCents, true);
check('selisih = potongan + bonus',
    ohneOfficer.netCents - withOfficer.netCents, 5966250 + 15000000);

// ---------------------------------------------------------------------------
// 3. Bonus mengurangi laba SEKALI (tidak pernah dua kali)
// ---------------------------------------------------------------------------

const bonusOnly = settlementComponent({
    holding_mg: 18050,
    holding_cost_cents: 3515750000,
    opex_fee_cents: 20000000,
    officer_pcts: [250],
    officer_bonus_cents: 0,
}, protocolFields);

check('bonus terserap (0) → tidak ada pengurangan bonus', bonusOnly.officerBonusCents, 0);
check('bonus terserap → label nol', bonusOnly.officerBonusLabel, '- Rp 0');
check('bonus akrual mengurangi laba tepat sebesar nominalnya',
    bonusOnly.netCents - withOfficer.netCents, 15000000);
check('potongan tidak terpengaruh bonus', bonusOnly.officerCutCents, withOfficer.officerCutCents);

// ---------------------------------------------------------------------------
// 4. Hari minus: potongan 0, tidak ada basis
// ---------------------------------------------------------------------------

const minusDay = settlementComponent({
    holding_mg: 18050,
    holding_cost_cents: 4000000000,
    opex_fee_cents: 0,
    officer_pcts: [250],
}, protocolFields);

check('hari minus: laba kotor negatif', minusDay.grossCents < 0, true);
check('hari minus: basis potongan 0', minusDay.grossPositiveCents, 0);
check('hari minus: potongan 0', minusDay.officerCutCents, 0);
check('hari minus: bagi hasil 0 / 0', minusDay.operatorCents, 0);
check('hari minus: pool mitra 0', minusDay.poolCents, 0);

// ---------------------------------------------------------------------------
// 5. Cap: Σ potongan tidak pernah melebihi laba kotor
// ---------------------------------------------------------------------------

const tooManyOfficers = settlementComponent({
    holding_mg: 18050,
    holding_cost_cents: 3515750000,
    opex_fee_cents: 0,
    officer_pcts: [6000, 6000],
}, protocolFields);

check('Σ tarif 120 %: dua petugas terhitung', tooManyOfficers.officerCount, 2);
check('Σ tarif 120 %: potongan dibatasi laba kotor', tooManyOfficers.officerCutCents, 238650000);
check('Σ tarif 120 %: laba bersih tidak negatif', tooManyOfficers.netCents, 0);

// ---------------------------------------------------------------------------
// 6. Penjagaan kirim: beban + bonus + potongan ≤ pendapatan toko
// ---------------------------------------------------------------------------

const overExpense = settlementComponent({
    holding_mg: 18050,
    holding_cost_cents: 3515750000,
    opex_fee_cents: 3754400000,
    officer_bonus_cents: 100000,
}, protocolFields);

check('beban + hak petugas > pendapatan → isian tidak sah', overExpense.inputValid, false);

// ---------------------------------------------------------------------------
// 7. Nilai yang dirender peladen dibaca ulang `init()` (jalur 422)
// ---------------------------------------------------------------------------

const restored = settlementComponent({
    holding_mg: 18050,
    holding_cost_cents: 3515750000,
    opex_fee_cents: 20000000,
    officer_pcts: [250],
    officer_bonus_cents: 15000000,
}, protocolFields);

check('init: berat jual dari DOM', restored.soldGrams, '18,050');
check('init: kadar dari DOM', restored.densityPercent, '93,50');
check('init: harga dari DOM', restored.shopPrice, '2.080.000');
check('init: potongan dihitung dari roster peladen', restored.officerCutCents, 5966250);
check('init: saran kas langsung terisi termasuk sen (Rp 35.567.162,50)', restored.kasInput, '35.567.162,50');
check('init: saran brankas termasuk sen (Rp 1.976.837,50)', restored.brankasInput, '1.976.837,50');

// ---------------------------------------------------------------------------
// 8. Markup: dua baris pratinjau terikat ke properti yang benar
// ---------------------------------------------------------------------------

const view = String(fs.readFileSync(SETTLEMENT_VIEW, 'utf8'));

check('markup: baris bonus terikat ke officerBonusLabel',
    view.includes('x-text="officerBonusLabel"'), true);
check('markup: baris potongan terikat ke officerCutLabel',
    view.includes('x-text="officerCutLabel"'), true);
check('markup: jumlah petugas terikat ke officerCount',
    view.includes('x-text="officerCount"'), true);
check('markup: kartu read-only menyebut mitra lapangan',
    view.includes('Potongan &amp; Bonus Mitra Lapangan'), true);

// ---------------------------------------------------------------------------

console.log('assertion: ' + checks.length + ', gagal: ' + failures.length);

if (failures.length > 0) {
    failures.forEach((failure) => console.log('  [GAGAL] ' + failure));
    process.exit(1);
}

console.log('OK — cermin potongan & bonus mitra lapangan sesuai OfficerService & SettlementService');
