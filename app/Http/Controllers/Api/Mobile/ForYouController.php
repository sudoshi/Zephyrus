<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ProtectPatientCommunicationResponse;
use App\Models\User;
use App\Services\Mobile\MobileForYouService;
use App\Services\Patient\Messaging\StaffPatientCommunicationFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw StaffPatientCommunicationFailure::notFound();
        }

        try {
            $sorted = $this->forYou->itemsForUser($user);
            $response = $this->envelope($sorted, meta: [
                'count' => $sorted->count(),
                'classification' => 'phi_minimized_restricted',
                'offline_cache_allowed' => false,
            ], links: ['web' => url('/rtdc/bed-tracking')]);
        } catch (StaffPatientCommunicationFailure $failure) {
            $response = response()->json([
                'error' => [
                    'code' => $failure->errorCode,
                    'message' => $failure->getMessage(),
                ],
                'meta' => [
                    'as_of' => now()->toISOString(),
                    'classification' => 'phi_minimized_restricted',
                    'offline_cache_allowed' => false,
                ],
            ], $failure->httpStatus);
        }

        ProtectPatientCommunicationResponse::protect($response);

        return $response;
    }
}
