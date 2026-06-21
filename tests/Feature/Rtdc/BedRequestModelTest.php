<?php

namespace Tests\Feature\Rtdc;

use App\Models\BedRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BedRequestModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_scope_and_casts(): void
    {
        BedRequest::create(['patient_ref' => 'p1', 'source' => 'ed', 'acuity_tier' => 3, 'isolation_required' => 'contact', 'required_unit_type' => 'med_surg']);
        BedRequest::create(['patient_ref' => 'p2', 'source' => 'ed', 'acuity_tier' => 2, 'status' => 'placed']);

        $this->assertEquals(1, BedRequest::pending()->count());
        $this->assertIsInt(BedRequest::first()->acuity_tier);
    }
}
