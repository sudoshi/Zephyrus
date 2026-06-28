<?php

namespace Tests\Feature\Eddy;

use App\Models\Eddy\EddyKnowledge;
use App\Models\User;
use App\Services\Eddy\EddyContextService;
use App\Services\Eddy\EddyKnowledgeService;
use Database\Seeders\EddySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EddyContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_context_assembles_a_phi_free_live_snapshot(): void
    {
        $user = User::factory()->create();

        $context = app(EddyContextService::class)->forSurface($user, 'rtdc');

        // Structure present even on an empty test DB (zeros, never PHI).
        $this->assertArrayHasKey('capacity', $context);
        $this->assertArrayHasKey('source_freshness', $context);
        $this->assertArrayHasKey('net_beds', $context['capacity']);

        $json = strtolower((string) json_encode($context));
        foreach (['mrn', 'ssn', 'patient_name', 'dob'] as $phi) {
            $this->assertStringNotContainsString($phi, $json);
        }
    }

    public function test_house_wide_surfaces_add_the_executive_situation(): void
    {
        $user = User::factory()->create();

        $context = app(EddyContextService::class)->forSurface($user, 'command_center');

        $this->assertArrayHasKey('situation', $context);
        $this->assertArrayHasKey('governance', $context);
    }

    public function test_knowledge_retrieval_is_surface_scoped_and_keyword_ranked(): void
    {
        $this->seed(EddySeeder::class);

        // A surge question should surface the rtdc 'Red stretch' doctrine first.
        $hits = app(EddyKnowledgeService::class)->forSurface('rtdc', 'we have a surge, how do I escalate?');

        $this->assertNotEmpty($hits);
        $this->assertSame('Red stretch / surge escalation', $hits[0]['title']);
    }

    public function test_knowledge_excludes_non_phi_free_rows(): void
    {
        EddyKnowledge::create([
            'eddy_knowledge_uuid' => (string) Str::uuid(),
            'surface' => 'rtdc',
            'category' => 'policy',
            'title' => 'Sensitive note',
            'body' => 'contains patient identifiers',
            'is_phi_free' => false,
            'is_active' => true,
        ]);

        $hits = app(EddyKnowledgeService::class)->forSurface('rtdc', 'sensitive note');

        $this->assertCount(0, $hits);
    }
}
