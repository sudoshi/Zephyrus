<?php

namespace App\Services\Flow;

use App\Models\Unit;
use App\Models\User;
use App\Services\Mobile\MobilePatientContextService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;

/**
 * The persona lens for the 48-hour Flow Window (FLOW-WINDOW-PLAN §6.4, D1).
 *
 * One window API, persona-lensed: the lens (config/hummingbird/flow_lens.php)
 * decides which scopes, layers, event kinds, projection kinds, and patient
 * depth a role gets. Everything is clamped SERVER-SIDE — an unauthorized
 * scope is a 403 with an explicit unauthorized state, never a silently
 * narrowed payload the client must interpret.
 *
 * patient depth maps 1:1 onto the MobilePatientContextService matrix:
 * whatever a role could not open in A2P never appears as a ptok in a
 * window payload either.
 */
class FlowLensService
{
    public function __construct(
        private readonly MobilePatientContextService $patientContext,
    ) {}

    /** @return array<string, mixed> */
    public function lensFor(string $roleId): array
    {
        $lens = config("hummingbird.flow_lens.{$roleId}");

        if (! is_array($lens)) {
            throw new AuthorizationException('No flow lens is configured for this persona.');
        }

        return $lens + ['role_id' => $roleId];
    }

    /**
     * Resolve + authorize a requested scope string against the lens.
     *
     * Grammar: house | floor:{n} | unit:{id|abbr} | patient:{ptok_…}
     *
     * @return array{type: string, floor: ?int, unit_id: ?int, patient_ref: ?string, patient_context_ref: ?string, label: string}
     *
     * @throws AuthorizationException when the scope type is outside the lens
     *                                or the patient is outside the role's matrix
     */
    public function resolveScope(array $lens, ?string $requested, ?User $user): array
    {
        $requested = trim((string) ($requested ?: $lens['scope_default']));
        [$type, $arg] = array_pad(explode(':', $requested, 2), 2, null);
        $type = strtolower($type);

        if (! in_array($type, $lens['scopes_allowed'], true)) {
            throw new AuthorizationException("The '{$type}' scope is not available to the {$lens['role_id']} lens.");
        }

        return match ($type) {
            'house' => ['type' => 'house', 'floor' => null, 'unit_id' => null, 'patient_ref' => null, 'patient_context_ref' => null, 'label' => 'House'],
            'floor' => $this->floorScope($arg, $lens, $user),
            'unit' => $this->unitScope($arg, $lens, $user),
            'patient' => $this->patientScope($arg, $lens, $user),
            default => throw new AuthorizationException("Unknown flow scope '{$type}'."),
        };
    }

    /**
     * Clamp requested layers to the lens. Unknown/unauthorized layers are
     * dropped, not errored — the layer list is a filter, not an authority claim.
     *
     * @return list<string>
     */
    public function clampLayers(array $lens, ?string $requested): array
    {
        $allowed = $lens['layers'];
        if ($requested === null || trim($requested) === '') {
            return $allowed;
        }

        $asked = array_filter(array_map('trim', explode(',', strtolower($requested))));

        return array_values(array_intersect($allowed, $asked)) ?: $allowed;
    }

    /**
     * The effective patient depth for a resolved scope — the per-payload
     * refinement of the lens' static policy:
     *
     *   full → full everywhere
     *   unit → 'unit' only where the user actually shares the scoped unit
     *          (prod.user_unit), otherwise none — mirroring the A2P rule
     *   task → dots only for patients on that role's active request type
     *   none → none, always
     */
    public function effectivePatientDepth(array $lens, array $scope, ?User $user): string
    {
        $policy = $lens['patient_dots'];

        if ($policy === 'none' || $policy === 'full' || $policy === 'task') {
            return $policy;
        }

        // unit policy: depth only inside a unit the user shares.
        if ($scope['type'] !== 'unit' && $scope['type'] !== 'patient') {
            return 'none';
        }

        if ($scope['type'] === 'patient') {
            return 'unit'; // patientScope() already authorized the specific patient
        }

        return $this->userSharesUnit($user, (int) $scope['unit_id']) ? 'unit' : 'none';
    }

    /**
     * Apply an effective patient depth to one timeline event / projection
     * item and strip internal fields — the ONE redaction implementation for
     * every flow surface (mobile window AND web projections). A row survives
     * redaction; only the patient identity inside it may not.
     *
     * @param  array{type: string, floor: ?int, unit_id: ?int}  $scope
     * @param  list<string>  $taskRefs  patient refs visible to a task-depth role
     * @return array<string, mixed>
     */
    public function redactRow(array $row, string $depth, array $scope, array $taskRefs = []): array
    {
        $patientRef = $row['_patient_ref'] ?? null;
        unset($row['_patient_ref']);

        $allowed = match (true) {
            $patientRef === null => false,
            $depth === 'none' => false,
            $depth === 'full' => true,
            $depth === 'task' => in_array($patientRef, $taskRefs, true),
            // unit depth: the shared-unit check happened in
            // effectivePatientDepth(); keep identity only inside the scoped unit.
            default => ($row['unit_id'] ?? null) !== null
                && (($scope['unit_id'] ?? null) === null || $row['unit_id'] === $scope['unit_id']),
        };

        if (! $allowed) {
            $row['patient_context_ref'] = null;
            if (($row['entity']['type'] ?? null) === 'patient') {
                $row['entity'] = null;
            }
        }

        return $row;
    }

