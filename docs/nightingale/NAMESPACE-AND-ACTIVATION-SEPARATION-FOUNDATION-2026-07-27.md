# Nightingale namespace and activation-separation foundation

**Date:** 2026-07-27

**Status:** bounded engineering implementation; no route, identity, source query, patient
content, disclosure, mutation, pilot, or production activation

**Product:** Nightingale

**Executable API paths:** 0

**Runtime callers:** 0

## 1. Decision

Nightingale now has two mechanically enforced foundations that close separate product-plan
requirements without pretending that live patient functionality has been approved:

1. every current product identifier is owned by the Nightingale namespace; and
2. clinical approval, patient-content release, feature activation, pilot enrollment, and
   source-connector deployment are distinct, conjunctive prerequisites that default to
   negative states.

Neither foundation is an authorization or release mechanism. The namespace contract
prevents cross-product identity drift. The activation gate prevents any one governance
state from standing in for the other four. Even its sole all-positive outcome only
continues to a future operation-specific release evaluation.

## 2. Scope and non-scope

### 2.1 Implemented

- exact iOS and Android product, bundle, package, accessibility, test-hook, protected-state,
  presentation-preference, telemetry, and diagnostic namespace inventories;
- fully qualified Nightingale storage identifiers, including the formerly container-local
  Android preference keys and both native future-session-binding keys;
- a deterministic namespace manifest builder;
- an independent source scanner and manifest verifier with eight negative mutations;
- five independent backend activation state types;
- a pure five-input activation gate;
- code-owned, non-environment-activatable negative defaults;
- five additional false activation fields in the empty executable contract;
- exhaustive 32-row PHPUnit and dependency-free truth-table proof;
- runtime-registration scans for the activation gate; and
- CI integration through both the contract job and every native product-boundary pass.

### 2.2 Deliberately not implemented

- an identity provider, proofing, enrollment, recovery, or representative workflow;
- a Nightingale route, controller, service-provider binding, middleware, or client;
- a production source adapter or patient query;
- a clinical approval repository or approval-record validator;
- a patient-content release repository or content payload;
- a feature-switch provider;
- a pilot manifest, cohort membership service, consent service, or support schedule;
- a source-connector deployment or health evaluator;
- a disclosure, mutation, message, notification, telemetry event, diagnostic channel, or
  log sink; or
- a claim that the all-positive gate result authorizes access, release, production, or
  pilot use.

## 3. Namespace contract

### 3.1 Canonical rules

| Identifier class          | Canonical rule                              | Current inventory |
| ------------------------- | ------------------------------------------- | ----------------: |
| Product reverse-DNS root  | `net.acumenus.nightingale`                  |                 1 |
| Accessibility/test tags   | prefix `nightingale-`                       |                10 |
| Debug-only native hooks   | prefix `NIGHTINGALE_`                       |                 4 |
| Persistent identifiers    | prefix `net.acumenus.nightingale.`          |                10 |
| Telemetry event names     | prefix reserved; no event is implemented    |                 0 |
| Diagnostic channel names  | prefix reserved; no channel is implemented  |                 0 |
| Legacy Hummingbird tokens | prohibited from Nightingale runtime sources |                 0 |

The generated source of record is
[`namespace/foundation-namespace.v1.json`](./namespace/foundation-namespace.v1.json).
It binds 12 exact source files to SHA-256 digests and records what each file is responsible
for.

### 3.2 Accessibility and semantic identifiers

Both native implementations expose the same exact ten identifiers:

1. `nightingale-safe-shell`
2. `nightingale-product-heading`
3. `nightingale-foundation-mission`
4. `nightingale-privacy-status-heading`
5. `nightingale-display-comfort-heading`
6. `nightingale-reduce-motion-toggle`
7. `nightingale-motion-status`
8. `nightingale-hide-imagery-toggle`
9. `nightingale-imagery-status`
10. `nightingale-privacy-cover`

The verifier extracts iOS `.accessibilityIdentifier(...)` and Android `.testTag(...)`
declarations from production sources. A manifest-only edit cannot conceal source drift.

