<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Services\Mobile\MobilePersonaCatalog;
use App\Services\Mobile\OperationalActivityLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    use RendersMobileEnvelope;

    public function __construct(
        private readonly OperationalActivityLedger $ledger,
        private readonly MobilePersonaCatalog $personas,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $roleId = $this->personas->fromRequest($request);
        $feed = $this->ledger->feed($request->user(), $roleId, $request->query('cursor'), 50);

        return $this->envelope(
            $feed['data'],
            meta: ['count' => count($feed['data']), 'next_cursor' => $feed['next_cursor']],
            links: ['web' => url('/ops/agent-inbox')],
        );
    }

    public function ack(Request $request, string $eventUuid): JsonResponse
    {
        $roleId = $this->personas->fromRequest($request);

        return $this->envelope(
            $this->ledger->acknowledge($eventUuid, $request->user(), $roleId),
            links: ['web' => url('/ops/agent-inbox')],
        );
    }
}
