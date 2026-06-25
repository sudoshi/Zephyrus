<?php

namespace Tests\Unit\PatientFlow;

use App\Services\PatientFlow\PatientStateProjector;
use Tests\TestCase;

class PatientStateProjectorTest extends TestCase
{
    public function test_reconstructs_active_state_and_discharge_removes_patient(): void
    {
        $projector = app(PatientStateProjector::class);
        $events = [
            [
                'patient_id' => 'p1',
                'patient_display_id' => 'PT-000001',
                'encounter_id' => 'e1',
                'occurred_at' => '2026-06-25T01:00:00Z',
                'event_type' => 'admit',
                'event_category' => 'movement',
                'to_location' => 'TICU-B001',
                'patient_class' => 'I',
                'service_line' => 'critical_care',
            ],
            [
                'patient_id' => 'p1',
                'patient_display_id' => 'PT-000001',
                'encounter_id' => 'e1',
                'occurred_at' => '2026-06-25T03:00:00Z',
                'event_type' => 'discharge',
                'event_category' => 'movement',
                'to_location' => 'TICU-B001',
            ],
        ];

        $this->assertCount(1, $projector->reconstruct($events, '2026-06-25T02:00:00Z'));
        $this->assertCount(0, $projector->reconstruct($events, '2026-06-25T04:00:00Z'));
    }
}
