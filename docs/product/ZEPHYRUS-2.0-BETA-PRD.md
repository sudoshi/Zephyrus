# Zephyrus 2.0 Beta PRD

Status: Canonical go-forward product requirements document
Date: 2026-07-08
Scope: Zephyrus web, Eddy AI agent, Hummingbird mobile coordination app, demo data, operational intelligence, and beta validation
Primary outcome: a coherent Zephyrus 2.0 beta with a complete demo track across all functional areas

---

## 0. Executive Decision

Zephyrus 2.0 beta is not another dashboard tranche. It is the consolidation of the application suite into one operational product:

- The Zephyrus web application becomes the Hospital Operations Cockpit and Flow OS for the command center.
- Eddy becomes the governed operational AI loop that can explain, draft, and route work, but never self-approve or bypass the existing action lifecycle.
- Hummingbird becomes the role-specific mobile coordination layer that consumes the same backend truth as Zephyrus and closes the loop for frontline workers.
- Summit Regional / HOSP1 becomes the deterministic beta reference hospital and demo environment.

The beta is complete when a user can move from a calm house-wide cockpit to a specific operational blocker, ask Eddy what changed and what to do next, approve or reject an auditable action, see that action land on the appropriate Hummingbird persona, and then return to Zephyrus to review impact and provenance. That loop must work across ED boarding, inpatient flow, patient placement, transport, EVS, perioperative pressure, staffing, improvement, command-center intelligence, and executive review.

This document is now the planning authority. Prior Markdown files are preserved as lineage, evidence, and appendix material, but this document resolves their conflicts and defines the beta finish line.

---

## 1. How To Use This Document

### 1.1 Authority Order

When docs conflict, use this order:

1. `docs/product/ZEPHYRUS-2.0-BETA-PRD.md` - this document.
2. `docs/product/ZEPHYRUS-2.0-PLAN.md` - master Zephyrus 2.0 plan, with its Part II corrections taking precedence over its later chapters.
3. `docs/product/ZEPHYRUS-2.0-PART-X.md` - canonical companion for process intelligence and the Patient-Flow Arena.
4. `docs/hummingbird/PLATFORM-RECONCILIATION-TODO.md` plus `docs/hummingbird/ALTITUDE-PERSONA-OPERATING-PLAN.md` - Hummingbird parity and mobile altitude authority.
5. `docs/plans/EDDY-AI-AGENT-PLAN.md`, `docs/plans/EDDY-LOCAL-AGENT-PATH.md`, and the Patient Flow 4D Barrier/Eddy plan - Eddy authority.
6. Devlogs dated 2026-06-25 through 2026-07-05 - shipped-state evidence.
7. Older superpowers plans/specs, root solution docs, and research files - supporting lineage only.

### 1.2 Required Behavior For Future Planning

- Do not create another competing master plan.
- Do not treat old unchecked boxes in superseded plans as automatically current.
- Do not implement mobile-only semantics where the capability should exist in Zephyrus.
- Do not treat synthetic demo data as live operational truth.
- Do not let Eddy hold approval authority.
- Do not route around the shared backend services, status vocabulary, event trail, or PHI redaction policies.

### 1.3 What This Document Does Not Do

This document does not physically move existing Markdown files. "Logical organization" means every Markdown file has a go-forward disposition and is placed into the planning hierarchy below.

---

## 2. Product Doctrine

### 2.1 The North Star

Zephyrus 2.0 is a hospital operations cockpit and execution system. It must answer four questions:

1. Is the house OK right now?
2. What is driving the current strain?
3. Who should act next?
4. Did the action improve the operational state?

The product must support those questions in web, mobile, and AI-assisted workflows without splitting truth across separate dashboards, duplicated status logic, or platform-specific behavior.

### 2.2 The Altitude Model

All experiences must fit the same altitude model:

| Altitude | User question | Web surface | Hummingbird surface | Beta rule |
|---|---|---|---|---|
| A0 - Glance | Is my scope OK right now? | Cockpit overview, wall view, scoped cockpit face | Role-specific Home | No scroll for primary glance; status never color-only |
| A1 - Workspace | What do I work now? | RTDC, ED, OR, transport, EVS, staffing, improvement workspaces | Role package workspace/list | Live operational work, not historical study |
| A2 - Drill | Why is this item abnormal? | Drill modal, focused board, panel drill | Drill from Home/For You/Activity | Must expose source, freshness, driver, owner, and next move |
| A2P - Patient/Encounter Lens | Which patient/encounter/entity is causing this? | Patient lens or operational entity drill | Patient/encounter operational context | Operational lens only, not an EHR chart |
| A3 - Study | What pattern should we improve? | Analytics, PI, Patient-Flow Arena, retrospectives | Open in Zephyrus / read-only summary | Not the first screen; no mobile-only analytic divergence |

### 2.3 Suite Relationship

Zephyrus web is the command and study surface. Hummingbird is the role-specific mobile action surface. Eddy is the governed reasoning and drafting layer. They must share:

- the same source services,
- the same status output,
- the same role and scope policy,
- the same event trail,
- the same action lifecycle,
- the same PHI and audit rules,
- the same synthetic/live provenance labels.

### 2.4 Non-Negotiables

1. One home: `/dashboard` is the single web home.
2. One descent: A0 -> A2 -> A1 -> A3, with A2P when authorized.
3. One status authority: `StatusEngine` and its client mirror.
4. One snapshot contract: cockpit, Eddy, and mobile aggregate views read the same as-of truth where a metric exists.
5. One action lifecycle: `ops.actions` and `ops.approvals` govern all agent-drafted work.
6. One nav source: `navigationConfig.ts`; no shadow nav source.
7. One mobile BFF: Hummingbird uses `/api/mobile/v1/*`; native clients do not scrape web pages.
8. One PHI posture: no patient identifiers in push, broadcasts, list payloads not authorized for detail, logs, crash analytics, or cloud model routes.
9. One demo hospital: Summit Regional / HOSP1, with `ZEPHYRUS-500` retained as immutable CAD/data lineage where needed.
10. One beta proof: a deterministic demo that shows action, not just visibility.

---

## 3. Logical Organization Of All Markdown Files

Every Markdown file in `docs/` is assigned a disposition here. The goal is to keep useful lineage while eliminating planning ambiguity.

### 3.1 Tier 0 - New Authority

| File | Disposition |
|---|---|
| `docs/product/ZEPHYRUS-2.0-BETA-PRD.md` | Canonical beta PRD and north-star. Supersedes all prior planning documents where they conflict. |

### 3.2 Tier 1 - Primary Product Authority

| File | Disposition |
|---|---|
| `docs/product/ZEPHYRUS-2.0-PLAN.md` | Primary Zephyrus 2.0 master plan. Part II corrections win over later conflicts. |
| `docs/product/ZEPHYRUS-2.0-PART-X.md` | Canonical companion for object-centric process intelligence and Patient-Flow Arena. Beta includes the substrate/status; full Arena phases are post-beta unless explicitly enabled. |
| `docs/plans/EDDY-AI-AGENT-PLAN.md` | Eddy architectural authority, corrected by local-agent and mobile devlogs where status has changed. |
| `docs/plans/EDDY-LOCAL-AGENT-PATH.md` | Current local-agent status and gated remaining work. |
| `docs/hummingbird/PLATFORM-RECONCILIATION-TODO.md` | Hummingbird parity execution authority. |
| `docs/hummingbird/ALTITUDE-PERSONA-OPERATING-PLAN.md` | Mobile altitude, persona, patient lens, relay, and Eddy awareness authority. |

### 3.3 Tier 2 - Active Companion Plans

