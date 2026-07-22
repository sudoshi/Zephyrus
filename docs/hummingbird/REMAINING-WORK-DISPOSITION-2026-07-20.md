# Hummingbird plan — remaining-checkbox disposition (2026-07-20)

> Companion to `ZEPHYRUS-HUMMINGBIRD-FUNCTIONAL-PARITY-AND-PATIENT-EXPERIENCE-PLAN-2026-07-19.md`.
> A systematic classification of **every** open (`[ ]`) checkbox in the plan at this checkpoint,
> so "completion status" is auditable rather than cherry-picked. Counts are a snapshot.

At this checkpoint the plan has **306 open** checkboxes. They fall into six dispositions.
Only bucket **F** is completable by an in-repo engineering agent with local test verification;
the others require organizational approval, production secrets/environment, native-device
verification, human/clinical review, or are aggregate exit-gates that only close when their
underlying work does. This document records that boundary honestly.

## Disposition buckets

| Code  | Disposition                                       | Meaning                                                                                                                                                                                                                       | Count (approx) |
| ----- | ------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------- |
| **A** | Governance / human / org-decision                 | Approvals, sign-offs, naming owners, policy ratification, clinical/usability/tabletop review, pilot definition, translation operations. Not code.                                                                             | ~95            |
| **B** | Production / deployment / secrets                 | Needs the deployment `APP_KEY`/patient HMAC, approved production source systems, live-deployed E2E, or real APNs/FCM certificates. The local runtime is deliberately fail-closed against these.                               | ~20            |
| **C** | Native-device verification                        | iOS Simulator / Android emulator XCTest/XCUITest/instrumentation matrices and screenshot baselines. Code is writable but the _verification_ gate requires booted devices and is out of scope for a backend-verifiable pass.   | ~30            |
| **D** | Large client build (iOS/Android)                  | Substantial native app work (FCM path, offline cache/outbox, single-flight refresh, session UIs, per-screen freshness, notification UX). Backend seams may exist; the native surface needs simulators to complete and verify. | ~55            |
| **E** | Aggregate exit / definition-of-done gate          | Phase "exit evidence" and §17 DoD items that only pass when _all_ underlying work plus governance is complete. Not independently tickable.                                                                                    | ~55            |
| **F** | **Locally implementable backend / contract / CI** | Server, OpenAPI contract, CI verifier, or local-test work completable and verifiable now without a device, secret, or sign-off. **This is the actionable set.**                                                               | ~50            |

## Bucket F — the actionable set (this pass works through these)

These are the items an in-repo agent can complete with `php artisan test` / verifier / prettier
verification. Items already completed earlier in this checkpoint are marked ✅.

| Plan ref                   | Item                                                                                          | State                                                                       |
| -------------------------- | --------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| §9.6 (automated lifecycle) | Automated shift/transfer/discharge/downtime routing reconciliation                            | ✅ done                                                                     |
| §6.2                       | One ISO-8601/UTC timezone rule + DST-boundary test                                            | ✅ done                                                                     |
| §4.3                       | `PersonaRelayPolicy` tracked backlog + no-implied-completeness guard                          | ✅ done                                                                     |
| §4.3                       | Authorization test matrix (task-ownership, changed-role)                                      | ✅ done                                                                     |
| §4.3                       | Staff patient-context reference TTL/expiry/revocation behavior + test                         | ✅ done                                                                     |
| §6.1                       | Deprecation-record gate (registry lock + verifier)                                            | ✅ done                                                                     |
| Phase 4                    | "My Path" structured stages/milestones + `pathway/events` op                                  | ✅ done (read spine)                                                        |
| §4.2 (191)                 | Regenerate `mobile-route-contract-inventory.md` from live routes                              | this pass                                                                   |
| §6.1 (329)                 | Extend capability ownership gate to non-mobile API + background jobs                          | this pass                                                                   |
| §6.2 (344)                 | Breaking-change checker vs a committed contract baseline                                      | this pass                                                                   |
| §6.5 (373,374)             | Offline cache-class registry (`NO_CACHE`/`ENCRYPTED_READ_CACHE`/`READ_CACHE_AND_OUTBOX`) + CI | this pass                                                                   |
| §6.4 (364)                 | Server-owned T1–T4 notification urgency registry + validation test                            | this pass                                                                   |
| §4.2 (181)                 | One client error taxonomy (server enum + contract + test)                                     | this pass                                                                   |
| §10.1 (811)                | No advertising/tracking pixels in authenticated/patient surfaces — scan test                  | this pass                                                                   |
| §9.5 (746)                 | Opaque, scoped, short-lived paging cursors — verify/harden                                    | this pass                                                                   |
| §6.1 (331)                 | OpenAPI operation-extension completeness gate                                                 | needs per-op product input for ~48 legacy staff ops (partial)               |
| §4.3 (200)                 | Named patient-access policy classes + audit reason codes                                      | candidate (auth-refactor risk; test-covered)                                |
| §13.1 (1051–1056,1061)     | Broaden backend authorization/IDOR/idempotency/concurrency/audit test matrices                | candidate (incremental)                                                     |
| Phase 4 (952,956,957,955)  | Patient goals / education-teach-back / discharge-readiness / milestone projections            | candidate (each a coordinated patient-contract build like `pathway/events`) |

