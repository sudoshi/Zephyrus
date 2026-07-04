<?php

namespace App\Domain\Cockpit\Metrics;

use App\Domain\Cockpit\SnapshotContext;
use App\Services\Cockpit\StatusEngine;
use App\Support\Cockpit\MetricValue;

/**
 * Base for the Domain/Cockpit/Metrics adapters (Zephyrus 2.0 P1). Each
 * provider is a THIN adapter over existing computed values (the legacy
 * payload via SnapshotContext) and existing domain services — never a new
 * query battery. Providers emit MetricValue[] only; status is resolved
 * exactly once through StatusEngine + ops.metric_definitions.
 *
 * A metric with no seeded definition is skipped (fail-open): the catalog in
 * CockpitKpiDefinitionSeeder is the contract for what the cockpit shows.
 */
abstract class BaseMetrics
{
    public function __construct(protected readonly StatusEngine $engine) {}

    /** The Appendix-A key prefix this provider owns ('rtdc', 'ed', ...). */
    abstract public function domain(): string;

    /** @return list<MetricValue> */
    abstract public function metrics(SnapshotContext $ctx): array;

    /**
     * Build a MetricValue from its seeded definition; null when the catalog
     * has no row for the key (the metric simply doesn't appear).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function fromKey(SnapshotContext $ctx, string $key, ?float $value, array $overrides = []): ?MetricValue
    {
        $definition = $ctx->definition($key);

        if ($definition === null || $value === null) {
            return null;
        }

        $overrides['updatedAt'] ??= $ctx->nowIso;

        return $ctx->remember(MetricValue::fromDefinition($value, $definition, $this->engine, $overrides));
    }

    /**
     * A mocked metric (D5): value from config('cockpit.demo_values'),
     * always badged metadata.provenance='demo'.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function demo(SnapshotContext $ctx, string $key, array $overrides = []): ?MetricValue
    {
        // Metric keys contain dots — config() dot-notation would mis-traverse
        // them, so index the demo map directly.
        $value = config('cockpit.demo_values')[$key] ?? null;

        if ($value === null) {
            return null;
        }

        $overrides['metadata'] = ($overrides['metadata'] ?? []) + ['provenance' => 'demo'];

        return $this->fromKey($ctx, $key, (float) $value, $overrides);
    }

    /**
     * A live metric sourced from the legacy dashboard payload — guaranteed
     * identical to what /dashboard renders, sparkline included.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function fromLegacy(SnapshotContext $ctx, string $key, string $legacyKey, array $overrides = []): ?MetricValue
    {
        $value = $ctx->legacyValue($legacyKey);

        if ($value === null) {
            return null;
        }

        $overrides['trend'] ??= $ctx->legacyTrend($legacyKey);

        return $this->fromKey($ctx, $key, $value, $overrides);
    }

    /** @param list<MetricValue|null> $values
     * @return list<MetricValue> */
    protected function compact(array $values): array
    {
        return array_values(array_filter($values));
    }
}
