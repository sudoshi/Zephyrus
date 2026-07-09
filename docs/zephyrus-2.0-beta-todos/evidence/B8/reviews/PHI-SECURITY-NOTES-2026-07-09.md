# PHI And Security Notes - 2026-07-09

Scope: local code review and automated test evidence for the implemented B4-B7 slice.

## Pass Evidence

- Patient Flow occupancy history redacts patient and encounter identifiers for aggregate lenses.
- Patient Flow occupancy history now strips raw `patient_ref`, `patient_id`, `patient_display_id`, `encounter_id`, `encounter_ref`, and downstream patient refs from returned detail rows, using `ptok_` context refs where detail is allowed.
- Mobile list and context tests continue to enforce raw patient-ref suppression in broad payloads.
- Eddy scoped agent tokens remain draft-only and cannot self-approve, including the misissued `ops:approve` token regression.
- Eddy web proposal/token routes and mobile approval decisions now enforce minimum role eligibility in tested paths.
- Mobile RTDC, transport, EVS, and staffing writes now require the matching mobile persona instead of relying on broad `mobile:act` alone.
- Admin integration read/write routes now have explicit permission gates.
- OR case write routes now require the `writeOrCases` gate and map writes to the current `prod.or_cases`/`prod.or_logs` schema.
- Android backup, device transfer, and release cleartext network settings are hardened for demo scope.
- iOS query encoding was tightened for reserved characters in mobile API requests.
- Mobile activity ledger writes are replay-safe for repeated idempotency keys.
- Login failed-auth errors now render the `errors.general` response returned by the backend.

## Not Yet Complete

- Screenshot PHI review has not been performed.
- Push notification PHI review has not been performed.
- Eddy prompt/output prompt-injection and cloud-model policy review remain B5/B8 work.
- Production headers, CORS, CSP, Reverb origin, scheduler, and queue posture still require B8 runtime review.
- Concurrent duplicate idempotency submissions still need database-level atomic hardening beyond the tested sequential replay path.
