# Admin Clinical Payload Protection Runbook

**Status:** Branch implementation procedure. Production enablement, backfill, destructive retention, partitioning, and cutover require institution-specific approval and retained release evidence.

**Scope:** Encrypted clinical-payload storage, immutable key authority, migration coverage, restore verification, retention, legal hold, quarantine, and minimum-necessary Admin evidence.

## Non-negotiable controls

- Never place a patient body, patient identifier, token, credential, wrapped key, key reference, object path, provider response, or decrypted sample in a command line, log, screenshot, audit reason, support ticket, or release artifact.
- Treat `raw.payload_objects` as the object authority and `raw.payload_object_events` as its append-only lifecycle evidence. Do not update authority lifecycle columns directly.
- Treat the source, organization, facility, and environment recorded on an object as one exact authority. A pointer from a different or missing source is rejected at the database boundary.
- A protected relational row contains an opaque payload-object ID and non-content metadata only. Raw and normalized bodies become `NULL`; FHIR, canonical, and writeback bodies become an empty JSON object.
- The payload store fails closed. Disabling it does not create a plaintext fallback; protected writes and provider readiness become unavailable.
- Keep the immutable external key version used by every retained object resolvable until those objects are rewrapped or have passed governed deletion. Rotating an alias alone does not rewrap existing objects.
- Do not purge an object with a legal hold, open replay/dead-letter dependency, unresolved backfill item, or non-terminal outbound writeback draft.
- Quarantine is not a dead letter. Quarantine blocks decryption and therefore blocks normalization, projection, replay, export, search, AI use, and writeback until an independently approved release.
- Do not call the Admin page, a successful readiness probe, or a passing unit test proof of production recoverability. A deployment-owned restore drill and provider-side evidence are required.

## Implemented authority model

The application protects these five body authorities:

| Transaction boundary | Relational pointer | Cleared legacy body |
|---|---|---|
| Raw inbound receipt | `raw.inbound_messages.payload_object_id` | `payload = NULL` |
| Normalized inbound receipt | `raw.inbound_messages.normalized_payload_object_id` | `normalized_payload = NULL` |
| FHIR resource version | `fhir.resource_versions.payload_object_id` | `resource_data = {}` |
| Canonical replay event | `integration.canonical_events.payload_object_id` | `payload = {}` |
| Outbound writeback draft | `ops.writeback_drafts.payload_object_id` | `resource_payload = {}` |

Every new protected write stores and reads back the encrypted object before committing its relational pointer. The object envelope uses a random per-object DEK, XChaCha20-Poly1305 authenticated encryption, source/kind/UUID-bound additional authenticated data, and optional gzip compression. It wraps that DEK with the configured 32-byte KEK. The manifest records plaintext and ciphertext SHA-256 values, byte counts, provider scheme and immutable version, retention policy, and lifecycle state. Object keys contain only random UUID material and environment/kind routing segments.

The application currently supports:

- a fail-closed local filesystem disk for tests and explicitly approved sealed single-host deployments;
- an S3 or S3-compatible object disk through Flysystem, with private visibility and configurable server-side encryption. The production example defaults to `aws:kms` and requires an explicit KMS key ID;
- application-envelope key references resolved through the same sealed-file, Vault KV, AWS Secrets Manager, GCP Secret Manager, or Azure Key Vault providers documented in `ADMIN-CREDENTIAL-NETWORK-GOVERNANCE-RUNBOOK.md`.

This implementation does **not** claim an Azure Blob or GCS object-storage adapter, cloud-KMS `GenerateDataKey` envelope integration, automatic KEK rewrap, immutable/WORM bucket policy, cross-region replication, or completed online table partitioning. Add and validate those capabilities when the institutional architecture requires them.

## Provider bootstrap

### 1. Provision the application-envelope KEK

Create 32 cryptographically random bytes inside the approved secret-management workflow. Store the secret value in this exact format:

```text
base64:<base64 encoding of exactly 32 raw bytes>
```

Do not generate the production value in shell history or copy it through an Admin request. Pin an immutable provider version. The runtime principal needs read access only to the selected secret version; it should not have list, write, delete, or policy-administration permission.

Set `CLINICAL_PAYLOADS_KEK_REF` to a supported provider URI. The application stores the reference and its SHA-256 fingerprint in the protected manifest, so access to the database must remain restricted even though the secret value is external.

### 2. Provision object storage

For production S3-compatible storage, set:

