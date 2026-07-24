# Hospital Operations Cockpit — Implementation Specification

**Target stack:** PostgreSQL 17 · Laravel (API) · React (SPA)
**Source of truth for the design:** `Hospital Operations Cockpit.dc.html` (single-file reference prototype)
**Audience:** Claude Code (and human engineers) implementing the production cockpit.

This document is the bridge between the reference design and a production implementation. It captures
**every design decision, every KPI definition, every threshold, the data model, the API contract, and the
component architecture**. Read it top to bottom once before writing code.

---

## 0. What this is and why it looks the way it does

The cockpit is a **single-screen, 24/7 situational-awareness display** for hospital house operations. It is
modeled on **control-room / process-control HMI design**, not on consumer dashboards. The governing principles:

### 0.1 ISA-101 (High-Performance HMI) philosophy
- **Grey is the baseline.** A unit operating normally is rendered in muted, low-saturation neutrals. The screen
  at a calm moment should look almost monochrome.
- **Color is an exception signal, not decoration.** Saturated color (amber/red/blue/green) appears **only** to
  mark an abnormal or noteworthy state. This is what lets the eye lock onto the one thing that's wrong on a wall
  of 400 numbers. Do **not** colorize "good" values just to be cheerful — that destroys the signal.
- **No alarm fatigue.** Reserve red for genuinely actionable, time-critical conditions. Everything that is merely
  "watch this" is amber or blue, never red.

### 0.2 Human-factors rules that are non-negotiable
- **Redundant encoding (color-vision safety).** Every status is encoded by **shape AND color**, never color
  alone. ~8% of male staff have a color-vision deficiency. The shape glyphs are:
  - Normal `–` · On-target `●` (green) · Watch `●` (blue) · Warning `▲` (amber) · Critical `◆` (red)
- **Gauges show context, not just a number.** A radial gauge encodes the value against its full scale so a
  glance reads "how full" without reading digits.
- **Trend is first-class.** Sparklines accompany rate metrics so a viewer sees direction, not just level.
- **Legibility at distance.** On a wall display, the smallest meaningful number is ~24px; primary metrics are
  much larger. Tabular/monospaced figures so columns of numbers align and don't "shimmer."
- **One screen, no scrolling for the overview.** The overview must fit a 1080p display. Detail lives behind
  drill-downs, never below a scroll fold on the overview.

### 0.3 Layout grammar
```
┌─────────────────────────────────────────────────────────────────────┐
│ COMMAND BAR  hospital · capacity-status pill · live clock            │
├─────────────────────────────────────────────────────────────────────┤
│ CENSUS STRIP  8 house-level summary chips                            │
├─────────────────────────────────────────────────────────────────────┤
│ ALERT TICKER  scrolling list of active critical/warning conditions   │
├─────────────────────────────────────────────────────────────────────┤
│ EXECUTIVE OKR SCORECARD  7 objective cards (actual vs target+trend)  │
├───────────────────┬───────────────────┬─────────────────────────────┤
│ COL 1             │ COL 2             │ COL 3                       │
│  RTDC / Capacity  │  Emergency Dept   │  Staffing & Workforce       │
│  Flow & Transport │  Perioperative    │  Quality & Safety           │
│                   │                   │  Service Lines              │
│                   │                   │  Labor & Financial          │
├─────────────────────────────────────────────────────────────────────┤
│ STATUS LEGEND + data-source/refresh footer                          │
└─────────────────────────────────────────────────────────────────────┘
```
Every panel header and every OKR card is a **drill-down entry point** (marked with a `⤢` glyph). Clicking opens
a full-screen modal with a KPI summary strip plus deep detail tables for that domain.

---

## 1. Design System (tokens)

Implement these as a single source of truth (e.g. `resources/js/theme.ts` and/or CSS custom properties). The
reference uses inline styles; production should centralize.

### 1.1 Color tokens
Colors are authored in **OKLCH** so the abnormal-state hues share identical lightness/chroma and differ only in
hue (perceptually balanced, accessible). Backgrounds are near-black with a slight cool tint.

```ts
export const color = {
  // Surfaces (cool near-black)
  appBg:        'radial-gradient(1400px 700px at 70% -200px, #101a28, #080b11 60%)',
  panel:        'linear-gradient(180deg,#101825,#0c121d)',
  panelHeader:  '#0d1420',
  tile:         '#0c1320',
  inset:        '#0b1320',
  border:       '#1d2737',
  borderSoft:   '#18212f',
  hairline:     '#141c28',

  // Text
  textHi:   '#f1f5fa',
  text:     '#e6ecf2',
  textMid:  '#c4cedb',
  textDim:  '#8c97a5',
  textFaint:'#69727f',
  label:    '#7d8a99',
  labelDim: '#5f6b79',

  // Status (THE important part — see status engine §4)
  status: {
    normal: { color: '#6b7686',              glyph: '–', track: '#3c4a5b' },
    ok:     { color: 'oklch(0.74 0.13 162)', glyph: '●', track: 'oklch(0.74 0.13 162)' }, // green
    watch:  { color: 'oklch(0.72 0.13 235)', glyph: '●', track: 'oklch(0.72 0.13 235)' }, // blue
    warn:   { color: 'oklch(0.80 0.14 78)',  glyph: '▲', track: 'oklch(0.80 0.14 78)'  }, // amber
    crit:   { color: 'oklch(0.64 0.20 25)',  glyph: '◆', track: 'oklch(0.64 0.20 25)'  }, // red
  },

  accent: 'oklch(0.72 0.13 200)', // cyan — brand/neutral domain accent (NOT a status color)
  gaugeTrack: '#1a2535',
};
```

