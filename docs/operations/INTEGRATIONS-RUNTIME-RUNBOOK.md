# Integrations Runtime Runbook

**Status:** Operational first slice

**Scope:** Supervised Laravel queues, FHIR R4/SMART Backend Services, Patient Flow HL7 v2 ADT ingress, protocol health, replay, and production activation.

## Safety properties

- Integration administration remains behind `viewIntegrations` / `manageIntegrations`; no general administrator or operations role inherits it.
- Production outbound connections require an exact governed network route, revalidate and pin target/proxy DNS at connection time, reject redirects, and never persist an address, access token, certificate body, or private key. The configured public-host policy remains only as a non-production fallback.
- Credential material is referenced through the governed `file`, Vault, AWS Secrets Manager, GCP Secret Manager, or Azure Key Vault provider. Sealed files remain rooted under `INTEGRATION_SECRET_FILE_ROOT` and are subject to owner/group/mode/size/path checks. See `ADMIN-CREDENTIAL-NETWORK-GOVERNANCE-RUNBOOK.md`.
- Protocol reachability and clinical-data freshness are separate. A successful discovery check does not advance a connector data watermark.
- Replay preview never mutates. Execution is limited to pending/failed RTDC canonical events, seven days, and 1,000 events; already-projected events cannot be force-replayed.
- HL7 accepts only a persisted Sanctum bearer token with the exact `integration:patient-flow:ingest` ability, an active dedicated integration identity, and a production/live/PHI-approved source in production.

## Deploy and worker lifecycle

The repository unit is `deploy/systemd/zephyrus-queue-worker.service`. `./deploy.sh` installs or refreshes it, enables it, restarts it after Laravel caches are cleared, and fails deployment if it is not active. The worker explicitly consumes `integrations,default` from the database connection with a 300-second timeout and a retry window longer than the job timeout.

For the schema-bearing first deployment:

```bash
DEPLOY_RUN_MIGRATIONS=1 ./deploy.sh
```

Required production values:

```dotenv
QUEUE_CONNECTION=database
DB_QUEUE_CONNECTION=pgsql
DB_QUEUE_RETRY_AFTER=360
INTEGRATION_ALLOWED_HOSTS=fhir.epic.com
INTEGRATION_SECRET_FILE_ROOT=/etc/zephyrus/secrets
INTEGRATION_CIRCUIT_BREAKER_TRIP_FAILURES=5
INTEGRATION_CIRCUIT_BREAKER_OPEN_SECONDS=60
INTEGRATION_CIRCUIT_BREAKER_HALF_OPEN_LEASE_SECONDS=30
INTEGRATION_RATE_LIMIT_DEFAULT_RETRY_SECONDS=60
INTEGRATION_FHIR_POLL_CADENCE_MINUTES=15
INTEGRATION_FHIR_PAGE_SIZE=100
INTEGRATION_FHIR_PAGE_LIMIT=10
INTEGRATION_FHIR_RESOURCE_LIMIT=1000
```

Verify:

```bash
systemctl is-active zephyrus-queue-worker.service
systemctl status zephyrus-queue-worker.service --no-pager
sudo -u www-data php /var/www/Zephyrus/artisan queue:monitor integrations,default --max=100
sudo -u www-data php /var/www/Zephyrus/artisan schedule:list
```

Rollback is operationally safe: stop/disable the worker before reverting application code. Do not roll back the additive database migration while replay or FHIR jobs remain queued.

## FHIR R4 / SMART activation

The runtime protocol core is vendor-neutral. `SmartBackendFhirClient` and `PollFhirResource` own SMART Backend Services and FHIR R4 behavior; vendor assertions are optional source policies. The Epic sandbox below is the shipped bootstrap profile, while the same runtime accepts another governed FHIR R4 source without embedding Epic checks in token exchange, search, persistence, or scheduling.

The configured public non-production endpoints are Epic's published sandbox endpoints:

- FHIR R4 base: `https://fhir.epic.com/interconnect-fhir-oauth/api/FHIR/R4`
- SMART discovery: `https://fhir.epic.com/interconnect-fhir-oauth/api/FHIR/R4/.well-known/smart-configuration`
- OAuth token: `https://fhir.epic.com/interconnect-fhir-oauth/oauth2/token`

