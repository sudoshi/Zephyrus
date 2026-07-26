# Nightingale patient-state vocabulary classification

**Status:** Source-level migration analysis only. No state code, label, locale, schema,
projection, client behavior, or patient-facing copy is approved for Nightingale.

**Decision date:** 2026-07-26

**Related records:**

- [Contract ownership and authorization matrix](./CONTRACT-OWNERSHIP-AND-AUTHORIZATION-MATRIX-2026-07-26.md)
- [Migration classification](./MIGRATION-CLASSIFICATION-2026-07-26.md)
- [Empty/default-off contract foundation](./api-contract/nightingale-foundation.v0.json)

## 1. Outcome

The Hummingbird Patient state vocabulary is useful evidence, but it is not a coherent,
portable Nightingale vocabulary. The reviewed sources disagree about domain coverage,
schema placement, decoding behavior, and what an absent vocabulary version means.
Nightingale therefore adopts only the following design principles at this stage:

1. wire codes and patient-facing labels are different governed artifacts;
2. internal codes must never be mechanically title-cased or shown to a patient;
3. every patient-visible code requires an exact domain, locale, version, and reviewed label;
4. an absent, unknown, malformed, or incompatible version fails closed for the affected
   projection;
5. unknown codes and unrecognized enum values withhold the affected field or projection
   according to an approved field-safety matrix; and
6. backend, contract fixtures, iOS, and Android must be generated from or mechanically
   reconciled to one Nightingale registry before the first patient projection is enabled.

No vocabulary implementation is added to either Nightingale native app in this slice.

## 2. Evidence snapshot

The review used the source tree after merging `origin/main` commit `84b5f830`.

| Source                                                                                                            | SHA-256                                                            | Observed role                                                            |
| ----------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------ | ------------------------------------------------------------------------ |
| `config/hummingbird-patient-content.php`                                                                          | `a4f23f9efba3e8364953cfa6bc2e45183ae8f054fdd49b012f684cd86e80aefa` | Backend English registry: 12 domains, 49 code-label pairs, draft version |
| `app/Services/Patient/Projection/PatientProjectionStateVocabulary.php`                                            | `6c0d8fc2b5e268612ff043c192c9d0ded06cb211af9a2e559c7a25f2db953f01` | Backend domain/code/label validation                                     |
| `docs/hummingbird/api-contract/hummingbird-patient.v1.yaml`                                                       | `fb6220b4ef8eb106223624a9785256fdc1603995f281f430a79897905cb45a1b` | Legacy API schema and response metadata                                  |
| `hummingbird/iosPatientApp/HummingbirdPatient/Models/PatientStateVocabulary.swift`                                | `e13015b0f09f58f845d64d202839d16be1ea36744b35445d95e6ef6904da231a` | iOS registry: 8 domains, 37 code-label pairs                             |
| `hummingbird/iosPatientApp/HummingbirdPatient/Networking/PatientAPIModels.swift`                                  | `93c3250b7292c9abe17e3bb932e39c489332c8becc321663b4b8365a1e468759` | iOS response/version models plus a separate event-category enum          |
| `hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/data/PatientStateVocabulary.kt` | `023157f23c8aa372f6ef10e1d07086603cb507d446e9a18798cb3fcbf953660e` | Android registry: 9 domains, 41 code-label pairs                         |

These hashes establish the reviewed inputs; they do not certify the legacy behavior.

## 3. Domain-by-domain reconciliation

