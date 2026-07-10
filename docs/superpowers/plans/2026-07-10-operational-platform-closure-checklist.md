# Operational Platform Closure Checklist

**Status:** Active execution checklist

**Opened:** 2026-07-10

**Authority:** This checklist tracks the bounded release/security/operations closure tranches requested after the 2026-07-09 remediation review. Product scope and acceptance intent remain governed by `docs/ZEPHYRUS-2.0-BETA-PRD.md` and `docs/ZEPHYRUS-2.0-PLAN.md`.

## Release order and branch discipline

- [x] R0.1 Prove the production application tree came from `feat/hummingbird-4d-service-line-eddy` and record the deployed head.
- [x] R0.2 Open a normal merge PR from the deployed branch without squashing or rewriting its fourteen commits.
- [x] R0.3 Repair branch CI so the core and full workflows do not cancel one another.
- [x] R0.4 Run Laravel/PHPUnit, Pint, Vite/TypeScript/Vitest, and Arena pytest checks to green.
- [x] R0.5 Merge through GitHub with a merge commit and verify the deployed feature history remains reachable from `main`.
- [x] R0.6 Deploy only the merged `main` tree through `./deploy.sh`.
- [x] R0.7 Verify Apache, the forced Zephyrus vhost, the public login boundary, runtime file parity, and current release migrations.
- [x] R0.8 Restore all pre-existing local worktree changes without including them in the release PR.
- [ ] R0.9 Keep each remaining tranche on a separate branch and PR; require full CI and a post-merge deployment from `main` before starting the next production tranche.

## Patient Flow authorization and ingress hotfix

### Read boundary

- [x] S1.1 Resolve the canonical Flow Window scope (`house`, `floor`, `unit`, or opaque `patient`) on every lensed web Patient Flow request.
- [x] S1.2 Compute effective patient depth server-side and reject patient endpoints when the resolved role/scope grants no patient depth.
- [x] S1.3 Centralize active transport/EVS task-patient references in `FlowLensService`; terminal or deleted work must not confer access.
- [x] S1.4 Filter event, track, state, and SSE rows by resolved spatial scope and patient access before serialization.
- [x] S1.5 Replace raw patient/display/encounter references with stable opaque context tokens and strip raw-message hashes plus identifier-bearing metadata.
- [x] S1.6 Reject raw patient query identifiers; only opaque `ptok_` filters may select a patient.
- [x] S1.7 Apply effective scope/depth to occupancy, occupancy history, and forward projections while preserving aggregate-safe output.
- [x] S1.8 Add `private, no-store` response policy to all patient-bearing JSON and event-stream responses.

### FHIR boundary

- [x] S1.9 Authorize the requested flow event against the resolved scope/depth before constructing a FHIR bundle.
- [x] S1.10 Return a non-enumerating not-found response for an absent or out-of-scope event.
- [x] S1.11 Construct the bundle from the lensed payload so Patient and Encounter resources never restore internal identifiers.

### HL7 v2 ingress boundary

- [x] S1.12 Remove `/api/patient-flow/ingest/hl7v2` from the browser-session Patient Flow route group.
- [x] S1.13 Expose ADT ingestion only under the canonical `/api/integrations/v1/...` namespace.
- [x] S1.14 Require a real Sanctum bearer token with the explicit `integration:patient-flow:ingest` ability; browser sessions and wildcard human tokens must not satisfy the machine boundary.
- [x] S1.15 Require a configured, active, PHI-approved HL7 v2 source; production also requires a production/live source.
- [x] S1.16 Persist an ingest run and immutable raw message before normalization.
- [x] S1.17 Write the canonical event through `CanonicalEventWriter`, then project `flow_core.flow_events` with source/raw/canonical foreign keys.
- [x] S1.18 Write provenance from raw message through canonical event to the Patient Flow projection.
- [x] S1.19 Enforce source-scoped idempotency, return the prior opaque receipt for a byte-identical retry, and reject a key reused with a different payload.
- [x] S1.20 Return only opaque run/message/canonical receipt identifiers; never echo HL7 or patient data.

