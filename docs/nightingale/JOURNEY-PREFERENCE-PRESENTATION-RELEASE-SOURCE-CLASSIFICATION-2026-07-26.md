# Nightingale journey, preference, presentation, synthetic, and release source classification

**Decision date:** 2026-07-26

**Reviewed source commit:** `45ddf907c0f15e378b37a1b0726724e346cb29fd`

**Last source revalidation:** 2026-07-27

**Status:** Complete source classification; evidence only; no implementation, route,
network client, source adapter, preference persistence, patient mutation, pathway release,
synthetic runtime, migration, production query, patient creation, deployment, or pilot
activation is approved.

**Machine-readable ledger:**
[`migration/candidates/v0/journey-preference-presentation-release-source-classification.json`](./migration/candidates/v0/journey-preference-presentation-release-source-classification.json)

**Verifier:**
`scripts/ci/verify-nightingale-journey-preference-release-classification.mjs`

## 1. Decision

The legacy Hummingbird Patient source-classification phase is now closed at repository
scope. The tracked product universe contains exactly **256 sources**:

- **122 unique sources** were already covered by the identity/first-read and
  communication/notification ledgers;
- this final slice classifies the remaining **134 sources**; and
- the union covers all **256 of 256** sources in the defined universe.

This is classification completeness, not functional parity, implementation completeness,
clinical approval, release readiness, or permission to copy the legacy patient app.

The final 134 sources resolve to exactly one required migration class each:

| Required class            | Sources | Result                                                                                    |
| ------------------------- | ------: | ----------------------------------------------------------------------------------------- |
| Reusable safety primitive |      42 | Retain the control objective; independently reimplement only after applicable approval.   |
| Reusable product behavior |      20 | Hold the patient need/behavior pending contract, content, authorization, and UX approval. |
| Test/fixture only         |      28 | Retain as synthetic test or provenance evidence; never ship or execute in production.     |
| Rejected legacy behavior  |      44 | Do not migrate legacy identity, packaging, assets, activation, or provisioning behavior.  |
| **Total**                 | **134** | Every final-slice source has one exact class, decision, SHA-256, surface, and domain set. |

No source is classified as “copy as-is.” Even a reusable safety primitive transfers only
as a requirement and testable property, never as implicit approval of legacy code, schema,
copy, identifier, endpoint, or operating policy.

## 2. Bounded universe and reproducibility

### 2.1 Included source roots

The product-universe rule is code-owned in the builder and verifier. It includes tracked
files from:

- `hummingbird/iosPatientApp/**`;
- `hummingbird/androidPatientApp/**`;
- `config/hummingbird-patient.php` and
  `config/hummingbird-patient-content.php`;
- `routes/patient.php`;
- patient API controllers and requests;
- patient models, policies, contracts, and services;
- patient feature and unit tests;
- migrations whose filename contains `patient`, except the unrelated
  `patient_flow` subsystem; and
- Hummingbird console commands whose class filename contains `Patient`.

This scope deliberately follows product ownership, not filename proximity alone. It
includes native packaging and binary assets because bundle/package identity, test hooks,
release source sets, launcher resources, backup policy, and network policy are functional
release inputs.

### 2.2 Explicit exclusions

The following are not silently ignored:

| Exclusion                                               | Reason                                                                                                                    |
| ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `database/migrations/*patient_flow*.php`                | These migrations belong to the staff/operational Patient Flow subsystem, not the Hummingbird Patient product.             |
| General/staff mobile, web, and operational source roots | Outside this product universe. Staff communication sources needed for end-to-end handoff were explicitly covered earlier. |
| Generated build output and local emulator/tool caches   | Untracked products of verification, not reviewable source.                                                                |
| Nightingale application source                          | Destination implementation, governed by its own boundary tests; it is not legacy migration input.                         |
| Production data or deployed configuration               | Neither is required to classify tracked source and neither was accessed.                                                  |

### 2.3 Inventory identity

| Inventory                         | Count | SHA-256 path-list digest                                           |
| --------------------------------- | ----: | ------------------------------------------------------------------ |
| Full legacy patient-product scope |   256 | `a307e1957df7ef78eb61a9a9123f3902fd8929ebb3aaeb4dce48f2c88fb4a881` |
| Final unclassified slice          |   134 | `4301de88a9071214001c2a58aa8fbc624bb49f24bb566428c8a1ef98aa44c13d` |

The digest is calculated over lexicographically sorted repository-relative paths with a
final newline. Every final-slice entry also carries the SHA-256 of the exact file bytes at
the reviewed commit.

The verifier fails if:

- a tracked product source is added or removed;
- a source is no longer covered by one of the three ledgers;
- the final slice overlaps a prior ledger;
- a path, byte checksum, surface, class, disposition, category, or decision changes;
- any decision rationale becomes non-substantive;
- any runtime/production permission becomes true; or
- a required finding is softened.

### 2.4 Legacy release-path collision revalidation

Current `main` introduced a release-path collision after the original classification: it
assigned `net.acumenus.nightingale` and the Nightingale display name to the historical
`hummingbird/iosPatientApp` target and placed a Nightingale export profile inside that
legacy root. That would have allowed two source trees to claim the same patient-product
identity and contradicted the independently governed Nightingale application boundary.

Correction commit `85316fbc5794735a77a0a4fa0e0096e18db4240b` restores the legacy project,
generated project, and Info.plist to their exact pre-collision Hummingbird Patient
identities; removes its Nightingale export profile; points the Apple registry to
`nightingale/iosApp`, project `Nightingale.xcodeproj`, and scheme `Nightingale`; and keeps
the export profile under that independent root. Product-boundary CI now rejects any
registry reversal, legacy Nightingale identifier, misplaced export profile, malformed
export policy, or exported IPA bundle/build mismatch.

Follow-up test correction commit `45ddf907c0f15e378b37a1b0726724e346cb29fd`
removes a competing manual Activity recreation from the Android pseudolocale harness and
waits for the platform-managed locale relaunch to become resumed. The complete API 35
instrumentation suite passes 10/10. This changes one classified test-source checksum but
does not change the 256-path universe, 134-path final slice, any classification,
disposition, runtime permission, or activation decision.

The only new path retained inside the legacy product universe is
`hummingbird/iosPatientApp/.gitignore`. It excludes generated Xcode, archive, derived-data,
and per-user artifacts and is classified as the
`generated_artifact_hygiene_principle`: a portable repository-safety objective, not
Nightingale runtime code or permission to reuse the legacy root. The corrected universe
therefore grows by one source, from 255 to 256, while all patient runtime behavior and
findings remain unchanged.

## 3. Surface reconciliation

### 3.1 Final-slice source surfaces

| Surface                | Sources | Primary concerns                                                                 |
| ---------------------- | ------: | -------------------------------------------------------------------------------- |
| Backend commands       |       4 | Draft workflows and command-accessible synthetic provisioning                    |
| Backend request        |       1 | Account presentation/delivery preference shape                                   |
| Backend models         |      15 | Append-only history, authored facts, cursors, failures, review/release facts     |
| Backend services       |      14 | HMAC/audit, authored inputs, source reconciliation, draft/release, demo fixtures |
| Database migrations    |      11 | Projection kinds, immutable history, preferences/goals, outbox, review/release   |
| Backend tests          |       9 | Synthetic provisioning, authored inputs, pathway history/source/release behavior |
| iOS packaging          |       6 | Legacy project, scheme, Info.plist, ignore rules, and generation ownership       |
| iOS source             |      12 | App boot, shell, journeys, preferences, presentation, privacy                    |
| iOS assets             |      11 | Legacy icon and scenic Hummingbird imagery                                       |
| iOS unit/UI tests      |       3 | Presentation/state/session behavior                                              |
| Android packaging      |       9 | Legacy package/version/build/wrapper/proguard ownership                          |
| Android source         |       9 | App boot, journey shell, preferences, accessibility, privacy                     |
| Android debug source   |       2 | Launch extras and full synthetic reference scenario                              |
| Android release source |       2 | Inert replacements for launch hooks and synthetic scenario                       |
| Android resources      |      13 | Hummingbird imagery, icons, themes, strings, network/backup controls             |
| Android tests          |      12 | Auth/session/privacy/presentation/product-boundary and debug/release controls    |
| Android asset evidence |       1 | Legacy asset provenance                                                          |
| **Total**              | **134** |                                                                                  |

### 3.2 Decision groups

