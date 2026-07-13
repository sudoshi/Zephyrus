<?php

namespace App\Integrations\Healthcare\Services;

use App\Security\ClinicalPayloads\ClinicalContentGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IntegrationConfigurationAuditService
{
    public function __construct(private readonly ClinicalContentGuard $clinicalContent) {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(
        ?int $actorUserId,
        string $action,
        string $entityType,
        ?int $entityId,
        ?string $entityKey,
        array $before,
        array $after,
        string $correlationId,
    ): void {
        $this->clinicalContent->assertSafe(
            ['before' => $before, 'after' => $after],
            'clinical_content_audit_rejected',
        );

        DB::table('integration.configuration_audits')->insert([
            'audit_uuid' => (string) Str::uuid(),
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_key' => $entityKey,
            'before_payload' => json_encode((object) $before, JSON_THROW_ON_ERROR),
            'after_payload' => json_encode((object) $after, JSON_THROW_ON_ERROR),
            'correlation_id' => $correlationId,
            'created_at' => now(),
        ]);
    }
}
