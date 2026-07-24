# Zephyrus 2.0 Beta Todo Set

Generated from `docs/product/ZEPHYRUS-2.0-BETA-PRD.md` on 2026-07-08 after a local repo audit and three read-only swarm passes:

- Backend/API/domain explorer.
- Frontend/product/mobile explorer.
- Data/deployment/security explorer.

This folder is an execution layer for the PRD. It does not replace the PRD. Every checkbox below and in the phase files should be treated as incomplete until the code, tests, screenshots, runbooks, and evidence named in the task are all present.

## File Map

| File | Purpose |
| --- | --- |
| `AGENTIC-EXECUTION-WORKFLOW.md` | Master operating model for multi-agent implementation, double-checking, evidence capture, deploy, and rollback. |
| `ENGINEERING-CRITICALITIES.md` | Required review dimensions for every phase: auth, PHI, contracts, data trust, migrations, UI, mobile, Eddy, ops, rollback, and docs. |
| `TRACEABILITY-MATRIX.md` | Stable PRD requirement IDs mapped to phases, surfaces, proof, and final B8 artifacts. |
| `DECISION-REGISTER.md` | Stable D1-D19 decision register replacing conflicting local numbering. |
| `ACCEPTANCE-EVIDENCE-PACKAGE.md` | Required evidence directory structure, metadata, naming, screenshot/API rules, and signoff rules. |
| `VALIDATION-INVENTORY.md` | Existing commands/tests versus validation assets each phase must create before claiming completion. |
| `00-uncompleted-work-analysis.md` | Reconciled analysis of what is already shipped, partial, missing, or unproven against the PRD. |
| `B0-documentation-contract-freeze.md` | Freeze the contract, fix stale docs, track the PRD, and add drift/smoke guardrails. |
| `B1-demo-data-trust-foundation.md` | Make HOSP1/Summit demo data repeatable and visibly trustworthy. |
| `B2-cockpit-status-snapshot-foundation.md` | Prove the cockpit/status/snapshot foundation end to end and fill trust metadata gaps. |
| `B3-unified-web-experience.md` | Finish the one-home web shell, route convergence, mock gating, PHI posture, and visual evidence. |
| `B4-patient-flow-4d-eddy-barriers.md` | Complete Patient Flow 4D history, demo scenarios, barrier intelligence, and Eddy context. |
| `B5-governed-eddy-action-loop.md` | Complete Eddy's governed propose -> approve -> execute -> audit loop. |
| `B6-hummingbird-demo-parity.md` | Finish Hummingbird parity, role packages, push/offline/security, and mobile screenshots. |
| `B7-domain-completion-study-handoff.md` | Remove or label remaining domain mocks and finish RTDC, ED, periop, transport, staffing, improvement, and study handoff. |
| `B8-beta-hardening-demo-package.md` | Assemble the release package, hardening evidence, demo script, deploy checklist, and rollback proof. |

## Current Reconciled Baseline

The PRD is not a greenfield build. The repo already has major seams that should be preserved and hardened:

- `/dashboard` is already the cockpit home, backed by `CommandCenterController`, `SnapshotBuilder`, `StatusEngine`, and `MetricValue`.
- `resources/js/config/navigationConfig.ts` is the current navigation source of truth. The older `DashboardContext` description in `AGENTS.md` is stale for current code.
- Cockpit snapshot, metric-trust tables, source registry, integration ledger, canonical events, provenance, dead-letter, replay, and synthetic connector foundations already exist.
- Mobile BFF routes and parity tests already exist for a broad Hummingbird surface.
- Eddy already has scoped-token draft/propose routes and cannot approve with its agent token.
- Patient Flow 4D already has navigator APIs, occupancy context, barrier taxonomy, redaction, projections, and Eddy context.

The remaining beta work is mostly:

- Prove the foundations with automated, visual, and operational gates.
- Fill named PRD gaps such as Patient Flow occupancy history and demo scenario registry.
- Finish live-vs-synthetic labeling and remove risky fallback mocks from beta surfaces.
- Complete governed Eddy execution beyond lifecycle records.
- Harden mobile push/offline/security and prove parity across iOS, Android, and web.
- Package repeatable demo data, release validation, and production operations evidence.

## 2026-07-09 Local Implementation Addendum

