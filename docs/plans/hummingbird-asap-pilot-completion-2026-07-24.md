# Hummingbird ASAP Pilot-Completion Plan — 2026-07-24

**Status:** active execution reset; no patient feature is enabled
**Execution log:** [DEVLOG-hummingbird-asap-pilot-completion-2026-07-24.md](../devlog/DEVLOG-hummingbird-asap-pilot-completion-2026-07-24.md)
**Governing scope:** [Hummingbird functional parity and patient experience plan](../hummingbird/ZEPHYRUS-HUMMINGBIRD-FUNCTIONAL-PARITY-AND-PATIENT-EXPERIENCE-PLAN-2026-07-19.md)
**Decision needed:** approve the controlled-pilot cutline in section 2 within one business day.

## 1. Purpose and honest baseline

The program has accumulated implementation across a very broad goal: staff parity
with Zephyrus, a separate patient product, projection governance, secure
communications, and production readiness. That is not one feature. It is a
multi-release healthcare program with several decisions that engineering cannot
make on its own.

The governing checklist currently contains **196 completed and 277 open items**
(473 total), reconciled from every Markdown checkbox on 2026-07-25. This is an
unweighted execution count, not a claim that the product is 41% clinically or
operationally ready. A single unresolved source-release or
identity decision can block a patient pilot regardless of how many code items are
complete.

This plan replaces open-ended activity with a shortest safe path to a **controlled
inpatient pilot**. It does not call unfinished work "complete," and it does not
enable any patient or messaging feature merely because a local test passes.

## 2. Completion target and scope lock

### 2.1 Target: controlled inpatient pilot

The target is one approved facility and two named inpatient units, with a small,
eligible adult cohort. The release is behind server-side kill switches and can be
withdrawn without a client update.

At pilot entry, a patient can:

- authenticate in the separate patient realm after approved enrollment;
- see an approved, plain-language **Today**, **My Path**, **Care Team**, and
  discharge-readiness view for the current encounter, including freshness and
  correction/stale-data language;
- use the approved calming Hummingbird background system without compromising
  contrast, large text, reduced-motion, or screen-reader use;
- ask a bounded non-urgent question through secure messaging and see an accurate
  receipt/status; and
- receive a response from an accountable, staffed care-team responsibility pool.

At pilot entry, a staff member can:

- receive the restricted For You/inbox item, claim it, reply, route, reassign,
  release, close, and see the immutable activity trail; and
- safely handle unit transfer, shift handoff, discharge, lost authorization, and
  a no-eligible-destination condition without exposing message content.

The pilot also requires an approved source/release path, projection health
monitoring, live-like deployed end-to-end evidence, incident/runbook coverage, and
written go/no-go approval. These are release requirements, not later polish.

### 2.2 Explicitly deferred from this pilot

The following remain disabled and are not silently included in the definition of
completion:

- patient Eddy, including any retrieval or generative explanation;
- proxy/representative, minor, sensitive-service, and guardianship access;
- attachments, offline message queues, patient push delivery, and post-discharge
  portal handoff;
- medication and result interpretation, full education/teach-back workflow, and
  Home Hospital transition;
- broad staff operational parity (rounds, huddles, ancillary, OR, transport,
  staffing, and prediction packages) beyond what is required to operate the pilot;
- general availability, a second facility, and broad language rollout.

Each deferral needs a named owner, risk statement, feature flag state, and a
follow-on release date before pilot approval. No item may be added to the pilot
without updating this plan, its acceptance matrix, and the governing ledger.

### 2.3 What “full parity” means after the pilot

Full Zephyrus-to-Hummingbird parity remains the governing plan's Phase 1/2 and
Phase 6/7 program. It is **not** achievable on the pilot critical path without
delaying the patient benefit and weakening validation. After the pilot gate passes,
the parity ledger should be burned down by disposition and user value: P0/P1
operational workflows first, then P2/P3 glance/deep-link surfaces.

## 3. Operating rules that stop drift

1. **One definition of done.** A work item is only done when implementation,
   authorization, contract, automated test, native evidence, telemetry, runbook,
   and owner sign-off required by its risk tier are linked in the execution log.
2. **Small vertical slices.** One pull request has one user-visible outcome and one
   explicit acceptance matrix. It may not bundle a source adapter, client redesign,
   and feature enablement.
3. **Feature flags stay off by default.** Merging code, provisioning synthetic data,
   and deploying a migration are separate from releasing patient access.
4. **No backfill by inference.** Patient content is released only from the approved
   source, policy, clinical review, and release chain. Unknown, stale, conflicting,
   or unreleased inputs fail closed.
