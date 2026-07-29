## 2026-07-29 (Portfolio visuals — screenshot gallery)

- `docs/SCREENSHOTS.md` — full gallery of 13 screenshots grouped by workflow
  (daily operation, per-SKU depth, forecasting, promotion planning,
  configuration and data, reference, dark mode), one caption each.
- `docs/screenshots/` refreshed with the 28 July capture run, renamed from
  ordinal filenames (`02-dashboard.png`) to descriptive ones
  (`dashboard.png`, `promotion-uplift-prediction.png`, …). The four
  superseded 29 June stills were retired; the dark-mode shot was replaced
  with the current-quality capture.
- Glossary reference card regenerated. The previous image claimed "34 terms"
  while rendering 36 — corrected and re-rendered at 2x scale for the
  carousel.
- README screenshots section reworked to four heroes (dashboard, forecast
  reports, promotion uplift, status actions) plus a link to the full gallery,
  replacing the previous four stills. Two of the old links
  (`reports.png`, `settings.png`) pointed at files retired in this change.

## 2026-07-28 (Portfolio publication — README and reference docs)

Documentation pass for the public portfolio repo. No application code changed.

- `docs/GLOSSARY.md` — every user-facing metric and label in one reference,
  grouped by area (ABC/XYZ classification, recommendations and urgency,
  inventory position, forecasting metrics). It is a 1:1 extraction of
  `resources/js/composables/useGlossary.ts`, which drives both the in-app
  glossary panel in `AppHeader.vue` and the `?` tooltips in `GlossaryTip.vue`.
  All 36 entries verified term-for-term and definition-for-definition against
  the composable. The two files are now duplicated by design — a note in the
  CLAUDE.md documentation index flags that a change to one requires a change
  to the other.
- README links the glossary from the "What it does" section and the
  repository layout listing.
- README screenshots section — dashboard (light and dark), forecast reports,
  SKU detail, and forecast settings, plus an animated walkthrough GIF.
  (Committed in 88f33ae and af636da; recorded here retroactively.)

## 2026-06-06 (Fly.io demo deploy — Phase A + B hosted artifact)

The partner-share tunnel (Cloudflare Quick Tunnel) was retired in favour of
an always-on hosted deploy so the same URL works for both partner review and
the eventual client demo. Live at https://inventory-engine-demo.fly.dev.

Build artifacts (all committed):

- `Dockerfile` — multi-stage: node:22-alpine builds the Vite bundle,
  composer:2 installs --no-dev with `--ignore-platform-req=ext-pcntl`
  (the composer image lacks pcntl but laravel/horizon's lock requires
  it; the final runtime stage installs it natively), and
  php:8.3-fpm-bookworm bundles PHP + Python 3.11 + nginx + supervisor
  + the forecasting scientific deps. Final image ~384 MB.
- `fly.toml` — `inventory-engine-demo` app in `sin`, 1 GB volume
  mounted at `/data` for SQLite + uploads + forecasting reports,
  /up health check, shared-cpu-1x machine.
- `docker/nginx.conf`, `docker/supervisord.conf`,
  `docker/entrypoint.sh` — sidecar configs. The entrypoint seeds the
  30-SKU SEA dataset on first boot only (marker file at
  `/data/.seeded`) so any data the client touches survives container
  restarts. Symlinks `storage/app/{forecasting,ingestion}` into the
  Fly volume so uploads and forecasting reports persist across
  deploys.

Two latent issues surfaced and were fixed during the first two deploy
attempts:

- Stale `bootstrap/cache/services.php` + `packages.php` referenced
  Laravel\\Pail\\PailServiceProvider, which is a dev-only package. Since
  we install --no-dev in production, Laravel boot crashed before the
  entrypoint could regenerate the cache. Fix: excluded the cache files
  from the Docker build context so Laravel rebuilds them at runtime
  from `vendor/composer/installed.json`. Added a defensive .gitignore
  rule alongside the existing `bootstrap/cache/.gitignore` so the same
  problem can't recur.
- `bootstrap/app.php` had no TrustProxies wired. Without it, Laravel
  generates URLs against the upstream Host header and mis-detects
  HTTPS, which breaks asset paths behind any reverse proxy (Fly's
  edge, Cloudflare, etc.). Committed in c86821e while debugging the
  tunnel; required for Fly too.

Scope is explicitly demo-grade: no Horizon (`QUEUE_CONNECTION=sync`),
no Reverb (`BROADCAST_CONNECTION=log`), no Prometheus, no backups, no
scale testing. All 8 items in `docs/TODO_PRE_PRODUCTION.md` remain
gated on Phase C (post-client-signoff). Throwing the demo away and
rebuilding properly is the explicit plan if the client signs.

## 2026-05-19

- Forecast sweep moved from monthly (1st, 03:00) to bi-weekly — every other
  Saturday at 03:00, an off-peak weekend slot. Week parity is anchored to a
  fixed Saturday so the cadence stays stable across year boundaries. In
  production the scheduler daemon fires this automatically and Horizon
  processes the dispatched jobs — no manual step. `RunInventoryEngineJob`
  already runs daily at 06:00.

## 2026-05-17 (Synthetic dataset milestone — 30-SKU SEA Shopify-shape demo)

Replaced the 11-SKU / 1-year demo fixture with a 30-SKU South-East-Asia
e-commerce dataset spanning a 913-day (~30-month) window — a hard test of
how the engine behaves under larger, more realistic data.

Step 7/7 (this commit): retired `RetroactivePromotionTagSeeder.php` — the
1-year-demo retro-tagging stopgap, fully superseded by
`SeaPromotionCampaignGenerator` over the 30-month window. Stale references
in `SeaPromotionCampaignGenerator` docblocks cleaned up.

Milestone summary (steps 1–7):
- `SeaSeasonalCalendar` — SEA mega-sale days (9.9 / 10.10 / 11.11 / 12.12),
  CNY, Hari Raya Puasa/Haji, Songkran, Black Friday / Cyber Monday,
  Christmas, monsoon dampening. Multipliers in `config/synthetic_dataset.php`.
- `SeaPromotionCampaignGenerator` — ~57 Brief-tagged promos anchored to the
  SEA calendar, ≥30-day baseline-gap rule enforced by design.
- `sea_sku_catalog.php` — 5 suppliers (5–28d lead time), 30 SKUs across
  equipment / accessory / bundle, each with an assigned pathology.
- `SeaDatasetSeeder` orchestrator — wired into `SyntheticDataSeeder` and
  `RegionalHolidaySeeder` (now seeds 2023–2026 holidays).
- End-to-end verification gate caught and fixed 3 latent production bugs:
  `MlLayer` hardcoded `python3` (Windows exit 9009), `MIN_TRAINING_SAMPLES`
  PHP/Python mismatch, `SkuObserver` dispatching forecasts before sales
  history existed.

Measured: seed ≈7.8s, forecast_demand avg ≈10.92. 43 synthetic-dataset tests.

## 2026-05-04 (Terminology alignment — commit 5/5: leftover label sweep)

Final commit in the five-step terminology alignment kicked off by the
2026-05-03 office-hours design doc. Addresses the residual inconsistencies
found during the visual sweep across all 7 pages.

Label fixes (Title Case + canonical glossary form):
- 'Days Cover' / 'Days cover'   → 'Days of Cover'  (Decisions column,
                                                    SKU dialog, Dashboard
                                                    mobile cards)
- 'Demand / day' / 'Demand/d'   → 'Demand / Day' / 'Demand/Day'
                                   (Dashboard column + mobile cards)
- 'Eff. pos.'                   → 'Eff. Pos.'      (Dashboard mobile)
- 'BUDGET BLOCKED'              → 'Budget Blocked' (SKUs catalogue filter)

