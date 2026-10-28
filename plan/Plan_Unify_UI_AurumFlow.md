# Plan Unify UI AurumFlow — 2-Tier Shell for Every Operator & Cashier Page

> **Status dokumen (pasca-Fase 9 — sinkronisasi dokumentasi).** Seluruh angka dan perilaku di
> bawah ini — termasuk (bila ada) penyebutan **13 tabel** atau kurang, **14 suite / 853
> assertion**, `CACHE_NAME` **v3**, dan isian **`coord_fee`** — adalah **potret fase tersebut**,
> bukan keadaan sekarang. Keadaan terkini: **14 tabel** (termasuk `operational_expenses`, nol `ALTER`),
> `CACHE_NAME` **`aurumflow-shell-v4`**, **15 suite / 900 assertion / 0 gagal**, beban operasional
> dicatat sebagai **voucher kas** di meja kasir lalu diserap otomatis oleh tutup buku
> (`total_cash = pendapatan`). Rujukan terkini: `plan/Summary_Fase_9_OPEX_AurumFlow.md`.


**Target file:** `plan/Plan_Unify_UI_AurumFlow.md` (this document)
**Objective:** unify `/dashboard` + `/operator/*` onto one 2-tier chrome (`app/Views/layouts/operator.php`): no visual jump, no duplicated quick actions, one consistent header.
**Decisions already taken** (`dec-9ab922a185e7cc38`, `dec-e6acc8117bba9602`):

1. Layout wiring via an explicit `LAYOUT` constant on `DashboardController` (mirrors `Teller`/`Settlement`/`Capital`/`CashierController`).
2. The tab strip uses **only already-compiled** Tailwind classes — no CSS rebuild.
3. Header second line is page-aware for operator, literal `Meja Hulu` for cashier.
4. The top-tier identity block (User Info + Role Label) is made visible at every width.

**Locale note:** this document is written in English to match the request; the rest of the `plan/` corpus is Indonesian — say the word and it is localised before commit.

---

## 0. Executive summary

| # | Change | File(s) | Size |
| :-- | :--- | :--- | :--- |
| C1 | Dashboard renders through the 2-tier operator layout | `app/Controllers/DashboardController.php` | +2 lines |
| C2 | Top tier: drop redundant **Dashboard** button, page-aware subtitle, identity always visible | `app/Views/layouts/operator.php` | ~6 lines |
| C3 | Bottom tier → segmented tabs, **5 tabs** operator / **1 tab** cashier, horizontally scrollable | `app/Views/layouts/operator.php` | ~20 lines |
| C4 | Wallet widget row degrades gracefully when the view has no register data | `app/Views/layouts/operator.php` | 1 wrapper |
| C5 | Quick-action grid aligned 1:1 with the tabs; stale roadmap copy removed | `app/Views/dashboard/index.php` | ~10 lines |
| C6 | 3 new QC assertions + docs/plan updates | `tests/qc_fase_4.php`, `docs/`, `AGENTS.md`, `plan/Plan_Fase_1_AurumFlow.md` | — |

No route, middleware, DB, model, service, JS or CSS change. **No Tailwind rebuild**, so `CACHE_NAME` in `public/sw.js` stays `aurumflow-shell-v2` and `qc_fase_6` G.17/G.18 + `qc_fase_7` F.7 stay green.

---

## 1. Current state (evidence)

### 1.1 Layout ownership today

| Route | Controller | Layout | Evidence |
| :--- | :--- | :--- | :--- |
| `GET /dashboard` | `DashboardController::index()` | **`app`** (1-tier) | `render('dashboard/index', […])` — no 3rd argument ⇒ `BaseController::render($view, $data, $layout = 'app')` |
| `GET/POST /operator/teller` | `TellerController` | `operator` | `private const LAYOUT = 'operator'` (line 40), `], self::LAYOUT);` |
| `GET/POST /operator/settlement` | `SettlementController` | `operator` | line 38 |
| `GET+5 POST /operator/capital*` | `CapitalController` | `operator` | line 45 |
| `GET+3 POST /operator/kasir*` | `CashierController` | `operator` | line 36 |
| investor / report / auth pages | — | `investor` / `report` / `auth` | explicit constants |

