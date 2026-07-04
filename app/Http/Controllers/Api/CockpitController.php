<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cockpit\CockpitSnapshot;
use App\Models\Ops\MetricDefinition;
use App\Services\Cockpit\SnapshotBuilder;
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

        $snapshot = CockpitSnapshot::query()->find($facilityKey);

        // Cold start (fresh deploy, scheduler not yet fired): compute once
        // synchronously so the first client never sees an empty cockpit.
        if ($snapshot === null) {
            $this->builder->refresh($facilityKey);
            $snapshot = CockpitSnapshot::query()->find($facilityKey);
        }

        if ($snapshot === null) {
            return response()->json(['message' => 'Snapshot unavailable'], 503);
        }

        $etag = '"'.sha1($facilityKey.'|'.$snapshot->generated_at->toIso8601String()).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304)->withHeaders(['ETag' => $etag]);
        }

        return response()->json($snapshot->payload)
            ->withHeaders(['ETag' => $etag, 'Cache-Control' => 'private, no-cache']);
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
