# Hummingbird — Persona Screens: Design + Build/Test/Validate Plan

**Status:** Shipped baseline, incomplete relative to Altitude 2.0 · **Created:** 2026-06-30 · **Owner:** mobile
**Relationship to existing docs:** This is the _concrete buildout_ that operationalizes the
already-approved persona specification in
[`reference/02-core-functionality-by-role.md`](reference/02-core-functionality-by-role.md),
sequenced against [`reference/01-feature-parity-matrix.md`](reference/01-feature-parity-matrix.md)
and the BFF [`api-contract/hummingbird-bff.v1.yaml`](api-contract/hummingbird-bff.v1.yaml). It
does **not** restate strategy (see [`PRODUCT.md`](../../PRODUCT.md)) or the visual system (see
[`DESIGN.md`](../../DESIGN.md)). It adds: per-persona screen designs grounded in the **seeded
demo data**, the exact BFF endpoints each screen needs, the client wiring on iOS + Android, and
an end-to-end test/validation protocol.

**Altitude 2.0 overlay:** [`ALTITUDE-PERSONA-OPERATING-PLAN.md`](ALTITUDE-PERSONA-OPERATING-PLAN.md)
is now the governing direction. This plan remains the shipped persona-screen baseline; it does
not yet complete the shared A0/A1/A2/A2P/A3 descent, patient/encounter lens, cross-persona
activity ledger, or Eddy event-awareness contract.

---

## 0. Build status — 2026-07-01 (ALL role screens shipped + simulator-validated)

Every role-specific screen is built, compiles, and was **validated live on the iOS Simulator**
against the running BFF + fixed Summit Healthcare demo data:

| Persona                                                      | Home                                                                                                  | BFF                                               | Live-validated |
| ------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------- | ------------------------------------------------- | :------------: |
| P1 Transporter                                               | My Trips (claim→run→handoff)                                                                          | `/transport/*`                                    |       ✅       |
| P2 EVS Tech                                                  | Bed Turns (claim→start→complete + iso SOP)                                                            | `/evs/*`                                          |       ✅       |
| P5 Bed Manager                                               | House Capacity + placement review                                                                     | `/rtdc/house·bed-requests·decision`               |       ✅       |
| P4 OR Nurse / P7 Periop Mgr                                  | OR Board (live rooms, cases)                                                                          | `/or/board`                                       |       ✅       |
| P6 Capacity Lead                                             | Capacity & Demand + approvals inbox                                                                   | `/command/house` + `/ops/inbox·decision`          |       ✅       |
| P9 Executive                                                 | House Brief (strain 0–4 + KPIs)                                                                       | `/command/house`                                  |       ✅       |
| P10 Staffing Coord                                           | Staffing gaps + fill                                                                                  | `/staffing/overview·fill`                         |       ✅       |
| P8 PI/Quality Lead                                           | Improvement (PDSA + opportunities)                                                                    | `/improvement/pdsa·opportunities`                 |       ✅       |
| P3 Charge / bedside / hospitalist / intensivist / supervisor | Tailored census homes (unit-focused / critical-care / house) + cross-domain For-You w/ inline Resolve | `/rtdc/census·house·barriers/resolve`, `/for-you` |       ✅       |

**Demo data hardened** (all reversible SQL): 88% house occupancy, ED beds/stray-encounters fixed,
transport/EVS SLAs refreshed to near-now, today's staffing plans with gaps (MICU critical, SICU/6E/
7E), OR board live via simulated clock, 7 pending ops approvals, 5 PDSA cycles, 6 opportunities.
Backend: PHP 8.5 + `artisan serve` on `:8001` against the fixed DB; `admin@acumenus.net` preserved.
**Reproducible:** the tuning is folded into `database/seeders/DemoTuningSeeder.php` (idempotent,
runs last in `DatabaseSeeder`) so a fresh `db:seed` yields the same compelling state — 85%
occupancy, today's staffing gaps, near-now SLAs, clean ED beds, varied OR surgeons. BFF
conformance tests live in `tests/Feature/MobileBffTest.php`; the **full Feature suite is green
(270 passed, 2371 assertions)**.

Android now has a real `MobileRoleCatalog`, `AltitudeViewModel`, and Compose altitude screens for
A0/A1/A2/A2P, activity, patient context, and Eddy context calls. The remaining Android work is
**role-package UX parity** with iOS (final per-role home routing, bespoke feature packages, and
onboarding/persona-switching polish), plus optional polish such as a bespoke P3 charge-nurse unit
board. The section below is the design plan.

---

## 1. The gap this plan closes

Hummingbird ships today at **Phase 0 / early Phase 1**:

- **The original two-screen shell is no longer the whole app.** iOS has role-aware feature homes,
  and Android now has an altitude shell (`AltitudeViewModel` + `ui/altitude/AltitudeScreens.kt`) that can switch
  among all 14 mobile personas and call A0/A1/A2/A2P, activity, patient-context, and Eddy context
  endpoints. Android still needs the final role-package UX parity: the bespoke transport/EVS/OR/
  staffing/improvement homes, detail flows, and onboarding/persona-switching polish.
- **Persona awareness is implemented but not fully even across platforms.** iOS `Role.swift` and
  `RoleExperience.swift` enumerate all 14 personas and map them to `HomeKind` values. Android
  `MobileRoleCatalog` also has all 14 role ids, but it is currently a lighter altitude-domain
  selector rather than a full `RoleExperience`/feature-package router.
- **The BFF now covers the high-value implemented domains.** `/api/mobile/v1/*` includes
  altitude, patient operational context, activity, RTDC census/house/placements, transport, EVS,
  OR board, command house, ops inbox/approvals, staffing overview/fill, improvement reads,
  realtime config, and Eddy. Deferred surfaces remain explicit: OR writes/performance,
  `/command/brief`, generic ops action transitions, ED signals, and PDSA write/advance.

Meanwhile, the **demo seeders already populate every pillar** with realistic, deterministic data
(named surgeons, OR rooms running cases, 6 open discharge barriers, 2 staffing gaps, 8 transport
jobs, 6 EVS turns, 5 active PDSA cycles). The data exists; the persona screens to _show_ it do
not. **This plan builds those screens.**

> The OpenAPI contract now matches the implemented mobile BFF route inventory. The remaining work
> is composing real persona screens on top of those implemented routes, then adding explicitly
> deferred routes only when their backend write/read surfaces land.

---

## 2. Design contract for persona screens (reused, not reinvented)

Every persona screen obeys the project non-negotiables ([`CLAUDE.md`](../../CLAUDE.md)) and the
"one app, many homes" doctrine ([`reference/02`](reference/02-core-functionality-by-role.md)):

