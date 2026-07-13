<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AssignRequestIdentity;
use App\Services\Admin\SystemHealthService;
use App\Services\Audit\UserAuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class SystemHealthController extends Controller
{
    public function __construct(
        private readonly SystemHealthService $health,
        private readonly UserAuditRecorder $audit,
    ) {}

    public function index(): Response
    {
        Gate::authorize('viewSystemHealth');

        return $this->render();
    }

    public function show(string $component): Response
    {
        Gate::authorize('viewSystemHealth');

        return $this->render($component);
    }

    public function diagnostics(Request $request): JsonResponse
    {
        Gate::authorize('runDiagnostics');

        $snapshot = DB::transaction(function () use ($request): array {
            $snapshot = $this->health->collect(
                'manual',
                $request->user(),
                (string) $request->attributes->get(AssignRequestIdentity::ATTRIBUTE),
            );
            $this->audit->record('administration.system_health.diagnostic', 'administration', 'success', [
                'request' => $request,
                'actor' => $request->user(),
                'target_type' => 'system_health',
                'target_id' => $snapshot['batchUuid'],
                'metadata' => [
                    'batch_uuid' => $snapshot['batchUuid'],
                    'component_count' => $snapshot['batchObservationCount'],
                    'critical_count' => $snapshot['counts']['critical'],
                    'warning_count' => $snapshot['counts']['warning'],
                    'unknown_count' => $snapshot['counts']['unknown'],
                ],
            ]);

            return $snapshot;
        });

        return response()->json($snapshot);
    }

    private function render(?string $selected = null): Response
    {
        return Inertia::render('Admin/SystemHealth', [
            'snapshot' => $this->health->snapshot($selected),
            'canRunDiagnostics' => Gate::allows('runDiagnostics'),
        ]);
    }
}
