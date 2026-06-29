# DATA_INGESTION.md — Client Data Onboarding & Sync

> Read this file before touching any importer, connector, or ingestion job.

---

## Overview

Every client onboarding starts with data. Before the engine can produce a single recommendation, the system needs the client's SKUs, suppliers, and historical sales. The ingestion layer handles two realities:

1. **Initial load** — pulling 12+ months of history at onboarding, often from a mix of Shopify and Excel
2. **Ongoing sync** — keeping `sales_history` current once the system is live, ideally from a direct source of truth (Shopify) rather than manual re-upload

**Design principle:** ingestion is a **plug-in system**, not a one-off importer. Every source (CSV upload, Shopify, WooCommerce, Salla, direct DB) is an adapter behind a common interface. This is not speculative — different clients will have different stacks, and rebuilding ingestion for each is not an option.

---

## Scope & Phasing

**Primary — Shopify connector.** The recommended ingestion path for SKUs and sales history. The current client uses Shopify; the connector handles both initial load (24-month order history + full product catalogue) and ongoing hourly sync. Any Shopify-based client follows this path from day one.

**Fallback / supplementary — CSV upload.** Required for supplier data (Shopify does not model suppliers). Also the universal fallback for clients without a supported connector. Onboarding always starts with a CSV supplier import before Shopify sync runs, since SKU→supplier associations cannot be derived from Shopify.

**Future adapters (not built yet).** WooCommerce, Salla, direct MySQL read connections. Interface is designed to accommodate them without refactoring.

**Onboarding sequence for a Shopify client:**
1. Owner imports suppliers via CSV (required first — Shopify has no supplier concept)
2. Owner connects Shopify store in the Ingestion UI — credentials saved, `RunShopifyInitialLoadJob` dispatched
3. Initial load pulls all active products → SKUs, and 24 months of orders → sales history
4. Hourly incremental sync keeps data current from that point forward
5. CSV remains available as an override or correction tool at any time

---

## The `IngestionSource` Interface

All adapters implement a common contract. Architecture teams should not need to touch the orchestration layer when adding a new source — only implement the interface.

```php
interface IngestionSource
{
    public function name(): string;                          // 'csv_upload', 'shopify', etc.
    public function supports(string $entity): bool;          // 'skus', 'suppliers', 'sales_history'
    public function fetch(string $entity, array $options): iterable;  // yields raw rows
    public function naturalKey(string $entity): array;       // columns forming the dedup key
    public function transform(string $entity, array $raw): array;     // raw → canonical schema
}
```

The orchestrator (`DataIngestionService`) accepts an `IngestionSource`, iterates `fetch()` for each requested entity, runs `transform()` per row, validates against the canonical schema, and upserts using the `naturalKey()` to guarantee idempotency. A single method handles CSV, Shopify, or anything added later.

---

## Phase 1 — CSV Upload

### Importers

Three importers, one per entity:

- `SkuImporter` → upserts into `skus`
- `SupplierImporter` → upserts into `suppliers`
- `SalesHistoryImporter` → upserts into `sales_history`

Order matters on first load: suppliers → SKUs → sales history (FK dependencies). The UI enforces this order or runs all three in a single transaction.

### Entry points

**UI upload** — owner role only. `Ingestion/Index.vue` at route `/ingestion`. Upload fields for each importer, template download links, live validation feedback after upload.

**Artisan command** — `php artisan ingestion:csv {entity} {path} [--tenant=1] [--dry-run]`. For engineer use during onboarding and for scripted migrations. `--dry-run` validates and reports without writing.

### Templates

Downloadable CSV templates live at `storage/app/ingestion/templates/`. The client's data team uses these to shape their export. Each template has a header row and one commented example row.

**`skus_template.csv`**
```
sku_code,name,category,supplier_name,moq,unit_cost,current_stock,lead_time_days
FB-001,Nike Grip Socks 8pk,accessory,Acme Distributor,24,45,120,7
```

**`suppliers_template.csv`**
```
name,stated_lead_time_days,notes
Acme Distributor,7,Primary supplier for grip sock lines
```

**`sales_history_template.csv`**
```
sku_code,sale_date,quantity_sold,is_promotion
FB-001,2024-04-01,3,false
FB-001,2024-04-02,2,false
```

`supplier_name` in the SKU template is matched against `suppliers.name` within the tenant. Unknown supplier → validation error, not silent null.

### Validation

Validation is **row-level with full reporting**, not fail-fast. The client's data is rarely clean; failing on row 1 and hiding the other 187 errors wastes their time.

Rules applied per row:

- Required fields present and non-empty
- Types coerceable (`quantity_sold` is integer, `unit_cost` is decimal, dates in ISO-8601)
- FK references resolvable within tenant (`sku_code` exists for sales history rows; `supplier_name` exists for SKU rows)
- Enum values valid (`category` in `{equipment, accessory, bundle}`)
- Sale dates not in the future
- `quantity_sold ≥ 0`
- Per-entity uniqueness: `(tenant_id, sku_code)` for SKUs, `(tenant_id, sku_id, sale_date)` for sales history

