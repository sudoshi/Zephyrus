# Nightingale current-main product-boundary reconciliation

- **Date:** 2026-07-27
- **Feature branch:** `codex/nightingale-patient-product`
- **Verified pre-merge Nightingale baseline:** `21e4de17e27c258955c0b14ba490f9d0d4be16b5`
- **Merged `origin/main`:** `ea14d2bd2be9656555dfba35da2dbf28e74730e3`
- **Merge commit:** `bcaae3da`
- **Boundary-restoration commit:** `c11d462cb5c62e399139caf0538ed9def1f1d441`
- **Functional checklist effect:** none; remains 41/54 (75.93%)

## 1. Why reconciliation was required

Current `main` contained two changes after the last verified Nightingale feature head:

1. `f63e599476ed59fff443a8b1ac0c7bbc4f2483fc` updated the Flow 4D
   patient-journey/conformance plan. It is unrelated to the Nightingale product boundary
   and had to be preserved.
2. `ea14d2bd2be9656555dfba35da2dbf28e74730e3` renamed the legacy
   `hummingbird/iosPatientApp`, `hummingbird/androidPatientApp`, patient middleware,
   backend configuration, contract, and reference provisioners to Nightingale.

The second change conflicts with the accepted product direction. Nightingale is an
independent product rooted at `nightingale/iosApp` and `nightingale/androidApp`; the
legacy Hummingbird Patient applications remain deprecated, non-production migration
evidence. Renaming the legacy applications in place would create two source roots claiming
the same Nightingale app identifiers and would collapse the migration boundary that the
plan requires.

## 2. Reconciliation method

The feature branch merged current `origin/main` so the unrelated Flow 4D change and the
complete upstream graph were preserved. It then forward-reverted only the incompatible
patient-product rename:

```text
bcaae3da Merge remote-tracking branch 'origin/main' into codex/nightingale-patient-product
c11d462c Revert "Rename the patient product from Hummingbird Patient to Nightingale (#101)"
```

Seven merge/revert conflict paths and the independent Nightingale configuration were
resolved against the exact verified tree at `21e4de17`:

- `.appledeploy.example`
- `.gitleaks.toml`
- `config/hummingbird-patient.php`
- `config/nightingale.php`
- `docs/hummingbird/TESTFLIGHT.md`
- `hummingbird/iosApp/Hummingbird/Assets.xcassets/AppIcon.appiconset/icon-1024.png`
- `hummingbird/iosPatientApp/HummingbirdPatient.xcodeproj/project.pbxproj`
- `hummingbird/iosPatientApp/project.yml`

This is a forward reconciliation on the feature branch. It does not rewrite published
history, discard unrelated upstream work, alter production, or assert that the upstream
rename never occurred.

## 3. Exact tree-equivalence proof

The boundary-restoration tree at `c11d462c`, before this evidence-only follow-up, differs
from the verified Nightingale baseline in exactly one path:

```text
M docs/plans/FLOW-4D-PATIENT-JOURNEY-AND-CONFORMANCE-PLAN-2026-07-26.md
```

That path is byte-identical to the version introduced by `f63e5994`. Therefore:

- every Nightingale and legacy Hummingbird Patient source/configuration path is restored
  to the exact verified `21e4de17` state;
- the unrelated Flow 4D upstream change is retained;
- no unrelated user or upstream work is dropped.

At `c11d462c`, the branch was 0 commits behind and 36 commits ahead of
`ea14d2bd`, before adding this evidence-only record.

## 4. Product-identity invariants after reconciliation

