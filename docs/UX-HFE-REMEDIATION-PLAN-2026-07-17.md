# Zephyrus UX/HFE Remediation Plan — Evaluation of the 2026-07-17 Audit

**Companion to:** [ZEPHYRUS_COMPREHENSIVE_UX_UI_HFE_AUDIT_2026-07-17.md](./ZEPHYRUS_COMPREHENSIVE_UX_UI_HFE_AUDIT_2026-07-17.md)
**Method:** every audit finding was verified against the codebase, the production host (Apache config, ModSecurity logs, filesystem), and the production database before being accepted. Findings are classified **CONFIRMED**, **MISDIAGNOSED** (real symptom, wrong root cause → wrong proposed fix), **BY-DESIGN** (audit lacked product context), or **DEFERRED-DECISION** (strategic, needs CMIO ruling, not unilateral remediation).

---

## 1. Verdict on the audit's highest-priority findings

| ID | Audit claim | Verdict | Verified root cause |
| --- | --- | --- | --- |
| PF4D-01 | Hospital model asset missing; "deploy/version the asset" | **MISDIAGNOSED** | The `.glb` exists in the repo (tracked since `d47949a`), and on prod disk at `/var/www/Zephyrus/public/vendor/zephyrus-facility-models/zep-500/`. **ModSecurity rule id 1000003** (`/etc/apache2/zephyrus/edge-security.conf:33`, pattern `^/(?:\.env\|\.git\|storage\|vendor)(?:/\|$)`) blocks the entire `/vendor/*` URL namespace with a 404. Deploying the asset again would change nothing. Fix: **move the asset URL out of `/vendor`** (`public/facility-models/…`); keep the security rule at full strength. |
| ANA-01 | Turnover Times crashes on `turnoverDistribution` | **CONFIRMED** | `Components/Analytics/TurnoverTimes/Views/OverviewView.jsx:43` dereferences `locationData.turnoverDistribution` where `locationData` falls back to `sites['MARH OR']`, which the DB-driven payload doesn't guarantee. Guard + real empty state. |
| GOV-01 | Two action stores contradict; "establish one authoritative store" | **MISDIAGNOSED** | There is exactly **one** store: `ops.approvals`/`ops.actions` → `OperationalActionLifecycleService` → `GET /api/ops/agent-inbox` → single TanStack query key, consumed by both surfaces. Prod DB currently holds **8 pending approvals / 8 draft actions** — the dashboard's "8" is correct. The Agent Inbox page renders `summary?.pendingApprovals ?? 0` (`AgentInbox.tsx:98`): **loading and failed fetches display as zero**. The defect is unknown-rendered-as-zero, an HFE cardinal sin — but the fix is state handling, not architecture. |
| EDDY-01 | Eddy permanent blank state | **CONFIRMED** | `features/eddy/stream.ts` reads the SSE stream in an unbounded loop with no timeout/abort; `EddySlideOver` pins `isSending` until stream end. Add abort timeout + error state + retry + preserved draft. |
| RESP-01 | Core pages unusable at mobile size | **CONFIRMED (scoped)** | `TopNavbar.tsx:94` right controls are `flex-shrink-0` with no collapse; `EddyLauncher.tsx` is `fixed bottom-5 right-5 z-[80]` over content; board tables lack narrow-viewport treatment. Full task-specific mobile layouts are Phase-2 scope; the overflow/clipping is fixable now. |
| TIME-01 | 145–308-hour-old alerts shown active | **CONFIRMED** | `prod.cockpit_alerts.opened_at` is stamped once and never expires; `AlertEngine` clears only on candidate absence over ≥2 snapshots. `AlertTicker` renders verbose `formatDurationSeconds` ("308 hr 22 min 15 sec"). Add TTL auto-expiry + humanized ages. |
| EDDY-02 | Raw prompt/JSON exposed | **CONFIRMED** | `PatientFlowNavigator.tsx:819-843` prefills the composer with serialized context; `flattenInspector` (`:179-187`) `JSON.stringify`s the `timers` array into the detail panel. |
| VR-01 | Cancelled runs overwhelm selector | **CONFIRMED** | `RoundsCommandBar.tsx:118` maps **all** runs into the dropdown. (Aggravated by demo seeding, but the selector should archive terminal runs regardless.) |
| A11Y-01 | Ambiguous Approve/Reject names | **CONFIRMED** | `ActionInboxModal.tsx:93-108`, `EddyApprovalCard.tsx:92-107`, `ClinicalPayloadGovernance.tsx:260` — no subject in accessible name. Same defect: three identical "Run" buttons in `AgentInbox.tsx:41`. |
| SCOPE-01 | Filters nested in heading | **CONFIRMED** | `ServiceHuddle.jsx:128-164` — two unlabeled `<select>`s inside `<CardTitle>`. |

