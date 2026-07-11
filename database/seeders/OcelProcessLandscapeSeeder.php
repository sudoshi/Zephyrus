<?php

namespace Database\Seeders;

use App\Domain\Ocel\HospitalProcessCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotently materializes all ACUM-OPS-OCEL-001 reference flows.
 *
 * This seeder owns ocel.process_model_* only. It never writes observed OCEL
 * facts and never claims that a seeded reference node occurred in production.
 */
class OcelProcessLandscapeSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('ocel.process_models')
            || ! Schema::hasTable('ocel.process_model_nodes')
            || ! Schema::hasTable('ocel.process_model_edges')) {
            return;
        }

        $models = HospitalProcessCatalog::models();
        $modelIds = array_column($models, 'process_id');
        $now = now();

        DB::transaction(function () use ($models, $modelIds, $now): void {
            DB::table('ocel.process_model_edges')->whereNotIn('process_id', $modelIds)->delete();
            DB::table('ocel.process_model_nodes')->whereNotIn('process_id', $modelIds)->delete();
            DB::table('ocel.process_models')->whereNotIn('process_id', $modelIds)->delete();

            foreach ($models as $model) {
                DB::table('ocel.process_models')->updateOrInsert(
                    ['process_id' => $model['process_id']],
                    [
                        'domain_code' => $model['domain_code'],
                        'process_number' => $model['process_number'],
                        'domain_name' => $model['domain_name'],
                        'name' => $model['name'],
                        'core_interaction' => $model['core_interaction'],
                        'core_objects' => json_encode($model['core_objects']),
                        'improvement_question' => $model['improvement_question'],
                        'evidence_grade' => $model['evidence_grade'],
                        'priority' => $model['priority'],
                        'interaction_pattern' => $model['interaction_pattern'],
                        'implementation_wave' => $model['implementation_wave'],
                        'current_readiness' => $model['current_readiness'],
                        'readiness_note' => $model['readiness_note'],
                        'source_document' => $model['source_document'],
                        'catalog_version' => $model['catalog_version'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );

                $nodeKeys = [];
                foreach ($model['nodes'] as $node) {
                    $nodeKeys[] = $node['node_key'];
                    DB::table('ocel.process_model_nodes')->updateOrInsert(
                        ['process_id' => $model['process_id'], 'node_key' => $node['node_key']],
                        [
                            'activity' => $node['activity'],
                            'label' => $node['label'],
                            'node_kind' => $node['node_kind'],
                            'ordinal' => $node['ordinal'],
                            'object_types' => json_encode($node['object_types']),
                            'required' => $node['required'],
                            'source_basis' => $node['source_basis'],
                            'metadata' => json_encode($node['metadata']),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]
                    );
                }
                DB::table('ocel.process_model_nodes')
                    ->where('process_id', $model['process_id'])
                    ->whereNotIn('node_key', $nodeKeys)
                    ->delete();

                $edgeKeys = [];
                foreach ($model['edges'] as $edge) {
                    $edgeKeys[] = $edge['edge_key'];
                    DB::table('ocel.process_model_edges')->updateOrInsert(
                        ['process_id' => $model['process_id'], 'edge_key' => $edge['edge_key']],
                        [
                            'source_node_key' => $edge['source_node_key'],
                            'target_node_key' => $edge['target_node_key'],
                            'label' => $edge['label'],
                            'relationship_type' => $edge['relationship_type'],
                            'ordinal' => $edge['ordinal'],
                            'is_exception' => $edge['is_exception'],
                            'metadata' => json_encode($edge['metadata']),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]
                    );
                }
                DB::table('ocel.process_model_edges')
                    ->where('process_id', $model['process_id'])
                    ->whereNotIn('edge_key', $edgeKeys)
                    ->delete();
            }
        });
    }
}
