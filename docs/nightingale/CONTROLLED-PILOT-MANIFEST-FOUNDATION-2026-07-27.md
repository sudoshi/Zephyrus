# Nightingale controlled-pilot manifest foundation

**Date:** 2026-07-27

**Status:** generated, default-off governance candidate; not approved, implemented, or
authorized for pilot use

**Product:** Nightingale

**Executable API paths:** 0

**Runtime evaluator/callers:** 0/0

**Current production effect:** none

**Acceptance evidence:**
[`controlled-pilot-manifest-2026-07-27`](../evidence/nightingale/controlled-pilot-manifest-2026-07-27/README.md)

## 1. Executive disposition

Nightingale now has a mechanically defined configuration boundary for the first controlled
pilot. It describes the exact facility, unit, cohort, language, exclusion, support-hour,
validity, prerequisite, named-approval, audit, rollback, and kill-switch fields that a
future pilot manifest must contain.

The committed manifest is deliberately empty and inactive:

- no facility, unit, cohort, or locale is selected;
- maximum active enrollment is zero;
- no support window is claimed;
- start and expiry are absent;
- no approval, clinical release, source deployment, audit sink, rollback plan, or
  kill-switch test is recorded;
- go/no-go review has not been requested; and
- runtime activation remains prohibited.

The candidate therefore cannot enroll anyone or make any Nightingale behavior available.
It closes only the plan requirement to define a fail-closed manifest shape. It does not
close identity, content, accessibility, privacy/security, clinical-safety, integration,
distribution, pilot-authorization, deployment, or production gates.

## 2. Why this boundary is implemented before live functionality

The current Nightingale foundation has five independent activation states, but it
previously had no concrete pilot-scope record. An `enrolled` state without an exact,
expiry-bounded scope would be ambiguous about:

- which facility and units are covered;
- which cohort and exclusion policy apply;
- which languages and interpreter coverage are supported;
- when staffed support is available;
- how many participants can be active;
- when the authorization ends;
- which approvals, releases, source deployment, and audit sink are prerequisites;
- which kill switch and rollback target apply; and
- which durable events prove that a scope changed or expired.

The manifest candidate eliminates that ambiguity without enabling a runtime. It is
designed so that missing, malformed, stale, contradictory, or unavailable inputs hold
before external go/no-go review.

## 3. Generated artifacts and authoritative implementation

| Artifact                                                                                                                | Role                                                                                                                                                  |
| ----------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| [`pilot/candidates/v0/candidate.json`](./pilot/candidates/v0/candidate.json)                                            | Generated candidate policy, empty committed template, constraints, technical ceilings, approval/audit inventories, and checksum-bound source evidence |
| [`pilot/candidates/v0/fixtures.json`](./pilot/candidates/v0/fixtures.json)                                              | Synthetic no-PHI complete example and 34 deterministic evaluation cases                                                                               |
| [`build-nightingale-controlled-pilot-manifest.mjs`](../../scripts/ci/build-nightingale-controlled-pilot-manifest.mjs)   | Deterministic artifact builder                                                                                                                        |
| [`verify-nightingale-controlled-pilot-manifest.mjs`](../../scripts/ci/verify-nightingale-controlled-pilot-manifest.mjs) | Independent structural, semantic, source-hash, foundation-state, fixture, and negative-mutation verifier                                              |

The builder pins four current inputs:

1. code-owned Nightingale backend configuration;
2. the zero-path executable contract;
3. the five-input activation gate; and
4. the pilot-enrollment state type.

The verifier reruns the builder and requires exact JSON equality. A hand edit cannot
silently change a scope, ceiling, audit inventory, fixture outcome, source digest, or
non-authorization constraint.

## 4. Candidate and runtime separation

The candidate recognizes only two dispositions:

| Disposition                                  | Meaning                                                                                                               | Explicitly does not mean                                                                                                     |
| -------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `hold`                                       | The manifest is incomplete, malformed, outside its validity window, or inconsistent with a fail-closed pilot boundary | that a person or resource exists; that any one approval is missing; or that retrying will succeed                            |
| `eligible_for_external_go_no_go_review_only` | The synthetic structure contains every required record and may be presented to independent governance owners          | activation, enrollment, deployment, disclosure, source authorization, clinical release, distribution, or production approval |

