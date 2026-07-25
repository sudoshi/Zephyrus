# D1 — AncillaryDemoScenarioService::refresh profile (2026-07-25)

Plan item D1 of `docs/superpowers/plans/2026-07-24-ci-duration-optimization-plan.md`:
profile the ancillary refresh chain with `EXPLAIN (ANALYZE, BUFFERS, WAL)` on the
66-order / 328-milestone fixture in a disposable database. Every backend shard
layout is lower-bounded by `AncillaryDemoScenarioTest`, so this class's cost is
the suite's floor.

## Method

Throwaway PHPUnit harness (not committed) mirroring `AncillaryDemoScenarioTest`'s
exact fixture (same 5 seeders, same `2026-07-11T14:00:00Z` anchor) against the
standard isolated per-process test database on host PG17. Per-query aggregation
via `DB::listen`; top statements re-executed under
`EXPLAIN (ANALYZE, BUFFERS, WAL)` inside rolled-back transactions. Fixture size
confirmed: **66 orders / 328 milestones** — exactly the plan's numbers.

## Measured baseline (local, beastmode)

| Path | Wall | SQL | Statements |
|---|---:|---:|---:|
| Cold refresh (first build) | 3.9 s | — | — |
| Same-anchor replace refresh | 8.7 s | 7.2 s | **11,730** (390 distinct) |
| Next-day roll-forward refresh | **25.6 s** | 24.0 s | 12,402 (393 distinct) |

The full test class (9 tests, several refreshes each plus per-test seeding) ran
**201.3 s locally**; its CI median is 790.7 s (S1 manifest weight).

## Findings, ranked

1. **Stale planner statistics dominate (root cause of the floor).** The two
   milestone-selection joins (`ancillary_milestones ⋈ ancillary_orders`, one
   pair of queries per department × 5) cost **15.2 s of the 25.6 s roll-forward**.
   `EXPLAIN` shows `rows=1` estimates against 300+-row actuals everywhere: on a
   freshly provisioned test database autoanalyze has not caught up with the bulk
   seed/insert churn, so the planner assumes near-empty tables and picks
   unparameterized nested loops (Join Filter removes 4,326 of 4,423 rows;
   11.8 ms per inner loop × 328 loops). **A plain `ANALYZE` before the
   roll-forward collapsed it from 25.6 s to 11.2 s (2.3×), beating the plan's
   2× target with zero query changes.**
2. **Reconciliation N+1** — `select * from prod.ancillary_orders where
   department = ? and metadata->>'reconciliation_key' = ? limit 2` runs **once
   per milestone (328 calls)**; the department index narrows to ~16 rows, then
   filters JSONB text row-by-row. Candidates: expression index on
   `(department, (metadata->>'reconciliation_key'))`, or promote the key to a
   real column; or batch-fetch keys per department.
3. **Per-row projection-status updates** — `update integration.canonical_events
   set projection_status = ... where canonical_event_id = ?` ran **672 times**;
   batchable into `where canonical_event_id in (...)` groups.
4. **Provenance delete filter mismatch** — `delete from
   integration.provenance_records where target_schema = ? and target_table in
   (…11) and canonical_event_id = any(...)` scans via `provenance_target_idx`
   then filters out 635 rows per call. Candidate: index on
   `(canonical_event_id)` (or extend the target index).
5. **Statement chattiness** — 11,730 statements to replace 66 orders/328
   milestones is per-row ORM traffic; bulk upsert paths would cut both SQL and
   PHP overhead (PHP-side overhead measured 1.4–1.6 s per refresh).

(The `EXPLAIN` replay of the owned-order `DELETE` reports an FK violation
artifact — replayed out of its transactional order, children still present.
Not a code bug.)

## Remediation shipped with this report

`AncillaryDemoScenarioService::refresh()` now runs a targeted
`ANALYZE prod.ancillary_orders, prod.ancillary_milestones,
prod.ancillary_current_assertions, prod.ancillary_breaches,
integration.canonical_events, integration.provenance_records` after the
generation transaction (failure-tolerant: logs a warning, never breaks the
refresh — statistics are an optimization, not a correctness requirement).
§3.2.9-clean: no dirty-DB reuse, no trigger suppression, no isolation change —
the fixture pipeline is untouched; only the planner's view of it is corrected.

**Measured effect (local, full real test classes, before → after):**

| Test class | Before | After | Δ |
|---|---:|---:|---|
| `AncillaryDemoScenarioTest` (9 tests) | 201.3 s | **122.9 s** | −39% (1.64×) |
| `LaboratoryCockpitMetricsTest` (4 tests) | 42.6 s | 39.8 s | −7% |

Prod demo pipeline impact: positive-or-neutral — steady-state tables there
already have autovacuum statistics; the explicit ANALYZE merely guarantees
fresh stats immediately after each 6-hourly churn.

## Follow-ups (for D2/D3, in leverage order)

1. Regenerate `tests/ci/shard-manifest.json` once this and S2 land — the floor
   weight (790.7 s median) shrinks materially, and LPT should re-pack.
2. Reconciliation-key expression index or column (finding 2).
3. Batch the projection-status updates (finding 3) and provenance delete index
   (finding 4) — then re-profile before D3 (paratest) is evaluated.
4. `SnapshotBuilder::buildWithContext` / Lab services inherit the stats fix via
   their `refresh()`-seeded fixtures (the −7% above); their own D2
   request-scoped aggregate remains open.
