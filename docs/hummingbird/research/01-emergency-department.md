# Hummingbird Research 01 — Emergency Department (ED) Subsystem

**Source app:** Zephyrus (Laravel 11 + React/Inertia, PostgreSQL `prod` schema)
**Purpose:** Exhaustive inventory of ED data elements, endpoints, pages, actions, real-time aspects, roles, and per-feature mobile relevance, feeding the Hummingbird mobile companion (Kotlin/Android + Swift/iOS) implementation plan.
**Date:** 2026-06-26

---

## 0. Executive Summary & Critical Caveat

The ED subsystem in Zephyrus has a **two-tier reality** that Hummingbird's plan must account for:

1. **The ED-branded UI (`/ed/*` + `/dashboard/emergency`) is almost entirely mock / placeholder.** The dedicated ED dashboard renders from a static JS mock file (`resources/js/mock-data/ed.js`), and **8 of the 9 ED sub-pages are "coming soon" placeholders** (`EDPlaceholder`). Only `ED/Analytics/Flow` is real (it embeds the cross-app Patient Flow 4D navigator). There are **no ED-specific API endpoints and no ED mutation endpoints** — the ED controller only renders Inertia pages with no data.

2. **The *real*, live ED data lives in the backend services layer**, computed from genuine DB tables `prod.ed_visits` and `prod.diversion_events`, and is surfaced **through the Command Center / Operations Analytics APIs** (not through the ED pages). Metrics like Door-to-Provider, LWBS %, ED Boarding, ED LOS, and Diversion hours are computed with real SQL (percentiles, dispositions) and consumed by the enterprise command-center dashboard, the OKR scoreboard, the AI ops agents, and the analytics drill-downs.

**Implication for Hummingbird:** The most valuable, defensible ED mobile experience is built on the **real backend ED metrics (tier 2)**, surfaced as glanceable status + notifications — *not* on porting the mock ED dashboard screens (tier 1). The ED page UI is a design skeleton; the data model and the analytics/command-center APIs are the substance.

### Counts at a glance
| Item | Count | Notes |
|---|---|---|
| ED-relevant DB models / tables | **2 primary** (`EdVisit`, `DiversionEvent`) + ~5 referenced (`units`, `bed_requests`, `encounters`, `census_snapshots`, `transport_requests`) | |
| ED Inertia routes (web) | **10** (1 dashboard + 9 sub-pages) | 8 sub-pages are placeholders |
| ED controller methods | **10** (all `Inertia::render`, zero data/zero mutations) | |
| ED-specific API endpoints | **0** | ED data flows via Command Center + Analytics + Patient Flow APIs |
| APIs that surface real ED data | **3 families** (`/api/command-center/drilldown`, `/api/analytics/*`, `/api/patient-flow/*`) | |
| ED user mutations in app | **0** (no triage entry, status change, disposition, or assignment UI exists) | |
| Real-time ED channels | **0 ED-specific** (RTDC public channels carry house census, not ED board) | |

---

## 1. Data Elements (Models, Fields, Types, Relationships)

### 1.1 `EdVisit` — `prod.ed_visits` (PRIMARY ED entity)
File: `app/Models/EdVisit.php` · Migration: `database/migrations/2026_06_22_000010_create_ed_visits_table.php`

The canonical ED patient-visit record and the lifecycle timestamp spine (arrival → triage → provider → admit decision → bed → departure). `$guarded = []` (mass-assignable).

