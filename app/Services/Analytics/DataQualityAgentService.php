<?php

namespace App\Services\Analytics;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DataQualityAgentService
{
    public function __construct(private readonly MetricLineageService $lineage) {}

    /**
     * @param  array<int,array<string,mixed>>  $analyticsChecks
     * @return array<string,mixed>
     */
    public function run(array $analyticsChecks = []): array
    {
        $checks = [
            ...$this->normalizeAnalyticsChecks($analyticsChecks),
            ...$this->sourceFreshnessChecks(),
            ...$this->metricCatalogChecks(),
        ];

        $this->lineage->recordDataQualityFindings($checks);

        $findings = collect($checks)
            ->reject(fn (array $check): bool => $check['status'] === 'success')
            ->map(fn (array $check): array => [
                'key' => $check['key'],
                'label' => $check['label'],
                'status' => $check['status'],
                'severity' => $check['status'],
                'sourceKey' => $check['sourceKey'] ?? null,
                'detail' => $check['detail'],
                'lineage' => $check['lineage'] ?? null,
                'recommendedAction' => $check['recommendedAction'] ?? $this->recommendedAction($check),
            ])
            ->values()
            ->all();

        return [
            'key' => 'data_quality_agent',
            'label' => 'Data Quality Agent',
            'mode' => 'rules_only',
            'llmEnabled' => false,
            'generatedAtIso' => now()->toIso8601String(),
            'status' => $this->overallStatus($checks),
            'summary' => [
                'checksEvaluated' => count($checks),
                'issuesOpen' => count($findings),
                'critical' => collect($findings)->where('status', 'critical')->count(),
                'warning' => collect($findings)->where('status', 'warning')->count(),
                'passing' => collect($checks)->where('status', 'success')->count(),
            ],
            'rules' => [
                [
                    'key' => 'analytics_governance_checks',
                    'label' => 'Analytics governance checks',
                    'scope' => 'Freshness, completeness, and ownership checks emitted by the analytics engine.',
                ],
                [
                    'key' => 'source_freshness',
                    'label' => 'Source freshness',
                    'scope' => 'Every registered source must have records and remain within its expected lag window.',
                ],
                [
                    'key' => 'metric_catalog_completeness',
                    'label' => 'Metric catalog completeness',
                    'scope' => 'Every curated metric must define ownership, direction, cadence, and known source keys.',
                ],
            ],
            'findings' => $findings,
            'nextActions' => collect($findings)
                ->take(5)
                ->map(fn (array $finding): string => $finding['recommendedAction'])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $analyticsChecks
     * @return array<int,array<string,mixed>>
     */
    private function normalizeAnalyticsChecks(array $analyticsChecks): array
    {
        return collect($analyticsChecks)
            ->map(fn (array $check): array => [
                'key' => Str::snake((string) ($check['key'] ?? $check['label'])),
                'label' => (string) $check['label'],
                'status' => (string) $check['status'],
                'detail' => (string) $check['detail'],
                'sourceKey' => $check['sourceKey'] ?? null,
                'lineage' => $check['lineage'] ?? null,
                'measuredValue' => $check['measuredValue'] ?? null,
                'thresholdValue' => $check['thresholdValue'] ?? null,
                'recommendedAction' => $check['status'] === 'success'
                    ? 'Continue monitoring the current analytics governance check.'
                    : "Review {$check['label']} before using dependent operational metrics.",
            ])
            ->values()
            ->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function sourceFreshnessChecks(): array
    {
        return collect(array_keys($this->lineage->sourceCatalog()))
            ->map(function (string $sourceKey): array {
                $source = $this->lineage->freshnessForSource($sourceKey);
                $sourceName = (string) ($source['source'] ?? $sourceKey);
                $freshnessColumn = (string) ($source['freshnessColumn'] ?? 'unknown freshness column');
                $status = (string) $source['status'];
                $recordCount = (int) ($source['recordCount'] ?? 0);

                if ($recordCount === 0 && $status !== 'critical') {
                    $status = 'warning';
                }

                return [
                    'key' => "source_{$sourceKey}_freshness",
                    'label' => "{$source['label']} source freshness",
                    'status' => $status,
                    'detail' => $recordCount === 0
                        ? "{$source['label']} has no records available to analytics."
                        : "{$source['label']} latest observation is {$source['freshnessLabel']}.",
                    'sourceKey' => $sourceKey,
                    'lineage' => "{$sourceName} {$freshnessColumn}",
                    'measuredValue' => $source['freshnessLabel'],
                    'thresholdValue' => "{$source['expectedLagMinutes']}m expected / {$source['warningLagMinutes']}m warning",
                    'recommendedAction' => $recordCount === 0
                        ? "Confirm {$source['label']} ingestion is configured and producing records."
                        : "Review {$source['label']} source latency and upstream job health.",
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function metricCatalogChecks(): array
    {
        $sourceKeys = array_keys($this->lineage->sourceCatalog());

        return collect($this->lineage->metricCatalog())
            ->map(function (array $metric, string $metricKey) use ($sourceKeys): array {
                $missing = collect(['label', 'domain', 'definition', 'owner', 'direction', 'cadence'])
                    ->filter(fn (string $field): bool => blank($metric[$field] ?? null))
                    ->values();
                $unknownSources = collect($metric['source_keys'] ?? [])
                    ->reject(fn (string $sourceKey): bool => in_array($sourceKey, $sourceKeys, true))
                    ->values();
                $status = $missing->isNotEmpty() || $unknownSources->isNotEmpty()
                    ? 'critical'
                    : 'success';

                return [
                    'key' => "metric_{$metricKey}_catalog",
                    'label' => "{$metric['label']} metric catalog",
                    'status' => $status,
                    'detail' => $status === 'success'
                        ? "{$metric['label']} has a complete metric definition and known sources."
                        : $this->catalogGapDetail($missing, $unknownSources),
                    'sourceKey' => null,
                    'lineage' => "ops.metric_definitions {$metricKey}",
                    'measuredValue' => $status === 'success' ? 'complete' : 'incomplete',
                    'thresholdValue' => 'label, domain, definition, owner, direction, cadence, known source keys',
                    'recommendedAction' => "Complete the {$metric['label']} metric definition before promoting it to governed use.",
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,string>  $missing
     * @param  Collection<int,string>  $unknownSources
     */
    private function catalogGapDetail(Collection $missing, Collection $unknownSources): string
    {
        $parts = [];
        if ($missing->isNotEmpty()) {
            $parts[] = 'missing fields: '.$missing->implode(', ');
        }
        if ($unknownSources->isNotEmpty()) {
            $parts[] = 'unknown sources: '.$unknownSources->implode(', ');
        }

        return implode('; ', $parts);
    }

    /** @param array<int,array<string,mixed>> $checks */
    private function overallStatus(array $checks): string
    {
        $statuses = collect($checks)->pluck('status');
        if ($statuses->contains('critical')) {
            return 'critical';
        }
        if ($statuses->contains('warning')) {
            return 'warning';
        }

        return 'success';
    }

    /** @param array<string,mixed> $check */
    private function recommendedAction(array $check): string
    {
        if (($check['sourceKey'] ?? null) !== null) {
            return "Review {$check['label']} source health and dependent metric lineage.";
        }

        return "Review {$check['label']} with Analytics governance.";
    }
}
