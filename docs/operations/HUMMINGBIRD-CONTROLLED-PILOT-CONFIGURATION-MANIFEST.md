# Hummingbird Controlled-Pilot Configuration Manifest

**Status:** template only — not an authorization, not a runtime configuration, and not a release record
**Last reconciled:** 2026-07-25
**Applies to:** the proposed inpatient Hummingbird Patient controlled pilot only
**Companion plan:** [Hummingbird ASAP Pilot-Completion Plan](../plans/hummingbird-asap-pilot-completion-2026-07-24.md)
**Current effective disposition:** every patient-serving and patient-adjacent flag remains **off**; no pilot facility, unit, cohort, identity workflow, approved source release, or expiry has been authorized.

## 1. Safety contract

This is a fail-closed template for the Wave 1 configuration-manifest and
flag-matrix deliverable. It deliberately contains no patient identifiers,
unit identifiers, credentials, encryption material, source endpoint, staffing
assignment, or enabled value. Filling in a Markdown field, creating a synthetic
reference patient, merging code, or deploying a migration does **not** authorize
patient access.

No setting in this document may be changed outside a peer-reviewed, exact-SHA
test-environment release record. No setting may be enabled until all of the
following are attached to that record:

1. The signed facility/unit/cohort, language, urgent-help, message-topic, SLA,
   escalation, and kill-switch decision record.
2. The approved field-level disclosure matrix and prohibited-data list, including
   source, release authority, freshness limit, uncertainty language, correction
   behavior, and accountable owner for each patient field.
3. The approved patient identity, proofing, recovery, revocation, device, and
   staff-support workflow. A synthetic reference identity is not an alternative.
4. The named, versioned, clinically approved source adapter and release
   authority; test fixtures and draft projections are not release sources.
5. The approved responsibility pools, eligible responders, shifts, handoff
   process, response SLA, and outage escalation for every enabled message topic.
6. The recorded privacy, security, clinical-safety, accessibility, language
   access, patient-advisor, visual-asset licensing, and release approvals.
7. A named expiry date, rollback owner, change request, environment name, exact
   application SHA, and an independently observed off-state proof.

If any item is missing, conflicting, expired, or cannot be independently
verified, retain the default-off state and stop the release. Do not substitute a
production patient, production database mutation, or direct server change for a
non-production pilot rehearsal.

## 2. Configuration record to complete at authorization time

| Required field                       | Current value | Acceptance rule                                                                                                                                          |
| ------------------------------------ | ------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Change request / release record      | `UNASSIGNED`  | Immutable URL or identifier, peer-review approvals, and exact application SHA.                                                                           |
| Environment                          | `UNASSIGNED`  | A production-like **non-production** boundary with redacted data, isolated queues, scheduler, logs, observability, and independently controllable flags. |
| Facility and pilot units             | `UNASSIGNED`  | Signed decision; unit identifiers stored only in the approved release system, not this public-to-repo template.                                          |
| Cohort and exclusions                | `UNASSIGNED`  | Signed clinical/privacy decision and enrollment workflow.                                                                                                |
| Effective window / expiry            | `UNASSIGNED`  | Start and end timestamps, timezone, expiry owner, and automatic/manual rollback evidence. An absent expiry prohibits enablement.                         |
| Default disposition                  | `OFF`         | Each flag below independently proven off before any test-only candidate enablement.                                                                      |
| Emergency kill-switch owner          | `UNASSIGNED`  | Named 24/7 accountable role, acknowledgement channel, target restoration time, and tested rollback.                                                      |
| Source and patient release authority | `UNASSIGNED`  | Versioned source contract, field-by-field release rule, reviewer, and release authority.                                                                 |
| Identity/enrollment authority        | `UNASSIGNED`  | Identity proofing, recovery, revocation, session/device controls, and support escalation approved.                                                       |
| Messaging operations authority       | `UNASSIGNED`  | Topic pools, on-duty responders, handoff, response SLA, urgent guidance, and downtime procedure approved.                                                |
| Visual-asset authorization           | `HOLD`        | Complete [visual asset provenance approval](../hummingbird/PATIENT-VISUAL-ASSET-PROVENANCE.md); technical hashes alone do not authorize distribution.    |

