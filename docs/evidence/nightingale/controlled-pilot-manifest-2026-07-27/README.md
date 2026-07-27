# Nightingale controlled-pilot manifest foundation evidence

- **Date:** 2026-07-27
- **Feature branch:** `codex/nightingale-patient-product`
- **Pre-slice verified head:** `b333ebf8a618cac5c3c8a27d43ea666de32579bc`
- **Milestone:** Stream F default-off, audited, expiry-bound pilot configuration
- **Checklist result:** 42/54 complete (77.78%); 12/54 remain open
- **Production database activity:** none
- **Application deployment or activation:** none

## 1. Scope accepted

This slice defines a generated, non-runnable controlled-pilot manifest candidate. It
contains:

- opaque facility, unit, and cohort scope;
- inclusion/exclusion policy release references;
- a technical maximum of 25 active enrollments with automatic enrollment prohibited;
- canonical locale-tag and released interpreter-coverage requirements;
- fail-closed unknown/outage exclusion handling without sensitive-service inference;
- IANA-zone, non-overlapping local weekly support windows;
- released urgent-help guidance for uncovered windows;
- an exact UTC start/expiry window no longer than 168 hours;
- eight prerequisite-record references;
- seven distinct named-approval roles;
- nine append-only audit event types with nine content-free fields;
- a default-engaged kill switch and exact rollback evidence; and
- a positive disposition limited to external go/no-go review.

The committed template contains no real facility, unit, cohort, language, support,
approval, validity, source-deployment, release, audit-sink, rollback, or pilot value.

## 2. Artifacts

| Artifact                                                                                                                      | Purpose                                                                                        |
| ----------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| [`candidate.json`](../../../nightingale/pilot/candidates/v0/candidate.json)                                                   | Generated policy, default manifest, constraints, ceilings, inventories, and four source hashes |
| [`fixtures.json`](../../../nightingale/pilot/candidates/v0/fixtures.json)                                                     | Synthetic complete structure plus 34 deterministic evaluation cases                            |
| [Decision/evidence record](../../../nightingale/CONTROLLED-PILOT-MANIFEST-FOUNDATION-2026-07-27.md)                           | Field semantics, non-authorization boundary, future requirements, and residual holds           |
| [`build-nightingale-controlled-pilot-manifest.mjs`](../../../../scripts/ci/build-nightingale-controlled-pilot-manifest.mjs)   | Deterministic builder                                                                          |
| [`verify-nightingale-controlled-pilot-manifest.mjs`](../../../../scripts/ci/verify-nightingale-controlled-pilot-manifest.mjs) | Independent builder, shape, semantic, source, fixture, and mutation verifier                   |

## 3. Candidate-verifier acceptance

The independent verifier passed:

```text
Nightingale controlled-pilot manifest verified: 34 synthetic cases (33 hold, 1 external go/no-go review only), 4 checksum-bound sources, 25 negative self-tests.
```

It proved:

- committed JSON is exact builder output;
- all four source SHA-256 values match current bytes;
- the executable Nightingale contract still has zero paths;
- every executable activation fact remains false;
- backend configuration remains code-owned with `not_enrolled`, no disclosure/mutation,
  and no production activation;
- the committed template is empty, revision zero, and inactive;
- the only complete synthetic case remains `runtime_activation_permitted=false`;
- 33 incomplete/adversarial cases hold;
- the complete synthetic case may only reach external go/no-go review;
- all 13 implementation/route/network/source/query/enrollment/disclosure/deployment/
  activation constraints remain false;
- no patient/content/credential key enters the manifest or fixture;
- no production host, credential, or executable Nightingale route enters the artifacts;
  and
- all 25 deliberate artifact mutations are rejected by validation itself.

The negative-test harness was separately inspected and corrected so it counts a mutation
only after the validator throws. A self-test cannot pass by throwing its own
“did not fail” assertion.

## 4. Full contract and backend acceptance

The full Nightingale chain passed after CI integration:

