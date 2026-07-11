<?php

namespace App\Http\Controllers\Api\Rounds;

use App\Http\Requests\Rounds\CompleteRunRequest;
use App\Http\Requests\Rounds\CreateRunRequest;
use App\Http\Requests\Rounds\ReconcileCohortRequest;
use App\Http\Requests\Rounds\ReorderQueueRequest;
use App\Models\Rounds\RoundRun;
use App\Services\Rounds\RoundAuthorizationService;
use App\Services\Rounds\RoundCohortBuilder;
use App\Services\Rounds\RoundCommandService;
use App\Services\Rounds\RoundProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoundRunController extends RoundsController
{
    public function __construct(
        RoundProjectionService $projection,
        private readonly RoundCommandService $commands,
        private readonly RoundAuthorizationService $authorization,
        private readonly RoundCohortBuilder $cohortBuilder,
    ) {
        parent::__construct($projection);
    }

    public function index(Request $request): JsonResponse
    {
        $query = RoundRun::query()->with('template')->orderByDesc('created_at')->limit(50);

        if ($request->filled('scope')) {
            [$type, $key] = array_pad(explode(':', (string) $request->query('scope'), 2), 2, null);
            $query->where('scope_type', $type)->where('scope_key', $key);
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('date')) {
            $query->whereDate('created_at', (string) $request->query('date'));
        }

        $user = $request->user();
        $runs = $query->get()
            ->filter(fn (RoundRun $run) => $this->authorization->canViewRun($user, $run))
            ->map(fn (RoundRun $run) => $this->projection->runSummary($run))
            ->values();

        return response()->json(['data' => $runs]);
    }

    public function store(CreateRunRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $run = $this->commands->createRun($request->user(), array_merge(
                $request->validated(),
                ['idempotency_key' => $this->idempotencyKey($request)],
            ));

            return response()->json($this->projection->board($run, $request->user()), 201);
        });
    }

    public function show(Request $request, string $runUuid): JsonResponse
    {
        $run = $this->resolveRun($runUuid);
        $this->authorization->assertCanViewRun($request->user(), $run);

        return response()->json($this->projection->envelope($run, $request->user(), $this->projection->runSummary($run)));
    }

    public function board(Request $request, string $runUuid): JsonResponse
    {
        $run = $this->resolveRun($runUuid);
        $this->authorization->assertCanViewRun($request->user(), $run);

        return response()->json($this->projection->board($run, $request->user()));
    }

    public function scene(Request $request, string $runUuid): JsonResponse
    {
        $run = $this->resolveRun($runUuid);
        $this->authorization->assertCanViewRun($request->user(), $run);

        return response()->json($this->projection->scene($run, $request->user()));
    }

    public function start(Request $request, string $runUuid): JsonResponse
    {
        return $this->lifecycle($request, $runUuid, 'start');
    }

    public function pause(Request $request, string $runUuid): JsonResponse
    {
        return $this->lifecycle($request, $runUuid, 'pause');
    }

    public function resume(Request $request, string $runUuid): JsonResponse
    {
        return $this->lifecycle($request, $runUuid, 'resume');
    }

    public function cancel(Request $request, string $runUuid): JsonResponse
    {
        $run = $this->resolveRun($runUuid);

        return $this->guard(function () use ($request, $run): JsonResponse {
            $updated = $this->commands->cancel($request->user(), $run, [
                'reason' => $request->input('reason'),
                'idempotency_key' => $this->idempotencyKey($request),
            ]);

            return response()->json($this->projection->board($updated, $request->user()));
        }, $run, $request);
    }

    public function complete(CompleteRunRequest $request, string $runUuid): JsonResponse
    {
        $run = $this->resolveRun($runUuid);

        return $this->guard(function () use ($request, $run): JsonResponse {
            $updated = $this->commands->complete($request->user(), $run, array_merge(
                $request->validated(),
                ['idempotency_key' => $this->idempotencyKey($request)],
            ));

            return response()->json($this->projection->board($updated, $request->user()));
        }, $run, $request);
    }

    public function queue(ReorderQueueRequest $request, string $runUuid): JsonResponse
    {
        $run = $this->resolveRun($runUuid);

        return $this->guard(function () use ($request, $run): JsonResponse {
            $updated = $this->commands->reorderQueue(
                $request->user(),
                $run,
                $request->validated('order'),
                (int) $request->validated('expected_queue_version'),
                ['idempotency_key' => $this->idempotencyKey($request)],
            );

            return response()->json($this->projection->board($updated, $request->user()));
        }, $run, $request);
    }

    /**
     * No body: return reconciliation suggestions. With apply sets: enroll /
     * defer the listed patients and return the updated board.
     */
    public function reconcile(ReconcileCohortRequest $request, string $runUuid): JsonResponse
    {
        $run = $this->resolveRun($runUuid);
        $this->authorization->assertCanViewRun($request->user(), $run);

        $validated = $request->validated();

        if (empty($validated['add']) && empty($validated['remove'])) {
            $unit = $this->authorization->resolveUnit($run->scope_key);

            abort_if($unit === null, 422, 'Run scope unit no longer exists.');

            return response()->json($this->projection->envelope(
                $run,
                $request->user(),
                ['suggestions' => $this->cohortBuilder->suggestReconciliation($run, $unit)],
            ));
        }

        return $this->guard(function () use ($request, $run, $validated): JsonResponse {
            $updated = $this->commands->applyReconciliation($request->user(), $run, array_merge(
                $validated,
                ['idempotency_key' => $this->idempotencyKey($request)],
            ));

            return response()->json($this->projection->board($updated, $request->user()));
        }, $run, $request);
    }

    private function lifecycle(Request $request, string $runUuid, string $command): JsonResponse
    {
        $run = $this->resolveRun($runUuid);

        return $this->guard(function () use ($request, $run, $command): JsonResponse {
            $updated = $this->commands->{$command}($request->user(), $run, [
                'idempotency_key' => $this->idempotencyKey($request),
            ]);

            return response()->json($this->projection->board($updated, $request->user()));
        }, $run, $request);
    }
}
