<?php

namespace App\Http\Controllers\Api\Ops;

use App\Http\Controllers\Controller;
use App\Models\Ops\OperationsNode;
use App\Services\Ops\OperationalActionLifecycleService;
use App\Services\Ops\OperationsGraphProjector;
use App\Services\Ops\OperationsRecommendationService;
use Illuminate\Http\JsonResponse;

class OperationsGraphController extends Controller
{
    public function __construct(
        private readonly OperationsGraphProjector $projector,
        private readonly OperationsRecommendationService $recommendations,
        private readonly OperationalActionLifecycleService $lifecycle,
    ) {}

    public function snapshot(): JsonResponse
    {
        $snapshot = $this->projector->rebuild();

        return response()->json([
            'data' => $this->projector->serializeSnapshot($snapshot),
        ]);
    }

    public function node(string $node): JsonResponse
    {
        $query = OperationsNode::query()->where('is_active', true);
        $graphNode = ctype_digit($node)
            ? $query->where('graph_node_id', (int) $node)->firstOrFail()
            : $query->where('canonical_key', $node)->firstOrFail();

        return response()->json([
            'data' => $this->projector->serializeNodeTimeline($graphNode),
        ]);
    }

    public function recommendations(): JsonResponse
    {
        return response()->json([
            'data' => $this->recommendations->generate(),
        ]);
    }

    public function agentInbox(): JsonResponse
    {
        $this->recommendations->generate(rebuildGraph: false);

        return response()->json([
            'data' => $this->lifecycle->inbox(),
        ]);
    }
}
