<?php

namespace App\Services\Patient\Messaging;

final class StaffPatientCommunicationRoutingPolicy
{
    /** @var array<string, list<array{code: string, label: string}>> */
    private const REASON_OPTIONS = [
        'release' => [
            ['code' => 'return_to_team', 'label' => 'Return to the care team'],
            ['code' => 'shift_handoff', 'label' => 'Shift handoff'],
            ['code' => 'responder_unavailable', 'label' => 'Responder unavailable'],
            ['code' => 'incorrect_assignment', 'label' => 'Incorrect assignment'],
        ],
        'reassign' => [
            ['code' => 'supervisor_assignment', 'label' => 'Supervisor assignment'],
            ['code' => 'shift_handoff', 'label' => 'Shift handoff'],
            ['code' => 'coverage_change', 'label' => 'Coverage change'],
            ['code' => 'workload_balance', 'label' => 'Balance workload'],
        ],
        'reroute' => [
            ['code' => 'wrong_team', 'label' => 'Wrong care team'],
            ['code' => 'unit_transfer', 'label' => 'Unit transfer'],
            ['code' => 'service_change', 'label' => 'Service change'],
            ['code' => 'specialty_needed', 'label' => 'Specialty team needed'],
        ],
    ];

    /** @return list<array{code: string, label: string}> */
    public static function reasonOptions(string $operation): array
    {
        return self::REASON_OPTIONS[$operation] ?? [];
    }

    /** @return list<string> */
    public static function reasonCodes(string $operation): array
    {
        return array_column(self::reasonOptions($operation), 'code');
    }

    /** @return array<string, list<array{code: string, label: string}>> */
    public static function allReasonOptions(): array
    {
        return self::REASON_OPTIONS;
    }
}
