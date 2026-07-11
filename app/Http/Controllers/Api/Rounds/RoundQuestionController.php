<?php

namespace App\Http\Controllers\Api\Rounds;

use App\Http\Requests\Rounds\CreateQuestionRequest;
use App\Models\Rounds\RoundEvent;
use App\Models\Rounds\RoundQuestion;
use App\Services\Rounds\RoundAuthorizationService;
use App\Services\Rounds\RoundProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoundQuestionController extends RoundsController
{
    public function __construct(
        RoundProjectionService $projection,
        private readonly RoundAuthorizationService $authorization,
    ) {
        parent::__construct($projection);
    }

    public function store(CreateQuestionRequest $request, string $roundPatientUuid): JsonResponse
    {
        $patient = $this->resolvePatient($roundPatientUuid);
        $this->authorization->assertCanContribute($request->user(), $patient->run);

        $question = DB::transaction(function () use ($request, $patient): RoundQuestion {
            $question = RoundQuestion::create(array_merge($request->validated(), [
                'question_uuid' => (string) Str::uuid(),
                'round_patient_id' => $patient->round_patient_id,
                'raised_by' => $request->user()->id,
                'raised_role' => $this->authorization->contributorRoleFor($request->user(), $patient->run),
                'status' => 'open',
            ]));

            RoundEvent::record(
                'question', $question->question_id, $question->question_uuid, 1,
                $request->user()->id, 'question.raised',
                ['target_role' => $question->target_role],
            );

            return $question;
        });

        return response()->json($this->projection->patientDetail($patient->refresh(), $request->user()), 201);
    }

    public function resolve(\Illuminate\Http\Request $request, string $questionUuid): JsonResponse
    {
        $question = RoundQuestion::query()->where('question_uuid', $questionUuid)->firstOrFail();
        $patient = $question->patient;
        $this->authorization->assertCanContribute($request->user(), $patient->run);
        // Resolving belongs to the question's target/raiser or a run leader.
        abort_unless($this->authorization->canResolveQuestion($request->user(), $question, $patient->run), 403);

        $status = $request->input('status', 'answered');
        abort_unless(in_array($status, ['answered', 'dismissed'], true), 422);

        DB::transaction(function () use ($request, $question, $status): void {
            $question->update(['status' => $status, 'answered_at' => now()]);

            RoundEvent::record(
                'question', $question->question_id, $question->question_uuid, 1,
                $request->user()->id, 'question.'.$status, [],
            );
        });

        return response()->json($this->projection->patientDetail($patient->refresh(), $request->user()));
    }
}
