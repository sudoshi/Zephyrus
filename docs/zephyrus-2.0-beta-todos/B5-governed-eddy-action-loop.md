# B5 - Governed Eddy Action Loop

Goal: complete Eddy as a governed hospital-operations copilot that can explain context, draft safe actions, route human approval, execute supported actions through domain adapters, and audit the outcome.

Primary source: PRD section `B5 - Governed Eddy Action Loop`.

Exit principle: Eddy is beta-complete only when an operator can follow a real signal to a governed action and see the resulting state and audit trail, without granting Eddy unsupervised write authority.

## Current Evidence

- Eddy action catalog/propose/token routes exist.
- Agent token lacks `ops:approve`.
- `EddyActionService` creates recommendations, actions, and approvals.
- Tests cover basic propose and approval constraints.
- 2026-07-09 local implementation proves that token callers remain draft-only even if a token is misissued with `ops:approve`.
- 2026-07-09 local implementation expands action-catalog safety metadata and tests the required schema fields.
- 2026-07-09 adversarial hardening adds `useEddyActions` gates to web Eddy propose/token routes and enforces role eligibility inside `EddyActionService`.
- 2026-07-09 adversarial hardening checks Hummingbird Eddy approval decisions against the same minimum-role policy instead of relying only on broad token ability.
- Python local agent loop exists but is gated/stubbed and not proven as durable production state.
- Full execution adapters and end-to-end loop are not proven.

## Deliverables

- [x] Final no-self-approval decision and enforcement for agent/scoped tokens.
- [x] Complete tool/action catalog with safety metadata for locally registered catalog entries.
- [ ] Dry-run descriptors and rollback descriptions for every write action.
- [ ] Durable agent run/tool-call persistence.
- [ ] Domain execution adapters for beta actions.
- [ ] Web and Hummingbird approval/action parity.
- [ ] Prompt-injection, PHI, no-SQL, no-write-tool, and approval tests.
- [ ] One archived full demo loop.

## Phase Entry Gate

B5 may start only after:

- [ ] B2 has handed off the shared snapshot/trust contract.
- [ ] B4 has handed off Patient Flow selected/all-barrier context if B5 demo actions depend on barriers.
- [ ] D8, D9, D15, and D17 are resolved or time-boxed.
- [ ] Current `EddyActionTest` behavior is reviewed, especially whether one-step human propose-and-approve is still allowed.
- [ ] Python local loop dependencies and test command are inventoried.
- [ ] Requirement IDs `PRD-EDDY-001` through `PRD-EDDY-008` are assigned.

Preflight commands:

```bash
git status --short --branch
php artisan route:list --path=eddy
php artisan test --filter=EddyActionTest
php artisan test --filter=AgentControlPlaneTest
cd eddy && python -m pytest
```

If `cd eddy && python -m pytest` fails due missing local Python dependencies, record dependency setup as a B5 task before treating the local loop as validated.

## Agent Swarm

| Work Package | Owner Agent | Reviewer | Handoff Recipient | Required Artifact |
| --- | --- | --- | --- | --- |
| Approval/no-self policy | Security Agent | Backend Agent | B6/B8 owners | D15 enforcement tests |
| Tool catalog schema | Backend/Eddy Agent | QA Agent | Frontend/Mobile owners | catalog JSON and schema tests |
| Dry-run previews | Backend/Eddy Agent | Security Agent | B3/B6 owners | dry-run samples and PHI review |
| Execution adapters | Backend Agent | Ops Agent | B7/B8 owners | adapter list, tests, rollback notes |
| Durable agent run state | Backend/Eddy Agent | Ops Agent | B8 owner | persistence proof and local loop tests |
| Web/mobile parity | Frontend + Mobile Agents | Security Agent | B6/B8 owners | approval/action screenshots and API samples |
| Full E2E loop | Orchestrator | QA Agent | B8 owner | demo loop rehearsal artifact |

## Agent Execution Contract

Owned write scope:

- `app/Services/Eddy/EddyActionService.php`.
- `app/Http/Controllers/Api/Eddy/EddyActionController.php`.
- `app/Services/Ops/Agents/*`.
- `eddy/app/routers/agent.py`.
- `eddy/app/agent/*`.
- Eddy web components only for approval/catalog UI.
- mobile Eddy/approval BFF mappings only where needed for parity.
- Eddy/Python/Ops tests.

Read-only first:

