<?php

namespace Tests\Unit\Observability;

use App\Integrations\Healthcare\Services\IntegrationSecretReferenceResolver;
use App\Observability\Exporters\OtlpExporter;
use App\Observability\Exporters\OtlpExporterFactory;
use App\Observability\MetricSample;
use App\Observability\SpanAttributes;
use App\Observability\SpanRecord;
use App\Security\ClinicalPayloads\ClinicalContentGuard;
use InvalidArgumentException;
use Mockery;
use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Behavior\Internal\LogWriter\NoopLogWriter;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use Tests\TestCase;

final class OtlpExporterTest extends TestCase
{
    public function test_official_otlp_protobuf_contains_only_guarded_resource_metric_and_span_attributes(): void
    {
        $metricTransport = new CapturingOtlpTransport;
        $traceTransport = new CapturingOtlpTransport;
        $exporter = new OtlpExporter(
            new MetricExporter($metricTransport, Temporality::DELTA),
            new SpanExporter($traceTransport),
            SpanAttributes::make([
                'service.name' => 'zephyrus',
                'service.namespace' => 'acumenus',
                'service.version' => 'test',
                'deployment.environment.name' => 'testing',
            ]),
        );
        $correlationUuid = '018f0d67-3f34-7f5a-8d95-f61e75fabc01';
        $eventUuid = '018f0d67-3f34-7f5a-8d95-f61e75fabc02';
        $attributes = SpanAttributes::make([
            'zephyrus.source.id' => 42,
            'zephyrus.correlation.uuid' => $correlationUuid,
            'zephyrus.event.uuid' => $eventUuid,
            'zephyrus.outcome' => 'projected',
        ]);

        $exporter->export(new MetricSample(
            name: 'zephyrus.integration.source_health.observation',
            instrument: 'counter',
            value: 1,
            attributes: $attributes,
            unit: '1',
            recordedAtIso: '2026-07-15T14:00:00+00:00',
        ));
        $exporter->export(new SpanRecord(
            name: 'zephyrus.integration.hl7.receipt_to_projection',
            status: 'ok',
            durationMs: 37,
            attributes: $attributes,
            startedAtIso: '2026-07-15T14:00:00+00:00',
        ));

        $this->assertCount(1, $metricTransport->payloads);
        $metricRequest = new ExportMetricsServiceRequest;
        $metricRequest->mergeFromString($metricTransport->payloads[0]);
        $resourceMetric = $metricRequest->getResourceMetrics()[0];
        $metric = $resourceMetric->getScopeMetrics()[0]->getMetrics()[0];
        $this->assertSame('zephyrus.integration.source_health.observation', $metric->getName());
        $this->assertSame('zephyrus', $this->attribute($resourceMetric->getResource()->getAttributes(), 'service.name'));
        $this->assertSame('testing', $this->attribute($resourceMetric->getResource()->getAttributes(), 'deployment.environment.name'));

        $this->assertCount(1, $traceTransport->payloads);
        $traceRequest = new ExportTraceServiceRequest;
        $traceRequest->mergeFromString($traceTransport->payloads[0]);
        $resourceSpan = $traceRequest->getResourceSpans()[0];
        $span = $resourceSpan->getScopeSpans()[0]->getSpans()[0];
        $this->assertSame('zephyrus.integration.hl7.receipt_to_projection', $span->getName());
        $this->assertSame($eventUuid, $this->attribute($span->getAttributes(), 'zephyrus.event.uuid'));
        $this->assertSame($correlationUuid, $this->attribute($span->getAttributes(), 'zephyrus.correlation.uuid'));
        $this->assertGreaterThan($span->getStartTimeUnixNano(), $span->getEndTimeUnixNano());
        $this->assertStringNotContainsString('ZPHI', $metricTransport->payloads[0].$traceTransport->payloads[0]);
    }

    public function test_factory_rejects_remote_cleartext_and_non_allowlisted_collectors(): void
    {
        $secrets = Mockery::mock(IntegrationSecretReferenceResolver::class);
        $factory = new OtlpExporterFactory($secrets, app(ClinicalContentGuard::class));
        config()->set('observability.otlp.endpoint', 'http://collector.example.test:4318');
        config()->set('observability.otlp.allowed_hosts', ['collector.example.test']);

        try {
            $factory->make();
            $this->fail('A remote cleartext collector endpoint was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('observability_otlp_transport_not_secure', $exception->getMessage());
        }

        config()->set('observability.otlp.endpoint', 'https://collector.example.test:4318');
        config()->set('observability.otlp.allowed_hosts', ['different.example.test']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('observability_otlp_host_not_allowed');
        $factory->make();
    }

    public function test_factory_resolves_headers_by_reference_and_rejects_header_injection(): void
    {
        $secrets = Mockery::mock(IntegrationSecretReferenceResolver::class);
        $secrets->shouldReceive('resolve')
            ->once()
            ->with('file:///run/secrets/otel-headers.json')
            ->andReturn(json_encode(['Authorization' => "Bearer secret\r\nX-Injected: true"], JSON_THROW_ON_ERROR));
        config()->set('observability.otlp.headers_secret_ref', 'file:///run/secrets/otel-headers.json');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('observability_otlp_headers_invalid');
        (new OtlpExporterFactory($secrets, app(ClinicalContentGuard::class)))->make();
    }

    public function test_factory_disables_sdk_internal_exception_logging(): void
    {
        $secrets = Mockery::mock(IntegrationSecretReferenceResolver::class);
        config()->set('observability.otlp.endpoint', 'http://127.0.0.1:4318');
        config()->set('observability.otlp.allowed_hosts', ['127.0.0.1']);

        (new OtlpExporterFactory($secrets, app(ClinicalContentGuard::class)))->make();

        $this->assertInstanceOf(NoopLogWriter::class, Logging::logWriter());
    }

    private function attribute(iterable $attributes, string $key): mixed
    {
        foreach ($attributes as $attribute) {
            if ($attribute->getKey() === $key) {
                return $attribute->getValue()?->getStringValue();
            }
        }

        return null;
    }
}

final class CapturingOtlpTransport implements TransportInterface
{
    /** @var list<string> */
    public array $payloads = [];

    public function contentType(): string
    {
        return 'application/x-protobuf';
    }

    public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
    {
        $this->payloads[] = $payload;

        return new CompletedFuture(null);
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }
}