| Column | Type | Cast | Meaning / constraints |
|---|---|---|---|
| `ed_visit_id` | bigint PK | — | Primary key |
| `patient_ref` | string | — | **Pseudonymous** patient ref (e.g. `sim-ed-0001`). No PHI. |
| `arrived_at` | timestamp | datetime | ED arrival / door time (clock start). Indexed. |
| `triaged_at` | timestamp nullable | datetime | Triage completion time |
| `esi_level` | tinyint nullable | integer | **ESI acuity 1–5** (DB CHECK `BETWEEN 1 AND 5`). 1 = resuscitation … 5 = non-urgent |
| `provider_seen_at` | timestamp nullable | datetime | First provider contact (drives Door-to-Provider) |
| `disposition` | string nullable | — | CHECK in (`admitted`,`discharged`,`lwbs`,`transfer`,`eloped`); **NULL = still in ED**. Indexed. |
| `admit_decision_at` | timestamp nullable | datetime | **Boarding clock START** (admit decision made) |
| `bed_assigned_at` | timestamp nullable | datetime | **Boarding clock END** (inpatient bed assigned). `admitted` + NULL ⇒ *currently boarding*. |
| `departed_at` | timestamp nullable | datetime | ED departure (clock end; drives ED LOS) |
| `unit_id` | bigint FK nullable | — | Admitting inpatient unit → `prod.units` |
| `created_at`/`updated_at` | timestamps | — | `updated_at` is the freshness column for the `ed_flow` source |
| `is_deleted` | boolean | boolean | Soft-delete (default false); every query filters `is_deleted = false` |

**Relationship:** `unit(): belongsTo(Unit, unit_id)` — the admitting unit.

**Derived metrics computed from this table (see §2.3):**
- **Door-to-Provider (D2P):** median minutes `provider_seen_at − arrived_at` (24h or 30d window).
- **LWBS rate:** `disposition='lwbs'` count / total arrivals; also tracks **ESI 1–2 LWBS** (high-acuity walkouts — critical signal).
- **ED LOS (discharged):** median minutes `departed_at − arrived_at` where `disposition='discharged'`.
- **ED Boarding count:** rows where `disposition='admitted' AND bed_assigned_at IS NULL`.
- **ED Boarding minutes (proxy):** median `bed_assigned_at − admit_decision_at` for admitted (OKR baseline 192 min → target 120 min).
- **ED arrivals by week** (30d trend).

### 1.2 `DiversionEvent` — `prod.diversion_events`
File: `app/Models/DiversionEvent.php` · Migration: `database/migrations/2026_06_22_000030_create_diversion_events_table.php`

Ambulance/ED diversion episodes (a high-signal operational + reputational event).

| Column | Type | Cast | Meaning |
|---|---|---|---|
| `diversion_event_id` | bigint PK | — | Primary key |
| `scope` | string (default `ed`) | — | CHECK in (`ed`,`hospital`) |
| `unit_id` | bigint FK nullable | — | → `prod.units` |
| `started_at` | timestamp | datetime | Diversion start |
| `ended_at` | timestamp nullable | datetime | **NULL = ongoing/active diversion** |
| `reason` | string nullable | — | Free-text reason |
| `created_at`/`updated_at`/`is_deleted` | — | — | Standard |

**Relationship:** `unit(): belongsTo(Unit, unit_id)`.
**Derived:** **Diversion hours (last 24h)** = summed overlap of events with the trailing-24h window (`CommandCenterDataService::computeOutcomesMetrics`).

### 1.3 Referenced / adjacent tables (ED context but not ED-owned)
- `prod.units` — the ED unit (type/identity); boarding admits target non-ED units.
- `prod.bed_requests` — pending inpatient admissions (drives "pending admits", net beds, placement cycle). ED boarding is the demand side of this.
- `prod.encounters` — inpatient admissions/LOS/discharge-by-noon (downstream of ED admits).
- `prod.census_snapshots` — house occupancy/staffed/blocked beds (capacity context for ED boarding risk).
- `prod.transport_requests` — at-risk transports (combined with ED boarders into capacity risk score).

