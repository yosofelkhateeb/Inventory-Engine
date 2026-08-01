# Forecast Pipeline Performance — Optimisation Plan

**Status:** Analysis complete, implementation deferred. No pipeline code has been changed.
**Audience:** whoever implements this on the production product. This demo repo is the
evidence base, not the delivery target.
**Measured:** 2026-07-29, full 30-SKU sweep on a 4-core / 8-thread i7-8550U laptop.

---

## Problem statement

A full 30-SKU forecast sweep took roughly 5 hours sequentially. Individual SKUs took
**8 to 32 minutes**. The shipped default `FORECAST_PROCESS_TIMEOUT` is 600s, which is
below what most SKUs need on this class of hardware, so **27 of 30 SKUs were failing** —
and failing *silently*.

That is not viable for a real business. A daily replenishment cycle cannot depend on a
forecasting stage that takes hours and fails invisibly.

Two separate defects are tangled here:

1. **Performance** — the pipeline is far slower than it needs to be.
2. **Failure handling** — when it exceeds the timeout, nothing is recorded anywhere.

They should be fixed independently. The failure handling is the more serious of the two.

---

## How the pipeline actually costs what it costs

Per SKU, per run. The relevant stages are 6, 7 and 13 of the 15-stage pipeline.

### Step 1 — the classifier shortlists candidates

`python/forecasting/classifier.py:20-46` (`_get_candidate_shortlist`) returns **2, sometimes
3 candidates** based on the demand profile. It is never all six:

| Profile | Candidates |
|---|---|
| intermittent | `croston`, `ets_fallback` |
| low volume | `holt_winters` + (`prophet` if seasonal, else `ets_fallback`) |
| med/high volume, stable | `holt_winters`, `sarimax` (+ `prophet` if seasonal) |
| med/high volume, moderate/erratic | `sarimax`, `lightgbm` (+ `prophet` if seasonal) |

**There is no "runs 6 models per SKU" waste to eliminate.** That was the initial hypothesis
and the code does not support it.

### Step 2 — each candidate is fit 7 times

`python/forecasting/pipeline/validation.py` creates `cv_folds: 5` walk-forward folds
(`config/forecasting_config.yaml:13`), plus a 30-day holdout untouched by any fold.

`python/forecasting/evaluator.py:200` (`_fresh_model`) deliberately constructs a **new
unfitted instance for every fold**. Its docstring is explicit about why: reusing a fitted
model would train folds on different data than the production fit and contaminate
candidate selection.

So per candidate: **5 CV fits + 1 holdout fit + 1 final refit on full history = 7 full fits.**

### Step 3 — for SARIMAX, one "fit" is an entire order search

`python/forecasting/models/sarimax.py:47` calls `pm.auto_arima(seasonal=True, m=7,
max_p=3, max_q=3, max_P=1, max_Q=1, stepwise=True)`.

`stepwise=True` is already the fast path — this is *not* an exhaustive-grid bug, and
`m=7` (weekly) is correct, not a pathological `m=365`. But a stepwise search still fits
dozens of candidate ARIMA models by maximum likelihood over ~900 daily observations.

**SARIMAX therefore costs roughly 7 complete order searches per SKU.** That is the entire
performance story.

---

## Measured evidence

Per-model runtimes, extracted from `selection_rationale` across the swept SKUs:

| Model | Runtime per SKU | SKUs won |
|---|---|---|
| **sarimax** | **462.5 – 1376.1s** | 12 |
| lightgbm | 48.7 – 52.7s | 2 |
| holt_winters | under the 30s warn threshold | 11 |
| ets_fallback | under the 30s warn threshold | 5 |

SARIMAX is **10–25x slower than the next-slowest candidate** and accounts for **50–80% of
each SKU's total wall time**.

Demand profiles across the 30 SKUs:

| seasonality_detected | count |
|---|---|
| yes | 23 |
| no | 7 |

> Runtimes were measured with 5 sweep workers competing for 4 physical cores, so each
> process ran at roughly 0.6-0.8 of full speed. Single-run figures will be lower. The
> *ratios* between models are unaffected.

---

## What NOT to do

These were considered and rejected on the evidence. Documented so they don't get
re-proposed.

**Do not drop models from the shortlist.** SARIMAX — the expensive one — **wins 12 of 30
SKUs**, more than any other model. Removing it means materially worse forecasts for 40% of
the catalogue. The shortlist already averages ~2 candidates. There is no fat here.

**Do not select the ARIMA order once on the full series.** This is the obvious ~7x
speedup and it is wrong: the order would be chosen using data from later folds, leaking
future information into cross-validation and inflating the CV metrics that drive model
selection. `_fresh_model()` exists specifically to prevent this class of contamination.
See the correct version in Fix A below.