1. **One screen → one BFF call.** The screen's home call aggregates; no chatty fan-out from the
   device. The BFF reshapes existing web services; it never re-implements them.
2. **Role-aware Home = the altitude's single most important truth, glanceable in 3 s.** Dark by
   default, `tabular-nums` on every metric, status never by color alone (arrow/icon/label).
3. **The For-You queue stays the universal primitive.** New domains _feed_ it (transport STAT,
   EVS isolation turn, safety-note SLA, approval pending) and are tier-ranked by earned urgency.
   The queue component is unchanged; only its sources grow.
4. **Primary action inline.** The common verb (Claim, Resolve, Approve, Acknowledge, Start,
   Complete) is one tap from the card — zero extra navigation.
5. **PHI-minimized.** Lists and notifications carry counts/refs/status only. Patient identifiers
   appear only on an explicit, authorized detail call — never in a list, never in a push.
6. **Deep-link to web for desktop-grade work.** Analytics, process mining, block utilization
   tables, the Monday review — `links.web` hands off in one tap rather than cramming a table.
7. **Status vocabulary is rationed.** teal=optimal, amber=watch, coral=breach (earned), sky=info.
   Coral is the most expensive ink; reserve it for true breaches.

### The "screen package" pattern

Each persona gets a **package**, not a one-off screen:

```
Persona package = { Home (tailored glance)  +  Feature screen(s) (list → detail → action)  +  For-You feed contribution  +  web deep-links }
```

The client routes a confirmed role → its package via an extension of the existing
`RoleExperience` (iOS) and its Android twin. Home composition and the primary feature tab become
**data-driven by role**, so adding a persona is: (1) extend the role catalog, (2) add a
`RoleExperience` case that names the home layout + feature route, (3) build the feature
screen(s), (4) point them at the BFF endpoint. No new navigation framework.

---

## 3. Persona → screen catalog (grounded in seeded demo data)

10 personas across 4 waves. Wave order follows **backend readiness** (live endpoints first),
matching [`reference/07-roadmap-phasing.md`](reference/07-roadmap-phasing.md). Each entry lists
the Home, the feature screens, the **exact demo data it renders today**, the BFF endpoint, the
inline action(s), the web deep-link, and the **acceptance criteria** (what "built + validated"
means against the seed).

Legend for BFF column: **WIRE** = contract path exists, reshape a live web service · **+OR-FIX**
= needs the 3 perioperative backend fixes first · **NEW** = net-new backend (web is stub too).

---

### WAVE 1 — Frontline workers + unit/house RTDC (all live endpoints)

These four are the clearest mobile wins and ride entirely on **live** RTDC/Transport/EVS
services. `RoleExperience` already stubs `transport`, `evs`, `charge_nurse`, `bed_manager`.

---

#### P1 — Transporter / Porter · _Frontline mobile · `transport`_

**Home — "My Trips"** (claimed jobs as a timeline + Available Jobs sorted by priority):

```
┌ Transport ───────────────────── ⟳ 12:04 ┐
│  STAT  CT → 4 East · needed 11:50 ▲ OVERDUE│   ← coral banner only if a STAT exists
├────────────────────────────────────────────┤
│ MY TRIPS (1 active)                         │
│  ● Picked up · ED → MICU · stretcher        │
│       en route · 6 min elapsed   [Advance] │
├────────────────────────────────────────────┤
│ AVAILABLE (7)              sort: priority ▾ │
│  urgent  PACU → 6 West · bed     [Claim]    │
│  routine Lobby → Imaging · wc    [Claim]    │
│  …                                          │
└────────────────────────────────────────────┘
```

- **Feature screens:** Job detail with the lifecycle stepper (`dispatched → arrived →
picked_up → en_route → arrived → handoff`), one big primary button per state; **Structured
  handoff** sheet (`handoff_to`, summary, `outstanding_risks[]` → Complete).
- **Demo data:** `prod.transport_requests` — **8 active** (mixed `inpatient/transfer/discharge/
ems/care_transition`, some `stat`/overdue), 14 historical for "completed today" count;
  `transport_events` drive the stepper.
- **BFF:** `GET /transport/queue` (WIRE → `TransportOperationsService`), `POST
/transport/requests/{id}/status`, `POST /transport/requests/{id}/handoff`.
- **For-You feed:** STAT/at-risk jobs assigned or offered to me.
- **Deep-link:** `/transport/dispatch`.
- **Acceptance:** with the seed, Home shows ≥1 STAT banner, 7–8 available jobs, ≥1 overdue
  (negative `needed_at`); Claim moves a job into MY TRIPS; the full stepper completes a trip and
  it disappears from the queue and increments "completed today."

---

#### P2 — EVS Technician · _Frontline mobile · `evs`_

**Home — "Bed Turns" / Next Dirty Bed** (queue sorted by SLA, isolation badged):

```
┌ Bed Turns ───────────────────── ⟳ 12:04 ┐
│ pending 6   ·  overdue 2 ▲  ·  isolation 1 │
├────────────────────────────────────────────┤
│  NEXT DIRTY BED                             │
│   6E-12 · discharge turn · needed 11:30     │
│   ▲ 34 min overdue              [Claim]     │
├────────────────────────────────────────────┤
│   ISO  MICU-03 · isolation clean · PPE req  │
│        needed 12:20             [Claim]     │
│   3S-08 · terminal clean · needed 12:45     │
│   …                                         │
└────────────────────────────────────────────┘
```

- **Feature screens:** Turn detail with the `Claim → Start (stamps started_at, shows isolation
SOP/PPE) → Complete (stamps completed_at)` flow; completing **notifies the bed manager** (the
  bed becomes placeable).
- **Demo data:** `prod.evs_requests` — **6 requests** (`discharge_turnover`, `isolation_clean`,
  `terminal_clean`, `bed_clean`), **2 overdue**, ≥1 `isolation_required` driving the PPE prompt.
- **BFF:** `GET /evs/queue` (WIRE → `EvsOperationsService`), `POST /evs/requests/{id}/status`.
- **For-You feed:** isolation turns, STAT turns, SLA at-risk.
- **Deep-link:** none (frontline is fully native here).
- **Acceptance:** Home leads with the most-overdue dirty bed; isolation turn shows a PPE/SOP step
  on Start; Complete removes it and emits the `beds.changed` event the bed manager's house view
  re-snapshots on.

---

#### P3 — Bedside / Charge Nurse · _Frontline unit · `charge_nurse` / `bedside_nurse`_

**Home — "Your Unit"** (the pinned unit, full; the rest of house as quiet context):