| File | Disposition |
|---|---|
| `docs/hummingbird/IMPLEMENTATION-PLAN.md` | Historical-to-current mobile program plan; use for doctrine and broad phase framing, but reconcile with newer platform TODO. |
| `docs/hummingbird/PERSONA-SCREENS-PLAN.md` | Shipped iOS baseline plus remaining Android/platform parity. Stale where the reconciliation TODO says Android has advanced. |
| `docs/hummingbird/FLOW-WINDOW-PLAN.md` | Baseline for the shipped 48-hour Flow Window; remaining items become Hummingbird hardening/backlog. |
| `docs/hummingbird/NATIVE-4D-VIEWER-PLAN.md` | Proposed native 3D duties viewer; post-beta unless explicitly promoted. |
| `docs/hummingbird/ADR-2026-07-01-altitude-patient-lens.md` | Ratified decision: patient/encounter lens is A2P, not a fifth altitude. |
| `docs/hummingbird/PHASE-0-BACKEND.md` | Shipped mobile backend foundation evidence. |
| `docs/hummingbird/PUSH.md` | Push plumbing and open push-trigger work. |
| `docs/hummingbird/DATA-PLAUSIBILITY.md` | Demo-data plausibility checks for Hummingbird. |
| `docs/hummingbird/DESIGN-ELEVATION-TODO.md` | Hummingbird UI polish backlog. |
| `docs/hummingbird/DEV-STACK.md` | Mobile development environment and Android handoff reference. |
| `docs/hummingbird/README.md` | Hummingbird document map and product doctrine entry point. |
| `docs/hummingbird/api-contract/README.md` | Mobile BFF contract status and open API-contract tasks. |
| `docs/hummingbird/api-contract/mobile-route-contract-inventory.md` | Route/OpenAPI/client inventory and drift evidence. |
| `docs/hummingbird/design-tokens/README.md` | Hummingbird token reference. |
| `docs/superpowers/plans/2026-07-05-patient-flow-4d-barrier-eddy-implementation-plan.md` | Active Patient Flow 4D barrier/Eddy backlog. |
| `docs/architecture/SERVICE-LINE-LOCATION-DEPLOYMENT-TAXONOMY-2026-07-04.md` | Canonical service-line/location deployment taxonomy. |
| `docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md` | Implementation appendix for deployment taxonomy. |
| `docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md` | Staffing alignment implementation appendix. |

### 3.4 Tier 3 - Canonical Demo, Facility, And Cockpit Evidence

| File | Disposition |
|---|---|
| `docs/reference/hospital-operations-cockpit/docs/COCKPIT_IMPLEMENTATION.md` | Cockpit layout/API/design reference. Use for layout grammar; reject any token/schema/facility assumptions that conflict with Zephyrus 2.0. |
| `docs/plans/HOSPITAL-1-SUMMIT-REGIONAL-PLAN.md` | HOSP1/Summit demo-data unification plan. |
| `docs/plans/HOSPITAL-1-EXECUTION-INTEL.md` | Verified HOSP1 execution intelligence. Supersedes older HOSP1 assumptions when specific. |
| `docs/superpowers/specs/2026-06-26-90day-demo-walkthrough.md` | Demo-story input. Fold into beta demo track. |
| `docs/superpowers/specs/2026-06-26-competitor-capability-scorecard.md` | Differentiation and scorecard input. Fold into beta acceptance. |

### 3.5 Tier 4 - Baseline Devlogs And Shipped-State Evidence

| File | Disposition |
|---|---|
| `docs/devlog/DEVLOG-application-completeness-2026-06-27.md` | Evidence for application-completeness tranche and route smoke baseline. |
| `docs/devlog/DEVLOG-authentik-sso-fleet-2026-06-22.md` | Evidence for Authentik SSO verification. |
| `docs/devlog/DEVLOG-cockpit-mount-anywhere-p8-2026-07-05.md` | Evidence for P8 mount-anywhere slice; verify current code before relying on details. |
| `docs/devlog/DEVLOG-command-center-resilience-adaptive-2026-06-26.md` | Evidence for command-center resilience work. |
| `docs/devlog/DEVLOG-flow-os-competitor-leapfrog-2026-06-26.md` | Evidence for Flow OS competitive tranche. |
| `docs/devlog/DEVLOG-hummingbird-ios-testflight-2026-06-28.md` | Hummingbird iOS/TestFlight status evidence. |
| `docs/devlog/DEVLOG-native-4d-viewer-2026-07-05.md` | Native 4D viewer status evidence; treat remaining uncommitted/open notes carefully. |
| `docs/devlog/DEVLOG-patient-flow-4d-navigator-2026-06-25.md` | Patient Flow 4D navigator shipped-state evidence. |
| `docs/devlog/DEVLOG-ui-consistency-remediation-2026-06-26.md` | UI consistency remediation shipped-state evidence. |

### 3.6 Tier 5 - Supporting Research And Reference

| File | Disposition |
|---|---|
| `docs/hummingbird/reference/00-vision-scope-personas.md` | Mobile reference; subordinate to current altitude and reconciliation docs. |
| `docs/hummingbird/reference/01-feature-parity-matrix.md` | Mobile parity reference; update as beta work lands. |
| `docs/hummingbird/reference/02-core-functionality-by-role.md` | Persona reference; subordinate to 14-role altitude plan. |
| `docs/hummingbird/reference/03-architecture.md` | Mobile architecture reference. |
| `docs/hummingbird/reference/04-backend-requirements.md` | Mobile backend requirements reference. |
| `docs/hummingbird/reference/05-notifications-earned-urgency.md` | Notification and earned-urgency reference. |
| `docs/hummingbird/reference/06-security-hipaa.md` | Mobile security/HIPAA release-gate reference. |
| `docs/hummingbird/reference/07-roadmap-phasing.md` | Historical mobile phasing reference. |
| `docs/hummingbird/reference/08-eddy-mobile.md` | Mobile Eddy reference; current for BFF/doorbell status. |
| `docs/hummingbird/research/01-emergency-department.md` | ED mobile research and gap inventory. |
| `docs/hummingbird/research/02-rtdc.md` | RTDC mobile research and gap inventory. |
| `docs/hummingbird/research/03-perioperative.md` | Perioperative mobile research and schema caveats. |
| `docs/hummingbird/research/04-improvement-and-ops.md` | Improvement/Ops/AI mobile research. |
| `docs/hummingbird/research/05-platform-auth-realtime-design.md` | Platform/auth/realtime/token research. |
| `docs/hummingbird/research/06-supporting-domains-and-api.md` | Supporting domains/API research. |
| `docs/hummingbird/research/07-mobile-best-practices.md` | Mobile security/offline/realtime best-practice reference. |
| `docs/architecture/RootCauseImprovementWorkflow.md` | Historical improvement workflow reference; superseded by v2.0 Study/Arena direction. |
| `docs/guides/import-export-guide.md` | Pattern guide; keep as engineering reference. |
| `docs/guides/coding-standards.md` | Engineering reference. |
| `docs/archive/auth-legacy/session-auth.md` | Session-auth reference. |
| `docs/archive/auth-legacy/csrf-solution.md` | Historical CSRF/auth reference. |
| `docs/archive/auth-legacy/no-login-solution.md` | Historical no-login/demo auth reference; do not use as beta auth authority. |
| `docs/plans/EDDY-ABBY-TEARDOWN-EVIDENCE.md` | Raw Abby teardown evidence; useful only when explaining Eddy architecture choices. |
| `docs/plans/APPLICATION-COMPLETENESS-AUDIT-AND-PLAN-2026-06-26.md` | Historical application-completeness baseline; useful for page inventory and unfinished-domain lineage, superseded by this PRD for beta planning. |
| `docs/plans/UX-DENSITY-TIGHTENING-PLAN.md` | Supporting density rationale; not current design authority. |
| `docs/plans/UI-CONSISTENCY-REMEDIATION-PLAN-2026-06-26.md` | Historical plan; use devlog plus this PRD for current rules. |

