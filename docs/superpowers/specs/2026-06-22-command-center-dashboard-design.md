# Hospital Operations Command Center — Main Dashboard Redesign

**Date:** 2026-06-22
**Status:** Approved design — ready for implementation planning
**Branch:** `feature/command-center-dashboard`
**Author:** Sanjay Udoshi (with Claude Code)

---

## 1. Context & Motivation

Zephyrus is, at its core, a **real-time demand & capacity (RTDC) hospital operations platform**. Its
richest data lives in the RTDC schema (`prod.census_snapshots`, `prod.encounters`, `prod.beds`,
`prod.units`, `prod.operational_events`, `prod.rtdc_predictions`) and its navigation spans five
operational domains: RTDC, Perioperative, Emergency, Improvement, and Analytics.

The **current main dashboard** (`resources/js/Pages/Dashboard.jsx` → `DashboardOverview.jsx`) is, by
contrast, **perioperative-only**: surgical KPIs (on-time starts, turnover, block utilization, case-length
accuracy) rendered from mock data, with non-functional location/service/surgeon filters. It does not
reflect the platform's actual identity and offers no house-wide operational picture or pathways into the
deeper domain areas.

This redesign replaces the main dashboard with a **house-wide Hospital Operations Command Center** that
surfaces the metrics that matter across the whole hospital and routes into every domain. The existing
perioperative dashboard is preserved unchanged at `/dashboard/perioperative`.

## 2. Research Foundation

### 2.1 Organizing framework — Donabedian + Forecast

The most defensible structure in the operations literature is the **Donabedian model
(Structure → Process → Outcome)**, which is precisely how Johns Hopkins' patient-flow dashboard and
Judy Reitz Capacity Command Center organize their ~10 headline metrics. Modern command centers
(JH, GE HealthCare, Qventus) add a fourth, forward-looking band the older dashboards lack:
**prediction**. The command center therefore presents four bands:

**Capacity (Structure) → Flow (Process) → Outcomes → Forecast (Prediction).**

### 2.2 KPI families and best-practice targets

| Band | Metric | Best-practice target | Evidence |
|---|---|---|---|
| Capacity | Staffed bed occupancy | Safe zone ≤ **85%**; >85–90% degrades ED flow & safety | Guidehouse; occupancy↔LWBS studies |
| Capacity | Staffed / occupied / blocked / available; acuity-adjusted capacity | live | JH structural metrics |
| Capacity | Capacity strain / surge level (NEDOCS-style composite) | banded 0–4 | ED crowding literature |
| Flow (ED) | Door-to-provider | **< 20 min** | EDBA / Core Clinical Partners |
| Flow (ED) | Left without being seen (LWBS) | **< 1%** elite, < 2% goal | ED benchmarking |
| Flow (ED) | ED length of stay | discharged **< 150 min**, admitted **< 300 min** pre-transfer | EDBA |
| Flow (ED) | ED boarding (admit decision → bed) | **< 4 h** (Joint Commission); reality median ~192 min | ACEP / TJC |
| Flow (IP) | Admit-decision → bed-assigned latency | JH cut **30%** | JH command center |
| Flow (IP) | Discharge before noon (DBN) | best programs ~**27%** (from ~9%) | BMC multi-year QI |
| Flow (OR) | First-case on-time starts (FCOTS) | **≥ 80–85%** (15-min grace) | AORN |
| Flow (OR) | Block utilization | **~75–80%** (avg ~71%) | LeanTaaS / Plante Moran |
| Flow (OR) | Turnover time | median **~28.5 min**; target < 25 min | OR benchmarking |
| Flow (OR) | Same-day (DoS) cancellations | track + trend | — |
| Outcome | 30-day readmission rate | vs target | CMS / JH |
| Outcome | LOS vs **GMLOS** index | → **1.0**; excess days −20–35% | Qventus / GE |
| Outcome | Excess / avoidable bed-days | trend down | Qventus |
| Outcome | Capacity-related diversion hours | → 0 | JH |
| Forecast | Predicted discharges (24/48 h) | the differentiator | GE / Qventus |
| Forecast | Predicted ED arrivals / admissions | — | GE / Qventus |
| Forecast | Forecasted occupancy curve (24 h, with confidence) | — | command center models |
| Forecast | **Net bed position** (projected supply − demand) | ≥ 0 | derived |
| Forecast | Surge probability | banded | derived |

### 2.3 OKRs — the missing layer

A KPI states *where you are*; an OKR states *where you're going and whether you'll make it*. The
high-impact move is to render each headline metric as **current value + target + trajectory toward the
Key Result**, not a bare number. Canonical hospital-operations OKRs the dashboard is built to track:

