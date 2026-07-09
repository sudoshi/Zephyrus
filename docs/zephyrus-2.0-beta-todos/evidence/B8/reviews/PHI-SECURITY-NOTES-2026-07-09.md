# PHI And Security Notes - 2026-07-09

Scope: local code review and automated test evidence for the implemented B4-B7 slice.

## Pass Evidence

- Patient Flow occupancy history redacts patient and encounter identifiers for aggregate lenses.
- Mobile list and context tests continue to enforce raw patient-ref suppression in broad payloads.
- Eddy scoped agent tokens remain draft-only and cannot self-approve, including the misissued `ops:approve` token regression.
- Admin integration read/write routes now have explicit permission gates.
- Android backup, device transfer, and cleartext network settings are hardened for demo scope.
- Mobile activity ledger writes are replay-safe for repeated idempotency keys.

## Not Yet Complete

- Screenshot PHI review has not been performed.
- Push notification PHI review has not been performed.
- Eddy prompt/output prompt-injection and cloud-model policy review remain B5/B8 work.
- Production headers, CORS, CSP, Reverb origin, scheduler, and queue posture still require B8 runtime review.