### 3.7 Tier 6 - Superseded Superpowers Lineage

These files remain useful as historical design and implementation evidence, but their unchecked boxes are not automatically current backlog. Pull requirements forward only if they are named in this PRD or in an active companion plan.

| File | Disposition |
|---|---|
| `docs/superpowers/plans/2026-06-20-rtdc-engine-huddles.md` | Superseded by current RTDC/Flow/Altitude plans; preserve for detailed RTDC engine lineage. |
| `docs/superpowers/specs/2026-06-20-rtdc-engine-huddles-design.md` | Design lineage for RTDC huddles. |
| `docs/superpowers/plans/2026-06-21-authentik-oidc-login.md` | Historical auth implementation plan; current Authentik status comes from devlog/code. |
| `docs/superpowers/specs/2026-06-21-authentik-oidc-login-design.md` | Historical auth design spec. |
| `docs/superpowers/plans/2026-06-21-bed-assignment-recommender.md` | Bed recommendation lineage; current placement work governed by Zephyrus 2.0 and Patient Flow/Eddy backlog. |
| `docs/superpowers/specs/2026-06-21-bed-assignment-recommender-design.md` | Bed recommender design lineage. |
| `docs/superpowers/plans/2026-06-21-navbar-consolidation.md` | Superseded by Zephyrus 2.0 IA. |
| `docs/superpowers/specs/2026-06-21-navbar-consolidation-design.md` | Nav design lineage. |
| `docs/superpowers/plans/2026-06-22-auth-ux-reimagine.md` | Historical auth UX plan; protected auth rules remain unchanged. |
| `docs/superpowers/specs/2026-06-22-auth-ux-reimagine-design.md` | Historical auth UX design spec. |
| `docs/superpowers/plans/2026-06-22-command-center-dashboard.md` | Superseded by Zephyrus 2.0 Cockpit plan. |
| `docs/superpowers/specs/2026-06-22-command-center-dashboard-design.md` | Cockpit design lineage. |
| `docs/superpowers/specs/2026-06-22-command-center-live-data-design.md` | Live-data design lineage. |
| `docs/superpowers/plans/2026-06-24-transport-operations.md` | Transport lineage; current transport work lives under domain backlog and demo track. |
| `docs/superpowers/plans/2026-06-25-analytics-engine-reimagine.md` | Analytics lineage; current A3 Study work governs. |
| `docs/superpowers/plans/2026-06-25-command-center-observability-leadership-plan.md` | Supporting leadership/observability plan. |
| `docs/superpowers/plans/2026-06-25-hospital-blueprint-ingestion-digital-twin.md` | Facility/digital-twin lineage; current HOSP1 and service-line/location docs govern. |
| `docs/superpowers/plans/2026-06-25-patient-flow-4d-navigator-integration.md` | Superseded by shipped navigator devlog and July barrier/Eddy backlog. |
| `docs/superpowers/plans/2026-06-25-patient-flow-4d-navigator-todo.md` | Mostly superseded by shipped navigator devlog and July barrier/Eddy backlog. |
| `docs/superpowers/plans/2026-06-25-real-time-healthcare-data-acquisition.md` | Supporting integration/acquisition plan; active requirements carried forward below. |
| `docs/superpowers/plans/2026-06-25-zephyrus-flow-os-market-leapfrog-plan.md` | Strategic lineage; beta acceptance pulls forward execution-system differentiators. |
| `docs/superpowers/plans/2026-06-26-competitor-leapfrog-execution-plan.md` | Strategic lineage; beta scorecard pulls forward relevant proof points. |

---

## 4. Beta Scope Boundary

### 4.1 In Scope For Zephyrus 2.0 Beta

The beta includes:

1. One no-scroll web Cockpit at `/dashboard`.
2. Cockpit snapshot/API/status foundation.
3. Drill modal descent from A0 to domain evidence.
4. A1 workspaces for the demo flows: RTDC/bed placement, Patient Flow 4D, transport, EVS, perioperative, staffing, improvement, command center.
5. A2P patient/encounter operational lens where authorized.
6. HOSP1/Summit demo data with clear live/synthetic provenance.
7. Patient Flow 4D barrier finder, inspector, scenario, and Eddy context sufficient for demo.
8. Eddy governed action loop: read context, draft action, dry-run preview, human approval/rejection/override, audited lifecycle.
9. Hummingbird parity for the demo personas and scenario handoffs.
10. Mobile security/offline/realtime minimums needed to demo safely.
11. Route, build, UI-canon, PHI, and screenshot validation package.
12. Deployment/runbook clarity for manual Zephyrus release and demo reset.

### 4.2 Explicitly Post-Beta Unless Promoted

The following are not required for beta unless the user explicitly promotes them:

1. Full multi-facility IDN deployment with all source systems live.
2. Fully live Quality, Service Line, and Financial domains beyond demo/provenance.
3. Native mobile 3D viewer replacing Flow Window.
4. Full Patient-Flow Arena X1-X4, including OCPM sidecar, OPerA cockpit tiles, conformance-to-Eddy flow, and AI process copilot.
5. Broad production APNs/FCM paging with all roles and escalation policies.
6. Cloud frontier model use on patient-level context without BAA and de-identification review.
7. Unattended clinical alerting or autonomous clinical recommendations.

### 4.3 Beta Decisions That Resolve Prior Ambiguity

| Topic | Decision |
|---|---|
| Facility model | Beta uses single-facility `facility_key`/HospitalManifest. Do not create a blocking `Facility` table/model for Cockpit P0/P1. |
| Service line | Treat service line as a scope and deployment taxonomy, not a separate persona. |
| Wall mode | Same `/dashboard` route and component tree, with wall preset/chrome reduction/dark lock. No separate app. |
| Synthetic metrics | Allowed only when visibly marked as demo/synthetic or hidden by a flag. Hero demo flows must disclose synthetic status. |
| NEDOCS | Synthetic until real input columns and service exist. Do not demo it as live. |
| Eddy authority | Eddy can read, reason, draft, and explain. Humans approve, reject, override, assign, and complete. |
| Hummingbird parity | Mobile can adapt shape, not truth. Backend/web parity is a release gate. |
| Part X | X0/substrate may be referenced; X1-X4 are roadmap unless promoted. |
| Old dashboards | Redirect or repoint with rollback discipline; do not hard-delete routes with inbound links. |

---

## 5. Current Baseline To Preserve

This baseline is derived from the docs audit and must be re-verified against code before implementation or release.

### 5.1 Zephyrus Web Baseline

- The app already has a working command center/cockpit nucleus.
- Patient Flow 4D navigator, route, API, GLB/facility model, synthetic flow import, and nav placement are documented as shipped.
- Demo seed orchestration exists via `zephyrus:demo-seed`.
- Ops graph, metric trust, graph-backed recommendations, governed actions, EVS workflow, simulation, attribution, staffing operations, executive brief, and regional transfer are documented as shipped or materially present.
- Route smoke has been used as the core non-regression proof.
- UI consistency remediation and token discipline have a shipped devlog baseline.
- Authentik SSO has been verified in earlier devlogs.

### 5.2 Hummingbird Baseline