## Why the other buckets cannot be ticked here

- **A (governance):** e.g. Phase 0 approvals, §10.3 sign-offs, §9.2 NIST-assurance selection, pilot
  definition, professional-translation operations, "assign named owners." An engineering agent
  cannot grant an organizational approval or run a human review; ticking these would be false.
- **B (production/secrets):** e.g. Phase 3 principal/grant linkage to the synthetic encounter,
  "Today/My Path/Care Team from approved sources," credential rotation of history-exposed secrets,
  live-deployed E2E. The repo is intentionally fail-closed without the deployment secrets, and
  this pass never touches the canonical backend.
- **C (native verification):** §6.6 and §13.2 device matrices require booted simulators/emulators
  and screenshot baselines — outside a backend-verifiable pass.
- **D (large client builds):** §6.3/§6.4/§6.5 client halves, Phase 1/2 native packages, Journey
  UIs. These need the native apps + simulators to complete and verify.
- **E (aggregate gates):** §2.1 outcomes, every "Exit evidence" block, §17 DoD. These are
  roll-ups; they close only when their constituent work and approvals do.

## Honesty note

"Complete the remaining implementation checkboxes" is bounded by what an in-repo agent can
actually build and verify. This pass completes bucket **F** and records **A/B/C/D/E** as the
reason the remaining items stay open — deferring them to their required owners rather than
marking governance, production, or device-verified work as done.

## Bucket F — completed this pass (2026-07-20)

Nine bucket-F items were code-completed with local verification in this pass
(seven newly, plus the two earlier tranches), all CI-gated:

- §6.5/373–374 offline cache-class registry + patient-`NO_CACHE` invariant (ledger verifier).
- §10.1/811 no advertising/tracking pixels (`scripts/check-no-tracking-technologies.sh`).
- §6.4/364 server-owned T1–T4 urgency registry (`config/hummingbird-notifications.php` + `NotificationUrgencyRegistry`).
- §9.5/746 opaque-cursor paging guard (patient-contract verifier).
- §6.2/344 contract breaking-change checker (`verify-hummingbird-contract-baseline.php` + `contract-operations.lock`).
- §4.2/191 generated, CI-freshness-checked route inventory (`generate-hummingbird-route-inventory.php`).
- §4.2/181 one client error taxonomy (`ErrorCategory`/`ErrorCatalog` + `error-taxonomy.md`).

**Still open in F (not clean-completable without more input):**

- §6.1/329 extend ownership gate to non-mobile API domains + background jobs — assessed
  `implementable_risky`: classifying ~27 API domains and ~26 scheduled jobs into
  owned-vs-excluded is governance-flavored data entry; deferred to avoid mis-assigning ownership.
- §6.1/331 OpenAPI operation-extension completeness gate — needs per-operation idempotency/
  error-behavior values for ~48 legacy staff ops that no authoritative source provides.
- §4.3/200 named patient-access policy classes + audit reason codes; §13.1 broader test
  matrices; Phase 4 patient goals/education/discharge projections — each a larger coordinated
  build, tractable but beyond this pass.
