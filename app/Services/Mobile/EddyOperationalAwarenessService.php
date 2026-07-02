<?php

namespace App\Services\Mobile;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EddyOperationalAwarenessService
{
    public function __construct(
        private readonly OperationalActivityLedger $ledger,
        private readonly MobilePatientContextService $patients,
        private readonly MobilePersonaCatalog $personas,
    ) {}

    /** @return array<string, mixed> */
    public function packet(string $scopeRef, ?User $user = null, ?string $roleId = null): array
    {
        $roleId = $this->personas->normalize($roleId, $user);
        $event = $this->ledger->findByUuid($scopeRef);
        $scopeType = $event ? 'event' : 'patient_or_scope';
        $safeScopeRef = $this->safeScopeRef($scopeRef, $event);

        $payload = [
            'scope_ref' => $safeScopeRef,
            'scope_type' => $scopeType,
            'generated_at' => now()->toISOString(),
            'persona' => $this->personas->describe($roleId),
            'phi_policy' => [
                'minimized_by_default' => true,
                'requires_user_authorization_for_patient_join' => true,
                'drafts_only' => true,
                'ops_approve_not_available' => true,
            ],
            'context' => $event
                ? ['event' => $event, 'activity' => [$event]]
                : $this->scopeContext($safeScopeRef, $user, $roleId),
            'questions_supported' => [
                'what_changed',
                'who_acted',
                'what_is_blocked',
                'who_needs_to_act_next',
                'what_should_happen_next',
            ],
        ];

        $payload = $this->scrubPhi($payload);
        $this->store($payload, $event['event_uuid'] ?? null);

        return $payload;
    }

    /** @return array<string, mixed> */
    private function scopeContext(string $scopeRef, ?User $user, string $roleId): array
    {
        if ($this->patients->hasPatientContext($scopeRef)) {
            $patient = $this->patients->build($scopeRef, $user, $roleId);

            return [
                'patient_context_ref' => $patient['patient']['patient_context_ref'] ?? null,
                'status_spine' => $patient['status_spine'] ?? [],
                'dependencies' => $patient['dependencies'] ?? [],
                'activity' => $patient['activity'] ?? [],
                'phi_minimized' => true,
            ];
        }

        return [
            'activity' => $this->ledger->feed($user, $roleId, null, 25)['data'],
            'phi_minimized' => true,
        ];
    }

    /** @param array<string, mixed>|null $event */
    private function safeScopeRef(string $scopeRef, ?array $event): string
    {
        if ($event !== null) {
            return $scopeRef;
        }

        $patientRef = $this->patients->resolvePatientRef($scopeRef);

        return $patientRef ? (string) $this->patients->contextRefFor($patientRef) : $scopeRef;
    }

    /** @return array<string, mixed> */
    private function scrubPhi(array $payload): array
    {
        return $this->scrubValue($payload);
    }

    private function scrubValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (($value['entity_type'] ?? null) === 'patient' && array_key_exists('entity_ref', $value)) {
            $value['entity_ref'] = $value['patient_context_ref'] ?? null;
        }

        if (($value['entity_type'] ?? null) === 'encounter' && array_key_exists('entity_ref', $value)) {
            $value['entity_ref'] = null;
        }

        foreach ($value as $key => $child) {
            if (in_array($key, ['patient_ref', 'encounter_ref', 'downstream_patient_refs'], true)) {
                unset($value[$key]);

                continue;
            }

            $value[$key] = $this->scrubValue($child);
        }

        return $value;
    }

    private function store(array $payload, ?string $eventUuid): void
    {
        if (! Schema::hasTable('ops.eddy_context_packets')) {
            return;
        }

        $eventId = null;
        if ($eventUuid && Schema::hasTable('ops.operational_events')) {
            $eventId = DB::table('ops.operational_events')->where('event_uuid', $eventUuid)->value('operational_event_id');
        }

        DB::table('ops.eddy_context_packets')->insert([
            'packet_uuid' => (string) Str::uuid(),
            'scope_ref' => $payload['scope_ref'],
            'scope_type' => $payload['scope_type'],
            'source_event_id' => $eventId,
            'generated_at' => now(),
            'expires_at' => now()->addMinutes(15),
            'packet_payload' => json_encode($payload),
            'phi_policy' => json_encode($payload['phi_policy']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
