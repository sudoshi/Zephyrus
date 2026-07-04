# Zephyrus 2.0 — The Operations Cockpit

### Master Evolution Plan

**Status:** Draft for review &middot; **Date:** 2026-06-28 &middot; **Owner:** Dr. Sanjay Udoshi (Acumenus Data Sciences)

**Rigorous. Composed. Defensible.**

> This plan evolves Zephyrus from a disjointed set of six parallel dashboards and eight
> navigation silos into a single, cohesive **Hospital Operations Cockpit** — anchored to the
> reference in `docs/Hospital Operations Cockpit/` and built **additively** on the assets
> already shipped to `main`. It is an evolution of a live, deployed app, not a rewrite.

---

## Provenance & method

Produced by a **22-agent planning swarm** (Workflow `wf_0d8f0c66-501`, 2026-06-28):

- **13 read-only discovery agents** inventoried every subsystem (navigation/IA, the existing
  Command Center, RTDC, ED, Perioperative, Process Improvement, Analytics, the flow domains,
  the data/services layer, the design system, Eddy + the ops control plane, the API/real-time
  infra) and reverse-engineered the cockpit reference prototype.
- **5 architecture agents** reconciled the design language, information architecture, backend
  foundation, frontend component system, and intelligence layer.
- **3 synthesis writers** produced the vision, the phased roadmap, and the risk/testing chapters.
- **1 adversarial critic** audited the assembled plan for gaps, contradictions, and
  canon-violation risks.

Every claim is grounded in the live tree (file:line citations throughout). The swarm verified
the app at **137 pages, 282 components, ~87–91 services, ~70–72 controllers, 106 models, 66
migrations**, and confirmed the central finding: **~60–80% of the cockpit already exists on
`main`** — this is overwhelmingly a *consolidation and connection* effort.

> **Read Part II first.** It captures the critic's required corrections. Where Part II conflicts
> with a later chapter, **Part II wins** — the chapters are the swarm's first draft; Part II is
> the correction layer applied after adversarial review.

## How this document is organized

| Part | Contents |
|---|---|
| **I** | Vision, Current-State Assessment & the Cohesion Thesis (the *why*) |
| **II** | Critical Corrections & Decisions (must-fix items + resolved decisions) |
| **III** | Product Cohesion & Information Architecture (the Altitude model) |
| **IV** | Unified Design Language & Status Vocabulary (the canon reconciliation) |
| **V** | Frontend Component System & Drill Architecture |
| **VI** | Backend Foundation — StatusEngine, kpi_definitions, SnapshotBuilder, serving layer |
| **VII** | Intelligence Layer — Alerting, the Eddy Loop, Predictive Overlays |
| **VIII** | Phased Migration Roadmap (P0 → P8, brownfield, additive, flag-gated) |
| **IX** | Risks, Guardrails, Testing & Success Metrics |
| **App. A** | Adversarial Critique (full) |

---

# Part I — Vision, Current State & the Cohesion Thesis

## Chapter 1 — Executive Summary

Zephyrus today is a capable hospital operations platform trapped inside a fragmented shell. The backend is genuinely strong — production-grade event sourcing in RTDC, accurate percentile-based ED and OR analytics computed live from `prod.*` fact tables, a fully merged Eddy governance control plane, and a token canon so disciplined that raw-color violations have fallen from 4,261 to roughly 600. But that strength is scattered across **six parallel overview dashboards**, **eight sibling navigation domains**, and a sprawl of **137 pages, 282 components, 91 services, 72 controllers, and 106 models** (counts verified live, 2026-06-28). There is no single front door, no shared definition of "is the house OK," and no deterministic path from a glance to a decision. The same number — house occupancy — is computed by three independent SQL queries; door-to-provider by three more; prime-time utilization by four, *with different denominators*. Five overview routes (`/dashboard/rtdc`, `/dashboard/perioperative`, `/dashboard/emergency`, `/dashboard/improvement`, `/dashboard/transport`, confirmed at `routes/web.php:50–54`) each re-implement a silo of the same truth, and a seventh orphaned `/home` route (`routes/web.php:37`) ships dead mock data to production.

**Zephyrus 2.0 collapses this into one cohesive, tightly-knit operations application built around a single north star: the Hospital Operations Cockpit.** The cockpit is a single-screen, 24/7 ISA-101 high-performance-HMI situational-awareness surface where grey is the baseline, saturated color is an exception signal, and status is encoded by *shape plus color*. It becomes the one home at `/dashboard`, and the *same* surface, with chrome stripped, becomes the kiosk/wall mode at `/dashboard?display=wall`. Every domain becomes reachable by one deterministic descent: glance → drill-in-place → live workspace → retrospective study.

The 2.0 plan is **emphatically evolutionary, not a rewrite.** The existing `/dashboard` Command Center is already ~60% aligned to the cockpit spec: a single server-computed snapshot via `CommandCenterDataService::build()`, a frozen Zod contract (`types/commandCenter.ts`), thirteen canon-clean components (`Components/CommandCenter/*`), a working drilldown API, and a RoleSwitcher persona prototype. The path forward is **additive**: extract one `StatusEngine` from the duplicated band logic, formalize `kpi_definitions` on the already-shipped `ops.metric_definitions` schema, decompose the data service into reusable per-domain Metrics classes, assemble one `cockpit_snapshots` row per facility, and surface it through the existing Reverb/SSE infrastructure — then close the loop by wiring warn/crit tiles into the already-merged Eddy `ops.agent_*` control plane through its human approval gate.

The plan honors three hard constraints without exception: (1) **the enforced design canon** — Figtree + tabular-nums, four healthcare-* status tokens plus grey, blue (not cyan) accent, one Surface primitive — is made *stricter*, never weaker; the cockpit's ISA-101 philosophy is adopted in full while its reference tokens (IBM Plex, OKLCH, a fifth green) are rejected entirely. (2) **The protected authentication system** (temp-password + Resend flow, `ChangePasswordModal`, `must_change_password`) is untouched. (3) **Production data is sacred** — every change is additive and reversible; no URL is hard-deleted that carries a real bookmark; the five redundant dashboards become graceful redirects, not 404s.

The outcome is one home, one design language, one status engine, one snapshot contract, one primitive library, one navigation source, and one closed action loop — a command center that finally reads as a *single instrument*, not a binder of separate dashboards.

---

## Chapter 2 — The 2.0 Vision & North Star: The Cohesion Thesis

### 2.1 What "disjointed mess → cohesive application" means concretely

"Cohesion" is not a feeling; it is **seven specific unifications**, each replacing a concrete fragmentation with a single source of truth. The thesis of Zephyrus 2.0 is that *the cockpit is the organizing force that makes all seven true at once.*

| # | Today (fragmented) | 2.0 (unified) | The single source of truth |
|---|---|---|---|
| 1 | **One home** | Six overview dashboards + an orphaned `/home` | One cockpit home; every other overview folds into it | `/dashboard` (`CommandCenter.tsx`) |
| 2 | **One design language** | Five layout shells, two color-state vocabularies (canon's 4 vs cockpit's 5), an unenforced grey-baseline | One ISA-101 render discipline expressed entirely through the canon | DESIGN.md canon + `statusStyle()` |
| 3 | **One status engine** | `bandHighBad`/`bandLowBad` duplicated (`CommandCenterDataService:421/437` + `OperationsAnalyticsService:748`) + ~117 ad-hoc status strings | One threshold resolver emitting a 5-logical-state vocabulary | `StatusEngine.php` ← `kpi_definitions` |
| 4 | **One data/snapshot contract** | 15+ synchronous queries per `/dashboard` load; no cache; metrics never emit a shared shape | One server-computed `cockpit_snapshots` JSONB row, one `MetricValue` shape | `SnapshotBuilder` + `MetricValue` |
| 5 | **One primitive library** | Sparkline/MeterBar inlined; StatusChip/CensusChip/DataTable/DrillModal absent; 9 ad-hoc modals | One shared `Components/cockpit/*` set on the Surface primitive | `Components/ui/Surface.tsx` + `cockpit/*` |
| 6 | **One navigation source** | `navigationConfig.ts` (8 flat domains) shadowed by a dead 486-line `DashboardContext.tsx` duplicate | One nav SSOT restructured into four altitude-aligned sections | `navigationConfig.ts` |
| 7 | **Eddy closing the loop** | Cockpit, Eddy dock, AgentInbox, Opportunity Portfolio are four separate surfaces | One loop: warn/crit tile → Eddy proposal → human approval → audited action | `ops.agent_*` + `OperationalActionLifecycleService` |

When all seven hold, a charge nurse, a bed manager, and a CMO read **the same truth at different altitudes** — the literal "Operations Bridge" north star of DESIGN.md, finally realized as software rather than aspiration.

### 2.2 The cockpit as the anchor

The cockpit is not a new feature bolted onto Zephyrus; it is the **gravity well** that pulls the scattered sections back into one body. Anchored to `docs/Hospital Operations Cockpit/docs/COCKPIT_IMPLEMENTATION.md`, the north star is precise:

- **One screen, no scroll for the overview** (§0.2). The overview fits a 1080p display. Depth lives behind drills, never below a fold.
- **Grey baseline, color as exception** (§0.1). A calm screen reads near-monochrome — slate, grey, near-white — and saturated color appears *only* to mark an abnormal state. This is the literal, stricter expression of the canon's own Earned-Red rule.
- **Shape + color, never color alone** (§0.2). Five state glyphs — normal `–`, ok `●`, watch `●`, warn `▲`, crit `◆` — so meaning survives grayscale, color-blindness, and a wall display across the room. This *is* the canon's Status-Never-Alone rule, made non-negotiable.
- **Every panel header and OKR card is a drill-down entry point** (§0.3) that opens a full-screen modal with a KPI strip plus detail tables.
- **One server-computed snapshot drives the whole overview** (§3.2), and a **central StatusEngine resolves every threshold once** (§4).

This is the same product DESIGN.md and PRODUCT.md already describe — "calm command-and-control for the overview, unmistakable urgency for what's breaching" — said in the dialect of process-control engineering. The cockpit makes the principles *operational* instead of merely stated.

### 2.3 The dual-presentation thesis: one surface, two modes

The cockpit is **both** the interactive house-wide HOME and a chromeless wall/kiosk MODE of the *same* page — never two builds. `/dashboard` is the full-interactivity home (drill-modals, persistent RoleSwitcher, Eddy hand-off, Cmd+K, dual-theme toggle). `/dashboard?display=wall` is the same component tree with interactive chrome dropped, scaled to wall-glance typography, running a 1Hz clock and the AlertTicker, and **locked to dark theme** (a bright wall in a dark unit is hostile). One snapshot, one StatusEngine, one component tree; the mode is a presentation flag read by the layout shell. This single decision dissolves the false choice between "the dashboard staff click through" and "the board on the wall" — they are the same instrument at two distances, exactly as PRODUCT.md demands ("read at a 3-second glance during a surge *and* pored over line-by-line in a Monday review").

### 2.4 The Altitude model — the spine that ties it together

Cohesion needs a *mental model users learn once and apply everywhere.* That model is **Altitude**: every screen in 2.0 lives at exactly one of four altitudes, and the descent path is always the same.

| Altitude | Question it answers | Surface | Lives at |
|---|---|---|---|
| **A0 — Glance** | "Is the house OK right now?" (3-second read) | The Cockpit overview, one screen, no scroll | `/dashboard` |
| **A1 — Workspace** | "Show me everything in this domain, live" | A live domain operating surface (boards, queues, placement) | `/rtdc/*`, `/ed/operations/*`, `/operations/*`, `/transport/*`, `/staffing`, `/flow` |
| **A2 — Drill** | "Why is *this one tile* red?" | A full-screen DrillModal over the cockpit (KPI strip + tables) | `/dashboard?drill={domain}` |
| **A3 — Study** | "What is the pattern over weeks; what should we change?" | Analytics deep-dives, process mining, PDSA, opportunity portfolio | `/analytics/*`, `/improvement/*` |

The descent is always **A0 → (A2 drill in place) → A1 workspace → A3 study.** The same domain — ED, say — appears at A0 as a cockpit panel, at A1 as a live track-board, and at A3 as a retrospective wait-time study. The boundary is **temporal, not topical**: anything answering "now" is a Workspace; anything answering "over time / why / what-if" is a Study. Crucially, **both altitudes read the same StatusEngine output**, so a tile that is `crit` at A0 is `crit` in its A3 trend — the connective tissue that keeps live and analytic surfaces telling *one story* instead of three contradictory ones (today `bandHighBad` is duplicated across the live and analytics services; the extraction is what makes the story singular).

### 2.5 Audiences are one home reshaped by persona — never three home pages

PRODUCT.md is explicit: Zephyrus serves "a mixed command-center audience, switched between by role rather than split across separate products." 2.0 honors this with **one home and a persona control**, never three URLs. The existing `RoleSwitcher` (`Components/CommandCenter/RoleSwitcher.tsx`, `command | executive | service-line`) graduates from a `/dashboard`-only widget into persistent app chrome beside the TopNavbar, URL-synced via `?role=`. Persona changes **never change the route** — they re-rank the bands and swap the HeroWall for the OKR Scorecard within the same page:

- **Frontline unit staff** (`command`): Capacity/Flow/ED panels lead; "my unit" emphasis; descent goes A2 drill → A1 workspace.
- **House-wide ops leaders** (`command`, house scope): full panel grid + AlertTicker + StrainIndex; drill across domains.
- **Executives** (`executive`): OKR Scorecard leads; bands reorder Outcomes-first; descent goes A2 OKR drill → A3 study.

This is "adaptive emphasis by role and moment" (Design Principle 1) rendered as a single switch on a single surface.

### 2.6 Eddy closes the loop

A cockpit that only *renders* status is half a product. 2.0's payoff is that the cockpit *prosecutes* exceptions. The governance spine already exists on `main` and is reused verbatim: a warn/crit tile or AlertTicker entry hands off to Eddy pre-seeded with the offending `MetricValue` and the matching `EddyActionService::CATALOG` action (the five tiered actions — `flag_barrier`, `propose_huddle_action`, `propose_transport_dispatch`, `propose_bed_placement`, `propose_surge_plan` — are confirmed live at `app/Services/Eddy/EddyActionService.php:31–35`). Eddy *drafts*; its scoped token holds `ops:draft` but never `ops:approve`; a human approves through `OperationalActionLifecycleService`'s state machine, which records the audit trail. Alert → recommendation → approval → audited action becomes one loop on one snapshot — the "advice, not autopilot" contract, and the final piece of cohesion. This is gated behind `EDDY_ENABLED` (ships disabled), preserving the established ship-disabled precedent.

---

## Chapter 3 — Honest Current-State Assessment: The Fragmentation

This is a candid inventory of the disjointedness, grounded in the discovery digest and verified against the live tree. The intent is not to disparage strong work — the backend is excellent — but to name precisely what 2.0 must unify.

### 3.1 The six-dashboard problem (the headline fragmentation)

There are **six independent overview surfaces** plus a dead seventh, each with its own data pipeline, threshold logic, layout shell, and visual treatment:

1. **`/dashboard`** — `CommandCenter.tsx`: live single-snapshot, 45s poll, HeroWall + Bands + OkrScoreboard, RoleSwitcher. *The nucleus — canon-clean and ~60% cockpit-aligned.*
2. **`/dashboard/rtdc`** — `Dashboard/RTDC.jsx`: live via `RtdcDashboardService`, with mock fallbacks.
3. **`/dashboard/perioperative`** — `Dashboard/Perioperative.jsx`: live via `PerioperativeMetricsService`.
4. **`/dashboard/emergency`** — `Dashboard/ED.jsx`: live, mock fallbacks.
5. **`/dashboard/improvement`** — `Dashboard/Improvement.jsx`: live via `DashboardService`, static mock metrics.
6. **`/dashboard/transport`** — `Transport/Dashboard.tsx`: TanStack Query, live Transport API.
7. **`/home`** — pure hardcoded mock, **unreachable from nav, still shipping to prod** (`routes/web.php:37`).

**No shared data shape, no shared freshness signal, no shared threshold resolver.** Each is a silo with its own answer to "is the house OK." Verified: all five `/dashboard/*` routes and `/home` exist in `routes/web.php` exactly as described.

### 3.2 Navigation and layout fragmentation

Navigation *is* driven by a single config (`navigationConfig.ts`) — architecturally sound and the right SSOT to keep — but it defines **eight flat sibling domains** (RTDC, Transport, Staffing, Perioperative, Emergency, Improvement, Analytics, Admin; verified live) with no organizing model. Worse, a **dead 486-line `DashboardContext.tsx`** (verified) duplicates the *entire* nav inventory as a conflicting `workflowNavigationConfig` with **zero consumers** beyond its own mount in `Providers.tsx` — a second, shadow nav source that the 2.0 SSOT makes redundant.

Layout is split across **five shells** — `DashboardLayout` (32 pages), `RTDCPageLayout` (14), `AuthenticatedLayout` (12), `AnalyticsLayout` (5), `TransportLayout` (5) — each its own TopNavbar instance, each its own visual treatment. A security wrinkle compounds the cost: the `ChangePasswordModal` is present only on `AuthenticatedLayout`-derived pages and **absent from the 51 pages** under `DashboardLayout`/`RTDCPageLayout`/`TransportLayout`. The server-side login redirect provides the initial gate, but unifying onto one app shell closes the in-app gap and is one of the quieter wins of 2.0. (The protected auth system itself is untouched; this is purely about which shell renders the modal.)

### 3.3 Duplicated computation and threshold logic

The same metric is computed by independent SQL in multiple services — a direct violation of "the data underneath is defensible," because three queries can disagree:

| Metric | Computed in |
|---|---|
| House occupancy | `OperationsAnalyticsService`, `CommandCenterDataService`, `Rtdc/UtilizationAnalyticsService` (3× independent SQL) |
| Door-to-provider / LWBS | `OperationsAnalyticsService`, `EdDashboardService`, `CommandCenterDataService` |
| Prime-time utilization | `OrUtilizationService`, `PrimetimeUtilizationService`, `PerioperativeMetricsService`, `CommandCenterDataService` — **different denominators** |
| Block utilization | `BlockUtilizationService`, `PerioperativeMetricsService`, `CommandCenterDataService`, `OperationsAnalyticsService.retrospective()` |

Threshold logic is the same story: `bandHighBad`/`bandLowBad`/`bandProgress` live in `CommandCenterDataService` (verified at lines 421/437/452) and are duplicated in `OperationsAnalyticsService` (line 748), with ~117 additional ad-hoc status strings scattered across 20+ services. There is **no `StatusEngine`** — every service hand-codes its own 2–3 state thresholds, so the same condition can read "warning" on one screen and "critical" on another.

### 3.4 The Analytics / PI / Staffing / Transport silos

- **Analytics** is structurally *two unrelated layers* sharing a URL prefix: an Operations Intelligence Hub (one 1,427-line `Analytics.jsx` polling eight endpoints, three sections live, five "planned") and five Surgical Deep Dives (`/analytics/block-utilization` etc.) that **appear verbatim under both the Perioperative and Analytics nav domains** — a literal duplicated nav leaf. Per-domain analytics silos (`/rtdc/analytics/*`, `/ed/analytics/*`, `/transport/analytics`) form three more unrelated trees. Dead code ships to prod: `PatientFlowDashboard` (321 lines, no route), `ProviderDashboard`, `ServiceDashboard`, `TrendsOverview`, and ~2,400 lines of mock-data files.
- **Process Improvement** is thin scaffolding over a production-grade Ops layer. Its one live crown jewel — `DashboardService::getBottleneckStats()` (five live bottleneck signals) — is buried behind `/improvement/bottlenecks` and never reaches the overview. Process maps load from static JSON in `public/mock-data/`; root causes are a hardcoded PHP array; PDSA stages are synthesized from a modulo of the cycle ID. The `PdsaCycle → Ops::Intervention` attribution bridge exists but is surfaced nowhere.
- **Staffing** is the least-developed silo: a single `StaffingOffice.tsx` page over a clean `StaffingOperationsService::overview()`, but OT hours, callouts, sitter demand, HPPD, and agency-RN counts have no schema columns (resource-pool inventory is hardcoded statics).
- **Transport** is the most analytically capable service (`measures()` computes six throughput KPIs) yet has no single end-to-end wait-time KPI, no time-bounded event scan (full-table risk), and no snapshot/cache layer. EVS computes no bed-turnaround time despite having the timestamps. `AncillaryServices.jsx` is the one fully mock-backed page in an otherwise live app.

### 3.5 The serving-layer gap

Every `/dashboard` load re-executes the full query battery — **15+ synchronous queries with no cache**. There is no `cockpit_snapshots` table, no background refresh job, and no SSE broadcast for the cockpit. The *only* scheduled job in `bootstrap/app.php` is `ReconcileRtdcPredictions` (daily). Sparklines are mostly theatre: 27 of 30 metrics use deterministic crc32+sin/cos noise rather than real history. And a production footgun lurks: `BROADCAST_CONNECTION` defaults to `'null'`, so Reverb broadcasts are silently dropped in prod — the live-update story works in dev and is dead in production until the env var is set.

### 3.6 The good news embedded in the assessment

The fragmentation is wide but shallow, because the foundations 2.0 needs *already exist*: the `ops.metric_definitions` trust schema (migration `2026_06_26_000010`, verified) maps almost 1:1 onto the spec's `kpi_definitions`; `CommandCenterDataService::build()` is a working proto-`SnapshotBuilder`; `Components/CommandCenter/*` is a canon-clean proto-primitive-library (`Panel.tsx` is literally `export { Surface as Panel }`, verified); the Eddy `ops.agent_*` control plane and `OperationalActionLifecycleService` FSM are fully merged; `HospitalManifest` + `config/hospital/hospital-1.php` is a 25-unit Summit Regional SSOT ready to drive `facilities`/`units`. **2.0 is overwhelmingly a consolidation and connection effort, not a greenfield build.**

---

## Chapter 4 — The 2.0 Design Principles

The five PRODUCT.md principles remain the constitution. 2.0 *extends* them with five operating principles that turn "cohesion" from a goal into an enforceable discipline. Each new principle inherits a parent and adds the rule the cockpit makes necessary.

### Principle 6 — One Home, One Descent
*(extends: "Defensible at a glance and in depth")*

There is exactly one front door (`/dashboard`) and exactly one descent path (A0 glance → A2 drill → A1 workspace → A3 study). Depth is always behind a drill or a workspace link; the overview never scrolls. Every other overview either keeps its live URL as a Workspace, is repointed, or is gracefully redirected — **no URL that carries a real bookmark is ever hard-deleted** (the five `/dashboard/*` overviews become redirects into the matching `?drill=`; only the dead `/home` mock and the dead `DashboardContext.tsx` are removed outright). Cohesion is *navigational determinism*: from anywhere, the way up and the way down are the same.

### Principle 7 — One Truth, Computed Once
*(extends: "Earned urgency — signal is scarce currency" and Replace-the-EHR rigor)*

Every threshold is resolved exactly once, by one `StatusEngine`, reading one `kpi_definitions` config. Every metric is computed once into one server-side `cockpit_snapshots` row and emitted in one `MetricValue` shape. Live and analytic altitudes read the *same* engine output, so a metric cannot be "warning" on one screen and "critical" on another. Defensibility stops being a hope and becomes a structural guarantee: there is one number, with one threshold, with one provenance.

### Principle 8 — Earned Color, ISA-101 Discipline
*(extends: "Earned urgency" — the Earned-Red and Status-Never-Alone rules, made stricter)*

Grey is the resting state of the entire surface. A metric that is merely in-band renders **normal/grey with near-white value text** — never teal-just-to-be-cheerful. Color appears on a value *only* for `warn`/`crit` (and `ok` in its rationed confirmation slots: OKR scorecards, days-since counters, a met hand-hygiene target). The four status colors map cleanly — normal→grey, ok→teal (`success`), watch→sky (`info`), warn→amber (`warning`), crit→coral (`critical`) — with **shape carrying the fifth distinction the four colors cannot** (`– ● ● ▲ ◆`). This is *additive to the canon*: it makes the rules stricter, never weaker, and it passes `scripts/check-ui-canon.sh` and the impeccable hook unchanged. The cockpit's philosophy is adopted in full; its tokens (IBM Plex, OKLCH, a fifth green, cyan) are adopted not at all.

### Principle 9 — One Language, One Primitive Set
*(extends: "Composed under pressure" and Replace-the-EHR clarity)*

Every screen renders through one shared `Components/cockpit/*` library layered on the one Surface primitive (`Components/ui/Surface.tsx`) and the one color bridge (`STATUS_VAR`), driven by one `statusStyle()` helper that mirrors the server `StatusEngine`. Sparkline and MeterBar are extracted (not re-inlined); StatusChip, CensusChip, Panel-as-drill-entry, DataTable, and DrillModal are added; the nine ad-hoc reference modals and the per-section DrillDownModals collapse into one `DrillModal`. No primitive may name a font family, use weight 700, `text-[Npx]`, `font-mono`, or a raw Tailwind palette class. Visual coherence stops depending on per-page diligence and becomes a property of the component layer.

### Principle 10 — Render Status, Then Prosecute It
*(extends: "Adaptive emphasis by role and moment")*

The cockpit does not merely display an exception — it offers the next action and records the decision. Every warn/crit signal is a potential entry point into the closed Eddy loop: alert → recommended action (drafted, never executed) → human approval gate → audited lifecycle. Predictions surface as **watch** (sky), never crit — a forecast is uncertain and must not manufacture false urgency or erode trust. The standalone ExecutiveBrief and AgentInbox surfaces reconcile into the cockpit (an executive-role panel and an alert/action drill), so four surfaces become one loop driven by one snapshot. Cohesion's final form is that *looking and acting happen in the same place.*

---

*These four chapters establish the spine. The chapters that follow — Design Language, Information Architecture, Backend Foundation, Frontend Component System, and the Intelligence Layer — specify, in turn, exactly how each of the seven unifications and ten principles is built, in an additive, prod-safe, canon-compliant sequence on top of the assets Zephyrus already ships.*

---

# Part II — Critical Corrections & Decisions (apply before/at execution)