```
┌ 6 East ──────────────────────── ⟳ 12:04 ┐
│  occupied 27 / 32     can admit 0 ▲        │   ← acuity-safe headroom, not raw beds
│  available 0   ·   blocked 5   ·   ESI mix │
├────────────────────────────────────────────┤
│  BARRIERS ON YOUR UNIT (2)                  │
│   ▲ Cardiology consult pending · 6h  [Resolve]│
│     No SNF bed · 22h                 [Resolve]│
├────────────────────────────────────────────┤
│  INCOMING (1 bed request → 6E) · 1 bed-turn │
│  STAFFING  RN gap ▲ 4 / 6 scheduled         │
└────────────────────────────────────────────┘
```

- **Feature screens:** Unit detail (exists — enhance with barriers + inbound + staffing strip);
  Barrier resolve sheet; **New Bed Request** form; (P2) unit-huddle step entry.
- **Demo data:** `census` for the pinned unit (fix the headroom-vs-total display — see §5);
  `prod.barriers` filtered to the unit (6 open house-wide, categories
  medical/logistical/placement/social); `prod.bed_requests` inbound to the unit;
  `prod.staffing_plans` for the unit (6E is a seeded `gap`: 4/6 RN).
- **BFF:** `GET /rtdc/census` + `GET /rtdc/house` (WIRE — exist), `GET /rtdc/barriers`, `POST
/rtdc/barriers/{id}/resolve`, `POST /rtdc/bed-requests`.
- **For-You feed:** aging barriers on my unit, incoming transports, bed-turn completions
  (bed ready), unit tipping into deficit.
- **Deep-link:** `/rtdc/unit-huddle`.
- **Acceptance:** Home pins the role's unit; resolving a barrier removes it from both the unit
  card and the For-You queue; the seeded 6E RN gap renders amber with an arrow + "4 / 6."

---

#### P5 — Nursing Supervisor / Bed Manager · _Ops leader · `bed_manager` / `house_supervisor`_

**Home — "House Capacity"** (net bed-need roll-up + pending placements + house heat):

```
┌ House Capacity ──────────────── ⟳ 12:04 ┐
│  occupancy 87%   net bed need +6 by 2pm ▲  │
│  pending placements 6   ·   ED boarding 5  │
├────────────────────────────────────────────┤
│  PENDING BED REQUESTS (6)        oldest ▾   │
│   ▲ ICU · tier 1 · iso · 41 min   [Place]  │
│     Med/Surg · tier 3 · 18 min    [Place]  │
├────────────────────────────────────────────┤
│  UNIT HEAT (25)  ▢▢▢◧■ MICU 96% · 6E full…  │
└────────────────────────────────────────────┘
```

- **Feature screens:** House heat grid (all 25 units, status-stripe + label); Placement worklist
  → **Bed Placement decision** screen with the _transparent score + rationale_ and Accept / Edit
  / Reject; Barriers-across-units triage.
- **Demo data:** house roll-up from `census_snapshots` + `rtdc_predictions` (`by_2pm` /
  `by_midnight` horizons, `bed_need`); `prod.bed_requests` (6 pending) + `bed_placement_decisions`
  (recommended/chosen bed, ~45–65 min median latency); ED boarding count (5) via `ed_visits`
  where `bed_assigned_at IS NULL`.
- **BFF:** `GET /rtdc/house` (WIRE), `GET /rtdc/bed-requests`, `POST
/rtdc/bed-requests/{id}/decision`, `GET /rtdc/barriers`.
- **For-You feed:** pending bed requests, placement contention, new/aging barriers house-wide,
  capacity breach, EVS bed-turn completed (placeable now).
- **Deep-link:** `/rtdc/bed-placement`, `/rtdc/global-huddle`.
- **Acceptance:** Home shows the seeded 6 pending requests oldest-first; opening one shows a
  recommended bed + score; Accept records a `bed_placement_decision` and clears it from the queue.

---

### WAVE 2 — OR board + decisions (live; OR needs 3 backend fixes)

---

#### P4 — OR Circulating / Charge Nurse · _Frontline OR · NEW role `or_nurse`_

**Home — "OR Board"** (live room status for OR-1..4 + My Cases Today):

```
┌ OR Board ────────────────────── ⟳ 12:04 ┐
│  running 3 / 4   ·   on-time starts 75% ▲  │
├────────────────────────────────────────────┤
│  OR-1 ● In-Progress  Marchetti · CABG       │
│        92 min · ~38 left            ▣▣▣▣▢   │
│  OR-2 ◐ Turnover     ready ~9 min           │
│  OR-3 ● In-Progress  Desai · Craniotomy     │
│  OR-4 ○ Available    next: Okonkwo 13:00    │
├────────────────────────────────────────────┤
│  SAFETY NOTE ▲ OR-3 overdue 4 min  [Ack]    │
└────────────────────────────────────────────┘
```

- **Feature screens:** Case detail with the wheels-clock timeline (`or_in → procedure_start →
procedure_end → or_out`) and progress %; advance case status; **safety note** create/ack
  (SLA: Crit 15m / High 30m / Med 60m / Low 120m); pre-op milestone ack (H&P/Consent/Labs);
  case-transport ready/complete.
- **Demo data:** `prod.or_cases` for **today** across **OR-1..OR-4** in "Main OR Suite" (MOR),
  joined to `or_logs` for the clock and `case_metrics` for turnover (24–34 min); surgeons
  **Marchetti (CV) / Okonkwo (Trauma) / Wexler (Trauma) / Desai (Neuro) / Castellano**;
  procedures from the 15-name catalog (CABG, Craniotomy, TKA, Lap Chole…). Today's mix is
  seeded as completed / in-progress / scheduled by clock, with **1 same-day cancellation** and
  **FCOTS 75%** (3 of 4 first cases on time).
- **BFF:** `GET /or/board` is implemented. `POST /or/cases/{id}/status`, OR performance, and
  safety-note/milestone/transport bodies are deferred contract/backend work.
- **For-You feed:** safety-note SLA breaches, "you're up next," pre-op milestone incomplete.
- **Deep-link:** `/operations/room-status`, `/operations/cases`.
- **Acceptance:** Board renders 4 rooms with the correct derived state from the wheels clock at
  the current hour; an in-progress case shows live elapsed/remaining; the seeded overdue safety
  note appears coral in For-You with an inline **Ack**.

> **Backend note:** the earlier OR bug audit has been partially superseded. Appendix B of
> `IMPLEMENTATION-PLAN.md` records `status_id` and `active_status` fixes as done, and the
> `actual_start/end_time` claim as a false positive. Remaining OR mobile work is the write/
> performance contract and feature UX, not those fixed backend bugs.

---

#### P7 — Perioperative Manager · _Ops leader · NEW role `periop_manager`_

**Home — "OR Today"** (manager lens over the same board data):