## 2. Findings that are by-design or overstated

| Audit item | Ruling | Why |
| --- | --- | --- |
| Stale **synthetic Transport/Staffing** data (Phase-0 asks "confirm whether intended") | **BY-DESIGN — confirmed intended** | Production is deliberately a demo/investor environment (`DEMO_MODE=true`, rolling 6-hour demo refresh). `SourceFreshnessBanner`/`SourceFreshnessBadge`/`ProvenanceBadge` already exist and are mounted on those workspaces. Remaining real gap: provenance does not propagate into dashboard **drill-downs** (audit is right there — Phase 1 item). |
| Login page restructure (make SSO primary, strip marketing panel) | **REJECTED (protected)** | The auth system is production-deployed and governed by `.claude/rules/auth-system.md` (additive changes only; the split-screen design and its palette are deliberate). Only the P3 "environment indicator" idea survives, as an additive element, if desired. |
| Rename **Patient-Flow Arena** | **REJECTED** | "Arena" is the deliberate Part X (object-centric process intelligence) product concept with its own spec; not a naming accident. |
| §7 top-level IA restructure (Command/Flow/Clinical Ops/Intelligence/Improvement/Governance) | **DEFERRED-DECISION** | Conflicts with the shipped Zephyrus 2.0 navigation SSOT (Cockpit/Workspaces/Study). The *principles* (canonical queue, one utilization workspace, decision-intent naming) are adopted below; wholesale re-architecture needs a product ruling, not a remediation commit. |
| Pharmacy Dispense "repeated no data cells" | **OVERSTATED** | `Dispense.tsx` already renders em-dashes and contextual sentences ("No vends in the window — no rate denominator"). No action beyond the shared empty-state vocabulary (Phase 3). |
| "Consolidate PDSA/Active Cycles, Resources, Demand, Executive surfaces" | **PARTIALLY ADOPTED** | Naming/labeling clarity ships now (cheap, high-value); page merges are Phase-2 items behind the same DEFERRED-DECISION gate as the IA restructure. |

## 3. Comprehensive remediation todo

### Phase 0 — this branch (`feature/ux-hfe-remediation`)

**Broken functionality**
- [x] Relocate facility model to `public/facility-models/…`; update `config/facility_models.php` + `.env.example`; hotfix prod env + verify 200. *(ModSec rule untouched.)*
- [x] Turnover Times: null-guards + decision-relevant empty state (all views reading per-site payloads).
- [x] Agent Inbox: loading/error/retry states — never render unknown as `0`.
- [x] Eddy: stream timeout (AbortController), visible failure state, retry, preserved draft.
- [x] Alert lifecycle: TTL auto-expiry in `AlertEngine` (config-driven), humanized ages ("12d 4h", no seconds past 1 h) in `AlertTicker`.

**Safe actions & accessibility**
- [x] Approve/Reject/Run accessible names include the proposal/agent subject (4 components).
- [x] Service Huddle: filters out of the heading, labeled, wording fixed.
- [x] Patient Flow: disambiguate the two "Barriers" controls.