Open question parked for follow-up: per the strict Title Case rule,
'Days of Cover' / 'Avg Days of Cover' should arguably become 'Days Of
Cover' / 'Avg Days Of Cover'. Common SaaS convention keeps articles
and short prepositions lowercase; the call is sitting with the user.
If they go strict, a tiny follow-up commit handles the 'of' → 'Of'
sweep across 5 callsites. If they go relaxed, this is the canonical.

== Five-commit terminology series complete ==
1. d13bceb — UI 'Decision' → 'Recommendation'
   (105a43a, 760a394, 1dcab7b — subtitle Title Case sweep + Reports rename)
2. a9884af — KPI tiers Order Now / Order Soon / Watchlist (exclusive counts)
3. 1aff385 — Title Case pills + unified urgency (Severe/High/Medium/Low)
4. 28150ab — Buffer field in dialog and SKU show
5. (this) — leftover label sweep

183 tests passing. UI-only changes throughout the series. Visual
evidence: storage/app/design-audit/screenshots/term-c{1,2,3,4}-*.png.

## 2026-05-03 (Terminology alignment — commit 1/5: "Decision" → "Recommendation" in UI)

First of five commits standardising user-facing terminology across the system. Per the office-hours design doc at `~/.gstack/projects/yosofelkhateeb-Procurement_Project/hp-main-design-20260503-221737.md`.

The split decision: code stays on "decision" (DB tables, models, services, audit log columns are unchanged) — UI everywhere uses "Recommendation". Matches CLAUDE.md decision #7's framing of the system as a "recommendation engine, not a procurement platform."

Files touched (8): Dashboard subtitle + section header + column header; Decisions page heading + column header; SKUs catalogue column + filter; SKU detail panel + history; SKU dialog section; AppHeader nav link; Cmd+K palette entry; glossary canonical entry.

Glossary update: `decision` term replaced with `recommendation` term (single canonical entry — no orphan duplicates). All four `<GlossaryTip term="decision" />` callsites now reference `recommendation`.

Deliberately NOT in this commit (next four):
- Commit 2: KPI cards + tier sections (Order Now / Order Soon / Watchlist) — exclusive counts
- Commit 3: Urgency labels (Severe / High / Medium / Low) + pill case (Title Case)
- Commit 4: Buffer field in SkuDetailDialog and SKU show page
- Commit 5: Glossary entries + locale strings + small leftovers

Visual evidence: `storage/app/design-audit/screenshots/term-c1-{dashboard,decisions}.png`.

178 tests still passing — no test changes (UI-only).

## 2026-05-03 (Shopify Link header host validation — last CSO tentative item closed)

Closes T1 from the 2026-05-02 CSO daily report.

`ShopifySource::nextPageUrl()` now validates that the host parsed from the Link
header's `rel="next"` URL exactly matches `$this->shopDomain`. Mismatched hosts
return `null` so `shopifyRequest()` doesn't follow the redirect and the
`X-Shopify-Access-Token` never leaves the connected shop's domain.

Defence-in-depth — exploitable only with a TLS MITM against `*.myshopify.com`
or a Shopify-side compromise (both unlikely given the pinned-domain regex at
connect time). Two-line fix; the test coverage was the bigger investment.

5 new Pest cases:
- `nextPageUrl returns null when the Link header is empty`
- `nextPageUrl follows a same-host next link`
- `nextPageUrl rejects an off-host next link to prevent token exfiltration`
- `nextPageUrl rejects a same-domain-different-shop next link`
- `nextPageUrl rejects a Link header without a next relation`

Tests use Reflection to invoke the private method directly — preferred over
orchestrating a full Http::fake-driven pagination flow because the security
property is local to that method.

CSO report status (one day after the audit):
- Finding 1 — Promotion role gate           ✅ shipped (`9543850`)
- Finding 2 — Strict TenantContext          ✅ shipped (`9b7ccc4`)
- T1 — Shopify Link header validation       ✅ shipped (this commit)
- T2 — lodash-es CVE                        ✅ shipped (`9543850` via npm audit fix)
- T3 — axios CVE                            ✅ shipped (`9543850` via npm audit fix)
- T4 — vite dev-server CVEs                 ✅ shipped (`9543850` via npm audit fix)

All 6 CSO findings (verified + tentative) are now closed.

## 2026-05-03 (Dark mode + theme toggle — deferred items complete)

Final deferred item from the design audit. All 6 quick-win + bigger-win items shipped today.

### What's live
- **Theme toggle** in the header (sun/moon icon): three options — `System` (follows OS), `Light`, `Dark`. Persists to `localStorage`. The "system" mode reacts live to OS theme flips via `matchMedia` listener.
- **No-flash bootstrap** in `app.blade.php`: a tiny inline `<script>` reads the persisted preference and applies the `dark` class to `<html>` before Vite assets load. Users never see a light flash before the dark theme renders.
- **Tailwind 4 dark variant** wired via `@custom-variant dark (&:where(.dark, .dark *))` in `app.css` — class-strategy, not media-query, so the toggle works.
- **Body element** sets the canonical light/dark surface: `bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-100`.

### Implementation
- New `composables/useTheme.ts` — manages preference, listens to system changes, applies class to `<html>` and `color-scheme` to the document
- New `Components/ThemeToggle.vue` — sun/moon icon button + dropdown with three radio choices, ARIA-labelled, click-outside-to-close
- One-shot Python sweep across 20 `.vue` files (`storage/app/design-audit/dark-sweep.py` + `dark-sweep-hover.py` + `dark-cleanup.py`):
  - 878 base utility pairs (`bg-white` → `bg-white dark:bg-slate-900`, etc)
  - 47 hover variants (`hover:bg-slate-50 dark:hover:bg-slate-800`)
  - cleanup pass to remove duplicate dark: tokens from chained substitutions
- Idempotent — re-running the scripts produces no diff

### Token map applied
| Light | Dark |
|---|---|
| `bg-white` | `bg-slate-900` |
| `bg-slate-50` | `bg-slate-800` (cards/headers) or `bg-slate-950` (page bg) |
| `text-slate-900/800` | `text-slate-100` |
| `text-slate-700/600` | `text-slate-200/300` |
| `text-slate-500/400` | `text-slate-400/500` |
| `border-slate-200/100` | `border-slate-700/800` |
| `divide-slate-100` | `divide-slate-800` |
| `hover:bg-slate-50` | `dark:hover:bg-slate-800` |

Pill colors (red/amber/green) kept as-is — they read as low-saturation accents on dark backgrounds and the reduced opacity from dark surfaces keeps WCAG contrast OK. Status pill backgrounds (e.g. blue/violet for ordered/in-transit) get the same `-100/-700` light-mode pair which holds up on dark too.

### What's not converted (intentional)
- Decision pill colors and confidence pill colors — already use `bg-red-100 text-red-700` style which works in both modes
- Pink "critical row" background tint (`bg-red-50` on rows where days_cover < lead_time_days) — kept light pink in dark mode too; the row remains visually flagged
- Email banner / alert tints (amber/emerald) — stay at light shades; provide signal contrast against the dark backdrop
- Login page — kept gray-50 light to match the bare-page feel of pre-auth screens (could change later if needed)

### 178 tests still passing (793 assertions)
UI-only changes; the `colorScheme` style + class manipulation runs entirely in the browser.

Visual evidence: `storage/app/design-audit/screenshots/`
- `dark-dashboard.png` — three-tier groups, KPI cards, table all dark-themed
- `dark-decisions.png` — paginated decision table, status + confidence pills
- `dark-settings.png` — 25 inputs across 6 sections, all readable
- `dark-promotions.png` — empty state + clean header showing the moon icon
- `dark-skus.png`, `dark-reports.png`, `dark-ingestion.png` — full coverage

### What was shipped today (full list)
1. Promotion role gate + 6-CVE npm audit fix (`9543850`)
2. Strict `TenantContext` closing CSO finding #2 (`9b7ccc4`)
3. Pill icons + 44px touch targets + Promotions de-dup (`864b394`)
4. Dashboard 3-tier triage groups + Fira Code headings (`67ba79b`)
5. Mobile card list + Settings inline help (`7c9b247`)
6. Cmd+K command palette (`aedc262`)
7. Dark mode + theme toggle (this commit)