The first implementation pass has moved several formerly missing B0/B4/B5/B6/B7 items into locally proven, not-yet-deployed state:

- Patient Flow now has `GET /api/patient-flow/occupancy/history` and `GET /api/patient-flow/demo-scenarios`.
- Live `flow:snapshot` writes now persist redactable occupancy details and aggregate counts.
- Eddy agent/scoped tokens are draft-only even if misissued with approval scope.
- The Eddy action catalog now exposes safety metadata required by the PRD.
- Hummingbird mobile writes can replay safely with deterministic idempotency keys across the BFF, Android, and iOS clients.
- Android backup, transfer, and cleartext posture is hardened and locally build-validated.
- API route smoke and API authorization matrix tests now exist.
- Periop case APIs were corrected away from stale table references.

These are still not final beta-complete until B8 archives full-suite, visual, iOS, deployment, rollback, and demo-rehearsal evidence.

## Execution Rules

- Start with `AGENTIC-EXECUTION-WORKFLOW.md`; every phase must follow its nine-step execution loop.
- Use `TRACEABILITY-MATRIX.md` IDs in commits, PR descriptions, test names where practical, screenshot indexes, demo scripts, and release notes.
- Resolve or time-box phase-blocking decisions in `DECISION-REGISTER.md` before coding the dependent phase.
- Copy the review form from `ENGINEERING-CRITICALITIES.md` into each phase evidence README and close every row as `Pass`, `Deferred`, `Fail`, or `Not applicable`.
- Store or point to proof using `ACCEPTANCE-EVIDENCE-PACKAGE.md`; a checked box without evidence is not complete.
- Use `VALIDATION-INVENTORY.md` before running or documenting commands; do not mark planned tests such as `ApiRouteSmokeTest` or `ApiAuthorizationTest` green until the files exist.
- Do phases in order unless a later phase is explicitly split into an independent PR with its own gate.
- Keep Zephyrus web and Hummingbird parity as a hard acceptance rule for shared product capabilities.
- Do not count a mock as complete unless it is explicitly labeled as synthetic/demo in the UI, API response, metric metadata, and release notes.
- Do not count a backend route as complete unless its authorization, redaction, provenance, failure mode, and route-smoke coverage are also complete.
- Do not count a mobile workflow as complete unless web parity, backend contract tests, native screenshot evidence, and activity/audit ledger behavior are present.
- Do not deploy by GitHub Actions, direct production `git pull`, ad hoc SSH command blocks, or alternate deploy scripts. `./deploy.sh` remains the application deployment path; migrations and post-deploy checks must be run explicitly.

## Agentic Phase Protocol

Every `B0` through `B8` file now has, or must be expanded to have, the following sections:

1. `Phase Entry Gate`: predecessor work, decisions, seed state, branch state, and blockers.
2. `Agent Swarm`: role ownership, reviewers, handoff recipients, and artifacts.
3. `Implementation Work Packages`: concrete code/doc/test/data/UI/mobile/ops packages with owned files.
4. `Criticality Double Check`: phase-specific use of the engineering criticality matrix.
5. `Evidence Package`: required command logs, API samples, screenshots, native artifacts, review notes, and deploy handoff.
6. `Phase Exit Gate`: final pass/fail/defer criteria.

Do not let agents start from the workstream checklist alone. The workstreams say what to build; the agentic protocol says how to execute, prove, and hand off the work safely.

## Phase Dependency Graph

| Phase | Must Receive | Must Hand Off |
| --- | --- | --- |
| B0 | PRD, current repo state, swarm audit | authority path, decision register, traceability IDs, route/auth/nav guardrails |
| B1 | B0 decisions D1/D2/D6/D7/D13 | deterministic seed, provenance labels, scenario keys, degraded feed proof |
| B2 | B1 seed/trust contracts | versioned snapshot contract, status parity, freshness proof, runtime refresh proof |
| B3 | B0 nav/auth decisions and B2 snapshot contract | unified web shell, route disposition, mock/PHI policy, visual baseline |
| B4 | B1 scenario keys and B2 status/trust contract | Patient Flow history/scenarios/barriers/Eddy context and web/mobile parity |
| B5 | B2 snapshot contract and B4 barrier context | governed action catalog, no-self-approval proof, executable/dry-run adapters |
| B6 | B2/B4/B5 backend contracts | mobile parity, push/offline/security proof, screenshots, native builds |
| B7 | B1-B6 shared contracts | domain completion matrix, live/synthetic cleanup, Study handoff proof |
| B8 | all prior evidence | deploy/release package, final demo rehearsal, rollback proof, limitations |