## 3. Common flag-record requirements

For every row below, the release record must include the exact environment
variable name, prior and proposed value, actor, UTC timestamp, peer reviewer,
environment, release SHA, change request, expiry, and redacted command/output
proving the result. The required audit-record type is
`hummingbird.patient.pilot_flag_state_verified` with the variable name as its
subject. This is a required release-evidence record; this template does not
claim that the application currently emits it.

Every rollback is the same minimum sequence: set the named value to `false`,
rebuild/reload configuration through the approved release procedure, verify the
affected API/UI is unavailable to an unprivileged patient principal, preserve
existing audit records, and record the result. A master switch, dependent
switch, or policy gate may be rolled back independently; the most restrictive
state wins. No rollback deletes clinical, identity, communication, or audit
records.

All expiry cells are intentionally `UNASSIGNED`. The eventual expiry must be
the earliest of the signed pilot end, a safety/policy expiry, or the approved
release-window end. A missing, past, or disputed expiry means `false`.

Data classification labels are minimum classifications:

- **Restricted PHI** — patient-identifiable care, encounter, pathway, team, or
  communication information.
- **Restricted identity/security** — credentials, enrollment, sessions,
  revocation, provider-device tokens, or cryptographic key material.
- **Confidential clinical operations** — source lineage, release/review state,
  staffing, responsibility pools, or operational routing.

## 4. Patient-realm flag matrix

The code-owned defaults below are taken from
[`config/hummingbird-patient.php`](../../config/hummingbird-patient.php). The
listed owner is the accountable approval role, not evidence that an individual
has accepted the role.

