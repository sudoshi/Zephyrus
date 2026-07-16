<?php

namespace App\Observability;

/**
 * INT-OBS 4 — one PHI-safe span handed to a TraceExporter.
 *
 * Correlation from receipt through projection/outbound ACK rides on the
 * attributes: the AssignRequestIdentity UUID ('zephyrus.correlation.uuid') and
 * canonical event UUID ('zephyrus.event.uuid'). The selected exporter retains
 * the guarded shape in memory or serializes it through the official OTLP SDK.
 */
final class SpanRecord
{
    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly int $durationMs,
        public readonly SpanAttributes $attributes,
        public readonly string $startedAtIso,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status,
            'durationMs' => $this->durationMs,
            'startedAtIso' => $this->startedAtIso,
            'attributes' => $this->attributes->toArray(),
        ];
    }
}
