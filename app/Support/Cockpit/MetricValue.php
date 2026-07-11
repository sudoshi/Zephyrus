<?php

namespace App\Support\Cockpit;

use App\Enums\CockpitStatus;
use App\Models\Ops\MetricDefinition;
use App\Services\Cockpit\StatusEngine;
use App\Support\Operations\DurationFormatter;

/**
 * Immutable canonical metric value (spec §3.1) — the ONE shape every
 * Domain/Cockpit/Metrics adapter emits from P1 onward, mirrored by
 * cockpitMetricValueSchema in resources/js/types/cockpit.ts. `status` is the
 * LOGICAL 5-state name; the canon token bridge lives client-side.
 */
final readonly class MetricValue
{
    /**
     * @param  list<int|float>  $trend
     * @param  array<string, mixed>  $metadata  carries provenance ('demo' for
     *                                          mocked domains per D5), watch-band config, etc.
     */
    public function __construct(
        public string $key,
        public string $label,
        public float $value,
        public string $display,
        public ?string $unit,
        public ?string $sub,
        public CockpitStatus $status,
        public ?float $target,
        public string $direction,
        public array $trend,
        public ?string $trendLabel,
        public string $updatedAt,
        public array $metadata = [],
    ) {}

    /**
     * Build from a raw number + its kpi_definition, resolving status through
     * the StatusEngine exactly once.
     *
     * @param  array{label?: string, display?: string, sub?: string, trend?: list<int|float>, trendLabel?: string, updatedAt?: string, metadata?: array<string, mixed>}  $overrides
     */
    public static function fromDefinition(
        float $value,
        MetricDefinition $definition,
        StatusEngine $engine,
        array $overrides = [],
    ): self {
        // Existing installations may still carry the original ambiguous unit.
        $unit = $definition->metric_key === 'service.avoidable_days'
            ? 'bed-days'
            : $definition->unit;

        return new self(
            key: $definition->metric_key,
            label: $overrides['label'] ?? $definition->label,
            value: $value,
            display: $overrides['display'] ?? self::defaultDisplay($value, $unit),
            unit: $unit,
            sub: $overrides['sub'] ?? null,
            status: $engine->resolveStatus($value, $definition),
            target: $definition->target_value !== null ? (float) $definition->target_value : null,
            direction: $definition->direction ?? 'neutral',
            trend: $overrides['trend'] ?? [],
            trendLabel: $overrides['trendLabel'] ?? null,
            updatedAt: $overrides['updatedAt'] ?? now()->toIso8601String(),
            metadata: $overrides['metadata'] ?? [],
        );
    }

    private static function defaultDisplay(float $value, ?string $unit): string
    {
        $formatted = fmod($value, 1.0) === 0.0
            ? number_format($value)
            : rtrim(rtrim(number_format($value, 2), '0'), '.');

        return match ($unit) {
            '%' => $formatted.'%',
            's', 'sec', 'secs', 'second', 'seconds' => DurationFormatter::seconds($value),
            'm', 'min', 'mins', 'minute', 'minutes' => DurationFormatter::minutes($value),
            'h', 'hr', 'hrs', 'hour', 'hours' => DurationFormatter::minutes($value * 60),
            null, '' => $formatted,
            default => $formatted.' '.$unit,
        };
    }

    /**
     * Spec §3.1 wire shape. `metadata` is omitted when empty rather than
     * serialized as [] (json_encode([]) yields an array, not an object, and
     * would fail the Zod record schema).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'key' => $this->key,
            'label' => $this->label,
            'value' => $this->value,
            'display' => $this->display,
            'unit' => $this->unit,
            'sub' => $this->sub,
            'status' => $this->status->value,
            'target' => $this->target,
            'direction' => $this->direction,
            'trend' => $this->trend,
            'trendLabel' => $this->trendLabel,
            'updatedAt' => $this->updatedAt,
        ];

        if ($this->metadata !== []) {
            $payload['metadata'] = $this->metadata;
        }

        return $payload;
    }
}
