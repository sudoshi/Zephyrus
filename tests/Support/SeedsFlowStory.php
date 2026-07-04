<?php

namespace Tests\Support;

use App\Models\Barrier;
use App\Models\Bed;
use App\Models\Encounter;
use App\Models\Evs\EvsRequest;
use App\Models\OperationalEvent;
use App\Models\RtdcPrediction;
use App\Models\Transport\TransportRequest;
use App\Models\Unit;
use Illuminate\Support\Str;

/**
 * One coherent operational story across both halves of the Flow Window,
 * shared by the window feature tests and the shared-fixture regenerator
 * (FLOW_FIXTURE_DUMP=1) so the contract fixtures are captured, not typed.
 */
trait SeedsFlowStory
{
    /** @var list<string> */
    protected array $flowStoryPatientRefs = ['FLOWTEST-PAT-EDD', 'FLOWTEST-PAT-TRIP', 'FLOWTEST-PAT-TURN'];

    protected function seedFlowStory(): void
    {
        $micu = Unit::where('abbreviation', 'MICU')->firstOrFail();
        $bed = Bed::where('unit_id', $micu->unit_id)->orderBy('bed_id')->firstOrFail();
        $bed->update(['status' => 'occupied']);

        // An EDD-flagged encounter (the expected_discharge + derived ghosts source).
        Encounter::create([
            'patient_ref' => 'FLOWTEST-PAT-EDD',
            'unit_id' => $micu->unit_id,
            'bed_id' => $bed->bed_id,
            'admitted_at' => now()->subDays(3),
            'expected_discharge_date' => now()->toDateString(),
            'acuity_tier' => 2,
            'status' => 'active',
        ]);

        // Review half: an admit event 6h ago and an open barrier 4h ago.
        OperationalEvent::create([
            'event_id' => (string) Str::uuid(),
            'type' => 'EncounterStarted',
            'encounter_ref' => 'FLOWTEST-PAT-EDD',
            'payload' => ['unit_id' => $micu->unit_id, 'bed_id' => $bed->bed_id, 'acuity_tier' => 2],
            'occurred_at' => now()->subHours(6),
        ]);
        Barrier::create([
            'unit_id' => $micu->unit_id,
            'category' => 'logistical',
            'description' => 'Awaiting ride',
            'status' => 'open',
            'opened_at' => now()->subHours(4),
        ]);

        // Prediction vocabulary so the EDD projection has confidence tiers.
        RtdcPrediction::create([
            'unit_id' => $micu->unit_id,
            'service_date' => now()->toDateString(),
            'horizon' => 'by_2pm',
            'discharges_definite' => 1,
            'discharges_probable' => 1,
            'discharges_possible' => 1,
            'discharges_weighted' => 2.0,
            'demand_ed' => 2,
            'demand_or' => 0,
            'demand_transfer' => 0,
            'demand_direct' => 0,
            'demand_expected' => 2,
            'capacity_now' => 3,
            'bed_need' => 0,
            'status' => 'open',
        ]);

        // Prediction half: a trip due in 2h and a turn due in 1h.
        TransportRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'inpatient',
            'priority' => 'routine',
            'status' => 'queued',
            'patient_ref' => 'FLOWTEST-PAT-TRIP',
            'origin' => 'MICU',
            'destination' => 'Imaging',
            'transport_mode' => 'wheelchair',
            'needed_at' => now()->addHours(2),
        ]);
        EvsRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'bed_clean',
            'priority' => 'routine',
            'status' => 'queued',
            'unit_id' => $micu->unit_id,
            'bed_id' => $bed->bed_id,
            'patient_ref' => 'FLOWTEST-PAT-TURN',
            'location_label' => $bed->label,
            'turn_type' => 'standard',
            'needed_at' => now()->addHour(),
        ]);
    }
}