### Security evidence and release

- [x] S1.21 Test unit-scope allow/deny behavior across sibling units.
- [x] S1.22 Test task-scope access for active work and denial after terminal status.
- [x] S1.23 Test that JSON, FHIR, and SSE payloads contain no internal patient/encounter references.
- [x] S1.24 Test old-route removal plus anonymous, browser-session, missing-ability, wildcard-token, and explicit-machine-token ingress outcomes.
- [x] S1.25 Test source-state enforcement, canonical lineage, provenance, rejected raw retention, and duplicate delivery.
- [x] S1.26 Run focused tests, repository Pint, the full Laravel suite, frontend checks, and full branch CI.
- [x] S1.27 Merge the security PR and redeploy the merged `main` commit through `./deploy.sh`.

Pre-PR local evidence on the isolated security branch:

- `./vendor/bin/pint --test`: 874 files passed.
- `php artisan test`: 718 passed, 7,403 assertions, one intentional fixture-regeneration skip.
- `PatientFlowSecurityHotfixTest`: nine adversarial tests and 100 assertions passed after the final ingress validation changes.
- `npx tsc --noEmit`: passed.
- `npx vitest run --coverage`: 80 files and 330 tests passed.
- `npm run build`: production build passed.
- `python -m pytest tests -q` in a clean Arena dependency environment: 17 passed.
- Exact-head GitHub CI passed all five required checks on PR #15.
- PR #15 merged as `2905664a058e7ba15ff9c6d941e67c090f562c09`; the security head remains an ancestor of `main`.
- `./deploy.sh` completed from clean/current `main`; Apache was active, the forced vhost redirected to login, runtime hashes matched, anonymous machine ingress returned 401, and the retired browser route returned 404.

## Patient Flow 4D wall-display lockdown

- [x] W1.1 Treat wall mode as a render-only surface: no drill, patient, lens, inbox, alert-engagement, or Eddy controls may mount.
- [x] W1.2 Remove interactive semantics, focus targets, hover affordances, and pointer handlers from wall-mode tiles and overlays.
- [x] W1.3 Ignore or strip drill/patient URL state when wall mode is active.
- [x] W1.4 Do not fetch agent-inbox or patient-detail data in wall mode.
- [x] W1.5 Preserve current desk-mode drill, patient, inbox, lens, and Eddy behavior without duplication.
- [x] W1.6 Add component tests proving wall mode is chromeless/non-interactive and desk mode remains interactive.
- [x] W1.7 Publish as its own bounded PR, run full CI, merge, and deploy from `main`.

Pre-PR local evidence on the isolated wall-lockdown branch:

- Focused wall/desk suite: nine files and 60 tests passed.
- Full Vitest suite: 81 files and 342 tests passed.
- `npx tsc --noEmit`: passed.
- `npm run build`: production build passed.
- `scripts/check-ui-canon.sh`: passed; one pre-existing dashboard raw-palette match was converted to the canonical healthcare token to restore the ratchet from 77 to its baseline of 76.
- PR #16 passed all five exact-head CI checks, merged as `356b5004d856eac0cb67c1b05cb07f23a309c935`, and was deployed through `./deploy.sh` from clean/current `main`.
- Runtime parity checks matched local/production source and manifest hashes; the public wall URL returned 200 and the deployed bundle omitted patient lens, inbox, drill URL state, and Eddy wall controls.

## Integrations operational runtime