```dotenv
CLINICAL_PAYLOADS_ENABLED=true
CLINICAL_PAYLOADS_DISK=clinical-payloads-s3
CLINICAL_PAYLOADS_KEK_REF=<immutable-provider-reference>
CLINICAL_PAYLOADS_S3_REGION=<approved-region>
CLINICAL_PAYLOADS_S3_BUCKET=<dedicated-private-bucket>
CLINICAL_PAYLOADS_S3_SSE=aws:kms
CLINICAL_PAYLOADS_S3_KMS_KEY_ID=<approved-kms-key-id>
CLINICAL_PAYLOADS_ALLOW_LOCAL_IN_PRODUCTION=false
CLINICAL_PAYLOADS_READINESS_PROBE_ENABLED=true
```

Supply credentials through the deployment's protected environment or workload boundary, never through Git. The current adapter accepts access key, secret, and optional session token variables. It does not claim IAM-role discovery. Grant only the object-prefix operations needed to put, get, and delete protected objects plus the minimum KMS permissions required by the selected bucket encryption policy. Deny public ACLs, public bucket policy, unencrypted transport, and writes without the required server-side encryption. Enable provider audit logging, versioning/replication, backup, lifecycle, and deletion protection according to the signed institutional retention and recovery design.

For a sealed local deployment, set a root outside the release tree, owned by the runtime identity with no general user access. Production rejects the local driver unless `CLINICAL_PAYLOADS_ALLOW_LOCAL_IN_PRODUCTION=true`; that exception needs explicit risk acceptance and host backup/restore evidence.

### 3. Set payload policy

Review these defaults before enabling a source:

- `CLINICAL_PAYLOADS_DEFAULT_RETENTION_POLICY` and `CLINICAL_PAYLOADS_DEFAULT_RETENTION_DAYS`;
- `CLINICAL_PAYLOADS_MAX_BYTES`;
- `CLINICAL_PAYLOADS_COMPRESSION` and compression level;
- integrity and retention batch sizes;
- readiness-probe TTL.

A source onboarding version overrides classification, retention-policy key, and retention days. A PHI-enabled source is always elevated to `restricted_phi` even if a lower classification was supplied.

The readiness probe writes, reads, compares, and deletes a random 32-byte non-PHI object under an opaque `v1/readiness/` key. Its result is cached for at most the configured TTL. The probe validates current reachability; it does not prove historical-object restore, backup recovery, replication, or provider durability.

## Release and migration sequence

This migration changes writer and reader contracts. Treat schema, application code, object storage, secret authority, queue workers, and scheduler as one coordinated release.

### Gate A - Pre-release

1. Freeze the exact release commit and retain the normal database backup, recovery point, dependency, security, and browser evidence.
2. Provision the external KEK version and object storage. Confirm least-privilege policy and provider-side audit logging without exposing credentials in the release record.
3. Confirm adequate object-store capacity, request-rate quota, network path, KMS quota, recovery objectives, and deletion behavior.
4. Inventory every in-scope source and its signed retention, legal-hold, replay, and permitted-purpose requirements.
5. Stop if a partner or institution requires an object provider, key-management pattern, server-side immutability control, or recovery topology not implemented by this release.

### Gate B - Coordinated deployment

The migration must exist before the first protected writer executes. From the canonical clean `main` checkout, the approved manual deployment path supports a migration-coupled release with:

```bash
DEPLOY_RUN_MIGRATIONS=1 ./deploy.sh
```

Do not run that command from this feature worktree, and do not run it until review, merge, release authorization, environment provisioning, and a rollback decision are complete.

After deployment:

```bash
cd /var/www/Zephyrus
sudo -u www-data php artisan about --only=environment
sudo -u www-data php artisan list | rg '^  clinical-payloads:'
sudo -u www-data php artisan schedule:list
```

Open **Administration -> Data Protection** with the exact authorized organization/facility/source scope. Require `Ready`, the intended disk driver, `xchacha20-poly1305-ietf`, the expected provider scheme/version, and provider reachability. The page intentionally exposes no object paths, KEK reference, wrapped keys, or decrypted content.

Once the first protected object is written, do not roll application code back to a version that cannot resolve payload pointers. A forward repair or a tested compatibility release is required. Setting `CLINICAL_PAYLOADS_ENABLED=false` is a containment action, not a plaintext rollback.

## Legacy inventory and backfill

Backfill targets only legacy rows whose pointer is absent and body is still present. It is resumable and idempotent per table, primary key, and column; it records per-item leases, attempt counts, source/time bounds, hashes, counts, and stable error codes without recording content.