| Decision ID                               |   Count | Class                     | Disposition    |
| ----------------------------------------- | ------: | ------------------------- | -------------- |
| `pathway_draft_command_held`              |       1 | Reusable product behavior | Held           |
| `reference_provisioning_rejected`         |       6 | Rejected legacy behavior  | Reject         |
| `account_preference_contract_held`        |       1 | Reusable product behavior | Held           |
| `append_only_audit_safety_principle`      |      15 | Reusable safety primitive | Principle only |
| `patient_authored_input_behavior_held`    |       4 | Reusable product behavior | Held           |
| `projection_release_safety_principle`     |       4 | Reusable safety primitive | Principle only |
| `pathway_source_contract_held`            |       2 | Reusable product behavior | Held           |
| `testing_only_projection_fixture`         |       1 | Test/fixture only         | Test only      |
| `append_only_projection_schema_principle` |      11 | Reusable safety primitive | Principle only |
| `asset_provenance_evidence`               |       1 | Test/fixture only         | Test only      |
| `generated_artifact_hygiene_principle`    |       1 | Reusable safety primitive | Principle only |
| `legacy_packaging_identity_rejected`      |      14 | Rejected legacy behavior  | Reject         |
| `test_fixture_evidence`                   |      24 | Test/fixture only         | Test only      |
| `debug_synthetic_fixture`                 |       2 | Test/fixture only         | Test only      |
| `native_journey_behavior_held`            |      12 | Reusable product behavior | Held           |
| `native_privacy_configuration_principle`  |       3 | Reusable safety primitive | Principle only |
| `legacy_brand_assets_rejected`            |      23 | Rejected legacy behavior  | Reject         |
| `native_presentation_safety_principle`    |       6 | Reusable safety primitive | Principle only |
| `release_synthetic_exclusion_principle`   |       2 | Reusable safety primitive | Principle only |
| `legacy_runtime_activation_rejected`      |       1 | Rejected legacy behavior  | Reject         |
| **Total**                                 | **134** |                           |                |

## 4. Patient-journey functional analysis

### 4.1 Useful journey properties

The legacy app contains strong candidate behavior that Nightingale should preserve as
requirements after its contract and content are approved:

1. **Released-only posture.** Today, My Path, and Care Team empty states explicitly refuse
   to infer content from staff-only or operational data.
2. **Uncertainty language.** Timing is described as estimated, being clarified, unknown,
   or able to change rather than as a promise.
3. **Provenance visibility.** Individual cards often identify a patient-safe projection
   source.
4. **Revision notices.** Updated released information can be distinguished from the
   original patient view.
5. **Urgent-help separation.** The app repeatedly directs immediate needs to the bedside
   call button or in-person staff and describes messages as non-urgent.
6. **Care-team role framing.** Care Team describes roles, responsibilities, availability,
   and safe connection routes rather than exposing staff operational assignments.
7. **Authored-intent separation.** Personal goals and “what matters to you” messages do not
   automatically mutate a clinical plan, order, consent, or assessment.
8. **Education clarification boundary.** Asking for an explanation does not represent
   completion, comprehension, consent, or clinician assessment.
9. **Discharge uncertainty.** Released preparation material is not represented as a
   discharge order or guaranteed date.
10. **Empty/error dignity.** Missing released content is explained without blaming the
    patient or inventing clinical detail.

These are product and safety inputs. They are not evidence that the current wording,
information architecture, source fields, state registry, or clinical release is approved
for Nightingale.

### 4.2 Top-level information architecture conflicts with the charter

Both legacy apps render four primary destinations:

1. Today
2. My Path
3. Care Team
4. Messages

The Nightingale charter requires three primary destinations—Today, My Path, and Care
Team—and communication entered from an allowed care-team or question flow. A top-level
Messages destination is therefore **not migration-approved**.

Before implementation, product and clinical operations owners must decide:

- which released item or care-team context creates a message entry point;
- whether a general non-urgent question entry point exists;
- what topic/routing context is preselected;
- how patients return to the originating care context;
- how unavailable, outside-hours, source-closed, and discharged states behave; and
- how the urgent-help escape remains visible without making the interface alarming.

### 4.3 iOS context aggregation is not field-level provenance

The iOS `PatientExperienceSnapshot.live` implementation:

- selects the first encounter;
- takes the maximum `as_of` time across available projection envelopes;
- marks the aggregate stale if any projection is stale;
- concatenates provenance and uncertainty across projections; and
- feeds that shared context to several screens.

This can make a screen’s freshness/source label describe a different projection than the
card being read. It can also make one stale auxiliary projection mark unrelated content
stale, while the maximum timestamp can appear newer than some displayed fields.

Nightingale must bind context to the smallest patient-meaningful unit:

| Required field-level context | Rule                                                                                          |
| ---------------------------- | --------------------------------------------------------------------------------------------- |
| Projection kind              | Identify the governed source contract that produced the displayed content.                    |
| Source observation time      | Time the authoritative source state was observed, not merely response or rendering time.      |
| Generated/released time      | Keep projection generation and release distinct.                                              |
| Freshness status             | Evaluate against a versioned, source-specific policy; do not derive from an unrelated panel.  |
| Uncertainty                  | Bind to the exact fact or section whose certainty is described.                               |
| Revision/retraction          | Identify what changed, when the replacement became effective, and what the patient should do. |
| Provenance label             | Use approved patient language; never expose raw source-system or staff-only identifiers.      |

### 4.4 Android composite My Path context is incomplete

Android renders pathway, pathway events, discharge readiness, and rounds summary in one My
Path destination. Its context map selects:

1. pathway context when available;
2. otherwise pathway-events context;
3. otherwise discharge-readiness context;
4. otherwise rounds-summary context.

The remaining subprojections can still be displayed below that one selected context.
Consequently, the My Path header does not prove the source, freshness, or uncertainty of
every section on the screen.

Nightingale must either:

- render context independently for each subprojection;
- define a governed composite envelope with coherent observation/release semantics; or
- split the material into patient-tested subroutes with their own context.

Choosing the newest timestamp or first non-null envelope is prohibited.

### 4.5 Encounter cardinality remains unsafe

Both legacy native flows silently select the first encounter returned by the server. The
prior encounter-access classification established that the backend ordering is not a
patient-safe transfer/readmission selection rule.

Nightingale’s held first-read candidate therefore remains correct:

- zero eligible current contexts returns a bounded empty outcome;
- exactly one self context may proceed;
- more than one context fails closed to account review until an approved selector exists;
- representatives remain out of scope; and
- clients never select the first record by array position.

### 4.6 Pathway release controls are strong but narrow

The backend contains a meaningful two-person pathway release pattern:

- an active authorized clinical reviewer records an immutable approved/withheld decision;
- a different active catalog release manager performs release;
- the draft must be version-pinned, current/aging, under an effective grant and policy;
- the release is a new immutable projection, not an in-place mutation;
- the release execution and projection are created transactionally;
- actor identities are stored as domain HMAC digests rather than raw staff IDs; and
- database triggers validate review/release facts and enqueue released-projection outbox
  work.

That control exists for the version-pinned **pathway** draft. It does not prove equivalent
two-person release coverage for Today, care team, pathway events, discharge readiness,
rounds summary, education, communication copy, or notification copy. Nightingale must not
generalize one pathway-specific service into global clinical approval.

### 4.7 Patient-authored goal and preference semantics

The backend distinguishes two concepts that must not be collapsed:

| Concept                               | Legacy behavior                                                        | Nightingale requirement                                                              |
| ------------------------------------- | ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| Account presentation preference       | Updates principal-level display/delivery fields                        | Separate device presentation, locale, communication consent, and delivery preference |
| “What matters to you” care preference | Immutable content-free association to an encrypted accountable message | Keep nonclinical until a staff workflow explicitly evaluates it                      |
| Patient-authored personal goal        | Immutable content-free association to an encrypted accountable message | Never silently become a care-plan goal, order, assessment, or completion fact        |
| Released care-team goal               | Read-only projection field with author/source context                  | Preserve author identity class and release provenance                                |

The content-free association pattern is useful: it prevents plaintext preference/goal
content from being duplicated in a secondary table. It still depends on the unresolved
Nightingale communication workflow, idempotency model, responder ownership, review status,
and patient-visible outcome.

## 5. Preference and accessibility reconciliation

### 5.1 Server/native shape mismatch

The legacy server accepts seven optional preference fields:

| Server field           | Server values                | iOS editor | Android editor | Classification |
| ---------------------- | ---------------------------- | :--------: | :------------: | -------------- |
| `locale`               | BCP-47-like string           |     No     |       No       | Held           |
| `timezone`             | IANA timezone                |     No     |       No       | Held           |
| `text_size`            | standard, large, extra_large |    Yes     |      Yes       | Principle only |
| `reduced_motion`       | boolean                      |    Yes     |      Yes       | Split behavior |
| `high_contrast`        | boolean                      |    Yes     |      Yes       | Principle only |
| `notification_preview` | hidden, generic              |    Yes     |      Yes       | Held           |
| `preferred_channel`    | push, email, sms, none       | push/email |   push/email   | Rejected as-is |

Neither client can edit locale or timezone. Neither exposes `sms` or `none`. Both default a
missing preferred channel to `push`, even though the communication review proved that no
patient push-delivery implementation exists.

Nightingale must not reproduce this mismatch. Every preference needs:

- an accountable owner;
- a defined scope: account, device, encounter, facility, or message topic;
- a purpose and non-purpose;
- allowed values and unknown-value behavior;
- default and migration behavior;
- consent/withdrawal semantics where applicable;
- server and native parity;
- accessibility and language review;
- audit/retention policy; and
- proof that the represented capability actually exists.

### 5.2 Device accessibility versus account preference

Text size, reduced motion, contrast, and transparency are normally device/environment
properties. Persisting an account preference can be useful, but must never weaken stronger
system settings or imply that one patient’s device needs apply to another device.

Observed positive behavior:

- iOS uses the maximum of system Dynamic Type and the account-selected minimum;
- Android uses the maximum of system font scale and the account-selected scale;
- iOS combines system and account reduced-motion values;
- iOS combines increased contrast/reduced transparency with account preferences; and
- Android applies an account high-contrast choice to its theme/background policy.