`DashboardController` is the **only** controller relying on the `'app'` default. `layouts/app.php` (84 lines, `max-w-3xl`, links *Dashboard* + *Kasir Hulu*) is therefore the sole 1-tier surface left.

### 1.2 Header anatomy today (`layouts/operator.php`, 156 lines)

```
row 1  AURUMFLOW / "Meja Hulu"      [Dashboard]  name+role (hidden sm:block)  [Keluar]
row 2  [Kasir] [Rekonsiliasi] [Kelola Modal] [Kelola Kasir]      <- flex gap-2
row 3  Saldo <Kas>            Emas holding / n nota semalam       <- sticky register widget
```

Three defects feed the "jarring jump":

1. `Dashboard` exists **twice** on operator pages (`layouts/app.php:52` and `layouts/operator.php:88`) and once as a button in the operator header while `/dashboard` itself has no tab.
2. Subtitle is hardcoded `Meja Hulu` — wrong on `/dashboard`, `/operator/capital`, `/operator/kasir`.
3. The identity block uses `hidden … sm:block`, and **`.sm\:block` is absent from the committed bundle** (§1.3) while `.hidden{display:none}` is compiled ⇒ today the name + role label are invisible at *every* width.

### 1.3 Class availability in the committed bundle (`public/assets/css/app.min.css`, 24 264 B)

Audited with `grep -o` against the minified file (JIT sources are `./app/Views/**/*.php` + `./public/assets/js/*.js` at build time only):

| Class | Compiled? | Consequence for this plan |
| :--- | :---: | :--- |
| `overflow-x-auto` | ✅ `.overflow-x-auto{overflow-x:auto}` | available for the tab strip |
| `shrink-0` | ✅ `.shrink-0{flex-shrink:0}` | per-tab, prevents shrink **and** label wrap (flex basis → max-content) |
| `gap-2`, `px-4`, `py-2`, `max-w-2xl`, `block`, `items-center`, `justify-between` | ✅ | all used in C2/C3 |
| `truncate` | ✅ `{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}` | mobile mitigation for the identity name |
| `min-w-0` | ✅ | lets the identity flex item shrink |
| `flex-wrap` | ✅ (used by `layouts/app.php`) | optional wrap of the top tier |
| **`whitespace-nowrap`, `flex-nowrap`, `flex-1`, `flex-col`, `-mx-4`** | ❌ **absent** | forbidden — must not be introduced |
| **`sm:block`** | ❌ **absent** | the current identity block never renders (§1.2 defect 3) |

Also relevant: `qc_fase_2` **H.7** asserts the bundle is **< 30 720 B** — a rebuild is both excluded by constraint 4 and a QC-visible risk.

---

## 2. Target state

```
ANY operator page (/dashboard, /operator/teller, /operator/settlement, /operator/capital, /operator/kasir)
┌────────────────────────────────────────────────────────────────┐
│ AURUMFLOW                          Robert / Pengelola (Operator)│  row 1: logo · subtitle · identity · Keluar
│ <page title>                                         [ Keluar ] │
├────────────────────────────────────────────────────────────────┤
│ [Dashboard] [Kasir] [Rekonsiliasi] [Kelola Modal] [Kelola Kasir]│  row 2: tabs (scroll-x on phones)
├────────────────────────────────────────────────────────────────┤
│ Saldo Kas Meja Bakar              Emas holding                  │  row 3: register widget (operator pages only)
└────────────────────────────────────────────────────────────────┘

ANY cashier page (/operator/teller only)
┌────────────────────────────────────────────────────────────────┐
│ AURUMFLOW / "Meja Hulu"         Andi / Kasir Meja Bakar  [Keluar]│
├────────────────────────────────────────────────────────────────┤
│ [Kasir]                                                         │  exactly one tab
└────────────────────────────────────────────────────────────────┘
```

---

## 3. C1 — Layout consolidation (the one unavoidable PHP edit)

`app/Views/dashboard/index.php` **cannot** pick its own layout: `BaseController::render()` does `extract($data, EXTR_SKIP); require $viewFile; require $layoutFile;` — the view is a fragment injected as `$content` into the layout chosen in PHP. There is no view-side hook. Per decision 1, mirror the sibling operator controllers:

