<?php

namespace App\Http\Controllers\Api\Rounds;

use App\Http\Requests\Rounds\CreateContributionRequest;
use App\Services\Rounds\RoundContributionService;
use App\Services\Rounds\RoundProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoundContributionController extends RoundsController
{
    public function __construct(
        RoundProjectionService $projection,
        private readonly RoundContributionService $contributions,
    ) {
        parent::__construct($projection);
    }

    public function store(CreateContributionRequest $request, string $roundPatientUuid): JsonResponse
    {
        $patient = $this->resolvePatient($roundPatientUuid);

        return $this->guard(function () use ($request, $patient): JsonResponse {
            $contribution = $this->contributions->compose($request->user(), $patient, array_merge(
                $request->validated(),
                ['idempotency_key' => $this->idempotencyKey($request)],
            ));

            return response()->json(
                $this->projection->patientDetail($patient->refresh(), $request->user()),
                201,
            );
        }, $patient->run, $request);
    }

    public function submit(Request $request, string $contributionUuid): JsonResponse
    {
        $contribution = $this->resolveContribution($contributionUuid);
        $patient = $contribution->patient;

        return $this->guard(function () use ($request, $contribution, $patient): JsonResponse {
            $this->contributions->submit($request->user(), $contribution);

            return response()->json($this->projection->patientDetail($patient->refresh(), $request->user()));
        }, $patient->run, $request);
    }

    public function withdraw(Request $request, string $contributionUuid): JsonResponse
    {
        $contribution = $this->resolveContribution($contributionUuid);
        $patient = $contribution->patient;

        return $this->guard(function () use ($request, $contribution, $patient): JsonResponse {
            $this->contributions->withdraw($request->user(), $contribution, $request->input('reason'));

            return response()->json($this->projection->patientDetail($patient->refresh(), $request->user()));
        }, $patient->run, $request);
    }
}
