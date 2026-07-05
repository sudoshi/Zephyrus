<?php

namespace App\Support\Cockpit;

use App\Models\User;
use App\Services\Mobile\MobilePersonaCatalog;
use App\Support\Hospital\HospitalManifest;

/**
 * Decides whether a user MAY MOUNT a given {@see CockpitScope} (Zephyrus 2.0 P8 WS-6 —
 * the scope-leakage gate deferred by CockpitScopeResolver::resolve()). Scope EXISTENCE
 * is the resolver's job; scope AUTHORIZATION is this class's.
 *
 * Tier model (deliberately non-regressive and drift-free):
 *
 *  - **house + department** faces are AGGREGATE operational overviews — the same
 *    house-wide visibility every authenticated user has had since pre-2.0 (the
 *    /dashboard snapshot and the public per-domain drills). They are never gated;
 *    gating them would break the default cockpit for a unit nurse.
 *  - **unit + service_line** faces serve LIVE bed census (bed-level rows, patient
 *    lists) — the actual leak surface. They are gated to the caller's own
 *    assignment: a house-wide operator (admin/super-admin or any persona that is
 *    not a unit-scoped clinician) may mount any of them; a unit-scoped clinician
 *    (charge/bedside nurse, hospitalist, intensivist, OR nurse) may mount ONLY a
 *    unit they are assigned to (prod.user_unit) or a service line that contains one.
 *
 * Role resolution routes THROUGH {@see MobilePersonaCatalog} (never a second, drifting
 * Spatie parse) so the cockpit and the mobile BFF agree on who is house-wide. Patient
 * rows inside a face still drill through EnforceFlowLens, so this gate governs the
 * mount, not the deeper A2P descent.
 */
final class CockpitScopeAuthorizer
{
    /**
     * Personas whose assignment scope is a single unit (mirrors
     * MobilePersonaCatalog::assignmentScope). Everyone else — supervisors,
     * capacity/bed managers, executives, and the roleless demo default
     * (house_supervisor) — is house-wide and may mount any altitude.
     *
     * @var list<string>
     */
    private const UNIT_SCOPED_PERSONAS = [
        'charge_nurse',
        'bedside_nurse',
        'hospitalist',
        'intensivist',
        'or_nurse',
    ];

    public function __construct(
        private readonly MobilePersonaCatalog $personas,
        private readonly HospitalManifest $manifest,
    ) {}

    public function canMount(?User $user, CockpitScope $scope): bool
    {
        if ($user === null) {
            return false;
        }

        // Aggregate overviews — public to every authenticated user (no regression).
        if (in_array($scope->level, [CockpitScope::LEVEL_HOUSE, CockpitScope::LEVEL_DEPARTMENT], true)) {
            return true;
        }

        // House-wide operators may mount any live-census face.
        if ($this->isHouseWideMounter($user)) {
            return true;
        }

        // Unit-scoped clinicians are gated to their own assignment.
        $assigned = $this->assignedUnitAbbrs($user);

        if ($scope->level === CockpitScope::LEVEL_UNIT) {
            return $scope->key !== null && isset($assigned[strtoupper($scope->key)]);
        }

        if ($scope->level === CockpitScope::LEVEL_SERVICE_LINE) {
            foreach ($this->manifest->unitsByServiceLine((string) $scope->key) as $unit) {
                $abbr = strtoupper((string) ($unit['abbr'] ?? ''));
                if ($abbr !== '' && isset($assigned[$abbr])) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    /**
     * The catalog filtered to the scopes this user may actually mount — the picker
     * (WS-5) must never OFFER an unauthorized mount. house + departments are always
     * mountable; units + service lines are dropped to the authorized set. A house-wide
     * mounter sees the full catalog unchanged.
     *
     * @param  array{house: array<string,mixed>, departments: list<array<string,mixed>>, serviceLines: list<array<string,mixed>>, units: list<array<string,mixed>>}  $catalog
     * @return array{house: array<string,mixed>, departments: list<array<string,mixed>>, serviceLines: list<array<string,mixed>>, units: list<array<string,mixed>>}
     */
    public function filterCatalog(?User $user, array $catalog): array
    {
        if ($user !== null && $this->isHouseWideMounter($user)) {
            return $catalog;
        }

        $catalog['units'] = array_values(array_filter(
            $catalog['units'],
            fn (array $unit): bool => $this->canMount($user, CockpitScope::unit((string) ($unit['key'] ?? ''), (string) ($unit['label'] ?? ''))),
        ));

        $catalog['serviceLines'] = array_values(array_filter(
            $catalog['serviceLines'],
            fn (array $line): bool => $this->canMount($user, CockpitScope::serviceLine((string) ($line['key'] ?? ''), (string) ($line['label'] ?? ''))),
        ));

        return $catalog;
    }

    /**
     * Admin/super-admin (via the shared broad-access check) OR any persona that is not
     * a unit-scoped clinician. Routed through MobilePersonaCatalog so the cockpit and
     * mobile never diverge on who is house-wide.
     */
    private function isHouseWideMounter(User $user): bool
    {
        if ($this->personas->isBroadAccessUser($user)) {
            return true;
        }

        return ! in_array($this->personas->normalize(null, $user), self::UNIT_SCOPED_PERSONAS, true);
    }

    /**
     * The caller's assigned unit abbreviations (prod.user_unit), upper-cased into a set
     * for O(1) membership — the same source primaryUnitScope() and catalog() read.
     *
     * @return array<string, int>
     */
    private function assignedUnitAbbrs(User $user): array
    {
        return array_flip(
            $user->units()
                ->pluck('abbreviation')
                ->filter()
                ->map(fn ($abbr): string => strtoupper((string) $abbr))
                ->all()
        );
    }
}
