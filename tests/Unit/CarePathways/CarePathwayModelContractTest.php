<?php

namespace Tests\Unit\CarePathways;

use App\Models\CarePathways\ActivityDefinition;
use App\Models\CarePathways\CatalogRelease;
use App\Models\CarePathways\CompletenessResolution;
use App\Models\CarePathways\EducationDefinition;
use App\Models\CarePathways\GoalDefinition;
use App\Models\CarePathways\MilestoneDefinition;
use App\Models\CarePathways\PathwayApproval;
use App\Models\CarePathways\PathwayEvent;
use App\Models\CarePathways\PathwayReview;
use App\Models\CarePathways\PathwaySection;
use App\Models\CarePathways\PathwaySource;
use App\Models\CarePathways\PathwayStageDefinition;
use App\Models\CarePathways\PathwayVersion;
use App\Models\CarePathways\SectionSource;
use App\Models\CarePathways\ServiceLineMapping;
use App\Models\CarePathways\SourceChange;
use App\Models\CarePathways\SourceEnrichment;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CarePathwayModelContractTest extends TestCase
{
    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     */
    #[DataProvider('modelTables')]
    public function test_model_uses_the_canonical_schema_and_primary_key(
        string $modelClass,
        string $table,
        string $primaryKey,
    ): void {
        $model = new $modelClass;

        $this->assertSame($table, $model->getTable());
        $this->assertSame($primaryKey, $model->getKeyName());
    }

    /** @return array<string, array{class-string<\Illuminate\Database\Eloquent\Model>, string, string}> */
    public static function modelTables(): array
    {
        return [
            'milestone definition' => [MilestoneDefinition::class, 'care_pathways.milestone_definitions', 'milestone_definition_id'],
            'stage definition' => [PathwayStageDefinition::class, 'care_pathways.stage_definitions', 'stage_definition_id'],
            'activity definition' => [ActivityDefinition::class, 'care_pathways.activity_definitions', 'activity_definition_id'],
            'goal definition' => [GoalDefinition::class, 'care_pathways.goal_definitions', 'goal_definition_id'],
            'education definition' => [EducationDefinition::class, 'care_pathways.education_definitions', 'education_definition_id'],
            'section source' => [SectionSource::class, 'care_pathways.section_sources', 'section_source_id'],
            'source change' => [SourceChange::class, 'care_pathways.source_changes', 'source_change_id'],
            'completeness resolution' => [CompletenessResolution::class, 'care_pathways.completeness_resolutions', 'completeness_resolution_id'],
            'source enrichment' => [SourceEnrichment::class, 'care_pathways.source_enrichments', 'source_enrichment_id'],
            'service-line mapping' => [ServiceLineMapping::class, 'care_pathways.service_line_mappings', 'service_line_mapping_id'],
            'pathway review' => [PathwayReview::class, 'care_pathways.reviews', 'pathway_review_id'],
            'pathway approval' => [PathwayApproval::class, 'care_pathways.approvals', 'pathway_approval_id'],
            'pathway event' => [PathwayEvent::class, 'care_pathways.events', 'pathway_event_id'],
        ];
    }

    public function test_canonical_relationships_use_the_expected_foreign_keys(): void
    {
        $this->assertSame('pathway_version_id', (new PathwayVersion)->milestones()->getForeignKeyName());
        $this->assertSame('pathway_version_id', (new PathwayVersion)->stages()->getForeignKeyName());
        $this->assertSame('pathway_version_id', (new PathwayVersion)->activities()->getForeignKeyName());
        $this->assertSame('pathway_version_id', (new PathwayVersion)->goals()->getForeignKeyName());
        $this->assertSame('pathway_version_id', (new PathwayVersion)->education()->getForeignKeyName());
        $this->assertSame('pathway_version_id', (new PathwayVersion)->reviews()->getForeignKeyName());
        $this->assertSame('pathway_version_id', (new PathwayVersion)->approvals()->getForeignKeyName());
        $this->assertSame('pathway_section_id', (new PathwaySection)->sources()->getForeignKeyName());
        $this->assertSame('source_id', (new PathwaySource)->sectionSources()->getForeignKeyName());
        $this->assertSame('catalog_release_id', (new CatalogRelease)->changes()->getForeignKeyName());
        $this->assertSame('catalog_release_id', (new CatalogRelease)->enrichments()->getForeignKeyName());
        $this->assertSame('catalog_release_id', (new CatalogRelease)->completenessResolutions()->getForeignKeyName());
        $this->assertSame('catalog_release_id', (new CatalogRelease)->serviceLineMappings()->getForeignKeyName());
    }
}