## 2026-05-03 (Cmd+K command palette)

Power-user feature requested by both review passes. Replaces "click SKUs tab → wait → scroll the catalogue → click row" with `⌘K → BENCH-0009 ↵`.

### What it does
Press **Cmd+K** (Mac) / **Ctrl+K** (Win/Linux) from any authenticated page — or click the new "Search" chip in the header — to open a command palette. Fuzzy-matches across:

- **Pages** — Dashboard, Decisions, SKUs, Promotions, Reports, Ingestion, Settings (with sublabels and shortcut-style badges)
- **SKUs** — by `sku_code` or `name` (e.g. `BENCH-0009`, `Nike`, `Adidas`)
- **Actions** — `Run Engine` (more later)

Empty query shows just pages + actions to avoid drowning the user in 1000-SKU lists. Typing filters across all three categories at once with `.includes()` matching.

### Implementation
- New `Components/CommandPalette.vue` — modal with input, results list, kbd-hinted footer, role="dialog" + role="listbox" + aria-selected for screen readers
- `defineExpose({ open, close })` so `AppHeader` can trigger via ref
- `HandleInertiaRequests` middleware shares a `commandPaletteSkus` prop globally — closure-evaluated, only queries when authenticated
- AppHeader gains a "Search ⌘K" chip on desktop (`sm:` and up) and a search icon button on mobile, both ≥44px touch targets
- Platform-aware shortcut hint: shows `⌘K` on Mac, `Ctrl+K` elsewhere (detected via `navigator.platform`)

### Keyboard
- `Cmd+K` / `Ctrl+K` toggles the palette
- `↑` `↓` move active row (with hover-to-activate so mouse + keyboard mix smoothly)
- `Enter` selects (navigates or runs the action)
- `Esc` closes

### Notes
- `actionIndex.value = 0` resets to top result whenever the filter changes
- Closes automatically after navigation; stays open after typing
- Z-index 50 sits above the bell dropdown (z-50) — both use full-screen-modal patterns so they don't clash visually
- 178 tests still passing (UI-only change). The middleware addition is one line and exercises the existing `Sku::orderBy` query, no behavioural change.

Visual evidence: `storage/app/design-audit/screenshots/`
- `after-cmdk-header.png` — search chip discoverable in header
- `after-cmdk-open.png` — palette default state
- `after-cmdk-search.png` — typing filters across pages + SKUs

## 2026-05-03 (Mobile card list + inline help on every Settings field)

Continuing the deferred-items push — two more shipped.

### Dashboard mobile card list (<768px)
The dashboard table at 375px showed only the first 3 columns; numeric data (days cover, position, demand, lead time, action buttons) was off-screen with no scroll affordance. Operations users on phones in warehouses couldn't see what they needed.

Replaced with a **card list at <768px** that surfaces the three highest-leverage numbers per row in a 3-column grid:

  Days cover   Eff. pos.   Demand/d
     5.13         8          41

Each card shows the SKU name + code, the DecisionPill + urgency label, the number grid, and the lead-time + action buttons at the bottom. Critical rows keep the pink tint. The same `sections` / `sectionsOpen` / `toggleSection` from the desktop grouping is reused — collapse state stays in sync if the user rotates the device.

Visibility: `hidden md:block` on the table, `md:hidden` on the cards. At ≥768px the user gets the full table; below it, the cards.

### Inline help on every Settings field
The Settings page exposed 25 numeric inputs across 6 sections — labels like "Intermittency Threshold", "k_smape", "Coverage Target". An owner who hadn't read `docs/FORECASTING_ENGINE.md` would have to guess values that directly drive forecast behaviour.

Three small changes:

1. Extended `<GlossaryTip>` to accept an inline `definition` prop (in addition to looking up a shared `term`). Same UI, same tooltip positioning logic, no duplication.
2. Added a `fieldHelp` map in `Settings/Index.vue` keyed by field-name suffix, with one-sentence guidance per field sourced from the seeder's existing doc-comments.
3. Wired `<GlossaryTip>` next to every label across the 6 sections + Notifications section. Hover or focus reveals the popover; the existing collision-aware placement in GlossaryTip handles edge cases.

The user now hovers `?` to learn that "Intermittency Threshold" is "Ratio of zero-sale days to total days. Above this, the SKU is treated as intermittent and routed to a Croston-style model instead of the regular forecaster" — without leaving the Settings page.

- **178 tests still passing (793 assertions)** — UI-only changes.

Visual evidence: `storage/app/design-audit/screenshots/`
- `dashboard-after-mobile-{mobile,tablet,desktop}.png`
- `after-settings-help.png`

## 2026-05-03 (Dashboard triage groups + Fira Code display headings)

Continuing through the deferred items from yesterday's design audit. Two more shipped today.

### Dashboard "Active Decisions" → 3-tier triage groups
The 30-row flat scroll wall is replaced with three collapsible sections:

- **Critical — order today** — `decision = order/budget_blocked` AND `days_of_cover < lead_time_days`. Pink-tinted header, expanded by default. The "act today, can't wait" bucket.
- **Order now — plan ahead** — other order/budget_blocked decisions. Orange-tinted header, expanded by default. The "act this week" bucket.
- **Watch — monitor** — `decision = watch`. Amber-tinted header, **collapsed by default**. Biggest count, lowest action payoff, so it stays out of the way until clicked.

Each section header is a button with a rotating chevron, count badge, `aria-expanded` / `aria-controls` for screen readers, and `min-h-[36px]` + `focus-visible:ring` for keyboard / mobile use.

Search and sort apply globally — typing "BENCH-0009" filters all three sections at once and any section with zero results hides its header (verified via DOM inspection — only the matching section remains visible).

Implementation: a `sections` computed array of `{ key, label, rows, badgeClass, headerBg }` drives a single `v-for` template, replacing the previous single `<tbody>`. Filtering logic is pure (no event listeners, no router round-trips), so the grouping doesn't slow the page.

### Fira Code for display headings (h1/h2)
Per the ui-ux-pro-max design-system canon for "data-dense dashboard" products, h1/h2 now use Fira Code monospace instead of Fira Sans. Body, h3, h4, table content stay in Fira Sans for readability density.

- Added `--font-display` token in `app.css @theme` block (separate from `--font-mono` to keep semantic separation)
- `h1, h2` rule applies the display font with `letter-spacing: -0.01em` to compensate for monospace's open tracking
- Verified via `getComputedStyle` — both heading levels resolve to Fira Code
- Page-title weight stays the same; only the typeface changes

The result: page titles like "Dashboard", "SKU Catalogue", "Forecast Settings", "Promotional Calendar" read with technical/precise tracking that reinforces the operations-tool identity. The change is intentionally subtle — Fira Code at heading sizes is recognizable to a designer but doesn't shout to a user.

- **178 tests still passing (793 assertions)** — no test changes (UI-only).

## 2026-05-03 (UI quick wins from /design-review + /ui-ux-pro-max)

Reviewed the seven authenticated pages with both gstack `/design-review` and the `ui-ux-pro-max` rule-pass. Two reports written to `storage/app/design-audit/` (gitignored). Shipped the highest-leverage quick wins from the synthesis:

### Pill icons + accessibility (color-only encoding fix)
- New `Components/DecisionPill.vue` — renders an SVG shape (▲ order, ○ watch, ✓ hold, ⚠ budget-blocked) plus the label, with `aria-label="Decision: …"` and `role="status"`. Color is no longer the only signal — WCAG SC 1.4.1 compliance and 8% of male users with red-green deficiency now get the same information.
- New `Components/ConfidencePill.vue` — same pattern, ▲ high / ■ medium / ▼ low.
- Replaced inline pill rendering across `Dashboard/Index.vue`, `Decisions/Index.vue`, `SKUs/Index.vue`, `SKUs/Show.vue`. Existing `decisionBadgeClass` / `confidenceBadgeClass` maps left in place for callers we didn't touch yet.

