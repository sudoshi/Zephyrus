<?php

namespace Tests\Feature\CarePathways;

use App\Models\User;
use App\Services\CarePathways\CatalogImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CarePathwayRawFixture;
use Tests\TestCase;

class CarePathwayCatalogPageTest extends TestCase
{
    use CarePathwayRawFixture;
    use RefreshDatabase;

    private User $dataSteward;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureCarePathwayFixture();
        $this->seedCarePathwayRawFixture();
        app(CatalogImportService::class)->adopt(1, 'test-data-steward');
        config(['care-pathways.governance_enabled' => true]);

        $this->dataSteward = User::factory()->create(['role' => 'data_steward']);
    }

    public function test_catalog_index_returns_404_when_governance_disabled(): void
    {
        config(['care-pathways.governance_enabled' => false]);

        $this->actingAs($this->dataSteward)
            ->get('/care-pathways/catalog')
            ->assertNotFound();
    }

    public function test_catalog_index_forbidden_without_catalog_capability(): void
    {
        $frontline = User::factory()->create(['role' => 'user']);

        $this->actingAs($frontline)
            ->get('/care-pathways/catalog')
            ->assertForbidden();
    }

    public function test_catalog_index_renders_inertia_page_with_summary_for_authorized_user(): void
    {
        $this->actingAs($this->dataSteward)
            ->get('/care-pathways/catalog')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CarePathways/Catalog/Index')
                ->where('initialSummary.data.release.state', 'inactive')
                ->where('initialSummary.data.release.clinical_signoff_count', 0)
                ->where('initialSummary.meta.patient_serving', false)
                ->has('initialPathways.data')
                ->has('initialPathways.meta.pagination'));
    }

    public function test_catalog_show_renders_version_detail(): void
    {
        $versionUuid = (string) DB::table('care_pathways.versions')
            ->orderBy('pathway_version_id')
            ->value('pathway_version_uuid');

        $this->actingAs($this->dataSteward)
            ->get('/care-pathways/catalog/'.$versionUuid)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CarePathways/Catalog/Show')
                ->where('initialVersion.data.version.version_uuid', $versionUuid)
                ->has('initialVersion.data.sections')
                ->has('initialVersion.data.drg_candidates'));
    }

    public function test_catalog_show_404s_for_unknown_version(): void
    {
        $this->actingAs($this->dataSteward)
            ->get('/care-pathways/catalog/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }
}
