# Admin System Health Runbook

## Purpose and safety boundary

The Admin System Health surface is a PHI-free platform-readiness view. Its evidence is stored in `governance.system_health_observations` as append-only observation batches. The scheduled collector is `php artisan admin:observe-system-health`; Laravel invokes it every minute from the application scheduler. A manual collection is available only to principals with `runDiagnostics`, is rate limited, carries the trusted request UUID as its correlation/batch UUID, and creates a user-audit event.

An observation is not configuration, source freshness, an uptime SLO, or an incident. It expires after `ADMIN_HEALTH_FRESH_FOR_SECONDS`; an expired green row is rendered as `unknown` while the original row remains unchanged. `disabled` means deployment policy intentionally turned an optional component off. It is not synonymous with healthy.

Diagnostics must never call an EHR, advance a source cursor, replay a transaction, read a backup body, inspect a private key, test a credential, or return raw upstream responses, exception text, secret references, patient data, or local filesystem paths.

## First response

1. Open `/admin/system-health?status=attention` and identify required components that are `critical`, `warning`, `unknown`, `disabled`, or stale.
2. Confirm the latest scheduler timestamp. If it is absent or expired, start with the scheduler section; do not trust older green observations.
3. Use **Run bounded diagnostics** only when the actor has `runDiagnostics`. Record the returned correlation ID in the incident or change ticket.
4. Follow the component-specific procedure below. Use the authoritative domain console for integration payload disposition, credential changes, access changes, or replay. The health page is read-only apart from its evidence collection.
5. After remediation, collect a new observation. Never edit or delete prior rows.

## Status semantics

- `healthy`: fresh evidence satisfied the bounded policy.
- `warning`: the dependency is usable but crossed an operator-attention threshold.
- `critical`: required behavior, configuration, or policy failed.
- `unknown`: current evidence does not exist. Do not infer health.
- `disabled`: intentionally inactive by deployment policy.

## database

The collector performs only `SELECT 1` through Laravel's primary connection. A failure usually prevents the web page and evidence insert as well, so use application logs, PostgreSQL service status, connection saturation, authentication, TLS, and network telemetry. Do not point the application at another database as an incident workaround. Confirm migrations and search path before restoring traffic.

## database-replicas

Set `ADMIN_HEALTH_EXPECTED_DB_REPLICAS` to the deployment contract. Zero explicitly disables replica evaluation. PostgreSQL deployments compare this value to streaming rows in `pg_stat_replication`; fewer replicas is critical. Validate WAL retention, receiver status, replication slots, replay lag, and failover policy outside the application before changing the expected count.

## queue

Database queues report queued rows, failed rows, and oldest queued age. `sync` or `null` is disabled and is not production-ready for asynchronous integration work. Review supervised worker state, queue connection, failed-job exception handling under PHI-safe logging rules, retry policy, and the dedicated integration queue. Resolve the root cause before retrying or forgetting jobs. Use the integration console for dead letters and canonical projection errors; those are distinct from Laravel failed jobs.

## scheduler

Only the scheduler invocation may emit a healthy scheduler observation. A manual diagnostic deliberately cannot. Confirm the host timer/cron executes `php artisan schedule:run` every minute as the deployment identity, that no stale overlap lock exists, and that the command exits successfully. Do not hand-edit a heartbeat row.

## cache

The probe writes, reads, and deletes one random value with a ten-second lifetime. Check the configured cache store, storage permissions or Redis connectivity, eviction/saturation, and application cache prefix. The probe key contains no PHI and is always deleted best-effort.

## sessions

The probe evaluates the configured store and cookie policy without reading session contents. Production requires an available persistent store, an HTTP-only cookie, HTTPS-only cookies, and a recognized SameSite mode. Use `ProductionSessionConfiguration` and the authentication regression suite before changing the deployment contract. Session remediation must preserve current session-version revocation behavior.

## integration-runtime

The summary counts configured and active sources, protocol-health failures/unobserved sources, open raw dead letters, and open projection errors. Open `/integrations` for source-specific evidence. Never replay or activate a source from System Health. A production source activation, credential rotation, destructive replay, or outbound-policy change must continue through the governed dual-control workflow.

## realtime

`null` and `log` are intentionally disabled. Reverb configuration checks never expose app keys or secrets and cannot prove the server is alive, so a complete configuration without heartbeat evidence remains unknown. Validate the supervised Reverb process, reverse proxy, TLS, allowed origins, application identity, and an authenticated test channel outside patient workflows.

## object-storage

Local private storage is checked only for a writable boundary. External storage receives no probe object and therefore remains unknown until a dedicated non-mutating provider heartbeat is implemented. Validate encryption, bucket/container policy, private networking, object lock/retention, restore access, and application identity without exposing a secret or object name in the Admin response.

## disk-capacity

The collector reports free percentage and byte counts for the storage filesystem, never its path. Warning and critical thresholds are deployment-configurable. Identify growth by approved host tooling, preserving audit evidence and database files. Do not bulk-delete application, database, release-evidence, or backup files from the Admin workflow.

## backups

`ADMIN_HEALTH_BACKUP_EVIDENCE_PATH` points to a deployment-managed marker updated only after a successful backup verification, including the deployment's required restore/sample check. The application reads only the file modification time, never its contents. A missing path is unknown; an unavailable configured marker is critical; age is evaluated against the warning/critical hour thresholds. Refreshing the marker without performing the backup verification is evidence falsification.

## tls-certificate

`ADMIN_HEALTH_TLS_CERTIFICATE_PATH` must reference the public X.509 certificate, never a private key or combined secret bundle. The collector returns only days remaining. Renew using the deployment's certificate process, verify the public vhost and chain, and then collect new evidence. Do not expose certificate filesystem paths in tickets or screenshots.

## arena

Disabled Arena is an optional disabled state. When enabled, the collector uses only timestamps from the latest conformance/performance signals; it does not call the sidecar. Review supervised sidecar health, de-identified OCEL export, scheduled Arena jobs, and their structured logs. Arena remediation must not bypass fitness, feature-flag, or AI-governance gates.

## eddy

Disabled Eddy is an optional disabled state. When enabled, the collector validates only non-secret server-to-server configuration and leaves runtime status unknown because no PHI-safe heartbeat contract exists. Review the Eddy provider policy, BAA/PHI eligibility, local-first routing, redaction controls, budget gates, and supervised service health. Do not use a patient prompt as a health probe.

## Evidence and escalation

For an incident or change record, retain the Admin correlation ID, effective status, stable error code, observed/fresh-until times, owner, and this runbook reference. Do not copy database error text, job payloads, upstream bodies, credentials, raw messages, or patient identifiers. Alert routing, acknowledgement, maintenance suppression, and incident links remain a separate implementation item; until that ships, follow the deployment's existing on-call procedure outside Zephyrus.