### Touch targets at 44px (Apple HIG / Material baseline)
- `AppHeader.vue` nav links: `min-h-[44px]` via `inline-flex items-center` (visual padding stays compact).
- Glossary `?` button: `min-w/min-h [44px]` instead of `w-7 h-7` (28×28).
- `NotificationBell.vue` button: `min-w/min-h [44px]` instead of `p-2` (36×36).
- Header bar: `min-h-[56px]` to accommodate the larger targets.
- `focus-visible:ring-2 focus-visible:ring-blue-500` on every interactive element in the header for keyboard nav.
- Verified via `getBoundingClientRect()` — every header target now reports 44px height.

### Promotions: redundant CTA hidden when empty
- The header `+ Add Promotion` button now hides via `v-if="promotions.length > 0"`. The empty-state card's centered button is the only CTA when no promotions exist — single primary action, no dilution.
- Added `focus-visible:ring` to the header CTA for parity.

### Notes from the review (not findings, kept intentional)
- Fira Sans (real typeface) — kept. The skill's recommendation to also adopt Fira Code for h1/h2 is in the report for later.
- Tabular numerals already widely applied (52 `tabular-nums` occurrences across 7 pages) — the original review's "missing tabular-nums" finding was off; spot-checks confirmed numeric columns are tabular.
- `NotificationBell` already had unread-state dot + conditional aria-label — no change needed.
- Login page subtitle "Sign in to your account" is plain `<p>` not a link — false alarm from screenshot interpretation.

### Reports (kept locally in `storage/app/design-audit/`, gitignored)
- `design-audit.md` — gstack designer narrative review, 14 findings + scoring (Design C+, AI Slop B+).
- `ui-ux-pro-max-review.md` — rule-pass across 10 priority categories. Adds: dark mode is missing, Reports has no charts, KPI sparklines opportunity, semantic color tokens via Tailwind 4 `@theme`, breadcrumbs, autosave on Settings, Cmd+K palette.
- 11 screenshots (login + 7 pages + 3 responsive sizes).

### Deferred (scoped for follow-up)
- Dashboard table grouping (Critical / Watch / Healthy collapsibles) — biggest UX win, ~2h.
- Mobile card list for dashboard at <768px — currently broken under tablet width.
- Cmd+K command palette — power-user feature.
- Inline help on Settings fields — 25 inputs without context.
- Adopt Fira Code for h1/h2 — visual identity upgrade, 30min.
- Dark mode — Tailwind 4 `@theme` makes this ~30 min once palette is tokenised.

- **178 tests still passing (793 assertions)** — no test changes needed (component refactor, no behavior change).

## 2026-05-02 (CSO security pass — promotion role gate + dependency hygiene)

First /cso (gstack) audit pass. Two medium findings, both addressed.

### Finding 1 fix — PromotionController owner-only writes
- `StorePromotionRequest::authorize()` and `UpdatePromotionRequest::authorize()` now `return $this->user()?->hasRole('owner') ?? false` (was `return true`).
- `PromotionController::destroy` adds `abort_unless($request->user()->hasRole('owner'), 403)` with `Request $request` parameter.
- Matches the existing posture across IngestionController, ShopifyController, SettingsController, and InventoryDecisionController.
- 4 new Pest cases: non-owner cannot create / update / delete / no-role-cannot-create. All 10 PromotionController tests pass.

### Dependency hygiene — `npm audit fix`
- 6 vulns → 0 (lodash-es high CVE, axios + follow-redirects + vite moderate CVEs). Bumped 8 packages.
- None were exploitable in our usage (axios is browser-only here so the Node.js NO_PROXY/cloud-metadata vectors don't apply; lodash code-injection requires upstream `_.template` call we don't make; vite vulns affect dev server only). Hygiene bumps remove footguns.

### Finding 2 fix — strict TenantContext (closes the multi-tenant footgun)
- New `App\Support\TenantContext` (set / clear / peek / run / tenantId). Resolution order: `Auth::user()->tenant_id` → bound context → throw `RuntimeException`.
- `TenantScope::apply` now reads from `TenantContext::tenantId()` and throws when no tenant is in scope. The previous `Auth::check() ? Auth::user()->tenant_id : 1` fallback is gone — silent cross-tenant leaks the moment a 2nd tenant lands.
- All 11 model `creating` hooks updated identically (Sku, Supplier, EngineRun, InventoryDecision, Promotion, SalesHistory, ForecastModelRegistry, SkuDemandProfile, FeedbackMetric, DataIngestionRun, StockAdjustment).
- Jobs that hit scoped models without an authenticated user now wrap their bodies in `TenantContext::run($this->tenantId, fn () => ...)`: RunInventoryEngineJob (now requires `$tenantId`), RunForecastJob, RunCsvImportJob, RunShopifyInitialLoadJob, RunShopifyIncrementalSyncJob, RunDecisionCalibrationJob. Cross-tenant iterators (AnalyzeRecommendationFeedbackJob, CheckBiasDriftJob) bind per inner tenant.
- `EngineController` passes `$user->tenant_id` to `RunInventoryEngineJob::dispatch`. The daily-engine scheduled task in `routes/console.php` now iterates `Tenant::query()` and dispatches one job per tenant (matching the existing calibration scheduler pattern).
- Console commands wrap their bodies: `forecast:benchmark`, `training:generate`, `ingestion:csv`. `calibration:run`, `forecast:sweep`, `ingestion:cleanup-uploads` already safe (delegate to jobs or pure FS).
- Seeders: `DatabaseSeeder` wraps `run()` in `TenantContext::run(1, ...)` so `php artisan db:seed` works.
- Tests: `tests/Pest.php` `beforeEach` binds tenant 1 by default for Feature + Unit tests; `afterEach` clears. Tests that need to exercise the unbound state clear locally first.
- 7 new unit tests in `tests/Feature/Support/TenantContextTest.php` covering: bound-context resolution, throw on unbound, `run()` lifecycle (success and exception paths), `peek()` semantics, auth-wins-over-context.

- **178 tests passing (793 assertions)** (was 171 / 784).

### Repo housekeeping
- Added `## Skill routing` section to CLAUDE.md (commit `60dc2ae`) so future sessions auto-route to gstack skills (`/qa`, `/review`, `/investigate`, etc).
- `.gstack/` added to `.gitignore` (security reports stay local).

- **171 tests passing (784 assertions)** (was 116 / 434 before).

## 2026-04-23 (Shopify-shaped data fixtures + benchmark + pathology CI — guardrail 5 of 5)

Closes out the production-hardening work: a Shopify-shape fixture builder for realistic data, an artisan benchmark command that produces a human-readable report over 30 SKUs, and a Pest test that locks in what each data pathology looks like plus a slow integration test that runs every pathology through the real pipeline.

### Why PHP for the fixture
All the consumers are PHP (artisan benchmark, Pest tests, existing Laravel factories). Putting the fixture builder in PHP means the fixture path `Shopify JSON → ShopifySource::transform → SalesHistory` matches what the production Shopify sync does, and existing `Sku::factory()` / `Supplier::factory()` / global scopes are reused for free.

