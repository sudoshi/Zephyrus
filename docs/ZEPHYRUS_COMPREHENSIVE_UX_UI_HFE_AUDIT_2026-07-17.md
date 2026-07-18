# Zephyrus Comprehensive UX/UI and Healthcare Human-Factors Audit

**Application:** `https://zephyrus.acumenus.net`  
**Assessment date:** July 17, 2026  
**Assessment type:** Authenticated production-surface heuristic review, route smoke test, responsive review, accessibility-oriented DOM inspection, and console-error review  
**Authenticated role observed:** Administrative/operational user with access to Cockpit, Workspaces, Study, and Administration  
**Coverage:** 87 authenticated routes or distinct application surfaces, all primary navigation menus, all nine dashboard drill-downs, desktop and mobile-size layouts, and representative interactive controls  
**Primary emphasis:** Redundant functionality, missing functionality, dead or broken navigation, healthcare human-factors engineering (HFE), excessive whitespace, and inadequate data density

---

## 1. Purpose and intended use

This document is a remediation-oriented UX/UI audit of the current Zephyrus web application. It is not a visual-style critique alone. Zephyrus presents operational information that can influence bed placement, staffing, patient flow, emergency department operations, perioperative operations, ancillary services, and governed approvals. In this setting, usability defects can become safety defects when they obscure data freshness, present contradictory operational states, make actions ambiguous, or overload a user during time-sensitive work.

The audit therefore evaluates each section and page against five questions:

1. **Can the user reach and understand the page?** This includes navigation, dead links, broken assets, loading states, and route identity.
2. **Is the functionality distinct and necessary?** This includes duplicated pages, overlapping concepts, ambiguous labels, and multiple surfaces that claim to represent the same state.
3. **Is critical functionality missing?** This includes absent recovery, provenance, status explanation, filtering, confirmation, ownership, and escalation mechanisms.
4. **Does the page respect healthcare HFE?** This includes situational awareness, alarm fatigue, temporal clarity, cognitive load, predictable controls, accessibility, safe action design, and prevention of wrong-patient/wrong-unit decisions.
5. **Does the layout use screen space appropriately?** This includes excessive blank regions, insufficient data density, over-dense unstructured content, poor responsive behavior, and inability to compare related information without scrolling.

Observed defects are separated from design risks. A route that loaded successfully is not automatically considered usable or safe.

---

## 2. Severity model

| Priority                            | Meaning                                                                                                                                                          | Expected response                                         |
| ----------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------- |
| **P0 — Safety blocker**             | A condition that could directly support a wrong operational or patient-flow decision, exposes uncontrolled PHI, or makes a safety-critical action irrecoverable. | Disable or isolate the affected workflow until corrected. |
| **P1 — Release blocker**            | Core functionality is broken, contradictory, inaccessible on a supported device, or cannot recover from failure.                                                 | Correct before broader release or operational use.        |
| **P2 — Major usability/HFE defect** | Functionality works but creates high cognitive load, ambiguity, poor accessibility, low trust, or inefficient task completion.                                   | Correct in the next planned release.                      |
| **P3 — Moderate improvement**       | The experience is usable but inconsistent, redundant, space-inefficient, or missing useful guidance.                                                             | Address during consolidation and design-system work.      |
| **P4 — Polish/technical debt**      | Minor visual, wording, or implementation issues that do not materially block a workflow.                                                                         | Resolve opportunistically.                                |

---

## 3. Executive summary

Zephyrus has broad functional coverage and a coherent dark visual theme, but it currently behaves like several operational products assembled under one shell rather than one carefully governed clinical-operations system. The most serious problems are not cosmetic:

- Patient Flow 4D is visibly broken because the deployed hospital model asset returns `404 Not Found`.
- Turnover Times fails through an error boundary because `turnoverDistribution` is undefined.
- The dashboard Action Inbox and full Agent Inbox disagree about whether governed approvals and actions exist.
- Contextual Eddy exposes raw internal context, submits successfully, and then stalls without a response, timeout, retry, or error state.
- Mobile-size layouts horizontally overflow and clip essential controls on the dashboard, Virtual Rounds, and Patient Flow 4D.
- Long-expired alerts remain presented as active, including alerts more than 300 hours old, which is incompatible with time-sensitive operational cognition.
- Multiple pages repeat closely related concepts—resources, predictions, utilization, flow, bottlenecks, PDSA work, action queues—without a clear canonical source or handoff model.
- Several approval and filter controls have ambiguous accessible names, increasing the chance of selecting the wrong action or scope.
- Synthetic and stale data is labeled, but the labels are not always prominent enough to prevent a user from interpreting demo information as live operational truth.

### 3.1 Highest-priority findings

| ID           | Priority | Finding                                                               | Evidence                                                                                                                                             | Required outcome                                                                                                                                |
| ------------ | -------- | --------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| **PF4D-01**  | P1       | Hospital model is missing in both Patient Flow entry points.          | `/vendor/zephyrus-facility-models/zep-500/hospital_model.glb` returns `404`; UI displays **Model failed to load**.                                   | Deploy/version the model asset, add integrity checks, and provide a functional fallback that clearly states which capabilities are unavailable. |
| **ANA-01**   | P1       | Turnover Times crashes.                                               | `/analytics/turnover-times` renders **Something went wrong**; console: `Cannot read properties of undefined (reading 'turnoverDistribution')`.       | Handle absent datasets, validate the response contract, and render a meaningful empty/error state.                                              |
| **GOV-01**   | P1       | Governed action state contradicts itself.                             | Dashboard shows 8 pending approvals and active drafts; `/ops/agent-inbox` shows 0 pending approvals and 0 active actions.                            | Establish one authoritative action store and display source, scope, and last synchronization time on both surfaces.                             |
| **EDDY-01**  | P1       | Contextual Eddy can enter a permanent blank state.                    | After submission, the response remained blank for more than 14 seconds; Send was disabled and no progress, cancel, error, or retry control appeared. | Add progress, cancellation, timeout, error classification, retry, and preserved-draft behavior.                                                 |
| **RESP-01**  | P1       | Core pages are unusable at mobile size.                               | Dashboard, Virtual Rounds, and Patient Flow 4D showed horizontal overflow and clipped controls; measured document width exceeded the viewport.       | Define supported breakpoints and create task-specific mobile layouts rather than compressing desktop boards.                                    |
| **TIME-01**  | P1       | Active alerts include extremely stale conditions.                     | Dashboard displayed alerts aged approximately 145, 165, and 308 hours as active.                                                                     | Add expiration, acknowledgement, ownership, escalation, and stale-state suppression rules.                                                      |
| **EDDY-02**  | P2       | Internal prompt and JSON are exposed to end users.                    | Patient Flow’s Eddy composer showed the entire governed context and instruction prompt; the detail panel rendered serialized `timers` JSON.          | Convert structured context into concise, human-readable evidence and hide implementation instructions.                                          |
| **VR-01**    | P2       | Virtual Rounds run selection is overloaded with cancelled duplicates. | Unit run selectors showed dozens of near-identical cancelled runs alongside the active run.                                                          | Default to active/recent runs, archive cancelled runs, and add status/date filters.                                                             |
| **A11Y-01**  | P2       | Governed action buttons are ambiguous.                                | Eight separate actions expose identical **Approve** and **Reject** accessible names.                                                                 | Include the action subject in each accessible name and require an explicit review step before approval.                                         |
| **SCOPE-01** | P2       | Filters and headings are semantically entangled.                      | Service Huddle filters are nested inside a heading; its accessible name includes every unit and service option.                                      | Separate headings from controls and provide explicit labels and scope summaries.                                                                |

---

## 4. System-wide UX and HFE findings

### 4.1 Information architecture and redundant functionality

The top-level information architecture is understandable—Cockpit, Workspaces, and Study—but the content underneath has considerable conceptual overlap.

#### Redundant or insufficiently differentiated concepts

