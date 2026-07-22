# Hummingbird client error taxonomy (plan §4.2)

One taxonomy for every error a Hummingbird client must reason about. Clients
branch on the **category**, not on the open-ended list of per-surface leaf codes.
Leaf wire codes remain (they carry human copy); `app/Http/Errors/ErrorCatalog.php`
classifies each into one `app/Http/Errors/ErrorCategory.php` case. Guarded by
`tests/Feature/Hummingbird/ErrorTaxonomyTest.php`.

| Category                    | Typical HTTP | Meaning                                                            | Client behavior                              |
| --------------------------- | ------------ | ------------------------------------------------------------------ | -------------------------------------------- |
| `unauthenticated`           | 401          | No/again-invalid identity (bad/expired/absent credential)          | Re-authenticate; do not retry blindly        |
| `unauthorized`              | 403          | Authenticated but wrong realm/scope/ability                        | Do not retry; surface access message         |
| `forbidden_by_relationship` | 403 (or 404) | Resource not disclosable to this relationship/grant (IDOR-safe)    | Do not retry; never treat 404 as "gone"      |
| `stale_version`             | 409          | Optimistic-concurrency conflict; caller's version is behind        | Refresh, re-read, let the user re-decide     |
| `invalid_transition`        | 409          | Illegal state transition (closed/assigned/response-required)       | Refresh state; do not force                  |
| `rate_limited`              | 429          | Throughput/limit exceeded                                          | Back off with jitter; retry idempotent reads |
| `offline`                   | — (client)   | No connectivity (client-emitted; not a server wire code)           | Queue idempotent intent; show offline status |
| `server_unavailable`        | 503          | Server/dependency temporarily unavailable                          | Back off; retry idempotent reads only        |
| `contract_mismatch`         | 422          | Request/replay/media shape invalid (validation, idempotency, size) | Fix request; do not retry unchanged          |

Only `server_unavailable`, `offline`, and `rate_limited` permit automatic retry of
idempotent **reads** (`ErrorCategory::retryableRead()`). The patient and staff
patient-communication surfaces (closed code sets) are fully classified; broader
staff-mobile surfaces adopt the catalog incrementally.