**Rule for value text color:** a metric's number is near-white (`textHi`) for `normal`/`watch`, green for `ok`,
and only takes the status color when `warn` or `crit`. This keeps abnormal values "glowing."

### 1.2 Typography
- **Sans:** `IBM Plex Sans` (labels, copy, headers). Weights 400/500/600/700.
- **Mono:** `IBM Plex Mono` (all numbers, codes, times, ratios). Tabular alignment is the point.
- Scale (overview): panel title 11.5px/600/upper/tracked; tile label 9px/600/upper; tile value 23px mono;
  hero metric 26–30px mono; census value 26px mono. **Never below 9px** on the overview; 24px floor on a true
  wall display (scale the root up).

### 1.3 Iconography
No decorative icons. The only glyphs are the **status shapes** (`– ● ▲ ◆`), the **expand affordance** (`⤢`), a
small **domain swatch** (7px rounded square in the accent or the domain's status hue), and live/refresh marks
(`●` pulse, `⟲`). Do not introduce an icon library; it fights the HMI aesthetic.

### 1.4 Motion
- Live clock ticks 1Hz.
- Alert ticker: horizontal marquee, ~60s linear loop, content duplicated for seamless wrap.
- Critical alert indicator: 1.6s pulse ring.
- Gauge/bar fills: 0.6s ease transition on value change.
- **No gratuitous animation.** Motion is reserved for "this is live" and "this is critical."

### 1.5 Primitive components (visual building blocks)
| Primitive | Purpose | Key props |
|---|---|---|
| `RadialGauge` | hero domain index (occupancy, NEDOCS, OR util) | `value, scale, status, big, small` |
| `Sparkline` | 7-point trend | `data:number[], status` |
| `MeterBar` | inline horizontal fill (occupancy, funnel) | `pct, status, label` |
| `StatusChip` | shape+color status mark | `status` |
| `Tile` | label / big value / unit / sub / chip / sparkline | `label,value,unit,sub,status,trend` |
| `CensusChip` | house-level summary stat | `label,value,sub,status` |
| `Panel` | domain container w/ clickable header | `title, accent, onDrill, children` |
| `DataTable` | generic detail table (drill-downs) | `cols, rows` (see §6.4) |

> **Gauges/sparklines/bars are data-viz**, fine to render as SVG/canvas. **Everything else is real DOM** so it's
> inspectable/accessible.

---

## 2. KPI & OKR Catalog (the domain knowledge)

This is the heart of the spec: **what to measure, how to compute it, where it comes from, and when it turns
color.** Thresholds below are sensible, literature-aligned defaults — expose them all as **configurable** per
facility (see `kpi_definitions` table §5). Direction `↓` = lower is better, `↑` = higher is better.

> **Threshold semantics.** Each KPI has up to four band edges. The status engine (§4) maps a value to
> `normal/watch/warn/crit`. "Green/ok" is used sparingly and only where confirming on-target genuinely aids the
> viewer (OKRs, a few safety measures).

### 2.1 Executive OKR Scorecard (enterprise)
One card per objective: objective label, key-result label, actual, target, Δ week-over-week, owner, status,
7-point sparkline.

| Objective | Key Result (metric) | Dir | Target | warn at | crit at | Owner |
|---|---|---|---|---|---|---|
| Safe Care · Zero Harm | Sepsis 3-hr bundle compliance | ↑ | ≥90% | <90% | <80% | CMO |
| Timely Access | ED median LOS, admitted | ↓ | ≤5h | >5h | >6h | VP Ops |
| Smooth Throughput | Discharges before noon | ↑ | ≥40% | <40% | <30% | CNO |
| Right-Sized Capacity | Midnight bed occupancy | ↓ | ≤85% | >85% | >92% | House Sup |
| Engaged Workforce | Unfilled shifts / next 24h | ↓ | ≤30 | >30 | >45 | CNO |
| Reliable Care | Hand-hygiene compliance | ↑ | ≥90% | <90% | <85% | Quality |
| Financial Stewardship | Worked hours / UOS index | ↓ | ≤1.00 | >1.00 | >1.08 | CFO |
| Patient Experience | HCAHPS top-box | ↑ | ≥76% | <76% | <70% | CXO |
| Care Reliability | 30-day readmission rate | ↓ | ≤13.0% | >13% | >16% | CMO |

### 2.2 RTDC — Real-Time Demand & Capacity
RTDC ("bed huddle") is the capacity command discipline. Refresh: **1–5 min** from ADT.

**House summary (census strip):**
- **House census / licensed** — `occupied_beds / staffed_beds`. Occupancy %. warn >85%, crit >92%.
- **Available beds** — staffed, clean, unoccupied. Informational (normal).
- **Pending admits** — orders to admit without an assigned bed. Split ED / direct / transfer. watch.
- **Pending discharges** — potential + confirmed today; track "confirmed."
- **ED boarders** — admitted patients physically still in ED. crit driver. >4h count is the harm signal.
- **ICU occupancy** — ICU specifically; crit >90% (low elasticity).
- **Blocked beds** — physically present but unusable (staffing/iso/maintenance).

**Unit Capacity Board (per inpatient unit), drill detail:**
- `unit, census, capacity, occupancy%`, `admits_in, discharges_out, transfers, blocked, midnight_projection, status`.
- Occupancy bar colored by unit status (warn ≥90%, crit ≥95% for low-elasticity units).
- **Midnight projection** = `census + pending_admits − confirmed_discharges + net_transfers` over the horizon.

**Boarding & placement barriers (drill):** holding area (ED→ICU, ED→Tele, PACU→Floor, ICU→Stepdown),
patient count, longest wait, bed requested, **primary barrier** (no staffed bed / EVS cleaning / orders pending).

### 2.3 Emergency Department
Refresh: **1–2 min**. The ED panel leads with a crowding gauge because ED crowding is the canonical
whole-hospital failure signal.

- **NEDOCS** (National ED Overcrowding Score) — composite 0–200. Gauge scale = 200. Bands: ≤50 not busy ·
  51–100 busy · 101–140 overcrowded (warn) · 141–180 severe (crit) · >180 disaster. **Formula** (implement
  server-side): `NEDOCS = −20 + 85.8·(total_pts/beds) + 600·(admits/beds) + 13.4·vent_pts + 0.93·longest_admit_wait_hrs + 5.64·last_bed_time_hrs`.
- **Total in department** — all ED patients; show beds and waiting-room count.
- **Waiting room count** + avg wait. warn when avg wait high.
- **Door-to-provider median (min)** — arrival → first provider. ↓ target ≤30, warn >30.
- **LWBS %** (left without being seen) — `lwbs / total_arrivals`. ↓ target ≤2.0%, warn >2%, **crit >3%** (a
  safety + revenue red flag).
- **Admitted boarders** + longest boarding time (crit > a few hours).
- **Median ED LOS** split admitted / discharged. Admitted ≤5h, discharged ≤3h.
- **EMS inbound** — count + any critical (STEMI/stroke/trauma).
- **Ambulance diversion** — OFF/ON status (ON is bad).

**Track board (drill):** per patient `bed, ESI (1–5), chief_complaint, age/sex, LOS, provider, disposition,
status`. ESI rendered as a colored tag (1–2 crit, 3 warn, 4–5 normal). Dispositions containing "boarding" go red.
**Admitted boarders table (drill):** `patient, admitting_service, bed_requested, boarding_time, bed_status`.

### 2.4 Perioperative / OR
Refresh: **2–5 min** from the OR management system.

- **Prime-time utilization %** — used prime-time minutes / available prime-time minutes. Gauge. ↑ target ≥80.
- **First-case on-time start %** — ↑ target ≥85, warn <85.
- **Turnover time (min)** — wheels-out → wheels-in, same room. ↓ target ≤30.
- **Cases today** — scheduled / completed / in-progress / add-ons.
- **Cancellations** — same-day; track no-shows.
- **PACU occupancy & holds** — recovery bays in use, and patients held in PACU awaiting an inpatient bed
  (a downstream-capacity signal; warn).
- **Block utilization %** — block time used by block owners.

**OR room board (drill):** 18 suites — `room, status(Running/In Turnover/Idle/Closed), current_or_next_case,
service, surgeon, scheduled_start, delay, status`. Delay ≥20 min → crit, >0 → warn. Idle rooms in prime time → warn.
**PACU table (drill):** `bay, case, phase(I/II), time_in_pacu, status (HOLD flagged)`.

### 2.5 Staffing & Workforce
Refresh: **5–15 min** from scheduling/time-and-attendance + live census.

- **Unfilled shifts / next 24h** + fill %. crit driver of everything else.
- **Overtime %** — OT hours / worked hours. ↓ target ≤4%.
- **Agency / contract RNs** active + float-pool deployment.
- **Callouts** today + on-call activations.
- **Sitters / 1:1 observation** — demand signal (behavioral/falls).
- **Productivity %** — HPPD or worked-hours index (see Financial).

**Unit coverage (drill):** `unit, census, RN_scheduled, RN_needed, ratio_actual, ratio_target, open, OT_hours,
status`. **RN_needed** derived from census × acuity ÷ target ratio. Ratio worse than target → warn/crit.
**Open-shift requests (drill):** `unit, role, shift, start, incentive_tier, fill_status`.

### 2.6 Patient Flow & Transport
Refresh: **2–5 min** from ADT + transport + EVS systems.

- **Discharge before noon %** — ↑ target ≥40.
- **Discharge lounge** occupancy vs capacity.
- **Transport queue** — pending + in-progress.
- **Transport avg wait (min)** — request → pickup. ↓ target ≤15; show longest.
- **EVS bed turnaround (min)** — dirty → clean → ready. ↓ target ≤45 (≤30 for ICU/ED).
- **Dirty beds** count + in-progress.

**Transport queue (drill):** `request_id, time, from, to, mode, priority(STAT/Urgent/Routine), wait, status`.
**EVS by unit (drill):** `unit, dirty, in_progress, avg_turn, longest, target`.
**Discharge barriers (drill):** `patient, unit, barrier, owner, hours_held`.

### 2.7 Quality & Safety
Refresh: **15–60 min** (most are MTD measures) + real-time event feed for codes.

- **Sepsis bundle compliance** — 3-hr and 6-hr (SEP-1). ↑ target ≥90.
- **Hand-hygiene compliance %** — ↑ target ≥90 (one of the few `ok`-confirmed metrics).
- **Falls rate / 1000 patient-days** — ↓ target ≤3.0.
- **Rapid responses today** + Code Blue count.
- **HAI counts MTD** — CLABSI, CAUTI, C. diff, SSI, MRSA bacteremia, VAP — with **NHSN SIR** (observed/expected;
  SIR >1 → warn) and **days-since** counters.
- **HAPI (hospital-acquired pressure injury) stage 3+** count + days-since.
- **Med reconciliation %** — ↑ target ≥95.

**HAI/HAC ledger (drill):** `measure, cases_MTD, rate_per_1k, NHSN_benchmark, days_since, YTD, status`.
**Sepsis active cases (drill):** `patient/unit, onset, 3hr_bundle, 6hr_bundle, lactate, status`.
**Safety/code log (drill):** `time, event(Code Blue/Stroke/STEMI/Rapid/Fall/Med event), unit, severity, outcome`.

### 2.8 Service Lines / Throughput
Refresh: **hourly / daily**.

- **O:E LOS ratio** — observed / expected (GMLOS-based). ↓ target ≤1.0.
- **30-day readmission rate** — ↓ target ≤13%.
- **Avoidable days** — documented days beyond clinical need (MTD).
- **Case Mix Index** — acuity context (informational).
- **Observation rate %** + conversion to inpatient.
- **Discharges MTD** vs plan.

**Service line table (drill):** `service, census, ALOS, GMLOS, O:E, readmit%, avoidable_days, margin_index, status`.
**Top DRGs (drill):** `drg, description, cases, ALOS, O:E, readmit%, status`.

### 2.9 Labor & Financial Stewardship
Refresh: **hourly / daily**.

- **Worked hours / UOS index** — actual worked-hours-per-unit-of-service ÷ target. ↓ target ≤1.00. This is the
  single most important productivity number; it also appears as an OKR.
- **Premium pay $** today — overtime + agency + incentive.
- **Labor productivity %** — ↑ target ≥100.
- **Cost / OR case** vs budget.
- **Contract labor $** today.
- **Overtime %** (mirror of staffing).

**Cost-center productivity (drill):** `cost_center, worked_hours, UOS, HPPD/index, target, variance, status`.
**Premium-pay breakdown (drill):** `category(OT/Agency/Incentive/On-call/Total), today, WTD, vs_budget, status`.

---

## 3. Reference data shapes (the API contract)

The frontend should consume **pre-computed, status-tagged** data. Do **not** push raw rows and compute status in
React — compute it once on the server so every consumer (cockpit, mobile, paging) agrees. One snapshot endpoint
drives the overview; per-domain endpoints drive drills.

### 3.1 Canonical "metric value" object
Every scalar shown on the cockpit is a `MetricValue`:
```jsonc
{
  "key": "ed.nedocs",
  "label": "NEDOCS",
  "value": 142,              // raw number
  "display": "142",          // formatted string
  "unit": "",                // "min", "%", "k", ...
  "sub": "142 of 200 — severe",
  "status": "crit",          // normal | ok | watch | warn | crit  (server-computed)
  "target": 100,
  "direction": "down",       // up | down
  "trend": [120,128,134,138,140,141,142],  // optional sparkline series
  "trendLabel": "+38m",      // optional Δ
  "updatedAt": "2026-06-28T14:32:00Z"
}
```

### 3.2 Overview snapshot
`GET /api/cockpit/snapshot` → one document that paints the whole overview:
```jsonc
{
  "facility": { "name": "Riverbend Regional Medical Center", "licensedBeds": 612, "level": "II Trauma" },
  "capacityStatus": { "level": "SURGE — LEVEL 2", "code": "yellow", "status": "warn" },
  "asOf": "2026-06-28T14:32:00Z",
  "census": [ MetricValue, … ],          // 8 chips
  "alerts": [ { "status":"crit", "text":"ED OVERCROWDED — NEDOCS 142 …" }, … ],
  "okrs":   [ { "objective":"…","keyResult":"…", …MetricValue } , … ],
  "domains": {
    "rtdc":   { "gauge": {…}, "tiles":[MetricValue,…], "subGauges":[…] },
    "ed":     { "gauge": {…}, "tiles":[…], "esiMix":[{label,count,status},…] },
    "periop": { "gauge": {…}, "tiles":[…], "rooms":[{id,state},…] },
    "staffing":{ "tiles":[…], "ratios":[…] },
    "flow":   { "tiles":[…], "funnel":[{label,n},…] },
    "quality":{ "tiles":[…], "daysSince":[…], "codes":[…] },
    "service":{ "rows":[…], "tiles":[…] },
    "financial":{ "tiles":[…] }
  }
}
```

### 3.3 Domain drill
`GET /api/cockpit/drill/{domain}` → the modal payload:
```jsonc
{
  "title": "Emergency Department — Track Board",
  "sub":   "Live patient tracking, acuity and disposition · NEDOCS 142 (Severe)",
  "accent": "oklch(0.64 0.20 25)",
  "kpis":  [ MetricValue, … ],            // 6 summary tiles
  "tables":[ {
      "title":"Active Track Board",
      "note":"representative active patients",
      "cols":[ {"label":"Bed","flex":0.9,"align":"left"}, … ],
      "rows":[ [ Cell, Cell, … ], … ]     // see §6.4 for Cell
  }, … ]
}
```
`domain ∈ rtdc | ed | periop | staffing | flow | quality | service | financial | okr`.

---

## 4. The Status Engine (most important code you'll write)

Centralize threshold→status resolution. One function, used everywhere (API serializers, alert generation, the
OKR scorecard). Never hand-set a status in a query.

```php
// app/Services/StatusEngine.php
enum Status: string { case Normal='normal'; case Ok='ok'; case Watch='watch'; case Warn='warn'; case Crit='crit'; }

/**
 * A KPI definition carries direction + band edges. Edges are optional; only set the ones that apply.
 * direction 'down' => higher values are worse. 'up' => lower values are worse.
 */
function resolveStatus(float $value, array $def): Status {
  $dir = $def['direction'];           // 'up' | 'down'
  $crit = $def['crit'] ?? null;       // edge into crit
  $warn = $def['warn'] ?? null;
  $okEdge = $def['ok'] ?? null;       // optional: confirm "on target" green
  $cmp = fn($v,$edge) => $dir === 'down' ? $v >= $edge : $v <= $edge;
  if ($crit !== null && $cmp($value,$crit)) return Status::Crit;
  if ($warn !== null && $cmp($value,$warn)) return Status::Warn;
  if ($okEdge !== null && !$cmp($value,$okEdge)) return Status::Ok; // beat target comfortably
  return Status::Normal;
}
```

**Alert generation** is derived: after building the snapshot, collect every `MetricValue` whose status is
`warn`/`crit`, sort crit-first, and render the alert ticker + (optionally) fan out to paging/Teams. The alert
text templates live with the KPI definition so messages are consistent.

**Watch vs warn:** use `watch` (blue) for "trending toward a threshold / needs eyes" and `warn` (amber) for "off
target now." Reserve `crit` (red) for actionable, time-critical harm/throughput failures.

---

## 5. PostgreSQL 17 data model

Two layers: **(a) source/operational tables** (probably already partly exist in your app, or are synced from
EHR/ADT/OR/scheduling), and **(b) cockpit-serving tables** (definitions, snapshots, thresholds, audit). Keep the
serving layer thin and fast; the cockpit reads from materialized snapshots, not from live joins across the EHR.

### 5.1 Configuration & definitions
```sql
-- Threshold/target config, editable per facility. The status engine reads these.
CREATE TABLE kpi_definitions (
  id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  key           text NOT NULL UNIQUE,            -- 'ed.nedocs', 'rtdc.occupancy', ...
  domain        text NOT NULL,                   -- 'ed','rtdc','periop',...
  label         text NOT NULL,
  unit          text NOT NULL DEFAULT '',
  direction     text NOT NULL CHECK (direction IN ('up','down')),
  target        numeric,
  ok_edge       numeric,
  warn_edge     numeric,
  crit_edge     numeric,
  refresh_secs  int  NOT NULL DEFAULT 300,
  source_system text,                            -- 'ADT','EHR','ORM','Kronos','EVS',...
  alert_template text,                           -- 'ED OVERCROWDED — NEDOCS {value} …'
  is_active     boolean NOT NULL DEFAULT true,
  facility_id   bigint NOT NULL REFERENCES facilities(id),
  updated_at    timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE facilities (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name text NOT NULL, licensed_beds int, staffed_beds int, trauma_level text
);

CREATE TABLE units (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  facility_id bigint NOT NULL REFERENCES facilities(id),
  code text NOT NULL,                            -- 'ICU','5MSW',...
  name text NOT NULL,
  kind text,                                     -- 'icu','stepdown','medsurg','ed','or','pacu','peds','wcc'
  capacity int NOT NULL,
  target_ratio text,                             -- '1:4'
  low_elasticity boolean DEFAULT false,          -- ICU/ED: tighter occupancy thresholds
  UNIQUE (facility_id, code)
);
```

### 5.2 Operational fact tables (sampled time-series)
Prefer **append-only fact tables** sampled at each refresh; compute deltas/trends from them. Partition by day.
```sql
CREATE TABLE unit_census_samples (
  unit_id     bigint NOT NULL REFERENCES units(id),
  sampled_at  timestamptz NOT NULL,
  census int, capacity int, blocked int,
  admits_in int, discharges_out int, transfers int,
  midnight_projection int,
  PRIMARY KEY (unit_id, sampled_at)
) PARTITION BY RANGE (sampled_at);

CREATE TABLE ed_state_samples (
  facility_id bigint NOT NULL, sampled_at timestamptz NOT NULL,
  total_pts int, beds int, waiting int, vent_pts int,
  admits int, boarders int, boarders_over_4h int,
  longest_admit_wait_min int, door_to_provider_median_min int,
  lwbs_pct numeric, los_admit_min int, los_discharge_min int,
  nedocs numeric, diversion boolean,
  PRIMARY KEY (facility_id, sampled_at)
) PARTITION BY RANGE (sampled_at);

-- live per-patient board for drills (no PHI in the cockpit beyond what staff already see on a tracking board;
-- enforce access control + audit; consider de-identified initials/room only)
CREATE TABLE ed_track_board (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  facility_id bigint NOT NULL, bed text, esi int,
  chief_complaint text, age_sex text, arrived_at timestamptz,
  provider text, disposition text, status text, updated_at timestamptz
);

CREATE TABLE or_room_board (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  facility_id bigint NOT NULL, room text, state text,         -- running|turnover|idle|closed
  case_label text, service text, surgeon text,
  scheduled_start time, delay_min int, status text, updated_at timestamptz
);

CREATE TABLE staffing_coverage (
  unit_id bigint REFERENCES units(id), shift date, period text, -- 'day'|'night'
  census int, rn_scheduled int, rn_needed int,
  ratio_actual text, ratio_target text, open_shifts int, ot_hours numeric,
  PRIMARY KEY (unit_id, shift, period)
);

CREATE TABLE transport_requests (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  facility_id bigint, requested_at timestamptz, from_loc text, to_loc text,
  mode text, priority text, picked_up_at timestamptz, status text
);

CREATE TABLE evs_bed_turnaround (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  unit_id bigint REFERENCES units(id), bed text,
  dirtied_at timestamptz, started_at timestamptz, ready_at timestamptz, status text
);

CREATE TABLE quality_events (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  facility_id bigint, occurred_at timestamptz, type text, unit_id bigint,
  severity text, outcome text
);

CREATE TABLE hai_ledger (
  facility_id bigint, measure text, period date,      -- monthly
  cases_mtd int, rate_per_1k numeric, nhsn_sir numeric,
  days_since int, ytd int,
  PRIMARY KEY (facility_id, measure, period)
);

CREATE TABLE service_line_los (
  facility_id bigint, service text, period date,
  census int, alos numeric, gmlos numeric, oe_ratio numeric,
  readmit_pct numeric, avoidable_days int, margin_index numeric,
  PRIMARY KEY (facility_id, service, period)
);

CREATE TABLE cost_center_productivity (
  facility_id bigint, cost_center text, period date,
  worked_hours numeric, uos numeric, hppd_index numeric, target numeric, variance numeric,
  PRIMARY KEY (facility_id, cost_center, period)
);
```

### 5.3 Serving layer
```sql
-- Pre-rendered overview snapshot (one row per facility, replaced each refresh). JSONB = the §3.2 document.
CREATE TABLE cockpit_snapshots (
  facility_id bigint PRIMARY KEY REFERENCES facilities(id),
  payload jsonb NOT NULL,
  generated_at timestamptz NOT NULL DEFAULT now()
);

-- Optional: persisted alerts for paging + history.
CREATE TABLE cockpit_alerts (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  facility_id bigint, key text, status text, text text,
  opened_at timestamptz, cleared_at timestamptz
);
```

> Use **materialized views** for the heavier daily/MTD aggregations (HAI SIR, O:E LOS, productivity) and
> `REFRESH MATERIALIZED VIEW CONCURRENTLY` on a schedule. The fast 1–5 min metrics come from the latest
> `*_samples` rows.

---

## 6. Laravel backend

### 6.1 Structure
```
app/
  Domain/Cockpit/
    Metrics/          # one class per metric or per domain: compute raw value + trend
      EdMetrics.php
      RtdcMetrics.php
      PeriopMetrics.php
      StaffingMetrics.php
      FlowMetrics.php
      QualityMetrics.php
      ServiceLineMetrics.php
      FinancialMetrics.php
      OkrMetrics.php
    StatusEngine.php  # §4
    SnapshotBuilder.php   # assembles the §3.2 document, tags status, derives alerts
    DrillBuilder.php      # builds §3.3 per-domain table payloads
  Http/Controllers/Api/CockpitController.php
  Jobs/RefreshCockpitSnapshot.php   # scheduled; writes cockpit_snapshots + cockpit_alerts
  Models/ (Eloquent for the tables above)
```

### 6.2 Endpoints
```
GET  /api/cockpit/snapshot              -> cockpit_snapshots.payload (cached, ETag on generated_at)
GET  /api/cockpit/drill/{domain}        -> DrillBuilder::build($domain)
GET  /api/cockpit/kpi-definitions       -> for an admin/threshold editor
PUT  /api/cockpit/kpi-definitions/{key} -> update target/edges (audited)
GET  /api/cockpit/stream                -> SSE: emits 'snapshot' events (see §7)
```
All read endpoints: facility-scoped, auth required, rate-limited. The snapshot is **read-only and pre-computed**;
the request is a cache lookup, not a query storm.

### 6.3 Refresh job
```php
// Scheduled per the shortest relevant cadence (e.g. every minute); each metric class respects its own
// refresh_secs and returns cached value if not due.
class RefreshCockpitSnapshot implements ShouldQueue {
  public function handle(SnapshotBuilder $b): void {
    foreach (Facility::active()->get() as $f) {
      $payload = $b->build($f);                         // pulls latest samples + MVs, tags status, derives alerts
      DB::table('cockpit_snapshots')->updateOrInsert(
        ['facility_id' => $f->id],
        ['payload' => json_encode($payload), 'generated_at' => now()]
      );
      event(new CockpitSnapshotUpdated($f->id));         // -> broadcast/SSE
    }
  }
}
```
Schedule in `routes/console.php`: `Schedule::job(RefreshCockpitSnapshot::class)->everyMinute()->withoutOverlapping();`

### 6.4 DataTable Cell contract (drives all drill tables)
The reference uses a tiny, powerful cell grammar. Reproduce it so one `<DataTable>` renders every domain. A
**row** is `Cell[]`; a **Cell** is one of:
```ts
type Cell =
  | string | number                                   // plain text
  | { v: string; mono?: boolean; strong?: boolean; dim?: boolean; status?: Status; color?: string }
  | { bar: { pct: number; status: Status; label?: string } }  // inline meter
  | { chip: Status }                                  // status shape+color
  | { tag: { text: string; status: Status } };        // bordered pill (ESI, priority)
```
Columns: `{ label, flex, align: 'left'|'right'|'center' }`. The server emits these directly in the drill payload
(see `DrillBuilder`), so the React table is purely presentational.

---

## 7. Real-time delivery

The cockpit is a wall display; it must update without user action and survive overnight.
- **Primary:** Server-Sent Events (`/api/cockpit/stream`) or Laravel Echo/Reverb (WebSocket). On
  `CockpitSnapshotUpdated`, push the new snapshot (or just a "reload" ping). SSE is simplest for a one-way feed.
- **Fallback:** poll `GET /api/cockpit/snapshot` every 30–60s with ETag/304.
- **Resilience:** the React shell shows a "stale — last sync HH:MM:SS" indicator if no update arrives within
  2× the expected interval. Never blank the screen on a fetch error; keep last-good and flag staleness.
- **Clock:** render locally at 1Hz; don't round-trip for the time.

---

## 8. React frontend

### 8.1 Component tree
```
<Cockpit>                         // fetches snapshot, owns SSE subscription + drill state
  <CommandBar/>                   // facility, capacity-status pill, live clock, LIVE/stale indicator
  <CensusStrip chips/>            // 8 CensusChip
  <AlertTicker alerts/>           // marquee; crit-first
  <OkrScorecard okrs onDrill/>    // 7 OkrCard (each clickable -> drill 'okr')
  <DomainGrid>
    <Column>
      <Panel domain="rtdc"  onDrill> <RtdcBody/> </Panel>
      <Panel domain="flow"  onDrill> <FlowBody/> </Panel>
    </Column>
    <Column>
      <Panel domain="ed"     onDrill accent="crit"> <EdBody/> </Panel>
      <Panel domain="periop" onDrill> <PeriopBody/> </Panel>
    </Column>
    <Column>
      <Panel domain="staffing"  onDrill> <StaffingBody/> </Panel>
      <Panel domain="quality"   onDrill> <QualityBody/> </Panel>
      <Panel domain="service"   onDrill> <ServiceBody/> </Panel>
      <Panel domain="financial" onDrill> <FinancialBody/> </Panel>
    </Column>
  </DomainGrid>
  <Legend/>
  <DrillModal domain payload onClose/>   // backdrop click / ESC / button close
</Cockpit>
```

### 8.2 State
- `snapshot` (from SSE/poll). Single source for the overview. No per-panel fetching.
- `drill: { domain } | null`. Opening fetches `/drill/{domain}` (or include drills in the snapshot if cheap).
- `now` (1Hz tick) for the clock; `lastSyncAt` + derived `isStale`.
- Drill close: backdrop `onClick`, an `Escape` keydown listener, and the close button — all set `drill=null`.

### 8.3 Status → style helper (mirror of §4)
```ts
export function statusStyle(s: Status, role: 'value'|'chip'|'bar'|'text') {
  const def = color.status[s];
  if (role === 'value') {
    // ISA rule: only abnormal values take color; ok=green; normal/watch stay near-white
    const c = s === 'crit' || s === 'warn' ? def.color : s === 'ok' ? def.color : color.textHi;
    return { color: c };
  }
  if (role === 'chip') return { color: def.color };
  if (role === 'bar')  return { background: s === 'normal' ? def.track : def.color };
  return { color: def.color };
}
```

### 8.4 Gauge (SVG donut)
Render the arc as a conic gradient or stroked SVG circle: `arc = clamp(value/scale)·360°`. Center shows the big
number + a small caps label. ED NEDOCS uses `scale=200`; occupancy/util use `scale=100`. Color the arc by the
metric's status. Keep a visible track (`color.gaugeTrack`) for the unfilled remainder.

### 8.5 Accessibility / wall-display checklist
- Every status chip has an `aria-label` ("critical", "warning", …) — color is never the only cue (also satisfied
  by the glyph).
- Tables are real `<table>`/role-grid with header cells; the reference's flex rows should become semantic tables
  in production.
- Respect `prefers-reduced-motion`: disable the ticker marquee and pulses, keep value transitions instant.
- Contrast: body text ≥ 4.5:1 on panel surfaces; status colors chosen at consistent OKLCH lightness for parity.
- Provide a **scale control / zoom** for wall mounting (root font-size multiplier) and a high-contrast variant.

---

## 9. Build order (recommended)

1. **Theme + primitives**: tokens, `StatusChip`, `Tile`, `MeterBar`, `Sparkline`, `RadialGauge`, `DataTable`,
   `Panel`, `DrillModal`. Storybook them against the reference screenshots.
2. **StatusEngine + kpi_definitions** seed (every KPI from §2 with default edges). This unlocks correct color
   everywhere.
3. **SnapshotBuilder** with **mocked** metric classes returning the reference numbers — gets the full overview
   pixel-matching the prototype fast, before any EHR integration.
4. **DrillBuilder** + the 9 drill payloads (same mocked data).
5. **Frontend** wired to `/snapshot` + `/drill/{domain}` + SSE. Match the reference exactly.
6. **Swap mocks for real sources** one domain at a time, easiest first: RTDC/census (ADT) → Flow/EVS/Transport →
   Staffing → ED → Periop → Quality → Service Lines → Financial. Each metric class is independently testable.
7. **Alerting fan-out** (paging/Teams) off the derived warn/crit set.
8. **Admin threshold editor** against `kpi_definitions` (so clinicians tune bands without a deploy).
9. **Wall-display hardening**: SSE reconnect, staleness banner, kiosk mode, reduced-motion, zoom.

---

## 10. Fidelity notes (match the reference exactly)

- Census strip is **8 chips**; OKR scorecard is **7 cards** on the overview (9 rows in the OKR drill).
- Domain accent swatch is cyan `oklch(0.72 0.13 200)` for all panels **except ED**, which uses the crit red
  swatch + a red-tinted header to signal it's the hottest domain.
- The reference's demo numbers are deliberately tuned to show **multiple active alert states** (ED severe,
  boarders, 5 Med/Surg West understaffed, EVS slow). Keep a "demo/seed" facility with these values for
  screenshots and acceptance tests.