### Fixture builder
- `tests/Fixtures/ShopifyOrderFactory.php` — produces order arrays matching [Shopify's Admin REST `orders.json` shape](https://shopify.dev/docs/api/admin-rest/latest/resources/order) (the subset consumed by `ShopifySource::fetchOrderLineItems`: `created_at`, `line_items[].sku/quantity`, `discount_codes`, `refunds[].refund_line_items`). Deterministic from an RNG seed.
- Seven pathologies: `clean`, `sparse`, `stockout_gaps`, `promo_spike`, `returns_heavy`, `stopped_selling`, `new_sku`. Each models a real shape a Shopify sync can produce.
- `tests/Fixtures/ShopifyFixtureGenerator.php` — orchestrator that generates N SKUs with a weighted pathology mix (default: 12 clean / 6 promo_spike / 4 sparse / 3 stockout / 2 returns / 2 stopped / 1 new_sku for 30 SKUs), persists them through `SalesHistory::insert()`.

### Benchmark command
- `php artisan forecast:benchmark --skus=30 --seed=42 --history-days=365` — generates the fixture, runs `RunForecastJob::handle()` for each SKU, writes a Markdown report to `storage/app/forecasting/benchmarks/benchmark_<ts>.md` with portfolio WMAPE, per-pathology rollup (median sMAPE, mean runtime, warning-hit count), and per-SKU detail. Supports `--skip-fixture` for re-running the pipeline against an existing seed.

### Data-shape CI test
- `tests/Feature/Forecasting/PipelineDataShapesTest.php`:
  - **Tier 1 (fast, 9 tests, ~1.3s)** — shape assertions per pathology. Clean series mean is ~baseLevel; sparse is mostly zero; stockout_gaps has an 8+ day internal gap; promo_spike produces discount codes with 1.5×+ spike on promo days; returns_heavy net quantity < gross; stopped_selling last sale is ≥30 days before the series end; new_sku spans only 30 days; factory output is deterministic for a given seed; generator covers all pathologies for 30 SKUs.
  - **Tier 2 (slow, `python-integration` group)** — one SKU per pathology fed through the real Python pipeline. Asserts the pipeline doesn't crash on any shape, `stopped_selling` trips the `trailing_zero_run` audit warning (our 2026-04-23 guardrail), and `sanity_ceiling_breach` never fires for any shape.

- **133 tests / 565 assertions in the fast suite** (was 124/483). +1 slow integration test covering all 7 pathologies.

## 2026-04-23 (Production-hardening guardrails — 4 of 5 complete)

Response to the 2026-04 silent-wrong forecasting bugs (100% → 4.6% WMAPE): four layers of guardrails so regressions fail loudly in CI and drift is visible in production. Scale/data-shape testing deferred.

### 1. Pipeline assertions (silent-wrong → loud)
- `python/forecasting/pipeline/data_audit.py`: counts consecutive zero-quantity days at the tail of the series (`trailing_zero_run_days`). Above `audit.max_trailing_zero_run_days` (default 3), emits an `audit_warnings` entry surfaced on the output JSON.
- `python/forecasting/main.py`: winner sMAPE checked against `evaluation.wmape_sanity_ceiling_pct` (default 80%). Above it, emits `sanity_ceiling_breach` warning and logs `ERROR` to the run log. The 2026-04 100%-WMAPE bugs would each have tripped this.
- `_empty_audit()` now carries an explicit `empty_sales_history` warning instead of failing silently through ets_fallback.
- `staleness_days` and all audit warnings are copied into the run's `warnings_out` → registry `warnings` column.

### 2. Monitoring loop (close the feedback)
- `AnalyzeRecommendationFeedbackJob` now computes portfolio WMAPE per tenant after processing decisions and logs `weekly_portfolio_digest` to the `forecasting` log channel (sku_count, portfolio_wmape_pct, drift_events). Single grep target for week-over-week drift.
- `ReportsController` now computes per-SKU staleness from the max `sale_date` per SKU and flags any SKU past `monitoring.sku_staleness_warning_days` (default 7, configurable per tenant).
- `Reports/Index.vue`: amber banner at the top of the page listing the first 5 stale SKUs with last-sale date and days stale.
- Seeded the new `monitoring.sku_staleness_warning_days` key in `ForecastSettingsSeeder`.

### 3. Golden-SKU regression guard (catch silent data regressions in CI)
- `tests/Feature/Forecasting/GoldenSkuWmapeRegressionTest.php` — seeds 365 days of a deterministic weekly-seasonal pattern (`qty = 10 + 3·sin(2π·dow/7) + noise(seed=42)`), invokes the real Python pipeline via `RunForecastJob::handle()`, asserts sMAPE < 25%, demand_rate in [6, 14], and no trailing-zero warning. Grouped `slow`/`python-integration` so it stays out of fast suites. Runs ~180s. Skips cleanly when the Python binary is unreachable.
- Each of the three silent-wrong bugs we fixed this week would have failed this test.

### 5. Model freshness contract
- `routes/console.php` now schedules `forecast:sweep` monthly at 03:00 on the 1st. Monthly safety-net re-trains every active SKU even when bias/feedback drift triggers don't fire.
- `tests/Feature/Forecasting/ForecastFreshnessContractTest.php` — asserts `RunForecastJob implements ShouldQueue` and targets the `forecasting` queue; asserts the monthly sweep, daily bias drift check, and weekly feedback analysis are all registered on the scheduler. A future refactor that downgrades `RunForecastJob` to sync or drops a schedule fails loudly.

- **124 tests passing / 483 assertions in the fast suite** (was 120/477). +1 golden test (125 total) runs in ~180s and lives in the `slow` / `python-integration` groups.

## 2026-04-22 (Engine run timeout fix — bias drift check moved to daily schedule)

- **Bug**: Pressing "Run Engine" on the Dashboard returned a 500 — `Symfony\Component\ErrorHandler\Error\FatalError: Maximum execution time of 30 seconds exceeded` at `Symfony\Process\Pipes\WindowsPipes.php:145`.
- **Root cause**: `InventoryEngineService::run()` called `$this->checkBiasDrift($skus)` at the end of every engine run. For any SKU with |bias| > 15% (which included SKUs 5, 6, 11 on the current data), it dispatched `RunForecastJob` synchronously. On `QUEUE_CONNECTION=sync` (Laravel default; `.env.example` targets `database`) this ran the Python forecasting pipeline inline in the web request — ~120s per drifted SKU against PHP's 30s `max_execution_time`.
- **Fix**: Extracted `checkBiasDrift` logic into `app/Jobs/CheckBiasDriftJob.php` (implements `ShouldQueue`). Removed the inline call from the engine run. Scheduled the new job daily at 07:00 on the `forecasting` queue via `routes/console.php`. Engine runs now do pure scoring and write decisions in well under a second.
- **Behavior preserved**: Bias-drift detection threshold, logic, and RunForecastJob dispatch signature are unchanged — the two `ForecastTriggersTest` tests were updated to call `CheckBiasDriftJob::handle()` directly, and all 7 pass. Full suite: 120 passed / 477 assertions.

## 2026-04-22 (Forecast seeder race fix)

- **Bug**: Every forecast in `forecast_model_registry` shipped with `model_name=ets_fallback`, `demand_rate=0`, and the warning `Insufficient history — ets_fallback assigned without model competition.` — producing an exact 100% Portfolio WMAPE on the Reports page.
- **Root cause**: `SkuObserver::created()` dispatches `RunForecastJob(new_sku)` on every SKU insert. `SyntheticDataSeeder` created SKUs at step 5 and inserted sales_history at step 6. On `QUEUE_CONNECTION=sync` (local dev) the forecast jobs ran immediately during step 5, saw an empty `sales_history` table, tripped `classifier.py`'s `history_days < min_history_days` gate, and wrote `demand_rate=0`. Timestamps confirm it — every `trained_at` is earlier than the single `sales_history.created_at`.
- **Fix**: Wrap SKU creation in `Sku::withoutEvents(fn () => …)` so the observer does not fire during seeding. Forecasts are now produced separately via `php artisan forecast:sweep` after seeding completes, which runs against the fully-populated `sales_history`. Explicit comment in the seeder documents the intent so this doesn't regress.

## 2026-04-22 (GlossaryTip flexible placement)

- **Bug**: The `?` tooltips were `position: absolute; bottom-full` — always above the trigger, centered, nested inside the trigger's relative parent. Two failure modes: (1) clipped by ancestors with `overflow-hidden` (rounded cards, scrollable tables); (2) clipped by the viewport when the trigger sat near the top of the page (stat card labels, the topmost table header row).
- **Fix**: `GlossaryTip` now Teleports the tooltip into `<body>` and positions it with `position: fixed` via JavaScript. Placement is computed at open time from `getBoundingClientRect()`: prefer top → bottom → right → left based on available viewport space; fall back to the direction with the most room if nothing fits. Final `left` / `top` are clamped to an 8px viewport margin so the tooltip is never partially offscreen. Scroll (capture-phase, so nested scrollable containers also fire it) and resize re-run the placement while the tooltip is open.

## 2026-04-22 (Decisions page fix)

- **Bug**: `/decisions` returned a 500 on local dev. Root cause: `DecisionController::index()` used `orderByRaw("FIELD(status, …)")` for lifecycle-based ordering. `FIELD()` is a MySQL-specific function; SQLite (local dev) reports `no such function: FIELD`. The bug shipped silently in `98d8b50` (Tasks 4-6) because there was no feature test hitting the endpoint.
- **Fix**: Replaced `FIELD()` with a portable `CASE status WHEN … THEN n … ELSE 99 END` built from `InventoryDecision::STATUSES` so SQLite, MySQL, and Postgres all agree on the ordering.
- **Regression guard**: New `tests/Feature/Decisions/DecisionsPageTest.php` — seeds one decision per lifecycle status, hits `/decisions`, asserts 200 + Inertia component + `decisions.data.0.status === 'pending'`. Also covers empty-state render, decision-type filter, and guest redirect.
- **120 tests passing (477 assertions)** — was 116/434.

## 2026-04-22 (UI polish — Task 11)

- New shared `Components/EmptyState.vue` (icon slot, title, description, action slot, `dense` padding variant) — replaces 8 distinct inline empty-state patterns with one component
- Applied EmptyState to: Dashboard "All stock levels healthy" + "No recent activity"; Decisions "No recommendations" + "No audit entries"; Reports "No forecast data"; SKUs/Index "No SKUs match filters"; SKUs/Show "No forecast model" + "No decisions yet"; Promotions "No promotions scheduled"; Ingestion "No import runs yet"
- Consistency pass: H1 normalised to `text-2xl font-semibold text-slate-900` on Promotions, Ingestion, Settings (were `text-xl font-bold text-slate-800`)
- Promotions and Ingestion now wrap in `<div class="min-h-screen bg-[#F8FAFC]">` matching the other pages
- Decision badges unified to `border border-{hue}-200` everywhere — Dashboard, SKUs/Index, and SkuDetailDialog previously used `ring-1 ring-{hue}-200`
- `SkuDetailDialog` HOLD badge emerald→green and urgency "OK" emerald→green to match the rest of the app
- Ingestion `statusBadgeClass.completed` emerald→green; rows_succeeded cell emerald→green (Shopify connected-state panel kept emerald as a brand/live-connection signal)
- Settings "Save Settings" button `bg-[#2563EB]` replaced with `bg-blue-600` token (same hex, consistent naming)
- `AppHeader` "Inventory Engine" wordmark `text-[#1E293B]` replaced with `text-slate-800` token
- A11y: `SKUs/Index` preview-eye button now has a descriptive `aria-label` + `focus-visible` ring; `GlossaryTip` aria-label now includes the term name; `NotificationBell` gains `aria-expanded`/`aria-haspopup` + live alert count in the label + focus-visible ring
- Vite production build clean, 116 tests passing (434 assertions) — no backend regressions

## 2026-04-22 (UI redesign — Tasks 1–10)

- Task 1: Added Decisions nav link to AppHeader; fixed "Accessorys" → "Accessories" in Settings page; removed Glossary tab from Dashboard
- Task 2: Created `useGlossary.ts` composable (30 terms) and `GlossaryTip.vue` hover/focus tooltip component
- Task 3: Reworked Dashboard — removed tabs/WMAPE card; ORDER NOW and WATCH cards now navigate to Decisions; Run Engine moved below stat cards; added Recent Activity section with last 5 status transitions; GlossaryTip on key column headers
- Task 4: New Decisions page — `DecisionController` (index + auditLog), routes, and `Decisions/Index.vue` with Active Recommendations tab (filter bar, status lifecycle pills, RecommendationActions, CSV export) and Audit Log tab (flattened status_history, CSV export)
- Task 5: Extended `SkuController::show()` and fully rewrote `SKUs/Show.vue` — forecast model panel, decision history table, feedback metrics table, demand profile chips, export summary button
- Task 6: Reworked Reports page — WMAPE card at top, GlossaryTip on column headers, human-readable trigger badges, CSV export
- Task 7: SKUs/Index.vue — SKU code and name now link to /skus/{id}; eye icon opens SkuDetailDialog; GlossaryTip on On Hand, Days Cover, Decision, Class headers
- Task 8: Promotions/Index.vue — enriched empty state with icon + message + Add Promotion button; SKU multi-select now has a live search input filtering by code/name
- Task 9: AppHeader glossary slide-over — ? button in nav opens a right-aligned w-80 Teleport panel with searchable glossary list
- Task 10: 116 tests passing (434 assertions); Vite production build clean (0 errors)

## 2026-04-18 (Services and jobs phase — Parts 1–3)

### PART 1 — Recommendation action tracking
- Migration: 9 new columns on `inventory_decisions` (`status_history` JSON, `ordered_qty/at`, `expected_arrival`, `supplier_id` FK, `received_qty/at`, `ignored_reason`, `superseded_by_decision_id` self-FK)
- `InvalidStatusTransitionException` — thrown on illegal status moves
- `DecisionStatusService`: 5 user-initiated transition methods (`acknowledge`, `markOrdered`, `markInTransit`, `markReceived`, `ignore`); `supersedePending()` now returns IDs for `superseded_by_decision_id` linking
- `InventoryEngineService::persist()`: uses `supersedePending()` return value to back-fill `superseded_by_decision_id` after new decision is created
- `InventoryDecisionController`: `PATCH /inventory-decisions/{decision}/status`; owner/warehouse role gate; 422 on invalid transition
- 5 new Pest tests (full chain, illegal transition, terminal-state block, audit-trail correctness, controller endpoint)

### PART 2 — Recommendation feedback loop
- Migration: `feedback_metrics` table (tenant/sku/period + 4 float metric columns + composite index)
- `FeedbackMetric` model with `TenantScope`, casts, `sku` relation
- `ForecastDriftDetected` event (`skuId`, `tenantId`, `reason`)
- `AnalyzeRecommendationFeedbackJob`: weekly queueable; groups past-7-day decisions by tenant+SKU; computes `ignored_rate`, `ordered_delta`, `received_delta`, `superseded_rate`; stores `FeedbackMetric`; dispatches `ForecastDriftDetected` when any threshold breached
- Weekly scheduler entry in `routes/console.php`
- 4 new threshold settings keys in `ForecastSettingsSeeder`
- 3 new Pest tests

### PART 3 — ForecastPresenter
- `InvalidForecastOutputException`
- `ForecastPresenter` service in `app/Services/Forecasting/` with `humanReadableForecast(array): array` (sentence + scaled point/lower/upper + confidence_label) and `confidenceLabel(float, float): string` (high/medium/low via configurable thresholds)
- 3 new confidence threshold settings keys in `ForecastSettingsSeeder`
- 4 new Pest tests (sentence format, confidence labels, exception on missing fields)

**82 tests passing (313 assertions)**

## 2026-04-19 (Phase 3 — Shopify connector)

- `ingestion_credentials` migration + `IngestionCredential` model: encrypted JSON credentials cast, tenant-scoped, `(tenant_id, source)` unique constraint
- `sku_external_mappings` migration + `SkuExternalMapping` model: maps external source SKU codes to internal SKUs
- `ShopifySource`: implements `IngestionSource`; Link-header cursor pagination; 429 exponential backoff; transforms product variants → skus, order line items → sales_history
- `RunShopifyInitialLoadJob` (forecasting queue): stock sync for existing SKUs + 24-month order history pull via `SalesHistoryImporter`; unmapped sku_codes logged as errors in run
- `RunShopifyIncrementalSyncJob` (default queue): cursor-based hourly sync; skips inactive credentials
- `ShopifyController`: `POST /ingestion/shopify/connect` (owner-only, saves encrypted credentials, dispatches initial load), `DELETE /ingestion/shopify/disconnect`, `POST /ingestion/shopify/sync`
- Hourly scheduler entry in `routes/console.php`: dispatches incremental sync per active Shopify credential
- `Ingestion/Index.vue`: Shopify section with connect form, connected-state card (domain, last sync, Sync Now, Disconnect), credential status via `shopify` Inertia prop
- `IngestionController::index()`: passes `shopify` connection status prop
- 10 new Pest tests: connect/disconnect/sync auth, domain validation, transform correctness, inactive skip, live sync with Http::fake()

**113 tests passing (430 assertions)**

## 2026-04-19 (Phase 2 — Security baseline)

- Rate limiting: `engine.run` 10/min, `ingestion.upload` 10/hr/tenant, `decision.transition` 60/min — registered in AppServiceProvider, applied via throttle middleware on routes
- `CsvSource`: `realpath()` path guard rejects files outside `sys_get_temp_dir()` or `storage/app`
- `RunForecastJob`: `reeval_trigger` allowlist validation in constructor; required Python output key check before upsert

## 2026-04-19 (Phase 1 — Settings page)

- `SettingsController::update()`: owner-only gate (`abort_unless hasRole owner`)
- `SettingsController::index()`: passes `canEdit` prop to Inertia
- `Settings/Index.vue`: added Feedback Loop and Confidence Labels sections; inputs disabled + Save button hidden for non-owners; read-only notice banner
- 5 new tests: render (any user), canEdit=true for owner, successful update, 403 for non-owner, 422 on negative value

## 2026-04-18 (Data ingestion Phase 1 — CSV upload)

- `IngestionSource` interface (`name`, `supports`, `fetch`, `naturalKey`, `transform`)
- `CsvSource`: PHP built-in `fgetcsv` parsing; transforms `stated_lead_time_days→avg_lead_time_days`, `unit_cost` SAR→halalas (×100), `is_promotion` string→bool
- `SupplierImporter`: validates name required; upserts on `(tenant_id, name)`; dry-run support; partial/failed/completed status
- `SkuImporter`: pre-builds supplier name→id map; validates category enum and supplier presence; sets `reorder_qty=moq`; upserts on `(tenant_id, sku_code)` with halala conversion
- `SalesHistoryImporter`: pre-builds sku_code→sku_id map; aggregates same-SKU+date rows (sum qty, OR promotion flag); upserts via `DB::table()->updateOrInsert()` to bypass Eloquent scope issues
- `DataIngestionService`: orchestrator; creates `DataIngestionRun`, sets metadata, delegates to importer
- `RunCsvImportJob`: moves uploaded file to temp, calls service, deletes temp in `finally`
- `IngestionController`: `GET /ingestion` (runs table + canUpload), `POST /ingestion/upload` (owner-only, dispatches job), `GET /ingestion/template/{entity}` (CSV download, 404 for unknown)
- `IngestionCsvCommand` (`ingestion:csv`): artisan path-to-CSV runner
- `IngestionCleanupUploadsCommand` (`ingestion:cleanup-uploads`): removes stale temp files
- `Ingestion/Index.vue`: three upload sections with template download links, recent runs table with expandable error rows
- 16 new Pest tests (controller access/auth/dispatch, 3 importers — happy path, idempotency, error handling, dry-run)

**98 tests passing (365 assertions)**

## 2026-04-16 (Reports page)

- `ReportsController`: queries `forecast_model_registry`, computes portfolio WMAPE over trailing 30 days, returns per-SKU rows to Inertia
- `GET /reports`: new route, named `reports.index`, behind `auth` middleware
- `Reports/Index.vue`: table with model colour badges (holt_winters=blue, lightgbm=emerald, croston=amber, ets_fallback=slate), MAE, sMAPE (amber highlight >50%), interval range, empirical coverage (✓/↓ vs target), selection rationale truncated with full tooltip, trigger badges
- `AppHeader.vue`: Reports nav link added between Promotions and Settings
- i18n: `reports.*` keys added to `en.ts` and `ar.ts`
- 2 new Pest tests — 66 total passing

## 2026-04-16 (Interval wiring fix + process timeout)

- Smoke test revealed: `Process::run()` was timing out at 60s; Python pipeline takes ~128s per SKU — all 11 jobs were failing
- `RunForecastJob`: added `->timeout(config('forecasting.process_timeout', 600))` — timeout now config-backed via `FORECAST_PROCESS_TIMEOUT` env var
- `config/forecasting.php`: added `process_timeout` key (default 600s, env-overridable)
- `ForecastModelRegistry`: added 7 new columns to `$fillable` and `casts()` (`interval_lower/upper/confidence/coverage`, `selection_rationale`, `transformation_applied`, `hyperparameters`)
- `RunForecastJob`: upsert now writes all 7 new fields (previously silently dropped)
- Migration: made `interval_confidence` nullable with DB default 0.95 (was NOT NULL, conflicting with null=not-computed semantics)
- New Pest test: `persists interval and selection fields from Python output`
- All 11 SKUs backfilled — `forecast_model_registry` + `sku_demand_profiles` both at 11 rows; `selection_rationale` and `interval_lower/upper` now populated
- 64 tests passing (258 assertions)

## 2026-04-15 (Python pipeline upgrade + intervals migration)

- Python pipeline: `models/selection.py` — Stage 12 selection logic with baseline gate, test MAE ranking, bias tiebreaker, diagnostic penalty, simplicity preference, runtime warning
- Python pipeline: `models/holt_winters.py` — added `_train_series`, `_hyperparameters`; Stage 10 grid search over α/β/γ via `tune()`
- Python pipeline: `models/lightgbm_model.py` — added `_train_series`, `_fitted_model`; Stage 10 Optuna/grid-search `tune()`
- Python pipeline: `evaluator.py` — full rewrite with `evaluate_on_folds()` (walk-forward CV, excludes imputed rows), `evaluate_on_holdout()`, legacy fallback kept
- Python pipeline: `registry.py` — extended output schema with interval fields, `selection_rationale`, `transformation_applied`, `hyperparameters`
- Python pipeline: `main.py` — complete 15-stage pipeline (classifier → data audit → preprocessing → EDA → baselines → feature engineering → walk-forward CV → model training/eval → diagnostics → tuning → intervals → selection → refit/forecast → monitoring)
- DB migration: added `interval_lower`, `interval_upper`, `interval_confidence`, `interval_empirical_coverage`, `selection_rationale`, `transformation_applied`, `hyperparameters` columns to `forecast_model_registry`
- Gitignore: excluded `python/forecasting/reports/` (generated pipeline artifacts)
- Verified end-to-end: pipeline runs clean on both high-volume continuous and low-volume intermittent SKUs
- 63 tests passing (251 assertions)

## 2026-04-14 (Phase 5)

- Phase 5: Created `SkuObserver` — dispatches `RunForecastJob(reeval_trigger=new_sku)` on SKU create
- Phase 5: Registered observer in `AppServiceProvider::boot()`
- Phase 5: Added bias drift check to `InventoryEngineService::run()` — dispatches `RunForecastJob(reeval_trigger=bias_drift)` when |bias%| > threshold (default 15%)
- Phase 5: Created `forecast:sweep` artisan command — dispatches `RunForecastJob(scheduled)` for all active SKUs per tenant
- Phase 5: Added `Queue::fake()` to `TestCase::setUp()` — prevents SkuObserver from executing Python synchronously in all tests; test-specific `Queue::fake()` calls reset the captured-jobs list
- Phase 5: 7 new trigger tests — 63/63 passing (251 assertions)

## 2026-04-13 (Phase 4)

- Phase 4: Created `forecast_model_registry` migration + `ForecastModelRegistry` model (TenantScope, unique per sku+tenant)
- Phase 4: Created `sku_demand_profiles` migration + `SkuDemandProfile` model (TenantScope)
- Phase 4: Created `system_settings` migration + `SystemSetting` model with static `get()` helper
- Phase 4: Created `ForecastSettingsSeeder` — seeds default forecast thresholds for equipment/accessory/bundle categories
- Phase 4: Added `is_promotion` column to `sales_history` table (migration + model + seeder)
- Phase 4: Added `category` column to `skus` table (equipment/accessory/bundle) + factory + seeder
- Phase 4: Created `RunForecastJob` — loads history/promotions/holidays, calls Python subprocess, upserts registry and demand profiles
- Phase 4: Created `config/forecasting.php` — `PYTHON_BIN` env var (default: python)
- Phase 4: Upgraded `DemandForecaster` — reads `demand_rate` from registry (primary), falls back to weighted moving average
- Phase 4: Updated `InventoryEngineService` to pass `tenant_id` to `DemandForecaster::forecast()`
- Phase 4: 8 new tests — 56/56 passing (241 assertions)

## 2026-04-13 (Phase 3)

- Phase 3: Created Python forecasting microservice in `python/forecasting/`
- Phase 3: `classifier.py` — demand profile classifier (volume, volatility, intermittency, seasonality ACF, trend linregress) → candidate model shortlist
- Phase 3: `models/ets_fallback.py` — SimpleExpSmoothing fallback, always available
- Phase 3: `models/holt_winters.py` — ExponentialSmoothing, auto-selects additive/multiplicative by AIC
- Phase 3: `models/croston.py` — manual Croston implementation for intermittent demand
- Phase 3: `models/sarimax.py` — pmdarima auto_arima order selection + statsmodels SARIMAX with exog promo column
- Phase 3: `models/lightgbm_model.py` — LightGBM with lag/rolling/calendar feature engineering, iterative multi-step forecasting
- Phase 3: `models/prophet_model.py` — Meta Prophet with promotion regressor (optional, skipped gracefully if not installed)
- Phase 3: `evaluator.py` — train/test split (last 20%, min 30 days), MAE/RMSE/Bias/sMAPE, portfolio WMAPE
- Phase 3: `registry.py` — formats complete output JSON (trained_at, next_review_at, warnings)
- Phase 3: `main.py` — argparse entry point, classifier → competition → winner selection (lowest MAE) → refit → output
- Phase 3: Fixed `numpy.bool_` serialization bug in classifier (cast to `bool()`)
- Phase 3: Standalone tests verified: high-volume continuous (ets_fallback wins) + low-volume intermittent (croston correctly selected)
- Phase 3: `requirements.txt` and `README.md` added

## 2026-04-13 (Phase 2)

- Phase 2: Created `promotions`, `promotion_skus`, `regional_holidays` table migrations
- Phase 2: Created `Promotion` model (TenantScope, soft deletes, BelongsToMany skus)
- Phase 2: Created `RegionalHoliday` model (shared reference, no tenant scope)
- Phase 2: Created `RegionalHolidaySeeder` — Saudi public holidays for current year with uplift %
- Phase 2: Added `RegionalHolidaySeeder` to `DatabaseSeeder`
- Phase 2: Created `PromotionController` (index, store, update, destroy)
- Phase 2: Created `StorePromotionRequest` + `UpdatePromotionRequest` (uplift 0–500 validation)
- Phase 2: Added `/promotions` routes (GET, POST, PATCH, DELETE) under auth middleware
- Phase 2: Created `Promotions/Index.vue` — table + Teleport dialog for create/edit, SKU multi-select
- Phase 2: Added Promotions link to `AppHeader.vue`
- Phase 2: Added EN + AR i18n keys for promotions section
- Phase 2: 6 new feature tests — 48/48 tests passing (220 assertions)
- Note: shadcn-vue not installed; dialog uses existing Teleport/Tailwind pattern from SkuDetailDialog

## 2026-04-13

- Phase 1: Created `tenants` table migration with name, locale, currency fields
- Phase 1: Added `tenant_id` FK column (default 1) to users, suppliers, skus, sales_history, inventory_decisions, purchase_orders, engine_runs
- Phase 1: Created `TenantScope` — global Eloquent scope filtering queries by `Auth::user()->tenant_id` (fallback: 1)
- Phase 1: Created `Tenant` model
- Phase 1: Applied `TenantScope` + auto `tenant_id` injection on create to all 6 core models
- Phase 1: Updated `SyntheticDataSeeder` to seed default tenant (id=1) and wire all records to tenant_id=1
- Phase 1: Updated User/Sku/Supplier/EngineRun factories to include `tenant_id=1`
- Phase 1: Updated `tests/TestCase.php` to create default tenant before each test run
- Phase 1: All 42 tests passing (192 assertions)

## 2026-04-11
- Add SKU detail popup dialog: clicking any SKU code or name in the catalogue or dashboard opens a snapshot showing inventory position and latest decision metrics

## 2026-03-19

- Task 8: Installed laravel-echo and pusher-js packages
- Task 8: Created resources/js/types.d.ts — extends Window interface with Echo and Pusher for TypeScript
- Task 8: Rewrote bootstrap.js to initialise Laravel Echo with Reverb broadcaster (conditional on VITE_REVERB_APP_KEY)
- Task 8: Created NotificationBell.vue — listens to inventory-alerts private channel, shows dropdown with ORDER NOW alerts, supports clear-all
- Task 8: Created AppHeader.vue — sticky top nav with Dashboard + SKUs links and NotificationBell
- Task 8: Added AppHeader to Dashboard/Index.vue, SKUs/Index.vue, SKUs/Show.vue; removed standalone h1 from Dashboard
- Vite build: ✓ 0 errors — 8.29s build
- All 39 tests still passing

## 2026-03-19

- Task 7: Added lastRun (latest completed EngineRun) and deadStock (SKUs with stock but no sales in 30 days) to DashboardController response
- Task 7: Added abc_class and xyz_class to each decision row in the mapped output
- Task 7: Added 3 new Pest tests (lastRun present, lastRun null, dead stock detection) — all 39 tests passing (164 assertions)
- Task 5: Integrated AbcXyzClassifier into InventoryEngineService — classifier runs before decision loop, safety stock multiplier applied per SKU
- Task 5: Added EngineRun logging to InventoryEngineService — records running/completed/failed status, decisions_count, and duration_ms
- Added AbcXyzClassifier as 6th constructor argument in AppServiceProvider DI wiring
- Extended EngineRunTest with 3 new assertions (EngineRun count, status, decisions_count)
- All 36 tests passing (131 assertions)

## 2026-03-11

- Implemented full Inventory Decision Engine MVP (all 15 tasks complete)
- Phase 1: Supplier, SKU, SalesHistory, InventoryDecision, PurchaseOrder migrations + models + factories
- Phase 1: SyntheticDataSeeder with 2 suppliers, 11 SKUs, 12 months of daily sales, 2 demo users
- Phase 2: DemandForecaster (exponential smoothing + moving average), InventoryPositionTracker, LeadTimeHandler, ConstraintEngine, DecisionScorer service classes with DTOs
- Phase 2: InventoryEngineService orchestrator, RunInventoryEngineJob, StockAlertEvent
- Phase 3: DashboardController (stats + latest decisions), SkuController (index + show), EngineController (dispatches job), auth-guarded web routes
- Phase 4: Dashboard/Index.vue (stats cards + decisions table + Run Engine button), SKUs/Index.vue (catalogue table), SKUs/Show.vue (detail page with decision history + sales history)
- Phase 5: Fortify auth wired (login view, FortifyServiceProvider, Login.vue)
- All 26 tests passing (78 assertions), engine verified end-to-end (11 decisions persisted to DB)