**Do not reduce `cv_folds` from 5 to 3.** Linear saving across all models, but it buys
speed by weakening the validation that justifies the model selection. Wrong trade for a
product whose selling point is rigour. (`min_cv_folds: 3` already exists as a floor for
short series — that's a different mechanism and should stay.)

**One genuine deletion candidate: Prophet.** It appears in three shortlist branches
(`classifier.py:31,37,42`) but is an optional dependency that is not installed. It never
competes, so it contributes nothing while implying a capability that cannot be
demonstrated. Either install and validate it, or remove it from the shortlists.

---

## Fixes, in priority order

### Fix A — cache the ARIMA order across folds

**The dominant win. ~5-7x on the largest cost, with no statistical compromise.**

Select the ARIMA order **on the earliest training fold only**, then reuse that fixed order
for subsequent folds, refitting only the *coefficients* each time.

This is not the leaky version. The order is derived exclusively from data available at the
earliest point in the walk-forward sequence, so no fold is ever informed by its own future.
It is standard practice in production forecasting systems.

- **Touches:** `models/sarimax.py` (accept an optional pre-selected order),
  `evaluator.py` (thread the order through the fold loop).
- **Expected:** SARIMAX per-SKU from ~7 order searches to 1, plus 6 cheap coefficient refits.
- **Risk:** medium. Changes what CV measures.
- **Verification:** on a sample of SKUs spanning all four profiles, confirm the selected
  order and CV MAE are unchanged or explainably close versus the current implementation
  before rollout. If order selection is unstable across folds for a given SKU, that is
  itself a signal worth logging.

### Fix B — per-candidate time budget with graceful degradation

**The fix that makes the system operationally safe.**

Today, one slow candidate blows the whole-SKU process timeout and the SKU gets **no
forecast at all**. A budget per candidate inverts this: if SARIMAX exceeds its allowance,
abandon that candidate, record why, and select from the ones that finished.

The result is a slightly worse forecast instead of no forecast. That is almost always the
right trade in a replenishment system, where a stale demand rate silently degrades every
downstream recommendation.

- **Touches:** `evaluator.py` (budget per candidate), `main.py` (record abandoned
  candidates in `warnings` and `selection_rationale`).
- **Expected:** bounded worst-case runtime per SKU; catastrophic failure becomes
  graceful degradation.
- **Risk:** low. Purely additive — a candidate that finishes is treated exactly as today.
- **Config:** budget belongs in `forecasting_config.yaml`, not hardcoded.

### Fix C — skip the seasonal search when the classifier found no seasonality

`sarimax.py:47` passes `seasonal=True` unconditionally, even though the classifier has
already determined `seasonality_detected` and stored it on the profile. Seasonal order
search is substantially more expensive than non-seasonal.

- **Expected:** helps the 7 of 30 SKUs with no detected seasonality (~23% of this
  catalogue). Proportion will vary by client.
- **Risk:** low, and principled — it honours a determination the pipeline already made.
- **Note:** confirm the classifier's ACF-at-lag-7 test (`classifier.py:133-141`) is
  trustworthy for the client's data before gating expensive work on it. It requires
  `history_days >= seasonality_min_days` (default 365) or seasonality is reported as
  `False` by default, which would incorrectly gate off SARIMAX for short-history SKUs.
  **Gate on `seasonality_detected == False AND history_days >= seasonality_min_days`**,
  so "not enough history to tell" is not treated as "not seasonal".

### Fix D — realistic timeout default and non-silent failures

Two separate changes:

**D1 — make timeout failures visible.** `RunForecastJob.php:135` sets the process timeout.
`Process::timeout()` raises `ProcessTimedOutException` **before** control reaches the
`if (! $result->successful())` block at line 141, so the structured `logger()->error(...)`
never runs. A timeout currently produces: no log line, no registry row, no marker on the
SKU. The Reports page just renders fewer rows.

This is how 27 of 30 SKUs went missing without anyone noticing. Wrap the call, catch the
timeout explicitly, log it with SKU context, and surface it.

It matters more than it first looks: `DemandForecaster` falls back to a weighted moving
average when the registry has no row, so the system keeps emitting recommendations with
quantities at full confidence, driven by the fallback instead of the selected model. The
dashboard looks normal. A client can order against silently degraded forecasts for weeks.

**This item is tracked as the highest priority in `TODO_PRE_PRODUCTION.md` §1 and should be
fixed independently of — and before — everything else in this plan.** Speeding the pipeline
up reduces how often timeouts occur; it does not make them visible when they do.

**D2 — raise the default.** 600s is below the observed requirement on modest hardware.
After Fix A the realistic ceiling drops sharply, so **set this after A lands**, sized from
measurements rather than guessed. Whatever the number, document the hardware assumption in
`DEPLOYMENT_PREREQUISITES.md` — a default that silently fails on a small box is a bad
default regardless of value.

### Fix E — cross-SKU parallelism

The pipeline is effectively single-threaded (measured: 612s CPU over 594s wall, ratio ~1.0).
Sweep throughput scales close to linearly with worker count.

Production already has Horizon for this; the demo deploy runs `QUEUE_CONNECTION=sync`,
which is why the sweep was serial. Sharding across 5 workers cut ~5 hours to ~90 minutes
with no code changes at all.

- **Action:** ensure the `forecasting` queue has adequate worker concurrency configured,
  and that `forecast:sweep` dispatches are actually processed in parallel.
- **Risk:** low, but note the sweep is I/O-light and CPU-heavy — size workers to physical
  cores, not to an arbitrary number, or contention eats the gain.

---

## Suggested sequencing

1. **D1** (visible failures) — independent, low risk, fixes the most dangerous defect.
2. **E** (parallelism) — configuration, no code, immediate multiplier.
3. **B** (time budgets) — bounds worst case, additive, low risk.
4. **A** (order caching) — the big win, needs the most careful verification.
5. **C** (seasonality gate) — small, do it alongside A since both touch `sarimax.py`.
6. **D2** (timeout default) — last, sized from post-A measurements.

Combined, A + B + E should bring a 30-SKU sweep from ~5 hours to well under 15 minutes on
comparable hardware, with no reduction in validation rigour.

---

## Open questions for the production build

- What is the real catalogue size? All figures here are for 30 SKUs. Cost is linear per
  SKU, so a 1000-SKU client needs Fix E sized accordingly and probably a re-think of sweep
  cadence. See `TODO_PRE_PRODUCTION.md` §3 Scale Testing.
- What forecast freshness does the business actually require? If a SKU's demand rate can
  be a week old without harming decisions, the sweep can be spread across days rather than
  run as a batch — which changes the priority of all of this.
- Is Prophet wanted? If yes, install and validate it. If no, remove it from the shortlists.
