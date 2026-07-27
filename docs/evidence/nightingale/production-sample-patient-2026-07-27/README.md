# Nightingale production sample-patient evidence

**Date:** 2026-07-27  
**Environment:** `pgsql.acumenus.net`, database `zephyrus`  
**Operator role:** `smudoshi`  
**Authorization:** explicit user direction to create a Nightingale sample patient from the
deprecated Hummingbird Patient reference patient  
**Classification:** synthetic operational evidence; no real patient data or enrollment
material  
**Product activation:** none

No password, token, encrypted value, keyed digest, challenge hash, application key, patient
contact value, or patient-authored/clinical content is retained in this evidence.

## 1. Outcome

Production now contains one Nightingale-owned synthetic sample with two deliberately
separate records:

| Layer                 | Stable lookup marker                                                                                                 | State                                                                                                      |
| --------------------- | -------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| Operational encounter | `patient_ref = demo-nightingale-reference-inpatient` and `created_by = nightingale-reference-patient-provisioner-v1` | active synthetic encounter, unit 85, no bed, acuity 2, no discharge timestamp, not deleted                 |
| Patient principal     | `preferences.provisioning.owner = nightingale-reference-patient-provisioner-v1`                                      | patient principal, display name `Nightingale Reference Patient`, pending, inactive, no contact or password |

The principal is intentionally not identity-linked or encounter-authorized. This sample
therefore cannot sign in, receive a session, enumerate a context, read a projection, send a
message, register a notification device, or activate Nightingale.

## 2. Hummingbird source-template use

The existing Hummingbird reference patient was used only as a safe synthetic template:

- one provisioner-owned active operational reference encounter existed;
- one pending/inactive Hummingbird reference principal existed;
- the principal had no email, phone, or password;
- its locale and timezone were `en-US` and `America/New_York`;
- its operational sample used unit 85, no bed, acuity 2, and active/not-discharged state;
- its identity link, grant, challenge material, external UUIDs, keyed digests, encrypted
  source references, projection content, and product-owner strings were not copied.

The Hummingbird source records were preserved unchanged. Post-write reconciliation found
exactly one Hummingbird operational encounter, principal, identity link, and access grant
under their original owner markers.

## 3. Read-only preflight

The production session was first forced read-only. Metadata-only checks established:

| Check                                      | Result                                                |
| ------------------------------------------ | ----------------------------------------------------- |
| Connected database/user                    | expected database and operator                        |
| Transaction mode                           | read-only                                             |
| `prod.encounters` target patient reference | 0 rows                                                |
| Nightingale encounter owner marker         | 0 rows                                                |
| Nightingale principal owner marker         | 0 rows                                                |
| Nightingale identity-link owner marker     | 0 rows                                                |
| Nightingale grant owner marker             | 0 rows                                                |
| Selected unit 85                           | exists and is not deleted                             |
| Hummingbird template principal             | exactly 1, pending/inactive, contactless/passwordless |
| Insert/sequence privileges                 | present                                               |
| User-defined `prod.encounters` triggers    | none                                                  |

The preflight queried only schema metadata and exact synthetic owner/reference markers. It
did not enumerate real patients.

## 4. Encounter transaction

The operational insert used one serializable transaction with:

- `ON_ERROR_STOP`;
- a 15-second statement timeout;
- a 5-second lock timeout;
- a transaction-scoped advisory lock on the Nightingale provisioner owner;
- exact target-reference and owner-marker zero-cardinality checks;
- an active/non-deleted unit-85 check;
- explicit values for every operational field; and
- a pre-commit exact-row read.

Any existing target reference, existing owner record, missing/deleted unit, constraint
failure, timeout, or cardinality drift raised an exception and rolled back the complete
transaction.

The committed encounter has:

| Field                        | Value                                          |
| ---------------------------- | ---------------------------------------------- |
| `patient_ref`                | `demo-nightingale-reference-inpatient`         |
| `unit_id`                    | 85 (`5 East — Medical/Surgical`)               |
| `bed_id`                     | null                                           |
| `acuity_tier`                | 2                                              |
| `status`                     | `active`                                       |
| `discharged_at`              | null                                           |
| `expected_discharge_date`    | 2026-07-29                                     |
| `created_by` / `modified_by` | `nightingale-reference-patient-provisioner-v1` |
| `is_deleted`                 | false                                          |

The expected-discharge date is sample data, not a clinical prediction and not an automatic
retirement mechanism.

## 5. Principal clone transaction

The principal insert used a second serializable transaction under the same advisory lock.
It generated a fresh UUIDv7 and selected only safe attributes from the exact Hummingbird
template row:

- `principal_type`;
- locale; and
- timezone.

All other values were independently set for Nightingale. The transaction required:

- exactly one Hummingbird source-template principal;
- exactly one committed Nightingale synthetic encounter;
- no pre-existing Nightingale principal owner marker;
- source principal type `patient`;
- source state pending/inactive;
- null source contact and password values; and
- exact post-insert Nightingale principal cardinality and safety properties.

The committed principal has:

| Field                               | Value                                          |
| ----------------------------------- | ---------------------------------------------- |
| `principal_type`                    | `patient`                                      |
| `display_name`                      | `Nightingale Reference Patient`                |
| `status` / `is_active`              | `pending` / false                              |
| `email` / `phone_e164` / `password` | null / null / null                             |
| `preferences.synthetic`             | true                                           |
| `preferences.product`               | `nightingale`                                  |
| `preferences.provisioning.owner`    | `nightingale-reference-patient-provisioner-v1` |
| `preferences.provisioning.mode`     | `operator-authorized-production-sample-clone`  |
| source-template label               | `hummingbird_patient`                          |
| locale / timezone                   | `en-US` / `America/New_York`                   |

No source identifier, encrypted subject, keyed digest, encounter foreign key, grant, or
credential was added to the principal.

## 6. Independent post-write reconciliation

A fresh read-only connection verified this exact result:

| Invariant                                                                             | Count/result  |
| ------------------------------------------------------------------------------------- | ------------- |
| Nightingale target encounter rows                                                     | 1             |
| Rows satisfying every encounter ownership/state constraint                            | 1             |
| Nightingale owner principals                                                          | 1             |
| Principals satisfying every synthetic/product/pending/inactive/contactless constraint | 1             |
| Nightingale principal identity links                                                  | 0             |
| Nightingale principal encounter grants                                                | 0             |
| Nightingale principal enrollment challenges                                           | 0             |
| Nightingale principal sessions                                                        | 0             |
| Nightingale principal access-audit events                                             | 0             |
| Nightingale principal notification devices                                            | 0             |
| Hummingbird template encounter/principal/identity/grant cardinalities                 | 1 / 1 / 1 / 1 |

This proves bounded creation and source preservation. It does not prove that a Nightingale
identity, source, authorization, projection, or native client is ready.

## 7. Operational boundary and follow-up

The sample is not a pilot cohort member and must never be confused with a real patient.
Specifically:

- the Nightingale executable contract still has zero paths;
- no Laravel route or container binding can reach the sample;
- native Nightingale remains offline and no-data;
- no clinical or patient-authored content was cloned;
- no relationship or representative access exists;
- no content release or communication capability exists; and
- no feature flag, source adapter, migration, application deployment, or pilot activation
  occurred.

Retirement was not authorized or performed. A future teardown must use the exact owner and
patient-reference markers, prove one/one cardinality and zero dependent identity/session/
grant rows, and run in its own explicitly authorized transaction. The operational owner
must also decide whether the sample should be refreshed, soft-deleted, or removed after its
verification purpose ends; `expected_discharge_date` alone does not perform cleanup.