The implementation follows [Epic's backend OAuth guidance](https://fhir.epic.com/Documentation?docId=oauth2&section=BackendOAuth2Guide) and the [HL7 SMART Backend Services profile](https://hl7.org/fhir/smart-app-launch/backend-services.html): private-key JWT client assertions use RS384, a five-minute assertion lifetime, `client_credentials`, and minimal system scopes.

Configure discovery-only (safe without credentials):

```bash
sudo -u www-data php artisan integrations:configure-operational-sources
```

The source remains `testing` / `activation_required`. Live discovery can run, but the UI disables polling.

For authenticated non-production polling:

1. Register Zephyrus as an Epic non-production backend-services client.
2. Place its private PEM key outside the repository, for example `/etc/zephyrus/secrets/epic-fhir-private-key.pem`, owned `root:www-data` and mode `0640`.
3. Set `EPIC_FHIR_CLIENT_ID`, `EPIC_FHIR_PRIVATE_KEY_REF=file:///etc/zephyrus/secrets/epic-fhir-private-key.pem`, and optional `EPIC_FHIR_KEY_ID` in production `.env`.
4. Clear config and activate:

```bash
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan integrations:configure-operational-sources --activate-epic
```

5. In Integrations → FHIR R4 / SMART, run **Check**. Only after live SMART and CapabilityStatement validation plus key resolution does the connection become `ready`. The bootstrap Encounter and Location profiles become pollable only when the server advertises each resource and the credential contains a matching SMART system read scope.

FHIR polling retains raw versioned resources, `fhir.resource_versions`, provenance, run counters, and a per-resource `_lastUpdated` watermark. A token is held in process memory only. Production PHI polling additionally requires the source to be production/live and PHI-approved.

### Inspect immutable discovery evidence

Each successful **Check** appends one bounded, normalized CapabilityStatement/SMART observation and its resource details before advancing the connection's current-evidence pointer. The observation tables reject UPDATE/DELETE, source/connection mismatches, pointer rewind or clearing, late child insertion, count-inconsistent finalization, and recognizable clinical content. A failed or token-endpoint-drifted check does not append or advance evidence.

In Integrations → FHIR R4 / SMART, select the exact FHIR source and inspect **Discovered FHIR + SMART Conformance**. The panel exposes only PHI-free status, counts, capability flags, warning codes, SHA-256 document hashes, endpoint origins, and bounded resource capability rows. `unobserved` means no successful full discovery exists; it must not be interpreted as healthy. `legacy_reduced` identifies a migration backfill from the former reduced snapshot, not new full discovery evidence.

The source-scoped read contract is `GET /api/admin/integrations/sources/{source}/fhir/conformance`. It requires `viewIntegrations` and the same exact selected source boundary. Do not expose or copy raw discovered documents into tickets, audit reasons, or logs.

Useful PHI-free queries:

```sql
SELECT source_id, observation_uuid, observation_status, fhir_version,
       capability_status, resource_count, searchable_resource_count,
       search_parameter_count, operation_count, supports_batch,
       supports_transaction, supports_bulk_data, supports_subscriptions,
       capability_document_sha256, smart_document_sha256, observed_at
FROM integration.fhir_conformance_observations
ORDER BY fhir_conformance_observation_id DESC
LIMIT 50;

SELECT source_id, resource_type, versioning, read_history,
       update_create, conditional_create, conditional_read,
       conditional_update, conditional_delete,
       search_parameter_count, operation_count
FROM integration.fhir_conformance_resource_observations
WHERE fhir_conformance_observation_id = :observation_id
ORDER BY resource_type;
```

Never edit either observation table or `current_conformance_observation_id` directly. Correct the endpoint, credential authority, or partner metadata, rerun **Check**, and preserve the failed check's stable error evidence separately from the last successful discovery observation.

### Govern resource profiles

Do not add resource types to `polling_payload` or edit integration tables directly. Select the exact source scope in Integrations → FHIR R4 / SMART, then use **Governed Resource Profiles**:

1. Enter the case-sensitive FHIR resource type and, when required, its canonical profile URL and version.
2. Set bounded cadence, page size, page count, and total resource limits.
3. Record a PHI-free change reason and save. The current row and append-only version event record the actor and correlation ID.
4. Run **Check**. The profile stays `configured` unless CapabilityStatement discovery advertises the resource and a `system/{Resource}.read` or read-capable SMART v2 scope authorizes it. Lost capability or scope moves an enabled profile to `suspended`.
5. Use **Retire** with a reason when the profile is no longer approved. Retirement is immutable; database delete is deliberately rejected.

An `enabled` profile is the only source of truth for manual and scheduled polling. The scheduler no longer contains an Encounter/Location allowlist.

Direct profile changes are permitted only while a source is non-production, PHI-disallowed, and outside a protected lifecycle state. For a live, production, PHI-approved, scheduled, or otherwise protected source, direct configure/retire requests fail closed; change the source through the governed reconfiguration, independent-approval, validation, and activation workflow instead.

## HL7 v2 ADT activation

The boundary is configured by `integrations:configure-operational-sources`, but no feed is called live merely because a route exists.

Configure a source in inactive form first:

```bash
sudo -u www-data php artisan integrations:configure-hl7-adt-source epic.adt.production \
  --name="Epic ADT Production" --vendor=Epic --environment=production
```

After the executed contract, executed BAA, PHI approval, and live-state evidence are recorded, activate explicitly:

```bash
sudo -u www-data php artisan integrations:configure-hl7-adt-source epic.adt.production \
  --name="Epic ADT Production" --vendor=Epic --environment=production \
  --contract-status=executed --baa-status=executed --go-live-status=live \
  --phi-allowed --activate
```

Create or select a dedicated active `prod.users` identity whose role is `integration` or `integration-machine`, then issue a bounded token:

```bash
sudo -u www-data php artisan integrations:issue-hl7-token USER_ID --expires=90
```

Store the one-time plaintext token immediately in the sender's secret manager. Send ADT to `/api/integrations/v1/patient-flow/hl7v2` with:

```text
Authorization: Bearer <token>
X-Integration-Source: epic.adt.production
Idempotency-Key: <stable delivery identifier>
Content-Type: application/json
```

The body is `{"raw_hl7":"..."}`. Successful ingress writes raw message → canonical event → Patient Flow projection → provenance and returns opaque receipt UUIDs only.

## Health, replay, and recovery

- **FHIR Check** validates and immutably records bounded SMART discovery and the complete advertised FHIR 4.0.1 server CapabilityStatement, verifies asymmetric-client capability, exact credential/token-endpoint authority, any configured vendor policy, searchable resources, governed profiles, and local key resolvability. Failure leaves the prior successful observation current.
- **HL7 Check** validates the canonical boundary, exact-ability machine identity, and last successful source ingress without exposing patient or token data.
- **Replay Preview** returns aggregate counts and event types only.
- **Queue Replay** requires `Idempotency-Key`; reusing the key with an identical scope returns the original job, while a different scope returns conflict.
- Poll failures create a dead letter with a stable error code. Correct the endpoint/credential condition, rerun protocol health, and queue the poll again. Never paste credentials or raw patient content into replay metadata or configuration audit records.

### Backpressure and immutable attempt evidence

FHIR polling and FHIR protocol-health checks enforce runtime pressure before each partner call. A partner 429 records the connector-scoped rate-limit transition and honors `Retry-After` as either bounded delta seconds or an HTTP date. Calls made before that window expires are released back to the integrations queue without contacting the partner. When a successful call occurs after the window, an append-only normal transition clears the throttle.

Only transient network/timeout errors and partner 5xx responses count toward `INTEGRATION_CIRCUIT_BREAKER_TRIP_FAILURES`. Governance, unsupported-profile, discovery, and credential errors remain actionable configuration failures and do not trip the circuit. An open circuit rejects calls before network access for `INTEGRATION_CIRCUIT_BREAKER_OPEN_SECONDS`. Once the window expires, a source-row lock grants one half-open probe for `INTEGRATION_CIRCUIT_BREAKER_HALF_OPEN_LEASE_SECONDS`; concurrent workers remain rejected. Probe success closes the circuit and resets consecutive failures, while probe failure reopens it.

`integration.source_runtime_execution_events` is the execution authority for retry-budget reporting. It records idempotent, append-only, PHI-free lifecycle events for the exact ingest run or replay job. PostgreSQL independently verifies the run's source and connector, rejects UPDATE/DELETE, and applies the clinical-content tripwire. The integrations console counts `attempt_started` rows within the configured window. A mutable `raw.ingest_runs.status` value alone is deliberately not treated as proof that a retry occurred.

Useful PHI-free queries:

```sql
SELECT source_id, connector_key, pressure_kind, pressure_state,
       retry_after_seconds, consecutive_failures, reason_code, observed_at
FROM integration.source_runtime_pressure_events
ORDER BY source_runtime_pressure_event_id DESC
LIMIT 50;

SELECT source_id, connector_key, job_type, event_type, attempt_number,
       max_attempts, retry_after_seconds, reason_code, observed_at
FROM integration.source_runtime_execution_events
ORDER BY source_runtime_execution_event_id DESC
LIMIT 100;

SELECT source_id, resource_type, profile_status, poll_enabled,
       cadence_minutes, page_size, page_limit, resource_limit,
       version_number, reason_code, updated_at
FROM integration.fhir_resource_profiles
ORDER BY source_id, resource_type;
```

Useful inspection commands:

```bash
sudo -u www-data php artisan queue:failed
sudo -u www-data php artisan queue:retry <failed-job-uuid>
journalctl -u zephyrus-queue-worker.service -n 100 --no-pager
```

Credential rotation uses the immutable authority and dual-control workflow: provision a new provider version, request the exact bounded overlap, obtain independent approval, re-enter and execute the exact payload, validate the new version, run health and a sandbox transaction, then retire the old provider version after the overlap. Follow `ADMIN-CREDENTIAL-NETWORK-GOVERNANCE-RUNBOOK.md`. Do not store a PEM, access token, HL7 payload, or client assertion in Git, command history, application logs, or configuration audits.

PHI-safe OTLP metrics/traces are default-off and use a loopback Collector agent in the recommended production topology. Activation, endpoint allowlisting, secret-referenced headers, Collector configuration, failure drills, and rollback are defined in `INTEGRATIONS-OBSERVABILITY-OTLP-RUNBOOK.md`.
