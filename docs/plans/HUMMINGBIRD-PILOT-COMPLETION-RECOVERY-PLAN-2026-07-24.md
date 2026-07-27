# Hummingbird Pilot Completion Recovery Plan

**Date:** 2026-07-24  
**Status:** proposed recovery plan; it does not authorize patient activation, a migration, or a production release  
**Companion execution log:** [DEVLOG-hummingbird-pilot-completion-recovery-2026-07-24.md](../devlog/DEVLOG-hummingbird-pilot-completion-recovery-2026-07-24.md)  
**Baseline:** [Zephyrus-Hummingbird Functional Parity and Inpatient Experience Plan](../hummingbird/ZEPHYRUS-HUMMINGBIRD-FUNCTIONAL-PARITY-AND-PATIENT-EXPERIENCE-PLAN-2026-07-19.md)

## 1. Decision and recovery objective

The program must stop treating the 463-item parity checklist as one undifferentiated engineering sprint. Its current raw count is **172 checked and 291 open (37.1%)** on `main` at this plan's date. That count is useful for transparency, but it is not a safe release metric: it mixes engineering foundations, broad GA work, clinical governance, source-system access, operational staffing, and external review.

The fastest credible outcome is a **small, controlled inpatient pilot**, not immediate general availability and not a claim that all Zephyrus staff functionality is complete on mobile. The recovery target is:

1. Close a narrowly defined, approved patient pilot in **15 working days of uninterrupted decisions and access**.
2. Obtain a go/no-go packet after a live-like rehearsal, not automatically activate patients on day 15.
3. Hold full staff functional parity and patient GA as separately measured releases after pilot evidence exists.

This is an acceleration plan, not a safety exception. A patient product must remain disabled whenever identity, current encounter authorization, source/release authority, responsible responder coverage, or required review evidence is absent.

## 2. Finish lines and honest timing

| Finish line                            | Definition                                                                                                                                     | Earliest credible timing      | Required authority                                                                                                                 |
| -------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| **Engineering-integrated pilot slice** | The approved minimum flows work against live-like approved sources, with negative authorization, reconciliation, routing, and native evidence. | 15 working days               | Engineering and test-environment access only; no patient activation.                                                               |
| **Pilot-ready**                        | A named facility, two staffed units, a small eligible cohort, and all required evidence have a signed go/no-go decision.                       | 3–6 weeks                     | Clinical safety, privacy/HIM/IAM, nursing/operations, integration, security, accessibility, language-access, and product approval. |
| **General availability / full parity** | The agreed staff and patient definition of done, pilot exit measures, broader support model, and release-board criteria are complete.          | 10–14 weeks after pilot start | Cross-functional release authority; not engineering alone.                                                                         |

The 15-day target assumes decisions within 48 hours, test access to the selected identity and source systems by day 3, dedicated backend/integration, iOS, Android, QA/release, and operations owners, and no material safety finding. If any assumption fails, the schedule must move or the pilot scope must shrink; engineering must not replace a missing control with fixtures or a demo login.

## 3. Immediate scope reset

### 3.1 Pilot promise

The pilot may contain only these controlled capabilities:

- correct patient identity proofing and current-inpatient encounter authorization;
- patient-readable **Today**, **My Path**, **Care Team**, and approved discharge-preparation content from a named, approved source;
- a bounded non-urgent message to an accountable responsibility pool, only while coverage is current;
- staff receipt, ownership, handoff, response, closure, transfer/discharge handling, and audit of that message;
- explicit urgent-help language directing the patient to the approved bedside/emergency route;
- safe withholding, stale/degraded language, correction/retraction, revocation, and global/unit kill switches;
- accessible, static, locally bundled Hummingbird imagery only after visual-asset approval.

Every displayed field and message topic must exist in the signed disclosure matrix. If a field lacks an approved source, release rule, freshness expectation, correction behavior, patient wording, and owner, it is excluded rather than marked "temporarily complete."

### 3.2 Explicitly defer from the first pilot

Keep the following disabled, hidden from launch claims, and assigned to later releases:

- patient Eddy or any generative patient-facing assistance;
- attachments, photos, and free-form document exchange;
- proxy, guardian, minor, sensitive-service, and delegation workflows;
- offline patient message composition or retained PHI queues;
- dynamic machine translation or broad language expansion;
- medication/result interpretation, clinical orders, exact ETAs, Home Hospital, and post-discharge management;
- additional facility/service-line rollout and broad staff-persona expansion;
- cosmetic work that does not fix a documented comprehension, accessibility, privacy, or safety defect.

The full staff-parity backlog continues, but it cannot delay the pilot unless it blocks the selected staff responder journey. Each non-pilot capability must receive a disposition, named owner, target release, and no-claim rule in the capability ledger.