| Concept                | Overlapping surfaces                                                                                                                                                                | Risk                                                                                                  | Recommendation                                                                                                                                   |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| Patient flow           | Dashboard Flow & Transport drill-down, ED Patient Flow, RTDC Patient Flow 4D, Bed Tracking, Bed Placement, Virtual Rounds, Unit Huddle, Service Huddle, Global Huddle               | Users can obtain different counts, clocks, and scopes without knowing which surface is authoritative. | Define a canonical patient-flow data model and make every secondary view disclose its scope, as-of time, and relationship to the canonical view. |
| Resource planning      | ED Operations Resources, ED Prediction Resources, ED Analytics Resources, Perioperative Resource Planning, RTDC Prediction Resources, RTDC Analytics Resources, Transport Resources | Repeated **Resources** labels are indistinguishable in search and navigation.                         | Rename by decision intent: **Current ED Coverage**, **Forecast ED Demand**, **Historical ED Resource Performance**, etc.                         |
| Demand                 | Dashboard Demand & Capacity, Perioperative Demand Analysis, RTDC Demand Forecast, ED Arrival Prediction, predictive planning                                                        | The word “demand” changes meaning by service and time horizon.                                        | Include domain and horizon in every title and navigation label.                                                                                  |
| Utilization            | Block, OR, Primetime, IR, RTDC, Modality, dashboard Perioperative utilization                                                                                                       | Many pages appear as separate destinations even when they are likely slices of one analysis task.     | Use a consolidated Utilization workspace with domain tabs and persistent filters.                                                                |
| Process improvement    | Bottlenecks, Process Analysis, Root Cause, Active Cycles, PDSA Cycles, Opportunity Portfolio, Process Intelligence, Retrospective Review                                            | The workflow from finding a problem to managing an intervention is unclear and duplicated.            | Create a single improvement lifecycle: detect → validate → prioritize → design intervention → approve → measure → sustain.                       |
| Governed action queues | Dashboard Action Inbox, full Agent Inbox, Opportunity Portfolio, active actions, Eddy recommendations                                                                               | State is duplicated and currently contradictory.                                                      | Create one canonical queue with filtered projections, not separate stores or independently calculated counts.                                    |
| Executive information  | Cockpit Executive view, Executive OKR drill-down, Executive Brief page, Executive Briefing Agent                                                                                    | Multiple executive summaries compete for attention.                                                   | Consolidate into an Executive workspace with brief, OKRs, decisions, and provenance as tabs.                                                     |

#### Navigation naming defects

- **Resources** appears in multiple workspaces without domain or time-horizon context.
- **Patient Flow** and **Patient Flow 4D** appear in different workspaces but share a rendering substrate.
- **Active Cycles** and **PDSA Cycles** are not clearly differentiated.
- **Analytics** routes often retain the generic H1 **Operations Intelligence**, which weakens orientation after navigation.
- **Service Line** appears as a disabled “soon” Cockpit option while Service Lines already exists as a dashboard drill-down and Service Huddle exists in RTDC.
- Search can find destinations, but the result label alone may not reveal the workspace or scope.

### 4.2 Missing global functionality

The following capabilities should be available consistently across the application but are missing, incomplete, or inconsistently implemented:

1. **Canonical scope banner:** facility, unit, service line, department, patient cohort, and time window should be visible and persistent.
2. **Data provenance drawer:** source system, last successful observation, transformation status, synthetic/live status, and exclusions.
3. **Stale-data behavior:** stale information should degrade in a consistent way; critical actions should be disabled or require acknowledgement when evidence is stale.
4. **Error recovery:** every asynchronous surface needs retry, cancellation, support details, preserved user input, and a safe fallback.
5. **Saved views and role presets:** operational users should not repeatedly reconstruct the same filters during huddles or shift changes.
6. **Cross-surface deep links:** an alert, metric, or recommendation should open the same scoped entity in the canonical workspace.
7. **Audit-friendly action review:** governed actions need owner, rationale, evidence, scope, expiry, rollback plan, and before/after state.
8. **Consistent empty states:** distinguish zero, unknown, unavailable, excluded, not configured, and still loading.
9. **Comparison support:** current versus target, prior shift, prior day, and forecast should use consistent visual grammar.
10. **Shift-handoff state:** users need acknowledged, unresolved, newly changed, and handed-off markers.
11. **Notification prioritization:** alert severity should combine clinical/operational impact, confidence, recency, ownership, and actionability.
12. **Keyboard and assistive-technology validation:** accessible names must include the object being acted upon.

### 4.3 Healthcare human-factors engineering gaps

#### Temporal cognition

- “Updated just now” coexists with alerts hundreds of hours old.
- Relative times are sometimes verbose down to seconds, e.g. multi-day durations rendered as hundreds of hours plus minutes and seconds. This is precise but not cognitively useful.
- Patient Flow replay presented inconsistent “now minus” timing relative to its displayed timestamp.
- Several pages use synthetic data with dates that can look operational unless the user notices the source label.

**Required pattern:** Show `as of`, source freshness, time zone, expected refresh cadence, and clinical/operational relevance. Use human-readable durations—“12 days old”—with exact timestamps available on demand.

#### Alarm fatigue and prioritization

- The dashboard presents many critical and warning alerts simultaneously.
- Old alerts remain active rather than transitioning to expired, acknowledged, unresolved, or historical states.
- The same alert content appears in repeated DOM content, which may create duplicate announcements for assistive technology.
- Severity colors are visually prominent, but severity does not clearly incorporate confidence or actionability.

**Required pattern:** Rank by actionability and time sensitivity, group duplicates, identify the accountable role, and suppress or archive expired conditions.

#### Safe actions and wrong-context prevention

- Approve/Reject buttons do not include the proposal name in their accessible label.
- Virtual Rounds presents multiple unit, template, and run selectors in a single row; cancelled runs are visually mixed with the active run.
- Patient Flow contains two controls named **Barriers**, increasing the risk of toggling the wrong layer or filter.
- Scope changes can materially change the operational picture without a persistent “you are viewing” banner.

**Required pattern:** Every action must state object, scope, expected effect, source freshness, and reversibility. High-risk actions should use a review screen rather than immediate inline approval.

#### Trust, provenance, and synthetic data

- Transport and Staffing prominently report stale synthetic data, which is good, but synthetic state is not equally prominent everywhere.
- Eddy’s context used an older snapshot and different counts than the visible Patient Flow surface.
- Raw structured context is visible to users without an explanation of which facts are verified versus inferred.

**Required pattern:** Use a consistent trust badge system: **Live**, **Delayed**, **Stale**, **Synthetic**, **Inferred**, **Unavailable**, and **User-entered**. Each badge should open evidence and limitations.

### 4.4 Excessive whitespace and insufficient data density

Zephyrus has both under-dense and over-dense surfaces.

#### Under-dense examples

- Turnover Times leaves most of the content canvas blank after the error boundary fires.
- Several analytics pages place a generic heading above large cards without a compact summary of the current question, scope, or key result.
- Some drill-downs use large modal framing for relatively small metric sets.
- Mobile views waste the narrow viewport on desktop-scale padding, fixed panels, and floating Eddy controls.

#### Over-dense examples

- The dashboard presents many alerts, KPI strips, eight operational cards, and an OKR scorecard in one continuous scroll without a clear “what changed since last review” layer.
- Virtual Rounds places unit/template/run controls, queue table, patient detail, completion requirements, contributions, and contribution entry on one page.
- Patient Flow 4D combines timeline, filters, layers, scene, recent events, details, timers, and Eddy in three competing columns.
- Service Huddle exposes broad unit/service option lists and several metric sections without strong prioritization.

#### Data-density principle

The goal should not be “more data per pixel.” The goal should be **more decision-relevant information per unit of attention**. Recommended hierarchy:

1. Current state and scope.
2. What changed.
3. What requires a decision.
4. Evidence and confidence.
5. Recommended owner and time window.
6. Supporting detail on demand.

### 4.5 Responsive and device-support defects

At a mobile-size viewport, the dashboard, Virtual Rounds, and Patient Flow 4D produced document widths greater than the available viewport and displayed horizontal scrollbars.

Specific failures:

- Right-side top-navigation controls were clipped.
- Dashboard alert strips extended far outside the viewport.
- Virtual Rounds unit and run values were truncated without an alternate detail view.
- The rounds queue table could not be read as a coherent row.
- Patient Flow’s control and detail panels consumed the available width, leaving the scene and content difficult to use.
- The floating Eddy button overlapped core content.

