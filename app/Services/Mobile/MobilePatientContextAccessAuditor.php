<?php

namespace App\Services\Mobile;

use App\Models\User;
use App\Policies\Mobile\PatientOperationalContextAccessDecision;
use App\Services\Audit\UserAuditRecorder;
use Illuminate\Http\Request;

/**
 * Records staff operational-context decisions without persisting a source
 * patient identifier, clinical payload, or token. Successful disclosures are
 * strict: if audit durability is unavailable, the caller must fail closed.
 */
final class MobilePatientContextAccessAuditor
{
    public function __construct(private readonly UserAuditRecorder $audit) {}

    public function record(
        ?User $user,
        string $contextRef,
        PatientOperationalContextAccessDecision $decision,
    ): void {
        $request = $this->currentRequest();
        if ($request === null) {
            return;
        }

        $context = [
            'request' => $request,
            'actor' => $user,
            'reason' => $decision->reasonCode,
            'target_type' => $this->isOpaqueContextRef($contextRef) ? 'mobile_patient_context' : null,
            'target_id' => $this->isOpaqueContextRef($contextRef) ? $contextRef : null,
            'metadata' => ['policy_key' => $decision->policyKey],
            'http_status' => $decision->allowed ? 200 : 403,
        ];

        if ($decision->allowed) {
            $this->audit->record('mobile.patient_context.access', 'access', 'success', $context);

            return;
        }

        // A denial must not become an availability oracle when the audit sink
        // itself is unavailable. The endpoint remains a generic refusal.
        $this->audit->bestEffort('mobile.patient_context.access', 'authorization', 'denied', $context);
    }

    private function currentRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request ? $request : null;
    }

    private function isOpaqueContextRef(string $contextRef): bool
    {
        return preg_match('/^ptok_[a-f0-9]{24}$/D', $contextRef) === 1;
    }
}
