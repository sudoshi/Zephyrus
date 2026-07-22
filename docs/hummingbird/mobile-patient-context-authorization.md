# Mobile patient operational-context authorization

## Purpose

The staff-mobile operational context (`GET /api/mobile/v1/patients/{ptok}/operational-context`) is a PHI-minimized disclosure, not a general patient lookup. This document records the server-side authorization boundary shared by Hummingbird, the Zephyrus patient lens, Flow Window patient scopes, and Eddy context packets.

The caller must provide a current opaque `ptok_…` handle. A raw source patient reference is never accepted at the route boundary, policy interface, or staff audit target.

## Evaluation order

`MobilePatientContextAuthorizationService` evaluates these conditions on every disclosure:

1. A staff user is present and its account is active.
2. The requested value is an opaque, current context handle.
3. The handle resolves and the patient still has a current operational context in an authoritative source.
4. The requested Hummingbird persona is still authorized for that user.
5. The ordered named authority paths return one governed allow/deny decision; a matching scoped policy may refuse access rather than falling through to another scope.

The HTTP response stays a generic `403` for a denied disclosure. Precise machine reason codes exist only for governance/audit; they are not client-facing authorization hints.

## Named authority paths

| Policy key                 | Applies to                                            | Allow condition                                                                                         | Allow reason code                     | Denial reason code                                                     |
| -------------------------- | ----------------------------------------------------- | ------------------------------------------------------------------------------------------------------- | ------------------------------------- | ---------------------------------------------------------------------- |
| `broad_mobile_persona`     | Staff with `AssumeAnyMobilePersona`                   | Governed broad-persona capability remains effective                                                     | `broad_mobile_persona_authorized`     | n/a                                                                    |
| `house_operations_persona` | Bed manager, house supervisor, capacity lead          | The still-authorized persona has governed house-operations context scope                                | `house_operations_persona_authorized` | n/a                                                                    |
| `transport_active_task`    | Transport                                             | A nondeleted, nonterminal transport request exists for the context                                      | `transport_task_active`               | `transport_task_not_active`                                            |
| `evs_active_task`          | EVS                                                   | A nondeleted, nonterminal EVS request exists for the context                                            | `evs_task_active`                     | `evs_task_not_active`                                                  |
| `shared_active_unit`       | Charge nurse, bedside nurse, hospitalist, intensivist | A live `prod.user_unit` assignment intersects an active patient unit from encounter, ED, or EVS sources | `shared_active_unit_authorized`       | `patient_unit_assignment_unavailable` or `shared_active_unit_required` |

Other personas receive `patient_context_scope_not_authorized`. Pre-policy denials use the non-PHI codes `staff_authentication_required`, `staff_account_inactive`, `opaque_context_reference_required`, `patient_context_unavailable`, or `mobile_persona_not_authorized`.

## Audit boundary

Every HTTP disclosure decision is written through `UserAuditRecorder` as `mobile.patient_context.access`:

- successful disclosure: `category=access`, `outcome=success`;
- refusal: `category=authorization`, `outcome=denied`;
- `reason`: the machine reason code above;
- target: only an opaque `mobile_patient_context` handle, never a source patient reference;
- metadata: only the safe `policy_key`.

Success is fail-closed: a durable audit event must be recorded before the service returns the context. Denials use the recorder's best-effort path so an unavailable audit sink cannot become a patient-context existence oracle.

The audit writer's clinical-content guard and allowlists reject clinical bodies, free text, credentials, raw identifiers, and unapproved metadata. No event emitted by this policy includes a patient name, MRN, encounter/source identifier, payload, or token.

## Verification

`MobileBackendSafetyTest::test_patient_context_authorization_uses_named_scope_policy_and_safe_audit_reason_codes` proves a permitted house-operations disclosure and a nonoverlapping unit denial. It asserts the exact audit reason/policy target, generic client denial, and absence of the source patient reference from both audit rows and the response.