**Recommendation:** Do not force every desktop command-center feature into a phone layout. Define supported mobile tasks—acknowledge alert, review queue, contribute to a round, view one patient/unit, approve a governed action—and build focused mobile presentations for those tasks.

---

## 5. Page-by-page audit

### 5.1 Authentication and global application shell

| Page/surface     | Route                             | Observed state                                                           | UX/HFE issues and missing functionality                                                                                                                                                                                                                        | Priority recommendation                                                                                                |
| ---------------- | --------------------------------- | ------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| Zephyrus sign-in | `/login`                          | Loaded with local username/password and Authentik SSO options.           | Two authentication paths are presented without explaining which users should use which method. The marketing-heavy left panel competes with the login task and increases whitespace. No visible environment indicator distinguishes demo, test, or production. | P3: Make Authentik the primary enterprise path, explain fallback credentials, and add environment/support information. |
| Authentik SSO    | `/auth/oidc/redirect` → Authentik | Chrome completed SSO through an existing Authentik session.              | Identity-provider transition changes branding and layout. The Zephyrus login page should set expectations about redirect and account recovery ownership.                                                                                                       | P3: Add concise SSO guidance and return-path messaging.                                                                |
| Global header    | All authenticated routes          | Cockpit, Workspaces, Study, Search, theme, and user menu were available. | On desktop, categories are clear; on mobile, right-side controls are clipped. Current page/workspace is not always obvious because many pages have generic headings. Floating Eddy competes with content and can obscure mobile actions.                       | P1 mobile; P3 desktop: define responsive header behavior and stronger location breadcrumbs.                            |
| Command palette  | Global                            | Search found and opened Virtual Rounds.                                  | Search results need workspace/domain labels, synonyms, recent destinations, and scope. Generic terms such as Resources or Patient Flow are ambiguous.                                                                                                          | P2: Display breadcrumb, purpose, and domain in every result.                                                           |
| Theme toggle     | Global                            | Light/dark switch worked and was reversible.                             | Status/color semantics must remain distinguishable in both themes and cannot rely on color alone.                                                                                                                                                              | P3: Add automated contrast and non-color status tests.                                                                 |
| User menu        | Global                            | Profile and six administration destinations loaded.                      | Administrative links are mixed with profile/logout and may overwhelm non-admin users. Mobile clipping can partially hide the menu trigger.                                                                                                                     | P2: Role-filter the menu, group administration, and guarantee full mobile visibility.                                  |
| Eddy launcher    | Global                            | Launcher appeared on most pages.                                         | Persistent placement overlaps mobile content. The launcher does not disclose whether the answer uses current page scope, stale data, or a new conversation.                                                                                                    | P2: Make scope and data freshness visible before submission; reposition responsively.                                  |

### 5.2 Cockpit and dashboard

| Page/surface                 | Route                              | Observed state                                                                                                            | UX/HFE issues and missing functionality                                                                                                                                                         | Priority recommendation                                                                                                 |
| ---------------------------- | ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Command Cockpit              | `/dashboard`                       | Loaded live/synthetic operational dashboard with alerts, census strip, operational domains, OKRs, inbox, and mount scope. | Very high information volume with limited progressive disclosure. Alerts older than 300 hours remain active. “Live” status can be misread as applying to every metric. Mobile layout overflows. | P1: Fix alert lifecycle and mobile behavior. P2: add “changed since last review,” ownership, and domain prioritization. |
| Executive Cockpit            | `/dashboard?role=executive`        | Role switch worked; Executive brief appeared.                                                                             | Overlaps with Executive Brief page, Executive OKR drill-down, and Executive Briefing Agent. Scope and source synchronization between these surfaces are unclear.                                | P2: Consolidate into one executive workspace with canonical state.                                                      |
| Service Line Cockpit         | Disabled **Service Line soon** tab | Visible but disabled.                                                                                                     | Functionality is advertised before availability while Service Lines already exists elsewhere. This is both redundant and potentially interpreted as broken.                                     | P3: Remove until ready or link to the current Service Lines view with a roadmap note.                                   |
| Mount scope                  | Dashboard selector                 | Facility and unit/service options loaded; switching to MICU worked.                                                       | Changing scope can transform the page without a sufficiently persistent scope banner. Mixed unit, department, and service-line options appear in one long selector.                             | P2: Group by scope type, show selection in a persistent banner, and support recently used scopes.                       |
| Action Inbox modal           | Dashboard                          | Showed 8 pending approvals and active draft actions.                                                                      | Contradicts full Agent Inbox. Approve/Reject accessible names are repeated and context-free. Inline approval lacks an obvious evidence-review step.                                             | P1 state consistency; P2 action safety.                                                                                 |
| Active alert strip           | Dashboard                          | Multiple critical and warning alerts displayed.                                                                           | Excessive alert count, very old alerts, continuously changing durations, and repeated content create alarm fatigue. No visible owner/acknowledgement state.                                     | P1: Add expiry, acknowledgement, deduplication, owner, and escalation.                                                  |
| Demand & Capacity drill-down | Dashboard modal                    | Opened successfully.                                                                                                      | Overlaps with Bed Tracking, Demand Forecast, Bed Placement, and Global Huddle. Modal should link to canonical pages and preserve dashboard scope.                                               | P3: Use the modal as a summary and route deeper work to one canonical RTDC surface.                                     |
| Emergency drill-down         | Dashboard modal                    | Opened successfully.                                                                                                      | Overlaps ED Triage, Treatment, Patient Flow, Wait Time, and predictions.                                                                                                                        | P3: Show only change/action summary and deep-link to ED workspace.                                                      |
| Perioperative drill-down     | Dashboard modal                    | Opened successfully.                                                                                                      | Overlaps Room Status, Case Management, Block Schedule, and several utilization pages.                                                                                                           | P3: Consolidate with a clear current-state versus analysis distinction.                                                 |
| Staffing drill-down          | Dashboard modal                    | Opened successfully.                                                                                                      | Dashboard values can appear “live” while the Staffing workspace reports stale synthetic data.                                                                                                   | P1/P2: Propagate source freshness into the drill-down.                                                                  |
| Flow & Transport drill-down  | Dashboard modal                    | Opened successfully.                                                                                                      | Overlaps Patient Flow 4D and the entire Transport workspace.                                                                                                                                    | P2: identify which metrics are authoritative and stale.                                                                 |
| Quality & Safety drill-down  | Dashboard modal                    | Opened successfully.                                                                                                      | Quality metrics require denominator, measurement period, exclusion logic, and confidence; compact presentation can hide those limitations.                                                      | P2: add evidence/provenance expansion.                                                                                  |
| Service Lines drill-down     | Dashboard modal                    | Opened successfully.                                                                                                      | Competes with Service Huddle and planned Service Line Cockpit.                                                                                                                                  | P2: define the decision each surface supports and eliminate duplicate summaries.                                        |
| Financial drill-down         | Dashboard modal                    | Opened successfully.                                                                                                      | Financial values sit beside clinical/operational metrics without explaining period, accounting basis, or update cadence.                                                                        | P2: add units, period, source, and operational interpretation.                                                          |
| Executive OKR drill-down     | Dashboard modal                    | Opened successfully.                                                                                                      | Overlaps Executive Cockpit and Executive Brief. Large modal framing adds navigation burden.                                                                                                     | P3: merge into Executive workspace.                                                                                     |

### 5.3 RTDC workspace

