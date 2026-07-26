<?php

namespace App\Http\Controllers\Api\Rounds;

use App\Http\Requests\Rounds\CreateQuestionRequest;
use App\Http\Requests\Rounds\DeferPatientRoundQuestionRequest;
use App\Http\Requests\Rounds\PromotePatientRoundQuestionRequest;
use App\Models\Rounds\RoundEvent;
use App\Models\Rounds\RoundQuestion;
use App\Services\Rounds\PatientRoundQuestionPromotionService;
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
        private readonly PatientRoundQuestionPromotionService $patientQuestionPromotions,
    ) {
        parent::__construct($projection);
    }

    public function promotePatientQuestion(
        PromotePatientRoundQuestionRequest $request,
        string $roundPatientUuid,
        string $threadUuid,
    ): JsonResponse {
        $patient = $this->resolvePatient($roundPatientUuid);

        return $this->guard(function () use ($request, $patient, $threadUuid): JsonResponse {
            $result = $this->patientQuestionPromotions->promote(
                $request,
                $request->user(),
                $patient,
                $threadUuid,
                $request->validated(),
            );

            return response()->json(
                $this->projection->patientDetail($patient->refresh(), $request->user()),
                $result['replayed'] ? 200 : 201,
            );
        }, $patient->run, $request);
    }

    public function availablePatientQuestions(
        \Illuminate\Http\Request $request,
        string $roundPatientUuid,
    ): JsonResponse {
        $patient = $this->resolvePatient($roundPatientUuid);

        return $this->guard(function () use ($request, $patient): JsonResponse {
            return response()->json($this->projection->envelope($patient->run, $request->user(), [
                'patient_questions' => $this->patientQuestionPromotions->available($request->user(), $patient),
            ]));
        }, $patient->run, $request);
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

            $this->patientQuestionPromotions->recordResolutionOutcome(
                $request,
                $request->user(),
                $question,
                $status,
            );
        });

        return response()->json($this->projection->patientDetail($patient->refresh(), $request->user()));
    }

    public function defer(DeferPatientRoundQuestionRequest $request, string $questionUuid): JsonResponse
    {
        $question = RoundQuestion::query()->where('question_uuid', $questionUuid)->firstOrFail();
        $patient = $question->patient;
        $this->authorization->assertCanContribute($request->user(), $patient->run);
        abort_unless($this->authorization->canResolveQuestion($request->user(), $question, $patient->run), 403);

        return $this->guard(function () use ($request, $question, $patient): JsonResponse {
            $idempotencyKey = (string) $request->validated('idempotency_key');
            $replayed = false;

            DB::transaction(function () use ($request, $question, $idempotencyKey, &$replayed): void {
                $lockedQuestion = RoundQuestion::query()
                    ->whereKey($question->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $existingEvent = RoundEvent::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existingEvent instanceof RoundEvent) {
                    if ($existingEvent->event_type !== 'question.deferred'
                        || $existingEvent->aggregate_type !== 'question'
                        || (int) $existingEvent->aggregate_id !== (int) $lockedQuestion->getKey()
                        || (int) $existingEvent->actor_user_id !== (int) $request->user()->getKey()
                    ) {
                        throw new \App\Exceptions\Rounds\RoundConflictException('This idempotency key was already used for a different question action.');
                    }

                    $replayed = true;

                    return;
                }

                $this->patientQuestionPromotions->recordDeferralOutcome(
                    $request,
                    $request->user(),
                    $lockedQuestion,
                    $idempotencyKey,
                );

                RoundEvent::record(
                    'question',
                    $lockedQuestion->question_id,
                    $lockedQuestion->question_uuid,
                    1,
                    $request->user()->id,
                    'question.deferred',
                    ['event_type' => 'patient_question_deferred'],
                    $idempotencyKey,
                );
            });

            return response()->json(
                $this->projection->patientDetail($patient->refresh(), $request->user()),
                $replayed ? 200 : 201,
            );
        }, $patient->run, $request);
    }
}