```diff
 final class DashboardController extends BaseController
 {
+    /** Kerangka 2-tier yang sama dengan layar operator lain (lihat Plan_Unify_UI). */
+    private const LAYOUT = 'operator';
+
     private const SETTING_LABELS = [
@@
-        $this->render('dashboard/index', [
+        $this->render('dashboard/index', [
             …
-        ]);
+        ], self::LAYOUT);
     }
 }
```

* `requireRole(Constants::ROLE_OPERATOR)` stays the **first** statement — `/dashboard` remains operator-only, so the cashier never reaches this chrome.
* `BaseController::render()`'s default stays `'app'`; `layouts/app.php` is **retained** because `qc_fase_5` I.10 asserts all five layouts (`app, auth, operator, investor, report`) exist and carry `manifest.json` + `data-app-base`. Its becoming route-unused is recorded as a known issue (§13), not fixed here.

---

## 4. C2–C4 — `app/Views/layouts/operator.php`

### 4.1 Derived variables (header of the file, next to `$isCashier`)

```php
// Subjudul baris-1: operator mengikuti judul halaman (nilai peladen, di-escape di
// markup); kasir tetap "Meja Hulu" agar kebiasaan kerja tidak berubah
// (keputusan dec-e6acc8117bba9602).
$headerSubtitle = $isCashier ? 'Meja Hulu' : (string) ($title ?? '');
```

### 4.2 Top tier — logo · subtitle · identity · logout

```php
<div class="mx-auto flex max-w-2xl flex-wrap items-center justify-between gap-2 px-4 py-2.5">
    <div class="leading-tight">
        <span class="block text-xs font-bold tracking-[0.22em] text-gold-primary">AURUMFLOW</span>
        <span class="block text-[0.7rem] uppercase tracking-[0.14em] text-muted"><?= Security::e($headerSubtitle) ?></span>
    </div>

    <div class="flex items-center gap-2">
        <!-- Tombol "Dashboard" di baris-1 DIHAPUS: kini tab pertama baris-2. -->
        <span class="block min-w-0 text-right text-[0.7rem] leading-tight">
            <span class="block truncate font-semibold text-pure"><?= Security::e($auth['name'] ?? '') ?></span>
            <span class="block truncate text-muted"><?= Security::e($auth['role_label'] ?? '') ?></span>
        </span>

        <form method="post" action="<?= Security::e(App::url('logout')) ?>" class="inline"
              data-confirm="Keluar dari AurumFlow?">
            <?= Security::csrfField() ?>
            <button type="submit" class="af-btn af-btn--ghost px-3 text-xs">Keluar</button>
        </form>
    </div>
</div>
```

* `hidden … sm:block` → `block min-w-0` + `truncate` (decision 4): User Info + Role Label become visible at every width using **only compiled classes**; `min-w-0` + `truncate` guarantee the row cannot overflow at 320 px.
* Subtitle rendering: operator sees `Dashboard` / `Meja Kasir Hulu` / `Rekonsiliasi & Settlement` / `Kelola Modal & Likuiditas` / `Kelola Kasir`; cashier sees `Meja Hulu` exactly as today. `qc_fase_7` D.1 keeps passing — it reads the string from `<title>`, which is untouched.
* "Redundancy in quick-action buttons" resolved: no `href="/dashboard"` shortcut button in either header row; the operator still has `href="/dashboard"` on the tab, so `qc_fase_7` E.6 stays satisfied.

### 4.3 Bottom tier — 5 tabs (operator) / 1 tab (cashier)

```php
// Navigasi antar layar. Path aktif dibandingkan dengan REQUEST_URI supaya
// penanda "sedang dibuka" tetap benar pada pemasangan subdirektori.
$activePath = rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');

$navItems = [];

if (!$isCashier) {
    // Tab pertama = beranda operator. Kasir tidak pernah menerimanya, karena
    // `/dashboard` operator-only (penjaga sesungguhnya tetap di peladen).
    $navItems[] = ['path' => App::url('dashboard'), 'label' => 'Dashboard', 'active' => false];
}

$navItems[] = ['path' => App::url('operator/teller'), 'label' => 'Kasir', 'active' => false];

if (!$isCashier) {
    $navItems[] = ['path' => App::url('operator/settlement'), 'label' => 'Rekonsiliasi', 'active' => false];
    $navItems[] = ['path' => App::url('operator/capital'), 'label' => 'Kelola Modal', 'active' => false];
    $navItems[] = ['path' => App::url('operator/kasir'), 'label' => 'Kelola Kasir', 'active' => false];
}

foreach ($navItems as $index => $item) {
    $itemPath = rtrim((string) $item['path'], '/');

    // Sub-halaman (mis. `/operator/capital/topup`) tetap menandai menu induknya.
    $navItems[$index]['active'] = $activePath === $itemPath
        || str_starts_with($activePath, $itemPath . '/');
}
```