| Page                 | Route                          | Observed state                                                               | UX/HFE issues and missing functionality                                                                                                                                                                                | Priority recommendation                                                                                    |
| -------------------- | ------------------------------ | ---------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| Bed Tracking         | `/rtdc/bed-tracking`           | Loaded capacity, pending flow, and unit census.                              | Needs explicit distinction among licensed, staffed, available, blocked, dirty, isolation, and assignable beds. Dense tables should pin unit and status columns.                                                        | P2: standardize bed-state ontology and provenance.                                                         |
| Patient Flow 4D      | `/rtdc/patient-flow-navigator` | Route loaded but hospital model failed. Fallback dots and controls remained. | Core model asset is dead. Three-column layout is cognitively overloaded. Detail view exposes raw JSON. Duplicate Barriers controls. Stale replay coexists with “latest” controls.                                      | P1: restore model and error recovery. P2: redesign controls around one task and human-readable evidence.   |
| Virtual Rounds       | `/rtdc/virtual-rounds`         | Unit/run switching and queue-row selection worked.                           | Cancelled runs overwhelm the selector. Queue, details, requirements, contributions, and entry form compete on one page. Mobile table is clipped. Actions need stronger state-transition explanation and audit context. | P1 mobile; P2 desktop: archive/filter runs, prioritize active queue, and use a stepwise contribution flow. |
| Bed Placement        | `/rtdc/bed-placement`          | Loaded pending requests and recommendations.                                 | Recommendations need explicit eligibility, contraindications, source freshness, confidence, and human ownership. Relationship to dashboard Action Inbox is unclear.                                                    | P2: make placement rationale and approval state traceable across surfaces.                                 |
| Ancillary Services   | `/rtdc/ancillary-services`     | Loaded service load and details.                                             | Broad “ancillary” grouping risks hiding modality-specific queues and different SLAs.                                                                                                                                   | P3: prioritize only constraints that affect placement/discharge and link to the source workspace.          |
| Global Huddle        | `/rtdc/global-huddle`          | Hospital Bed Meeting loaded system roll-up and unit bed-need table.          | Dense table supports scanning, but the page lacks visible change-since-last-huddle, ownership, decisions, and unresolved-item carryover.                                                                               | P2: add shift/huddle comparison and a decision log.                                                        |
| Unit Huddle          | `/rtdc/unit-huddle`            | Loaded discharge prediction, demand prediction, and barriers.                | Predicted discharge and demand should show calibration, uncertainty, and source freshness adjacent to values. Barrier ownership/escalation should be explicit.                                                         | P2: add uncertainty, owner, due time, and verification status.                                             |
| Service Huddle       | `/rtdc/service-huddle`         | Loaded and filters updated metrics.                                          | Unit/service filters are unlabeled and nested inside an H3, making the heading name include every option. “Unit and Departments” wording is grammatically and conceptually inconsistent.                               | P2: fix semantic structure and scope taxonomy.                                                             |
| Demand Forecast      | `/rtdc/predictions/demand`     | Loaded horizon roll-up and unit demand/capacity.                             | Forecast horizon, confidence interval, scenario assumptions, and decision threshold must be visible before action. Overlaps dashboard demand and Predictive Planning.                                                  | P2: use standardized forecast cards and one canonical demand model view.                                   |
| Resource Prediction  | `/rtdc/predictions/resources`  | Listed in navigation; not deeply exercised in this pass.                     | Label is indistinguishable from RTDC Resource Analytics. Prediction versus historical analysis is not clear.                                                                                                           | P2: rename to **Forecast Resource Need** and link to model evidence.                                       |
| Discharge Prediction | `/rtdc/predictions/discharge`  | Listed in navigation; not deeply exercised in this pass.                     | Must differentiate predicted readiness, expected discharge order, destination readiness, and actual discharge.                                                                                                         | P2: show stage-specific confidence and missing dependencies.                                               |
| Risk Assessment      | `/rtdc/predictions/risk`       | Loaded stratification, watchlist, and risk drivers.                          | Risk pages require strong safeguards against automation bias. Drivers should distinguish correlation, inference, and verified clinical fact.                                                                           | P2: require explainability, calibration, intended-use statement, and no autonomous action.                 |
| RTDC Utilization     | `/rtdc/analytics/utilization`  | Loaded utilization and capacity.                                             | Overlaps Bed Tracking and dashboard occupancy.                                                                                                                                                                         | P3: consolidate current-state and historical trends within one RTDC analytics workspace.                   |
| RTDC Performance     | `/rtdc/analytics/performance`  | Loaded performance metrics.                                                  | Needs target definitions, period, comparison cohort, and action linkage.                                                                                                                                               | P3: add measure metadata and decision context.                                                             |
| RTDC Resources       | `/rtdc/analytics/resources`    | Loaded resource analytics.                                                   | Ambiguous relative to Resource Prediction and other Resources pages.                                                                                                                                                   | P2: clarify historical versus forecast purpose.                                                            |
| RTDC Trends          | `/rtdc/analytics/trends`       | Loaded trends and patterns.                                                  | Trends need change-point annotations, source changes, and comparison-period controls.                                                                                                                                  | P3: annotate operational events and data-quality discontinuities.                                          |

### 5.4 Emergency Department workspace

| Page                   | Route                       | Observed state                                            | UX/HFE issues and missing functionality                                                                                                                                              | Priority recommendation                                                                           |
| ---------------------- | --------------------------- | --------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------- |
| Triage                 | `/ed/operations/triage`     | Loaded ED Triage.                                         | Triage surfaces must emphasize acuity, elapsed time, reassessment due time, deterioration, isolation, and ownership. Avoid excessive secondary details before critical exceptions.   | P2: use exception-first ordering and reassessment timers.                                         |
| Treatment Board        | `/ed/operations/treatment`  | Loaded with patient rows and ancillary readiness details. | Rows contain long composite accessible names and several “Unknown/unavailable” ancillary fields. Boarding durations are extremely long. Dense row content can hinder rapid scanning. | P2: use column grouping, pinned patient/acuity/time fields, and clear unavailable-data semantics. |
| ED Resources           | `/ed/operations/resources`  | Loaded Resource Management.                               | Redundant with ED Prediction Resources and ED Analytics Resources.                                                                                                                   | P2: consolidate into Current, Forecast, and Historical tabs.                                      |
| ED Patient Flow        | `/ed/analytics/flow`        | Loaded the same Patient Flow 4D navigator; model failed.  | Dead model asset, stale replay, and overloaded layout are inherited from RTDC. ED-specific page title does not create an ED-specific interaction model.                              | P1: fix shared model deployment and define ED-specific default scope.                             |
| Arrival Prediction     | `/ed/predictions/arrival`   | Loaded.                                                   | Needs forecast interval, source coverage, calibration, surge threshold, and actionable staffing/capacity linkage.                                                                    | P2: couple prediction with uncertainty and decision thresholds.                                   |
| Acuity Prediction      | `/ed/predictions/acuity`    | Loaded.                                                   | High risk of automation bias if predicted acuity resembles a clinical triage score. Must distinguish operational planning from clinical assessment.                                  | P1/P2: display intended use, limitations, and prohibit replacement of clinician triage.           |
| ED Resource Prediction | `/ed/predictions/resources` | Loaded Resource Optimization.                             | “Optimization” can imply autonomous authority. Overlaps current and historical resource pages.                                                                                       | P2: call it planning support and expose constraints and human approval.                           |
| ED Wait Time           | `/ed/analytics/wait-time`   | Loaded.                                                   | Wait metrics need cohort definition, percentile distribution, exclusions, and stratification by acuity. Averages alone are unsafe.                                                   | P2: lead with percentile and high-acuity exceptions.                                              |
| ED Resource Analytics  | `/ed/analytics/resources`   | Loaded.                                                   | Redundant naming and weak distinction from operations/predictions.                                                                                                                   | P2: consolidate.                                                                                  |

### 5.5 Perioperative workspace

