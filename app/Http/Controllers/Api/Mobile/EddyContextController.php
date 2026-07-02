<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Services\Mobile\EddyOperationalAwarenessService;
use App\Services\Mobile\MobilePersonaCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EddyContextController extends Controller
{
    use RendersMobileEnvelope;

    public function __construct(
        private readonly EddyOperationalAwarenessService $eddy,
        private readonly MobilePersonaCatalog $personas,
    ) {}

    public function show(Request $request, string $scopeRef): JsonResponse
    {
        $roleId = $this->personas->fromRequest($request);

        return $this->envelope(
            $this->eddy->packet($scopeRef, $request->user(), $roleId),
            links: ['web' => url('/dashboard')],
        );
    }
}