> ⚠️ `qc_fase_4` H.11 matches the **literal** substrings `'label' => 'Kasir'`, `'label' => 'Rekonsiliasi'`, `'label' => 'Kelola Modal'` and `App::url('operator/capital')`. Keep the single-quote + space-around-`=>` formatting; do not fold this into a loop or array-map.

Markup (only `overflow-x-auto` added to the container, `shrink-0` to every tab):

```php
<nav class="border-t border-surface-800 bg-obsidian-950/80">
    <div class="mx-auto flex max-w-2xl gap-2 overflow-x-auto px-4 py-2">
        <?php foreach ($navItems as $item): ?>
            <?php
            $navClass = $item['active']
                ? 'af-btn shrink-0 bg-gold-primary px-3 text-xs text-obsidian-950'
                : 'af-btn af-btn--ghost shrink-0 px-3 text-xs';
            $navAria = $item['active'] ? 'aria-current="page"' : '';
            ?>
            <a class="<?= Security::e($navClass) ?>" href="<?= Security::e($item['path']) ?>" <?= Security::e($navAria) ?>
            ><?= Security::e($item['label']) ?></a>
        <?php endforeach; ?>
    </div>
</nav>
```

**Active-state logic (requirement 2.3)** — URI-driven, no controller input:

| Aspect | Rule |
| :--- | :--- |
| Source | `parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)` (query string stripped), then `rtrim('/')` |
| Why `REQUEST_URI` | already subdirectory-correct: request `/aurumflow/dashboard` vs `App::url('dashboard')` → `/aurumflow/dashboard` |
| Match | exact equality **or** prefix `$itemPath . '/'` ⇒ `/operator/capital/topup` (POST+PRG) still highlights **Kelola Modal** |
| Edge | `/` → `home()` redirects before any render, so `activePath === ''` never occurs |
| Emitted | the active tab keeps its `href` (self-link) and gains `aria-current="page"`; both escaped via `Security::e(...)` (contract of the static escaping scans in `qc_fase_2`/`qc_fase_3`/`qc_fase_4`) |

### 4.4 Mobile responsiveness (requirement 2.4)

* Container: `flex gap-2 overflow-x-auto px-4 py-2`; flex containers are `nowrap` by default, so the row scrolls horizontally instead of stacking.
* Each tab: `shrink-0` (`.shrink-0{flex-shrink:0}`) → flex basis resolves to max-content ⇒ labels are never wrapped, ellipsised, or squeezed.
* **`whitespace-nowrap` is deliberately NOT used** (decision 2): it has no rule in the committed bundle and constraint 4 forbids a rebuild. If a `white-space` guarantee is ever needed, `truncate` is the only compiled carrier of `white-space:nowrap`.
* Width arithmetic: 5 tabs ≈ 486 px total → fits `max-w-2xl` (640 px content box) with **no scrollbar** on desktop/tablet; at 360 px (328 px content box) it scrolls — exactly the intended behaviour. Padding sits on the scroll container (no `-mx-4` trick: not compiled).
* Every class above is verified present in §1.3; no new arbitrary value (`max-w-[…]`, `text-[…]`) is introduced.

### 4.5 Role gating (requirement 2.5 — restated, unchanged semantics)

| | operator | cashier |
| :--- | :---: | :---: |
| `[Dashboard]` `/dashboard` | ✅ | ⛔ (inside `if (!$isCashier)`) |
| `[Kasir]` `/operator/teller` | ✅ | ✅ |
| `[Rekonsiliasi]` `/operator/settlement` | ✅ | ⛔ |
| `[Kelola Modal]` `/operator/capital` | ✅ | ⛔ |
| `[Kelola Kasir]` `/operator/kasir` | ✅ | ⛔ |

