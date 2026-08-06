# Nightingale investor-demo cohort planning evidence

**Observed:** 2026-07-27

**Database access:** PostgreSQL TLS session, repeatable-read read-only transaction

**Mutation:** none

**Patient data retained:** none

## Purpose

This evidence records the source-backed catalog facts used to design five synthetic
Nightingale investor-demo patients. It is not clinical approval, catalog activation,
patient-content release, account provisioning, pilot authorization, or deployment.

## Aggregate catalog observations

The read-only transaction observed:

| Fact                                      | Value |
| ----------------------------------------- | ----: |
| Catalog releases                          |     1 |
| Pathway versions                          |   250 |
| Stage definitions                         | 1,250 |
| Milestone definitions                     | 9,601 |
| Source-candidate staff-reference sections | 7,000 |
| Ready for institutional clinician signoff |    96 |
| Specialist review with limitations        |   148 |
| Needs pathway redesign                    |     6 |
| Clinically signed-off releases            |     0 |
| Active releases                           |     0 |

The sole release was `inactive`, institutionally `not_reviewed`, and not clinically
signed off. Every observed stage and milestone definition was `draft`. No canonical
catalog row was changed.

The 2026-08-05 pre-provisioning reconciliation confirmed the production release UUID is
`019f8702-a824-7172-b1dc-ab3612d2f1e8`. That UUID is environment-generated provenance,
not the portable content identity. The exact cross-environment identity is the dataset
key plus source CSV, verification workbook, and declared-baseline SHA-256 fingerprints;
the provisioner separately rechecks grouper, all aggregate control counts, inactive
state, and zero clinical signoff before accepting the row. The production evidence values
are recorded in the paired implementation plan.

## Exact selected scenario facts

| Demo handle | Pathway key                                                          | MS-DRG | Exact codebook title                                                                | Stages | Milestones |
| ----------- | -------------------------------------------------------------------- | ------ | ----------------------------------------------------------------------------------- | -----: | ---------: |
| `demo1`     | `drgcp-heart-failure-671d63b4d61b`                                   | `293`  | Heart Failure and Shock without CC/MCC                                              |      5 |         41 |
| `demo2`     | `drgcp-simple-pneumonia-pleurisy-337d0f29a350`                       | `195`  | Simple Pneumonia and Pleurisy without CC/MCC                                        |      5 |         44 |
| `demo3`     | `drgcp-major-joint-replacement-hipknee-lower-extremity-a7fc97e65adc` | `470`  | Major Hip and Knee Joint Replacement or Reattachment of Lower Extremity without MCC |      5 |         49 |
| `demo4`     | `drgcp-appendectomy-5b2df7e00bf7`                                    | `399`  | Appendix Procedures without CC/MCC                                                  |      5 |         36 |
| `demo5`     | `drgcp-vaginal-delivery-2fd506169d41`                                | `807`  | Vaginal Delivery without Sterilization or D&C without CC/MCC                        |      5 |         44 |

All five versions were marked ready for institutional clinician signoff while retaining
the explicit status that institutional SME signoff is required. The counts above are
reconciliation guards for the future command; they do not authorize copying draft
definitions into patient responses.

## Safety disposition

- Use the exact code/title/pathway bindings as catalog lineage only.
- Generate separate patient-safe synthetic demo projections.
- Display `DEMO — NOT FOR CLINICAL USE` persistently.
- Do not expose raw staff-reference sections or unapproved clinical prose.
- Do not modify or activate the canonical catalog.
- Supply the user-provided password only as a runtime secret; do not retain it in evidence.
- Implement the demo login only in the canonical clients at
  `hummingbird/iosPatientApp` and `hummingbird/androidPatientApp`; do not restore
  either superseded `nightingale/*App` scaffold.
- Treat merged PR #111 as the owner of the salvaged foundation, privacy, governance, and
  identity-uniqueness artifacts; do not duplicate them in the cohort stream.
- Before any write, require tested preview/apply/verify/suspend behavior, atomic
  five-member provisioning, isolation checks, rollback, exact-SHA CI, and governed
  deployment.

## Existing synthetic reference-sample observation

Read-only production inspection also found one inactive-credential Nightingale reference
patient derived from the former Hummingbird Patient template:

- one active, non-discharged, non-deleted encounter in unit 85 with patient reference
  `demo-nightingale-reference-inpatient` and owner
  `nightingale-reference-patient-provisioner-v1`;
- one pending, inactive synthetic Nightingale principal with no email, phone, password,
  verification, authentication, lock, or closure state;
- source-template product `hummingbird_patient`, source-template owner
  `hummingbird-patient-reference-identity-provisioner-v1`, and mode
  `operator-authorized-production-sample-clone`; and
- zero identity links, access grants, enrollment challenges, sessions, access-audit
  events, notification devices, notification outbox rows, or access tokens.

The implementation adopts this exact sample in place as `demo1`; it does not create a
replacement sample. It preserves the principal/encounter identifiers and source lineage,
and fails before cohort writes if the reference encounter, principal, or any dependent
state has changed. These observations are synthetic lineage evidence, not patient data or
authorization to provision.

## Local implementation-verification checkpoint

- Backend five-account isolation: all five aliases authenticate against the existing
  patient token route, each sees all six own projections, and all 20 directed
  cross-account pathway substitutions return the same content-free 404.
- Backend patient regression: 208 Patient feature tests completed with no failures and
  3,569 assertions after rebasing directly onto the protected-main PR #111 merge; the
  warnings are the repository's existing missing-local-`.env` warning, not failed
  assertions.
- Reference adoption: creation/replay/suspend/re-apply pass; changed encounter lifecycle,
  contact data, or an existing identity dependency fails before cohort writes.
- iOS: 67 unit tests, nine canonical UI journeys, the Release build, the patient
  artifact boundary, and the Release transport check passed from
  `hummingbird/iosPatientApp`.
- Android: debug and release JVM test matrices and all 16 connected instrumentation
  tests passed from `hummingbird/androidPatientApp` on an API 35
  1080×2400/420 dpi emulator. The run also exposed and verified the fix for shared
  list-scroll state carrying across patient tabs.
- App identity: the merged PR #111 uniqueness guard reports seven unique iOS identifiers
  and two unique Android identifiers in the reconciled checkout.

PR #118 passed all 19 required checks, merged through protected `main` as
`06e3d0b363f2ef553f857aa426f220ccff7fc8a4`, and is present in the canonically deployed
`main` release `a38044ca9f0df564b7de08cf915bdcc189a4d860`. The first production cohort preview
failed closed before any account write because the implementation had pinned a generated
fixture release UUID. No cohort principal, credential, grant, encounter, projection,
session, or token was created. A semantic catalog-identity correction is now the only
code gate before the production preview/apply/verify/suspend-and-reapply sequence.
