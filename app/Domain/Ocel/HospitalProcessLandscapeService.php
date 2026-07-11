<?php

namespace App\Domain\Ocel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read model for the seeded hospital process landscape.
 *
 * Reference definitions and observed OCEL evidence stay separate all the way
 * through the API contract. The former comes from process_model_*; the latter
 * is a compact readiness snapshot from the live projection tables.
 */
class HospitalProcessLandscapeService
{
    /** @return array<string, mixed> */
    public function index(): array
    {
        if (! $this->isAvailable()) {
            return ['available' => false, 'reason' => 'process_model_registry_not_seeded'];
        }

        $rows = DB::table('ocel.process_models')
            ->orderBy('domain_code')
            ->orderBy('process_number')
            ->get();

        $models = $rows->map(fn (object $row): array => $this->modelSummary($row))->all();
        $domains = collect($models)
            ->groupBy('domain_code')
            ->map(fn ($items, string $code): array => [
                'code' => $code,
                'name' => (string) $items->first()['domain_name'],
                'count' => $items->count(),
            ])
            ->values()
            ->all();

        return [
            'available' => true,
            'document' => [
                'id' => HospitalProcessCatalog::DOCUMENT_ID,
                'version' => HospitalProcessCatalog::DOCUMENT_VERSION,
                'date' => '2026-07-09',
                'catalog_count' => count($models),
                'requested_count_note' => 'The source catalog contains 93 rows; the original request referred to 88.',
                'data_basis' => 'seeded_reference_models',
                'observed_claim' => false,
            ],
            'counts' => [
                'models' => count($models),
                'domains' => count($domains),
                'priorities' => $this->countsBy($models, 'priority'),
                'readiness' => $this->countsBy($models, 'current_readiness'),
                'waves' => $this->countsBy($models, 'implementation_wave'),
            ],
            'projection' => $this->projectionSnapshot(),
            'domains' => $domains,
            'models' => $models,
        ];
    }

    /** @return array<string, mixed>|null */
    public function show(string $processId): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $processId = strtoupper(trim($processId));
        $row = DB::table('ocel.process_models')->where('process_id', $processId)->first();
        if ($row === null) {
            return null;
        }

        $nodes = DB::table('ocel.process_model_nodes')
            ->where('process_id', $processId)
            ->orderBy('ordinal')
            ->get()
            ->map(fn (object $node): array => [
                'node_key' => $node->node_key,
                'activity' => $node->activity,
                'label' => $node->label,
                'node_kind' => $node->node_kind,
                'ordinal' => (int) $node->ordinal,
                'object_types' => $this->jsonArray($node->object_types),
                'required' => (bool) $node->required,
                'source_basis' => $node->source_basis,
                'observed_count' => $this->observedActivityCount($node->activity),
            ])
            ->all();

        $edges = DB::table('ocel.process_model_edges')
            ->where('process_id', $processId)
            ->orderBy('ordinal')
            ->get()
            ->map(fn (object $edge): array => [
                'edge_key' => $edge->edge_key,
                'source_node_key' => $edge->source_node_key,
                'target_node_key' => $edge->target_node_key,
                'label' => $edge->label,
                'relationship_type' => $edge->relationship_type,
                'ordinal' => (int) $edge->ordinal,
                'is_exception' => (bool) $edge->is_exception,
            ])
            ->all();

        return [
            'available' => true,
            'data_basis' => 'seeded_reference_model',
            'observed_claim' => false,
            'model' => $this->modelSummary($row) + [
                'core_objects' => $this->jsonArray($row->core_objects),
                'source_document' => $row->source_document,
                'catalog_version' => (int) $row->catalog_version,
            ],
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }

    private function isAvailable(): bool
    {
        return Schema::hasTable('ocel.process_models')
            && Schema::hasTable('ocel.process_model_nodes')
            && Schema::hasTable('ocel.process_model_edges');
    }

    /** @return array<string, mixed> */
    private function modelSummary(object $row): array
    {
        return [
            'process_id' => $row->process_id,
            'process_number' => (int) $row->process_number,
            'domain_code' => $row->domain_code,
            'domain_name' => $row->domain_name,
            'name' => $row->name,
            'core_interaction' => $row->core_interaction,
            'improvement_question' => $row->improvement_question,
            'evidence_grade' => $row->evidence_grade,
            'priority' => $row->priority,
            'interaction_pattern' => $row->interaction_pattern,
            'implementation_wave' => $row->implementation_wave,
            'current_readiness' => $row->current_readiness,
            'readiness_note' => $row->readiness_note,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $models
     * @return array<string, int>
     */
    private function countsBy(array $models, string $key): array
    {
        $counts = [];
        foreach ($models as $model) {
            $value = (string) $model[$key];
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /** @return array<string, int> */
    private function projectionSnapshot(): array
    {
        $events = Schema::hasTable('ocel.events') ? (int) DB::table('ocel.events')->count() : 0;
        $objects = Schema::hasTable('ocel.objects') ? (int) DB::table('ocel.objects')->count() : 0;
        $emittedTypes = Schema::hasTable('ocel.objects')
            ? (int) DB::table('ocel.objects')->distinct('type')->count('type')
            : 0;
        $sourceSystems = Schema::hasTable('ocel.events')
            ? (int) DB::table('ocel.events')->distinct('source_system')->count('source_system')
            : 0;

        return [
            'projected_events' => $events,
            'projected_objects' => $objects,
            'source_systems' => $sourceSystems,
            'declared_object_types' => count(OcelCatalog::objectTypes()),
            'emitted_object_types' => $emittedTypes,
            'target_object_types' => HospitalProcessCatalog::TARGET_OBJECT_TYPE_COUNT,
            'catalog_activities' => count(OcelCatalog::activities()),
        ];
    }

    private function observedActivityCount(string $activity): int
    {
        if (! Schema::hasTable('ocel.events')) {
            return 0;
        }

        return (int) DB::table('ocel.events')->where('activity', $activity)->count();
    }

    /** @return array<int, mixed> */
    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? array_values($decoded) : [];
        }

        return [];
    }
}
