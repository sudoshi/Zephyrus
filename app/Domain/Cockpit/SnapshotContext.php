<?php

namespace App\Domain\Cockpit;

use App\Models\Ops\MetricDefinition;
use App\Support\Cockpit\MetricValue;
use Illuminate\Support\Collection;

/**
 * Shared build context handed to every Domain/Cockpit/Metrics provider.
 *
 * Carries the legacy CommandCenterDataService::build() payload so any number
 * the cockpit shares with /dashboard is read from the SAME computed value —
 * never recomputed (the single-snapshot discipline, and how the bed-board
 * occupancy fallback is preserved for free: occupancy here IS the dashboard's
 * occupancy). Providers only run their own queries for cockpit-only values.
 *
 * Also accumulates every emitted MetricValue so later providers (OkrMetrics
 * runs last) can reuse earlier values instead of re-querying.
 */
class SnapshotContext
{
    /** @var array<string, array<string, mixed>>|null lazy index of legacy metrics by key */
    private ?array $legacyIndex = null;

    /** @var array<string, MetricValue> */
    private array $emitted = [];

    /**
     * @param  array<string, mixed>  $legacy  the CommandCenterDataService payload
     * @param  Collection<string, MetricDefinition>  $definitions  keyed by metric_key
     */
    public function __construct(
        public readonly array $legacy,
        public readonly Collection $definitions,
        public readonly string $nowIso,
    ) {}

    public function definition(string $key): ?MetricDefinition
    {
        return $this->definitions->get($key);
    }

    /**
     * Find a legacy dashboard metric (heroMetrics / capacity / flow subgroups /
     * outcomes / forecast) by its legacy key.
     *
     * @return array<string, mixed>|null
     */
    public function legacyMetric(string $key): ?array
    {
        return $this->legacyIndex()[$key] ?? null;
    }

    public function legacyValue(string $key): ?float
    {
        $metric = $this->legacyMetric($key);

        return isset($metric['value']) && is_numeric($metric['value'])
            ? (float) $metric['value']
            : null;
    }

    /**
     * Tail of the legacy 90-day trajectory for a metric — a ready-made
     * sparkline until ops.metric_values accrues real history.
     *
     * @return list<int|float>
     */
    public function legacyTrend(string $key, int $points = 7): array
    {
        $trajectory = $this->legacyMetric($key)['trajectory']['points'] ?? null;

        return is_array($trajectory) ? array_slice($trajectory, -$points) : [];
    }

    public function remember(MetricValue $value): MetricValue
    {
        $this->emitted[$value->key] = $value;

        return $value;
    }

    public function emittedValue(string $key): ?MetricValue
    {
        return $this->emitted[$key] ?? null;
    }

    /** @return array<string, MetricValue> */
    public function allEmitted(): array
    {
        return $this->emitted;
    }

    /** @return array<string, array<string, mixed>> */
    private function legacyIndex(): array
    {
        if ($this->legacyIndex !== null) {
            return $this->legacyIndex;
        }

        $index = [];
        $collect = function (array $metrics) use (&$index): void {
            foreach ($metrics as $metric) {
                if (isset($metric['key'])) {
                    $index[$metric['key']] = $metric;
                }
            }
        };

        $collect($this->legacy['heroMetrics'] ?? []);

        foreach (['capacity', 'outcomes', 'forecast'] as $band) {
            $collect($this->legacy[$band]['metrics'] ?? []);
        }

        foreach ($this->legacy['flow']['subgroups'] ?? [] as $subgroup) {
            $collect($subgroup['metrics'] ?? []);
        }

        return $this->legacyIndex = $index;
    }
}
