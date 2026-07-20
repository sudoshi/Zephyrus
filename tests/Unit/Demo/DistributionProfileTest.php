<?php

namespace Tests\Unit\Demo;

use App\Services\Demo\DistributionProfile;
use RuntimeException;
use Tests\TestCase;

class DistributionProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DistributionProfile::flush();
    }

    public function test_loads_verified_distribution_json_previously_unused_at_runtime(): void
    {
        $p = new DistributionProfile;

        $this->assertSame(500, $p->licensedInpatientBeds());
        $this->assertEqualsCanonicalizing(
            ['icu', 'step_down', 'med_surg'],
            $p->inpatientUnitTypes()
        );
        $this->assertArrayHasKey('EMERGENCY', $p->losByUnitType());
        $this->assertArrayHasKey('INPATIENT', $p->losByUnitType());
        $this->assertSame(4.07, $p->icuLos()['medianDays'] ?? null);
        $this->assertGreaterThan(5.0, $p->edThroughputHours()['median'] ?? 0);
        $this->assertGreaterThan(0.0, $p->mortalityRate());
    }

    public function test_exposes_verified_disposition_mix_in_order(): void
    {
        $mix = (new DistributionProfile)->dispositionMix();

        $this->assertNotEmpty($mix);
        $this->assertSame('Home/Self Care', $mix[0]['destination']);
        $this->assertGreaterThan(0.7, $mix[0]['probability']);
    }

    public function test_exposes_tuneable_clinical_plausibility_bands_from_config(): void
    {
        $p = new DistributionProfile;

        $this->assertArrayHasKey('icu', $p->occupancyBands());
        $this->assertArrayHasKey(3, $p->esiBands());
        $this->assertCount(2, $p->dischargeBeforeNoonBand());
        $this->assertArrayHasKey('routine', $p->transportPriorityBands());
        $this->assertLessThan(0.5, $p->transportOverdueShareMax());
        // The urgent ceiling must clear the seeded ~30% design target (3-per-10
        // urgent pattern) with headroom — a 0.30 knife-edge self-flagged.
        $this->assertGreaterThan(0.30, $p->transportPriorityBands()['urgent'][1]);
    }

    public function test_throws_a_clear_error_for_an_unregistered_facility(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No distribution profile registered');

        (new DistributionProfile('NO_SUCH_FACILITY'))->licensedInpatientBeds();
    }
}
