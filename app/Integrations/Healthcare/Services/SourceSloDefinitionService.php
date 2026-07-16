<?php

namespace App\Integrations\Healthcare\Services;

use App\Security\ClinicalPayloads\ClinicalContentGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Normalizes each immutable onboarding SLO document into a queryable authority.
 *
 * The onboarding version remains the configuration source. This ledger is a
 * one-to-one, append-only materialization and cannot drift independently.
 */
final class SourceSloDefinitionService
{
    /** @var list<string> */
    public const REQUIRED_KEYS = [
        'availability_percent',
        'freshness_minutes',
        'completeness_percent',
        'latency_ms',
        'error_rate_percent',
        'acknowledgement_seconds',
        'reconciliation_variance_percent',
    ];

    public function __construct(private readonly ClinicalContentGuard $contentGuard) {}

    public function recordForOnboarding(object $onboarding): object
    {
        return DB::transaction(function () use ($onboarding): object {
            $existing = DB::table('integration.source_slo_definitions')
                ->where('source_onboarding_version_id', (int) $onboarding->source_onboarding_version_id)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $sourceId = (int) $onboarding->source_id;
            $versionNumber = (int) $onboarding->version_number;
            $previous = DB::table('integration.source_slo_definitions')
                ->where('source_id', $sourceId)
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->first();
            $definition = $this->normalize($onboarding->slo_definition);
            $row = [
                'slo_definition_uuid' => (string) Str::uuid7(),
                'source_id' => $sourceId,
                'source_onboarding_version_id' => (int) $onboarding->source_onboarding_version_id,
                'version_number' => $versionNumber,
                'previous_definition_id' => $previous?->source_slo_definition_id,
                ...$definition,
                'definition_sha256' => $this->hash($definition),
                'created_by_user_id' => $onboarding->created_by_user_id,
                'created_at' => $onboarding->created_at ?? now(),
            ];
            $this->contentGuard->assertSafe($row, 'source_slo_definition_content_rejected');

            $definitionId = (int) DB::table('integration.source_slo_definitions')->insertGetId(
                $row,
                'source_slo_definition_id',
            );

            return DB::table('integration.source_slo_definitions')
                ->where('source_slo_definition_id', $definitionId)
                ->firstOrFail();
        });
    }

    public function forOnboarding(object $onboarding): object
    {
        return DB::table('integration.source_slo_definitions')
            ->where('source_onboarding_version_id', (int) $onboarding->source_onboarding_version_id)
            ->first() ?? $this->recordForOnboarding($onboarding);
    }

    /** @return array<string, int|float|string|null> */
    public function normalize(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        $decoded = is_array($decoded) ? $decoded : [];
        $definition = [
            'evaluation_window_minutes' => $this->integer($decoded['evaluation_window_minutes'] ?? 1440, 5, 10080) ?? 1440,
            'availability_percent' => $this->decimal($decoded['availability_percent'] ?? null, 90, 100),
            'freshness_minutes' => $this->integer($decoded['freshness_minutes'] ?? null, 1, 10080),
            'completeness_percent' => $this->decimal($decoded['completeness_percent'] ?? null, 0, 100),
            'latency_ms' => $this->integer($decoded['latency_ms'] ?? null, 1, 3600000),
            'error_rate_percent' => $this->decimal($decoded['error_rate_percent'] ?? null, 0, 100),
            'acknowledgement_seconds' => $this->integer($decoded['acknowledgement_seconds'] ?? null, 1, 86400),
            'reconciliation_variance_percent' => $this->decimal($decoded['reconciliation_variance_percent'] ?? null, 0, 100),
        ];
        $complete = collect(self::REQUIRED_KEYS)->every(fn (string $key): bool => $definition[$key] !== null);

        return ['definition_status' => $complete ? 'complete' : 'incomplete', ...$definition];
    }

    private function integer(mixed $value, int $minimum, int $maximum): ?int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            return null;
        }
        $value = (int) $value;

        return $value >= $minimum && $value <= $maximum ? $value : null;
    }

    private function decimal(mixed $value, float $minimum, float $maximum): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $value = (float) $value;

        return is_finite($value) && $value >= $minimum && $value <= $maximum ? $value : null;
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        ksort($value);

        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
