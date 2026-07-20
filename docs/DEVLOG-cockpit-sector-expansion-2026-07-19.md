# Devlog — Cockpit Sector Expansion (2026-07-19)

**Branch:** `feature/cockpit-sector-expansion` · **Base:** main (post PR #45)

Two asks, one coherent change: (1) give **every** main cockpit panel a circular
indicator, and (2) add four service sectors — **Radiology, Laboratory, Pharmacy,
Hospital@Home** — as first-class gauged panels with KPIs and OKRs.

## What shipped

### Phase 1 — all panels gauged
- **Bug fixed:** `MetricValue::fromDefinition` never merged the KPI definition's
  `metadata` onto the tile, so the NEDOCS ring rendered against `/100` instead of
  its seeded `scale: 200`, with mis-scaled band arcs. Now definition metadata
  rides on every tile (override keys win). This was latent because the one
  Vitest fixture hand-wrote `metadata.scale`.
- Registered gauges for the five bare panels: **Staffing** (productivity %, scale
  120), **Flow** (transport request-to-pickup min, scale 60), **Quality** (sepsis
  3-hr bundle %), **Service Lines** (O:E LOS, scale 2), **Financial** (worked/UOS,
  scale 2). Each gets a `GAUGE_BANDS` arc mirroring its StatusEngine edges.

### Phase 2 — Radiology / Laboratory / Pharmacy promoted out of Flow
- New `RadiologyMetrics` / `LabMetrics` / `PharmacyMetrics` providers. They emit
  the **same governed `flow.ancillary_*` keys** (seeded catalog, admin-tuned
  edges, and accrued sparkline history preserved) — only the domain section moved
  from `flow` to `radiology` / `lab` / `pharmacy`. `FlowMetrics` lost its
  ancillary emission and the three health-service deps; Flow refocuses on
  transport / EVS / lounge / bottlenecks.
- `SnapshotBuilder`: registers the three providers + `OPTIONAL_WHEN_EMPTY`
  (`radiology`, `lab`, `pharmacy`, `home`) so a sector with no feed is **ABSENT**,
  never an empty panel.
- `DrillBuilder`: `DOMAINS`/`TITLES` gain the three sectors; each drills to its
  own aggregate measure ledger (the privacy-preserving default — the workspace
  owns per-item detail). The old cross-service "Ancillary operational health"
  table was removed from the Flow drill.
- Frontend: `DOMAIN_ORDER`/`DOMAIN_TITLES`/`cockpitDrillDomains` → **12 panels**;
  minute-unit gauges render a compact `"75m"` ring center (a formatted duration
  overflows the 84px ring) while tile/drill keep the full display.

### Phase 3 — Hospital@Home
- Promoted `home` into `DOMAIN_ORDER` as panel 12 ("Hospital@Home"). The domain
  was already fully built (`HomeMetrics`, migrations, seeder, drill) and
  flag-gated; prod already runs `HOME_HOSPITAL_ENABLED=true`.

### Phase 4 — OKR scorecard 9 → 13
- Four sector OKRs, each reusing its sector's live headline signal with a config
  demo fallback (same pattern as the existing 9): Imaging SLA breaches ·
  Lab STAT SLA compliance · Medication stockouts · H@H avoided bed-days MTD.

## Why the keys did not change

The `flow.ancillary_*` keys carry a documented governance/privacy contract with
heavy freshness/last-known test coverage. Renaming them (to `rad.*/lab.*/rx.*`)
would have been cosmetic — invisible to the user — while discarding sparkline
history and generating large test churn. Relocating emission preserves the
contract and the history; the domain **section** is what moved.

## Prod readiness (verified before writing code)

Prod already has `CLINICAL_PAYLOADS_ENABLED`, `HOME_HOSPITAL_ENABLED`,
`DEMO_MODE`, `ARENA_ENABLED` all true, with **66 ancillary orders / 328
milestones / 8 home episodes** live and a 6-hourly rolling demo-refresh. So the
four new panels populate automatically on deploy — no infra to stand up. (Dev
lacks the clinical-payload store, so the three ancillary sectors are absent on
dev; they are proven by PHPUnit, which enables the store in-test, and by the
12-panel Vitest fixture.)

## Gates

- `tsc` clean · `vite build` clean · `check-ui-canon.sh` pass (raw-palette ≤ 76)
- Cockpit Vitest 86/86 · Pint clean
- PHPUnit: Radiology + Sections + DrillApi (9/9, incl. absent-sector→404) +
  FlowLive + DashboardPage + AncillaryReference all green; Lab/Pharmacy green
  (slow — 3–6 min/test from the demo-scenario setUp).

## Follow-ups

- Prod visual verification of all 12 panels + 13 OKRs post-deploy.
- The three ancillary sectors deliberately do **not** page (no `alert_template`)
  — consistent with the pre-existing aggregate-only ancillary posture; promoting
  any to Earned-Red alerting is a separate [SU] ruling.
