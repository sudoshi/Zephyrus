<?php

namespace App\Http\Controllers\Api\Rounds;

use App\Http\Requests\Rounds\CreateTaskRequest;
use App\Models\Rounds\RoundEvent;
use App\Models\Rounds\RoundTask;
use App\Services\Rounds\RoundAuthorizationService;
use App\Services\Rounds\RoundProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoundTaskController extends RoundsController
{
    public function __construct(
        RoundProjectionService $projection,
        private readonly RoundAuthorizationService $authorization,
    ) {
        parent::__construct($projection);
    }

    public function store(CreateTaskRequest $request, string $roundPatientUuid): JsonResponse
    {
        $patient = $this->resolvePatient($roundPatientUuid);
        $this->authorization->assertCanContribute($request->user(), $patient->run);

        DB::transaction(function () use ($request, $patient): void {
            $task = RoundTask::create(array_merge($request->validated(), [
                'task_uuid' => (string) Str::uuid(),
                'run_id' => $patient->run_id,
                'round_patient_id' => $patient->round_patient_id,
                'status' => 'open',
                'created_by' => $request->user()->id,
            ]));

            RoundEvent::record(
                'task', $task->task_id, $task->task_uuid, 1,
                $request->user()->id, 'task.created',
                ['category' => $task->category],
            );
        });

        return response()->json($this->projection->patientDetail($patient->refresh(), $request->user()), 201);
    }

    public function transition(Request $request, string $taskUuid): JsonResponse
    {
        $task = RoundTask::query()->where('task_uuid', $taskUuid)->firstOrFail();
        $patient = $task->patient;
        $run = $task->run;

        $this->authorization->assertCanContribute($request->user(), $run);

        $status = (string) $request->input('status');
        abort_unless(in_array($status, ['in_progress', 'completed', 'cancelled'], true), 422);

        DB::transaction(function () use ($request, $task, $status): void {
            $task->update([
                'status' => $status,
                'completed_by' => $status === 'completed' ? $request->user()->id : null,
                'completed_at' => $status === 'completed' ? now() : null,
            ]);

            RoundEvent::record(
                'task', $task->task_id, $task->task_uuid, 1,
                $request->user()->id, 'task.'.$status, [],
            );
        });

        if ($patient !== null) {
            return response()->json($this->projection->patientDetail($patient->refresh(), $request->user()));
        }

        return response()->json($this->projection->board($run->refresh(), $request->user()));
    }
}
