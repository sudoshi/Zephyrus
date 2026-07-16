<?php

namespace App\Integrations\Healthcare\Services;

use App\Models\Integration\Source;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SourceRegistryService
{
    public function __construct(
        private readonly SourceConfigurationVersionService $versions,
        private readonly SourceLifecycleService $lifecycle,
        private readonly SourceOnboardingService $onboarding,
    ) {}

    public function ensureSource(array $attributes): Source
    {
        $sourceKey = $attributes['source_key'];
        $source = Source::firstOrNew(['source_key' => $sourceKey]);
        $desired = array_merge([
            'tenant_key' => 'default',
            'source_name' => $sourceKey,
            'vendor' => 'synthetic',
            'system_class' => 'test_harness',
            'environment' => 'sandbox',
            'interface_type' => 'synthetic',
            'active_status' => 'testing',
            'contract_status' => 'internal',
            'baa_status' => 'not_required',
            'phi_allowed' => false,
            'go_live_status' => 'not_started',
            'metadata' => [],
        ], $attributes);
        $desiredState = $this->lifecycle->stateForLegacyStatuses(
            (string) $desired['active_status'],
            (string) $desired['go_live_status'],
        );
        if (($desired['environment'] ?? null) === 'production'
            && in_array($desiredState, ['approved', 'scheduled', 'live', 'degraded'], true)) {
            throw ValidationException::withMessages([
                'activation' => 'Managed production sources must cross approval and go-live only through the governed Admin workflow.',
            ]);
        }

        DB::transaction(function () use ($source, $desired, $desiredState): void {
            if (! $source->exists) {
                $source->source_uuid = (string) Str::uuid();
                $source->fill([
                    ...$desired,
                    'active_status' => 'inactive',
                    'go_live_status' => 'not_started',
                    'lifecycle_state' => 'draft',
                ]);
                $source->save();
                $this->versions->initialize(
                    (int) $source->source_id,
                    null,
                    'Initial managed integration source configuration.',
                    (string) Str::uuid(),
                );
                $this->lifecycle->initialize(
                    (int) $source->source_id,
                    null,
                    'Initial managed integration source lifecycle.',
                );
            } else {
                $source->fill($desired);
                $configurationDirty = collect($source->getDirty())->except([
                    'active_status',
                    'go_live_status',
                    'lifecycle_state',
                    'lifecycle_changed_at',
                ])->isNotEmpty();
                if ($configurationDirty) {
                    $configurationApplied = false;
                    $currentVersion = $this->versions->initialize(
                        (int) $source->source_id,
                        null,
                        'Adopt the managed source into immutable configuration authority.',
                        (string) Str::uuid(),
                    );
                    try {
                        $this->versions->reviseAndApply(
                            (int) $source->source_id,
                            $desired,
                            (int) $currentVersion->source_configuration_version_id,
                            null,
                            'Synchronize the managed integration source configuration.',
                            (string) Str::uuid(),
                        );
                        $configurationApplied = true;
                    } catch (ValidationException $exception) {
                        if (! array_key_exists('configuration', $exception->errors())) {
                            throw $exception;
                        }
                    }
                    if ($configurationApplied) {
                        $this->lifecycle->resetAfterConfigurationChange(
                            (int) $source->source_id,
                            'Managed source configuration changed and requires validation.',
                            null,
                        );
                    }
                }
            }

            $this->onboarding->initialize(
                (int) $source->source_id,
                [
                    'protocol_profile' => $desired['interface_type'] ?? null,
                    'owner_name' => is_array($desired['metadata'] ?? null)
                        ? ($desired['metadata']['owner'] ?? null)
                        : null,
                    'data_classification' => (bool) ($desired['phi_allowed'] ?? false)
                        ? 'restricted_phi'
                        : 'confidential',
                ],
                null,
            );

            $this->transitionToDesiredState((int) $source->source_id, $desiredState);
        });

        return $source->fresh();
    }

    private function transitionToDesiredState(int $sourceId, string $desiredState): void
    {
        $current = (string) DB::table('integration.sources')->where('source_id', $sourceId)->value('lifecycle_state');
        if ($current === $desiredState) {
            return;
        }

        $paths = [
            'draft' => [],
            'discovery' => ['discovery'],
            'configured' => ['configured'],
            'validating' => ['configured', 'validating'],
            'approved' => ['configured', 'validating', 'approved'],
            'scheduled' => ['configured', 'validating', 'approved', 'scheduled'],
            'live' => ['configured', 'validating', 'approved', 'scheduled', 'live'],
            'degraded' => ['configured', 'validating', 'approved', 'scheduled', 'live', 'degraded'],
            'suspended' => ['configured', 'validating', 'suspended'],
            'retired' => ['retired'],
        ];
        if ($current !== 'draft') {
            throw ValidationException::withMessages([
                'lifecycle_state' => "Managed source lifecycle {$current} cannot be implicitly changed to {$desiredState}.",
            ]);
        }
        foreach ($paths[$desiredState] as $state) {
            $this->lifecycle->transition(
                $sourceId,
                $state,
                'Managed source registry lifecycle synchronization.',
                null,
                null,
                ['managed_registry' => true],
            );
        }
    }
}
