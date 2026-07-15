<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;

class FhirResourcePolicy
{
    /** @return list<string> */
    public function enabledResourceTypes(): array
    {
        $types = array_keys(array_filter(
            $this->definitions(),
            fn (array $definition): bool => (bool) ($definition['enabled'] ?? false),
        ));
        sort($types);

        return $types;
    }

    public function allows(string $resourceType): bool
    {
        return in_array($resourceType, $this->enabledResourceTypes(), true);
    }

    public function assertResourceAllowed(string $resourceType): void
    {
        if (! $this->allows($resourceType)) {
            throw new IntegrationProtocolException('fhir_resource_not_allowed');
        }
    }

    public function assertCredentialAllows(string $resourceType, mixed $scopePayload): void
    {
        $this->assertResourceAllowed($resourceType);
        $requiredScope = $this->definitions()[$resourceType]['scope'] ?? null;
        $configured = $this->decodeScopes($scopePayload);

        if (! is_string($requiredScope) || ! in_array($requiredScope, $configured, true)) {
            throw new IntegrationProtocolException('fhir_scope_not_approved');
        }
    }

    /** @return array<string, array{enabled?:bool,scope?:string,family?:string}> */
    private function definitions(): array
    {
        $definitions = config('integrations.fhir_resources', []);

        return is_array($definitions) ? $definitions : [];
    }

    /** @return list<string> */
    private function decodeScopes(mixed $scopePayload): array
    {
        if (is_string($scopePayload)) {
            $decoded = json_decode($scopePayload, true);
            if (is_array($decoded)) {
                $scopePayload = $decoded;
            } else {
                $scopePayload = preg_split('/\s+/', trim($scopePayload)) ?: [];
            }
        }

        if (! is_array($scopePayload)) {
            return [];
        }

        return array_values(array_unique(array_filter($scopePayload, 'is_string')));
    }
}