- Mobile BFF foundation is documented as shipped.
- `/api/mobile/v1/*` covers altitude, drills, patients, activity, Flow Window, RTDC, Ops, OR, Command, Transport, EVS, Staffing, Improvement, realtime, and Eddy.
- iOS has the stronger role-specific baseline.
- Android has role catalog and altitude shell, but still needs role-package UX parity and platform hardening.
- Flow Window Phases 0-5 are documented as shipped, including delta sync, cache, widgets/live activities, Android channels, and cross-platform builds.
- Eddy mobile BFF and PHI-free doorbell are documented as implemented; native screens and richer operational awareness remain.

### 5.3 Eddy Baseline

- The governed action/control-plane concept is already aligned to `ops.*`.
- Eddy mobile BFF and Phase 6 knowledge/learning are documented as implemented.
- Local `qwen3:8b` proxy/tool loop is built and tested but gated off.
- Local agent persistence, full-stack live E2E, queue worker, provider policy, evals, and production runbook remain open.

---

## 6. Unfinished Work: Main Zephyrus Web Application

### 6.1 Workstream Z1 - Canonical Status, Metric, And Snapshot Foundation

Goal: every cockpit, drill, mobile aggregate, alert, and Eddy context reads one coherent operational truth.

Required work:

- Create or finish `StatusEngine` as the sole threshold resolver.
- Create or finish `MetricValue` as the emitted metric shape.
- Extend/use metric definitions with ok/warn/crit edges, direction, provenance, refresh interval, source system, and alert template.
- Ensure client-side status rendering mirrors the backend status exactly.
- Build/cache the cockpit snapshot as a single replaceable facility-keyed payload.
- Ensure `SnapshotBuilder` and `EddyContextService` read the same snapshot/cache where applicable.
- Label each metric as live, seeded, synthetic, stale, degraded, or hidden.
- Write snapshot values into metric history so synthetic sparklines can be retired.

Beta acceptance:

- `/api/cockpit/snapshot` returns a schema-validated payload.
- Snapshot read is a cache lookup, not a query storm.
- The same metric cannot show different status on web and mobile for the same as-of time.
- Every hero metric exposes freshness and provenance.
- Synthetic NEDOCS and any other seeded metric are visibly labeled.

### 6.2 Workstream Z2 - Unified Cockpit Home

Goal: `/dashboard` becomes the single web home and beta command-center entry point.

Required work:

- Render one no-scroll A0 Cockpit overview.
- Include command bar, facility chip, capacity-status pill, 1Hz clock, freshness badge, census strip, alert ticker, OKR cards, eight domain panels, legend, and footer.
- Keep calm near-monochrome baseline; use amber/coral only for earned exceptions.
- Encode status with shape plus color plus text.
- Support `?display=wall`, `?role=`, `?scope=`, and `?drill=` where beta scope requires.
- Preserve accessible keyboard and focus behavior.
- Keep `/dashboard` useful if Eddy is disabled.

Beta acceptance:

- A quiet house reads calm within three seconds.
- No primary cockpit content requires scrolling at target desktop/wall sizes.
- Wall mode is the same surface with chrome reduction and no layout clipping.
- Stale data is visible, not silently accepted.

### 6.3 Workstream Z3 - Drill And Descent Architecture

Goal: every abnormal cockpit signal has a deterministic explanation path.

Required work:

- Create one unified drill modal pattern.
- Every panel header and OKR card opens a drill.
- `?drill={domain}` opens the correct drill and supports back/escape/close.
- Drill includes KPI strip, source/freshness/provenance, 1-3 dense tables, and A1/A3 handoff links.
- Drill payloads use a typed cell grammar, not arbitrary display strings.
- Redirect legacy overview routes to matching drill/workspace without losing bookmarks.
- Add A2P patient/encounter lens where the domain supports patient/entity detail.

Beta acceptance:

- Five old overview routes resolve gracefully.
- Back button walks drill state predictably.
- Every beta demo abnormality can be explained from the cockpit without leaving users at a dead end.

### 6.4 Workstream Z4 - Information Architecture And Layout Convergence

Goal: eliminate competing homes, nav duplication, and shell drift.

Required work:

- Make `navigationConfig.ts` the only nav source.
- Delete or retire dead/shadow nav context only after verifying its provider mount and consumers.
- Reorganize navigation into Cockpit, Workspaces, Study, and Admin.
- Collapse or converge app layout shells while preserving RTDC/Transport-specific affordances.
- Render `ChangePasswordModal` app-wide without weakening the protected auth flow.
- Assign dispositions to omitted live routes such as global huddle, improvement overview/active/opportunities, PDSA routes, Ops Console routes, and staffing routes.

Beta acceptance:

- One nav source.
- One app shell or a clearly staged convergence with route-smoke checkpoints.
- No authenticated page loses the protected auth modal behavior.
- `RouteSmokeTest` remains green.

### 6.5 Workstream Z5 - Domain Completeness

Goal: every beta domain is believable, connected, and honest about live versus synthetic data.

#### RTDC / Bed Flow

Open work:

- Finish live bed-tracking grid/census backing where still mock.
- Expose huddle action items with owner, due date, status, and persistence.
- Ensure discharge priorities and readiness are either live or clearly scoped as demo/study.
- Keep Patient Flow 4D under RTDC Operations, immediately after Bed Tracking.

Beta acceptance:

- Bed manager can see house strain, pending placements, candidate beds, barriers, and downstream tasks.
- Huddle/action state is not a static mock when used in the demo.

#### Emergency Department

Open work:

- Build or finish ED domain data model and demo seeding.
- Add ED signals endpoint if needed for mobile and cockpit.
- Make ED boarder, LWBS, triage/treatment board, wait, diversion, and NEDOCS signals honest.
- Mark NEDOCS synthetic until real inputs land.

Beta acceptance:

- ED boarding demo starts from a credible ED board/strain state.
- An ED boarder can flow into bed placement, EVS/transport, Hummingbird, and cockpit relief.

#### Perioperative

Open work:

- Fix known OR backend issues: `status_id` creation path, missing analytics columns, `active_status` reference filters, and `or_cases` timing assumptions.
- Make OR board, room status, PACU hold, delay cascade, turnover, block utilization, and periop predictions coherent.
- Remove or replace `CaseManagementMockData`.
- Fix remaining purple/raw-token issues when touched.

Beta acceptance:

- OR delay or PACU hold can create downstream bed/staffing pressure and appear in cockpit, Hummingbird, and Eddy.

#### Transport And EVS

Open work:

- Compute end-to-end transport wait from bounded event scans.
- Compute EVS average/p90 bed turnaround from live timestamps.
- Differentiate dispatch, EMS, care transition, and regional transfer where needed.
- Complete mobile action parity for claim, progress, handoff, complete, and safe disabled states.

Beta acceptance:

- Transporter and EVS Hummingbird roles receive and complete demo work generated by bed flow.
- Completion feeds Activity and relieves the cockpit state.

#### Staffing

Open work:

- Replace static resource options with real OT, callout, sitter, HPPD, agency, and coverage data where beta uses them.
- Finish staffing alignment wizard/readiness work needed for the demo.
- Support staffing coordinator Hummingbird role, fill requests, safe-minimum alerts, and event relay.

Beta acceptance:

- A staffing gap contributes to house strain and can be assigned/fill-tracked through web/mobile.

#### Improvement / Quality / Process Intelligence

Open work:

- Replace hardcoded root-cause arrays and static process maps with live or clearly seeded data.
- Finish `improvement_opportunities`, `improvement_resources`, CRUD, and PDSA create/advance if used in beta.
- Promote live bottleneck stats into cockpit/study.
- Treat Part X Arena X1-X4 as roadmap unless explicitly enabled.