Output: a validation report with per-row errors, returned to the UI as a table and stored in `data_ingestion_runs.error_log`. The user sees exactly which rows failed and why, can fix the CSV, and re-upload.

Default behaviour on validation errors: **import valid rows, reject invalid ones, return the report.** The user can choose "strict mode" which rejects the whole file if any row fails — useful for onboarding where partial data is worse than no data.

### Idempotency

Every importer uses a natural key for upsert, not auto-increment IDs:

| Entity | Natural key |
|---|---|
| `skus` | `(tenant_id, sku_code)` |
| `suppliers` | `(tenant_id, name)` |
| `sales_history` | `(tenant_id, sku_id, sale_date)` |

Re-uploading the same file produces the same database state. This matters for two reasons: clients often re-upload after fixing errors, and sales history may be exported with overlapping date ranges. The system must not double-count.

Multiple sales on the same SKU on the same date are **summed** into a single row per `(sku_id, sale_date)`. If the client's export has one row per order line, they'll produce multiple rows per SKU per day — the importer aggregates during transform.

### Transactional behaviour

Each importer runs inside a DB transaction. If the transformation step raises an unhandled exception mid-file, the whole upload rolls back and the run status is `failed`. Validation errors are not exceptions — they're logged and skipped, and the run status is `partial`.

---

## Phase 2 — Shopify Connector

### Responsibilities

- Pull products from Shopify → transform → upsert into `skus`
- Pull orders from Shopify → transform line items → upsert into `sales_history`
- Track incremental sync state so we don't re-pull 12 months every hour

### Credentials

Stored per tenant in a new table:

```sql
ingestion_credentials
  id, tenant_id, source (enum: shopify / woocommerce / salla / ...)
  credentials (json, encrypted at rest via Laravel's `encrypted` cast)
  connected_at, last_sync_at, last_sync_cursor (string nullable)
  is_active (boolean)
```

For Shopify the `credentials` payload holds shop domain and admin API access token (or app installation token depending on auth flow). Credentials are **never** logged, never returned in API responses after initial save, never surfaced in error output.

### Initial load vs incremental sync

**Initial load** (first connection): pull all active products, then pull orders back to a configurable start date (default: 24 months ago). This is a heavy, one-time job. Runs on the `forecasting` queue (long timeout) to avoid blocking the `inventory` queue.

**Incremental sync** (scheduled hourly per tenant with active Shopify credentials): uses Shopify's `updated_at_min` cursor. The last successful cursor is stored on `ingestion_credentials.last_sync_cursor`. Sync pulls only rows updated since that cursor, advancing it on success.

Hourly cadence is the default. Configurable per tenant in `ingestion_credentials` if a client needs near-real-time (every 5 min) or less frequent (daily).

### Entity mapping

**Shopify product → `skus`:**
- Shopify variant `sku` field → `sku_code`
- Variant `title` or product `title` → `name`
- Product `product_type` → `category` (requires mapping rules; defaults to `accessory`)
- Variant `inventory_quantity` → `current_stock`
- Supplier is **not** mapped from Shopify — Shopify doesn't model suppliers cleanly. Supplier must be set via CSV or manual UI entry, then joined to the SKU by `sku_code` at import time.

**Shopify order line item → `sales_history`:**
- Order `created_at` date (tenant timezone) → `sale_date`
- Line item `quantity` → `quantity_sold`
- Variant `sku` → `sku_id` lookup via `skus.sku_code`
- `is_promotion` derived from presence of discount codes on the order (`line_item.discount_allocations`) OR by cross-referencing against the `promotions` table for the order date
- Refunded quantities subtracted in a follow-up pass

### Deduplication

Shopify orders can change (edits, refunds). Rely on the `sales_history` natural key — `(tenant_id, sku_id, sale_date)` — and re-upsert the aggregated quantity on each sync. Store `line_item_id` on each source row temporarily during transform to aggregate correctly per date, but the canonical dedup stays at the date level.

For downstream auditability, a separate append-only `sales_history_source_log` table captures each Shopify line item with its ID, allowing reconciliation if numbers look off later. This is optional — implement only if a client reports mismatches.

### SKU code mismatches

A recurring onboarding issue: the client's Shopify SKU codes don't match their internal SKU codes (the ones on their supplier POs). The connector does not try to solve this silently.

Workflow:
1. Run Shopify sync; unmapped SKU codes land in a `unmapped_sku_codes` table per run
2. UI surfaces unmapped codes to the owner with a "map to existing SKU" or "create new SKU" action
3. Once mapped, the mapping is stored in `sku_external_mappings` (natural key: `tenant_id, source, external_code`) and applied on subsequent syncs

### Rate limits

Shopify's Admin API has well-documented rate limits (40 requests/sec bucket for Plus, 2/sec for standard). The connector uses an exponential backoff on 429 responses and respects `X-Shopify-Shop-Api-Call-Limit` headers. Worker memory must accommodate pagination state (cursor-based, max 250 items per page).

---

## Observability: `data_ingestion_runs`