Required Nightingale rule:

```text
effective presentation
  = strongest applicable system accommodation
  + explicitly supported device-local choice
  + approved account preference only when it cannot weaken either
```

The product must document how conflicts resolve and which choices roam across devices.

### 5.3 Android reduced motion is currently semantically inert

The Android preference is decoded, saved, stored in
`PatientPresentationAccessibility`, and displayed in a “Your reading preferences” notice.
No main-source branch uses it to suppress, shorten, replace, or otherwise alter motion.

This is not evidence that Android currently violates a motion animation—the reviewed
surface may simply contain little motion. It is evidence that the “Reduce motion” control
does not itself change presentation and therefore should not be shown as an effective
setting until a tested behavior exists.

Nightingale acceptance must include:

- an inventory of every animation, transition, shimmer, parallax, auto-scroll, and
  decorative motion;
- system-level reduced-motion behavior;
- the product-choice behavior, if retained;
- no-motion alternatives;
- transition duration and repetition limits;
- screen-reader and focus stability; and
- emulator/device evidence that toggling the setting changes every governed motion site.

### 5.4 iOS reduced motion has bounded functional effect

iOS uses the combined system/account setting for:

- privacy-cover transition and animation; and
- decorative scenic-background motion.

This is reusable only as a control objective. Nightingale must enumerate and test every
future motion site; the existence of two compliant branches does not establish app-wide
coverage.

### 5.5 Text scaling and layout evidence still needed

Programmatically increasing text scale is not equivalent to proving accessibility. The
current source does not establish:

- every screen at the largest system accessibility sizes;
- landscape/compact-height behavior where supported;
- no clipped or truncated clinical copy;
- focus order after reflow;
- minimum touch targets;
- language expansion;
- screen-reader headings/actions/values for every journey;
- contrast under light/dark/high-contrast modes;
- images disabled or withheld;
- external keyboard/switch access; or
- cognitive-load and plain-language acceptance by patients.

These remain Stream E work, not inferred from code inspection.

## 6. Synthetic and debug boundary

### 6.1 Native compile-time separation

The legacy native targets contain two useful release-safety patterns:

| Platform | Debug behavior                                                                 | Release behavior                                                         |
| -------- | ------------------------------------------------------------------------------ | ------------------------------------------------------------------------ |
| iOS      | Synthetic activation and payload are inside `#if DEBUG`; UI tests use env keys | Synthetic request resolves false and synthetic payload is absent         |
| Android  | Debug source set supplies launch extras and a full marked synthetic scenario   | Release source set supplies inert hooks and null synthetic-content stubs |

The properties are reusable; the Hummingbird test keys, extras, IDs, content, names, and
payloads are not.

Nightingale release evidence must prove:

- debug/test symbols are absent from the signed Release binary/APK/AAB;
- release launch arguments, intents, deep links, notifications, restore state, and
  accessibility/test APIs cannot activate a fixture;
- no synthetic patient name, encounter ID, message, token, or content remains in release
  resources;
- test backends and certificates are excluded;
- store/distribution configuration cannot select a debug endpoint; and
- release analytics/crash reports cannot label synthetic content as a real patient.

### 6.2 Backend testing-only fixture

`SyntheticPatientProjectionProvisioner` refuses every environment except `testing` and
creates deterministic patient, grant, policy, cursor, and released-projection fixtures.
This is strong test containment and may inform a Nightingale test factory.

It remains test-only because it:

- constructs synthetic released clinical content;
- creates a synthetic active principal and grant;
- uses a testing policy and source system;
- bypasses real clinical/content approval by design; and
- is intended for automated verification, not patient use.

No production or staging fixture should reuse those records or identifiers.

### 6.3 Command-accessible reference provisioning is rejected

The remaining reference commands and demo provisioners have several safeguards:

- default-off configuration;
- PostgreSQL requirement;
- local-runtime refusal of a remote database;
- required synthetic `demo-`/`sim-` pseudonyms;
- command ownership checks;
- dry-run defaults;
- explicit `--commit`;
- pending/non-active principal constraints;
- secret redaction unless explicitly requested;
- draft-only projection state;
- synthetic provenance and unknown uncertainty;
- outbox/publication absence checks; and
- idempotency/locking behavior.

They can nevertheless mutate a deployed application database when enabled and invoked.
They can create or bind an operational encounter, patient principal, identity link, grant,
enrollment challenges, one-time secrets, draft policy, cursors, and draft projections.

Therefore:

- they are rejected as Nightingale runtime or deployment tooling;
- they were not executed;
- their `--commit` paths were not tested against production;
- they do not authorize a sample patient;
- their one-time secret output is not a support/recovery design;
- their synthetic labeling does not make production mutation appropriate; and
- a future Nightingale non-production fixture system requires a separate isolated
  environment, data lifecycle, owner, teardown, and approval.