5. **No unbounded work-in-progress.** Limit the active queue to one release blocker
   per workstream and one integration owner. New work replaces, rather than joins,
   the active queue.
6. **Daily evidence, not status prose.** Every day ends with the exact commit,
   tests, emulator/simulator result, changed flags, unresolved decision, and next
   smallest blocker in the execution log.

## 4. Completed staff increments: isolate them from the pilot critical path

The native staff Eddy streaming increment and the server-governed staff
communication-action increment are both bounded staff-parity work. They are not
dependencies of the patient pilot. They are accepted locally, documented in the
companion devlog, and must stay out of the patient release train until their own
review/release gate is met.

| Deliverable                            | Accepted evidence                                                                                                     | Safety boundary                                                                                                  |
| -------------------------------------- | --------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| Native staff Eddy SSE                  | Laravel, Android JVM/API 35, and iOS Simulator evidence recorded in the devlog at commit `2c60052b`                   | Raw proposal payload is never rendered as an executable action; no transcript persistence or replay.             |
| Staff communication-action affordances | PHP feature suite, web tests/build, Android API 35 emulator, and iOS Simulator evidence recorded at commit `7764bde1` | The server calculates per-user actions; clients fail closed and mutations independently re-authorize under lock. |

**Rule:** do not reopen or expand either increment while a pilot-critical Wave 1–5
blocker is active. New staff work enters the post-pilot ledger queue unless it fixes a
pilot safety defect.

## 5. Critical path to pilot

The schedule below is expressed in working days after the scope lock. It assumes a
decision-maker can answer the listed decisions within one business day, the source
owners provide a sandbox/feed, and three focused implementation lanes are staffed.
Without those inputs, calendar completion cannot be promised safely.

### Wave 1 — decisions, pilot configuration, and production-like test boundary (days 1–2)

**Owner roles:** product/pilot lead, clinical content lead, privacy/security/IAM,
integration lead, nursing operations lead, engineering release owner.

1. Ratify the facility, two units, cohort exclusions, support hours, languages,
   message topics, non-urgent/urgent wording, response SLA, escalation owner, and
   kill-switch owner.
2. Sign the initial disclosure matrix and prohibited-data list. Record the approved
   source, release policy, freshness threshold, uncertainty language, correction
   behavior, and owner for every field in Today, My Path, Care Team, and discharge
   readiness.
3. Choose the patient enrollment/identity workflow for the pilot. It must include
   identity proofing, recovery, device/session policy, revocation, and staff-support
   escalation; a synthetic reference patient is not a substitute for this decision.
4. Establish a production-like, non-production test boundary with representative
   source data, redacted logs, queues, scheduler, feature flags, and observability.
   The existing production reference identity remains pending/inactive and must not
   be activated as a shortcut.
5. Publish the pilot configuration manifest and flag matrix. Each flag needs a
   default, owner, data classification, audit event, rollback action, and expiry.
   The fail-closed template is published at
   [Hummingbird Controlled-Pilot Configuration Manifest](../operations/HUMMINGBIRD-CONTROLLED-PILOT-CONFIGURATION-MANIFEST.md);
   it intentionally contains no authorization-time values and does not satisfy
   the Wave 1 exit until the signed evidence is attached.

**Wave 1 exit evidence:** signed decision record, field-level disclosure matrix,
pilot configuration manifest, threat/hazard workshop dates, and an enabled test
environment in which flags can be independently proven off.

### Wave 2 — approved projection pipeline (days 2–7)

**Critical path owner:** integration lead. This is the primary engineering blocker.

1. Implement the approved source adapter contract for the selected facility. It
   must bind source identity/version/time, reject unmapped fields, and retain only
   the protected internal trace required for reconciliation.
2. Complete the Today, My Path, Care Team, discharge-readiness, and selected
   rounds-summary producers. Keep source extraction, draft generation, clinical
   review, release authority, and patient projection publication as separate
   auditable steps.
3. Add reconciliation, idempotency, late/out-of-order, stale/source-behind,
   correction, retraction, dead-letter, and replay tests. Prove that no source
   identifier or unreleased content reaches the patient API, telemetry, or alert.
4. Add operational monitors for projection lag, failed releases, unowned messages,
   and source outage. Define who receives each alert and the patient-facing stale
   behavior.
5. Run clinical/content review with nursing, physicians, care management, pharmacy,
   HIM, health literacy, accessibility, and patient advisors for the actual
   pilot content—not a synthetic fixture.

