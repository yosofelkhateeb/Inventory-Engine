# TODO_PRE_PRODUCTION.md — Deferred Work Tracker

> This file exists so deferred work doesn't disappear. Every item here is a real concern that was intentionally postponed, not a nice-to-have. Before any production client goes live, walk this list.

---

## How to Use This File

- Items are organised by concern area, not by priority. Priority is set at the time of triage, not at the time of documentation.
- Each item has a **trigger** — the condition under which it must be addressed.
- Each item has a **scope note** — what's in and out when we eventually tackle it.
- Do not implement items from here preemptively. They're deferred for a reason. Pull them into active work only when the trigger fires.

---

## Delivery Sequence (set 2026-06-03)

The project flows through three sequential phases. Everything in this file gates on Phase C — Phases A and B do not require any item below.

**Phase A — Partner Share (current).** Hand the working tool and the system guidebook to the partner for review. They evaluate whether it's ready to present to the client. *No items from this file are needed here.* The deliverables already exist: the running app, the 30-SKU SEA synthetic dataset, the docs index, and the system guidebook.

**Phase B — Client Demo (after partner sign-off).** Walk the client through the product on synthetic data. *No items from this file are needed here either*, though a Phase A review may surface targeted polish/demo-prep items that get added on top. Outcome of the demo is binary: client requests further changes (loop) OR approves and we move to Phase C.

**Phase C — Deployment & Delivery (after client approval).** This is when every item below comes into scope. Triggers stay as written; the umbrella condition is "client has approved and we are preparing to deploy."

**Implication for this list:** until the client approves, nothing here is active work. Any change requests that surface during Phase A or Phase B are handled reactively as feature/fix work, not from this tracker.

---

## 1. Failure UX

**Trigger:** before first external user uses the deployed system.

**Current state:** failures are invisible to end users. Horizon failures show up in the Horizon dashboard (engineer-only). Python subprocess failures land in `storage/logs/laravel.log`. Queue backlogs are undetectable from the UI.

**Scope when addressed:**
- In-app system status indicator on the dashboard (green/amber/red) showing overall engine health: last engine run recency, forecasting queue depth, last successful forecast run
- Horizon failure notification channel — route failed jobs to Slack/email for the engineering team (not the client)
- Python subprocess failure surfacing — when `RunForecastJob` fails all 3 retries for an SKU, flag it on that SKU's detail page with "forecast stale, engineer attention required" rather than silently falling back to moving average
- Degraded-mode banner when `forecasting` queue depth > threshold — informs the user that recommendations are still being produced but with fallback forecasting
- Error boundary on the Inertia layer — graceful page for 500s instead of the default Laravel error page

