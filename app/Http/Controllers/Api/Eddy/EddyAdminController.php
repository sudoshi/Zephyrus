<?php

namespace App\Http\Controllers\Api\Eddy;

use App\Http\Controllers\Controller;
use App\Models\Eddy\EddyCloudUsage;
use App\Models\Eddy\EddyKnowledge;
use App\Services\Eddy\EddyProviderPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super-admin surfaces for Eddy (Phase 6):
 *  - cloud cost + redaction accounting from eddy_cloud_usage,
 *  - the route simulator (which provider a surface would call, and why),
 *  - the auto-curated-knowledge review queue (proposed → approved|retired).
 *
 * Web-session auth + an explicit super-admin gate (defense in depth on top of the
 * route group). Read endpoints never expose message content — only aggregates.
 */
class EddyAdminController extends Controller
{
    public function __construct(private readonly EddyProviderPolicyService $policy) {}

    /** Cloud spend + redaction aggregates (per-provider, per-surface, and budget). */
    public function usage(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $byProvider = EddyCloudUsage::query()
            ->selectRaw('provider, count(*) as calls, coalesce(sum(cost_usd),0) as cost_usd, '
                .'coalesce(sum(tokens_in),0) as tokens_in, coalesce(sum(tokens_out),0) as tokens_out, '
                .'coalesce(sum(sanitizer_redaction_count),0) as redactions')
            ->groupBy('provider')
            ->get();

        $bySurface = EddyCloudUsage::query()
            ->selectRaw('request_surface as surface, count(*) as calls, '
                .'coalesce(sum(cost_usd),0) as cost_usd, coalesce(sum(sanitizer_redaction_count),0) as redactions')
            ->groupBy('request_surface')
            ->orderByDesc('cost_usd')
            ->get();

        $totalCost = (float) EddyCloudUsage::sum('cost_usd');
        $monthlyBudget = (float) config('eddy.budget.monthly_usd', 0.0);

        return response()->json(['data' => [
            'by_provider' => $byProvider,
            'by_surface' => $bySurface,
            'totals' => [
                'cost_usd' => round($totalCost, 4),
                'redactions' => (int) EddyCloudUsage::sum('sanitizer_redaction_count'),
                'cloud_calls' => (int) EddyCloudUsage::count(),
            ],
            'budget' => [
                'monthly_usd' => $monthlyBudget,
                'utilization' => $monthlyBudget > 0 ? round($totalCost / $monthlyBudget, 4) : null,
            ],
        ]]);
    }

    /** Dry-run the surface→provider routing decision (no model is called). */
    public function simulate(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'surface' => ['required', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $this->policy->simulateRoute($validated)]);
    }

    /** The auto-curated knowledge awaiting review. */
    public function proposedKnowledge(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $proposed = EddyKnowledge::query()
            ->proposed()
            ->orderByDesc('eddy_knowledge_id')
            ->limit(100)
            ->get(['eddy_knowledge_uuid', 'surface', 'category', 'title', 'body', 'source', 'curated_from', 'created_at']);

        return response()->json(['data' => $proposed]);
    }

    /** Approve (→ RAG-eligible) or retire a proposed knowledge entry. */
    public function reviewKnowledge(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:approved,retired'],
        ]);

        $knowledge = EddyKnowledge::query()
            ->where('eddy_knowledge_uuid', $uuid)
            ->where('status', 'proposed')
            ->firstOrFail();

        $knowledge->update([
            'status' => $validated['decision'],
            'is_active' => $validated['decision'] === 'approved',
            'updated_by' => $request->user()->id,
        ]);

        return response()->json(['data' => [
            'eddy_knowledge_uuid' => $knowledge->eddy_knowledge_uuid,
            'status' => $knowledge->status,
        ]]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole(['super-admin', 'admin']) ?? false, 403, 'Super-admin access required.');
    }
}
