<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Models\PdsaCycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * PI / Quality Lead (P8) — GET /api/mobile/v1/improvement/pdsa + /improvement/opportunities.
 * Read-only (the PI layer has no service today); queries the models/tables directly.
 */
class ImprovementController extends Controller
{
    use RendersMobileEnvelope;

    public function pdsa(): JsonResponse
    {
        $cycles = PdsaCycle::where('is_deleted', false)
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'planned' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END")
            ->orderByDesc('started_at')
            ->with('unit')
            ->get()
            ->map(fn (PdsaCycle $c) => [
                'id' => $c->pdsa_cycle_id,
                'title' => $c->title,
                'status' => $c->status,
                'owner' => $c->owner,
                'objective' => $c->objective,
                'unit' => $c->unit?->name,
                'started_at' => optional($c->started_at)->toIso8601String(),
                'target_date' => optional($c->target_date)->toDateString(),
            ])
            ->values();

        return $this->envelope($cycles, meta: ['count' => $cycles->count()], links: ['web' => url('/improvement/pdsa')]);
    }

    public function opportunities(): JsonResponse
    {
        $opps = DB::table('prod.improvement_opportunities')
            ->where('is_deleted', false)
            ->orderByRaw("CASE priority WHEN 'High' THEN 0 WHEN 'Medium' THEN 1 ELSE 2 END")
            ->orderByDesc('estimated_impact')
            ->get(['opportunity_id', 'title', 'description', 'department', 'priority', 'status', 'estimated_impact'])
            ->map(fn ($o) => [
                'id' => $o->opportunity_id,
                'title' => $o->title,
                'description' => $o->description,
                'department' => $o->department,
                'priority' => $o->priority,
                'status' => $o->status,
                'impact' => $o->estimated_impact,
            ])
            ->values();

        return $this->envelope($opps, meta: ['count' => $opps->count()], links: ['web' => url('/improvement/opportunities')]);
    }
}