The candidate has no runtime evaluator. The JavaScript evaluator exists only in
repository verification and is not imported by Laravel, iOS, or Android. No route,
controller, service provider, native client, database model, feature flag, environment
variable, or production query consumes either JSON artifact.

The following candidate constraints are all fixed to `false`:

- runtime implementation;
- route registration;
- native networking;
- identity-provider selection;
- source-adapter selection;
- production query;
- patient or representative creation;
- patient enrollment;
- patient disclosure;
- patient mutation;
- communication or notification;
- deployment; and
- pilot activation.

## 5. Manifest field semantics

### 5.1 Identity and revision

Every future manifest must have:

- a Nightingale-owned `manifest_id`;
- a positive, monotonically advancing `manifest_revision`;
- the exact candidate `policy_version`;
- an `approved_inactive` state before external review; and
- an explicit request for external go/no-go review.

No `active` manifest state exists in this candidate. Even a structurally complete record
must retain `runtime_activation_permitted=false`.

The committed template uses revision zero and `draft_inactive`. It is not a placeholder
that defaults into an active scope.

### 5.2 Facility and unit scope

Facility and unit values use opaque Nightingale handles:

- facility handles use the `ngf_` prefix; and
- unit handles use the `ngu_` prefix.

The candidate does not accept source-system identifiers, hospital names, unit names,
database keys, FHIR references, MRNs, or other embedded source meaning. At least one
facility and one unit are required for external review. Empty, duplicate, or malformed
lists hold.

The committed template contains no handles. The complete fixture uses synthetic handles
only and is not a proposed production scope.

### 5.3 Cohort configuration

The cohort block requires:

- one opaque `ngc_` cohort handle;
- separately versioned inclusion and exclusion policy release IDs;
- the same exclusion release in both the cohort and exclusion blocks;
- a positive maximum active-enrollment count no greater than 25;
- `enrollment_is_automatic=false`; and
- `unknown_or_unavailable_eligibility_result=withhold`.

The 25-person ceiling is a conservative technical maximum for this candidate, not a
recommended clinical cohort size. A real pilot may set a smaller number, and independent
governance owners must choose the actual number. Raising the technical ceiling requires a
reviewed candidate version, fixture changes, and exact-SHA ratification.

No algorithm is permitted to infer enrollment from presence in a unit, encounter status,
diagnosis, pathway, language, or use of the sample patient.

### 5.4 Language and interpreter configuration

The language block requires:

- at least one unique canonical locale tag;
- a released interpreter-coverage record;
- no unapproved locale fallback; and
- `withhold` for an unknown language.

The verifier performs a bounded canonical tag-shape check consistent with BCP 47-style
language, optional script, and optional region structure. That mechanical check is not a
translation approval, locale registry, language-access assessment, or interpreter-service
validation.

The complete fixture uses `en-US` and `es-US` only as synthetic examples. The committed
template approves no language. A future manifest cannot use the presence of English copy
in the offline shell as evidence that English, Spanish, or any other language is approved
for a pilot.

### 5.5 Exclusion behavior and non-disclosure

The exclusion block requires:

- a released exclusion-policy identifier that matches the cohort block;
- denial when a rule result is unknown;
- denial when a rule source is unavailable;
- no inference that a sensitive service exists; and
- no disclosure of the internal exclusion reason.

This foundation defines the fail-closed shape but no clinical exclusion rules. It does not
select diagnoses, services, ages, legal statuses, accommodations, behavioral-health
conditions, reproductive-health conditions, interpreter needs, or other sensitive facts.
Those decisions require named clinical, legal/HIM, privacy, patient-advisor, and
accessibility review.

### 5.6 Support hours

Support coverage requires:

- an IANA-style timezone identifier;
- at least one non-overlapping weekly local-time window;
- an approved urgent-help content release;
- a fixed uncovered-window disposition of
  `withhold_new_enrollment_and_show_released_support_guidance`; and
- no patient-visible inference about staffing availability.

Weekly windows use ISO weekday values 1 through 7 and exact `HH:MM` local start/end times.
Cross-midnight windows are intentionally not accepted in this candidate; they must be
split across weekdays to make review and overlap detection explicit.

The candidate validates window shape and overlap only. It does not implement daylight
saving transitions, holiday exceptions, emergency staffing, service-level targets,
contact routes, or support content. A future approved implementation must define and test
those behaviors before enrollment.