### 3.3 Debug-only test hooks

The iOS foundation has exactly four current hooks:

| Hook                                              | Purpose                                  | Release posture |
| ------------------------------------------------- | ---------------------------------------- | --------------- |
| `NIGHTINGALE_SHOW_PRIVACY_COVER`                  | Exercise lifecycle privacy cover         | stripped        |
| `NIGHTINGALE_TEST_ACCESSIBILITY_TEXT_SIZE`        | Exercise accessibility text layout       | stripped        |
| `NIGHTINGALE_TEST_LAYOUT_DIRECTION`               | Exercise right-to-left layout            | stripped        |
| `NIGHTINGALE_TEST_RESET_PRESENTATION_PREFERENCES` | Reset synthetic presentation preferences | stripped        |

The existing Release-artifact verifiers remain responsible for proving that these Debug
hooks do not survive in distributable binaries. The namespace verifier prevents an
unregistered or cross-product hook from entering the current sources.

### 3.4 Persistent identifiers

| Platform | Kind             | Identifier                                                           |
| -------- | ---------------- | -------------------------------------------------------------------- |
| iOS      | Keychain service | `net.acumenus.nightingale.protected-state.v1`                        |
| iOS      | Keychain account | `net.acumenus.nightingale.protected-state.v1.future-session-binding` |
| iOS      | Preference key   | `net.acumenus.nightingale.presentation.v1.reduce-motion`             |
| iOS      | Preference key   | `net.acumenus.nightingale.presentation.v1.hide-decorative-imagery`   |
| Android  | Preference file  | `net.acumenus.nightingale.presentation.v1`                           |
| Android  | Preference key   | `net.acumenus.nightingale.presentation.v1.reduce-motion`             |
| Android  | Preference key   | `net.acumenus.nightingale.presentation.v1.hide-decorative-imagery`   |
| Android  | Keystore alias   | `net.acumenus.nightingale.protected-state-key.v1`                    |
| Android  | Preference file  | `net.acumenus.nightingale.protected-state-ciphertext.v1`             |
| Android  | Preference key   | `net.acumenus.nightingale.protected-state.v1.future-session-binding` |

The protected-state primitives remain caller-free and credential-agnostic. The
presentation keys contain device-local comfort choices only. Backup, device transfer,
cloud sync, account sync, and production credential persistence remain prohibited.

### 3.5 Product strings, telemetry, and diagnostics

The existing exact 15-key cross-platform copy verifier remains the patient-string
authority. This namespace slice adds an independent prohibition against
`net.acumenus.hummingbird`, `hummingbird.patient`, or `Hummingbird Patient` in Nightingale
runtime sources.

No telemetry implementation or diagnostic channel currently exists. Their future
reverse-DNS roots are reserved, but both inventories remain empty and both implementation
flags remain false. Runtime logging and common analytics/crash primitives remain rejected
by the product-boundary scanner.

## 4. Activation separation

### 4.1 Independent typed states

| Gate                        | Negative/default state | Positive prerequisite state | What the positive state does not prove                      |
| --------------------------- | ---------------------- | --------------------------- | ----------------------------------------------------------- |
| Institutional clinical      | `absent`               | `recorded`                  | content release, activation, enrollment, source, disclosure |
| Patient-content release     | `unreleased`           | `released`                  | clinical approval, activation, enrollment, source, access   |
| Product feature activation  | `disabled`             | `enabled`                   | approval, content release, enrollment, source, access       |
| Pilot enrollment            | `not_enrolled`         | `enrolled`                  | approval, release, consent validity, identity, access       |
| Source connector deployment | `undeployed`           | `deployed`                  | source health, correctness, release, identity, access       |

Each state has a distinct PHP type. A caller cannot accidentally pass a content-release
state where a pilot-enrollment state is required.

### 4.2 Default configuration

`config/nightingale.php` is code-owned and contains no environment lookup. Its activation
record is exactly:

| Field                      | Default        |
| -------------------------- | -------------- |
| `clinical_approval_state`  | `absent`       |
| `clinical_approval_record` | `null`         |
| `content_release_state`    | `unreleased`   |
| `content_release_id`       | `null`         |
| `feature_activation_state` | `disabled`     |
| `pilot_enrollment_state`   | `not_enrolled` |
| `source_connector_state`   | `undeployed`   |

The empty executable contract independently pins five corresponding Boolean activation
facts to `false`:

- `clinical_approval_recorded`
- `patient_content_released`
- `feature_activated`
- `pilot_enrollment_confirmed`
- `source_connector_deployed`

These are additive to the existing route, network, identity, source-query, disclosure,
mutation, and production false fields.

### 4.3 Exhaustive truth table

For compactness, `1` means the positive prerequisite state and `0` means its negative
state. Columns are clinical approval (`A`), content release (`C`), feature activation
(`F`), pilot enrollment (`P`), and source connector (`S`).

| Row | A   | C   | F   | P   | S   | Disposition                                         |
| --: | --- | --- | --- | --- | --- | --------------------------------------------------- |
|   1 | 0   | 0   | 0   | 0   | 0   | `hold`                                              |
|   2 | 0   | 0   | 0   | 0   | 1   | `hold`                                              |
|   3 | 0   | 0   | 0   | 1   | 0   | `hold`                                              |
|   4 | 0   | 0   | 0   | 1   | 1   | `hold`                                              |
|   5 | 0   | 0   | 1   | 0   | 0   | `hold`                                              |
|   6 | 0   | 0   | 1   | 0   | 1   | `hold`                                              |
|   7 | 0   | 0   | 1   | 1   | 0   | `hold`                                              |
|   8 | 0   | 0   | 1   | 1   | 1   | `hold`                                              |
|   9 | 0   | 1   | 0   | 0   | 0   | `hold`                                              |
|  10 | 0   | 1   | 0   | 0   | 1   | `hold`                                              |
|  11 | 0   | 1   | 0   | 1   | 0   | `hold`                                              |
|  12 | 0   | 1   | 0   | 1   | 1   | `hold`                                              |
|  13 | 0   | 1   | 1   | 0   | 0   | `hold`                                              |
|  14 | 0   | 1   | 1   | 0   | 1   | `hold`                                              |
|  15 | 0   | 1   | 1   | 1   | 0   | `hold`                                              |
|  16 | 0   | 1   | 1   | 1   | 1   | `hold`                                              |
|  17 | 1   | 0   | 0   | 0   | 0   | `hold`                                              |
|  18 | 1   | 0   | 0   | 0   | 1   | `hold`                                              |
|  19 | 1   | 0   | 0   | 1   | 0   | `hold`                                              |
|  20 | 1   | 0   | 0   | 1   | 1   | `hold`                                              |
|  21 | 1   | 0   | 1   | 0   | 0   | `hold`                                              |
|  22 | 1   | 0   | 1   | 0   | 1   | `hold`                                              |
|  23 | 1   | 0   | 1   | 1   | 0   | `hold`                                              |
|  24 | 1   | 0   | 1   | 1   | 1   | `hold`                                              |
|  25 | 1   | 1   | 0   | 0   | 0   | `hold`                                              |
|  26 | 1   | 1   | 0   | 0   | 1   | `hold`                                              |
|  27 | 1   | 1   | 0   | 1   | 0   | `hold`                                              |
|  28 | 1   | 1   | 0   | 1   | 1   | `hold`                                              |
|  29 | 1   | 1   | 1   | 0   | 0   | `hold`                                              |
|  30 | 1   | 1   | 1   | 0   | 1   | `hold`                                              |
|  31 | 1   | 1   | 1   | 1   | 0   | `hold`                                              |
|  32 | 1   | 1   | 1   | 1   | 1   | `continue_to_operation_specific_release_evaluation` |

The one continuing row is not a release decision. A future operation must still pass the
identity, current-encounter, relationship, resource, field-release, freshness, language,
correction, audit-before-disclosure, serialization, and generic non-disclosure controls
applicable to that operation.