The cashier surface is **allow-list**: `TellerController::ALLOWED_ROLES = [ROLE_OPERATOR, ROLE_CASHIER]`; gating in the layout is cosmetic only. `qc_fase_7` E.3 (cashier body has no `href` to `/dashboard`, `/operator/settlement`, `/operator/capital`, `/operator/kasir`) and E.4 (no `Rekonsiliasi` / `Kelola Modal` / `Kelola Kasir` string in the cashier body) still pass — the `[Dashboard]` entry is built inside the same guard as the other three.

### 4.6 Widget row degradation (assumption A1 — flag for veto)

Row 3 reads `$register`/`$holding`, which `TellerController` (:242/:253), `SettlementController` (:278/:279) and `CapitalController` (:509/:513) all supply; `DashboardController` supplies neither, so today's placeholder logic would print `Saldo Kas -` / `-`. Therefore:

```php
<?php if ($register !== [] || $holding !== []): ?>
    <!-- Widget informasi #1: saldo kas aktif hari ini (kontras tinggi). -->
    …
<?php endif; ?>
```

Result: `/dashboard` gets a clean 2-row header; the three operational pages keep byte-identical behaviour. Populating a register summary on the dashboard would need controller data — explicitly out of scope.

---

## 5. C5 — `app/Views/dashboard/index.php` cleanup

### 5.1 Quick actions aligned 1:1 with the tabs (requirement 3)

Keep the `mt-4 grid gap-3 sm:grid-cols-2` 2×2 grid (it must exist: `qc_fase_4` **H.12** requires `App::url('operator/capital')` in this file), but make the tiles read exactly like the tabs, same order, same destinations:

```php
<?php if ($isOperator): ?>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <a class="af-btn af-btn--primary" href="<?= Security::e(App::url('operator/teller')) ?>">Kasir</a>
        <a class="af-btn af-btn--ghost"   href="<?= Security::e(App::url('operator/settlement')) ?>">Rekonsiliasi</a>
        <a class="af-btn af-btn--ghost"   href="<?= Security::e(App::url('operator/capital')) ?>">Kelola Modal</a>
        <a class="af-btn af-btn--ghost"   href="<?= Security::e(App::url('operator/kasir')) ?>">Kelola Kasir</a>
    </div>
<?php endif; ?>
```

* Tiles = the four non-Dashboard tabs, verbatim labels ⇒ "align perfectly with the tabs"; `af-btn--primary` stays on the operational workhorse (Kasir).
* Wider canvas than before: the operator layout gives `max-w-2xl` (672 px) instead of `max-w-3xl`, so ~320 px per tile — labels like *Kelola Modal* now fit on one line (the concern recorded in `plan/Plan_Fix_Dashboard_UI.md` §3 is retired).
* Assumption A2: if the PO prefers the descriptive wording (`Buka Meja Kasir Hulu`, …), it is a one-line-per-tile revert; nothing in QC binds those labels (`qc_fase_7` D.1 reads *Meja Kasir Hulu* from the `<title>` of `/operator/teller`, not from this tile).

### 5.2 Legacy headers / stale copy removed

* There is no header element outside the welcome card, so the only clash is the roadmap copy inside it. Replace the `af-card__subtitle` sentence *"Fase 1 (fondasi arsitektur) dan Fase 2 (meja kasir hulu) sudah aktif. Halaman ini membuktikan bahwa session, skema ERD, model, dan formatter bekerja bersama."* with operational copy, e.g. *"Ringkasan operasional hari ini. Gunakan tab di atas untuk berpindah antar layar pengelola."*
* Keep `<div class="af-glow">` (decorative, inside the card) and the `<h1>Selamat datang, …</h1>` (identity dedupe is out of scope; `qc_routing` reads the phone + `Pengelola` from the facts list).
* Verified: no test greps `Selamat datang`, `sudah aktif`, or `af-glow`.

### 5.3 Content frozen by QC — **do not touch**

| Must stay | Guarded by |
| :--- | :--- |
| `$auth['phone']` fact row | `qc_routing` 5.9 ("nomor telepon operator") |
| `$auth['role_label']` ⇒ `Pengelola` | `qc_routing` 5.9b |
| Formatter samples `Rp 28.937.500`, `14,850 gr` | `qc_routing` 5.9c |
| Settings table values `waterfall`, `50.00` | `qc_routing` 5.9d |
| `App::url('operator/capital')` | `qc_fase_4` H.12 |
| `Security::e(...)` on every dynamic echo | `qc_fase_2`/`qc_fase_3`/`qc_fase_4` escaping scans |

