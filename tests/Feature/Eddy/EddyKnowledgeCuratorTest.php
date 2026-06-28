<?php

namespace Tests\Feature\Eddy;

use App\Models\Eddy\EddyKnowledge;
use App\Services\Eddy\EddyKnowledgeCurator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 6 auto-curation: recurring resolved-barrier patterns become PHI-free
 * proposed knowledge behind a review gate, with dedup + a frequency threshold.
 */
class EddyKnowledgeCuratorTest extends TestCase
{
    use RefreshDatabase;

    private function resolvedBarrier(string $category, ?string $reason): void
    {
        DB::table('prod.barriers')->insert([
            'category' => $category,
            'reason_code' => $reason,
            'status' => 'resolved',
            'opened_at' => now()->subHours(2),
            'resolved_at' => now(),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_recurring_pattern_is_proposed_as_phi_free_knowledge(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->resolvedBarrier('logistical', 'ct_delay');
        }

        $proposed = $this->app->make(EddyKnowledgeCurator::class)->curateFromResolvedBarriers(3);

        $this->assertCount(1, $proposed);
        $k = $proposed[0];
        $this->assertSame('proposed', $k->status);
        $this->assertTrue($k->is_phi_free);
        $this->assertSame('resolved_barriers', $k->curated_from['origin']);
        $this->assertSame('logistical', $k->curated_from['category']);
        $this->assertSame(3, $k->curated_from['sample_size']);
    }

    public function test_curation_is_idempotent_no_duplicates(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->resolvedBarrier('logistical', 'ct_delay');
        }

        $curator = $this->app->make(EddyKnowledgeCurator::class);
        $curator->curateFromResolvedBarriers(3);
        $second = $curator->curateFromResolvedBarriers(3);

        $this->assertCount(0, $second);
        $this->assertSame(1, EddyKnowledge::where('source', 'auto:resolved-barriers')->count());
    }

    public function test_patterns_below_threshold_are_not_proposed(): void
    {
        $this->resolvedBarrier('medical', 'hemolyzed');
        $this->resolvedBarrier('medical', 'hemolyzed');

        $proposed = $this->app->make(EddyKnowledgeCurator::class)->curateFromResolvedBarriers(3);

        $this->assertCount(0, $proposed);
    }
}