## 7. Release, packaging, and product-identity analysis

### 7.1 Legacy packaging is not a migration primitive

The legacy native packages remain Hummingbird Patient:

- Hummingbird bundle/package identifiers;
- Hummingbird names and test targets;
- Hummingbird launcher/app icons and scenic assets;
- Hummingbird endpoint/activation keys;
- Hummingbird test hook names; and
- Hummingbird build/version ownership.

Nightingale already has independent application roots and the separately supplied
nightingale icon. Copying a project, package, plist, Gradle build, resource tree, or wrapper
as the migration mechanism would reintroduce legacy identity and activation decisions.

### 7.2 Runtime activation is not release governance

iOS can activate the legacy API from Info.plist or a process environment value after URL
validation. Android compiles an enabled flag and base URL from Gradle properties, defaulting
disabled while retaining the legacy production URL as a build value.

URL validation and default-off behavior are useful, but neither mechanism proves:

- who authorized the build;
- which contract/schema/client version it uses;
- which environment and certificate policy is allowed;
- which cohort may receive it;
- whether identity/source/content/communication gates are approved;
- how the feature expires or rolls back;
- how minimum versions are enforced; or
- that the distributed binary matches the reviewed SHA.

Nightingale requires a signed, immutable, environment-specific release manifest and
code-owned default denial. Environment variables or developer Gradle properties cannot be
the clinical/product authorization mechanism.

### 7.3 Store and distribution evidence is absent

The Android legacy target uses `versionCode = 1`,
`versionName = "0.1.0-pilot"`, and a non-minified Release build. The repository does not
prove external store records, distribution signing, retained released-artifact upgrade,
monotonic version history, privacy declarations, support URLs, notification identities, or
store review.

Those facts are not defects in a reference app; they are evidence that the repository
cannot claim release readiness. Nightingale’s Stream B released-artifact/store item remains
open.

### 7.4 Brand assets

All final-slice Hummingbird Patient icon, scenic photo, color, string, and theme resources
are rejected as Nightingale assets. Their design techniques may inform independent
patient testing, but the files themselves do not transfer.

Nightingale continues to use:

- its separate product name and app IDs;
- the supplied nightingale artwork and checksum-pinned provenance;
- its own launcher/app-icon derivatives;
- its own no-data scenic foundation; and
- its own accessibility and product-boundary tests.

## 8. Persistence, audit, and database controls

### 8.1 Reusable principles

The reviewed models/migrations demonstrate useful control objectives:

- external UUID assignment separated from database IDs;
- append-only application models;
- database triggers that reject updates/deletes to governed facts;
- immutable pathway stage/milestone status events;
- projection cursors with source/version/observation state;
- explicit projection failure facts;
- content-free access audit events;
- immutable patient-authored goal/preference associations;
- draft versus released projection states;
- independent review and release-execution records;
- content digest and trace digest binding; and
- released-projection outbox enqueueing.

Nightingale must reissue these controls only after its data model, retention, correction,
HIM/legal, audit, source, and release requirements are approved.

### 8.2 Append-only does not mean clinically immutable

An append-only database prevents silent rewriting; it does not prevent stale or incorrect
patient-visible content. Nightingale requires explicit:

- correction and supersession links;
- effective/retracted time;
- patient acknowledgment where appropriate;
- downstream cache/notification invalidation;
- source reconciliation;
- audit of who decided and released the correction;
- patient-safe explanation;
- staff escalation when a correction cannot be delivered; and
- retention/legal-hold behavior.

### 8.3 No migration approval

The eleven migration files are classified as safety-principle evidence only. This review:

- did not execute a migration;
- did not inspect deployed schema state;
- did not query production;
- did not create a backup;
- did not authorize a Nightingale schema; and
- does not satisfy the repository’s path-scoped production migration workflow.

## 9. Cross-platform parity decisions