    /**
     * Canonical scope string for a resolved scope (deep links, echoes).
     */
    public function scopeString(array $scope): string
    {
        return match ($scope['type']) {
            'floor' => 'floor:'.$scope['floor'],
            'unit' => 'unit:'.$scope['unit_id'],
            'patient' => 'patient:'.$scope['patient_context_ref'],
            default => 'house',
        };
    }

    /** @return array{type: string, floor: int, unit_id: null, patient_ref: null, patient_context_ref: null, label: string} */
    private function floorScope(?string $arg, array $lens, ?User $user): array
    {
        if ($arg !== null && ctype_digit((string) $arg)) {
            $floor = (int) $arg;
        } else {
            // Default floor: the user's assigned unit's floor; the periop
            // floor for OR lenses; otherwise the lowest manifest floor.
            $manifest = app(\App\Support\Hospital\HospitalManifest::class);
            $assigned = $user ? $this->defaultUnitFor($user) : null;
            $assignedFloor = $assigned !== null ? $this->floorForUnit($assigned) : null;
            $floor = match (true) {
                $assignedFloor !== null => $assignedFloor,
                in_array($lens['role_id'], ['or_nurse', 'periop_manager'], true) => (int) ($manifest->unit('OR')['floor'] ?? 2),
                default => (int) min(array_column($manifest->units(), 'floor') ?: [1]),
            };
        }

        return ['type' => 'floor', 'floor' => $floor, 'unit_id' => null, 'patient_ref' => null, 'patient_context_ref' => null, 'label' => "Floor {$floor}"];
    }

    /** @return array{type: string, floor: ?int, unit_id: int, patient_ref: null, patient_context_ref: null, label: string} */
    private function unitScope(?string $arg, array $lens, ?User $user): array
    {
        $unit = null;

        if ($arg !== null && $arg !== '') {
            $unit = ctype_digit($arg)
                ? Unit::where('is_deleted', false)->find((int) $arg)
                : Unit::where('is_deleted', false)->whereRaw('LOWER(abbreviation) = ?', [strtolower($arg)])->first();

            if (! $unit) {
                throw new AuthorizationException('Unit scope requires a known unit (unit:{id} or unit:{abbr}).');
            }
        } elseif ($user) {
            // Own assigned unit first; otherwise fall back to the first
            // active unit — the aggregate unit board is as visible as
            // /rtdc/census, and patient depth still requires a SHARED unit
            // (effectivePatientDepth), so the fallback never widens identity.
            $unit = $this->defaultUnitFor($user)
                ?? Unit::where('is_deleted', false)->orderBy('unit_id')->first();
        }

        if (! $unit) {
            throw new AuthorizationException('Unit scope requires a known unit (unit:{id} or unit:{abbr}), or an assigned unit for this user.');
        }

        return [
            'type' => 'unit',
            'floor' => $this->floorForUnit($unit),
            'unit_id' => (int) $unit->unit_id,
            'patient_ref' => null,
            'patient_context_ref' => null,
            'label' => (string) ($unit->abbreviation ?? $unit->name),
        ];
    }

    /** @return array{type: string, floor: null, unit_id: ?int, patient_ref: string, patient_context_ref: string, label: string} */
    private function patientScope(?string $arg, array $lens, ?User $user): array
    {
        if ($arg === null || ! str_starts_with($arg, 'ptok_')) {
            throw new AuthorizationException('Patient scope requires an opaque context ref (patient:ptok_…).');
        }

        if ($lens['patient_dots'] === 'none') {
            throw new AuthorizationException('This persona has no patient-level flow access.');
        }

        // Delegate the authorization decision to the existing A2P matrix —
        // build() throws AuthorizationException for out-of-matrix patients.
        $context = $this->patientContext->build($arg, $user, $lens['role_id']);
        $patientRef = $this->patientContext->resolvePatientRef($arg);

        $unitId = \App\Models\Encounter::query()
            ->where('patient_ref', $patientRef)
            ->where('status', 'active')
            ->where('is_deleted', false)
            ->value('unit_id');

        return [
            'type' => 'patient',
            'floor' => null,
            'unit_id' => $unitId !== null ? (int) $unitId : null,
            'patient_ref' => (string) $patientRef,
            'patient_context_ref' => $arg,
            'label' => (string) ($context['header']['current_location'] ?? 'Patient'),
        ];
    }

    private function defaultUnitFor(User $user): ?Unit
    {
        if (! Schema::hasTable('prod.user_unit')) {
            return null;
        }

        return $user->units()->where('prod.units.is_deleted', false)->first();
    }

    private function userSharesUnit(?User $user, int $unitId): bool
    {
        if (! $user || ! Schema::hasTable('prod.user_unit')) {
            return false;
        }

        return $user->units()->wherePivot('unit_id', $unitId)->exists();
    }

    private function floorForUnit(Unit $unit): ?int
    {
        $manifest = app(\App\Support\Hospital\HospitalManifest::class);
        $entry = $unit->abbreviation ? $manifest->unit($unit->abbreviation) : null;

        return isset($entry['floor']) ? (int) $entry['floor'] : null;
    }
}
