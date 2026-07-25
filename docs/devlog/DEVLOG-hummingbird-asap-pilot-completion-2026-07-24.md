# Hummingbird ASAP Pilot-Completion Devlog — 2026-07-24

**Plan:** [Hummingbird ASAP Pilot-Completion Plan](../plans/hummingbird-asap-pilot-completion-2026-07-24.md)
**Status:** execution reset proposed; native staff Eddy SSE increment accepted
locally; no patient feature enabled by this entry.

## Baseline

- The governing Hummingbird plan has 172 checked and 291 unchecked checklist items
  (463 total). This is an unweighted work-item count, not a clinical-readiness
  percentage.
- The program is reset to a controlled inpatient-pilot cutline: approved
  Today/My Path/Care Team/discharge projections plus accountable secure messaging
  for one facility and two units.
- Patient Eddy, proxy access, attachments, patient push delivery, offline message
  queues, post-discharge handoff, broad staff parity, and general availability are
  deliberately outside that pilot cutline.
- The active worktree contains a bounded staff Eddy native streaming increment. It
  is locally verified and ready for review, but does not authorize deployment or
  patient feature enablement.

## Daily control board

| Release slice                           | Owner                               | Status           | Decision/dependency                                                                   | Evidence                                                                                                                                               | Flag state                            | Next action                                           |
| --------------------------------------- | ----------------------------------- | ---------------- | ------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------- | ----------------------------------------------------- |
| Native staff Eddy SSE completion        | Engineering                         | Ready for review | No external decision                                                                  | Laravel BFF 9 tests/66 assertions; stream service 3/13; Android 122 Debug JVM + 26 API 35 AVD; iPhone 17 Pro 110 tests; contract/ledger verifiers pass | Staff-only; no patient flag           | Include with scoped commit; no deployment             |
| Pilot scope and governance              | Product / clinical / privacy        | Blocked          | Facility, units, cohort, disclosure policy, identity, language, SLA, escalation owner | None yet                                                                                                                                               | All patient flags remain off          | Convene decision meeting within one business day      |
| Approved patient source/release adapter | Integration / clinical content      | Blocked          | Named source system, source contract, review/release owner                            | Draft/reconciliation kernel exists; no approved production adapter or release authority                                                                | All patient exposure flags remain off | Select source and write source contract               |
| Patient native vertical slice           | Native / accessibility              | Not started      | Released pilot projection contract                                                    | Existing separate patient binaries and guarded presentation foundation                                                                                 | All patient flags remain off          | Bind fixtures to approved source/release contract     |
| Accountable communication pilot         | Nursing ops / support / engineering | Blocked          | Responsibility pools, shifts, topics, SLA, support desk                               | Local workflow foundations exist; pilot configuration and deployed E2E do not                                                                          | Messaging remains off                 | Configure two pilot unit pools and tabletop           |
| Integrated pilot rehearsal              | Release / independent reviewers     | Not started      | Waves 1–4 exit evidence                                                               | None yet                                                                                                                                               | All patient flags remain off          | Schedule after source, governance, and workflow gates |

## Evidence convention

Each subsequent entry must cite the exact commit SHA, command output/test count,
simulator or emulator target, feature-flag state, decision record, and unresolved
blocker. Narrative progress without those artifacts is not an accepted update.

## 2026-07-24 — Native staff Eddy no-store SSE increment

### Completed implementation

- The shared Laravel stream proxy now parses incremental upstream frames rather
  than forwarding the upstream terminal `complete` frame verbatim. It relays only
  token/error frames, persists the assistant result, emits a server-persisted clean
  `complete` reply, then emits the separately sanitized persisted proposal and
  `[DONE]`. Its response is `Cache-Control: no-store, no-cache, max-age=0` plus
  `Pragma: no-cache`.
- iOS (`URLSession.AsyncBytes`) and Android (`HttpURLConnection` Flow) consume the
  BFF stream through dedicated non-idempotent transports. They use no-store headers,
  a 45-second inactivity limit, cancellation when the visible scope disappears,
  no local transcript/cache, no automatic replay, and no generated idempotency key.
- Both chat surfaces update one pending assistant bubble with provisional token text,
  suppress a partial `<propose_action>` marker in defense in depth, and finalize only
  with the server-persisted clean reply. A stream cannot open, approve, or execute an
  action; approval remains a separate fetch-on-open, explicit-human flow.
- The OpenAPI summary, mobile reference, capability ledger, and governing checklist
  now distinguish completed native streaming from still-open history deletion,
  deployed role evidence, and autonomous action.

### Verification

| Boundary               | Command / target                                                             | Result                                                                                                        |
| ---------------------- | ---------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| Laravel mobile BFF     | `php artisan test tests/Feature/Mobile/Eddy/EddyMobileBffTest.php --compact` | 9 passed / 66 assertions                                                                                      |
| Laravel stream service | `php artisan test tests/Feature/Eddy/EddyStreamTest.php --compact`           | 3 passed / 13 assertions                                                                                      |
| Laravel formatting     | `./vendor/bin/pint` on the three changed PHP files                           | passed (Pint's bundled dependency emitted a non-source deprecation notice)                                    |
| Contract/ledger        | four Hummingbird verifier scripts                                            | 85 contract operations, 60 staff operations, 52 capabilities / 101 routes, and 25 patient operations verified |
| Android JVM            | `testDebugUnitTest --rerun-tasks`                                            | 122 tests, 0 failures/errors/skips                                                                            |
| Android emulator       | `connectedDebugAndroidTest --rerun-tasks` on `emulator-5554` / API 35        | 26 tests, 0 failures                                                                                          |
| iOS simulator          | `xcodebuild test` on iPhone 17 Pro / iOS 26.3.1                              | 110 tests, 0 failures/skips                                                                                   |

### Remaining boundary

This entry is a staff-only local change. It does not deploy, enable a patient flag,
activate the pending reference patient, or change patient release/governance status.
