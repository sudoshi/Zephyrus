<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Services\Mobile\MobileForYouService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/mobile/v1/for-you
 *
 * The "For You" queue (Phase 1) — a single prioritized list of things that need action,
 * composed from pending bed placements, open discharge barriers, and units at/over safe
 * capacity. PHI-minimized (no patient identifiers, no free-text descriptions); ranked by the
 * rationed tier vocabulary so the most urgent item is first.
 */
class ForYouController extends Controller
{
    use RendersMobileEnvelope;

    public function __construct(private readonly MobileForYouService $forYou) {}

    public function index(): JsonResponse
    {
        $sorted = $this->forYou->items();

        return $this->envelope($sorted, meta: ['count' => $sorted->count()], links: ['web' => url('/rtdc/bed-tracking')]);
    }
}
