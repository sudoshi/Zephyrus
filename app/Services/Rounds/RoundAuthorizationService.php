<?php

namespace App\Services\Rounds;

use App\Models\Rounds\RoundQuestion;
use App\Models\Rounds\RoundRun;
use App\Models\Rounds\RoundTask;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side authorization for the rounds domain (plan §12.1).
 *
 * Authorization combines the app role, current unit staff assignment
 * (prod.user_unit), and the run's scope. The projection layer additionally
 * clamps patient detail with canViewPatientDetail(); clients never receive
 * unauthorized rows to hide. 403 responses must not leak patient identifiers.
 *
 * Phase 1 supports unit scope. Service-line and department scopes arrive with
 * the FlowLensService extension in Phase 3 and stay broad-access-only here
 * until then.
 */
class RoundAuthorizationService
{
    public function isBroadAccess(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $broad = (array) config('rounds.broad_access_roles', []);

        if (in_array((string) $user->role, $broad, true)) {
            return true;
        }

        return method_exists($user, 'hasRole') && $user->hasRole(['super-admin', 'admin', 'super_admin']);
    }

    public function canViewRun(User $user, RoundRun $run): bool
    {
        if ($this->isBroadAccess($user)) {
            return true;
        }

        $unitId = $this->runUnitId($run);

        return $unitId !== null && $this->sharesUnit($user, $unitId);
    }

    /**
     * Whether the viewer may see patient identifiers and clinical text, or
     * only aggregate progress. Unit-assigned staff and broad-access roles get
     * detail; everyone else who can somehow view gets aggregate only.
     */
    public function canViewPatientDetail(User $user, RoundRun $run): bool
    {
        return $this->canViewRun($user, $run);
    }

    public function canContribute(User $user, RoundRun $run): bool
    {
        if ($this->isBroadAccess($user)) {
            return true;
        }

        if ($run->participants()->where('user_id', $user->id)->exists()) {
            return true;
        }

        $unitId = $this->runUnitId($run);

        return $unitId !== null && $this->sharesUnit($user, $unitId);
    }

    /**
     * Leading = start/pause/complete/cancel the run, mark patients rounded,
     * waive requirements, and reorder the queue.
     */
    public function canLead(User $user, RoundRun $run): bool
    {
        if ($this->isBroadAccess($user)) {
            return true;
        }

        if ($run->created_by !== null && (int) $run->created_by === (int) $user->id) {
            return true;
        }

        $unitId = $this->runUnitId($run);

        if ($unitId === null || ! Schema::hasTable('prod.user_unit')) {
            return false;
        }

        $leadRoles = (array) config('rounds.lead_unit_roles', []);

        return $user->units()
            ->wherePivot('unit_id', $unitId)
            ->wherePivotIn('role', $leadRoles)
            ->exists();
    }

    public function canStartRun(User $user, Unit $unit): bool
    {
        if ($this->isBroadAccess($user)) {
            return true;
        }

        if (! Schema::hasTable('prod.user_unit')) {
            return false;
        }

        return $user->units()->wherePivot('unit_id', $unit->unit_id)->exists();
    }

    public function canManageTemplates(User $user): bool
    {
        return $this->isBroadAccess($user);
    }

    /**
     * Closing or reassigning a task belongs to its owner (by user or mapped
     * role), its creator, or a run leader — not to any unit-sharing contributor.
     * Contributors can still CREATE tasks; only these actors may mutate one.
     */
    public function canManageTask(User $user, RoundTask $task): bool
    {
        if ($this->canLead($user, $task->run)) {
            return true;
        }

        if ((int) $task->owner_user_id === (int) $user->id || (int) $task->created_by === (int) $user->id) {
            return true;
        }

        return $task->owner_role !== null
            && $task->owner_role === $this->contributorRoleFor($user, $task->run);
    }

    /**
     * Resolving a question belongs to its target (by user or mapped role), the
     * clinician who raised it, or a run leader.
     */
    public function canResolveQuestion(User $user, RoundQuestion $question, RoundRun $run): bool
    {
        if ($this->canLead($user, $run)) {
            return true;
        }

        if ((int) $question->target_user_id === (int) $user->id || (int) $question->raised_by === (int) $user->id) {
            return true;
        }

        return $question->target_role !== null
            && $question->target_role === $this->contributorRoleFor($user, $run);
    }

    /**
     * The contributor role this user acts as on this run, resolved from the
     * unit assignment pivot role (config rounds.unit_role_map). Null when no
     * mapped assignment exists — the client must then supply an explicit,
     * allowlisted role and authorization falls back to canContribute.
     */
    public function contributorRoleFor(User $user, RoundRun $run): ?string
    {
        $unitId = $this->runUnitId($run);

        if ($unitId === null || ! Schema::hasTable('prod.user_unit')) {
            return null;
        }

        $pivotRole = $user->units()
            ->wherePivot('unit_id', $unitId)
            ->first()
            ?->pivot
            ?->role;

        if ($pivotRole === null) {
            return null;
        }

        return config('rounds.unit_role_map.'.$pivotRole);
    }

    /** Units this user may start or view unit-scope rounds for. */
    public function accessibleUnits(User $user): Collection
    {
        $units = Unit::query()->where('is_deleted', false)->orderBy('name');

        if ($this->isBroadAccess($user)) {
            return $units->get();
        }

        if (! Schema::hasTable('prod.user_unit')) {
            return collect();
        }

        $unitIds = $user->units()->pluck('prod.units.unit_id');

        return $units->whereIn('unit_id', $unitIds)->get();
    }

    /** @throws AuthorizationException */
    public function assertCanViewRun(User $user, RoundRun $run): void
    {
        if (! $this->canViewRun($user, $run)) {
            throw new AuthorizationException('You are not authorized to view this round.');
        }
    }

    /** @throws AuthorizationException */
    public function assertCanContribute(User $user, RoundRun $run): void
    {
        if (! $this->canContribute($user, $run)) {
            throw new AuthorizationException('You are not authorized to contribute to this round.');
        }
    }

    /** @throws AuthorizationException */
    public function assertCanLead(User $user, RoundRun $run): void
    {
        if (! $this->canLead($user, $run)) {
            throw new AuthorizationException('You are not authorized to lead this round.');
        }
    }

    public function runUnitId(RoundRun $run): ?int
    {
        if ($run->scope_type !== 'unit') {
            return null;
        }

        $unit = $this->resolveUnit($run->scope_key);

        return $unit?->unit_id;
    }

    public function resolveUnit(string $scopeKey): ?Unit
    {
        $query = Unit::query()->where('is_deleted', false);

        if (ctype_digit($scopeKey)) {
            return $query->where('unit_id', (int) $scopeKey)->first();
        }

        return $query->whereRaw('LOWER(abbreviation) = ?', [strtolower($scopeKey)])->first();
    }

    private function sharesUnit(User $user, int $unitId): bool
    {
        if (! Schema::hasTable('prod.user_unit')) {
            return false;
        }

        return $user->units()->wherePivot('unit_id', $unitId)->exists();
    }
}
