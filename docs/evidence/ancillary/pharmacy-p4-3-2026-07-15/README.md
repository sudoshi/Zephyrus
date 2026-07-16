# Pharmacy P4-3 Queue and Stockout Forecast Evidence

| Field | Evidence |
| --- | --- |
| Gate | P4-3 — Add Pharmacy queue and stockout forecasts |
| Date | 2026-07-15 |
| Branch | `agent/ancillary-radiology-foundation` |
| Model artifact | `config/pharmacy/forecast_model.json` |
| Calibration data | Deterministic synthetic demo history only |
| Activation | Opt-in browser/API planning aid; off by default |
| Production | Not accessed, mutated, activated, migrated, or deployed |
| Result | PASS — no accepted forecast, privacy, freshness, accessibility, baseline, or observed-state failure |

## Outcome

P4-3 adds two distinct server-owned Pharmacy planning forecasts without creating a composite risk score:

- An eight-hour hourly verification-queue depth series based on the current observed queue, historical hour-of-week arrivals and exits, recent net trend, and medication demand whose `due_at` was already known at prediction time.
- A six-hour station/medication stockout probability available only when a structurally valid inventory snapshot provides current or tolerably stale on-hand and par evidence.

Both forecasts are synthetic, non-clinical, and off by default. They are returned under a separate `planningForecast` contract and never replace observed queue depth, SLA state, station rates, open stockouts, shortage flags, Cockpit state, readiness, alerts, or operational sorting.

## Committed backtest evidence

The versioned artifact `rx-forecast-2026.07.15-synthetic-v1` carries separate targets, models, train/evaluation windows, metrics, and baselines.

| Target | Evaluation evidence | Winner rule |
| --- | --- | --- |
| Verification queue depth | MAE 0.2373; RMSE 0.2916; WAPE 0.001 | MAE and RMSE are both lower than the hour-of-week baseline (MAE 1.1305; RMSE 1.3379) and last-value baseline (MAE 1.2886; RMSE 1.5713) |
| Station/medication stockout within six hours | AUC 0.8978; Brier 0.0762; calibration error 0.037 | Brier is lower than the base-rate baseline 0.14 and AUC is higher than 0.5 |

The deterministic 42-day queue cohort and 900-row stockout cohort use a 70/30 time split. Queue training ends before evaluation begins (`2026-07-02T20:00:00Z` then `2026-07-02T21:00:00Z`); stockout training ends before evaluation begins (`2026-07-04T05:00:00Z` then `2026-07-04T06:00:00Z`). `php artisan pharmacy:build-forecast-model` regenerates the artifact and refuses publication unless both winner rules pass.

## Inventory and evidence honesty

The optional inventory snapshot lives under `adc_stations.metadata.inventory`, keyed by governed local medication code. Each usable entry requires nonnegative `on_hand`, positive `par_level`, and a nonfuture `captured_at`; malformed or negative entries are rejected.

The service keeps evidence states explicit:

- Current valid snapshot: calibrated probability, text band, drivers, cutoff, and station/medication identity.
- Tolerably stale snapshot: probability retained as `low_confidence`, with the cutoff and `inventory_snapshot_stale` signal visible.
- Missing, invalid, or unusably stale snapshot: null probability and band; only the separately named velocity-pressure context is returned.
- Current open stockout: preserved as an observed operational fact with null predicted probability and band.
- Unknown local medication: terminology remains `unmapped_local`; the service never invents an RxNorm/NDC mapping.

The deterministic demo includes current covered positions, one observed open stockout, one stale inventory position, and one velocity-only station so every browser state remains reproducible.

## Privacy and authority boundaries

`PharmacyForecastService` is the sole forecast authority. React validates and renders strict Zod contracts; it does not recompute probabilities, bands, thresholds, or coverage.

The frozen station/medication feature schema and source/payload tests deny individual and clinical dimensions, including user, staff, pharmacist, technician, nurse, verifier, badge, actor, patient diagnosis/result, protected attributes, controlled-diversion scores, and ranking scores. The browser contract declares station/medication aggregates only and carries no individual identifier or performance output.

No live Pharmacy, ADC, or inventory feed was activated. P4-3 adds no scheduler entry, queue worker, notification, alert, clinical action, writeback, connector, credential, source endpoint, migration, deployment, or production change.

## UI and browser evidence

- `/pharmacy?forecast=1` renders a separate synthetic verification-queue table with current depth, eight horizon points, uncertainty, scheduled-demand contribution, historical rates, model version, calibration/evaluation window, and both-baseline comparison.
- `/pharmacy/dispense?forecast=1` renders a separate station/medication stockout-pressure table with observed, forecast-available, low-confidence, velocity-only, and unavailable semantics; probability-bearing rows sort within their coverage class and nonprobability evidence remains explicit.
- Both pages expose an off-by-default keyboard-focusable toggle, semantic headings/tables, icon-plus-text state labels, responsive containment, and light/dark rendering.
- Chromium smoke passed on desktop/light queue and mobile/dark stockout surfaces with zero browser-console or uncaught page errors.

## Defects found and repaired by the gate

The release pass found two contract defects and repaired them before publication:

- Carbon returns fractional hours for non-hour-aligned timestamps. The queue service initially emitted that float as `historyHours`, which the strict browser schema correctly rejected. The service now floors to an integer whole-hour observation and the feature test asserts the type.
- The complete Vitest run found a stale Radiology Worklist fixture from P4-1 missing the strict `risk`, `requested`, `model`, and row-level `risk` fields. The fixture now represents the off-by-default contract and all 113 frontend files pass.

## Verification matrix

| Gate | Result |
| --- | --- |
| Forecast model + service acceptance | PASS, 14 tests / 395 assertions |
| Focused affected Pharmacy/Cockpit/demo regression | PASS, 29 tests / 69,868 assertions |
| Full Pharmacy regression | PASS, 115 tests / 72,144 assertions / 967.74 s |
| Full Ancillary regression | PASS, 325 tests / 75,492 assertions / 1,036.53 s |
| Forecast artifact build command | PASS; queue beats both baselines and stockout beats base rate |
| Deterministic demo refresh/invariants | PASS, 50 checks / 0 critical failures / 0 warnings; Cockpit published |
| Full Vitest | PASS, 113 files / 470 tests |
| Pharmacy forecast Playwright smoke | PASS, 2 tests |
| TypeScript | PASS, `npx tsc --noEmit` |
| Production frontend build | PASS, 7,914 modules / 44.16 s |
| UI canon | PASS; 104 pre-existing arbitrary-line-height warnings only; raw-palette ratchet at or below 76 |
| Laravel Pint | PASS, 16 changed PHP files |
| Whitespace | PASS, `git diff --check` |

## Accepted warnings and limitations

- The UI-canon check reports 104 pre-existing arbitrary-line-height warnings in untouched Transport pages and exits success; P4-3 adds none.
- Vite reports the existing stale Browserslist database and chunks over 500 kB; the production build exits success.
- Playwright reports the existing `NO_COLOR`/`FORCE_COLOR` warning; all assertions pass.
- Calibration is synthetic and proves contract, time-split, baseline, missing-evidence, and rendering behavior—not production accuracy, production inventory coverage, or live operational performance.

None of these warnings or limitations is a Pharmacy forecast correctness, privacy, freshness, accessibility, observed-state, or reconciliation failure. Real calibration and feed activation remain separately governed work.