| Environment variable                                        | Code default / current disposition | Accountable approval role                  | Minimum classification                   | Required evidence before a test-only candidate state                                                               | Expiry           |
| ----------------------------------------------------------- | ---------------------------------- | ------------------------------------------ | ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------ | ---------------- |
| `HUMMINGBIRD_PATIENT_ENABLED`                               | `false` / OFF                      | Product/pilot lead + Privacy/IAM           | Restricted PHI + identity/security       | All Section 1 prerequisites; master kill-switch test; independent off proof                                        | UNASSIGNED — OFF |
| `HUMMINGBIRD_PATIENT_ENROLLMENT_ENABLED`                    | `false` / OFF                      | Identity/IAM lead                          | Restricted identity/security             | Approved enrollment, proofing, recovery, revocation, rate-limit, and support workflow                              | UNASSIGNED — OFF |
| `HUMMINGBIRD_PATIENT_TOKEN_EXCHANGE_ENABLED`                | `false` / OFF                      | Identity/IAM lead                          | Restricted identity/security             | Approved token issuer/audience, HMAC/key custody, TTL, revocation, and replay controls                             | UNASSIGNED — OFF |
| `HUMMINGBIRD_PATIENT_PROFILE_ENABLED`                       | `false` / OFF                      | Privacy lead + patient product lead        | Restricted PHI                           | Approved field-level profile disclosure and correction behavior                                                    | UNASSIGNED — OFF |
| `HUMMINGBIRD_PATIENT_SESSION_MANAGEMENT_ENABLED`            | `false` / OFF                      | Identity/IAM lead                          | Restricted identity/security             | Device/session policy, revoke-all and single-device recovery tests                                                 | UNASSIGNED — OFF |
| `HUMMINGBIRD_PATIENT_NOTIFICATION_DEVICES_ENABLED`          | `false` / OFF                      | Security lead + mobile lead                | Restricted identity/security             | Provider-token encryption/key rotation review; no notification delivery implied                                    | UNASSIGNED — OFF |
| `HUMMINGBIRD_PATIENT_ENCOUNTERS_ENABLED`                    | `false` / OFF                      | Clinical content lead + privacy lead       | Restricted PHI                           | Approved encounter source, identity binding, freshness, correction, and revocation rules                           | UNASSIGNED — OFF |
| `HUMMINGBIRD_PATIENT_TODAY_ENABLED`                         | `false` / OFF                      | Clinical content lead                      | Restricted PHI                           | Approved Today field source/release/freshness/uncertainty rules and reviewer evidence                              | UNASSIGNED — OFF |
| `HUMMINGBIRD_PATIENT_PATHWAY_ENABLED`                       | `false` / OFF                      | Clinical pathway release lead              | Restricted PHI                           | Released, version-pinned patient projection; no draft or synthetic substitute                                      | UNASSIGNED — OFF |
| `HUMMINGBIRD_PATIENT_PATHWAY_HISTORY_DRAFTS_ENABLED`        | `false` / OFF                      | Integration lead + clinical content lead   | Confidential clinical operations         | Approved draft-producer boundary and non-patient-visible reconciliation evidence                                   | UNASSIGNED — OFF |
| `HUMMINGBIRD_PATIENT_PATHWAY_SOURCE_RECONCILIATION_ENABLED` | `false` / OFF                      | Integration lead                           | Confidential clinical operations         | Reviewed connector allowlist in deployment code, source lineage, and rejection evidence                            | UNASSIGNED — OFF |
| `HUMMINGBIRD_PATIENT_PATHWAY_HISTORY_RELEASES_ENABLED`      | `false` / OFF                      | Clinical pathway release lead              | Restricted PHI                           | Named clinical reviewer and release authority; release/retraction and correction rehearsal                         | UNASSIGNED — OFF |
| `HUMMINGBIRD_PATIENT_TEACH_BACK_ENABLED`                    | `false` / OFF                      | Clinical education lead + nursing ops      | Restricted PHI                           | Approved released-education-only flow and language clarifying it is not comprehension/consent                      | UNASSIGNED — OFF |
| `HUMMINGBIRD_PATIENT_CARE_PREFERENCES_ENABLED`              | `false` / OFF                      | Nursing operations lead + privacy lead     | Restricted PHI                           | Approved non-clinical preference wording, routing, reviewer, and correction/escalation behavior                    | UNASSIGNED — OFF |
| `HUMMINGBIRD_PATIENT_GOALS_ENABLED`                         | `false` / OFF                      | Nursing operations lead + privacy lead     | Restricted PHI                           | Approved non-clinical personal-goal wording, routing, reviewer, and correction/escalation behavior                 | UNASSIGNED — OFF |
| `HUMMINGBIRD_PATIENT_ROUNDS_QUESTIONS_ENABLED`              | `false` / OFF                      | Virtual rounds clinical lead + nursing ops | Restricted PHI                           | Approved non-urgent wording, topic pool, handoff, SLA, and bridge decision                                         | UNASSIGNED — OFF |
| `HUMMINGBIRD_PATIENT_ROUNDS_SUMMARY_ENABLED`                | `false` / OFF                      | Virtual rounds clinical lead               | Restricted PHI                           | Plain-language summary source, clinical release/retraction, and uncertainty review                                 | UNASSIGNED — OFF |
| `HUMMINGBIRD_PATIENT_CARE_TEAM_ENABLED`                     | `false` / OFF                      | Nursing operations lead + privacy lead     | Restricted PHI                           | Approved care-team roster source, relationship rules, coverage/freshness, and correction path                      | UNASSIGNED — OFF |
| `HUMMINGBIRD_PATIENT_MESSAGING_ENABLED`                     | `false` / OFF                      | Nursing operations lead + privacy lead     | Restricted PHI                           | Approved messaging policy, urgent guidance, topics, response window, encryption, and failure-state copy            | UNASSIGNED — OFF |
| `HUMMINGBIRD_PATIENT_REFERENCE_PROVISIONING_ENABLED`        | `false` / OFF                      | Engineering test-environment owner         | Restricted identity/security             | Synthetic-only, non-production boundary; preview/dry-run proof; no production activation                           | UNASSIGNED — OFF |
| `HUMMINGBIRD_PATIENT_STAFF_MESSAGING_ENABLED`               | `false` / OFF                      | Nursing operations lead + support lead     | Restricted PHI + confidential operations | Every pilot unit has eligible topic responders, healthy consumer, handoff/reconciliation, SLA, and tabletop result | UNASSIGNED — OFF |