- [x] I1.1a Change the repository/runtime default to the database queue and add an `integrations,default` worker with bounded timeout, backoff, memory, and lifecycle settings.
- [x] I1.1b Add a hardened systemd unit plus deploy-time install/restart/active verification; keep first-run migrations explicit through `DEPLOY_RUN_MIGRATIONS=1`.
- [ ] I1.1c Install the unit in production, change production from `sync` to `database`, and prove scheduler → database job → worker completion.
- [x] I1.2 Add protocol-aware health checks for FHIR R4/SMART and HL7 v2 rather than configuration-presence checks; keep protocol health separate from data freshness.
- [x] I1.3 Add audited replay controls with bounded source/time/event scope, read-only preview, idempotency, and strict operator authorization. Execution excludes already-projected events.
- [x] I1.4a Add an idempotent real Epic public-sandbox source with official FHIR, SMART-discovery, and token endpoints; never commit credentials.
- [ ] I1.4b Configure that source in production and complete a live discovery run.
- [x] I1.5a Prove RS384 SMART client assertion construction, five-minute JWT lifetime, minimal Encounter/Location scope, FHIR 4.0.1 compatibility, version retention, provenance, pagination bounds, and watermark advancement in integration tests.
- [ ] I1.5b Complete a real Epic token exchange and poll. External gate: registered Epic non-production client ID plus an approved private-key reference.
- [x] I1.6a Operationalize the S1 HL7 boundary with source-governance and bounded machine-token commands plus protocol health.
- [ ] I1.6b Configure/activate the first real production HL7 v2 ADT source and deliver a test ADT through raw → canonical → Patient Flow projection → provenance. External gate: executed interface/BAA/PHI approval and sender identity.
- [x] I1.7a Exercise discovery-only activation, unsafe/unresolved credentials, bounded retry/backoff, dead-letter recovery, replay idempotency/conflict, strict authorization, FHIR lineage, and worker dispatch in tests.
- [ ] I1.7b Exercise live token/poll failure, dead-letter recovery, source staleness, credential rotation, worker restart, and rollback in production.
- [x] I1.8 Publish `docs/operations/INTEGRATIONS-RUNTIME-RUNBOOK.md` while keeping environment-specific secrets outside Git.

Current isolated branch evidence:

- `IntegrationOperationalRuntimeTest`: seven end-to-end feature tests and 64 assertions cover idempotent source configuration, queued live discovery, RSA-signed SMART authentication, versioned FHIR persistence/provenance/watermarking, exhausted-retry dead-letter recovery, replay, HL7 governance/token issuance, and authorization.
- `tests/js/integrations/api.test.ts`: operator API schemas and health/poll/replay request contracts pass.
- `npx tsc --noEmit`: passed after adding operator controls to FHIR, HL7, and replay panels.
- `bash -n deploy.sh` and `systemd-analyze verify deploy/systemd/zephyrus-queue-worker.service`: repository unit and deploy script validated (host-wide verifier emitted unrelated unreadable-unit notices only).
- Repository `./vendor/bin/pint --test`: 890 files passed.
- Full `php artisan test`: exit 0 with 7,471 assertions; the isolated worktree reported its known missing-local-environment `file_get_contents` warnings but no failing test.
- Full `npx vitest run --coverage`: 81 files and 343 tests passed; `npm run build` and `scripts/check-ui-canon.sh` passed.
- Clean Arena environment: 17 pytest tests passed.
- Production pre-change audit: `QUEUE_CONNECTION=sync`, no Zephyrus worker, no endpoints/watermarks/FHIR or SMART rows, and only the synthetic non-PHI HL7 file source. No Epic client identity or private-key reference exists on the host.

## Staffing operations completion

- [x] ST1.1 Establish the canonical qualification vocabulary and effective-dated staff qualification records.
- [x] ST1.2 Model availability, leave, preference, and conflict windows with timezone-safe overlap rules.
- [x] ST1.3 Validate each assignment against role, qualification, unit/service-line capability, availability, and overlapping shifts.
- [x] ST1.4 Implement shift fulfillment as a governed lifecycle with idempotent accept/fill/release/cancel transitions and an immutable activity trail.
- [x] ST1.5 Surface explicit unfilled/unsafe/conflicted states to web and Hummingbird with server-enforced action policies.
- [x] ST1.6 Add migration, service, API, policy, concurrency, pagination, and mobile parity tests.

Current isolated branch evidence:

- `2026_07_10_000300_create_canonical_staffing_fulfillment_tables.php`, `config/staffing.php`, and the canonical staffing models establish effective-dated qualifications, availability windows, shift assignments, request fulfillments, and append-only command/event ledgers without replacing legacy source records.
- `MaterializeCanonicalStaffing` projects configured roles, qualifications, and timezone-safe shift windows in deterministic batches; dry runs roll back the complete projection, deactivated source qualifications expire, and shortened horizons remove stale source-owned windows.
- `CanonicalStaffingService` applies facility scope, verified qualifications, effective dates, availability/leave/conflicts, overlapping assignments, unit/service-line capability, accepted/filled headcount reservation, row locks, and advisory idempotency locks before every governed transition.
- The web API and Staffing Office expose all active fulfillments and explicit ineligible/unavailable/conflicted states. Legacy source selection can no longer assign a person or mark a request filled, and cancellation/completion are tied to canonical fulfillment state.
- Hummingbird Android and iOS now select an eligible named candidate through the same canonical API; the mobile write requires header idempotency and records offer, accept, and fill atomically with operational activity. OpenAPI and the mobile route inventory carry the contract.
- `CanonicalStaffingFulfillmentTest`: six feature tests and 109 assertions cover pagination and facility scope, authorization and immutable idempotency, transition-time revalidation, headcount reservation, DST/materialization reconciliation, and legacy/mobile parity.
- Full `php artisan test` after rebasing onto `main`: 43 tests passed with 9,593 assertions and only the isolated worktree's known missing-local-`.env` warnings. Repository `./vendor/bin/pint --test`: 901 files passed.
- Full `npx vitest run --coverage` after rebasing onto `main`: 82 files and 346 tests passed. `npx tsc --noEmit` and `npm run build` passed.
- `scripts/check-ui-canon.sh` names no staffing file, but the branch inherits pre-existing failures from `main` commit `025139f` in the newly added Arena UI (font weight, arbitrary text sizes, and raw palette ratchet); those unrelated files remain outside this tranche.
- Android `testDebugUnitTest` passed under the repository-supported JDK 17. Native iOS compilation is unavailable on this Linux host; shared contract/parity guards cover the iOS request and model seams pending GitHub/macOS validation if configured.

## Transport lifecycle completion

- [ ] T1.1 Define one transition graph for request, assignment, pickup, movement, arrival, handoff, completion, escalation, cancellation, and failure.
- [ ] T1.2 Reject illegal or actor-inappropriate transitions server-side across web and mobile endpoints.
- [ ] T1.3 Require idempotency keys on lifecycle writes and make replays return the original result without duplicate events.
- [ ] T1.4 Enforce transporter/team/vendor capacity and prevent overlapping active assignments.
- [ ] T1.5 Require structured handoff evidence before completion where the request type demands it.
- [ ] T1.6 Add deterministic cursor pagination and stable filters to web/mobile queues.
- [ ] T1.7 Prove transition, concurrency, capacity, idempotency, handoff, pagination, and parity behavior with tests.

## Canonical documentation reconciliation

- [ ] D1.1 Re-audit code, migrations, tests, CI, deployed runtime, and open PR state before changing completion claims.
- [ ] D1.2 Reconcile `docs/superpowers/plans/2026-07-09-operational-platform-remediation-plan.md` so deployed work is marked shipped rather than gated.
- [ ] D1.3 Link each remaining gate to this checklist's evidence, owner, branch/PR, test, migration, and deployment result.
- [ ] D1.4 Remove contradictory status language while preserving historical decision context.
- [ ] D1.5 Update the canonical PRD/plan only after the corresponding production tranche is verified.

## Definition of done for every production tranche

- [ ] The branch contains only the intended tranche and preserves unrelated local work.
- [ ] Focused tests and relevant static/build checks pass locally.
- [ ] Full GitHub branch CI passes on the exact PR head.
- [ ] Review/merge happens through a normal PR; no history rewrite or direct production pull is used.
- [ ] `main` contains the PR head, the production deploy comes from that merged `main`, and runtime verification is recorded.
- [ ] Documentation claims match the code and deployed state after—not before—the verification gate.