1. empty/default-off executable contract and negative tests;
2. encounter-access candidate;
3. Today candidate with 68 cases and 24 negative mutations;
4. 15-family/27-case journey candidate with 23 negative mutations;
5. 64 identity and 42 source cases;
6. 65-source identity/input ledger;
7. 130-source communication/notification ledger;
8. 134-source journey/preference/release ledger;
9. threat/hazard model;
10. dependency inventory;
11. namespace foundation;
12. controlled-pilot manifest;
13. backend foundation; and
14. native product boundary.

The focused Laravel foundation suite passed:

```text
Tests: 23 passed (149 assertions)
```

No runtime manifest class, service provider, route, controller, database table, patient
query, native client, or enrollment operation was introduced.

## 5. iOS Simulator acceptance

An isolated iPhone 16e simulator on iOS 26.3.1 was booted. The Nightingale Xcode project
first reproduced from `project.yml`.

Accepted results:

- unit tests: 11/11 passed;
- UI journeys: 6/6 passed;
- failures: 0;
- skipped: 0;
- Debug build-for-testing: passed;
- unsigned Release Simulator build: passed; and
- Release artifact identity, version, orientation, privacy, dependencies, no-network,
  no-deep-link, no-test-hook, English-copy, and seven-background boundary: passed.

The UI suite covered display-comfort persistence, double-length landscape reflow,
accessibility-size landscape reachability, foundation copy, lifecycle privacy cover, and
right-to-left layout.

The isolated iPhone 16e was shut down by the test trap. No patient data or production
network was used.

## 6. Android API 35 emulator acceptance

The pre-existing `hb` API 35 emulator on port 5554 belonged to another process and was not
targeted. A second read-only instance of the same AVD was started on port 5556 without a
snapshot.

Accepted build and artifact results:

- Debug and Release lint: passed;
- Debug and Release assembly: passed;
- unsigned Release APK identity, permission, network/deep-link/test-hook, cleartext,
  English-copy, native-library, and seven-background boundary: passed;
- installed API 35 journeys: 10/10 passed;
- installed failures/errors/skips: 0/0/0.

The initial JVM task invocation restored test outputs from the Gradle cache. Those results
were not accepted as fresh execution. A separate forced `--rerun-tasks` invocation then
executed all test tasks:

- Debug JVM tests: 8/8 passed;
- Release JVM tests: 8/8 passed;
- failures/errors/skips: 0/0/0; and
- actionable tasks: 45/45 executed.

Only the port-5556 emulator was terminated. Port 5554 remained connected to its original
process.

## 7. Documentation and repository acceptance

The following gates are required before publication:

- exact checklist reconciliation: 42 checked, 12 open, 54 total;
- Prettier over every changed Markdown, JSON, JavaScript, and workflow file;
- local Markdown-link resolution for every changed document;
- JavaScript syntax checks;
- Bash syntax check;
- Git whitespace validation;
- secret scans; and
- exact-SHA hosted CI.

Final command results and the exact publication SHA are recorded in the companion devlog
and draft pull request after commit.

## 8. Current-main reconciliation

The validated slice was committed as `487d1c796fd9359819d501ccb2d3c1c60928b7d9`.
The branch then merged current `origin/main` at
`996a5336066e0523de63c3e88f7bc0e1243ab192`. That upstream commit changes only
`.github/workflows/ci.yml` to provide the shared frontend build through a thin,
independent `frontend-build` job for the Browser and DAST jobs.

The automatic merge preserved both the upstream job graph and the Nightingale contract
job's controlled-pilot verifier. No application, contract, fixture, database, native
client, or sample-patient source changed after emulator acceptance. The post-merge
verification therefore reruns the affected workflow parse and Nightingale mechanical
gates; the recorded iOS and Android results continue to apply to the exact unchanged
runtime trees.

## 9. Explicit non-authorization

This evidence does not approve:

- a real pilot manifest or any of its scope values;
- any named reviewer or approval;
- identity proofing, recovery, consent, representatives, or sensitive-service handling;
- a source connector, audit sink, evaluator, enrollment service, support service, or
  rollback system;
- clinical content or a patient-visible operation;
- signed distribution, physical-device acceptance, store submission, pilot enrollment,
  or deployment; or
- any production database access or mutation.

The separately authorized Nightingale sample patient remains pending/inactive and
unreachable. This slice did not read or alter it.