| Backend domain              | Codes | iOS reference                                                                                    | Android reference                                          | Classification and Nightingale decision                                                                                          |
| --------------------------- | ----: | ------------------------------------------------------------------------------------------------ | ---------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| `schedule_status`           |     7 | Registry as `.schedule`                                                                          | Registry as `SCHEDULE`                                     | Candidate code set and labels; held for clinical/content/language review                                                         |
| `stage_status`              |     5 | Registry as `.pathway`                                                                           | Registry as `PATHWAY`                                      | Candidate; native naming is not the wire-domain name and must not become the canonical registry key                              |
| `milestone_status`          |     5 | Registry as `.milestone`                                                                         | Registry as `MILESTONE`                                    | Candidate; currently duplicates stage labels but remains a distinct semantic domain                                              |
| `pathway_event_status`      |     5 | Registry as `.pathwayEvent`                                                                      | Registry as `PATHWAY_EVENT`                                | Candidate; held with the pathway-event schema discrepancy below                                                                  |
| `pathway_event_category`    |     4 | Separate `PatientPathwayEventCategory` enum and `patientLabel`, outside `PatientStateVocabulary` | Registry as `PATHWAY_EVENT_CATEGORY`                       | **Parity defect.** Same English labels exist, but ownership/version enforcement differs                                          |
| `rounds_topic_status`       |     3 | Registry as `.roundsTopic`                                                                       | Registry as `ROUNDS_TOPIC`                                 | Candidate; patient-language and correction semantics unapproved                                                                  |
| `discharge_criteria_status` |     3 | Registry as `.dischargeCriterion`                                                                | Registry as `DISCHARGE_CRITERION`                          | High-scrutiny candidate; “Needs attention” cannot imply a diagnosis, urgency, or discharge decision                              |
| `goal_status`               |     6 | Registry as `.goal`                                                                              | Registry as `GOAL`                                         | Candidate; patient/proxy/team authorship and cancellation semantics require review                                               |
| `goal_author`               |     3 | Separate string switch in `PatientPathView`                                                      | Separate string switch in `PatientSessionCoordinator`      | **Parity/governance defect.** Labels are duplicated outside both versioned registries                                            |
| `timing_confidence`         |     3 | Registry as `.timingConfidence`                                                                  | Registry as `TIMING_CONFIDENCE`                            | Candidate; must be interpreted with timestamp, freshness, and “can change” rules                                                 |
| `location_status`           |     3 | Decoded as an unchecked `String`, but not rendered as a status                                   | Today decoder/model omits `care_location`                  | **Functional gap.** Backend-valid states have no cross-platform rendering contract                                               |
| `contact_route`             |     2 | Multiple separate string switches with different sentences                                       | Multiple separate string switches with different sentences | **Parity/safety defect.** Code-to-action copy is duplicated and context-dependent; urgent guidance requires operational approval |

The backend registry contains 49 code-label pairs. The iOS versioned registry contains 37;
its separate event-category enum brings its implemented label count to 41 but does not
bring that domain under the advertised registry/version. Android's versioned registry
contains 41. Neither client keeps `goal_author`, `location_status`, or `contact_route`
inside its versioned registry.

## 4. Schema and decoder discrepancies

### 4.1 Category is attached to different objects

The legacy OpenAPI contract defines optional `category` on `PatientScheduleItem`, but the
backend content guard does not allow a schedule category and neither native schedule model
decodes it. Conversely, the backend content guard and both native clients support optional
`category` on a pathway event, while the legacy OpenAPI `PatientPathwayEvent` schema does
not define that property.

This is not an additive-detail issue. It means the schema cannot currently prove the shape
that backend and clients exchange. Nightingale must not copy either placement until an
approved projection-field matrix resolves whether category belongs on schedule items,
pathway events, both as separate concepts, or neither.

### 4.2 Android omits two Today subdocuments

The legacy contract and iOS model include `care_location` and `discharge_outlook` in Today.
The Android `PatientTodayContent` model/decoder omits both. As a result:

- `location_status` has no Android Today representation;
- Today-level discharge timing/readiness context is not cross-platform equivalent; and
- a successful Android decode can silently discard contract-declared content.

Nightingale requires fixture-level field-presence reconciliation, not merely successful
JSON parsing.

### 4.3 Unknown event categories fail differently

