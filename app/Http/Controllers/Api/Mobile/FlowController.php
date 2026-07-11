<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Models\Bed;
use App\Models\CensusSnapshot;
use App\Services\Flow\DutyProjectionService;
use App\Services\Flow\FloorPlateAssetService;
use App\Services\Flow\FloorRollupService;
use App\Services\Flow\FlowLensService;
use App\Services\Flow\ForwardProjectionService;
use App\Services\Flow\OperationalTimelineService;
use App\Services\Flow\Spaces3dAssetService;
use App\Services\Mobile\MobilePersonaCatalog;
use App\Services\PatientFlow\PatientFlowOccupancyHistoryService;
use App\Services\PatientFlow\PatientFlowScenarioRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The 48-hour Flow Window — FLOW-WINDOW-PLAN §6.4 (W4, D1).
 *
 *   GET /api/mobile/v1/flow/floors   (mobile:read) — versioned 2D plate asset,
 *       strong ETag; geometry only, no live state, aggressively cacheable.
 *   GET /api/mobile/v1/flow/window   (mobile:read) — snapshots + events +
 *       projections for one persona-lensed scope, clipped to ≤48h centered
 *       on now. `?since=` (Phase 5 delta refresh) narrows events/snapshots
 *       to t > since; projections/spaces/bed_statuses always come in full.
 *
 * Everything is clamped server-side by FlowLensService (config/hummingbird/
 * flow_lens.php). Unauthorized scopes are an explicit 403 state, and
 * patient identity appears only as ptok_ context refs — and only at the
 * depth the role's A2P matrix already grants. Payloads for patient_dots:
 * 'none' roles contain no patient entities at all.
 */
class FlowController extends Controller
{
    use RendersMobileEnvelope;

    private const MAX_WINDOW_HOURS = 48;

    /** @var list<array<string, mixed>>|null one FloorRollupService pass per request */
    private ?array $floorRollup = null;

    public function __construct(
        private readonly MobilePersonaCatalog $personas,
        private readonly FlowLensService $lens,
        private readonly FloorPlateAssetService $plates,
        private readonly FloorRollupService $floors,
        private readonly OperationalTimelineService $timeline,
        private readonly ForwardProjectionService $projections,
        private readonly DutyProjectionService $dutyProjections,
        private readonly Spaces3dAssetService $spaces3dAsset,
        private readonly PatientFlowOccupancyHistoryService $occupancyHistory,
        private readonly PatientFlowScenarioRegistry $scenarios,
    ) {}

