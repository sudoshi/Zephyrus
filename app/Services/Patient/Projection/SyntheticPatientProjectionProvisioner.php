<?php

namespace App\Services\Patient\Projection;

use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientEncounterProjection;
use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientProjectionCursor;
use App\Models\Patient\PatientReleasePolicyVersion;
use App\Services\Patient\PatientHmac;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Deterministic synthetic fixture for local and automated verification only.
 * It has no path to production source tables and refuses every other runtime.
 */
class SyntheticPatientProjectionProvisioner
{
    public function __construct(private readonly PatientHmac $hmac) {}

    /**
     * @return array{
     *   principal: PatientPrincipal,
     *   grant: PatientEncounterAccessGrant,
     *   policy: PatientReleasePolicyVersion,
     *   projections: array<string, PatientEncounterProjection>
     * }
     */
    public function provision(string $seed = 'reference-patient-projection'): array
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('synthetic_patient_projection_provisioning_forbidden');
        }

        $recordedAt = Carbon::parse('2026-01-15T12:00:00Z');
        $policyVersion = 'patient-disclosure-v1-test';

        $principal = PatientPrincipal::query()->firstOrCreate(
            ['principal_uuid' => $this->uuid($seed.'/principal')],
            [
                'principal_type' => 'patient',
                'display_name' => 'Synthetic Reference Patient',
                'email' => 'synthetic.patient+'.substr(hash('sha256', $seed), 0, 12).'@example.test',
                'status' => 'active',
                'is_active' => true,
                'locale' => 'en-US',
                'timezone' => 'America/New_York',
            ],
        );

        $policy = PatientReleasePolicyVersion::query()->firstOrCreate(
            ['version' => $policyVersion],
            [
                'policy_uuid' => $this->uuid('shared/patient-disclosure-v1-test'),
                'status' => 'active',
                'disclosure_matrix_version' => 'patient-disclosure-matrix.v1-test',
                'content_contract_version' => 'patient-projection.v1',
                'rules' => ['fixture' => true],
                'approved_by_actor_ref' => 'synthetic-governance-fixture',
                'approved_at' => $recordedAt,
                'effective_from' => $recordedAt,
            ],
        );

        $grant = PatientEncounterAccessGrant::query()->firstOrCreate(
            ['grant_uuid' => $this->uuid($seed.'/grant')],
            [
                'principal_id' => $principal->getKey(),
                'encounter_uuid' => $this->uuid($seed.'/encounter'),
                'source_encounter_ref_digest' => $this->hmac->digest('synthetic-encounter-ref', $seed),
                'source_system_key' => 'synthetic-test-only',
                'relationship' => 'self',
                'scopes' => ['today:read', 'pathway:read', 'care_team:read'],
                'purpose_of_use' => 'patient_access',
                'status' => 'active',
                'valid_from' => $recordedAt,
                'issued_by_actor_type' => 'system',
                'issued_by_actor_ref' => 'synthetic-fixture',
                'grant_reason' => 'Deterministic automated projection test fixture.',
                'version' => 1,
                'metadata' => ['fixture' => true],
            ],
        );

        $projections = [];
        foreach (['today', 'pathway', 'pathway_events', 'discharge_readiness', 'rounds_summary', 'care_team'] as $kind) {
            $cursor = PatientProjectionCursor::query()->firstOrCreate(
                ['cursor_uuid' => $this->uuid($seed.'/cursor/'.$kind)],
                [
                    'source_system_key' => 'synthetic-test-only',
                    'projection_kind' => $kind,
                    'cursor_digest' => $this->hmac->digest('synthetic-projection-cursor', $seed.'|'.$kind),
                    'source_version' => 'synthetic-v1',
                    'status' => 'projected',
                    'source_observed_at' => $recordedAt,
                    'projected_at' => $recordedAt->addMinute(),
                    'metadata' => ['fixture' => true],
                ],
            );

            $projections[$kind] = PatientEncounterProjection::query()->firstOrCreate(
                ['projection_uuid' => $this->uuid($seed.'/projection/'.$kind)],
                [
                    'access_grant_id' => $grant->getKey(),
                    'release_policy_version_id' => $policy->getKey(),
                    'projection_cursor_id' => $cursor->getKey(),
                    'projection_kind' => $kind,
                    'projection_sequence' => 1,
                    'content' => $this->content($seed, $kind),
                    'content_schema_version' => 'patient-'.$kind.'.v1',
                    'source_version' => 'synthetic-v1',
                    'provenance' => [
                        'projection_method' => 'deterministic_fixture',
                        'source_class' => 'synthetic_clinical_record',
                        'input_classes' => ['synthetic_schedule', 'synthetic_care_plan'],
                        'review_state' => 'automated_test_only',
                        'producer_version' => 'fixture-v1',
                        'trace_digest' => $this->hmac->digest('synthetic-projection-trace', $seed.'|'.$kind),
                    ],
                    'source_observed_at' => $recordedAt,
                    'generated_at' => $recordedAt->addMinute(),
                    'released_at' => $recordedAt->addMinutes(2),
                    'freshness_class' => 'current',
                    'uncertainty' => [
                        'level' => 'low',
                        'explanation' => 'Plans can change as your care needs change.',
                        'can_change' => true,
                        'reviewed_at' => $recordedAt->toISOString(),
                    ],
                    'required_scope' => PatientProjectionDisclosureService::REQUIRED_SCOPES[$kind],
                    'permitted_relationships' => ['self'],
                    'release_state' => 'released',
                ],
            );
        }

        return compact('principal', 'grant', 'policy', 'projections');
    }

    /** @return array<string, mixed> */
    private function content(string $seed, string $kind): array
    {
        return match ($kind) {
            'today' => [
                'headline' => 'Your plan for today',
                'summary' => 'Your care team is reviewing progress and preparing the next steps.',
                'schedule' => [[
                    'item_uuid' => $this->uuid($seed.'/today/rounds'),
                    'label' => 'Care team rounds',
                    'status' => 'planned',
                    'time_window' => 'This morning',
                    'timing_confidence' => 'estimated',
                    'can_change' => true,
                ]],
                'next_steps' => ['Ask questions during rounds or speak with bedside staff.'],
                'notices' => ['For urgent help, use your call button or speak with bedside staff.'],
            ],
            'pathway' => [
                'headline' => 'My Path',
                'summary' => 'This pathway explains the expected sequence of your hospital stay. Timing can change as your care needs change.',
                'current_stage' => 'Monitoring and treatment',
                'stages' => [
                    [
                        'stage_uuid' => $this->uuid($seed.'/pathway/stage/admission'),
                        'title' => 'Arriving and getting settled',
                        'status' => 'completed',
                        'summary' => 'You were admitted and your care team reviewed your history.',
                        'can_change' => false,
                    ],
                    [
                        'stage_uuid' => $this->uuid($seed.'/pathway/stage/diagnosis'),
                        'title' => 'Understanding what is going on',
                        'status' => 'completed',
                        'summary' => 'Your team ran initial tests and started treatment.',
                        'can_change' => false,
                    ],
                    [
                        'stage_uuid' => $this->uuid($seed.'/pathway/stage/monitoring'),
                        'title' => 'Monitoring and treatment',
                        'status' => 'current',
                        'summary' => 'Your team is checking how you respond to treatment.',
                        'expected_range' => 'The next day or two',
                        'timing_confidence' => 'estimated',
                        'can_change' => true,
                    ],
                    [
                        'stage_uuid' => $this->uuid($seed.'/pathway/stage/discharge'),
                        'title' => 'Preparing to leave the hospital',
                        'status' => 'planned',
                        'summary' => 'When you are ready, your team will help you plan a safe discharge.',
                        'timing_confidence' => 'unknown',
                        'can_change' => true,
                    ],
                ],
                'milestones' => [
                    [
                        'milestone_uuid' => $this->uuid($seed.'/pathway/milestone/settled'),
                        'title' => 'Admitted and settled into your room',
                        'status' => 'completed',
                        'detail' => 'Your care team introduced themselves and reviewed your plan.',
                        'can_change' => false,
                    ],
                    [
                        'milestone_uuid' => $this->uuid($seed.'/pathway/milestone/responding'),
                        'title' => 'Responding to treatment',
                        'status' => 'current',
                        'detail' => 'Your team is watching how your symptoms improve.',
                        'timing' => 'Being checked each day',
                        'timing_confidence' => 'estimated',
                        'can_change' => true,
                    ],
                    [
                        'milestone_uuid' => $this->uuid($seed.'/pathway/milestone/discharge-plan'),
                        'title' => 'A safe plan for leaving the hospital',
                        'status' => 'planned',
                        'detail' => 'Includes medicines, follow-up, and who to contact.',
                        'timing_confidence' => 'unknown',
                        'can_change' => true,
                    ],
                ],
                'goals' => [
                    [
                        'goal_uuid' => $this->uuid($seed.'/pathway/goal/mobility'),
                        'author_type' => 'care_team',
                        'label' => 'Stay active and moving each day',
                        'explanation' => 'Gentle movement helps recovery and lowers the risk of complications.',
                        'status' => 'in_progress',
                    ],
                    [
                        'goal_uuid' => $this->uuid($seed.'/pathway/goal/medications'),
                        'author_type' => 'patient',
                        'label' => 'Understand my medicines before I go home',
                        'status' => 'planned',
                    ],
                ],
                'education' => [
                    [
                        'item_uuid' => $this->uuid($seed.'/pathway/education/treatment'),
                        'title' => 'Understanding your treatment',
                        'summary' => 'A plain-language overview of what your treatment does and what to expect.',
                    ],
                ],
                'notices' => ['Expected timing is an estimate, not a promise.'],
            ],
            'pathway_events' => [
                'headline' => 'What has happened so far',
                'summary' => 'A simple timeline of key moments in your hospital stay. Timing is approximate.',
                'events' => [
                    [
                        'event_uuid' => $this->uuid($seed.'/pathway-events/admitted'),
                        'title' => 'Admitted to the hospital',
                        'when' => 'Two days ago',
                        'category' => 'other',
                        'status' => 'completed',
                        'detail' => 'Your care team reviewed your history and started your plan.',
                    ],
                    [
                        'event_uuid' => $this->uuid($seed.'/pathway-events/treatment-started'),
                        'title' => 'Initial tests completed',
                        'when' => 'Two days ago',
                        'category' => 'test',
                        'status' => 'completed',
                    ],
                    [
                        'event_uuid' => $this->uuid($seed.'/pathway-events/procedure-preparation'),
                        'title' => 'Preparing for a bedside procedure',
                        'when' => 'Today',
                        'category' => 'procedure',
                        'status' => 'current',
                        'detail' => 'Your team will explain what to expect and how to prepare before it happens.',
                    ],
                    [
                        'event_uuid' => $this->uuid($seed.'/pathway-events/transport-planning'),
                        'title' => 'Planning transportation after you leave',
                        'when' => 'When you are ready',
                        'category' => 'transport',
                        'status' => 'planned',
                        'detail' => 'Your team will confirm whether you need a ride or other transportation support.',
                    ],
                ],
                'notices' => ['This timeline is a summary and may not include every detail.'],
            ],
            'discharge_readiness' => [
                'headline' => 'Getting ready to leave',
                'summary' => 'What still needs to happen before you can safely go home. Timing can change.',
                'estimated_range' => 'In the next day or two',
                'estimated_confidence' => 'estimated',
                'criteria' => [
                    [
                        'item_uuid' => $this->uuid($seed.'/discharge/criteria/pain'),
                        'label' => 'Comfortable with your pain plan',
                        'status' => 'met',
                    ],
                    [
                        'item_uuid' => $this->uuid($seed.'/discharge/criteria/mobility'),
                        'label' => 'Moving safely on your own or with help',
                        'status' => 'pending',
                        'detail' => 'Your team will check this with you each day.',
                    ],
                ],
                'unresolved_needs' => ['A ride home arranged for the day you leave.'],
                'medications' => [
                    [
                        'item_uuid' => $this->uuid($seed.'/discharge/med/home'),
                        'name' => 'Your updated home medicine list',
                        'purpose' => 'Your team will review each medicine with you before you go.',
                    ],
                ],
                'follow_up' => [
                    [
                        'item_uuid' => $this->uuid($seed.'/discharge/followup/clinic'),
                        'label' => 'Follow-up visit with your care team',
                        'when' => 'Within a week or two of leaving',
                    ],
                ],
                'warning_signs' => ['Call your care team if symptoms get worse after you go home.'],
                'contacts' => [
                    [
                        'item_uuid' => $this->uuid($seed.'/discharge/contact/team'),
                        'label' => 'Your care team',
                        'route' => 'speak_with_bedside_staff',
                    ],
                ],
                'notices' => ['This is a summary; your team will confirm the details before you leave.'],
            ],
            'rounds_summary' => [
                'headline' => 'Your care-team conversation',
                'summary' => 'A plain-language summary your team released after reviewing your care. It is not a complete clinical record.',
                'round_window' => 'Earlier today',
                'topics' => [
                    [
                        'topic_uuid' => $this->uuid($seed.'/rounds/topic/progress'),
                        'title' => 'How you are feeling and responding to care',
                        'summary' => 'Your team reviewed how you are doing and will keep checking your progress.',
                        'status' => 'current',
                    ],
                    [
                        'topic_uuid' => $this->uuid($seed.'/rounds/topic/mobility'),
                        'title' => 'Moving safely',
                        'summary' => 'Your team plans to support safe movement as you regain strength.',
                        'status' => 'planned',
                    ],
                ],
                'next_steps' => ['Tell your bedside team what you would like explained or what matters most to you today.'],
                'questions' => ['Use Messages for non-urgent questions, or speak with bedside staff sooner if you need help.'],
                'notices' => ['This summary can change after your team reassesses you. It does not replace a conversation with your care team.'],
            ],
            'care_team' => [
                'headline' => 'Your care team',
                'summary' => 'These roles are involved in your care today.',
                'members' => [[
                    'member_uuid' => $this->uuid($seed.'/care-team/coordinator'),
                    'display_name' => 'Care Coordinator',
                    'role' => 'Care coordination',
                    'responsibilities' => ['Helps coordinate your care plan and questions.'],
                    'contact_route' => 'speak_with_bedside_staff',
                ]],
                'communication_options' => ['speak_with_bedside_staff', 'call_button_for_urgent_help'],
                'notices' => ['Messages are not monitored for emergencies.'],
            ],
            default => throw new RuntimeException('unsupported_synthetic_projection_kind'),
        };
    }

    private function uuid(string $name): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'https://zephyrus.example.test/'.$name)->toString();
    }
}
