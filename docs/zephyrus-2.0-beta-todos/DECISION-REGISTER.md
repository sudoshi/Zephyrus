# Decision Register

Purpose: replace conflicting decision numbering with one stable register for beta execution. This register preserves the PRD's `D1-D12` IDs and adds local implementation decisions as `D13+`.

Rules:

- B0 owns this register.
- A decision can be `Open`, `Resolved`, `Time-boxed`, or `Deferred`.
- A phase cannot start if it depends on an `Open` decision unless the phase entry gate names an explicit default.
- A resolved decision must name the artifact that enforces it: code, config, test, doc, or limitation.
- If a default is used for beta, the known limitations register must state what would change after beta.

## Stable Decisions

| ID | Decision | PRD Default / Proposed Default | Blocking Phases | Owner Agent | Required Artifact | Status |
| --- | --- | --- | --- | --- | --- | --- |
| D1 | Which domains may remain synthetic? | Only non-hero scorecards; disclose or hide. | B1, B3, B7, B8 | Orchestrator + Data | synthetic/live domain table, UI/API labels, limitations | Open |
| D2 | CDU2 / 500 versus 516 bed story | Use HOSP1 display truth consistently; do not mix counts in demo. | B1, B2, B4, B8 | Data | facility identity doc, seed proof queries | Open |
| D3 | Old dashboard rollback strategy | Redirect with rollback path for one release if practical. | B0, B3, B8 | Frontend + Ops | route disposition table, redirect tests, rollback note | Open |
| D4 | Service-scope patient access for hospitalist/intensivist | 403/disabled if not implemented; do not overexpose. | B3, B6, B7, B8 | Security | policy tests, disabled-state screenshots | Open |
| D5 | Barrier taxonomy storage | Config first acceptable; DB-backed only if editing/admin required. | B4, B5, B8 | Backend + Data | taxonomy source file/schema and tests | Time-boxed |
| D6 | Demo scenarios storage | API/config registry first; database rows only when needed for reproducibility. | B1, B4, B8 | Backend + Data | scenario registry contract | Resolved |
| D7 | Production demo replacement mode | Off by default; additive overlays only with explicit config. | B1, B4, B8 | Data + Ops | config flag, seed runbook, tests | Open |
| D8 | Eddy local runtime | Gated local loop acceptable; production enablement requires persistence, queue, policy, evals. | B5, B8 | Backend + Ops | runtime mode doc, persistence tests, deployment note | Time-boxed |
| D9 | Cloud model use | Aggregate only unless BAA/de-id and policy permit patient context. | B5, B6, B8 | Security | provider policy, PHI eval, limitation if needed | Time-boxed |
| D10 | APNs/FCM credentials | Required for real push demo; otherwise fetch-on-open/manual refresh disclosed. | B6, B8 | Mobile + Ops | push posture doc, build/runtime evidence | Time-boxed |
| D11 | Part X Arena in beta | Roadmap/reference only unless explicitly promoted. | B7, B8 | Orchestrator | Study/Arena scope note and limitations | Open |
| D12 | Native 4D viewer in beta | Not required; Flow Window and web 4D carry beta unless promoted. | B6, B8 | Mobile + Frontend | native 4D scope note and limitations | Resolved |
| D13 | PRD authority path | Commit `docs/ZEPHYRUS-2.0-BETA-PRD.md` or committed derivative before implementation claims authority. | B0, all | Documentation | committed authority file and docs index link | Open |
| D14 | Beta auth posture | Demo auto-login allowed only in local/dev unless beta host has explicit compensating controls. | B0, B3, B8 | Security + Ops | auth posture doc, tests/config | Open |
| D15 | Strict no-self-approval semantics | Agent can never approve; decide whether human requester and approver must differ. | B5, B8 | Security + Backend | approval policy tests | Resolved |
| D16 | Patient display policy | Patient names only for privileged roles; MRN not visible in broad beta screenshots; masked/context refs by default. | B3, B4, B6, B8 | Security | shared helper/policy tests and screenshot review | Time-boxed |
| D17 | Mutable API exposure | Mutable/admin routes require session, Sanctum, admin, or scoped token; no throttle-only mutable beta APIs. | B0, B7, B8 | Security + Backend | route inventory and auth tests | Time-boxed |
| D18 | Reverb origins and rate limits | Restrict beta origins; if Reverb disabled, poll fallback must be proven. | B2, B3, B8 | Ops + Security | config review and runtime proof | Open |
| D19 | Rollback trigger/window | PHI exposure, cockpit outage, failed migration, unsafe Eddy behavior, or data corruption triggers rollback; target window must be declared. | B8 | Ops + Orchestrator | rollback rehearsal | Resolved |