- `tests/Feature/Eddy/EddyActionTest.php`.
- `tests/Feature/Ops/AgentControlPlaneTest.php`.
- `tests/Feature/Mobile/Eddy/EddyMobileBffTest.php`.
- `tests/Feature/MobileBackendSafetyTest.php`.
- `routes/api.php`.
- `app/Models/Ops/*` action/approval/audit models.

Do not touch:

- Patient Flow scenario generation owned by B4.
- broad Hummingbird role packages owned by B6 except approval/action parity.
- domain completion owned by B7 except the specific execution adapters selected for beta.

## Tool Catalog Contract

Every Eddy tool/action must declare:

| Field | Required Meaning |
| --- | --- |
| `tool_key` | stable machine key |
| `label` | operator-facing label |
| `domain` | cockpit, patient_flow, rtdc, ed, periop, transport, evs, staffing, improvement, integration, or study |
| `min_role` | minimum human role needed to see/use |
| `required_scopes` | token/session scopes required |
| `risk_tier` | low/medium/high or approved enum |
| `phi_policy` | aggregate/deidentified/privileged-only/no-phi |
| `draft_only` | true when no execution adapter exists |
| `dry_run_schema` | structured preview output |
| `input_schema` | validated request shape |
| `output_schema` | execution/result shape |
| `execution_adapter` | class or null with disabled/draft reason |
| `rollback_adapter` | rollback class or `not_supported` reason |
| `approval_policy` | agent prohibited, human approval requirements, D15 self-approval rule |
| `audit_event_type` | durable action/activity event |
| `web_available` | visible in Zephyrus web |
| `mobile_available` | visible in Hummingbird |
| `enabled` | beta-enabled boolean |
| `disabled_reason` | required when disabled |

Policy:

- [x] No enabled write tool may lack dry-run schema, approval policy, audit event, and execution/draft-only declaration.
- [x] Draft-only tools must show draft-only status in web/mobile/Eddy responses.
- [ ] Disabled tools must be omitted from action affordances or displayed with disabled reason.
- [x] Agent tokens must never include approval scope.
- [ ] If D15 forbids human self-approval, tests must prove proposer and approver differ.

## Execution Adapter List

B5 must classify every beta tool:

| Tool | Adapter Status | Required Test | Rollback Posture |
| --- | --- | --- | --- |
| capacity snapshot/read | executable read-only | existing/extended control-plane test | no rollback needed |
| draft bed-placement recommendation | draft-only or adapter | Eddy action + RTDC test | rollback or no-write label |
| draft huddle agenda | draft-only or adapter | huddle draft test | no-write label unless persisted |
| transport barrier escalation | adapter or disabled | transport API/action test | cancel/escalation reversal note |
| EVS barrier escalation | adapter or disabled | EVS API/action test | cancel/escalation reversal note |
| staffing mitigation draft | draft-only or adapter | staffing action test | reversal note |
| discharge-barrier follow-up | draft-only or adapter | Patient Flow/action test | reversal note |
| regional transfer recommendation | draft-only by default | transfer graph/Eddy test | no-write label |
| Study handoff | adapter or disabled | improvement/study event test | close/reopen note |

## Validation Inventory

| Validation | Existing Or To Create | Required Evidence |
| --- | --- | --- |
| `EddyActionTest` | Existing | extend for D15, metadata, dry-run, disabled/draft-only tools |
| `AgentControlPlaneTest` | Existing | tool-call persistence/control-plane proof |
| `EddyMobileBffTest` | Existing | mobile Eddy parity when BFF changes |
| Python local loop tests | Existing under `eddy/tests` | `cd eddy && python -m pytest` output |
| tool catalog schema test | To create/extend | every enabled/draft/disabled tool validates |
| execution adapter tests | To create per adapter | lifecycle record plus domain side effect or explicit draft-only state |
| PHI/prompt-injection evals | To create/extend | no raw PHI/log leakage, no SQL/no-write bypass |
| full E2E loop | To rehearse | cockpit -> Eddy -> approval -> Hummingbird -> ledger -> web outcome |

## Criticality Double Check

B5 must pass or defer:

- Eddy governance.
- Authorization.
- PHI/privacy.
- API contract.
- Data trust.
- Observability.
- Security headers/config where agent runtime is exposed.
- Testing.
- Deployment.
- Rollback.
- Documentation.

## Evidence Package

Create:

- [ ] `evidence/B5/README.md`.
- [ ] `api/eddy-tool-catalog.json`.
- [ ] `api/eddy-dry-run-preview.json`.
- [ ] `api/eddy-approval-policy-agent-denied.json`.
- [ ] `commands/eddy-php-tests.txt`.
- [ ] `commands/eddy-python-pytest.txt`.
- [ ] `reviews/no-self-approval-policy.md`.
- [ ] `reviews/tool-catalog-review.md`.
- [ ] `reviews/phi-prompt-injection-review.md`.
- [ ] `demo/full-governed-action-loop.md`.

## Workstream 5.1: Governance Semantics

- [x] Decide no-self-approval semantics:
  - [x] Agent can never approve.
  - [x] Human requester can approve their own draft.
  - [ ] Human requester cannot approve their own draft.
  - [ ] Escalation role can override with audit.
- [ ] Encode the decision in:
  - [x] Service logic.
  - [x] Tests.
  - [ ] UI copy.
  - [x] Mobile API policy.
  - [x] Release notes.
- [ ] Ensure actions have states:
  - [ ] Recommended.
  - [ ] Drafted.
  - [ ] Pending approval.
  - [ ] Approved.
  - [ ] Rejected.
  - [ ] Executing.
  - [ ] Executed.
  - [ ] Failed.
  - [ ] Rolled back where supported.
  - [ ] Expired.
- [ ] Ensure every transition records actor, role, timestamp, source, and reason.

Suggested files:

- `app/Services/Eddy/EddyActionService.php`.
- `app/Http/Controllers/Api/Eddy/EddyActionController.php`.
- `tests/Feature/Eddy/EddyActionTest.php`.

## Workstream 5.2: Tool Catalog Metadata

- [x] Define required metadata for every Eddy tool/action:
  - [x] Tool key.
  - [x] Display label.
  - [x] Domain.
  - [x] Minimum role.
  - [x] Risk tier.
  - [x] PHI policy.
  - [x] Required scopes.
  - [x] Dry-run support.
  - [x] Rollback support.
  - [x] Execution adapter class.
  - [x] Approval policy.
  - [x] Input schema.
  - [x] Output schema.
  - [x] Audit event type.
  - [x] Mobile availability.
  - [x] Web availability.
  - [x] Enabled state.
  - [x] Disabled reason.
- [x] Add tests that no enabled write tool lacks required metadata.
- [ ] Add UI/API display of disabled reasons.
- [ ] Add a beta operator view of the catalog.
- [ ] Add OpenAPI or schema tests for tool inputs.

Suggested tool groups:

- [ ] Draft bed-placement recommendation.
- [ ] Draft huddle agenda.
- [ ] Assign or escalate transport barrier.
- [ ] Assign or escalate EVS barrier.
- [ ] Draft staffing mitigation.
- [ ] Draft discharge-barrier follow-up.
- [ ] Draft regional transfer recommendation.
- [ ] Open study handoff.

## Workstream 5.3: Dry-Run And Preview

- [ ] Add dry-run output for every write-capable tool.
- [ ] Dry-run output must include:
  - [ ] Plain-language action summary.
  - [ ] Affected scope.
  - [ ] Affected patients only if role permits.
  - [ ] Source metric/event.
  - [ ] Expected impact.
  - [ ] Risk.
  - [ ] Rollback path or "not rollbackable" label.
  - [ ] Approval requirement.
  - [ ] Audit event that would be created.
- [ ] UI must show dry-run preview before approval for high-risk actions.
- [ ] Mobile must show compact preview before approval/action.
- [ ] Tests must assert no high-risk write bypasses dry-run.

## Workstream 5.4: Execution Adapters

- [ ] Separate action lifecycle from domain execution adapters.
- [ ] For each beta action, implement or explicitly disable the backing adapter:
  - [ ] Bed placement action adapter.
  - [ ] Patient Flow barrier escalation adapter.
  - [ ] Huddle draft adapter.
  - [ ] Transport/EVS escalation adapter.
  - [ ] Staffing mitigation adapter.
  - [ ] Improvement/study handoff adapter.
- [ ] Each adapter must:
  - [ ] Validate input schema.
  - [ ] Check authorization.
  - [ ] Enforce dry-run for required risk tier.
  - [ ] Execute idempotently.
  - [ ] Write an audit event.
  - [ ] Write mobile activity where relevant.
  - [ ] Return updated domain state reference.
  - [ ] Fail safely with actionable error.