**Out of scope (don't scope creep):**
- Full observability/APM integration (Datadog, Sentry) — that's infra-team territory
- Automated remediation (auto-retrying failed jobs beyond the 3 retries already configured)

---

## 2. Backup Strategy

**Trigger:** before first production tenant contains real client data.

**Current state:** no backups. Development MySQL is local and disposable.

**Scope when addressed:**
- MySQL nightly dump to object storage (S3-compatible, whatever the deployment team uses). 30-day retention minimum. Weekly full + daily incremental preferred over daily full.
- `storage/app/forecasting/reports/` archival — not critical for recovery but useful for historical drift analysis. Monthly tarball to cold storage, 12-month retention.
- `forecast_model_registry` point-in-time recovery strategy — the table is small and recoverable from the DB backup, but also dumped separately weekly as a JSON export for fast comparison across model versions. Engineers-only, stored alongside reports.
- Documented restore procedure — a runbook that's been tested end-to-end on a staging environment. An untested restore procedure is not a backup strategy.
- Backup monitoring — alert when a scheduled backup fails, not just when it succeeds silently.

**Out of scope:**
- Multi-region backup replication. Single-region is fine for current scale.
- Real-time replication / hot standby. The RPO (recovery point objective) for this system is ≥ 24 hours; nightly is sufficient.

---

## 3. Scale Testing

**Trigger:** before onboarding a client with > 100 SKUs, OR before onboarding a second tenant.

**Current state:** verified end-to-end at 30 SKUs. Every component runs in seconds. Unknown behaviour at 100+ SKUs.

**Scope when addressed:**
- Generate synthetic datasets at 100, 500, and 1000 SKUs with realistic demand patterns
- Benchmark a full engine run end-to-end at each scale; record duration, memory, query count
- Benchmark the monthly `forecast:sweep` at each scale — this is the heaviest workload in the system; verify it completes within a reasonable window and doesn't starve the `inventory` queue
- Profile the Python pipeline for bottlenecks at 1000 SKUs; the per-SKU cost is probably fine, but sequential execution across 1000 SKUs is the concern. Parallelisation options:
  - Multiple `forecasting` queue workers (simplest; horizontal)
  - Batched Python invocations (one subprocess processing N SKUs) — changes the `RunForecastJob` contract
- Tenant isolation under load — verify that one tenant's engine run doesn't starve another's queue slots; may require per-tenant concurrency caps beyond the forecasting-only cap already in place
- Database query analysis — run `EXPLAIN` on the heavy queries (dashboard metrics, reports page, engine-run aggregations) at 1000-SKU scale; add indexes where needed

**Out of scope:**
- Load testing the web tier (concurrent user simulation). Current architecture is a decision engine, not a high-traffic public product. Dashboard and reports endpoints will not see > 10 concurrent users per tenant in any realistic scenario.

---

## 4. Security Final Pass

**Trigger:** before first production deployment.

**Current state:** security baseline is documented in `docs/ARCHITECTURE.md` → Security Baseline. That baseline is intentionally minimum-viable. A real pass has not happened.

**Scope when addressed:**
- Third-party audit OR internal red-team pass, whichever is practical given budget. Scope: authentication, authorization, mass assignment, SQL injection surface, XSS in Inertia-rendered content, CSRF coverage, rate-limit effectiveness, file upload handling, tenant isolation.
- Dependency audit — `composer audit`, `npm audit`, `pip-audit` on the Python venv. All high/critical advisories addressed before deploy. Automated re-run monthly.
- Tenant isolation test suite — property-based tests that attempt every tenant-boundary crossing we can think of. These should be separate from feature tests and run in CI.
- Secrets scan — ensure no credentials, tokens, or keys are in git history. Run a tool like `gitleaks`.
- Session and auth review — session lifetime, refresh behaviour, logout cleanup, multi-device session handling
- File upload handling audit — CSV importer specifically: path traversal, zip-bomb equivalents (extremely large CSVs claiming small uncompressed size), filename sanitisation
- Python subprocess input — verify the schema validation in `main.py` is airtight. Malformed JSON or adversarial field values must fail fast, not crash the interpreter in a way that leaks stack traces to Laravel logs.
- Logging review — confirm no PII, credentials, or full payloads end up in logs. Especially important around Shopify credential handling and CSV import error logs.

**Out of scope:**
- Formal compliance certifications (SOC 2, ISO 27001). Out of scope for a small consultancy engagement; revisit only if a client requires it.

---

## 5. Operational Runbook

**Trigger:** before handoff to the partner for deployment.

**Current state:** operational knowledge lives in the developer's head.

**Scope when addressed:** a short runbook (one page per topic, not a thesis) covering:
- How to restart each process (Horizon, Reverb, scheduler) and what signals user-visible degradation
- How to check why the engine didn't run (scheduler → job → worker chain)
- How to check why a forecast didn't update (registry query, RunForecastJob log, Python stderr)
- How to manually re-run a forecast for a specific SKU
- How to investigate a client report of "wrong recommendation" (decision reasoning JSON, forecast registry, sales history for the SKU, constraints applied)
- How to onboard a new tenant (seeder, settings, credentials, first engine run)
- How to roll back a deploy (migrations are forward-only by default; document what's safe to revert and what requires a DB rollback)

**Out of scope:**
- Full documentation of every function and service. Code is documentation for code-level concerns; the runbook is for operational concerns only.

---

## 6. Data Retention and Deletion

**Trigger:** when a client asks, or before a tenant offboarding ever happens, whichever comes first.

**Current state:** no deletion policy. Soft-deletes exist on `skus`, `suppliers`, `purchase_orders` (legacy — now unused). No scheduled cleanup of anything except temp files.

**Scope when addressed:**
- Define retention policy per table. `engine_runs` and `inventory_decisions` likely don't need to keep 5 years of history online — archive after N months.
- Define a tenant offboarding procedure — if a tenant leaves, data export (CSV dumps of their data) then hard deletion. Document the procedure and the SLA for completion.
- Review soft-delete usage — decide per entity whether soft-delete is right (SKUs: yes, historical reference) or whether hard delete is right (uploaded CSVs: yes, privacy).
- Consider data subject access requests if any client operates in a jurisdiction that enforces them (unlikely for current client but worth noting).

**Out of scope:**
- GDPR / CCPA compliance work unless a specific client is in scope. Design with the principle in mind (data minimisation, purpose limitation); formal compliance is a separate project.

---

## 7. Monitoring and Alerting

**Trigger:** before first production deployment.

**Current state:** no monitoring beyond Horizon's built-in dashboard.

**Scope when addressed:**
- Application metrics — at minimum: engine run success rate, engine run duration, forecasting queue depth, forecast job success rate, average forecast MAE trend (per-tenant)
- Alert on failure conditions — engine run failed, forecasting job failed 3× for the same SKU, queue depth exceeds threshold, scheduled command didn't run in expected window
- Alert on business conditions — WMAPE trending up across a tenant (could indicate data quality degradation), SUPERSEDED rate spiking for an SKU (engine instability signal, see feedback loop infrastructure)
- Choose a monitoring tool — infra-team decision, but the application needs to emit metrics in whatever format the tool expects (Prometheus format is a safe default)

**Out of scope:**
- User behaviour analytics. Not a growth-metrics product.

---

## 8. Frontend Polish

**Trigger:** deferred indefinitely. The client is not being presented to in the near term, and the developer's focus is backend architecture.

**Current state:** pages function. UX is not refined.

**Scope when addressed:**
- Forecast panel on SKU detail page — interval rendering, plain-language summary, confidence badge
- Dashboard WMAPE card refinement
- Settings page — threshold editor for per-category forecasting config (this is the one functional blocker in this list; without it, every threshold change is an engineering task)
- Recommendation status lifecycle UI — status transitions, action buttons per status, history timeline
- Loading states, empty states, error states across all pages
- Keyboard navigation, accessibility pass

**Out of scope until explicitly in scope:**
- Full visual redesign
- Mobile-responsive layouts (desktop-first is fine for an internal warehouse/owner tool)

---

## 9. Forecast Pipeline Performance

**Trigger:** before any client with a real catalogue runs a scheduled sweep. This is a functional blocker, not polish — at current speeds a 30-SKU sweep takes ~5 hours and most SKUs exceed the shipped process timeout.

**Current state:** measured 2026-07-29 on a 4-core laptop. SKUs take 8–32 minutes each; SARIMAX accounts for 50–80% of every run because `auto_arima` re-selects the model order on all 5 CV folds plus holdout plus final fit (~7 full order searches per SKU). The default `FORECAST_PROCESS_TIMEOUT` of 600s is below what that needs, and the timeout path in `RunForecastJob` throws before reaching its structured error logging — so failures leave no log line, no registry row, and no marker on the SKU. 27 of 30 SKUs were failing invisibly.

**Scope when addressed:** see the full evidence-backed plan in [`plans/2026-07-31-forecast-performance-optimisation.md`](plans/2026-07-31-forecast-performance-optimisation.md). Summary:
- Make timeout failures visible (overlaps §1 Failure UX — the per-SKU "forecast stale" flag)
- Cache the ARIMA order across CV folds — select on the earliest fold only, refit coefficients per fold. ~5-7x, no leakage
- Per-candidate time budgets so a slow model degrades gracefully instead of killing the whole SKU
- Skip seasonal order search where the classifier found no seasonality
- Size `forecasting` queue worker concurrency (the pipeline is single-threaded; sweep throughput scales near-linearly)
- Re-size the timeout default from measurements *after* the above, and document the hardware assumption

**Out of scope:** dropping models from the candidate shortlist. The shortlist already averages ~2 candidates, and SARIMAX — the expensive one — wins 12 of 30 SKUs. The plan documents why this and two other tempting shortcuts were rejected.

---

## Items Not on This List

If you think of something that should be deferred and tracked, add it here. The list is deliberately terse — a single-line "we should think about X" is better than nothing and better than a multi-paragraph speculative design.

**Not yet added but likely to come up:**
- Webhooks for external integrations (client's accounting software wanting to know when stock is received)
- Multi-warehouse support (known limitation today)
- Multi-currency (known limitation today)
- API for third-party tools to read recommendations (no client has asked)
