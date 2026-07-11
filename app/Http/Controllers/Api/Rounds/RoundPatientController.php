<?php

namespace App\Http\Controllers\Api\Rounds;

use App\Http\Requests\Rounds\PinPatientRequest;
use App\Http\Requests\Rounds\TransitionPatientRequest;
use App\Services\Rounds\RoundAuthorizationService;
use App\Services\Rounds\RoundCommandService;
use App\Services\Rounds\RoundProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoundPatientController extends RoundsController
{
    public function __construct(
        RoundProjectionService $projection,
        private readonly RoundCommandService $commands,
        private readonly RoundAuthorizationService $authorization,
    ) {
        parent::__construct($projection);
    }

    public function show(Request $request, string $roundPatientUuid): JsonResponse
    {
        $patient = $this->resolvePatient($roundPatientUuid);
        $this->authorization->assertCanViewRun($request->user(), $patient->run);

        return response()->json($this->projection->patientDetail($patient, $request->user()));
    }

    public function markReady(TransitionPatientRequest $request, string $roundPatientUuid): JsonResponse
    {
        return $this->transition($request, $roundPatientUuid, 'ready_for_review');
    }

    public function complete(TransitionPatientRequest $request, string $roundPatientUuid): JsonResponse
    {
        return $this->transition($request, $roundPatientUuid, 'rounded');
    }

    public function reopen(TransitionPatientRequest $request, string $roundPatientUuid): JsonResponse
    {
        return $this->transition($request, $roundPatientUuid, 'in_progress');
    }

    public function defer(TransitionPatientRequest $request, string $roundPatientUuid): JsonResponse
    {
        return $this->transition($request, $roundPatientUuid, 'deferred');
    }

    public function skip(TransitionPatientRequest $request, string $roundPatientUuid): JsonResponse
    {
        return $this->transition($request, $roundPatientUuid, 'skipped');
    }

    public function pin(PinPatientRequest $request, string $roundPatientUuid): JsonResponse
    {
        $patient = $this->resolvePatient($roundPatientUuid);
        $run = $patient->run;

        return $this->guard(function () use ($request, $patient): JsonResponse {
            $updated = $this->commands->setPin(
                $request->user(),
                $patient,
                (bool) $request->validated('pinned'),
                $request->validated('reason'),
                (int) $request->validated('expected_queue_version'),
                ['idempotency_key' => $this->idempotencyKey($request)],
            );

            return response()->json($this->projection->board($updated, $request->user()));
        }, $run, $request);
    }

    private function transition(TransitionPatientRequest $request, string $roundPatientUuid, string $to): JsonResponse
    {
        $patient = $this->resolvePatient($roundPatientUuid);
        $run = $patient->run;

        return $this->guard(function () use ($request, $patient, $to): JsonResponse {
            $updated = $this->commands->transitionPatient($request->user(), $patient, $to, array_merge(
                $request->validated(),
                ['idempotency_key' => $this->idempotencyKey($request)],
            ));

            return response()->json($this->projection->patientDetail($updated, $request->user()));
        }, $run, $request);
    }
}