### 4.1 Non-boolean patient gates

These are not feature switches and must never appear in source control, logs, or
this manifest with their real value. Their absence or unapproved state fails
closed. Record only a secret-manager reference/version and an approver in the
restricted release record.

| Setting                                                | Safe current disposition  | Accountable role           | Required release evidence                                                                                                           |
| ------------------------------------------------------ | ------------------------- | -------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| `HUMMINGBIRD_PATIENT_HMAC_SECRET`                      | No value recorded here    | Security + IAM             | Secret-manager reference, rotation/incident procedure, separation from `APP_KEY`, and access review.                                |
| `HUMMINGBIRD_PATIENT_POLICY_VERSION`                   | Draft policy version only | Privacy + clinical content | Signed disclosure-policy version; no arbitrary environment value may change field release rules.                                    |
| `HUMMINGBIRD_PATIENT_MESSAGING_POLICY_STATUS`          | `draft_requires_approval` | Privacy + nursing ops      | Signed policy, urgent-guidance copy/version, response window, topic set, encryption key reference, and all patient-visible strings. |
| `HUMMINGBIRD_PATIENT_STAFF_MESSAGING_POLICY_STATUS`    | `draft_requires_approval` | Nursing ops + support lead | Approved pilot units, responsibility pools, on-duty staffing, consumer health, and downtime escalation.                             |
| Messaging, notification, and reference encryption keys | No value recorded here    | Security lead              | Separate secret-manager references, current/previous key version, rotation drill, and redacted key-access audit.                    |
| `HUMMINGBIRD_PATIENT_STAFF_MESSAGING_PILOT_UNIT_IDS`   | Empty                     | Nursing operations lead    | Signed two-unit scope; identifiers exist only in approved restricted configuration.                                                 |

## 5. Related-system controls

These related controls are outside the patient realm but can expand data,
workflow, or routing. They remain off unless an independently approved release
record names their scope. Enabling a related staff feature never enables the
patient app.

| Environment variable(s)                                                                                          | Code default / current disposition | Accountable approval role                      | Minimum classification           | Required evidence before any change                                                                                    | Expiry           |
| ---------------------------------------------------------------------------------------------------------------- | ---------------------------------- | ---------------------------------------------- | -------------------------------- | ---------------------------------------------------------------------------------------------------------------------- | ---------------- |
| `CARE_PATHWAYS_GOVERNANCE_ENABLED`, `CARE_PATHWAYS_CATALOG_ENABLED`                                              | `false` / OFF                      | Clinical pathway governance lead               | Confidential clinical operations | Separate staff-governance authorization; inactive catalog is not clinical approval                                     | UNASSIGNED — OFF |
| `CARE_PATHWAYS_ASSIGNMENT_ENABLED`, `CARE_PATHWAYS_ROUNDS_ENABLED`, `CARE_PATHWAYS_STAFF_MOBILE_ENABLED`         | `false` / OFF                      | Clinical pathway governance lead + nursing ops | Confidential clinical operations | Separate staff workflow, source/release, and rollback authorization                                                    | UNASSIGNED — OFF |
| `CARE_PATHWAYS_PATIENT_ENABLED`                                                                                  | `false` / OFF                      | Clinical pathway release lead + privacy lead   | Restricted PHI                   | Approved patient release source and field disclosure; must align with the patient-realm pathway gates                  | UNASSIGNED — OFF |
| `CARE_PATHWAYS_EDDY_REFERENCE_ENABLED`, `CARE_PATHWAYS_EDDY_INSTANCE_ENABLED`, `CARE_PATHWAYS_WRITEBACK_ENABLED` | `false` / OFF                      | Clinical pathway governance lead               | Confidential clinical operations | Separate clinical-safety review; no inferred pathway or writeback becomes patient content                              | UNASSIGNED — OFF |
| `CARE_PATHWAYS_DEMO_ENABLED`                                                                                     | `false` / OFF                      | Demo/test-environment owner                    | Confidential clinical operations | Synthetic, non-production-only demo authorization; no patient exposure                                                 | UNASSIGNED — OFF |
| `VIRTUAL_ROUNDS_PATIENT_QUESTION_BRIDGE_ENABLED`                                                                 | `false` / OFF                      | Virtual rounds clinical lead + nursing ops     | Restricted PHI                   | Approved rounds-question topic, staff bridge workflow, recipient pool, SLA, outage behavior, and retraction boundaries | UNASSIGNED — OFF |