## 4. Required decisions in the first 48 hours

The sponsor must convene a single decision session and assign one accountable owner and evidence location for each item below. A missing owner is a blocker, not an engineering task.

| Decision                                                                              | Accountable owner                              | Evidence required by end of day 2                                                                          | If unavailable                                               |
| ------------------------------------------------------------------------------------- | ---------------------------------------------- | ---------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------ |
| Facility, two pilot units, cohort, languages, hours, exclusions, and support model    | Executive/product sponsor + nursing/operations | One-page pilot charter                                                                                     | No patient scope; retain synthetic usability rehearsal only. |
| Patient disclosure and prohibited-data matrix                                         | Clinical safety + privacy/HIM                  | Signed field/topic rows with source, release, freshness, correction, language, and degraded-state policy   | Remove unapproved rows from the app/API.                     |
| Identity proofing, federation, recovery, encounter eligibility, and revocation policy | IAM/HIM                                        | Test-tenant plan, assurance/recovery rules, merge/discharge handling, and representative policy            | No enrollment or patient login.                              |
| Message ownership, escalation, downtime, and urgent wording                           | Nursing/operations + clinical safety           | Responsibility-pool policy, staffing source, hours, fallback, tabletop script, and patient copy            | Keep messaging off; retain read-only pilot only.             |
| Approved source contracts                                                             | Integration/data + clinical safety             | Authoritative source, record key, allowed fields, cadence, DQ checks, retention, change owner, outage rule | Do not use fixtures or raw source content as patient data.   |
| Hummingbird image license, attribution, privacy, and accessibility status             | Product + legal/privacy + accessibility        | Approved provenance register and high-contrast/no-scenery fallback                                         | Remove unapproved imagery from pilot scope.                  |
| Go/no-go reviewers and stop conditions                                                | Sponsor                                        | Named reviewers, date, kill-switch owner, incident/restart authority                                       | No patient activation date may be scheduled.                 |

## 5. Five binary acceptance packages

Each package has an accountable delivery owner, a visible evidence register row, and a binary exit. A PR, demo, feature flag, or passing happy-path test alone does not close a package.

### Package A — Pilot governance and safe scope (days 0–2)

**Owners:** sponsor, clinical safety, nursing/operations, privacy/HIM/IAM, product.

Deliverables:

- pilot charter; disclosure/prohibited-data matrix; message policy; identity policy; visual-asset approval; risk and stop-condition register;
- one explicit definition of pilot success, including cohort ceiling, support hours, safety metrics, and no-response/escalation behavior;
- feature flag inventory showing patient product, messaging, pathway release, reference provisioning, and push gates remain off until go/no-go.

Exit evidence:

- named owners sign all six artifacts;
- every pilot field/topic has a source and release owner;
- all exclusions in section 3.2 have a no-claim rule;
- a reviewer can identify the global and unit kill-switch owner.

Failure response: remove the disputed field/topic/visual asset from scope the same day. Do not wait for optional content to settle.

### Package B — Identity, grant, enrollment, and revocation (days 2–8)

**Owners:** IAM/HIM, backend, iOS, Android, QA/security.

Deliverables:

- approved IdP/proofing test-tenant integration and recovery design;
- current-encounter grant adapter with default-deny, merge/discharge/revocation behavior, opaque identifiers, and audit redaction;
- enrolled patient, recovery, session inventory/revocation, native protected-state purge, and account exit journeys;
- negative authorization matrix for wrong patient, staff credential, wrong realm, stale/revoked/merged identity, discharged encounter, cross-unit, cross-facility, and replayed request.

Exit evidence:

- live-like positive and negative journeys pass on both native platforms and the server;
- independent reviewer validates audit records and absence of protected content after revocation;
- measured revocation delay meets the approved policy;
- no enrollment secret, HMAC, password, or patient data appears in logs, source control, test output, or push content.

Failure response: a supervised, no-PHI usability rehearsal is allowed; a demo identity account is not a substitute for patient proofing.

### Package C — Approved source to patient-safe projection (days 2–10)

**Owners:** integration/data, clinical safety, backend, QA.

Deliverables:

- default-deny source allowlist and versioned source contracts for the selected Today, My Path, Care Team, and discharge fields;
- reconciliation worker with bounded replay, idempotency, lag/error measures, safe retry classes, dead-letter procedure, and no inferred cancellation from a missing snapshot;
- draft, review, release, withheld, stale, corrected, and retracted projection states;
- patient-readable freshness/provenance/uncertainty treatment and one governed state vocabulary across iOS and Android.

Exit evidence:

- reference journeys cover initial load, duplicate, late event, wrong encounter, source outage, unavailable field, correction, retraction, stale state, and recovery;
- raw source identifiers and prose are absent from patient responses and telemetry;
- a clinical reviewer and release owner can independently reproduce the release decision.

Failure response: reduce to the smallest approved read-only field set. Do not expose a deterministic fixture as current care.

### Package D — Accountable messaging and staff responder journey (days 6–12)

**Owners:** nursing/operations, backend, staff-web, iOS, Android, QA.

Deliverables:

- authoritative responsibility-pool/coverage feed and facility/unit/service context, each rechecked at server action time;
- non-urgent patient message creation, exact replay, assignment, acknowledgment, response, handoff, escalation, closure, and content-minimized audit;
- patient-visible state vocabulary that never promises a named clinician, delivery/read certainty, or unsafe response time;
- staff inbox/detail flow with authorization, privacy-clearing, unit/team/facility/service filters, and transfer/discharge/downtime handling;
- provider-sandbox notification configuration only after Package A's notification policy is approved.

Exit evidence:

- a tabletop and live-like sequence exercises message creation, unavailable coverage, ownership, shift handoff, unit transfer, discharge, duplicate/replay, source outage, and kill switch;
- unowned/ambiguous routing fails visibly to `unresolved` and has an operational owner; it never silently substitutes a recipient;
- both native patient clients and the staff responder use the same server-defined lifecycle semantics.

Failure response: leave messaging off and continue only read-only patient information. Messaging cannot be "beta" without coverage authority.

### Package E — Independent validation, release rehearsal, and go/no-go (days 10–15)

**Owners:** QA/release/security, clinical safety, accessibility, language access, patient/family reviewers, sponsor.

Deliverables:

- security, privacy, mobile authorization, dependency/SBOM, and secrets-review evidence;
- patient usability, plain-language, accessibility, VoiceOver/TalkBack, large text, high-contrast, reduced motion/transparency, and no-scenery review;
- native simulator/emulator evidence for the exact pilot journeys, including unavailable, stale, revoked, and error states;
- release rehearsal through protected `main`, exact-SHA CI, `./deploy.sh --check`, controlled deploy, service health, HTTPS verification, and rollback/kill-switch proof; migrations remain separate and path-scoped;
- one go/no-go packet containing exact commit, CI runs, release manifest, flags, test evidence, unresolved findings, owners, and pilot stop criteria.

Exit evidence:

- all P0/P1 findings are resolved or the affected feature is removed from the pilot;
- every required reviewer signs or explicitly records a no-go;
- global/unit kill switch, source outage, correction/retraction, transfer/discharge, message escalation, and identity/session revocation are rehearsed in the deployed boundary.

Failure response: publish a documented hold with the failed package and owner. Do not enable a cohort to meet a calendar date.

## 6. 15-working-day schedule and parallel lanes

| Days  | Governance / operations lane                                                        | Backend / integration lane                                                      | Native / staff lane                                                                  | Daily exit                                                 |
| ----- | ----------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ | ---------------------------------------------------------- |
| 0–2   | Close Package A decisions, name source/IdP/pool owners, freeze scope and deferrals. | Map selected contracts and feature gates; prepare live-like observability.      | Freeze non-pilot work; inventory pilot journeys and acceptance IDs.                  | Signed scope or documented read-only/no-PHI fallback.      |
| 3–5   | Give test access, staffing roster, message policy, and visual approval.             | Build/verify grant and source seams; default-deny policies; test data.          | Complete pilot-screen semantics, protected-state behavior, and staff responder gaps. | First correct-denial and withheld-projection evidence.     |
| 6–8   | Review disclosure/release rules; tabletop coverage and urgent language.             | Reconciliation, release, correction/retraction, audit, and routing lifecycle.   | iOS/Android read flows plus staff ownership/handoff; no speculative expansion.       | Package B passes or pilot becomes no-enrollment rehearsal. |
| 9–10  | Approve final pilot field/topic set and degradation language.                       | Complete Package C tests, lag/error alerting, dead-letter recovery.             | Simulator/emulator test the same state vocabulary and empty/error/revoked views.     | Package C passes with approved source evidence.            |
| 11–12 | Tabletop transfer, discharge, unowned queue, and outage.                            | Complete Package D lifecycle/telemetry; provider-sandbox push only if approved. | Staff detail/inbox and both patient clients exercise identical lifecycle.            | Package D passes or messaging remains disabled.            |
| 13–15 | Independent review, release-board preparation, sign/hold decision.                  | Exact-SHA CI/release rehearsal, service/manifest/rollback proof.                | Accessibility/large-text/screen-reader evidence and final native E2E.                | Package E go/no-go packet, never automatic activation.     |