## 5. Mechanical enforcement

### 5.1 Namespace builder and verifier

```bash
node scripts/ci/build-nightingale-namespace-foundation.mjs . --write
node scripts/ci/verify-nightingale-namespace-foundation.mjs . --self-test
```

The verifier:

- requires byte-for-byte equality with deterministic builder output;
- validates exact product and namespace rules;
- scans current native accessibility/test-tag declarations;
- scans current Debug-hook declarations;
- proves every storage identifier uses the Nightingale reverse-DNS prefix;
- proves both telemetry and diagnostic inventories are empty;
- rejects legacy Hummingbird patient tokens;
- rejects unregistered logging/analytics primitives;
- verifies all 12 source SHA-256 values; and
- rejects eight adversarial manifest mutations.

### 5.2 Backend verifier and tests

```bash
php scripts/ci/verify-nightingale-backend-foundation.php . --self-test
php artisan test tests/Unit/Nightingale/NightingaleBackendFoundationTest.php
```

The independent verifier and PHPUnit suite each enumerate all 32 activation combinations,
require exactly 31 holds and one limited continuation, pin all state vocabularies, validate
the negative configuration, and confirm that no provider, route, or bootstrap source
registers the activation gate.

### 5.3 Native artifact and emulator regression

The existing native product-boundary verifier now invokes the namespace verifier. Release
artifact checks continue to prove exact bundle/package identity and absence of Debug hooks.
The accepted implementation record retains these exact results:

| Platform/result                                    | Accepted result                             |
| -------------------------------------------------- | ------------------------------------------- |
| iOS Debug build-for-testing                        | passed from a fresh `/tmp` DerivedData root |
| iOS unit suite, iPhone 16e / iOS 26.3.1            | 11 passed; 0 failed; 0 skipped              |
| iOS UI suite, iPhone 16e / iOS 26.3.1              | 6 passed; 0 failed; 0 skipped               |
| iOS unsigned Release build and artifact verifier   | passed                                      |
| Android Debug unit suite                           | 8 passed; 0 failed; 0 errors; 0 skipped     |
| Android Release unit suite                         | 8 passed; 0 failed; 0 errors; 0 skipped     |
| Android Debug/Release lint and assembly            | passed                                      |
| Android unsigned Release APK boundary              | passed                                      |
| Android isolated cold/wiped API 35 instrumentation | 10 passed; 0 failed; 0 errors; 0 skipped    |

The Android device was a temporary `nightingale-codex` AVD on its own port because a
separate process owned the shared `hb` AVD. The separate emulator was preserved. The
temporary AVD and the iPhone simulator were shut down after XML/xcresult reconciliation.
These results authorize completion of only the two bounded master-checklist items described
by this document.

## 6. Failure behavior and rollback

- Any manifest/source mismatch fails CI.
- Any unknown accessibility ID, test hook, storage key, telemetry event, or diagnostic
  channel fails CI.
- Any legacy product namespace in Nightingale runtime source fails CI.
- Any single missing activation prerequisite returns `hold`.
- Any configuration attempt to change a default through an environment hook fails CI.
- Any service-provider, route, or bootstrap registration of the activation gate fails CI.

Rollback is source-only because this slice adds no migration, route, deployment, account,
patient data, external identifier, or background task. The namespaced protected-state
keys remain dormant and caller-free. No credential migration or production-state rewrite
is required or authorized.

## 7. Residual requirements

This bounded foundation does not satisfy the open requirements for:

- a live patient API or approved state vocabulary;
- named clinical, content, patient-advisor, accessibility, privacy, security, legal, or
  operational approval;
- full WCAG 2.2 AA conformance or assistive-technology validation;
- production-like integration, source outage, failover, load, recovery, or rollback
  exercises;
- a signed release, store record, notification surface, or upgrade test;
- a pilot manifest, enrollment decision, or support procedure; or
- a production deployment or activation.

Those items remain independently open in the
[master Nightingale plan](../plans/nightingale-patient-product-2026-07-26.md).
