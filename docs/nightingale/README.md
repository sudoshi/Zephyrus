# Nightingale documentation

Nightingale is the dedicated inpatient-facing Zephyrus product. This directory will hold
only its product-owned contract, safety evidence, release evidence, and migration lineage.

## Current state

The 2026-07-26 foundation has independent iOS and Android application identifiers and a
branded, patient-safe shell. It intentionally has no live patient access, network
permission, authentication, clinical projection, care-team messaging, or production
activation. The source of record is the
[Nightingale product plan](../plans/nightingale-patient-product-2026-07-26.md) and its
[execution log](../devlog/DEVLOG-nightingale-patient-product-2026-07-26.md).
The bounded 255-source Hummingbird Patient predecessor universe is fully classified across
three checksum-pinned ledgers; this is migration evidence, not implementation approval. A
held Today candidate now defines field-level release, freshness, uncertainty, language,
correction, and offline semantics across 68 synthetic outcomes while the executable
contract remains empty. Nightingale now also has two device-local display-comfort controls:
reduced motion and decorative-imagery suppression. They are presentation-only, remain
separate from care-account preferences, and do not authorize patient access. The current
offline shell now also has a bounded maximum-text, landscape, semantic-order, target-size,
and light/dark contrast matrix; this does not establish product-wide WCAG conformance or
human approval. A draft foundation threat and clinical-hazard model now versions the
current trust boundaries, implemented controls, 22 security/privacy threats, 22 clinical
hazards, 20 activation gates, incident requirements, and open risks. It is not a safety
case, compliance claim, residual-risk acceptance, or live-use approval. A generated
foundation dependency inventory now binds seven direct Android Release runtime
declarations, 83 resolved components, 457 dependency edges, and the current zero-package
iOS application target to exact source hashes. It is not a standards-conformant SBOM,
vulnerability/provenance assessment, or supply-chain approval.

Current product-identity records:

- [Product identity and support naming checklist](./PRODUCT-IDENTITY-AND-SUPPORT-NAMING-CHECKLIST-2026-07-26.md)
- [Legacy migration classification](./MIGRATION-CLASSIFICATION-2026-07-26.md)
- [Identity, recovery, and protected-state decisions](./IDENTITY-RECOVERY-AND-PROTECTED-STATE-DECISIONS-2026-07-26.md)
- [Contract ownership and authorization matrix](./CONTRACT-OWNERSHIP-AND-AUTHORIZATION-MATRIX-2026-07-26.md)
- [Patient-state vocabulary classification](./PATIENT-STATE-VOCABULARY-CLASSIFICATION-2026-07-26.md)
- [Encounter-access held-candidate decision](./ENCOUNTER-ACCESS-CANDIDATE-DECISION-2026-07-26.md)
- [Today projection held-candidate decision](./TODAY-PROJECTION-CANDIDATE-DECISION-2026-07-26.md)
- [Route, compatibility, identity, and inpatient-source ADR](./ROUTE-COMPATIBILITY-IDENTITY-SOURCE-ADR-2026-07-26.md)
- [Identity, session, recovery, and inpatient-source held-candidate decision](./IDENTITY-SESSION-RECOVERY-AND-SOURCE-CANDIDATE-DECISION-2026-07-26.md)
- [Identity-input, enrollment/recovery, first-read, and error source classification](./IDENTITY-INPUT-FIRST-READ-ERROR-SOURCE-CLASSIFICATION-2026-07-26.md)
- [Communication and notification source classification](./COMMUNICATION-AND-NOTIFICATION-SOURCE-CLASSIFICATION-2026-07-26.md)
- [Journey, preference, presentation, synthetic, and release source classification](./JOURNEY-PREFERENCE-PRESENTATION-RELEASE-SOURCE-CLASSIFICATION-2026-07-26.md)
- [Presentation-preferences foundation decision and evidence](./PRESENTATION-PREFERENCES-FOUNDATION-DECISION-2026-07-26.md)
- [Foundation accessibility and layout matrix](./FOUNDATION-ACCESSIBILITY-LAYOUT-MATRIX-2026-07-26.md)
- [Draft foundation threat and clinical-hazard model](./FOUNDATION-THREAT-AND-HAZARD-MODEL-2026-07-26.md)
- [Foundation dependency inventory decision and evidence](./FOUNDATION-DEPENDENCY-INVENTORY-2026-07-26.md)
- [Generated foundation dependency inventory](./supply-chain/foundation-dependency-inventory.v0.json)
- [Empty/default-off contract foundation](./api-contract/nightingale-foundation.v0.json)
- [Identity candidate and fixtures](./identity/candidates/v0/candidate.json)
- [Current-inpatient source candidate and fixtures](./source-candidates/current-inpatient/v0/candidate.json)
- [Today projection candidate and fixtures](./api-contract/candidates/today/v0/candidate.json)
- [65-file checksum-pinned source ledger](./migration/candidates/v0/source-classification.json)
- [130-file communication/notification checksum ledger](./migration/candidates/v0/communication-notification-source-classification.json)
- [133-file journey/preference/presentation/release checksum ledger](./migration/candidates/v0/journey-preference-presentation-release-source-classification.json)
- [Launcher, themed-icon, and splash evidence](../evidence/nightingale/brand-identity-2026-07-26/README.md)

## Lineage and filing rules

- The prior Hummingbird Patient native targets and patient contract remain immutable,
  governed migration inputs. They are neither renamed nor shipped as Nightingale.
- The `0.0.0-governance` contract under `api-contract/` is deliberately empty and
  non-routable. An operation may be added only after its owner, compatibility decision,
  authorization/non-disclosure matrix, fixtures, and review evidence are defined.
- Candidate artifacts under `api-contract/candidates/`, `identity/candidates/`,
  `source-candidates/`, and `migration/candidates/` are non-runnable decision/fixture
  evidence. They do not add a path, bind a provider or source adapter, permit client
  generation or a source query, or authorize implementation.
- The three migration ledgers now mechanically cover all 255 tracked sources in the bounded
  legacy Hummingbird Patient product universe. Complete classification means every source
  has an evidence disposition; it does not mean any source is approved to migrate.
- Add clinical, content, privacy, accessibility, and release evidence under `safety/` only
  with a dated reviewer/approval record. Draft evidence must say that it is draft.
- Never store patient information, credentials, tokens, production fixtures, or private
  contact information in this directory.