## 7. Delivery discipline that removes avoidable delay

1. Run a 15-minute 09:00 decision/risk stand-up and a 15-minute 16:30 integration demonstration every working day. Every blocker has one owner, a same-day next action, and a recorded decision deadline.
2. Maintain one visible package board: `not ready`, `in build`, `awaiting independent evidence`, `accepted`, or `held`. No ambiguous “mostly done.”
3. Limit work in progress to one contract/backend change, one iOS change, and one Android/staff change per package. New feature requests enter the deferred queue until the current package exits.
4. Make server contract, authorization, and lifecycle semantics authoritative. Native code may have platform-native presentation but may not invent authorization, state, retry, or freshness rules.
5. Use local focused checks while coding; run full exact-SHA CI and all affected native/server tests only for package closure. Record actual test duration and failure class so performance work targets measured bottlenecks instead of increasing timeouts.
6. Merge narrow reviewed PRs mapped to one package. Do not use direct production SSH changes, direct production `git pull`, broad migrations, or catch-all PRs.
7. Continue the broad checklist only by mapping each open item to `pilot-critical`, `GA`, `post-GA`, `external decision`, or `retired`. An unchecked item without this disposition is the next planning defect to resolve.

## 8. Evidence register and daily metrics

Create one evidence row per package requirement. A package cannot be accepted with self-attestation, a screenshot without a reproducible test, or an environment with unknown commit identity.

| Package | Requirement                                                       | Artifact path/URL | Commit / environment | Independent reviewer | Result/date | Open risk and mitigation |
| ------- | ----------------------------------------------------------------- | ----------------- | -------------------- | -------------------- | ----------- | ------------------------ |
| A       | Charter, disclosure, messaging, identity, assets, hazard register |                   |                      |                      |             |                          |
| B       | Wrong-patient, revoked, merged, discharged, recovery journeys     |                   |                      |                      |             |                          |
| C       | Source-to-release-to-correction/retraction journey                |                   |                      |                      |             |                          |
| D       | Coverage, handoff, transfer, discharge, escalation tabletop       |                   |                      |                      |             |                          |
| E       | Accessibility, security, release rehearsal, go/no-go packet       |                   |                      |                      |             |                          |

Report four measures daily:

- **Package completion:** accepted packages / 5. This is the primary pilot measure.
- **Pilot evidence completion:** accepted required artifacts / required artifacts. This prevents a feature demo from counting as release readiness.
- **Decision latency:** decisions closed within 48 hours / decisions raised. This identifies the actual schedule constraint.
- **Backlog disposition:** open checklist items assigned a release/disposition / all open checklist items. Raw checked boxes remain informational only.

## 9. Automatic no-go conditions

Do not enable a patient cohort if any of the following is true:

- a patient cannot be proofed, tied to a current encounter, promptly revoked, or kept out of another patient's data;
- any field/topic has no approved source, release authority, freshness/uncertainty language, correction rule, or responsible owner;
- the receiving pool, coverage source, urgent wording, escalation, or downtime owner is undefined;
- a critical/high clinical safety, privacy, security, accessibility, language, or patient-usability finding remains open;
- imagery lacks license/attribution/privacy approval or its accessible no-scenery treatment fails;
- source lag/error monitoring or dead-letter recovery is untested;
- deployed E2E has not exercised revocation, correction/retraction, transfer/discharge, message handoff, source outage, and kill switch;
- the exact commit, CI status, release manifest, migration disposition, service health, or rollback evidence is missing;
- patient copy promises a response, delivery/read state, clinical interpretation, or urgent support that operations cannot fulfill.

## 10. Definition of recovered completion

The recovery plan is complete only when an independent reviewer can follow one approved reference inpatient through this auditable sequence:

1. The correct patient gains access to one current eligible encounter; wrong-patient, staff, revoked, merged, or discharged access fails without disclosure.
2. An approved source produces a small set of patient-safe care content, which is reviewed, released, displayed with accurate freshness/uncertainty, and can be corrected or withdrawn.
3. The patient can find urgent-help instructions and, only if the pilot enables it, send a non-urgent message to an accountable pool.
4. The pool receives, owns, hands off, replies to, and closes the work through transfer, discharge, and outage conditions without losing the thread or silently rerouting it.
5. iOS and Android display the same governed meaning with readable, calming, accessible Hummingbird treatment; decorations may disappear without removing safety information or actions.
6. Revocation, source outage, correction/retraction, feature kill switches, and release rollback are proven in a deployed rehearsal.
7. Clinical safety, privacy, security, accessibility, language access, patient/family, operations, and release authorities accept the evidence or formally hold the pilot.

Anything less is useful engineering progress, but it is not a safe patient-facing completion claim.
