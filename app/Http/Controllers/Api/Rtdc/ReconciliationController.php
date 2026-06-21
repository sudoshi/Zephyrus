<?php

namespace App\Http\Controllers\Api\Rtdc;

use App\Http\Controllers\Controller;
use App\Models\RtdcReconciliation;
use Illuminate\Http\JsonResponse;

class ReconciliationController extends Controller
{
    public function latest(int $unitId): JsonResponse
    {
        $recon = RtdcReconciliation::where('unit_id', $unitId)
            ->orderByDesc('service_date')
            ->first();

        return response()->json(['data' => $recon]);
    }
}