**Wave 2 exit evidence:** approved source contract; version-pinned released
projections in the test boundary; reconciliation/retraction proof; dashboard/alert
test; clinical review records; and an API response captured from a released,
authorized projection.

### Wave 3 — patient mobile vertical slice (days 3–8, in parallel with Wave 2)

**Critical path owner:** native lead.

**Current local foundation (2026-07-25):** the same six patient-care DTOs are
captured through the testing-only patient BFF and decoded by the production iOS and
Android model layers. One separately labelled, test-only forward-compatibility fixture
is deterministically derived from the pathway-events BFF capture to prove nullable,
unknown-enum, additive-field, exact-integer/decimal, and large-payload handling.
Focused iPhone 17 Pro and Android API 35 journeys are green. This closes local contract
ratification only; the Wave 3 exit still requires the approved-source release responses
and all listed release-mode/accessibility evidence.

1. Bind the already separate iOS and Android patient binaries to the approved pilot
   enrollment and session lifecycle, including expired/revoked access and a generic
   non-disclosing denial.
2. Complete the Today, My Path, Care Team, discharge-readiness, and bounded rounds
   summary states: loading, unavailable, stale, corrected, access lost, and source
   outage. Never synthesize missing clinical content on device.
3. Verify the Hummingbird-image background system on the real screens at normal and
   large text, light/dark/high-contrast settings, reduced transparency/motion, and
   image-disabled fallback. Background artwork must not obscure clinical meaning or
   emergency instructions.
4. Test both platform apps against the same contract fixtures and release responses.
   Add UI journeys for a fresh install, enrollment, current encounter access,
   correction notice, stale projection, session revocation, and logout/wipe.
5. Use iOS Simulator and Android API 35 emulator for every end-to-end journey.
   Device evidence includes the build SHA, platform/OS, test name, result, and any
   visual/accessibility capture retained under the evidence policy.

**Wave 3 exit evidence:** both apps pass release-mode build, unit, UI, and emulator
journeys for the same approved projection; no patient data is retained outside the
approved protected-storage/no-store policy; automated accessibility checks and
manual screen-reader review have no P0/P1 defect.

### Wave 4 — accountable communication and staff response (days 4–9)

**Critical path owner:** operations workflow lead.

1. Configure approved patient message topics, responsibility pools, current-shift
   membership, response SLA, handoff policy, and escalation contact for the two pilot
   units. Do not infer service ownership where no authoritative feed exists.
2. Exercise patient compose, receipt, outbox delivery, staff For You/inbox, claim,
   reply, route, release, reassign, reroute, escalation, transfer, discharge, and
   access revocation through the deployed-equivalent boundary.
3. Add/complete the operator runbook for unowned, aged, unresolved, duplicate,
   source-outage, and downtime situations. The no-eligible-destination state must be
   visible to the support desk and must not fabricate a recipient.
4. Prove message text stays out of push payloads, routing/activity telemetry, and
   unnecessary staff list views. Keep attachments and push delivery disabled for this
   pilot.
5. Train the response desk and run a timed tabletop with nursing and support.

**Wave 4 exit evidence:** all message lifecycle transitions produce exactly one
auditable outcome; no test thread exceeds SLA without a visible escalation; staff
and patient receipt state match; transfer/discharge/access-loss simulations purge
stale access on both clients.

### Wave 5 — integrated release gate (days 9–12)

**Critical path owner:** release owner, with independent reviewers.

1. Execute the reference scenarios from enrollment through discharge using the
   approved source boundary and the actual pilot configuration. Cover source outage,
   stale/retracted projection, message handoff, unit transfer, discharge with an open
   thread, revoked session, and wrong/unknown resource.
2. Run security/privacy, clinical-safety, accessibility, language-access, and
   patient/family usability reviews. Findings are triaged by severity; any P0/P1
   finding blocks enablement.
3. Test kill switches and rollback: disable patient read, messaging, pathway,
   rounds-summary, source adapter, and native service access independently. Verify
   generic safe behavior and that queued/replayed work does not publish patient data.
4. Verify backup/restore, queue/scheduler health, encryption-key access procedure,
   audit retention, deployment manifest, SLO dashboard, and on-call escalation.
5. Produce a one-page go/no-go packet: exact release SHA, migration state, flag
   states, test results, known limitations, pilot cohort, incident contacts, rollback
   steps, and all approval signatures.

**Wave 5 exit evidence:** signed go/no-go packet and a successful rehearsal of
enablement, disablement, and rollback in the pilot-like environment.

### Wave 6 — smallest controlled enablement (days 13–15)