| Concern                       | iOS legacy result                                  | Android legacy result                                            | Nightingale decision                                                |
| ----------------------------- | -------------------------------------------------- | ---------------------------------------------------------------- | ------------------------------------------------------------------- |
| Primary destinations          | Today, My Path, Care Team, Messages                | Today, My Path, Care Team, Messages                              | Hold Messages as contextual-only; no top-level destination approval |
| Encounter selection           | First returned encounter                           | First returned encounter                                         | Reject; zero/one candidate and fail closed on multiple              |
| Today empty state             | Released-only, no staff-data inference             | Released-only, no staff-data inference                           | Reimplement after Today contract approval                           |
| My Path composition           | Several subprojections in one long view            | Several subprojections in one long view                          | Patient-test hierarchy; field-level context required                |
| Context/freshness             | Aggregated across projections                      | Per destination, but composite path uses first available context | No unrelated max/first aggregation                                  |
| Care-team connection guidance | Bedside plus nonurgent Messages when allowed       | Bedside plus secure Messages when available                      | Contextual entry, accountable routing, urgent escape                |
| Text-size accommodation       | Max(system, selected minimum)                      | Max(system font scale, selected scale)                           | Preserve stronger system setting; verify reflow                     |
| High contrast                 | Account plus system contrast/transparency branches | Account theme/background branch                                  | Reimplement and test app-wide                                       |
| Reduced motion                | Controls privacy/background transitions            | Stored and announced; no rendering-control branch                | Do not expose until behavior is testable on both platforms          |
| Locale/timezone editor        | Absent                                             | Absent                                                           | Define ownership and parity before persistence                      |
| Delivery-channel editor       | Push/email, missing defaults to push               | Push/email, missing defaults to push                             | Reject until channels/consent/support exist                         |
| Synthetic release exclusion   | Compiler-gated                                     | Release source-set stubs                                         | Independent binary/source-set exclusion proof                       |
| Product activation            | Plist/environment + legacy host validation         | Gradle properties + legacy host build value                      | Reject; signed Nightingale release manifest required                |
| Product identity/assets       | Hummingbird Patient                                | Hummingbird Patient                                              | Reject; independent Nightingale identity/artwork only               |

## 10. Nightingale implementation gates derived from this review

### 10.1 Journey contracts

- [ ] Name the product, clinical safety, content, privacy, accessibility, language, source,
      support, and release owners for each journey.
- [ ] Define separate held candidate envelopes for Today, My Path, Care Team, education,
      pathway events, discharge readiness, and rounds summary.
- [ ] Specify every field’s source, version, release state, patient-language owner,
      observation/generated/released times, freshness, uncertainty, correction, retraction,
      offline, and unknown behavior.
- [ ] Define zero/one current inpatient context and reject client-side first-record
      selection.
- [ ] Decide whether My Path remains composite or is split into patient-tested subroutes.
- [ ] Define field-level context for every displayed subprojection.
- [ ] Define the contextual communication entry points; keep Messages out of primary
      navigation unless the charter is deliberately reapproved.
- [ ] Add complete omission, stale, corrected, retracted, dependency-outage, and
      incompatible-version fixtures.
- [ ] Add patient-safe non-disclosure behavior for wrong patient, encounter, relationship,
      grant, scope, and source state.

### 10.2 Preferences and presentation

- [x] Split device accessibility settings from account preferences.
      Nightingale now stores only reduced-motion and decorative-imagery choices in
      product-local platform stores; neither is connected to a care account, API, clinical
      model, locale, communication consent, or delivery preference.
- [x] Decide whether any accessibility preference roams across devices.
      Nightingale implements no account/cloud synchronization. Android backup and transfer
      exclude shared preferences. Apple-managed iOS backup/restore behavior remains an
      explicitly unratified external release property, not an app roaming feature.
- [ ] Define locale ownership, translation release, fallback, language-change, and
      interpreter-support behavior.
- [ ] Define timezone derivation and prevent a stale account timezone from changing clinical
      meaning.
- [ ] Remove delivery-channel choices until corresponding channels and consent/withdrawal
      workflows exist.
- [ ] Define preview privacy independently for lock screen, notification center, wearable,
      car, shared device, and representative access.
- [x] Implement actual reduced-motion behavior on both platforms before presenting the
      control as effective.
      Every current governed motion site consumes the stronger system/patient policy; iOS
      removes the foundation transitions and Android uses a zero-duration `snap()` policy.
- [ ] Prove largest text, reflow, focus order, contrast, target size, screen reader,
      landscape, language expansion, and images-disabled behavior.
      The images-disabled foundation subset is proven on both platforms, but the remaining
      compound requirements keep this item open.
- [x] Bind accessibility evidence to exact Debug and Release artifacts.
      The presentation decision records SHA-256 values for the exact local iOS simulator
      executables and Android APKs. Distribution signing, stores, protected-`main` release,
      and independent approval remain separate open gates.

### 10.3 Patient-authored inputs

- [ ] Define Nightingale-owned personal-goal and “what matters” operations only after
      communication ownership is approved.
- [ ] Preserve content encryption and content-free secondary associations.
- [ ] Preserve client operation identity through ambiguous network retries.
- [ ] Define staff receipt, ownership, review, response, closure, escalation, correction, and
      discharge-mid-thread behavior.
- [ ] Never represent submission as a care-plan mutation, order, consent, assessment,
      comprehension, or guaranteed team acknowledgment.
- [ ] Show exact patient-visible state based on accountable backend facts.

