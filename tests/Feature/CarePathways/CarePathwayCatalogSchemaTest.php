<?php

namespace Tests\Feature\CarePathways;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CarePathwayCatalogSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_schema_contains_separate_release_knowledge_evidence_and_governance_authorities(): void
    {
        foreach ([
            'catalog_releases',
            'catalog_release_controls',
            'catalog_release_sources',
            'definitions',
            'versions',
            'drg_codebook_entries',
            'drg_mappings',
            'sections',
            'stage_definitions',
            'milestone_definitions',
            'activity_definitions',
            'goal_definitions',
            'education_definitions',
            'sources',
            'source_status_events',
            'evidence_claims',
            'claim_sources',
            'section_sources',
            'source_changes',
            'source_enrichments',
            'completeness_resolutions',
            'service_line_mappings',
            'reviews',
            'approvals',
            'events',
        ] as $table) {
            $this->assertTrue(Schema::hasTable("care_pathways.{$table}"), "Missing care_pathways.{$table}");
        }

        $this->assertTrue(Schema::hasColumns('care_pathways.catalog_releases', [
            'source_csv_sha256',
            'verification_workbook_sha256',
            'pathway_count',
            'pathway_drg_association_count',
            'unique_drg_code_count',
            'clinical_signoff_complete',
            'state',
        ]));
        $this->assertTrue(Schema::hasColumns('care_pathways.versions', [
            'evidence_status',
            'verification_confidence',
            'source_specificity',
            'release_disposition',
            'clinical_signoff_status',
            'institutional_approval_status',
            'activation_status',
        ]));
    }

    public function test_catalog_database_has_activation_immutability_and_append_only_triggers(): void
    {
        $triggers = DB::table('information_schema.triggers')
            ->where('trigger_schema', 'care_pathways')
            ->pluck('trigger_name')
            ->all();

        foreach ([
            'care_pathway_release_source_controls_immutable',
            'care_pathway_release_activation_gate',
            'care_pathway_version_content_immutable',
            'care_pathway_active_version_non_overlap',
            'care_pathway_section_source_content_immutable',
            'care_pathway_stage_definition_content_immutable',
            'care_pathway_release_controls_append_only',
            'care_pathway_release_sources_append_only',
            'care_pathway_release_source_digest_guard',
            'care_pathway_reviews_append_only',
            'care_pathway_approvals_append_only',
            'care_pathway_events_append_only',
            'care_pathway_evidence_claims_append_only',
            'care_pathway_claim_sources_append_only',
            'care_pathway_claim_source_membership_guard',
            'care_pathway_section_sources_append_only',
            'care_pathway_section_source_membership_guard',
            'care_pathway_codebook_append_only',
            'care_pathway_drg_mappings_append_only',
            'care_pathway_sources_append_only',
            'care_pathway_source_status_events_append_only',
            'care_pathway_source_changes_append_only',
            'care_pathway_source_enrichments_append_only',
            'care_pathway_completeness_append_only',
        ] as $trigger) {
            $this->assertContains($trigger, $triggers, "Missing database trigger {$trigger}");
        }
    }

    public function test_current_provenance_views_exclude_non_normalized_historical_facts(): void
    {
        $this->assertSame('care_pathways.current_source_enrichments', DB::selectOne(
            "SELECT to_regclass('care_pathways.current_source_enrichments') AS relation",
        )->relation);
        $this->assertSame('care_pathways.current_completeness_resolutions', DB::selectOne(
            "SELECT to_regclass('care_pathways.current_completeness_resolutions') AS relation",
        )->relation);
        $this->assertSame('care_pathways.current_source_statuses', DB::selectOne(
            "SELECT to_regclass('care_pathways.current_source_statuses') AS relation",
        )->relation);

        $enrichmentDefinition = (string) DB::selectOne(
            "SELECT pg_get_viewdef('care_pathways.current_source_enrichments'::regclass, true) AS definition",
        )->definition;
        $completenessDefinition = (string) DB::selectOne(
            "SELECT pg_get_viewdef('care_pathways.current_completeness_resolutions'::regclass, true) AS definition",
        )->definition;
        $sourceStatusDefinition = (string) DB::selectOne(
            "SELECT pg_get_viewdef('care_pathways.current_source_statuses'::regclass, true) AS definition",
        )->definition;

        $this->assertStringContainsString('resolution_class IS NOT NULL', $enrichmentDefinition);
        $this->assertStringContainsString('resolution_digest IS NOT NULL', $completenessDefinition);
        $this->assertStringContainsString('source_status_events', $sourceStatusDefinition);
    }

    public function test_canonical_catalog_has_no_foreign_key_into_raw_or_application_serving_schemas(): void
    {
        $references = DB::select(<<<'SQL'
SELECT DISTINCT referenced_namespace.nspname AS referenced_schema
FROM pg_constraint constraints
JOIN pg_class source_table ON source_table.oid = constraints.conrelid
JOIN pg_namespace source_namespace ON source_namespace.oid = source_table.relnamespace
JOIN pg_class referenced_table ON referenced_table.oid = constraints.confrelid
JOIN pg_namespace referenced_namespace ON referenced_namespace.oid = referenced_table.relnamespace
WHERE constraints.contype = 'f'
  AND source_namespace.nspname = 'care_pathways'
SQL);

        $schemas = array_map(fn (object $row): string => (string) $row->referenced_schema, $references);
        $this->assertNotContains('raw', $schemas);
        $this->assertNotContains('patient_experience', $schemas);
        $this->assertNotContains('rounds', $schemas);
        $this->assertNotContains('eddy', $schemas);
        $this->assertNotContains('prod', $schemas);
    }
}
