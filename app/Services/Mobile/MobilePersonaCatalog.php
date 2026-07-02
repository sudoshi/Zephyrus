<?php

namespace App\Services\Mobile;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class MobilePersonaCatalog
{
    public const ROLE_IDS = [
        'charge_nurse',
        'bedside_nurse',
        'bed_manager',
        'house_supervisor',
        'hospitalist',
        'intensivist',
        'evs',
        'transport',
        'or_nurse',
        'capacity_lead',
        'periop_manager',
        'staffing_coordinator',
        'pi_lead',
        'executive',
    ];

    private const EXPERIENCES = [
        'charge_nurse' => ['title' => 'Charge Nurse', 'home' => 'census', 'focus' => 'Placements, barriers, staffing', 'question' => 'Is my unit safe to receive and discharge?', 'web' => '/rtdc/bed-tracking'],
        'bedside_nurse' => ['title' => 'Bedside / Duty Nurse', 'home' => 'census', 'focus' => 'Patients needing operational action', 'question' => 'Which of my patients need operational action?', 'web' => '/rtdc/bed-tracking'],
        'bed_manager' => ['title' => 'Bed Manager / Flow', 'home' => 'houseCapacity', 'focus' => 'House capacity and placement', 'question' => 'Can the house absorb demand?', 'web' => '/rtdc/bed-placement'],
        'house_supervisor' => ['title' => 'House Supervisor', 'home' => 'census', 'focus' => 'House status and escalations', 'question' => 'What is threatening the house right now?', 'web' => '/dashboard/rtdc'],
        'hospitalist' => ['title' => 'Hospitalist', 'home' => 'census', 'focus' => 'Service discharges and barriers', 'question' => 'Which patients are blocking flow or need a discharge decision?', 'web' => '/rtdc/barriers'],
        'intensivist' => ['title' => 'Intensivist', 'home' => 'census', 'focus' => 'Critical-care capacity', 'question' => 'Which critical-care decisions affect capacity and safety?', 'web' => '/rtdc/bed-tracking'],
        'evs' => ['title' => 'EVS', 'home' => 'evsTurns', 'focus' => 'Bed turns unlocking care', 'question' => 'Which bed turn unlocks care next?', 'web' => '/rtdc/bed-tracking'],
        'transport' => ['title' => 'Transport', 'home' => 'transportJobs', 'focus' => 'Trips and handoffs', 'question' => 'What trip needs me now?', 'web' => '/transport/dispatch'],
        'or_nurse' => ['title' => 'OR Nurse', 'home' => 'orBoard', 'focus' => 'Room board, cases, safety notes', 'question' => 'What case or room needs action now?', 'web' => '/operations/room-status'],
        'capacity_lead' => ['title' => 'Capacity Lead', 'home' => 'capacityDemand', 'focus' => 'Forecast, approvals, huddles', 'question' => 'What decisions will change the next four hours?', 'web' => '/ops/agent-inbox'],
        'periop_manager' => ['title' => 'Perioperative Manager', 'home' => 'orBoard', 'focus' => 'OR day drift', 'question' => 'Is the OR day drifting?', 'web' => '/analytics/or-utilization'],
        'staffing_coordinator' => ['title' => 'Staffing Coordinator', 'home' => 'staffing', 'focus' => 'Safe coverage gaps', 'question' => 'Where are we below safe coverage?', 'web' => '/staffing'],
        'pi_lead' => ['title' => 'PI / Quality Lead', 'home' => 'improvement', 'focus' => 'PDSA and recurring barriers', 'question' => 'Which improvement work is tied to today\'s operational pain?', 'web' => '/improvement/pdsa-cycles'],
        'executive' => ['title' => 'Executive', 'home' => 'houseBrief', 'focus' => 'House brief and material breaches', 'question' => 'Is the hospital OK?', 'web' => '/dashboard'],
    ];

    public function fromRequest(Request $request): string
    {
        $user = $request->user();
        $requested = $request->query('persona')
            ?? $request->headers->get('X-Hummingbird-Role')
            ?? $request->input('persona');
        $candidate = $requested ? $this->canonical((string) $requested) : $this->defaultForUser($user);

        if (! in_array($candidate, $this->allowedForUser($user), true)) {
            throw new AuthorizationException('The requested Hummingbird persona is not available to this user.');
        }

        return $candidate;
    }

    /** @return array<string, mixed> */
    public function describe(string $roleId): array
    {
        $roleId = $this->normalize($roleId);

        return [
            'role_id' => $roleId,
            'assignment_scope' => $this->assignmentScope($roleId),
            ...self::EXPERIENCES[$roleId],
        ];
    }

    public function normalize(?string $roleId, ?User $user = null): string
    {
        $roleId = $this->canonical($roleId);

        if (in_array($roleId, self::ROLE_IDS, true)) {
            return $roleId;
        }

        return $this->defaultForUser($user);
    }

    /** @return array<int, string> */
    public function allowedForUser(?User $user): array
    {
        if (! $user) {
            return [];
        }

        if ($this->isBroadAccessUser($user)) {
            return self::ROLE_IDS;
        }

        $roleNames = $user?->getRoleNames()?->map(fn (string $role): string => strtolower(str_replace([' ', '-'], '_', $role))) ?? collect();
        $allowed = [];
        foreach (self::ROLE_IDS as $known) {
            if ($roleNames->contains($known) || $roleNames->contains(fn (string $role): bool => str_contains($role, $known))) {
                $allowed[] = $known;
            }
        }

        $userRole = $this->canonical($user->role ?? null);
        if (in_array($userRole, self::ROLE_IDS, true)) {
            $allowed[] = $userRole;
        }

        return collect($allowed)->filter()->unique()->values()->all();
    }

    public function isBroadAccessUser(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $appRole = $this->canonical($user->role ?? null);
        if (in_array($appRole, ['admin', 'super_admin'], true)) {
            return true;
        }

        return $user->hasRole(['admin', 'super-admin', 'super_admin']);
    }

    private function defaultForUser(?User $user): string
    {
        $allowed = $this->allowedForUser($user);

        return $allowed[0] ?? 'house_supervisor';
    }

    private function canonical(?string $roleId): string
    {
        $roleId = strtolower((string) $roleId);

        return str_replace([' ', '-'], '_', $roleId);
    }

    private function assignmentScope(string $roleId): string
    {
        return in_array($roleId, ['charge_nurse', 'bedside_nurse', 'hospitalist', 'intensivist'], true)
            ? 'unit'
            : 'house';
    }
}