### 10.4 Synthetic/test architecture

- [ ] Create Nightingale-only synthetic factories under test/debug source boundaries.
- [ ] Use no legacy patient name, UUID, endpoint, hook, extra, environment key, source
      identifier, policy, or content record.
- [ ] Prove release artifacts exclude every synthetic activation and payload.
- [ ] Isolate non-production integration data from operational/production sources.
- [ ] Define fixture ownership, expiry, teardown, collision detection, and audit.
- [ ] Never use a production database to create a “sample patient.”

### 10.5 Release and operations

- [ ] Define signed environment-specific release manifests with exact contract,
      vocabulary, content, identity, source, and feature versions.
- [ ] Reserve external Apple/Google identifiers through authorized organization accounts.
- [ ] Establish monotonic build/version policy and retained-artifact upgrade tests.
- [ ] Complete distribution signing, store records, privacy declarations, support contacts,
      screenshots, release notes, and rollback.
- [ ] Prove exact-SHA CI and binary namespace/debug-hook scans.
- [ ] Keep deployment and migration separate, protected, path-scoped, backed up, and
      independently approved.
- [ ] Require a cohort/expiry-bound pilot manifest and signed go/no-go record.

## 11. Prioritized implementation sequence

The fastest safe path is not to port all 134 sources. It is to resolve the smallest set of
decisions that unlock one coherent patient journey:

### Phase 1 — Approve one read-only Today slice

1. Approve identity and current-inpatient prerequisites already modeled by the held
   candidate.
2. Define a Nightingale Today candidate envelope with no raw grant/source identifiers.
3. Bind every Today field to one approved source/release/freshness/uncertainty rule.
4. Define exact empty, stale, corrected, retracted, and unavailable outcomes.
5. Implement a default-off non-production adapter and exhaustive authorization fixtures.
6. Add Nightingale-native clients only after the contract is approved and generated.
7. Validate with patients, accessibility specialists, privacy/security, clinical safety,
   nursing, language/interpreter, and support.

### Phase 2 — Add My Path through governed composition

1. Approve source adapter(s), pathway vocabulary, content release, and two-person release
   applicability.
2. Decide composite versus subroute information architecture.
3. Add field-level context for pathway, events, discharge readiness, and rounds.
4. Implement correction/retraction and downstream invalidation.
5. Validate comprehension, anxiety, timing uncertainty, and care-team conversation impact.

### Phase 3 — Add Care Team and contextual questions

1. Approve patient-safe team roles and connection routes.
2. Approve contextual entry points and routing ownership.
3. Implement exact idempotency, staff projection, readback, and patient-visible states.
4. Add no-delivery/after-hours/downtime/discharge behavior.
5. Add push/email/SMS only as separately approved capabilities; do not expose placeholders.

### Phase 4 — Expand accommodations and controlled pilot evidence

1. Complete locale/language, interpreter, representative, shared-device, and accessibility
   decisions.
2. Complete non-production integration, outage, recovery, security, and load exercises.
3. Produce exact signed artifacts, store/distribution evidence, rollback, and a
   cohort/expiry-bound pilot manifest.
4. Deploy only from reviewed protected `main` after exact-SHA CI and all named approvals.

## 12. Evidence and holds

### Completed in this classification

- Exact full-universe definition and path-list digest.
- Exact final-slice definition and path-list digest.
- SHA-256 for every one of 134 final-slice sources.
- One class, disposition, surface, decision, and domain set per source.
- Closure across all three classification ledgers.
- Patient-journey, preference, accessibility, synthetic/debug, persistence, and release
  analysis.
- Required findings that preserve known cross-platform and governance gaps.
- Negative mechanical enforcement in CI.

### Still held

- Every Nightingale runtime operation and route.
- Identity provider, enrollment, recovery, representative access, and production source
  adapter.
- Patient API client and Android network permission.
- Every clinical projection and content release.
- Every patient-authored mutation.
- Communication, notification provider, push, email, SMS, and background refresh.
- Locale/translation and account preference persistence.
- Synthetic runtime and deployed reference provisioning.
- Database migration and production data access.
- Distribution/store release, pilot, deployment, and activation.
- Named clinical, privacy/security, accessibility, patient-advisor, language/interpreter,
  legal/HIM, nursing, medical-staff, pharmacy, support, and release approvals.

## 13. Safety statement

No production database was accessed. No patient, principal, identity link, grant, session,
encounter, preference, message, goal, pathway draft, review, release, outbox event, feature
flag, route, provider, migration, deployment, or pilot state was created, read, or changed.

The command-accessible Hummingbird reference provisioners were reviewed from source and
explicitly rejected as Nightingale/production tooling. Their existence is not permission to
run them.