- **O: Improve patient access & flow** → KRs: ED boarding 192 → < 120 min; DBN 10% → 25%; hold occupancy at 85%.
- **O: Maximize surgical throughput** → KRs: FCOTS → ≥ 85%; block utilization → 80%; turnover → < 25 min.
- **O: Eliminate avoidable bed-days** → KRs: LOS/GMLOS index → 1.0; excess days −25%.

### 2.4 Evidence-based dashboard design rules applied

- 5–9 primary visuals per default view; most-important top-left.
- A single dominant **status indicator** (house-wide surge/strain level).
- Tiles + sparklines for movement; semantic color only (status, never decoration).
- **Progressive disclosure**: tile → hover/expand for breakdown → click to drill into the domain page.
- Metric definitions one click away (info popover).
- Operational dashboards are real-time, low-latency, and action-oriented.

**Sources:** Johns Hopkins Command Center & Donabedian patient-flow dashboard (PubMed 29915933);
Guidehouse capacity management (2025); EDBA / ACEP / Core Clinical Partners ED metrics; BMC
discharge-before-noon multi-year QI (2024); LeanTaaS / AORN / Plante Moran OR metrics; Qventus inpatient
flow & GMLOS; GE HealthCare Command Center; DataCamp / UXPin / Yellowfin dashboard-design guidance.

## 3. Goals & Non-Goals

### Goals
- Replace `/dashboard` with a house-wide operations command center reflecting Zephyrus's RTDC identity.
- Surface the four-band Donabedian + Forecast KPI set with OKR-aware tiles (current + target + trajectory).
- Maximize data density and respect for screen real estate; default view fits one screen.
- Provide drill-down from every band header and tile into the relevant domain page.
- Support three roles via a view switcher: **Command**, **Executive**, **Service-line**.
- Deliver a fully built, clickable frontend on **representative data** shaped to the prod RTDC schema.

### Non-Goals (this phase)
- Live metric computation from production tables (clean follow-up; the data builder is designed to be
  swapped for real queries).
- Real-time websocket streaming (interval polling is sufficient for this phase).
- Modifying or removing the perioperative dashboard (it stays at `/dashboard/perioperative`).
- Any change to the authentication system (protected per `.claude/rules/auth-system.md`).

## 4. Design

### 4.1 Layout — the "Command Wall" hybrid

A **bento hero "command wall"** for at-a-glance status above a **banded board** for dense, scannable
depth. The bento reads from across a command room; the bands give systematic scan order and clean
drill-down.

```
╔════════════════════ COMMAND WALL (bento, full-width) ═══════════════════╗
║ ┌───────────────┐ ┌──────────┐ ┌──────────┐                            ║
║ │  SURGE LVL 2  │ │ Occupancy│ │ Net beds │   ← hero strain cell (2×2)  ║
║ │  ▲ from L1    │ │  88%  ▁▂▅│ │  −3 ▼    │     + mixed-size urgent     ║
║ │  drivers:occ, │ ├──────────┤ ├──────────┤       "right-now" tiles     ║
║ │  board, adm   │ │ Boarding │ │ DC ready │                            ║
║ │               │ │  4 · 6h  │ │  9 ·18%↗ │                            ║
║ └───────────────┘ └──────────┘ └──────────┘                            ║
╚══════════════════════════════════════════════════════════════════════════╝
[ Command ▸ Executive ▸ Service-line ]                    ⟳ updated 28s ago
─ CAPACITY ───────────────────────────────────────────── open RTDC →
  [unit census heat ▦▦▦▦▦▦] [Avail 14] [Blocked 6→barriers] [Acuity-adj 92%]
─ FLOW ──────────────────────────── open ED · Periop · Bed Placement →
  ED:[D2P 17m][LWBS 0.9%][LOS 138/284m][Board 4]
  IP:[adm→bed 47m][DBN 18%→25%]
  OR:[FCOTS 82%][Block 76%][Turn 31m][Cxl 2]
─ OUTCOMES ────────────────────────────────────────── open Improvement →
  [Readmit 12.1%][LOS/GMLOS 1.10][Excess days ▼][Diversion 0h][Active PDSA 5]
─ FORECAST ────────────────────────────────────────── open Predictions →
  [Discharges 24h: 22][ED arrivals ↗][Occupancy curve 24h ◌][Net beds by unit][Surge prob 38%]
```

### 4.2 Command wall (bento) tiles

The bento hero contains the 5–6 "what matters this minute" indicators:

