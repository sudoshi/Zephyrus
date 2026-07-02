<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Services\Mobile\MobilePatientContextService;
use App\Services\Mobile\MobilePersonaCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientContextController extends Controller
{
    use RendersMobileEnvelope;

    public function __construct(
        private readonly MobilePatientContextService $patients,
        private readonly MobilePersonaCatalog $personas,
    ) {}

    public function show(Request $request, string $contextRef): JsonResponse
    {
        $roleId = $this->personas->fromRequest($request);

        return $this->envelope(
            $this->patients->build($contextRef, $request->user(), $roleId),
            links: ['web' => url('/rtdc/bed-tracking')],
        );
    }
}