Start with a source-bounded inventory:

```bash
php artisan clinical-payloads:backfill --mode=inventory --source=<source-id> --limit=100
```

Use ISO-8601 bounds when a source is large:

```bash
php artisan clinical-payloads:backfill --mode=inventory --source=<source-id> --from=<inclusive-iso-time> --to=<inclusive-iso-time> --limit=1000
```

Review aggregate evidence only:

```sql
SELECT payload_backfill_run_id, run_uuid, source_id, mode, status,
       scanned_count, protected_count, skipped_count, failed_count,
       mismatch_count, error_code, started_at, completed_at
FROM raw.payload_backfill_runs
ORDER BY payload_backfill_run_id DESC
LIMIT 20;

SELECT source_table, source_column, status, last_error_code, count(*)
FROM raw.payload_backfill_items
GROUP BY source_table, source_column, status, last_error_code
ORDER BY source_table, source_column, status, last_error_code;
```

Do not select legacy body columns, object keys, wrapped keys, or key references into a terminal or evidence capture.

Run bounded protection only after the inventory is accepted:

```bash
php artisan clinical-payloads:backfill --mode=backfill --source=<source-id> --limit=100
```

For each row, the service decodes and hashes the legacy JSON, stores the encrypted object, decrypts and verifies it, atomically attaches the pointer only if the legacy value has not changed, clears the ordinary JSONB body, and records append-only evidence. Concurrent drift becomes `clinical_payload_legacy_hash_drift`; malformed JSON or a missing legacy source becomes a per-item stable failure instead of aborting unrelated rows.

Repeat in bounded batches until inventory returns zero. A completed-with-errors run returns a failing exit code and is not a successful cutover. Required source-level exit evidence is:

- zero pending, failed, and mismatched items;
- zero legacy bodies across all five coverage targets;
- protected count reconciled to the source inventory;
- no recognizable fixture/patient marker in backfill evidence, ordinary JSONB, logs, failed jobs, or captured traces;
- a successful bounded integrity sample;
- current readers, replay, projection, and writeback draft review proven against protected rows;
- an approved soak period with queue, scheduler, object-provider, and KMS telemetry.

`CLINICAL_PAYLOADS_LEGACY_READ_ENABLED` keeps the application capable of reading unbackfilled rows during the mixed-authority period. Do not turn it off until every in-scope source has zero legacy bodies and the rollback decision explicitly accepts pointer-only authority.

## Integrity and restore verification

Run a bounded source sample:

```bash
php artisan clinical-payloads:verify --source=<source-id> --limit=25
```

Verification checks source/kind authority, ciphertext hash, envelope format, immutable provider version, wrapped-key decryption, authenticated payload decryption, decompression, plaintext size/hash, and JSON structure. It records an append-only `verified` event but never prints the body. Integrity failure transitions the object to `integrity_failed` and blocks further reads.

The scheduler runs a bounded verification sample daily. Confirm the scheduler actually runs on one deployment host and that its heartbeat/monitoring is healthy; a configured schedule is not execution evidence.

At least once per release and recovery design, perform a deployment-owned restore drill from the real backup/replica path into an isolated approved environment. Reconcile manifest/object counts, provider versions, and sampled hashes. Do not use production patient bodies as screenshots or release artifacts. Retain only IDs, counts, timestamps, hashes, stable codes, and the external drill ticket.

## Retention, legal hold, and deletion

Preview expired, non-held objects first:

```bash
php artisan clinical-payloads:lifecycle --source=<source-id> --limit=100
```

Execution is explicit:

```bash
php artisan clinical-payloads:lifecycle --source=<source-id> --limit=100 --execute
```

The lifecycle blocks deletion for pending/failed canonical projection, an open inbound dead letter, an unresolved backfill item, or a writeback draft not in `sent`, `cancelled`, `rejected`, or `expired`. A successful delete removes the encrypted object and leaves a manifest tombstone plus append-only event. A provider deletion failure records `deletion_failed` and remains visible in Admin.

Legal hold and exceptional purge are operated only from **Administration -> Data Protection** after selecting one exact organization, facility, and source. Aggregate views do not expose object records or controls. A `manageDataStewardship` author requests the action with a 10-500 character non-PHI rationale; hold actions also require a stable non-content reason code. A different `approveIntegrationChanges` operator approves or rejects it. Request, decision, and execution each require recent step-up authentication.

For a hold:

