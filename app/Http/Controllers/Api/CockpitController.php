<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ops\MetricDefinition;
use App\Services\Cockpit\DrillBuilder;
use App\Services\Cockpit\ScopedFaceBuilder;
use App\Services\Cockpit\SnapshotBuilder;
use App\Support\Cockpit\CockpitScopeResolver;
use App\Support\Hospital\HospitalManifest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Zephyrus 2.0 P1 cockpit serving API. /snapshot is a cached read of the
 * single replaced cockpit_snapshots row (ETag/304-aware) — never a query
 * storm; the per-minute RefreshCockpitSnapshot job keeps it warm. The
 * kpi-definitions endpoints back the P8 admin threshold editor.
 */
class CockpitController extends Controller
{
    public function __construct(
        private readonly SnapshotBuilder $builder,
        private readonly HospitalManifest $manifest,
    ) {}

    public function snapshot(Request $request): JsonResponse|Response
    {
        $facilityKey = $this->manifest->facilityCode();

        // current() resolves cache → fresh row → inline refresh, covering both
        // the cold start (fresh deploy) and a dead scheduler (P2: staleness is
        // bounded at SERVE_MAX_AGE_SECONDS even with no cron installed).
        $payload = $this->builder->current($facilityKey);

        // asOf is constant per built payload, so it is the correct 304 pivot —
        // the ETag changes exactly when the numbers can have changed.
        $etag = '"'.sha1($facilityKey.'|'.(string) ($payload['asOf'] ?? '')).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304)->withHeaders(['ETag' => $etag]);
        }

        return response()->json($payload)
            ->withHeaders(['ETag' => $etag, 'Cache-Control' => 'private, no-cache']);
    }

    /**
     * Per-domain drill payload (spec §3.3, §6.4 Cell grammar). 404 for an
     * unknown domain or one hidden by COCKPIT_HIDE_DEMO_DOMAINS (D5).
     */
    public function drill(string $domain, DrillBuilder $drills): JsonResponse
    {
        $payload = $drills->build($domain);

        if ($payload === null) {
            return response()->json(['message' => 'Unknown cockpit domain'], 404);
        }

        return response()->json($payload)
            ->withHeaders(['Cache-Control' => 'private, no-cache']);
    }

    /**
     * The active mount scope + the catalog of scopes this user may mount (P8 WS-1).
     * `?scope=` names the mount ('unit:MICU' | 'service_line:critical_care' |
     * 'department:ed' | 'house'); absent/unknown resolves to the user's primary unit
     * assignment, else house. Backs the mount picker and the scope-aware faces (WS-2).
     */
    public function scopes(Request $request, CockpitScopeResolver $resolver): JsonResponse
    {
        $user = $request->user();
        $scope = $request->query('scope');
        $active = $resolver->resolve(is_string($scope) ? $scope : null, $user);

        return response()->json([
            'active' => $active->toArray(),
            'catalog' => $resolver->catalog($user),
        ])->withHeaders(['Cache-Control' => 'private, no-cache']);
    }

    /**
     * The altitude-appropriate face for a mount (P8 WS-2). `?scope=` resolves as
     * in scopes(); the payload is the same {title, kpis[], tables[]} drill grammar
     * so React renders every altitude with the existing primitives. house →
     * 'render' => 'grid' (the frontend keeps the DomainGrid); department reuses the
     * domain drill; unit / service_line render live-census faces.
     */
    public function face(Request $request, CockpitScopeResolver $resolver, ScopedFaceBuilder $faces): JsonResponse
    {
        $scope = $request->query('scope');
        $resolved = $resolver->resolve(is_string($scope) ? $scope : null, $request->user());

        return response()->json($faces->build($resolved))
            ->withHeaders(['Cache-Control' => 'private, no-cache']);
    }

    public function kpiDefinitions(): JsonResponse
    {
        $definitions = MetricDefinition::query()
            ->orderBy('metric_key')
            ->get()
            ->map(fn (MetricDefinition $def): array => [
                'key' => $def->metric_key,
                'label' => $def->label,
                'domain' => $def->domain,
                'unit' => $def->unit,
                'direction' => $def->direction,
                'target' => $def->target_value !== null ? (float) $def->target_value : null,
                'edges' => $def->edges(),
                'refreshSecs' => $def->refresh_secs,
                'alertTemplate' => $def->alert_template,
                'facilityKey' => $def->facility_key,
                'isActive' => (bool) ($def->is_active ?? true),
            ]);

        return response()->json(['definitions' => $definitions]);
    }

    /**
     * Audited band-edge edit (admin-gated in routes). Additive fields only —
     * identity/direction/domain are immutable here; clinicians tune bands,
     * they do not redefine metrics.
     */
    public function updateKpiDefinition(Request $request, string $metricKey): JsonResponse
    {
        $validated = $request->validate([
            'ok_edge' => ['nullable', 'numeric'],
            'warn_edge' => ['nullable', 'numeric'],
            'crit_edge' => ['nullable', 'numeric'],
            'refresh_secs' => ['sometimes', 'integer', 'min:30', 'max:86400'],
            'alert_template' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $definition = MetricDefinition::query()->where('metric_key', $metricKey)->firstOrFail();

        $before = $definition->only(array_keys($validated));
        $definition->fill($validated)->save();

        Log::info('cockpit.kpi_definition.updated', [
            'metric_key' => $metricKey,
            'actor_id' => $request->user()?->getKey(),
            'before' => $before,
            'after' => $definition->only(array_keys($validated)),
        ]);

        return response()->json(['key' => $metricKey, 'edges' => $definition->edges()]);
    }
}
