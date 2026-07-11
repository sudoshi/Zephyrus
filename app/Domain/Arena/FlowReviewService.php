<?php

namespace App\Domain\Arena;

use App\Domain\Ocel\OcelJsonExporter;
use App\Models\Barrier;
use App\Models\Ops\Approval;
use App\Models\Ops\Intervention;
use App\Models\Ops\InterventionMetric;
use App\Models\Ops\OperationalAction;
use App\Models\Ops\Recommendation;
use App\Services\Ops\CorrectiveActionExecutor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Zephyrus 2.0 — Part X / Flow Reconciliation. The orchestrator for the 48-Hour
 * Flow Review — the backend loop the FE already reads through
 * arenaReviewResponseSchema (Components/arena/review/*).
 *
 * It mirrors ArenaService's /snapshot discipline but collapses FOUR reads into
 * ONE persisted artifact: a single OCPM mining pass (discover + performance +
 * conformance) unioned with the open prod.barriers, ranked into the unified
 * flow/care/human taxonomy by FlowReviewComposer and stashed in arena.reviews,
 * keyed by (window ref, source signature).
 *
 *   run() — (re)build the artifact. Invoked by POST /review/run and the scheduled
 *           arena:review:run command (the "trigger = both" decision). Needs the
 *           sidecar; performance/conformance may individually degrade to empty.
 *   get() — a pure, read-only cache lookup for GET /review. Never calls the
 *           sidecar; if no review has been built yet it reports unavailable so
 *           the movement invites a Run, exactly like every other Arena surface.
 *
 * NOTE (v1): the discovered map + performance run over the full OCEL projection,
 * not a window-clipped log (the exporter is un-windowed). The window governs
 * which prod.barriers are open, the labels, and the this-vs-last diff; a windowed
 * OCEL export is a follow-up. This matches how ArenaService::performance already
 * reads the whole log.
 */
class FlowReviewService
{
    /** Trailing width of the review window. */
    private const WINDOW_HOURS = 48;

    public function __construct(
        private readonly ArenaSidecarClient $client,
        private readonly OcelJsonExporter $exporter,
    ) {}

    /**
     * (Re)build and persist the Flow Review artifact for the window ending at
     * $windowRef (default: now).
     *
     * @return array<string, mixed>
     */
    public function run(?string $windowRef = null): array
    {
        $to = $this->parseWindowRef($windowRef);
        $from = $to->copy()->subHours(self::WINDOW_HOURS);
        $signature = $this->sourceSignature();
        $cacheKey = sha1($to->toIso8601String().'|'.$signature);

        $doc = $this->exporter->export();

        // The map is essential — without discovery there is no review. Refuse
        // rather than persist a hollow artifact; the last-good one stays readable.
        $discover = $this->client->discover($doc);
        if ($discover === null) {
            return ['available' => false, 'reason' => 'sidecar_unavailable'];
        }

        // Performance + conformance may each be down independently; the composer
        // simply surfaces fewer barrier kinds when a signal is missing.
        $performance = $this->client->performance($doc);
        $conformance = $this->client->conformance($doc);

        $prior = $this->latestPriorPayload($cacheKey);

        $artifact = FlowReviewComposer::compose(
            $discover,
            $performance,
            $conformance,
            $this->openHumanBarriers($to),
            $prior,
            $this->pendingCorrectiveActions(),
            $this->correctiveActionsByBarrier(),
            $from,
            $to,
        );

        $now = now();
        DB::table('arena.reviews')->upsert([[
            'cache_key' => $cacheKey,
            'window_ref' => $to->toIso8601String(),
            'window_from' => $from,
            'window_to' => $to,
            'source_signature' => $signature,
            'payload' => json_encode($artifact),
            'barrier_count' => count($artifact['barriers'] ?? []),
            'generated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['cache_key'], ['window_ref', 'window_from', 'window_to', 'source_signature', 'payload', 'barrier_count', 'generated_at', 'updated_at']);

        return ['cached' => false, 'stale' => false] + $artifact;
    }

    /**
     * Read the persisted artifact for a window (default: the latest). Read-only —
     * the sidecar is never touched here.
     *
     * @return array<string, mixed>
     */
    public function get(?string $windowRef = null): array
    {
        $query = DB::table('arena.reviews')->orderByDesc('generated_at');
        if ($windowRef !== null && $windowRef !== '' && $windowRef !== 'latest') {
            $query->where('window_ref', $windowRef);
        }
        $row = $query->first();

        if ($row === null) {
            return ['available' => false, 'reason' => 'no_review'];
        }

        $ttl = (int) config('services.arena.cache_ttl', 900);
        $stale = Carbon::parse($row->generated_at)->lt(now()->subSeconds($ttl));
        $artifact = json_decode($row->payload, true) ?: ['available' => false, 'reason' => 'corrupt_artifact'];

        if (($artifact['available'] ?? false) !== true) {
            return $artifact;
        }

        return ['cached' => true, 'stale' => $stale] + $artifact;
    }

    /**
     * The open barriers relevant to the window, normalised for the composer. A
     * barrier is in scope if it was opened on or before the window end and is
     * still open.
     *
     * @return array<int, array<string, mixed>>
     */
    private function openHumanBarriers(\Illuminate\Support\Carbon $to): array
    {
        return Barrier::query()
            ->open()
            ->with('unit')
            ->where('opened_at', '<=', $to)
            ->orderBy('opened_at')
            ->get()
            ->map(fn (Barrier $b) => [
                'id' => 'human-'.$b->barrier_id,
                'category' => (string) ($b->category ?? ''),
                'unit_id' => $b->unit_id,
                'unit_label' => $b->unit?->name,
                'owner' => $b->owner,
                'description' => $b->description,
                'opened_at' => optional($b->opened_at)->toIso8601String() ?? $to->toIso8601String(),
            ])
            ->all();
    }

    /**
     * How many governed corrective-action drafts are awaiting a human decision —
     * the "actions pending" the Review surfaces. Counts pending approvals whose
     * action materializes a domain row on approval (the copilot's two draft types),
     * so it tracks the P3 executor's inbox, not every Eddy proposal. Global (not
     * window-clipped): the approval queue has no bearing on the review window.
     */
    private function pendingCorrectiveActions(): int
    {
        return Approval::query()
            ->where('status', 'pending')
            ->whereHas('action', fn ($q) => $q->whereIn('action_type', CorrectiveActionExecutor::MATERIALIZES))
            ->count();
    }

    /**
     * Fold each review barrier's governed corrective action back onto it (P4/P5),
     * keyed by the review-barrier id the draft was raised against (target_ref).
     * Two facets per barrier: the PENDING draft awaiting approval (P4) and the
     * PRIOR OUTCOME of the last completed action's measured intervention (P5).
     * Newest recommendation wins for the pending draft; the newest completed one
     * supplies the outcome.
     *
     * @return array<string, array<string, mixed>>
     */
    private function correctiveActionsByBarrier(): array
    {
        $recs = Recommendation::query()
            ->whereNotNull('target_ref')
            ->whereIn('recommendation_type', ['eddy_pdsa_cycle', 'eddy_pathway_correction'])
            ->with(['actions.approvals'])
            ->orderByDesc('recommendation_id')
            ->get();

        $out = [];
        foreach ($recs as $rec) {
            $ref = (string) $rec->target_ref;
            $entry = $out[$ref] ?? [];
            $action = $rec->actions->sortByDesc('action_id')->first();
            if ($action === null) {
                continue;
            }

            if (! isset($entry['draft'])) {
                $pending = $action->approvals->firstWhere('status', 'pending');
                if ($pending !== null && $action->status === 'draft') {
                    $entry['draft'] = [
                        'action_uuid' => (string) $action->action_uuid,
                        'action_type' => (string) $action->action_type,
                        'tier' => $rec->evidence['tier'] ?? null,
                        'risk' => (string) $rec->risk_level,
                        'title' => (string) $rec->title,
                        'status' => 'pending',
                        'approved' => false,
                        'approval_uuid' => (string) $pending->approval_uuid,
                        'approval_id' => (int) $pending->approval_id,
                    ];
                }
            }

            if (! array_key_exists('prior_outcome', $entry) && $action->status === 'completed') {
                $entry['prior_outcome'] = $this->priorOutcomeForAction($action);
            }

            if ($entry !== []) {
                $out[$ref] = $entry;
            }
        }

        // The schema requires prior_outcome to be present (nullable) whenever a
        // corrective_action exists — default it to null for draft-only barriers.
        foreach ($out as &$entry) {
            if (! array_key_exists('prior_outcome', $entry)) {
                $entry['prior_outcome'] = null;
            }
        }
        unset($entry);

        return $out;
    }

    /**
     * The re-measured outcome of a completed corrective action (P5): the primary,
     * time-based metric of the intervention it produced, expressed as seconds
     * moved (negative = improved). Null when there is no intervention, no primary
     * metric, or the metric is not a duration.
     *
     * @return array{label: string, moved_sec: int}|null
     */
    private function priorOutcomeForAction(OperationalAction $action): ?array
    {
        $pdsaId = data_get($action->payload, 'pdsa_cycle_id');
        $intervention = Intervention::query()
            ->where(function ($query) use ($action, $pdsaId) {
                $query->where('action_id', $action->action_id);
                if ($pdsaId) {
                    $query->orWhere('pdsa_cycle_id', $pdsaId);
                }
            })
            ->latest('intervention_id')
            ->first();
        if ($intervention === null) {
            return null;
        }

        $metric = InterventionMetric::query()
            ->where('intervention_id', $intervention->intervention_id)
            ->where('is_primary', true)
            ->orderBy('intervention_metric_id') // deterministic if ever >1 primary
            ->first();
        if ($metric === null || $metric->delta_value === null) {
            return null;
        }

        $seconds = $this->metricToSeconds((float) $metric->delta_value, (string) $metric->unit);
        if ($seconds === null) {
            return null;
        }

        return [
            'label' => (string) ($metric->label ?: 'last action moved'),
            'moved_sec' => (int) round($seconds),
        ];
    }

    /** Convert a metric delta to seconds, or null when the unit is not a duration. */
    private function metricToSeconds(float $value, string $unit): ?float
    {
        return match (strtolower(trim($unit))) {
            'seconds', 'second', 'sec', 's' => $value,
            'minutes', 'minute', 'min', 'm' => $value * 60,
            'hours', 'hour', 'hr', 'h' => $value * 3600,
            'days', 'day', 'd' => $value * 86400,
            default => null,
        };
    }

    /**
     * The most recent artifact that is NOT the one we're about to write, so a
     * rebuild of the same window diffs against the previous window, not itself.
     *
     * @return array<string, mixed>|null
     */
    private function latestPriorPayload(string $excludeCacheKey): ?array
    {
        $row = DB::table('arena.reviews')
            ->where('cache_key', '!=', $excludeCacheKey)
            ->orderByDesc('generated_at')
            ->first();

        return $row !== null ? json_decode($row->payload, true) : null;
    }

    /** Default window end is now; an explicit ISO ref rebuilds a past window. */
    private function parseWindowRef(?string $windowRef): Carbon
    {
        if ($windowRef !== null && $windowRef !== '' && $windowRef !== 'latest') {
            try {
                return Carbon::parse($windowRef);
            } catch (\Throwable) {
                // Fall through to now() on an unparseable ref.
            }
        }

        return Carbon::now();
    }

    /** A cheap fingerprint of the OCEL log; changes when the projection changes. */
    private function sourceSignature(): string
    {
        $row = DB::table('ocel.events')->selectRaw('count(*) as c, max(event_time) as m')->first();

        return sha1((string) ((int) ($row->c ?? 0)).'|'.(string) ($row->m ?? ''));
    }
}
