/*
 * AurumFlow — probe lapis Alpine untuk Simulator "Belanja Malam Ini" (Fase 4).
 *
 *   node tests/manual/js_capital_simulator_check.js
 *
 * Mengapa berkas ini ada
 * ----------------------
 * Layar Kelola Modal menampilkan pratinjau serapan modal di sisi peramban
 * (`AurumAlloc` di `public/assets/js/app.js`). QC PHP tidak dapat melihat
 * perhitungan itu, sedangkan angka pratinjau yang berbeda satu sen dari hasil
 * peladen akan membuat operator mengambil keputusan dari data yang salah.
 *
 * Probe ini menjalankan `public/assets/js/app.js` yang SEBENARNYA di atas stub
 * DOM minimal (pendekatan yang sama dengan `js_form_init_check.js`) dan
 * memeriksa tiga hal:
 *
 *   1. cermin `AurumAlloc.plan()` menghasilkan serapan waterfall yang SAMA
 *      PERSIS dengan `AllocationEngine` (angka Protokol QC Manual 4);
 *   2. pembagian pool pro-rata memakai sisa terbesar — jumlahnya tidak pernah
 *      berselisih sen dari pool;
 *   3. isian rencana belanja terisi nilai dari peladen dan tetap dapat diubah
 *      (simulator bebas diedit), serta `AurumFormat.rupiah` membulatkan sen
 *      dengan aturan yang sama seperti `Formatter::rupiah()`.
 *
 * Sengaja TIDAK dimasukkan ke `run_qc.sh`: gerbang QC tetap PHP-only (hosting
 * QC belum tentu punya Node.js). Rincian: plan/Plan_Fase_4_AurumFlow.md T8.
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

if (typeof registry.capitalSimulator !== 'function') {
    throw new Error('komponen capitalSimulator tidak terdaftar oleh app.js');
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
    };
}

function field(value) {
    return { value, checked: false };
}

/** Komponen Alpine dengan magic yang disuntikkan seperti aslinya. */
function buildComponent(config, fields) {
    const component = registry.capitalSimulator(config);

    component.$root = rootElement(fields);
    component.$refs = {};

    return component;
}

// ---------------------------------------------------------------------------
// 1. Dompet Protokol QC Manual 4 (Pengelola 30 juta, Mitra Prioritas 16 juta
//    dengan kuota 20 %, Mitra Reguler A 20 juta, Mitra Reguler B 14 juta).
// ---------------------------------------------------------------------------

const wallets = [
    {
        id: 'op', name: 'Pengelola', tier: 'operator', tier_label: 'Pengelola',
        available_cents: 3000000000, quota_hundredths: 0,
    },
    {
        id: 'fam', name: 'Bpk. Ahmad', tier: 'family', tier_label: 'Mitra Prioritas',
        available_cents: 1600000000, quota_hundredths: 2000,
    },
    {
        id: 'exa', name: 'Mitra A', tier: 'external', tier_label: 'Mitra Reguler',
        available_cents: 2000000000, quota_hundredths: 0,
    },
    {
        id: 'exb', name: 'Mitra B', tier: 'external', tier_label: 'Mitra Reguler',
        available_cents: 1400000000, quota_hundredths: 0,
    },
];

const config = {
    operator_quota_hundredths: 5000,
    need_cents: 3515750000,
    wallets,
};

// Pre-fill dari peladen: `value="35.157.500"` (modal belanja hulu holding).
const simulator = buildComponent(config, { need_cents: field('35.157.500') });

simulator.init();

check('prefill: isian rencana belanja dari peladen', simulator.needInput, '35.157.500');
check('prefill: nilai terbaca sebagai sen', simulator.needCents, 3515750000);

const plan = simulator.plan;

check('waterfall: kuota dasar Pengelola', plan.operatorQuotaCents, 1757875000);
check('waterfall: serapan Pengelola', plan.stages.operator, 1757875000);
check('waterfall: serapan Mitra Prioritas', plan.stages.family, 703150000);
check('waterfall: serapan Mitra Reguler', plan.stages.external, 1054725000);
check('waterfall: total terserap', plan.utilizedCents, 3515750000);
check('waterfall: sisa tak terserap', plan.uncoveredCents, 0);
check('waterfall: basis pool mitra', plan.mitraBaseCents, 1757875000);

const byId = {};

plan.rows.forEach((row) => { byId[row.id] = row.utilized; });

check('pro-rata: Mitra Reguler A', byId.exa, 620426471);
check('pro-rata: Mitra Reguler B', byId.exb, 434298529);

check('label: Pengelola', simulator.operatorLabel, 'Rp 17.578.750');
check('label: Mitra Prioritas', simulator.familyLabel, 'Rp 7.031.500');
check('label: Mitra Reguler', simulator.externalLabel, 'Rp 10.547.250');
check('label: sisa tak terserap', simulator.uncoveredLabel, 'Rp 0');

// Simulator bebas diedit: menaikkan rencana belanja mengubah proyeksi.
simulator.needInput = '40.000.000';

check('editable: kebutuhan baru terbaca', simulator.needCents, 4000000000);
check('editable: serapan Pengelola ikut berubah', simulator.plan.stages.operator, 2000000000);
check('editable: sisa tak terserap saat belanja melebihi saldo', simulator.plan.uncoveredCents, 0);

simulator.needInput = '90.000.000';

