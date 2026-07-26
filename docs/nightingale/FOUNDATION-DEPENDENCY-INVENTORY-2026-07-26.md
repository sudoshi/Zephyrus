# Nightingale foundation dependency inventory

**Status:** Generated, source-hash-bound engineering inventory for the offline native
foundation. This is not a CycloneDX or SPDX conformance claim, license determination,
vulnerability assessment, software-supply-chain approval, signed-artifact manifest, or
authorization for distribution or live clinical use.

**Canonical machine record:**
[`supply-chain/foundation-dependency-inventory.v0.json`](./supply-chain/foundation-dependency-inventory.v0.json)

**Governing plan:**
[`nightingale-patient-product-2026-07-26.md`](../plans/nightingale-patient-product-2026-07-26.md)

## 1. Executive disposition

| Question                                                                 | Verified foundation answer                                                                  |
| ------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------- |
| What Android runtime graph is bounded?                                   | `releaseRuntimeClasspath` for `nightingale/androidApp/app`                                  |
| How many Android direct runtime declarations exist?                      | 7                                                                                           |
| How many unique external Android Release runtime components resolve?     | 83                                                                                          |
| How many unique resolved Android dependency edges are recorded?          | 457                                                                                         |
| Does the iOS application target declare a third-party package manager?   | No                                                                                          |
| How many iOS third-party runtime packages are declared?                  | 0                                                                                           |
| Which Apple modules does the iOS application source import?              | `Combine`, `Foundation`, `Security`, and `SwiftUI`                                          |
| Did generation require a patient, credential, database, API, or network? | No patient/production system was accessed; Gradle resolved public build dependency metadata |
| Is the record deterministic?                                             | Yes; timestamps are omitted and six source files are bound by SHA-256                       |
| Is the record continuously vulnerability-scanned or provenance-approved? | No                                                                                          |
| Does this approve the native apps for signing, stores, pilot, or use?    | No                                                                                          |

The inventory closes the missing **generated dependency-inventory evidence** for the
current Stream A foundation. It does not close the independent signing, store,
vulnerability, provenance, license, penetration-test, clinical-safety, privacy, identity,
source, patient-advisor, accessibility, support, pilot, or release gates.

## 2. Why this record exists

The initial Nightingale product plan required a generated
“software-bill-of-materials/dependency inventory” before the native foundation could leave
its product-foundation workstream. A hand-written package list would not be sufficient
because Android version alignment and transitive resolution change the runtime graph beyond
the seven declarations visible in `dependencies {}`. Conversely, describing the current
iOS target as dependency-free without bounding what was inspected would overstate the
finding.

This record therefore separates four different facts:

1. **Declared dependencies:** what the source explicitly requests.
2. **Resolved runtime components:** which external Android module versions Gradle selects
   for the Release runtime classpath.
3. **Resolved graph edges:** which component/request relationships produce that selected
   graph.
4. **Observed iOS package-manager state:** whether the XcodeGen source or common
   package-manager files declare third-party packages, plus the Apple modules imported by
   the application source.