---

## 6. Constraint compliance

| Constraint | Status |
| :--- | :--- |
| Controllers | **C1 only** — `DashboardController` +2 lines (layout declaration). No logic, no route handler touched. Decision 1 explicitly authorises this; requirement 1 is otherwise unreachable (§3). |
| Routing / middleware | untouched (`app/routes.php`, `public/index.php`). |
| Database / models / services | untouched; zero SQL in views. |
| CSS rebuild | none. **Hard requirement**, since `whitespace-nowrap`, `sm:block`, `flex-nowrap` are absent and `qc_fase_2` H.7 caps the bundle at < 30 KB. |
| `public/sw.js` / `CACHE_NAME` | untouched (`aurumflow-shell-v2`); no `app.js`/`app.min.css` change. |
| Alpine / `app.js` | untouched — no new component, no expression-semantics change; the "one-use interaction in the view" and `Alpine.data()` rules are not engaged. |
| CSRF / `_once` | untouched — both changed views are GET-only renders. |

---

## 7. QC impact analysis (13 suites / 782 assertions today)

**Must keep passing (strings/paths that must survive verbatim):**

| Assertion | Requirement | Plan status |
| :--- | :--- | :--- |
| `qc_fase_4` H.11 | layout carries `'label' => 'Kasir'/'Rekonsiliasi'/'Kelola Modal'` + `App::url('operator/capital')` | ✅ literals untouched in §4.3 |
| `qc_fase_4` H.12 | dashboard view carries `App::url('operator/capital')` | ✅ §5.1 |
| `qc_fase_7` E.3 | cashier body has **no** `href="/dashboard"` etc. | ✅ Dashboard tab inside `if (!$isCashier)` |
| `qc_fase_7` E.4 | cashier body has no `Rekonsiliasi` / `Kelola Modal` / `Kelola Kasir` | ✅ unchanged guard |
| `qc_fase_7` E.6 | operator `/operator/teller` body **does** contain all four owner hrefs incl. `/dashboard` | ✅ tab provides `href="/dashboard"` |
| `qc_fase_7` E.8 | `layouts/operator.php` free of `BRANKAS_NAME`, `CapitalWallet`, `laba bersih`, … | ✅ strings added are page labels only |
| `qc_fase_7` D.1 | teller page contains `Meja Kasir Hulu` (`<title>`) + `Emas holding` | ✅ `<title>` untouched; widget row kept for operator pages |
| `qc_fase_5` I.10 | **five** layouts each with `manifest.json` + `data-app-base` | ✅ `layouts/app.php` retained |
| `qc_fase_2` H.2/H.3/H.9 | no `number_format`, no manual `beginTransaction`, no SQL keywords in the layout | ✅ |
| `qc_fase_2`/`qc_fase_3`/`qc_fase_4` escaping scans | every `<?=` carries `Security::e(...)` / `Security::csrfField()` | ✅ including `$headerSubtitle`, `$navAria` |
| `qc_routing` 5.9…5.9d | dashboard still prints phone / `Pengelola` / formatter samples / `waterfall` / `50.00` | ✅ §5.3 |
| `qc_fase_6` G.17/G.18, `qc_fase_7` F.7 | `CACHE_NAME` parity AGENTS.md ↔ sw.js | ✅ no asset change |

**No assertion counts `af-btn` on the dashboard, binds `sm:grid-cols-3`, or greps the removed header button** (also confirmed by `Plan_Fix_Dashboard_UI.md` §4).

---

## 8. New assertions (recommended) + baseline update

Add to `tests/qc_fase_4.php` group **H** (its `$operatorJar` at :702 is still authenticated there; no `/logout` call in this suite):

| ID | Type | Claim |
| :--- | :--- | :--- |
| `H.11b` | static | `DashboardController.php` declares `private const LAYOUT = 'operator';` **and** passes `self::LAYOUT` to `render()` — guards the C1 wiring |
| `H.11c` | static | `layouts/operator.php` contains `'label' => 'Dashboard'`, `overflow-x-auto`, and `shrink-0` — guards the segmented-tab contract |
| `H.12b` | HTTP | `GET /dashboard` with `$operatorJar` → 200, body contains `aria-current="page"` + `href` for all five tabs, and **does not** contain `max-w-3xl` (only `layouts/app.php` uses it) ⇒ proves the 1-tier shell is gone |

