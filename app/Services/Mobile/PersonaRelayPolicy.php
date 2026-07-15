<?php

namespace App\Services\Mobile;

class PersonaRelayPolicy
{
    public const STANDARD_EVENT_TYPES = [
        'recommendation.created',
        'recommendation.approved',
        'recommendation.rejected',
        'recommendation.overridden',
        'action.assigned',
        'action.started',
        'action.completed',
        'action.blocked',
        'bed_request.created',
        'bed_request.placed',
        'barrier.created',
        'barrier.resolved',
        'transport.claimed',
        'transport.progressed',
        'transport.handoff_completed',
        'evs.claimed',
        'evs.started',
        'evs.completed',
        'staffing.request_created',
        'staffing.request_filled',
        'or.case_delayed',
        'or.case_advanced',
        'huddle.action_created',
        'huddle.action_completed',
        'patient.operational_state_changed',
        'alert.acknowledged',
        'alert.escalated',
        'ancillary.sla_breached',
        'ancillary.sla_cleared',
    ];

    public const NOT_EMITTED_YET_EVENT_TYPES = [
        'recommendation.created' => 'Mobile currently consumes recommendations created by web or background services.',
        'recommendation.overridden' => 'Mobile approval endpoints expose approve/reject only.',
        'action.assigned' => 'No direct mobile operational-action assignment endpoint is implemented yet.',
        'action.started' => 'No direct mobile operational-action start endpoint is implemented yet.',
        'action.completed' => 'No direct mobile operational-action completion endpoint is implemented yet.',
        'action.blocked' => 'No direct mobile operational-action blocked endpoint is implemented yet.',
        'bed_request.created' => 'Mobile currently consumes bed requests; it does not create them.',
        'barrier.created' => 'Mobile currently resolves existing barriers; it does not create them.',
        'staffing.request_created' => 'Mobile currently fills staffing requests; it does not create them.',
        'or.case_delayed' => 'The OR mobile board is read-only today.',
        'or.case_advanced' => 'The OR mobile board is read-only today.',
        'huddle.action_created' => 'No mobile huddle action write is implemented yet.',
        'huddle.action_completed' => 'No mobile huddle action completion write is implemented yet.',
        'patient.operational_state_changed' => 'Patient state changes are inferred from domain records today.',
        'alert.acknowledged' => 'Activity acknowledgement is stored separately and does not mutate the operational object.',
        'alert.escalated' => 'No mobile alert escalation write is implemented yet.',
    ];

    /** @return array<string, mixed> */
    public function forEvent(string $eventType, string $domain = 'ops', array $scope = []): array
    {
        $relay = match (true) {
            str_starts_with($eventType, 'bed_request.') => [
                'affected_roles' => ['bed_manager', 'charge_nurse', 'evs', 'transport', 'capacity_lead'],
                'notify_now' => $eventType === 'bed_request.placed' ? ['charge_nurse'] : [],
                'activity_only' => ['house_supervisor', 'executive', 'pi_lead'],
                'push_tier' => $eventType === 'bed_request.placed' ? 'warning' : 'activity',
            ],
            str_starts_with($eventType, 'barrier.') => [
                'affected_roles' => ['charge_nurse', 'bed_manager', 'hospitalist', 'capacity_lead'],
                'notify_now' => $eventType === 'barrier.created' ? ['charge_nurse', 'hospitalist'] : [],
                'activity_only' => ['house_supervisor', 'pi_lead'],
                'push_tier' => $eventType === 'barrier.created' ? 'warning' : 'activity',
            ],
            str_starts_with($eventType, 'transport.') => [
                'affected_roles' => ['transport', 'charge_nurse', 'bed_manager', 'or_nurse'],
                'notify_now' => in_array($eventType, ['transport.claimed', 'transport.progressed'], true) ? ['charge_nurse'] : [],
                'activity_only' => ['capacity_lead', 'evs'],
                'push_tier' => 'activity',
            ],
            str_starts_with($eventType, 'evs.') => [
                'affected_roles' => ['evs', 'bed_manager', 'charge_nurse', 'transport'],
                'notify_now' => $eventType === 'evs.completed' ? ['bed_manager', 'charge_nurse'] : [],
                'activity_only' => ['capacity_lead'],
                'push_tier' => $eventType === 'evs.completed' ? 'warning' : 'activity',
            ],
            str_starts_with($eventType, 'staffing.') => [
                'affected_roles' => ['staffing_coordinator', 'charge_nurse', 'house_supervisor', 'bed_manager', 'capacity_lead'],
                'notify_now' => $eventType === 'staffing.request_created' ? ['staffing_coordinator'] : [],
                'activity_only' => ['executive'],
                'push_tier' => str_contains($eventType, 'filled') ? 'activity' : 'warning',
            ],
            str_starts_with($eventType, 'recommendation.') || str_starts_with($eventType, 'action.') => [
                'affected_roles' => ['capacity_lead', 'house_supervisor'],
                'notify_now' => ['capacity_lead'],
                'activity_only' => ['executive', 'pi_lead'],
                'push_tier' => 'warning',
            ],
            str_starts_with($eventType, 'alert.') => [
                'affected_roles' => ['house_supervisor', 'capacity_lead'],
                'notify_now' => ['house_supervisor'],
                'activity_only' => ['executive', 'pi_lead'],
                'push_tier' => 'warning',
            ],
            str_starts_with($eventType, 'ancillary.') => [
                'affected_roles' => ['house_supervisor', 'capacity_lead', 'charge_nurse'],
                'notify_now' => $eventType === 'ancillary.sla_breached' ? ['house_supervisor'] : [],
                'activity_only' => ['executive', 'pi_lead'],
                'push_tier' => $eventType === 'ancillary.sla_breached' ? 'warning' : 'activity',
            ],
            default => [
                'affected_roles' => ['house_supervisor', 'capacity_lead'],
                'notify_now' => [],
                'activity_only' => ['executive', 'pi_lead'],
                'push_tier' => 'activity',
            ],
        };

        if (! empty($scope['actor_role']) && ! in_array($scope['actor_role'], $relay['affected_roles'], true)) {
            $relay['affected_roles'][] = $scope['actor_role'];
        }

        $relay['notify_now'] = array_values(array_unique($relay['notify_now']));
        $relay['activity_only'] = array_values(array_unique($relay['activity_only']));
        $relay['affected_roles'] = array_values(array_unique([
            ...$relay['affected_roles'],
            ...$relay['notify_now'],
            ...$relay['activity_only'],
        ]));

        return $relay;
    }

    public function isRecognizedEventType(string $eventType): bool
    {
        foreach ([
            'bed_request.',
            'barrier.',
            'transport.',
            'evs.',
            'staffing.',
            'recommendation.',
            'action.',
            'alert.',
            'ancillary.',
        ] as $prefix) {
            if (str_starts_with($eventType, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function isDocumentedAsNotEmittedYet(string $eventType): bool
    {
        return array_key_exists($eventType, self::NOT_EMITTED_YET_EVENT_TYPES);
    }
}
