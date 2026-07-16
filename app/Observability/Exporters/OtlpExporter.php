<?php

namespace App\Observability\Exporters;

use App\Observability\Contracts\MetricExporter as MetricExporterContract;
use App\Observability\Contracts\TraceExporter as TraceExporterContract;
use App\Observability\MetricSample;
use App\Observability\SpanAttributes;
use App\Observability\SpanRecord;
use Carbon\CarbonImmutable;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\GaugeInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use RuntimeException;

/** Official OpenTelemetry SDK adapter for already-guarded application records. */
final class OtlpExporter implements MetricExporterContract, TraceExporterContract
{
    private readonly MeterProvider $meterProvider;

    private readonly TracerProvider $tracerProvider;

    private readonly SafeSpanExporter $safeSpanExporter;

    /** @var array<string, CounterInterface|GaugeInterface|HistogramInterface> */
    private array $instruments = [];

    public function __construct(
        PushMetricExporterInterface $metricExporter,
        SpanExporterInterface $spanExporter,
        SpanAttributes $resourceAttributes,
    ) {
        $resource = ResourceInfo::create(Attributes::create($resourceAttributes->toArray()));
        $reader = new ExportingReader($metricExporter);
        $this->meterProvider = MeterProvider::builder()
            ->setResource($resource)
            ->addReader($reader)
            ->build();
        $this->safeSpanExporter = new SafeSpanExporter($spanExporter);
        $this->tracerProvider = TracerProvider::builder()
            ->setResource($resource)
            ->addSpanProcessor(new SimpleSpanProcessor($this->safeSpanExporter))
            ->build();
    }

    public function export(MetricSample|SpanRecord $record): void
    {
        if ($record instanceof MetricSample) {
            $this->exportMetric($record);

            return;
        }

        $this->exportSpan($record);
    }

    private function exportMetric(MetricSample $sample): void
    {
        $key = implode("\0", [$sample->instrument, $sample->name, $sample->unit]);
        $instrument = $this->instruments[$key] ??= match ($sample->instrument) {
            'counter' => $this->meterProvider->getMeter('zephyrus.integrations')->createCounter($sample->name, $sample->unit),
            'gauge' => $this->meterProvider->getMeter('zephyrus.integrations')->createGauge($sample->name, $sample->unit),
            'histogram' => $this->meterProvider->getMeter('zephyrus.integrations')->createHistogram($sample->name, $sample->unit),
        };
        match ($sample->instrument) {
            'counter' => $instrument->add($sample->value, $sample->attributes->toArray()),
            'gauge', 'histogram' => $instrument->record($sample->value, $sample->attributes->toArray()),
        };

        if (! $this->meterProvider->forceFlush()) {
            throw new RuntimeException('observability_otlp_metric_export_failed');
        }
    }

    private function exportSpan(SpanRecord $record): void
    {
        $startNanos = CarbonImmutable::parse($record->startedAtIso)->getTimestampMs() * 1_000_000;
        $endNanos = $startNanos + ($record->durationMs * 1_000_000);
        $this->safeSpanExporter->beginExport();
        $span = $this->tracerProvider
            ->getTracer('zephyrus.integrations')
            ->spanBuilder($record->name)
            ->setParent(false)
            ->setStartTimestamp($startNanos)
            ->setAttributes($record->attributes->toArray())
            ->startSpan();
        $span->setStatus(match ($record->status) {
            'ok' => StatusCode::STATUS_OK,
            'error' => StatusCode::STATUS_ERROR,
            default => StatusCode::STATUS_UNSET,
        });
        $span->end($endNanos);

        if (! $this->safeSpanExporter->lastExportSucceeded()) {
            throw new RuntimeException('observability_otlp_trace_export_failed');
        }
    }
}
