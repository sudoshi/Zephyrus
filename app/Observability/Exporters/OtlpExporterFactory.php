<?php

namespace App\Observability\Exporters;

use App\Integrations\Healthcare\Services\IntegrationSecretReferenceResolver;
use App\Observability\SpanAttributes;
use App\Security\ClinicalPayloads\ClinicalContentGuard;
use InvalidArgumentException;
use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Metrics\Data\Temporality;

/** Builds the official OTLP/HTTP protobuf exporters from deployment-owned config. */
final class OtlpExporterFactory
{
    private const CONTENT_TYPE = 'application/x-protobuf';

    public function __construct(
        private readonly IntegrationSecretReferenceResolver $secrets,
        private readonly ClinicalContentGuard $guard,
    ) {}

    public function make(): OtlpExporter
    {
        // The SDK can otherwise log transport exceptions and PSR request context.
        // Zephyrus owns the failure log and emits stable codes only.
        Logging::disable();

        $headers = $this->headers((string) config('observability.otlp.headers_secret_ref', ''));
        $compression = $this->compression((string) config('observability.otlp.compression', 'gzip'));
        $timeout = max(0.1, min(30.0, (float) config('observability.otlp.timeout_seconds', 0.25)));
        $retryDelay = max(10, min(60_000, (int) config('observability.otlp.retry_delay_ms', 50)));
        $maxRetries = max(0, min(10, (int) config('observability.otlp.max_retries', 0)));
        $transportFactory = new OtlpHttpTransportFactory;
        $metricTransport = $transportFactory->create(
            $this->endpoint('metrics'),
            self::CONTENT_TYPE,
            $headers,
            $compression,
            $timeout,
            $retryDelay,
            $maxRetries,
        );
        $spanTransport = $transportFactory->create(
            $this->endpoint('traces'),
            self::CONTENT_TYPE,
            $headers,
            $compression,
            $timeout,
            $retryDelay,
            $maxRetries,
        );

        return new OtlpExporter(
            new MetricExporter($metricTransport, Temporality::DELTA),
            new SpanExporter($spanTransport),
            SpanAttributes::make([
                'service.name' => (string) config('observability.service.name', 'zephyrus'),
                'service.namespace' => (string) config('observability.service.namespace', 'zephyrus'),
                'service.version' => (string) config('observability.service.version', 'unknown'),
                'deployment.environment.name' => (string) config('observability.service.environment', 'production'),
            ], $this->guard),
        );
    }

    private function endpoint(string $signal): string
    {
        $specific = trim((string) config("observability.otlp.{$signal}_endpoint", ''));
        $endpoint = $specific !== ''
            ? $specific
            : rtrim((string) config('observability.otlp.endpoint', 'http://127.0.0.1:4318'), '/').'/v1/'.$signal;
        $parts = parse_url($endpoint);
        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
            throw new InvalidArgumentException('observability_otlp_endpoint_invalid');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $allowedHosts = array_map(
            static fn (mixed $value): string => strtolower(rtrim(trim((string) $value), '.')),
            (array) config('observability.otlp.allowed_hosts', []),
        );
        if (! in_array($host, $allowedHosts, true)) {
            throw new InvalidArgumentException('observability_otlp_host_not_allowed');
        }
        $loopback = in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
        if ($scheme !== 'https' && ! ($scheme === 'http' && $loopback)) {
            throw new InvalidArgumentException('observability_otlp_transport_not_secure');
        }

        return $endpoint;
    }

    /** @return array<string, string> */
    private function headers(string $reference): array
    {
        $reference = trim($reference);
        if ($reference === '') {
            return [];
        }

        $decoded = json_decode($this->secrets->resolve($reference), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('observability_otlp_headers_invalid');
        }

        $headers = [];
        foreach ($decoded as $name => $value) {
            if (! is_string($name)
                || preg_match('/^[A-Za-z][A-Za-z0-9-]{0,63}$/', $name) !== 1
                || ! is_string($value)
                || $value === ''
                || strlen($value) > 4096
                || preg_match('/[\r\n]/', $value) === 1) {
                throw new InvalidArgumentException('observability_otlp_headers_invalid');
            }
            $headers[$name] = $value;
        }

        return $headers;
    }

    private function compression(string $value): string
    {
        $value = strtolower(trim($value));
        if (! in_array($value, ['none', 'gzip'], true)) {
            throw new InvalidArgumentException('observability_otlp_compression_invalid');
        }

        return $value;
    }
}
