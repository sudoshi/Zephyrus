<?php

namespace Tests\Feature\Cockpit;

use App\Services\Cockpit\DrillBuilder;
use App\Services\Cockpit\SnapshotBuilder;
use Database\Seeders\CockpitKpiDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Zephyrus 2.0 P7 (Periop) — the OR board carries suite name + delay minutes,
 * and the PACU bay board surfaces recovery-bay boarders from or_logs joins.
 */
class PeriopDrillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(SnapshotBuilder::CACHE_KEY);
        $this->seed(CockpitKpiDefinitionSeeder::class);
    }

    private function room(int $id, string $name): void
    {
        DB::table('prod.locations')->updateOrInsert(['location_id' => 1], [
            'name' => 'Main OR', 'abbreviation' => 'MOR', 'type' => 'OR', 'pos_type' => 'inpatient',
            'active_status' => true, 'is_deleted' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('prod.rooms')->insert([
            'room_id' => $id, 'location_id' => 1, 'name' => $name, 'type' => 'OR',
            'active_status' => true, 'is_deleted' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedOrReferenceData(): void
    {
        DB::table('prod.specialties')->updateOrInsert(['specialty_id' => 1], ['name' => 'General Surgery', 'code' => 'GS', 'is_deleted' => false]);
        DB::table('prod.providers')->updateOrInsert(['provider_id' => 1], ['npi' => '1234567890', 'name' => 'Dr Test', 'specialty_id' => 1, 'type' => 'surgeon', 'is_deleted' => false]);
        DB::table('prod.services')->updateOrInsert(['service_id' => 1], ['name' => 'General', 'code' => 'GEN', 'is_deleted' => false]);
        DB::table('prod.asa_ratings')->updateOrInsert(['asa_id' => 1], ['name' => 'ASA I', 'code' => '1', 'is_deleted' => false]);
        DB::table('prod.case_classes')->updateOrInsert(['case_class_id' => 1], ['name' => 'Elective', 'code' => 'EL', 'is_deleted' => false]);
        DB::table('prod.case_types')->updateOrInsert(['case_type_id' => 1], ['name' => 'Inpatient', 'code' => 'IP', 'is_deleted' => false]);
        DB::table('prod.patient_classes')->updateOrInsert(['patient_class_id' => 1], ['name' => 'Inpatient', 'code' => 'IP', 'is_deleted' => false]);
        DB::table('prod.case_statuses')->updateOrInsert(['status_id' => 1], ['name' => 'Completed', 'code' => 'C', 'is_deleted' => false]);
    }

    private function caseWithLog(int $roomId, array $log): int
    {
        $this->seedOrReferenceData();

        $caseId = DB::table('prod.or_cases')->insertGetId([
            'patient_id' => 'SIM'.Str::random(4),
            'surgery_date' => now()->toDateString(),
            'room_id' => $roomId,
            'location_id' => 1,
            'primary_surgeon_id' => 1,
            'case_service_id' => 1,
            'scheduled_start_time' => now()->subHours(4),
            'scheduled_duration' => 120,
            'record_create_date' => now()->subDays(2),
            'status_id' => 1,
            'asa_rating_id' => 1,
            'case_type_id' => 1,
            'case_class_id' => 1,
            'patient_class_id' => 1,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'case_id');

        DB::table('prod.or_logs')->insert($log + [
            'case_id' => $caseId,
            'tracking_date' => now()->toDateString(),
            'primary_procedure' => 'Test procedure',
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $caseId;
    }

    public function test_pacu_bay_board_flags_holds_past_the_threshold(): void
    {
        $this->room(1, 'OR 1 — Cardiac');
        // Held 2h in PACU (no pacu_out) → boarding.
        $this->caseWithLog(1, [
            'or_in_time' => now()->subHours(4),
            'or_out_time' => now()->subHours(2)->subMinutes(30),
            'pacu_in_time' => now()->subHours(2),
            'pacu_out_time' => null,
        ]);
        // Recovering 20m, under threshold.
        $this->caseWithLog(1, [
            'or_in_time' => now()->subHours(3),
            'or_out_time' => now()->subMinutes(25),
            'pacu_in_time' => now()->subMinutes(20),
            'pacu_out_time' => null,
        ]);
        // Already discharged from PACU → not on the board.
        $this->caseWithLog(1, [
            'or_in_time' => now()->subHours(5),
            'or_out_time' => now()->subHours(4),
            'pacu_in_time' => now()->subHours(3)->subMinutes(30),
            'pacu_out_time' => now()->subHours(2),
        ]);

        $periop = app(DrillBuilder::class)->build('periop');
        $captions = array_column($periop['tables'], 'caption');
        $this->assertContains('PACU bay board', $captions);

        $pacu = collect($periop['tables'])->firstWhere('caption', 'PACU bay board');
        $this->assertCount(2, $pacu['rows']); // the two still-in-PACU, not the discharged one

        $held = collect($pacu['rows'])->firstWhere('state.tag.text', 'boarding');
        $this->assertNotNull($held);
        $recovering = collect($pacu['rows'])->firstWhere('state.tag.text', 'recovering');
        $this->assertNotNull($recovering);
    }

    public function test_or_board_exposes_suite_name_and_delay(): void
    {
        $this->room(1, 'OR 1 — Cardiac');
        // A case running 90m past its 120m schedule (well beyond the 1.15 ratio).
        $this->caseWithLog(1, [
            'or_in_time' => now()->subHours(4),
            'procedure_start_time' => now()->subHours(4),
            'or_out_time' => now()->addHour(),
        ]);

        $rooms = app(\App\Services\Operations\RoomStatusService::class)->build()['rooms'];
        $this->assertNotEmpty($rooms);
        $this->assertSame('OR 1 — Cardiac', $rooms[0]['suiteName']);
        $this->assertArrayHasKey('delayMin', $rooms[0]);
    }
}