- [ ] Add tests for success, unauthorized, invalid input, duplicate execution, stale source, and failed dependency.

Implementation rule:

- If a tool has no adapter, it must be disabled or draft-only with visible labeling.

## Workstream 5.5: Durable Agent Run State

- [ ] Decide whether Eddy local loop runs through:
  - [ ] Existing Python service.
  - [ ] Claude SDK path.
  - [ ] Laravel-only deterministic agent control plane.
  - [ ] Hybrid.
- [ ] Persist agent runs and tool calls durably.
- [ ] Avoid in-memory-only session state for beta operation.
- [ ] Capture:
  - [ ] Prompt/context version.
  - [ ] Actor.
  - [ ] Model/provider where applicable.
  - [ ] Tools available.
  - [ ] Tool calls attempted.
  - [ ] Tool calls blocked.
  - [ ] PHI/redaction mode.
  - [ ] Source snapshot id.
  - [ ] Final recommendation/action ids.
- [ ] Add admin/operator inspection view or API.
- [ ] Add pruning/retention policy.

Suggested files:

- `eddy/app/routers/agent.py`.
- `eddy/app/agent/local_loop.py`.
- `app/Services/Ops/*Agent*`.
- `database/migrations/*agent*`.
- `tests/Feature/Ops/AgentControlPlaneTest.php`.

## Workstream 5.6: Safety And Abuse Tests

- [ ] Add or extend tests for:
  - [x] Agent cannot approve.
  - [x] Human self-approval rule.
  - [ ] No SQL execution.
  - [ ] Prompt injection resistance.
  - [ ] Tool not in catalog cannot execute.
  - [ ] Disabled tool cannot execute.
  - [ ] Dry-run required for high-risk action.
  - [ ] PHI minimization in aggregate contexts.
  - [ ] Stale source blocks or downgrades recommendations.
  - [x] Unauthorized mobile role cannot approve or execute tested mobile action families.
  - [ ] Duplicate approval/execution is idempotent.
  - [ ] Audit event created exactly once.
- [x] Include tests for both web session and Sanctum/mobile token paths.
- [x] Include tests for agent scoped token path.

Verification:

```bash
php artisan test --filter=EddyActionTest
php artisan test --filter=AgentControlPlaneTest
php artisan test --filter=MobileBackendSafetyTest
```

## Workstream 5.7: Web And Hummingbird Action Parity

- [ ] Web cockpit action inbox must show:
  - [ ] Recommendations.
  - [ ] Drafts.
  - [ ] Pending approvals.
  - [ ] Execution status.
  - [ ] Failure reason.
- [ ] Hummingbird must show equivalent action cards for roles that can act.
- [ ] Mobile action details must include:
  - [ ] Source.
  - [ ] Freshness.
  - [ ] Risk.
  - [ ] Preview.
  - [ ] Approval requirement.
  - [ ] Activity/audit result.
- [ ] API must support both web and mobile without divergent semantics.
- [ ] Add tests that a web-created recommendation can be seen on mobile and a mobile-completed action is reflected on web.

## Workstream 5.8: End-To-End Demo Loop

Build and archive one full path:

- [ ] Cockpit shows a capacity/flow signal.
- [ ] Operator drills into domain or Patient Flow.
- [ ] Eddy receives source-aware context.
- [ ] Eddy drafts a recommendation/action.
- [ ] Human reviews dry-run preview.
- [ ] Human approves according to governance policy.
- [ ] Execution adapter updates domain state.
- [ ] Hummingbird displays the action/activity.
- [ ] Web cockpit/action inbox reflects outcome.
- [ ] Audit/ledger contains source, actor, approval, execution, and result.
- [ ] Screenshots and API payload snippets are archived.

Recommended scenario:

- ED boarder to inpatient bed with Patient Flow barrier context and bed/EVS/transport action routing.

## Phase Exit Gate

This phase is complete only when:

- [x] Governance semantics are decided, implemented, and tested.
- [x] Enabled tools have complete metadata.
- [ ] High-risk writes require dry-run preview.
- [ ] Beta tools have execution adapters or are visibly disabled/draft-only.
- [ ] Agent run/tool-call state is durable.
- [ ] Safety and abuse tests pass.
- [ ] Web and Hummingbird action states match.
- [ ] One full demo loop is archived with screenshots, API evidence, and audit rows.
