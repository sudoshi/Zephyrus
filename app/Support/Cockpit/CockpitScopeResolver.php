<?php

namespace App\Support\Cockpit;

use App\Models\User;
use App\Support\Hospital\HospitalManifest;

/**
 * Resolves the active {@see CockpitScope} for a request and enumerates the scopes a
 * user may mount (Zephyrus 2.0 P8 WS-1 — the Mount-Anywhere Cockpit spine).
 *
 * Resolution order: an explicit, valid `?scope=` token wins; else the user's PRIMARY
 * unit assignment (prod.user_unit) — the "mount-anywhere" default so a charge nurse's
 * screen opens on her unit; else the house-wide overview. A malformed or unknown token
 * degrades to that same fallback chain rather than erroring — a bad URL must never 500
 * a wall display.
 *
 * Scope EXISTENCE is validated here against the HospitalManifest SSOT. Scope
 * AUTHORIZATION (may THIS user mount it) layers on in WS-6 via RBAC + the flow lens.
 * WS-2 consumes the resolved scope to render altitude-appropriate faces.
 */
final class CockpitScopeResolver
{
    /**
     * Departments that have a live, physically-staffed board face. Keys are cockpit
     * domain keys so a department mount reuses that domain's drill face (WS-2). The
     * cross-cutting domains (staffing/flow/quality/service/financial) are house-level
     * tiles reached by drilling the house grid, not mount points.
     *
     * @var array<string, string>
     */
    public const DEPARTMENTS = [
        'ed' => 'Emergency Department',
        'periop' => 'Perioperative Services',
        'rtdc' => 'Capacity Command (RTDC)',
    ];

    public function __construct(private readonly HospitalManifest $manifest) {}

    public function resolve(?string $token, ?User $user = null): CockpitScope
    {
        if ($token !== null && trim($token) !== '') {
            $explicit = $this->fromToken($token);
            if ($explicit !== null) {
                return $explicit;
            }
        }

        if ($user !== null) {
            $primary = $this->primaryUnitScope($user);
            if ($primary !== null) {
                return $primary;
            }
        }

        return CockpitScope::house($this->manifest->facilityName());
    }

    /**
     * Parse a scope token back into a validated CockpitScope, or null if the token is
     * malformed or names something the manifest does not know.
     */
    public function fromToken(string $token): ?CockpitScope
    {
        $token = trim($token);

        if ($token === '' || $token === CockpitScope::LEVEL_HOUSE) {
            return CockpitScope::house($this->manifest->facilityName());
        }

        if (! str_contains($token, ':')) {
            return null;
        }

        [$level, $key] = explode(':', $token, 2);
        $key = trim($key);

        if ($key === '') {
            return null;
        }

        return match ($level) {
            CockpitScope::LEVEL_UNIT => $this->unitScope($key),
            CockpitScope::LEVEL_SERVICE_LINE => $this->serviceLineScope($key),
            CockpitScope::LEVEL_DEPARTMENT => $this->departmentScope($key),
            default => null,
        };
    }

    /**
     * The full catalog of mountable scopes for the picker (WS-5). Units the user is
     * assigned to are flagged `assigned` so the UI can surface "my units" first; RBAC
     * filtering of who-may-mount-what lands in WS-6.
     *
     * @return array{
     *     house: array<string, mixed>,
     *     departments: list<array<string, mixed>>,
     *     serviceLines: list<array<string, mixed>>,
     *     units: list<array<string, mixed>>,
     * }
     */
    public function catalog(?User $user = null): array
    {
        $assignedSet = $user !== null
            ? array_flip(
                $user->units()
                    ->pluck('abbreviation')
                    ->filter()
                    ->map(fn ($abbr) => strtoupper((string) $abbr))
                    ->all()
            )
            : [];

        $departments = [];
        foreach (self::DEPARTMENTS as $key => $label) {
            $departments[] = CockpitScope::department($key, $label)->toArray();
        }

        $serviceLines = [];
        foreach ($this->manifest->serviceLines() as $line) {
            $serviceLines[] = CockpitScope::serviceLine($line['code'], $line['name'])->toArray();
        }

        $units = [];
        foreach ($this->manifest->units() as $u) {
            $entry = CockpitScope::unit($u['abbr'], $u['name'])->toArray();
            $entry['serviceLine'] = $u['service_line'] ?? null;
            $entry['type'] = $u['type'] ?? null;
            // prod.user_unit joins prod.units, whose abbreviation may carry either the
            // branded abbr or the CAD join key depending on which importer wrote it —
            // flag the assignment through both.
            $entry['assigned'] = isset($assignedSet[strtoupper((string) $u['abbr'])])
                || isset($assignedSet[strtoupper((string) ($u['cad_code'] ?? ''))]);
            $units[] = $entry;
        }

        return [
            'house' => CockpitScope::house($this->manifest->facilityName())->toArray(),
            'departments' => $departments,
            'serviceLines' => $serviceLines,
            'units' => $units,
        ];
    }

    private function unitScope(string $abbr): ?CockpitScope
    {
        // Accept both the branded manifest abbreviation ('MICU') and the CAD join key
        // ('MICU3') — deep links and prod.user_unit rows may carry either taxonomy —
        // and canonicalize to ONE token (the manifest abbr) so cache keys never fork.
        $unit = $this->manifest->unit($abbr) ?? $this->manifest->unitByCadCode($abbr);

        if ($unit === null) {
            return null;
        }

        return CockpitScope::unit($unit['abbr'], $unit['name']);
    }

    private function serviceLineScope(string $code): ?CockpitScope
    {
        foreach ($this->manifest->serviceLines() as $line) {
            if (strcasecmp($line['code'], $code) === 0) {
                return CockpitScope::serviceLine($line['code'], $line['name']);
            }
        }

        return null;
    }

    private function departmentScope(string $key): ?CockpitScope
    {
        $key = strtolower($key);

        if (! isset(self::DEPARTMENTS[$key])) {
            return null;
        }

        return CockpitScope::department($key, self::DEPARTMENTS[$key]);
    }

    /**
     * The user's primary unit assignment as a unit scope, or null when they have no
     * assignment (or it names a unit the manifest no longer knows).
     */
    private function primaryUnitScope(User $user): ?CockpitScope
    {
        $unit = $user->units()->wherePivot('is_primary', true)->first()
            ?? $user->units()->first();

        if ($unit === null || $unit->abbreviation === null) {
            return null;
        }

        return $this->unitScope($unit->abbreviation);
    }
}