1. Select the exact source and opaque object authority.
2. Request **Apply legal hold** with the institution's approved reason code. Do not enter a patient name, MRN, case narrative, legal document, or clinical content.
3. Have an independent approver compare the external legal authorization to the opaque object/source evidence and record a bounded rationale.
4. Execute the approved change. Confirm `legal_hold = true` and an append-only `hold_applied` event bound to the governed change UUID.
5. Release follows the same independent path and requires the object to be held. Confirm `hold_released`; do not update the projection directly.

Exceptional purge is available before the normal retention boundary only as a separately approved exception. It is rejected when the object is held, quarantined, already deleted, or has any unresolved writeback, canonical projection, inbound projection, dead-letter, or backfill dependency. Approval binds the exact immutable object UUID, source, kind, classification, current lifecycle/hold/retention state, stable blocker list, operation, and derived cryptographic-authority hash. Successful execution records `purge_marked`, deletes the encrypted object, records `deleted`, and retains the non-content tombstone.

External object deletion is not transactional. The governed executor therefore commits `deletion_pending`, `deletion_failed`, and a failed execution attempt when the provider rejects deletion. Correct the provider/IAM/quota/network cause, reselect the same approved request, and use **Retry approved execution**. Do not create an unbounded retry loop or a replacement approval unless the existing request expired or its exact contract legitimately changed.

## Quarantine and governed release

The separate quarantine authority supports malware, unsafe-content, consent, policy, classification, integrity, and encryption categories. Details are size-bounded, scalar-only, and reject keys that could carry patients, payloads, messages, resources, tokens, credentials, or secrets.

An open quarantine can enter governed release only through the exact-source Admin control/API. The author must have integration-operation capability and recent step-up. A different authorized operator approves or rejects the governed change. Execution rebinds the exact source, quarantine UUID, payload-object authority, quarantine/object states, hold/retention/dependency evidence, operation, and derived cryptographic-authority hash. Any changed, expired, rejected, wrong-scope, or self-approved request fails closed.

The page shows only opaque quarantine/object IDs, category and stable reason code, detector, timestamps, legal-hold state, and stable dependency codes. It never exposes `details`, inbound bodies, decrypted content, paths, hashes, or key material. **Release quarantine** restores bounded processing access. **Terminal purge** requires `manageDataStewardship`, an independent approval, no legal hold, and no deletion dependency; it deletes the encrypted object and records both governed object and quarantine tombstones. Retain the scanner/provider evidence outside Zephyrus under the institution's approved incident system without copying clinical content into the governed rationale.

## Governed integrity recovery

An integrity failure changes the object to `integrity_failed` and blocks all reads. The Admin workflow does not accept an upload, replacement body, new object path, or new key reference.

1. Investigate the storage, replication, backup, and immutable key-version evidence outside the application.
2. Restore the exact original encrypted object to its existing provider key through the approved storage recovery process. Do not edit the database manifest or upload through Admin.
3. Select the exact source/object and request **Verify restored object**. A different authorized operator approves it after comparing the external recovery evidence.
4. Execute the approval. Zephyrus verifies the immutable ciphertext hash, envelope/AAD, pinned key authority, authenticated decryption, plaintext length/hash, and JSON structure.
5. Confirm `integrity_recovered`, `ready`, the governed change UUID, and a current verification timestamp. A mismatch remains fail closed; never force `ready` directly.

## Safe operational queries

Coverage by authority:

```sql
SELECT 'raw' AS authority,
       count(*) FILTER (WHERE payload_object_id IS NOT NULL) AS protected,
       count(*) FILTER (WHERE payload_object_id IS NULL AND payload IS NOT NULL) AS legacy
FROM raw.inbound_messages
UNION ALL
SELECT 'normalized',
       count(*) FILTER (WHERE normalized_payload_object_id IS NOT NULL),
       count(*) FILTER (WHERE normalized_payload_object_id IS NULL AND normalized_payload IS NOT NULL)
FROM raw.inbound_messages
UNION ALL
SELECT 'fhir',
       count(*) FILTER (WHERE payload_object_id IS NOT NULL),
       count(*) FILTER (WHERE payload_object_id IS NULL AND resource_data <> '{}'::jsonb)
FROM fhir.resource_versions
UNION ALL
SELECT 'canonical',
       count(*) FILTER (WHERE payload_object_id IS NOT NULL),
       count(*) FILTER (WHERE payload_object_id IS NULL AND payload <> '{}'::jsonb)
FROM integration.canonical_events
UNION ALL
SELECT 'writeback',
       count(*) FILTER (WHERE payload_object_id IS NOT NULL),
       count(*) FILTER (WHERE payload_object_id IS NULL AND resource_payload <> '{}'::jsonb)
FROM ops.writeback_drafts;
```