```
┌ OR Today ────────────────────── ⟳ 12:04 ┐
│  FCOTS 75% ▲   turnover 29m   util 81%     │
│  rooms running 3/4 · cases 12/17 · cancel 1│
├────────────────────────────────────────────┤
│  DELAYS & CANCELLATIONS                      │
│   ▲ OR-1 first-case late +14m               │
│     OR-4 case cancelled (same-day)          │
├────────────────────────────────────────────┤
│  TURNOVER OUTLIERS  OR-2 41m ▲              │
│  [ Open block utilization in Zephyrus → ]   │
└────────────────────────────────────────────┘
```

- **Feature screens:** shares the OR Board (read/triage lens); Delays & cancellations list;
  Turnover outliers; a single block-utilization tile that **deep-links** to web for the full
  by-service/room analytics (not rebuilt on mobile).
- **Demo data:** `case_metrics` (turnover, late-start, prime-time), `block_utilization`
  (scheduled vs actual, 70–95%/room/day), `or_cases` (today's done/remaining, 1 cancel), FCOTS
  75% from the latest seeded day.
- **BFF:** `GET /or/board` is implemented and shared. `GET /or/performance` remains deferred as a
  small reshape of `case_metrics` + `block_utilization`.
- **For-You feed:** first-case late starts, cancellations, turnover breaches, OR staffing gaps.
- **Deep-link:** `/analytics/block-utilization`, `/analytics/turnover-times`,
  `/analytics/or-utilization`.
- **Acceptance:** Home shows FCOTS 75% (amber, arrow), avg turnover ~29m, 1 cancellation; the
  block-utilization tile deep-links and the web page opens authenticated.

---

#### P6 — Patient-Flow / Capacity Lead · _Ops leader · NEW role `capacity_lead`_

**Home — "Capacity & Demand"** (strain + demand-vs-supply + approvals inbox):

```
┌ Capacity & Demand ───────────── ⟳ 12:04 ┐
│  House strain  ▣▣▣◻ 2 / 4  (elevated)      │
│  net bed need +6 by 2pm ▲                   │
├────────────────────────────────────────────┤
│  DEMAND        ED 12 · OR 4 · xfer 3 · dir 2│
│  DISCHARGES    definite 7 · probable 9      │
├────────────────────────────────────────────┤
│  APPROVALS (2 pending) ★                    │
│   ▲ Open 4 surge beds on 7W (risk: med)     │
│        [Approve]  [Reject]                  │
└────────────────────────────────────────────┘
```

- **Feature screens:** Demand-capacity by unit (predictions); **Ops approvals** inbox → approval
  detail (title, risk, rationale → Approve/Reject + reason, optional Assign); Huddle action
  items; surge/diversion status.
- **Demo data:** `rtdc_predictions` (demand sources, weighted discharges), `census` roll-up for
  strain; Eddy/`ops` recommendations + operational actions seeded by `EddySeeder` /
  `CommandCenterDemoSeeder`; `diversion_events` (2 historical → "no active diversion").
- **BFF:** `GET /command/house`, `GET /ops/inbox`, and
  `POST /ops/approvals/{uuid}/decision` are implemented. Generic
  `POST /ops/actions/{id}/{transition}` remains deferred.
- **For-You feed:** actions awaiting my approval ★, surge alert, huddle to facilitate, stale-feed
  qualified metrics.
- **Deep-link:** `/ops/agent-inbox`, `/rtdc/global-huddle`.
- **Acceptance:** Home shows a 0–4 strain index with non-color cue; the approvals inbox shows
  seeded pending action(s); Approve transitions the action and removes it (and emits to web).

---

#### P9 — Executive (CMO / COO / CNO) · _Executive · NEW role `executive`_

**Home — "House Brief"** (one quiet screen; loud only on escalation):

```
┌ House Brief ─────────────────── ⟳ 06:30 ┐
│  House strain   ▣▣◻◻ 2 / 4   stable →      │
│                                            │
│  occupancy 87%  ·  ED boarding 5  ·  LWBS 2.1%
│  OR on-time 75% ▲  ·  net bed need +6      │
├────────────────────────────────────────────┤
│  THE ONE THING                              │
│   ▲ MICU at capacity, RN critical-gap       │
├────────────────────────────────────────────┤
│  EXECUTIVE BRIEF  (situation · plan · impact)│
│   "House is at 87%… plan: 7 definite d/c…"  │
│   [ Open Monday review in Zephyrus → ]      │
└────────────────────────────────────────────┘
```

- **Feature screens:** Strain detail (drivers: occupancy, LOS, barriers, staffing); **Executive
  Brief** (server-composed situation/plan/impact/confidence); drill-once into a hero KPI; deep
  link to the web Monday review.
- **Demo data:** Command Center strain + hero KPIs + 24h forecast (`CommandCenterController`);
  the server-composed brief from the Executive Briefing agent (`EddySeeder` corpus); "the one
  thing" = the single most material breach (seeded MICU critical-gap + at-capacity).
- **BFF:** `GET /command/house` is implemented. `GET /command/brief` remains deferred narrative
  work.
- **For-You feed:** house-status escalation, the single most material breach, "morning brief
  ready" (sparse, high-bar).
- **Deep-link:** `/dashboard` (Command Center, `?role=executive`), `/ops/executive-brief`.
- **Acceptance:** Home stays calm (no coral) under nominal seed; forcing a breach (re-seed a unit
  to 100%+) promotes "the one thing" to coral with an arrow; the brief renders the seeded
  narrative; tabular KPIs carry trust/trajectory affordances.

---

### WAVE 3 — Staffing + ED signals + huddles (live, lower mobile-first priority)

---

#### P10 — Staffing Coordinator · _Ops leader · NEW role `staffing_coordinator`_

**Home — "Staffing"** (open requests + units below minimum-safe):

```
┌ Staffing ────────────────────── ⟳ 12:04 ┐
│  open requests 2   ·   units below safe 2 ▲ │
├────────────────────────────────────────────┤
│  BELOW MINIMUM-SAFE                          │
│   ▲ MICU · RN 3 / 5 (min 4) critical-gap    │
│     6E   · RN 4 / 6 (min 5) gap             │
├────────────────────────────────────────────┤
│  OPEN REQUESTS                               │
│   STAT  MICU · 2 RN · day      [Assign]     │
│   urgent 6E  · 1 RN · day      [Assign]     │
└────────────────────────────────────────────┘
```

- **Feature screens:** Gap board (`staffing_plans` by status); Open-requests worklist →
  create/source/assign/fill.
- **Demo data:** `prod.staffing_plans` (MICU `critical_gap` 3/5 RN, 6E `gap` 4/6 RN),
  `prod.staffing_requests` (2: one stat, one urgent), `staffing_events`.
- **BFF:** `GET /staffing/overview`, `POST /staffing/requests/{id}/fill` (implemented mobile
  BFF paths that reshape/delegate to the live staffing services).
- **For-You feed:** new gap below safe minimum, unfilled/escalated requests.
- **Deep-link:** `/staffing` (Staffing Office).
- **Acceptance:** Home shows the 2 seeded gaps with min-safe context and arrows; an Assign action
  transitions a request through the lifecycle.

---

#### ED signals (glance for `charge_nurse` (ED), `capacity_lead`, `executive`)

Not a standalone persona screen — an **ED signals strip/tile** injected into the house/exec
homes and the For-You queue. ED _actions_ (triage, disposition) are net-new backend and deferred
to Wave 4.

- **Demo data:** `ed_visits` — boarding count (5, `bed_assigned_at IS NULL`), LWBS rate
  (~15% of ~70/24h), door-to-provider median, ESI 1–2 LWBS safety flag; `diversion_events`
  (no active). All available via Command Center today.
- **BFF:** fold into `GET /command/house`; add `GET /ed/signals` if a dedicated tile is wanted.
- **Acceptance:** boarding count and LWBS render with targets; an ESI 1–2 LWBS produces a
  NOTIFY-tier For-You item.

---

### WAVE 4 — PI / PDSA (net-new backend; web is stub too)

---

#### P8 — PI / Quality Lead · _Ops leader · NEW role `pi_lead`_

**Home — "Improvement"** (my PDSA cycles + opportunity portfolio + barrier trend):

```
┌ Improvement ─────────────────── ⟳ 12:04 ┐
│  active cycles 5   ·   opportunities 6      │
├────────────────────────────────────────────┤
│  MY PDSA CYCLES                              │
│   ● Do   ED boarding rapid bed-assign  d+30 │
│   ● Plan FCOTS checklist standardize   d+16 │
│   ● Study Sepsis bundle compliance     d+9  │
├────────────────────────────────────────────┤
│  TOP OPPORTUNITIES (by impact)               │
│   88  ED Boarding Reduction   · In Progress │
│   81  First-Case On-Time      · Open        │
│   76  Discharge-Before-Noon   · Open        │
└────────────────────────────────────────────┘
```

- **Feature screens:** PDSA list → cycle detail (objective, stage, owner, baseline/current/
  target, barriers); Opportunity board (ranked by `estimated_impact`); barrier-trend tile;
  Resource library (deep-link). Bottleneck/root-cause/process-mining stays **WEB**.
- **Demo data:** `prod.pdsa_cycles` (5 active: ED boarding, DC-before-noon, FCOTS checklist,
  sepsis bundle, barrier huddle; 2 completed: VTE prophylaxis, med-rec),
  `prod.improvement_opportunities` (6, impact 88→41), `prod.improvement_resources` (6),
  `prod.barriers` trend by category.
- **BFF:** `GET /improvement/pdsa`, `GET /improvement/opportunities`, `POST
/improvement/pdsa/{id}/advance` (**NEW** — requires building the PDSA write API on the web
  first; model exists, routes do not).
- **For-You feed:** PDSA stage due, barrier assigned to me, intervention metric moved.
- **Deep-link:** `/improvement/pdsa`, `/improvement/opportunities`, `/improvement/bottlenecks`.
- **Acceptance:** Home lists the 5 active cycles with stage + age; opportunities rank by impact;
  (once write API lands) advancing a stage persists and notifies.

---

### Coverage map (persona × wave × backend readiness)

| #   | Persona                | Role id                          | Home              | Wave | Backend                                                             |
| --- | ---------------------- | -------------------------------- | ----------------- | :--: | ------------------------------------------------------------------- |
| P1  | Transporter            | `transport`                      | My Trips          |  1   | LIVE (WIRE)                                                         |
| P2  | EVS Tech               | `evs`                            | Bed Turns         |  1   | LIVE (WIRE)                                                         |
| P3  | Charge/Bedside RN      | `charge_nurse`/`bedside_nurse`   | Your Unit         |  1   | LIVE (WIRE)                                                         |
| P5  | Bed Manager/Supervisor | `bed_manager`/`house_supervisor` | House Capacity    |  1   | LIVE (WIRE)                                                         |
| P4  | OR Nurse               | `or_nurse`                       | OR Board          |  2   | BFF read implemented; writes deferred                               |
| P7  | Periop Manager         | `periop_manager`                 | OR Today          |  2   | BFF read implemented; performance deferred                          |
| P6  | Capacity Lead          | `capacity_lead`                  | Capacity & Demand |  2   | Inbox/approval BFF implemented; generic action transitions deferred |
| P9  | Executive              | `executive`                      | House Brief       |  2   | `/command/house` implemented; `/command/brief` deferred             |
| P10 | Staffing Coord         | `staffing_coordinator`           | Staffing          |  3   | BFF overview/fill implemented                                       |
| —   | ED signals             | (folded)                         | tile/strip        |  3   | LIVE via Command Center                                             |
| P8  | PI/Quality Lead        | `pi_lead`                        | Improvement       |  4   | BFF reads implemented; PDSA write deferred                          |

All role ids above exist in iOS `Role.swift` and Android `MobileRoleCatalog`; remaining parity is
feature-package routing and UX depth, not catalog vocabulary.

---

## 4. BFF endpoint buildout

The contract already drafts the Wave-1/2 paths; they need **route wiring + a controller that
reshapes the live service into the envelope**. Add Wave-3/4 paths to the contract as their
backends land. All require `auth:sanctum` + `ability:mobile:read`; mutations add `mobile:act`.

| Endpoint                                          | Method | Reshapes                              | Status                    | Wave |
| ------------------------------------------------- | ------ | ------------------------------------- | ------------------------- | :--: |
| `/transport/queue`                                | GET    | `TransportOperationsService`          | Implemented               |  1   |
| `/transport/requests/{id}/status`                 | POST   | same                                  | Implemented (act)         |  1   |
| `/transport/requests/{id}/handoff`                | POST   | same                                  | Implemented (act)         |  1   |
| `/evs/queue`                                      | GET    | `EvsOperationsService`                | Implemented               |  1   |
| `/evs/requests/{id}/status`                       | POST   | same                                  | Implemented (act)         |  1   |
| `/rtdc/house`                                     | GET    | census roll-up + `RtdcPrediction`     | Implemented               |  1   |
| `/rtdc/bed-requests`                              | GET    | `BedRequest` + `BedPlacementDecision` | Implemented               |  1   |
| `/rtdc/bed-requests/{id}/recommendations`         | GET    | RTDC placement engine                 | Implemented               |  1   |
| `/rtdc/bed-requests/{id}/decision`                | POST   | RTDC engine                           | Implemented (act)         |  1   |
| `/rtdc/barriers/{id}/resolve`                     | POST   | RTDC engine                           | Implemented (act)         |  1   |
| `/or/board`                                       | GET    | `ORCase`+`ORLog`+`Room`               | Implemented read          |  2   |
| `/or/cases/{id}/status`                           | POST   | case lifecycle                        | Deferred                  |  2   |
| `/or/performance`                                 | GET    | `CaseMetrics`+`BlockUtilization`      | Deferred                  |  2   |
| `/command/house`                                  | GET    | `CommandCenterController`             | Implemented               |  2   |
| `/command/brief`                                  | GET    | Executive Brief                       | Deferred                  |  2   |
| `/ops/inbox`                                      | GET    | `Ops/*` recommendations+actions       | Implemented               |  2   |
| `/ops/approvals/{uuid}/decision`                  | POST   | `OperationalActionLifecycleService`   | Implemented (act)         |  2   |
| `/ops/actions/{id}/{transition}`                  | POST   | same                                  | Deferred                  |  2   |
| `/staffing/overview`                              | GET    | `StaffingOperationsService`           | Implemented               |  3   |
| `/staffing/requests/{id}/fill`                    | POST   | staffing service                      | Implemented (act)         |  3   |
| `/ed/signals` (optional)                          | GET    | Command Center / analytics            | WIRE                      |  3   |
| `/improvement/pdsa`, `/improvement/opportunities` | GET    | `PdsaCycle`/`ImprovementOpportunity`  | Implemented reads         |  4   |
| `/improvement/pdsa/{id}/advance`                  | POST   | PDSA write API                        | Deferred web/backend work |  4   |

**Naming reconciliation:** `/for-you` is the canonical implemented mobile path and the OpenAPI
contract now matches it. Continue to confirm `/me` returns `roles[]` rich enough to pre-select the
new roles.

Every list endpoint: PHI-minimized, `{ data, meta:{as_of,stale,version}, links:{web} }`
envelope, `updated_since`+`cursor` support, and a `links.web` deep-link target.

---

## 5. Data-plausibility prerequisites (gate for "plausibly uses demo data")

[`DATA-PLAUSIBILITY.md`](DATA-PLAUSIBILITY.md) shows the live DB currently renders some
implausible numbers. Screens that "plausibly take advantage of the demo data" require these
fixed **before** their wave ships:

1. **Re-seed to the intended state.** The seeders target ~87% house occupancy, 12 pending
   requests, 5 ED boarding, 6 barriers, 2 staffing gaps. The live snapshot diverged (132 occupied
   vs ~432 intended). Run `php artisan migrate:fresh --seed` against the demo DB and re-verify.
2. **ED bed inventory (#1).** ED has 148 bed rows vs `staffed_bed_count = 40` → "33 / 0 safe ·
   108 available." Either set ED `staffed_bed_count` to true capacity or prune phantom
   `available` beds so `count(beds) ≈ staffed`. **Blocks P3 (ED charge) + P5 house heat.**
3. **Stray active encounters (#2).** 143 active encounters vs 132 occupied beds; ~11 units show
   "0 occupied, 1 active enc." Reconcile `encounters.status='active'` ↔ `beds.status='occupied'`.
   **Blocks every census-derived screen.**
4. **Headroom-vs-total display (#3).** ✅ **Already fixed in this branch** — verified
   2026-06-30. `RtdcController::census` returns `staffed_bed_count`, `occupied`, `available`,
   `blocked`, **`can_admit` (separate)**, and a `status` derived from `occupied / staffed`;
   the iOS `CensusRollup` likewise computes `occupied / staffedBedCount`. The "6E 500% / ED No
   data" symptom in `DATA-PLAUSIBILITY.md` was a _remote_-DB audit (db `zephyrus` on
   192.168.1.58) that predates the current code. No code change needed; the residual ED/encounter
   issues below are pure _data_.
5. **Unit type labels (#4, minor).** PICU/NICU typed `med_surg` won't show in critical-care
   scopes; Behavioral Health mislabeled. Adjust `units.type` if intensivist/critical-care scoping
   matters.

Deliverable: a `seed-and-verify` checklist run (the read-only diagnostic queries in
`DATA-PLAUSIBILITY.md` §"Diagnostic queries") that must come back clean per wave.

---

## 6. Client integration (iOS + Android, in lockstep)

The architecture is persona-ready; the integration points are small and known.

### 6.1 Extend the role catalog

- **iOS** `Features/Onboarding/Role.swift`: add `or_nurse`, `periop_manager`, `capacity_lead`,
  `executive`, `staffing_coordinator`, `pi_lead` (id, title, subtitle, SF Symbol, `unitBound`).
  Keep `matching(serverRoles:)` mapping server role names → ids.
- **Android:** `data/Models.kt` now contains `MobileRoleCatalog` with all 14 role ids, and
  `AltitudeViewModel`/`ui/altitude/AltitudeScreens.kt` use it to select persona/domain for altitude,
  activity, patient-context, and Eddy context calls. Remaining work: map that catalog into the
  same final role-package UX as iOS (`RoleExperience`/`HomeKind` equivalent plus onboarding and
  feature-package routing).

### 6.2 Make `RoleExperience` route to a _feature package_, not just census scope

Today `RoleExperience` returns `{ homeTitle, homeFocus, censusScope, queueFilter }`. Extend it
with a **`home: HomeKind`** and **`featureTab: FeatureRoute?`** so a role selects which screen
package renders, e.g.:

```swift
enum HomeKind { case census(CensusScope)   // P3, P5 (existing)
                case transportJobs          // P1
                case evsTurns               // P2
                case orBoard                // P4, P7
                case capacityDemand         // P6
                case houseBrief             // P9
                case staffing               // P10
                case improvement }          // P8
```

`MainTabView` renders Home from `HomeKind`; the second tab stays **For You** for everyone; a
role with a distinct workspace (transport, EVS, OR, staffing, PI) gets that as its Home, with the
census glance demoted to a tile/secondary. House/ops/exec roles keep census-style homes.

### 6.3 Build feature screens (new folders, mirror both platforms)

| Package      | iOS                                                                              | Android                                                                |
| ------------ | -------------------------------------------------------------------------------- | ---------------------------------------------------------------------- |
| Transport    | `Features/Transport/{TransportJobsView, JobDetailView, HandoffSheet}`            | `ui/transport/{TransportJobsScreen, JobDetailScreen, HandoffSheet}.kt` |
| EVS          | `Features/EVS/{BedTurnsView, TurnDetailView}`                                    | `ui/evs/{BedTurnsScreen, TurnDetailScreen}.kt`                         |
| OR           | `Features/OR/{RoomBoardView, CaseDetailView, SafetyNoteSheet}`                   | `ui/or/{RoomBoardScreen, CaseDetailScreen}.kt`                         |
| Capacity/Ops | `Features/Capacity/{CapacityDemandView, ApprovalsInboxView, ApprovalDetailView}` | `ui/capacity/*`                                                        |
| Executive    | `Features/Executive/{HouseBriefView, StrainDetailView}`                          | `ui/executive/*`                                                       |
| Staffing     | `Features/Staffing/{StaffingView, RequestDetailView}`                            | `ui/staffing/*`                                                        |
| Improvement  | `Features/Improvement/{ImprovementView, PdsaDetailView}`                         | `ui/improvement/*`                                                     |

Reuse the existing design-system components (`KpiTile`, `Panel`, `StatusChip`,
`RetryableMessage`) — no new primitives. Each screen: one BFF call, `RetryableMessage` on error,
`meta.stale` → a "stale" chip, `links.web` → an "Open in Zephyrus" row.

### 6.4 Networking + models

- Add DTOs to `Networking/Models.swift` (iOS) / `data/Models.kt` (Android) for each new envelope
  (`TransportJob`, `EvsTurn`, `ORRoom`, `ORCase`, `Approval`, `StrainBrief`, `StaffingPlan`,
  `PdsaCycle`). Add `APIClient`/`ApiClient` methods per endpoint.
- Mutations send `meta.version`; on **409** surface "changed since you loaded," re-snapshot.
- Realtime: subscribe to the role's PHI-free channel (`hospital.beds` for census; add `or.board`
  / domain channels as the backend broadcasts them); **re-snapshot on every reconnect** (Reverb
  doesn't replay); keep the 15 s poll fallback.

### 6.5 For-You feed growth (backend, one place)

Extend `ForYouController` to compose the new domains (transport STAT, EVS isolation/overdue,
safety-note SLA, approval pending, staffing gap, PDSA-stage-due), each tier-ranked by earned
urgency and carrying an inline action verb. The client queue component is unchanged.

---

## 7. Phased TODO (checklist)

### Wave 0 — foundations & data (do once, blocks everything)

- [x] **Add the 6 missing roles** (`or_nurse`, `capacity_lead`, `periop_manager`,
      `staffing_coordinator`, `pi_lead`, `executive`) to `Role.swift` + tailored `RoleExperience`
      cases (honest census/queue proxies per the `transport`/`evs` precedent). **iOS build
      passes.** 2026-06-30.
- [x] **Census display semantics** — verified already correct in this branch (see §5.4); no change.
- [ ] **Android role-package parity** — Android now has `MobileRoleCatalog`,
      `AltitudeViewModel`, `ui/altitude/AltitudeScreens.kt`, and altitude/patient-context/activity/Eddy client
      calls. It still needs the final iOS-parity role experience mapping, onboarding/persona
      switcher polish, and bespoke per-role feature packages.
- [ ] **`HomeKind`/`featureTab`** — land per-wave with the first bespoke Home (Wave 1 Transport),
      not as speculative scaffolding now.
- [ ] ⛔ **Re-seed demo DB + DB fixes** — _blocked on environment._ `.env` points at the **shared
      remote** `zephyrus` (192.168.1.58), not a disposable local `zephyrus_dev`, and Docker is
      down. Do **not** `migrate:fresh`/mutate here. **Decision needed:** spin up a local Docker
      `zephyrus_dev` to seed+fix against, or explicitly authorize re-seeding the shared DB.
- [ ] **ED bed inventory + stray active encounters** (data fixes) — gated on the above.
- [x] **Reconcile `/for-you` ↔ `/foryou`** path naming — `/for-you` is canonical in Laravel,
      OpenAPI, iOS, and Android.
- [ ] **Persona test harness:** confirm `HB_ROLE=<id>` (iOS) auto-selects each new persona for
      simulator validation (needs a reachable backend — see env decision above).

### Wave 1 — Transport, EVS, Charge RN, Bed Manager (all live)

- [x] **Transport (P1) — BFF + iOS built, compiles.** `MobileTransportController` (`/transport/
queue` read; `/transport/requests/{id}/status` + `…/handoff` mobile:act) reshaping
      `TransportOperationsService`, PHI-minimized. iOS: `TransportJob` DTOs, APIClient methods,
      `RoleExperience.HomeKind` (`transport → .transportJobs`), role-adaptive `MainTabView` first
      tab, `TransportJobsView` (My Trips + metrics + STAT banner) + `JobDetailView`
      (claim-and-run stepper) + `HandoffSheet`. **iOS BUILD SUCCEEDED** and **✅ validated live on
      the simulator** (2026-06-30) against the BFF + fixed DB — 8 jobs / 2 STAT render correctly.
- [x] **EVS (P2) — BFF + iOS built, compiles.** `MobileEvsController` (`/evs/queue` read;
      `/evs/requests/{id}/status` mobile:act) reshaping `EvsOperationsService`. iOS: `EvsTurn`
      DTOs, `RoleExperience.HomeKind.evsTurns`, `BedTurnsView` (Next Dirty Bed + pending/overdue/
      isolation metrics) + `TurnDetailView` (Claim → Start → Complete + isolation SOP/PPE
      callout). **iOS BUILD SUCCEEDED** and **✅ validated live on the simulator** (2026-06-30) —
      6 turns / 1 isolation / 2 overdue render correctly with the ISO badge.
- [x] **RTDC actions + Bed Manager (P5) — done + simulator-validated.** `/rtdc/barriers/{id}/
resolve` (one-tap inline Resolve in For-You) **plus** `/rtdc/house` (roll-up), `/rtdc/
bed-requests` (pending placements), `…/{id}/recommendations` (ranked beds + transparent
      score/safety chips), `…/{id}/decision` (mobile:act → `BedPlacementService`, server
      re-validates safety). iOS: `HomeKind.houseCapacity` → `HouseCapacityView` (roll-up +
      placements + pressured units) + `PlacementDetailView` (recommendation review → Place/Reject).
      **iOS BUILD SUCCEEDED + validated live** (77% occupancy, 12 placements render). Still pending:
      bespoke **Charge Nurse (P3)** home (unit board with barriers + inbound + staffing — currently
      rides the census proxy).
- [ ] Android: extend the existing `MobileRoleCatalog` + altitude shell into full role-package UX
      parity, then add the Transport/EVS packages at parity.
- [x] **For-You enriched** — now cross-domain: pending bed requests + open barriers + at-capacity
      units **+ STAT/at-risk transport + overdue/isolation bed-turns**, tier-ranked, with the
      inline barrier Resolve action. iOS build ✅.
- [ ] Validate each persona on simulator **and** emulator against the seed (see §8).

### Wave 2 — OR board, Capacity Lead, Executive (live; OR fixes first)

- [ ] Backend: land the 3 perioperative fixes (`store` status_id; analytics columns; reference
      `active_status`).
- [ ] BFF: `/or/board|cases/{id}/status|performance`, `/command/house|brief`, `/ops/inbox|
approvals/{id}/decision|actions/{id}/{transition}`.
- [ ] iOS + Android: OR (P4/P7), Capacity/Ops approvals (P6), Executive brief (P9) packages; add
      `or_nurse`/`periop_manager`/`capacity_lead`/`executive` roles.
- [ ] For-You: safety-note SLA, approval-pending, OR delay/cancellation sources.
- [ ] Validate (incl. an induced breach for the exec "one thing").

### Wave 3 — Staffing + ED signals + huddles

- [x] BFF: `/staffing/overview` + `/staffing/requests/{id}/fill`; add to contract.
- [ ] BFF: add `/ed/signals` if a dedicated mobile ED signal endpoint is still needed.
- [ ] iOS + Android: Staffing (P10) package + `staffing_coordinator` role; ED-signals tile in
      house/exec homes.
- [ ] (P2) RTDC huddle action-items if pulled forward.
- [ ] Validate against seeded 2 gaps / 2 requests / 5 ED boarding.

### Wave 4 — PI / PDSA (net-new backend)

- [x] BFF: `/improvement/pdsa` + `/improvement/opportunities`; add to contract.
- [ ] Backend/BFF: build the PDSA write/advance API (`/improvement/pdsa/{id}/advance`) if mobile
      stage advancement remains in scope.
- [ ] iOS + Android: Improvement (P8) package + `pi_lead` role.
- [ ] For-You: PDSA-stage-due, barrier-assigned sources.
- [ ] Validate against 5 active cycles / 6 opportunities.

### Cross-cutting (every wave)

- [ ] OpenAPI conformance test passes for new paths (envelope, 409, error enum).
- [ ] `php artisan test --testsuite=Feature` green (BFF controllers + policies).
- [ ] Notifications: map each new top-tier For-You item to an actionable push category.
- [ ] Update [`reference/01`](reference/01-feature-parity-matrix.md) Phase column + contract
      `Status/TODO` as paths land.

---

## 8. Test & validation strategy

### 8.1 Backend / BFF (fast, deterministic)

- **Conformance:** lint + validate against `hummingbird-bff.v1.yaml`; every new path returns the
  envelope, honors `mobile:read`/`mobile:act` abilities, returns 409 on stale `meta.version`.
- **Feature tests:** one `Feature` test per high-value endpoint asserting the **seeded** shape
  (e.g. `/transport/queue` returns active jobs with a STAT/at-risk case, `/or/board` returns room
  metrics, `/staffing/overview` returns open staffing requests). Deterministic seed → exact
  assertions.
- **PHI guard test:** assert no patient identifiers/free-text in any list payload or push body.

### 8.2 Client (per-persona, on a visible simulator + emulator)

Per the project rule, **run the iOS Simulator and Android emulator visibly on screen, never
headless** (see memory `run-simulators-visibly`). For each persona:

1. Launch with the persona forced: iOS `HB_AUTOLOGIN=1 HB_ROLE=<id>` (+ `HB_OPEN_UNIT` for
   unit-bound), Android the equivalent role env.
2. **Glance check:** Home renders the persona's package with the **seeded** numbers (the wireframe
   targets in §3) — correct title, metrics tabular, status with arrow/icon (never color alone),
   calm by default.
3. **Action check:** exercise the inline verb end-to-end (Claim a trip, Resolve a barrier,
   Approve an action, Complete a turn) and confirm the item clears from Home **and** For-You and
   the change round-trips (re-snapshot/WS or poll reflects it).
4. **Honest-empty check:** a persona whose backend isn't live yet shows the framed empty state,
   not a broken/blank screen.
5. **Deep-link check:** "Open in Zephyrus" opens the correct authenticated web surface.
6. **Capture a GIF** of each persona's glance→action loop for the demo/review (name it
   `persona-<id>-loop.gif`).

### 8.3 Acceptance gates (per wave)

A wave is "done + validated" when, against a freshly re-seeded demo DB:

- every persona in the wave passes its §3 **Acceptance** line on **both** platforms,
- the BFF conformance + Feature tests are green,
- the `DATA-PLAUSIBILITY` diagnostics for that wave's screens return clean,
- no console errors on the persona's glance→action loop,
- the For-You queue tier-ranks the wave's new sources correctly (most-urgent first).

### 8.4 Regression

- Re-run the existing 214 `Feature` tests + the Home/For-You persona-filter tests each wave.
- Snapshot/visual diff the two original screens to confirm no regression as `HomeKind` routing
  lands.

---

## 9. Risks & open questions

- **OR backend fixes are a hard gate for Wave 2.** The three `ORCase`/analytics/reference bugs
  must land first or the board renders garbage. Sequence them as the first Wave-2 backend tasks.
- **PI/PDSA writes are genuinely net-new.** The mobile BFF now exposes PDSA/opportunity reads;
  stage advancement remains backend-led and should stay behind the live-data waves.
- **Reverb edge proxy.** Production Reverb (`:8080`) isn't proxied at the Apache edge today, so
  realtime falls back to 15 s polling. New live-board screens (OR, capacity) feel best on WS —
  decide whether to fix the proxy in Wave 2 or accept polling for the demo.
- **Android role-package parity.** Android has the role catalog and altitude shell, but iOS
  `RoleExperience` is still ahead for final feature-package routing. Wave 0/1 must finish that
  parity so personas are validated on both platforms, not iOS-only.
- **Server role vocabulary.** `/me.roles[]` must carry enough signal to pre-select the 6 new
  roles; confirm the web role names map cleanly via `matching(serverRoles:)`, or add an explicit
  mapping table.
- **Demo vs. real divergence.** All acceptance targets assume the deterministic seed. If a demo
  is run against drifted data, re-seed first — the §5 gate exists for this reason.

---

## 10. One-paragraph summary

Hummingbird already has the _skeleton_ for persona-driven mobile (role catalog, `RoleExperience`,
a uniform PHI-minimized BFF envelope, a universal For-You queue) and the web backend already has
_live, seeded data_ for every pillar — but only 2 of 10 personas have real screens, and the BFF
exposes only census + queue + Eddy. This plan builds the missing 8 persona **packages** (Home +
feature screens + For-You feed + deep-links), wiring the **already-contracted** BFF paths to
existing live services in four readiness-ordered waves (frontline RTDC/transport/EVS → OR +
decisions + exec → staffing/ED → PI/PDSA), gated on a one-time data-plausibility cleanup, and
validates each persona end-to-end against the deterministic seed on visible iOS + Android
simulators.