| Page                  | Route                              | Observed state                            | UX/HFE issues and missing functionality                                                                                                                           | Priority recommendation                                                     |
| --------------------- | ---------------------------------- | ----------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| Room Status           | `/operations/room-status`          | Loaded.                                   | Must emphasize current phase, next milestone, delay reason, downstream dependency, and source time.                                                               | P2: use a consistent perioperative timeline grammar.                        |
| Block Schedule        | `/operations/block-schedule`       | Loaded.                                   | Block ownership, release rules, unused-time risk, and change history should be visible.                                                                           | P2: add governance and forecasted release opportunities.                    |
| Case Management       | `/operations/cases`                | Loaded.                                   | Case lists need patient-safe identity handling, phase, delay, readiness, and ownership without overloading rows.                                                  | P2: progressive disclosure and exception-first filters.                     |
| Utilization Forecast  | `/predictions/forecast`            | Loaded.                                   | Overlaps multiple utilization analytics pages. Forecast assumptions and confidence should be explicit.                                                            | P2: merge under Utilization workspace with Forecast tab.                    |
| Demand Analysis       | `/predictions/demand`              | Loaded.                                   | “Demand” is ambiguous relative to forecasted cases, staffed room demand, and downstream bed need.                                                                 | P3: rename and show horizon.                                                |
| Resource Planning     | `/predictions/resources`           | Loaded.                                   | Overlaps staffing, room, and resource pages without clear decision ownership.                                                                                     | P2: specify resource type, horizon, and accountable role.                   |
| Block Utilization     | `/analytics/block-utilization`     | Loaded.                                   | Closely related to OR and Primetime Utilization; separate routes increase navigation cost.                                                                        | P3: consolidate as tabs.                                                    |
| OR Utilization        | `/analytics/or-utilization`        | Loaded.                                   | Needs consistent denominator, prime-time window, exclusions, and source.                                                                                          | P2: standardize metric metadata.                                            |
| Primetime Utilization | `/analytics/primetime-utilization` | Loaded.                                   | Separate page for one utilization definition is excessive fragmentation.                                                                                          | P3: make it a saved measure/view within OR Utilization.                     |
| Room Running          | `/analytics/room-running`          | Loaded.                                   | Operational and retrospective uses should be separated; “running” can mean live status or historical performance.                                                 | P3: clarify intent and link to Room Status.                                 |
| Turnover Times        | `/analytics/turnover-times`        | Error boundary displayed.                 | Functional failure creates a large empty canvas. Filters remain visible even though results cannot render. No useful recovery or diagnostic reference is offered. | P1: fix `turnoverDistribution` contract and add retry/empty-state behavior. |
| IR Suite Study        | `/analytics/ir-utilization`        | Loaded with several unavailable measures. | “Unavailable” is repeated without always explaining whether data is absent, excluded, late, or unsupported.                                                       | P2: use explicit unavailable reasons and coverage denominator.              |

### 5.6 Radiology workspace

| Page                 | Route                       | Observed state                                                                             | UX/HFE issues and missing functionality                                                                                                        | Priority recommendation                                                     |
| -------------------- | --------------------------- | ------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| Imaging Flow Board   | `/radiology`                | Loaded; several patient locations unavailable and scanner unavailable conditions appeared. | Missing location directly impairs queue prioritization and handoff. Scanner unavailability needs owner, expected recovery, and affected queue. | P2: elevate location/data-quality issues and operational ownership.         |
| Order Worklist       | `/radiology/worklist`       | Loaded; location and scanner availability gaps persisted.                                  | Worklist density should prioritize urgency, age, modality, location, transport readiness, and result dependency.                               | P2: add exception-first sorting and reasoned unavailable states.            |
| Modality Utilization | `/radiology/modality`       | Loaded.                                                                                    | Must separate scheduled capacity, actual use, downtime, and staffing limitation.                                                               | P3: add causal breakdown and time horizon.                                  |
| Reads and Results    | `/radiology/reads`          | Loaded with an alert about missing timestamps.                                             | Data-quality warning is appropriate, but affected calculations and excluded records should be explorable without leaving the page.             | P2: link warning to excluded records and impact statement.                  |
| Radiology TAT Study  | `/analytics/radiology-tat`  | Loaded with visible evidence exclusions.                                                   | Stronger than many pages because exclusions are visible. Still needs compact “coverage and trust” summary near the primary result.             | P3: preserve transparency while reducing repeated unavailable text.         |
| IR Suite Utilization | `/analytics/ir-utilization` | Loaded; several intervals unavailable.                                                     | Shares perioperative and radiology concerns; ownership and taxonomy are unclear.                                                               | P3: place in the most relevant domain and cross-link rather than duplicate. |

### 5.7 Laboratory workspace

| Page                          | Route                    | Observed state                                           | UX/HFE issues and missing functionality                                                                                                                                  | Priority recommendation                                                                   |
| ----------------------------- | ------------------------ | -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------- |
| Laboratory Flow Board         | `/lab`                   | Loaded; some locations unavailable.                      | Queue prioritization must distinguish specimen not collected, in transit, received, processing, verified, and communicated. Missing location needs immediate visibility. | P2: add specimen lifecycle and escalation ownership.                                      |
| Specimen Tracker              | `/lab/specimens`         | Loaded; unit unavailable for some items.                 | Technical lineage identifiers such as roots/representatives are useful for audit but visually compete with operational status.                                           | P3: move lineage into expandable technical detail.                                        |
| Decision-Pending Results      | `/lab/pending-decisions` | Loaded with a warning that two candidates were withheld. | Good refusal to infer from test names. The user still needs a concise explanation of what validation is missing and who owns resolution.                                 | P2: add owner and remediation link.                                                       |
| Blood Bank Readiness          | `/lab/blood-bank`        | Loaded.                                                  | Blood-bank decisions are safety-critical and need explicit compatibility, reservation expiry, transport readiness, and verification status.                              | P1/P2: validate against transfusion-safety HFE and independent verification requirements. |
| Anatomic Pathology Case Aging | `/lab/anatomic-path`     | Loaded.                                                  | Aging should distinguish expected processing, external dependency, overdue, and clinically escalated cases.                                                              | P2: provide reason, owner, and escalation threshold.                                      |
| Laboratory TAT Study          | `/analytics/lab-tat`     | Loaded with evidence limitations.                        | Missing pairs and conflicts are disclosed, but the primary metric should not appear more certain than the data coverage permits.                                         | P2: visually bind every result to coverage/confidence.                                    |

### 5.8 Pharmacy workspace

| Page                           | Route                      | Observed state                                             | UX/HFE issues and missing functionality                                                                                                                                                                     | Priority recommendation                                                                                |
| ------------------------------ | -------------------------- | ---------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| Medication Flow Board          | `/pharmacy`                | Loaded; some locations unavailable.                        | Medication workflow requires clear distinction between ordered, verified, prepared, dispensed, delivered, administered, held, and discontinued. Repeated medication cards can be difficult to disambiguate. | P2: standardize medication lifecycle and surface patient/location safely.                              |
| Discharge Medication Readiness | `/pharmacy/discharge-meds` | Loaded.                                                    | Readiness should identify prescription, reconciliation, authorization, fill, education, payment, pickup/delivery, and unresolved barriers.                                                                  | P2: show dependency chain and accountable owner.                                                       |
| IV Room and Batches            | `/pharmacy/iv-room`        | Loaded.                                                    | Batch status needs beyond-SLA risk, sterility/expiry constraints, and downstream patient impact.                                                                                                            | P2: prioritize time-critical and safety-critical exceptions.                                           |
| Dispense and Delivery          | `/pharmacy/dispense`       | Loaded with repeated **no data** cells.                    | “No data” is ambiguous and lowers density by repeating text.                                                                                                                                                | P2: distinguish zero, unavailable, not applicable, and not connected; summarize missing coverage once. |
| Controlled Substances          | `/pharmacy/controlled`     | Loaded with unavailable/no-data values.                    | Safety and regulatory workflow requires clear exception reason, audit linkage, owner, and time-to-resolution.                                                                                               | P1/P2: use exception-based controlled-substance review with strong provenance.                         |
| Pharmacy TAT Study             | `/analytics/pharmacy-tat`  | Loaded with evidence limitations and unavailable measures. | Evidence limitations are transparent but lengthy.                                                                                                                                                           | P3: provide compact coverage badges with drill-down.                                                   |

### 5.9 Transport workspace