## Decision Detail Template

Copy this block below the table when closing a decision:

```markdown
### D# - <decision title>

Status:
Date:
Owner:
Reviewer:
Decision:
Rationale:
Affected requirement IDs:
Blocking phases released:
Enforcing artifacts:
Known limitations entry:
Follow-up:
```

### D5 - Barrier Taxonomy Storage

Status: Time-boxed
Date: 2026-07-09
Owner: Backend + Data
Reviewer: Orchestrator
Decision: Keep the existing config/service taxonomy for beta; DB-backed editing remains post-beta unless an admin editing requirement is promoted.
Rationale: Current beta evidence needs stable categories and tests, not live taxonomy editing.
Affected requirement IDs: PRD-PF-004, PRD-EDDY-003
Blocking phases released: B4, B5, B8 with limitation
Enforcing artifacts: `docs/zephyrus-2.0-beta-todos/B4-patient-flow-4d-eddy-barriers.md`, Patient Flow tests
Known limitations entry: Barrier UI/context screenshots remain open.
Follow-up: Add DB-backed taxonomy only if admin editing becomes beta scope.

### D6 - Demo Scenarios Storage

Status: Resolved
Date: 2026-07-09
Owner: Backend + Data
Reviewer: Orchestrator
Decision: Use an API/config-backed `PatientFlowScenarioRegistry` for beta scenario metadata.
Rationale: The registry is deterministic, testable, and sufficient for demo scenario selection without adding mutable scenario tables.
Affected requirement IDs: PRD-PF-003, PRD-DEMO-002, PRD-DEMO-003, PRD-DEMO-005
Blocking phases released: B4, B8
Enforcing artifacts: `GET /api/patient-flow/demo-scenarios`, `GET /api/mobile/v1/flow/demo-scenarios`, route smoke tests
Known limitations entry: Visual scenario walkthroughs remain incomplete.
Follow-up: Move scenarios to database rows only if runtime editing/reseeding requires it.

### D10 - APNs/FCM Credentials

Status: Time-boxed
Date: 2026-07-09
Owner: Mobile + Ops
Reviewer: Security
Decision: Real push is not required for the current hardening deploy; fetch-on-open/manual refresh remains the disclosed demo posture until PHI-free APNs/FCM evidence is captured.
Rationale: Native push credentials and screenshots are not available in this Linux validation environment.
Affected requirement IDs: PRD-HB-005, PRD-HB-007, PRD-GATE-PHI-001
Blocking phases released: B6, B8 with limitation
Enforcing artifacts: `docs/beta-known-limitations.md`, B6 evidence README
Known limitations entry: Push-notification PHI review remains open.
Follow-up: Run `hummingbird:test-push` with approved beta credentials and archive payload/device evidence.

### D12 - Native 4D Viewer In Beta

Status: Resolved
Date: 2026-07-09
Owner: Mobile + Frontend
Reviewer: Orchestrator
Decision: Native 4D viewer is not required for this beta; Hummingbird uses Flow Window/history/scenario BFF contracts while web carries the 4D navigator.
Rationale: This keeps mobile parity tied to backend truth without forcing an unproven native 3D implementation into beta signoff.
Affected requirement IDs: PRD-HB-008, PRD-PF-006
Blocking phases released: B6, B8
Enforcing artifacts: mobile `/api/mobile/v1/flow/*` routes and native client calls
Known limitations entry: Native screenshot matrix remains open.
Follow-up: Promote native 4D only after iOS/Android visual validation exists.

### D15 - Strict No-Self-Approval Semantics

