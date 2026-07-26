# Nightingale

Nightingale is Zephyrus' independent patient-facing product. It is not a Hummingbird mode, route, or authenticated surface.

The initial native applications are intentionally safe shells: they demonstrate product identity and the patient-facing tone while containing no network permission, patient data access, authentication, care-team messaging, or staff-service dependency. Those capabilities may be introduced only through the governance, consent, threat-model, clinical-content, and controlled-pilot gates in [the product plan](../docs/plans/nightingale-patient-product-2026-07-26.md).

## Native roots

- `iosApp/` — iOS 17+ SwiftUI application, bundle identifier `net.acumenus.nightingale`.
- `androidApp/` — Android application, package and application identifier `net.acumenus.nightingale`.
- `brand/` — immutable source artwork and icon provenance.

## Non-negotiable product boundary

Nightingale must never import Hummingbird staff modules, share a staff application identifier, use a staff endpoint, or expose a copied staff workflow to a patient. The legacy Hummingbird Patient applications remain read-only migration evidence; new patient work belongs here.