## Cross-Phase Validation Commands

These commands are intentionally repeated in phase files when they are phase-specific gates:

```bash
php artisan route:list
php artisan test
php artisan test --filter=CockpitSnapshotApiTest
php artisan test --filter=PatientFlowApiTest
php artisan test --filter=MobileBffTest
php artisan test --filter=MobileBackendSafetyTest
php artisan test --filter=EddyActionTest
php artisan test --filter=AgentControlPlaneTest
npm run test
npm run build
./scripts/check-ui-canon.sh
npm run test:e2e
```

Mobile validation should also include native build/test commands from the Hummingbird app directories once the local platform toolchains are available.

Known beta operations commands verified in the repo:

```bash
php artisan zephyrus:demo-seed
php artisan patient-flow:import-synthetic patient-flow-4d-navigator/data/hl7_messages.ndjson --source-key=synthetic-flow-ehr --facility-code=ZEPHYRUS-500
php artisan patient-flow:rebase-synthetic
php artisan flow:snapshot
php artisan rtdc:demo-reset
php artisan rtdc:simulate
php artisan schedule:list
php artisan schedule:run -vvv
php artisan queue:failed
php artisan reverb:start
```

If a phase needs a command that does not exist, implementing that command and its tests is part of the phase.

## Completion Ledger

Use this table to mark phase-level status only after the phase file exit gate is satisfied.

| Phase | Status | Blocking Decisions | Requirement IDs | Evidence Location | Reviewer |
| --- | --- | --- | --- | --- | --- |
| B0 Documentation And Contract Freeze | Not started | D1-D19 ownership | PRD-SEC-001, PRD-SEC-002, PRD-OPS-001, PRD-Z4-001 | Add PR/commit/test links here. | Add reviewer. |
| B1 Demo Data And Trust Foundation | Not started | D1, D2, D6, D7, D13 | PRD-Z6-001, PRD-Z6-002, PRD-Z6-003, PRD-TRUST-001, PRD-TRUST-002 | Add PR/commit/test links here. | Add reviewer. |
| B2 Cockpit Foundation | Not started | D1, D2, D18 | PRD-Z1-001, PRD-Z1-002, PRD-Z1-003, PRD-Z1-004 | Add PR/commit/test links here. | Add reviewer. |
| B3 Unified Web Experience | Not started | D3, D4, D14, D16, D17, D18 | PRD-Z2-001, PRD-Z2-002, PRD-Z2-003, PRD-Z3-001, PRD-Z3-002, PRD-Z4-001, PRD-Z4-002 | Add PR/commit/test links here. | Add reviewer. |
| B4 Patient Flow 4D And Eddy Barrier Intelligence | Not started | D2, D5, D6, D7, D16 | PRD-PF-001 through PRD-PF-007, PRD-DEMO-002, PRD-DEMO-003, PRD-DEMO-005 | Add PR/commit/test links here. | Add reviewer. |
| B5 Governed Eddy Action Loop | Not started | D8, D9, D15, D17 | PRD-EDDY-001 through PRD-EDDY-008, PRD-DEMO-002, PRD-DEMO-005, PRD-GATE-PHI-001 | Add PR/commit/test links here. | Add reviewer. |
| B6 Hummingbird Demo Parity | Not started | D4, D9, D10, D12, D16 | PRD-HB-001 through PRD-HB-008, PRD-GATE-AUTO-004, PRD-GATE-VIS-001 | Add PR/commit/test links here. | Add reviewer. |
| B7 Domain Completion And Study Handoff | Not started | D1, D4, D11, D17 | PRD-Z5-001 through PRD-Z5-006, PRD-DEMO-004, PRD-DEMO-006, PRD-DEMO-007, PRD-DEMO-008 | Add PR/commit/test links here. | Add reviewer. |
| B8 Beta Hardening And Demo Package | Not started | all decisions closed or limitations recorded | PRD-GATE-AUTO-001 through PRD-GATE-DEMO-001, PRD-OPS-001 through PRD-OPS-003 | Add PR/commit/test links here. | Add reviewer. |
