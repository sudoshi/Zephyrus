<?php

namespace Tests\Unit\Patient;

use App\Services\Patient\Projection\PatientProjectionContentGuard;
use App\Services\Patient\Projection\PatientProjectionStateVocabulary;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PatientProjectionStateVocabularyTest extends TestCase
{
    public function test_default_registry_keeps_every_projection_state_code_explicit_and_patient_readable(): void
    {
        $registry = require dirname(__DIR__, 3).'/config/hummingbird-patient-content.php';
        $vocabulary = new PatientProjectionStateVocabulary($registry['state_vocabulary']);

        $this->assertSame('patient-state-vocabulary.v1-draft', $registry['state_vocabulary']['version']);
        $this->assertSame('Happening now', $vocabulary->label('stage_status', 'current'));
        $this->assertSame('No longer planned', $vocabulary->label('goal_status', 'canceled'));
        $this->assertSame('Needs attention', $vocabulary->label('discharge_criteria_status', 'at_risk'));
        $this->assertSame('Use your bedside call button for urgent help', $vocabulary->label(
            'contact_route',
            'call_button_for_urgent_help',
        ));
    }

    public function test_registry_rejects_unknown_or_malformed_state_codes(): void
    {
        $registry = require dirname(__DIR__, 3).'/config/hummingbird-patient-content.php';
        $vocabulary = new PatientProjectionStateVocabulary($registry['state_vocabulary']);

        try {
            $vocabulary->assertCode('stage_status', 'internal_triage_hold');
            $this->fail('An unknown internal state code must not be admitted to a patient projection.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('patient_projection_state_code_invalid', $exception->getMessage());
        }

        $invalid = $registry['state_vocabulary'];
        $invalid['locales']['en-US']['stage_status']['bad-code'] = '';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('patient_projection_state_vocabulary_invalid');
        new PatientProjectionStateVocabulary($invalid);
    }

    public function test_content_guard_uses_the_registry_for_released_projection_states(): void
    {
        $registry = require dirname(__DIR__, 3).'/config/hummingbird-patient-content.php';
        $guard = new PatientProjectionContentGuard(
            new PatientProjectionStateVocabulary($registry['state_vocabulary']),
        );
        $content = [
            'headline' => 'My Path',
            'summary' => 'A released summary of the current care pathway.',
            'stages' => [[
                'stage_uuid' => '019f0000-0000-7000-8000-000000000001',
                'title' => 'Monitoring and treatment',
                'status' => 'current',
                'summary' => 'Your care team is checking how you respond to treatment.',
                'can_change' => true,
            ]],
        ];
        $provenance = [
            'projection_method' => 'test_projection',
            'source_class' => 'test_source',
            'input_classes' => ['approved_definition'],
            'review_state' => 'draft',
            'producer_version' => 'test-v1',
        ];
        $uncertainty = [
            'level' => 'medium',
            'explanation' => 'Care plans can change.',
            'can_change' => true,
            'reviewed_at' => '2026-07-22T00:00:00+00:00',
        ];

        $guard->assertSafe('pathway', $content, $provenance, $uncertainty, ['self']);

        $content['stages'][0]['status'] = 'internal_triage_hold';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('patient_projection_state_code_invalid');
        $guard->assertSafe('pathway', $content, $provenance, $uncertainty, ['self']);
    }
}