- Drill modal: max-width 1280px, max-height 88vh, internal scroll, KPI strip (6 tiles) above detail tables.
  Close via ESC / backdrop / button.
- Every number that is a time or ratio is **monospaced**; every label is **uppercase tracked sans**.

---

## Appendix A — KPI key registry (use these exact keys)
```
okr.sepsis_3hr  okr.ed_los_admit  okr.dc_before_noon  okr.occupancy_midnight
okr.open_shifts okr.hand_hygiene  okr.worked_per_uos  okr.hcahps  okr.readmit_30d

rtdc.census rtdc.available rtdc.pending_admits rtdc.pending_dc rtdc.boarders rtdc.icu_occupancy rtdc.blocked_beds rtdc.occupancy

ed.nedocs ed.in_dept ed.waiting ed.door_to_provider ed.lwbs ed.boarders ed.los_admit ed.los_discharge ed.ems_inbound ed.diversion

periop.prime_util periop.first_case_ontime periop.turnover periop.cases periop.cancellations periop.pacu_holds periop.block_util

staffing.open_shifts staffing.overtime staffing.agency staffing.callouts staffing.sitters staffing.productivity

flow.dc_before_noon flow.discharge_lounge flow.transport_queue flow.transport_wait flow.bed_turnaround flow.dirty_beds

quality.sepsis_3hr quality.sepsis_6hr quality.hand_hygiene quality.falls_rate quality.rapid_response quality.med_rec
quality.clabsi quality.cauti quality.cdiff quality.ssi quality.mrsa quality.vap quality.hapi

service.oe_los service.readmit_30d service.avoidable_days service.cmi service.observation_rate service.discharges_mtd

financial.worked_per_uos financial.premium_pay financial.productivity financial.cost_per_case financial.contract_labor financial.overtime
```

## Appendix B — Glossary
**RTDC** real-time demand & capacity · **ADT** admit/discharge/transfer feed · **NEDOCS** National ED Overcrowding
Score · **ESI** Emergency Severity Index (1 sickest–5) · **LWBS** left without being seen · **LOS** length of stay
· **GMLOS** geometric mean LOS · **O:E** observed-to-expected · **PACU** post-anesthesia care unit · **HPPD** hours
per patient day · **UOS** unit of service · **HAI/HAC** healthcare-associated infection / condition · **SIR**
standardized infection ratio (NHSN) · **HAPI** hospital-acquired pressure injury · **CLABSI/CAUTI/CDI/SSI/VAP**
infection types · **SEP-1** CMS sepsis bundle measure · **HCAHPS** patient-experience survey.
