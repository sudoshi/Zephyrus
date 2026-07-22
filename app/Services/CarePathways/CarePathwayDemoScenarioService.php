<?php

namespace App\Services\CarePathways;

use Illuminate\Support\Facades\DB;
use Throwable;

final class CarePathwayDemoScenarioService
{
    public const SCENARIO_KEY = 'heart-failure-awareness-demo-v1';

    public const MAX_STEP = 5;

    /**
     * Build a read-only, synthetic journey over the governed catalog.
     *
     * @param  array<string, mixed>|null  $catalogOverride  Test/demo override; never accepted from HTTP input.
     * @return array<string, mixed>
     */
    public function scenario(int $step = 0, ?array $catalogOverride = null): array
    {
        $step = max(0, min(self::MAX_STEP, $step));
        $catalog = $catalogOverride ?? $this->catalogSnapshot();
        $steps = $this->steps($step);

        return [
            'meta' => [
                'schema' => 'care_pathway_demo.v1',
                'scenario_key' => self::SCENARIO_KEY,
                'title' => 'Heart Failure: Admission to Supported Transition',
                'current_step' => $step,
                'max_step' => self::MAX_STEP,
                'synthetic' => true,
                'read_only' => true,
                'clinical_use' => false,
                'generated_at' => now()->toIso8601String(),
                'warning' => 'Simulation only. No pathway is clinically activated, no patient record is changed, and no clinical action is executed.',
            ],
            'steps' => $steps,
            'catalog' => $catalog,
            'subject' => [
                'display_name' => 'Jordan Lee',
                'synthetic_label' => 'Synthetic adult inpatient',
                'context_ref' => 'ptok_d3e0100f5a1e4c7b88f00123',
                'location' => '6 East · Room 612',
                'encounter_day' => $step < 2 ? 'Admission' : ($step < 5 ? 'Hospital day 2' : 'Transition day'),
                'working_problem' => 'Heart failure with fluid overload',
                'service' => 'Hospital Medicine · Cardiology consulting',
                'privacy' => 'Fictional identity and scenario data; no production patient identifiers.',
            ],
            'care_team' => $this->careTeamProjection($step),
            'virtual_rounds' => $this->roundsProjection($step),
            'hummingbird_staff' => $this->staffProjection($step),
            'hummingbird_patient' => $this->patientProjection($step),
            'eddy' => $this->eddyProjection($step),
            'governance' => $this->governanceProjection($catalog),
            'timeline' => $this->timeline($step),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function steps(int $current): array
    {
        $definitions = [
            ['key' => 'evidence', 'label' => 'Evidence review', 'summary' => 'Inspect provenance and activation blockers.'],
            ['key' => 'candidate', 'label' => 'Candidate match', 'summary' => 'Explain why the pathway may apply.'],
            ['key' => 'confirm', 'label' => 'Clinician confirm', 'summary' => 'Confirm a sandbox-only encounter instance.'],
            ['key' => 'rounds', 'label' => 'Coordinate rounds', 'summary' => 'Share milestones, barriers, roles, and questions.'],
            ['key' => 'awareness', 'label' => 'Patient awareness', 'summary' => 'Release plain-language synthetic projections.'],
            ['key' => 'transition', 'label' => 'Supported transition', 'summary' => 'Reconcile readiness and close the simulation.'],
        ];

        return array_map(
            fn (array $definition, int $index): array => $definition + [
                'index' => $index,
                'state' => $index < $current ? 'complete' : ($index === $current ? 'current' : 'upcoming'),
            ],
            $definitions,
            array_keys($definitions),
        );
    }

    /** @return array<string, mixed> */
    private function careTeamProjection(int $step): array
    {
        $assignmentStates = ['not_started', 'candidate', 'confirmed', 'active', 'active', 'completed'];
        $stageLabels = [
            'Governance review',
            'Admission assessment',
            'Stabilization',
            'Coordinated recovery',
            'Transition planning',
            'Supported transition',
        ];

        return [
            'surface' => 'Zephyrus Encounter Pathway Workspace',
            'visible' => true,
            'assignment' => [
                'status' => $assignmentStates[$step],
                'pathway' => 'Heart Failure',
                'version' => '43.1-source.1 · demo overlay 1.0',
                'current_stage' => $stageLabels[$step],
                'confidence' => $step === 1 ? 'medium' : ($step >= 2 ? 'clinician_confirmed_demo' : 'not_evaluated'),
                'requires_confirmation' => $step < 2,
                'matched' => $step >= 1 ? ['working problem', 'inpatient setting', 'cardiology involvement'] : [],
                'conflicts' => $step === 1 ? ['final DRG unavailable', 'local exclusion review pending'] : [],
                'decision_record' => $step >= 2
                    ? 'Confirmed by Dr. Avery Chen for simulation only; no order or diagnosis was created.'
                    : 'No encounter pathway has been confirmed.',
            ],
            'milestones' => [
                $this->milestone('Admission assessment complete', 'Hospitalist', 2, $step),
                $this->milestone('Medication history reconciled', 'Pharmacist', 3, $step),
                $this->milestone('Mobility and home-support review', 'Nurse / PT', 4, $step),
                $this->milestone('Follow-up plan reviewed with patient', 'Care manager', 5, $step),
            ],
            'next_decisions' => match ($step) {
                0 => ['Select a pilot pathway for local multidisciplinary review.'],
                1 => ['Confirm applicability or reject with a reason.', 'Resolve missing local exclusion data.'],
                2 => ['Assign accountable milestone owners.', 'Bring open questions into rounds.'],
                3 => ['Resolve the transportation barrier.', 'Answer the patient medication question.'],
                4 => ['Record observed teach-back without claiming comprehension.', 'Finalize follow-up ownership.'],
                default => ['Reconcile completed milestones.', 'Close the synthetic instance with an audit event.'],
            },
            'barriers' => [[
                'label' => 'Transportation to follow-up',
                'status' => $step >= 5 ? 'resolved' : ($step >= 3 ? 'owner_assigned' : 'not_yet_assessed'),
                'owner' => $step >= 3 ? 'Care management' : null,
            ]],
            'patient_awareness' => [
                'plain_language_projection_released' => $step >= 4,
                'question_waiting' => $step >= 3 && $step < 5,
                'teach_back_observation' => $step >= 5 ? 'Patient explained the follow-up plan in their own words; clinician review recorded.' : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function roundsProjection(int $step): array
    {
        return [
            'surface' => 'Virtual Rounds and 4D pathway layer',
            'visible' => $step >= 3,
            'queue_status' => $step >= 5 ? 'complete' : ($step >= 3 ? 'ready_with_barrier' : 'not_started'),
            'pathway_badge' => [
                'stage' => $step >= 5 ? 'Supported transition' : 'Coordinated recovery',
                'variance' => $step >= 3 && $step < 5 ? 'transportation barrier' : null,
                'patient_question_count' => $step >= 3 && $step < 5 ? 1 : 0,
            ],
            'role_inputs' => [
                ['role' => 'Nursing', 'state' => $step >= 3 ? 'submitted' : 'pending', 'summary' => 'Daily-weight teaching introduced; mobility improving.'],
                ['role' => 'Pharmacy', 'state' => $step >= 4 ? 'submitted' : 'pending', 'summary' => 'Medication list reviewed; patient asks which medicines changed.'],
                ['role' => 'Care management', 'state' => $step >= 5 ? 'submitted' : ($step >= 3 ? 'needs_action' : 'pending'), 'summary' => 'Transportation plan secured for follow-up.'],
            ],
            'open_question' => $step >= 3 && $step < 5
                ? 'Which medicines are new, and what should I watch for at home?'
                : null,
            'four_d_payload' => [
                'context_ref' => 'ptok_d3e0100f5a1e4c7b88f00123',
                'stage_code' => $step >= 5 ? 'transition' : 'recovery',
                'status_code' => $step >= 5 ? 'complete' : 'attention',
                'contains_narrative_or_identifiers' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function staffProjection(int $step): array
    {
        return [
            'surface' => 'Hummingbird Staff',
            'visible' => $step >= 3,
            'context_ref' => 'ptok_d3e0100f5a1e4c7b88f00123',
            'for_you' => [
                'priority' => $step >= 5 ? 'resolved' : 'action_due',
                'title' => $step >= 5 ? 'Transition plan complete' : 'Pathway question waiting',
                'detail' => $step >= 5
                    ? 'Follow-up and transportation are reconciled in the demo.'
                    : 'Jordan has a medication question for the accountable team.',
                'deep_link' => '/care-pathways/demo?surface=hummingbird-staff',
            ],
            'patient_context' => [
                'current_stage' => $step >= 5 ? 'Supported transition' : 'Coordinated recovery',
                'next_owner' => $step >= 5 ? 'Primary care follow-up' : 'Pharmacy',
                'milestones_complete' => min(4, max(0, $step - 1)),
                'milestones_total' => 4,
                'raw_patient_identifier_present' => false,
            ],
            'notification' => [
                'doorbell_only' => true,
                'contains_phi' => false,
                'message' => 'A care-pathway item needs review in Hummingbird.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function patientProjection(int $step): array
    {
        return [
            'surface' => 'Hummingbird Patient',
            'visible' => $step >= 4,
            'release_boundary' => 'Synthetic patient projection; never sourced directly from raw pathway prose.',
            'headline' => 'Your plan for today',
            'why_here' => 'Your care team is helping your body remove extra fluid and preparing a safe plan for home.',
            'today' => [
                ['label' => 'Review your medicines with the pharmacist', 'state' => $step >= 5 ? 'done' : 'today'],
                ['label' => 'Practice the daily check-in plan', 'state' => $step >= 5 ? 'done' : 'today'],
                ['label' => 'Confirm transportation for follow-up', 'state' => $step >= 5 ? 'done' : 'needs_help'],
            ],
            'goals' => [
                ['author' => 'patient', 'text' => 'I want to feel confident about what to do when I get home.'],
                ['author' => 'care_team', 'text' => 'Leave with a reviewed medication and follow-up plan.'],
            ],
            'question' => [
                'text' => 'Which medicines are new, and what should I watch for at home?',
                'status' => $step >= 5 ? 'answered_in_person' : 'sent_to_care_team',
            ],
            'urgent_help' => 'This demo does not provide medical advice. In a real deployment, urgent symptoms route to bedside or emergency instructions, never asynchronous Eddy chat.',
            'claims_understanding' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function eddyProjection(int $step): array
    {
        return [
            'surface' => 'Eddy staff assistance',
            'visible' => $step >= 3,
            'mode' => 'rounds_preparation',
            'provider_policy' => 'patient_context_local_only',
            'prompt' => 'Prepare a concise rounds gap scan for this synthetic heart-failure pathway instance.',
            'answer' => $step >= 3
                ? 'Draft: medication reconciliation and the transportation barrier need accountable closure. The patient medication question should be answered by the care team. Confirm applicability and local policy before using any pathway recommendation.'
                : null,
            'citations' => [
                [
                    'label' => '2022 AHA/ACC/HFSA Heart Failure Guideline',
                    'reference' => 'PMID 35363499',
                    'scope' => 'Research reference from the verified source package; local approval pending.',
                ],
                [
                    'label' => 'Verification package v43.1',
                    'reference' => 'Heart Failure · rank 6',
                    'scope' => 'Automated evidence verification; not institutional clinical approval.',
                ],
            ],
            'guardrails' => [
                'draft_only' => true,
                'may_diagnose' => false,
                'may_order' => false,
                'may_activate_pathway' => false,
                'cloud_egress_allowed' => false,
                'global_memory_write' => false,
            ],
        ];
    }

    /** @param array<string, mixed> $catalog */
    private function governanceProjection(array $catalog): array
    {
        return [
            'surface' => 'Catalog governance and provenance',
            'release_state' => (string) ($catalog['state'] ?? 'inactive'),
            'institutional_signoff_complete' => false,
            'demo_overlay_state' => 'simulation_only',
            'controls' => [
                'failed' => (int) ($catalog['failed_controls'] ?? 0),
                'residual_unknowns' => (int) ($catalog['residual_unknowns'] ?? 0),
                'patient_copy_locally_approved' => false,
                'eddy_reference_locally_approved' => false,
            ],
            'activation_blockers' => [
                'Multidisciplinary local clinical approval is incomplete.',
                'Patient-language content has not completed local health-literacy review.',
                'Encounter eligibility and exclusion rules are not approved computable logic.',
                'Eddy and Hummingbird production release policies remain disabled.',
            ],
            'separation' => [
                'catalog' => 'Real imported evidence remains inactive and immutable.',
                'demo' => 'Synthetic projections are generated in memory and never persisted as care.',
                'production' => 'Activation still requires the full approval matrix and explicit feature flags.',
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function timeline(int $step): array
    {
        $events = [
            ['step' => 0, 'time' => '08:00', 'actor' => 'Governance', 'event' => 'Verified source package selected for a synthetic pilot preview.'],
            ['step' => 1, 'time' => '08:12', 'actor' => 'Matcher', 'event' => 'Heart Failure returned as an explainable candidate; confirmation required.'],
            ['step' => 2, 'time' => '08:20', 'actor' => 'Hospitalist', 'event' => 'Sandbox-only pathway instance confirmed; no order or diagnosis created.'],
            ['step' => 3, 'time' => '09:05', 'actor' => 'Virtual Rounds', 'event' => 'Barrier, role inputs, and patient question added to the shared view.'],
            ['step' => 4, 'time' => '09:24', 'actor' => 'Projection service', 'event' => 'Synthetic staff and patient awareness projections released.'],
            ['step' => 5, 'time' => '13:40', 'actor' => 'Care team', 'event' => 'Question answered in person, transportation resolved, and demo instance completed.'],
        ];

        return array_values(array_filter($events, fn (array $event): bool => $event['step'] <= $step));
    }

    /** @return array<string, mixed> */
    private function milestone(string $label, string $owner, int $completeAt, int $step): array
    {
        return [
            'label' => $label,
            'owner' => $owner,
            'state' => $step >= $completeAt ? 'complete' : ($step + 1 >= $completeAt ? 'due' : 'upcoming'),
        ];
    }

    /** @return array<string, mixed> */
    private function catalogSnapshot(): array
    {
        try {
            $release = DB::table('care_pathways.catalog_releases')
                ->orderByDesc('catalog_release_id')
                ->first();

            if ($release !== null) {
                $controls = DB::table('care_pathways.catalog_release_controls')
                    ->where('catalog_release_id', $release->catalog_release_id)
                    ->selectRaw("count(*) FILTER (WHERE status = 'failed') AS failed")
                    ->first();
                $residualUnknowns = DB::table('care_pathways.current_completeness_resolutions')
                    ->where('catalog_release_id', $release->catalog_release_id)
                    ->sum('residual_unknown_count');

                return [
                    'source' => 'live_catalog_metadata',
                    'dataset_key' => (string) $release->dataset_key,
                    'grouper_version' => (string) $release->grouper_version,
                    'state' => (string) $release->state,
                    'pathways' => (int) $release->pathway_count,
                    'evidence_verified' => (int) $release->evidence_verified_count,
                    'evidence_limitations' => (int) $release->evidence_limitations_count,
                    'clinical_signoff_count' => (int) $release->clinical_signoff_count,
                    'failed_controls' => (int) ($controls->failed ?? 0),
                    'residual_unknowns' => (int) $residualUnknowns,
                ];
            }
        } catch (Throwable) {
            // The page remains demonstrable in isolated frontend/test environments.
            // This fallback never claims a live-database observation.
        }

        $controls = (array) config('care-pathways.expected_controls', []);

        return [
            'source' => 'configured_verified_release_controls',
            'dataset_key' => (string) config('care-pathways.source_release.dataset_key'),
            'grouper_version' => (string) config('care-pathways.source_release.grouper_version'),
            'state' => 'inactive',
            'pathways' => (int) ($controls['pathways'] ?? 250),
            'evidence_verified' => (int) ($controls['evidence_verified'] ?? 96),
            'evidence_limitations' => (int) ($controls['evidence_limitations'] ?? 154),
            'clinical_signoff_count' => (int) ($controls['clinical_signoff'] ?? 0),
            'failed_controls' => 0,
            'residual_unknowns' => (int) ($controls['residual_unclassified_absence'] ?? 0),
        ];
    }
}
