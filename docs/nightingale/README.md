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

Current product-identity records:

- [Product identity and support naming checklist](./PRODUCT-IDENTITY-AND-SUPPORT-NAMING-CHECKLIST-2026-07-26.md)
- [Legacy migration classification](./MIGRATION-CLASSIFICATION-2026-07-26.md)
- [Identity, recovery, and protected-state decisions](./IDENTITY-RECOVERY-AND-PROTECTED-STATE-DECISIONS-2026-07-26.md)
- [Contract ownership and authorization matrix](./CONTRACT-OWNERSHIP-AND-AUTHORIZATION-MATRIX-2026-07-26.md)
- [Patient-state vocabulary classification](./PATIENT-STATE-VOCABULARY-CLASSIFICATION-2026-07-26.md)
- [Encounter-access held-candidate decision](./ENCOUNTER-ACCESS-CANDIDATE-DECISION-2026-07-26.md)
- [Empty/default-off contract foundation](./api-contract/nightingale-foundation.v0.json)
- [Launcher, themed-icon, and splash evidence](../evidence/nightingale/brand-identity-2026-07-26/README.md)

## Lineage and filing rules

- The prior Hummingbird Patient native targets and patient contract remain immutable,
  governed migration inputs. They are neither renamed nor shipped as Nightingale.
- The `0.0.0-governance` contract under `api-contract/` is deliberately empty and
  non-routable. An operation may be added only after its owner, compatibility decision,
  authorization/non-disclosure matrix, fixtures, and review evidence are defined.
- Candidate artifacts under `api-contract/candidates/` are non-runnable decision/fixture
  evidence. They do not add a path, reserve a namespace, permit client generation, or
  authorize implementation.
- Add clinical, content, privacy, accessibility, and release evidence under `safety/` only
  with a dated reviewer/approval record. Draft evidence must say that it is draft.
- Never store patient information, credentials, tokens, production fixtures, or private
  contact information in this directory.
