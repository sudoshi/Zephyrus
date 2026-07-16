<?php

namespace Database\Factories\Ancillary\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CreatesAncillaryIntegrationFixtures
{
    protected function createIntegrationSourceId(string $systemClass = 'ancillary'): int
    {
        return DB::table('integration.sources')->insertGetId([
            'source_uuid' => (string) Str::uuid(),
            'source_key' => 'ancillary-factory-'.Str::uuid(),
            'source_name' => 'Ancillary factory source',
            'system_class' => $systemClass,
            'interface_type' => 'synthetic',
            'active_status' => 'inactive',
            'phi_allowed' => false,
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'source_id');
    }

    protected function createCanonicalEventId(int $sourceId, string $eventType, mixed $occurredAt): int
    {
        return DB::table('integration.canonical_events')->insertGetId([
            'event_id' => (string) Str::uuid(),
            'source_id' => $sourceId,
            'event_type' => $eventType,
            'entity_type' => 'ancillary_order',
            'entity_ref' => 'factory-'.Str::uuid(),
            'occurred_at' => $occurredAt,
            'received_at' => now(),
            'payload' => '{}',
            'payload_hash' => hash('sha256', (string) Str::uuid()),
            'idempotency_key' => 'ancillary-factory-'.Str::uuid(),
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'canonical_event_id');
    }

    /** @param array<string, mixed> $overrides */
    protected function ensureMilestoneType(string $code, string $department, int $ordinal, array $overrides = []): void
    {
        DB::table('hosp_ref.ancillary_milestone_types')->updateOrInsert(
            ['code' => $code],
            array_merge([
                'department' => $department,
                'label' => str($code)->replace('_', ' ')->title()->toString(),
                'phase' => 'factory',
                'ordinal' => $ordinal,
                'is_terminal' => false,
                'is_optional' => false,
                'is_minimum_feed' => false,
                'source_precedence' => '[]',
                'process_ids' => '[]',
                'display_metadata' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ], $overrides)
        );
    }
}