| Page                    | Route                         | Observed state                                                                | UX/HFE issues and missing functionality                                                                                                                                | Priority recommendation                                                                                  |
| ----------------------- | ----------------------------- | ----------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| Requests                | `/transport/requests`         | Loaded after a brief loading state.                                           | Requests need patient/unit, origin/destination, readiness, equipment, isolation, requested-by, age, SLA, and downstream impact.                                        | P2: prioritize actionable readiness and SLA risk.                                                        |
| Dispatch Workbench      | `/transport/dispatch`         | Loaded with stale synthetic-data alert and several **Failed** buttons/states. | Repeated **Failed** labels are context-poor. Stale data can invalidate dispatch decisions.                                                                             | P1 if operational; P2 in demo: disable live dispatch actions when stale and expose failure reason/owner. |
| Inpatient Transport     | `/transport/inpatient`        | Loaded with the same stale alert and repeated failed states.                  | Redundant with Requests/Dispatch unless it provides a distinct inpatient queue decision.                                                                               | P2: clarify workflow stage or merge as a filtered queue.                                                 |
| Interfacility Transfers | `/transport/transfers`        | Loaded with stale alert.                                                      | Interfacility transfer is substantially different from internal transport and needs accepting facility, clinical readiness, payer/authorization, mode, and escalation. | P2: retain as a distinct workflow but avoid generic transport controls.                                  |
| Discharge Transport     | `/transport/discharge`        | Loaded with stale alert.                                                      | Needs linkage to discharge readiness and destination acceptance; otherwise transport can be arranged prematurely.                                                      | P2: show discharge dependency checklist.                                                                 |
| EMS Handoff             | `/transport/ems`              | Loaded with stale alert.                                                      | EMS handoff requires time-critical identity, acuity, destination, handoff completeness, and accountability. Synthetic/stale state must be unmistakable.                | P1/P2: use dedicated handoff HFE, not a generic transport template.                                      |
| Care Transitions        | `/transport/care-transitions` | Loaded with stale alert.                                                      | Overlaps discharge transport and broader care-progression workflows.                                                                                                   | P2: define the transition milestones and ownership clearly.                                              |
| Transport Resources     | `/transport/resources`        | Loaded with stale alert.                                                      | “Resources” is ambiguous and separated from Dispatch.                                                                                                                  | P3: integrate resource posture into Dispatch or rename by decision.                                      |
| Transport Analytics     | `/transport/analytics`        | Loaded with stale alert.                                                      | Historical analytics should not inherit a live-dispatch warning without explaining the analysis cutoff.                                                                | P2: separate operational freshness from analytical period completeness.                                  |

### 5.10 Staffing workspace

| Page            | Route       | Observed state                                                                    | UX/HFE issues and missing functionality                                                                                                                                                                                  | Priority recommendation                                                                                               |
| --------------- | ----------- | --------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------- |
| Staffing Office | `/staffing` | Loaded broad workforce roster and coverage metrics; data was stale and synthetic. | Extremely high data volume spans coverage, risk, gap headcount, roster roles, employment mix, credentials, and search. Stale state may invalidate decisions. The page needs decision-focused subsets by shift/unit/role. | P1 if operational; P2 design: create Coverage, Gaps, Requests, Credentials, and Workforce tabs with persistent scope. |

### 5.11 Operations Intelligence and analytics

| Page                  | Route                              | Observed state                                              | UX/HFE issues and missing functionality                                                                                                                 | Priority recommendation                                                            |
| --------------------- | ---------------------------------- | ----------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| Intelligence Hub      | `/analytics`                       | Loaded after a brief loading state.                         | Hub should answer “what requires analysis now?” instead of duplicating navigation.                                                                      | P3: prioritize changed signals and saved investigations.                           |
| Live Signals          | `/analytics/live`                  | Loaded under generic Operations Intelligence heading.       | “Live” must define refresh cadence and avoid mixing stale/synthetic sources.                                                                            | P2: display freshness per signal.                                                  |
| Executive Brief       | `/ops/executive-brief`             | Loaded.                                                     | Redundant with Executive Cockpit, OKR scorecard, and Executive Briefing Agent.                                                                          | P2: consolidate.                                                                   |
| Agent Inbox           | `/ops/agent-inbox`                 | Loaded governed agents but reported zero approvals/actions. | Contradicts dashboard. Three agent Run buttons share the same accessible name. Agents need run history, scope, evidence cutoff, and result disposition. | P1 state consistency; P2 accessibility and governance.                             |
| Data Quality          | `/analytics/data-quality`          | Loaded under generic Operations Intelligence heading.       | Data quality should be globally accessible from affected metrics, not isolated in a separate destination.                                               | P2: link every degraded measure to its exact quality issue.                        |
| Retrospective Review  | `/analytics/retrospective`         | Loaded under generic heading.                               | Relationship to Process Intelligence and performance studies is unclear.                                                                                | P3: define retrospective workflow and canonical outputs.                           |
| Process Intelligence  | `/analytics/process-intelligence`  | Loaded under generic heading.                               | Overlaps Process Analysis and Patient-Flow Arena.                                                                                                       | P2: consolidate process-discovery and conformance capabilities.                    |
| Patient-Flow Arena    | `/analytics/arena`                 | Loaded.                                                     | “Arena” is non-standard healthcare terminology and may obscure the task.                                                                                | P3: rename around comparison/simulation purpose and display non-production status. |
| Opportunity Portfolio | `/analytics/opportunities`         | Loaded after a brief loading state.                         | Overlaps Agent Inbox, improvement cycles, and governed approvals.                                                                                       | P2: make this the canonical prioritization stage or remove duplication.            |
| Predictive Planning   | `/analytics/predictive`            | Loaded.                                                     | Broad page competes with domain-specific prediction pages.                                                                                              | P2: use it as a cross-domain scenario layer with links to canonical models.        |
| Scenario Workbench    | `/analytics/workbench`             | Loaded after a brief loading state.                         | “Workbench” and Predictive Planning may represent the same task. Scenario assumptions, save state, comparison, and approval are essential.              | P2: merge or establish explicit plan-versus-simulation roles.                      |
| Block Utilization     | `/analytics/block-utilization`     | Loaded.                                                     | See perioperative consolidation recommendation.                                                                                                         | P3.                                                                                |
| OR Utilization        | `/analytics/or-utilization`        | Loaded.                                                     | See perioperative consolidation recommendation.                                                                                                         | P3.                                                                                |
| Primetime Utilization | `/analytics/primetime-utilization` | Loaded.                                                     | See perioperative consolidation recommendation.                                                                                                         | P3.                                                                                |
| Room Running          | `/analytics/room-running`          | Loaded.                                                     | See perioperative consolidation recommendation.                                                                                                         | P3.                                                                                |
| Turnover Times        | `/analytics/turnover-times`        | Broken.                                                     | See ANA-01.                                                                                                                                             | P1.                                                                                |
| Radiology TAT         | `/analytics/radiology-tat`         | Loaded with exclusion alert.                                | See radiology section.                                                                                                                                  | P3.                                                                                |
| Laboratory TAT        | `/analytics/lab-tat`               | Loaded with limitation alert.                               | See laboratory section.                                                                                                                                 | P2/P3.                                                                             |
| Pharmacy TAT          | `/analytics/pharmacy-tat`          | Loaded with limitation alert.                               | See pharmacy section.                                                                                                                                   | P2/P3.                                                                             |
| IR Suite Utilization  | `/analytics/ir-utilization`        | Loaded with unavailable intervals.                          | See perioperative/radiology sections.                                                                                                                   | P3.                                                                                |

### 5.12 Improvement workspace

| Page                | Route                      | Observed state                              | UX/HFE issues and missing functionality                                                                                                     | Priority recommendation                                                |
| ------------------- | -------------------------- | ------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------- |
| Bottlenecks         | `/improvement/bottlenecks` | Loaded.                                     | Must distinguish detected signal, validated bottleneck, suspected cause, and selected improvement target.                                   | P2: create explicit evidence maturity.                                 |
| Process Analysis    | `/improvement/process`     | Loaded but no visible H1 was detected.      | Missing/weak page identity harms orientation and accessibility. Overlaps Process Intelligence.                                              | P2: add clear page identity and consolidate with Process Intelligence. |
| Root Cause          | `/improvement/root-cause`  | Loaded.                                     | Root-cause tools should resist premature closure and preserve competing hypotheses and evidence.                                            | P2: support hypothesis/evidence structure and facilitator controls.    |
| Active Cycles       | `/improvement/active`      | Loaded PDSA Cycles & Opportunities content. | Name differs from H1 and overlaps PDSA Cycles. Scanner-unavailable content appeared among cycles, suggesting mixed source semantics.        | P2: reconcile naming and define canonical cycle list.                  |
| PDSA Cycles         | `/improvement/pdsa`        | Loaded but no visible H1 was detected.      | Redundant with Active Cycles and weak page identity.                                                                                        | P2: merge with Active Cycles.                                          |
| Improvement Library | `/improvement/library`     | Loaded.                                     | Library should connect reusable interventions to context, evidence strength, owner, and local outcome—not just act as a content repository. | P3: add applicability and evidence metadata.                           |