1. **Capacity Strain / Surge Level** — the single dominant indicator, hero cell (~2×2). Banded 0–4
   (Green → Amber → Red) with the composite drivers listed (occupancy, boarding, pending admissions,
   ED waiting). Shows direction vs previous level.
2. **Staffed Occupancy** — % with target band (≤ 85% safe), 12 h sparkline, status color.
3. **Net Bed Position** — projected available − projected demand over the next 4–8 h (e.g., −3 ▼).
   The best single "are we about to run out" number. Links to Forecast.
4. **ED Boarding** — count + longest boarder hours; target < 4 h.
5. **Discharges Ready / DBN%** — ready-now count + discharge-before-noon % vs target.

### 4.3 Band content (OKR-aware tiles)

Every tile renders **current value · target · trajectory sparkline · status color**, so a KPI doubles as
Key-Result progress.

- **Capacity (structure)** → drills to RTDC / Bed Tracking:
  unit census heat strip (staffed/occupied/blocked/available, acuity-adjusted), available by type,
  blocked + reasons (→ Barriers), acuity-adjusted capacity.
- **Flow (process)** → drills to ED / Perioperative / Bed Placement; sub-grouped:
  - **ED:** door-to-provider, LWBS, ED LOS (discharged/admitted), boarding.
  - **Inpatient:** admit-decision → bed latency, discharge-before-noon, discharges completed vs predicted.
  - **OR:** FCOTS, block utilization, turnover, same-day cancellations.
- **Outcomes** → drills to Improvement:
  30-day readmission, LOS/GMLOS index, excess bed-days trend, capacity-related diversion hours,
  active PDSA cycles.
- **Forecast (prediction)** → drills to Predictions:
  predicted discharges 24/48 h, predicted ED arrivals/admissions, 24 h occupancy curve with confidence
  band, net bed position by unit, surge probability.

### 4.4 Role switcher behavior

A `RoleSwitcher` (Command ▸ Executive ▸ Service-line) re-weights the same data:

- **Command** (default): hero wall emphasizes *right-now* strain; bands show live operational tiles.
- **Executive:** hero wall flips to an **OKR scoreboard** (the 3 objectives with KR progress bars);
  bands emphasize variance-to-target and trend.
- **Service-line:** scopes everything to a chosen service/unit; the OR band and that unit's flow rise
  to the top.

Role selection is client-side UI state (Zustand); it does not change routes.

### 4.5 Drill-down map (uses existing `navigationConfig` routes)

| Source | Destination |
|---|---|
| Capacity band / occupancy tiles | `/rtdc/bed-tracking` |
| Blocked beds tile | RTDC Barriers (`BarrierBoard`) |
| Flow — ED tiles | `/dashboard/emergency` |
| Flow — Inpatient tiles | `/rtdc/bed-placement` |
| Flow — OR tiles | `/dashboard/perioperative` |
| Outcomes band | `/dashboard/improvement` |
| Forecast band | `/rtdc/predictions/demand` (discharge → `/rtdc/predictions/discharge`) |