### 5.7 Validity and expiry

An inactive committed template may have null validity because it cannot proceed to
external review. Any candidate eligible for external review must have:

- exact UTC `starts_at` and `expires_at` timestamps;
- `starts_at < expires_at`;
- an evaluation time within `[starts_at, expires_at)`;
- a duration no greater than 168 hours;
- `renewal_requires_new_manifest=true`; and
- `expiry_fails_closed=true`.

The seven-day ceiling is a technical authorization lifetime, not a statement about patient
length of stay or pilot duration. A longer pilot would require a new manifest and new
external authorization before the current manifest expires. Silent extension, reuse, or
automatic renewal is prohibited.

An expired manifest holds. It does not disclose whether the prior scope, cohort, person,
or approval existed.

### 5.8 Prerequisite records

All eight prerequisite references must exist before external review:

1. institutional clinical approval;
2. patient-content release;
3. independent feature activation;
4. source-connector deployment;
5. identity/source approval;
6. durable audit-sink deployment;
7. rollback plan; and
8. kill-switch test.

A reference is only a linkage requirement. The candidate does not validate the substance,
signatures, currentness, scope, or authority of a future record. Those checks require an
independently reviewed implementation and governance repository.

### 5.9 Named approval roles

Seven distinct roles must each provide a named subject, approval record, and UTC approval
timestamp before the manifest validity begins:

1. product owner;
2. clinical-safety owner;
3. privacy/security owner;
4. patient-advisor/accessibility owner;
5. identity/source owner;
6. operations/support owner; and
7. release owner.

The committed template contains no names or approvals. The fixture values are explicitly
synthetic. This document neither appoints approvers nor records their decisions.

One person filling multiple future roles is not approved by this candidate. Independence,
delegation, conflicts, revocation, quorum, signature, and evidence-retention rules remain
governance decisions.

### 5.10 Audit requirements

The manifest requires an append-only sink and an event schema that are durable before an
effective change. Nine event types are mandatory:

1. manifest created;
2. review requested;
3. manifest approved;
4. go/no-go requested;
5. pilot started;
6. pilot expired;
7. pilot withdrawn;
8. kill switch invoked; and
9. rollback completed.

Every event must contain:

- manifest ID and revision;
- event type and UTC occurrence time;
- actor role and a hashed actor reference;
- outcome and a bounded reason code; and
- policy version.

Patient identifiers, clinical content, and message content are prohibited in this
candidate's audit events. Audit failure holds.

This is an audit contract candidate, not an audit implementation. There is no writer,
sink, retention schedule, key-management design, actor-hash construction, access policy,
alert, reconciliation job, or evidence export. Those must be reviewed before the audit
prerequisite can be considered real.

### 5.11 Rollback and kill switch

The kill switch defaults to `engaged`. External review requires:

- a rollback target release;
- a rollback verification record;
- a separately referenced kill-switch test; and
- prohibition of enrollment and disclosure after expiry.

The candidate does not implement a kill-switch service or deploy a rollback target. The
current offline foundation is the conceptual safe state, but a future release must identify
and verify its exact artifact rather than rely on that phrase.

## 6. Synthetic evaluation matrix

The fixture set contains 34 cases:

- one structurally complete synthetic case returns
  `eligible_for_external_go_no_go_review_only`;
- 33 cases return `hold`.

The hold cases cover:

- the committed empty template;
- draft state or no review request;
- attempted runtime activation;
- missing facility, unit, or cohort scope;
- missing or inconsistent inclusion/exclusion releases;
- zero or excessive cohort count;
- automatic enrollment;
- absent or malformed language scope;
- missing interpreter coverage or attempted fallback;
- non-denying unknown/outage exclusion results;
- missing timezone, support windows, or urgent-help release;
- overlapping support windows;
- future, expired, or overlong validity;
- missing audit deployment;
- mutable/incomplete/content-bearing audit policy;
- missing rollback evidence;
- a released default kill switch; and
- a missing named approver.

Fixtures contain no real patient, principal, encounter, staff, facility, unit, approval,
source, support, or release identifiers. The complete fixture is a structural test, not a
recommendation or authorization.

## 7. Negative verifier mutations

The `--self-test` mode exercises 25 independent artifact mutations, including attempts to:

- mark the candidate approved;
- enable pilot activation;
- populate the committed default scope, validity, or approval;
- alter source checksums;
- convert the limited positive disposition into activation;
- widen validity or cohort ceilings;
- remove approval/audit inventories;
- change case counts or expected outcomes;
- insert a patient identifier;
- enable runtime activation or automatic enrollment;
- permit audit clinical content;
- release the default kill switch;
- permit post-expiry disclosure;
- weaken uncovered-support behavior;
- expose exclusion reasoning; or
- allow unapproved language fallback.

Every mutation must fail. The verifier also checks the four source hashes, the zero-path
contract, every false executable activation fact, the code-owned negative backend
defaults, and the absence of production hosts and credentials.

## 8. Relationship to the existing activation gate

The existing five-state activation gate and this manifest solve different problems:

| Boundary                   | Question answered                                                                                                                            |
| -------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| Activation-separation gate | Are clinical approval, content release, feature activation, pilot enrollment, and source deployment represented as distinct positive states? |
| Controlled-pilot manifest  | Is the proposed pilot scope exact, bounded, supported, expiry-limited, approved by all required roles, auditable, and rollback-linked?       |

Neither boundary authorizes access. A future operation would still need identity,
current-encounter binding, relationship, operation scope, content release, freshness,
language, correction/retraction, audit-before-disclosure, serialization, and generic
non-disclosure evaluation.

The manifest does not change `pilot_enrollment_state` from `not_enrolled`, and it does not
make the all-positive activation row reachable.

## 9. Patient, production, and sample-record boundary

This work performed no database access. It neither reads nor uses the authorized
Nightingale sample principal or encounter.

The sample remains:

- pending and inactive;
- contactless and passwordless;
- without an identity link, grant, challenge, session, projection, or native caller; and
- unrelated to the synthetic cohort fixture.

No facility, unit, or cohort handle in the manifest can be resolved to a production row.
No migration, route, query, notification, feature flag, enrollment, content release,
deployment, or activation occurred.

## 10. Future implementation requirements

Before any runtime manifest service exists, a separately reviewed change must define:

- the signed/canonical serialization and immutable digest;
- authoritative facility/unit handle issuance and non-enumerability;
- cohort and exclusion policy contract semantics;
- language registry, translated content releases, interpreter-service status, and fallback;
- timezone, daylight-saving, exception-calendar, and support escalation behavior;
- named-approval authority, independence, signature, revocation, and expiry;
- durable audit storage, actor hashing, retention, access, alerting, and reconciliation;
- kill-switch propagation, bounded convergence time, rollback artifact identity, and proof;
- generic non-disclosure for every manifest lookup/evaluation error;
- race behavior for transfer, discharge, expiry, withdrawal, source outage, or approval
  revocation;
- idempotent enrollment and removal semantics;
- production-like failover, load, downtime, and rollback exercises; and
- native behavior on expiry, withdrawal, offline state, and unavailable support.

Those requirements must be backed by approved contracts and independent review. They must
not be inferred from this candidate or implemented by wiring the JSON directly into
Laravel.

## 11. Verification command

From the repository root:

```bash
node scripts/ci/verify-nightingale-controlled-pilot-manifest.mjs . --self-test
```

Expected result:

```text
Nightingale controlled-pilot manifest verified: 34 synthetic cases (33 hold, 1 external go/no-go review only), 4 checksum-bound sources, 25 negative self-tests.
```

The verifier is included in the Nightingale contract job and the native product-boundary
chain. Exact-SHA CI remains required for publication.

## 12. Remaining holds

This foundation does not approve or implement:

- a real facility, unit, cohort, locale, exclusion policy, or support schedule;
- identity proofing, recovery, representatives, consent, or sensitive-service policy;
- clinical content, Today, My Path, Care Team, education, discharge, or communication;
- a source connector, audit sink, manifest repository, evaluator, or enrollment service;
- human patient-advisor, accessibility, language, clinical, legal, privacy, or security
  review;
- a signed artifact, App Store/Play distribution, physical-device acceptance, or support
  operation;
- production-like integration, load, failover, outage, monitoring, or rollback exercises;
- a pilot go/no-go decision; or
- merge, deployment, migration, production mutation, or activation.

The next Stream F items remain open.