    public function floors(Request $request): JsonResponse
    {
        $document = $this->plates->load();
        $etag = '"'.$document['version'].'"';

        if (trim((string) $request->headers->get('If-None-Match')) === $etag) {
            return response()->json(null, 304)->withHeaders(['ETag' => $etag]);
        }

        return $this->envelope(
            $document,
            meta: ['version' => $document['version']],
            links: ['web' => url('/rtdc/patient-flow-navigator')],
        )->withHeaders([
            'ETag' => $etag,
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * The 3D space-anchor asset (centroids + unit/bed bridges) the native
     * SceneKit/Filament viewers use to place tokens + duty markers over the
     * GLB shell. Geometry only, no live state — ETagged + aggressively cached.
     */
    public function spaces3d(Request $request): JsonResponse
    {
        $document = $this->spaces3dAsset->load();
        $etag = '"'.$document['version'].'"';

        if (trim((string) $request->headers->get('If-None-Match')) === $etag) {
            return response()->json(null, 304)->withHeaders(['ETag' => $etag]);
        }

        return $this->envelope(
            $document,
            meta: ['version' => $document['version']],
            links: ['web' => url('/rtdc/patient-flow-navigator')],
        )->withHeaders([
            'ETag' => $etag,
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function demoScenarios(): JsonResponse
    {
        return $this->envelope(
            $this->scenarios->all(),
            meta: [
                'enabled_keys' => $this->scenarios->enabledKeys(),
                'source_mode' => 'synthetic_demo',
            ],
            links: ['web' => url('/rtdc/patient-flow-navigator')],
        );
    }

    public function occupancyHistory(Request $request): JsonResponse
    {
        try {
            $roleId = $this->personas->fromRequest($request);
            $lens = $this->lens->lensFor($roleId);
        } catch (AuthorizationException $exception) {
            return $this->forbidden($exception->getMessage());
        }

        try {
            return $this->envelope(
                $this->occupancyHistory->history($lens, $roleId, $this->historyFilters($request), $request->user()),
                links: ['web' => url('/rtdc/patient-flow-navigator')],
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_occupancy_history_window',
                    'message' => $exception->getMessage(),
                ],
            ], 422);
        }
    }

    public function window(Request $request): JsonResponse
    {
        try {
            $roleId = $this->personas->fromRequest($request);
            $lens = $this->lens->lensFor($roleId);
            $scope = $this->lens->resolveScope($lens, $request->query('scope'), $request->user());
        } catch (AuthorizationException $exception) {
            return $this->forbidden($exception->getMessage());
        }

        $now = CarbonImmutable::now();
        [$from, $to] = $this->clampWindow($request, $now);

        // Delta refresh (Phase 5): `since` filters the append-only halves
        // (events, snapshots) to t > since; projections/spaces/bed_statuses
        // are recomputed and served in full on every request. Unlike from/to
        // (which clamp silently), an unparseable or out-of-range `since` is a
        // hard 422 — a delta against the wrong window must never look like a
        // quiet full refresh.
        $since = null;
        if (($sinceRaw = $request->query('since')) !== null && trim((string) $sinceRaw) !== '') {
            $since = $this->parseTime((string) $sinceRaw);
            if ($since === null || $since->lt($from) || $since->gte($to)) {
                return $this->invalidSince($from, $to);
            }
        }

        $layers = $this->lens->clampLayers($lens, $request->query('layers'));
        $depth = $this->lens->effectivePatientDepth($lens, $scope, $request->user());
        $taskRefs = $depth === 'task' ? $this->lens->taskPatientRefs($roleId) : [];
        $visibleUnitIds = $depth === 'unit' ? $this->lens->visibleUnitIds($request->user()) : [];

        $payload = [
            'window' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'now' => $now->toIso8601String(),
                'since' => $since?->toIso8601String(),
            ],
            'lens' => [
                'role_id' => $roleId,
                'scope_default' => $lens['scope_default'],
                'scopes_allowed' => $lens['scopes_allowed'],
                'layers' => $lens['layers'],
                'event_kinds' => $lens['event_kinds'],
                'projection_kinds' => $lens['projection_kinds'],
                'patient_dots' => $depth,
                'actions' => $lens['actions'],
                'default_zoom_hours' => $lens['default_zoom_hours'],
            ],
            'scope' => [
                'type' => $scope['type'],
                'floor' => $scope['floor'],
                'unit_id' => $scope['unit_id'],
                'patient_context_ref' => $scope['patient_context_ref'],
                'label' => $scope['label'],
            ],
        ];

        if (in_array('spaces', $layers, true)) {
            $payload['spaces'] = [
                'plates_version' => $this->plates->load()['version'],
                'floors' => $this->floorRollup(),
            ];
        }

        if (in_array('snapshots', $layers, true)) {
            $payload['snapshots'] = $this->snapshots($from, $to, $scope, $since);
        }

        if (in_array('events', $layers, true)) {
            $events = $this->timeline->events($from, $to->min($now), $scope, $lens['event_kinds']);
            if ($since !== null) {
                $events = array_values(array_filter(
                    $events,
                    fn (array $event): bool => CarbonImmutable::parse($event['t'])->gt($since),
                ));
            }
            $payload['events'] = array_map(
                fn (array $event): array => $this->lens->redactRow($event, $depth, $scope, $taskRefs, $visibleUnitIds),
                $events,
            );
        }

        if (in_array('projections', $layers, true)) {
            $payload['projections'] = array_map(
                fn (array $item): array => $this->lens->redactRow($item, $depth, $scope, $taskRefs, $visibleUnitIds),
                $this->projections->projections($from->max($now), $to, $scope, $lens['projection_kinds']),
            );
        }

        // Duties — the persona's actionable worklist, spatially anchored and
        // due-dated (NATIVE-4D-VIEWER-PLAN §4 W1). Like projections: clamped to
        // the lens `duty_kinds`, redacted per patient_dots, and served IN FULL
        // even on a `?since=` delta (it is current worklist, not append-only).
        if (in_array('duties', $layers, true)) {
            $payload['duties'] = array_map(
                fn (array $item): array => $this->lens->redactRow($item, $depth, $scope, $taskRefs, $visibleUnitIds),
                $this->dutyProjections->duties($now, $lens['duty_kinds'] ?? [], $this->scopeUnitIds($scope)),
            );
        }

        // Bed-level "now" for the turn map: only floor/unit scopes, and only
        // for lenses whose event_kinds already include bed_status — the same
        // vocabulary gate, so transport/executive/etc. never receive the key.
        if (in_array($scope['type'], ['floor', 'unit'], true)
            && in_array('bed_status', $lens['event_kinds'], true)) {
            $payload['bed_statuses'] = $this->bedStatuses($scope);
        }

        return $this->envelope(
            $payload,
            meta: [
                'count' => count($payload['events'] ?? []) + count($payload['projections'] ?? []),
            ],
            links: ['web' => url('/rtdc/patient-flow-navigator')
                .'?persona='.$roleId
                .'&scope='.urlencode($this->lens->scopeString($scope))
                .'&t='.urlencode($now->toIso8601String()), ],
        );
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function clampWindow(Request $request, CarbonImmutable $now): array
    {
        $earliest = $now->subHours(self::MAX_WINDOW_HOURS / 2);
        $latest = $now->addHours(self::MAX_WINDOW_HOURS / 2);

        $from = $this->parseTime($request->query('from')) ?? $earliest;
        $to = $this->parseTime($request->query('to')) ?? $latest;

        $from = $from->max($earliest)->min($latest);
        $to = $to->max($earliest)->min($latest);
        if ($to->lte($from)) {
            [$from, $to] = [$earliest, $latest];
        }

        return [$from, $to];
    }

    private function parseTime(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function historyFilters(Request $request): array
    {
        return [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'asOf' => $request->query('asOf'),
            'service_line' => $request->query('service_line'),
            'floor' => $request->query('floor'),
            'demo' => $request->query('demo'),
            'scenario' => $request->query('scenario'),
            'limit' => $request->query('limit', 120),
        ];
    }

    /** @return list<array<string, mixed>> census checkpoints in scope (t > since on a delta) */
    private function snapshots(CarbonImmutable $from, CarbonImmutable $to, array $scope, ?CarbonImmutable $since = null): array
    {
        $query = CensusSnapshot::query()
            ->whereBetween('captured_at', [$from, $to])
            ->when($since, fn ($query) => $query->where('captured_at', '>', $since))
            ->orderBy('captured_at');

        if ($scope['type'] === 'unit' || $scope['type'] === 'patient') {
            if ($scope['unit_id'] !== null) {
                $query->where('unit_id', $scope['unit_id']);
            }
        } elseif ($scope['type'] === 'floor') {
            $unitIds = collect($this->floorRollup())
                ->firstWhere('floor', $scope['floor'])['units'] ?? [];
            $query->whereIn('unit_id', array_values(array_filter(array_column($unitIds, 'unit_id'))));
        } else {
            // House scope: only the active roster — retired/soft-deleted
            // units may still own historical checkpoint rows.
            $query->whereIn('unit_id', collect($this->floorRollup())
                ->flatMap(fn (array $floor): array => array_column($floor['units'], 'unit_id'))
                ->filter()
                ->all());
        }

        return $query->limit(5000)->get()
            ->map(fn (CensusSnapshot $snapshot): array => [
                't' => $snapshot->captured_at->toIso8601String(),
                'unit_id' => (int) $snapshot->unit_id,
                'staffed' => $snapshot->staffed_beds,
                'occupied' => $snapshot->occupied,
                'available' => $snapshot->available,
                'blocked' => $snapshot->blocked,
            ])
            ->all();
    }

    /**
     * Current bed state for the scoped floor/unit — strictly "now", never
     * historical or projected (the review/prediction halves stay in events/
     * projections). Floor membership comes from the same manifest-driven
     * rollup the snapshots/spaces layers use.
     *
     * @param  array{type: string, floor: ?int, unit_id: ?int}  $scope
     * @return list<array{bed_id: int, unit_id: int, label: ?string, status: string}>
     */
    private function bedStatuses(array $scope): array
    {
        $unitIds = $scope['type'] === 'unit'
            ? [(int) $scope['unit_id']]
            : array_values(array_filter(array_column(
                collect($this->floorRollup())->firstWhere('floor', $scope['floor'])['units'] ?? [],
                'unit_id',
            )));

        if ($unitIds === []) {
            return [];
        }

        return Bed::query()
            ->where('is_deleted', false)
            ->whereIn('unit_id', $unitIds)
            ->orderBy('unit_id')
            ->orderBy('label')
            ->limit(5000)
            ->get(['bed_id', 'unit_id', 'label', 'status'])
            ->map(fn (Bed $bed): array => [
                'bed_id' => (int) $bed->bed_id,
                'unit_id' => (int) $bed->unit_id,
                'label' => $bed->label,
                'status' => (string) $bed->status,
            ])
            ->all();
    }

    /** @return list<int>|null  null = house/patient scope (no spatial duty filter) */
    private function scopeUnitIds(array $scope): ?array
    {
        return match ($scope['type']) {
            'unit' => $scope['unit_id'] !== null ? [(int) $scope['unit_id']] : [],
            'floor' => array_values(array_filter(array_column(
                collect($this->floorRollup())->firstWhere('floor', $scope['floor'])['units'] ?? [],
                'unit_id',
            ))),
            default => null,
        };
    }

    /** @return list<array<string, mixed>> */
    private function floorRollup(): array
    {
        return $this->floorRollup ??= $this->floors->floors();
    }

    private function invalidSince(CarbonImmutable $from, CarbonImmutable $to): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'invalid_since',
                'message' => sprintf(
                    'since must be an ISO8601 timestamp within [%s, %s).',
                    $from->toIso8601String(),
                    $to->toIso8601String(),
                ),
            ],
        ], 422);
    }

    private function forbidden(string $reason): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'flow_lens_forbidden',
                'message' => $reason,
                'unauthorized_state' => true,
            ],
        ], 403);
    }
}
