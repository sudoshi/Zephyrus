<?php

namespace Tests\Unit\PatientFlow;

use App\Services\PatientFlow\BarrierTaxonomyService;
use Tests\TestCase;

class BarrierTaxonomyServiceTest extends TestCase
{
    public function test_resolves_known_barrier_metadata_for_eddy_and_rtdc(): void
    {
        $service = app(BarrierTaxonomyService::class);

        $definition = $service->definition('evs_isolation_clean_delayed');

        $this->assertTrue($definition['known']);
        $this->assertSame('Isolation clean delayed', $definition['label']);
        $this->assertSame('evs', $service->ownerFor('evs_isolation_clean_delayed'));
        $this->assertContains('bed_turnaround_minutes', $service->rtdcMetricsFor('evs_isolation_clean_delayed'));
        $this->assertStringContainsString('isolation clean', strtolower($service->eddySummaryFor('evs_isolation_clean_delayed')));
    }

    public function test_status_thresholds_are_code_driven(): void
    {
        $service = app(BarrierTaxonomyService::class);

        $this->assertSame('ok', $service->statusFor('pacu_floor_transport_at_risk', 45));
        $this->assertSame('watch', $service->statusFor('pacu_floor_transport_at_risk', 22));
        $this->assertSame('delayed', $service->statusFor('pacu_floor_transport_at_risk', -0.49));
        $this->assertSame('delayed', $service->statusFor('pacu_floor_transport_at_risk', -1));
    }

    public function test_unknown_barrier_codes_are_safe_and_still_traceable(): void
    {
        $service = app(BarrierTaxonomyService::class);

        $definition = $service->definition('Vendor Free Text Barrier!');

        $this->assertFalse($definition['known']);
        $this->assertSame('vendor_free_text_barrier', $definition['code']);
        $this->assertSame('Unclassified barrier', $definition['label']);
        $this->assertSame('bed_manager', $service->ownerFor('Vendor Free Text Barrier!'));
        $this->assertSame(['capacity_risk'], $service->rtdcMetricsFor('Vendor Free Text Barrier!'));
    }
}
