# Nightingale generic non-disclosure foundation

**Date:** 2026-07-27  
**Status:** Implemented, route-free safety foundation; not an operation, authorization,
clinical release, or production approval  
**Applies to:** the future Nightingale patient API boundary only  
**Does not apply to:** Hummingbird staff, the legacy Hummingbird Patient reference apps, or
any existing Zephyrus route

## 1. Decision

Nightingale now owns a small, pure domain gate that proves one bounded anti-enumeration
property before any patient operation exists:

> An unknown, revoked, expired, cross-principal, wrong-encounter, omitted-resource, or
> failed-upstream-precondition request produces the same public disposition and the same
> complete public failure tuple.

The exact public failure tuple is:

| Field           | Exact value                    |
| --------------- | ------------------------------ |
| `status`        | `404`                          |
| `code`          | `not_found`                    |
| `cache_control` | `private, no-store, max-age=0` |

It contains no patient copy, internal reason, identifier, redirect, retry hint, source
state, relationship state, grant state, or variable field.

Only one combination may advance:

1. the existing identity/current-inpatient prerequisites returned
   `continue_to_governed_evaluation`;
2. the request-scoped relationship is `active`;
3. the opaque request handle matches the current context; and
4. the requested resource is released.

Advancing means only `continue_to_governed_projection_evaluation`. It is deliberately not
named or treated as authorization. Source integrity, operation policy, field release,
freshness, uncertainty, language, correction/retraction, audit-before-disclosure, and the
serialization-boundary recheck remain mandatory later gates.

## 2. Why this is a foundation rather than a route

The Nightingale executable OpenAPI contract still has zero paths. The repository still
registers no Nightingale route, controller, middleware, service provider binding, identity
provider, source adapter, database query, native network client, patient projection, or
mutation. Creating a live endpoint now would incorrectly collapse unresolved identity,
source, clinical-content, patient-language, privacy, accessibility, operational, and
release decisions.

The domain gate is therefore:

- identifier-free;
- route-free;
- framework-independent;
- side-effect-free;
- unable to query a patient source;
- unable to emit an audit event;
- unable to return patient content; and
- unreachable from any application request.

This design permits exhaustive proof of the state-collapse invariant without implying that
the surrounding product has been authorized or implemented.

## 3. Inputs and deliberately bounded vocabularies

### 3.1 Existing precondition disposition

| State                             | May advance? | Meaning at this gate                                                      |
| --------------------------------- | ------------ | ------------------------------------------------------------------------- |
| `withhold`                        | No           | Identity or current-inpatient prerequisite did not pass                   |
| `continue_to_governed_evaluation` | Yes          | Both prerequisite ports were explicitly positive; later gates still apply |

The existing precondition gate only reaches the positive state when the independent
Nightingale identity state is `verified_self` and the current-inpatient source state is
`confirmed_current`.

### 3.2 Relationship state

| State             | May advance? | Public distinction permitted? |
| ----------------- | ------------ | ----------------------------- |
| `unknown`         | No           | No                            |
| `active`          | Yes          | Not applicable                |
| `revoked`         | No           | No                            |
| `expired`         | No           | No                            |
| `cross_principal` | No           | No                            |

These values carry no principal, patient, grant, representative, or source identifier.
They are request-scoped results expected from a future approved authorization adapter.

### 3.3 Encounter binding state

| State                     | May advance? | Public distinction permitted? |
| ------------------------- | ------------ | ----------------------------- |
| `matches_current_context` | Yes          | Not applicable                |
| `wrong_encounter`         | No           | No                            |

The state is evaluated after a future adapter resolves an opaque Nightingale handle. The
domain seam does not accept or expose that handle or a source encounter identifier.

### 3.4 Resource state

| State      | May advance? | Public distinction permitted? |
| ---------- | ------------ | ----------------------------- |
| `released` | Yes          | Not applicable                |
| `omitted`  | No           | No                            |

`omitted` is intentionally the public-boundary collapse for unknown, absent, closed,
withheld, unreleased, retracted, or otherwise non-disclosable resources. The distinction
between those internal causes may be retained only as a bounded code in a future approved,
content-free audit record. It must never alter the public tuple.

## 4. Exhaustive truth-table proof

The implementation evaluates the Cartesian product:

- 2 precondition dispositions;
- 5 relationship states;
- 2 encounter-binding states; and
- 2 resource states.

That produces exactly 40 combinations:

| Outcome                                      | Combination count |
| -------------------------------------------- | ----------------- |
| `continue_to_governed_projection_evaluation` | 1                 |
| `withhold_not_found`                         | 39                |
| Total                                        | 40                |

The sole continuing row is:

| Preconditions                     | Relationship | Encounter binding         | Resource   | Result                                           |
| --------------------------------- | ------------ | ------------------------- | ---------- | ------------------------------------------------ |
| `continue_to_governed_evaluation` | `active`     | `matches_current_context` | `released` | Continue to later governed projection evaluation |

Every other permutation returns `withhold_not_found` and the exact three-field public
failure tuple.

### 4.1 Named plan cases

The PHPUnit suite contains an explicit, readable case for each plan requirement:

| Required case    | Differing input                     | Exact public result |
| ---------------- | ----------------------------------- | ------------------- |
| Unknown          | relationship `unknown`              | Generic 404 tuple   |
| Revoked          | relationship `revoked`              | Generic 404 tuple   |
| Expired          | relationship `expired`              | Generic 404 tuple   |
| Cross-principal  | relationship `cross_principal`      | Generic 404 tuple   |
| Wrong encounter  | encounter binding `wrong_encounter` | Generic 404 tuple   |
| Omitted resource | resource `omitted`                  | Generic 404 tuple   |
| Upstream denial  | preconditions `withhold`            | Generic 404 tuple   |

The independent CI verifier repeats the full 40-row truth table rather than trusting the
PHPUnit assertions alone. It also pins the exact enum vocabularies, the one/39
cardinalities, and the public tuple.

## 5. Evaluation order and race boundary

The implemented order is:

1. prerequisite disposition;
2. relationship eligibility;
3. current-context binding;
4. resource release eligibility; and
5. continue or generically withhold.

The order is not intended to expose which check failed. No caller exists, and the returned
value has no reason field. When a live operation is later proposed, it must add:

- equivalent material work or another reviewed timing-control strategy for existence-
  sensitive equivalence classes;
- rate-limit and abuse controls that do not become an oracle;
- a fresh authorization and source-lifecycle recheck immediately before serialization;
- atomic response withholding if the context, relationship, resource, or release changes;
- no-store/no-index response enforcement at the real HTTP edge; and
- response-length, header, redirect, retry, and cache equivalence tests.

Those runtime properties are not claimed by this route-free foundation.

## 6. Audit boundary

The gate does not log. This is intentional: introducing an audit writer before event
ownership, retention, access control, availability behavior, and schema are approved would
create an ungoverned patient-security data set.

A future operation must separate:

- a stable public code (`not_found`);
- an internal bounded diagnostic reason code;
- a durable, content-free request evaluation event where required;
- a durable, content-free disclosure event before successful content release; and
- operational diagnostics that contain no raw handle, source identifier, patient value,
  staff actor, free text, credential, or stack material.

A denial-audit outage must not make known resources behave differently from unknown ones.
An audit-before-disclosure outage must fail closed.

## 7. Source inventory

| Source                                                               | Responsibility                                                                              |
| -------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| `app/Nightingale/Disclosure/NightingaleRelationshipState.php`        | Exact relationship vocabulary; only `active` may advance                                    |
| `app/Nightingale/Disclosure/NightingaleEncounterBindingState.php`    | Exact current-context match vocabulary                                                      |
| `app/Nightingale/Disclosure/NightingaleResourceState.php`            | Exact released/omitted resource vocabulary                                                  |
| `app/Nightingale/Disclosure/NightingaleDisclosureDisposition.php`    | Exact generic public failure tuple and non-authorizing continue disposition                 |
| `app/Nightingale/Disclosure/NightingaleGenericNonDisclosureGate.php` | Pure all-positive conjunction and generic collapse                                          |
| `tests/Unit/Nightingale/NightingaleBackendFoundationTest.php`        | Named plan cases and complete 40-row truth table                                            |
| `scripts/ci/verify-nightingale-backend-foundation.php`               | Dependency-free independent vocabulary, tuple, cardinality, and runtime-registration checks |

No Hummingbird namespace, legacy patient identifier, route, controller, data model, source
schema, framework response, or database dependency is imported.

## 8. Verification

Run from the repository root:

```bash
./vendor/bin/pint --test \
  app/Nightingale/Disclosure \
  tests/Unit/Nightingale/NightingaleBackendFoundationTest.php \
  scripts/ci/verify-nightingale-backend-foundation.php

php artisan test --filter=NightingaleBackendFoundationTest

php scripts/ci/verify-nightingale-backend-foundation.php --self-test

node scripts/ci/verify-nightingale-contract-foundation.mjs --self-test
node scripts/ci/verify-nightingale-encounter-access-candidate.mjs --self-test
node scripts/ci/verify-nightingale-today-candidate.mjs --self-test
```

The test suite must prove:

- 21 PHPUnit cases and 111 assertions for the current combined backend foundation;
- all seven named non-disclosure cases return the same disposition and public tuple;
- exactly one of the 40 disclosure permutations continues;
- the other 39 permutations withhold;
- the unconfigured identity/source ports still fail closed;
- no Nightingale route or runtime binding exists; and
- the executable contract still contains zero paths and all activation fields remain
  false.

Counts are evidence for the current source, not a waiver for reviewing future test changes.

## 9. What this completion does and does not satisfy

This implementation satisfies the bounded Stream D checklist requirement to prove generic
non-disclosure for unknown, revoked, expired, cross-principal, wrong-encounter, and
omitted-resource requests at the route-free Nightingale domain boundary.

It does not satisfy or approve:

- a Nightingale identity provider or recovery flow;
- an encounter/source adapter or production query;
- an opaque-handle generator, store, rotation policy, or collision design;
- a patient route, controller, middleware chain, or native API client;
- clinical-content or patient-language release;
- Today, My Path, Care Team, education, discharge, communication, or representative access;
- audit storage, monitoring, timing-equivalence, rate-limit, load, penetration, or
  production-like integration evidence;
- a real HTTP response or client error-state mapping;
- patient, clinical-safety, accessibility, privacy/security, language, legal/HIM,
  operational, or release approval;
- pilot enrollment, distribution, production activation, or deployment; or
- production database access or mutation.

Any later operation must preserve this state-collapse property and add operation-specific
authorization, audit, race, serialization, client, and HTTP-edge proof before the
executable contract gains a path.
