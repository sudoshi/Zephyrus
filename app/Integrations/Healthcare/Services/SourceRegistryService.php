<?php

namespace App\Integrations\Healthcare\Services;

use App\Models\Integration\Source;
use Illuminate\Support\Str;

class SourceRegistryService
{
    public function ensureSource(array $attributes): Source
    {
        $sourceKey = $attributes['source_key'];

        $source = Source::firstOrNew(['source_key' => $sourceKey]);

        if (! $source->exists) {
            $source->source_uuid = (string) Str::uuid();
        }

        $source->fill(array_merge([
            'tenant_key' => 'default',
            'source_name' => $sourceKey,
            'vendor' => 'synthetic',
            'system_class' => 'test_harness',
            'environment' => 'sandbox',
            'interface_type' => 'synthetic',
            'active_status' => 'active',
            'contract_status' => 'internal',
            'baa_status' => 'not_required',
            'phi_allowed' => false,
            'go_live_status' => 'sandbox',
            'metadata' => [],
        ], $attributes));

        $source->save();

        return $source;
    }

    public function healthSummary(): array
    {
        $sources = Source::query()
            ->withCount(['ingestRuns', 'inboundMessages', 'canonicalEvents'])
            ->orderBy('source_key')
            ->get()
            ->map(fn (Source $source): array => [
                'source_id' => $source->source_id,
                'source_key' => $source->source_key,
                'source_name' => $source->source_name,
                'vendor' => $source->vendor,
                'system_class' => $source->system_class,
                'interface_type' => $source->interface_type,
                'active_status' => $source->active_status,
                'go_live_status' => $source->go_live_status,
                'phi_allowed' => $source->phi_allowed,
                'ingest_runs_count' => $source->ingest_runs_count,
                'inbound_messages_count' => $source->inbound_messages_count,
                'canonical_events_count' => $source->canonical_events_count,
                'updated_at' => $source->updated_at?->toISOString(),
            ])
            ->values()
            ->all();

        return [
            'status' => collect($sources)->contains(fn (array $source): bool => $source['active_status'] === 'active')
                ? 'active'
                : 'not_configured',
            'sources' => $sources,
            'counts' => [
                'sources' => Source::query()->count(),
                'active_sources' => Source::query()->where('active_status', 'active')->count(),
                'open_dead_letters' => \App\Models\Raw\DeadLetter::query()->where('status', 'open')->count(),
                'running_ingest_runs' => \App\Models\Raw\IngestRun::query()->where('status', 'running')->count(),
                'pending_canonical_events' => \App\Models\Integration\CanonicalEventRecord::query()->where('projection_status', 'pending')->count(),
            ],
        ];
    }
}