Metric definitions are one click away via an info popover (matching today's `MetricCard` pattern).

### 4.6 Density & screen-real-estate tactics

- Uniform tile sizing **within** each band for fast scanning (bento variety only in the hero wall).
- Sparklines instead of full charts inside tiles; full charts appear only on expand/drill.
- Progressive disclosure: hover/expand for the breakdown, click to drill into the domain page.
- The existing dense 11–48px type scale; display serif (Crimson Pro) reserved for hero numerals.
- Semantic color only — crimson `#9B1B30`, gold `#C9A227`, teal `#2DD4BF`, plus
  critical/warning/success/info tokens = status, never decoration.
- Default view targets ~6–9 primary visuals per the operational-dashboard evidence and fits one screen
  on a laptop; bands reflow responsively and the hero wall scales for wall displays.

## 5. Data — representative-data approach

This phase ships a fully built frontend on **representative data** that is shaped exactly like the prod
RTDC schema so it can be swapped for live queries with no frontend changes.

- A backend **`CommandCenterDataService`** emits a single typed payload (`CommandCenterData`) containing:
  house strain/surge state, per-unit census rows, ED/IP/OR flow metrics, outcome metrics, forecast
  series, and the OKR scoreboard. Values are realistic and internally consistent (e.g., strain level is
  derived from occupancy + boarding + pending admits, not random).
- The service reads from the existing models where seed data exists (`Bed`, `Unit`, `Encounter`,
  `CensusSnapshot`, `RtdcPrediction`) and synthesizes the remainder deterministically.
- TypeScript interfaces mirror the payload exactly (strict mode, no `any`). A Zod schema validates the
  payload at the boundary.

The follow-up phase replaces the synthesis with real aggregate queries; the payload contract is the seam.

## 6. Architecture & Components

- **Page:** repoint `/dashboard` → new `resources/js/Pages/Dashboard/CommandCenter.tsx` (TSX, strict
  types). The current main view (`Dashboard.jsx` → `DashboardOverview`) is **preserved and remains
  reachable** — not deleted. Note: `/dashboard/perioperative` today renders a *separate*
  `Dashboard/Perioperative` page (via `DashboardController`), so the exact binding for the legacy
  overview (reuse `/dashboard/perioperative`, fold it in, or give it a dedicated path) is decided during
  planning. The non-goal stands: no perioperative content is lost.
- **Controller:** `app/Http/Controllers/CommandCenterController.php@index` renders the Inertia page with
  the `CommandCenterData` payload from `CommandCenterDataService`.
- **Service:** `app/Services/CommandCenterDataService.php` (the representative-data builder / future
  live-query seam).
- **Components** (small, focused; `resources/js/Components/CommandCenter/`):
  - `HeroWall.tsx` — bento hero layout.
  - `StrainIndex.tsx` — surge/strain hero cell with drivers.
  - `Band.tsx` — band wrapper (header + drill link + tile grid).
  - `KpiTile.tsx` — OKR-aware tile (value + target + trajectory + status + info popover).
  - `UnitHeatStrip.tsx` — compact per-unit census heat row.
  - `ForecastCurve.tsx` — 24 h occupancy projection with confidence band (Recharts).
  - `RoleSwitcher.tsx` — Command ▸ Executive ▸ Service-line toggle.
  - `OkrScoreboard.tsx` — Executive-mode objective/KR progress board.
- **Reuse:** existing Recharts wrappers (`Components/ui/charts`, `Components/Dashboard/Charts`),
  `CircularProgress`, `Card` primitives, design tokens (`tokens-base.css`, `tokens-dark.css`), the dark
  clinical theme and `useDarkMode()`.
- **State:** Zustand store for role/service-line selection. Refresh via interval poll (~30–60 s) with an
  "updated Xs ago" indicator and a manual refresh control; data delivered through Inertia props
  (partial reload) so the live-data swap is transparent.
- **File size discipline:** each component < 300 lines; extract sub-tiles as needed.

## 7. Testing

- Component tests (Vitest + React Testing Library; setup at `tests/js/setup.ts`) for `KpiTile`
  (status/threshold logic, OKR target rendering), `StrainIndex` (level banding from drivers),
  `RoleSwitcher` (mode re-weighting), and the drill-down links.
- Type safety: `npx tsc --noEmit` **and** `npx vite build` (vite is stricter — catches unresolved
  imports tsc misses) must both pass before completion.
- Backend: a feature test asserting `/dashboard` returns the command-center Inertia component with a
  payload that validates against the `CommandCenterData` contract.
- Pint on any PHP touched.

## 8. Risks & Mitigations

- **Density vs clutter** — mitigated by uniform in-band tile sizing, sparklines over charts, and
  progressive disclosure; enforce the 6–9 primary-visual budget on the default view.
- **Representative data looking "fake"** — mitigated by deriving values from consistent drivers and
  reusing real seed rows where present.
- **Scope creep into live data** — explicitly deferred; the payload contract is the seam.
- **Route/regression risk** — perioperative dashboard preserved at its existing route; no auth changes.

## 9. Open Questions (resolve during planning)

- Exact refresh interval (default 45 s) and whether Service-line scoping persists per user.
- Whether Executive mode is a sub-view of `/dashboard` (chosen) vs a separate route (`/dashboard?view=exec`).
- Wall-display mode (kiosk) — out of scope this phase, but the hero wall is designed to scale into it.

## 10. File Manifest (planned)

**New**
- `resources/js/Pages/Dashboard/CommandCenter.tsx`
- `resources/js/Components/CommandCenter/{HeroWall,StrainIndex,Band,KpiTile,UnitHeatStrip,ForecastCurve,RoleSwitcher,OkrScoreboard}.tsx`
- `resources/js/stores/commandCenterStore.ts` (Zustand)
- `resources/js/types/commandCenter.ts` (+ Zod schema)
- `app/Http/Controllers/CommandCenterController.php`
- `app/Services/CommandCenterDataService.php`
- Tests under `tests/js/` and `tests/Feature/`

**Modified**
- `routes/web.php` — point `/dashboard` at `CommandCenterController`; preserve access to the legacy
  perioperative overview (`DashboardOverview`); leave the existing `/dashboard/perioperative` →
  `Dashboard/Perioperative` route intact.
