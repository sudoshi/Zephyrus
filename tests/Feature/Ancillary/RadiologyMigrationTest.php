<?php

namespace Tests\Feature\Ancillary;

use App\Models\Ancillary\AncillaryOrder;
use App\Models\Radiology\CriticalResult;
use App\Models\Radiology\Exam;
use App\Models\Radiology\Read;
use App\Models\Radiology\Scanner;
use App\Models\Radiology\ScannerDowntime;
use Database\Seeders\AncillaryReferenceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RadiologyMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AncillaryReferenceSeeder::class);
    }

    public function test_radiology_tables_constraints_and_worklist_indexes_exist(): void
    {
        foreach (['hosp_ref.rad_modalities', 'hosp_ref.rad_subspecialties', 'prod.rad_scanners', 'prod.rad_scanner_downtimes', 'prod.rad_exams', 'prod.rad_reads', 'prod.rad_critical_results'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing {$table}");
        }

        $constraints = DB::table('pg_constraint')->whereIn('conname', [
            'rad_scanner_downtimes_interval_check', 'rad_exams_schedule_interval_check', 'rad_exams_performed_interval_check',
            'rad_reads_status_check', 'rad_critical_results_ack_order_check',
        ])->pluck('conname')->all();
        $this->assertCount(5, $constraints);

        $indexes = DB::table('pg_indexes')->whereIn('indexname', [
            'rad_exams_open_worklist_idx', 'rad_exams_scanner_day_idx', 'rad_exams_unread_backlog_idx',
            'rad_reads_queue_idx', 'rad_critical_results_open_loop_idx',
        ])->pluck('indexname')->all();
        $this->assertCount(5, $indexes);
    }

    public function test_scanner_downtime_rejects_reversed_effective_interval(): void
    {
        $scanner = Scanner::factory()->create();

        $this->expectException(QueryException::class);
        ScannerDowntime::factory()->for($scanner, 'scanner')->create([
            'source_id' => $scanner->source_id,
            'starts_at' => now(),
            'ends_at' => now()->subMinute(),
        ]);
    }

    public function test_duplicate_source_exam_identity_is_rejected(): void
    {
        $exam = Exam::factory()->create(['source_exam_key' => 'duplicate-exam']);

        $this->expectException(QueryException::class);
        Exam::factory()->create(['source_id' => $exam->source_id, 'source_exam_key' => 'duplicate-exam']);
    }

    public function test_duplicate_source_read_identity_is_rejected(): void
    {
        $read = Read::factory()->create(['source_read_key' => 'duplicate-read']);

        $this->expectException(QueryException::class);
        Read::factory()->create(['source_id' => $read->source_id, 'source_read_key' => 'duplicate-read']);
    }

    public function test_duplicate_source_critical_result_identity_is_rejected(): void
    {
        $critical = CriticalResult::factory()->create(['source_result_key' => 'duplicate-critical']);

        $this->expectException(QueryException::class);
        CriticalResult::factory()->create(['source_id' => $critical->source_id, 'source_result_key' => 'duplicate-critical']);
    }

    public function test_exam_requires_a_radiology_ancillary_order_and_valid_encounter(): void
    {
        $exam = Exam::factory()->create();
        $this->assertSame('rad', $exam->ancillaryOrder->department);
        $this->assertSame($exam->encounter_id, $exam->ancillaryOrder->encounter_id);
        $this->assertNotNull($exam->encounter);

        $labOrder = AncillaryOrder::factory()->lab()->create();
        $this->expectException(QueryException::class);
        Exam::factory()->create(['ancillary_order_id' => $labOrder->ancillary_order_id, 'source_id' => $labOrder->source_id, 'encounter_id' => $labOrder->encounter_id]);
    }
}
