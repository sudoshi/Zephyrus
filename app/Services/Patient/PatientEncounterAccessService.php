<?php

namespace App\Services\Patient;

use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientPrincipal;
use Illuminate\Support\Collection;

class PatientEncounterAccessService
{
    /**
     * Return only the governed patient-facing encounter handles. Source EHR
     * identifiers and encrypted linkage material never leave this service.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function activeGrants(PatientPrincipal $principal): Collection
    {
        return PatientEncounterAccessGrant::query()
            ->where('principal_id', $principal->getKey())
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where(function ($query) {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('valid_from')
            ->orderBy('grant_uuid')
            ->get()
            ->filter(fn (PatientEncounterAccessGrant $grant): bool => $principal->can('view', $grant))
            ->values();
    }

    /** @return array<string, mixed> */
    public function patientSafeProjection(PatientEncounterAccessGrant $grant): array
    {
        return [
            'encounter_uuid' => (string) $grant->encounter_uuid,
            'grant_uuid' => (string) $grant->grant_uuid,
            'relationship' => (string) $grant->relationship,
            'scopes' => array_values((array) $grant->scopes),
            'valid_from' => $grant->valid_from?->toISOString(),
            'expires_at' => $grant->expires_at?->toISOString(),
            'version' => (int) $grant->version,
        ];
    }
}
