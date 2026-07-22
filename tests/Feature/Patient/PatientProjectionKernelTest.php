<?php

namespace Tests\Feature\Patient;

use App\Models\Patient\PatientContentAction;
use App\Models\Patient\PatientEncounterProjection;
use App\Models\Patient\PatientProjectionCursor;
use App\Models\Patient\PatientProjectionFailure;
use App\Services\Patient\PatientHmac;
use App\Services\Patient\Projection\PatientProjectionContentGuard;
use App\Services\Patient\Projection\SyntheticPatientProjectionProvisioner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class PatientProjectionKernelTest extends TestCase
{
    use RefreshDatabase;

    public function test_projection_kernel_tables_columns_and_database_guards_exist(): void
    {
        foreach ([
            'patient_experience.release_policy_versions',
            'patient_experience.source_projection_cursors',
            'patient_experience.source_projection_failures',
            'patient_experience.encounter_projections',
            'patient_experience.content_actions',
            'patient_experience.pathway_projection_reviews',
            'patient_experience.pathway_projection_release_executions',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing projection-kernel table {$table}.");
        }

        foreach ([
            'projection_uuid', 'access_grant_id', 'release_policy_version_id',
            'projection_kind', 'projection_sequence', 'content', 'content_digest',
            'source_version', 'provenance', 'source_observed_at', 'generated_at',
            'released_at', 'freshness_class', 'uncertainty', 'required_scope',
            'permitted_relationships', 'release_state',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('patient_experience.encounter_projections', $column),
                "Missing governed projection column {$column}.",
            );
        }

        $triggers = collect(DB::select(<<<'SQL'
SELECT trigger_name
FROM information_schema.triggers
WHERE trigger_schema = 'patient_experience'
  AND event_object_table IN (
      'release_policy_versions',
      'source_projection_cursors',
      'source_projection_failures',
      'encounter_projections',
      'content_actions',
      'pathway_projection_reviews',
      'pathway_projection_release_executions'
  )
SQL))->pluck('trigger_name')->unique()->sort()->values()->all();

        $this->assertSame([
            'patient_content_actions_append_only',
            'patient_encounter_projections_append_only',
            'patient_projection_cursors_append_only',
            'patient_projection_failures_append_only',
            'patient_pathway_projection_release_execution_valid',
            'patient_pathway_projection_release_executions_append_only',
            'patient_pathway_projection_review_valid',
            'patient_pathway_projection_reviews_append_only',
            'patient_pathway_release_execution_required',
            'patient_released_projection_outbox',
            'patient_release_policy_versions_append_only',
        ], $triggers);
    }

    public function test_synthetic_fixture_is_deterministic_hashes_source_references_and_has_no_password(): void
    {
        $provisioner = $this->app->make(SyntheticPatientProjectionProvisioner::class);
        $first = $provisioner->provision('deterministic-reference');
        $second = $provisioner->provision('deterministic-reference');

        $this->assertSame($first['principal']->getKey(), $second['principal']->getKey());
        $this->assertSame((string) $first['grant']->encounter_uuid, (string) $second['grant']->encounter_uuid);
        $this->assertSame(
            (string) $first['projections']['today']->projection_uuid,
            (string) $second['projections']['today']->projection_uuid,
        );
        $this->assertDatabaseCount('patient_experience.encounter_projections', 6);
        $this->assertDatabaseCount('patient_experience.source_projection_cursors', 6);
        $this->assertNull($first['principal']->password);

        $plainEncounterHash = hash('sha256', 'synthetic-encounter|deterministic-reference');
        $this->assertNotSame($plainEncounterHash, (string) $first['grant']->source_encounter_ref_digest);
        $this->assertSame(
            $this->app->make(PatientHmac::class)->digest('synthetic-encounter-ref', 'deterministic-reference'),
            (string) $first['grant']->source_encounter_ref_digest,
        );

        $cursor = PatientProjectionCursor::query()->where('projection_kind', 'today')->firstOrFail();
        $this->assertNotSame(
            hash('sha256', 'synthetic-cursor|deterministic-reference|today'),
            (string) $cursor->cursor_digest,
        );
    }

    public function test_synthetic_fixture_refuses_every_non_testing_runtime(): void
    {
        $originalEnvironment = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->app->make(SyntheticPatientProjectionProvisioner::class)
                ->provision('must-not-be-created');
            $this->fail('Synthetic projection provisioning unexpectedly ran outside testing.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'synthetic_patient_projection_provisioning_forbidden',
                $exception->getMessage(),
            );
        } finally {
            $this->app['env'] = $originalEnvironment;
        }

        $this->assertDatabaseCount('patient_experience.principals', 0);
        $this->assertDatabaseCount('patient_experience.encounter_projections', 0);
    }

    public function test_content_guard_accepts_only_explicit_patient_language_nested_contracts(): void
    {
        $guard = $this->app->make(PatientProjectionContentGuard::class);
        $provenance = [
            'projection_method' => 'automated_test',
            'source_class' => 'synthetic_clinical_record',
            'input_classes' => ['synthetic_care_plan'],
            'review_state' => 'automated_test_only',
            'producer_version' => 'test-v1',
        ];
        $uncertainty = [
            'level' => 'low',
            'explanation' => 'Plans can change.',
            'can_change' => true,
            'reviewed_at' => now()->toISOString(),
        ];

        $valid = [
            'today' => [
                'headline' => 'Today',
                'summary' => 'Your released plan for today.',
                'schedule' => [[
                    'item_uuid' => (string) Str::uuid(),
                    'label' => 'Care team rounds',
                    'status' => 'planned',
                    'time_window' => 'This morning',
                    'timing_confidence' => 'estimated',
                    'can_change' => true,
                ]],
                'next_steps' => ['Speak with bedside staff if you have questions.'],
            ],
            'pathway' => [
                'headline' => 'My Path',
                'summary' => 'Your released care pathway.',
                'current_stage' => 'Monitoring',
                'stages' => [[
                    'stage_uuid' => (string) Str::uuid(),
                    'title' => 'Monitoring',
                    'status' => 'current',
                    'summary' => 'Your team is checking your progress.',
                    'can_change' => true,
                ]],
                'milestones' => [[
                    'milestone_uuid' => (string) Str::uuid(),
                    'title' => 'Responding to treatment',
                    'status' => 'current',
                    'detail' => 'Your team is watching your progress each day.',
                    'can_change' => true,
                ]],
            ],
            'pathway_events' => [
                'headline' => 'What has happened so far',
                'summary' => 'A simple timeline of your stay.',
                'events' => [[
                    'event_uuid' => (string) Str::uuid(),
                    'title' => 'Admitted to the hospital',
                    'when' => 'Two days ago',
                    'category' => 'test',
                    'status' => 'completed',
                    'detail' => 'Your care team reviewed your plan.',
                ]],
            ],
            'discharge_readiness' => [
                'headline' => 'Getting ready to leave',
                'summary' => 'What still needs to happen before you can safely go home.',
                'estimated_range' => 'In the next day or two',
                'estimated_confidence' => 'estimated',
                'criteria' => [[
                    'item_uuid' => (string) Str::uuid(),
                    'label' => 'Comfortable with your pain plan',
                    'status' => 'met',
                ]],
                'warning_signs' => ['Call your team if symptoms get worse at home.'],
            ],
            'rounds_summary' => [
                'headline' => 'Your care-team conversation',
                'summary' => 'A plain-language summary your team released after reviewing your care.',
                'round_window' => 'Earlier today',
                'topics' => [[
                    'topic_uuid' => (string) Str::uuid(),
                    'title' => 'How you are doing',
                    'summary' => 'Your team reviewed your progress and next steps.',
                    'status' => 'current',
                ]],
                'next_steps' => ['Tell your bedside team what you would like explained.'],
            ],
            'care_team' => [
                'headline' => 'Your care team',
                'summary' => 'The roles involved in your care.',
                'members' => [[
                    'member_uuid' => (string) Str::uuid(),
                    'display_name' => 'Care Coordinator',
                    'role' => 'Care coordination',
                    'responsibilities' => ['Helps coordinate your care plan.'],
                    'contact_route' => 'speak_with_bedside_staff',
                ]],
                'communication_options' => ['speak_with_bedside_staff'],
            ],
        ];

        foreach ($valid as $kind => $content) {
            $guard->assertSafe($kind, $content, $provenance, $uncertainty, ['self']);
            $this->addToAssertionCount(1);
        }

        $invalid = [
            ['today', ['headline' => 'Today', 'summary' => 'Released plan.', 'schedule' => [['label' => 'Rounds', 'risk_score' => 0.91]]]],
            ['today', ['headline' => 'Today', 'summary' => 'Released plan.', 'discharge_outlook' => ['internal_priority' => 1]]],
            ['pathway', ['headline' => 'My Path', 'summary' => 'Released path.', 'stages' => [['title' => 'Monitoring', 'staff_note' => 'private']]]],
            ['pathway', ['headline' => 'My Path', 'summary' => 'Released path.', 'milestones' => ['a plain string milestone is no longer allowed']]],
            ['pathway', ['headline' => 'My Path', 'summary' => 'Released path.', 'milestones' => [['milestone_uuid' => (string) Str::uuid(), 'title' => 'Bad status', 'status' => 'bogus']]]],
            ['pathway', ['headline' => 'My Path', 'summary' => 'Released path.', 'milestones' => [['milestone_uuid' => (string) Str::uuid(), 'title' => 'Leaks a note', 'status' => 'current', 'staff_note' => 'private']]]],
            ['pathway_events', ['headline' => 'Timeline', 'summary' => 'Released.', 'events' => ['a plain string event is not allowed']]],
            ['pathway_events', ['headline' => 'Timeline', 'summary' => 'Released.', 'events' => [['event_uuid' => (string) Str::uuid(), 'title' => 'Bad status', 'when' => 'Today', 'status' => 'bogus']]]],
            ['pathway_events', ['headline' => 'Timeline', 'summary' => 'Released.', 'events' => [['event_uuid' => (string) Str::uuid(), 'title' => 'Bad category', 'when' => 'Today', 'category' => 'private', 'status' => 'planned']]]],
            ['discharge_readiness', ['headline' => 'Discharge', 'summary' => 'Released.', 'criteria' => [['item_uuid' => (string) Str::uuid(), 'label' => 'Bad', 'status' => 'bogus']]]],
            ['discharge_readiness', ['headline' => 'Discharge', 'summary' => 'Released.', 'medications' => [['item_uuid' => (string) Str::uuid(), 'name' => 'Med', 'unreleased_result' => 'leak']]]],
            ['rounds_summary', ['headline' => 'Rounds', 'summary' => 'Released.', 'topics' => ['A plain string topic is not allowed']]],
            ['rounds_summary', ['headline' => 'Rounds', 'summary' => 'Released.', 'topics' => [['topic_uuid' => (string) Str::uuid(), 'title' => 'Bad status', 'summary' => 'Not safe.', 'status' => 'private']]]],
            ['rounds_summary', ['headline' => 'Rounds', 'summary' => 'Released.', 'topics' => [['topic_uuid' => (string) Str::uuid(), 'title' => 'Leaks a note', 'summary' => 'Not safe.', 'status' => 'current', 'staff_note' => 'private']]]],
            ['pathway', ['headline' => 'My Path', 'summary' => 'Raw Encounter/12345 source value']],
            ['care_team', ['headline' => 'Care Team', 'summary' => 'Released team.', 'members' => [['display_name' => 'Clinician', 'private_schedule' => '07:00']]]],
            ['care_team', ['headline' => 'Care Team', 'summary' => 'Released team.', 'members' => [['display_name' => 'Clinician', 'communication_options' => ['request_non_urgent_message']]]]],
            ['care_team', ['headline' => 'Care Team', 'summary' => 'Released team.', 'coverage' => ['staffing' => 0.8]]],
            ['today', ['headline' => 'Today', 'summary' => 'MRN: 123456']],
        ];

        foreach ($invalid as [$kind, $content]) {
            try {
                $guard->assertSafe($kind, $content, $provenance, $uncertainty, ['self']);
                $this->fail("{$kind} prohibited content unexpectedly passed the release guard.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $validToday = [
            'headline' => 'Today',
            'summary' => 'Your released plan for today.',
        ];
        $invalidMetadata = [
            [['projection_method' => ['staff_note' => 'private']] + $provenance, $uncertainty],
            [array_merge($provenance, ['input_classes' => [['staff_note' => 'private']]]), $uncertainty],
            [$provenance, array_merge($uncertainty, ['explanation' => ['staff_note' => 'private']])],
            [$provenance, array_merge($uncertainty, ['can_change' => 'true'])],
            [$provenance, array_merge($uncertainty, ['reviewed_at' => 'tomorrow morning'])],
        ];
        foreach ($invalidMetadata as [$badProvenance, $badUncertainty]) {
            try {
                $guard->assertSafe('today', $validToday, $badProvenance, $badUncertainty, ['self']);
                $this->fail('Invalid provenance or uncertainty unexpectedly passed the release guard.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_projection_digest_is_canonical_and_caller_supplied_mismatch_is_rejected(): void
    {
        $guard = $this->app->make(PatientProjectionContentGuard::class);
        $this->assertSame(
            $guard->digest('today', 'patient-today.v1', ['summary' => 'Safe', 'headline' => 'Today']),
            $guard->digest('today', 'patient-today.v1', ['headline' => 'Today', 'summary' => 'Safe']),
        );

        $fixture = $this->app->make(SyntheticPatientProjectionProvisioner::class)
            ->provision('digest-mismatch');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('patient_projection_content_digest_mismatch');

        PatientEncounterProjection::query()->create([
            'projection_uuid' => (string) Str::uuid(),
            'access_grant_id' => $fixture['grant']->getKey(),
            'release_policy_version_id' => $fixture['policy']->getKey(),
            'projection_kind' => 'today',
            'projection_sequence' => 2,
            'content' => [
                'headline' => 'Safe content',
                'summary' => 'Safe released summary.',
            ],
            'content_schema_version' => 'patient-today.v1',
            'content_digest' => str_repeat('a', 64),
            'source_version' => 'synthetic-v2',
            'provenance' => [
                'projection_method' => 'automated_test',
                'source_class' => 'synthetic_clinical_record',
                'input_classes' => ['synthetic_care_plan'],
                'review_state' => 'automated_test_only',
                'producer_version' => 'test-v1',
            ],
            'source_observed_at' => now()->subMinutes(2),
            'generated_at' => now()->subMinute(),
            'released_at' => now()->subSecond(),
            'freshness_class' => 'current',
            'uncertainty' => [
                'level' => 'low',
                'explanation' => 'Plans can change.',
                'can_change' => true,
                'reviewed_at' => now()->toISOString(),
            ],
            'required_scope' => 'today:read',
            'permitted_relationships' => ['self'],
            'release_state' => 'released',
        ]);
    }

    public function test_projection_governance_models_reject_in_place_mutation(): void
    {
        $fixture = $this->app->make(SyntheticPatientProjectionProvisioner::class)
            ->provision('append-only-models');
        $target = $fixture['projections']['today'];
        $action = PatientContentAction::query()->create([
            'action_uuid' => (string) Str::uuid(),
            'target_projection_id' => $target->getKey(),
            'release_policy_version_id' => $fixture['policy']->getKey(),
            'action_type' => 'retraction',
            'reason_code' => 'automated_test',
            'effective_at' => now(),
        ]);
        $failure = PatientProjectionFailure::query()->create([
            'failure_uuid' => (string) Str::uuid(),
            'source_system_key' => 'synthetic-test-only',
            'projection_kind' => 'today',
            'failure_code' => 'automated_test_failure',
            'retryability' => 'manual_review',
            'attempt_number' => 1,
            'occurred_at' => now(),
            'context' => ['fixture' => true],
        ]);

        foreach ([
            [$fixture['policy'], ['status' => 'draft']],
            [$target, ['freshness_class' => 'stale']],
            [$action, ['reason_code' => 'changed_reason']],
            [$failure, ['failure_code' => 'changed_failure']],
        ] as [$model, $changes]) {
            try {
                $model->forceFill($changes)->save();
                $this->fail($model::class.' unexpectedly permitted an update.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_database_trigger_rejects_projection_update(): void
    {
        $fixture = $this->app->make(SyntheticPatientProjectionProvisioner::class)
            ->provision('append-only-database');

        $this->expectException(QueryException::class);
        DB::table('patient_experience.encounter_projections')
            ->where('encounter_projection_id', $fixture['projections']['today']->getKey())
            ->update(['freshness_class' => 'stale']);
    }

    public function test_database_unique_target_prevents_concurrent_duplicate_terminal_actions(): void
    {
        $fixture = $this->app->make(SyntheticPatientProjectionProvisioner::class)
            ->provision('content-action-race');
        $target = $fixture['projections']['today'];
        PatientContentAction::query()->create([
            'action_uuid' => (string) Str::uuid(),
            'target_projection_id' => $target->getKey(),
            'release_policy_version_id' => $fixture['policy']->getKey(),
            'action_type' => 'retraction',
            'reason_code' => 'first_terminal_action',
            'effective_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('patient_experience.content_actions')->insert([
            'action_uuid' => (string) Str::uuid(),
            'target_projection_id' => $target->getKey(),
            'release_policy_version_id' => $fixture['policy']->getKey(),
            'action_type' => 'retraction',
            'reason_code' => 'racing_terminal_action',
            'actor_type' => 'system',
            'effective_at' => now(),
            'recorded_at' => now(),
        ]);
    }
}