This plan was put through an adversarial principal-engineer critique after assembly. The verdict
was that the **spine is correct** ("ship the plan after the prioritized fixes; the design-canon
reconciliation is the plan's best work and contains no canon violations; the protected auth
system is respected throughout") — but the review found **one architecture-level contradiction**
and a set of mechanical/sequencing corrections that must be applied so execution does not stall.

> **Precedence rule:** where this Part conflicts with a later chapter (especially Part VI Backend
> and Part VIII Roadmap), **this Part wins.** The chapters are the swarm's first draft; these are
> the corrections verified against the live tree.

## II.1 Must-fix before execution

> **Status (2026-06-28):** all eight corrections below are now folded into the tightened
> Part VIII roadmap, and the seven product decisions are resolved in II.2. This section remains
> as the verified rationale and the precedence record.

1. **Resolve the facility model — ship single-facility now (blocks P0/P1).**
   Several backend passages assume a multi-facility model (`cockpit_snapshots(facility_id PK)`,
   `RefreshCockpitSnapshot` iterating `Facility::active()`). **Verified: there is no `Facility`
   Eloquent model and no `facilities` table** — `CommandCenterDataService` queries `prod.*` with
   zero facility scoping, and the plan elsewhere correctly says "reuse `HospitalManifest`, no new
   facility table." Both cannot hold. **Decision: single-facility.** Make `cockpit_snapshots` a
   single replaced row (no `facility_id` PK), **or** back a lightweight `Facility` value-object
   with `HospitalManifest` so any `Facility::active()` call resolves to the manifest's facility
   list (not a DB table). Any `facility_id` column kept for forward-compat must be nullable and
   manifest-keyed, never a hard FK. **This is a P0 prerequisite, not a P1 detail.**

2. **Add `?drill=` / `?display=wall` param-reading to P2/P3, *before* P4 (sequencing fix).**
   **Verified: `CommandCenter.tsx` reads neither param today.** P4 deprecates five routes to
   redirects landing on `/dashboard?drill={domain}`, expecting the cockpit to auto-open that
   DrillModal — but the param-reading + auto-open logic is never scoped. **Add explicitly:** P2
   reads the params; P3 auto-opens the matching DrillModal; **P4's redirects depend on P2+P3.**
   Without this, every redirected bookmark lands on a bare cockpit with no modal — silently
   degrading "every bookmark preserved" to "bookmark resolves but does nothing."

3. **Harden `scripts/check-ui-canon.sh` to enforce the reconciliation (P0).**
   **Verified: the script enforces only `font-bold`/`font-extrabold` and `text-[Npx]` today**; it
   explicitly defers color/surface/glassmorphism to the *interactive* impeccable hook — which a
   worktree or CI commit can bypass. Since the cockpit reference literally ships OKLCH, cyan,
   `radial-gradient`, and `backdrop-blur`, the scripted gate is the missing guard. **Add scripted
   patterns** for `oklch(`, `backdrop-blur`, and raw `bg-(gray|red|blue|green|amber|indigo|slate)-[0-9]`
   in `resources/js` (excluding sanctioned paths), **and widen the `text-[Npx]` exclusion** to
   allow the wall-mode `text-[11px]` inside `Components/cockpit/`. Documentation does not stop an
   `exit 1`, so the P0 wall-mode exception trips its own gate unless the exclusion path is widened.
   This is the single highest-leverage canon hardening.

4. **Add the missing route dispositions (IA §8 table).**
   The disposition table claims "every current section" but omits live routes verified in
   `routes/web.php`: `/rtdc/global-huddle` (a **POST-bearing** live huddle → assign **A1
   Workspace**), `/improvement/overview|active|opportunities`, the PDSA `create/show/store`
   sub-routes (**A3 Study**), and the `OpsConsoleController` routes (Agent Inbox / Executive
   Brief → reconciled into the cockpit in P6). Also clarify `StaffingDashboardController` vs the
   `StaffingOffice.tsx` page: confirm which is the canonical A1 Staffing workspace.

5. **Split P4 into P4a + P4b with a per-shell smoke checkpoint.**
   Merging five layout shells into one (`DashboardLayout`=32 pages, `RTDCPageLayout`=14,
   `TransportLayout`=5, `AuthenticatedLayout`=12, `AnalyticsLayout`=5) is the single
   highest-blast-radius IA step and is under-allotted at ~2 weeks shared with four other
   workstreams. **P4a** = nav restructure + redirects + RoleSwitcher-to-chrome. **P4b** = layout
   convergence + `ChangePasswordModal` app-wide, gated, with a `RouteSmokeTest` checkpoint *per
   shell* (merging `RTDCPageLayout`'s RTDC-specific chrome into a generic shell can silently strip
   functionality from 14 pages).

6. **Correct the cited file paths before implementation.**
   `OperationsAnalyticsService` is at **`app/Services/Analytics/OperationsAnalyticsService.php`**
   (not `app/Services/`). `DashboardContext.tsx` is mounted in
   **`resources/js/Providers/HeroUIProvider.tsx`** (not `Providers.tsx`); its `useDashboard()`
   hook has zero consumers (so the config payload is dead) **but the Provider is in the live
   render tree** — deletion requires editing `HeroUIProvider.tsx` plus a grep-confirmed sweep, not
   a blind delete. The underlying theses (band duplication, dead config) are correct; only the
   paths were wrong, and a literal-following implementer would fail to find them.

7. **Flag `ed.nedocs` as synthetic-until-P7, and index the MVs.**
   The headline "NEDOCS 142" crit alert that lights the wall for screenshots is **mock** until the
   P7 schema migration adds `vent_pts` / `longest_admit_wait_hrs` / `last_bed_time_hrs` to
   `prod.ed_visits`. State this in P1/P2 acceptance so the demoed marquee crit is not mistaken for
   live; carry `metadata.provenance='demo'` on every mocked MetricValue and surface it in the UI.
   Separately: the MTD materialized views refreshed `CONCURRENTLY` **require a unique index** on
   each MV or the refresh fails at runtime — add that to the P7 MV migrations.

8. **Pin the "fix-on-contact" canon violations to specific gates.**
   `border-healthcare-purple` (a **non-existent token**, in `CareJourneyCard`), `text-purple-*`
   (PDSA / BlockUtilization), and `bg-gray-900`+`text-white` white-on-white instances
   (`ProcessFlowDiagram.jsx:79`, `RoomRunningService`) are real existing violations the script
   cannot catch. Pin them to the **P5/P7 gates** with an **explicit light-mode visual pass** in
   acceptance (the dark-default dev view hides white-on-white). "On contact" risks them never
   being fixed if a phase de-scopes.

## II.2 Product decisions — RESOLVED (2026-06-28)

All seven decisions are answered below and are now **binding for execution**; Part VIII has been
tightened to reflect them.

| # | Decision | Resolution | Implementation consequence |
|---|---|---|---|
| **D1** | **Facility scope** | **Single-facility.** `cockpit_snapshots` is a single replaced row keyed by a `facility_key text` PK (default `'HOSP1'` from `HospitalManifest`), **not** a `facility_id` FK; **no `Facility` Eloquent model/table is created.** `RefreshCockpitSnapshot` iterates `HospitalManifest::facilities()` (a value-object list), never `Facility::active()`. | Resolves the contradiction; forward-compatible (a second manifest facility just adds a keyed row) without inventing a table. Lands P0/P1. |
| **D2** | **Drill deep-link** | **`?drill={domain}` query-param**, with `history.pushState` integration so the browser Back button *and* ESC close the DrillModal and the drill state is shareable/bookmarkable. | Keeps the one-page snapshot intact; the redirect target is trivial; the back-button downside is mitigated by history integration. Lands P2/P3. |
| **D3** | **`ok`→teal rationing** | **Rationed teal.** Teal renders *only* in confirmed-on-target slots: OKR cards meeting target, days-since-event counters, a met hand-hygiene/safety target. Any metric merely in-band is `normal`/grey. | Enforced centrally in `statusStyle().valuePrimary`. Lands P0/P2. |
| **D4** | **Redirect horizon** | **Permanent.** The five `/dashboard/*` overviews redirect to `?drill=` indefinitely; no sunset. | Five trivial redirect lines stay; bookmarks live forever. Lands P4a. |
| **D5** | **Mocked domains** | **Visible by default with a `metadata.provenance='demo'` badge + stale `source_freshness`**, *plus* a `COCKPIT_HIDE_DEMO_DOMAINS` config flag (default `false`) so a real (non-demo) deployment hides Quality/Service/Financial until live fact tables exist. | The Summit wall lights up for screenshots/acceptance; real sites are protected from trusting fabricated HAI/financial numbers. Lands P1, hardened P7. |
| **D6** | **Wall-mode theme** | **Hard dark-lock by default**, with a documented soft `?theme=` escape hatch for atypical bright-NOC deployments (not advertised in the UI). | Lands P8. |
| **D7** | **Status enum** | **Add a `CockpitState` type alias** mapping onto the existing `StatusLevel` enum (`critical/warning/success/info/neutral`). **No physical rename** of the ~117 ad-hoc assignments or the 20+ Zod schemas. | Additive, zero-blast-radius. Lands P0. |

## II.3 Post-plan drift (verified against the live tree, 2026-07-03)

The plan was written 2026-06-28. The Hummingbird mobile merge (~32k lines, 280 files) and the
Reverb/Eddy production enablement landed after it. All Part I–VIII citations were re-verified
on 2026-07-03 and still hold (routes, band methods, `DashboardContext` mount, CATALOG, canon
script scope; no P0 work had started). The following **supersede the corresponding passages**
wherever they conflict:

1. **R9 is resolved.** Prod runs `BROADCAST_CONNECTION=reverb`, proven end-to-end via the
   Hummingbird realtime badge over `wss://zephyrus.acumenus.net`. Every "silent-drop footgun"
   cutover item becomes a *verify* step, not a build step.
2. **Eddy is ENABLED on prod** (`EDDY_ENABLED=true`, 2026-06-28). P6 modifies a live, enabled
   agent surface — the "ships disabled" framing is stale; the flag remains the rollback lever.
3. **Mobile paging is conditionally LIVE.** `HummingbirdServiceProvider` binds a real
   `ApnsPushNotifier` when configured (falling back to `LogPushNotifier`); the approval
   doorbell is proven on device. P6 alert fan-out will actually page phones → **flap-damping
   is a hard prerequisite for enabling fan-out, not a P6 nice-to-have.**
4. **The Altitude model is externally ratified.** `docs/hummingbird/ADR-2026-07-01-altitude-
   patient-lens.md` accepts the four-altitude model as binding and adds **A2P** (patient/
   encounter lens) as the deepest *mobile* drill leaf under A2. A shipped native client now
   consumes the IA — altitude semantics changes have second-client blast radius.
5. **The mobile BFF is a fifth snapshot consumer.** `MobileAltitudeService` computes its own
   house rollup from `Barrier`/`BedRequest`/`EvsRequest`/`TransportRequest`/`StaffingRequest`/
   `Approval` (14 personas in `MobilePersonaCatalog`). Success metric **C9 widens**: one cached
   `cockpit_snapshots` row read by the Cockpit, Eddy, **and the mobile BFF** (repoint in/after
   P1). `MobilePersonaCatalog` hardcodes web deep links incl. `house_supervisor →
   /dashboard/rtdc` — P4a must repoint the catalog's `web` values in the same commit as the
   redirects, and the P2/P3-before-P4a sequencing (II.1#2) is now load-bearing for a shipped app.
6. **`CommandCenterDataService` gained a live-bed-board occupancy fallback** (empty census
   snapshots → direct `prod.units`/`prod.beds` query, "every surface reads the same
   occupancy"). The P1 `RtdcMetrics` decomposition must preserve this fallback.
7. **P6 must reconcile with the OperationalEvent pipeline.** `Ops\OperationalEvent` +
   `OperationalEventAcknowledgement`/`Entity`/`Target` models (over the rtdc operational-events
   table) now carry an API surface and an acknowledgement flow. P6 must either extend that
   pipeline or state the boundary explicitly (derived KPI-threshold alerts in `cockpit_alerts`
   vs. discrete operational events) — never two parallel alert stores.

**Gap pins** (Appendix-A items not folded in by II.1, now assigned): redirect-vs-original
rollback flag → **P4a**; `CommandCenterDrilldownService` synthetic→real drill tables → **P3
surfaces the `synthetic` flag, P7 owns the swap**; `ops.metric_values` retention/partition
policy → **P1** (decide before the per-minute writes start); approver-routing policy
(`resolveApprover` falls back to the proposing actor) → **P6, blocking for fan-out** given #3.

**P1 execution notes (2026-07-04).** The serving layer shipped as SnapshotBuilder v1
wrapping `CommandCenterDataService::build()` (the sanctioned transition seam — the
Metrics-class decomposition lands on top without a contract change): `cockpit_snapshots` +
`cockpit_alerts` (with `hold_count` from day one), `RefreshCockpitSnapshot` every minute,
`GET /api/cockpit/snapshot` (ETag/304), kpi-definitions GET/PUT (admin-gated, audited), and
the capacity.snapshot tool reading the shared `cockpit.snapshot` cache (Eddy = cockpit,
proven by test). **Retention decision (gap pin resolved):** `ops.metric_values` per-minute
writes have NOT started; they land with the Metrics decomposition, accompanied by a
**90-day retention prune** (aligned to TREND_DAYS) scheduled daily — the writer and the
pruner ship in the same commit, never separately. Still open in P1: Metrics decomposition,
DrillBuilder + Cell grammar, `/cockpit/stream` SSE, Appendix-A seed catalog + demo stubs
(D5 provenance badges), remaining 4 domains + 7-OKR expansion.

**P1 execution notes, second slice (2026-07-04).** The Metrics decomposition shipped:
`app/Domain/Cockpit/Metrics/*` (9 providers) + `SnapshotContext`, with SnapshotBuilder
assembling the §3.2 sections (facility / capacityStatus / census[8] / okrs[9] /
domains{8} with `provenance` + `gaugeKey` / derived alerts) ADDITIVELY on the frozen
legacy payload. Deliberate deltas from the chapter text, all verified live on the seeded
dev DB (5 simultaneous crit-first alerts, NEDOCS 142 demo-badged):
1. **Providers read the legacy payload first** (via SnapshotContext), running their own
   queries only for cockpit-only values — any number shared with `/dashboard` is the same
   computed value (this is how the bed-board occupancy fallback is preserved, Part II.3 #6),
   and per-minute query cost stays flat. Domain services are wrapped where the value
   doesn't already exist (StaffingOperationsService, Transport/Evs, PerioperativeMetrics,
   DemandForecast by_midnight for `okr.occupancy_midnight`).
2. **Alerts are template-gated:** only warn/crit values whose kpi_definition carries an
   `alert_template` enter the ticker (crit-first). Template presence is the Earned-Red
   ration — MTD ledger measures change color on the wall but never page. P6's AlertEngine
   inherits this gate.
3. **StatusEngine order corrected to crit→warn→ok→watch→normal** (spec §4): an explicitly
   earned ok_edge beats the watch band, otherwise the catalog's ok==warn seeds could never
   show rationed green. Watch still fires without ok_edge and in the gap below a higher one.
4. **The embedded Eddy capacity document moved to `capacitySnapshot`** — slice 1 had it on
   `capacity`, silently clobbering the legacy Zod contract's capacity band in the persisted
   payload. `AgentToolRegistry` reads the new key; nothing user-facing had consumed it yet.
5. **The writer + 90-day pruner shipped together** as decided: `MetricValueWriter` appends
   every snapshot's scalars to `ops.metric_values` (grain='snapshot', definition-linked,
   provenance-tagged, ~70 rows/min) and `PruneCockpitMetricValues` (dailyAt 03:40) deletes
   only that grain past `COCKPIT_METRIC_VALUES_RETENTION_DAYS` (90).
6. The **OKR scorecard is the 9 registry cards** (spec §2.1 rows), not 7 — the plan's
   "okrs[7]" undercounted the registry; owners + objectives ride on the definition rows.
Catalog: `CockpitKpiDefinitionSeeder` (standalone, prod-safe, updateOrCreate by key; also
step 11 of CommandCenterDemoSeeder) seeds all 71 Appendix-A keys; edges match legacy
/dashboard band constants wherever the same number renders in both places (e.g. readmit
warn 11/crit 13, D2P warn 20/crit 30). Still open in P1: DrillBuilder + Cell grammar over
CommandCenterDrilldownService, `/cockpit/stream` SSE fallback.

**P1 CLOSED (2026-07-04, third slice + prod deploy).** `DrillBuilder` +
`GET /api/cockpit/drill/{domain}` shipped for all 9 registry domains: KPI tiles from the
CACHED snapshot (single-snapshot discipline), detail tables from the live domain boards
(RTDC unit board / ED track board + acuity / OR room board / staffing coverage /
transport measures + EVS queue; mocked domains get a demo-tagged measure ledger), Cell
grammar in CANON tokens. One deliberate deviation: DrillBuilder v1 does NOT call
`CommandCenterDrilldownService` (that would re-run the whole dashboard build per drill);
each payload carries `drilldownHref` so P3's modal wires the deep timeline/opportunity
detail through the existing endpoint instead. `GET /api/cockpit/stream` (SSE) emits
PHI-free reload pings on snapshot advance, bounded cycles, EventSource reconnects.
**Prod state:** deployed 2026-07-04 (user-approved); both migrations applied via
`--path`, catalog seeded (108 definitions), snapshot row live (NEDOCS 142 crit alert,
68 history rows), endpoint auth-gated. **Prod has NO Laravel scheduler** (pre-existing —
flow:snapshot/ReconcileRtdcPredictions were never firing either); installing the
www-data `schedule:run` cron was permission-blocked and is the ONE remaining P1
operational step. P1 acceptance otherwise met.

---

# Part III — Product Cohesion & Information Architecture

**Decision:** Collapse the six parallel dashboards and the Analytics/Staffing/Transport silos into ONE product organized by a four-level Altitude model. The `/dashboard` Command Center becomes the single HOME (the house-wide Cockpit overview); every live domain (RTDC, ED, Periop, Flow, Staffing, Transport, Quality) becomes a drill-reachable Workspace; Analytics + Process-Improvement become the analytical Study altitude. Navigation stays governed by the existing `navigationConfig.ts` SSOT, restructured from 8 sibling domains into Cockpit / Workspaces / Study / Admin. The five redundant `/dashboard/*` overview routes are deprecated to redirects into Cockpit panel-drills; no URL is hard-deleted.

_Rationale:_ The fragmentation is not a data problem — the digest confirms the backend is production-grade and the SSOT (navigationConfig.ts), the snapshot proto (CommandCenterDataService::build), the persona switcher (RoleSwitcher + commandCenterStore: command/executive/service-line), and the drilldown API (/api/command-center/drilldown) already exist. The fragmentation is an information-architecture problem: six overview surfaces with no shared data shape, threshold resolver, or freshness signal, and a flat 8-domain nav that treats live ops and retrospective analytics as peers. The cockpit spec (COCKPIT_IMPLEMENTATION.md §0.3, §2, §3) already prescribes the cure: ONE single-screen overview, panel-and-OKR headers as drill entry points, one server-computed snapshot, one StatusEngine. Mapping that onto an explicit Altitude model (glance→workspace→drill→study) gives every audience a single front door and a deterministic path down, eliminating the "which dashboard do I open?" decision. Reusing navigationConfig.ts as the restructured SSOT means the change is a reshaping of an existing config plus route redirects — additive and non-destructive, honoring the protected auth flow and the production data.

## Product Cohesion & Information Architecture — Zephyrus 2.0

### 1. The core problem in one sentence

Zephyrus today is six overview dashboards (`/dashboard`, `/dashboard/rtdc`, `/dashboard/perioperative`, `/dashboard/emergency`, `/dashboard/improvement`, `/dashboard/transport`, plus an orphaned `/home`) and eight sibling navigation domains, each with its own data pipeline, threshold logic, layout shell, and visual treatment. There is no single front door and no deterministic way down. The fix is not more surfaces — it is **one home and one descent path**.

### 2. The Altitude model (the organizing spine of 2.0)

Every screen in 2.0 lives at exactly one of four altitudes. This is the mental model users learn once and apply everywhere.

| Altitude | Question it answers | Latency | Surface | Lives at |
|---|---|---|---|---|
| **A0 — Glance** | "Is the house OK right now?" (3-second read) | 1–5 min snapshot | The Cockpit overview, one screen, no scroll | `/dashboard` |
| **A1 — Workspace** | "Show me everything happening in this domain, now" | live (Reverb/poll) | A live domain operating surface (boards, queues, placement) | `/rtdc/*`, `/ed/*`, `/operations/*`, `/transport/*`, `/staffing`, `/flow` |
| **A2 — Drill** | "Why is this one tile red?" | snapshot + on-demand | A full-screen DrillModal over the Cockpit (KPI strip + detail tables) | `/dashboard?drill={domain}` |
| **A3 — Study** | "What is the pattern over weeks; what should we change?" | retrospective | Analytics deep-dives, process mining, PDSA, opportunity portfolio | `/analytics/*`, `/improvement/*` |

The descent is always **A0 → (A2 drill in place) → A1 workspace → A3 study**. A0 never scrolls; depth is always behind a drill or a workspace link, exactly as the cockpit spec mandates (`COCKPIT_IMPLEMENTATION.md` §0.2 "one screen, no scrolling for the overview"; §0.3 "every panel header and every OKR card is a drill-down entry point").

### 3. The single HOME — Cockpit overview at `/dashboard`

`/dashboard` (`Pages/Dashboard/CommandCenter.tsx`, served by `CommandCenterController`) is the **only** home. It is the ISA-101 house-wide situational-awareness surface: CommandBar → CensusStrip → AlertTicker → OKR Scorecard → 8-domain panel grid → legend/footer (spec §0.3). It is the highest-readiness asset in the codebase: a single server-computed snapshot via `CommandCenterDataService::build()`, a frozen Zod contract (`commandCenter.ts`), canon-compliant components (`Components/CommandCenter/*`, 13 files, zero raw-palette), an existing drilldown API (`GET /api/command-center/drilldown`), and the `RoleSwitcher`. **Evolve, do not replace.**

Every other overview dies into it. The five `/dashboard/{rtdc|perioperative|emergency|improvement|transport}` routes (confirmed at `routes/web.php:50–54`) are **deprecated to 301-style Inertia redirects** that land on `/dashboard?drill={domain}`, opening that domain's DrillModal (A2) over the cockpit. This preserves every existing bookmark while collapsing six homes into one. The orphaned `/home` route (`routes/web.php:37`, hardcoded mock, unreachable) is **deleted outright** — it is dead weight, not a sanctioned surface.

### 4. Audiences → the RoleSwitcher persona, not three home pages

The three audiences are **one home reshaped by persona**, never three URLs. The `RoleSwitcher` (`Components/CommandCenter/RoleSwitcher.tsx`) already encodes this with `command | executive | service-line` backed by `commandCenterStore`; `service-line` ships as "coming soon" today.

| Audience | Persona (RoleSwitcher) | A0 emphasis | Default descent |
|---|---|---|---|
| **Frontline unit staff** | `command` | Capacity + Flow + ED panels lead; UnitHeatStrip prominent; "my unit" filter | A2 drill → A1 workspace (bed placement, treatment board) |
| **House-wide ops leaders** | `command` (house scope) | Full 8-panel grid + AlertTicker + StrainIndex | A2 drill across domains; A1 for the hot domain |
| **Executives** | `executive` | OKR Scorecard leads; HeroWall swaps out; bands reordered Outcomes-first | A2 OKR drill → A3 study |

To make the persona persistent app chrome (not a `/dashboard`-only control), the `RoleSwitcher` moves into the unified app shell next to the TopNavbar, URL-synced via a `?role=` query param so a shared link reproduces the view. Persona changes **never** change the route — they re-rank bands and swap HeroWall↔OKR within the same `/dashboard` page (the behavior the digest confirms already exists). A future `service-line` persona scopes the snapshot to a chosen service line; the IA reserves the slot now.

### 5. Navigation model — ONE SSOT, four sections

`navigationConfig.ts` (verified: 8 `NavDomain` objects, `flattenNavigation()` feeds Cmd+K with href-dedup) **remains the single source of truth**. It is restructured from "8 flat sibling domains" into **four altitude-aligned sections**, so the nav itself teaches the Altitude model:

1. **COCKPIT** — the home link (`/dashboard`), no submenu. (Today's `TOP_LEVEL_DASHBOARD`.)
2. **WORKSPACES (A1, live ops)** — RTDC, Emergency, Perioperative, Transport, Staffing, Patient Flow. Each domain's `dashboardHref` (today pointing at a dead `/dashboard/*` overview) is **repointed to its primary live workspace** (e.g. RTDC → `/rtdc/bed-tracking`, Periop → `/operations/room-status`), because the overview now lives in the Cockpit. The Operations/Predictions sub-groups are preserved as the workspace's internal tabs.
3. **STUDY (A3, analytical)** — Analytics (Intelligence Hub, Live Signals, Retrospective, Predictive, Process Intelligence, Scenario Workbench, Data Quality, Opportunity Portfolio) **+** Process-Improvement (Bottlenecks, Process Analysis, Root Cause, PDSA, Library). These two of-a-kind retrospective domains merge under one STUDY heading. The five Surgical Deep Dives (`/analytics/block-utilization` etc.) are **de-duplicated** — today they appear verbatim under both Perioperative and Analytics; in 2.0 they live ONLY under Study, and the Perioperative workspace reaches them via a "deep dive →" affordance, not a duplicated nav leaf.
4. **ADMIN** — User Management (admin-only), unchanged.

The dead `DashboardContext.tsx` (verified: ~460-line `workflowNavigationConfig` duplicate with zero consumers beyond its own mount in `Providers.tsx`) is **deleted** — it is a second, conflicting nav inventory that the 2.0 SSOT makes redundant.

### 6. Live-ops vs analytics relationship

The split is **temporal, not topical**. The SAME domain (e.g. ED) appears at three altitudes: as a Cockpit panel (A0), as a live track-board workspace (A1, `/ed/operations/*`), and as a retrospective wait-time/flow study (A3, `/ed/analytics/*`). 2.0 makes the boundary explicit: **anything answering "now" is a Workspace; anything answering "over time / why / what-if" is a Study.** The per-domain analytics silos (`/rtdc/analytics/*`, `/ed/analytics/*`, `/transport/analytics`) are **re-homed under Study** as that domain's deep-dive set, reached both from the Study section and from a "view trends →" link inside the matching workspace. This eliminates the current confusion where `/analytics`, `/rtdc/analytics`, and `/ed/analytics` are three unrelated trees. Crucially, both altitudes must read the **same StatusEngine output** so a tile that is `crit` at A0 is `crit` in its A3 trend — the digest confirms `bandHighBad`/`bandLowBad` is duplicated across `CommandCenterDataService` and `OperationsAnalyticsService` today; that extraction is the connective tissue that keeps live and analytic altitudes telling one story.

### 7. URL / route consolidation summary

- **`/dashboard`** — the one home (Cockpit A0). Unchanged route, evolved page.
- **`/dashboard?drill={domain}`** — A2 DrillModal deep-link (new query convention; backend `/api/cockpit/drill/{domain}` per spec §3.3).
- **`/dashboard?role={command|executive|service-line}`** — persona deep-link (A0 reshape).
- **`/dashboard/{rtdc|perioperative|emergency|improvement|transport}`** — deprecated → redirect to the matching `?drill=` (preserve bookmarks).
- **`/home`** — deleted (dead mock).
- **Workspaces** keep their existing live URLs (`/rtdc/*`, `/ed/operations/*`, `/operations/*`, `/transport/*`, `/staffing`, `/flow`).
- **Study** keeps `/analytics/*` and `/improvement/*`; per-domain analytics re-homed under Study headings; surgical deep-dives de-duplicated to a single canonical home.

### 8. Section-by-section disposition table (every current section → its 2.0 fate)

| Current section / route | Today | 2.0 Altitude | Fate | Notes |
|---|---|---|---|---|
| `/dashboard` (CommandCenter) | Live house overview | **A0 Cockpit** | **KEEP as the one HOME — evolve** | Add CommandBar/CensusStrip/AlertTicker/DrillModal/5 missing panels |
| `/dashboard/rtdc` | RTDC overview | A2 | **DEPRECATE → redirect** to `?drill=rtdc` | Overview folds into Cockpit RTDC panel |
| `/dashboard/perioperative` | Periop overview | A2 | **DEPRECATE → redirect** to `?drill=periop` | |
| `/dashboard/emergency` | ED overview | A2 | **DEPRECATE → redirect** to `?drill=ed` | |
| `/dashboard/improvement` | PI overview | A2/A3 | **DEPRECATE → redirect** to `?drill=quality` or Study | Bottleneck KPIs go to Cockpit; deep view to Study |
| `/dashboard/transport` | Transport overview | A2 | **DEPRECATE → redirect** to `?drill=flow` | |
| `/home` | Hardcoded mock, orphaned | — | **DELETE** | Unreachable dead weight |
| RTDC › Operations (bed-tracking, placement, huddles, flow-4D, ancillary) | Live boards | **A1 Workspace** | **KEEP as RTDC Workspace** | Repoint domain `dashboardHref` to `/rtdc/bed-tracking` |
| RTDC › Analytics (utilization, performance, resources, trends) | Retrospective | **A3 Study** | **RE-HOME under Study** | Reachable from RTDC workspace "trends →" |
| RTDC › Predictions | Forecast | A3 Study | RE-HOME under Study (Forecast group) | |
| Transport › Operations (requests, dispatch, inpatient, transfers, discharge, EMS, transitions) | Live | **A1 Workspace** | **KEEP as Transport Workspace** | |
| Transport › Control (resources, analytics, integrations) | Mixed | A1 chrome + A3 | KEEP resources/integrations in workspace; analytics → Study | |
| Staffing › Staffing Office (`/staffing`) | Live, single page | **A1 Workspace** | **KEEP & EXPAND** | Becomes Cockpit Staffing panel's drill target |
| Perioperative › Operations (room-status, block-schedule, cases) | Live | **A1 Workspace** | **KEEP as Periop Workspace** | Repoint `dashboardHref` to `/operations/room-status` |
| Perioperative › Analytics (5 surgical) | Retrospective | A3 Study | **DE-DUP → Study only** | Remove from Periop nav; link from workspace |
| Perioperative › Predictions | Forecast | A3 Study | RE-HOME under Study | |
| Emergency › Operations (triage, treatment, resources) | Live | **A1 Workspace** | **KEEP as ED Workspace** | |
| Emergency › Analytics / Predictions | Retrospective/forecast | A3 Study | RE-HOME under Study | |
| Improvement › Diagnose (bottlenecks, process, root-cause) | Mostly mock + 1 live | **A3 Study** | **MERGE into Study (Process Mining)** | `getBottleneckStats()` (live) promoted to Cockpit Quality/Flow signals |
| Improvement › Improve (active, PDSA, library) | PDSA CRUD | A3 Study | **KEEP under Study (Improvement)** | PDSA↔Ops::Intervention bridge surfaced |
| Analytics › Control (hub, live signals, data quality) | Partly live | **A3 Study** | **KEEP as Study root** | Hub sections become modal drills, not pages |
| Analytics › Ops Console (Agent Inbox, Executive Brief) | Live | A3 / chrome | KEEP; Agent Inbox also reachable from Eddy approvals | |
| Analytics › Patterns/Forecast/Improve | Mixed | A3 Study | KEEP under Study | |
| Analytics › Surgical Deep Dives (5) | Live, duplicated | A3 Study | **CANONICAL HOME (de-dup from Periop)** | Kill mock bundles still shipped to prod |
| Admin › User Management | Admin | Admin | **KEEP unchanged** | adminOnly preserved |
| `DashboardContext.tsx` | Dead nav duplicate | — | **DELETE** | Zero consumers; conflicts with SSOT |
| Auth pages + ChangePasswordModal | Protected | — | **UNCHANGED (protected)** | Per `.claude/rules/auth-system.md` |

### 9. What this buys

One home, one descent path, one nav SSOT, one persona control, one snapshot, one StatusEngine feeding both live and analytic altitudes — with every existing URL either kept, repointed, or gracefully redirected. No data destroyed, no auth touched, no canon violated.

## Risks
- Redirecting the five /dashboard/* overview routes will surprise users with deep muscle memory; mitigate with a one-time 'this view now opens here' toast and a 60-day redirect window before considering removal.
- Re-homing per-domain analytics under Study changes URLs in nav even though the underlying routes persist; Cmd+K (flattenNavigation) must be updated in lockstep or stale palette entries point to moved labels.
- The Altitude model only coheres if the StatusEngine extraction lands first; if Cockpit (A0) and Study (A3) keep separate threshold logic (bandHighBad/bandLowBad duplicated today), a tile can be red at A0 and green at A3, destroying user trust in the unified product.
- Persona persistence as app chrome means RoleSwitcher must move out of the CommandCenter page into the shell; if commandCenterStore state is page-scoped, the persona will reset on workspace navigation unless hoisted.
- De-duplicating the 5 surgical deep dives risks breaking a bookmark that assumed the Perioperative-prefixed copy; both currently resolve to the same /analytics/* href, so the risk is nav-label-only, but verify no second route exists.
- Deleting DashboardContext.tsx and /home is safe per the zero-consumer finding, but a grep-confirmed sweep is required before deletion since background agents have re-added deleted files on this repo's siblings before.

## Open Questions
- Should the A2 DrillModal use a query-param deep-link (/dashboard?drill=ed) or a real nested route (/dashboard/ed)? Query-param keeps the single-page snapshot intact and matches the modal-over-cockpit model, but a nested route gives cleaner back-button semantics — which does Sanjay prefer?
- What is the redirect deprecation horizon for /dashboard/rtdc etc. — permanent redirect, or remove after a fixed window (e.g. 60 days)?
- Does the executive persona need its own scoped snapshot (OKR-only, lighter query battery) or does it reshape the same full snapshot client-side? This affects whether RefreshCockpitSnapshot caches one document or per-persona documents.
- Should Process-Improvement's live bottleneck signals (getBottleneckStats, the one live PI asset) surface as a Cockpit Quality-panel tile, a Flow-panel tile, or its own 9th 'Improvement' panel — and does adding a 9th panel break the spec's 'fits 1080p, no scroll' constraint?
- For the frontline 'my unit' scope, does the snapshot get a unit filter at A0, or is unit-scoping deferred to the A1 workspace (with A0 always house-wide)?
- Confirm there is genuinely only one route serving the 5 surgical deep dives (so de-duplicating the Perioperative nav leaves no orphaned second route) — needs a routes/web.php grep before the nav change lands.

---

# Part IV — Unified Design Language & Status Vocabulary

**Decision:** Adopt the cockpit's ISA-101 grey-baseline / color-as-exception / shape-plus-color philosophy in full, but render it entirely through Zephyrus's existing canon: Figtree + tabular-nums (never IBM Plex/mono), the four healthcare-* status tokens plus a neutral grey baseline (never OKLCH or a fifth color), and blue (healthcare-info / healthcare-primary) instead of cyan. The five logical cockpit states collapse onto a 5-row vocabulary backed by 4 colors + grey: normal→neutral-grey, ok→teal (success, rationed to confirmed-on-target only), watch→sky (info), warn→amber, crit→coral. The cockpit is BOTH the new house-wide HOME at /dashboard AND a chromeless kiosk/wall MODE of that same surface (?display=wall), never a separate build.

_Rationale:_ The tension is smaller than it looks: the reference spec's HMI philosophy (grey rest state, color as exception, redundant shape encoding) is token-independent and actually STRENGTHENS the canon's existing Earned-Red and Status-Never-Alone rules — it is additive discipline, not a conflict. The only genuine clashes are surface-level token choices (IBM Plex vs Figtree, OKLCH vs healthcare-*, cyan vs blue, 5 colors vs 4), and every one resolves cleanly by translating to the nearest canon token. The codebase already proves this works: STATUS_VAR in Components/CommandCenter/status.ts already bridges a 5-level StatusLevel enum (critical/warning/success/info/neutral) onto the four CSS-vars + --text-muted, and KpiTile/UnitHeatStrip/OkrScoreboard already consume it with tabular-nums Figtree. The single piece of conceptual work is renaming the 5 LOGICAL states to the ISA-101 vocabulary (normal/ok/watch/warn/crit) and adding the shape glyph + ISA-101 value-color discipline at the render layer — both purely additive. I keep "ok" as teal rather than introducing a confirming green because (a) introducing green would violate the four-color vocabulary and (b) teal already reads as "healthy/on-target" in the canon; the green-vs-teal distinction in the reference is cosmetic, and the SHAPE (dot vs dash) already carries the ok-vs-normal distinction the reference uses color for. The watch-vs-interactive blue collision is resolved by reserving sky (--info, #60A5FA) for the watch STATUS and the deeper interactive blue (healthcare-primary, #2563EB/#3B82F6) for clickable affordances — they are already two distinct tokens in the canon, and the watch dot never appears as a clickable control, so context disambiguates.

## Unified Design Language — The Zephyrus 2.0 Cockpit

### 0. Governing principle

The cockpit reference and the Zephyrus canon want the *same thing* and say it in two dialects. The reference's ISA-101 creed — **grey is the baseline, saturated color is an exception signal, status is encoded by shape AND color** — is the literal, stricter expression of the canon's own **Earned-Red** and **Status-Never-Alone** rules (DESIGN.md §2 "Named Rules"). We therefore adopt the reference's *philosophy in full* and its *tokens not at all*. Every reference token (IBM Plex, OKLCH, cyan, a fifth green) translates to its nearest canon equivalent. The result is additive: the cockpit makes the canon stricter, never weaker, and passes `scripts/check-ui-canon.sh` and the impeccable hook unchanged.

### 1. The status vocabulary — 5 logical states, 4 colors + 1 grey

The reference `meta(t)` engine (in `Hospital Operations Cockpit.dc.html`) defines five logical states. Zephyrus already ships a 5-member `StatusLevel` (`types/commandCenter.ts:4`: `critical/warning/success/info/neutral`) bridged to CSS-vars in `Components/CommandCenter/status.ts`. The 2.0 work is to *rename the logical states to the ISA-101 vocabulary* and bind each to an existing token. **No new color, no new CSS-var.**

| Logical state (ISA-101) | Meaning | Canon color token | Hex (dark) | CSS-var | Shape glyph | Value-text color |
|---|---|---|---|---|---|---|
| **normal** | At rest, in-band, nothing to see | `neutral` → `--text-muted` | `#8A857D` | `var(--text-muted)` | `–` (en-dash) | `healthcare-text-primary` (near-white) |
| **ok** | Confirmed on-target (rationed) | `success` (teal) | `#2DD4BF` | `var(--success)` | `●` (filled dot) | teal — confirmation aids the viewer |
| **watch** | Eyes on it, trending | `info` (sky) | `#60A5FA` | `var(--info)` | `●` (filled dot) | `healthcare-text-primary` (near-white) |
| **warn** | Off target, approaching breach | `warning` (amber) | `#E5A84B` | `var(--warning)` | `▲` (up-triangle) | amber |
| **crit** | True breach, actionable | `critical` (coral-red) | `#E85A6B` | `var(--critical)` | `◆` (diamond) | coral |

Three decisions are load-bearing:

**(a) "ok" folds into teal, not a new green.** The reference uses `oklch(0.74 0.13 162)` (green) for ok and reserves teal for nothing. Zephyrus has no green token and adding one breaks the four-color vocabulary and the Two-System discipline. Teal *is* the canon's "healthy / optimal flow" hue (DESIGN.md §2 Tertiary). The reference's green-vs-teal split is cosmetic; the **shape** already carries the real distinction (normal `–` vs ok `●`). So ok = teal, and ok is **rationed** — it appears only where confirming on-target genuinely helps (OKR scorecards, days-since-event counters, a met hand-hygiene target). Everywhere else, a metric that is simply in-band is **normal/grey**, never teal. This preserves Earned-Color: teal means "we hit it," not "this exists."

**(b) "watch" reuses sky (--info) and re-points its semantic.** The canon currently labels `--info` as "completed / neutral information." The cockpit re-points it to **"watch this — trending, not yet a breach,"** which the discovery digest flagged as low-risk. Sky is the right register: it draws the eye without the alarm weight of amber.

**(c) watch-blue vs interactive-blue never collide.** This is the one real ambiguity and it resolves by token, not by context-prayer. Two *different* blues already exist in the canon: **watch = sky `#60A5FA` (`--info`)** for status, and **interactive = `healthcare-primary` `#2563EB`/`#3B82F6`** for clickable affordances (DESIGN.md §2 Primary). The watch dot is a 7-9px status mark inside a tile body; an interactive blue is a button fill, a link, or an active nav tint. They share a family but differ in lightness, saturation, and *position in the layout grammar* — a status dot is never a control, a control is never a 7px dot. Rule for builders: **status sky is for the StatusChip/dot/bar only; interactive blue is for buttons/links/active states only.** Never put a watch-sky fill on a clickable element.

### 2. Typography — Figtree + tabular-nums, full stop

Reject IBM Plex Sans/Mono entirely (the mono face is deleted from the build; weight 700 is not loaded). The reference reaches for Plex Mono purely to get **non-reflowing digits on a ticking wall display** — Figtree with `font-variant-numeric: tabular-nums` delivers exactly that, which is why the canon's Tabular Rule (DESIGN.md §3) exists. All weights stay **400/500/600**; the reference's `700` headers downgrade to `font-semibold` (600).

Glance-distance / wall scale (Tailwind scale only — no `text-[Npx]`):

| Role | Class | px | Use |
|---|---|---|---|
| Hero / census-chip value | `text-3xl`/`text-4xl` `font-semibold tabular-nums` | 30-36 | the number read across the room |
| Panel KPI value | `text-2xl tabular-nums` | 24 | tile values |
| Section / panel title | `text-base font-semibold` | 16 | panel headers, drill entry points |
| Body / table cell | `text-sm tabular-nums` | 14 | rows, sub-values |
| Functional label | `text-xs font-semibold uppercase tracking-wide` | 12 | tile/column labels only |

**Wall-display exception:** the reference's 9px panel micro-labels fall below the canon `text-xs` (12px) floor. The 2.0 plan declares a **single sanctioned exception**: dense panel micro-captions on the wall MODE may use `text-[11px]` *only inside cockpit primitives* (CensusChip sub, panel timestamp), documented in CLAUDE.md alongside the existing Auth/Design exceptions so the canon check is annotated rather than silently broken. Default (desk) mode stays at the `text-xs` floor.

### 3. The grey baseline — making the screen read near-monochrome

This is the heart of ISA-101 and it requires NO new tokens, only **render discipline**:

- **Background:** keep the canon solid `#0F172A → #1E293B` tonal step (reject the reference's `radial-gradient` cool-black; the tonal layering already delivers instrument depth — DESIGN.md §4).
- **Resting text:** `normal` and `watch` values render in `healthcare-text-primary` (near-white). Color appears on the value ONLY for `warn`/`crit` (and `ok` in its rationed confirmation slots). This is the *new, stricter* rule the cockpit adds to KpiTile — it is additive, the canon never restricted value-text color before.
- **Meter/bar tracks:** the empty portion of every MeterBar/gauge uses a neutral track (`--border` / `#334155`), filling with status color only as the value crosses thresholds — so a calm screen shows grey bars on a slate field, and a surging one lights up exactly where attention belongs.
- **Domain swatches & chrome:** the reference's cyan `oklch(0.72 0.13 200)` 7px domain swatch maps to **sky `--info`** (a neutral domain-identity mark, not a status). Panel borders stay `healthcare-border`.

The net effect: at rest the cockpit is slate, grey, and near-white with sky domain marks — visually near-monochrome — and color is *information*, exactly as the reference intends.

### 4. Accent — blue, not cyan

Every cyan in the reference (`oklch(0.72 0.13 200)`: the logo glow, domain swatches, the LIVE badge accent) maps to **sky `healthcare-info`** for non-interactive marks and **`healthcare-primary` blue** for the one interactive accent. Crimson `#9B1B30` + gold `#C9A227` remain brand/heritage + focus ONLY (Two-System Rule): gold is the `:focus-visible` ring on every cockpit control including the wall MODE; crimson appears only in the wordmark/CommandBar identity, never as a panel or status color. The reference's pulsing-red `blip` keyframe on the AlertTicker is the one sanctioned animation, gated behind `prefers-reduced-motion` (crossfade fallback).

### 5. Home AND wall MODE — one surface, two presentations

The cockpit is **both**, never two builds:

- **House-wide HOME** at `/dashboard` — the existing `CommandCenter.tsx` evolves in place (it is already ~60% aligned per discovery). Full interactivity: drill-modals, RoleSwitcher in persistent chrome, Eddy hand-off from warn/crit tiles, Cmd+K.
- **Kiosk / wall MODE** = the *same* page at `/dashboard?display=wall`. It drops interactive chrome (no nav mega-menu, no user menu, no drill affordances — `onDrill` becomes a no-op), enlarges to the wall scale tier, runs the 1Hz clock + AlertTicker, and **locks dark theme** (a bright wall in a dark unit is hostile). One snapshot, one StatusEngine, one component tree; mode is a presentation flag read by the layout shell.

**Dual-theme implications:** desk mode honors the dual-theme toggle (dark default, light mirror per DESIGN.md). Wall mode forces dark. Light-mode status hues already darken for white-bg contrast in the canon (`--success` `#059669`, `--critical` `#DC2626`, etc.) so the same StatusEngine output renders WCAG-AA in both themes without branching — the engine emits a *logical state*, the theme layer picks the hue.

### 6. The shape glyph set, reconciled with Status-Never-Alone

The canon's redundancy rule uses trajectory arrows `▲ ▼ ▬` (DESIGN.md §2 Status-Never-Alone). The cockpit's ISA-101 *state* glyphs `– ● ▲ ◆` answer a **different question** and coexist:

- **State glyph** (in the new `StatusChip`): `–` normal / `●` ok / `●` watch / `▲` warn / `◆` crit — answers *"what is the condition?"*. Color + shape together, so meaning survives grayscale and color-blindness (satisfying WCAG 2.2 AA non-color-reliance, 1.4.1).
- **Trajectory arrow** (existing, on sparklines/KPI deltas): `▲ ▼ ▬` — answers *"which way is it moving?"*. Unchanged.

Note the deliberate overlap: warn's state glyph is `▲` and an up-trajectory arrow is also `▲`. They never share a slot — the state glyph lives in the StatusChip at the tile/row leading edge; the trajectory arrow lives beside the delta value. ok and watch share the `●` dot but differ by color (teal vs sky) AND by the value-text discipline (ok values are teal, watch values stay near-white), so two redundant channels separate them. The expand affordance uses the Unicode `⤢` glyph (no icon library) per the reference, consistent with the "glyphs for status primitives, @iconify for domain chrome" resolution in the design-system digest.

### 7. Implementation seam (no canon files change)

Extend `StatusLevel` semantics in `types/commandCenter.ts` to the 5 logical names (or add a parallel `CockpitState` alias mapping to the existing enum), add a `StatusChip` primitive emitting `{color: STATUS_VAR[state], glyph}` from a single `cockpitMeta(state)` helper that mirrors the reference `meta(t)` but reads CSS-vars, and apply the ISA-101 value-color rule inside `KpiTile.tsx`. Every color still flows through `STATUS_VAR` → `--critical/--warning/--success/--info/--text-muted`. Zero raw Tailwind palette, zero OKLCH, zero IBM Plex, zero new tokens. The canon guard and impeccable hook stay green.

## Risks
- Teal overuse risk: if 'ok' is not strictly rationed to confirmed-on-target contexts, the screen drifts back toward an always-colored alarm-fatigue dashboard, violating Earned-Color. Mitigation: StatusEngine must default in-band metrics to 'normal' (grey), and a code-review rule must flag teal value-text outside OKR/days-since/target-met contexts.
- Watch-sky vs interactive-blue confusion on small wall displays viewed at distance, where the lightness gap between #60A5FA and #3B82F6 may be hard to read. Mitigation: status sky only ever appears as a 7-9px dot/bar, never as a fill the eye could mistake for a button; document the no-overlap rule in CLAUDE.md.
- The text-[11px] wall-mode exception could be cargo-culted into desk pages, re-opening the text-[Npx] ban. Mitigation: scope the exception to named cockpit primitives + wall mode only, annotate scripts/check-ui-canon.sh allowlist, and add it to the CLAUDE.md sanctioned-exceptions list.
- Re-pointing --info from 'completed' to 'watch' could mis-color legacy surfaces (Transport en-route, PDSA, status-completed aliases) that still read --info as 'done'. Mitigation: audit the ~20 --status-completed/info consumers; the semantic shift is additive at the cockpit layer but legacy pages must keep their existing meaning until migrated.
- WCAG 2.2 AA contrast for amber #E5A84B and teal #2DD4BF as VALUE text on the dark #0F172A field is borderline for small sizes; status-as-fill behind near-white text is safer. Mitigation: verify each status hue meets 4.5:1 as text at the value sizes in both themes, and prefer status-as-stripe/dot + near-white value where contrast is marginal.
- RoleSwitcher and dual-theme toggle behavior in wall MODE: forcing dark theme could surprise a site that runs a bright NOC. Mitigation: wall mode locks dark by default but the lock should be overridable via an explicit ?theme= param for atypical deployments.

## Open Questions
- Should the 5 logical state names physically replace the StatusLevel enum members (critical/warning/success/info/neutral → crit/warn/watch/ok/normal), or should a CockpitState alias map onto the existing enum to avoid touching the ~117 ad-hoc status assignments and the Zod schemas across 20+ services?
- Does wall MODE need a per-deployment config (which domains/panels to show, rotation interval) or is a single fixed house-wide layout sufficient for v2.0?
- Is the rationed 'ok→teal' confirmation acceptable on the executive OKR scorecard, or does Sanjay want OKR cards to also stay grey-at-rest and only color on miss (warn/crit), reserving teal for nothing but explicit target-met celebration?
- For the ~20 legacy --status-completed/info consumers, do we migrate them to a new --completed alias now, or leave them and accept that --info means 'watch' in the cockpit but 'completed' elsewhere until a later sweep?
- Should the wall-mode dark-theme lock be hard (no override) or soft (?theme= override) for atypical bright-NOC deployments?

---

# Part V — Frontend Component System & Drill Architecture

**Decision:** Build a single shared cockpit primitive library (cockpit/) layered on the existing Surface primitive and STATUS_VAR token bridge — extracting Sparkline and MeterBar from KpiTile, generalizing Gauge into RadialGauge, and adding StatusChip, CensusChip, Tile, Panel (clickable drill header), DataTable (typed Cell grammar), and DrillModal — driven by a single statusStyle() helper that mirrors the server StatusEngine. Reconcile the cockpit's 5-state HMI vocabulary onto the canon's 4-state palette via a shape-first encoding (dash/dot/triangle/diamond), keeping all rendering on Figtree + tabular-nums + healthcare-* tokens. Refactor per-section components (UnitHeatStrip, OkrScoreboard, the periop room board, ORUtilization views) onto these primitives; DrillModal replaces the 9 reference modals and the existing per-section DrillDownModal using the already-fixed solid .modal-surface scrim.

_Rationale:_ The CommandCenter component layer is already token-canonical and ~60% of the cockpit's primitive surface area exists in production-quality form (Gauge, KpiTile with inline Sparkline+MeterBar, UnitHeatStrip, OkrScoreboard, the STATUS_VAR bridge, the StatusLevel 5-member enum, a Zod contract with 15 types). Building net-new would discard proven, canon-clean code and re-introduce the fragmentation the 2.0 effort is trying to kill. The correct move is extraction + generalization: lift the inline Sparkline/MeterBar out of KpiTile so every section shares one implementation, generalize Gauge with a scale prop and multi-band arc for NEDOCS, and add the four genuinely-missing primitives (StatusChip, CensusChip, Panel-as-drill-header, DataTable Cell grammar, DrillModal). The central tension (IBM Plex/OKLCH/5-state-with-green vs Figtree/tabular-nums/4-state) resolves cleanly at the rendering layer: SHAPE carries the fifth state (normal=dash, ok=dot, watch=dot, warn=triangle, crit=diamond) so the canon's four colors plus neutral-grey suffice with zero token violations, and the ISA-101 grey-baseline rule (value text near-white except warn/crit) is additive — it makes the canon stricter, not weaker. The transparent-modal bug was just fixed by defining .modal-backdrop/.modal-surface globally; DrillModal must consume exactly that scrim and the established Radix dialog shadow-lg pattern rather than re-inventing overlays.

## Frontend Component System & Drill Architecture (Zephyrus 2.0)

### 1. Principle: extract and generalize, do not rebuild

The cockpit primitive set already exists ~60% in `resources/js/Components/CommandCenter/`, all of it token-canonical. The 2.0 library is a promotion of that code to a shared, documented `resources/js/Components/cockpit/` namespace, plus four genuinely-missing primitives. Every primitive resolves to the ONE surface primitive `Components/ui/Surface.tsx` (verified: `Panel.tsx` is `export { Surface as Panel }`) and the ONE color bridge `STATUS_VAR` in `CommandCenter/status.ts`. No primitive names a font family, uses `font-bold`/700, `text-[Npx]`, `font-mono`, or a raw Tailwind palette class. SVG fills/strokes take `STATUS_VAR[...]` values (the documented data-viz exemption), never hex.

### 2. The status contract (the reconciliation)

Keep the existing 5-member `StatusLevel = 'critical'|'warning'|'success'|'info'|'neutral'` (`types/commandCenter.ts:4`). The cockpit's 5-state HMI vocabulary maps onto it with SHAPE carrying the distinction the canon's 4 colors cannot:

| Cockpit tier | StatusLevel | Color (STATUS_VAR) | Glyph | Value-text rule (ISA-101) |
|---|---|---|---|---|
| normal | `neutral` | `var(--text-muted)` grey | `–` (dash) | near-white (`text-primary`) |
| ok | `success` | teal | `●` (dot) | teal — only for confirmed-on-target |
| watch | `info` | sky | `●` (dot) | near-white |
| warn | `warning` | amber | `▲` (triangle) | amber |
| crit | `critical` | coral | `◆` (diamond) | coral |

Add one shared helper, `cockpit/statusStyle.ts`, the client mirror of the server `StatusEngine`:

```ts
export interface StatusStyle { color: string; glyph: '–'|'●'|'▲'|'◆'; label: string; valuePrimary: boolean; }
export function statusStyle(level: StatusLevel): StatusStyle
```

`valuePrimary` encodes the ISA-101 grey-baseline rule (normal/watch render value in `text-primary`; ok/warn/crit take status color). This is the SINGLE place glyph + color + value-color policy lives; every primitive reads it. This is additive to the canon (stricter, not weaker) and resolves the central tension at the render layer with zero token changes. Reject IBM Plex outright — `tabular-nums` on Figtree achieves digit alignment; reject OKLCH — `STATUS_VAR` already resolves to the unified teal/amber/coral/sky CSS vars.

### 3. Primitive catalog (props + contract)

All props typed; metrics carry `tabular-nums`. Shared Zod additions live in `types/cockpit.ts` and extend the existing `commandCenter.ts` schemas.

**RadialGauge** — generalize the existing `CommandCenter/Gauge.tsx` (clockwise SVG donut, target tick, canon-clean). Add `scale` (replaces `max`, default 100) and an optional `bands?: {edge:number; level:StatusLevel}[]` array to render the multi-segment NEDOCS arc (0–200, 5-band) the ED panel needs. Props: `{ value:number; scale?:number; status:StatusLevel; bands?:Band[]; target?:number|null; size?:number; big?:string; small?:string }`. `color` is derived from `status` via `statusStyle`, removing the current free-hex `color` prop (a tightening).

**Sparkline** — EXTRACT the inline `Sparkline` from `KpiTile.tsx:12-59` into `cockpit/Sparkline.tsx`. Props: `{ data:number[]; status:StatusLevel; target?:number|null; id:string; w?:number; h?:number }`. Same area-gradient + terminal-dot rendering; `aria-hidden` (decorative). One implementation kills the `Math.random()` sparklines in `Dashboard/DrillDownModal.jsx` and the periop `DrillDownModal`.

**MeterBar** — EXTRACT the bar pattern repeated in `UnitHeatStrip.tsx:60-63`, `OkrScoreboard.tsx:25-28`, and `KpiTile` detail segments into `cockpit/MeterBar.tsx`. Props: `{ pct:number; status:StatusLevel; label?:string; track?:boolean; h?:number }`. Track = `bg-healthcare-border dark:bg-healthcare-border-dark`; fill = `statusStyle(status).color`; `0.6s` ease width transition gated by `prefers-reduced-motion`.

**StatusChip** — NEW (`cockpit/StatusChip.tsx`). The shape+color+label primitive. Props: `{ status:StatusLevel; label?:string; size?:'sm'|'md' }`. Renders `<span role="img" aria-label={statusStyle(status).label}>` with the glyph in `statusStyle().color` plus optional text label. Replaces the ad-hoc Eddy chip and the color-only `StatusPill` in `Analytics.jsx`. This is the load-bearing accessibility primitive — status is NEVER color-alone because the glyph differs per tier.

**Tile** — promote `KpiTile.tsx` to `cockpit/Tile.tsx`, unchanged in behavior (it is the most complete primitive in the codebase: label, value, unit, sub/caption, gauge for %-metrics, Sparkline, detail bar, trust badge, drill `Link`). Refactor it to consume the extracted Sparkline/MeterBar and `statusStyle` instead of inlining. Apply `valuePrimary` from `statusStyle` to the value `<span>` (currently always status-colored at `KpiTile.tsx:179`).

**CensusChip** — NEW (`cockpit/CensusChip.tsx`). Larger-value census tile for the 8-chip strip. Props: `{ label:string; value:string|number; sub?:string; status:StatusLevel }`. 26px-equivalent value via `text-2xl tabular-nums`, gradient-free Surface background, glyph mark top-right. UnitHeatStrip cards are a CensusChip variant.

**Panel** — NEW drill-entry wrapper (`cockpit/Panel.tsx`) distinct from the bare Surface re-export. Props: `{ title:string; accent?:StatusLevel; timestamp?:string; onDrill?:()=>void; children:ReactNode }`. The header is the drill affordance: when `onDrill` is set the title row becomes a `<button>` (focusable, gold `:focus-visible`, `aria-haspopup="dialog"`) with a `⤢` expand glyph; the existing `Band.tsx` "See all →" `Link` pattern is preserved for URL-nav cases but cockpit panels use `onDrill` to open DrillModal in place. Body delegates to Surface.

**DataTable** — NEW (`cockpit/DataTable.tsx`) built over the bare `ui/table.jsx`, which today is an unstyled wrapper. Implements the reference Cell grammar as a typed union:

```ts
type Cell =
  | string | number                                         // plain
  | { v:string|number; strong?:boolean; dim?:boolean; status?:StatusLevel } // styled text
  | { bar:{ pct:number; status:StatusLevel; label?:string } } // inline MeterBar
  | { chip:StatusLevel }                                      // StatusChip
  | { tag:{ text:string; status:StatusLevel } };             // bordered pill (ESI, priority)
interface Column { key:string; header:string; align?:'left'|'right'; note?:string }
interface DataTableProps { columns:Column[]; rows:Record<string,Cell>[]; caption:string }
```

Renders semantic `<table>` with `<caption className="sr-only">`, `scope="col"` headers, `tabular-nums` numeric columns. `bar` cells render MeterBar; `chip` cells render StatusChip; `tag` cells render a bordered pill in `statusStyle().color`. This is the single table grammar for every drill detail section.

**DrillModal** — NEW (`cockpit/DrillModal.tsx`), the unified full-screen drill. Built on the JUST-FIXED scrim (`.modal-backdrop` solid `rgb(15 23 42/.62)` light / `.80` dark, `app.css:197-214`) and the `.modal-surface` solid panel (`shadow-lg`). Use the headless-ui `Common/Modal.jsx` pattern (already correct) OR the Radix `ui/dialog.jsx` (solid `bg-[rgb(15_23_42/0.62)]` overlay, `shadow-lg`, built-in focus trap + ESC). Recommend Radix `dialog.jsx` as the base — it ships ESC, backdrop-click, focus-trap, and `aria-modal` for free. Structure per the reference 9-modal pattern: header = `9px×30px` accent bar in `statusStyle(accent).color` + title (`text-base font-semibold`) + sub + `timestamp` + close `<button aria-label="Close">`; body = 6-tile KPI strip (`cockpit/Tile` grid) + 1–3 `DataTable` sections each with a divider/note header. Props: `{ open:boolean; onClose:()=>void; domain:DrillDomain; accent:StatusLevel }`; data comes from the existing `GET /api/command-center/drilldown` (and the planned `/api/cockpit/drill/{domain}`) via TanStack Query, parsed with `parseCommandCenterDrilldown` (already exists, `commandCenter.ts:338`). MUST be solid (no `backdrop-blur`), `shadow-lg`, closeable by ESC, backdrop click, and button.

### 4. Refactor map — what gets absorbed

- **`UnitHeatStrip.tsx`** → re-expressed as a grid of `CensusChip` (its per-unit card already carries glyph + color + label + mini-bar; the `STATUS_META` icon/label map folds into `statusStyle`). Becomes the RTDC panel's house-census body and a drill table source.
- **`OkrScoreboard.tsx`** → key-result rows become `MeterBar`; the card becomes `cockpit/Panel` with `onDrill` so each OKR card is a drill entry (the cockpit requirement that "every OKR card is a drill-down entry point").
- **`KpiTile.tsx`** → becomes `cockpit/Tile`, consuming extracted Sparkline/MeterBar + `statusStyle`.
- **`Band.tsx`** → unchanged structurally; its `TileGrid` now renders `cockpit/Tile`. Band header keeps the URL `Link` (navigational) while domain panels use `Panel.onDrill` (in-place modal).
- **Periop** room board (`RoomStatusService`-fed 18-suite grid), **ORUtilization views**, RTDC `BedTrackingService` census, ED `TreatmentService`/`TriageService` board rows → all re-rendered through `DataTable` + `CensusChip` + `StatusChip`; their statusTone fields (`healthcare-critical/warning/success/info`, already cockpit-aligned) map 1:1 to `StatusLevel`.
- **Modals to retire/replace:** `Dashboard/DrillDownModal.jsx` and the periop DrillDownModal (Math.random sparklines) → DrillModal. Glassmorphism violations to fix on contact: `Components/Modal.jsx:45` (`bg-gray-500/75`), `RTDC/StatusUpdateModal.jsx:154`, `RTDC/TrendsModal.jsx:126` (`backdrop-blur-sm bg-black/30`) → swap to `.modal-backdrop`/`.modal-surface`. (Auth's `ChangePasswordModal.jsx` is protected and exempt — do not touch.)

### 5. Accessibility

- StatusChip is `role="img"` with `aria-label` = `statusStyle().label` ("Critical"/"Warning"/"On target"/"Watch"/"Normal"); glyph differs per tier so screen-reader and color-blind users both get the state.
- DataTable: `<caption className="sr-only">`, `scope="col"`, numeric `tabular-nums`; `bar`/`chip`/`tag` cells expose `aria-label` with the numeric/state value.
- Gauges/Sparklines are `aria-hidden` (decorative); the labeled value beside them is the accessible name (pattern already in `Gauge.tsx:67`).
- DrillModal: Radix `aria-modal` + focus-trap + ESC + restore-focus-to-trigger; the drill-entry Panel button is `aria-haspopup="dialog"`.
- All transitions (MeterBar width, Sparkline, gauge `stroke-dashoffset`, modal enter/leave) gated by `@media (prefers-reduced-motion: reduce)` — extend the existing block at `app.css:165`.
- Gold `:focus-visible` on every interactive element (Panel drill button, Tile `Link`, modal close), reusing `css/components/focus.css`.

### 6. Kiosk / zoom hardening

- Add a root font-size multiplier on `<html>` driven by a `data-density` attribute (`compact|normal|wall`) so a wall display can scale every `rem`-based size at once without touching components. Persist via the existing preference round-trip.
- A `StaleDataBanner` (extract the existing aging/stale detection in `CommandCenterView`) rendered app-chrome-level: when `generatedAtIso` ages past threshold, show a solid amber `healthcare-warning` banner ("Data X min old — verify before acting"), never a silent stale screen. This is the safety-floor pattern already proven by `safeParseCommandCenterData` (degrade to defensible "data unavailable", never white-screen).
- `tabular-nums` everywhere keeps digit columns from reflowing at any zoom level — the reason IBM Plex Mono is rejected.

### 7. Sequencing

1. `cockpit/statusStyle.ts` (mirror StatusEngine) + extend `StatusLevel` doc.
2. Extract Sparkline + MeterBar from KpiTile.
3. Generalize Gauge → RadialGauge (`scale` + `bands`).
4. Add StatusChip, CensusChip, Panel (drill header), DataTable.
5. Build DrillModal on Radix `dialog.jsx` + `.modal-surface`; wire to `/api/command-center/drilldown`.
6. Refactor UnitHeatStrip/OkrScoreboard/KpiTile/Band onto the library.
7. Migrate periop/RTDC/ED/Analytics section components onto DataTable + chips; retire the two DrillDownModals; fix the 3 glassmorphism modals.
8. Add density multiplier + StaleDataBanner to app chrome.

Verify with both `npx tsc --noEmit` AND `npx vite build` (vite is stricter) and `scripts/check-ui-canon.sh` before any commit.

## Risks
- Extracting Sparkline/MeterBar from KpiTile is a wide refactor surface — any consumer relying on the inline implementation must be migrated in the same change or the build breaks; mitigate by keeping identical rendering and props.
- Generalizing Gauge to remove the free-hex `color` prop in favor of `status` could break the StrainIndex/CommandCenter callers that currently pass a raw STATUS_VAR string; audit all Gauge call sites before changing the prop shape.
- The Radix vs headless-ui modal choice splits the codebase if not standardized — both correct patterns exist (dialog.jsx, Common/Modal.jsx); picking Radix for DrillModal but leaving Common/Modal for other dialogs perpetuates two systems.
- The 5→4 state mapping leans entirely on glyph differentiation; if a downstream component renders status by color only (e.g. Analytics StatusPill), the 'watch' (sky) and 'ok' (teal) states can read ambiguously without the shape — every status surface MUST route through StatusChip/statusStyle.
- Root font-size multiplier can interact badly with components using fixed px sizes via inline style (Gauge `size`, Sparkline `w/h` are px props); wall-display scaling should multiply those props too, or they will not grow with the rem scale.
- tabular-nums is necessary but not sufficient for perfect column alignment if any metric mixes proportional symbols; verify ⓘ/▲/◆ glyphs do not break digit columns in DataTable.
- DrillModal pulling from /api/command-center/drilldown today returns 22/25 synthetic sparklines; until the StatusEngine/snapshot backend lands, the modal will display synthetic trend data — must surface the existing `synthetic` flag (commandCenter.ts:283,310) as a visible 'demo data' note.

## Open Questions
- Standardize on Radix dialog.jsx OR headless-ui Common/Modal.jsx as THE single modal base for the whole app, or accept both (DrillModal=Radix, simpler dialogs=headless)? Recommend consolidating on one to honor the one-primitive principle.
- Should the cockpit primitives live in a new Components/cockpit/ directory or replace Components/CommandCenter/ in place? New dir is cleaner for the shared-library framing but requires updating all CommandCenter imports.
- Does the density multiplier persist per-user (via the existing /set-preference round-trip) or per-device (localStorage) for shared wall displays? Wall kiosks have no per-user session in a 24/7 floor context.
- Should RadialGauge keep a back-compat `color` prop during migration, or is a hard cut to `status`-derived color acceptable given the audit of call sites?
- The reference spec allows 9px panel labels as a sanctioned exception; do we adopt a sub-text-xs cockpit label size, or hold the canon text-xs floor and accept denser-but-larger labels?
- Confirm DrillModal should consume both /api/command-center/drilldown (existing) and the planned /api/cockpit/drill/{domain} (Phase backend), and whether the Zod drilldown schema needs extension for the 5 missing domain panels (Staffing/Quality/Service Lines/Financial/Transport).

---

# Part VI — Backend Foundation: StatusEngine, kpi_definitions & the Serving Layer

**Decision:** Build the cockpit backend as an ADDITIVE serving layer on top of the already-shipped `ops.*` trust schema and `CommandCenterDataService`: (1) extend `ops.metric_definitions` into the spec's `kpi_definitions` (add `direction`-aware band edges + `refresh_secs` + `alert_template`), (2) extract a single `StatusEngine` from the duplicated `bandHighBad`/`bandLowBad` methods resolving the reconciled 5-logical-state vocabulary, (3) decompose `CommandCenterDataService` into `Domain/Cockpit/Metrics/*` classes that REUSE the existing domain services as data sources, (4) assemble one `cockpit_snapshots` JSONB row per facility via a `RefreshCockpitSnapshot` job on the existing `withSchedule` hook, and (5) deliver via the existing Reverb channel pattern (flip `BROADCAST_CONNECTION`) with SSE/poll fallback. Nothing existing is rebuilt or removed.

_Rationale:_ The discovery digest's central finding is confirmed in code: Zephyrus already ships ~80% of the cockpit serving layer. The `ops.metric_definitions/metric_values/metric_lineage/source_freshness/data_quality_findings` tables (migration 2026_06_26_000010, all model-backed) ARE the §5.1/§5.3 schema the spec asks us to invent — `metric_definitions` already carries `metric_key`, `domain`, `direction CHECK(up|down|neutral)`, `target_value`, `cadence`, and a `jsonb metadata` column. It is missing only the band edges. `CommandCenterDataService::build()` is a working proto-SnapshotBuilder hitting 10+ live prod tables and emitting a status-tagged Donabedian document with a frozen Zod contract. The threshold logic is already centralized into exactly two private functions (`bandHighBad` line 421, `bandLowBad` line 437) plus `bandProgress` — these are the literal extraction point for `StatusEngine`, and they already encode the direction='down'/'up' semantics the spec's `resolveStatus` uses. The Reverb event pattern (`app/Events/Rtdc/CensusUpdated`) is PHI-free aggregate-only on public channels — the exact safety posture a wall display needs. Two production SSE precedents exist (`ProxiesEddyChatStream`, `PatientFlowStreamController`). The `HospitalManifest` + `config/hospital/hospital-1.php` is the facilities/units SSOT with `census_demo_targets`, and `CommandCenterDemoSeeder` already lights up Summit Regional. Building net-new tables/services would duplicate this and violate the user's DRY-after-3 and reuse-first principles. The token tension is a RENDERING-layer concern, not a backend one: the backend emits a 5-value `status` string enum; the canon mapping (normal→neutral, ok→success, watch→info, warn→warning, crit→critical) happens in the React status→style helper. The backend's only obligation is to emit the 5 logical states and never collapse them prematurely.

## Backend Foundation — Cockpit Data, StatusEngine & API Architecture

### Guiding principle: extend the trust layer, do not rebuild it

The single most important finding is that Zephyrus already ships the cockpit serving schema. Migration `database/migrations/2026_06_26_000010_create_ops_metric_trust_tables.php` created `ops.metric_definitions` (carrying `metric_key`, `domain`, `direction CHECK(up|down|neutral)`, `target_value`, `target_display`, `cadence`, `unit`, plus a `jsonb metadata`), `ops.metric_values`, `ops.metric_lineage`, `ops.source_freshness`, and `ops.data_quality_findings` — all model-backed (`app/Models/Ops/MetricDefinition.php` et al.) and partially populated by `app/Services/Analytics/MetricLineageService.php`. The spec's §5.1 `kpi_definitions` and §5.3 serving tables map onto this almost 1:1. We extend, we do not invent.

### 1. StatusEngine — the single threshold→status resolver

Today threshold logic lives in two private methods in `app/Services/CommandCenterDataService.php`: `bandHighBad()` (line 421, direction='down' semantics) and `bandLowBad()` (line 437, direction='up'), plus `bandProgress()` (line 452), and is duplicated in `OperationsAnalyticsService` (line ~748) with ~117 ad-hoc status strings scattered across 20+ services. **Extract one class:** `app/Services/Cockpit/StatusEngine.php`.

Define the 5 logical states as a PHP enum mirroring the spec §4, but **the engine emits the logical names, and a single `canonToken()` map performs the reconciliation** so no caller ever hand-codes a canon token:

```
enum CockpitStatus: string {
  case Normal='normal'; case Ok='ok'; case Watch='watch'; case Warn='warn'; case Crit='crit';
  // reconciliation to the enforced 4+neutral canon (rendering contract):
  public function canon(): string => match($this){
    Normal => 'neutral',   // grey baseline (ISA-101)
    Ok     => 'success',    // teal — confirmed on-target, used sparingly
    Watch  => 'info',       // sky/blue — eyes on it
    Warn   => 'warning',    // amber — off target
    Crit   => 'critical',   // coral-red — actionable
  };
}
```

`resolveStatus(float $value, KpiDefinition $def): CockpitStatus` reproduces the spec §4 comparator: `$cmp = direction==='down' ? $v >= $edge : $v <= $edge`; crit edge first, then warn, then `watch_edge`, then "beat ok_edge comfortably ⇒ Ok", else Normal. **Add a `watch` band** the spec under-specifies (it has `ok/warn/crit` edges only): watch fires when the value is within a configurable proximity (`metadata.watch_band_pct`, default 10%) of the warn edge — this gives ISA-101's "trending toward threshold" semantic without a fourth numeric column. The MetricValue carries BOTH `status` (logical: `crit`) and the canon is derived client-side from the helper in spec §8.3, so the backend stays canon-agnostic and the React layer owns the token bridge. Refactor `CommandCenterDataService::bandHighBad/bandLowBad` to delegate to `StatusEngine` (backward-compatible: it can return the legacy 3-state via `->canon()` collapsing during transition).

### 2. kpi_definitions config + the MetricValue contract

**Do not create a new table.** Add one additive migration that extends `ops.metric_definitions` with the spec's band columns: `ok_edge numeric`, `warn_edge numeric`, `crit_edge numeric`, `refresh_secs int default 300`, `source_system text`, `alert_template text`, `facility_id bigint`, `is_active boolean default true`. Use the `SafeMigration` trait (`app/Traits/SafeMigration.php`) and guard each `addColumn` with `Schema::hasColumn` so re-runs are idempotent and prod-safe; the migration is purely additive (no down-drop outside local, matching the existing file). A thin Eloquent accessor on `MetricDefinition` exposes `->edges()` returning `['ok'=>…, 'warn'=>…, 'crit'=>…, 'direction'=>…]` for the engine.

The **canonical MetricValue** is already the de-facto shape `CommandCenterDataService` emits and the Zod contract in `resources/js/types/commandCenter.ts` enforces. Formalize spec §3.1 exactly: `{key,label,value,display,unit,sub,status,target,direction,trend[],trendLabel,updatedAt}`. A `App\Support\Cockpit\MetricValue` factory (a value object, immutable per coding-style.md) builds it from a raw number + a `KpiDefinition`, calling `StatusEngine` once. Every domain Metrics class returns `MetricValue[]` — never raw rows.

### 3. SnapshotBuilder + DrillBuilder — REUSE map (do not rebuild)

Decompose the 1382-line `CommandCenterDataService` into `app/Domain/Cockpit/Metrics/*` (≤400 lines each per file-org rules), each a thin adapter over the **existing** service it wraps. The mapping, by Appendix-A key prefix:

| Cockpit domain | Metrics class | Reuses (verbatim data source) | Status |
|---|---|---|---|
| `rtdc.*` | RtdcMetrics | `HuddleService::hospitalRollup()`, `BedTrackingService::build()`, `DemandForecastService` (midnight_projection), `RtdcDashboardService` | LIVE |
| `ed.*` | EdMetrics | `EdDashboardService`, `Ed\TreatmentService` (boarders), `Ed\WaitTimeService::kpi()`, `DiversionEvent` | LIVE except `ed.nedocs` (needs vent_pts/longest_admit_wait/last_bed_time cols on prod.ed_visits) |
| `periop.*` | PeriopMetrics | `PerioperativeMetricsService` (7 KPIs), `Operations\RoomStatusService` (rooms), `InterventionAttributionService::pacuHoldsAt()` (PACU) | LIVE; rooms need `delay_min`+`suite_name` emit |
| `staffing.*` | StaffingMetrics | `StaffingOperationsService::overview()` | LIVE except OT%/agency/callouts/sitters (no schema cols — MOCK from manifest) |
| `flow.*` | FlowMetrics | `TransportOperationsService::measures()`, `EvsOperationsService`, `flow_core.*` (DBN%) | PARTIAL — transport_wait & EVS turnaround need new precompute |
| `quality.*` | QualityMetrics | none (no fact tables) | MOCK — seed `hai_ledger`, `quality_events` stubs |
| `service.*` | ServiceLineMetrics | `gmlos_references`, encounters | MOCK — `service_line_los` MV stub |
| `financial.*` | FinancialMetrics | none | MOCK — `cost_center_productivity` stub |
| `okr.*` (7 cards) | OkrMetrics | `CommandCenterDataService` OKR constants + the above | LIVE where source is live |

`SnapshotBuilder::build(Facility $f)` calls each Metrics class, assembles the §3.2 document (`facility`, `capacityStatus`, `census[8]`, `alerts[]`, `okrs[7]`, `domains{8}`), and **derives alerts**: collect every MetricValue with `status ∈ {warn,crit}`, sort crit-first, render via `alert_template`. `DrillBuilder::build($domain)` reuses the existing `CommandCenterDrilldownService` (which already does focus routing `panel:`/`metric:`/`unit:` at `app/Services/CommandCenterDrilldownService.php:798`) and emits the §6.4 Cell grammar (`string|number | {v,mono,strong,dim,status,color} | {bar} | {chip} | {tag}`) so the React DataTable is purely presentational. This honors the build-order: mock domains return demo numbers behind the same contract, swapped to live one domain at a time.

### 4. Serving layer — snapshots, alerts, MTD materialized views

Two thin new tables (additive migration, `SafeMigration`): `prod.cockpit_snapshots(facility_key text PK, payload jsonb, generated_at)` — a single replaced row keyed by `facility_key` (default `'HOSP1'` from `HospitalManifest`; **no `Facility` table/FK** — see **D1** in Part II.2) — and `prod.cockpit_alerts(id, facility_key, key, status, text, opened_at, cleared_at, hold_count int default 0)` for paging + history. The fast 1–5-min metrics read latest `*_samples`/live queries; the heavy MTD aggregations (HAI SIR, O:E LOS, cost-center productivity) become **materialized views** (`mv_service_line_los`, `mv_cost_center_productivity`, `mv_hai_ledger`) refreshed `CONCURRENTLY` on an hourly schedule. Also begin writing each snapshot's computed scalars into the EXISTING `ops.metric_values` table — this retires the synthetic-noise sparklines (27 of 30 today use crc32+sin/cos) with real history once enough rows accrue, and feeds `MetricLineageService` freshness for free.

### 5. API endpoints + the refresh job

Add one additive route group to `routes/api.php`, copying the established `['web','auth','throttle:60,1']` middleware pattern (the cockpit shares the Inertia session — no Sanctum):

```
Route::middleware(['web','auth','throttle:60,1'])->prefix('cockpit')->group(function(){
  Route::get('/snapshot', …);              // cockpit_snapshots.payload, ETag on generated_at, 304-aware
  Route::get('/drill/{domain}', …);        // DrillBuilder; domain ∈ rtdc|ed|periop|staffing|flow|quality|service|financial|okr
  Route::get('/kpi-definitions', …);       // admin threshold editor read
  Route::put('/kpi-definitions/{key}', …); // audited edge edit (writes ops.metric_definitions)
  Route::get('/stream', …);                // SSE fallback
});
```

`app/Jobs/RefreshCockpitSnapshot.php` (ShouldQueue) iterates `HospitalManifest::facilities()` (per **D1** — a value-object list, **not** an Eloquent `Facility` model), calls `SnapshotBuilder::build()`, `updateOrInsert`s `cockpit_snapshots`, reconciles `cockpit_alerts` (open new warn/crit, clear resolved), and dispatches `CockpitSnapshotUpdated`. Each Metrics class respects its own `refresh_secs` (returns cached value if not due) so the per-metric cadence in spec §2 is honored even though the job runs every minute. Wire it into the EXISTING `bootstrap/app.php:34` `withSchedule` hook beside `ReconcileRtdcPredictions`: `$schedule->job(new RefreshCockpitSnapshot)->everyMinute()->withoutOverlapping();` plus the MV refreshes hourly. The `GET /snapshot` endpoint is then a pure cache lookup (Cache::remember as belt-and-suspenders), not a query storm — this kills today's 15+-synchronous-query-per-load problem on `/dashboard`.

### 6. Real-time delivery — reuse Reverb, SSE as fallback

Add `app/Events/Cockpit/CockpitSnapshotUpdated.php` mirroring `app/Events/Rtdc/CensusUpdated.php`: `implements ShouldBroadcast`, public `Channel('hospital.cockpit')`, `broadcastAs('cockpit.snapshot')`, `broadcastWith()` carrying ONLY `{facility_id, generated_at}` (a reload ping — the payload is fetched from `/snapshot`, keeping the channel PHI-free per the existing aggregate-only discipline). The frontend reuses `resources/js/lib/echo.ts` and the `useLiveCensus` snapshot-on-reconnect pattern. **Operational gotcha to flag:** `config/broadcasting.php:18` defaults `BROADCAST_CONNECTION` to `'null'` — broadcasts are silently dropped in prod. The deploy must set `BROADCAST_CONNECTION=reverb`. The `GET /cockpit/stream` SSE endpoint (modeled on `app/Http/Concerns/ProxiesEddyChatStream.php` and `PatientFlowStreamController`) is the no-WebSocket fallback; a 30–60s poll with ETag/304 is the final fallback. The React shell keeps last-good and flags "stale — last sync HH:MM:SS" at 2× the expected interval, reusing the aging/stale detection already in `CommandCenter.tsx`.

### 7. Demo-data strategy — Summit Regional lights up immediately

Reuse `app/Support/Hospital/HospitalManifest.php` + `config/hospital/hospital-1.php` (the 25-unit/500-bed Summit Regional SSOT with `census_demo_targets`, nurse ratios, service lines, network facilities) as the cockpit `facilities`/`units` source — no new facility table. Extend `database/seeders/CommandCenterDemoSeeder.php` (which already drives `/dashboard`) to: (a) seed `ops.metric_definitions` rows for every Appendix-A key with literature-aligned default edges from spec §2 (e.g. `ed.nedocs` direction=down warn=101 crit=141 scale=200; `okr.dc_before_noon` direction=up ok=40 warn=40 crit=30), and (b) seed the MOCK fact-table stubs (`hai_ledger`, `service_line_los`, `cost_center_productivity`, NEDOCS input cols) with the spec §10 demo numbers tuned to fire **multiple simultaneous alerts** (ED severe/NEDOCS 142, boarders, 5 Med/Surg West understaffed, EVS slow) so the wall lights up for screenshots and acceptance tests. Prod refresh stays `php artisan zephyrus:demo-seed` then `db:seed CommandCenterDemoSeeder`. The mock domains return these seeded numbers behind the identical MetricValue contract, so swapping mock→live (build order step 6) requires zero frontend or contract change.

### Build sequence (additive, prod-safe)

1. Additive migration: extend `ops.metric_definitions` (edges/refresh_secs/alert_template/facility_id) + create `cockpit_snapshots`/`cockpit_alerts`. 2. Extract `StatusEngine` + `MetricValue` factory; refactor `CommandCenterDataService` band methods to delegate. 3. Seed `kpi_definitions` for all Appendix-A keys via `CommandCenterDemoSeeder`. 4. Decompose into `Domain/Cockpit/Metrics/*` (live domains first: RTDC, ED, Periop, Staffing). 5. `SnapshotBuilder` + `RefreshCockpitSnapshot` job on `withSchedule`. 6. `DrillBuilder` over `CommandCenterDrilldownService` with Cell grammar. 7. Routes + `CockpitSnapshotUpdated` Reverb event; set `BROADCAST_CONNECTION=reverb`. 8. Mock-table stubs (Quality/Service/Financial) + MVs. 9. Admin threshold editor (PUT, audited). Run `vendor/bin/pint` (Docker) after every PHP edit per project convention.

## Risks
- `config/broadcasting.php:18` defaults BROADCAST_CONNECTION='null' — if the deploy does not set it to 'reverb', CockpitSnapshotUpdated broadcasts are silently dropped and the wall display falls back to polling without anyone noticing. Add a startup assertion or deploy checklist item.
- Extending ops.metric_definitions vs the spec's standalone kpi_definitions: the spec's facility_id FK references a facilities table Zephyrus does not have (it uses HospitalManifest config, not a table). The facility_id column must be nullable/manifest-keyed, not a hard FK, or the migration breaks.
- 5 of 8 domains (Quality, Service, Financial, and parts of Staffing/Flow) have NO live fact tables. Shipping them as mock keeps the cockpit visually complete but risks executives trusting fabricated NEDOCS/HAI/HPPD numbers. Each mocked MetricValue must carry a metadata.provenance='demo' flag the UI surfaces, and source_freshness must mark them stale.
- RefreshCockpitSnapshot->everyMinute requires the queue worker + scheduler running in prod (Apache+php8.5-fpm). If Horizon/queue:work is not supervised, snapshots go stale silently. The existing schedule has only ONE daily job — the per-minute cadence is a new operational dependency.
- Decomposing the 1382-line CommandCenterDataService risks regressing the frozen Zod contract in resources/js/types/commandCenter.ts and the live /dashboard. The SnapshotBuilder must emit a byte-compatible superset; safeParseCommandCenterData fallback must be preserved during transition.
- NEDOCS requires vent_pts, longest_admit_wait_hrs, last_bed_time_hrs which are absent from prod.ed_visits. Adding columns to a seeded prod table is additive but the values are unmeasured today — NEDOCS will be partially synthetic until ADT/EHR integration, and must be labeled as such.
- Writing computed scalars into ops.metric_values every minute across ~80 metrics × N facilities grows the table fast; needs a retention/partition policy or the sparkline-history table balloons.
- PG transaction poisoning (per global rules): the RefreshCockpitSnapshot job touches many prod tables — any failed statement inside a transaction poisons the rest. Each facility build must be its own try-catch/nested transaction so one bad domain does not blank the whole snapshot.

## Open Questions
- Should kpi_definitions live as an EXTENSION of ops.metric_definitions (recommended — single config SoT) or as the spec's standalone table? The recommendation assumes extension; confirm before the migration is written.
- Facility scoping: HospitalManifest is single-facility (Summit Regional HOSP1) today but networkFacilities() lists others. Does 2.0 need true multi-facility cockpit_snapshots (one row per network facility) now, or is single-facility acceptable for v2.0 with facility_id nullable?
- Mocked-domain provenance: is a metadata.provenance='demo' flag + stale source_freshness sufficient executive protection, or must mocked Quality/Financial panels be hidden behind a feature flag until live fact tables exist (matching the EDDY_ENABLED ship-disabled precedent)?
- NEDOCS inputs (vent_pts, longest_admit_wait_hrs, last_bed_time_hrs): add nullable columns to prod.ed_visits now and seed demo values, or compute a simplified surrogate NEDOCS from available columns until ADT integration?
- ops.metric_values retention: what partition/retention window for the per-minute scalar history that will back live sparklines — 90 days to match TREND_DAYS, or longer?
- Reverb in prod: is a supervised queue:work/Horizon process already running on the Apache+php8.5-fpm box, or does the per-minute RefreshCockpitSnapshot job require new process supervision (systemd unit) as part of the deploy?
- Does the existing CommandCenterDrilldownService's focus-routing (panel:/metric:/unit:) cover all 9 spec drill domains, or do periop/staffing/quality drills need net-new DrillBuilder branches beyond what it provides?

---

# Part VII — Intelligence Layer: Alerting, the Eddy Loop & Predictive Overlays

**Decision:** Build a single server-derived alert engine off the StatusEngine's warn/crit MetricValue set (deduped, crit-first, open/clear lifecycle persisted in cockpit_alerts) that feeds the AlertTicker, drives fan-out through the EXISTING PushNotifier, and is the entry point that surfaces an Eddy recommended action — which then rides the already-shipped ops.agent_* control plane (EddyActionService.CATALOG → OperationalActionLifecycleService FSM) through a human approval gate, gated by EDDY_ENABLED. Predictive forecasts (DemandForecastService by_midnight, ArrivalPredictionService) become watch-state signals via dedicated kpi_definitions, and ExecutiveBrief/AgentInbox stop being separate pages and reconcile into the cockpit as an executive-role panel and a drill-modal action list.

_Rationale:_ The governance half of the loop is already production-grade and merged to main: AgentRunner seam, OperationalActionLifecycleService (draft→approved→assigned→executing→completed/rejected/overridden/expired FSM with lockForUpdate re-validation), EddyActionService.CATALOG (5 tiered T1/T2/T3 proposable actions, ops:draft-never-ops:approve), EddyApprovalNotifier wired to the PushNotifier contract (LogPushNotifier today, APNs/FCM later), and EddyContextService.forSurface() which ALREADY calls the same capacity.snapshot tool the cockpit will compute from. The only missing piece is the connective tissue: a StatusEngine-derived alert engine and the discipline that one snapshot feeds both the cockpit and Eddy. Building net-new alerting infra would duplicate what cockpit_alerts (already in the spec) and the ops lifecycle already provide. The central token tension resolves cleanly because alerting is logic, not pixels: the 5-state status maps to the 4-color canon (normal=grey, ok=teal-success, watch=sky-info, warn=amber-warning, crit=coral-critical) with shape disambiguating ok-dot from normal-dash. Risk/tier vocab (T1/T2/T3, low/med/high/critical) and the cockpit 5-state must be reconciled in one mapping table so EddyApprovalCard and AlertTicker speak the same shape+color language.

## Intelligence, Alerting & the Eddy Loop — Making the Cockpit Actionable

The Zephyrus 2.0 cockpit must do more than render status; it must *prosecute* exceptions. This chapter defines the intelligence layer: a server-side alert engine derived from the StatusEngine, fan-out hooks reusing the existing Push contract, the closed Eddy loop (alert → recommended action → human approval → audited execution), predictive overlays as watch-state signals, and the reconciliation of the executive-brief and agent-inbox surfaces into the cockpit role model. The guiding principle is **reuse**: the governance half of this loop is already merged to `main`. We are building connective tissue, not a new engine.

### 1. The Alert Engine — derived, never hand-set

Per the cockpit spec (`docs/Hospital Operations Cockpit/docs/COCKPIT_IMPLEMENTATION.md` §4, lines 397–402), alerts are a **derivation**, not a parallel rules system. After `SnapshotBuilder` tags every `MetricValue` via the shared `StatusEngine` (§4, lines 376–394), `AlertEngine` collects every metric whose status is `warn` or `crit`, **dedups by KPI key**, sorts **crit-first**, and renders the alert text from `kpi_definitions.alert_template` (§5.1, line 428 — e.g. `'ED OVERCROWDED — NEDOCS {value} …'`). This guarantees a single threshold authority. Today threshold logic is duplicated across `CommandCenterDataService::bandHighBad()` (line ~421) and `OperationsAnalyticsService::bandHighBad()` (line ~748) plus 117 ad-hoc status strings; the StatusEngine extraction collapses these, and the alert engine inherits that single source for free.

**Open/clear lifecycle.** The spec already provisions `cockpit_alerts` (§5.3, lines 546–550: `key, status, text, opened_at, cleared_at`). The engine runs as a reconciliation each snapshot:

- A KPI in warn/crit with **no open `cockpit_alerts` row** → INSERT (open).
- A KPI returned to normal/ok/watch with an **open row** → UPDATE `cleared_at` (clear).
- A KPI still warn/crit with an open row → no-op (this is the **dedup** that prevents re-paging every minute).

This makes `cockpit_alerts` the backing store for both the live **AlertTicker** (open rows, crit-first marquee) and the **alert history** view (cleared rows). To honor the *earned-urgency* canon, add a per-key **cooldown / flap-damping** column: require a status to hold for K consecutive snapshots before opening or clearing, so a metric oscillating around an edge does not strobe the ticker. Without this, deriving alerts from *every* warn/crit MetricValue will reproduce exactly the alarm-fatigue dashboard the canon forbids.

**Token reconciliation.** Alerting is logic, not pixels — the IBM-Plex/OKLCH conflict does not apply here. The 5-state cockpit vocabulary maps to the 4-color canon: `crit→healthcare-critical (coral) ◆`, `warn→healthcare-warning (amber) ▲`, `watch→healthcare-info (sky) ●`, `ok→healthcare-success (teal) ●`, `normal→muted grey –`. Shape disambiguates ok-dot from normal-dash, satisfying *status-never-by-color-alone*. The AlertTicker only ever shows warn/crit (amber/coral), so the ticker is honest about reserving saturated color for exceptions.

### 2. Fan-out hooks — reuse the Push contract

Fan-out is **already half-built**. `App\Contracts\PushNotifier` (PHI-free `sendToUser($user, $title, $body, $data)`) is bound to `LogPushNotifier` in `HummingbirdServiceProvider` and consumed by `EddyApprovalNotifier`. The alert engine's fan-out should dispatch through this same contract, not invent a notifier:

- **Push (mobile):** `PushNotifier::sendToUser` with a PHI-free payload (alert key, status tier, deep link only — mirroring `EddyApprovalNotifier`'s doorbell pattern, which carries *only* ids+tier+deep-link, never params/rationale). Gated by `EDDY_PUSH_ENABLED` (default `false`, `config/eddy.php` line 70) — the seam is inert until a real APNs/FCM sender replaces `LogPushNotifier`. **Do not present mobile paging as live.**
- **Teams / paging:** add sibling implementations behind a small `AlertChannel` interface (or Laravel Notification channels), keyed off `kpi_definitions` so only crit (and selected warn) KPIs page. The earned-urgency taxonomy in `EddyApprovalNotifier::tierForRisk` (tier_1 = genuine capacity breach) is the precedent: tier-1 fan-out is reserved for crit; warn alerts surface in-app only unless a KPI opts in.

**Prod footgun:** `BROADCAST_CONNECTION` defaults to `'null'`, so `CockpitSnapshotUpdated` and any new `hospital.cockpit` alert broadcast are silently dropped in prod unless the env var is set. The AlertTicker will appear to work in dev and be dead in prod. This must be set as part of the 2.0 cutover.

### 3. The Eddy Loop — alert → recommendation → approval → audited action

This is the payoff. Today the cockpit panel, the Eddy dock, the AgentInbox, and the Opportunity Portfolio are separate surfaces. In 2.0 they become one loop, and **the entire governance spine already exists** (confirmed merged):

1. **Surface.** A warn/crit KPI tile or AlertTicker entry exposes a drill affordance that opens the `EddyDock` pre-seeded with the alert's `key`, the offending `MetricValue`, and the matching `EddyActionService::CATALOG` action type. The CATALOG (`app/Services/Eddy/EddyActionService.php` lines 30–36) already ships 5 tiered proposable actions: `flag_barrier` (T1/low), `propose_huddle_action` (T1/low), `propose_transport_dispatch` (T2/medium), `propose_bed_placement` (T3/high), `propose_surge_plan` (T3/critical). A crit ED-boarding alert naturally maps to `propose_surge_plan`; a placement barrier to `flag_barrier`. Extend the CATALOG with an `alert_key` provenance field so every proposal is traceable to the alert that spawned it.

2. **Propose.** `EddyActionService::propose()` (lines 63–143) creates `Recommendation(draft) → OperationalAction(draft) → Approval(pending)` inside a DB transaction — pure governance records, **no domain mutation**. Eddy's scoped token holds `ops:draft` and **never** `ops:approve` (lines 18–20, 116–120), so its proposals always land pending for a human.

3. **Approve.** A web-session human (who `can()`s every ability) approves via `EddyApprovalCard` in the dock or the AgentInbox. Approval routes through `OperationalActionLifecycleService::decideApproval()` → the FSM (`draft → approved → assigned → executing → completed/rejected/overridden/expired`, confirmed lines 58–190) with `lockForUpdate` re-validation. **This is the existing inbox** — reuse it verbatim.

4. **Execute & audit.** The lifecycle service is the audit trail. `assign → start → complete` are all timestamped (`approved_at`, `assigned_at`, `completed_at`, ISO-serialized lines 217–224). Eddy executes nothing; it drafts, a human decides, the FSM records.

**Single-snapshot discipline (critical).** `EddyContextService::forSurface()` (lines 29–41) *already* calls `capacity.snapshot` via `AgentToolRegistry` — the same SQL the cockpit's StatusEngine will compute. Today this runs twice. In 2.0, **both the cockpit SnapshotBuilder and EddyContextService must read the same cached `cockpit_snapshots` row.** Otherwise alerts and Eddy's worldview diverge and a proposal cites stale numbers against the alert that spawned it. Wrap the build in `Cache::remember('cockpit.snapshot', 60, …)` primed by `RefreshCockpitSnapshot->everyMinute()->withoutOverlapping()` (§6.3, lines 598–611); point `EddyContextService` at the same cache key.

**Tier ↔ state reconciliation.** Eddy's risk vocabulary (T1/T2/T3, low/medium/high/critical) and the cockpit's 5-state must collapse into **one mapping table** so `EddyApprovalCard` and `AlertTicker` encode identical severity with identical shape+color: `critical-risk/T3 → crit ◆ coral`, `high → warn ▲ amber`, `medium → watch ● sky`, `low → ok/normal`. `EddyApprovalNotifier::tierForRisk` is the existing seam; extend it to emit cockpit `Status`, not just push tiers. Everything is `EDDY_ENABLED`-gated (dock suppressed when false, `EddyDock.tsx` line 33), respecting the ship-disabled precedent.

### 4. Predictive overlays as watch-state signals

The forecast surfaces (`DemandForecastService` `by_midnight`/`by_2pm`, `ArrivalPredictionService` 4h, `AcuityPredictionService`, `UtilizationForecastService`, `RtdcPrediction`) already emit a status vocabulary (`DemandForecastService::bedNeedStatus` → critical|warning|success, lines 226/316/389). The discipline for 2.0: **a forecast is uncertain, so it surfaces as `watch` (sky-blue ●), not `crit`.** Register dedicated `kpi_definitions` keys (e.g. `rtdc.midnight_deficit`, `ed.arrival_surge_4h`) whose `warn`/`crit` edges are *deliberately conservative* and whose default rendering is watch — "trending toward a threshold / needs eyes" (§4 line 401). Surfacing a projected midnight deficit as crit-red manufactures false urgency and erodes trust. The forecast becomes a real warn/crit alert only when the *actual* metric crosses its edge. This keeps predictions informative without polluting the earned-red alert stream, and the existing `ReconcileRtdcPredictions` job (the only current scheduled job) closes the accuracy loop.

### 5. Reconciling ExecutiveBrief & AgentInbox into the cockpit/role model

Two standalone pages (`resources/js/Pages/Ops/ExecutiveBrief.tsx`, `AgentInbox.tsx`) perpetuate the silo problem 2.0 exists to fix:

- **ExecutiveBrief** → becomes the **executive-role cockpit panel**. The role switcher already re-orders bands and swaps HeroWall for OkrScoreboard in executive mode; the executive brief (composed via the `executive_brief.compose` tool, already in `AgentToolRegistry`) renders as a narrative panel in that mode, refreshed on a slower cadence than the 1-min snapshot. No separate route.
- **AgentInbox** → becomes the **alert/action drill**. It is already a view over the `OperationalActionLifecycleService` queue. In 2.0 it is reachable as a DrillModal from the AlertTicker and from any KPI tile that has open Eddy proposals — the same FSM data, surfaced where the operator is looking. The standalone `/ops/agent-inbox` route can remain as a deep-link target but is no longer the only path.

This collapses four surfaces into one loop driven by one snapshot, satisfying the single-screen situational-awareness north star while reusing every governance primitive already shipped. The net new code is `StatusEngine`, `AlertEngine`, `cockpit_alerts` wiring, the AlertTicker component, a Teams notifier impl, and an approver-routing policy — everything else is reconciliation of assets that already exist on `main`.

## Risks
- Double-SQL drift: CommandCenterDataService and EddyContextService->capacity.snapshot run overlapping queries; if the cockpit SnapshotBuilder and Eddy context aren't pointed at the SAME cached snapshot, alerts and Eddy's worldview diverge and proposals reference stale numbers
- Alert fatigue regression: deriving alerts from EVERY warn/crit MetricValue without dedup-by-key + suppression windows will flood the ticker and paging, violating the earned-urgency canon — the open/clear lifecycle and a per-key cooldown are mandatory, not optional
- BROADCAST_CONNECTION defaults to 'null' in prod — CockpitSnapshotUpdated/alert broadcasts silently drop unless the env var is set; the alert ticker would appear to work in dev and be dead in prod
- EDDY_PUSH_ENABLED defaults false and the PushNotifier binding is LogPushNotifier — fan-out to devices is inert until a real APNs/FCM sender ships; the plan must not present mobile paging as live
- Tier/state mapping ambiguity: T1/T2/T3 + low/med/high/critical risk vs normal/ok/watch/warn/crit must be ONE table or EddyApprovalCard and AlertTicker will encode the same severity with different shapes/colors
- Predictive overlays as crit (not watch) would erode trust — a forecast is by definition uncertain; surfacing by_midnight deficits as crit-red instead of watch-blue manufactures false urgency
- Approval routing is a stub: EddyApprovalNotifier.resolveApprover falls back to the proposing actor; without a real approver-routing policy, capacity-crit proposals page the wrong person or no on-call lead

## Open Questions
- Approver-routing policy: who receives a crit capacity alert's Eddy proposal? Per-unit charge nurse, house supervisor, on-call admin? resolveApprover() currently defaults to the proposing actor — needs a real on-call/role lookup before paging is meaningful
- Alert cooldown/suppression window per KPI: is a fixed N-minute cooldown sufficient, or do we need flap-damping (require status to hold for K consecutive snapshots before opening/clearing)?
- Teams fan-out channel: is the Push service contract the right seam for Teams, or should Teams be a separate Notifier impl/Laravel Notification channel? Confirm whether an Incoming Webhook or Graph API is the target
- Does the executive-brief composition (executive_brief.compose tool) become a cockpit panel rendered from the snapshot, or remain an Eddy-generated narrative refreshed on a slower cadence?
- Should predictive watch-state KPIs (by_midnight deficit, 4h ED arrival surge) ever auto-escalate to warn if the forecast confidence band tightens, or always stay watch until the actual metric crosses a real threshold?
- Alert-to-Eddy seeding: when a warn/crit tile opens Eddy, do we pre-seed the chat envelope with the alert_key + offending MetricValue + the matching CATALOG action type, or let Eddy infer the action from context?
- cockpit_alerts retention/history depth — how long do cleared alerts persist for the history view, and is there a PHI concern in alert text templates that name patients/units?

---

# Part VIII — Phased Migration Roadmap

> **What this is.** The execution spine for evolving Zephyrus from a disjointed 137-page parallel-section app into the unified **Hospital Operations Cockpit**. This is a **brownfield evolution of a LIVE, DEPLOYED app** (`/var/www/Zephyrus`, Apache + php8.5-fpm), not a greenfield build. Every phase **reuses existing services/components**, lands **additively behind flags**, **never regresses the token canon** (`scripts/check-ui-canon.sh` + the impeccable hook stay green on every commit), and **never touches the protected auth system** (`.claude/rules/auth-system.md`). Data is production — additive/reversible only.

> **Sequencing principle.** Adopt the spec §9 build order — *primitives → StatusEngine/seed → SnapshotBuilder(mocked) → DrillBuilder → frontend wired → swap mocks per domain → alerting → admin editor → wall hardening* — adapted to the fact that **~60% of the cockpit already exists** on `main` (`Pages/Dashboard/CommandCenter.tsx`, `Components/CommandCenter/*`, `CommandCenterDataService::build()`, the Zod contract, the `ops.*` trust schema, the Eddy governance plane). We **evolve in place**; we do not rebuild.

---

## Roadmap at a glance

| Phase | Name | Goal | Primary risk | Ship gate |
|---|---|---|---|---|
| **P0** | Reconciled foundations | Tokens-as-discipline + shared primitive library + StatusEngine + `kpi_definitions` | Canon drift while extracting primitives | Both `tsc --noEmit` + `vite build` + `check-ui-canon.sh` green; Pint green |
| **P1** | SnapshotBuilder + Cockpit API + Summit demo | One server-computed snapshot drives everything (mocked-then-live) | Snapshot ≠ Eddy worldview divergence | `/api/cockpit/snapshot` returns a parsed payload; Summit lights up multiple alerts |
| **P2** | Unified Cockpit overview | `/dashboard` becomes the one cockpit HOME; CommandBar/CensusStrip/AlertTicker/8 panels | Regressing the working `/dashboard` | A0 single-screen, no-scroll, role-aware; old `/dashboard` parity or better |
| **P3** | Drill modals per domain | Every panel header + OKR card opens a `DrillModal` reusing section logic | Modal scrim/glassmorphism canon violations | 9 domain drills, ESC/backdrop/button close, solid scrim |
| **P4a** | Unified nav + redirects + persona chrome | One home, one descent path, one nav SSOT (4 altitude sections); 5 overviews → drills | Breaking bookmarks / Cmd+K palette | 5 `/dashboard/*` → `?drill=` redirects (depend on P2/P3 param-reading); nav restructured; `/home` + dead `DashboardContext` deleted |
| **P4b** | Layout convergence (5 shells → 1) | One app shell; `ChangePasswordModal` app-wide (closes the 51-page gap) | Silently stripping chrome from 14 RTDC / 5 Transport pages | Per-shell `RouteSmokeTest` checkpoint; one shell renders the modal everywhere |
| **P5** | Analytics + PI → the Study altitude | A3 consolidation; de-dup surgical deep-dives; kill mock bundles | Hidden mock→live denominator mismatches | Surgical deep-dives single canonical home; mock bundles removed from prod |
| **P6** | Eddy loop + alerting fan-out | alert → recommended action → approval → audited execution; ticker + push | Alarm fatigue (flap/strobe) | Flap-damped `cockpit_alerts`; Eddy proposes via existing FSM; `EDDY_ENABLED`-gated |
| **P7** | Swap mocks → real sources per domain | Replace seeded MOCK domains + synthetic sparklines with live history | Per-metric denominator drift | RTDC→Flow→Staffing→ED→Periop→Quality→Service→Financial, contract unchanged |
| **P8** | Wall-display / kiosk hardening | `?display=wall` chromeless kiosk mode; SSE reconnect; staleness; reduced-motion; zoom | Dead Reverb in prod (`BROADCAST_CONNECTION=null`) | Wall mode renders chromeless; stale banner; SSE/poll fallback proven |

**Cutover footgun applies to every phase that touches real-time:** `config/broadcasting.php` defaults `BROADCAST_CONNECTION='null'` → broadcasts are silently dropped in prod. The deploy that ships P1/P6/P8 real-time MUST set `BROADCAST_CONNECTION=reverb` (and recall `docker compose restart` does NOT reload `env_file` — must `up -d`; here it's Apache+fpm so the deploy must `./deploy.sh` and clear config cache).

---

## P0 — Reconciled Foundations

**Goal.** Land the design-language reconciliation and the backbone primitives/engine **before any cockpit feature**, so every later phase builds on a single token bridge, a single status resolver, and a single primitive library. Nothing user-visible changes yet (additive modules + a backend extraction that delegates back to existing behavior).

**Scope / workstreams.**
1. **Status vocabulary as discipline (no new tokens).** Document the 5-logical-state → 4-color+grey mapping (`normal→neutral-grey / ok→teal(success) / watch→sky(info) / warn→amber / crit→coral`) and the ISA-101 render rules (grey baseline; value-text colored only for warn/crit, ok in rationed slots; neutral meter tracks; sky domain swatch). Add the **sanctioned `text-[11px]` wall-mode exception** to `CLAUDE.md` alongside the existing Auth/Design exceptions. **Harden `scripts/check-ui-canon.sh`** to script-enforce the reconciliation (per Part II.1#3): add patterns for `oklch(`, `backdrop-blur`, and raw `bg-(gray|red|blue|green|amber|indigo|slate)-[0-9]` in `resources/js` (excluding sanctioned paths), **and** widen the `text-[Npx]` exclusion to allow `text-[11px]` inside `Components/cockpit/` — documentation alone does not stop an `exit 1`, so the wall-mode exception trips its own gate unless the exclusion path is widened.
2. **Backend StatusEngine extraction.** Create `app/Services/Cockpit/StatusEngine.php` + a `CockpitStatus` enum (`Normal/Ok/Watch/Warn/Crit`) with a `canon()` map (the rendering contract; backend stays canon-agnostic). Implement `resolveStatus(float, KpiDefinition)` reproducing spec §4 (crit→warn→watch→ok comparator, `direction`-aware), adding the under-specified **watch band** via `metadata.watch_band_pct` (default 10% proximity to warn edge). Refactor `CommandCenterDataService::bandHighBad()`(line 421)/`bandLowBad()`(437)/`bandProgress()`(452) to **delegate** to it (backward-compatible 3-state collapse during transition); leave `OperationsAnalyticsService::bandHighBad()`(~748) for P5 to converge.
3. **`kpi_definitions` schema extension.** One additive migration using `app/Traits/SafeMigration.php`, each `addColumn` guarded by `Schema::hasColumn` (idempotent, prod-safe, no destructive down outside local), extending `ops.metric_definitions` with `ok_edge / warn_edge / crit_edge numeric`, `refresh_secs int default 300`, `source_system text`, `alert_template text`, `facility_id bigint`, `is_active boolean default true`. Add an `->edges()` accessor on `app/Models/Ops/MetricDefinition.php`.
4. **MetricValue value object.** `app/Support/Cockpit/MetricValue.php` (immutable per coding-style.md), built from a raw number + a `KpiDefinition`, calling `StatusEngine` exactly once, emitting spec §3.1 shape `{key,label,value,display,unit,sub,status,target,direction,trend[],trendLabel,updatedAt}`.
5. **Shared frontend primitive library `resources/js/Components/cockpit/`.** Promote the ~60% that exists in `Components/CommandCenter/`:
   - `cockpit/statusStyle.ts` — the **single** client mirror of `StatusEngine`: `statusStyle(level) → { color: STATUS_VAR[...], glyph: '–'|'●'|'▲'|'◆', label, valuePrimary }`. This is the one place glyph + color + ISA-101 value-color policy lives. Add a `CockpitState` **type alias** onto the existing `StatusLevel` enum (per D7 — no physical rename of the ~117 ad-hoc assignments).
   - **Extract** `Sparkline` from `KpiTile.tsx:12-59` → `cockpit/Sparkline.tsx`; **extract** `MeterBar` from `UnitHeatStrip.tsx:60-63` / `OkrScoreboard.tsx:25-28` → `cockpit/MeterBar.tsx`.
   - **Generalize** `CommandCenter/Gauge.tsx` → `cockpit/RadialGauge.tsx` (add `scale` + optional `bands[]` for the NEDOCS 0–200 multi-segment arc; derive `color` from `status`, drop the free-hex `color` prop — a tightening).
   - **New:** `cockpit/StatusChip.tsx` (the load-bearing accessibility primitive: shape+color+`role="img"` `aria-label`), `cockpit/CensusChip.tsx`, `cockpit/Panel.tsx` (drill-entry header as a focusable `<button aria-haspopup="dialog">` with gold `:focus-visible` + `⤢` glyph), `cockpit/DataTable.tsx` (typed `Cell` union over the bare `ui/table.jsx`), `cockpit/Tile.tsx` (promote `KpiTile`, consume extracted Sparkline/MeterBar + `statusStyle`, apply `valuePrimary`).
   - Shared Zod additions in `resources/js/types/cockpit.ts` extending `commandCenter.ts`.

**Files / areas touched.** `app/Services/Cockpit/StatusEngine.php` (new); `app/Support/Cockpit/MetricValue.php` (new); `app/Models/Ops/MetricDefinition.php`; `app/Services/CommandCenterDataService.php` (band methods → delegate); one additive migration (extend `ops.metric_definitions`); `resources/js/Components/cockpit/*` (new); extract from `Components/CommandCenter/KpiTile.tsx`, `UnitHeatStrip.tsx`, `OkrScoreboard.tsx`, `Gauge.tsx`; `resources/js/types/cockpit.ts` (new); `CLAUDE.md` (exception annotation).

**Dependencies.** None — this is the floor. **Merged/deprecated:** nothing user-facing; band methods become thin delegators (old behavior preserved).

**Prerequisite decision (D1, settle before P1's migration).** Single-facility: `cockpit_snapshots` is a single row keyed by `facility_key` (default `'HOSP1'` from `HospitalManifest`); **no `Facility` table/model**; `RefreshCockpitSnapshot` iterates `HospitalManifest::facilities()`.

**Acceptance criteria.** `StatusEngine` unit-tested against spec §4 edge cases per direction; `/dashboard` renders byte-identically (delegation is behavior-preserving); `npx tsc --noEmit` AND `npx vite build` AND `scripts/check-ui-canon.sh` all green; `vendor/bin/pint` (Docker) green; zero raw-Tailwind / zero `font-bold` / zero `text-[Npx]` (except the documented wall exception) / zero OKLCH / zero IBM Plex introduced.

**Effort / sequencing.** ~1.5–2 weeks. Internal order: `statusStyle.ts` → extract Sparkline/MeterBar → RadialGauge → new primitives (StatusChip/CensusChip/Panel/DataTable) → backend StatusEngine + migration + MetricValue. Lands as **sequential commits on main** (not a long-lived worktree — sweep-regression protocol).

**Chief risk.** Extracting Sparkline/MeterBar from `KpiTile` while keeping `/dashboard` pixel-stable; mitigate by making the extracted components drop-in (same props/markup) and verifying against the live page before merge.

---

## P1 — SnapshotBuilder + Cockpit API + Summit Demo Data

**Goal.** One **server-computed snapshot** drives the whole overview. Build the serving layer additively on the `ops.*` trust schema, **mocked-first then live** (spec §9 step 3), so the full overview can pixel-match the prototype before any new EHR integration.

**Scope / workstreams.**
1. **Decompose, don't rebuild.** Split the 1,382-line `CommandCenterDataService` into `app/Domain/Cockpit/Metrics/*` (≤400 lines each), each a **thin adapter over the existing domain service** by Appendix-A key prefix:
   - `RtdcMetrics` → `HuddleService::hospitalRollup()`, `BedTrackingService::build()`, `DemandForecastService` (midnight), `RtdcDashboardService` *(LIVE)*
   - `EdMetrics` → `EdDashboardService`, `Ed\TreatmentService` (boarders), `Ed\WaitTimeService::kpi()`, `DiversionEvent` *(LIVE except `ed.nedocs` — needs `vent_pts/longest_admit_wait/last_bed_time` cols, deferred to P7)*
   - `PeriopMetrics` → `PerioperativeMetricsService` (7 KPIs), `Operations\RoomStatusService`, `InterventionAttributionService::pacuHoldsAt()` *(LIVE; rooms need `delay_min`+`suite_name` emit)*
   - `StaffingMetrics` → `StaffingOperationsService::overview()` *(LIVE except OT/agency/callouts/sitters — MOCK from manifest, real in P7)*
   - `FlowMetrics` → `TransportOperationsService::measures()`, `EvsOperationsService`, `flow_core.*` *(PARTIAL — transport_wait & EVS turnaround need precompute, P7)*
   - `QualityMetrics`, `ServiceLineMetrics`, `FinancialMetrics` *(MOCK — seeded stubs, real in P7)*
   - `OkrMetrics` (7 cards) → OKR constants + the above
   Every Metrics class returns `MetricValue[]` — never raw rows; each respects its own `refresh_secs`.
2. **`SnapshotBuilder::build(Facility)`** assembles the spec §3.2 document (`facility, capacityStatus, census[8], alerts[], okrs[7], domains{8}`) and derives alerts (collect warn/crit MetricValues, crit-first). **`DrillBuilder::build($domain)`** reuses `CommandCenterDrilldownService` (focus routing `panel:`/`metric:`/`unit:` at line ~798) and emits the §6.4 `Cell` grammar so React is purely presentational.
3. **Serving + refresh.** Additive (`SafeMigration`) `prod.cockpit_snapshots(facility_key text PK, payload jsonb, generated_at)` — a single replaced row (default key `'HOSP1'` from `HospitalManifest`; **no `Facility` table/FK**, per D1) — plus `prod.cockpit_alerts(id, facility_key, key, status, text, opened_at, cleared_at, hold_count int default 0)` (the `hold_count` flap-damping column is created **now**, not ALTER'd in P6). `app/Jobs/RefreshCockpitSnapshot.php` (ShouldQueue) iterates `HospitalManifest::facilities()` (a value-object list, **not** an Eloquent model), `updateOrInsert`s snapshots, wired to the existing `bootstrap/app.php` `withSchedule` hook beside `ReconcileRtdcPredictions`: `->everyMinute()->withoutOverlapping()`. Begin writing computed scalars into the existing `ops.metric_values` (retires synthetic sparklines over time; feeds `MetricLineageService` freshness for free).
4. **API.** Additive route group in `routes/api.php`, copying the established `['web','auth','throttle:60,1']` Inertia-session pattern (no Sanctum): `GET /cockpit/snapshot` (cache lookup, ETag on `generated_at`, 304-aware — kills the 15+-synchronous-query-per-load problem), `GET /cockpit/drill/{domain}`, `GET /cockpit/kpi-definitions`, `PUT /cockpit/kpi-definitions/{key}` (audited, P8), `GET /cockpit/stream` (SSE fallback, modeled on `ProxiesEddyChatStream` / `PatientFlowStreamController`).
5. **Summit demo lights up immediately.** Reuse `HospitalManifest` + `config/hospital/hospital-1.php` (25-unit/500-bed Summit Regional SSOT) as facilities/units source — no new facility table. Extend `database/seeders/CommandCenterDemoSeeder.php` to seed `ops.metric_definitions` rows for **every Appendix-A key** with literature-aligned default edges (`ed.nedocs` direction=down warn=101 crit=141 scale=200; `okr.dc_before_noon` direction=up ok=40 warn=40 crit=30), plus the MOCK fact-table stubs tuned to fire **multiple simultaneous alerts** (ED severe/NEDOCS 142, boarders, 5 Med/Surg West understaffed, EVS slow) for screenshots and acceptance tests. Every mocked MetricValue carries `metadata.provenance='demo'` (surfaced as a UI badge) and is marked stale in `source_freshness`; a `COCKPIT_HIDE_DEMO_DOMAINS` config flag (default `false`, per D5) lets a real deployment hide Quality/Service/Financial until live. **`ed.nedocs` is synthetic until P7** (its input columns don't exist on `prod.ed_visits` yet) — the demoed 'NEDOCS 142' crit alert is a seeded mock, labeled as such (Part II.1#7).

**Files / areas touched.** `app/Domain/Cockpit/Metrics/*` (new); `app/Services/Cockpit/SnapshotBuilder.php`, `DrillBuilder.php` (new); `app/Jobs/RefreshCockpitSnapshot.php` (new); `bootstrap/app.php` (schedule); two additive migrations; `routes/api.php` (group); `database/seeders/CommandCenterDemoSeeder.php`; reuses `CommandCenterDrilldownService`, `HospitalManifest`, `config/hospital/hospital-1.php`, all domain services verbatim.

**Dependencies.** P0 (`StatusEngine`, `MetricValue`, `kpi_definitions` columns). **Merged/deprecated:** `CommandCenterDataService::build()` is the proto-SnapshotBuilder — its query battery migrates into the Metrics adapters; the old method can delegate to `SnapshotBuilder` during transition (the `/dashboard` controller keeps working until P2 flips it).

**Acceptance criteria.** `GET /api/cockpit/snapshot` returns a payload that `parseCommandCenterData`/the new `cockpit.ts` schema parses without fallback; Summit seed fires ≥4 simultaneous alerts; `RefreshCockpitSnapshot` runs `withoutOverlapping` and primes the cache so `/snapshot` is a pure cache hit; drill payloads for all 9 domains return valid `Cell`-grammar tables; Pint + canon green.

**Effort / sequencing.** ~2–2.5 weeks. Live domains first (RTDC, ED, Periop, Staffing), MOCK domains (Quality/Service/Financial) return seeded numbers behind the **identical** MetricValue contract so the P7 mock→live swap requires zero contract change.

**Chief risk.** **Snapshot/Eddy divergence** — `EddyContextService::forSurface()` already calls `capacity.snapshot` (the same SQL). Both MUST read the same cached `cockpit_snapshots` row (`Cache::remember('cockpit.snapshot', 60, …)`) or a proposal cites stale numbers against the alert that spawned it. Point `EddyContextService` at the same cache key in this phase even though the loop lands in P6.

---

## P2 — The Unified Cockpit Overview (the one HOME)

**Goal.** Make `/dashboard` the single ISA-101 house-wide situational-awareness surface — **A0 Glance**, one screen, no scroll — replacing the fragmented home. Evolve `Pages/Dashboard/CommandCenter.tsx` in place; do not replace it.

**Scope / workstreams.**
1. **Wire the page to the snapshot.** `CommandCenterController` serves the cached `cockpit_snapshots.payload` (Inertia initial render) with TanStack-Query refresh against `/api/cockpit/snapshot` (ETag/304); keep `safeParseCommandCenterData` defensive degrade (never white-screen).
2. **Add the missing layout grammar rows** (spec §0.3 / §8.1): **CommandBar** (facility chip + capacity-status pill + 1Hz clock + LIVE badge), **8-chip CensusStrip**, **AlertTicker** (crit-first marquee, blip behind `prefers-reduced-motion`), **OKR Scorecard expanded to 7 cards**, the **8-domain panel grid** (today only 3 of 8 panels exist — add Staffing, Quality, Service Lines, Financial, Transport/Flow), legend/footer.
3. **Refactor section components onto the P0 library.** `UnitHeatStrip` → grid of `CensusChip`; `OkrScoreboard` rows → `MeterBar`, card → `cockpit/Panel` with `onDrill` (so "every OKR card is a drill-down entry point"); `KpiTile` → `cockpit/Tile`; `Band` keeps its URL `Link` for nav while domain panels use `Panel.onDrill` for in-place modals (P3).
4. **Apply ISA-101 render discipline.** Grey baseline; value-text colored only for warn/crit (and ok in rationed slots); neutral meter tracks; sky domain swatches; keep canon solid `#0F172A→#1E293B` (reject the reference radial-gradient).
5. **Implement NEDOCS gauge** via `RadialGauge` `scale=200` + 5-band arc (composite stays MOCK until P7 adds the input cols; carry the `provenance='demo'` badge).
6. **Read the deep-link params (D2).** `CommandCenter.tsx` reads `?drill={domain}` and `?display=wall` (it reads neither today). `?display=wall` is handed to the layout shell (P8 builds full wall mode; P2 just wires the flag); `?drill=` is held in state for P3 to auto-open. **This MUST land in P2 because P4a's redirects depend on it.**

**Files / areas touched.** `resources/js/Pages/Dashboard/CommandCenter.tsx`; `Components/CommandCenter/CommandCenterView.tsx`; new `cockpit/CommandBar.tsx`, `CensusStrip.tsx`, `AlertTicker.tsx`; refactor `UnitHeatStrip.tsx`, `OkrScoreboard.tsx`, `KpiTile.tsx` onto `cockpit/*`; `CommandCenterController`.

**Dependencies.** P0 (primitives), P1 (snapshot API + 8 domains + OKRs). **Downstream:** P4a's overview→drill redirects depend on the `?drill=` param-reading wired here. **Merged/deprecated:** the old `/dashboard` 4-band layout absorbs into the cockpit grammar (HeroWall↔OKR swap behavior preserved for the role switcher).

**Acceptance criteria.** `/dashboard` is one screen, no scroll at desk resolution; all 8 panels + 8 census chips + 7 OKR cards render from the live/seeded snapshot; role switcher re-ranks bands and swaps HeroWall↔OKR (executive Outcomes-first); stale banner fires at 2× expected interval; parity-or-better vs the pre-2.0 `/dashboard`; canon + Pint + both builds green.

**Effort / sequencing.** ~2 weeks. Land the 3 new chrome rows first (CommandBar/CensusStrip/AlertTicker), then the 5 missing panels, then the refactor of existing sections (lowest-risk last).

**Chief risk.** Regressing the **production-quality working `/dashboard`**. Mitigate by feature-flagging the new cockpit grammar (`?cockpit=1` or a config flag) until parity is proven, then flip the default — the old render path stays available for one release as a rollback.

---

## P3 — Drill Modals Per Domain (reuse section logic)

**Goal.** Make every panel header and OKR card a drill-down entry point that opens a full-screen **A2 Drill** `DrillModal` (KPI strip + detail tables), reusing each section's existing board/queue/table logic. This is what lets P4 deprecate the standalone `/dashboard/*` overviews into drills.

**Scope / workstreams.**
1. **Build `cockpit/DrillModal.tsx` on Radix `ui/dialog.jsx`** (ships ESC + backdrop-click + focus-trap + `aria-modal` for free) over the **just-fixed solid scrim** (`.modal-backdrop` / `.modal-surface`, `app.css:197-214`, `shadow-lg`, **no `backdrop-blur`**). Structure per the reference 9-modal pattern: `9px×30px` accent bar in `statusStyle(accent).color` + title (`text-base font-semibold`) + sub + timestamp + close; body = 6-tile KPI strip (`cockpit/Tile` grid) + 1–3 `cockpit/DataTable` sections. Max-width 1280px, max-height 88vh, internal scroll. Data via TanStack Query against `/api/cockpit/drill/{domain}` (+ existing `/api/command-center/drilldown`), parsed by the existing `parseCommandCenterDrilldown` (`commandCenter.ts:338`).
2. **Map the 9 domain drills to existing section data:** RTDC (units board + boarding/placement barriers via `BedTrackingService`/`DischargePrioritiesService`), ED (track board rows + admitted-boarders drill from `TreatmentService`/`TriageService`), Periop (18-suite room board from `RoomStatusService` + PACU bay table from `or_logs` joins / `pacuHoldsAt()`), Staffing (RN-ratio table from `StaffingOperationsService`), Flow (discharge funnel + transport queue), Quality/Service/Financial (seeded MOCK tables behind the same grammar), OKR (9-row drill).
3. **Retire/replace legacy modals.** `Dashboard/DrillDownModal.jsx` and the periop `DrillDownModal` (both with `Math.random()` sparklines) → `DrillModal` with real `cockpit/Sparkline`. **Fix glassmorphism on contact:** `Components/Modal.jsx:45` (`bg-gray-500/75`), `RTDC/StatusUpdateModal.jsx:154`, `RTDC/TrendsModal.jsx:126` (`backdrop-blur-sm bg-black/30`) → `.modal-backdrop`/`.modal-surface`. **Auth's `ChangePasswordModal.jsx` is protected — do not touch.**
4. **Auto-open from the URL (D2).** When `?drill={domain}` is present, the cockpit opens the matching `DrillModal` on mount; opening/closing a drill updates the URL via `history.pushState` so the browser **Back button and ESC both close it** and the drill is shareable/bookmarkable. **P4a's redirects depend on this auto-open.**

**Files / areas touched.** `resources/js/Components/cockpit/DrillModal.tsx` (new); wires to `CommandCenterDrilldownService` + the P1 `DrillBuilder`; retires `Pages/Dashboard/DrillDownModal.jsx` + periop DrillDownModal; fixes 3 glassmorphism modals; `cockpit/Panel.onDrill` handlers in `CommandCenter.tsx`.

**Dependencies.** P0 (DataTable, StatusChip, Panel), P1 (DrillBuilder + Cell grammar), P2 (panels with `onDrill` wired).

**Acceptance criteria.** All 9 domain drills open from panel headers + OKR cards; solid scrim (no glassmorphism); close via ESC/backdrop/button with focus-restore-to-trigger; `bar`/`chip`/`tag` cells expose `aria-label`; the two `Math.random()` modals are gone; the 3 glassmorphism modals fixed; canon + builds green.

**Effort / sequencing.** ~2 weeks. Build `DrillModal` shell first, then the live-data drills (RTDC/ED/Periop/Staffing), then MOCK drills, then retire legacy modals.

**Chief risk.** Reintroducing glassmorphism / non-solid scrim (canon violation the hook will flag). Build exclusively on the already-fixed `.modal-surface`/Radix path; never `backdrop-blur`.

---

## P4a — Collapse the Six Dashboards → Unified Nav + Redirects + Persona Chrome

**Goal.** One home, one descent path, one nav SSOT. Implement the **Altitude model** (A0 Cockpit / A1 Workspace / A2 Drill / A3 Study) in navigation and routing: deprecate the redundant overviews into drills, repoint domains to their live workspaces, restructure `navigationConfig.ts` into four altitude sections.

**Scope / workstreams.**
1. **Deprecate the 5 redundant overviews to redirects (preserve bookmarks).** `routes/web.php:50-54` — `/dashboard/{rtdc|perioperative|emergency|improvement|transport}` become Inertia redirects to `/dashboard?drill={rtdc|periop|ed|quality|flow}` (open that domain's DrillModal over the cockpit). **Delete `/home`** (`routes/web.php:37`, hardcoded mock, unreachable — dead weight, not a sanctioned surface). No URL hard-deleted except `/home`.
2. **Restructure `navigationConfig.ts` (remains the SSOT)** from 8 flat sibling domains into 4 altitude-aligned sections: **COCKPIT** (home link `/dashboard`, no submenu), **WORKSPACES** (RTDC/Emergency/Perioperative/Transport/Staffing/Patient Flow — each `dashboardHref` **repointed** from its dead `/dashboard/*` overview to its primary live workspace, e.g. RTDC → `/rtdc/bed-tracking`, Periop → `/operations/room-status`), **STUDY** (Analytics + Process-Improvement merged — see P5), **ADMIN** (User Management, `adminOnly`, unchanged). `flattenNavigation()` still feeds Cmd+K with href-dedup.
3. **Move `RoleSwitcher` to persistent app chrome** next to `TopNavbar`, URL-synced via `?role=` so a shared link reproduces the persona view; persona changes never change the route (re-rank bands / swap HeroWall↔OKR within `/dashboard`). Reserve the `service-line` persona slot.
4. **(Layout convergence is split into P4b — see below.)** P4a keeps each page on its current shell; merging the five shells into one and surfacing `ChangePasswordModal` app-wide is the highest-blast-radius IA step (per Part II.1#5) and gets its own gated phase with a per-shell smoke checkpoint.
5. **Delete `DashboardContext.tsx`** (~460-line `workflowNavigationConfig` duplicate, zero consumers, conflicts with the SSOT) and remove its mount in `Providers.tsx`.

**Files / areas touched.** `routes/web.php` (5 redirects + `/home` delete); `resources/js/config/navigationConfig.ts` (restructure); `resources/js/Components/CommandCenter/RoleSwitcher.tsx` → app chrome; `TopNavbar`; layouts + `ChangePasswordModal` chrome-level; `resources/js/Contexts/DashboardContext.tsx` (delete) + `Providers.tsx`.

**Dependencies.** P2 (`?drill=` param-reading) **and** P3 (drills + URL auto-open) — the redirects must land on a cockpit that reads the param and opens the modal, or every bookmark resolves to a bare cockpit. **Merged/deprecated:** 6 overviews → 1 home + 5 redirects; `/home` + dead `DashboardContext` deleted (edit the `HeroUIProvider.tsx` mount per Part II.1#6); domain `dashboardHref`s repointed to live workspaces.

**Acceptance criteria.** Every old `/dashboard/*` bookmark resolves to the matching `?drill=`; nav shows 4 altitude sections; Cmd+K still works (href-dedup); RoleSwitcher persists across the app and URL-syncs; `ChangePasswordModal` present on all authenticated pages; `DashboardContext`/`/home` gone with zero broken references (`grep` clean); RouteSmokeTest green; canon + builds green.

**Effort / sequencing.** ~1 week. Nav restructure + redirects first (mechanical, high-value), then RoleSwitcher-to-chrome, then the two deletions last (after a `grep`-confirmed zero-consumer sweep + editing the `HeroUIProvider.tsx` mount). Layout convergence is the separate P4b.

**Chief risk.** Breaking nav/links or the Cmd+K palette via the SSOT restructure. Mitigate with a RouteSmokeTest pass and a `grep` for every repointed `dashboardHref` + every `DashboardContext` import before merge.

---

## P4b — Layout Convergence (5 Shells → 1) + ChangePasswordModal App-Wide

**Goal.** Collapse the five layout shells (`DashboardLayout`=32 pages, `RTDCPageLayout`=14, `AuthenticatedLayout`=12, `AnalyticsLayout`=5, `TransportLayout`=5) toward one unified app shell, and surface `ChangePasswordModal` at the shell level so it renders on **every** authenticated page — closing the 51-page gap where it is absent today. Split from P4a deliberately because it is the single highest-blast-radius IA step (Part II.1#5).

**Scope / workstreams.**
1. **Converge to one shell**, migrating each shell's distinctive chrome into composable shell slots so no page loses functionality: `RTDCPageLayout`'s title/subtitle wrapper, `TransportLayout`'s horizontal tab strip, `AnalyticsLayout`'s Flowbite/Nivo theme providers.
2. **Render `ChangePasswordModal` at shell level** (an *addition* the auth rules explicitly permit) so it is present app-wide and **never dismissable** — the server-side login redirect remains the initial gate; this closes the in-app gap on the 51 pages. The protected auth system is otherwise untouched (`.claude/rules/auth-system.md`).
3. **Per-shell migration with a `RouteSmokeTest` checkpoint after each shell** — convert and verify `RTDCPageLayout`'s 14 pages as one batch, `TransportLayout`'s 5 as another, etc., each gated green before the next, so a generic shell never silently strips RTDC/Transport-specific chrome.

**Files / areas touched.** `resources/js/Layouts/*` (converge); the unified shell + the shell-level `ChangePasswordModal` mount; per-shell page batches.

**Dependencies.** P4a (nav + redirects landed). **Merged/deprecated:** 5 shells → 1; `ChangePasswordModal` present on all authenticated pages.

**Acceptance criteria.** One shell renders every authenticated page; `ChangePasswordModal` present app-wide (never dismissable; auth rules intact); `RouteSmokeTest` green after **each** per-shell batch (≥82/82, no 5xx); no page loses chrome; canon + builds green.

**Effort / sequencing.** ~1.5 weeks. One shell batch at a time, smoke-gated, lowest-traffic shells first.

**Chief risk.** Silently stripping functionality from the 14 RTDC / 5 Transport pages when their bespoke chrome merges into a generic shell. The per-shell `RouteSmokeTest` checkpoint is mandatory, not optional.

---

## P5 — Analytics + Process-Improvement → the Study Altitude (A3)

**Goal.** Consolidate the two retrospective domains into one **STUDY** altitude, de-duplicate the surgical deep-dives, kill mock bundles shipped to prod, and converge the second copy of the threshold logic onto the StatusEngine so live (A0) and analytic (A3) altitudes tell **one story**.

**Scope / workstreams.**
1. **Merge Analytics + Process-Improvement under STUDY** in the nav (P4 placed the heading). Per-domain analytics silos (`/rtdc/analytics/*`, `/ed/analytics/*`, `/transport/analytics`) **re-home under Study** as that domain's deep-dive set, reachable both from Study and from a "view trends →" link inside the matching workspace. The split is **temporal, not topical**: "now" = Workspace, "over time / why / what-if" = Study.
2. **De-dup the 5 surgical deep-dives.** Today `/analytics/block-utilization` etc. appear verbatim under both Perioperative and Analytics. In 2.0 they live **only** under Study (canonical home); the Periop workspace reaches them via a "deep dive →" affordance, not a duplicated nav leaf.
3. **Kill mock bundles still shipped to prod.** Remove the static mock-data fallbacks from the surgical deep-dive dashboards (live services are already wired — `BlockUtilizationService`/`OrUtilizationService`/`PrimetimeUtilizationService`/`RoomRunningService`/turnover, 4,629 lines, live-controller-backed) and the 2,104 lines across 4 JS mock files + `useORUtilizationData.js` `hardCodedData`. Delete dead components with no route (`PatientFlowDashboard` 321 lines, `ProviderDashboard`, `ServiceDashboard`, `HistoricalTrends/TrendsOverview`) — or **activate `PatientFlowDashboard` as the process-map drill** per the digest recommendation.
4. **Converge the second threshold copy.** Refactor `app/Services/Analytics/OperationsAnalyticsService.php`'s `bandHighBad()` (~line 748, corrected path per Part II.1#6) to delegate to the P0 `StatusEngine` — this is the connective tissue so a tile that is `crit` at A0 is `crit` in its A3 trend. Resolve redundant SQL (house occupancy computed 3×, prime-time computed 4× with **different denominators**) by making CommandCenter/RTDC delegate to the single service; `PrimetimeUtilizationService` supersedes `PerioperativeMetricsService.primetimeUtilizationPct()`.
5. **Surface the PI crown jewel + bridge.** Promote `DashboardService::getBottleneckStats()` (the only genuinely-live PI signal — 5 bottleneck signals from `prod.*`) to feed Cockpit Quality/Flow signals (A0); keep the deep process map, PDSA CRUD, RCA kanban at A3. Surface the existing-but-hidden `PdsaCycle hasMany Ops::Intervention` → `InterventionMetric` → `OutcomeAttribution` attribution chain. Convert Analytics hub sections from URL-navigated pages to modal drills (single-screen vision). Fix PI canon violations as a **pinned P5 gate item** (not 'on contact', per Part II.1#8): `RootCause.jsx` 65+ raw palette, `VariantsViewPanel.jsx` hex, `ProcessFlowDiagram.jsx:79` `bg-gray-900`+`text-white` (white-on-white in light mode), PDSA pre-canon `healthcare-card`/`healthcare-button-primary` + `text-purple-*` Study badges. Because `check-ui-canon.sh` cannot catch these, P5 acceptance includes an **explicit light-mode visual pass**.

**Files / areas touched.** `navigationConfig.ts` (Study consolidation, surgical de-dup); `app/Services/OperationsAnalyticsService.php` (delegate to StatusEngine); delete/activate dead Analytics components; remove mock bundles + `useORUtilizationData.js` hardCodedData; `Components/Process/*` canon fixes; `DashboardService::getBottleneckStats()` → snapshot; PDSA pages canon migration.

**Dependencies.** P0 (StatusEngine), P4 (nav sections + workspace repointing). **Merged/deprecated:** Analytics + PI → one STUDY heading; surgical deep-dives → single canonical home; per-domain analytics → Study; mock bundles + 4 dead components removed; second `bandHighBad` copy retired.

**Acceptance criteria.** Surgical deep-dives appear exactly once in nav; no mock-data file shipped to prod (grep clean); house-occupancy/prime-time computed by one authority; `OperationsAnalyticsService` delegates to StatusEngine (A0/A3 status parity test); PI canon violations cleared; bottleneck signals visible on the cockpit; builds + canon green.

**Effort / sequencing.** ~2–2.5 weeks. Nav de-dup + StatusEngine convergence first (correctness), then mock-bundle removal, then canon fixes (mechanical), then bottleneck-to-snapshot wiring.

**Chief risk.** Hidden **denominator mismatches** surfacing when redundant metrics converge onto one authority (prime-time has 4 different denominators today). Mitigate by snapshotting current values before convergence and diffing; pick the spec-correct denominator and document the change.

---

## P6 — Eddy Loop + Alerting Fan-out

**Goal.** Make the cockpit **actionable**: derive alerts from the StatusEngine warn/crit set, drive the AlertTicker, fan out through the existing Push contract, and close the loop **alert → Eddy recommended action → human approval → audited execution** — reusing the already-merged governance plane. Ships `EDDY_ENABLED`-gated.

**Scope / workstreams.**
1. **AlertEngine (derived, never hand-set).** After `SnapshotBuilder` tags every MetricValue, collect warn/crit, **dedup by KPI key**, sort crit-first, render text from `kpi_definitions.alert_template`. Open/clear lifecycle reconciliation against `cockpit_alerts` (P1 table): open new, clear resolved, no-op held. **Add flap-damping / cooldown** (require K consecutive snapshots before open/clear) — without this, deriving alerts from *every* warn/crit reproduces the alarm-fatigue dashboard the canon forbids (Earned-Red).
2. **AlertTicker** (new component) reads open `cockpit_alerts` (crit-first, amber/coral only — the ticker is honest about reserving saturated color for exceptions); alert history = cleared rows.
3. **Fan-out reuses the Push contract.** Dispatch through the existing `App\Contracts\PushNotifier` (PHI-free `sendToUser`, bound to `LogPushNotifier`), gated by `EDDY_PUSH_ENABLED` (default false — inert until a real APNs/FCM sender replaces `LogPushNotifier`; **do not present mobile paging as live**). Add a small `AlertChannel` interface (or Laravel Notification channels) for Teams/paging keyed off `kpi_definitions` so only crit (+ opted-in warn) pages; tier-1 fan-out reserved for crit (precedent: `EddyApprovalNotifier::tierForRisk`).
4. **Close the Eddy loop on the existing FSM.** A warn/crit tile / AlertTicker entry opens `EddyDock` pre-seeded with the alert's `key` + offending `MetricValue` + matching `EddyActionService::CATALOG` action (`flag_barrier`/`propose_huddle_action`/`propose_transport_dispatch`/`propose_bed_placement`/`propose_surge_plan`). Extend CATALOG with an `alert_key` provenance field. `propose()` creates `Recommendation(draft)→OperationalAction(draft)→Approval(pending)` (Eddy holds `ops:draft`, never `ops:approve`); approval routes through `OperationalActionLifecycleService::decideApproval()` (the existing FSM with `lockForUpdate`). Eddy executes nothing — it drafts, a human decides, the FSM audits.
5. **Reconcile silo pages into the cockpit.** `ExecutiveBrief.tsx` → **executive-role cockpit panel** (narrative via the existing `executive_brief.compose` tool, slower cadence). `AgentInbox.tsx` → **alert/action DrillModal** over the same `OperationalActionLifecycleService` queue (standalone `/ops/agent-inbox` remains as a deep-link, no longer the only path).
6. **One tier↔state mapping table.** `critical-risk/T3→crit ◆ coral`, `high→warn ▲ amber`, `medium→watch ● sky`, `low→ok/normal` — so `EddyApprovalCard` and `AlertTicker` encode identical severity with identical shape+color; extend `tierForRisk` to emit cockpit `Status`.
7. **Real-time.** `app/Events/Cockpit/CockpitSnapshotUpdated.php` mirroring `Events/Rtdc/CensusUpdated.php` (`Channel('hospital.cockpit')`, `broadcastWith` = `{facility_id, generated_at}` reload-ping only, PHI-free). Frontend reuses `lib/echo.ts` + snapshot-on-reconnect. **Single-snapshot discipline:** both `SnapshotBuilder` and `EddyContextService` read the same `Cache::remember('cockpit.snapshot', 60, …)` key (wired in P1).

**Files / areas touched.** `app/Services/Cockpit/AlertEngine.php` (new); `cockpit_alerts` reconciliation + cooldown column (additive migration); `app/Jobs/RefreshCockpitSnapshot.php` (alert reconcile); `cockpit/AlertTicker.tsx` (new); reuse `App\Contracts\PushNotifier` + add Teams channel impl; extend `EddyActionService::CATALOG` (alert_key) + `EddyApprovalNotifier::tierForRisk`; `app/Events/Cockpit/CockpitSnapshotUpdated.php` (new); reconcile `Pages/Ops/ExecutiveBrief.tsx` + `AgentInbox.tsx` into cockpit panel/drill; reuse `EddyDock/EddyApprovalCard/eddyStore.ts` verbatim.

**Dependencies.** P1 (snapshot + `cockpit_alerts`), P2/P3 (cockpit tiles + DrillModal), P0 (StatusEngine). **Merged/deprecated:** ExecutiveBrief + AgentInbox pages reconcile into cockpit surfaces (routes kept as deep-links); four surfaces (panel/dock/inbox/portfolio) become one loop.

**Acceptance criteria.** Alerts derive solely from StatusEngine warn/crit; flap-damping prevents strobing (oscillation test); AlertTicker shows only amber/coral; a crit ED-boarding alert opens Eddy pre-seeded with `propose_surge_plan`; proposal lands pending → human approves via existing FSM → timestamps recorded; `EDDY_ENABLED=false` suppresses the dock; `BROADCAST_CONNECTION=reverb` set in the cutover; Pint + canon + builds green.

**Effort / sequencing.** ~2.5–3 weeks. AlertEngine + ticker first, then fan-out (Push reuse), then Eddy pre-seed wiring, then ExecutiveBrief/AgentInbox reconciliation, then the Reverb event + env cutover.

**Chief risk.** **Alarm fatigue** (the exact anti-pattern the canon forbids). Flap-damping/cooldown is mandatory, not optional; default tier-1 paging to crit only; warn surfaces in-app unless a KPI opts in.

---

## P7 — Swap Mocks for Real Sources, Per Domain (easiest first)

**Goal.** Replace the seeded MOCK domains and the synthetic sparklines with live history, one domain at a time, with **zero frontend/contract change** (the MetricValue contract is identical mock vs live).

**Scope / workstreams.** Per spec §9 step 6 order — **RTDC/census (already live) → Flow/EVS/Transport → Staffing → ED → Periop → Quality → Service Lines → Financial**:
1. **Flow** — precompute single end-to-end transport wait (`request→pickup` elapsed min) in `TransportOperationsService` (currently not precomputed; `measures()` is also full-table-unbounded — add a time bound); add EVS avg/p90 bed turnaround in `EvsOperationsService` (`started_at`/`completed_at` exist, no method computes it); compute discharge-before-noon % + lounge census from `flow_core.*` `stateProjection`.
2. **Staffing** — add the missing schema (OT hours, callouts, sitter/1:1, HPPD, agency-RN active) currently hardcoded statics in `resourceOptions()`; replace MOCK Staffing metrics.
3. **ED** — add NEDOCS input cols to `prod.ed_visits` (`vent_pts`, `longest_admit_wait_hrs`, `last_bed_time_hrs`), build `NedocsService`, wire EMS inbound from `prod.transport_requests`, surface diversion chip + longest-boarding clock.
4. **Periop** — extend `RoomStatusService` to emit `delay_min`+`suite_name`; build the PACU bay drill from `or_logs` joins (`pacuHoldsAt()` already computes the scalar); fix `CareJourneyCard.jsx:252` `border-healthcare-purple` (non-existent token → `healthcare-info`) + BlockUtilization `text-purple-*` + `RoomRunningService.php` embedded hex.
5. **Quality / Service Lines / Financial** — replace seeded stubs with real fact tables: `mv_hai_ledger`/`quality_events` (HAI SIR, sepsis bundle, hand-hygiene, falls, rapid responses), `mv_service_line_los` (O:E LOS, CMI), `mv_cost_center_productivity` (HPPD/UOS, OT%, cost/case) — heavy MTD aggregations as **materialized views refreshed CONCURRENTLY hourly**. Each MV **must carry a unique index** or `REFRESH MATERIALIZED VIEW CONCURRENTLY` fails at runtime (Postgres requirement, Part II.1#7).
6. **Retire synthetic sparklines** — once `ops.metric_values` (written each snapshot since P1) has enough history, swap the 27-of-30 crc32+sin/cos sparklines for real trend arrays.

**Files / areas touched.** `TransportOperationsService`, `EvsOperationsService`, `flow_core` services; staffing schema migration + `StaffingOperationsService`; `prod.ed_visits` NEDOCS cols + `NedocsService`; `RoomStatusService` + PACU drill; MV migrations (`mv_hai_ledger`/`mv_service_line_los`/`mv_cost_center_productivity`); periop token fixes; each `Domain/Cockpit/Metrics/*` class flips MOCK→live source.

**Dependencies.** P1 (Metrics adapters + contract), P2/P3 (rendered). **Merged/deprecated:** MOCK stubs and synthetic sparklines retired domain-by-domain; seed data remains for the demo facility (screenshots/acceptance).

**Acceptance criteria.** Each domain's MetricValues come from live sources with the contract unchanged (no frontend diff); MVs refresh CONCURRENTLY without lock storms; NEDOCS computes from real inputs; synthetic sparklines replaced as history accrues; periop token violations cleared; builds + canon green per domain swap.

**Effort / sequencing.** ~3–4 weeks, **incremental and independently shippable** — each metric class is independently testable; swap one domain, deploy, verify, next. Easiest-first ordering minimizes risk.

**Chief risk.** Per-metric **denominator/source drift** between mock and live (e.g., NEDOCS scaling, transport wait definition). Mitigate by validating each live metric against a known-good period and the seeded demo numbers before flipping the source.

---

## P8 — Wall-Display / Kiosk Hardening

**Goal.** Deliver the **24/7 wall-display MODE** — `/dashboard?display=wall` as a chromeless kiosk presentation of the *same* surface (never a separate build) — plus SSE reconnect, staleness safety, reduced-motion, and zoom robustness. Land the admin threshold editor so clinicians tune bands without a deploy.

**Scope / workstreams.**
1. **Wall MODE = presentation flag.** The layout shell reads `?display=wall`: drops interactive chrome (no nav mega-menu, no user menu, `onDrill` → no-op), enlarges to the wall scale tier, runs the 1Hz clock + AlertTicker, **locks dark theme** (a bright wall in a dark unit is hostile). One snapshot, one StatusEngine, one component tree. Desk mode keeps full interactivity + dual-theme toggle. Light-mode status hues already darken for white-bg contrast so the same StatusEngine output is WCAG-AA in both themes without branching.
2. **Density multiplier.** Root `data-density` attribute (`compact|normal|wall`) on `<html>` driving a font-size multiplier so the wall scales every `rem`-based size at once without touching components; persist via the existing preference round-trip. The sanctioned `text-[11px]` wall exception (documented P0) applies only inside cockpit primitives.
3. **Safety floor.** App-chrome-level `StaleDataBanner` (extract the aging/stale detection in `CommandCenterView`): solid amber `healthcare-warning` banner ("Data X min old — verify before acting") when `generatedAtIso` ages past threshold — never a silent stale screen. SSE reconnect via `/cockpit/stream`; 30–60s ETag/304 poll as final fallback; keep last-good on disconnect.
4. **Reduced-motion + zoom.** All transitions (MeterBar width, Sparkline, gauge `stroke-dashoffset`, modal enter/leave, AlertTicker blip) gated by `@media (prefers-reduced-motion: reduce)` (extend `app.css:165`); `tabular-nums` everywhere keeps digit columns from reflowing at any zoom (the reason IBM Plex Mono is rejected).
5. **Admin threshold editor.** Wire the P1 `PUT /cockpit/kpi-definitions/{key}` (audited edge edit, writes `ops.metric_definitions`) to an admin UI so bands tune without a deploy.
6. **Cutover hardening.** Confirm `BROADCAST_CONNECTION=reverb` in prod env (the silent-drop footgun); verify the snapshot job, MV refreshes, and SSE survive the Apache+fpm deploy (config-cache clear, opcache).

**Files / areas touched.** layout shell (`?display=wall` + `data-density`); `cockpit/StaleDataBanner.tsx` (extract from `CommandCenterView`); `app.css` reduced-motion block; SSE consumer for `/cockpit/stream`; admin threshold editor page + `PUT` controller; prod env + deploy.

**Dependencies.** P2 (overview), P6 (alerts/real-time), P1 (kpi-definitions endpoint). **Merged/deprecated:** nothing — wall MODE is a presentation of the existing surface.

**Acceptance criteria.** `/dashboard?display=wall` renders chromeless, dark-locked, wall-scaled, 1Hz clock + ticker, no-op drills; stale banner fires; SSE reconnect + poll fallback proven; reduced-motion honored; legible at 80–125% zoom without reflow; admin can edit an edge and see the band change without deploy; `BROADCAST_CONNECTION=reverb` verified live; canon + builds green.

**Effort / sequencing.** ~2 weeks. Wall MODE flag + density first, then stale/SSE safety, then reduced-motion/zoom, then admin editor, then cutover verification.

**Chief risk.** **Dead Reverb in prod** (`BROADCAST_CONNECTION=null`) — the AlertTicker/wall appear live in dev and are dead in prod. This is a hard cutover checklist item; verify with a real broadcast before declaring done.

---

## Cross-cutting guardrails (every phase)

- **Canon never regresses.** Every commit passes `npx tsc --noEmit` AND `npx vite build` (vite is stricter — catches UNRESOLVED_IMPORT tsc misses) AND `scripts/check-ui-canon.sh`; the impeccable hook stays on. Zero raw Tailwind palette, zero `font-bold`/700, zero `text-[Npx]` (except the one documented wall exception), zero `font-mono`, zero OKLCH, zero IBM Plex, zero new color tokens, zero glassmorphism. Two-System Rule held: blue/slate for ops + interaction; crimson/gold for brand/heritage + gold `:focus-visible` only.
- **Auth is untouchable.** No change to the temp-password + Resend flow, `ChangePasswordModal` (only *added* app-chrome-wide in P4), `must_change_password` redirect, or any protected controller (`.claude/rules/auth-system.md`). The `EddyDock` `must_change_password` suppression precedent is honored.
- **Additive + reversible.** All migrations use `SafeMigration` + `Schema::hasColumn` guards; no destructive down outside local; no prod data destroyed. Risky surfaces ship behind flags (`EDDY_ENABLED`, `EDDY_PUSH_ENABLED`, a cockpit-overview flag in P2, `?display=wall` MODE).
- **One snapshot, one engine.** `SnapshotBuilder` and `EddyContextService` read the same cached `cockpit.snapshot` key; `StatusEngine` is the sole threshold authority (both `bandHighBad` copies retired by P5).
- **Sweep discipline.** Prefer sequential commits on `main` over long-lived worktrees (per the worktree-sweep-regression protocol); a large refactor lands as many small auto-rebased commits, not one atomic merge.
- **Backend hygiene.** `vendor/bin/pint` (Docker) after every PHP edit; Form Requests for validation; eager-loading to avoid N+1; UPPERCASE enum cases.

## Effort summary

| Phase | Rough effort | Parallelizable with |
|---|---|---|
| P0 | 1.5–2 wk | — (floor) |
| P1 | 2–2.5 wk | P0 frontend primitives (backend/frontend split) |
| P2 | 2 wk | P1 MOCK domains |
| P3 | 2 wk | P2 panel work |
| P4a | 1 wk | P5 nav-adjacent work |
| P4b | 1.5 wk | — (smoke-gated per shell) |
| P5 | 2–2.5 wk | P4 |
| P6 | 2.5–3 wk | P7 (loop vs source-swap independent) |
| P7 | 3–4 wk | incremental after P1; a domain's live swap follows that domain's P2 gauge + P6 alert wiring (e.g. `ed.nedocs` after P2/P6) |
| P8 | 2 wk | after P2/P6 |

**Total ~19–23 weeks** for the full cohesion (P4 split into P4a+P4b adds ~0.5 wk), with the cockpit HOME visible and demo-lit after **P2 (~week 6)** and actionable after **P6**. The path is monotonic: every phase leaves `main` green, deployable, and strictly more cohesive than before.

---

# Part IX — Risks, Guardrails, Testing & Success Metrics

This is the closing band of the Zephyrus 2.0 plan. The preceding chapters established *what* we build — one Cockpit home, a single `StatusEngine`, the four-altitude IA, the shared `cockpit/` primitive library, the additive serving layer, and the Eddy loop. This chapter establishes the *fences and the finish line*: the risks that have historically bitten this codebase, the non-negotiable guardrails every contributor must honor, the testing strategy that proves the work (under a real constraint — Pest cannot be installed), and the acceptance criteria that measure the one thing 2.0 exists to deliver: **cohesion**.

The throughline is the same as the build chapters — **additive, reuse-first, canon-clean**. 2.0 succeeds only if, at the end, the app is *more* unified and *more* canon-compliant than it is today, with zero data lost and zero auth touched.

---

### 1. Risks & Mitigations

The risk register below is ordered by blast radius. Each risk cites the concrete failure mode (several have already happened on this codebase or its siblings) and a mitigation that is enforceable, not aspirational.

#### R1 — Design-canon regression (HIGH likelihood, HIGH impact)

The 2.0 surface area is enormous: a new primitive library, five new domain panels, a DrillModal that replaces nine reference modals plus the two existing `DrillDownModal`s, and refactors of `UnitHeatStrip`, `OkrScoreboard`, `KpiTile`, and the periop/RTDC/ED section components. Every one of those touches is an opportunity to re-introduce a raw Tailwind palette class, `font-bold`, `text-[Npx]`, `backdrop-blur`, or a `bg-gray-*` surface — undoing the 2026-06-26 remediation that cut raw-color violations from ~4261 to ~600.

The central tension makes this worse: the cockpit reference spec *literally ships* the forbidden tokens (IBM Plex, OKLCH, a fifth green, cyan, 9px text, `radial-gradient`, `backdrop-blur`). A contributor copying the prototype verbatim — exactly what `Hospital Operations Cockpit.dc.html` invites — silently violates the canon in a way that "looks right" against the screenshots.

**Mitigations:**
- **Keep the impeccable design hook ON for the entire 2.0 build.** It flags violations interactively on every edit; never disable it "to move faster."
- **`scripts/check-ui-canon.sh` is a required gate** in CI and pre-commit. It is exact and narrow — it fails (exit 1) on `font-bold`/`font-extrabold` and `text-[Npx]`/`text-[Nrem]` (both excluding `/Design/`), and warns (non-fatal) on arbitrary line-heights. The broader color/surface ban is the impeccable hook's job; the script is the unambiguous floor. **Do not weaken either rule to land cockpit work.**
- **The reconciliation is the law, the reference is a sketch.** Every reference token maps to a canon token *before* code is written: IBM Plex → Figtree + `tabular-nums`; OKLCH → `STATUS_VAR` CSS-vars; 5th green "ok" → teal (`--success`, rationed); cyan → sky (`--info`) / blue (`--primary`); `radial-gradient` → the solid `#0F172A → #1E293B` tonal step; `backdrop-blur` → the solid `.modal-backdrop`/`.modal-surface`. **No reference token enters the codebase.** SVG fills/strokes are the only place a non-Tailwind value is allowed, and they must read `STATUS_VAR[...]`, never raw hex (the data-viz exemption).
- **The 9px wall micro-caption is the ONE sanctioned new exception**, and it must be added to the CLAUDE.md exceptions list (beside Auth, Design, and the categorical-chart annotations) as `text-[11px]` *scoped to cockpit primitives in wall mode only*. Document it so the canon check is annotated, never silently broken. Desk mode keeps the `text-xs` (12px) floor.

##### R1a — The tailwind duplicate-key footgun (KNOWN, must not be re-tripped)

`tailwind.config.js` defines `surface` and `background` keys **twice** in the `healthcare` palette (verified at lines 29/43 and 87/92 — the second block wins). The first `surface.hover` was silently shadowed until a comment was added (line 97-99) forcing `surface.hover` to live in the *winning* block, otherwise `bg-healthcare-surface-hover` resolves to nothing. **Any new healthcare-* token added for 2.0 (e.g. a domain-swatch alias) must be added to the WINNING block.** Adding it to the first, shadowed block produces a class that compiles to nothing — a silent no-op that will pass `tsc`, pass `vite build`, and look broken only at runtime.

##### R1b — The `bg-gray-900 → surface` white-on-white footgun (KNOWN)

The UI-consistency remediation caught a class of silent regression: a `bg-gray-900` element carrying `text-white`, when mechanically swapped to a `healthcare-surface` token, becomes **white text on a light-mode surface** — invisible in light theme, while looking fine in the dark-default dev view. The periop digest flags two live instances of this exact pattern waiting to be hit (`ProcessFlowDiagram.jsx:79` `bg-gray-900` inside a node; the `RoomRunningService.php` raw-hex dataSeries). **Rule: when converting any `bg-gray-900`/`bg-black` surface, verify the paired text token has a `dark:` pair and is legible in BOTH themes.** White text is only ever allowed on a *solid colored fill*, never on a surface. Every refactor must be eyeballed in light mode, not just dark.

#### R2 — Worktree-sweep regression (HIGH impact, has happened 3×)

The 2.0 build is precisely the kind of work that has clobbered `main` on the sibling Parthenon repo three times (2026-04-11): a large, mechanical, codebase-wide sweep (here: the primitive refactor, the token reconciliation across periop/RTDC/ED, the modal replacement) executed in a long-lived worktree that branched off an old commit, then merged back as if it still only knew that commit — re-adding deleted files and restoring pre-refactor content. With concurrent agents and a live `main`, the cockpit refactor is a textbook trigger.

**Mitigations (the Worktree Agent Protocol, mandatory for every sweep):**
- **Prefer sequential commits on `main` over a long-lived worktree.** The primitive refactor should land as a sequence of small commits (extract Sparkline; extract MeterBar; generalize Gauge; add StatusChip/CensusChip/Panel/DataTable; build DrillModal; migrate section components) — each auto-rebasing — *not* a single 6-hour atomic merge. A 200-file sweep is 10×20-file commits over an hour, not one worktree.
- **If a worktree is unavoidable**, before returning it MUST: `git fetch origin`; `git rebase origin/main` (resolve conflicts FILE-BY-FILE, never `-X ours`); run `git log origin/main..HEAD --diff-filter=D --name-only` and list EVERY deletion in the summary; run BOTH `npx tsc --noEmit` AND `npx vite build`; report commit count, files touched, files deleted, merge-base age.
- **`git branch --show-current` before every commit.** Concurrent sessions and subagents have silently switched the tree to `main` mid-task before. Background subagents cannot Write/Edit (denied at the harness layer) — dispatch them read-only and do all edits in the foreground loop.

#### R3 — Breaking the protected authentication system (CATASTROPHIC, hard rule)

`.claude/rules/auth-system.md` lists the production-deployed auth surface that MUST NOT change: the temp-password + Resend registration flow, the forced `must_change_password` redirect, the non-dismissable `ChangePasswordModal`, the `admin@acumenus.net` superuser, the `noreply@acumenus.net` sender, and email-enumeration prevention. The IA work in 2.0 *touches the navigation and layout shell directly* — and the discovery digest surfaced a real latent hole: **`ChangePasswordModal` is absent from 51 pages** (every `DashboardLayout`, `RTDCPageLayout`, and `TransportLayout` page). The server-side login redirect is the only gate on those routes today.

**Mitigations:**
- **The unified app shell is the place to FIX this, not regress it.** When 2.0 merges the five layouts into one shell, the `ChangePasswordModal` must render at the shell level so it is present on *every* authenticated page — closing the 51-page hole as a side-effect of unification. This is an *addition* the auth rules explicitly permit ("additions only").
- **Auth pages, `Components/Auth/*`, `ChangePasswordModal.jsx`, and `GuestLayout` are off-limits** to the cockpit refactor and the canon sweep. They are a sanctioned exception (deliberate indigo/blue/cyan `auth.css`) — do not recolor, do not "fix" the glassmorphism on `ChangePasswordModal.jsx`, do not absorb them into the cockpit primitive library.
- **No route deprecation may touch an auth route.** The `RouteSmokeTest` skip-list (`logout`, `login`, `register`, `password.`, `verification.`) must remain intact.

#### R4 — Data destruction / non-destructive prod violation (CATASTROPHIC, hard rule)

Data is production and seeded (Summit Regional). The plan is explicitly additive — but the build adds migrations (`ops.metric_definitions` extension, `cockpit_snapshots`, `cockpit_alerts`, NEDOCS columns, the mock-fact stubs, materialized views) and re-seeds for demo. The historical incidents are unambiguous: `migrate --force` wiped an app schema (2026-03-30); a full deploy silently skips migrations (must use `--db`).

**Mitigations:**
- **Every migration uses the `SafeMigration` trait** and guards each `addColumn` with `Schema::hasColumn` — idempotent, re-runnable, prod-safe. No `down()` drop outside local. New tables are additive; no existing table is altered destructively.
- **Never `migrate --force` on a refresh.** Prod refresh follows the established runbook: `php artisan zephyrus:demo-seed` then `db:seed CommandCenterDemoSeeder`. Re-seeding writes demo rows; it does not drop live tables.
- **The five deprecated `/dashboard/*` overview routes are redirected, never deleted** — every bookmark survives. Only `/home` (an unreachable hardcoded mock with zero inbound links) and `DashboardContext.tsx` (a ~460-line nav duplicate with zero consumers) are deleted outright, and both are verified-dead code, not data.
- **Soft deletes by default; explicit confirmation before any destructive op.** The `omop`-style read-only discipline does not apply here, but the spirit does: additive/reversible only.

#### R5 — Brownfield breakage of the 137 pages (MEDIUM likelihood, HIGH impact)

The refactor reaches into the most-used pages in the app. Repointing eight domains' `dashboardHref`, merging five layouts, deleting `DashboardContext`, deprecating five routes, and re-homing the per-domain analytics silos can break navigation, layout, or render on pages far from the cockpit.

**Mitigations:**
- **`RouteSmokeTest` is the brownfield safety net** (see §3). It currently passes 82/82 — it must stay green after *every* IA change. Any route deprecation, `dashboardHref` repoint, or layout merge that drops a page to a 5xx is caught here before merge.
- **Repoint, don't rip.** Workspaces keep their existing live URLs (`/rtdc/*`, `/ed/operations/*`, `/operations/*`, `/transport/*`, `/staffing`, `/flow`); only the dead overview `dashboardHref`s move to point at the live workspace. The surgical deep-dives are de-duplicated to a single canonical home under Study, but the URLs themselves persist.
- **`navigationConfig.ts` stays the single SSOT** and has its own contract test (`tests/js/config/navigationConfig.test.ts`). Restructuring 8 flat domains into 4 altitude sections must keep that test green and keep `flattenNavigation()` (which feeds Cmd+K) href-deduped.

#### R6 — Alarm fatigue / over-coloring — the Earned-Red failure (MEDIUM likelihood, HIGH impact)

This is the risk that defeats the *entire point* of the cockpit. The AlertEngine derives an alert from *every* `warn`/`crit` MetricValue. Naively, a metric oscillating around an edge will strobe the AlertTicker every minute; a calm screen littered with teal "ok" confirmations becomes the alarm-fatigue dashboard the canon forbids. ISA-101 and the canon's Earned-Red rule are the same rule, and 2.0 must honor it at the engine level, not just the pixel level.

**Mitigations:**
- **Grey is the baseline; color is information.** The ISA-101 render discipline (normal/watch values render near-white; color appears on the value only for `warn`/`crit`, and `ok` only in its rationed confirmation slots) is enforced *in the primitives* via `statusStyle().valuePrimary` — one place, every tile.
- **"ok" (teal) is rationed.** It appears only where confirming on-target genuinely helps (OKR scorecards, days-since counters, a met hand-hygiene target). A metric merely in-band is `normal`/grey, never teal. Teal means "we hit it," not "this exists."
- **Flap-damping in the AlertEngine.** Add a per-key cooldown column: a status must hold for K consecutive snapshots before the engine opens or clears a `cockpit_alerts` row. The open/clear reconciliation already dedups by KPI key (no re-paging every minute); flap-damping stops edge-oscillation strobing. **Without this, deriving alerts from every warn/crit reproduces exactly the dashboard the canon forbids.**
- **Predictions surface as `watch` (sky), never `crit`.** A forecast is uncertain; rendering a projected midnight deficit as coral-red manufactures false urgency and erodes trust. The forecast becomes a real `warn`/`crit` alert only when the *actual* metric crosses its edge. The AlertTicker shows only `warn`/`crit` (amber/coral) — it is honest about reserving saturated color for exceptions.
- **Tier-1 fan-out reserved for crit.** Push/Teams paging fires only on crit (and KPI-opted-in warn); warn alerts surface in-app only. `EddyApprovalNotifier::tierForRisk` is the existing precedent.

#### R7 — Scope creep / over-build (MEDIUM likelihood, MEDIUM impact)

The plan spans backend serving layer, primitives, IA, intelligence, and the Eddy loop. The temptation is to build the mock domains (Quality, Service Lines, Financial) as fully live, to build a real LLM Eddy brain, or to net-new where reuse suffices.

**Mitigations:**
- **Reuse over net-new is the law.** The reconciliation chapters establish that ~60% of the cockpit already exists (`CommandCenter.tsx`, the Zod contract, the drilldown API, the RoleSwitcher, the governance control plane). The net-new surface is small: `StatusEngine`, `AlertEngine`, `cockpit_alerts` wiring, the AlertTicker, four missing primitives, a Teams notifier, and an approver-routing policy. Everything else is reconciliation of shipped assets.
- **Mock domains return demo numbers behind the IDENTICAL MetricValue contract.** Quality/Service/Financial ship as seeded stubs so the wall lights up for screenshots and acceptance tests; swapping mock→live later requires zero frontend or contract change. Do not block 2.0 on building fact tables that don't exist yet.
- **Eddy stays `EDDY_ENABLED`-gated and ships disabled.** The Python inference brain is *out of scope* for 2.0's cockpit work — the loop is wired to the existing `RulesOnlyAgentRunner` seam. Do not present mobile paging or a frontier LLM as live; `EDDY_PUSH_ENABLED` defaults false and `LogPushNotifier` is the bound implementation.
- **No new color, no new font, no new surface primitive.** The strongest anti-scope-creep fence is the canon itself: if a feature "needs" a fifth status color or a second surface, the feature is wrong, not the canon.

#### R8 — The Pest-unavailable testing constraint (MEDIUM impact, structural)

Pest cannot be installed in this environment: PHP 8.5 is ahead of several transitive deps, and composer's block-insecure audit refuses the advisory-flagged `laravel/framework` 11.x that `pest-plugin-laravel` re-resolves. `phpunit.xml` already excludes four Pest-syntax files (`ProcessAnalysisServiceTest`, `DashboardServiceTest`, `ProcessAnalysisTest`, `AuthenticationFlowTest`) so the PHPUnit runner loads at all. **New tests must be written in PHPUnit class syntax, not Pest `test()/it()/beforeEach()`** — or they will be silently excluded and provide false coverage. See §3.

#### R9 — Broadcast silently dead in prod (LOW likelihood once known, HIGH impact)

`config/broadcasting.php` defaults `BROADCAST_CONNECTION` to `'null'` — every broadcast (`CensusUpdated`, the new `CockpitSnapshotUpdated`, any `hospital.cockpit` alert) is **silently dropped in prod**. The AlertTicker and live snapshot refresh will work perfectly in dev and be dead on the wall display. **Mitigation: setting `BROADCAST_CONNECTION=reverb` is a mandatory line item in the 2.0 cutover runbook**, with an SSE (`/cockpit/stream`) fallback and a 30–60s ETag/304 poll as the final fallback, and a `StaleDataBanner` that flags "stale — last sync HH:MM:SS" at 2× the expected interval so a dead channel never silently shows stale numbers.

---

### 2. Guardrails & Non-Negotiables

These are not graded on a curve. A PR that violates any of them does not merge.

#### 2.1 The Design Canon (enforced by hook + `scripts/check-ui-canon.sh`)
- **Typography:** Figtree only via `font-sans`. Weights **400/500/600 only** — no `font-bold`/`font-extrabold` (700/800 are not loaded → faux-bold). Tailwind size scale only — no `text-[Npx]`. Metrics/IDs use `tabular-nums`, never `font-mono` (the mono face is deleted). The reference's 700 headers downgrade to `font-semibold`; the IBM Plex Mono digit-alignment goal is met by `tabular-nums` on Figtree.
- **Surfaces:** ONE primitive — `Components/ui/Surface.tsx` (Card/Panel delegate to it). No `bg-white`/`bg-gray-*` surfaces, no glassmorphism (`backdrop-blur`). Resting panels = `shadow-sm`; only floating elements (modals/dropdowns/tooltips) = `shadow-lg`.
- **Color:** `healthcare-*` tokens with a `dark:` pair, always. **No raw Tailwind palette** in `resources/js`. SVG data-viz reads `STATUS_VAR[...]`, never raw hex.
- **Status vocabulary = FOUR colors + grey:** `critical` (coral) / `warning` (amber) / `success` (teal) / `info` (sky) + `neutral` (grey). The five logical ISA-101 states collapse onto these: normal→neutral-grey `–`, ok→teal `●` (rationed), watch→sky `●`, warn→amber `▲`, crit→coral `◆`. **No OKLCH, no fifth green, no cyan, no new CSS-var.**
- **Status never by color alone:** every status carries a SHAPE glyph (`– ● ▲ ◆`) in addition to color (WCAG 1.4.1). The `StatusChip` is the load-bearing accessibility primitive.
- **Spacing:** 4px grid; one gutter owner (`PageContentLayout` `p-4`). No double gutter.
- **Dark-default, dual-theme.** Wall mode forces dark (a bright wall in a dark unit is hostile); desk mode honors the toggle. The StatusEngine emits a *logical state*; the theme layer picks the hue, so light-mode contrast (`--success` `#059669`, `--critical` `#DC2626`, etc.) is automatic.
- **Sanctioned exceptions (do not expand):** the 7 Auth pages + `Components/Auth/*` + `GuestLayout`; `Pages/Design/*`; categorical chart palettes / Nivo schemes / reactflow handle ports / dynamic `bg-${…}`; the RTDC status CSS-vars. **NEW for 2.0:** `text-[11px]` micro-captions inside cockpit primitives in wall mode only.

#### 2.2 The Two-System Rule
The **blue/slate `healthcare-*` palette governs all operational surfaces and interaction.** Crimson `#9B1B30` + gold `#C9A227` is **Acumenus brand/heritage + the focus layer ONLY** — crimson appears only in the wordmark/CommandBar identity, never as a panel, dashboard primary, or status color; gold is the `:focus-visible` ring on every cockpit control (including wall mode). Cyan in the reference maps to sky (`--info`) for non-interactive marks and `healthcare-primary` blue for the one interactive accent. **Watch-blue (sky `#60A5FA`, status only) and interactive-blue (`healthcare-primary`, controls only) never share a slot** — a status dot is never a control; a control is never a 7px dot.

#### 2.3 The Protected Auth System (`.claude/rules/auth-system.md`)
Temp-password + Resend flow, forced `must_change_password` redirect, non-dismissable `ChangePasswordModal`, `admin@acumenus.net` superuser, `noreply@acumenus.net` sender, email-enumeration prevention — **additions only, no architectural change.** The unified shell renders `ChangePasswordModal` app-wide (closing the 51-page gap) but never makes it dismissable, never adds password fields to Register, never bypasses the redirect.

#### 2.4 Data Protection
Production data, additive/reversible only. `SafeMigration` + `Schema::hasColumn` guards on every migration. Never `migrate --force`. Deprecate-via-redirect, never hard-delete a route with inbound links. Prod refresh via `zephyrus:demo-seed` then `db:seed CommandCenterDemoSeeder`.

#### 2.5 One Source of Truth, Everywhere
- **One nav SSOT:** `navigationConfig.ts`. Delete `DashboardContext.tsx`.
- **One threshold authority:** `StatusEngine` (extracts the duplicated `bandHighBad`/`bandLowBad` and ~117 ad-hoc status strings).
- **One snapshot:** `cockpit_snapshots`, read by the Cockpit, the drills, AND `EddyContextService` (today the capacity snapshot SQL runs twice — both must read the same cached row, or Eddy cites stale numbers against the alert that spawned it).
- **One surface primitive, one status helper, one DrillModal.**

---

### 3. Testing Strategy

Testing must prove three things: (a) nothing broke (brownfield safety), (b) the new logic is correct (StatusEngine, AlertEngine, the contract), and (c) the result matches the vision (visual fidelity, accessibility). All of it works *under the Pest constraint* — PHPUnit class syntax only on the backend, Vitest on the frontend.

#### 3.1 Brownfield safety — `RouteSmokeTest` (the non-negotiable gate)
`tests/Feature/RouteSmokeTest.php` iterates every GET route, acts as the seeded user, and asserts no route returns ≥500 (currently **82/82** GET routes pass, per the memory note). It auto-skips auth routes, parameterized routes, `api/*`, and `design/*`. **This is the single most important regression gate for the IA work.** Every layout merge, `dashboardHref` repoint, route deprecation, and `DashboardContext` deletion must keep it green. When the five `/dashboard/*` routes become redirects, the test still asserts they don't 5xx (a 301/302 is fine). **Acceptance: RouteSmokeTest passes ≥82/82 after every IA change** (the count may rise as new cockpit routes are added; it must never fall, and no route may regress to 5xx).

#### 3.2 Build verification — `tsc` AND `vite build` (both, every time)
The canon is explicit and proven: **`npx vite build` is STRICTER than `npx tsc --noEmit`** — vite catches `UNRESOLVED_IMPORT` and bundler-level errors tsc misses. Both run before any commit. The primitive extraction (Sparkline/MeterBar out of `KpiTile`), the `Gauge → RadialGauge` generalization, and the DrillModal-on-Radix work are exactly the kind of import-graph churn where vite-only failures hide. **Acceptance: clean `npx tsc --noEmit` AND clean `npx vite build` on every commit, plus `scripts/check-ui-canon.sh` exit 0.**

#### 3.3 StatusEngine unit tests (new, PHPUnit class syntax)
The `StatusEngine` is the connective tissue of the whole plan — it must be the most thoroughly tested new class. Mirror the existing derived-value style in `tests/Feature/CommandCenterLiveDataTest.php` (self-contained fixtures, insert only the rows the assertion needs, call, assert the exact value). Cover:
- The §4 comparator for both directions (`direction='down'`: `v >= edge`; `direction='up'`: `v <= edge`), crit-edge-first ordering, then warn, then watch.
- The new `watch` band derivation (within `metadata.watch_band_pct`, default 10%, of the warn edge) — the band the spec under-specifies.
- The `ok` rationing (only fires where confirmation is sanctioned).
- The `canon()` reconciliation map: `Normal→neutral`, `Ok→success`, `Watch→info`, `Warn→warning`, `Crit→critical` — assert the engine emits logical names and the map is the single bridge.
- Backward-compat: refactored `CommandCenterDataService::bandHighBad/bandLowBad` delegate to `StatusEngine` and return the same status the existing `CommandCenterLiveDataTest` asserts (e.g. occupancy 90 → `warning`, the test already pins this).
- **Mirror the client `cockpit/statusStyle.ts` against the same cases** in a Vitest spec so the server engine and its client mirror never diverge — a single table-driven test in both languages over the same fixtures.

#### 3.4 AlertEngine lifecycle tests (new, PHPUnit)
- open: a KPI in warn/crit with no open `cockpit_alerts` row → INSERT.
- clear: a KPI back to normal/ok/watch with an open row → set `cleared_at`.
- dedup: a KPI still warn/crit with an open row → no-op (does not re-page).
- **flap-damping: a KPI oscillating around an edge does NOT open/clear until it holds K consecutive snapshots** — this is the alarm-fatigue guard and must be tested explicitly.
- crit-first ordering and `alert_template` rendering.

#### 3.5 Snapshot-contract Zod tests (extend existing, Vitest)
The contract is already guarded: `tests/js/commandCenter/contract.test.tsx` parses a full valid payload and `states.test.ts` proves `safeParseCommandCenterData` returns `ok:false` with a path-tagged message (e.g. `/unitCensus/`) instead of throwing on malformed input. **Extend, don't replace:**
- Add `types/cockpit.ts` schemas (StatusChip/Cell grammar/DrillModal payload/the 8-domain snapshot) with the same valid-payload + each-field-malformed-rejects pattern.
- Add the new domains (Staffing, Quality, Service Lines, Financial, Transport) and the 7 OKR cards to the fixture.
- Assert the §6.4 Cell grammar union parses each variant (`string|number | {v,strong,dim,status} | {bar} | {chip} | {tag}`).
- **Round-trip test: a PHP-built snapshot (from `SnapshotBuilder`, dumped to JSON in a feature test) parses cleanly under the frontend Zod schema.** This is the PHP↔React contract proof — the single most valuable cross-stack test, catching any drift between `MetricValue` factory output and the Zod shape.
- Keep `CommandCenterSchemaTest` (asserts `prod.ed_visits`, `prod.gmlos_references` columns exist) green and add schema-existence assertions for the new tables (`cockpit_snapshots`, `cockpit_alerts`, the extended `ops.metric_definitions` columns, NEDOCS columns).

#### 3.6 Component & store tests (extend existing, Vitest)
The CommandCenter suite is already substantial: `KpiTile.test.tsx`, `OkrScoreboard.test.tsx`, `UnitHeatStrip.test.tsx`, `StrainIndex.test.tsx`, `Band.test.tsx`, `RoleSwitcher.test.tsx`, `ForecastCurve.test.tsx`, `HeroWall.test.tsx`, `CommandCenterView.test.tsx`, `CommandCenterError.test.tsx`, `commandCenterStore.test.ts`. As each primitive is extracted/promoted to `cockpit/`, **port its test alongside it** and add:
- `StatusChip`: asserts `role="img"` + `aria-label` = the human label, and a *different glyph per tier* (the non-color-reliance proof).
- `DataTable`: each Cell variant renders; `<caption className="sr-only">`; `scope="col"`; `bar`/`chip`/`tag` cells expose `aria-label` with the numeric/state value.
- `DrillModal`: ESC closes, backdrop click closes, focus-trap, focus restores to trigger; solid scrim (no `backdrop-blur`); `aria-modal`.
- `statusStyle`: the `valuePrimary` rule (normal/watch → near-white; ok/warn/crit → status color).
- `navigationConfig.test.ts`: stays green through the 4-section restructure; `flattenNavigation()` stays href-deduped.

#### 3.7 Visual fidelity vs the reference screenshots
The reference `screenshots/` set is the *visual* acceptance bar — but only for **layout, density, and information architecture**, never for tokens. The discipline: compare the built Cockpit and each of the nine drill modals against the corresponding reference screenshot for *structure* (CommandBar → CensusStrip → AlertTicker → OKR Scorecard → 8-domain grid → legend; the domain-panel body patterns; the 6-tile KPI strip + 1–3 DataTable drill structure) — and explicitly confirm the *rendering* uses Figtree/`tabular-nums`/the 4-color palette, **not** the reference's IBM Plex/OKLCH. A side-by-side that matches structure while differing in tokens is a *pass*; matching tokens is a *fail*. Capture both desk mode and `?display=wall` (chromeless, dark-locked, 1Hz clock, AlertTicker) against the relevant screenshots.

#### 3.8 Accessibility checks (WCAG 2.2 AA, pragmatic)
- **Non-color-reliance (1.4.1):** every status carries a shape glyph; `StatusChip` is `role="img"` with a per-tier `aria-label`. Verified by component test (§3.6) and a manual grayscale pass over the Cockpit (must remain legible with color stripped).
- **Contrast (1.4.3):** the StatusEngine emits logical states; light-mode hues darken automatically for white-bg contrast. Spot-check the watch-sky-on-slate and the rationed-teal cases in both themes.
- **Focus visible (2.4.7):** gold `:focus-visible` on every interactive element — Panel drill button, Tile `Link`, modal close, RoleSwitcher tabs.
- **Reduced motion (2.3.3):** MeterBar width, Sparkline, gauge `stroke-dashoffset`, modal enter/leave, and the AlertTicker `blip`/marquee all gated by `@media (prefers-reduced-motion: reduce)` (extend the existing block).
- **Keyboard:** DrillModal is fully keyboard-operable (Radix ships ESC, focus-trap, restore); the four-altitude descent (A0 → A2 drill → A1 → A3) is reachable without a pointer.
- **Existing Playwright e2e** (`tests/e2e/auth.spec.ts`, `navigation.spec.ts`, `rtdc-huddle.spec.ts`) is the critical-flow harness — extend `navigation.spec.ts` to exercise the new four-section nav and a cockpit-panel → DrillModal flow.

#### 3.9 Coverage & order
- New backend logic (StatusEngine, AlertEngine, the Metrics adapters, SnapshotBuilder, DrillBuilder) targets the global 80% bar — **PHPUnit class syntax only** (Pest files are excluded and provide false coverage).
- TDD on the StatusEngine specifically: write the comparator/`watch`/`ok`/`canon()` tests first (RED), implement to green, then refactor `CommandCenterDataService` to delegate (and confirm `CommandCenterLiveDataTest` still passes — the regression proof that delegation didn't change behavior).
- Run `vendor/bin/pint` (Docker) after every PHP edit per project convention.

---

### 4. Acceptance Criteria & Success Metrics

2.0 is not "done" because features exist; it is done when the app is *demonstrably more cohesive*. These criteria are binary where possible and measurable where not. **The metrics deliberately measure cohesion** — the disjointedness that motivated 2.0.

#### 4.1 Cohesion metrics (the headline — these define success)

| # | Metric | Today (baseline) | 2.0 target | How measured |
|---|---|---|---|---|
| C1 | **One home** | 6 overview dashboards + 1 orphan (`/dashboard`, `/dashboard/rtdc`, `/dashboard/perioperative`, `/dashboard/emergency`, `/dashboard/improvement`, `/dashboard/transport`, `/home`) | **1** house-wide Cockpit home at `/dashboard` | Count of routes rendering an overview "dashboard" page; the other 5 are redirects, `/home` is deleted |
| C2 | **Dashboards collapsed** | — | **5 overview routes deprecated to redirects; 1 dead route deleted** | `routes/web.php` diff: 5×`Redirect::route(...→ ?drill=)`, `/home` removed |
| C3 | **One nav source** | `navigationConfig.ts` (live) + `DashboardContext.tsx` (~460-line dead duplicate) | **1** SSOT (`navigationConfig.ts`), `DashboardContext.tsx` deleted | File deletion + zero remaining imports; `navigationConfig.test.ts` green |
| C4 | **One design language passing the canon check** | ~600 raw-color occurrences; canon check green on the 2 hard rules | **canon check green; raw-color count does NOT rise (target: net decrease)** as periop/RTDC/ED/PI violations are fixed on contact | `scripts/check-ui-canon.sh` exit 0 + the impeccable hook + a `grep -i` raw-palette count before/after |
| C5 | **One threshold authority** | `bandHighBad`/`bandLowBad` duplicated in ≥2 services + ~117 ad-hoc status strings | **1** `StatusEngine`; both legacy methods delegate to it | grep: zero independent `bandHighBad`/`bandLowBad` bodies; all status flows through `StatusEngine` |
| C6 | **Snapshot-driven overview** | `/dashboard` fires 15+ synchronous DB queries per load, no cache | **1** server-computed `cockpit_snapshots` row per facility; `/snapshot` is a cache lookup, not a query storm | Query count on `/cockpit/snapshot` (≤1 for the cached read); `RefreshCockpitSnapshot` primes `everyMinute()->withoutOverlapping()` |
| C7 | **One nav tree, four altitudes** | 8 flat sibling domains | **4** altitude sections (Cockpit / Workspaces / Study / Admin) | `navigationConfig.ts` structure; surgical deep-dives appear ONCE (de-duped from Periop+Analytics) |
| C8 | **One layout shell** | 5 layouts (DashboardLayout, RTDCPageLayout, AuthenticatedLayout, AnalyticsLayout, TransportLayout); `ChangePasswordModal` absent from 51 pages | **1** unified shell rendering `ChangePasswordModal` app-wide | Layout count; `ChangePasswordModal` present on all authenticated routes |
| C9 | **One status snapshot shared with Eddy** | capacity-snapshot SQL runs twice (Cockpit + `EddyContextService`) | **1** cached `cockpit_snapshots` row read by both | `EddyContextService` reads the same cache key as `SnapshotBuilder` |

#### 4.2 Glance-test latency (the situational-awareness bar)
The cockpit north star is a **3-second read** ("is the house OK right now?"). Acceptance:
- **A0 never scrolls** — the entire house overview fits one screen at desk and wall resolutions (1600px content max; wall density tier). Verified visually against the reference layout and on a real wall display.
- **Snapshot freshness ≤ the per-metric `refresh_secs`** (default 300s; fast metrics 60–120s). The `StaleDataBanner` fires at 2× the expected interval and never silently shows stale numbers.
- **Time-to-first-meaningful-paint of `/dashboard`** measurably improves versus today's 15+-query synchronous load, because `/snapshot` is a cached read. Capture before/after.
- **A calm screen reads near-monochrome** (slate/grey/near-white with sky domain marks); saturated color appears only where a `warn`/`crit` (or rationed `ok`) lives. This is the Earned-Red acceptance test — a manual review confirming a quiet house shows little-to-no amber/coral.

#### 4.3 Functional acceptance (the build is complete when)
- The Cockpit renders CommandBar (facility chip + capacity-status pill + 1Hz clock) → 8-chip CensusStrip → AlertTicker (crit-first marquee, `blip` reduced-motion-gated) → 7-card OKR Scorecard → all 8 domain panels → legend/footer.
- Every panel header and every OKR card is a drill-entry point (`aria-haspopup="dialog"`, gold focus ring, `⤢` glyph) opening the unified `DrillModal` (KPI strip + 1–3 DataTable sections) over the Cockpit — replacing the 9 reference modals and the 2 existing `DrillDownModal`s; the two `Math.random()` sparkline modals are retired.
- `?display=wall` renders the chromeless, dark-locked kiosk presentation of the *same* page (no separate build) — one snapshot, one StatusEngine, one component tree.
- The RoleSwitcher lives in persistent app chrome, `?role=`-synced, re-ranking bands and swapping HeroWall↔OKR without changing route.
- The five deprecated overview routes redirect to `?drill={domain}`; every existing bookmark resolves.
- A warn/crit tile can hand off to Eddy pre-seeded with the alert key + offending MetricValue, riding the existing `ops.agent_*` control plane through the human approval gate (`EDDY_ENABLED`-gated, ship-disabled).
- `BROADCAST_CONNECTION=reverb` is set in the cutover; SSE + ETag/304 poll fallbacks are wired.

#### 4.4 Non-regression acceptance (nothing broke)
- `RouteSmokeTest` ≥82/82, zero 5xx.
- Clean `npx tsc --noEmit` AND clean `npx vite build`.
- `scripts/check-ui-canon.sh` exit 0; impeccable hook green.
- Existing Vitest suite (CommandCenter components, store, contract, states, navigationConfig) green; new specs added per §3.
- `CommandCenterLiveDataTest` + `CommandCenterSchemaTest` green (proves StatusEngine delegation didn't change derived values, and the additive migrations didn't break the contract).
- Playwright auth/navigation/rtdc-huddle specs green; navigation spec extended for the 4-section IA.
- **Auth system unchanged** — every `.claude/rules/auth-system.md` rule holds; `ChangePasswordModal` now present on *more* pages, never fewer, never dismissable.
- **Zero production data lost** — all migrations additive/`SafeMigration`-guarded; no route with inbound links hard-deleted; prod refresh via the sanctioned `zephyrus:demo-seed` runbook.

#### 4.5 The one-sentence definition of done
**Zephyrus 2.0 is done when there is one home, one descent path, one nav SSOT, one persona control, one snapshot, and one StatusEngine feeding both the live and analytic altitudes — every existing URL kept, repointed, or gracefully redirected; the canon check and impeccable hook green; `RouteSmokeTest` ≥82/82; `tsc` and `vite build` clean; the auth system untouched; and a calm house reading near-monochrome on a single, non-scrolling screen.**

---

# Appendix A — Adversarial Critique (full, verbatim)

## Overall
This is a strong, evidence-grounded plan whose central thesis (additive consolidation onto a single StatusEngine/snapshot/primitive-library/nav-SSOT, with the canon adopted-in-philosophy/rejected-in-tokens) is sound and verifiable. The design-canon reconciliation chapter is the plan's best work and contains no canon violations I could find — it correctly rejects IBM Plex/OKLCH/cyan/fifth-green and routes everything through STATUS_VAR, tabular-nums, and the 4+grey vocabulary. The protected auth system is respected throughout (ChangePasswordModal is only ADDED app-wide, never made dismissable; auth pages are walled off as a sanctioned exception). However, the plan has one ARCHITECTURE-LEVEL gap that undermines its backend foundation: it assumes a multi-facility model (Facility::active(), cockpit_snapshots PK=facility_id) that DOES NOT EXIST in the codebase, contradicting its own 'no new facility table, reuse HospitalManifest' decision and the verified single-facility reality (CommandCenterDataService queries prod.* directly with no facility scoping). There are also several unverified mechanical claims (DashboardContext mount location cited wrong in 3+ chapters; ?drill= and ?display=wall param-reading treated as settled but absent from CommandCenter.tsx today; over-reliance on check-ui-canon.sh which only enforces 2 of the ~6 canon rules). None of these are fatal, but the facility-model contradiction and the redirect/drill sequencing gap must be resolved before P1/P4 or those phases will stall. Recommend: ship the plan after the prioritized fixes below; the spine is correct.

## Gaps
- FACILITY MODEL DOES NOT EXIST (Backend Foundation §4/§7, Roadmap P1): the plan's RefreshCockpitSnapshot job 'iterates Facility::active()' and cockpit_snapshots uses 'facility_id PK', but there is NO Facility model in app/Models and NO facilities table created in any migration (verified: grep for Schema::create('facilities') returns nothing; CommandCenterDataService queries prod.* directly with zero facility scoping). The plan elsewhere correctly says 'no new facility table — reuse HospitalManifest', but never reconciles that single-facility/manifest reality with the multi-facility serving design. cockpit_snapshots(facility_id PK) over a single implicit facility is over-engineering; Facility::active() will not compile.
- NO 2.0 DISPOSITION for several live route trees verified in routes/web.php but absent from the §8 section-by-section table: /improvement/overview, /improvement/opportunities, /improvement/active, the PDSA create/show/store sub-routes, /rtdc/global-huddle (a POST-bearing live huddle surface), and the Analytics Ops Console controller (OpsConsoleController) routes. The table claims to map 'every current section' but omits these — a charge nurse using /rtdc/global-huddle has no altitude assignment.
- THE ?drill= REDIRECT TARGET IS NOT BUILD-READY (IA §3/§7, Roadmap P4): CommandCenter.tsx does NOT read ?drill= or ?display= today (verified). P4 deprecates 5 routes to redirects landing on /dashboard?drill={domain} expecting the cockpit to auto-open that DrillModal — but the param-reading/auto-open logic is never explicitly scoped in P2 or P3, only assumed. If P4 lands before that logic, every redirected bookmark lands on the bare cockpit with no modal, silently degrading the 'preserve every bookmark' promise to 'bookmark resolves but does nothing'.
- NEDOCS input columns (vent_pts, longest_admit_wait_hrs, last_bed_time_hrs) are deferred to P7, but the P2 acceptance criteria require 'Implement NEDOCS gauge via RadialGauge scale=200 + 5-band arc' and the P1 seed fires 'NEDOCS 142' as a headline alert. The gauge renders a MOCK 142 in P2-P6 while the IA, alerting, and Eddy chapters all treat ed.nedocs as a first-class crit signal — the plan never flags that the marquee crit alert demoed for 4+ months is synthetic until P7.
- No disposition for the StaffingDashboardController (imported in routes/web.php) vs the StaffingOffice.tsx page the chapters discuss — the plan treats Staffing as a single page but a dashboard controller is wired; unclear which is the A1 workspace.
- Migration/rollback story for the deprecated routes is one-directional: the plan says 'deprecate to redirect, never hard-delete' but gives no mechanism to RESTORE the old overview pages if the cockpit drill proves insufficient (the old controller methods RTDCDashboardController::index etc. would be orphaned). No feature-flag to toggle redirect-vs-original during the P4 transition, unlike the P2 cockpit flag.

## Contradictions
- FACILITY SCOPING CONTRADICTION: Backend Foundation §7 ('Reuse HospitalManifest ... as the cockpit facilities/units source — no new facility table') directly contradicts Backend Foundation §4 and §5 + Roadmap P1 ('prod.cockpit_snapshots(facility_id PK ...)' and 'RefreshCockpitSnapshot iterates Facility::active()'). You cannot both have 'no facility table' and a 'facility_id PK' iterated via a Facility Eloquent model. The Success Metrics C6 ('one cockpit_snapshots row per facility') inherits the same contradiction.
- DEAD-CODE MOUNT LOCATION cited inconsistently and incorrectly: IA §5, Synthesis Chapter 2/3, and Roadmap P4 all state DashboardContext.tsx is mounted 'in Providers.tsx' and has 'zero consumers'. Verified reality: it is mounted in resources/js/Providers/HeroUIProvider.tsx (not Providers.tsx — that file doesn't carry the mount), as <DashboardProvider currentUrl={url}> wrapping ALL children. Its useDashboard() hook indeed has zero consumers (so the config payload is dead), but the Provider IS in the live render tree — deleting it requires editing HeroUIProvider.tsx and confirming nothing relies on the wrapper, which the plan's 'zero consumers, delete outright' framing understates.
- CANON-CHECK SCOPE overstated vs the plan's own honest §3.2: multiple acceptance gates (Success Metrics C4, P3/P5 'canon green' gates, the cross-cutting guardrail 'scripts/check-ui-canon.sh ... the broader color/surface ban') imply the script enforces color/surface/glassmorphism. Verified: check-ui-canon.sh enforces ONLY font-bold/font-extrabold and text-[Npx]/[Nrem] (and warns on arbitrary line-heights); its own comment says 'The broader color/surface canon ... is enforced interactively by the impeccable hook'. The Testing chapter §3.2/R1 gets this right, but the synthesis/metrics chapters lean on the script as if it catches raw-palette/backdrop-blur regressions — it does not, so C4's 'raw-color count does not rise' is measured by grep, NOT by the gate, and a glassmorphism regression will pass the scripted gate.
- EFFORT/SEQUENCING vs PARALLELISM: the effort table says P7 is 'incremental, any time after P1' and parallelizable, but P7 swaps ed.nedocs to live which REQUIRES the P7-internal schema migration AND the P2-built NEDOCS gauge AND the P6 alert wiring already consuming ed.nedocs. P7 cannot precede P2/P6 for that domain, contradicting 'any time after P1'.
- OperationsAnalyticsService path cited as 'app/Services/OperationsAnalyticsService.php' (Backend Foundation §1, Current-State §3.3, Intelligence §1) — actual path is app/Services/Analytics/OperationsAnalyticsService.php. The duplication thesis is CORRECT (both files do contain bandHighBad), but the wrong path would send an implementer to a non-existent file.

## Canon/Auth Risks
- Reference-token copy risk is correctly identified (R1) but the mitigation under-protects: because check-ui-canon.sh does NOT catch raw hex/OKLCH/backdrop-blur (only font-bold + text-[Npx]), the ONLY automated guard against a contributor pasting the prototype's OKLCH/cyan/radial-gradient/backdrop-blur is the impeccable hook running interactively. If a sweep agent or CI run bypasses the interactive hook (e.g. a worktree commit), OKLCH and glassmorphism can land green. The plan should add these patterns to check-ui-canon.sh (oklch\(, backdrop-blur, raw bg-gray/red/blue/green in resources/js) so the SCRIPTED gate — not just the interactive hook — enforces the reconciliation. This is the single highest-leverage canon hardening missing.
- The sanctioned text-[11px] wall-mode exception (Design Language §2, P0) will be FLAGGED by check-ui-canon.sh because the script's regex is text-\[[0-9.]+(px|rem)\] excluding only /Design/ — it does NOT exclude cockpit primitives or wall mode. The plan says 'document it in CLAUDE.md so the check is annotated' but documentation does not stop an exit-1. To actually ship text-[11px], the script's exclusion path must be widened (e.g. exclude Components/cockpit/ or a /* canon-ok: wall-micro */ inline marker) — otherwise P0 breaks the gate it promises to keep green.
- Two-System Rule risk in the AlertTicker 'pulsing-red blip' (Design Language §4): coral pulsing animation is sanctioned, but the plan must ensure the blip uses --critical (coral) NOT crimson #9B1B30 — a 'pulsing red' on a dark wall is exactly where a contributor reaches for brand crimson. The reconciliation says coral, but the wall-mode AlertTicker is the most likely place crimson leaks into a status role.
- border-healthcare-purple (cited in P7 as a CareJourneyCard fix) and text-purple-* (PDSA/BlockUtilization) are real existing violations the plan commits to fix 'on contact' — but they are scheduled for P5/P7, meaning purple ships in prod through P0-P4. Since check-ui-canon.sh does not catch them and they are pre-existing, there is risk they are never actually fixed if the touching phase de-scopes; the plan should pin them to a specific phase gate rather than 'on contact'.
- Light-mode white-on-white footgun (R1b) is well-described, but the plan converts many bg-gray-900 surfaces (ProcessFlowDiagram:79, RoomRunningService dataSeries) during P5/P7 — the mitigation 'eyeball in light mode' is manual and unenforced. Given the dark-default dev view, this class of regression is invisible in CI; recommend an explicit light-mode visual pass in the P5/P7 acceptance criteria, not just R1b prose.

## Missing Steps
- P0/P1 must create or explicitly DECIDE-AGAINST a Facility model BEFORE the SnapshotBuilder/RefreshCockpitSnapshot work. Resolution: either (a) cockpit_snapshots is a single-row table (no facility_id) matching the verified single-facility prod.* reality, or (b) introduce a minimal Facility model backed by HospitalManifest (not a DB table) so Facility::active() resolves to the manifest's facility list. This decision is a P0 prerequisite, currently buried as an unstated assumption.
- A frontend step 'CommandCenter.tsx reads ?drill={domain} and ?display=wall query params and opens the matching DrillModal / enters wall mode' must be added to P2 (param reading) and P3 (drill auto-open), and must MERGE BEFORE P4's redirects. The dependency P4-depends-on-(P2-param-reading) is missing from the dependency graph; today P4 lists only 'depends on P3'.
- P4 layout convergence (5 shells -> 1) is the single highest-blast-radius IA step (DashboardLayout=32 pages, RTDCPageLayout=14, etc.) and the plan allots it ~2 weeks shared with RoleSwitcher-to-chrome, ChangePasswordModal app-wide, AND the two deletions. This is under-sequenced. Recommend splitting: (P4a) nav restructure + redirects + RoleSwitcher chrome; (P4b) layout convergence + ChangePasswordModal app-wide as its own gated step with a per-shell RouteSmokeTest checkpoint, since merging RTDCPageLayout's RTDC-specific chrome into a generic shell can silently strip functionality from 14 pages.
- Add an explicit migration step to back-correct the cited-wrong file paths in the plan before implementation: OperationsAnalyticsService is under Analytics/, DashboardContext mount is in HeroUIProvider.tsx. An implementer following the literal paths will fail to find them.
- P6 alarm-fatigue flap-damping adds a 'cooldown column' to cockpit_alerts, but cockpit_alerts is created in P1 — the column should be in the P1 schema (additive migrations are cheap) rather than a second ALTER in P6, or the plan must add the P6 migration to the migration inventory (it currently says 'additive migration' in P6 without listing it in the P1 table-creation, risking the K-consecutive-snapshots state having nowhere to persist).
- Add a step to verify/seed the materialized views (mv_hai_ledger, mv_service_line_los, mv_cost_center_productivity) refresh ordering — CONCURRENTLY requires a unique index on each MV or REFRESH MATERIALIZED VIEW CONCURRENTLY fails at runtime; the plan says 'refreshed CONCURRENTLY hourly' but never specifies the required unique index, a known Postgres footgun.
- No step addresses how the 90-day synthetic drilldown (CommandCenterController::drilldown is documented as 'synthetic, 90-day minimum') is reconciled with the new DrillBuilder real data — the plan reuses CommandCenterDrilldownService but that service currently returns synthetic data; swapping to real Cell-grammar requires a step the plan glosses as 'reuse CommandCenterDrilldownService' without noting it is synthetic today.

## Prioritized Fixes
1. 1. RESOLVE THE FACILITY MODEL CONTRADICTION (Backend Foundation §4/§5/§7 + Roadmap P1 + Success Metrics C6). Decide single-facility now: drop facility_id PK from cockpit_snapshots (single replaced row) OR add a manifest-backed Facility value-object that makes Facility::active() resolve to HospitalManifest. Verified: no Facility model, no facilities table, CommandCenterDataService queries prod.* with no facility scope. This blocks P1 — fix before any SnapshotBuilder code.
2. 2. ADD PARAM-READING TO P2/P3 AND RE-ORDER THE DEPENDENCY (IA §3/§7 + Roadmap P4). Make CommandCenter.tsx read ?drill= and ?display=wall (P2) and auto-open DrillModal (P3); state explicitly that P4's redirects depend on this. Verified: CommandCenter.tsx reads neither param today. Without this, P4's 'every bookmark preserved' promise silently fails to 'resolves to a bare cockpit'.
3. 3. HARDEN scripts/check-ui-canon.sh TO ENFORCE THE RECONCILIATION (Design Language §1 + R1 + Cross-cutting guardrails). Add scripted patterns for oklch\(, backdrop-blur, and raw bg-(gray|red|blue|green|amber|indigo|slate)-[0-9] in resources/js (excluding sanctioned paths), and widen the text-[Npx] exclusion to allow the wall-mode text-[11px] in Components/cockpit/. Verified: the script enforces only font-bold + text-[Npx] today and explicitly defers color/surface to the interactive hook — which a worktree commit can bypass. This closes the only path by which the rejected reference tokens can land green.
4. 4. ADD THE 2.0 DISPOSITION FOR THE OMITTED LIVE ROUTES (IA §8 table). Assign altitudes to /rtdc/global-huddle (A1 workspace, it has a POST), /improvement/overview|active|opportunities, the PDSA create/show/store sub-routes (A3 Study), and OpsConsoleController routes. The table claims 'every current section' but these are missing — verified present in routes/web.php.
5. 5. SPLIT P4 INTO P4a (nav + redirects + RoleSwitcher) AND P4b (layout convergence + ChangePasswordModal app-wide) WITH A PER-SHELL ROUTESMOKETEST CHECKPOINT (Roadmap P4). Merging RTDCPageLayout (14 pages) and TransportLayout (5) into one generic shell is the highest-blast-radius step and is under-allotted; it must not share a 2-week budget with four other workstreams. Verified shell distribution from the assessment.
6. 6. CORRECT THE CITED FILE PATHS THROUGHOUT (Backend Foundation §1, Current-State §3.3, IA §5, P4): OperationsAnalyticsService is at app/Services/Analytics/OperationsAnalyticsService.php; DashboardContext is mounted in resources/js/Providers/HeroUIProvider.tsx (not Providers.tsx). The theses (band duplication, dead config) are correct; only the paths are wrong, but a literal-following implementer will fail.
7. 7. FLAG ed.nedocs AS SYNTHETIC-UNTIL-P7 in P1/P2 acceptance (Backend Foundation §3 + Intelligence §3 + Roadmap P1/P2/P7). The headline 'NEDOCS 142' crit alert that lights the wall for screenshots is MOCK until the P7 schema migration; the plan should state this explicitly so the demo'd marquee crit is not mistaken for live, and add the required unique index for the CONCURRENTLY-refreshed MVs.
8. 8. PIN THE 'fix-on-contact' CANON VIOLATIONS (border-healthcare-purple, text-purple-*, bg-gray-900 white-on-white) TO SPECIFIC PHASE GATES (P5/P7) WITH A LIGHT-MODE VISUAL PASS in their acceptance criteria, since check-ui-canon.sh cannot catch them and the dark-default dev view hides the white-on-white class (R1b).
