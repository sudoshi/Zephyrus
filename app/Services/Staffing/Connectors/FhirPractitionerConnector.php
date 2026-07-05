<?php

namespace App\Services\Staffing\Connectors;

use App\Services\Staffing\Contracts\StaffingConnector;
use App\Services\Staffing\Support\ConnectionResult;
use App\Services\Staffing\Support\ConnectorCapabilities;
use App\Services\Staffing\Support\PullWindow;
use App\Services\Staffing\Support\RawStaffRecord;

/**
 * Phase 7: ingests a FHIR R4 Bundle of Practitioner + PractitionerRole resources.
 * PractitionerRole.specialty -> service line, PractitionerRole.code -> job title,
 * PractitionerRole.location -> home unit; Practitioner NPI/name/email carry identity.
 * A practitioner with multiple roles yields multiple RawStaffRecords (multi-membership).
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§4)
 */
class FhirPractitionerConnector implements StaffingConnector
{
    private const NPI_SYSTEM_HINT = 'us-npi';

    /**
     * @param  array<string, mixed>  $bundle  a FHIR R4 Bundle
     */
    public function __construct(
        private readonly string $sourceKey,
        private readonly array $bundle,
    ) {}

    public function key(): string
    {
        return $this->sourceKey;
    }

    public function testConnection(): ConnectionResult
    {
        $resources = $this->resources();
        $practitioners = array_filter($resources, static fn ($r): bool => ($r['resourceType'] ?? null) === 'Practitioner');

        return $practitioners === []
            ? ConnectionResult::fail('Bundle contains no Practitioner resources.')
            : ConnectionResult::ok('FHIR bundle parsed.', ['practitioners' => count($practitioners)]);
    }

    public function discoverSchema(): array
    {
        return [
            ['field' => 'external_id', 'samples' => ['Practitioner.id']],
            ['field' => 'npi', 'samples' => ['Practitioner.identifier[us-npi]']],
            ['field' => 'display_name', 'samples' => ['Practitioner.name']],
            ['field' => 'email', 'samples' => ['Practitioner.telecom[email]']],
            ['field' => 'specialty', 'samples' => ['PractitionerRole.specialty']],
            ['field' => 'job_title', 'samples' => ['PractitionerRole.code']],
            ['field' => 'home_unit', 'samples' => ['PractitionerRole.location']],
        ];
    }

    public function pullStaff(PullWindow $window): iterable
    {
        $resources = $this->resources();

        $practitioners = [];
        $roles = [];

        foreach ($resources as $resource) {
            $type = $resource['resourceType'] ?? null;
            if ($type === 'Practitioner' && isset($resource['id'])) {
                $practitioners[(string) $resource['id']] = $resource;
            } elseif ($type === 'PractitionerRole') {
                $ref = $this->referenceId($resource['practitioner']['reference'] ?? null);
                if ($ref !== null) {
                    $roles[$ref][] = $resource;
                }
            }
        }

        foreach ($practitioners as $id => $practitioner) {
            $identity = $this->practitionerIdentity($id, $practitioner);
            $practitionerRoles = $roles[$id] ?? [];

            if ($practitionerRoles === []) {
                yield RawStaffRecord::fromArray($this->sourceKey, $identity);

                continue;
            }

            foreach ($practitionerRoles as $role) {
                yield RawStaffRecord::fromArray($this->sourceKey, array_merge($identity, $this->roleFields($role)));
            }
        }
    }

    public function capabilities(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(incremental: false, onCall: false, credentials: true, push: false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resources(): array
    {
        $out = [];
        foreach ($this->bundle['entry'] ?? [] as $entry) {
            if (isset($entry['resource']) && is_array($entry['resource'])) {
                $out[] = $entry['resource'];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $practitioner
     * @return array<string, mixed>
     */
    private function practitionerIdentity(string $id, array $practitioner): array
    {
        return [
            'external_id' => $id,
            'display_name' => $this->humanName($practitioner['name'][0] ?? null),
            'npi' => $this->npi($practitioner['identifier'] ?? []),
            'email' => $this->telecom($practitioner['telecom'] ?? [], 'email'),
            'employment_status' => ($practitioner['active'] ?? true) === false ? 'terminated' : 'active',
            'raw' => $practitioner,
        ];
    }

    /**
     * @param  array<string, mixed>  $role
     * @return array<string, mixed>
     */
    private function roleFields(array $role): array
    {
        $fields = [
            'specialty' => $this->codeableText($role['specialty'][0] ?? null),
            'job_title' => $this->codeableText($role['code'][0] ?? null),
            'home_unit' => $this->referenceDisplay($role['location'][0] ?? null),
            'department' => $this->referenceDisplay($role['location'][0] ?? null),
        ];

        if (($role['active'] ?? true) === false) {
            $fields['employment_status'] = 'terminated';
        }

        return array_filter($fields, static fn ($v): bool => $v !== null);
    }

    private function referenceId(?string $reference): ?string
    {
        if ($reference === null || ! str_contains($reference, '/')) {
            return null;
        }

        return substr($reference, strrpos($reference, '/') + 1) ?: null;
    }

    /**
     * @param  array<string, mixed>|null  $name
     */
    private function humanName(?array $name): ?string
    {
        if ($name === null) {
            return null;
        }
        if (! empty($name['text'])) {
            return (string) $name['text'];
        }
        $given = implode(' ', array_map('strval', $name['given'] ?? []));
        $full = trim($given.' '.($name['family'] ?? ''));

        return $full === '' ? null : $full;
    }

    /**
     * @param  list<array<string, mixed>>  $identifiers
     */
    private function npi(array $identifiers): ?string
    {
        foreach ($identifiers as $identifier) {
            $system = strtolower((string) ($identifier['system'] ?? ''));
            if (str_contains($system, self::NPI_SYSTEM_HINT) && ! empty($identifier['value'])) {
                return (string) $identifier['value'];
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $telecoms
     */
    private function telecom(array $telecoms, string $system): ?string
    {
        foreach ($telecoms as $telecom) {
            if (($telecom['system'] ?? null) === $system && ! empty($telecom['value'])) {
                return (string) $telecom['value'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $codeable
     */
    private function codeableText(?array $codeable): ?string
    {
        if ($codeable === null) {
            return null;
        }
        if (! empty($codeable['text'])) {
            return (string) $codeable['text'];
        }
        $coding = $codeable['coding'][0] ?? null;
        if (is_array($coding)) {
            return ($coding['display'] ?? $coding['code'] ?? null) !== null
                ? (string) ($coding['display'] ?? $coding['code'])
                : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $reference
     */
    private function referenceDisplay(?array $reference): ?string
    {
        if ($reference === null) {
            return null;
        }

        return ! empty($reference['display']) ? (string) $reference['display'] : null;
    }
}