Gradle documents `ResolutionResult` as the model containing selected component instances
and their relationships in the resolved graph:
[Gradle `ResolutionResult`](https://docs.gradle.org/current/kotlin-dsl/gradle/org.gradle.api.artifacts.result/-resolution-result/index.html).
The generator uses that model rather than scraping human-formatted console output.

Apple documents Swift packages as reusable components that Xcode can add and manage as
package dependencies:
[Apple Swift packages](https://developer.apple.com/documentation/xcode/swift-packages).
The current Nightingale XcodeGen source declares none. That does **not** turn Apple SDK
modules into third-party packages or inventory the contents of iOS itself.

## 3. Exact scope

### 3.1 Included

The machine record includes:

- the seven direct dependencies inherited by Android `releaseRuntimeClasspath`;
- all unique external module components selected by Gradle for that configuration;
- every unique resolved `from` → `requested` → `selected` relationship reported by Gradle;
- declared Gradle wrapper, Android Gradle Plugin, Kotlin plugin, Java, and Android SDK
  requirements;
- the XcodeGen source path, Swift language version, and minimum iOS version;
- the absence of Swift Package Manager, CocoaPods, and Carthage source inputs in the
  bounded iOS root;
- the absence of an XcodeGen `packages:` or target `package:` declaration;
- Apple module imports found in the `Nightingale` application-target Swift sources; and
- SHA-256 values for all six source inputs that define or generate the record.

### 3.2 Excluded

This inventory deliberately does not claim to include or determine:

- Android Debug, unit-test, instrumentation-test, lint, annotation-processor, or other
  configurations;
- resolved Gradle plugin/build-tool transitive classpaths;
- downloaded artifact bytes, artifact SHA-256 values, PGP signatures, repository identity,
  or reproducible-build equivalence;
- iOS SDK, operating-system, compiler, Xcode, linker, or Apple-framework component
  contents;
- embedded binary/framework inspection of a signed iOS archive or Android app bundle;
- license identity, license compatibility, notices, export-control status, or legal advice;
- vulnerability, malware, exploitability, end-of-life, or threat-intelligence findings;
- signing certificates, provisioning profiles, entitlements, store records, release
  manifests, notarization, or provenance attestations;
- build runner, host OS, container/image, package registry, source repository, or account
  security;
- clinical safety, patient privacy, authorization, identity, data-source, messaging,
  support, accessibility, language, content, pilot, or operational readiness; or
- CycloneDX, SPDX, SLSA, OWASP MASVS, NIST, regulatory, or other standards compliance.

These exclusions are intentional. A bounded and accurate inventory is useful evidence; an
overbroad “SBOM complete” label would create false assurance.

## 4. Source-of-truth boundary

The generator binds the canonical record to exactly these files:

| Source                                                                | Role                                                                          |
| --------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| `nightingale/androidApp/app/build.gradle.kts`                         | Android app configuration, direct dependencies, and resolution-report task    |
| `nightingale/androidApp/build.gradle.kts`                             | Android Gradle Plugin and Kotlin plugin declarations                          |
| `nightingale/androidApp/settings.gradle.kts`                          | Plugin and dependency repository declarations                                 |
| `nightingale/androidApp/gradle/wrapper/gradle-wrapper.properties`     | Gradle wrapper version                                                        |
| `nightingale/iosApp/project.yml`                                      | iOS application target, deployment target, build settings, and package source |
| `scripts/ci/generate-nightingale-foundation-dependency-inventory.mjs` | Deterministic assembly and scope logic                                        |

The JSON stores the SHA-256 of each file. The verifier recalculates every value from the
checkout. A dependency declaration, plugin version, repository, wrapper version, SDK
requirement, XcodeGen source, or generator change makes the checked-in record stale until
it is regenerated and reviewed.

The verifier itself is intentionally not part of that hash set. Its behavior is exercised
by negative mutations in CI; changing verification policy does not rewrite the dependency
record unless an inventoried source also changes.

## 5. Android resolution method

### 5.1 Source declarations

The current Release runtime inherits seven direct declarations:

| Declaration                                 | Requested version source |
| ------------------------------------------- | ------------------------ |
| `androidx.activity:activity-compose`        | Explicit `1.10.0`        |
| `androidx.compose:compose-bom`              | Explicit `2025.02.00`    |
| `androidx.compose.animation:animation-core` | Compose BOM managed      |
| `androidx.compose.foundation:foundation`    | Compose BOM managed      |
| `androidx.compose.material3:material3`      | Compose BOM managed      |
| `androidx.compose.ui:ui`                    | Compose BOM managed      |
| `androidx.compose.ui:ui-tooling-preview`    | Compose BOM managed      |

Test and Debug declarations are excluded because the recorded configuration is specifically
the Release runtime. They remain build inputs that require a future broader build-supply-
chain inventory.

### 5.2 Resolution task

The app Gradle file defines
`:app:writeNightingaleReleaseDependencyResolution`. It:

1. selects `releaseRuntimeClasspath`;
2. queries `configuration.incoming.resolutionResult`;
3. records all external `ModuleComponentIdentifier` values;
4. records every successfully resolved dependency relationship;
5. removes duplicate relationship triples;
6. sorts declarations, components, and edges by stable tuple keys; and
7. writes a structured intermediate JSON report under the ignored Gradle build directory.

The repository generator reads the structured report and embeds it in the canonical
cross-platform record. It does not parse Gradle console decoration, tree glyphs, conflict
markers, or log wording.

### 5.3 Interpretation of counts

The 83-component result does not mean product owners selected 83 first-order libraries. It
contains:

- direct runtime libraries;
- version-alignment platforms/BOMs represented in the resolution graph;
- transitive AndroidX modules;
- Kotlin runtime variants;
- coroutine modules; and
- other transitive modules required by the selected graph.

The 457 edges are deduplicated graph relationships, not 457 unique packages. Components and
edges serve different reconciliation purposes and must not be conflated.

## 6. iOS inspection method

The current iOS application uses XcodeGen source-of-truth configuration. The generator:

1. inspects `nightingale/iosApp/project.yml`;
2. rejects an XcodeGen `packages:` block;
3. rejects a target-level `package:` dependency;
4. rejects `Package.swift`, `Package.resolved`, `Podfile`, `Podfile.lock`, `Cartfile`, or
   `Cartfile.resolved` within the Nightingale iOS root;
5. scans only the application target’s `.swift` files for `import` statements; and
6. records the four current Apple modules.

“Zero iOS third-party runtime packages” therefore means no third-party package-manager
input exists in this bounded source model. It does not mean the compiled app contains zero
Apple framework dependencies, nor does it inspect a signed archive for embedded
third-party binaries.

If Swift Package Manager is later introduced, the inventory design must be extended to
record and verify the checked-in resolved package graph. Apple’s CI guidance explains that
`Package.resolved` stores exact package versions:
[Apple package dependencies in CI](https://developer.apple.com/documentation/xcode/building-swift-packages-or-apps-that-use-them-in-continuous-integration-workflows).

## 7. Generation and verification

### 7.1 Regenerate

Use a JDK 17 runtime:

```bash
JAVA_HOME=<JDK_17_HOME> \
  node scripts/ci/generate-nightingale-foundation-dependency-inventory.mjs .
```

The generator runs Gradle, replaces the canonical JSON, and prints the component summary.
Review the complete JSON delta. Do not commit a regenerated record solely because the
command succeeds.

### 7.2 Verify without resolving dependencies

```bash
node scripts/ci/verify-nightingale-foundation-dependency-inventory.mjs . --self-test
```

The dependency-free verifier checks:

- exact product identity and offline/unapproved release state;
- exact inventory scope and limitation statements;
- exact source-hash key set and current SHA-256 values;
- exact seven direct Android declarations;
- non-empty, unique, deterministically sorted Android component and edge sets;
- required selected component versions;
- referential integrity for every dependency edge;
- internal count reconciliation;
- declared Android/iOS build requirements;
- zero iOS third-party packages and exactly four current Apple imports;
- absence of unrecorded package-manager inputs;
- absence of patient-data, production-access, standards, and live-use claims; and
- non-authorization language.

### 7.3 Negative self-tests

Nine isolated mutations prove that the verifier rejects:

1. a patient-data claim;
2. a production-access claim;
3. a CycloneDX conformance overclaim;
4. a stale source hash;
5. a removed direct Android dependency;
6. a duplicate resolved component;
7. an edge pointing to an unrecorded component;
8. a newly asserted iOS third-party package; and
9. a live-release approval claim.

This is mutation evidence for the verifier’s bounded rules. It is not adversarial
supply-chain testing.

## 8. Supply-chain risk disposition

The generated inventory improves the foundation’s ability to detect and review dependency
drift, but it does not close `THR-SC-001` or `RISK-007` in the threat/hazard model.

In particular, Gradle dependency verification is a separate control. Gradle’s official
guidance states that verification metadata can record checksums/signatures and also warns
that bootstrapping metadata trusts what is present at generation time:
[Gradle dependency verification](https://docs.gradle.org/current/userguide/dependency_verification.html).
No such metadata or trust ceremony is claimed by this work.

Required future supply-chain work includes:

- approved dependency ownership and update cadence;
- artifact checksum/signature verification with independent bootstrap review;
- registry/repository allowlisting and provenance policy;
- build-plugin and CI action inventories;
- continuous vulnerability intake, exploitability assessment, response SLOs, and
  emergency update procedure;
- license/notice review;
- signed iOS archive and Android app-bundle embedded-component inspection;
- reproducibility or independent artifact-verification strategy;
- signing-key, store-account, runner, and release-manifest security;
- unsupported-version and forced-upgrade policy; and
- incident exercises for a compromised dependency, registry, build runner, signing key,
  or store account.

## 9. Change-control rules

Regenerate, review, and obtain fresh exact-SHA CI whenever any of these change:

- a direct Android dependency or dependency configuration;
- a Gradle resolution rule, BOM, repository, plugin, wrapper, Java, or SDK version;
- the resolution-report task;
- the iOS XcodeGen target, package declaration, deployment target, or Swift version;
- an iOS package-manager input or application-target import;
- the generator’s scope or interpretation rules; or
- the product identifier or release-state assertion.

Adding a dependency must not be interpreted as approval to add networking, patient data,
identity, clinical content, messaging, analytics, crash reporting, notifications, or any
other capability. Those changes require their own threat/hazard revision, privacy and
clinical review, contract/authorization evidence, native tests, and release gates.

## 10. Non-authorization statement

This inventory:

- used no patient, patient record, production database, production API, identity, grant,
  session, credential, or private fixture;
- adds no route, network permission, network client, source adapter, disclosure, mutation,
  message, notification, analytics, or crash SDK;
- does not approve any dependency, version, license, repository, vendor, or risk;
- does not assert that the graph is vulnerability-free, tamper-free, reproducible, signed,
  complete for all build configurations, or standards-conformant;
- does not authorize distribution, deployment, migration, pilot, or clinical use; and
- does not replace security, privacy, legal, clinical, accessibility, language, support,
  patient-advisor, release, or operational review.
