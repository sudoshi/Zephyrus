<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FhirConformanceObservationService
{
    private const MAX_RESOURCES = 500;

    /**
     * @param  array<string, mixed>  $statement
     * @param  array<string, mixed>  $smart
     * @return array<string, mixed>
     */
    public function capture(int $sourceId, int $connectionId, array $statement, array $smart): array
    {
        $normalized = $this->normalize($statement, $smart);

        return DB::transaction(function () use ($sourceId, $connectionId, $normalized): array {
            $connection = DB::table('integration.fhir_client_connections')
                ->where('fhir_client_connection_id', $connectionId)
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->first();
            if ($connection === null) {
                throw new IntegrationProtocolException('fhir_connection_authority_mismatch');
            }

            $observationId = (int) DB::table('integration.fhir_conformance_observations')->insertGetId([
                'observation_uuid' => (string) Str::uuid7(),
                'source_id' => $sourceId,
                'fhir_client_connection_id' => $connectionId,
                'previous_observation_id' => $connection->current_conformance_observation_id,
                'observation_status' => $normalized['warnings'] === [] ? 'passed' : 'passed_with_warnings',
                'capability_document_sha256' => $normalized['capabilityDocumentSha256'],
                'smart_document_sha256' => $normalized['smartDocumentSha256'],
                'fhir_version' => $normalized['fhirVersion'],
                'capability_kind' => $normalized['capabilityKind'],
                'capability_status' => $normalized['capabilityStatus'],
                'capability_date' => $normalized['capabilityDate'],
                'software_name' => $normalized['softwareName'],
                'software_version' => $normalized['softwareVersion'],
                'implementation_origin' => $normalized['implementationOrigin'],
                'format_payload' => $this->json($normalized['formats']),
                'patch_format_payload' => $this->json($normalized['patchFormats']),
                'implementation_guide_payload' => $this->json($normalized['implementationGuides']),
                'system_interaction_payload' => $this->json($normalized['systemInteractions']),
                'system_operation_payload' => $this->json($normalized['systemOperations']),
                'compartment_payload' => $this->json($normalized['compartments']),
                'security_service_payload' => $this->json($normalized['securityServices']),
                'smart_issuer_url' => $normalized['smart']['issuer'],
                'smart_jwks_url' => $normalized['smart']['jwksUrl'],
                'smart_authorization_url' => $normalized['smart']['authorizationUrl'],
                'smart_token_url' => $normalized['smart']['tokenUrl'],
                'smart_registration_url' => $normalized['smart']['registrationUrl'],
                'smart_management_url' => $normalized['smart']['managementUrl'],
                'smart_introspection_url' => $normalized['smart']['introspectionUrl'],
                'smart_grant_type_payload' => $this->json($normalized['smart']['grantTypes']),
                'smart_token_auth_method_payload' => $this->json($normalized['smart']['tokenAuthMethods']),
                'smart_token_signing_algorithm_payload' => $this->json($normalized['smart']['tokenSigningAlgorithms']),
                'smart_scope_payload' => $this->json($normalized['smart']['scopes']),
                'smart_capability_payload' => $this->json($normalized['smart']['capabilities']),
                'smart_pkce_method_payload' => $this->json($normalized['smart']['pkceMethods']),
                'smart_associated_endpoint_payload' => $this->json($normalized['smart']['associatedEndpoints']),
                'supports_batch' => in_array('batch', $normalized['systemInteractions'], true),
                'supports_transaction' => in_array('transaction', $normalized['systemInteractions'], true),
                'supports_system_history' => in_array('history-system', $normalized['systemInteractions'], true),
                'supports_system_search' => in_array('search-system', $normalized['systemInteractions'], true),
                'supports_bulk_data' => $normalized['supportsBulkData'],
                'supports_subscriptions' => $normalized['supportsSubscriptions'],
                'resource_count' => count($normalized['resources']),
                'searchable_resource_count' => count($normalized['searchableResourceTypes']),
                'search_parameter_count' => $normalized['searchParameterCount'],
                'operation_count' => $normalized['operationCount'],
                'warning_code_payload' => $this->json($normalized['warnings']),
                'observed_at' => now(),
                'created_at' => now(),
            ], 'fhir_conformance_observation_id');

            foreach ($normalized['resources'] as $resource) {
                DB::table('integration.fhir_conformance_resource_observations')->insert([
                    'fhir_conformance_observation_id' => $observationId,
                    'source_id' => $sourceId,
                    'resource_type' => $resource['resourceType'],
                    'base_profile_url' => $resource['baseProfileUrl'],
                    'supported_profile_payload' => $this->json($resource['supportedProfiles']),
                    'interaction_payload' => $this->json($resource['interactions']),
                    'versioning' => $resource['versioning'],
                    'read_history' => $resource['readHistory'],
                    'update_create' => $resource['updateCreate'],
                    'conditional_create' => $resource['conditionalCreate'],
                    'conditional_read' => $resource['conditionalRead'],
                    'conditional_update' => $resource['conditionalUpdate'],
                    'conditional_delete' => $resource['conditionalDelete'],
                    'search_include_payload' => $this->json($resource['searchIncludes']),
                    'search_revinclude_payload' => $this->json($resource['searchRevIncludes']),
                    'search_parameter_payload' => $this->json($resource['searchParameters']),
                    'operation_payload' => $this->json($resource['operations']),
                    'search_parameter_count' => count($resource['searchParameters']),
                    'operation_count' => count($resource['operations']),
                    'created_at' => now(),
                ]);
            }

            DB::table('integration.fhir_client_connections')
                ->where('fhir_client_connection_id', $connectionId)
                ->update(['current_conformance_observation_id' => $observationId]);

            return [
                ...$normalized,
                'observationId' => $observationId,
            ];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function latestForSource(int $sourceId): array
    {
        $observation = DB::table('integration.fhir_client_connections as connection')
            ->join(
                'integration.fhir_conformance_observations as observation',
                'observation.fhir_conformance_observation_id',
                '=',
                'connection.current_conformance_observation_id',
            )
            ->where('connection.source_id', $sourceId)
            ->orderBy('connection.fhir_client_connection_id')
            ->first(['observation.*']);
        if ($observation === null) {
            return ['status' => 'unobserved', 'sourceId' => $sourceId, 'resources' => []];
        }

        $resources = DB::table('integration.fhir_conformance_resource_observations')
            ->where('fhir_conformance_observation_id', $observation->fhir_conformance_observation_id)
            ->orderBy('resource_type')
            ->get()
            ->map(fn (object $resource): array => [
                'resourceType' => (string) $resource->resource_type,
                'baseProfileUrl' => $resource->base_profile_url,
                'supportedProfiles' => $this->decodeList($resource->supported_profile_payload),
                'interactions' => $this->decodeList($resource->interaction_payload),
                'versioning' => $resource->versioning,
                'readHistory' => (bool) $resource->read_history,
                'updateCreate' => (bool) $resource->update_create,
                'conditionalCreate' => $resource->conditional_create === null ? null : (bool) $resource->conditional_create,
                'conditionalRead' => $resource->conditional_read,
                'conditionalUpdate' => $resource->conditional_update === null ? null : (bool) $resource->conditional_update,
                'conditionalDelete' => $resource->conditional_delete,
                'searchIncludes' => $this->decodeList($resource->search_include_payload),
                'searchRevIncludes' => $this->decodeList($resource->search_revinclude_payload),
                'searchParameters' => $this->decodeList($resource->search_parameter_payload),
                'operations' => $this->decodeList($resource->operation_payload),
            ])->all();

        return [
            'status' => (string) $observation->observation_status,
            'sourceId' => $sourceId,
            'connectionId' => (int) $observation->fhir_client_connection_id,
            'observationId' => (int) $observation->fhir_conformance_observation_id,
            'observedAtIso' => CarbonImmutable::parse($observation->observed_at)->toIso8601String(),
            'fhirVersion' => (string) $observation->fhir_version,
            'capabilityKind' => $observation->capability_kind,
            'capabilityStatus' => $observation->capability_status,
            'capabilityDateIso' => $observation->capability_date === null
                ? null
                : CarbonImmutable::parse($observation->capability_date)->toIso8601String(),
            'softwareName' => $observation->software_name,
            'softwareVersion' => $observation->software_version,
            'implementationOrigin' => $observation->implementation_origin,
            'formats' => $this->decodeList($observation->format_payload),
            'patchFormats' => $this->decodeList($observation->patch_format_payload),
            'implementationGuides' => $this->decodeList($observation->implementation_guide_payload),
            'systemInteractions' => $this->decodeList($observation->system_interaction_payload),
            'systemOperations' => $this->decodeList($observation->system_operation_payload),
            'compartments' => $this->decodeList($observation->compartment_payload),
            'securityServices' => $this->decodeList($observation->security_service_payload),
            'supportsBatch' => (bool) $observation->supports_batch,
            'supportsTransaction' => (bool) $observation->supports_transaction,
            'supportsSystemHistory' => (bool) $observation->supports_system_history,
            'supportsSystemSearch' => (bool) $observation->supports_system_search,
            'supportsBulkData' => (bool) $observation->supports_bulk_data,
            'supportsSubscriptions' => (bool) $observation->supports_subscriptions,
            'resourceCount' => (int) $observation->resource_count,
            'searchableResourceCount' => (int) $observation->searchable_resource_count,
            'searchParameterCount' => (int) $observation->search_parameter_count,
            'operationCount' => (int) $observation->operation_count,
            'warnings' => $this->decodeList($observation->warning_code_payload),
            'documentHashes' => [
                'capabilityStatement' => $observation->capability_document_sha256,
                'smartConfiguration' => $observation->smart_document_sha256,
            ],
            'smart' => [
                'issuerOrigin' => $this->origin($observation->smart_issuer_url),
                'jwksOrigin' => $this->origin($observation->smart_jwks_url),
                'authorizationOrigin' => $this->origin($observation->smart_authorization_url),
                'tokenOrigin' => $this->origin($observation->smart_token_url),
                'registrationOrigin' => $this->origin($observation->smart_registration_url),
                'managementOrigin' => $this->origin($observation->smart_management_url),
                'introspectionOrigin' => $this->origin($observation->smart_introspection_url),
                'grantTypes' => $this->decodeList($observation->smart_grant_type_payload),
                'tokenAuthMethods' => $this->decodeList($observation->smart_token_auth_method_payload),
                'tokenSigningAlgorithms' => $this->decodeList($observation->smart_token_signing_algorithm_payload),
                'scopes' => $this->decodeList($observation->smart_scope_payload),
                'capabilities' => $this->decodeList($observation->smart_capability_payload),
                'pkceMethods' => $this->decodeList($observation->smart_pkce_method_payload),
                'associatedEndpoints' => collect($this->decodeList($observation->smart_associated_endpoint_payload))
                    ->map(fn (mixed $endpoint): array => [
                        'origin' => is_array($endpoint) ? $this->origin($endpoint['url'] ?? null) : null,
                        'capabilities' => is_array($endpoint) ? ($endpoint['capabilities'] ?? []) : [],
                    ])->all(),
            ],
            'resources' => $resources,
        ];
    }

    /**
     * @param  array<string, mixed>  $statement
     * @param  array<string, mixed>  $smart
     * @return array<string, mixed>
     */
    private function normalize(array $statement, array $smart): array
    {
        if (($statement['resourceType'] ?? null) !== 'CapabilityStatement') {
            throw new IntegrationProtocolException('fhir_capability_statement_invalid');
        }
        $fhirVersion = $this->requiredString($statement['fhirVersion'] ?? null, 40, 'fhir_version_missing');
        if ($fhirVersion !== '4.0.1') {
            throw new IntegrationProtocolException('fhir_r4_version_required');
        }
        $formats = $this->strings($statement['format'] ?? null, 20, 120, 'fhir_format_invalid');
        if ($formats === [] || collect($formats)->contains(fn (string $format): bool => in_array(strtolower($format), ['json', 'application/json', 'application/fhir+json'], true)) === false) {
            throw new IntegrationProtocolException('fhir_json_format_required');
        }
        $capabilityKind = $this->requiredString($statement['kind'] ?? null, 30, 'fhir_capability_kind_missing');
        if (! in_array($capabilityKind, ['instance', 'capability', 'requirements'], true)) {
            throw new IntegrationProtocolException('fhir_capability_kind_invalid');
        }
        $capabilityStatus = $this->requiredString($statement['status'] ?? null, 30, 'fhir_capability_status_missing');
        if (! in_array($capabilityStatus, ['draft', 'active', 'retired', 'unknown'], true)) {
            throw new IntegrationProtocolException('fhir_capability_status_invalid');
        }
        $capabilityDate = $this->date($statement['date'] ?? null);
        if ($capabilityDate === null) {
            throw new IntegrationProtocolException('fhir_capability_date_missing');
        }

        $restEntries = is_array($statement['rest'] ?? null) ? $statement['rest'] : [];
        if (count($restEntries) > 10) {
            throw new IntegrationProtocolException('fhir_capability_limit_exceeded');
        }
        $serverRest = collect($restEntries)->first(fn (mixed $rest): bool => is_array($rest) && ($rest['mode'] ?? null) === 'server');
        if (! is_array($serverRest)) {
            throw new IntegrationProtocolException('fhir_server_rest_capability_required');
        }

        $resources = $this->resources($serverRest['resource'] ?? null);
        $systemInteractions = $this->interactionCodes($serverRest['interaction'] ?? null, 20);
        $systemOperations = $this->operations($serverRest['operation'] ?? null, 100);
        $compartments = $this->canonicalUris($serverRest['compartment'] ?? null, 100, 'fhir_compartment_invalid');
        $warnings = [];
        $smartNormalized = $this->smart($smart, $warnings);
        $searchableResourceTypes = collect($resources)
            ->filter(fn (array $resource): bool => in_array('search-type', $resource['interactions'], true))
            ->pluck('resourceType')->values()->all();
        $supportsBulkData = collect($systemOperations)->contains(
            fn (array $operation): bool => str_contains(strtolower($operation['name']), 'export')
                || str_contains(strtolower($operation['definition']), 'export'),
        ) || collect($smartNormalized['associatedEndpoints'])->contains(
            fn (array $endpoint): bool => collect($endpoint['capabilities'])->contains(
                fn (string $capability): bool => str_contains(strtolower($capability), 'bulk'),
            ),
        );

        return [
            'capabilityDocumentSha256' => hash('sha256', $this->canonicalJson($statement)),
            'smartDocumentSha256' => hash('sha256', $this->canonicalJson($smart)),
            'fhirVersion' => $fhirVersion,
            'capabilityKind' => $capabilityKind,
            'capabilityStatus' => $capabilityStatus,
            'capabilityDate' => $capabilityDate,
            'softwareName' => $this->optionalString(data_get($statement, 'software.name'), 160, 'fhir_software_name_invalid'),
            'softwareVersion' => $this->optionalString(data_get($statement, 'software.version'), 80, 'fhir_software_version_invalid'),
            'implementationOrigin' => $this->origin($this->canonicalUri(data_get($statement, 'implementation.url'), false, 'fhir_implementation_url_invalid')),
            'formats' => $formats,
            'patchFormats' => $this->strings($statement['patchFormat'] ?? null, 20, 120, 'fhir_patch_format_invalid'),
            'implementationGuides' => $this->canonicalUris($statement['implementationGuide'] ?? null, 100, 'fhir_implementation_guide_invalid'),
            'systemInteractions' => $systemInteractions,
            'systemOperations' => $systemOperations,
            'compartments' => $compartments,
            'securityServices' => $this->securityServices(data_get($serverRest, 'security.service', [])),
            'resources' => $resources,
            'searchableResourceTypes' => $searchableResourceTypes,
            'searchParameterCount' => collect($resources)->sum(fn (array $resource): int => count($resource['searchParameters'])),
            'operationCount' => count($systemOperations) + collect($resources)->sum(fn (array $resource): int => count($resource['operations'])),
            'supportsBulkData' => $supportsBulkData,
            'supportsSubscriptions' => collect($resources)->contains(fn (array $resource): bool => $resource['resourceType'] === 'Subscription'),
            'warnings' => array_values(array_unique($warnings)),
            'smart' => $smartNormalized,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function resources(mixed $value): array
    {
        if (! is_array($value) || count($value) > self::MAX_RESOURCES) {
            throw new IntegrationProtocolException('fhir_capability_limit_exceeded');
        }
        $resources = [];
        foreach ($value as $resource) {
            if (! is_array($resource)) {
                throw new IntegrationProtocolException('fhir_resource_capability_invalid');
            }
            $type = $this->requiredString($resource['type'] ?? null, 80, 'fhir_resource_type_invalid');
            if (preg_match('/^[A-Z][A-Za-z]{1,79}$/', $type) !== 1 || isset($resources[$type])) {
                throw new IntegrationProtocolException('fhir_resource_type_invalid');
            }
            $resources[$type] = [
                'resourceType' => $type,
                'baseProfileUrl' => $this->canonicalUri($resource['profile'] ?? null, false, 'fhir_profile_url_invalid'),
                'supportedProfiles' => $this->canonicalUris($resource['supportedProfile'] ?? null, 100, 'fhir_supported_profile_invalid'),
                'interactions' => $this->interactionCodes($resource['interaction'] ?? null, 20),
                'versioning' => $this->optionalString($resource['versioning'] ?? null, 30, 'fhir_versioning_invalid'),
                'readHistory' => $this->bool($resource, 'readHistory', false),
                'updateCreate' => $this->bool($resource, 'updateCreate', false),
                'conditionalCreate' => $this->nullableBool($resource, 'conditionalCreate'),
                'conditionalRead' => $this->optionalString($resource['conditionalRead'] ?? null, 30, 'fhir_conditional_read_invalid'),
                'conditionalUpdate' => $this->nullableBool($resource, 'conditionalUpdate'),
                'conditionalDelete' => $this->optionalString($resource['conditionalDelete'] ?? null, 30, 'fhir_conditional_delete_invalid'),
                'searchIncludes' => $this->strings($resource['searchInclude'] ?? null, 500, 160, 'fhir_search_include_invalid'),
                'searchRevIncludes' => $this->strings($resource['searchRevInclude'] ?? null, 500, 160, 'fhir_search_revinclude_invalid'),
                'searchParameters' => $this->searchParameters($resource['searchParam'] ?? null),
                'operations' => $this->operations($resource['operation'] ?? null, 100),
            ];
        }
        ksort($resources);

        return array_values($resources);
    }

    /** @param list<string> $warnings
     * @return array<string, mixed>
     */
    private function smart(array $smart, array &$warnings): array
    {
        $capabilities = $this->strings($smart['capabilities'] ?? null, 500, 500, 'smart_capabilities_invalid');
        if ($capabilities === []) {
            throw new IntegrationProtocolException('smart_capabilities_missing');
        }
        $grantTypes = $this->strings($smart['grant_types_supported'] ?? null, 20, 80, 'smart_grant_types_invalid');
        if (! in_array('client_credentials', $grantTypes, true)) {
            throw new IntegrationProtocolException('smart_client_credentials_grant_required');
        }
        $tokenUrl = $this->endpoint($smart['token_endpoint'] ?? null, true, 'smart_token_endpoint_missing');
        $tokenAuthMethods = $this->strings($smart['token_endpoint_auth_methods_supported'] ?? null, 20, 80, 'smart_token_auth_methods_invalid');
        if ($tokenAuthMethods === []) {
            $warnings[] = 'smart_token_auth_methods_undisclosed';
        } elseif (in_array('client-confidential-asymmetric', $capabilities, true)
            && ! in_array('private_key_jwt', $tokenAuthMethods, true)) {
            throw new IntegrationProtocolException('smart_private_key_jwt_required');
        }
        $scopes = $this->strings($smart['scopes_supported'] ?? null, 1000, 500, 'smart_scopes_invalid');
        if ($scopes === []) {
            $warnings[] = 'smart_scopes_undisclosed';
        }
        $pkceMethods = $this->strings($smart['code_challenge_methods_supported'] ?? null, 20, 80, 'smart_pkce_methods_invalid');
        $interactive = in_array('authorization_code', $grantTypes, true)
            || array_intersect(['launch-ehr', 'launch-standalone'], $capabilities) !== [];
        if ($interactive && (! in_array('S256', $pkceMethods, true) || in_array('plain', $pkceMethods, true))) {
            throw new IntegrationProtocolException('smart_pkce_s256_required');
        }
        $issuer = $this->endpoint($smart['issuer'] ?? null, false, 'smart_issuer_invalid');
        $jwks = $this->endpoint($smart['jwks_uri'] ?? null, false, 'smart_jwks_uri_invalid');
        if (in_array('sso-openid-connect', $capabilities, true) && ($issuer === null || $jwks === null)) {
            throw new IntegrationProtocolException('smart_openid_metadata_required');
        }
        $authorization = $this->endpoint($smart['authorization_endpoint'] ?? null, false, 'smart_authorization_endpoint_invalid');
        if ($interactive && $authorization === null) {
            throw new IntegrationProtocolException('smart_authorization_endpoint_missing');
        }

        return [
            'issuer' => $issuer,
            'jwksUrl' => $jwks,
            'authorizationUrl' => $authorization,
            'tokenUrl' => $tokenUrl,
            'registrationUrl' => $this->endpoint($smart['registration_endpoint'] ?? null, false, 'smart_registration_endpoint_invalid'),
            'managementUrl' => $this->endpoint($smart['management_endpoint'] ?? null, false, 'smart_management_endpoint_invalid'),
            'introspectionUrl' => $this->endpoint($smart['introspection_endpoint'] ?? null, false, 'smart_introspection_endpoint_invalid'),
            'grantTypes' => $grantTypes,
            'tokenAuthMethods' => $tokenAuthMethods,
            'tokenSigningAlgorithms' => $this->strings($smart['token_endpoint_auth_signing_alg_values_supported'] ?? null, 50, 80, 'smart_token_algorithms_invalid'),
            'scopes' => $scopes,
            'capabilities' => $capabilities,
            'pkceMethods' => $pkceMethods,
            'associatedEndpoints' => $this->associatedEndpoints($smart['associated_endpoints'] ?? null),
        ];
    }

    /** @return list<array{name:string,definition:string,type?:string}> */
    private function searchParameters(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value) || count($value) > 500) {
            throw new IntegrationProtocolException('fhir_search_parameter_limit_exceeded');
        }
        $parameters = [];
        foreach ($value as $parameter) {
            if (! is_array($parameter)) {
                throw new IntegrationProtocolException('fhir_search_parameter_invalid');
            }
            $name = $this->requiredString($parameter['name'] ?? null, 160, 'fhir_search_parameter_invalid');
            $definition = $this->canonicalUri($parameter['definition'] ?? null, false, 'fhir_search_parameter_definition_invalid');
            $type = $this->requiredString($parameter['type'] ?? null, 30, 'fhir_search_parameter_type_invalid');
            $parameters[$name] = ['name' => $name, 'definition' => $definition, 'type' => $type];
        }
        ksort($parameters);

        return array_values($parameters);
    }

    /** @return list<array{name:string,definition:string}> */
    private function operations(mixed $value, int $limit): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value) || count($value) > $limit) {
            throw new IntegrationProtocolException('fhir_operation_limit_exceeded');
        }
        $operations = [];
        foreach ($value as $operation) {
            if (! is_array($operation)) {
                throw new IntegrationProtocolException('fhir_operation_invalid');
            }
            $name = $this->requiredString($operation['name'] ?? null, 120, 'fhir_operation_invalid');
            $definition = $this->canonicalUri($operation['definition'] ?? null, true, 'fhir_operation_definition_invalid');
            $operations[$name.'|'.$definition] = ['name' => $name, 'definition' => $definition];
        }
        ksort($operations);

        return array_values($operations);
    }

    /** @return list<string> */
    private function interactionCodes(mixed $value, int $limit): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value) || count($value) > $limit) {
            throw new IntegrationProtocolException('fhir_interaction_limit_exceeded');
        }

        return $this->strings(
            array_map(fn (mixed $interaction): mixed => is_array($interaction) ? ($interaction['code'] ?? null) : null, $value),
            $limit,
            40,
            'fhir_interaction_invalid',
        );
    }

    /** @return list<array{system:?string,code:string}> */
    private function securityServices(mixed $value): array
    {
        if (! is_array($value) || count($value) > 20) {
            return [];
        }
        $services = [];
        foreach ($value as $service) {
            foreach ((array) (is_array($service) ? ($service['coding'] ?? []) : []) as $coding) {
                if (! is_array($coding) || ! is_string($coding['code'] ?? null)) {
                    continue;
                }
                $code = $this->requiredString($coding['code'], 120, 'fhir_security_service_invalid');
                $system = $this->canonicalUri($coding['system'] ?? null, false, 'fhir_security_service_system_invalid');
                $services[$code.'|'.$system] = ['system' => $system, 'code' => $code];
            }
        }
        ksort($services);

        return array_values($services);
    }

    /** @return list<array{url:string,capabilities:list<string>}> */
    private function associatedEndpoints(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value) || count($value) > 50) {
            throw new IntegrationProtocolException('smart_associated_endpoint_limit_exceeded');
        }
        $endpoints = [];
        foreach ($value as $endpoint) {
            if (! is_array($endpoint)) {
                throw new IntegrationProtocolException('smart_associated_endpoint_invalid');
            }
            $url = $this->endpoint($endpoint['url'] ?? null, true, 'smart_associated_endpoint_invalid');
            $endpoints[$url] = [
                'url' => $url,
                'capabilities' => $this->strings($endpoint['capabilities'] ?? null, 100, 500, 'smart_associated_capabilities_invalid'),
            ];
        }
        ksort($endpoints);

        return array_values($endpoints);
    }

    /** @return list<string> */
    private function canonicalUris(mixed $value, int $limit, string $errorCode): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value) || count($value) > $limit) {
            throw new IntegrationProtocolException($errorCode);
        }
        $uris = [];
        foreach ($value as $uri) {
            $uris[] = $this->canonicalUri($uri, true, $errorCode);
        }
        sort($uris);

        return array_values(array_unique($uris));
    }

    /** @return list<string> */
    private function strings(mixed $value, int $limit, int $maxLength, string $errorCode): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value) || count($value) > $limit) {
            throw new IntegrationProtocolException($errorCode);
        }
        $strings = [];
        foreach ($value as $string) {
            if (! is_string($string) || $string === '' || strlen($string) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $string) === 1) {
                throw new IntegrationProtocolException($errorCode);
            }
            $strings[] = $string;
        }
        sort($strings);

        return array_values(array_unique($strings));
    }

    private function endpoint(mixed $value, bool $required, string $errorCode): ?string
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new IntegrationProtocolException($errorCode);
            }

            return null;
        }
        if (! is_string($value) || strlen($value) > 2048 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new IntegrationProtocolException($errorCode);
        }
        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($host === '' || ! in_array($scheme, ['http', 'https'], true) || ($scheme !== 'https' && ! $loopback)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new IntegrationProtocolException($errorCode);
        }

        return $value;
    }

    private function canonicalUri(mixed $value, bool $required, string $errorCode): ?string
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new IntegrationProtocolException($errorCode);
            }

            return null;
        }
        if (! is_string($value) || strlen($value) > 2048
            || preg_match('/[\x00-\x20\x7F]/', $value) === 1
            || preg_match('/^([A-Za-z][A-Za-z0-9+.-]*):/', $value, $matches) !== 1
            || ! in_array(strtolower($matches[1]), ['http', 'https', 'urn'], true)) {
            throw new IntegrationProtocolException($errorCode);
        }

        if (in_array(strtolower($matches[1]), ['http', 'https'], true)) {
            $unversioned = explode('|', $value, 2)[0];
            $parts = parse_url($unversioned);
            if (! is_array($parts) || ! isset($parts['host']) || $parts['host'] === ''
                || isset($parts['user']) || isset($parts['pass'])) {
                throw new IntegrationProtocolException($errorCode);
            }
        } elseif (strlen($value) <= 4) {
            throw new IntegrationProtocolException($errorCode);
        }

        return $value;
    }

    private function requiredString(mixed $value, int $maxLength, string $errorCode): string
    {
        $string = $this->optionalString($value, $maxLength, $errorCode);
        if ($string === null) {
            throw new IntegrationProtocolException($errorCode);
        }

        return $string;
    }

    private function optionalString(mixed $value, int $maxLength, string $errorCode): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new IntegrationProtocolException($errorCode);
        }

        return $value;
    }

    private function nullableBool(array $value, string $key): ?bool
    {
        if (! array_key_exists($key, $value)) {
            return null;
        }
        if (! is_bool($value[$key])) {
            throw new IntegrationProtocolException('fhir_boolean_capability_invalid');
        }

        return $value[$key];
    }

    private function bool(array $value, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $value)) {
            return $default;
        }
        if (! is_bool($value[$key])) {
            throw new IntegrationProtocolException('fhir_boolean_capability_invalid');
        }

        return $value[$key];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($this->requiredString($value, 80, 'fhir_capability_date_invalid'));
        } catch (\Throwable) {
            throw new IntegrationProtocolException('fhir_capability_date_invalid');
        }
    }

    private function origin(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).$port;
    }

    /** @return list<mixed> */
    private function decodeList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function canonicalJson(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return $item;
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
