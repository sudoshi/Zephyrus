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
}