Beta acceptance:

- PI lead can see a pattern and open a study/workspace view without seeing patient identifiers where not allowed.

#### Analytics / Study

Open work:

- Deduplicate surgical deep dives across Periop and Analytics.
- Gate fallback mocks behind dev/demo mode.
- Retire dead/orphaned analytics pages or route them intentionally.
- Ensure Analytics serves A3 study, not another home.

Beta acceptance:

- A beta demo can move from action to impact measurement or retrospective view without crossing into a stale mock.

#### Service Lines / Financial / Quality Scorecards

Open work:

- Decide which are visible in beta and which are hidden behind demo/live flags.
- Build live MVs or seed honest stubs with provenance.
- Add unique indexes for materialized views refreshed concurrently.

Beta acceptance:

- No service-line or financial card is presented as live if it is seeded or synthetic.

### 6.6 Workstream Z6 - HOSP1 / Summit Demo Data

Goal: one deterministic, internally consistent reference hospital.

Required work:

- Preserve `ZEPHYRUS-500` where it is a durable CAD/join key.
- Use Summit Regional / HOSP1 as the display and demo identity.
- Eliminate Parthenon/Virtua/500-bed display leaks where docs have identified them.
- Align CAD manifest, hospital manifest, prod units, flow spaces, beds, service lines, and demo routes.
- Decide CDU2/516-bed handling before demo scripts rely on it.
- Re-seed demo data for current-day freshness before demos.
- Ensure `zephyrus:demo-seed` lights the full beta demo without ad hoc SQL.

Beta acceptance:

- Demo reset produces stable counts and scenarios.
- No phantom units appear in live demo surfaces.
- Every synthetic value is labeled or intentionally hidden.

---

## 7. Unfinished Work: Patient Flow 4D

### 7.1 Goal

Patient Flow 4D is the spatial-temporal proof that Zephyrus is more than a dashboard. It must show where flow is blocked, why it is blocked, who owns the next move, and what changes if action happens.

### 7.2 Open Work

Required for beta:

- Confirm barrier taxonomy as config, DB, or both.
- Persist occupancy snapshots with detail JSON, scenario/live flag, barrier counts, lineage counts, retention, and pruning.
- Add `GET /api/patient-flow/occupancy/history`.
- Build barrier finder panel with rank, filter, sort, click-to-focus, and accessible scene focus.
- Build grouped disk inspector with occupancy, timers, barriers, owners, next move, source lineage, status, and persona redaction labels.
- Add compact visual legend and tooltips.
- Add named deterministic demo scenarios:
  - `rtdc_barriers`
  - `ed_boarding_surge`
  - `evs_backlog`
  - `or_pacu_hold`
  - `weekend_staffing_gap`
  - `post_acute_discharge_gridlock`
  - `critical_care_outflow`
- Add `GET /api/patient-flow/demo-scenarios`.
- Keep production demo replacement off unless explicitly configured.
- Add persona comparison mode and server-authorized `persona` query behavior.
- Add service-line compounding analytics.
- Add source lineage and confidence to timers, projections, demo data, and real data.
- Add browser smoke, canvas/nonblank, mobile viewport, redaction, and contract tests.

Already treated as baseline:

- Patient Flow 4D navigator exists.
- Shared occupancy/Eddy context service is documented as implemented.
- Flow Window phases 0-5 are documented as shipped for mobile.

### 7.3 Beta Acceptance

- Operator can identify top blocked flow items in under 10 seconds.
- Bed manager can click a barrier row and focus the correct spatial object.
- Inspector answers: duration, origin, next move, active timers, delay reason, owner, impact, confidence, and source.
- Executive/persona-limited views show aggregate pressure without patient identity.
- Eddy can summarize selected barrier context and state uncertainty/source lineage.
- Demo scenarios are repeatable and switchable in dev/demo mode.
- Production can disable demo replacement.

---

## 8. Unfinished Work: Eddy AI Agent

### 8.1 Eddy Doctrine

Eddy is a governed operational assistant. It is not a clinical decision system and not an autonomous operator.

Eddy may:

- read operational context,
- summarize current state,
- cite source/freshness/lineage,
- draft action proposals,
- produce dry-run previews,
- offer runner-up options,
- record rationale,
- learn from human approve/reject/override outcomes.

Eddy must not:

- self-approve,
- execute safety-critical writes directly,
- mutate `prod.*` domain tables directly,
- emit clinical diagnosis or treatment advice,
- send patient-level PHI to cloud models without policy approval and BAA/de-identification controls,
- bypass `OperationalActionLifecycleService`.

### 8.2 Open Control-Plane Work

Required for beta:

- Confirm whether `HasApiTokens` is present and policy-acceptable for Eddy scoped tokens.
- Persist local agent runs to `ops.agent_runs` and `ops.agent_tool_calls`.
- Prove live scoped-token handshake to `ops.actions` draft and `ops.approvals` pending.
- Stand up and document queue worker if local/LLM agent loop runs async.
- Wire `ops_agent` provider policy to the correct local profile.
- Decide direct local loop versus Claude Agent SDK runtime for beta.
- Promote tool-call reliability smoke into repo/CI.
- Add full-stack live E2E for propose -> draft -> approval -> backing service execution.
- Add no-self-approval, no-write-tools, PHI-minimized, prompt-injection, and no-SQL evals.

### 8.3 Open Context Work

Required for beta:

- Ensure cockpit and Eddy read the same snapshot/cache for shared metrics.
- Ensure each Eddy response can cite source, as-of time, and confidence.
- Populate context packets for:
  - cockpit alerts,
  - Patient Flow barriers,
  - bed placement decisions,
  - transport/EVS tasks,
  - staffing gaps,
  - OR delay/PACU pressure,
  - activity events,
  - A2 drills,
  - A2P patient/encounter context where authorized.
- Separate facts from recommendations in model output.
- Ensure stale data causes refusal, downgrade, or explicit caveat.

### 8.4 Open Tool/Action Work

Required for beta:

- Keep read tools mapped to real Laravel service endpoints.
- Add dry-run descriptors for every write tool used in demo.
- Add selected-barrier, all-barriers, and draft-huddle-summary Eddy entry points where scoped.
- Ensure each tool has:
  - minimum role,
  - read/write tier,
  - PHI policy,
  - dry-run output,
  - rollback or non-rollback classification,
  - audit record.
- Keep `action.approve` human-only.

### 8.5 Open Mobile Eddy Work

Required for beta if Hummingbird demo uses Eddy:

- Native surfaces must fetch details on open; push payloads stay PHI-free.
- Eddy context entry appears only where useful and authorized.
- Pending approvals sync on reconnect; approvals are not queued offline.
- Mobile approve/reject/override writes back to the same event/action trail as web.
- Mobile Eddy explains what changed, who acted, what remains blocked, and who should act next.

### 8.6 Beta Acceptance

- Eddy can explain the ED boarding or Patient Flow barrier scenario using live/demo-labeled sources.
- Eddy drafts an action with dry-run preview, rationale, and runner-up.
- User approves, rejects, or overrides.
- Approval executes through existing lifecycle service and backing domain service.
- Every decision is auditable.
- Eddy cannot self-approve, even with a scoped token.
- Safety evals pass.

---

## 9. Unfinished Work: Hummingbird Mobile Coordination App

### 9.1 Mobile Doctrine

Hummingbird is a glance-and-act companion, not a Zephyrus port. It must answer:

- What matters to my role right now?
- What do I need to do next?
- Why does it matter?
- Which patient, bed, room, unit, or task is involved?
- Who else is affected?
- Has the action changed the house state?

### 9.2 Persona Catalog

The beta persona model preserves 14 roles:

1. Charge nurse
2. Bedside nurse
3. Bed manager
4. House supervisor
5. Hospitalist
6. Intensivist
7. Transporter
8. EVS technician
9. OR nurse
10. Capacity lead
11. Perioperative manager
12. Staffing coordinator
13. PI / quality lead
14. Executive

Each persona package must include:

- role-specific Home,
- primary workspace/list,
- For You queue,
- Activity,
- A2 drill,
- A2P patient/encounter lens where authorized,
- inline primary action where safe,
- Eddy entry where useful,
- Open in Zephyrus deep link for study/desktop-grade work.

### 9.3 Open Contract Work

Required for beta:

- Reconcile OpenAPI with Laravel mobile routes.
- Add route/OpenAPI drift test.
- Finalize error codes, retry semantics, 409 behavior, pagination/cursors, and `links.web` map.
- Add missing request bodies for OR safety notes/milestones/case transport if used.
- Add ED signal endpoints if demo requires them.
- Keep shared fixtures for iOS/Android decode tests.

### 9.4 Open Platform Parity Work

Required for beta:

- Finish Android role-derived three-tab shell: Home, For You, Activity.
- Move visible A0/A1/A2/A2P debug navigation out of production UX.
- Finish Android transport and EVS role packages at iOS parity.
- Finish Android house capacity, For You, Activity, and A2/A2P parity.
- Thread A2/A2P/activity into iOS role packages where still missing.
- Hide raw refs, endpoint strings, and implementation vocabulary on both platforms.
- Add persona screenshot matrix for 14 roles across both platforms.

### 9.5 Open Backend Product Specialization

Required for beta:

- Upgrade mobile Home payloads from generic metrics to role-specific composition.
- Move role filtering out of clients and into `MobileForYouService`.
- Add activity-only versus needs-action separation.
- Complete operational activity ledger coverage for every mobile write used in demo.
- Add relay fan-out policy per event type.
- Expand patient/encounter context for OR, staffing dependency, ops approval, huddle/action items, and barriers where allowed.
- Add per-role redaction tests.
- Ensure raw `patient_ref` cannot become a mobile URL detail key.

### 9.6 Open Security, Offline, Push, And Realtime Work

Required for beta:

- Encrypted token storage on Android.
- Biometric or passcode lock where PHI/detail access exists.
- Idle auto-lock.
- App background privacy screen and Android `FLAG_SECURE` policy where appropriate.
- Logout token revoke parity.
- No PHI/tokens in logs.
- PHI-free push payload linter.
- Android FCM token registration and notification channels.
- Real APNs/FCM credentials or documented demo substitute.
- Server-controlled quiet hours and notification budgets.
- Reverb snapshot-on-reconnect behavior.
- Stale badges on both platforms.
- Local cache strategy with per-user clearing and visible staleness.
- Outbox only for non-critical writes.
- Safety-critical writes blocked offline with explicit reason.
- 409 conflict UX on both platforms.

### 9.7 Beta Acceptance

- Every demo persona lands on role-specific Home on iOS and Android.
- For You ordering and action behavior match against the same seeded backend state.
- Activity relay appears on both platforms with the same PHI policy.
- A2 and A2P work from every relevant demo item.
- Android no longer feels like a generic altitude/debug explorer in normal use.
- iOS keeps its role-package elegance while absorbing A2/A2P/activity.
- Every mobile write records one operational event.
- Both platforms pass build, backend, contract, PHI, and persona screenshot checks.

---

## 10. Integration, Provenance, And Trust Work

### 10.1 Required Beta Trust Contract

Every cockpit, mobile, and Eddy hero signal must expose:

- source system or demo source,
- as-of time,
- refresh interval,
- freshness/stale state,
- status edge/threshold,
- live versus synthetic versus seeded state,
- confidence where projected,
- source lineage where derived,
- fallback behavior when degraded.

### 10.2 Open Integration Work

From the real-time healthcare acquisition plan, carry forward:

- source registry,
- credential lifecycle,
- ingest runs,
- raw inbound ledger,
- canonical events,
- projection offsets,
- dead-letter queue and admin visibility,
- provenance per metric,
- replay/reprojection path,
- FHIR/HL7/ADT coverage for beta flows,
- integration health summary.

### 10.3 Beta Acceptance

- Integration Health is visible and not a placeholder.
- Dead or stale feeds degrade surfaces visibly.
- Eddy cites feed freshness when recommending actions.
- Demo mode cannot be confused with a live source feed.

---

## 11. Deployment And Operations Work

### 11.1 Manual Deployment Rule

Production deployment remains manual-only through `./deploy.sh`. GitHub Actions must not deploy production.

The deploy script does not run migrations. Any schema-bearing release requires explicit migration execution after deploy.

### 11.2 Open Operations Work

Required for beta:

- Document exact demo reset:
  - pull/current branch state,
  - migrate if needed,
  - `zephyrus:demo-seed`,
  - patient-flow imports/scenarios,
  - facility/model checks,
  - mobile BFF checks.
- Verify scheduler trigger exists for all scheduled snapshot/refresh jobs.
- Verify `BROADCAST_CONNECTION=reverb` wherever live broadcasts are expected.
- Verify Reverb/proxy or fallback behavior for web and mobile.
- Verify queue worker if Eddy or async jobs depend on it.
- Document host/service units for Eddy local agent if enabled.
- Document disabled/default flags:
  - `EDDY_ENABLED`,
  - `EDDY_PUSH_ENABLED`,
  - `ARENA_ENABLED`,
  - demo scenario flags,
  - wall/display behavior.

### 11.3 Beta Acceptance

- A fresh demo environment can be rebuilt from documented commands.
- Route smoke, build, migrations, scheduler, Reverb/fallback, mobile BFF, and Eddy health checks are all captured.
- No beta demo depends on unrecorded manual SQL or an invisible local process.

---

## 12. Beta Roadmap

The roadmap below is execution-oriented. Phases can be broken into smaller PRs, but the acceptance gates must remain intact.

### B0 - Documentation And Contract Freeze

Purpose: stop planning drift before implementation.

Deliverables:

- Adopt this PRD as canonical.
- Add links from relevant README/docs index if desired.
- Reconcile OpenAPI/Laravel route inventory for Hummingbird.
- Create route/OpenAPI drift test.
- Decide beta synthetic/live visibility rules.
- Decide old overview route redirect/rollback strategy.
- Verify current code against doc assumptions before coding.

Exit:

- One source-of-truth order is agreed.
- OpenAPI, route inventory, and this PRD agree on beta surfaces.

### B1 - Demo Data And Trust Foundation

Purpose: make HOSP1/Summit deterministic and honest.

Deliverables:

- Demo reset runbook.
- HOSP1 naming and unit/space/service alignment.
- Metric provenance labels.
- Synthetic/liveness flags.
- Core scenario data: ED boarder, ICU downgrade, OR delay/PACU, discharge barrier, EVS backlog, transport wait, staffing gap, stale feed.
- Integration Health and data freshness visible.

Exit:

- Demo can be seeded without ad hoc SQL.
- All beta hero metrics show source/freshness/provenance.

### B2 - Cockpit Foundation

Purpose: unify status, snapshot, metrics, and cockpit API.

Deliverables:

- `StatusEngine`.
- `MetricValue`.
- Snapshot builder/cache/API.
- Alert derivation.
- Cockpit schema and client validation.
- Shared status rendering.

Exit:

- `/api/cockpit/snapshot` drives the cockpit.
- Eddy and mobile aggregate views can reference the same truth.

### B3 - Unified Web Experience

Purpose: ship the single web home and deterministic descent.

Deliverables:

- `/dashboard` cockpit.
- Wall mode.
- Drill modal and `?drill=`.
- Navigation restructuring.
- Route redirects/repoints.
- App-shell convergence or staged shell plan.
- ChangePasswordModal app-wide.

Exit:

- One home, one nav source, one descent path.
- Route smoke remains green.

### B4 - Patient Flow 4D And Eddy Barrier Intelligence

Purpose: make spatial flow operationally actionable.

Deliverables:

- Barrier taxonomy.
- Barrier finder.
- Grouped inspector.
- Demo scenarios.
- Snapshot/history endpoint.
- Eddy structured context and selected/all-barrier prompts.
- Persona redaction and lineage.

Exit:

- Demo user can identify, inspect, explain, and act on a flow barrier.

### B5 - Governed Eddy Action Loop

Purpose: prove AI-assisted execution with human control.

Deliverables:

- Scoped-token handshake.
- Run/tool persistence.
- Dry-run previews.
- Action draft -> approval -> execution flow.
- Local/LLM loop gated and tested if enabled.
- Eval suite: no self-approval, PHI minimized, prompt-injection, no SQL, no-write-tools.
- Mobile Eddy approval/fetch-on-open path if used in demo.

Exit:

- Eddy drafts a recommendation, user approves/rejects/overrides, action lifecycle records outcome.

### B6 - Hummingbird Demo Parity

Purpose: show that the operational loop reaches the people who act.

Deliverables:

- Android role-package parity for demo roles.
- iOS A2/A2P/activity threading where missing.
- Server-side For You filtering.
- Operational event ledger for demo writes.
- Push or demo-safe fetch-on-open path.
- Offline/stale/409 safety behavior.
- Persona screenshot matrix.

Exit:

- Each demo scenario crosses web -> mobile -> web without changing truth.

### B7 - Domain Completion And Study Handoff

Purpose: fill the credibility gaps across functional areas.

Deliverables:

- ED signals and boarder flow.
- Periop fixes and PACU/OR delay path.
- Transport/EVS live wait/turnaround.
- Staffing safe-minimum and fill path.
- Improvement/PI study handoff.
- Analytics dedupe and dev/mock gating.

Exit:

- Every demo functional area is either live-backed or honestly marked as seeded/synthetic.

### B8 - Beta Hardening And Demo Package

Purpose: make beta demonstrable, testable, and repeatable.

Deliverables:

- End-to-end demo scripts.
- Screenshots/GIFs/videos by scenario/persona.
- Build/test/canon/PHI gates.
- Deployment and rollback notes.
- Known limitations list.
- Beta release checklist.

Exit:

- A reviewer can run the demo track and inspect evidence without tribal knowledge.

---

## 13. Demo Track

### 13.1 Demo Principles

The demo must show execution. Every scenario must include:

- trigger,
- status change,
- drill/explanation,
- recommendation,
- human decision,
- task assignment,
- mobile action,
- activity relay,
- state improvement or explicit non-improvement,
- study/impact handoff,
- provenance and audit.

### 13.2 Scenario 1 - House Executive Glance

Actors:

- Executive
- Capacity lead
- House supervisor

Surfaces:

- Zephyrus `/dashboard`
- Wall mode
- Executive/capacity Hummingbird Home if in scope

Story:

1. User opens `/dashboard`.
2. Cockpit shows calm baseline plus selected earned exceptions.
3. Executive sees house status, ED strain, staffing, OR/PACU pressure, flow blockers, and freshness.
4. User opens wall mode and sees the same truth without chrome.
5. User opens an executive drill and sees source, threshold, as-of time, and current blockers.

Acceptance:

- No scroll on primary cockpit.
- Status is shape plus color plus label.
- Synthetic fields are labeled.
- Stale feed behavior is visible if demoed.

### 13.3 Scenario 2 - ED Boarder To Inpatient Bed

Actors:

- Executive
- Capacity lead
- Bed manager
- Charge nurse
- EVS
- Transport
- Bedside nurse
- Eddy

Surfaces:

- Cockpit ED/RTDC drill
- Bed placement workspace
- Patient Flow 4D
- Hummingbird bed manager, charge nurse, EVS, transport
- Eddy dock/mobile Eddy

Story:

1. ED boarder raises house strain.
2. Capacity lead drills into ED boarding pressure.
3. Bed manager opens pending bed request and A2P context.
4. Eddy explains the placement bottleneck and drafts a bed placement or surge action with runner-up.
5. Bed manager approves or overrides.
6. Charge nurse receives inbound placement readiness task.
7. EVS receives bed-turn task if needed.
8. Transport receives pickup/move timing.
9. Bedside nurse sees incoming patient operational context.
10. Cockpit shows improved or pending state; Activity records each action.

Acceptance:

- No raw patient identifiers in push/list payloads.
- Bed placement action routes through lifecycle service.
- Hummingbird and Zephyrus counts agree.
- Eddy cites current snapshot/event trail.

### 13.4 Scenario 3 - ICU Downgrade Unlocks Capacity

Actors:

- Intensivist
- Hospitalist
- Bed manager
- Charge nurse
- Transport
- Capacity lead
- Executive
- PI lead

Story:

1. Intensivist or hospitalist marks downgrade/readiness.
2. Bed manager sees capacity opportunity.
3. Charge nurse sees receiving-unit dependency.
4. Transport receives move when ready.
5. Capacity lead sees critical-care pressure improve.
6. Executive sees only threshold-crossing status changes.
7. PI lead later sees recurring downgrade delay pattern.

Acceptance:

- Patient depth follows role authorization.
- Service-scope access gaps are either resolved or explicitly blocked with a 403/disabled state.
- Activity relay reaches affected roles.

### 13.5 Scenario 4 - OR Delay Creates Bed And Staffing Pressure

Actors:

- OR nurse
- Perioperative manager
- Bed manager
- Staffing coordinator
- Capacity lead
- Eddy

Story:

1. OR delay or PACU hold appears.
2. Periop manager opens delay drill.
3. Cockpit shows downstream bed/staffing pressure.
4. Staffing coordinator sees evening coverage risk.
5. Bed manager adjusts bed flow forecast.
6. Eddy drafts options and records human decisions.

Acceptance:

- OR backend timing/status bugs are fixed or scenario is not used.
- PACU/OR delay signal is not an unverified mock.
- Downstream impact is explainable.

### 13.6 Scenario 5 - Discharge Barrier Resolution

Actors:

- Hospitalist
- Bedside nurse
- Charge nurse
- Bed manager
- EVS
- Transport
- PI lead
- Eddy

Story:

1. Discharge barrier is identified in cockpit/Patient Flow/Hummingbird.
2. Eddy explains why it matters and who owns the next move.
3. Authorized user resolves or advances the barrier.
4. EVS/transport work queues update.
5. Bed manager sees bed release forecast improve.
6. PI lead later reviews aggregate pattern.

Acceptance:

- Barrier taxonomy is canonical.
- Source lineage and confidence are visible.
- The action emits an operational event.

### 13.7 Scenario 6 - Staffing Gap And Safe Capacity

Actors:

- Staffing coordinator
- Charge nurse
- Capacity lead
- Executive

Story:

1. Unit falls below minimum-safe staffing.
2. Cockpit and staffing Hummingbird Home show the gap.
3. Coordinator opens fill request and assigns resource.
4. Charge nurse sees updated staffing state.
5. Capacity lead sees safe capacity change.