### 5.13 Profile and administration

| Page                    | Route                       | Observed state                                                               | UX/HFE issues and missing functionality                                                                                                                                    | Priority recommendation                                                                          |
| ----------------------- | --------------------------- | ---------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| Profile                 | `/profile`                  | Loaded profile, password, and delete-account sections.                       | Delete Account is visually adjacent to routine settings and is inappropriate for many enterprise-managed identities. Password ownership may belong to Authentik.           | P2: hide unsupported local identity actions and separate destructive controls.                   |
| Administration Overview | `/admin`                    | Loaded effective boundary, action queue, and accountability activity.        | Strong concepts, but admin terminology should match governed actions elsewhere.                                                                                            | P3: use canonical queue and audit terminology.                                                   |
| User Audit              | `/admin/user-audit`         | Loaded accountability activity and failed-login metrics.                     | Audit entries need filters, actor/subject distinction, source, immutable event identifiers, and export governance.                                                         | P2: strengthen investigative workflow.                                                           |
| Access Reviews          | `/admin/access-reviews`     | Loaded quarterly campaigns.                                                  | Healthcare access review needs attestor, evidence, due date, exception, revocation status, and segregation-of-duties support.                                              | P2: make completion and unresolved risk explicit.                                                |
| User Management         | `/users`                    | Loaded.                                                                      | Must clearly distinguish local profile, Authentik identity, roles, groups, facility scope, break-glass status, and last review.                                            | P2: align identity model with SSO and least privilege.                                           |
| Cockpit Thresholds      | `/admin/cockpit/thresholds` | Loaded duplicate/ambiguous metric keys, policies, and pending changes.       | This page surfaces a root cause of dashboard ambiguity. Threshold changes need simulation, impact preview, approval, versioning, and rollback.                             | P1/P2: enforce governed threshold lifecycle.                                                     |
| Enterprise Setup        | `/admin/enterprise-setup`   | Loaded empty state: no organizations imported; CLI import instruction shown. | Operational setup depends on a developer/CLI command and lacks an in-product guided workflow. Empty state is technically accurate but not actionable for an administrator. | P2: provide validated import, preview, mapping, error correction, and audit workflow in product. |

---

## 6. Dead links, broken paths, and incomplete destinations

### 6.1 Confirmed broken functionality

1. **Dead Patient Flow facility-model asset**  
   `GET /vendor/zephyrus-facility-models/zep-500/hospital_model.glb` returned `404 Not Found` on both Patient Flow entry points.

2. **Turnover Times application exception**  
   `/analytics/turnover-times` failed while reading `turnoverDistribution` from an undefined object.

3. **Eddy response dead-end**  
   Contextual Eddy accepted a prompt but produced no response, recovery, or terminal error state.

4. **Contradictory action-queue destination**  
   The dashboard’s “Open the full agent inbox” link reaches a page that reports no approvals even though the source modal reports eight.

### 6.2 Incomplete or misleading destinations

- Disabled **Service Line soon** Cockpit tab.
- Improvement Process and PDSA routes lack a detected visible H1.
- Enterprise Setup delegates initial population to `deployment:import-facilities` rather than providing an administrative workflow.
- Several analytics destinations use the generic H1 **Operations Intelligence**, weakening destination confirmation.
- Navigation destinations labeled only **Resources** or **Demand** do not provide enough context before selection.

### 6.3 Navigation links that did not fail during the smoke test

No primary Workspaces, Study, Profile, or Administration navigation link tested returned a route-level 404. Most destinations rendered their expected page identity. The absence of route-level 404s does not negate the functional failures above, especially the dead 3D model asset and Turnover Times crash.

---

## 7. Recommended consolidation model

### 7.1 Proposed top-level structure

1. **Command**
   - Current operational picture
   - Alerts and decisions
   - Executive view
   - Shift/huddle handoff

2. **Flow**
   - Capacity and beds
   - Patient Flow 4D
   - Placement
   - Virtual Rounds
   - Huddles

3. **Clinical Operations**
   - Emergency
   - Perioperative
   - Radiology
   - Laboratory
   - Pharmacy
   - Transport
   - Staffing

4. **Intelligence**
   - Live signals
   - Historical analysis
   - Predictions and scenarios
   - Data quality
   - Executive brief

5. **Improvement**
   - Opportunities
   - Root cause
   - PDSA lifecycle
   - Intervention library

6. **Governance**
   - Action queue
   - Agents
   - Thresholds
   - Access reviews
   - Audit
   - Enterprise setup

### 7.2 Canonical-surface rules

- One canonical queue for all governed actions.
- One canonical page for each live operational state; drill-downs are projections, not separate calculations.
- One Utilization workspace with domain and metric tabs.
- One Improvement lifecycle rather than separate opportunity, active-cycle, and PDSA lists.
- One Executive workspace incorporating brief, OKRs, decisions, and agent output.
- One consistent scope object across facility, unit, department, and service line.
- One trust/provenance component used by every metric and recommendation.

---

## 8. Prioritized remediation plan

### Phase 0 — Immediate containment (0–7 days)

- Restore or correctly publish the Patient Flow hospital model.
- Fix Turnover Times response-contract handling.
- Reconcile Action Inbox and Agent Inbox state or temporarily hide the inconsistent full-inbox link.
- Add an Eddy timeout and visible failure state; prevent indefinite blank responses.
- Expire or clearly quarantine alerts that exceed their operational validity window.
- Confirm whether stale synthetic Transport/Staffing data is intended on this environment; disable operational actions if not current.

### Phase 1 — Safety and responsive usability (1–3 weeks)

- Create supported mobile task flows for dashboard review, Virtual Rounds contribution, and governed action review.
- Replace context-free Approve/Reject labels with proposal-specific accessible labels and a review step.
- Fix Service Huddle filter semantics and all duplicate accessible names.
- Hide raw JSON and internal prompts; render evidence, confidence, source, owner, and limitations.
- Add persistent scope and data-freshness banners.
- Archive/filter cancelled Virtual Rounds runs.

### Phase 2 — Information architecture consolidation (3–8 weeks)

- Consolidate Resources, Demand, Utilization, Executive, Improvement, and Action Queue surfaces.
- Rename routes and menu items by domain, time horizon, and decision intent.
- Replace generic Operations Intelligence headings with destination-specific page identity and breadcrumbs.
- Add saved views, recent scopes, and role-based presets.

### Phase 3 — Healthcare HFE design system (6–12 weeks)

- Create standard components for freshness, uncertainty, inferred/verified evidence, ownership, acknowledgement, escalation, and action review.
- Standardize zero/unknown/unavailable/not-applicable/loading states.
- Add alert lifecycle and alarm-fatigue controls.
- Add shift-handoff and “changed since last review” workflows.
- Validate color, keyboard, screen-reader, and low-vision behavior across both themes.

### Phase 4 — Continuous validation

- Add automated authenticated route checks.
- Add console-error and asset-integrity gates to deployment.
- Add visual regression tests at supported breakpoints.
- Add accessibility tests for unique action names and landmark/heading structure.
- Add data-contract tests for every analytics response.
- Conduct formative usability testing with charge nurses, bed managers, house supervisors, transport dispatchers, perioperative leaders, and quality/improvement users.

---

## 9. Acceptance criteria for remediation

### Navigation and reliability

- Every primary destination loads without a route, asset, or console error.
- Every page has one clear H1 matching its navigation label and document title.
- Search results include workspace, domain, purpose, and route context.
- Disabled future functionality is not shown as a primary control without explanation.

### Healthcare HFE

- Every actionable alert shows recency, confidence, owner, acknowledgement state, and expiry/escalation behavior.
- Every governed action shows scope, evidence, expected effect, reversibility, and accountable approver.
- Stale or synthetic data cannot be mistaken for current operational data.
- Predictions show intended use, horizon, uncertainty, calibration/limitations, and human-decision boundary.
- No page requires raw JSON interpretation for an operational decision.

### Responsive behavior

- No horizontal document overflow at supported breakpoints.
- Essential controls remain visible without horizontal scrolling.
- Tables transform into task-appropriate cards or focused row detail on narrow screens.
- Floating assistants do not cover actions, data, or navigation.