Baseline becomes **13 suites / 785 assertions**: update the baseline line in `AGENTS.md` and record it in the follow-up summary doc (§9). If a *strictly view-only* diff is preferred, drop this section — the plan then relies on the existing assertions listed in §7.

---

## 9. Docs & plan updates (mandatory before/with the code, per AGENTS.md)

| File | Edit |
| :--- | :--- |
| `docs/AurumFlow UI UX Documents.md` § 7.2 (table at :625–634) | replace row *"Tombol **Dashboard** di header"* with *"Menu **Dashboard** (`/dashboard`)"* (operator ✅ / kasir ⛔); describe the 2-tier shell: 5 tabs operator, 1 tab kasir, horizontal scroll on phones |
| same file, Fase-7 note at :138–142 | second line of row 1 is page-aware for operator, literal `Meja Hulu` for cashier; header button removed |
| `docs/AurumFlow Roadmap and QC Documents.md` :369 | checklist wording: hidden for cashier are the **menus** Rekonsiliasi / Kelola Modal / Kelola Kasir / Dashboard |
| `docs/AurumFlow System Architecture Document.md` :99 | `app.php (operator)` comment → note that `/dashboard` now shares `layouts/operator.php` and `app.php` is the retained default shell |
| same file :290–295 | add: operator pages (incl. dashboard) share one 2-tier chrome; the cashier sees only the `[Kasir]` tab |
| `plan/Plan_Fase_1_AurumFlow.md` → ADDENDUM | record the deviation + rationale: dashboard layout ownership moved to `DashboardController::LAYOUT`; `layouts/app.php` kept alive by `qc_fase_5` I.10 while unused by routes |
| `plan/Summary_Unify_UI_AurumFlow.md` (new, repo convention) | post-execution record: assertion delta, manual-verification result, open items |

---

## 10. Execution order

- [x] 0. Persist this document as `plan/Plan_Unify_UI_AurumFlow.md` (done — plan approved; no code executed yet).
- [ ] 1. Docs first: §9 rows for `docs/` + the `Plan_Fase_1` ADDENDUM.
- [ ] 2. C1 wiring in `DashboardController`.
- [ ] 3. C2–C4 in `layouts/operator.php`.
- [ ] 4. C5 in `dashboard/index.php`.
- [ ] 5. §8 assertions (+ baseline line in `AGENTS.md`).
- [ ] 6. `php -l` on the three PHP files.
- [ ] 7. `bash tests/run_qc.sh` → exit 0.
- [ ] 8. Manual verification (§11.3), then `git status` review and `plan/Summary_Unify_UI_AurumFlow.md`.

---

## 11. Verification

**11.1 Lint**

```bash
php -l app/Controllers/DashboardController.php
php -l app/Views/layouts/operator.php
php -l app/Views/dashboard/index.php
```

**11.2 QC gate** — `bash tests/run_qc.sh` → **exit 0**, no new `storage/logs/php-*.log`.

> ⚠️ This **writes** to the DB pointed at by `.env` (schema, seeds, operator *and cashier* test accounts, cash balances, hulu transactions, settlements, wallets, distributions, withdrawals, `bridge_loans`, `users` onboarding columns) **and to disk** (`public/uploads/receipts/`, `storage/throttle/`). Never aim it at production.

**11.3 Manual (dev server mimicking `.htaccess`)**

```bash
php -S 127.0.0.1:8000 -t public tests/server_router.php
```

1. Operator login → `/dashboard`: 2-tier chrome, 5 tabs, **Dashboard** highlighted (`aria-current="page"`), no register row, no `Kasir Hulu` / header Dashboard button, second line `Dashboard`.
2. `/operator/teller`, `/operator/settlement`, `/operator/capital`, `/operator/kasir`: correct tab highlighted, second line = page title, register row present.
3. Narrow the viewport to 360 px (and 320 px): tabs scroll horizontally, **no label wrapping**, top-tier row never overflows (identity truncates, does not overlap).
4. Cashier login (`089700000101` from the QC fixtures, or a freshly created one via `/operator/kasir`): `/operator/teller` shows exactly `[Kasir]` + `Keluar`, **no** `href="/dashboard"`, second line stays `Meja Hulu`; `GET /dashboard` → `302` to `/operator/teller`.
5. `GET /operator/capital/topup` (or any sub-page) still highlights its parent tab.

