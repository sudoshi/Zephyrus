# Cockpit Sector Expansion — 12 Panels, All Gauged, OKR 9→13

**Date:** 2026-07-19 · **Branch:** `feature/cockpit-sector-expansion` · **Status:** EXECUTING

[SU]-approved shape (all four recommendations accepted):

1. **Full domains, 12-panel grid** — Radiology, Laboratory, Pharmacy, Hospital@Home become
   first-class cockpit domains with the same grammar as the existing 8 (gauge + top-6
   MetricRows + drill modal). The `flow.ancillary_*` tiles move OUT of Flow into their
   own panels; Flow refocuses on transport/EVS/lounge/bottlenecks.
2. **Real headline metric per panel** — every panel's circular indicator is a defensible
   operational number, not a synthetic composite (earned-urgency principle).
3. **OKR scorecard 9→13** — one card per new sector, live-with-demo-fallback like the
   existing registry.
4. **Live-first + enable H@H** — reuse the existing `*CockpitHealthService` live reads;
   `HOME_HOSPITAL_ENABLED=true` dev AND prod; extend demo seed; deploy to prod.

## Recon findings (verified on main 2026-07-19)

- `SnapshotBuilder::DOMAIN_GAUGES` (`app/Services/Cockpit/SnapshotBuilder.php:68`) is the
  ONE gauge registry; the frontend renders `RadialGauge` automatically wherever a domain
  has a `gaugeKey` whose tile exists. Missing: staffing, flow, quality, service, financial.
- **Pre-existing bug found:** `MetricValue::fromDefinition` never merges the definition's
  `metadata` (where `ed.nedocs` seeds `scale: 200`) into the tile, so the live NEDOCS ring
  renders `value/100` clamped with mis-scaled band arcs. The `CockpitOverview.test.tsx`
  fixture hand-writes `metadata.scale` and masks it. Fix: merge definition metadata under
  overrides in `fromDefinition` (overrides win per-key).
- Radiology/Lab/Pharmacy have full service layers on main; the cockpit-facing aggregate
  contracts are `RadiologyCockpitHealthService` / `LabCockpitHealthService` /
  `PharmacyCockpitHealthService` (sourceState freshness demotion pattern — preserve).
  The tile emitters (`radiologyMetric`/`laboratoryMetric`/`pharmacyMetric` + display/href
  helpers) currently live in `FlowMetrics` and move wholesale into the new providers.
- Hospital@Home is COMPLETE on main: `HomeMetrics` (9 KPIs, gauge already registered in
  DOMAIN_GAUGES), 3 migrations (2026_07_17), `HomeHospitalDemoSeeder` in `DatabaseSeeder`,
  and `DrillBuilder::homeTables()` (virtual ward board + referral funnel) already built.
  It has simply never rendered: flag default false + absent from frontend `DOMAIN_ORDER`.
- `flow.ancillary_*` consumers are CONTAINED: FlowMetrics, DrillBuilder
  (`ancillaryHealthTable`), 3 cockpit tests, ancillary seeder test, KPI seeder. No
  mobile/Eddy/scoped-face consumers.
- `BaseMetrics::fromKey` skips any key without a seeded definition — every new key MUST
  land in `CockpitKpiDefinitionSeeder` and be re-seeded on dev + prod.
- OKR grid is count-agnostic (`auto-fit minmax(190px,1fr)`); pins to update:
  `CockpitDashboardPageTest` `has('data.okrs', 9)`, `CockpitOverview.test.tsx` 9-card
  fixture.
- Seeder preserves admin-tuned edges for `flow.ancillary_*` on re-seed ("governed local
  policy") — the new `rad.*`/`lab.*`/`rx.*` keys keep that behavior (prefix list update).

## Gauge mapping (Phase 1 + new sectors)

| Domain | gaugeKey | Scale (metadata) | Notes |
|---|---|---|---|
| staffing | `staffing.productivity` | 120 | up; ok 100 / warn 95 / crit 90 |
| flow | `flow.transport_wait` | 60 | down; warn 15 / crit 25; alert-templated |
| quality | `quality.sepsis_3hr` | 100 | up; ok 90 / crit 80 |
| service | `service.oe_los` | 2 | down; warn 1.0 / crit 1.2, target 1.0 |
| financial | `financial.worked_per_uos` | 2 | down; warn 1.00 / crit 1.08 |
| radiology | `rad.stat_read_compliance` (new fact) | 100 | ratio query on projector tables, Lab-statCompliance pattern |
| lab | `lab.stat_compliance` | 100 | existing fact, renamed key |
| pharmacy | `rx.stat_verify_compliance` (new fact) | 100 | same pattern |
| home | `home.census_occupancy` | 100 | already registered |

Band arcs (frontend `GAUGE_BANDS`) added for the five Phase-1 gauges mirroring their
seeded edges; 'up' metrics paint the danger zone at the low end.

## Phases

- **P1** (commit 1): metadata-merge fix + 5 DOMAIN_GAUGES entries + seeder scale metadata
  + GAUGE_BANDS + tests (every rendered panel has a gauge).
- **P2** (commits 2a/2b): `RadiologyMetrics`/`LabMetrics`/`PharmacyMetrics` providers
  (keys `rad.*`/`lab.*`/`rx.*`, ~6 tiles each, one new compliance fact per health
  service); register in SnapshotBuilder + DrillBuilder (+TITLES, drill tables from
  `*FlowBoardService`); strip ancillary from FlowMetrics + flow drill; frontend
  DOMAIN_ORDER/TITLES/cockpitDrillDomains/GAUGE_BANDS; seeder defs (alert_template only
  on lab critical callbacks, rx sepsis-at-risk, rad breaches — Earned-Red ration).
- **P3** (commit 3): DOMAIN_ORDER += home ("Hospital@Home"), `HOME_HOSPITAL_ENABLED=true`
  dev, seed verify.
- **P4** (commit 4): OKR 13 — `okr.rad_stat_tat` (reuse rad gauge), `okr.lab_critical_callback`,
  `okr.rx_stockouts`, `okr.hah_avoided_bed_days` (reuse `home.avoided_bed_days_mtd`);
  update pins.
- **P5** (commit 5): demo-seed enrichment; snapshot→real-Zod Vitest (the `direction: null`
  bug class); full gates (PHPUnit/Vitest/tsc/vite/canon ≤76/Pint). **User check-in pre-PR.**
- **P6**: PR → CI → merge → deploy.sh → prod flag + `CockpitKpiDefinitionSeeder` +
  `zephyrus:demo-seed --skip-imports` → php8.5-fpm restart → tinker/curl verify
  (12 domains all gauged, 13 OKRs, 4 new drills) → devlog + memory.

## Risks

- 12 panels = 3 rows on xl — visual check on dev; tighten if the A0 glance scrolls.
- Key rename loses `ops.metric_values` sparkline history for ancillary tiles (accrues
  fresh; acceptable).
- Enabling home flag activates its 4 alert templates — deliberate, Earned-Red compliant.