iOS decodes pathway-event category into a closed `Codable` enum. An unknown non-null value
causes decoding of the containing response to fail. Android decodes the category as a
nullable string and maps an unknown value to the generic label “Status being confirmed.”
Those are materially different failure scopes. Nightingale needs an explicit per-field
rule—reject envelope, withhold projection, omit field, or show reviewed neutral copy—with
the same result on backend, iOS, and Android.

### 4.4 Version absence is treated as compatible

Both legacy native registries return compatible when `state_vocabulary_version` is absent,
as well as when it exactly matches `patient-state-vocabulary.v1-draft`. That preserves
older-server behavior but does not prove which labels governed the response. Nightingale
will require an explicit supported version for any response containing governed codes.
Absence is incompatible, not an implicit legacy version.

### 4.5 Backend validation does not bind the advertised version

The backend class reads the default locale's definitions and validates domain/code/label
syntax, but it does not itself validate or retain the registry `version`. Version metadata
is emitted separately by `PatientResponseMetadata`. Nightingale needs one immutable
registry identity that binds version, locales, domains, codes, labels, fixture checksum,
and response metadata.

## 5. Candidate properties worth reimplementing

These are candidate safety properties, not approved source code:

- stable lower-snake-case wire codes;
- explicit contextual labels instead of code-derived display text;
- backend rejection of unknown domains and codes before release;
- neutral handling that never exposes an internal code;
- response-level vocabulary version metadata;
- client withholding on an explicitly incompatible version; and
- tests that enumerate every domain/code/label tuple.

The legacy exception strings, Hummingbird configuration key, draft version, English copy,
domain aliases, separate switch statements, and `nil`-means-compatible behavior are
rejected as Nightingale implementation inputs.

## 6. Required Nightingale vocabulary artifact

Before a vocabulary implementation enters Nightingale, one reviewed artifact must define:

| Required field         | Required rule                                                                                   |
| ---------------------- | ----------------------------------------------------------------------------------------------- |
| Registry identity      | Nightingale-owned name, immutable semantic version, content checksum, effective state           |
| Domain                 | Exact wire-domain name and clinical/product meaning; no native-only alias as authority          |
| Code                   | Stable wire value, definition, source, deprecated/replacement state                             |
| Patient label          | Locale, reading level, context, screen-reader rendering, prohibited interpretations             |
| Unknown handling       | Exact field/projection failure scope and approved neutral experience                            |
| Provenance             | Clinical/content owner, source authority, review date, evidence link                            |
| Timing                 | Relationship to source-observed time, generated time, freshness, uncertainty, and changeability |
| Relationship           | Whether self and each representative class may receive the field                                |
| Translation            | Human translation workflow, locale fallback, withdrawal and correction behavior                 |
| Compatibility          | Supported server/client ranges, additive/breaking rules, forced withdrawal behavior             |
| Cross-platform fixture | Canonical input and exact backend/iOS/Android output or withholding result                      |

Code generation may be used only after the reviewed registry is authoritative. Generated
backend validators and native lookup tables must contain a pinned registry version/checksum
and pass a cross-platform reconciliation check in CI.

## 7. First-operation gates affected by this finding

The empty Nightingale contract must remain empty until at least:

- the category placement discrepancy is resolved in an approved field matrix;
- Today field coverage is made intentional and equivalent across platforms;
- `goal_author`, `location_status`, and `contact_route` ownership is centralized;
- absent-version and unknown-code failure scopes are approved;
- the first read operation has canonical positive, unknown-code, unknown-version,
  missing-version, stale, corrected, retracted, and unauthorized fixtures;
- patient/advisor, clinical-content, accessibility, language/interpreter, privacy, and
  operational owners approve applicable labels and fallbacks; and
- CI proves exact registry/fixture parity across backend, iOS, and Android.

No production database, deployed response, patient record, session, grant, projection,
feature flag, route, client, or network access was used for this classification.
