# Nightingale

Nightingale is Zephyrus' independent patient-facing product. It is not a Hummingbird mode, route, or authenticated surface.

The initial native applications are intentionally safe shells: they demonstrate product identity and the patient-facing tone while containing no network permission, patient data access, authentication, care-team messaging, or staff-service dependency. Those capabilities may be introduced only through the governance, consent, threat-model, clinical-content, and controlled-pilot gates in [the product plan](../docs/plans/nightingale-patient-product-2026-07-26.md).

## Native roots

- `iosApp/` — iOS 17+ SwiftUI application, bundle identifier `net.acumenus.nightingale`.
- `androidApp/` — Android application, package and application identifier `net.acumenus.nightingale`.
- `brand/` — immutable source artwork and icon provenance.
- `backgrounds/` — the governed seven-image decorative background catalog, its immutable
  source/derivative lineage, and the exact shared iOS/Android app derivatives.

The independent iOS release-tooling boundary is documented in
[Nightingale TestFlight and iOS distribution](../docs/nightingale/TESTFLIGHT.md). The shared
repository helper maps Nightingale only to `iosApp/`; the historical Hummingbird Patient
target is not a release source.

Both native apps select the same background for the patient’s local Gregorian date, keep
the photograph out of accessibility semantics, and preserve a complete text-only
experience when imagery is hidden. See
[the background governance record](../docs/nightingale/BACKGROUND-ASSET-GOVERNANCE-AND-NATIVE-INTEGRATION-2026-07-26.md).
The assets are foundation-only until release rights/attribution and named human review are
recorded.

A machine-verified
[patient-journey reference catalog](../docs/nightingale/PATIENT-JOURNEY-REFERENCE-SCENARIO-CANDIDATE-DECISION-2026-07-27.md)
defines 15 cross-surface journey families through 27 synthetic cases. It is held,
non-runnable governance evidence: it adds no route, client, patient data, clinical content,
communication capability, notification provider, or production permission.

## Non-negotiable product boundary

Nightingale must never import Hummingbird staff modules, share a staff application identifier, use a staff endpoint, or expose a copied staff workflow to a patient. The legacy Hummingbird Patient applications remain read-only migration evidence; new patient work belongs here.
