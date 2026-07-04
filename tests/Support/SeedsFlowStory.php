<?php

namespace Tests\Support;

use App\Models\Barrier;
use App\Models\Bed;
use App\Models\Encounter;
use App\Models\Evs\EvsEvent;
use App\Models\Evs\EvsRequest;
use App\Models\OperationalEvent;
use App\Models\RtdcPrediction;
use App\Models\Transport\TransportEvent;
use App\Models\Transport\TransportRequest;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One coherent operational story across both halves of the Flow Window,
 * shared by the window feature tests and the shared-fixture regenerator
 * (FLOW_FIXTURE_DUMP=1) so the contract fixtures are captured, not typed.
 *
 * The story: FLOWTEST-PAT-EDD discharges from MICU bed 1 today; EVS turns
 * that bed (isolation); FLOWTEST-PAT-TRIP is inbound from the ED to the
 * same bed label. In periop, one case runs in "OR 3" with in-room /
 * procedure-start milestones already on the clock.
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

        // Prediction half: a trip due in 2h — origin/destination drawn from
        // vocabularies the mobile plates asset can resolve (unit abbreviation
        // and bed label), never free text.
        $trip = TransportRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'inpatient',
            'priority' => 'routine',
            'status' => 'queued',
            'patient_ref' => 'FLOWTEST-PAT-TRIP',
            'origin' => 'ED',
            'destination' => $bed->label,
            'transport_mode' => 'wheelchair',
            'needed_at' => now()->addHours(2),
        ]);

        // Review half: the trip's status transitions inside the past window.
        TransportEvent::create([
            'event_uuid' => (string) Str::uuid(),
            'transport_request_id' => $trip->transport_request_id,
            'event_type' => 'transport.requested',
            'from_status' => null,
            'to_status' => 'requested',
            'occurred_at' => now()->subHours(2),
        ]);
        TransportEvent::create([
            'event_uuid' => (string) Str::uuid(),
            'transport_request_id' => $trip->transport_request_id,
            'event_type' => 'transport.queued',
            'from_status' => 'requested',
            'to_status' => 'queued',
            'occurred_at' => now()->subHour(),
        ]);

        // A turn due in 1h on a real bed (isolation — the flag EVS pre-positions on).
        $turn = EvsRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'bed_clean',
            'priority' => 'routine',
            'status' => 'queued',
            'unit_id' => $micu->unit_id,
            'bed_id' => $bed->bed_id,
            'patient_ref' => 'FLOWTEST-PAT-TURN',
            'location_label' => $bed->label,
            'turn_type' => 'isolation',
            'isolation_required' => true,
            'needed_at' => now()->addHour(),
        ]);
        EvsEvent::create([
            'event_uuid' => (string) Str::uuid(),
            'evs_request_id' => $turn->evs_request_id,
            'event_type' => 'evs.queued',
            'from_status' => 'requested',
            'to_status' => 'queued',
            'occurred_at' => now()->subMinutes(45),
        ]);

        $this->seedPeriopFlowStory();
    }

    /**
     * One case in "OR 3" today: scheduled an hour ago (so the projection
     * still runs +3h ahead) with in-room / procedure-start milestones inside
     * the past window. Guarded like the flow services — the suite still
     * passes when the optional periop tables are absent.
     */
    private function seedPeriopFlowStory(): void
    {
        // Same dual-name resolution OperationalTimelineService uses (legacy
        // ETL: prod.orlog; migration path: prod.or_logs).
        $orlogTable = collect(['prod.orlog', 'prod.or_logs'])
            ->first(fn (string $table): bool => Schema::hasTable($table));

        if ($orlogTable === null || ! Schema::hasTable('prod.or_cases')) {
            return;
        }

        $locationId = DB::table('prod.locations')->where('abbreviation', 'MOR')->value('location_id')
            ?? DB::table('prod.locations')->insertGetId($this->referenceRow([
                'name' => 'Main OR Suite', 'abbreviation' => 'MOR',
                'type' => 'surgical', 'pos_type' => 'inpatient',
            ]), 'location_id');

        $roomId = DB::table('prod.rooms')->where('name', 'OR 3')->value('room_id')
            ?? DB::table('prod.rooms')->insertGetId($this->referenceRow([
                'location_id' => $locationId, 'name' => 'OR 3', 'type' => 'OR',
            ]), 'room_id');

        $specialtyId = DB::table('prod.specialties')->where('code', 'GENSUR')->value('specialty_id')
            ?? DB::table('prod.specialties')->insertGetId($this->referenceRow([
                'name' => 'General Surgery', 'code' => 'GENSUR',
            ]), 'specialty_id');

        $surgeonId = DB::table('prod.providers')->where('npi', '9990001111')->value('provider_id')
            ?? DB::table('prod.providers')->insertGetId($this->referenceRow([
                'npi' => '9990001111', 'name' => 'Flow Story Surgeon',
                'specialty_id' => $specialtyId, 'type' => 'surgeon',
            ]), 'provider_id');

        $serviceId = DB::table('prod.services')->where('code', 'GENSUR')->value('service_id')
            ?? DB::table('prod.services')->insertGetId($this->referenceRow([
                'name' => 'General Surgery', 'code' => 'GENSUR',
            ]), 'service_id');

        $statusId = DB::table('prod.case_statuses')->where('code', 'SCHED')->value('status_id')
            ?? DB::table('prod.case_statuses')->insertGetId($this->referenceRow([
                'name' => 'Scheduled', 'code' => 'SCHED',
            ]), 'status_id');

        $caseTypeId = DB::table('prod.case_types')->where('code', 'ELEC')->value('case_type_id')
            ?? DB::table('prod.case_types')->insertGetId($this->referenceRow([
                'name' => 'Elective', 'code' => 'ELEC',
            ]), 'case_type_id');

        $caseClassId = DB::table('prod.case_classes')->where('code', 'INP')->value('case_class_id')
            ?? DB::table('prod.case_classes')->insertGetId($this->referenceRow([
                'name' => 'Inpatient', 'code' => 'INP',
            ]), 'case_class_id');

        $patientClassId = DB::table('prod.patient_classes')->where('code', 'INP')->value('patient_class_id')
            ?? DB::table('prod.patient_classes')->insertGetId($this->referenceRow([
                'name' => 'Inpatient', 'code' => 'INP',
            ]), 'patient_class_id');

        $asaId = DB::table('prod.asa_ratings')->where('code', 'ASA2')->value('asa_id')
            ?? DB::table('prod.asa_ratings')->insertGetId($this->referenceRow([
                'name' => 'ASA II', 'code' => 'ASA2',
            ]), 'asa_id');

        $caseId = DB::table('prod.or_cases')->insertGetId($this->referenceRow([
            'patient_id' => 'FLOWTEST-OR-CASE',
            'surgery_date' => now()->toDateString(),
            'room_id' => $roomId,
            'location_id' => $locationId,
            'primary_surgeon_id' => $surgeonId,
            'case_service_id' => $serviceId,
            'scheduled_start_time' => now()->subHour(),
            'scheduled_duration' => 240,
            'record_create_date' => now()->subDays(3),
            'status_id' => $statusId,
            'asa_rating_id' => $asaId,
            'case_type_id' => $caseTypeId,
            'case_class_id' => $caseClassId,
            'patient_class_id' => $patientClassId,
        ]), 'case_id');

        DB::table($orlogTable)->insert($this->referenceRow([
            'case_id' => $caseId,
            'tracking_date' => now()->toDateString(),
            'or_in_time' => now()->subHours(2),
            'procedure_start_time' => now()->subMinutes(90),
            'primary_procedure' => 'Laparoscopic Cholecystectomy',
        ]));
    }

    /** @return array<string, mixed> the row plus the audit boilerplate every prod.* table shares */
    private function referenceRow(array $attributes): array
    {
        return $attributes + [
            'created_by' => 'flow-story',
            'modified_by' => 'flow-story',
            'created_at' => now(),
            'updated_at' => now(),
            'is_deleted' => false,
        ];
    }
}
