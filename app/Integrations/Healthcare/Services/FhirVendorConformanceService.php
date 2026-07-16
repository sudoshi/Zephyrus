<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;
use App\Models\Integration\Source;

/**
 * Applies optional vendor assertions without coupling the SMART/FHIR protocol
 * clients to a particular EHR implementation.
 */
final class FhirVendorConformanceService
{
    /** @param array<string, mixed> $capabilityStatement */
    public function assertCapabilityStatement(Source $source, array $capabilityStatement): void
    {
        $vendorKey = strtolower(trim((string) $source->vendor));
        if ($vendorKey === '') {
            return;
        }

        $profile = config("integrations.fhir_vendor_conformance.{$vendorKey}");
        if (! is_array($profile)) {
            return;
        }

        $softwareName = strtolower(trim((string) data_get($capabilityStatement, 'software.name')));
        $tokens = array_values(array_filter(
            (array) ($profile['software_name_contains_any'] ?? []),
            fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));
        if ($tokens !== [] && ! collect($tokens)->contains(
            fn (string $token): bool => str_contains($softwareName, strtolower(trim($token))),
        )) {
            throw new IntegrationProtocolException('fhir_vendor_mismatch');
        }
    }
}