**11.4 Clean tree** — `git status --short` shows only the intended files; no `tests/.tmp/`, `storage/installed.lock`, `*.log`. No scratch scripts committed (verification is done inline / via the dev server).

---

## 12. Risks & mitigations

| # | Risk | Mitigation |
| :-- | :--- | :--- |
| R1 | A class used in the new markup is not in the committed CSS (silent no-op) | §1.3 audit; only `overflow-x-auto` / `shrink-0` / `max-w-2xl` / `truncate` / `min-w-0` / `flex-wrap` / `block` are introduced — all verified present |
| R2 | QC literal-string check (`H.11`) broken by reformatting the nav literals | §4.3 keeps the exact `'label' => '…'` lines; no `array_map`/loop |
| R3 | Dashboard tab leaks to the cashier surface | built inside `if (!$isCashier)`; existing E.3/E.4 + new §8 assertion guard it |
| R4 | 5 tabs overflow `max-w-2xl` on desktop | measured ≈486 px vs 640 px content box ⇒ no scrollbar above ~520 px; scroll intended below that |
| R5 | Long subtitle (`Rekonsiliasi & Settlement`) crowds row 1 at 320 px | `flex-wrap` + `min-w-0` + `truncate`; verified at 320 px in step 11.3.3 |
| R6 | `/dashboard` loses the cash-balance widget | intended (no controller data; §4.6). Re-adding it requires backend data — separate decision |
| R7 | `layouts/app.php` becomes route-unused (dead-ish code) | retained because `qc_fase_5` I.10 requires five layouts; `render()`'s default keeps it legitimate; recorded in §9/§13 |
| R8 | Somebody "fixes" the tabs by rebuilding Tailwind | recorded here + in docs: `whitespace-nowrap`/`sm:block` would need a rebuild, which drags `CACHE_NAME`, `AGENTS.md` and G.17–G.18 along |

---

## 13. Out of scope / known issues recorded, not fixed

* No changes to routes, middleware, DB/schema, models, services, `app.js`, `app.min.css`, `public/sw.js`, `manifest.json`, or the investor/auth/report layouts.
* No new Alpine component, no `_once`/CSRF change, no PWA cache bump.
* `layouts/app.php` stays in the tree though route-unused; removing it later requires amending `qc_fase_5` I.10 plus a decision.
* `max-w-2xl` on the dashboard makes the smoke-test tables narrower — cosmetic, intended by the unification.
* Fixed en route (decision 4): the top-tier identity block was invisible everywhere because `.sm\:block` was never compiled; the new markup uses `block min-w-0` instead.
* Not addressed: self-service PIN reset, a register summary on the dashboard, hamburger/drawer navigation (tabs + horizontal scroll are the agreed pattern).

---

## 14. Status

- [x] Requirements analysed; codebase contracts verified (`layouts/*`, controllers, `app.min.css` class audit, 6 QC suites)
- [x] Three PO decisions recorded (`dec-9ab922a185e7cc38`, `dec-e6acc8117bba9602`)
- [x] Plan approved by the PO
- [x] Document written to `plan/Plan_Unify_UI_AurumFlow.md`
- [x] Execution steps §10 (docs → C1 → C2–C4 → C5 → assertions → baseline)
- [x] Verification §11 — `php -l` bersih, `bash tests/run_qc.sh` **exit 0**,
      13 suite / **785 assertion** / 0 gagal, tanpa `storage/logs/php-*.log`

**Deviasi saat eksekusi (satu):** § 4.3 di atas mengusulkan
`<?= Security::e($navAria) ?>`, tetapi `Security::e()` memakai `ENT_QUOTES`
sehingga atributnya tercetak `aria-current=&quot;page&quot;` (nilai
aksesibilitas tidak sah — dan `H.12b` sempat gagal karena itu). Implementasi
memakai markup statis `<?php if ($item['active']): ?> aria-current="page"<?php endif; ?>`.
Rincian + hasil lengkap ada di `plan/Summary_Unify_UI_AurumFlow.md`.