1. Deploy only from synchronized, CI-green `main` using `./deploy.sh --check` then
   `./deploy.sh`; use a separately approved, path-scoped migration procedure only
   if required. Do not use direct production pulls or ad hoc SSH deployment.
2. Verify the immutable release SHA, service health, migration state, and HTTPS
   route before enabling any flag.
3. Enable the smallest eligible cohort; monitor the named SLOs, response desk,
   projection freshness, delivery states, and error/denial rates. Hold the next cohort
   until the first cohort's evidence is reviewed.
4. Disable immediately on a clinical-safety, privacy, authorization, incorrect
   disclosure, unowned-message, or kill-switch failure. Preserve the audit trail
   and open an incident; do not attempt an in-place silent correction.

## 6. Parallel staff-parity burn-down after the pilot cutline

The staff capability ledger must not become invisible, but it must not block the
patient pilot unless it is used by pilot operations. Work it in this order:

| Order | Release slice                                                    | Required proof before closing                                                               |
| ----- | ---------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| 1     | Contract generation/fixture freshness and auth/session lifecycle | server, Swift, Kotlin contract decoding; refresh/revoke/error tests                         |
| 2     | Realtime/push transport and safe notification policy             | APNs/FCM production-like delivery, redaction, collapse, expiry, revocation                  |
| 3     | Existing mobile write parity                                     | replay, conflict, authorization change, audit event, native emulator UI                     |
| 4     | P1 operational workflows                                         | role-specific staff journey, ownership, source/provenance, failure mode, deep-link boundary |
| 5     | P2/P3 glance/analytics/deep-link surfaces                        | explicit ledger disposition, usefulness validation, no misleading clinical/forecast claim   |
| 6     | GA hardening                                                     | load/failover/recovery, independent audits, multi-facility manifest, support runbooks       |

No capability is marked complete from a route or mock screen alone. The ledger entry
must link its source, authorization, client support, contract, evidence, telemetry,
and disposition.

## 7. Daily control board

Maintain this table in the companion devlog at the end of every workday. An empty
cell is a blocker, not an implicit pass.

| Field                  | Required entry                                                                           |
| ---------------------- | ---------------------------------------------------------------------------------------- |
| Release slice          | one user outcome and repository paths                                                    |
| Owner                  | accountable person and backup                                                            |
| Status                 | not started / in progress / blocked / ready for review / accepted                        |
| Decision or dependency | exact question, owner, due time, and safe fallback                                       |
| Evidence               | commit SHA, command/test result, emulator/simulator/device result, review artifact       |
| Flag state             | off / test-only / pilot candidate, plus kill-switch test                                 |
| Risk                   | privacy, clinical-safety, authorization, reliability, accessibility, or operational risk |
| Next 24-hour action    | smallest verifiable step only                                                            |

The release owner reviews this board daily. Work with no named owner, acceptance
evidence, or dependency due time is removed from active work.

## 8. First 48 hours: exact execution order

1. Freeze the accepted staff increments above, confirm the worktree/release branch
   boundary, and keep every patient and messaging flag off.
2. Hold a 60-minute decision meeting to approve the scope lock, pilot units,
   disclosure policy, enrollment approach, source owner, message topics/SLA, and
   decision owners. Record unanswered decisions as blockers with dates.
3. Create the pilot configuration/flag manifest and a test-environment release
   checklist. The template now exists at
   [Hummingbird Controlled-Pilot Configuration Manifest](../operations/HUMMINGBIRD-CONTROLLED-PILOT-CONFIGURATION-MANIFEST.md),
   with every patient and related-system flag defaulting to off; fill it only
   from signed pilot decisions in a non-production release record.
4. Select and document the approved source adapter input for the four pilot patient
   views. The six deterministic test-only projection fixtures and one derived
   compatibility probe ratify the native DTO boundary, but they are not an approved
   source release; create separate release fixtures from the selected source and reject
   synthetic content as a release substitute.
5. Start Waves 2, 3, and 4 in parallel, each with exactly one integration owner.
6. At the end of day two, hold a hard checkpoint. If source/release or governance
   approval is still absent, stop feature expansion and escalate that dependency;
   do not pad the schedule with unrelated parity work.

## 9. Controlled-pilot completion criteria and schedule risk

With the stated decisions and inputs, the engineering and rehearsal path is **15
working days**. This is an aggressive controlled-pilot estimate, not a guarantee:
clinical content approval, identity/federation selection, source-system access,
privacy/legal review, and pilot staffing are external gates. If any of those are
unavailable, the correct state is **blocked**, not "nearly complete."

