# Integrations Runtime Runbook

**Status:** Operational first slice

**Scope:** Supervised Laravel queues, FHIR R4/SMART Backend Services, Patient Flow HL7 v2 ADT ingress, protocol health, replay, and production activation.

## Safety properties

- Integration administration remains behind `viewIntegrations` / `manageIntegrations`; no general administrator or operations role inherits it.
- Outbound checks allow HTTPS only, reject redirects, resolve only allowlisted public hosts, and never serialize an access token or private key.
- Private keys are referenced as `file:///...` values rooted under `INTEGRATION_SECRET_FILE_ROOT`; the file must be readable by `www-data`, no larger than 64 KiB, and inaccessible to world users.
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
```

Verify:

```bash
systemctl is-active zephyrus-queue-worker.service
systemctl status zephyrus-queue-worker.service --no-pager
sudo -u www-data php /var/www/Zephyrus/artisan queue:monitor integrations,default --max=100
sudo -u www-data php /var/www/Zephyrus/artisan schedule:list
```

Rollback is operationally safe: stop/disable the worker before reverting application code. Do not roll back the additive database migration while replay or FHIR jobs remain queued.

## Epic FHIR R4 / SMART activation

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

5. In Integrations → FHIR R4 / SMART, run **Check**. Only after live SMART and CapabilityStatement validation plus key resolution does the connection become `ready` and allow Encounter/Location polling.

FHIR polling retains raw versioned resources, `fhir.resource_versions`, provenance, run counters, and a per-resource `_lastUpdated` watermark. A token is held in process memory only. Production PHI polling additionally requires the source to be production/live and PHI-approved.

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

- **FHIR Check** validates SMART discovery, asymmetric-client capability, the token endpoint, FHIR 4.0.1 CapabilityStatement, expected vendor, supported resources, and local key resolvability.
- **HL7 Check** validates the canonical boundary, exact-ability machine identity, and last successful source ingress without exposing patient or token data.
- **Replay Preview** returns aggregate counts and event types only.
- **Queue Replay** requires `Idempotency-Key`; reusing the key with an identical scope returns the original job, while a different scope returns conflict.
- Poll failures create a dead letter with a stable error code. Correct the endpoint/credential condition, rerun protocol health, and queue the poll again. Never paste credentials or raw patient content into replay metadata or configuration audit records.

Useful inspection commands:

```bash
sudo -u www-data php artisan queue:failed
sudo -u www-data php artisan queue:retry <failed-job-uuid>
journalctl -u zephyrus-queue-worker.service -n 100 --no-pager
```

Credential rotation uses overlap: provision the new key with Epic, replace the referenced file atomically, clear config only if reference metadata changed, run a health check, then retire the old public key after successful polling. Do not store a PEM, access token, HL7 payload, or client assertion in Git, command history, application logs, or configuration audits.