Acceptance:

- Staffing gap data is live or clearly seeded.
- Mobile fill action is audited and reflected in web.

### 13.8 Scenario 7 - Regional Transfer / External Demand

Actors:

- Transfer center / capacity lead
- Executive
- Eddy

Story:

1. External transfer pressure appears.
2. User compares destination/route scenarios.
3. Eddy drafts recommendation without executing.
4. Human accepts, redirects, or defers.
5. Decision records audit event and affects capacity forecast.

Acceptance:

- Recommendations are graph-backed and auditable.
- Draft-only AI boundary holds.

### 13.9 Scenario 8 - Improvement And Study Handoff

Actors:

- PI lead
- Executive
- Operational owner

Story:

1. Repeated barrier or delay pattern appears after operational scenarios.
2. PI lead opens Study/A3 view.
3. Opportunity/PDSA or Arena roadmap surface explains pattern.
4. User assigns or drafts improvement action.

Acceptance:

- Study view is not a fake home.
- Patient identifiers are suppressed where not needed.
- Impact/balancing measures are shown or explicitly future-scoped.

### 13.10 Scenario 9 - Trust, Provenance, And Degraded Feed

Actors:

- Admin
- Executive
- Eddy

Story:

1. Data feed becomes stale or demo feed is selected.
2. Cockpit shows stale/degraded/demo state.
3. Eddy cites freshness and refuses or caveats unsafe recommendation.
4. Admin opens Integration Health.

Acceptance:

- No silent stale state.
- No AI answer that ignores stale/degraded source.

---

## 14. Beta Validation Gates

### 14.1 Required Automated Gates

Before beta:

- `php artisan test`
- `php artisan test --filter=RouteSmokeTest`
- `npx tsc --noEmit`
- `npx vite build`
- `scripts/check-ui-canon.sh`
- Targeted cockpit/status/snapshot/alert tests
- Targeted Eddy no-self-approval and PHI evals
- Targeted mobile BFF conformance tests
- OpenAPI/route drift test
- Patient Flow redaction and contract tests
- Android build and iOS build for Hummingbird demo scope

### 14.2 Required Manual/Visual Gates

- Cockpit desktop screenshot.
- Cockpit wall screenshot.
- Light mode and dark mode pass.
- Mobile small-device and large-text pass.
- Hummingbird persona screenshot matrix for beta personas, with full 14-role matrix if feasible.
- Patient Flow 4D nonblank/canvas/framing/interaction check.
- Reduced-motion check for animated replay/ticker.
- PHI review for push, Activity, list rows, logs, screenshots, and Eddy prompts.
- Demo script rehearsal from fresh seed.

### 14.3 Beta Definition Of Done

Zephyrus 2.0 beta is done when:

- `/dashboard` is the single cockpit home.
- The cockpit, Eddy, and Hummingbird demo surfaces share the same status/snapshot truth.
- Every beta abnormality drills to source, owner, freshness, and next move.
- Eddy drafts governed actions and cannot self-approve.
- Hummingbird receives and completes role-appropriate work using the same backend contracts.
- Every mobile write used in demo emits an operational event.
- HOSP1/Summit demo data is deterministic and internally consistent.
- Synthetic/demo state is disclosed.
- Auth system remains intact.
- Route smoke, build, canon, PHI, and mobile platform gates pass.
- A complete demo track proves visibility -> recommendation -> human decision -> action -> outcome.

---

## 15. Open Decision Register

These decisions must be resolved explicitly before beta cutover if they affect the demo:

| ID | Decision | Default for beta |
|---|---|---|
| D1 | Which domains may remain synthetic? | Only non-hero scorecards; disclose or hide. |
| D2 | CDU2 / 500 versus 516 bed story | Use HOSP1 display truth consistently; do not mix counts in demo. |
| D3 | Old dashboard rollback strategy | Redirect with rollback path for one release if practical. |
| D4 | Service-scope patient access for hospitalist/intensivist | 403/disabled if not implemented; do not overexpose. |
| D5 | Barrier taxonomy storage | Config first is acceptable; DB-backed if editing/admin required. |
| D6 | Demo scenarios storage | API/config registry first; database rows only when needed for reproducibility. |
| D7 | Production demo replacement mode | Off by default; additive overlays only with explicit config. |
| D8 | Eddy local runtime | Gated local loop acceptable; production enablement requires persistence, queue, policy, evals. |
| D9 | Cloud model use | Aggregate only unless BAA/de-id and policy permit patient context. |
| D10 | APNs/FCM credentials | Required for real push demo; otherwise fetch-on-open/manual refresh must be disclosed. |
| D11 | Part X Arena in beta | Roadmap/reference only unless explicitly promoted. |
| D12 | Native 4D viewer in beta | Not required; Flow Window and web 4D carry beta unless promoted. |

---

## 16. First Ten PRs

This sequence minimizes drift and creates usable proof quickly.

1. PRD adoption and docs index update.
2. Hummingbird route/OpenAPI drift test and contract cleanup.
3. HOSP1 demo reset/provenance hardening.
4. StatusEngine, MetricValue, and client status mirror.
5. Cockpit snapshot API/cache and shared freshness/provenance.
6. `/dashboard` cockpit shell with command/census/OKR/panel layout.
7. Unified drill modal and `?drill=` route state.
8. Patient Flow barrier taxonomy, finder, and grouped inspector.
9. Eddy scoped-token run persistence and no-self-approval proof.
10. Hummingbird Android role-shell/Transport/EVS/House parity for demo roles.

After these, continue with domain-specific demo scenarios and beta hardening.

---

## 17. Traceability Matrix

| Beta requirement | Source docs carried forward |
|---|---|
| One cockpit home and altitude IA | `docs/product/ZEPHYRUS-2.0-PLAN.md`, cockpit implementation spec |
| Object-centric process roadmap | `docs/product/ZEPHYRUS-2.0-PART-X.md` |
| Eddy governed action loop | `docs/plans/EDDY-AI-AGENT-PLAN.md`, `docs/plans/EDDY-LOCAL-AGENT-PATH.md`, Flow OS devlogs |
| Patient Flow barriers and Eddy context | `2026-07-05-patient-flow-4d-barrier-eddy-implementation-plan.md` |
| Hummingbird parity | `PLATFORM-RECONCILIATION-TODO.md`, `ALTITUDE-PERSONA-OPERATING-PLAN.md`, mobile API docs |
| Mobile Flow Window baseline | `FLOW-WINDOW-PLAN.md` |
| HOSP1/Summit demo | `docs/plans/HOSPITAL-1-SUMMIT-REGIONAL-PLAN.md`, `docs/plans/HOSPITAL-1-EXECUTION-INTEL.md` |
| Service-line/location deployment | `docs/architecture/SERVICE-LINE-LOCATION-DEPLOYMENT-TAXONOMY-2026-07-04.md`, implementation plan |
| Demo walkthrough and differentiation | 90-day demo walkthrough, competitor capability scorecard, Flow OS leapfrog plans |
| UI/canon/density | UI consistency devlog, UX density plan, Zephyrus 2.0 design chapters |
| Integration trust | real-time healthcare data acquisition plan, Integration Health docs/signals |

---

## 18. Final North-Star Statement

Zephyrus 2.0 beta is successful when it no longer feels like a set of related hospital dashboards. It must feel like one operational instrument: a calm cockpit that detects strain, a spatial flow view that reveals blockers, a governed agent that drafts but does not execute, a mobile companion that sends the work to the right role, and a study layer that proves whether the action mattered.

Every future tranche should be judged against that standard.