The pilot is complete only when every Wave 5 exit artifact and the Wave 6 first-cohort
review are accepted. Full Zephyrus parity and general availability remain separate,
ledger-governed releases after that controlled pilot; they should not be represented
as complete by this plan.

## 10. Fastest credible path to full program completion

The controlled pilot is the first release, not a redefinition of the original
commitment. The remaining program has two release tracks that run in parallel after
the Wave 1 scope lock. This is the shortest credible sequence because it removes the
shared platform and governance blockers before multiplying feature work.

### 10.1 Capacity and sequencing assumption

This schedule assumes a dedicated delivery pod: one backend/integration engineer,
one iOS engineer, one Android engineer, one web/operations engineer, one QA/release
owner, and named part-time clinical, privacy, IAM, accessibility, and patient-advisor
reviewers. A person may cover multiple engineering roles only if that does not reduce
the listed acceptance evidence. Workstream owners meet for a 15-minute dependency
review every business day; blocked decisions are escalated after one business day.

| Program release                        | Earliest execution window after scope lock | Completion boundary                                                                                                                        | Cannot start without                                                                        |
| -------------------------------------- | ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------- |
| Controlled inpatient pilot             | Days 1–15                                  | Waves 1–6 and first-cohort review accepted                                                                                                 | approved pilot scope, source/release owner, identity approach, response staffing            |
| Staff P0/P1 parity release             | Days 3–35, parallel                        | contract/client seam, push/realtime, write/conflict paths, and P0/P1 role journeys accepted on both platforms                              | capability owners and production notification credentials/environments                      |
| Patient general-availability readiness | Days 16–55, parallel after pilot evidence  | proxy policy as applicable, language/accessibility, discharge transition, retention/export, security/a11y review, scale/readiness controls | pilot evidence, legal/HIM decisions, translation/interpreter model, portal-transition owner |
| Full multi-facility GA                 | Days 56–75                                 | Phase 7 exit evidence, load/failover drills, independent audits, facility manifests, and signed go/no-go                                   | successful pilot, remediation closure, operational support capacity                         |

These are aggressive execution windows, not promises of calendar completion. They do
not include time that external approvers, source-system owners, app-store review, or
an independent audit take to respond. If the program needs a date rather than an
evidence gate, the steering group must explicitly accept the residual risk; engineering
must not convert an unanswered decision into an implicit approval.

### 10.2 Post-pilot release train

| Release                      | Scope in strict order                                                                                                                                                                                                   | Required acceptance evidence                                                                                                                                                   |
| ---------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| S1 — shared safety platform  | Factory-derived fixtures; generated Swift/Kotlin contract artifacts; error/envelope compatibility; client request IDs; encrypted cache/outbox only for approved staff workflows; wipe/revocation; session privacy cover | PHP, Swift, Kotlin decode and compatibility gates; replay/conflict tests; simulator/emulator offline, revoke, and restore journeys                                             |
| S2 — operational staff P0/P1 | P1 RTDC/huddle/rounds and task-owner workflows; explicit dispositions for every remaining Zephyrus capability; complete high-value write lifecycle; notification/realtime hardening                                     | Every released ledger item has owner, source, authorization, contract, two-client journey, telemetry, runbook, and role acceptance evidence                                    |
| S3 — patient GA uplift       | Approved proxy/revocation scope; translated and interpreter-supported content; education/teach-back, discharge/home-transition, retention/export; patient push only after provider and policy approval                  | Patient/representative access matrix; translated-content review; accessibility and usability review; messaging/downtime/urgent tabletop; security and clinical-safety sign-off |
| S4 — multi-facility GA       | Facility manifests, policy parameterization, source and responsibility-pool onboarding, load/failover/EHR-outage recovery, independent audits, support/on-call operating model                                          | Facility-readiness assessment for each launch site; recovery drill; SLO/alert evidence; signed release and rollback packet                                                     |

### 10.3 Rules for declaring the program complete

1. The controlled pilot is not called GA; S1/S2/S3/S4 are not called complete
   because a route, mock screen, or local test exists.
2. The governing capability ledger is the single staff-parity checklist. Every
   patient field remains subject to its disclosure matrix and source/release rule.
3. A release can close only when its exit evidence is independently reviewed and the
   companion devlog links the exact release SHA, flags, migrations, test matrix,
   emulator/simulator evidence, operational approval, and rollback result.
4. The program is complete only when both definition-of-done sections in the
   governing plan are accepted, all pilot/GA deferred items have either been
   delivered or formally retired with owner approval, and no patient/staff feature
   remains enabled on an unreviewed source, policy, or operational dependency.
