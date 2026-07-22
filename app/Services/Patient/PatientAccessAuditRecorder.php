<?php

namespace App\Services\Patient;

use App\Http\Middleware\AssignRequestIdentity;
use App\Models\Patient\PatientAccessAuditEvent;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PatientAccessAuditRecorder
{
    public function __construct(private readonly PatientHmac $hmac) {}

    /**
     * Persist a patient-realm access event. Authentication secrets, source
     * patient identifiers, request bodies, and free text are never accepted.
     *
     * @param  array<string, bool|int|string|null>  $metadata
     */
    public function record(
        Request $request,
        string $eventType,
        string $category,
        string $action,
        string $outcome,
        ?PatientPrincipal $principal = null,
        ?PatientSession $session = null,
        ?PatientEncounterAccessGrant $grant = null,
        ?string $reasonCode = null,
        ?string $resourceType = null,
        ?string $resourceUuid = null,
        array $metadata = [],
    ): PatientAccessAuditEvent {
        $requestPrincipal = $request->user();
        $principal ??= $requestPrincipal instanceof PatientPrincipal ? $requestPrincipal : null;
        $session ??= $principal !== null ? $this->sessionFromRequest($request, $principal) : null;
        $requestUuid = $request->attributes->get(AssignRequestIdentity::ATTRIBUTE);

        if (! is_string($requestUuid) || ! Str::isUuid($requestUuid)) {
            $requestUuid = (string) Str::uuid7();
        }

        $correlationUuid = $request->headers->get('X-Correlation-ID');
        if (! is_string($correlationUuid) || ! Str::isUuid($correlationUuid)) {
            $correlationUuid = null;
        }

        $idempotencyKey = trim((string) $request->headers->get('Idempotency-Key'));

        return PatientAccessAuditEvent::query()->create([
            'event_uuid' => (string) Str::uuid7(),
            'principal_id' => $principal?->getKey(),
            'patient_session_id' => $session?->getKey(),
            'access_grant_id' => $grant?->getKey(),
            'actor_type' => $principal?->principal_type ?? 'system',
            'actor_ref' => $principal?->principal_uuid,
            'event_type' => $eventType,
            'category' => $category,
            'action' => $action,
            'outcome' => $outcome,
            'purpose_of_use' => $grant?->purpose_of_use,
            'reason_code' => $reasonCode,
            'resource_type' => $resourceType,
            'resource_uuid' => $resourceUuid,
            'request_uuid' => $requestUuid,
            'correlation_uuid' => $correlationUuid,
            'idempotency_key_digest' => $idempotencyKey !== ''
                ? $this->hmac->digest(
                    'audit-idempotency',
                    $eventType.'|'.(string) $request->route()?->uri().'|'.$idempotencyKey,
                )
                : null,
            'ip_address' => $request->ip(),
            'user_agent_digest' => hash('sha256', (string) $request->userAgent()),
            'metadata' => (object) $metadata,
            'schema_version' => 1,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Authentication denials should not become an availability oracle if the
     * audit sink is unavailable. Successful access uses record() and fails
     * closed so disclosures cannot proceed without durable evidence.
     *
     * @param  array<string, bool|int|string|null>  $metadata
     */
    public function bestEffort(
        Request $request,
        string $eventType,
        string $category,
        string $action,
        string $outcome,
        ?PatientPrincipal $principal = null,
        ?PatientSession $session = null,
        ?PatientEncounterAccessGrant $grant = null,
        ?string $reasonCode = null,
        ?string $resourceType = null,
        ?string $resourceUuid = null,
        array $metadata = [],
    ): ?PatientAccessAuditEvent {
        try {
            return $this->record(
                $request,
                $eventType,
                $category,
                $action,
                $outcome,
                $principal,
                $session,
                $grant,
                $reasonCode,
                $resourceType,
                $resourceUuid,
                $metadata,
            );
        } catch (Throwable $exception) {
            Log::error('patient_access_audit.record_failed', [
                'event_type' => $eventType,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    private function sessionFromRequest(Request $request, PatientPrincipal $principal): ?PatientSession
    {
        $name = (string) $principal->currentAccessToken()?->name;
        $separator = strpos($name, ':');
        $sessionUuid = $separator === false ? '' : substr($name, $separator + 1);

        if (! Str::isUuid($sessionUuid)) {
            return null;
        }

        return PatientSession::query()
            ->where('principal_id', $principal->getKey())
            ->where('session_uuid', $sessionUuid)
            ->first();
    }
}