**Screen real estate & page identity**
- [x] Distinct titles/H1s for the seven `/analytics/*` destinations sharing "Operations Intelligence".
- [x] `/improvement/process`: visible H1 via `PageContentLayout`; `/improvement/active` title ↔ nav label aligned.
- [x] Navigation SSOT: every "Resources"/"Demand" leaf disambiguated by domain + time horizon (touches navbar + command palette at once).
- [x] Virtual Rounds: selector defaults to live/recent runs; cancelled runs behind a toggle.
- [x] Eddy evidence: timers rendered as readable rows, not serialized JSON; composer prefill states scope in prose instead of dumping context JSON.
- [x] Mobile: TopNavbar controls collapse instead of clipping; Eddy launcher repositioned/shrunk on narrow viewports.

### Phase 1 — next branch(es), 1–3 weeks
- [ ] Persistent scope banner ("you are viewing: facility/unit/service · as-of · source") — extend `ScopePicker` into a `ScopeBanner`; mount on cockpit + huddles + boards.
- [ ] Propagate `ProvenanceBadge`/freshness into all dashboard drill-downs (`DrillModal` KPI rows).
- [ ] Alert ownership + acknowledgement: `acknowledged_by/at` on `cockpit_alerts`, ticker affordance, dedup grouping.
- [ ] Review-step for approvals: evidence sheet (scope, expected effect, reversibility, freshness) before Approve is enabled.
- [ ] Mobile task flows (per audit): alert review, rounds contribution, governed-action review — task-first layouts, not compressed boards.
- [ ] Turnover/TAT pages: standardized metric-metadata header (denominator, window, exclusions, coverage).

### Phase 2 — consolidation (needs CMIO ruling per §2 above)
- [ ] One Utilization workspace (Block/OR/Primetime/Room-Running/IR as tabs + saved measures).
- [ ] Improvement lifecycle merge (Bottlenecks → Root Cause → PDSA → Library as one flow; Active/PDSA duplication removed).
- [ ] Executive workspace merge (Cockpit exec view, Executive Brief, OKR drill-down, briefing agent as tabs).
- [ ] Resources/Demand route renames to decision-intent names (beyond labels — URLs + controllers).
- [ ] Saved views, role presets, recent scopes.

### Phase 3 — HFE design system
- [ ] `PatientFlowNavigator.css` token reconciliation — the impeccable hook reports 62 pre-existing findings (literal colors outside DESIGN.md, side-tab accents) in the 4D scene's bespoke stylesheet. Pre-dates this branch; needs a deliberate pass mapping the scene palette onto the token canon.
- [ ] Trust-badge vocabulary: Live / Delayed / Stale / Synthetic / Inferred / Unavailable / User-entered (one component; today's three badges converge into it).
- [ ] Empty-state vocabulary: zero ≠ unknown ≠ unavailable ≠ excluded ≠ not-configured ≠ loading (shared primitive).
- [ ] `CollapsibleSection` primitive; apply to Staffing office, dashboard sections, drill-modal detail tables.
- [ ] Forecast card standard: horizon, interval, calibration, decision threshold (RTDC/ED/Periop predictions).
- [ ] Shift-handoff / "changed since last review" layer on cockpit + huddles.

### Phase 4 — continuous validation
- [ ] Authenticated route smoke + console-error gate in CI (Playwright, per `e2e-runner`).
- [ ] Asset-integrity check in deploy (HEAD every `config/facility_models.php` URL post-deploy).
- [ ] Data-contract (Zod) validation on every analytics response — Turnover Times class of bug becomes a typed failure, not a crash.
- [ ] axe-based a11y assertions: unique accessible names for repeated actions; headings contain no controls.
- [ ] Visual regression at supported breakpoints.

## 4. Notes for the deployer

- The model-asset fix requires **no ModSecurity change**. Prod hotfix = copy `public/facility-models/` + set `ZEPHYRUS_500_MODEL_URL=/facility-models/zep-500/hospital_model.glb` + `php artisan config:cache`. The repo change makes the hotfix permanent on next `./deploy.sh`.
- `/var/www/Zephyrus/.git` is a stale `master` checkout (HEAD `7d39f5a`) unrelated to what's actually deployed; do not diagnose prod from its git metadata.
- Alert TTL default is deliberately conservative (72 h) and config-gated: `COCKPIT_ALERT_TTL_HOURS`.