### 1.4 Front-end mock data shape (`resources/js/mock-data/ed.js`)
Drives the existing ED dashboard UI only (not persisted). Useful as a **spec of what an ED clinician/charge view *wants* to show**, even though it's static:
- `edMetrics.currentStatus`: `totalPatients, capacity, occupancy%, waitingRoom, averageWaitTime, criticalCases`.
- `edMetrics.triageCategories`: `{resuscitation, emergent, urgent, semiUrgent, nonUrgent}` each `{count, maxWaitTime, targetTime}`.
- `edMetrics.throughput`: `lastHour` & `today` → `{arrivals, discharges, admissions, leftWithoutBeingSeen}`.
- `edMetrics.staffing`: `current`/`nextShift` → `physicians/nurses/techs {scheduled, present, required}`.
- `edMetrics.waitTimes`: `current`/`targets` → `{doorToTriage, doorToProvider, doorToDisposition, doorToDeparture}` + hourly `trends`.
- `edMetrics.resources.beds`: `{total, occupied, cleaning, available, categories{trauma, acute, fastTrack, behavioral, isolation}}`.
- `edMetrics.resources.equipment`: `{ventilators, monitors, portableXray} {total, inUse}`.
- `edMetrics.predictions`: hourly `arrivals`, `admissions{probability, predictedCount, byService}`, `bottlenecks[]{resource, probability, timeframe, impact}`.
- `alertsData.alerts[]`: `{id, type(critical|warning|info), title, message, timestamp}`.
- `patientStatusBoard[]`: `{id, location, chiefComplaint, triageLevel, waitTime, status, nextAction, provider}`.
- `performanceMetrics`: `doorToProvider, lengthOfStay{admitted,discharged}, leftWithoutBeingSeen, patientSatisfaction` each `{current, target, trend, trendValue}`.

> Note: the mock includes richer concepts (waiting-room count, equipment, staffing present-vs-required, chief complaint, provider assignment, patient satisfaction) that have **no backing table** in `prod`. These are aspirational and would require new data sources for Hummingbird to surface real values.

---

## 2. API / Web Endpoints

### 2.1 ED Inertia (web) routes — `routes/web.php` (all GET, auth + workflow=emergency)
Controller: `app/Http/Controllers/EDDashboardController.php`. **Every method only renders an Inertia page; none accept params or return data, and none mutate.**

| Method | Path | Route name | Controller method | Renders | Status |
|---|---|---|---|---|---|
| GET | `/dashboard/emergency` | `dashboard.emergency` | `index` | `Dashboard/ED` | **Built (mock data)** |
| GET | `/ed/operations/triage` | `ed.operations.triage` | `triage` | `ED/Operations/Triage` | Placeholder |
| GET | `/ed/operations/treatment` | `ed.operations.treatment` | `treatment` | `ED/Operations/Treatment` | Placeholder |
| GET | `/ed/operations/resources` | `ed.operations.resources` | `resourceManagement` | `ED/Operations/Resources` | Placeholder |
| GET | `/ed/analytics/wait-time` | `ed.analytics.wait-time` | `waitTime` | `ED/Analytics/WaitTime` | Placeholder |
| GET | `/ed/analytics/flow` | `ed.analytics.flow` | `flow` | `ED/Analytics/Flow` | **Built** (Patient Flow navigator) |
| GET | `/ed/analytics/resources` | `ed.analytics.resources` | `resources` | `ED/Analytics/Resources` | Placeholder |
| GET | `/ed/predictions/arrival` | `ed.predictions.arrival` | `arrival` | `ED/Predictions/Arrival` | Placeholder |
| GET | `/ed/predictions/acuity` | `ed.predictions.acuity` | `acuity` | `ED/Predictions/Acuity` | Placeholder |
| GET | `/ed/predictions/resources` | `ed.predictions.resources` | `resourcePlanning` | `ED/Predictions/Resources` | Placeholder |

`index()` also does `session()->put('workflow','emergency')`. Workflow switching: `GET /set-preference/{workflow}` (`emergency` allowed) and `ChangeWorkflowRequest` (`in:...,emergency,...`).

### 2.2 Real ED data — exposed via Analytics API — `routes/api.php` (`web,auth,throttle:60,1`)
Controller: `app/Http/Controllers/Api/AnalyticsController.php` → `app/Services/Analytics/OperationsAnalyticsService.php`.

| Method | Path | Service method | ED data returned |
|---|---|---|---|
| GET | `/api/analytics/overview` | `overview()` | Rolls up live+retro+predictive incl. ED metrics |
| GET | `/api/analytics/live` | `live()` → `liveSummary()` | **ED Boarding count** (admitted, no bed) folded into capacity/risk |
| GET | `/api/analytics/retrospective` | `retrospective()` | **ED Visits (30d), LWBS Rate %, Door-to-Provider median**, ED arrivals-by-week trend |
| GET | `/api/analytics/predictive` | `predictive()` | Surge probability (uses ED boarding as an input) |
| GET | `/api/analytics/process-intelligence` | `processIntelligence()` | Placement cycle, event coverage (ED→bed flow) |
| GET | `/api/analytics/opportunities` | `opportunities()` | Recommendations incl. ED boarding/LWBS actions |
| GET | `/api/analytics/data-quality` | `dataQuality()` | "ED timestamp completeness" check (provider_seen coverage) |
| GET | `/api/analytics/metrics/{metricKey}/lineage` | `metricLineage()` | Lineage for `ed_visits`/`lwbs`/D2P metrics |