Every ingestion run — CSV or connector — writes a row to `data_ingestion_runs`:

```sql
data_ingestion_runs
  id, tenant_id
  source (enum: csv_upload / shopify / woocommerce / salla / manual)
  importer (string — 'SkuImporter', 'ShopifySalesSync', etc.)
  status (enum: running / completed / failed / partial)
  rows_processed, rows_succeeded, rows_failed
  error_log (json — per-row errors for partial runs)
  metadata (json — file name, connector cursor before/after, etc.)
  started_at, completed_at, duration_ms
```

This table parallels `engine_runs` deliberately. Both are operational logs; both surface to engineers when something fails. `status = partial` means some rows succeeded and some failed — the expected state for messy CSV uploads.

No UI for this table yet. Engineer tooling only (artisan command `php artisan ingestion:runs --tenant=1 --recent=10`). A lightweight UI can come later if ingestion failures become frequent enough to matter operationally.

---

## Jobs & Scheduling

**`RunCsvImportJob`** — dispatched by the UI upload flow. Queue: `default`. Handles file parsing, validation, transformation, upsert. Deletes the uploaded file from `storage/app/ingestion/uploads/{tenant_id}/{uuid}.csv` on completion (success or failure).

**`RunShopifyInitialLoadJob`** — dispatched on first Shopify connection. Queue: `forecasting` (long-running). Chunked internally so it can resume on failure (checkpoint in `last_sync_cursor`).

**`RunShopifyIncrementalSyncJob`** — dispatched hourly by scheduler for each tenant with active Shopify credentials. Queue: `default`. Short-lived; skips cleanly if no updates since last cursor.

**`ingestion:cleanup-uploads`** — scheduled daily. Removes orphan files older than 24h from `storage/app/ingestion/uploads/`.

**Scheduler registration** in `routes/console.php`:
```php
Schedule::job(new RunShopifyIncrementalSyncJob($tenant))->hourly();
Schedule::command('ingestion:cleanup-uploads')->daily();
```

---

## Interaction with the Engine

Ingestion and the decision engine are loosely coupled. The engine reads from `sales_history` and `skus`; it does not know or care how data got there. Three integration points:

1. **New SKU detection** — when `SkuImporter` creates a new SKU, `SkuObserver` fires and dispatches `RunForecastJob` with `reeval_trigger = 'new_sku'`. Works identically for CSV and Shopify.

2. **Material history change** — when an ingestion run for `sales_history` inserts or updates rows affecting the trailing 90 days, the affected SKUs are queued for forecast re-evaluation with `reeval_trigger = 'bias_drift'` (close enough to drift semantically; no new trigger needed). A threshold prevents tiny corrections from triggering full re-runs — only runs with > 5% of trailing-90-day rows changed.

3. **Stock reconciliation** — Shopify `inventory_quantity` is authoritative for `skus.current_stock`. When the Shopify connector updates stock, this overrides whatever the `RECEIVED` status transitions have tracked. The audit trail notes "stock reconciled via Shopify sync" in the SKU history.

---

## Security

- **Uploaded file paths** constructed from UUIDs only. Path validation guards resolve the final path under `storage/app/ingestion/uploads/{tenant_id}/` before read.
- **Credentials** encrypted at rest via `encrypted` cast. Never returned in API responses after initial save. Owner-only write access.
- **Rate limits** on CSV upload endpoint: 10/hour/tenant (oversized files should be chunked by the importer, not uploaded repeatedly).
- **File size limit** on CSV upload: 50 MB default, configurable. Sales history files can be large; use artisan command for files above this limit.
- **No auto-approve** on Shopify credential save. The owner must confirm the connection, and the first sync only runs after explicit confirmation in the UI.
- **Shopify scope** requested at install: minimum necessary. `read_products`, `read_inventory`, `read_orders`. Do not request write scopes.

---

## Testing

- Unit tests per importer with known-good CSVs, known-bad CSVs, and mixed files
- Integration tests for the `IngestionSource` interface using a `FakeIngestionSource`
- Contract tests for the Shopify connector using recorded API fixtures (never live API in CI)
- Idempotency tests: run the same import twice, assert no duplicate rows
- Tenant isolation tests: import into tenant 1, assert tenant 2 sees nothing

All following Pest syntax per project convention.

---

## Known Limitations

1. **No partial-column CSV support.** The importer expects all template columns. Missing columns = validation error. Adding "optional columns" is a Phase 3 concern.
2. **No multi-currency on import.** Unit costs assumed to be in the tenant's currency (SAR for current client). Conversion is not handled.
3. **No historical stock reconstruction from orders.** We cannot back-compute `current_stock` values for past dates — only the current snapshot. Lead time analysis uses `ORDERED → RECEIVED` timestamps on `inventory_decisions`, which only exist forward from system go-live.
4. **Shopify refunds handled simplistically.** Full refund = subtract from that date's quantity. Partial refunds of quantity are handled; partial refunds by amount (without quantity reduction) are ignored.
5. **No image, description, or pricing history import.** Only the fields the engine actually uses. Scope stays tight.
