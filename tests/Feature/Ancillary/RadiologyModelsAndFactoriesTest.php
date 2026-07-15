<?php

namespace Tests\Feature\Ancillary;

use App\Models\Ancillary\AncillaryOrder;
use App\Models\Encounter;
use App\Models\Integration\Source;
use App\Models\Radiology\CriticalResult;
use App\Models\Radiology\Exam;
use App\Models\Radiology\Modality;
use App\Models\Radiology\Read;
use App\Models\Radiology\Scanner;
use App\Models\Radiology\ScannerDowntime;
use App\Models\Radiology\Subspecialty;
use Database\Seeders\AncillaryReferenceSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RadiologyModelsAndFactoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AncillaryReferenceSeeder::class);
    }

    public function test_reference_seeder_is_idempotent_and_governs_required_catalogs(): void
    {
        $this->seed(AncillaryReferenceSeeder::class);

        $this->assertSame(['CT', 'IR', 'MRI', 'NM', 'US', 'XR'], Modality::query()->orderBy('code')->pluck('code')->all());
        $this->assertSame(9, Subspecialty::query()->count());
        $this->assertTrue(Modality::query()->findOrFail('XR')->supports_portable);
        $this->assertTrue(Modality::query()->findOrFail('CT')->contrast_screening_applicable);
    }

    public function test_factories_cover_modality_portable_contrast_ir_and_cancelled_states(): void
    {
        $fixtures = [
            Exam::factory()->xr()->create(),
            Exam::factory()->ct()->withContrast()->create(),
            Exam::factory()->mri()->create(),
            Exam::factory()->ultrasound()->create(),
            Exam::factory()->nuclearMedicine()->create(),
            Exam::factory()->interventional()->create(),
            Exam::factory()->portable()->create(),
            Exam::factory()->cancelled()->create(),
        ];

        $this->assertSame(['CT', 'IR', 'MRI', 'NM', 'US', 'XR'], collect($fixtures)->pluck('modality_code')->unique()->sort()->values()->all());
        $this->assertTrue($fixtures[1]->preparation['contrast_screening'] === 'complete');
        $this->assertTrue($fixtures[5]->is_ir);
        $this->assertTrue($fixtures[6]->is_portable);
        $this->assertSame('cancelled', $fixtures[7]->status);
        $this->assertInstanceOf(DateTimeImmutable::class, $fixtures[7]->cancelled_at);
    }

    public function test_model_graph_preserves_sources_orders_encounters_resources_reads_and_critical_loops(): void
    {
        $scanner = Scanner::factory()->modality('CT')->create();
        $downtime = ScannerDowntime::factory()->for($scanner, 'scanner')->create(['source_id' => $scanner->source_id]);
        $exam = Exam::factory()->completed()->create(['rad_scanner_id' => $scanner->rad_scanner_id]);
        $read = Read::factory()->for($exam, 'exam')->create(['source_id' => $exam->source_id, 'subspecialty_code' => $exam->subspecialty_code]);
        $addendum = Read::factory()->addendum($read)->create();
        $critical = CriticalResult::factory()->for($read, 'read')->create([
            'rad_exam_id' => $exam->rad_exam_id,
            'source_id' => $exam->source_id,
        ]);

        $exam->load(['ancillaryOrder.radiologyExam', 'source', 'encounter', 'modality', 'subspecialty', 'scanner', 'reads', 'criticalResults']);
        $scanner->load(['source', 'modality', 'downtimes', 'exams']);
        $read->load(['parent', 'addenda', 'criticalResults']);

        $this->assertInstanceOf(AncillaryOrder::class, $exam->ancillaryOrder);
        $this->assertInstanceOf(Encounter::class, $exam->encounter);
        $this->assertInstanceOf(Source::class, $exam->source);
        $this->assertInstanceOf(Modality::class, $exam->modality);
        $this->assertInstanceOf(Subspecialty::class, $exam->subspecialty);
        $this->assertSame($exam->rad_exam_id, $exam->ancillaryOrder->radiologyExam->rad_exam_id);
        $this->assertSame($scanner->rad_scanner_id, $downtime->scanner->rad_scanner_id);
        $this->assertTrue($scanner->downtimes->contains($downtime));
        $this->assertTrue($scanner->exams->contains($exam));
        $this->assertTrue($exam->reads->contains($read));
        $this->assertTrue($read->addenda->contains($addendum));
        $this->assertTrue($exam->criticalResults->contains($critical));
        $this->assertTrue(CriticalResult::query()->open()->whereKey($critical->getKey())->exists());
        $this->assertTrue(Exam::query()->unread()->whereKey(Exam::factory()->completed()->create()->getKey())->exists());
    }
}
