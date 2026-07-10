<?php

namespace App\Services\Flow;

use App\Models\Evs\EvsRequest;
use App\Models\Transport\TransportRequest;
use App\Models\Unit;
use App\Models\User;
use App\Services\Mobile\MobilePatientContextService;
use App\Services\Mobile\MobilePersonaCatalog;
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
    private const IDENTIFIER_KEYS = [
        '_patient_ref',
        'patient_ref',
        'patient_id',
        'patient_display_id',
        'patient_name',
        'patient_ids',
        'patient_refs',
        'downstream_patient_refs',
        'encounter_id',
        'encounter_ref',
        'raw_message_hash',
        'mrn',
        'medical_record_number',
        'message_control_id',
        'attending_provider_hash',
    ];

    public function __construct(
        private readonly MobilePatientContextService $patientContext,
        private readonly MobilePersonaCatalog $personas,
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

        if ($scope['type'] === 'patient') {
            return 'unit'; // patientScope() already authorized the specific patient
        }

        // Broad-access operators may assume any persona. In an empty/bootstrap
        // facility there is no unit row to resolve, but the request must remain
        // safely usable (it will contain no patient rows because the visible
        // unit set is empty).
        if ($this->personas->isBroadAccessUser($user)) {
            return 'unit';
        }

        $visibleUnitIds = $this->visibleUnitIds($user);
        if ($scope['type'] === 'unit' && ! in_array((int) $scope['unit_id'], $visibleUnitIds, true)) {
            return 'none';
        }

        // At house/floor scope a unit-depth persona receives patient rows only
        // for their assigned units. The row filter applies that intersection.
        return $visibleUnitIds === [] ? 'none' : 'unit';
    }

    /** @return list<int> */
    public function visibleUnitIds(?User $user): array
    {
        if (! $user || ! Schema::hasTable('prod.units')) {
            return [];
        }

        $query = $this->personas->isBroadAccessUser($user)
            ? Unit::query()->where('is_deleted', false)
            : $user->units()->where('prod.units.is_deleted', false);

        return $query
            ->pluck('prod.units.unit_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> patient refs visible to an active task-depth role */
    public function taskPatientRefs(string $roleId): array
    {
        $query = match ($roleId) {
            'transport' => TransportRequest::query()->active(),
            'evs' => EvsRequest::query()->active(),
            default => null,
        };

        if ($query === null) {
            return [];
        }

        return $query
            ->whereNotNull('patient_ref')
            ->pluck('patient_ref')
            ->map(fn ($ref): string => (string) $ref)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether a raw patient row is inside the requested spatial/patient scope.
     *
     * @param  array{type: string, floor: ?int, unit_id: ?int, patient_ref?: ?string}  $scope
     */
    public function rowInScope(array $row, array $scope): bool
    {
        return match ($scope['type']) {
            'patient' => $this->patientRefForRow($row) !== null
                && hash_equals((string) ($scope['patient_ref'] ?? ''), (string) $this->patientRefForRow($row)),
            'unit' => isset($row['unit_id']) && (int) $row['unit_id'] === (int) $scope['unit_id'],
            'floor' => $this->floorForRow($row) !== null
                && $this->floorForRow($row) === (int) $scope['floor'],
            default => true,
        };
    }

    /**
     * Whether a scoped row may carry patient context for the effective depth.
     *
     * @param  array{type: string, floor: ?int, unit_id: ?int, patient_ref?: ?string}  $scope
     * @param  list<int>  $visibleUnitIds
     * @param  list<string>  $taskRefs
     */
    public function canViewPatientRow(
        array $row,
        string $depth,
        array $scope,
        array $visibleUnitIds = [],
        array $taskRefs = [],
    ): bool {
        $patientRef = $this->patientRefForRow($row);
        if ($patientRef === null || ! $this->rowInScope($row, $scope)) {
            return false;
        }

        return match ($depth) {
            'full' => true,
            'task' => in_array($patientRef, $taskRefs, true),
            'unit' => $scope['type'] === 'patient'
                || (isset($row['unit_id']) && in_array((int) $row['unit_id'], $visibleUnitIds, true)),
            default => false,
        };
    }

    /**
     * Apply an effective patient depth to one timeline event / projection
     * item and strip internal fields — the ONE redaction implementation for
     * every flow surface (mobile window AND web projections). A row survives
     * redaction; only the patient identity inside it may not.
     *
     * @param  array{type: string, floor: ?int, unit_id: ?int}  $scope
     * @param  list<string>  $taskRefs  patient refs visible to a task-depth role
     * @param  list<int>  $visibleUnitIds  unit ids visible to a unit-depth role
     * @return array<string, mixed>
     */
    public function redactRow(
        array $row,
        string $depth,
        array $scope,
        array $taskRefs = [],
        array $visibleUnitIds = [],
    ): array {
        $patientRef = $this->patientRefForRow($row);
        $hadPatientId = array_key_exists('patient_id', $row);
        $hadPatientDisplay = array_key_exists('patient_display_id', $row);
        $encounterRef = isset($row['encounter_id']) && is_scalar($row['encounter_id'])
            ? (string) $row['encounter_id']
            : null;
        $originalKey = isset($row['key']) && is_scalar($row['key']) ? (string) $row['key'] : null;

        $allowed = match (true) {
            $patientRef === null => false,
            $depth === 'none' => false,
            $depth === 'full' => true,
            $depth === 'task' => in_array($patientRef, $taskRefs, true),
            $depth === 'unit' && $scope['type'] === 'patient' => true,
            $depth === 'unit' => ($row['unit_id'] ?? null) !== null
                && in_array((int) $row['unit_id'], $visibleUnitIds, true),
            default => false,
        };

        $row = $this->stripIdentifierKeys($row);

        if ($patientRef !== null && $originalKey !== null) {
            $row['key'] = $this->opaqueRef('flow-row', $patientRef, 'flow_')
                .(($row['location'] ?? null) ? ':'.$row['location'] : '');
        }

        if ($allowed && $patientRef !== null) {
            $patientContextRef = $this->patientContext->contextRefFor($patientRef);
            $row['patient_context_ref'] = $patientContextRef;

            if ($hadPatientId) {
                $row['patient_id'] = $patientContextRef;
            }

            if ($hadPatientDisplay) {
                $row['patient_display_id'] = 'Patient '.strtoupper(substr((string) $patientContextRef, -6));
            }

            if ($encounterRef !== null && $encounterRef !== '') {
                $row['encounter_id'] = $this->opaqueRef('encounter', $encounterRef, 'etok_');
            }

            if (($row['entity']['type'] ?? null) === 'patient') {
                $row['entity']['ref'] = $patientContextRef;
            }
        } else {
            $row['patient_context_ref'] = null;
            if (($row['entity']['type'] ?? null) === 'patient') {
                $row['entity'] = null;
            }
        }

        return $row;
    }

    public function opaqueRef(string $kind, string $value, string $prefix): string
    {
        return $prefix.substr(hash_hmac(
            'sha256',
            $kind.'|'.$value,
            (string) config('app.key', 'zephyrus'),
        ), 0, 24);
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
            if ($user && $arg === null && $this->personas->isBroadAccessUser($user)) {
                return [
                    'type' => 'house',
                    'floor' => null,
                    'unit_id' => null,
                    'patient_ref' => null,
                    'patient_context_ref' => null,
                    'label' => 'House',
                ];
            }

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

    private function patientRefForRow(array $row): ?string
    {
        foreach (['_patient_ref', 'patient_ref', 'patient_id'] as $key) {
            $value = $row[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '' && ! str_starts_with((string) $value, 'ptok_')) {
                return (string) $value;
            }
        }

        return null;
    }

    private function floorForRow(array $row): ?int
    {
        foreach (['location_floor', 'floor'] as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return (int) $row[$key];
            }
        }

        return null;
    }

    /** @param array<array-key, mixed> $value */
    private function stripIdentifierKeys(array $value): array
    {
        $redacted = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), self::IDENTIFIER_KEYS, true)) {
                continue;
            }

            $redacted[$key] = is_array($item) ? $this->stripIdentifierKeys($item) : $item;
        }

        return $redacted;
    }

    private function floorForUnit(Unit $unit): ?int
    {
        $manifest = app(\App\Support\Hospital\HospitalManifest::class);
        $entry = $unit->abbreviation ? $manifest->unit($unit->abbreviation) : null;

        return isset($entry['floor']) ? (int) $entry['floor'] : null;
    }
}
