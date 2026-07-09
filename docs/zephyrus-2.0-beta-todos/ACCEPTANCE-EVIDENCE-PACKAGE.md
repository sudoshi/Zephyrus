# Acceptance Evidence Package

Purpose: define the artifact structure, metadata, naming, and signoff required to prove Zephyrus 2.0 beta is implemented, validated, deployable, deployed when authorized, and rollback-ready.

The evidence package is cumulative. B1-B7 produce phase evidence. B8 assembles and verifies the release evidence bundle.

## Root Layout

Use this structure unless B0 records a different artifact store:

```text
docs/zephyrus-2.0-beta-todos/evidence/
  B0/
  B1/
  B2/
  B3/
  B4/
  B5/
  B6/
  B7/
  B8/
    commands/
    api/
    screenshots/
      web/
      mobile/
      wall/
    mobile/
      android/
      ios/
    deploy/
    rollback/
    reviews/
    demo/
```

Do not commit raw PHI, tokens, secrets, production credentials, private keys, or unredacted patient payloads. If evidence must remain out of git, commit a pointer file that states storage location, retention owner, and redaction status.

## Required Metadata

Every evidence README must include:

```markdown
# Evidence - <Phase>

Date:
Branch:
Commit:
Environment:
Database target:
Seed command:
Seed timestamp:
Scenario keys:
Operator:
Reviewer:
Related requirement IDs:
Known limitations touched:
Deployment status:
Rollback status:
```

Every command output file must start with:

```text
date:
cwd:
branch:
commit:
command:
exit_code:
environment:
```

Every screenshot index row must include:

| Field | Requirement |
| --- | --- |
| Path | Relative path to artifact. |
| Viewport/device | Example: `desktop-1440`, `tablet-1024`, `mobile-390`, `wall-1920`, `ios-simulator`, `android-emulator`. |
| Role | Executive, bed manager, charge nurse, EVS, transport, staffing, PI, admin, aggregate, privileged. |
| Scenario | Demo scenario key or `default`. |
| Requirement IDs | One or more IDs from `TRACEABILITY-MATRIX.md`. |
| PHI review | Pass/deferred/fail and reviewer. |
| Synthetic label review | Pass/deferred/fail and reviewer. |
| Visual review | Pass/deferred/fail and reviewer. |

Every API sample must include:

- [ ] Route and method.
- [ ] Role/token type.
- [ ] Request parameters.
- [ ] HTTP status.
- [ ] Redaction statement.
- [ ] Requirement IDs.
- [ ] Source/as-of/freshness fields if action-driving.
- [ ] Synthetic/demo/live state.

## Required Phase Evidence

### B0 Evidence

- [ ] PRD authority and docs index diff.
- [ ] Decision register with D1-D19 status.
- [ ] API route inventory with auth class per route.
- [ ] Nav/route drift command output.
- [ ] Known limitations register created.
- [ ] Reviewer signoff that stale AGENTS.md guidance is corrected or intentionally superseded.

### B1 Evidence

- [ ] `zephyrus:demo-seed` output.
- [ ] `patient-flow:rebase-synthetic` output if used.
- [ ] `patient-flow:import-synthetic` output if used.
- [ ] `rtdc:demo-reset` output if used.
- [ ] Before/after proof queries for facility identity, beds, flow events, source registry, watermarks, and scenario keys.
- [ ] Repeat-run idempotency proof.
- [ ] UI/API proof that synthetic/demo labels are visible.
- [ ] Degraded feed scenario proof.

### B2 Evidence

- [ ] Snapshot JSON schema sample.
- [ ] Cockpit/mobile/Eddy as-of comparison with allowed skew.
- [ ] Cache/stale/ETag/fallback behavior proof.
- [ ] Scheduler `schedule:list` and `schedule:run` proof if runtime-related changes land.
- [ ] Cockpit screenshots for normal, stale, and degraded states.

### B3 Evidence

- [ ] Route disposition table.
- [ ] Nav leaf to route proof.
- [ ] Mock-mode enforcement proof.
- [ ] PHI helper/policy tests.
- [ ] Desktop/tablet/mobile/wall screenshots.
- [ ] Playwright or manual viewport review output.

### B4 Evidence

- [ ] Patient Flow API contract samples for occupancy, history, scenarios, barriers, and Eddy context.
- [ ] Snapshot detail persistence proof.
- [ ] Scenario registry proof for selected scenario set.
- [ ] Barrier taxonomy proof.
- [ ] Nonblank canvas/framing/interaction screenshot or Playwright check.
- [ ] Web/mobile parity proof for Patient Flow.

### B5 Evidence

- [ ] Tool/action catalog JSON sample.
- [ ] No-self-approval tests.
- [ ] Dry-run preview tests.
- [ ] Execution adapter tests.
- [ ] Durable agent run/tool-call proof.
- [ ] Full governed action loop rehearsal.
- [ ] Security/PHI prompt-output review.

### B6 Evidence

- [ ] Mobile role package matrix.
- [ ] BFF contract samples for demo personas.
- [ ] Activity/audit event proof for mobile writes.
- [ ] Offline/stale/unsafe-write tests.
- [ ] Push/fetch-on-open decision artifact and runtime proof.
- [ ] Android build/test output.
- [ ] iOS build output.
- [ ] Persona screenshot matrix.

### B7 Evidence

- [ ] Domain completion matrix.
- [ ] Route/API smoke by domain.
- [ ] Live-vs-synthetic labeling by domain.
- [ ] ED, RTDC, periop, transport, EVS, staffing, improvement, and Study handoff proof.
- [ ] Operational-event-to-study payload sample.
- [ ] Auth and PHI checks for domain routes.

### B8 Evidence

- [ ] Full automated validation archive.
- [ ] Final visual validation archive.
- [ ] Final mobile build archive.
- [ ] Security/PHI signoff.
- [ ] Demo script and rehearsal log from fresh seed.
- [ ] Deploy log.
- [ ] Post-deploy migration/scheduler/queue/Reverb/vhost/API smoke log.
- [ ] Rollback rehearsal.
- [ ] Known limitations and release notes.

## Signoff Rules

Each artifact category requires owner and reviewer:

| Artifact Category | Owner | Reviewer |
| --- | --- | --- |
| Requirements traceability | Orchestrator | QA |
| Backend/API tests | Backend | QA or Security |
| Frontend screenshots | Frontend | QA or Security |
| Mobile builds/screenshots | Mobile | QA or Security |
| PHI/security review | Security | Orchestrator |
| Data seed/provenance | Data | QA |
| Eddy action loop | Backend/Eddy | Security |
| Deployment | Ops | Orchestrator |
| Rollback | Ops | Backend or Data |
| Release notes/limitations | Documentation | Orchestrator |

No one may be both owner and reviewer for the same artifact row.

## Completion Checklist

The final release package is acceptable only when:

- [ ] Every requirement ID is `Complete`, `Ready for B8`, or `Deferred`.
- [ ] Every `Deferred` row appears in known limitations.
- [ ] Every phase evidence README has metadata.
- [ ] Every command artifact records exit code.
- [ ] Every screenshot has PHI and synthetic-label review.
- [ ] Every API sample is redacted and names role/token type.
- [ ] Every deployment command is captured from the correct working directory.
- [ ] Rollback has been rehearsed or explicitly accepted as not rehearsed with risk owner.
- [ ] The demo script can be executed from a fresh seed without undocumented intervention.