Status: Resolved
Date: 2026-07-09
Owner: Security + Backend
Reviewer: Orchestrator
Decision: Agent/scoped-token callers can never approve. Human session/mobile roles may approve according to the action minimum-role policy; strict human requester/approver separation is not enforced for beta.
Rationale: The beta risk boundary is preventing agent/token self-approval and role bypass; stricter dual-control remains a policy enhancement.
Affected requirement IDs: PRD-EDDY-001, PRD-EDDY-004, PRD-HB-003
Blocking phases released: B5, B8
Enforcing artifacts: `EddyActionService`, Eddy route gates, mobile Eddy approval gate, `EddyActionTest`, `ApiAuthorizationTest`, `MobileBackendSafetyTest`
Known limitations entry: Full execute-to-outcome loop remains open.
Follow-up: Add strict two-person approval if clinical governance requires it.

### D16 - Patient Display Policy

Status: Time-boxed
Date: 2026-07-09
Owner: Security
Reviewer: Mobile + Backend
Decision: Broad/aggregate Patient Flow and mobile history payloads must not expose raw patient or encounter identifiers; tested detail rows use `ptok_` context refs.
Rationale: Automated API proof can enforce payload safety now; screenshot review is still needed for visual disclosure.
Affected requirement IDs: PRD-PF-005, PRD-HB-007, PRD-GATE-PHI-001
Blocking phases released: B4, B6, B8 with limitation
Enforcing artifacts: `PatientFlowOccupancyHistoryService`, `MobilePatientContextService`, `FlowWindowTest`, `PatientFlowApiTest`
Known limitations entry: Manual screenshot PHI review remains open.
Follow-up: Complete web/native screenshot review before final beta signoff.

### D17 - Mutable API Exposure

Status: Time-boxed
Date: 2026-07-09
Owner: Security + Backend
Reviewer: Orchestrator
Decision: The hardened route families now require explicit session, Sanctum, role, or scoped-token authorization; remaining unreviewed route families stay known-limited.
Rationale: This pass closed mobile write, Eddy, and OR case write gaps found by adversarial review.
Affected requirement IDs: PRD-SEC-002, PRD-HB-003, PRD-EDDY-001
Blocking phases released: B5, B6, B8 with limitation
Enforcing artifacts: `AuthServiceProvider`, `AuthorizesMobilePersonaActions`, `ApiAuthorizationTest`, `ApiRouteSmokeTest`, `MobileBackendSafetyTest`
Known limitations entry: Full admin/browser smoke remains open.
Follow-up: Expand authorization matrix to every mutable/admin route before final production signoff.

### D19 - Rollback Trigger And Window

Status: Resolved
Date: 2026-07-09
Owner: Ops + Orchestrator
Reviewer: Backend/Data
Decision: Rollback triggers are PHI exposure, cockpit/vhost outage, failed deploy/migration, unsafe Eddy behavior, mobile BFF unavailability, scheduler/queue degradation, or data corruption. Rollback is app-artifact/commit based for the post-review hardening slice; additive Patient Flow columns remain in place unless an ops-approved database restore is required.
Rationale: The current hardening code adds no migrations, while the earlier Patient Flow migration is additive and already required by deployed history/snapshot behavior.
Affected requirement IDs: PRD-OPS-003
Blocking phases released: B8
Enforcing artifacts: `evidence/B8/rollback/rehearsal.md`, deploy evidence
Known limitations entry: Production rollback drill not executed.
Follow-up: Archive a real rollback drill or staging-equivalent before final beta signoff.

## Phase Blocking Map

| Phase | Decisions That Must Be Resolved Or Time-Boxed |
| --- | --- |
| B0 | D1-D19 register created, ownership assigned, and defaults accepted or rejected. |
| B1 | D1, D2, D6, D7, D13. |
| B2 | D1, D2, D18. |
| B3 | D3, D4, D14, D16, D17, D18. |
| B4 | D2, D5, D6, D7, D16. |
| B5 | D8, D9, D15, D17. |
| B6 | D4, D9, D10, D12, D16. |
| B7 | D1, D4, D11, D17. |
| B8 | All decisions either resolved or documented as known limitations. |
