<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Integrations\Healthcare\Services\SourceRegistryService;
use Illuminate\Http\JsonResponse;

class IntegrationHealthController extends Controller
{
    public function __construct(private readonly SourceRegistryService $sources) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => $this->sources->healthSummary(),
        ]);
    }
}
