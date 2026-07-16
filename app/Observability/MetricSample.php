<?php

namespace App\Observability;

/**
 * INT-OBS 4 — one PHI-safe metric point handed to a MetricExporter.
 *
 * Shapes map 1:1 onto OpenTelemetry metric instruments: 'counter' (monotonic
 * sum), 'gauge' (last value), 'histogram' (distribution recording). The
 * attributes are always a validated SpanAttributes bag.
 */
final class MetricSample
{
    /** @param 'counter'|'gauge'|'histogram' $instrument */
    public function __construct(
        public readonly string $name,
        public readonly string $instrument,
        public readonly int|float $value,
        public readonly SpanAttributes $attributes,
        public readonly string $unit,
        public readonly string $recordedAtIso,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'instrument' => $this->instrument,
            'value' => $this->value,
            'unit' => $this->unit,
            'recordedAtIso' => $this->recordedAtIso,
            'attributes' => $this->attributes->toArray(),
        ];
    }
}
