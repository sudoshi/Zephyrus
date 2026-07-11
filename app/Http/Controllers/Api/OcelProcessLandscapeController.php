<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ocel\HospitalProcessLandscapeService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class OcelProcessLandscapeController extends Controller
{
    public function __construct(private readonly HospitalProcessLandscapeService $landscape) {}

    public function index(): JsonResponse
    {
        $payload = $this->landscape->index();
        $status = ($payload['available'] ?? false) ? 200 : 503;

        return response()->json($payload, $status);
    }

    public function show(string $processId): JsonResponse
    {
        $payload = $this->landscape->show($processId);

        return $payload === null
            ? response()->json(['message' => 'OCEL process model not found.'], 404)
            : response()->json($payload);
    }
}
