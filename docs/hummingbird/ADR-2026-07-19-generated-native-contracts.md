# ADR: Generated native contracts instead of a KMP runtime layer

- **Status:** Accepted
- **Date:** 2026-07-19
- **Decision owners:** Hummingbird Platform and Zephyrus API Architecture
- **Applies to:** Hummingbird Staff and Hummingbird Patient

## Context

Hummingbird has mature, platform-native SwiftUI and Jetpack Compose applications. The
clients currently duplicate request models, DTOs, enum interpretation, error mapping,
authorization presentation, and parts of cache/sync behavior. Earlier planning selected a
Kotlin Multiplatform domain/data layer, but no KMP module exists in the repository.

Introducing KMP now would add a third runtime/build boundary before the current API and
behavioral drift is controlled. It would also require migrating working Swift and Kotlin
networking, persistence, and state-management code before delivering the separately secured
patient product.

The non-negotiable requirement is semantic parity—not a particular shared-runtime
technology.

## Decision

Use versioned OpenAPI contracts and generated platform-native artifacts as the primary
cross-platform sharing mechanism.

1. Laravel route and contract tests remain the executable server boundary.
2. OpenAPI generates Swift `Codable` and Kotlin serialization request/response types,
   operation identifiers, errors, and enum wrappers.
3. Role catalogs, status vocabulary, urgency vocabulary, design tokens, and deterministic
   fixtures remain language-neutral source artifacts and generate native constants where
   appropriate.
4. View models, navigation, accessibility behavior, widgets, Live Activities, notification
   integrations, protected storage, and platform persistence remain native.
5. Shared business invariants are expressed as server policy, contract constraints,
   generated fixtures, and platform conformance tests. They are not manually copied prose.
6. Unknown additive enum values must decode into an explicit safe fallback rather than
   crashing or silently becoming an actionable state.
7. Generated code is never hand-edited. Generator version, input hash, and output freshness
   are CI-controlled.

No KMP runtime module will be introduced during the parity-foundation or initial patient
phases.

## Why this option

- It moves the existing native applications toward one semantic source without a wholesale
  rewrite.
- Generated artifacts can be reviewed and tested independently on each platform.
- Patient and staff contracts can remain strictly separated while sharing the same generation
  toolchain.
- It preserves platform-native security, accessibility, background execution, widget, and
  lifecycle behavior.
- It makes contract drift visible before network calls reach production.

## Required controls

- A breaking-change comparison against the last released contract.
- Three-language fixture decode tests for PHP, Swift, and Kotlin.
- Generated-file freshness checks in CI.
- Contract extensions for authorization, data classification, idempotency, error behavior,
  freshness, and cache class.
- Native tests for transformations that are intentionally not generated.
- Separate generated modules/namespaces for Staff and Patient; patient binaries must not link
  staff operations.

## Consequences

### Positive

- Lower migration risk and faster incremental parity closure.
- Native teams retain idiomatic tooling and platform APIs.
- Staff/patient privilege separation is visible at compile and link time.
- Contract review becomes the shared-domain review point.

### Negative

- Some cache/outbox and session coordinator code remains implemented twice.
- Generator governance and safe unknown-value behavior become critical.
- Cross-platform tests must prove semantics rather than relying on shared runtime code.

## Revisit criteria

Reconsider KMP only if all of the following are true:

- generated contracts and fixture parity have been operating successfully for at least two
  release cycles;
- a measured defect pattern shows repeated divergence in deterministic non-UI logic;
- the proposed shared module has a narrow API, no platform credential ownership, and a
  demonstrated Swift interop plan;
- migration does not combine staff and patient privilege/data modules;
- both native platform owners approve the operational cost.

## Verification

- The capability ledger records this ADR as the architecture direction.
- The first generated-client PR must include a generator lock, output freshness CI, additive
  and unknown-enum fixtures, and both native build gates.
