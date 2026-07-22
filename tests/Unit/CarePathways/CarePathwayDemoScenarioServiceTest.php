<?php

namespace Tests\Unit\CarePathways;

use App\Services\CarePathways\CarePathwayDemoScenarioService;
use Tests\TestCase;

class CarePathwayDemoScenarioServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $catalog = [
        'source' => 'test_catalog',
        'dataset_key' => 'verified-test-release',
        'grouper_version' => '43.1',
        'state' => 'inactive',
        'pathways' => 250,
        'evidence_verified' => 96,
        'evidence_limitations' => 154,
        'clinical_signoff_count' => 0,
        'failed_controls' => 0,
        'residual_unknowns' => 0,
    ];

    public function test_every_step_is_synthetic_read_only_and_non_clinical(): void
    {
        $service = app(CarePathwayDemoScenarioService::class);

        for ($step = 0; $step <= CarePathwayDemoScenarioService::MAX_STEP; $step++) {
            $scenario = $service->scenario($step, $this->catalog);

            $this->assertTrue($scenario['meta']['synthetic']);
            $this->assertTrue($scenario['meta']['read_only']);
            $this->assertFalse($scenario['meta']['clinical_use']);
            $this->assertSame('inactive', $scenario['catalog']['state']);
            $this->assertSame('simulation_only', $scenario['governance']['demo_overlay_state']);
            $this->assertFalse($scenario['governance']['institutional_signoff_complete']);
            $this->assertFalse($scenario['eddy']['guardrails']['may_diagnose']);
            $this->assertFalse($scenario['eddy']['guardrails']['may_order']);
            $this->assertFalse($scenario['eddy']['guardrails']['may_activate_pathway']);
            $this->assertMatchesRegularExpression('/^ptok_[a-f0-9]{24}$/', $scenario['subject']['context_ref']);
        }
    }

    public function test_journey_releases_surfaces_only_at_their_demo_boundaries(): void
    {
        $service = app(CarePathwayDemoScenarioService::class);

        $candidate = $service->scenario(1, $this->catalog);
        $confirmed = $service->scenario(2, $this->catalog);
        $rounds = $service->scenario(3, $this->catalog);
        $awareness = $service->scenario(4, $this->catalog);
        $transition = $service->scenario(5, $this->catalog);

        $this->assertSame('candidate', $candidate['care_team']['assignment']['status']);
        $this->assertTrue($candidate['care_team']['assignment']['requires_confirmation']);
        $this->assertSame('confirmed', $confirmed['care_team']['assignment']['status']);
        $this->assertFalse($confirmed['care_team']['assignment']['requires_confirmation']);

        $this->assertTrue($rounds['virtual_rounds']['visible']);
        $this->assertTrue($rounds['hummingbird_staff']['visible']);
        $this->assertTrue($rounds['eddy']['visible']);
        $this->assertFalse($rounds['hummingbird_patient']['visible']);

        $this->assertTrue($awareness['hummingbird_patient']['visible']);
        $this->assertTrue($awareness['care_team']['patient_awareness']['plain_language_projection_released']);
        $this->assertFalse($awareness['hummingbird_patient']['claims_understanding']);

        $this->assertSame('completed', $transition['care_team']['assignment']['status']);
        $this->assertSame('resolved', $transition['care_team']['barriers'][0]['status']);
        $this->assertSame('answered_in_person', $transition['hummingbird_patient']['question']['status']);
    }

    public function test_step_input_is_clamped_and_payload_contains_every_integration_surface(): void
    {
        $service = app(CarePathwayDemoScenarioService::class);

        $before = $service->scenario(-100, $this->catalog);
        $after = $service->scenario(100, $this->catalog);

        $this->assertSame(0, $before['meta']['current_step']);
        $this->assertSame(CarePathwayDemoScenarioService::MAX_STEP, $after['meta']['current_step']);
        $this->assertCount(CarePathwayDemoScenarioService::MAX_STEP + 1, $after['steps']);

        foreach (['care_team', 'virtual_rounds', 'hummingbird_staff', 'hummingbird_patient', 'eddy', 'governance'] as $surface) {
            $this->assertArrayHasKey($surface, $after);
        }
    }

    public function test_synthetic_mobile_and_scene_contracts_do_not_contain_raw_patient_identifiers(): void
    {
        $scenario = app(CarePathwayDemoScenarioService::class)->scenario(5, $this->catalog);
        $json = json_encode($scenario, JSON_THROW_ON_ERROR);

        $this->assertFalse($scenario['virtual_rounds']['four_d_payload']['contains_narrative_or_identifiers']);
        $this->assertFalse($scenario['hummingbird_staff']['patient_context']['raw_patient_identifier_present']);
        $this->assertFalse($scenario['hummingbird_staff']['notification']['contains_phi']);
        $this->assertStringNotContainsString('medical_record_number', $json);
        $this->assertStringNotContainsString('date_of_birth', $json);
        $this->assertStringNotContainsString('real patient', strtolower($json));
    }
}
