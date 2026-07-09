<?php

namespace App\Services\PatientFlow;

use App\Services\Flow\FlowLensService;
use App\Services\Flow\ForwardProjectionService;
use Carbon\CarbonImmutable;

class PatientFlowOccupancyContextService
{
    public function __construct(
        private readonly FlowEventRepository $events,
        private readonly FacilitySpaceLocationResolver $locations,
        private readonly OccupancyInsightProjector $occupancyInsights,
        private readonly PatientFlowDemoBarrierScenario $demoBarriers,
        private readonly PatientFlowEddyContextBuilder $eddyContext,
        private readonly ForwardProjectionService $projections,
        private readonly FlowLensService $flowLens,
        private readonly PatientFlowScenarioRegistry $scenarios,
    ) {}

    /**
     * @param  array<string, mixed>  $lens
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(
        array $lens,
        string $roleId,
        CarbonImmutable $time,
        array $filters = [],
        bool $includeEddyContext = false,
    ): array {
        $filters = $this->normalizedFilters($filters, $time);
        $events = $this->events->serializeEvents($this->events->filteredEvents($filters));
        $locations = $this->locations->allNavigatorLocations($this->facilityCode());

        $scope = ['type' => 'house', 'floor' => null, 'unit_id' => null, 'patient_ref' => null];
        $rawProjections = $this->projections->projections($time, $time->addHours(24), $scope, $lens['projection_kinds']);
        $depth = ($lens['patient_dots'] ?? null) === 'full' ? 'full' : 'none';
        $projections = array_map(
            fn (array $item): array => $this->flowLens->redactRow($item, $depth, $scope),
            $rawProjections,
        );

        $demoScenario = null;
        $demoEnabled = $this->demoBarriersEnabled($filters);
        $staleReplay = $demoEnabled && $this->replayIsStale($events, $time);
        $demoReplacesReplay = $demoEnabled && (
            $this->demoBarriersReplaceReplay($filters)
            || $staleReplay
        );
        if ($demoReplacesReplay) {
            $events = [];
            $projections = [];
        }

        if ($demoEnabled) {
            $demo = $this->demoBarriers->build($locations, $time, $filters);
            $events = [...$events, ...$demo['events']];
            $projections = [...$projections, ...$demo['projections']];
            $demoScenario = $demo['scenario'] + [
                'replaces_replay' => $demoReplacesReplay,
                'replaces_stale_replay' => $staleReplay,
            ];
        }

        $payload = $this->occupancyInsights->project(
            events: $events,
            locations: $locations,
            projections: $projections,
            asOf: $time->toIso8601String(),
            lens: $lens,
        );

        $response = $payload + [
            'lens' => [
                'role_id' => $roleId,
                'patient_dots' => $lens['patient_dots'],
                'projection_kinds' => $lens['projection_kinds'],
            ],
            'projection_window' => [
                'from' => $time->toIso8601String(),
                'to' => $time->addHours(24)->toIso8601String(),
            ],
            'demo_scenario' => $demoScenario,
            'generated_at' => now()->toJSON(),
        ];

        if ($includeEddyContext) {
            $response['eddy_context'] = $this->eddyContext->build(
                $payload,
                $lens,
                $roleId,
                $time,
                $filters,
                $demoScenario,
                $this->contextScope($filters),
            );
        }

        return $response;
    }

    /** @param array<string, mixed> $filters */
    private function normalizedFilters(array $filters, CarbonImmutable $time): array
    {
        $filters['limit'] = $filters['limit'] ?? 20000;
        unset($filters['from']);
        $filters['to'] = $time->toIso8601String();

        return $filters;
    }

    private function facilityCode(): string
    {
        return (string) config('facility_models.zep_500.facility_code', 'ZEPHYRUS-500');
    }

    /** @param array<string, mixed> $filters */
    private function demoBarriersEnabled(array $filters): bool
    {
        $value = $filters['demo'] ?? null;
        if ($value !== null) {
            $normalized = strtolower((string) $value);

            return $this->scenarios->isDemoRequestValue($normalized);
        }

        return (bool) config('patient_flow.demo_barriers_enabled', false);
    }

    /** @param array<string, mixed> $filters */
    private function demoBarriersReplaceReplay(array $filters): bool
    {
        $value = $filters['demo'] ?? null;
        if ($value !== null) {
            $normalized = strtolower((string) $value);

            return $this->scenarios->isDemoRequestValue($normalized);
        }

        return (bool) config('patient_flow.demo_barriers_replace_replay', false);
    }

    /** @param list<array<string, mixed>> $events */
    private function replayIsStale(array $events, CarbonImmutable $time): bool
    {
        $latest = null;
        foreach ($events as $event) {
            if (empty($event['occurred_at'])) {
                continue;
            }

            $occurred = CarbonImmutable::parse((string) $event['occurred_at']);
            if (! $latest || $occurred->greaterThan($latest)) {
                $latest = $occurred;
            }
        }

        return ! $latest || $latest->lessThan($time->subHours(48));
    }

    /** @param array<string, mixed> $filters */
    private function contextScope(array $filters): array
    {
        $scope = [
            'type' => 'house',
            'label' => 'House',
            'filters' => $this->contextFilters($filters),
        ];

        if (isset($filters['floor']) && $filters['floor'] !== '' && $filters['floor'] !== 'all') {
            $floor = (int) $filters['floor'];
            $scope['type'] = 'floor';
            $scope['floor'] = $floor;
            $scope['label'] = "Floor {$floor}";
        }

        return $scope;
    }

    /** @param array<string, mixed> $filters */
    private function contextFilters(array $filters): array
    {
        return [
            'floor' => $filters['floor'] ?? null,
            'service_line' => $filters['service_line'] ?? null,
            'category' => $filters['category'] ?? null,
        ];
    }
}