For any row in this section, use the common audit-record type and common
rollback procedure in Section 3. A related-system change also requires the
corresponding system owner’s release evidence; this document is not a blanket
approval for Virtual Rounds or Care Pathways.

## 6. Test-environment release checklist

This checklist may be executed only in the approved production-like,
non-production boundary. It is deliberately not a production deployment guide.
Every check remains a release blocker until evidence is attached to the named
change request.

- [ ] Verify the environment is non-production, contains no production patient
      identities, and has an isolated database, queues, scheduler, log sink,
      outbound notification provider, and observability scope.
- [ ] Verify every matrix variable is explicitly false/empty as applicable and
      record the redacted, independent off-state result for each one.
- [ ] Verify `HUMMINGBIRD_PATIENT_HMAC_SECRET`, key material, and unit IDs are
      absent from repository files, shell history, CI output, logs, and this
      manifest; use approved secret references only.
- [ ] Attach the signed decisions, disclosure matrix, prohibited-data list,
      source contract, identity workflow, policy versions, owners, and expiry.
- [ ] Attach privacy/security threat-model and clinical hazard-log workshop
      dates, attendees, unresolved risks, mitigations, and acceptance owners.
- [ ] Run the isolated patient backend suite:
      `php artisan test tests/Feature/Patient --stop-on-failure`.
- [ ] Run the patient boundary/contract/disclosure/accessibility verifiers:
      `bash scripts/ci/verify-hummingbird-patient-boundary.sh source hummingbird/androidPatientApp`,
      `php scripts/verify-hummingbird-patient-contract.php`,
      `php scripts/verify-hummingbird-patient-disclosure-matrix.php`, and
      `php scripts/verify-hummingbird-patient-accessibility-matrix.php`.
- [ ] Run the current native regression suites on the recorded iOS Simulator and
      Android emulator; attach result bundles, device/OS identifiers, and exact
      build SHA. Automated evidence does not replace the required human
      accessibility, language-access, and patient-advisor validation.
- [ ] Prove each proposed enablement independently with its master switch,
      dependent switch, source/release gate, and policy gate off; prove that the
      most restrictive state denies patient access.
- [ ] Tabletop source outage, stale/retracted projection, wrong/unknown
      relationship, unit transfer, revoked session, message handoff failure,
      expired flag, and emergency kill-switch rollback. Record outcomes and
      unresolved risks.
- [ ] Obtain independent release review. Production eligibility, if ever
      approved, follows the protected-main exact-CI release procedure; it is not
      implied by a successful non-production rehearsal.

## 7. Current disposition and next action

As of 2026-07-25, this template is complete as a configuration-control
instrument, but **none** of its authorization-time fields has been supplied and
no flag is eligible to change. The next action is a 60-minute decision meeting
led by the product/pilot lead with clinical content, privacy/security/IAM,
integration, nursing operations, and release owners. It must either fill the
Section 2 record from signed evidence or record the missing decision as a dated
blocker. Engineering must not infer the values.