Response shape (analytics): `{generatedAtIso, section, metrics:[{label,value,unit,status,description}], trends{...}, sourceMap{...}}`. Status band is one of `success|warning|critical|info` and is the **status-not-by-color-alone** signal Hummingbird should reuse.

### 2.3 Real ED data — exposed via Command Center drill-down API
Controller: `app/Http/Controllers/CommandCenterController.php` → `app/Services/CommandCenterDataService.php` (+ `CommandCenterDrilldownService`).

| Method | Path | Purpose | ED-relevant keys |
|---|---|---|---|
| GET | `/api/command-center/drilldown?metric={key}` | Drill-down detail for a command-center metric | ED metric keys: `ed_boarding`, `ed_d2p`, `ed_lwbs`, `ed_los`, `diversion`, plus `adm_to_bed`, `surge_prob` |

`CommandCenterDataService` (the enterprise command center board, served via the page controller, not a dedicated API) computes a **flow band**, **outcomes band**, **hero metrics**, and an **OKR scoreboard** that include:
- `ed_boarding` (count), `ed_d2p` (median min), `ed_lwbs` (%), `ed_los` (median min), `diversion` (hours, 24h), and **daily LWBS trend** (`dailyLwbsTrend`).
- Hero tile `ed_boarding` ("ED Boarding", status critical if ≥6, warning if >0) linking to `/dashboard/emergency`.
- Strain score factors in ED boarding (≥6 boarders ⇒ +1 strain).
- OKR "Improve access & flow" → **ED boarding** key result (192→<120 min) + Discharge-by-noon.

Drill-down `CommandCenterDrilldownService` maps ED keys to remediation guidance, e.g.:
- `ed_boarding`/`adm_to_bed` → "Prioritize bed assignment, inpatient acceptance, safety checks for admitted ED patients."
- `ed_d2p`/`ed_lwbs`/`ed_los` → "Rebalance front-end ED resources, fast-track low-acuity flow, review waiting-room risk."

