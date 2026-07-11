<?php

namespace App\Integrations\Healthcare\Services;

use App\Models\Integration\Source;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OperationalIntegrationConfigurator
{
    public function __construct(private readonly IntegrationConfigurationAuditService $audit) {}

    /** @return array<string, mixed> */
    public function configureEpicSandbox(
        ?string $clientId = null,
        ?string $privateKeyRef = null,
        ?string $keyId = null,
        bool $activate = false,
    ): array {
        $settings = config('integrations.epic_sandbox');
        $sourceKey = (string) $settings['source_key'];
        $correlationId = (string) Str::uuid();

        return DB::transaction(function () use ($settings, $sourceKey, $clientId, $privateKeyRef, $keyId, $activate, $correlationId): array {
            $source = Source::query()->firstOrNew(['source_key' => $sourceKey]);
            $before = $source->exists ? $this->epicSummary($source) : [];
            if (! $source->exists) {
                $source->source_uuid = (string) Str::uuid();
            }

            $existingCredential = $source->exists
                ? DB::table('integration.smart_backend_credentials')
                    ->where('source_id', $source->source_id)
                    ->where('credential_key', 'epic-smart-backend')
                    ->first()
                : null;
            $resolvedClientId = $this->normalizedOptionalString(
                filled($clientId) ? $clientId : ($existingCredential?->client_id ?: $settings['client_id']),
            );
            $resolvedKeyRef = $this->normalizedOptionalString(
                filled($privateKeyRef) ? $privateKeyRef : ($existingCredential?->jwks_secret_ref ?: $settings['private_key_ref']),
            );
            $resolvedKeyId = $this->normalizedOptionalString(
                filled($keyId) ? $keyId : ($this->decodeMap($existingCredential?->metadata ?? null)['key_id'] ?? $settings['key_id']),
            );
            $credentialsReady = filled($resolvedClientId) && filled($resolvedKeyRef);

            if ($activate && ! $credentialsReady) {
                throw ValidationException::withMessages([
                    'credentials' => 'Epic sandbox activation requires both a registered client ID and a private-key reference.',
                ]);
            }

            $source->fill([
                'tenant_key' => 'default',
                'facility_key' => null,
                'source_name' => 'Epic FHIR R4 Public Sandbox',
                'vendor' => 'Epic',
                'system_class' => 'ehr',
                'environment' => 'sandbox',
                'base_url' => $settings['base_url'],
                'interface_type' => 'fhir_r4',
                'active_status' => $activate ? 'active' : ($source->active_status ?: 'testing'),
                'fhir_version' => '4.0.1',
                'smart_supported' => true,
                'bulk_supported' => false,
                'subscriptions_supported' => false,
                'contract_status' => 'public_sandbox',
                'baa_status' => 'not_required',
                'phi_allowed' => false,
                'go_live_status' => $activate ? 'validation' : ($source->go_live_status ?: 'not_started'),
                'metadata' => [
                    'owner' => 'Integration governance',
                    'expected_cadence_minutes' => 15,
                    'managed_by' => 'integrations:configure-operational-sources',
                    'data_classification' => 'synthetic_sandbox',
                ],
            ]);
            $source->save();

            foreach ([
                'fhir_base' => [$settings['base_url'], 'smart_backend_services'],
                'smart_configuration' => [$settings['smart_configuration_url'], 'none'],
                'oauth_token' => [$settings['token_url'], 'private_key_jwt'],
            ] as $type => [$url, $authType]) {
                DB::table('integration.source_endpoints')->updateOrInsert(
                    ['source_id' => $source->source_id, 'endpoint_type' => $type],
                    [
                        'url' => $url,
                        'auth_type' => $authType,
                        'tls_mode' => 'tls_1_2_or_newer',
                        'is_active' => true,
                        'metadata' => json_encode(['managed' => true], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }

            DB::table('integration.fhir_client_connections')->updateOrInsert(
                ['source_id' => $source->source_id, 'connection_key' => 'epic-fhir-r4-sandbox'],
                [
                    'connection_uuid' => $this->stableUuid(
                        'integration.fhir_client_connections',
                        ['source_id' => $source->source_id, 'connection_key' => 'epic-fhir-r4-sandbox'],
                        'connection_uuid',
                    ),
                    'status' => $credentialsReady ? 'configured' : 'discovery_only',
                    'base_url' => $settings['base_url'],
                    'fhir_version' => '4.0.1',
                    'polling_payload' => json_encode([
                        'resource_types' => ['Encounter', 'Location'],
                        'cadence_minutes' => 15,
                        'credential_activation_required' => ! $credentialsReady,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            DB::table('integration.smart_backend_credentials')->updateOrInsert(
                ['source_id' => $source->source_id, 'credential_key' => 'epic-smart-backend'],
                [
                    'credential_uuid' => $this->stableUuid(
                        'integration.smart_backend_credentials',
                        ['source_id' => $source->source_id, 'credential_key' => 'epic-smart-backend'],
                        'credential_uuid',
                    ),
                    'status' => $credentialsReady ? 'configured' : 'activation_required',
                    'client_id' => $resolvedClientId,
                    'jwks_secret_ref' => $resolvedKeyRef,
                    'token_url' => $settings['token_url'],
                    'scope_payload' => json_encode($settings['default_scopes'], JSON_THROW_ON_ERROR),
                    'metadata' => json_encode(array_filter([
                        'auth_method' => 'private_key_jwt',
                        'algorithm' => 'RS384',
                        'key_id' => $resolvedKeyId,
                    ], fn (mixed $value): bool => filled($value)), JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $after = $this->epicSummary($source->fresh());
            if ($before !== $after) {
                $this->audit->record(null, $before === [] ? 'created' : 'updated', 'operational_connector', (int) $source->source_id, $sourceKey, $before, $after, $correlationId);
            }

            return $after;
        });
    }

    /** @return array<string, mixed> */
    public function configureHl7Boundary(): array
    {
        $key = 'patient-flow-hl7v2-ingress';
        $existing = DB::table('integration.interface_engines')->where('engine_key', $key)->first();
        $before = $existing ? $this->hl7Summary($existing) : [];

        DB::table('integration.interface_engines')->updateOrInsert(
            ['engine_key' => $key],
            [
                'interface_engine_uuid' => $existing?->interface_engine_uuid ?? (string) Str::uuid(),
                'label' => 'Patient Flow HL7 v2 ADT Ingress',
                'engine_type' => 'canonical_https_gateway',
                'environment' => app()->environment('production') ? 'production' : 'sandbox',
                'status' => 'ready',
                'boundary_payload' => json_encode([
                    'protocol' => 'hl7v2_adt',
                    'transport' => 'https',
                    'route' => '/api/integrations/v1/patient-flow/hl7v2',
                    'authentication' => 'sanctum_machine_token',
                    'required_ability' => 'integration:patient-flow:ingest',
                    'pipeline' => ['raw', 'canonical', 'patient_flow_projection', 'provenance'],
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $after = $this->hl7Summary(DB::table('integration.interface_engines')->where('engine_key', $key)->first());
        if ($before !== $after) {
            $this->audit->record(null, $before === [] ? 'created' : 'updated', 'interface_engine', (int) $after['interfaceEngineId'], $key, $before, $after, (string) Str::uuid());
        }

        return $after;
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    public function configureHl7Source(array $attributes): array
    {
        $sourceKey = trim((string) $attributes['source_key']);
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,159}$/', $sourceKey) !== 1) {
            throw ValidationException::withMessages(['source_key' => 'The HL7 source key is invalid.']);
        }
        $activate = (bool) ($attributes['activate'] ?? false);
        if ($activate && (
            ($attributes['environment'] ?? null) !== 'production'
            || ($attributes['contract_status'] ?? null) !== 'executed'
            || ($attributes['baa_status'] ?? null) !== 'executed'
            || ! ($attributes['phi_allowed'] ?? false)
            || ($attributes['go_live_status'] ?? null) !== 'live'
        )) {
            throw ValidationException::withMessages([
                'activation' => 'An active HL7 ADT source requires production/live state, executed contract and BAA, and explicit PHI approval.',
            ]);
        }

        return DB::transaction(function () use ($attributes, $sourceKey, $activate): array {
            $source = Source::query()->firstOrNew(['source_key' => $sourceKey]);
            $before = $source->exists ? $this->hl7SourceSummary($source) : [];
            if (! $source->exists) {
                $source->source_uuid = (string) Str::uuid();
            }
            $source->fill([
                'tenant_key' => 'default',
                'facility_key' => $attributes['facility_key'] ?? 'main',
                'source_name' => $attributes['source_name'],
                'vendor' => $attributes['vendor'],
                'system_class' => 'ehr',
                'environment' => $attributes['environment'],
                'base_url' => null,
                'interface_type' => 'hl7v2',
                'active_status' => $activate ? 'active' : 'inactive',
                'fhir_version' => null,
                'smart_supported' => false,
                'bulk_supported' => false,
                'subscriptions_supported' => false,
                'contract_status' => $attributes['contract_status'],
                'baa_status' => $attributes['baa_status'],
                'phi_allowed' => (bool) $attributes['phi_allowed'],
                'go_live_status' => $attributes['go_live_status'],
                'metadata' => [
                    'owner' => 'Integration governance',
                    'expected_cadence_minutes' => 5,
                    'managed_by' => 'integrations:configure-hl7-adt-source',
                    'ingress_route' => '/api/integrations/v1/patient-flow/hl7v2',
                ],
            ]);
            $source->save();

            $after = $this->hl7SourceSummary($source->fresh());
            if ($before !== $after) {
                $this->audit->record(null, $before === [] ? 'created' : 'updated', 'operational_connector', (int) $source->source_id, $sourceKey, $before, $after, (string) Str::uuid());
            }

            return $after;
        });
    }

    /** @return array<string, mixed> */
    private function epicSummary(Source $source): array
    {
        $credential = DB::table('integration.smart_backend_credentials')
            ->where('source_id', $source->source_id)
            ->where('credential_key', 'epic-smart-backend')
            ->first();

        return [
            'sourceId' => (int) $source->source_id,
            'sourceKey' => $source->source_key,
            'status' => $source->active_status,
            'goLiveStatus' => $source->go_live_status,
            'credentialStatus' => $credential?->status ?? 'activation_required',
            'clientIdConfigured' => filled($credential?->client_id),
            'privateKeyReferenceConfigured' => filled($credential?->jwks_secret_ref),
            'baseUrl' => (string) config('integrations.epic_sandbox.base_url'),
        ];
    }

    /** @return array<string, mixed> */
    private function hl7Summary(object $engine): array
    {
        return [
            'interfaceEngineId' => (int) $engine->interface_engine_id,
            'engineKey' => $engine->engine_key,
            'status' => $engine->status,
            'environment' => $engine->environment,
            'route' => '/api/integrations/v1/patient-flow/hl7v2',
        ];
    }

    /** @return array<string, mixed> */
    private function hl7SourceSummary(Source $source): array
    {
        return [
            'sourceId' => (int) $source->source_id,
            'sourceKey' => $source->source_key,
            'sourceName' => $source->source_name,
            'vendor' => $source->vendor,
            'environment' => $source->environment,
            'activeStatus' => $source->active_status,
            'contractStatus' => $source->contract_status,
            'baaStatus' => $source->baa_status,
            'phiAllowed' => (bool) $source->phi_allowed,
            'goLiveStatus' => $source->go_live_status,
            'ingressRoute' => '/api/integrations/v1/patient-flow/hl7v2',
        ];
    }

    /** @param array<string, mixed> $keys */
    private function stableUuid(string $table, array $keys, string $uuidColumn): string
    {
        return (string) (DB::table($table)->where($keys)->value($uuidColumn) ?? Str::uuid());
    }

    private function normalizedOptionalString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    /** @return array<string, mixed> */
    private function decodeMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return is_string($value) ? (json_decode($value, true) ?: []) : [];
    }
}
