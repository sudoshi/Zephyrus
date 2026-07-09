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
| D5 | Barrier taxonomy storage | Config first acceptable; DB-backed only if editing/admin required. | B4, B5, B8 | Backend + Data | taxonomy source file/schema and tests | Open |
| D6 | Demo scenarios storage | API/config registry first; database rows only when needed for reproducibility. | B1, B4, B8 | Backend + Data | scenario registry contract | Open |
| D7 | Production demo replacement mode | Off by default; additive overlays only with explicit config. | B1, B4, B8 | Data + Ops | config flag, seed runbook, tests | Open |
| D8 | Eddy local runtime | Gated local loop acceptable; production enablement requires persistence, queue, policy, evals. | B5, B8 | Backend + Ops | runtime mode doc, persistence tests, deployment note | Open |
| D9 | Cloud model use | Aggregate only unless BAA/de-id and policy permit patient context. | B5, B6, B8 | Security | provider policy, PHI eval, limitation if needed | Open |
| D10 | APNs/FCM credentials | Required for real push demo; otherwise fetch-on-open/manual refresh disclosed. | B6, B8 | Mobile + Ops | push posture doc, build/runtime evidence | Open |
| D11 | Part X Arena in beta | Roadmap/reference only unless explicitly promoted. | B7, B8 | Orchestrator | Study/Arena scope note and limitations | Open |
| D12 | Native 4D viewer in beta | Not required; Flow Window and web 4D carry beta unless promoted. | B6, B8 | Mobile + Frontend | native 4D scope note and limitations | Open |
| D13 | PRD authority path | Commit `docs/ZEPHYRUS-2.0-BETA-PRD.md` or committed derivative before implementation claims authority. | B0, all | Documentation | committed authority file and docs index link | Open |
| D14 | Beta auth posture | Demo auto-login allowed only in local/dev unless beta host has explicit compensating controls. | B0, B3, B8 | Security + Ops | auth posture doc, tests/config | Open |
| D15 | Strict no-self-approval semantics | Agent can never approve; decide whether human requester and approver must differ. | B5, B8 | Security + Backend | approval policy tests | Open |
| D16 | Patient display policy | Patient names only for privileged roles; MRN not visible in broad beta screenshots; masked/context refs by default. | B3, B4, B6, B8 | Security | shared helper/policy tests and screenshot review | Open |
| D17 | Mutable API exposure | Mutable/admin routes require session, Sanctum, admin, or scoped token; no throttle-only mutable beta APIs. | B0, B7, B8 | Security + Backend | route inventory and auth tests | Open |
| D18 | Reverb origins and rate limits | Restrict beta origins; if Reverb disabled, poll fallback must be proven. | B2, B3, B8 | Ops + Security | config review and runtime proof | Open |
| D19 | Rollback trigger/window | PHI exposure, cockpit outage, failed migration, unsafe Eddy behavior, or data corruption triggers rollback; target window must be declared. | B8 | Ops + Orchestrator | rollback rehearsal | Open |

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