### 2.4 Real ED data — exposed via Patient Flow API (the one built ED page)
Controller: `app/Http/Controllers/Api/PatientFlow/PatientFlowController.php` (+ stream/ingest). Used by `ED/Analytics/Flow` → `PatientFlowNavigator`.

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/patient-flow/summary` | Counts: messages, normalized events, patients, locations, movement events |
| GET | `/api/patient-flow/locations` | Navigator locations (floors/spaces incl. ED) |
| GET | `/api/patient-flow/events` | Flow events (ADT/movement) |
| GET | `/api/patient-flow/tracks` | Patient movement tracks |
| GET | `/api/patient-flow/state` | Current projected patient state |
| GET | `/api/patient-flow/ambient` | Ambient signals |
| GET | `/api/patient-flow/fhir/bundle` | FHIR bundle export |
| **GET (SSE)** | `/api/patient-flow/stream/adt` | **Server-Sent-Events ADT stream** (the only streaming endpoint in scope) |
| POST | `/api/patient-flow/ingest/hl7v2` | HL7v2 ingest (write path, system-to-system) |

> Patient Flow data is sourced from `flow_core.*` / `raw.inbound_messages` (a separate synthetic-flow EHR pipeline), distinct from `prod.ed_visits`. It tracks patient *movement* through the building, including the ED as floor-1 locations.

### 2.5 AI Ops Agents that read ED data — `routes/api.php` `/api/ops/*`
`app/Services/Ops/Agents/AgentToolRegistry.php` (`capacity.snapshot` tool) surfaces **`edBoarders`** (admitted + no bed) inside a risk score, returned by:
- `GET /api/ops/agent-inbox`, `GET /api/ops/recommendations`, and `POST /api/ops/agents/capacity-commander/run`.
- `OperationsRecommendationService` and `OperationsSimulationService` also read `prod.ed_visits` for boarding pressure; `OperationsGraphProjector` projects ED visits into the ops graph (`ops.nodes`).

---

## 3. Pages / Features

### 3.1 `Dashboard/ED` — Emergency Department dashboard (the one substantive ED screen)
Route `/dashboard/emergency` · `resources/js/Pages/Dashboard/ED.jsx` · **mock data** (`mock-data/ed.js`). Layout: `DashboardLayout` + `PageContentLayout`.

| Section / component | Content (metrics & visuals) | User-facing actions |
|---|---|---|
| **Current Status** card | Total Patients (+occupancy% trend), Waiting Room (+avg wait), Triage Categories list (resuscitation/emergent/urgent/… with count + target time, color dot) | View only |
| **Performance Metrics** card | Door-to-Provider (vs target), Left-Without-Being-Seen % (vs target), Wait-Time hourly TrendChart | View only |
| **Patient Status Board** table | Per patient: Location, Chief Complaint, Triage Level (color pill), Wait Time, Next Action, Provider | View only (no row actions/edit) |
| **ResourceManagement** (`Components/ED/ResourceManagement.jsx`) | Beds total/occupied + occupancy% pill; bed categories (trauma/acute/fastTrack/behavioral/isolation) availability with status dot; equipment (ventilators/monitors/portableXray) % in-use | View only |
| **AlertsAndPredictions** (`Components/ED/AlertsAndPredictions.jsx`) | Active Alerts list (critical/warning/info + time); Predictions: expected arrivals by hour, predicted admissions by service, potential bottlenecks (resource, % probability, timeframe) | View only (alerts are not acknowledgeable) |

### 3.2 `ED/Analytics/Flow` — Patient Flow (BUILT, real)
Route `/ed/analytics/flow` · `resources/js/Pages/ED/Analytics/Flow.jsx` → `PatientFlowNavigator` (full-screen, own `TopNavbar`, dark-mode aware). A 4D (space+time) navigator of patient movement through the facility, backed by `/api/patient-flow/*` incl. the live SSE ADT stream. Heavy, map-like, interactive — analytical.

### 3.3 The 8 placeholder pages (`Components/ED/EDPlaceholder.jsx` — "coming soon")
All render a Construction icon + "This Emergency Department view is coming soon." No data, no actions.

| Route | Title / intended purpose |
|---|---|
| `/ed/operations/triage` | **Triage** — "Manage triage operations and patient prioritization" |
| `/ed/operations/treatment` | **Treatment** — "Oversee treatment procedures and protocols" |
| `/ed/operations/resources` | **Resource Management** — "Manage ED resources and staffing" |
| `/ed/analytics/wait-time` | **Wait Time** — "Monitor and analyze ED patient wait times" |
| `/ed/analytics/resources` | **Resource Analytics** — "Analyze ED resource utilization" |
| `/ed/predictions/arrival` | **Arrival Prediction** — "Forecast patient arrivals to the ED" |
| `/ed/predictions/acuity` | **Acuity Prediction** — "Forecast patient acuity mix" |
| `/ed/predictions/resources` | **Resource Optimization** — "Optimize resource allocation from predictions" |

The navigation (`resources/js/config/navigationConfig.ts`, `key:'emergency'`, icon Siren) groups these as **Operations** (Triage/Treatment/Resources), **Analytics** (Wait Time/Patient Flow/Resources), **Predictions** (Arrival/Acuity/Resources). This menu is the intended ED feature taxonomy — a useful blueprint for Hummingbird's ED tab structure even though most targets are unbuilt.

---

## 4. User Actions / Mutations

**There are ZERO ED-specific mutations in the application today.** No endpoint or UI exists for triage entry, ESI assignment, status/disposition changes, provider assignment, bed assignment, or diversion start/stop. The ED controller is read-only render; `routes/api.php` has **no** `/ed` POST/PUT/PATCH routes.

The ED-adjacent **mutations that *do* exist** live in neighboring subsystems and act on the *downstream* of ED admits (relevant because ED boarding is resolved here):
- **Bed requests** (`/api/rtdc/bed-requests` POST, `/decision` POST) — create an inpatient bed request and decide placement (resolves boarding).
- **Huddles / barriers** (`/api/rtdc/huddles`, `/api/rtdc/barriers` + `/resolve`) — capacity coordination & blocker resolution.
- **Transport requests** (`/api/transport/requests` + assign/status/cancel/handoff) — move admitted ED patients.
- **Ops actions/approvals** (`/api/ops/actions/{id}/assign|start|complete`, `/approvals/{id}/decision`) — execute/approve agent-recommended interventions (some target ED boarding).

> For Hummingbird: if mobile "actions" are desired for ED, the realistic near-term targets are **acknowledging an ED-boarding/diversion alert**, **kicking off a bed request**, or **approving an agent recommendation** — all of which would be *new* mobile-initiated calls into existing RTDC/Ops endpoints, since native ED triage/disposition mutation does not yet exist server-side.

---

## 5. Real-Time Aspects

| Mechanism | Scope | ED relevance |
|---|---|---|
| **SSE: `/api/patient-flow/stream/adt`** (`PatientFlowStreamController`) | Patient movement / ADT | The only true streaming endpoint; powers the Patient Flow navigator (ED floor included). **Live patient location/movement.** |
| **HL7v2 ingest** `/api/patient-flow/ingest/hl7v2` | System→system | Real-time ADT feed source (not user-facing). |
| **Broadcast channels** (`routes/channels.php`) | `unit.{unitId}`, `hospital.beds` (PUBLIC) | Events `CensusUpdated`, `HuddleUpdated`, `BedMeetingUpdated`. PHI-free aggregate **house** census — **not an ED board feed**. Could carry ED-unit census if `unitId` = ED unit, but no ED-board event exists. |
| **Polling** | Analytics / Command Center APIs | All `*DataService` responses carry `generatedAtIso`; the web app refetches. No websocket for ED metrics — they're **poll-on-load** today. ED metrics freshness target (`ed_flow` source): expected lag 240 min, warning 1440 min — i.e. **near-real-time but not second-by-second**. |

**Net:** ED today is effectively a **polled** subsystem (snapshot metrics + on-demand drill-down), with one real **streaming** surface (Patient Flow ADT). The data model timestamps (`arrived_at`, `provider_seen_at`, `bed_assigned_at`) make event-driven push *feasible* but it is **not yet implemented** for ED.

---

## 6. Roles / Personas

Zephyrus uses **workflow-based navigation, not granular ED RBAC.** Any authenticated user can switch into the `emergency` workflow (role switcher), which reveals the same ED menu (`navigationConfig.ts` `key:'emergency'`). `prod.users` has a coarse `role` (default `'user'`); only `admin` is enforced (for `/users` management) via `AdminMiddleware`. There is no per-feature ED clinician/charge gating in code.

Mapping intended personas (from PRODUCT.md command-center model) to ED surfaces:

| Persona | ED interest | Primary surfaces |
|---|---|---|
| **Frontline ED clinician / nurse** | Live board, triage queue, my patients, wait times | (Intended) Triage/Treatment boards — **not yet built**; today only the mock dashboard board |
| **Charge nurse / ED flow coordinator** | Boarding, waiting-room risk, LWBS, diversion, capacity | ED Boarding hero tile, diversion, LWBS — via Command Center; bed-request/huddle actions (RTDC) |
| **Ops leader (house supervisor / capacity)** | ED boarding vs house capacity, surge probability, placement cycle | Command Center bands, OKR scoreboard, Analytics live/predictive, Ops agent inbox |
| **Executive** | ED Visits (30d), LWBS rate, D2P trend, diversion hours, OKR progress | Analytics retrospective, OKR scoreboard, executive brief |

> Reality check: because there's no ED RBAC and the frontline-clinician screens are placeholders, the **only fully-served personas today are ops-leader and exec** (via Command Center + Analytics). Hummingbird should design ED for charge/ops/exec first (real data exists) and treat frontline-clinician ED views as a future tier dependent on new backend work.

---

## 7. Mobile Relevance (Hummingbird) — per feature

Flags: **GLANCEABLE** (read at a glance) · **ACTIONABLE** (a worker would act on mobile) · **NOTIFY** (notification-worthy event) · **DESKTOP-ONLY** (too complex/analytical for phone). Data availability noted because much of the ED UI is mock.

| # | Feature / data | Flag(s) | Persona | Data backing | Notes for Hummingbird |
|---|---|---|---|---|---|
| 1 | **ED Boarding count** (admitted, no bed) | **GLANCEABLE + NOTIFY** | Charge / Ops | **Real** (`ed_visits`) | Single most important ED number. Threshold semantics already defined: warning >0, **critical ≥6**. Push when crossing critical, or when a boarder exceeds 4h since `admit_decision_at` (Joint Commission framing in code). |
| 2 | **Active ED diversion** (ongoing `ended_at IS NULL`) | **NOTIFY + GLANCEABLE** | Charge / Ops / Exec | **Real** (`diversion_events`) | Binary "are we on diversion?" + elapsed hours. High-signal, rare → ideal push. Start/end are notification-worthy events. |
| 3 | **High-acuity LWBS (ESI 1–2 walkouts)** | **NOTIFY** | Charge / Ops | **Real** (`ed_visits`) | Code already isolates `disposition='lwbs' AND esi_level<=2` and flags **critical** if >0. A sick patient leaving without being seen is a true safety breach → push. |
| 4 | **LWBS rate %** (24h / 30d) | **GLANCEABLE** | Charge / Exec | **Real** | Trend tile; bands high-bad 3%/2%. Daily trend available (`dailyLwbsTrend`). Good for a glance card + sparkline. |
| 5 | **Door-to-Provider (median min)** | **GLANCEABLE** | Charge / Ops / Exec | **Real** | Tile with target 30/warn 20. Glance only; not actionable on phone. |
| 6 | **ED LOS (discharged, median min)** | **GLANCEABLE** | Ops / Exec | **Real** | Throughput pulse. Glance card. |
| 7 | **OKR "ED boarding 192→<120 min" progress** | **GLANCEABLE** | Exec / Ops | **Real** | Progress bar + status band — perfect compact exec widget. |
| 8 | **Surge probability** (uses ED boarding) | **GLANCEABLE + NOTIFY** | Ops / Exec | **Real** | Predictive heuristic; notify on critical surge band. |
| 9 | **Acknowledge ED alert / approve agent rec** | **ACTIONABLE** | Charge / Ops | **Real** (Ops actions/approvals endpoints) | The realistic mobile *action*: one-tap ack/approve of a boarding/diversion recommendation via `/api/ops/...`. |
| 10 | **Initiate bed request for a boarder** | **ACTIONABLE** | Charge | **Real** (RTDC bed-requests) | Mobile "request a bed" to relieve boarding — high value, uses existing endpoint. |
| 11 | **Patient Status Board** (location, chief complaint, triage, wait, next action, provider) | GLANCEABLE *(intended)* / **DESKTOP-ONLY today** | Frontline / Charge | **Mock only** (no table) | Conceptually the killer mobile glance for clinicians, but **no real data source exists** — needs backend before Hummingbird can ship it. |
| 12 | **Triage queue / triage entry / ESI assignment** | ACTIONABLE *(intended)* / **DESKTOP-ONLY / N-A today** | Frontline | **None** (placeholder + no mutation) | Genuinely actionable on mobile *if built*, but requires new server-side write path. Flag as future. |
| 13 | **Patient Flow 4D navigator** | **DESKTOP-ONLY** | Ops analyst | Real (SSE) | Map/timeline, heavy interaction → not a phone surface; maybe a read-only "where is the ED crowding" mini-view. |
| 14 | **Wait-time / arrival / acuity / resource analytics & predictions** | **DESKTOP-ONLY** (most placeholders) | Ops / Exec | Mostly mock/unbuilt | Deep analytical charts; not phone-first. Exec could get a single predicted-arrivals glance tile. |
| 15 | **Bed/equipment availability, staffing present-vs-required** | GLANCEABLE *(intended)* / **DESKTOP-ONLY today** | Charge | **Mock only** | Desirable glance; no `prod` table backs ED beds/equipment/staffing yet. |

### 7.1 Recommended Hummingbird ED scope (data-defensible MVP)
1. **ED Pulse card (GLANCEABLE):** Boarding count, LWBS %, D2P median, ED LOS, active-diversion flag — all from real `ed_visits`/`diversion_events` via a (new, thin) read API or the existing Command Center/Analytics responses.
2. **ED notifications (NOTIFY):** (a) boarding crosses critical (≥6) or any boarder >4h; (b) diversion start/end; (c) ESI 1–2 LWBS occurs; (d) surge probability critical.
3. **One-tap actions (ACTIONABLE):** acknowledge/approve an ops recommendation; initiate a bed request — both via existing `/api/ops/*` and `/api/rtdc/bed-requests`.
4. **Defer to v2 (needs backend):** live patient status board, triage entry/disposition mutations, ED beds/equipment/staffing, deep analytics — all currently mock/placeholder.

---

## 8. Notable Real-Time / Notification Opportunities (summary)
- **Diversion start/stop** — discrete, rare, high-stakes → push. (`diversion_events.started_at/ended_at`.)
- **ED boarding threshold crossings** — quantitative, well-defined bands already in code (≥6 critical) → push + glance.
- **Boarder dwell >4h** since admit decision — derivable from `admit_decision_at` with no bed; matches the in-code Joint Commission 4h placement goal → push.
- **High-acuity (ESI 1–2) LWBS** — safety event already flagged critical in code → push.
- **Surge probability critical** — predictive, ops-leader facing → push.
- **Streaming substrate exists** only for Patient Flow ADT (SSE); ED metric streaming is not built, so Hummingbird notifications would initially be **server-side scheduled evaluation + push**, not socket-driven.

---

## 9. Key Source Files (absolute paths)
- Controller (render-only): `/Users/sudoshi/Github/Zephyrus/app/Http/Controllers/EDDashboardController.php`
- Models: `/Users/sudoshi/Github/Zephyrus/app/Models/EdVisit.php`, `/Users/sudoshi/Github/Zephyrus/app/Models/DiversionEvent.php`
- Migrations: `/Users/sudoshi/Github/Zephyrus/database/migrations/2026_06_22_000010_create_ed_visits_table.php`, `/Users/sudoshi/Github/Zephyrus/database/migrations/2026_06_22_000030_create_diversion_events_table.php`
- Seeder (ED data realism + boarding=5 target): `/Users/sudoshi/Github/Zephyrus/database/seeders/CommandCenterDemoSeeder.php` (`seedEdVisits`, `seedDiversionEvents`)
- Real ED metric computation: `/Users/sudoshi/Github/Zephyrus/app/Services/CommandCenterDataService.php` (`computeFlowMetrics`, `computeOutcomesMetrics`, `objectives`), `/Users/sudoshi/Github/Zephyrus/app/Services/Analytics/OperationsAnalyticsService.php` (`retrospective`, `liveSummary`)
- Drill-down remediation mapping: `/Users/sudoshi/Github/Zephyrus/app/Services/CommandCenterDrilldownService.php`
- Metric lineage (`ed_flow`, `ed_visits`, LWBS, D2P): `/Users/sudoshi/Github/Zephyrus/app/Services/Analytics/MetricLineageService.php`
- AI ops ED-boarders tool: `/Users/sudoshi/Github/Zephyrus/app/Services/Ops/Agents/AgentToolRegistry.php`
- ED pages: `/Users/sudoshi/Github/Zephyrus/resources/js/Pages/Dashboard/ED.jsx`, `/Users/sudoshi/Github/Zephyrus/resources/js/Pages/ED/**` (8 placeholders + `Analytics/Flow.jsx`)
- ED UI components: `/Users/sudoshi/Github/Zephyrus/resources/js/Components/ED/{AlertsAndPredictions,ResourceManagement,EDPlaceholder}.jsx`
- ED mock data: `/Users/sudoshi/Github/Zephyrus/resources/js/mock-data/ed.js`
- Routes: `/Users/sudoshi/Github/Zephyrus/routes/web.php` (lines 52, 131–153), `/Users/sudoshi/Github/Zephyrus/routes/api.php` (analytics/command-center/patient-flow/ops), `/Users/sudoshi/Github/Zephyrus/routes/channels.php`
- ED navigation taxonomy: `/Users/sudoshi/Github/Zephyrus/resources/js/config/navigationConfig.ts` (`key:'emergency'`, lines ~191–224)
