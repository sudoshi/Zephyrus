<?php

namespace App\Services\Lab;

use App\Data\Ancillary\FreshnessEnvelope;
use App\Services\Ancillary\AncillaryContractSerializer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AnatomicPathologyService
{
    public const STAGES = ['specimen_out', 'received', 'grossed', 'processing', 'slides_ready', 'diagnosed', 'signed_out'];

    public const COHORTS = ['routine', 'complex', 'consult_send_out', 'frozen_section'];

    public const STATUSES = ['all', 'open', 'completed'];

    public const AGE_BANDS = ['under_4h', '4_to_8h', '8_to_24h', '24_to_48h', '48_plus', 'complete'];

    private const TIMELINE = [
        'received' => ['Received', 'received_at'],
        'grossed' => ['Grossed', 'grossed_at'],
        'processing' => ['Processing batch', 'processing_batch_at'],
        'slides_ready' => ['Slides ready', 'slides_ready_at'],
        'diagnosed' => ['Diagnosed', 'diagnosed_at'],
        'signed_out' => ['Signed out', 'signed_out_at'],
    ];

    public function __construct(private readonly AncillaryContractSerializer $contracts) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function build(array $input = []): array
    {
        $filters = $this->filters($input);
        $rows = $this->query()
            ->when($filters['caseId'], fn (Builder $query, int $caseId): Builder => $query->where('a.case_id', $caseId))
            ->when($filters['caseId'] === null, fn (Builder $query): Builder => $query->where('a.current_stage_at', '>=', now()->subDays(7)))
            ->orderByRaw("CASE WHEN a.stage IN ('signed_out', 'cancelled') THEN 1 ELSE 0 END")
            ->orderBy('a.current_stage_at')->orderBy('a.ap_case_id')->limit(500)->get();
        $freshness = $this->freshness($rows->pluck('source_cutoff_at')->filter()->min());
        $coverage = $this->coverage($rows);

        $all = $rows->map(fn (object $row): array => $this->item($row, $freshness));
        $filtered = $all->filter(function (array $item) use ($filters): bool {
            if ($filters['stage'] !== 'all' && $item['stage'] !== $filters['stage']) {
                return false;
            }
            if ($filters['cohort'] !== 'all' && $item['cohort'] !== $filters['cohort']) {
                return false;
            }
            if ($filters['status'] === 'open' && $item['terminal']) {
                return false;
            }
            if ($filters['status'] === 'completed' && ! $item['terminal']) {
                return false;
            }

            return $filters['ageBand'] === 'all' || $item['ageBand'] === $filters['ageBand'];
        })->values();
        $registered = strtolower((string) (DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->value('status') ?? ''));
        $state = match (true) {
            in_array($registered, ['error', 'failed', 'unavailable'], true) => 'source_error',
            $filtered->isEmpty() => 'no_data',
            $freshness['status'] === 'stale' => 'stale',
            collect($coverage)->contains(fn (array $entry): bool => $entry['status'] === 'missing') => 'degraded',
            default => 'normal',
        };
        $visible = $filtered->take($filters['limit'])->values();

        return [
            'generatedAt' => now()->toAtomString(),
            'lookbackDays' => 7,
            'state' => $state,
            'stateMessage' => match ($state) {
                'source_error' => 'AP source health reports an error. Last-known cases remain cutoff-qualified and timers are withheld.',
                'no_data' => 'No anatomic-pathology cases match the selected seven-day operational window and filters.',
                'stale' => 'AP evidence is stale. Case stages remain visible as last known, but live frozen-section timers are withheld.',
                'degraded' => 'AP-LIS or governed backfill detail is incomplete; available stages remain visible without inventing missing evidence.',
                default => 'Anatomic-pathology stages and frozen-section evidence are current.',
            },
            'freshness' => $freshness,
            'filters' => $filters,
            'filterOptions' => [
                'stages' => ['all', ...self::STAGES],
                'cohorts' => ['all', ...self::COHORTS],
                'statuses' => self::STATUSES,
                'ageBands' => ['all', ...self::AGE_BANDS],
            ],
            'summary' => [
                'visible' => $visible->count(),
                'matchingBeforeLimit' => $filtered->count(),
                'open' => $filtered->where('terminal', false)->count(),
                'completed' => $filtered->where('terminal', true)->count(),
                'activeFrozen' => $filtered->where('frozen.timerActive', true)->count(),
                'byStage' => collect(self::STAGES)->map(fn (string $stage): array => [
                    'stage' => $stage,
                    'label' => self::stageLabel($stage),
                    'count' => $filtered->where('stage', $stage)->count(),
                ])->all(),
                'byCohort' => collect(self::COHORTS)->map(fn (string $cohort): array => [
                    'cohort' => $cohort,
                    'label' => self::cohortLabel($cohort),
                    'count' => $filtered->where('cohort', $cohort)->count(),
                ])->all(),
            ],
            'benchmarkLines' => $this->benchmarkLines(),
            'coverage' => $coverage,
            'data' => $visible->all(),
            'privacy' => [
                'directPatientIdentifiersIncluded' => false,
                'diagnosisOrNarrativeIncluded' => false,
                'writebackIncluded' => false,
                'explanation' => 'The board exposes source-scoped case/accession identity, stage evidence, and OR-case linkage only; patient identity, diagnosis, narrative, and source-system commands are excluded.',
            ],
        ];
    }

    /**
     * @param  list<int>  $caseIds
     * @param  list<int>  $activeProcedureCaseIds
     * @return Collection<int, array<string, mixed>>
     */
    public function frozenTimersForCases(array $caseIds, array $activeProcedureCaseIds): Collection
    {
        $ids = collect($caseIds)->map(fn (mixed $id): int => (int) $id)->filter()->unique();
        $active = collect($activeProcedureCaseIds)->map(fn (mixed $id): int => (int) $id)->filter()->unique();
        $eligible = $ids->intersect($active)->values()->all();
        if ($eligible === []) {
            return collect();
        }
        $rows = $this->query()->whereIn('a.case_id', $eligible)
            ->where('a.frozen_status', 'in_progress')->whereNotNull('a.frozen_started_at')->whereNull('a.frozen_resulted_at')
            ->orderByDesc('a.frozen_started_at')->get();
        $freshness = $this->freshness($rows->pluck('source_cutoff_at')->filter()->min());
        if ($freshness['status'] !== 'fresh') {
            return collect();
        }

        return $rows->map(fn (object $row): array => $this->timer($row))->keyBy('caseId');
    }

    /** @return array<string, mixed> */
    private function filters(array $input): array
    {
        $stage = is_string($input['stage'] ?? null) && in_array($input['stage'], ['all', ...self::STAGES], true) ? $input['stage'] : 'all';
        $cohort = is_string($input['cohort'] ?? null) && in_array($input['cohort'], ['all', ...self::COHORTS], true) ? $input['cohort'] : 'all';
        $status = is_string($input['status'] ?? null) && in_array($input['status'], self::STATUSES, true) ? $input['status'] : 'all';
        $ageBand = is_string($input['ageBand'] ?? null) && in_array($input['ageBand'], ['all', ...self::AGE_BANDS], true) ? $input['ageBand'] : 'all';
        $caseId = filter_var($input['caseId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return [
            'stage' => $stage,
            'cohort' => $cohort,
            'status' => $status,
            'ageBand' => $ageBand,
            'caseId' => $caseId === false ? null : $caseId,
            'limit' => min(100, max(1, (int) ($input['limit'] ?? 50))),
        ];
    }

    private function query(): Builder
    {
        return DB::table('prod.ap_cases as a')
            ->join('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 'a.ancillary_order_id')
            ->join('integration.sources as source', 'source.source_id', '=', 'a.source_id')
            ->leftJoin('prod.or_cases as oc', function ($join): void {
                $join->on('oc.case_id', '=', 'a.case_id')->where('oc.is_deleted', false);
            })
            ->whereNull('a.cancelled_at')->where('a.stage', '<>', 'cancelled')
            ->select([
                'a.*', 'o.order_uuid', 'o.source_cutoff_at', 'source.source_key', 'source.source_name',
                'source.system_class', 'source.bulk_supported', 'oc.case_id as valid_case_id',
                'oc.surgery_date', 'oc.scheduled_start_time',
            ]);
    }

    /** @param array<string, mixed> $freshness @return array<string, mixed> */
    private function item(object $row, array $freshness): array
    {
        $metadata = $this->json($row->metadata);
        $cohort = $this->cohort($row, $metadata);
        $terminal = $row->stage === 'signed_out';
        $stageAge = $terminal ? null : $this->elapsed($row->current_stage_at);
        $totalStart = $row->received_at ?? $row->specimen_out_at ?? $row->current_stage_at;
        $totalEnd = $terminal ? $row->signed_out_at : now();
        $totalAge = max(0, (int) floor(CarbonImmutable::parse($totalStart)->diffInSeconds(CarbonImmutable::parse($totalEnd), false) / 60));
        $frozenTimer = $row->frozen_status === 'in_progress' && $row->frozen_started_at !== null
            && $row->frozen_resulted_at === null && $row->valid_case_id !== null && $freshness['status'] === 'fresh'
            ? $this->timer($row) : null;
        $processingModel = (string) ($metadata['processing_model'] ?? 'continuous');

        return [
            'apCaseUuid' => (string) $row->ap_case_uuid,
            'orderUuid' => (string) $row->order_uuid,
            'caseId' => $row->valid_case_id === null ? null : (int) $row->valid_case_id,
            'caseLabel' => $row->valid_case_id === null ? null : 'OR case '.(int) $row->valid_case_id,
            'sourceCaseKey' => (string) $row->source_case_key,
            'sourceAccessionKey' => $row->source_accession_key,
            'sourceKey' => (string) $row->source_key,
            'procedureLabel' => (string) ($row->procedure_label ?: 'Anatomic pathology case'),
            'caseType' => (string) $row->case_type,
            'cohort' => $cohort,
            'cohortLabel' => self::cohortLabel($cohort),
            'stage' => (string) $row->stage,
            'stageLabel' => self::stageLabel((string) $row->stage),
            'currentStageAt' => CarbonImmutable::parse($row->current_stage_at)->toAtomString(),
            'stageAgeMinutes' => $stageAge,
            'totalAgeMinutes' => $totalAge,
            'ageBand' => $terminal ? 'complete' : $this->ageBand((int) $stageAge),
            'terminal' => $terminal,
            'timeline' => collect(self::TIMELINE)->map(function (array $definition, string $stage) use ($row): array {
                $at = $row->{$definition[1]};
                $rank = array_search($stage, array_keys(self::TIMELINE), true);
                $currentRank = $row->stage === 'specimen_out' ? -1 : array_search($row->stage, array_keys(self::TIMELINE), true);

                return [
                    'stage' => $stage,
                    'label' => $definition[0],
                    'at' => $at === null ? null : CarbonImmutable::parse($at)->toAtomString(),
                    'state' => $at !== null ? ($row->stage === $stage && ! in_array($stage, ['signed_out'], true) ? 'current' : 'complete')
                        : (($currentRank !== false && $rank !== false && $rank > $currentRank) ? 'pending' : 'not_asserted'),
                ];
            })->values()->all(),
            'structuralStage' => [
                'kind' => $cohort === 'consult_send_out' ? 'send_out' : (str_contains($processingModel, 'overnight_batch') ? 'overnight_batch' : 'none'),
                'label' => $cohort === 'consult_send_out' ? 'Consult / send-out handoff' : (str_contains($processingModel, 'overnight_batch') ? 'Overnight histology batch' : null),
                'enteredAt' => $row->processing_batch_at === null ? null : CarbonImmutable::parse($row->processing_batch_at)->toAtomString(),
                'explanation' => $cohort === 'consult_send_out'
                    ? 'External consultation or send-out handling is a declared structural workflow branch.'
                    : (str_contains($processingModel, 'overnight_batch') ? 'The histology batch is a declared structural workflow stage, not unexplained idle time.' : null),
            ],
            'benchmarkKey' => $cohort === 'consult_send_out' ? null : ($cohort === 'frozen_section' ? (($metadata['single_block'] ?? false) ? 'frozen_single_block' : null) : $cohort),
            'frozen' => [
                'applicable' => $row->frozen_status !== 'not_applicable',
                'status' => (string) $row->frozen_status,
                'startedAt' => $row->frozen_started_at === null ? null : CarbonImmutable::parse($row->frozen_started_at)->toAtomString(),
                'resultedAt' => $row->frozen_resulted_at === null ? null : CarbonImmutable::parse($row->frozen_resulted_at)->toAtomString(),
                'elapsedMinutes' => $row->frozen_started_at === null ? null : max(0, (int) floor(CarbonImmutable::parse($row->frozen_started_at)->diffInSeconds(CarbonImmutable::parse($row->frozen_resulted_at ?? now()), false) / 60)),
                'timerActive' => $frozenTimer !== null,
                'timer' => $frozenTimer,
            ],
            'sourceCutoffAt' => CarbonImmutable::parse($row->source_cutoff_at)->toAtomString(),
            'drillHref' => $row->valid_case_id === null
                ? '/lab/anatomic-path'
                : '/lab/anatomic-path?'.http_build_query(['caseId' => (int) $row->valid_case_id]),
        ];
    }

    /** @return array<string, mixed> */
    private function timer(object $row): array
    {
        return [
            'caseId' => (int) $row->valid_case_id,
            'apCaseUuid' => (string) $row->ap_case_uuid,
            'label' => (string) ($row->procedure_label ?: 'Frozen section'),
            'startedAt' => CarbonImmutable::parse($row->frozen_started_at)->toAtomString(),
            'elapsedMinutes' => $this->elapsed($row->frozen_started_at),
            'blocking' => true,
            'explanation' => 'Frozen-section interpretation is in progress for this active OR case. This timer is operational and does not replace pathology communication.',
            'sourceCutoffAt' => CarbonImmutable::parse($row->source_cutoff_at)->toAtomString(),
            'drillHref' => '/lab/anatomic-path?'.http_build_query(['caseId' => (int) $row->valid_case_id]),
        ];
    }

    /** @param Collection<int, object> $rows @return array<string, array<string, mixed>> */
    private function coverage(Collection $rows): array
    {
        $sourceIds = $rows->pluck('source_id')->map(fn (mixed $id): int => (int) $id)->unique();
        $apLisAvailable = $rows->isNotEmpty() && $rows->every(fn (object $row): bool => $row->system_class === 'ap_lis');
        $bulkSources = $rows->filter(fn (object $row): bool => (bool) $row->bulk_supported)->pluck('source_id')->unique();
        $watermarks = $bulkSources->isEmpty() ? collect() : DB::table('integration.connector_watermarks')
            ->whereIn('source_id', $sourceIds->all())->where('scope_type', 'bulk_backfill')->whereNotNull('last_success_at')->get()->keyBy('source_id');
        $backfillStatus = $bulkSources->isEmpty() ? 'not_configured' : ($bulkSources->every(fn (mixed $id): bool => $watermarks->has($id)) ? 'available' : 'missing');

        return [
            'apLis' => [
                'status' => $apLisAvailable ? 'available' : 'missing',
                'explanation' => $apLisAvailable
                    ? 'Every selected case is sourced from a governed AP-LIS feed.'
                    : 'At least one selected case lacks governed AP-LIS source classification; stages are shown only as available.',
            ],
            'backfill' => [
                'status' => $backfillStatus,
                'lastSuccessAt' => $watermarks->pluck('last_success_at')->filter()->min() === null
                    ? null
                    : CarbonImmutable::parse($watermarks->pluck('last_success_at')->filter()->min())->toAtomString(),
                'explanation' => match ($backfillStatus) {
                    'available' => 'Every selected bulk-capable AP source has a successful governed backfill watermark.',
                    'missing' => 'A bulk-capable AP source has no successful governed backfill watermark; historical completeness is uncertain.',
                    default => 'No selected AP source declares bulk backfill support; no historical-completeness claim is made.',
                },
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function benchmarkLines(): array
    {
        $evidence = 'Established CAP guidance summarized in ACUM-ENG-ANC-001 section 8.1; reference only, not universal or local policy.';

        return [
            ['key' => 'routine', 'label' => 'Routine AP final', 'percentile' => 90, 'thresholdValue' => 2, 'thresholdUnit' => 'days', 'evidenceLabel' => $evidence, 'applicability' => 'Routine AP cases; working-day interpretation must be governed locally before scoring.'],
            ['key' => 'complex', 'label' => 'Complex AP final', 'percentile' => 90, 'thresholdValue' => 3, 'thresholdUnit' => 'days', 'evidenceLabel' => $evidence, 'applicability' => 'Complex AP cases; working-day interpretation must be governed locally before scoring.'],
            ['key' => 'frozen_single_block', 'label' => 'Single-block frozen section', 'percentile' => 90, 'thresholdValue' => 20, 'thresholdUnit' => 'minutes', 'evidenceLabel' => $evidence, 'applicability' => 'Single-block frozen sections only; displayed as an established reference, not a clinical command.'],
        ];
    }

    private function cohort(object $row, array $metadata): string
    {
        $value = (string) ($metadata['work_cohort'] ?? '');
        if (in_array($value, self::COHORTS, true)) {
            return $value;
        }

        return $row->case_type === 'frozen_section' ? 'frozen_section' : 'routine';
    }

    private function ageBand(int $minutes): string
    {
        return match (true) {
            $minutes < 240 => 'under_4h',
            $minutes < 480 => '4_to_8h',
            $minutes < 1440 => '8_to_24h',
            $minutes < 2880 => '24_to_48h',
            default => '48_plus',
        };
    }

    private function elapsed(mixed $startedAt): int
    {
        return max(0, (int) floor(CarbonImmutable::parse($startedAt)->diffInSeconds(now(), false) / 60));
    }

    private static function stageLabel(string $stage): string
    {
        return match ($stage) {
            'specimen_out' => 'Specimen out',
            'slides_ready' => 'Slides ready',
            'signed_out' => 'Signed out',
            default => ucfirst($stage),
        };
    }

    private static function cohortLabel(string $cohort): string
    {
        return match ($cohort) {
            'consult_send_out' => 'Consult / send-out',
            'frozen_section' => 'Frozen section',
            default => ucfirst($cohort),
        };
    }

    /** @return array<string, mixed> */
    private function freshness(mixed $cutoff): array
    {
        $registered = DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->first();
        $value = $cutoff ?? $registered?->latest_observed_at;
        if ($value === null) {
            return $this->contracts->freshness(new FreshnessEnvelope(
                'unknown', new \DateTimeImmutable(now()->toAtomString()), null, null,
                (string) ($registered?->source_label ?? 'Anatomic Pathology operational feeds'),
                'No AP source cutoff is available.',
            ));
        }
        $at = CarbonImmutable::parse($value);
        $lag = max(0, (int) floor($at->diffInSeconds(now(), false) / 60));
        $registeredStatus = strtolower((string) ($registered?->status ?? 'current'));
        $stale = in_array($registeredStatus, ['stale', 'error', 'failed', 'unavailable'], true)
            || $lag > max(1, (int) ($registered?->warning_lag_minutes ?? 60));

        return $this->contracts->freshness(new FreshnessEnvelope(
            $stale ? 'stale' : 'fresh', new \DateTimeImmutable(now()->toAtomString()), new \DateTimeImmutable($at->toAtomString()), $lag,
            (string) ($registered?->source_label ?? 'Anatomic Pathology operational feeds'),
            $stale ? 'The selected AP assertions exceed the registered freshness tolerance.' : null,
        ));
    }

    /** @return array<string, mixed> */
    private function json(mixed $value): array
    {
        return is_array($value) ? $value : (json_decode((string) ($value ?? '{}'), true) ?: []);
    }
}
