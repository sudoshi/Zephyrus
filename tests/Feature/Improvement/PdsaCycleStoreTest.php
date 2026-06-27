<?php

namespace Tests\Feature\Improvement;

use App\Models\PdsaCycle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PdsaCycleStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_persists_a_pdsa_cycle_and_redirects_to_its_detail_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/improvement/pdsa', [
            'title' => 'Reduce AM discharge order delays on 5-West',
            'objective' => 'Cut median discharge-order time by 90 minutes.',
            'rationale' => 'Orders cluster after rounds; earlier entry frees beds by noon.',
            'prediction' => 'We predict a 20% lift in discharge-by-noon.',
            'owner' => 'C. Rivera, RN',
            'dueDate' => '2026-08-01',
        ]);

        $cycle = PdsaCycle::where('title', 'Reduce AM discharge order delays on 5-West')->first();
        $this->assertNotNull($cycle, 'The PDSA cycle should be persisted.');
        $response->assertRedirect('/improvement/pdsa/'.$cycle->pdsa_cycle_id);

        $this->assertSame('active', $cycle->status);
        $this->assertSame('C. Rivera, RN', $cycle->owner);
        $this->assertSame('Cut median discharge-order time by 90 minutes.', $cycle->objective);
        $this->assertSame('Orders cluster after rounds; earlier entry frees beds by noon.', $cycle->rationale);
        $this->assertSame('We predict a 20% lift in discharge-by-noon.', $cycle->prediction);
        $this->assertNotNull($cycle->started_at);
        $this->assertSame('2026-08-01', $cycle->target_date->format('Y-m-d'));
        $this->assertFalse($cycle->is_deleted);
    }

    public function test_store_requires_a_title(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/improvement/pdsa', ['title' => ''])
            ->assertSessionHasErrors('title');

        $this->assertSame(0, PdsaCycle::count());
    }
}