| Boundary               | Required state                             | Verified state                                                                                                   |
| ---------------------- | ------------------------------------------ | ---------------------------------------------------------------------------------------------------------------- |
| Nightingale iOS        | independent target and bundle identity     | `nightingale/iosApp`; `net.acumenus.nightingale`                                                                 |
| Nightingale Android    | independent app and package identity       | `nightingale/androidApp`; `net.acumenus.nightingale`                                                             |
| Legacy patient iOS     | deprecated Hummingbird Patient identity    | `hummingbird/iosPatientApp`; `net.acumenus.hummingbird.patient`                                                  |
| Legacy patient Android | deprecated Hummingbird Patient identity    | `hummingbird/androidPatientApp`; `net.acumenus.hummingbird.patient`                                              |
| Nightingale backend    | route-free, code-owned, inert foundation   | `/api/nightingale/v1` reserved; all route/network/identity/source/disclosure/mutation/production states disabled |
| Legacy backend         | retained compatibility and migration input | `config/hummingbird-patient.php` and legacy contract restored                                                    |
| Distribution source    | independent Nightingale root only          | `.appledeploy.example` points Nightingale to `nightingale/iosApp`                                                |

Repository scans found:

- no `net.acumenus.nightingale` runtime identity under either legacy Hummingbird Patient
  native root;
- no `net.acumenus.hummingbird.patient` identity under either independent Nightingale
  native root;
- no executable Nightingale route, network permission, source adapter, identity provider,
  content release, notification provider, or production activation.

## 5. Reacceptance evidence

### 5.1 Contract, governance, and backend

The full Nightingale contract/boundary chain passed:

- empty/default-off contract and negative self-tests;
- held encounter-access, Today, and patient-journey candidates;
- 64 identity cases and 42 source cases;
- 65-source identity, 130-source communication/notification, and 134-source
  journey/preference/release ledgers, covering the exact 256-source predecessor universe;
- threat/hazard model;
- dependency inventory;
- namespace manifest and eight negative mutations;
- backend foundation and native product-boundary verifiers.

The focused Laravel suite passed 23 tests and 149 assertions.

### 5.2 iOS Simulator and Release artifact

The reconciled Xcode project matched `project.yml`. A fresh build-for-testing ran on an
iPhone 16e simulator with iOS 26.3.1:

- Nightingale unit tests: 11/11 passed;
- Nightingale UI journeys: 6/6 passed;
- failures: 0;
- skipped: 0.

An unsigned Release simulator application then passed the exact identity, version,
orientation, privacy-manifest, linked-dependency, no-network/no-deep-link/no-test-hook,
English-copy, and seven-background artifact boundary. Only the Nightingale simulator
started for this run was shut down.

### 5.3 Android unit, build, emulator, and Release artifact

The clean Android acceptance passed:

- Debug unit tests: 8/8 passed after forced uncached execution;
- Release unit tests: 8/8 passed after forced uncached execution;
- Debug and Release lint: passed;
- Debug and Release assembly: passed;
- unsigned Release APK boundary: passed.

The pre-existing API 35 emulator on port 5554 belonged to another process and was left
untouched. A second read-only API 35 instance was started on port 5556. All 10/10 installed
Nightingale journeys passed with zero failures, errors, or skips. Only the port-5556
instance was terminated afterward; port 5554 remained connected.

## 6. Sample-patient and production boundary

This reconciliation performed no production database access or mutation. The already
authorized sample remains exactly as documented in
[the production sample-patient evidence](../production-sample-patient-2026-07-27/README.md):
one pending/inactive, contactless/passwordless Nightingale principal and one synthetic
operational encounter derived from the safe shape of the deprecated Hummingbird Patient
reference. It has no identity link, grant, challenge, session, projection, application
caller, or activation.

No migration, deployment, signing, upload, distribution, pilot enrollment, clinical
approval, content release, source-connector deployment, or patient disclosure occurred.

## 7. Checklist accounting and remaining holds

This work restores and proves an already-accepted architectural boundary. It does not
deliver a new functional capability, approval, live integration, or release control.
Accordingly, no functional checkbox is newly closed:

- complete: 41/54;
- incomplete: 13/54;
- count-based completion: 75.93%.

The remaining items still require released operation implementations, named
identity/source/content/reviewer approvals, rights and attribution evidence, signed
distribution proof, human accessibility and patient-advisor review, production-like
integration/failover exercises, and controlled-pilot authorization.