Lifecycle posture:

```sql
SELECT status, legal_hold, count(*)
FROM raw.payload_objects
GROUP BY status, legal_hold
ORDER BY status, legal_hold;

SELECT reason_category, status, count(*)
FROM raw.payload_quarantines
GROUP BY reason_category, status
ORDER BY reason_category, status;

SELECT event_type, reason_code, count(*)
FROM raw.payload_object_events
WHERE occurred_at >= now() - interval '24 hours'
GROUP BY event_type, reason_code
ORDER BY event_type, reason_code;
```

These queries are suitable for bounded operator review because they omit content and cryptographic material. Scope them by `source_id` or an authorized organization/facility source set before using them for institutional evidence.

## Stable failure codes

| Code family | Meaning | Operator response |
|---|---|---|
| `clinical_payload_store_disabled` | Protection is not enabled | Stop protected ingestion; correct approved configuration |
| `clinical_payload_key_*` | KEK reference, encoding, length, version, provider, or availability failed | Repair the external immutable authority; never substitute or overwrite an old version |
| `clinical_payload_disk_*` / `clinical_payload_provider_probe_*` | Disk is missing, not fail-closed, disallowed, or failed non-PHI write/read/delete probe | Stop ingestion; inspect object-provider, KMS, IAM, quota, and network evidence |
| `clinical_payload_storage_*` | Object write, readback verification, or delete failed | Preserve IDs/correlation and provider audit evidence; do not retry unboundedly |
| `clinical_payload_authority_mismatch` | Source or payload kind does not match the manifest | Treat as a security/integrity event; do not bypass the pointer guard |
| `clinical_payload_*hash_mismatch` / `clinical_payload_*decrypt*` / `clinical_payload_envelope_invalid` | Ciphertext, envelope, key unwrap, authenticated decryption, or plaintext integrity failed | Quarantine/contain, retain provider and storage audit evidence, and execute the incident runbook |
| `clinical_payload_not_readable` | Object is quarantined, integrity-failed, or deleted | Use governed lifecycle evidence; never force a direct status update |
| `clinical_payload_deletion_blocked` | An unresolved writeback, projection, dead letter, or backfill dependency exists | Resolve the authoritative dependency; request approval again only if the exact contract changes |
| `clinical_payload_legal_hold_active` | A legal hold blocks purge or retention deletion | Use the independently approved hold-release workflow; never bypass it |
| `clinical_payload_integrity_recovery_state_invalid` | Recovery was requested for an object not currently integrity-failed | Refresh exact-source evidence and do not execute the stale request |
| `clinical_payload_legacy_hash_drift` | Legacy row changed during backfill | Stop that item, reconcile the owning writer, and rerun a bounded inventory |
| `clinical_payload_source_missing` | Legacy row has no authoritative source | Resolve ownership through governed data repair before protection |
| `clinical_payload_storage_delete_failed` | Retention deletion did not complete | Leave the tombstone transition pending, correct provider access, and retry through approved lifecycle tooling |

Use only the stable code, opaque request/correlation ID, source/object/quarantine UUID, timestamp, and non-content hashes in operational tickets. Do not copy exception context or upstream bodies.

## Clinical-content negative-output boundary

`App\Security\ClinicalPayloads\ClinicalContentGuard` is the shared failure-output tripwire. It detects reserved non-production canaries and recognizable HL7 v2, FHIR JSON/XML, X12, CDA/C-CDA, NCPDP SCRIPT, DICOM/DICOMweb, vendor JSON/CSV clinical envelopes, bearer/basic/JWT/cookie/API credentials, and private keys. It is not a de-identification service, a data-loss-prevention certification, or permission to place a body in a diagnostic channel. Every producer must still emit only allowlisted stable codes, opaque identifiers, bounded counts, timestamps, and approved non-content hashes.

The enforced boundaries are:

- all JSON validation responses retain only field keys and replace every message with fixed non-content text;
- error, exception, trace, and debug fields are inspected globally, while an authorized domain projection carried beside an optimistic-concurrency error remains governed by its normal minimum-necessary response contract;
- every queue payload is inspected before dispatch, regardless of queue driver or name;
- the `integrations` queue additionally requires `ClinicalPayloadSafeQueueJob`, an exact declared-argument/property match, `ShouldBeEncrypted`, and stable failure middleware that discards the original exception chain;
- PostgreSQL scans existing rows and rejects future clinical content in queued/failed jobs, dead letters, projection errors, system-health observations, configuration and user audit, governed changes, and access-review evidence;
- configured single/daily/Slack/Papertrail/stderr/syslog/errorlog channels and Laravel's emergency fallback redact messages, structured context, exception arguments, and trace arguments; retained frames contain only bounded file basenames, line numbers, classes, and functions;
- Teams, APNs, log-push, Hummingbird fan-out, governance, quarantine, access-review export, and incident projections use explicit guard or allowlist boundaries;
- `scripts/capture-release-evidence.sh` scans the complete command display and complete stdout/stderr stream before writing a log or manifest, so multi-line bodies and key material cannot evade a line boundary.

There is no configured application trace exporter in this release. INT-OBS must not enable OpenTelemetry, Sentry, APM, or another trace exporter until its resource attributes, span attributes/events, exception recording, baggage, and exporter failure path use this same non-content contract and pass the fixture matrix.

Run the focused negative-output gate with:

```bash
php artisan test --compact \
  tests/Unit/Security/ClinicalContentGuardTest.php \
  tests/Feature/Security/ClinicalContentFailureBoundaryTest.php
```

The focused gate covers HTTP errors/validation, authorized conflict recovery, all queue names, encrypted integration serialization, stable failed-job exceptions, database tripwires, ordinary and emergency logs, bounded trace frames, alerts, audit, incident projection, and multi-line release evidence. The authenticated browser lane separately proves that Data Protection stays minimum-necessary in the rendered application. Any recognizable fixture marker in a response diagnostic, log, failed job, audit/evidence authority, alert, screenshot, or retained command output is a release blocker. Do not add a Gitleaks ignore entry for a synthetic credential fixture; construct the fixture at runtime and keep both history and working-tree scans at zero findings.

## Rollback and incident containment

- **Before the first protected write:** repair provider configuration or roll back the coordinated release through the normal release process.
- **After protected writes begin:** do not deploy code that reads only legacy JSONB. Suspend affected sources, stop queue consumers if necessary, preserve current pointer-aware readers, and forward-fix the provider, key, or code fault.
- **During backfill:** stop issuing new batches. Leases expire safely; already verified rows remain pointer-authoritative. Reconcile failed/mismatched items before continuing.
- **Provider outage:** keep the manifest and event ledger unchanged. Do not copy encrypted objects to an unapproved disk or replace the KEK reference with a different key version.
- **Suspected key compromise:** suspend affected sources and outbound flows, preserve provider/storage audit logs, identify exact provider versions and object counts, and follow the institutional incident/rotation process. Automatic rewrap is not implemented in this tranche.
- **Integrity failure:** the object is fail-closed. Restore only the exact encrypted provider object through the approved storage process, then use the implemented independently approved Admin recovery verification; never directly restore `ready`.

Database rollback of migration `2026_07_13_000800` drops protected pointers and manifests and therefore must never run after protected objects exist. It is safe only in an empty disposable environment. Production rollback is an application/release and recovery procedure, not `migrate:rollback`.

## Production exit gate

Do not declare INT-STORAGE production-complete until all of the following are retained for every activated source and environment:

- approved object-store, KMS/SSE, KEK provider, immutable-version, IAM, network, region, backup, replication, recovery, and deletion designs;
- zero legacy bodies, zero unexplained backfill failures/mismatches, and reconciled manifest/object counts;
- successful current-read, replay, projection, outbound-draft, queue retry, scheduler, and provider-outage tests;
- periodic integrity execution plus a real backup/restore sample with no content in evidence;
- successful deployment-owned exercises and retained non-content evidence for governed legal-hold apply/release, exceptional purge, quarantine inspection/release/purge, failed-delete retry, and exact-object recovery;
- online time/tenant partition migrations based on measured volume and retention behavior;
- negative evidence that requests, validation errors, logs, traces, audit records, queue payloads, failed jobs, alerts, screenshots, exports, and support workflows contain no raw PHI, secret, wrapped key, key reference, or object path;
- authenticated browser smoke of `/admin/data-protection`, source-scoped authorization tests, full backend/frontend gates, security scans, migration rehearsal, manual deployment verification, and a tested forward-repair/rollback decision.

Until then, the Admin page is accurate operational evidence for the implemented branch controls, not a certification of institution-wide storage readiness.
