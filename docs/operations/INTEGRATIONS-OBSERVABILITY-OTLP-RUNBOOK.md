# Integrations OpenTelemetry / OTLP Runbook

**Status:** Implemented, default off, deployment activation required

**Scope:** PHI-safe integration metrics and traces emitted through the official OpenTelemetry PHP SDK using OTLP/HTTP protobuf.

## Production topology

Run an OpenTelemetry Collector agent on the Zephyrus host and send the application to its loopback OTLP/HTTP receiver:

```text
Zephyrus guarded recorder -> 127.0.0.1:4318 -> Collector batch/retry -> approved observability backend
```

The application path is synchronous, so its defaults deliberately use a 250 ms transport timeout and no application retry. The loopback Collector owns durable batching, retry, upstream TLS/mTLS, authentication, routing, and backend-specific policy. Increasing the application timeout or retry count affects FHIR/HL7 transaction latency and requires a measured resilience review.

Direct remote export is supported only over HTTPS to an exact `OTEL_EXPORTER_OTLP_ALLOWED_HOSTS` entry. Loopback HTTP is allowed because the traffic never leaves the host. A collector or backend hostname is never accepted merely because it appears in an endpoint URL.

## Safety invariants

- `OBSERVABILITY_METRICS_ENABLED=false` is the default. When disabled, both exporter contracts resolve to the null exporter and do not resolve an OTLP credential or create a transport.
- Every resource, metric, and span attribute passes `SpanAttributes` and `ClinicalContentGuard` before it reaches the SDK. Values are bounded scalars; attribute keys are bounded lowercase dotted identifiers.
- Do not record exception messages, stack arguments, HTTP bodies, FHIR resources, HL7 fields, patient/encounter identifiers, access tokens, client assertions, PEM material, or arbitrary baggage/events.
- The SDK's internal exception logging is disabled for this adapter. Export failure produces only Zephyrus's stable `observability.metric_dropped` warning with instrument and normalized record name.
- OTLP authentication headers are never placed directly in `.env`. `OTEL_EXPORTER_OTLP_HEADERS_SECRET_REF` points to the governed file/Vault/AWS/GCP/Azure secret provider and resolves to a JSON object at runtime. Header names and values reject CR/LF injection.
- Zephyrus does not trust or forward an inbound `traceparent` or baggage value in this slice. Cross-stage search uses the assigned request UUID (`zephyrus.correlation.uuid`) and canonical event UUID (`zephyrus.event.uuid`), preventing an external sender from injecting trace state.
- Do not enable Collector `debug`/logging exporters or payload/body logging in production. Collector diagnostic logs must remain metadata-only.

## Emitted telemetry

| Kind | Name | Safe correlation / measurements |
|---|---|---|
| Counter | `zephyrus.integration.source_health.observation` | source ID, observation UUID, correlation UUID, status code, breach count |
| Span | `zephyrus.integration.fhir.poll` | source/run IDs, connector key, allowlisted resource type, correlation UUID, received/persisted counts, stable outcome/error code |
| Span | `zephyrus.integration.canonical.write` | source ID, run/message/event UUIDs, assigned correlation UUID, stable outcome/error code |
| Span | `zephyrus.integration.rtdc.project` | canonical event UUID and stable outcome/error code |
| Span | `zephyrus.integration.hl7.receipt_to_projection` | source ID, connector key, run/message/event UUIDs, assigned correlation UUID, projected/duplicate outcome |

`service.name`, `service.namespace`, `service.version`, and `deployment.environment.name` are OTLP resource attributes. The recorder also retains the deployment identity on guarded metric/span attributes for compatibility with the in-memory diagnostics seam.

## Collector example

Install an approved `otelcol-contrib` package and place deployment-owned configuration outside Git, for example `/etc/zephyrus/otel-collector.yaml`:

```yaml
extensions:
  health_check:
    endpoint: 127.0.0.1:13133

receivers:
  otlp:
    protocols:
      http:
        endpoint: 127.0.0.1:4318

processors:
  memory_limiter:
    check_interval: 1s
    limit_mib: 256
  batch:
    timeout: 5s
    send_batch_size: 512

exporters:
  otlphttp/upstream:
    endpoint: ${env:OTEL_UPSTREAM_ENDPOINT}
    headers:
      Authorization: ${env:OTEL_UPSTREAM_AUTHORIZATION}
    sending_queue:
      enabled: true
      queue_size: 2048
    retry_on_failure:
      enabled: true

service:
  extensions: [health_check]
  pipelines:
    metrics:
      receivers: [otlp]
      processors: [memory_limiter, batch]
      exporters: [otlphttp/upstream]
    traces:
      receivers: [otlp]
      processors: [memory_limiter, batch]
      exporters: [otlphttp/upstream]
```

Store upstream Collector credentials in its root-owned systemd environment file or approved host secret manager. The Collector configuration and credentials are deployment assets, not application configuration.

## Zephyrus activation

Recommended loopback settings:

```dotenv
OBSERVABILITY_METRICS_ENABLED=true
OBSERVABILITY_EXPORTER=otlp
OTEL_SERVICE_NAME=zephyrus
OTEL_SERVICE_NAMESPACE=acumenus
OTEL_SERVICE_VERSION=<release-sha-or-version>
OTEL_DEPLOYMENT_ENVIRONMENT=production
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318
OTEL_EXPORTER_OTLP_ALLOWED_HOSTS=127.0.0.1,::1,localhost
OTEL_EXPORTER_OTLP_HEADERS_SECRET_REF=
OTEL_EXPORTER_OTLP_COMPRESSION=gzip
OTEL_EXPORTER_OTLP_TIMEOUT_SECONDS=0.25
OTEL_EXPORTER_OTLP_RETRY_DELAY_MS=50
OTEL_EXPORTER_OTLP_MAX_RETRIES=0
```

For an approved remote collector, set an HTTPS endpoint, reduce the allowlist to its exact hostname, and point the header reference to a secret containing an object such as:

```json
{"Authorization":"Bearer deployment-owned-value"}
```

Do not paste the real value into a shell command, `.env`, ticket, test fixture, log, or configuration audit.

Activation sequence:

1. Start the Collector and verify `curl --fail --silent http://127.0.0.1:13133/`.
2. Confirm the receiver binds only to loopback and the upstream exporter uses approved TLS/authentication.
3. Apply the Zephyrus environment settings, then clear config and restart the Apache/PHP and integrations queue-worker processes through the manual deployment workflow.
4. Collect one source-health observation, perform an approved non-production FHIR poll, and send one synthetic/non-production HL7 ADT transaction.
5. Verify metric/span names, resource identity, correlation UUIDs, event UUIDs, and backend retention/access controls. Search Collector and application logs for the fixture canaries and confirm none are present.
6. Exercise Collector unavailability. The healthcare transaction may incur only the configured bounded timeout; it must continue, and the application log must contain only the stable drop warning.

## Verification

```bash
php artisan test tests/Unit/Observability/OtlpExporterTest.php
php artisan test tests/Feature/Integrations/SourceObservabilityControlPlaneTest.php
php artisan test tests/Feature/Integrations/IntegrationOperationalRuntimeTest.php
php artisan test tests/Feature/PatientFlow/PatientFlowSecurityHotfixTest.php
```

The unit suite decodes the emitted protobuf and verifies exact metric/span/resource fields, endpoint policy, secret-header injection rejection, and disabled SDK exception logging. Feature coverage proves FHIR, canonical, RTDC, and HL7 correlation attributes while fixture patient/resource content remains absent.

## Disable and rollback

Set `OBSERVABILITY_METRICS_ENABLED=false`, clear Laravel configuration, and restart application/worker processes. This binds the null exporter without changing integration processing or database authority. Keep the Collector running until its sending queue drains, then stop it if the deployment is being removed. Never roll back healthcare data or integration migrations to disable telemetry.

## References

- [OpenTelemetry PHP](https://opentelemetry.io/docs/languages/php/)
- [OpenTelemetry PHP exporters](https://opentelemetry.io/docs/languages/php/exporters/)
- [OTLP exporter configuration](https://opentelemetry.io/docs/specs/otel/protocol/exporter/)
