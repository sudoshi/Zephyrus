<?php

namespace Tests\Unit\Ocel;

use App\Domain\Ocel\HospitalProcessCatalog;
use PHPUnit\Framework\TestCase;

class HospitalProcessCatalogTest extends TestCase
{
    public function test_catalog_contains_every_pdf_row_exactly_once(): void
    {
        $models = HospitalProcessCatalog::models();
        $ids = array_column($models, 'process_id');

        $expected = [];
        foreach (['A' => 10, 'B' => 12, 'C' => 12, 'D' => 14, 'E' => 12, 'F' => 13, 'G' => 12, 'H' => 8] as $domain => $count) {
            for ($number = 1; $number <= $count; $number++) {
                $expected[] = $domain.$number;
            }
        }

        $this->assertCount(HospitalProcessCatalog::MODEL_COUNT, $models);
        $this->assertSame(93, HospitalProcessCatalog::MODEL_COUNT);
        $this->assertSame($expected, $ids);
        $this->assertCount(count($ids), array_unique($ids));
    }

    public function test_every_model_is_a_complete_renderable_reference_graph(): void
    {
        foreach (HospitalProcessCatalog::models() as $model) {
            $this->assertNotEmpty($model['name'], $model['process_id']);
            $this->assertNotEmpty($model['core_objects'], $model['process_id']);
            $this->assertNotEmpty($model['improvement_question'], $model['process_id']);
            $this->assertContains($model['priority'], ['P0', 'P1', 'P2', 'P3'], $model['process_id']);
            $this->assertContains($model['current_readiness'], [
                'partial_projection',
                'source_present_not_projected',
                'reference_only',
            ], $model['process_id']);

            $nodes = $model['nodes'];
            $edges = $model['edges'];
            $keys = array_column($nodes, 'node_key');

            $this->assertGreaterThanOrEqual(6, count($nodes), $model['process_id']);
            $this->assertCount(count($nodes) - 1, $edges, $model['process_id']);
            $this->assertCount(count($keys), array_unique($keys), $model['process_id']);
            $this->assertSame('trigger', $nodes[0]['node_kind'], $model['process_id']);
            $this->assertSame('outcome', $nodes[array_key_last($nodes)]['node_kind'], $model['process_id']);

            foreach ($edges as $edge) {
                $this->assertContains($edge['source_node_key'], $keys, $model['process_id']);
                $this->assertContains($edge['target_node_key'], $keys, $model['process_id']);
            }
        }
    }

    public function test_semantic_risk_models_are_not_overstated_as_ready(): void
    {
        $this->assertSame('partial_projection', HospitalProcessCatalog::find('A8')['current_readiness']);
        $this->assertStringContainsString('assignment is currently treated as occupancy', HospitalProcessCatalog::find('A8')['readiness_note']);
        $this->assertStringContainsString('dispatch/assignment', HospitalProcessCatalog::find('E1')['readiness_note']);
        $this->assertSame('foundation', HospitalProcessCatalog::find('H7')['implementation_wave']);
        $this->assertNull(HospitalProcessCatalog::find('Z99'));
    }
}