### Accessibility

- Repeated actions include their object in the accessible name.
- All filters have programmatic labels.
- Headings contain only heading content, not form controls or option lists.
- Status is conveyed by text/iconography in addition to color.
- Live regions do not repeatedly announce duplicate alert content or constantly changing seconds.

### Data density and whitespace

- Every large content region has a decision purpose, primary result, or intentional empty state.
- Error boundaries preserve useful context and offer recovery without leaving large blank canvases.
- Dense boards support pinned identifiers, exception-first sorting, saved views, and progressive disclosure.
- Users can compare current state, target, prior state, and forecast without excessive navigation.

---

## 10. Route coverage appendix

The following routes were authenticated and opened during the baseline review. “Loaded” means a page identity rendered; it does not mean the page passed all usability or data-quality criteria.

### Global, RTDC, and administration

| Route                          | Result                                                            |
| ------------------------------ | ----------------------------------------------------------------- |
| `/dashboard`                   | Loaded; major alert, inbox, density, and responsive issues.       |
| `/rtdc/patient-flow-navigator` | Loaded with failed hospital model asset.                          |
| `/rtdc/virtual-rounds`         | Loaded; queue interactions worked; run history and mobile issues. |
| `/rtdc/bed-tracking`           | Loaded.                                                           |
| `/rtdc/bed-placement`          | Loaded.                                                           |
| `/rtdc/ancillary-services`     | Loaded.                                                           |
| `/rtdc/global-huddle`          | Loaded.                                                           |
| `/rtdc/unit-huddle`            | Loaded.                                                           |
| `/rtdc/service-huddle`         | Loaded; semantic filter defect.                                   |
| `/rtdc/predictions/demand`     | Loaded.                                                           |
| `/rtdc/predictions/risk`       | Loaded.                                                           |
| `/rtdc/analytics/utilization`  | Loaded.                                                           |
| `/rtdc/analytics/performance`  | Loaded.                                                           |
| `/rtdc/analytics/resources`    | Loaded.                                                           |
| `/rtdc/analytics/trends`       | Loaded.                                                           |
| `/profile`                     | Loaded.                                                           |
| `/admin`                       | Loaded.                                                           |
| `/admin/user-audit`            | Loaded.                                                           |
| `/admin/access-reviews`        | Loaded.                                                           |
| `/users`                       | Loaded.                                                           |
| `/admin/cockpit/thresholds`    | Loaded.                                                           |
| `/admin/enterprise-setup`      | Loaded empty state.                                               |
| `/ops/agent-inbox`             | Loaded; contradictory zero state.                                 |

### Emergency and perioperative

| Route                              | Result                                   |
| ---------------------------------- | ---------------------------------------- |
| `/ed/operations/triage`            | Loaded.                                  |
| `/ed/operations/treatment`         | Loaded.                                  |
| `/ed/operations/resources`         | Loaded.                                  |
| `/ed/analytics/flow`               | Loaded with failed hospital model asset. |
| `/ed/analytics/wait-time`          | Loaded.                                  |
| `/ed/analytics/resources`          | Loaded.                                  |
| `/ed/predictions/arrival`          | Loaded.                                  |
| `/ed/predictions/acuity`           | Loaded.                                  |
| `/ed/predictions/resources`        | Loaded.                                  |
| `/operations/room-status`          | Loaded.                                  |
| `/operations/block-schedule`       | Loaded.                                  |
| `/operations/cases`                | Loaded.                                  |
| `/predictions/forecast`            | Loaded.                                  |
| `/predictions/demand`              | Loaded.                                  |
| `/predictions/resources`           | Loaded.                                  |
| `/analytics/block-utilization`     | Loaded.                                  |
| `/analytics/or-utilization`        | Loaded.                                  |
| `/analytics/primetime-utilization` | Loaded.                                  |
| `/analytics/room-running`          | Loaded.                                  |
| `/analytics/turnover-times`        | Broken by application exception.         |

### Radiology, laboratory, and pharmacy

| Route                       | Result                                            |
| --------------------------- | ------------------------------------------------- |
| `/radiology`                | Loaded with unavailable location/scanner signals. |
| `/radiology/worklist`       | Loaded with unavailable location/scanner signals. |
| `/radiology/modality`       | Loaded.                                           |
| `/radiology/reads`          | Loaded with missing-timestamp warning.            |
| `/analytics/radiology-tat`  | Loaded with evidence-exclusion warning.           |
| `/analytics/ir-utilization` | Loaded with unavailable intervals.                |
| `/lab`                      | Loaded with unavailable locations.                |
| `/lab/specimens`            | Loaded with unavailable units.                    |
| `/lab/pending-decisions`    | Loaded with withheld-candidate warning.           |
| `/lab/blood-bank`           | Loaded.                                           |
| `/lab/anatomic-path`        | Loaded.                                           |
| `/analytics/lab-tat`        | Loaded with evidence limitations.                 |
| `/pharmacy`                 | Loaded with unavailable locations.                |
| `/pharmacy/discharge-meds`  | Loaded.                                           |
| `/pharmacy/iv-room`         | Loaded.                                           |
| `/pharmacy/dispense`        | Loaded with repeated no-data states.              |
| `/pharmacy/controlled`      | Loaded with no-data/unavailable states.           |
| `/analytics/pharmacy-tat`   | Loaded with evidence limitations.                 |

### Transport and staffing

| Route                         | Result                                              |
| ----------------------------- | --------------------------------------------------- |
| `/transport/requests`         | Loaded.                                             |
| `/transport/dispatch`         | Loaded with stale synthetic data and failed states. |
| `/transport/inpatient`        | Loaded with stale synthetic data and failed states. |
| `/transport/transfers`        | Loaded with stale synthetic data and failed states. |
| `/transport/discharge`        | Loaded with stale synthetic data and failed states. |
| `/transport/ems`              | Loaded with stale synthetic data and failed states. |
| `/transport/care-transitions` | Loaded with stale synthetic data and failed states. |
| `/transport/resources`        | Loaded with stale synthetic data.                   |
| `/transport/analytics`        | Loaded with stale synthetic data.                   |
| `/staffing`                   | Loaded with stale synthetic data.                   |

### Intelligence and improvement

| Route                             | Result                          |
| --------------------------------- | ------------------------------- |
| `/analytics`                      | Loaded.                         |
| `/analytics/live`                 | Loaded.                         |
| `/ops/executive-brief`            | Loaded.                         |
| `/analytics/data-quality`         | Loaded.                         |
| `/analytics/retrospective`        | Loaded.                         |
| `/analytics/process-intelligence` | Loaded.                         |
| `/analytics/arena`                | Loaded.                         |
| `/analytics/opportunities`        | Loaded.                         |
| `/analytics/predictive`           | Loaded.                         |
| `/analytics/workbench`            | Loaded.                         |
| `/improvement/bottlenecks`        | Loaded.                         |
| `/improvement/process`            | Loaded; no detected visible H1. |
| `/improvement/root-cause`         | Loaded.                         |
| `/improvement/active`             | Loaded.                         |
| `/improvement/pdsa`               | Loaded; no detected visible H1. |
| `/improvement/library`            | Loaded.                         |

### Navigation-only destinations identified but not deeply exercised

| Route                         | Reason for follow-up                                                 |
| ----------------------------- | -------------------------------------------------------------------- |
| `/rtdc/predictions/resources` | Distinguish forecast resource need from RTDC Resource Analytics.     |
| `/rtdc/predictions/discharge` | Validate discharge-stage semantics, confidence, and barrier linkage. |

---

## 11. Final assessment

Zephyrus demonstrates substantial domain breadth and a promising operational-command concept, but the current product needs consolidation and a healthcare-specific HFE layer before it can reliably support high-tempo operational decisions. The immediate work is to restore broken core functionality and reconcile contradictory operational state. The next work is not simply visual polish: it is to create canonical data/state ownership, safer actions, explicit freshness and uncertainty, role-focused information density, and responsive task designs.

The product should be judged by whether a user can answer—within seconds and without ambiguity—**what is happening, what changed, what is trustworthy, what requires action, who owns it, and what will happen if I approve it**. Several current pages provide useful data, but the application does not yet answer those questions consistently across sections.