check('editable: belanja melebihi seluruh dompet', simulator.plan.utilizedCents, 8000000000);
check('editable: sisa tak terserap dilaporkan', simulator.plan.uncoveredCents, 1000000000);

// ---------------------------------------------------------------------------
// 2. Sisa terbesar & pembulatan Rupiah — dua perilaku yang paling mudah
//    menyimpang satu sen dari peladen.
//
// `AurumAlloc`/`AurumFormat` sengaja privat di dalam IIFE; perilakunya diuji
// lewat komponennya (pendekatan yang sama dengan `js_form_init_check.js`).
// ---------------------------------------------------------------------------

// (a) Kebutuhan melebihi saldo seluruh dompet: serapan TIDAK boleh melebihi
//     saldo, dan sisanya dilaporkan sebagai sisa tak terserap.
const shortSimulator = buildComponent({
    operator_quota_hundredths: 5000,
    need_cents: 7,
    wallets: [
        { id: 'a', name: 'A', tier: 'external', tier_label: 'Mitra Reguler', available_cents: 3, quota_hundredths: 0 },
        { id: 'b', name: 'B', tier: 'external', tier_label: 'Mitra Reguler', available_cents: 3, quota_hundredths: 0 },
    ],
}, { need_cents: field('0') });

shortSimulator.init();
shortSimulator.needInput = '0,07';

check('saldo kurang: kebutuhan 7 sen', shortSimulator.needCents, 7);
check('saldo kurang: serapan dibatasi saldo', shortSimulator.plan.utilizedCents, 6);
check('saldo kurang: sisa dilaporkan', shortSimulator.plan.uncoveredCents, 1);

// (b) Pembagian dengan sisa: 2 sen untuk tiga dompet berbobot sama (satu sen
//     masing-masing). Metode sisa terbesar memberi sen terakhir ke `id` terkecil.
const tieSimulator = buildComponent({
    operator_quota_hundredths: 0,
    need_cents: 0,
    wallets: [
        { id: 'a', name: 'A', tier: 'external', tier_label: 'Mitra Reguler', available_cents: 1, quota_hundredths: 0 },
        { id: 'b', name: 'B', tier: 'external', tier_label: 'Mitra Reguler', available_cents: 1, quota_hundredths: 0 },
        { id: 'c', name: 'C', tier: 'external', tier_label: 'Mitra Reguler', available_cents: 1, quota_hundredths: 0 },
    ],
}, { need_cents: field('') });

tieSimulator.init();
tieSimulator.needInput = '0,02';

check('sisa terbesar: kebutuhan 2 sen', tieSimulator.needCents, 2);
check('sisa terbesar: A mendapat sen pertama', tieSimulator.plan.rows[0].utilized, 1);
check('sisa terbesar: B mendapat sen kedua', tieSimulator.plan.rows[1].utilized, 1);
check('sisa terbesar: C tertinggal tanpa sen', tieSimulator.plan.rows[2].utilized, 0);
check('sisa terbesar: jumlah persis kebutuhan', tieSimulator.plan.utilizedCents, 2);
check('sisa terbesar: tanpa sisa tak terserap', tieSimulator.plan.uncoveredCents, 0);

// (b) Pembulatan Rupiah half-up: Rp 108.038,82 wajib tampil Rp 108.039.
const roundingSimulator = buildComponent({
    operator_quota_hundredths: 0,
    need_cents: 0,
    wallets: [
        { id: 'a', name: 'A', tier: 'external', tier_label: 'Mitra Reguler', available_cents: 10803882, quota_hundredths: 0 },
        { id: 'b', name: 'B', tier: 'external', tier_label: 'Mitra Reguler', available_cents: 15434118, quota_hundredths: 0 },
    ],
}, { need_cents: field('') });

roundingSimulator.init();
roundingSimulator.needInput = '262.380,00';

check('pembulatan: kebutuhan terbaca', roundingSimulator.needCents, 26238000);

const roundingRows = {};

roundingSimulator.rows.forEach((row) => { roundingRows[row.id] = row; });

check('pembulatan: 10.803.882 sen tampil Rp 108.039', roundingRows.a.utilizedLabel, 'Rp 108.039');
check('pembulatan: 15.434.118 sen tampil Rp 154.341', roundingRows.b.utilizedLabel, 'Rp 154.341');
check('pembulatan: saldo dompet ikut terformat', roundingRows.b.balanceLabel, 'Rp 154.341');

// ---------------------------------------------------------------------------
// 3. Cermin parser nominal — sama dengan `SettlementService::parseMoneyInput()`.
// ---------------------------------------------------------------------------

simulator.needInput = '1.234,50';

check('parser: koma desimal', simulator.needCents, 123450);

simulator.needInput = 'Rp 10.000.000';

check('parser: label Rupiah ikut terbaca', simulator.needCents, 1000000000);

simulator.needInput = '';

check('parser: kosong menjadi nol', simulator.needCents, 0);
check('parser: rencana nol tanpa serapan', simulator.plan.utilizedCents, 0);

simulator.needInput = 'tidak sah';

check('parser: teks tidak sah menjadi nol', simulator.needCents, 0);

console.log('assertion: ' + checks.length + ', gagal: ' + failures.length);

if (failures.length > 0) {
    failures.forEach((failure) => console.log('  [GAGAL] ' + failure));
    process.exit(1);
}

console.log('OK — cermin simulator sesuai AllocationEngine & Formatter');
