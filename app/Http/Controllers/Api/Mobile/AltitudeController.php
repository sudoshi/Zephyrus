<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Services\Mobile\MobileAltitudeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AltitudeController extends Controller
{
    use RendersMobileEnvelope;

    public function __construct(private readonly MobileAltitudeService $altitude) {}

    public function home(Request $request): JsonResponse
    {
        return $this->envelope($this->altitude->home($request), links: ['web' => url('/dashboard')]);
    }

    public function workspace(Request $request, string $domain): JsonResponse
    {
        return $this->envelope($this->altitude->workspace($request, $domain), links: ['web' => url('/dashboard')]);
    }

    public function drill(Request $request, string $itemUuid): JsonResponse
    {
        return $this->envelope($this->altitude->drill($request, $itemUuid), links: ['web' => url('/dashboard')]);
    }
}
